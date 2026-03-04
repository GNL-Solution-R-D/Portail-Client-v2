<?php
$host = config('DB_HOST');
$port = config('DB_PORT');
$dbname = config('DB_NAME');
$username = config('DB_USER');
$password = config('DB_PASSWORD');

$missing = [];
foreach (
    [
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_NAME' => $dbname,
        'DB_USER' => $username,
        'DB_PASSWORD' => $password
    ] as $key => $value
) {
    if ($value === null || $value === false || $value === '') {
        $missing[] = $key;
    }
}

if (!empty($missing)) {
    http_response_code(500);
    error_log('Variables de configuration manquantes : ' . implode(', ', $missing));
    echo 'Configuration base de données incomplète.';
    exit();
}

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Erreur connexion oh_pame [' . $host . ':' . $port . '/' . $dbname . ' - ' . $username . '] : ' . $e->getMessage());
    echo 'Erreur de connexion à la base de données principale.';
    exit();
}
?>
