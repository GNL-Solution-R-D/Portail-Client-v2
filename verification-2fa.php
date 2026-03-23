<?php
session_start();
require_once 'config_loader.php';
require_once 'include/csrf.php';
require_once 'include/two_factor.php';
require_once 'include/webauthn.php';
require_once 'include/account_sessions.php';

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
$infoMessage = 'Saisissez un code TOTP ou utilisez une clé de sécurité si elle est configurée sur votre compte.';
$userPreview = $pending['user'];

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([(int) $pending['user']['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($user)) {
    unset($_SESSION['pending_2fa']);
    header('Location: /connexion?error=' . urlencode('Compte introuvable.'));
    exit();
}

$config = twoFactorGetConfig($pdo, (int) $user['id']);
$hasTotp = twoFactorHasEnabledTotp($config);
$webauthnConfigured = webauthnIsConfigured();
$webauthnCredentials = $webauthnConfigured ? webauthnGetCredentials($pdo, (int) $user['id']) : [];
$hasWebauthn = $webauthnCredentials !== [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $submittedCode = trim((string) ($_POST['verification_code'] ?? ''));
    $submittedWebauthnPayload = trim((string) ($_POST['webauthn_authentication_response'] ?? ''));

    if (!verify_csrf_token($submittedToken)) {
        $errorMessage = 'La session de sécurité a expiré. Rechargez la page et réessayez.';
    } else {
        $verified = false;

        if ($submittedWebauthnPayload !== '') {
            try {
                webauthnFinishAuthentication($pdo, (int) $user['id'], $submittedWebauthnPayload);
                $verified = true;
                $infoMessage = 'Clé de sécurité validée avec succès.';
            } catch (Throwable $exception) {
                $errorMessage = $exception->getMessage();
            }
        } elseif ($submittedCode === '') {
            $errorMessage = 'Veuillez saisir un code de vérification ou utiliser une clé de sécurité.';
        } else {
            if ($hasTotp) {
                $verified = twoFactorVerifyTotpCode((string) $config['totp_secret'], $submittedCode);
            }

            if (!$verified) {
                $verified = twoFactorConsumeRecoveryCode($pdo, (int) $user['id'], strtoupper($submittedCode));
                if ($verified) {
                    $infoMessage = 'Code de secours accepté. Pensez à en régénérer un nouveau lot depuis vos paramètres.';
                }
            }

            if (!$verified && $errorMessage === '') {
                $errorMessage = 'Le code saisi est invalide ou expiré.';
            }
        }

        if ($verified) {
            session_regenerate_id(true);
            $_SESSION['user'] = twoFactorBuildAuthenticatedUser($user);
            accountSessionsTouchCurrent($pdo, (int) $user['id']);
            unset($_SESSION['pending_2fa']);
            header('Location: ' . ($pending['remember_origin'] ?? '/dashboard'));
            exit();
        }
    }

    $config = twoFactorGetConfig($pdo, (int) $user['id']);
    $hasTotp = twoFactorHasEnabledTotp($config);
    $webauthnCredentials = $webauthnConfigured ? webauthnGetCredentials($pdo, (int) $user['id']) : [];
    $hasWebauthn = $webauthnCredentials !== [];
}

$webauthnAuthenticationOptions = null;
if ($hasWebauthn && $webauthnConfigured) {
    try {
        $webauthnAuthenticationOptions = webauthnCreateAuthenticationOptions($pdo, (int) $user['id']);
    } catch (Throwable $exception) {
        $errorMessage = $errorMessage !== '' ? $errorMessage : 'Impossible de préparer la clé de sécurité pour cette session.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#ffffff">
  <title>Vérification 2FA - GNL Solution</title>
  <link rel="stylesheet" href="assets/styles/connexion-style.css">
  <script>
    (() => {
      try {
        const storedTheme = localStorage.getItem('theme') || 'system';
        const resolvedTheme = storedTheme === 'system'
          ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
          : storedTheme;

        document.documentElement.classList.remove('light', 'dark');
        document.documentElement.classList.add(resolvedTheme);
        document.documentElement.style.colorScheme = resolvedTheme;

        const themeColor = resolvedTheme === 'dark' ? '#09090b' : '#ffffff';
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', themeColor);
      } catch (_) {
        document.documentElement.classList.remove('light', 'dark');
        document.documentElement.classList.add('light');
        document.documentElement.style.colorScheme = 'light';
      }
    })();
  </script>
</head>
<body class="text-foreground group/body overscroll-none font-sans antialiased bg-surface">
  <div class="w-full bg-surface">
    <div class="grid min-h-screen grid-cols-1 items-center gap-4 lg:grid-cols-2">
      <div class="mx-auto w-full max-w-lg p-6 sm:p-16 lg:max-w-md lg:p-0">
        <h1 class="mb-2 text-center text-2xl font-bold tracking-tight">Vérification à deux facteurs</h1>
        <p class="text-muted-foreground mb-8 text-center text-base">
          Bonjour <?= htmlspecialchars(trim((string) (($userPreview['prenom'] ?? '') . ' ' . ($userPreview['nom'] ?? ''))) ?: (string) ($userPreview['username'] ?? 'Utilisateur'), ENT_QUOTES, 'UTF-8') ?>,
          terminez votre connexion avec votre second facteur.
        </p>

        <?php if ($errorMessage !== ''): ?>
          <div class="mb-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          <?= htmlspecialchars($infoMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <?php if ($hasWebauthn && $webauthnAuthenticationOptions !== null): ?>
          <form action="verification-2fa.php" method="POST" class="mb-6 space-y-4 rounded-2xl border border-border p-4" id="webauthn-authentication-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="webauthn_authentication_response" id="webauthn_authentication_response" value="">
            <div>
              <h2 class="text-sm font-semibold">Clé de sécurité</h2>
              <p class="text-muted-foreground mt-1 text-sm">Touchez votre clé ou confirmez avec le système de sécurité intégré de votre appareil.</p>
            </div>
            <button
              type="button"
              id="webauthn-authentication-button"
              class="inline-flex h-10 w-full items-center justify-center rounded-md border px-4 text-sm font-medium hover:bg-accent"
              data-options='<?= htmlspecialchars(json_encode($webauthnAuthenticationOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'
            >
              Utiliser ma clé de sécurité
            </button>
          </form>
        <?php endif; ?>

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
              <?= $hasTotp ? 'required' : '' ?>
            >
            <p class="text-muted-foreground text-sm">
              <?= $hasTotp
                ? 'Les codes TOTP expirent toutes les 30 secondes. Les codes de secours ne sont valables qu’une seule fois.'
                : 'Si vous n’avez plus de code TOTP, utilisez votre clé de sécurité ou un code de secours.' ?>
            </p>
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
            <li>3. Vous validez avec votre clé de sécurité, votre application TOTP ou un code de secours.</li>
            <li>4. L’accès au tableau de bord n’est accordé qu’après cette étape.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <?php if ($hasWebauthn && $webauthnAuthenticationOptions !== null): ?>
    <script>
      (() => {
        const button = document.getElementById('webauthn-authentication-button');
        const form = document.getElementById('webauthn-authentication-form');
        const responseField = document.getElementById('webauthn_authentication_response');

        if (!button || !form || !responseField) {
          return;
        }

        const decodeBase64Url = (value) => {
          const padding = '='.repeat((4 - (value.length % 4 || 4)) % 4);
          const normalized = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
          const binary = atob(normalized);
          return Uint8Array.from(binary, (char) => char.charCodeAt(0));
        };

        const encodeBase64Url = (buffer) => {
          const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
          let binary = '';
          bytes.forEach((byte) => {
            binary += String.fromCharCode(byte);
          });
          return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
        };

        const toPublicKeyOptions = (options) => ({
          ...options,
          challenge: decodeBase64Url(options.challenge),
          allowCredentials: Array.isArray(options.allowCredentials)
            ? options.allowCredentials.map((credential) => ({
                ...credential,
                id: decodeBase64Url(credential.id),
              }))
            : [],
        });

        button.addEventListener('click', async () => {
          if (!window.PublicKeyCredential || !navigator.credentials) {
            alert('Votre navigateur ne prend pas en charge WebAuthn sur cette page.');
            return;
          }

          button.disabled = true;
          const initialLabel = button.textContent;
          button.textContent = 'Validation en cours…';

          try {
            const options = JSON.parse(button.dataset.options || '{}');
            const credential = await navigator.credentials.get({
              publicKey: toPublicKeyOptions(options),
            });

            const payload = {
              id: credential.id,
              rawId: encodeBase64Url(credential.rawId),
              type: credential.type,
              response: {
                clientDataJSON: encodeBase64Url(credential.response.clientDataJSON),
                authenticatorData: encodeBase64Url(credential.response.authenticatorData),
                signature: encodeBase64Url(credential.response.signature),
                userHandle: credential.response.userHandle ? encodeBase64Url(credential.response.userHandle) : null,
              },
            };

            responseField.value = JSON.stringify(payload);
            form.submit();
          } catch (error) {
            console.error(error);
            alert(error && error.message ? error.message : 'La validation avec la clé de sécurité a échoué.');
            button.disabled = false;
            button.textContent = initialLabel;
          }
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
