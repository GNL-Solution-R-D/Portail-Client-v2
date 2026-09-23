<?php

/**
 * data/portail_api.php
 *
 * Proxy serveur UNIQUE entre le portail (front) et n8n.
 * Fusionne les anciens endpoints :
 *   domains_api.php · documentation_api.php · abonnements_api.php ·
 *   factures_api.php · equipes_api.php · deployments_api.php · notifications_api.php
 *
 * Principes (identiques aux anciens fichiers) :
 *   - le navigateur appelle CE endpoint, jamais n8n directement ;
 *   - le client_id est injecté ICI depuis la session (non falsifiable) ;
 *   - protection CSRF (header X-CSRF-Token) sur toutes les écritures ;
 *   - réponse toujours normalisée en { ok: true/false, ... }.
 *
 * ── Différences avec les anciens fichiers ─────────────────────────────────────
 *   1) Un SEUL webhook n8n est utilisé pour tout (lecture ET écriture) :
 *          https://api.gnl-solution.fr/webhook/portail-gestion-infrastructure   (méthode POST)
 *   2) Le champ "action" est PRÉFIXÉ par le module. n8n aiguille via un nœud
 *      Switch sur {{ $json.action }}. Exemples :
 *          "domain.list"          au lieu de "list"
 *          "documentation.search" au lieu de "search"
 *          "invoice.detail"       au lieu de "detail"
 *          "team.update"          au lieu de "update"
 *
 * ── Contrat navigateur → CE endpoint ──────────────────────────────────────────
 *   Lectures  → GET  ?action=<module>.<sous-action>   (pas de CSRF)
 *   Écritures → POST ?action=<module>.<sous-action>   (header X-CSRF-Token)
 *
 * ── Carte des actions ─────────────────────────────────────────────────────────
 *   DOMAINES
 *     domain.list           GET   → { ok, domains:[...] }
 *     domain.records        GET   ?domain=            → { ok, records:[...] }
 *     domain.add_record     POST  CSRF                → { ok, action }
 *     domain.delete_record  POST  CSRF                → { ok, action }
 *     domain.upsert         POST  CSRF                → { ok, action, row? }
 *     domain.verify         POST  CSRF                → { ok, action, row?, verified }
 *     domain.deploy         POST  CSRF                → { ok, action, row? }
 *     domain.delete         POST  CSRF                → { ok, action }
 *   DOCUMENTATION
 *     documentation.list    GET                       → { ok, articles:[...], count }
 *     documentation.search  GET   ?q=                 → { ok, articles:[...], count, query }
 *   ABONNEMENTS
 *     subscription.list     GET                       → { ok, count, linked, subscriptions:[...] }
 *     subscription.detail   GET   ?id= | ?ref=        → { ok, count, linked, subscriptions:[...] }
 *                                 ⚠️ NE passe PAS par n8n : API Mollie en direct
 *                                 (include/mollie_client.php), pour le client Mollie
 *                                 (cst_…) de l'attribut d'ORGANISATION Keycloak
 *                                 « moliecliid » (organisation de la session).
 *                                 Sans cet attribut : liste vide, linked:false.
 *   FACTURES
 *     invoice.list          GET                       → { ok, count, invoices:[...] }
 *     invoice.detail        GET   ?id= | ?ref=        → { ok, count, invoices:[...] }
 *   COMMANDES
 *     order.list            GET                       → { ok, count, orders:[...] }
 *     order.detail          GET   ?id= | ?ref=        → { ok, count, products:[ {..., options:[...]} ],
 *                                                       extra_options:[...], totals:{...} }
 *                                 (n'appelle PAS « order.detail » côté n8n : uniquement
 *                                  order.product puis order.product.option. L'en-tête de
 *                                  commande vient déjà d'order.list.)
 *     order.product         GET   ?id= | ?ref=        → { ok, count, products:[...] }
 *     order.product.option  GET   ?id= | ?ref=        → { ok, count, options:[...] }
 *   CATALOGUE PRODUITS
 *     product.list          GET                       → { ok, count, products:[...] }
 *                                 (table product : slug, name, esp_cli_menu_name, …
 *                                  utilisée par data/services_menu_api.php pour ranger
 *                                  les produits achetés dans les dépliants du menu)
 *   ÉQUIPES
 *     team.list             GET                       → { ok, count, members:[...], structure, organization, source, can_edit:false }
 *                                 ⚠️ NE passe PAS par n8n : les membres viennent des
 *                                 ORGANIZATIONS de Keycloak (Admin REST), pour
 *                                 l'organisation retenue à la connexion.
 *                                 Voir include/keycloak_organizations.php.
 *                                 Lecture seule (can_edit toujours false).
 *     team.ensure           POST  CSRF                → { ok, message, row? }   (provisionne la ligne « team » du client courant)
 *     team.update           POST  CSRF + droits       → { ok, message }
 *                                 (conservé pour la table « team » n8n ; plus appelé
 *                                  par /equipes depuis le passage en lecture seule)
 *   RENOMMAGE « Mes services » (table label_portail V2)
 *     deployment.list       GET                       → { ok, deployments:[ {product_uid, display_name} ] }
 *     deployment.rename     POST  CSRF  product_uid=… → { ok, row }
 *                                 (la clé de renommage est order_product.uid,
 *                                  colonne label_portail.product_uid)
 *   NOTIFICATIONS (cloche)
 *     notification.list     GET   ?limit=             → { ok, notifications:[...], unread:N }
 *     notification.read     POST  CSRF  id=… | all=1  → { ok }
 *   STATISTIQUES (cartes + graphique du dashboard)
 *     stats.dashboard       GET                       → { ok, current_month_hits, previous_month_hits, by_month:{...}, by_deployment:{...} }
 *   TICKETS (support / assistance)
 *     ticket.list           GET                       → { ok, count, tickets:[...] }
 *     ticket.detail         GET   ?id=                → { ok, ticket:{..., messages:[...]} }
 *     ticket.create         POST  CSRF                → { ok, message, ticket? }
 *     ticket.reply          POST  CSRF                → { ok, message }
 *     ticket.close          POST  CSRF  reopen=0|1    → { ok, message }
 *   SUPPORT (console équipe GNL — require_support)
 *     support.ticket.list   GET   ?status=            → { ok, count, tickets:[...] }
 *     support.ticket.detail GET   ?id=                → { ok, ticket:{..., messages:[...]} }
 *     support.ticket.reply  POST  CSRF                → { ok, message }   (author_type=support)
 *     support.ticket.update POST  CSRF  status|priority → { ok, message }
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
require_once __DIR__ . '/../include/portail_api_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// ══════════════════════════════════════════════════════════════════════════════
//  Configuration : UN SEUL webhook n8n, toujours en POST.
// ══════════════════════════════════════════════════════════════════════════════
const N8N_PORTAIL_URL = 'https://api.gnl-solution.fr/webhook/portail-gestion-infrastructure';

// ── Contrôle d'accès « support » (console gestion-ticket.php) ──────────────────
// Si CE déploiement est entièrement dédié au support (tous les comptes connectés
// sont des agents), laissez TICKET_SUPPORT_SITE = true : tout utilisateur authentifié
// est alors considéré comme support, sans heuristique.
//
// ⚠️  Si ce MÊME proxy sert aussi un portail CLIENT, repassez-le à false : le contrôle
//     par rôle / domaine e-mail / SIRET ci-dessous s'appliquera alors (fail-closed).
const TICKET_SUPPORT_SITE          = true;
const TICKET_SUPPORT_EMAIL_DOMAINS = ['gnl-solution.fr']; // utilisé seulement si TICKET_SUPPORT_SITE = false
const TICKET_SUPPORT_SIRETS        = [];                   // idem

// ══════════════════════════════════════════════════════════════════════════════
//  Helpers communs
// ══════════════════════════════════════════════════════════════════════════════

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

/** Exige la méthode POST pour les écritures. */
function require_post(): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        send_json(405, ['ok' => false, 'error' => 'Méthode non autorisée (POST requis).']);
    }
}

/**
 * Relaie un payload au webhook n8n UNIQUE, toujours en POST JSON,
 * et renvoie la réponse décodée.
 *
 * @return array{status:int, json:mixed, raw:string}
 */
function n8n_call(array $payload): array
{
    // Transport centralisé dans include/portail_api_client.php afin d'être
    // réutilisable hors de ce proxy (ex. keycloak_callback.php → team.ensure).
    // Forme de retour identique : { status, json, raw }.
    return portailApiCall($payload);
}

/** Échec si n8n renvoie un code HTTP hors plage 2xx. */
function ensure_ok(array $resp): void
{
    if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
        $detail = is_array($resp['json']) ? (string)($resp['json']['error'] ?? '') : '';
        send_json($resp['status'] ?: 502, [
            'ok'    => false,
            'error' => 'n8n a renvoyé HTTP ' . $resp['status'] . ($detail !== '' ? ' — ' . $detail : ''),
        ]);
    }
}

/**
 * Extrait une liste de lignes depuis une réponse n8n tolérante au format.
 *
 * @param array  $containerKeys clés de conteneur spécifiques au module
 * @param array  $idKeys        clés qui identifient un objet « ligne » unique
 */
function extract_rows($json, array $containerKeys = [], array $idKeys = ['id']): array
{
    // Déballe le format d'item n8n { "json": {...} } → {...}
    $unwrap = static function ($v) {
        return (is_array($v) && isset($v['json']) && is_array($v['json'])) ? $v['json'] : $v;
    };

    $containerKeys = array_merge($containerKeys, ['data', 'results', 'rows', 'items']);

    if (is_array($json)) {
        foreach ($containerKeys as $key) {
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
        foreach ($idKeys as $k) {
            if (isset($json[$k])) {
                return [$json];
            }
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

/** Première valeur non vide parmi plusieurs clés candidates. */
function pick(array $row, array $keys, $default = null)
{
    foreach ($keys as $k) {
        if (!array_key_exists($k, $row)) {
            continue;
        }
        $v = $row[$k];
        if ($v === null) {
            continue;
        }
        // Une colonne JSON/JSONB revient en tableau depuis n8n. Le cast (string)
        // déclencherait « Array to string conversion » — un simple warning, mais
        // le set_error_handler du fichier le transforme en ErrorException, donc
        // en erreur 502 pour toute la requête. On ignore la clé à la place.
        if (is_array($v) || is_object($v) || is_resource($v)) {
            continue;
        }
        if (trim((string)$v) === '') {
            continue;
        }
        return $v;
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
        $n = (int)$value;
        if ($n > 100000000000) { // millisecondes → secondes
            $n = (int)($n / 1000);
        }
        return $n > 0 ? $n : null;
    }
    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }
    // Retire un éventuel suffixe de zone entre crochets que ni DateTime ni
    // strtotime ne savent lire : "...+02:00[Europe/Paris]", "...226[UTC]".
    $s = (string)preg_replace('/\[[^\]]*\]\s*$/', '', $s);
    try {
        // DateTimeImmutable gère millisecondes (.226), offset (+02:00) et « Z ».
        return (new DateTimeImmutable($s))->getTimestamp();
    } catch (Throwable $e) {
        $ts = strtotime($s);
        return $ts === false ? null : $ts;
    }
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

// Chaînes multioctets (repli si mbstring absent) ──────────────────────────────
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

// ══════════════════════════════════════════════════════════════════════════════
//  Validation — DOMAINES
// ══════════════════════════════════════════════════════════════════════════════

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

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — DOCUMENTATION
// ══════════════════════════════════════════════════════════════════════════════

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

/** Transforme une date n8n (timestamp s/ms, ISO 8601…) en « d/m/Y ». */
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

/** strpos insensible à la casse et compatible UTF-8. */
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

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — ABONNEMENTS
// ══════════════════════════════════════════════════════════════════════════════

function subscription_frequency_display(?int $start, ?int $end): string
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

function subscription_status_label($status): string
{
    $n = strtolower(trim((string)$status));
    $map = [
        '0' => 'Brouillon', '4' => 'En cours', '5' => 'Fermé',
        'draft' => 'Brouillon', 'pending' => 'En attente',
        'open' => 'En cours', 'running' => 'En cours', 'active' => 'En cours',
        'closed' => 'Fermé', 'cancelled' => 'Résilié', 'canceled' => 'Résilié',
        'expired' => 'Expiré', 'suspended' => 'Suspendu',
        'completed' => 'Terminé',
    ];
    return $map[$n] ?? ($n !== '' ? ucfirst($n) : 'Inconnu');
}

function subscription_status_class($status): string
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

    $freqExplicit = pick($row, ['frequency', 'periodicity', 'frequence']);
    $frequency = ($freqExplicit !== null && $freqExplicit !== '')
        ? (string)$freqExplicit
        : subscription_frequency_display($startTs, $endTs);

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
        'status_label' => subscription_status_label($statusRaw),
        'status_class' => subscription_status_class($statusRaw),
    ];
}

/**
 * Abonnement Mollie (GET /v2/customers/{cst}/subscriptions) → même forme que
 * normalize_subscription(), pour que pages/abonnements.php n'ait rien à changer.
 *
 * Champs Mollie : id (sub_…), status (pending|active|canceled|suspended|completed),
 * amount {value:"10.00", currency:"EUR"}, interval ("1 month"), times,
 * timesRemaining, startDate / nextPaymentDate (AAAA-MM-JJ), description, createdAt.
 */
function normalize_mollie_subscription(array $row): array
{
    $id     = (string)($row['id'] ?? '');
    $status = strtolower((string)($row['status'] ?? ''));

    $startTs = to_timestamp($row['startDate'] ?? ($row['createdAt'] ?? null));
    // Mollie ne renvoie nextPaymentDate que pour un abonnement actif.
    $nextTs  = in_array($status, ['active', 'pending'], true)
        ? to_timestamp($row['nextPaymentDate'] ?? null)
        : null;

    $amount   = is_array($row['amount'] ?? null) ? $row['amount'] : [];
    $value    = $amount['value'] ?? null;
    $currency = strtoupper((string)($amount['currency'] ?? 'EUR'));
    $amountTxt = amount_display($value);
    if ($currency !== 'EUR' && $amountTxt !== '—') {
        $amountTxt = number_format((float)$value, 2, ',', ' ') . ' ' . $currency;
    }

    $frequency = mollieIntervalLabel((string)($row['interval'] ?? ''));
    $times     = isset($row['times']) && is_numeric($row['times']) ? (int)$row['times'] : 0;
    if ($times > 0) {
        $frequency .= ' · ' . $times . ' échéance' . ($times > 1 ? 's' : '');
    }

    $label = trim((string)($row['description'] ?? ''));

    return [
        'id'           => $id,
        'ref'          => $id,
        'label'        => $label !== '' ? $label : '—',
        'start'        => date_display($startTs),
        'start_ts'     => $startTs,
        'end'          => date_display($nextTs),
        'end_ts'       => $nextTs,
        'frequency'    => $frequency,
        'amount'       => $amountTxt,
        'amount_raw'   => is_numeric($value) ? (float)$value : null,
        'currency'     => $currency,
        'status'       => $status,
        'status_label' => subscription_status_label($status),
        'status_class' => subscription_status_class($status),
        'source'       => 'mollie',
    ];
}

/**
 * Client Mollie de l'utilisateur courant, ou réponse JSON directe :
 *   - Keycloak injoignable        → 502 ;
 *   - pas d'attribut moliecliid    → 200 { ok, linked:false, subscriptions:[] }.
 */
function mollie_customer_or_exit(array $user): string
{
    require_once __DIR__ . '/../include/mollie_client.php';

    $c = mollieCustomerIdForSessionUser($user);
    if ($c['id'] === '') {
        if ($c['error'] !== '') {
            send_json(502, ['ok' => false, 'error' => $c['error'], 'code' => 'KEYCLOAK']);
        }
        send_json(200, ['ok' => true, 'linked' => false, 'count' => 0, 'subscriptions' => []]);
    }
    if (!mollieConfigured()) {
        send_json(503, [
            'ok'    => false,
            'error' => 'Mollie n\'est pas configuré (MOLLIE_API_KEY absente du Secret du portail).',
            'code'  => 'MOLLIE',
        ]);
    }
    return $c['id'];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — FACTURES
// ══════════════════════════════════════════════════════════════════════════════

function invoice_status_label($status): string
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

function invoice_status_class($status): string
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

function normalize_invoice(array $row): array
{
    $id  = pick($row, ['id', 'rowid', 'invoice_id'], 0);
    $id  = is_numeric($id) ? (int)$id : 0;
    $ref = (string)pick($row, ['ref', 'reference', 'number', 'invoice_number'], 'FAC-' . $id);

    $dateTs = to_timestamp(pick($row, ['date', 'datef', 'date_valid', 'date_creation', 'invoice_date', 'issued_at']));
    $dueTs  = to_timestamp(pick($row, ['due', 'date_lim_reglement', 'date_echeance', 'date_due', 'due_date']));

    $totalHt   = pick($row, ['total_ht', 'amount_ht', 'ht']);
    $totalTtc  = pick($row, ['total_ttc', 'amount_ttc', 'ttc', 'amount', 'total']);
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
        'status_label'   => invoice_status_label($statusRaw),
        'status_class'   => invoice_status_class($statusRaw),
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

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — COMMANDES
// ══════════════════════════════════════════════════════════════════════════════

function order_status_label($status): string
{
    $n = strtolower(trim((string)$status));
    $map = [
        '-1' => 'Annulée', '0' => 'Brouillon', '1' => 'Validée',
        '2' => 'En cours', '3' => 'Livrée',
        'draft' => 'Brouillon', 'validated' => 'Validée',
        'processing' => 'En cours', 'shipped' => 'Expédiée',
        'delivered' => 'Livrée', 'closed' => 'Classée',
        'cancelled' => 'Annulée', 'canceled' => 'Annulée',
        // États Mollie (nouveau format n8n).
        'open' => 'En attente', 'pending' => 'En attente',
        'authorized' => 'Autorisée', 'paid' => 'Payée',
        'active' => 'Active', 'suspended' => 'Suspendue',
        'completed' => 'Terminée', 'failed' => 'Échouée',
        'expired' => 'Expirée', 'chargeback' => 'Rejetée',
    ];
    return $map[$n] ?? ($n !== '' ? ucfirst($n) : 'Inconnu');
}

function order_status_class($status): string
{
    $n = strtolower(trim((string)$status));
    if (in_array($n, ['3', 'delivered', 'closed', 'shipped', 'paid', 'active', 'completed'], true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }
    if (in_array($n, ['-1', 'cancelled', 'canceled', 'failed', 'expired', 'chargeback'], true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    if (in_array($n, ['1', 'validated', 'authorized'], true)) {
        return 'bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300';
    }
    return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
}

/**
 * Périodicité lisible d'une commande.
 * Source : « frequence » (annuel/mensuel…) puis repli sur « interval_months »
 * ou « mollie_interval » ("12 months"). Chaîne vide = paiement unique.
 */
function order_frequency_label($frequence, $intervalMonths = null, $mollieInterval = null): string
{
    $f = s_lower(trim((string)$frequence));
    $map = [
        'annuel' => 'Annuel', 'annuelle' => 'Annuel', 'annual' => 'Annuel', 'yearly' => 'Annuel',
        'mensuel' => 'Mensuel', 'mensuelle' => 'Mensuel', 'monthly' => 'Mensuel',
        'trimestriel' => 'Trimestriel', 'quarterly' => 'Trimestriel',
        'semestriel' => 'Semestriel', 'biannual' => 'Semestriel',
        'hebdomadaire' => 'Hebdomadaire', 'weekly' => 'Hebdomadaire',
        'ponctuel' => 'Paiement unique', 'once' => 'Paiement unique', 'unique' => 'Paiement unique',
    ];
    if ($f !== '' && isset($map[$f])) {
        return $map[$f];
    }

    // Repli : nombre de mois, en clair ou extrait de « 12 months ».
    $months = null;
    if (is_numeric($intervalMonths)) {
        $months = (int)$intervalMonths;
    } elseif (preg_match('/(\d+)\s*month/i', (string)$mollieInterval, $m)) {
        $months = (int)$m[1];
    }
    if ($months !== null && $months > 0) {
        $byMonths = [1 => 'Mensuel', 3 => 'Trimestriel', 6 => 'Semestriel', 12 => 'Annuel'];
        return $byMonths[$months] ?? ('Tous les ' . $months . ' mois');
    }

    return $f !== '' ? s_upper(s_sub($f, 0, 1)) . s_sub($f, 1) : 'Paiement unique';
}

function normalize_order(array $row): array
{
    $id  = pick($row, ['id', 'rowid', 'order_id'], 0);
    $id  = is_numeric($id) ? (int)$id : 0;
    $ref = (string)pick($row, ['ref', 'reference', 'number', 'order_number'], 'CMD-' . $id);

    // « createdAt » = nouveau format n8n (abonnements Mollie) ; les autres clés
    // restent pour l'ancien format Dolibarr.
    $dateTs = to_timestamp(pick($row, [
        'date', 'datef', 'date_commande', 'date_creation', 'order_date',
        'created_at', 'createdAt',
    ]));

    // Montant affiché = premier paiement. « total_autres_mois » décrit les
    // échéances suivantes d'un abonnement : exposé à part, jamais additionné.
    // Ancien format : on privilégie le TTC, plus parlant pour un client.
    $amount     = pick($row, ['total_premier', 'total_ttc', 'amount_ttc', 'ttc', 'amount', 'total', 'total_ht', 'amount_ht', 'ht']);
    $amountNext = pick($row, ['total_autres_mois', 'total_suivant', 'amount_next']);

    // Conservées pour les consommateurs de l'ancien format (order.detail, exports).
    $totalHt  = pick($row, ['total_ht', 'amount_ht', 'ht', 'total_premier']);
    $totalTtc = pick($row, ['total_ttc', 'amount_ttc', 'ttc', 'amount', 'total', 'total_premier']);

    $statusRaw = (string)pick($row, ['status', 'statut', 'fk_statut', 'state'], '');

    // Demandeur = prénom + nom de la personne à l'origine de la commande.
    // Repli sur un champ « nom complet » déjà assemblé, puis sur l'e-mail.
    $prenom = trim((string)pick($row, ['order_prenom', 'prenom', 'firstname', 'first_name'], ''));
    $nom    = trim((string)pick($row, ['order_nom', 'nom', 'lastname', 'last_name'], ''));
    $requester = trim($prenom . ' ' . $nom);
    if ($requester === '') {
        $requester = trim((string)pick($row, [
            'order_demandeur', 'demandeur', 'fullname', 'full_name', 'name',
            'order_client_email', 'email',
        ], ''));
    }

    $frequence      = pick($row, ['frequence', 'frequency', 'periodicite'], '');
    $intervalMonths = pick($row, ['interval_months', 'intervalMonths']);
    $mollieInterval = pick($row, ['mollie_interval', 'mollieInterval'], '');
    $renewalTs      = to_timestamp(pick($row, ['next_renewal', 'nextRenewal', 'next_payment_date']));

    return [
        'id'               => $id,
        'ref'              => $ref,
        'requester'        => $requester !== '' ? $requester : '—',
        'requester_first'  => $prenom,
        'requester_last'   => $nom,
        'date'             => date_display($dateTs),
        'date_ts'          => $dateTs,
        'status'           => $statusRaw,
        'status_label'     => order_status_label($statusRaw),
        'status_class'     => order_status_class($statusRaw),

        // Montant unique affiché dans le tableau.
        'amount'           => amount_display($amount),
        'amount_raw'       => is_numeric($amount) ? (float)$amount : null,
        'amount_next'      => amount_display($amountNext),
        'amount_next_raw'  => is_numeric($amountNext) ? (float)$amountNext : null,

        // Abonnement.
        'frequency'        => (string)$frequence,
        'frequency_label'  => order_frequency_label($frequence, $intervalMonths, $mollieInterval),
        'interval_months'  => is_numeric($intervalMonths) ? (int)$intervalMonths : null,
        'next_renewal'     => date_display($renewalTs),
        'next_renewal_ts'  => $renewalTs,

        // Rétrocompatibilité ancien format.
        'total_ht'         => amount_display($totalHt),
        'total_ht_raw'     => is_numeric($totalHt) ? (float)$totalHt : null,
        'total_ttc'        => amount_display($totalTtc),
        'total_ttc_raw'    => is_numeric($totalTtc) ? (float)$totalTtc : null,
    ];
}

// ── Détail d'une commande : produits (order_product) + options (order_option) ──
//
//  Modèle de données n8n :
//    order_product : { ref, slug, uid, prix, quantite }
//    order_option  : { ref, produit, option_slug, prix, prix_unique, product_uid }
//  Le rattachement se fait sur order_option.product_uid = order_product.uid,
//  JAMAIS sur le slug : une même commande peut contenir deux exemplaires du même
//  produit (uid différents) dont un seul porte des options.
//
//  Les prix sont ceux d'UNE période ; le total de la commande vaut
//  (récurrent × interval_months) + frais uniques (options prix_unique = true).

/** « dev_expert » → « Dev expert ». Repli quand n8n n'envoie pas de libellé. */
function label_from_slug($slug): string
{
    $s = trim((string)$slug);
    if ($s === '') {
        return '';
    }
    $s = (string)preg_replace('/[\s_\-.]+/', ' ', $s);
    return s_upper(s_sub($s, 0, 1)) . s_sub($s, 1);
}

function normalize_order_option(array $row): array
{
    $prix = pick($row, ['prix', 'price', 'montant', 'amount'], 0);
    $slug = (string)pick($row, ['option_slug', 'slug', 'option', 'code'], '');

    return [
        'uid'          => (string)pick($row, ['uid', 'option_uid'], ''),
        'product_uid'  => (string)pick($row, ['product_uid', 'produit_uid', 'item_uid', 'parent_uid'], ''),
        'product_slug' => (string)pick($row, ['produit', 'product', 'product_slug'], ''),
        'slug'         => $slug,
        'label'        => (string)pick($row, ['label', 'libelle', 'nom', 'name'], label_from_slug($slug)),
        'price'        => amount_display($prix),
        'price_raw'    => is_numeric($prix) ? (float)$prix : 0.0,
        'one_off'      => truthy(pick($row, ['prix_unique', 'one_off', 'once', 'unique'], false)),
    ];
}

function normalize_order_product(array $row, array $options = []): array
{
    $slug = (string)pick($row, ['slug', 'produit', 'product', 'code'], '');
    $prix = pick($row, ['prix', 'price', 'unit_price', 'montant'], 0);
    $qte  = pick($row, ['quantite', 'quantity', 'qty', 'nb'], 1);

    $unit = is_numeric($prix) ? (float)$prix : 0.0;
    $qte  = is_numeric($qte) ? max(1, (int)$qte) : 1;

    $optRecurring = 0.0;
    $optOneOff    = 0.0;
    foreach ($options as $o) {
        if (!empty($o['one_off'])) {
            $optOneOff += (float)$o['price_raw'];
        } else {
            $optRecurring += (float)$o['price_raw'];
        }
    }

    // Une option est attachée à UN exemplaire (product_uid) : comptée une fois
    // par ligne, pas multipliée par la quantité.
    $lineRecurring = ($unit * $qte) + $optRecurring;

    return [
        'uid'                => (string)pick($row, ['uid', 'product_uid', 'item_uid'], ''),
        'slug'               => $slug,
        'label'              => (string)pick($row, ['label', 'libelle', 'nom', 'name'], label_from_slug($slug)),
        'quantity'           => $qte,
        'unit_price'         => amount_display($unit),
        'unit_price_raw'     => $unit,
        'options'            => array_values($options),
        'options_count'      => count($options),
        'options_total'      => amount_display($optRecurring + $optOneOff),
        'options_total_raw'  => $optRecurring + $optOneOff,
        'line_total'         => amount_display($lineRecurring + $optOneOff),
        'line_total_raw'     => $lineRecurring + $optOneOff,
        'line_recurring_raw' => $lineRecurring,
        'line_one_off_raw'   => $optOneOff,
    ];
}

/**
 * Normalise une ligne de la table « product » (catalogue), telle que renvoyée
 * par l'action n8n « product.list ».
 *
 * « esp_cli_menu_name » est la colonne qui range le produit dans un dépliant de
 * la barre latérale : web | cloud | other | vm | bm (cf. include/menu.php).
 */
function normalize_catalog_product(array $row): array
{
    $slug = (string)pick($row, ['slug', 'code', 'product_slug'], '');
    $prix = pick($row, ['prix_mensuel', 'prix', 'price'], null);

    return [
        'id'            => (int)pick($row, ['id', 'rowid'], 0),
        'slug'          => $slug,
        'name'          => (string)pick($row, ['name', 'nom', 'label', 'libelle'], label_from_slug($slug)),
        'subtitle'      => (string)pick($row, ['stitre', 'subtitle'], ''),
        'type'          => (string)pick($row, ['type'], ''),
        'menu'          => s_lower((string)pick($row, ['esp_cli_menu_name', 'menu', 'menu_name'], '')),
        'datacenter'    => (string)pick($row, ['datacenter_flag', 'datacenter'], ''),
        'categorie_id'  => (int)pick($row, ['categorie_id', 'category_id'], 0),
        'provider_type' => (string)pick($row, ['provider_type'], ''),
        'store_showable' => truthy(pick($row, ['store_showable'], false)),
        'price_monthly' => is_numeric($prix) ? (float)$prix : null,
    ];
}

/**
 * Un appel n8n de lignes de commande, en échec doux : le panneau de détail doit
 * pouvoir s'afficher même si l'une des deux requêtes tombe, plutôt que de faire
 * échouer toute la commande.
 */
function order_rows(
    string $action,
    $clientId,
    $id,
    $ref,
    array $containerKeys,
    array $idKeys,
    ?string &$warning = null
): array {
    // Pas de type string sur ces trois paramètres : le routeur travaille avec
    // $clientId = (int)($user['id'] ?? 0), et le fichier est en strict_types=1,
    // donc un typage strict ferait échouer l'appel par TypeError. On normalise
    // ici plutôt que d'imposer une contrainte au reste du fichier.
    $clientId = (string)$clientId;
    $id       = (string)$id;
    $ref      = (string)$ref;

    try {
        $resp = n8n_call([
            'action'    => $action,
            'client_id' => $clientId,
            'id'        => $id,
            'ref'       => $ref,
        ]);
        if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
            $warning = $action . ' : HTTP ' . $resp['status'];
            return [];
        }

        $rows = extract_rows($resp['json'], $containerKeys, $idKeys);

        // Zéro ligne extraite peut vouloir dire deux choses très différentes :
        // « cette commande n'a pas de ligne » (tableau vide, légitime) ou
        // « n8n a répondu autre chose qu'une liste » (contrat rompu). Sans cette
        // distinction, un workflow qui renvoie true, null ou {success:…} produit
        // un panneau vide silencieux, impossible à diagnostiquer.
        if (!$rows) {
            $body = $resp['json'];
            $legitEmpty = ($body === null) || (is_array($body) && $body === []);
            if (!$legitEmpty) {
                $dump = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($dump === false || $dump === null) {
                    $dump = (string)$resp['raw'];
                }
                $warning = $action . ' : réponse inattendue de n8n — ' . s_sub($dump, 0, 200);
            }
        }

        return $rows;
    } catch (Throwable $e) {
        $warning = $action . ' : ' . $e->getMessage();
        return [];
    }
}

/** Table order_product (action n8n « order.product »). */
function order_product_rows($clientId, $id, $ref, ?string &$warning = null): array
{
    return order_rows(
        'order.product', $clientId, $id, $ref,
        ['order_product', 'products', 'produits', 'lignes', 'lines'],
        ['uid', 'slug'],
        $warning
    );
}

/** Table order_option (action n8n « order.product.option »). */
function order_option_rows($clientId, $id, $ref, ?string &$warning = null): array
{
    return order_rows(
        'order.product.option', $clientId, $id, $ref,
        ['order_option', 'order_product_option', 'options'],
        ['uid', 'option_slug'],
        $warning
    );
}

/**
 * Assemble produits + options en lignes prêtes à afficher, et calcule les totaux.
 *
 * @return array{products:array, extra_options:array, totals:array}
 */
function build_order_lines(array $productRows, array $optionRows, string $ref = ''): array
{
    $sameRef = static function ($r) use ($ref): bool {
        if (!is_array($r)) {
            return false;
        }
        if ($ref === '') {
            return true;
        }
        $rr = trim((string)($r['ref'] ?? $r['reference'] ?? ''));
        return $rr === '' || $rr === $ref; // ref absente = on garde (n8n filtre déjà)
    };

    // Options indexées par product_uid ; celles sans uid le sont par slug produit.
    $byUid  = [];
    $bySlug = [];
    foreach ($optionRows as $raw) {
        if (!$sameRef($raw)) {
            continue;
        }
        $o = normalize_order_option($raw);
        if ($o['product_uid'] !== '') {
            $byUid[$o['product_uid']][] = $o;
        } elseif ($o['product_slug'] !== '') {
            $bySlug[$o['product_slug']][] = $o;
        } else {
            $byUid[''][] = $o;
        }
    }

    $products = [];
    foreach ($productRows as $raw) {
        if (!$sameRef($raw)) {
            continue;
        }
        $uid  = (string)pick($raw, ['uid', 'product_uid', 'item_uid'], '');
        $slug = (string)pick($raw, ['slug', 'produit', 'product'], '');

        $opts = [];
        if ($uid !== '' && isset($byUid[$uid])) {
            $opts = $byUid[$uid];
            unset($byUid[$uid]);
        } elseif ($slug !== '' && isset($bySlug[$slug])) {
            // Repli : option sans product_uid → premier produit du même slug.
            $opts = $bySlug[$slug];
            unset($bySlug[$slug]);
        }

        $products[] = normalize_order_product($raw, $opts);
    }

    // Options dont le produit est absent de la réponse : jamais perdues.
    $orphans = [];
    foreach ($byUid as $list) {
        foreach ($list as $o) { $orphans[] = $o; }
    }
    foreach ($bySlug as $list) {
        foreach ($list as $o) { $orphans[] = $o; }
    }

    $recurring = 0.0;
    $oneOff    = 0.0;
    foreach ($products as $p) {
        $recurring += (float)$p['line_recurring_raw'];
        $oneOff    += (float)$p['line_one_off_raw'];
    }
    foreach ($orphans as $o) {
        if (!empty($o['one_off'])) { $oneOff += (float)$o['price_raw']; }
        else                       { $recurring += (float)$o['price_raw']; }
    }

    return [
        'products'      => $products,
        'extra_options' => $orphans,
        'totals'        => [
            'products_count' => count($products),
            'recurring'      => amount_display($recurring),
            'recurring_raw'  => $recurring,
            'one_off'        => amount_display($oneOff),
            'one_off_raw'    => $oneOff,
        ],
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — ÉQUIPES
// ══════════════════════════════════════════════════════════════════════════════

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

function team_status_class(string $status): string
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
        'status_class' => team_status_class($statusLabel),
        'active'       => $active,
        'perm_id'      => $permId,
        'permission'   => permission_label($permId),
        'structure'    => trim((string)(pick($row, ['structure', 'company', 'socname', 'raison']) ?? '')),
    ];
}

/**
 * Membre d'une ORGANISATION Keycloak (MemberRepresentation de l'Admin REST)
 * → même forme de sortie que normalize_member(), attendue par pages/equipes.php.
 *
 * Différences assumées avec la version n8n :
 *   - « id » est l'UUID Keycloak (chaîne), pas un entier de table ;
 *   - « statut » vient de user.enabled ;
 *   - « fonction » et « permission » viennent des ATTRIBUTS utilisateur
 *     Keycloak ; à défaut, la permission retombe sur le type d'adhésion
 *     (MANAGED = compte géré par l'organisation, UNMANAGED = invité externe).
 */
function normalize_kc_member(array $m, string $structure): array
{
    $attrs = [];
    if (isset($m['attributes']) && is_array($m['attributes'])) {
        foreach ($m['attributes'] as $k => $v) {
            if (is_array($v)) {
                $attrs[(string)$k] = (isset($v[0]) && is_scalar($v[0])) ? trim((string)$v[0]) : '';
            } elseif (is_scalar($v)) {
                $attrs[(string)$k] = trim((string)$v);
            }
        }
    }
    $attr = static function (array $keys) use ($attrs): string {
        foreach ($keys as $k) {
            if (isset($attrs[$k]) && $attrs[$k] !== '') {
                return $attrs[$k];
            }
        }
        return '';
    };

    $firstName = trim((string)($m['firstName'] ?? ''));
    $lastName  = trim((string)($m['lastName'] ?? ''));
    $email     = trim((string)($m['email'] ?? ''));
    $username  = trim((string)($m['username'] ?? ''));

    $name = trim($firstName . ' ' . $lastName);
    if ($name === '') {
        $name = $username !== '' ? $username : ($email !== '' ? $email : 'Utilisateur');
    }

    $initials = s_upper(
        ($firstName !== '' ? s_sub($firstName, 0, 1) : '')
        . ($lastName !== '' ? s_sub($lastName, 0, 1) : '')
    );
    if ($initials === '') {
        $base = $username !== '' ? $username : $email;
        $initials = $base !== '' ? s_upper(s_sub($base, 0, 2)) : '#';
    }

    // Statut : compte activé/désactivé dans Keycloak.
    $enabled     = !array_key_exists('enabled', $m) || (bool)$m['enabled'];
    $statusLabel = $enabled ? 'Actif' : 'Inactif';

    $function = $attr(['fonction', 'poste', 'job', 'job_title', 'jobTitle', 'function', 'title']);

    // Permission : attribut explicite, sinon type d'adhésion à l'organisation.
    $permRaw = $attr(['perm_id', 'permission', 'role_id']);
    $permId  = null;
    if ($permRaw !== '' && is_numeric($permRaw)) {
        $permId     = (int)$permRaw;
        $permission = permission_label($permId);
    } elseif ($permRaw !== '') {
        $permission = $permRaw;
    } else {
        $permission = (s_upper(trim((string)($m['membershipType'] ?? ''))) === 'UNMANAGED')
            ? 'Invité externe'
            : "Membre de l'organisation";
    }

    return [
        'id'           => trim((string)($m['id'] ?? '')),
        'name'         => $name,
        'secondary'    => $email !== '' ? $email : ($username !== '' ? $username : 'Compte Keycloak'),
        'initials'     => $initials,
        'function'     => $function !== '' ? $function : 'Aucune fonction définie',
        'fonction'     => $function,
        'email'        => $email,
        'username'     => $username,
        'status_label' => $statusLabel,
        'status_class' => team_status_class($statusLabel),
        'active'       => $enabled ? 1 : 0,
        'perm_id'      => $permId,
        'permission'   => $permission,
        'structure'    => $structure,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
//  ÉQUIPES — services, fonctions et droits (groupes d'organisation Keycloak)
//  Modèle et règles : include/org_permissions.php. Ces helpers ne sont appelés
//  qu'après require_once de ce fichier (actions team.*).
// ══════════════════════════════════════════════════════════════════════════════

/** Contexte org/arbre/droits, ou réponse d'erreur (HTTP 200 + ok:false). */
function team_context_or_fail(array $user): array
{
    require_once __DIR__ . '/../include/org_permissions.php';
    $ctx = orgTeamContext($user);
    if (!$ctx['ok']) {
        send_json(200, ['ok' => false, 'code' => 502, 'error' => $ctx['error'] !== '' ? $ctx['error'] : 'Organisation Keycloak introuvable.']);
    }
    if (!$ctx['groups_supported']) {
        send_json(200, ['ok' => false, 'code' => 501, 'error' => "Les groupes d'organisation nécessitent Keycloak 26.6 ou plus récent."]);
    }
    return $ctx;
}

function team_actor_can(array $ctx, string $key): bool
{
    return is_array($ctx['actor'] ?? null) && orgPermHas($ctx['actor']['perms'], $key);
}

function team_require(array $ctx, string $key, string $message): void
{
    if (!team_actor_can($ctx, $key)) {
        send_json(403, ['ok' => false, 'error' => $message]);
    }
}

/** Refuse toute élévation : $needed doit être couvert par les droits de l'acteur. */
function team_require_covers(array $ctx, array $needed, string $message = ''): void
{
    if (!orgPermCovers($ctx['actor']['perms'], $needed)) {
        $missing = [];
        foreach (orgPermExpand($needed) as $k) {
            if ($k === '*' ? !in_array('*', $ctx['actor']['perms'], true) : !orgPermHas($ctx['actor']['perms'], $k)) {
                $missing[] = $k;
            }
        }
        send_json(403, [
            'ok'      => false,
            'error'   => $message !== '' ? $message : 'Vous ne pouvez accorder que des droits que vous détenez vous-même : ' . implode(', ', orgPermLabels($missing)) . '.',
            'missing' => $missing,
        ]);
    }
}

/**
 * Garde-fou « dernier gestionnaire » : si l'équipe a au moins un gestionnaire
 * (teams.manage), l'opération simulée ne doit pas en laisser zéro — sinon la
 * gestion basculerait en mode initialisation, ouverte à tous les membres.
 */
function team_guard_managers(array $ctx, array $newIndex, array $newMemberships, string $message): void
{
    if ($ctx['managers'] === []) return;
    if (orgManagers($newIndex, $newMemberships) === []) {
        send_json(409, ['ok' => false, 'error' => $message]);
    }
}

function team_group_or_fail(array $ctx, string $groupId): array
{
    if ($groupId === '' || !isset($ctx['index'][$groupId])) {
        send_json(404, ['ok' => false, 'error' => 'Service ou fonction introuvable dans votre organisation.']);
    }
    return $ctx['index'][$groupId];
}

/** Nom de service/fonction : 1–60 caractères, sans « / » (séparateur de chemin Keycloak). */
function team_clean_group_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') {
        send_json(400, ['ok' => false, 'error' => 'Le nom est obligatoire.']);
    }
    if (s_len($name) > 60) {
        send_json(400, ['ok' => false, 'error' => 'Le nom ne doit pas dépasser 60 caractères.']);
    }
    if (strpbrk($name, "/\\") !== false || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        send_json(400, ['ok' => false, 'error' => 'Le nom ne peut pas contenir « / » ni « \\ ».']);
    }
    return $name;
}

/** Droits envoyés par le formulaire : perm[]=… ou perm="a,b". */
function team_perm_from_post(): array
{
    $raw = $_POST['perm'] ?? [];
    return orgPermParse(is_array($raw) ? $raw : [(string)$raw]);
}

function team_find_global(array $ctx): string
{
    foreach ($ctx['index'] as $g) {
        if ($g['is_service'] && $g['is_global']) return $g['id'];
    }
    return '';
}

/** Id du service « Global », créé au besoin (et ajouté à $ctx['index']). */
function team_ensure_global_service(array &$ctx): string
{
    $id = team_find_global($ctx);
    if ($id !== '') return $id;

    $res = kcOrgGroupCreate((string)$ctx['org']['id'], ORG_GLOBAL_SERVICE, '', []);
    if (!$res['ok'] || $res['id'] === '') {
        send_json(200, ['ok' => false, 'code' => 502, 'error' => 'Création du service « ' . ORG_GLOBAL_SERVICE . ' » impossible : ' . ($res['error'] ?: 'identifiant non renvoyé.')]);
    }
    $groups   = array_values($ctx['index']);
    $groups[] = ['id' => $res['id'], 'name' => ORG_GLOBAL_SERVICE, 'parent_id' => '', 'attributes' => []];
    $ctx['index'] = orgGroupsIndex($groups);
    return $res['id'];
}

/** Longueur multioctet (repli si mbstring absent). */
function s_len(string $v): int
{
    return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
}

/** Arbre pour la page : nœuds sans attributs bruts + nombre de membres directs. */
function team_groups_payload(array $ctx): array
{
    $counts = [];
    foreach ($ctx['memberships'] as $gids) {
        foreach ($gids as $gid) $counts[$gid] = ($counts[$gid] ?? 0) + 1;
    }
    $out = [];
    foreach ($ctx['index'] as $g) {
        $out[] = [
            'id'           => $g['id'],
            'name'         => $g['name'],
            'parent_id'    => $g['parent_id'],
            'depth'        => $g['depth'],
            'path'         => $g['path'],
            'service'      => $g['service'],
            'service_id'   => $g['service_id'],
            'is_service'   => $g['is_service'],
            'is_global'    => $g['is_global'],
            'label'        => $g['label'],
            'perm'         => $g['perm'],
            'effective'    => $g['effective'],
            'member_count' => $counts[$g['id']] ?? 0,
            // L'acteur peut-il modifier / attribuer ce groupe ? (affichage seulement ;
            // le serveur revérifie à chaque action)
            'editable'     => orgPermCovers($ctx['actor']['perms'], $g['effective']),
        ];
    }
    return $out;
}

function team_catalog_payload(): array
{
    $out = [];
    foreach (orgPermCatalog() as $key => $def) {
        $out[] = ['key' => $key, 'label' => $def['label'], 'group' => $def['group']];
    }
    return $out;
}

function team_me_payload(array $ctx): array
{
    $a = $ctx['actor'];
    return [
        'id'         => $a['uid'],
        'perms'      => $a['perms'],
        'labels'     => orgPermLabels($a['perms']),
        'can_manage' => $ctx['groups_supported'] && orgPermHas($a['perms'], 'teams.manage'),
        'can_assign' => $ctx['groups_supported'] && orgPermHas($a['perms'], 'teams.assign'),
        'bootstrap'  => (bool)$a['bootstrap'],
        'functions'  => array_values(array_map(static function ($gid) use ($ctx) {
            return $ctx['index'][$gid]['label'] ?? $gid;
        }, $a['groups'])),
    ];
}

/**
 * Ajoute à un membre normalisé ses fonctions (groupes d'organisation) et ses
 * droits effectifs. Sans fonction, on garde l'attribut utilisateur « fonction »
 * historique et la permission calculée par normalize_kc_member().
 */
function team_attach_functions(array $member, array $ctx): array
{
    $gids  = $ctx['memberships'][$member['id']] ?? [];
    $funcs = [];
    foreach ($gids as $gid) {
        if (!isset($ctx['index'][$gid])) continue;
        $g = $ctx['index'][$gid];
        $funcs[] = [
            'id'         => $g['id'],
            'label'      => $g['label'],
            'name'       => $g['name'],
            'service'    => $g['service'],
            'service_id' => $g['service_id'],
            'is_global'  => $g['is_global'],
            'is_service' => $g['is_service'],
            'path'       => implode(' / ', $g['path']),
        ];
    }
    usort($funcs, static function ($a, $b) { return strcmp(s_lower($a['label']), s_lower($b['label'])); });

    $perms = orgPermsForGroups($ctx['index'], $gids);
    $member['functions']   = $funcs;
    $member['perms']       = $perms;
    $member['perm_labels'] = orgPermLabels($perms);
    $member['legacy_function'] = $member['fonction'];

    if ($funcs !== []) {
        $member['function'] = implode(', ', array_map(static function ($f) { return $f['label']; }, $funcs));
        if (in_array('*', $perms, true)) {
            $member['permission'] = 'Administrateur';
        } elseif ($perms !== []) {
            $member['permission'] = count($perms) . ' droit' . (count($perms) > 1 ? 's' : '');
        } else {
            $member['permission'] = 'Aucun droit particulier';
        }
    }
    $member['is_me'] = ($member['id'] !== '' && $member['id'] === ($ctx['actor']['uid'] ?? ''));
    return $member;
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — DÉPLOIEMENTS
// ══════════════════════════════════════════════════════════════════════════════

function normalize_deployment(array $r): ?array
{
    // Table label_portail V2 : la clé de renommage est « product_uid »,
    // c'est-à-dire order_product.uid — un exemplaire acheté, pas un produit du
    // catalogue. « deployment_name » reste accepté EN LECTURE pour les lignes
    // héritées de la V1, mais n'est plus produit.
    $uid  = trim((string)($r['product_uid'] ?? $r['uid'] ?? $r['deployment_name'] ?? $r['name'] ?? ''));
    $disp = trim((string)($r['display_name'] ?? $r['label'] ?? ''));
    if ($uid === '') {
        return null;
    }
    return ['product_uid' => $uid, 'display_name' => $disp];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — NOTIFICATIONS
// ══════════════════════════════════════════════════════════════════════════════

/** Une notification est-elle non lue ? (tolérant aux schémas n8n) */
function notif_is_unread(array $r): bool
{
    // Champs « horodatage de lecture » : présence (non vide) ⇒ lue.
    foreach (['read_at', 'date_lecture', 'seen_at'] as $k) {
        if (array_key_exists($k, $r) && $r[$k] !== null && trim((string)$r[$k]) !== '') {
            return false;
        }
    }
    // Drapeaux booléens : truthy ⇒ lue.
    foreach (['read', 'is_read', 'lu', 'seen', 'vue'] as $k) {
        if (array_key_exists($k, $r) && $r[$k] !== null && $r[$k] !== '') {
            return !truthy($r[$k]);
        }
    }
    // Drapeaux « non lu » explicites : truthy ⇒ non lue.
    foreach (['unread', 'non_lu'] as $k) {
        if (array_key_exists($k, $r)) {
            return truthy($r[$k]);
        }
    }
    return true; // par défaut : considérée non lue
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — TICKETS (support / assistance)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Sélecteur tolérant : insensible à la casse ET aux séparateurs.
 * « created_at », « createdAt », « CreatedAt », « created-at » matchent tous.
 * On tente d'abord la correspondance exacte (rapide), puis la forme normalisée.
 */
function ticket_pick(array $row, array $keys, $default = null)
{
    $hit = pick($row, $keys, $default);
    if ($hit !== $default) {
        return $hit;
    }
    $norm   = static fn($k): string => (string)preg_replace('/[^a-z0-9]/', '', strtolower((string)$k));
    $wanted = array_map($norm, $keys);
    foreach ($row as $k => $v) {
        if ($v === null || trim((string)$v) === '') {
            continue;
        }
        if (in_array($norm($k), $wanted, true)) {
            return $v;
        }
    }
    return $default;
}

/** Clé canonique de statut (utilisée par les onglets/filtres de la page). */
function ticket_status_key($status): string
{
    $n = s_lower(trim((string)$status));
    if (in_array($n, ['closed', 'ferme', 'fermé', 'close', '4'], true)) {
        return 'ferme';
    }
    if (in_array($n, ['resolved', 'resolu', 'résolu', 'done', '3'], true)) {
        return 'resolu';
    }
    if (in_array($n, ['pending', 'en_attente', 'attente', 'waiting', 'on_hold', '2'], true)) {
        return 'en_attente';
    }
    if (in_array($n, ['in_progress', 'en_cours', 'processing', 'progress', '1'], true)) {
        return 'en_cours';
    }
    return 'ouvert';
}

function ticket_status_label($status): string
{
    return [
        'ouvert'     => 'Ouvert',
        'en_cours'   => 'En cours',
        'en_attente' => 'En attente',
        'resolu'     => 'Résolu',
        'ferme'      => 'Fermé',
    ][ticket_status_key($status)] ?? 'Ouvert';
}

function ticket_status_class($status): string
{
    switch (ticket_status_key($status)) {
        case 'resolu':
            return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
        case 'ferme':
            return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
        case 'en_cours':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300';
        case 'en_attente':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
        default: // ouvert
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
    }
}

/** Clé canonique de priorité. */
function ticket_priority_key($priority): string
{
    $n = s_lower(trim((string)$priority));
    if (in_array($n, ['urgent', 'urgente', 'critical', 'critique', '4'], true)) {
        return 'urgente';
    }
    if (in_array($n, ['high', 'haute', 'elevee', 'élevée', '3'], true)) {
        return 'haute';
    }
    if (in_array($n, ['low', 'basse', 'faible', '1'], true)) {
        return 'basse';
    }
    return 'normale';
}

function ticket_priority_label($priority): string
{
    return [
        'basse'   => 'Basse',
        'normale' => 'Normale',
        'haute'   => 'Haute',
        'urgente' => 'Urgente',
    ][ticket_priority_key($priority)] ?? 'Normale';
}

function ticket_priority_class($priority): string
{
    switch (ticket_priority_key($priority)) {
        case 'urgente':
            return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
        case 'haute':
            return 'bg-orange-100 text-orange-700 dark:bg-orange-900/20 dark:text-orange-300';
        case 'basse':
            return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
        default: // normale
            return 'bg-sky-100 text-sky-700 dark:bg-sky-900/20 dark:text-sky-300';
    }
}

function ticket_category_label($category): string
{
    $n = s_lower(trim((string)$category));
    return [
        'technique'   => 'Technique',
        'facturation' => 'Facturation',
        'commercial'  => 'Commercial',
        'compte'      => 'Compte',
        'autre'       => 'Autre',
    ][$n] ?? ($n !== '' ? ucfirst($n) : 'Autre');
}

/**
 * Libellé humain d'une famille de services (colonne product.esp_cli_menu_name).
 * Mêmes clés que servicesCatalogMenus() et que les dépliants « Mes services »
 * de la barre latérale : le ticket parle donc la même langue que le menu.
 */
function ticket_menu_label($menu): string
{
    $n = s_lower(trim((string)$menu));
    return [
        'web'   => 'Services WEB',
        'cloud' => 'Services Cloud',
        'other' => 'Services Spécifiques',
        'vm'    => 'Serveurs Virtualisés',
        'bm'    => 'Serveurs Dédiés',
    ][$n] ?? ($n !== '' ? ucfirst($n) : '');
}

/**
 * Résout des UID Keycloak en identités { name, email }.
 *
 * Le portail n'envoie plus author_name / author_email à la création : la base
 * des tickets ne stocke plus qu'un client_id. C'est ici que ce client_id
 * redevient un nom affichable, à la lecture, en interrogeant Keycloak.
 *
 * Ne lève jamais : Keycloak indisponible ⇒ tableau vide ⇒ l'appelant garde ce
 * que la ligne portait (author_name historique, ou rien). Un nom manquant vaut
 * mieux qu'une page de tickets en erreur.
 *
 * @param  string[] $uids
 * @return array<string, array{name:string,email:string}>
 */
function tickets_resolve_identities(array $uids): array
{
    $wanted = [];
    foreach ($uids as $uid) {
        $uid = trim((string)$uid);
        // Un identifiant local entier n'est pas un UID Keycloak.
        if ($uid !== '' && !ctype_digit($uid)) {
            $wanted[$uid] = true;
        }
    }
    if ($wanted === []) {
        return [];
    }

    try {
        require_once __DIR__ . '/../include/keycloak_organizations.php';
        $resolved = kcOrgUsersByIds(array_keys($wanted));
    } catch (Throwable $e) {
        error_log('[tickets] résolution des auteurs : ' . $e->getMessage());
        return [];
    }

    $out = [];
    foreach ($resolved as $uid => $r) {
        if (!empty($r['ok'])) {
            $out[(string)$uid] = [
                'name'  => (string)($r['name'] ?? ''),
                'email' => (string)($r['email'] ?? ''),
            ];
        }
    }

    return $out;
}

/**
 * Renseigne created_by / author_email de chaque ticket depuis son client_id.
 * Une seule passe de résolution pour toute la liste : les tickets d'un même
 * client partagent le même appel Keycloak (mémorisé en session).
 */
function tickets_attach_authors(array $tickets): array
{
    $ids = [];
    foreach ($tickets as $t) {
        $ids[] = (string)($t['client_id'] ?? '');
    }
    $map = tickets_resolve_identities($ids);
    if ($map === []) {
        return $tickets;
    }

    foreach ($tickets as &$t) {
        $id = (string)($t['client_id'] ?? '');
        if ($id !== '' && isset($map[$id])) {
            $name = $map[$id]['name'];
            if ($name !== '') {
                $t['created_by'] = dot_civility_name($name);
            }
            $t['author_email'] = $map[$id]['email'];
        }
    }
    unset($t);

    return $tickets;
}

/**
 * Même chose pour les messages d'un fil. Les réponses du support portent un
 * agent_id : on résout les deux, sans jamais écraser un nom déjà canonique.
 */
function tickets_attach_message_authors(array $messages): array
{
    $ids = [];
    foreach ($messages as $m) {
        $ids[] = (string)($m['client_id'] ?? '');
        $ids[] = (string)($m['agent_id'] ?? '');
    }
    $map = tickets_resolve_identities($ids);
    if ($map === []) {
        return $messages;
    }

    foreach ($messages as &$m) {
        foreach ([(string)($m['client_id'] ?? ''), (string)($m['agent_id'] ?? '')] as $id) {
            if ($id === '' || !isset($map[$id])) {
                continue;
            }
            if ($map[$id]['name'] !== '') {
                $m['author'] = dot_civility_name($map[$id]['name']);
            }
            $m['author_email'] = $map[$id]['email'];
            break;
        }
    }
    unset($m);

    return $messages;
}

/**
 * Remplace le product_uid de chaque ticket par un libellé affichable.
 *
 * La base ne stocke plus un nom de déploiement mais l'uid de la ligne de
 * commande : stable, valable pour TOUS les fournisseurs, et insensible aux
 * renommages. La contrepartie est qu'un uid ne se lit pas — c'est ici qu'il
 * redevient « Site vitrine » ou « Serveur FiveM ».
 *
 * UNE seule lecture du catalogue pour toute la liste, et JAMAIS de relecture
 * forcée : un uid inconnu (ticket d'un autre client vu depuis la console
 * support, service résilié depuis) doit coûter zéro appel n8n supplémentaire.
 */
function tickets_attach_products(array $tickets, int $accountId): array
{
    $needed = false;
    foreach ($tickets as $t) {
        if (trim((string)($t['product_uid'] ?? '')) !== '') {
            $needed = true;
            break;
        }
    }
    if (!$needed || $accountId <= 0) {
        return $tickets;
    }

    $byUid = [];
    try {
        require_once __DIR__ . '/../include/services_catalog.php';
        $data = servicesCatalogFetch($accountId, false);
        foreach ($data['entries'] as $entry) {
            $uid = trim((string)($entry['uid'] ?? ''));
            if ($uid !== '') {
                $byUid[$uid] = $entry;
            }
        }
    } catch (Throwable $e) {
        error_log('[tickets] catalogue des services : ' . $e->getMessage());
        return $tickets;
    }

    foreach ($tickets as &$t) {
        $uid = trim((string)($t['product_uid'] ?? ''));
        if ($uid === '' || !isset($byUid[$uid])) {
            continue;
        }
        $entry = $byUid[$uid];
        $t['product_name'] = (string)($entry['name'] ?? '');
        // La ligne du ticket fait foi si elle porte déjà une famille ; sinon
        // on prend celle du catalogue.
        if (trim((string)($t['esp_cli_menu_name'] ?? '')) === '') {
            $t['esp_cli_menu_name'] = (string)($entry['menu'] ?? '');
            $t['menu_label']        = ticket_menu_label($entry['menu'] ?? '');
        }
    }
    unset($t);

    return $tickets;
}

/** Formate un instant (timestamp) en fuseau métier (Europe/Paris), date ± heure. */
function ticket_datetime(?int $ts, bool $withTime = true): string
{
    if ($ts === null || $ts <= 0) {
        return '—';
    }
    $tz = defined('PORTAIL_STATS_TZ') ? PORTAIL_STATS_TZ : 'Europe/Paris';
    try {
        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($tz));
    } catch (Throwable $e) {
        $dt = new DateTimeImmutable('@' . $ts);
    }
    return $dt->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
}

/** Normalise un code de civilité en libellé pointé : "m"/"mr"/"monsieur" → "M.". */
function civility_label($code): string
{
    $n = s_lower(trim((string)$code));
    if ($n === '') {
        return '';
    }
    if (in_array($n, ['m', 'mr', 'm.', 'mister', 'monsieur'], true)) {
        return 'M.';
    }
    if (in_array($n, ['mme', 'mrs', 'ms', 'madame'], true)) {
        return 'Mme';
    }
    if (in_array($n, ['mlle', 'miss', 'mademoiselle'], true)) {
        return 'Mlle';
    }
    if (in_array($n, ['dr', 'dr.', 'docteur', 'doctor'], true)) {
        return 'Dr';
    }
    if (in_array($n, ['me', 'maitre', 'maître'], true)) {
        return 'Me';
    }
    return ucfirst($n);
}

/** Nom complet de l'utilisateur connecté, civilité comprise (ex. « M. Gabin Grobost »). */
function user_display_name(array $user): string
{
    $civ    = civility_label(pick($user, ['civilite', 'civility', 'civility_code'], ''));
    $prenom = trim((string)(pick($user, ['prenom', 'firstname', 'first_name']) ?? ''));
    $nom    = trim((string)(pick($user, ['nom', 'lastname', 'last_name']) ?? ''));

    $full = trim(preg_replace('/\s+/', ' ', trim($civ . ' ' . $prenom . ' ' . $nom)));
    if ($full !== '' && ($prenom !== '' || $nom !== '')) {
        return $full;
    }
    $u = trim((string)(pick($user, ['username', 'login', 'email']) ?? ''));
    return $u !== '' ? $u : 'Utilisateur';
}

/** Retire une civilité éventuelle en tête de nom : "M Gabin Grobost" → "Gabin Grobost". */
function strip_civility(string $name): string
{
    return trim((string)preg_replace(
        '/^(M|Mr|Mme|Mlle|Dr|Me|Monsieur|Madame|Mademoiselle|Docteur)\.?\s+/iu',
        '',
        trim($name)
    ));
}

/** Met la civilité de tête au propre : "M Gabin Grobost" → "M. Gabin Grobost". */
function dot_civility_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return $name;
    }
    return (string)preg_replace_callback(
        '/^(M|Mr|Mme|Mlle|Dr|Me|Monsieur|Madame|Mademoiselle|Docteur)\.?\s+/iu',
        static fn($mm) => civility_label($mm[1]) . ' ',
        $name
    );
}

/**
 * Vrai si l'utilisateur connecté appartient à l'équipe support GNL.
 * Critères (au moins un) : rôle/flag de session, domaine e-mail, ou SIRET interne.
 * Fail-closed : tout ce qui n'est pas explicitement support est refusé.
 */
function user_is_support(array $user): bool
{
    if (TICKET_SUPPORT_SITE) {
        return true; // déploiement entièrement dédié au support
    }
    $role = s_lower(trim((string)(pick($user, ['role', 'type', 'profil', 'profile']) ?? '')));
    if (in_array($role, ['support', 'staff', 'agent', 'admin', 'gnl', 'interne'], true)) {
        return true;
    }
    foreach (['is_support', 'is_staff', 'is_admin', 'support', 'staff', 'admin'] as $flag) {
        if (array_key_exists($flag, $user) && truthy($user[$flag])) {
            return true;
        }
    }
    $email = s_lower(trim((string)($user['email'] ?? '')));
    $at    = strrchr($email, '@');
    if ($at !== false) {
        $dom = ltrim($at, '@');
        foreach (TICKET_SUPPORT_EMAIL_DOMAINS as $d) {
            if ($dom !== '' && $dom === s_lower(trim((string)$d))) {
                return true;
            }
        }
    }
    $siret = preg_replace('/\s+/', '', (string)($user['siret'] ?? ''));
    if ($siret !== '') {
        foreach (TICKET_SUPPORT_SIRETS as $s) {
            if ($siret === preg_replace('/\s+/', '', (string)$s)) {
                return true;
            }
        }
    }
    return false;
}

/** Refuse l'accès (403) si l'utilisateur n'est pas support. */
function require_support(array $user): void
{
    if (!user_is_support($user)) {
        send_json(403, ['ok' => false, 'error' => 'Accès réservé à l’équipe support.']);
    }
}

/**
 * Sépare la réponse n8n d'un détail de ticket en [ligne ticket | null, messages normalisés].
 * Gère les trois formes : ligne ticket seule, ticket + "messages", ou tableau de messages.
 */
function extract_ticket_and_messages($json): array
{
    $rows = extract_rows($json, ['tickets', 'messages'], ['id', 'ref', 'reference']);

    $isMessageRow = static function ($r): bool {
        return is_array($r) && (
            array_key_exists('body', $r) || array_key_exists('author_type', $r) ||
            array_key_exists('authorType', $r) || array_key_exists('ticket_id', $r) ||
            array_key_exists('ticketId', $r)
        );
    };
    $isTicketRow = static function ($r): bool {
        return is_array($r) && (
            array_key_exists('subject', $r) || array_key_exists('sujet', $r) ||
            array_key_exists('objet', $r) || array_key_exists('status', $r) ||
            array_key_exists('statut', $r)
        );
    };

    $messageRows = [];
    if (is_array($json) && isset($json['messages']) && is_array($json['messages'])) {
        foreach ($json['messages'] as $m) {
            if (is_array($m)) {
                $messageRows[] = $m;
            }
        }
    }

    $ticketRow = null;
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        if (isset($r['messages']) && is_array($r['messages'])) {
            foreach ($r['messages'] as $m) {
                if (is_array($m)) {
                    $messageRows[] = $m;
                }
            }
        }
        if ($isMessageRow($r) && !$isTicketRow($r)) {
            $messageRows[] = $r;
        } elseif ($isTicketRow($r) && $ticketRow === null) {
            $ticketRow = $r;
        } elseif (!$isMessageRow($r) && $ticketRow === null && empty($messageRows)) {
            $ticketRow = $r;
        }
    }

    $messages = array_map('normalize_ticket_message', array_values(array_filter($messageRows, 'is_array')));
    usort($messages, static function (array $a, array $b): int {
        return ($a['created_ts'] ?? 0) <=> ($b['created_ts'] ?? 0);
    });

    return [$ticketRow, $messages];
}

/**
 * Étiquette de date relative pour un message :
 *   aujourd'hui → "10:51" · hier → "Hier 10:51" · sinon → "3J" / "2M" / "1A".
 */
function ticket_msg_when(?int $ts): string
{
    if ($ts === null || $ts <= 0) {
        return '—';
    }
    $tz = new DateTimeZone(defined('PORTAIL_STATS_TZ') ? PORTAIL_STATS_TZ : 'Europe/Paris');
    try {
        $d   = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $now = new DateTimeImmutable('now', $tz);
    } catch (Throwable $e) {
        return ticket_datetime($ts, true);
    }

    $msgDay = $d->setTime(0, 0, 0);
    $today  = $now->setTime(0, 0, 0);

    if ($msgDay > $today) {            // futur (horloge décalée) → on montre l'heure
        return $d->format('H:i');
    }
    $ageDays = (int)$msgDay->diff($today)->days;
    if ($ageDays === 0) {
        return $d->format('H:i');      // aujourd'hui
    }
    if ($ageDays === 1) {
        return 'Hier ' . $d->format('H:i');
    }
    if ($ageDays < 31) {
        return $ageDays . 'J';
    }
    $iv = $msgDay->diff($today);
    if ($iv->y >= 1) {
        return $iv->y . 'A';
    }
    $months = $iv->y * 12 + $iv->m;
    return ($months >= 1 ? $months : 1) . 'M';
}

/** Mappe une ligne n8n (table « ticket_portail ») vers la structure attendue par la page. */
function normalize_ticket(array $row): array
{
    $id = pick($row, ['id', 'rowid', 'ticket_id', 'ticketId'], 0);
    $id = is_numeric($id) ? (int)$id : 0;

    $ref = (string)pick($row, ['ref', 'reference', 'number'], $id > 0 ? sprintf('TIC-%06d', $id) : 'TIC');

    $createdTs = to_timestamp(pick($row, ['created_at', 'createdAt', 'date_creation', 'datec', 'created', 'date']));
    $updatedTs = to_timestamp(pick($row, ['updated_at', 'updatedAt', 'date_modification', 'tms', 'updated', 'last_reply_at', 'lastReplyAt']));

    $subject   = trim((string)pick($row, ['subject', 'sujet', 'objet', 'title', 'titre'], ''));
    $category  = (string)pick($row, ['category', 'categorie', 'type'], '');
    $message   = trim((string)pick($row, ['message', 'description', 'body', 'content'], ''));
    $statusRaw = (string)pick($row, ['status', 'statut', 'state'], 'ouvert');
    $prioRaw   = (string)pick($row, ['priority', 'priorite', 'prio'], 'normale');
    $replies   = pick($row, ['replies_count', 'messages_count', 'nb_messages'], null);

    $subcategory = s_lower(trim((string)pick($row, ['subcategory', 'sous_categorie', 'souscategorie', 'subcategorie'], '')));
    // « deployment » est l'ancienne valeur : le choix ne se limite plus aux
    // déploiements Kubernetes, il porte sur n'importe quel service acheté.
    if ($subcategory === 'deployment') {
        $subcategory = 'service';
    }

    // Service concerné : uid de la ligne de commande (order_product.uid), et
    // non plus un nom de Deployment. tickets_attach_products() lui rendra un
    // libellé lisible.
    $productUid = trim((string)pick($row, ['product_uid', 'productUid', 'product', 'uid'], ''));

    // Famille du service (colonne product.esp_cli_menu_name) : web, cloud,
    // other, vm, bm — les mêmes clés que les dépliants « Mes services ».
    $menu = s_lower(trim((string)pick($row, ['esp_cli_menu_name', 'espCliMenuName', 'menu', 'menu_name'], '')));

    // Tickets créés AVANT la bascule : la colonne « deployments » portait des
    // noms de Deployment. On la relit pour ne pas afficher un fil vide.
    $deployments = pick($row, ['deployments', 'deploiements', 'deployment', 'deploiement'], '');
    if (is_array($deployments)) {
        $deployments = implode(', ', array_map('strval', $deployments));
    }
    $deployments = trim((string)$deployments);
    $domains = pick($row, ['domains', 'domaines', 'domain', 'domaine'], '');
    if (is_array($domains)) {
        $domains = implode(', ', array_map('strval', $domains));
    }
    $domains = trim((string)$domains);

    // Créateur du ticket (colonne author_name de ticket_portail).
    $createdBy = dot_civility_name(trim((string)pick($row, ['author_name', 'authorName', 'created_by', 'createdBy', 'author', 'auteur'], '')));

    $rowClientId = (string)pick($row, ['client_id', 'clientId'], '');
    $structure   = trim((string)pick($row, ['structure', 'raison', 'organization', 'organization_name', 'nom_commercial'], ''));

    return [
        'id'             => $id,
        'ref'            => $ref,
        'subject'        => $subject !== '' ? $subject : 'Sans objet',
        'category'       => ticket_category_label($category),
        'category_key'   => s_lower(trim((string)$category)),
        'subcategory'    => $subcategory,
        'product_uid'       => $productUid,
        'product_name'      => '',   // rempli par tickets_attach_products()
        'esp_cli_menu_name' => $menu,
        'menu_label'        => ticket_menu_label($menu),
        'deployments'    => $deployments,   // héritage : tickets d'avant la bascule
        'domains'        => $domains,
        'created_by'     => $createdBy,
        'author_email'   => '',      // rempli par tickets_attach_authors()
        'client_id'      => $rowClientId,
        'structure'      => $structure,
        'message'        => $message,
        'priority'       => ticket_priority_key($prioRaw),
        'priority_label' => ticket_priority_label($prioRaw),
        'priority_class' => ticket_priority_class($prioRaw),
        'status'         => ticket_status_key($statusRaw),
        'status_label'   => ticket_status_label($statusRaw),
        'status_class'   => ticket_status_class($statusRaw),
        'created_at'     => ticket_datetime($createdTs, false),
        'created_full'   => ticket_datetime($createdTs, true),
        'created_ts'     => $createdTs,
        'updated_at'     => ticket_datetime($updatedTs, false),
        'updated_ts'     => $updatedTs,
        'replies_count'  => is_numeric($replies) ? (int)$replies : null,
    ];
}

/** Mappe une ligne de message/réponse (table « ticket_message_portail »). */
function normalize_ticket_message(array $row): array
{
    $createdTs = to_timestamp(pick($row, ['created_at', 'createdAt', 'date', 'datec', 'created', 'tms']));

    $type      = s_lower(trim((string)pick($row, ['author_type', 'authorType', 'type', 'sender', 'from', 'role'], 'client')));
    $isSupport = in_array($type, ['support', 'staff', 'agent', 'admin', 'assistance', 'gnl'], true);

    return [
        'id'            => (int)pick($row, ['id', 'rowid'], 0),
        'body'          => trim((string)pick($row, ['body', 'message', 'content', 'text'], '')),
        'author'        => dot_civility_name(trim((string)pick($row, ['author_name', 'authorName', 'author', 'name', 'user', 'from_name'], $isSupport ? 'Support GNL' : 'Vous'))),
        'author_type'   => $isSupport ? 'support' : 'client',
        'agent_id'      => (string)pick($row, ['agent_id', 'agentId'], ''),
        'client_id'     => (string)pick($row, ['client_id', 'clientId'], ''),
        'created_at'    => ticket_datetime($createdTs, true), // complet (info-bulle)
        'created_label' => ticket_msg_when($createdTs),       // relatif (affiché)
        'created_ts'    => $createdTs,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Authentification (commune à tous les modules)
// ══════════════════════════════════════════════════════════════════════════════
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    send_json(401, ['ok' => false, 'error' => 'Non authentifié (cookie de session absent ?).']);
}

$user     = $_SESSION['user'];
$clientId = (int)($user['id'] ?? 0);

// Identité : depuis gnl_apply_identity() (include/keycloak_rest.php),
// $user['id'] est le VRAI UID Keycloak — une CHAÎNE (UUID) — et
// $user['account_id'] l'entier stable réservé aux tables locales à clé INT.
// (int) d'un UUID vaut 0 dès qu'il commence par une lettre (a-f, soit ~1 compte
// sur 3) : ce cast ne peut donc servir NI à juger qu'une session est valide,
// NI de clé pour user_account_sessions. On garde $clientId tel quel car
// portailApiCall() réinjecte de toute façon le vrai UID dans chaque payload
// n8n (client_id), mais le contrôle d'accès et le suivi de session utilisent
// désormais l'UID pour l'un et account_id pour l'autre.
$clientUid = trim((string)($user['id'] ?? ''));
$accountId = (int)($user['account_id'] ?? 0);
if ($accountId <= 0 && ctype_digit($clientUid)) {
    $accountId = (int)$clientUid;  // sessions historiques : id = entier local
}
if ($clientUid === '' && $accountId <= 0) {
    send_json(401, ['ok' => false, 'error' => 'Identifiant client introuvable dans la session.']);
}

if ($accountId > 0) {
    if (accountSessionsIsCurrentSessionRevoked($pdo, $accountId)) {
        accountSessionsDestroyPhpSession();
        send_json(401, ['ok' => false, 'error' => 'Cette session a été déconnectée depuis vos paramètres.']);
    }
    accountSessionsTouchCurrent($pdo, $accountId);
}

// Contexte « équipes » (droits calculés serveur, non falsifiables)
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

// ══════════════════════════════════════════════════════════════════════════════
//  Routage : action = "<module>.<sous-action>"
// ══════════════════════════════════════════════════════════════════════════════
$action = (string)($_REQUEST['action'] ?? '');

// ── Droits par action (fonction Keycloak, include/org_permissions.php) ───────
// Première règle dont le préfixe correspond. Une liste = AU MOINS UN des droits.
// Les actions absentes (notifications, documentation, tableau de bord, équipe,
// console support) restent ouvertes à tout membre authentifié ; les actions
// team.* appliquent leurs propres contrôles plus bas.
$portailPermRules = [
    'domain.'           => 'dns.manage',
    'invoice.'          => 'invoices.view',
    'order.'            => 'orders.view',
    'subscription.'     => 'orders.view',
    'product.list'      => 'orders.view',
    'ticket.'           => 'tickets.manage',
    'deployment.list'   => ['services.manage', 'dns.manage'],
    'deployment.rename' => 'services.manage',
];
foreach ($portailPermRules as $prefix => $needed) {
    if (strpos($action, $prefix) === 0) {
        require_once __DIR__ . '/../include/org_permissions.php';
        orgRequireApi($needed, 'send_json');
        break;
    }
}

try {
    switch ($action) {

        // ─────────────────────────────────────────────────────────────────────
        //  DOMAINES
        // ─────────────────────────────────────────────────────────────────────
        case 'domain.list': {
            $resp = n8n_call(['action' => 'domain.list', 'client_id' => $clientId]);
            ensure_ok($resp);
            send_json(200, [
                'ok'      => true,
                'domains' => extract_rows($resp['json'], ['domains', 'records'], ['id', 'domain_buy_name']),
            ]);
        }

        case 'domain.records': {
            $domain = rtrim(strtolower(trim((string)($_GET['domain'] ?? ''))), '.');
            if (!is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de domaine invalide.']);
            }
            $resp = n8n_call(['action' => 'domain.records', 'client_id' => $clientId, 'domain' => $domain]);
            ensure_ok($resp);
            send_json(200, [
                'ok'      => true,
                'records' => extract_rows($resp['json'], ['records', 'domains'], ['id', 'domain_buy_name']),
            ]);
        }

        case 'domain.add_record':
        case 'domain.delete_record': {
            require_post();
            csrf_check();

            $domain = rtrim(strtolower(trim((string)($_POST['domain'] ?? ''))), '.');
            if (!is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de domaine invalide.']);
            }

            $payload = ['action' => $action, 'client_id' => $clientId, 'domain' => $domain];

            if ($action === 'domain.add_record') {
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
            } else { // domain.delete_record
                $recordId = trim((string)($_POST['id'] ?? ''));
                if ($recordId === '') {
                    send_json(400, ['ok' => false, 'error' => 'Identifiant d\'enregistrement manquant.']);
                }
                $payload['id'] = $recordId;
            }

            $resp = n8n_call($payload);
            ensure_ok($resp);
            send_json(200, ['ok' => true, 'action' => $action]);
        }

        case 'domain.upsert':
        case 'domain.verify':
        case 'domain.deploy': {
            require_post();
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
            if ($action === 'domain.deploy' && $linkedTo === '') {
                send_json(400, ['ok' => false, 'error' => 'Un déploiement cible est requis.']);
            }

            $resp = n8n_call([
                'action'          => $action,
                'client_id'       => $clientId,
                'domain_buy_name' => $domain,
                'linked_to'       => $linkedTo,
                'gnl_domain'      => $gnl,
                'ns_gnl'          => $nsGnl,
            ]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['domains', 'records'], ['id', 'domain_buy_name']);
            $row  = $rows[0] ?? null;

            $out = ['ok' => true, 'action' => $action];
            if ($row !== null) {
                $out['row'] = $row;
            }
            if ($action === 'domain.verify') {
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

        case 'domain.delete': {
            require_post();
            csrf_check();

            $domain = rtrim(strtolower(trim((string)($_POST['domain_buy_name'] ?? ''))), '.');
            $id     = trim((string)($_POST['id'] ?? ''));

            if ($id === '' && !is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Domaine ou identifiant requis pour la suppression.']);
            }

            $resp = n8n_call([
                'action'          => 'domain.delete',
                'client_id'       => $clientId,
                'domain_buy_name' => $domain,
                'id'              => $id !== '' ? $id : null,
            ]);
            ensure_ok($resp);
            send_json(200, ['ok' => true, 'action' => 'domain.delete']);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  DOCUMENTATION
        // ─────────────────────────────────────────────────────────────────────
        case 'documentation.list':
        case 'documentation.search': {
            $query = ($action === 'documentation.search') ? trim((string)($_GET['q'] ?? '')) : '';

            // On demande toujours « documentation.list » à n8n ; le filtrage de
            // garantie se fait ICI. Le champ q est transmis au cas où.
            $payload = ['action' => 'documentation.list', 'client_id' => $clientId];
            if ($query !== '') {
                $payload['q'] = $query;
            }

            $resp = n8n_call($payload);
            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], [
                    'ok'    => false,
                    'error' => 'n8n a renvoyé HTTP ' . $resp['status'],
                    'code'  => 'N8N',
                ]);
            }

            $rows = extract_rows($resp['json'], ['articles', 'documents', 'records', 'knowledgebase'], ['id', 'title', 'question']);

            $articles = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $articles[] = documentationNormalize($row);
                }
            }

            usort(
                $articles,
                static fn(array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title'])
            );

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
            if ($action === 'documentation.search') {
                $out['query'] = $query;
            }
            send_json(200, $out);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  ABONNEMENTS
        // ─────────────────────────────────────────────────────────────────────
        // Source : API Mollie (plus n8n). Le client Mollie vient de l'attribut
        // « moliecliid » de l'ORGANISATION Keycloak de la session, lu côté serveur.
        case 'subscription.list': {
            $customerId = mollie_customer_or_exit($user);

            $r = mollieListCustomerSubscriptions($customerId);
            if (!$r['ok']) {
                send_json(502, [
                    'ok'    => false,
                    'error' => $r['error'],
                    'code'  => 'MOLLIE',
                ]);
            }

            $subscriptions = array_map('normalize_mollie_subscription', $r['subscriptions']);

            // Actifs d'abord, puis par prochaine échéance / date de début.
            $rank = ['active' => 0, 'pending' => 1, 'suspended' => 2, 'completed' => 3, 'canceled' => 4];
            usort($subscriptions, static function (array $a, array $b) use ($rank): int {
                $ra = $rank[$a['status']] ?? 5;
                $rb = $rank[$b['status']] ?? 5;
                if ($ra !== $rb) {
                    return $ra <=> $rb;
                }
                $ta = $a['end_ts'] ?? $a['start_ts'] ?? PHP_INT_MAX;
                $tb = $b['end_ts'] ?? $b['start_ts'] ?? PHP_INT_MAX;
                return $ta <=> $tb;
            });

            send_json(200, [
                'ok'            => true,
                'linked'        => true,
                'count'         => count($subscriptions),
                'subscriptions' => $subscriptions,
            ]);
        }

        case 'subscription.detail': {
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                $id = trim((string)($_GET['ref'] ?? ''));
            }
            if ($id === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » ou « ref » requis.']);
            }

            $customerId = mollie_customer_or_exit($user);

            if (!mollieIsSubscriptionId($id)) {
                send_json(404, ['ok' => false, 'error' => 'Abonnement introuvable.']);
            }

            // Lu SOUS le client : un sub_ appartenant à un autre client → 404 Mollie.
            $r = mollieGetCustomerSubscription($customerId, $id);
            if (!$r['ok']) {
                if ($r['status'] === 404) {
                    send_json(404, ['ok' => false, 'error' => 'Abonnement introuvable.']);
                }
                send_json(502, [
                    'ok'    => false,
                    'error' => $r['error'],
                    'code'  => 'MOLLIE',
                ]);
            }

            send_json(200, [
                'ok'            => true,
                'linked'        => true,
                'count'         => 1,
                'subscriptions' => [normalize_mollie_subscription($r['subscription'])],
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  FACTURES
        // ─────────────────────────────────────────────────────────────────────
        case 'invoice.list': {
            $resp = n8n_call(['action' => 'invoice.list', 'client_id' => $clientId]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['invoices', 'factures'], ['id', 'ref', 'reference']);
            $invoices = array_map('normalize_invoice', $rows);

            send_json(200, [
                'ok'       => true,
                'count'    => count($invoices),
                'invoices' => $invoices,
            ]);
        }

        case 'invoice.detail': {
            $id  = trim((string)($_GET['id'] ?? ''));
            $ref = trim((string)($_GET['ref'] ?? ''));
            if ($id === '' && $ref === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » ou « ref » requis.']);
            }

            $resp = n8n_call([
                'action'    => 'invoice.detail',
                'client_id' => $clientId,
                'id'        => $id,
                'ref'       => $ref,
            ]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['invoices', 'factures'], ['id', 'ref', 'reference']);

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

        // ─────────────────────────────────────────────────────────────────────
        //  COMMANDES
        // ─────────────────────────────────────────────────────────────────────
        case 'order.list': {
            $resp = n8n_call(['action' => 'order.list', 'client_id' => $clientId]);
            ensure_ok($resp);

            $rows   = extract_rows($resp['json'], ['orders', 'commandes'], ['id', 'ref', 'reference']);
            $orders = array_map('normalize_order', $rows);

            send_json(200, [
                'ok'     => true,
                'count'  => count($orders),
                'orders' => $orders,
            ]);
        }

        case 'order.detail': {
            // Détail d'une commande = ses lignes. DEUX actions n8n, et deux
            // seulement : « order.product » puis « order.product.option ».
            // L'en-tête (référence, date, statut, montant, fréquence) est déjà
            // connu du navigateur via order.list : le re-demander serait un
            // aller-retour n8n pour rien.
            $id  = trim((string)($_GET['id'] ?? ''));
            $ref = trim((string)($_GET['ref'] ?? ''));
            if ($id === '' && $ref === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » ou « ref » requis.']);
            }

            $warnProducts = null;
            $warnOptions  = null;
            $productRows = order_product_rows($clientId, $id, $ref, $warnProducts);
            $optionRows  = order_option_rows($clientId, $id, $ref, $warnOptions);

            $lines = build_order_lines($productRows, $optionRows, $ref);

            $warnings = array_values(array_filter([$warnProducts, $warnOptions]));

            send_json(200, [
                'ok'            => true,
                'ref'           => $ref,
                'id'            => $id,
                'count'         => count($lines['products']),
                'products'      => $lines['products'],
                'extra_options' => $lines['extra_options'],
                'totals'        => $lines['totals'],
                'lines_warning' => $warnings ? implode(' ; ', $warnings) : null,
            ]);
        }

        case 'order.product': {
            $id  = trim((string)($_GET['id'] ?? ''));
            $ref = trim((string)($_GET['ref'] ?? ''));
            if ($id === '' && $ref === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » ou « ref » requis.']);
            }

            $warning  = null;
            $rows     = order_product_rows($clientId, $id, $ref, $warning);
            $products = array_map(static function ($r) {
                return normalize_order_product(is_array($r) ? $r : []);
            }, $rows);

            send_json(200, [
                'ok'       => true,
                'count'    => count($products),
                'products' => $products,
                'warning'  => $warning,
            ]);
        }

        case 'order.product.option': {
            $id  = trim((string)($_GET['id'] ?? ''));
            $ref = trim((string)($_GET['ref'] ?? ''));
            if ($id === '' && $ref === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » ou « ref » requis.']);
            }

            $warning = null;
            $rows    = order_option_rows($clientId, $id, $ref, $warning);
            $options = array_map(static function ($r) {
                return normalize_order_option(is_array($r) ? $r : []);
            }, $rows);

            send_json(200, [
                'ok'      => true,
                'count'   => count($options),
                'options' => $options,
                'warning' => $warning,
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  CATALOGUE PRODUITS
        // ─────────────────────────────────────────────────────────────────────
        case 'product.list': {
            $resp = n8n_call(['action' => 'product.list', 'client_id' => $clientId]);
            ensure_ok($resp);

            $rows     = extract_rows($resp['json'], ['products', 'produits', 'product', 'catalogue', 'catalog'], ['slug', 'id']);
            $products = array_map(static function ($r) {
                return normalize_catalog_product(is_array($r) ? $r : []);
            }, $rows);

            send_json(200, [
                'ok'       => true,
                'count'    => count($products),
                'products' => $products,
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  ÉQUIPES
        // ─────────────────────────────────────────────────────────────────────
        // Source de vérité : les ORGANISATIONS Keycloak (Admin REST), et non
        // plus la table « team » de n8n. On liste les membres de l'organisation
        // que l'utilisateur a retenue à la connexion (page /organisation).
        // Services / fonctions = groupes d'organisation (include/org_permissions.php).
        case 'team.list': {
            require_once __DIR__ . '/../include/org_permissions.php';

            // NB : HTTP 200 + ok:false (et non 502), comme le catch en bas de
            // fichier — le middleware Traefik « custom-errors » remplace le
            // corps de toute réponse 5xx et effacerait le message d'erreur.
            // orgTeamContext() : organisation courante (kcOrgResolveCurrent),
            // arbre des services/fonctions (groupes d'organisation), appartenances
            // de chaque membre et droits effectifs de l'utilisateur.
            $ctx = orgTeamContext($user);
            if (!$ctx['ok']) {
                send_json(200, [
                    'ok'    => false,
                    'code'  => 502,
                    'error' => $ctx['error'] !== '' ? $ctx['error'] : 'Organisation Keycloak introuvable.',
                ]);
            }
            orgRememberPerms($ctx);
            $org = $ctx['org'];

            $fetched = kcOrgMembers((string)$org['id']);
            if (!$fetched['ok']) {
                send_json(200, [
                    'ok'    => false,
                    'code'  => 502,
                    'error' => $fetched['error'] !== '' ? $fetched['error'] : 'Membres Keycloak indisponibles.',
                ]);
            }

            // Nom affiché (structureName côté page) : attribut d'organisation
            // « nom_commercial », à défaut le nom de l'organisation — c'est ce
            // que calcule kcOrgNormalize()['label']. La raison sociale de la
            // session ne sert que si Keycloak ne renvoyait NI l'un NI l'autre.
            $structure = $org['label'] !== '' ? $org['label'] : $sessionStructure;

            $members = array_map(
                static function ($row) use ($structure, $ctx) {
                    return team_attach_functions(normalize_kc_member(is_array($row) ? $row : [], $structure), $ctx);
                },
                $fetched['members']
            );

            // Tri alphabétique stable (l'Admin REST ne garantit pas d'ordre).
            usort($members, static function (array $a, array $b): int {
                return strcmp(s_lower($a['name']), s_lower($b['name']))
                    ?: strcmp((string)$a['id'], (string)$b['id']);
            });

            send_json(200, [
                'ok'           => true,
                'count'        => count($members),
                'members'      => $members,
                'structure'    => $structure,
                'can_edit'     => team_actor_can($ctx, 'teams.assign') || team_actor_can($ctx, 'teams.manage'),
                'source'       => 'keycloak',
                'truncated'    => (bool)$fetched['truncated'],
                'organization' => [
                    'id'    => $org['id'],
                    'name'  => $org['name'],
                    'alias' => $org['alias'],
                    'label' => $org['label'],
                ],
                'groups'           => team_groups_payload($ctx),
                'groups_supported' => (bool)$ctx['groups_supported'],
                'groups_truncated' => (bool)$ctx['truncated_groups'],
                'global_service'   => ORG_GLOBAL_SERVICE,
                'catalog'          => team_catalog_payload(),
                'me'               => team_me_payload($ctx),
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  SERVICES / FONCTIONS (groupes d'organisation Keycloak)
        //  Toutes ces actions : POST + CSRF, droits recalculés côté serveur à
        //  partir de Keycloak (jamais depuis le navigateur).
        // ─────────────────────────────────────────────────────────────────────
        case 'team.group.create': {
            require_post();
            csrf_check();
            $ctx = team_context_or_fail($user);
            team_require($ctx, 'teams.manage', "Vous n'avez pas le droit de créer des services ou des fonctions.");
            $orgId = (string)$ctx['org']['id'];

            $name     = team_clean_group_name((string)($_POST['name'] ?? ''));
            $parentIn = trim((string)($_POST['parent_id'] ?? ''));
            $kind     = (string)($_POST['kind'] ?? ($parentIn === '' ? 'service' : 'function'));
            $perm     = team_perm_from_post();

            // Fonction sans service → sous-groupe du service « Global ».
            if ($kind === 'function' && ($parentIn === '' || $parentIn === '__global__')) {
                $parentIn = team_ensure_global_service($ctx);
            }

            $parentEff = [];
            if ($parentIn !== '') {
                if (!isset($ctx['index'][$parentIn])) {
                    send_json(400, ['ok' => false, 'error' => 'Service ou fonction parent introuvable dans votre organisation.']);
                }
                if ($ctx['index'][$parentIn]['depth'] + 1 >= KC_ORG_GROUPS_MAX_DEPTH) {
                    send_json(400, ['ok' => false, 'error' => 'Profondeur maximale atteinte (' . KC_ORG_GROUPS_MAX_DEPTH . ' niveaux).']);
                }
                $parentEff = $ctx['index'][$parentIn]['effective'];
                foreach ($ctx['index'] as $g) {
                    if ($g['parent_id'] === $parentIn && s_lower($g['name']) === s_lower($name)) {
                        send_json(400, ['ok' => false, 'error' => 'Une fonction porte déjà ce nom dans ce service.']);
                    }
                }
            } else {
                if (strcasecmp($name, ORG_GLOBAL_SERVICE) === 0 && team_find_global($ctx) !== '') {
                    send_json(400, ['ok' => false, 'error' => 'Le service « ' . ORG_GLOBAL_SERVICE . ' » existe déjà.']);
                }
                foreach ($ctx['index'] as $g) {
                    if ($g['is_service'] && s_lower($g['name']) === s_lower($name)) {
                        send_json(400, ['ok' => false, 'error' => 'Un service porte déjà ce nom.']);
                    }
                }
            }

            // Pas d'élévation : on n'accorde que ce qu'on détient.
            team_require_covers($ctx, array_merge($perm, $parentEff));

            $attrs = $perm !== [] ? [ORG_PERM_ATTRIBUTE => [orgPermSerialize($perm)]] : [];
            $res   = kcOrgGroupCreate($orgId, $name, $parentIn, $attrs);
            if (!$res['ok']) {
                send_json(200, ['ok' => false, 'code' => 502, 'error' => $res['error']]);
            }
            orgForgetPerms();
            send_json(200, [
                'ok'      => true,
                'id'      => $res['id'],
                'message' => $parentIn === '' ? 'Service créé.' : 'Fonction créée.',
            ]);
        }

        case 'team.group.update': {
            require_post();
            csrf_check();
            $ctx = team_context_or_fail($user);
            team_require($ctx, 'teams.manage', "Vous n'avez pas le droit de modifier les services ou les fonctions.");
            $orgId   = (string)$ctx['org']['id'];
            $groupId = trim((string)($_POST['group_id'] ?? ''));
            $g       = team_group_or_fail($ctx, $groupId);

            // On ne touche pas à un groupe plus privilégié que soi.
            team_require_covers($ctx, $g['effective'], 'Ce groupe accorde des droits que vous ne détenez pas : vous ne pouvez pas le modifier.');

            $newName = array_key_exists('name', $_POST) ? team_clean_group_name((string)$_POST['name']) : null;
            if ($newName !== null && $g['is_global'] && $g['is_service'] && strcasecmp($newName, ORG_GLOBAL_SERVICE) !== 0) {
                send_json(400, ['ok' => false, 'error' => 'Le service « ' . ORG_GLOBAL_SERVICE . ' » ne peut pas être renommé.']);
            }
            if ($newName !== null) {
                foreach ($ctx['index'] as $o) {
                    if ($o['id'] !== $groupId && $o['parent_id'] === $g['parent_id'] && s_lower($o['name']) === s_lower($newName)) {
                        send_json(400, ['ok' => false, 'error' => 'Ce nom est déjà utilisé à cet endroit.']);
                    }
                }
            }

            $attrs = null;
            if (array_key_exists('perm', $_POST) || array_key_exists('perm_set', $_POST)) {
                $perm   = team_perm_from_post();
                $parent = $g['parent_id'] !== '' && isset($ctx['index'][$g['parent_id']]) ? $ctx['index'][$g['parent_id']]['effective'] : [];
                team_require_covers($ctx, array_merge($perm, $parent));

                // Simulation : les sous-groupes héritent du nouveau jeu de droits.
                $groups = [];
                foreach ($ctx['index'] as $id => $o) {
                    $o['attributes'] = $o['attributes'] ?? [];
                    if ($id === $groupId) {
                        $o['attributes'][ORG_PERM_ATTRIBUTE] = $perm !== [] ? [orgPermSerialize($perm)] : [];
                    }
                    $groups[] = $o;
                }
                team_guard_managers($ctx, orgGroupsIndex($groups), $ctx['memberships'],
                    'Cette modification retirerait à tous les membres le droit de gérer l\'équipe.');

                $attrs = $g['attributes'];
                if ($perm !== []) {
                    $attrs[ORG_PERM_ATTRIBUTE] = [orgPermSerialize($perm)];
                } else {
                    unset($attrs[ORG_PERM_ATTRIBUTE]);
                }
            }

            if ($newName === null && $attrs === null) {
                send_json(400, ['ok' => false, 'error' => 'Aucune modification demandée.']);
            }

            $res = kcOrgGroupUpdate($orgId, $groupId, $newName, $attrs);
            if (!$res['ok']) {
                send_json(200, ['ok' => false, 'code' => 502, 'error' => $res['error']]);
            }
            orgForgetPerms();
            send_json(200, ['ok' => true, 'message' => 'Modifications enregistrées.']);
        }

        case 'team.group.delete': {
            require_post();
            csrf_check();
            $ctx = team_context_or_fail($user);
            team_require($ctx, 'teams.manage', "Vous n'avez pas le droit de supprimer des services ou des fonctions.");
            $orgId   = (string)$ctx['org']['id'];
            $groupId = trim((string)($_POST['group_id'] ?? ''));
            team_group_or_fail($ctx, $groupId);

            $subtree = orgGroupSubtree($ctx['index'], $groupId);
            foreach ($subtree as $sid) {
                team_require_covers($ctx, $ctx['index'][$sid]['effective'],
                    'Ce groupe (ou l\'un de ses sous-groupes) accorde des droits que vous ne détenez pas : vous ne pouvez pas le supprimer.');
            }

            // Simulation : le sous-arbre disparaît, ses membres perdent ces fonctions.
            $gone   = array_fill_keys($subtree, true);
            $groups = array_values(array_filter($ctx['index'], static function ($o) use ($gone) { return !isset($gone[$o['id']]); }));
            $ms     = [];
            foreach ($ctx['memberships'] as $uid => $gids) {
                $ms[$uid] = array_values(array_filter($gids, static function ($x) use ($gone) { return !isset($gone[$x]); }));
            }
            team_guard_managers($ctx, orgGroupsIndex($groups), $ms,
                'Supprimer ce groupe retirerait à tous les membres le droit de gérer l\'équipe.');

            $res = kcOrgGroupDelete($orgId, $groupId);
            if (!$res['ok']) {
                send_json(200, ['ok' => false, 'code' => 502, 'error' => $res['error']]);
            }
            orgForgetPerms();
            send_json(200, ['ok' => true, 'message' => 'Suppression effectuée.']);
        }

        case 'team.member.assign':
        case 'team.member.unassign': {
            require_post();
            csrf_check();
            $ctx = team_context_or_fail($user);
            team_require($ctx, 'teams.assign', "Vous n'avez pas le droit d'attribuer des fonctions.");
            $orgId    = (string)$ctx['org']['id'];
            $groupId  = trim((string)($_POST['group_id'] ?? ''));
            $memberId = trim((string)($_POST['member_id'] ?? ''));
            $g        = team_group_or_fail($ctx, $groupId);
            $add      = ($action === 'team.member.assign');

            if ($memberId === '' || ctype_digit($memberId)) {
                send_json(400, ['ok' => false, 'error' => 'Membre invalide.']);
            }
            if ($g['is_service']) {
                send_json(400, ['ok' => false, 'error' => 'Choisissez une fonction (un service ne s\'attribue pas directement).']);
            }
            team_require_covers($ctx, $g['effective'], 'Cette fonction accorde des droits que vous ne détenez pas.');

            // Le membre doit appartenir à l'organisation courante.
            if (kcOrgMemberGet($orgId, $memberId) === null) {
                send_json(404, ['ok' => false, 'error' => "Ce compte n'est pas membre de votre organisation."]);
            }

            $ms  = $ctx['memberships'];
            $cur = $ms[$memberId] ?? [];
            if ($add) {
                if (in_array($groupId, $cur, true)) {
                    send_json(200, ['ok' => true, 'message' => 'Fonction déjà attribuée.']);
                }
                $ms[$memberId] = array_merge($cur, [$groupId]);
            } else {
                $ms[$memberId] = array_values(array_filter($cur, static function ($x) use ($groupId) { return $x !== $groupId; }));
                team_guard_managers($ctx, $ctx['index'], $ms,
                    'Impossible : ce membre est le dernier à pouvoir gérer l\'équipe.');
            }

            $res = kcOrgGroupSetMember($orgId, $groupId, $memberId, $add);
            if (!$res['ok']) {
                send_json(200, ['ok' => false, 'code' => 502, 'error' => $res['error']]);
            }
            orgForgetPerms();
            send_json(200, ['ok' => true, 'message' => $add ? 'Fonction attribuée.' : 'Fonction retirée.']);
        }

        case 'team.ensure': {
            // Alimente (idempotent) la table « team » pour l'utilisateur COURANT.
            // client_id injecté depuis la session (non falsifiable) ; un membre
            // peut provisionner sa PROPRE appartenance (aucun droit d'édition requis).
            require_post();
            csrf_check();

            $payload = portailBuildTeamEnsurePayload($user, 'portail_api');
            // Valeurs serveur non falsifiables prioritaires.
            $payload['client_id'] = $clientId;
            if ($currentSiret !== '') {
                $payload['siret'] = $currentSiret;
            }
            if ($sessionStructure !== '') {
                $payload['structure'] = $sessionStructure;
            }

            $resp = n8n_call($payload);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => "L'initialisation de l'équipe a échoué (n8n HTTP " . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? "L'initialisation de l'équipe a échoué.")]);
            }

            $row = (is_array($resp['json']) && isset($resp['json']['row']) && is_array($resp['json']['row']))
                ? $resp['json']['row']
                : null;

            send_json(200, ['ok' => true, 'message' => 'Équipe initialisée.', 'row' => $row]);
        }

        case 'team.update': {
            require_post();
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

            $resp = n8n_call([
                'action'    => 'team.update',
                'client_id' => $clientId,
                'siret'     => $currentSiret,
                'member_id' => $memberId,
                'email'     => $email,
                'fonction'  => $fonction,
                'statut'    => $active,
                'active'    => $active,
            ]);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'La mise à jour a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'La mise à jour a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => 'Le membre a été mis à jour.']);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  RENOMMAGE « Mes services » (table label_portail V2, clé product_uid)
        // ─────────────────────────────────────────────────────────────────────
        case 'deployment.list': {
            $resp = n8n_call(['action' => 'deployment.list', 'client_id' => $clientId]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['deployments', 'labels', 'label_portail'], ['product_uid', 'uid', 'deployment_name', 'name']);
            $deployments = [];
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $norm = normalize_deployment($r);
                if ($norm !== null) {
                    $deployments[] = $norm;
                }
            }

            send_json(200, ['ok' => true, 'deployments' => $deployments]);
        }

        case 'deployment.rename': {
            require_post();
            csrf_check();

            // « deployment_name » reste accepté pour ne pas casser un appelant
            // resté sur la V1 ; la clé de référence est désormais product_uid.
            $productUid  = trim((string)($_POST['product_uid'] ?? $_POST['deployment_name'] ?? ''));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            if ($productUid === '') {
                send_json(400, ['ok' => false, 'error' => 'product_uid manquant.']);
            }

            $resp = n8n_call([
                'action'       => 'deployment.rename',
                'client_id'    => $clientId,
                'product_uid'  => $productUid,   // = order_product.uid
                'display_name' => $displayName,  // '' ⇒ réinitialise au nom du catalogue
            ]);
            ensure_ok($resp);

            // La barre latérale et la page de service lisent les libellés via
            // include/services_catalog.php, qui met sa réponse en cache 120 s : on
            // l'invalide pour que le nouveau nom apparaisse dès le rechargement.
            // (« services_menu_cache » : ancienne clé, purgée par sécurité.)
            unset($_SESSION['services_catalog_cache'], $_SESSION['services_menu_cache']);

            $row = (is_array($resp['json']) && isset($resp['json']['row']) && is_array($resp['json']['row']))
                ? $resp['json']['row']
                : ['product_uid' => $productUid, 'display_name' => $displayName];

            send_json(200, ['ok' => true, 'row' => $row]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  NOTIFICATIONS (cloche)
        // ─────────────────────────────────────────────────────────────────────
        case 'notification.list': {
            $limit = (int)($_GET['limit'] ?? 20);
            if ($limit < 1)   { $limit = 1; }
            if ($limit > 100) { $limit = 100; }

            $resp = n8n_call([
                'action'    => 'notification.list',
                'client_id' => $clientId,
                'limit'     => $limit,
            ]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['notifications'], ['id']);

            // unread : top-level n8n prioritaire, sinon calcul tolérant.
            $unread = null;
            if (is_array($resp['json']) && array_key_exists('unread', $resp['json']) && is_numeric($resp['json']['unread'])) {
                $unread = (int)$resp['json']['unread'];
            } else {
                $unread = 0;
                foreach ($rows as $r) {
                    if (is_array($r) && notif_is_unread($r)) {
                        $unread++;
                    }
                }
            }

            send_json(200, [
                'ok'            => true,
                'notifications' => $rows,
                'unread'        => $unread,
            ]);
        }

        case 'notification.read': {
            require_post();
            csrf_check();

            $all = truthy($_POST['all'] ?? '0');
            $id  = trim((string)($_POST['id'] ?? ''));
            if (!$all && $id === '') {
                send_json(400, ['ok' => false, 'error' => 'Préciser id=… ou all=1.']);
            }

            $resp = n8n_call([
                'action'    => 'notification.read',
                'client_id' => $clientId,
                'all'       => $all ? 1 : 0,
                'id'        => $all ? null : $id,
            ]);
            ensure_ok($resp);

            send_json(200, ['ok' => true]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  STATISTIQUES (cartes + graphique du dashboard)
        // ─────────────────────────────────────────────────────────────────────
        case 'stats.dashboard': {
            // Lecture : pas de CSRF. client_id + contexte k8s injectés SERVEUR
            // (non falsifiables) via $user. Récupère les stats par deployment
            // au travers du webhook n8n unique (action "stats.dashboard").
            $stats        = portailFetchDashboardStats($user);
            $byDeployment = is_array($stats['by_deployment'] ?? null) ? $stats['by_deployment'] : [];

            // Agrégat tous deployments (alimente la carte « Requêtes ce mois-ci »
            // et la tendance vs mois précédent).
            $current  = 0;
            $previous = 0;
            $byMonth  = [];
            foreach ($byDeployment as $dep) {
                if (!is_array($dep)) {
                    continue;
                }
                $current  += (int)($dep['current_month_hits']  ?? 0);
                $previous += (int)($dep['previous_month_hits'] ?? 0);
                foreach (($dep['by_month'] ?? []) as $m => $c) {
                    $byMonth[(string)$m] = ($byMonth[(string)$m] ?? 0) + (int)$c;
                }
            }
            ksort($byMonth);

            send_json(200, [
                'ok'                  => true,
                'current_month_hits'  => $current,
                'previous_month_hits' => $previous,
                'by_month'            => $byMonth,
                'by_deployment'       => $byDeployment,
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  TICKETS (support / assistance)
        // ─────────────────────────────────────────────────────────────────────
        case 'ticket.list': {
            $resp = n8n_call([
                'action'    => 'ticket.list',
                'client_id' => $clientId,
                'siret'     => $currentSiret,
            ]);
            ensure_ok($resp);

            $rows    = extract_rows($resp['json'], ['tickets'], ['id', 'ref', 'reference']);
            $tickets = array_map('normalize_ticket', $rows);

            // L'identité de l'auteur n'est plus stockée : elle est résolue ici,
            // depuis client_id, via Keycloak (une passe pour toute la liste).
            $tickets = tickets_attach_authors($tickets);
            $tickets = tickets_attach_products($tickets, $accountId);

            // Plus récents d'abord (dernière mise à jour, sinon création).
            usort($tickets, static function (array $a, array $b): int {
                return ($b['updated_ts'] ?? $b['created_ts'] ?? 0) <=> ($a['updated_ts'] ?? $a['created_ts'] ?? 0);
            });

            send_json(200, [
                'ok'      => true,
                'count'   => count($tickets),
                'tickets' => $tickets,
            ]);
        }

        case 'ticket.detail': {
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » requis.']);
            }

            $resp = n8n_call([
                'action'    => 'ticket.detail',
                'client_id' => $clientId,
                'id'        => $id,
            ]);
            ensure_ok($resp);

            // n8n peut renvoyer, selon le workflow :
            //   - une ligne ticket seule,
            //   - une ligne ticket + un tableau "messages",
            //   - OU directement le tableau des messages (table ticket_message_portail).
            // On classe donc chaque ligne : « ressemble à un message » ou « à un ticket ».
            $rows = extract_rows($resp['json'], ['tickets', 'messages'], ['id', 'ref', 'reference']);

            $isMessageRow = static function ($r): bool {
                return is_array($r) && (
                    array_key_exists('body', $r) || array_key_exists('author_type', $r) ||
                    array_key_exists('authorType', $r) || array_key_exists('ticket_id', $r) ||
                    array_key_exists('ticketId', $r)
                );
            };
            $isTicketRow = static function ($r): bool {
                return is_array($r) && (
                    array_key_exists('subject', $r) || array_key_exists('sujet', $r) ||
                    array_key_exists('objet', $r) || array_key_exists('status', $r) ||
                    array_key_exists('statut', $r)
                );
            };

            // Messages explicitement fournis au niveau racine.
            $messageRows = [];
            if (is_array($resp['json']) && isset($resp['json']['messages']) && is_array($resp['json']['messages'])) {
                foreach ($resp['json']['messages'] as $m) {
                    if (is_array($m)) {
                        $messageRows[] = $m;
                    }
                }
            }

            $ticketRow = null;
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                // Messages imbriqués dans une ligne ticket.
                if (isset($r['messages']) && is_array($r['messages'])) {
                    foreach ($r['messages'] as $m) {
                        if (is_array($m)) {
                            $messageRows[] = $m;
                        }
                    }
                }
                if ($isMessageRow($r) && !$isTicketRow($r)) {
                    $messageRows[] = $r;
                } elseif ($isTicketRow($r) && $ticketRow === null) {
                    $ticketRow = $r;
                } elseif (!$isMessageRow($r) && $ticketRow === null && empty($messageRows)) {
                    // Ligne ambiguë et rien d'autre : on tente le ticket.
                    $ticketRow = $r;
                }
            }

            $messages = array_map('normalize_ticket_message', array_values(array_filter($messageRows, 'is_array')));
            usort($messages, static function (array $a, array $b): int {
                return ($a['created_ts'] ?? 0) <=> ($b['created_ts'] ?? 0);
            });

            // Auteurs résolus depuis Keycloak avant toute comparaison : les
            // lignes ne portent plus qu'un client_id / agent_id.
            $messages = tickets_attach_message_authors($messages);

            // Style WhatsApp + identité :
            //  - « mes » messages (auteur = utilisateur connecté) → à droite + nom canonique ;
            //  - les autres intervenants → à gauche, leur propre nom (déjà normalisé).
            //
            // ⚠️ $clientId vaut (int) de l'UID Keycloak, donc 0 dès que l'UID
            // commence par une lettre : la comparaison ne tombait JAMAIS juste
            // et aucun message n'était reconnu comme le sien. On compare
            // désormais l'UID lui-même, et l'entier local ne sert que pour les
            // sessions historiques.
            $me     = $clientUid !== '' ? $clientUid : (string) $accountId;
            $myName = user_display_name($user);
            // Si la session n'a pas de civilité, on la récupère dans MES propres messages.
            if (!preg_match('/^(M\.|Mme|Mlle|Dr|Me)\b/u', $myName)) {
                foreach ($messages as $m) {
                    $mid = (string)($m['client_id'] ?? '');
                    $aid = (string)($m['agent_id'] ?? '');
                    if (($mid === $me || $aid === $me)
                        && preg_match('/^(M|Mr|Mme|Mlle|Dr|Me|Monsieur|Madame|Mademoiselle|Docteur)\.?\s+/iu', (string)$m['author'], $mm)) {
                        $myName = trim(civility_label($mm[1]) . ' ' . strip_civility($myName !== '' ? $myName : (string)$m['author']));
                        break;
                    }
                }
            }

            foreach ($messages as &$m) {
                $mid  = (string)($m['client_id'] ?? '');
                $aid  = (string)($m['agent_id'] ?? '');
                $mine = ($me !== '' && (($mid !== '' && $mid === $me) || ($aid !== '' && $aid === $me)));
                $m['mine'] = $mine;
                if ($mine && $myName !== '') {
                    $m['author'] = $myName;
                }
            }
            unset($m);

            if ($ticketRow !== null) {
                $one    = tickets_attach_products(tickets_attach_authors([normalize_ticket($ticketRow)]), $accountId);
                $ticket = $one[0];
                $ticket['messages'] = $messages;
                $ticket['mine_owner'] = ((string)($ticket['client_id'] ?? '') === $me && $me !== '');
            } else {
                // Pas de ligne ticket : la page complète les métadonnées depuis ticket.list.
                // Côté client, le message initial appartient au client connecté.
                $ticket = [
                    'id'         => is_numeric($id) ? (int) $id : $id,
                    'messages'   => $messages,
                    'mine_owner' => true,
                ];
            }

            send_json(200, ['ok' => true, 'ticket' => $ticket]);
        }

        case 'ticket.create': {
            require_post();
            csrf_check();

            $subject  = trim((string)($_POST['subject'] ?? ''));
            $message  = trim((string)($_POST['message'] ?? ''));
            $category = s_lower(trim((string)($_POST['category'] ?? 'technique')));
            $priority = s_lower(trim((string)($_POST['priority'] ?? 'normale')));

            if (s_sub($subject, 0) === '' || mb_strlen($subject) < 3 || mb_strlen($subject) > 150) {
                send_json(400, ['ok' => false, 'error' => 'L’objet doit contenir entre 3 et 150 caractères.']);
            }
            if (mb_strlen($message) < 5 || mb_strlen($message) > 5000) {
                send_json(400, ['ok' => false, 'error' => 'Le message doit contenir entre 5 et 5000 caractères.']);
            }
            if (!in_array($category, ['technique', 'facturation', 'commercial', 'compte', 'autre'], true)) {
                $category = 'autre';
            }
            if (!in_array($priority, ['basse', 'normale', 'haute', 'urgente'], true)) {
                $priority = 'normale';
            }

            // Sous-catégorie : pertinente uniquement pour la catégorie « technique ».
            // « deployment » est accepté en entrée (page en cache, favori,
            // appel externe) et ramené à « service » : le choix ne se limite
            // plus aux déploiements Kubernetes.
            $subcategory = s_lower(trim((string)($_POST['subcategory'] ?? '')));
            if ($subcategory === 'deployment') {
                $subcategory = 'service';
            }
            if ($category !== 'technique') {
                $subcategory = '';
            } elseif (!in_array($subcategory, ['dns', 'service'], true)) {
                $subcategory = '';
            }

            // ── Service concerné (uniquement si sous-catégorie « service ») ──
            // On n'envoie plus un nom de Deployment mais l'uid de la ligne de
            // commande : il désigne aussi bien un serveur Pterodactyl qu'un
            // hébergement web, et il survit aux renommages.
            //
            // L'uid est VÉRIFIÉ contre le catalogue du client connecté :
            // servicesCatalogFindByUid() ne renvoie que ses propres lignes de
            // commande. Un uid emprunté à un autre client est donc refusé, et
            // la famille (esp_cli_menu_name) est lue dans le catalogue, JAMAIS
            // dans la requête — sinon n'importe qui la choisirait.
            $productUid  = '';
            $productMenu = '';
            $productName = '';
            if ($subcategory === 'service') {
                $productUid = trim((string)($_POST['product_uid'] ?? ''));
                if ($productUid === '') {
                    send_json(400, ['ok' => false, 'error' => 'Sélectionnez le service concerné.']);
                }

                require_once __DIR__ . '/../include/services_catalog.php';
                $entry = servicesCatalogFindByUid($accountId, $productUid);
                if ($entry === null) {
                    send_json(403, ['ok' => false, 'error' => "Ce service ne figure pas parmi vos services."]);
                }

                $productMenu = s_lower(trim((string)($entry['menu'] ?? '')));
                $productName = trim((string)($entry['name'] ?? ''));
            }

            // Domaines concernés (uniquement si sous-catégorie « dns »).
            $domains = [];
            if ($subcategory === 'dns') {
                $raw = $_POST['domains'] ?? [];
                if (is_string($raw)) {
                    $raw = array_map('trim', explode(',', $raw));
                }
                if (is_array($raw)) {
                    foreach ($raw as $d) {
                        $d = rtrim(strtolower(trim((string)$d)), '.');
                        if ($d !== '') {
                            $domains[] = $d;
                        }
                    }
                }
                $domains = array_values(array_unique($domains));
                if (empty($domains)) {
                    send_json(400, ['ok' => false, 'error' => 'Sélectionnez au moins un domaine concerné.']);
                }
            }

            // ⚠️ author_name / author_email NE SONT PLUS ENVOYÉS. Les stocker
            // revenait à figer l'identité de l'auteur au moment de la création :
            // un changement de nom ou d'adresse laissait les anciens tickets
            // sur l'ancienne valeur, et la même donnée vivait à deux endroits.
            // La base ne garde que client_id ; le nom et l'e-mail sont résolus
            // à la lecture depuis Keycloak (tickets_attach_authors()).
            $resp = n8n_call([
                'action'            => 'ticket.create',
                'client_id'         => $clientId,
                'siret'             => $currentSiret,
                'structure'         => $sessionStructure,
                'subject'           => $subject,
                'message'           => $message,
                'category'          => $category,
                'subcategory'       => $subcategory,
                // Service concerné : uid de la ligne de commande + famille du
                // produit, tous deux relus dans le catalogue côté serveur.
                'product_uid'       => $productUid,
                'esp_cli_menu_name' => $productMenu,
                'domains'           => implode(', ', $domains),
                'priority'          => $priority,
                'status'            => 'ouvert',
            ]);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'La création du ticket a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'La création du ticket a échoué.')]);
            }

            $row = null;
            if (is_array($resp['json'])) {
                if (isset($resp['json']['row']) && is_array($resp['json']['row'])) {
                    $row = $resp['json']['row'];
                } elseif (isset($resp['json']['ticket']) && is_array($resp['json']['ticket'])) {
                    $row = $resp['json']['ticket'];
                } else {
                    $rows = extract_rows($resp['json'], ['tickets'], ['id', 'ref']);
                    $row  = $rows[0] ?? null;
                }
            }

            $created = null;
            if (is_array($row)) {
                $created = normalize_ticket($row);
                // n8n renvoie la ligne telle qu'insérée : ni nom d'auteur, ni
                // libellé de service. On complète avec ce que l'on sait déjà,
                // sans refaire d'appel.
                if ($created['created_by'] === '') {
                    $created['created_by'] = user_display_name($user);
                }
                if ($created['author_email'] === '') {
                    $created['author_email'] = (string)($user['email'] ?? '');
                }
                if ($created['product_uid'] === '' && $productUid !== '') {
                    $created['product_uid'] = $productUid;
                }
                if ($created['product_name'] === '') {
                    $created['product_name'] = $productName;
                }
                if ($created['esp_cli_menu_name'] === '' && $productMenu !== '') {
                    $created['esp_cli_menu_name'] = $productMenu;
                    $created['menu_label']        = ticket_menu_label($productMenu);
                }
            }

            send_json(200, [
                'ok'      => true,
                'message' => 'Votre ticket a été créé.',
                'ticket'  => $created,
            ]);
        }

        case 'ticket.reply': {
            require_post();
            csrf_check();

            $ticketId = trim((string)($_POST['ticket_id'] ?? ''));
            $body     = trim((string)($_POST['body'] ?? ''));
            if ($ticketId === '') {
                send_json(400, ['ok' => false, 'error' => 'Ticket invalide.']);
            }
            if (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
                send_json(400, ['ok' => false, 'error' => 'Le message doit contenir entre 1 et 5000 caractères.']);
            }

            $authorName = user_display_name($user);

            $resp = n8n_call([
                'action'      => 'ticket.reply',
                'client_id'   => $clientId,
                'ticket_id'   => $ticketId,
                'body'        => $body,
                'author_type' => 'client',
                'author_name' => $authorName,
            ]);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'L’envoi de la réponse a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'L’envoi de la réponse a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => 'Réponse envoyée.']);
        }

        case 'ticket.close': {
            require_post();
            csrf_check();

            $ticketId = trim((string)($_POST['ticket_id'] ?? ''));
            if ($ticketId === '') {
                send_json(400, ['ok' => false, 'error' => 'Ticket invalide.']);
            }
            $reopen = truthy($_POST['reopen'] ?? '0');

            $resp = n8n_call([
                'action'    => $reopen ? 'ticket.reopen' : 'ticket.close',
                'client_id' => $clientId,
                'ticket_id' => $ticketId,
                'status'    => $reopen ? 'ouvert' : 'ferme',
            ]);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'L’opération a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'L’opération a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => $reopen ? 'Ticket rouvert.' : 'Ticket clôturé.']);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  CONSOLE SUPPORT (équipe GNL) — accès réservé via require_support()
        // ─────────────────────────────────────────────────────────────────────
        case 'support.ticket.list': {
            require_support($user);

            $resp = n8n_call([
                'action'   => 'support.ticket.list',
                'support'  => 1,
                'agent_id' => $clientId,
                'status'   => s_lower(trim((string)($_GET['status'] ?? ''))),
            ]);
            ensure_ok($resp);

            $rows    = extract_rows($resp['json'], ['tickets'], ['id', 'ref', 'reference']);
            $tickets = array_map('normalize_ticket', $rows);
            // Console support : les tickets viennent de clients DIFFÉRENTS.
            // Keycloak sait nommer n'importe lequel d'entre eux ; le catalogue,
            // lui, est celui de l'agent connecté et ne résoudra donc pas leurs
            // product_uid — c'est voulu, l'agent n'a pas à voir leur catalogue.
            $tickets = tickets_attach_authors($tickets);
            usort($tickets, static function (array $a, array $b): int {
                return ($b['updated_ts'] ?? $b['created_ts'] ?? 0) <=> ($a['updated_ts'] ?? $a['created_ts'] ?? 0);
            });

            send_json(200, ['ok' => true, 'count' => count($tickets), 'tickets' => $tickets]);
        }

        case 'support.ticket.detail': {
            require_support($user);

            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » requis.']);
            }

            $resp = n8n_call([
                'action'  => 'support.ticket.detail',
                'support' => 1,
                'id'      => $id,
            ]);
            ensure_ok($resp);

            [$ticketRow, $messages] = extract_ticket_and_messages($resp['json']);
            $messages = tickets_attach_message_authors($messages);

            // Style WhatsApp : « mes » messages (= cet agent support) à droite + nom canonique.
            // Même correction que ticket.detail : on compare l'UID Keycloak, et
            // non (int) de cet UID, qui vaut 0 une fois sur trois.
            $me     = $clientUid !== '' ? $clientUid : (string) $accountId;
            $myName = user_display_name($user);
            foreach ($messages as &$m) {
                $mid  = (string)($m['client_id'] ?? '');
                $aid  = (string)($m['agent_id'] ?? '');
                $mine = ($me !== '' && (($mid !== '' && $mid === $me) || ($aid !== '' && $aid === $me)));
                $m['mine'] = $mine;
                if ($mine && $myName !== '') {
                    $m['author'] = $myName;
                }
            }
            unset($m);

            if ($ticketRow !== null) {
                $one    = tickets_attach_authors([normalize_ticket($ticketRow)]);
                $ticket = $one[0];
                $ticket['messages'] = $messages;
                $ticket['mine_owner'] = false; // le créateur est le client, pas l'agent
            } else {
                $ticket = ['id' => is_numeric($id) ? (int) $id : $id, 'messages' => $messages, 'mine_owner' => false];
            }

            send_json(200, ['ok' => true, 'ticket' => $ticket]);
        }

        case 'support.ticket.reply': {
            require_support($user);
            require_post();
            csrf_check();

            $ticketId = trim((string)($_POST['ticket_id'] ?? ''));
            $body     = trim((string)($_POST['body'] ?? ''));
            if ($ticketId === '') {
                send_json(400, ['ok' => false, 'error' => 'Ticket invalide.']);
            }
            if (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
                send_json(400, ['ok' => false, 'error' => 'Le message doit contenir entre 1 et 5000 caractères.']);
            }

            $resp = n8n_call([
                'action'      => 'support.ticket.reply',
                'support'     => 1,
                'agent_id'    => $clientId,
                'ticket_id'   => $ticketId,
                'body'        => $body,
                'author_type' => 'support',
                'author_name' => user_display_name($user),
            ]);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'L’envoi de la réponse a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'L’envoi de la réponse a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => 'Réponse envoyée.']);
        }

        case 'support.ticket.update': {
            require_support($user);
            require_post();
            csrf_check();

            $ticketId = trim((string)($_POST['ticket_id'] ?? ''));
            if ($ticketId === '') {
                send_json(400, ['ok' => false, 'error' => 'Ticket invalide.']);
            }

            $payload   = ['action' => 'support.ticket.update', 'support' => 1, 'agent_id' => $clientId, 'ticket_id' => $ticketId];
            $hasChange = false;
            if (isset($_POST['status']) && trim((string)$_POST['status']) !== '') {
                $payload['status'] = ticket_status_key($_POST['status']);
                $hasChange = true;
            }
            if (isset($_POST['priority']) && trim((string)$_POST['priority']) !== '') {
                $payload['priority'] = ticket_priority_key($_POST['priority']);
                $hasChange = true;
            }
            if (!$hasChange) {
                send_json(400, ['ok' => false, 'error' => 'Aucune modification fournie.']);
            }

            $resp = n8n_call($payload);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'La mise à jour a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'La mise à jour a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => 'Ticket mis à jour.']);
        }

        // ─────────────────────────────────────────────────────────────────────
        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue : ' . $action]);
    }
} catch (Throwable $e) {
    // 1) Journaliser : c'est la seule trace exploitable. Le set_error_handler
    //    ci-dessus transforme le moindre warning PHP en ErrorException, qui
    //    atterrit ici — sans log, l'origine est introuvable.
    error_log(sprintf(
        '[portail_api] action=%s %s: %s @ %s:%d',
        $action, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));

    // 2) HTTP 200 volontaire, avec le vrai statut dans « code ».
    //    L'Ingress porte le middleware Traefik « custom-errors », qui remplace
    //    le CORPS de toute réponse 5xx par une page générique
    //    ({"error":true,"code":502,"message":"Bad Gateway"}). Renvoyer 502 ici
    //    revenait donc à effacer le message d'erreur avant qu'il n'atteigne le
    //    navigateur. « ok: false » reste le signal d'échec pour les appelants.
    send_json(200, [
        'ok'    => false,
        'code'  => 502,
        'error' => $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
}