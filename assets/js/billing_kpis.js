/* =====================================================================
   assets/js/billing_kpis.js — cartes du haut des pages Facturation
   ---------------------------------------------------------------------
   Remplit les trois cartes posées par gnl_billing_head() (include/page_head.php) :
     [data-kpi="next"]    prochaine échéance (abonnements actifs)
     [data-kpi="due"]     reste à payer (somme des factures non soldées)
     [data-kpi="method"]  moyen de paiement (mandat Mollie d'un abonnement actif)
   Lecture seule, via data/portail_api.php : invoice.list, subscription.list,
   subscription.mandate. Une carte dont l'API échoue (droits, réseau) affiche « — ».
   ===================================================================== */
(function () {
  'use strict';

  var root = document.querySelector('[data-billing-kpis]');
  if (!root) return;

  var API = root.getAttribute('data-api') || '../data/portail_api.php';
  var L = {};
  try { L = JSON.parse(root.getAttribute('data-i18n') || '{}'); } catch (e) { L = {}; }
  function tr(k, d) { return L[k] || d; }

  function card(key) { return root.querySelector('[data-kpi="' + key + '"]'); }
  function set(key, value, note, noteClass) {
    var c = card(key); if (!c) return;
    var v = c.querySelector('.ec-kpi__value'), n = c.querySelector('.ec-kpi__note');
    if (v) v.textContent = value;
    if (n) {
      n.textContent = note || '';
      n.className = 'ec-kpi__note' + (noteClass ? ' ' + noteClass : '');
    }
  }

  function get(action, params) {
    var u = new URL(API, window.location.href);
    u.searchParams.set('action', action);
    Object.keys(params || {}).forEach(function (k) { u.searchParams.set(k, params[k]); });
    return fetch(u.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (d) { if (!d || !d.ok) throw new Error((d && d.error) || 'api'); return d; });
  }

  var money = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
  var dayFmt = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long' });
  var yearFmt = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });

  /* Reste à payer ------------------------------------------------------- */
  get('invoice.list').then(function (d) {
    var list = Array.isArray(d.invoices) ? d.invoices : [];
    var total = 0, open = 0;
    list.forEach(function (inv) {
      var r = typeof inv.remaining_raw === 'number' ? inv.remaining_raw : 0;
      if (r > 0.004) { total += r; open++; }
    });
    if (open === 0) set('due', money.format(0), tr('upToDate', 'Tout est à jour'), 'ec-kpi__note--ok');
    else set('due', money.format(total), open + ' ' + tr('openInvoices', 'facture(s) en attente'), 'ec-kpi__note--warn');
  }).catch(function () { set('due', '—', ''); });

  /* Prochaine échéance + moyen de paiement ----------------------------- */
  get('subscription.list').then(function (d) {
    var subs = Array.isArray(d.subscriptions) ? d.subscriptions : [];
    var now = Date.now() / 1000;
    var active = subs.filter(function (s) { return s.status === 'active' || s.status === 'pending'; });
    var upcoming = active.filter(function (s) { return s.end_ts && s.end_ts >= now - 86400; });

    if (!upcoming.length) {
      set('next', '—', active.length ? '' : tr('noSub', 'Aucun abonnement actif'));
    } else {
      var minTs = Math.min.apply(null, upcoming.map(function (s) { return s.end_ts; }));
      var sameDay = upcoming.filter(function (s) { return Math.abs(s.end_ts - minTs) < 86400; });
      var sum = 0, known = true;
      sameDay.forEach(function (s) { if (typeof s.amount_raw === 'number') sum += s.amount_raw; else known = false; });
      var date = new Date(minTs * 1000);
      var label = (date.getFullYear() === new Date().getFullYear() ? dayFmt : yearFmt).format(date);
      var note = (known && sum > 0 ? money.format(sum) + ' · ' : '')
        + active.length + ' ' + tr('activeSubs', 'abonnement(s) actif(s)');
      set('next', label, note);
    }

    var withMandate = active.filter(function (s) { return s.has_mandate; })[0];
    if (!withMandate) { set('method', tr('noMethod', 'Aucun enregistré'), ''); return; }
    return get('subscription.mandate', { id: withMandate.id }).then(function (m) {
      set('method', m.label || '—', '');
    });
  }).catch(function () { set('next', '—', ''); set('method', '—', ''); });
})();
