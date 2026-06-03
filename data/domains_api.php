<?php

/**
 * data/domains_api.php
 *
 * Proxy serveur entre le portail (menu « Ajouter un domaine ») et le webhook n8n
 * qui pilote la table `domain_portail` :
 *
 *   id, client_id, domain_buy_name, linked_to, gnl_domain, ns_gnl,
 *   verified, domain_active, createdAt, updatedAt
 *
 * Le navigateur appelle CE endpoint (jamais n8n directement) :
 *   - le client_id est injecté ICI depuis la session (non falsifiable) ;
 *   - protection CSRF identique à data/k8s_api.php (header X-CSRF-Token) ;
 *   - la réponse est toujours normalisée en { ok: true/false, ... }.
 *
 * Actions (GET ?action=…, corps en POST form-urlencoded) :
 *   - list   : liste les domaines du client                → { ok, domains: [...] }
 *   - upsert : crée/met à jour une ligne (idempotent)       → { ok, row }
 *   - verify : déclenche/relit la vérification DNS          → { ok, verified: bool, row }
 *   - deploy : rattache le domaine + lance le déploiement   → { ok, row }
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
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
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

/** Label DNS simple (un segment) : déploiement, etc. */
function is_dns_label(string $v): bool
{
    return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $v);
}

/** Nom de domaine complet (FQDN) : labels + TLD ≥ 2. */
function is_domain_name(string $v): bool
{
    $v = rtrim(strtolower(trim($v)), '.');
    if ($v === '' || strlen($v) > 253) {
        return false;
    }
    return (bool)preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $v);
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
//  Écriture (upsert/verify/deploy) → webhook de PRODUCTION (toujours actif).
$N8N_URL   = getenv_non_empty('N8N_DATA_DOMAIN_URL') ?? 'https://api.gnl-solution.fr/webhook/data-domain';
//  Lecture (list, alimente le menu) → webhook de PRODUCTION (toujours actif).
//  Surchargeable via N8N_DATA_DOMAIN_LIST_URL (ex. /webhook-test/ pour debug).
$N8N_LIST_URL = getenv_non_empty('N8N_DATA_DOMAIN_LIST_URL') ?? 'https://api.gnl-solution.fr/webhook/data-domain';
//  Jeton d'authentification optionnel envoyé à n8n (Header Auth du webhook).
$N8N_TOKEN = getenv_non_empty('N8N_WEBHOOK_TOKEN');

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
        // Conteneurs usuels : { domains|data|results|rows|items: [...] }
        foreach (['domains', 'records', 'data', 'results', 'rows', 'items'] as $key) {
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
        // Objet unique ressemblant à une ligne
        if (isset($json['id']) || isset($json['domain_buy_name'])) {
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

// ── Routage ───────────────────────────────────────────────────────────────────
$action = (string)($_GET['action'] ?? '');

try {
    switch ($action) {

        // ── Liste des domaines du client (LECTURE → GET) ────────────────────
        //  n8n distingue les méthodes : un nœud Webhook « GET » répond aux
        //  lectures, un nœud « POST » aux écritures (même chemin data-domain).
        //  client_id passe donc en paramètre de requête (?action=list&client_id=…).
        case 'list': {
            $resp = n8n_call($N8N_LIST_URL, [
                'action'    => 'list',
                'client_id' => $clientId,
            ], $N8N_TOKEN, 'GET');

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'n8n a renvoyé HTTP ' . $resp['status']]);
            }

            send_json(200, ['ok' => true, 'domains' => extract_rows($resp['json'])]);
        }

        // ── Enregistrements DNS d'un domaine (LECTURE → GET) ────────────────
        case 'records': {
            $domain = rtrim(strtolower(trim((string)($_GET['domain'] ?? ''))), '.');
            if (!is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de domaine invalide.']);
            }
            $resp = n8n_call($N8N_LIST_URL, [
                'action'    => 'records',
                'client_id' => $clientId,
                'domain'    => $domain,
            ], $N8N_TOKEN, 'GET');

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'n8n a renvoyé HTTP ' . $resp['status']]);
            }

            send_json(200, ['ok' => true, 'records' => extract_rows($resp['json'])]);
        }

        // ── Ajout / suppression d'un enregistrement DNS (ÉCRITURE → POST) ───
        case 'add_record':
        case 'delete_record': {
            csrf_check();

            $domain = rtrim(strtolower(trim((string)($_POST['domain'] ?? ''))), '.');
            if (!is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de domaine invalide.']);
            }

            $payload = ['action' => $action, 'client_id' => $clientId, 'domain' => $domain];

            if ($action === 'add_record') {
                $type    = strtoupper(trim((string)($_POST['type'] ?? '')));
                $name    = trim((string)($_POST['name'] ?? ''));
                $content = trim((string)($_POST['content'] ?? ''));
                $ttl     = (int)($_POST['ttl'] ?? 3600);
                $allowedTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

                if (!in_array($type, $allowedTypes, true)) {
                    send_json(400, ['ok' => false, 'error' => 'Type d\'enregistrement non supporté.']);
                }
                if ($content === '') {
                    send_json(400, ['ok' => false, 'error' => 'La valeur de l\'enregistrement est requise.']);
                }
                if ($ttl < 60) {
                    $ttl = 60;
                }
                $payload['type']    = $type;
                $payload['name']    = ($name === '') ? '@' : $name;
                $payload['content'] = $content;
                $payload['ttl']     = $ttl;
            } else { // delete_record
                $recordId = trim((string)($_POST['id'] ?? ''));
                if ($recordId === '') {
                    send_json(400, ['ok' => false, 'error' => 'Identifiant d\'enregistrement manquant.']);
                }
                $payload['id'] = $recordId;
            }

            $resp = n8n_call($N8N_URL, $payload, $N8N_TOKEN);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                $detail = is_array($resp['json']) ? (string)($resp['json']['error'] ?? '') : '';
                send_json($resp['status'], [
                    'ok'    => false,
                    'error' => 'n8n a renvoyé HTTP ' . $resp['status'] . ($detail !== '' ? ' — ' . $detail : ''),
                ]);
            }

            send_json(200, ['ok' => true, 'action' => $action]);
        }
        case 'upsert':
        case 'verify':
        case 'deploy': {
            csrf_check();

            $domain   = rtrim(strtolower(trim((string)($_POST['domain_buy_name'] ?? ''))), '.');
            $gnl      = truthy($_POST['gnl_domain'] ?? '0');
            $nsGnl    = truthy($_POST['ns_gnl'] ?? '0');
            $linkedTo = trim((string)($_POST['linked_to'] ?? ''));

            if (!is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de domaine invalide.']);
            }
            if ($linkedTo !== '' && !is_dns_label($linkedTo)) {
                send_json(400, ['ok' => false, 'error' => 'Déploiement cible invalide.']);
            }
            if ($action === 'deploy' && $linkedTo === '') {
                send_json(400, ['ok' => false, 'error' => 'Un déploiement cible est requis.']);
            }

            // Payload envoyé à n8n — colonnes de la table domain_portail.
            //  client_id imposé côté serveur ; verified/domain_active gérés par n8n.
            $payload = [
                'action'          => $action,
                'client_id'       => $clientId,
                'domain_buy_name' => $domain,
                'linked_to'       => $linkedTo,
                'gnl_domain'      => $gnl,
                'ns_gnl'          => $nsGnl,
            ];

            $resp = n8n_call($N8N_URL, $payload, $N8N_TOKEN);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                $detail = is_array($resp['json']) ? (string)($resp['json']['error'] ?? '') : '';
                send_json($resp['status'], [
                    'ok'    => false,
                    'error' => 'n8n a renvoyé HTTP ' . $resp['status'] . ($detail !== '' ? ' — ' . $detail : ''),
                ]);
            }

            $rows = extract_rows($resp['json']);
            $row  = $rows[0] ?? null;

            $out = ['ok' => true, 'action' => $action];
            if ($row !== null) {
                $out['row'] = $row;
            }
            if ($action === 'verify') {
                // verified depuis la ligne renvoyée, sinon depuis un champ racine.
                $verified = false;
                if (is_array($row) && array_key_exists('verified', $row)) {
                    $verified = truthy($row['verified']);
                } elseif (is_array($resp['json']) && array_key_exists('verified', $resp['json'])) {
                    $verified = truthy($resp['json']['verified']);
                }
                $out['verified'] = $verified;
            }

            send_json(200, $out);
        }

        // ── Suppression d'un domaine ────────────────────────────────────────
        case 'delete': {
            csrf_check();

            $domain = rtrim(strtolower(trim((string)($_POST['domain_buy_name'] ?? ''))), '.');
            $id     = trim((string)($_POST['id'] ?? ''));

            if ($id === '' && !is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Domaine ou identifiant requis pour la suppression.']);
            }

            $payload = [
                'action'          => 'delete',
                'client_id'       => $clientId,
                'domain_buy_name' => $domain,
                'id'              => $id !== '' ? $id : null,
            ];

            $resp = n8n_call($N8N_URL, $payload, $N8N_TOKEN); // écriture → POST production

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                $detail = is_array($resp['json']) ? (string)($resp['json']['error'] ?? '') : '';
                send_json($resp['status'], [
                    'ok'    => false,
                    'error' => 'n8n a renvoyé HTTP ' . $resp['status'] . ($detail !== '' ? ' — ' . $detail : ''),
                ]);
            }

            send_json(200, ['ok' => true, 'action' => 'delete']);
        }

        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue.']);
    }
} catch (Throwable $e) {
    send_json(502, ['ok' => false, 'error' => $e->getMessage()]);
}