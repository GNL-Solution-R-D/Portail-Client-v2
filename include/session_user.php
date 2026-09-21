<?php

declare(strict_types=1);

/**
 * include/session_user.php
 *
 * Fonctions utilitaires pour lire les données utilisateur depuis $_SESSION['user'].
 *
 * Centralise les blocs répétés dans chaque page :
 *   - la dérivation du namespace Kubernetes (« ns-k8s ») — 7 fichiers concernés
 *   - la lecture sécurisée de l'id, du nom, du siret, etc.
 *   - la vérification que la session user est bien un tableau
 *
 * Usage :
 *   require_once '../include/session_user.php';
 *
 *   $ns  = sessionUserNsK8s();       // '610bf504-b94c-45f6-a184-a2cf8a2e5c94'
 *   $id  = sessionUserId();          // 42
 *   $nom = sessionUserField('nom');  // 'Jean Dupont'
 */

if (!function_exists('sessionUserArray')) {
    /**
     * Retourne le tableau $_SESSION['user'] ou [] si absent/invalide.
     */
    function sessionUserArray(): array
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user'])
            ? $_SESSION['user']
            : [];
    }
}

if (!function_exists('sessionUserId')) {
    /**
     * Retourne l'id de l'utilisateur connecté (int).
     * Retourne 0 si absent.
     */
    function sessionUserId(): int
    {
        return (int)(sessionUserArray()['id'] ?? 0);
    }
}

if (!function_exists('sessionUserField')) {
    /**
     * Lit un champ arbitraire de $_SESSION['user'] avec une valeur par défaut.
     *
     * @param string $key     Clé à lire
     * @param string $default Valeur par défaut si absent ou vide
     */
    function sessionUserField(string $key, string $default = ''): string
    {
        $v = sessionUserArray()[$key] ?? null;
        return ($v !== null && $v !== '') ? (string) $v : $default;
    }
}

if (!function_exists('k8sNormalizeNamespace')) {
    /**
     * Normalise un identifiant quelconque en nom d'objet Kubernetes valide.
     *
     * Kubernetes impose RFC1123 pour les noms d'objets :
     *   [a-z0-9]([-a-z0-9]*[a-z0-9])?  -> pas d'underscore, pas de majuscule,
     *   63 caractères au maximum, ni tiret en tête ni tiret en fin.
     *
     * Un UUID d'organisation Keycloak passe déjà tel quel
     * ('610bf504-b94c-45f6-a184-a2cf8a2e5c94'), mais une autre forme d'UID
     * comme 'itm_f0aa10375c97c8e8' serait REFUSÉE par l'API : l'underscore
     * devient un tiret.
     *
     * ⚠️ L'ordre compte : le trim des tirets se fait APRÈS la coupe à 63
     * caractères, sinon la coupe peut laisser un tiret final que l'API refuse.
     *
     * Retourne '' si rien d'exploitable ne subsiste.
     */
    function k8sNormalizeNamespace(string $raw): string
    {
        $ns = strtolower(trim($raw));
        $ns = (string) preg_replace('/[^a-z0-9-]/', '-', $ns); // '_' et le reste -> tiret
        $ns = (string) preg_replace('/-{2,}/', '-', $ns);      // pas de tirets consécutifs
        $ns = substr($ns, 0, 63);                              // limite RFC1123
        return trim($ns, '-');                                 // trim APRÈS la coupe
    }
}

if (!function_exists('sessionUserNsK8s')) {
    /**
     * Retourne le namespace Kubernetes de l'utilisateur connecté : « ns-k8s ».
     *
     * SOURCE : l'UUID de l'organisation Keycloak ($_SESSION['user']['kc_org_id'],
     * celui-là même qui part sous « organization_uid » dans les appels n8n),
     * normalisé RFC1123. L'attribut d'organisation « namespace » n'est PLUS
     * une source : il pouvait diverger du périmètre réel du compte, alors que
     * l'UID d'organisation est unique, stable et déjà le périmètre entreprise
     * partout ailleurs dans le portail.
     *
     * Avant : bloc de 5 coalescences ?? copié-collé dans chaque fichier :
     *   $ns = $_SESSION['user']['k8s_namespace']
     *       ?? $_SESSION['user']['k8sNamespace']
     *       ?? ... ?? $_SESSION['user']['namespace'] ?? '';
     *
     * La valeur est mémorisée en session sous 'ns-k8s' : les sessions ouvertes
     * AVANT cette bascule n'ont pas la clé, et la recalculent ici — au besoin
     * en résolvant l'UID d'organisation une seule fois via l'Admin REST
     * (portailOrganizationUid(), inclusion paresseuse, jamais bloquante).
     *
     * Fichiers concernés : dashboard.php, deployment.php, zdns.php, log.php,
     *                      menu.php, k8s_api.php, services_state_api.php,
     *                      projects_menu_api.php
     */
    function sessionUserNsK8s(): string
    {
        $ns = k8sNamespaceForUser(sessionUserArray());

        if ($ns !== '' && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['ns-k8s'] = $ns; // mémorisé : un seul calcul par session
        }

        return $ns;
    }
}

if (!function_exists('k8sNamespaceForUser')) {
    /**
     * Même dérivation que sessionUserNsK8s(), mais sur un tableau utilisateur
     * fourni — pour les proxys qui manipulent $user explicitement
     * (data/k8s_api.php, data/projects_menu_api.php).
     */
    function k8sNamespaceForUser(array $user): string
    {
        // 1) Déjà présent : chemin nominal, aucun calcul.
        $cached = $user['ns-k8s'] ?? null;
        if (is_string($cached) && trim($cached) !== '') {
            return trim($cached);
        }

        // 2) UID d'organisation déjà connu.
        $ns = k8sNormalizeNamespace((string) ($user['kc_org_id'] ?? ''));
        if ($ns !== '') {
            return $ns;
        }

        // 3) Rattrapage des sessions ouvertes AVANT cette bascule, et des
        //    mappers Keycloak qui n'envoient pas l'« id » : une résolution
        //    Admin REST, mémorisée par portailOrganizationUid(). Jamais
        //    bloquante — en cas d'échec on renvoie '' et l'appelant décide.
        if ($user === []) {
            return '';
        }
        if (!function_exists('portailOrganizationUid')) {
            $file = __DIR__ . '/portail_api_client.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
        if (!function_exists('portailOrganizationUid')) {
            return '';
        }
        try {
            return k8sNormalizeNamespace(portailOrganizationUid($user));
        } catch (Throwable $e) {
            error_log('[ns-k8s] résolution organisation : ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('sessionUserNamespace')) {
    /**
     * @deprecated Alias de compatibilité — utiliser sessionUserNsK8s().
     *             Conservé pour les appels existants ; même valeur.
     */
    function sessionUserNamespace(): string
    {
        return sessionUserNsK8s();
    }
}

if (!function_exists('sessionUserHasNamespace')) {
    /**
     * Retourne true si un namespace k8s est dérivable pour cet utilisateur.
     */
    function sessionUserHasNamespace(): bool
    {
        return sessionUserNsK8s() !== '';
    }
}

if (!function_exists('sessionUserCsrf')) {
    /**
     * Retourne le token CSRF de la session, en le créant s'il n'existe pas.
     * Centralise la logique présente dans deployment.php et network.php.
     */
    function sessionUserCsrf(): string
    {
        if (!isset($_SESSION['csrf'])
            || !is_string($_SESSION['csrf'])
            || $_SESSION['csrf'] === ''
        ) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }
}