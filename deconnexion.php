<?php
session_start();
require_once 'config_loader.php';
require_once 'include/account_sessions.php';

if (isset($_SESSION['user']['id'])) {
    accountSessionsRevokeCurrent($pdo, (int) $_SESSION['user']['id']);
}

accountSessionsDestroyPhpSession();
header("Location: /connexion");
exit();
