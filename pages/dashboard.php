<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: /connexion");
    exit();
}

$name = $_SESSION['user']['nom'];
$siret = $_SESSION['user']['siret'];
$perm_id = $_SESSION['user']['perm_id'];

require_once '../config_loader.php';
require_once '../include/account_sessions.php';
require_once '../data/zabbix_api.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit();
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

$user_account = $_SESSION['user']['id'];
$domains = [];
if ($pdo_powerdns instanceof PDO) {
    try {
        $query_domains = $pdo_powerdns->prepare("SELECT id, name FROM domains WHERE account = ?");
        $query_domains->execute([$user_account]);
        $domains = $query_domains->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($domains)) {
            $domains = [];
        }
    } catch (Throwable $exception) {
        error_log('Impossible de récupérer les domaines PowerDNS : ' . $exception->getMessage());
        $domains = [];
    }
}

if (!function_exists('dashboardExtractErrorCode')) {
    function dashboardExtractErrorCode(Throwable $e): ?string
    {
        $code = $e->getCode();
        if ((is_int($code) || preg_match('/^-?\d+$/', (string)$code)) && (int)$code !== 0) {
            return (string)(int)$code;
        }
        if (preg_match('/\bHTTP\s+(\d{3})\b/i', $e->getMessage(), $matches)) {
            return $matches[1];
        }
        if (preg_match('/\bstatus(?:\s+code)?\s*[:=]?\s*(\d{3})\b/i', $e->getMessage(), $matches)) {
            return $matches[1];
        }
        return null;
    }
}

if (!function_exists('dashboardRenderWidgetErrorBadge')) {
    function dashboardRenderWidgetErrorBadge(?string $errorCode): string
    {
        if ($errorCode === null || $errorCode === '') {
            return '';
        }
        $safeCode = htmlspecialchars($errorCode, ENT_QUOTES, 'UTF-8');
        return '<span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden border-transparent gap-1 bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-400">Erreur ' . $safeCode . '</span>';
    }
}

// --- Résolution du namespace k8s depuis la session Keycloak ---
$k8s_namespace = $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['k8sNamespace']
    ?? $_SESSION['user']['namespace_k8s']
    ?? $_SESSION['user']['k8s_ns']
    ?? $_SESSION['user']['namespace']
    ?? null;
$k8s_namespace = is_string($k8s_namespace) ? trim($k8s_namespace) : '';

// --- Stats de visites depuis le sidecar stats-collector ---
// L'URL est construite dynamiquement depuis le namespace k8s du client :
//   namespace "slapia" → http://slapia-stats.slapia.svc.cluster.local:9090/stats
// Chaque namespace doit avoir son propre sidecar stats-collector + Service "slapia-stats".
$visit_stats = [
    'total_hits'          => 0,
    'current_month_hits'  => 0,
    'previous_month_hits' => 0,
    'by_month'            => [],
];
$visitors_error_code = null;

if ($k8s_namespace !== '') {
    try {
        $stats_url    = "http://{$k8s_namespace}-stats.{$k8s_namespace}.svc.cluster.local:9090/stats";
        $stats_secret = getenv('STATS_SECRET') ?: '';

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 3,
                'header'  => $stats_secret ? "X-Stats-Secret: {$stats_secret}\r\n" : '',
            ]
        ]);

        $raw = @file_get_contents($stats_url, false, $ctx);
        if ($raw !== false) {
            $decoded = json_decode(trim($raw), true);
            if (is_array($decoded)) {
                $visit_stats = $decoded;
            }
        } else {
            $visitors_error_code = 'N/A';
        }
    } catch (Throwable $e) {
        $visitors_error_code = dashboardExtractErrorCode($e);
    }
}

$current_month_hits  = (int)($visit_stats['current_month_hits']  ?? 0);
$previous_month_hits = (int)($visit_stats['previous_month_hits'] ?? 0);

// Prépare les données mensuelles pour le graphique (12 derniers mois)
// Format by_month : {"2026-05": 372, "2026-04": 180, ...}
$by_month_raw = is_array($visit_stats['by_month'] ?? null) ? $visit_stats['by_month'] : [];

// Génère les 12 derniers mois (du plus ancien au plus récent)
$chart_months       = [];
$chart_month_labels = [];
$chart_month_data   = [];

for ($i = 11; $i >= 0; $i--) {
    $ts    = mktime(0, 0, 0, (int)date('n') - $i, 1, (int)date('Y'));
    $key   = date('Y-m', $ts);
    $label = ucfirst(strftime('%b %Y', $ts) ?: date('M Y', $ts));
    // strftime est deprecated en PHP 8.1+, fallback propre :
    $monthNames = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
    $label = $monthNames[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);

    $chart_months[]       = $key;
    $chart_month_labels[] = $label;
    $chart_month_data[]   = (int)($by_month_raw[$key] ?? 0);
}

// --- Kubernetes: stats rapides (déploiements + domaines depuis les Ingress) ---
$k8s_deployments_count    = 0;
$k8s_deployments_error_code = null;
$k8s_ingress_domains_count  = 0;
$k8s_ingress_error_code     = null;
$availability_error_code    = null;
$annual_availability_display = '---';
$k8s_ingress_base_domains   = [];
$k8s_deployments_names      = [];

// --- Zabbix: disponibilité annuelle (SLA) ---
$zabbixAvailability = zabbixApiGetAnnualAvailabilityDisplay(
    isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : []
);
$annual_availability_display = (string)($zabbixAvailability['display'] ?? '---');
$availability_error_code = isset($zabbixAvailability['error_code']) && $zabbixAvailability['error_code'] !== ''
    ? (string)$zabbixAvailability['error_code']
    : null;

if ($k8s_namespace !== '') {
    $k8sBaseDomain = function (string $host): string {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');
        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }
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
        $twoLevelSuffixes = [
            'co.uk','org.uk','gov.uk','ac.uk','net.uk',
            'com.au','net.au','org.au',
            'co.nz','org.nz',
            'com.br','com.mx','co.jp',
        ];
        if (in_array($last2, $twoLevelSuffixes, true) && $n >= 3) {
            return $parts[$n - 3] . '.' . $last2;
        }
        return $last2;
    };

    $k8sClientPath = dirname(__DIR__) . '/data/KubernetesClient.php';
    if (!is_readable($k8sClientPath)) {
        $k8sClientPath = dirname(__DIR__) . '/KubernetesClient.php';
    }

    if (is_readable($k8sClientPath)) {
        require_once $k8sClientPath;

        try {
            $k8s = new KubernetesClient(null, null, null, 3);

            try {
                $list  = $k8s->listDeployments($k8s_namespace);
                $items = $list['items'] ?? [];
                if (is_array($items)) {
                    foreach ($items as $item) {
                        $deploymentName = (string)($item['metadata']['name'] ?? '');
                        if ($deploymentName !== '') {
                            $k8s_deployments_names[] = $deploymentName;
                        }
                    }
                    sort($k8s_deployments_names, SORT_NATURAL | SORT_FLAG_CASE);
                    $k8s_deployments_names = array_values(array_unique($k8s_deployments_names));
                    $k8s_deployments_count = count($k8s_deployments_names);
                }
            } catch (Throwable $e) {
                $k8s_deployments_count      = 0;
                $k8s_deployments_names      = [];
                $k8s_deployments_error_code = dashboardExtractErrorCode($e);
            }

            try {
                $ns       = rawurlencode($k8s_namespace);
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

                $hosts    = [];
                $ingItems = $ingresses['items'] ?? [];
                if (is_array($ingItems)) {
                    foreach ($ingItems as $ing) {
                        $spec  = $ing['spec'] ?? [];
                        $rules = $spec['rules'] ?? [];
                        if (is_array($rules)) {
                            foreach ($rules as $r) {
                                $h = (string)($r['host'] ?? '');
                                if ($h !== '') $hosts[] = $h;
                            }
                        }
                        $tls = $spec['tls'] ?? [];
                        if (is_array($tls)) {
                            foreach ($tls as $t) {
                                $ths = $t['hosts'] ?? [];
                                if (is_array($ths)) {
                                    foreach ($ths as $h) {
                                        $h = (string)$h;
                                        if ($h !== '') $hosts[] = $h;
                                    }
                                }
                            }
                        }
                    }
                }

                $baseDomains = [];
                foreach ($hosts as $h) {
                    $bd = $k8sBaseDomain($h);
                    if ($bd !== '') $baseDomains[$bd] = true;
                }
                $k8s_ingress_base_domains = array_keys($baseDomains);
                sort($k8s_ingress_base_domains, SORT_NATURAL | SORT_FLAG_CASE);
                $k8s_ingress_domains_count = count($k8s_ingress_base_domains);
            } catch (Throwable $e) {
                $k8s_ingress_domains_count = 0;
                $k8s_ingress_base_domains  = [];
                $k8s_ingress_error_code    = dashboardExtractErrorCode($e);
            }

        } catch (Throwable $e) {
            $sharedErrorCode = dashboardExtractErrorCode($e);
            if ($k8s_deployments_error_code === null) $k8s_deployments_error_code = $sharedErrorCode;
            if ($k8s_ingress_error_code === null)     $k8s_ingress_error_code     = $sharedErrorCode;
        }
    }
}

$visitors_chart_series_names = $k8s_deployments_names;
if ($visitors_chart_series_names === [] && $k8s_namespace !== '') {
    $visitors_chart_series_names = [$k8s_namespace];
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
      display:flex;flex-direction:row;align-items:stretch;
      width:100%;
      min-height:calc(100vh - var(--app-header-height, 0px));
      min-height:calc(100dvh - var(--app-header-height, 0px));
    }
    .dashboard-sidebar{ flex:0 0 20rem;width:20rem;max-width:20rem; }
    .dashboard-main{ flex:1 1 auto;min-width:0; }
    @media (max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main{padding:1rem;}
    }
    @keyframes fadeUp{
      from{opacity:0;transform:translate3d(0,10px,0);}
      to{opacity:1;transform:translate3d(0,0,0);}
    }
    .chart-reveal{opacity:0;transform:translate3d(0,10px,0);}
    .chart-reveal.is-visible{animation:fadeUp .6s ease-out both;}
    .metric-card{transition:transform .2s ease,box-shadow .2s ease;}
    .metric-card:hover{transform:translate3d(0,-2px,0);}
    @media(prefers-reduced-motion:reduce){
      .chart-reveal,.chart-reveal.is-visible{opacity:1;transform:none;animation:none;}
      .metric-card{transition:none;}
    }
  </style>
  <style>
    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease,opacity 220ms ease;will-change:height,opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}
    @media(prefers-reduced-motion:reduce){
      .collapsible-content,.collapsible-trigger .collapsible-chevron{transition:none !important;}
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include("../include/header.php"); ?>
  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>
    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6">

        <!-- Cartes métriques -->
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">

          <!-- Visiteurs ce mois-ci -->
          <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
            <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6">
              <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 min-w-0">
                  <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg">
                    <p class="text-base font-bold tracking-tight">
                      <?php echo $current_month_hits > 0 ? number_format($current_month_hits, 0, ',', ' ') : '---'; ?>
                    </p>
                  </div>
                  <div class="min-w-0 space-y-1">
                    <p class="font-bold tracking-tight text-sm">Visiteurs ce mois-ci</p>
                    <?php if ($previous_month_hits > 0 && $current_month_hits > 0): ?>
                      <?php $pct = round((($current_month_hits - $previous_month_hits) / $previous_month_hits) * 100); ?>
                      <p class="text-sm <?php echo $pct >= 0 ? 'text-green-600' : 'text-red-500'; ?>">
                        <?php echo ($pct >= 0 ? '+' : '') . $pct; ?>% vs mois dernier
                      </p>
                    <?php else: ?>
                      <p class="text-sm text-muted-foreground">toutes applications</p>
                    <?php endif; ?>
                  </div>
                </div>
                <?php echo dashboardRenderWidgetErrorBadge($visitors_error_code); ?>
              </div>
            </div>
          </div>

          <!-- Nombre d'applications -->
          <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
            <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6">
              <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 min-w-0">
                  <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg">
                    <p class="text-base font-bold tracking-tight"><?php echo (int)$k8s_deployments_count; ?></p>
                  </div>
                  <div class="min-w-0 space-y-1">
                    <p class="font-bold tracking-tight text-sm">Nombre d'application</p>
                    <p class="text-sm text-muted-foreground">inter-connecté</p>
                  </div>
                </div>
                <?php echo dashboardRenderWidgetErrorBadge($k8s_deployments_error_code); ?>
              </div>
            </div>
          </div>

          <!-- Domaines -->
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
                <?php echo dashboardRenderWidgetErrorBadge($k8s_ingress_error_code); ?>
              </div>
            </div>
          </div>

          <!-- Disponibilité annuelle -->
          <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
            <div data-slot="card-header" class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6">
              <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 min-w-0">
                  <div class="bg-muted flex h-10 w-20 items-center justify-center rounded-lg">
                    <p class="text-base font-bold tracking-tight"><?php echo htmlspecialchars($annual_availability_display, ENT_QUOTES, 'UTF-8'); ?></p>
                  </div>
                  <div class="min-w-0 space-y-1">
                    <p class="font-bold tracking-tight text-sm">Disponibilité annuelle</p>
                    <p class="text-sm text-muted-foreground">tout services</p>
                  </div>
                </div>
                <?php echo dashboardRenderWidgetErrorBadge($availability_error_code); ?>
              </div>
            </div>
          </div>

        </div><!-- /grid métriques -->

        <!-- Graphique visites mensuelles réelles -->
        <div class="mt-6 chart-reveal" data-chart="visitors">
          <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-6 rounded-xl border py-3 shadow-sm">
            <div data-slot="card-header" class="flex flex-row items-center justify-between space-y-0 px-6 pb-3 border-b">
              <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-activity h-5 w-5 text-blue-600">
                  <path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"></path>
                </svg>
                <h3 class="text-sm font-bold">Visiteurs par mois</h3>
              </div>
              <span class="text-xs text-muted-foreground">12 derniers mois</span>
            </div>

            <div data-slot="card-content" class="px-6">
              <?php if ($k8s_namespace === ''): ?>
                <div class="rounded-lg border border-dashed px-4 py-6 text-sm text-muted-foreground">
                  Namespace Keycloak indisponible : impossible d'afficher les statistiques de visites.
                </div>
              <?php elseif (array_sum($chart_month_data) === 0): ?>
                <div class="rounded-lg border border-dashed px-4 py-6 text-sm text-muted-foreground">
                  Aucune donnée de visites disponible pour les 12 derniers mois.
                </div>
              <?php else: ?>
                <div class="h-[320px]">
                  <canvas id="visitorsChart" aria-label="Graphique des visiteurs mensuels" role="img"></canvas>
                </div>
                <div class="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                  <span class="h-2.5 w-2.5 rounded-full bg-blue-500 inline-block"></span>
                  <?php echo htmlspecialchars($k8s_namespace, ENT_QUOTES, 'UTF-8'); ?> — visites réelles (hors health-checks)
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>

  <script>
  (function () {
    // Données réelles injectées depuis PHP
    const chartLabels = <?php echo json_encode($chart_month_labels, JSON_UNESCAPED_UNICODE); ?>;
    const chartData   = <?php echo json_encode($chart_month_data,   JSON_UNESCAPED_UNICODE); ?>;

    function prefersReducedMotion() {
      return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    }

    function buildChart() {
      const canvas = document.getElementById("visitorsChart");
      if (!canvas || !window.Chart) return;

      const ctx = canvas.getContext("2d");
      const h   = 320;

      const gradient = ctx.createLinearGradient(0, 0, 0, h);
      gradient.addColorStop(0, "rgba(59,130,246,0.25)");
      gradient.addColorStop(1, "rgba(59,130,246,0)");

      new Chart(ctx, {
        type: "bar",
        data: {
          labels: chartLabels,
          datasets: [{
            label: "Visites",
            data: chartData,
            backgroundColor: chartData.map((_, i) =>
              i === chartData.length - 1
                ? "rgba(59,130,246,0.9)"   // mois courant plus foncé
                : "rgba(59,130,246,0.45)"
            ),
            borderColor: "rgba(59,130,246,1)",
            borderWidth: 1,
            borderRadius: 4,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: "index", intersect: false },
          plugins: {
            legend: { display: false },
            tooltip: {
              padding: 10,
              callbacks: {
                label: (ctx) => " " + ctx.parsed.y.toLocaleString("fr-FR") + " visites",
              }
            }
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: { color: "rgba(148,163,184,0.9)", maxRotation: 45 }
            },
            y: {
              grid: { color: "rgba(148,163,184,0.15)" },
              beginAtZero: true,
              ticks: {
                color: "rgba(148,163,184,0.9)",
                callback: (v) => v.toLocaleString("fr-FR")
              }
            }
          },
          animation: prefersReducedMotion() ? false : { duration: 700, easing: "easeOutQuart" }
        }
      });
    }

    function init() {
      const section = document.querySelector('[data-chart="visitors"]');
      if (!section) return;

      const run = () => {
        section.classList.add("is-visible");
        buildChart();
      };

      if ("IntersectionObserver" in window && !prefersReducedMotion()) {
        const io = new IntersectionObserver(
          (entries) => { if (entries.some(e => e.isIntersecting)) { run(); io.disconnect(); } },
          { threshold: 0.2 }
        );
        io.observe(section);
      } else {
        run();
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
      triggers.forEach(function (btn) {
        btn.classList.add('collapsible-trigger');
        var targetId = btn.getAttribute('aria-controls');
        var content  = targetId ? document.getElementById(targetId) : null;
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
          content.hidden = false; content.classList.add('is-open'); content.style.height = 'auto';
        } else {
          content.hidden = true; content.classList.remove('is-open'); content.style.height = '0px';
        }
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var isOpen = btn.getAttribute('aria-expanded') === 'true';
          if (!isOpen) {
            btn.setAttribute('aria-expanded','true'); btn.setAttribute('data-state','open');
            content.hidden = false; content.classList.add('is-open'); content.setAttribute('data-state','open');
            content.style.height = '0px';
            var h = content.scrollHeight;
            requestAnimationFrame(function(){ content.style.height = h + 'px'; });
            var onEnd = function(ev){ if(ev.propertyName !== 'height') return; content.style.height = 'auto'; content.removeEventListener('transitionend', onEnd); };
            content.addEventListener('transitionend', onEnd);
          } else {
            btn.setAttribute('aria-expanded','false'); btn.setAttribute('data-state','closed');
            content.classList.remove('is-open'); content.setAttribute('data-state','closed');
            var current = content.scrollHeight;
            content.style.height = current + 'px';
            requestAnimationFrame(function(){ content.style.height = '0px'; });
            var onEndClose = function(ev){ if(ev.propertyName !== 'height') return; content.hidden = true; content.removeEventListener('transitionend', onEndClose); };
            content.addEventListener('transitionend', onEndClose);
          }
        }, { passive: false });
      });
    });
  })();
  </script>

  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../assets/js/k8s_menu.js" defer></script>
</body>
</html>