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

$mountsCount = count($storageMounts);

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
    .storage-grid{display:flex;flex-direction:column;gap:16px;align-items:stretch;width:100%;}
    .storage-column{min-width:0;width:100%;}
    .explorer-table{width:100%;border-collapse:collapse;}
    .explorer-table th,.explorer-table td{padding:12px 10px;border-bottom:1px solid rgba(127,127,127,.16);vertical-align:middle;}
    .explorer-row{cursor:pointer;}
    .explorer-row:hover{background:rgba(127,127,127,.06);}
    .explorer-row.is-dir .file-name{font-weight:600;}
    .crumbs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
    .crumb-sep{opacity:.55;}
    .explorer-path{display:flex;flex-wrap:wrap;align-items:center;gap:0;font-size:.875rem;color:inherit;}
    .explorer-path-prefix{margin-right:6px;color:inherit;font-weight:500;}
    .explorer-path-sep{opacity:.55;}
    .explorer-path-link{background:none;border:0;padding:0;margin:0;font:inherit;color:inherit;cursor:pointer;}
    .explorer-path-link:hover{text-decoration:underline;}
    .explorer-path-text{color:inherit;}
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

          <div class="" id="stockCard">
            <div class="flex flex-wrap items-left justify-between gap-3">
              <a class="text-sm text-muted-foreground hover:text-foreground" href="/log?deployment=<?= urlencode($deploymentName) ?>">Acceder aux Logs →</a>
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

          <?php if ($mountsCount === 0): ?>
            <div class="bg-background rounded-xl border p-6 mt-6" id="storageExplorerCard">
              <h2 class="text-lg font-semibold mb-3">Explorateur de fichiers</h2>
              <p class="text-sm text-muted-foreground">
                Ce Deployment n’expose aucun volume de type <span class="mono">persistentVolumeClaim</span> dans son template de Pod.
              </p>
            </div>
          <?php else: ?>
          <div class="storage-grid mt-6" id="storageExplorerCard">
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
                            <div id="explorerCardSubtitle" class="text-sm text-muted-foreground mono break-all"></div>
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

          <div class="" id="secretCard">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
              <button type="button" id="secretCreateToggle" class="h-9 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Nouvelle variable</button>
            </div>
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


      const navigateToPath = async (path) => {
        currentPath = path;
        renderBreadcrumbs();
        await loadDirectory(path);
      };

      const renderSubtitlePath = () => {
        if (!explorerCardSubtitle) return;

        const current = currentMount
          ? normalizePath(currentPath, normalizePath(currentMount.mountPath || '/', '/'))
          : '/';
        const root = currentMount
          ? normalizePath(currentMount.mountPath || '/', '/')
          : '/';

        explorerCardSubtitle.innerHTML = '';

        const wrapper = document.createElement('div');
        wrapper.className = 'explorer-path';

        const prefix = document.createElement('span');
        prefix.className = 'explorer-path-prefix';
        prefix.textContent = 'Chemin :';
        wrapper.appendChild(prefix);

        const parts = current.split('/').filter(Boolean);
        const rootParts = root.split('/').filter(Boolean);
        const clickableStartIndex = rootParts.length === 0 ? 0 : Math.max(rootParts.length - 1, 0);

        const leadingSlash = document.createElement('span');
        leadingSlash.className = 'explorer-path-sep mono';
        leadingSlash.textContent = '/';
        wrapper.appendChild(leadingSlash);

        let built = '';
        parts.forEach((part, index) => {
          built += '/' + part;
          const segmentPath = normalizePath(built, '/');
          const isClickable = index >= clickableStartIndex;
          const node = document.createElement(isClickable ? 'button' : 'span');
          node.className = (isClickable ? 'explorer-path-link' : 'explorer-path-text') + ' mono';
          node.textContent = part;
          if (isClickable) {
            node.type = 'button';
            node.addEventListener('click', () => {
              navigateToPath(segmentPath);
            });
          }
          wrapper.appendChild(node);

          if (index < parts.length - 1) {
            const sep = document.createElement('span');
            sep.className = 'explorer-path-sep mono';
            sep.textContent = '/';
            wrapper.appendChild(sep);
          }
        });

        explorerCardSubtitle.appendChild(wrapper);
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
          renderSubtitlePath();
          return;
        }

        renderDirectorySummary(directoryItems);

        renderSubtitlePath();
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
          renderSubtitlePath();
          return;
        }

        const root = normalizePath(currentMount.mountPath || '/', '/');
        const current = normalizePath(currentPath, root);

        renderSubtitlePath();
        const rootParts = root.split('/').filter(Boolean);
        const currentParts = current.split('/').filter(Boolean);

        let built = '';
        const makeCrumb = (label, path) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'text-sm hover:underline';
          btn.innerHTML = label;
          btn.addEventListener('click', () => {
            navigateToPath(path);
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
            await navigateToPath(nextPath);
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
        wrap.className = 'bg-background rounded-lg border p-4';
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
