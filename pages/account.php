<?php

/* =====================================================================
   GNL Solution — Mon compte  (/account)  [espace client]
   ---------------------------------------------------------------------
   Gestion du compte par le client lui-même : état civil, identifiants
   (nom d'utilisateur + e-mail), mot de passe, double authentification,
   sessions actives.

   SOURCE DE VÉRITÉ : Keycloak. Tout est lu et réécrit via l'Admin REST
   (include/keycloak_account.php), appelé par data/account_api.php. Rien
   n'est stocké côté portail — la session PHP n'est qu'un cache rafraîchi
   après chaque écriture, pour que l'en-tête affiche la bonne identité.

   Accessible depuis le menu utilisateur (#userMenuDropdown → « Mon
   compte »), qui pointait auparavant vers la console Keycloak.

   ⚠️ L'activation de la 2FA passe forcément par Keycloak : l'Admin REST
      ne sait pas créer un secret TOTP. La page pose l'action requise
      CONFIGURE_TOTP et envoie le lien par e-mail. La désactivation, elle,
      est bien effectuée ici (DELETE du credential otp).
   ===================================================================== */

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

// user_account_sessions a une clé INT : on utilise 'account_id' (entier
// stable), pas 'id' qui est l'UID Keycloak — (int) d'un UUID vaut 0 dès
// qu'il commence par une lettre.
$accountPageId = (int) ($_SESSION['user']['account_id'] ?? 0);
if ($accountPageId <= 0 && ctype_digit((string) ($_SESSION['user']['id'] ?? ''))) {
    $accountPageId = (int) $_SESSION['user']['id'];
}

if ($accountPageId > 0) {
    if (accountSessionsIsCurrentSessionRevoked($pdo, $accountPageId)) {
        accountSessionsDestroyPhpSession();
        header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
        exit();
    }
    accountSessionsTouchCurrent($pdo, $accountPageId);
}

// Jeton CSRF (même clé que header.php et que data/account_api.php).
if (empty($_SESSION['csrf'])) {
    try {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['csrf'] = bin2hex((string) mt_rand());
    }
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

// Barre de recherche du header : sans objet sur une page de formulaires.
$showSearch = false;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Mon compte - GNL Solution') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta name="robots" content="noindex, nofollow"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="next-size-adjust" content=""/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  <style>
    .dashboard-layout {display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height, 0px));min-height:calc(100dvh - var(--app-header-height, 0px));}
    .dashboard-sidebar {flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main {flex:1 1 auto;min-width:0;}

    .acc-grid {display:grid;grid-template-columns:repeat(2, minmax(0,1fr));gap:1rem;}
    .acc-grid--full {grid-template-columns:minmax(0,1fr);}
    @media (max-width: 720px) { .acc-grid {grid-template-columns:minmax(0,1fr);} }

    .acc-field {display:flex;flex-direction:column;gap:.35rem;min-width:0;}
    .acc-field label {font-size:.82rem;font-weight:600;color:var(--muted-foreground,#64748b);}
    .acc-field input, .acc-field select {
      width:100%;border:1px solid var(--border,#e2e8f0);border-radius:.5rem;
      padding:.55rem .7rem;font:inherit;font-size:.92rem;background:var(--background,#fff);
      color:inherit;transition:border-color .15s, box-shadow .15s;
    }
    .acc-field input:focus, .acc-field select:focus {
      outline:none;border-color:#6c9400;box-shadow:0 0 0 3px rgba(108,148,0,.15);
    }
    .acc-field input[readonly], .acc-field input:disabled {
      background:var(--muted,#f1f5f9);color:var(--muted-foreground,#64748b);cursor:not-allowed;
    }
    .acc-field .hint {font-size:.76rem;color:var(--muted-foreground,#64748b);}

    .acc-btn {
      display:inline-flex;align-items:center;justify-content:center;gap:.45rem;
      border-radius:.5rem;padding:.55rem 1rem;font-size:.88rem;font-weight:600;
      border:1px solid transparent;cursor:pointer;transition:filter .15s, background .15s;
    }
    .acc-btn:disabled {opacity:.55;cursor:not-allowed;}
    .acc-btn--primary {background:#6c9400;color:#fff;}
    .acc-btn--primary:hover:not(:disabled) {filter:brightness(1.07);}
    .acc-btn--ghost {background:transparent;border-color:var(--border,#e2e8f0);color:inherit;}
    .acc-btn--ghost:hover:not(:disabled) {background:var(--muted,#f1f5f9);}
    .acc-btn--danger {background:#fff;border-color:#fecaca;color:#b91c1c;}
    .acc-btn--danger:hover:not(:disabled) {background:#fef2f2;}

    .acc-msg {border-radius:.6rem;border:1px solid;padding:.7rem .9rem;font-size:.86rem;margin-top:.9rem;}
    .acc-msg--ok  {border-color:#bbf7d0;background:#f0fdf4;color:#15803d;}
    .acc-msg--err {border-color:#fecaca;background:#fef2f2;color:#b91c1c;}
    .acc-msg--info{border-color:#fde68a;background:#fffbeb;color:#b45309;}

    .acc-badge {
      display:inline-flex;align-items:center;gap:.3rem;border:1px solid;border-radius:.4rem;
      padding:.15rem .5rem;font-size:.72rem;font-weight:600;
    }
    .acc-badge--on  {border-color:#bbf7d0;background:#f0fdf4;color:#15803d;}
    .acc-badge--off {border-color:#e2e8f0;background:#f8fafc;color:#64748b;}
    .acc-badge--warn{border-color:#fde68a;background:#fffbeb;color:#b45309;}

    .acc-skel {height:2.3rem;border-radius:.5rem;background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 37%,#f1f5f9 63%);background-size:400% 100%;animation:acc-shimmer 1.3s ease infinite;}
    @keyframes acc-shimmer {0%{background-position:100% 50%;}100%{background-position:0 50%;}}
    @media (prefers-reduced-motion: reduce) {.acc-skel {animation:none;}}

    .acc-session {display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;
      border:1px solid var(--border,#e2e8f0);border-radius:.6rem;padding:.75rem .9rem;}
    .acc-session__meta {font-size:.78rem;color:var(--muted-foreground,#64748b);}

    @media (max-width: 1024px) {
      .dashboard-layout { flex-direction: column; }
      .dashboard-sidebar {width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main { padding: 1rem; }
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
      <div class="app-shell-offset-min-height w-full bg-surface p-6 space-y-6">

        <!-- En-tête ------------------------------------------------- -->
        <div class="bg-background text-card-foreground flex flex-col gap-2 rounded-xl border py-6 shadow-sm">
          <div class="px-6">
            <h1 class="text-lg font-semibold"><?= t('Mon compte') ?></h1>
            <p class="text-sm text-muted-foreground">
              <?= t('Vos informations personnelles et la sécurité de votre accès. Tout est enregistré directement dans votre compte Keycloak.') ?>
            </p>
          </div>
          <div class="px-6 flex flex-wrap items-center gap-2 text-sm">
            <span id="accIdentity" class="text-muted-foreground">…</span>
            <span id="accEmailBadge" class="acc-badge acc-badge--off" hidden></span>
            <span id="acc2faBadge" class="acc-badge acc-badge--off" hidden></span>
          </div>
        </div>

        <div id="accGlobalAlert"></div>

        <!-- Profil -------------------------------------------------- -->
        <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
          <div class="px-6 pb-4 border-b">
            <h2 class="text-base font-semibold"><?= t('Profil') ?></h2>
            <p class="text-sm text-muted-foreground"><?= t('Votre état civil et vos coordonnées.') ?></p>
          </div>
          <form id="accProfileForm" class="px-6 pt-5" novalidate>
            <div class="acc-grid">
              <div class="acc-field">
                <label for="accCivilite"><?= t('Civilité') ?></label>
                <select id="accCivilite" name="civilite">
                  <option value=""><?= t('Non précisée') ?></option>
                  <option value="M.">M.</option>
                  <option value="Mme">Mme</option>
                </select>
              </div>
              <div class="acc-field">
                <label for="accFonction"><?= t('Fonction') ?></label>
                <input type="text" id="accFonction" name="fonction" maxlength="120" autocomplete="organization-title">
              </div>
              <div class="acc-field">
                <label for="accFirstName"><?= t('Prénom') ?></label>
                <input type="text" id="accFirstName" name="firstName" maxlength="100" autocomplete="given-name">
              </div>
              <div class="acc-field">
                <label for="accLastName"><?= t('Nom') ?></label>
                <input type="text" id="accLastName" name="lastName" maxlength="100" autocomplete="family-name">
              </div>
              <div class="acc-field">
                <label for="accPhone"><?= t('Téléphone') ?></label>
                <input type="tel" id="accPhone" name="phone" maxlength="25" autocomplete="tel" placeholder="+33 1 23 45 67 89">
              </div>
              <div class="acc-field">
                <label for="accLang"><?= t('Langue préférée') ?></label>
                <select id="accLang" name="pref_lang">
                  <option value=""><?= t('Non précisée') ?></option>
                  <option value="fr">Français</option>
                  <option value="en">English</option>
                </select>
              </div>
            </div>
            <div class="mt-5 flex items-center gap-3">
              <button type="submit" class="acc-btn acc-btn--primary"><?= t('Enregistrer') ?></button>
              <button type="reset" class="acc-btn acc-btn--ghost" data-acc-reset="profile"><?= t('Annuler') ?></button>
            </div>
            <div class="acc-msg-slot"></div>
          </form>
        </section>

        <!-- Identifiants -------------------------------------------- -->
        <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
          <div class="px-6 pb-4 border-b">
            <h2 class="text-base font-semibold"><?= t('Identifiants de connexion') ?></h2>
            <p class="text-sm text-muted-foreground"><?= t('Nom d’utilisateur et adresse e-mail de votre compte.') ?></p>
          </div>
          <form id="accIdentityForm" class="px-6 pt-5" novalidate>
            <div class="acc-grid">
              <div class="acc-field">
                <label for="accUsername"><?= t('Nom d’utilisateur') ?></label>
                <input type="text" id="accUsername" name="username" maxlength="64" autocomplete="username" spellcheck="false">
                <span class="hint" id="accUsernameHint"></span>
              </div>
              <div class="acc-field">
                <label for="accEmail"><?= t('Adresse e-mail') ?></label>
                <input type="email" id="accEmail" name="email" maxlength="190" autocomplete="email" spellcheck="false">
                <span class="hint"><?= t('Changer d’adresse déclenche un e-mail de vérification.') ?></span>
              </div>
            </div>
            <div class="mt-5 flex items-center gap-3">
              <button type="submit" class="acc-btn acc-btn--primary"><?= t('Enregistrer') ?></button>
              <button type="reset" class="acc-btn acc-btn--ghost" data-acc-reset="identity"><?= t('Annuler') ?></button>
            </div>
            <div class="acc-msg-slot"></div>
          </form>
        </section>

        <!-- Mot de passe -------------------------------------------- -->
        <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
          <div class="px-6 pb-4 border-b">
            <h2 class="text-base font-semibold"><?= t('Mot de passe') ?></h2>
            <p class="text-sm text-muted-foreground"><?= t('Votre mot de passe actuel est exigé pour valider le changement.') ?></p>
          </div>
          <form id="accPasswordForm" class="px-6 pt-5" novalidate>
            <div class="acc-grid acc-grid--full" style="max-width:32rem">
              <div class="acc-field">
                <label for="accPwCurrent"><?= t('Mot de passe actuel') ?></label>
                <input type="password" id="accPwCurrent" name="current" autocomplete="current-password" required>
              </div>
              <div class="acc-field">
                <label for="accPwNew"><?= t('Nouveau mot de passe') ?></label>
                <input type="password" id="accPwNew" name="new" autocomplete="new-password" minlength="12" required>
                <span class="hint"><?= t('12 caractères minimum. La politique de votre realm peut être plus stricte.') ?></span>
              </div>
              <div class="acc-field">
                <label for="accPwConfirm"><?= t('Confirmer le nouveau mot de passe') ?></label>
                <input type="password" id="accPwConfirm" name="confirm" autocomplete="new-password" minlength="12" required>
              </div>
            </div>
            <div class="mt-5">
              <button type="submit" class="acc-btn acc-btn--primary"><?= t('Changer le mot de passe') ?></button>
            </div>
            <div class="acc-msg-slot"></div>
          </form>
        </section>

        <!-- Double authentification --------------------------------- -->
        <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
          <div class="px-6 pb-4 border-b">
            <h2 class="text-base font-semibold"><?= t('Double authentification (2FA)') ?></h2>
            <p class="text-sm text-muted-foreground"><?= t('Un code à usage unique généré par votre application d’authentification, en plus du mot de passe.') ?></p>
          </div>
          <div class="px-6 pt-5">
            <div id="acc2faState" class="text-sm"><div class="acc-skel" style="max-width:22rem"></div></div>

            <div id="acc2faEnable" class="mt-4" hidden>
              <button type="button" id="acc2faEnableBtn" class="acc-btn acc-btn--primary"><?= t('Activer la double authentification') ?></button>
              <p class="mt-2 text-sm text-muted-foreground">
                <?= t('Vous recevrez un e-mail contenant le lien sécurisé pour scanner le QR code. La configuration vous sera également demandée à votre prochaine connexion.') ?>
              </p>
            </div>

            <form id="acc2faDisableForm" class="mt-4" hidden novalidate>
              <div class="acc-field" style="max-width:22rem">
                <label for="acc2faPassword"><?= t('Mot de passe actuel') ?></label>
                <input type="password" id="acc2faPassword" name="current" autocomplete="current-password" required>
                <span class="hint"><?= t('Exigé pour désactiver la double authentification.') ?></span>
              </div>
              <button type="submit" class="acc-btn acc-btn--danger mt-4"><?= t('Désactiver la double authentification') ?></button>
            </form>

            <div class="acc-msg-slot"></div>
          </div>
        </section>

        <!-- Sessions ------------------------------------------------- -->
        <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
          <div class="px-6 pb-4 border-b flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h2 class="text-base font-semibold"><?= t('Sessions actives') ?></h2>
              <p class="text-sm text-muted-foreground"><?= t('Les appareils connectés à votre compte.') ?></p>
            </div>
            <button type="button" id="accSessionsRefresh" class="acc-btn acc-btn--ghost"><?= t('Actualiser') ?></button>
          </div>
          <div class="px-6 pt-5 space-y-5">
            <div>
              <h3 class="text-sm font-semibold mb-2"><?= t('Connexions Keycloak (SSO)') ?></h3>
              <div id="accKcSessions" class="space-y-2"><div class="acc-skel"></div></div>
            </div>
            <div>
              <div class="flex items-center justify-between gap-3 flex-wrap mb-2">
                <h3 class="text-sm font-semibold"><?= t('Sessions de l’espace client') ?></h3>
                <button type="button" id="accRevokeOthers" class="acc-btn acc-btn--danger"><?= t('Fermer les autres sessions') ?></button>
              </div>
              <div id="accPortalSessions" class="space-y-2"><div class="acc-skel"></div></div>
            </div>
            <div class="acc-msg-slot"></div>
          </div>
        </section>

        <!-- Rattachement entreprise (lecture seule) ------------------ -->
        <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
          <div class="px-6 pb-4 border-b">
            <h2 class="text-base font-semibold"><?= t('Rattachement entreprise') ?></h2>
            <p class="text-sm text-muted-foreground"><?= t('Ces informations appartiennent à votre structure : contactez le support pour les corriger.') ?></p>
          </div>
          <div class="px-6 pt-5">
            <dl id="accCompany" class="acc-grid text-sm"><div class="acc-skel"></div></dl>
          </div>
        </section>

      </div>
    </main>
  </div>

  <script>
    window.ACCOUNT_API  = window.ACCOUNT_API  || "../data/account_api.php";
    window.ACCOUNT_CSRF = <?= json_encode($_SESSION['csrf'] ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    window.ACCOUNT_I18N = {
      netError:     <?= json_encode(t('Le serveur est injoignable. Réessayez dans un instant.'), JSON_UNESCAPED_UNICODE) ?>,
      saved:        <?= json_encode(t('Enregistré.'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaOn:      <?= json_encode(t('Double authentification active'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaOff:     <?= json_encode(t('Double authentification inactive'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaPending: <?= json_encode(t('Configuration en attente'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaSince:   <?= json_encode(t('Configurée le'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaSoon:    <?= json_encode(t('La configuration vous sera demandée à votre prochaine connexion, ou via le lien reçu par e-mail.'), JSON_UNESCAPED_UNICODE) ?>,
      emailOk:      <?= json_encode(t('E-mail vérifié'), JSON_UNESCAPED_UNICODE) ?>,
      emailKo:      <?= json_encode(t('E-mail non vérifié'), JSON_UNESCAPED_UNICODE) ?>,
      noSession:    <?= json_encode(t('Aucune session active.'), JSON_UNESCAPED_UNICODE) ?>,
      current:      <?= json_encode(t('Session courante'), JSON_UNESCAPED_UNICODE) ?>,
      close:        <?= json_encode(t('Fermer'), JSON_UNESCAPED_UNICODE) ?>,
      openedOn:     <?= json_encode(t('ouverte le'), JSON_UNESCAPED_UNICODE) ?>,
      seenOn:       <?= json_encode(t('vue'), JSON_UNESCAPED_UNICODE) ?>,
      confirmOther: <?= json_encode(t('Fermer toutes vos autres sessions de l’espace client ?'), JSON_UNESCAPED_UNICODE) ?>,
      confirmKc:    <?= json_encode(t('Fermer cette connexion Keycloak ? L’appareil concerné devra se reconnecter.'), JSON_UNESCAPED_UNICODE) ?>,
      usernameLock: <?= json_encode(t('Modification désactivée sur ce realm Keycloak.'), JSON_UNESCAPED_UNICODE) ?>,
      usernameMail: <?= json_encode(t('Votre identifiant suit votre adresse e-mail.'), JSON_UNESCAPED_UNICODE) ?>,
      usernameFree: <?= json_encode(t('Lettres, chiffres et . _ - @ — 3 à 64 caractères.'), JSON_UNESCAPED_UNICODE) ?>,
      noCompany:    <?= json_encode(t('Aucune information d’entreprise rattachée à ce compte.'), JSON_UNESCAPED_UNICODE) ?>,
      never:        <?= json_encode(t('jamais'), JSON_UNESCAPED_UNICODE) ?>,
      labels: {
        raison:         <?= json_encode(t('Raison sociale'), JSON_UNESCAPED_UNICODE) ?>,
        nom_commercial: <?= json_encode(t('Nom commercial'), JSON_UNESCAPED_UNICODE) ?>,
        siret:          "SIRET",
        siren:          "SIREN",
        client_code:    <?= json_encode(t('Code client'), JSON_UNESCAPED_UNICODE) ?>
      }
    };
  </script>

  <script>
  (function () {
    'use strict';

    var API   = window.ACCOUNT_API;
    var CSRF  = window.ACCOUNT_CSRF || '';
    var I18N  = window.ACCOUNT_I18N || {};
    var state = { profile: null, sessions: { keycloak: [], portal: [] } };

    function $(id) { return document.getElementById(id); }
    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    /* ---------- Messages ---------------------------------------- */

    function slotOf(el) {
      var host = el.closest('section') || el;
      return host.querySelector('.acc-msg-slot');
    }
    function say(el, text, kind) {
      var slot = slotOf(el);
      if (!slot) return;
      slot.innerHTML = '';
      if (!text) return;
      var div = document.createElement('div');
      div.className = 'acc-msg acc-msg--' + (kind || 'ok');
      div.setAttribute('role', kind === 'err' ? 'alert' : 'status');
      div.textContent = text;
      slot.appendChild(div);
    }
    function globalError(text) {
      var host = $('accGlobalAlert');
      if (!host) return;
      host.innerHTML = '';
      if (!text) return;
      var div = document.createElement('div');
      div.className = 'acc-msg acc-msg--err';
      div.setAttribute('role', 'alert');
      div.style.marginTop = '0';
      div.textContent = text;
      host.appendChild(div);
    }

    /* ---------- Transport --------------------------------------- */

    function call(action, payload) {
      var isRead = (action === 'account.load' || action === 'account.sessions.list');
      var opts;

      if (isRead) {
        opts = { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
      } else {
        var body = { action: action, csrf: CSRF };
        if (payload) {
          Object.keys(payload).forEach(function (k) { body[k] = payload[k]; });
        }
        opts = {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': CSRF },
          body: JSON.stringify(body)
        };
      }

      return fetch(API + '?action=' + encodeURIComponent(action), opts)
        .then(function (r) {
          // 401 = session portail expirée ou révoquée : on repart au login.
          if (r.status === 401) { window.location.href = '/connexion'; throw new Error('unauthorized'); }
          return r.json().catch(function () { return { ok: false, error: I18N.netError }; });
        })
        .catch(function (e) {
          if (e && e.message === 'unauthorized') throw e;
          return { ok: false, error: I18N.netError };
        });
    }

    /** Désactive un formulaire le temps de l'aller-retour. */
    function busy(form, on) {
      Array.prototype.forEach.call(form.querySelectorAll('button, input, select'), function (n) {
        n.disabled = !!on;
      });
    }

    /* ---------- Rendu ------------------------------------------- */

    function fmtMs(ms) {
      if (!ms) return I18N.never;
      try { return new Date(ms).toLocaleString('fr-FR'); }
      catch (e) { return String(ms); }
    }

    function renderProfile(p) {
      if (!p) return;
      state.profile = p;

      var full = [p.civilite, p.firstName, p.lastName].filter(Boolean).join(' ').trim();
      $('accIdentity').textContent = full !== '' ? full + ' — ' + p.email : p.email;

      var eb = $('accEmailBadge');
      eb.hidden = false;
      eb.className = 'acc-badge ' + (p.emailVerified ? 'acc-badge--on' : 'acc-badge--warn');
      eb.textContent = p.emailVerified ? I18N.emailOk : I18N.emailKo;

      var tf = p.twoFactor || { enabled: false, pending: false, credentials: [] };
      var tb = $('acc2faBadge');
      tb.hidden = false;
      if (tf.enabled)      { tb.className = 'acc-badge acc-badge--on';   tb.textContent = I18N.twoFaOn; }
      else if (tf.pending) { tb.className = 'acc-badge acc-badge--warn'; tb.textContent = I18N.twoFaPending; }
      else                 { tb.className = 'acc-badge acc-badge--off';  tb.textContent = I18N.twoFaOff; }

      $('accCivilite').value  = p.civilite  || '';
      $('accFirstName').value = p.firstName || '';
      $('accLastName').value  = p.lastName  || '';
      $('accPhone').value     = p.phone     || '';
      $('accFonction').value  = p.fonction  || '';
      $('accLang').value      = (p.pref_lang === 'fr' || p.pref_lang === 'en') ? p.pref_lang : '';

      $('accUsername').value = p.username || '';
      $('accEmail').value    = p.email    || '';

      // L'identifiant n'est éditable que si le realm l'autorise ET qu'il ne
      // suit pas l'e-mail : sinon le champ est verrouillé, avec la raison.
      var realm = p.realm || {};
      var lockedByEmail = !!realm.registrationEmailAsUsername;
      var locked = lockedByEmail || realm.editUsernameAllowed === false;
      $('accUsername').readOnly = locked;
      $('accUsernameHint').textContent = lockedByEmail ? I18N.usernameMail
                                       : locked        ? I18N.usernameLock
                                       :                 I18N.usernameFree;

      render2fa(tf);
      renderCompany(p.company || {});
    }

    function render2fa(tf) {
      var host = $('acc2faState');

      if (tf.enabled) {
        var cred = tf.credentials[0] || {};
        host.innerHTML = '<span class="acc-badge acc-badge--on">' + esc(I18N.twoFaOn) + '</span>'
          + '<p class="mt-2 text-sm text-muted-foreground">'
          + esc(I18N.twoFaSince + ' ' + fmtMs(cred.createdDate))
          + (cred.label ? ' — ' + esc(cred.label) : '') + '</p>';
      } else if (tf.pending) {
        host.innerHTML = '<span class="acc-badge acc-badge--warn">' + esc(I18N.twoFaPending) + '</span>'
          + '<p class="mt-2 text-sm text-muted-foreground">' + esc(I18N.twoFaSoon) + '</p>';
      } else {
        host.innerHTML = '<span class="acc-badge acc-badge--off">' + esc(I18N.twoFaOff) + '</span>';
      }

      // Le bouton « Activer » reste proposé tant qu'aucun credential OTP
      // n'existe — y compris en attente, l'e-mail peut être renvoyé.
      $('acc2faEnable').hidden      = tf.enabled;
      $('acc2faDisableForm').hidden = !tf.enabled;
    }

    function renderCompany(c) {
      var order = ['raison', 'nom_commercial', 'siret', 'siren', 'client_code'];
      var host  = $('accCompany');
      var rows  = order.filter(function (k) { return c[k]; });

      if (!rows.length) {
        host.innerHTML = '<p class="text-sm text-muted-foreground">' + esc(I18N.noCompany) + '</p>';
        return;
      }
      host.innerHTML = rows.map(function (k) {
        return '<div><dt class="text-xs font-semibold text-muted-foreground">'
          + esc((I18N.labels || {})[k] || k) + '</dt>'
          + '<dd class="text-sm">' + esc(c[k]) + '</dd></div>';
      }).join('');
    }

    function renderSessions(s) {
      state.sessions = s || { keycloak: [], portal: [] };

      var kc = $('accKcSessions');
      if (!state.sessions.keycloak.length) {
        kc.innerHTML = '<p class="text-sm text-muted-foreground">' + esc(I18N.noSession) + '</p>';
      } else {
        kc.innerHTML = state.sessions.keycloak.map(function (x) {
          var clients = (x.clients || []).join(', ');
          var meta = I18N.openedOn + ' ' + fmtMs(x.start) + ' · ' + I18N.seenOn + ' ' + fmtMs(x.lastAccess)
                   + (clients ? ' · ' + clients : '');
          return '<div class="acc-session">'
            + '<div><p class="text-sm font-medium">' + esc(x.ip || '—') + '</p>'
            + '<p class="acc-session__meta">' + esc(meta) + '</p></div>'
            + '<button type="button" class="acc-btn acc-btn--danger" data-kc-session="' + esc(x.id) + '">'
            + esc(I18N.close) + '</button></div>';
        }).join('');
      }

      var pt = $('accPortalSessions');
      if (!state.sessions.portal.length) {
        pt.innerHTML = '<p class="text-sm text-muted-foreground">' + esc(I18N.noSession) + '</p>';
      } else {
        pt.innerHTML = state.sessions.portal.map(function (x) {
          var meta = (x.ip || '—') + ' · ' + I18N.openedOn + ' ' + (x.createdAt || '—')
                   + ' · ' + I18N.seenOn + ' ' + (x.lastSeen || '—');
          return '<div class="acc-session">'
            + '<div><p class="text-sm font-medium">' + esc(x.device || '—')
            + (x.current ? ' <span class="acc-badge acc-badge--on">' + esc(I18N.current) + '</span>' : '')
            + '</p><p class="acc-session__meta">' + esc(meta) + '</p></div>'
            + (x.current ? ''
              : '<button type="button" class="acc-btn acc-btn--danger" data-local-session="' + esc(x.id) + '">'
                + esc(I18N.close) + '</button>')
            + '</div>';
        }).join('');
      }
    }

    /* ---------- Chargement initial ------------------------------ */

    function load() {
      return call('account.load').then(function (r) {
        if (!r.ok) { globalError(r.error || I18N.netError); return; }
        globalError('');
        renderProfile(r.profile);
        renderSessions(r.sessions);
      }).catch(function () { /* redirection /connexion en cours */ });
    }

    /* ---------- Formulaires ------------------------------------- */

    function bindForm(formId, action, collect, after) {
      var form = $(formId);
      if (!form) return;

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        say(form, '', 'ok');
        busy(form, true);

        call(action, collect(form)).then(function (r) {
          busy(form, false);
          if (!r.ok) { say(form, r.error || I18N.netError, 'err'); return; }
          if (r.profile) renderProfile(r.profile);
          say(form, r.notice || I18N.saved, 'ok');
          if (typeof after === 'function') after(form, r);
        }).catch(function () { busy(form, false); });
      });

      // « Annuler » : on repart des valeurs Keycloak, pas des valeurs
      // initiales du HTML (vides au premier rendu).
      var reset = form.querySelector('[data-acc-reset]');
      if (reset) {
        reset.addEventListener('click', function (e) {
          e.preventDefault();
          say(form, '', 'ok');
          if (state.profile) renderProfile(state.profile);
        });
      }
    }

    bindForm('accProfileForm', 'account.profile.save', function (f) {
      return {
        civilite:  f.civilite.value,
        firstName: f.firstName.value,
        lastName:  f.lastName.value,
        phone:     f.phone.value,
        fonction:  f.fonction.value,
        pref_lang: f.pref_lang.value
      };
    });

    bindForm('accIdentityForm', 'account.identity.save', function (f) {
      return { username: f.username.value, email: f.email.value };
    });

    bindForm('accPasswordForm', 'account.password.change', function (f) {
      return { current: f.current.value, 'new': f.new.value, confirm: f.confirm.value };
    }, function (form) {
      form.reset();   // ne jamais laisser des mots de passe dans le DOM
    });

    /* ---------- 2FA --------------------------------------------- */

    var enableBtn = $('acc2faEnableBtn');
    if (enableBtn) {
      enableBtn.addEventListener('click', function () {
        enableBtn.disabled = true;
        call('account.2fa.enable', {}).then(function (r) {
          enableBtn.disabled = false;
          if (!r.ok) { say(enableBtn, r.error || I18N.netError, 'err'); return; }
          if (r.profile) renderProfile(r.profile);
          say(enableBtn, r.notice, r.emailSent ? 'ok' : 'info');
        }).catch(function () { enableBtn.disabled = false; });
      });
    }

    var disableForm = $('acc2faDisableForm');
    if (disableForm) {
      disableForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var pw = disableForm.current.value;
        busy(disableForm, true);
        call('account.2fa.disable', { current: pw }).then(function (r) {
          busy(disableForm, false);
          disableForm.reset();
          if (!r.ok) { say(disableForm, r.error || I18N.netError, 'err'); return; }
          if (r.profile) renderProfile(r.profile);
          say(disableForm, r.notice, 'ok');
        }).catch(function () { busy(disableForm, false); });
      });
    }

    /* ---------- Sessions ---------------------------------------- */

    var kcHost       = $('accKcSessions');
    var sessionsHost = $('accPortalSessions');

    function revoke(target, confirmText, anchor) {
      if (confirmText && !window.confirm(confirmText)) return;
      call('account.sessions.revoke', { target: target }).then(function (r) {
        if (!r.ok) { say(anchor, r.error || I18N.netError, 'err'); return; }
        renderSessions(r.sessions);
        say(anchor, r.notice, 'ok');
      }).catch(function () {});
    }

    if (kcHost) {
      kcHost.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-kc-session]');
        if (!btn) return;
        revoke('kc:' + btn.getAttribute('data-kc-session'), I18N.confirmKc, kcHost);
      });
    }

    if (sessionsHost) {
      sessionsHost.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-local-session]');
        if (!btn) return;
        revoke('local:' + btn.getAttribute('data-local-session'), null, sessionsHost);
      });
    }

    var others = $('accRevokeOthers');
    if (others) {
      others.addEventListener('click', function () {
        if (!window.confirm(I18N.confirmOther)) return;
        others.disabled = true;
        call('account.sessions.revoke_others', {}).then(function (r) {
          others.disabled = false;
          if (!r.ok) { say(others, r.error || I18N.netError, 'err'); return; }
          renderSessions(r.sessions);
          say(others, r.notice, 'ok');
        }).catch(function () { others.disabled = false; });
      });
    }

    var refresh = $('accSessionsRefresh');
    if (refresh) {
      refresh.addEventListener('click', function () {
        refresh.disabled = true;
        call('account.sessions.list').then(function (r) {
          refresh.disabled = false;
          if (r.ok) renderSessions(r.sessions);
          else say(refresh, r.error || I18N.netError, 'err');
        }).catch(function () { refresh.disabled = false; });
      });
    }

    /* ---------- Go ---------------------------------------------- */

    if (document.readyState !== 'loading') load();
    else document.addEventListener('DOMContentLoaded', load);
  })();
  </script>
</body>
</html>
