<?php
session_start();
require_once 'config_loader.php';
require_once 'include/csrf.php';

$siren = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$token = $_POST["csrf_token"] ?? "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && !verify_csrf_token($token)) {
    http_response_code(403);
    exit("Invalid CSRF token");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE siren = ?");
    $stmt->execute([$siren]);
    $user = $stmt->fetch();

    if ($user) {
        // Vérifier si l'utilisateur est bloqué
        if ($user['login_attempts'] >= 3 && strtotime($user['last_attempt']) > time() - 300) {
            header("Location: index.php?error=Compte bloqué, réessayez plus tard.");
            exit();
        }

        if (password_verify($password, $user['password'])) {

            // Optionnel : régénérer l'ID de session pour prévenir la fixation de session

            session_regenerate_id(true);

            // Presence de cles U2F ?
            $stmtKey = $pdo->prepare('SELECT COUNT(*) FROM user_u2f WHERE user_id=?');
            $stmtKey->execute([$user['id']]);
            $hasKey = $stmtKey->fetchColumn() > 0;

            if ($hasKey) {
                $_SESSION['pending_user'] = [
                    'id' => $user['id'],
                    'siren' => $user['siren'],
                    'name' => $user['name'],
                    'structure_type' => $user['structure_type']
                ];
                $_SESSION['pending_user_id'] = $user['id'];
            } else {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'siren' => $user['siren'],
                    'name' => $user['name'],
                    'structure_type' => $user['structure_type']
                ];
            }


            // Réinitialiser les tentatives de connexion

            $stmtReset = $pdo->prepare("UPDATE users SET login_attempts = 0 WHERE siren = ?");
            $stmtReset->execute([$siren]);

            if ($hasKey) {
                header('Location: u2f_verify.php');
            } else {
                header('Location: dashboard.php');
            }
            exit();
        } else {
            // Incrémenter le nombre de tentatives et mettre à jour la date de la dernière tentative
            $stmtUpdate = $pdo->prepare("UPDATE users SET login_attempts = login_attempts + 1, last_attempt = NOW() WHERE siren = ?");
            $stmtUpdate->execute([$siren]);
            header("Location: index.php?error=Identifiants incorrects");
            exit();
        }
    } else {
        header("Location: index.php?error=Utilisateur inconnu");
        exit();
    }
}
?>
