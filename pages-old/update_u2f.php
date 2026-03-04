<?php
session_start();
require_once 'config_loader.php';
require_once 'vendor/autoload.php';
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

$userId = $_SESSION['user']['id'] ?? ($_SESSION['pending_user_id'] ?? null);
if (!$userId) {
    http_response_code(401);
    exit('Unauthorized');
}

$WebAuthn = new WebAuthn('OpenHebergement', $_SERVER['HTTP_HOST']);
$fn = $_GET['fn'] ?? '';

if ($fn === 'getCreateArgs') {
    $args = $WebAuthn->getCreateArgs($userId, $_SESSION['user']['siren'] ?? '', $_SESSION['user']['name'] ?? '');
    $_SESSION['challenge'] = $WebAuthn->getChallenge();
    header('Content-Type: application/json');
    echo json_encode($args);
    exit();
}

if ($fn === 'processCreate') {
    $input = json_decode(file_get_contents('php://input'));
    $clientDataJSON = base64_decode($input->clientDataJSON ?? '');
    $attestationObject = base64_decode($input->attestationObject ?? '');
    $challenge = $_SESSION['challenge'] ?? '';
    try {
        $data = $WebAuthn->processCreate($clientDataJSON, $attestationObject, $challenge);
        $stmt = $pdo->prepare('INSERT INTO user_u2f (user_id, key_handle, public_key) VALUES (?, ?, ?)');
        $stmt->execute([$userId, base64_encode($data->credentialId), base64_encode($data->credentialPublicKey)]);
        $stmt = $pdo->prepare('SELECT recovery_code FROM users WHERE id=?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (empty($row['recovery_code'])) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare('UPDATE users SET recovery_code=? WHERE id=?')->execute([password_hash($code, PASSWORD_DEFAULT), $userId]);
            $_SESSION['recovery_code'] = $code;
        }
        echo json_encode(['success'=>true]);
    } catch (WebAuthnException $e) {
        echo json_encode(['success'=>false,'msg'=>$e->getMessage()]);
    }
    exit();
}

if ($fn === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    $pdo->prepare('DELETE FROM user_u2f WHERE id=? AND user_id=?')->execute([$id, $userId]);
    echo json_encode(['success'=>true]);
    exit();
}

if ($fn === 'getGetArgs') {
    $stmt = $pdo->prepare('SELECT key_handle FROM user_u2f WHERE user_id=?');
    $stmt->execute([$userId]);
    $ids = array_map('base64_decode', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) { echo json_encode(['success'=>false,'msg'=>'no keys']); exit(); }
    $args = $WebAuthn->getGetArgs($ids);
    $_SESSION['challenge'] = $WebAuthn->getChallenge();
    header('Content-Type: application/json');
    echo json_encode($args);
    exit();
}

if ($fn === 'processGet') {
    $input = json_decode(file_get_contents('php://input'));
    $clientDataJSON = base64_decode($input->clientDataJSON ?? '');
    $authenticatorData = base64_decode($input->authenticatorData ?? '');
    $signature = base64_decode($input->signature ?? '');
    $id = base64_decode($input->id ?? '');
    $challenge = $_SESSION['challenge'] ?? '';
    $stmt = $pdo->prepare('SELECT public_key FROM user_u2f WHERE user_id=? AND key_handle=?');
    $stmt->execute([$userId, base64_encode($id)]);
    $publicKey = $stmt->fetchColumn();
    if (!$publicKey) { echo json_encode(['success'=>false,'msg'=>'key not found']); exit(); }
    try {
        $WebAuthn->processGet($clientDataJSON, $authenticatorData, $signature, base64_decode($publicKey), $challenge);
        echo json_encode(['success'=>true]);
    } catch (WebAuthnException $e) {
        echo json_encode(['success'=>false,'msg'=>$e->getMessage()]);
    }
    exit();
}

if ($fn === 'list') {
    $stmt = $pdo->prepare('SELECT id, created_at FROM user_u2f WHERE user_id=?');
    $stmt->execute([$userId]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll());
    exit();
}

http_response_code(400);
?>
