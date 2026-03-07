<?php
session_start();
require_once 'config_loader.php';
require_once 'include/csrf.php';

$siret = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$token = $_POST["csrf_token"] ?? "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && !verify_csrf_token($token)) {
    http_response_code(403);
    exit("Invalid CSRF token");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE siret = ?");
    $stmt->execute([$siret]);
    $user = $stmt->fetch();

    if ($user) {
        // Vérifier si l'utilisateur est bloqué
        if ($user['login_attempts'] >= 3 && strtotime($user['last_attempt']) > time() - 300) {
            header("Location: connexion?error=Compte bloqué, réessayez plus tard.");
            exit();
        }

        if (password_verify($password, $user['password'])) {

            // Optionnel : régénérer l'ID de session pour prévenir la fixation de session

            session_regenerate_id(true);

                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'siret' => $user['siret'],
                    'nom' => $user['nom'],
                    'k8s_namespace' => $user['k8s_namespace']
                ];


            // Réinitialiser les tentatives de connexion

            $stmtReset = $pdo->prepare("UPDATE users SET login_attempts = 0 WHERE siret = ?");
            $stmtReset->execute([$siret]);


                header('Location: /dashboard');
            exit();
        } else {
            // Incrémenter le nombre de tentatives et mettre à jour la date de la dernière tentative
            $stmtUpdate = $pdo->prepare("UPDATE users SET login_attempts = login_attempts + 1, last_attempt = NOW() WHERE siret = ?");
            $stmtUpdate->execute([$siret]);
            header("Location: connexion?error=Identifiants incorrects");
            exit();
        }
    } else {
        header("Location: connexion?error=Utilisateur inconnu");
        exit();
    }
}
?>
