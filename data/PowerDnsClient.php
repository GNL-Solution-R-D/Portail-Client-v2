<?php

/**
 * data/PowerDnsClient.php
 *
 * Client serveur de l'API REST PowerDNS Authoritative (5.0).
 *
 * Le navigateur n'appelle JAMAIS cette API : la clé X-API-Key reste côté
 * serveur, derrière data/pdns_api.php qui revérifie les droits du client à
 * chaque appel.
 *
 * ── Chemin réseau ────────────────────────────────────────────────────────────
 * Le portail (namespace `webintern`) joint le Service PowerDNS (namespace
 * `powerdns`) par son nom complet de cluster, sans passer par l'Ingress :
 *
 *     http://pwrdns-service.powerdns.svc.cluster.local/api/v1/...
 *
 * Donc pas de TLS, pas d'allowlist IP, pas de basic auth — le trafic ne sort
 * jamais du cluster. La NetworkPolicy du namespace `powerdns` doit autoriser
 * `webintern` sur le port 8081 (voir powerdns-k8s/07-networkpolicy.yaml).
 *
 * ── Configuration (Secret Kubernetes, lue par config()) ──────────────────────
 *   PDNS_API_URL   — défaut : http://pwrdns-service.powerdns.svc.cluster.local
 *                    Acceptée avec ou sans /api/v1, avec ou sans / final.
 *   PDNS_API_KEY   — clé de l'API PowerDNS (obligatoire, sinon client null).
 *   PDNS_SERVER_ID — défaut « localhost », l'identifiant serveur de l'API.
 *
 * ── Pièges traités ───────────────────────────────────────────────────────────
 *   • Un 200 non-JSON n'est PAS un succès (même leçon que PterodactylClient) :
 *     une URL qui ne pointe pas sur PowerDNS renvoie volontiers du HTML en 200.
 *   • PATCH répond 204 sans corps : pas d'erreur à en tirer.
 *   • Les identifiants de zone portent un point final — il fait partie du nom.
 */

declare(strict_types=1);

class PowerDnsException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

final class PowerDnsClient
{
    /** Service PowerDNS joint depuis un autre namespace du même cluster. */
    public const DEFAULT_URL = 'http://pwrdns-service.powerdns.svc.cluster.local';

    private string $base;
    private string $apiKey;
    private string $serverId;
    private int $timeout;
    private int $connectTimeout;

    public function __construct(
        string $url,
        string $apiKey,
        string $serverId = 'localhost',
        int $timeout = 10,
        int $connectTimeout = 4
    ) {
        $this->base           = self::normalizeUrl($url);
        $this->apiKey         = trim($apiKey);
        $this->serverId       = $serverId !== '' ? $serverId : 'localhost';
        $this->timeout        = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /** Instancie depuis la configuration, ou null si la clé API manque. */
    public static function fromConfig(): ?self
    {
        $url = trim((string) config('PDNS_API_URL', self::DEFAULT_URL));
        $key = trim((string) config('PDNS_API_KEY', ''));
        if ($key === '') {
            return null;
        }
        return new self(
            $url !== '' ? $url : self::DEFAULT_URL,
            $key,
            trim((string) config('PDNS_SERVER_ID', 'localhost'))
        );
    }

    /**
     * Normalise vers « <schéma>://<hôte>[:port]/api/v1 ».
     * Accepte les quatre écritures courantes de PDNS_API_URL.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return self::DEFAULT_URL . '/api/v1';
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'http://' . $url;
        }
        // Retire un /api ou /api/v1 déjà présent, puis le repose : une seule forme.
        $url = (string) preg_replace('~/api(/v\d+)?$~i', '', $url);
        return rtrim($url, '/') . '/api/v1';
    }

    /**
     * Nom de zone canonique PowerDNS : minuscules, un point final, et sans le
     * préfixe « *. » — un joker est un ENREGISTREMENT dans la zone, pas une zone.
     * La page zdns.php interroge historiquement « domaine » et « *.domaine » ;
     * les deux retombent donc ici sur la même zone.
     */
    public static function canonicalZone(string $zone): string
    {
        $zone = strtolower(trim($zone));
        $zone = (string) preg_replace('~^\*\.~', '', $zone);
        $zone = rtrim($zone, '.');
        return $zone === '' ? '' : $zone . '.';
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    public function serverId(): string
    {
        return $this->serverId;
    }

    /** Fiche serveur — sert de test de configuration (voir l'action diag). */
    public function serverInfo(): array
    {
        return $this->request('GET', '/servers/' . rawurlencode($this->serverId));
    }

    /** Zone complète, rrsets inclus. Lève une PowerDnsException en 404. */
    public function getZone(string $zone): array
    {
        $z = self::canonicalZone($zone);
        if ($z === '') {
            throw new PowerDnsException('Nom de zone vide.', 400);
        }
        return $this->request(
            'GET',
            '/servers/' . rawurlencode($this->serverId) . '/zones/' . rawurlencode($z)
        );
    }

    /**
     * Applique des changements de rrsets (changetype REPLACE ou DELETE).
     * PowerDNS répond 204 sans corps.
     */
    public function patchRrsets(string $zone, array $rrsets): void
    {
        $z = self::canonicalZone($zone);
        if ($z === '') {
            throw new PowerDnsException('Nom de zone vide.', 400);
        }
        $this->request(
            'PATCH',
            '/servers/' . rawurlencode($this->serverId) . '/zones/' . rawurlencode($z),
            ['rrsets' => $rrsets]
        );
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->apiKey === '') {
            throw new PowerDnsException('PDNS_API_KEY n\'est pas configurée.', 500);
        }

        $url = $this->base . $path;
        $ch  = curl_init($url);
        if ($ch === false) {
            throw new PowerDnsException('Impossible d\'initialiser la requête HTTP.', 500);
        }

        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errNo !== 0 || $raw === false) {
            throw new PowerDnsException(
                'PowerDNS injoignable (' . $errMsg . '). URL utilisée : ' . $this->base,
                0
            );
        }
        $raw = (string) $raw;

        // 204 No Content : réponse normale d'un PATCH réussi.
        if ($status === 204 || ($status >= 200 && $status < 300 && trim($raw) === '')) {
            return [];
        }

        $json = json_decode($raw, true);

        if ($status >= 200 && $status < 300) {
            // ⚠️ Un 200 non-JSON n'est pas un succès : une URL qui ne pointe pas
            // sur PowerDNS (ou un portail d'authentification interposé) renvoie
            // du HTML en 200. Sans ce garde-fou on retournerait une zone vide en
            // prétendant que tout va bien.
            if (!is_array($json)) {
                throw new PowerDnsException(
                    'Réponse non-JSON reçue en HTTP ' . $status . ' — '
                    . $this->base . ' ne semble pas être une API PowerDNS.',
                    502
                );
            }
            return $json;
        }

        $detail = '';
        if (is_array($json)) {
            $detail = (string) ($json['error'] ?? $json['message'] ?? '');
        }
        if ($detail === '') {
            $detail = trim(substr($raw, 0, 300));
        }

        if ($status === 401 || $status === 403) {
            throw new PowerDnsException(
                'PowerDNS a refusé la clé API (HTTP ' . $status . ')'
                . ($detail !== '' ? ' — ' . $detail : '') . '.',
                $status
            );
        }
        if ($status === 404) {
            throw new PowerDnsException(
                'Zone inconnue de PowerDNS' . ($detail !== '' ? ' — ' . $detail : '') . '.',
                404
            );
        }
        if ($status === 422) {
            // PowerDNS refuse un enregistrement mal formé et dit pourquoi :
            // ce message est utile au client, on le relaie tel quel.
            throw new PowerDnsException(
                $detail !== '' ? $detail : 'Enregistrement refusé par PowerDNS.',
                422
            );
        }

        throw new PowerDnsException(
            'PowerDNS a répondu HTTP ' . $status . ($detail !== '' ? ' — ' . $detail : '') . '.',
            $status
        );
    }
}
