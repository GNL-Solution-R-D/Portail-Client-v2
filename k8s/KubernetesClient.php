<?php

/**
 * Minimal Kubernetes API client (in-cluster by default).
 *
 * - Uses the Pod's ServiceAccount token + CA.
 * - Does NOT expose any Kubernetes credentials to the browser.
 *
 * This version adds:
 * - GET JSON + GET text (logs)
 * - POST JSON / DELETE / PATCH
 * - Helpers for deployments, pods, services, ingresses
 */
class KubernetesClient
{
    private string $apiServer;
    private string $token;
    private string $caCertPath;
    private int $timeoutSeconds;

    /** Return env var only if set AND non-empty after trim. */
    private function getenvNonEmpty(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false) {
            return null;
        }
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    public function __construct(?string $apiServer = null, ?string $token = null, ?string $caCertPath = null, int $timeoutSeconds = 10)
    {
        // Prefer explicit API server, then env, then in-cluster service.
        // Also accept standard in-cluster env vars if present.
        $api = $apiServer
            ?? $this->getenvNonEmpty('K8S_API_SERVER')
            ?? (
                ($this->getenvNonEmpty('KUBERNETES_SERVICE_HOST') && $this->getenvNonEmpty('KUBERNETES_SERVICE_PORT'))
                    ? ('https://' . $this->getenvNonEmpty('KUBERNETES_SERVICE_HOST') . ':' . $this->getenvNonEmpty('KUBERNETES_SERVICE_PORT'))
                    : 'https://kubernetes.default.svc'
            );

        $this->apiServer = rtrim((string)$api, '/');
        $this->timeoutSeconds = $timeoutSeconds;

        $defaultTokenPath = '/var/run/secrets/kubernetes.io/serviceaccount/token';
        $defaultCaPath    = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';

        // IMPORTANT: ignore empty env vars (""), otherwise they shadow the in-cluster token.
        $this->token = $token
            ?? $this->getenvNonEmpty('K8S_BEARER_TOKEN')
            ?? (is_readable($defaultTokenPath) ? trim((string)@file_get_contents($defaultTokenPath)) : '');

        $this->caCertPath = $caCertPath
            ?? $this->getenvNonEmpty('K8S_CA_CERT')
            ?? (is_readable($defaultCaPath) ? $defaultCaPath : '');

        if ($this->token === '') {
            $openBaseDir = (string)ini_get('open_basedir');
            $hint = $openBaseDir !== '' ? (' open_basedir=' . $openBaseDir) : '';
            throw new RuntimeException(
                'Kubernetes token introuvable (env K8S_BEARER_TOKEN vide/non défini et ' . $defaultTokenPath . ' non lisible).' . $hint
            );
        }
        if ($this->caCertPath === '' || !is_readable($this->caCertPath)) {
            $openBaseDir = (string)ini_get('open_basedir');
            $hint = $openBaseDir !== '' ? (' open_basedir=' . $openBaseDir) : '';
            throw new RuntimeException(
                'CA Kubernetes introuvable (' . $defaultCaPath . ' non lisible et env K8S_CA_CERT vide/non défini).' . $hint
            );
        }
    }

    /** GET JSON. */
    public function get(string $path): array
    {
        return $this->requestJson('GET', $path);
    }

    /** GET raw text (for logs). */
    public function getText(string $path): string
    {
        [$status, $raw] = $this->requestRaw('GET', $path, null, [
            'Accept: text/plain, */*',
        ]);

        if ($status < 200 || $status >= 300) {
            $snippet = substr($raw, 0, 500);
            throw new RuntimeException('Kubernetes: réponse inattendue (HTTP ' . $status . '). ' . $snippet);
        }

        return $raw;
    }

    /** PATCH JSON (strategic merge by default). */
    public function patch(string $path, array $payload, string $contentType = 'application/strategic-merge-patch+json'): array
    {
        return $this->requestJson('PATCH', $path, $payload, [
            'Content-Type: ' . $contentType,
        ]);
    }

    /** POST JSON. */
    public function post(string $path, array $payload): array
    {
        return $this->requestJson('POST', $path, $payload, [
            'Content-Type: application/json',
        ]);
    }

    /** DELETE JSON (K8S returns a Status object). */
    public function delete(string $path): array
    {
        return $this->requestJson('DELETE', $path);
    }

    // ----------------- Deployments -----------------

    /** List deployments in a namespace. */
    public function listDeployments(string $namespace): array
    {
        $ns = rawurlencode($namespace);
        return $this->get("/apis/apps/v1/namespaces/{$ns}/deployments?limit=200");
    }

    public function getDeployment(string $namespace, string $deployment): array
    {
        $ns = rawurlencode($namespace);
        $dp = rawurlencode($deployment);
        return $this->get("/apis/apps/v1/namespaces/{$ns}/deployments/{$dp}");
    }

    /** "kubectl rollout restart" equivalent. */
    public function restartDeployment(string $namespace, string $deployment): array
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $payload = [
            'spec' => [
                'template' => [
                    'metadata' => [
                        'annotations' => [
                            'kubectl.kubernetes.io/restartedAt' => $now,
                        ],
                    ],
                ],
            ],
        ];
        $ns = rawurlencode($namespace);
        $dp = rawurlencode($deployment);
        return $this->patch("/apis/apps/v1/namespaces/{$ns}/deployments/{$dp}", $payload);
    }

    // ----------------- Pods / Logs -----------------

    public function listPods(string $namespace, ?string $labelSelector = null): array
    {
        $ns = rawurlencode($namespace);
        $q = 'limit=200';
        if (is_string($labelSelector) && $labelSelector !== '') {
            $q .= '&labelSelector=' . rawurlencode($labelSelector);
        }
        return $this->get("/api/v1/namespaces/{$ns}/pods?{$q}");
    }

    public function getPodLogsTail(string $namespace, string $pod, ?string $container = null, int $tailLines = 200, bool $timestamps = true): string
    {
        $ns = rawurlencode($namespace);
        $pd = rawurlencode($pod);

        $tailLines = max(10, min(5000, $tailLines));

        $params = [
            'tailLines' => (string)$tailLines,
            'timestamps' => $timestamps ? 'true' : 'false',
        ];
        if (is_string($container) && $container !== '') {
            $params['container'] = $container;
        }

        $q = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return $this->getText("/api/v1/namespaces/{$ns}/pods/{$pd}/log?{$q}");
    }

    // ----------------- Services -----------------

    public function listServices(string $namespace): array
    {
        $ns = rawurlencode($namespace);
        return $this->get("/api/v1/namespaces/{$ns}/services?limit=200");
    }

    // ----------------- Ingress -----------------

    public function listIngresses(string $namespace): array
    {
        $ns = rawurlencode($namespace);
        // networking.k8s.io/v1 is standard on modern clusters
        return $this->get("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses?limit=200");
    }

    public function getIngress(string $namespace, string $name): array
    {
        $ns = rawurlencode($namespace);
        $nm = rawurlencode($name);
        return $this->get("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses/{$nm}");
    }

    public function createIngress(string $namespace, array $payload): array
    {
        $ns = rawurlencode($namespace);
        return $this->post("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses", $payload);
    }

    public function deleteIngress(string $namespace, string $name): array
    {
        $ns = rawurlencode($namespace);
        $nm = rawurlencode($name);
        return $this->delete("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses/{$nm}");
    }

    // ----------------- Secrets -----------------

    public function getSecret(string $namespace, string $secret): array
    {
        $ns = rawurlencode($namespace);
        $sc = rawurlencode($secret);
        return $this->get("/api/v1/namespaces/{$ns}/secrets/{$sc}");
    }

    /**
     * Patch ONE key of a Secret (value will be base64-encoded).
     * NOTE: only use this if you deliberately granted secrets/patch in RBAC.
     */
    public function patchSecretDataKey(string $namespace, string $secret, string $key, string $valuePlain): array
    {
        $payload = [
            'data' => [
                $key => base64_encode($valuePlain),
            ],
        ];
        $ns = rawurlencode($namespace);
        $sc = rawurlencode($secret);
        return $this->patch("/api/v1/namespaces/{$ns}/secrets/{$sc}", $payload);
    }

    // ----------------- Low-level HTTP -----------------

    /**
     * Raw request returning [httpStatus, responseBody].
     * - Always sends Bearer token.
     * - Verifies the in-cluster CA.
     */
    private function requestRaw(string $method, string $path, ?string $payload = null, array $extraHeaders = []): array
    {
        $url = $this->apiServer . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossible d\'initialiser cURL.');
        }

        $headers = array_merge([
            'Authorization: Bearer ' . $this->token,
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_CAINFO         => $this->caCertPath,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Erreur cURL: ' . $err);
        }

        return [$status, (string)$raw];
    }

    private function requestJson(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Accept: application/json',
        ], $extraHeaders);

        $body = null;
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Payload JSON invalide.');
            }
            $body = $json;
        }

        [$status, $raw] = $this->requestRaw($method, $path, $body, $headers);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse Kubernetes non-JSON (HTTP ' . $status . ').');
        }

        if ($status < 200 || $status >= 300) {
            $msg = $decoded['message'] ?? ($decoded['status'] ?? 'Erreur Kubernetes');
            throw new RuntimeException('Kubernetes: ' . $msg . ' (HTTP ' . $status . ').');
        }

        return $decoded;
    }
}
