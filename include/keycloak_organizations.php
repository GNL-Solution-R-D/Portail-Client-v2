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
            └─ GET /admin/realms/{realm}/organizations/members/{uid}/organizations
       └─ kcOrgMembers($org['id'])                 → les membres
            └─ GET /admin/realms/{realm}/organizations/{id}/members

   ⚠️ Piège d'URL : les organisations d'un utilisateur se lisent sous
      /organizations/members/{uid}/organizations — et NON sous
      /users/{uid}/organizations, qui n'existe pas et renvoie un 404 même
      quand la fonctionnalité Organizations est parfaitement active.
      (OrganizationsResource.members() → @Path("members"), puis
       OrganizationMemberResource.getOrganizations() → @Path("{member-id}/organizations").)

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

   Réglages optionnels :
       KEYCLOAK_ORG_MEMBERS_MAX      défaut : 500 (plafond dur : 2000)
       KEYCLOAK_ORG_DEBUG=1          journalise chaque appel Admin REST réussi

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
            if ((string) config('KEYCLOAK_ORG_DEBUG', '0') === '1') {
                error_log('[GNL KC-ORG] GET ' . $path . ' → HTTP ' . $status . ' (' . count($body) . ' élément(s))');
            }
            return ['status' => $status, 'body' => $body, 'error' => ''];
        }

        // Le chemin appelé fait partie du diagnostic : un 404 sur Organizations
        // vient presque toujours d'une URL, pas d'une fonctionnalité absente.
        $error = 'Keycloak a renvoyé HTTP ' . $status . ' sur ' . $path;
        if ($status === 403) {
            $error .= " — le compte de service n'a pas les rôles « view-organizations » et « view-users » (realm-management).";
        } elseif ($status === 404) {
            $error .= " — ressource introuvable (fonctionnalité Organizations désactivée sur le realm, endpoint absent de cette version de Keycloak, ou identifiant inconnu).";
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

/**
 * OrganizationRepresentation -> forme interne stable.
 *
 * « label » est le nom affiché par /equipes (champ structureName + colonne
 * Fonction). Règle voulue, dans cet ordre STRICT :
 *   1. l'attribut d'organisation « nom_commercial » ;
 *   2. à défaut, le nom de l'organisation (`name`).
 * `alias` ne sert que de garde-fou si `name` était vide — Keycloak l'impose,
 * donc en pratique on n'y arrive jamais. Ni `raison`, ni `displayName` :
 * l'attribut métier, puis le nom, et rien d'autre.
 */
if (!function_exists('kcOrgNormalize')) {
    function kcOrgNormalize(array $org): array
    {
        $attrs = kcOrgFlattenAttributes($org['attributes'] ?? []);

        $label = trim((string) ($attrs['nom_commercial'] ?? ''));
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

/**
 * Organisations dont l'utilisateur est membre.
 *
 * ⚠️ Le chemin officiel est
 *      GET /admin/realms/{realm}/organizations/members/{member-id}/organizations
 *    (OrganizationsResource.members() → @Path("members"), puis
 *     OrganizationMemberResource.getOrganizations() → @Path("{member-id}/organizations")).
 *    Il n'existe PAS de /admin/realms/{realm}/users/{id}/organizations : cette
 *    URL-là renvoie un 404 même quand la fonctionnalité Organizations est
 *    parfaitement active.
 *
 * Retour : { ok:bool, orgs:array, supported:bool, error:string }.
 * supported=false ⇒ 404 sur l'endpoint : l'appelant bascule sur la recherche
 * par repères de session plutôt que d'échouer.
 */
if (!function_exists('kcOrgListForUser')) {
    function kcOrgListForUser(string $userId): array
    {
        if ($userId === '') {
            return ['ok' => false, 'orgs' => [], 'supported' => true, 'error' => "Identifiant Keycloak de l'utilisateur introuvable."];
        }

        $r = kcOrgAdminGet(
            '/organizations/members/' . rawurlencode($userId) . '/organizations',
            ['briefRepresentation' => 'false']
        );

        if ($r['status'] === 200) {
            $rows = array_values(array_filter($r['body'], 'is_array'));
            return ['ok' => true, 'orgs' => array_map('kcOrgNormalize', $rows), 'supported' => true, 'error' => ''];
        }
        if ($r['status'] === 404) {
            return ['ok' => false, 'orgs' => [], 'supported' => false, 'error' => $r['error']];
        }
        return ['ok' => false, 'orgs' => [], 'supported' => true, 'error' => $r['error'] ?: 'Organisations Keycloak indisponibles.'];
    }
}

/**
 * Recherche d'organisations du realm. `search` porte sur le nom, l'alias et les
 * domaines. Sans terme, renvoie les `max` premières. { ok, orgs[], error }.
 */
if (!function_exists('kcOrgSearch')) {
    function kcOrgSearch(string $term = '', int $max = 100): array
    {
        $query = ['first' => 0, 'max' => max(1, $max), 'briefRepresentation' => 'false'];
        if (trim($term) !== '') {
            $query['search'] = trim($term);
        }
        $r = kcOrgAdminGet('/organizations', $query);
        if ($r['status'] !== 200) {
            return ['ok' => false, 'orgs' => [], 'error' => $r['error'] ?: 'Organisations Keycloak indisponibles.'];
        }
        $rows = array_values(array_filter($r['body'], 'is_array'));
        return ['ok' => true, 'orgs' => array_map('kcOrgNormalize', $rows), 'error' => ''];
    }
}

/**
 * L'utilisateur est-il membre de cette organisation ?
 * GET /organizations/{orgId}/members/{userId} — 200 = oui, 404 = non.
 * Renvoie null quand la question n'a pas pu être tranchée (403, réseau…).
 */
if (!function_exists('kcOrgIsMember')) {
    function kcOrgIsMember(string $orgId, string $userId): ?bool
    {
        if ($orgId === '' || $userId === '') return null;
        $r = kcOrgAdminGet('/organizations/' . rawurlencode($orgId) . '/members/' . rawurlencode($userId));
        if ($r['status'] === 200) return true;
        if ($r['status'] === 404) return false;
        return null;
    }
}

/**
 * Garantit que l'organisation porte bien ses ATTRIBUTS — donc son
 * « nom_commercial », dont dépend le nom affiché par /equipes.
 *
 * Certaines routes de l'Admin REST renvoient une représentation « brève »
 * sans les attributs, même avec briefRepresentation=false. Un tableau
 * d'attributs VIDE est le signal : on relit alors l'organisation par son id
 * (GET /organizations/{id}), qui les renvoie toujours. Des attributs présents
 * mais sans « nom_commercial » signifient qu'il n'est réellement pas
 * renseigné : on garde le nom de l'organisation, sans appel inutile.
 */
if (!function_exists('kcOrgEnsureAttributes')) {
    function kcOrgEnsureAttributes(array $org): array
    {
        if (($org['attributes'] ?? []) !== []) return $org;

        $id = trim((string) ($org['id'] ?? ''));
        if ($id === '') return $org;

        $full = kcOrgById($id);
        if (!$full['ok'] || !is_array($full['org']) || ($full['org']['attributes'] ?? []) === []) {
            return $org;
        }
        return $full['org'];
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

/** Repères de session servant à reconnaître l'organisation retenue. */
if (!function_exists('kcOrgSessionHints')) {
    function kcOrgSessionHints(array $sessionUser): array
    {
        $norm = static function ($v): string {
            if (!is_scalar($v)) return '';
            return (string) preg_replace('/\s+/', ' ', strtolower(trim((string) $v)));
        };
        $digits = static function ($v): string {
            if (!is_scalar($v)) return '';
            return (string) preg_replace('/\D/', '', (string) $v);
        };

        // Plusieurs dénominations possibles côté session : on les teste toutes
        // contre toutes celles de l'organisation (voir kcOrgComparableNames()).
        $labels = [];
        foreach (['raison', 'nom_commercial', 'organization_name', 'organization_commercial_name'] as $k) {
            $v = $norm($sessionUser[$k] ?? '');
            if ($v !== '') $labels[$v] = true;
        }

        return [
            'name'   => $norm($sessionUser['kc_org_alias'] ?? ($sessionUser['kc_org_name'] ?? '')),
            'ns'     => $norm($sessionUser['k8s_namespace'] ?? ($sessionUser['namespace'] ?? '')),
            'siret'  => $digits($sessionUser['siret'] ?? ''),
            'labels' => array_keys($labels),
            '_norm'  => $norm,
            '_dig'   => $digits,
        ];
    }
}

/**
 * Toutes les dénominations sous lesquelles une organisation peut être reconnue.
 * Sert UNIQUEMENT à l'appariement : le nom AFFICHÉ, lui, suit la règle stricte
 * de kcOrgNormalize() (nom_commercial, puis name). On reste large ici pour ne
 * pas perdre la désambiguïsation multi-organisations.
 */
if (!function_exists('kcOrgComparableNames')) {
    function kcOrgComparableNames(array $org, callable $norm): array
    {
        $names = [
            $norm($org['alias'] ?? ''),
            $norm($org['name'] ?? ''),
            $norm($org['display_name'] ?? ''),
            $norm($org['label'] ?? ''),
        ];
        $attrs = (isset($org['attributes']) && is_array($org['attributes'])) ? $org['attributes'] : [];
        foreach (['nom_commercial', 'raison', 'raison_social'] as $k) {
            $names[] = $norm($attrs[$k] ?? '');
        }
        return array_values(array_unique(array_filter($names, static function ($v) { return $v !== ''; })));
    }
}

/**
 * Parmi des organisations candidates, celle qui correspond aux repères de
 * session. Essaie dans l'ordre : alias/nom retenu à la connexion, namespace
 * Kubernetes, SIRET, puis dénominations (raison sociale / nom commercial).
 * Renvoie null si rien ne correspond — on ne devine jamais.
 */
if (!function_exists('kcOrgMatchFromHints')) {
    function kcOrgMatchFromHints(array $orgs, array $hints): ?array
    {
        $norm   = $hints['_norm'];
        $digits = $hints['_dig'];

        foreach ($orgs as $org) {
            if ($hints['name'] !== '' && in_array($hints['name'], [$norm($org['alias']), $norm($org['name'])], true)) {
                return $org;
            }
        }
        foreach ($orgs as $org) {
            $ns = $norm($org['attributes']['namespace'] ?? ($org['attributes']['k8s_namespace'] ?? ''));
            if ($hints['ns'] !== '' && $ns === $hints['ns']) {
                return $org;
            }
        }
        foreach ($orgs as $org) {
            $siret = $digits($org['attributes']['siret'] ?? '');
            if ($hints['siret'] !== '' && $siret !== '' && $siret === $hints['siret']) {
                return $org;
            }
        }
        if ($hints['labels'] !== []) {
            foreach ($orgs as $org) {
                if (array_intersect($hints['labels'], kcOrgComparableNames($org, $norm)) !== []) {
                    return $org;
                }
            }
        }
        return null;
    }
}

/**
 * Détermine l'organisation COURANTE : celle que l'utilisateur a retenue à la
 * connexion (page /organisation), mémorisée en session par
 * keycloakAttachOrganizationContext().
 *
 * Ordre de résolution :
 *   1. kc_org_id en session      -> lecture directe (chemin le plus sûr) ;
 *   2. organisations DU MEMBRE   -> GET /organizations/members/{uid}/organizations
 *                                   une seule : c'est elle ; plusieurs :
 *                                   appariement sur les repères de session ;
 *   3. endpoint absent (404)     -> repli : recherche dans les organisations du
 *                                   realm sur les mêmes repères, PUIS
 *                                   vérification d'appartenance ;
 *   4. rien de fiable            -> erreur explicite (pas de devinette).
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

        $hints = kcOrgSessionHints($sessionUser);

        // 2) Organisations du membre (chemin nominal).
        $list = kcOrgListForUser($uid);

        if ($list['ok']) {
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
            $match = kcOrgMatchFromHints($orgs, $hints);
            if ($match !== null) {
                return ['ok' => true, 'org' => $match, 'error' => '', 'candidates' => count($orgs)];
            }
            return [
                'ok' => false, 'org' => null, 'candidates' => count($orgs),
                'error' => "Vous appartenez à plusieurs organisations et celle de cette session n'a pas pu être retrouvée. Reconnectez-vous pour la choisir à nouveau.",
            ];
        }

        // Échec autre qu'un 404 (403, réseau, Organizations désactivé…) :
        // inutile d'insister, le message est déjà exploitable.
        if ($list['supported']) {
            return ['ok' => false, 'org' => null, 'candidates' => 0, 'error' => $list['error']];
        }

        // 3) Repli : l'endpoint « organisations du membre » n'existe pas sur
        //    cette version. On cherche dans les organisations du realm sur les
        //    repères de session, puis on VÉRIFIE l'appartenance — sans quoi on
        //    risquerait d'afficher les membres d'une autre société.
        error_log('[GNL KC-ORG] /organizations/members/{id}/organizations indisponible — repli par recherche.');

        $candidates = [];
        if ($hints['name'] !== '') {
            $s = kcOrgSearch($hints['name'], 20);
            if ($s['ok']) $candidates = $s['orgs'];
        }
        if ($candidates === []) {
            $s = kcOrgSearch('', 100);
            if (!$s['ok']) {
                return ['ok' => false, 'org' => null, 'candidates' => 0, 'error' => $s['error']];
            }
            $candidates = $s['orgs'];
        }

        $match = kcOrgMatchFromHints($candidates, $hints);
        if ($match === null) {
            return [
                'ok' => false, 'org' => null, 'candidates' => count($candidates),
                'error' => "L'organisation de cette session n'a pas pu être retrouvée dans Keycloak. Reconnectez-vous pour la choisir à nouveau.",
            ];
        }

        $isMember = kcOrgIsMember((string) $match['id'], $uid);
        if ($isMember === false) {
            return [
                'ok' => false, 'org' => null, 'candidates' => count($candidates),
                'error' => "Votre compte n'est pas membre de l'organisation « " . $match['label'] . " » dans Keycloak.",
            ];
        }
        // $isMember === null : question non tranchée (403, réseau). On accepte
        // l'appariement — il vient des claims de l'utilisateur — et on le note.
        if ($isMember === null) {
            error_log('[GNL KC-ORG] appartenance non vérifiable pour ' . $uid . ' / org ' . $match['id'] . '.');
        }

        return ['ok' => true, 'org' => $match, 'error' => '', 'candidates' => count($candidates)];
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
