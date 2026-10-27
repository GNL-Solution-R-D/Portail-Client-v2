<?php
/* =====================================================================
   GNL Solution — Liste des services  (/services)  [espace client]
   ---------------------------------------------------------------------
   Vue d'ensemble de tous les produits achetés (actifs, suspendus, en
   déploiement), toutes catégories confondues. Mêmes sources que les
   dépliants de la barre latérale :
     - data/services_menu_api.php  → entrées groupées par catégorie ;
     - data/services_state_api.php → état réel chez le fournisseur.
   Filtres par catégorie et recherche locale (champ de la barre du haut).
   ===================================================================== */

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion?return=' . rawurlencode('/services'));
    exit();
}

require_once __DIR__ . '/../include/org_permissions.php';
orgRequirePage('services.manage');

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit();
}
accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

$showSearch        = true;
$searchInputId     = 'servicesSearchInput';
$searchPlaceholder = t('Rechercher un service…');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars((string) ($_SESSION['language'] ?? 'fr'), ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= t('Mes services') ?> — GNL Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta name="theme-color" content="#141a33"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  <link rel="stylesheet" href="../assets/styles/gnl-portail.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/styles/gnl-portail.css') ?>"/>
  <link rel="preload" href="/assets/gnl/fonts/Manrope-latin.woff2" as="font" type="font/woff2" crossorigin/>
  <link rel="icon" href="/assets/gnl/img/favicon-32.png" sizes="32x32"/>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height ec-page">
        <div class="ec-head">
          <div>
            <p class="ec-kicker"><?= t('Piloter') ?></p>
            <h1 class="ec-h1"><?= t('Mes services') ?></h1>
            <p class="ec-sub" id="servicesSummary"><?= t('Chargement…') ?></p>
          </div>
          <a class="ec-btn ec-btn--primary" href="https://gnl-solution.com/solutions" target="_blank" rel="noopener">
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
            <?= t('Commander un service') ?>
          </a>
        </div>

        <div class="ec-filters" role="group" aria-label="<?= t('Filtrer par catégorie') ?>" id="servicesFilters">
          <button type="button" class="ec-filter" data-cat="" aria-pressed="true"><?= t('Tous') ?> <span data-count=""></span></button>
          <button type="button" class="ec-filter" data-cat="web" aria-pressed="false" hidden><?= t('Sites web') ?> <span data-count="web"></span></button>
          <button type="button" class="ec-filter" data-cat="cloud" aria-pressed="false" hidden><?= t('Cloud') ?> <span data-count="cloud"></span></button>
          <button type="button" class="ec-filter" data-cat="other" aria-pressed="false" hidden><?= t('Spécifiques') ?> <span data-count="other"></span></button>
          <button type="button" class="ec-filter" data-cat="vm" aria-pressed="false" hidden><?= t('Serveurs virtualisés') ?> <span data-count="vm"></span></button>
          <button type="button" class="ec-filter" data-cat="bm" aria-pressed="false" hidden><?= t('Serveurs dédiés') ?> <span data-count="bm"></span></button>
        </div>

        <section class="ec-card" aria-label="<?= t('Liste des services') ?>">
          <div class="ec-table-wrap">
            <table class="ec-table" style="min-width:680px">
              <thead>
                <tr>
                  <th scope="col"><?= t('Service') ?></th>
                  <th scope="col"><?= t('Offre') ?></th>
                  <th scope="col"><?= t('Catégorie') ?></th>
                  <th scope="col"><?= t('État') ?></th>
                  <th scope="col"><span class="ec-sr"><?= t('Actions') ?></span></th>
                </tr>
              </thead>
              <tbody id="servicesBody">
                <tr class="ec-table__state"><td colspan="5"><?= t('Chargement…') ?></td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <p class="ec-muted ec-small">
          <?= t('Un service sans page de gestion est piloté par notre équipe : ouvrez un ticket pour toute demande.') ?>
          <?= t('Clic droit sur un service de la barre latérale pour le renommer.') ?>
        </p>
      </div>
    </main>
  </div>

  <script>
  (function () {
    var I18N = {
      none:    <?= json_encode(t('Aucun service pour le moment.'), JSON_UNESCAPED_UNICODE) ?>,
      noMatch: <?= json_encode(t('Aucun service ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      err:     <?= json_encode(t('Impossible de charger les services.'), JSON_UNESCAPED_UNICODE) ?>,
      manage:  <?= json_encode(t('Gérer'), JSON_UNESCAPED_UNICODE) ?>,
      ticket:  <?= json_encode(t('Demande'), JSON_UNESCAPED_UNICODE) ?>,
      summary: <?= json_encode(t('{n} service(s) · {on} en ligne'), JSON_UNESCAPED_UNICODE) ?>
    };
    var CAT = {
      web:   <?= json_encode(t('Site web'), JSON_UNESCAPED_UNICODE) ?>,
      cloud: <?= json_encode(t('Cloud'), JSON_UNESCAPED_UNICODE) ?>,
      other: <?= json_encode(t('Spécifique'), JSON_UNESCAPED_UNICODE) ?>,
      vm:    <?= json_encode(t('Serveur virtualisé'), JSON_UNESCAPED_UNICODE) ?>,
      bm:    <?= json_encode(t('Serveur dédié'), JSON_UNESCAPED_UNICODE) ?>
    };
    var LABELS = {
      ok: <?= json_encode(t('En ligne'), JSON_UNESCAPED_UNICODE) ?>, deploy: <?= json_encode(t('Déploiement'), JSON_UNESCAPED_UNICODE) ?>,
      susp: <?= json_encode(t('Suspendu'), JSON_UNESCAPED_UNICODE) ?>, stop: <?= json_encode(t('Arrêté'), JSON_UNESCAPED_UNICODE) ?>,
      err: <?= json_encode(t('Incident'), JSON_UNESCAPED_UNICODE) ?>
    };

    var body = document.getElementById('servicesBody');
    var search = document.getElementById('servicesSearchInput');
    var filters = document.getElementById('servicesFilters');
    var rows = [], current = '';

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
    function norm(s) { return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
    function getJson(url) {
      return fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return null; }).then(function (d) { return { ok: r.ok && d && d.ok, data: d }; }); })
        .catch(function () { return { ok: false, data: null }; });
    }
    function state(entry, st) {
      if (st && (st.level === 'error' || st.level === 'crash')) return { cls: 'ec-badge--danger', label: LABELS.err, title: st.reason || st.label || '' };
      var s = String(st && st.level === 'state' ? st.label : entry.status || '').toLowerCase();
      if (s === 'running' || s === 'active') return { cls: 'ec-badge--ok', label: LABELS.ok, on: true };
      if (s === 'deployment' || s === 'starting' || s === 'stopping') return { cls: 'ec-badge--warn', label: LABELS.deploy };
      if (s === 'suspended') return { cls: 'ec-badge--warn', label: LABELS.susp };
      if (s === 'stopped' || s === 'offline') return { cls: '', label: LABELS.stop };
      return { cls: '', label: entry.status || '—' };
    }

    function render() {
      var q = search ? norm(search.value.trim()) : '';
      var tokens = q ? q.split(/\s+/) : [];
      var list = rows.filter(function (r) {
        if (current && r.cat !== current) return false;
        return tokens.every(function (t) { return r.hay.indexOf(t) !== -1; });
      });
      if (!rows.length) { body.innerHTML = '<tr class="ec-table__state"><td colspan="5">' + esc(I18N.none) + '</td></tr>'; return; }
      if (!list.length) { body.innerHTML = '<tr class="ec-table__state"><td colspan="5">' + esc(I18N.noMatch) + '</td></tr>'; return; }
      body.innerHTML = list.map(function (r) {
        var e = r.e, s = r.s;
        var action = e.href
          ? '<a class="ec-btn ec-btn--ghost ec-btn--sm" href="' + esc(e.href) + '">' + esc(I18N.manage) + '</a>'
          : '<a class="ec-link" href="/tickets?nouveau=1&service=' + encodeURIComponent(e.name || e.slug || '') + '">' + esc(I18N.ticket) + '</a>';
        return '<tr>' +
          '<td><strong style="display:block">' + esc(e.name || e.slug) + '</strong>' +
            (e.slug ? '<span class="ec-muted ec-small mono">' + esc(e.slug) + '</span>' : '') + '</td>' +
          '<td class="ec-muted">' + esc(e.product_name || '—') + '</td>' +
          '<td class="ec-muted">' + esc(CAT[r.cat] || '') + '</td>' +
          '<td><span class="ec-badge ' + s.cls + '"' + (s.title ? ' title="' + esc(s.title) + '"' : '') + '>' + esc(s.label) + '</span></td>' +
          '<td style="text-align:right">' + action + '</td></tr>';
      }).join('');
    }

    filters.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-cat]'); if (!b) return;
      current = b.getAttribute('data-cat') || '';
      filters.querySelectorAll('[data-cat]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      render();
    });
    if (search) { search.addEventListener('input', render); search.addEventListener('search', render); }

    Promise.all([getJson('../data/services_menu_api.php'), getJson('../data/services_state_api.php')]).then(function (res) {
      if (!res[0].ok) {
        body.innerHTML = '<tr class="ec-table__state ec-table__state--error"><td colspan="5">' + esc(I18N.err + (res[0].data && res[0].data.error ? ' ' + res[0].data.error : '')) + '</td></tr>';
        document.getElementById('servicesSummary').textContent = '';
        return;
      }
      var menus = res[0].data.menus || {}, states = (res[1].ok && res[1].data.states) || {};
      var counts = {}, online = 0;
      Object.keys(menus).forEach(function (cat) {
        (menus[cat] || []).forEach(function (e) {
          if (!e || !(e.name || e.slug)) return;
          var s = state(e, states[e.uid]);
          if (s.on) online++;
          counts[cat] = (counts[cat] || 0) + 1;
          rows.push({ e: e, cat: cat, s: s, hay: norm([e.name, e.slug, e.product_name, CAT[cat], s.label].join(' ')) });
        });
      });
      filters.querySelectorAll('[data-count]').forEach(function (el) {
        var k = el.getAttribute('data-count');
        var n = k ? (counts[k] || 0) : rows.length;
        el.textContent = '(' + n + ')';
        if (k) el.parentNode.hidden = n === 0;
      });
      document.getElementById('servicesSummary').textContent = I18N.summary.replace('{n}', rows.length).replace('{on}', online);
      render();
    });
  })();
  </script>

  <script src="../assets/js/collapsible.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/collapsible.js') ?>"></script>
  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../assets/js/services_menu.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/services_menu.js') ?>" defer></script>
</body>
</html>
