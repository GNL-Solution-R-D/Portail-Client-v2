<?php

/**
 * data/documentation_api.php
 *
 * Proxy serveur entre le portail (page « Documentation ») et l'API Dolibarr
 * (module Knowledge Management). Même socle que data/domains_api.php :
 *
 *   - les identifiants Dolibarr sont résolus ICI (config + session) : jamais exposés ;
 *   - le client est authentifié via la session du portail (cookie de session) ;
 *   - protection CSRF disponible (header X-CSRF-Token) pour de futures écritures ;
 *   - la réponse est toujours normalisée en { ok: true/false, ... }.
 *
 * Le navigateur appelle CE endpoint (jamais Dolibarr directement).
 *
 * Actions (GET ?action=…) :
 *   - list   : liste tous les articles                  → { ok, articles:[...], count }
 *   - search : filtre par mot-clé (?q=…) côté serveur   → { ok, articles:[...], count, query }
 *
 * Structure d'un article renvoyé :
 *   { id, title, category, summary, content, content_html, updated_at }
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

// Cookie de session valable sur /pages/* ET /data/* (identique à domains_api.php).
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

require_once __DIR__ . '/../config_loader.php';
require_once __DIR__ . '/../include/account_sessions.php';
require_once __DIR__ . '/../include/lang.php';
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

/** Repli si lang.php n'expose pas t() (l'API renvoie surtout des données brutes). */
if (!function_exists('t')) {
    function t(string $s): string
    {
        return $s;
    }
}

/** Vérifie le jeton CSRF (header X-CSRF-Token vs session) — pour de futures écritures. */
function csrf_check(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sess = $_SESSION['csrf'] ?? '';
    if (!is_string($sess) || $sess === '' || !is_string($sent) || !hash_equals($sess, $sent)) {
        send_json(403, ['ok' => false, 'error' => 'Jeton CSRF invalide.']);
    }
}

/** strpos insensible à la casse et compatible UTF-8 (repli si mbstring absent). */
function documentationContains(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
}

// ── Authentification (identique à data/domains_api.php) ───────────────────────
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

// ── Helpers de normalisation (repris de l'ancien documentation.php) ───────────

function documentationExtractRows(array $payload): array
{
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }

    foreach (['data', 'items', 'results', 'records', 'knowledgerecords', 'knowledgebase'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    return [];
}

function documentationFirstValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = $row[$key];
        if (is_string($value) || is_numeric($value)) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
    }

    return '';
}

function documentationDateDisplay(array $row): string
{
    foreach (['date_modification', 'date_update', 'tms', 'date_creation', 'datec', 'date'] as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $timestamp = dolbarApiDateToTimestamp($row[$key]);
        if ($timestamp !== null) {
            return date('d/m/Y', $timestamp);
        }
    }

    return '—';
}

function documentationPlainText(string $value): string
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $stripped = strip_tags($decoded);
    $normalized = preg_replace('/\s+/u', ' ', $stripped);

    return trim((string) $normalized);
}

function documentationHtmlToDisplay(string $value): string
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $allowed = '<p><br><ul><ol><li><strong><b><em><i><u><a><code><pre><blockquote>';
    $safe = strip_tags($decoded, $allowed);

    return trim($safe);
}

/**
 * Récupère et normalise les articles depuis l'API Dolibarr (Knowledge Management).
 *
 * @return array<int, array<string, mixed>>
 * @throws Throwable en cas d'échec réseau / configuration incomplète.
 */
function documentationFetchArticles(): array
{
    $apiUrl       = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $_SESSION['user']);
    $login        = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $_SESSION['user']);
    $password     = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $_SESSION['user']);
    $apiKey       = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $_SESSION['user']);
    $sessionToken = dolbarApiResolveSessionToken($_SESSION);

    if ($apiUrl === null) {
        throw new RuntimeException(t('Configuration Dolibarr incomplète (URL manquante).'), 0);
    }

    $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);

    $query = [
        'sortfield' => 't.rowid',
        'sortorder' => 'DESC',
        'limit'     => 200,
    ];

    $endpoints = [
        '/knowledgemanagement',
        '/knowledgemanagement/knowledgerecords',
        '/knowledgemanagement/records',
        '/knowledgebase/records',
        '/knowledgerecords',
    ];

    $doRequest = static function (string $endpoint) use ($apiUrl, $sessionToken, $login, $password, $apiKey, $query): array {
        if ($sessionToken !== '') {
            return dolbarApiCallWithToken($apiUrl, $endpoint, $sessionToken, 'GET', $query, [], 12);
        }

        if ($login !== null && $password !== null) {
            $token = dolbarApiLoginToken($apiUrl, $login, $password, 8);
            return dolbarApiCallWithToken($apiUrl, $endpoint, $token, 'GET', $query, [], 12);
        }

        if ($apiKey !== null) {
            return dolbarApiCall($apiUrl, $endpoint, $apiKey, 'GET', $query, [], 12);
        }

        throw new RuntimeException(
            t('Configuration Dolibarr incomplète (renseigner login/mot de passe ou clé API).'),
            0
        );
    };

    $lastError = null;
    $rows = [];

    foreach ($endpoints as $endpoint) {
        try {
            $payload = $doRequest($endpoint);
            $rows = documentationExtractRows($payload);
            if ($rows !== []) {
                break;
            }
        } catch (Throwable $endpointError) {
            $lastError = $endpointError;
        }
    }

    if ($rows === [] && $lastError !== null) {
        throw $lastError;
    }

    $articles = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id       = (int) documentationFirstValue($row, ['id', 'rowid']);
        $title    = documentationFirstValue($row, ['question', 'title', 'label', 'name', 'ref']);
        $category = documentationFirstValue($row, ['category', 'category_label', 'type_label', 'type']);

        $summaryRaw = documentationFirstValue($row, ['question', 'description', 'summary', 'note_public', 'note', 'content']);
        $contentRaw = documentationFirstValue($row, ['answer', 'content', 'description', 'body', 'note_public', 'note']);

        $summary     = documentationPlainText($summaryRaw);
        $content     = documentationPlainText($contentRaw);
        $contentHtml = documentationHtmlToDisplay($contentRaw);

        if ($summary === '' && $content !== '') {
            $summary = mb_substr($content, 0, 180);
            if (mb_strlen($content) > 180) {
                $summary .= '…';
            }
        }

        $articles[] = [
            'id'           => $id,
            'title'        => $title !== '' ? $title : 'Article sans titre',
            'category'     => $category !== '' ? $category : 'Général',
            'summary'      => $summary,
            'content'      => $content,
            'content_html' => $contentHtml,
            'updated_at'   => documentationDateDisplay($row),
        ];
    }

    return $articles;
}

// ── Routage ───────────────────────────────────────────────────────────────────
$action = (string)($_GET['action'] ?? 'list');

try {
    switch ($action) {

        // ── Liste / recherche d'articles (LECTURE → GET) ────────────────────
        case 'list':
        case 'search': {
            // Intégration désactivée : on renvoie une liste vide (pas une erreur),
            // exactement comme le faisait l'ancienne page côté serveur.
            if (!dolbarApiIntegrationEnabled()) {
                $out = ['ok' => true, 'articles' => [], 'count' => 0];
                if ($action === 'search') {
                    $out['query'] = trim((string)($_GET['q'] ?? ''));
                }
                send_json(200, $out);
            }

            $articles = documentationFetchArticles();

            // Tri alphabétique par titre (comme l'ancienne page).
            usort(
                $articles,
                static fn(array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title'])
            );

            $query = '';
            if ($action === 'search') {
                $query = trim((string)($_GET['q'] ?? ''));
                if ($query !== '') {
                    $articles = array_values(array_filter(
                        $articles,
                        static function (array $a) use ($query): bool {
                            $haystack = $a['title'] . ' ' . $a['category'] . ' ' . $a['summary'] . ' ' . $a['content'];
                            return documentationContains((string) $haystack, $query);
                        }
                    ));
                }
            }

            $out = ['ok' => true, 'articles' => $articles, 'count' => count($articles)];
            if ($action === 'search') {
                $out['query'] = $query;
            }

            send_json(200, $out);
        }

        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue.']);
    }
} catch (Throwable $e) {
    $code = dolbarApiExtractErrorCode($e) ?? 'DLB';
    send_json(502, ['ok' => false, 'error' => $e->getMessage(), 'code' => $code]);
}
