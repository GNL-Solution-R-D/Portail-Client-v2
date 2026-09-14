<?php

/**
 * data/PterodactylClient.php
 *
 * Client minimal de l'API CLIENT Pterodactyl (`/api/client/...`).
 *
 * Configuration (Secret Kubernetes / .env, lu via config()) :
 *   PTERO_API_URL   URL du panel, ex. https://panel.gnl-solution.fr
 *                   (avec ou sans « /api/client » : la classe normalise)
 *   PTERO_API_KEY   Clé de compte « ptlc_… ». Générée depuis un compte
 *                   administrateur racine, elle donne accès à tous les serveurs
 *                   du panel ; sinon, uniquement à ceux de son propriétaire.
 *
 * La clé ne quitte JAMAIS le serveur : le navigateur passe par
 * data/ptero_api.php, qui contrôle d'abord que le client possède bien le
 * produit demandé (include/services_catalog.php).
 *
 * Endpoints utilisés :
 *   GET  /servers/{id}             → identité, limites, allocations
 *   GET  /servers/{id}/resources   → état courant + CPU/RAM/disque/réseau
 *   POST /servers/{id}/power       → start | stop | restart | kill
 *   POST /servers/{id}/command     → commande console
 *   GET  /servers/{id}/websocket   → { token, socket } pour la console live
 */

declare(strict_types=1);

class PterodactylException extends RuntimeException
{
}

class PterodactylClient
{
    /** Identifiants acceptés : UUID complet, ou identifiant court (8 hexa). */
    public const ID_PATTERN = '/^[0-9a-f]{8}(-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})?$/i';

    /** Signaux d'alimentation acceptés par l'API. */
    public const POWER_SIGNALS = ['start', 'stop', 'restart', 'kill'];

    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, int $timeout = 10)
    {
        $baseUrl = trim((string)($baseUrl ?? self::configValue('PTERO_API_URL')));
        $apiKey  = trim((string)($apiKey ?? self::configValue('PTERO_API_KEY')));

        if ($baseUrl === '') {
            throw new PterodactylException('PTERO_API_URL non configurée.');
        }
        if ($apiKey === '') {
            throw new PterodactylException('PTERO_API_KEY non configurée.');
        }

        // On accepte « https://panel » comme « https://panel/api/client » et on
        // se ramène toujours à la racine de l'API client, sans slash final.
        $baseUrl = rtrim($baseUrl, '/');
        $baseUrl = (string)preg_replace('#/api/client$#', '', $baseUrl);
        $baseUrl = (string)preg_replace('#/api$#', '', $baseUrl);

        $this->baseUrl = $baseUrl . '/api/client';
        $this->apiKey  = $apiKey;
        $this->timeout = max(2, $timeout);
    }

    /** true si le panel est configuré (sans instancier le client). */
    public static function isConfigured(): bool
    {
        return trim((string)self::configValue('PTERO_API_URL')) !== ''
            && trim((string)self::configValue('PTERO_API_KEY')) !== '';
    }

    /**
     * Type de clé déduit du préfixe.
     *   ptlc_ → API CLIENT (celle qu'attend cette classe)
     *   ptla_ → API APPLICATION (mauvaise API : /api/client la refusera)
     */
    public static function keyType(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return 'absente';
        }
        if (str_starts_with($key, 'ptlc_')) {
            return 'client (ptlc_)';
        }
        if (str_starts_with($key, 'ptla_')) {
            return 'application (ptla_) — mauvaise API';
        }

        return 'préfixe inconnu';
    }

    /**
     * Ce que le client utilise réellement, sans jamais exposer la clé.
     * Sert au diagnostic affiché dans la console de la page de service.
     *
     * @return array{base_url:string, key_type:string, key_length:int}
     */
    public function describe(): array
    {
        return [
            'base_url'   => $this->baseUrl,
            'key_type'   => self::keyType($this->apiKey),
            'key_length' => strlen($this->apiKey),
        ];
    }

    /**
     * Appel brut qui ne lève JAMAIS d'exception : renvoie le code HTTP et le
     * début du corps tels quels. Utilisé uniquement par l'action « diag ».
     *
     * @return array{status:int, body:string, error:string}
     */
    public function probe(string $path): array
    {
        try {
            $raw = $this->rawRequest('GET', $path, null, $status);

            return ['status' => $status, 'body' => mb_substr(trim((string)preg_replace('/\s+/', ' ', $raw)), 0, 300), 'error' => ''];
        } catch (Throwable $e) {
            return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        }
    }

    /** Valide la forme d'un identifiant de serveur avant tout appel réseau. */
    public static function isValidServerId(string $id): bool
    {
        return (bool)preg_match(self::ID_PATTERN, trim($id));
    }

    private static function configValue(string $key): string
    {
        if (function_exists('config')) {
            $v = config($key, '');
            if (is_string($v) || is_numeric($v)) {
                return (string)$v;
            }
        }
        $v = getenv($key);

        return $v === false ? '' : (string)$v;
    }

    // ── Endpoints ────────────────────────────────────────────────────────────

    /** Fiche du serveur : nom, limites, allocations, état d'installation. */
    public function getServer(string $id): array
    {
        $json = $this->request('GET', '/servers/' . rawurlencode($id));

        return is_array($json['attributes'] ?? null) ? $json['attributes'] : [];
    }

    /** État courant + consommation (mémoire, CPU, disque, réseau, uptime). */
    public function getResources(string $id): array
    {
        $json = $this->request('GET', '/servers/' . rawurlencode($id) . '/resources');

        return is_array($json['attributes'] ?? null) ? $json['attributes'] : [];
    }

    /** start | stop | restart | kill. Réponse 204 sans corps. */
    public function sendPower(string $id, string $signal): void
    {
        $signal = strtolower(trim($signal));
        if (!in_array($signal, self::POWER_SIGNALS, true)) {
            throw new PterodactylException('Signal invalide : ' . $signal);
        }

        $this->request('POST', '/servers/' . rawurlencode($id) . '/power', ['signal' => $signal]);
    }

    /** Envoie une commande à la console. Échoue si le serveur est arrêté (502). */
    public function sendCommand(string $id, string $command): void
    {
        $command = trim($command);
        if ($command === '') {
            throw new PterodactylException('Commande vide.');
        }

        $this->request('POST', '/servers/' . rawurlencode($id) . '/command', ['command' => $command]);
    }

    /**
     * Jeton de console live.
     *
     * @return array{token:string, socket:string}
     */
    public function getWebsocket(string $id): array
    {
        $json = $this->request('GET', '/servers/' . rawurlencode($id) . '/websocket');
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        return [
            'token'  => (string)($data['token'] ?? ''),
            'socket' => (string)($data['socket'] ?? ''),
        ];
    }

    // ── Transport ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $raw  = $this->rawRequest($method, $path, $body, $status);
        $json = json_decode($raw, true);

        if ($status === 204 || $raw === '') {
            return [];
        }

        if ($status < 200 || $status >= 300) {
            throw new PterodactylException($this->errorMessage($status, $json, $raw));
        }

        // Un 200 qui n'est pas du JSON n'est PAS un succès : c'est typiquement
        // une page HTML servie par un reverse-proxy ou une URL qui ne pointe pas
        // sur le panel. Sans ce garde-fou, la page afficherait un serveur vide
        // en prétendant que tout va bien.
        if (!is_array($json)) {
            throw new PterodactylException($this->errorMessage($status, $json, $raw));
        }

        return $json;
    }

    /**
     * Transport nu : renvoie le corps et remplit $status. Ne lève que sur une
     * erreur de transport (DNS, TLS, timeout), jamais sur un code HTTP.
     */
    private function rawRequest(string $method, string $path, ?array $body, ?int &$status): string
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $payload = $body === null
            ? null
            : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!function_exists('curl_init')) {
            throw new PterodactylException('cURL indisponible sur ce serveur PHP.');
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new PterodactylException('Panel injoignable : ' . $err);
        }

        return (string)$raw;
    }

    /** Message lisible à partir d'une erreur Pterodactyl (format JSON:API). */
    private function errorMessage(int $status, $json, string $raw): string
    {
        $compact = trim((string)preg_replace('/\s+/', ' ', $raw));

        // Détail renvoyé par le panel (« Unauthenticated. », « This action is
        // unauthorized. », …) : c'est la meilleure explication disponible.
        $detail = '';
        if (is_array($json) && is_array($json['errors'] ?? null)) {
            $parts = [];
            foreach ($json['errors'] as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $one = trim((string)($error['detail'] ?? $error['code'] ?? ''));
                if ($one !== '') {
                    $parts[] = $one;
                }
            }
            $detail = implode(' ; ', $parts);
        }

        if ($status === 401 || $status === 403) {
            // Cause la plus fréquente : une clé d'API Application (ptla_) là où
            // /api/client attend une clé de compte (ptlc_). Le panel se contente
            // de dire « non », donc c'est à nous de nommer le soupçon.
            $hint = str_starts_with($this->apiKey, 'ptlc_')
                ? ' — la clé est bien de type client ; vérifiez que son compte a accès à CE serveur (admin racine, ou sous-utilisateur).'
                : ' — clé ' . self::keyType($this->apiKey) . ', or /api/client exige une clé de compte « ptlc_ ».';

            return 'Panel Pterodactyl (HTTP ' . $status . ') : '
                 . ($detail !== '' ? $detail : 'clé API refusée ou sans accès à ce serveur.') . $hint;
        }

        if ($status === 404) {
            return 'Panel Pterodactyl (HTTP 404) : serveur introuvable — vérifiez PTERO_API_URL et le Server ID'
                 . ($detail !== '' ? ' (' . $detail . ')' : '') . '.';
        }

        if ($detail !== '') {
            return 'Panel Pterodactyl (HTTP ' . $status . ') : ' . $detail;
        }

        if ($compact !== '' && (stripos($compact, '<html') !== false || stripos($compact, '<!doctype') !== false)) {
            return 'Panel Pterodactyl (HTTP ' . $status . ') : réponse HTML au lieu de JSON — '
                 . 'PTERO_API_URL ne pointe probablement pas sur le panel, ou un portail d\'authentification s\'interpose.';
        }

        if (!is_array($json)) {
            return 'Panel Pterodactyl (HTTP ' . $status . ') : réponse illisible (JSON attendu) — '
                 . mb_substr($compact, 0, 160);
        }

        return 'Panel Pterodactyl (HTTP ' . $status . ') : ' . mb_substr($compact, 0, 200);
    }
}
