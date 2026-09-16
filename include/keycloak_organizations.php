<?php
/* =====================================================================
   GNL Solution — Organizations Keycloak (include/keycloak_organizations.php)
   ---------------------------------------------------------------------
   Lecture des ORGANISATIONS Keycloak (fonctionnalité « Organizations »,
   Keycloak >= 26) via l'Admin REST API, pour alimenter la carte
   « Membres de la structure » de /equipes.

   Source de vérité : Keycloak. Aucun appel n8n ici, aucune écriture :
   ce fichier ne fait que des GET.

   Chaîne d'appels :
     data/portail_api.php ?action=team.list
       └─ kcOrgResolveCurrent($_SESSION['user'])   → l'organisation choisie
            └─ GET /admin/realms/{realm}/users/{uid}/organizations
       └─ kcOrgMembers($org['id'])                 → les membres
            └─ GET /admin/realms/{realm}/organizations/{id}/members

   -------------------- Pré-requis Keycloak ----------------------------
   On réutilise le client OIDC du portail — KEYCLOAK_CLIENT_ID /
   KEYCLOAK_CLIENT_SECRET (« siteweb ») — comme compte de service. Aucune
   variable supplémentaire : pas de KEYCLOAK_ADMIN_CLIENT_ID/_SECRET.

   Sur ce client, dans Keycloak :
       - « Client authentication » = ON (client confidentiel, déjà requis
         par la connexion REST) ;
       - « Service accounts roles » = ON (active le grant client_credentials) ;
       - dans les rôles du client « realm-management », affecter au compte
         de service :
             · view-organizations   (lister les organisations et leurs membres)
             · view-users           (lire les comptes membres)

   Sans ces rôles, l'API répond 403 et la page affiche un message explicite
   plutôt qu'une liste vide.

   Seul réglage optionnel :
       KEYCLOAK_ORG_MEMBERS_MAX      défaut : 500 (plafond dur : 2000)

   Ce fichier ne définit QUE des fonctions (aucune sortie à l'inclusion).
   Les helpers portent le préfixe kcOrg* — distinct des kcRestAdmin* de
   include/keycloak_rest.php (mot de passe oublié), pour que le comportement
   ne dépende PAS de l'ordre de chargement des deux fichiers.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/../config_loader.php';   // config()
require_once __DIR__ . '/keycloak_auth.php';      // keycloakHttpRequest(), keycloakGetIssuer(), …

/** Nombre maximum de membres remontés (garde-fou contre une organisation énorme). */
if (!defined('KC_ORG_MEMBERS_HARD_LIMIT')) {
    define('KC_ORG_MEMBERS_HARD_LIMIT', 2000);
}

/* ==================== Compte de service / Admin REST ==================== */

if (!function_exists('kcOrgAdminBase')) {
    /** Déduit la base Admin REST de l'issuer (https://host/auth/admin/realms/<realm>). */
    function kcOrgAdminBase(): string
    {
        $iss = rtrim(keycloakGetIssuer(), '/');
        $pos = strpos($iss, '/realms/');
        if ($pos === false) return $iss . '/admin';
        $server = substr($iss, 0, $pos);                              // https://host/auth
        $realm  = trim(substr($iss, $pos + strlen('/realms/')), '/');  // client-auth
        return $server . '/admin/realms/' . rawurlencode($realm);
    }
}
if (!function_exists('kcOrgAdminToken')) {
    /**
     * Jeton client_credentials du client du portail (KEYCLOAK_CLIENT_ID /
     * KEYCLOAK_CLIENT_SECRET), mis en cache le temps de la requête PHP.
     * Renvoie null si le grant échoue — l'appelant produit alors un message
     * exploitable ; le détail (HTTP + error_description) part dans les logs.
     */
    function kcOrgAdminToken(): ?string
    {
        static $tok = null, $exp = 0;
        if ($tok !== null && time() < $exp - 15) return $tok;

        $clientId     = keycloakGetClientId();
        $clientSecret = keycloakGetClientSecret();
        if ($clientId === '' || $clientSecret === '') {
            error_log('[GNL KC-ORG] KEYCLOAK_CLIENT_ID / KEYCLOAK_CLIENT_SECRET manquant.');
            return null;
        }

        try {
            $resp = keycloakHttpRequest(
                keycloakGetIssuer() . '/protocol/openid-connect/token',
                [
                    CURLOPT_POST       => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $clientId,
                        'client_secret' => $clientSecret,
                    ], '', '&', PHP_QUERY_RFC3986),
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                ]
            );
        } catch (Throwable $e) {
            error_log('[GNL KC-ORG] token client_credentials — erreur réseau : ' . $e->getMessage());
            return null;
        }

        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        if (empty($body['access_token'])) {
            error_log('[GNL KC-ORG] token client_credentials refusé : HTTP ' . (int) ($resp['status'] ?? 0)
                . ' ' . (string) ($body['error'] ?? '') . ' ' . (string) ($body['error_description'] ?? '')
                . ' — vérifiez que « Service accounts roles » est activé sur le client ' . $clientId . '.');
            return null;
        }
        $tok = (string) $body['access_token'];
        $exp = time() + (isset($body['expires_in']) ? (int) $body['expires_in'] : 60);
        return $tok;
    }
}

/**
 * GET sur l'Admin REST API. Ne lève jamais : renvoie toujours
 * { status:int, body:array, error:string } — error non vide = échec exploitable.
 */
if (!function_exists('kcOrgAdminGet')) {
    function kcOrgAdminGet(string $path, array $query = []): array
    {
        $bearer = kcOrgAdminToken();
        if ($bearer === null) {
            return [
                'status' => 0,
                'body'   => [],
                'error'  => "Keycloak n'a pas délivré de jeton de service : serveur injoignable, KEYCLOAK_CLIENT_ID / KEYCLOAK_CLIENT_SECRET invalides, ou « Service accounts roles » désactivé sur le client (le détail est dans les logs du portail).",
            ];
        }

        $url = kcOrgAdminBase() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        try {
            $resp = keycloakHttpRequest($url, [
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $bearer],
            ]);
        } catch (Throwable $e) {
            error_log('[GNL KC-ORG] GET ' . $path . ' : ' . $e->getMessage());
            return ['status' => 0, 'body' => [], 'error' => 'Keycloak est injoignable.'];
        }

        $status = (int) ($resp['status'] ?? 0);
        $body   = (isset($resp['body']) && is_array($resp['body'])) ? $resp['body'] : [];

        if ($status >= 200 && $status < 300) {
            return ['status' => $status, 'body' => $body, 'error' => ''];
        }

        $error = 'Keycloak a renvoyé HTTP ' . $status;
        if ($status === 403) {
            $error .= " — le compte de service n'a pas les rôles « view-organizations » et « view-users » (realm-management).";
        } elseif ($status === 404) {
            $error .= " — endpoint Organizations absent (fonctionnalité désactivée sur le realm, ou Keycloak < 26).";
        } elseif (!empty($body['errorMessage'])) {
            $error .= ' — ' . (string) $body['errorMessage'];
        }
        error_log('[GNL KC-ORG] GET ' . $path . ' → ' . $error);

        return ['status' => $status, 'body' => $body, 'error' => $error];
    }
}

/* ============================ Normalisation ============================ */

/** Aplati les attributs Keycloak ({cle:[val]} ou {cle:val}) en {cle: "val"}. */
if (!function_exists('kcOrgFlattenAttributes')) {
    function kcOrgFlattenAttributes($attrs): array
    {
        $out = [];
        if (!is_array($attrs)) return $out;
        foreach ($attrs as $k => $v) {
            if (is_array($v)) {
                $out[(string) $k] = isset($v[0]) && is_scalar($v[0]) ? trim((string) $v[0]) : '';
            } elseif (is_scalar($v)) {
                $out[(string) $k] = trim((string) $v);
            }
        }
        return $out;
    }
}

/** OrganizationRepresentation -> forme interne stable. */
if (!function_exists('kcOrgNormalize')) {
    function kcOrgNormalize(array $org): array
    {
        $attrs = kcOrgFlattenAttributes($org['attributes'] ?? []);

        $label = '';
        foreach (['nom_commercial', 'raison', 'raison_social'] as $k) {
            if (($attrs[$k] ?? '') !== '') { $label = $attrs[$k]; break; }
        }
        if ($label === '') $label = trim((string) ($org['displayName'] ?? ''));
        if ($label === '') $label = trim((string) ($org['name'] ?? ''));
        if ($label === '') $label = trim((string) ($org['alias'] ?? ''));

        $domains = [];
        if (isset($org['domains']) && is_array($org['domains'])) {
            foreach ($org['domains'] as $d) {
                if (is_array($d) && isset($d['name'])) $domains[] = strtolower(trim((string) $d['name']));
                elseif (is_scalar($d))                 $domains[] = strtolower(trim((string) $d));
            }
        }

        return [
            'id'           => trim((string) ($org['id'] ?? '')),
            'name'         => trim((string) ($org['name'] ?? '')),
            'alias'        => trim((string) ($org['alias'] ?? '')),
            'display_name' => trim((string) ($org['displayName'] ?? '')),
            'label'        => $label,
            'enabled'      => !array_key_exists('enabled', $org) || (bool) $org['enabled'],
            'attributes'   => $attrs,
            'domains'      => array_values(array_filter($domains)),
        ];
    }
}

/* ===================== Identité de l'utilisateur ======================= */

/**
 * UID Keycloak (claim « sub ») de l'utilisateur de session.
 * Le flow REST pose id = UID ; le flow « code » garde un id entier local,
 * d'où les clés de repli. Renvoie '' si seule une clé entière est présente.
 */
if (!function_exists('kcOrgSessionUserId')) {
    function kcOrgSessionUserId(array $sessionUser): string
    {
        foreach (['keycloak_uid', 'sub', 'kc_uid', 'id'] as $k) {
            $v = trim((string) ($sessionUser[$k] ?? ''));
            if ($v !== '' && !ctype_digit($v)) return $v; // un id entier local n'est pas un UID
        }
        return '';
    }
}

/**
 * Repli pour les sessions ouvertes AVANT l'ajout du contexte organisation :
 * retrouve l'UID Keycloak par e-mail, puis par nom d'utilisateur.
 */
if (!function_exists('kcOrgFindUserId')) {
    function kcOrgFindUserId(string $email, string $username = ''): string
    {
        foreach ([['email', $email], ['username', $username]] as [$field, $value]) {
            $value = trim($value);
            if ($value === '') continue;
            $r = kcOrgAdminGet('/users', [$field => $value, 'exact' => 'true', 'max' => 2]);
            if ($r['status'] !== 200) continue;
            $rows = array_values(array_filter($r['body'], 'is_array'));
            if (count($rows) === 1 && !empty($rows[0]['id'])) {
                return trim((string) $rows[0]['id']);
            }
        }
        return '';
    }
}

/* ======================= Organisations d'un membre ===================== */

/** Organisations dont l'utilisateur est membre. { ok, orgs[], error }. */
if (!function_exists('kcOrgListForUser')) {
    function kcOrgListForUser(string $userId): array
    {
        if ($userId === '') {
            return ['ok' => false, 'orgs' => [], 'error' => "Identifiant Keycloak de l'utilisateur introuvable."];
        }
        $r = kcOrgAdminGet('/users/' . rawurlencode($userId) . '/organizations', ['briefRepresentation' => 'false']);
        if ($r['status'] !== 200) {
            return ['ok' => false, 'orgs' => [], 'error' => $r['error'] ?: 'Organisations Keycloak indisponibles.'];
        }
        $rows = array_values(array_filter($r['body'], 'is_array'));
        return ['ok' => true, 'orgs' => array_map('kcOrgNormalize', $rows), 'error' => ''];
    }
}

/** Une organisation par son id. { ok, org|null, error }. */
if (!function_exists('kcOrgById')) {
    function kcOrgById(string $orgId): array
    {
        $orgId = trim($orgId);
        if ($orgId === '') return ['ok' => false, 'org' => null, 'error' => 'Organisation non identifiée.'];
        $r = kcOrgAdminGet('/organizations/' . rawurlencode($orgId));
        if ($r['status'] !== 200 || empty($r['body']['id'])) {
            return ['ok' => false, 'org' => null, 'error' => $r['error'] ?: 'Organisation Keycloak introuvable.'];
        }
        return ['ok' => true, 'org' => kcOrgNormalize($r['body']), 'error' => ''];
    }
}

/**
 * Détermine l'organisation COURANTE : celle que l'utilisateur a retenue à la
 * connexion (page /organisation), mémorisée en session par
 * keycloakAttachOrganizationContext().
 *
 * Ordre de résolution :
 *   1. kc_org_id en session            -> lecture directe (chemin nominal) ;
 *   2. organisations du membre         -> une seule : c'est elle ;
 *   3. plusieurs organisations         -> appariement sur les repères de session
 *                                         (alias/nom retenu, namespace, siret,
 *                                          raison sociale, domaine e-mail) ;
 *   4. aucun appariement fiable        -> erreur explicite (pas de devinette).
 *
 * Retour : { ok:bool, org:array|null, error:string, candidates:int }.
 */
if (!function_exists('kcOrgResolveCurrent')) {
    function kcOrgResolveCurrent(array $sessionUser): array
    {
        // 1) Identifiant mémorisé à la connexion : chemin le plus sûr.
        $pinned = trim((string) ($sessionUser['kc_org_id'] ?? ''));
        if ($pinned !== '') {
            $r = kcOrgById($pinned);
            if ($r['ok']) return ['ok' => true, 'org' => $r['org'], 'error' => '', 'candidates' => 1];
            // L'organisation a pu être renommée/supprimée : on retombe sur la suite.
        }

        // 2) Liste des organisations du membre.
        $uid = kcOrgSessionUserId($sessionUser);
        if ($uid === '') {
            $uid = kcOrgFindUserId(
                (string) ($sessionUser['email'] ?? ''),
                (string) ($sessionUser['username'] ?? '')
            );
        }
        if ($uid === '') {
            return [
                'ok' => false, 'org' => null, 'candidates' => 0,
                'error' => "Impossible d'identifier votre compte dans Keycloak. Reconnectez-vous.",
            ];
        }

        $list = kcOrgListForUser($uid);
        if (!$list['ok']) {
            return ['ok' => false, 'org' => null, 'candidates' => 0, 'error' => $list['error']];
        }

        $orgs = $list['orgs'];
        if ($orgs === []) {
            return [
                'ok' => false, 'org' => null, 'candidates' => 0,
                'error' => "Votre compte n'est rattaché à aucune organisation Keycloak.",
            ];
        }
        if (count($orgs) === 1) {
            return ['ok' => true, 'org' => $orgs[0], 'error' => '', 'candidates' => 1];
        }

        // 3) Appariement avec le choix fait à la connexion.
        $norm = static function ($v): string {
            if (!is_scalar($v)) return '';
            return (string) preg_replace('/\s+/', ' ', strtolower(trim((string) $v)));
        };
        $digits = static function ($v): string {
            if (!is_scalar($v)) return '';
            return (string) preg_replace('/\D/', '', (string) $v);
        };

        $wantedName  = $norm($sessionUser['kc_org_alias'] ?? ($sessionUser['kc_org_name'] ?? ''));
        $wantedNs    = $norm($sessionUser['k8s_namespace'] ?? ($sessionUser['namespace'] ?? ''));
        $wantedSiret = $digits($sessionUser['siret'] ?? '');
        $wantedLabel = $norm($sessionUser['raison'] ?? ($sessionUser['nom_commercial'] ?? ''));

        foreach ($orgs as $org) {
            if ($wantedName !== '' && in_array($wantedName, [$norm($org['alias']), $norm($org['name'])], true)) {
                return ['ok' => true, 'org' => $org, 'error' => '', 'candidates' => count($orgs)];
            }
        }
        foreach ($orgs as $org) {
            $ns = $norm($org['attributes']['namespace'] ?? ($org['attributes']['k8s_namespace'] ?? ''));
            if ($wantedNs !== '' && $ns === $wantedNs) {
                return ['ok' => true, 'org' => $org, 'error' => '', 'candidates' => count($orgs)];
            }
        }
        foreach ($orgs as $org) {
            $siret = $digits($org['attributes']['siret'] ?? '');
            if ($wantedSiret !== '' && $siret !== '' && $siret === $wantedSiret) {
                return ['ok' => true, 'org' => $org, 'error' => '', 'candidates' => count($orgs)];
            }
        }
        foreach ($orgs as $org) {
            if ($wantedLabel !== '' && $norm($org['label']) === $wantedLabel) {
                return ['ok' => true, 'org' => $org, 'error' => '', 'candidates' => count($orgs)];
            }
        }

        // 4) Ambiguïté : on refuse de choisir à la place de l'utilisateur.
        return [
            'ok' => false, 'org' => null, 'candidates' => count($orgs),
            'error' => "Vous appartenez à plusieurs organisations et celle de cette session n'a pas pu être retrouvée. Reconnectez-vous pour la choisir à nouveau.",
        ];
    }
}

/* ============================== Membres ================================ */

/**
 * Membres d'une organisation (pagination Admin REST, 100 par page).
 * Retour : { ok:bool, members:array, truncated:bool, error:string }.
 */
if (!function_exists('kcOrgMembers')) {
    function kcOrgMembers(string $orgId, ?int $max = null): array
    {
        $orgId = trim($orgId);
        if ($orgId === '') {
            return ['ok' => false, 'members' => [], 'truncated' => false, 'error' => 'Organisation non identifiée.'];
        }

        if ($max === null) {
            $max = (int) config('KEYCLOAK_ORG_MEMBERS_MAX', 500);
        }
        $max = max(1, min($max, KC_ORG_MEMBERS_HARD_LIMIT));

        $page      = 100;
        $first     = 0;
        $all       = [];
        $truncated = false;

        // Garde-fou : au pire KC_ORG_MEMBERS_HARD_LIMIT / $page itérations.
        for ($i = 0, $guard = (int) ceil(KC_ORG_MEMBERS_HARD_LIMIT / $page) + 1; $i < $guard; $i++) {
            $r = kcOrgAdminGet('/organizations/' . rawurlencode($orgId) . '/members', [
                'first'               => $first,
                'max'                 => $page,
                'briefRepresentation' => 'false',
            ]);
            if ($r['status'] !== 200) {
                return ['ok' => false, 'members' => [], 'truncated' => false, 'error' => $r['error'] ?: 'Membres Keycloak indisponibles.'];
            }

            $rows = array_values(array_filter($r['body'], 'is_array'));
            foreach ($rows as $row) {
                if (count($all) >= $max) { $truncated = true; break 2; }
                $all[] = $row;
            }

            if (count($rows) < $page) break; // dernière page
            $first += $page;
        }

        return ['ok' => true, 'members' => $all, 'truncated' => $truncated, 'error' => ''];
    }
}
