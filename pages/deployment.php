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

$deploymentParam = $_GET['deployment'] ?? $_GET['name'] ?? '';
$deploymentName = is_string($deploymentParam) ? $deploymentParam : '';

if (isset($_GET['name']) && !isset($_GET['deployment']) && $deploymentName !== '') {
    $canonicalQuery = $_GET;
    unset($canonicalQuery['name']);
    $canonicalQuery['deployment'] = $deploymentName;
    header('Location: /deployment?' . http_build_query($canonicalQuery, '', '&', PHP_QUERY_RFC3986), true, 302);
    exit;
}

if (
    $deploymentName === ''
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

$deploymentStatusLabel = 'État indisponible';
$deploymentStatusIconColor = '#ef4444';

if ($k8sError === null) {
    if ($replicas > 0 && $ready >= $replicas && $available >= $replicas) {
        $deploymentStatusLabel = 'Déploiement opérationnel';
        $deploymentStatusIconColor = '#22c55e';
    } elseif ($ready > 0 || $updated > 0 || $available > 0) {
        $deploymentStatusLabel = 'Déploiement en cours';
        $deploymentStatusIconColor = '#3b82f6';
    } else {
        $deploymentStatusLabel = 'Service non démarré';
        $deploymentStatusIconColor = '#f59e0b';
    }
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
      min-height:calc(100vh - var(--app-header-height, 0px));
      min-height:calc(100dvh - var(--app-header-height, 0px));
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
    .widget-hero-icon{width:.75rem;height:.75rem;flex:0 0 .75rem;display:block;}
    .widget-back-icon{width:1rem;height:1rem;flex:0 0 1rem;display:block;}

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
      <div class="app-shell-offset-min-height w-full p-6">
        <?php if ($k8sError !== null): ?>
          <div class="bg-background rounded-xl border p-6 text-red-600">
            <strong>Erreur Kubernetes:</strong>
            <div class="mt-2 mono text-sm"><?= htmlspecialchars($k8sError, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        <?php else: ?>

          <div class="w-full bg-surface mb-6">
            <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-6 rounded-xl group relative overflow-hidden border-0 shadow-lg transition-shadow hover:shadow-xl">
              <div class="absolute inset-0">
                <img
                  src="https://images.unsplash.com/photo-1494984858525-798dd0b282f5?ixlib=rb-4.1.0&amp;ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&amp;auto=format&amp;fit=crop&amp;q=80&amp;w=2070"
                  alt="Event background"
                  class="h-full w-full object-cover"
                />
                <div class="absolute inset-0 bg-gradient-to-r from-black/80 via-black/60 to-black/40 dark:from-black/90 dark:via-black/70 dark:to-black/50"></div>
              </div>

              <div data-slot="card-content" class="relative z-10 space-y-6 p-8 md:p-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                  <div class="space-y-3">
                    <h1 class="text-3xl font-bold text-white md:text-xl lg:text-2xl">
                      Service <span class="mono"><?= htmlspecialchars($deploymentName, ENT_QUOTES, 'UTF-8') ?></span>
                    </h1>
                    <p class="max-w-2xl text-base text-muted-foreground md:text-sm">
                      Namespace: <span class="mono"><?= htmlspecialchars($userNamespace, ENT_QUOTES, 'UTF-8') ?></span>
                    </p>
                  </div>

                  <div class="flex md:justify-end md:pt-1">
                    <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 gap-1 overflow-hidden border-transparent bg-white/20 text-white backdrop-blur-sm hover:bg-white/30">
                      <svg class="widget-hero-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M7.493 0.015C7.442 0.021 7.268 0.039 7.107 0.055C5.234 0.242 3.347 1.208 2.071 2.634C0.66 4.211 -0.057 6.168 0.009 8.253C0.124 11.854 2.599 14.903 6.11 15.771C8.169 16.28 10.433 15.917 12.227 14.791C14.017 13.666 15.27 11.933 15.771 9.887C15.943 9.186 15.983 8.829 15.983 8C15.983 7.171 15.943 6.814 15.771 6.113C14.979 2.878 12.315 0.498 9 0.064C8.716 0.027 7.683 -0.006 7.493 0.015ZM8.853 1.563C9.967 1.707 11.01 2.136 11.944 2.834C12.273 3.08 12.92 3.727 13.166 4.056C13.727 4.807 14.142 5.69 14.33 6.535C14.544 7.5 14.544 8.5 14.33 9.465C13.916 11.326 12.605 12.978 10.867 13.828C10.239 14.135 9.591 14.336 8.88 14.444C8.456 14.509 7.544 14.509 7.12 14.444C5.172 14.148 3.528 13.085 2.493 11.451C2.279 11.114 1.999 10.526 1.859 10.119C1.618 9.422 1.514 8.781 1.514 8C1.514 6.961 1.715 6.075 2.16 5.16C2.5 4.462 2.846 3.98 3.413 3.413C3.98 2.846 4.462 2.5 5.16 2.16C6.313 1.599 7.567 1.397 8.853 1.563ZM7.706 4.29C7.482 4.363 7.355 4.491 7.293 4.705C7.257 4.827 7.253 5.106 7.259 6.816C7.267 8.786 7.267 8.787 7.325 8.896C7.398 9.033 7.538 9.157 7.671 9.204C7.803 9.25 8.197 9.25 8.329 9.204C8.462 9.157 8.602 9.033 8.675 8.896C8.733 8.787 8.733 8.786 8.741 6.816C8.749 4.664 8.749 4.662 8.596 4.481C8.472 4.333 8.339 4.284 8.04 4.276C7.893 4.272 7.743 4.278 7.706 4.29ZM7.786 10.53C7.597 10.592 7.41 10.753 7.319 10.932C7.249 11.072 7.237 11.325 7.294 11.495C7.388 11.78 7.697 12 8 12C8.303 12 8.612 11.78 8.706 11.495C8.763 11.325 8.751 11.072 8.681 10.932C8.616 10.804 8.46 10.646 8.333 10.58C8.217 10.52 7.904 10.491 7.786 10.53Z" fill="<?= htmlspecialchars($deploymentStatusIconColor, ENT_QUOTES, 'UTF-8') ?>"/>
                      </svg>
                      <?= htmlspecialchars($deploymentStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </div>
                </div>

                <div data-slot="separator" data-orientation="horizontal" role="none" class="shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px bg-white/20"></div>

                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                  <a href="/dashboard" class="flex items-center gap-2 text-sm text-muted-foreground hover:text-white transition-colors">
                    <svg class="widget-back-icon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                      <path d="M595.9 757L350.6 511.7l245.3-245.3 51.7 51.7L454 511.7l193.6 193.5z" fill="#ffffff"/>
                    </svg>
                    <span>Retour dashboard</span>
                  </a>

                  <div class="">
                    <button data-slot="button" id="restartBtn" class="h-9 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">
                      Redémarrer l'application
                    </button>
                    <div id="restartMsg" class="text-xs text-white/80"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div id="restartPopup" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4" role="dialog" aria-modal="true" aria-labelledby="restartPopupTitle" aria-describedby="restartPopupText">
            <div class="w-full max-w-md rounded-xl border bg-card text-card-foreground shadow-lg">
              <div class="p-6">
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <h2 id="restartPopupTitle" class="text-lg font-semibold">Redémarrage</h2>
                    <p id="restartPopupText" class="mt-2 text-sm text-muted-foreground">Le service redémarre.</p>
                  </div>
                  <button type="button" id="restartPopupClose" class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary" aria-label="Fermer">Fermer</button>
                </div>
              </div>
            </div>
          </div>

          <div class="" id="imageCard">
            <div id="imageTools" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              <div class="text-muted-foreground text-sm">Chargement…</div>
            </div>
          </div>

          <div class="" id="urlsCard">
            <div id="publicUrls" class="mt-4 flex flex-wrap gap-3 text-sm">
              <div class="text-muted-foreground">Chargement…</div>
            </div>
          </div>

          <div class="bg-background rounded-xl border p-6 mt-6" id="secretCard">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
              <h2 class="text-lg font-semibold">Variables secrètes</h2>
              <button type="button" id="secretCreateToggle" class="h-9 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Nouvelle variable</button>
            </div>
            <p class="text-sm text-muted-foreground mb-4">
              Les noms des variables sont visibles, mais leurs valeurs restent masquées. Renseigne une nouvelle valeur pour mettre à jour le secret Kubernetes associé.
            </p>
            <div id="secretCreatePanel" class="mb-4 hidden rounded-lg border p-4">
              <div class="grid gap-3 md:grid-cols-2">
                <label class="text-sm">
                  <span class="mb-1 block text-xs text-muted-foreground">Container</span>
                  <select id="secretCreateContainer" class="h-10 w-full rounded-md border bg-background px-3 text-sm"></select>
                </label>
                <label class="text-sm">
                  <span class="mb-1 block text-xs text-muted-foreground">Variable / clé du secret</span>
                  <input id="secretCreateEnv" type="text" class="h-10 w-full rounded-md border bg-background px-3 text-sm" placeholder="ex: API_TOKEN" />
                </label>
                <label class="text-sm">
                  <span class="mb-1 block text-xs text-muted-foreground">Secret</span>
                  <select id="secretCreateSecret" class="h-10 w-full rounded-md border bg-background px-3 text-sm"></select>
                </label>
                <label class="text-sm md:col-span-2">
                  <span class="mb-1 block text-xs text-muted-foreground">Valeur initiale masquée (optionnel)</span>
                  <input id="secretCreateValue" type="password" class="h-10 w-full rounded-md border bg-background px-3 text-sm" placeholder="Laisser vide pour créer une valeur vide" autocomplete="new-password" />
                </label>
              </div>
              <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div id="secretCreateStatus" class="text-xs text-muted-foreground"></div>
                <button type="button" id="secretCreateSubmit" class="h-10 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Créer la variable</button>
              </div>
            </div>
            <div id="secretTools" class="space-y-3">
              <div class="text-muted-foreground text-sm">Chargement…</div>
            </div>
          </div>

          <div class="bg-background rounded-xl border p-6 mt-6" id="stockCard">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <h2 class="text-lg font-semibold">Logs</h2>
              <a class="text-sm text-muted-foreground hover:text-foreground" href="/log?deployment=<?= urlencode($deploymentName) ?>">Acceder aux Logs →</a>
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
      const popup = document.getElementById('restartPopup');
      const popupTitle = document.getElementById('restartPopupTitle');
      const popupText = document.getElementById('restartPopupText');
      const popupClose = document.getElementById('restartPopupClose');
      if(!btn) return;

      const openPopup = (title, text) => {
        if (!popup) return;
        if (popupTitle) popupTitle.textContent = title;
        if (popupText) popupText.textContent = text;
        popup.classList.remove('hidden');
        popup.classList.add('flex');
      };

      const closePopup = () => {
        if (!popup) return;
        popup.classList.remove('flex');
        popup.classList.add('hidden');
      };

      popupClose?.addEventListener('click', closePopup);
      popup?.addEventListener('click', (event) => {
        if (event.target === popup) {
          closePopup();
        }
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closePopup();
        }
      });

      btn.addEventListener('click', async () => {
        btn.disabled = true;
        msg.textContent = '';

        try {
          const body = new URLSearchParams({ name: DEPLOYMENT_NAME });
          const apiUrl = new URL('../k8s/k8s_api.php', window.location.href);
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

          openPopup('Redémarrage', 'Le service redémarre.');
        } catch (e) {
          closePopup();
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
          const u = new URL('../k8s/k8s_api.php', window.location.href);
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
            row.className = 'bg-background flex min-w-[320px] flex-1 flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2';
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
              </div>
            `;

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
      const host = document.getElementById('secretTools');
      const createToggle = document.getElementById('secretCreateToggle');
      const createPanel = document.getElementById('secretCreatePanel');
      const createContainer = document.getElementById('secretCreateContainer');
      const createEnv = document.getElementById('secretCreateEnv');
      const createSecret = document.getElementById('secretCreateSecret');
      const createValue = document.getElementById('secretCreateValue');
      const createStatus = document.getElementById('secretCreateStatus');
      const createSubmit = document.getElementById('secretCreateSubmit');
      if(!host) return;

      const apiUrl = new URL('../k8s/k8s_api.php', window.location.href);
      apiUrl.searchParams.set('action', 'list_deployment_secret_variables');
      apiUrl.searchParams.set('deployment', DEPLOYMENT_NAME);

      const escapeHtml = (s) => String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');

      const setMsg = (el, text, kind='muted') => {
        if (!el) return;
        el.className = 'text-xs ' + (kind === 'ok'
          ? 'text-emerald-600'
          : kind === 'warn'
            ? 'text-amber-600'
            : kind === 'err'
              ? 'text-red-600'
              : 'text-muted-foreground');
        el.textContent = text;
      };

      const readJson = async (res, url) => {
        const ct = (res.headers.get('content-type') || '').toLowerCase();
        const raw = await res.text();
        let data = null;
        try { data = JSON.parse(raw); } catch (_) {}

        if (!ct.includes('application/json') || !data) {
          throw new Error(`Réponse non-JSON (${res.status}). URL: ${url.pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
        }

        if (!res.ok || !data.ok) {
          throw new Error(data.error || ('HTTP ' + res.status));
        }

        return data;
      };

      const setCreatePanelOpen = (open) => {
        if (!createPanel) return;
        createPanel.classList.toggle('hidden', !open);
        if (createToggle) {
          createToggle.textContent = open ? 'Fermer' : 'Nouvelle variable';
        }
      };

      const populateSecretOptions = (secrets) => {
        if (!createSecret) return;
        createSecret.innerHTML = '';

        if (!Array.isArray(secrets) || secrets.length === 0) {
          const option = document.createElement('option');
          option.value = '';
          option.textContent = 'Aucun secret disponible';
          createSecret.appendChild(option);
          createSecret.disabled = true;
          if (createSubmit) createSubmit.disabled = true;
          return;
        }

        createSecret.disabled = false;
        if (createSubmit) createSubmit.disabled = false;

        for (const name of secrets) {
          const option = document.createElement('option');
          option.value = name;
          option.textContent = name;
          createSecret.appendChild(option);
        }
      };

      const populateContainerOptions = (containers) => {
        if (!createContainer) return;
        createContainer.innerHTML = '';

        if (!Array.isArray(containers) || containers.length === 0) {
          const option = document.createElement('option');
          option.value = '';
          option.textContent = 'Aucun container disponible';
          createContainer.appendChild(option);
          createContainer.disabled = true;
          if (createSubmit) createSubmit.disabled = true;
          return;
        }

        createContainer.disabled = false;
        if (createSubmit) createSubmit.disabled = false;

        for (const name of containers) {
          const option = document.createElement('option');
          option.value = name;
          option.textContent = name;
          createContainer.appendChild(option);
        }
      };

      const buildRow = (entry) => {
        const id = 'secret_' + [entry.container, entry.envName, entry.secretName, entry.secretKey]
          .join('_')
          .replace(/[^a-z0-9_-]/gi,'_');

        const wrap = document.createElement('div');
        wrap.className = 'rounded-lg border p-4';
        wrap.innerHTML = `
          <div class="flex flex-col gap-3">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
              <div class="min-w-0">
                <div class="text-sm font-medium">Variable: <span class="mono">${escapeHtml(entry.envName || '')}</span></div>
                <div class="text-xs text-muted-foreground mt-1">
                  Container: <span class="mono">${escapeHtml(entry.container || '')}</span>
                  • Secret: <span class="mono">${escapeHtml(entry.secretName || '')}</span>
                  • Clé: <span class="mono">${escapeHtml(entry.secretKey || '')}</span>
                </div>
              </div>
              <div class="w-full lg:w-auto lg:min-w-[24rem]">
                <label class="sr-only" for="${id}_value">Nouvelle valeur pour ${escapeHtml(entry.envName || '')}</label>
                <div class="flex flex-col gap-2 sm:flex-row">
                  <input
                    id="${id}_value"
                    type="password"
                    class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                    placeholder="Valeur actuelle masquée — saisir une nouvelle valeur"
                    autocomplete="new-password"
                  />
                  <button id="${id}_btn" class="h-10 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Enregistrer</button>
                </div>
              </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="text-xs text-muted-foreground">
                ${entry.source === 'secretRef' ? "Variable issue d'un import de secret." : "Variable liée directement à une clé de secret."}
              </div>
              <div id="${id}_status" class="text-xs text-muted-foreground"></div>
            </div>
          </div>
        `;

        const input = wrap.querySelector('#' + id + '_value');
        const button = wrap.querySelector('#' + id + '_btn');
        const status = wrap.querySelector('#' + id + '_status');

        const submit = async () => {
          const value = input.value;
          if (value === '') {
            setMsg(status, "Saisis une nouvelle valeur avant d'enregistrer.", 'warn');
            return;
          }

          button.disabled = true;
          input.disabled = true;
          setMsg(status, 'Mise à jour du secret…', 'muted');

          try {
            const body = new URLSearchParams({
              name: DEPLOYMENT_NAME,
              container: entry.container || '',
              env: entry.envName || '',
              secret: entry.secretName || '',
              key: entry.secretKey || '',
              value,
            });

            const u = new URL('../k8s/k8s_api.php', window.location.href);
            u.searchParams.set('action', 'update_deployment_secret_variable');

            const res = await fetch(u.toString(), {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': CSRF_TOKEN,
              },
              body,
            });

            await readJson(res, u);
            input.value = '';
            setMsg(status, 'Valeur enregistrée. La valeur existante reste masquée dans le portail.', 'ok');
          } catch (e) {
            setMsg(status, 'Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
          } finally {
            button.disabled = false;
            input.disabled = false;
          }
        };

        button.addEventListener('click', submit);
        input.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            submit();
          }
        });

        return wrap;
      };

      const renderList = (entries, secretErrors) => {
        host.innerHTML = '';

        if (!Array.isArray(entries) || entries.length === 0) {
          host.innerHTML = '<div class="text-sm text-muted-foreground">Aucune variable de secret détectée pour ce deployment.</div>';
        } else {
          for (const entry of entries) {
            host.appendChild(buildRow(entry));
          }
        }

        const errors = secretErrors && typeof secretErrors === 'object' ? Object.entries(secretErrors) : [];
        if (errors.length > 0) {
          const alert = document.createElement('div');
          alert.className = 'rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800';
          alert.innerHTML = `
            <div class="font-medium">Certains secrets n'ont pas pu être inspectés.</div>
            <ul class="mt-2 list-disc pl-5">
              ${errors.map(([name, error]) => `<li><span class="mono">${escapeHtml(name)}</span>: ${escapeHtml(error)}</li>`).join('')}
            </ul>
          `;
          host.appendChild(alert);
        }
      };

      const loadSecretVariables = async () => {
        const res = await fetch(apiUrl.toString(), { credentials: 'same-origin' });
        const data = await readJson(res, apiUrl);
        const containers = Array.isArray(data.containers) ? data.containers : [];
        const secrets = Array.isArray(data.secrets) ? data.secrets : [];
        const entries = Array.isArray(data.entries) ? data.entries : [];
        populateContainerOptions(containers);
        populateSecretOptions(secrets);
        renderList(entries, data.secretErrors);
      };

      const resetCreateForm = () => {
        if (createEnv) createEnv.value = '';
        if (createSecret) createSecret.value = '';
        if (createValue) createValue.value = '';
      };

      const createVariable = async () => {
        const payload = {
          name: DEPLOYMENT_NAME,
          container: createContainer ? createContainer.value.trim() : '',
          env: createEnv ? createEnv.value.trim() : '',
          secret: createSecret ? createSecret.value.trim() : '',
          value: createValue ? createValue.value : '',
        };

        if (!payload.container || !payload.env || !payload.secret) {
          setMsg(createStatus, 'Renseigne le container, la variable / clé et le secret.', 'warn');
          return;
        }

        if (createSubmit) createSubmit.disabled = true;
        setMsg(createStatus, 'Création de la variable…', 'muted');

        try {
          const u = new URL('../k8s/k8s_api.php', window.location.href);
          u.searchParams.set('action', 'create_deployment_secret_variable');

          const res = await fetch(u.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
              'X-CSRF-Token': CSRF_TOKEN,
            },
            body: new URLSearchParams(payload),
          });

          const data = await readJson(res, u);
          resetCreateForm();
          setMsg(createStatus, 'Variable créée. La valeur reste masquée dans le portail.', 'ok');
          await loadSecretVariables();
        } catch (e) {
          setMsg(createStatus, 'Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
        } finally {
          if (createSubmit) createSubmit.disabled = false;
        }
      };

      createToggle?.addEventListener('click', () => {
        const open = createPanel ? createPanel.classList.contains('hidden') : false;
        setCreatePanelOpen(open);
      });

      createSubmit?.addEventListener('click', createVariable);

      (async () => {
        try {
          await loadSecretVariables();
        } catch (e) {
          host.innerHTML = `<div class="text-sm text-red-600"><strong>Erreur:</strong> ${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
          populateContainerOptions([]);
          populateSecretOptions([]);
        }
      })();
    })();
  </script>

  <script>
    (function(){
      const host = document.getElementById('imageTools');
      if(!host) return;

      const apiUrl = new URL('../k8s/k8s_api.php', window.location.href);
      apiUrl.searchParams.set('action', 'list_deployment_images');
      apiUrl.searchParams.set('deployment', DEPLOYMENT_NAME);

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
        wrap.className = 'bg-background rounded-lg border p-4 h-full';

        wrap.innerHTML = `
          <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0 flex-1">
              <div class="text-sm font-medium">Version Updater: <span class="mono">${escapeHtml(c.name)}</span></div>
              <div class="text-xs text-muted-foreground mono break-all mt-1" id="${id}_img">${escapeHtml(c.currentImage)}</div>
              <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                <div class="text-xs text-muted-foreground mono" id="${id}_current">Actuel: ${escapeHtml(current)}</div>
                <div id="${id}_info" class="text-xs text-muted-foreground"></div>
              </div>
              <div id="${id}_status" class="mt-2 text-xs text-muted-foreground"></div>
            </div>
            <div class="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:flex-nowrap lg:justify-end">
              <select id="${id}_sel" class="h-9 min-w-[12rem] flex-1 rounded-md border bg-background px-3 text-sm lg:flex-none">
                <option value="">Chargement…</option>
              </select>
            </div>
          </div>
        `;

        const sel = wrap.querySelector('#' + id + '_sel');
        const currentEl = wrap.querySelector('#' + id + '_current');
        const info = wrap.querySelector('#' + id + '_info');
        const status = wrap.querySelector('#' + id + '_status');
        const imgEl = wrap.querySelector('#' + id + '_img');

        sel.innerHTML = '';
        const tags = Array.isArray(c.availableTags) ? c.availableTags : [];

        if (tags.length === 0) {
          sel.innerHTML = '<option value="">Indisponible</option>';
          sel.disabled = true;

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
          const previousTag = c.currentTag || '';
          if (!tag) {
            setMsg(status, 'Choisis un tag.', 'warn');
            return;
          }

          sel.disabled = true;
          setMsg(status, 'Mise à jour en cours…', 'muted');

          try {
            const body = new URLSearchParams({
              name: DEPLOYMENT_NAME,
              container: c.name,
              tag
            });

            const u = new URL('../k8s/k8s_api.php', window.location.href);
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
            if (currentEl) currentEl.textContent = `Actuel: ${tag}`;

            if (c.latestTag && c.latestTag !== tag) {
              setMsg(info, `Nouvelle version disponible: ${c.latestTag}`, 'ok');
            } else {
              setMsg(info, 'À jour.', 'muted');
            }

            setMsg(status, 'Ok. Image mise à jour automatiquement. Kubernetes va lancer un rollout.', 'ok');
          } catch (e) {
            sel.value = previousTag;
            setMsg(status, 'Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
          } finally {
            sel.disabled = false;
          }
        };

        sel.addEventListener('change', () => {
          if (!sel.value || sel.value === c.currentTag) {
            setMsg(status, sel.value === c.currentTag ? 'Cette version est déjà appliquée.' : 'Choisis un tag.', sel.value === c.currentTag ? 'muted' : 'warn');
            return;
          }
          void postUpdate();
        });
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
