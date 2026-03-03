<?php
require_once __DIR__ . '/vendor/autoload.php';

use Jumbojett\OpenIDConnectClient;

$oidc = new OpenIDConnectClient(
    'https://your-keycloak-domain/realms/your-realm',
    'CLIENT_ID',
    'CLIENT_SECRET'
);

// Optional config
$oidc->setRedirectURL('http://localhost:8000/keycloak.php');
$oidc->setProviderConfigParams([
    'token_endpoint' => 'https://your-keycloak-domain/realms/your-realm/protocol/openid-connect/token',
    'userinfo_endpoint' => 'https://your-keycloak-domain/realms/your-realm/protocol/openid-connect/userinfo'
]);

// Start login flow
$oidc->authenticate();

// Show user info
$userInfo = $oidc->requestUserInfo();

echo "<h1>Welcome, " . htmlspecialchars($userInfo->preferred_username) . "</h1>";
echo "<pre>";
print_r($userInfo);
echo "</pre>";
?>