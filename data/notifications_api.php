<?php

/**
 * data/notifications_api.php
 *
 * Proxy serveur entre le menu « cloche » du header et le webhook n8n des
 * notifications. Le navigateur appelle CE endpoint (jamais n8n directement) :
 *   - le client_id est injecté ICI depuis la session (non falsifiable) ;
 *   - protection CSRF (header X-CSRF-Token) sur les écritures, comme data/domains_api.php ;
 *   - la réponse est toujours normalisée en { ok: true/false, ... }.
 *
 * Actions (GET ?action=…, corps en POST form-urlencoded) :
 *   - list (GET)         : notifications du client → { ok, notifications:[...], unread:N }
 *   - read (POST, CSRF)  : marque lu — id=… OU all=1 → { ok }
 *
 * La création se fait côté serveur via notify() (include/notifications.php),
 * jamais depuis le navigateur.
 */

declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('html_errors', '0');
@ini_set('display_startup_errors', '0');

ob_start();

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if (!$lastError || !in_array($lastError['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        http_response_code(500);
    }
    echo json_encode([
        'ok'     => false,
        'error'  => 'Erreur serveur PHP',
        'detail' => (string)($lastError['message'] ?? 'Erreur fatale'),
    ], JSON_UNESCAPED_SLASHES);
});

// Cookie de session valable sur /pages/* ET /data/*
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

require_once __DIR__ . '/../config_loader.php';
require_once __DIR__ . '/../include/account_sessions.php';
require_once __DIR__ . '/../include/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function send_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Vérifie le jeton CSRF (header X-CSRF-Token vs session). */
function csrf_check(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sess = $_SESSION['csrf'] ?? '';
    if (!is_string($sess) || $sess === '' || !is_string($sent) || !hash_equals($sess, $sent)) {
        send_json(403, ['ok' => false, 'error' => 'Jeton CSRF invalide.']);
    }
}

// ── Authentification ─────────────────────────────────────────────────────────
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    send_json(401, ['ok' => false, 'error' => 'Non authentifié (cookie de session absent ?).']);
}

$clientId = (int)($_SESSION['user']['id'] ?? 0);
if ($clientId <= 0) {
    send_json(401, ['ok' => false, 'error' => 'Identifiant client introuvable dans la session.']);
}

if (accountSessionsIsCurrentSessionRevoked($pdo, $clientId)) {
    accountSessionsDestroyPhpSession();
    send_json(401, ['ok' => false, 'error' => 'Cette session a été déconnectée depuis vos paramètres.']);
}
accountSessionsTouchCurrent($pdo, $clientId);

// ── Routage ───────────────────────────────────────────────────────────────────
$action = (string)($_GET['action'] ?? '');

try {
    switch ($action) {

        // ── Liste des notifications du client (LECTURE → GET) ───────────────
        case 'list': {
            $limit = (int)($_GET['limit'] ?? 20);
            if ($limit < 1)   { $limit = 1; }
            if ($limit > 100) { $limit = 100; }

            $r = notif_list($clientId, $limit);

            if ($r['status'] !== 0 && ($r['status'] < 200 || $r['status'] >= 300)) {
                send_json($r['status'] ?: 502, ['ok' => false, 'error' => 'n8n a renvoyé HTTP ' . $r['status']]);
            }

            send_json(200, [
                'ok'            => true,
                'notifications' => $r['notifications'],
                'unread'        => $r['unread'],
            ]);
        }

        // ── Marquage « lu » (ÉCRITURE → POST + CSRF) ────────────────────────
        case 'read': {
            csrf_check();

            $all = notif_truthy($_POST['all'] ?? '0');
            $id  = trim((string)($_POST['id'] ?? ''));

            if (!$all && $id === '') {
                send_json(400, ['ok' => false, 'error' => 'Préciser id=… ou all=1.']);
            }

            $r = notif_mark_read($clientId, $all ? null : $id);

            if ($r['status'] !== 0 && ($r['status'] < 200 || $r['status'] >= 300)) {
                $detail = is_array($r['json']) ? (string)($r['json']['error'] ?? '') : '';
                send_json($r['status'], [
                    'ok'    => false,
                    'error' => 'n8n a renvoyé HTTP ' . $r['status'] . ($detail !== '' ? ' — ' . $detail : ''),
                ]);
            }

            send_json(200, ['ok' => true]);
        }

        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue.']);
    }
} catch (Throwable $e) {
    send_json(502, ['ok' => false, 'error' => $e->getMessage()]);
}
