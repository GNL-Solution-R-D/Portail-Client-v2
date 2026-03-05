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
      </div>
    </main>
  </div>
</body>
</html>
