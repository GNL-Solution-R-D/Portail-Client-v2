<?php

/**
 * data/ptero_api.php
 *
 * Proxy serveur entre la page de service Pterodactyl et le panel.
 *
 * Le navigateur n'appelle JAMAIS le panel directement : la clé PTERO_API_KEY
 * reste côté serveur, et CHAQUE action revérifie que le product_uid demandé
 * appartient bien au client connecté (include/services_catalog.php → chaîne
 * n8n order.list / order.product, client_id injecté serveur). Un uid qui n'est
 * pas dans la liste du client donne 403, même si l'utilisateur le devine.
 *
 * ── Actions ──────────────────────────────────────────────────────────────────
 *   status     GET   ?product_uid=…            → { ok, server:{…}, resources:{…} }
 *                                              server.location = code court de la
 *                                              Location du nœud (vide si l'API
 *                                              application n'est pas configurée)
 *   resources  GET   ?product_uid=…            → { ok, resources:{…} }  (1 appel panel)
 *   websocket  GET   ?product_uid=…            → { ok, token, socket }
 *   power      POST  CSRF  product_uid, signal → { ok }   signal ∈ start|stop|restart|kill
 *   command    POST  CSRF  product_uid, command→ { ok }
 *   diag       GET   ?product_uid=…            → { ok, diag:{…} }  configuration effective
 *
 * ── Explorateur de fichiers ──────────────────────────────────────────────────
 *   files_list      GET   ?path=…                     → { ok, path, items[] }
 *   file_contents   GET   ?path=…                     → { ok, path, content }
 *   file_download   GET   ?path=…                     → { ok, url }   URL signée
 *   file_upload_url POST  CSRF  path                  → { ok, url, directory }
 *   file_write      POST  CSRF  path, content         → { ok, bytes }
 *   file_rename     POST  CSRF  root, from, to        → { ok }
 *   file_mkdir      POST  CSRF  root, name            → { ok }
 *   file_delete     POST  CSRF  root, files[]         → { ok, deleted[] }
 *
 * Téléchargement et téléversement passent par les URL signées du panel, rendues
 * telles quelles au navigateur : temporaires, limitées à un fichier (ou à un
 * dossier pour l'envoi) et sans la clé du panel. Aucun fichier ne transite par
 * PHP. Un service « suspended » reste lisible mais n'accepte plus aucune
 * écriture.
 *
 * ⚠️ Ce endpoint ne renvoie JAMAIS de code 5xx : l'Ingress porte le middleware
 * Traefik « custom-errors » qui remplace le CORPS de toute réponse 5xx par une
 * page générique, effaçant le message. Convention retenue (identique à
 * data/portail_api.php) : HTTP 200, « ok: false », vrai statut dans « code ».
 *
 * Les écritures exigent l'en-tête X-CSRF-Token (même jeton que le reste du
 * portail, $_SESSION['csrf']).
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
require_once __DIR__ . '/../include/services_catalog.php';
require_once __DIR__ . '/PterodactylClient.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ptero_send(int $status, array $payload): void
{
    // ⚠️ Jamais de 5xx sur ce endpoint.
    //    L'Ingress porte le middleware Traefik « custom-errors », qui remplace le
    //    CORPS de toute réponse 5xx par une page générique
    //    ({"error":true,"code":502,"message":"Bad Gateway"}). Renvoyer 502 ici
    //    effacerait le message d'erreur avant qu'il n'atteigne le navigateur —
    //    le client ne verrait que « Réponse non-JSON (502) ».
    //    Même convention que data/portail_api.php : HTTP 200, « ok: false » comme
    //    signal d'échec, et le vrai statut dans « code ».
    if ($status >= 500) {
        $payload['code'] = $status;
        $status = 200;
    }

    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ptero_require_post(): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        ptero_send(405, ['ok' => false, 'error' => 'Méthode non autorisée.']);
    }
}

function ptero_csrf_check(): void
{
    $sent    = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $session = (string)($_SESSION['csrf'] ?? '');
    if ($session === '' || $sent === '' || !hash_equals($session, $sent)) {
        ptero_send(403, ['ok' => false, 'error' => 'Jeton CSRF invalide.']);
    }
}

// ── Authentification ─────────────────────────────────────────────────────────
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    ptero_send(401, ['ok' => false, 'error' => 'Non authentifié.']);
}

// Droits (fonction Keycloak, include/org_permissions.php) : services.manage.
require_once __DIR__ . '/../include/org_permissions.php';
orgRequireApi('services.manage', 'ptero_send');


// $_SESSION['user']['id'] est l'UID Keycloak (UUID) ; ['account_id'] l'entier
// stable réservé aux tables locales à clé INT. (int) d'un UUID vaut 0 dès qu'il
// commence par une lettre (a-f, soit ~1 compte sur 3) : ce cast rendait TOUTE
// la page Pterodactyl inaccessible à ces comptes, console comprise. Même
// correction que portail_api.php, pdns_api.php, k8s_api.php,
// services_menu_api.php et pages/equipes.php.
//
// $clientId ne sert ensuite qu'à la clé du cache catalogue : portailApiCall()
// réinjecte de toute façon le vrai UID dans chaque payload n8n (client_id).
$clientUid = trim((string)($_SESSION['user']['id'] ?? ''));
$accountId = (int)($_SESSION['user']['account_id'] ?? 0);
if ($accountId <= 0 && ctype_digit($clientUid)) {
    $accountId = (int)$clientUid;  // sessions historiques : id = entier local
}
if ($clientUid === '' && $accountId <= 0) {
    ptero_send(401, ['ok' => false, 'error' => 'Identifiant client introuvable dans la session.']);
}
$clientId = $accountId;

if ($accountId > 0) {
    if (accountSessionsIsCurrentSessionRevoked($pdo, $accountId)) {
        accountSessionsDestroyPhpSession();
        ptero_send(401, ['ok' => false, 'error' => 'Cette session a été déconnectée depuis vos paramètres.']);
    }
    accountSessionsTouchCurrent($pdo, $accountId);
}

// ── Contrôle d'accès au produit ──────────────────────────────────────────────
$productUid = trim((string)($_REQUEST['product_uid'] ?? ''));
if ($productUid === '') {
    ptero_send(400, ['ok' => false, 'error' => 'Paramètre « product_uid » requis.']);
}

$service = servicesCatalogFindByUid($clientId, $productUid);
if ($service === null) {
    // Volontairement identique à « inconnu » : on ne révèle pas l'existence
    // d'un uid appartenant à un autre client.
    ptero_send(403, ['ok' => false, 'error' => "Ce service n'est pas accessible avec ce compte."]);
}

// Suspendu : plus aucune action, pas même une lecture. La page refuse déjà,
// mais elle n'est pas le rempart — c'est ici que passent console, fichiers,
// power et commandes.
if (!servicesCatalogEntryIsUsable($service)) {
    ptero_send(403, [
        'ok'    => false,
        'error' => "Ce service est suspendu : aucune action n'est possible tant que la suspension dure.",
    ]);
}

if (($service['provider_type'] ?? '') !== 'ptero') {
    ptero_send(400, ['ok' => false, 'error' => "Ce service n'est pas hébergé sur Pterodactyl."]);
}

// Donnée de commande incomplète, pas une panne serveur : 409 pour que le
// message soit lisible côté client (et non avalé comme un 5xx).
$serverId = trim((string)($service['provider_service_slug'] ?? ''));
if ($serverId === '') {
    ptero_send(409, ['ok' => false, 'error' => "Ce service n'est pas encore rattaché à un serveur du panel (provider_service_slug vide)."]);
}
if (!PterodactylClient::isValidServerId($serverId)) {
    ptero_send(409, [
        'ok'    => false,
        'error' => 'provider_service_slug « ' . $serverId . ' » : ce n\'est pas un Server ID Pterodactyl '
                 . '(UUID complet, ou identifiant court de 8 caractères hexadécimaux attendu).',
    ]);
}

/**
 * Fiche serveur, mémorisée en session PTERO_SERVER_TTL secondes.
 *
 * Le quota du panel est compté par COMPTE, et le portail n'en utilise qu'un :
 * chaque appel épargné profite à tous les clients. Or nom, limites, nœud et
 * allocations ne changent qu'à la reconfiguration du serveur — inutile de les
 * redemander à chaque rafraîchissement.
 */
const PTERO_SERVER_TTL = 120;

function ptero_server_card(PterodactylClient $ptero, string $serverId): array
{
    $cache = $_SESSION['ptero_server_cache'][$serverId] ?? null;
    if (is_array($cache) && (time() - (int)($cache['at'] ?? 0)) < PTERO_SERVER_TTL && is_array($cache['card'] ?? null)) {
        return $cache['card'];
    }

    $server = $ptero->getServer($serverId);

    // Adresse publique : allocation marquée par défaut.
    $address = '';
    $allocations = $server['relationships']['allocations']['data'] ?? [];
    if (is_array($allocations)) {
        foreach ($allocations as $allocation) {
            $attr = is_array($allocation) ? ($allocation['attributes'] ?? []) : [];
            if (!is_array($attr) || empty($attr['is_default'])) {
                continue;
            }
            $host = trim((string)($attr['ip_alias'] ?? '')) ?: trim((string)($attr['ip'] ?? ''));
            $port = (int)($attr['port'] ?? 0);
            if ($host !== '') {
                $address = $port > 0 ? $host . ':' . $port : $host;
            }
            break;
        }
    }

    // Location du nœud : sert au drapeau affiché à côté de son nom. Vide si
    // l'API application n'est pas configurée — la page s'en passe.
    $node = (string)($server['node'] ?? '');
    $locations = ptero_node_locations();

    $card = [
        'name'          => (string)($server['name'] ?? ''),
        'description'   => (string)($server['description'] ?? ''),
        'identifier'    => (string)($server['identifier'] ?? ''),
        'uuid'          => (string)($server['uuid'] ?? ''),
        'node'          => $node,
        'location'      => (string)($locations[$node] ?? ''),
        'address'       => $address,
        'is_suspended'  => (bool)($server['is_suspended'] ?? false),
        'is_installing' => (bool)($server['is_installing'] ?? false),
        'limits'        => [
            // 0 = illimité chez Pterodactyl.
            'memory' => (int)($server['limits']['memory'] ?? 0),   // Mio
            'disk'   => (int)($server['limits']['disk'] ?? 0),     // Mio
            'cpu'    => (int)($server['limits']['cpu'] ?? 0),      // %
        ],
    ];

    $_SESSION['ptero_server_cache'][$serverId] = ['at' => time(), 'card' => $card];

    return $card;
}

/**
 * Nom du nœud → code court de sa Location, pour tout le panel.
 *
 * L'API client ne donne que le NOM du nœud. La Location n'existe que côté API
 * application, on va donc la chercher là-bas — une fois par heure et par
 * session, car cette table ne bouge qu'à l'ajout d'un nœud.
 *
 * Tout est facultatif : pas de clé « ptla_ », panel qui refuse, réseau en
 * carafe — on renvoie une table vide et la page affiche le nœud sans drapeau.
 * Jamais d'exception qui remonterait : le drapeau ne doit pas pouvoir casser
 * l'affichage de l'état du serveur.
 *
 * @return array<string,string>
 */
const PTERO_LOCATIONS_TTL = 3600;

function ptero_node_locations(): array
{
    $cache = $_SESSION['ptero_locations_cache'] ?? null;
    if (is_array($cache) && is_array($cache['map'] ?? null)
        && (time() - (int)($cache['at'] ?? 0)) < PTERO_LOCATIONS_TTL) {
        return $cache['map'];
    }

    $map = [];
    if (PterodactylClient::hasApplicationKey()) {
        try {
            $map = PterodactylClient::application()->listNodeLocations();
        } catch (Throwable $e) {
            // On mémorise quand même l'échec : sans ce cache négatif, chaque
            // rafraîchissement retenterait un appel voué à échouer, sur un
            // quota partagé par tous les clients du portail.
            error_log('[ptero_api] locations indisponibles : ' . $e->getMessage());
            $map = [];
        }
    }

    $_SESSION['ptero_locations_cache'] = ['at' => time(), 'map' => $map];

    return $map;
}

/** Consommation courante, normalisée comme les événements « stats » du websocket. */
function ptero_resources(PterodactylClient $ptero, string $serverId): array
{
    $resources = $ptero->getResources($serverId);
    $usage = is_array($resources['resources'] ?? null) ? $resources['resources'] : [];

    return [
        'state'            => (string)($resources['current_state'] ?? 'unknown'),
        'is_suspended'     => (bool)($resources['is_suspended'] ?? false),
        'memory_bytes'     => (int)($usage['memory_bytes'] ?? 0),
        'disk_bytes'       => (int)($usage['disk_bytes'] ?? 0),
        'cpu_absolute'     => (float)($usage['cpu_absolute'] ?? 0),
        'network_rx_bytes' => (int)($usage['network_rx_bytes'] ?? 0),
        'network_tx_bytes' => (int)($usage['network_tx_bytes'] ?? 0),
        'uptime'           => (int)($usage['uptime'] ?? 0),       // ms
    ];
}

/**
 * Taille au-delà de laquelle l'éditeur ne s'ouvre pas. Un fichier de plusieurs
 * mégaoctets passerait deux fois par le réseau et bloquerait le navigateur ;
 * le téléchargement reste disponible.
 */
const PTERO_EDIT_MAX_BYTES = 524288;   // 512 Kio

/**
 * Chemin absolu normalisé dans l'arborescence du serveur.
 *
 * Le panel a son propre bac à sable, mais un « .. » n'a de toute façon aucune
 * raison de traverser ce proxy : on résout les segments ici, et ce qui sort est
 * toujours un chemin absolu sans « . » ni « .. ».
 */
function ptero_clean_path(string $path, string $fallback = '/'): string
{
    $p = trim(str_replace('\\', '/', $path));
    if ($p === '') {
        $p = $fallback === '' ? '/' : $fallback;
    }

    $out = [];
    foreach (explode('/', $p) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $segment;
    }

    return '/' . implode('/', $out);
}

/**
 * Un nom d'entrée, relatif à son dossier : ni séparateur, ni « .. », ni
 * caractère de contrôle. Renvoie '' si le nom est inutilisable.
 */
function ptero_clean_name(string $name): string
{
    $n = trim(str_replace('\\', '/', $name));

    if ($n === '' || $n === '.' || $n === '..') {
        return '';
    }
    if (str_contains($n, '/')) {
        return '';
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $n) === 1) {
        return '';
    }

    return $n;
}

/**
 * Ce que l'éditeur sait afficher. Pterodactyl annonce un mimetype, mais il est
 * souvent « application/octet-stream » pour un simple .properties : on retombe
 * alors sur l'extension.
 */
function ptero_is_editable(string $mimetype, string $name, int $size): bool
{
    if ($size > PTERO_EDIT_MAX_BYTES) {
        return false;
    }

    $mime = strtolower(trim($mimetype));
    if ($mime === 'inode/x-empty' || str_starts_with($mime, 'text/')) {
        return true;
    }

    $mimeOk = [
        'application/json', 'application/xml', 'application/x-yaml', 'application/yaml',
        'application/javascript', 'application/x-sh', 'application/toml', 'application/x-httpd-php',
    ];
    if (in_array($mime, $mimeOk, true)) {
        return true;
    }

    $extOk = [
        'txt', 'log', 'md', 'json', 'yml', 'yaml', 'toml', 'ini', 'cfg', 'conf', 'config',
        'properties', 'env', 'sh', 'bash', 'xml', 'html', 'htm', 'css', 'js', 'ts', 'sql',
        'csv', 'lock', 'gitignore', 'dockerignore', 'service', 'list',
    ];
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));

    return $ext !== '' && in_array($ext, $extOk, true);
}

/**
 * Un service suspendu reste consultable, mais plus modifiable : laisser écrire
 * dans les fichiers d'un service impayé n'aurait pas de sens, et le panel finit
 * de toute façon par refuser.
 */
function ptero_require_writable(array $service): void
{
    if ((string)($service['status'] ?? '') === 'suspended') {
        ptero_send(409, ['ok' => false, 'error' => 'Service suspendu : les fichiers sont en lecture seule.']);
    }
}

// ── Routage ──────────────────────────────────────────────────────────────────

$action = (string)($_REQUEST['action'] ?? 'status');

try {
    $ptero = new PterodactylClient();

    switch ($action) {
        case 'status': {
            // 2 appels panel au plus (1 seul si la fiche est encore en cache).
            ptero_send(200, [
                'ok'        => true,
                'server'    => ptero_server_card($ptero, $serverId),
                'resources' => ptero_resources($ptero, $serverId),
            ]);
        }

        case 'resources': {
            // Sondage de repli quand la console websocket ne passe pas :
            // 1 seul appel panel, pas de fiche serveur.
            ptero_send(200, ['ok' => true, 'resources' => ptero_resources($ptero, $serverId)]);
        }

        case 'websocket': {
            // Jeton court (≈10 min) : la console du navigateur se connecte
            // directement au nœud wings, jamais avec la clé du panel.
            $ws = $ptero->getWebsocket($serverId);
            if ($ws['token'] === '' || $ws['socket'] === '') {
                ptero_send(502, ['ok' => false, 'error' => 'Le panel n\'a pas fourni de jeton de console.']);
            }

            ptero_send(200, ['ok' => true, 'token' => $ws['token'], 'socket' => $ws['socket']]);
        }

        case 'power': {
            ptero_require_post();
            ptero_csrf_check();

            $signal = strtolower(trim((string)($_POST['signal'] ?? '')));
            if (!in_array($signal, PterodactylClient::POWER_SIGNALS, true)) {
                ptero_send(400, ['ok' => false, 'error' => 'Signal invalide.']);
            }
            if (!empty($service['status']) && $service['status'] === 'suspended' && $signal !== 'stop') {
                ptero_send(409, ['ok' => false, 'error' => 'Service suspendu : seul l\'arrêt est possible.']);
            }

            $ptero->sendPower($serverId, $signal);
            // is_suspended / is_installing peuvent avoir bougé.
            unset($_SESSION['ptero_server_cache'][$serverId]);
            ptero_send(200, ['ok' => true, 'signal' => $signal]);
        }

        case 'command': {
            ptero_require_post();
            ptero_csrf_check();

            $command = trim((string)($_POST['command'] ?? ''));
            if ($command === '') {
                ptero_send(400, ['ok' => false, 'error' => 'Commande vide.']);
            }
            if (mb_strlen($command) > 1000) {
                ptero_send(400, ['ok' => false, 'error' => 'Commande trop longue.']);
            }

            $ptero->sendCommand($serverId, $command);
            ptero_send(200, ['ok' => true]);
        }

        // ── Explorateur de fichiers ──────────────────────────────────────

        case 'files_list': {
            $dir   = ptero_clean_path((string)($_GET['path'] ?? '/'));
            $items = [];

            foreach ($ptero->listFiles($serverId, $dir) as $entry) {
                $name = (string)($entry['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $isFile   = (bool)($entry['is_file'] ?? true);
                $size     = (int)($entry['size'] ?? 0);
                $mimetype = (string)($entry['mimetype'] ?? '');

                $items[] = [
                    'name'     => $name,
                    'path'     => ptero_clean_path(($dir === '/' ? '' : $dir) . '/' . $name),
                    'type'     => $isFile ? 'file' : 'dir',
                    'symlink'  => (bool)($entry['is_symlink'] ?? false),
                    'size'     => $isFile ? $size : 0,
                    'mode'     => (string)($entry['mode'] ?? ''),
                    'mimetype' => $mimetype,
                    'mtime'    => (string)($entry['modified_at'] ?? ''),
                    // Calculé ici : la page n'a pas à connaître la liste des
                    // types qu'on accepte d'ouvrir dans l'éditeur.
                    'editable' => $isFile && ptero_is_editable($mimetype, $name, $size),
                ];
            }

            ptero_send(200, ['ok' => true, 'path' => $dir, 'items' => $items]);
        }

        case 'file_contents': {
            $file = ptero_clean_path((string)($_GET['path'] ?? ''));
            if ($file === '/') {
                ptero_send(400, ['ok' => false, 'error' => 'Chemin de fichier requis.']);
            }

            $content = $ptero->getFileContents($serverId, $file);

            if (strlen($content) > PTERO_EDIT_MAX_BYTES) {
                ptero_send(409, [
                    'ok'    => false,
                    'error' => 'Fichier trop volumineux pour l\'éditeur ('
                             . round(strlen($content) / 1024) . ' Kio). Téléchargez-le pour le consulter.',
                ]);
            }
            // Un binaire affiché dans un <textarea> reviendrait mutilé à
            // l'enregistrement : mieux vaut refuser que corrompre le fichier.
            if ($content !== '' && (str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8'))) {
                ptero_send(409, [
                    'ok'    => false,
                    'error' => 'Fichier binaire : il ne peut pas être ouvert dans l\'éditeur.',
                ]);
            }

            ptero_send(200, ['ok' => true, 'path' => $file, 'content' => $content]);
        }

        case 'file_download': {
            $file = ptero_clean_path((string)($_GET['path'] ?? ''));
            if ($file === '/') {
                ptero_send(400, ['ok' => false, 'error' => 'Chemin de fichier requis.']);
            }

            $url = $ptero->getDownloadUrl($serverId, $file);
            if ($url === '') {
                ptero_send(502, ['ok' => false, 'error' => 'Le panel n\'a pas fourni d\'URL de téléchargement.']);
            }

            ptero_send(200, ['ok' => true, 'path' => $file, 'url' => $url]);
        }

        case 'file_upload_url': {
            ptero_require_post();
            ptero_csrf_check();
            ptero_require_writable($service);

            $dir = ptero_clean_path((string)($_POST['path'] ?? '/'));
            $url = $ptero->getUploadUrl($serverId);
            if ($url === '') {
                ptero_send(502, ['ok' => false, 'error' => 'Le panel n\'a pas fourni d\'URL de téléversement.']);
            }

            ptero_send(200, ['ok' => true, 'url' => $url, 'directory' => $dir]);
        }

        case 'file_write': {
            ptero_require_post();
            ptero_csrf_check();
            ptero_require_writable($service);

            $file = ptero_clean_path((string)($_POST['path'] ?? ''));
            if ($file === '/') {
                ptero_send(400, ['ok' => false, 'error' => 'Chemin de fichier requis.']);
            }

            $content = (string)($_POST['content'] ?? '');
            if (strlen($content) > PTERO_EDIT_MAX_BYTES) {
                ptero_send(400, ['ok' => false, 'error' => 'Contenu trop volumineux (512 Kio maximum).']);
            }

            $ptero->writeFile($serverId, $file, $content);
            ptero_send(200, ['ok' => true, 'path' => $file, 'bytes' => strlen($content)]);
        }

        case 'file_rename': {
            ptero_require_post();
            ptero_csrf_check();
            ptero_require_writable($service);

            $root = ptero_clean_path((string)($_POST['root'] ?? '/'));
            $from = ptero_clean_name((string)($_POST['from'] ?? ''));
            $to   = ptero_clean_name((string)($_POST['to'] ?? ''));

            if ($from === '' || $to === '') {
                ptero_send(400, ['ok' => false, 'error' => 'Nom d\'origine et nouveau nom requis (sans « / »).']);
            }
            if ($from === $to) {
                ptero_send(200, ['ok' => true, 'unchanged' => true]);
            }

            $ptero->renameFiles($serverId, $root, [['from' => $from, 'to' => $to]]);
            ptero_send(200, ['ok' => true, 'root' => $root, 'from' => $from, 'to' => $to]);
        }

        case 'file_mkdir': {
            ptero_require_post();
            ptero_csrf_check();
            ptero_require_writable($service);

            $root = ptero_clean_path((string)($_POST['root'] ?? '/'));
            $name = ptero_clean_name((string)($_POST['name'] ?? ''));
            if ($name === '') {
                ptero_send(400, ['ok' => false, 'error' => 'Nom de dossier requis (sans « / »).']);
            }

            $ptero->createFolder($serverId, $root, $name);
            ptero_send(200, ['ok' => true, 'root' => $root, 'name' => $name]);
        }

        case 'file_delete': {
            ptero_require_post();
            ptero_csrf_check();
            ptero_require_writable($service);

            $root = ptero_clean_path((string)($_POST['root'] ?? '/'));

            $raw = $_POST['files'] ?? [];
            if (is_string($raw)) {
                $raw = [$raw];
            }

            $files = [];
            foreach ((array)$raw as $one) {
                $clean = ptero_clean_name((string)$one);
                if ($clean !== '') {
                    $files[] = $clean;
                }
            }
            $files = array_values(array_unique($files));

            if ($files === []) {
                ptero_send(400, ['ok' => false, 'error' => 'Aucun élément à supprimer.']);
            }
            if (count($files) > 100) {
                ptero_send(400, ['ok' => false, 'error' => 'Trop d\'éléments d\'un coup (100 maximum).']);
            }

            $ptero->deleteFiles($serverId, $root, $files);
            ptero_send(200, ['ok' => true, 'root' => $root, 'deleted' => $files]);
        }

        case 'diag': {
            // Diagnostic de configuration. Ne renvoie JAMAIS la clé : seulement
            // son type (déduit du préfixe) et sa longueur. Appelé automatiquement
            // par la page quand « status » échoue, pour que la cause soit lisible
            // sans avoir à fouiller les logs du pod.
            $d = $ptero->describe();
            $probe = $ptero->probe('/servers/' . rawurlencode($serverId));

            ptero_send(200, [
                'ok'   => true,
                'diag' => [
                    'base_url'       => $d['base_url'],
                    'key_type'       => $d['key_type'],
                    'key_length'     => $d['key_length'],
                    'key_source'     => $d['key_source'],
                    'server_id'      => $serverId,
                    'app_key'        => PterodactylClient::hasApplicationKey() ? 'présente' : 'absente',
                    'locations'      => count(ptero_node_locations()),
                    'probe_status'   => $probe['status'],
                    'probe_error'    => $probe['error'],
                    'probe_body'     => $probe['body'],
                ],
            ]);
        }

        default:
            ptero_send(400, ['ok' => false, 'error' => 'Action inconnue : ' . $action]);
    }
} catch (PterodactylRateLimitException $e) {
    // 429 : ce n'est ni une panne ni une erreur du client. On renvoie le vrai
    // statut (4xx, donc non réécrit par l'Ingress) et le délai d'attente, pour
    // que la page recule au lieu de réessayer en boucle.
    error_log('[ptero_api] action=' . $action . ' throttle, retry_after=' . $e->retryAfter);
    ptero_send(429, ['ok' => false, 'error' => $e->getMessage(), 'retry_after' => $e->retryAfter]);
} catch (PterodactylException $e) {
    // Configuration absente, panel injoignable, clé refusée… Le message est
    // rédigé pour être montré tel quel au client.
    error_log('[ptero_api] action=' . $action . ' ' . $e->getMessage());
    ptero_send(502, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[ptero_api] action=' . $action . ' ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    ptero_send(500, [
        'ok'    => false,
        'error' => $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
}
