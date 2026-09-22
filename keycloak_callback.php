<?php
/* =====================================================================
   /keycloak_callback.php — retour de la page Keycloak hébergée (flow code)
   ---------------------------------------------------------------------
   Deux modes, lus dans $_SESSION['keycloak_oauth_ctx'] (posé par
   keycloakBuildAuthorizationUrl() au départ, lié au « state ») :

   mode « login » (défaut) : connexion SSO / MFA / clé de sécurité.
     Même aboutissement que /connexion : gnl_route_after_login() — choix
     d'organisation si ≥ 2, identité normalisée (id = UID Keycloak,
     account_id entier), ns-k8s, suivi de session, team.ensure.

   mode « aia » : retour d'une application-initiated action lancée depuis
     /account (kc_action=webauthn-register). La session portail EXISTE
     déjà : on ne la reconstruit PAS (elle porte l'organisation choisie) ;
     on vérifie seulement que c'est bien le même compte Keycloak, puis on
     revient sur /account avec le résultat.
   ===================================================================== */

require_once __DIR__ . '/include/session_bootstrap.php';
require_once __DIR__ . '/include/keycloak_rest.php';   // inclut keycloak_auth, account_sessions, portail_api_client

/** Redirige vers un chemin interne et termine. */
function kc_cb_go(string $path): void
{
    header('Location: ' . $path);
    exit();
}

function kc_cb_login_error(string $message): void
{
    kc_cb_go('/connexion?error=' . urlencode($message));
}

$ctx = (isset($_SESSION['keycloak_oauth_ctx']) && is_array($_SESSION['keycloak_oauth_ctx']))
    ? $_SESSION['keycloak_oauth_ctx'] : ['mode' => 'login', 'return' => '/dashboard'];
$mode   = (($ctx['mode'] ?? 'login') === 'aia') ? 'aia' : 'login';
$return = gnl_safe_return($ctx['return'] ?? ($mode === 'aia' ? '/account' : '/dashboard'));

$code        = trim((string) ($_GET['code'] ?? ''));
$state       = trim((string) ($_GET['state'] ?? ''));
$storedState = trim((string) ($_SESSION['keycloak_oauth_state'] ?? ''));
$kcError     = trim((string) ($_GET['error'] ?? ''));
$actionState = trim((string) ($_GET['kc_action_status'] ?? ''));   // success | cancelled | error (AIA)

// Le state protège les deux modes (CSRF de login / injection de code).
if ($state === '' || $storedState === '' || !hash_equals($storedState, $state)) {
    unset($_SESSION['keycloak_oauth_state'], $_SESSION['keycloak_oauth_nonce'], $_SESSION['keycloak_oauth_ctx']);
    if ($mode === 'aia' && !empty($_SESSION['user'])) {
        kc_cb_go('/account?securitykey=error');
    }
    kc_cb_login_error('Retour Keycloak invalide (state/code).');
}

unset($_SESSION['keycloak_oauth_state'], $_SESSION['keycloak_oauth_ctx']);

/* ------------------------------------------------------------------ */
/* Erreur renvoyée par Keycloak (annulation, scope refusé, …)          */
/* ------------------------------------------------------------------ */
if ($kcError !== '') {
    error_log('[keycloak_callback] error=' . $kcError . ' desc=' . (string) ($_GET['error_description'] ?? ''));

    // Scope dynamique « organization:* » refusé : un seul nouvel essai.
    if ($kcError === 'invalid_scope' && empty($ctx['scope_fallback']) && $mode === 'login') {
        kc_cb_go('/keycloak_login.php?sf=1&return=' . rawurlencode($return));
    }
    if ($mode === 'aia') {
        kc_cb_go($return . '?securitykey=' . ($kcError === 'access_denied' ? 'cancelled' : 'error'));
    }
    kc_cb_login_error($kcError === 'access_denied'
        ? 'Connexion annulée.'
        : 'La connexion sécurisée a échoué. Réessayez.');
}

/* ------------------------------------------------------------------ */
/* Mode AIA : enregistrement d'une clé depuis /account                 */
/* ------------------------------------------------------------------ */
if ($mode === 'aia') {
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
        // Session portail expirée pendant la manipulation : la clé, elle, a
        // bien pu être enregistrée côté Keycloak. On repart au login.
        kc_cb_login_error('Votre session a expiré. Reconnectez-vous pour retrouver vos clés de sécurité.');
    }
    if ($actionState === 'cancelled') kc_cb_go($return . '?securitykey=cancelled');
    if ($actionState === 'error')     kc_cb_go($return . '?securitykey=error');

    // Succès annoncé : on échange quand même le code pour s'assurer que
    // l'action a été faite par LE MÊME compte que la session portail — un
    // collègue déjà connecté à Keycloak sur ce poste ne doit pas « réussir » ici.
    $sub = '';
    try {
        if ($code === '') throw new RuntimeException('code absent');
        $tok   = keycloakExchangeCodeForTokens($code);
        $jwt   = (string) ($tok['id_token'] ?? '');
        if ($jwt === '') $jwt = (string) ($tok['access_token'] ?? '');
        $claim = keycloakDecodeJwtPayload($jwt);
        $sub   = (string) ($claim['sub'] ?? '');
    } catch (Throwable $e) {
        error_log('[keycloak_callback] AIA échange de code : ' . $e->getMessage());
        kc_cb_go($return . '?securitykey=error');
    }

    $expected = (string) ($ctx['sub'] ?? '');
    if ($expected === '' || $sub === '' || !hash_equals($expected, $sub)) {
        error_log('[keycloak_callback] AIA : compte Keycloak différent de la session portail (attendu '
            . $expected . ', reçu ' . $sub . ').');
        kc_cb_go($return . '?securitykey=mismatch');
    }

    kc_cb_go($return . '?securitykey=' . ($actionState === 'success' ? 'added' : 'done'));
}

/* ------------------------------------------------------------------ */
/* Mode login                                                          */
/* ------------------------------------------------------------------ */
if ($code === '') {
    kc_cb_login_error('Retour Keycloak invalide (state/code).');
}

$claims  = [];
$idToken = '';
try {
    $tokenData = keycloakExchangeCodeForTokens($code);

    $accessToken = trim((string) ($tokenData['access_token'] ?? ''));
    $idToken     = trim((string) ($tokenData['id_token'] ?? ''));

    if ($accessToken === '') {
        throw new RuntimeException('Access token manquant dans la réponse Keycloak.');
    }

    $accessTokenClaims = keycloakDecodeJwtPayload($accessToken);
    $idTokenClaims     = $idToken !== '' ? keycloakDecodeJwtPayload($idToken) : [];
    $userInfoClaims    = keycloakFetchUserInfo($accessToken);

    $claims = array_merge($accessTokenClaims, $idTokenClaims, $userInfoClaims);
    if ($claims === []) {
        throw new RuntimeException('Impossible de lire les claims Keycloak (access_token/id_token/userinfo).');
    }

    // ── DEBUG temporaire ─────────────────────────────────────────────────────
    // KEYCLOAK_DEBUG_CLAIMS=1 → journalise la forme EXACTE des claims (PII : à
    // désactiver en production). Utile pour les attributs « select » vides.
    if (getenv('KEYCLOAK_DEBUG_CLAIMS') === '1') {
        error_log('[keycloak_callback] claims=' . json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
} catch (Throwable $exception) {
    kc_cb_login_error($exception->getMessage());
}

// Même aboutissement que /connexion : choix d'organisation, identité
// normalisée, ns-k8s obligatoire, suivi de session, team.ensure.
$r = gnl_route_after_login($claims, $idToken, $return);
if ($r['state'] === 'choose') kc_cb_go('/organisation');
if ($r['state'] === 'done')   kc_cb_go($r['redirect']);
kc_cb_login_error($r['error'] !== '' ? $r['error'] : 'Connexion impossible. Réessayez.');
