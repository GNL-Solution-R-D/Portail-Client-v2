<?php
/* =====================================================================
   GNL Solution — Choix de l'organisation  (/organisation)  [espace client]
   ---------------------------------------------------------------------
   Affiché juste après la connexion LORSQUE l'utilisateur appartient à
   plusieurs organisations. Il choisit au nom de laquelle il s'identifie ;
   les informations société (SIRET, TVA, adresse…) ET le namespace de
   l'organisation retenue alimentent alors $_SESSION['user'] via
   keycloakBuildSessionUser(), puis on repart vers la cible ("return").

   L'état d'attente (identité + claims + liste d'organisations) est mémorisé
   en session par gnl_route_after_login() quand elle renvoie 'choose'.
   Accessible uniquement pendant ce court laps de temps (voir GNL_PENDING_TTL).
   ===================================================================== */

require_once '../include/session_bootstrap.php';   // session sécurisée (en 1er)
require_once '../include/keycloak_rest.php';        // pending + finalize + template

/* Déjà finalisé -> plus rien à choisir. */
if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
    header('Location: ' . gnl_site_base() . '/dashboard');
    exit;
}

/* "Utiliser un autre compte" : on abandonne le choix et on repart au login. */
if (isset($_GET['switch'])) {
    unset($_SESSION['gnl_pending_login']);
    header('Location: ' . gnl_site_base() . '/connexion');
    exit;
}

/* Aucun choix en attente (jamais connecté, ou délai dépassé) -> login. */
$pending = gnl_pending_login();
if (!$pending) {
    header('Location: ' . gnl_site_base() . '/connexion');
    exit;
}

$orgs   = $pending['orgs'];
$return = gnl_safe_return($pending['return'] ?? '/dashboard');
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gnl_login_csrf_check()) {
        $error = "Session expirée. Merci de renvoyer le formulaire.";
    } else {
        $idx = $_POST['org_idx'] ?? '';
        if ($idx === '' || !ctype_digit((string) $idx)) {
            $error = "Sélectionnez une organisation pour continuer.";
        } else {
            $r = gnl_finalize_org_choice((int) $idx);
            if ($r['ok']) {
                header('Location: ' . gnl_site_base() . $return);
                exit;
            }
            $error = $r['error'] !== '' ? $r['error'] : "Ce choix n'est plus valide. Reconnectez-vous.";
        }
    }
}

$csrf = gnl_login_csrf_token();
$who  = trim((string) ($pending['claims']['name'] ?? ''));
if ($who === '') $who = (string) ($pending['claims']['email'] ?? '');
if ($who === '') $who = (string) ($pending['claims']['preferred_username'] ?? '');

gnl_auth_head('Choisir une organisation', 'organisation');
?>
    <style>
      .gnl-orgs{display:flex; flex-direction:column; gap:.7rem; margin:.2rem 0 1.2rem}
      .gnl-org{position:relative; display:flex; gap:.85rem; align-items:flex-start;
        border:1px solid var(--gnl-line); border-radius:12px; padding:.9rem 1rem;
        cursor:pointer; transition:border-color .15s, box-shadow .15s, background .15s}
      .gnl-org:hover{border-color:color-mix(in srgb,var(--gnl-green) 45%, transparent)}
      .gnl-org input{position:absolute; opacity:0; pointer-events:none}
      .gnl-org .dot{flex:none; width:20px; height:20px; margin-top:1px; border-radius:50%;
        border:2px solid var(--gnl-line); transition:border-color .15s; position:relative}
      .gnl-org .txt{min-width:0}
      .gnl-org .t{font-weight:700; font-size:.98rem; line-height:1.25; overflow-wrap:anywhere}
      .gnl-org .s{font-size:.82rem; color:#6a6f66; margin-top:.15rem; overflow-wrap:anywhere}
      .gnl-org:has(input:checked){border-color:var(--gnl-green);
        box-shadow:0 0 0 1px var(--gnl-green) inset; background:color-mix(in srgb,var(--gnl-green) 5%, #fff)}
      .gnl-org:has(input:checked) .dot{border-color:var(--gnl-green)}
      .gnl-org:has(input:checked) .dot::after{content:""; position:absolute; inset:3px;
        border-radius:50%; background:var(--gnl-green)}
      .gnl-org input:focus-visible ~ .dot{outline:2px solid var(--gnl-teal); outline-offset:2px}
    </style>

    <h1>Choisir une organisation</h1>
    <p class="sub">Vous appartenez à plusieurs organisations<?php echo $who !== '' ? ' (' . gnl_e($who) . ')' : ''; ?>. Au nom de laquelle souhaitez-vous continuer&nbsp;?</p>

    <?php if ($error !== ''): ?>
      <div class="gnl-msg err"><?php echo gnl_icon('err'); ?><span><?php echo gnl_e($error); ?></span></div>
    <?php endif; ?>

    <form method="post" action="/organisation" data-gnl-auth>
      <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">
      <div class="gnl-orgs" role="radiogroup" aria-label="Organisations">
        <?php foreach ($orgs as $i => $org):
          $lbl = gnl_org_label($org); ?>
          <label class="gnl-org">
            <input type="radio" name="org_idx" value="<?php echo (int) $i; ?>" <?php echo $i === 0 ? 'checked' : ''; ?> required>
            <span class="dot" aria-hidden="true"></span>
            <span class="txt">
              <span class="t"><?php echo gnl_e($lbl['title']); ?></span>
              <?php if ($lbl['sub'] !== ''): ?><span class="s"><?php echo gnl_e($lbl['sub']); ?></span><?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>

      <button class="gnl-btn" type="submit">Continuer</button>
    </form>

    <p class="gnl-alt"><a href="/organisation?switch=1">Utiliser un autre compte</a></p>
<?php
gnl_auth_foot();
