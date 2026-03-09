<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: /connexion");
    exit();
}

require_once '../config_loader.php';
include_once '../includes/lang.php';

if (empty($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

$user = $_SESSION['user'] ?? [];
$profileAlert = null;
$passwordAlert = null;
$isProfileSectionOpen = false;
$isPasswordSectionOpen = false;

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mergeNonNullValues(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if ($value !== null) {
            $base[$key] = $value;
        }
    }

    return $base;
}

function resolveUserAccount(PDO $pdo, array $user, string $select = '*'): ?array
{
    $userId = (int)($user['id'] ?? 0);
    $username = trim((string)($user['username'] ?? ''));
    $siret = trim((string)($user['siret'] ?? ''));

    if ($userId > 0) {
        $stmt = $pdo->prepare(sprintf('SELECT %s FROM users WHERE id = ? LIMIT 1', $select));
        $stmt->execute([$userId]);

        $account = $stmt->fetch();
        return is_array($account) ? $account : null;
    }

    if ($siret !== '' && $username !== '') {
        $stmt = $pdo->prepare(sprintf('SELECT %s FROM users WHERE siret = ? AND username = ? LIMIT 1', $select));
        $stmt->execute([$siret, $username]);

        $account = $stmt->fetch();
        return is_array($account) ? $account : null;
    }

    return null;
}

function normalizeCivilite(?string $value): string
{
    $normalized = trim((string)$value);
    if ($normalized === '') {
        return '';
    }

    $map = [
        'mme' => 'Madame',
        'madame' => 'Madame',
        'mrs' => 'Madame',
        'ms' => 'Madame',
        'female' => 'Madame',
        'm' => 'Monsieur',
        'mr' => 'Monsieur',
        'monsieur' => 'Monsieur',
        'male' => 'Monsieur',
        'dr' => 'Docteur',
        'docteur' => 'Docteur',
        'doctor' => 'Docteur',
        'prof' => 'Professeur',
        'professeur' => 'Professeur',
        'professor' => 'Professeur',
        'autre' => 'Autre',
        'other' => 'Autre',
    ];

    $key = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
    return $map[$key] ?? $normalized;
}

function normalizeLanguageCode(?string $value): string
{
    $normalized = trim((string)$value);
    if ($normalized === '') {
        return 'fr';
    }

    $map = [
        'fr' => 'fr',
        'fr-fr' => 'fr',
        'french' => 'fr',
        'français' => 'fr',
        'francais' => 'fr',
        'en' => 'en',
        'en-us' => 'en',
        'en-gb' => 'en',
        'english' => 'en',
        'es' => 'es',
        'es-es' => 'es',
        'spanish' => 'es',
        'español' => 'es',
        'espanol' => 'es',
        'de' => 'de',
        'de-de' => 'de',
        'german' => 'de',
        'deutsch' => 'de',
    ];

    $key = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
    return $map[$key] ?? 'fr';
}

function passwordValidationErrors(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = t('settings_password_min_length');
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = t('settings_password_uppercase');
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = t('settings_password_lowercase');
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = t('settings_password_number');
    }
    if (!preg_match('/[!@#$%^&*]/', $password)) {
        $errors[] = t('settings_password_special');
    }

    return $errors;
}

try {
    $account = resolveUserAccount($pdo, $user);
    if (is_array($account)) {
        $user = mergeNonNullValues($user, $account);
        $_SESSION['user'] = mergeNonNullValues($_SESSION['user'] ?? [], [
            'id' => $user['id'] ?? null,
            'siret' => $user['siret'] ?? null,
            'username' => $user['username'] ?? null,
            'civilite' => $user['civilite'] ?? null,
            'prenom' => $user['prenom'] ?? null,
            'nom' => $user['nom'] ?? null,
            'langue_code' => $user['langue_code'] ?? null,
            'timezone' => $user['timezone'] ?? null,
        ]);
    }
} catch (Throwable $exception) {
    error_log('Erreur chargement profil utilisateur: ' . $exception->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['settings_action'] ?? '') === 'update_personal_information') {
    $isProfileSectionOpen = true;

    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['settings_csrf_token'] ?? '');

    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $profileAlert = [
            'type' => 'error',
            'message' => t('settings_security_session_expired'),
        ];
    } else {
        $allowedCivilites = ['', 'Madame', 'Monsieur', 'Docteur', 'Professeur', 'Autre'];
        $allowedLanguages = ['fr', 'en', 'es', 'de'];
        $allowedTimezones = ['Europe/Paris', 'Europe/Brussels', 'UTC', 'America/New_York'];

        $submittedCivilite = normalizeCivilite((string)($_POST['civilite'] ?? ($_POST['gender'] ?? '')));
        $submittedFirstName = trim((string)($_POST['first_name'] ?? ''));
        $submittedLastName = trim((string)($_POST['last_name'] ?? ''));
        $submittedLanguage = normalizeLanguageCode((string)($_POST['language'] ?? 'fr'));
        $submittedTimezone = trim((string)($_POST['timezone'] ?? 'Europe/Paris'));

        if (!in_array($submittedCivilite, $allowedCivilites, true)) {
            $profileAlert = [
                'type' => 'error',
                'message' => t('settings_invalid_civility'),
            ];
        } elseif (!in_array($submittedLanguage, $allowedLanguages, true)) {
            $profileAlert = [
                'type' => 'error',
                'message' => t('settings_invalid_language'),
            ];
        } elseif (!in_array($submittedTimezone, $allowedTimezones, true)) {
            $profileAlert = [
                'type' => 'error',
                'message' => t('settings_invalid_timezone'),
            ];
        } elseif (strlen($submittedFirstName) > 100 || strlen($submittedLastName) > 100) {
            $profileAlert = [
                'type' => 'error',
                'message' => t('settings_name_too_long'),
            ];
        } else {
            try {
                $account = resolveUserAccount($pdo, $user, 'id');

                if (!$account || empty($account['id'])) {
                    $profileAlert = [
                        'type' => 'error',
                        'message' => t('settings_account_not_found_info'),
                    ];
                } else {
                    $updateStmt = $pdo->prepare('UPDATE users SET civilite = ?, prenom = ?, nom = ?, langue_code = ?, timezone = ? WHERE id = ?');
                    $updateStmt->execute([
                        $submittedCivilite !== '' ? $submittedCivilite : null,
                        $submittedFirstName,
                        $submittedLastName,
                        $submittedLanguage,
                        $submittedTimezone,
                        (int)$account['id'],
                    ]);

                    $user = mergeNonNullValues($user, [
                        'civilite' => $submittedCivilite,
                        'prenom' => $submittedFirstName,
                        'nom' => $submittedLastName,
                        'langue_code' => $submittedLanguage,
                        'timezone' => $submittedTimezone,
                    ]);

                    $_SESSION['user'] = mergeNonNullValues($_SESSION['user'] ?? [], [
                        'civilite' => $submittedCivilite,
                        'prenom' => $submittedFirstName,
                        'nom' => $submittedLastName,
                        'langue_code' => $submittedLanguage,
                        'timezone' => $submittedTimezone,
                    ]);

                    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));

                    $profileAlert = [
                        'type' => 'success',
                        'message' => t('settings_info_updated'),
                    ];
                }
            } catch (Throwable $exception) {
                error_log('Erreur mise à jour informations personnelles: ' . $exception->getMessage());
                $profileAlert = [
                    'type' => 'error',
                    'message' => t('settings_info_update_error'),
                ];
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['settings_action'] ?? '') === 'change_password') {
    $isPasswordSectionOpen = true;

    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['settings_csrf_token'] ?? '');

    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $passwordAlert = [
            'type' => 'error',
            'message' => t('settings_security_session_expired'),
        ];
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmNewPassword = (string)($_POST['confirm_new_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmNewPassword === '') {
            $passwordAlert = [
                'type' => 'error',
                'message' => t('settings_password_required'),
            ];
        } elseif (!hash_equals($newPassword, $confirmNewPassword)) {
            $passwordAlert = [
                'type' => 'error',
                'message' => t('settings_password_confirm_mismatch'),
            ];
        } else {
            $validationErrors = passwordValidationErrors($newPassword);

            if ($validationErrors !== []) {
                $passwordAlert = [
                    'type' => 'error',
                    'message' => $validationErrors[0],
                ];
            } else {
                try {
                    $account = resolveUserAccount($pdo, $user, 'id, password');

                    if (!$account || empty($account['password'])) {
                        $passwordAlert = [
                            'type' => 'error',
                            'message' => t('settings_account_not_found_password'),
                        ];
                    } elseif (!password_verify($currentPassword, (string)$account['password'])) {
                        $passwordAlert = [
                            'type' => 'error',
                            'message' => t('settings_current_password_incorrect'),
                        ];
                    } elseif (password_verify($newPassword, (string)$account['password'])) {
                        $passwordAlert = [
                            'type' => 'error',
                            'message' => t('settings_new_password_different'),
                        ];
                    } else {
                        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $updateStmt = $pdo->prepare('UPDATE users SET password = ?, login_attempts = 0 WHERE id = ?');
                        $updateStmt->execute([$newPasswordHash, (int)$account['id']]);

                        session_regenerate_id(true);
                        $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));

                        $passwordAlert = [
                            'type' => 'success',
                            'message' => t('settings_password_updated'),
                        ];
                    }
                } catch (Throwable $exception) {
                    error_log('Erreur changement mot de passe: ' . $exception->getMessage());
                    $passwordAlert = [
                        'type' => 'error',
                        'message' => t('settings_password_update_error'),
                    ];
                }
            }
        }
    }
}

$civilityOptions = [
    '' => t('settings_select_option'),
    'Madame' => t('settings_madame'),
    'Monsieur' => t('settings_monsieur'),
    'Docteur' => t('settings_docteur'),
    'Professeur' => t('settings_professeur'),
    'Autre' => t('settings_autre'),
];
$languageOptions = [
    'fr' => t('settings_french'),
    'en' => t('settings_english'),
    'es' => t('settings_spanish'),
    'de' => t('settings_german'),
];
$timezoneOptions = ['Europe/Paris', 'Europe/Brussels', 'UTC', 'America/New_York'];

$rawName = trim((string)($user['nom'] ?? ''));
$firstName = trim((string)($user['prenom'] ?? ''));
$lastName = trim((string)($user['nom'] ?? ''));

if ($firstName === '' && $rawName !== '' && str_contains($rawName, ' ')) {
    $parts = preg_split('/\s+/', $rawName, 2);
    $firstName = trim((string)($parts[0] ?? ''));
    $lastName = trim((string)($parts[1] ?? ''));
}

if ($firstName === '' && $lastName === '' && $rawName !== '') {
    $firstName = $rawName;
}

$email = trim((string)($user['email'] ?? ''));
$phone = trim((string)($user['telephone'] ?? ($user['phone'] ?? '')));
$location = trim((string)($user['ville'] ?? ($user['location'] ?? '')));
$profession = trim((string)($user['fonction'] ?? ($user['profession'] ?? '')));
$education = trim((string)($user['education'] ?? ''));
$language = normalizeLanguageCode((string)($user['langue_code'] ?? ($user['langue'] ?? 'fr')));
$timezone = trim((string)($user['timezone'] ?? 'Europe/Paris'));
$gender = normalizeCivilite((string)($user['civilite'] ?? ($user['genre'] ?? '')));
$birthDate = trim((string)($user['date_naissance'] ?? ($user['birth_date'] ?? '')));
$avatar = trim((string)($user['avatar'] ?? ($user['photo'] ?? '')));
$availability = isset($user['availability']) ? (bool)$user['availability'] : true;

$initialSource = trim($firstName . ' ' . $lastName);
if ($initialSource === '') {
    $initialSource = $email !== '' ? $email : 'U';
}

$initials = '';
$mbUpper = function (string $value): string {
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
};
$mbSub = function (string $value, int $start, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
};

foreach (preg_split('/\s+/', $initialSource) as $chunk) {
    if ($chunk !== '') {
        $initials .= $mbUpper($mbSub($chunk, 0, 1));
    }
    if (strlen($initials) >= 2) {
        break;
    }
}
if ($initials === '') {
    $initials = 'U';
}

$currentIp = $_SERVER['REMOTE_ADDR'] ?? t('settings_unknown_ip');
$currentUserAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? t('settings_unknown_browser')));
if ($currentUserAgent !== '' && strlen($currentUserAgent) > 120) {
    $currentUserAgent = substr($currentUserAgent, 0, 117) . '...';
}
$currentSessionStarted = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?php echo t('settings_page_title'); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="next-size-adjust" content=""/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>

  <style>
    .dashboard-layout {
      display: flex;
      flex-direction: row;
      align-items: stretch;
      width: 100%;
      min-height: 100vh;
    }
    .dashboard-sidebar {
      flex: 0 0 20rem;
      width: 20rem;
      max-width: 20rem;
    }
    .dashboard-main {
      flex: 1 1 auto;
      min-width: 0;
    }
    @media (max-width: 1024px) {
      .dashboard-layout { flex-direction: column; }
      .dashboard-sidebar {
        width: 100%;
        max-width: none;
        flex: 0 0 auto;
        height: auto !important;
      }
      .dashboard-main { padding: 1rem; }
    }

    .settings-shell {
      max-width: 1120px;
      margin: 0 auto;
    }

    .settings-stack {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .settings-section {
      border: 1px solid var(--border);
      border-radius: 1.1rem;
      background: var(--background);
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
      overflow: hidden;
    }

    .settings-section__trigger {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1.5rem;
      text-align: left;
      padding: 1rem 1rem;
      background: transparent;
      border: 0;
      cursor: pointer;
      transition: background 180ms ease;
    }

    .settings-section__trigger:hover {
      background: color-mix(in oklab, var(--muted) 30%, transparent);
    }

    .settings-section__trigger:focus-visible {
      outline: none;
      box-shadow: inset 0 0 0 2px color-mix(in oklab, var(--ring) 60%, transparent);
    }

    .settings-section__trigger[aria-expanded="true"] {
      border-bottom: 1px solid var(--border);
    }

    .settings-section__hero {
      display: flex;
      align-items: center;
      gap: 1rem;
      min-width: 0;
      flex: 1 1 auto;
    }

    .settings-section__icon {
      width: 3.25rem;
      height: 3.25rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 0.9rem;
      border: 1px solid var(--border);
      background: color-mix(in oklab, var(--muted) 65%, transparent);
      color: var(--foreground);
      flex: 0 0 auto;
    }

    .settings-section__copy {
      min-width: 0;
    }

    .settings-section__title {
      margin: 0;
      font-size: clamp(1.25rem, 1.1rem + 0.6vw, 1.85rem);
      line-height: 1.15;
      font-weight: 700;
      letter-spacing: -0.02em;
    }

    .settings-section__subtitle {
      margin: 0.45rem 0 0;
      color: var(--muted-foreground);
      font-size: 0.95rem;
    }

    .settings-section__chevron {
      flex: 0 0 auto;
      color: var(--muted-foreground);
    }

    .settings-section__content {
      padding: 2rem;
      background: color-mix(in oklab, var(--background) 92%, white 8%);
    }

    .settings-grid {
      display: grid;
      gap: 1.5rem;
      grid-template-columns: 1fr;
    }

    .settings-two-cols,
    .settings-form-grid,
    .session-grid {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(1, minmax(0, 1fr));
    }

    .settings-subsection + .settings-subsection {
      margin-top: 1.75rem;
    }

    .settings-subsection__title {
      margin-bottom: 1rem;
      font-size: 0.95rem;
      font-weight: 600;
    }

    .settings-tip {
      border: 1px solid var(--border);
      border-radius: 1rem;
      padding: 1rem 1.1rem;
      background: color-mix(in oklab, var(--muted) 48%, transparent);
    }

    .settings-tip p,
    .settings-tip ul {
      color: var(--muted-foreground);
      font-size: 0.93rem;
    }

    .settings-tip ul {
      margin: 0.5rem 0 0;
      padding-left: 1rem;
    }

    .avatar-uploader {
      display: grid;
      gap: 1.5rem;
      grid-template-columns: 1fr;
      align-items: center;
    }

    .avatar-block {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 1rem;
    }

    .avatar-preview-wrap {
      position: relative;
      width: 4.5rem;
      height: 4.5rem;
      flex: 0 0 auto;
    }

    .avatar-preview {
      width: 100%;
      height: 100%;
      border-radius: 9999px;
      border: 2px solid var(--border);
      overflow: hidden;
      background: color-mix(in oklab, var(--muted) 65%, transparent);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 1rem;
    }

    .avatar-preview img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .avatar-edit-badge {
      position: absolute;
      right: -0.1rem;
      bottom: -0.1rem;
      width: 2rem;
      height: 2rem;
      border-radius: 9999px;
      border: 1px solid var(--border);
      background: color-mix(in oklab, var(--background) 88%, white 12%);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
      cursor: pointer;
      transition: transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease;
    }

    .avatar-edit-badge:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 22px rgba(15, 23, 42, 0.16);
    }

    .avatar-edit-badge:focus-visible {
      outline: none;
      border-color: var(--ring);
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--ring) 22%, transparent);
    }

    .file-modal {
      position: fixed;
      inset: 0;
      z-index: 5000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.25rem;
    }

    .file-modal[hidden] {
      display: none !important;
    }

    .file-modal.is-open .file-modal__backdrop {
      opacity: 1;
    }

    .file-modal.is-open .file-modal__dialog {
      opacity: 1;
      transform: translateY(0) scale(1);
    }

    .file-modal__backdrop {
      position: absolute;
      inset: 0;
      border: 0;
      background: rgba(15, 23, 42, 0.58);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      opacity: 0;
      transition: opacity 180ms ease;
      cursor: pointer;
    }

    .file-modal__dialog {
      position: relative;
      z-index: 1;
      width: min(100%, 36rem);
      border: 1px solid color-mix(in oklab, var(--border) 78%, white 22%);
      border-radius: 1.4rem;
      background: color-mix(in oklab, var(--background) 92%, white 8%);
      box-shadow: 0 24px 70px rgba(15, 23, 42, 0.28);
      padding: 1.25rem;
      opacity: 0;
      transform: translateY(16px) scale(0.98);
      transition: opacity 180ms ease, transform 180ms ease;
    }

    .file-modal__header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .file-modal__title {
      margin: 0;
      font-size: clamp(1.1rem, 1rem + 0.5vw, 1.5rem);
      font-weight: 700;
      letter-spacing: -0.02em;
    }

    .file-modal__subtitle {
      margin: 0.4rem 0 0;
      color: var(--muted-foreground);
      font-size: 0.95rem;
    }

    .file-modal__icon {
      width: 3rem;
      height: 3rem;
      border-radius: 1rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid var(--border);
      background: color-mix(in oklab, var(--muted) 58%, transparent);
      color: var(--foreground);
      flex: 0 0 auto;
    }

    .file-modal__header-copy {
      min-width: 0;
      flex: 1 1 auto;
    }

    .file-modal__close {
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 9999px;
      border: 1px solid var(--border);
      background: transparent;
      color: var(--muted-foreground);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: background 160ms ease, color 160ms ease, border-color 160ms ease;
      flex: 0 0 auto;
    }

    .file-modal__close:hover {
      background: color-mix(in oklab, var(--muted) 58%, transparent);
      color: var(--foreground);
    }

    .file-modal__close:focus-visible,
    .file-modal__browse:focus-visible,
    .file-modal__action:focus-visible,
    .file-modal__dropzone:focus-visible {
      outline: none;
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--ring) 22%, transparent);
      border-color: var(--ring);
    }

    .file-modal__dropzone {
      position: relative;
      border: 1.5px dashed color-mix(in oklab, var(--border) 76%, var(--foreground) 24%);
      border-radius: 1.15rem;
      padding: 1.35rem;
      background: color-mix(in oklab, var(--muted) 42%, transparent);
      text-align: center;
      transition: border-color 160ms ease, background 160ms ease, transform 160ms ease;
      cursor: pointer;
    }

    .file-modal__dropzone:hover,
    .file-modal__dropzone.is-dragover {
      border-color: var(--ring);
      background: color-mix(in oklab, var(--accent) 48%, transparent);
      transform: translateY(-1px);
    }

    .file-modal__drop-icon {
      width: 4rem;
      height: 4rem;
      margin: 0 auto 1rem;
      border-radius: 1.2rem;
      border: 1px solid var(--border);
      background: color-mix(in oklab, var(--background) 82%, white 18%);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: var(--foreground);
    }

    .file-modal__drop-title {
      margin: 0;
      font-size: 1rem;
      font-weight: 600;
    }

    .file-modal__drop-text {
      margin: 0.45rem 0 0;
      color: var(--muted-foreground);
      font-size: 0.92rem;
    }

    .file-modal__browse {
      margin-top: 1rem;
      border: 1px solid var(--border);
      background: color-mix(in oklab, var(--background) 88%, white 12%);
      color: var(--foreground);
      border-radius: 0.85rem;
      min-height: 2.75rem;
      padding: 0.7rem 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 160ms ease, border-color 160ms ease, transform 160ms ease;
    }

    .file-modal__browse:hover,
    .file-modal__action:hover {
      transform: translateY(-1px);
    }

    .file-modal__browse:hover {
      background: color-mix(in oklab, var(--muted) 62%, transparent);
    }

    .file-modal__selected {
      margin-top: 1rem;
      padding: 0.85rem 1rem;
      border-radius: 0.95rem;
      border: 1px solid var(--border);
      background: color-mix(in oklab, var(--background) 86%, white 14%);
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .file-modal__selected-icon {
      width: 2.4rem;
      height: 2.4rem;
      border-radius: 0.8rem;
      border: 1px solid var(--border);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: color-mix(in oklab, var(--muted) 58%, transparent);
      flex: 0 0 auto;
    }

    .file-modal__selected-label {
      display: block;
      font-size: 0.84rem;
      color: var(--muted-foreground);
    }

    .file-modal__selected-name {
      display: block;
      font-weight: 600;
      word-break: break-word;
    }

    .file-modal__footer {
      display: flex;
      justify-content: flex-end;
      gap: 0.75rem;
      margin-top: 1rem;
      flex-wrap: wrap;
    }

    .file-modal__action {
      min-height: 2.75rem;
      padding: 0.75rem 1rem;
      border-radius: 0.85rem;
      border: 1px solid var(--border);
      background: transparent;
      font-weight: 600;
      cursor: pointer;
      transition: transform 160ms ease, background 160ms ease, border-color 160ms ease;
    }

    .file-modal__action--primary {
      background: var(--primary);
      border-color: var(--primary);
      color: var(--primary-foreground);
    }

    .file-modal__action--primary:hover {
      background: color-mix(in oklab, var(--primary) 90%, black 10%);
    }

    body.modal-open {
      overflow: hidden;
    }

    .settings-field {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .settings-field label {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.92rem;
      font-weight: 500;
    }

    .settings-input,
    .settings-select,
    .settings-textarea {
      width: 100%;
      min-height: 2.5rem;
      border: 1px solid var(--border);
      border-radius: 0.7rem;
      background: transparent;
      padding: 0.7rem 0.9rem;
      font-size: 0.95rem;
      outline: none;
      transition: box-shadow 160ms ease, border-color 160ms ease;
    }

    .settings-input:focus,
    .settings-select:focus,
    .settings-textarea:focus {
      border-color: var(--ring);
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--ring) 18%, transparent);
    }

    .settings-textarea {
      min-height: 7rem;
      resize: vertical;
    }

    .settings-input-wrap {
      position: relative;
    }

    .settings-input-wrap .settings-input {
      padding-right: 3.35rem;
    }

    .settings-inline-button {
      position: absolute;
      top: 50%;
      right: 0.55rem;
      transform: translateY(-50%);
      border: 0;
      background: transparent;
      color: var(--muted-foreground);
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
    }

    .settings-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      justify-content: flex-end;
    }

    .password-layout {
      display: grid;
      gap: 1.75rem;
      grid-template-columns: 1fr;
    }

    .password-rules {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 0.8rem;
    }

    .password-rules li {
      display: flex;
      align-items: flex-start;
      gap: 0.7rem;
      color: var(--muted-foreground);
      font-size: 0.92rem;
    }

    .password-rule-icon {
      width: 1.2rem;
      height: 1.2rem;
      border-radius: 9999px;
      border: 1px solid currentColor;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.72rem;
      line-height: 1;
      flex: 0 0 auto;
      margin-top: 0.08rem;
    }

    .password-rules li.is-valid {
      color: rgb(22 163 74);
    }

    .session-card {
      border: 1px solid var(--border);
      border-radius: 1rem;
      padding: 1rem;
      background: var(--background);
      box-shadow: 0 6px 24px rgba(15, 23, 42, 0.05);
    }

    .session-card__head {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .session-card__meta {
      display: grid;
      gap: 0.55rem;
      color: var(--muted-foreground);
      font-size: 0.92rem;
    }

    .session-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      border: 1px solid var(--border);
      border-radius: 9999px;
      padding: 0.35rem 0.7rem;
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--foreground);
      background: color-mix(in oklab, var(--muted) 48%, transparent);
    }

    .status-toggle {
      display: inline-flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .status-switch {
      position: relative;
      width: 3rem;
      height: 1.7rem;
      border-radius: 9999px;
      border: 1px solid transparent;
      background: var(--input);
      cursor: pointer;
      transition: background 160ms ease;
    }

    .status-switch::after {
      content: "";
      position: absolute;
      top: 1px;
      left: 1px;
      width: 1.45rem;
      height: 1.45rem;
      border-radius: 9999px;
      background: var(--background);
      transition: transform 160ms ease;
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.18);
    }

    .status-switch.is-on {
      background: var(--primary);
    }

    .status-switch.is-on::after {
      transform: translateX(1.3rem);
    }

    .status-text {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      font-size: 0.92rem;
      font-weight: 600;
    }

    .status-dot {
      width: 0.65rem;
      height: 0.65rem;
      border-radius: 9999px;
      background: rgb(34 197 94);
      box-shadow: 0 0 0 6px rgba(34, 197, 94, 0.14);
    }

    .session-remove {
      border: 1px solid color-mix(in oklab, var(--destructive) 35%, var(--border) 65%);
      background: color-mix(in oklab, var(--destructive) 8%, transparent);
      color: color-mix(in oklab, var(--destructive) 90%, var(--foreground) 10%);
    }

    .muted-copy {
      color: var(--muted-foreground);
      font-size: 0.92rem;
    }

    .settings-alert {
      border-radius: 0.9rem;
      border: 1px solid var(--border);
      padding: 0.95rem 1rem;
      font-size: 0.94rem;
      font-weight: 500;
    }

    .settings-alert--success {
      border-color: color-mix(in oklab, rgb(22 163 74) 45%, var(--border) 55%);
      background: color-mix(in oklab, rgb(22 163 74) 12%, transparent);
      color: rgb(21 128 61);
    }

    .settings-alert--error {
      border-color: color-mix(in oklab, var(--destructive) 45%, var(--border) 55%);
      background: color-mix(in oklab, var(--destructive) 10%, transparent);
      color: color-mix(in oklab, var(--destructive) 88%, black 12%);
    }

    .visually-hidden {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    @media (min-width: 768px) {
      .avatar-uploader {
        grid-template-columns: minmax(0, 1fr) auto;
      }
      .settings-two-cols {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .session-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (min-width: 1024px) {
      .settings-form-grid.settings-form-grid--4 {
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }
      .password-layout {
        grid-template-columns: minmax(0, 1.2fr) minmax(280px, 0.8fr);
      }
    }

    @media (max-width: 767px) {
      .settings-section__trigger,
      .settings-section__content {
        padding: 1.25rem;
      }
      .settings-section__hero {
        align-items: flex-start;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .settings-section__trigger,
      .status-switch,
      .status-switch::after {
        transition: none !important;
      }
    }

    .collapsible-content {
      overflow: hidden;
      height: 0;
      opacity: 0;
      transition: height 220ms ease, opacity 220ms ease;
      will-change: height, opacity;
    }
    .collapsible-content.is-open {
      opacity: 1;
    }
    .collapsible-trigger .collapsible-chevron {
      transition: transform 220ms ease;
      will-change: transform;
    }
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron {
      transform: rotate(90deg);
    }
    @media (prefers-reduced-motion: reduce) {
      .collapsible-content,
      .collapsible-trigger .collapsible-chevron {
        transition: none !important;
      }
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>
  <div class="dashboard-layout">
    <?php include('../include/menu.php'); ?>

    <main class="dashboard-main">
      <div class="w-full min-h-screen bg-surface p-4 md:p-6 lg:p-8">
        <div class="w-full">
          <div class="settings-stack">

            <section data-slot="collapsible" class="settings-section">
              <button
                type="button"
                data-slot="collapsible-trigger"
                class="settings-section__trigger"
                aria-expanded="true"
                aria-controls="settings-profile-picture"
              >
                <span class="settings-section__hero">
                  <span class="settings-section__icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                      <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
                      <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                  </span>
                  <span class="settings-section__copy">
                    <h2 class="text-base"><?php echo t('settings_profile_picture_title'); ?></h2>
                    <p class="text-muted-foreground text-sm"><?php echo t('settings_profile_picture_subtitle'); ?></p>
                  </span>
                </span>
                <span class="settings-section__chevron" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-right h-5 w-5">
                    <path d="m9 18 6-6-6-6"></path>
                  </svg>
                </span>
              </button>

              <div id="settings-profile-picture" data-slot="collapsible-content" class="settings-section__content">
                <div class="settings-grid">
                  <div class="avatar-uploader">
                    <div class="avatar-block">
                      <div class="avatar-preview-wrap">
                        <div class="avatar-preview" data-avatar-preview>
                          <?php if ($avatar !== ''): ?>
                            <img src="<?= e($avatar) ?>" alt="<?php echo t('settings_avatar_alt'); ?>" data-avatar-image>
                          <?php else: ?>
                            <span data-avatar-fallback><?= e($initials) ?></span>
                          <?php endif; ?>
                        </div>
                        <button type="button" class="avatar-edit-badge" data-avatar-dialog-open aria-label="<?php echo t('settings_edit_avatar'); ?>">
                          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"></path>
                          </svg>
                        </button>
                      </div>

                      <div>
                        <h3 class="font-semibold"><?php echo t('settings_select_upload_image'); ?></h3>
                        <p class="muted-copy"><?php echo t('settings_select_upload_image_hint'); ?></p>
                      </div>
                    </div>

                    <div class="status-toggle">
                      <button type="button" class="status-switch<?= $availability ? ' is-on' : '' ?>" role="switch" aria-checked="<?= $availability ? 'true' : 'false' ?>" data-availability-switch></button>
                      <span class="status-text">
                        <span class="status-dot"></span>
                        <span data-availability-text><?= $availability ? t('settings_availability_online') : t('settings_availability_offline') ?></span>
                      </span>
                    </div>
                  </div>

                  <div class="flex flex-wrap items-center gap-3">
                    <input type="file" id="avatar-upload" accept="image/png,image/jpeg,image/svg+xml" class="visually-hidden" data-avatar-input>
                    <button type="button" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2" data-avatar-dialog-open>
                      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" x2="12" y1="3" y2="15"></line>
                      </svg>
                      Importer un fichier
                    </button>
                  </div>

                  <div class="file-modal" id="avatar-upload-modal" hidden data-file-modal>
                    <button type="button" class="file-modal__backdrop" aria-label="<?php echo t('settings_close_import_window'); ?>" data-file-modal-close></button>

                    <div class="file-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="avatar-upload-title" aria-describedby="avatar-upload-description" tabindex="-1" data-file-modal-panel>
                      <div class="file-modal__header">
                        <span class="file-modal__icon" aria-hidden="true">
                          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="17 8 12 3 7 8"></polyline>
                            <line x1="12" x2="12" y1="3" y2="15"></line>
                          </svg>
                        </span>

                        <div class="file-modal__header-copy">
                          <h3 id="avatar-upload-title" class="file-modal__title"><?php echo t('settings_import_image'); ?></h3>
                          <p id="avatar-upload-description" class="file-modal__subtitle"><?php echo t('settings_import_image_subtitle'); ?></p>
                        </div>

                        <button type="button" class="file-modal__close" aria-label="<?php echo t('settings_close'); ?>" data-file-modal-close>
                          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18"></path>
                            <path d="m6 6 12 12"></path>
                          </svg>
                        </button>
                      </div>

                      <div class="file-modal__dropzone" data-file-dropzone tabindex="0">
                        <span class="file-modal__drop-icon" aria-hidden="true">
                          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 13V3"></path>
                            <path d="m7 8 5-5 5 5"></path>
                            <path d="M20 21H4"></path>
                            <path d="M19 13v3a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-3"></path>
                          </svg>
                        </span>
                        <p class="file-modal__drop-title"><?php echo t('settings_drag_drop_image'); ?></p>
                        <p class="file-modal__drop-text"><?php echo t('settings_formats_hint'); ?></p>
                        <button type="button" class="file-modal__browse" data-file-browse><?php echo t('settings_browse_files'); ?></button>
                      </div>

                      <div class="file-modal__selected" aria-live="polite">
                        <span class="file-modal__selected-icon" aria-hidden="true">
                          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.5 2H8a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7.5L14.5 2z"></path>
                            <path d="M14 2v6h6"></path>
                          </svg>
                        </span>
                        <div>
                          <span class="file-modal__selected-label"><?php echo t('settings_selected_file'); ?></span>
                          <span class="file-modal__selected-name" data-file-selected-name><?php echo t('settings_no_file_yet'); ?></span>
                        </div>
                      </div>

                      <div class="file-modal__footer">
                        <button type="button" class="file-modal__action" data-file-modal-close>Annuler</button>
                        <button type="button" class="file-modal__action file-modal__action--primary" data-file-browse><?php echo t('settings_choose_file'); ?></button>
                      </div>
                    </div>
                  </div>

                  <div class="settings-tip">
                    <div class="flex items-start gap-3">
                      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 text-blue-500">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="m9 12 2 2 4-4"></path>
                      </svg>
                      <div>
                        <h4 class="mb-1 text-sm font-medium"><?php echo t('settings_profile_picture_tips_title'); ?></h4>
                        <p><?php echo t('settings_profile_picture_tips_text'); ?></p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <section data-slot="collapsible" class="settings-section">
              <button
                type="button"
                data-slot="collapsible-trigger"
                class="settings-section__trigger"
                aria-expanded="<?= $isProfileSectionOpen ? 'true' : 'false' ?>"
                aria-controls="settings-basic-info"
              >
                <span class="settings-section__hero">
                  <span class="settings-section__icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                      <path d="M4 19.5V18a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1.5"></path>
                      <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                  </span>
                  <span class="settings-section__copy">
                    <h2 class="text-base"><?php echo t('settings_personal_information_title'); ?></h2>
                    <p class="text-muted-foreground text-sm"><?php echo t('settings_personal_information_subtitle'); ?></p>
                  </span>
                </span>
                <span class="settings-section__chevron" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-right h-5 w-5">
                    <path d="m9 18 6-6-6-6"></path>
                  </svg>
                </span>
              </button>

              <div id="settings-basic-info" data-slot="collapsible-content" class="settings-section__content"<?= $isProfileSectionOpen ? "" : " hidden" ?>>
                <form class="settings-grid" action="" method="post" novalidate>
                  <input type="hidden" name="settings_action" value="update_personal_information">
                  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['settings_csrf_token'] ?? '') ?>">

                  <?php if ($profileAlert !== null): ?>
                    <div class="settings-alert settings-alert--<?= e($profileAlert['type'] ?? 'error') ?>" role="alert">
                      <?= e($profileAlert['message'] ?? '') ?>
                    </div>
                  <?php endif; ?>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title"><?php echo t('settings_basic_details_title'); ?></h3>
                    <div class=" settings-form-grid settings-form-grid--4">
                      <div class="settings-field">
                        <label for="civilite"><?php echo t('settings_civility_label'); ?></label>
                        <select id="civilite" name="civilite" class="settings-select">
                          <?php foreach ($civilityOptions as $civiliteValue => $civiliteLabel): ?>
                            <option value="<?= e($civiliteValue) ?>"<?= $gender === $civiliteValue ? ' selected' : '' ?>><?= e($civiliteLabel) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="settings-field">
                        <label for="firstName"><?php echo t('settings_first_name_label'); ?></label>
                        <input id="firstName" name="first_name" class="settings-input" type="text" value="<?= e($firstName) ?>" placeholder="<?php echo t('settings_first_name_placeholder'); ?>" maxlength="100">
                      </div>
                      <div class="settings-field">
                        <label for="lastName"><?php echo t('settings_last_name_label'); ?></label>
                        <input id="lastName" name="last_name" class="settings-input" type="text" value="<?= e($lastName) ?>" placeholder="<?php echo t('settings_last_name_placeholder'); ?>" maxlength="100">
                      </div>
                    </div>
                  </div>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title"><?php echo t('settings_professional_info_soon'); ?></h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="profession"><?php echo t('settings_profession_label'); ?></label>
                        <input id="profession" name="profession" class="settings-input" type="text" value="<?= e($profession) ?>" placeholder="<?php echo t('settings_profession_placeholder'); ?>" disabled>
                      </div>
                      <div class="settings-field">
                        <label for="education"><?php echo t('settings_education_label'); ?></label>
                        <input id="education" name="education" class="settings-input" type="text" value="<?= e($education) ?>" placeholder="<?php echo t('settings_education_placeholder'); ?>" disabled>
                      </div>
                    </div>
                  </div>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title"><?php echo t('settings_contact_info_soon'); ?></h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="email"><?php echo t('settings_email_address_label'); ?></label>
                        <input id="email" name="email" class="settings-input" type="email" value="<?= e($email) ?>" placeholder="<?php echo t('settings_email_placeholder'); ?>" disabled>
                      </div>
                      <div class="settings-field">
                        <label for="confirmEmail"><?php echo t('settings_confirm_email_label'); ?></label>
                        <input id="confirmEmail" name="confirm_email" class="settings-input" type="email" value="<?= e($email) ?>" placeholder="<?php echo t('settings_email_placeholder'); ?>" disabled>
                      </div>
                      <div class="settings-field">
                        <label for="phone"><?php echo t('settings_phone_number_label'); ?></label>
                        <input id="phone" name="phone" class="settings-input" type="tel" value="<?= e($phone) ?>" placeholder="<?php echo t('settings_phone_placeholder'); ?>" disabled>
                      </div>
                      <div class="settings-field">
                        <label for="location"><?php echo t('settings_location_label'); ?></label>
                        <input id="location" name="location" class="settings-input" type="text" value="<?= e($location) ?>" placeholder="<?php echo t('settings_location_placeholder'); ?>" disabled>
                      </div>
                    </div>
                  </div>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title"><?php echo t('settings_additional_info_title'); ?></h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="language"><?php echo t('settings_preferred_language_label'); ?></label>
                        <select id="language" name="language" class="settings-select">
                          <?php foreach ($languageOptions as $languageCode => $languageLabel): ?>
                            <option value="<?= e($languageCode) ?>"<?= $language === $languageCode ? ' selected' : '' ?>><?= e($languageLabel) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="settings-field">
                        <label for="timezone"><?php echo t('settings_timezone_label'); ?></label>
                        <select id="timezone" name="timezone" class="settings-select">
                          <?php foreach ($timezoneOptions as $tz): ?>
                            <option value="<?= e($tz) ?>"<?= $timezone === $tz ? ' selected' : '' ?>><?= e($tz) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                  </div>

                  <div class="settings-actions">
                    <button type="reset" class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-medium"><?php echo t('cancel_button'); ?></button>
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"><?php echo t('settings_save_changes'); ?></button>
                  </div>
                </form>
              </div>
            </section>

            <section data-slot="collapsible" class="settings-section">
              <button
                type="button"
                data-slot="collapsible-trigger"
                class="settings-section__trigger"
                aria-expanded="<?= $isPasswordSectionOpen ? 'true' : 'false' ?>"
                aria-controls="settings-password"
              >
                <span class="settings-section__hero">
                  <span class="settings-section__icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                      <rect width="18" height="11" x="3" y="11" rx="2"></rect>
                      <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                  </span>
                  <span class="settings-section__copy">
                    <h2 class="text-base"><?php echo t('settings_change_password_title'); ?></h2>
                    <p class="text-muted-foreground text-sm"><?php echo t('settings_change_password_subtitle'); ?></p>
                  </span>
                </span>
                <span class="settings-section__chevron" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-right h-5 w-5">
                    <path d="m9 18 6-6-6-6"></path>
                  </svg>
                </span>
              </button>

              <div id="settings-password" data-slot="collapsible-content" class="settings-section__content"<?= $isPasswordSectionOpen ? '' : ' hidden' ?>>
                <div class="password-layout">
                  <form class="settings-grid" action="" method="post" novalidate>
                    <input type="hidden" name="settings_action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['settings_csrf_token'] ?? '') ?>">

                    <?php if ($passwordAlert !== null): ?>
                      <div class="settings-alert settings-alert--<?= e($passwordAlert['type'] ?? 'error') ?>" role="alert">
                        <?= e($passwordAlert['message'] ?? '') ?>
                      </div>
                    <?php endif; ?>

                    <div class="settings-field">
                      <label for="currentPassword"><?php echo t('settings_current_password_label'); ?></label>
                      <div class="settings-input-wrap">
                        <input id="currentPassword" name="current_password" class="settings-input" type="password" autocomplete="current-password" required>
                        <button type="button" class="settings-inline-button" data-password-toggle="currentPassword"><?php echo t('show_button'); ?></button>
                      </div>
                    </div>

                    <div class="settings-field">
                      <label for="newPassword"><?php echo t('settings_new_password_label'); ?></label>
                      <div class="settings-input-wrap">
                        <input id="newPassword" name="new_password" class="settings-input" type="password" autocomplete="new-password" data-password-source required>
                        <button type="button" class="settings-inline-button" data-password-toggle="newPassword"><?php echo t('show_button'); ?></button>
                      </div>
                    </div>

                    <div class="settings-field">
                      <label for="confirmNewPassword"><?php echo t('settings_confirm_new_password_label'); ?></label>
                      <div class="settings-input-wrap">
                        <input id="confirmNewPassword" name="confirm_new_password" class="settings-input" type="password" autocomplete="new-password" required>
                        <button type="button" class="settings-inline-button" data-password-toggle="confirmNewPassword"><?php echo t('show_button'); ?></button>
                      </div>
                    </div>

                    <div class="settings-actions">
                      <button type="reset" class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-medium"><?php echo t('cancel_button'); ?></button>
                      <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"><?php echo t('settings_update_password_button'); ?></button>
                    </div>
                  </form>

                  <div class="settings-grid">
                    <div>
                      <h3 class="settings-subsection__title"><?php echo t('settings_password_requirements_title'); ?></h3>
                      <p class="muted-copy mb-4"><?php echo t('settings_password_requirements_subtitle'); ?></p>
                      <ul class="password-rules">
                        <li data-password-rule="length"><span class="password-rule-icon">×</span><span><?php echo t('settings_rule_length'); ?></span></li>
                        <li data-password-rule="uppercase"><span class="password-rule-icon">×</span><span><?php echo t('settings_rule_uppercase'); ?></span></li>
                        <li data-password-rule="lowercase"><span class="password-rule-icon">×</span><span><?php echo t('settings_rule_lowercase'); ?></span></li>
                        <li data-password-rule="number"><span class="password-rule-icon">×</span><span><?php echo t('settings_rule_number'); ?></span></li>
                        <li data-password-rule="special"><span class="password-rule-icon">×</span><span><?php echo t('settings_rule_special'); ?></span></li>
                      </ul>
                    </div>

                    <div class="settings-tip">
                      <h4 class="mb-2 text-sm font-medium"><?php echo t('settings_security_best_practices_title'); ?></h4>
                      <ul>
                        <li><?php echo t('settings_security_tip_regularly'); ?></li>
                        <li><?php echo t('settings_security_tip_never_share'); ?></li>
                        <li><?php echo t('settings_security_tip_unique'); ?></li>
                        <li><?php echo t('settings_security_tip_manager'); ?></li>
                      </ul>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <section data-slot="collapsible" class="settings-section">
              <button
                type="button"
                data-slot="collapsible-trigger"
                class="settings-section__trigger"
                aria-expanded="false"
                aria-controls="settings-sessions"
              >
                <span class="settings-section__hero">
                  <span class="settings-section__icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                      <rect width="14" height="8" x="5" y="2" rx="1"></rect>
                      <rect width="20" height="8" x="2" y="14" rx="1"></rect>
                      <path d="M6 18h.01"></path>
                      <path d="M10 18h.01"></path>
                    </svg>
                  </span>
                  <span class="settings-section__copy">
                    <h2 class="text-base"><?php echo t('settings_active_sessions_title'); ?></h2>
                    <p class="text-muted-foreground text-sm"><?php echo t('settings_active_sessions_subtitle'); ?></p>
                  </span>
                </span>
                <span class="settings-section__chevron" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-right h-5 w-5">
                    <path d="m9 18 6-6-6-6"></path>
                  </svg>
                </span>
              </button>

              <div id="settings-sessions" data-slot="collapsible-content" class="settings-section__content" hidden>
                <div class="settings-grid">
                  <div class="session-grid">
                    <article class="session-card" data-session-card>
                      <div class="session-card__head">
                        <div>
                          <div class="session-chip"><?php echo t('settings_current_session'); ?></div>
                          <h4 class="mt-3 text-base font-semibold"><?php echo t('settings_laptop_session'); ?></h4>
                        </div>
                      </div>
                      <div class="session-card__meta">
                        <div><strong><?php echo t('settings_browser_label'); ?></strong> <?= e($currentUserAgent) ?></div>
                        <div><strong><?php echo t('settings_ip_label'); ?></strong> <?= e($currentIp) ?></div>
                        <div><strong><?php echo t('settings_started_label'); ?></strong> <?= e($currentSessionStarted) ?></div>
                      </div>
                    </article>

                    <article class="session-card" data-session-card>
                      <div class="session-card__head">
                        <div>
                          <div class="session-chip"><?php echo t('settings_mobile'); ?></div>
                          <h4 class="mt-3 text-base font-semibold"><?php echo t('settings_smartphone_session'); ?></h4>
                        </div>
                        <button type="button" class="inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium session-remove" data-remove-session><?php echo t('settings_remove'); ?></button>
                      </div>
                      <div class="session-card__meta">
                        <div><strong><?php echo t('settings_browser_label'); ?></strong> <?php echo t('settings_safari_iphone'); ?></div>
                        <div><strong><?php echo t('settings_location_meta_label'); ?></strong> <?php echo t('settings_paris_france'); ?></div>
                        <div><strong><?php echo t('settings_last_active_label'); ?></strong> <?php echo t('settings_two_hours_ago'); ?></div>
                      </div>
                    </article>

                    <article class="session-card" data-session-card>
                      <div class="session-card__head">
                        <div>
                          <div class="session-chip"><?php echo t('settings_workstation'); ?></div>
                          <h4 class="mt-3 text-base font-semibold"><?php echo t('settings_desktop_session'); ?></h4>
                        </div>
                        <button type="button" class="inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium session-remove" data-remove-session><?php echo t('settings_remove'); ?></button>
                      </div>
                      <div class="session-card__meta">
                        <div><strong><?php echo t('settings_browser_label'); ?></strong> <?php echo t('settings_chrome_windows'); ?></div>
                        <div><strong><?php echo t('settings_location_meta_label'); ?></strong> <?php echo t('settings_brussels_belgium'); ?></div>
                        <div><strong><?php echo t('settings_last_active_label'); ?></strong> <?php echo t('settings_yesterday_1842'); ?></div>
                      </div>
                    </article>
                  </div>

                  <div class="settings-tip">
                    <div class="flex items-start gap-3">
                      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 text-blue-500">
                        <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path>
                      </svg>
                      <div>
                        <h4 class="mb-1 text-sm font-medium"><?php echo t('settings_security_tip_title'); ?></h4>
                        <p><?php echo t('settings_security_tip_text'); ?></p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>

          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    (function () {
      function ready(fn) {
        if (document.readyState !== 'loading') {
          fn();
        } else {
          document.addEventListener('DOMContentLoaded', fn);
        }
      }

      ready(function () {
        var triggers = document.querySelectorAll('[data-slot="collapsible-trigger"]');
        triggers.forEach(function (btn) {
          btn.classList.add('collapsible-trigger');

          var targetId = btn.getAttribute('aria-controls');
          var content = targetId ? document.getElementById(targetId) : null;
          if (!content) {
            var parent = btn.closest('[data-slot="collapsible"]');
            if (parent) {
              content = parent.querySelector('[data-slot="collapsible-content"]');
            }
          }
          if (!content) return;

          content.classList.add('collapsible-content');

          var chev = btn.querySelector('.lucide-chevron-right');
          if (chev) {
            chev.classList.add('collapsible-chevron');
          }

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
              btn.setAttribute('data-state', 'open');
              content.hidden = false;
              content.classList.add('is-open');
              content.setAttribute('data-state', 'open');
              content.style.height = '0px';
              var h = content.scrollHeight;
              requestAnimationFrame(function () {
                content.style.height = h + 'px';
              });

              var onEnd = function (ev) {
                if (ev.propertyName !== 'height') return;
                content.style.height = 'auto';
                content.removeEventListener('transitionend', onEnd);
              };
              content.addEventListener('transitionend', onEnd);
            } else {
              btn.setAttribute('aria-expanded', 'false');
              btn.setAttribute('data-state', 'closed');
              content.classList.remove('is-open');
              content.setAttribute('data-state', 'closed');
              var current = content.scrollHeight;
              content.style.height = current + 'px';
              requestAnimationFrame(function () {
                content.style.height = '0px';
              });

              var onEndClose = function (ev) {
                if (ev.propertyName !== 'height') return;
                content.hidden = true;
                content.removeEventListener('transitionend', onEndClose);
              };
              content.addEventListener('transitionend', onEndClose);
            }
          }, { passive: false });
        });

        var avatarTriggers = document.querySelectorAll('[data-avatar-dialog-open]');
        var avatarInput = document.querySelector('[data-avatar-input]');
        var avatarPreview = document.querySelector('[data-avatar-preview]');
        var avatarModal = document.querySelector('[data-file-modal]');
        var avatarModalPanel = document.querySelector('[data-file-modal-panel]');
        var avatarDropzone = document.querySelector('[data-file-dropzone]');
        var avatarBrowseButtons = document.querySelectorAll('[data-file-browse]');
        var avatarModalCloseButtons = document.querySelectorAll('[data-file-modal-close]');
        var avatarSelectedName = document.querySelector('[data-file-selected-name]');
        var lastAvatarTrigger = null;

        var updateAvatarPreview = function (file) {
          if (!file || !avatarPreview) return;
          if (!file.type.match(/^image\//)) return;

          var reader = new FileReader();
          reader.onload = function (event) {
            avatarPreview.innerHTML = '';
            var img = document.createElement('img');
            img.src = event.target.result;
            img.alt = "<?php echo t('settings_avatar_preview_alt'); ?>";
            avatarPreview.appendChild(img);
          };
          reader.readAsDataURL(file);
        };

        var openAvatarModal = function (trigger) {
          if (!avatarModal || !avatarModalPanel) return;
          lastAvatarTrigger = trigger || document.activeElement;
          avatarModal.hidden = false;
          document.body.classList.add('modal-open');
          requestAnimationFrame(function () {
            avatarModal.classList.add('is-open');
            avatarModalPanel.focus();
          });
        };

        var closeAvatarModal = function () {
          if (!avatarModal) return;
          avatarModal.classList.remove('is-open');
          document.body.classList.remove('modal-open');
          setTimeout(function () {
            avatarModal.hidden = true;
          }, 180);
          if (lastAvatarTrigger && typeof lastAvatarTrigger.focus === 'function') {
            lastAvatarTrigger.focus();
          }
        };

        avatarTriggers.forEach(function (trigger) {
          trigger.addEventListener('click', function () {
            openAvatarModal(trigger);
          });
        });

        avatarModalCloseButtons.forEach(function (button) {
          button.addEventListener('click', function () {
            closeAvatarModal();
          });
        });

        avatarBrowseButtons.forEach(function (button) {
          button.addEventListener('click', function () {
            if (avatarInput) {
              avatarInput.click();
            }
          });
        });

        if (avatarDropzone && avatarInput) {
          avatarDropzone.addEventListener('click', function (event) {
            if (event.target.closest('[data-file-browse]')) return;
            avatarInput.click();
          });

          avatarDropzone.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              avatarInput.click();
            }
          });

          ['dragenter', 'dragover'].forEach(function (eventName) {
            avatarDropzone.addEventListener(eventName, function (event) {
              event.preventDefault();
              avatarDropzone.classList.add('is-dragover');
            });
          });

          ['dragleave', 'dragend', 'drop'].forEach(function (eventName) {
            avatarDropzone.addEventListener(eventName, function (event) {
              event.preventDefault();
              if (eventName !== 'drop') {
                avatarDropzone.classList.remove('is-dragover');
              }
            });
          });

          avatarDropzone.addEventListener('drop', function (event) {
            avatarDropzone.classList.remove('is-dragover');
            var file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
            if (!file || !file.type.match(/^image\//)) return;

            if (avatarInput && window.DataTransfer) {
              var transfer = new DataTransfer();
              transfer.items.add(file);
              avatarInput.files = transfer.files;
            }

            updateAvatarPreview(file);
            if (avatarSelectedName) {
              avatarSelectedName.textContent = file.name;
            }
            closeAvatarModal();
          });

          avatarInput.addEventListener('change', function () {
            var file = avatarInput.files && avatarInput.files[0];
            if (!file || !file.type.match(/^image\//)) return;
            updateAvatarPreview(file);
            if (avatarSelectedName) {
              avatarSelectedName.textContent = file.name;
            }
            closeAvatarModal();
          });
        }

        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && avatarModal && !avatarModal.hidden) {
            closeAvatarModal();
          }
        });

        var availabilitySwitch = document.querySelector('[data-availability-switch]');
        var availabilityText = document.querySelector('[data-availability-text]');
        if (availabilitySwitch && availabilityText) {
          availabilitySwitch.addEventListener('click', function () {
            var isOn = availabilitySwitch.getAttribute('aria-checked') === 'true';
            availabilitySwitch.setAttribute('aria-checked', isOn ? 'false' : 'true');
            availabilitySwitch.classList.toggle('is-on', !isOn);
            availabilityText.textContent = isOn ? "<?php echo t('settings_availability_offline'); ?>" : "<?php echo t('settings_availability_online'); ?>";
          });
        }

        document.querySelectorAll('[data-password-toggle]').forEach(function (toggle) {
          toggle.addEventListener('click', function () {
            var inputId = toggle.getAttribute('data-password-toggle');
            var input = document.getElementById(inputId);
            if (!input) return;
            var isPassword = input.getAttribute('type') === 'password';
            input.setAttribute('type', isPassword ? 'text' : 'password');
            toggle.textContent = isPassword ? "<?php echo t('hide_button'); ?>" : "<?php echo t('show_button'); ?>";
          });
        });

        var passwordSource = document.querySelector('[data-password-source]');
        if (passwordSource) {
          var rules = {
            length: function (value) { return value.length >= 8; },
            uppercase: function (value) { return /[A-Z]/.test(value); },
            lowercase: function (value) { return /[a-z]/.test(value); },
            number: function (value) { return /\d/.test(value); },
            special: function (value) { return /[!@#$%^&*]/.test(value); }
          };

          var updateRules = function () {
            var value = passwordSource.value || '';
            Object.keys(rules).forEach(function (ruleName) {
              var item = document.querySelector('[data-password-rule="' + ruleName + '"]');
              if (!item) return;
              var valid = rules[ruleName](value);
              item.classList.toggle('is-valid', valid);
              var icon = item.querySelector('.password-rule-icon');
              if (icon) {
                icon.textContent = valid ? '✓' : '×';
              }
            });
          };

          passwordSource.addEventListener('input', updateRules);
          updateRules();
        }

        document.querySelectorAll('[data-remove-session]').forEach(function (button) {
          button.addEventListener('click', function () {
            var card = button.closest('[data-session-card]');
            if (!card) return;
            card.style.opacity = '0';
            card.style.transform = 'translateY(-6px)';
            setTimeout(function () {
              card.remove();
            }, 180);
          });
        });
      });
    })();
  </script>

  <script>
    window.K8S_API_URL = "../k8s/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../k8s/k8s-menu.js" defer></script>
</body>
</html>
