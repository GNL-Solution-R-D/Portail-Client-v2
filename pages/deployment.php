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

      <div class="bg-card rounded-xl border p-6 mt-6" id="podsCard">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">Pods</h2>
          <a class="text-sm text-muted-foreground hover:text-foreground" href="/log?deployment=<?= urlencode($name) ?>">Logs complets →</a>
        </div>
        <p class="text-sm text-muted-foreground mt-2">Statut des pods du déploiement + dernières lignes de logs (aperçu).</p>
        <div id="podsList" class="mt-4 space-y-3 text-sm">
          <div class="text-muted-foreground">Chargement…</div>
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

  <script>
    // Pods + aperçu logs
    (function(){
      const host = document.getElementById('podsList');
      if(!host) return;

      const escapeHtml = (s) => String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');

      const badge = (text, kind='muted') => {
        const cls = kind === 'ok'
          ? 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400'
          : kind === 'warn'
            ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400'
            : kind === 'err'
              ? 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-400'
              : 'bg-muted text-muted-foreground';
        return `<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${cls}">${escapeHtml(text)}</span>`;
      };

      const age = (iso) => {
        if(!iso) return '—';
        const t = Date.parse(iso);
        if(Number.isNaN(t)) return '—';
        const s = Math.max(0, Math.floor((Date.now() - t)/1000));
        if(s < 60) return s + 's';
        const m = Math.floor(s/60);
        if(m < 60) return m + 'm';
        const h = Math.floor(m/60);
        if(h < 48) return h + 'h';
        const d = Math.floor(h/24);
        return d + 'j';
      };

      const apiBase = new URL('../k8s/k8s_api.php', window.location.origin);

      async function fetchJson(url){
        const res = await fetch(url.toString(), {credentials:'same-origin'});
        const raw = await res.text();
        let data = null;
        try { data = JSON.parse(raw); } catch(_) {}
        if(!data || typeof data !== 'object'){
          throw new Error(`Réponse non-JSON (${res.status}). URL: ${new URL(url).pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
        }
        if(!res.ok || !data.ok){
          throw new Error(data.error || ('HTTP ' + res.status));
        }
        return data;
      }

      function phaseKind(phase){
        if(phase === 'Running') return 'ok';
        if(phase === 'Pending') return 'warn';
        if(phase === 'Failed') return 'err';
        if(phase === 'Succeeded') return 'muted';
        return 'muted';
      }

      async function loadLogs(pod, container, preEl, btnEl, tail=40){
        if(btnEl) { btnEl.disabled = true; btnEl.textContent = 'Chargement…'; }
        try{
          const u = new URL(apiBase);
          u.searchParams.set('action','pod_logs_tail');
          u.searchParams.set('pod', pod);
          if(container) u.searchParams.set('container', container);
          u.searchParams.set('tail', String(tail));
          u.searchParams.set('timestamps','1');
          const data = await fetchJson(u);
          preEl.textContent = data.text || '';
        } finally {
          if(btnEl) { btnEl.disabled = false; btnEl.textContent = 'Rafraîchir logs'; }
        }
      }

      function render(pods){
        if(!pods.length){
          host.innerHTML = `<div class="text-muted-foreground">Aucun pod trouvé pour ce déploiement.</div>`;
          return;
        }

        host.innerHTML = pods.map((p) => {
          const ready = `${p.readyContainers}/${p.totalContainers}`;
          const kind = phaseKind(p.phase);
          const restarts = String(p.restartCount ?? 0);
          const node = p.node ? escapeHtml(p.node) : '—';
          const created = age(p.createdAt);
          const containers = Array.isArray(p.containers) ? p.containers : [];
          const containerOptions = containers.map((c,i)=>`<option value="${escapeHtml(c.name)}" ${i===0?'selected':''}>${escapeHtml(c.name)}</option>`).join('');
          const logHref = `/log?deployment=${encodeURIComponent(<?= json_encode($name) ?>)}&pod=${encodeURIComponent(p.name)}&container=${encodeURIComponent(containers[0]?.name || '')}`;
          return `
            <div class="rounded-lg border bg-background p-4">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <div class="font-semibold mono truncate">${escapeHtml(p.name)}</div>
                    ${badge(p.phase, kind)}
                    ${badge('Ready ' + ready, (p.readyContainers === p.totalContainers && p.totalContainers > 0) ? 'ok' : 'warn')}
                    ${badge('Restarts ' + restarts, (p.restartCount||0) > 0 ? 'warn' : 'muted')}
                  </div>
                  <div class="text-xs text-muted-foreground mt-1">Node: <span class="mono">${node}</span> · Age: <span class="mono">${created}</span></div>
                </div>
                <div class="flex items-center gap-2">
                  <a class="text-sm text-muted-foreground hover:text-foreground" data-log-link="${escapeHtml(p.name)}" href="${logHref}">Voir logs</a>
                </div>
              </div>

              <div class="mt-3 flex flex-wrap items-center gap-2">
                <label class="text-xs text-muted-foreground">Container</label>
                <select class="border rounded-md px-2 py-1 text-sm bg-background" data-pod-container="${escapeHtml(p.name)}">
                  ${containerOptions || '<option value="">(aucun)</option>'}
                </select>
                <button class="border rounded-md px-3 py-1 text-sm hover:bg-secondary" data-load-logs="${escapeHtml(p.name)}">Afficher logs</button>
                <span class="text-xs text-muted-foreground">(40 lignes)</span>
              </div>

              <pre class="mono text-xs overflow-auto p-3 rounded-lg bg-muted mt-3" style="max-height: 220px; white-space: pre;" data-pod-pre="${escapeHtml(p.name)}">Sélectionne un container puis clique « Afficher logs ».</pre>
            </div>
          `;
        }).join('');

        // Wire events
        host.querySelectorAll('[data-load-logs]').forEach(btn => {
          btn.addEventListener('click', () => {
            const pod = btn.getAttribute('data-load-logs');
            const sel = host.querySelector(`[data-pod-container="${CSS.escape(pod)}"]`);
            const pre = host.querySelector(`[data-pod-pre="${CSS.escape(pod)}"]`);
            const container = sel && sel.value ? sel.value : '';
            const link = host.querySelector(`[data-log-link="${CSS.escape(pod)}"]`);
            if(link){
              const u = new URL(link.getAttribute('href'), window.location.origin);
              u.searchParams.set('container', container);
              link.setAttribute('href', u.pathname + '?' + u.searchParams.toString());
            }
            loadLogs(pod, container, pre, btn, 40);
          });
        });

        // Auto-load logs for up to 3 newest pods
        pods.slice(0,3).forEach(p => {
          const pre = host.querySelector(`[data-pod-pre="${CSS.escape(p.name)}"]`);
          const btn = host.querySelector(`[data-load-logs="${CSS.escape(p.name)}"]`);
          const sel = host.querySelector(`[data-pod-container="${CSS.escape(p.name)}"]`);
          const container = sel && sel.value ? sel.value : '';
          if(pre && btn) loadLogs(p.name, container, pre, btn, 40);
        });
      }

      (async function(){
        try{
          const u = new URL(apiBase);
          u.searchParams.set('action','list_pods_for_deployment');
          u.searchParams.set('deployment', <?= json_encode($name) ?>);
          const data = await fetchJson(u);
          render(Array.isArray(data.pods) ? data.pods : []);
        } catch(e){
          host.innerHTML = `<div class="text-red-600"><strong>Erreur:</strong> ${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
        }
      })();
    })();
  </script>
</body>
</html>
