<?php

declare(strict_types=1);

function webauthnEnsureStorage(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_webauthn_credentials (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            credential_id VARCHAR(512) NOT NULL,
            public_key_pem TEXT NOT NULL,
            sign_count INT UNSIGNED NOT NULL DEFAULT 0,
            transports VARCHAR(255) DEFAULT NULL,
            label VARCHAR(120) DEFAULT NULL,
            aaguid CHAR(36) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_webauthn_credential_id (credential_id),
            KEY idx_webauthn_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $initialized = true;
}

function webauthnConfig(): array
{
    $origin = rtrim((string) config('WEBAUTHN_ORIGIN', 'https://espace-client.gnl-solution.fr'), '/');
    $rpId = trim((string) config('WEBAUTHN_RP_ID', 'espace-client.gnl-solution.fr'));
    $rpName = trim((string) config('WEBAUTHN_RP_NAME', 'GNL Solution'));
    $timeout = (int) config('WEBAUTHN_TIMEOUT', 60000);
    $userVerification = trim((string) config('WEBAUTHN_USER_VERIFICATION', 'preferred'));

    if ($origin === '') {
        $origin = 'https://espace-client.gnl-solution.fr';
    }
    if ($rpId === '') {
        $rpId = 'espace-client.gnl-solution.fr';
    }
    if ($rpName === '') {
        $rpName = 'GNL Solution';
    }
    if ($timeout <= 0) {
        $timeout = 60000;
    }
    if (!in_array($userVerification, ['required', 'preferred', 'discouraged'], true)) {
        $userVerification = 'preferred';
    }

    return [
        'origin' => $origin,
        'rp_id' => $rpId,
        'rp_name' => $rpName,
        'timeout' => $timeout,
        'user_verification' => $userVerification,
    ];
}

function webauthnIsConfigured(): bool
{
    $config = webauthnConfig();
    return $config['origin'] !== '' && $config['rp_id'] !== '' && $config['rp_name'] !== '';
}

function webauthnBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webauthnBase64UrlDecode(string $data): string
{
    $normalized = strtr($data, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($normalized, true);
    if ($decoded === false) {
        throw new RuntimeException('Valeur base64url invalide.');
    }

    return $decoded;
}

function webauthnSessionEnsure(): void
{
    if (!isset($_SESSION['webauthn']) || !is_array($_SESSION['webauthn'])) {
        $_SESSION['webauthn'] = [];
    }
}

function webauthnStoreChallenge(string $flow, array $payload): void
{
    webauthnSessionEnsure();
    $_SESSION['webauthn'][$flow] = array_merge($payload, [
        'expires_at' => time() + 300,
    ]);
}

function webauthnConsumeChallenge(string $flow): array
{
    webauthnSessionEnsure();
    $payload = $_SESSION['webauthn'][$flow] ?? null;
    unset($_SESSION['webauthn'][$flow]);

    if (!is_array($payload) || (int) ($payload['expires_at'] ?? 0) < time()) {
        throw new RuntimeException('La demande WebAuthn a expiré.');
    }

    return $payload;
}

function webauthnCreateChallenge(int $length = 32): string
{
    return webauthnBase64UrlEncode(random_bytes($length));
}

function webauthnGetCredentials(PDO $pdo, int $userId, bool $activeOnly = true): array
{
    webauthnEnsureStorage($pdo);

    $sql = 'SELECT * FROM user_webauthn_credentials WHERE user_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY created_at ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function webauthnCountActiveCredentials(PDO $pdo, int $userId): int
{
    webauthnEnsureStorage($pdo);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_webauthn_credentials WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$userId]);

    return (int) $stmt->fetchColumn();
}

function webauthnFindCredential(PDO $pdo, int $userId, string $credentialId): ?array
{
    webauthnEnsureStorage($pdo);

    $stmt = $pdo->prepare('SELECT * FROM user_webauthn_credentials WHERE user_id = ? AND credential_id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$userId, $credentialId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function webauthnCreateRegistrationOptions(PDO $pdo, int $userId, array $user): array
{
    $config = webauthnConfig();
    $challenge = webauthnCreateChallenge();
    $credentials = webauthnGetCredentials($pdo, $userId);
    $displayName = trim((string) (($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')));
    if ($displayName === '') {
        $displayName = trim((string) ($user['username'] ?? 'Utilisateur'));
    }

    $userHandle = webauthnBase64UrlEncode((string) $userId);

    webauthnStoreChallenge('registration', [
        'challenge' => $challenge,
        'user_id' => $userId,
    ]);

    return [
        'challenge' => $challenge,
        'rp' => [
            'name' => $config['rp_name'],
            'id' => $config['rp_id'],
        ],
        'user' => [
            'id' => $userHandle,
            'name' => (string) ($user['username'] ?? ('user-' . $userId)),
            'displayName' => $displayName,
        ],
        'timeout' => $config['timeout'],
        'attestation' => 'none',
        'pubKeyCredParams' => [
            ['type' => 'public-key', 'alg' => -7],
        ],
        'excludeCredentials' => array_map(static function (array $credential): array {
            $entry = [
                'type' => 'public-key',
                'id' => (string) $credential['credential_id'],
            ];

            $transports = trim((string) ($credential['transports'] ?? ''));
            if ($transports !== '') {
                $decoded = json_decode($transports, true);
                if (is_array($decoded) && $decoded !== []) {
                    $entry['transports'] = array_values(array_filter($decoded, 'is_string'));
                }
            }

            return $entry;
        }, $credentials),
        'authenticatorSelection' => [
            'residentKey' => 'preferred',
            'requireResidentKey' => false,
            'userVerification' => $config['user_verification'],
        ],
    ];
}

function webauthnCreateAuthenticationOptions(PDO $pdo, int $userId): array
{
    $config = webauthnConfig();
    $challenge = webauthnCreateChallenge();
    $credentials = webauthnGetCredentials($pdo, $userId);

    webauthnStoreChallenge('authentication', [
        'challenge' => $challenge,
        'user_id' => $userId,
    ]);

    return [
        'challenge' => $challenge,
        'timeout' => $config['timeout'],
        'rpId' => $config['rp_id'],
        'userVerification' => $config['user_verification'],
        'allowCredentials' => array_map(static function (array $credential): array {
            $entry = [
                'type' => 'public-key',
                'id' => (string) $credential['credential_id'],
            ];

            $transports = trim((string) ($credential['transports'] ?? ''));
            if ($transports !== '') {
                $decoded = json_decode($transports, true);
                if (is_array($decoded) && $decoded !== []) {
                    $entry['transports'] = array_values(array_filter($decoded, 'is_string'));
                }
            }

            return $entry;
        }, $credentials),
    ];
}

function webauthnNormalizeTransports(array $transports): string
{
    $normalized = [];
    foreach ($transports as $transport) {
        if (!is_string($transport)) {
            continue;
        }
        $value = trim($transport);
        if ($value !== '') {
            $normalized[] = $value;
        }
    }

    $normalized = array_values(array_unique($normalized));
    return $normalized === [] ? '' : json_encode($normalized, JSON_THROW_ON_ERROR);
}

function webauthnDeleteCredential(PDO $pdo, int $userId, int $credentialRowId): void
{
    webauthnEnsureStorage($pdo);

    $stmt = $pdo->prepare('UPDATE user_webauthn_credentials SET is_active = 0 WHERE id = ? AND user_id = ?');
    $stmt->execute([$credentialRowId, $userId]);
}

function webauthnUpdateCredentialUsage(PDO $pdo, int $credentialRowId, int $signCount): void
{
    webauthnEnsureStorage($pdo);

    $stmt = $pdo->prepare('UPDATE user_webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?');
    $stmt->execute([$signCount, $credentialRowId]);
}

function webauthnStoreCredential(PDO $pdo, int $userId, string $credentialId, string $publicKeyPem, int $signCount, array $transports, string $label, ?string $aaguid): void
{
    webauthnEnsureStorage($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO user_webauthn_credentials (user_id, credential_id, public_key_pem, sign_count, transports, label, aaguid, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            public_key_pem = VALUES(public_key_pem),
            sign_count = VALUES(sign_count),
            transports = VALUES(transports),
            label = VALUES(label),
            aaguid = VALUES(aaguid),
            is_active = 1'
    );

    $stmt->execute([
        $userId,
        $credentialId,
        $publicKeyPem,
        $signCount,
        webauthnNormalizeTransports($transports),
        $label !== '' ? $label : null,
        $aaguid,
    ]);
}

function webauthnIsUserVerifiedRequired(): bool
{
    return webauthnConfig()['user_verification'] === 'required';
}

function webauthnVerifyOrigin(string $origin): bool
{
    return hash_equals(webauthnConfig()['origin'], rtrim($origin, '/'));
}

function webauthnAssertFlag(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function webauthnDecodeClientData(string $clientDataJsonB64): array
{
    $clientDataJson = webauthnBase64UrlDecode($clientDataJsonB64);
    $decoded = json_decode($clientDataJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('ClientData WebAuthn invalide.');
    }

    $decoded['_raw'] = $clientDataJson;
    return $decoded;
}

function webauthnReadUint(string $bytes): int
{
    $length = strlen($bytes);
    if ($length === 0) {
        return 0;
    }

    $value = 0;
    for ($i = 0; $i < $length; $i++) {
        $value = ($value << 8) | ord($bytes[$i]);
    }

    return $value;
}

function webauthnCborDecode(string $data, int $offset = 0): array
{
    if ($offset >= strlen($data)) {
        throw new RuntimeException('CBOR tronqué.');
    }

    $initial = ord($data[$offset]);
    $major = $initial >> 5;
    $additional = $initial & 0x1F;
    $offset++;

    $readLength = static function () use ($data, &$offset, $additional): int {
        return match (true) {
            $additional < 24 => $additional,
            $additional === 24 => webauthnReadUint(substr($data, $offset++, 1)),
            $additional === 25 => (function () use ($data, &$offset): int {
                $value = webauthnReadUint(substr($data, $offset, 2));
                $offset += 2;
                return $value;
            })(),
            $additional === 26 => (function () use ($data, &$offset): int {
                $value = webauthnReadUint(substr($data, $offset, 4));
                $offset += 4;
                return $value;
            })(),
            default => throw new RuntimeException('Type CBOR non supporté.'),
        };
    };

    if ($major === 0) {
        return ['value' => $readLength(), 'offset' => $offset];
    }

    if ($major === 1) {
        $value = $readLength();
        return ['value' => -1 - $value, 'offset' => $offset];
    }

    if ($major === 2 || $major === 3) {
        $length = $readLength();
        return ['value' => substr($data, $offset, $length), 'offset' => $offset + $length];
    }

    if ($major === 4) {
        $length = $readLength();
        $items = [];
        $cursor = $offset;
        for ($index = 0; $index < $length; $index++) {
            $decoded = webauthnCborDecode($data, $cursor);
            $items[] = $decoded['value'];
            $cursor = $decoded['offset'];
        }

        return ['value' => $items, 'offset' => $cursor];
    }

    if ($major === 5) {
        $length = $readLength();
        $items = [];
        $cursor = $offset;
        for ($index = 0; $index < $length; $index++) {
            $key = webauthnCborDecode($data, $cursor);
            $cursor = $key['offset'];
            $value = webauthnCborDecode($data, $cursor);
            $cursor = $value['offset'];
            $items[$key['value']] = $value['value'];
        }

        return ['value' => $items, 'offset' => $cursor];
    }

    throw new RuntimeException('Structure CBOR non supportée.');
}

function webauthnDerToRawSignature(string $signature, int $partLength = 32): string
{
    if ($signature === '' || ord($signature[0]) !== 0x30) {
        throw new RuntimeException('Signature DER invalide.');
    }

    $offset = 2;
    if ((ord($signature[1]) & 0x80) !== 0) {
        $lengthBytes = ord($signature[1]) & 0x7F;
        $offset = 2 + $lengthBytes;
    }

    if (!isset($signature[$offset]) || ord($signature[$offset]) !== 0x02) {
        throw new RuntimeException('Signature DER invalide.');
    }
    $rLength = ord($signature[$offset + 1]);
    $r = substr($signature, $offset + 2, $rLength);
    $offset += 2 + $rLength;

    if (!isset($signature[$offset]) || ord($signature[$offset]) !== 0x02) {
        throw new RuntimeException('Signature DER invalide.');
    }
    $sLength = ord($signature[$offset + 1]);
    $s = substr($signature, $offset + 2, $sLength);

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");

    return str_pad($r, $partLength, "\x00", STR_PAD_LEFT) . str_pad($s, $partLength, "\x00", STR_PAD_LEFT);
}

function webauthnBuildDerLength(int $length): string
{
    if ($length < 128) {
        return chr($length);
    }

    $binary = ltrim(pack('N', $length), "\x00");
    return chr(0x80 | strlen($binary)) . $binary;
}

function webauthnRawToDerSignature(string $raw): string
{
    $partLength = intdiv(strlen($raw), 2);
    $r = substr($raw, 0, $partLength);
    $s = substr($raw, $partLength);

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");

    if ($r === '' || (ord($r[0]) & 0x80) !== 0) {
        $r = "\x00" . $r;
    }
    if ($s === '' || (ord($s[0]) & 0x80) !== 0) {
        $s = "\x00" . $s;
    }

    $sequence = "\x02" . webauthnBuildDerLength(strlen($r)) . $r . "\x02" . webauthnBuildDerLength(strlen($s)) . $s;

    return "\x30" . webauthnBuildDerLength(strlen($sequence)) . $sequence;
}

function webauthnAaguidToString(string $aaguid): string
{
    $hex = bin2hex($aaguid);
    if (strlen($hex) !== 32) {
        return '';
    }

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20)
    );
}

function webauthnParseAuthenticatorData(string $authData, bool $expectAttestedCredential = false): array
{
    webauthnAssertFlag(strlen($authData) >= 37, 'AuthenticatorData invalide.');

    $rpIdHash = substr($authData, 0, 32);
    $flags = ord($authData[32]);
    $signCount = webauthnReadUint(substr($authData, 33, 4));
    $result = [
        'rpIdHash' => $rpIdHash,
        'flags' => $flags,
        'signCount' => $signCount,
    ];

    if ($expectAttestedCredential) {
        webauthnAssertFlag(($flags & 0x40) === 0x40, 'Aucune donnée de credential WebAuthn trouvée.');
        $offset = 37;
        $aaguid = substr($authData, $offset, 16);
        $offset += 16;
        $credentialIdLength = webauthnReadUint(substr($authData, $offset, 2));
        $offset += 2;
        $credentialId = substr($authData, $offset, $credentialIdLength);
        $offset += $credentialIdLength;
        $cbor = webauthnCborDecode($authData, $offset);
        $credentialPublicKey = $cbor['value'];

        $result['aaguid'] = webauthnAaguidToString($aaguid);
        $result['credentialId'] = $credentialId;
        $result['credentialPublicKey'] = $credentialPublicKey;
    }

    return $result;
}

function webauthnCoordinateToPem(string $x, string $y): string
{
    $uncompressed = "\x04" . $x . $y;
    $publicKeyBitString = "\x03" . webauthnBuildDerLength(strlen($uncompressed) + 1) . "\x00" . $uncompressed;
    $algorithmIdentifier = hex2bin('301306072A8648CE3D020106082A8648CE3D030107');
    $subjectPublicKeyInfo = "\x30" . webauthnBuildDerLength(strlen($algorithmIdentifier . $publicKeyBitString)) . $algorithmIdentifier . $publicKeyBitString;

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function webauthnCoseKeyToPem(array $coseKey): string
{
    $kty = (int) ($coseKey[1] ?? 0);
    $alg = (int) ($coseKey[3] ?? 0);

    webauthnAssertFlag($kty === 2, 'Type de clé WebAuthn non supporté.');
    webauthnAssertFlag($alg === -7, 'Algorithme WebAuthn non supporté.');
    webauthnAssertFlag((int) ($coseKey[-1] ?? 0) === 1, 'Courbe WebAuthn non supportée.');

    $x = $coseKey[-2] ?? null;
    $y = $coseKey[-3] ?? null;
    webauthnAssertFlag(is_string($x) && strlen($x) === 32, 'Coordonnée X WebAuthn invalide.');
    webauthnAssertFlag(is_string($y) && strlen($y) === 32, 'Coordonnée Y WebAuthn invalide.');

    return webauthnCoordinateToPem($x, $y);
}

function webauthnParseJsonPayload(string $json): array
{
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Payload WebAuthn invalide.');
    }

    return $decoded;
}

function webauthnFinishRegistration(PDO $pdo, int $userId, string $jsonPayload, string $label = ''): array
{
    $payload = webauthnParseJsonPayload($jsonPayload);
    $challengeData = webauthnConsumeChallenge('registration');
    webauthnAssertFlag((int) ($challengeData['user_id'] ?? 0) === $userId, 'Demande WebAuthn invalide pour cet utilisateur.');

    $clientData = webauthnDecodeClientData((string) ($payload['response']['clientDataJSON'] ?? ''));
    webauthnAssertFlag(($clientData['type'] ?? '') === 'webauthn.create', 'Type d’opération WebAuthn invalide.');
    webauthnAssertFlag(($clientData['challenge'] ?? '') === (string) $challengeData['challenge'], 'Challenge WebAuthn invalide.');
    webauthnAssertFlag(webauthnVerifyOrigin((string) ($clientData['origin'] ?? '')), 'Origin WebAuthn invalide.');

    $attestationObject = webauthnBase64UrlDecode((string) ($payload['response']['attestationObject'] ?? ''));
    $attestation = webauthnCborDecode($attestationObject)['value'];
    webauthnAssertFlag(is_array($attestation), 'Attestation WebAuthn invalide.');

    $authData = (string) ($attestation['authData'] ?? '');
    $parsedAuthData = webauthnParseAuthenticatorData($authData, true);
    webauthnAssertFlag(hash_equals(hash('sha256', webauthnConfig()['rp_id'], true), $parsedAuthData['rpIdHash']), 'RP ID WebAuthn invalide.');
    webauthnAssertFlag(($parsedAuthData['flags'] & 0x01) === 0x01, 'Présence utilisateur WebAuthn requise.');
    if (webauthnIsUserVerifiedRequired()) {
        webauthnAssertFlag(($parsedAuthData['flags'] & 0x04) === 0x04, 'Vérification utilisateur WebAuthn requise.');
    }

    $credentialId = webauthnBase64UrlEncode($parsedAuthData['credentialId']);
    $publicKeyPem = webauthnCoseKeyToPem($parsedAuthData['credentialPublicKey']);

    $transports = [];
    if (isset($payload['response']['transports']) && is_array($payload['response']['transports'])) {
        $transports = $payload['response']['transports'];
    } elseif (isset($payload['transports']) && is_array($payload['transports'])) {
        $transports = $payload['transports'];
    }

    webauthnStoreCredential(
        $pdo,
        $userId,
        $credentialId,
        $publicKeyPem,
        (int) $parsedAuthData['signCount'],
        $transports,
        trim($label),
        ($parsedAuthData['aaguid'] ?? '') !== '' ? (string) $parsedAuthData['aaguid'] : null
    );

    return [
        'credential_id' => $credentialId,
        'sign_count' => (int) $parsedAuthData['signCount'],
        'aaguid' => (string) ($parsedAuthData['aaguid'] ?? ''),
    ];
}

function webauthnNormalizeAssertionSignature(string $signature, string $publicKeyPem): string
{
    $key = openssl_pkey_get_public($publicKeyPem);
    if ($key === false) {
        throw new RuntimeException('Clé publique WebAuthn invalide.');
    }

    $details = openssl_pkey_get_details($key);
    if (!is_array($details)) {
        throw new RuntimeException('Détails de clé WebAuthn indisponibles.');
    }

    if (($details['type'] ?? null) === OPENSSL_KEYTYPE_EC) {
        if ($signature !== '' && ord($signature[0]) === 0x30) {
            return $signature;
        }
        return webauthnRawToDerSignature($signature);
    }

    return $signature;
}

function webauthnFinishAuthentication(PDO $pdo, int $userId, string $jsonPayload): array
{
    $payload = webauthnParseJsonPayload($jsonPayload);
    $challengeData = webauthnConsumeChallenge('authentication');
    webauthnAssertFlag((int) ($challengeData['user_id'] ?? 0) === $userId, 'Demande WebAuthn invalide pour cet utilisateur.');

    $credentialId = trim((string) ($payload['id'] ?? ''));
    webauthnAssertFlag($credentialId !== '', 'Credential WebAuthn manquant.');

    $credential = webauthnFindCredential($pdo, $userId, $credentialId);
    webauthnAssertFlag(is_array($credential), 'Clé de sécurité introuvable pour ce compte.');

    $clientData = webauthnDecodeClientData((string) ($payload['response']['clientDataJSON'] ?? ''));
    webauthnAssertFlag(($clientData['type'] ?? '') === 'webauthn.get', 'Type d’opération WebAuthn invalide.');
    webauthnAssertFlag(($clientData['challenge'] ?? '') === (string) $challengeData['challenge'], 'Challenge WebAuthn invalide.');
    webauthnAssertFlag(webauthnVerifyOrigin((string) ($clientData['origin'] ?? '')), 'Origin WebAuthn invalide.');

    $authenticatorData = webauthnBase64UrlDecode((string) ($payload['response']['authenticatorData'] ?? ''));
    $signature = webauthnBase64UrlDecode((string) ($payload['response']['signature'] ?? ''));
    $parsedAuthData = webauthnParseAuthenticatorData($authenticatorData, false);

    webauthnAssertFlag(hash_equals(hash('sha256', webauthnConfig()['rp_id'], true), $parsedAuthData['rpIdHash']), 'RP ID WebAuthn invalide.');
    webauthnAssertFlag(($parsedAuthData['flags'] & 0x01) === 0x01, 'Présence utilisateur WebAuthn requise.');
    if (webauthnIsUserVerifiedRequired()) {
        webauthnAssertFlag(($parsedAuthData['flags'] & 0x04) === 0x04, 'Vérification utilisateur WebAuthn requise.');
    }

    $signedPayload = $authenticatorData . hash('sha256', (string) $clientData['_raw'], true);
    $normalizedSignature = webauthnNormalizeAssertionSignature($signature, (string) $credential['public_key_pem']);
    $verifyResult = openssl_verify($signedPayload, $normalizedSignature, (string) $credential['public_key_pem'], OPENSSL_ALGO_SHA256);
    webauthnAssertFlag($verifyResult === 1, 'Signature WebAuthn invalide.');

    $storedSignCount = (int) ($credential['sign_count'] ?? 0);
    $receivedSignCount = (int) $parsedAuthData['signCount'];
    if ($storedSignCount > 0 && $receivedSignCount > 0 && $receivedSignCount <= $storedSignCount) {
        throw new RuntimeException('Compteur WebAuthn incohérent.');
    }

    webauthnUpdateCredentialUsage($pdo, (int) $credential['id'], max($storedSignCount, $receivedSignCount));

    return [
        'credential' => $credential,
        'sign_count' => max($storedSignCount, $receivedSignCount),
    ];
}
