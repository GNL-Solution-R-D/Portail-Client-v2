<?php

/**
 * Backend-only Kubernetes actions endpoint.
 *
 * Browser calls THIS endpoint.
 * This endpoint calls the Kubernetes API using the Pod ServiceAccount.
 */

declare(strict_types=1);

session_start();

// Force clean JSON responses (avoid PHP warnings/HTML breaking fetch().)
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/KubernetesClient.php';

// Namespace comes from the authenticated user's profile/session.
$namespace = $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['namespace']
    ?? null;

if (!is_string($namespace) || $namespace === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Namespace manquant dans le profil utilisateur.']);
    exit;
}

// Strict-ish namespace validation (DNS label / dns subdomain friendly).
if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $namespace)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Namespace invalide.']);
    exit;
}

$action = $_GET['action'] ?? '';

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

                $specReplicas  = (int)($d['spec']['replicas'] ?? 0);
                $readyReplicas = (int)($d['status']['readyReplicas'] ?? 0);
                $updatedRep    = (int)($d['status']['updatedReplicas'] ?? 0);
                $availableRep  = (int)($d['status']['availableReplicas'] ?? 0);
                $createdAt     = $d['metadata']['creationTimestamp'] ?? null;

                $deployments[] = [
                    'name' => $name,
                    'replicas' => $specReplicas,
                    'ready' => $readyReplicas,
                    'updated' => $updatedRep,
                    'available' => $availableRep,
                    'createdAt' => is_string($createdAt) ? $createdAt : null,
                ];
            }

            usort($deployments, fn($a, $b) => strcmp($a['name'], $b['name']));

            echo json_encode(['ok' => true, 'namespace' => $namespace, 'deployments' => $deployments]);
            exit;
        }

        case 'restart_deployment': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
                exit;
            }

            // Basic CSRF: optional but recommended.
            $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (isset($_SESSION['csrf']) && $_SESSION['csrf'] !== '' && !hash_equals($_SESSION['csrf'], $csrf)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'CSRF invalid']);
                exit;
            }

            $deployment = $_POST['name'] ?? '';
            if (!is_string($deployment) || $deployment === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $deployment)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Nom de deployment invalide.']);
                exit;
            }

            $k8s->restartDeployment($namespace, $deployment);
            echo json_encode(['ok' => true, 'namespace' => $namespace, 'deployment' => $deployment]);
            exit;
        }

        case 'get_deployment': {
            $deployment = $_GET['name'] ?? '';
            if (!is_string($deployment) || $deployment === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $deployment)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Nom de deployment invalide.']);
                exit;
            }
            $d = $k8s->getDeployment($namespace, $deployment);
            echo json_encode(['ok' => true, 'namespace' => $namespace, 'deployment' => $d]);
            exit;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Action inconnue.']);
            exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
    exit;
}
