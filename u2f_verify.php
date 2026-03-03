<?php
session_start();
require_once 'config_loader.php';
if (isset($_GET['finish'])) {
    if (!isset($_SESSION['pending_user'])) {
        header('Location: index.php');
        exit();
    }
    $_SESSION['user'] = $_SESSION['pending_user'];
    unset($_SESSION['pending_user'], $_SESSION['pending_user_id']);
    header('Location: dashboard.php');
    exit();
}
if (!isset($_SESSION['pending_user_id'])) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Validation U2F</title>
</head>
<body>
<p>Veuillez valider votre cle de securite.</p>
<button id="u2f-login">Valider</button>
<script src="assets/js/webauthn.js"></script>
<script>
async function verify(){
    const rep = await fetch('update_u2f.php?fn=getGetArgs');
    const getArgs = await rep.json();
    if(getArgs.success===false){ alert(getArgs.msg); return; }
    recursiveBase64StrToArrayBuffer(getArgs);
    const cred = await navigator.credentials.get(getArgs);
    const data = {
        id: arrayBufferToBase64(cred.rawId),
        clientDataJSON: arrayBufferToBase64(cred.response.clientDataJSON),
        authenticatorData: arrayBufferToBase64(cred.response.authenticatorData),
        signature: arrayBufferToBase64(cred.response.signature),
        userHandle: cred.response.userHandle ? arrayBufferToBase64(cred.response.userHandle) : null
    };
    const res = await fetch('update_u2f.php?fn=processGet', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify(data)
    });
    const json = await res.json();
    if(json.success){
        window.location = 'u2f_verify.php?finish=1';
    } else {
        alert(json.msg || 'Erreur');
    }
}
 document.getElementById('u2f-login').addEventListener('click', verify);
</script>
</body>
</html>
