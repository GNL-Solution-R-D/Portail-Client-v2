<?php

/**
 * data/services_state_api.php
 *
 * État de SANTÉ des services affichés dans « Mes services »
 * (include/menu.php + assets/js/services_menu.js).
 *
 * À ne pas confondre avec data/services_menu_api.php, qui renvoie le statut de
 * FACTURATION venu de n8n (active / suspended / deployment). Ici on interroge
 * les fournisseurs pour savoir si le service est réellement en panne — la même
 * information que celle qu'affiche sa page de gestion (pages/deployment.php et
 * pages/deployment_ptero.php), mais pour tous les services d'un coup.
 *
 * Réponse :
 *   {
 *     ok: true,
 *     states: {
 *       "<order_product.uid>": { level: "error"|"crash", label: "ERROR"|"CRASH STATE", reason: "…" }
 *     },
 *     warnings: [ "…" ],
 *     cached: false
 *   }
 *
 * Un service en bon état N'APPARAÎT PAS dans « states » : la barre latérale
 * garde alors son badge de statut n8n. Seul un vrai incident écrase ce badge.
 *
 *   • level « error » → badge ERROR, fond rouge, texte blanc.
 *       - l'appel au fournisseur échoue (API injoignable, droits refusés) ;
 *       - le Deployment nommé par provider_service_slug n'existe pas
 *         (« deployments.apps "slapia-web-old" not found (HTTP 404) ») ;
 *       - le serveur Pterodactyl est introuvable ou son identifiant invalide.
 *   • level « crash » → badge CRASH STATE, fond jaune.
 *       - au moins un pod du Deployment est en CrashLoopBackOff.
 *   • level « state » → l'état LIVE du service (running, starting, stopped,
 *     offline), émis UNIQUEMENT quand le statut n8n vaut « active ».
 *       Il remplace alors « active » dans la barre latérale : un service
 *       facturé est actif par définition, l'information utile est son état
 *       réel. « suspended » et « deployment » disent, eux, quelque chose que
 *       le fournisseur ignore : on les laisse intacts.
 *
 * COÛT. La barre latérale est présente sur toutes les pages : cet endpoint est
 * donc conçu pour un nombre d'appels fournisseurs CONSTANT, pas proportionnel
 * au nombre de services.
 *   • Kubernetes : 2 appels pour tout le namespace (liste des Deployments,
 *     liste des Pods), quel que soit le nombre de services.
 *   • Pterodactyl : pas d'action de liste côté panel, donc 1 appel par serveur,
 *     plafonné par SERVICES_STATE_MAX_PTERO. Le quota du panel est compté par
 *     COMPTE et partagé par tout le portail (voir data/ptero_api.php) : un 429
 *     interrompt la boucle sans marquer personne en erreur.
 * Le tout est mémorisé SERVICES_STATE_TTL secondes en session. « ?refresh=1 »
 * force la relecture.
 *
 * ⚠️ Jamais de 5xx : l'Ingress porte le middleware Traefik « custom-errors »
 *    qui remplace le CORPS de toute réponse 5xx par une page générique — le
 *    message n'atteindrait jamais le navigateur. Convention du portail :
 *    HTTP 200, « ok: false », vrai statut dans « code ».
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
require_once __DIR__ . '/../include/session_user.php';
require_once __DIR__ . '/../include/services_catalog.php';
require_once __DIR__ . '/KubernetesClient.php';
require_once __DIR__ . '/PterodactylClient.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Durée du cache session de l'état des services. */
const SERVICES_STATE_TTL = 60;

/** Garde-fou : au-delà, on n'interroge plus le panel Pterodactyl. */
const SERVICES_STATE_MAX_PTERO = 12;

function services_state_send(int $status, array $payload): void
{
    if ($status >= 500) {
        $payload['code'] = $status;
        $status = 200;
    }

    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Authentification (identique aux autres endpoints data/) ───────────────────
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    services_state_send(401, ['ok' => false, 'error' => 'Non authentifié.']);
}

// $_SESSION['user']['id'] est l'UID Keycloak (UUID) ; ['account_id'] l'entier
// stable réservé aux tables locales à clé INT. (int) d'un UUID vaut 0 dès qu'il
// commence par une lettre (a-f, soit ~1 compte sur 3) : ce cast ne peut donc
// servir NI à juger qu'une session est valide, NI de clé pour
// user_account_sessions. Même garde que data/services_menu_api.php.
$clientUid = trim((string)($_SESSION['user']['id'] ?? ''));
$accountId = (int)($_SESSION['user']['account_id'] ?? 0);
if ($accountId <= 0 && ctype_digit($clientUid)) {
    $accountId = (int)$clientUid;  // sessions historiques : id = entier local
}
if ($clientUid === '' && $accountId <= 0) {
    services_state_send(401, ['ok' => false, 'error' => 'Identifiant client introuvable dans la session.']);
}
$clientId = $accountId;

if ($accountId > 0) {
    if (accountSessionsIsCurrentSessionRevoked($pdo, $accountId)) {
        accountSessionsDestroyPhpSession();
        services_state_send(401, ['ok' => false, 'error' => 'Cette session a été déconnectée depuis vos paramètres.']);
    }
    accountSessionsTouchCurrent($pdo, $accountId);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function services_state_error(string $reason): array
{
    return ['level' => 'error', 'label' => 'ERROR', 'reason' => $reason];
}

function services_state_crash(string $reason): array
{
    return ['level' => 'crash', 'label' => 'CRASH STATE', 'reason' => $reason];
}

/** État live — le libellé EST l'état : c'est lui que la barre latérale affiche. */
function services_state_live(string $state, string $reason): array
{
    return ['level' => 'state', 'label' => $state, 'reason' => $reason];
}

/**
 * Le pod a-t-il un conteneur en CrashLoopBackOff ? Renvoie la raison lisible,
 * ou null. On regarde aussi les initContainers : un init qui boucle empêche le
 * pod de démarrer tout autant.
 */
function services_state_crash_reason(array $pod): ?string
{
    $status = is_array($pod['status'] ?? null) ? $pod['status'] : [];

    foreach (['containerStatuses', 'initContainerStatuses'] as $key) {
        $list = is_array($status[$key] ?? null) ? $status[$key] : [];
        foreach ($list as $cs) {
            if (!is_array($cs)) {
                continue;
            }
            $waiting = is_array($cs['state']['waiting'] ?? null) ? $cs['state']['waiting'] : [];
            if ((string)($waiting['reason'] ?? '') !== 'CrashLoopBackOff') {
                continue;
            }

            $podName   = (string)($pod['metadata']['name'] ?? '');
            $container = (string)($cs['name'] ?? '');
            $restarts  = (int)($cs['restartCount'] ?? 0);

            return 'CrashLoopBackOff : le conteneur « ' . $container . ' » du pod '
                . $podName . ' redémarre en boucle'
                . ($restarts > 0 ? ' (' . $restarts . ' redémarrage' . ($restarts > 1 ? 's' : '') . ')' : '')
                . '.';
        }
    }

    return null;
}

/**
 * À quel Deployment appartient ce pod ?
 *
 * Un pod géré par un Deployment est possédé par un ReplicaSet nommé
 * « {deployment}-{hash} ». On ne devine donc rien : on cherche, parmi les
 * Deployments RÉELLEMENT présents dans le namespace, celui dont le nom préfixe
 * ce ReplicaSet. Le préfixe le plus long gagne, pour que « web » ne rafle pas
 * les pods de « web-api ».
 *
 * @param string[] $names noms des Deployments du namespace
 */
function services_state_pod_deployment(array $pod, array $names): ?string
{
    $owner = '';
    $refs  = is_array($pod['metadata']['ownerReferences'] ?? null) ? $pod['metadata']['ownerReferences'] : [];
    foreach ($refs as $ref) {
        if (is_array($ref) && (string)($ref['kind'] ?? '') === 'ReplicaSet') {
            $owner = (string)($ref['name'] ?? '');
            break;
        }
    }
    if ($owner === '') {
        $owner = (string)($pod['metadata']['name'] ?? '');
    }
    if ($owner === '') {
        return null;
    }

    $best = null;
    foreach ($names as $name) {
        if ($name === '' || strpos($owner, $name . '-') !== 0) {
            continue;
        }
        if ($best === null || strlen($name) > strlen($best)) {
            $best = $name;
        }
    }

    return $best;
}

// ── Calcul ────────────────────────────────────────────────────────────────────

try {
    $force = isset($_GET['refresh']) && $_GET['refresh'] !== '0' && $_GET['refresh'] !== '';

    $cache = $_SESSION['services_state_cache'] ?? null;
    if (!$force
        && is_array($cache)
        && (time() - (int)($cache['at'] ?? 0)) < SERVICES_STATE_TTL
        && (int)($cache['client_id'] ?? -1) === $clientId
        && is_array($cache['states'] ?? null)
    ) {
        services_state_send(200, [
            'ok'       => true,
            'states'   => (object)$cache['states'],
            'warnings' => is_array($cache['warnings'] ?? null) ? $cache['warnings'] : [],
            'cached'   => true,
        ]);
    }

    $catalog = servicesCatalogFetch($clientId, false);
    $entries = is_array($catalog['entries'] ?? null) ? $catalog['entries'] : [];

    $states   = [];
    $warnings = [];

    $kube  = [];
    $ptero = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $uid  = trim((string)($entry['uid'] ?? ''));
        $slug = trim((string)($entry['provider_service_slug'] ?? ''));
        // Sans slug il n'y a rien à interroger : le service n'est pas encore
        // rattaché à une instance. Ce n'est pas une panne, on laisse son statut
        // n8n parler (« deployment » pendant la mise en service).
        if ($uid === '' || $slug === '') {
            continue;
        }
        // Le statut n8n décide si l'état live a le droit de s'afficher.
        $line = [
            'uid'    => $uid,
            'slug'   => $slug,
            'active' => strtolower(trim((string)($entry['status'] ?? ''))) === 'active',
        ];
        $type = strtolower(trim((string)($entry['provider_type'] ?? '')));
        if ($type === 'kube') {
            $kube[] = $line;
        } elseif ($type === 'ptero') {
            $ptero[] = $line;
        }
    }

    // ── Kubernetes : 2 appels pour tout le namespace ──────────────────────────
    if ($kube !== []) {
        // « ns-k8s » : UID d'organisation Keycloak normalisé RFC1123.
        $namespace = sessionUserNsK8s();

        if (trim($namespace) === '') {
            $warnings[] = 'UID d\'organisation Keycloak absent : états Kubernetes ignorés.';
        } else {
            $namespace = trim($namespace);
            try {
                $k8s = new KubernetesClient();

                $byName = [];
                foreach (($k8s->listDeployments($namespace)['items'] ?? []) as $dep) {
                    if (!is_array($dep)) {
                        continue;
                    }
                    $name = (string)($dep['metadata']['name'] ?? '');
                    if ($name !== '') {
                        $byName[$name] = [
                            'replicas' => (int)($dep['spec']['replicas'] ?? 0),
                            'ready'    => (int)($dep['status']['readyReplicas'] ?? 0),
                        ];
                    }
                }

                // Pods du namespace : un seul appel. Un échec ici ne doit pas
                // faire passer tout le monde en erreur — on perd seulement la
                // détection des CrashLoopBackOff.
                $crash = [];
                try {
                    $names = array_keys($byName);
                    foreach (($k8s->listPods($namespace)['items'] ?? []) as $pod) {
                        if (!is_array($pod)) {
                            continue;
                        }
                        $reason = services_state_crash_reason($pod);
                        if ($reason === null) {
                            continue;
                        }
                        $dep = services_state_pod_deployment($pod, $names);
                        if ($dep !== null && !isset($crash[$dep])) {
                            $crash[$dep] = $reason;
                        }
                    }
                } catch (Throwable $e) {
                    $warnings[] = 'Pods Kubernetes : ' . $e->getMessage();
                }

                foreach ($kube as $svc) {
                    if (!isset($byName[$svc['slug']])) {
                        $states[$svc['uid']] = services_state_error(
                            'Kubernetes : deployments.apps "' . $svc['slug'] . '" not found (HTTP 404).'
                        );
                        continue;
                    }
                    if (isset($crash[$svc['slug']])) {
                        $states[$svc['uid']] = services_state_crash($crash[$svc['slug']]);
                        continue;
                    }
                    if (!$svc['active']) {
                        continue;   // « suspended » / « deployment » : on n'y touche pas
                    }
                    // Même règle que refreshState() de pages/deployment.php, pour
                    // que la barre latérale et la page ne se contredisent jamais.
                    $d        = $byName[$svc['slug']];
                    $replicas = (int)$d['replicas'];
                    $ready    = (int)$d['ready'];
                    $state    = $replicas === 0 ? 'stopped' : ($ready >= $replicas ? 'running' : 'starting');
                    $states[$svc['uid']] = services_state_live(
                        $state,
                        'Kubernetes : ' . $ready . '/' . $replicas . ' pod' . ($replicas > 1 ? 's' : '') . ' prêt'
                            . ($ready > 1 ? 's' : '') . '.'
                    );
                }
            } catch (Throwable $e) {
                // L'API elle-même est hors de portée : tous les services
                // Kubernetes sont en erreur, avec la cause exacte.
                $message = 'Kubernetes : ' . $e->getMessage();
                foreach ($kube as $svc) {
                    $states[$svc['uid']] = services_state_error($message);
                }
            }
        }
    }

    // ── Pterodactyl : 1 appel par serveur, plafonné ───────────────────────────
    if ($ptero !== []) {
        if (!PterodactylClient::isConfigured()) {
            $warnings[] = 'Pterodactyl non configuré : états des serveurs de jeu ignorés.';
        } else {
            $client = new PterodactylClient();
            $done   = 0;

            foreach ($ptero as $svc) {
                if ($done >= SERVICES_STATE_MAX_PTERO) {
                    $warnings[] = 'Plus de ' . SERVICES_STATE_MAX_PTERO
                        . ' serveurs Pterodactyl : les suivants n\'ont pas été interrogés.';
                    break;
                }
                $done++;

                if (!PterodactylClient::isValidServerId($svc['slug'])) {
                    $states[$svc['uid']] = services_state_error(
                        'Pterodactyl : identifiant de serveur invalide (« ' . $svc['slug'] . ' »).'
                    );
                    continue;
                }

                try {
                    $resources = $client->getResources($svc['slug']);
                    if ($svc['active']) {
                        $state = strtolower(trim((string)($resources['current_state'] ?? '')));
                        if ($state !== '') {
                            $states[$svc['uid']] = services_state_live(
                                $state, 'Pterodactyl : serveur ' . $state . '.'
                            );
                        }
                    }
                } catch (PterodactylRateLimitException $e) {
                    // Quota du panel, partagé par tout le portail : ce n'est pas
                    // une panne du service. On s'arrête là sans rien marquer.
                    $warnings[] = 'Quota du panel Pterodactyl atteint : états partiels.';
                    break;
                } catch (Throwable $e) {
                    $states[$svc['uid']] = services_state_error('Pterodactyl : ' . $e->getMessage());
                }
            }
        }
    }

    $_SESSION['services_state_cache'] = [
        'at'        => time(),
        'client_id' => $clientId,
        'states'    => $states,
        'warnings'  => $warnings,
    ];

    services_state_send(200, [
        'ok'       => true,
        'states'   => (object)$states,
        'warnings' => $warnings,
        'cached'   => false,
    ]);
} catch (Throwable $e) {
    error_log('[services_state] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    services_state_send(500, ['ok' => false, 'error' => $e->getMessage()]);
}
