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
   des attributs société ET de l'UID d'organisation (dont « ns-k8s » est
   dérivé), puis on finalise. Aucune session
   $_SESSION['user'] n'est ouverte tant que le choix n'est pas fait.

   Ce fichier ne définit QUE des fonctions (aucune sortie à l'inclusion).

   -------------------- Pré-requis Keycloak ----------------------------
   Client OIDC "siteweb" (KEYCLOAK_CLIENT_ID) :
     - "Client authentication" = ON (client confidentiel, secret)
     - "Direct access grants"  = ON   (indispensable à la connexion REST)
   Scope demandé : "openid profile email kubernetes organization:*"
     - "organization:*"  -> liste COMPLÈTE des organisations + attributs,
       ET l'« id » de chaque organisation : c'est LUI qui est obligatoire
       côté portail, puisque « ns-k8s » (namespace Kubernetes) en est dérivé.
       Repli automatique sur "organization" si le serveur refuse ":*".
     - "kubernetes"      -> claim "namespace" : conservé pour l'appariement
       d'organisation côté Admin REST, mais ce n'est PLUS le namespace k8s.
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
        // Doit contenir "organization:*" (liste complète des organisations,
        // AVEC leur id — obligatoire : « ns-k8s » en est dérivé). "kubernetes"
        // reste demandé pour l'appariement d'organisation, plus pour le namespace.
        return trim((string) config('KEYCLOAK_SCOPES', 'openid profile email kubernetes organization:*'));
    }
}
if (!function_exists('gnl_sso_authorization_url')) {
    /**
     * URL de la page de connexion Keycloak hébergée (flow « code »).
     *
     * C'est le SEUL chemin où Keycloak peut demander une clé de sécurité
     * (WebAuthn) : le grant password de /connexion n'en est pas capable.
     *
     * Même scope que la connexion REST (« organization:* » → organisations
     * AVEC leur id, dont « ns-k8s » est dérivé). $scopeFallback = true
     * rabat sur « organization » si le serveur a refusé le scope dynamique
     * (keycloak_callback.php le demande sur error=invalid_scope).
     */
    function gnl_sso_authorization_url(array $extra = [], array $context = [], bool $scopeFallback = false): string
    {
        $scope = kcRestScopes();
        if ($scopeFallback) {
            $scope = trim(str_replace('organization:*', 'organization', $scope));
        }
        $context['scope_fallback'] = $scopeFallback;
        return keycloakBuildAuthorizationUrl(['scope' => $scope] + $extra, $context);
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
   et l'objet unique auto-descriptif {"name":..,"attributes":{..}}.
   L'implémentation vit désormais dans include/keycloak_auth.php
   (keycloakOrganizationsFromClaims) afin d'être partagée avec le flow « code »
   et avec include/keycloak_organizations.php ; cet alias reste pour la
   compatibilité des appels existants. */
if (!function_exists('gnl_org_extract_all')) {
    function gnl_org_extract_all($org): array
    {
        return keycloakOrganizationsFromClaims($org);
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
    /**
     * @param string $totp code à 6 chiffres de l'application d'authentification.
     *                     Le flow « direct grant » par défaut de Keycloak contient
     *                     « Conditional OTP » : pour un compte ayant un TOTP, le
     *                     grant ÉCHOUE sans ce paramètre (même avec le bon mot de
     *                     passe). Ignoré s'il est vide.
     */
    function keycloakPasswordGrant(string $username, string $password, string $totp = ''): array
    {
        $totp = (string) preg_replace('/\s+/', '', $totp);
        $do = static function (string $scope) use ($username, $password, $totp): array {
            $fields = [
                'grant_type' => 'password',
                'client_id'  => keycloakGetClientId(),
                'username'   => $username,
                'password'   => $password,
                'scope'      => $scope,
            ];
            if ($totp !== '') $fields['totp'] = $totp;
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
            // Keycloak répond la même chose pour un mauvais mot de passe et pour
            // un code d'application d'authentification manquant : on oriente.
            return "Identifiant ou mot de passe incorrect. Si votre compte est protégé par une "
                . "application d'authentification, utilisez « Connexion sécurisée (SSO / MFA) ».";
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
   keycloakBuildSessionUser(), impose « ns-k8s », ouvre la session et
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

        // Identité : id = VRAI UID Keycloak ; account_id = entier local (tables INT).
        // Idempotent — fonctionne que keycloakBuildSessionUser() soit patché ou non.
        $sessionUser = gnl_apply_identity($sessionUser, $claims);

        // Organisation retenue pour CETTE session (nom/alias + attributs). Sert
        // à /equipes pour lister les membres via l'Admin REST « Organizations ».
        // À ce point, le claim a déjà été réduit à l'organisation choisie par
        // gnl_finalize_org_choice() lorsqu'il y en avait plusieurs.
        // C'est aussi là que « ns-k8s » est dérivé de l'UID d'organisation.
        $sessionUser = keycloakAttachOrganizationContext($sessionUser, $claims);

        // Namespace Kubernetes OBLIGATOIRE. Il ne vient plus de l'attribut
        // d'organisation « namespace » mais de l'UID d'organisation Keycloak
        // normalisé RFC1123 (« ns-k8s ») — cf. sessionUserNsK8s().
        if (trim((string) ($sessionUser['ns-k8s'] ?? '')) === '') {
            error_log('[GNL REST] ns-k8s indéterminable : UID d\'organisation Keycloak absent (mapper « organization » sans id).');
            return ['ok' => false, 'error' => "Ce compte n'est pas rattaché à un espace de travail. Contactez le support."];
        }

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
   Force l'organisation choisie comme SOURCE des attributs société ET de
   l'UID d'organisation (dont « ns-k8s » est dérivé), puis finalise.
   Retour : ['ok'=>bool, 'error'=>string]. */
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
        //    ⚠️ L'« id » (UUID de l'organisation) DOIT être conservé : c'est lui
        //    que keycloakAttachOrganizationContext() pose en kc_org_id, et dont
        //    « ns-k8s » est dérivé. Le perdre ici ferait échouer la garde
        //    namespace de gnl_finalize_portal_login() pour tout compte
        //    multi-organisations.
        $claims['organization'] = [
            'id'         => (string) ($chosen['id'] ?? ''),
            'name'       => (string) ($chosen['name'] ?? ''),
            'attributes' => $attrs,
        ];

        // 2) Force aussi les alias « plats » que keycloakBuildSessionUser() lit
        //    EN PRIORITÉ (avant la recherche profonde), pour que le choix gagne
        //    quel que soit le mapper. On n'écrase jamais avec une valeur vide.
        //    ⚠️ 'namespace' / 'k8s_namespace' ci-dessous ne sont PLUS le
        //    namespace Kubernetes : ils ne servent qu'à l'appariement
        //    d'organisation côté Admin REST (kcOrgSessionHints()).
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
   Panneau marine + formulaire, police Manrope locale, marine #141a33 / vert #5cd98b. */
if (!function_exists('gnl_auth_head')) {
    function gnl_auth_head(string $title, string $active = 'connexion'): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#141a33">
<title><?php echo gnl_e($title); ?> — Espace Client GNL Solution</title>
<link rel="preload" href="/assets/gnl/fonts/Manrope-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="icon" href="/assets/gnl/img/favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="/assets/gnl/img/favicon-180.png">
<style>
@font-face{font-family:Manrope;font-style:normal;font-weight:200 800;font-display:swap;src:url(/assets/gnl/fonts/Manrope-latin.woff2) format("woff2")}
:root{
  --navy:#141a33; --navy-2:#232b52; --navy-3:#32385e; --green:#5cd98b; --green-d:#1f7a4a;
  --ink:#141a33; --mute:#5e6684; --line:#c5cbd8; --line-2:#e3e6ee; --page:#f4f6fa;
  --danger:#b42318; --ok:#1f7a4a;
  /* alias conservés pour les pages qui les utilisent en ligne */
  --gnl-green:#141a33; --gnl-teal:#1f7a4a; --gnl-line:#c5cbd8;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font:15px/22px Manrope,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:var(--page);min-height:100vh;display:flex;flex-wrap:wrap;align-items:stretch}
a{color:var(--green-d)}
.gnl-aside{flex:1 1 420px;max-width:100%;padding:40px 48px;background:var(--navy);color:#fff;display:flex;flex-direction:column;gap:40px}
.gnl-aside img{width:210px;height:auto;display:block;margin-left:-12px}
.gnl-aside .k{margin:0;display:flex;align-items:center;gap:10px;font-size:12px;font-weight:700;letter-spacing:.2em;color:var(--green)}
.gnl-aside h2{margin:0;font-size:38px;line-height:44px;font-weight:800;max-width:460px}
.gnl-aside ul{margin:8px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:14px;color:#c5cbd8;max-width:460px}
.gnl-aside li{display:flex;gap:12px;align-items:baseline}
.gnl-aside .bottom{margin-top:auto;display:flex;flex-wrap:wrap;gap:8px 20px;font-size:13px;color:#c5cbd8}
.gnl-aside .bottom a{color:#c5cbd8;text-decoration:none}
.gnl-aside .bottom a:hover{color:#fff}
.gnl-aside .dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--green);margin-right:8px}
.gnl-auth{flex:1 1 520px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px 24px}
.gnl-card{width:100%;max-width:420px}
.gnl-card h1{font-size:30px;line-height:38px;font-weight:800;margin:0 0 6px}
.gnl-card .sub{margin:0 0 28px;color:var(--mute)}
.gnl-field{margin-bottom:18px}
label{display:block;font-size:13px;font-weight:700;margin:0 0 6px;color:var(--ink)}
.gnl-in{width:100%;height:46px;border:1px solid var(--line);border-radius:3px;padding:0 14px;font:inherit;background:#fff;color:inherit;transition:border-color .15s,box-shadow .15s}
.gnl-in:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 3px rgba(92,217,139,.35)}
.gnl-in::placeholder{color:#9aa1b8}
.gnl-pass{position:relative}
.gnl-pass .gnl-in{padding-right:48px}
.gnl-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;color:var(--mute);padding:6px;line-height:0;border-radius:3px}
.gnl-eye:hover{color:var(--ink)}
.gnl-row-between{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:-4px 0 20px}
.gnl-check{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:500;color:var(--ink);cursor:pointer;user-select:none;margin:0}
.gnl-check input{margin:0;accent-color:var(--green-d);flex:none;width:16px;height:16px}
.gnl-link{color:var(--green-d);text-decoration:none;font-size:14px;font-weight:700}
.gnl-link:hover{text-decoration:underline}
.gnl-btn{display:block;width:100%;height:48px;border:none;cursor:pointer;background:var(--navy);color:#fff;border-radius:3px;font:inherit;font-weight:700;font-size:15px;transition:background .15s}
.gnl-btn:hover{background:var(--navy-2)}
.gnl-btn:focus-visible{outline:3px solid var(--green);outline-offset:2px}
.gnl-btn[disabled]{opacity:.6;cursor:progress}
.gnl-alt{margin-top:20px;text-align:center;font-size:14px;color:var(--mute)}
.gnl-alt a{color:var(--green-d);font-weight:700;text-decoration:none}
.gnl-alt a:hover{text-decoration:underline}
.gnl-msg{border-radius:3px;padding:12px 14px;font-size:14px;margin:0 0 20px;display:flex;gap:10px;align-items:flex-start}
.gnl-msg svg{flex:none;margin-top:2px}
.gnl-msg.err{background:#fef3f2;border:1px solid #fecdca;color:var(--danger)}
.gnl-msg.ok{background:#ecfdf3;border:1px solid #abefc6;color:var(--ok)}
.gnl-hint{font-size:13px;color:var(--mute);margin:6px 0 0}
.gnl-sep{display:flex;align-items:center;gap:12px;color:var(--mute);font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin:24px 0 12px}
.gnl-sep::before,.gnl-sep::after{content:"";height:1px;background:var(--line-2);flex:1}
.gnl-foot{margin-top:32px;text-align:center;font-size:13px;color:var(--mute)}
.gnl-foot a{color:var(--mute)}
@media(max-width:900px){
  .gnl-aside{flex-basis:100%;padding:24px;gap:20px}
  .gnl-aside img{width:160px}
  .gnl-aside h2{font-size:24px;line-height:30px}
  .gnl-aside ul,.gnl-aside .bottom{display:none}
  .gnl-auth{padding:32px 16px 40px}
}
</style>
</head>
<body>
<aside class="gnl-aside">
  <a href="/" aria-label="GNL Solution"><img src="/assets/gnl/img/logo-gnl-marine.svg" alt="GNL Solution"></a>
  <div style="display:flex;flex-direction:column;gap:18px">
    <p class="k"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg>ESPACE CLIENT</p>
    <h2>Vos services, vos domaines et vos factures, au même endroit.</h2>
    <ul>
      <li><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg><span>Redémarrez, sauvegardez et suivez vos sites en temps réel.</span></li>
      <li><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg><span>Gérez vos DNS sans passer par un registrar.</span></li>
      <li><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg><span>Une équipe à Besançon, joignable par ticket ou au 03 65 67 01 69.</span></li>
    </ul>
  </div>
  <div class="bottom">
    <a href="https://gnl-solution.com/stats"><span class="dot"></span>État des services</a>
    <a href="https://gnl-solution.com/impact">Hébergement bas carbone</a>
  </div>
</aside>
<main class="gnl-auth">
  <div class="gnl-card">
<?php
    }
}
if (!function_exists('gnl_auth_foot')) {
    function gnl_auth_foot(): void
    {
        ?>
  </div>
  <p class="gnl-foot">Espace sécurisé GNL Solution &middot; <a href="/cgu">CGU</a> &middot; <a href="https://gnl-solution.com/politique-de-confidentialite">Confidentialité</a> &middot; <a href="https://gnl-solution.com/">Retour au site</a></p>
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
