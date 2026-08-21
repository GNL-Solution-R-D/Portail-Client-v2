<?php
/* =====================================================================
   GNL Solution — Connexion REST pour l'ESPACE CLIENT (include/keycloak_rest.php)
   ---------------------------------------------------------------------
   Adapte le formulaire « maison » (Direct Access Grant / grant password)
   au portail existant, SANS changer le contrat de session.

   On NE construit PAS la session nous-mêmes : on récupère les jetons via
   l'API REST, puis on délègue la construction de $_SESSION['user'] à
   keycloakBuildSessionUser() (include/keycloak_auth.php), exactement
   comme keycloak_callback.php. Le reste du portail est inchangé.

   MULTI-ORGANISATION
   ------------------
   Si le jeton contient >= 2 organisations, on met l'identité + la liste
   en ATTENTE ($_SESSION['gnl_pending_login']) et on renvoie l'utilisateur
   vers /organisation. Le choix force l'organisation retenue comme SOURCE
   des attributs société ET du namespace, puis on finalise. Aucune session
   $_SESSION['user'] n'est ouverte tant que le choix n'est pas fait.

   Ce fichier ne définit QUE des fonctions (aucune sortie à l'inclusion).

   -------------------- Pré-requis Keycloak ----------------------------
   Client OIDC "siteweb" (KEYCLOAK_CLIENT_ID) :
     - "Client authentication" = ON (client confidentiel, secret)
     - "Direct access grants"  = ON   (indispensable à la connexion REST)
   Scope demandé : "openid profile email kubernetes organization:*"
     - "kubernetes"      -> claim "namespace" (obligatoire côté portail)
     - "organization:*"  -> liste COMPLÈTE des organisations + attributs
       (repli automatique sur "organization" si le serveur refuse ":*").
   Réglable via config('KEYCLOAK_SCOPES').

   Mot de passe oublié (optionnel) : compte de service avec le rôle
   realm-management "manage-users" (+ "view-users"). Réutilise "siteweb"
   (Service accounts roles) ou un client dédié via
   config('KEYCLOAK_ADMIN_CLIENT_ID' / '_SECRET'). Sinon message générique.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/../config_loader.php';   // config(), $pdo
require_once __DIR__ . '/keycloak_auth.php';      // keycloakGet*, keycloakBuildSessionUser, etc.
require_once __DIR__ . '/account_sessions.php';    // accountSessionsTouchCurrent()
require_once __DIR__ . '/portail_api_client.php';  // portailEnsureTeamMembership()

/** Durée de vie de l'état « choix d'organisation en attente » (secondes). */
if (!defined('GNL_PENDING_TTL')) define('GNL_PENDING_TTL', 900); // 15 min

/* --------------------------------------------------------------------
   Config locale (lue via config(), même source que le flow code)
   -------------------------------------------------------------------- */
if (!function_exists('kcRestScopes')) {
    function kcRestScopes(): string
    {
        // Doit contenir "kubernetes" (namespace obligatoire) et de préférence
        // "organization:*" (liste complète des organisations avec attributs).
        return trim((string) config('KEYCLOAK_SCOPES', 'openid profile email kubernetes organization:*'));
    }
}
if (!function_exists('kcRestAdminClientId')) {
    function kcRestAdminClientId(): string
    {
        $v = trim((string) config('KEYCLOAK_ADMIN_CLIENT_ID', ''));
        return $v !== '' ? $v : keycloakGetClientId(); // repli : siteweb
    }
}
if (!function_exists('kcRestAdminClientSecret')) {
    function kcRestAdminClientSecret(): string
    {
        $v = trim((string) config('KEYCLOAK_ADMIN_CLIENT_SECRET', ''));
        return $v !== '' ? $v : keycloakGetClientSecret(); // repli : siteweb
    }
}
if (!function_exists('kcRestAllowRegistration')) {
    /** Affiche (ou non) le lien « Créer un compte ». Off par défaut sur le portail. */
    function kcRestAllowRegistration(): bool
    {
        return (string) config('KEYCLOAK_ALLOW_REGISTRATION', '0') === '1';
    }
}

/* ============================ Utilitaires =========================== */
if (!function_exists('gnl_e')) {
    function gnl_e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('gnl_site_base')) {
    function gnl_site_base(): string
    {
        static $b = null;
        if ($b !== null) return $b;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        return $b = ($https ? 'https' : 'http') . '://' . $host;
    }
}
/* N'autorise que des chemins internes ("/xxx"), défaut /dashboard. */
if (!function_exists('gnl_safe_return')) {
    function gnl_safe_return($v): string
    {
        $v = (string) $v;
        if ($v === '' || $v[0] !== '/' || (isset($v[1]) && $v[1] === '/')) return '/dashboard';
        return $v;
    }
}
if (!function_exists('gnl_rand_hex')) {
    function gnl_rand_hex(int $bytes = 24): string
    {
        try { return bin2hex(random_bytes($bytes)); }
        catch (Throwable $e) { return substr(md5(uniqid('', true) . mt_rand()), 0, $bytes * 2); }
    }
}

/* ------------------------------ CSRF -------------------------------
   Clé DISTINCTE de $_SESSION['csrf'] (utilisée par data/portail_api.php). */
if (!function_exists('gnl_login_csrf_token')) {
    function gnl_login_csrf_token(): string
    {
        if (empty($_SESSION['gnl_login_csrf'])) $_SESSION['gnl_login_csrf'] = gnl_rand_hex(24);
        return $_SESSION['gnl_login_csrf'];
    }
}
if (!function_exists('gnl_login_csrf_check')) {
    function gnl_login_csrf_check(): bool
    {
        $t = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
        return $t !== '' && !empty($_SESSION['gnl_login_csrf']) && hash_equals((string) $_SESSION['gnl_login_csrf'], $t);
    }
}

/* ============== Extraction des organisations (claim) ===============
   Aplati les attributs Keycloak ({cle:[val]} ou {cle:val}). */
if (!function_exists('gnl_flatten_attrs')) {
    function gnl_flatten_attrs($attrs): array
    {
        $out = [];
        if (!is_array($attrs)) return $out;
        foreach ($attrs as $k => $v) {
            if (in_array($k, ['id', 'name', 'alias', 'attributes'], true)) continue;
            $out[$k] = is_array($v) ? (isset($v[0]) ? (string) $v[0] : '') : (string) $v;
        }
        return $out;
    }
}
/* Extrait TOUTES les organisations du claim "organization".
   Gère : ["orgA","orgB"], [{name,attributes},..], {"orgA":{..},"orgB":{..}}
   et l'objet unique auto-descriptif {"name":..,"attributes":{..}}. */
if (!function_exists('gnl_org_extract_all')) {
    function gnl_org_extract_all($org): array
    {
        $list = [];
        if (!is_array($org) || !$org) return $list;
        $keys = array_keys($org);

        // Tableau séquentiel : noms simples ou objets.
        if ($keys === range(0, count($org) - 1)) {
            foreach ($org as $v) {
                if (is_array($v)) {
                    $name  = isset($v['name']) ? (string) $v['name'] : (isset($v['alias']) ? (string) $v['alias'] : '');
                    $attrs = (isset($v['attributes']) && is_array($v['attributes'])) ? $v['attributes'] : $v;
                    $list[] = ['name' => $name, 'attributes' => gnl_flatten_attrs($attrs)];
                } else {
                    $list[] = ['name' => (string) $v, 'attributes' => []];
                }
            }
            return $list;
        }
        // Objet unique auto-descriptif : {"name":..,"attributes":{..}}.
        if (isset($org['attributes']) || isset($org['name']) || isset($org['id']) || isset($org['alias'])) {
            $name  = isset($org['name']) ? (string) $org['name'] : (isset($org['alias']) ? (string) $org['alias'] : '');
            $attrs = (isset($org['attributes']) && is_array($org['attributes'])) ? $org['attributes'] : $org;
            $list[] = ['name' => $name, 'attributes' => gnl_flatten_attrs($attrs)];
            return $list;
        }
        // Map indexée par nom d'organisation : {"orgA":{..}, "orgB":{..}}.
        foreach ($org as $name => $data) {
            $attrs = [];
            if (is_array($data)) {
                $attrs = (isset($data['attributes']) && is_array($data['attributes'])) ? $data['attributes'] : $data;
            }
            $list[] = ['name' => (string) $name, 'attributes' => gnl_flatten_attrs($attrs)];
        }
        return $list;
    }
}
/* Libellé lisible d'une organisation pour la page de choix. */
if (!function_exists('gnl_org_label')) {
    function gnl_org_label($org): array
    {
        $oa = (isset($org['attributes']) && is_array($org['attributes'])) ? $org['attributes'] : [];
        $A  = static function ($k) use ($oa) { return isset($oa[$k]) ? trim((string) $oa[$k]) : ''; };
        $title = $A('nom_commercial');
        if ($title === '') $title = $A('raison');
        if ($title === '') $title = $A('raison_social');
        if ($title === '') $title = isset($org['name']) ? (string) $org['name'] : '';
        if ($title === '') $title = 'Organisation';
        $bits = [];
        if ($A('entite_legal') !== '') $bits[] = $A('entite_legal');
        $loc = trim($A('cp') . ' ' . $A('commune'));
        if ($loc === '') $loc = trim($A('cp') . ' ' . $A('comune'));
        if ($loc !== '') $bits[] = $loc;
        if ($A('siret') !== '') $bits[] = 'SIRET ' . $A('siret');
        return ['title' => $title, 'sub' => implode(' · ', $bits)];
    }
}

/* ================= Grant "password" (connexion REST) ================
   Retourne { status:int, body:array }. Repli "organization:*" -> "organization"
   si le serveur refuse le scope dynamique. Peut lever une RuntimeException
   sur échec réseau (à attraper par l'appelant). */
if (!function_exists('keycloakPasswordGrant')) {
    function keycloakPasswordGrant(string $username, string $password): array
    {
        $do = static function (string $scope) use ($username, $password): array {
            $fields = [
                'grant_type' => 'password',
                'client_id'  => keycloakGetClientId(),
                'username'   => $username,
                'password'   => $password,
                'scope'      => $scope,
            ];
            $secret = keycloakGetClientSecret();
            if ($secret !== '') $fields['client_secret'] = $secret;
            return keycloakHttpRequest(
                keycloakGetIssuer() . '/protocol/openid-connect/token',
                [
                    CURLOPT_POST       => true,
                    CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                ]
            );
        };

        $scope = kcRestScopes();
        $resp  = $do($scope);

        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        if ((int) ($resp['status'] ?? 0) !== 200
            && ($body['error'] ?? '') === 'invalid_scope'
            && strpos($scope, 'organization:*') !== false) {
            $resp2 = $do(trim(str_replace('organization:*', 'organization', $scope)));
            if ((int) ($resp2['status'] ?? 0) === 200 && !empty($resp2['body']['access_token'])) {
                return $resp2;
            }
        }
        return $resp;
    }
}

/* Traduit une réponse d'erreur Keycloak en message FR compréhensible. */
if (!function_exists('gnl_login_error_fr')) {
    function gnl_login_error_fr(array $resp): string
    {
        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        $err  = isset($body['error']) ? (string) $body['error'] : '';
        $desc = isset($body['error_description']) ? (string) $body['error_description'] : '';
        $d    = strtolower($desc);

        if ($err === 'invalid_grant' && strpos($d, 'not fully set up') !== false)
            return "Votre compte n'est pas encore finalisé (e-mail à vérifier ou action requise). Consultez votre boîte mail.";
        if ($err === 'invalid_grant' && strpos($d, 'disabled') !== false)
            return "Ce compte est désactivé. Contactez le support.";
        if ($err === 'invalid_grant')
            return "Identifiant ou mot de passe incorrect.";
        if ($err === 'unauthorized_client' || strpos($d, 'direct access') !== false)
            return "La connexion directe n'est pas activée côté serveur (Direct access grants).";
        if ($err === 'invalid_client')
            return "Configuration client invalide (secret manquant ou erroné).";
        if ($err === 'invalid_scope')
            return "Le scope de connexion est refusé par le serveur (vérifiez « kubernetes »).";
        if ($desc !== '') return $desc;
        return "Connexion impossible. Réessayez.";
    }
}
/* Détail court pour les logs serveur (jamais affiché à l'utilisateur). */
if (!function_exists('gnl_login_detail')) {
    function gnl_login_detail(array $resp): string
    {
        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        $err  = isset($body['error']) ? (string) $body['error'] : '';
        $desc = isset($body['error_description']) ? (string) $body['error_description'] : '';
        $st   = isset($resp['status']) ? (int) $resp['status'] : 0;
        $bits = [];
        if ($st)          $bits[] = 'HTTP ' . $st;
        if ($err !== '')  $bits[] = $err;
        if ($desc !== '') $bits[] = $desc;
        return implode(' — ', $bits);
    }
}

/* ============= Identité normalisée (UID Keycloak + entier local) ====
   - id           : VRAI UID Keycloak (claim "sub") -> identité métier / n8n.
   - account_id   : entier stable [1..2147483647] pour les tables locales à
                    clé INT (user_account_sessions, PowerDNS). JAMAIS envoyé
                    à n8n. Reproduit l'ancien identifiant (sha1(sub)) pour
                    conserver la correspondance des lignes existantes.
   - siren        : rendu OBLIGATOIRE ; dérivé du siret (9 premiers chiffres)
                    s'il n'est pas fourni par Keycloak.
   Idempotent : sûr même si keycloakBuildSessionUser() a déjà posé ces clés. */
if (!function_exists('gnl_apply_identity')) {
    function gnl_apply_identity(array $u, array $claims): array
    {
        $uid = keycloakReadClaim($claims, ['sub']);
        if ($uid === '') $uid = (string) ($u['keycloak_uid'] ?? $u['sub'] ?? '');

        // account_id : préserve un entier déjà présent, sinon le (re)dérive.
        $accountId = (int) ($u['account_id'] ?? 0);
        if ($accountId === 0) {
            $prev = (string) ($u['id'] ?? '');
            if (ctype_digit($prev)) {
                $accountId = (int) $prev;                       // ancien id entier
            } else {
                $seed = $uid !== '' ? $uid : ($prev !== '' ? $prev : 'anonymous');
                $accountId = (int) (hexdec(substr(sha1($seed), 0, 8)) % 2147483647);
            }
        }
        if ($accountId <= 0) $accountId = 1;

        $u['account_id'] = $accountId;
        if ($uid !== '') {
            $u['id']           = $uid;   // id = UID réel
            $u['keycloak_uid'] = $uid;
            $u['sub']          = $uid;
        }

        // siren obligatoire : dérivation depuis le siret si absent.
        $siret = preg_replace('/\D/', '', (string) ($u['siret'] ?? ''));
        if ((string) ($u['siren'] ?? '') === '' && strlen($siret) >= 9) {
            $u['siren'] = substr($siret, 0, 9);
        }
        return $u;
    }
}

/* ============= Construction de session (délégation) ================
   Prend les claims fusionnés + l'id_token, construit $_SESSION['user'] via
   keycloakBuildSessionUser(), impose le namespace, ouvre la session et
   déclenche le suivi + le provisioning « team ». Utilisé par la connexion
   simple ET par le choix d'organisation.
   Retour : ['ok'=>bool, 'error'=>string]. */
if (!function_exists('gnl_finalize_portal_login')) {
    function gnl_finalize_portal_login(array $claims, string $idToken): array
    {
        global $pdo;

        if ($claims === []) {
            return ['ok' => false, 'error' => "Impossible de lire votre profil. Réessayez."];
        }

        $sessionUser = keycloakBuildSessionUser($claims);

        // Namespace Kubernetes OBLIGATOIRE (comportement portail inchangé).
        if (trim((string) ($sessionUser['k8s_namespace'] ?? '')) === '') {
            error_log('[GNL REST] namespace absent (mapper "namespace" / scope kubernetes, ou attribut d\'organisation manquant).');
            return ['ok' => false, 'error' => "Ce compte n'est pas rattaché à un espace de travail. Contactez le support."];
        }

        // Identité : id = VRAI UID Keycloak ; account_id = entier local (tables INT).
        // Idempotent — fonctionne que keycloakBuildSessionUser() soit patché ou non.
        $sessionUser = gnl_apply_identity($sessionUser, $claims);

        session_regenerate_id(true); // anti-fixation, conserve les données de session
        $_SESSION['user'] = $sessionUser;
        $_SESSION['keycloak_id_token'] = $idToken;
        unset($_SESSION['gnl_pending_login']);

        // Suivi de session : clé INT locale -> account_id (JAMAIS l'UID).
        accountSessionsTouchCurrent($pdo, (int) ($sessionUser['account_id'] ?? 0));

        try {
            portailEnsureTeamMembership($_SESSION['user']);
        } catch (Throwable $e) {
            error_log('[GNL REST] team.ensure: ' . $e->getMessage());
        }

        return ['ok' => true, 'error' => ''];
    }
}

/* Après un grant réussi : décide entre finalisation directe et choix d'org.
   Retour : ['state'=>'done'|'choose'|'error', 'redirect'=>string, 'error'=>string]. */
if (!function_exists('gnl_route_after_login')) {
    function gnl_route_after_login(array $claims, string $idToken, string $return): array
    {
        $orgs = isset($claims['organization']) ? gnl_org_extract_all($claims['organization']) : [];

        if (count($orgs) >= 2) {
            // Identité vérifiée, mais choix requis : on met en attente.
            $_SESSION['gnl_pending_login'] = [
                'claims'   => $claims,
                'id_token' => $idToken,
                'orgs'     => $orgs,
                'return'   => $return,
                't'        => time(),
            ];
            unset($_SESSION['user']);
            session_regenerate_id(true); // anti-fixation dès la vérification des identifiants
            return ['state' => 'choose', 'redirect' => '/organisation', 'error' => ''];
        }

        // 0 ou 1 organisation : l'unique org est déjà dans les claims -> finalisation.
        $r = gnl_finalize_portal_login($claims, $idToken);
        if ($r['ok']) return ['state' => 'done', 'redirect' => $return, 'error' => ''];
        return ['state' => 'error', 'redirect' => '', 'error' => $r['error']];
    }
}

/* État d'attente « choix d'organisation » (ou null si absent/expiré). */
if (!function_exists('gnl_pending_login')) {
    function gnl_pending_login(): ?array
    {
        if (empty($_SESSION['gnl_pending_login']) || !is_array($_SESSION['gnl_pending_login'])) return null;
        $p = $_SESSION['gnl_pending_login'];
        if (empty($p['orgs']) || !is_array($p['orgs'])) { unset($_SESSION['gnl_pending_login']); return null; }
        if (time() - (int) ($p['t'] ?? 0) > GNL_PENDING_TTL) { unset($_SESSION['gnl_pending_login']); return null; }
        return $p;
    }
}

/* Applique le choix d'organisation (index dans la liste en attente).
   Force l'organisation choisie comme SOURCE des attributs société ET du
   namespace, puis finalise. Retour : ['ok'=>bool, 'error'=>string]. */
if (!function_exists('gnl_finalize_org_choice')) {
    function gnl_finalize_org_choice(int $idx): array
    {
        $p = gnl_pending_login();
        if (!$p) return ['ok' => false, 'error' => "Session expirée. Reconnectez-vous."];

        $orgs = $p['orgs'];
        if ($idx < 0 || $idx >= count($orgs)) {
            return ['ok' => false, 'error' => "Ce choix n'est plus valide. Reconnectez-vous."];
        }

        $claims = (isset($p['claims']) && is_array($p['claims'])) ? $p['claims'] : [];
        $chosen = $orgs[$idx];
        $attrs  = (isset($chosen['attributes']) && is_array($chosen['attributes'])) ? $chosen['attributes'] : [];

        // 1) Réduit le claim "organization" à l'organisation choisie.
        $claims['organization'] = ['name' => (string) ($chosen['name'] ?? ''), 'attributes' => $attrs];

        // 2) Force aussi les alias « plats » que keycloakBuildSessionUser() lit
        //    EN PRIORITÉ (avant la recherche profonde), pour que le choix gagne
        //    quel que soit le mapper. On n'écrase jamais avec une valeur vide :
        //    un namespace GLOBAL (par utilisateur) est ainsi préservé.
        $flat = [
            'siret'         => $attrs['siret']          ?? null,
            'siren'         => $attrs['siren']          ?? null,
            'raison'        => $attrs['raison']         ?? ($attrs['raison_social'] ?? null),
            'nom_commercial' => $attrs['nom_commercial'] ?? null,
            'entite_legal'  => $attrs['entite_legal']   ?? null,
            'tva'           => $attrs['tva']            ?? null,
            'num_tva'       => $attrs['tva']            ?? ($attrs['num_tva'] ?? null),
            'ent_email'     => $attrs['ent_email']      ?? null,
            'telephone'     => $attrs['telephone']      ?? null,
            'pays'          => $attrs['pays']           ?? null,
            'cp'            => $attrs['cp']             ?? null,
            'commune'       => $attrs['commune']        ?? ($attrs['comune'] ?? null),
            'comune'        => $attrs['commune']        ?? ($attrs['comune'] ?? null),
            'voie_name'     => $attrs['voie_name']      ?? null,
            'voie_nbr'      => $attrs['voie_nbr']       ?? null,
            'namespace'     => $attrs['namespace']      ?? null,
            'k8s_namespace' => $attrs['namespace']      ?? ($attrs['k8s_namespace'] ?? null),
        ];
        foreach ($flat as $k => $v) {
            if ($v !== null && $v !== '') $claims[$k] = $v;
        }

        return gnl_finalize_portal_login($claims, (string) ($p['id_token'] ?? ''));
    }
}

/* ============= Mot de passe oublié (Admin REST, optionnel) ==========
   Best-effort : n'échoue jamais bruyamment côté utilisateur. */
if (!function_exists('kcRestAdminBase')) {
    /** Déduit la base Admin REST de l'issuer. */
    function kcRestAdminBase(): string
    {
        $iss = rtrim(keycloakGetIssuer(), '/');
        $pos = strpos($iss, '/realms/');
        if ($pos === false) return $iss . '/admin';
        $server = substr($iss, 0, $pos);                             // https://host/auth
        $realm  = trim(substr($iss, $pos + strlen('/realms/')), '/'); // client-auth
        return $server . '/admin/realms/' . rawurlencode($realm);
    }
}
if (!function_exists('kcRestAdminToken')) {
    function kcRestAdminToken(): ?string
    {
        static $tok = null, $exp = 0;
        if ($tok !== null && time() < $exp - 15) return $tok;

        try {
            $resp = keycloakHttpRequest(
                keycloakGetIssuer() . '/protocol/openid-connect/token',
                [
                    CURLOPT_POST       => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'grant_type'    => 'client_credentials',
                        'client_id'     => kcRestAdminClientId(),
                        'client_secret' => kcRestAdminClientSecret(),
                    ], '', '&', PHP_QUERY_RFC3986),
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                ]
            );
        } catch (Throwable $e) {
            error_log('[GNL REST] admin token network error: ' . $e->getMessage());
            return null;
        }

        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        if (empty($body['access_token'])) {
            error_log('[GNL REST] admin token failed: ' . gnl_login_detail($resp));
            return null;
        }
        $tok = (string) $body['access_token'];
        $exp = time() + (isset($body['expires_in']) ? (int) $body['expires_in'] : 60);
        return $tok;
    }
}
if (!function_exists('kcRestFindUserId')) {
    function kcRestFindUserId(string $email): ?string
    {
        $bearer = kcRestAdminToken();
        if ($bearer === null) return null;
        try {
            $resp = keycloakHttpRequest(
                kcRestAdminBase() . '/users?' . http_build_query(['email' => $email, 'exact' => 'true', 'max' => 1]),
                [CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $bearer]]
            );
        } catch (Throwable $e) {
            error_log('[GNL REST] find user network error: ' . $e->getMessage());
            return null;
        }
        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        if (!empty($body[0]) && is_array($body[0]) && !empty($body[0]['id'])) return (string) $body[0]['id'];
        return null;
    }
}
if (!function_exists('kcRestSendPasswordResetEmail')) {
    /** Déclenche l'e-mail Keycloak "UPDATE_PASSWORD". Best-effort, silencieux. */
    function kcRestSendPasswordResetEmail(string $email): void
    {
        $bearer = kcRestAdminToken();
        if ($bearer === null) return; // non configuré -> message générique affiché quand même
        $userId = kcRestFindUserId($email);
        if ($userId === null) return; // adresse inconnue -> on ne révèle rien

        try {
            keycloakHttpRequest(
                kcRestAdminBase() . '/users/' . rawurlencode($userId) . '/execute-actions-email?'
                    . http_build_query(['client_id' => keycloakGetClientId()]),
                [
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS    => json_encode(['UPDATE_PASSWORD']),
                    CURLOPT_HTTPHEADER    => [
                        'Accept: application/json',
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $bearer,
                    ],
                ]
            );
        } catch (Throwable $e) {
            error_log('[GNL REST] execute-actions-email network error: ' . $e->getMessage());
        }
    }
}

/* ==================== Gabarit visuel (charte GNL) ===================
   Carte centrée, police Manrope, vert #6c9400 / teal #009494 / ink #353535. */
if (!function_exists('gnl_auth_head')) {
    function gnl_auth_head(string $title, string $active = 'connexion'): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        $logo = gnl_e((string) config('KEYCLOAK_LOGO_URL', 'https://gnl-solution.fr/wp-content/uploads/2025/04/Logo-GNL3.png'));
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo gnl_e($title); ?> — GNL Solution</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" href="https://gnl-solution.fr/wp-content/uploads/2025/12/cropped-Sans-titre37-32x32.png" sizes="32x32">
<style>
:root{
  --gnl-green:#6c9400; --gnl-green-d:#5c7f00; --gnl-teal:#009494; --gnl-ink:#353535;
  --gnl-line:#e4e6e2; --gnl-soft:#f4f6f1; --gnl-danger:#c0392b; --gnl-ok:#2e7d32;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  font-family:'Manrope',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  color:var(--gnl-ink); line-height:1.45;
  background:
    radial-gradient(1200px 500px at 15% -10%, color-mix(in srgb,var(--gnl-green) 10%, transparent), transparent 60%),
    radial-gradient(1000px 480px at 110% 10%, color-mix(in srgb,var(--gnl-teal) 12%, transparent), transparent 55%),
    #f3f4f1;
  min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center;
  padding:2.2rem 1rem;
}
.gnl-auth{width:100%; max-width:440px}
.gnl-auth-logo{display:block; text-align:center; margin:0 auto 1.1rem}
.gnl-auth-logo img{height:56px; width:auto}
.gnl-card{
  background:#fff; border:1px solid var(--gnl-line); border-radius:16px;
  padding:1.9rem 1.9rem 1.7rem; box-shadow:0 18px 50px rgba(20,30,15,.08);
}
.gnl-card h1{font-size:1.4rem; font-weight:700; margin:.1rem 0 .25rem; letter-spacing:-.2px}
.gnl-card .sub{margin:0 0 1.35rem; font-size:.92rem; color:#6a6f66}
.gnl-field{margin-bottom:.95rem}
label{display:block; font-size:.82rem; font-weight:600; margin:0 0 .35rem; color:#4a4f46}
.gnl-in{
  width:100%; border:1px solid var(--gnl-line); border-radius:10px;
  padding:.72rem .85rem; font:inherit; font-size:.96rem; background:#fff; color:inherit;
  transition:border-color .15s, box-shadow .15s;
}
.gnl-in:focus{outline:none; border-color:var(--gnl-green); box-shadow:0 0 0 3px color-mix(in srgb,var(--gnl-green) 22%, transparent)}
.gnl-in::placeholder{color:#a7aca1}
.gnl-pass{position:relative}
.gnl-pass .gnl-in{padding-right:3.2rem}
.gnl-eye{position:absolute; right:.55rem; top:50%; transform:translateY(-50%); border:none; background:none; cursor:pointer; color:#8a8f85; padding:.3rem; line-height:0; border-radius:7px}
.gnl-eye:hover{color:var(--gnl-ink)}
.gnl-row-between{display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin:-.15rem 0 1.1rem}
.gnl-check{display:flex; align-items:flex-start; gap:.55rem; font-size:.85rem; color:#4a4f46; cursor:pointer; user-select:none}
.gnl-check input{margin:.15rem 0 0; accent-color:var(--gnl-green); flex:none}
.gnl-link{color:var(--gnl-teal); text-decoration:none; font-size:.85rem; font-weight:600}
.gnl-link:hover{text-decoration:underline}
.gnl-btn{
  display:block; width:100%; border:none; cursor:pointer; margin-top:.35rem;
  background:var(--gnl-green); color:#fff; border-radius:11px;
  padding:.85rem 1rem; font:inherit; font-weight:700; font-size:1rem;
  transition:filter .15s, transform .02s;
}
.gnl-btn:hover{filter:brightness(1.05)}
.gnl-btn:active{transform:translateY(1px)}
.gnl-btn[disabled]{opacity:.6; cursor:progress}
.gnl-alt{margin-top:1.25rem; text-align:center; font-size:.9rem; color:#5c6157}
.gnl-alt a{color:var(--gnl-teal); font-weight:700; text-decoration:none}
.gnl-alt a:hover{text-decoration:underline}
.gnl-msg{border-radius:11px; padding:.75rem .9rem; font-size:.88rem; margin:0 0 1.15rem; display:flex; gap:.55rem; align-items:flex-start}
.gnl-msg svg{flex:none; margin-top:1px}
.gnl-msg.err{background:color-mix(in srgb,var(--gnl-danger) 8%, #fff); border:1px solid color-mix(in srgb,var(--gnl-danger) 35%, transparent); color:#8e2a1e}
.gnl-msg.ok{background:color-mix(in srgb,var(--gnl-ok) 8%, #fff); border:1px solid color-mix(in srgb,var(--gnl-ok) 35%, transparent); color:#1f6323}
.gnl-hint{font-size:.78rem; color:#8a8f85; margin:.3rem 0 0}
.gnl-sep{display:flex; align-items:center; gap:.8rem; color:#a7aca1; font-size:.78rem; margin:1.15rem 0 .3rem}
.gnl-sep::before,.gnl-sep::after{content:""; height:1px; background:var(--gnl-line); flex:1}
.gnl-foot{margin-top:1.4rem; text-align:center; font-size:.76rem; color:#9aa093}
.gnl-foot a{color:inherit}
@media(max-width:480px){ .gnl-card{padding:1.5rem 1.25rem} }
</style>
</head>
<body>
<main class="gnl-auth">
  <a class="gnl-auth-logo" href="/" aria-label="GNL Solution"><img src="<?php echo $logo; ?>" alt="GNL Solution"></a>
  <div class="gnl-card">
<?php
    }
}

if (!function_exists('gnl_auth_foot')) {
    function gnl_auth_foot(): void
    {
        ?>
  </div>
  <p class="gnl-foot">Espace sécurisé GNL Solution &middot; <a href="/">Retour au site</a></p>
</main>
<script>
document.addEventListener('click', function(e){
  var b = e.target.closest('.gnl-eye'); if(!b) return;
  var inp = b.parentNode.querySelector('input');
  if(!inp) return;
  var show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  b.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
});
document.querySelectorAll('form[data-gnl-auth]').forEach(function(f){
  f.addEventListener('submit', function(){
    var b = f.querySelector('button[type=submit]');
    if(b){ b.disabled = true; b.textContent = 'Veuillez patienter…'; }
  });
});
</script>
</body>
</html>
<?php
    }
}

if (!function_exists('gnl_icon')) {
    function gnl_icon(string $type): string
    {
        if ($type === 'ok') return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    }
}
