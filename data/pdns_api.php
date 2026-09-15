<?php

/**
 * data/pdns_api.php
 *
 * Proxy serveur entre pages/zdns.php et l'API REST PowerDNS.
 *
 * Remplace le chemin historique « navigateur → data/portail_api.php → webhook
 * n8n → registrar » pour les zones hébergées chez nous : un aller-retour au
 * lieu de deux, et la source de vérité est le serveur DNS lui-même.
 *
 * ⚠️ n8n reste indispensable pour le CONTRÔLE D'ACCÈS : la liste des domaines
 * d'un client n'existe que là. Chaque action revérifie que le domaine demandé
 * figure dans `domain.list` pour le client connecté (client_id injecté serveur
 * par portailApiCall, non falsifiable). Un domaine absent de cette liste donne
 * 403 — même s'il existe dans PowerDNS, même si l'utilisateur le devine.
 * Sans cette vérification, n'importe quel compte lirait toutes les zones.
 *
 * ── Actions (mêmes noms que data/portail_api.php, pour un diff minimal) ──────
 *   domain.records        GET   ?domain=…                → { ok, domain, zone, records:[…] }
 *   domain.add_record     POST  CSRF  domain,type,name,content,ttl → { ok }
 *   domain.delete_record  POST  CSRF  domain,id          → { ok }
 *   diag                  GET   ?domain=…                → { ok, diag:{…} }
 *
 * `diag` ne révèle JAMAIS la clé API : seulement l'URL résolue, la présence de
 * la clé, et le code HTTP renvoyé par PowerDNS. Il est appelé par la page quand
 * `domain.records` échoue, pour que la cause soit lisible sans ouvrir les logs.
 *
 * ⚠️ Ce endpoint ne renvoie JAMAIS de 5xx : l'Ingress porte le middleware
 * Traefik « custom-errors » qui remplace le CORPS de toute réponse 5xx par une
 * page générique, effaçant le message. Convention du portail : HTTP 200,
 * « ok: false », vrai statut dans « code ». Les 4xx restent de vrais 4xx.
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
require_once __DIR__ . '/../include/portail_api_client.php';
require_once __DIR__ . '/PowerDnsClient.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Durée du cache session de la liste des domaines du client. */
const PDNS_DOMAINS_TTL = 120;

/** Types d'enregistrement que la page propose — même liste que portail_api.php. */
const PDNS_ALLOWED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

/** Types dont la valeur est un nom d'hôte : le point final est obligatoire. */
const PDNS_HOSTNAME_TYPES = ['CNAME', 'NS', 'MX', 'SRV'];

// ─────────────────────────────────────────────────────────────────────────────
//  Réponses
// ─────────────────────────────────────────────────────────────────────────────

function pdns_send(int $status, array $payload): void
{
    // Jamais de 5xx : « custom-errors » effacerait le message (voir l'en-tête).
    if ($status >= 500) {
        $payload['code'] = $status;
        $status = 200;
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pdns_require_post(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        pdns_send(405, ['ok' => false, 'error' => 'Méthode non autorisée (POST requis).']);
    }
}

function pdns_csrf_check(): void
{
    $sent    = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $session = (string) ($_SESSION['csrf'] ?? '');
    if ($session === '' || $sent === '' || !hash_equals($session, $sent)) {
        pdns_send(403, ['ok' => false, 'error' => 'Jeton CSRF invalide.']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Noms et valeurs
// ─────────────────────────────────────────────────────────────────────────────

function pdns_is_domain_name(string $v): bool
{
    $v = rtrim(strtolower(trim($v)), '.');
    if ($v === '' || strlen($v) > 253) {
        return false;
    }
    return (bool) preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $v);
}

/** « www.exemple.fr. » dans la zone « exemple.fr. » → « www » ; l'apex → « @ ». */
function pdns_short_name(string $fqdn, string $zone): string
{
    $fqdn = strtolower(rtrim(trim($fqdn), '.'));
    $zone = strtolower(rtrim(trim($zone), '.'));
    if ($fqdn === $zone || $fqdn === '') {
        return '@';
    }
    $suffix = '.' . $zone;
    if (str_ends_with($fqdn, $suffix)) {
        return substr($fqdn, 0, -strlen($suffix));
    }
    return $fqdn;
}

/** « www » ou « @ » dans la zone « exemple.fr. » → « www.exemple.fr. ». */
function pdns_full_name(string $name, string $zone): string
{
    $zone = PowerDnsClient::canonicalZone($zone);
    $name = strtolower(trim($name));
    if ($name === '' || $name === '@') {
        return $zone;
    }
    $name = rtrim($name, '.');
    $bare = rtrim($zone, '.');
    if ($name === $bare || str_ends_with($name, '.' . $bare)) {
        return $name . '.';   // l'utilisateur a saisi le FQDN complet
    }
    return $name . '.' . $zone;
}

/**
 * Met la valeur à la forme attendue par PowerDNS.
 *
 * Deux corrections silencieuses, qui évitent les deux erreurs les plus
 * fréquentes d'une interface DNS :
 *   • TXT non guillemeté → PowerDNS refuse. On guillemette.
 *   • cible d'un CNAME / NS / MX / SRV sans point final → l'enregistrement
 *     devient relatif à la zone (« www.exemple.fr.exemple.fr. »). On ajoute
 *     le point, sauf si la cible est un simple label sans point (« @ », « www »),
 *     qui est alors volontairement relatif.
 */
function pdns_normalize_content(string $type, string $content): string
{
    $content = trim($content);
    if ($content === '') {
        return $content;
    }

    if ($type === 'TXT') {
        if (!str_starts_with($content, '"')) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $content) . '"';
        }
        return $content;
    }

    if (in_array($type, PDNS_HOSTNAME_TYPES, true)) {
        // MX et SRV : la priorité (et le poids/port) précèdent la cible.
        $parts  = preg_split('/\s+/', $content) ?: [$content];
        $last   = (string) array_pop($parts);
        if ($last !== '' && str_contains($last, '.') && !str_ends_with($last, '.')) {
            $last .= '.';
        }
        $parts[] = $last;
        return implode(' ', $parts);
    }

    return $content;
}

/**
 * Identifiant stable d'un enregistrement.
 *
 * PowerDNS n'en donne pas : un rrset regroupe plusieurs valeurs sous un même
 * (nom, type). On dérive donc l'identifiant du triplet, ce qui suffit à le
 * retrouver pour le supprimer, et reste stable entre deux chargements.
 */
function pdns_record_id(string $type, string $name, string $content): string
{
    $raw = $type . "\x1F" . $name . "\x1F" . $content;
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** @return array{0:string,1:string,2:string}|null [type, name, content] */
function pdns_record_id_decode(string $id): ?array
{
    $b64 = strtr($id, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $raw = base64_decode($b64, true);
    if (!is_string($raw)) {
        return null;
    }
    $parts = explode("\x1F", $raw);
    if (count($parts) !== 3) {
        return null;
    }
    return [$parts[0], $parts[1], $parts[2]];
}

// ─────────────────────────────────────────────────────────────────────────────
//  Authentification
// ─────────────────────────────────────────────────────────────────────────────

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    pdns_send(401, ['ok' => false, 'error' => 'Non authentifié.']);
}

$clientId = (int) ($_SESSION['user']['id'] ?? 0);
if ($clientId <= 0) {
    pdns_send(401, ['ok' => false, 'error' => 'Identifiant client introuvable dans la session.']);
}

if (isset($pdo) && $pdo instanceof PDO) {
    if (accountSessionsIsCurrentSessionRevoked($pdo, $clientId)) {
        accountSessionsDestroyPhpSession();
        pdns_send(401, ['ok' => false, 'error' => 'Cette session a été déconnectée depuis vos paramètres.']);
    }
    accountSessionsTouchCurrent($pdo, $clientId);
}

// ─────────────────────────────────────────────────────────────────────────────
//  Contrôle d'accès — la liste des domaines du client vient de n8n
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Domaines du client connecté, en cache session 120 s.
 *
 * @param bool $fresh ignore le cache (une commande toute fraîche ne doit pas
 *                    donner un 403 pendant deux minutes — même logique que
 *                    servicesCatalogFindByUid()).
 * @return array<string,true> domaines en minuscules, sans point final
 */
function pdns_client_domains(bool $fresh = false): array
{
    $cache = $_SESSION['pdns_domains_cache'] ?? null;
    if (
        !$fresh
        && is_array($cache)
        && isset($cache['ts'], $cache['domains'])
        && is_array($cache['domains'])
        && (time() - (int) $cache['ts']) < PDNS_DOMAINS_TTL
    ) {
        return $cache['domains'];
    }

    $resp = portailApiCall(['action' => 'domain.list']);
    $json = $resp['json'] ?? null;

    $rows = [];
    if (is_array($json)) {
        foreach (['domains', 'data', 'results', 'rows', 'items'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $json = $json[$key];
                break;
            }
        }
        if (is_array($json)) {
            $rows = array_key_exists(0, $json) ? $json : [$json];
        }
    }

    $domains = [];
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['json']) && is_array($row['json'])) {
            $row = $row['json'];   // format d'item n8n { "json": {...} }
        }
        if (!is_array($row)) {
            continue;
        }
        foreach (['domain_buy_name', 'domain', 'name', 'domaine'] as $k) {
            $v = $row[$k] ?? null;
            if (is_string($v) && $v !== '') {
                $domains[rtrim(strtolower(trim($v)), '.')] = true;
                break;
            }
        }
    }

    $_SESSION['pdns_domains_cache'] = ['ts' => time(), 'domains' => $domains];
    return $domains;
}

/** 403 si le domaine n'appartient pas au client connecté. */
function pdns_require_domain(string $domain): string
{
    $bare = rtrim(strtolower(trim($domain)), '.');
    $bare = (string) preg_replace('~^\*\.~', '', $bare);

    if (!pdns_is_domain_name($bare)) {
        pdns_send(400, ['ok' => false, 'error' => 'Nom de domaine invalide.']);
    }

    $domains = pdns_client_domains();
    if (!isset($domains[$bare])) {
        // Seconde chance sans cache : domaine acheté il y a moins de 2 minutes.
        $domains = pdns_client_domains(true);
    }
    if (!isset($domains[$bare])) {
        // Pas de distinction entre « pas à vous » et « n'existe pas » —
        // même règle que servicesCatalogFindByUid().
        pdns_send(403, ['ok' => false, 'error' => 'Ce domaine n\'est pas rattaché à votre compte.']);
    }

    return $bare;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Client PowerDNS
// ─────────────────────────────────────────────────────────────────────────────

$pdns = PowerDnsClient::fromConfig();
$action = trim((string) ($_REQUEST['action'] ?? ''));

if ($action === '') {
    pdns_send(400, ['ok' => false, 'error' => 'Action manquante.']);
}

if ($pdns === null && $action !== 'diag') {
    pdns_send(500, [
        'ok'    => false,
        'error' => 'PowerDNS n\'est pas configuré (PDNS_API_KEY absente du Secret du portail).',
    ]);
}

try {
    switch ($action) {

        // ── Lecture de la zone ───────────────────────────────────────────────
        case 'domain.records': {
            $domain = pdns_require_domain((string) ($_GET['domain'] ?? ''));
            $zone   = PowerDnsClient::canonicalZone($domain);

            try {
                $data = $pdns->getZone($zone);
            } catch (PowerDnsException $e) {
                if ($e->httpStatus() === 404) {
                    // La zone n'existe pas encore côté PowerDNS : ce n'est pas
                    // une erreur pour la page, c'est une zone vide.
                    pdns_send(200, [
                        'ok'      => true,
                        'domain'  => $domain,
                        'zone'    => $zone,
                        'records' => [],
                        'notice'  => 'Zone absente de PowerDNS.',
                    ]);
                }
                throw $e;
            }

            $includeSoa = ((string) ($_GET['include_soa'] ?? '')) === '1';
            $records    = [];

            foreach (($data['rrsets'] ?? []) as $rr) {
                if (!is_array($rr)) {
                    continue;
                }
                $type = strtoupper((string) ($rr['type'] ?? ''));
                if ($type === '' || ($type === 'SOA' && !$includeSoa)) {
                    continue;
                }
                $fqdn = (string) ($rr['name'] ?? '');
                $ttl  = (int) ($rr['ttl'] ?? 0);

                foreach (($rr['records'] ?? []) as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $content = (string) ($r['content'] ?? '');
                    $records[] = [
                        'id'       => pdns_record_id($type, $fqdn, $content),
                        'type'     => $type,
                        'name'     => pdns_short_name($fqdn, $zone),
                        'fqdn'     => $fqdn,
                        'content'  => $content,
                        'ttl'      => $ttl,
                        'disabled' => (bool) ($r['disabled'] ?? false),
                    ];
                }
            }

            // Tri lisible : apex d'abord, puis par nom, puis par type.
            usort($records, static function (array $a, array $b): int {
                if ($a['name'] !== $b['name']) {
                    if ($a['name'] === '@') {
                        return -1;
                    }
                    if ($b['name'] === '@') {
                        return 1;
                    }
                    return strcmp($a['name'], $b['name']);
                }
                return strcmp($a['type'], $b['type']);
            });

            pdns_send(200, [
                'ok'      => true,
                'domain'  => $domain,
                'zone'    => $zone,
                'dnssec'  => (bool) ($data['dnssec'] ?? false),
                'serial'  => (int) ($data['serial'] ?? 0),
                'records' => $records,
            ]);
        }

        // ── Ajout d'un enregistrement ────────────────────────────────────────
        case 'domain.add_record': {
            pdns_require_post();
            pdns_csrf_check();

            $domain = pdns_require_domain((string) ($_POST['domain'] ?? ''));
            $zone   = PowerDnsClient::canonicalZone($domain);

            $type    = strtoupper(trim((string) ($_POST['type'] ?? '')));
            $name    = trim((string) ($_POST['name'] ?? ''));
            $content = trim((string) ($_POST['content'] ?? ''));
            $ttl     = (int) ($_POST['ttl'] ?? 3600);

            if (!in_array($type, PDNS_ALLOWED_TYPES, true)) {
                pdns_send(400, ['ok' => false, 'error' => 'Type d\'enregistrement non supporté.']);
            }
            if ($content === '') {
                pdns_send(400, ['ok' => false, 'error' => 'La valeur de l\'enregistrement est requise.']);
            }
            if ($ttl < 60) {
                $ttl = 60;
            }

            $fqdn = pdns_full_name($name, $zone);

            // Un CNAME à l'apex casse la zone (RFC 1034) : PowerDNS le refuse,
            // autant le dire clairement plutôt que de relayer un 422 obscur.
            if ($type === 'CNAME' && $fqdn === $zone) {
                pdns_send(400, [
                    'ok'    => false,
                    'error' => 'Un CNAME est interdit à la racine du domaine. Utilisez un A ou un AAAA.',
                ]);
            }

            $content = pdns_normalize_content($type, $content);

            // Un PATCH REPLACE écrase TOUT le rrset : il faut donc relire les
            // valeurs existantes de ce (nom, type) et les réémettre avec la
            // nouvelle. Sans ça, ajouter un second MX effacerait le premier.
            $existing = [];
            try {
                $data = $pdns->getZone($zone);
                foreach (($data['rrsets'] ?? []) as $rr) {
                    if (
                        is_array($rr)
                        && strtoupper((string) ($rr['type'] ?? '')) === $type
                        && strtolower((string) ($rr['name'] ?? '')) === strtolower($fqdn)
                    ) {
                        foreach (($rr['records'] ?? []) as $r) {
                            if (is_array($r) && isset($r['content'])) {
                                $existing[] = [
                                    'content'  => (string) $r['content'],
                                    'disabled' => (bool) ($r['disabled'] ?? false),
                                ];
                            }
                        }
                    }
                }
            } catch (PowerDnsException $e) {
                if ($e->httpStatus() !== 404) {
                    throw $e;
                }
                pdns_send(404, [
                    'ok'    => false,
                    'error' => 'La zone n\'existe pas encore sur le serveur DNS.',
                ]);
            }

            foreach ($existing as $r) {
                if ($r['content'] === $content) {
                    pdns_send(409, ['ok' => false, 'error' => 'Cet enregistrement existe déjà.']);
                }
            }

            $existing[] = ['content' => $content, 'disabled' => false];

            $pdns->patchRrsets($zone, [[
                'name'       => $fqdn,
                'type'       => $type,
                'ttl'        => $ttl,
                'changetype' => 'REPLACE',
                'records'    => $existing,
            ]]);

            pdns_send(200, [
                'ok'     => true,
                'action' => $action,
                'record' => [
                    'id'      => pdns_record_id($type, $fqdn, $content),
                    'type'    => $type,
                    'name'    => pdns_short_name($fqdn, $zone),
                    'content' => $content,
                    'ttl'     => $ttl,
                ],
            ]);
        }

        // ── Suppression d'un enregistrement ──────────────────────────────────
        case 'domain.delete_record': {
            pdns_require_post();
            pdns_csrf_check();

            $domain = pdns_require_domain((string) ($_POST['domain'] ?? ''));
            $zone   = PowerDnsClient::canonicalZone($domain);

            $id = trim((string) ($_POST['id'] ?? ''));
            if ($id === '') {
                pdns_send(400, ['ok' => false, 'error' => 'Identifiant d\'enregistrement manquant.']);
            }
            $decoded = pdns_record_id_decode($id);
            if ($decoded === null) {
                pdns_send(400, ['ok' => false, 'error' => 'Identifiant d\'enregistrement illisible.']);
            }
            [$type, $fqdn, $content] = $decoded;

            if ($type === 'SOA') {
                pdns_send(400, ['ok' => false, 'error' => 'Le SOA ne peut pas être supprimé.']);
            }

            // L'identifiant vient du navigateur : il dit quoi supprimer, jamais
            // OÙ. La zone reste celle validée ci-dessus, donc un identifiant
            // forgé ne peut pas atteindre la zone d'un autre client.
            $data     = $pdns->getZone($zone);
            $target   = null;
            $apexNs   = 0;
            foreach (($data['rrsets'] ?? []) as $rr) {
                if (!is_array($rr)) {
                    continue;
                }
                $rrType = strtoupper((string) ($rr['type'] ?? ''));
                $rrName = strtolower((string) ($rr['name'] ?? ''));
                if ($rrType === 'NS' && $rrName === strtolower($zone)) {
                    $apexNs = count($rr['records'] ?? []);
                }
                if ($rrType === $type && $rrName === strtolower($fqdn)) {
                    $target = $rr;
                }
            }

            if ($target === null) {
                pdns_send(404, ['ok' => false, 'error' => 'Enregistrement introuvable.']);
            }

            // Retirer le dernier NS de la racine rend la zone inexploitable et
            // n'est pas rattrapable depuis cette interface.
            if ($type === 'NS' && strtolower($fqdn) === strtolower($zone) && $apexNs <= 1) {
                pdns_send(400, [
                    'ok'    => false,
                    'error' => 'Impossible de supprimer le dernier serveur de noms de la zone.',
                ]);
            }

            $remaining = [];
            $found     = false;
            foreach (($target['records'] ?? []) as $r) {
                if (!is_array($r) || !isset($r['content'])) {
                    continue;
                }
                if ((string) $r['content'] === $content) {
                    $found = true;
                    continue;
                }
                $remaining[] = [
                    'content'  => (string) $r['content'],
                    'disabled' => (bool) ($r['disabled'] ?? false),
                ];
            }

            if (!$found) {
                pdns_send(404, ['ok' => false, 'error' => 'Enregistrement introuvable.']);
            }

            if ($remaining === []) {
                // Plus aucune valeur : c'est le rrset entier qui disparaît.
                $pdns->patchRrsets($zone, [[
                    'name'       => (string) $target['name'],
                    'type'       => $type,
                    'changetype' => 'DELETE',
                ]]);
            } else {
                $pdns->patchRrsets($zone, [[
                    'name'       => (string) $target['name'],
                    'type'       => $type,
                    'ttl'        => (int) ($target['ttl'] ?? 3600),
                    'changetype' => 'REPLACE',
                    'records'    => $remaining,
                ]]);
            }

            pdns_send(200, ['ok' => true, 'action' => $action]);
        }

        // ── Diagnostic ───────────────────────────────────────────────────────
        case 'diag': {
            $domain = trim((string) ($_GET['domain'] ?? ''));
            $diag   = [
                'configured' => $pdns !== null,
                'url'        => $pdns !== null ? $pdns->baseUrl() : PowerDnsClient::normalizeUrl(
                    (string) config('PDNS_API_URL', PowerDnsClient::DEFAULT_URL)
                ),
                'server_id'  => $pdns !== null ? $pdns->serverId() : (string) config('PDNS_SERVER_ID', 'localhost'),
                'api_key'    => $pdns !== null ? 'présente' : 'absente',  // jamais la valeur
                'domain'     => $domain,
            ];

            if ($pdns === null) {
                pdns_send(200, ['ok' => false, 'error' => 'PDNS_API_KEY absente.', 'diag' => $diag]);
            }

            try {
                $info                = $pdns->serverInfo();
                $diag['server']      = (string) ($info['daemon_type'] ?? '?') . ' ' . (string) ($info['version'] ?? '?');
                $diag['server_ok']   = true;
            } catch (PowerDnsException $e) {
                $diag['server_ok']    = false;
                $diag['server_error'] = $e->getMessage();
                $diag['http_status']  = $e->httpStatus();
            }

            if ($domain !== '' && pdns_is_domain_name($domain)) {
                $domains          = pdns_client_domains();
                $bare             = rtrim(strtolower($domain), '.');
                $diag['owned']    = isset($domains[$bare]);
                $diag['owned_of'] = count($domains);
            }

            pdns_send(200, ['ok' => (bool) ($diag['server_ok'] ?? false), 'diag' => $diag]);
        }

        default:
            pdns_send(400, ['ok' => false, 'error' => 'Action inconnue : ' . $action]);
    }
} catch (PowerDnsException $e) {
    $status = $e->httpStatus();
    // Les 4xx de PowerDNS sont des erreurs de saisie : on les relaie tels quels.
    // Tout le reste devient 502 — donc HTTP 200 + code, cf. pdns_send().
    if ($status < 400 || $status >= 500) {
        $status = 502;
    }
    pdns_send($status, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('pdns_api: ' . $e->getMessage());
    pdns_send(502, ['ok' => false, 'error' => 'Erreur interne du proxy DNS.']);
}
