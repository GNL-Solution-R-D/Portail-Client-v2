<?php

/**
 * data/factures_api.php
 *
 * Proxy serveur entre le portail (page « Mes factures ») et le webhook n8n
 * qui pilote les factures du client.
 *
 * Même architecture que data/domains_api.php et data/abonnements_api.php :
 *   - le navigateur appelle CE endpoint (jamais n8n directement) ;
 *   - le client_id est injecté ICI depuis la session (non falsifiable) ;
 *   - protection CSRF identique (header X-CSRF-Token) pour les écritures ;
 *   - la réponse est toujours normalisée en { ok: true/false, ... }.
 *
 * Actions (GET ?action=…) :
 *   - list   : liste les factures du client → { ok, count, invoices: [...] }
 *   - detail : détail d'une facture (?id= ou ?ref=) → { ok, invoices: [...] }
 *
 * Le téléchargement du PDF passe par data/n8n_invoice_download.php (proxy
 * authentifié) ; chaque facture expose `download_url` quand un PDF existe.
 *
 * Chaque facture est renvoyée « prête à l'affichage » :
 *   { id, ref, date, date_ts, due, due_ts, status, status_label, status_class,
 *     total_ht, total_ht_raw, total_ttc, total_ttc_raw,
 *     remaining, remaining_raw, has_pdf, download_url }
 *
 * ── Schéma attendu côté n8n (tolérant : plusieurs noms de champ acceptés) ─────
 *   id|rowid|invoice_id, ref|reference|number, date|datef|date_valid|invoice_date,
 *   due|date_lim_reglement|date_echeance|due_date,
 *   status|statut|fk_statut|state|paye,
 *   total_ht|amount_ht, total_ttc|amount_ttc|amount|total,
 *   remaining|remaintopay|resteapayer|remaining_to_pay,
 *   pdf|pdf_url|download_url|last_main_doc|main_doc|doc|url (présence ⇒ has_pdf).
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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function send_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Variable d'environnement uniquement si définie ET non vide après trim. */
function getenv_non_empty(string $name): ?string
{
    $v = getenv($name);
    if ($v === false) {
        return null;
    }
    $v = trim((string)$v);
    return $v === '' ? null : $v;
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

// ── Configuration du webhook n8n ─────────────────────────────────────────────
$N8N_URL      = getenv_non_empty('N8N_DATA_INVOICE_URL') ?? 'https://api.gnl-solution.fr/webhook/data-invoice';
$N8N_LIST_URL = getenv_non_empty('N8N_DATA_INVOICE_LIST_URL') ?? 'https://api.gnl-solution.fr/webhook/data-invoice';
$N8N_TOKEN    = getenv_non_empty('N8N_WEBHOOK_TOKEN');

/**
 * Relaie un payload au webhook n8n et renvoie la réponse décodée.
 * En GET, le payload part en query string ; sinon en JSON.
 *
 * @return array{status:int, json:mixed, raw:string}
 */
function n8n_call(string $url, array $payload, ?string $token, string $method = 'POST'): array
{
    $method  = strtoupper($method);
    $isGet   = ($method === 'GET');
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
        $headers[] = 'X-GNL-Token: ' . $token;
    }

    $body = null;
    if ($isGet) {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        $url .= $sep . http_build_query($payload);
    } else {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    if (function_exists('curl_init')) {
        $opts = [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
        ];
        if ($isGet) {
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            $opts[CURLOPT_POSTFIELDS]    = $body;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            throw new RuntimeException('Connexion n8n impossible : ' . $err);
        }
        $raw = (string)$raw;
    } else {
        $httpOpts = [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'timeout'       => 12,
            'ignore_errors' => true,
        ];
        if (!$isGet) {
            $httpOpts['content'] = $body;
        }
        $ctx = stream_context_create(['http' => $httpOpts]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('Connexion n8n impossible.');
        }
        $status = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
        $raw = (string)$raw;
    }

    $json = json_decode($raw, true);
    return ['status' => $status, 'json' => $json, 'raw' => $raw];
}

/** Extrait la liste de lignes depuis une réponse n8n tolérante au format. */
function extract_rows($json): array
{
    $unwrap = static function ($v) {
        return (is_array($v) && isset($v['json']) && is_array($v['json'])) ? $v['json'] : $v;
    };

    if (is_array($json)) {
        foreach (['invoices', 'factures', 'data', 'results', 'rows', 'items'] as $key) {
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
        if (isset($json['id']) || isset($json['ref']) || isset($json['reference'])) {
            return [$json];
        }
    }
    return [];
}

// ── Helpers d'affichage ───────────────────────────────────────────────────────

/** Première valeur non vide parmi plusieurs clés candidates. */
function pick(array $row, array $keys, $default = null)
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return $default;
}

/** Date hétérogène (timestamp unix ou chaîne ISO) → timestamp. */
function to_timestamp($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    $ts = strtotime((string)$value);
    return $ts === false ? null : $ts;
}

function date_display(?int $ts): string
{
    return ($ts === null || $ts <= 0) ? '—' : date('d/m/Y', $ts);
}

function amount_display($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    return number_format((float)$value, 2, ',', ' ') . ' €';
}

function status_label($status): string
{
    $n = strtolower(trim((string)$status));
    $map = [
        '0' => 'Brouillon', '1' => 'Validée', '2' => 'Payée', '3' => 'Abandonnée', '4' => 'Classée',
        'draft' => 'Brouillon', 'validated' => 'Validée', 'paid' => 'Payée',
        'abandoned' => 'Abandonnée', 'closed' => 'Classée',
        'cancelled' => 'Annulée', 'canceled' => 'Annulée', 'unpaid' => 'Impayée',
    ];
    return $map[$n] ?? ($n !== '' ? ucfirst($n) : 'Inconnu');
}

function status_class($status): string
{
    $n = strtolower(trim((string)$status));
    if (in_array($n, ['2', 'paid', '4', 'closed'], true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }
    if (in_array($n, ['3', 'abandoned', 'cancelled', 'canceled'], true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    if (in_array($n, ['1', 'validated'], true)) {
        return 'bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300';
    }
    return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
}

/** Transforme une ligne n8n brute en objet facture normalisé. */
function normalize_invoice(array $row): array
{
    $id  = pick($row, ['id', 'rowid', 'invoice_id'], 0);
    $id  = is_numeric($id) ? (int)$id : 0;
    $ref = (string)pick($row, ['ref', 'reference', 'number', 'invoice_number'], 'FAC-' . $id);

    $dateTs = to_timestamp(pick($row, ['date', 'datef', 'date_valid', 'date_creation', 'invoice_date', 'issued_at']));
    $dueTs  = to_timestamp(pick($row, ['due', 'date_lim_reglement', 'date_echeance', 'date_due', 'due_date']));

    $totalHt  = pick($row, ['total_ht', 'amount_ht', 'ht']);
    $totalTtc = pick($row, ['total_ttc', 'amount_ttc', 'ttc', 'amount', 'total']);
    $remaining = pick($row, ['remaining', 'remaintopay', 'resteapayer', 'remaining_to_pay', 'reste']);

    $statusRaw = (string)pick($row, ['status', 'statut', 'fk_statut', 'state', 'paye'], '');

    // PDF : présence d'un chemin/URL ⇒ téléchargement disponible (via proxy authentifié).
    $hasPdf = pick($row, ['pdf', 'pdf_url', 'download_url', 'last_main_doc', 'main_doc', 'doc', 'url']) !== null;
    $downloadUrl = $hasPdf
        ? '/data/n8n_invoice_download.php?id=' . rawurlencode((string)$id) . '&ref=' . rawurlencode($ref)
        : null;

    return [
        'id'             => $id,
        'ref'            => $ref,
        'date'           => date_display($dateTs),
        'date_ts'        => $dateTs,
        'due'            => date_display($dueTs),
        'due_ts'         => $dueTs,
        'status'         => $statusRaw,
        'status_label'   => status_label($statusRaw),
        'status_class'   => status_class($statusRaw),
        'total_ht'       => amount_display($totalHt),
        'total_ht_raw'   => is_numeric($totalHt) ? (float)$totalHt : null,
        'total_ttc'      => amount_display($totalTtc),
        'total_ttc_raw'  => is_numeric($totalTtc) ? (float)$totalTtc : null,
        'remaining'      => amount_display($remaining),
        'remaining_raw'  => is_numeric($remaining) ? (float)$remaining : null,
        'has_pdf'        => $hasPdf,
        'download_url'   => $downloadUrl,
    ];
}

// ── Routage ───────────────────────────────────────────────────────────────────
$action = (string)($_GET['action'] ?? '');

try {
    switch ($action) {

        // ── Liste des factures du client (LECTURE → GET) ────────────────────
        case 'list': {
            $resp = n8n_call($N8N_LIST_URL, [
                'action'    => 'list',
                'client_id' => $clientId,
            ], $N8N_TOKEN, 'GET');

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'n8n a renvoyé HTTP ' . $resp['status']]);
            }

            $rows = extract_rows($resp['json']);
            $invoices = array_map('normalize_invoice', $rows);

            send_json(200, [
                'ok'       => true,
                'count'    => count($invoices),
                'invoices' => $invoices,
            ]);
        }

        // ── Détail d'une facture (LECTURE → GET) ────────────────────────────
        case 'detail': {
            $id  = trim((string)($_GET['id'] ?? ''));
            $ref = trim((string)($_GET['ref'] ?? ''));
            if ($id === '' && $ref === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » ou « ref » requis.']);
            }

            $resp = n8n_call($N8N_LIST_URL, [
                'action'    => 'detail',
                'client_id' => $clientId,
                'id'        => $id,
                'ref'       => $ref,
            ], $N8N_TOKEN, 'GET');

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'n8n a renvoyé HTTP ' . $resp['status']]);
            }

            $rows = extract_rows($resp['json']);

            if (($id !== '' || $ref !== '') && count($rows) > 1) {
                $rows = array_values(array_filter($rows, static function ($r) use ($id, $ref): bool {
                    if (!is_array($r)) {
                        return false;
                    }
                    $rId  = (string)($r['id'] ?? $r['rowid'] ?? '');
                    $rRef = (string)($r['ref'] ?? $r['reference'] ?? '');
                    return ($id !== '' && $rId === $id) || ($ref !== '' && $rRef === $ref);
                }));
            }

            if (empty($rows)) {
                send_json(404, ['ok' => false, 'error' => 'Facture introuvable.']);
            }

            $invoices = array_map('normalize_invoice', $rows);

            send_json(200, [
                'ok'       => true,
                'count'    => count($invoices),
                'invoices' => $invoices,
            ]);
        }

        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue.']);
    }
} catch (Throwable $e) {
    send_json(502, ['ok' => false, 'error' => $e->getMessage()]);
}
