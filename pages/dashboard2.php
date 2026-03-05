<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: /connexion");
    exit();
}

$name = $_SESSION['user']['name'];
$siren = $_SESSION['user']['siren'];
$structure_type = $_SESSION['user']['structure_type'];

// Inclusion du fichier de configuration qui crée $pdo (base principale) et $pdo_powerdns (base PowerDNS)
require_once '../config_loader.php';

// Récupérer les domaines PowerDNS pour l'utilisateur
$user_account = $_SESSION['user']['id'];
$query_domains = $pdo_powerdns->prepare("SELECT id, name FROM domains WHERE account = ?");
$query_domains->execute([$user_account]);
$domains = $query_domains->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Dashboard - GNL Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
<meta name="next-size-adjust" content=""/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
  
    /* --- Chart section (petite animation, sans tomber dans le cirque) --- */
    @keyframes fadeUp {
      from { opacity: 0; transform: translate3d(0, 10px, 0); }
      to   { opacity: 1; transform: translate3d(0, 0, 0); }
    }
    .chart-reveal { opacity: 0; transform: translate3d(0, 10px, 0); }
    .chart-reveal.is-visible { animation: fadeUp .6s ease-out both; }

    .metric-card { transition: transform .2s ease, box-shadow .2s ease; }
    .metric-card:hover { transform: translate3d(0, -2px, 0); }

    @media (prefers-reduced-motion: reduce) {
      .chart-reveal, .chart-reveal.is-visible { opacity: 1; transform: none; animation: none; }
      .metric-card { transition: none; }
    }

  </style>
</head>
<body class="bg-background text-foreground">
  <?php include("../include/header.php"); ?>
  <div class="dashboard-layout">
    <div class="bg-background flex h-screen w-full max-w-xs flex-col overflow-y-auto border shadow-sm dashboard-sidebar">
<div class="px-6 pt-6"></div>
<div class="flex-1 px-6 pb-6">
<small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Navigation</small>
<nav class="mb-4 space-y-0.5 border-b pb-4">
<div data-slot="collapsible" data-state="closed">
<button aria-controls="radix-«Rl7neplb»" aria-expanded="false" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors" data-slot="collapsible-trigger" data-state="closed" type="button">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-layout-grid h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><rect height="7" rx="1" width="7" x="3" y="3"></rect><rect height="7" rx="1" width="7" x="14" y="3"></rect><rect height="7" rx="1" width="7" x="14" y="14"></rect><rect height="7" rx="1" width="7" x="3" y="14"></rect></svg></span><span class="font-medium">Projet A</span><span class="ml-auto grid shrink-0 place-items-center pl-2.5"><svg class="lucide lucide-chevron-right h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m9 18 6-6-6-6"></path></svg></span>
</button>
<div class="mt-1 space-y-1" data-slot="collapsible-content" data-state="closed" hidden="" id="radix-«Rl7neplb»"></div>
</div>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="#">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-package h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg></span>
<span class="font-medium">Products</span>
</a>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="#">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-shopping-cart h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="21" r="1"></circle><circle cx="19" cy="21" r="1"></circle><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"></path></svg></span>
<span class="font-medium">Orders</span>
</a>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="#"><span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-users h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
<span class="font-medium">Customers</span>
</a>
</nav>
<small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Support</small>
<nav class="space-y-0.5">
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="#">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-headphones h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"></path></svg></span>
<span class="font-medium">Help and Support</span>
</a>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="#">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-log-out h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" x2="9" y1="12" y2="12"></line></svg></span>
<span class="font-medium">Sign Out</span>
</a>
</nav>
</div>
<div class="mt-auto p-6 pt-0">
<div class="bg-border shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px mb-6" data-orientation="horizontal" data-slot="separator" role="none"></div>
<div class="w-full rounded-lg border px-4 py-3 text-sm grid has-[&gt;svg]:grid-cols-[calc(var(--spacing)*4)_1fr] grid-cols-[0_1fr] has-[&gt;svg]:gap-x-3 gap-y-0.5 items-start [&amp;&gt;svg]:size-4 [&amp;&gt;svg]:translate-y-0.5 [&amp;&gt;svg]:text-current relative border-transparent bg-green-500/10 text-green-500" data-slot="alert" role="alert">
<button class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:text-accent-foreground dark:hover:bg-accent/50 size-9 absolute top-2 right-2 h-6 w-6 hover:bg-green-500/20" data-slot="button"><svg class="lucide lucide-x h-3.5 w-3.5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg></button>
<div class="pr-6">
<div class="mb-3 flex items-center gap-2">
<div class="rounded-lg bg-green-500/20 p-1.5"><svg class="lucide lucide-bell h-3.5 w-3.5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M10.268 21a2 2 0 0 0 3.464 0"></path><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"></path></svg></div>
<div class="text-muted-foreground col-start-2 grid justify-items-start gap-1 text-sm [&amp;_p]:leading-relaxed m-0 font-semibold" data-slot="alert-description">New Version Available</div>
</div>
<div class="text-muted-foreground col-start-2 grid justify-items-start gap-1 [&amp;_p]:leading-relaxed mb-4 text-sm" data-slot="alert-description">Update your app and enjoy the new features and improvements.</div>
<div class="flex items-center gap-4">
<button class="inline-flex items-center justify-center whitespace-nowrap transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:hover:bg-accent/50 rounded-md gap-1.5 has-[&gt;svg]:px-2.5 h-auto p-0 text-sm font-semibold text-red-500 hover:bg-red-500/10 hover:text-red-600" data-slot="button">Dismiss</button>
<button class="inline-flex items-center justify-center whitespace-nowrap transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:hover:bg-accent/50 rounded-md gap-1.5 has-[&gt;svg]:px-2.5 h-auto p-0 text-sm font-semibold text-green-500 hover:bg-green-500/10 hover:text-green-600" data-slot="button">Upgrade Now</button>
</div>
</div>
</div>
<div class="bg-border shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px my-6" data-orientation="horizontal" data-slot="separator" role="none"></div>
<small class="text-muted-foreground block text-center text-sm">Creative Tim UI v3.0.0</small>
        </div>
      </div>
      <main class="dashboard-main">
        <div class="w-full bg-surface p-6">
          <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
            <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
              <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6 pb-3">
                <div class="flex items-start justify-between gap-4">
                  <div class="flex items-start gap-4 min-w-0">
                    <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg">
                      <p class="text-base font-bold tracking-tight">---</p>
                  </div>
                    <div class="min-w-0 space-y-1">
                      <p class="font-bold tracking-tight text-sm">Visiteurs ce mois-ci</p>
                      <p class="text-sm text-muted-foreground">tout site</p>
                    </div>
                  </div>
                  <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden border-transparent [a&amp;]:hover:bg-secondary/90 gap-1 bg-green-100 text-green-700 hover:bg-green-100 dark:bg-green-900/20 dark:text-green-400"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-up h-3 w-3"><path d="m5 12 7-7 7 7"></path><path d="M12 19V5"></path></svg>+3%</span>
                </div>
              </div>
            </div>
            <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
              <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6 pb-3">
                <div class="flex items-start justify-between gap-4">
                  <div class="flex items-start gap-4 min-w-0">
                    <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg">
                      <p class="text-base font-bold tracking-tight">---</p>
                  </div>
                    <div class="min-w-0 space-y-1">
                      <p class="font-bold tracking-tight text-sm">Nombre de site</p>
                      <p class="text-sm text-muted-foreground">inter-connecté</p>
                    </div>
                  </div>
                  <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden border-transparent [a&amp;]:hover:bg-secondary/90 gap-1 bg-green-100 text-green-700 hover:bg-green-100 dark:bg-green-900/20 dark:text-green-400"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-up h-3 w-3"><path d="m5 12 7-7 7 7"></path><path d="M12 19V5"></path></svg>+3%</span>
                </div>
              </div>
            </div>
            <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
              <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6 pb-3">
                <div class="flex items-start justify-between gap-4">
                  <div class="flex items-start gap-4 min-w-0">
                    <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg">
                      <p class="text-base font-bold tracking-tight">---</p>
                  </div>
                    <div class="min-w-0 space-y-1">
                      <p class="font-bold tracking-tight text-sm">Domaines</p>
                      <p class="text-sm text-muted-foreground">.fr, .com, .org,...</p>
                    </div>
                  </div>
                  <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden border-transparent [a&amp;]:hover:bg-secondary/90 gap-1 bg-green-100 text-green-700 hover:bg-green-100 dark:bg-green-900/20 dark:text-green-400"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-up h-3 w-3"><path d="m5 12 7-7 7 7"></path><path d="M12 19V5"></path></svg>+3%</span>
                </div>
              </div>
            </div>
            <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
              <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6 pb-3">
                <div class="flex items-start justify-between gap-4">
                  <div class="flex items-start gap-4 min-w-0">
                    <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg">
                      <p class="text-base font-bold tracking-tight">---</p>
              </div>
                <div class="min-w-0 space-y-1">
                  <p class="font-bold tracking-tight text-sm">Disponibilité annuelle</p>
                  <p class="text-sm text-muted-foreground">tout services</p>
                </div>
              </div>
              <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden border-transparent [a&amp;]:hover:bg-secondary/90 gap-1 bg-red-100 text-red-700 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-down h-3 w-3"><path d="M12 19V5"></path><path d="m5 12 7-7 7 7"></path></svg>-1%</span>
            </div>
          </div>
        </div>
          <!-- Graphique (statique pour l'instant) -->
          <div class="mt-6 chart-reveal md:col-span-4 lg:col-span-4" data-chart="visitors">
            <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-6 rounded-xl border py-6 shadow-sm">
              <div data-slot="card-header" class="flex flex-row items-center justify-between space-y-0 px-6 pb-6 border-b">
                <div class="flex items-center gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-activity h-5 w-5 text-blue-600">
                    <path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"></path>
                  </svg>
                  <h3 class="text-xl font-bold">Visiteurs par site</h3>
                </div>

                <select id="visitorsRange"
                        class="border-input data-[placeholder]:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 dark:bg-input/30 flex items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] h-9 w-[140px]">
                  <option value="7">7 jours</option>
                  <option value="30" selected>30 jours</option>
                  <option value="90">90 jours</option>
                </select>
              </div>

              <div data-slot="card-content" class="px-6 grid grid-cols-1 gap-6 lg:grid-cols-4">
                <div class="col-span-1 flex flex-col gap-4">
                  <div data-slot="card" class="metric-card bg-card text-card-foreground flex flex-col gap-6 rounded-xl border py-6 border-l-4 border-l-green-500 shadow-sm">
                    <div data-slot="card-content" class="p-4">
                      <div class="flex items-center gap-3">
                        <div class="rounded-full bg-green-500/10 p-2.5">
                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-activity h-5 w-5 text-green-600">
                            <path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"></path>
                          </svg>
                        </div>
                        <div>
                          <p class="text-muted-foreground text-xs font-medium">GNL Solution</p>
                          <div class="flex items-baseline gap-2">
                            <h4 class="text-2xl font-bold">416,180</h4>
                            <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 overflow-hidden border-transparent gap-1 bg-green-500/10 text-green-600">
                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trending-up h-3 w-3">
                                <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline>
                                <polyline points="16 7 22 7 22 13"></polyline>
                              </svg>
                              +12%
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div data-slot="card" class="metric-card bg-card text-card-foreground flex flex-col gap-6 rounded-xl border py-6 border-l-4 border-l-blue-500 shadow-sm">
                    <div data-slot="card-content" class="p-4">
                      <div class="flex items-center gap-3">
                        <div class="rounded-full bg-blue-500/10 p-2.5">
                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-dollar-sign h-5 w-5 text-blue-600">
                            <line x1="12" x2="12" y1="2" y2="22"></line>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                          </svg>
                        </div>
                        <div>
                          <p class="text-muted-foreground text-xs font-medium">SlapIA</p>
                          <div class="flex items-baseline gap-2">
                            <h4 class="text-2xl font-bold">348,850</h4>
                            <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 overflow-hidden border-transparent gap-1 bg-blue-500/10 text-blue-600">
                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trending-up h-3 w-3">
                                <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline>
                                <polyline points="16 7 22 7 22 13"></polyline>
                              </svg>
                              +8%
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div data-slot="card" class="metric-card bg-card text-card-foreground flex flex-col gap-6 rounded-xl border py-6 border-l-4 border-l-purple-500 shadow-sm">
                    <div data-slot="card-content" class="p-4">
                      <div class="flex items-center gap-3">
                        <div class="rounded-full bg-purple-500/10 p-2.5">
                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-wallet h-5 w-5 text-purple-600">
                            <path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"></path>
                            <path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"></path>
                          </svg>
                        </div>
                        <div>
                          <p class="text-muted-foreground text-xs font-medium">Game Reduction</p>
                          <div class="flex items-baseline gap-2">
                            <h4 class="text-2xl font-bold">260,500</h4>
                            <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 overflow-hidden border-transparent gap-1 bg-purple-500/10 text-purple-600">
                              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trending-up h-3 w-3">
                                <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline>
                                <polyline points="16 7 22 7 22 13"></polyline>
                              </svg>
                              +15%
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-span-1 lg:col-span-3">
                  <div class="h-[320px]">
                    <canvas id="visitorsChart" aria-label="Graphique des visiteurs" role="img"></canvas>
                  </div>

                  <div class="mt-4 flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
                    <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-green-500"></span>GNL Solution</div>
                    <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-blue-500"></span>SlapIA</div>
                    <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-purple-500"></span>Game Reduction</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

      </div>
    </main>
  </div>

  <script>
    (function () {
      function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      }

      function buildVisitorsChart() {
        const canvas = document.getElementById("visitorsChart");
        if (!canvas || !window.Chart) return null;

        const ctx = canvas.getContext("2d");
        const h = 320;

        const gGreen = ctx.createLinearGradient(0, 0, 0, h);
        gGreen.addColorStop(0, "rgba(34, 197, 94, 0.25)");
        gGreen.addColorStop(1, "rgba(34, 197, 94, 0)");

        const gBlue = ctx.createLinearGradient(0, 0, 0, h);
        gBlue.addColorStop(0, "rgba(59, 130, 246, 0.25)");
        gBlue.addColorStop(1, "rgba(59, 130, 246, 0)");

        const gPurple = ctx.createLinearGradient(0, 0, 0, h);
        gPurple.addColorStop(0, "rgba(168, 85, 247, 0.22)");
        gPurple.addColorStop(1, "rgba(168, 85, 247, 0)");

        const labels = ["S1","S2","S3","S4","S5","S6","S7","S8","S9","S10","S11","S12"];

        const data = {
          labels,
          datasets: [
            {
              label: "GNL Solution",
              data: [32000, 36000, 34500, 39000, 41000, 40200, 43500, 47000, 45500, 49000, 52000, 50500],
              borderColor: "rgba(34, 197, 94, 1)",
              backgroundColor: gGreen,
              fill: true,
            },
            {
              label: "SlapIA",
              data: [28000, 30000, 29500, 32500, 34000, 33800, 36000, 39500, 38000, 41000, 43000, 42000],
              borderColor: "rgba(59, 130, 246, 1)",
              backgroundColor: gBlue,
              fill: true,
            },
            {
              label: "Game Reduction",
              data: [21000, 23000, 22500, 25000, 26000, 25500, 27500, 30000, 29200, 31500, 33500, 32800],
              borderColor: "rgba(168, 85, 247, 1)",
              backgroundColor: gPurple,
              fill: true,
            },
          ],
        };

        return new Chart(ctx, {
          type: "line",
          data,
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: "index", intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: {
                padding: 10,
                displayColors: true,
                callbacks: {
                  label: function (ctx) {
                    const v = ctx.parsed.y;
                    return " " + ctx.dataset.label + ": " + v.toLocaleString("fr-FR");
                  },
                },
              },
            },
            elements: {
              point: { radius: 0, hoverRadius: 4, hitRadius: 12 },
              line: { tension: 0.35, borderWidth: 2 },
            },
            scales: {
              x: {
                grid: { display: false },
                ticks: { color: "rgba(148, 163, 184, 0.9)" },
              },
              y: {
                grid: { color: "rgba(148, 163, 184, 0.15)" },
                ticks: {
                  color: "rgba(148, 163, 184, 0.9)",
                  callback: (value) => value.toLocaleString("fr-FR"),
                },
              },
            },
            animation: prefersReducedMotion()
              ? false
              : { duration: 900, easing: "easeOutQuart" },
          },
        });
      }

      function init() {
        const section = document.querySelector('[data-chart="visitors"]');
        if (!section) return;

        let chartInstance = null;

        const run = () => {
          section.classList.add("is-visible");
          if (!chartInstance) chartInstance = buildVisitorsChart();
        };

        if ("IntersectionObserver" in window && !prefersReducedMotion()) {
          const io = new IntersectionObserver(
            (entries) => {
              if (entries.some((e) => e.isIntersecting)) {
                run();
                io.disconnect();
              }
            },
            { threshold: 0.2 }
          );
          io.observe(section);
        } else {
          run();
        }

        const range = document.getElementById("visitorsRange");
        if (range) {
          range.addEventListener("change", () => {
            // Statique pour l'instant: on garde juste l'UI vivante.
            // Quand tu voudras: on branchera ici la vraie data et on fera un chart.update().
          });
        }
      }

      document.addEventListener("DOMContentLoaded", init);
    })();
  </script>

</body>
</html>
