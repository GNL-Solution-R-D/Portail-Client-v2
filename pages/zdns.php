<?php

declare(strict_types=1);

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit;
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit;
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

// Jeton CSRF (partagé avec le proxy data/domains_api.php)
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf'];

// Namespace Kubernetes (repris de network.php) — requis pour la gestion des URLs publiques / Ingress
$namespace = $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['k8sNamespace']
    ?? $_SESSION['user']['namespace_k8s']
    ?? $_SESSION['user']['k8s_ns']
    ?? $_SESSION['user']['namespace']
    ?? '';

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function zdns_is_domain(string $v): bool
{
    $v = rtrim(strtolower(trim($v)), '.');
    if ($v === '' || strlen($v) > 253) {
        return false;
    }
    return (bool) preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $v);
}

$domainParam = $_GET['domain'] ?? '';
$domain = is_string($domainParam) ? rtrim(strtolower(trim($domainParam)), '.') : '';
$domainValid = zdns_is_domain($domain);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo $domainValid ? h($domain) . ' — ' : ''; ?><?= t('Zone DNS — GNL Solution') ?></title>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css" />
  <style>
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height,0px));min-height:calc(100dvh - var(--app-header-height,0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}
    @media(max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main{padding:1rem;}
    }
    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease,opacity 220ms ease;will-change:height,opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}
    @media(prefers-reduced-motion:reduce){.collapsible-content,.collapsible-trigger .collapsible-chevron{transition:none !important;}}

    .zone-table{width:100%;border-collapse:separate;border-spacing:0;min-width:640px;}
    .zone-table th,.zone-table td{padding:.75rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.9rem;text-align:left;vertical-align:middle;}
    .zone-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.7rem;color:var(--muted-foreground,#64748b);}
    .zone-table tbody tr:hover{background:rgba(148,163,184,.08);}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}

    /* — Network / URLs publiques (repris de network.php) — */
    .net-row-grid{display:grid;gap:10px;align-items:center;}
    @media(min-width:1024px){
      .net-row-grid{grid-template-columns:2.2fr 1.2fr 1.6fr .8fr 1.4fr .9fr 1.6fr;}
    }
    .net-field{width:100%;height:36px;border-radius:8px;border:1px solid hsl(var(--border));background:hsl(var(--background));padding:0 10px;font-size:14px;}
    .net-field:disabled{opacity:.6;}
    .net-btn{height:36px;border-radius:8px;border:1px solid hsl(var(--border));padding:0 12px;font-size:13px;transition:background .15s ease;}
    .net-btn:hover{background:hsl(var(--secondary));}
    .net-btn:disabled{opacity:.6;cursor:not-allowed;}
    .net-badge{display:inline-flex;align-items:center;border-radius:999px;padding:2px 10px;font-size:12px;border:1px solid transparent;white-space:nowrap;}
    .net-b-ok{background:rgba(34,197,94,.12);color:rgb(22,163,74);}
    .net-b-warn{background:rgba(245,158,11,.12);color:rgb(217,119,6);}
    .net-b-err{background:rgba(239,68,68,.12);color:rgb(220,38,38);}
    .net-b-muted{background:hsl(var(--muted));color:hsl(var(--muted-foreground));}
    .net-actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;}
    @media(prefers-reduced-motion:reduce){.net-btn{transition:none;}}

    /* — Statut de liaison domaine ↔ déploiement : couleurs en thème sombre uniquement — */
    .dark [data-link-state="linked"]{
      border-color: transparent;
      background-color: rgba(0, 236, 125, 0.12);
      color: rgba(20, 218, 144, 1);
    }
    .dark [data-link-state="unlinked"]{
      border-color: transparent;
      background-color: rgba(255, 0, 5, 0.29);
      color: rgb(255, 182, 188);
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6 space-y-6">

        <?php if (!$domainValid): ?>
          <div data-slot="card" class="bg-background text-card-foreground rounded-xl border p-6 shadow-sm">
            <h1 class="text-lg font-semibold"><?= t('Zone DNS') ?></h1>
            <p class="mt-2 text-sm text-muted-foreground"><?= t('Domaine manquant ou invalide. Revenez au menu et sélectionnez un domaine.') ?></p>
            <a href="./dashboard" class="mt-4 inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('← Retour au tableau de bord') ?></a>
          </div>
        <?php else: ?>

          <!-- En-tête du domaine -->
          <div data-slot="card" class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
            <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
              <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground"><?= t('Zone DNS') ?></p>
                <div class="mt-1 flex items-center gap-2">
                  <h1 class="text-xl font-bold mono truncate"><?php echo h($domain); ?></h1>
                  <span class="inline-flex items-center gap-1 rounded-md border border-transparent bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path><path d="m9 11 3 3L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <?= t('Vérifié') ?>
                  </span>
                </div>
                <p class="mt-1 text-sm text-muted-foreground"><?= t('Gérez les enregistrements DNS de ce domaine.') ?></p>
              </div>
              <button type="button" data-copy-domain="<?php echo h($domain); ?>"
                class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary">Copier le domaine</button>
            </div>
          </div>

          <!-- Statut de liaison domaine ↔ déploiement -->
          <div data-domain-link data-domain="<?php echo h($domain); ?>">

            <!-- Vérification en cours -->
            <div data-link-state="checking" class="rounded-xl border p-5 flex items-center gap-3 text-sm text-muted-foreground">
              <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" stroke-opacity=".25"></circle><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>
              <span><?= t('Vérification de la liaison…') ?></span>
            </div>

            <!-- Domaine lié (vert) -->
            <div data-link-state="linked" hidden
                 class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 p-5 flex items-center justify-between gap-4 flex-wrap">
              <div class="flex items-center gap-3 min-w-0">
                <svg class="h-7 w-7 shrink-0" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M9.16488 17.6505C8.92513 17.8743 8.73958 18.0241 8.54996 18.1336C7.62175 18.6695 6.47816 18.6695 5.54996 18.1336C5.20791 17.9361 4.87912 17.6073 4.22153 16.9498C3.56394 16.2922 3.23514 15.9634 3.03767 15.6213C2.50177 14.6931 2.50177 13.5495 3.03767 12.6213C3.23514 12.2793 3.56394 11.9505 4.22153 11.2929L7.04996 8.46448C7.70755 7.80689 8.03634 7.47809 8.37838 7.28062C9.30659 6.74472 10.4502 6.74472 11.3784 7.28061C11.7204 7.47809 12.0492 7.80689 12.7068 8.46448C13.3644 9.12207 13.6932 9.45086 13.8907 9.7929C14.4266 10.7211 14.4266 11.8647 13.8907 12.7929C13.7812 12.9825 13.6314 13.1681 13.4075 13.4078M10.5919 10.5922C10.368 10.8319 10.2182 11.0175 10.1087 11.2071C9.57284 12.1353 9.57284 13.2789 10.1087 14.2071C10.3062 14.5492 10.635 14.878 11.2926 15.5355C11.9502 16.1931 12.279 16.5219 12.621 16.7194C13.5492 17.2553 14.6928 17.2553 15.621 16.7194C15.9631 16.5219 16.2919 16.1931 16.9495 15.5355L19.7779 12.7071C20.4355 12.0495 20.7643 11.7207 20.9617 11.3787C21.4976 10.4505 21.4976 9.30689 20.9617 8.37869C20.7643 8.03665 20.4355 7.70785 19.7779 7.05026C19.1203 6.39267 18.7915 6.06388 18.4495 5.8664C17.5212 5.3305 16.3777 5.3305 15.4495 5.8664C15.2598 5.97588 15.0743 6.12571 14.8345 6.34955" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>
                <p class="text-sm font-medium"><?= t('Domaine lié au déploiement') ?> <span class="mono font-semibold" data-link-deployment>—</span></p>
              </div>
              <button type="button" data-link-manage
                class="inline-flex h-9 items-center justify-center rounded-md border border-emerald-300 bg-white/60 px-3 text-sm font-medium text-emerald-800 transition-all hover:bg-white dark:bg-transparent dark:text-emerald-300 dark:border-emerald-800 dark:hover:bg-emerald-900/30"><?= t('Gérer les interconnexions') ?></button>
            </div>

            <!-- Domaine non lié (rouge) -->
            <div data-link-state="unlinked" hidden
                 class="rounded-xl border border-red-200 bg-red-50 text-red-800 p-5 flex items-center justify-between gap-4 flex-wrap">
              <div class="flex items-center gap-3 min-w-0">
                <svg class="h-7 w-7 shrink-0" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M7 7C4.23858 7 2 9.23858 2 12C2 14.7614 4.23858 17 7 17H9C11.1636 17 13.0062 15.6258 13.7026 13.7026M17 17H16.5M10 12C10 11.4021 10.1049 10.8288 10.2974 10.2974M21 21L13.7026 13.7026M3 3L10.2974 10.2974M10.2974 10.2974L13.7026 13.7026M13.0464 7.39604C13.6466 7.14106 14.3068 7 15 7H17C19.7614 7 22 9.23858 22 12C22 13.2151 21.5665 14.329 20.8458 15.1954" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>
                <p class="text-sm font-medium"><?= t('Domaine non lié à un déploiement') ?></p>
              </div>
              <button type="button" data-link-create
                class="inline-flex h-9 items-center justify-center rounded-md border border-red-300 bg-white/60 px-3 text-sm font-medium text-red-800 transition-all hover:bg-white dark:bg-transparent dark:text-red-300 dark:border-red-800 dark:hover:bg-red-900/30"><?= t('Lier le domaine') ?></button>
            </div>

          </div>

          <!-- Enregistrements DNS -->
          <div data-slot="card" class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
            <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
              <div>
                <h2 class="text-base font-semibold"><?= t('Enregistrements') ?></h2>
                <p class="text-sm text-muted-foreground" data-zone-count></p>
              </div>
              <button type="button" data-zone-add
                class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90"><?= t('Ajouter un enregistrement') ?></button>
            </div>

            <div class="px-2 md:px-6 mt-2">
              <div class="table-wrap overflow-x-auto">
                <table class="zone-table">
                  <thead>
                    <tr>
                      <th><?= t('Type') ?></th>
                      <th><?= t('Nom') ?></th>
                      <th><?= t('Valeur') ?></th>
                      <th><?= t('TTL') ?></th>
                      <th class="text-right"><?= t('Action') ?></th>
                    </tr>
                  </thead>
                  <tbody data-zone-body>
                    <tr><td colspan="5" class="text-sm text-muted-foreground"><?= t('Chargement…') ?></td></tr>
                  </tbody>
                </table>
              </div>
              <div data-zone-status class="mt-3 text-xs"></div>
            </div>
          </div>

          <!-- Network / URLs publiques (repris de network.php) -->
          <div id="netCard" data-slot="card" class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
            <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
              <div class="min-w-0">
                <h2 class="text-base font-semibold">Network <span class="text-muted-foreground">/ <?= t('URLs publiques') ?></span></h2>
                <p class="text-sm text-muted-foreground mt-1"><?= t('Liste des URLs publiques (host + path) et leur backend Service.') ?></p>
                <p class="text-xs text-muted-foreground mt-1"><?= t('Namespace:') ?> <span class="mono"><?php echo h((string) $namespace); ?></span></p>
              </div>
              <div class="flex items-center gap-2">
                <button type="button" id="netRefreshBtn" class="net-btn"><?= t('Rafraîchir') ?></button>
                <button type="button" id="netAddBtn" class="net-btn"><?= t('Ajouter une URL') ?></button>
              </div>
            </div>

            <div class="px-6 mt-4">
              <div id="netMsg" class="text-sm text-muted-foreground"></div>
              <div class="mt-4 space-y-3" id="netRows"></div>

              <div class="mt-5 text-xs text-muted-foreground">
                Notes :
                <ul class="list-disc pl-5 mt-2 space-y-1">
                  <li>Seuls les Ingress marqués <span class="mono">gnl-solution.fr/managed-by=dashboard</span> sont modifiables ici.</li>
                  <li>Le statut du certificat TLS est basé sur <span class="mono">cert-manager</span> si dispo, sinon sur le Secret TLS (<span class="mono">tls.crt</span>).</li>
                </ul>
              </div>
            </div>
          </div>

        <?php endif; ?>
      </div>
    </main>
  </div>

  <?php if ($domainValid): ?>
  <!-- Modal : ajouter un enregistrement -->
  <div id="zoneAddModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
       role="dialog" aria-modal="true" aria-labelledby="zoneAddTitle">
    <div class="w-full max-w-md rounded-xl border bg-card text-card-foreground shadow-lg">
      <div class="p-6">
        <div class="flex items-start justify-between gap-4">
          <h2 id="zoneAddTitle" class="text-lg font-semibold"><?= t('Ajouter un enregistrement') ?></h2>
          <button type="button" data-zone-close class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Fermer') ?></button>
        </div>
        <div class="mt-5 space-y-4">
          <div>
            <label for="zoneType" class="mb-1.5 block text-xs font-medium text-muted-foreground"><?= t('Type') ?></label>
            <select id="zoneType" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
              <option>A</option><option>AAAA</option><option>CNAME</option>
              <option>MX</option><option>TXT</option><option>NS</option><option>SRV</option><option>CAA</option>
            </select>
          </div>
          <div>
            <label for="zoneName" class="mb-1.5 block text-xs font-medium text-muted-foreground"><?= t('Nom') ?></label>
            <input id="zoneName" type="text" autocomplete="off" spellcheck="false" placeholder="<?= t('@ ou www') ?>"
              class="h-10 w-full rounded-md border bg-background px-3 text-sm" />
          </div>
          <div>
            <label for="zoneValue" class="mb-1.5 block text-xs font-medium text-muted-foreground"><?= t('Valeur') ?></label>
            <input id="zoneValue" type="text" autocomplete="off" spellcheck="false" placeholder="203.0.113.10"
              class="h-10 w-full rounded-md border bg-background px-3 text-sm" />
          </div>
          <div>
            <label for="zoneTtl" class="mb-1.5 block text-xs font-medium text-muted-foreground"><?= t('TTL (secondes)') ?></label>
            <input id="zoneTtl" type="number" min="60" step="1" value="3600"
              class="h-10 w-full rounded-md border bg-background px-3 text-sm" />
          </div>
          <div data-zone-add-error class="hidden text-xs text-red-600"></div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" data-zone-close class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Annuler') ?></button>
          <button type="button" data-zone-save class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90"><?= t('Enregistrer') ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal : lier le domaine (nouvelle entrée Ingress) -->
  <div id="linkFormModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
       role="dialog" aria-modal="true" aria-labelledby="linkFormTitle">
    <div class="w-full max-w-md rounded-xl border bg-card text-card-foreground shadow-lg">
      <div class="p-6">
        <div class="flex items-start justify-between gap-4">
          <h2 id="linkFormTitle" class="text-lg font-semibold"><?= t('Lier le domaine') ?></h2>
          <button type="button" data-link-form-close class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Fermer') ?></button>
        </div>
        <div class="mt-5 space-y-4">
          <div>
            <label for="linkHost" class="mb-1.5 block text-xs font-medium text-muted-foreground">Host</label>
            <input id="linkHost" type="text" autocomplete="off" spellcheck="false" placeholder="sub.example.fr/uri"
              class="h-10 w-full rounded-md border bg-background px-3 text-sm mono" />
            <p class="mt-1 text-xs text-muted-foreground"><?= t('Format :') ?> <span class="mono">sub.example.fr/uri</span></p>
          </div>
          <div>
            <label for="linkDeployment" class="mb-1.5 block text-xs font-medium text-muted-foreground"><?= t('Déploiement') ?></label>
            <select id="linkDeployment" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
              <option value="">…</option>
            </select>
          </div>
          <div>
            <label for="linkProtocol" class="mb-1.5 block text-xs font-medium text-muted-foreground"><?= t('Protocole (Port)') ?></label>
            <select id="linkProtocol" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
              <option value="80"><?= t('Site Internet (80)') ?></option>
              <option value="custom"><?= t('Autre…') ?></option>
            </select>
            <input id="linkPort" type="number" min="1" max="65535" step="1" placeholder="8080"
              class="mt-2 h-10 w-full rounded-md border bg-background px-3 text-sm hidden" />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input id="linkTls" type="checkbox" /> <span><?= t('SSL (TLS)') ?></span>
          </label>
          <div id="linkFormError" class="hidden text-xs text-red-600"></div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" data-link-form-close class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Annuler') ?></button>
          <button type="button" id="linkFormSave" class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90"><?= t('Enregistrer') ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal : gérer les interconnexions -->
  <div id="linkManageModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
       role="dialog" aria-modal="true" aria-labelledby="linkManageTitle">
    <div class="w-full max-w-2xl rounded-xl border bg-card text-card-foreground shadow-lg">
      <div class="p-6">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <h2 id="linkManageTitle" class="text-lg font-semibold"><?= t('Gérer les interconnexions') ?></h2>
            <p class="text-sm text-muted-foreground mt-1"><?= t('Ingress dont le host correspond à') ?>
              <span class="mono"><?php echo h($domain); ?></span> <?= t('ou') ?> <span class="mono">*.<?php echo h($domain); ?>/*</span></p>
          </div>
          <button type="button" data-link-manage-close class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Fermer') ?></button>
        </div>
        <div id="linkManageBody" class="mt-5 space-y-3 max-h-[60vh] overflow-auto"></div>
        <div id="linkManageStatus" class="mt-3 text-xs"></div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" id="linkManageAdd" class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Ajouter une URL') ?></button>
          <button type="button" data-link-manage-close class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90"><?= t('Fermer') ?></button>
        </div>
      </div>
    </div>
  </div>

  <script>
  (function () {
    const DOMAIN = <?php echo json_encode($domain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const CSRF   = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const API    = '../data/domains_api.php';

    const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

    async function apiCall(action, payload, method) {
      method = (method || 'POST').toUpperCase();
      const u = new URL(API, window.location.href);
      u.searchParams.set('action', action);
      const opts = { method, credentials: 'same-origin', headers: {} };
      if (method === 'GET') {
        Object.entries(payload || {}).forEach(([k, v]) => u.searchParams.set(k, v));
      } else {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        opts.headers['X-CSRF-Token'] = CSRF;
        opts.body = new URLSearchParams(payload || {});
      }
      const res = await fetch(u.toString(), opts);
      const ct = (res.headers.get('content-type') || '').toLowerCase();
      const raw = await res.text();
      let data = null; try { data = JSON.parse(raw); } catch (_) {}
      if (!ct.includes('application/json') || !data) throw new Error('Réponse non-JSON (' + res.status + ').');
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
      return data;
    }

    const body   = document.querySelector('[data-zone-body]');
    const countEl = document.querySelector('[data-zone-count]');
    const statusEl = document.querySelector('[data-zone-status]');

    function setStatus(text, kind) {
      if (!statusEl) return;
      statusEl.textContent = text || '';
      statusEl.className = 'mt-3 text-xs ' + (kind === 'err' ? 'text-red-600' : kind === 'ok' ? 'text-emerald-600' : 'text-muted-foreground');
    }

    function rowHtml(r) {
      const type = esc(r.type || r.record_type || '');
      const name = esc(r.name || r.host || '@');
      const val  = esc(r.content || r.value || r.data || '');
      const ttl  = esc(r.ttl != null ? r.ttl : '');
      const id   = esc(r.id != null ? r.id : '');
      return '<tr>' +
        '<td class="mono">' + type + '</td>' +
        '<td class="mono">' + name + '</td>' +
        '<td class="mono" style="word-break:break-all;">' + val + '</td>' +
        '<td class="mono">' + ttl + '</td>' +
        '<td class="text-right">' +
          '<button type="button" data-zone-del="' + id + '" data-row-label="' + type + ' ' + name + '" ' +
          'class="inline-flex h-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-all hover:bg-secondary">Supprimer</button>' +
        '</td></tr>';
    }

    async function loadRecords() {
      if (!body) return;
      body.innerHTML = '<tr><td colspan="5" class="text-sm text-muted-foreground">Chargement…</td></tr>';
      setStatus('');
      try {
        const data = await apiCall('records', { domain: DOMAIN }, 'GET');
        const rows = Array.isArray(data.records) ? data.records
                   : Array.isArray(data.domains) ? data.domains : [];
        if (countEl) countEl.textContent = rows.length + ' enregistrement(s)';
        if (rows.length === 0) {
          body.innerHTML = '<tr><td colspan="5" class="text-sm text-muted-foreground">Aucun enregistrement.</td></tr>';
          return;
        }
        body.innerHTML = rows.map(rowHtml).join('');
      } catch (e) {
        if (countEl) countEl.textContent = '';
        body.innerHTML = '<tr><td colspan="5" class="text-sm text-muted-foreground">Impossible de charger les enregistrements.</td></tr>';
        setStatus('Erreur : ' + (e && e.message ? e.message : e), 'err');
      }
    }

    // ── Modal ajout ───────────────────────────────────────────────────────────
    const modal = document.getElementById('zoneAddModal');
    const addBtn = document.querySelector('[data-zone-add]');
    const saveBtn = modal ? modal.querySelector('[data-zone-save]') : null;
    const errEl = modal ? modal.querySelector('[data-zone-add-error]') : null;
    const fType = document.getElementById('zoneType');
    const fName = document.getElementById('zoneName');
    const fValue = document.getElementById('zoneValue');
    const fTtl = document.getElementById('zoneTtl');

    function openModal() {
      if (!modal) return;
      if (errEl) { errEl.classList.add('hidden'); errEl.textContent = ''; }
      if (fName) fName.value = ''; if (fValue) fValue.value = ''; if (fTtl) fTtl.value = '3600';
      modal.classList.remove('hidden'); modal.classList.add('flex');
    }
    function closeModal() { if (modal) { modal.classList.remove('flex'); modal.classList.add('hidden'); } }

    addBtn && addBtn.addEventListener('click', openModal);
    modal && modal.querySelectorAll('[data-zone-close]').forEach(b => b.addEventListener('click', closeModal));
    modal && modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal && modal.classList.contains('flex')) closeModal(); });

    saveBtn && saveBtn.addEventListener('click', async () => {
      const payload = {
        domain: DOMAIN,
        type: fType ? fType.value : '',
        name: fName ? fName.value.trim() : '',
        content: fValue ? fValue.value.trim() : '',
        ttl: fTtl ? fTtl.value : '3600',
      };
      if (!payload.content) {
        if (errEl) { errEl.textContent = <?= json_encode(t('La valeur est requise.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>; errEl.classList.remove('hidden'); }
        return;
      }
      saveBtn.disabled = true;
      try {
        await apiCall('add_record', payload);
        closeModal();
        loadRecords();
      } catch (e) {
        if (errEl) { errEl.textContent = 'Erreur : ' + (e && e.message ? e.message : e); errEl.classList.remove('hidden'); }
      } finally {
        saveBtn.disabled = false;
      }
    });

    // ── Suppression (délégation) ────────────────────────────────────────────────
    body && body.addEventListener('click', async (e) => {
      const del = e.target.closest('[data-zone-del]');
      if (!del) return;
      const id = del.getAttribute('data-zone-del');
      if (!confirm('Supprimer l\u2019enregistrement ' + (del.getAttribute('data-row-label') || '') + ' ?')) return;
      del.disabled = true;
      try {
        await apiCall('delete_record', { domain: DOMAIN, id });
        loadRecords();
      } catch (err) {
        setStatus('Erreur suppression : ' + (err && err.message ? err.message : err), 'err');
        del.disabled = false;
      }
    });

    // ── Copier le domaine ───────────────────────────────────────────────────────
    document.querySelectorAll('[data-copy-domain]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const txt = btn.getAttribute('data-copy-domain');
        try { await navigator.clipboard.writeText(txt); } catch (_) {}
        const old = btn.textContent; btn.textContent = 'Copié'; setTimeout(() => { btn.textContent = old; }, 1200);
      });
    });

    loadRecords();
  })();
  </script>

  <!-- Network / URLs publiques — logique reprise de network.php -->
  <script>
    (function(){
      const rowsEl = document.getElementById('netRows');
      const msgEl = document.getElementById('netMsg');
      const addBtn = document.getElementById('netAddBtn');
      const refreshBtn = document.getElementById('netRefreshBtn');
      if (!rowsEl || !msgEl) return;

      const csrf = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

      const apiBase = new URL('../data/k8s_api.php', window.location.href);

      const escapeHtml = (s) => String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');

      const badge = (text, kind='muted') => {
        const cls = kind==='ok' ? 'net-badge net-b-ok' : kind==='warn' ? 'net-badge net-b-warn' : kind==='err' ? 'net-badge net-b-err' : 'net-badge net-b-muted';
        return `<span class="${cls}">${escapeHtml(text)}</span>`;
      };

      const setMsg = (text, kind='muted') => {
        msgEl.className = 'text-sm ' + (kind==='err' ? 'text-red-600' : kind==='ok' ? 'text-emerald-600' : 'text-muted-foreground');
        msgEl.textContent = text;
      };

      let services = [];
      let entries = [];

      function urlFrom(e){
        const scheme = e.tlsSecret ? 'https' : (e.scheme || 'http');
        let path = (e.path || '/');
        if(typeof path !== 'string') path = '/';
        path = path.trim();
        if(path === '') path = '/';
        if(!path.startsWith('/')) path = '/' + path;
        return `${scheme}://${e.host}${path}`;
      }

      function certBadge(e){
        const c = e.cert || null;
        if(!c || !c.status) return badge('TLS ?', 'warn');
        if(c.status === 'none') return badge('Sans TLS', 'muted');
        if(c.status === 'valid'){
          const d = (c.daysRemaining != null) ? ` (${c.daysRemaining}j)` : '';
          return badge('TLS OK' + d, 'ok');
        }
        if(c.status === 'expired') return badge('TLS expiré', 'err');
        if(c.status === 'error') return badge('TLS KO', 'err');
        return badge('TLS ?', 'warn');
      }

      function lbBadge(e){
        const lb = Array.isArray(e.loadBalancer) ? e.loadBalancer : [];
        if(lb.length === 0) return badge('LB: -', 'muted');
        const first = lb[0];
        const t = first.hostname || first.ip || 'OK';
        return badge('LB ' + t, 'ok');
      }

      function serviceOptions(selected){
        const opts = services.map(s => {
          const sel = (s.name === selected) ? 'selected' : '';
          return `<option value="${escapeHtml(s.name)}" ${sel}>${escapeHtml(s.name)}</option>`;
        }).join('');
        return `<option value="">(choisir)</option>` + opts;
      }

      function serviceDefaultPort(serviceName){
        const s = services.find(x => x.name === serviceName);
        if(!s || !Array.isArray(s.ports) || s.ports.length === 0) return 80;
        const p = s.ports[0];
        return Number(p.port) || 80;
      }

      function render(){
        rowsEl.innerHTML = '';

        if(entries.length === 0){
          rowsEl.innerHTML = `<div class="text-muted-foreground text-sm">Aucune URL publique trouvée.</div>`;
          return;
        }

        for(const e of entries){
          const url = urlFrom(e);
          const managed = !!e.managed;
          const ro = managed ? '' : 'disabled';

          const row = document.createElement('div');
          row.className = 'rounded-xl border p-4';
          row.dataset.ingress = e.ingressName || '';
          row.dataset.id = e.id || '';

          const portVal = (e.port != null && e.port !== '') ? e.port : '';
          const tlsOn = !!e.tlsSecret;

          row.innerHTML = `
            <div class="net-row-grid">
              <div>
                <div class="text-xs text-muted-foreground mb-1">Host</div>
                <input class="net-field" name="host" value="${escapeHtml(e.host || '')}" ${ro} placeholder="ex: app.example.com" />
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Path</div>
                <input class="net-field" name="path" value="${escapeHtml(e.path || '/') }" ${ro} placeholder="/" />
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Service</div>
                <select class="net-field" name="service" ${ro}>${serviceOptions(e.service || '')}</select>
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Port</div>
                <input class="net-field" name="port" value="${escapeHtml(String(portVal))}" ${ro} placeholder="80" />
              </div>
              <div>
                <div class="flex items-center justify-between">
                  <div class="text-xs text-muted-foreground mb-1">TLS secret</div>
                  <label class="text-xs text-muted-foreground flex items-center gap-1" title="Active/désactive TLS">
                    <input type="checkbox" name="tls" ${tlsOn ? 'checked' : ''} ${ro} /> TLS
                  </label>
                </div>
                <input class="net-field" name="tlsSecret" value="${escapeHtml(e.tlsSecret || '')}" ${ro} placeholder="ex: app-tls" />
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Certificat</div>
                <div class="flex flex-wrap items-center gap-2">
                  ${certBadge(e)}
                  ${lbBadge(e)}
                </div>
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Actions</div>
                <div class="net-actions">
                  <a class="net-btn" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;">Ouvrir</a>
                  <button type="button" class="net-btn" data-copy>Copier</button>
                  ${managed ? '<button type="button" class="net-btn" data-save>Enregistrer</button>' : ''}
                  ${managed ? '<button type="button" class="net-btn" data-del>Supprimer</button>' : ''}
                </div>
              </div>
            </div>

            <div class="mt-3 text-xs text-muted-foreground">
              <span>Ingress: <span class="mono">${escapeHtml(e.ingressName || '(nouveau)')}</span></span>
              <span class="mx-2">•</span>
              <span>URL: <span class="mono">${escapeHtml(url)}</span></span>
              ${managed ? '' : '<span class="mx-2">•</span><span class="text-amber-600">Lecture seule</span>'}
            </div>

            <div class="mt-2 text-xs" data-row-msg></div>
          `;

          const rowMsg = row.querySelector('[data-row-msg]');
          const setRowMsg = (t, kind='muted') => {
            rowMsg.className = 'mt-2 text-xs ' + (kind==='err' ? 'text-red-600' : kind==='ok' ? 'text-emerald-600' : 'text-muted-foreground');
            rowMsg.textContent = t;
          };

          // copy
          row.querySelector('[data-copy]').addEventListener('click', async () => {
            try{ await navigator.clipboard.writeText(url); setRowMsg('Copié.', 'ok'); }
            catch(_){
              const ta = document.createElement('textarea');
              ta.value = url; document.body.appendChild(ta); ta.select();
              document.execCommand('copy'); ta.remove();
              setRowMsg('Copié.', 'ok');
            }
          });

          // auto port when service changes
          const svcSel = row.querySelector('select[name="service"]');
          const portInp = row.querySelector('input[name="port"]');
          if(svcSel && portInp && managed){
            svcSel.addEventListener('change', () => {
              if(!portInp.value){
                portInp.value = String(serviceDefaultPort(svcSel.value));
              }
            });
          }

          // tls toggles secret field
          const tlsCb = row.querySelector('input[name="tls"]');
          const tlsSecretInp = row.querySelector('input[name="tlsSecret"]');
          if(tlsCb && tlsSecretInp && managed){
            const syncTls = () => {
              if(tlsCb.checked){
                tlsSecretInp.disabled = false;
                tlsSecretInp.placeholder = 'ex: app-tls';
              }else{
                tlsSecretInp.value = '';
                tlsSecretInp.disabled = true;
              }
            };
            syncTls();
            tlsCb.addEventListener('change', syncTls);
          }

          // save
          const saveBtn = row.querySelector('[data-save]');
          if(saveBtn){
            saveBtn.addEventListener('click', async () => {
              saveBtn.disabled = true;
              setRowMsg('Enregistrement…');
              try{
                const hostV = row.querySelector('input[name="host"]').value.trim();
                const pathV = row.querySelector('input[name="path"]').value.trim() || '/';
                const svcV  = row.querySelector('select[name="service"]').value.trim();
                const portV = row.querySelector('input[name="port"]').value.trim();
                const tlsV  = row.querySelector('input[name="tls"]').checked;
                const tlsSecretV = row.querySelector('input[name="tlsSecret"]').value.trim();

                const body = new URLSearchParams({
                  id: row.dataset.id || '',
                  ingressName: row.dataset.ingress || '',
                  host: hostV,
                  path: pathV,
                  service: svcV,
                  port: portV,
                  tls: tlsV ? '1' : '0',
                  tlsSecret: tlsSecretV,
                });

                const u = new URL(apiBase.toString());
                u.searchParams.set('action','upsert_public_url');

                const res = await fetch(u.toString(), {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrf,
                  },
                  body,
                });

                const ct = (res.headers.get('content-type') || '').toLowerCase();
                const raw = await res.text();
                let data = null;
                try{ data = JSON.parse(raw); }catch(_){ }

                if(!ct.includes('application/json') || !data){
                  throw new Error(`Réponse non-JSON (${res.status}). ` + raw.slice(0,200).replace(/\s+/g,' '));
                }
                if(!res.ok || !data.ok){
                  throw new Error(data.error || ('HTTP ' + res.status));
                }

                setRowMsg('Ok. Ingress mis à jour. Rafraîchissement…', 'ok');
                await load();

              }catch(e){
                setRowMsg('Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
              }finally{
                saveBtn.disabled = false;
              }
            });
          }

          // delete
          const delBtn = row.querySelector('[data-del]');
          if(delBtn){
            delBtn.addEventListener('click', async () => {
              if(!row.dataset.ingress){
                setRowMsg('Rien à supprimer (pas encore créé).', 'warn');
                return;
              }
              delBtn.disabled = true;
              setRowMsg('Suppression…');
              try{
                const body = new URLSearchParams({ ingressName: row.dataset.ingress });
                const u = new URL(apiBase.toString());
                u.searchParams.set('action','delete_public_url');
                const res = await fetch(u.toString(), {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrf,
                  },
                  body,
                });

                const ct = (res.headers.get('content-type') || '').toLowerCase();
                const raw = await res.text();
                let data = null;
                try{ data = JSON.parse(raw); }catch(_){ }

                if(!ct.includes('application/json') || !data){
                  throw new Error(`Réponse non-JSON (${res.status}). ` + raw.slice(0,200).replace(/\s+/g,' '));
                }
                if(!res.ok || !data.ok){
                  throw new Error(data.error || ('HTTP ' + res.status));
                }

                setRowMsg('Supprimé. Rafraîchissement…', 'ok');
                await load();

              }catch(e){
                setRowMsg('Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
              }finally{
                delBtn.disabled = false;
              }
            });
          }

          rowsEl.appendChild(row);
        }
      }

      function newRow(){
        const id = 'new-' + Math.random().toString(16).slice(2, 10);
        return {
          id,
          ingressName: '',
          managed: true,
          host: '',
          path: '/',
          service: '',
          port: '',
          tlsSecret: '',
          cert: { status: 'none' },
          loadBalancer: [],
        };
      }

      async function load(){
        setMsg('Chargement…');
        try{
          const u = new URL(apiBase.toString());
          u.searchParams.set('action','list_public_urls');

          const res = await fetch(u.toString(), { credentials: 'same-origin' });
          const ct = (res.headers.get('content-type') || '').toLowerCase();
          const raw = await res.text();
          let data = null;
          try{ data = JSON.parse(raw); }catch(_){ }

          if(!ct.includes('application/json') || !data){
            throw new Error(`Réponse non-JSON (${res.status}). ` + raw.slice(0,200).replace(/\s+/g,' '));
          }
          if(!res.ok || !data.ok){
            throw new Error(data.error || ('HTTP ' + res.status));
          }

          services = Array.isArray(data.services) ? data.services : [];
          entries = Array.isArray(data.entries) ? data.entries : [];

          render();
          setMsg(`${entries.length} URL(s) chargée(s).`, 'ok');

        }catch(e){
          setMsg('Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
          rowsEl.innerHTML = '';
        }
      }

      addBtn && addBtn.addEventListener('click', () => {
        entries = [newRow(), ...entries];
        render();
        setMsg('Nouvelle ligne ajoutée (pense à enregistrer).', 'muted');
      });

      refreshBtn && refreshBtn.addEventListener('click', () => load());

      load();
    })();
  </script>

  <!-- Statut de liaison domaine ↔ déploiement -->
  <script>
    (function(){
      const root = document.querySelector('[data-domain-link]');
      if (!root) return;

      const DOMAIN  = (root.getAttribute('data-domain') || '').toLowerCase();
      const CSRF    = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
      const apiBase = new URL('../data/k8s_api.php', window.location.href);

      const SERVICE_SUFFIX = '-service'; // services : [deployment]-service
      const TLS_SUFFIX     = '-tls';     // secrets TLS : [deployment]-tls
      const STATS_SUFFIX   = '-stats';   // services de stats à masquer du déroulant

      // Déploiements/services à ne jamais proposer dans le déroulant
      const HIDDEN_DEPLOYMENTS = new Set(['deployment-stats']);

      const esc = s => String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');

      // ── Appels API k8s ────────────────────────────────────────────────────────
      async function parseJson(res){
        const ct = (res.headers.get('content-type') || '').toLowerCase();
        const raw = await res.text();
        let data = null; try { data = JSON.parse(raw); } catch(_){}
        if (!ct.includes('application/json') || !data || !res.ok || !data.ok) {
          throw new Error((data && data.error) || ('HTTP ' + res.status));
        }
        return data;
      }
      async function apiList(){
        const u = new URL(apiBase.toString());
        u.searchParams.set('action', 'list_public_urls');
        const data = await parseJson(await fetch(u.toString(), { credentials: 'same-origin' }));
        return {
          services: Array.isArray(data.services) ? data.services : [],
          entries:  Array.isArray(data.entries)  ? data.entries  : [],
        };
      }
      async function apiPost(action, payload){
        const u = new URL(apiBase.toString());
        u.searchParams.set('action', action);
        const res = await fetch(u.toString(), {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
          body: new URLSearchParams(payload || {}),
        });
        return parseJson(res);
      }

      function matchesDomain(host){
        host = String(host || '').toLowerCase().replace(/\.$/, '');
        if (!host || !DOMAIN) return false;
        return host === DOMAIN || host.endsWith('.' + DOMAIN);
      }
      function serviceToDeployment(svc){
        svc = String(svc || '');
        return svc.endsWith(SERVICE_SUFFIX) ? svc.slice(0, -SERVICE_SUFFIX.length) : svc;
      }
      // Combine host + path → "sub.example.fr/uri"
      function hostPathToField(host, path){
        host = String(host || '');
        path = String(path || '/');
        return host + (path && path !== '/' ? path : '');
      }
      // Découpe "sub.example.fr/uri" → { host, path }
      function parseHostPath(v){
        v = String(v || '').trim().replace(/^https?:\/\//i, '');
        const idx = v.indexOf('/');
        let host, path;
        if (idx === -1) { host = v; path = '/'; }
        else { host = v.slice(0, idx); path = v.slice(idx) || '/'; }
        host = host.replace(/\.$/, '').toLowerCase();
        if (!path.startsWith('/')) path = '/' + path;
        return { host, path };
      }

      // ── État lié / non lié de la section ───────────────────────────────────────
      const states = {
        checking: root.querySelector('[data-link-state="checking"]'),
        linked:   root.querySelector('[data-link-state="linked"]'),
        unlinked: root.querySelector('[data-link-state="unlinked"]'),
      };
      const depEl = root.querySelector('[data-link-deployment]');
      function show(state){
        Object.entries(states).forEach(([k, el]) => { if (el) el.hidden = (k !== state); });
      }
      async function check(){
        show('checking');
        try{
          const { entries } = await apiList();
          const linked = entries.filter(e => matchesDomain(e.host));
          if (linked.length > 0) {
            const names = Array.from(new Set(linked.map(e => serviceToDeployment(e.service)).filter(Boolean)));
            if (depEl) depEl.textContent = names.length ? names.join(', ') : '—';
            show('linked');
          } else {
            show('unlinked');
          }
        }catch(_){
          show('unlinked'); // état sûr en cas d'erreur
        }
      }

      // ── Utilitaires modales ─────────────────────────────────────────────────────
      const openModal  = m => { if (m) { m.classList.remove('hidden'); m.classList.add('flex'); } };
      const closeModal = m => { if (m) { m.classList.remove('flex'); m.classList.add('hidden'); } };
      const isOpen     = m => !!m && m.classList.contains('flex');

      // ── Modale FORMULAIRE (créer / modifier une interconnexion) ─────────────────
      const fModal = document.getElementById('linkFormModal');
      const fTitle = document.getElementById('linkFormTitle');
      const fHost  = document.getElementById('linkHost');
      const fDep   = document.getElementById('linkDeployment');
      const fProto = document.getElementById('linkProtocol');
      const fPort  = document.getElementById('linkPort');
      const fTls   = document.getElementById('linkTls');
      const fErr   = document.getElementById('linkFormError');
      const fSave  = document.getElementById('linkFormSave');

      let servicesCache = [];
      let editing = null; // entrée en cours d'édition, ou null

      function depOptions(selected){
        const seen = new Set(); const opts = [];
        servicesCache.forEach(s => {
          if (String(s.name || '').endsWith(STATS_SUFFIX)) return; // masque [deployment]-stats
          const dep = serviceToDeployment(s.name);
          if (!dep || seen.has(dep)) return;
          if (HIDDEN_DEPLOYMENTS.has(dep) || HIDDEN_DEPLOYMENTS.has(s.name)) return; // masqué
          seen.add(dep);
          opts.push(`<option value="${esc(dep)}" ${dep === selected ? 'selected' : ''}>${esc(dep)}</option>`);
        });
        if (!opts.length) return `<option value="">(aucun déploiement)</option>`;
        return `<option value="">(choisir)</option>` + opts.join('');
      }
      function syncProto(){
        if (!fProto || !fPort) return;
        if (fProto.value === 'custom') fPort.classList.remove('hidden');
        else { fPort.classList.add('hidden'); }
      }
      fProto && fProto.addEventListener('change', syncProto);

      function setFormError(msg){
        if (!fErr) return;
        if (msg) { fErr.textContent = msg; fErr.classList.remove('hidden'); }
        else { fErr.textContent = ''; fErr.classList.add('hidden'); }
      }

      async function openForm(entry){
        editing = entry || null;
        setFormError('');
        if (fTitle) fTitle.textContent = editing ? 'Modifier l\u2019interconnexion' : 'Lier le domaine';
        if (fDep) fDep.innerHTML = '<option value="">…</option>';
        closeModal(mModal); // ferme la modale de gestion (« Ajouter une URL » / « Modifier »)
        openModal(fModal);

        try { servicesCache = (await apiList()).services; }
        catch(_) { servicesCache = []; }

        let host = DOMAIN, path = '/', depSel = '', proto = '80', portVal = '', tlsOn = false;
        if (editing) {
          host  = editing.host || DOMAIN;
          path  = editing.path || '/';
          depSel = serviceToDeployment(editing.service || '');
          tlsOn = !!editing.tlsSecret;
          const p = (editing.port != null && editing.port !== '') ? String(editing.port) : '80';
          if (p === '80') { proto = '80'; } else { proto = 'custom'; portVal = p; }
        }
        if (fDep)   fDep.innerHTML = depOptions(depSel);
        if (fHost)  fHost.value = hostPathToField(host, path);
        if (fProto) fProto.value = proto;
        if (fPort)  fPort.value = (proto === 'custom' ? portVal : '');
        if (fTls)   fTls.checked = tlsOn;
        syncProto();
        if (fHost) fHost.focus();
      }

      fSave && fSave.addEventListener('click', async () => {
        setFormError('');
        const { host, path } = parseHostPath(fHost ? fHost.value : '');
        const dep = fDep ? fDep.value.trim() : '';
        if (!host) { setFormError('Le host est requis.'); return; }
        if (!dep)  { setFormError('Choisissez un déploiement.'); return; }

        let port = '80';
        if (fProto && fProto.value === 'custom') {
          port = (fPort ? fPort.value.trim() : '');
          if (!port) { setFormError('Indiquez un port personnalisé.'); return; }
        }

        const tlsOn = !!(fTls && fTls.checked);
        // service = [deployment]-service ; secret TLS = [deployment]-tls
        const svc = servicesCache.find(s => serviceToDeployment(s.name) === dep);
        const serviceName = svc ? svc.name : (dep + SERVICE_SUFFIX);
        const tlsSecret = tlsOn ? (dep + TLS_SUFFIX) : '';

        const payload = {
          id: editing ? (editing.id || '') : ('new-' + Math.random().toString(16).slice(2, 10)),
          ingressName: editing ? (editing.ingressName || '') : '',
          host, path, service: serviceName, port,
          tls: tlsOn ? '1' : '0', tlsSecret,
        };

        fSave.disabled = true;
        try {
          await apiPost('upsert_public_url', payload);
          closeModal(fModal);
          await check();
          if (isOpen(mModal)) await loadManage();
        } catch(e) {
          setFormError('Erreur : ' + (e && e.message ? e.message : e));
        } finally {
          fSave.disabled = false;
        }
      });

      fModal && fModal.querySelectorAll('[data-link-form-close]').forEach(b => b.addEventListener('click', () => closeModal(fModal)));
      fModal && fModal.addEventListener('click', e => { if (e.target === fModal) closeModal(fModal); });

      // ── Modale GESTION (liste des interconnexions du domaine) ───────────────────
      const mModal  = document.getElementById('linkManageModal');
      const mBody   = document.getElementById('linkManageBody');
      const mStatus = document.getElementById('linkManageStatus');
      const mAdd    = document.getElementById('linkManageAdd');

      function setManageStatus(t, kind){
        if (!mStatus) return;
        mStatus.textContent = t || '';
        mStatus.className = 'mt-3 text-xs ' + (kind === 'err' ? 'text-red-600' : kind === 'ok' ? 'text-emerald-600' : 'text-muted-foreground');
      }

      function manageRow(e){
        const host = esc(e.host || '');
        const path = esc(e.path || '/');
        const dep  = esc(serviceToDeployment(e.service || ''));
        const port = esc((e.port != null && e.port !== '') ? String(e.port) : '');
        const tls  = !!e.tlsSecret;
        const managed = !!e.managed;
        const url = `${tls ? 'https' : 'http'}://${e.host || ''}${(e.path && e.path !== '/') ? e.path : ''}`;
        const meta = [];
        if (dep)  meta.push('Déploiement : <span class="mono">' + dep + '</span>');
        if (port) meta.push('Port ' + port);
        if (tls)  meta.push('TLS');
        if (!managed) meta.push('<span class="text-amber-600">Lecture seule</span>');
        return `
          <div class="rounded-lg border p-3 flex items-center justify-between gap-3 flex-wrap">
            <div class="min-w-0 text-sm">
              <div class="mono font-medium" style="word-break:break-all;">${host}${path !== '/' ? '<span class="text-muted-foreground">' + path + '</span>' : ''}</div>
              <div class="text-xs text-muted-foreground mt-0.5">${meta.join(' • ')}</div>
            </div>
            <div class="flex items-center gap-2">
              <a class="inline-flex h-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-all hover:bg-secondary" href="${esc(url)}" target="_blank" rel="noopener noreferrer">Ouvrir</a>
              ${managed ? '<button type="button" data-edit class="inline-flex h-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-all hover:bg-secondary">Modifier</button>' : ''}
              ${managed ? '<button type="button" data-del class="inline-flex h-8 items-center justify-center rounded-md border border-red-300 px-2.5 text-xs font-medium text-red-700 transition-all hover:bg-red-50 dark:text-red-300 dark:border-red-800 dark:hover:bg-red-900/30">Supprimer</button>' : ''}
            </div>
          </div>`;
      }

      let manageEntries = [];
      async function loadManage(){
        if (!mBody) return;
        mBody.innerHTML = '<div class="text-sm text-muted-foreground">Chargement…</div>';
        setManageStatus('');
        try {
          const { entries } = await apiList();
          manageEntries = entries.filter(e => matchesDomain(e.host));
          if (!manageEntries.length) {
            mBody.innerHTML = '<div class="text-sm text-muted-foreground">Aucune interconnexion pour ce domaine.</div>';
            return;
          }
          mBody.innerHTML = manageEntries.map(manageRow).join('');
          Array.from(mBody.children).forEach((rowEl, i) => {
            const e = manageEntries[i];
            const editBtn = rowEl.querySelector('[data-edit]');
            editBtn && editBtn.addEventListener('click', () => openForm(e));
            const delBtn = rowEl.querySelector('[data-del]');
            delBtn && delBtn.addEventListener('click', async () => {
              if (!confirm('Supprimer l\u2019interconnexion ' + (e.host || '') + ((e.path && e.path !== '/') ? e.path : '') + ' ?')) return;
              delBtn.disabled = true;
              try {
                await apiPost('delete_public_url', { ingressName: e.ingressName || '' });
                await loadManage();
                await check();
              } catch(err) {
                setManageStatus('Erreur suppression : ' + (err && err.message ? err.message : err), 'err');
                delBtn.disabled = false;
              }
            });
          });
        } catch(e) {
          mBody.innerHTML = '';
          setManageStatus('Erreur : ' + (e && e.message ? e.message : e), 'err');
        }
      }

      mModal && mModal.querySelectorAll('[data-link-manage-close]').forEach(b => b.addEventListener('click', () => closeModal(mModal)));
      mModal && mModal.addEventListener('click', e => { if (e.target === mModal) closeModal(mModal); });
      mAdd && mAdd.addEventListener('click', () => { closeModal(mModal); openForm(null); });

      // Échap ferme la modale ouverte (formulaire prioritaire car au-dessus)
      document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (isOpen(fModal)) closeModal(fModal);
        else if (isOpen(mModal)) closeModal(mModal);
      });

      // ── Boutons de la section de statut ─────────────────────────────────────────
      const manageBtn = root.querySelector('[data-link-manage]'); // « Gérer les interconnexions »
      manageBtn && manageBtn.addEventListener('click', () => { openModal(mModal); loadManage(); });

      const createBtn = root.querySelector('[data-link-create]'); // « Lier le domaine »
      createBtn && createBtn.addEventListener('click', () => openForm(null));

      check();
    })();
  </script>
  <?php endif; ?>
</body>
</html>