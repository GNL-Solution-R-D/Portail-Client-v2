<?php
session_start();
require_once 'config_loader.php';
require_once 'include/csrf.php';
require_once 'include/two_factor.php';
require_once 'include/webauthn.php';
require_once 'include/account_sessions.php';

function buildAuthenticatedUser(array $user): array
{
    return [
        'id' => $user['id'],
        'siret' => $user['siret'],
        'username' => $user['username'],
        'civilite' => $user['civilite'],
        'prenom' => $user['prenom'],
        'nom' => $user['nom'],
        'perm_id' => $user['perm_id'],
        'langue_code' => $user['langue_code'],
        'timezone' => $user['timezone'],
        'fonction' => $user['fonction'],
        'k8s_namespace' => $user['k8s_namespace'],
    ];
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$token = $_POST['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token($token)) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /connexion');
    exit();
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: /connexion?error=' . urlencode('Identifiants incorrects'));
    exit();
}

if ((int) ($user['login_attempts'] ?? 0) >= 3 && strtotime((string) ($user['last_attempt'] ?? '')) > time() - 300) {
    header('Location: /connexion?error=' . urlencode('Compte bloqué, réessayez plus tard.'));
    exit();
}

if (!password_verify($password, (string) ($user['password'] ?? ''))) {
    $stmtUpdate = $pdo->prepare('UPDATE users SET login_attempts = login_attempts + 1, last_attempt = NOW() WHERE id = ?');
    $stmtUpdate->execute([$user['id']]);

    header('Location: /connexion?error=' . urlencode('Identifiants incorrects'));
    exit();
}

$stmtReset = $pdo->prepare('UPDATE users SET login_attempts = 0 WHERE id = ?');
$stmtReset->execute([$user['id']]);

$twoFactorConfig = twoFactorGetConfig($pdo, (int) $user['id']);
$twoFactorEnabled = twoFactorHasAnyEnabledMethod($pdo, (int) $user['id'], $twoFactorConfig);

if ($twoFactorEnabled) {
    unset($_SESSION['user']);
    $_SESSION['pending_2fa'] = [
        'user' => buildAuthenticatedUser($user),
        'issued_at' => time(),
        'remember_origin' => '/dashboard',
    ];

    header('Location: /verification-2fa');
    exit();
}

session_regenerate_id(true);
$_SESSION['user'] = buildAuthenticatedUser($user);
accountSessionsTouchCurrent($pdo, (int) $user['id']);
unset($_SESSION['pending_2fa']);

header('Location: /dashboard');
exit();
