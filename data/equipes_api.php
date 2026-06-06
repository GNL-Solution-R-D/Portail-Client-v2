<?php

/**
 * data/equipes_api.php
 *
 * Proxy serveur entre le portail (page « Équipes / Membres ») et le webhook n8n
 * qui pilote les membres de la structure du client.
 *
 * Même architecture que data/domains_api.php / abonnements_api.php / factures_api.php :
 *   - le navigateur appelle CE endpoint (jamais n8n directement) ;
 *   - le client_id est injecté ICI depuis la session (non falsifiable) ;
 *   - les droits d'édition (can_edit) sont calculés ICI à partir de la session ;
 *   - protection CSRF (header X-CSRF-Token) sur l'écriture ;
 *   - réponse toujours normalisée en { ok: true/false, ... }.
 *
 * Actions :
 *   - list   (GET)  → { ok, count, members:[...], structure, can_edit }
 *   - update (POST) → { ok, message }      (CSRF + droits requis)
 *
 * Chaque membre est renvoyé « prêt à l'affichage » :
 *   { id, name, secondary, initials, function, email, fonction,
 *     status_label, status_class, active, perm_id, permission, structure }
 *
 * ── Schéma attendu côté n8n (tolérant : plusieurs noms de champ acceptés) ─────
 *   id|rowid|contact_id, civilite|civility, prenom|firstname, nom|lastname,
 *   username|login|email, email, fonction|poste|job,
 *   statut|status|active, perm_id|permission|role_id (défaut 6 = Invité),
 *   structure|company|socname (facultatif, nom de la structure).
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

function getenv_non_empty(string $name): ?string
{
    $v = getenv($name);
    if ($v === false) {
        return null;
    }
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

/** Vérifie le jeton CSRF (header X-CSRF-Token vs session $_SESSION['csrf']). */
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

$user     = $_SESSION['user'];
$clientId = (int)($user['id'] ?? 0);
if ($clientId <= 0) {
    send_json(401, ['ok' => false, 'error' => 'Identifiant client introuvable dans la session.']);
}

if (accountSessionsIsCurrentSessionRevoked($pdo, $clientId)) {
    accountSessionsDestroyPhpSession();
    send_json(401, ['ok' => false, 'error' => 'Cette session a été déconnectée depuis vos paramètres.']);
}
accountSessionsTouchCurrent($pdo, $clientId);

// ── Droits & contexte (calculés serveur, non falsifiables) ────────────────────
$currentSiret  = trim((string)($user['siret'] ?? ''));
$currentPermId = (int)($user['perm_id'] ?? 255);
$canEdit       = ($currentSiret !== '' && in_array($currentPermId, [0, 1, 2, 3, 4], true));

$sessionStructure = trim((string)(
    $user['raison']
    ?? $user['organization_name']
    ?? $user['organization']
    ?? $user['nom_commercial']
    ?? ''
));

// ── Configuration du webhook n8n ─────────────────────────────────────────────
$N8N_URL      = getenv_non_empty('N8N_DATA_TEAM_URL') ?? 'https://api.gnl-solution.fr/webhook/data-team';
$N8N_LIST_URL = getenv_non_empty('N8N_DATA_TEAM_LIST_URL') ?? 'https://api.gnl-solution.fr/webhook/data-team';
$N8N_TOKEN    = getenv_non_empty('N8N_WEBHOOK_TOKEN');

/**
 * Relaie un payload au webhook n8n et renvoie la réponse décodée.
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
        foreach (['members', 'membres', 'contacts', 'users', 'data', 'results', 'rows', 'items'] as $key) {
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
        if (isset($json['id']) || isset($json['email']) || isset($json['nom']) || isset($json['lastname'])) {
            return [$json];
        }
    }
    return [];
}

// ── Helpers d'affichage (alignés sur l'ancienne page equipes.php) ─────────────

function pick(array $row, array $keys, $default = null)
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && trim((string)$row[$k]) !== '') {
            return $row[$k];
        }
    }
    return $default;
}

function s_lower($v): string
{
    $v = (string)$v;
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
}

function s_upper($v): string
{
    $v = (string)$v;
    return function_exists('mb_strtoupper') ? mb_strtoupper($v, 'UTF-8') : strtoupper($v);
}

function s_sub($v, int $start, ?int $len = null): string
{
    $v = (string)$v;
    if (function_exists('mb_substr')) {
        return $len === null ? mb_substr($v, $start, null, 'UTF-8') : mb_substr($v, $start, $len, 'UTF-8');
    }
    return $len === null ? substr($v, $start) : substr($v, $start, $len);
}

function permission_label($permId): string
{
    $id = max(0, min(255, (int)$permId));
    $labels = [
        0 => 'Accès complet',
        1 => 'Signataire/Représentant',
        2 => 'Accès financier',
        3 => 'Accès trésorerie',
        4 => 'Accès technique',
        5 => 'Lecture seule',
        6 => 'Invité',
    ];
    return $labels[$id] ?? 'Profil non défini';
}

/** [label, active] : Actif/Inactif/texte libre + drapeau actif 0/1. */
function member_status(array $m): array
{
    $raw = $m['statut'] ?? $m['status'] ?? null;
    if ($raw === null || trim((string)$raw) === '') {
        if (isset($m['active'])) {
            $active = ((int)(is_bool($m['active']) ? ($m['active'] ? 1 : 0) : $m['active']) === 1);
            return [$active ? 'Actif' : 'Inactif', $active ? 1 : 0];
        }
        return ['Actif', 1];
    }
    if (is_numeric($raw)) {
        $active = ((int)$raw === 1);
        return [$active ? 'Actif' : 'Inactif', $active ? 1 : 0];
    }
    $label  = (string)$raw;
    $active = in_array(s_lower(trim($label)), ['actif', 'active', 'on', 'enabled', 'ok', 'en poste', 'disponible'], true) ? 1 : 0;
    return [$label, $active];
}

function status_class(string $status): string
{
    $n = s_lower(trim($status));
    $positive = ['actif', 'active', 'online', 'enabled', 'ok', 'en poste', 'disponible'];
    $negative = ['inactif', 'inactive', 'offline', 'disabled', 'bloqué', 'suspendu'];
    if (in_array($n, $positive, true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }
    if (in_array($n, $negative, true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
}

function member_name(array $m, int $id): string
{
    $parts = [];
    foreach (['civilite' => ['civilite', 'civility', 'civility_code'], 'prenom' => ['prenom', 'firstname', 'first_name'], 'nom' => ['nom', 'lastname', 'last_name']] as $cands) {
        $v = pick($m, $cands);
        if ($v !== null) {
            $parts[] = trim((string)$v);
        }
    }
    if (!empty($parts)) {
        return implode(' ', $parts);
    }
    $u = pick($m, ['username', 'login', 'email']);
    if ($u !== null) {
        return trim((string)$u);
    }
    return 'Utilisateur #' . $id;
}

function member_secondary(array $m): string
{
    $v = pick($m, ['email', 'username', 'login']);
    return $v !== null ? trim((string)$v) : 'Compte interne';
}

function member_initials(array $m): string
{
    $fn = trim((string)(pick($m, ['prenom', 'firstname', 'first_name']) ?? ''));
    $ln = trim((string)(pick($m, ['nom', 'lastname', 'last_name']) ?? ''));
    $i  = s_upper(($fn !== '' ? s_sub($fn, 0, 1) : '') . ($ln !== '' ? s_sub($ln, 0, 1) : ''));
    if ($i !== '') {
        return $i;
    }
    $u = trim((string)(pick($m, ['username', 'login', 'email']) ?? ''));
    if ($u !== '') {
        return s_upper(s_sub($u, 0, 2));
    }
    return '#';
}

function normalize_member(array $row): array
{
    $id = (int)(pick($row, ['id', 'rowid', 'contact_id']) ?? 0);
    [$statusLabel, $active] = member_status($row);
    $permId   = (int)(pick($row, ['perm_id', 'permission', 'role_id']) ?? 6);
    $function = trim((string)(pick($row, ['fonction', 'poste', 'job', 'function']) ?? ''));
    $email    = trim((string)(pick($row, ['email']) ?? ''));

    return [
        'id'           => $id,
        'name'         => member_name($row, $id),
        'secondary'    => member_secondary($row),
        'initials'     => member_initials($row),
        'function'     => $function !== '' ? $function : 'Aucune fonction définie',
        'fonction'     => $function,            // brut, pour le formulaire d'édition
        'email'        => $email,               // brut, pour le formulaire d'édition
        'status_label' => $statusLabel,
        'status_class' => status_class($statusLabel),
        'active'       => $active,
        'perm_id'      => $permId,
        'permission'   => permission_label($permId),
        'structure'    => trim((string)(pick($row, ['structure', 'company', 'socname', 'raison']) ?? '')),
    ];
}

// ── Routage ───────────────────────────────────────────────────────────────────
$action = (string)($_REQUEST['action'] ?? '');

try {
    switch ($action) {

        // ── Liste des membres (LECTURE → GET) ───────────────────────────────
        case 'list': {
            $resp = n8n_call($N8N_LIST_URL, [
                'action'    => 'list',
                'client_id' => $clientId,
                'siret'     => $currentSiret,
            ], $N8N_TOKEN, 'GET');

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'n8n a renvoyé HTTP ' . $resp['status']]);
            }

            $rows    = extract_rows($resp['json']);
            $members = array_map('normalize_member', $rows);

            // Nom de structure : n8n (top-level ou 1re ligne) sinon session.
            $structure = $sessionStructure;
            if (is_array($resp['json']) && !empty($resp['json']['structure'])) {
                $structure = trim((string)$resp['json']['structure']);
            } elseif (!empty($members[0]['structure'])) {
                $structure = $members[0]['structure'];
            }

            send_json(200, [
                'ok'        => true,
                'count'     => count($members),
                'members'   => $members,
                'structure' => $structure,
                'can_edit'  => $canEdit,
            ]);
        }

        // ── Mise à jour d'un membre (ÉCRITURE → POST + CSRF + droits) ────────
        case 'update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(405, ['ok' => false, 'error' => 'Méthode non autorisée (POST requis).']);
            }
            csrf_check();

            if (!$canEdit) {
                send_json(403, ['ok' => false, 'error' => "Vous n'avez pas les droits pour modifier les membres de cette structure."]);
            }

            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId <= 0) {
                send_json(400, ['ok' => false, 'error' => 'Membre invalide.']);
            }

            $email    = trim((string)($_POST['email'] ?? ''));
            $fonction = trim((string)($_POST['fonction'] ?? ''));
            $statutIn = s_lower(trim((string)($_POST['statut'] ?? '')));
            $active   = in_array($statutIn, ['1', 'actif', 'active', 'on', 'enabled', 'true'], true) ? 1 : 0;

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                send_json(400, ['ok' => false, 'error' => 'Adresse e-mail invalide.']);
            }

            $resp = n8n_call($N8N_URL, [
                'action'    => 'update',
                'client_id' => $clientId,
                'siret'     => $currentSiret,
                'member_id' => $memberId,
                'email'     => $email,
                'fonction'  => $fonction,
                'statut'    => $active,
                'active'    => $active,
            ], $N8N_TOKEN, 'POST');

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'La mise à jour a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'La mise à jour a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => 'Le membre a été mis à jour.']);
        }

        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue.']);
    }
} catch (Throwable $e) {
    send_json(502, ['ok' => false, 'error' => $e->getMessage()]);
}
