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

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit;
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

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

$deploymentParam = $_GET['deployment'] ?? $_GET['name'] ?? '';
$deploymentName = is_string($deploymentParam) ? $deploymentParam : '';

if (isset($_GET['name']) && !isset($_GET['deployment']) && $deploymentName !== '') {
    $canonicalQuery = $_GET;
    unset($canonicalQuery['name']);
    $canonicalQuery['deployment'] = $deploymentName;
    header('Location: /stockage?' . http_build_query($canonicalQuery, '', '&', PHP_QUERY_RFC3986), true, 302);
    exit;
}

if ($deploymentName === '' || !isDnsLabel($deploymentName)) {
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
    .storage-grid{display:flex;flex-direction:column;gap:16px;align-items:stretch;width:100%;}
    .storage-column{min-width:0;width:100%;}
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

        <?php if ($k8sError !== null): ?>
          <div class="bg-background rounded-xl border p-6 text-red-600">
            <strong>Erreur Kubernetes:</strong>
            <div class="mt-2 mono text-sm"><?= htmlspecialchars($k8sError, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        <?php else: ?>

          <?php if ($mountsCount === 0): ?>
            <div class="bg-background rounded-xl border p-6">
              <h2 class="text-lg font-semibold mb-3">Aucun stockage détecté</h2>
              <p class="text-sm text-muted-foreground">
                Ce Deployment n’expose aucun volume de type <span class="mono">persistentVolumeClaim</span> dans son template de Pod.
              </p>
            </div>
          <?php else: ?>

            <div class="storage-grid">
              <section class="storage-column">
                <div>
                  <div id="explorerMeta" class="hidden" style="display:none"></div>
                  <div id="explorerStatus" class="mt-4 text-sm text-muted-foreground">Sélectionne un volume pour commencer.</div>

                  <div data-slot="card" class="bg-background text-card-foreground mt-4 flex flex-col gap-6 rounded-xl border py-6 shadow-sm">
                    <div class="space-y-6 px-4">
                      <div class="flex flex-col flex-wrap gap-6 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                          <div class="bg-muted rounded-lg p-2.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-folder-open text-muted-foreground h-5 w-5"><path d="m6 14 1.5-8A2 2 0 0 1 9.47 4H20a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2Z"></path><path d="M3 6a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.94L12 7h8"></path></svg>
                          </div>
                          <div class="space-y-1">
                            <h3 class="text-xl font-semibold">Explorateur de fichiers</h3>
                            <div id="breadcrumbs" class="crumbs text-sm" style="display:none"></div>
                          </div>
                        </div>
                        <div class="flex w-full items-center gap-3 sm:w-max">
<button id="reloadDirBtn" data-slot="button" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*=&#x27;size-&#x27;])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive bg-primary text-primary-foreground hover:bg-primary/90 h-9 px-4 py-2 has-[&gt;svg]:px-3 w-full gap-2 transition-all sm:w-auto">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-refresh-cw h-4 w-4"><path d="M3 2v6h6"></path><path d="M21 12A9 9 0 0 0 6 5.3L3 8"></path><path d="M21 22v-6h-6"></path><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"></path></svg>
                            Recharger
                          </button>
                        </div>
                      </div>
                      <div data-orientation="horizontal" role="none" data-slot="separator" class="bg-border shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px"></div>
                      <div class="flex flex-col flex-wrap items-center justify-between gap-6 sm:flex-row">
                        <div dir="ltr" data-orientation="horizontal" data-slot="tabs" class="flex flex-col gap-2 w-full sm:w-max">
                          <div id="mountTabs" role="tablist" aria-orientation="horizontal" data-slot="tabs-list" class="text-muted-foreground inline-flex h-9 items-center justify-center rounded-lg p-[3px] bg-muted/50 w-full overflow-x-auto" tabindex="-1" data-orientation="horizontal" style="outline:none"></div>
                        </div>
                        <div class="flex w-full flex-col items-center gap-2 sm:w-max sm:flex-row">
                          <select id="explorerSort" data-slot="select-trigger" data-size="default" class="border-input data-[placeholder]:text-muted-foreground [&_svg:not([class*=&#x27;text-&#x27;])]:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 dark:hover:bg-input/50 flex items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-sm whitespace-nowrap shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 data-[size=default]:h-9 data-[size=sm]:h-8 hover:bg-muted w-full transition-all sm:w-max">
                            <option value="name-asc">Nom A → Z</option>
                            <option value="name-desc">Nom Z → A</option>
                            <option value="mtime-desc">Modifiés récemment</option>
                            <option value="size-desc">Taille décroissante</option>
                            <option value="type-asc">Type</option>
                          </select>
                          <div class="relative w-full">
                            <input id="explorerSearchInput" type="text" data-slot="input" class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive pl-9 transition-all focus:ring-2" placeholder="Rechercher un fichier ou dossier..."/>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div data-slot="card-content" class="overflow-scroll rounded-none p-0">
                      <table class="w-full min-w-max table-auto text-left">
                        <thead>
                          <tr>
                            <th class="border-surface border-b p-4">
                              <div class="flex items-center gap-2">
                                <button id="selectAllRows" type="button" role="checkbox" aria-checked="false" data-state="unchecked" value="on" data-slot="checkbox" class="peer border-input dark:bg-input/30 data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground dark:data-[state=checked]:bg-primary data-[state=checked]:border-primary focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive size-4 shrink-0 rounded-[4px] border shadow-xs transition-shadow outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50"></button>
                                <input type="checkbox" aria-hidden="true" tabindex="-1" style="position:absolute;pointer-events:none;opacity:0;margin:0;transform:translateX(-100%)" value="on"/>
                                <label for="selectAllRows" class="text-default block text-sm font-medium">Nom</label>
                              </div>
                            </th>
                            <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Modifié</p></th>
                            <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Statut</p></th>
                                                        <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Taille</p></th>
                            <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"></p></th>
                          </tr>
                        </thead>
                        <tbody id="fileListBody">
                          <tr>
                            <td colspan="5" class="border-surface border-b p-4 text-muted-foreground">Aucun dossier chargé.</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
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
      const explorerCardSubtitle = document.getElementById('explorerCardSubtitle');
      const fileListBody = document.getElementById('fileListBody');
      const selectAllRowsBtn = document.getElementById('selectAllRows');
      const refreshStorageMetaBtn = document.getElementById('refreshStorageMetaBtn');
      const reloadDirBtn = document.getElementById('reloadDirBtn');
      const mountTabs = document.getElementById('mountTabs');
      const explorerSearchInput = document.getElementById('explorerSearchInput');
      const explorerSort = document.getElementById('explorerSort');

      if (!explorerMeta || !breadcrumbsEl || !explorerStatus || !fileListBody) {
        return;
      }

      const TABLE_COLSPAN = 5;

      let mounts = Array.isArray(DETECTED_MOUNTS) ? [...DETECTED_MOUNTS] : [];
      let currentMount = mounts[0] || null;
      let currentPath = currentMount ? String(currentMount.mountPath || '/') : '/';
      let directoryItems = [];
      let currentItems = [];
      let selectedRows = new Set();
      let currentSort = explorerSort && explorerSort.value ? explorerSort.value : 'name-asc';
      let currentSearch = explorerSearchInput && explorerSearchInput.value ? explorerSearchInput.value.trim().toLowerCase() : '';

      const getMountKey = (mount) => {
        if (!mount) return '';
        return [mount.claimName || '', mount.container || '', mount.mountPath || '', mount.subPath || ''].join('::');
      };

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

      const setCheckboxState = (button, checked) => {
        if (!button) return;
        button.setAttribute('aria-checked', checked ? 'true' : 'false');
        button.setAttribute('data-state', checked ? 'checked' : 'unchecked');
      };


      const getItemType = (item) => {
        const type = String(item && item.type ? item.type : 'file').toLowerCase();
        return (type === 'dir' || type === 'directory') ? 'dir' : 'file';
      };

      const getRowKey = (item) => {
        const path = String(item && item.path ? item.path : joinPath(currentPath, item && item.name ? item.name : ''));
        return `${currentMount && currentMount.claimName ? currentMount.claimName : 'mount'}::${path}`;
      };

      const renderTableMessage = (message) => {
        fileListBody.innerHTML = `<tr><td colspan="${TABLE_COLSPAN}" class="border-surface border-b p-4 text-muted-foreground">${escapeHtml(message)}</td></tr>`;
        currentItems = [];
        selectedRows = new Set();
        setCheckboxState(selectAllRowsBtn, false);
      };

      const syncSelectAllState = () => {
        if (!selectAllRowsBtn || currentItems.length === 0) {
          setCheckboxState(selectAllRowsBtn, false);
          return;
        }
        const visibleKeys = currentItems.map(getRowKey);
        const allSelected = visibleKeys.length > 0 && visibleKeys.every((key) => selectedRows.has(key));
        setCheckboxState(selectAllRowsBtn, allSelected);
      };

      const renderMountTabs = () => {
        if (!mountTabs) return;
        mountTabs.innerHTML = '';

        if (!Array.isArray(mounts) || mounts.length === 0) {
          return;
        }

        mounts.forEach((mount) => {
          const isActive = currentMount && getMountKey(currentMount) === getMountKey(mount);
          const button = document.createElement('button');
          button.type = 'button';
          button.role = 'tab';
          button.setAttribute('aria-selected', isActive ? 'true' : 'false');
          button.setAttribute('data-state', isActive ? 'active' : 'inactive');
          button.setAttribute('data-slot', 'tabs-trigger');
          button.className = 'data-[state=active]:bg-background dark:data-[state=active]:text-foreground focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:outline-ring dark:data-[state=active]:border-input dark:data-[state=active]:bg-input/30 text-foreground dark:text-muted-foreground inline-flex h-[calc(100%-1px)] items-center justify-center gap-1.5 rounded-md border border-transparent px-3 py-1 text-sm font-medium whitespace-nowrap transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:outline-1 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:shadow-sm shrink-0';
          button.textContent = mount.container || mount.claimName || 'Montage';
          button.addEventListener('click', () => {
            currentMount = mount;
            currentPath = normalizePath(mount.mountPath || '/', mount.mountPath || '/');
            directoryItems = [];
            selectedRows = new Set();
            renderMounts();
            renderMountTabs();
            renderSelectedMount();
            renderBreadcrumbs();
            loadDirectory(currentPath);
          });
          mountTabs.appendChild(button);
        });
      };

      const renderDirectorySummary = (items) => {
        const list = Array.isArray(items) ? items : [];
        const dirsCount = list.filter((item) => getItemType(item) === 'dir').length;
        const filesCount = list.length - dirsCount;
        if (!explorerMeta) return;
        if (!currentMount) {
          explorerMeta.textContent = 'Aucun volume sélectionné.';
          return;
        }
        explorerMeta.innerHTML = `Namespace <span class="mono">${escapeHtml(USER_NAMESPACE)}</span>
          • PVC <span class="mono">${escapeHtml(currentMount.claimName || '')}</span>
          • Container <span class="mono">${escapeHtml(currentMount.container || '')}</span>
          • ${list.length} éléments (${dirsCount} dossiers, ${filesCount} fichiers)`;
      };

      const compareByMtimeDesc = (a, b) => {
        const av = Date.parse(a && a.mtime ? String(a.mtime) : '') || 0;
        const bv = Date.parse(b && b.mtime ? String(b.mtime) : '') || 0;
        return bv - av;
      };

      const sortItems = (items) => {
        const list = [...items];
        list.sort((a, b) => {
          const typeA = getItemType(a);
          const typeB = getItemType(b);
          if (currentSort === 'type-asc' && typeA !== typeB) {
            return typeA.localeCompare(typeB, 'fr');
          }

          const nameA = String(a && a.name ? a.name : '').toLocaleLowerCase('fr');
          const nameB = String(b && b.name ? b.name : '').toLocaleLowerCase('fr');

          if (currentSort === 'name-desc') {
            return nameB.localeCompare(nameA, 'fr', { numeric: true, sensitivity: 'base' });
          }

          if (currentSort === 'mtime-desc') {
            const byMtime = compareByMtimeDesc(a, b);
            return byMtime !== 0 ? byMtime : nameA.localeCompare(nameB, 'fr', { numeric: true, sensitivity: 'base' });
          }

          if (currentSort === 'size-desc') {
            const sizeA = Number(a && a.size ? a.size : 0);
            const sizeB = Number(b && b.size ? b.size : 0);
            if (sizeB !== sizeA) return sizeB - sizeA;
            return nameA.localeCompare(nameB, 'fr', { numeric: true, sensitivity: 'base' });
          }

          if (currentSort === 'type-asc' && typeA === typeB) {
            return nameA.localeCompare(nameB, 'fr', { numeric: true, sensitivity: 'base' });
          }

          return nameA.localeCompare(nameB, 'fr', { numeric: true, sensitivity: 'base' });
        });
        return list;
      };

      const getVisibleItems = (items) => {
        let list = Array.isArray(items) ? [...items] : [];

        if (currentSearch) {
          list = list.filter((item) => {
            const name = String(item && item.name ? item.name : '').toLowerCase();
            const path = String(item && item.path ? item.path : '').toLowerCase();
            return name.includes(currentSearch) || path.includes(currentSearch);
          });
        }

        return sortItems(list);
      };

      const getApiUrl = (action) => {
        const url = new URL('../k8s/k8s_api.php', window.location.href);
        url.searchParams.set('action', action);
        return url;
      };

      const renderSelectedMount = () => {
        if (!currentMount) {
          explorerMeta.textContent = 'Aucun volume sélectionné.';
          if (explorerCardSubtitle) {
            explorerCardSubtitle.textContent = 'Sélectionne un volume pour commencer.';
          }
          return;
        }

        renderDirectorySummary(directoryItems);

        if (explorerCardSubtitle) {
          explorerCardSubtitle.textContent = `Container ${currentMount.container || '—'}`;
        }
      };

      const renderMounts = () => {
        if (!mountListEl) {
          return;
        }

        mountListEl.innerHTML = '';

        if (!Array.isArray(mounts) || mounts.length === 0) {
          mountListEl.innerHTML = '<div class="text-sm text-muted-foreground">Aucun montage PVC détecté.</div>';
          return;
        }

        mounts.forEach((mount) => {
          const isActive = currentMount && currentMount.container === mount.container && currentMount.mountPath === mount.mountPath && currentMount.claimName === mount.claimName;
          const card = document.createElement('button');
          card.type = 'button';
          card.className = 'mount-card w-full text-left rounded-lg border p-4 hover:bg-secondary/30 transition-colors' + (isActive ? ' is-active' : '');
          card.innerHTML = `
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="font-medium break-all">${escapeHtml(mount.container || 'Container')}</div>
                <div class="text-xs text-muted-foreground mt-1">PVC <span class="mono">${escapeHtml(mount.claimName || '')}</span></div>
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
            directoryItems = [];
            selectedRows = new Set();
            renderMounts();
            renderMountTabs();
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
            renderBreadcrumbs();
            loadDirectory(path);
          });
          return btn;
        };

        const rootLabel = `<span class="mono text-muted-foreground">${escapeHtml(currentMount.claimName || 'PVC')} ${escapeHtml(root)}</span>`;
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
        currentItems = Array.isArray(items) ? items : [];
        fileListBody.innerHTML = '';

        if (currentItems.length === 0) {
          const msg = directoryItems.length === 0
            ? 'Ce dossier est vide.'
            : 'Aucun élément ne correspond aux filtres actifs.';
          renderTableMessage(msg);
          renderDirectorySummary(directoryItems);
          return;
        }

        currentItems.forEach((item, index) => {
          const isDir = getItemType(item) === 'dir';
          const name = String(item && item.name ? item.name : '');
          const nextPath = normalizePath(item && item.path ? item.path : joinPath(currentPath, name), currentPath);
          const rowKey = getRowKey(item);
          const checkboxId = `file-row-${index}`;
          const tr = document.createElement('tr');
          tr.className = isDir ? 'cursor-pointer hover:bg-accent/30' : 'hover:bg-accent/10';
          tr.innerHTML = `
            <td class="border-surface border-b p-4">
              <div class="flex items-center gap-2">
                <button type="button" role="checkbox" aria-checked="${selectedRows.has(rowKey) ? 'true' : 'false'}" data-state="${selectedRows.has(rowKey) ? 'checked' : 'unchecked'}" value="on" data-slot="checkbox" class="row-select peer border-input dark:bg-input/30 data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground dark:data-[state=checked]:bg-primary data-[state=checked]:border-primary focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive size-4 shrink-0 rounded-[4px] border shadow-xs transition-shadow outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50" data-row-key="${escapeHtml(rowKey)}" id="${checkboxId}"></button>
                <input type="checkbox" aria-hidden="true" tabindex="-1" style="position:absolute;pointer-events:none;opacity:0;margin:0;transform:translateX(-100%)" value="on"/>
                <label for="${checkboxId}" class="text-foreground block text-sm font-medium">${escapeHtml(name || '(sans nom)')}</label>
              </div>
            </td>
            <td class="border-surface border-b p-4"><p class="text-foreground block text-sm mono">${escapeHtml(item && item.mtime ? String(item.mtime) : '—')}</p></td>
            <td class="border-surface border-b p-4">
              <span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden w-max" color="${isDir ? 'success' : 'secondary'}" data-slot="badge">${escapeHtml(isDir ? 'Dossier' : 'Fichier')}</span>
            </td>
            <td class="border-surface border-b p-4"><p class="text-foreground block text-sm">${isDir ? '—' : escapeHtml(formatBytes(item && item.size))}</p></td>
            <td class="border-surface border-b p-4 text-end">
              <button type="button" class="open-row inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9" ${isDir ? '' : 'disabled'} aria-label="${isDir ? 'Ouvrir le dossier' : 'Aucune action'}">
                <svg class="lucide lucide-ellipsis-vertical h-5 w-5 stroke-2" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>
              </button>
            </td>
          `;

          const goToRow = async () => {
            if (!isDir) return;
            currentPath = nextPath;
            renderBreadcrumbs();
            await loadDirectory(currentPath);
          };

          if (isDir) {
            tr.addEventListener('click', goToRow);
          }

          const selectBtn = tr.querySelector('.row-select');
          if (selectBtn) {
            selectBtn.addEventListener('click', (event) => {
              event.stopPropagation();
              if (selectedRows.has(rowKey)) {
                selectedRows.delete(rowKey);
                setCheckboxState(selectBtn, false);
              } else {
                selectedRows.add(rowKey);
                setCheckboxState(selectBtn, true);
              }
              syncSelectAllState();
            });
          }

          const openBtn = tr.querySelector('.open-row');
          if (openBtn) {
            openBtn.addEventListener('click', async (event) => {
              event.stopPropagation();
              await goToRow();
            });
          }

          fileListBody.appendChild(tr);
        });

        renderDirectorySummary(directoryItems);
        syncSelectAllState();
      };

      const renderVisibleRows = () => {
        renderRows(getVisibleItems(directoryItems));
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
            renderMounts();
            renderMountTabs();
            renderSelectedMount();
            renderBreadcrumbs();
            setStatus('Montages rechargés.', 'ok');
            return true;
          }

          mounts = [];
          currentMount = null;
          currentPath = '/';
          directoryItems = [];
          renderMounts();
          renderMountTabs();
          renderSelectedMount();
          renderBreadcrumbs();
          renderDirectorySummary([]);
          renderTableMessage('Aucun montage PVC détecté.');
          setStatus('Aucun montage PVC détecté.', 'warn');
          return true;
        } catch (e) {
          renderMounts();
          renderMountTabs();
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
          directoryItems = [];
          renderDirectorySummary([]);
          renderTableMessage('Sélectionne un volume pour commencer.');
          setStatus('Sélectionne un volume pour commencer.', 'warn');
          return;
        }

        const safePath = normalizePath(path, currentMount.mountPath || '/');
        currentPath = safePath;
        setStatus('Chargement du dossier…', 'muted');
        renderTableMessage('Chargement…');

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

          directoryItems = Array.isArray(data.items) ? data.items : [];
          renderBreadcrumbs();
          renderVisibleRows();

          const dirShown = typeof data.path === 'string' && data.path !== '' ? data.path : safePath;
          setStatus(`Dossier chargé: ${dirShown}`, 'ok');
        } catch (e) {
          directoryItems = [];
          renderDirectorySummary([]);
          renderTableMessage('Impossible de charger les éléments de ce dossier.');
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

      selectAllRowsBtn && selectAllRowsBtn.addEventListener('click', () => {
        if (currentItems.length === 0) {
          setCheckboxState(selectAllRowsBtn, false);
          return;
        }
        const visibleKeys = currentItems.map(getRowKey);
        const shouldSelectAll = !visibleKeys.every((key) => selectedRows.has(key));
        visibleKeys.forEach((key) => {
          if (shouldSelectAll) {
            selectedRows.add(key);
          } else {
            selectedRows.delete(key);
          }
        });
        renderVisibleRows();
      });

      reloadDirBtn && reloadDirBtn.addEventListener('click', async () => {
        await loadDirectory(currentPath);
      });


      explorerSearchInput && explorerSearchInput.addEventListener('input', () => {
        currentSearch = explorerSearchInput.value.trim().toLowerCase();
        renderVisibleRows();
      });

      explorerSort && explorerSort.addEventListener('change', () => {
        currentSort = explorerSort.value || 'name-asc';
        renderVisibleRows();
      });


      renderMounts();
      renderMountTabs();
      renderSelectedMount();
      renderBreadcrumbs();
      renderDirectorySummary([]);

      if (currentMount) {
        loadStorageMeta().finally(() => {
          loadDirectory(currentPath);
        });
      }
    })();
  </script>
</body>
</html>
