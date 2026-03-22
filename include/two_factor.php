<?php

declare(strict_types=1);

function twoFactorEnsureStorage(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_two_factor (
            user_id INT NOT NULL PRIMARY KEY,
            totp_secret VARCHAR(64) DEFAULT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            phone_number VARCHAR(32) DEFAULT NULL,
            recovery_codes TEXT DEFAULT NULL,
            preferred_method VARCHAR(16) NOT NULL DEFAULT "totp",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $initialized = true;
}

function twoFactorGetConfig(PDO $pdo, int $userId): array
{
    twoFactorEnsureStorage($pdo);

    $stmt = $pdo->prepare('SELECT * FROM user_two_factor WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($config)) {
        return [
            'user_id' => $userId,
            'totp_secret' => null,
            'is_enabled' => 0,
            'phone_number' => null,
            'recovery_codes' => null,
            'preferred_method' => 'totp',
        ];
    }

    return $config;
}

function twoFactorUpsertConfig(PDO $pdo, int $userId, array $values): void
{
    twoFactorEnsureStorage($pdo);

    $allowedKeys = ['totp_secret', 'is_enabled', 'phone_number', 'recovery_codes', 'preferred_method'];
    $payload = [];

    foreach ($allowedKeys as $key) {
        if (array_key_exists($key, $values)) {
            $payload[$key] = $values[$key];
        }
    }

    if ($payload === []) {
        return;
    }

    $columns = array_keys($payload);
    $insertColumns = implode(', ', array_merge(['user_id'], $columns));
    $insertPlaceholders = implode(', ', array_fill(0, count($columns) + 1, '?'));
    $updates = implode(', ', array_map(static fn (string $column): string => sprintf('%1$s = VALUES(%1$s)', $column), $columns));

    $stmt = $pdo->prepare(sprintf(
        'INSERT INTO user_two_factor (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
        $insertColumns,
        $insertPlaceholders,
        $updates
    ));

    $stmt->execute(array_merge([$userId], array_values($payload)));
}

function twoFactorGenerateSecret(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $bytes = random_bytes($length);

    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
    }

    return $secret;
}

function twoFactorBase32Decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');

    if ($secret === '') {
        return '';
    }

    $buffer = 0;
    $bitsLeft = 0;
    $output = '';

    $length = strlen($secret);
    for ($i = 0; $i < $length; $i++) {
        $value = strpos($alphabet, $secret[$i]);
        if ($value === false) {
            continue;
        }

        $buffer = ($buffer << 5) | $value;
        $bitsLeft += 5;

        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $output;
}

function twoFactorGenerateTotpCode(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
{
    $timestamp ??= time();
    $counter = (int) floor($timestamp / $period);
    $binarySecret = twoFactorBase32Decode($secret);

    if ($binarySecret === '') {
        return '';
    }

    $high = intdiv($counter, 0x100000000);
    $low = $counter % 0x100000000;
    $counterBytes = pack('N2', $high, $low);
    $hash = hash_hmac('sha1', $counterBytes, $binarySecret, true);

    $offset = ord(substr($hash, -1)) & 0x0F;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
    $modulo = 10 ** $digits;

    return str_pad((string) ($value % $modulo), $digits, '0', STR_PAD_LEFT);
}

function twoFactorVerifyTotpCode(string $secret, string $submittedCode, int $window = 1): bool
{
    $normalizedCode = preg_replace('/\D+/', '', $submittedCode) ?? '';
    if ($normalizedCode === '' || $secret === '') {
        return false;
    }

    for ($offset = -$window; $offset <= $window; $offset++) {
        $candidate = twoFactorGenerateTotpCode($secret, time() + ($offset * 30));
        if ($candidate !== '' && hash_equals($candidate, $normalizedCode)) {
            return true;
        }
    }

    return false;
}

function twoFactorProvisioningUri(string $issuer, string $label, string $secret): string
{
    return sprintf(
        'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode($label),
        rawurlencode($secret),
        rawurlencode($issuer)
    );
}

function twoFactorGenerateRecoveryCodes(int $count = 8): array
{
    $codes = [];

    for ($i = 0; $i < $count; $i++) {
        $bytes = strtoupper(bin2hex(random_bytes(4)));
        $codes[] = substr($bytes, 0, 4) . '-' . substr($bytes, 4, 4);
    }

    return $codes;
}

function twoFactorHashRecoveryCodes(array $codes): string
{
    $hashes = [];
    foreach ($codes as $code) {
        $hashes[] = password_hash($code, PASSWORD_DEFAULT);
    }

    return json_encode($hashes, JSON_THROW_ON_ERROR);
}

function twoFactorCountRemainingRecoveryCodes(?string $encodedHashes): int
{
    if ($encodedHashes === null || trim($encodedHashes) === '') {
        return 0;
    }

    try {
        $decoded = json_decode($encodedHashes, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return 0;
    }

    return is_array($decoded) ? count($decoded) : 0;
}

function twoFactorConsumeRecoveryCode(PDO $pdo, int $userId, string $submittedCode): bool
{
    $normalizedCode = strtoupper(trim($submittedCode));
    if ($normalizedCode === '') {
        return false;
    }

    $config = twoFactorGetConfig($pdo, $userId);
    $rawHashes = $config['recovery_codes'] ?? null;

    if (!is_string($rawHashes) || trim($rawHashes) === '') {
        return false;
    }

    try {
        $hashes = json_decode($rawHashes, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return false;
    }

    if (!is_array($hashes)) {
        return false;
    }

    foreach ($hashes as $index => $hash) {
        if (is_string($hash) && password_verify($normalizedCode, $hash)) {
            unset($hashes[$index]);
            twoFactorUpsertConfig($pdo, $userId, [
                'recovery_codes' => json_encode(array_values($hashes), JSON_THROW_ON_ERROR),
            ]);
            return true;
        }
    }

    return false;
}

function twoFactorMaskPhone(?string $phone): string
{
    $value = trim((string) $phone);
    if ($value === '') {
        return 'Aucun numéro enregistré';
    }

    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) <= 4) {
        return $value;
    }

    return str_repeat('•', max(strlen($digits) - 4, 0)) . substr($digits, -4);
}

function twoFactorHasEnabledTotp(array $config): bool
{
    return !empty($config['is_enabled']) && !empty($config['totp_secret']);
}

function twoFactorHasEnabledWebauthn(PDO $pdo, int $userId): bool
{
    if ($userId <= 0 || !function_exists('webauthnCountActiveCredentials')) {
        return false;
    }

    return webauthnCountActiveCredentials($pdo, $userId) > 0;
}

function twoFactorHasAnyEnabledMethod(PDO $pdo, int $userId, ?array $config = null): bool
{
    $config ??= twoFactorGetConfig($pdo, $userId);

    return twoFactorHasEnabledTotp($config) || twoFactorHasEnabledWebauthn($pdo, $userId);
}
