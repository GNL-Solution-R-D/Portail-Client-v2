<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

require_once 'include/csrf.php';
$token = $_POST["csrf_token"] ?? "";
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        exit("Invalid CSRF token");
    }
// Inclusion du fichier de configuration qui crée la connexion $pdo
require_once 'config_loader.php';

// Récupération de l'ID utilisateur depuis la session
$user_id = $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer les valeurs du formulaire
    $theme = isset($_POST['theme']) ? trim($_POST['theme']) : 'default';
    $background = isset($_POST['background']) ? trim($_POST['background']) : 'color';

    // Vérification de l'existence d'une entrée pour l'utilisateur
    $query = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = ?");
    $query->execute([$user_id]);
    $existing = $query->fetch();

    if ($existing) {
        // Mise à jour des préférences existantes
        $update = $pdo->prepare("UPDATE user_preferences SET theme = ?, background = ? WHERE user_id = ?");
        $update->execute([$theme, $background, $user_id]);
    } else {
        // Insertion d'une nouvelle entrée si l'utilisateur n'a pas encore de préférences
        $insert = $pdo->prepare("INSERT INTO user_preferences (user_id, theme, background) VALUES (?, ?, ?)");
        $insert->execute([$user_id, $theme, $background]);
    }

    // Mettre à jour la session pour refléter les nouvelles préférences immédiatement
    $_SESSION['user']['theme'] = $theme;
    $_SESSION['user']['background'] = $background;

    // Redirection vers le dashboard avec un message de succès
    header("Location: dashboard.php?msg=preferences_updated");
    exit();
} else {
    // Redirection si l'accès se fait sans formulaire
    header("Location: dashboard.php");
    exit();
}
?>
