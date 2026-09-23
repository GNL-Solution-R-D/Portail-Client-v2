<?php
/**
 * Charge un éventuel fichier .env en local et expose un helper config()
 */

// Ne charge le fichier .env que s'il existe encore (utile en développement local)
function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Essayez de charger .env uniquement pour le développement local ; en production sur Kubernetes,
// le fichier n’existe pas et loadEnv() ne fera rien.
loadEnv(__DIR__ . '/../.env');

/**
 * Récupère une valeur de configuration en privilégiant les variables d’environnement.
 *
 * @param string $key Nom de la variable (.env, Secret Kubernetes, etc.)
 * @param mixed $default Valeur par défaut si la variable est absente
 *
 * @return mixed
 */
function config(string $key, $default = null) {
    // getenv() renvoie false si la variable n’existe pas
    $value = getenv($key);
    if ($value === false) {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    return $value;
}


/**
 * Connexions MySQL optionnelles.
 *
 * L'authentification utilisateur est portée par Keycloak : l'application ne doit
 * donc plus échouer au chargement si la base historique n'est pas configurée ou
 * joignable. Les fonctionnalités qui utilisent encore MySQL doivent tester que
 * $pdo / $pdo_powerdns est bien une instance de PDO avant d'exécuter une requête.
 *
 * Définir DB_REQUIRED=true permet de conserver l'ancien comportement bloquant
 * dans les environnements qui exigent explicitement MySQL.
 */
$pdo = null;
$pdo_powerdns = null;

function configBool(string $key, bool $default = false): bool {
    $value = config($key, $default ? 'true' : 'false');
    if (is_bool($value)) {
        return $value;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function createOptionalPdo(string $databaseName, string $charset = 'utf8'): ?PDO {
    $host = trim((string) config('DB_HOST', ''));
    $port = trim((string) config('DB_PORT', '3306'));
    $username = (string) config('DB_USER', '');
    $password = (string) config('DB_PASSWORD', '');
    $databaseName = trim($databaseName);

    if ($host === '' || $databaseName === '' || $username === '') {
        return null;
    }

    return new PDO("mysql:host=$host;port=$port;dbname=$databaseName;charset=$charset", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

try {
    $pdo = createOptionalPdo((string) config('DB_NAME', ''), 'utf8');
} catch (PDOException $e) {
    error_log('Connexion MySQL principale indisponible (mode optionnel) : ' . $e->getMessage());
    if (configBool('DB_REQUIRED', false)) {
        http_response_code(500);
        echo 'Erreur de connexion à la base de données principale.';
        exit();
    }
    $pdo = null;
}

try {
    $pdo_powerdns = createOptionalPdo((string) config('PAME_POWERDNS_DB', 'oh_ns'), 'latin1');
} catch (PDOException $e) {
    error_log('Connexion PowerDNS indisponible (mode optionnel) : ' . $e->getMessage());
    if (configBool('DB_REQUIRED', false)) {
        http_response_code(500);
        echo 'Erreur de connexion à la base de données PowerDNS.';
        exit();
    }
    $pdo_powerdns = null;
}

/**
 * ── PowerDNS : API REST (data/pdns_api.php) ─────────────────────────────────
 *
 * La zone DNS de la page /zdns passe désormais par l'API REST de NOTRE serveur
 * PowerDNS (namespace Kubernetes « powerdns »), et non plus par n8n ni par une
 * requête SQL directe. Trois variables, à poser dans le Secret du portail :
 *
 *   PDNS_API_URL    URL du Service PowerDNS, joint depuis le namespace du
 *                   portail sans passer par l'Ingress — donc sans TLS, sans
 *                   allowlist IP et sans basic auth ; la clé API est la seule
 *                   barrière, avec la NetworkPolicy du namespace « powerdns ».
 *                   Défaut : http://pwrdns-service.powerdns.svc.cluster.local
 *                   Acceptée avec ou sans /api/v1, avec ou sans / final.
 *   PDNS_API_KEY    clé de l'API PowerDNS. OBLIGATOIRE : sans elle, le proxy
 *                   répond « ok:false » proprement au lieu de planter.
 *   PDNS_SERVER_ID  identifiant serveur de l'API. Défaut : localhost
 *
 * ── Création automatique de zone ────────────────────────────────────────────
 * L'assistant « Ajouter un domaine » (include/menu.php) crée la zone chez nous
 * dès que le client choisit « domaine externe » + « serveurs DNS GNL : oui »
 * (action « zone.create » de data/pdns_api.php). Seuls le SOA et les NS de
 * l'apex sont posés, générés par PowerDNS ; le client ajoute ses
 * enregistrements depuis /zdns. L'action est idempotente : une zone déjà
 * présente n'est jamais écrasée.
 *
 *   PDNS_ZONE_NAMESERVERS  serveurs DNS inscrits dans la zone créée, séparés
 *                          par des virgules, points-virgules ou espaces.
 *                          Défaut : ns1/ns2/ns3.gnl-solution.fr
 *                          (PowerDnsClient::DEFAULT_NAMESERVERS).
 *   PDNS_ZONE_KIND         Native (défaut), Master ou Slave. « Native » =
 *                          réplication par la base entre les serveurs, sans
 *                          AXFR ni NOTIFY.
 *
 * ⚠️ PDNS_ZONE_NAMESERVERS est la source unique de vérité : l'assistant
 *    AFFICHE ces mêmes NS au client pour qu'il les pose chez son registrar.
 *    Ne les redéfinissez pas en dur dans include/menu.php — un écart signifie
 *    que le client pointe son domaine vers des serveurs absents de la zone,
 *    et rien ne le signale.
 *
 * ⚠️ $pdo_powerdns ci-dessus est l'ANCIENNE voie : du SQL écrit directement
 * dans les tables de PowerDNS. Elle reste en place pour le code hérité, mais
 * ne l'utilisez pas pour de nouvelles écritures de zone. Écrire en SQL
 * contourne le serveur : pas d'incrément du numéro de série du SOA, pas de
 * NOTIFY aux secondaires, pas de « rectify » DNSSEC. La zone paraît juste dans
 * la base et reste fausse sur le réseau — une panne que rien ne signale.
 * L'API REST fait ces trois choses pour vous.
 */

/**
 * ── Mollie : page /abonnements ──────────────────────────────────────────────
 *
 * Les abonnements sont lus DIRECTEMENT dans l'API Mollie (plus via n8n), pour
 * le client Mollie (« cst_… ») renseigné dans l'attribut d'ORGANISATION
 * Keycloak « moliecliid » (organisation retenue à la connexion). Sans cet
 * attribut, la page affiche « aucun compte de paiement associé » et Mollie
 * n'est pas appelé. Voir include/mollie_client.php.
 *
 *   MOLLIE_API_KEY   OBLIGATOIRE. Clé API du profil (live_… / test_…) ou jeton
 *                    d'organisation (access_…).
 *   MOLLIE_API_URL   Défaut : https://api.mollie.com/v2
 *   MOLLIE_TESTMODE  1 = testmode=true (jeton access_… uniquement).
 *   MOLLIE_TIMEOUT   Secondes. Défaut : 15.
 *
 * ⚠️ Keycloak › Organizations › <organisation> › Attributes : clé
 *    « moliecliid », valeur « cst_… ». Le compte de service du portail doit
 *    avoir « view-organizations » (déjà requis par /equipes).
 */

/**
 * ── Keycloak : ORGANIZATIONS (page /equipes) ────────────────────────────────
 *
 * La carte « Membres de la structure » de /equipes est alimentée par la
 * fonctionnalité « Organizations » de Keycloak (>= 26), lue via l'Admin REST
 * API — plus par la table « team » de n8n. Voir
 * include/keycloak_organizations.php et l'action « team.list » de
 * data/portail_api.php.
 *
 * SERVICES et FONCTIONS = GROUPES D'ORGANISATION (Keycloak >= 26.6) :
 *   groupe de 1er niveau = service (« R&D », « Global » pour les fonctions
 *   sans service), sous-groupe = fonction (« Directeur » → « Directeur R&D »).
 *   L'attribut de groupe « perm » liste les droits (ex.
 *   « invoices.view,company.edit,teams.assign », « * » = tous) ; un
 *   sous-groupe hérite de ses parents. Catalogue et règles :
 *   include/org_permissions.php. Gérés depuis /equipes (actions
 *   team.group.* et team.member.*). Sur Keycloak < 26.6, l'annuaire reste
 *   affiché et la gestion est désactivée.
 *
 * Aucune variable dédiée : l'Admin REST est appelée avec le client OIDC du
 * portail, déjà configuré pour la connexion —
 *     KEYCLOAK_CLIENT_ID  /  KEYCLOAK_CLIENT_SECRET   (« siteweb »)
 * en grant client_credentials.
 *
 * Réglages optionnels :
 *   KEYCLOAK_ORG_MEMBERS_MAX      nombre maximum de membres remontés.
 *                                 Défaut : 500 (plafond dur : 2000).
 *   KEYCLOAK_ORG_DEBUG=1          journalise chaque appel Admin REST réussi
 *                                 (chemin + nombre d'éléments). Utile pour
 *                                 diagnostiquer un 404 ; à laisser à 0 sinon.
 *
 * ⚠️ À faire une fois côté Keycloak, sur le client KEYCLOAK_CLIENT_ID :
 *        - « Client authentication » = ON (déjà requis par la connexion REST) ;
 *        - « Service accounts roles » = ON (active le client_credentials) ;
 *        - dans les rôles du client « realm-management », affecter au compte
 *          de service :
 *              · view-organizations  (lister les organisations et leurs membres)
 *              · view-users          (lire les comptes membres)
 *              · manage-organizations (créer / modifier les services et
 *                                     fonctions, y rattacher des membres —
 *                                     inclut view-organizations)
 *    Sans ces rôles, l'API Keycloak répond 403 et la page affiche le message
 *    correspondant au lieu de la liste.
 *
 * ⚠️ L'organisation retenue à la connexion (page /organisation quand
 *    l'utilisateur en a plusieurs) est mémorisée en session par
 *    keycloakAttachOrganizationContext() : c'est elle qui est interrogée. Les
 *    sessions ouvertes AVANT cette mise en place retombent sur un appariement
 *    par namespace / SIRET / raison sociale, et demandent une reconnexion si
 *    l'organisation reste ambiguë.
 */
?>
