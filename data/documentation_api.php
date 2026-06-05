<?php

/**
 * data/documentation_api.php
 *
 * Proxy serveur entre le portail (page « Documentation ») et le webhook n8n
 * qui sert la base de connaissance.
 *
 * Même socle que data/domains_api.php :
 *   - le client_id est injecté ICI depuis la session (non falsifiable) ;
 *   - protection CSRF disponible (header X-CSRF-Token) pour de futures écritures ;
 *   - la réponse est toujours normalisée en { ok: true/false, ... }.
 *
 * Le navigateur appelle CE endpoint (jamais n8n directement).
 *
 * Actions (GET ?action=…) :
 *   - list   : liste tous les articles                       → { ok, articles:[...], count }
 *   - search : liste filtrée par mot-clé (?q=…, côté serveur) → { ok, articles:[...], count, query }
 *
 * Structure d'un article renvoyé :
 *   { id, title, category, summary, content, content_html, updated_at }
 *
 * Remarque : la recherche est filtrée ICI (en PHP) sur la liste renvoyée par n8n.
 * Le workflow n8n n'a donc qu'à savoir répondre à « list » ; si plus tard il sait
 * filtrer lui-même, le champ q lui est tout de même transmis.
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

/** Vérifie le jeton CSRF (header X-CSRF-Token vs session) — pour de futures écritures. */
function csrf_check(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sess = $_SESSION['csrf'] ?? '';
    if (!is_string($sess) || $sess === '' || !is_string($sent) || !hash_equals($sess, $sent)) {
        send_json(403, ['ok' => false, 'error' => 'Jeton CSRF invalide.']);
    }
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

// ── Configuration du webhook n8n ─────────────────────────────────────────────
//  Lecture (list/search, alimente la page) → webhook de PRODUCTION.
//  Surchargeable via N8N_DATA_DOC_LIST_URL (ex. /webhook-test/ pour debug).
$N8N_LIST_URL = getenv_non_empty('N8N_DATA_DOC_LIST_URL') ?? 'https://api.gnl-solution.fr/webhook/data-documentation';
//  Écriture (réservé à de futures actions create/update/delete) → webhook PRODUCTION.
$N8N_URL      = getenv_non_empty('N8N_DATA_DOC_URL')      ?? 'https://api.gnl-solution.fr/webhook/data-documentation';
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
        // n8n « Header Auth » : adapter le nom d'en-tête à votre workflow.
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
        // Conteneurs usuels : { articles|documents|data|results|rows|items: [...] }
        foreach (['articles', 'documents', 'records', 'knowledgebase', 'data', 'results', 'rows', 'items'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $json = $json[$key];
                break;
            }
        }
        // Tableau brut de lignes (clé numérique 0 présente, ou tableau vide)
        if ($json === [] || array_key_exists(0, $json)) {
            return array_map($unwrap, array_values($json));
        }
        // Item n8n unique { "json": {...} }
        if (isset($json['json']) && is_array($json['json'])) {
            return [$json['json']];
        }
        // Objet unique ressemblant à un article
        if (isset($json['id']) || isset($json['title']) || isset($json['question'])) {
            return [$json];
        }
    }
    return [];
}

// ── Normalisation d'un article (tolérante aux noms de champs n8n) ─────────────

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

function documentationPlainText(string $value): string
{
    $decoded    = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $stripped   = strip_tags($decoded);
    $normalized = preg_replace('/\s+/u', ' ', $stripped);

    return trim((string) $normalized);
}

function documentationHtmlToDisplay(string $value): string
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $allowed = '<p><br><ul><ol><li><strong><b><em><i><u><a><code><pre><blockquote>';
    $safe    = strip_tags($decoded, $allowed);

    return trim($safe);
}

/** Transforme une date n8n (timestamp s/ms, ISO 8601, « Y-m-d H:i:s »…) en « d/m/Y ». */
function documentationDate(array $row): string
{
    $keys = [
        'date_modification', 'date_update', 'updatedAt', 'updated_at', 'tms',
        'date_creation', 'createdAt', 'created_at', 'datec', 'date',
    ];

    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = $row[$key];
        if ($value === null || $value === '' || $value === false) {
            continue;
        }

        if (is_numeric($value)) {
            $ts = (int) $value;
            if ($ts > 100000000000) { // millisecondes → secondes
                $ts = (int) ($ts / 1000);
            }
            if ($ts > 0) {
                return date('d/m/Y', $ts);
            }
            continue;
        }

        $ts = strtotime((string) $value);
        if ($ts !== false) {
            return date('d/m/Y', $ts);
        }
    }

    return '—';
}

/** Mappe une ligne n8n vers la structure d'article attendue par la page. */
function documentationNormalize(array $row): array
{
    $id       = (int) documentationFirstValue($row, ['id', 'rowid']);
    $title    = documentationFirstValue($row, ['title', 'question', 'label', 'name', 'ref', 'subject']);
    $category = documentationFirstValue($row, ['category', 'category_label', 'type_label', 'type', 'tag', 'section']);

    $summaryRaw = documentationFirstValue($row, ['summary', 'question', 'description', 'excerpt', 'note_public', 'note']);
    $contentRaw = documentationFirstValue($row, ['content', 'answer', 'description', 'body', 'html', 'note_public', 'note', 'text']);

    $summary     = documentationPlainText($summaryRaw);
    $content     = documentationPlainText($contentRaw);
    $contentHtml = documentationHtmlToDisplay($contentRaw);

    if ($summary === '' && $content !== '') {
        $summary = mb_substr($content, 0, 180);
        if (mb_strlen($content) > 180) {
            $summary .= '…';
        }
    }

    return [
        'id'           => $id,
        'title'        => $title !== '' ? $title : 'Article sans titre',
        'category'     => $category !== '' ? $category : 'Général',
        'summary'      => $summary,
        'content'      => $content,
        'content_html' => $contentHtml,
        'updated_at'   => documentationDate($row),
    ];
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

// ── Routage ───────────────────────────────────────────────────────────────────
$action = (string)($_GET['action'] ?? 'list');

try {
    switch ($action) {

        // ── Liste / recherche d'articles (LECTURE → GET) ────────────────────
        //  On demande toujours « list » à n8n ; le champ q est transmis au cas où
        //  le workflow sait filtrer, mais le filtrage de garantie se fait ICI.
        case 'list':
        case 'search': {
            $query = ($action === 'search') ? trim((string)($_GET['q'] ?? '')) : '';

            $payload = ['action' => 'list', 'client_id' => $clientId];
            if ($query !== '') {
                $payload['q'] = $query;
            }

            $resp = n8n_call($N8N_LIST_URL, $payload, $N8N_TOKEN, 'GET');

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], [
                    'ok'    => false,
                    'error' => 'n8n a renvoyé HTTP ' . $resp['status'],
                    'code'  => 'N8N',
                ]);
            }

            $rows = extract_rows($resp['json']);

            $articles = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $articles[] = documentationNormalize($row);
                }
            }

            // Tri alphabétique par titre.
            usort(
                $articles,
                static fn(array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title'])
            );

            // Filtrage de garantie côté serveur pour l'action search.
            if ($query !== '') {
                $articles = array_values(array_filter(
                    $articles,
                    static function (array $a) use ($query): bool {
                        $haystack = $a['title'] . ' ' . $a['category'] . ' ' . $a['summary'] . ' ' . $a['content'];
                        return documentationContains((string) $haystack, $query);
                    }
                ));
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
    send_json(502, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'N8N']);
}