<?php

/**
 * data/services_menu_api.php
 *
 * Alimente les dépliants « Mes services » de la barre latérale
 * (include/menu.php) à partir des PRODUITS RÉELLEMENT ACHETÉS par le client.
 *
 * Toute la logique (chaîne n8n, contrôle d'accès, cache) vit dans
 * include/services_catalog.php, partagé avec pages/deployment.php et
 * data/ptero_api.php. Ce fichier ne fait que regrouper par dépliant.
 *
 * Répartition (colonne product.esp_cli_menu_name) :
 *
 *   web    → Services WEB
 *   cloud  → Services Cloud
 *   other  → Services Spécifiques
 *   vm     → Serveurs Virtualisés
 *   bm     → Serveurs Dédiés
 *
 * Réponse :
 *   {
 *     ok: true,
 *     count: 3,
 *     orders: 1,
 *     menus: {
 *       web:   [ { uid, slug, name, product_name, display_name, type, status,
 *                  ref, provider_type, provider_service_slug, href } ],
 *       cloud: [...], other: [...], vm: [...], bm: [...]
 *     },
 *     unmapped: [ "slug_sans_menu" ],
 *     warnings: [ "order.product (GNL-… ) : …" ],
 *     cached: false
 *   }
 *
 * « ?refresh=1 » force la relecture (le cache session dure 120 s).
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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function services_menu_send(int $status, array $payload): void
{
    // ⚠️ Jamais de 5xx : l'Ingress porte le middleware Traefik « custom-errors »,
    //    qui remplace le CORPS de toute réponse 5xx par une page générique — le
    //    message d'erreur n'atteindrait jamais le navigateur. Même convention que
    //    data/portail_api.php : HTTP 200, « ok: false », vrai statut dans « code ».
    if ($status >= 500) {
        $payload['code'] = $status;
        $status = 200;
    }

    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

try {
    $force = isset($_GET['refresh']) && $_GET['refresh'] !== '0' && $_GET['refresh'] !== '';
    $data  = servicesCatalogFetch($clientId, $force);

    $menus = array_fill_keys(servicesCatalogMenus(), []);
    $total = 0;

    foreach ($data['entries'] as $entry) {
        $menu = (string)($entry['menu'] ?? '');
        if (!array_key_exists($menu, $menus)) {
            continue;
        }
        // « menu » est une clé de routage interne : inutile côté navigateur.
        unset($entry['menu']);
        $menus[$menu][] = $entry;
        $total++;
    }

    services_menu_send(200, [
        'ok'       => true,
        'count'    => $total,
        'orders'   => (int)$data['orders'],
        'menus'    => $menus,
        'unmapped' => $data['unmapped'],
        'warnings' => $data['warnings'],
        'cached'   => (bool)$data['cached'],
    ]);
} catch (Throwable $e) {
    error_log('[services_menu] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    services_menu_send(500, ['ok' => false, 'error' => $e->getMessage()]);
}
