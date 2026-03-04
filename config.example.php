<?php
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$dbname = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Erreur de connexion à oh_pame : ' . $e->getMessage());
    echo 'Erreur de connexion à la base de données principale.';
    exit();
}

$powerdns_dbname = getenv('PAME_POWERDNS_DB') ?: 'powerdns';
try {
    $pdo_powerdns = new PDO("mysql:host=$host;port=$port;dbname=$powerdns_dbname;charset=latin1", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Erreur de connexion à PowerDNS : ' . $e->getMessage());
    echo 'Erreur de connexion à la base de données PowerDNS.';
    exit();
}
?>
