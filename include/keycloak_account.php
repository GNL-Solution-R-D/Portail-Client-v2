<?php

declare(strict_types=1);

/**
 * include/keycloak_account.php
 *
 * « Mon compte » (/account) — lecture ET écriture du profil Keycloak via
 * l'Admin REST API. Keycloak est la SEULE source de vérité : rien n'est
 * stocké en base côté portail, la session PHP n'est qu'un cache rafraîchi
 * après chaque écriture.
 *
 * Compte de service : le client OIDC du portail (KEYCLOAK_CLIENT_ID /
 * KEYCLOAK_CLIENT_SECRET), grant client_credentials — exactement le même
 * mécanisme que include/keycloak_organizations.php. Voir les pré-requis en
 * bas de ce fichier.
 *
 * ⚠️ Deux pièges Keycloak, à ne pas « simplifier » :
 *
 *  1. PUT /users/{id} fusionne les champs de premier niveau, mais REMPLACE
 *     la map « attributes » en entier. Écrire {attributes:{phone:…}} efface
 *     siret, siren, client_code, nom_commercial… Toute écriture passe donc
 *     par kcAccUpdateUser(), qui relit la représentation et fusionne.
 *
 *  2. L'Admin REST NE SAIT PAS créer un credential OTP : il n'existe aucun
 *     POST /users/{id}/credentials. On ne peut donc pas générer le secret
 *     TOTP depuis le portail. L'activation passe par l'action requise
 *     CONFIGURE_TOTP + l'e-mail « execute-actions-email » (le client scanne
 *     le QR code sur la page Keycloak). La DÉSACTIVATION, elle, est bien
 *     pilotable : DELETE du credential de type « otp ».
 *
 *  3. Même chose pour les CLÉS DE SÉCURITÉ (WebAuthn) : l'enregistrement
 *     est une cérémonie navigateur ↔ Keycloak, impossible par l'Admin REST.
 *     On l'ouvre en « application-initiated action » (kc_action=
 *     webauthn-register, cf. data/account_api.php → account.keys.register) ;
 *     lister / renommer / supprimer passe bien par l'Admin REST.
 *
 *  4. Le grant password (/connexion) NE SAIT PAS demander une clé WebAuthn.
 *     kcAccLoginNeedsSecurityKey() permet à /connexion de renvoyer ces
 *     comptes vers la page Keycloak hébergée — sans quoi la clé serait
 *     contournée par le formulaire du portail.
 */

require_once __DIR__ . '/keycloak_auth.php';   // keycloakHttpRequest, keycloakGet*
require_once __DIR__ . '/keycloak_rest.php';   // kcRestAdminBase/Token, keycloakPasswordGrant

/* ===================================================================
   Constantes
   =================================================================== */

if (!defined('KC_ACC_TOTP_ACTION')) {
    define('KC_ACC_TOTP_ACTION', 'CONFIGURE_TOTP');
}

/**
 * Clés de sécurité (WebAuthn / FIDO2) — alias Keycloak.
 *  - action : alias de la required action d'enregistrement, utilisé en
 *    « application-initiated action » (kc_action=…) ;
 *  - types  : types de credential. « webauthn » = second facteur ;
 *    « webauthn-passwordless » = clé d'accès sans mot de passe (listée et
 *    supprimable ici si elle existe, mais non proposée à l'ajout).
 */
if (!defined('KC_ACC_WEBAUTHN_ACTION')) {
    define('KC_ACC_WEBAUTHN_ACTION', 'webauthn-register');
}
if (!defined('KC_ACC_WEBAUTHN_TYPES')) {
    define('KC_ACC_WEBAUTHN_TYPES', ['webauthn', 'webauthn-passwordless']);
}

/* ===================================================================
   Transport Admin REST (toutes méthodes)
   =================================================================== */

if (!function_exists('kcAccRequest')) {
    /**
     * Appel Admin REST. Ne lève jamais.
     *
     * @param string|null $text corps text/plain (exclusif de $json) — requis par
     *                          PUT /credentials/{id}/userLabel.
     * @return array{status:int, body:array, error:string}
     */
    function kcAccRequest(string $method, string $path, ?array $json = null, array $query = [], ?string $text = null): array
    {
        $bearer = kcRestAdminToken();
        if ($bearer === null) {
            return [
                'status' => 0,
                'body'   => [],
                'error'  => "Keycloak n'a pas délivré de jeton de service : serveur injoignable, "
                    . "KEYCLOAK_CLIENT_ID / KEYCLOAK_CLIENT_SECRET invalides, ou « Service accounts roles » "
                    . "désactivé sur le client (détail dans les logs du portail).",
            ];
        }

        $url = kcRestAdminBase() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $bearer];
        $opts    = [CURLOPT_CUSTOMREQUEST => strtoupper($method)];

        if ($json !== null) {
            $headers[]                = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($text !== null) {
            $headers[]                = 'Content-Type: text/plain; charset=utf-8';
            $opts[CURLOPT_POSTFIELDS] = $text;
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        try {
            $resp = keycloakHttpRequest($url, $opts);
        } catch (Throwable $e) {
            error_log('[GNL ACCOUNT] ' . $method . ' ' . $path . ' : ' . $e->getMessage());
            return ['status' => 0, 'body' => [], 'error' => 'Keycloak est injoignable. Réessayez dans un instant.'];
        }

        $status = (int) ($resp['status'] ?? 0);
        $body   = (isset($resp['body']) && is_array($resp['body'])) ? $resp['body'] : [];

        if ($status >= 200 && $status < 300) {
            return ['status' => $status, 'body' => $body, 'error' => ''];
        }

        $error = kcAccErrorFr($status, $body, $path);
        error_log('[GNL ACCOUNT] ' . $method . ' ' . $path . ' → HTTP ' . $status
            . ' ' . json_encode($body, JSON_UNESCAPED_UNICODE));

        return ['status' => $status, 'body' => $body, 'error' => $error];
    }
}

if (!function_exists('kcAccErrorFr')) {
    /** Traduit une erreur Admin REST en message affichable par le client. */
    function kcAccErrorFr(int $status, array $body, string $path = ''): string
    {
        $raw = trim((string) ($body['errorMessage'] ?? $body['error_description'] ?? $body['error'] ?? ''));
        $low = strtolower($raw);

        // Messages de politique de mot de passe (clés i18n Keycloak).
        if (strpos($low, 'invalidpasswordminlength') !== false)
            return "Mot de passe trop court : respectez la longueur minimale exigée.";
        if (strpos($low, 'invalidpasswordminupper') !== false)
            return "Le mot de passe doit contenir au moins une majuscule.";
        if (strpos($low, 'invalidpasswordminlower') !== false)
            return "Le mot de passe doit contenir au moins une minuscule.";
        if (strpos($low, 'invalidpasswordmindigits') !== false)
            return "Le mot de passe doit contenir au moins un chiffre.";
        if (strpos($low, 'invalidpasswordminspecialchars') !== false)
            return "Le mot de passe doit contenir au moins un caractère spécial.";
        if (strpos($low, 'invalidpasswordhistory') !== false)
            return "Ce mot de passe a déjà été utilisé récemment. Choisissez-en un autre.";
        if (strpos($low, 'invalidpasswordnotusername') !== false)
            return "Le mot de passe ne peut pas être identique à votre identifiant.";
        if (strpos($low, 'invalidpasswordnotemail') !== false)
            return "Le mot de passe ne peut pas être identique à votre e-mail.";
        if (strpos($low, 'invalidpasswordregexpattern') !== false)
            return "Le mot de passe ne respecte pas le format exigé.";

        switch ($status) {
            case 400:
                if (strpos($low, 'user exists') !== false || strpos($low, 'already exists') !== false)
                    return "Cet identifiant ou cette adresse e-mail est déjà utilisé.";
                if (strpos($low, 'read-only') !== false || strpos($low, 'readonly') !== false)
                    return "Ce champ est géré par votre annuaire et ne peut pas être modifié ici.";
                return $raw !== '' ? $raw : "Données refusées par Keycloak.";
            case 401:
                return "Le compte de service du portail n'est pas autorisé sur Keycloak.";
            case 403:
                return "Le compte de service n'a pas les droits requis sur Keycloak "
                    . "(rôles « manage-users » et « view-users » du client realm-management).";
            case 404:
                return "Ressource introuvable côté Keycloak" . ($path !== '' ? ' (' . $path . ')' : '') . '.';
            case 409:
                return "Cet identifiant ou cette adresse e-mail est déjà utilisé par un autre compte.";
            default:
                return "Keycloak a renvoyé HTTP " . $status . ($raw !== '' ? ' — ' . $raw : '') . '.';
        }
    }
}

/* ===================================================================
   Identité : UID Keycloak de l'utilisateur connecté
   =================================================================== */

if (!function_exists('kcAccUserId')) {
    /**
     * UID Keycloak (claim « sub ») de la session. Repli par e-mail pour les
     * sessions ouvertes avant la normalisation de l'identité.
     * Renvoie '' si rien n'est exploitable.
     */
    function kcAccUserId(array $sessionUser): string
    {
        foreach (['keycloak_uid', 'sub', 'kc_uid', 'id'] as $k) {
            $v = trim((string) ($sessionUser[$k] ?? ''));
            // Un identifiant local ENTIER n'est pas un UID Keycloak.
            if ($v !== '' && !ctype_digit($v)) {
                return $v;
            }
        }

        $email = trim((string) ($sessionUser['email'] ?? ''));
        if ($email !== '') {
            $found = kcRestFindUserId($email);
            if ($found !== null && $found !== '') {
                return $found;
            }
        }
        return '';
    }
}

/* ===================================================================
   Lecture du profil
   =================================================================== */

if (!function_exists('kcAccFlattenAttrs')) {
    /** Aplati {cle:[val]} ou {cle:val} en {cle:"val"}. */
    function kcAccFlattenAttrs($attrs): array
    {
        $out = [];
        if (!is_array($attrs)) return $out;
        foreach ($attrs as $k => $v) {
            if (is_array($v)) $v = $v[0] ?? '';
            if (is_scalar($v)) $out[(string) $k] = trim((string) $v);
        }
        return $out;
    }
}

if (!function_exists('kcAccGetUser')) {
    /**
     * Représentation BRUTE de l'utilisateur (nécessaire avant toute écriture).
     * Mémorisée le temps de la requête PHP : une page /account déclenche
     * sinon 4 à 5 GET identiques.
     *
     * @return array{ok:bool, user:array, error:string}
     */
    function kcAccGetUser(string $userId, bool $fresh = false): array
    {
        if ($userId === '') {
            return ['ok' => false, 'user' => [], 'error' => "Identifiant Keycloak introuvable dans votre session."];
        }

        // Cache en GLOBALS (et non en static) pour que kcAccForgetUser()
        // puisse l'invalider : après un PUT, la représentation en mémoire est
        // périmée, et une 2FA « activée » se relirait « inactive ».
        if (!isset($GLOBALS['KC_ACC_USER_CACHE']) || !is_array($GLOBALS['KC_ACC_USER_CACHE'])) {
            $GLOBALS['KC_ACC_USER_CACHE'] = [];
        }
        if (!$fresh && isset($GLOBALS['KC_ACC_USER_CACHE'][$userId])) {
            return $GLOBALS['KC_ACC_USER_CACHE'][$userId];
        }

        $r = kcAccRequest('GET', '/users/' . rawurlencode($userId));
        if ($r['error'] !== '' || empty($r['body']['id'])) {
            // Un échec n'est PAS mis en cache : l'appel suivant doit retenter.
            return [
                'ok'    => false,
                'user'  => [],
                'error' => $r['error'] !== '' ? $r['error'] : "Compte introuvable côté Keycloak.",
            ];
        }

        return $GLOBALS['KC_ACC_USER_CACHE'][$userId] = ['ok' => true, 'user' => $r['body'], 'error' => ''];
    }
}

if (!function_exists('kcAccForgetUser')) {
    /**
     * Invalide la représentation mémorisée. À appeler après TOUTE écriture :
     * sans cela, kcAccTwoFactorStatus() et kcAccRefreshSession() relisent
     * l'état d'AVANT le PUT dans la même requête PHP.
     */
    function kcAccForgetUser(string $userId): void
    {
        if (isset($GLOBALS['KC_ACC_USER_CACHE'][$userId])) {
            unset($GLOBALS['KC_ACC_USER_CACHE'][$userId]);
        }
    }
}

if (!function_exists('kcAccRealmInfo')) {
    /**
     * Réglages du realm qui conditionnent l'UI.
     *  - editUsernameAllowed        : le champ « identifiant » est-il modifiable ?
     *  - registrationEmailAsUsername: l'identifiant SUIT l'e-mail (champ masqué).
     *
     * La lecture du realm demande « view-realm » ; si le compte de service ne
     * l'a pas, on reste OPTIMISTE (champ proposé) et c'est Keycloak qui
     * tranchera au moment du PUT, avec un message explicite.
     */
    function kcAccRealmInfo(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $r = kcAccRequest('GET', '');            // GET /admin/realms/{realm}
        if ($r['error'] !== '' || $r['body'] === []) {
            return $cache = [
                'ok' => false, 'editUsernameAllowed' => true, 'registrationEmailAsUsername' => false,
                'browserFlow' => '',
            ];
        }
        return $cache = [
            'ok'                          => true,
            'editUsernameAllowed'         => (bool) ($r['body']['editUsernameAllowed'] ?? false),
            'registrationEmailAsUsername' => (bool) ($r['body']['registrationEmailAsUsername'] ?? false),
            // Flow lié à la page de connexion hébergée : c'est lui qui doit
            // contenir « WebAuthn Authenticator » pour que la clé soit demandée.
            'browserFlow'                 => (string) ($r['body']['browserFlow'] ?? 'browser'),
        ];
    }
}

if (!function_exists('kcAccLoadProfile')) {
    /**
     * Profil normalisé pour l'UI : identité, coordonnées, 2FA, réglages realm.
     *
     * @return array{ok:bool, profile:array, error:string}
     */
    function kcAccLoadProfile(string $userId): array
    {
        $u = kcAccGetUser($userId, true);
        if (!$u['ok']) {
            return ['ok' => false, 'profile' => [], 'error' => $u['error']];
        }

        $row   = $u['user'];
        $attrs = kcAccFlattenAttrs($row['attributes'] ?? []);
        $realm = kcAccRealmInfo();
        $otp   = kcAccTwoFactorStatus($userId);
        $keys  = kcAccSecurityKeyStatus($userId);

        $required = array_values(array_filter(array_map('strval', (array) ($row['requiredActions'] ?? []))));

        return [
            'ok'    => true,
            'error' => '',
            'profile' => [
                'id'            => (string) $row['id'],
                'username'      => (string) ($row['username'] ?? ''),
                'email'         => (string) ($row['email'] ?? ''),
                'emailVerified' => (bool) ($row['emailVerified'] ?? false),
                'firstName'     => (string) ($row['firstName'] ?? ''),
                'lastName'      => (string) ($row['lastName'] ?? ''),
                'enabled'       => (bool) ($row['enabled'] ?? true),
                'createdAt'     => isset($row['createdTimestamp']) ? (int) $row['createdTimestamp'] : 0,
                'civilite'      => (string) ($attrs['civilite'] ?? ''),
                'phone'         => (string) ($attrs['phone'] ?? $attrs['telephone'] ?? ''),
                'fonction'      => (string) ($attrs['fonction'] ?? ''),
                'pref_lang'     => (string) ($attrs['pref_lang'] ?? $attrs['locale'] ?? ''),
                // Informations entreprise : AFFICHÉES, jamais éditables ici
                // (elles appartiennent à l'organisation, pas à la personne).
                'company'       => [
                    'raison'         => (string) ($attrs['raison'] ?? $attrs['raison_social'] ?? ''),
                    'nom_commercial' => (string) ($attrs['nom_commercial'] ?? ''),
                    'siret'          => (string) ($attrs['siret'] ?? ''),
                    'siren'          => (string) ($attrs['siren'] ?? ''),
                    'client_code'    => (string) ($attrs['client_code'] ?? $attrs['code_client'] ?? ''),
                ],
                'twoFactor'       => $otp,
                'securityKeys'    => $keys,
                'requiredActions' => $required,
                'realm'           => [
                    'editUsernameAllowed'         => $realm['editUsernameAllowed'],
                    'registrationEmailAsUsername' => $realm['registrationEmailAsUsername'],
                ],
            ],
        ];
    }
}

/* ===================================================================
   Écriture du profil
   =================================================================== */

if (!function_exists('kcAccUpdateUser')) {
    /**
     * PUT /users/{id} en préservant l'existant.
     *
     * @param array $top   champs de premier niveau (firstName, lastName, email, username, …)
     * @param array $attrs attributs à poser/écraser ; les AUTRES attributs sont conservés.
     *                     Une valeur '' SUPPRIME l'attribut (Keycloak n'aime pas les chaînes vides).
     * @return array{ok:bool, error:string}
     */
    function kcAccUpdateUser(string $userId, array $top = [], array $attrs = []): array
    {
        $cur = kcAccGetUser($userId, true);
        if (!$cur['ok']) {
            return ['ok' => false, 'error' => $cur['error']];
        }

        $payload = $cur['user'];

        foreach ($top as $k => $v) {
            $payload[$k] = $v;
        }

        if ($attrs !== []) {
            // ⚠️ La map attributes est REMPLACÉE par Keycloak : on part de
            // l'existant pour ne rien perdre (siret, client_code, namespace…).
            $merged = kcAccFlattenAttrs($cur['user']['attributes'] ?? []);
            foreach ($attrs as $k => $v) {
                $v = trim((string) $v);
                if ($v === '') unset($merged[$k]);
                else           $merged[$k] = $v;
            }
            $out = [];
            foreach ($merged as $k => $v) {
                $out[$k] = [$v];   // Keycloak attend des tableaux de valeurs
            }
            $payload['attributes'] = $out;
        }

        // Champs en lecture seule côté Admin REST : les renvoyer provoque des 400.
        unset($payload['access'], $payload['origin'], $payload['self'], $payload['federationLink']);

        $r = kcAccRequest('PUT', '/users/' . rawurlencode($userId), $payload);

        // La copie en mémoire ne reflète plus Keycloak : on la jette.
        kcAccForgetUser($userId);

        return ['ok' => $r['error'] === '', 'error' => $r['error']];
    }
}

if (!function_exists('kcAccSaveProfile')) {
    /**
     * Onglet « Profil » : état civil et coordonnées.
     *
     * @return array{ok:bool, error:string}
     */
    function kcAccSaveProfile(string $userId, array $in): array
    {
        $firstName = trim((string) ($in['firstName'] ?? ''));
        $lastName  = trim((string) ($in['lastName'] ?? ''));

        if ($firstName === '' && $lastName === '') {
            return ['ok' => false, 'error' => "Indiquez au moins un nom ou un prénom."];
        }
        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            return ['ok' => false, 'error' => "Nom ou prénom trop long (100 caractères maximum)."];
        }

        $phone = trim((string) ($in['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^[0-9 +().\-]{6,25}$/', $phone)) {
            return ['ok' => false, 'error' => "Numéro de téléphone invalide."];
        }

        $civilite = trim((string) ($in['civilite'] ?? ''));
        if ($civilite !== '' && !in_array($civilite, ['M.', 'Mme'], true)) {
            $civilite = '';
        }

        $lang = strtolower(trim((string) ($in['pref_lang'] ?? '')));
        if ($lang !== '' && !preg_match('/^[a-z]{2}$/', $lang)) {
            $lang = '';
        }

        return kcAccUpdateUser(
            $userId,
            ['firstName' => $firstName, 'lastName' => $lastName],
            [
                'civilite'  => $civilite,
                'phone'     => $phone,
                'fonction'  => mb_substr(trim((string) ($in['fonction'] ?? '')), 0, 120),
                'pref_lang' => $lang,
            ]
        );
    }
}

if (!function_exists('kcAccSaveIdentity')) {
    /**
     * Onglet « Identifiants » : nom d'utilisateur et e-mail.
     *
     * Changer l'e-mail remet emailVerified à false et déclenche un e-mail de
     * vérification — sinon un client pourrait s'attribuer une adresse dont il
     * n'a pas le contrôle.
     *
     * @return array{ok:bool, error:string, notice:string}
     */
    function kcAccSaveIdentity(string $userId, array $in): array
    {
        $fail = static function (string $m): array {
            return ['ok' => false, 'error' => $m, 'notice' => ''];
        };

        $cur = kcAccGetUser($userId, true);
        if (!$cur['ok']) return $fail($cur['error']);

        $realm       = kcAccRealmInfo();
        $curUsername = (string) ($cur['user']['username'] ?? '');
        $curEmail    = (string) ($cur['user']['email'] ?? '');

        $email    = strtolower(trim((string) ($in['email'] ?? '')));
        $username = trim((string) ($in['username'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $fail("Adresse e-mail invalide.");
        }

        $top          = [];
        $emailChanged = (strtolower($curEmail) !== $email);

        if ($emailChanged) {
            $top['email']         = $email;
            $top['emailVerified'] = false;
        }

        if ($realm['registrationEmailAsUsername']) {
            // L'identifiant suit l'e-mail : Keycloak s'en charge, on n'y touche pas.
            $username = $email;
        } elseif ($username !== '' && $username !== $curUsername) {
            if (!$realm['editUsernameAllowed'] && $realm['ok']) {
                return $fail("La modification de l'identifiant est désactivée sur ce realm Keycloak.");
            }
            if (!preg_match('/^[A-Za-z0-9._@\-]{3,64}$/', $username)) {
                return $fail("Identifiant invalide : 3 à 64 caractères, lettres, chiffres et . _ - @ uniquement.");
            }
            $top['username'] = strtolower($username);
        }

        if ($top === []) {
            return ['ok' => true, 'error' => '', 'notice' => "Aucune modification à enregistrer."];
        }

        $r = kcAccUpdateUser($userId, $top);
        if (!$r['ok']) return $fail($r['error']);

        $notice = "Identifiants mis à jour.";
        if ($emailChanged) {
            $sent   = kcAccSendVerifyEmail($userId);
            $notice = $sent
                ? "Adresse e-mail modifiée. Un message de vérification vient de vous être envoyé à " . $email . "."
                : "Adresse e-mail modifiée. L'e-mail de vérification n'a pas pu être envoyé : "
                  . "vérifiez vos messages plus tard ou contactez le support.";
        }

        return ['ok' => true, 'error' => '', 'notice' => $notice];
    }
}

if (!function_exists('kcAccSendVerifyEmail')) {
    /** Déclenche l'action VERIFY_EMAIL par e-mail. Best-effort. */
    function kcAccSendVerifyEmail(string $userId): bool
    {
        $r = kcAccRequest(
            'PUT',
            '/users/' . rawurlencode($userId) . '/execute-actions-email',
            ['VERIFY_EMAIL'],
            ['client_id' => keycloakGetClientId(), 'lifespan' => 43200]
        );
        return $r['error'] === '';
    }
}

/* ===================================================================
   Mot de passe
   =================================================================== */

if (!function_exists('kcAccVerifyPassword')) {
    /**
     * Vérifie le mot de passe ACTUEL par un password grant (Direct Access
     * Grants, déjà utilisé par /connexion). Sans cette étape, un vol de
     * cookie de session suffirait à changer le mot de passe.
     *
     * ⚠️ Compte avec TOTP : le flow « direct grant » par défaut exige aussi le
     * code (« Conditional OTP »), sinon il répond invalid_grant comme pour un
     * mauvais mot de passe. D'où $totp, que la page demande dès que la 2FA
     * est active. Le jeton obtenu n'est pas conservé et sa session Keycloak
     * est refermée aussitôt (sinon chaque vérification laisse une session
     * SSO fantôme dans la liste « Sessions actives »).
     */
    function kcAccVerifyPassword(string $username, string $password, string $totp = ''): bool
    {
        if ($username === '' || $password === '') return false;
        try {
            $tok = keycloakPasswordGrant($username, $password, $totp);
        } catch (Throwable $e) {
            error_log('[GNL ACCOUNT] vérification mot de passe — réseau : ' . $e->getMessage());
            return false;
        }
        $ok = (int) ($tok['status'] ?? 0) === 200 && !empty($tok['body']['access_token']);
        if ($ok) {
            kcAccEndTokenSession((string) ($tok['body']['refresh_token'] ?? ''));
        }
        return $ok;
    }
}

if (!function_exists('kcAccEndTokenSession')) {
    /**
     * Ferme la session Keycloak ouverte par un grant password (logout OIDC
     * par refresh_token). Best-effort : n'échoue jamais.
     */
    function kcAccEndTokenSession(string $refreshToken): void
    {
        if ($refreshToken === '') return;
        $fields = ['client_id' => keycloakGetClientId(), 'refresh_token' => $refreshToken];
        $secret = keycloakGetClientSecret();
        if ($secret !== '') $fields['client_secret'] = $secret;
        try {
            keycloakHttpRequest(keycloakGetIssuer() . '/protocol/openid-connect/logout', [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            ]);
        } catch (Throwable $e) {
            error_log('[GNL ACCOUNT] fermeture session de vérification : ' . $e->getMessage());
        }
    }
}

if (!function_exists('kcAccChangePassword')) {
    /**
     * @return array{ok:bool, error:string}
     */
    function kcAccChangePassword(string $userId, array $in): array
    {
        $current = (string) ($in['current'] ?? '');
        $new     = (string) ($in['new'] ?? '');
        $confirm = (string) ($in['confirm'] ?? '');

        if ($current === '' || $new === '') {
            return ['ok' => false, 'error' => "Renseignez le mot de passe actuel et le nouveau."];
        }
        if (!hash_equals($new, $confirm)) {
            return ['ok' => false, 'error' => "Les deux nouveaux mots de passe ne correspondent pas."];
        }
        if (hash_equals($current, $new)) {
            return ['ok' => false, 'error' => "Le nouveau mot de passe doit différer de l'actuel."];
        }
        if (mb_strlen($new) < 12) {
            // Garde-fou du portail ; la politique du realm s'applique en plus.
            return ['ok' => false, 'error' => "Le nouveau mot de passe doit faire au moins 12 caractères."];
        }

        $cur = kcAccGetUser($userId);
        if (!$cur['ok']) return ['ok' => false, 'error' => $cur['error']];

        $username = (string) ($cur['user']['username'] ?? '');
        if (!kcAccVerifyPassword($username, $current, (string) ($in['totp'] ?? ''))) {
            return ['ok' => false, 'error' => kcAccBadSecretMessage($userId, "Mot de passe actuel incorrect.")];
        }

        $r = kcAccRequest(
            'PUT',
            '/users/' . rawurlencode($userId) . '/reset-password',
            ['type' => 'password', 'value' => $new, 'temporary' => false]
        );
        if ($r['error'] !== '') {
            return ['ok' => false, 'error' => $r['error']];
        }
        return ['ok' => true, 'error' => ''];
    }
}

/* ===================================================================
   Double authentification (TOTP)
   =================================================================== */

if (!function_exists('kcAccCredentials')) {
    /** GET /users/{id}/credentials — liste des credentials (jamais les secrets). */
    function kcAccCredentials(string $userId): array
    {
        $r = kcAccRequest('GET', '/users/' . rawurlencode($userId) . '/credentials');
        if ($r['error'] !== '') return [];
        return array_values(array_filter($r['body'], 'is_array'));
    }
}

if (!function_exists('kcAccTwoFactorStatus')) {
    /**
     * @return array{enabled:bool, pending:bool, credentials:array<int,array{id:string,label:string,createdDate:int}>}
     */
    function kcAccTwoFactorStatus(string $userId): array
    {
        $otp = [];
        foreach (kcAccCredentials($userId) as $c) {
            if (strtolower((string) ($c['type'] ?? '')) !== 'otp') continue;
            $otp[] = [
                'id'          => (string) ($c['id'] ?? ''),
                'label'       => (string) ($c['userLabel'] ?? ''),
                'createdDate' => (int) ($c['createdDate'] ?? 0),
            ];
        }

        $pending = false;
        $u = kcAccGetUser($userId);
        if ($u['ok']) {
            $actions = array_map('strval', (array) ($u['user']['requiredActions'] ?? []));
            $pending = in_array(KC_ACC_TOTP_ACTION, $actions, true);
        }

        return ['enabled' => $otp !== [], 'pending' => $pending && $otp === [], 'credentials' => $otp];
    }
}

if (!function_exists('kcAccEnableTwoFactor')) {
    /**
     * Active la 2FA.
     *
     * L'Admin REST ne sait PAS créer un credential OTP (aucun endpoint de
     * création) : on pose l'action requise CONFIGURE_TOTP — qui s'imposera à
     * la prochaine connexion — et on envoie le lien Keycloak par e-mail pour
     * que le client puisse le faire tout de suite.
     *
     * @return array{ok:bool, error:string, notice:string, emailSent:bool}
     */
    function kcAccEnableTwoFactor(string $userId): array
    {
        $status = kcAccTwoFactorStatus($userId);
        if ($status['enabled']) {
            return ['ok' => true, 'error' => '', 'notice' => "La double authentification est déjà active.", 'emailSent' => false];
        }

        $cur = kcAccGetUser($userId, true);
        if (!$cur['ok']) return ['ok' => false, 'error' => $cur['error'], 'notice' => '', 'emailSent' => false];

        $actions = array_values(array_unique(array_merge(
            array_map('strval', (array) ($cur['user']['requiredActions'] ?? [])),
            [KC_ACC_TOTP_ACTION]
        )));

        $r = kcAccUpdateUser($userId, ['requiredActions' => $actions]);
        if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'notice' => '', 'emailSent' => false];

        $mail = kcAccRequest(
            'PUT',
            '/users/' . rawurlencode($userId) . '/execute-actions-email',
            [KC_ACC_TOTP_ACTION],
            ['client_id' => keycloakGetClientId(), 'lifespan' => 3600]
        );
        $sent = $mail['error'] === '';

        return [
            'ok'        => true,
            'error'     => '',
            'emailSent' => $sent,
            'notice'    => $sent
                ? "Un e-mail vient de vous être envoyé : suivez le lien pour scanner le QR code avec votre application d'authentification."
                : "La configuration est activée : elle vous sera demandée à votre prochaine connexion. "
                  . "(L'e-mail contenant le lien n'a pas pu être envoyé.)",
        ];
    }
}

if (!function_exists('kcAccDisableTwoFactor')) {
    /**
     * Désactive la 2FA : supprime les credentials OTP ET retire l'action
     * requise. Le mot de passe actuel est exigé — désactiver une 2FA est une
     * opération sensible, un cookie volé ne doit pas suffire.
     *
     * @return array{ok:bool, error:string}
     */
    function kcAccDisableTwoFactor(string $userId, string $currentPassword, string $totp = ''): array
    {
        $cur = kcAccGetUser($userId, true);
        if (!$cur['ok']) return ['ok' => false, 'error' => $cur['error']];

        $username = (string) ($cur['user']['username'] ?? '');
        if (!kcAccVerifyPassword($username, $currentPassword, $totp)) {
            return ['ok' => false, 'error' => kcAccBadSecretMessage(
                $userId,
                "Mot de passe incorrect : la double authentification n'a pas été désactivée."
            )];
        }

        $status = kcAccTwoFactorStatus($userId);
        foreach ($status['credentials'] as $c) {
            if ($c['id'] === '') continue;
            $r = kcAccRequest('DELETE', '/users/' . rawurlencode($userId) . '/credentials/' . rawurlencode($c['id']));
            if ($r['error'] !== '') {
                return ['ok' => false, 'error' => $r['error']];
            }
        }

        $actions = array_values(array_filter(
            array_map('strval', (array) ($cur['user']['requiredActions'] ?? [])),
            static function (string $a): bool { return $a !== KC_ACC_TOTP_ACTION; }
        ));
        $upd = kcAccUpdateUser($userId, ['requiredActions' => $actions]);
        if (!$upd['ok']) return ['ok' => false, 'error' => $upd['error']];

        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('kcAccBadSecretMessage')) {
    /**
     * Message d'échec de vérification : si le compte a un TOTP, l'échec peut
     * venir du code (absent ou périmé) et non du mot de passe — Keycloak ne
     * distingue pas les deux.
     */
    function kcAccBadSecretMessage(string $userId, string $default): string
    {
        $otp = kcAccTwoFactorStatus($userId);
        if (!$otp['enabled']) return $default;
        return rtrim($default, '.') . " — ou code de l'application d'authentification "
            . "absent ou expiré (il change toutes les 30 secondes).";
    }
}

/* ===================================================================
   Clés de sécurité physiques (WebAuthn / FIDO2)
   =================================================================== */

if (!function_exists('kcAccSecurityKeys')) {
    /**
     * Clés WebAuthn du compte, les plus anciennes d'abord.
     * Renvoie NULL si Keycloak n'a pas pu répondre — à distinguer de [] :
     * /connexion refuse d'ouvrir une session par mot de passe seul quand on
     * ne sait pas.
     *
     * @return array<int,array{id:string,label:string,createdDate:int,type:string}>|null
     */
    function kcAccSecurityKeys(string $userId): ?array
    {
        if ($userId === '') return null;
        $r = kcAccRequest('GET', '/users/' . rawurlencode($userId) . '/credentials');
        if ($r['error'] !== '') return null;

        $out = [];
        foreach ($r['body'] as $c) {
            if (!is_array($c)) continue;
            $type = strtolower((string) ($c['type'] ?? ''));
            if (!in_array($type, KC_ACC_WEBAUTHN_TYPES, true)) continue;
            $out[] = [
                'id'          => (string) ($c['id'] ?? ''),
                'label'       => (string) ($c['userLabel'] ?? ''),
                'createdDate' => (int) ($c['createdDate'] ?? 0),
                'type'        => $type,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return $a['createdDate'] <=> $b['createdDate'];
        });
        return $out;
    }
}

if (!function_exists('kcAccWebAuthnServerStatus')) {
    /**
     * Keycloak est-il prêt à DEMANDER la clé ? Deux réglages realm :
     *  - flow : le flow « browser » lié au realm contient un « WebAuthn
     *    Authenticator » effectivement actif (lui ET ses sous-flows parents
     *    non DISABLED) ;
     *  - action : la required action « webauthn-register » est activée
     *    (sinon kc_action=webauthn-register échoue).
     *
     * Lecture réservée au rôle view-realm (OPTIONNEL) : sans lui, chaque
     * valeur vaut « unknown » et la page n'affiche simplement pas d'alerte.
     *
     * @return array{flow:string, action:string}  ready|missing|unknown · enabled|disabled|unknown
     */
    function kcAccWebAuthnServerStatus(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $flow  = 'unknown';
        $realm = kcAccRealmInfo();
        if ($realm['ok'] && $realm['browserFlow'] !== '') {
            $r = kcAccRequest('GET', '/authentication/flows/' . rawurlencode($realm['browserFlow']) . '/executions');
            if ($r['error'] === '') {
                $flow = 'missing';
                // Liste À PLAT, ordonnée, avec « level » : on garde l'exigence
                // de chaque niveau pour savoir si un parent est désactivé.
                $levels = [];
                foreach ($r['body'] as $ex) {
                    if (!is_array($ex)) continue;
                    $lvl = (int) ($ex['level'] ?? 0);
                    $req = strtoupper((string) ($ex['requirement'] ?? 'DISABLED'));
                    $levels[$lvl] = $req;
                    foreach (array_keys($levels) as $k) {
                        if ($k > $lvl) unset($levels[$k]);
                    }
                    $provider = (string) ($ex['providerId'] ?? '');
                    if ($provider !== 'webauthn-authenticator' && $provider !== 'webauthn-authenticator-passwordless') {
                        continue;
                    }
                    if (!in_array('DISABLED', $levels, true)) {
                        $flow = 'ready';
                        break;
                    }
                }
            }
        }

        $action = 'unknown';
        $a = kcAccRequest('GET', '/authentication/required-actions/' . rawurlencode(KC_ACC_WEBAUTHN_ACTION));
        if ($a['error'] === '' && $a['body'] !== []) {
            $action = !empty($a['body']['enabled']) ? 'enabled' : 'disabled';
        } elseif ($a['status'] === 404) {
            $action = 'disabled';   // action non enregistrée sur ce realm
        }

        return $cache = ['flow' => $flow, 'action' => $action];
    }
}

if (!function_exists('kcAccSecurityKeyStatus')) {
    /**
     * @return array{enabled:bool, available:bool, credentials:array, server:array{flow:string,action:string}}
     *   available = false si la liste n'a pas pu être lue (erreur Keycloak).
     */
    function kcAccSecurityKeyStatus(string $userId): array
    {
        $keys = kcAccSecurityKeys($userId);
        return [
            'enabled'     => !empty($keys),
            'available'   => $keys !== null,
            'credentials' => $keys ?? [],
            'server'      => kcAccWebAuthnServerStatus(),
        ];
    }
}

if (!function_exists('kcAccFindSecurityKey')) {
    /**
     * La clé $credId appartient-elle à CE compte et est-elle bien de type
     * WebAuthn ? Garde-fou indispensable : sans lui, account.keys.delete
     * permettrait de supprimer le credential « password » ou « otp ».
     */
    function kcAccFindSecurityKey(string $userId, string $credId): ?array
    {
        if ($credId === '') return null;
        foreach (kcAccSecurityKeys($userId) ?? [] as $k) {
            if (hash_equals($k['id'], $credId)) return $k;
        }
        return null;
    }
}

if (!function_exists('kcAccRenameSecurityKey')) {
    /**
     * PUT /users/{id}/credentials/{credId}/userLabel — corps text/plain.
     *
     * @return array{ok:bool, error:string}
     */
    function kcAccRenameSecurityKey(string $userId, string $credId, string $label): array
    {
        $label = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label) ?? '');
        if ($label === '') {
            return ['ok' => false, 'error' => "Donnez un nom à la clé (ex. « YubiKey bureau »)."];
        }
        if (mb_strlen($label) > 60) {
            return ['ok' => false, 'error' => "Nom trop long (60 caractères maximum)."];
        }
        if (kcAccFindSecurityKey($userId, $credId) === null) {
            return ['ok' => false, 'error' => "Cette clé n'existe plus sur votre compte."];
        }

        $r = kcAccRequest(
            'PUT',
            '/users/' . rawurlencode($userId) . '/credentials/' . rawurlencode($credId) . '/userLabel',
            null,
            [],
            $label
        );
        return ['ok' => $r['error'] === '', 'error' => $r['error']];
    }
}

if (!function_exists('kcAccDeleteSecurityKey')) {
    /**
     * Supprime une clé. Mot de passe (et code TOTP si actif) exigés : retirer
     * un facteur est aussi sensible que désactiver la 2FA.
     *
     * @return array{ok:bool, error:string, remaining:int}
     */
    function kcAccDeleteSecurityKey(string $userId, string $credId, string $currentPassword, string $totp = ''): array
    {
        $fail = static function (string $m): array { return ['ok' => false, 'error' => $m, 'remaining' => -1]; };

        $key = kcAccFindSecurityKey($userId, $credId);
        if ($key === null) return $fail("Cette clé n'existe plus sur votre compte.");

        $cur = kcAccGetUser($userId, true);
        if (!$cur['ok']) return $fail($cur['error']);

        $username = (string) ($cur['user']['username'] ?? '');
        if (!kcAccVerifyPassword($username, $currentPassword, $totp)) {
            return $fail(kcAccBadSecretMessage($userId, "Mot de passe incorrect : la clé n'a pas été supprimée."));
        }

        $r = kcAccRequest('DELETE', '/users/' . rawurlencode($userId) . '/credentials/' . rawurlencode($credId));
        if ($r['error'] !== '') return $fail($r['error']);

        $left = kcAccSecurityKeys($userId);
        return ['ok' => true, 'error' => '', 'remaining' => $left === null ? -1 : count($left)];
    }
}

if (!function_exists('kcAccLoginNeedsSecurityKey')) {
    /**
     * Appelée par /connexion APRÈS un grant password réussi : faut-il
     * renvoyer ce compte vers la page Keycloak hébergée pour qu'il présente
     * sa clé ?
     *
     * Échec FERMÉ : si Keycloak ne répond pas (rôle view-users manquant,
     * panne…), on répond true. La page hébergée sait traiter tous les
     * comptes, avec ou sans clé ; ouvrir une session par mot de passe seul
     * sur un compte peut-être protégé serait, lui, une faille.
     */
    function kcAccLoginNeedsSecurityKey(string $userId): bool
    {
        $keys = kcAccSecurityKeys($userId);
        if ($keys === null) {
            error_log('[GNL ACCOUNT] clés de sécurité illisibles pour ' . $userId
                . ' — connexion redirigée vers la page Keycloak par précaution.');
            return true;
        }
        return $keys !== [];
    }
}

/* ===================================================================
   Sessions Keycloak (SSO)
   =================================================================== */

if (!function_exists('kcAccSessions')) {
    /** Sessions SSO actives du compte, les plus récentes d'abord. */
    function kcAccSessions(string $userId): array
    {
        $r = kcAccRequest('GET', '/users/' . rawurlencode($userId) . '/sessions');
        if ($r['error'] !== '') return [];

        $out = [];
        foreach ($r['body'] as $s) {
            if (!is_array($s)) continue;
            $clients = is_array($s['clients'] ?? null) ? array_values($s['clients']) : [];
            $out[] = [
                'id'         => (string) ($s['id'] ?? ''),
                'ip'         => (string) ($s['ipAddress'] ?? ''),
                'start'      => (int) ($s['start'] ?? 0),
                'lastAccess' => (int) ($s['lastAccess'] ?? 0),
                'clients'    => array_map('strval', $clients),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return $b['lastAccess'] <=> $a['lastAccess'];
        });
        return $out;
    }
}

if (!function_exists('kcAccDeleteSession')) {
    /** ⚠️ Endpoint au niveau REALM, pas sous /users/{id}. */
    function kcAccDeleteSession(string $sessionId): array
    {
        if ($sessionId === '') return ['ok' => false, 'error' => "Session inconnue."];
        $r = kcAccRequest('DELETE', '/sessions/' . rawurlencode($sessionId));
        return ['ok' => $r['error'] === '', 'error' => $r['error']];
    }
}

if (!function_exists('kcAccLogoutAll')) {
    /** Ferme TOUTES les sessions SSO du compte (y compris la courante). */
    function kcAccLogoutAll(string $userId): array
    {
        $r = kcAccRequest('POST', '/users/' . rawurlencode($userId) . '/logout', []);
        return ['ok' => $r['error'] === '', 'error' => $r['error']];
    }
}

/* ===================================================================
   Rafraîchissement de $_SESSION['user'] après écriture
   =================================================================== */

if (!function_exists('kcAccRefreshSession')) {
    /**
     * Recopie dans la session PHP ce que Keycloak vient d'enregistrer, pour
     * que l'en-tête et le menu affichent la nouvelle identité sans
     * reconnexion. N'invente rien : uniquement les champs relus.
     */
    function kcAccRefreshSession(string $userId): void
    {
        if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) return;

        $u = kcAccGetUser($userId, true);
        if (!$u['ok']) return;

        $row   = $u['user'];
        $attrs = kcAccFlattenAttrs($row['attributes'] ?? []);

        $map = [
            'username'  => (string) ($row['username'] ?? ''),
            'email'     => (string) ($row['email'] ?? ''),
            'prenom'    => (string) ($row['firstName'] ?? ''),
            'nom'       => (string) ($row['lastName'] ?? ''),
            'firstName' => (string) ($row['firstName'] ?? ''),
            'lastName'  => (string) ($row['lastName'] ?? ''),
            'civilite'  => (string) ($attrs['civilite'] ?? ''),
            'phone'     => (string) ($attrs['phone'] ?? ''),
            'telephone' => (string) ($attrs['phone'] ?? ''),
            'fonction'  => (string) ($attrs['fonction'] ?? ''),
        ];
        foreach ($map as $k => $v) {
            if ($v !== '') $_SESSION['user'][$k] = $v;
        }
    }
}

/* =====================================================================
   PRÉ-REQUIS KEYCLOAK (à faire une fois, côté serveur)
   ---------------------------------------------------------------------
   Sur le client OIDC du portail (KEYCLOAK_CLIENT_ID, « esp-client » en
   production, realm « client-auth ») :

     - « Client authentication » = ON           (déjà requis par /connexion)
     - « Service accounts roles » = ON          (grant client_credentials)
     - « Direct access grants »   = ON          (déjà requis par /connexion ;
                                                 sert ici à vérifier le mot de
                                                 passe actuel)

   Rôles à affecter au compte de service « service-account-esp-client »,
   dans les rôles du client « realm-management » :

     - manage-users   — écrire le profil, reset-password, supprimer un
                        credential OTP, fermer une session, execute-actions-email
     - view-users     — relire le profil et les credentials
     - view-realm     — OPTIONNEL : permet de savoir si l'identifiant est
                        modifiable (editUsernameAllowed) et si l'identifiant
                        suit l'e-mail. Sans ce rôle la page reste fonctionnelle,
                        elle propose simplement le champ et laisse Keycloak
                        refuser avec un message clair.

   Un SMTP doit être configuré sur le realm pour que l'activation de la 2FA
   et la vérification d'e-mail envoient leur lien. Sans SMTP, l'activation
   fonctionne quand même : l'action requise s'impose à la prochaine connexion.

   CLÉS DE SÉCURITÉ (WebAuthn) — en plus :

     1. Authentication → Required actions : « Webauthn Register » = Enabled.
     2. Authentication → flow lié à « Browser flow » (par défaut « browser ») :
        dans le sous-flow conditionnel de second facteur (« Browser -
        Conditional OTP » / « … 2FA »), ajouter l'étape « WebAuthn
        Authenticator » en ALTERNATIVE, à côté de « OTP Form ». Sans cela
        la clé est enregistrée mais JAMAIS demandée.
     3. Authentication → Policies → Webauthn Policy : « Relying Party ID »
        = domaine de Keycloak (auth.gnl-solution.fr) ou laissé vide ;
        « User verification » = preferred / discouraged pour une clé simple.
     4. Client « esp-client » : KEYCLOAK_REDIRECT_URI dans les « Valid
        redirect URIs » (déjà requis par /keycloak_login.php).

   Le rôle view-realm (optionnel) permet à /account de vérifier 1 et 2 et
   d'avertir le client si la clé ne sera pas demandée.
   ===================================================================== */
