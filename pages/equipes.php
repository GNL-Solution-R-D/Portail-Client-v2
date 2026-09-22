<?php

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

// user_account_sessions a une clé INT : on utilise 'account_id' (entier stable
// posé par gnl_apply_identity()), pas 'id' qui est l'UID Keycloak — (int) d'un
// UUID vaut 0 dès qu'il commence par une lettre.
$equipesAccountId = (int) ($_SESSION['user']['account_id'] ?? 0);
if ($equipesAccountId <= 0 && ctype_digit((string) ($_SESSION['user']['id'] ?? ''))) {
    $equipesAccountId = (int) $_SESSION['user']['id'];
}

if ($equipesAccountId > 0) {
    if (accountSessionsIsCurrentSessionRevoked($pdo, $equipesAccountId)) {
        accountSessionsDestroyPhpSession();
        header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
        exit();
    }

    accountSessionsTouchCurrent($pdo, $equipesAccountId);
}

// Jeton CSRF (même clé que header.php et que data/portail_api.php).
if (empty($_SESSION['csrf'])) {
    try {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['csrf'] = bin2hex((string) mt_rand());
    }
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Barre de recherche du header (include/header.php) : ACTIVÉE pour cette page.
// Elle filtre la liste des membres, alimentée par les ORGANIZATIONS de
// Keycloak (data/portail_api.php ?action=team.list →
// include/org_permissions.php → Admin REST).
//
// Services et fonctions = GROUPES D'ORGANISATION Keycloak :
//   service  = groupe de 1er niveau (« R&D », « Global » pour les fonctions
//              sans service) ; fonction = sous-groupe (« Directeur » →
//              affichée « Directeur R&D »). L'attribut de groupe « perm »
//              (liste de clés) porte les droits ; un sous-groupe hérite de ses
//              parents et peut en ajouter. La page permet, selon les droits de
//              l'utilisateur (teams.manage / teams.assign), de créer / modifier
//              / supprimer services et fonctions et de les attribuer aux membres.
//              Les droits sont TOUJOURS revérifiés côté serveur.
$showSearch        = true;
$searchInputId     = 'membersSearchInput';
$searchPlaceholder = t('Rechercher un membre…');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Équipes - GNL Solution') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
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
    .table-wrap {overflow-x:auto;}
    .members-state td{padding:1.5rem 1rem;text-align:center;color:var(--muted-foreground, #64748b);}
    .members-state--error td{color:#b91c1c;}

    .collapsible-content {overflow:hidden;height:0;opacity:0;transition:height 220ms ease, opacity 220ms ease;will-change:height, opacity;}
    .collapsible-content.is-open {opacity:1;}
    .collapsible-trigger .collapsible-chevron {transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron {transform:rotate(90deg);}
    @media (prefers-reduced-motion: reduce) {.collapsible-content,.collapsible-trigger .collapsible-chevron {transition:none !important;}}

    /* ── Services & fonctions ─────────────────────────────────────────── */
    .tm-btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;height:2.25rem;padding:0 .75rem;border:1px solid var(--border,#e2e8f0);border-radius:.375rem;font-size:.875rem;font-weight:500;background:var(--background,#fff);color:inherit;cursor:pointer;white-space:nowrap;transition:background .15s, opacity .15s;}
    .tm-btn:hover{background:var(--secondary,#f1f5f9);}
    .tm-btn:disabled{opacity:.5;cursor:not-allowed;}
    .tm-btn--primary{background:var(--primary,#0f172a);color:var(--primary-foreground,#fff);border-color:transparent;}
    .tm-btn--primary:hover{background:var(--primary,#0f172a);opacity:.9;}
    .tm-btn--danger{color:#b91c1c;}
    .tm-btn--danger.tm-btn--solid{background:#b91c1c;color:#fff;border-color:transparent;}
    .tm-btn--sm{height:1.75rem;padding:0 .5rem;font-size:.75rem;}
    .tm-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:1.75rem;height:1.75rem;border-radius:.375rem;border:1px solid transparent;background:transparent;color:var(--muted-foreground,#64748b);cursor:pointer;}
    .tm-icon-btn:hover{background:var(--secondary,#f1f5f9);color:var(--foreground,#0f172a);}
    .tm-icon-btn--danger:hover{color:#b91c1c;}
    .tm-icon-btn svg{width:1rem;height:1rem;}

    .svc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(20rem,1fr));gap:1rem;padding:1.25rem 1.5rem 0;}
    .svc-card{border:1px solid var(--border,#e2e8f0);border-radius:.75rem;background:var(--background,#fff);display:flex;flex-direction:column;min-width:0;}
    .svc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem;padding:.875rem 1rem;border-bottom:1px solid var(--border,#e2e8f0);}
    .svc-title{font-weight:600;font-size:.95rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
    .svc-sub{font-size:.75rem;color:var(--muted-foreground,#64748b);margin-top:.15rem;}
    .svc-body{padding:.5rem .5rem .75rem;}
    .fn-row{display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem;padding:.5rem .5rem;border-radius:.5rem;}
    .fn-row:hover{background:var(--muted,#f8fafc);}
    .fn-name{font-size:.875rem;font-weight:500;}
    .fn-meta{font-size:.75rem;color:var(--muted-foreground,#64748b);}
    .fn-actions{display:flex;gap:.125rem;flex-shrink:0;}
    .fn-empty{font-size:.8rem;color:var(--muted-foreground,#64748b);padding:.5rem;}
    .fn-tree{border-left:1px dashed var(--border,#e2e8f0);margin-left:.75rem;padding-left:.25rem;}

    .chips{display:flex;flex-wrap:wrap;gap:.25rem;margin-top:.3rem;}
    .chip{display:inline-flex;align-items:center;gap:.25rem;border:1px solid var(--border,#e2e8f0);border-radius:999px;padding:.05rem .5rem;font-size:.7rem;line-height:1.1rem;background:var(--muted,#f8fafc);color:var(--foreground,#0f172a);white-space:nowrap;}
    .chip--perm{background:#eef2ff;border-color:#c7d2fe;color:#3730a3;}
    .chip--inherited{background:transparent;border-style:dashed;color:var(--muted-foreground,#64748b);}
    .chip--admin{background:#fef3c7;border-color:#fcd34d;color:#92400e;}
    .chip--fn{font-size:.75rem;padding:.1rem .55rem;background:var(--background,#fff);}
    .chip--global{border-color:#cbd5e1;}
    .chip button{border:0;background:transparent;padding:0;margin-left:.1rem;cursor:pointer;color:inherit;opacity:.6;font-size:.85rem;line-height:1;}
    .chip button:hover{opacity:1;color:#b91c1c;}
    .badge-soft{display:inline-flex;align-items:center;justify-content:center;border-radius:.375rem;border:1px solid var(--border,#e2e8f0);padding:.1rem .5rem;font-size:.75rem;font-weight:500;background:#f1f5f9;color:#334155;}
    .badge-soft--ok{background:#dcfce7;color:#166534;border-color:#bbf7d0;}
    .badge-soft--warn{background:#fef3c7;color:#92400e;border-color:#fde68a;}

    .perm-list{display:grid;gap:.25rem;max-height:18rem;overflow:auto;border:1px solid var(--border,#e2e8f0);border-radius:.5rem;padding:.5rem;}
    .perm-group{font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-foreground,#64748b);margin:.35rem 0 .1rem;}
    .perm-group:first-child{margin-top:0;}
    .perm-item{display:flex;align-items:flex-start;gap:.5rem;font-size:.85rem;padding:.2rem .25rem;border-radius:.375rem;}
    .perm-item input{margin:.2rem .15rem 0 0;flex-shrink:0;}
    .perm-item code{font-size:.7rem;color:var(--muted-foreground,#64748b);}
    .perm-item.is-locked{opacity:.55;}
    .perm-item .perm-note{font-size:.7rem;color:var(--muted-foreground,#64748b);}

    .tm-modal{position:fixed;inset:0;z-index:60;display:none;align-items:center;justify-content:center;padding:1rem;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);}
    .tm-modal.is-open{display:flex;}
    .tm-dialog{width:100%;max-width:32rem;max-height:calc(100vh - 2rem);overflow:auto;border:1px solid var(--border,#e2e8f0);border-radius:.75rem;background:var(--card,#fff);color:var(--card-foreground,#0f172a);box-shadow:0 10px 30px rgba(0,0,0,.2);}
    .tm-field label{display:block;margin-bottom:.35rem;font-size:.75rem;font-weight:500;color:var(--muted-foreground,#64748b);}
    .tm-field input[type=text],.tm-field select{height:2.5rem;width:100%;border:1px solid var(--border,#e2e8f0);border-radius:.375rem;background:var(--background,#fff);color:inherit;padding:0 .75rem;font-size:.875rem;}
    .tm-error{font-size:.8rem;color:#b91c1c;}
    .tm-hint{font-size:.75rem;color:var(--muted-foreground,#64748b);}

    @media (max-width: 1024px) {
      .dashboard-layout { flex-direction: column; }
      .dashboard-sidebar {width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main { padding: 1rem; }
      .svc-grid{padding:1rem 1rem 0;grid-template-columns:1fr;}
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
        <div class="bg-background text-card-foreground flex flex-col gap-3 rounded-xl border py-6 shadow-sm">
          <div class="px-6">
            <h1 class="text-lg font-semibold"><?= t('Membres de la structure') ?></h1>
            <p class="text-sm text-muted-foreground">
              <?= t('Membres rattachés à votre structure') ?><span id="structureName"></span>.
            </p>
          </div>
          <div class="px-6 flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
            <span id="membersCount" class="badge-soft" data-suffix="<?php echo h(t('membre(s)')); ?>">…</span>
            <span id="orgBadge" class="badge-soft" hidden></span>
            <span id="editModeBadge" class="badge-soft" title=""></span>
          </div>
        </div>

        <div id="teamAlerts" class="space-y-3"></div>

        <!-- Services et fonctions (groupes d'organisation Keycloak) -->
        <section id="servicesSection" class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm" hidden>
          <div class="px-6 pb-4 border-b flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h2 class="text-base font-semibold"><?= t('Services et fonctions') ?></h2>
              <p class="text-sm text-muted-foreground"><?= t('Chaque fonction hérite des droits de son service ; un sous-groupe peut en ajouter.') ?></p>
            </div>
            <div id="servicesToolbar" class="flex flex-wrap gap-2" hidden>
              <button type="button" class="tm-btn" data-action="new-service">+ <?= t('Service') ?></button>
              <button type="button" class="tm-btn tm-btn--primary" data-action="new-function">+ <?= t('Fonction') ?></button>
            </div>
          </div>
          <div id="servicesGrid" class="svc-grid"></div>
          <p id="servicesEmpty" class="px-6 pt-5 text-sm text-muted-foreground" hidden></p>
        </section>

        <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
          <div class="px-6 pb-4 border-b flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h2 class="text-base font-semibold"><?= t('Liste des membres') ?></h2>
              <p class="text-sm text-muted-foreground"><?= t('Annuaire Keycloak de l’organisation') ?></p>
            </div>
          </div>

          <div class="table-wrap" data-slot="card-content">
            <table class="w-full min-w-max table-auto text-left">
              <thead>
                <tr id="membersHeadRow">
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Membre') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Service / Fonction') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Statut') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Droits') ?></p></th>
                  <th class="border-surface border-b p-4" data-col-actions hidden><p class="text-default block text-sm font-medium"><?= t('Action') ?></p></th>
                </tr>
              </thead>
              <tbody id="membersTableBody">
                <tr class="members-state">
                  <td colspan="5"><?= t('Chargement des membres…') ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  </div>

  <!-- Modale : créer / modifier un service ou une fonction -->
  <div id="groupModal" class="tm-modal" role="dialog" aria-modal="true" aria-labelledby="groupModalTitle">
    <div class="tm-dialog">
      <div class="p-6">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 id="groupModalTitle" class="text-lg font-semibold"></h2>
            <p id="groupModalSub" class="tm-hint mt-1"></p>
          </div>
          <button type="button" class="tm-btn" data-close><?= t('Fermer') ?></button>
        </div>
        <div class="mt-5 space-y-4">
          <div class="tm-field" id="groupParentField">
            <label for="groupParent"><?= t('Service') ?></label>
            <select id="groupParent"></select>
            <p class="tm-hint mt-1" id="groupParentHint"></p>
          </div>
          <div class="tm-field">
            <label for="groupName"><?= t('Nom') ?></label>
            <input id="groupName" type="text" maxlength="60" autocomplete="off" spellcheck="false" />
            <p class="tm-hint mt-1" id="groupNamePreview"></p>
          </div>
          <div class="tm-field">
            <label><?= t('Droits (attribut « perm »)') ?></label>
            <div id="groupPerms" class="perm-list"></div>
            <p class="tm-hint mt-1"><?= t('Les droits hérités du service parent sont déjà acquis. Vous ne pouvez accorder que des droits que vous détenez.') ?></p>
          </div>
          <div id="groupError" class="tm-error" hidden></div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="tm-btn" data-close><?= t('Annuler') ?></button>
          <button type="button" class="tm-btn tm-btn--primary" id="groupSave"><?= t('Enregistrer') ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modale : attribuer une fonction à un membre -->
  <div id="assignModal" class="tm-modal" role="dialog" aria-modal="true" aria-labelledby="assignModalTitle">
    <div class="tm-dialog">
      <div class="p-6">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 id="assignModalTitle" class="text-lg font-semibold"><?= t('Attribuer une fonction') ?></h2>
            <p id="assignModalSub" class="tm-hint mt-1"></p>
          </div>
          <button type="button" class="tm-btn" data-close><?= t('Fermer') ?></button>
        </div>
        <div class="mt-5 space-y-4">
          <div class="tm-field">
            <label for="assignGroup"><?= t('Fonction') ?></label>
            <select id="assignGroup"></select>
          </div>
          <div id="assignPreview" class="chips"></div>
          <div id="assignError" class="tm-error" hidden></div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="tm-btn" data-close><?= t('Annuler') ?></button>
          <button type="button" class="tm-btn tm-btn--primary" id="assignSave"><?= t('Attribuer') ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modale : confirmation -->
  <div id="confirmModal" class="tm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <div class="tm-dialog" style="max-width:28rem">
      <div class="p-6">
        <h2 id="confirmTitle" class="text-lg font-semibold"></h2>
        <p id="confirmText" class="text-sm text-muted-foreground mt-2"></p>
        <div id="confirmError" class="tm-error mt-3" hidden></div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="tm-btn" data-close><?= t('Annuler') ?></button>
          <button type="button" class="tm-btn tm-btn--danger tm-btn--solid" id="confirmOk"><?= t('Confirmer') ?></button>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
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
          if (expanded) { content.hidden = false; content.classList.add('is-open'); content.style.height = 'auto'; }
          else { content.hidden = true; content.classList.remove('is-open'); content.style.height = '0px'; }

          btn.addEventListener('click', function (e) {
            e.preventDefault();
            var isOpen = btn.getAttribute('aria-expanded') === 'true';
            if (!isOpen) {
              btn.setAttribute('aria-expanded', 'true');
              content.hidden = false; content.classList.add('is-open'); content.style.height = '0px';
              var h = content.scrollHeight;
              requestAnimationFrame(function () { content.style.height = h + 'px'; });
              content.addEventListener('transitionend', function onEnd(ev) {
                if (ev.propertyName !== 'height') return;
                content.style.height = 'auto'; content.removeEventListener('transitionend', onEnd);
              });
            } else {
              btn.setAttribute('aria-expanded', 'false');
              content.classList.remove('is-open');
              var current = content.scrollHeight; content.style.height = current + 'px';
              requestAnimationFrame(function () { content.style.height = '0px'; });
              content.addEventListener('transitionend', function onEndClose(ev) {
                if (ev.propertyName !== 'height') return;
                content.hidden = true; content.removeEventListener('transitionend', onEndClose);
              });
            }
          }, { passive: false });
        });
      });
    })();
  </script>

  <!-- Membres + services/fonctions : ORGANIZATIONS Keycloak via
       data/portail_api.php (team.list, team.group.*, team.member.*).
       Les droits affichés ici ne servent qu'à l'interface : le serveur les
       recalcule depuis Keycloak à chaque écriture. -->
  <script>
    window.TEAM_API_URL = window.TEAM_API_URL || "../data/portail_api.php";
    window.TEAM_CSRF = <?= json_encode((string) ($_SESSION['csrf'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    window.TEAM_I18N = {
      loading:        <?= json_encode(t('Chargement des membres…'), JSON_UNESCAPED_UNICODE) ?>,
      empty:          <?= json_encode(t('Aucun membre trouvé pour cette structure.'), JSON_UNESCAPED_UNICODE) ?>,
      noResults:      <?= json_encode(t('Aucun membre ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      error:          <?= json_encode(t('Impossible de charger les membres.'), JSON_UNESCAPED_UNICODE) ?>,
      readOnly:       <?= json_encode(t('Lecture seule'), JSON_UNESCAPED_UNICODE) ?>,
      manager:        <?= json_encode(t('Gestionnaire de l’équipe'), JSON_UNESCAPED_UNICODE) ?>,
      assigner:       <?= json_encode(t('Attribution des fonctions'), JSON_UNESCAPED_UNICODE) ?>,
      bootstrap:      <?= json_encode(t('Mode initialisation'), JSON_UNESCAPED_UNICODE) ?>,
      bootstrapHelp:  <?= json_encode(t('Aucun membre ne gère encore l’équipe : tous les membres de l’organisation ont temporairement tous les droits. Créez une fonction avec le droit « Gérer les services, fonctions et droits » (ou « Administrateur ») et attribuez-la vous : ce mode prendra fin automatiquement.'), JSON_UNESCAPED_UNICODE) ?>,
      unsupported:    <?= json_encode(t('Les services et fonctions nécessitent Keycloak 26.6 ou plus récent (groupes d’organisation) : la gestion est désactivée.'), JSON_UNESCAPED_UNICODE) ?>,
      truncated:      <?= json_encode(t('Liste tronquée : seuls les premiers membres sont affichés.'), JSON_UNESCAPED_UNICODE) ?>,
      noFunction:     <?= json_encode(t('Aucune fonction définie'), JSON_UNESCAPED_UNICODE) ?>,
      noService:      <?= json_encode(t('Aucun service (fonction transverse)'), JSON_UNESCAPED_UNICODE) ?>,
      noServices:     <?= json_encode(t('Aucun service ni fonction pour l’instant.'), JSON_UNESCAPED_UNICODE) ?>,
      noFunctions:    <?= json_encode(t('Aucune fonction dans ce service.'), JSON_UNESCAPED_UNICODE) ?>,
      newService:     <?= json_encode(t('Nouveau service'), JSON_UNESCAPED_UNICODE) ?>,
      newFunction:    <?= json_encode(t('Nouvelle fonction'), JSON_UNESCAPED_UNICODE) ?>,
      newSub:         <?= json_encode(t('Nouvelle sous-fonction'), JSON_UNESCAPED_UNICODE) ?>,
      editService:    <?= json_encode(t('Modifier le service'), JSON_UNESCAPED_UNICODE) ?>,
      editFunction:   <?= json_encode(t('Modifier la fonction'), JSON_UNESCAPED_UNICODE) ?>,
      serviceHint:    <?= json_encode(t('Un service regroupe des fonctions. Ses droits s’appliquent à toutes ses fonctions.'), JSON_UNESCAPED_UNICODE) ?>,
      globalHint:     <?= json_encode(t('Sans service, la fonction est rangée dans le service « Global ».'), JSON_UNESCAPED_UNICODE) ?>,
      subOf:          <?= json_encode(t('Sous-fonction de'), JSON_UNESCAPED_UNICODE) ?>,
      displayedAs:    <?= json_encode(t('Affichée :'), JSON_UNESCAPED_UNICODE) ?>,
      inherited:      <?= json_encode(t('hérité'), JSON_UNESCAPED_UNICODE) ?>,
      notHeld:        <?= json_encode(t('vous ne détenez pas ce droit'), JSON_UNESCAPED_UNICODE) ?>,
      noRight:        <?= json_encode(t('Aucun droit particulier'), JSON_UNESCAPED_UNICODE) ?>,
      members:        <?= json_encode(t('membre(s)'), JSON_UNESCAPED_UNICODE) ?>,
      functions:      <?= json_encode(t('fonction(s)'), JSON_UNESCAPED_UNICODE) ?>,
      edit:           <?= json_encode(t('Modifier'), JSON_UNESCAPED_UNICODE) ?>,
      remove:         <?= json_encode(t('Supprimer'), JSON_UNESCAPED_UNICODE) ?>,
      addSub:         <?= json_encode(t('Ajouter une sous-fonction'), JSON_UNESCAPED_UNICODE) ?>,
      addFunction:    <?= json_encode(t('Ajouter une fonction'), JSON_UNESCAPED_UNICODE) ?>,
      assign:         <?= json_encode(t('Attribuer'), JSON_UNESCAPED_UNICODE) ?>,
      assignTo:       <?= json_encode(t('Membre :'), JSON_UNESCAPED_UNICODE) ?>,
      unassign:       <?= json_encode(t('Retirer cette fonction'), JSON_UNESCAPED_UNICODE) ?>,
      noAssignable:   <?= json_encode(t('Aucune fonction attribuable : créez d’abord une fonction.'), JSON_UNESCAPED_UNICODE) ?>,
      confirmDelTitle:<?= json_encode(t('Supprimer ce groupe ?'), JSON_UNESCAPED_UNICODE) ?>,
      confirmDelText: <?= json_encode(t('« :name » sera supprimé ainsi que ses sous-fonctions. Les membres concernés perdront ces fonctions et les droits associés.'), JSON_UNESCAPED_UNICODE) ?>,
      confirmUnTitle: <?= json_encode(t('Retirer la fonction ?'), JSON_UNESCAPED_UNICODE) ?>,
      confirmUnText:  <?= json_encode(t(':member perdra la fonction « :fn » et les droits associés.'), JSON_UNESCAPED_UNICODE) ?>,
      you:            <?= json_encode(t('vous'), JSON_UNESCAPED_UNICODE) ?>,
      nameRequired:   <?= json_encode(t('Le nom est obligatoire.'), JSON_UNESCAPED_UNICODE) ?>,
      saving:         <?= json_encode(t('Enregistrement…'), JSON_UNESCAPED_UNICODE) ?>,
      yourRights:     <?= json_encode(t('Vos droits :'), JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    var I18N = window.TEAM_I18N || {};
    var API  = window.TEAM_API_URL || "../data/portail_api.php";
    var CSRF = window.TEAM_CSRF || window.NOTIF_CSRF || '';

    function norm(s){ return String(s==null?'':s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,''); }
    function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
    function fmt(s, map){ return String(s||'').replace(/:([a-z]+)/g, function(m,k){ return (map && k in map) ? map[k] : m; }); }

    var ICON = {
      edit:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
      plus:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>',
      trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>'
    };

    function post(action, data){
      var body = new URLSearchParams();
      Object.keys(data || {}).forEach(function (k){
        var v = data[k];
        if (Array.isArray(v)) v.forEach(function (x){ body.append(k + '[]', x); });
        else if (v !== undefined && v !== null) body.append(k, v);
      });
      return fetch(API + '?action=' + encodeURIComponent(action), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
        body: body.toString()
      }).then(function (res){
        return res.json().catch(function(){ return null; }).then(function (d){
          if (!d || !d.ok) throw new Error((d && d.error) || ('HTTP ' + res.status));
          return d;
        });
      });
    }

    ready(function () {
      var input    = document.getElementById('membersSearchInput');
      var tbody    = document.getElementById('membersTableBody');
      var counter  = document.getElementById('membersCount');
      var editBadge= document.getElementById('editModeBadge');
      var orgBadge = document.getElementById('orgBadge');
      var structEl = document.getElementById('structureName');
      var alerts   = document.getElementById('teamAlerts');
      var svcSection = document.getElementById('servicesSection');
      var svcGrid  = document.getElementById('servicesGrid');
      var svcEmpty = document.getElementById('servicesEmpty');
      var svcTools = document.getElementById('servicesToolbar');
      var actionsTh= document.querySelector('[data-col-actions]');
      if (!tbody) return;

      var state = {
        members: [], structure: '', organization: null, truncated: false,
        groups: [], byId: {}, catalog: [], me: { perms: [], can_manage: false, can_assign: false },
        supported: true, globalName: 'Global'
      };
      var suffix = counter ? (counter.getAttribute('data-suffix') || '') : '';
      function cols(){ return state.me.can_assign ? 5 : 4; }

      function setCounter(n){ if (counter) counter.textContent = (n==null?'…':n) + (suffix ? ' ' + suffix : ''); }

      function showAlerts(list){
        if (!alerts) return;
        alerts.innerHTML = '';
        (list || []).forEach(function (a){
          if (!a || !a.text) return;
          var div = document.createElement('div');
          div.className = 'rounded-xl border px-6 py-4 text-sm ' + (a.error
            ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/30 dark:bg-red-950/30 dark:text-red-300'
            : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/30 dark:bg-amber-950/30 dark:text-amber-300');
          div.textContent = a.text;
          alerts.appendChild(div);
        });
      }

      function stateRow(text, isError){
        return '<tr class="members-state' + (isError ? ' members-state--error' : '') + '"><td colspan="' + cols() + '">' + esc(text) + '</td></tr>';
      }

      // ── Droits ────────────────────────────────────────────────────────────
      var IMPLIED = { 'teams.manage': ['teams.assign'] };
      function expand(keys){
        var set = {}; (keys || []).forEach(function (k){ set[k] = true; });
        if (set['*']) return ['*'];
        Object.keys(IMPLIED).forEach(function (k){ if (set[k]) IMPLIED[k].forEach(function (i){ set[i] = true; }); });
        return Object.keys(set);
      }
      function has(keys, key){
        keys = expand(keys);
        if (keys.indexOf('*') !== -1 || keys.indexOf(key) !== -1) return true;
        return keys.some(function (k){ return k.slice(-2) === '.*' && key.indexOf(k.slice(0, -1)) === 0; });
      }
      function covers(held, needed){
        if (expand(held).indexOf('*') !== -1) return true;
        return expand(needed).every(function (k){ return k !== '*' && has(held, k); });
      }
      function permLabel(k){
        for (var i = 0; i < state.catalog.length; i++) if (state.catalog[i].key === k) return state.catalog[i].label;
        return k;
      }
      function permChips(own, inherited){
        var html = '';
        var ownSet = {}; (own || []).forEach(function (k){ ownSet[k] = true; });
        (own || []).forEach(function (k){
          html += '<span class="chip ' + (k === '*' ? 'chip--admin' : 'chip--perm') + '" title="' + esc(k) + '">' + esc(permLabel(k)) + '</span>';
        });
        (inherited || []).forEach(function (k){
          if (ownSet[k]) return;
          html += '<span class="chip chip--inherited" title="' + esc(k + ' — ' + (I18N.inherited || 'hérité')) + '">' + esc(permLabel(k)) + '</span>';
        });
        return html ? '<div class="chips">' + html + '</div>' : '';
      }

      // ── Services & fonctions ──────────────────────────────────────────────
      function childrenOf(id){ return state.groups.filter(function (g){ return g.parent_id === id; }); }
      function services(){
        var list = state.groups.filter(function (g){ return g.is_service; });
        // « Global » en dernier, le reste par ordre alphabétique
        list.sort(function (a, b){ return (a.is_global - b.is_global) || a.name.localeCompare(b.name, 'fr'); });
        return list;
      }
      function subtreeCount(id){
        var n = 0; childrenOf(id).forEach(function (c){ n += 1 + subtreeCount(c.id); }); return n;
      }
      function inheritedOf(g){
        var p = g.parent_id ? state.byId[g.parent_id] : null;
        return p ? p.effective : [];
      }

      function fnRows(parentId){
        var kids = childrenOf(parentId).sort(function (a, b){ return a.name.localeCompare(b.name, 'fr'); });
        if (!kids.length) return '';
        return kids.map(function (g){
          var actions = '';
          if (state.me.can_manage && g.editable) {
            if (g.depth + 1 < 4) actions += '<button type="button" class="tm-icon-btn" data-action="new-sub" data-id="' + esc(g.id) + '" title="' + esc(I18N.addSub) + '" aria-label="' + esc(I18N.addSub) + '">' + ICON.plus + '</button>';
            actions += '<button type="button" class="tm-icon-btn" data-action="edit" data-id="' + esc(g.id) + '" title="' + esc(I18N.edit) + '" aria-label="' + esc(I18N.edit) + '">' + ICON.edit + '</button>';
            actions += '<button type="button" class="tm-icon-btn tm-icon-btn--danger" data-action="delete" data-id="' + esc(g.id) + '" title="' + esc(I18N.remove) + '" aria-label="' + esc(I18N.remove) + '">' + ICON.trash + '</button>';
          }
          var sub = fnRows(g.id);
          return '<div>' +
            '<div class="fn-row">' +
              '<div class="min-w-0">' +
                '<div class="fn-name">' + esc(g.label) + '</div>' +
                '<div class="fn-meta">' + g.member_count + ' ' + esc(I18N.members) + (g.depth > 1 ? ' · ' + esc(g.path.join(' / ')) : '') + '</div>' +
                permChips(g.perm, inheritedOf(g)) +
              '</div>' +
              (actions ? '<div class="fn-actions">' + actions + '</div>' : '') +
            '</div>' +
            (sub ? '<div class="fn-tree">' + sub + '</div>' : '') +
          '</div>';
        }).join('');
      }

      function renderServices(){
        if (!svcSection) return;
        svcSection.hidden = !state.supported;
        if (!state.supported) return;
        if (svcTools) svcTools.hidden = !state.me.can_manage;

        var list = services();
        if (!list.length) {
          svcGrid.innerHTML = '';
          svcEmpty.hidden = false;
          svcEmpty.textContent = I18N.noServices || '';
          return;
        }
        svcEmpty.hidden = true;
        svcGrid.innerHTML = list.map(function (s){
          var n = subtreeCount(s.id);
          var total = 0;
          (function walk(id){ childrenOf(id).forEach(function (c){ total += c.member_count; walk(c.id); }); })(s.id);
          var actions = '';
          if (state.me.can_manage && s.editable) {
            actions += '<button type="button" class="tm-icon-btn" data-action="new-sub" data-id="' + esc(s.id) + '" title="' + esc(I18N.addFunction) + '" aria-label="' + esc(I18N.addFunction) + '">' + ICON.plus + '</button>';
            actions += '<button type="button" class="tm-icon-btn" data-action="edit" data-id="' + esc(s.id) + '" title="' + esc(I18N.edit) + '" aria-label="' + esc(I18N.edit) + '">' + ICON.edit + '</button>';
            actions += '<button type="button" class="tm-icon-btn tm-icon-btn--danger" data-action="delete" data-id="' + esc(s.id) + '" title="' + esc(I18N.remove) + '" aria-label="' + esc(I18N.remove) + '">' + ICON.trash + '</button>';
          }
          var body = fnRows(s.id);
          return '<article class="svc-card">' +
            '<div class="svc-head">' +
              '<div class="min-w-0">' +
                '<div class="svc-title">' + esc(s.name) + (s.is_global ? ' <span class="badge-soft">' + esc(I18N.noService) + '</span>' : '') + '</div>' +
                '<div class="svc-sub">' + n + ' ' + esc(I18N.functions) + ' · ' + total + ' ' + esc(I18N.members) + '</div>' +
                permChips(s.perm, []) +
              '</div>' +
              (actions ? '<div class="fn-actions">' + actions + '</div>' : '') +
            '</div>' +
            '<div class="svc-body">' + (body || '<div class="fn-empty">' + esc(I18N.noFunctions) + '</div>') + '</div>' +
          '</article>';
        }).join('');
      }

      // ── Membres ───────────────────────────────────────────────────────────
      function assignableGroups(){
        return state.groups.filter(function (g){ return !g.is_service && g.editable; });
      }

      function rowHtml(m){
        var fns = Array.isArray(m.functions) ? m.functions : [];
        var hay = [m.name, m.secondary, m.function, m.status_label, m.permission, (m.perm_labels || []).join(' '),
                   fns.map(function (f){ return f.label + ' ' + f.service; }).join(' ')].join(' ').toLowerCase();

        var fnCell;
        if (fns.length) {
          fnCell = '<div class="chips" style="margin-top:0">' + fns.map(function (f){
            var g = state.byId[f.id];
            var canRemove = state.me.can_assign && g && g.editable;
            return '<span class="chip chip--fn' + (f.is_global ? ' chip--global' : '') + '" title="' + esc(f.path) + '">' + esc(f.label) +
              (canRemove ? '<button type="button" data-action="unassign" data-member="' + esc(m.id) + '" data-id="' + esc(f.id) + '" aria-label="' + esc(I18N.unassign) + '" title="' + esc(I18N.unassign) + '">×</button>' : '') +
              '</span>';
          }).join('') + '</div>';
          var svcs = {}; fns.forEach(function (f){ if (!f.is_global) svcs[f.service] = true; });
          var svcList = Object.keys(svcs);
          if (svcList.length) fnCell += '<p class="text-foreground block text-xs mt-1" style="opacity:.7">' + esc(svcList.join(', ')) + '</p>';
        } else {
          fnCell = '<p class="text-foreground block text-sm" style="opacity:.7">' + esc(m.legacy_function || I18N.noFunction || '') + '</p>';
        }

        var perms = Array.isArray(m.perms) ? m.perms : [];
        var permCell;
        if (fns.length) {
          var title = (m.perm_labels || []).join('\n');
          permCell = '<p class="text-foreground block text-sm" title="' + esc(title) + '">' + esc(m.permission) + '</p>' +
            (perms.length && perms.indexOf('*') === -1 ? permChips(perms.slice(0, 3), []) + (perms.length > 3 ? '<span class="fn-meta">+' + (perms.length - 3) + '</span>' : '') : '');
        } else {
          permCell = '<p class="text-foreground block text-sm">' + esc(m.permission) + '</p>';
        }

        var actionCell = '';
        if (state.me.can_assign) {
          actionCell = '<td class="border-surface border-b p-4 align-top">' +
            (assignableGroups().length ? '<button type="button" class="tm-btn tm-btn--sm" data-action="assign" data-member="' + esc(m.id) + '">+ ' + esc(I18N.assign) + '</button>' : '') +
          '</td>';
        }

        return '<tr data-search="' + esc(hay) + '">' +
          '<td class="border-surface border-b p-4 align-top">' +
            '<div class="flex items-center gap-3">' +
              '<span class="relative flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted text-xs font-semibold">' + esc(m.initials) + '</span>' +
              '<div><p class="text-default block text-sm font-semibold">' + esc(m.name) + (m.is_me ? ' <span class="fn-meta">(' + esc(I18N.you) + ')</span>' : '') + '</p>' +
              '<p class="text-foreground block text-sm">' + esc(m.secondary) + '</p></div>' +
            '</div>' +
          '</td>' +
          '<td class="border-surface border-b p-4 align-top" style="max-width:26rem;white-space:normal">' + fnCell + '</td>' +
          '<td class="border-surface border-b p-4 align-top"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 ' + esc(m.status_class) + '">' + esc(m.status_label) + '</span></td>' +
          '<td class="border-surface border-b p-4 align-top" style="max-width:20rem;white-space:normal">' + permCell + '</td>' +
          actionCell +
        '</tr>';
      }

      function dataRows(){ return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search]')); }

      function applyFilter(){
        var rows = dataRows();
        if (!rows.length) return;
        var q = input ? norm(input.value.trim()) : '';
        var tokens = q ? q.split(/\s+/) : [];
        var visible = 0;
        rows.forEach(function (row){
          var hay = norm(row.getAttribute('data-search') || '');
          var match = tokens.every(function (t){ return hay.indexOf(t) !== -1; });
          row.hidden = !match;
          if (match) visible++;
        });
        var noRes = document.getElementById('membersNoResults');
        if (noRes) noRes.hidden = (visible !== 0);
        setCounter(visible);
      }

      function renderRows(){
        if (actionsTh) actionsTh.hidden = !state.me.can_assign;
        var list = state.members;
        if (!list.length) {
          tbody.innerHTML = stateRow(I18N.empty || 'Aucun membre.', false);
          setCounter(0);
          return;
        }
        tbody.innerHTML = list.map(rowHtml).join('') +
          '<tr id="membersNoResults" class="members-state" hidden><td colspan="' + cols() + '">' + esc(I18N.noResults || '') + '</td></tr>';
        setCounter(list.length);
        applyFilter();
      }

      function updateHeader(){
        if (structEl) structEl.textContent = state.structure ? (' : ' + state.structure) : '';

        if (orgBadge) {
          var org = state.organization || {};
          var tech = org.alias || org.name || '';
          if (tech && tech !== state.structure) { orgBadge.textContent = tech; orgBadge.hidden = false; }
          else { orgBadge.textContent = ''; orgBadge.hidden = true; }
        }

        if (editBadge) {
          var me = state.me || {};
          var text = I18N.readOnly, cls = 'badge-soft';
          if (me.bootstrap)       { text = I18N.bootstrap; cls = 'badge-soft badge-soft--warn'; }
          else if (me.can_manage) { text = I18N.manager;   cls = 'badge-soft badge-soft--ok'; }
          else if (me.can_assign) { text = I18N.assigner;  cls = 'badge-soft badge-soft--ok'; }
          editBadge.textContent = text;
          editBadge.className = cls;
          editBadge.title = (I18N.yourRights || '') + ' ' + ((me.labels && me.labels.length) ? me.labels.join(', ') : (I18N.noRight || ''));
        }
      }

      function load(){
        tbody.innerHTML = stateRow(I18N.loading || 'Chargement…', false);
        setCounter(null);
        return fetch(API + '?action=team.list', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (res){ return res.json().catch(function(){ return null; }).then(function (data){ return { ok: res.ok, data: data }; }); })
        .then(function (r){
          var data = r.data;
          if (!r.ok || !data || !data.ok) {
            var msg = (data && data.error) ? data.error : (I18N.error || 'Erreur.');
            tbody.innerHTML = stateRow(msg, true);
            setCounter(null);
            updateHeader();
            return;
          }
          state.members      = Array.isArray(data.members) ? data.members : [];
          state.structure    = data.structure || '';
          state.organization = data.organization || null;
          state.truncated    = !!data.truncated;
          state.groups       = Array.isArray(data.groups) ? data.groups : [];
          state.byId         = {}; state.groups.forEach(function (g){ state.byId[g.id] = g; });
          state.catalog      = Array.isArray(data.catalog) ? data.catalog : [];
          state.me           = data.me || { perms: [] };
          state.supported    = data.groups_supported !== false;
          state.globalName   = data.global_service || 'Global';
          updateHeader();
          renderServices();
          renderRows();
          var list = [];
          if (!state.supported) list.push({ text: I18N.unsupported });
          if (state.me.bootstrap) list.push({ text: I18N.bootstrapHelp });
          if (state.truncated) list.push({ text: I18N.truncated });
          showAlerts(list);
        })
        .catch(function (){ tbody.innerHTML = stateRow(I18N.error || 'Impossible de charger les membres.', true); setCounter(null); });
      }

      // ── Modales ───────────────────────────────────────────────────────────
      function openModal(el){ el.classList.add('is-open'); var f = el.querySelector('input[type=text],select'); if (f) setTimeout(function(){ f.focus(); }, 30); }
      function closeModal(el){ el.classList.remove('is-open'); }
      ['groupModal', 'assignModal', 'confirmModal'].forEach(function (id){
        var el = document.getElementById(id);
        el.addEventListener('click', function (e){ if (e.target === el || e.target.closest('[data-close]')) closeModal(el); });
      });
      document.addEventListener('keydown', function (e){
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.tm-modal.is-open').forEach(closeModal);
      });
      function setErr(el, msg){ el.textContent = msg || ''; el.hidden = !msg; }
      function busy(btn, on){ btn.disabled = !!on; if (on) { btn.dataset.label = btn.textContent; btn.textContent = I18N.saving || '…'; } else if (btn.dataset.label) { btn.textContent = btn.dataset.label; } }

      // Groupe : création / édition
      var gm = {
        el: document.getElementById('groupModal'),
        title: document.getElementById('groupModalTitle'),
        sub: document.getElementById('groupModalSub'),
        parentField: document.getElementById('groupParentField'),
        parent: document.getElementById('groupParent'),
        parentHint: document.getElementById('groupParentHint'),
        name: document.getElementById('groupName'),
        preview: document.getElementById('groupNamePreview'),
        perms: document.getElementById('groupPerms'),
        err: document.getElementById('groupError'),
        save: document.getElementById('groupSave'),
        mode: null, groupId: '', fixedParent: ''
      };

      function currentParentId(){
        if (gm.mode === 'edit') return (state.byId[gm.groupId] || {}).parent_id || '';
        if (gm.mode === 'service') return '';
        if (gm.fixedParent) return gm.fixedParent;
        var v = gm.parent.value;
        if (v === '__global__') { var gl = services().filter(function (s){ return s.is_global; })[0]; return gl ? gl.id : ''; }
        return v;
      }

      function renderPermList(selected){
        var pid = currentParentId();
        var inherited = pid && state.byId[pid] ? state.byId[pid].effective : [];
        var mine = state.me.perms || [];
        var html = '', lastGroup = null;
        state.catalog.forEach(function (c){
          if (c.group !== lastGroup) { html += '<div class="perm-group">' + esc(c.group) + '</div>'; lastGroup = c.group; }
          var isInh = has(inherited, c.key) && c.key !== '*' ? true : (c.key === '*' && inherited.indexOf('*') !== -1);
          var canGive = covers(mine, [c.key]);
          var checked = isInh || selected.indexOf(c.key) !== -1;
          var locked = isInh || !canGive;
          var note = isInh ? (I18N.inherited || '') : (!canGive ? (I18N.notHeld || '') : '');
          html += '<label class="perm-item' + (locked ? ' is-locked' : '') + '">' +
            '<input type="checkbox" value="' + esc(c.key) + '"' + (checked ? ' checked' : '') + (locked ? ' disabled' : '') + (isInh ? ' data-inherited="1"' : '') + '>' +
            '<span>' + esc(c.label) + ' <code>' + esc(c.key) + '</code>' + (note ? '<br><span class="perm-note">' + esc(note) + '</span>' : '') + '</span>' +
          '</label>';
        });
        // Clés inconnues du catalogue (saisies dans la console Keycloak) : conservées.
        selected.forEach(function (k){
          if (state.catalog.some(function (c){ return c.key === k; })) return;
          html += '<label class="perm-item"><input type="checkbox" value="' + esc(k) + '" checked' + (covers(mine, [k]) ? '' : ' disabled') + '><span><code>' + esc(k) + '</code></span></label>';
        });
        gm.perms.innerHTML = html;
      }

      function selectedPerms(){
        var out = [];
        gm.perms.querySelectorAll('input[type=checkbox]').forEach(function (cb){
          // Hérités : déjà acquis via le parent, inutile de les recopier.
          if (cb.checked && !cb.hasAttribute('data-inherited')) out.push(cb.value);
          // Clé détenue par le groupe mais non modifiable par l'acteur : on la garde.
          else if (cb.checked && cb.disabled && !cb.hasAttribute('data-inherited')) out.push(cb.value);
        });
        return out;
      }

      function updatePreview(){
        var name = gm.name.value.trim();
        if (gm.mode === 'service' || (gm.mode === 'edit' && (state.byId[gm.groupId] || {}).is_service)) { gm.preview.textContent = ''; return; }
        var pid = currentParentId(); var p = pid ? state.byId[pid] : null;
        var svc = p ? p.service : (gm.parent.value === '__global__' ? state.globalName : '');
        var isGlobal = !p ? gm.parent.value === '__global__' : p.is_global;
        gm.preview.textContent = name ? ((I18N.displayedAs || '') + ' ' + name + (isGlobal || !svc ? '' : ' ' + svc)) : '';
      }

      function openGroupModal(mode, id){
        gm.mode = mode; gm.groupId = ''; gm.fixedParent = '';
        setErr(gm.err, '');
        var selected = [];
        gm.parentField.hidden = true;
        gm.name.value = '';
        gm.sub.textContent = '';

        if (mode === 'service') {
          gm.title.textContent = I18N.newService;
          gm.sub.textContent = I18N.serviceHint;
        } else if (mode === 'function') {
          gm.title.textContent = I18N.newFunction;
          gm.parentField.hidden = false;
          var opts = '<option value="__global__">' + esc(I18N.noService) + '</option>';
          services().forEach(function (s){ if (!s.is_global && s.editable) opts += '<option value="' + esc(s.id) + '">' + esc(s.name) + '</option>'; });
          gm.parent.innerHTML = opts;
          var firstReal = services().filter(function (s){ return !s.is_global && s.editable; })[0];
          gm.parent.value = firstReal ? firstReal.id : '__global__';
          gm.parentHint.textContent = I18N.globalHint;
        } else if (mode === 'sub') {
          var p = state.byId[id];
          gm.fixedParent = id;
          gm.title.textContent = p && p.is_service ? I18N.newFunction : I18N.newSub;
          gm.sub.textContent = p ? ((p.is_service ? '' : (I18N.subOf || '') + ' ') + (p.is_service ? p.name : p.label)) : '';
        } else if (mode === 'edit') {
          var g = state.byId[id];
          if (!g) return;
          gm.groupId = id;
          gm.title.textContent = g.is_service ? I18N.editService : I18N.editFunction;
          gm.sub.textContent = g.path.join(' / ');
          gm.name.value = g.name;
          gm.name.disabled = g.is_service && g.is_global;
          selected = (g.perm || []).slice();
        }
        if (mode !== 'edit') gm.name.disabled = false;
        renderPermList(selected);
        updatePreview();
        openModal(gm.el);
      }
      gm.name.addEventListener('input', updatePreview);
      gm.parent.addEventListener('change', function (){ renderPermList(selectedPerms()); updatePreview(); });

      gm.save.addEventListener('click', function (){
        var name = gm.name.value.trim();
        if (!name) { setErr(gm.err, I18N.nameRequired); return; }
        var perm = selectedPerms();
        var action, data;
        if (gm.mode === 'edit') {
          action = 'team.group.update';
          data = { group_id: gm.groupId, perm: perm, perm_set: '1' };
          if (!gm.name.disabled) data.name = name;
        } else {
          action = 'team.group.create';
          data = { name: name, perm: perm };
          if (gm.mode === 'service') { data.kind = 'service'; }
          else if (gm.mode === 'sub') { data.kind = 'function'; data.parent_id = gm.fixedParent; }
          else { data.kind = 'function'; data.parent_id = gm.parent.value; }
        }
        setErr(gm.err, ''); busy(gm.save, true);
        post(action, data)
          .then(function (){ closeModal(gm.el); return load(); })
          .catch(function (e){ setErr(gm.err, e.message); })
          .then(function (){ busy(gm.save, false); });
      });

      // Attribution
      var am = {
        el: document.getElementById('assignModal'), sub: document.getElementById('assignModalSub'),
        select: document.getElementById('assignGroup'), preview: document.getElementById('assignPreview'),
        err: document.getElementById('assignError'), save: document.getElementById('assignSave'), member: null
      };
      function renderAssignPreview(){
        var g = state.byId[am.select.value];
        am.preview.innerHTML = g ? permChips([], g.effective) : '';
      }
      function openAssign(memberId){
        var m = state.members.filter(function (x){ return x.id === memberId; })[0];
        if (!m) return;
        am.member = m;
        setErr(am.err, '');
        am.sub.textContent = (I18N.assignTo || '') + ' ' + m.name;
        var owned = {}; (m.functions || []).forEach(function (f){ owned[f.id] = true; });
        var html = '';
        services().forEach(function (s){
          var opts = assignableGroups().filter(function (g){ return g.service_id === s.id && !owned[g.id]; });
          if (!opts.length) return;
          html += '<optgroup label="' + esc(s.is_global ? I18N.noService : s.name) + '">' +
            opts.map(function (g){ return '<option value="' + esc(g.id) + '">' + esc(g.depth > 1 ? g.path.slice(1).join(' / ') + (s.is_global ? '' : ' ' + s.name) : g.label) + '</option>'; }).join('') +
          '</optgroup>';
        });
        am.select.innerHTML = html;
        am.save.disabled = !html;
        if (!html) setErr(am.err, I18N.noAssignable);
        renderAssignPreview();
        openModal(am.el);
      }
      am.select.addEventListener('change', renderAssignPreview);
      am.save.addEventListener('click', function (){
        if (!am.member || !am.select.value) return;
        setErr(am.err, ''); busy(am.save, true);
        post('team.member.assign', { member_id: am.member.id, group_id: am.select.value })
          .then(function (){ closeModal(am.el); return load(); })
          .catch(function (e){ setErr(am.err, e.message); })
          .then(function (){ busy(am.save, false); });
      });

      // Confirmation
      var cm = { el: document.getElementById('confirmModal'), title: document.getElementById('confirmTitle'), text: document.getElementById('confirmText'), err: document.getElementById('confirmError'), ok: document.getElementById('confirmOk'), run: null };
      function confirmAction(title, text, run){
        cm.title.textContent = title; cm.text.textContent = text; cm.run = run; setErr(cm.err, ''); openModal(cm.el);
      }
      cm.ok.addEventListener('click', function (){
        if (!cm.run) return;
        busy(cm.ok, true);
        cm.run()
          .then(function (){ closeModal(cm.el); return load(); })
          .catch(function (e){ setErr(cm.err, e.message); })
          .then(function (){ busy(cm.ok, false); });
      });

      // Délégation des clics
      document.addEventListener('click', function (e){
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var act = btn.getAttribute('data-action'), id = btn.getAttribute('data-id');
        if (act === 'new-service')  return openGroupModal('service');
        if (act === 'new-function') return openGroupModal('function');
        if (act === 'new-sub')      return openGroupModal('sub', id);
        if (act === 'edit')         return openGroupModal('edit', id);
        if (act === 'assign')       return openAssign(btn.getAttribute('data-member'));
        if (act === 'delete') {
          var g = state.byId[id]; if (!g) return;
          return confirmAction(I18N.confirmDelTitle, fmt(I18N.confirmDelText, { name: g.is_service ? g.name : g.label }), function (){
            return post('team.group.delete', { group_id: id });
          });
        }
        if (act === 'unassign') {
          var mid = btn.getAttribute('data-member');
          var m = state.members.filter(function (x){ return x.id === mid; })[0];
          var f = state.byId[id];
          return confirmAction(I18N.confirmUnTitle, fmt(I18N.confirmUnText, { member: m ? m.name : '', fn: f ? f.label : '' }), function (){
            return post('team.member.unassign', { member_id: mid, group_id: id });
          });
        }
      });

      if (input) {
        input.addEventListener('input', applyFilter);
        input.addEventListener('search', applyFilter);
      }

      load();
    });
  })();
  </script>

  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../assets/js/services_menu.js" defer></script>
</body>
</html>
