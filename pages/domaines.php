<?php
/* =====================================================================
   GNL Solution — Domaines et DNS  (/domaines)  [espace client]
   ---------------------------------------------------------------------
   Liste des domaines de l'organisation (table n8n domain_buy_name, lue
   par include/menu.php : action=domain.list). La page ne refait pas
   l'appel : elle écoute l'événement « gnl:domains » émis par le menu et
   réutilise ses fenêtres (vérification en attente, suppression) via
   window.GNL_DOMAINS. « Ajouter un domaine » ouvre l'assistant du menu.
   ===================================================================== */

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion?return=' . rawurlencode('/domaines'));
    exit();
}

require_once __DIR__ . '/../include/org_permissions.php';
orgRequirePage('dns.manage');

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit();
}
accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

$showSearch        = true;
$searchInputId     = 'domainsSearchInput';
$searchPlaceholder = t('Rechercher un domaine…');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars((string) ($_SESSION['language'] ?? 'fr'), ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= t('Domaines et DNS') ?> — GNL Solution</title>
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
            <h1 class="ec-h1"><?= t('Domaines et DNS') ?></h1>
            <p class="ec-sub" id="domainsSummary"><?= t('Chargement…') ?></p>
          </div>
          <button type="button" class="ec-btn ec-btn--primary" data-add-domain-open>
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
            <?= t('Ajouter un domaine') ?>
          </button>
        </div>

        <div class="ec-row">
          <section class="ec-card ec-grow" aria-label="<?= t('Liste des domaines') ?>">
            <div class="ec-table-wrap">
              <table class="ec-table" style="min-width:640px">
                <thead>
                  <tr>
                    <th scope="col"><?= t('Domaine') ?></th>
                    <th scope="col"><?= t('Provenance') ?></th>
                    <th scope="col"><?= t('Serveurs DNS') ?></th>
                    <th scope="col"><?= t('État') ?></th>
                    <th scope="col"><span class="ec-sr"><?= t('Actions') ?></span></th>
                  </tr>
                </thead>
                <tbody id="domainsBody">
                  <tr class="ec-table__state"><td colspan="5"><?= t('Chargement…') ?></td></tr>
                </tbody>
              </table>
            </div>
          </section>

          <div class="ec-side-col">
            <section class="ec-card" aria-labelledby="t-ns">
              <div class="ec-card__head"><h2 class="ec-h2" id="t-ns"><?= t('Nos serveurs DNS') ?></h2></div>
              <div class="ec-card__body">
                <p class="ec-muted ec-small" style="margin:0 0 12px"><?= t('Pour confier la zone d\'un domaine externe à GNL Solution, indiquez ces serveurs chez votre registrar.') ?></p>
                <ul class="ec-list" style="border:1px solid var(--border);border-radius:3px">
                  <?php foreach (($gnl_nameservers ?? []) as $i => $ns): ?>
                  <li style="padding:10px 12px">
                    <span class="ec-muted ec-small" style="width:34px">NS<?= (int) $i + 1 ?></span>
                    <code style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px"><?= htmlspecialchars((string) $ns, ENT_QUOTES, 'UTF-8') ?></code>
                    <button type="button" class="ec-btn ec-btn--ghost ec-btn--sm" data-copy-ns="<?= htmlspecialchars((string) $ns, ENT_QUOTES, 'UTF-8') ?>"><?= t('Copier') ?></button>
                  </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </section>
            <section class="ec-card ec-card__body" aria-labelledby="t-help">
              <h2 class="ec-h2" id="t-help" style="margin-bottom:8px"><?= t('Bon à savoir') ?></h2>
              <ul class="ec-muted ec-small" style="margin:0;padding-left:18px;display:grid;gap:6px">
                <li><?= t('La propagation d\'un changement de serveurs DNS peut prendre jusqu\'à 48 h.') ?></li>
                <li><?= t('Un domaine « en attente » n\'est pas encore vérifié : cliquez sur Vérification pour connaître la marche à suivre.') ?></li>
                <li><?= t('Supprimer un domaine retire aussi ses routes vers vos services.') ?></li>
              </ul>
            </section>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
  (function () {
    var I18N = {
      none:     <?= json_encode(t('Aucun domaine pour le moment. Ajoutez-en un pour le relier à vos services.'), JSON_UNESCAPED_UNICODE) ?>,
      noMatch:  <?= json_encode(t('Aucun domaine ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      err:      <?= json_encode(t('Impossible de charger les domaines.'), JSON_UNESCAPED_UNICODE) ?>,
      gnl:      <?= json_encode(t('Acheté chez GNL'), JSON_UNESCAPED_UNICODE) ?>,
      ext:      <?= json_encode(t('Externe'), JSON_UNESCAPED_UNICODE) ?>,
      nsGnl:    <?= json_encode(t('GNL Solution'), JSON_UNESCAPED_UNICODE) ?>,
      nsOwn:    <?= json_encode(t('Les vôtres'), JSON_UNESCAPED_UNICODE) ?>,
      ok:       <?= json_encode(t('Vérifié'), JSON_UNESCAPED_UNICODE) ?>,
      pending:  <?= json_encode(t('En attente'), JSON_UNESCAPED_UNICODE) ?>,
      off:      <?= json_encode(t('Désactivé'), JSON_UNESCAPED_UNICODE) ?>,
      inactive: <?= json_encode(t('À rattacher'), JSON_UNESCAPED_UNICODE) ?>,
      zone:     <?= json_encode(t('Zone DNS'), JSON_UNESCAPED_UNICODE) ?>,
      verify:   <?= json_encode(t('Vérification'), JSON_UNESCAPED_UNICODE) ?>,
      attach:   <?= json_encode(t('Rattacher'), JSON_UNESCAPED_UNICODE) ?>,
      del:      <?= json_encode(t('Supprimer'), JSON_UNESCAPED_UNICODE) ?>,
      copied:   <?= json_encode(t('Copié'), JSON_UNESCAPED_UNICODE) ?>,
      summary:  <?= json_encode(t('{n} domaine(s) · {v} vérifié(s)'), JSON_UNESCAPED_UNICODE) ?>
    };
    var body = document.getElementById('domainsBody');
    var search = document.getElementById('domainsSearchInput');
    var rows = [];

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
    function truthy(v) { return v === true || v === 1 || v === '1' || String(v).toLowerCase() === 'true' || String(v).toLowerCase() === 't'; }
    function norm(s) { return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

    function build(list) {
      var byName = {};
      (list || []).forEach(function (d) {
        var n = String((d && d.domain_buy_name) || '').trim(); if (!n) return;
        var k = n.toLowerCase(), cur = byName[k];
        var row = { name: n, active: truthy(d.domain_active), verified: truthy(d.verified), off: truthy(d.domain_off), gnl: truthy(d.gnl_domain), ns: truthy(d.ns_gnl) };
        if (!cur) byName[k] = row;
        else { cur.active = cur.active || row.active; cur.verified = cur.verified || row.verified; cur.off = cur.off || row.off; cur.gnl = cur.gnl || row.gnl; cur.ns = cur.ns || row.ns; }
      });
      return Object.keys(byName).sort().map(function (k) { return byName[k]; });
    }

    function render() {
      var q = search ? norm(search.value.trim()) : '';
      var list = rows.filter(function (r) { return !q || norm(r.name).indexOf(q) !== -1; });
      if (!rows.length) { body.innerHTML = '<tr class="ec-table__state"><td colspan="5">' + esc(I18N.none) + '</td></tr>'; return; }
      if (!list.length) { body.innerHTML = '<tr class="ec-table__state"><td colspan="5">' + esc(I18N.noMatch) + '</td></tr>'; return; }
      body.innerHTML = list.map(function (r) {
        var badge, actions = [];
        if (!r.active)       { badge = '<span class="ec-badge">' + esc(I18N.inactive) + '</span>'; actions.push('<button type="button" class="ec-btn ec-btn--ghost ec-btn--sm" data-add-domain-open>' + esc(I18N.attach) + '</button>'); }
        else if (r.off)      { badge = '<span class="ec-badge">' + esc(I18N.off) + '</span>'; actions.push('<a class="ec-btn ec-btn--ghost ec-btn--sm" href="/zdns?domain=' + encodeURIComponent(r.name) + '">' + esc(I18N.zone) + '</a>'); }
        else if (r.verified) { badge = '<span class="ec-badge ec-badge--ok">' + esc(I18N.ok) + '</span>'; actions.push('<a class="ec-btn ec-btn--ghost ec-btn--sm" href="/zdns?domain=' + encodeURIComponent(r.name) + '">' + esc(I18N.zone) + '</a>'); }
        else                 { badge = '<span class="ec-badge ec-badge--warn">' + esc(I18N.pending) + '</span>'; actions.push('<button type="button" class="ec-btn ec-btn--ghost ec-btn--sm" data-pending="' + esc(r.name) + '">' + esc(I18N.verify) + '</button>'); }
        if (r.active) actions.push('<button type="button" class="ec-btn ec-btn--sm" style="color:var(--gnl-danger);background:transparent" data-delete="' + esc(r.name) + '">' + esc(I18N.del) + '</button>');
        return '<tr><td><strong>' + esc(r.name) + '</strong></td>' +
          '<td class="ec-muted">' + esc(r.gnl ? I18N.gnl : I18N.ext) + '</td>' +
          '<td class="ec-muted">' + esc(r.ns ? I18N.nsGnl : I18N.nsOwn) + '</td>' +
          '<td>' + badge + '</td>' +
          '<td style="text-align:right;white-space:nowrap"><span style="display:inline-flex;gap:6px">' + actions.join('') + '</span></td></tr>';
      }).join('');
    }

    document.addEventListener('gnl:domains', function (ev) {
      var d = ev.detail || {};
      if (!d.ok) {
        body.innerHTML = '<tr class="ec-table__state ec-table__state--error"><td colspan="5">' + esc(I18N.err + (d.error ? ' ' + d.error : '')) + '</td></tr>';
        document.getElementById('domainsSummary').textContent = '';
        return;
      }
      rows = build(d.rows);
      var active = rows.filter(function (r) { return r.active; });
      document.getElementById('domainsSummary').textContent = I18N.summary
        .replace('{n}', active.length).replace('{v}', active.filter(function (r) { return r.verified; }).length);
      render();
    });

    body.addEventListener('click', function (ev) {
      var p = ev.target.closest('[data-pending]'), d = ev.target.closest('[data-delete]');
      if (p && window.GNL_DOMAINS) window.GNL_DOMAINS.openPending(p.getAttribute('data-pending'));
      if (d && window.GNL_DOMAINS) window.GNL_DOMAINS.openDelete(d.getAttribute('data-delete'));
    });
    if (search) { search.addEventListener('input', render); search.addEventListener('search', render); }

    document.querySelectorAll('[data-copy-ns]').forEach(function (b) {
      b.addEventListener('click', function () {
        var txt = b.getAttribute('data-copy-ns'), lbl = b.textContent;
        (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () {
          b.textContent = I18N.copied; setTimeout(function () { b.textContent = lbl; }, 1400);
        }).catch(function () {});
      });
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
