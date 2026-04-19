<?php
session_start();
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/include/account_sessions.php';
require_once __DIR__ . '/include/keycloak_auth.php';

$code = trim((string) ($_GET['code'] ?? ''));
$state = trim((string) ($_GET['state'] ?? ''));
$storedState = trim((string) ($_SESSION['keycloak_oauth_state'] ?? ''));

if ($code === '' || $state === '' || $storedState === '' || !hash_equals($storedState, $state)) {
    unset($_SESSION['keycloak_oauth_state'], $_SESSION['keycloak_oauth_nonce']);
    header('Location: /connexion?error=' . urlencode('Retour Keycloak invalide (state/code).'));
    exit();
}

unset($_SESSION['keycloak_oauth_state']);

try {
    $tokenData = keycloakExchangeCodeForTokens($code);

    $accessToken = trim((string) ($tokenData['access_token'] ?? ''));
    $idToken = trim((string) ($tokenData['id_token'] ?? ''));

    if ($accessToken === '') {
        throw new RuntimeException('Access token manquant dans la réponse Keycloak.');
    }

    $claims = keycloakDecodeJwtPayload($accessToken);
    if ($claims === []) {
        throw new RuntimeException('Impossible de lire les claims Keycloak.');
    }

    $sessionUser = keycloakBuildSessionUser($claims);
    if (trim((string) ($sessionUser['k8s_namespace'] ?? '')) === '') {
        throw new RuntimeException('Le mapper Keycloak "namespace" est requis (scope kubernetes).');
    }

    $dolibarrToken = trim((string) ($claims['token_dolibarr'] ?? ''));

    session_regenerate_id(true);
    $_SESSION['user'] = $sessionUser;
    $_SESSION['dolibarr_token'] = $dolibarrToken;
    $_SESSION['dolibarr_token_obtained_at'] = time();
    $_SESSION['keycloak_id_token'] = $idToken;

    accountSessionsTouchCurrent($pdo, (int) $sessionUser['id']);
} catch (Throwable $exception) {
    header('Location: /connexion?error=' . urlencode($exception->getMessage()));
    exit();
}

header('Location: /dashboard');
exit();
