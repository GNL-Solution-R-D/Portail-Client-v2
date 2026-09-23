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
 *   MOLLIE_MANDATE_METHODS  Moyens proposés pour « Changer de moyen de
 *                     paiement » (paiement « first » à 0,00 €). Défaut :
 *                     creditcard. Liste séparée par des virgules ; 0,00 € n'est
 *                     accepté par Mollie que pour creditcard et paypal.
 *   MOLLIE_PROFILE_ID Requis UNIQUEMENT avec un jeton access_… (création de
 *                     paiement).
 *   PORTAIL_PUBLIC_URL URL publique du portail pour le retour depuis Mollie
 *                     (ex. https://espace.gnl-solution.fr). Défaut : déduite
 *                     de la requête (X-Forwarded-Proto / Host).
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
     * Appel à l'API Mollie. Ne lève jamais.
     *
     * @param string     $pathOrUrl chemin relatif (« /customers/… ») ou URL absolue
     *                              Mollie (lien _links.next de la pagination, GET).
     * @param array      $query     paramètres d'URL
     * @param string     $method    GET | POST | PATCH | DELETE
     * @param array|null $body      corps JSON (POST / PATCH / DELETE)
     * @return array{status:int, json:array, error:string}
     */
    function mollieRequest(string $pathOrUrl, array $query = [], string $method = 'GET', ?array $body = null): array
    {
        $key = mollieApiKey();
        if ($key === '') {
            return ['status' => 0, 'json' => [], 'error' => 'Mollie n\'est pas configuré (MOLIE_API_KEY absente du Secret du portail).'];
        }

        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
            return ['status' => 0, 'json' => [], 'error' => 'Méthode HTTP non prise en charge.'];
        }

        $base = rtrim(trim((string) config('MOLLIE_API_URL', 'https://api.mollie.com/v2')), '/');

        if (preg_match('#^https?://#i', $pathOrUrl)) {
            // On ne suit QUE des liens de pagination vers l'API configurée :
            // la clé API ne doit jamais partir vers un autre hôte.
            if ($method !== 'GET' || strpos($pathOrUrl, $base . '/') !== 0) {
                return ['status' => 0, 'json' => [], 'error' => 'Lien de pagination Mollie inattendu.'];
            }
            $url = $pathOrUrl;
        } else {
            $url = $base . '/' . ltrim($pathOrUrl, '/');
        }

        // Jeton d'organisation (access_…) : le mode test se passe dans l'URL
        // pour une lecture, dans le corps pour une écriture.
        $testmode = in_array(strtolower(trim((string) config('MOLLIE_TESTMODE', ''))), ['1', 'true', 'yes', 'on'], true);
        if ($testmode && strpos($key, 'access_') === 0) {
            if ($method === 'GET') {
                $query['testmode'] = $query['testmode'] ?? 'true';
            } else {
                $body = $body ?? [];
                $body['testmode'] = $body['testmode'] ?? true;
            }
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
        $payload = null;
        if ($body !== null) {
            $payload   = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        $raw = false;
        $status = 0;
        $err = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_FOLLOWLOCATION => false,
            ];
            if ($method === 'GET') {
                $opts[CURLOPT_HTTPGET] = true;
            } else {
                $opts[CURLOPT_CUSTOMREQUEST] = $method;
                if ($payload !== null) {
                    $opts[CURLOPT_POSTFIELDS] = $payload;
                }
            }
            curl_setopt_array($ch, $opts);
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if (curl_errno($ch)) {
                $err = curl_error($ch);
            }
            // Pas de curl_close() : sans effet depuis PHP 8.0 et déprécié en 8.5,
            // où l'avertissement devient une exception (set_error_handler du proxy).
            unset($ch);
        } else {
            $http = [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ];
            if ($payload !== null) {
                $http['content'] = $payload;
            }
            $raw = @file_get_contents($url, false, stream_context_create(['http' => $http]));
            // PHP 8.5 : $http_response_header est déprécié (exception via le
            // set_error_handler du proxy) ; http_get_last_response_headers() dès 8.4.
            $hdrs = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?? [])
                : (${'http_response_header'} ?? []);
            if (isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', (string) $hdrs[0], $m)) {
                $status = (int) $m[1];
            }
            if ($raw === false) {
                $err = 'Connexion à Mollie impossible.';
            }
        }

        if ($err !== '' || $raw === false) {
            error_log('[mollie] ' . $method . ' ' . $url . ' : ' . $err);
            return ['status' => $status, 'json' => [], 'error' => 'Mollie est injoignable pour le moment.'];
        }

        $json = json_decode((string) $raw, true);
        $json = is_array($json) ? $json : [];

        if ($status < 200 || $status >= 300) {
            $detail = (string) ($json['detail'] ?? $json['title'] ?? '');
            error_log('[mollie] HTTP ' . $status . ' ' . $method . ' ' . $url . ($detail !== '' ? ' — ' . $detail : ''));
            $msg = $status === 401 ? 'Clé API Mollie refusée.'
                : ($status === 404 ? 'Élément introuvable chez Mollie.'
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

/* ===================================================================
   Gestion des abonnements (bouton « Mettre à jour » / « Arrêter »)
   =================================================================== */

if (!defined('MOLLIE_MANAGEABLE_STATUSES')) {
    // Mollie refuse toute modification d'un abonnement résilié ou terminé.
    define('MOLLIE_MANAGEABLE_STATUSES', ['active', 'pending', 'suspended']);
}

if (!defined('MOLLIE_INTERVAL_CHOICES')) {
    /** Fréquences proposées au client (clé = valeur Mollie « interval »). */
    define('MOLLIE_INTERVAL_CHOICES', [
        '1 month'   => 1,
        '3 months'  => 3,
        '6 months'  => 6,
        '12 months' => 12,
    ]);
}

if (!function_exists('mollieIntervalMonths')) {
    /** « 1 month » → 1, « 12 months » → 12 ; null si l'unité n'est pas le mois. */
    function mollieIntervalMonths(string $interval): ?int
    {
        if (!preg_match('/^\s*(\d+)\s*months?\s*$/i', $interval, $m)) {
            return null;
        }
        $n = (int) $m[1];
        return $n > 0 ? $n : null;
    }
}

if (!function_exists('mollieSubscriptionCapabilities')) {
    /**
     * Ce que le client peut faire sur un abonnement Mollie brut.
     * @return array{can_change_interval:bool, can_change_payment:bool, can_cancel:bool, interval_reason:string}
     */
    function mollieSubscriptionCapabilities(array $sub): array
    {
        $status   = strtolower((string) ($sub['status'] ?? ''));
        $editable = in_array($status, MOLLIE_MANAGEABLE_STATUSES, true);

        $reason = '';
        if (!$editable) {
            $reason = 'Cet abonnement n\'est plus modifiable.';
        } elseif ($status === 'suspended') {
            $reason = 'Mettez d\'abord à jour le moyen de paiement.';
        } elseif (mollieIntervalMonths((string) ($sub['interval'] ?? '')) === null) {
            $reason = 'Fréquence non mensuelle : contactez le support.';
        } elseif (isset($sub['times']) && is_numeric($sub['times']) && (int) $sub['times'] > 0) {
            $reason = 'Abonnement à durée fixe : contactez le support.';
        }

        return [
            'can_change_interval' => $reason === '',
            'can_change_payment'  => $editable,
            'can_cancel'          => $editable,
            'interval_reason'     => $reason,
        ];
    }
}

if (!function_exists('mollieUpdateCustomerSubscription')) {
    /** PATCH d'un abonnement, toujours sous le client. */
    function mollieUpdateCustomerSubscription(string $customerId, string $subscriptionId, array $fields): array
    {
        if (!mollieIsCustomerId($customerId) || !mollieIsSubscriptionId($subscriptionId)) {
            return ['ok' => false, 'subscription' => [], 'error' => 'Identifiant invalide.', 'status' => 400];
        }
        $r = mollieRequest(
            '/customers/' . rawurlencode($customerId) . '/subscriptions/' . rawurlencode($subscriptionId),
            [], 'PATCH', $fields
        );
        if ($r['error'] !== '') {
            return ['ok' => false, 'subscription' => [], 'error' => $r['error'], 'status' => $r['status']];
        }
        return ['ok' => true, 'subscription' => $r['json'], 'error' => '', 'status' => $r['status']];
    }
}

if (!function_exists('mollieCancelCustomerSubscription')) {
    /** DELETE d'un abonnement (définitif côté Mollie). */
    function mollieCancelCustomerSubscription(string $customerId, string $subscriptionId): array
    {
        if (!mollieIsCustomerId($customerId) || !mollieIsSubscriptionId($subscriptionId)) {
            return ['ok' => false, 'subscription' => [], 'error' => 'Identifiant invalide.', 'status' => 400];
        }
        $r = mollieRequest(
            '/customers/' . rawurlencode($customerId) . '/subscriptions/' . rawurlencode($subscriptionId),
            [], 'DELETE'
        );
        if ($r['error'] !== '') {
            return ['ok' => false, 'subscription' => [], 'error' => $r['error'], 'status' => $r['status']];
        }
        return ['ok' => true, 'subscription' => $r['json'], 'error' => '', 'status' => $r['status']];
    }
}

if (!function_exists('mollieScaleAmount')) {
    /**
     * Montant d'une échéance pour une nouvelle fréquence, au prorata du prix
     * mensuel actuel : 10,00 € / 1 mois → 30,00 € / 3 mois. Calcul en centimes.
     * null si l'intervalle actuel n'est pas exprimé en mois.
     */
    function mollieScaleAmount(array $sub, int $newMonths): ?string
    {
        $cur = mollieIntervalMonths((string) ($sub['interval'] ?? ''));
        $val = $sub['amount']['value'] ?? null;
        if ($cur === null || !is_numeric($val) || $newMonths <= 0) {
            return null;
        }
        $cents = (int) round(((float) $val) * 100);
        $new   = (int) round($cents * $newMonths / $cur);
        return number_format($new / 100, 2, '.', '');
    }
}

if (!function_exists('mollieCreateMandatePayment')) {
    /**
     * Paiement « first » qui crée un NOUVEAU mandat pour le client (changement
     * de carte). Montant 0,00 : accepté par Mollie pour la carte bancaire et
     * PayPal, rien n'est débité.
     *
     * Config : MOLLIE_MANDATE_METHODS (défaut « creditcard », liste séparée par
     * des virgules) ; MOLLIE_PROFILE_ID requis avec un jeton access_….
     *
     * @return array{ok:bool, payment:array, checkout:string, error:string, status:int}
     */
    function mollieCreateMandatePayment(string $customerId, string $currency, string $redirectUrl, string $description, array $metadata = []): array
    {
        if (!mollieIsCustomerId($customerId)) {
            return ['ok' => false, 'payment' => [], 'checkout' => '', 'error' => 'Identifiant client Mollie invalide.', 'status' => 400];
        }

        $methods = array_values(array_filter(array_map('trim', explode(',', (string) config('MOLLIE_MANDATE_METHODS', 'creditcard')))));
        $body = [
            'amount'       => ['currency' => $currency !== '' ? strtoupper($currency) : 'EUR', 'value' => '0.00'],
            'description'  => function_exists('mb_substr') ? mb_substr($description, 0, 255) : substr($description, 0, 255),
            'redirectUrl'  => $redirectUrl,
            'customerId'   => $customerId,
            'sequenceType' => 'first',
            'metadata'     => $metadata,
        ];
        if ($methods !== []) {
            $body['method'] = count($methods) === 1 ? $methods[0] : $methods;
        }
        $profileId = trim((string) config('MOLLIE_PROFILE_ID', ''));
        if ($profileId !== '' && strpos(mollieApiKey(), 'access_') === 0) {
            $body['profileId'] = $profileId;
        }

        $r = mollieRequest('/payments', [], 'POST', $body);
        if ($r['error'] !== '') {
            return ['ok' => false, 'payment' => [], 'checkout' => '', 'error' => $r['error'], 'status' => $r['status']];
        }
        $checkout = (string) ($r['json']['_links']['checkout']['href'] ?? '');
        if ($checkout === '' || !preg_match('#^https://#i', $checkout)) {
            return ['ok' => false, 'payment' => $r['json'], 'checkout' => '', 'error' => 'Mollie n\'a pas renvoyé de page de paiement.', 'status' => 502];
        }
        return ['ok' => true, 'payment' => $r['json'], 'checkout' => $checkout, 'error' => '', 'status' => $r['status']];
    }
}

if (!function_exists('mollieGetPayment')) {
    function mollieGetPayment(string $paymentId): array
    {
        if (!preg_match('/^tr_[A-Za-z0-9]{4,64}$/', $paymentId)) {
            return ['ok' => false, 'payment' => [], 'error' => 'Identifiant de paiement invalide.', 'status' => 400];
        }
        $r = mollieRequest('/payments/' . rawurlencode($paymentId));
        if ($r['error'] !== '') {
            return ['ok' => false, 'payment' => [], 'error' => $r['error'], 'status' => $r['status']];
        }
        return ['ok' => true, 'payment' => $r['json'], 'error' => '', 'status' => $r['status']];
    }
}

if (!function_exists('mollieGetCustomerMandate')) {
    function mollieGetCustomerMandate(string $customerId, string $mandateId): array
    {
        if (!mollieIsCustomerId($customerId) || !preg_match('/^mdt_[A-Za-z0-9]{4,64}$/', $mandateId)) {
            return ['ok' => false, 'mandate' => [], 'error' => 'Identifiant de mandat invalide.', 'status' => 400];
        }
        $r = mollieRequest('/customers/' . rawurlencode($customerId) . '/mandates/' . rawurlencode($mandateId));
        if ($r['error'] !== '') {
            return ['ok' => false, 'mandate' => [], 'error' => $r['error'], 'status' => $r['status']];
        }
        return ['ok' => true, 'mandate' => $r['json'], 'error' => '', 'status' => $r['status']];
    }
}

if (!function_exists('mollieMandateLabel')) {
    /** « Visa •••• 4242 », « PayPal (x@y.fr) », « SEPA •••• 1234 ». */
    function mollieMandateLabel(array $mandate): string
    {
        $method  = strtolower((string) ($mandate['method'] ?? ''));
        $details = is_array($mandate['details'] ?? null) ? $mandate['details'] : [];
        if ($method === 'creditcard') {
            $brand = (string) ($details['cardLabel'] ?? 'Carte');
            $last  = (string) ($details['cardNumber'] ?? '');
            return trim($brand . ($last !== '' ? ' •••• ' . substr($last, -4) : ''));
        }
        if ($method === 'paypal') {
            $who = (string) ($details['consumerAccount'] ?? '');
            return 'PayPal' . ($who !== '' ? ' (' . $who . ')' : '');
        }
        if ($method === 'directdebit') {
            $iban = (string) ($details['consumerAccount'] ?? '');
            return 'Prélèvement SEPA' . ($iban !== '' ? ' •••• ' . substr($iban, -4) : '');
        }
        return $method !== '' ? ucfirst($method) : '—';
    }
}

/* ===================================================================
   Commandes = paiements du client Mollie (page /commande)
   =================================================================== */

if (!function_exists('mollieListCustomerPayments')) {
    /**
     * Paiements d'UN client Mollie, du plus récent au plus ancien
     * (GET /customers/{cst}/payments, pagination suivie). Plafonné à $max.
     *
     * @return array{ok:bool, payments:array, truncated:bool, error:string, status:int}
     */
    function mollieListCustomerPayments(string $customerId, int $max = 500): array
    {
        if (!mollieIsCustomerId($customerId)) {
            return ['ok' => false, 'payments' => [], 'truncated' => false, 'error' => 'Identifiant client Mollie invalide.', 'status' => 400];
        }

        $out   = [];
        $next  = '/customers/' . rawurlencode($customerId) . '/payments';
        $query = ['limit' => 250];

        while ($next !== '' && count($out) < $max) {
            $r = mollieRequest($next, $query);
            if ($r['error'] !== '') {
                return ['ok' => false, 'payments' => [], 'truncated' => false, 'error' => $r['error'], 'status' => $r['status']];
            }
            foreach ((array) ($r['json']['_embedded']['payments'] ?? []) as $p) {
                if (is_array($p)) {
                    $out[] = $p;
                }
            }
            $next  = (string) ($r['json']['_links']['next']['href'] ?? '');
            $query = [];
        }

        $truncated = $next !== '' || count($out) > $max;
        return ['ok' => true, 'payments' => array_slice($out, 0, $max), 'truncated' => $truncated, 'error' => '', 'status' => 200];
    }
}

if (!function_exists('molliePaymentMethodLabel')) {
    /** « Visa •••• 4242 », « PayPal (x@y.fr) », « SEPA •••• 1234 », « Bancontact »… */
    function molliePaymentMethodLabel(array $payment): string
    {
        $method  = strtolower((string) ($payment['method'] ?? ''));
        $details = is_array($payment['details'] ?? null) ? $payment['details'] : [];
        $names = [
            'creditcard' => 'Carte', 'paypal' => 'PayPal', 'directdebit' => 'Prélèvement SEPA',
            'banktransfer' => 'Virement', 'ideal' => 'iDEAL', 'bancontact' => 'Bancontact',
            'applepay' => 'Apple Pay', 'googlepay' => 'Google Pay', 'sofort' => 'SOFORT',
            'kbc' => 'KBC/CBC', 'belfius' => 'Belfius', 'eps' => 'EPS', 'giropay' => 'giropay',
            'przelewy24' => 'Przelewy24', 'klarna' => 'Klarna', 'billie' => 'Billie',
            'trustly' => 'Trustly', 'twint' => 'TWINT', 'blik' => 'BLIK',
        ];
        if ($method === 'creditcard') {
            $brand = (string) ($details['cardLabel'] ?? 'Carte');
            $last  = (string) ($details['cardNumber'] ?? '');
            return trim($brand . ($last !== '' ? ' •••• ' . substr($last, -4) : ''));
        }
        $label = $names[$method] ?? ($method !== '' ? ucfirst($method) : '—');
        $acct  = (string) ($details['consumerAccount'] ?? '');
        if ($acct !== '') {
            $label .= strpos($acct, '@') !== false ? ' (' . $acct . ')' : ' •••• ' . substr(preg_replace('/\s+/', '', $acct), -4);
        }
        return $label;
    }
}
