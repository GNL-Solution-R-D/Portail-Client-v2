
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - GNLSolution</title>
    <link rel="stylesheet" href="assets/styles/login.css">
</head>
<body>
    <div class="container">
        <div class="login-box">
            <h1>GNL SOLUTION</h1>
            <p>Portail Association & Entreprise</p>
            <form action="login.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <input type="text" name="username" placeholder="NRA ou Siret" required>
                <input type="password" name="password" placeholder="Mot de Passe" required>
                <button type="submit">Connexion</button>
            </form>
            <a href="activation.php">Premiere Connexion ?</a>
            <a href="disable_u2f.php">Perte de la cle ?</a>
        </div>
        <div class="image-box">
        </div>
    </div>
</body>
</html>
