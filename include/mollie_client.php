<?php

declare(strict_types=1);

/**
 * include/mollie_client.php
 *
 * Accès DIRECT à l'API Mollie (v2) — remplace n8n pour la page /abonnements.
 *
 * ── Rattachement client ↔ Mollie ─────────────────────────────────────────────
 * L'identifiant client Mollie (« cst_… ») est porté par l'attribut
 * d'ORGANISATION Keycloak « moliecliid » (Organizations › <org> › Attributes).
 * Tous les membres de l'organisation voient donc les mêmes abonnements.
 *
 * L'organisation est celle retenue à la connexion (kc_org_id en session),
 * résolue par kcOrgResolveCurrent() puis relue via l'Admin REST
 * (GET /organizations/{id}). Repli : les attributs d'organisation du jeton
 * (kc_org_attributes, signés par Keycloak) si l'Admin REST est injoignable.
 * Jamais depuis le navigateur : non falsifiable par le client.
 *
 *   - attribut absent / vide      → aucun abonnement listé (linked:false) ;
 *   - attribut hors format cst_…  → idem, avec un message dans le journal PHP ;
 *   - Mollie n'est JAMAIS appelé sans identifiant client : un appel
 *     GET /v2/subscriptions sans client listerait les abonnements de TOUS
 *     les clients du profil.
 *
 * ⚠️ Les attributs d'organisation ne sont modifiables que par un
 *    administrateur Keycloak, et le portail ne les écrit jamais. Ne pas ajouter
 *    d'écriture générique des attributs d'organisation côté portail : un client
 *    pourrait alors y mettre l'identifiant Mollie d'un autre client.
 *    Compte de service : rôle realm-management « view-organizations » (déjà
 *    requis par /equipes).
 *
 * ── Configuration (Secret du portail) ───────────────────────────────────────
 *   MOLIE_API_KEY     OBLIGATOIRE (un seul « L » : nom de la variable du
 *                     Secret). Clé API du profil (live_… ou test_…), ou
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

if (!function_exists('mollieApiKey')) {
    /** Clé API Mollie : variable d'environnement MOLIE_API_KEY. */
    function mollieApiKey(): string
    {
        return trim((string) config('MOLIE_API_KEY', ''));
    }
}

if (!function_exists('mollieConfigured')) {
    function mollieConfigured(): bool
    {
        return mollieApiKey() !== '';
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
     * Identifiant client Mollie de l'ORGANISATION courante de l'utilisateur,
     * lu dans l'attribut d'organisation Keycloak « moliecliid ».
     * id vide si absent ou invalide.
     *
     * @return array{id:string, error:string, org_id:string}
     *         error non vide = organisation introuvable / Keycloak injoignable
     */
    function mollieCustomerIdForSessionUser(array $sessionUser): array
    {
        require_once __DIR__ . '/keycloak_organizations.php';

        $raw = '';
        $kcError = '';
        $orgId = trim((string) ($sessionUser['kc_org_id'] ?? ''));

        $cur = kcOrgResolveCurrent($sessionUser);
        if ($cur['ok'] && is_array($cur['org'])) {
            // kcOrgListForUser() peut renvoyer des organisations sans attributs.
            $org   = kcOrgEnsureAttributes($cur['org']);
            $orgId = (string) ($org['id'] ?? $orgId);
            $raw   = (string) (($org['attributes'] ?? [])[MOLLIE_USER_ATTRIBUTE] ?? '');
        } else {
            $kcError = (string) ($cur['error'] ?? '') ?: 'Organisation Keycloak introuvable.';

            // Repli : attributs d'organisation reçus dans le jeton à la connexion.
            $tokenAttrs = $sessionUser['kc_org_attributes'] ?? [];
            if (is_array($tokenAttrs)) {
                $v = $tokenAttrs[MOLLIE_USER_ATTRIBUTE] ?? '';
                if (is_array($v)) {
                    $v = $v[0] ?? '';
                }
                if (is_scalar($v) && trim((string) $v) !== '') {
                    $raw = (string) $v;
                    $kcError = '';
                }
            }
        }

        $raw = trim($raw);
        if ($raw !== '' && !mollieIsCustomerId($raw)) {
            error_log('[mollie] attribut d\'organisation ' . MOLLIE_USER_ATTRIBUTE . ' invalide pour l\'org ' . $orgId . ' : « ' . $raw . ' » (attendu : cst_…)');
            $raw = '';
        }

        return ['id' => $raw, 'error' => $kcError, 'org_id' => $orgId];
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
        $key = mollieApiKey();
        if ($key === '') {
            return ['status' => 0, 'json' => [], 'error' => 'Mollie n\'est pas configuré (MOLIE_API_KEY absente du Secret du portail).'];
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
            // Pas de curl_close() : sans effet depuis PHP 8.0 et déprécié en 8.5,
            // où l'avertissement devient une exception (set_error_handler du proxy).
            unset($ch);
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
