<?php

declare(strict_types=1);

/**
 * data/account_api.php
 *
 * Point d'entrée JSON de la page « Mon compte » (pages/account.php).
 * Toute la logique Keycloak vit dans include/keycloak_account.php ; ce
 * fichier ne fait que : authentifier, vérifier le CSRF, router l'action,
 * répondre en JSON.
 *
 * Actions (POST sauf mention contraire) :
 *
 *   account.load           GET  — profil + 2FA + sessions
 *   account.profile.save        — état civil, téléphone, fonction, langue
 *   account.identity.save       — identifiant + e-mail (VERIFY_EMAIL auto)
 *   account.password.change     — mot de passe actuel vérifié, puis reset
 *   account.2fa.enable          — action requise CONFIGURE_TOTP + e-mail
 *   account.2fa.disable         — DELETE credential otp (mot de passe exigé)
 *   account.sessions.list       — sessions Keycloak + sessions portail
 *   account.sessions.revoke     — ferme UNE session (kc:<id> ou local:<id>)
 *   account.sessions.revoke_others — ferme toutes les autres sessions portail
 *
 * Convention de réponse identique aux autres endpoints data/ :
 *   { ok:true, ... } ou { ok:false, error:"…" }
 *
 * ⚠️ Jamais de 5xx : l'Ingress porte le middleware Traefik « custom-errors »
 *    qui remplace le CORPS de toute réponse 5xx par une page générique — le
 *    message n'atteindrait jamais le navigateur. On répond 200 avec
 *    « ok:false » et le vrai statut dans « code ».
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../include/session_bootstrap.php';
require_once __DIR__ . '/../config_loader.php';
require_once __DIR__ . '/../include/account_sessions.php';
require_once __DIR__ . '/../include/keycloak_account.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function account_api_send(int $status, array $payload): void
{
    if ($status >= 500) {
        $payload['code'] = $status;
        $status = 200;
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ───────────────────────── Authentification ───────────────────────── */

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    account_api_send(401, ['ok' => false, 'error' => 'Non authentifié.']);
}

// ['id'] est l'UID Keycloak (UUID) ; ['account_id'] l'entier stable réservé
// aux tables locales à clé INT. (int) d'un UUID vaut 0 dès qu'il commence par
// une lettre : ce cast ne peut servir ni à juger la session, ni de clé.
$accountId = (int) ($_SESSION['user']['account_id'] ?? 0);
if ($accountId <= 0 && ctype_digit((string) ($_SESSION['user']['id'] ?? ''))) {
    $accountId = (int) $_SESSION['user']['id'];
}

if ($accountId > 0) {
    if (accountSessionsIsCurrentSessionRevoked($pdo, $accountId)) {
        accountSessionsDestroyPhpSession();
        account_api_send(401, ['ok' => false, 'error' => 'Cette session a été déconnectée depuis vos paramètres.']);
    }
    accountSessionsTouchCurrent($pdo, $accountId);
}

$kcUserId = kcAccUserId($_SESSION['user']);
if ($kcUserId === '') {
    account_api_send(200, [
        'ok'    => false,
        'error' => "Votre session ne porte pas d'identifiant Keycloak exploitable. Déconnectez-vous puis reconnectez-vous.",
    ]);
}

/* ─────────────────────── Entrée : action + corps ───────────────────── */

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$raw    = file_get_contents('php://input');
$json   = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$in     = is_array($json) ? $json : $_POST;

$action = (string) ($_GET['action'] ?? $in['action'] ?? '');
if ($action === '') {
    account_api_send(400, ['ok' => false, 'error' => 'Action manquante.']);
}

/* ───────────────────────────── CSRF ───────────────────────────────── */

/**
 * Toute action qui ÉCRIT exige le jeton CSRF de la session (même clé que
 * include/header.php et data/portail_api.php). La lecture en est dispensée.
 */
function account_api_require_csrf(array $in): void
{
    $expected = (string) ($_SESSION['csrf'] ?? '');
    $given    = (string) ($in['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        account_api_send(403, ['ok' => false, 'error' => 'Jeton de sécurité invalide. Rechargez la page.']);
    }
}

$readOnly = ($action === 'account.load' || $action === 'account.sessions.list');
if (!$readOnly) {
    if ($method !== 'POST') {
        account_api_send(405, ['ok' => false, 'error' => 'Méthode non autorisée.']);
    }
    account_api_require_csrf($in);
}

/* ───────────────────────── Sessions : agrégat ─────────────────────── */

/**
 * Sessions Keycloak (SSO, tous clients du realm) + sessions du portail
 * (table user_account_sessions). Les deux listes sont distinctes : fermer
 * une session SSO ne ferme pas la session PHP du portail, et inversement.
 * La page les présente séparément pour éviter toute confusion.
 */
function account_api_sessions(string $kcUserId, int $accountId): array
{
    global $pdo;

    $local   = [];
    $current = accountSessionsHashSessionId(accountSessionsCurrentSessionId());

    if ($accountId > 0) {
        foreach (accountSessionsListForUser($pdo, $accountId) as $row) {
            $local[] = [
                'id'        => (int) ($row['id'] ?? 0),
                'device'    => (string) ($row['device_label'] ?? ''),
                'ip'        => (string) ($row['ip_address'] ?? ''),
                'createdAt' => accountSessionsFormatDate($row['created_at'] ?? null),
                'lastSeen'  => accountSessionsFormatDate($row['last_activity_at'] ?? null),
                'current'   => hash_equals($current, (string) ($row['session_id_hash'] ?? '')),
            ];
        }
    }

    return ['keycloak' => kcAccSessions($kcUserId), 'portal' => $local];
}

/* ───────────────────────────── Routage ────────────────────────────── */

try {
    switch ($action) {

        case 'account.load': {
            $p = kcAccLoadProfile($kcUserId);
            if (!$p['ok']) {
                account_api_send(200, ['ok' => false, 'error' => $p['error']]);
            }
            account_api_send(200, [
                'ok'       => true,
                'profile'  => $p['profile'],
                'sessions' => account_api_sessions($kcUserId, $accountId),
            ]);
        }

        case 'account.profile.save': {
            $r = kcAccSaveProfile($kcUserId, $in);
            if (!$r['ok']) {
                account_api_send(200, ['ok' => false, 'error' => $r['error']]);
            }
            kcAccRefreshSession($kcUserId);
            $p = kcAccLoadProfile($kcUserId);
            account_api_send(200, [
                'ok'      => true,
                'notice'  => 'Profil enregistré.',
                'profile' => $p['ok'] ? $p['profile'] : null,
            ]);
        }

        case 'account.identity.save': {
            $r = kcAccSaveIdentity($kcUserId, $in);
            if (!$r['ok']) {
                account_api_send(200, ['ok' => false, 'error' => $r['error']]);
            }
            kcAccRefreshSession($kcUserId);
            $p = kcAccLoadProfile($kcUserId);
            account_api_send(200, [
                'ok'      => true,
                'notice'  => $r['notice'],
                'profile' => $p['ok'] ? $p['profile'] : null,
            ]);
        }

        case 'account.password.change': {
            $r = kcAccChangePassword($kcUserId, $in);
            if (!$r['ok']) {
                account_api_send(200, ['ok' => false, 'error' => $r['error']]);
            }
            account_api_send(200, [
                'ok'     => true,
                'notice' => "Mot de passe modifié. Vos autres sessions restent ouvertes : "
                    . "fermez-les depuis l'onglet « Sessions » si vous le souhaitez.",
            ]);
        }

        case 'account.2fa.enable': {
            $r = kcAccEnableTwoFactor($kcUserId);
            if (!$r['ok']) {
                account_api_send(200, ['ok' => false, 'error' => $r['error']]);
            }
            $p = kcAccLoadProfile($kcUserId);
            account_api_send(200, [
                'ok'        => true,
                'notice'    => $r['notice'],
                'emailSent' => $r['emailSent'],
                'profile'   => $p['ok'] ? $p['profile'] : null,
            ]);
        }

        case 'account.2fa.disable': {
            $r = kcAccDisableTwoFactor($kcUserId, (string) ($in['current'] ?? ''));
            if (!$r['ok']) {
                account_api_send(200, ['ok' => false, 'error' => $r['error']]);
            }
            $p = kcAccLoadProfile($kcUserId);
            account_api_send(200, [
                'ok'      => true,
                'notice'  => 'Double authentification désactivée.',
                'profile' => $p['ok'] ? $p['profile'] : null,
            ]);
        }

        case 'account.sessions.list': {
            account_api_send(200, ['ok' => true, 'sessions' => account_api_sessions($kcUserId, $accountId)]);
        }

        case 'account.sessions.revoke': {
            $target = trim((string) ($in['target'] ?? ''));

            if (strncmp($target, 'kc:', 3) === 0) {
                $r = kcAccDeleteSession(substr($target, 3));
                if (!$r['ok']) {
                    account_api_send(200, ['ok' => false, 'error' => $r['error']]);
                }
                account_api_send(200, [
                    'ok'       => true,
                    'notice'   => 'Session fermée.',
                    'sessions' => account_api_sessions($kcUserId, $accountId),
                ]);
            }

            if (strncmp($target, 'local:', 6) === 0) {
                $id = (int) substr($target, 6);
                if ($accountId <= 0 || $id <= 0) {
                    account_api_send(200, ['ok' => false, 'error' => 'Session inconnue.']);
                }
                // accountSessionsRevokeById() filtre sur user_id : une session
                // d'un AUTRE compte ne peut pas être fermée par cet appel.
                if (!accountSessionsRevokeById($pdo, $accountId, $id)) {
                    account_api_send(200, ['ok' => false, 'error' => "Cette session n'existe plus."]);
                }
                account_api_send(200, [
                    'ok'       => true,
                    'notice'   => 'Session fermée.',
                    'sessions' => account_api_sessions($kcUserId, $accountId),
                ]);
            }

            account_api_send(400, ['ok' => false, 'error' => 'Cible de session invalide.']);
        }

        case 'account.sessions.revoke_others': {
            if ($accountId <= 0) {
                account_api_send(200, ['ok' => false, 'error' => 'Aucune session locale à fermer.']);
            }
            $n = accountSessionsRevokeOtherSessions($pdo, $accountId);
            account_api_send(200, [
                'ok'       => true,
                'notice'   => $n > 0
                    ? $n . ' session(s) fermée(s). La session courante reste ouverte.'
                    : 'Aucune autre session ouverte.',
                'sessions' => account_api_sessions($kcUserId, $accountId),
            ]);
        }

        default:
            account_api_send(400, ['ok' => false, 'error' => 'Action inconnue : ' . $action]);
    }
} catch (Throwable $e) {
    error_log('[account_api] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    account_api_send(500, ['ok' => false, 'error' => "Une erreur interne est survenue. Réessayez."]);
}
