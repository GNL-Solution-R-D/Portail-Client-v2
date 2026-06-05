<?php

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';
require_once '../data/dolbar_api.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit();
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function abonnementsExtractRows(array $payload): array
{
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }

    foreach (['data', 'items', 'results', 'subscriptions', 'contracts', 'abonnements'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    return [];
}

function abonnementsExtractContractServices(array $contract): array
{
    foreach (['lines', 'services', 'service_lines', 'detlines'] as $key) {
        if (isset($contract[$key]) && is_array($contract[$key])) {
            return array_values(array_filter(
                $contract[$key],
                static fn($row): bool => is_array($row)
            ));
        }
    }

    return [];
}

function abonnementsBuildServiceRows(array $contracts): array
{
    $rows = [];

    foreach ($contracts as $contract) {
        $services = abonnementsExtractContractServices($contract);
        if (empty($services)) {
            $rows[] = $contract;
            continue;
        }

        foreach ($services as $service) {
            $rows[] = [
                '__contract' => $contract,
                '__service' => $service,
            ] + $service + $contract;
        }
    }

    return $rows;
}

function abonnementsParseDateToTimestamp($value): ?int
{
    return dolbarApiDateToTimestamp($value);
}

function abonnementsExtractStartTimestamp(array $row): ?int
{
    $candidates = [
        $row['date_contrat'] ?? null,
        $row['date_ouverture'] ?? null,
        $row['date_valid'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $ts = abonnementsParseDateToTimestamp($candidate);
        if ($ts !== null) {
            return $ts;
        }
    }

    return null;
}

function abonnementsExtractPlannedEndTimestamp(array $row): ?int
{
    $candidates = [
        $row['date_end'] ?? null,
        $row['date_fin_validite'] ?? null,
        $row['fin_validite'] ?? null,
        $row['date_cloture'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $ts = abonnementsParseDateToTimestamp($candidate);
        if ($ts !== null) {
            return $ts;
        }
    }

    return null;
}

function abonnementsTimestampDisplay(?int $timestamp): string
{
    if ($timestamp === null || $timestamp <= 0) {
        return '—';
    }

    return date('d/m/Y', $timestamp);
}

function abonnementsFrequencyDisplay(?int $startTimestamp, ?int $endTimestamp): string
{
    if ($startTimestamp === null || $endTimestamp === null || $endTimestamp <= $startTimestamp) {
        return '—';
    }

    $start = new DateTimeImmutable('@' . $startTimestamp);
    $end = new DateTimeImmutable('@' . $endTimestamp);
    $diff = $start->diff($end);

    $parts = [];
    if ($diff->y > 0) {
        $parts[] = $diff->y . ' an' . ($diff->y > 1 ? 's' : '');
    }
    if ($diff->m > 0) {
        $parts[] = $diff->m . ' mois';
    }
    if ($diff->d > 0) {
        $parts[] = $diff->d . ' jour' . ($diff->d > 1 ? 's' : '');
    }

    if (empty($parts)) {
        return t('Moins d’un jour');
    }

    return implode(' ', $parts);
}

function abonnementsAmountDisplay($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }

    return number_format((float) $value, 2, ',', ' ') . ' €';
}

function abonnementsStatusLabel($status): string
{
    $normalized = strtolower(trim((string) $status));

    $map = [
        '0' => t('Brouillon'),
        '4' => t('En cours'),
        '5' => 'Fermé',
        'draft' => t('Brouillon'),
        'open' => t('En cours'),
        'running' => t('En cours'),
        'closed' => 'Fermé',
    ];

    return $map[$normalized] ?? ($normalized !== '' ? ucfirst($normalized) : t('Inconnu'));
}

function abonnementsStatusClass($status): string
{
    $normalized = strtolower(trim((string) $status));

    if (in_array($normalized, ['4', 'open', 'running'], true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }

    if (in_array($normalized, ['0', 'draft'], true)) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    }

    if (in_array($normalized, ['5', 'closed'], true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }

    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
}

$subscriptions = [];
$subscriptionsError = null;
$subscriptionsErrorCode = null;

if (dolbarApiIntegrationEnabled()) {
try {
    $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $_SESSION['user']);
    $login = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $_SESSION['user']);
    $password = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $_SESSION['user']);
    $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $_SESSION['user']);
    $sessionToken = dolbarApiResolveSessionToken($_SESSION);

    if ($apiUrl === null) {
        throw new RuntimeException(t('Configuration Dolbar incomplète (URL manquante).'), 0);
    }

    $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);
    $query = ['sortfield' => 't.rowid', 'sortorder' => 'DESC', 'limit' => 100];

    $endpoints = ['/contracts'];
    $lastError = null;
    $rawSubscriptions = [];

    foreach ($endpoints as $endpoint) {
        try {
            if ($sessionToken !== '') {
                $rawSubscriptions = dolbarApiCallWithToken($apiUrl, $endpoint, $sessionToken, 'GET', $query, [], 12);
            } elseif ($login !== null && $password !== null) {
                $token = dolbarApiLoginToken($apiUrl, $login, $password, 8);
                $rawSubscriptions = dolbarApiCallWithToken($apiUrl, $endpoint, $token, 'GET', $query, [], 12);
            } elseif ($apiKey !== null) {
                $rawSubscriptions = dolbarApiCall($apiUrl, $endpoint, $apiKey, 'GET', $query, [], 12);
            } else {
                throw new RuntimeException(
                    t('Configuration Dolibarr incomplète (renseigner login/mot de passe ou clé API).'),
                    0
                );
            }

            $rows = abonnementsExtractRows(is_array($rawSubscriptions) ? $rawSubscriptions : []);
            if (!empty($rows) || $endpoint === '/contracts') {
                break;
            }
        } catch (Throwable $endpointError) {
            $lastError = $endpointError;
        }
    }

    if (!empty($lastError) && empty($rawSubscriptions)) {
        throw $lastError;
    }

    $contracts = array_values(array_filter(
        abonnementsExtractRows(is_array($rawSubscriptions) ? $rawSubscriptions : []),
        static fn($row): bool => is_array($row)
    ));
    $subscriptions = abonnementsBuildServiceRows($contracts);
} catch (Throwable $e) {
    $subscriptionsError = $e->getMessage();
    $subscriptionsErrorCode = dolbarApiExtractErrorCode($e) ?? 'DLB';
}
}

// Barre de recherche du header (include/header.php) : activée pour cette page.
// Le champ porte l'id ci-dessous ; le JS en bas de page y branche le filtrage
// du tableau des abonnements (et le rafraîchissement via data/abonnements_api.php).
$showSearch        = true;
$searchInputId     = 'subscriptionsSearchInput';
$searchPlaceholder = t('Rechercher un abonnement…');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Mes abonnements - GNL Solution') ?></title>
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

    .subscriptions-table-wrap{overflow:auto;}
    .subscriptions-table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px;}
    .subscriptions-table th,.subscriptions-table td{padding:0.9rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.92rem;white-space:nowrap;}
    .subscriptions-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.72rem;color:var(--muted-foreground, #64748b);text-align:left;}
    .subscriptions-table tbody tr:hover{background:rgba(148,163,184,.08);}

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

        <aside class="dashboard-sidebar">
            <?php include('../include/menu.php'); ?>
        </aside>
        <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6">
        <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-5 shadow-sm">
          <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-xl font-bold"><?= t('Mes abonnements') ?></h1>
              <p class="text-sm text-muted-foreground mt-1"><?= t('Suivi des abonnements synchronisés depuis Dolbar.') ?></p>
            </div>
            <span id="subscriptionsCount" class="text-sm text-muted-foreground"
                  data-suffix="<?php echo h(t('abonnement(s)')); ?>"
                  data-total="<?php echo (int) count($subscriptions); ?>"><?php echo count($subscriptions); ?> <?= t('abonnement(s)') ?></span>
          </div>

          <?php if ($subscriptionsError !== null): ?>
            <div class="mx-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 ml-8 mr-8">
              Impossible de charger les abonnements (code: <?php echo h($subscriptionsErrorCode); ?>). <?php echo h($subscriptionsError); ?>
            </div>
          <?php elseif (empty($subscriptions)): ?>
            <div class="mx-6 rounded-lg border border-dashed px-4 py-6 text-sm text-muted-foreground">
              <?= t('Aucun abonnement trouvé pour le moment.') ?>
            </div>
          <?php else: ?>
            <div class="subscriptions-table-wrap px-2 md:px-6">
              <table class="subscriptions-table">
                <thead>
                  <tr>
                    <th><?= t('Référence') ?></th>
                    <th><?= t('Libellé') ?></th>
                    <th><?= t('Date de début') ?></th>
                    <th><?= t('Prochaine échéance') ?></th>
                    <th>Fréquence</th>
                    <th><?= t('Montant') ?></th>
                    <th><?= t('Statut') ?></th>
                  </tr>
                </thead>
                <tbody id="subscriptionsTableBody">
                <?php foreach ($subscriptions as $subscription): ?>
                  <?php
                    $contract = (isset($subscription['__contract']) && is_array($subscription['__contract'])) ? $subscription['__contract'] : $subscription;
                    $service = (isset($subscription['__service']) && is_array($subscription['__service'])) ? $subscription['__service'] : $subscription;
                    $subscriptionId = (int)($service['id'] ?? $contract['id'] ?? 0);
                    $reference = $contract['ref'] ?? ('ABO-' . $subscriptionId);
                    $label = $service['product_label'] ?? $service['label'] ?? $service['description'] ?? $contract['label'] ?? $contract['description'] ?? '—';
                    $startTimestamp = abonnementsExtractStartTimestamp($subscription);
                    $plannedEndTimestamp = abonnementsExtractPlannedEndTimestamp($subscription);
                    $frequency = abonnementsFrequencyDisplay($startTimestamp, $plannedEndTimestamp);
                    $amount = $service['subprice'] ?? $service['total_ht'] ?? $subscription['total_ht'] ?? $subscription['total_ttc'] ?? null;
                    $statusRaw = $service['statut'] ?? '';
                    $statusLabel = abonnementsStatusLabel($statusRaw);
                    $statusClass = abonnementsStatusClass($statusRaw);
                    // Chaîne consultée par le filtre de recherche (insensible casse/accents côté JS).
                    $searchHaystack = strtolower(trim(implode(' ', [
                        (string) $reference,
                        (string) $label,
                        abonnementsTimestampDisplay($startTimestamp),
                        abonnementsTimestampDisplay($plannedEndTimestamp),
                        (string) $frequency,
                        abonnementsAmountDisplay($amount),
                        (string) $statusLabel,
                    ])));
                  ?>
                  <tr data-search="<?php echo h($searchHaystack); ?>">
                    <td class="font-medium"><?php echo h($reference); ?></td>
                    <td><?php echo h($label); ?></td>
                    <td><?php echo h(abonnementsTimestampDisplay($startTimestamp)); ?></td>
                    <td><?php echo h(abonnementsTimestampDisplay($plannedEndTimestamp)); ?></td>
                    <td><?php echo h((string) $frequency); ?></td>
                    <td><?php echo h(abonnementsAmountDisplay($amount)); ?></td>
                    <td><span class="badge <?php echo h($statusClass); ?>"><?php echo h($statusLabel); ?></span></td>
                  </tr>
                <?php endforeach; ?>
                  <tr id="subscriptionsNoResults" hidden>
                    <td colspan="7" class="text-center text-sm text-muted-foreground" style="padding:1.5rem 1rem;">
                      <?= t('Aucun abonnement ne correspond à votre recherche.') ?>
                    </td>
                  </tr>
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

  <!-- Recherche d'abonnements (barre du header) + rafraîchissement via data/abonnements_api.php -->
  <script>
    window.SUBSCRIPTIONS_API_URL = window.SUBSCRIPTIONS_API_URL || "../data/abonnements_api.php";
  </script>
  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    // Normalisation : minuscules + suppression des accents pour une recherche tolérante.
    function norm(s) {
      return String(s == null ? '' : s)
        .toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
      });
    }

    ready(function () {
      var input    = document.getElementById('subscriptionsSearchInput');
      var tbody    = document.getElementById('subscriptionsTableBody');
      var noResult = document.getElementById('subscriptionsNoResults');
      var counter  = document.getElementById('subscriptionsCount');

      // Pas de tableau (page en erreur ou vide) : le filtre n'a rien à faire.
      if (!tbody) return;

      var suffix = counter ? (counter.getAttribute('data-suffix') || '') : '';

      function dataRows() {
        return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search]'));
      }

      function updateCounter(visible) {
        if (!counter) return;
        counter.textContent = visible + (suffix ? ' ' + suffix : '');
      }

      function applyFilter() {
        var q = input ? norm(input.value.trim()) : '';
        var tokens = q ? q.split(/\s+/) : [];
        var rows = dataRows();
        var visible = 0;

        rows.forEach(function (row) {
          var hay = norm(row.getAttribute('data-search') || row.textContent || '');
          var match = tokens.every(function (tok) { return hay.indexOf(tok) !== -1; });
          row.hidden = !match;
          if (match) visible++;
        });

        if (noResult) noResult.hidden = (visible !== 0 || rows.length === 0);
        updateCounter(visible);
      }

      // Construit une ligne <tr> à partir d'un abonnement renvoyé par l'API
      // (mêmes colonnes et même data-search que le rendu serveur).
      function rowHtml(s) {
        var hay = [
          s.ref, s.label, s.start, s.end, s.frequency, s.amount, s.status_label
        ].join(' ').toLowerCase();

        return '<tr data-search="' + esc(hay) + '">' +
          '<td class="font-medium">' + esc(s.ref) + '</td>' +
          '<td>' + esc(s.label) + '</td>' +
          '<td>' + esc(s.start) + '</td>' +
          '<td>' + esc(s.end) + '</td>' +
          '<td>' + esc(s.frequency) + '</td>' +
          '<td>' + esc(s.amount) + '</td>' +
          '<td><span class="badge ' + esc(s.status_class) + '">' + esc(s.status_label) + '</span></td>' +
        '</tr>';
      }

      // Rafraîchissement progressif depuis l'API (facultatif) : si l'appel
      // réussit et renvoie des lignes, on remplace le rendu serveur par des
      // données fraîches ; sinon on conserve l'affichage initial.
      function refreshFromApi() {
        var url = window.SUBSCRIPTIONS_API_URL;
        if (!url) return;

        fetch(url + '?action=list', {
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin'
        })
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (data) {
          if (!data || !data.ok || !Array.isArray(data.subscriptions) || !data.subscriptions.length) return;

          var html = data.subscriptions.map(rowHtml).join('');
          if (noResult) {
            // On réinjecte les lignes avant la ligne « aucun résultat ».
            dataRows().forEach(function (r) { r.parentNode.removeChild(r); });
            noResult.insertAdjacentHTML('beforebegin', html);
          } else {
            tbody.innerHTML = html;
          }

          if (counter) counter.setAttribute('data-total', String(data.subscriptions.length));
          applyFilter();
        })
        .catch(function () { /* hors-ligne / erreur : on garde le rendu serveur */ });
      }

      if (input) {
        input.addEventListener('input', applyFilter);
        input.addEventListener('search', applyFilter); // croix « effacer » du type=search
      }

      applyFilter();      // état initial cohérent (compteur, etc.)
      refreshFromApi();   // synchronise avec l'API au chargement
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