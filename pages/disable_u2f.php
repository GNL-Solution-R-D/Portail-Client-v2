<?php
session_start();
require_once 'config_loader.php';
$message = '';
require_once 'include/csrf.php';
$token = $_POST["csrf_token"] ?? "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && !verify_csrf_token($token)) {
    http_response_code(403);
    exit("Invalid CSRF token");
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $siren = $_POST['siren'] ?? '';
    $password = $_POST['password'] ?? '';
    $code = $_POST['code'] ?? '';
    $stmt = $pdo->prepare('SELECT * FROM users WHERE siren = ?');
    $stmt->execute([$siren]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password']) && !empty($user['recovery_code']) && password_verify($code, $user['recovery_code'])) {
        $pdo->prepare('DELETE FROM user_u2f WHERE user_id=?')->execute([$user['id']]);
        $pdo->prepare('UPDATE users SET recovery_code=NULL WHERE id=?')->execute([$user['id']]);
        $message = 'U2F desactive. Vous pouvez vous connecter.';
    } else {
        $message = 'Informations incorrectes.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Desactivation U2F</title>
</head>
<body>
<?php if ($message) echo '<p>'.htmlspecialchars($message).'</p>'; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
<input type="text" name="siren" placeholder="NRA ou SIREN" required>
<input type="password" name="password" placeholder="Mot de passe" required>
<input type="text" name="code" placeholder="Code de secours" required>
<button type="submit">Desactiver U2F</button>
</form>
</body>
</html>
