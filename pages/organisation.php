<?php
/* =====================================================================
   GNL Solution — Choix de l'organisation  (/organisation)  [espace client]
   ---------------------------------------------------------------------
   Affiché juste après la connexion LORSQUE l'utilisateur appartient à
   plusieurs organisations. Il choisit au nom de laquelle il s'identifie ;
   les informations société (SIRET, TVA, adresse…) ET l'UID de
   l'organisation retenue alimentent alors $_SESSION['user'] via
   keycloakBuildSessionUser() — c'est de cet UID qu'est dérivé « ns-k8s »,
   le namespace Kubernetes. Puis on repart vers la cible ("return").

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

/* Initiales de l'organisation : même règle que le badge de la barre latérale (include/menu.php). */
$orgInitials = static function (string $name): string {
    $out = '';
    foreach (preg_split('/[\s\-]+/u', $name) ?: [] as $w) {
        if ($w === '' || mb_strlen($w, 'UTF-8') < 3) continue;
        $out .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
        if (mb_strlen($out, 'UTF-8') >= 2) break;
    }
    return $out !== '' ? $out : 'GN';
};
$orgCount = count($orgs);

gnl_auth_head('Choisir une organisation', 'organisation');
?>
    <style>
      .gnl-kicker{margin:0 0 10px;display:flex;align-items:center;gap:10px;font-size:12px;font-weight:700;letter-spacing:.2em;color:var(--green-d)}
      .gnl-who{display:flex;align-items:center;gap:12px;margin:0 0 24px;padding:12px 14px;border-radius:3px;background:#fff;border:1px solid var(--line-2);font-size:14px}
      .gnl-who .av{flex:none;display:grid;place-items:center;width:32px;height:32px;border-radius:50%;background:var(--navy);color:#fff;font-size:12px;font-weight:800}
      .gnl-who .n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .gnl-who .n small{display:block;font-size:12px;color:var(--mute)}
      .gnl-who a{flex:none;font-size:13px;font-weight:700;text-decoration:none}
      .gnl-search{font-size:15px;font-weight:400;display:flex;align-items:center;gap:10px;height:46px;padding:0 14px;margin:0 0 12px;border:1px solid var(--line);border-radius:3px;background:#fff;color:var(--mute)}
      .gnl-search:focus-within{border-color:var(--navy);box-shadow:0 0 0 3px rgba(92,217,139,.35)}
      .gnl-search input{flex:1;min-width:0;border:0;outline:0;font:inherit;color:var(--ink);background:transparent}
      .gnl-orgs{margin:0 0 20px;padding:0;border:0;display:flex;flex-direction:column;gap:8px}
      .gnl-orgs legend{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
      .gnl-orgs.scroll{max-height:372px;overflow:auto;padding-right:4px}
      .gnl-org{margin:0;font-size:15px;font-weight:400;color:var(--ink);display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--line);border-radius:3px;background:#fff;cursor:pointer;transition:border-color .15s,background .15s}
      .gnl-org:hover{border-color:var(--navy)}
      .gnl-org input{flex:none;width:18px;height:18px;margin:0;accent-color:var(--green-d)}
      .gnl-org input:focus-visible{outline:3px solid var(--green);outline-offset:2px}
      .gnl-org .tile{flex:none;display:grid;place-items:center;width:40px;height:40px;border-radius:3px;background:var(--navy);color:#fff;font-size:13px;font-weight:800;letter-spacing:.02em}
      .gnl-org .txt{flex:1;min-width:0}
      .gnl-org .t{display:block;font-weight:700;line-height:20px;overflow-wrap:anywhere}
      .gnl-org .s{display:block;font-size:13px;line-height:18px;color:var(--mute);margin-top:2px;overflow-wrap:anywhere}
      .gnl-org:has(input:checked){border:2px solid var(--green-d);padding:11px 13px;background:#e9f6ef}
      .gnl-org:has(input:checked) .tile{background:var(--green);color:var(--navy)}
      .gnl-org[hidden]{display:none}
      .gnl-empty{margin:4px 0 0;font-size:14px;color:var(--mute)}
    </style>

    <p class="gnl-kicker"><svg aria-hidden="true" width="8" height="14" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg>CONNEXION</p>
    <h1>Choisir une organisation</h1>
    <p class="sub">Vous êtes membre de <?php echo (int) $orgCount; ?> organisations. Vos services, factures et droits dépendent de celle que vous choisissez.</p>

    <?php if ($who !== ''): ?>
      <div class="gnl-who">
        <span class="av" aria-hidden="true"><?php echo gnl_e($orgInitials($who)); ?></span>
        <span class="n"><small>Connecté en tant que</small><?php echo gnl_e($who); ?></span>
        <a href="/organisation?switch=1">Changer de compte</a>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="gnl-msg err"><?php echo gnl_icon('err'); ?><span><?php echo gnl_e($error); ?></span></div>
    <?php endif; ?>

    <form method="post" action="/organisation" data-gnl-auth>
      <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">

      <?php if ($orgCount > 5): ?>
        <label class="gnl-search">
          <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
          <span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Rechercher une organisation</span>
          <input type="search" id="gnl-org-filter" placeholder="Rechercher une organisation" autocomplete="off">
        </label>
      <?php endif; ?>

      <fieldset class="gnl-orgs<?php echo $orgCount > 5 ? ' scroll' : ''; ?>">
        <legend>Organisations</legend>
        <?php foreach ($orgs as $i => $org):
          $lbl = gnl_org_label($org); ?>
          <label class="gnl-org" data-q="<?php echo gnl_e(mb_strtolower($lbl['title'] . ' ' . $lbl['sub'], 'UTF-8')); ?>">
            <input type="radio" name="org_idx" value="<?php echo (int) $i; ?>" <?php echo $i === 0 ? 'checked' : ''; ?> required>
            <span class="tile" aria-hidden="true"><?php echo gnl_e($orgInitials($lbl['title'])); ?></span>
            <span class="txt">
              <span class="t"><?php echo gnl_e($lbl['title']); ?></span>
              <?php if ($lbl['sub'] !== ''): ?><span class="s"><?php echo gnl_e($lbl['sub']); ?></span><?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
        <p class="gnl-empty" id="gnl-org-empty" hidden>Aucune organisation ne correspond.</p>
      </fieldset>

      <button class="gnl-btn" type="submit">Continuer</button>
    </form>

    <?php if ($who === ''): ?>
      <p class="gnl-alt"><a href="/organisation?switch=1">Utiliser un autre compte</a></p>
    <?php endif; ?>

    <script>
      (function () {
        var f = document.getElementById('gnl-org-filter'); if (!f) return;
        var items = Array.prototype.slice.call(document.querySelectorAll('.gnl-org'));
        var empty = document.getElementById('gnl-org-empty');
        f.addEventListener('input', function () {
          var q = f.value.trim().toLowerCase(), n = 0;
          items.forEach(function (it) { var ok = !q || it.getAttribute('data-q').indexOf(q) !== -1; it.hidden = !ok; if (ok) n++; });
          empty.hidden = n !== 0;
        });
      })();
    </script>
<?php
gnl_auth_foot();
