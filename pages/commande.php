<?php
session_start();

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';
require_once '../data/dolbar_api.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit();
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function commandeStatusLabel($status): string
{
    $normalized = strtolower(trim((string) $status));

    $map = [
        '0' => 'Brouillon',
        '1' => 'Validée',
        '2' => 'En cours',
        '3' => 'Livrée',
        '4' => 'Clôturée',
        '5' => 'Annulée',
        'draft' => 'Brouillon',
        'validated' => 'Validée',
        'shipped' => 'En cours',
        'delivered' => 'Livrée',
        'invoiced' => 'Clôturée',
        'canceled' => 'Annulée',
        'cancelled' => 'Annulée',
    ];

    return $map[$normalized] ?? ($normalized !== '' ? ucfirst($normalized) : 'Inconnu');
}

function commandeStatusClass($status): string
{
    $normalized = strtolower(trim((string) $status));

    if (in_array($normalized, ['1', 'validated', '2', 'shipped', '3', 'delivered', '4', 'invoiced'], true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }

    if (in_array($normalized, ['5', 'canceled', 'cancelled'], true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }

    if (in_array($normalized, ['0', 'draft'], true)) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    }

    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
}

function commandeDateDisplay($order): string
{
    $candidates = [
        $order['date_commande'] ?? null,
        $order['date'] ?? null,
        $order['date_creation'] ?? null,
        $order['date_valid'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $timestamp = dolbarApiDateToTimestamp($candidate);
        if ($timestamp !== null) {
            return date('d/m/Y', $timestamp);
        }
    }

    return '—';
}

function commandeAmountDisplay($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }

    return number_format((float) $value, 2, ',', ' ') . ' €';
}

function commandeExtractClientName(array $order): string
{
    $thirdparty = $order['thirdparty'] ?? null;

    $candidates = [
        is_array($thirdparty) ? ($thirdparty['name'] ?? null) : null,
        $order['socname'] ?? null,
        $order['thirdparty_name'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if ((is_string($candidate) || is_numeric($candidate)) && trim((string) $candidate) !== '') {
            return trim((string) $candidate);
        }
    }

    return '—';
}

function commandeExtractRows(array $payload): array
{
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }

    foreach (['data', 'items', 'results', 'orders', 'commandes'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    return [];
}


$orders = [];
$ordersError = null;
$ordersErrorCode = null;

try {
    $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $_SESSION['user']);
    $login = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $_SESSION['user']);
    $password = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $_SESSION['user']);
    $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $_SESSION['user']);
    $sessionToken = trim((string)($_SESSION['dolibarr_token'] ?? ''));

    if ($apiUrl === null) {
        throw new RuntimeException('Configuration Dolbar incomplète (URL manquante).', 0);
    }

    $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);
    $query = ['sortfield' => 't.rowid', 'sortorder' => 'DESC', 'limit' => 100];

    if ($sessionToken !== '') {
        $rawOrders = dolbarApiCallWithToken($apiUrl, '/orders', $sessionToken, 'GET', $query, [], 12);
    } elseif ($login !== null && $password !== null) {
        $token = dolbarApiLoginToken($apiUrl, $login, $password, 8);
        $rawOrders = dolbarApiCallWithToken($apiUrl, '/orders', $token, 'GET', $query, [], 12);
    } elseif ($apiKey !== null) {
        $rawOrders = dolbarApiCall($apiUrl, '/orders', $apiKey, 'GET', $query, [], 12);
    } else {
        throw new RuntimeException(
            'Configuration Dolibarr incomplète (renseigner login/mot de passe ou clé API).',
            0
        );
    }

    $orders = array_values(array_filter(
        commandeExtractRows($rawOrders),
        static fn($row): bool => is_array($row)
    ));
} catch (Throwable $e) {
    $ordersError = $e->getMessage();
    $ordersErrorCode = dolbarApiExtractErrorCode($e) ?? 'DLB';
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Mes commandes - GNL Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>

  <style>
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height, 0px));min-height:calc(100dvh - var(--app-header-height, 0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}

    .orders-table-wrap{overflow:auto;}
    .orders-table{width:100%;border-collapse:separate;border-spacing:0;min-width:760px;}
    .orders-table th,.orders-table td{padding:0.9rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.92rem;white-space:nowrap;}
    .orders-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.72rem;color:var(--muted-foreground, #64748b);text-align:left;}
    .orders-table tbody tr:hover{background:rgba(148,163,184,.08);}

    .badge{display:inline-flex;align-items:center;justify-content:center;border-radius:.5rem;padding:.2rem .6rem;font-size:.75rem;font-weight:600;}

    .collapsible-content {overflow:hidden;height:0;opacity:0;transition:height 220ms ease, opacity 220ms ease;will-change:height, opacity;}
    .collapsible-content.is-open {opacity:1;}
    .collapsible-trigger .collapsible-chevron {transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron {transform:rotate(90deg);}

    @media (max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main{padding:1rem;}
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <?php include('../include/menu.php'); ?>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6">
        <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-5 shadow-sm">
          <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-xl font-bold">Mes commandes</h1>
              <p class="text-sm text-muted-foreground mt-1">Liste synchronisée depuis Dolbar.</p>
            </div>
            <span class="text-sm text-muted-foreground"><?php echo count($orders); ?> commande(s)</span>
          </div>

          <?php if ($ordersError !== null): ?>
            <div class="mx-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 ml-8 mr-8">
              Impossible de charger les commandes (code: <?php echo h($ordersErrorCode); ?>). <?php echo h($ordersError); ?>
            </div>
          <?php elseif (empty($orders)): ?>
            <div class="mx-6 rounded-lg border border-dashed px-4 py-6 text-sm text-muted-foreground">
              Aucune commande trouvée pour le moment.
            </div>
          <?php else: ?>
            <div class="orders-table-wrap px-2 md:px-6">
              <table class="orders-table">
                <thead>
                  <tr>
                    <th>Référence</th>
                    <th>Date</th>
                    <th>Statut</th>
                    <th>Total HT</th>
                    <th>Total TTC</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                  <?php
                    $reference = $order['ref'] ?? $order['ref_client'] ?? ('CMD-' . (int)($order['id'] ?? 0));
                    $statusRaw = $order['statut'] ?? $order['status'] ?? '';
                    $statusLabel = commandeStatusLabel($statusRaw);
                    $statusClass = commandeStatusClass($statusRaw);
                    $totalHt = $order['total_ht'] ?? $order['total_net'] ?? $order['total'] ?? null;
                    $totalTtc = $order['total_ttc'] ?? null;
                  ?>
                  <tr>
                    <td class="font-medium"><?php echo h($reference); ?></td>
                    <td><?php echo h(commandeDateDisplay($order)); ?></td>
                    <td>
                      <span class="badge <?php echo h($statusClass); ?>"><?php echo h($statusLabel); ?></span>
                    </td>
                    <td><?php echo h(commandeAmountDisplay($totalHt)); ?></td>
                    <td><?php echo h(commandeAmountDisplay($totalTtc)); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>

  <script>
  (function () {
    function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

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
            content.hidden = false;
            content.classList.add('is-open');
            content.style.height = '0px';
            var h = content.scrollHeight;
            requestAnimationFrame(function () { content.style.height = h + 'px'; });
            content.addEventListener('transitionend', function onEnd(ev) {
              if (ev.propertyName !== 'height') return;
              content.style.height = 'auto';
              content.removeEventListener('transitionend', onEnd);
            });
          } else {
            btn.setAttribute('aria-expanded', 'false');
            content.classList.remove('is-open');
            var current = content.scrollHeight;
            content.style.height = current + 'px';
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

  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../assets/js/k8s_menu.js" defer></script>
</body>
</html>
