<?php

/**
 * data/abonnements_api.php
 *
 * Proxy serveur entre le portail (page « Mes abonnements ») et l'API Dolbar
 * (Dolibarr) qui expose les contrats / abonnements du client.
 *
 * Même philosophie que data/domains_api.php :
 *   - le client_id est issu de la session (non falsifiable) ;
 *   - l'authentification Dolbar (token de session, login/mdp ou clé API) est
 *     résolue ICI, jamais exposée au navigateur ;
 *   - la réponse est toujours normalisée en { ok: true/false, ... } ;
 *   - protection CSRF identique (header X-CSRF-Token) pour les éventuelles
 *     écritures futures (les lectures GET n'en ont pas besoin).
 *
 * Actions (GET ?action=…) :
 *   - list   : liste les abonnements du client  → { ok, subscriptions: [...] }
 *   - detail : lignes d'un contrat (?id= ou ?ref=) → { ok, subscriptions: [...] }
 *
 * Chaque abonnement est renvoyé « prêt à l'affichage » :
 *   { id, contract_id, ref, label, start, start_ts, end, end_ts,
 *     frequency, amount, amount_raw, status, status_label, status_class }
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
require_once __DIR__ . '/dolbar_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function send_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Vérifie le jeton CSRF (header X-CSRF-Token vs session) — pour écritures futures. */
function csrf_check(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sess = $_SESSION['csrf'] ?? '';
    if (!is_string($sess) || $sess === '' || !is_string($sent) || !hash_equals($sess, $sent)) {
        send_json(403, ['ok' => false, 'error' => 'Jeton CSRF invalide.']);
    }
}

// ── Authentification portail ──────────────────────────────────────────────────
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

// ── Helpers de normalisation (alignés sur pages/abonnements.php) ──────────────

/** Extrait la liste de lignes depuis une réponse Dolbar tolérante au format. */
function subApiExtractRows($payload): array
{
    if (!is_array($payload)) {
        return [];
    }
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }
    foreach (['data', 'items', 'results', 'subscriptions', 'contracts', 'abonnements'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }
    return [];
}

/** Lignes de service d'un contrat. */
function subApiExtractServices(array $contract): array
{
    foreach (['lines', 'services', 'service_lines', 'detlines'] as $key) {
        if (isset($contract[$key]) && is_array($contract[$key])) {
            return array_values(array_filter($contract[$key], static fn($r): bool => is_array($r)));
        }
    }
    return [];
}

/** Aplatit les contrats en une ligne par service (comme la page). */
function subApiBuildServiceRows(array $contracts): array
{
    $rows = [];
    foreach ($contracts as $contract) {
        if (!is_array($contract)) {
            continue;
        }
        $services = subApiExtractServices($contract);
        if (empty($services)) {
            $rows[] = ['__contract' => $contract, '__service' => $contract] + $contract;
            continue;
        }
        foreach ($services as $service) {
            $rows[] = ['__contract' => $contract, '__service' => $service] + $service + $contract;
        }
    }
    return $rows;
}

/** Conversion date Dolbar → timestamp, avec repli si dolbar_api absent. */
function subApiDateTs($value): ?int
{
    if (function_exists('dolbarApiDateToTimestamp')) {
        return dolbarApiDateToTimestamp($value);
    }
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    $ts = strtotime((string)$value);
    return $ts === false ? null : $ts;
}

function subApiStartTs(array $row): ?int
{
    foreach ([$row['date_contrat'] ?? null, $row['date_ouverture'] ?? null, $row['date_valid'] ?? null] as $c) {
        $ts = subApiDateTs($c);
        if ($ts !== null) {
            return $ts;
        }
    }
    return null;
}

function subApiEndTs(array $row): ?int
{
    $candidates = [
        $row['date_end'] ?? null,
        $row['date_fin_validite'] ?? null,
        $row['fin_validite'] ?? null,
        $row['date_cloture'] ?? null,
    ];
    foreach ($candidates as $c) {
        $ts = subApiDateTs($c);
        if ($ts !== null) {
            return $ts;
        }
    }
    return null;
}

function subApiDateDisplay(?int $ts): string
{
    return ($ts === null || $ts <= 0) ? '—' : date('d/m/Y', $ts);
}

function subApiFrequency(?int $start, ?int $end): string
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

function subApiAmountDisplay($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    return number_format((float)$value, 2, ',', ' ') . ' €';
}

function subApiStatusLabel($status): string
{
    $n = strtolower(trim((string)$status));
    $map = [
        '0' => 'Brouillon', '4' => 'En cours', '5' => 'Fermé',
        'draft' => 'Brouillon', 'open' => 'En cours', 'running' => 'En cours', 'closed' => 'Fermé',
    ];
    return $map[$n] ?? ($n !== '' ? ucfirst($n) : 'Inconnu');
}

function subApiStatusClass($status): string
{
    $n = strtolower(trim((string)$status));
    if (in_array($n, ['4', 'open', 'running'], true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }
    if (in_array($n, ['0', 'draft'], true)) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    }
    if (in_array($n, ['5', 'closed'], true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
}

/** Transforme une ligne de service aplatie en objet d'affichage normalisé. */
function subApiNormalize(array $row): array
{
    $contract = (isset($row['__contract']) && is_array($row['__contract'])) ? $row['__contract'] : $row;
    $service  = (isset($row['__service']) && is_array($row['__service'])) ? $row['__service'] : $row;

    $id         = (int)($service['id'] ?? $contract['id'] ?? 0);
    $contractId = (int)($contract['id'] ?? 0);
    $ref        = (string)($contract['ref'] ?? ('ABO-' . $id));
    $label      = (string)(
        $service['product_label']
        ?? $service['label']
        ?? $service['description']
        ?? $contract['label']
        ?? $contract['description']
        ?? '—'
    );

    $startTs = subApiStartTs($row);
    $endTs   = subApiEndTs($row);

    $amountRaw = $service['subprice']
        ?? $service['total_ht']
        ?? $row['total_ht']
        ?? $row['total_ttc']
        ?? null;

    $statusRaw = (string)($service['statut'] ?? '');

    return [
        'id'           => $id,
        'contract_id'  => $contractId,
        'ref'          => $ref,
        'label'        => $label,
        'start'        => subApiDateDisplay($startTs),
        'start_ts'     => $startTs,
        'end'          => subApiDateDisplay($endTs),
        'end_ts'       => $endTs,
        'frequency'    => subApiFrequency($startTs, $endTs),
        'amount'       => subApiAmountDisplay($amountRaw),
        'amount_raw'   => is_numeric($amountRaw) ? (float)$amountRaw : null,
        'status'       => $statusRaw,
        'status_label' => subApiStatusLabel($statusRaw),
        'status_class' => subApiStatusClass($statusRaw),
    ];
}

/**
 * Charge les contrats du client depuis Dolbar (même logique que la page).
 *
 * @return array{contracts: array, integration: bool}
 * @throws Throwable en cas d'échec d'appel Dolbar
 */
function subApiLoadContracts(): array
{
    if (!dolbarApiIntegrationEnabled()) {
        return ['contracts' => [], 'integration' => false];
    }

    $user = $_SESSION['user'] ?? [];

    $apiUrl       = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $user);
    $login        = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $user);
    $password     = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $user);
    $apiKey       = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $user);
    $sessionToken = dolbarApiResolveSessionToken($_SESSION);

    if ($apiUrl === null) {
        throw new RuntimeException('Configuration Dolbar incomplète (URL manquante).', 0);
    }

    $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);
    $query  = ['sortfield' => 't.rowid', 'sortorder' => 'DESC', 'limit' => 100];

    $endpoints = ['/contracts'];
    $lastError = null;
    $raw       = [];

    foreach ($endpoints as $endpoint) {
        try {
            if ($sessionToken !== '') {
                $raw = dolbarApiCallWithToken($apiUrl, $endpoint, $sessionToken, 'GET', $query, [], 12);
            } elseif ($login !== null && $password !== null) {
                $token = dolbarApiLoginToken($apiUrl, $login, $password, 8);
                $raw   = dolbarApiCallWithToken($apiUrl, $endpoint, $token, 'GET', $query, [], 12);
            } elseif ($apiKey !== null) {
                $raw = dolbarApiCall($apiUrl, $endpoint, $apiKey, 'GET', $query, [], 12);
            } else {
                throw new RuntimeException(
                    'Configuration Dolibarr incomplète (renseigner login/mot de passe ou clé API).',
                    0
                );
            }

            $rows = subApiExtractRows(is_array($raw) ? $raw : []);
            if (!empty($rows) || $endpoint === '/contracts') {
                break;
            }
        } catch (Throwable $endpointError) {
            $lastError = $endpointError;
        }
    }

    if ($lastError !== null && empty($raw)) {
        throw $lastError;
    }

    $contracts = array_values(array_filter(
        subApiExtractRows(is_array($raw) ? $raw : []),
        static fn($row): bool => is_array($row)
    ));

    return ['contracts' => $contracts, 'integration' => true];
}

// ── Routage ───────────────────────────────────────────────────────────────────
$action = (string)($_GET['action'] ?? '');

try {
    switch ($action) {

        // ── Liste des abonnements du client (LECTURE → GET) ─────────────────
        case 'list': {
            try {
                $loaded = subApiLoadContracts();
            } catch (Throwable $e) {
                $code = function_exists('dolbarApiExtractErrorCode')
                    ? (dolbarApiExtractErrorCode($e) ?? 'DLB')
                    : 'DLB';
                send_json(502, ['ok' => false, 'error' => $e->getMessage(), 'code' => $code]);
            }

            $rows = subApiBuildServiceRows($loaded['contracts']);
            $subscriptions = array_map('subApiNormalize', $rows);

            send_json(200, [
                'ok'            => true,
                'integration'   => $loaded['integration'],
                'count'         => count($subscriptions),
                'subscriptions' => $subscriptions,
            ]);
        }

        // ── Détail d'un contrat (LECTURE → GET) ─────────────────────────────
        //  Filtré depuis la même requête contrats : pas d'appel Dolbar supplémentaire.
        case 'detail': {
            $id  = trim((string)($_GET['id'] ?? ''));
            $ref = trim((string)($_GET['ref'] ?? ''));
            if ($id === '' && $ref === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » ou « ref » requis.']);
            }

            try {
                $loaded = subApiLoadContracts();
            } catch (Throwable $e) {
                $code = function_exists('dolbarApiExtractErrorCode')
                    ? (dolbarApiExtractErrorCode($e) ?? 'DLB')
                    : 'DLB';
                send_json(502, ['ok' => false, 'error' => $e->getMessage(), 'code' => $code]);
            }

            $match = null;
            foreach ($loaded['contracts'] as $contract) {
                $cId  = (string)($contract['id'] ?? '');
                $cRef = (string)($contract['ref'] ?? '');
                if (($id !== '' && $cId === $id) || ($ref !== '' && $cRef === $ref)) {
                    $match = $contract;
                    break;
                }
            }

            if ($match === null) {
                send_json(404, ['ok' => false, 'error' => 'Abonnement introuvable.']);
            }

            $rows = subApiBuildServiceRows([$match]);
            $subscriptions = array_map('subApiNormalize', $rows);

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
