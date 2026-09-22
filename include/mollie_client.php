<?php

declare(strict_types=1);

/**
 * include/mollie_client.php
 *
 * Accès DIRECT à l'API Mollie (v2) — remplace n8n pour la page /abonnements.
 *
 * ── Rattachement client ↔ Mollie ─────────────────────────────────────────────
 * L'identifiant client Mollie (« cst_… ») est porté par l'attribut utilisateur
 * Keycloak « moliecliid » (Realm settings › User profile, groupe
 * user-metadata). Il est lu côté serveur via l'Admin REST (kcAccGetUser) :
 * jamais depuis le navigateur, donc non falsifiable par le client.
 *
 *   - attribut absent / vide      → aucun abonnement listé (linked:false) ;
 *   - attribut hors format cst_…  → idem, avec un message dans le journal PHP ;
 *   - Mollie n'est JAMAIS appelé sans identifiant client : un appel
 *     GET /v2/subscriptions sans client listerait les abonnements de TOUS
 *     les clients du profil.
 *
 * ⚠️ L'attribut « moliecliid » doit rester NON modifiable par l'utilisateur
 *    (User profile › Permission : « Who can edit » = admin uniquement). Sinon,
 *    un client pourrait y coller l'identifiant Mollie d'un autre client depuis
 *    la console de compte Keycloak et voir ses abonnements.
 *
 * ── Configuration (Secret du portail) ───────────────────────────────────────
 *   MOLLIE_API_KEY    OBLIGATOIRE. Clé API du profil (live_… ou test_…), ou
 *                     jeton d'organisation (access_…).
 *   MOLLIE_API_URL    Défaut : https://api.mollie.com/v2
 *   MOLLIE_TESTMODE   1 = ajoute testmode=true (utile UNIQUEMENT avec un jeton
 *                     access_… ; une clé test_… est déjà en mode test).
 *   MOLLIE_TIMEOUT    Délai max d'un appel, en secondes. Défaut : 15.
 */

require_once __DIR__ . '/../config_loader.php';

if (!defined('MOLLIE_USER_ATTRIBUTE')) {
    define('MOLLIE_USER_ATTRIBUTE', 'moliecliid');
}

if (!function_exists('mollieConfigured')) {
    function mollieConfigured(): bool
    {
        return trim((string) config('MOLLIE_API_KEY', '')) !== '';
    }
}

if (!function_exists('mollieIsCustomerId')) {
    /** Format d'un identifiant client Mollie : cst_ + alphanumérique. */
    function mollieIsCustomerId(string $id): bool
    {
        return (bool) preg_match('/^cst_[A-Za-z0-9]{4,64}$/', $id);
    }
}

if (!function_exists('mollieIsSubscriptionId')) {
    function mollieIsSubscriptionId(string $id): bool
    {
        return (bool) preg_match('/^sub_[A-Za-z0-9]{4,64}$/', $id);
    }
}

if (!function_exists('mollieCustomerIdForSessionUser')) {
    /**
     * Identifiant client Mollie de l'utilisateur connecté, lu dans l'attribut
     * Keycloak « moliecliid ». Chaîne vide si absent ou invalide.
     *
     * @return array{id:string, error:string}  error non vide = Keycloak injoignable
     */
    function mollieCustomerIdForSessionUser(array $sessionUser): array
    {
        require_once __DIR__ . '/keycloak_account.php';

        $raw = '';
        $kcError = '';

        $uid = kcAccUserId($sessionUser);
        if ($uid !== '') {
            $u = kcAccGetUser($uid);
            if ($u['ok']) {
                $attrs = kcAccFlattenAttrs($u['user']['attributes'] ?? []);
                $raw = (string) ($attrs[MOLLIE_USER_ATTRIBUTE] ?? '');
            } else {
                $kcError = $u['error'];
            }
        } else {
            $kcError = "Identifiant Keycloak introuvable dans votre session.";
        }

        // Repli : claim déjà présent en session (si un mapper l'ajoute au jeton).
        if ($raw === '' && $kcError !== '') {
            $raw = (string) ($sessionUser[MOLLIE_USER_ATTRIBUTE] ?? '');
            if ($raw !== '') {
                $kcError = '';
            }
        }

        $raw = trim($raw);
        if ($raw !== '' && !mollieIsCustomerId($raw)) {
            error_log('[mollie] attribut ' . MOLLIE_USER_ATTRIBUTE . ' invalide pour ' . $uid . ' : « ' . $raw . ' » (attendu : cst_…)');
            $raw = '';
        }

        return ['id' => $raw, 'error' => $kcError];
    }
}

if (!function_exists('mollieRequest')) {
    /**
     * GET sur l'API Mollie. Ne lève jamais.
     *
     * @param string $pathOrUrl chemin relatif (« /customers/… ») ou URL absolue
     *                          Mollie (lien _links.next de la pagination).
     * @return array{status:int, json:array, error:string}
     */
    function mollieRequest(string $pathOrUrl, array $query = []): array
    {
        $key = trim((string) config('MOLLIE_API_KEY', ''));
        if ($key === '') {
            return ['status' => 0, 'json' => [], 'error' => 'Mollie n\'est pas configuré (MOLLIE_API_KEY absente du Secret du portail).'];
        }

        $base = rtrim(trim((string) config('MOLLIE_API_URL', 'https://api.mollie.com/v2')), '/');

        if (preg_match('#^https?://#i', $pathOrUrl)) {
            // On ne suit QUE des liens de pagination vers l'API configurée :
            // la clé API ne doit jamais partir vers un autre hôte.
            if (strpos($pathOrUrl, $base . '/') !== 0) {
                return ['status' => 0, 'json' => [], 'error' => 'Lien de pagination Mollie inattendu.'];
            }
            $url = $pathOrUrl;
        } else {
            $url = $base . '/' . ltrim($pathOrUrl, '/');
        }

        $testmode = in_array(strtolower(trim((string) config('MOLLIE_TESTMODE', ''))), ['1', 'true', 'yes', 'on'], true);
        if ($testmode && strpos($key, 'access_') === 0 && !isset($query['testmode'])) {
            $query['testmode'] = 'true';
        }
        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $timeout = max(3, (int) config('MOLLIE_TIMEOUT', 15));
        $headers = [
            'Authorization: Bearer ' . $key,
            'Accept: application/hal+json',
            'User-Agent: GNL-Portail-Client/2',
        ];

        $raw = false;
        $status = 0;
        $err = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET        => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if (curl_errno($ch)) {
                $err = curl_error($ch);
            }
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($url, false, $ctx);
            if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
            if ($raw === false) {
                $err = 'Connexion à Mollie impossible.';
            }
        }

        if ($err !== '' || $raw === false) {
            error_log('[mollie] ' . $url . ' : ' . $err);
            return ['status' => $status, 'json' => [], 'error' => 'Mollie est injoignable pour le moment.'];
        }

        $json = json_decode((string) $raw, true);
        $json = is_array($json) ? $json : [];

        if ($status < 200 || $status >= 300) {
            $detail = (string) ($json['detail'] ?? $json['title'] ?? '');
            error_log('[mollie] HTTP ' . $status . ' ' . $url . ($detail !== '' ? ' — ' . $detail : ''));
            $msg = $status === 401 ? 'Clé API Mollie refusée.'
                : ($status === 404 ? 'Client Mollie introuvable.'
                : 'Mollie a renvoyé HTTP ' . $status . ($detail !== '' ? ' — ' . $detail : ''));
            return ['status' => $status, 'json' => $json, 'error' => $msg];
        }

        return ['status' => $status, 'json' => $json, 'error' => ''];
    }
}

if (!function_exists('mollieListCustomerSubscriptions')) {
    /**
     * Tous les abonnements d'UN client Mollie (pagination suivie, 20 pages max).
     *
     * @return array{ok:bool, subscriptions:array, error:string, status:int}
     */
    function mollieListCustomerSubscriptions(string $customerId): array
    {
        if (!mollieIsCustomerId($customerId)) {
            return ['ok' => false, 'subscriptions' => [], 'error' => 'Identifiant client Mollie invalide.', 'status' => 400];
        }

        $out  = [];
        $next = '/customers/' . rawurlencode($customerId) . '/subscriptions';
        $query = ['limit' => 250];

        for ($page = 0; $page < 20 && $next !== ''; $page++) {
            $r = mollieRequest($next, $query);
            if ($r['error'] !== '') {
                return ['ok' => false, 'subscriptions' => [], 'error' => $r['error'], 'status' => $r['status']];
            }
            $items = $r['json']['_embedded']['subscriptions'] ?? [];
            foreach ((array) $items as $s) {
                if (is_array($s)) {
                    $out[] = $s;
                }
            }
            $next  = (string) ($r['json']['_links']['next']['href'] ?? '');
            $query = []; // l'URL « next » porte déjà ses paramètres
        }

        return ['ok' => true, 'subscriptions' => $out, 'error' => '', 'status' => 200];
    }
}

if (!function_exists('mollieGetCustomerSubscription')) {
    /**
     * Un abonnement, TOUJOURS lu sous le client : un sub_ d'un autre client
     * renvoie 404 côté Mollie.
     *
     * @return array{ok:bool, subscription:array, error:string, status:int}
     */
    function mollieGetCustomerSubscription(string $customerId, string $subscriptionId): array
    {
        if (!mollieIsCustomerId($customerId) || !mollieIsSubscriptionId($subscriptionId)) {
            return ['ok' => false, 'subscription' => [], 'error' => 'Identifiant invalide.', 'status' => 400];
        }
        $r = mollieRequest('/customers/' . rawurlencode($customerId) . '/subscriptions/' . rawurlencode($subscriptionId));
        if ($r['error'] !== '') {
            return ['ok' => false, 'subscription' => [], 'error' => $r['error'], 'status' => $r['status']];
        }
        return ['ok' => true, 'subscription' => $r['json'], 'error' => '', 'status' => 200];
    }
}

if (!function_exists('mollieIntervalLabel')) {
    /** « 1 month » → « Mensuel », « 12 months » → « Annuel », « 2 weeks » → « Toutes les 2 semaines ». */
    function mollieIntervalLabel(string $interval): string
    {
        $interval = strtolower(trim($interval));
        if (!preg_match('/^(\d+)\s*(day|week|month)s?$/', $interval, $m)) {
            return $interval !== '' ? $interval : '—';
        }
        $n = (int) $m[1];
        $unit = $m[2];

        if ($unit === 'month') {
            $named = [1 => 'Mensuel', 2 => 'Bimestriel', 3 => 'Trimestriel', 6 => 'Semestriel', 12 => 'Annuel', 24 => 'Tous les 2 ans'];
            return $named[$n] ?? ('Tous les ' . $n . ' mois');
        }
        if ($unit === 'week') {
            return $n === 1 ? 'Hebdomadaire' : 'Toutes les ' . $n . ' semaines';
        }
        return $n === 1 ? 'Quotidien' : 'Tous les ' . $n . ' jours';
    }
}
