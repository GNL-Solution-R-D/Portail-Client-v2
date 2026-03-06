<?php

/**
 * Backend-only Kubernetes actions endpoint.
 *
 * Le navigateur appelle CE endpoint.
 * CE endpoint parle à l'API Kubernetes avec le ServiceAccount du Pod.
 */

declare(strict_types=1);

// Cookie de session valable sur /pages/* ET /k8s/*
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function send_json(int $status, array $payload): void {
    http_response_code($status);
    $flags = JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
    exit;
}

function is_k8s_name(string $s): bool {
    return $s !== '' && (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $s);
}

function is_k8s_dns_subdomain(string $s): bool {
    return $s !== '' && (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $s);
}

if (!isset($_SESSION['user'])) {
    send_json(401, [
        'ok' => false,
        'error' => 'Unauthorized (cookie de session absent ? vérifie session.cookie_path)',
    ]);
}

$user = $_SESSION['user'];
if (!is_array($user)) {
    send_json(500, [
        'ok' => false,
        'error' => 'Session user invalide (attendu: array).',
    ]);
}

require_once __DIR__ . '/KubernetesClient.php';

// Namespace vient du profil utilisateur (session).
$namespace = $user['k8s_namespace']
    ?? $user['k8sNamespace']
    ?? $user['namespace_k8s']
    ?? $user['k8s_ns']
    ?? $user['namespace']
    ?? null;

if (!is_string($namespace) || $namespace === '') {
    send_json(400, [
        'ok' => false,
        'error' => 'Namespace manquant dans le profil utilisateur (ex: user[k8s_namespace]).',
    ]);
}

// Validation simple de namespace (DNS label/subdomain).
if (!is_k8s_dns_subdomain($namespace)) {
    send_json(400, ['ok' => false, 'error' => 'Namespace invalide.']);
}

$action = (string)($_GET['action'] ?? '');

try {
    $k8s = new KubernetesClient();

    switch ($action) {
        case 'list_deployments': {
            $data = $k8s->listDeployments($namespace);
            $items = $data['items'] ?? [];
            $deployments = [];

            foreach ($items as $d) {
                $name = $d['metadata']['name'] ?? null;
                if (!is_string($name) || $name === '') continue;

                $deployments[] = [
                    'name' => $name,
                    'replicas' => (int)($d['spec']['replicas'] ?? 0),
                    'ready' => (int)($d['status']['readyReplicas'] ?? 0),
                    'updated' => (int)($d['status']['updatedReplicas'] ?? 0),
                    'available' => (int)($d['status']['availableReplicas'] ?? 0),
                    'createdAt' => is_string($d['metadata']['creationTimestamp'] ?? null) ? $d['metadata']['creationTimestamp'] : null,
                ];
            }

            usort($deployments, fn($a, $b) => strcmp($a['name'], $b['name']));
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployments' => $deployments]);
        }

        case 'get_deployment': {
            $deployment = (string)($_GET['name'] ?? '');
            if (!is_k8s_name($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }
            $d = $k8s->getDeployment($namespace, $deployment);
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployment' => $d]);
        }

        case 'list_pods_for_deployment': {
            $deployment = (string)($_GET['deployment'] ?? ($_GET['name'] ?? ''));
            if (!is_k8s_name($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }

            $d = $k8s->getDeployment($namespace, $deployment);
            $labels = $d['spec']['selector']['matchLabels'] ?? [];
            if (!is_array($labels) || $labels === []) {
                send_json(400, ['ok' => false, 'error' => 'Selector du deployment vide.']);
            }

            $parts = [];
            foreach ($labels as $k => $v) {
                if (!is_string($k) || !is_string($v) || $k === '' || $v === '') continue;
                // Keep it simple: exact match key=value, joined by commas.
                $parts[] = $k . '=' . $v;
            }
            if ($parts === []) {
                send_json(400, ['ok' => false, 'error' => 'Selector du deployment invalide.']);
            }

            $selector = implode(',', $parts);
            $podsRaw = $k8s->listPods($namespace, $selector, 200);
            $items = $podsRaw['items'] ?? [];
            $pods = [];

            foreach ($items as $p) {
                $pName = $p['metadata']['name'] ?? null;
                if (!is_string($pName) || $pName === '') continue;

                $phase = (string)($p['status']['phase'] ?? 'Unknown');
                $createdAt = is_string($p['metadata']['creationTimestamp'] ?? null) ? $p['metadata']['creationTimestamp'] : null;
                $nodeName = is_string($p['spec']['nodeName'] ?? null) ? $p['spec']['nodeName'] : null;

                $containerStatuses = $p['status']['containerStatuses'] ?? [];
                $containers = [];
                $readyCount = 0;
                $restartTotal = 0;
                if (is_array($containerStatuses)) {
                    foreach ($containerStatuses as $cs) {
                        $cName = $cs['name'] ?? null;
                        if (!is_string($cName) || $cName === '') continue;
                        $cReady = (bool)($cs['ready'] ?? false);
                        if ($cReady) $readyCount++;
                        $cRestarts = (int)($cs['restartCount'] ?? 0);
                        $restartTotal += max(0, $cRestarts);
                        $state = $cs['state'] ?? [];
                        $stateText = 'unknown';
                        $reason = null;
                        if (is_array($state)) {
                            if (isset($state['running'])) {
                                $stateText = 'running';
                            } elseif (isset($state['waiting'])) {
                                $stateText = 'waiting';
                                if (is_array($state['waiting']) && isset($state['waiting']['reason'])) $reason = (string)$state['waiting']['reason'];
                            } elseif (isset($state['terminated'])) {
                                $stateText = 'terminated';
                                if (is_array($state['terminated']) && isset($state['terminated']['reason'])) $reason = (string)$state['terminated']['reason'];
                            }
                        }

                        $containers[] = [
                            'name' => $cName,
                            'ready' => $cReady,
                            'restartCount' => $cRestarts,
                            'image' => is_string($cs['image'] ?? null) ? $cs['image'] : null,
                            'state' => $stateText,
                            'reason' => $reason,
                        ];
                    }
                }

                $totalContainers = is_array($p['spec']['containers'] ?? null) ? count($p['spec']['containers']) : count($containers);

                $pods[] = [
                    'name' => $pName,
                    'phase' => $phase,
                    'readyContainers' => $readyCount,
                    'totalContainers' => $totalContainers,
                    'restartCount' => $restartTotal,
                    'createdAt' => $createdAt,
                    'node' => $nodeName,
                    'containers' => $containers,
                ];
            }

            usort($pods, function($a, $b){
                return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
            });

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'deployment' => $deployment,
                'labelSelector' => $selector,
                'pods' => $pods,
            ]);
        }

        case 'pod_logs_tail': {
            $pod = (string)($_GET['pod'] ?? '');
            $container = (string)($_GET['container'] ?? '');
            $tail = (int)($_GET['tail'] ?? 50);
            $tail = max(1, min(5000, $tail));
            $timestamps = (string)($_GET['timestamps'] ?? '1');
            $previous = (string)($_GET['previous'] ?? '0');

            if (!is_k8s_name($pod)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de pod invalide.']);
            }
            if ($container !== '' && !is_k8s_dns_subdomain($container)) {
                // container names can include dots in some setups, keep DNS-subdomain level.
                send_json(400, ['ok' => false, 'error' => 'Nom de container invalide.']);
            }

            $text = $k8s->getPodLogs($namespace, $pod, [
                'container' => $container !== '' ? $container : null,
                'tailLines' => $tail,
                'timestamps' => $timestamps !== '0',
                'previous' => $previous === '1',
                // limitBytes optional; keep off for now.
            ]);

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'pod' => $pod,
                'container' => $container !== '' ? $container : null,
                'tail' => $tail,
                'text' => $text,
            ]);
        }

        case 'restart_deployment': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
            }

            // CSRF simple: si tu le mets en place côté app, ça se vérifie ici.
            $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if (isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && $_SESSION['csrf'] !== '' && !hash_equals($_SESSION['csrf'], $csrf)) {
                send_json(403, ['ok' => false, 'error' => 'CSRF invalid']);
            }

            $deployment = (string)($_POST['name'] ?? '');
            if (!is_k8s_name($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }

            $k8s->restartDeployment($namespace, $deployment);
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployment' => $deployment]);
        }

        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue.']);
    }

} catch (Throwable $e) {
    // Ne masque pas les 403/404 Kubernetes derrière un 500 “mystère”.
    $msg = $e->getMessage();
    $status = 500;
    if (preg_match('/\(HTTP\s+(\d{3})\)/', $msg, $m)) {
        $s = (int)$m[1];
        if ($s >= 400 && $s <= 599) $status = $s;
    }
    send_json($status, ['ok' => false, 'error' => $msg]);
}
