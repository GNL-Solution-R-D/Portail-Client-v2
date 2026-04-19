<?php

declare(strict_types=1);

function keycloakGetIssuer(): string
{
    $issuer = trim((string) config('KEYCLOAK_ISSUER', 'https://auth.gnl-solution.fr/auth/realms/client-auth'));
    return rtrim($issuer, '/');
}

function keycloakGetClientId(): string
{
    return trim((string) config('KEYCLOAK_CLIENT_ID', ''));
}

function keycloakGetClientSecret(): string
{
    return trim((string) config('KEYCLOAK_CLIENT_SECRET', ''));
}

function keycloakGetRedirectUri(): string
{
    return trim((string) config('KEYCLOAK_REDIRECT_URI', 'https://espace-client.gnl-solution.fr/keycloak_callback.php'));
}

function keycloakGetPostLogoutRedirectUri(): string
{
    return trim((string) config('KEYCLOAK_POST_LOGOUT_REDIRECT_URI', 'https://espace-client.gnl-solution.fr/connexion'));
}

function keycloakBuildAuthorizationUrl(): string
{
    $clientId = keycloakGetClientId();
    if ($clientId === '') {
        throw new RuntimeException('KEYCLOAK_CLIENT_ID manquant.');
    }

    $state = bin2hex(random_bytes(24));
    $nonce = bin2hex(random_bytes(24));

    $_SESSION['keycloak_oauth_state'] = $state;
    $_SESSION['keycloak_oauth_nonce'] = $nonce;

    $params = [
        'client_id' => $clientId,
        'redirect_uri' => keycloakGetRedirectUri(),
        'response_type' => 'code',
        'scope' => 'openid profile email kubernetes dolibarr',
        'state' => $state,
        'nonce' => $nonce,
    ];

    return keycloakGetIssuer() . '/protocol/openid-connect/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function keycloakHttpRequest(string $url, array $options = []): array
{
    $ch = curl_init($url);

    $base = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];

    foreach ($options as $opt => $value) {
        $base[$opt] = $value;
    }

    curl_setopt_array($ch, $base);
    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erreur réseau Keycloak: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => $response];
    }

    return ['status' => $status, 'body' => $decoded];
}

function keycloakExchangeCodeForTokens(string $code): array
{
    $clientSecret = keycloakGetClientSecret();
    if ($clientSecret === '') {
        throw new RuntimeException('KEYCLOAK_CLIENT_SECRET manquant.');
    }

    $response = keycloakHttpRequest(
        keycloakGetIssuer() . '/protocol/openid-connect/token',
        [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => keycloakGetRedirectUri(),
                'client_id' => keycloakGetClientId(),
                'client_secret' => $clientSecret,
            ], '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]
    );

    if (($response['status'] ?? 0) !== 200) {
        throw new RuntimeException('Échec récupération token Keycloak.');
    }

    return is_array($response['body']) ? $response['body'] : [];
}

function keycloakDecodeJwtPayload(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return [];
    }

    $payload = strtr($parts[1], '-_', '+/');
    $pad = strlen($payload) % 4;
    if ($pad > 0) {
        $payload .= str_repeat('=', 4 - $pad);
    }

    $json = base64_decode($payload, true);
    if (!is_string($json) || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function keycloakFetchUserInfo(string $accessToken): array
{
    $token = trim($accessToken);
    if ($token === '') {
        return [];
    }

    $response = keycloakHttpRequest(
        keycloakGetIssuer() . '/protocol/openid-connect/userinfo',
        [
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]
    );

    if (($response['status'] ?? 0) !== 200 || !is_array($response['body'])) {
        return [];
    }

    return $response['body'];
}

function keycloakClaimToString($value): string
{
    if (is_scalar($value)) {
        return trim((string) $value);
    }

    if (is_array($value)) {
        foreach ($value as $candidate) {
            if (is_scalar($candidate)) {
                $normalized = trim((string) $candidate);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }
    }

    return '';
}

function keycloakReadClaim(array $claims, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $claims)) {
            continue;
        }

        $stringValue = keycloakClaimToString($claims[$key]);
        if ($stringValue !== '') {
            return $stringValue;
        }
    }

    if (isset($claims['kubernetes']) && is_array($claims['kubernetes'])) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $claims['kubernetes'])) {
                continue;
            }
            $stringValue = keycloakClaimToString($claims['kubernetes'][$key]);
            if ($stringValue !== '') {
                return $stringValue;
            }
        }
    }

    return '';
}

function keycloakBuildSessionUser(array $claims): array
{
    $subject = (string) ($claims['sub'] ?? '');
    $fallbackId = (int) sprintf('%u', crc32($subject !== '' ? $subject : (string) ($claims['preferred_username'] ?? '')));

    return [
        'id' => $fallbackId,
        'siret' => keycloakReadClaim($claims, ['siret']),
        'username' => keycloakReadClaim($claims, ['preferred_username', 'email']) ?: 'utilisateur',
        'civilite' => keycloakReadClaim($claims, ['civilite']),
        'prenom' => keycloakReadClaim($claims, ['given_name', 'prenom']),
        'nom' => keycloakReadClaim($claims, ['family_name', 'nom']),
        'perm_id' => 1,
        'langue_code' => keycloakReadClaim($claims, ['locale']) ?: 'fr',
        'timezone' => 'Europe/Paris',
        'fonction' => keycloakReadClaim($claims, ['fonction']),
        'k8s_namespace' => keycloakReadClaim($claims, ['namespace', 'k8s_namespace', 'namespace_k8s']),
        'cluster_id' => keycloakReadClaim($claims, ['cluster_id', 'clusterId']),
        'email' => keycloakReadClaim($claims, ['email']),
    ];
}

function keycloakBuildLogoutUrl(?string $idToken): string
{
    $params = [
        'post_logout_redirect_uri' => keycloakGetPostLogoutRedirectUri(),
    ];

    if (is_string($idToken) && trim($idToken) !== '') {
        $params['id_token_hint'] = trim($idToken);
    }

    return keycloakGetIssuer() . '/protocol/openid-connect/logout?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}
