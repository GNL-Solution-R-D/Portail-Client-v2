<?php
session_start();
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/include/account_sessions.php';
require_once __DIR__ . '/include/keycloak_auth.php';
require_once __DIR__ . '/include/portail_api_client.php';

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

    $accessTokenClaims = keycloakDecodeJwtPayload($accessToken);
    $idTokenClaims = $idToken !== '' ? keycloakDecodeJwtPayload($idToken) : [];
    $userInfoClaims = keycloakFetchUserInfo($accessToken);

    $claims = array_merge($accessTokenClaims, $idTokenClaims, $userInfoClaims);
    if ($claims === []) {
        throw new RuntimeException('Impossible de lire les claims Keycloak (access_token/id_token/userinfo).');
    }

    $sessionUser = keycloakBuildSessionUser($claims);
    if (trim((string) ($sessionUser['k8s_namespace'] ?? '')) === '') {
        throw new RuntimeException('Le mapper Keycloak "namespace" est requis (scope kubernetes).');
    }

    session_regenerate_id(true);
    $_SESSION['user'] = $sessionUser;
    $_SESSION['keycloak_id_token'] = $idToken;

    accountSessionsTouchCurrent($pdo, (int) $sessionUser['id']);
} catch (Throwable $exception) {
    header('Location: /connexion?error=' . urlencode($exception->getMessage()));
    exit();
}

// ── Connexion établie ────────────────────────────────────────────────────────
// On PERSISTE la session (avec le namespace + le nouveau cookie régénéré) et on
// rend la main au navigateur AVANT tout appel réseau. L'alimentation de la table
// « team » ne doit jamais retarder la connexion ni compromettre la session :
// un n8n lent/injoignable ne doit pas pouvoir « manger » le Set-Cookie ni le
// namespace.
$sessionUserSnapshot = $_SESSION['user'];
session_write_close();

header('Location: /dashboard');

// PHP-FPM : renvoie la réponse 302 (et le Set-Cookie) immédiatement, puis
// poursuit le feed en arrière-plan, connexion navigateur déjà fermée.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Best-effort, timeouts courts : aucun impact possible sur la connexion.
try {
    portailEnsureTeamMembership($sessionUserSnapshot);
} catch (Throwable $teamException) {
    error_log('[keycloak_callback] team.ensure: ' . $teamException->getMessage());
}

exit();