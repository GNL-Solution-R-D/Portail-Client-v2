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

    public function __construct(?string $apiServer = null, ?string $token = null, ?string $caCertPath = null, int $timeoutSeconds = 10)
    {
        $this->apiServer = rtrim($apiServer ?? getenv('K8S_API_SERVER') ?: 'https://kubernetes.default.svc', '/');
        $this->timeoutSeconds = $timeoutSeconds;

        $defaultTokenPath = '/var/run/secrets/kubernetes.io/serviceaccount/token';
        $defaultCaPath    = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';

        $this->token = $token
            ?? getenv('K8S_BEARER_TOKEN')
            ?? (is_readable($defaultTokenPath) ? trim((string)file_get_contents($defaultTokenPath)) : '');

        $this->caCertPath = $caCertPath
            ?? getenv('K8S_CA_CERT')
            ?? (is_readable($defaultCaPath) ? $defaultCaPath : '');

        if ($this->token === '') {
            throw new RuntimeException('Kubernetes token introuvable (ServiceAccount token ou env K8S_BEARER_TOKEN).');
        }
        if ($this->caCertPath === '' || !is_readable($this->caCertPath)) {
            throw new RuntimeException('CA Kubernetes introuvable (ca.crt ou env K8S_CA_CERT).');
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
}
