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

   GABARIT VISUEL — toutes les cartes suivent la même structure :

     section.acc-card
       header.acc-card__head   tuile d'icône + titre + sous-titre [+ pastille]
       .acc-card__body         lignes .acc-row (filet entre elles) ou
                               formulaire .acc-fields
       .acc-note               encadré d'information (optionnel)
       footer.acc-card__foot   actions alignées à droite (optionnel)

   Une ligne .acc-row = tuile d'icône · titre (+ pastille) · métadonnées ·
   description · action à droite. Les lignes de sessions sont produites
   par le JS avec EXACTEMENT le même balisage — voir rowHtml() plus bas,
   et acc_icon() pour le jeu d'icônes partagé PHP/JS.
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

/**
 * Jeu d'icônes de la page (tracé type Lucide, 24×24, currentColor).
 *
 * Centralisé ici pour que PHP et le JS partagent les mêmes tracés : les
 * lignes de sessions sont rendues côté navigateur et doivent être
 * indiscernables des lignes rendues côté serveur.
 */
function acc_icon(string $name, string $cls = 'acc-i'): string
{
    $p = [
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        'at'       => '<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 5-3.4 8.4-8 9-4.6-.6-8-4-8-9V6l8-3Z"/>',
        'shieldOk' => '<path d="M12 3l8 3v6c0 5-3.4 8.4-8 9-4.6-.6-8-4-8-9V6l8-3Z"/><path d="m9 12 2 2 4-4"/>',
        'lock'     => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'key'      => '<circle cx="7.5" cy="15.5" r="3.5"/><path d="m10 13 9-9 3 3-2 2-2-2-2 2 2 2-3 3-2-2"/>',
        'phone'    => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
        'laptop'   => '<rect x="4" y="5" width="16" height="11" rx="2"/><path d="M2 19h20"/>',
        'mobile'   => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
        'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18Z"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'building' => '<path d="M5 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16"/><path d="M16 9h3a2 2 0 0 1 2 2v10"/><path d="M3 21h18M9 7h2M9 11h2M9 15h2"/>',
        'x'        => '<path d="m6 6 12 12M18 6 6 18"/>',
        'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
        'cross'    => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
        'alert'    => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
        'eye'      => '<path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.5"/>',
        'eyeOff'   => '<path d="M10.6 6.2A9.9 9.9 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3.1 3.6M6.5 7.9A17 17 0 0 0 2 12s3.6 6 10 6a9.6 9.6 0 0 0 3.6-.7"/><path d="m3 3 18 18"/>',
    ];
    $d = $p[$name] ?? $p['alert'];
    return '<svg class="' . h($cls) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $d . '</svg>';
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

    /* ---- Jetons locaux ------------------------------------------ */
    .acc {
      --acc-line:#e8e8e8; --acc-ink:#0f0f0f; --acc-muted:#6b7280;
      --acc-tile:#f4f4f5; --acc-soft:#fafafa;
      --acc-ok:#16a34a; --acc-ok-bg:#f0fdf4; --acc-ok-line:#bbf7d0;
      --acc-warn:#b45309; --acc-warn-bg:#fffbeb; --acc-warn-line:#fde68a;
      --acc-danger:#dc2626; --acc-danger-line:#fecaca;
      max-width:64rem;

      /* Police du site. connexion-style.css la pose sur « html » avec
         exactement cette expression (via --default-font-family). On la
         répète ici pour que la page y soit explicitement alignée, et pour
         donner une source sûre aux éléments de formulaire, qui n'héritent
         jamais de la police par défaut.
         var(--font-sans, …) : si le thème finit par définir vraiment la
         variable (« Geist »), la page suivra sans retouche ; tant qu'elle
         reste cyclique elle vaut « invalide garanti », et c'est le repli
         ci-dessous qui s'applique — la pile du reste du portail. */
      font-family: var(--font-sans, ui-sans-serif, system-ui, sans-serif,
                   "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji");
    }
    .acc, .acc * { box-sizing:border-box; }
    /* ⚠️ .acc-btn et .acc-row portent un display explicite, qui l'emporte
       sur le display:none du navigateur pour [hidden]. Sans cette règle,
       « Configurer » ET « Désactiver » s'affichent en même temps, et le
       formulaire de confirmation 2FA reste visible en permanence. */
    .acc [hidden] { display:none !important; }

    /* ---- Carte --------------------------------------------------- */
    .acc-card {
      background:#fff;border:1px solid var(--acc-line);border-radius:1rem;
      padding:1.6rem 1.75rem;margin-bottom:1.5rem;
      box-shadow:0 1px 2px rgba(16,24,40,.04);
    }
    .acc-card__head {
      display:flex;align-items:flex-start;gap:1rem;
      padding-bottom:1.1rem;border-bottom:1px solid var(--acc-line);
    }
    .acc-card__icon {
      flex:none;display:inline-flex;align-items:center;justify-content:center;
      width:2.9rem;height:2.9rem;border-radius:.7rem;
      background:var(--acc-tile);color:var(--acc-ink);
    }
    .acc-card__icon svg {width:1.35rem;height:1.35rem;}
    .acc-card__titles {flex:1 1 auto;min-width:0;}
    .acc-card__titles h2 {margin:0;font-size:1.32rem;font-weight:700;letter-spacing:-.01em;color:var(--acc-ink);line-height:1.25;}
    .acc-card__titles p  {margin:.25rem 0 0;font-size:.92rem;color:var(--acc-muted);}
    .acc-card__head > .acc-pill, .acc-card__head > .acc-btn {flex:none;margin-top:.3rem;}
    .acc-card__body {padding-top:.35rem;}
    .acc-card__foot {
      display:flex;justify-content:flex-end;gap:.65rem;flex-wrap:wrap;
      padding-top:1.1rem;margin-top:1.1rem;border-top:1px solid var(--acc-line);
    }

    /* ---- Ligne --------------------------------------------------- */
    .acc-row {
      display:flex;align-items:flex-start;gap:1rem;
      padding:1.15rem 0;border-top:1px solid var(--acc-line);
    }
    .acc-row:first-child {border-top:0;}
    .acc-row__icon {
      flex:none;display:inline-flex;align-items:center;justify-content:center;
      width:2.6rem;height:2.6rem;border-radius:.65rem;
      background:var(--acc-tile);color:var(--acc-ink);
    }
    .acc-row__icon svg {width:1.2rem;height:1.2rem;}
    /* flex-basis 0 (et non auto) : sur mobile, .acc-row passe en wrap pour
       faire descendre le bouton ; avec une base « auto » c'est le bloc de
       texte qui passerait à la ligne, laissant la tuile d'icône seule. */
    .acc-row__main {flex:1 1 0;min-width:0;}
    .acc-row__title {
      margin:0;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;
      font-size:1rem;font-weight:700;color:var(--acc-ink);
    }
    .acc-row__desc {margin:.3rem 0 0;font-size:.9rem;color:var(--acc-muted);line-height:1.5;}
    .acc-row__meta {
      margin:.3rem 0 0;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;
      font-size:.86rem;color:var(--acc-muted);
    }
    .acc-row__meta svg {width:.95rem;height:.95rem;flex:none;}
    .acc-row__state {margin:.35rem 0 0;font-size:.86rem;color:var(--acc-muted);}
    .acc-row__action {flex:none;display:flex;align-items:center;gap:.5rem;padding-top:.15rem;}

    /* ---- Pastilles ----------------------------------------------- */
    .acc-pill {
      display:inline-flex;align-items:center;gap:.28rem;
      border:1px solid var(--acc-line);border-radius:999px;
      padding:.14rem .6rem;font-size:.74rem;font-weight:600;
      color:var(--acc-muted);background:#fff;white-space:nowrap;
    }
    .acc-pill svg {width:.8rem;height:.8rem;}
    .acc-pill--on   {color:var(--acc-ok);   border-color:var(--acc-ok-line);   background:var(--acc-ok-bg);}
    .acc-pill--warn {color:var(--acc-warn); border-color:var(--acc-warn-line); background:var(--acc-warn-bg);}
    .acc-pill--reco {color:#7c5e10;border-color:#f0dfae;background:#fdf8ec;}

    /* ---- Boutons ------------------------------------------------- */
    .acc-btn {
      display:inline-flex;align-items:center;justify-content:center;gap:.45rem;
      border:1px solid transparent;border-radius:.55rem;
      padding:.52rem 1.05rem;font:inherit;font-size:.88rem;font-weight:600;
      cursor:pointer;white-space:nowrap;transition:background .15s, border-color .15s, opacity .15s;
    }
    .acc-btn svg {width:1rem;height:1rem;}
    .acc-btn:disabled {opacity:.5;cursor:not-allowed;}
    .acc-btn--primary {background:var(--acc-ink);color:#fff;}
    .acc-btn--primary:hover:not(:disabled) {background:#2a2a2a;}
    .acc-btn--ghost {background:#fff;border-color:var(--acc-line);color:var(--acc-ink);}
    .acc-btn--ghost:hover:not(:disabled) {background:var(--acc-soft);}
    .acc-btn--danger {background:var(--acc-danger);color:#fff;}
    .acc-btn--danger:hover:not(:disabled) {background:#b91c1c;}
    .acc-btn--danger-ghost {background:#fff;border-color:var(--acc-danger-line);color:var(--acc-danger);}
    .acc-btn--danger-ghost:hover:not(:disabled) {background:#fef2f2;}

    /* ---- Encadré ------------------------------------------------- */
    .acc-note {
      display:flex;align-items:flex-start;gap:.75rem;
      margin-top:1.3rem;padding:1rem 1.1rem;
      border:1px solid var(--acc-line);border-radius:.75rem;background:var(--acc-soft);
    }
    .acc-note > svg {width:1.05rem;height:1.05rem;flex:none;margin-top:.12rem;color:#2563eb;}
    .acc-note__title {margin:0;font-size:.9rem;font-weight:700;color:var(--acc-ink);}
    .acc-note__text  {margin:.3rem 0 0;font-size:.87rem;color:var(--acc-muted);line-height:1.55;}

    /* ---- Formulaires --------------------------------------------- */
    .acc-fields {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1.05rem;padding-top:1.15rem;}
    .acc-fields--one {grid-template-columns:minmax(0,1fr);}
    .acc-field {display:flex;flex-direction:column;gap:.4rem;min-width:0;}
    .acc-field > label {
      display:flex;align-items:center;gap:.4rem;
      font-size:.86rem;font-weight:600;color:var(--acc-ink);
    }
    .acc-field > label svg {width:.95rem;height:.95rem;color:var(--acc-muted);}
    .acc-field input, .acc-field select {
      width:100%;border:1px solid var(--acc-line);border-radius:.55rem;
      padding:.62rem .8rem;font:inherit;font-size:.93rem;background:#fff;color:var(--acc-ink);
      transition:border-color .15s, box-shadow .15s;
    }
    .acc-field input:focus, .acc-field select:focus {
      outline:none;border-color:#9ca3af;box-shadow:0 0 0 3px rgba(17,17,17,.07);
    }
    .acc-field input[readonly], .acc-field input:disabled, .acc-field select:disabled {
      background:var(--acc-tile);color:var(--acc-muted);cursor:not-allowed;
    }
    .acc-field .hint {font-size:.79rem;color:var(--acc-muted);}

    .acc-pw {position:relative;}
    .acc-pw input {padding-right:2.6rem;}
    .acc-pw__eye {
      position:absolute;top:50%;right:.5rem;transform:translateY(-50%);
      display:inline-flex;align-items:center;justify-content:center;
      width:1.9rem;height:1.9rem;border:0;border-radius:.4rem;
      background:transparent;color:var(--acc-muted);cursor:pointer;font:inherit;
    }
    .acc-pw__eye:hover {background:var(--acc-tile);color:var(--acc-ink);}
    .acc-pw__eye svg {width:1.05rem;height:1.05rem;}

    /* ---- Mot de passe : formulaire + exigences ------------------- */
    .acc-split {display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:2rem;padding-top:1.15rem;}
    .acc-req__head {display:flex;align-items:center;gap:.5rem;margin:0;font-size:1rem;font-weight:700;color:var(--acc-ink);}
    .acc-req__head svg {width:1.05rem;height:1.05rem;}
    .acc-req__intro {margin:.55rem 0 .9rem;font-size:.87rem;color:var(--acc-muted);line-height:1.5;}
    .acc-req__list {list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.55rem;}
    .acc-req__list li {display:flex;align-items:center;gap:.55rem;font-size:.88rem;color:var(--acc-muted);}
    .acc-req__list li svg {width:1.05rem;height:1.05rem;flex:none;}
    .acc-req__list li.is-ok {color:var(--acc-ok);}
    .acc-req__list li .i-ok {display:none;}
    .acc-req__list li.is-ok .i-ok {display:inline;}
    .acc-req__list li.is-ok .i-ko {display:none;}
    .acc-tips {margin-top:1.1rem;padding:1rem 1.1rem;border:1px solid var(--acc-line);border-radius:.75rem;background:var(--acc-soft);}
    .acc-tips h3 {margin:0 0 .55rem;font-size:.9rem;font-weight:700;color:var(--acc-ink);}
    .acc-tips ul {margin:0;padding-left:1.1rem;display:flex;flex-direction:column;gap:.35rem;}
    .acc-tips li {font-size:.86rem;color:var(--acc-muted);}

    /* ---- Divers -------------------------------------------------- */
    .acc-msg {border-radius:.6rem;border:1px solid;padding:.72rem .95rem;font-size:.87rem;margin-top:1rem;}
    .acc-msg--ok  {border-color:var(--acc-ok-line);background:var(--acc-ok-bg);color:#15803d;}
    .acc-msg--err {border-color:var(--acc-danger-line);background:#fef2f2;color:#b91c1c;}
    .acc-msg--info{border-color:var(--acc-warn-line);background:var(--acc-warn-bg);color:var(--acc-warn);}

    .acc-empty {padding:1.3rem 0;margin:0;font-size:.9rem;color:var(--acc-muted);}
    .acc-sub {
      display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;
      margin:1.6rem 0 .2rem;font-size:.78rem;font-weight:700;letter-spacing:.05em;
      text-transform:uppercase;color:var(--acc-muted);
    }
    .acc-sub:first-child {margin-top:.5rem;}

    .acc-skel {height:2.4rem;border-radius:.55rem;margin:1rem 0;background:linear-gradient(90deg,#f4f4f5 25%,#e8e8ea 37%,#f4f4f5 63%);background-size:400% 100%;animation:acc-shimmer 1.3s ease infinite;}
    @keyframes acc-shimmer {0%{background-position:100% 50%;}100%{background-position:0 50%;}}
    @media (prefers-reduced-motion: reduce) {.acc-skel {animation:none;}}

    .acc-dl {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1.05rem;padding-top:1.15rem;margin:0;}
    .acc-dl dt {font-size:.78rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:var(--acc-muted);}
    .acc-dl dd {margin:.2rem 0 0;font-size:.95rem;color:var(--acc-ink);overflow-wrap:anywhere;}

    @media (max-width: 860px) {
      .acc-split {grid-template-columns:minmax(0,1fr);gap:1.4rem;}
      .acc-fields, .acc-dl {grid-template-columns:minmax(0,1fr);}
      .acc-card {padding:1.25rem 1.1rem;}
      .acc-row {flex-wrap:wrap;}
      .acc-row__action {flex:0 0 100%;width:100%;padding-left:3.6rem;}
      .acc-row__action .acc-btn {width:100%;}
    }
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
      <div class="app-shell-offset-min-height w-full bg-surface p-6">
        <div class="acc">

          <div id="accGlobalAlert"></div>

          <!-- ============ Profil ================================== -->
          <section class="acc-card">
            <header class="acc-card__head">
              <span class="acc-card__icon"><?= acc_icon('user') ?></span>
              <div class="acc-card__titles">
                <h2><?= t('Profil') ?></h2>
                <p><?= t('Votre état civil et vos coordonnées, enregistrés dans Keycloak') ?></p>
              </div>
              <span id="acc2faBadge" class="acc-pill" hidden></span>
            </header>

            <form id="accProfileForm" novalidate>
              <div class="acc-fields">
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

              <div class="acc-msg-slot"></div>

              <footer class="acc-card__foot">
                <button type="reset" class="acc-btn acc-btn--ghost" data-acc-reset><?= t('Annuler') ?></button>
                <button type="submit" class="acc-btn acc-btn--primary"><?= t('Enregistrer') ?></button>
              </footer>
            </form>
          </section>

          <!-- ============ Identifiants ============================ -->
          <section class="acc-card">
            <header class="acc-card__head">
              <span class="acc-card__icon"><?= acc_icon('at') ?></span>
              <div class="acc-card__titles">
                <h2><?= t('Identifiants de connexion') ?></h2>
                <p><?= t('Nom d’utilisateur et adresse e-mail de votre compte') ?></p>
              </div>
              <span id="accEmailBadge" class="acc-pill" hidden></span>
            </header>

            <form id="accIdentityForm" novalidate>
              <div class="acc-fields">
                <div class="acc-field">
                  <label for="accUsername"><?= acc_icon('user') ?><?= t('Nom d’utilisateur') ?></label>
                  <input type="text" id="accUsername" name="username" maxlength="64" autocomplete="username" spellcheck="false">
                  <span class="hint" id="accUsernameHint"></span>
                </div>
                <div class="acc-field">
                  <label for="accEmail"><?= acc_icon('at') ?><?= t('Adresse e-mail') ?></label>
                  <input type="email" id="accEmail" name="email" maxlength="190" autocomplete="email" spellcheck="false">
                  <span class="hint"><?= t('Changer d’adresse déclenche un e-mail de vérification.') ?></span>
                </div>
              </div>

              <div class="acc-msg-slot"></div>

              <footer class="acc-card__foot">
                <button type="reset" class="acc-btn acc-btn--ghost" data-acc-reset><?= t('Annuler') ?></button>
                <button type="submit" class="acc-btn acc-btn--primary"><?= t('Enregistrer') ?></button>
              </footer>
            </form>
          </section>

          <!-- ============ Mot de passe ============================ -->
          <section class="acc-card">
            <header class="acc-card__head">
              <span class="acc-card__icon"><?= acc_icon('shield') ?></span>
              <div class="acc-card__titles">
                <h2><?= t('Changer le mot de passe') ?></h2>
                <p><?= t('Mettez votre mot de passe à jour pour sécuriser votre compte') ?></p>
              </div>
            </header>

            <form id="accPasswordForm" novalidate>
              <div class="acc-split">
                <div>
                  <div class="acc-fields acc-fields--one" style="padding-top:0">
                    <div class="acc-field">
                      <label for="accPwCurrent"><?= acc_icon('lock') ?><?= t('Mot de passe actuel') ?></label>
                      <div class="acc-pw">
                        <input type="password" id="accPwCurrent" name="current" autocomplete="current-password" required>
                        <button type="button" class="acc-pw__eye" data-acc-eye="accPwCurrent"
                                aria-label="<?= h(t('Afficher le mot de passe')) ?>"><?= acc_icon('eye') ?></button>
                      </div>
                    </div>
                    <div class="acc-field">
                      <label for="accPwNew"><?= acc_icon('lock') ?><?= t('Nouveau mot de passe') ?></label>
                      <div class="acc-pw">
                        <input type="password" id="accPwNew" name="new" autocomplete="new-password" minlength="12" required>
                        <button type="button" class="acc-pw__eye" data-acc-eye="accPwNew"
                                aria-label="<?= h(t('Afficher le mot de passe')) ?>"><?= acc_icon('eye') ?></button>
                      </div>
                    </div>
                    <div class="acc-field">
                      <label for="accPwConfirm"><?= acc_icon('lock') ?><?= t('Confirmer le nouveau mot de passe') ?></label>
                      <div class="acc-pw">
                        <input type="password" id="accPwConfirm" name="confirm" autocomplete="new-password" minlength="12" required>
                        <button type="button" class="acc-pw__eye" data-acc-eye="accPwConfirm"
                                aria-label="<?= h(t('Afficher le mot de passe')) ?>"><?= acc_icon('eye') ?></button>
                      </div>
                    </div>
                  </div>

                  <div class="acc-msg-slot"></div>

                  <div style="display:flex;gap:.65rem;flex-wrap:wrap;margin-top:1.2rem">
                    <button type="reset" class="acc-btn acc-btn--ghost"><?= t('Annuler') ?></button>
                    <button type="submit" class="acc-btn acc-btn--primary"><?= t('Mettre à jour') ?></button>
                  </div>
                </div>

                <!-- Exigences : retour visuel en direct. Seule la longueur
                     est bloquante côté portail ; la politique du realm
                     Keycloak s'applique EN PLUS, d'où la mention. -->
                <aside>
                  <p class="acc-req__head"><?= acc_icon('shield') ?><?= t('Exigences du mot de passe') ?></p>
                  <p class="acc-req__intro"><?= t('Votre mot de passe doit respecter les critères suivants :') ?></p>
                  <ul class="acc-req__list" id="accPwReqs">
                    <li data-req="len"><?= acc_icon('check', 'i-ok') . acc_icon('cross', 'i-ko') ?><span><?= t('Au moins 12 caractères') ?></span></li>
                    <li data-req="upper"><?= acc_icon('check', 'i-ok') . acc_icon('cross', 'i-ko') ?><span><?= t('Une lettre majuscule (A-Z)') ?></span></li>
                    <li data-req="lower"><?= acc_icon('check', 'i-ok') . acc_icon('cross', 'i-ko') ?><span><?= t('Une lettre minuscule (a-z)') ?></span></li>
                    <li data-req="digit"><?= acc_icon('check', 'i-ok') . acc_icon('cross', 'i-ko') ?><span><?= t('Un chiffre (0-9)') ?></span></li>
                    <li data-req="special"><?= acc_icon('check', 'i-ok') . acc_icon('cross', 'i-ko') ?><span><?= t('Un caractère spécial (!@#$%^&amp;*)') ?></span></li>
                  </ul>

                  <div class="acc-tips">
                    <h3><?= t('Bonnes pratiques') ?></h3>
                    <ul>
                      <li><?= t('Changez votre mot de passe régulièrement') ?></li>
                      <li><?= t('Ne le partagez jamais avec qui que ce soit') ?></li>
                      <li><?= t('Utilisez un mot de passe unique par service') ?></li>
                      <li><?= t('Pensez à un gestionnaire de mots de passe') ?></li>
                    </ul>
                  </div>
                </aside>
              </div>
            </form>
          </section>

          <!-- ============ 2FA ===================================== -->
          <section class="acc-card">
            <header class="acc-card__head">
              <span class="acc-card__icon"><?= acc_icon('shield') ?></span>
              <div class="acc-card__titles">
                <h2><?= t('Double authentification') ?></h2>
                <p><?= t('Ajoutez une couche de sécurité supplémentaire à votre compte') ?></p>
              </div>
              <span id="acc2faStatePill" class="acc-pill"><?= t('Chargement…') ?></span>
            </header>

            <div class="acc-card__body">
              <!-- Application d'authentification (TOTP) -->
              <div class="acc-row">
                <span class="acc-row__icon"><?= acc_icon('phone') ?></span>
                <div class="acc-row__main">
                  <p class="acc-row__title">
                    <?= t('Application d’authentification') ?>
                    <span class="acc-pill acc-pill--reco"><?= t('Recommandé') ?></span>
                  </p>
                  <p class="acc-row__desc">
                    <?= t('Générez des codes à usage unique (TOTP) avec Google Authenticator, Authy ou FreeOTP.') ?>
                  </p>
                  <p class="acc-row__state" id="acc2faState"><?= t('Chargement…') ?></p>
                </div>
                <div class="acc-row__action">
                  <button type="button" id="acc2faEnableBtn" class="acc-btn acc-btn--primary" hidden><?= t('Configurer') ?></button>
                  <button type="button" id="acc2faDisableBtn" class="acc-btn acc-btn--danger-ghost" hidden><?= t('Désactiver') ?></button>
                </div>
              </div>

              <!-- Confirmation par mot de passe, dépliée au clic -->
              <form id="acc2faDisableForm" class="acc-row" hidden novalidate>
                <span class="acc-row__icon"><?= acc_icon('lock') ?></span>
                <div class="acc-row__main">
                  <p class="acc-row__title"><?= t('Confirmer la désactivation') ?></p>
                  <p class="acc-row__desc"><?= t('Saisissez votre mot de passe actuel : désactiver la double authentification affaiblit la protection de votre compte.') ?></p>
                  <div class="acc-field" style="max-width:24rem;margin-top:.75rem">
                    <div class="acc-pw">
                      <input type="password" id="acc2faPassword" name="current" autocomplete="current-password"
                             placeholder="<?= h(t('Mot de passe actuel')) ?>" required>
                      <button type="button" class="acc-pw__eye" data-acc-eye="acc2faPassword"
                              aria-label="<?= h(t('Afficher le mot de passe')) ?>"><?= acc_icon('eye') ?></button>
                    </div>
                  </div>
                  <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.85rem">
                    <button type="button" class="acc-btn acc-btn--ghost" id="acc2faCancelBtn"><?= t('Annuler') ?></button>
                    <button type="submit" class="acc-btn acc-btn--danger"><?= t('Désactiver la 2FA') ?></button>
                  </div>
                </div>
              </form>

              <!-- Codes de secours : gérés par Keycloak, informatif ici -->
              <div class="acc-row">
                <span class="acc-row__icon"><?= acc_icon('key') ?></span>
                <div class="acc-row__main">
                  <p class="acc-row__title"><?= t('Codes de secours') ?></p>
                  <p class="acc-row__desc">
                    <?= t('Si votre realm les propose, Keycloak permet de générer des codes de secours utilisables en cas de perte de votre téléphone.') ?>
                  </p>
                  <p class="acc-row__state"><?= t('Gérés directement par Keycloak lors de la configuration.') ?></p>
                </div>
              </div>
            </div>

            <div class="acc-note">
              <?= acc_icon('alert') ?>
              <div>
                <p class="acc-note__title"><?= t('Comment se passe l’activation') ?></p>
                <p class="acc-note__text">
                  <?= t('Le secret ne peut pas être généré depuis le portail : nous demandons à Keycloak de vous envoyer un lien sécurisé par e-mail pour scanner le QR code. La configuration vous sera aussi proposée à votre prochaine connexion.') ?>
                </p>
              </div>
            </div>

            <div class="acc-msg-slot"></div>
          </section>

          <!-- ============ Sessions ================================ -->
          <section class="acc-card">
            <header class="acc-card__head">
              <span class="acc-card__icon"><?= acc_icon('shieldOk') ?></span>
              <div class="acc-card__titles">
                <h2><?= t('Sessions actives') ?></h2>
                <p><?= t('Gérez et surveillez les appareils qui accèdent à votre compte') ?></p>
              </div>
              <button type="button" id="accSessionsRefresh" class="acc-btn acc-btn--ghost"><?= t('Actualiser') ?></button>
            </header>

            <div class="acc-card__body">
              <p class="acc-sub"><span><?= t('Connexions Keycloak (SSO)') ?></span></p>
              <div id="accKcSessions"><div class="acc-skel"></div></div>

              <p class="acc-sub">
                <span><?= t('Sessions de l’espace client') ?></span>
                <button type="button" id="accRevokeOthers" class="acc-btn acc-btn--danger-ghost"><?= t('Fermer les autres') ?></button>
              </p>
              <div id="accPortalSessions"><div class="acc-skel"></div></div>
            </div>

            <div class="acc-note">
              <?= acc_icon('shieldOk') ?>
              <div>
                <p class="acc-note__title"><?= t('Conseil de sécurité') ?></p>
                <p class="acc-note__text">
                  <?= t('Si vous repérez une activité suspecte, fermez immédiatement la session concernée et changez votre mot de passe. Activez la double authentification pour une protection renforcée.') ?>
                </p>
              </div>
            </div>

            <div class="acc-msg-slot"></div>
          </section>

          <!-- ============ Entreprise (lecture seule) ============== -->
          <section class="acc-card">
            <header class="acc-card__head">
              <span class="acc-card__icon"><?= acc_icon('building') ?></span>
              <div class="acc-card__titles">
                <h2><?= t('Rattachement entreprise') ?></h2>
                <p><?= t('Ces informations appartiennent à votre structure : contactez le support pour les corriger') ?></p>
              </div>
            </header>
            <dl id="accCompany" class="acc-dl"><div class="acc-skel"></div></dl>
          </section>

        </div>
      </div>
    </main>
  </div>

  <script>
    window.ACCOUNT_API  = window.ACCOUNT_API  || "../data/account_api.php";
    window.ACCOUNT_CSRF = <?= json_encode($_SESSION['csrf'] ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    // Mêmes tracés que côté PHP : les lignes de sessions rendues par le JS
    // doivent être indiscernables des lignes rendues par le serveur.
    window.ACCOUNT_ICONS = {
      laptop: <?= json_encode(acc_icon('laptop'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      mobile: <?= json_encode(acc_icon('mobile'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      globe:  <?= json_encode(acc_icon('globe'),  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      clock:  <?= json_encode(acc_icon('clock'),  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      x:      <?= json_encode(acc_icon('x'),      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      eye:    <?= json_encode(acc_icon('eye'),    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      eyeOff: <?= json_encode(acc_icon('eyeOff'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    };

    window.ACCOUNT_I18N = {
      netError:     <?= json_encode(t('Le serveur est injoignable. Réessayez dans un instant.'), JSON_UNESCAPED_UNICODE) ?>,
      saved:        <?= json_encode(t('Enregistré.'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaOn:      <?= json_encode(t('Activée'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaOff:     <?= json_encode(t('Désactivée'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaPending: <?= json_encode(t('En attente'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaSince:   <?= json_encode(t('Configurée le'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaSoon:    <?= json_encode(t('Demandée à votre prochaine connexion, ou via le lien reçu par e-mail.'), JSON_UNESCAPED_UNICODE) ?>,
      twoFaNone:    <?= json_encode(t('Non configurée'), JSON_UNESCAPED_UNICODE) ?>,
      emailOk:      <?= json_encode(t('E-mail vérifié'), JSON_UNESCAPED_UNICODE) ?>,
      emailKo:      <?= json_encode(t('E-mail non vérifié'), JSON_UNESCAPED_UNICODE) ?>,
      noSession:    <?= json_encode(t('Aucune session active.'), JSON_UNESCAPED_UNICODE) ?>,
      current:      <?= json_encode(t('Session courante'), JSON_UNESCAPED_UNICODE) ?>,
      remove:       <?= json_encode(t('Fermer'), JSON_UNESCAPED_UNICODE) ?>,
      kcSession:    <?= json_encode(t('Connexion SSO'), JSON_UNESCAPED_UNICODE) ?>,
      portalSession:<?= json_encode(t('Session portail'), JSON_UNESCAPED_UNICODE) ?>,
      lastActive:   <?= json_encode(t('Dernière activité :'), JSON_UNESCAPED_UNICODE) ?>,
      openedOn:     <?= json_encode(t('Ouverte le'), JSON_UNESCAPED_UNICODE) ?>,
      confirmOther: <?= json_encode(t('Fermer toutes vos autres sessions de l’espace client ?'), JSON_UNESCAPED_UNICODE) ?>,
      confirmKc:    <?= json_encode(t('Fermer cette connexion Keycloak ? L’appareil concerné devra se reconnecter.'), JSON_UNESCAPED_UNICODE) ?>,
      usernameLock: <?= json_encode(t('Modification désactivée sur ce realm Keycloak.'), JSON_UNESCAPED_UNICODE) ?>,
      usernameMail: <?= json_encode(t('Votre identifiant suit votre adresse e-mail.'), JSON_UNESCAPED_UNICODE) ?>,
      usernameFree: <?= json_encode(t('Lettres, chiffres et . _ - @ — 3 à 64 caractères.'), JSON_UNESCAPED_UNICODE) ?>,
      noCompany:    <?= json_encode(t('Aucune information d’entreprise rattachée à ce compte.'), JSON_UNESCAPED_UNICODE) ?>,
      never:        <?= json_encode(t('jamais'), JSON_UNESCAPED_UNICODE) ?>,
      showPw:       <?= json_encode(t('Afficher le mot de passe'), JSON_UNESCAPED_UNICODE) ?>,
      hidePw:       <?= json_encode(t('Masquer le mot de passe'), JSON_UNESCAPED_UNICODE) ?>,
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
    var ICO   = window.ACCOUNT_ICONS || {};
    var state = { profile: null, sessions: { keycloak: [], portal: [] } };

    function $(id) { return document.getElementById(id); }
    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    /* ---------- Messages ---------------------------------------- */

    function slotOf(el) {
      var host = el.closest('form') || el.closest('section');
      var slot = host ? host.querySelector('.acc-msg-slot') : null;
      if (!slot) {
        var card = el.closest('section');
        slot = card ? card.querySelector('.acc-msg-slot') : null;
      }
      return slot;
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
      div.style.margin = '0 0 1.5rem';
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

    function pill(text, kind) {
      return '<span class="acc-pill' + (kind ? ' acc-pill--' + kind : '') + '">' + esc(text) + '</span>';
    }

    /**
     * Ligne de session — même balisage que les .acc-row rendues par PHP.
     * @param o {icon, title, pillText, pillKind, metas:[[icone,texte]], desc, action}
     */
    function rowHtml(o) {
      var metas = (o.metas || []).map(function (m) {
        return '<p class="acc-row__meta">' + (ICO[m[0]] || '') + '<span>' + esc(m[1]) + '</span></p>';
      }).join('');

      return '<div class="acc-row">'
        + '<span class="acc-row__icon">' + (ICO[o.icon] || '') + '</span>'
        + '<div class="acc-row__main">'
        +   '<p class="acc-row__title"><span>' + esc(o.title) + '</span>'
        +     (o.pillText ? pill(o.pillText, o.pillKind) : '') + '</p>'
        +   metas
        +   (o.desc ? '<p class="acc-row__desc">' + esc(o.desc) + '</p>' : '')
        + '</div>'
        + (o.action ? '<div class="acc-row__action">' + o.action + '</div>' : '')
        + '</div>';
    }

    function removeBtn(attr, value) {
      return '<button type="button" class="acc-btn acc-btn--danger" ' + attr + '="' + esc(value) + '">'
        + (ICO.x || '') + '<span>' + esc(I18N.remove) + '</span></button>';
    }

    /** Un libellé d'appareil qui parle de mobile => icône téléphone. */
    function deviceIcon(label) {
      return /mobil|phone|android|iphone|ipad|ios|tablet/i.test(String(label || '')) ? 'mobile' : 'laptop';
    }

    function renderProfile(p) {
      if (!p) return;
      state.profile = p;

      var eb = $('accEmailBadge');
      eb.hidden = false;
      eb.className = 'acc-pill ' + (p.emailVerified ? 'acc-pill--on' : 'acc-pill--warn');
      eb.textContent = p.emailVerified ? I18N.emailOk : I18N.emailKo;

      var tf = p.twoFactor || { enabled: false, pending: false, credentials: [] };
      var tb = $('acc2faBadge');
      tb.hidden = false;
      tb.className = 'acc-pill ' + (tf.enabled ? 'acc-pill--on' : tf.pending ? 'acc-pill--warn' : '');
      tb.textContent = '2FA · ' + (tf.enabled ? I18N.twoFaOn : tf.pending ? I18N.twoFaPending : I18N.twoFaOff);

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
      var pillEl = $('acc2faStatePill');
      pillEl.className = 'acc-pill ' + (tf.enabled ? 'acc-pill--on' : tf.pending ? 'acc-pill--warn' : '');
      pillEl.textContent = tf.enabled ? I18N.twoFaOn : tf.pending ? I18N.twoFaPending : I18N.twoFaOff;

      var st = $('acc2faState');
      if (tf.enabled) {
        var cred = tf.credentials[0] || {};
        st.textContent = I18N.twoFaSince + ' ' + fmtMs(cred.createdDate) + (cred.label ? ' — ' + cred.label : '');
      } else if (tf.pending) {
        st.textContent = I18N.twoFaSoon;
      } else {
        st.textContent = I18N.twoFaNone;
      }

      $('acc2faEnableBtn').hidden  = tf.enabled;
      $('acc2faDisableBtn').hidden = !tf.enabled;
      // Le formulaire de confirmation ne s'ouvre que sur clic « Désactiver ».
      if (!tf.enabled) { $('acc2faDisableForm').hidden = true; }
    }

    function renderCompany(c) {
      var order = ['raison', 'nom_commercial', 'siret', 'siren', 'client_code'];
      var host  = $('accCompany');
      var rows  = order.filter(function (k) { return c[k]; });

      if (!rows.length) {
        host.innerHTML = '<p class="acc-empty">' + esc(I18N.noCompany) + '</p>';
        return;
      }
      host.innerHTML = rows.map(function (k) {
        return '<div><dt>' + esc((I18N.labels || {})[k] || k) + '</dt>'
          + '<dd>' + esc(c[k]) + '</dd></div>';
      }).join('');
    }

    function renderSessions(s) {
      state.sessions = s || { keycloak: [], portal: [] };

      var kc = $('accKcSessions');
      if (!state.sessions.keycloak.length) {
        kc.innerHTML = '<p class="acc-empty">' + esc(I18N.noSession) + '</p>';
      } else {
        kc.innerHTML = state.sessions.keycloak.map(function (x) {
          var clients = (x.clients || []).join(', ');
          return rowHtml({
            icon: 'laptop',
            title: I18N.kcSession,
            metas: [
              ['globe', x.ip || '—'],
              ['clock', I18N.lastActive + ' ' + fmtMs(x.lastAccess)]
            ],
            desc: I18N.openedOn + ' ' + fmtMs(x.start) + (clients ? ' · ' + clients : ''),
            action: removeBtn('data-kc-session', x.id)
          });
        }).join('');
      }

      var pt = $('accPortalSessions');
      if (!state.sessions.portal.length) {
        pt.innerHTML = '<p class="acc-empty">' + esc(I18N.noSession) + '</p>';
      } else {
        pt.innerHTML = state.sessions.portal.map(function (x) {
          return rowHtml({
            icon: deviceIcon(x.device),
            title: x.device || I18N.portalSession,
            pillText: x.current ? I18N.current : '',
            pillKind: 'on',
            metas: [
              ['globe', x.ip || '—'],
              ['clock', I18N.lastActive + ' ' + (x.lastSeen || '—')]
            ],
            desc: I18N.openedOn + ' ' + (x.createdAt || '—'),
            action: x.current ? '' : removeBtn('data-local-session', x.id)
          });
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

    /* ---------- Afficher / masquer les mots de passe ------------- */

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-acc-eye]');
      if (!btn) return;
      var input = $(btn.getAttribute('data-acc-eye'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? (ICO.eyeOff || '') : (ICO.eye || '');
      btn.setAttribute('aria-label', show ? I18N.hidePw : I18N.showPw);
    });

    /* ---------- Exigences du mot de passe, en direct ------------- */

    var pwNew = $('accPwNew');
    var reqs  = $('accPwReqs');
    var TESTS = {
      len:     function (v) { return v.length >= 12; },
      upper:   function (v) { return /[A-ZÀ-Þ]/.test(v); },
      lower:   function (v) { return /[a-zà-þ]/.test(v); },
      digit:   function (v) { return /[0-9]/.test(v); },
      special: function (v) { return /[^A-Za-zÀ-þ0-9]/.test(v); }
    };
    function checkReqs() {
      if (!reqs || !pwNew) return;
      var v = pwNew.value || '';
      Array.prototype.forEach.call(reqs.querySelectorAll('li'), function (li) {
        var fn = TESTS[li.getAttribute('data-req')];
        li.classList.toggle('is-ok', !!(fn && v !== '' && fn(v)));
      });
    }
    if (pwNew) { pwNew.addEventListener('input', checkReqs); checkReqs(); }

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
      checkReqs();
    });

    var pwForm = $('accPasswordForm');
    if (pwForm) {
      pwForm.addEventListener('reset', function () { setTimeout(checkReqs, 0); });
    }

    /* ---------- 2FA --------------------------------------------- */

    var enableBtn   = $('acc2faEnableBtn');
    var disableBtn  = $('acc2faDisableBtn');
    var cancelBtn   = $('acc2faCancelBtn');
    var disableForm = $('acc2faDisableForm');

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

    if (disableBtn && disableForm) {
      disableBtn.addEventListener('click', function () {
        disableForm.hidden = false;
        $('acc2faPassword').focus();
      });
    }
    if (cancelBtn && disableForm) {
      cancelBtn.addEventListener('click', function () {
        disableForm.hidden = true;
        disableForm.reset();
        say(disableForm, '', 'ok');
      });
    }

    if (disableForm) {
      disableForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var pw = disableForm.current.value;
        busy(disableForm, true);
        call('account.2fa.disable', { current: pw }).then(function (r) {
          busy(disableForm, false);
          disableForm.reset();
          if (!r.ok) { say(disableForm, r.error || I18N.netError, 'err'); return; }
          disableForm.hidden = true;
          if (r.profile) renderProfile(r.profile);
          say(disableBtn, r.notice, 'ok');
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