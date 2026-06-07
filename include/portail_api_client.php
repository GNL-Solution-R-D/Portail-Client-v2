<?php

/**
 * include/portail_api_client.php
 *
 * Client serveur PARTAGÉ vers le webhook n8n « data-portail ».
 *
 * Centralise la logique de transport historiquement embarquée dans
 * data/portail_api.php (fonction n8n_call) afin qu'elle soit réutilisable
 * AILLEURS que dans le proxy navigateur — en particulier à la connexion
 * (keycloak_callback.php) pour alimenter la table « team ».
 *
 * Principes (identiques à data/portail_api.php) :
 *   - UN SEUL webhook n8n, toujours appelé en POST JSON ;
 *   - le champ "action" est préfixé par le module ("team.ensure", …) ;
 *   - le client_id n'est JAMAIS pris du navigateur : l'appelant le fournit
 *     depuis une source de confiance (session / claims Keycloak).
 *
 * Aucune sortie : ce fichier ne définit que des fonctions (idempotent à inclure).
 */

declare(strict_types=1);

if (!defined('PORTAIL_API_DEFAULT_URL')) {
    // Repli si la variable d'environnement N8N_DATA_PORTAIL_URL est absente.
    define('PORTAIL_API_DEFAULT_URL', 'https://api.gnl-solution.fr/webhook/data-portail');
}

if (!function_exists('portailApiEnvNonEmpty')) {
    /** Variable d'environnement uniquement si définie ET non vide après trim. */
    function portailApiEnvNonEmpty(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}

if (!function_exists('portailApiUrl')) {
    function portailApiUrl(): string
    {
        return portailApiEnvNonEmpty('N8N_DATA_PORTAIL_URL') ?? PORTAIL_API_DEFAULT_URL;
    }
}

if (!function_exists('portailApiCall')) {
    /**
     * Relaie un payload au webhook n8n UNIQUE, toujours en POST JSON,
     * et renvoie la réponse décodée.
     *
     * Comportement et forme de retour STRICTEMENT identiques à l'ancien
     * data/portail_api.php::n8n_call() (compatibilité totale : les défauts
     * 12s / 6s reproduisent l'ancien comportement). Des timeouts plus courts
     * peuvent être passés pour les appels « best-effort » (ex. à la connexion).
     *
     * @return array{status:int, json:mixed, raw:string}
     */
    function portailApiCall(array $payload, int $timeout = 12, int $connectTimeout = 6): array
    {
        $url     = portailApiUrl();
        $token   = portailApiEnvNonEmpty('N8N_WEBHOOK_TOKEN');
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token !== null) {
            // n8n « Header Auth » : adapter le nom d'en-tête à votre workflow.
            $headers[] = 'Authorization: Bearer ' . $token;
            $headers[] = 'X-GNL-Token: ' . $token;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            ]);
            $raw    = curl_exec($ch);
            $errno  = curl_errno($ch);
            $err    = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($errno !== 0) {
                throw new RuntimeException('Connexion n8n impossible : ' . $err);
            }
            $raw = (string) $raw;
        } else {
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                throw new RuntimeException('Connexion n8n impossible.');
            }
            $status = 0;
            foreach (($http_response_header ?? []) as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int) $m[1];
                }
            }
            $raw = (string) $raw;
        }

        $json = json_decode($raw, true);
        return ['status' => $status, 'json' => $json, 'raw' => $raw];
    }
}

if (!function_exists('portailFirstNonEmpty')) {
    /** Première valeur non vide parmi plusieurs clés candidates. */
    function portailFirstNonEmpty(array $row, array $keys, string $default = ''): string
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && trim((string) $row[$k]) !== '') {
                return trim((string) $row[$k]);
            }
        }
        return $default;
    }
}

if (!function_exists('portailBuildTeamEnsurePayload')) {
    /**
     * Construit le payload "team.ensure" à partir d'un utilisateur de session
     * (issu de keycloakBuildSessionUser). Tolérant aux variantes de clés.
     *
     * Le client_id provient de la session (non falsifiable). n8n se charge du
     * find-or-create de l'équipe (regroupée par siret/structure/namespace) et
     * de l'upsert de la ligne d'appartenance dans la table « team ».
     */
    function portailBuildTeamEnsurePayload(array $sessionUser, string $source = 'keycloak_callback'): array
    {
        $clientId = (int) ($sessionUser['id'] ?? 0);

        $permRaw = $sessionUser['perm_id'] ?? $sessionUser['permission'] ?? $sessionUser['role_id'] ?? null;
        $permId  = (is_numeric($permRaw)) ? (int) $permRaw : null;

        // Clés alignées sur keycloakBuildSessionUser() (include/keycloak_auth.php).
        // Les fallbacks restent tolérants si la forme de session évolue.
        return [
            'action'         => 'team.ensure',
            'client_id'      => $clientId,

            // ── Identité du membre (colonnes directes de la table « team ») ──
            'civilite'       => portailFirstNonEmpty($sessionUser, ['civilite', 'title']),
            'prenom'         => portailFirstNonEmpty($sessionUser, ['prenom', 'firstName', 'firstname', 'first_name', 'given_name']),
            'nom'            => portailFirstNonEmpty($sessionUser, ['nom', 'lastName', 'lastname', 'last_name', 'family_name']),
            'email'          => portailFirstNonEmpty($sessionUser, ['email', 'ent_email', 'organization_email', 'mail']),
            'telephone'      => portailFirstNonEmpty($sessionUser, ['telephone', 'phone', 'organization_telephone']),
            'fonction'       => portailFirstNonEmpty($sessionUser, ['fonction', 'poste', 'job', 'function', 'job_title']),
            'username'       => portailFirstNonEmpty($sessionUser, ['username', 'preferred_username']),
            'perm_id'        => $permId,

            // ── Identification entreprise (find-or-create de l'équipe côté n8n) ──
            'siret'          => portailFirstNonEmpty($sessionUser, ['siret', 'organization_siret']),
            'siren'          => portailFirstNonEmpty($sessionUser, ['siren', 'organization_siren']),
            'client_code'    => portailFirstNonEmpty($sessionUser, ['client_code', 'code_client', 'organization_code_client']),
            'structure'      => portailFirstNonEmpty($sessionUser, [
                'raison', 'organization_name', 'organization', 'nom_commercial', 'company', 'structure',
            ]),
            'nom_commercial' => portailFirstNonEmpty($sessionUser, ['nom_commercial', 'organization_commercial_name']),
            'num_tva'        => portailFirstNonEmpty($sessionUser, ['num_tva', 'organization_tva', 'tva']),

            // ── Contexte Kubernetes (clé de regroupement éventuelle) ──
            'k8s_namespace'  => portailFirstNonEmpty($sessionUser, ['k8s_namespace', 'namespace']),
            'cluster'        => portailFirstNonEmpty($sessionUser, ['cluster', 'cluster_id']),

            'source'         => $source,
        ];
    }
}

if (!function_exists('portailEnsureTeamMembership')) {
    /**
     * Alimente (idempotent) la table « team » pour l'utilisateur fourni,
     * via le pipeline n8n data-portail (action "team.ensure").
     *
     * @return array{status:int, json:mixed, raw:string}
     * @throws RuntimeException si client_id absent ou si n8n est injoignable
     */
    function portailEnsureTeamMembership(
        array $sessionUser,
        string $source = 'keycloak_callback',
        int $timeout = 4,
        int $connectTimeout = 2
    ): array {
        $payload = portailBuildTeamEnsurePayload($sessionUser, $source);
        if ((int) $payload['client_id'] <= 0) {
            throw new RuntimeException('client_id introuvable : impossible d\'alimenter la table team.');
        }
        return portailApiCall($payload, $timeout, $connectTimeout);
    }
}