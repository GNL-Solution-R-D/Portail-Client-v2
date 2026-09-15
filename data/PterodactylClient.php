<?php

/**
 * data/PterodactylClient.php
 *
 * Client minimal de l'API CLIENT Pterodactyl (`/api/client/...`).
 *
 * Configuration (Secret Kubernetes / .env, lu via config()) :
 *
 *   PTERO_API_URL          URL du panel, ex. https://panel.gnl-solution.fr
 *                          (avec ou sans « /api/client » : la classe normalise)
 *
 *   PTERO_CLIENT_API_KEY   Clé de COMPTE « ptlc_… », à créer dans le panel sous
 *                          « Compte → Clés API ». C'est la seule qu'accepte
 *                          /api/client. Depuis un compte administrateur racine,
 *                          elle atteint tous les serveurs du panel ; sinon,
 *                          uniquement ceux de son propriétaire.
 *   PTERO_API_KEY          Repli, pour les instances qui n'ont qu'une variable.
 *
 *   ⚠️ Une clé d'API APPLICATION (« ptla_… », créée sous « Admin → Application
 *   API ») sert à provisionner des serveurs, PAS à les piloter : /api/client la
 *   refuse avec « You are attempting to use an application API key on an
 *   endpoint that requires a client API key ». Si PTERO_API_KEY porte déjà une
 *   clé d'application utilisée ailleurs (n8n…), laissez-la et ajoutez
 *   PTERO_CLIENT_API_KEY à côté : elle est prioritaire.
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
 *
 *   Fichiers (explorateur) :
 *   GET  /servers/{id}/files/list?directory=…      → contenu d'un dossier
 *   GET  /servers/{id}/files/contents?file=…       → contenu BRUT (text/plain)
 *   GET  /servers/{id}/files/download?file=…       → URL signée à usage unique
 *   GET  /servers/{id}/files/upload                → URL signée de téléversement
 *   POST /servers/{id}/files/write?file=…          → écrit le corps de la requête
 *   PUT  /servers/{id}/files/rename                → { root, files:[{from,to}] }
 *   POST /servers/{id}/files/create-folder         → { root, name }
 *   POST /servers/{id}/files/delete                → { root, files:[…] }
 *
 * Les deux URL signées sont renvoyées TELLES QUELLES au navigateur : elles sont
 * temporaires, limitées à un fichier (ou à un dossier pour l'envoi) et ne
 * portent pas la clé du panel. Cela évite de faire transiter des fichiers
 * entiers par PHP.
 */

declare(strict_types=1);

class PterodactylException extends RuntimeException
{
}

/**
 * Throttle du panel (HTTP 429).
 *
 * ⚠️ Le quota Pterodactyl est compté PAR COMPTE. Le portail n'utilise qu'une
 * seule clé, donc TOUS les clients partagent le même seau : il faut être avare
 * en appels et reculer franchement quand le panel dit stop.
 */
class PterodactylRateLimitException extends PterodactylException
{
    public int $retryAfter;

    public function __construct(string $message, int $retryAfter = 0)
    {
        parent::__construct($message);
        $this->retryAfter = max(0, $retryAfter);
    }
}

class PterodactylClient
{
    /** Identifiants acceptés : UUID complet, ou identifiant court (8 hexa). */
    public const ID_PATTERN = '/^[0-9a-f]{8}(-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})?$/i';

    /** Signaux d'alimentation acceptés par l'API. */
    public const POWER_SIGNALS = ['start', 'stop', 'restart', 'kill'];

    /** Variables de clé, par ordre de priorité. */
    public const KEY_VARS = ['PTERO_CLIENT_API_KEY', 'PTERO_API_KEY'];

    private string $baseUrl;
    private string $apiKey;
    private string $keySource;
    private int $timeout;
    private int $lastRetryAfter = 0;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, int $timeout = 10)
    {
        $baseUrl = trim((string)($baseUrl ?? self::configValue('PTERO_API_URL')));

        $keySource = 'paramètre';
        if ($apiKey === null) {
            [$apiKey, $keySource] = self::resolveKey();
        }
        $apiKey = trim((string)$apiKey);

        if ($baseUrl === '') {
            throw new PterodactylException('PTERO_API_URL non configurée.');
        }
        if ($apiKey === '') {
            throw new PterodactylException('Aucune clé Pterodactyl configurée ('
                . implode(' ou ', self::KEY_VARS) . ').');
        }

        $this->keySource = $keySource;

        // On accepte « https://panel » comme « https://panel/api/client » et on
        // se ramène toujours à la racine de l'API client, sans slash final.
        $baseUrl = rtrim($baseUrl, '/');
        $baseUrl = (string)preg_replace('#/api/client$#', '', $baseUrl);
        $baseUrl = (string)preg_replace('#/api$#', '', $baseUrl);

        $this->baseUrl = $baseUrl . '/api/client';
        $this->apiKey  = $apiKey;
        $this->timeout = max(2, $timeout);
    }

    /**
     * Clé effective et nom de la variable d'où elle vient.
     * PTERO_CLIENT_API_KEY prime : elle permet d'ajouter la clé « ptlc_ » sans
     * toucher à un PTERO_API_KEY déjà utilisé ailleurs (provisioning n8n).
     *
     * @return array{0:string, 1:string}
     */
    public static function resolveKey(): array
    {
        foreach (self::KEY_VARS as $var) {
            $v = trim((string)self::configValue($var));
            if ($v !== '') {
                return [$v, $var];
            }
        }

        return ['', ''];
    }

    /** true si le panel est configuré (sans instancier le client). */
    public static function isConfigured(): bool
    {
        return trim((string)self::configValue('PTERO_API_URL')) !== ''
            && self::resolveKey()[0] !== '';
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
     * @return array{base_url:string, key_type:string, key_length:int, key_source:string}
     */
    public function describe(): array
    {
        return [
            'base_url'   => $this->baseUrl,
            'key_type'   => self::keyType($this->apiKey),
            'key_length' => strlen($this->apiKey),
            'key_source' => $this->keySource,
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

    // ── Fichiers ─────────────────────────────────────────────────────────────

    /**
     * Contenu d'un dossier, à plat (le panel ne descend pas récursivement).
     *
     * Chaque entrée : name, mode, mode_bits, size, is_file, is_symlink,
     * mimetype, created_at, modified_at.
     *
     * @return list<array<string,mixed>>
     */
    public function listFiles(string $id, string $directory = '/'): array
    {
        $json = $this->request(
            'GET',
            '/servers/' . rawurlencode($id) . '/files/list?directory=' . rawurlencode($directory)
        );

        $out = [];
        foreach (is_array($json['data'] ?? null) ? $json['data'] : [] as $row) {
            if (!is_array($row) || !is_array($row['attributes'] ?? null)) {
                continue;
            }
            $out[] = $row['attributes'];
        }

        return $out;
    }

    /**
     * Contenu brut d'un fichier.
     *
     * ⚠️ Cet endpoint répond en text/plain, pas en JSON : passer par request()
     * le ferait échouer sur le garde-fou « un 200 non-JSON n'est pas un succès ».
     */
    public function getFileContents(string $id, string $file): string
    {
        return $this->requestPlain(
            'GET',
            '/servers/' . rawurlencode($id) . '/files/contents?file=' . rawurlencode($file)
        );
    }

    /** URL signée, à usage unique, pour télécharger un fichier. */
    public function getDownloadUrl(string $id, string $file): string
    {
        $json = $this->request(
            'GET',
            '/servers/' . rawurlencode($id) . '/files/download?file=' . rawurlencode($file)
        );

        return (string)($json['attributes']['url'] ?? '');
    }

    /**
     * URL signée de téléversement. Le navigateur y POSTe un multipart dont le
     * champ s'appelle « files », en ajoutant « &directory=<dossier> ».
     */
    public function getUploadUrl(string $id): string
    {
        $json = $this->request('GET', '/servers/' . rawurlencode($id) . '/files/upload');

        return (string)($json['attributes']['url'] ?? '');
    }

    /** Écrit (ou crée) un fichier. Le corps de la requête EST le contenu. */
    public function writeFile(string $id, string $file, string $content): void
    {
        $this->requestPlain(
            'POST',
            '/servers/' . rawurlencode($id) . '/files/write?file=' . rawurlencode($file),
            $content
        );
    }

    /**
     * Renomme — ou déplace, le panel ne distingue pas les deux.
     *
     * @param list<array{from:string,to:string}> $pairs chemins relatifs à $root
     */
    public function renameFiles(string $id, string $root, array $pairs): void
    {
        $this->request('PUT', '/servers/' . rawurlencode($id) . '/files/rename', [
            'root'  => $root,
            'files' => array_values($pairs),
        ]);
    }

    public function createFolder(string $id, string $root, string $name): void
    {
        $this->request('POST', '/servers/' . rawurlencode($id) . '/files/create-folder', [
            'root' => $root,
            'name' => $name,
        ]);
    }

    /**
     * Supprime fichiers et dossiers (récursif côté panel).
     *
     * @param list<string> $files noms relatifs à $root
     */
    public function deleteFiles(string $id, string $root, array $files): void
    {
        $this->request('POST', '/servers/' . rawurlencode($id) . '/files/delete', [
            'root'  => $root,
            'files' => array_values($files),
        ]);
    }

    // ── Transport ────────────────────────────────────────────────────────────

    /**
     * Variante texte : pour les deux endpoints de fichiers qui ne parlent pas
     * JSON — « contents » répond en text/plain, « write » attend le fichier brut
     * et répond 204 sans corps. Le contrôle d'erreur reste le même ; seul le
     * garde-fou « un 200 non-JSON n'est pas un succès » ne s'applique pas, parce
     * qu'ici du texte EST la réponse attendue.
     */
    private function requestPlain(string $method, string $path, ?string $body = null): string
    {
        $raw = $this->rawRequest($method, $path, null, $status, $body, 'text/plain');

        if ($status === 429) {
            throw new PterodactylRateLimitException(
                $this->errorMessage($status, json_decode($raw, true), $raw),
                $this->lastRetryAfter > 0 ? $this->lastRetryAfter : 30
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new PterodactylException($this->errorMessage($status, json_decode($raw, true), $raw));
        }

        return $raw;
    }

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

        if ($status === 429) {
            throw new PterodactylRateLimitException(
                $this->errorMessage($status, $json, $raw),
                $this->lastRetryAfter > 0 ? $this->lastRetryAfter : 30
            );
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
    private function rawRequest(
        string $method,
        string $path,
        ?array $body,
        ?int &$status,
        ?string $rawBody = null,
        ?string $contentType = null
    ): string {
        $url = $this->baseUrl . $path;

        // files/write envoie le fichier tel quel : annoncer application/json sur
        // un corps qui n'en est pas invite Laravel à le parser pour rien.
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: ' . ($contentType ?? 'application/json'),
        ];

        $payload = $rawBody;
        if ($payload === null && $body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (!function_exists('curl_init')) {
            throw new PterodactylException('cURL indisponible sur ce serveur PHP.');
        }

        $this->lastRetryAfter = 0;

        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            // Seul en-tête qui nous intéresse : combien de temps patienter
            // quand le panel throttle (HTTP 429).
            CURLOPT_HEADERFUNCTION => function ($ch, string $header): int {
                if (stripos($header, 'retry-after:') === 0) {
                    $this->lastRetryAfter = (int)trim(substr($header, 12));
                }
                return strlen($header);
            },
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
                : ' — ' . ($this->keySource !== '' ? $this->keySource . ' porte une ' : 'clé ')
                  . 'clé ' . self::keyType($this->apiKey)
                  . '. Créez une clé de compte dans le panel (Compte → Clés API) et placez-la dans '
                  . 'PTERO_CLIENT_API_KEY ; la clé d\'application reste utilisable ailleurs.';

            return 'Panel Pterodactyl (HTTP ' . $status . ') : '
                 . ($detail !== '' ? $detail : 'clé API refusée ou sans accès à ce serveur.') . $hint;
        }

        if ($status === 429) {
            $wait = $this->lastRetryAfter > 0 ? $this->lastRetryAfter : 30;

            return 'Panel Pterodactyl (HTTP 429) : quota d\'appels atteint, nouvelle tentative dans '
                 . $wait . ' s. Le portail interroge le panel avec UN seul compte : '
                 . 'le quota est partagé par tous les clients connectés.';
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
