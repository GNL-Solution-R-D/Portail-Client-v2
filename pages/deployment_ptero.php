<?php

/**
 * pages/deployment_ptero.php
 *
 * Vue « service Pterodactyl » de la page /deployment.
 *
 * Inclus par pages/deployment.php quand le produit résolu depuis ?product_uid=
 * a product.provider_type = « ptero ». Les droits ont DÉJÀ été vérifiés par
 * servicesCatalogFindByUid() : ce fichier ne fait que l'affichage.
 *
 * Variables attendues dans la portée : $service (entrée du catalogue),
 * $productUid, $csrfToken.
 *
 * Toutes les écritures (power, commande) passent par data/ptero_api.php, qui
 * revérifie les droits et porte la clé du panel. Le navigateur ne reçoit que
 * des jetons de console à durée de vie courte.
 */

if (!isset($service) || !is_array($service)) {
    http_response_code(500);
    echo 'Contexte de service manquant.';
    exit;
}

$serviceName  = (string)($service['name'] ?? '');
$productName  = (string)($service['product_name'] ?? $serviceName);
$serviceState = (string)($service['status'] ?? '');
$isSuspended  = ($serviceState === 'suspended');
$pageTitle    = ($serviceName !== '' ? $serviceName : 'Service') . ' — GNL Solution';

$pteroConfigured = PterodactylClient::isConfigured();

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
    /* Mêmes repères visuels que la page de déploiement Kubernetes. */
    .widget-hero-icon{width:.75rem;height:.75rem;flex:0 0 .75rem;display:block;}
    .widget-back-icon{width:1rem;height:1rem;flex:0 0 1rem;display:block;}
    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease,opacity 220ms ease;will-change:height,opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}
    @media(prefers-reduced-motion:reduce){.collapsible-content,.collapsible-trigger .collapsible-chevron{transition:none!important;}}

    /* ── Console ── */
    #pteroConsole{
      font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
      font-size:.75rem;line-height:1.55;
      height:24rem;max-height:60vh;overflow:auto;white-space:pre-wrap;word-break:break-word;
      background:#0b0f17;color:#d5dae3;border-radius:.5rem;padding:1rem;
    }
    #pteroConsole .line-err{color:#fca5a5;}
    #pteroConsole .line-sys{color:#93c5fd;}
    .meter{height:.5rem;border-radius:9999px;overflow:hidden;background:color-mix(in srgb,currentColor 12%,transparent);}
    .meter > span{display:block;height:100%;border-radius:9999px;background:currentColor;transition:width .4s ease;}
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

        <!-- ══════════════════════════════════════════════
             HERO — même traitement que la page de déploiement Kubernetes
             (photo + dégradé + badge d'état), adapté au serveur de jeu.
        ══════════════════════════════════════════════ -->
        <div class="w-full bg-surface">
          <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-6 rounded group relative overflow-hidden border-0 shadow-lg transition-shadow hover:shadow-xl">
            <div class="absolute inset-0">
              <img
                src="https://images.unsplash.com/photo-1494984858525-798dd0b282f5?ixlib=rb-4.1.0&auto=format&fit=crop&q=80&w=2070"
                alt=""
                class="h-full w-full object-cover"
              />
              <div class="absolute inset-0 bg-gradient-to-r from-black/80 via-black/60 to-black/40 dark:from-black/90 dark:via-black/70 dark:to-black/50"></div>
            </div>

            <div data-slot="card-content" class="relative z-10 space-y-6 p-8 md:p-5">
              <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between" style="margin-block-end: 0px;">
                <div class="space-y-3 min-w-0">
                  <h1 class="flex flex-wrap items-center gap-2 text-3xl font-bold text-white md:text-xl lg:text-2xl">
                    <span class="truncate"><?= htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') ?></span>
                    <!-- État live du serveur, mis à jour par le websocket. -->
                    <span id="pteroState"
                      class="rounded-md border border-white/20 bg-white/15 px-2 py-0.5 text-xs font-medium text-white backdrop-blur-sm">…</span>
                  </h1>

                  <p class="text-sm text-white/70">
                    <?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>
                    · <span class="mono text-xs" id="pteroAddress">—</span>
                  </p>

                  <a href="/dashboard" class="flex items-center gap-2 text-sm text-white/70 hover:text-white transition-colors">
                    <svg class="widget-back-icon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                      <path d="M595.9 757L350.6 511.7l245.3-245.3 51.7 51.7L454 511.7l193.6 193.5z" fill="#ffffff"/>
                    </svg>
                    <span><?= t('Retour dashboard') ?></span>
                  </a>
                </div>

                <div class="flex md:justify-end md:pt-1">
                  <span id="pteroHeroBadge" data-slot="badge"
                    class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 gap-1 overflow-hidden border-transparent bg-white/20 text-white backdrop-blur-sm hover:bg-white/30">
                    <svg class="widget-hero-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                      <path d="M7.493 0.015C7.442 0.021 7.268 0.039 7.107 0.055C5.234 0.242 3.347 1.208 2.071 2.634C0.66 4.211 -0.057 6.168 0.009 8.253C0.124 11.854 2.599 14.903 6.11 15.771C8.169 16.28 10.433 15.917 12.227 14.791C14.017 13.666 15.27 11.933 15.771 9.887C15.943 9.186 15.983 8.829 15.983 8C15.983 7.171 15.943 6.814 15.771 6.113C14.979 2.878 12.315 0.498 9 0.064C8.716 0.027 7.683 -0.006 7.493 0.015ZM8.853 1.563C9.967 1.707 11.01 2.136 11.944 2.834C12.273 3.08 12.92 3.727 13.166 4.056C13.727 4.807 14.142 5.69 14.33 6.535C14.544 7.5 14.544 8.5 14.33 9.465C13.916 11.326 12.605 12.978 10.867 13.828C10.239 14.135 9.591 14.336 8.88 14.444C8.456 14.509 7.544 14.509 7.12 14.444C5.172 14.148 3.528 13.085 2.493 11.451C2.279 11.114 1.999 10.526 1.859 10.119C1.618 9.422 1.514 8.781 1.514 8C1.514 6.961 1.715 6.075 2.16 5.16C2.5 4.462 2.846 3.98 3.413 3.413C3.98 2.846 4.462 2.5 5.16 2.16C6.313 1.599 7.567 1.397 8.853 1.563ZM7.706 4.29C7.482 4.363 7.355 4.491 7.293 4.705C7.257 4.827 7.253 5.106 7.259 6.816C7.267 8.786 7.267 8.787 7.325 8.896C7.398 9.033 7.538 9.157 7.671 9.204C7.803 9.25 8.197 9.25 8.329 9.204C8.462 9.157 8.602 9.033 8.675 8.896C8.733 8.787 8.733 8.786 8.741 6.816C8.749 4.664 8.749 4.662 8.596 4.481C8.472 4.333 8.339 4.284 8.04 4.276C7.893 4.272 7.743 4.278 7.706 4.29ZM7.786 10.53C7.597 10.592 7.41 10.753 7.319 10.932C7.249 11.072 7.237 11.325 7.294 11.495C7.388 11.78 7.697 12 8 12C8.303 12 8.612 11.78 8.706 11.495C8.763 11.325 8.751 11.072 8.681 10.932C8.616 10.804 8.46 10.646 8.333 10.58C8.217 10.52 7.904 10.491 7.786 10.53Z"
                        id="pteroHeroBadgeIcon" fill="#e5e7eb"/>
                    </svg>
                    <span id="pteroHeroBadgeText"><?= t('Connexion…') ?></span>
                  </span>
                </div>
              </div>

              <!-- Actions d'alimentation, sur la même ligne de base que le bouton
                   « Redémarrer l'application » de la page Kubernetes. -->
              <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="max-w-2xl text-base text-white/70 md:text-sm">
                  <?php if ($isSuspended): ?><?= t('Ce service est suspendu.') ?><?php endif; ?>
                </p>
                <div class="flex flex-wrap items-center gap-2 sm:justify-end" id="pteroPower">
                  <button type="button" data-signal="start"
                    class="inline-flex h-9 items-center justify-center rounded-md bg-emerald-600 px-3 text-sm font-medium text-white transition-all hover:bg-emerald-500 disabled:opacity-40"><?= t('Démarrer') ?></button>
                  <button type="button" data-signal="restart"
                    class="inline-flex h-9 items-center justify-center rounded-md border border-white/25 bg-white/10 px-3 text-sm font-medium text-white backdrop-blur-sm transition-all hover:bg-white/20 disabled:opacity-40"><?= t('Redémarrer') ?></button>
                  <button type="button" data-signal="stop"
                    class="inline-flex h-9 items-center justify-center rounded-md border border-white/25 bg-white/10 px-3 text-sm font-medium text-white backdrop-blur-sm transition-all hover:bg-white/20 disabled:opacity-40"><?= t('Arrêter') ?></button>
                  <button type="button" data-signal="kill"
                    class="inline-flex h-9 items-center justify-center rounded-md border border-red-400/40 bg-red-500/15 px-3 text-sm font-medium text-red-200 backdrop-blur-sm transition-all hover:bg-red-500/30 disabled:opacity-40"><?= t('Tuer') ?></button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Les erreurs sortent du hero : illisibles sur la photo. -->
        <div id="pteroError" class="mt-4 hidden rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"></div>

        <!-- ══════════════════════════════════════════════
             RESSOURCES
        ══════════════════════════════════════════════ -->
        <div class="mt-4 grid gap-4 md:grid-cols-3">
          <div class="bg-background rounded border p-4 text-indigo-600">
            <div class="flex items-baseline justify-between gap-2">
              <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= t('Processeur') ?></span>
              <span class="text-sm font-semibold" data-metric="cpu-text">—</span>
            </div>
            <div class="meter mt-3"><span data-metric="cpu-bar" style="width:0%"></span></div>
            <p class="mt-2 text-xs text-muted-foreground" data-metric="cpu-limit">—</p>
          </div>

          <div class="bg-background rounded border p-4 text-sky-600">
            <div class="flex items-baseline justify-between gap-2">
              <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= t('Mémoire') ?></span>
              <span class="text-sm font-semibold" data-metric="mem-text">—</span>
            </div>
            <div class="meter mt-3"><span data-metric="mem-bar" style="width:0%"></span></div>
            <p class="mt-2 text-xs text-muted-foreground" data-metric="mem-limit">—</p>
          </div>

          <div class="bg-background rounded border p-4 text-emerald-600">
            <div class="flex items-baseline justify-between gap-2">
              <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= t('Disque') ?></span>
              <span class="text-sm font-semibold" data-metric="disk-text">—</span>
            </div>
            <div class="meter mt-3"><span data-metric="disk-bar" style="width:0%"></span></div>
            <p class="mt-2 text-xs text-muted-foreground" data-metric="disk-limit">—</p>
          </div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-3">
          <div class="bg-background rounded border p-4">
            <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= t('Réseau') ?></span>
            <p class="mt-2 text-sm mono" data-metric="net">—</p>
          </div>
          <div class="bg-background rounded border p-4">
            <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= t('Uptime') ?></span>
            <p class="mt-2 text-sm mono" data-metric="uptime">—</p>
          </div>
          <div class="bg-background rounded border p-4">
            <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= t('Nœud') ?></span>
            <p class="mt-2 text-sm mono" data-metric="node">—</p>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════
             CONSOLE
        ══════════════════════════════════════════════ -->
        <div class="mt-4 bg-background rounded-xl border p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold"><?= t('Console') ?></h2>
            <span id="pteroSocketState" class="text-xs text-muted-foreground"><?= t('Connexion…') ?></span>
          </div>

          <div id="pteroConsole" class="mt-3" role="log" aria-live="polite"></div>

          <form id="pteroCommandForm" class="mt-3 flex gap-2">
            <input id="pteroCommand" type="text" autocomplete="off" spellcheck="false"
              class="mono h-10 w-full min-w-0 flex-1 rounded-md border bg-background px-3 text-sm"
              placeholder="<?= t('Saisissez une commande…') ?>" />
            <button type="submit"
              class="inline-flex h-10 shrink-0 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90 disabled:opacity-50"><?= t('Envoyer') ?></button>
          </form>
        </div>

      </div>
    </main>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════════
       Collapsible de la barre latérale (même logique que les autres pages)
  ══════════════════════════════════════════════════════════════════════ -->
  <script>
  (function () {
    function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    ready(function () {
      document.querySelectorAll('[data-slot="collapsible-trigger"]').forEach(function (btn) {
        btn.classList.add('collapsible-trigger');
        var targetId = btn.getAttribute('aria-controls');
        var content  = targetId ? document.getElementById(targetId) : null;
        if (!content) {
          var p = btn.closest('[data-slot="collapsible"]');
          if (p) content = p.querySelector('[data-slot="collapsible-content"]');
        }
        if (!content) return;
        content.classList.add('collapsible-content');
        var chev = btn.querySelector('.lucide-chevron-right');
        if (chev) chev.classList.add('collapsible-chevron');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) { content.hidden = false; content.classList.add('is-open'); content.style.height = 'auto'; }
        else          { content.hidden = true;  content.classList.remove('is-open'); content.style.height = '0px'; }
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var isOpen = btn.getAttribute('aria-expanded') === 'true';
          if (!isOpen) {
            btn.setAttribute('aria-expanded', 'true'); btn.setAttribute('data-state', 'open');
            content.hidden = false; content.classList.add('is-open'); content.setAttribute('data-state', 'open');
            content.style.height = '0px';
            requestAnimationFrame(function () { content.style.height = content.scrollHeight + 'px'; });
            content.addEventListener('transitionend', function onEnd(ev) {
              if (ev.propertyName !== 'height') return;
              content.style.height = 'auto';
              content.removeEventListener('transitionend', onEnd);
            });
          } else {
            btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('data-state', 'closed');
            content.classList.remove('is-open'); content.setAttribute('data-state', 'closed');
            content.style.height = content.scrollHeight + 'px';
            requestAnimationFrame(function () { content.style.height = '0px'; });
            content.addEventListener('transitionend', function onEndClose(ev) {
              if (ev.propertyName !== 'height') return;
              content.hidden = true;
              content.removeEventListener('transitionend', onEndClose);
            });
          }
        }, { passive: false });
      });
    });
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════════════
       Pilotage du serveur Pterodactyl
       - lecture / actions  → data/ptero_api.php (la clé du panel reste serveur)
       - flux temps réel    → websocket wings, avec un jeton court fourni par
                              l'API. Les commandes passent quand même par le
                              proxy, qui revérifie les droits à chaque envoi.
  ══════════════════════════════════════════════════════════════════════ -->
  <script>
  (function () {
    const PRODUCT_UID = <?= json_encode($productUid, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const CSRF        = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const CONFIGURED  = <?= $pteroConfigured ? 'true' : 'false' ?>;
    const API         = new URL('../data/ptero_api.php', window.location.href);

    const consoleEl = document.getElementById('pteroConsole');
    const stateEl   = document.getElementById('pteroState');
    const socketEl  = document.getElementById('pteroSocketState');
    const errorEl   = document.getElementById('pteroError');
    const powerEl   = document.getElementById('pteroPower');
    const addressEl = document.getElementById('pteroAddress');
    const cmdForm   = document.getElementById('pteroCommandForm');
    const cmdInput  = document.getElementById('pteroCommand');

    const metric = (n) => document.querySelector('[data-metric="' + n + '"]');

    let limits = { memory: 0, disk: 0, cpu: 0 };
    let socket = null;
    let reconnectTimer = null;
    let closedByUs = false;

    // Le quota du panel est compté par COMPTE, et le portail n'en utilise qu'un :
    // tous les clients connectés se partagent le même seau. D'où la sobriété —
    // sondage lent, reconnexion à temporisation croissante, et arrêt net quand
    // le panel renvoie 429.
    const POLL_MS       = 30000;   // repli REST : 1 appel panel toutes les 30 s
    const RECONNECT_MIN = 5000;
    const RECONNECT_MAX = 120000;
    let reconnectDelay  = RECONNECT_MIN;
    let pausedUntil     = 0;       // horodatage jusqu'auquel on ne touche plus au panel

    // ── Utilitaires ──────────────────────────────────────────────────────────

    function showError(msg) {
      if (!errorEl) return;
      if (!msg) { errorEl.classList.add('hidden'); errorEl.textContent = ''; return; }
      errorEl.textContent = msg;
      errorEl.classList.remove('hidden');
    }

    function bytes(n) {
      n = Number(n) || 0;
      const units = ['o', 'Kio', 'Mio', 'Gio', 'Tio'];
      let i = 0;
      while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
      return (i === 0 ? n : n.toFixed(n < 10 ? 1 : 0)) + ' ' + units[i];
    }

    function duration(ms) {
      let s = Math.floor((Number(ms) || 0) / 1000);
      if (s <= 0) return '—';
      const d = Math.floor(s / 86400); s %= 86400;
      const h = Math.floor(s / 3600);  s %= 3600;
      const m = Math.floor(s / 60);    s %= 60;
      if (d) return d + 'j ' + h + 'h';
      if (h) return h + 'h ' + m + 'min';
      if (m) return m + 'min ' + s + 's';
      return s + 's';
    }

    function setMeter(prefix, used, limit, text) {
      const t = metric(prefix + '-text');
      const b = metric(prefix + '-bar');
      const l = metric(prefix + '-limit');
      if (t) t.textContent = text;
      const pct = limit > 0 ? Math.min(100, (used / limit) * 100) : 0;
      if (b) b.style.width = pct.toFixed(1) + '%';
      if (l) l.textContent = limit > 0 ? ('Limite : ' + (prefix === 'cpu' ? limit + ' %' : bytes(limit))) : 'Illimité';
    }

    // Pastille d'état, posée sur la photo du hero : fonds translucides plutôt
    // que les teintes claires habituelles, sinon illisible.
    const STATE_STYLE = {
      running:  { pill: 'border-emerald-300/40 bg-emerald-400/20 text-emerald-100', icon: '#34d399', label: 'Serveur en ligne' },
      starting: { pill: 'border-sky-300/40 bg-sky-400/20 text-sky-100',             icon: '#38bdf8', label: 'Démarrage en cours' },
      stopping: { pill: 'border-amber-300/40 bg-amber-400/20 text-amber-100',       icon: '#fbbf24', label: 'Arrêt en cours' },
      offline:  { pill: 'border-white/20 bg-white/10 text-white/80',                icon: '#e5e7eb', label: 'Serveur arrêté' },
      unknown:  { pill: 'border-white/20 bg-white/15 text-white',                   icon: '#e5e7eb', label: 'État inconnu' }
    };

    const badgeTextEl = document.getElementById('pteroHeroBadgeText');
    const badgeIconEl = document.getElementById('pteroHeroBadgeIcon');
    const SERVICE_SUSPENDED = <?= $isSuspended ? 'true' : 'false' ?>;

    function setState(state) {
      state = String(state || 'unknown');
      const style = STATE_STYLE[state] || STATE_STYLE.unknown;

      if (stateEl) {
        stateEl.textContent = state;
        stateEl.className = 'rounded-md border px-2 py-0.5 text-xs font-medium backdrop-blur-sm ' + style.pill;
      }

      // Badge de droite : verdict lisible, pas le nom technique de l'état.
      // Un service suspendu prime sur l'état du processus.
      if (badgeTextEl) {
        badgeTextEl.textContent = SERVICE_SUSPENDED ? 'Service suspendu' : style.label;
      }
      if (badgeIconEl) {
        badgeIconEl.setAttribute('fill', SERVICE_SUSPENDED ? '#fbbf24' : style.icon);
      }
      if (powerEl) {
        powerEl.querySelectorAll('button[data-signal]').forEach(function (b) {
          const sig = b.getAttribute('data-signal');
          b.disabled = (state === 'running' && sig === 'start')
            || (state === 'offline' && sig !== 'start');
        });
      }
      if (cmdInput) cmdInput.disabled = (state !== 'running');
    }

    // ANSI : les couleurs du serveur ne sont pas rendues, on les retire.
    const ANSI = /\[[0-9;?]*[ -\/]*[@-~]/g;

    function write(text, cls) {
      if (!consoleEl) return;
      const atBottom = consoleEl.scrollHeight - consoleEl.scrollTop - consoleEl.clientHeight < 40;
      const div = document.createElement('div');
      if (cls) div.className = cls;
      div.textContent = String(text).replace(ANSI, '').replace(/\r/g, '');
      consoleEl.appendChild(div);
      while (consoleEl.childElementCount > 2000) consoleEl.removeChild(consoleEl.firstChild);
      if (atBottom) consoleEl.scrollTop = consoleEl.scrollHeight;
    }

    async function call(action, method, body) {
      const u = new URL(API.toString());
      u.searchParams.set('action', action);
      u.searchParams.set('product_uid', PRODUCT_UID);

      const opts = { method: method || 'GET', credentials: 'same-origin', headers: {} };
      if ((method || 'GET') === 'POST') {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        opts.headers['X-CSRF-Token'] = CSRF;
        const params = new URLSearchParams(body || {});
        params.set('product_uid', PRODUCT_UID);
        opts.body = params;
      }

      if (Date.now() < pausedUntil) {
        throw new Error('Quota du panel atteint — reprise dans '
          + Math.ceil((pausedUntil - Date.now()) / 1000) + ' s.');
      }

      const res = await fetch(u.toString(), opts);
      const raw = await res.text();
      let data = null;
      try { data = JSON.parse(raw); } catch (_) { /* ignore */ }

      // 429 : le panel demande d'attendre. On gèle TOUS les appels d'ici là,
      // sinon chaque tentative repousse la fin du throttle.
      if (res.status === 429) {
        const wait = Math.max(5, Number(data && data.retry_after) || 30);
        pausedUntil = Date.now() + wait * 1000;
        reconnectDelay = Math.min(RECONNECT_MAX, wait * 1000);
        throw new Error((data && data.error) || ('Quota du panel atteint, reprise dans ' + wait + ' s.'));
      }

      if (!data) {
        const compact = String(raw || '').replace(/\s+/g, ' ').trim().slice(0, 160);
        throw new Error('Réponse inattendue du serveur (HTTP ' + res.status + ')'
          + (compact ? ' : ' + compact : '.'));
      }
      if (!res.ok || !data.ok) throw new Error(apiError(res, data));
      return data;
    }

    // Le proxy renvoie { ok:false, error:"…" }. La page d'erreur de l'Ingress,
    // elle, renvoie { error:true, code:502, message:"Bad Gateway" } : « error »
    // y est un booléen, d'où ce démêlage plutôt qu'un simple data.error.
    function apiError(res, data) {
      if (data && typeof data.error === 'string' && data.error) return data.error;
      if (data && typeof data.message === 'string' && data.message) {
        return data.message + ' (HTTP ' + (data.code || res.status) + ')';
      }
      return 'HTTP ' + ((data && data.code) || res.status);
    }

    // ── Lecture REST (état initial, et repli si la console ne passe pas) ─────

    async function refreshStatus() {
      try {
        const d = await call('status');
        showError('');

        limits = d.server.limits || limits;
        if (addressEl) addressEl.textContent = d.server.address || '—';
        const nodeEl = metric('node');
        if (nodeEl) nodeEl.textContent = d.server.node || '—';

        applyStats({
          state: d.resources.state,
          memory_bytes: d.resources.memory_bytes,
          disk_bytes: d.resources.disk_bytes,
          cpu_absolute: d.resources.cpu_absolute,
          network: { rx_bytes: d.resources.network_rx_bytes, tx_bytes: d.resources.network_tx_bytes },
          uptime: d.resources.uptime
        });

        if (d.server.is_installing) write('[portail] Installation du serveur en cours…', 'line-sys');
        if (d.server.is_suspended)  write('[portail] Ce serveur est suspendu.', 'line-sys');
        return true;
      } catch (e) {
        showError(e && e.message ? e.message : String(e));
        runDiagnostic();
        return false;
      }
    }

    // Quand « status » échoue, on affiche dans la console la configuration
    // réellement utilisée par le serveur (jamais la clé) et la réponse brute du
    // panel : la cause est alors lisible sans ouvrir les logs du pod.
    let diagDone = false;
    async function runDiagnostic() {
      if (diagDone) return;
      diagDone = true;
      try {
        const d = (await call('diag')).diag || {};
        write('[diagnostic] URL appelée   : ' + (d.base_url || '?') + '/servers/' + (d.server_id || '?'), 'line-sys');
        write('[diagnostic] clé PTERO     : ' + (d.key_type || '?') + ' (' + (d.key_length || 0) + ' caractères'
          + (d.key_source ? ', depuis ' + d.key_source : '') + ')', 'line-sys');
        if (d.probe_error) {
          write('[diagnostic] transport     : ' + d.probe_error, 'line-err');
        } else {
          write('[diagnostic] réponse panel : HTTP ' + d.probe_status, d.probe_status === 200 ? 'line-sys' : 'line-err');
          if (d.probe_body) write('[diagnostic] corps         : ' + d.probe_body, 'line-sys');
        }
      } catch (e) {
        write('[diagnostic] indisponible : ' + (e && e.message ? e.message : e), 'line-err');
      }
    }

    // Le websocket envoie « stats » sous la même forme que l'API REST.
    function applyStats(s) {
      if (!s) return;
      if (s.state) setState(s.state);

      const mem  = Number(s.memory_bytes) || 0;
      const disk = Number(s.disk_bytes) || 0;
      const cpu  = Number(s.cpu_absolute) || 0;

      setMeter('mem',  mem,  (limits.memory || 0) * 1024 * 1024, bytes(mem));
      setMeter('disk', disk, (limits.disk   || 0) * 1024 * 1024, bytes(disk));
      setMeter('cpu',  cpu,  limits.cpu || 100, cpu.toFixed(1) + ' %');

      const net = metric('net');
      if (net) {
        const rx = (s.network && s.network.rx_bytes) || 0;
        const tx = (s.network && s.network.tx_bytes) || 0;
        net.textContent = '↓ ' + bytes(rx) + '  ↑ ' + bytes(tx);
      }
      const up = metric('uptime');
      if (up) up.textContent = duration(s.uptime);
    }

    // ── Console live (websocket wings) ───────────────────────────────────────

    async function connectConsole() {
      if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }

      let creds;
      try {
        creds = await call('websocket');
      } catch (e) {
        if (socketEl) socketEl.textContent = 'Console indisponible';
        write('[portail] Console indisponible : ' + (e && e.message ? e.message : e), 'line-err');
        startPolling();
        // Nouvel essai plus tard : le jeton peut être refusé temporairement
        // (quota), inutile d'abandonner définitivement la console.
        const wait = Math.max(reconnectDelay, pausedUntil - Date.now());
        reconnectTimer = setTimeout(connectConsole, wait);
        reconnectDelay = Math.min(RECONNECT_MAX, reconnectDelay * 2);
        return;
      }

      try {
        socket = new WebSocket(creds.socket);
      } catch (e) {
        if (socketEl) socketEl.textContent = 'Console indisponible';
        write('[portail] Connexion impossible au nœud.', 'line-err');
        startPolling();
        return;
      }

      socket.addEventListener('open', function () {
        if (socketEl) socketEl.textContent = 'Connectée';
        reconnectDelay = RECONNECT_MIN;   // la connexion tient : on repart au plus court
        send('auth', creds.token);
      });

      socket.addEventListener('message', function (ev) {
        let msg = null;
        try { msg = JSON.parse(ev.data); } catch (_) { return; }
        const args = Array.isArray(msg.args) ? msg.args : [];

        switch (msg.event) {
          case 'auth success':
            stopPolling();
            send('send logs', null);
            send('send stats', null);
            break;
          case 'console output':
          case 'install output':
          case 'daemon message':
            write(args[0] || '');
            break;
          case 'status':
            setState(args[0]);
            break;
          case 'stats':
            try { applyStats(JSON.parse(args[0])); } catch (_) { /* ignore */ }
            break;
          case 'token expiring':
          case 'token expired':
            // Jeton de courte durée : on en redemande un au proxy.
            call('websocket').then(function (c) { send('auth', c.token); })
                             .catch(function () { /* la reconnexion s'en chargera */ });
            break;
          case 'jwt error':
            write('[portail] Console refusée : ' + (args[0] || ''), 'line-err');
            break;
          case 'daemon error':
            write('[wings] ' + (args[0] || ''), 'line-err');
            break;
        }
      });

      socket.addEventListener('close', function () {
        if (closedByUs) return;
        // Temporisation croissante : une console qui ne veut pas s'ouvrir ne doit
        // pas consommer un jeton toutes les 5 secondes.
        const wait = Math.max(reconnectDelay, pausedUntil - Date.now());
        if (socketEl) socketEl.textContent = 'Reconnexion dans ' + Math.ceil(wait / 1000) + ' s';
        startPolling();
        reconnectTimer = setTimeout(connectConsole, wait);
        reconnectDelay = Math.min(RECONNECT_MAX, reconnectDelay * 2);
      });

      socket.addEventListener('error', function () {
        if (socketEl) socketEl.textContent = 'Erreur de console';
      });
    }

    function send(event, arg) {
      if (!socket || socket.readyState !== WebSocket.OPEN) return;
      socket.send(JSON.stringify({ event: event, args: [arg] }));
    }

    // ── Repli : sondage REST quand la console n'est pas disponible ───────────

    let pollTimer = null;

    async function pollResources() {
      if (Date.now() < pausedUntil) return;
      try {
        const d = await call('resources');
        showError('');
        applyStats({
          state: d.resources.state,
          memory_bytes: d.resources.memory_bytes,
          disk_bytes: d.resources.disk_bytes,
          cpu_absolute: d.resources.cpu_absolute,
          network: { rx_bytes: d.resources.network_rx_bytes, tx_bytes: d.resources.network_tx_bytes },
          uptime: d.resources.uptime
        });
      } catch (e) {
        showError(e && e.message ? e.message : String(e));
      }
    }

    function startPolling() {
      if (pollTimer) return;
      pollTimer = setInterval(pollResources, POLL_MS);
    }
    function stopPolling() {
      if (!pollTimer) return;
      clearInterval(pollTimer);
      pollTimer = null;
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    if (powerEl) {
      powerEl.addEventListener('click', async function (e) {
        const btn = e.target.closest('button[data-signal]');
        if (!btn || btn.disabled) return;
        const signal = btn.getAttribute('data-signal');

        if (signal === 'kill' && !window.confirm('Tuer le serveur coupe le processus sans sauvegarde. Continuer ?')) {
          return;
        }

        const buttons = powerEl.querySelectorAll('button[data-signal]');
        buttons.forEach(function (b) { b.disabled = true; });
        try {
          await call('power', 'POST', { signal: signal });
          showError('');
          write('[portail] Signal « ' + signal +' » envoyé.', 'line-sys');
        } catch (err) {
          showError(err && err.message ? err.message : String(err));
        } finally {
          // L'état réel revient par le websocket (ou le prochain sondage).
          setTimeout(function () { buttons.forEach(function (b) { b.disabled = false; }); }, 1500);
        }
      });
    }

    if (cmdForm) {
      cmdForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        const command = cmdInput ? cmdInput.value.trim() : '';
        if (!command) return;
        cmdInput.value = '';
        write('> ' + command, 'line-sys');
        try {
          await call('command', 'POST', { command: command });
        } catch (err) {
          write('[portail] ' + (err && err.message ? err.message : err), 'line-err');
        }
      });
    }

    window.addEventListener('beforeunload', function () {
      closedByUs = true;
      if (socket) { try { socket.close(); } catch (_) {} }
    });

    // ── Démarrage ────────────────────────────────────────────────────────────

    if (!CONFIGURED) {
      showError('Le panel Pterodactyl n\'est pas configuré sur ce portail (PTERO_API_URL / PTERO_API_KEY).');
      if (socketEl) socketEl.textContent = 'Non configuré';
      setState('unknown');
    } else {
      refreshStatus().then(function (ok) { if (ok) connectConsole(); else startPolling(); });
    }
  })();
  </script>

  <script src="../assets/js/services_menu.js" defer></script>
</body>
</html>
