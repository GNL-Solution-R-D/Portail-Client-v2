<?php

/**
 * Backend-only Kubernetes actions endpoint.
 *
 * Le navigateur appelle CE endpoint.
 * CE endpoint parle à l'API Kubernetes avec le ServiceAccount du Pod.
 */

declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

// Cookie de session valable sur /pages/* ET /k8s/*
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    @session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function send_json(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function require_post_with_csrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
    }
    $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && $_SESSION['csrf'] !== '' && !hash_equals($_SESSION['csrf'], $csrf)) {
        send_json(403, ['ok' => false, 'error' => 'CSRF invalid']);
    }
}

function is_dns_label(string $s): bool {
    return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $s);
}

function is_dns_subdomain(string $s): bool {
    return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $s);
}

function is_host(string $host): bool {
    $host = strtolower(trim($host));
    if ($host === '') return false;
    // allow wildcard "*.example.com"
    if (str_starts_with($host, '*.')) {
        return is_dns_subdomain(substr($host, 2));
    }
    return is_dns_subdomain($host);
}

function normalize_path(string $path): string {
    $path = trim($path);
    if ($path === '') return '/';
    if ($path[0] !== '/') $path = '/' . $path;
    // no spaces/control chars
    $path = preg_replace('/[\x00-\x20]+/', '', $path);
    return $path === '' ? '/' : $path;
}

function managed_annotation_key(): string {
    return 'gnl-solution.fr/managed-by';
}

function entry_id_annotation_key(): string {
    return 'gnl-solution.fr/entry-id';
}

function ingress_name_for(string $id, string $host, string $path): string {
    $id = preg_replace('/[^a-z0-9-]/', '-', strtolower($id));
    $id = trim((string)$id, '-');
    if ($id === '') {
        $id = substr(sha1($host . '|' . $path), 0, 10);
    }
    $name = 'public-' . $id;
    if (strlen($name) > 63) {
        $name = 'public-' . substr(sha1($name), 0, 20);
    }
    $name = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
    $name = trim($name, '-');
    if (!is_dns_label($name)) {
        $name = 'public-' . substr(sha1($host . '|' . $path), 0, 20);
    }
    return $name;
}

function parse_tls_secret_cert(array $secret): ?array {
    $data = $secret['data'] ?? null;
    if (!is_array($data)) return null;
    $crtB64 = $data['tls.crt'] ?? null;
    if (!is_string($crtB64) || $crtB64 === '') return null;

    $pem = base64_decode($crtB64, true);
    if (!is_string($pem) || $pem === '') return null;
    if (!function_exists('openssl_x509_parse')) return null;

    $info = @openssl_x509_parse($pem);
    if (!is_array($info)) return null;

    $notAfter = $info['validTo_time_t'] ?? null;
    if (!is_int($notAfter)) return null;

    $now = time();
    $days = (int)floor(($notAfter - $now) / 86400);

    return [
        'notAfter' => gmdate('c', $notAfter),
        'daysRemaining' => $days,
        'expired' => $notAfter <= $now,
    ];
}

// --- Auth/session ---
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

$namespace = strtolower(trim($namespace));
if (!is_dns_subdomain($namespace)) {
    send_json(400, ['ok' => false, 'error' => 'Namespace invalide.']);
}

$action = (string)($_GET['action'] ?? '');

try {
    $k8s = new KubernetesClient();

    switch ($action) {
        // ---------------- Deployments ----------------
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
            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }
            $d = $k8s->getDeployment($namespace, $deployment);
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployment' => $d]);
        }

        case 'restart_deployment': {
            require_post_with_csrf();

            $deployment = (string)($_POST['name'] ?? '');
            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }

            $k8s->restartDeployment($namespace, $deployment);
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployment' => $deployment]);
        }

        // ---------------- Pods + logs ----------------
        case 'list_pods_for_deployment': {
            $deployment = (string)($_GET['deployment'] ?? '');
            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }

            $d = $k8s->getDeployment($namespace, $deployment);
            $labels = $d['spec']['selector']['matchLabels'] ?? [];
            if (!is_array($labels) || $labels === []) {
                send_json(200, ['ok' => true, 'namespace' => $namespace, 'pods' => []]);
            }

            $pairs = [];
            foreach ($labels as $k => $v) {
                if (!is_string($k) || !is_string($v)) continue;
                $pairs[] = $k . '=' . $v;
            }
            $selector = implode(',', $pairs);

            $podsObj = $k8s->listPods($namespace, $selector);
            $items = $podsObj['items'] ?? [];
            $pods = [];
            foreach ($items as $p) {
                if (!is_array($p)) continue;
                $pname = $p['metadata']['name'] ?? null;
                if (!is_string($pname) || $pname === '') continue;

                $containers = $p['spec']['containers'] ?? [];
                $outContainers = [];
                if (is_array($containers)) {
                    foreach ($containers as $c) {
                        $cn = $c['name'] ?? null;
                        if (is_string($cn) && $cn !== '') $outContainers[] = ['name' => $cn];
                    }
                }
                $pods[] = ['name' => $pname, 'containers' => $outContainers];
            }

            usort($pods, fn($a, $b) => strcmp($a['name'], $b['name']));
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'pods' => $pods]);
        }

        case 'pod_logs_tail': {
            $pod = (string)($_GET['pod'] ?? '');
            $container = (string)($_GET['container'] ?? '');
            $tail = (int)($_GET['tail'] ?? 200);
            $timestamps = ((string)($_GET['timestamps'] ?? '1')) === '1';

            if ($pod === '' || !is_dns_subdomain($pod)) {
                send_json(400, ['ok' => false, 'error' => 'Pod invalide.']);
            }
            if ($container !== '' && !is_dns_label($container)) {
                send_json(400, ['ok' => false, 'error' => 'Container invalide.']);
            }

            $tail = max(10, min(5000, $tail));

            $text = $k8s->getPodLogsTail($namespace, $pod, $container !== '' ? $container : null, $tail, $timestamps);
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'text' => $text]);
        }

        // ---------------- Network / Ingress ----------------
        case 'list_public_urls': {
            $deploymentFilter = (string)($_GET['deployment'] ?? '');
            $deploymentFilter = trim($deploymentFilter);
            if ($deploymentFilter !== '' && !is_dns_label($deploymentFilter)) {
                // Filter is optional, but keep it sane.
                $deploymentFilter = '';
            }

            // Services list for dropdown
            $svcs = $k8s->listServices($namespace);
            $services = [];
            foreach (($svcs['items'] ?? []) as $s) {
                if (!is_array($s)) continue;
                $sn = $s['metadata']['name'] ?? null;
                if (!is_string($sn) || $sn === '') continue;
                $ports = [];
                foreach (($s['spec']['ports'] ?? []) as $p) {
                    if (!is_array($p)) continue;
                    $ports[] = [
                        'name' => is_string($p['name'] ?? null) ? $p['name'] : null,
                        'port' => (int)($p['port'] ?? 0),
                        'protocol' => is_string($p['protocol'] ?? null) ? $p['protocol'] : null,
                        'targetPort' => $p['targetPort'] ?? null,
                    ];
                }
                $services[] = ['name' => $sn, 'ports' => $ports];
            }
            usort($services, fn($a, $b) => strcmp($a['name'], $b['name']));

            // Ingress list
            $ing = $k8s->listIngresses($namespace);
            $entries = [];
            foreach (($ing['items'] ?? []) as $i) {
                if (!is_array($i)) continue;

                $meta = $i['metadata'] ?? [];
                $spec = $i['spec'] ?? [];
                $status = $i['status'] ?? [];

                $ingName = is_string($meta['name'] ?? null) ? $meta['name'] : '';
                if ($ingName === '') continue;

                $ann = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
                $managed = (($ann[managed_annotation_key()] ?? '') === 'dashboard');
                $entryId = is_string($ann[entry_id_annotation_key()] ?? null) ? $ann[entry_id_annotation_key()] : '';

                $lb = $status['loadBalancer']['ingress'] ?? [];
                $lbOut = [];
                if (is_array($lb)) {
                    foreach ($lb as $lbi) {
                        if (!is_array($lbi)) continue;
                        $lbOut[] = [
                            'ip' => is_string($lbi['ip'] ?? null) ? $lbi['ip'] : null,
                            'hostname' => is_string($lbi['hostname'] ?? null) ? $lbi['hostname'] : null,
                        ];
                    }
                }

                // TLS secret (first one)
                $tlsSecret = '';
                if (is_array($spec['tls'] ?? null) && isset($spec['tls'][0]) && is_array($spec['tls'][0])) {
                    $tlsSecret = (string)($spec['tls'][0]['secretName'] ?? '');
                }

                // Extract rules/paths
                $rules = $spec['rules'] ?? [];
                if (!is_array($rules)) $rules = [];

                // Only allow editing when it is "simple": 1 rule + 1 path.
                $simpleManaged = false;
                if ($managed && count($rules) === 1) {
                    $r0 = $rules[0];
                    $paths = $r0['http']['paths'] ?? [];
                    if (is_array($paths) && count($paths) === 1) {
                        $simpleManaged = true;
                    }
                }

                foreach ($rules as $r) {
                    if (!is_array($r)) continue;
                    $host = (string)($r['host'] ?? '');
                    if ($host === '') continue;

                    $paths = $r['http']['paths'] ?? [];
                    if (!is_array($paths)) $paths = [];

                    foreach ($paths as $p) {
                        if (!is_array($p)) continue;
                        $path = (string)($p['path'] ?? '/');
                        $path = normalize_path($path);

                        $backend = $p['backend']['service'] ?? null;
                        $svcName = is_array($backend) ? (string)($backend['name'] ?? '') : '';
                        $port = null;
                        if (is_array($backend) && isset($backend['port']) && is_array($backend['port'])) {
                            $port = $backend['port']['number'] ?? ($backend['port']['name'] ?? null);
                        }

                        if ($svcName === '') continue;

                        // Deployment filter (best-effort): match service name or annotation (if present).
                        if ($deploymentFilter !== '') {
                            $annDep = is_string($ann['gnl-solution.fr/deployment'] ?? null) ? $ann['gnl-solution.fr/deployment'] : '';
                            if ($annDep !== $deploymentFilter && !str_contains($svcName, $deploymentFilter) && !str_contains($ingName, $deploymentFilter)) {
                                continue;
                            }
                        }

                        $cert = null;
                        if ($tlsSecret !== '') {
                            try {
                                $sec = $k8s->getSecret($namespace, $tlsSecret);
                                $info = parse_tls_secret_cert($sec);
                                if ($info) {
                                    $cert = [
                                        'status' => ($info['expired'] ?? false) ? 'expired' : 'valid',
                                        'daysRemaining' => $info['daysRemaining'] ?? null,
                                        'notAfter' => $info['notAfter'] ?? null,
                                    ];
                                } else {
                                    $cert = ['status' => 'unknown'];
                                }
                            } catch (Throwable $e) {
                                // RBAC may block secrets/get
                                $cert = ['status' => 'unknown'];
                            }
                        } else {
                            $cert = ['status' => 'none'];
                        }

                        $id = $entryId !== '' ? $entryId : (substr(sha1($ingName . '|' . $host . '|' . $path), 0, 10));

                        $entries[] = [
                            'id' => $id,
                            'ingressName' => $ingName,
                            'managed' => $simpleManaged,
                            'host' => $host,
                            'path' => $path,
                            'service' => $svcName,
                            'port' => is_int($port) ? $port : (is_string($port) ? $port : ''),
                            'tlsSecret' => $tlsSecret,
                            'cert' => $cert,
                            'loadBalancer' => $lbOut,
                        ];
                    }
                }
            }

            // stable ordering
            usort($entries, function($a, $b){
                $x = strcmp((string)$a['host'], (string)$b['host']);
                if ($x !== 0) return $x;
                $x = strcmp((string)$a['path'], (string)$b['path']);
                if ($x !== 0) return $x;
                return strcmp((string)$a['ingressName'], (string)$b['ingressName']);
            });

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'services' => $services,
                'entries' => $entries,
            ]);
        }

        case 'upsert_public_url': {
            require_post_with_csrf();

            $id = (string)($_POST['id'] ?? '');
            $ingressName = (string)($_POST['ingressName'] ?? '');
            $host = strtolower(trim((string)($_POST['host'] ?? '')));
            $path = normalize_path((string)($_POST['path'] ?? '/'));
            $service = trim((string)($_POST['service'] ?? ''));
            $portRaw = trim((string)($_POST['port'] ?? ''));
            $tls = ((string)($_POST['tls'] ?? '0')) === '1';
            $tlsSecret = trim((string)($_POST['tlsSecret'] ?? ''));

            if (!is_host($host)) {
                send_json(400, ['ok' => false, 'error' => 'Host invalide.']);
            }
            if ($service === '' || !is_dns_label($service)) {
                send_json(400, ['ok' => false, 'error' => 'Service invalide.']);
            }
            if ($portRaw === '') {
                send_json(400, ['ok' => false, 'error' => 'Port manquant.']);
            }
            $port = (int)$portRaw;
            if ($port < 1 || $port > 65535) {
                send_json(400, ['ok' => false, 'error' => 'Port invalide.']);
            }
            if ($tls) {
                if ($tlsSecret === '' || !is_dns_label($tlsSecret)) {
                    send_json(400, ['ok' => false, 'error' => 'TLS activé: secret TLS invalide.']);
                }
            } else {
                $tlsSecret = '';
            }

            // Determine ingress name
            if ($ingressName !== '') {
                if (!is_dns_label($ingressName)) {
                    send_json(400, ['ok' => false, 'error' => 'Nom ingress invalide.']);
                }
                // must be managed-by dashboard
                $cur = $k8s->getIngress($namespace, $ingressName);
                $ann = is_array($cur['metadata']['annotations'] ?? null) ? $cur['metadata']['annotations'] : [];
                if (($ann[managed_annotation_key()] ?? '') !== 'dashboard') {
                    send_json(403, ['ok' => false, 'error' => 'Ingress non géré par le dashboard (lecture seule).']);
                }
            } else {
                $ingressName = ingress_name_for($id, $host, $path);
            }

            $annotations = [
                managed_annotation_key() => 'dashboard',
                entry_id_annotation_key() => ($id !== '' ? $id : substr(sha1($host . '|' . $path), 0, 10)),
            ];

            $payload = [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'Ingress',
                'metadata' => [
                    'name' => $ingressName,
                    'annotations' => $annotations,
                ],
                'spec' => [
                    'rules' => [[
                        'host' => $host,
                        'http' => [
                            'paths' => [[
                                'path' => $path,
                                'pathType' => 'Prefix',
                                'backend' => [
                                    'service' => [
                                        'name' => $service,
                                        'port' => ['number' => $port],
                                    ],
                                ],
                            ]],
                        ],
                    ]],
                ],
            ];

            if ($tlsSecret !== '') {
                $payload['spec']['tls'] = [[
                    'hosts' => [$host],
                    'secretName' => $tlsSecret,
                ]];
            }

            // Upsert: if exists -> patch spec/annotations, else create.
            $exists = false;
            try {
                $k8s->getIngress($namespace, $ingressName);
                $exists = true;
            } catch (Throwable $e) {
                $exists = false;
            }

            if ($exists) {
                $k8s->patch("/apis/networking.k8s.io/v1/namespaces/" . rawurlencode($namespace) . "/ingresses/" . rawurlencode($ingressName), [
                    'metadata' => ['annotations' => $annotations],
                    'spec' => $payload['spec'],
                ], 'application/strategic-merge-patch+json');
            } else {
                $k8s->createIngress($namespace, $payload);
            }

            send_json(200, ['ok' => true, 'namespace' => $namespace, 'ingressName' => $ingressName]);
        }

        case 'delete_public_url': {
            require_post_with_csrf();

            $ingressName = (string)($_POST['ingressName'] ?? '');
            if ($ingressName === '' || !is_dns_label($ingressName)) {
                send_json(400, ['ok' => false, 'error' => 'Nom ingress invalide.']);
            }

            $cur = $k8s->getIngress($namespace, $ingressName);
            $ann = is_array($cur['metadata']['annotations'] ?? null) ? $cur['metadata']['annotations'] : [];
            if (($ann[managed_annotation_key()] ?? '') !== 'dashboard') {
                send_json(403, ['ok' => false, 'error' => 'Ingress non géré par le dashboard (lecture seule).']);
            }

            $k8s->deleteIngress($namespace, $ingressName);
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'ingressName' => $ingressName]);
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
