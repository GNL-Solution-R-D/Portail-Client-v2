<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

require_once 'config_loader.php';
require_once 'include/csrf.php';

$token = $_POST["csrf_token"] ?? "";
if (!verify_csrf_token($token)) {
    http_response_code(403);
    exit("Invalid CSRF token");
}
// Récupérer le nouveau mot de passe envoyé par le formulaire
$password = isset($_POST['password']) ? trim($_POST['password']) : '';

$user_id = $_SESSION['user']['id'];

// Vérifier qu'un nouveau mot de passe a été fourni
if (empty($password)) {
    $_SESSION['update_error'] = "Aucun nouveau mot de passe fourni.";
    header("Location: dashboard.php");
    exit();
}

try {
    // Hacher le nouveau mot de passe
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    // Mettre à jour le mot de passe dans la base de données
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([$hashed_password, $user_id]);

    $_SESSION['update_success'] = "Mot de passe mis à jour avec succès.";
} catch (PDOException $e) {
    $_SESSION['update_error'] = "Erreur lors de la mise à jour du mot de passe : " . $e->getMessage();
}

// Rediriger vers le dashboard après mise à jour
header("Location: dashboard.php");
exit();
?>
