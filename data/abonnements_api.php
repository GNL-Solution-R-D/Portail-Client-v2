<?php

/**
 * data/abonnements_api.php
 *
 * Proxy serveur entre le portail (page « Mes abonnements ») et le webhook n8n
 * qui pilote les abonnements du client.
 *
 * Même architecture que data/domains_api.php :
 *   - le navigateur appelle CE endpoint (jamais n8n directement) ;
 *   - le client_id est injecté ICI depuis la session (non falsifiable) ;
 *   - protection CSRF identique (header X-CSRF-Token) pour les écritures ;
 *   - la réponse est toujours normalisée en { ok: true/false, ... }.
 *
 * Actions (GET ?action=…, corps en POST form-urlencoded pour les écritures) :
 *   - list   : liste les abonnements du client → { ok, count, subscriptions: [...] }
 *   - detail : détail d'un abonnement (?id= ou ?ref=) → { ok, subscriptions: [...] }
 *
 * Chaque abonnement est renvoyé « prêt à l'affichage » :
 *   { id, ref, label, start, start_ts, end, end_ts, frequency,
 *     amount, amount_raw, status, status_label, status_class }
 *
 * ── Schéma attendu côté n8n (tolérant : plusieurs noms de champ acceptés) ─────
 *   id|rowid|contract_id, ref|reference, label|product_label|name|description,
 *   date_start|date_contrat|date_ouverture|start, date_end|date_fin_validite|
 *   next_payment|end, amount|price|subprice|total_ht|total_ttc,
 *   status|statut|state, frequency|periodicity (facultatif).
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
//  Écriture (futures actions) → webhook de PRODUCTION.
$N8N_URL      = getenv_non_empty('N8N_DATA_SUBSCRIPTION_URL') ?? 'https://api.gnl-solution.fr/webhook/data-subscription';
//  Lecture (list, alimente la page). Surchargeable (ex. /webhook-test/ pour debug).
$N8N_LIST_URL = getenv_non_empty('N8N_DATA_SUBSCRIPTION_LIST_URL') ?? 'https://api.gnl-solution.fr/webhook/data-subscription';
//  Jeton d'authentification optionnel envoyé à n8n (Header Auth du webhook).
$N8N_TOKEN    = getenv_non_empty('N8N_WEBHOOK_TOKEN');

/**
 * Relaie un payload au webhook n8n et renvoie la réponse décodée.
 * En GET, le payload part en query string (pas de corps) ; sinon en JSON.
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
    // Déballe le format d'item n8n { "json": {...} } → {...}
    $unwrap = static function ($v) {
        return (is_array($v) && isset($v['json']) && is_array($v['json'])) ? $v['json'] : $v;
    };

    if (is_array($json)) {
        // Conteneurs usuels.
        foreach (['subscriptions', 'abonnements', 'contracts', 'data', 'results', 'rows', 'items'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $json = $json[$key];
                break;
            }
        }
        // Tableau brut de lignes (clé numérique 0 présente, ou tableau vide).
        if ($json === [] || array_key_exists(0, $json)) {
            return array_map($unwrap, array_values($json));
        }
        // Item n8n unique { "json": {...} }.
        if (isset($json['json']) && is_array($json['json'])) {
            return [$json['json']];
        }
        // Objet unique ressemblant à une ligne.
        if (isset($json['id']) || isset($json['ref']) || isset($json['reference'])) {
            return [$json];
        }
    }
    return [];
}

/** true/false depuis une valeur n8n hétérogène (bool, 0/1, "true"). */
function truthy($v): bool
{
    if (is_bool($v)) {
        return $v;
    }
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'oui', 'on'], true);
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

function frequency_display(?int $start, ?int $end): string
{
    if ($start === null || $end === null || $end <= $start) {
        return '—';
    }
    $diff = (new DateTimeImmutable('@' . $start))->diff(new DateTimeImmutable('@' . $end));
    $parts = [];
    if ($diff->y > 0) {
        $parts[] = $diff->y . ' an' . ($diff->y > 1 ? 's' : '');
    }
    if ($diff->m > 0) {
        $parts[] = $diff->m . ' mois';
    }
    if ($diff->d > 0) {
        $parts[] = $diff->d . ' jour' . ($diff->d > 1 ? 's' : '');
    }
    return empty($parts) ? 'Moins d’un jour' : implode(' ', $parts);
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
        '0' => 'Brouillon', '4' => 'En cours', '5' => 'Fermé',
        'draft' => 'Brouillon', 'pending' => 'En attente',
        'open' => 'En cours', 'running' => 'En cours', 'active' => 'En cours',
        'closed' => 'Fermé', 'cancelled' => 'Résilié', 'canceled' => 'Résilié',
        'expired' => 'Expiré', 'suspended' => 'Suspendu',
    ];
    return $map[$n] ?? ($n !== '' ? ucfirst($n) : 'Inconnu');
}

function status_class($status): string
{
    $n = strtolower(trim((string)$status));
    if (in_array($n, ['4', 'open', 'running', 'active'], true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }
    if (in_array($n, ['0', 'draft', 'pending', 'suspended'], true)) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    }
    if (in_array($n, ['5', 'closed', 'cancelled', 'canceled', 'expired'], true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
}

/** Transforme une ligne n8n brute en objet d'affichage normalisé. */
function normalize_subscription(array $row): array
{
    $id  = pick($row, ['id', 'rowid', 'contract_id'], 0);
    $id  = is_numeric($id) ? (int)$id : 0;
    $ref = (string)pick($row, ['ref', 'reference'], 'ABO-' . $id);

    $label = (string)pick(
        $row,
        ['label', 'product_label', 'name', 'description', 'product'],
        '—'
    );

    $startTs = to_timestamp(pick($row, ['date_start', 'date_contrat', 'date_ouverture', 'start', 'date_valid']));
    $endTs   = to_timestamp(pick($row, ['date_end', 'date_fin_validite', 'fin_validite', 'next_payment', 'date_cloture', 'end']));

    $amountRaw = pick($row, ['amount', 'price', 'subprice', 'total_ht', 'total_ttc']);
    $statusRaw = (string)pick($row, ['status', 'statut', 'state'], '');

    // Fréquence : explicite si fournie, sinon déduite de la période.
    $freqExplicit = pick($row, ['frequency', 'periodicity', 'frequence']);
    $frequency = ($freqExplicit !== null && $freqExplicit !== '')
        ? (string)$freqExplicit
        : frequency_display($startTs, $endTs);

    return [
        'id'           => $id,
        'ref'          => $ref,
        'label'        => $label,
        'start'        => date_display($startTs),
        'start_ts'     => $startTs,
        'end'          => date_display($endTs),
        'end_ts'       => $endTs,
        'frequency'    => $frequency,
        'amount'       => amount_display($amountRaw),
        'amount_raw'   => is_numeric($amountRaw) ? (float)$amountRaw : null,
        'status'       => $statusRaw,
        'status_label' => status_label($statusRaw),
        'status_class' => status_class($statusRaw),
    ];
}

// ── Routage ───────────────────────────────────────────────────────────────────
$action = (string)($_GET['action'] ?? '');

try {
    switch ($action) {

        // ── Liste des abonnements du client (LECTURE → GET) ─────────────────
        //  Un nœud Webhook « GET » répond aux lectures ; client_id passe en
        //  paramètre de requête (?action=list&client_id=…).
        case 'list': {
            $resp = n8n_call($N8N_LIST_URL, [
                'action'    => 'list',
                'client_id' => $clientId,
            ], $N8N_TOKEN, 'GET');

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'n8n a renvoyé HTTP ' . $resp['status']]);
            }

            $rows = extract_rows($resp['json']);
            $subscriptions = array_map('normalize_subscription', $rows);

            send_json(200, [
                'ok'            => true,
                'count'         => count($subscriptions),
                'subscriptions' => $subscriptions,
            ]);
        }

        // ── Détail d'un abonnement (LECTURE → GET) ──────────────────────────
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

            // Repli : si n8n renvoie tout, on filtre côté serveur.
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
                send_json(404, ['ok' => false, 'error' => 'Abonnement introuvable.']);
            }

            $subscriptions = array_map('normalize_subscription', $rows);

            send_json(200, [
                'ok'            => true,
                'count'         => count($subscriptions),
                'subscriptions' => $subscriptions,
            ]);
        }

        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue.']);
    }
} catch (Throwable $e) {
    send_json(502, ['ok' => false, 'error' => $e->getMessage()]);
}