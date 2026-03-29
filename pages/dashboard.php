<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: /connexion");
    exit();
}

$name = $_SESSION['user']['nom'];
$siret = $_SESSION['user']['siret'];
$perm_id = $_SESSION['user']['perm_id'];

// Inclusion du fichier de configuration qui crée $pdo (base principale) et $pdo_powerdns (base PowerDNS)
require_once '../config_loader.php';
require_once '../include/account_sessions.php';
require_once '../data/zabbix_api.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit();
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

// Récupérer les domaines PowerDNS pour l'utilisateur
$user_account = $_SESSION['user']['id'];
$query_domains = $pdo_powerdns->prepare("SELECT id, name FROM domains WHERE account = ?");
$query_domains->execute([$user_account]);
$domains = $query_domains->fetchAll(PDO::FETCH_ASSOC);

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


// --- Kubernetes: stats rapides (déploiements + domaines depuis les Ingress)
$visitors_error_code = null;
$k8s_deployments_count = 0;
$k8s_deployments_error_code = null;
$k8s_ingress_domains_count = 0;
$k8s_ingress_error_code = null;
$availability_error_code = null;
$annual_availability_display = '---';
$k8s_ingress_base_domains = [];
$k8s_deployments_names = [];

$k8s_namespace = $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['k8sNamespace']
    ?? $_SESSION['user']['namespace_k8s']
    ?? $_SESSION['user']['k8s_ns']
    ?? $_SESSION['user']['namespace']
    ?? null;



// --- Zabbix: disponibilité annuelle (SLA)
$zabbixAvailability = zabbixApiGetAnnualAvailabilityDisplay(
    isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : []
);
$annual_availability_display = (string)($zabbixAvailability['display'] ?? '---');
$availability_error_code = isset($zabbixAvailability['error_code']) && $zabbixAvailability['error_code'] !== ''
    ? (string)$zabbixAvailability['error_code']
    : null;

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

    $k8sClientPath = dirname(__DIR__) . '/data/KubernetesClient.php';
    if (!is_readable($k8sClientPath)) {
        $k8sClientPath = dirname(__DIR__) . '/KubernetesClient.php';
    }

    if (is_readable($k8sClientPath)) {
        require_once $k8sClientPath;

        try {
            $k8s = new KubernetesClient(null, null, null, 3); // timeout court

            try {
                // 1) Déploiements
                $list = $k8s->listDeployments($k8s_namespace);
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
                $k8s_deployments_count = 0;
                $k8s_deployments_names = [];
                $k8s_deployments_error_code = dashboardExtractErrorCode($e);
            }

            try {
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
                $k8s_ingress_domains_count = 0;
                $k8s_ingress_base_domains = [];
                $k8s_ingress_error_code = dashboardExtractErrorCode($e);
            }

        } catch (Throwable $e) {
            $sharedErrorCode = dashboardExtractErrorCode($e);

            if ($k8s_deployments_error_code === null) {
                $k8s_deployments_error_code = $sharedErrorCode;
            }

            if ($k8s_ingress_error_code === null) {
                $k8s_ingress_error_code = $sharedErrorCode;
            }
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
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>
      <main class="dashboard-main">
        <div class="app-shell-offset-min-height w-full bg-surface p-6">
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
                      <p class="text-sm text-muted-foreground">toutes application</p>
                    </div>
                  </div>
                  <?php echo dashboardRenderWidgetErrorBadge($visitors_error_code); ?>
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
                      <p class="font-bold tracking-tight text-sm">Nombre d'application</p>
                      <p class="text-sm text-muted-foreground">inter-connecté</p>
                    </div>
                  </div>
                  <?php echo dashboardRenderWidgetErrorBadge($k8s_deployments_error_code); ?>
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
                  <?php echo dashboardRenderWidgetErrorBadge($k8s_ingress_error_code); ?>
                </div>
              </div>
            </div>
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
          <!-- Graphique (statique pour l'instant) -->
          <div class="mt-6 chart-reveal md:col-span-4 lg:col-span-4" data-chart="visitors">
            <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-6 rounded-xl border py-3 shadow-sm">
              <div data-slot="card-header" class="flex flex-row items-center justify-between space-y-0 px-6 pb-3 border-b">
                <div class="flex items-center gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-activity h-5 w-5 text-blue-600">
                    <path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"></path>
                  </svg>
                  <h3 class="text-sm font-bold">Visiteurs par application</h3>
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

                  <div id="visitorsChartEmpty" class="mt-4 hidden rounded-lg border border-dashed px-4 py-6 text-sm text-muted-foreground">
                    Aucun deployment accessible pour personnaliser les séries du graphique. Les joies simples d'un namespace vide.
                  </div>

                  <div id="visitorsChartLegend" class="mt-4 flex flex-wrap items-center gap-4 text-sm text-muted-foreground"></div>
                </div>
              </div>
            </div>
          </div>

      </div>
    </main>
  </div>

  <script>
    (function () {
      const deploymentNames = <?php echo json_encode($k8s_deployments_names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

      function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      }

      function palette(index) {
        const colors = [
          [34, 197, 94],
          [59, 130, 246],
          [168, 85, 247],
          [249, 115, 22],
          [236, 72, 153],
          [20, 184, 166],
          [245, 158, 11],
          [99, 102, 241],
          [132, 204, 22],
          [239, 68, 68],
          [6, 182, 212],
          [217, 70, 239],
        ];
        return colors[index % colors.length];
      }

      function rgba(rgb, alpha) {
        return "rgba(" + rgb[0] + ", " + rgb[1] + ", " + rgb[2] + ", " + alpha + ")";
      }

      function stringHash(value) {
        let hash = 0;
        for (let i = 0; i < value.length; i += 1) {
          hash = ((hash << 5) - hash + value.charCodeAt(i)) | 0;
        }
        return Math.abs(hash);
      }

      function buildSyntheticSeries(name, index) {
        const templates = [
          [32000, 36000, 34500, 39000, 41000, 40200, 43500, 47000, 45500, 49000, 52000, 50500],
          [28000, 30000, 29500, 32500, 34000, 33800, 36000, 39500, 38000, 41000, 43000, 42000],
          [21000, 23000, 22500, 25000, 26000, 25500, 27500, 30000, 29200, 31500, 33500, 32800],
        ];

        const hash = stringHash(name + ":" + index);
        const template = templates[index % templates.length];
        const factor = 0.8 + ((hash % 31) / 100);
        const baseOffset = ((Math.floor(hash / 10) % 15) - 7) * 180;
        const wave = ((Math.floor(hash / 100) % 9) - 4) * 95;

        return template.map((value, pointIndex) => {
          const stepWave = ((pointIndex % 4) - 1.5) * wave;
          return Math.max(0, Math.round((value * factor) + baseOffset + stepWave));
        });
      }

      function toggleEmptyState(hasData) {
        const empty = document.getElementById("visitorsChartEmpty");
        const legend = document.getElementById("visitorsChartLegend");
        const canvas = document.getElementById("visitorsChart");

        if (empty) empty.classList.toggle("hidden", hasData);
        if (legend) legend.classList.toggle("hidden", !hasData);
        if (canvas && canvas.parentElement) canvas.parentElement.classList.toggle("hidden", !hasData);
      }

      function renderLegend(names) {
        const legend = document.getElementById("visitorsChartLegend");
        if (!legend) return;

        legend.innerHTML = "";

        names.forEach((name, index) => {
          const rgb = palette(index);
          const item = document.createElement("div");
          item.className = "flex items-center gap-2";

          const dot = document.createElement("span");
          dot.className = "h-2.5 w-2.5 rounded-full";
          dot.style.backgroundColor = rgba(rgb, 1);

          const label = document.createElement("span");
          label.textContent = name;

          item.appendChild(dot);
          item.appendChild(label);
          legend.appendChild(item);
        });
      }

      function buildVisitorsChart() {
        const canvas = document.getElementById("visitorsChart");
        if (!canvas || !window.Chart) return null;

        const names = Array.isArray(deploymentNames)
          ? deploymentNames.filter((name) => typeof name === "string" && name.trim() !== "")
          : [];

        if (names.length === 0) {
          toggleEmptyState(false);
          return null;
        }

        toggleEmptyState(true);
        renderLegend(names);

        const ctx = canvas.getContext("2d");
        const h = 320;
        const labels = ["S1", "S2", "S3", "S4", "S5", "S6", "S7", "S8", "S9", "S10", "S11", "S12"];
        const shouldFill = names.length <= 4;
        const fillOpacity = names.length <= 4 ? 0.18 : 0.08;

        const datasets = names.map((name, index) => {
          const rgb = palette(index);
          const gradient = ctx.createLinearGradient(0, 0, 0, h);
          gradient.addColorStop(0, rgba(rgb, fillOpacity));
          gradient.addColorStop(1, rgba(rgb, 0));

          return {
            label: name,
            data: buildSyntheticSeries(name, index),
            borderColor: rgba(rgb, 1),
            backgroundColor: gradient,
            pointBackgroundColor: rgba(rgb, 1),
            pointBorderColor: rgba(rgb, 1),
            pointHoverBackgroundColor: rgba(rgb, 1),
            pointHoverBorderColor: rgba(rgb, 1),
            fill: shouldFill,
          };
        });

        return new Chart(ctx, {
          type: "line",
          data: { labels, datasets },
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
            // Statique pour l'instant: on garde l'UI vivante.
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
  window.K8S_API_URL = "../data/k8s_api.php";
  window.K8S_UI_BASE = "./pages/";
</script>
<script src="../assets/js/k8s_menu.js" defer></script>

</body>
</html>
