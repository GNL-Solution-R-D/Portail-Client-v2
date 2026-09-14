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
 *   websocket  GET   ?product_uid=…            → { ok, token, socket }
 *   power      POST  CSRF  product_uid, signal → { ok }   signal ∈ start|stop|restart|kill
 *   command    POST  CSRF  product_uid, command→ { ok }
 *   diag       GET   ?product_uid=…            → { ok, diag:{…} }  configuration effective
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

$clientId = (int)($_SESSION['user']['id'] ?? 0);
if ($clientId <= 0) {
    ptero_send(401, ['ok' => false, 'error' => 'Identifiant client introuvable dans la session.']);
}

if (accountSessionsIsCurrentSessionRevoked($pdo, $clientId)) {
    accountSessionsDestroyPhpSession();
    ptero_send(401, ['ok' => false, 'error' => 'Cette session a été déconnectée depuis vos paramètres.']);
}
accountSessionsTouchCurrent($pdo, $clientId);

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

// ── Routage ──────────────────────────────────────────────────────────────────
$action = (string)($_REQUEST['action'] ?? 'status');

try {
    $ptero = new PterodactylClient();

    switch ($action) {
        case 'status': {
            $server    = $ptero->getServer($serverId);
            $resources = $ptero->getResources($serverId);

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

            $usage = is_array($resources['resources'] ?? null) ? $resources['resources'] : [];

            ptero_send(200, [
                'ok'     => true,
                'server' => [
                    'name'         => (string)($server['name'] ?? ''),
                    'description'  => (string)($server['description'] ?? ''),
                    'identifier'   => (string)($server['identifier'] ?? ''),
                    'uuid'         => (string)($server['uuid'] ?? ''),
                    'node'         => (string)($server['node'] ?? ''),
                    'address'      => $address,
                    'is_suspended' => (bool)($server['is_suspended'] ?? false),
                    'is_installing' => (bool)($server['is_installing'] ?? false),
                    'limits'       => [
                        // 0 = illimité chez Pterodactyl.
                        'memory' => (int)($server['limits']['memory'] ?? 0),   // Mio
                        'disk'   => (int)($server['limits']['disk'] ?? 0),     // Mio
                        'cpu'    => (int)($server['limits']['cpu'] ?? 0),      // %
                    ],
                ],
                'resources' => [
                    'state'             => (string)($resources['current_state'] ?? 'unknown'),
                    'is_suspended'      => (bool)($resources['is_suspended'] ?? false),
                    'memory_bytes'      => (int)($usage['memory_bytes'] ?? 0),
                    'disk_bytes'        => (int)($usage['disk_bytes'] ?? 0),
                    'cpu_absolute'      => (float)($usage['cpu_absolute'] ?? 0),
                    'network_rx_bytes'  => (int)($usage['network_rx_bytes'] ?? 0),
                    'network_tx_bytes'  => (int)($usage['network_tx_bytes'] ?? 0),
                    'uptime'            => (int)($usage['uptime'] ?? 0),       // ms
                ],
            ]);
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
                    'server_id'      => $serverId,
                    'probe_status'   => $probe['status'],
                    'probe_error'    => $probe['error'],
                    'probe_body'     => $probe['body'],
                ],
            ]);
        }

        default:
            ptero_send(400, ['ok' => false, 'error' => 'Action inconnue : ' . $action]);
    }
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
