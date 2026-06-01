<?php

declare(strict_types=1);

/* ============================================================================
   pages/deployment.php  —  version réécrite
   ----------------------------------------------------------------------------
   Améliorations intégrées par rapport à l'original :
     • Réutilisation des helpers partagés (session_user.php) au lieu des
       copies inline : sessionUserNamespace() et sessionUserCsrf().
     • CSRF géré par le helper centralisé (un seul endroit de vérité).
     • Message d'erreur Kubernetes assaini : le détail est journalisé,
       l'utilisateur ne voit qu'un message générique (pas de fuite d'infos).
     • En-têtes de sécurité (anti-clickjacking + referrer) sur la page HTML.
     • Système de modal unifié (window.Modal) : erreurs / confirmations /
       redémarrage / session expirée — voir blocs [A]/[B]/[C]/[D]/[E].
   ============================================================================ */

require_once '../include/session_bootstrap.php';
require_once '../config_loader.php';
require_once '../include/account_sessions.php';
require_once '../include/session_user.php';   // ← helpers partagés (namespace, csrf)

if (!isset($_SESSION['user'])) {
    header('Location: /connexion');
    exit;
}

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit;
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

require_once '../data/KubernetesClient.php';

/* ── En-têtes de sécurité (avant toute sortie HTML) ──
   X-Frame-Options : un panneau de contrôle ne doit jamais être embarqué en iframe.
   Referrer-Policy : évite de fuiter l'URL (qui contient le nom du déploiement).
   NB : une Content-Security-Policy stricte est recommandée mais nécessite
   d'abord d'externaliser les <script>/<style> inline (sinon 'unsafe-inline').
   Exemple à activer une fois le JS/CSS sortis dans des fichiers /assets :
   header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; connect-src 'self'"); */
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (!function_exists('includeIsolated')) {
    function includeIsolated(string $file, array $vars = []): void
    {
        if (!is_file($file)) {
            return;
        }
        (static function (string $__file, array $__vars): void {
            if ($__vars !== []) {
                extract($__vars, EXTR_SKIP);
            }
            include $__file;
        })($file, $vars);
    }
}

if (!function_exists('deploymentBaseDomainFromHost')) {
    /**
     * Réduit un host à son domaine de base.
     * Limitation connue : la liste $twoLevelSuffixes ci-dessous est une
     * approximation partielle de la Public Suffix List. Pour une exactitude
     * complète sur tous les ccTLD, envisager une vraie librairie PSL.
     */
    function deploymentBaseDomainFromHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');
        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }
        $host = (string) preg_replace('/:\d+$/', '', $host);
        if ($host === '') {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        $parts = array_values(array_filter(explode('.', $host), static fn ($p): bool => $p !== ''));
        $count = count($parts);
        if ($count <= 2) {
            return $host;
        }
        $lastTwo = $parts[$count - 2] . '.' . $parts[$count - 1];
        $twoLevelSuffixes = [
            'co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'net.uk',
            'com.au', 'net.au', 'org.au',
            'co.nz', 'org.nz',
            'com.br', 'com.mx', 'co.jp',
        ];
        if (in_array($lastTwo, $twoLevelSuffixes, true) && $count >= 3) {
            return $parts[$count - 3] . '.' . $lastTwo;
        }
        return $lastTwo;
    }
}

if (!function_exists('deploymentIngressBaseDomains')) {
    function deploymentIngressBaseDomains(KubernetesClient $k8s, string $namespace): array
    {
        if ($namespace === '') {
            return [];
        }
        try {
            $ingresses = $k8s->listIngresses($namespace);
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
            $ns = rawurlencode($namespace);
            $ingresses = $k8s->get("/apis/extensions/v1beta1/namespaces/{$ns}/ingresses?limit=500");
        }
        $hosts = [];
        foreach (($ingresses['items'] ?? []) as $ingress) {
            if (!is_array($ingress)) continue;
            $spec = $ingress['spec'] ?? [];
            if (!is_array($spec)) continue;
            foreach (($spec['rules'] ?? []) as $rule) {
                $h = is_array($rule) ? (string)($rule['host'] ?? '') : '';
                if ($h !== '') $hosts[] = $h;
            }
            foreach (($spec['tls'] ?? []) as $tlsEntry) {
                $tlsHosts = is_array($tlsEntry) ? ($tlsEntry['hosts'] ?? []) : [];
                if (!is_array($tlsHosts)) continue;
                foreach ($tlsHosts as $h) {
                    $h = (string)$h;
                    if ($h !== '') $hosts[] = $h;
                }
            }
        }
        $baseDomains = [];
        foreach ($hosts as $h) {
            $bd = deploymentBaseDomainFromHost($h);
            if ($bd !== '') $baseDomains[$bd] = true;
        }
        $domains = array_keys($baseDomains);
        sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
        return $domains;
    }
}

/* ── Namespace utilisateur : via le helper partagé (était dupliqué inline) ── */
$userNamespace = sessionUserNamespace();

$deploymentParam = $_GET['deployment'] ?? $_GET['name'] ?? '';
$deploymentName  = is_string($deploymentParam) ? $deploymentParam : '';

/* Redirige ?name= → ?deployment= pour une URL canonique. */
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

/* ── CSRF : via le helper partagé (création/lecture centralisée) ── */
$csrfToken = sessionUserCsrf();

$k8sError       = null;
$deploymentData = null;
$storageMounts  = [];
$claims         = [];
$k8s_ingress_base_domains = [];

try {
    $k8s            = new KubernetesClient();
    $deploymentData = $k8s->getDeployment($userNamespace, $deploymentName);
    $k8s_ingress_base_domains = deploymentIngressBaseDomains($k8s, $userNamespace);

    $volumes = $deploymentData['spec']['template']['spec']['volumes'] ?? [];
    if (!is_array($volumes)) $volumes = [];

    $pvcByVolumeName = [];
    foreach ($volumes as $volume) {
        if (!is_array($volume)) continue;
        $volumeName = $volume['name'] ?? null;
        $claimName  = $volume['persistentVolumeClaim']['claimName'] ?? null;
        if (!is_string($volumeName) || $volumeName === '' || !is_string($claimName) || $claimName === '') continue;
        $claims[$claimName] = true;
        $pvcByVolumeName[$volumeName] = [
            'volumeName' => $volumeName,
            'claimName'  => $claimName,
            'readOnly'   => (bool)($volume['persistentVolumeClaim']['readOnly'] ?? false),
        ];
    }

    $containers = $deploymentData['spec']['template']['spec']['containers'] ?? [];
    if (!is_array($containers)) $containers = [];

    foreach ($containers as $container) {
        if (!is_array($container)) continue;
        $containerName = $container['name'] ?? null;
        if (!is_string($containerName) || $containerName === '') continue;
        $volumeMounts = $container['volumeMounts'] ?? [];
        if (!is_array($volumeMounts)) $volumeMounts = [];
        foreach ($volumeMounts as $mount) {
            if (!is_array($mount)) continue;
            $volumeName = $mount['name'] ?? null;
            $mountPath  = $mount['mountPath'] ?? null;
            if (!is_string($volumeName) || $volumeName === '' || !isset($pvcByVolumeName[$volumeName])) continue;
            if (!is_string($mountPath) || $mountPath === '') continue;
            $meta = $pvcByVolumeName[$volumeName];
            $storageMounts[] = [
                'container'  => $containerName,
                'volumeName' => $meta['volumeName'],
                'claimName'  => $meta['claimName'],
                'mountPath'  => $mountPath,
                'subPath'    => is_string($mount['subPath'] ?? null) ? $mount['subPath'] : null,
                'readOnly'   => (bool)($mount['readOnly'] ?? false) || (bool)$meta['readOnly'],
            ];
        }
    }
} catch (Throwable $e) {
    /* Amélioration sécurité : on journalise le détail, on n'expose qu'un message neutre. */
    error_log('[deployment.php] Erreur Kubernetes (' . $deploymentName . ') : ' . $e->getMessage());
    $k8sError = "Impossible de récupérer l'état du déploiement pour le moment. Réessayez dans quelques instants.";
}

$mountsCount = count($storageMounts);

/* Garde explicite : si erreur, $deploymentData est null → on ne déréférence pas. */
$replicas  = ($k8sError === null && is_array($deploymentData)) ? (int)($deploymentData['spec']['replicas'] ?? 0) : 0;
$ready     = ($k8sError === null && is_array($deploymentData)) ? (int)($deploymentData['status']['readyReplicas'] ?? 0) : 0;
$updated   = ($k8sError === null && is_array($deploymentData)) ? (int)($deploymentData['status']['updatedReplicas'] ?? 0) : 0;
$available = ($k8sError === null && is_array($deploymentData)) ? (int)($deploymentData['status']['availableReplicas'] ?? 0) : 0;

$deploymentStatusLabel     = 'État indisponible';
$deploymentStatusIconColor = '#ef4444';

if ($k8sError === null) {
    if ($replicas > 0 && $ready >= $replicas && $available >= $replicas) {
        $deploymentStatusLabel     = 'Déploiement opérationnel';
        $deploymentStatusIconColor = '#22c55e';
    } elseif ($ready > 0 || $updated > 0 || $available > 0) {
        $deploymentStatusLabel     = 'Déploiement en cours';
        $deploymentStatusIconColor = '#3b82f6';
    } else {
        $deploymentStatusLabel     = 'Service non démarré';
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
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height,0px));min-height:calc(100dvh - var(--app-header-height,0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}
    @media(max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto!important;}
      .dashboard-main{padding:1rem;}
    }
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}
    .widget-hero-icon{width:.75rem;height:.75rem;flex:0 0 .75rem;display:block;}
    .widget-back-icon{width:1rem;height:1rem;flex:0 0 1rem;display:block;}
    .storage-grid{display:flex;flex-direction:column;gap:16px;align-items:stretch;width:100%;}
    .storage-column{min-width:0;width:100%;}
    .crumbs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
    .crumb-sep{opacity:.55;}
    .explorer-path{display:flex;flex-wrap:wrap;align-items:center;gap:0;font-size:.875rem;color:inherit;}
    .explorer-path-prefix{margin-right:6px;color:inherit;font-weight:500;}
    .explorer-path-sep{opacity:.55;}
    .explorer-path-link{background:none;border:0;padding:0;margin:0;font:inherit;color:inherit;cursor:pointer;}
    .explorer-path-link:hover{text-decoration:underline;}
    .explorer-path-text{color:inherit;}
    .secret-env-row{display:grid;gap:.75rem 1rem;align-items:start;}
    .secret-env-meta,.secret-env-controls{min-width:0;}
    .secret-env-form{display:flex;width:100%;gap:.5rem;align-items:center;}
    .secret-env-input{min-width:0;width:100%;flex:1 1 auto;}
    .secret-env-button{flex:0 0 auto;}
    @media(min-width:1024px){
      #secretTools{--secret-meta-width:420px;}
      .secret-env-row{grid-template-columns:minmax(260px,var(--secret-meta-width)) minmax(0,1fr);}
    }
    @media(max-width:639px){
      .secret-env-form{flex-direction:column;align-items:stretch;}
      .secret-env-button{width:100%;}
    }
    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease,opacity 220ms ease;will-change:height,opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}
    @media(prefers-reduced-motion:reduce){.collapsible-content,.collapsible-trigger .collapsible-chevron{transition:none!important;}}

    /* ── htaccess editor ── */
    #htaccessEditor{
      font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
      font-size:.75rem;line-height:1.6;min-height:200px;max-height:70vh;width:100%;
      resize:vertical;white-space:pre;overflow:auto;border:none;outline:none;padding:1rem;border-radius:.5rem;
    }
    #htaccessEditor:focus{box-shadow:0 0 0 2px rgba(99,102,241,.4);}
    #htaccessEditor:read-only{opacity:.6;cursor:default;}
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main bg-surface">
      <div class="app-shell-offset-min-height w-full p-6">

        <?php if ($k8sError !== null): ?>

          <div class="bg-background rounded-xl border p-6 text-red-600">
            <strong>Erreur Kubernetes :</strong>
            <div class="mt-2 mono text-sm"><?= htmlspecialchars($k8sError, ENT_QUOTES, 'UTF-8') ?></div>
          </div>

        <?php else: ?>

          <!-- ══════════════════════════════════════════════
               HERO CARD
          ══════════════════════════════════════════════ -->
          <div class="w-full bg-surface">
            <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-6 rounded-xl group relative overflow-hidden border-0 shadow-lg transition-shadow hover:shadow-xl">
              <div class="absolute inset-0">
                <img src="https://images.unsplash.com/photo-1494984858525-798dd0b282f5?ixlib=rb-4.1.0&auto=format&fit=crop&q=80&w=2070" alt="" class="h-full w-full object-cover" />
                <div class="absolute inset-0 bg-gradient-to-r from-black/80 via-black/60 to-black/40 dark:from-black/90 dark:via-black/70 dark:to-black/50"></div>
              </div>

              <div data-slot="card-content" class="relative z-10 space-y-6 p-8 md:p-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                  <div class="space-y-3">
                    <h1 class="text-3xl font-bold text-white md:text-xl lg:text-2xl">
                      Service <span class="mono"><?= htmlspecialchars($deploymentName, ENT_QUOTES, 'UTF-8') ?></span>
                    </h1>
                    <p class="max-w-2xl text-base text-muted-foreground md:text-sm">
                      Namespace : <span class="mono"><?= htmlspecialchars($userNamespace, ENT_QUOTES, 'UTF-8') ?></span>
                    </p>
                  </div>

                  <div class="flex md:justify-end md:pt-1">
                    <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 gap-1 overflow-hidden border-transparent bg-white/20 text-white backdrop-blur-sm hover:bg-white/30">
                      <svg class="widget-hero-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="8" cy="8" r="7" fill="<?= htmlspecialchars($deploymentStatusIconColor, ENT_QUOTES, 'UTF-8') ?>"/>
                      </svg>
                      <?= htmlspecialchars($deploymentStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </div>
                </div>

                <div data-slot="separator" role="none" class="shrink-0 h-px w-full bg-white/20"></div>

                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                  <a href="/dashboard" class="flex items-center gap-2 text-sm text-muted-foreground hover:text-white transition-colors">
                    <svg class="widget-back-icon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                      <path d="M595.9 757L350.6 511.7l245.3-245.3 51.7 51.7L454 511.7l193.6 193.5z" fill="#ffffff"/>
                    </svg>
                    <span>Retour dashboard</span>
                  </a>

                  <div>
                    <button data-slot="button" id="restartBtn" class="h-9 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">
                      Redémarrer l'application
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- ══════════════════════════════════════════════
               URLs PUBLIQUES
          ══════════════════════════════════════════════ -->
          <div id="urlsCard" class="mt-4">
            <div id="publicUrls" class="flex flex-wrap gap-3 text-sm">
              <div class="text-muted-foreground">Chargement…</div>
            </div>
          </div>

          <!-- Logs -->
          <div class="mt-3 flex justify-end">
            <a class="inline-flex h-9 items-center justify-center rounded-md px-3 text-sm hover:bg-secondary transition-colors"
               href="/log?deployment=<?= urlencode($deploymentName) ?>">
              Accéder aux Logs →
            </a>
          </div>

          <!-- ══════════════════════════════════════════════
               CONFIGURATION APACHE (htaccess editor)
          ══════════════════════════════════════════════ -->
          <div class="bg-background rounded-xl border px-4 py-3 mt-6" id="htaccessCard">
            <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
              <div class="flex items-center gap-2 min-w-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shrink-0">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
                </svg>
                <span class="text-sm font-medium shrink-0">Configuration Apache</span>
                <span id="htaccessConfigName" class="mono text-xs text-muted-foreground truncate"></span>
              </div>
              <div class="flex items-center gap-2 flex-wrap">
                <span id="htaccessStatus" class="text-xs text-muted-foreground"></span>
                <button type="button" id="htaccessReloadBtn" class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs hover:bg-secondary transition-colors">
                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 2v6h6"/><path d="M21 12A9 9 0 0 0 6 5.3L3 8"/><path d="M21 22v-6h-6"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/>
                  </svg>
                  Recharger
                </button>
                <button type="button" id="htaccessSaveBtn" class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs hover:bg-secondary transition-colors disabled:opacity-50 disabled:pointer-events-none" disabled>
                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                  </svg>
                  Enregistrer
                </button>
              </div>
            </div>

            <div id="htaccessValidation" class="hidden mb-2 rounded-md border px-3 py-2 text-xs"></div>

            <textarea id="htaccessEditor" class="bg-muted" spellcheck="false" placeholder="Chargement du ConfigMap…" readonly aria-label="Éditeur de configuration Apache"></textarea>

            <div class="mt-2 flex items-center justify-between gap-2 flex-wrap">
              <div id="htaccessMeta" class="text-xs text-muted-foreground mono"></div>
              <div id="htaccessSaveMsg" class="text-xs text-muted-foreground"></div>
            </div>
          </div>

          <!-- ══════════════════════════════════════════════
               VARIABLES SECRÈTES
          ══════════════════════════════════════════════ -->
          <div class="mt-6" id="secretCard">
            <div id="secretTools" class="space-y-3">
              <div class="text-muted-foreground text-sm">Chargement…</div>
            </div>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 mt-4">
              <button type="button" id="secretCreateToggle" class="h-9 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Nouvelle variable</button>
            </div>
            <div id="secretCreatePanel" class="bg-background mb-4 hidden rounded-lg border p-4">
              <div class="grid gap-3 md:grid-cols-2">
                <label class="text-sm">
                  <span class="mb-1 block text-xs text-muted-foreground">Nom de la variable</span>
                  <input id="secretCreateEnv" type="text" class="h-10 w-full rounded-md border bg-background px-3 text-sm" placeholder="ex : API_TOKEN" />
                </label>
                <label class="text-sm">
                  <span class="mb-1 block text-xs text-muted-foreground">Valeur initiale masquée (optionnel)</span>
                  <input id="secretCreateValue" type="password" class="h-10 w-full rounded-md border bg-background px-3 text-sm" placeholder="Laisser vide pour créer une valeur vide" autocomplete="new-password" />
                </label>
                <label class="text-sm">
                  <span class="mb-1 block text-xs text-muted-foreground">Secret</span>
                  <select id="secretCreateSecret" class="h-10 w-full rounded-md border bg-background px-3 text-sm"></select>
                </label>
              </div>
              <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div id="secretCreateStatus" class="text-xs text-muted-foreground"></div>
                <button type="button" id="secretCreateSubmit" class="h-10 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Créer la variable</button>
              </div>
            </div>
          </div>

          <!-- ══════════════════════════════════════════════
               IMAGES / VERSION UPDATER
          ══════════════════════════════════════════════ -->
          <div class="mt-6" id="imageCard">
            <div id="imageTools" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              <div class="text-muted-foreground text-sm">Chargement…</div>
            </div>
          </div>

          <!-- Réseaux -->
          <div class="mt-3 flex justify-end">
            <a class="inline-flex h-9 items-center justify-center rounded-md px-3 text-sm hover:bg-secondary transition-colors"
               href="/network?deployment=<?= urlencode($deploymentName) ?>">
              Accéder aux Réseaux →
            </a>
          </div>

          <!-- ══════════════════════════════════════════════
               EXPLORATEUR DE FICHIERS
          ══════════════════════════════════════════════ -->
          <?php if ($mountsCount === 0): ?>
            <div class="bg-background rounded-xl border p-6 mt-6" id="storageExplorerCard">
              <h2 class="text-lg font-semibold mb-3">Explorateur de fichiers</h2>
              <p class="text-sm text-muted-foreground">
                Ce Deployment n'expose aucun volume de type <span class="mono">persistentVolumeClaim</span> dans son template de Pod.
              </p>
            </div>
          <?php else: ?>

            <!-- ╔══════════════════════════════════════════════════════════════╗
                 ║  ⟨ CONSERVE TON BLOC EXISTANT ⟩                                ║
                 ║  Recolle ici TOUT le markup de l'explorateur (barre de        ║
                 ║  recherche #explorerSearchInput, tri #explorerSort, onglets   ║
                 ║  de montage, fil d'Ariane, bouton #reloadDirBtn, résumé, et   ║
                 ║  le <table> avec <tbody id="fileListBody">…) tel quel.        ║
                 ╚══════════════════════════════════════════════════════════════╝ -->

          <?php endif; // fin du bloc EXPLORATEUR (mountsCount) ?>

        <?php endif; // fin du bloc if ($k8sError !== null) / else ?>

      </div>
    </main>
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       [A] MODAL GÉNÉRIQUE RÉUTILISABLE (un seul nœud pour tous les types)
  ══════════════════════════════════════════════════════════════ -->
  <div id="appModal"
       class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
       role="dialog" aria-modal="true" aria-labelledby="appModalTitle" aria-describedby="appModalBody">
    <div class="w-full max-w-md rounded-xl border bg-card text-card-foreground shadow-lg" data-modal-card>
      <div class="p-6">
        <div class="flex items-start gap-4">
          <span id="appModalIcon" class="mt-0.5 inline-flex h-9 w-9 flex-none items-center justify-center rounded-full" aria-hidden="true"></span>
          <div class="min-w-0 flex-1">
            <h2 id="appModalTitle" class="text-lg font-semibold">Titre</h2>
            <div id="appModalBody" class="mt-2 text-sm text-muted-foreground"></div>
            <div id="appModalConfirmField" class="mt-4 hidden">
              <label id="appModalConfirmLabel" for="appModalConfirmInput" class="mb-2 block text-sm font-semibold"></label>
              <input id="appModalConfirmInput" type="text" autocomplete="off" class="h-10 w-full rounded-md border bg-background px-3 text-sm" />
            </div>
            <div id="appModalStatus" class="mt-3 text-xs text-muted-foreground"></div>
          </div>
          <button type="button" id="appModalClose" class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary" aria-label="Fermer">Fermer</button>
        </div>
        <div id="appModalFooter" class="mt-6 flex items-center justify-end gap-2">
          <button type="button" id="appModalCancel" class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary">Annuler</button>
          <button type="button" id="appModalConfirm" class="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-white transition-all disabled:opacity-50">
            <svg id="appModalSpinner" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25"/>
              <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <span id="appModalConfirmLabelText">Confirmer</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       VARIABLES JS GLOBALES
  ══════════════════════════════════════════════════════════════ -->
  <script>
    const DEPLOYMENT_NAME = <?= json_encode($deploymentName,                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const USER_NAMESPACE  = <?= json_encode($userNamespace,                 JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const CSRF_TOKEN      = <?= json_encode($csrfToken,                     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const DETECTED_MOUNTS = <?= json_encode(array_values($storageMounts),   JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       [B] CONTRÔLEUR DE MODAL  → window.Modal
  ══════════════════════════════════════════════════════════════ -->
  <script>
  window.Modal = (function () {
    const root = document.getElementById('appModal');
    if (!root) return { open(){}, info(){}, success(){}, warning(){}, error(){}, confirm(){return Promise.resolve(false);}, loading(){}, close(){} };

    const card = root.querySelector('[data-modal-card]');
    const iconEl = document.getElementById('appModalIcon');
    const titleEl = document.getElementById('appModalTitle');
    const bodyEl = document.getElementById('appModalBody');
    const statusEl = document.getElementById('appModalStatus');
    const footer = document.getElementById('appModalFooter');
    const closeBtn = document.getElementById('appModalClose');
    const cancel = document.getElementById('appModalCancel');
    const confirm = document.getElementById('appModalConfirm');
    const confirmLbl = document.getElementById('appModalConfirmLabelText');
    const spinner = document.getElementById('appModalSpinner');
    const field = document.getElementById('appModalConfirmField');
    const fieldLbl = document.getElementById('appModalConfirmLabel');
    const fieldInput = document.getElementById('appModalConfirmInput');

    const VARIANTS = {
      info:    { ring:'bg-blue-100 text-blue-600',    btn:'bg-blue-600 hover:bg-blue-700' },
      success: { ring:'bg-green-100 text-green-600',  btn:'bg-green-600 hover:bg-green-700' },
      warning: { ring:'bg-amber-100 text-amber-600',  btn:'bg-amber-600 hover:bg-amber-700' },
      error:   { ring:'bg-red-100 text-red-600',      btn:'bg-red-600 hover:bg-red-700' },
      danger:  { ring:'bg-red-100 text-red-600',      btn:'bg-red-600 hover:bg-red-700' },
      neutral: { ring:'bg-secondary text-foreground', btn:'bg-primary hover:bg-primary/90' },
    };
    const ICONS = {
      info:    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      success: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      warning: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4l9 16H3z" stroke-linejoin="round"/><path d="M12 10v4M12 17h.01" stroke-linecap="round"/></svg>',
      error:   '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6" stroke-linecap="round"/></svg>',
      danger:  '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      neutral: '',
    };

    let resolver = null, busy = false, lastFocused = null, requireText = null;
    const isOpen = () => root.classList.contains('flex');

    const setStatus = (text, kind) => {
      const cls = { ok:'text-green-600', warn:'text-amber-600', err:'text-red-600' }[kind] || 'text-muted-foreground';
      statusEl.className = 'mt-3 text-xs ' + cls;
      statusEl.textContent = text || '';
    };
    const setBusy = (v) => {
      busy = v;
      confirm.disabled = v || (requireText && fieldInput.value.trim() !== requireText);
      cancel.disabled = v; closeBtn.disabled = v; fieldInput.disabled = v;
      spinner.classList.toggle('hidden', !v);
    };
    const applyVariant = (variant) => {
      const v = VARIANTS[variant] || VARIANTS.neutral;
      iconEl.className = 'mt-0.5 inline-flex h-9 w-9 flex-none items-center justify-center rounded-full ' + v.ring;
      iconEl.innerHTML = ICONS[variant] || ICONS.neutral;
      iconEl.classList.toggle('hidden', !ICONS[variant]);
      confirm.className = 'inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-white transition-all disabled:opacity-50 ' + v.btn;
    };
    const close = (result) => {
      if (busy) return;
      root.classList.remove('flex'); root.classList.add('hidden');
      field.classList.add('hidden'); fieldInput.value = ''; requireText = null; setStatus('');
      const r = resolver; resolver = null;
      if (lastFocused && lastFocused.focus) { try { lastFocused.focus(); } catch (_) {} }
      if (r) r(result === true);
    };
    const onKeydown = (e) => {
      if (!isOpen()) return;
      if (e.key === 'Escape') { e.preventDefault(); close(false); return; }
      if (e.key !== 'Tab') return;
      const items = card.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      const visible = Array.from(items).filter(el => !el.disabled && el.offsetParent !== null);
      if (!visible.length) return;
      const first = visible[0], last = visible[visible.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };

    document.addEventListener('keydown', onKeydown);
    root.addEventListener('click', (e) => { if (e.target === root) close(false); });
    closeBtn.addEventListener('click', () => close(false));
    cancel.addEventListener('click', () => close(false));
    fieldInput.addEventListener('input', () => { confirm.disabled = requireText ? (fieldInput.value.trim() !== requireText) : false; });

    function open(opts) {
      opts = opts || {};
      const variant = opts.danger ? 'danger' : (opts.variant || 'neutral');
      lastFocused = document.activeElement;
      footer.classList.remove('hidden');
      applyVariant(variant);
      titleEl.textContent = opts.title || '';
      bodyEl.innerHTML = opts.body || '';
      setStatus('');
      confirmLbl.textContent = opts.confirmText || 'Confirmer';
      cancel.textContent = opts.cancelText || 'Annuler';
      cancel.classList.toggle('hidden', opts.showCancel === false);

      requireText = opts.requireText || null;
      if (requireText) {
        field.classList.remove('hidden');
        fieldLbl.textContent = opts.requireLabel || 'Saisissez pour confirmer';
        fieldInput.placeholder = requireText; fieldInput.value = ''; confirm.disabled = true;
      } else {
        field.classList.add('hidden'); confirm.disabled = false;
      }

      setBusy(false);
      root.classList.remove('hidden'); root.classList.add('flex');
      requestAnimationFrame(() => (requireText ? fieldInput : confirm).focus());

      return new Promise((resolve) => {
        resolver = resolve;
        const doConfirm = async () => {
          if (confirm.disabled) return;
          if (requireText && fieldInput.value.trim() !== requireText) {
            setStatus('Le texte saisi ne correspond pas.', 'err');
            fieldInput.focus(); fieldInput.select(); return;
          }
          if (typeof opts.onConfirm !== 'function') { close(true); return; }
          setBusy(true); setStatus(opts.pendingText || 'Traitement en cours…', '');
          try { await opts.onConfirm(); setBusy(false); close(true); }
          catch (err) { setBusy(false); setStatus('Erreur : ' + (err && err.message ? err.message : String(err)), 'err'); }
        };
        confirm.onclick = doConfirm;
        fieldInput.onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); doConfirm(); } };
      });
    }

    const simple = (variant, defTitle) => (message, o) => open(Object.assign(
      { variant, title: (o && o.title) || defTitle, body: message, confirmText: (o && o.confirmText) || 'OK', showCancel: false }, o || {}
    ));

    return {
      open,
      info:    simple('info',    'Information'),
      success: simple('success', 'Opération réussie'),
      warning: simple('warning', 'Attention'),
      error:   simple('error',   'Une erreur est survenue'),
      confirm: (o) => open(Object.assign({ variant:'neutral' }, o)),
      loading: (message, title) => {
        lastFocused = document.activeElement;
        applyVariant('neutral');
        titleEl.textContent = title || 'Veuillez patienter';
        bodyEl.innerHTML = ''; setStatus(message || 'Chargement…', '');
        footer.classList.add('hidden');
        iconEl.className = 'mt-0.5 inline-flex h-9 w-9 flex-none items-center justify-center rounded-full bg-secondary text-foreground';
        iconEl.innerHTML = '<svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';
        iconEl.classList.remove('hidden');
        root.classList.remove('hidden'); root.classList.add('flex');
      },
      close: () => { footer.classList.remove('hidden'); busy = false; close(false); },
    };
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       [E] SÉCURITÉ : wrapper fetch + détection session/CSRF expirée
       apiFetch() centralise "fetch → parse JSON → throw" et ouvre un
       modal clair en cas de 401/403 ou d'erreur CSRF.
  ══════════════════════════════════════════════════════════════ -->
  <script>
  async function apiFetch(url, options) {
    const res = await fetch(url, Object.assign({ credentials:'same-origin' }, options || {}));
    const raw = await res.text();
    let data = null; try { data = JSON.parse(raw); } catch (_) {}

    const looksSecurity = res.status === 401 || res.status === 403 ||
      (data && typeof data.error === 'string' && /csrf|session/i.test(data.error));

    if (looksSecurity) {
      Modal.open({
        variant:'warning', title:'Session expirée',
        body:"Votre session ou votre jeton de sécurité n'est plus valide. Rechargez la page puis reconnectez-vous si nécessaire.",
        confirmText:'Recharger la page', showCancel:false,
      }).then(() => window.location.reload());
      throw new Error(data && data.error ? data.error : ('HTTP ' + res.status));
    }
    if (!res.ok || !data || data.ok === false) {
      throw new Error((data && data.error) || ('HTTP ' + res.status));
    }
    return data;
  }
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       [C] REDÉMARRAGE  (confirmation AVANT → chargement → succès/erreur)
  ══════════════════════════════════════════════════════════════ -->
  <script>
  (function () {
    const btn = document.getElementById('restartBtn');
    if (!btn) return;
    btn.addEventListener('click', () => {
      Modal.open({
        danger: true,
        title: "Redémarrer l'application",
        body: 'Tous les pods de <strong class="mono">' + DEPLOYMENT_NAME + '</strong> vont être recréés. Une courte interruption de service est possible.',
        confirmText: 'Redémarrer',
        // Pour exiger la saisie du nom (sécurité renforcée), décommente :
        // requireText: DEPLOYMENT_NAME,
        // requireLabel: "Saisissez le nom de l'application pour confirmer",
        pendingText: 'Envoi de la demande de redémarrage…',
        onConfirm: async () => {
          const u = new URL('../data/k8s_api.php', window.location.href);
          u.searchParams.set('action', 'restart_deployment');
          await apiFetch(u.toString(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
            body: new URLSearchParams({ name: DEPLOYMENT_NAME }),
          });
        },
      }).then((ok) => {
        if (ok) Modal.success('Le redémarrage a été lancé. Le rollout peut prendre quelques instants.', { title: 'Redémarrage en cours' });
      });
    });
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       VARIABLES SECRÈTES  (bloc complet — [D] suppression via Modal)
  ══════════════════════════════════════════════════════════════ -->
  <script>
  (function(){
    const host          = document.getElementById('secretTools');
    const createToggle  = document.getElementById('secretCreateToggle');
    const createPanel   = document.getElementById('secretCreatePanel');
    const createEnv     = document.getElementById('secretCreateEnv');
    const createSecret  = document.getElementById('secretCreateSecret');
    const createValue   = document.getElementById('secretCreateValue');
    const createStatus  = document.getElementById('secretCreateStatus');
    const createSubmit  = document.getElementById('secretCreateSubmit');
    if (!host) return;

    const apiUrl = new URL('../data/k8s_api.php', window.location.href);
    apiUrl.searchParams.set('action', 'list_deployment_secret_variables');
    apiUrl.searchParams.set('deployment', DEPLOYMENT_NAME);

    const escHtml = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    const setMsg = (el, text, kind='muted') => {
      if (!el) return;
      el.className = 'text-xs ' + ({ok:'text-emerald-600',warn:'text-amber-600',err:'text-red-600'}[kind]||'text-muted-foreground');
      el.textContent = text;
    };
    const readJson = async (res, url) => {
      const ct = (res.headers.get('content-type')||'').toLowerCase(), raw = await res.text();
      let data=null; try{data=JSON.parse(raw);}catch(_){}
      if (!ct.includes('application/json')||!data) throw new Error(`Réponse non-JSON (${res.status}). URL: ${url.pathname}. `+raw.slice(0,200).replace(/\s+/g,' '));
      if (!res.ok||!data.ok) throw new Error(data.error||('HTTP '+res.status));
      return data;
    };

    const setCreatePanelOpen = (open) => {
      if (!createPanel) return;
      createPanel.classList.toggle('hidden',!open);
      if (createToggle) createToggle.textContent = open?'Fermer':'Nouvelle variable';
    };
    const populateSecretOptions = (secrets) => {
      if (!createSecret) return;
      createSecret.innerHTML='';
      if (!Array.isArray(secrets)||secrets.length===0) {
        const o=document.createElement('option'); o.value=''; o.textContent='Aucun secret disponible'; createSecret.appendChild(o);
        createSecret.disabled=true; if(createSubmit) createSubmit.disabled=true; return;
      }
      createSecret.disabled=false; if(createSubmit) createSubmit.disabled=false;
      for (const name of secrets) { const o=document.createElement('option'); o.value=name; o.textContent=name; createSecret.appendChild(o); }
    };

    const buildRow = (entry) => {
      const id='secret_'+[entry.container,entry.envName,entry.secretName,entry.secretKey].join('_').replace(/[^a-z0-9_-]/gi,'_');
      const wrap=document.createElement('div'); wrap.className='bg-background rounded-lg border p-4 mt-4';
      wrap.innerHTML=`
        <div class="secret-env-row">
          <div class="secret-env-meta">
            <div class="text-sm font-medium">Variable d'environnement : <span class="mono">${escHtml(entry.envName||'')}</span></div>
            <div class="text-xs text-muted-foreground mt-1">• Secret : <span class="mono">${escHtml(entry.secretName||'')}</span></div>
          </div>
          <div class="secret-env-controls">
            <label class="sr-only" for="${id}_value">Nouvelle valeur pour ${escHtml(entry.envName||'')}</label>
            <div class="secret-env-form">
              <input id="${id}_value" type="password" class="secret-env-input h-10 rounded-md border bg-background px-3 text-sm"
                placeholder="Valeur actuelle masquée — saisir une nouvelle valeur" autocomplete="new-password"/>
              <button type="button" data-action="save" class="secret-env-button h-10 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Enregistrer</button>
              <button type="button" data-action="delete" class="secret-env-button h-10 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Supprimer</button>
            </div>
            <div class="mt-2 text-xs text-muted-foreground" id="${id}_status"></div>
          </div>
        </div>`;

      const input=wrap.querySelector('#'+id+'_value'), saveBtn=wrap.querySelector('[data-action="save"]'), deleteBtn=wrap.querySelector('[data-action="delete"]'), status=wrap.querySelector('#'+id+'_status');
      const canDelete=entry.source==='secretRef';
      if(deleteBtn&&!canDelete){deleteBtn.disabled=true;deleteBtn.title='Suppression indisponible pour les variables définies directement dans le deployment.';}

      const submit=async()=>{
        if(!input||!saveBtn) return;
        const value=input.value;
        if(!value){setMsg(status,"Saisis une nouvelle valeur avant d'enregistrer.",'warn');return;}
        saveBtn.disabled=true; if(deleteBtn) deleteBtn.disabled=true; input.disabled=true; setMsg(status,'Mise à jour du secret…','muted');
        try {
          const u=new URL('../data/k8s_api.php',window.location.href); u.searchParams.set('action','update_deployment_secret_variable');
          await apiFetch(u.toString(),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF_TOKEN},body:new URLSearchParams({name:DEPLOYMENT_NAME,container:entry.container||'',env:entry.envName||'',secret:entry.secretName||'',key:entry.secretKey||'',value})});
          input.value=''; setMsg(status,'Valeur enregistrée. La valeur existante reste masquée dans le portail.','ok');
        } catch(e){ setMsg(status,'Erreur : '+(e?.message||String(e)),'err'); Modal.error(e?.message||String(e)); }
        finally{saveBtn.disabled=false;if(deleteBtn) deleteBtn.disabled=false;input.disabled=false;}
      };

      // [D] Suppression : confirmation par saisie + résultat affiché DANS le modal.
      const removeVariable=async()=>{
        if(!canDelete){setMsg(status,'Suppression indisponible pour cette variable.','warn');return;}
        const ok = await Modal.open({
          danger:true,
          title:'Suppression de la variable',
          body:'Cette action est irréversible. Saisissez le nom de la variable pour confirmer.',
          requireText:String(entry.envName||'').trim(),
          requireLabel:'Nom de la variable ('+(entry.envName||'')+')',
          confirmText:'Supprimer',
          pendingText:'Suppression de la variable…',
          onConfirm: async () => {
            const u=new URL('../data/k8s_api.php',window.location.href); u.searchParams.set('action','delete_deployment_secret_variable');
            await apiFetch(u.toString(),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF_TOKEN},body:new URLSearchParams({name:DEPLOYMENT_NAME,container:entry.container||'',env:entry.envName||'',secret:entry.secretName||'',key:entry.secretKey||''})});
          },
        });
        if (ok) { setMsg(status,'Variable supprimée.','ok'); await loadSecretVariables(); }
      };

      saveBtn?.addEventListener('click',submit);
      deleteBtn?.addEventListener('click',removeVariable);
      input?.addEventListener('keydown',(e)=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();submit();}});
      return wrap;
    };

    const syncSecretMetaWidth=()=>{
      const metas=Array.from(host.querySelectorAll('.secret-env-meta'));
      if(!metas.length){host.style.removeProperty('--secret-meta-width');return;}
      const widths=metas.map(el=>Math.ceil(el.getBoundingClientRect().width)), maxWidth=Math.max(...widths,260), clamped=Math.min(maxWidth,520);
      host.style.setProperty('--secret-meta-width',`${clamped}px`);
    };

    const renderList=(entries,secretErrors)=>{
      host.innerHTML='';
      if(!Array.isArray(entries)||entries.length===0) {host.innerHTML='<div class="text-sm text-muted-foreground">Aucune variable de secret détectée pour ce deployment.</div>';}
      else{for(const entry of entries) host.appendChild(buildRow(entry));syncSecretMetaWidth();}
      const errors=secretErrors&&typeof secretErrors==='object'?Object.entries(secretErrors):[];
      if(errors.length>0){
        const alert=document.createElement('div'); alert.className='rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800';
        alert.innerHTML=`<div class="font-medium">Certains secrets n'ont pas pu être inspectés.</div><ul class="mt-2 list-disc pl-5">${errors.map(([name,error])=>`<li><span class="mono">${escHtml(name)}</span> : ${escHtml(error)}</li>`).join('')}</ul>`;
        host.appendChild(alert);
      }
    };
    window.addEventListener('resize',syncSecretMetaWidth);

    const loadSecretVariables=async()=>{
      const res=await fetch(apiUrl.toString(),{credentials:'same-origin'}); const data=await readJson(res,apiUrl);
      populateSecretOptions(Array.isArray(data.secrets)?data.secrets:[]);
      renderList(Array.isArray(data.entries)?data.entries:[],data.secretErrors);
    };

    const resetCreateForm=()=>{ if(createEnv) createEnv.value=''; if(createSecret) createSecret.value=''; if(createValue) createValue.value=''; };

    const createVariable=async()=>{
      const payload={name:DEPLOYMENT_NAME,env:createEnv?createEnv.value.trim():'',secret:createSecret?createSecret.value.trim():'',value:createValue?createValue.value:''};
      if(!payload.env||!payload.secret){setMsg(createStatus,'Renseigne la variable / clé et le secret.','warn');return;}
      if(createSubmit) createSubmit.disabled=true; setMsg(createStatus,'Création de la variable dans le secret existant…','muted');
      try {
        const u=new URL('../data/k8s_api.php',window.location.href); u.searchParams.set('action','create_deployment_secret_variable');
        const data=await apiFetch(u.toString(),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF_TOKEN},body:new URLSearchParams(payload)});
        resetCreateForm();
        setMsg(createStatus,data?.deploymentRestarted?'Variable créée. Le déploiement redémarre automatiquement.':'Variable créée dans le secret.','ok');
        await loadSecretVariables();
      } catch(e){ setMsg(createStatus,'Erreur : '+(e?.message||String(e)),'err'); }
      finally{if(createSubmit) createSubmit.disabled=false;}
    };

    createToggle?.addEventListener('click',()=>{const open=createPanel?createPanel.classList.contains('hidden'):false;setCreatePanelOpen(open);});
    createSubmit?.addEventListener('click',createVariable);

    (async()=>{ try{await loadSecretVariables();}catch(e){host.innerHTML=`<div class="text-sm text-red-600"><strong>Erreur :</strong> ${escHtml(e?.message||String(e))}</div>`;populateSecretOptions([]);} })();
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       IMAGES / VERSION UPDATER
  ══════════════════════════════════════════════════════════════ -->
  <!-- ╔════════════════════════════════════════════════════════════════╗
       ║  ⟨ CONSERVE TON BLOC EXISTANT ⟩                                  ║
       ║  Recolle ici le <script> complet du "Version Updater" (IIFE      ║
       ║  sur #imageTools : buildRow, postUpdate, boot async).            ║
       ║  Conseil : remplace ses fetch internes par apiFetch(...) et      ║
       ║  route les erreurs vers Modal.error(...) pour homogénéiser.      ║
       ╚════════════════════════════════════════════════════════════════╝ -->

  <!-- ══════════════════════════════════════════════════════════════
       CONFIGURATION APACHE (htaccess editor)
  ══════════════════════════════════════════════════════════════ -->
  <!-- ╔════════════════════════════════════════════════════════════════╗
       ║  ⟨ CONSERVE TON BLOC EXISTANT ⟩                                  ║
       ║  Recolle ici le <script> de l'éditeur Apache (loadConfigMap,     ║
       ║  saveConfigMap, validateApacheConf, showValidation, wiring des   ║
       ║  boutons #htaccessReloadBtn / #htaccessSaveBtn).                 ║
       ╚════════════════════════════════════════════════════════════════╝ -->

  <!-- ══════════════════════════════════════════════════════════════
       URLs PUBLIQUES
  ══════════════════════════════════════════════════════════════ -->
  <!-- ╔════════════════════════════════════════════════════════════════╗
       ║  ⟨ CONSERVE TON BLOC EXISTANT ⟩                                  ║
       ║  Recolle ici le <script> IIFE sur #publicUrls (badge, rendu des  ║
       ║  Ingress, etc.).                                                 ║
       ╚════════════════════════════════════════════════════════════════╝ -->

  <!-- ══════════════════════════════════════════════════════════════
       EXPLORATEUR DE FICHIERS
  ══════════════════════════════════════════════════════════════ -->
  <!-- ╔════════════════════════════════════════════════════════════════╗
       ║  ⟨ CONSERVE TON BLOC EXISTANT ⟩                                  ║
       ║  Recolle ici le <script> IIFE de l'explorateur (loadStorageMeta, ║
       ║  loadDirectory, renderMountTabs, renderBreadcrumbs, normalizePath║
       ║  formatBytes, getApiUrl, etc.). Il consomme DETECTED_MOUNTS.     ║
       ╚════════════════════════════════════════════════════════════════╝ -->

  <!-- ══════════════════════════════════════════════════════════════
       COLLAPSIBLES (générique)
  ══════════════════════════════════════════════════════════════ -->
  <script>
  (function(){
    function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded',fn); }
    ready(function(){
      document.querySelectorAll('[data-slot="collapsible-trigger"]').forEach(function(btn){
        btn.classList.add('collapsible-trigger');
        const targetId = btn.getAttribute('aria-controls') || btn.getAttribute('data-target');
        const content  = targetId ? document.getElementById(targetId) : null;
        if (!content) return;
        content.classList.add('collapsible-content');
        const expanded = btn.getAttribute('aria-expanded') === 'true';
        content.style.height = expanded ? 'auto' : '0';
        content.classList.toggle('is-open', expanded);
        btn.addEventListener('click', function(){
          const isOpen = btn.getAttribute('aria-expanded') === 'true';
          btn.setAttribute('aria-expanded', String(!isOpen));
          if (isOpen) {
            content.style.height = content.scrollHeight + 'px';
            requestAnimationFrame(() => { content.style.height = '0'; });
            content.classList.remove('is-open');
          } else {
            content.classList.add('is-open');
            content.style.height = content.scrollHeight + 'px';
            content.addEventListener('transitionend', function te(){ content.style.height = 'auto'; content.removeEventListener('transitionend', te); });
          }
        });
      });
    });
  })();
  </script>

</body>
</html>