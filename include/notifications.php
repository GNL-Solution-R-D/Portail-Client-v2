<?php

/**
 * include/notifications.php
 *
 * Couche notifications côté serveur — parle au webhook n8n dédié
 * « data-notification » (workflow n8n « Notification + Rename »), qui pilote la
 * Data Table n8n « notification_portail » (1 ligne = 1 notification, colonne
 * client_id = UID Keycloak du destinataire).
 *
 * Utilisé par :
 *   - data/portail_api.php  → actions navigateur notification.list / notification.read
 *                             (cloche de include/header.php) ;
 *   - n'importe quel flux serveur → notify(...) pour créer une notification.
 *
 * Contrat n8n (webhook « data-notification », GET = lecture / POST = écriture) :
 *   GET  ?action=list&client_id=…&limit=20
 *        → [ {ligne}, ... ]   OU   { notifications:[...], unread:N }   (vide si aucune)
 *   POST { action:'read',   client_id, all:1 }          → { ok:true }   (tout marquer lu)
 *   POST { action:'read',   client_id, all:0, id:"…" }  → { ok:true }
 *   POST { action:'create', client_id, type, title, message, link } → { ok:true }
 *
 * Variables d'environnement :
 *   N8N_DATA_NOTIFICATION_URL  (déf. https://api.gnl-solution.fr/webhook/data-notification)
 *   N8N_WEBHOOK_TOKEN          (jeton « Header Auth » du webhook, optionnel)
 *
 * ⚠️ client_id est une CHAÎNE (UID Keycloak) : l'ancien typage int le
 *    transformait en 0 (ou en chiffres de tête) pour la plupart des comptes.
 */

declare(strict_types=1);

if (!defined('NOTIF_DEFAULT_URL')) {
    define('NOTIF_DEFAULT_URL', 'https://api.gnl-solution.fr/webhook/data-notification');
}

if (!function_exists('notif_getenv_non_empty')) {
    /** Variable d'environnement uniquement si définie ET non vide après trim. */
    function notif_getenv_non_empty(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false) {
            return null;
        }
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}

if (!function_exists('notif_n8n_url')) {
    function notif_n8n_url(): string
    {
        return notif_getenv_non_empty('N8N_DATA_NOTIFICATION_URL') ?? NOTIF_DEFAULT_URL;
    }
}

if (!function_exists('notif_session_uid')) {
    /** UID Keycloak de l'utilisateur connecté (même règle que portailUserUid()). */
    function notif_session_uid(): string
    {
        $u = (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : [];
        return trim((string)($u['keycloak_uid'] ?? $u['sub'] ?? $u['id'] ?? ''));
    }
}

if (!function_exists('notif_n8n_call')) {
    /**
     * Relaie un payload au webhook n8n et renvoie la réponse décodée.
     * En GET, le payload part en query string ; sinon en JSON.
     *
     * @return array{status:int, json:mixed, raw:string}
     */
    function notif_n8n_call(array $payload, string $method = 'POST'): array
    {
        $url     = notif_n8n_url();
        $token   = notif_getenv_non_empty('N8N_WEBHOOK_TOKEN');
        $method  = strtoupper($method);
        $isGet   = ($method === 'GET');
        $headers = ['Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
            $headers[] = 'X-GNL-Token: ' . $token;
        }

        $body = null;
        if ($isGet) {
            $url .= ((strpos($url, '?') === false) ? '?' : '&') . http_build_query($payload);
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
            $raw = @file_get_contents($url, false, stream_context_create(['http' => $httpOpts]));
            if ($raw === false) {
                throw new RuntimeException('Connexion n8n impossible.');
            }
            $status = 0;
            // PHP 8.5 : $http_response_header est déprécié ; http_get_last_response_headers() existe depuis 8.4.
            foreach ((function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : (${'http_response_header'} ?? [])) as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int)$m[1];
                }
            }
            $raw = (string)$raw;
        }

        // n8n répond un corps VIDE quand la Data Table ne renvoie aucune ligne.
        $json = (trim($raw) === '') ? [] : json_decode($raw, true);
        return ['status' => $status, 'json' => $json, 'raw' => $raw];
    }
}

if (!function_exists('notif_truthy')) {
    /** true/false depuis une valeur n8n hétérogène (bool, 0/1, "true", "t", "oui"). */
    function notif_truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_array($v) || is_object($v)) {
            return false;
        }
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 't', 'yes', 'oui', 'on'], true);
    }
}

if (!function_exists('notif_extract_rows')) {
    /** Extrait la liste de lignes depuis une réponse n8n tolérante au format. */
    function notif_extract_rows($json): array
    {
        $unwrap = static function ($v) {
            return (is_array($v) && isset($v['json']) && is_array($v['json'])) ? $v['json'] : $v;
        };

        if (!is_array($json)) {
            return [];
        }
        foreach (['notifications', 'data', 'results', 'rows', 'items'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $json = $json[$key];
                break;
            }
        }
        if ($json === [] || array_key_exists(0, $json)) {
            return array_values(array_filter(array_map($unwrap, array_values($json)), 'is_array'));
        }
        if (isset($json['json']) && is_array($json['json'])) {
            return [$json['json']];
        }
        if (isset($json['id']) || isset($json['title'])) {
            return [$json];
        }
        return [];
    }
}

if (!function_exists('notif_normalize')) {
    /**
     * Normalise une ligne n8n vers la forme attendue par la cloche :
     *   { id, type, title, message, link, is_read(bool), created_at }
     * Tolère plusieurs noms de colonnes (is_read/read/seen/lu, createdAt/created_at...).
     */
    function notif_normalize(array $row): array
    {
        $pick = static function (array $r, array $keys, $default = '') {
            foreach ($keys as $k) {
                if (!array_key_exists($k, $r) || $r[$k] === null || is_array($r[$k]) || is_object($r[$k])) {
                    continue;
                }
                if (is_bool($r[$k]) || trim((string)$r[$k]) !== '') {
                    return $r[$k];
                }
            }
            return $default;
        };

        $readAt = trim((string)$pick($row, ['read_at', 'readAt', 'lu_le', 'seen_at'], ''));
        $isRead = notif_truthy($pick($row, ['is_read', 'read', 'seen', 'lu'], false)) || ($readAt !== '');

        $link = trim((string)$pick($row, ['link', 'url', 'href', 'lien'], ''));
        // Seuls les liens internes (/…) ou http(s) sont rendus cliquables.
        if ($link !== '' && !preg_match('#^(/(?!/)|https?://)#i', $link)) {
            $link = '';
        }

        return [
            'id'         => (string)$pick($row, ['id', '_id', 'uuid'], ''),
            'type'       => strtolower((string)$pick($row, ['type', 'category', 'categorie'], 'info')),
            'title'      => (string)$pick($row, ['title', 'titre', 'subject', 'objet'], ''),
            'message'    => (string)$pick($row, ['message', 'body', 'text', 'contenu'], ''),
            'link'       => $link,
            'is_read'    => $isRead,
            'created_at' => (string)$pick($row, ['created_at', 'createdAt', 'date', 'created'], ''),
        ];
    }
}

if (!function_exists('notif_list')) {
    /**
     * Liste normalisée (plus récentes d'abord) + nombre de non-lus pour un client.
     *
     * @return array{status:int, notifications:array<int,array>, unread:int}
     */
    function notif_list(string $clientUid, int $limit = 20): array
    {
        $resp = notif_n8n_call([
            'action'    => 'list',
            'client_id' => $clientUid,
            'limit'     => $limit,
        ], 'GET');

        $rows = [];
        foreach (notif_extract_rows($resp['json']) as $r) {
            $n = notif_normalize($r);
            // Ignore les items vides ({}), p. ex. « Always Output Data » côté n8n.
            if ($n['id'] !== '' || $n['title'] !== '' || $n['message'] !== '') {
                $rows[] = $n;
            }
        }

        // La Data Table n8n ne trie pas : plus récentes d'abord, puis limite.
        usort($rows, static function (array $a, array $b): int {
            $ta = strtotime($a['created_at']) ?: 0;
            $tb = strtotime($b['created_at']) ?: 0;
            return ($tb <=> $ta) ?: strnatcmp($b['id'], $a['id']);
        });

        // unread : champ racine n8n (compte GLOBAL) prioritaire, sinon décompte
        // sur TOUTES les lignes reçues (avant la limite d'affichage).
        $unread = null;
        if (is_array($resp['json']) && isset($resp['json']['unread']) && is_numeric($resp['json']['unread'])) {
            $unread = (int)$resp['json']['unread'];
        }
        if ($unread === null) {
            $unread = 0;
            foreach ($rows as $r) {
                if (!$r['is_read']) {
                    $unread++;
                }
            }
        }

        return [
            'status'        => $resp['status'],
            'notifications' => array_slice($rows, 0, max(1, $limit)),
            'unread'        => $unread,
        ];
    }
}

if (!function_exists('notif_mark_read')) {
    /**
     * Marque une notification (ou toutes si $id vide/null) comme lue.
     *
     * @return array{status:int, json:mixed}
     */
    function notif_mark_read(string $clientUid, ?string $id): array
    {
        $payload = ['action' => 'read', 'client_id' => $clientUid];
        if ($id === null || $id === '') {
            $payload['all'] = 1;
        } else {
            $payload['all'] = 0;
            $payload['id']  = $id;
        }
        $resp = notif_n8n_call($payload, 'POST');
        return ['status' => $resp['status'], 'json' => $resp['json']];
    }
}

if (!function_exists('notify')) {
    /**
     * Crée une notification pour un client (usage MANUEL ou AUTOMATIQUE).
     *
     * Exemple :
     *   require_once __DIR__ . '/../include/notifications.php';
     *   notify(notif_session_uid(), 'Commande confirmée',
     *          'Votre commande a bien été enregistrée.', '/commande', 'order');
     *
     * Nécessite la branche « create » du workflow n8n « Notification + Rename ».
     *
     * @param string $clientUid destinataire (UID Keycloak)
     * @param string $type      info|success|warning|error|order|invoice|subscription|team
     * @return bool  true si n8n a répondu en 2xx
     */
    function notify(string $clientUid, string $title, string $message = '', string $link = '', string $type = 'info'): bool
    {
        $clientUid = trim($clientUid);
        if ($clientUid === '' || $clientUid === '0' || trim($title) === '') {
            return false;
        }
        try {
            $resp = notif_n8n_call([
                'action'    => 'create',
                'client_id' => $clientUid,
                'type'      => $type,
                'title'     => $title,
                'message'   => $message,
                'link'      => $link,
            ], 'POST');
        } catch (\Throwable $e) {
            return false;
        }
        return $resp['status'] >= 200 && $resp['status'] < 300;
    }
}
