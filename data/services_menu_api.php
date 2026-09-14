<?php

/**
 * data/services_menu_api.php
 *
 * Alimente les dépliants « Mes services » de la barre latérale
 * (include/menu.php) à partir des PRODUITS RÉELLEMENT ACHETÉS par le client.
 *
 * Chaîne d'appels n8n (webhook unique « data-portail », via portailApiCall) :
 *
 *   1) order.list      → toutes les commandes du client courant
 *                        on n'en garde que la référence (« ref »).
 *   2) order.product   → pour CHAQUE ref, les lignes de la commande
 *                        (slug, uid, quantite, status…).
 *                        Seules les lignes dont « status » vaut
 *                        active ou suspended sont retenues.
 *   3) product.list    → catalogue produits (table product)
 *                        donne, pour chaque slug : « name » (libellé affiché)
 *                        et « esp_cli_menu_name » (dépliant de destination).
 *   4) deployment.list → renommages du client (table label_portail V2)
 *                        product_uid (= order_product.uid) → display_name.
 *                        Un service renommé par le client s'affiche sous son
 *                        nom personnalisé ; le nom catalogue reste dans
 *                        « product_name ».
 *
 * Répartition dans la barre latérale (colonne product.esp_cli_menu_name) :
 *
 *   web    → Services WEB
 *   cloud  → Services Cloud
 *   other  → Services Spécifiques
 *   vm     → Serveurs Virtualisés
 *   bm     → Serveurs Dédiés
 *
 * Un esp_cli_menu_name vide ou inconnu n'est PAS affiché (il est compté dans
 * « unmapped » pour faciliter le diagnostic côté catalogue).
 *
 * UNE ENTRÉE = UNE LIGNE DE COMMANDE (order_product.uid). Deux exemplaires du
 * même produit donnent deux entrées, chacune avec son propre statut : c'est ce
 * qui permet d'afficher « active » sur l'une et « suspended » sur l'autre.
 *
 * LIEN VERS LA PAGE DE DÉPLOIEMENT — une entrée est cliquable si, et seulement
 * si, product.provider_type = « kube » ET order_product.provider_service_slug
 * est renseigné. Le champ « href » vaut alors
 *   https://espace-client.gnl-solution.fr/deployment?deployment=<provider_service_slug>
 * (base surchargeable par PORTAIL_DEPLOYMENT_URL). Sinon « href » est vide et
 * l'entrée reste un simple libellé.
 *
 * Réponse :
 *   {
 *     ok: true,
 *     count: 3,
 *     menus: {
 *       web:   [ { uid, slug, name, product_name, display_name, type, status,
 *                  ref, provider_type, provider_service_slug, href } ],
 *       cloud: [...], other: [...], vm: [...], bm: [...]
 *     },
 *     orders: 1,
 *     unmapped: [ "slug_sans_menu" ],
 *     warnings: [ "order.product (GNL-… ) : …" ],
 *     cached: false
 *   }
 *
 * Le menu étant présent sur TOUTES les pages, le résultat est mémorisé en
 * session pendant SERVICES_MENU_TTL secondes : sans cela chaque navigation
 * déclencherait 1 + N + 1 appels n8n. « ?refresh=1 » force le rafraîchissement.
 */

declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

require_once __DIR__ . '/../config_loader.php';
require_once __DIR__ . '/../include/account_sessions.php';
require_once __DIR__ . '/../include/portail_api_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Durée de vie du cache session (secondes). */
const SERVICES_MENU_TTL = 120;

/** Nombre maximum de commandes interrogées (garde-fou anti-avalanche n8n). */
const SERVICES_MENU_MAX_ORDERS = 50;

/** Colonne product.esp_cli_menu_name → clé de dépliant. */
const SERVICES_MENU_KEYS = ['web', 'cloud', 'other', 'vm', 'bm'];

/** Valeurs de order_product.status qui rendent un produit « visible ». */
const SERVICES_MENU_STATUS = ['active', 'suspended'];

/**
 * Base des liens « page du déploiement ».
 *
 * Un service n'est cliquable que si product.provider_type vaut « kube » ET que
 * la ligne order_product porte un provider_service_slug : le lien pointe alors
 * vers <base>?deployment=<provider_service_slug>.
 *
 * Surchargeable par la variable d'environnement PORTAIL_DEPLOYMENT_URL pour les
 * instances de test (préprod, local).
 */
const SERVICES_MENU_DEPLOYMENT_URL = 'https://espace-client.gnl-solution.fr/deployment';

/** provider_type (catalogue) qui donne accès à la page de déploiement. */
const SERVICES_MENU_LINKABLE_PROVIDER = 'kube';

function services_menu_send(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Extrait une liste de lignes d'une réponse n8n, quel que soit son emballage
 * (tableau brut, { data: [...] }, { json: {...} }, objet unique…).
 * Même logique que extract_rows() de data/portail_api.php.
 */
function services_menu_rows($json, array $containerKeys, array $idKeys): array
{
    $unwrap = static function ($v) {
        return (is_array($v) && isset($v['json']) && is_array($v['json'])) ? $v['json'] : $v;
    };

    $containerKeys = array_merge($containerKeys, ['data', 'results', 'rows', 'items']);

    if (is_array($json)) {
        foreach ($containerKeys as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $json = $json[$key];
                break;
            }
        }
        if ($json === [] || array_key_exists(0, $json)) {
            return array_map($unwrap, array_values($json));
        }
        if (isset($json['json']) && is_array($json['json'])) {
            return [$json['json']];
        }
        foreach ($idKeys as $k) {
            if (isset($json[$k])) {
                return [$json];
            }
        }
    }

    return [];
}

/** Première valeur non vide parmi plusieurs clés candidates. */
function services_menu_value(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $k) {
        if (!array_key_exists($k, $row)) {
            continue;
        }
        $v = $row[$k];
        if (is_string($v) || is_numeric($v)) {
            $v = trim((string)$v);
            if ($v !== '') {
                return $v;
            }
        }
    }

    return $default;
}

/**
 * Un appel n8n en « échec doux » : la barre latérale doit s'afficher même si
 * l'une des requêtes tombe, plutôt que de faire échouer tout le menu.
 */
function services_menu_call(array $payload, string $label, array $containerKeys, array $idKeys, array &$warnings): array
{
    try {
        $resp = portailApiCall($payload);
    } catch (Throwable $e) {
        $warnings[] = $label . ' : ' . $e->getMessage();
        return [];
    }

    $status = (int)($resp['status'] ?? 0);
    if ($status !== 0 && ($status < 200 || $status >= 300)) {
        $warnings[] = $label . ' : HTTP ' . $status;
        return [];
    }

    $rows = services_menu_rows($resp['json'] ?? null, $containerKeys, $idKeys);

    // Zéro ligne peut être légitime (aucune commande) ou signaler un contrat
    // rompu côté n8n. On ne prévient que dans le second cas.
    if (!$rows) {
        $body = $resp['json'] ?? null;
        $legitEmpty = ($body === null) || (is_array($body) && $body === []);
        if (!$legitEmpty) {
            $dump = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($dump)) {
                $dump = (string)($resp['raw'] ?? '');
            }
            $warnings[] = $label . ' : réponse inattendue de n8n — ' . mb_substr($dump, 0, 200);
        }
    }

    return $rows;
}

// ── Authentification (identique aux autres endpoints data/) ───────────────────
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    services_menu_send(401, ['ok' => false, 'error' => 'Non authentifié.']);
}

$clientId = (int)($_SESSION['user']['id'] ?? 0);
if ($clientId <= 0) {
    services_menu_send(401, ['ok' => false, 'error' => 'Identifiant client introuvable dans la session.']);
}

if (accountSessionsIsCurrentSessionRevoked($pdo, $clientId)) {
    accountSessionsDestroyPhpSession();
    services_menu_send(401, ['ok' => false, 'error' => 'Cette session a été déconnectée depuis vos paramètres.']);
}
accountSessionsTouchCurrent($pdo, $clientId);

// ── Cache session ─────────────────────────────────────────────────────────────
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] !== '0' && $_GET['refresh'] !== '';
$cache = $_SESSION['services_menu_cache'] ?? null;
if (
    !$forceRefresh
    && is_array($cache)
    && (int)($cache['client_id'] ?? 0) === $clientId
    && (time() - (int)($cache['at'] ?? 0)) < SERVICES_MENU_TTL
    && is_array($cache['payload'] ?? null)
) {
    services_menu_send(200, array_merge($cache['payload'], ['cached' => true]));
}

try {
    $warnings = [];

    // ── 1) order.list → références de commande ────────────────────────────────
    $orderRows = services_menu_call(
        ['action' => 'order.list', 'client_id' => $clientId],
        'order.list',
        ['orders', 'commandes'],
        ['id', 'ref', 'reference'],
        $warnings
    );

    $refs = [];
    foreach ($orderRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ref = services_menu_value($row, ['ref', 'reference', 'number', 'order_number']);
        if ($ref !== '' && !in_array($ref, $refs, true)) {
            $refs[] = $ref;
        }
    }
    if (count($refs) > SERVICES_MENU_MAX_ORDERS) {
        $warnings[] = 'order.list : ' . count($refs) . ' commandes — limité aux '
            . SERVICES_MENU_MAX_ORDERS . ' premières.';
        $refs = array_slice($refs, 0, SERVICES_MENU_MAX_ORDERS);
    }

    // ── 2) order.product → lignes actives / suspendues ────────────────────────
    $lines = [];   // [ ['slug'=>…, 'uid'=>…, 'ref'=>…, 'status'=>…, 'qty'=>int], … ]
    foreach ($refs as $ref) {
        $productRows = services_menu_call(
            ['action' => 'order.product', 'client_id' => $clientId, 'id' => '', 'ref' => $ref],
            'order.product (' . $ref . ')',
            ['order_product', 'products', 'produits', 'lignes', 'lines'],
            ['uid', 'slug'],
            $warnings
        );

        foreach ($productRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // n8n peut renvoyer les lignes de TOUTES les commandes : on ne garde
            // que celles de la référence demandée quand le champ est présent.
            $rowRef = services_menu_value($row, ['ref', 'reference', 'order_ref']);
            if ($rowRef !== '' && strcasecmp($rowRef, $ref) !== 0) {
                continue;
            }

            $status = strtolower(services_menu_value($row, ['status', 'statut', 'state']));
            if (!in_array($status, SERVICES_MENU_STATUS, true)) {
                continue;
            }

            $slug = services_menu_value($row, ['slug', 'produit', 'product', 'code']);
            if ($slug === '') {
                continue;
            }

            $lines[] = [
                'slug'   => $slug,
                'uid'    => services_menu_value($row, ['uid', 'product_uid', 'item_uid']),
                'ref'    => $rowRef !== '' ? $rowRef : $ref,
                'status' => $status,
                // Identifiant du déploiement chez le fournisseur (colonne
                // order_product.provider_service_slug) : c'est lui qui rend le
                // service cliquable quand le produit est de type « kube ».
                'provider_service_slug' => services_menu_value($row, [
                    'provider_service_slug', 'service_slug', 'provider_slug',
                ]),
            ];
        }
    }

    // ── 3) product.list → catalogue (slug → nom + dépliant) ───────────────────
    $catalog = [];
    if ($lines !== []) {
        $catalogRows = services_menu_call(
            ['action' => 'product.list', 'client_id' => $clientId],
            'product.list',
            ['products', 'produits', 'product', 'catalogue', 'catalog'],
            ['slug', 'id'],
            $warnings
        );

        foreach ($catalogRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = services_menu_value($row, ['slug', 'code', 'product_slug']);
            if ($slug === '') {
                continue;
            }
            $catalog[$slug] = [
                'name'          => services_menu_value($row, ['name', 'nom', 'label', 'libelle', 'titre'], $slug),
                'menu'          => strtolower(services_menu_value($row, ['esp_cli_menu_name', 'menu', 'menu_name'])),
                'type'          => services_menu_value($row, ['type']),
                'provider_type' => strtolower(services_menu_value($row, ['provider_type'])),
            ];
        }

        if ($catalog === []) {
            $warnings[] = 'product.list : catalogue vide — aucun produit ne peut être rattaché à un menu.';
        }
    }

    // ── 3bis) deployment.list → renommages clients (table label_portail V2) ───
    //  Le client peut renommer un service (clic droit dans la barre latérale) :
    //  label_portail associe un order_product.uid à un display_name. La table est
    //  lue via l'action n8n « deployment.list », déjà utilisée pour ce besoin.
    $renames = [];
    if ($lines !== []) {
        $renameRows = services_menu_call(
            ['action' => 'deployment.list', 'client_id' => $clientId],
            'deployment.list',
            ['deployments', 'labels', 'label_portail'],
            ['product_uid', 'uid', 'deployment_name', 'name'],
            $warnings
        );

        foreach ($renameRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid  = services_menu_value($row, ['product_uid', 'uid', 'deployment_name', 'name']);
            $disp = services_menu_value($row, ['display_name', 'label']);
            if ($uid !== '' && $disp !== '') {
                $renames[$uid] = $disp;
            }
        }
    }

    // ── 4) Regroupement par dépliant ──────────────────────────────────────────
    //  UNE entrée par ligne de commande (order_product.uid) : deux exemplaires
    //  du même produit = deux entrées distinctes, chacune avec SON statut.
    //  Les uid vus sont mémorisés pour ne pas dupliquer une ligne si n8n
    //  renvoyait deux fois la même.
    $menus = array_fill_keys(SERVICES_MENU_KEYS, []);
    $unmapped = [];
    $seenUids = [];
    $total = 0;

    $deploymentUrl = trim((string)getenv('PORTAIL_DEPLOYMENT_URL'));
    if ($deploymentUrl === '') {
        $deploymentUrl = SERVICES_MENU_DEPLOYMENT_URL;
    }
    $deploymentUrl = rtrim($deploymentUrl, '?&');

    foreach ($lines as $line) {
        $slug = $line['slug'];
        $meta = $catalog[$slug] ?? null;
        $menu = $meta['menu'] ?? '';

        if (!in_array($menu, SERVICES_MENU_KEYS, true)) {
            if (!in_array($slug, $unmapped, true)) {
                $unmapped[] = $slug;
            }
            continue;
        }

        $uid = $line['uid'];
        if ($uid !== '') {
            if (isset($seenUids[$uid])) {
                continue;
            }
            $seenUids[$uid] = true;
        }

        $productName = ($meta['name'] ?? '') !== '' ? $meta['name'] : $slug;
        $displayName = ($uid !== '' && isset($renames[$uid])) ? $renames[$uid] : '';

        // Cliquable uniquement si le produit est hébergé sur Kubernetes ET que
        // la ligne de commande porte le slug du déploiement.
        $providerType = (string)($meta['provider_type'] ?? '');
        $serviceSlug  = $line['provider_service_slug'];
        $href         = '';
        if ($providerType === SERVICES_MENU_LINKABLE_PROVIDER && $serviceSlug !== '') {
            $href = $deploymentUrl . '?deployment=' . rawurlencode($serviceSlug);
        }

        $menus[$menu][] = [
            'uid'          => $uid,
            'slug'         => $slug,
            // « name » = ce qu'il faut afficher ; « product_name » = le libellé
            // catalogue d'origine, conservé pour l'infobulle et le modal.
            'name'         => $displayName !== '' ? $displayName : $productName,
            'product_name' => $productName,
            'display_name' => $displayName,
            'type'         => $meta['type'] ?? '',
            'status'       => $line['status'],
            'ref'          => $line['ref'],
            'provider_type'         => $providerType,
            'provider_service_slug' => $serviceSlug,
            'href'                  => $href,   // '' ⇒ entrée non cliquable
        ];

        $total++;
    }

    foreach ($menus as $key => $entries) {
        // Tri par libellé affiché, puis par uid pour que deux exemplaires du même
        // produit gardent un ordre stable d'un chargement à l'autre.
        usort($entries, static function (array $a, array $b): int {
            $byName = strcasecmp((string)$a['name'], (string)$b['name']);
            return $byName !== 0 ? $byName : strcmp((string)$a['uid'], (string)$b['uid']);
        });
        $menus[$key] = array_values($entries);
    }

    $payload = [
        'ok'       => true,
        'count'    => $total,
        'orders'   => count($refs),
        'menus'    => $menus,
        'unmapped' => $unmapped,
        'warnings' => array_values($warnings),
    ];

    $_SESSION['services_menu_cache'] = [
        'client_id' => $clientId,
        'at'        => time(),
        'payload'   => $payload,
    ];

    services_menu_send(200, array_merge($payload, ['cached' => false]));
} catch (Throwable $e) {
    error_log('[services_menu] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    services_menu_send(500, ['ok' => false, 'error' => $e->getMessage()]);
}
