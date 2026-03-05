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
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
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
if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $namespace)) {
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
            if ($deployment === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }
            $d = $k8s->getDeployment($namespace, $deployment);
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployment' => $d]);
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
            if ($deployment === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $deployment)) {
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
