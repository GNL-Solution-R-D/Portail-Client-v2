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

      <div class="bg-card rounded-xl border p-6 mt-6" id="urlsCard">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">URLs publiques</h2>
          <a class="text-sm text-muted-foreground hover:text-foreground" href="/network?deployment=<?= urlencode($name) ?>">Gérer dans Network →</a>
        </div>
        <p class="text-sm text-muted-foreground mt-2">Les URLs exposées via Ingress pour ce déploiement (si un Service le pointe).</p>
        <div id="publicUrls" class="mt-4 space-y-2 text-sm">
          <div class="text-muted-foreground">Chargement…</div>
        </div>
      </div>

      <div class="bg-card rounded-xl border p-6 mt-6" id="imageCard">
        <h2 class="text-lg font-semibold mb-3">Image</h2>
        <p class="text-sm text-muted-foreground mb-4">
          Choisis la version du tag (ex: <span class="mono">8.1-apache</span> → <span class="mono">8.3-apache</span>). On garde le même repository, on change juste le tag.
        </p>
        <div id="imageTools" class="space-y-3">
          <div class="text-muted-foreground text-sm">Chargement…</div>
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
    // URLs publiques (Ingress)
    (function(){
      const host = document.getElementById('publicUrls');
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
        return `<span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium border-transparent ${cls}">${escapeHtml(text)}</span>`;
      };

      (async () => {
        try{
          const u = new URL('../k8s/k8s_api.php', window.location.origin);
          u.searchParams.set('action','list_public_urls');
          u.searchParams.set('deployment', <?= json_encode($name) ?>);

          const res = await fetch(u.toString(), { credentials: 'same-origin' });
          const ct = (res.headers.get('content-type') || '').toLowerCase();
          const raw = await res.text();
          let data = null;
          try{ data = JSON.parse(raw); }catch(_){ }

          if(!ct.includes('application/json') || !data){
            throw new Error(`Réponse non-JSON (${res.status}). URL: ${u.pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
          }
          if(!res.ok || !data.ok){
            throw new Error(data.error || ('HTTP ' + res.status));
          }

          const entries = Array.isArray(data.entries) ? data.entries : [];
          host.innerHTML = '';
          if(entries.length === 0){
            host.innerHTML = '<div class="text-muted-foreground">Aucune URL publique trouvée pour ce déploiement.</div>';
            return;
          }

          for(const e of entries){
            const url = e.url || ((e.scheme||'http') + '://' + e.host + (e.path||'/'));
            let cert = '';
            if(e.cert && e.cert.status){
              if(e.cert.status === 'valid'){
                const d = (e.cert.daysRemaining != null) ? ` (${e.cert.daysRemaining}j)` : '';
                cert = badge('TLS OK' + d, 'ok');
              }else if(e.cert.status === 'expired'){
                cert = badge('TLS expiré', 'err');
              }else if(e.cert.status === 'none'){
                cert = badge('Sans TLS', 'muted');
              }else{
                cert = badge('TLS ?', 'warn');
              }
            }

            const row = document.createElement('div');
            row.className = 'flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2';
            row.innerHTML = `
              <div class="min-w-0">
                <a class="font-medium hover:underline break-all" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(url)}</a>
                <div class="text-xs text-muted-foreground mt-1">Ingress: <span class="mono">${escapeHtml(e.ingressName || '')}</span> • Service: <span class="mono">${escapeHtml(e.service || '')}</span></div>
              </div>
              <div class="flex items-center gap-2">
                ${cert}
                <button class="h-8 rounded-md border px-3 text-xs hover:bg-secondary transition-colors" data-copy>Copier</button>
              </div>
            `;
            row.querySelector('[data-copy]').addEventListener('click', async () => {
              try{ await navigator.clipboard.writeText(url); }catch(_){
                const ta = document.createElement('textarea');
                ta.value = url; document.body.appendChild(ta); ta.select();
                document.execCommand('copy'); ta.remove();
              }
            });
            host.appendChild(row);
          }

        }catch(e){
          host.innerHTML = `<div class="text-red-600"><strong>Erreur:</strong> ${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
        }
      })();
    })();
  </script>

  <script>
    // Image tags dropdown
    (function(){
      const host = document.getElementById('imageTools');
      if(!host) return;

      const apiUrl = new URL('../k8s/k8s_api.php', window.location.origin);
      apiUrl.searchParams.set('action', 'list_deployment_images');
      apiUrl.searchParams.set('name', <?= json_encode($name) ?>);

      const escapeHtml = (s) => String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');

      const setMsg = (el, text, kind='muted') => {
        el.className = 'text-xs ' + (kind === 'ok'
          ? 'text-emerald-600'
          : kind === 'warn'
            ? 'text-amber-600'
            : kind === 'err'
              ? 'text-red-600'
              : 'text-muted-foreground');
        el.textContent = text;
      };

      const buildRow = (c) => {
        const id = 'c_' + c.name.replace(/[^a-z0-9_-]/gi,'_');
        const current = c.currentTag ? c.currentTag : '(sans tag)';
        const latest = c.latestTag;

        const wrap = document.createElement('div');
        wrap.className = 'rounded-lg border p-4';

        wrap.innerHTML = `
          <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-medium">Container: <span class="mono">${escapeHtml(c.name)}</span></div>
                <div class="text-xs text-muted-foreground mono break-all mt-1" id="${id}_img">${escapeHtml(c.currentImage)}</div>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <label class="text-xs text-muted-foreground" for="${id}_sel">Tag</label>
                <select id="${id}_sel" class="h-9 rounded-md border bg-background px-3 text-sm">
                  <option value="">Chargement…</option>
                </select>
                <button id="${id}_btn" class="h-9 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Appliquer</button>
              </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="text-xs text-muted-foreground mono">Actuel: <span id="${id}_current">${escapeHtml(current)}</span></div>
              <div id="${id}_info" class="text-xs text-muted-foreground"></div>
            </div>
            <div id="${id}_status" class="text-xs text-muted-foreground"></div>
          </div>
        `;

        const sel = wrap.querySelector('#' + id + '_sel');
        const btn = wrap.querySelector('#' + id + '_btn');
        const info = wrap.querySelector('#' + id + '_info');
        const status = wrap.querySelector('#' + id + '_status');
        const imgEl = wrap.querySelector('#' + id + '_img');
        const currentEl = wrap.querySelector('#' + id + '_current');

        // populate select
        sel.innerHTML = '';
        const tags = Array.isArray(c.availableTags) ? c.availableTags : [];
        if(tags.length === 0){
          sel.innerHTML = '<option value="">(Aucune version disponible)</option>';
          sel.disabled = true;
          btn.disabled = true;
          if(c.note) setMsg(info, c.note, 'warn');
          else setMsg(info, 'Pas de liste de versions pour cette image.', 'warn');
        } else {
          for(const t of tags){
            const opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            if(c.currentTag && t === c.currentTag) opt.selected = true;
            sel.appendChild(opt);
          }

          if(c.note) {
            setMsg(info, c.note, 'warn');
          } else if(c.hasUpdate && latest && c.currentTag && latest !== c.currentTag) {
            setMsg(info, `Nouvelle version disponible: ${latest}`, 'ok');
          } else {
            setMsg(info, 'À jour.', 'muted');
          }
        }

        const postUpdate = async () => {
          const tag = sel.value;
          if(!tag){
            setMsg(status, 'Choisis un tag.', 'warn');
            return;
          }
          btn.disabled = true;
          sel.disabled = true;
          setMsg(status, 'Mise à jour en cours…', 'muted');

          try{
            const body = new URLSearchParams({
              name: <?= json_encode($name) ?>,
              container: c.name,
              tag
            });

            const u = new URL('../k8s/k8s_api.php', window.location.origin);
            u.searchParams.set('action','set_deployment_image_tag');

            const res = await fetch(u.toString(), {
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
            try { data = JSON.parse(raw); } catch(_) {}

            if(!ct.includes('application/json') || !data){
              throw new Error(`Réponse non-JSON (${res.status}). URL: ${u.pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
            }
            if(!res.ok || !data.ok){
              throw new Error(data.error || ('HTTP ' + res.status));
            }

            // update UI: image label + tag courant
            const nextImage = (typeof data.newImage === 'string' && data.newImage !== '')
              ? data.newImage
              : (typeof data.image === 'string' && data.image !== '')
                ? data.image
                : c.currentImage;

            const nextTag = (typeof data.currentTag === 'string' && data.currentTag !== '')
              ? data.currentTag
              : (typeof data.tag === 'string' && data.tag !== '')
                ? data.tag
                : tag;

            if(nextImage) imgEl.textContent = nextImage;
            if(currentEl) currentEl.textContent = nextTag;

            c.currentTag = nextTag;
            c.currentImage = nextImage;
            sel.value = nextTag;

            if(c.latestTag && c.latestTag !== nextTag){
              setMsg(info, `Nouvelle version disponible: ${c.latestTag}`, 'ok');
            } else {
              setMsg(info, 'À jour.', 'muted');
            }
            setMsg(status, 'Ok. Image mise à jour. Kubernetes va lancer un rollout.', 'ok');

          }catch(e){
            setMsg(status, 'Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
          }finally{
            btn.disabled = false;
            sel.disabled = false;
          }
        };

        btn.addEventListener('click', postUpdate);
        return wrap;
      };

      (async () => {
        try{
          const res = await fetch(apiUrl.toString(), { credentials: 'same-origin' });
          const ct = (res.headers.get('content-type') || '').toLowerCase();
          const raw = await res.text();
          let data = null;
          try { data = JSON.parse(raw); } catch(_) {}

          if(!ct.includes('application/json') || !data){
            throw new Error(`Réponse non-JSON (${res.status}). URL: ${apiUrl.pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
          }
          if(!res.ok || !data.ok){
            throw new Error(data.error || ('HTTP ' + res.status));
          }

          const containers = Array.isArray(data.containers) ? data.containers : [];
          host.innerHTML = '';
          if(containers.length === 0){
            host.innerHTML = '<div class="text-sm text-muted-foreground">Aucun container trouvé dans ce deployment.</div>';
            return;
          }
          for(const c of containers){
            host.appendChild(buildRow(c));
          }
        }catch(e){
          host.innerHTML = `<div class="text-sm text-red-600"><strong>Erreur:</strong> ${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
        }
      })();
    })();
  </script>

</body>
</html>
