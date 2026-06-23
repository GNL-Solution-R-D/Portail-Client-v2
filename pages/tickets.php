<?php

declare(strict_types=1);

/**
 * pages/tickets.php
 *
 * Création et suivi des tickets de support de l'utilisateur courant.
 *
 * - Le navigateur ne parle JAMAIS à n8n : il appelle data/portail_api.php
 *   (actions "ticket.*") qui injecte le client_id depuis la session.
 * - Token CSRF : $_SESSION['csrf'] (le MÊME que celui vérifié par le proxy
 *   via l'en-tête X-CSRF-Token), aligné sur network.php / stockage.php.
 */

// Cookie de session valable sur /pages/* ET /data/*
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: /connexion');
    exit;
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit;
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

// CSRF token (identique à celui attendu par data/portail_api.php).
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf'];

$pageTitle = 'Mes tickets - GNL Solution';
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css" />
  <style>
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height,0px));min-height:calc(100dvh - var(--app-header-height,0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}
    @media (max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto!important;}
      .dashboard-main{padding:1rem;}
    }

    /* Boutons */
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;height:38px;border-radius:.6rem;border:1px solid var(--border);padding:0 .9rem;font-size:.875rem;font-weight:500;cursor:pointer;transition:background .15s ease,opacity .15s ease;background:var(--background);color:inherit;}
    .btn:hover{background:var(--secondary);}
    .btn:disabled{opacity:.55;cursor:not-allowed;}
    .btn-primary{background:var(--primary);color:var(--primary-foreground);border-color:transparent;}
    .btn-primary:hover{background:var(--primary);opacity:.9;}
    .btn-ghost{border-color:transparent;background:transparent;}
    .btn-ghost:hover{background:var(--secondary);}
    .btn-sm{height:32px;padding:0 .65rem;font-size:.8rem;border-radius:.5rem;}
    .icon{width:1rem;height:1rem;flex:0 0 1rem;display:block;}

    /* Stat chips */
    .chips{display:flex;flex-wrap:wrap;gap:.6rem;}
    .chip{display:inline-flex;align-items:center;gap:.4rem;border:1px solid var(--border);border-radius:999px;padding:.25rem .7rem;font-size:.8rem;background:var(--background);}
    .chip b{font-weight:700;}

    /* Filtres */
    .filterbar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between;}
    .tabs{display:flex;flex-wrap:wrap;gap:.3rem;}
    .tab{border:1px solid var(--border);border-radius:999px;padding:.32rem .8rem;font-size:.8rem;cursor:pointer;background:var(--background);transition:all .15s ease;}
    .tab[aria-selected="true"]{background:var(--primary);color:var(--primary-foreground);border-color:transparent;}
    .search{height:36px;border-radius:.6rem;border:1px solid var(--border);background:var(--background);padding:0 .7rem;font-size:.85rem;min-width:200px;}

    /* Table */
    .tickets-table-wrap{overflow-x:auto;}
    .tickets-table{width:100%;border-collapse:separate;border-spacing:0;min-width:880px;}
    .tickets-table th,.tickets-table td{padding:.85rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.9rem;text-align:left;vertical-align:middle;}
    .tickets-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.7rem;color:var(--muted-foreground,#64748b);white-space:nowrap;}
    .tickets-table tbody tr{cursor:pointer;}
    .tickets-table tbody tr:hover{background:rgba(148,163,184,.08);}
    .tickets-table .ref{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.82rem;color:var(--muted-foreground,#64748b);white-space:nowrap;}
    .tickets-table .subj{font-weight:600;}

    .badge{display:inline-flex;align-items:center;justify-content:center;border-radius:.5rem;padding:.18rem .55rem;font-size:.74rem;font-weight:600;white-space:nowrap;}

    .state-msg{padding:1.25rem 1.5rem;font-size:.9rem;color:var(--muted-foreground,#64748b);}
    .state-error{margin:0 1.5rem;border-radius:.6rem;border:1px solid rgba(248,113,113,.4);background:rgba(248,113,113,.08);padding:.8rem 1rem;font-size:.875rem;color:#b91c1c;}

    /* Modal */
    .modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:flex-start;justify-content:center;padding:5vh 1rem;z-index:120;overflow-y:auto;}
    .modal-overlay.is-open{display:flex;}
    .modal{background:var(--card);color:var(--card-foreground);width:100%;max-width:640px;border-radius:.9rem;border:1px solid var(--border);box-shadow:0 20px 60px rgba(0,0,0,.25);}
    .modal.modal-lg{max-width:760px;}
    .modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding:1.1rem 1.25rem;border-bottom:1px solid var(--border);}
    .modal-body{padding:1.1rem 1.25rem;}
    .modal-foot{display:flex;gap:.6rem;justify-content:flex-end;padding:1rem 1.25rem;border-top:1px solid var(--border);flex-wrap:wrap;}

    .field{display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem;}
    .field label{font-size:.82rem;font-weight:600;}
    .field input,.field select,.field textarea{width:100%;border-radius:.6rem;border:1px solid var(--border);background:var(--background);padding:.55rem .7rem;font-size:.9rem;color:inherit;font-family:inherit;}
    .field textarea{min-height:130px;resize:vertical;}
    .field .hint{font-size:.74rem;color:var(--muted-foreground,#64748b);}
    .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
    @media(max-width:560px){.grid-2{grid-template-columns:1fr;}}

    /* Fil de discussion */
    .thread{display:flex;flex-direction:column;gap:.75rem;max-height:46vh;overflow-y:auto;padding-right:.25rem;}
    .msg{border:1px solid var(--border);border-radius:.7rem;padding:.7rem .85rem;background:var(--background);}
    .msg.support{background:rgba(59,130,246,.07);border-color:rgba(59,130,246,.3);}
    .msg-meta{display:flex;justify-content:space-between;gap:.5rem;font-size:.74rem;color:var(--muted-foreground,#64748b);margin-bottom:.3rem;}
    .msg-author{font-weight:700;color:inherit;}
    .msg-body{font-size:.88rem;white-space:pre-wrap;word-break:break-word;}

    .meta-row{display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;font-size:.82rem;color:var(--muted-foreground,#64748b);margin-bottom:1rem;}
    .meta-row b{color:inherit;}

    .form-msg{font-size:.82rem;margin-top:.4rem;}
    .form-msg.err{color:#b91c1c;}
    .form-msg.ok{color:#047857;}

    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6 space-y-6">

        <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-5 shadow-sm">

          <!-- En-tête -->
          <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-xl font-bold">Mes tickets</h1>
              <p class="text-sm text-muted-foreground mt-1">Ouvrez une demande d’assistance et suivez son traitement par notre support.</p>
            </div>
            <button id="btn-new" type="button" class="btn btn-primary">
              <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
              Nouveau ticket
            </button>
          </div>

          <!-- Chips de synthèse -->
          <div class="px-6">
            <div class="chips" id="stat-chips" hidden>
              <span class="chip">Total <b id="c-total">0</b></span>
              <span class="chip">Ouverts <b id="c-ouvert">0</b></span>
              <span class="chip">En cours <b id="c-en_cours">0</b></span>
              <span class="chip">En attente <b id="c-en_attente">0</b></span>
              <span class="chip">Résolus <b id="c-resolu">0</b></span>
            </div>
          </div>

          <!-- Filtres -->
          <div class="px-6">
            <div class="filterbar">
              <div class="tabs" id="status-tabs" role="tablist">
                <button class="tab" role="tab" data-status="all" aria-selected="true">Tous</button>
                <button class="tab" role="tab" data-status="ouvert" aria-selected="false">Ouverts</button>
                <button class="tab" role="tab" data-status="en_cours" aria-selected="false">En cours</button>
                <button class="tab" role="tab" data-status="en_attente" aria-selected="false">En attente</button>
                <button class="tab" role="tab" data-status="resolu" aria-selected="false">Résolus</button>
                <button class="tab" role="tab" data-status="ferme" aria-selected="false">Fermés</button>
              </div>
              <input id="search" type="search" class="search" placeholder="Rechercher (objet, référence…)" />
            </div>
          </div>

          <!-- Tableau -->
          <div id="error-box" class="state-error" hidden></div>

          <div class="tickets-table-wrap px-2 md:px-6">
            <table class="tickets-table">
              <thead>
                <tr>
                  <th>Référence</th>
                  <th>Objet</th>
                  <th>Catégorie</th>
                  <th>Priorité</th>
                  <th>Statut</th>
                  <th>Créé le</th>
                  <th>Maj</th>
                </tr>
              </thead>
              <tbody id="tickets-body">
                <tr><td colspan="7" class="state-msg" id="loading-row">Chargement des tickets…</td></tr>
              </tbody>
            </table>
          </div>

          <div class="state-msg" id="empty-row" hidden>Aucun ticket ne correspond à ce filtre.</div>

        </div>
      </div>
    </main>
  </div>

  <!-- ═══════════════════ MODALE : NOUVEAU TICKET ═══════════════════ -->
  <div class="modal-overlay" id="modal-new" role="dialog" aria-modal="true" aria-labelledby="modal-new-title">
    <div class="modal">
      <div class="modal-head">
        <div>
          <h2 class="text-lg font-semibold" id="modal-new-title">Nouveau ticket</h2>
          <p class="text-sm text-muted-foreground">Décrivez votre demande, notre support vous répondra rapidement.</p>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" data-close aria-label="Fermer">✕</button>
      </div>
      <div class="modal-body">
        <div class="field">
          <label for="t-subject">Objet</label>
          <input id="t-subject" type="text" maxlength="150" placeholder="Ex. : Problème d’accès à mon service" />
        </div>
        <div class="grid-2">
          <div class="field">
            <label for="t-category">Catégorie</label>
            <select id="t-category">
              <option value="technique">Technique</option>
              <option value="facturation">Facturation</option>
              <option value="commercial">Commercial</option>
              <option value="compte">Compte</option>
              <option value="autre">Autre</option>
            </select>
          </div>
          <div class="field">
            <label for="t-priority">Priorité</label>
            <select id="t-priority">
              <option value="basse">Basse</option>
              <option value="normale" selected>Normale</option>
              <option value="haute">Haute</option>
              <option value="urgente">Urgente</option>
            </select>
          </div>
        </div>
        <div class="field" style="margin-bottom:.25rem;">
          <label for="t-message">Message</label>
          <textarea id="t-message" maxlength="5000" placeholder="Décrivez le contexte, les étapes pour reproduire le problème, les messages d’erreur…"></textarea>
          <span class="hint">Entre 5 et 5000 caractères.</span>
        </div>
        <div class="form-msg" id="new-msg" hidden></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" data-close>Annuler</button>
        <button type="button" class="btn btn-primary" id="btn-create">Envoyer le ticket</button>
      </div>
    </div>
  </div>

  <!-- ═══════════════════ MODALE : DÉTAIL TICKET ═══════════════════ -->
  <div class="modal-overlay" id="modal-detail" role="dialog" aria-modal="true" aria-labelledby="modal-detail-title">
    <div class="modal modal-lg">
      <div class="modal-head">
        <div style="min-width:0;">
          <h2 class="text-lg font-semibold" id="modal-detail-title">Ticket</h2>
          <span class="ref" id="d-ref"></span>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" data-close aria-label="Fermer">✕</button>
      </div>
      <div class="modal-body">
        <div class="meta-row" id="d-meta"></div>
        <div class="thread" id="d-thread"></div>

        <div id="d-reply-zone" style="margin-top:1rem;">
          <div class="field" style="margin-bottom:.4rem;">
            <label for="d-reply" class="sr-only">Votre réponse</label>
            <textarea id="d-reply" maxlength="5000" placeholder="Répondre au support…" style="min-height:90px;"></textarea>
          </div>
          <div class="form-msg" id="detail-msg" hidden></div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" id="btn-toggle-close"></button>
        <button type="button" class="btn btn-primary" id="btn-reply">Envoyer la réponse</button>
      </div>
    </div>
  </div>

  <script>
  (function () {
    'use strict';

    const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const API = new URL('../data/portail_api.php', window.location.href);

    // ── État ───────────────────────────────────────────────────────────────
    let tickets = [];
    let filterStatus = 'all';
    let searchTerm = '';
    let current = null; // ticket ouvert dans la modale détail

    // ── Utilitaires DOM ──────────────────────────────────────────────────────
    const $ = (sel, root) => (root || document).querySelector(sel);
    const esc = (s) => String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    function setFormMsg(el, text, kind) {
      if (!el) return;
      if (!text) { el.hidden = true; el.textContent = ''; return; }
      el.hidden = false;
      el.textContent = text;
      el.className = 'form-msg' + (kind ? ' ' + kind : '');
    }

    function showError(text) {
      const box = $('#error-box');
      if (!text) { box.hidden = true; box.textContent = ''; return; }
      box.hidden = false;
      box.textContent = text;
    }

    // ── Appels API (mêmes conventions que network.php / stockage.php) ─────────
    async function apiGet(action, params) {
      const u = new URL(API.toString());
      u.searchParams.set('action', action);
      Object.entries(params || {}).forEach(([k, v]) => u.searchParams.set(k, v));
      const res = await fetch(u.toString(), { credentials: 'same-origin' });
      return readJson(res);
    }

    async function apiPost(action, fields) {
      const u = new URL(API.toString());
      u.searchParams.set('action', action);
      const res = await fetch(u.toString(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
        body: new URLSearchParams(fields || {}),
      });
      return readJson(res);
    }

    async function readJson(res) {
      const ct = (res.headers.get('content-type') || '').toLowerCase();
      const raw = await res.text();
      let data = null;
      try { data = JSON.parse(raw); } catch (_) { /* noop */ }
      if (!ct.includes('application/json') || !data) {
        throw new Error('Réponse inattendue (' + res.status + '). ' + raw.slice(0, 160).replace(/\s+/g, ' '));
      }
      if (!res.ok || !data.ok) {
        throw new Error(data.error || ('HTTP ' + res.status));
      }
      return data;
    }

    // ── Chargement de la liste ────────────────────────────────────────────────
    async function load() {
      showError('');
      $('#loading-row') && ($('#loading-row').textContent = 'Chargement des tickets…');
      try {
        const data = await apiGet('ticket.list');
        tickets = Array.isArray(data.tickets) ? data.tickets : [];
        renderChips();
        render();
      } catch (e) {
        tickets = [];
        $('#tickets-body').innerHTML = '';
        $('#empty-row').hidden = true;
        showError('Impossible de charger les tickets : ' + (e && e.message ? e.message : e));
      }
    }

    function renderChips() {
      const count = (k) => tickets.filter((t) => t.status === k).length;
      $('#c-total').textContent = tickets.length;
      $('#c-ouvert').textContent = count('ouvert');
      $('#c-en_cours').textContent = count('en_cours');
      $('#c-en_attente').textContent = count('en_attente');
      $('#c-resolu').textContent = count('resolu');
      $('#stat-chips').hidden = tickets.length === 0;
    }

    function applyFilters() {
      return tickets.filter((t) => {
        if (filterStatus !== 'all' && t.status !== filterStatus) return false;
        if (searchTerm) {
          const hay = (t.ref + ' ' + t.subject + ' ' + t.category + ' ' + (t.message || '')).toLowerCase();
          if (!hay.includes(searchTerm)) return false;
        }
        return true;
      });
    }

    function render() {
      const body = $('#tickets-body');
      const rows = applyFilters();
      if (!tickets.length) {
        body.innerHTML = '<tr><td colspan="7" class="state-msg">Vous n’avez pas encore de ticket. Cliquez sur « Nouveau ticket » pour en créer un.</td></tr>';
        $('#empty-row').hidden = true;
        return;
      }
      if (!rows.length) {
        body.innerHTML = '';
        $('#empty-row').hidden = false;
        return;
      }
      $('#empty-row').hidden = true;
      body.innerHTML = rows.map((t) => `
        <tr data-id="${esc(t.id)}">
          <td class="ref">${esc(t.ref)}</td>
          <td class="subj">${esc(t.subject)}</td>
          <td>${esc(t.category)}</td>
          <td><span class="badge ${esc(t.priority_class)}">${esc(t.priority_label)}</span></td>
          <td><span class="badge ${esc(t.status_class)}">${esc(t.status_label)}</span></td>
          <td>${esc(t.created_at)}</td>
          <td>${esc(t.updated_at)}</td>
        </tr>`).join('');

      body.querySelectorAll('tr[data-id]').forEach((tr) => {
        tr.addEventListener('click', () => openDetail(tr.getAttribute('data-id')));
      });
    }

    // ── Filtres UI ────────────────────────────────────────────────────────────
    $('#status-tabs').addEventListener('click', (ev) => {
      const btn = ev.target.closest('.tab');
      if (!btn) return;
      filterStatus = btn.getAttribute('data-status');
      $('#status-tabs').querySelectorAll('.tab').forEach((b) =>
        b.setAttribute('aria-selected', b === btn ? 'true' : 'false'));
      render();
    });
    $('#search').addEventListener('input', (ev) => {
      searchTerm = (ev.target.value || '').trim().toLowerCase();
      render();
    });

    // ── Gestion des modales ─────────────────────────────────────────────────
    function openModal(id) { $('#' + id).classList.add('is-open'); document.body.style.overflow = 'hidden'; }
    function closeModal(el) { el.classList.remove('is-open'); document.body.style.overflow = ''; }
    document.querySelectorAll('.modal-overlay').forEach((ov) => {
      ov.addEventListener('click', (e) => { if (e.target === ov) closeModal(ov); });
      ov.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => closeModal(ov)));
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.is-open').forEach(closeModal);
    });

    // ── Création d'un ticket ──────────────────────────────────────────────────
    $('#btn-new').addEventListener('click', () => {
      $('#t-subject').value = '';
      $('#t-category').value = 'technique';
      $('#t-priority').value = 'normale';
      $('#t-message').value = '';
      setFormMsg($('#new-msg'), '');
      openModal('modal-new');
      $('#t-subject').focus();
    });

    $('#btn-create').addEventListener('click', async () => {
      const subject = $('#t-subject').value.trim();
      const message = $('#t-message').value.trim();
      if (subject.length < 3) { setFormMsg($('#new-msg'), 'L’objet doit contenir au moins 3 caractères.', 'err'); return; }
      if (message.length < 5) { setFormMsg($('#new-msg'), 'Le message doit contenir au moins 5 caractères.', 'err'); return; }

      const btn = $('#btn-create');
      btn.disabled = true;
      setFormMsg($('#new-msg'), 'Envoi en cours…');
      try {
        await apiPost('ticket.create', {
          subject,
          message,
          category: $('#t-category').value,
          priority: $('#t-priority').value,
        });
        closeModal($('#modal-new'));
        await load();
      } catch (e) {
        setFormMsg($('#new-msg'), 'Erreur : ' + (e && e.message ? e.message : e), 'err');
      } finally {
        btn.disabled = false;
      }
    });

    // ── Détail d'un ticket ────────────────────────────────────────────────────
    const findTicket = (id) => tickets.find((t) => String(t.id) === String(id)) || null;

    async function openDetail(id) {
      openModal('modal-detail');
      $('#d-reply').value = '';
      setFormMsg($('#detail-msg'), '');

      // 1) On part des données FIABLES déjà chargées par ticket.list
      //    (statut, objet, référence, priorité, dates…). C'est la source de
      //    vérité pour les métadonnées : ticket.detail ne sert qu'à la conversation.
      const base = findTicket(id);
      current = base ? Object.assign({}, base) : { id: id };
      renderDetail();

      // 2) On complète avec le détail : conversation + message initial complet.
      try {
        const data = await apiGet('ticket.detail', { id });
        const det = data && data.ticket ? data.ticket : null;
        if (det) {
          if (base) {
            if (det.message) current.message = det.message;
            current.messages = Array.isArray(det.messages) ? det.messages : (current.messages || []);
          } else {
            current = det; // pas d'entrée liste (ex. lien direct) → on prend le détail tel quel
          }
          renderDetail();
        }
      } catch (e) {
        // Non bloquant : les métadonnées issues de la liste restent affichées.
        setFormMsg($('#detail-msg'), 'Conversation indisponible : ' + (e && e.message ? e.message : e), 'err');
      }
    }

    function renderDetail() {
      if (!current) return;
      const t = current;
      $('#modal-detail-title').textContent = t.subject || 'Ticket';
      $('#d-ref').textContent = t.ref || '';
      $('#d-meta').innerHTML =
        `<span><b>Catégorie :</b> ${esc(t.category || '—')}</span>` +
        `<span><b>Priorité :</b> <span class="badge ${esc(t.priority_class || '')}">${esc(t.priority_label || '—')}</span></span>` +
        `<span><b>Statut :</b> <span class="badge ${esc(t.status_class || '')}">${esc(t.status_label || '—')}</span></span>` +
        `<span><b>Créé le :</b> ${esc(t.created_at || '—')}</span>`;

      const msgs = Array.isArray(t.messages) ? t.messages : [];
      const thread = [];
      // Le message initial du ticket est affiché comme première bulle « client ».
      if (t.message) {
        thread.push(bubble({ author: 'Vous', author_type: 'client', body: t.message, created_at: t.created_full || t.created_at }));
      }
      msgs.forEach((m) => thread.push(bubble(m)));
      $('#d-thread').innerHTML = thread.join('') ||
        '<div class="state-msg" style="padding:0;">Aucun message.</div>';
      const thr = $('#d-thread');
      thr.scrollTop = thr.scrollHeight;

      const isClosed = (t.status === 'ferme');
      $('#d-reply-zone').style.display = isClosed ? 'none' : '';
      $('#btn-reply').style.display = isClosed ? 'none' : '';
      $('#btn-toggle-close').textContent = isClosed ? 'Rouvrir le ticket' : 'Clôturer le ticket';
      $('#btn-toggle-close').dataset.reopen = isClosed ? '1' : '0';
    }

    function bubble(m) {
      const support = m.author_type === 'support';
      return `<div class="msg ${support ? 'support' : ''}">
        <div class="msg-meta"><span class="msg-author">${esc(m.author)}</span><span>${esc(m.created_at)}</span></div>
        <div class="msg-body">${esc(m.body)}</div>
      </div>`;
    }

    $('#btn-reply').addEventListener('click', async () => {
      if (!current) return;
      const id = current.id;
      const body = $('#d-reply').value.trim();
      if (!body) { setFormMsg($('#detail-msg'), 'Saisissez un message.', 'err'); return; }
      const btn = $('#btn-reply');
      btn.disabled = true;
      setFormMsg($('#detail-msg'), 'Envoi…');
      try {
        await apiPost('ticket.reply', { ticket_id: id, body });
        await load();            // met à jour la liste (Maj, statut éventuel)
        await openDetail(id);    // ré-amorce depuis la liste fraîche + recharge le fil
        setFormMsg($('#detail-msg'), 'Réponse envoyée.', 'ok');
      } catch (e) {
        setFormMsg($('#detail-msg'), 'Erreur : ' + (e && e.message ? e.message : e), 'err');
      } finally {
        btn.disabled = false;
      }
    });

    $('#btn-toggle-close').addEventListener('click', async () => {
      if (!current) return;
      const id = current.id;
      const reopen = $('#btn-toggle-close').dataset.reopen === '1';
      const btn = $('#btn-toggle-close');
      btn.disabled = true;
      setFormMsg($('#detail-msg'), reopen ? 'Réouverture…' : 'Clôture…');
      try {
        await apiPost('ticket.close', { ticket_id: id, reopen: reopen ? '1' : '0' });
        await load();            // la liste reflète le nouveau statut…
        await openDetail(id);    // …et la modale se ré-amorce dessus → statut à jour
        setFormMsg($('#detail-msg'), reopen ? 'Ticket rouvert.' : 'Ticket clôturé.', 'ok');
      } catch (e) {
        setFormMsg($('#detail-msg'), 'Erreur : ' + (e && e.message ? e.message : e), 'err');
      } finally {
        btn.disabled = false;
      }
    });

    // ── Go ────────────────────────────────────────────────────────────────────
    load();
  })();
  </script>
</body>
</html>