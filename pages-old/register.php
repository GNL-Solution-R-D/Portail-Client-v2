<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config_loader.php';
require_once 'include/csrf.php';

$token = $_POST["csrf_token"] ?? "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && !verify_csrf_token($token)) {
    http_response_code(403);
    exit("Invalid CSRF token");
}
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $siren = trim($_POST['siren']);
    $name = $_POST['name'];
    $structure_type = $_POST['structure_type'];
    $password = trim($_POST['password']);

    if (empty($siren) || empty($password)) {
        die("Tous les champs sont obligatoires.");
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (siren, name, structure_type, password) VALUES (?, ?, ?, ?)");
        $stmt->execute([$siren, $name, $structure_type, $hashedPassword]);
        echo "Utilisateur enregistré avec succès. <a href='index.php'>Se connecter</a>";
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Erreur de clé unique (SIREN déjà utilisé)
            echo "Erreur : Ce SIREN est déjà enregistré.";
        } else {
            echo "Erreur SQL : " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Inscription</title>
</head>
<body>
    <h2>Inscription</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <input type="text" name="siren" placeholder="NRA ou Siren" required>
        <input type="text" name="name" placeholder="Nom" required>
        
        <select name="structure_type" required>
            <option value="Association">Association</option>
            <option value="Micro-Entreprise">Micro-entreprise</option>
        </select>
        <input type="password" name="password" placeholder="Mot de Passe" required>
        <button type="submit">S'inscrire</button>
    </form>
</body>
</html>
