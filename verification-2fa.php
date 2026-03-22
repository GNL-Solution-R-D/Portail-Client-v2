<?php
session_start();
require_once 'config_loader.php';
require_once 'include/csrf.php';
require_once 'include/two_factor.php';

function twoFactorBuildAuthenticatedUser(array $user): array
{
    return [
        'id' => $user['id'],
        'siret' => $user['siret'],
        'username' => $user['username'],
        'civilite' => $user['civilite'],
        'prenom' => $user['prenom'],
        'nom' => $user['nom'],
        'perm_id' => $user['perm_id'],
        'langue_code' => $user['langue_code'],
        'timezone' => $user['timezone'],
        'fonction' => $user['fonction'],
        'k8s_namespace' => $user['k8s_namespace'],
    ];
}

$pending = $_SESSION['pending_2fa'] ?? null;
if (!is_array($pending) || !isset($pending['user']['id'])) {
    header('Location: /connexion');
    exit();
}

if ((int) ($pending['issued_at'] ?? 0) < (time() - 600)) {
    unset($_SESSION['pending_2fa']);
    header('Location: /connexion?error=' . urlencode('La vérification à deux facteurs a expiré.'));
    exit();
}

$errorMessage = '';
$infoMessage = 'Saisissez le code généré par votre application d’authentification ou utilisez un code de secours.';
$userPreview = $pending['user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $submittedCode = trim((string) ($_POST['verification_code'] ?? ''));

    if (!verify_csrf_token($submittedToken)) {
        $errorMessage = 'La session de sécurité a expiré. Rechargez la page et réessayez.';
    } elseif ($submittedCode === '') {
        $errorMessage = 'Veuillez saisir un code de vérification.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $pending['user']['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user)) {
            unset($_SESSION['pending_2fa']);
            header('Location: /connexion?error=' . urlencode('Compte introuvable.'));
            exit();
        }

        $config = twoFactorGetConfig($pdo, (int) $user['id']);
        $verified = false;

        if (!empty($config['totp_secret']) && !empty($config['is_enabled'])) {
            $verified = twoFactorVerifyTotpCode((string) $config['totp_secret'], $submittedCode);
        }

        if (!$verified) {
            $verified = twoFactorConsumeRecoveryCode($pdo, (int) $user['id'], strtoupper($submittedCode));
            if ($verified) {
                $infoMessage = 'Code de secours accepté. Pensez à en régénérer un nouveau lot depuis vos paramètres.';
            }
        }

        if ($verified) {
            session_regenerate_id(true);
            $_SESSION['user'] = twoFactorBuildAuthenticatedUser($user);
            unset($_SESSION['pending_2fa']);
            header('Location: ' . ($pending['remember_origin'] ?? '/dashboard'));
            exit();
        }

        $errorMessage = 'Le code saisi est invalide ou expiré.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Vérification 2FA - GNL Solution</title>
  <link rel="stylesheet" href="assets/styles/connexion-style.css">
</head>
<body class="text-foreground group/body overscroll-none font-sans antialiased bg-surface">
  <div class="w-full bg-surface">
    <div class="grid min-h-screen grid-cols-1 items-center gap-4 lg:grid-cols-2">
      <div class="mx-auto w-full max-w-lg p-6 sm:p-16 lg:max-w-md lg:p-0">
        <h1 class="mb-2 text-center text-2xl font-bold tracking-tight">Vérification à deux facteurs</h1>
        <p class="text-muted-foreground mb-8 text-center text-base">
          Bonjour <?= htmlspecialchars(trim((string) (($userPreview['prenom'] ?? '') . ' ' . ($userPreview['nom'] ?? ''))) ?: (string) ($userPreview['username'] ?? 'Utilisateur'), ENT_QUOTES, 'UTF-8') ?>,
          terminez votre connexion avec votre code 2FA.
        </p>

        <?php if ($errorMessage !== ''): ?>
          <div class="mb-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          <?= htmlspecialchars($infoMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <form action="verification-2fa.php" method="POST" class="space-y-6">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

          <div class="space-y-2">
            <label class="flex items-center gap-2 select-none text-sm font-semibold" for="verification_code">Code de vérification</label>
            <input
              type="text"
              id="verification_code"
              name="verification_code"
              inputmode="numeric"
              autocomplete="one-time-code"
              placeholder="123456 ou ABCD-EF12"
              class="border-input w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] h-11"
              required
            >
            <p class="text-muted-foreground text-sm">Les codes TOTP expirent toutes les 30 secondes. Les codes de secours ne sont valables qu’une seule fois.</p>
          </div>

          <button
            type="submit"
            class="inline-flex h-10 w-full items-center justify-center rounded-md bg-primary px-6 text-sm font-medium text-primary-foreground transition-all hover:bg-primary/90"
          >
            Valider et accéder au portail
          </button>
        </form>

        <div class="mt-6 text-center text-sm">
          <a href="deconnexion.php" class="text-primary font-semibold hover:underline">Annuler et revenir à la connexion</a>
        </div>
      </div>

      <div class="hidden min-h-screen bg-muted/40 p-12 lg:flex lg:flex-col lg:justify-center">
        <div class="mx-auto max-w-lg rounded-2xl border bg-background p-8 shadow-sm">
          <h2 class="text-lg font-semibold">Comment ça fonctionne</h2>
          <ul class="text-muted-foreground mt-4 space-y-3 text-sm">
            <li>1. Vous saisissez votre SIRET, identifiant et mot de passe.</li>
            <li>2. Si la 2FA est activée, le portail vous demande un second facteur.</li>
            <li>3. Vous validez avec votre application TOTP ou un code de secours.</li>
            <li>4. L’accès au tableau de bord n’est accordé qu’après cette étape.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
