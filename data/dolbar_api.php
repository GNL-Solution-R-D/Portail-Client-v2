<?php

declare(strict_types=1);

if (!function_exists('dolbarApiExtractErrorCode')) {
    function dolbarApiExtractErrorCode(Throwable $e): ?string
    {
        $code = $e->getCode();
        if ((is_int($code) || preg_match('/^-?\d+$/', (string)$code)) && (int)$code !== 0) {
            return (string)(int)$code;
        }

        if (preg_match('/\bHTTP\s+(\d{3})\b/i', $e->getMessage(), $matches)) {
            return $matches[1];
        }

        if (preg_match('/\bstatus(?:\s+code)?\s*[:=]?\s*(\d{3})\b/i', $e->getMessage(), $matches)) {
            return $matches[1];
        }

        return null;
    }
}

if (!function_exists('dolbarApiConfigValue')) {
    function dolbarApiConfigValue(array $keys, array $userContext = []): ?string
    {
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if ($userContext !== [] && array_key_exists($key, $userContext)) {
                $value = $userContext[$key];
                if ($value !== null && $value !== '') {
                    return trim((string)$value);
                }
            }

            if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null && $_ENV[$key] !== '') {
                return trim((string)$_ENV[$key]);
            }

            if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== null && $_SERVER[$key] !== '') {
                return trim((string)$_SERVER[$key]);
            }

            $envValue = getenv($key);
            if ($envValue !== false && $envValue !== '') {
                return trim((string)$envValue);
            }

            if (defined($key)) {
                $constantValue = constant($key);
                if ($constantValue !== null && $constantValue !== '') {
                    return trim((string)$constantValue);
                }
            }
        }

        return null;
    }
}



if (!function_exists('dolbarApiCandidateUrlKeys')) {
    function dolbarApiCandidateUrlKeys(): array
    {
        return [
            'dolbar_api_url', 'dolibarr_api_url', 'DOLBAR_API_URL', 'DOLIBARR_API_URL',
            // Variantes fréquemment utilisées dans les environnements existants.
            'dolbar_url', 'dolibarr_url', 'DOLBAR_URL', 'DOLIBARR_URL',
        ];
    }
}

if (!function_exists('dolbarApiCandidateKeyKeys')) {
    function dolbarApiCandidateKeyKeys(): array
    {
        return [
            'dolbar_api_key', 'dolibarr_api_key', 'DOLBAR_API_KEY', 'DOLIBARR_API_KEY',
            // Variantes fréquemment utilisées dans les environnements existants.
            'dolbar_key', 'dolibarr_key', 'DOLBAR_KEY', 'DOLIBARR_KEY',
            'dolapikey', 'DOLAPIKEY',
        ];
    }
}

if (!function_exists('dolbarApiNormalizeBaseUrl')) {
    function dolbarApiNormalizeBaseUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new RuntimeException('URL Dolbar vide.');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('URL Dolbar invalide.');
        }

        $path = $parts['path'] ?? '';
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        if (!preg_match('#/api(?:/index\.php)?$#i', $path)) {
            $path = rtrim($path, '/') . '/api/index.php';
        } elseif (preg_match('#/api$#i', $path)) {
            $path .= '/index.php';
        }

        $base = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $base .= ':' . (int)$parts['port'];
        }

        return $base . $path;
    }
}

if (!function_exists('dolbarApiHttpRequest')) {
    function dolbarApiHttpRequest(
        string $baseApiUrl,
        string $endpoint,
        string $method = 'GET',
        array $query = [],
        array $body = [],
        array $headers = [],
        int $timeout = 8
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extension cURL indisponible.', 500);
        }

        $endpoint = '/' . ltrim(trim($endpoint), '/');
        $url = rtrim($baseApiUrl, '/') . $endpoint;

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Initialisation cURL impossible.', 500);
        }

        $method = strtoupper(trim($method));
        $json = null;
        if ($body !== []) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Encodage JSON impossible.', 500);
            }
        }

        $httpHeaders = array_merge([
            'Accept: application/json',
            'Content-Type: application/json',
        ], $headers);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => max(3, $timeout),
            CURLOPT_FAILONERROR => false,
        ]);

        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Erreur réseau Dolbar: ' . $curlError, 500);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('HTTP ' . $httpCode . ' retourné par Dolbar.', $httpCode);
        }

        if ($responseBody === '' || $responseBody === null) {
            return [];
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse JSON Dolbar invalide.', 500);
        }

        return $decoded;
    }
}

if (!function_exists('dolbarApiCall')) {
    function dolbarApiCall(
        string $baseApiUrl,
        string $endpoint,
        string $apiKey,
        string $method = 'GET',
        array $query = [],
        array $body = [],
        int $timeout = 8
    ): array {
        if (trim($apiKey) === '') {
            throw new RuntimeException('Clé API Dolbar absente.', 0);
        }

        $headers = [
            'DOLAPIKEY: ' . $apiKey,
        ];

        return dolbarApiHttpRequest($baseApiUrl, $endpoint, $method, $query, $body, $headers, $timeout);
    }
}

if (!function_exists('dolbarApiHealthcheck')) {
    function dolbarApiHealthcheck(array $userContext = []): array
    {
        try {
            $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $userContext);
            $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $userContext);

            if ($apiUrl === null) {
                throw new RuntimeException('Configuration Dolbar absente: URL API.', 0);
            }
            if ($apiKey === null) {
                throw new RuntimeException('Configuration Dolbar absente: clé API.', 0);
            }

            $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);

            // Endpoint léger pour vérifier le bon fonctionnement de l'API REST.
            dolbarApiCall($apiUrl, '/status', $apiKey, 'GET', [], [], 6);

            return [
                'ok' => true,
                'error_code' => null,
                'message' => 'Dolbar API joignable.',
            ];
        } catch (Throwable $e) {
            $lowerMessage = strtolower($e->getMessage());
            $errorCode = dolbarApiExtractErrorCode($e)
                ?? (str_contains($lowerMessage, 'config') ? 'CONFIG' : 'DLB');

            return [
                'ok' => false,
                'error_code' => $errorCode,
                'message' => $e->getMessage(),
            ];
        }
    }
}
