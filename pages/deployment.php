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

/**
 * Inclut un fichier dans un scope isolé pour éviter qu'un include
 * écrase des variables de la page comme $deploymentName.
 */
function includeIsolated(string $file): void
{
    if (!is_file($file)) {
        return;
    }

    (static function (string $__file): void {
        include $__file;
    })($file);
}

$userNamespace = (string) (
    $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['k8sNamespace']
    ?? $_SESSION['user']['namespace_k8s']
    ?? $_SESSION['user']['k8s_ns']
    ?? $_SESSION['user']['namespace']
    ?? ''
);

$deploymentName = $_GET['name'] ?? '';
if (
    !is_string($deploymentName)
    || $deploymentName === ''
    || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $deploymentName)
) {
    http_response_code(400);
    echo 'Deployment invalide.';
    exit;
}

// CSRF token
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf'];

$k8sError = null;
$deploymentData = null;

try {
    $k8s = new KubernetesClient();
    $deploymentData = $k8s->getDeployment($userNamespace, $deploymentName);
} catch (Throwable $e) {
    $k8sError = $e->getMessage();
}

$replicas = (int)($deploymentData['spec']['replicas'] ?? 0);
$ready = (int)($deploymentData['status']['readyReplicas'] ?? 0);
$updated = (int)($deploymentData['status']['updatedReplicas'] ?? 0);
$available = (int)($deploymentData['status']['availableReplicas'] ?? 0);

$statusLabel = 'Service non démarré';
$statusTone = 'stopped';
$statusDetails = 'Aucun pod prêt pour le moment.';

if ($replicas > 0 && $ready >= $replicas && $available >= $replicas) {
    $statusLabel = 'Service opérationnel';
    $statusTone = 'running';
    $statusDetails = 'Tous les pods attendus sont prêts et disponibles.';
} elseif ($replicas > 0 && ($ready > 0 || $updated > 0 || $available > 0)) {
    $statusLabel = 'Déploiement en cours';
    $statusTone = 'progress';
    $statusDetails = 'Le rollout est en train de converger.';
} elseif ($replicas > 0) {
    $statusLabel = 'Pods en attente';
    $statusTone = 'warning';
    $statusDetails = "Des replicas sont demandés mais rien n'est encore prêt.";
}

$pageTitle = 'Deployment ' . $deploymentName;

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css" />
  <style>
    .dashboard-layout{
      display:flex;
      flex-direction:row;
      align-items:stretch;
      width:100%;
      min-height:100vh;
    }
    .dashboard-sidebar{
      flex:0 0 20rem;
      width:20rem;
      max-width:20rem;
    }
    .dashboard-main{
      flex:1 1 auto;
      min-width:0;
    }
    @media (max-width: 1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{
        width:100%;
        max-width:none;
        flex:0 0 auto;
        height:auto !important;
      }
      .dashboard-main{padding:1rem;}
    }

    .wrap{max-width:1100px;margin:0 auto;padding:24px;}
    .grid{display:grid;grid-template-columns:1fr;gap:16px;}
    @media(min-width:900px){.grid{grid-template-columns:1fr 1fr;}}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}

    .status-hero{
      position:relative;
      overflow:hidden;
      border-radius:1rem;
      border:1px solid rgba(255,255,255,.08);
      min-height:100%;
      background:#0f172a;
      color:#fff;
      box-shadow:0 12px 32px rgba(15,23,42,.20);
    }
    .status-hero::before{
      content:"";
      position:absolute;
      inset:0;
      background-image:url('https://images.unsplash.com/photo-1494984858525-798dd0b282f5?auto=format&fit=crop&q=80&w=2070');
      background-size:cover;
      background-position:center;
      transform:scale(1.02);
    }
    .status-hero::after{
      content:"";
      position:absolute;
      inset:0;
      background:linear-gradient(90deg, rgba(2,6,23,.88) 0%, rgba(2,6,23,.72) 52%, rgba(2,6,23,.52) 100%);
    }
    .status-hero__content{
      position:relative;
      z-index:1;
      display:flex;
      flex-direction:column;
      gap:1.5rem;
      padding:2rem;
      min-height:100%;
    }
    .status-badge{
      display:inline-flex;
      align-items:center;
      gap:.5rem;
      width:max-content;
      max-width:100%;
      padding:.4rem .8rem;
      border-radius:999px;
      background:rgba(255,255,255,.16);
      border:1px solid rgba(255,255,255,.14);
      color:#fff;
      font-size:.75rem;
      font-weight:700;
      backdrop-filter:blur(10px);
    }
    .status-badge__dot{
      width:.65rem;
      height:.65rem;
      border-radius:999px;
      flex:0 0 .65rem;
      box-shadow:0 0 0 4px rgba(255,255,255,.08);
    }
    .status-badge--running .status-badge__dot{background:#22c55e;}
    .status-badge--progress .status-badge__dot{background:#f59e0b;}
    .status-badge--warning .status-badge__dot{background:#fb7185;}
    .status-badge--stopped .status-badge__dot{background:#f97316;}
    .status-hero__title{
      margin:0;
      font-size:clamp(1.8rem, 3vw, 2.8rem);
      line-height:1.05;
      font-weight:800;
      letter-spacing:-0.03em;
    }
    .status-hero__subtitle{
      margin:.75rem 0 0;
      max-width:42rem;
      color:rgba(255,255,255,.82);
      font-size:1rem;
      line-height:1.6;
    }
    .status-hero__separator{
      height:1px;
      width:100%;
      background:rgba(255,255,255,.18);
    }
    .status-hero__footer{
      display:flex;
      flex-wrap:wrap;
      align-items:flex-start;
      justify-content:space-between;
      gap:1rem;
    }
    .status-stats{
      display:flex;
      flex-wrap:wrap;
      gap:.75rem;
    }
    .status-stat{
      min-width:6.5rem;
      padding:.75rem .9rem;
      border-radius:.9rem;
      background:rgba(255,255,255,.10);
      border:1px solid rgba(255,255,255,.10);
      backdrop-filter:blur(8px);
    }
    .status-stat__label{
      display:block;
      font-size:.72rem;
      text-transform:uppercase;
      letter-spacing:.08em;
      color:rgba(255,255,255,.62);
    }
    .status-stat__value{
      display:block;
      margin-top:.25rem;
      font-size:1.15rem;
      font-weight:800;
      color:#fff;
    }
    .status-actions{
      display:flex;
      flex-direction:column;
      align-items:flex-start;
      gap:.6rem;
    }
    .status-restart-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:2.75rem;
      padding:.75rem 1.25rem;
      border:none;
      border-radius:.75rem;
      background:#fff;
      color:#111827;
      font-size:.95rem;
      font-weight:700;
      cursor:pointer;
      box-shadow:0 10px 30px rgba(15,23,42,.18);
      transition:transform .15s ease, box-shadow .15s ease, opacity .15s ease;
    }
    .status-restart-btn:hover{
      transform:translateY(-1px);
      box-shadow:0 14px 34px rgba(15,23,42,.24);
    }
    .status-restart-btn:disabled{
      cursor:not-allowed;
      opacity:.65;
      transform:none;
    }
    .status-restart-msg{
      color:rgba(255,255,255,.78);
      font-size:.875rem;
      line-height:1.5;
    }
    @media (max-width: 640px){
      .status-hero__content{padding:1.5rem;}
      .status-hero__footer{flex-direction:column;}
      .status-stats{width:100%;}
      .status-stat{flex:1 1 calc(50% - .75rem); min-width:0;}
    }

    .collapsible-content {
      overflow: hidden;
      height: 0;
      opacity: 0;
      transition: height 220ms ease, opacity 220ms ease;
      will-change: height, opacity;
    }
    .collapsible-content.is-open {
      opacity: 1;
    }
    .collapsible-trigger .collapsible-chevron {
      transition: transform 220ms ease;
      will-change: transform;
    }
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron {
      transform: rotate(90deg);
    }
    @media (prefers-reduced-motion: reduce) {
      .collapsible-content,
      .collapsible-trigger .collapsible-chevron {
        transition: none !important;
      }
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php includeIsolated('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php includeIsolated('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main bg-surface">
      <div class="w-full h-screen p-6">
        <div class="mb-6">
          <a class="text-muted-foreground hover:text-foreground" href="/dashboard">← Retour dashboard</a>
          <h1 class="text-2xl font-bold mt-3">
            Aplication <span class="mono"><?= htmlspecialchars($deploymentName, ENT_QUOTES, 'UTF-8') ?></span>
          </h1>
          <p class="text-muted-foreground">
            Namespace: <span class="mono"><?= htmlspecialchars($userNamespace, ENT_QUOTES, 'UTF-8') ?></span>
          </p>
        </div>

        <?php if ($k8sError !== null): ?>
          <div class="bg-background rounded-xl border p-6 text-red-600">
            <strong>Erreur Kubernetes:</strong>
            <div class="mt-2 mono text-sm"><?= htmlspecialchars($k8sError, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        <?php else: ?>

          <div class="grid">
            <div class="status-hero">
              <div class="status-hero__content">
                <div>
                  <span class="status-badge status-badge--<?= htmlspecialchars($statusTone, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="status-badge__dot" aria-hidden="true"></span>
                    <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                  </span>

                  <h2 class="status-hero__title">Déploiement <span class="mono"><?= htmlspecialchars($deploymentName, ENT_QUOTES, 'UTF-8') ?></span></h2>
                  <p class="status-hero__subtitle">
                    Namespace <span class="mono"><?= htmlspecialchars($userNamespace, ENT_QUOTES, 'UTF-8') ?></span>
                    • <?= htmlspecialchars($statusDetails, ENT_QUOTES, 'UTF-8') ?>
                  </p>
                </div>

                <div class="status-hero__separator" aria-hidden="true"></div>

                <div class="status-hero__footer">
                  <div class="status-stats">
                    <div class="status-stat">
                      <span class="status-stat__label">Replicas</span>
                      <span class="status-stat__value mono"><?= $replicas ?></span>
                    </div>
                    <div class="status-stat">
                      <span class="status-stat__label">Ready</span>
                      <span class="status-stat__value mono"><?= $ready ?></span>
                    </div>
                    <div class="status-stat">
                      <span class="status-stat__label">Updated</span>
                      <span class="status-stat__value mono"><?= $updated ?></span>
                    </div>
                    <div class="status-stat">
                      <span class="status-stat__label">Available</span>
                      <span class="status-stat__value mono"><?= $available ?></span>
                    </div>
                  </div>

                  <div class="status-actions">
                    <button id="restartBtn" class="status-restart-btn">
                      Redémarrer l'application
                    </button>
                    <div id="restartMsg" class="status-restart-msg"></div>
                  </div>
                </div>
              </div>
            </div>

            <div class="bg-background rounded-xl border p-6">
              <h2 class="text-lg font-semibold mb-3">Détails</h2>
              <div class="text-sm space-y-2">
                <div>Strategy: <span class="mono"><?= htmlspecialchars((string)($deploymentData['spec']['strategy']['type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></div>
                <div>Selector: <span class="mono"><?= htmlspecialchars((string)json_encode($deploymentData['spec']['selector']['matchLabels'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></span></div>
                <div>Created: <span class="mono"><?= htmlspecialchars((string)($deploymentData['metadata']['creationTimestamp'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></div>
                <div>Replicas: <span class="mono"><?= $replicas ?></span></div>
                <div>Ready: <span class="mono"><?= $ready ?></span></div>
                <div>Updated: <span class="mono"><?= $updated ?></span></div>
                <div>Available: <span class="mono"><?= $available ?></span></div>
              </div>
            </div>
          </div>

          <div class="bg-background rounded-xl border p-6 mt-6" id="urlsCard">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <h2 class="text-lg font-semibold">URLs publiques</h2>
              <a class="text-sm text-muted-foreground hover:text-foreground" href="/network?deployment=<?= urlencode($deploymentName) ?>">Gérer dans Network →</a>
            </div>
            <p class="text-sm text-muted-foreground mt-2">
              Les URLs exposées via Ingress pour ce déploiement (si un Service le pointe).
            </p>
            <div id="publicUrls" class="mt-4 space-y-2 text-sm">
              <div class="text-muted-foreground">Chargement…</div>
            </div>
          </div>

          <div class="bg-background rounded-xl border p-6 mt-6" id="imageCard">
            <h2 class="text-lg font-semibold mb-3">Image</h2>
            <p class="text-sm text-muted-foreground mb-4">
              Choisis la version du tag (ex: <span class="mono">8.1-apache</span> → <span class="mono">8.3-apache</span>). On garde le même repository, on change juste le tag.
            </p>
            <div id="imageTools" class="space-y-3">
              <div class="text-muted-foreground text-sm">Chargement…</div>
            </div>
          </div>

        <?php endif; ?>
      </div>
    </main>
  </div>

  <script>
    const DEPLOYMENT_NAME = <?= json_encode($deploymentName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>

  <script>
    (function(){
      const btn = document.getElementById('restartBtn');
      const msg = document.getElementById('restartMsg');
      if(!btn) return;

      btn.addEventListener('click', async () => {
        btn.disabled = true;
        msg.textContent = 'Redémarrage en cours…';

        try {
          const body = new URLSearchParams({ name: DEPLOYMENT_NAME });
          const apiUrl = new URL('../k8s/k8s_api.php', window.location.origin);
          apiUrl.searchParams.set('action', 'restart_deployment');

          const res = await fetch(apiUrl.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
              'X-CSRF-Token': CSRF_TOKEN,
            },
            body,
          });

          const ct = (res.headers.get('content-type') || '').toLowerCase();
          const raw = await res.text();
          let data = null;
          try { data = JSON.parse(raw); } catch (_) {}

          if (!ct.includes('application/json') || !data) {
            throw new Error(`Réponse non-JSON (${res.status}). URL: ${apiUrl.pathname}. ` + raw.slice(0, 200).replace(/\s+/g, ' '));
          }

          if (!res.ok || !data.ok) {
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
          u.searchParams.set('action', 'list_public_urls');
          u.searchParams.set('deployment', DEPLOYMENT_NAME);

          const res = await fetch(u.toString(), { credentials: 'same-origin' });
          const ct = (res.headers.get('content-type') || '').toLowerCase();
          const raw = await res.text();
          let data = null;
          try { data = JSON.parse(raw); } catch (_) {}

          if (!ct.includes('application/json') || !data) {
            throw new Error(`Réponse non-JSON (${res.status}). URL: ${u.pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
          }

          if (!res.ok || !data.ok) {
            throw new Error(data.error || ('HTTP ' + res.status));
          }

          const entries = Array.isArray(data.entries) ? data.entries : [];
          host.innerHTML = '';

          if (entries.length === 0) {
            host.innerHTML = '<div class="text-muted-foreground">Aucune URL publique trouvée pour ce déploiement.</div>';
            return;
          }

          for (const e of entries) {
            const url = e.url || ((e.scheme || 'http') + '://' + e.host + (e.path || '/'));
            let cert = '';

            if (e.cert && e.cert.status) {
              if (e.cert.status === 'valid') {
                const d = (e.cert.daysRemaining != null) ? ` (${e.cert.daysRemaining}j)` : '';
                cert = badge('TLS OK' + d, 'ok');
              } else if (e.cert.status === 'expired') {
                cert = badge('TLS expiré', 'err');
              } else if (e.cert.status === 'none') {
                cert = badge('Sans TLS', 'muted');
              } else {
                cert = badge('TLS ?', 'warn');
              }
            }

            const row = document.createElement('div');
            row.className = 'flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2';
            row.innerHTML = `
              <div class="min-w-0">
                <a class="font-medium hover:underline break-all" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(url)}</a>
                <div class="text-xs text-muted-foreground mt-1">
                  Ingress: <span class="mono">${escapeHtml(e.ingressName || '')}</span>
                  • Service: <span class="mono">${escapeHtml(e.service || '')}</span>
                </div>
              </div>
              <div class="flex items-center gap-2">
                ${cert}
                <button class="h-8 rounded-md border px-3 text-xs hover:bg-secondary transition-colors" data-copy>Copier</button>
              </div>
            `;

            row.querySelector('[data-copy]').addEventListener('click', async () => {
              try {
                await navigator.clipboard.writeText(url);
              } catch (_) {
                const ta = document.createElement('textarea');
                ta.value = url;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
              }
            });

            host.appendChild(row);
          }
        } catch (e) {
          host.innerHTML = `<div class="text-red-600"><strong>Erreur:</strong> ${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
        }
      })();
    })();
  </script>

  <script>
    (function(){
      const host = document.getElementById('imageTools');
      if(!host) return;

      const apiUrl = new URL('../k8s/k8s_api.php', window.location.origin);
      apiUrl.searchParams.set('action', 'list_deployment_images');
      apiUrl.searchParams.set('name', DEPLOYMENT_NAME);

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
              <div class="text-xs text-muted-foreground mono">Actuel: ${escapeHtml(current)}</div>
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

        sel.innerHTML = '';
        const tags = Array.isArray(c.availableTags) ? c.availableTags : [];

        if (tags.length === 0) {
          sel.innerHTML = '<option value="">(Aucune version disponible)</option>';
          sel.disabled = true;
          btn.disabled = true;

          if (c.note) setMsg(info, c.note, 'warn');
          else setMsg(info, 'Pas de liste de versions pour cette image.', 'warn');
        } else {
          for (const t of tags) {
            const opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            if (c.currentTag && t === c.currentTag) opt.selected = true;
            sel.appendChild(opt);
          }

          if (c.note) {
            setMsg(info, c.note, 'warn');
          } else if (c.hasUpdate && latest && c.currentTag && latest !== c.currentTag) {
            setMsg(info, `Nouvelle version disponible: ${latest}`, 'ok');
          } else {
            setMsg(info, 'À jour.', 'muted');
          }
        }

        const postUpdate = async () => {
          const tag = sel.value;
          if (!tag) {
            setMsg(status, 'Choisis un tag.', 'warn');
            return;
          }

          btn.disabled = true;
          sel.disabled = true;
          setMsg(status, 'Mise à jour en cours…', 'muted');

          try {
            const body = new URLSearchParams({
              name: DEPLOYMENT_NAME,
              container: c.name,
              tag
            });

            const u = new URL('../k8s/k8s_api.php', window.location.origin);
            u.searchParams.set('action', 'set_deployment_image_tag');

            const res = await fetch(u.toString(), {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': CSRF_TOKEN,
              },
              body,
            });

            const ct = (res.headers.get('content-type') || '').toLowerCase();
            const raw = await res.text();
            let data = null;
            try { data = JSON.parse(raw); } catch (_) {}

            if (!ct.includes('application/json') || !data) {
              throw new Error(`Réponse non-JSON (${res.status}). URL: ${u.pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
            }

            if (!res.ok || !data.ok) {
              throw new Error(data.error || ('HTTP ' + res.status));
            }

            if (data.newImage) imgEl.textContent = data.newImage;
            c.currentTag = tag;
            c.currentImage = data.newImage || c.currentImage;

            if (c.latestTag && c.latestTag !== tag) {
              setMsg(info, `Nouvelle version disponible: ${c.latestTag}`, 'ok');
            } else {
              setMsg(info, 'À jour.', 'muted');
            }

            setMsg(status, 'Ok. Image mise à jour. Kubernetes va lancer un rollout.', 'ok');
          } catch (e) {
            setMsg(status, 'Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
          } finally {
            btn.disabled = false;
            sel.disabled = false;
          }
        };

        btn.addEventListener('click', postUpdate);
        return wrap;
      };

      (async () => {
        try {
          const res = await fetch(apiUrl.toString(), { credentials: 'same-origin' });
          const ct = (res.headers.get('content-type') || '').toLowerCase();
          const raw = await res.text();
          let data = null;
          try { data = JSON.parse(raw); } catch (_) {}

          if (!ct.includes('application/json') || !data) {
            throw new Error(`Réponse non-JSON (${res.status}). URL: ${apiUrl.pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
          }

          if (!res.ok || !data.ok) {
            throw new Error(data.error || ('HTTP ' + res.status));
          }

          const containers = Array.isArray(data.containers) ? data.containers : [];
          host.innerHTML = '';

          if (containers.length === 0) {
            host.innerHTML = '<div class="text-sm text-muted-foreground">Aucun container trouvé dans ce deployment.</div>';
            return;
          }

          for (const c of containers) {
            host.appendChild(buildRow(c));
          }
        } catch (e) {
          host.innerHTML = `<div class="text-sm text-red-600"><strong>Erreur:</strong> ${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
        }
      })();
    })();
  </script>

  <script>
    (function () {
      function ready(fn){
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
      }

      ready(function () {
        var triggers = document.querySelectorAll('[data-slot="collapsible-trigger"]');
        triggers.forEach(function (btn) {
          btn.classList.add('collapsible-trigger');

          var targetId = btn.getAttribute('aria-controls');
          var content = targetId ? document.getElementById(targetId) : null;

          if (!content) {
            var parent = btn.closest('[data-slot="collapsible"]');
            if (parent) content = parent.querySelector('[data-slot="collapsible-content"]');
          }
          if (!content) return;

          content.classList.add('collapsible-content');

          var chev = btn.querySelector('.lucide-chevron-right');
          if (chev) chev.classList.add('collapsible-chevron');

          var expanded = btn.getAttribute('aria-expanded') === 'true';
          if (expanded) {
            content.hidden = false;
            content.classList.add('is-open');
            content.style.height = 'auto';
          } else {
            content.hidden = true;
            content.classList.remove('is-open');
            content.style.height = '0px';
          }

          btn.addEventListener('click', function (e) {
            e.preventDefault();

            var isOpen = btn.getAttribute('aria-expanded') === 'true';

            if (!isOpen) {
              btn.setAttribute('aria-expanded', 'true');
              btn.setAttribute('data-state', 'open');
              content.hidden = false;
              content.classList.add('is-open');
              content.setAttribute('data-state', 'open');

              content.style.height = '0px';
              var h = content.scrollHeight;
              requestAnimationFrame(function () {
                content.style.height = h + 'px';
              });

              var onEnd = function (ev) {
                if (ev.propertyName !== 'height') return;
                content.style.height = 'auto';
                content.removeEventListener('transitionend', onEnd);
              };
              content.addEventListener('transitionend', onEnd);

            } else {
              btn.setAttribute('aria-expanded', 'false');
              btn.setAttribute('data-state', 'closed');
              content.classList.remove('is-open');
              content.setAttribute('data-state', 'closed');

              var current = content.scrollHeight;
              content.style.height = current + 'px';
              requestAnimationFrame(function () {
                content.style.height = '0px';
              });

              var onEndClose = function (ev) {
                if (ev.propertyName !== 'height') return;
                content.hidden = true;
                content.removeEventListener('transitionend', onEndClose);
              };
              content.addEventListener('transitionend', onEndClose);
            }
          }, { passive: false });
        });
      });
    })();
  </script>

  <script>
    window.K8S_API_URL = "../k8s/k8s_api.php";
    window.K8S_UI_BASE = "./";
  </script>
  <script src="../k8s/k8s-menu.js" defer></script>

</body>
</html>
