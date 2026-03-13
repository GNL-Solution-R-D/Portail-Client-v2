<?php

/**
 * Minimal Kubernetes API client (in-cluster by default).
 *
 * - Uses the Pod's ServiceAccount token + CA.
 * - Does NOT expose any Kubernetes credentials to the browser.
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
        $this->apiServer = rtrim($api, '/');
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

    /** POST JSON. */
    public function post(string $path, array $payload, array $extraHeaders = []): array
    {
        return $this->requestJson('POST', $path, $payload, array_merge([
            'Content-Type: application/json',
        ], $extraHeaders));
    }

    /** DELETE (JSON response). */
    public function delete(string $path): array
    {
        return $this->requestJson('DELETE', $path);
    }

    /** PATCH JSON (strategic merge by default). */
    public function patch(string $path, array $payload, string $contentType = 'application/strategic-merge-patch+json'): array
    {
        return $this->requestJson('PATCH', $path, $payload, [
            'Content-Type: ' . $contentType,
        ]);
    }

    /** List deployments in a namespace. */
    public function listDeployments(string $namespace): array
    {
        $ns = rawurlencode($namespace);
        return $this->get("/apis/apps/v1/namespaces/{$ns}/deployments?limit=200");
    }

    /** List services in a namespace. */
    public function listServices(string $namespace): array
    {
        $ns = rawurlencode($namespace);
        return $this->get("/api/v1/namespaces/{$ns}/services?limit=500");
    }

    /** List ingresses in a namespace (networking.k8s.io/v1). */
    public function listIngresses(string $namespace): array
    {
        $ns = rawurlencode($namespace);
        return $this->get("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses?limit=500");
    }

    public function createIngress(string $namespace, array $ingress): array
    {
        $ns = rawurlencode($namespace);
        return $this->post("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses", $ingress);
    }

    public function patchIngress(string $namespace, string $name, array $payload, string $contentType = 'application/merge-patch+json'): array
    {
        $ns = rawurlencode($namespace);
        $nm = rawurlencode($name);
        return $this->patch("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses/{$nm}", $payload, $contentType);
    }

    public function deleteIngress(string $namespace, string $name): array
    {
        $ns = rawurlencode($namespace);
        $nm = rawurlencode($name);
        return $this->delete("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses/{$nm}");
    }

    public function getDeployment(string $namespace, string $deployment): array
    {
        $ns = rawurlencode($namespace);
        $dp = rawurlencode($deployment);
        return $this->get("/apis/apps/v1/namespaces/{$ns}/deployments/{$dp}");
    }

    /** List pods in a namespace, optional label selector. */
    public function listPods(string $namespace, ?string $labelSelector = null): array
    {
        $ns = rawurlencode($namespace);
        $query = 'limit=500';
        if (is_string($labelSelector) && trim($labelSelector) !== '') {
            $query .= '&labelSelector=' . rawurlencode(trim($labelSelector));
        }
        return $this->get("/api/v1/namespaces/{$ns}/pods?{$query}");
    }

    /** Read logs from a pod. */
    public function getPodLogs(string $namespace, string $pod, ?string $container = null, int $tail = 200, bool $timestamps = true): string
    {
        $ns = rawurlencode($namespace);
        $pd = rawurlencode($pod);

        $tail = max(1, min($tail, 5000));
        $params = [
            'tailLines' => (string)$tail,
            'timestamps' => $timestamps ? 'true' : 'false',
        ];

        if (is_string($container) && trim($container) !== '') {
            $params['container'] = trim($container);
        }

        $path = "/api/v1/namespaces/{$ns}/pods/{$pd}/log?" . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return $this->requestText('GET', $path);
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

    private function requestJson(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $raw = $this->requestRaw($method, $path, $payload, array_merge([
            'Accept: application/json',
        ], $extraHeaders));

        $decoded = json_decode($raw['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse Kubernetes non-JSON (HTTP ' . $raw['status'] . ').');
        }

        if ($raw['status'] < 200 || $raw['status'] >= 300) {
            $msg = $decoded['message'] ?? ($decoded['status'] ?? 'Erreur Kubernetes');
            throw new RuntimeException('Kubernetes: ' . $msg . ' (HTTP ' . $raw['status'] . ').');
        }

        return $decoded;
    }

    private function requestText(string $method, string $path, ?array $payload = null, array $extraHeaders = []): string
    {
        $raw = $this->requestRaw($method, $path, $payload, array_merge([
            'Accept: text/plain, */*',
        ], $extraHeaders));

        if ($raw['status'] < 200 || $raw['status'] >= 300) {
            throw new RuntimeException('Kubernetes: récupération des logs impossible (HTTP ' . $raw['status'] . ').');
        }

        return $raw['body'];
    }

    private function requestRaw(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
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
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Payload JSON invalide.');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Erreur cURL: ' . $err);
        }

        return [
            'status' => $status,
            'body' => $raw,
        ];
    }
}
