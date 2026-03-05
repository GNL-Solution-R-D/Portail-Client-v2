<?php

declare(strict_types=1);

// Cookie de session valable sur /pages/* ET /k8s/*
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: /connexion');
    exit;
}

require_once '../k8s/KubernetesClient.php';

$namespace = $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['k8sNamespace']
    ?? $_SESSION['user']['namespace_k8s']
    ?? $_SESSION['user']['k8s_ns']
    ?? $_SESSION['user']['namespace']
    ?? '';

$name = $_GET['name'] ?? '';
if (!is_string($name) || $name === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name)) {
    http_response_code(400);
    echo 'Deployment invalide.';
    exit;
}

// CSRF token (optionnel mais utile)
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$error = null;
$deployment = null;
try {
    $k8s = new KubernetesClient();
    $deployment = $k8s->getDeployment((string)$namespace, $name);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$replicas = (int)($deployment['spec']['replicas'] ?? 0);
$ready   = (int)($deployment['status']['readyReplicas'] ?? 0);
$updated = (int)($deployment['status']['updatedReplicas'] ?? 0);
$avail   = (int)($deployment['status']['availableReplicas'] ?? 0);

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Deployment <?= htmlspecialchars($name) ?></title>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css" />
  <style>
    .wrap{max-width:1100px;margin:0 auto;padding:24px;}
    .grid{display:grid;grid-template-columns:1fr;gap:16px;}
    @media(min-width:900px){.grid{grid-template-columns:1fr 1fr;}}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}
  </style>
</head>
<body class="bg-background text-foreground">
  <?php if (file_exists('../include/header.php')) include('../include/header.php'); ?>

  <div class="wrap">
    <div class="mb-6">
      <a class="text-muted-foreground hover:text-foreground" href="/dashboard">← Retour dashboard</a>
      <h1 class="text-2xl font-bold mt-3">Deployment <span class="mono"><?= htmlspecialchars($name) ?></span></h1>
      <p class="text-muted-foreground">Namespace: <span class="mono"><?= htmlspecialchars((string)$namespace) ?></span></p>
    </div>

    <?php if ($error !== null): ?>
      <div class="bg-card rounded-xl border p-6 text-red-600">
        <strong>Erreur Kubernetes:</strong>
        <div class="mt-2 mono text-sm"><?= htmlspecialchars($error) ?></div>
      </div>
    <?php else: ?>

      <div class="grid">
        <div class="bg-card rounded-xl border p-6">
          <h2 class="text-lg font-semibold mb-3">État</h2>
          <div class="space-y-2 text-sm">
            <div>Replicas: <span class="mono"><?= $replicas ?></span></div>
            <div>Ready: <span class="mono"><?= $ready ?></span></div>
            <div>Updated: <span class="mono"><?= $updated ?></span></div>
            <div>Available: <span class="mono"><?= $avail ?></span></div>
          </div>
          <div class="mt-5">
            <button id="restartBtn" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium px-4 py-2 border hover:bg-secondary transition-colors">
              Redémarrer le déploiement
            </button>
            <div id="restartMsg" class="text-sm text-muted-foreground mt-2"></div>
          </div>
        </div>

        <div class="bg-card rounded-xl border p-6">
          <h2 class="text-lg font-semibold mb-3">Détails</h2>
          <div class="text-sm space-y-2">
            <div>Strategy: <span class="mono"><?= htmlspecialchars((string)($deployment['spec']['strategy']['type'] ?? '')) ?></span></div>
            <div>Selector: <span class="mono"><?= htmlspecialchars(json_encode($deployment['spec']['selector']['matchLabels'] ?? [], JSON_UNESCAPED_SLASHES)) ?></span></div>
            <div>Created: <span class="mono"><?= htmlspecialchars((string)($deployment['metadata']['creationTimestamp'] ?? '')) ?></span></div>
          </div>
        </div>
      </div>

      <div class="bg-card rounded-xl border p-6 mt-6">
        <h2 class="text-lg font-semibold mb-3">JSON (lecture)</h2>
        <pre class="mono text-xs overflow-auto p-4 rounded-lg bg-muted" style="max-height: 55vh;"><?= htmlspecialchars(json_encode($deployment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
      </div>

    <?php endif; ?>
  </div>

  <script>
    (function(){
      const btn = document.getElementById('restartBtn');
      const msg = document.getElementById('restartMsg');
      if(!btn) return;

      btn.addEventListener('click', async () => {
        btn.disabled = true;
        msg.textContent = 'Redémarrage en cours…';
        try {
          const body = new URLSearchParams({ name: <?= json_encode($name) ?> });
          const apiUrl = new URL('../k8s/k8s_api.php', window.location.origin);
          apiUrl.searchParams.set('action', 'restart_deployment');

          const res = await fetch(apiUrl.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
              'X-CSRF-Token': <?= json_encode($_SESSION['csrf']) ?>,
            },
            body,
          });

          const ct = (res.headers.get('content-type') || '').toLowerCase();
          const raw = await res.text();
          let data = null;
          try { data = JSON.parse(raw); } catch(_) { /* ignore */ }

          if(!ct.includes('application/json') || !data){
            throw new Error(`Réponse non-JSON (${res.status}). URL: ${apiUrl.pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
          }

          if(!res.ok || !data.ok){
            throw new Error(data.error || ('HTTP ' + res.status));
          }

          msg.textContent = 'Ok. Kubernetes a reçu le patch. Le rollout va suivre.';
        } catch (e) {
          msg.textContent = 'Erreur: ' + (e && e.message ? e.message : String(e));
        } finally {
          btn.disabled = false;
        }
      });
    })();
  </script>
</body>
</html>
