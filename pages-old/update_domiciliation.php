<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

// Inclusion du fichier de configuration
require_once 'config_loader.php';
require_once 'include/csrf.php';

$token = $_POST["csrf_token"] ?? "";
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        exit("Invalid CSRF token");
    }
// Récupération de l'ID utilisateur depuis la session
$user_id = $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer les valeurs du formulaire
    $street = isset($_POST['street']) ? trim($_POST['street']) : '';
    $postal_code = isset($_POST['postal_code']) ? trim($_POST['postal_code']) : '';
    $city = isset($_POST['city']) ? trim($_POST['city']) : '';

    // Validation des champs
    if (empty($street) || empty($postal_code) || empty($city)) {
        header("Location: dashboard.php?msg=missing_fields");
        exit();
    }

    if (!preg_match('/^\d{5}$/', $postal_code)) {
        header("Location: dashboard.php?msg=invalid_postal_code");
        exit();
    }

    // Vérification de l'existence d'une entrée pour l'utilisateur
    $query = $pdo->prepare("SELECT * FROM domiciliation WHERE user_id = ?");
    $query->execute([$user_id]);
    $existing = $query->fetch();

    if ($existing) {
        // Mise à jour des informations existantes
        $update = $pdo->prepare("UPDATE domiciliation SET street = ?, postal_code = ?, city = ? WHERE user_id = ?");
        $update->execute([$street, $postal_code, $city, $user_id]);
    } else {
        // Insertion d'une nouvelle entrée si elle n'existe pas
        $insert = $pdo->prepare("INSERT INTO domiciliation (user_id, street, postal_code, city) VALUES (?, ?, ?, ?)");
        $insert->execute([$user_id, $street, $postal_code, $city]);
    }

    // Mettre à jour la session pour refléter les nouvelles informations
    $_SESSION['user']['street'] = $street;
    $_SESSION['user']['postal_code'] = $postal_code;
    $_SESSION['user']['city'] = $city;

    // Redirection vers le dashboard avec un message de succès
    header("Location: dashboard.php?msg=domiciliation_updated");
    exit();
} else {
    // Redirection si l'accès se fait sans formulaire
    header("Location: dashboard.php");
    exit();
}
?>
