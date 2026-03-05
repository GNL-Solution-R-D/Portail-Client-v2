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

/** Return env var only if set AND non-empty after trim. */
function getenv_non_empty(string $name): ?string {
    $v = getenv($name);
    if ($v === false) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

/**
 * Parse an image reference into repo+tag+registry info.
 * Examples:
 * - php:8.1-apache -> repo=php tag=8.1-apache registry=docker.io path=library/php
 * - docker.io/library/php:8.1-apache -> repo=docker.io/library/php tag=8.1-apache registry=docker.io path=library/php
 * - registry.example.com/ns/app:1.2.3 -> registry=registry.example.com path=ns/app
 */
function parse_image_ref(string $image): array {
    $noDigest = explode('@', $image, 2)[0];

    $repo = $noDigest;
    $tag  = null;

    $lastColon = strrpos($noDigest, ':');
    $lastSlash = strrpos($noDigest, '/');
    if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
        $repo = substr($noDigest, 0, $lastColon);
        $tag  = substr($noDigest, $lastColon + 1);
    }

    $registry = 'docker.io';
    $path = $repo;

    $first = explode('/', $repo, 2)[0];
    if (strpos($first, '.') !== false || strpos($first, ':') !== false || $first === 'localhost') {
        $registry = $first;
        $path = explode('/', $repo, 2)[1] ?? '';
    }

    if ($registry === 'docker.io') {
        // normalize docker hub paths for official images
        if (strpos($path, '/') === false) {
            $path = 'library/' . $path;
        }
    }

    return [
        'image' => $image,
        'repo' => $repo,
        'tag' => $tag,
        'registry' => $registry,
        'path' => $path,
    ];
}

/** Split a tag into leading version and suffix. */
function split_tag_version(string $tag): array {
    if (preg_match('/^(\d+(?:\.\d+){0,2})(.*)$/', $tag, $m)) {
        return ['version' => $m[1], 'suffix' => $m[2]];
    }
    return ['version' => null, 'suffix' => $tag];
}

/** Turn a version string "8.3.1" into a comparable tuple. */
function version_tuple(string $v): array {
    $parts = explode('.', $v);
    $t = [0, 0, 0];
    for ($i = 0; $i < 3; $i++) {
        if (isset($parts[$i]) && ctype_digit($parts[$i])) $t[$i] = (int)$parts[$i];
    }
    return $t;
}

/**
 * List tags from Docker Hub (public) for a repo path like "library/php" or "myuser/myapp".
 * Caches briefly in session to avoid hammering Docker Hub.
 */
function dockerhub_list_tags(string $repoPath, int $maxPages = 6, int $pageSize = 100): array {
    $repoPath = trim($repoPath, '/');

    // tiny session cache (5 min)
    $cacheKey = 'dh:' . $repoPath;
    if (isset($_SESSION['k8s_tag_cache'][$cacheKey]) && is_array($_SESSION['k8s_tag_cache'][$cacheKey])) {
        $c = $_SESSION['k8s_tag_cache'][$cacheKey];
        if (isset($c['at'], $c['tags']) && is_int($c['at']) && (time() - $c['at'] < 300) && is_array($c['tags'])) {
            return $c['tags'];
        }
    }

    $tags = [];
    $url = 'https://hub.docker.com/v2/repositories/' . rawurlencode(str_replace('/', '%2F', $repoPath));
    // DockerHub endpoint does NOT like double-encoding; build properly:
    $url = 'https://hub.docker.com/v2/repositories/' . $repoPath . '/tags?page_size=' . $pageSize;

    $pages = 0;
    while ($url && $pages < $maxPages) {
        $pages++;

        $ch = curl_init($url);
        if ($ch === false) break;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: espace-client-k8s-dashboard/1.0',
            ],
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('DockerHub: erreur de récupération des tags (HTTP ' . $status . '): ' . ($err ?: ''));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('DockerHub: réponse non-JSON.');
        }

        $results = $decoded['results'] ?? [];
        if (is_array($results)) {
            foreach ($results as $r) {
                $name = $r['name'] ?? null;
                if (is_string($name) && $name !== '') $tags[] = $name;
            }
        }

        $url = is_string($decoded['next'] ?? null) ? $decoded['next'] : '';
    }

    $tags = array_values(array_unique($tags));
    $_SESSION['k8s_tag_cache'][$cacheKey] = ['at' => time(), 'tags' => $tags];
    return $tags;
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

        case 'list_deployment_images': {
            $deployment = (string)($_GET['name'] ?? '');
            if ($deployment === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }

            $d = $k8s->getDeployment($namespace, $deployment);
            $containers = $d['spec']['template']['spec']['containers'] ?? [];
            if (!is_array($containers)) $containers = [];

            $out = [];
            foreach ($containers as $c) {
                if (!is_array($c)) continue;
                $cName = $c['name'] ?? '';
                $img   = $c['image'] ?? '';
                if (!is_string($cName) || $cName === '' || !is_string($img) || $img === '') continue;

                $ref = parse_image_ref($img);

                $currentTag = is_string($ref['tag']) ? $ref['tag'] : null;
                $availableTags = [];
                $latestTag = null;
                $hasUpdate = false;
                $note = null;

                // Default: try Docker Hub public tags for docker.io images only (for now).
                if ($currentTag === null) {
                    $note = 'Tag absent (image sans ":tag").';
                } elseif ($ref['registry'] !== 'docker.io') {
                    $note = 'Registry "' . $ref['registry'] . '" non supporté pour l’auto-alimentation (pour l’instant).';
                } else {
                    $tags = dockerhub_list_tags((string)$ref['path']);

                    $split = split_tag_version($currentTag);
                    $wantSuffix = (string)($split['suffix'] ?? '');

                    if ($split['version'] === null) {
                        // current tag isn't a version (e.g., "latest"): just show a small alphabetical list.
                        sort($tags, SORT_STRING);
                        $availableTags = array_slice($tags, 0, 50);
                    } else {
                        $cands = [];
                        foreach ($tags as $t) {
                            if (!is_string($t) || $t === '') continue;
                            $s = split_tag_version($t);
                            if ($s['version'] === null) continue;
                            if ((string)$s['suffix'] !== $wantSuffix) continue;

                            $cands[] = [
                                'tag' => $t,
                                'tuple' => version_tuple((string)$s['version']),
                            ];
                        }

                        usort($cands, function($a, $b){
                            $ta = $a['tuple']; $tb = $b['tuple'];
                            for ($i = 0; $i < 3; $i++) {
                                if ($ta[$i] === $tb[$i]) continue;
                                return ($ta[$i] < $tb[$i]) ? 1 : -1; // desc
                            }
                            return strcmp((string)$a['tag'], (string)$b['tag']);
                        });

                        $availableTags = array_values(array_map(fn($x) => $x['tag'], $cands));
                        $availableTags = array_slice($availableTags, 0, 60);
                        $latestTag = $availableTags[0] ?? null;
                        $hasUpdate = is_string($latestTag) && $latestTag !== $currentTag;
                    }
                }

                $out[] = [
                    'name' => $cName,
                    'currentImage' => $img,
                    'repo' => $ref['repo'],
                    'registry' => $ref['registry'],
                    'path' => $ref['path'],
                    'currentTag' => $currentTag,
                    'availableTags' => $availableTags,
                    'latestTag' => $latestTag,
                    'hasUpdate' => $hasUpdate,
                    'note' => $note,
                ];
            }

            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployment' => $deployment, 'containers' => $out]);
        }

        case 'set_deployment_image_tag': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
            }

            $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if (isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && $_SESSION['csrf'] !== '' && !hash_equals($_SESSION['csrf'], $csrf)) {
                send_json(403, ['ok' => false, 'error' => 'CSRF invalid']);
            }

            $deployment = (string)($_POST['name'] ?? '');
            $container  = (string)($_POST['container'] ?? '');
            $newTag     = (string)($_POST['tag'] ?? '');

            if ($deployment === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }
            if ($container === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $container)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de container invalide.']);
            }
            // Allow typical tag chars (avoid spaces and weird stuff)
            if ($newTag === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $newTag)) {
                send_json(400, ['ok' => false, 'error' => 'Tag invalide.']);
            }

            // Fetch current deployment to validate repo + container existence
            $d = $k8s->getDeployment($namespace, $deployment);
            $containers = $d['spec']['template']['spec']['containers'] ?? [];
            if (!is_array($containers)) $containers = [];

            $currentImage = null;
            foreach ($containers as $c) {
                if (!is_array($c)) continue;
                if (($c['name'] ?? '') === $container && is_string($c['image'] ?? null)) {
                    $currentImage = (string)$c['image'];
                    break;
                }
            }
            if ($currentImage === null) {
                send_json(404, ['ok' => false, 'error' => 'Container introuvable dans ce deployment.']);
            }

            $ref = parse_image_ref($currentImage);
            $currentTag = is_string($ref['tag']) ? $ref['tag'] : null;
            if ($currentTag === null) {
                send_json(400, ['ok' => false, 'error' => 'Image actuelle sans tag, impossible de changer juste la version.']);
            }

            // Safety: only allow switching tags within same image repo.
            $repo = (string)$ref['repo'];

            // If docker.io + version-like tag, restrict to same suffix as current tag
            if ($ref['registry'] === 'docker.io') {
                $tags = dockerhub_list_tags((string)$ref['path']);

                $split = split_tag_version($currentTag);
                $wantSuffix = (string)($split['suffix'] ?? '');

                if ($split['version'] !== null) {
                    $allowed = [];
                    foreach ($tags as $t) {
                        $s = split_tag_version((string)$t);
                        if ($s['version'] === null) continue;
                        if ((string)$s['suffix'] !== $wantSuffix) continue;
                        $allowed[$t] = true;
                    }
                    if (!isset($allowed[$newTag])) {
                        send_json(400, ['ok' => false, 'error' => 'Tag non autorisé pour ce container (suffixe différent ou tag inconnu).']);
                    }
                } else {
                    // current tag isn't a version: allow only tags that exist
                    $allowed = array_flip($tags);
                    if (!isset($allowed[$newTag])) {
                        send_json(400, ['ok' => false, 'error' => 'Tag inconnu sur Docker Hub.']);
                    }
                }
            }

            $newImage = $repo . ':' . $newTag;

            $payload = [
                'spec' => [
                    'template' => [
                        'spec' => [
                            'containers' => [
                                [
                                    'name' => $container,
                                    'image' => $newImage,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $ns = rawurlencode($namespace);
            $dp = rawurlencode($deployment);
            $k8s->patch("/apis/apps/v1/namespaces/{$ns}/deployments/{$dp}", $payload);

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'deployment' => $deployment,
                'container' => $container,
                'oldImage' => $currentImage,
                'newImage' => $newImage,
            ]);
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
