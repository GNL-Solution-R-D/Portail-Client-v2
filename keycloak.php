<?php
// Charge l’autoload de Composer et démarre la session
require_once __DIR__ . '/vendor/autoload.php';
use Jumbojett\OpenIDConnectClient;
session_start();

// Configurez le client OIDC en utilisant votre domaine Keycloak, le royaume, l’ID et le secret du client
$oidc = new OpenIDConnectClient(
    'https://auth.gnl-solution.fr/realms/clients',
    'VOTRE_CLIENT_ID',
    'VOTRE_CLIENT_SECRET'
);

// Indiquez l’URL de redirection enregistrée dans Keycloak
$oidc->setRedirectURL('https://espace-client.gnl-solution.fr/index.php');

// (Optionnel) Indiquez explicitement les endpoints si nécessaire
$oidc->setProviderConfigParams([
    'token_endpoint'    => 'https://auth.gnl-solution.fr/realms/votre-royaume/protocol/openid-connect/token',
    'userinfo_endpoint' => 'https://auth.gnl-solution.fr/realms/votre-royaume/protocol/openid-connect/userinfo',
]);

try {
    // Lance le flux d’authentification : redirection vers Keycloak si non connecté
    $oidc->authenticate();

    // Récupère les informations de l’utilisateur authentifié
    $userInfo = $oidc->requestUserInfo();

    // Affiche votre contenu protégé
    echo '<h1>Bonjour ' . htmlspecialchars($userInfo->preferred_username) . ' !</h1>';
    echo '<p>Votre adresse e‑mail : ' . htmlspecialchars($userInfo->email) . '</p>';
    echo '<p>Bienvenue sur la page protégée accessible uniquement aux utilisateurs connectés via Keycloak.</p>';
} catch (Exception $e) {
    // Gère les erreurs d’authentification
    echo '<p>Erreur d\'authentification : ' . htmlspecialchars($e->getMessage()) . '</p>';
}