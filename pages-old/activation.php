<?php
session_start();
require_once 'include/csrf.php';
if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activation - OpenHebergement</title>
    <link rel="stylesheet" href="assets/styles/login.css">
</head>
<body>
    <div class="container">
        <div class="login-box">
            <h1>OpenHebergement</h1>
            <p>Portail Association & Micro Entreprise</p>
            <form action="login.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <input type="text" name="username" placeholder="NRA ou Siret" required>
                <input type="password" name="password" placeholder="Code de Premere Connexion" required>
                <input type="password" name="password" placeholder="Mot de Passe" required>
                <input type="password" name="password" placeholder="Confirmer le Mot de Passe" required>
                <button type="submit">Activation</button>
            </form>
            <a href="index.php">Deja actif ?</a>
        </div>
        <div class="image-box">
        </div>
    </div>
</body>
</html>

