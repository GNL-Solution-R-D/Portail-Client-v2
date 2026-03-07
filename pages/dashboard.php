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


// --- Kubernetes: stats rapides (déploiements + domaines depuis les Ingress)
$k8s_deployments_count = 0;
$k8s_ingress_domains_count = 0;
$k8s_ingress_base_domains = [];

$k8s_namespace = $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['k8sNamespace']
    ?? $_SESSION['user']['namespace_k8s']
    ?? $_SESSION['user']['k8s_ns']
    ?? $_SESSION['user']['namespace']
    ?? null;

if (is_string($k8s_namespace) && $k8s_namespace !== '') {
    // Base domain (hors sous-domaine) à partir d'un host d'Ingress.
    $k8sBaseDomain = function (string $host): string {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }

        // Retire un éventuel port (rare mais possible)
        $host = preg_replace('/:\\d+$/', '', $host);

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $parts = explode('.', $host);
        $n = count($parts);

        if ($n <= 2) {
            return $host;
        }

        $last2 = $parts[$n - 2] . '.' . $parts[$n - 1];

        // Heuristique pour suffixes à 2 niveaux courants (sans embarquer une Public Suffix List)
        $twoLevelSuffixes = [
            'co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'net.uk',
            'com.au', 'net.au', 'org.au',
            'co.nz', 'org.nz',
            'com.br', 'com.mx', 'co.jp',
        ];

        if (in_array($last2, $twoLevelSuffixes, true) && $n >= 3) {
            return $parts[$n - 3] . '.' . $last2;
        }

        return $last2;
    };

    $k8sClientPath = dirname(__DIR__) . '/k8s/KubernetesClient.php';
    if (!is_readable($k8sClientPath)) {
        $k8sClientPath = dirname(__DIR__) . '/KubernetesClient.php';
    }

    if (is_readable($k8sClientPath)) {
        require_once $k8sClientPath;

        try {
            $k8s = new KubernetesClient(null, null, null, 3); // timeout court

            // 1) Déploiements
            $list = $k8s->listDeployments($k8s_namespace);
            $items = $list['items'] ?? [];
            if (is_array($items)) {
                $k8s_deployments_count = count($items);
            }

            // 2) Ingress -> domaines (hors sous-domaines)
            $ns = rawurlencode($k8s_namespace);
            $ingresses = null;

            try {
                $ingresses = $k8s->get("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses?limit=200");
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'HTTP 404')) {
                    $ingresses = $k8s->get("/apis/extensions/v1beta1/namespaces/{$ns}/ingresses?limit=200");
                } else {
                    throw $e;
                }
            }

            $hosts = [];
            $ingItems = $ingresses['items'] ?? [];
            if (is_array($ingItems)) {
                foreach ($ingItems as $ing) {
                    $spec = $ing['spec'] ?? [];

                    $rules = $spec['rules'] ?? [];
                    if (is_array($rules)) {
                        foreach ($rules as $r) {
                            $h = (string)($r['host'] ?? '');
                            if ($h !== '') {
                                $hosts[] = $h;
                            }
                        }
                    }

                    $tls = $spec['tls'] ?? [];
                    if (is_array($tls)) {
                        foreach ($tls as $t) {
                            $ths = $t['hosts'] ?? [];
                            if (is_array($ths)) {
                                foreach ($ths as $h) {
                                    $h = (string)$h;
                                    if ($h !== '') {
                                        $hosts[] = $h;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $baseDomains = [];
            foreach ($hosts as $h) {
                $bd = $k8sBaseDomain($h);
                if ($bd !== '') {
                    $baseDomains[$bd] = true;
                }
            }

            $k8s_ingress_base_domains = array_keys($baseDomains);
            sort($k8s_ingress_base_domains, SORT_NATURAL | SORT_FLAG_CASE);
            $k8s_ingress_domains_count = count($k8s_ingress_base_domains);

        } catch (Throwable $e) {
            // On garde 0 si Kubernetes / RBAC / API est indisponible.
        }
    }
}


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

<style>
  /* Sidebar collapsible (vanilla JS) */
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
  <?php include("../include/header.php"); ?>
  <div class="dashboard-layout">
    <?php include('../include/menu.php'); ?>
      <main class="dashboard-main">
        <div class="w-full h-screen bg-surface p-6">
          <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
            <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
              <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6">
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
            <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
              <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6">
                <div class="flex items-start justify-between gap-4">
                  <div class="flex items-start gap-4 min-w-0">
                    <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg">
                      <p class="text-base font-bold tracking-tight"><?php echo (int)$k8s_deployments_count; ?></p>
                  </div>
                    <div class="min-w-0 space-y-1">
                      <p class="font-bold tracking-tight text-sm">Nombre de site</p>
                      <p class="text-sm text-muted-foreground">inter-connecté</p>
                    </div>
                  </div>
                  <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden border-transparent [a&amp;]:hover:bg-secondary/90 gap-1 bg-red-100 text-red-700 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-up h-3 w-3"><path d="m5 12 7-7 7 7"></path><path d="M12 19V5"></path></svg>Erreur 402</span>
                </div>
              </div>
            </div>
            <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
              <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6">
                <div class="flex items-start justify-between gap-4">
                  <div class="flex items-start gap-4 min-w-0">
                    <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg">
                      <p class="text-base font-bold tracking-tight"><?php echo (int)$k8s_ingress_domains_count; ?></p>
                  </div>
                    <div class="min-w-0 space-y-1">
                      <p class="font-bold tracking-tight text-sm">Domaines</p>
                      <p class="text-sm text-muted-foreground">.fr, .com, .org,...</p>
                    </div>
                  </div>
                  <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden border-transparent [a&amp;]:hover:bg-secondary/90 gap-1 bg-red-100 text-red-700 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-up h-3 w-3"><path d="m5 12 7-7 7 7"></path><path d="M12 19V5"></path></svg>Erreur 402</span>
                </div>
              </div>
            </div>
            <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
              <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6">
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
            <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-6 rounded-xl border py-3 shadow-sm">
              <div data-slot="card-header" class="flex flex-row items-center justify-between space-y-0 px-6 pb-3 border-b">
                <div class="flex items-center gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-activity h-5 w-5 text-blue-600">
                    <path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"></path>
                  </svg>
                  <h3 class="text-sm font-bold">Visiteurs par site</h3>
                </div>

                <select id="visitorsRange"
                        class="border-input data-[placeholder]:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 dark:bg-input/30 flex items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] h-9 w-[140px]">
                  <option value="7">7 jours</option>
                  <option value="30" selected>30 jours</option>
                  <option value="90">90 jours</option>
                </select>
              </div>

              <div data-slot="card-content" class="px-6 grid grid-cols-1 gap-6 lg:grid-cols-4">

                <div class="col-span-4 lg:col-span-4">
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



<script>
(function () {
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  ready(function () {
    var triggers = document.querySelectorAll('[data-slot="collapsible-trigger"]');
    triggers.forEach(function (btn, idx) {
      btn.classList.add('collapsible-trigger');

      // Find target content safely (Radix-style aria-controls)
      var targetId = btn.getAttribute('aria-controls');
      var content = targetId ? document.getElementById(targetId) : null;

      // Fallback: next sibling with data-slot="collapsible-content"
      if (!content) {
        var parent = btn.closest('[data-slot="collapsible"]');
        if (parent) content = parent.querySelector('[data-slot="collapsible-content"]');
      }
      if (!content) return;

      content.classList.add('collapsible-content');

      // Mark chevron for rotation
      var chev = btn.querySelector('.lucide-chevron-right');
      if (chev) chev.classList.add('collapsible-chevron');

      // Initial state
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

      // Toggle handler
      btn.addEventListener('click', function (e) {
        e.preventDefault();

        var isOpen = btn.getAttribute('aria-expanded') === 'true';

        if (!isOpen) {
          // OPEN
          btn.setAttribute('aria-expanded', 'true');
          btn.setAttribute('data-state', 'open');
          content.hidden = false;
          content.classList.add('is-open');
          content.setAttribute('data-state', 'open');

          // animate height: 0 -> scrollHeight
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
          // CLOSE
          btn.setAttribute('aria-expanded', 'false');
          btn.setAttribute('data-state', 'closed');
          content.classList.remove('is-open');
          content.setAttribute('data-state', 'closed');

          // animate height: current -> 0
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
  // K8S endpoints (paths absolus pour éviter les surprises depuis /pages/*)
  window.K8S_API_URL = "../k8s/k8s_api.php";
  window.K8S_UI_BASE = "./pages/";
</script>
<script src="../k8s/k8s-menu.js" defer></script>

</body>
</html>
