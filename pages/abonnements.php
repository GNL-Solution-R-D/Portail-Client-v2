<?php

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

// Droits (fonction de l'utilisateur dans son organisation Keycloak) :
// Abonnements. Sans le droit : page « Accès refusé » (403). Voir include/org_permissions.php.
require_once __DIR__ . '/../include/org_permissions.php';
orgRequirePage('orders.view');

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

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

// Barre de recherche du header (include/header.php) : activée pour cette page.
// Le champ porte l'id ci-dessous ; le JS en bas de page y branche le filtrage
// du tableau (les données proviennent de data/portail_api.php → API Mollie,
// pour le client Mollie de l'attribut Keycloak « moliecliid »).
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
    .subscriptions-state td{padding:1.5rem 1rem;text-align:center;color:var(--muted-foreground, #64748b);white-space:normal;}
    .subscriptions-state--error td{color:#b91c1c;}

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

    /* Actions par ligne + modales (mêmes styles que /equipes) */
    /* Plus compact depuis l'ajout des actions : en-têtes sur deux lignes si besoin. */
    .subscriptions-table{min-width:0;}
    .subscriptions-table th,.subscriptions-table td{padding:.8rem .6rem;}
    .subscriptions-table th{white-space:normal;vertical-align:bottom;}
    /* Colonne d'actions collée à droite : visible même si le tableau défile. */
    .subscriptions-table th.col-actions,.subscriptions-table td.col-actions{text-align:right;position:sticky;right:0;z-index:1;background:var(--background,#fff);box-shadow:-8px 0 8px -8px rgba(15,23,42,.18);}
    .subscriptions-table tbody tr:hover td.col-actions{background:var(--background,#fff);}
    .sub-actions{display:inline-flex;gap:.4rem;justify-content:flex-end;}
    .tm-btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;height:2.25rem;padding:0 .75rem;border:1px solid var(--border,#e2e8f0);border-radius:.375rem;font-size:.875rem;font-weight:500;background:var(--background,#fff);color:inherit;cursor:pointer;white-space:nowrap;transition:background .15s, opacity .15s;}
    .tm-btn:hover{background:var(--secondary,#f1f5f9);}
    .tm-btn:disabled{opacity:.5;cursor:not-allowed;}
    .tm-btn--primary{background:var(--primary,#0f172a);color:var(--primary-foreground,#fff);border-color:transparent;}
    .tm-btn--primary:hover{background:var(--primary,#0f172a);opacity:.9;}
    .tm-btn--danger{color:#b91c1c;}
    .tm-btn--danger.tm-btn--solid{background:#b91c1c;color:#fff;border-color:transparent;}
    .tm-btn--sm{height:1.9rem;padding:0 .5rem;font-size:.8rem;}
    .tm-modal{position:fixed;inset:0;z-index:60;display:none;align-items:center;justify-content:center;padding:1rem;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);}
    .tm-modal.is-open{display:flex;}
    .tm-dialog{width:100%;max-width:32rem;max-height:calc(100vh - 2rem);overflow:auto;border:1px solid var(--border,#e2e8f0);border-radius:.75rem;background:var(--card,#fff);color:var(--card-foreground,#0f172a);box-shadow:0 10px 30px rgba(0,0,0,.2);}
    .tm-field label{display:block;margin-bottom:.35rem;font-size:.75rem;font-weight:500;color:var(--muted-foreground,#64748b);}
    .tm-field select{height:2.5rem;width:100%;border:1px solid var(--border,#e2e8f0);border-radius:.375rem;background:var(--background,#fff);color:inherit;padding:0 .75rem;font-size:.875rem;}
    .tm-section{border-top:1px solid var(--border,#e2e8f0);padding-top:1rem;margin-top:1.25rem;}
    .tm-error{font-size:.8rem;color:#b91c1c;}
    .tm-hint{font-size:.75rem;color:var(--muted-foreground,#64748b);}
    .sub-notice{margin:0 1.5rem;padding:.75rem 1rem;border-radius:.5rem;font-size:.875rem;border:1px solid transparent;}
    .sub-notice--ok{background:rgba(22,163,74,.1);border-color:rgba(22,163,74,.3);color:#15803d;}
    .sub-notice--err{background:rgba(185,28,28,.08);border-color:rgba(185,28,28,.3);color:#b91c1c;}
    .sub-notice--info{background:rgba(148,163,184,.12);border-color:rgba(148,163,184,.35);}
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
        <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded border py-5 shadow-sm">
          <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-xl font-bold"><?= t('Mes abonnements') ?></h1>
              <p class="text-sm text-muted-foreground mt-1"><?= t('Suivi de vos abonnements.') ?></p>
            </div>
            <span id="subscriptionsCount" class="text-sm text-muted-foreground"
                  data-suffix="<?php echo h(t('abonnement(s)')); ?>"></span>
          </div>

          <div id="subscriptionsNotice" class="sub-notice" role="status" hidden></div>

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
                  <th class="col-actions"><span class="sr-only"><?= t('Actions') ?></span></th>
                </tr>
              </thead>
              <tbody id="subscriptionsTableBody">
                <tr class="subscriptions-state">
                  <td colspan="8"><?= t('Chargement des abonnements…') ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
        </main>

    </div>

  <!-- Modale : mettre à jour un abonnement (fréquence + moyen de paiement) -->
  <div id="subUpdateModal" class="tm-modal" role="dialog" aria-modal="true" aria-labelledby="subUpdateTitle">
    <div class="tm-dialog">
      <div class="p-6">
        <h2 id="subUpdateTitle" class="text-lg font-semibold"><?= t('Mettre à jour l\'abonnement') ?></h2>
        <p id="subUpdateSub" class="text-sm text-muted-foreground mt-1"></p>

        <div class="tm-field mt-5">
          <label for="subInterval"><?= t('Fréquence de paiement') ?></label>
          <select id="subInterval"></select>
          <p id="subIntervalPreview" class="tm-hint mt-2"></p>
          <p id="subIntervalLocked" class="tm-hint mt-2" hidden></p>
        </div>
        <div id="subIntervalError" class="tm-error mt-3" hidden></div>
        <div class="mt-4 flex justify-end">
          <button type="button" class="tm-btn tm-btn--primary" id="subIntervalSave"><?= t('Enregistrer la fréquence') ?></button>
        </div>

        <div class="tm-section">
          <div class="text-sm font-medium"><?= t('Moyen de paiement') ?></div>
          <p class="tm-hint mt-1"><?= t('Actuel :') ?> <span id="subMandateLabel">…</span></p>
          <p class="tm-hint mt-2"><?= t('Vous allez être redirigé vers Mollie pour enregistrer le nouveau moyen de paiement. Aucun montant n\'est débité.') ?></p>
          <div id="subPaymentError" class="tm-error mt-3" hidden></div>
          <div class="mt-4 flex justify-end">
            <button type="button" class="tm-btn" id="subPaymentStart"><?= t('Changer de moyen de paiement') ?></button>
          </div>
        </div>

        <div class="mt-6 flex justify-end">
          <button type="button" class="tm-btn" data-close><?= t('Fermer') ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modale : arrêter un abonnement -->
  <div id="subCancelModal" class="tm-modal" role="dialog" aria-modal="true" aria-labelledby="subCancelTitle">
    <div class="tm-dialog" style="max-width:28rem">
      <div class="p-6">
        <h2 id="subCancelTitle" class="text-lg font-semibold"><?= t('Arrêter l\'abonnement ?') ?></h2>
        <p id="subCancelText" class="text-sm text-muted-foreground mt-2"></p>
        <p class="text-sm mt-3"><?= t('Aucune échéance ne sera plus prélevée. Cette action est définitive : pour reprendre le service, il faudra souscrire un nouvel abonnement.') ?></p>
        <div id="subCancelError" class="tm-error mt-3" hidden></div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="tm-btn" data-close><?= t('Annuler') ?></button>
          <button type="button" class="tm-btn tm-btn--danger tm-btn--solid" id="subCancelOk"><?= t('Arrêter l\'abonnement') ?></button>
        </div>
      </div>
    </div>
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

  <!-- Données des abonnements via data/portail_api.php (→ API Mollie) + recherche du header -->
  <script>
    window.SUBSCRIPTIONS_API_URL = window.SUBSCRIPTIONS_API_URL || "../data/portail_api.php";
    window.SUBSCRIPTIONS_CSRF = <?= json_encode((string) ($_SESSION['csrf'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    window.SUBSCRIPTIONS_I18N = {
      loading:   <?= json_encode(t('Chargement des abonnements…'), JSON_UNESCAPED_UNICODE) ?>,
      empty:     <?= json_encode(t('Aucun abonnement trouvé pour le moment.'), JSON_UNESCAPED_UNICODE) ?>,
      notLinked: <?= json_encode(t('Aucun compte de paiement n\'est encore associé à votre organisation. Contactez le support si vous avez souscrit un abonnement.'), JSON_UNESCAPED_UNICODE) ?>,
      noResults: <?= json_encode(t('Aucun abonnement ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      error:     <?= json_encode(t('Impossible de charger les abonnements.'), JSON_UNESCAPED_UNICODE) ?>,
      update:    <?= json_encode(t('Mettre à jour'), JSON_UNESCAPED_UNICODE) ?>,
      stop:      <?= json_encode(t('Arrêter'), JSON_UNESCAPED_UNICODE) ?>,
      saving:    <?= json_encode(t('Enregistrement…'), JSON_UNESCAPED_UNICODE) ?>,
      redirecting: <?= json_encode(t('Redirection vers Mollie…'), JSON_UNESCAPED_UNICODE) ?>,
      newAmount: <?= json_encode(t('Nouveau montant par échéance :'), JSON_UNESCAPED_UNICODE) ?>,
      current:   <?= json_encode(t('(actuelle)'), JSON_UNESCAPED_UNICODE) ?>,
      none:      <?= json_encode(t('aucun'), JSON_UNESCAPED_UNICODE) ?>,
      intervalOk: <?= json_encode(t('La fréquence de l\'abonnement a été mise à jour.'), JSON_UNESCAPED_UNICODE) ?>,
      cancelOk:  <?= json_encode(t('L\'abonnement a été arrêté.'), JSON_UNESCAPED_UNICODE) ?>,
      pmChecking: <?= json_encode(t('Vérification du nouveau moyen de paiement…'), JSON_UNESCAPED_UNICODE) ?>,
      pmOk:      <?= json_encode(t('Moyen de paiement mis à jour :'), JSON_UNESCAPED_UNICODE) ?>,
      pmPending: <?= json_encode(t('Le moyen de paiement est en cours de validation chez Mollie. Rechargez la page dans quelques minutes.'), JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    var I18N = window.SUBSCRIPTIONS_I18N || {};
    var API  = window.SUBSCRIPTIONS_API_URL || "../data/portail_api.php";
    var CSRF = window.SUBSCRIPTIONS_CSRF || '';
    var COLS = 8;

    // Minuscules + suppression des accents pour une recherche tolérante.
    function norm(s) {
      return String(s == null ? '' : s).toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '');
    }
    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
      });
    }
    function money(v, cur) {
      if (v == null || isNaN(v)) return '—';
      var n = Number(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
      return n + ' ' + ((cur || 'EUR') === 'EUR' ? '€' : cur);
    }
    function monthsOf(interval) {
      var m = /^\s*(\d+)\s*months?\s*$/i.exec(String(interval || ''));
      return m ? parseInt(m[1], 10) : null;
    }

    function apiGet(action, params) {
      var q = '?action=' + encodeURIComponent(action);
      Object.keys(params || {}).forEach(function (k) { q += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); });
      return fetch(API + q, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }).then(readJson);
    }
    function apiPost(action, params) {
      return fetch(API + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
        body: new URLSearchParams(params || {})
      }).then(readJson);
    }
    function readJson(res) {
      return res.json().catch(function () { return null; }).then(function (data) {
        data = data || {};
        if (!res.ok && data.ok !== false) data.ok = false;
        if (!res.ok && !data.error) data.error = (I18N.error || 'Erreur') + ' (HTTP ' + res.status + ')';
        return data;
      });
    }

    ready(function () {
      var input   = document.getElementById('subscriptionsSearchInput');
      var tbody   = document.getElementById('subscriptionsTableBody');
      var counter = document.getElementById('subscriptionsCount');
      var notice  = document.getElementById('subscriptionsNotice');
      if (!tbody) return;

      var state = { list: [], byId: {}, canManage: false, choices: [], linked: true };
      var suffix = counter ? (counter.getAttribute('data-suffix') || '') : '';

      function setCounter(n) {
        if (!counter) return;
        counter.textContent = (n == null) ? '' : (n + (suffix ? ' ' + suffix : ''));
      }
      function showNotice(text, kind) {
        if (!notice) return;
        notice.className = 'sub-notice sub-notice--' + (kind || 'info');
        notice.textContent = text || '';
        notice.hidden = !text;
      }
      function stateRow(text, isError) {
        return '<tr class="subscriptions-state' + (isError ? ' subscriptions-state--error' : '') +
               '"><td colspan="' + COLS + '">' + esc(text) + '</td></tr>';
      }
      function actionsHtml(s) {
        if (!state.canManage || !s.actions) return '';
        var a = s.actions, html = '';
        if (a.can_change_interval || a.can_change_payment) {
          html += '<button type="button" class="tm-btn tm-btn--sm" data-act="update" data-id="' + esc(s.id) + '">' + esc(I18N.update) + '</button>';
        }
        if (a.can_cancel) {
          html += '<button type="button" class="tm-btn tm-btn--sm tm-btn--danger" data-act="cancel" data-id="' + esc(s.id) + '">' + esc(I18N.stop) + '</button>';
        }
        return html ? '<div class="sub-actions">' + html + '</div>' : '';
      }
      function rowHtml(s) {
        var hay = [s.ref, s.label, s.start, s.end, s.frequency, s.amount, s.status_label]
          .join(' ').toLowerCase();
        return '<tr data-search="' + esc(hay) + '" data-id="' + esc(s.id) + '">' +
          '<td class="font-medium">' + esc(s.ref) + '</td>' +
          '<td>' + esc(s.label) + '</td>' +
          '<td>' + esc(s.start) + '</td>' +
          '<td>' + esc(s.end) + '</td>' +
          '<td>' + esc(s.frequency) + '</td>' +
          '<td>' + esc(s.amount) + '</td>' +
          '<td><span class="badge ' + esc(s.status_class) + '">' + esc(s.status_label) + '</span></td>' +
          '<td class="col-actions">' + actionsHtml(s) + '</td>' +
        '</tr>';
      }

      function dataRows() {
        return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search]'));
      }
      function applyFilter() {
        var rows = dataRows();
        if (!rows.length) return;
        var q = input ? norm(input.value.trim()) : '';
        var tokens = q ? q.split(/\s+/) : [];
        var visible = 0;
        rows.forEach(function (row) {
          var hay = norm(row.getAttribute('data-search') || '');
          var match = tokens.every(function (t) { return hay.indexOf(t) !== -1; });
          row.hidden = !match;
          if (match) visible++;
        });
        var noRes = document.getElementById('subscriptionsNoResults');
        if (noRes) noRes.hidden = (visible !== 0);
        setCounter(visible);
      }

      function renderRows() {
        var list = state.list;
        state.byId = {};
        list.forEach(function (s) { state.byId[s.id] = s; });
        if (!list.length) {
          tbody.innerHTML = stateRow((state.linked === false ? I18N.notLinked : I18N.empty) || 'Aucun abonnement.', false);
          setCounter(0);
          return;
        }
        tbody.innerHTML = list.map(rowHtml).join('') +
          '<tr id="subscriptionsNoResults" class="subscriptions-state" hidden><td colspan="' + COLS + '">' +
          esc(I18N.noResults || '') + '</td></tr>';
        setCounter(list.length);
        applyFilter();
      }

      function load() {
        tbody.innerHTML = stateRow(I18N.loading || 'Chargement…', false);
        setCounter(null);
        return apiGet('subscription.list').then(function (data) {
          if (!data.ok) {
            var code = data.code ? ' (code: ' + data.code + ')' : '';
            tbody.innerHTML = stateRow((I18N.error || 'Erreur') + code + ' ' + (data.error || ''), true);
            setCounter(null);
            return;
          }
          state.list = Array.isArray(data.subscriptions) ? data.subscriptions : [];
          state.linked = data.linked;
          state.canManage = !!data.can_manage;
          state.choices = Array.isArray(data.interval_choices) ? data.interval_choices : [];
          renderRows();
        }).catch(function () {
          tbody.innerHTML = stateRow(I18N.error || 'Impossible de charger les abonnements.', true);
          setCounter(null);
        });
      }

      // ── Modales ─────────────────────────────────────────────────────────
      function openModal(el) { el.classList.add('is-open'); }
      function closeModal(el) { el.classList.remove('is-open'); }
      function setErr(el, msg) { el.textContent = msg || ''; el.hidden = !msg; }
      function busy(btn, on, label) {
        btn.disabled = !!on;
        if (on) { btn.dataset.label = btn.textContent; btn.textContent = label || I18N.saving || '…'; }
        else if (btn.dataset.label) { btn.textContent = btn.dataset.label; }
      }
      ['subUpdateModal', 'subCancelModal'].forEach(function (id) {
        var el = document.getElementById(id);
        el.addEventListener('click', function (e) { if (e.target === el || e.target.closest('[data-close]')) closeModal(el); });
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.tm-modal.is-open').forEach(closeModal);
      });

      var um = {
        el: document.getElementById('subUpdateModal'),
        sub: document.getElementById('subUpdateSub'),
        select: document.getElementById('subInterval'),
        preview: document.getElementById('subIntervalPreview'),
        locked: document.getElementById('subIntervalLocked'),
        ierr: document.getElementById('subIntervalError'),
        isave: document.getElementById('subIntervalSave'),
        mandate: document.getElementById('subMandateLabel'),
        perr: document.getElementById('subPaymentError'),
        pstart: document.getElementById('subPaymentStart'),
        id: ''
      };

      function refreshPreview() {
        var s = state.byId[um.id]; if (!s) return;
        var cur = monthsOf(s.interval), next = monthsOf(um.select.value);
        if (!cur || !next || cur === next || s.amount_raw == null) { um.preview.textContent = ''; return; }
        // Indicatif : le montant réel est recalculé par le serveur.
        var cents = Math.round(Math.round(s.amount_raw * 100) * next / cur);
        um.preview.textContent = (I18N.newAmount || '') + ' ' + money(cents / 100, s.currency);
      }
      um.select.addEventListener('change', refreshPreview);

      function openUpdate(id) {
        var s = state.byId[id]; if (!s) return;
        um.id = id;
        um.sub.textContent = (s.label && s.label !== '—' ? s.label + ' · ' : '') + s.amount + ' · ' + s.frequency;
        setErr(um.ierr, ''); setErr(um.perr, '');

        var a = s.actions || {}, cur = monthsOf(s.interval);
        um.select.innerHTML = state.choices.map(function (c) {
          var isCur = cur === c.months;
          return '<option value="' + esc(c.value) + '"' + (isCur ? ' selected' : '') + '>' +
                 esc(c.label) + (isCur ? ' ' + esc(I18N.current || '') : '') + '</option>';
        }).join('');
        um.select.disabled = !a.can_change_interval;
        um.isave.disabled = !a.can_change_interval;
        um.locked.textContent = a.interval_reason || '';
        um.locked.hidden = !!a.can_change_interval || !a.interval_reason;
        refreshPreview();

        um.pstart.disabled = !a.can_change_payment;
        um.mandate.textContent = '…';
        apiGet('subscription.mandate', { id: id }).then(function (d) {
          if (um.id !== id) return;
          um.mandate.textContent = (d.ok && d.label) ? d.label : (I18N.none || '—');
        }).catch(function () { um.mandate.textContent = '—'; });

        openModal(um.el);
      }

      um.isave.addEventListener('click', function () {
        setErr(um.ierr, '');
        busy(um.isave, true);
        apiPost('subscription.update_interval', { id: um.id, interval: um.select.value }).then(function (d) {
          busy(um.isave, false);
          if (!d.ok) { setErr(um.ierr, d.error || I18N.error); return; }
          closeModal(um.el);
          if (!d.unchanged) showNotice(I18N.intervalOk, 'ok');
          load();
        }).catch(function () { busy(um.isave, false); setErr(um.ierr, I18N.error); });
      });

      um.pstart.addEventListener('click', function () {
        setErr(um.perr, '');
        busy(um.pstart, true, I18N.redirecting);
        apiPost('subscription.payment.start', { id: um.id }).then(function (d) {
          if (!d.ok || !d.checkout_url) { busy(um.pstart, false); setErr(um.perr, d.error || I18N.error); return; }
          window.location.href = d.checkout_url;
        }).catch(function () { busy(um.pstart, false); setErr(um.perr, I18N.error); });
      });

      var cm = {
        el: document.getElementById('subCancelModal'),
        text: document.getElementById('subCancelText'),
        err: document.getElementById('subCancelError'),
        ok: document.getElementById('subCancelOk'),
        id: ''
      };
      function openCancel(id) {
        var s = state.byId[id]; if (!s) return;
        cm.id = id;
        cm.text.textContent = (s.label && s.label !== '—' ? s.label + ' — ' : '') + s.ref + ' · ' + s.amount + ' · ' + s.frequency;
        setErr(cm.err, '');
        openModal(cm.el);
      }
      cm.ok.addEventListener('click', function () {
        setErr(cm.err, '');
        busy(cm.ok, true);
        apiPost('subscription.cancel', { id: cm.id }).then(function (d) {
          busy(cm.ok, false);
          if (!d.ok) { setErr(cm.err, d.error || I18N.error); return; }
          closeModal(cm.el);
          showNotice(I18N.cancelOk, 'ok');
          load();
        }).catch(function () { busy(cm.ok, false); setErr(cm.err, I18N.error); });
      });

      tbody.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn) return;
        if (btn.getAttribute('data-act') === 'update') openUpdate(btn.getAttribute('data-id'));
        else if (btn.getAttribute('data-act') === 'cancel') openCancel(btn.getAttribute('data-id'));
      });

      // ── Retour de la page de paiement Mollie (?mollie_pm=…) ────────────
      function finishPayment(token, attempt) {
        apiPost('subscription.payment.finish', { token: token }).then(function (d) {
          if (d.ok && d.state === 'done') {
            showNotice((I18N.pmOk || '') + ' ' + (d.label || ''), 'ok');
            load();
          } else if (d.ok && d.state === 'pending') {
            if (attempt < 5) setTimeout(function () { finishPayment(token, attempt + 1); }, 2000);
            else showNotice(I18N.pmPending, 'info');
          } else {
            showNotice(d.error || I18N.error, 'err');
          }
        }).catch(function () { showNotice(I18N.error, 'err'); });
      }
      (function () {
        var params = new URLSearchParams(window.location.search);
        var token = params.get('mollie_pm');
        if (!token) return;
        params.delete('mollie_pm');
        var qs = params.toString();
        try { history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash); } catch (e) {}
        showNotice(I18N.pmChecking, 'info');
        finishPayment(token, 0);
      })();

      if (input) {
        input.addEventListener('input', applyFilter);
        input.addEventListener('search', applyFilter); // croix « effacer » du type=search
      }

      load();
    });
  })();
  </script>

  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../assets/js/services_menu.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/services_menu.js') ?>" defer></script>
</body>
</html>