<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

// Inclusion du fichier de configuration
require_once 'include/csrf.php';
require_once 'config_loader.php';

$token = $_POST["csrf_token"] ?? "";
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        exit("Invalid CSRF token");
    }
// Récupération de l'ID utilisateur depuis la session
$user_id = $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer les valeurs du formulaire
    $legal_representative = isset($_POST['legal_representative']) ? trim($_POST['legal_representative']) : '';
    $admin_email = isset($_POST['admin_email']) ? trim($_POST['admin_email']) : '';

    // Validation des champs
    if (empty($legal_representative) || empty($admin_email)) {
        header("Location: dashboard.php?msg=missing_fields");
        exit();
    }

    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        header("Location: dashboard.php?msg=invalid_email");
        exit();
    }

    // Vérification de l'existence d'une entrée pour l'utilisateur
    $query = $pdo->prepare("SELECT * FROM admin_contacts WHERE user_id = ?");
    $query->execute([$user_id]);
    $existing = $query->fetch();

    if ($existing) {
        // Mise à jour des informations existantes
        $update = $pdo->prepare("UPDATE admin_contacts SET legal_representative = ?, admin_email = ? WHERE user_id = ?");
        $update->execute([$legal_representative, $admin_email, $user_id]);
    } else {
        // Insertion d'une nouvelle entrée si elle n'existe pas
        $insert = $pdo->prepare("INSERT INTO admin_contacts (user_id, legal_representative, admin_email) VALUES (?, ?, ?)");
        $insert->execute([$user_id, $legal_representative, $admin_email]);
    }

    // Mettre à jour la session pour refléter les nouvelles informations
    $_SESSION['user']['legal_representative'] = $legal_representative;
    $_SESSION['user']['admin_email'] = $admin_email;

    // Redirection vers le dashboard avec un message de succès
    header("Location: dashboard.php?msg=admin_contact_updated");
    exit();
} else {
    // Redirection si l'accès se fait sans formulaire
    header("Location: dashboard.php");
    exit();
}
?>
