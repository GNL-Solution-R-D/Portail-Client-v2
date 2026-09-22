<?php
/* =====================================================================
   GNL Solution — Services, fonctions et droits (include/org_permissions.php)
   ---------------------------------------------------------------------
   Modèle (groupes d'organisation Keycloak, voir keycloak_organizations.php) :

     Organisation
       ├─ R&D                ← SERVICE  (groupe de 1er niveau)
       │    └─ Directeur     ← FONCTION (sous-groupe) → affichée « Directeur R&D »
       └─ Global             ← service réservé aux fonctions SANS service
            └─ Gérant        → affichée « Gérant »

   Chaque groupe peut porter l'attribut « perm » : une LISTE DE CLÉS
   séparées par des virgules (espaces, « ; » et « | » tolérés), par ex.

       perm = invoices.view,company.edit,teams.assign

   Plusieurs valeurs d'attribut sont aussi acceptées (saisie console).

   Droits effectifs d'un groupe  = ses clés ∪ celles de TOUS ses ancêtres.
   Droits effectifs d'un membre  = union sur toutes ses fonctions.
   → un sous-groupe (ou sous-sous-groupe) plus élevé l'emporte sur son parent,
     et un parent ne peut jamais retirer ce qu'un enfant accorde.

   Garde-fous appliqués par data/portail_api.php (actions team.*) :
     - on ne peut accorder / attribuer / modifier que des droits qu'on détient
       soi-même (pas d'élévation de privilèges) ;
     - on ne peut pas retirer le dernier gestionnaire (teams.manage) ;
     - « mode initialisation » : tant qu'AUCUN membre ne détient
       teams.manage, les membres gérés (MANAGED) de l'organisation ont tous
       les droits, pour pouvoir créer la première fonction d'administration.

   Ce fichier ne définit QUE des fonctions (aucune sortie à l'inclusion).
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/keycloak_organizations.php';

/** Nom d'attribut Keycloak portant les droits. */
if (!defined('ORG_PERM_ATTRIBUTE')) {
    define('ORG_PERM_ATTRIBUTE', 'perm');
}
/** Service réservé aux fonctions sans service. */
if (!defined('ORG_GLOBAL_SERVICE')) {
    define('ORG_GLOBAL_SERVICE', 'Global');
}
/** Durée de vie du cache des droits de l'utilisateur courant (secondes). */
if (!defined('ORG_PERMS_TTL')) {
    define('ORG_PERMS_TTL', 300);
}

/**
 * Catalogue des droits. L'ordre est celui de l'affichage.
 * Ajouter une clé ici suffit à la rendre attribuable depuis /equipes.
 */
if (!function_exists('orgPermCatalog')) {
    function orgPermCatalog(): array
    {
        return [
            '*'               => ['label' => 'Administrateur (tous les droits)',          'group' => 'Administration'],
            'teams.manage'    => ['label' => 'Gérer les services, fonctions et droits',  'group' => 'Équipe'],
            'teams.assign'    => ['label' => 'Attribuer les fonctions aux membres',      'group' => 'Équipe'],
            'company.edit'    => ['label' => "Modifier les informations de l'entreprise", 'group' => 'Entreprise'],
            'invoices.view'   => ['label' => 'Voir les factures',                         'group' => 'Facturation'],
            'orders.view'     => ['label' => 'Voir les commandes et abonnements',         'group' => 'Facturation'],
            'services.manage' => ['label' => 'Gérer les services déployés',               'group' => 'Technique'],
            'dns.manage'      => ['label' => 'Gérer les domaines et la zone DNS',         'group' => 'Technique'],
            'tickets.manage'  => ['label' => 'Ouvrir et suivre les tickets de support',   'group' => 'Support'],
        ];
    }
}

/** Droits impliqués par un autre (en plus de « * » qui implique tout). */
if (!function_exists('orgPermImplied')) {
    function orgPermImplied(): array
    {
        return [
            'teams.manage' => ['teams.assign'],
        ];
    }
}

/**
 * Valeur(s) d'attribut → liste de clés normalisées (minuscules, dédoublonnées).
 * Accepte une chaîne « a,b c;d » ou un tableau de chaînes.
 */
if (!function_exists('orgPermParse')) {
    function orgPermParse($raw): array
    {
        $vals = is_array($raw) ? $raw : [$raw];
        $out  = [];
        foreach ($vals as $v) {
            if (!is_scalar($v)) continue;
            foreach (preg_split('/[\s,;|]+/', strtolower(trim((string) $v))) ?: [] as $k) {
                if ($k !== '' && preg_match('/^(\*|[a-z0-9_-]+(\.[a-z0-9_*-]+)*)$/', $k)) {
                    $out[$k] = true;
                }
            }
        }
        return array_keys($out);
    }
}

/** Liste de clés → valeur d'attribut canonique (ordre du catalogue d'abord). */
if (!function_exists('orgPermSerialize')) {
    function orgPermSerialize(array $keys): string
    {
        $keys = orgPermParse($keys);
        if (in_array('*', $keys, true)) return '*';
        $order = array_keys(orgPermCatalog());
        usort($keys, static function (string $a, string $b) use ($order): int {
            $ia = array_search($a, $order, true); $ib = array_search($b, $order, true);
            $ia = $ia === false ? 999 : $ia;      $ib = $ib === false ? 999 : $ib;
            return ($ia <=> $ib) ?: strcmp($a, $b);
        });
        return implode(',', $keys);
    }
}

/** Clés PROPRES d'un groupe normalisé (kcOrgGroupNormalize) — attribut « perm ». */
if (!function_exists('orgPermOfGroup')) {
    function orgPermOfGroup(array $group): array
    {
        $attrs = (isset($group['attributes']) && is_array($group['attributes'])) ? $group['attributes'] : [];
        return orgPermParse($attrs[ORG_PERM_ATTRIBUTE] ?? []);
    }
}

/** Ajoute les droits impliqués. « * » reste « * ». */
if (!function_exists('orgPermExpand')) {
    function orgPermExpand(array $keys): array
    {
        $set = array_fill_keys(orgPermParse($keys), true);
        if (isset($set['*'])) return ['*'];
        foreach (orgPermImplied() as $k => $implied) {
            if (isset($set[$k])) foreach ($implied as $i) $set[$i] = true;
        }
        return array_keys($set);
    }
}

/** L'ensemble $keys accorde-t-il $key ? (joker « * », préfixe « invoices.* »). */
if (!function_exists('orgPermHas')) {
    function orgPermHas(array $keys, string $key): bool
    {
        $key  = strtolower(trim($key));
        $keys = orgPermExpand($keys);
        if (in_array('*', $keys, true) || in_array($key, $keys, true)) return true;
        foreach ($keys as $k) {
            if (substr($k, -2) === '.*' && strpos($key, substr($k, 0, -1)) === 0) return true;
        }
        return false;
    }
}

/** $needed ⊆ $held ? (pour interdire l'élévation de privilèges). */
if (!function_exists('orgPermCovers')) {
    function orgPermCovers(array $held, array $needed): bool
    {
        $held = orgPermExpand($held);
        if (in_array('*', $held, true)) return true;
        foreach (orgPermExpand($needed) as $k) {
            if ($k === '*' || !orgPermHas($held, $k)) return false;
        }
        return true;
    }
}

/** Libellés lisibles d'un ensemble de clés. */
if (!function_exists('orgPermLabels')) {
    function orgPermLabels(array $keys): array
    {
        $cat = orgPermCatalog();
        $out = [];
        foreach (orgPermParse($keys) as $k) {
            $out[] = $cat[$k]['label'] ?? $k;
        }
        return $out;
    }
}

/* ============================ Arbre des groupes ============================ */

/**
 * Enrichit l'arbre à plat de kcOrgGroupsTree() :
 *   path        [noms depuis la racine]
 *   service     nom du groupe racine
 *   service_id  id du groupe racine
 *   is_service  groupe de 1er niveau
 *   is_global   appartient au service « Global »
 *   perm        clés PROPRES
 *   effective   clés propres ∪ ancêtres
 *   label       libellé de fonction : « Directeur R&D », « Gérant » (Global)
 *
 * Retour : [ id => nœud ] dans l'ordre du parcours.
 */
if (!function_exists('orgGroupsIndex')) {
    function orgGroupsIndex(array $groups): array
    {
        $byId = [];
        foreach ($groups as $g) {
            if (!is_array($g) || empty($g['id'])) continue;
            $byId[(string) $g['id']] = $g;
        }

        $out = [];
        foreach ($byId as $id => $g) {
            $id    = (string) $id;
            $chain = [];
            $cur   = $g;
            $guard = 0;
            while (is_array($cur) && $guard++ < 16) {
                array_unshift($chain, $cur);
                $pid = (string) ($cur['parent_id'] ?? '');
                $cur = ($pid !== '' && isset($byId[$pid])) ? $byId[$pid] : null;
            }
            $root = $chain[0];
            $eff  = [];
            foreach ($chain as $c) $eff = array_merge($eff, orgPermOfGroup($c));

            $isGlobal  = strcasecmp((string) $root['name'], ORG_GLOBAL_SERVICE) === 0;
            $isService = count($chain) === 1;
            $label     = (string) $g['name'];
            if (!$isService && !$isGlobal) $label .= ' ' . $root['name'];

            $out[$id] = [
                'id'         => $id,
                'name'       => (string) $g['name'],
                'parent_id'  => (string) ($g['parent_id'] ?? ''),
                'depth'      => count($chain) - 1,
                'path'       => array_map(static function ($c) { return (string) $c['name']; }, $chain),
                'service'    => (string) $root['name'],
                'service_id' => (string) $root['id'],
                'is_service' => $isService,
                'is_global'  => $isGlobal,
                'perm'       => orgPermOfGroup($g),
                'effective'  => orgPermExpand($eff),
                'label'      => $label,
                'attributes' => $g['attributes'] ?? [],
            ];
        }
        return $out;
    }
}

/** Ids du sous-arbre enraciné en $id (inclus). */
if (!function_exists('orgGroupSubtree')) {
    function orgGroupSubtree(array $index, string $id): array
    {
        $ids = [$id => true];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($index as $gid => $g) {
                if (!isset($ids[$gid]) && isset($ids[$g['parent_id']])) {
                    $ids[$gid] = true;
                    $changed = true;
                }
            }
        }
        return array_map('strval', array_keys($ids));
    }
}

/** Droits effectifs d'un membre à partir de ses groupes DIRECTS. */
if (!function_exists('orgPermsForGroups')) {
    function orgPermsForGroups(array $index, array $groupIds): array
    {
        $keys = [];
        foreach ($groupIds as $gid) {
            if (isset($index[$gid])) $keys = array_merge($keys, $index[$gid]['effective']);
        }
        return orgPermExpand($keys);
    }
}

/**
 * Appartenances directes de TOUS les membres : { uid => [groupIds] }.
 * Une requête par groupe (les organisations en ont peu).
 */
if (!function_exists('orgMembershipsByUser')) {
    function orgMembershipsByUser(string $orgId, array $index): array
    {
        $map = [];
        foreach ($index as $gid => $g) {
            $r = kcOrgGroupMemberIds($orgId, (string) $gid);
            if (!$r['ok']) {
                return ['ok' => false, 'map' => [], 'error' => $r['error']];
            }
            foreach ($r['ids'] as $uid) $map[$uid][] = (string) $gid;
        }
        return ['ok' => true, 'map' => $map, 'error' => ''];
    }
}

/** UID des membres qui détiennent teams.manage d'après $index + $memberships. */
if (!function_exists('orgManagers')) {
    function orgManagers(array $index, array $memberships): array
    {
        $out = [];
        foreach ($memberships as $uid => $gids) {
            if (orgPermHas(orgPermsForGroups($index, $gids), 'teams.manage')) $out[] = (string) $uid;
        }
        return $out;
    }
}

/* ========================== Contexte de l'acteur ========================== */

/**
 * Contexte complet pour /equipes et les actions team.* :
 *   ok, error, org, index, memberships, managers,
 *   actor { uid, groups, perms, bootstrap, managed }, groups_supported.
 */
if (!function_exists('orgTeamContext')) {
    function orgTeamContext(array $sessionUser): array
    {
        $fail = static function (string $e, $org = null): array {
            return [
                'ok' => false, 'error' => $e, 'org' => $org, 'index' => [], 'memberships' => [],
                'managers' => [], 'actor' => null, 'groups_supported' => true, 'truncated_groups' => false,
            ];
        };

        $resolved = kcOrgResolveCurrent($sessionUser);
        if (!$resolved['ok'] || !is_array($resolved['org'])) {
            return $fail($resolved['error'] !== '' ? $resolved['error'] : 'Organisation Keycloak introuvable.');
        }
        $org = kcOrgEnsureAttributes($resolved['org']);

        $uid = kcOrgSessionUserId($sessionUser);
        if ($uid === '') {
            $uid = kcOrgFindUserId((string) ($sessionUser['email'] ?? ''), (string) ($sessionUser['username'] ?? ''));
        }

        $tree = kcOrgGroupsTree((string) $org['id']);
        $groupsSupported = true;
        if (!$tree['ok']) {
            // 404 = Keycloak < 26.6 (pas de groupes d'organisation) : on ne
            // bloque pas l'annuaire, on désactive simplement la gestion.
            if (strpos($tree['error'], 'HTTP 404') !== false) {
                $groupsSupported = false;
                $tree = ['ok' => true, 'groups' => [], 'truncated' => false, 'error' => ''];
            } else {
                return $fail($tree['error'], $org);
            }
        }
        $index = orgGroupsIndex($tree['groups']);

        $ms = orgMembershipsByUser((string) $org['id'], $index);
        if (!$ms['ok']) {
            return $fail($ms['error'], $org);
        }
        $memberships = $ms['map'];

        $actorGroups = $memberships[$uid] ?? [];
        $perms       = orgPermsForGroups($index, $actorGroups);
        $managers    = orgManagers($index, $memberships);

        // Mode initialisation : personne ne gère encore l'équipe. Réservé aux
        // membres GÉRÉS de l'organisation (pas aux invités externes).
        $bootstrap = false;
        $managed   = true;
        if ($managers === [] && $uid !== '' && $groupsSupported) {
            $m = kcOrgMemberGet((string) $org['id'], $uid);
            $managed = !is_array($m) || strtoupper((string) ($m['membershipType'] ?? 'MANAGED')) !== 'UNMANAGED';
            if ($managed) {
                $bootstrap = true;
                $perms     = ['*'];
            }
        }

        return [
            'ok'               => true,
            'error'            => '',
            'org'              => $org,
            'index'            => $index,
            'memberships'      => $memberships,
            'managers'         => $managers,
            'truncated_groups' => (bool) $tree['truncated'],
            'groups_supported' => $groupsSupported,
            'actor'            => [
                'uid'       => $uid,
                'groups'    => $actorGroups,
                'perms'     => $perms,
                'bootstrap' => $bootstrap,
                'managed'   => $managed,
            ],
        ];
    }
}

/* =================== Droits de l'utilisateur COURANT (pages) ============== */

/**
 * Droits effectifs de l'utilisateur de session, mis en cache ORG_PERMS_TTL s.
 * Pour les autres pages : if (!orgUserCan('invoices.view')) { … }.
 * En cas d'erreur Keycloak, renvoie null (l'appelant décide : fail-open ou
 * fail-closed selon la sensibilité de la page).
 */
if (!function_exists('orgCurrentPerms')) {
    function orgCurrentPerms(bool $refresh = false): ?array
    {
        if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) return null;
        $c = $_SESSION['org_perms'] ?? null;
        if (!$refresh && is_array($c) && (time() - (int) ($c['at'] ?? 0)) < ORG_PERMS_TTL && is_array($c['perms'] ?? null)) {
            return $c['perms'];
        }
        $ctx = orgTeamContext($_SESSION['user']);
        if (!$ctx['ok']) return null;
        orgRememberPerms($ctx);
        return $ctx['actor']['perms'];
    }
}

if (!function_exists('orgRememberPerms')) {
    function orgRememberPerms(array $ctx): void
    {
        if (!$ctx['ok'] || !is_array($ctx['actor'])) return;
        $_SESSION['org_perms'] = [
            'at'        => time(),
            'org_id'    => (string) ($ctx['org']['id'] ?? ''),
            'perms'     => $ctx['actor']['perms'],
            'bootstrap' => $ctx['actor']['bootstrap'],
        ];
    }
}

if (!function_exists('orgForgetPerms')) {
    function orgForgetPerms(): void
    {
        unset($_SESSION['org_perms']);
    }
}

if (!function_exists('orgUserCan')) {
    function orgUserCan(string $key, bool $default = false): bool
    {
        $perms = orgCurrentPerms();
        if ($perms === null) return $default;
        return orgPermHas($perms, $key);
    }
}
