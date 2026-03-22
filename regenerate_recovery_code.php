<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit();
}
require_once 'config_loader.php';
require_once 'include/csrf.php';
require_once 'include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit();
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id=?');
    $stmt->execute([$_SESSION['user']['id']]);
    $hash = $stmt->fetchColumn();
    if ($hash && password_verify($password, $hash)) {
        $code = strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare('UPDATE users SET recovery_code=? WHERE id=?');
        $stmt->execute([password_hash($code, PASSWORD_DEFAULT), $_SESSION['user']['id']]);
        $_SESSION['recovery_code'] = $code;
        header('Location: account.php');
        exit();
    } else {
        $error = 'Mot de passe incorrect';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Nouveau code de secours</title>
</head>
<body>
<?php if ($error) echo '<p>'.htmlspecialchars($error).'</p>'; ?>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
    <label for="password">Mot de passe:</label>
    <input type="password" id="password" name="password" required>
    <button type="submit">Generer</button>
</form>
</body>
</html>
