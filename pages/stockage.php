<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: /connexion');
    exit;
}

$deploymentName = $_GET['name'] ?? $_GET['deployment'] ?? '';
if (!is_string($deploymentName) || $deploymentName === '') {
    http_response_code(400);
    echo 'Deployment invalide.';
    exit;
}

header('Location: /deployment?name=' . rawurlencode($deploymentName), true, 302);
exit;
