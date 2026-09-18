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
    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease,opacity 220ms ease;will-change:height,opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}
    @media(prefers-reduced-motion:reduce){.collapsible-content,.collapsible-trigger .collapsible-chevron{transition:none!important;}}

    /* ── Hero : rangée identité / actions ───────────────────────────────────
       Le CSS du portail est un build Tailwind figé : sm:items-end et
       sm:shrink-0 n'y existent pas. La colonne de droite n'alignait donc pas
       ses enfants à droite, et le trafic réseau restait collé à gauche.      */
    .hero-row{display:flex;flex-direction:column;gap:1rem;}
    .hero-aside{display:flex;flex-direction:column;gap:.5rem;}
    @media(min-width:640px){
      .hero-row{flex-direction:row;align-items:flex-end;justify-content:space-between;}
      .hero-aside{flex-shrink:0;align-items:flex-end;}
    }

    /* ── Boutons d'alimentation inactifs ────────────────────────────────────
       Le build Tailwind figé n'a pas disabled:opacity-40 : la classe posée sur
       les boutons ne correspondait à aucune règle et un bouton bloqué avait
       exactement l'allure d'un bouton cliquable. On le grise donc ici, en
       éteignant aussi le survol — :hover s'applique encore à un bouton
       désactivé, et hover:bg-white/20 le rallumait au passage de la souris. */
    #pteroPower button[disabled]{
      opacity:.4;
      cursor:not-allowed;
      color:rgba(255,255,255,.6);
      border-color:rgba(255,255,255,.12);
      background:rgba(255,255,255,.04);
      backdrop-filter:none;
      box-shadow:none;
    }
    #pteroPower button[disabled]:hover{background:rgba(255,255,255,.04);}

    /* ── Explorateur de fichiers ────────────────────────────────────────────
       Le build Tailwind du portail est figé : pas d'utilitaire pour le fil
       d'Ariane ni pour la couleur des cases à cocher natives. */
    .explorer-path{display:flex;flex-wrap:wrap;align-items:center;gap:0;font-size:.875rem;}
    .explorer-path-sep{opacity:.55;margin:0 .15rem;}
    .explorer-path-link{background:none;border:0;padding:0;margin:0;font:inherit;color:inherit;cursor:pointer;}
    .explorer-path-link:hover{text-decoration:underline;}
    .explorer-path-text{color:inherit;}
    .files-check{accent-color:var(--primary,#2563eb);width:1rem;height:1rem;cursor:pointer;}
    /* Les actions d'une ligne : discrètes au repos, lisibles au survol. */
    .files-act{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;
      border-radius:.375rem;color:var(--muted-foreground);cursor:pointer;background:none;border:0;}
    .files-act:hover{background:var(--secondary);color:var(--foreground);}
    .files-act svg{width:1rem;height:1rem;}
    .files-name{background:none;border:0;padding:0;font:inherit;color:inherit;cursor:pointer;text-align:left;}
    .files-name:hover{text-decoration:underline;}
    .files-name[disabled]{cursor:default;text-decoration:none;}
    #pteroEditorText{height:24rem;max-height:55vh;}
    /* Même raison que pour le hero : disabled:opacity-40 n'existe pas dans le
       build figé, et « Supprimer » restait rouge vif sans sélection. */
    #pteroFiles > div > div > button[disabled]{opacity:.45;cursor:not-allowed;filter:grayscale(1);}
    #pteroFiles > div > div > button[disabled]:hover{background:inherit;}

    /* ── Console ── */
    #pteroConsole{
      font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
      font-size:.75rem;line-height:1.55;
      height:24rem;max-height:60vh;overflow:auto;white-space:pre-wrap;word-break:break-word;
      background:#0b0f17;color:#d5dae3;border-radius:.5rem;padding:1rem;
    }
    #pteroConsole .line-err{color:#fca5a5;}
    #pteroConsole .line-sys{color:#93c5fd;}
    /* ── Nœud : drapeau de sa Location ──────────────────────────────────────
       Le drapeau est un SVG et non un emoji : sous Windows, Chrome ne rend pas
       les drapeaux emoji et affiche les deux lettres du pays à la place.
       Le filet clair détache les drapeaux à bande blanche (NL, LU) de la photo. */
    .node-line{display:inline-flex;align-items:center;gap:.4rem;}
    .node-flag{flex:none;width:1.05rem;height:.7rem;border-radius:2px;overflow:hidden;
      box-shadow:0 0 0 1px rgba(255,255,255,.3);}
    .node-flag svg{display:block;width:100%;height:100%;}

    /* ── Pastille d'état : la couleur suit l'état du processus ──────────────
       Vert : le service tourne. Orange : transition en cours (starting,
       stopping). Rouge : arrêté. Neutre : état inconnu.

       Le fond est un voile teinté, pas un aplat : la pastille est posée sur la
       photo du hero, un aplat opaque y ferait tache. Le texte prend le pas 200
       de la même teinte, largement au-dessus du voile assombri.

       La couleur ne porte jamais seule : le libellé (running / starting /
       stopping / offline) est dans la pastille, et la puce reprend la teinte. */
    #pteroState{display:inline-flex;align-items:center;gap:.375rem;
      transition:background-color .3s ease,border-color .3s ease,color .3s ease;
      /* Le voile neutre est posé ici et non par une classe utilitaire :
         bg-white/15 n'existe pas dans le build Tailwind figé, la pastille
         « unknown » était donc entièrement transparente, réduite à son filet. */
      background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.28);}
    #pteroState::before{content:"";width:.375rem;height:.375rem;border-radius:9999px;
      background:currentColor;flex:none;}

    #pteroState[data-state="running"]{
      background:rgba(34,197,94,.25);border-color:rgba(34,197,94,.55);color:#bbf7d0;}
    #pteroState[data-state="starting"],
    #pteroState[data-state="stopping"]{
      background:rgba(249,115,22,.25);border-color:rgba(249,115,22,.55);color:#fed7aa;}
    #pteroState[data-state="offline"]{
      background:rgba(239,68,68,.25);border-color:rgba(239,68,68,.55);color:#fecaca;}
    /* « unknown » garde le voile neutre d'origine : tant qu'on ne sait pas, on
       n'annonce rien. */

    @media(prefers-reduced-motion:reduce){#pteroState{transition:none;}}

    /* ── Fond des cartes de ressources : l'historique de la mesure ──────────
       x = temps, y = valeur. Un aplat à 10 % surmonté d'un trait de 2 px :
       assez pour lire une tendance, assez discret pour que le chiffre de la
       carte reste ce qu'on lit en premier.

       La couleur vient de --spark et non de currentColor : en thème sombre,
       indigo-600 tombe à 2,6:1 contre la carte (mesuré) et le trait
       disparaissait. Le pas 500 repasse au-dessus de 3:1. Les trois teintes
       restent celles des cartes — le graphe n'introduit aucune couleur. */
    /* Hauteur plancher : sans la jauge, la carte se tasse et la courbe n'a plus
       assez d'amplitude pour se lire. Les trois restent alignées. */
    .metric-card{position:relative;overflow:hidden;min-height:6.5rem;}
    /* Valeur au-dessus, limite en dessous et en plus petit : c'est la valeur
       qu'on vient lire, la limite ne sert qu'à la situer. Colonne alignée à
       droite pour que les deux nombres partagent le même bord. */
    .metric-value{display:flex;flex-direction:column;align-items:flex-end;line-height:1.2;}
    .metric-cap{font-size:.6875rem;font-weight:500;opacity:.65;}
    .metric-card > *{position:relative;z-index:1;}

    .metric-card--cpu {--spark:#4f46e5;}   /* indigo-600 */
    .metric-card--mem {--spark:#0284c7;}   /* sky-600    */
    .metric-card--disk{--spark:#059669;}   /* emerald-600 */
    .dark .metric-card--cpu {--spark:#6366f1;}
    .dark .metric-card--mem {--spark:#0ea5e9;}
    .dark .metric-card--disk{--spark:#10b981;}

    .metric-spark{position:absolute;inset:0;width:100%;height:100%;z-index:0;
      pointer-events:none;color:var(--spark);}
    .metric-spark .spark-area{fill:currentColor;opacity:.10;}
    /* non-scaling-stroke : le viewBox est étiré sans respecter les
       proportions, un stroke-width ordinaire sortirait déformé. */
    .metric-spark .spark-line{fill:none;stroke:currentColor;stroke-width:2;
      stroke-linejoin:round;stroke-linecap:round;opacity:.5;}

    /* Lecture d'un point passé au survol. Masqués tant que la souris est
       ailleurs : un curseur permanent serait du bruit. */
    .spark-cursor,.spark-dot,.spark-tip{position:absolute;z-index:2;
      pointer-events:none;display:none;}
    .spark-cursor{top:0;bottom:0;width:1px;background:var(--spark);opacity:.45;}
    /* 8 px avec un anneau de 2 px dans la couleur de la carte : le point reste
       lisible là où il croise le trait. */
    .spark-dot{width:8px;height:8px;border-radius:9999px;background:var(--spark);
      box-shadow:0 0 0 2px var(--background);transform:translate(-50%,-50%);}
    .spark-tip{transform:translate(-50%,calc(-100% - 8px));white-space:nowrap;
      border-radius:.25rem;border:1px solid var(--border);padding:.125rem .375rem;
      font-size:.6875rem;line-height:1.35;background:var(--popover);
      color:var(--popover-foreground);box-shadow:0 1px 2px rgb(0 0 0 / .08);}
    .spark-tip.is-below{transform:translate(-50%,8px);}
    .metric-card.is-probing .spark-cursor,
    .metric-card.is-probing .spark-dot,
    .metric-card.is-probing .spark-tip{display:block;}

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
             HERO — même carte que la page de déploiement Kubernetes
             (photo + dégradé), resserrée : titre + état, adresse, puis les
             actions d'alimentation alignées à droite.
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

            <div data-slot="card-content" class="relative z-10 p-8 md:p-5">
              <!-- Une seule rangée : identité à gauche, actions à droite.
                   Elle retombe en colonne sous 640 px, où la place manque. -->
              <div class="hero-row">
                <div class="min-w-0">
                  <!-- Titre + état live du processus -->
                  <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-3xl font-bold text-white md:text-xl lg:text-2xl">
                      <?= htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') ?>
                    </h1>
                    <span id="pteroState" data-state="unknown" hidden
                      class="rounded-md border border-white/20 bg-white/15 px-2 py-0.5 text-xs font-medium text-white backdrop-blur-sm">…</span>
                    <!-- L'état n'est plus affiché ici : il a rejoint la barre
                         latérale, où il remplace le statut de facturation. La
                         pastille reste dans le DOM, masquée — setState() écrit
                         toujours dedans et pilote les boutons d'alimentation. -->
                    <button type="button" data-settings-open aria-haspopup="dialog"
                      class="grid h-7 w-7 shrink-0 place-items-center rounded-md border border-white/20 bg-white/15 text-white backdrop-blur-sm transition-all hover:bg-white/25"
                      title="<?= t('Paramètres du service') ?>" aria-label="<?= t('Paramètres du service') ?>">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"></path>
                      </svg>
                    </button>
                    <?php if ($isSuspended): ?>
                      <!-- Statut de facturation : distinct de l'état du processus. -->
                      <span class="rounded-md border border-amber-300/40 bg-amber-400/20 px-2 py-0.5 text-xs font-medium text-amber-100 backdrop-blur-sm">suspended</span>
                    <?php endif; ?>
                  </div>

                  <!-- Produit commandé + adresse de connexion -->
                  <p class="mt-1 text-sm text-white/70">
                    <?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>
                    · <span class="mono text-xs" id="pteroAddress">—</span>
                  </p>
                </div>

                <!-- Colonne de droite : trafic réseau, puis actions, le tout
                     aligné à droite et calé sur le bas du bloc d'identité
                     (.hero-row / .hero-aside dans le <style> de la page). -->
                <div class="hero-aside">
                  <!-- Nœud, au-dessus du trafic : une donnée d'identité, qui ne
                       bouge jamais. Elle ne méritait pas une vignette à elle
                       seule en bas de page. Un ton plus discret que le trafic,
                       qui lui évolue — et /50, pas /55 : le build Tailwind est
                       figé, un palier absent retomberait sur du blanc plein. -->
                  <p class="mono text-xs text-white/50 text-right node-line" data-metric="node">—</p>

                  <!-- Trafic cumulé depuis le démarrage. Même élément que la
                       tuile d'avant (data-metric="net") : le JS est inchangé.
                       text-right en plus de l'alignement du conteneur : sous
                       640 px la colonne est pleine largeur. -->
                  <p class="mono text-xs text-white/70 text-right" data-metric="net">—</p>

                  <!-- Même habillage pour les quatre boutons : sur la photo, un
                       bouton plein tirerait l'œil plus que le titre. -->
                  <div class="flex flex-wrap items-center gap-2 sm:justify-end" id="pteroPower">
                    <button type="button" data-signal="start"
                      class="inline-flex h-9 items-center justify-center rounded border border-white/25 bg-white/10 px-3 text-sm font-medium text-white backdrop-blur-sm transition-all hover:bg-white/20 disabled:opacity-40"><?= t('Démarrer') ?></button>
                    <button type="button" data-signal="restart"
                      class="inline-flex h-9 items-center justify-center rounded border border-white/25 bg-white/10 px-3 text-sm font-medium text-white backdrop-blur-sm transition-all hover:bg-white/20 disabled:opacity-40"><?= t('Redémarrer') ?></button>
                    <button type="button" data-signal="stop"
                      class="inline-flex h-9 items-center justify-center rounded border border-white/25 bg-white/10 px-3 text-sm font-medium text-white backdrop-blur-sm transition-all hover:bg-white/20 disabled:opacity-40"><?= t('Arrêter') ?></button>
                    <button type="button" data-signal="kill"
                      class="inline-flex h-9 items-center justify-center rounded border border-white/25 bg-white/10 px-3 text-sm font-medium text-white backdrop-blur-sm transition-all hover:bg-white/20 disabled:opacity-40"><?= t('Tuer') ?></button>
                  </div>
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
          <div class="bg-background rounded border p-4 text-indigo-600 metric-card metric-card--cpu" data-spark="cpu">
            <svg class="metric-spark" viewBox="0 0 100 100" preserveAspectRatio="none"
                 aria-hidden="true" focusable="false">
              <path class="spark-area" d=""></path>
              <path class="spark-line" d="" vector-effect="non-scaling-stroke"></path>
            </svg>
            <span class="spark-cursor" data-spark-cursor></span>
            <span class="spark-dot" data-spark-dot></span>
            <span class="spark-tip" data-spark-tip></span>
            <div class="flex items-baseline justify-between gap-2">
              <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= t('Processeur') ?></span>
              <span class="text-sm font-semibold metric-value" data-metric="cpu-text">—</span>
            </div>
            <p class="mt-2 text-xs text-muted-foreground" data-metric="cpu-limit">—</p>
          </div>

          <div class="bg-background rounded border p-4 text-sky-600 metric-card metric-card--mem" data-spark="mem">
            <svg class="metric-spark" viewBox="0 0 100 100" preserveAspectRatio="none"
                 aria-hidden="true" focusable="false">
              <path class="spark-area" d=""></path>
              <path class="spark-line" d="" vector-effect="non-scaling-stroke"></path>
            </svg>
            <span class="spark-cursor" data-spark-cursor></span>
            <span class="spark-dot" data-spark-dot></span>
            <span class="spark-tip" data-spark-tip></span>
            <div class="flex items-baseline justify-between gap-2">
              <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= t('Mémoire') ?></span>
              <span class="text-sm font-semibold metric-value" data-metric="mem-text">—</span>
            </div>
            <p class="mt-2 text-xs text-muted-foreground" data-metric="mem-limit">—</p>
          </div>

          <div class="bg-background rounded border p-4 text-emerald-600 metric-card metric-card--disk" data-spark="disk">
            <svg class="metric-spark" viewBox="0 0 100 100" preserveAspectRatio="none"
                 aria-hidden="true" focusable="false">
              <path class="spark-area" d=""></path>
              <path class="spark-line" d="" vector-effect="non-scaling-stroke"></path>
            </svg>
            <span class="spark-cursor" data-spark-cursor></span>
            <span class="spark-dot" data-spark-dot></span>
            <span class="spark-tip" data-spark-tip></span>
            <div class="flex items-baseline justify-between gap-2">
              <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"><?= t('Disque') ?></span>
              <span class="text-sm font-semibold metric-value" data-metric="disk-text">—</span>
            </div>
            <p class="mt-2 text-xs text-muted-foreground" data-metric="disk-limit">—</p>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════
             CONSOLE
        ══════════════════════════════════════════════ -->
        <div class="mt-4 bg-background rounded border p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold"><?= t('Console') ?></h2>
            <span id="pteroSocketState" class="text-xs text-muted-foreground"><?= t('Connexion…') ?></span>
          </div>

          <div id="pteroConsole" class="mt-3" role="log" aria-live="polite"></div>

          <form id="pteroCommandForm" class="mt-3 flex gap-2">
            <input id="pteroCommand" type="text" autocomplete="off" spellcheck="false"
              class="mono h-10 w-full min-w-0 flex-1 rounded border bg-background px-3 text-sm"
              placeholder="<?= t('Saisissez une commande…') ?>" />
            <button type="submit"
              class="inline-flex h-10 shrink-0 items-center justify-center rounded bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90 disabled:opacity-50"><?= t('Envoyer') ?></button>
          </form>
        </div>


        <!-- ══════════════════════════════════════════════
             EXPLORATEUR DE FICHIERS
             Tout passe par data/ptero_api.php, qui revérifie la propriété du
             service et exige le jeton CSRF sur chaque écriture. Seuls le
             téléchargement et le téléversement s'adressent au panel en direct,
             via des URL signées, temporaires et sans clé — un fichier de
             plusieurs centaines de mégaoctets n'a rien à faire dans PHP.
        ══════════════════════════════════════════════ -->
        <div class="mt-4 bg-background rounded border p-4" id="pteroFiles">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold"><?= t('Fichiers') ?></h2>
            <div class="flex flex-wrap items-center gap-2">
              <button type="button" id="filesReload" class="inline-flex h-9 items-center justify-center rounded border px-3 text-sm font-medium transition-all hover:bg-secondary"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 2v6h6"/><path d="M21 12A9 9 0 0 0 6 5.3L3 8"/><path d="M21 22v-6h-6"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/></svg><span class="ml-2"><?= t('Recharger') ?></span></button>
              <button type="button" id="filesMkdir" class="inline-flex h-9 items-center justify-center rounded border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Nouveau dossier') ?></button>
              <button type="button" id="filesUpload" class="inline-flex h-9 items-center justify-center rounded border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Téléverser') ?></button>
              <button type="button" id="filesDeleteSel" class="inline-flex h-9 items-center justify-center rounded bg-red-600 px-3 text-sm font-medium text-white transition-all hover:bg-red-700 disabled:opacity-50" disabled><?= t('Supprimer') ?></button>
              <input type="file" id="filesInput" multiple hidden />
            </div>
          </div>

          <!-- Fil d'Ariane : chaque segment ramène à son dossier. -->
          <div id="filesCrumbs" class="explorer-path mt-3 mono text-muted-foreground"></div>

          <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <select id="filesSort" class="h-9 rounded border bg-background px-3 text-sm sm:w-max">
              <option value="name-asc"><?= t('Nom A → Z') ?></option>
              <option value="name-desc"><?= t('Nom Z → A') ?></option>
              <option value="mtime-desc"><?= t('Modifiés récemment') ?></option>
              <option value="size-desc"><?= t('Taille décroissante') ?></option>
            </select>
            <input id="filesSearch" type="text" autocomplete="off"
              class="h-9 w-full min-w-0 rounded border bg-background px-3 text-sm sm:w-64"
              placeholder="<?= t('Rechercher un fichier ou dossier…') ?>" />
          </div>

          <div id="filesStatus" class="mt-3 text-xs text-muted-foreground"></div>

          <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-max table-auto text-left text-sm">
              <thead>
                <tr>
                  <th class="border-surface border-b p-3">
                    <div class="flex items-center gap-2">
                      <input type="checkbox" id="filesSelectAll" class="files-check" aria-label="<?= t('Tout sélectionner') ?>" />
                      <span class="font-medium"><?= t('Nom') ?></span>
                    </div>
                  </th>
                  <th class="border-surface border-b p-3 font-medium"><?= t('Modifié') ?></th>
                  <th class="border-surface border-b p-3 font-medium"><?= t('Type') ?></th>
                  <th class="border-surface border-b p-3 font-medium"><?= t('Taille') ?></th>
                  <th class="border-surface border-b p-3"></th>
                </tr>
              </thead>
              <tbody id="filesBody"></tbody>
            </table>
          </div>
        </div>

      </div>
    </main>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════════
       ÉDITEUR DE FICHIER
       Un simple <textarea> : le portail n'embarque pas d'éditeur de code, et le
       panel reste disponible pour les cas lourds. Le fichier est rechargé à
       l'ouverture, jamais servi depuis un cache.
  ══════════════════════════════════════════════════════════════════════ -->
  <div id="pteroEditorModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4" role="dialog" aria-modal="true" aria-labelledby="pteroEditorTitle">
    <div class="w-full max-w-3xl rounded border bg-card text-card-foreground shadow-lg">
      <div class="p-6">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <h2 id="pteroEditorTitle" class="text-lg font-semibold"><?= t('Édition') ?></h2>
            <p id="pteroEditorPath" class="mono mt-1 text-xs text-muted-foreground break-all"></p>
          </div>
          <button type="button" data-editor-cancel class="inline-flex h-9 items-center justify-center rounded border px-3 text-sm font-medium transition-all hover:bg-secondary" aria-label="<?= t('Fermer') ?>"><?= t('Fermer') ?></button>
        </div>
        <textarea id="pteroEditorText" spellcheck="false" wrap="off"
          class="mono mt-4 w-full resize-y rounded border bg-background p-3 text-xs"></textarea>
        <div data-editor-status class="mt-3 text-xs text-muted-foreground"></div>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" data-editor-cancel class="inline-flex h-9 items-center justify-center rounded border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Annuler') ?></button>
          <button type="button" data-editor-save class="inline-flex h-9 items-center justify-center rounded bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90 disabled:opacity-50"><?= t('Enregistrer') ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Renommer / Nouveau dossier : même modal, deux titres. -->
  <div id="pteroPromptModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4" role="dialog" aria-modal="true" aria-labelledby="pteroPromptTitle">
    <div class="w-full max-w-md rounded border bg-card text-card-foreground shadow-lg">
      <div class="p-6">
        <h2 id="pteroPromptTitle" class="text-lg font-semibold"></h2>
        <p id="pteroPromptText" class="mt-2 text-sm text-muted-foreground"></p>
        <input type="text" id="pteroPromptInput" autocomplete="off" spellcheck="false"
          class="mono mt-4 h-10 w-full rounded border bg-background px-3 text-sm" />
        <div data-prompt-status class="mt-3 text-xs text-muted-foreground"></div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" data-prompt-cancel class="inline-flex h-9 items-center justify-center rounded border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Annuler') ?></button>
          <button type="button" data-prompt-confirm class="inline-flex h-9 items-center justify-center rounded bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90 disabled:opacity-50"><?= t('Valider') ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Suppression : irréversible côté panel, donc confirmation explicite. -->
  <div id="pteroDeleteModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4" role="dialog" aria-modal="true" aria-labelledby="pteroDeleteTitle">
    <div class="w-full max-w-md rounded border bg-card text-card-foreground shadow-lg">
      <div class="p-6">
        <h2 id="pteroDeleteTitle" class="text-lg font-semibold"><?= t('Supprimer') ?></h2>
        <p id="pteroDeleteText" class="mt-2 text-sm text-muted-foreground"></p>
        <ul id="pteroDeleteList" class="mono mt-3 max-h-40 overflow-x-auto text-xs text-muted-foreground"></ul>
        <div data-delete-status class="mt-3 text-xs text-muted-foreground"></div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" data-delete-cancel class="inline-flex h-9 items-center justify-center rounded border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Annuler') ?></button>
          <button type="button" data-delete-confirm class="inline-flex h-9 items-center justify-center rounded bg-red-600 px-3 text-sm font-medium text-white transition-all hover:bg-red-700 disabled:opacity-50"><?= t('Supprimer définitivement') ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════════
       CONFIRMATION — « Tuer »
       Même facture que les modals de confirmation du portail (suppression de
       domaine, renommage). Remplace le window.confirm() natif, qui bloque le
       fil d'exécution et ignore le thème.
  ══════════════════════════════════════════════════════════════════════ -->
  <div id="killServerModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
       role="dialog" aria-modal="true" aria-labelledby="killServerTitle" aria-describedby="killServerText">
    <div class="w-full max-w-md rounded border bg-card text-card-foreground shadow-lg">
      <div class="p-6">
        <h2 id="killServerTitle" class="text-lg font-semibold"><?= t('Tuer le serveur') ?></h2>
        <p id="killServerText" class="mt-2 text-sm text-muted-foreground">
          <?= t('Le processus de') ?>
          <span class="font-medium text-foreground"><?= htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') ?></span>
          <?= t("sera coupé immédiatement, sans arrêt propre : les données non enregistrées seront perdues. À réserver à un serveur qui ne répond plus.") ?>
        </p>
        <div data-kill-status class="mt-3 text-xs"></div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" data-kill-cancel
            class="inline-flex h-9 items-center justify-center rounded border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Annuler') ?></button>
          <button type="button" data-kill-confirm
            class="inline-flex h-9 items-center justify-center rounded bg-red-600 px-3 text-sm font-medium text-white transition-all hover:bg-red-700 disabled:opacity-50"><?= t('Je comprend les risque, proceder') ?></button>
        </div>
      </div>
    </div>
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

    // La console se connecte en DIRECT du navigateur au nœud wings (le proxy PHP
    // ne fait que délivrer le jeton). Si wings refuse le handshake — cas le plus
    // courant : l'en-tête Origin du portail absent de « allowed_origins » dans
    // /etc/pterodactyl/config.yml du nœud — réessayer ne servira jamais à rien et
    // chaque tentative consomme un jeton sur le quota partagé. On abandonne donc
    // après CONSOLE_MAX_TRIES et on bascule définitivement sur le sondage REST,
    // qui lui continue d'actualiser les compteurs.
    const CONSOLE_MAX_TRIES = 3;
    let consoleTries   = 0;
    let consoleGaveUp  = false;
    let authOk         = false;
    let openedAt       = 0;

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

    // ── Drapeau de la Location du nœud ───────────────────────────────────────
    // Des SVG, pas des emoji : sous Windows, Chrome ne rend pas les drapeaux
    // emoji — il affiche « FR », « DE »… en lettres.
    const NODE_FLAGS = {
      FR: '<svg viewBox="0 0 3 2" aria-hidden="true"><rect width="1" height="2" fill="#0055A4"/><rect x="1" width="1" height="2" fill="#fff"/><rect x="2" width="1" height="2" fill="#EF4135"/></svg>',
      DE: '<svg viewBox="0 0 3 2" aria-hidden="true"><rect width="3" height="2" fill="#FFCE00"/><rect width="3" height="1.333" fill="#DD0000"/><rect width="3" height="0.667" fill="#000"/></svg>',
      BE: '<svg viewBox="0 0 3 2" aria-hidden="true"><rect width="1" height="2" fill="#000"/><rect x="1" width="1" height="2" fill="#FDDA24"/><rect x="2" width="1" height="2" fill="#EF3340"/></svg>',
      IT: '<svg viewBox="0 0 3 2" aria-hidden="true"><rect width="1" height="2" fill="#008C45"/><rect x="1" width="1" height="2" fill="#F4F5F0"/><rect x="2" width="1" height="2" fill="#CD212A"/></svg>',
      NL: '<svg viewBox="0 0 3 2" aria-hidden="true"><rect width="3" height="2" fill="#21468B"/><rect width="3" height="1.333" fill="#fff"/><rect width="3" height="0.667" fill="#AE1C28"/></svg>',
      LU: '<svg viewBox="0 0 3 2" aria-hidden="true"><rect width="3" height="2" fill="#00A1DE"/><rect width="3" height="1.333" fill="#fff"/><rect width="3" height="0.667" fill="#ED2939"/></svg>',
      ES: '<svg viewBox="0 0 3 2" aria-hidden="true"><rect width="3" height="2" fill="#AA151B"/><rect y="0.5" width="3" height="1" fill="#F1BF00"/></svg>',
      CH: '<svg viewBox="0 0 3 2" aria-hidden="true"><rect width="3" height="2" fill="#DA291C"/><rect x="1.3" y="0.4" width="0.4" height="1.2" fill="#fff"/><rect x="0.9" y="0.8" width="1.2" height="0.4" fill="#fff"/></svg>',
      UK: '<svg viewBox="0 0 60 30" aria-hidden="true"><clipPath id="ukc"><path d="M30 15h30v15zv15H0zH0V0zV0h30z"/></clipPath><path d="M0 0v30h60V0z" fill="#012169"/><path d="M0 0 60 30M60 0 0 30" stroke="#fff" stroke-width="6"/><path d="M0 0 60 30M60 0 0 30" clip-path="url(#ukc)" stroke="#C8102E" stroke-width="4"/><path d="M30 0v30M0 15h60" stroke="#fff" stroke-width="10"/><path d="M30 0v30M0 15h60" stroke="#C8102E" stroke-width="6"/></svg>'
    };

    // Codes que le panel peut employer pour un même pays. « SW » est celui de
    // GNL pour la Suisse ; l'ISO serait « CH ».
    const NODE_FLAG_ALIASES = { SW: 'CH', SUISSE: 'CH', CHE: 'CH', GB: 'UK', GBR: 'UK', EN: 'UK' };

    function nodeFlag(location) {
        const raw = String(location || '').trim().toUpperCase();
        if (raw === '') return null;

        // On tente le code tel quel, son alias, puis ses deux premières lettres :
        // une Location nommée « FR-2 » doit donner le drapeau français.
        const tries = [raw, NODE_FLAG_ALIASES[raw], raw.slice(0, 2), NODE_FLAG_ALIASES[raw.slice(0, 2)]];
        for (const key of tries) {
            if (key && NODE_FLAGS[key]) {
                const span = document.createElement('span');
                span.className = 'node-flag';
                span.title = raw;
                span.setAttribute('role', 'img');
                span.setAttribute('aria-label', 'Location ' + raw);
                // Le SVG vient de notre propre table, jamais du panel.
                span.innerHTML = NODE_FLAGS[key];
                return span;
            }
        }

        return null;
    }

    // Drapeau à gauche, nom du nœud à droite. Le nom passe par un nœud texte :
    // il vient du panel, il n'a rien à faire dans un innerHTML.
    function renderNode(el, name, location) {
        el.textContent = '';
        const flag = nodeFlag(location);
        if (flag) el.appendChild(flag);
        el.appendChild(document.createTextNode(name || '—'));
    }

    // ── Historique tracé au fond des cartes de ressources ────────────────────
    // Le panel ne garde aucun historique : la courbe part vide et se construit
    // à partir des relevés reçus depuis l'ouverture de la page (websocket ≈ 1/s,
    // repli REST toutes les 30 s).
    const SPARK_WINDOW_MS  = 300000;   // fenêtre glissante de 5 minutes
    const SPARK_MAX_POINTS = 600;      // garde-fou : la page peut rester ouverte

    const sparks = {};

    const sparkClock = (ms) => {
      const d = new Date(ms), p = (n) => String(n).padStart(2, '0');
      return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    };

    function sparkSetup(prefix) {
      const card = document.querySelector('[data-spark="' + prefix + '"]');
      if (!card) return null;

      const s = {
        card:   card,
        area:   card.querySelector('.spark-area'),
        line:   card.querySelector('.spark-line'),
        cursor: card.querySelector('[data-spark-cursor]'),
        dot:    card.querySelector('[data-spark-dot]'),
        tip:    card.querySelector('[data-spark-tip]'),
        points: [],
        top:    0,
        format: null,
      };

      // Survol : lire une valeur passée sans quitter la carte. Le pointeur est
      // sur la carte entière, pas sur le tracé : viser une courbe de 2 px à la
      // souris est intenable.
      card.addEventListener('pointermove', function (e) { sparkProbe(s, e); });
      card.addEventListener('pointerleave', function () { card.classList.remove('is-probing'); });

      return s;
    }

    // Domaine du tracé : le temps en abscisse, la valeur en ordonnée.
    function sparkGeometry(s) {
      const pts = s.points;
      if (pts.length === 0) return null;

      const last = pts[pts.length - 1].t;
      // Tant qu'il y a moins de 5 minutes d'historique, la courbe occupe toute
      // la largeur : trois points tassés à droite ne diraient rien.
      const t0   = Math.max(pts[0].t, last - SPARK_WINDOW_MS);
      const span = Math.max(1, last - t0);

      // L'échelle verticale suit la limite du panel quand il y en a une, pour
      // que la courbe se lise comme la jauge. « Illimité » (0) se rabat sur le
      // maximum observé, avec 15 % d'air au-dessus.
      let observed = 0;
      pts.forEach(function (p) { if (p.v > observed) observed = p.v; });

      let top = s.top > 0 ? s.top : observed * 1.15;
      if (observed > top) top = observed;   // un dépassement de limite doit se voir
      if (!(top > 0)) top = 1;

      return { t0: t0, span: span, top: top };
    }

    function sparkDraw(s) {
      const g = sparkGeometry(s);
      if (!g || !s.area || !s.line) return;

      const pts = s.points;
      const x = (t) => (((t - g.t0) / g.span) * 100).toFixed(2);
      const y = (v) => (100 - Math.min(1, v / g.top) * 100).toFixed(2);

      let d = '';
      pts.forEach(function (p, i) { d += (i === 0 ? 'M' : 'L') + x(p.t) + ' ' + y(p.v); });
      // Un seul relevé ne fait pas une ligne : on la prolonge à l'horizontale
      // pour que la carte montre quelque chose dès la première mesure.
      if (pts.length === 1) d += 'L100 ' + y(pts[0].v);

      s.line.setAttribute('d', d);
      s.area.setAttribute('d', d + 'L100 100 L' + x(pts[0].t) + ' 100 Z');
    }

    function sparkProbe(s, e) {
      const g = sparkGeometry(s);
      if (!g) return;

      const box = s.card.getBoundingClientRect();
      if (box.width <= 0) return;
      const px = Math.min(Math.max(e.clientX - box.left, 0), box.width);
      const t  = g.t0 + (px / box.width) * g.span;

      // Le relevé le plus proche dans le TEMPS : les points ne sont pas
      // régulièrement espacés (websocket ≈ 1 s, repli REST 30 s), chercher par
      // indice tomberait à côté après une coupure.
      let best = s.points[0], bestGap = Infinity;
      s.points.forEach(function (p) {
        const gap = Math.abs(p.t - t);
        if (gap < bestGap) { bestGap = gap; best = p; }
      });

      const left = ((best.t - g.t0) / g.span) * box.width;
      const top  = (1 - Math.min(1, best.v / g.top)) * box.height;

      if (s.cursor) s.cursor.style.left = left + 'px';
      if (s.dot) { s.dot.style.left = left + 'px'; s.dot.style.top = top + 'px'; }
      if (s.tip) {
        s.tip.textContent = sparkClock(best.t) + ' · ' + (s.format ? s.format(best.v) : String(best.v));
        // La carte est en overflow:hidden : l'étiquette se retourne sous le
        // point quand il n'y a plus la place au-dessus, et reste dans les bords.
        s.tip.classList.toggle('is-below', top < 26);
        s.tip.style.left = Math.min(Math.max(left, 32), box.width - 32) + 'px';
        s.tip.style.top  = top + 'px';
      }

      s.card.classList.add('is-probing');
    }

    function sparkPush(prefix, value, top, format) {
      if (!(prefix in sparks)) sparks[prefix] = sparkSetup(prefix);
      const s = sparks[prefix];
      if (!s) return;

      const now = Date.now();
      s.points.push({ t: now, v: Math.max(0, Number(value) || 0) });

      // Fenêtre glissante, puis garde-fou en nombre de points : une page laissée
      // ouverte une journée ne doit ni ramer ni gonfler.
      const floor = now - SPARK_WINDOW_MS;
      while (s.points.length > 1 && s.points[0].t < floor) s.points.shift();
      while (s.points.length > SPARK_MAX_POINTS) s.points.shift();

      s.top = Number(top) || 0;
      s.format = format;
      sparkDraw(s);
    }

    // La jauge horizontale a disparu des cartes : le graphe de fond dit la même
    // chose, en montrant en plus d'où vient la valeur.
    function setMeter(prefix, used, limit, text) {
      const t = metric(prefix + '-text');
      const l = metric(prefix + '-limit');

      // Valeur sur une ligne, limite en dessous et en plus petit :
      //   609 Mio
      //   /1000 Mio
      // Chaque membre garde son unité naturelle — écrire « 0.6 Gio » pour tenir
      // la même unité que la limite ferait perdre la précision du côté qui bouge.
      const cap = limit > 0 ? (prefix === 'cpu' ? limit + ' %' : bytes(limit)) : '';
      if (t) {
        t.textContent = '';

        const now = document.createElement('span');
        now.textContent = text;
        t.appendChild(now);

        if (cap !== '') {
          const max = document.createElement('span');
          max.className = 'metric-cap';
          max.textContent = '/' + cap;
          t.appendChild(max);
        }
      }

      // Il ne reste à dire que ce que la ligne du dessus ne dit pas : l'absence
      // de limite. Répéter « Limite : 1000 Mio » juste en dessous ne servirait
      // plus à rien.
      if (l) l.textContent = limit > 0 ? '' : 'Illimité';

      // Même source que la jauge : le fond de la carte raconte d'où vient le
      // chiffre affiché juste au-dessus.
      sparkPush(prefix, used, limit, prefix === 'cpu'
        ? function (v) { return v.toFixed(1) + ' %'; }
        : bytes);
    }

    // Pastille d'état, posée sur la photo du hero : fond translucide uniforme,
    // c'est le libellé (running / stopping / offline) qui porte l'information.
    // Le statut de facturation « suspended » a sa propre pastille, rendue côté
    // PHP : il ne change pas au fil du websocket.
    const KNOWN_STATES = ['running', 'starting', 'stopping', 'offline'];

    // État courant du processus, mémorisé : c'est lui qui décide des actions
    // disponibles. Sans cette mémoire, la fin d'une action réactivait les quatre
    // boutons sans tenir compte de l'état réel.
    let currentState = 'unknown';

    // Serveur éteint  → « Arrêter » et « Tuer » n'ont rien à arrêter.
    // Serveur allumé  → « Démarrer » n'a rien à démarrer.
    // « Redémarrer » reste toujours accessible : sur un serveur éteint, il
    // l'allume. « stopping » et « unknown » ne brident rien : pendant un arrêt
    // qui traîne, « Tuer » doit rester la porte de sortie.
    const POWER_BLOCKED = {
      offline:  { stop: 'Le serveur est déjà arrêté.', kill: 'Le serveur est déjà arrêté.' },
      running:  { start: 'Le serveur tourne déjà.' },
      starting: { start: 'Le serveur est en cours de démarrage.' }
    };

    function applyPowerAvailability() {
      if (!powerEl) return;
      const blocked = POWER_BLOCKED[currentState] || {};
      powerEl.querySelectorAll('button[data-signal]').forEach(function (b) {
        const reason = blocked[b.getAttribute('data-signal')];
        b.disabled = !!reason;
        if (reason) b.setAttribute('title', reason);
        else b.removeAttribute('title');
      });
    }

    function setState(state) {
      state = String(state || 'unknown');
      currentState = KNOWN_STATES.indexOf(state) !== -1 ? state : 'unknown';

      if (stateEl) {
        stateEl.textContent = currentState;
        // C'est cet attribut que le CSS de la page lit pour teinter la pastille.
        stateEl.setAttribute('data-state', currentState);
      }
      applyPowerAvailability();
      if (cmdInput) cmdInput.disabled = (currentState !== 'running');
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
        if (nodeEl) renderNode(nodeEl, d.server.node || '', d.server.location || '');

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

    // Arrêt définitif des tentatives : on explique, et on garde les compteurs à jour.
    function giveUpConsole(why) {
      consoleGaveUp = true;
      if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
      if (socketEl) socketEl.textContent = 'Console indisponible';

      write('[portail] Console live abandonnée après ' + consoleTries + ' tentatives'
        + (why ? ' (' + why + ')' : '') + '.', 'line-err');
      write('[portail] Les compteurs continuent d\'être actualisés toutes les '
        + (POLL_MS / 1000) + ' s. L\'envoi de commandes reste opérationnel.', 'line-sys');
      write('[portail] Cause habituelle : le nœud wings refuse l\'origine ' + window.location.origin
        + '. À ajouter dans « allowed_origins » de /etc/pterodactyl/config.yml sur le nœud, '
        + 'puis « systemctl restart wings ».', 'line-sys');

      startPolling();
      pollResources();   // sans attendre le prochain tick
    }

    // Replanifie une tentative, ou abandonne si le quota d'essais est épuisé.
    function retryConsole(why) {
      if (consoleGaveUp) return;
      if (consoleTries >= CONSOLE_MAX_TRIES) { giveUpConsole(why); return; }

      const wait = Math.max(reconnectDelay, pausedUntil - Date.now());
      if (socketEl) socketEl.textContent = 'Reconnexion dans ' + Math.ceil(wait / 1000) + ' s';
      startPolling();
      if (reconnectTimer) clearTimeout(reconnectTimer);
      reconnectTimer = setTimeout(connectConsole, wait);
      reconnectDelay = Math.min(RECONNECT_MAX, reconnectDelay * 2);
    }

    async function connectConsole() {
      if (consoleGaveUp) return;
      if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
      consoleTries++;
      authOk = false;

      let creds;
      try {
        creds = await call('websocket');
      } catch (e) {
        write('[portail] Jeton de console refusé : ' + (e && e.message ? e.message : e), 'line-err');
        retryConsole('jeton refusé');
        return;
      }

      try {
        socket = new WebSocket(creds.socket);
      } catch (e) {
        write('[portail] Connexion impossible au nœud : ' + creds.socket, 'line-err');
        retryConsole('socket inutilisable');
        return;
      }

      socket.addEventListener('open', function () {
        openedAt = Date.now();
        if (socketEl) socketEl.textContent = 'Authentification…';
        send('auth', creds.token);
      });

      socket.addEventListener('message', function (ev) {
        let msg = null;
        try { msg = JSON.parse(ev.data); } catch (_) { return; }
        const args = Array.isArray(msg.args) ? msg.args : [];

        switch (msg.event) {
          case 'auth success':
            // Seul vrai signal de succès : une connexion TCP ouverte ne prouve
            // rien, wings peut fermer juste après le handshake.
            authOk = true;
            consoleTries = 0;
            reconnectDelay = RECONNECT_MIN;
            if (socketEl) socketEl.textContent = 'Connectée';
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

      socket.addEventListener('close', function (ev) {
        if (closedByUs) return;

        const lived = openedAt ? Date.now() - openedAt : 0;
        const why = 'code ' + ev.code + (ev.reason ? ' — ' + ev.reason : '');

        if (!authOk) {
          // Fermé sans jamais s'authentifier : handshake ou jeton rejeté par
          // wings. Le code 1006 sans raison = refus au niveau HTTP (Origin,
          // TLS, pare-feu) — le navigateur n'en dit pas plus, par conception.
          write('[portail] Console fermée avant authentification (' + why + ', après '
            + lived + ' ms).', 'line-err');
        }

        retryConsole(why);
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
      powerEl.addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-signal]');
        if (!btn || btn.disabled) return;
        const signal = btn.getAttribute('data-signal');

        // « Tuer » coupe le processus sans arrêt propre : on demande
        // confirmation au lieu d'envoyer le signal au premier clic.
        if (signal === 'kill') { openKillModal(); return; }

        sendPower(signal);
      });
    }

    // Envoi effectif d'un signal d'alimentation.
    async function sendPower(signal) {
      if (!powerEl) return;

      // Tout figer le temps de l'aller-retour, pour éviter le double clic.
      const buttons = powerEl.querySelectorAll('button[data-signal]');
      buttons.forEach(function (b) { b.disabled = true; });
      try {
        await call('power', 'POST', { signal: signal });
        showError('');
        write('[portail] Signal « ' + signal + ' » envoyé.', 'line-sys');
      } catch (err) {
        showError(err && err.message ? err.message : String(err));
        throw err;                 // le modal doit savoir que ça a échoué
      } finally {
        // Le nouvel état arrive par le websocket (ou le prochain sondage) ;
        // on laisse ce court délai puis on REAPPLIQUE la règle au lieu de
        // rallumer les quatre boutons — sinon « Démarrer » redevenait
        // cliquable sur un serveur qui tourne.
        setTimeout(applyPowerAvailability, 1500);
      }
    }

    // ── Modal de confirmation « Tuer » ───────────────────────────────────────
    const killModal = document.getElementById('killServerModal');

    function killStatus(text, kind) {
      const el = killModal && killModal.querySelector('[data-kill-status]');
      if (!el) return;
      el.textContent = text || '';
      el.className = 'mt-3 text-xs ' + (kind === 'err' ? 'text-red-600' : 'text-muted-foreground');
    }

    function openKillModal() {
      if (!killModal) { sendPower('kill').catch(function () {}); return; }  // repli
      killStatus('');
      killModal.classList.remove('hidden');
      killModal.classList.add('flex');
      // Focus sur « Annuler » : l'action par défaut d'un modal destructeur ne
      // doit pas être celle qui détruit. setTimeout plutôt que
      // requestAnimationFrame, défini partout.
      const cancel = killModal.querySelector('[data-kill-cancel]');
      if (cancel) setTimeout(function () { cancel.focus(); }, 0);
    }

    function closeKillModal() {
      if (!killModal) return;
      killModal.classList.remove('flex');
      killModal.classList.add('hidden');
    }

    if (killModal) {
      killModal.querySelectorAll('[data-kill-cancel]').forEach(function (b) {
        b.addEventListener('click', closeKillModal);
      });
      // Clic sur le fond et Échap : mêmes sorties que les autres modals.
      killModal.addEventListener('click', function (e) { if (e.target === killModal) closeKillModal(); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && killModal.classList.contains('flex')) closeKillModal();
      });

      const killConfirm = killModal.querySelector('[data-kill-confirm]');
      if (killConfirm) {
        killConfirm.addEventListener('click', async function () {
          killConfirm.disabled = true;
          killStatus('Envoi du signal…');
          try {
            await sendPower('kill');
            closeKillModal();
          } catch (err) {
            // L'erreur est déjà affichée dans le bandeau ; on la répète ici
            // pour que l'utilisateur la voie sans fermer le modal.
            killStatus(err && err.message ? err.message : String(err), 'err');
          } finally {
            killConfirm.disabled = false;
          }
        });
      }
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

  <!-- ══════════════════════════════════════════════════════════════════════
       EXPLORATEUR DE FICHIERS — navigation et gestion
       IIFE indépendante du pilotage : une panne du websocket ne doit pas
       emporter l'explorateur, ni l'inverse.
  ══════════════════════════════════════════════════════════════════════ -->
  <script>
  (function () {
    const PRODUCT_UID = <?= json_encode($productUid, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const CSRF        = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const SUSPENDED   = <?= $isSuspended ? 'true' : 'false' ?>;
    const API         = new URL('../data/ptero_api.php', window.location.href);

    const root       = document.getElementById('pteroFiles');
    const body       = document.getElementById('filesBody');
    const crumbs     = document.getElementById('filesCrumbs');
    const statusEl   = document.getElementById('filesStatus');
    const sortEl     = document.getElementById('filesSort');
    const searchEl   = document.getElementById('filesSearch');
    const selectAll  = document.getElementById('filesSelectAll');
    const reloadBtn  = document.getElementById('filesReload');
    const mkdirBtn   = document.getElementById('filesMkdir');
    const uploadBtn  = document.getElementById('filesUpload');
    const deleteBtn  = document.getElementById('filesDeleteSel');
    const fileInput  = document.getElementById('filesInput');
    if (!root || !body) return;

    const COLSPAN = 5;

    // Repère visuel des dossiers, aligné sur le trait du reste du portail.
    const FOLDER_ICON =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
      + ' stroke-linejoin="round" aria-hidden="true"'
      + ' style="width:1rem;height:1rem;display:inline-block;vertical-align:-.2rem;margin-right:.4rem;opacity:.7">'
      + '<path d="M3 8.2c0-1.12 0-1.68.218-2.108A2 2 0 0 1 4.092 5.218C4.52 5 5.08 5 6.2 5h3.475c.489 0'
      + ' .733 0 .963.055a2 2 0 0 1 .579.24c.201.123.374.296.72.642l.126.126c.346.346.519.519.72.642a2 2 0 0'
      + ' 0 .579.24c.23.055.474.055.963.055H17.8c1.12 0 1.68 0 2.108.218a2 2 0 0 1 .874.874C21 8.52 21 9.08'
      + ' 21 10.2v5.6c0 1.12 0 1.68-.218 2.108a2 2 0 0 1-.874.874C19.48 19 18.92 19 17.8 19H6.2c-1.12'
      + ' 0-1.68 0-2.108-.218a2 2 0 0 1-.874-.874C3 17.48 3 16.92 3 15.8V8.2Z"/></svg>';

    let currentPath = '/';
    let items       = [];
    let selected    = new Set();
    let sortMode    = sortEl ? sortEl.value : 'name-asc';
    let search      = '';

    // ── Utilitaires ────────────────────────────────────────────────────────
    const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

    function cleanPath(value) {
      const out = [];
      String(value || '/').split('/').forEach(function (seg) {
        if (seg === '' || seg === '.') return;
        if (seg === '..') { out.pop(); return; }
        out.push(seg);
      });
      return '/' + out.join('/');
    }
    const joinPath = (dir, name) => cleanPath((dir === '/' ? '' : dir) + '/' + name);

    function humanSize(value) {
      const n = Number(value);
      if (!Number.isFinite(n) || n < 0) return '—';
      if (n < 1024) return n + ' o';
      const units = ['Kio', 'Mio', 'Gio', 'Tio'];
      let v = n, u = 'o';
      for (const next of units) { v /= 1024; u = next; if (v < 1024) break; }
      return (v >= 10 ? v.toFixed(0) : v.toFixed(1)) + ' ' + u;
    }

    function humanDate(iso) {
      const t = Date.parse(String(iso || ''));
      if (!Number.isFinite(t)) return '—';
      const d = new Date(t);
      const p = (x) => String(x).padStart(2, '0');
      return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear()
        + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function setStatus(text, kind) {
      if (!statusEl) return;
      const tone = kind === 'err' ? 'text-red-600'
                 : kind === 'ok'  ? 'text-emerald-600'
                 : kind === 'warn'? 'text-amber-600'
                 : 'text-muted-foreground';
      statusEl.className = 'mt-3 text-xs ' + tone;
      statusEl.textContent = String(text || '');
    }

    // Erreur lisible : la page d'erreur de l'Ingress porte un champ « error »
    // booléen (true), qui afficherait « true » si on le prenait tel quel.
    function apiError(data, status) {
      if (data && typeof data.error === 'string' && data.error) return data.error;
      if (data && typeof data.message === 'string' && data.message) return data.message;
      return 'HTTP ' + status;
    }

    async function call(action, method, params) {
      const u = new URL(API.toString());
      u.searchParams.set('action', action);
      u.searchParams.set('product_uid', PRODUCT_UID);

      const opts = { method: method || 'GET', credentials: 'same-origin', headers: {} };
      if ((method || 'GET') === 'POST') {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        opts.headers['X-CSRF-Token'] = CSRF;
        const form = new URLSearchParams();
        form.set('product_uid', PRODUCT_UID);
        Object.entries(params || {}).forEach(function ([k, v]) {
          if (Array.isArray(v)) v.forEach((one) => form.append(k + '[]', String(one)));
          else form.set(k, String(v));
        });
        opts.body = form;
      } else {
        Object.entries(params || {}).forEach(([k, v]) => u.searchParams.set(k, String(v)));
      }

      const res = await fetch(u.toString(), opts);
      const raw = await res.text();
      let data = null;
      try { data = JSON.parse(raw); } catch (_) { /* ignore */ }
      if (!data) {
        throw new Error('Réponse non-JSON (' + res.status + '). '
          + raw.slice(0, 200).replace(/\s+/g, ' '));
      }
      if (!res.ok || !data.ok) throw new Error(apiError(data, res.status));
      return data;
    }

    // ── Rendu ──────────────────────────────────────────────────────────────
    function renderCrumbs() {
      if (!crumbs) return;
      crumbs.innerHTML = '';
      const parts = currentPath.split('/').filter(Boolean);

      const mk = (label, path, clickable) => {
        const el = document.createElement(clickable ? 'button' : 'span');
        el.className = clickable ? 'explorer-path-link' : 'explorer-path-text';
        el.textContent = label;
        if (clickable) { el.type = 'button'; el.addEventListener('click', () => go(path)); }
        crumbs.appendChild(el);
      };

      mk('/', '/', parts.length > 0);
      parts.forEach(function (part, i) {
        if (i > 0) {
          const sep = document.createElement('span');
          sep.className = 'explorer-path-sep';
          sep.textContent = '/';
          crumbs.appendChild(sep);
        }
        mk(part, '/' + parts.slice(0, i + 1).join('/'), i < parts.length - 1);
      });
    }

    function message(text) {
      body.innerHTML = '<tr><td colspan="' + COLSPAN
        + '" class="border-surface border-b p-3 text-muted-foreground">' + esc(text) + '</td></tr>';
    }

    function visibleItems() {
      let list = items.slice();
      if (search) list = list.filter((it) => String(it.name || '').toLowerCase().includes(search));

      list.sort(function (a, b) {
        // Les dossiers d'abord : c'est ainsi que se lit une arborescence.
        if ((a.type === 'dir') !== (b.type === 'dir')) return a.type === 'dir' ? -1 : 1;
        const na = String(a.name || ''), nb = String(b.name || '');
        if (sortMode === 'name-desc')  return nb.localeCompare(na, 'fr', { numeric: true, sensitivity: 'base' });
        if (sortMode === 'mtime-desc') return (Date.parse(b.mtime || '') || 0) - (Date.parse(a.mtime || '') || 0);
        if (sortMode === 'size-desc')  return Number(b.size || 0) - Number(a.size || 0);
        return na.localeCompare(nb, 'fr', { numeric: true, sensitivity: 'base' });
      });
      return list;
    }

    function syncSelection() {
      const shown = visibleItems().map((it) => it.name);
      const all = shown.length > 0 && shown.every((n) => selected.has(n));
      if (selectAll) {
        selectAll.checked = all;
        selectAll.indeterminate = !all && shown.some((n) => selected.has(n));
      }
      if (deleteBtn) {
        deleteBtn.disabled = SUSPENDED || selected.size === 0;
        deleteBtn.textContent = selected.size > 0
          ? 'Supprimer (' + selected.size + ')'
          : 'Supprimer';
      }
    }

    function render() {
      const list = visibleItems();
      body.innerHTML = '';

      if (list.length === 0) {
        message(items.length === 0 ? 'Ce dossier est vide.' : 'Aucun élément ne correspond à la recherche.');
        syncSelection();
        return;
      }

      list.forEach(function (item) {
        const isDir = item.type === 'dir';
        const tr = document.createElement('tr');
        tr.innerHTML =
          '<td class="border-surface border-b p-3">'
        +   '<div class="flex items-center gap-2">'
        +     '<input type="checkbox" class="files-check" data-pick aria-label="' + esc(item.name) + '"'
        +       (selected.has(item.name) ? ' checked' : '') + ' />'
        +     '<button type="button" class="files-name" data-open>'
        +       (isDir ? FOLDER_ICON : '') + esc(item.name)
        +       (item.symlink ? ' <span class="opacity-60">↪</span>' : '')
        +     '</button>'
        +   '</div>'
        + '</td>'
        + '<td class="border-surface border-b p-3 mono text-xs whitespace-nowrap">' + esc(humanDate(item.mtime)) + '</td>'
        + '<td class="border-surface border-b p-3 text-xs">' + (isDir ? 'Dossier' : 'Fichier') + '</td>'
        + '<td class="border-surface border-b p-3 text-xs tabular-nums whitespace-nowrap">'
        +   (isDir ? '—' : esc(humanSize(item.size))) + '</td>'
        + '<td class="border-surface border-b p-3 text-end whitespace-nowrap">'
        +   (isDir ? '' :
              '<button type="button" class="files-act" data-edit title="Éditer"'
              + (item.editable ? '' : ' disabled style="opacity:.3;cursor:default"') + '><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg></button>'
            + '<button type="button" class="files-act" data-download title="Télécharger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></button>')
        +   '<button type="button" class="files-act" data-rename title="Renommer"'
        +     (SUSPENDED ? ' disabled style="opacity:.3;cursor:default"' : '') + '><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg></button>'
        +   '<button type="button" class="files-act" data-delete title="Supprimer"'
        +     (SUSPENDED ? ' disabled style="opacity:.3;cursor:default"' : '') + '><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>'
        + '</td>';

        tr.querySelector('[data-pick]').addEventListener('change', function (e) {
          if (e.target.checked) selected.add(item.name); else selected.delete(item.name);
          syncSelection();
        });

        const openBtn = tr.querySelector('[data-open]');
        openBtn.addEventListener('click', function () {
          if (isDir) { go(joinPath(currentPath, item.name)); return; }
          if (item.editable) { openEditor(item); return; }
          download(item);
        });

        tr.querySelector('[data-edit]')?.addEventListener('click', () => openEditor(item));
        tr.querySelector('[data-download]')?.addEventListener('click', () => download(item));
        tr.querySelector('[data-rename]')?.addEventListener('click', () => askRename(item));
        tr.querySelector('[data-delete]')?.addEventListener('click', () => askDelete([item.name]));

        body.appendChild(tr);
      });

      syncSelection();
    }

    // ── Chargement ─────────────────────────────────────────────────────────
    async function go(path) {
      currentPath = cleanPath(path);
      selected = new Set();
      renderCrumbs();
      message('Chargement…');
      setStatus('');
      try {
        const data = await call('files_list', 'GET', { path: currentPath });
        items = Array.isArray(data.items) ? data.items : [];
        render();
        setStatus(items.length + ' élément' + (items.length > 1 ? 's' : ''));
      } catch (err) {
        items = [];
        message('Dossier illisible.');
        setStatus(err && err.message ? err.message : String(err), 'err');
      }
    }

    // ── Modals ─────────────────────────────────────────────────────────────
    function modal(el) {
      const open = () => { el.classList.remove('hidden'); el.classList.add('flex'); };
      const close = () => { el.classList.remove('flex'); el.classList.add('hidden'); };
      el.addEventListener('click', (e) => { if (e.target === el) close(); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && el.classList.contains('flex')) close();
      });
      return { open, close, isOpen: () => el.classList.contains('flex') };
    }

    function statusIn(el, selector, text, kind) {
      const node = el.querySelector(selector);
      if (!node) return;
      node.className = 'mt-3 text-xs ' + (kind === 'err' ? 'text-red-600'
        : kind === 'ok' ? 'text-emerald-600' : 'text-muted-foreground');
      node.textContent = String(text || '');
    }

    // Éditeur
    const editorEl   = document.getElementById('pteroEditorModal');
    const editor     = editorEl ? modal(editorEl) : null;
    const editorText = document.getElementById('pteroEditorText');
    const editorPath = document.getElementById('pteroEditorPath');
    const editorSave = editorEl ? editorEl.querySelector('[data-editor-save]') : null;
    let   editing    = null;
    // Tant que le contenu n'est pas arrivé, « Enregistrer » écrirait le textarea
    // vide par-dessus le fichier : un binaire refusé, une coupure réseau, et le
    // fichier du client était remis à zéro. On n'arme le bouton qu'au succès.
    let   editorReady = false;

    async function openEditor(item) {
      if (!editor || !editorText) { download(item); return; }
      editing = joinPath(currentPath, item.name);
      editorReady = false;
      if (editorPath) editorPath.textContent = editing;
      if (editorSave) editorSave.disabled = true;
      editorText.value = '';
      editorText.disabled = true;
      statusIn(editorEl, '[data-editor-status]', 'Chargement…');
      editor.open();
      try {
        const data = await call('file_contents', 'GET', { path: editing });
        editorText.value = String(data.content || '');
        editorText.disabled = SUSPENDED;
        editorReady = !SUSPENDED;
        if (editorSave) editorSave.disabled = SUSPENDED;
        statusIn(editorEl, '[data-editor-status]', SUSPENDED ? 'Service suspendu : lecture seule.' : '');
      } catch (err) {
        statusIn(editorEl, '[data-editor-status]', err && err.message ? err.message : String(err), 'err');
      }
    }

    if (editorEl) {
      editorEl.querySelectorAll('[data-editor-cancel]').forEach((b) => b.addEventListener('click', editor.close));
      const saveBtn = editorSave;
      saveBtn?.addEventListener('click', async function () {
        if (!editing || !editorReady) return;
        saveBtn.disabled = true;
        statusIn(editorEl, '[data-editor-status]', 'Enregistrement…');
        try {
          await call('file_write', 'POST', { path: editing, content: editorText.value });
          statusIn(editorEl, '[data-editor-status]', 'Enregistré.', 'ok');
          await go(currentPath);
        } catch (err) {
          statusIn(editorEl, '[data-editor-status]', err && err.message ? err.message : String(err), 'err');
        } finally {
          saveBtn.disabled = !editorReady;
        }
      });
    }

    // Saisie d'un nom (renommage, nouveau dossier)
    const promptEl    = document.getElementById('pteroPromptModal');
    const prompt      = promptEl ? modal(promptEl) : null;
    const promptInput = document.getElementById('pteroPromptInput');
    let   onPrompt    = null;

    function askName(title, text, value, handler) {
      if (!prompt || !promptInput) return;
      document.getElementById('pteroPromptTitle').textContent = title;
      document.getElementById('pteroPromptText').textContent = text;
      promptInput.value = value || '';
      statusIn(promptEl, '[data-prompt-status]', '');
      onPrompt = handler;
      prompt.open();
      setTimeout(function () { promptInput.focus(); promptInput.select(); }, 0);
    }

    if (promptEl) {
      promptEl.querySelectorAll('[data-prompt-cancel]').forEach((b) => b.addEventListener('click', prompt.close));
      const confirmBtn = promptEl.querySelector('[data-prompt-confirm]');
      const submit = async function () {
        const value = promptInput.value.trim();
        if (!value) { statusIn(promptEl, '[data-prompt-status]', 'Saisissez un nom.', 'err'); return; }
        if (value.includes('/')) { statusIn(promptEl, '[data-prompt-status]', 'Le nom ne peut pas contenir « / ».', 'err'); return; }
        confirmBtn.disabled = true;
        statusIn(promptEl, '[data-prompt-status]', 'Envoi…');
        try {
          await onPrompt(value);
          prompt.close();
          await go(currentPath);
        } catch (err) {
          statusIn(promptEl, '[data-prompt-status]', err && err.message ? err.message : String(err), 'err');
        } finally {
          confirmBtn.disabled = false;
        }
      };
      confirmBtn?.addEventListener('click', submit);
      promptInput?.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
    }

    const askRename = (item) => askName('Renommer', 'Nouveau nom de « ' + item.name + ' ».', item.name,
      (value) => call('file_rename', 'POST', { root: currentPath, from: item.name, to: value }));

    mkdirBtn?.addEventListener('click', () => askName('Nouveau dossier', 'Créé dans ' + currentPath + '.', '',
      (value) => call('file_mkdir', 'POST', { root: currentPath, name: value })));

    // Suppression
    const deleteEl = document.getElementById('pteroDeleteModal');
    const delModal = deleteEl ? modal(deleteEl) : null;
    let   toDelete = [];

    function askDelete(names) {
      toDelete = names.filter(Boolean);
      if (toDelete.length === 0 || !delModal) return;
      document.getElementById('pteroDeleteText').textContent =
        toDelete.length === 1
          ? 'Cet élément sera supprimé du serveur. Un dossier part avec tout son contenu, et rien n\'est récupérable.'
          : 'Ces ' + toDelete.length + ' éléments seront supprimés du serveur. Un dossier part avec tout son contenu, et rien n\'est récupérable.';
      const list = document.getElementById('pteroDeleteList');
      list.innerHTML = '';
      toDelete.slice(0, 20).forEach(function (n) {
        const li = document.createElement('li');
        li.textContent = joinPath(currentPath, n);
        list.appendChild(li);
      });
      if (toDelete.length > 20) {
        const li = document.createElement('li');
        li.textContent = '… et ' + (toDelete.length - 20) + ' autres.';
        list.appendChild(li);
      }
      statusIn(deleteEl, '[data-delete-status]', '');
      delModal.open();
      setTimeout(function () { deleteEl.querySelector('[data-delete-cancel]')?.focus(); }, 0);
    }

    if (deleteEl) {
      deleteEl.querySelectorAll('[data-delete-cancel]').forEach((b) => b.addEventListener('click', delModal.close));
      const confirmBtn = deleteEl.querySelector('[data-delete-confirm]');
      confirmBtn?.addEventListener('click', async function () {
        confirmBtn.disabled = true;
        statusIn(deleteEl, '[data-delete-status]', 'Suppression…');
        try {
          await call('file_delete', 'POST', { root: currentPath, files: toDelete });
          delModal.close();
          selected = new Set();
          await go(currentPath);
          setStatus(toDelete.length + ' élément' + (toDelete.length > 1 ? 's supprimés.' : ' supprimé.'), 'ok');
        } catch (err) {
          statusIn(deleteEl, '[data-delete-status]', err && err.message ? err.message : String(err), 'err');
        } finally {
          confirmBtn.disabled = false;
        }
      });
    }

    deleteBtn?.addEventListener('click', () => askDelete(Array.from(selected)));

    // ── Téléchargement et téléversement : URL signées du panel ─────────────
    async function download(item) {
      setStatus('Préparation du téléchargement…');
      try {
        const data = await call('file_download', 'GET', { path: joinPath(currentPath, item.name) });
        // URL à usage unique : on ouvre un onglet plutôt que de remplacer la
        // page, pour ne pas perdre la console en cours.
        window.open(data.url, '_blank', 'noopener');
        setStatus('');
      } catch (err) {
        setStatus(err && err.message ? err.message : String(err), 'err');
      }
    }

    uploadBtn?.addEventListener('click', () => fileInput?.click());

    fileInput?.addEventListener('change', async function () {
      const files = Array.from(fileInput.files || []);
      fileInput.value = '';
      if (files.length === 0) return;

      setStatus('Téléversement…');
      try {
        const data = await call('file_upload_url', 'POST', { path: currentPath });
        const target = data.url + (data.url.includes('?') ? '&' : '?')
          + 'directory=' + encodeURIComponent(currentPath);

        // Un envoi par fichier : une erreur sur l'un n'emporte pas les autres,
        // et le message dit lequel a échoué.
        let done = 0;
        for (const file of files) {
          const form = new FormData();
          form.append('files', file, file.name);
          const res = await fetch(target, { method: 'POST', body: form });
          if (!res.ok) throw new Error('« ' + file.name + ' » refusé par le panel (HTTP ' + res.status + ').');
          done++;
          setStatus('Téléversement… ' + done + '/' + files.length);
        }
        await go(currentPath);
        setStatus(done + ' fichier' + (done > 1 ? 's envoyés.' : ' envoyé.'), 'ok');
      } catch (err) {
        setStatus(err && err.message ? err.message : String(err), 'err');
      }
    });

    // ── Barre d'outils ─────────────────────────────────────────────────────
    reloadBtn?.addEventListener('click', () => go(currentPath));
    sortEl?.addEventListener('change', function () { sortMode = sortEl.value; render(); });
    searchEl?.addEventListener('input', function () { search = searchEl.value.trim().toLowerCase(); render(); });
    selectAll?.addEventListener('change', function () {
      const shown = visibleItems().map((it) => it.name);
      if (selectAll.checked) shown.forEach((n) => selected.add(n));
      else shown.forEach((n) => selected.delete(n));
      render();
    });

    // Service suspendu : le panel refuserait de toute façon les écritures.
    if (SUSPENDED) {
      [mkdirBtn, uploadBtn, deleteBtn].forEach(function (b) {
        if (!b) return;
        b.disabled = true;
        b.setAttribute('title', 'Service suspendu : les fichiers sont en lecture seule.');
      });
    }

    go('/');
  })();
  </script>

  <!-- ═════════════════════════════════════════════════════════════════════
       MODALE « PARAMÈTRES » — ouverte par l'engrenage du bandeau, là où
       s'affichait la pastille d'état.

       Le câblage est complet (ouverture, fermeture, Échap, clic hors de la
       boîte). Le CONTENU reste à définir : il se pose dans
       [data-settings-body], en remplaçant le paragraphe d'attente.
  ════════════════════════════════════════════════════════════════════ -->
  <div id="serviceSettingsModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
       role="dialog" aria-modal="true" aria-labelledby="serviceSettingsTitle">
    <div class="w-full max-w-md rounded border bg-card text-card-foreground shadow-lg">
      <div class="p-6">
        <div class="flex items-start justify-between gap-4">
          <h2 id="serviceSettingsTitle" class="text-lg font-semibold"><?= t('Paramètres') ?></h2>
          <button type="button" data-settings-close
            class="inline-flex h-9 items-center justify-center rounded border px-3 text-sm font-medium transition-all hover:bg-secondary"
            aria-label="<?= t('Fermer') ?>"><?= t('Fermer') ?></button>
        </div>

        <div data-settings-body class="mt-5 space-y-4">
          <p class="text-sm text-muted-foreground"><?= t('Aucun réglage disponible pour le moment.') ?></p>
        </div>
      </div>
    </div>
  </div>

  <script>
  (function () {
    var modal = document.getElementById('serviceSettingsModal');
    var gear  = document.querySelector('[data-settings-open]');
    if (!modal || !gear) return;

    function show() { modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function hide() { modal.classList.remove('flex'); modal.classList.add('hidden'); }

    gear.addEventListener('click', show);
    modal.querySelectorAll('[data-settings-close]').forEach(function (b) {
      b.addEventListener('click', hide);
    });
    // Clic sur le voile, pas sur la boîte.
    modal.addEventListener('click', function (e) { if (e.target === modal) hide(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('flex')) hide();
    });
  })();
  </script>

  <script src="../assets/js/services_menu.js" defer></script>
</body>
</html>
