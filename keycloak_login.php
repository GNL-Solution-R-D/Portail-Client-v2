<?php
/* =====================================================================
   /keycloak_login.php — connexion par la page Keycloak HÉBERGÉE (flow code)
   ---------------------------------------------------------------------
   Seul chemin où Keycloak peut demander une clé de sécurité (WebAuthn),
   un code TOTP ou une action requise : le formulaire /connexion (grant
   password) ne sait pas le faire et y renvoie les comptes protégés par clé.

   Paramètres (GET, tous optionnels) :
     return      chemin interne où revenir après connexion (défaut /dashboard)
     login_hint  identifiant pré-rempli sur la page Keycloak
     sf=1        repli de scope « organization:* » → « organization »
                 (demandé par keycloak_callback.php sur error=invalid_scope)
   ===================================================================== */

require_once __DIR__ . '/include/session_bootstrap.php';   // mêmes options de cookie que le reste du portail
require_once __DIR__ . '/include/keycloak_rest.php';       // gnl_sso_authorization_url, gnl_safe_return

$return    = gnl_safe_return($_GET['return'] ?? '/dashboard');
$loginHint = trim((string) ($_GET['login_hint'] ?? ''));
if (mb_strlen($loginHint) > 190) $loginHint = '';

try {
    $authorizationUrl = gnl_sso_authorization_url(
        ['login_hint' => $loginHint],
        ['mode' => 'login', 'return' => $return],
        (string) ($_GET['sf'] ?? '') === '1'
    );
} catch (Throwable $exception) {
    header('Location: /connexion?error=' . urlencode($exception->getMessage()));
    exit();
}

header('Location: ' . $authorizationUrl);
exit();
