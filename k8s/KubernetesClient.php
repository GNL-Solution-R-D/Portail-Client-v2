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

    /** PATCH JSON (strategic merge by default). */
    public function patch(string $path, array $payload, string $contentType = 'application/strategic-merge-patch+json'): array
    {
        return $this->requestJson('PATCH', $path, $payload, [
            'Content-Type: ' . $contentType,
        ]);
    }

    /** List pods in a namespace (optionally filtered by labelSelector). */
    public function listPods(string $namespace, ?string $labelSelector = null, int $limit = 200): array
    {
        $ns = rawurlencode($namespace);
        $query = [
            'limit' => max(1, min(500, $limit)),
        ];
        if (is_string($labelSelector) && trim($labelSelector) !== '') {
            $query['labelSelector'] = $labelSelector;
        }
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $this->get("/api/v1/namespaces/{$ns}/pods?{$qs}");
    }

    /** Get pod logs as plain text. */
    public function getPodLogs(string $namespace, string $pod, array $opts = []): string
    {
        $ns = rawurlencode($namespace);
        $pd = rawurlencode($pod);

        $query = [];
        if (isset($opts['container']) && is_string($opts['container']) && $opts['container'] !== '') {
            $query['container'] = $opts['container'];
        }
        if (isset($opts['tailLines'])) {
            $tail = (int)$opts['tailLines'];
            $query['tailLines'] = max(1, min(5000, $tail));
        }
        if (isset($opts['sinceSeconds'])) {
            $since = (int)$opts['sinceSeconds'];
            if ($since > 0) $query['sinceSeconds'] = $since;
        }
        if (isset($opts['timestamps'])) {
            $query['timestamps'] = (bool)$opts['timestamps'] ? 'true' : 'false';
        }
        if (isset($opts['previous'])) {
            $query['previous'] = (bool)$opts['previous'] ? 'true' : 'false';
        }
        if (isset($opts['limitBytes'])) {
            $lb = (int)$opts['limitBytes'];
            if ($lb > 0) $query['limitBytes'] = $lb;
        }

        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $path = "/api/v1/namespaces/{$ns}/pods/{$pd}/log" . ($qs !== '' ? "?{$qs}" : '');
        return $this->requestText('GET', $path, null, [
            'Accept: text/plain, */*;q=0.8',
        ]);
    }

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
        $url = $this->apiServer . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossible d\'initialiser cURL.');
        }

        $headers = array_merge([
            'Accept: application/json',
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

    /** Same as requestJson, but returns raw text. Useful for /log endpoints. */
    private function requestText(string $method, string $path, ?string $payload = null, array $extraHeaders = []): string
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

        if ($status < 200 || $status >= 300) {
            // Kubernetes errors are usually JSON; try to extract message.
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $msg = $decoded['message'] ?? ($decoded['status'] ?? 'Erreur Kubernetes');
                throw new RuntimeException('Kubernetes: ' . $msg . ' (HTTP ' . $status . ').');
            }
            throw new RuntimeException('Kubernetes: HTTP ' . $status . ' (HTTP ' . $status . ').');
        }

        return (string)$raw;
    }
}
