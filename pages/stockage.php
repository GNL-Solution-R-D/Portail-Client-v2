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
 * écrase des variables de la page.
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

function isDnsLabel(string $value): bool
{
    return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $value);
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
if (!is_string($deploymentName) || $deploymentName === '' || !isDnsLabel($deploymentName)) {
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
$storageMounts = [];
$claims = [];

try {
    $k8s = new KubernetesClient();
    $deploymentData = $k8s->getDeployment($userNamespace, $deploymentName);

    $volumes = $deploymentData['spec']['template']['spec']['volumes'] ?? [];
    if (!is_array($volumes)) {
        $volumes = [];
    }

    $pvcByVolumeName = [];
    foreach ($volumes as $volume) {
        if (!is_array($volume)) {
            continue;
        }

        $volumeName = $volume['name'] ?? null;
        $claimName = $volume['persistentVolumeClaim']['claimName'] ?? null;

        if (!is_string($volumeName) || $volumeName === '' || !is_string($claimName) || $claimName === '') {
            continue;
        }

        $claims[$claimName] = true;
        $pvcByVolumeName[$volumeName] = [
            'volumeName' => $volumeName,
            'claimName' => $claimName,
            'readOnly' => (bool)($volume['persistentVolumeClaim']['readOnly'] ?? false),
        ];
    }

    $containers = $deploymentData['spec']['template']['spec']['containers'] ?? [];
    if (!is_array($containers)) {
        $containers = [];
    }

    foreach ($containers as $container) {
        if (!is_array($container)) {
            continue;
        }

        $containerName = $container['name'] ?? null;
        if (!is_string($containerName) || $containerName === '') {
            continue;
        }

        $volumeMounts = $container['volumeMounts'] ?? [];
        if (!is_array($volumeMounts)) {
            $volumeMounts = [];
        }

        foreach ($volumeMounts as $mount) {
            if (!is_array($mount)) {
                continue;
            }

            $volumeName = $mount['name'] ?? null;
            $mountPath = $mount['mountPath'] ?? null;
            if (!is_string($volumeName) || $volumeName === '' || !isset($pvcByVolumeName[$volumeName])) {
                continue;
            }
            if (!is_string($mountPath) || $mountPath === '') {
                continue;
            }

            $meta = $pvcByVolumeName[$volumeName];
            $storageMounts[] = [
                'container' => $containerName,
                'volumeName' => $meta['volumeName'],
                'claimName' => $meta['claimName'],
                'mountPath' => $mountPath,
                'subPath' => is_string($mount['subPath'] ?? null) ? $mount['subPath'] : null,
                'readOnly' => (bool)($mount['readOnly'] ?? false) || (bool)$meta['readOnly'],
            ];
        }
    }
} catch (Throwable $e) {
    $k8sError = $e->getMessage();
}

$claimsCount = count($claims);
$mountsCount = count($storageMounts);
$pageTitle = 'Stockage ' . $deploymentName;
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

    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}
    .storage-grid{display:grid;grid-template-columns:1fr;gap:16px;}
    @media(min-width:1200px){.storage-grid{grid-template-columns:minmax(320px, 420px) minmax(0,1fr);}}
    .explorer-table{width:100%;border-collapse:collapse;}
    .explorer-table th,.explorer-table td{padding:12px 10px;border-bottom:1px solid rgba(127,127,127,.16);vertical-align:middle;}
    .explorer-row{cursor:pointer;}
    .explorer-row:hover{background:rgba(127,127,127,.06);}
    .explorer-row.is-dir .file-name{font-weight:600;}
    .crumbs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
    .crumb-sep{opacity:.55;}
    .mount-card.is-active{border-color:rgba(59,130,246,.45);box-shadow:0 0 0 1px rgba(59,130,246,.18) inset;}
    .status-ok{color:#059669;}
    .status-warn{color:#d97706;}
    .status-err{color:#dc2626;}
    .status-info{color:#2563eb;}
    .file-icon{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:1.75rem;
      height:1.75rem;
      border-radius:.55rem;
      border:1px solid rgba(127,127,127,.16);
      font-size:.85rem;
      flex:0 0 auto;
    }
    .line-clamp-2{
      display:-webkit-box;
      -webkit-line-clamp:2;
      -webkit-box-orient:vertical;
      overflow:hidden;
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
        <div class="mb-6">
          <div class="flex flex-wrap items-center gap-3">
            <a class="text-muted-foreground hover:text-foreground" href="/deployment?name=<?= urlencode($deploymentName) ?>">← Retour au deployment</a>
            <span class="text-muted-foreground">•</span>
            <a class="text-muted-foreground hover:text-foreground" href="/dashboard">Dashboard</a>
          </div>
          <h1 class="text-2xl font-bold mt-3">
            Stockage <span class="mono"><?= htmlspecialchars($deploymentName, ENT_QUOTES, 'UTF-8') ?></span>
          </h1>
          <p class="text-muted-foreground mt-2">
            Namespace: <span class="mono"><?= htmlspecialchars($userNamespace, ENT_QUOTES, 'UTF-8') ?></span>
          </p>
        </div>

        <?php if ($k8sError !== null): ?>
          <div class="bg-background rounded-xl border p-6 text-red-600">
            <strong>Erreur Kubernetes:</strong>
            <div class="mt-2 mono text-sm"><?= htmlspecialchars($k8sError, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        <?php else: ?>

          <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
            <div class="bg-background rounded-xl border p-6">
              <h2 class="text-lg font-semibold mb-3">Résumé</h2>
              <div class="space-y-2 text-sm">
                <div>PVC distincts: <span class="mono"><?= $claimsCount ?></span></div>
                <div>Montages détectés: <span class="mono"><?= $mountsCount ?></span></div>
                <div>Replicas voulus: <span class="mono"><?= (int)($deploymentData['spec']['replicas'] ?? 0) ?></span></div>
                <div>Ready: <span class="mono"><?= (int)($deploymentData['status']['readyReplicas'] ?? 0) ?></span></div>
              </div>
            </div>

            <div class="bg-background rounded-xl border p-6 lg:col-span-2">
              <h2 class="text-lg font-semibold mb-3">Mode d’emploi</h2>
              <div class="text-sm text-muted-foreground space-y-2">
                <p>La page détecte les montages PVC présents dans le Deployment puis interroge un pod du service pour lister les fichiers.</p>
                <p>La navigation reste bornée au point de montage sélectionné pour éviter de sortir du volume exposé dans l’interface.</p>
                <p>Le ServiceAccount du dashboard doit aussi avoir accès au sous-ressource <span class="mono">pods/exec</span> avec le verbe <span class="mono">get</span>, sinon l’exploration renverra une erreur RBAC.</p>
              </div>
            </div>
          </div>

          <?php if ($mountsCount === 0): ?>
            <div class="bg-background rounded-xl border p-6">
              <h2 class="text-lg font-semibold mb-3">Aucun stockage détecté</h2>
              <p class="text-sm text-muted-foreground">
                Ce Deployment n’expose aucun volume de type <span class="mono">persistentVolumeClaim</span> dans son template de Pod.
              </p>
            </div>
          <?php else: ?>

            <div class="storage-grid">
              <section class="space-y-4">
                <div class="bg-background rounded-xl border p-6">
                  <div class="flex items-center justify-between gap-3 mb-4">
                    <h2 class="text-lg font-semibold">Volumes montés</h2>
                    <button id="refreshStorageMetaBtn" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium px-4 py-2 border hover:bg-secondary transition-colors">
                      Rafraîchir
                    </button>
                  </div>
                  <div id="mountList" class="space-y-3"></div>
                </div>

                <div class="bg-background rounded-xl border p-6">
                  <h2 class="text-lg font-semibold mb-3">Chemin courant</h2>
                  <label class="block text-sm text-muted-foreground mb-2" for="pathInput">Naviguer vers</label>
                  <div class="flex flex-col sm:flex-row gap-2">
                    <input id="pathInput" type="text" class="w-full rounded-md border bg-background px-3 py-2 text-sm mono" placeholder="/var/www/html" />
                    <button id="goPathBtn" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium px-4 py-2 border hover:bg-secondary transition-colors">
                      Ouvrir
                    </button>
                  </div>
                  <div id="pathHint" class="text-xs text-muted-foreground mt-3"></div>
                </div>
              </section>

              <section class="space-y-4">
                <div class="bg-background rounded-xl border p-6">
                  <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                      <h2 class="text-lg font-semibold">Explorateur</h2>
                      <p class="text-sm text-muted-foreground mt-1">Parcours un montage PVC depuis le Pod du deployment.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                      <button id="upDirBtn" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium px-4 py-2 border hover:bg-secondary transition-colors">
                        Dossier parent
                      </button>
                      <button id="reloadDirBtn" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium px-4 py-2 border hover:bg-secondary transition-colors">
                        Recharger
                      </button>
                    </div>
                  </div>

                  <div id="explorerMeta" class="mt-4 text-sm text-muted-foreground"></div>
                  <div id="breadcrumbs" class="crumbs mt-4 text-sm"></div>
                  <div id="explorerStatus" class="mt-4 text-sm text-muted-foreground">Sélectionne un volume pour commencer.</div>

                  <div class="mt-4 overflow-x-auto">
                    <table class="explorer-table text-sm">
                      <thead>
                        <tr class="text-left text-muted-foreground">
                          <th>Nom</th>
                          <th>Type</th>
                          <th>Taille</th>
                          <th>Modifié</th>
                        </tr>
                      </thead>
                      <tbody id="fileListBody">
                        <tr>
                          <td colspan="4" class="text-muted-foreground">Aucun dossier chargé.</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="bg-background rounded-xl border p-6">
                  <h2 class="text-lg font-semibold mb-3">Montage sélectionné</h2>
                  <div id="selectedMountCard" class="text-sm text-muted-foreground">
                    Aucun volume sélectionné.
                  </div>
                </div>
              </section>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <script>
    const DEPLOYMENT_NAME = <?= json_encode($deploymentName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const USER_NAMESPACE = <?= json_encode($userNamespace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const DETECTED_MOUNTS = <?= json_encode(array_values($storageMounts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>

  <script>
    (function(){
      const mountListEl = document.getElementById('mountList');
      const selectedMountCard = document.getElementById('selectedMountCard');
      const explorerMeta = document.getElementById('explorerMeta');
      const breadcrumbsEl = document.getElementById('breadcrumbs');
      const explorerStatus = document.getElementById('explorerStatus');
      const fileListBody = document.getElementById('fileListBody');
      const refreshStorageMetaBtn = document.getElementById('refreshStorageMetaBtn');
      const reloadDirBtn = document.getElementById('reloadDirBtn');
      const upDirBtn = document.getElementById('upDirBtn');
      const goPathBtn = document.getElementById('goPathBtn');
      const pathInput = document.getElementById('pathInput');
      const pathHint = document.getElementById('pathHint');

      if (!mountListEl || !selectedMountCard || !explorerMeta || !breadcrumbsEl || !explorerStatus || !fileListBody) {
        return;
      }

      let mounts = Array.isArray(DETECTED_MOUNTS) ? [...DETECTED_MOUNTS] : [];
      let currentMount = mounts[0] || null;
      let currentPath = currentMount ? String(currentMount.mountPath || '/') : '/';

      const escapeHtml = (s) => String(s)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');

      const normalizePath = (value, fallback) => {
        let path = String(value || '').trim();
        if (!path) path = String(fallback || '/');
        if (!path.startsWith('/')) path = '/' + path;
        path = path.replace(/\/+/g, '/');
        path = path.replace(/\/$/, '');
        return path === '' ? '/' : path;
      };

      const joinPath = (base, name) => {
        const b = normalizePath(base, '/');
        const clean = String(name || '').replace(/^\/+/, '');
        return normalizePath(b + '/' + clean, '/');
      };

      const parentPath = (path, root) => {
        const current = normalizePath(path, root);
        const base = normalizePath(root, '/');
        if (current === base) return base;
        const parts = current.split('/').filter(Boolean);
        const rootParts = base.split('/').filter(Boolean);
        if (parts.length <= rootParts.length) return base;
        parts.pop();
        return '/' + parts.join('/');
      };

      const formatBytes = (value) => {
        const n = Number(value);
        if (!Number.isFinite(n) || n < 0) return '—';
        if (n < 1024) return `${n} B`;
        const units = ['KB', 'MB', 'GB', 'TB'];
        let size = n;
        let unit = 'B';
        for (const u of units) {
          size /= 1024;
          unit = u;
          if (size < 1024) break;
        }
        return `${size >= 10 ? size.toFixed(0) : size.toFixed(1)} ${unit}`;
      };

      const setStatus = (text, kind = 'muted') => {
        explorerStatus.className = 'mt-4 text-sm ' + (
          kind === 'ok' ? 'status-ok' :
          kind === 'warn' ? 'status-warn' :
          kind === 'err' ? 'status-err' :
          kind === 'info' ? 'status-info' :
          'text-muted-foreground'
        );
        explorerStatus.textContent = text;
      };

      const getApiUrl = (action) => {
        const url = new URL('../k8s/k8s_api.php', window.location.href);
        url.searchParams.set('action', action);
        return url;
      };

      const renderSelectedMount = () => {
        if (!currentMount) {
          selectedMountCard.innerHTML = 'Aucun volume sélectionné.';
          explorerMeta.textContent = 'Aucun volume sélectionné.';
          pathHint.textContent = '';
          return;
        }

        selectedMountCard.innerHTML = `
          <div class="space-y-2">
            <div>PVC: <span class="mono">${escapeHtml(currentMount.claimName || '')}</span></div>
            <div>Container: <span class="mono">${escapeHtml(currentMount.container || '')}</span></div>
            <div>Volume: <span class="mono">${escapeHtml(currentMount.volumeName || '')}</span></div>
            <div>Mount path: <span class="mono">${escapeHtml(currentMount.mountPath || '')}</span></div>
            <div>SubPath: <span class="mono">${escapeHtml(currentMount.subPath || '—')}</span></div>
            <div>Mode: <span class="mono">${currentMount.readOnly ? 'read-only' : 'lecture/écriture'}</span></div>
          </div>
        `;

        explorerMeta.innerHTML = `
          Namespace <span class="mono">${escapeHtml(USER_NAMESPACE)}</span>
          • PVC <span class="mono">${escapeHtml(currentMount.claimName || '')}</span>
          • Container <span class="mono">${escapeHtml(currentMount.container || '')}</span>
        `;
        pathHint.textContent = `Racine autorisée: ${currentMount.mountPath || '/'}`;
      };

      const renderMounts = () => {
        mountListEl.innerHTML = '';

        if (!Array.isArray(mounts) || mounts.length === 0) {
          mountListEl.innerHTML = '<div class="text-sm text-muted-foreground">Aucun montage PVC détecté.</div>';
          return;
        }

        mounts.forEach((mount, index) => {
          const isActive = currentMount && currentMount.container === mount.container && currentMount.mountPath === mount.mountPath && currentMount.claimName === mount.claimName;
          const card = document.createElement('button');
          card.type = 'button';
          card.className = 'mount-card w-full text-left rounded-lg border p-4 hover:bg-secondary/30 transition-colors' + (isActive ? ' is-active' : '');
          card.innerHTML = `
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="font-medium break-all">${escapeHtml(mount.claimName || 'PVC')}</div>
                <div class="text-xs text-muted-foreground mt-1">Container <span class="mono">${escapeHtml(mount.container || '')}</span></div>
              </div>
              <span class="text-xs rounded-md border px-2 py-1 ${mount.readOnly ? 'text-amber-700' : 'text-emerald-700'}">${mount.readOnly ? 'read-only' : 'rw'}</span>
            </div>
            <div class="text-xs text-muted-foreground mono mt-3 break-all">${escapeHtml(mount.mountPath || '')}</div>
            <div class="text-xs text-muted-foreground mt-1">Volume: <span class="mono">${escapeHtml(mount.volumeName || '')}</span></div>
            ${mount.subPath ? `<div class="text-xs text-muted-foreground mt-1">SubPath: <span class="mono">${escapeHtml(mount.subPath)}</span></div>` : ''}
          `;
          card.addEventListener('click', () => {
            currentMount = mount;
            currentPath = normalizePath(mount.mountPath || '/', mount.mountPath || '/');
            pathInput.value = currentPath;
            renderMounts();
            renderSelectedMount();
            renderBreadcrumbs();
            loadDirectory(currentPath);
          });
          mountListEl.appendChild(card);
        });
      };

      const renderBreadcrumbs = () => {
        breadcrumbsEl.innerHTML = '';

        if (!currentMount) {
          return;
        }

        const root = normalizePath(currentMount.mountPath || '/', '/');
        const current = normalizePath(currentPath, root);
        const rootParts = root.split('/').filter(Boolean);
        const currentParts = current.split('/').filter(Boolean);

        let built = '';
        const makeCrumb = (label, path) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'text-sm hover:underline';
          btn.innerHTML = label;
          btn.addEventListener('click', () => {
            currentPath = path;
            pathInput.value = path;
            renderBreadcrumbs();
            loadDirectory(path);
          });
          return btn;
        };

        const rootLabel = `${escapeHtml(currentMount.claimName || 'PVC')} <span class="mono text-muted-foreground">${escapeHtml(root)}</span>`;
        breadcrumbsEl.appendChild(makeCrumb(rootLabel, root));

        for (let i = rootParts.length; i < currentParts.length; i++) {
          built += '/' + currentParts[i];
          const sep = document.createElement('span');
          sep.className = 'crumb-sep text-muted-foreground';
          sep.textContent = '/';
          breadcrumbsEl.appendChild(sep);

          const partPath = normalizePath(root + built, root);
          breadcrumbsEl.appendChild(makeCrumb(escapeHtml(currentParts[i]), partPath));
        }
      };

      const renderRows = (items) => {
        fileListBody.innerHTML = '';

        if (!Array.isArray(items) || items.length === 0) {
          fileListBody.innerHTML = '<tr><td colspan="4" class="text-muted-foreground">Ce dossier est vide.</td></tr>';
          return;
        }

        items.forEach((item) => {
          const type = String(item && item.type ? item.type : 'file');
          const isDir = type === 'dir' || type === 'directory';
          const name = String(item && item.name ? item.name : '');
          const nextPath = normalizePath(item && item.path ? item.path : joinPath(currentPath, name), currentPath);
          const tr = document.createElement('tr');
          tr.className = 'explorer-row' + (isDir ? ' is-dir' : '');
          tr.innerHTML = `
            <td>
              <div class="flex items-center gap-3 min-w-0">
                <span class="file-icon">${isDir ? '📁' : '📄'}</span>
                <div class="min-w-0">
                  <div class="file-name break-all">${escapeHtml(name || '(sans nom)')}</div>
                  ${item && item.subPath ? `<div class="text-xs text-muted-foreground mono break-all">${escapeHtml(String(item.subPath))}</div>` : ''}
                </div>
              </div>
            </td>
            <td class="text-muted-foreground">${escapeHtml(isDir ? 'Dossier' : 'Fichier')}</td>
            <td class="text-muted-foreground">${isDir ? '—' : escapeHtml(formatBytes(item && item.size))}</td>
            <td class="text-muted-foreground mono">${escapeHtml(item && item.mtime ? String(item.mtime) : '—')}</td>
          `;

          if (isDir) {
            tr.addEventListener('click', () => {
              currentPath = nextPath;
              pathInput.value = currentPath;
              renderBreadcrumbs();
              loadDirectory(currentPath);
            });
          }

          fileListBody.appendChild(tr);
        });
      };

      const explainMissingEndpoint = (actionName) => {
        setStatus(`Le backend n’expose pas encore l’action ${actionName}. La page continue avec les données déjà détectées.`, 'info');

      };

      const loadStorageMeta = async () => {
        if (!currentMount && mounts[0]) {
          currentMount = mounts[0];
          currentPath = normalizePath(currentMount.mountPath || '/', '/');
        }

        const url = getApiUrl('get_deployment_storage');
        url.searchParams.set('deployment', DEPLOYMENT_NAME);

        try {
          const res = await fetch(url.toString(), { credentials: 'same-origin' });
          const raw = await res.text();
          let data = null;
          try { data = JSON.parse(raw); } catch (_) {}

          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.error) ? data.error : ('HTTP ' + res.status));
          }

          const nextMounts = Array.isArray(data.mounts) ? data.mounts : [];
          if (nextMounts.length > 0) {
            mounts = nextMounts;
            const sameMount = currentMount
              ? mounts.find((m) => m.container === currentMount.container && m.mountPath === currentMount.mountPath && m.claimName === currentMount.claimName)
              : null;
            currentMount = sameMount || mounts[0] || null;
            currentPath = normalizePath(currentMount && currentMount.mountPath ? currentMount.mountPath : '/', '/');
            pathInput.value = currentPath;
            renderMounts();
            renderSelectedMount();
            renderBreadcrumbs();
            setStatus('Montages rechargés.', 'ok');
            return true;
          }

          mounts = [];
          currentMount = null;
          currentPath = '/';
          renderMounts();
          renderSelectedMount();
          renderBreadcrumbs();
          renderRows([]);
          setStatus('Aucun montage PVC détecté.', 'warn');
          return true;
        } catch (e) {
          renderMounts();
          renderSelectedMount();
          renderBreadcrumbs();
          const msg = e && e.message ? String(e.message) : String(e);
          if (/unknown action|not found|404/i.test(msg)) {
            explainMissingEndpoint('get_deployment_storage');
          } else {
            setStatus('Impossible de recharger les montages: ' + msg, 'warn');
          }
          return false;
        }
      };

      const loadDirectory = async (path) => {
        if (!currentMount) {
          renderRows([]);
          setStatus('Sélectionne un volume pour commencer.', 'warn');
          return;
        }

        const safePath = normalizePath(path, currentMount.mountPath || '/');
        currentPath = safePath;
        pathInput.value = safePath;
        setStatus('Chargement du dossier…', 'muted');
        fileListBody.innerHTML = '<tr><td colspan="4" class="text-muted-foreground">Chargement…</td></tr>';

        const url = getApiUrl('list_files');
        url.searchParams.set('deployment', DEPLOYMENT_NAME);
        url.searchParams.set('container', String(currentMount.container || ''));
        url.searchParams.set('claim', String(currentMount.claimName || ''));
        url.searchParams.set('mountPath', String(currentMount.mountPath || '/'));
        url.searchParams.set('path', safePath);

        try {
          const res = await fetch(url.toString(), { credentials: 'same-origin' });
          const raw = await res.text();
          let data = null;
          try { data = JSON.parse(raw); } catch (_) {}

          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.error) ? data.error : ('HTTP ' + res.status));
          }

          const items = Array.isArray(data.items) ? data.items : [];
          renderRows(items);
          renderBreadcrumbs();

          const dirShown = typeof data.path === 'string' && data.path !== '' ? data.path : safePath;
          setStatus(`Dossier chargé: ${dirShown}`, 'ok');
        } catch (e) {
          renderRows([]);
          const msg = e && e.message ? String(e.message) : String(e);
          if (/unknown action|not found|404/i.test(msg)) {
            explainMissingEndpoint('list_files');
          } else {
            setStatus('Impossible de lister ce dossier: ' + msg, 'err');
          }
        }
      };

      refreshStorageMetaBtn && refreshStorageMetaBtn.addEventListener('click', async () => {
        refreshStorageMetaBtn.disabled = true;
        try {
          const ok = await loadStorageMeta();
          if (ok && currentMount) {
            await loadDirectory(currentPath || currentMount.mountPath || '/');
          }
        } finally {
          refreshStorageMetaBtn.disabled = false;
        }
      });

      reloadDirBtn && reloadDirBtn.addEventListener('click', async () => {
        await loadDirectory(currentPath);
      });

      upDirBtn && upDirBtn.addEventListener('click', async () => {
        if (!currentMount) return;
        const next = parentPath(currentPath, currentMount.mountPath || '/');
        currentPath = next;
        pathInput.value = next;
        renderBreadcrumbs();
        await loadDirectory(next);
      });

      goPathBtn && goPathBtn.addEventListener('click', async () => {
        if (!currentMount) {
          setStatus('Aucun volume sélectionné.', 'warn');
          return;
        }
        const next = normalizePath(pathInput.value, currentMount.mountPath || '/');
        currentPath = next;
        renderBreadcrumbs();
        await loadDirectory(next);
      });

      pathInput && pathInput.addEventListener('keydown', async (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        goPathBtn && goPathBtn.click();
      });

      renderMounts();
      renderSelectedMount();
      renderBreadcrumbs();
      pathInput.value = currentPath;

      if (currentMount) {
        loadStorageMeta().finally(() => {
          loadDirectory(currentPath);
        });

      }
    })();
  </script>
</body>
</html>
