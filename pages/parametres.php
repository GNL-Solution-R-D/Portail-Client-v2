<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: /connexion");
    exit();
}

require_once '../config_loader.php';

$user = $_SESSION['user'] ?? [];

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
$language = trim((string)($user['langue'] ?? 'French'));
$timezone = trim((string)($user['timezone'] ?? 'Europe/Paris'));
$gender = trim((string)($user['genre'] ?? ''));
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

$currentIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
$currentUserAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown browser'));
if ($currentUserAgent !== '' && strlen($currentUserAgent) > 120) {
    $currentUserAgent = substr($currentUserAgent, 0, 117) . '...';
}
$currentSessionStarted = date('d/m/Y H:i');

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Paramètres - GNL Solution</title>
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
        <div class="settings-shell">
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
                    <h2 class="text-base">Profile Picture</h2>
                    <p class="text-muted-foreground text-sm">Update your profile picture and personal information</p>
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
                            <img src="<?= e($avatar) ?>" alt="Avatar" data-avatar-image>
                          <?php else: ?>
                            <span data-avatar-fallback><?= e($initials) ?></span>
                          <?php endif; ?>
                        </div>
                        <span class="avatar-edit-badge" aria-hidden="true">
                          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"></path>
                          </svg>
                        </span>
                      </div>

                      <div>
                        <h3 class="font-semibold">Select and Upload Image</h3>
                        <p class="muted-copy">.svg, .png, .jpg (size 400x400px)</p>
                      </div>
                    </div>

                    <div class="status-toggle">
                      <button type="button" class="status-switch<?= $availability ? ' is-on' : '' ?>" role="switch" aria-checked="<?= $availability ? 'true' : 'false' ?>" data-availability-switch></button>
                      <span class="status-text">
                        <span class="status-dot"></span>
                        <span data-availability-text><?= $availability ? 'Online' : 'Offline' ?></span>
                      </span>
                    </div>
                  </div>

                  <div class="flex flex-wrap items-center gap-3">
                    <input type="file" id="avatar-upload" accept="image/png,image/jpeg,image/svg+xml" class="visually-hidden" data-avatar-input>
                    <button type="button" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2" data-avatar-trigger>
                      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" x2="12" y1="3" y2="15"></line>
                      </svg>
                      Upload Avatar
                    </button>
                  </div>

                  <div class="settings-tip">
                    <div class="flex items-start gap-3">
                      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 text-blue-500">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="m9 12 2 2 4-4"></path>
                      </svg>
                      <div>
                        <h4 class="mb-1 text-sm font-medium">Profile Picture Tips</h4>
                        <p>Choose a high-quality, professional image that clearly shows your face. Recommended image size is 400x400 pixels. Only .svg, .png, and .jpg formats are supported.</p>
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
                aria-expanded="false"
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
                    <h2 class="text-base">Personal Information</h2>
                    <p class="text-muted-foreground text-sm">Manage your personal details and profile information. This information will be visible to other users on the platform.</p>
                  </span>
                </span>
                <span class="settings-section__chevron" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-right h-5 w-5">
                    <path d="m9 18 6-6-6-6"></path>
                  </svg>
                </span>
              </button>

              <div id="settings-basic-info" data-slot="collapsible-content" class="settings-section__content" hidden>
                <form class="settings-grid" action="#" method="post" novalidate>
                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title">Basic Details</h3>
                    <div class=" settings-form-grid settings-form-grid--4">
                        <div class="settings-field">
                        <label for="gender">Civilité</label>
                        <select id="gender" name="gender" class="settings-select">
                          <option value=""<?= $gender === '' ? ' selected' : '' ?>>Select</option>
                          <option value="female"<?= strtolower($gender) === 'female' ? ' selected' : '' ?>>Madame</option>
                          <option value="male"<?= strtolower($gender) === 'male' ? ' selected' : '' ?>>Monsieur</option>
                          <option value="other"<?= strtolower($gender) === 'other' ? ' selected' : '' ?>>Docteur</option>
                          <option value="other"<?= strtolower($gender) === 'other' ? ' selected' : '' ?>>Professeur</option>
                          <option value="other"<?= strtolower($gender) === 'other' ? ' selected' : '' ?>>Other</option>
                        </select>
                      </div>
                      <div class="settings-field">
                        <label for="firstName">First Name</label>
                        <input id="firstName" name="first_name" class="settings-input" type="text" value="<?= e($firstName) ?>" placeholder="Emma">
                      </div>
                      <div class="settings-field">
                        <label for="lastName">Last Name</label>
                        <input id="lastName" name="last_name" class="settings-input" type="text" value="<?= e($lastName) ?>" placeholder="Roberts">
                      </div>
                    </div>
                  </div>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title">Professional Information</h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="birthDate">Birth Date</label>
                        <input id="birthDate" name="birth_date" class="settings-input" type="date" value="<?= e($birthDate) ?>">
                      </div>
                      <div class="settings-field">
                        <label for="profession">Profession</label>
                        <input id="profession" name="profession" class="settings-input" type="text" value="<?= e($profession) ?>" placeholder="Product Designer">
                      </div>
                      <div class="settings-field">
                        <label for="education">Education</label>
                        <input id="education" name="education" class="settings-input" type="text" value="<?= e($education) ?>" placeholder="Bachelor's degree">
                      </div>
                    </div>
                  </div>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title">Contact Information</h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="email">Email Address</label>
                        <input id="email" name="email" class="settings-input" type="email" value="<?= e($email) ?>" placeholder="emma@mail.com">
                      </div>
                      <div class="settings-field">
                        <label for="confirmEmail">Confirm Email</label>
                        <input id="confirmEmail" name="confirm_email" class="settings-input" type="email" value="<?= e($email) ?>" placeholder="emma@mail.com">
                      </div>
                      <div class="settings-field">
                        <label for="phone">Phone Number</label>
                        <input id="phone" name="phone" class="settings-input" type="tel" value="<?= e($phone) ?>" placeholder="+33 6 12 34 56 78">
                      </div>
                      <div class="settings-field">
                        <label for="location">Location</label>
                        <input id="location" name="location" class="settings-input" type="text" value="<?= e($location) ?>" placeholder="Paris, France">
                      </div>
                    </div>
                  </div>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title">Additional Information</h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="language">Preferred Language</label>
                        <select id="language" name="language" class="settings-select">
                          <?php foreach (['French', 'English', 'Spanish', 'German'] as $lang): ?>
                            <option value="<?= e($lang) ?>"<?= strtolower($language) === strtolower($lang) ? ' selected' : '' ?>><?= e($lang) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="settings-field">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone" class="settings-select">
                          <?php foreach (['Europe/Paris', 'Europe/Brussels', 'UTC', 'America/New_York'] as $tz): ?>
                            <option value="<?= e($tz) ?>"<?= $timezone === $tz ? ' selected' : '' ?>><?= e($tz) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                  </div>

                  <div class="settings-actions">
                    <button type="reset" class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-medium">Cancel</button>
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">Save Changes</button>
                  </div>
                </form>
              </div>
            </section>

            <section data-slot="collapsible" class="settings-section">
              <button
                type="button"
                data-slot="collapsible-trigger"
                class="settings-section__trigger"
                aria-expanded="false"
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
                    <h2 class="text-base">Change Password</h2>
                    <p class="text-muted-foreground text-sm">Update your password to keep your account secure</p>
                  </span>
                </span>
                <span class="settings-section__chevron" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-right h-5 w-5">
                    <path d="m9 18 6-6-6-6"></path>
                  </svg>
                </span>
              </button>

              <div id="settings-password" data-slot="collapsible-content" class="settings-section__content" hidden>
                <div class="password-layout">
                  <form class="settings-grid" action="#" method="post" novalidate>
                    <div class="settings-field">
                      <label for="currentPassword">Current Password</label>
                      <div class="settings-input-wrap">
                        <input id="currentPassword" name="current_password" class="settings-input" type="password" autocomplete="current-password">
                        <button type="button" class="settings-inline-button" data-password-toggle="currentPassword">Show</button>
                      </div>
                    </div>

                    <div class="settings-field">
                      <label for="newPassword">New Password</label>
                      <div class="settings-input-wrap">
                        <input id="newPassword" name="new_password" class="settings-input" type="password" autocomplete="new-password" data-password-source>
                        <button type="button" class="settings-inline-button" data-password-toggle="newPassword">Show</button>
                      </div>
                    </div>

                    <div class="settings-field">
                      <label for="confirmNewPassword">Confirm New Password</label>
                      <div class="settings-input-wrap">
                        <input id="confirmNewPassword" name="confirm_new_password" class="settings-input" type="password" autocomplete="new-password">
                        <button type="button" class="settings-inline-button" data-password-toggle="confirmNewPassword">Show</button>
                      </div>
                    </div>

                    <div class="settings-actions">
                      <button type="reset" class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-medium">Cancel</button>
                      <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">Update Password</button>
                    </div>
                  </form>

                  <div class="settings-grid">
                    <div>
                      <h3 class="settings-subsection__title">Password Requirements</h3>
                      <p class="muted-copy mb-4">Your password must meet the following criteria for enhanced security:</p>
                      <ul class="password-rules">
                        <li data-password-rule="length"><span class="password-rule-icon">×</span><span>At least 8 characters long</span></li>
                        <li data-password-rule="uppercase"><span class="password-rule-icon">×</span><span>One uppercase letter (A-Z)</span></li>
                        <li data-password-rule="lowercase"><span class="password-rule-icon">×</span><span>One lowercase letter (a-z)</span></li>
                        <li data-password-rule="number"><span class="password-rule-icon">×</span><span>One number (0-9)</span></li>
                        <li data-password-rule="special"><span class="password-rule-icon">×</span><span>One special character (!@#$%^&amp;*)</span></li>
                      </ul>
                    </div>

                    <div class="settings-tip">
                      <h4 class="mb-2 text-sm font-medium">Security Best Practices</h4>
                      <ul>
                        <li>Change your password regularly (every 90 days).</li>
                        <li>Never share your password with anyone.</li>
                        <li>Use a unique password for each account.</li>
                        <li>Consider using a password manager.</li>
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
                    <h2 class="text-base">Active Sessions</h2>
                    <p class="text-muted-foreground text-sm">Manage and monitor devices that have access to your account</p>
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
                          <div class="session-chip">Current Session</div>
                          <h4 class="mt-3 text-base font-semibold">Laptop Session</h4>
                        </div>
                      </div>
                      <div class="session-card__meta">
                        <div><strong>Browser:</strong> <?= e($currentUserAgent) ?></div>
                        <div><strong>IP:</strong> <?= e($currentIp) ?></div>
                        <div><strong>Started:</strong> <?= e($currentSessionStarted) ?></div>
                      </div>
                    </article>

                    <article class="session-card" data-session-card>
                      <div class="session-card__head">
                        <div>
                          <div class="session-chip">Mobile</div>
                          <h4 class="mt-3 text-base font-semibold">Smartphone Session</h4>
                        </div>
                        <button type="button" class="inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium session-remove" data-remove-session>Remove</button>
                      </div>
                      <div class="session-card__meta">
                        <div><strong>Browser:</strong> Safari on iPhone</div>
                        <div><strong>Location:</strong> Paris, France</div>
                        <div><strong>Last active:</strong> 2 hours ago</div>
                      </div>
                    </article>

                    <article class="session-card" data-session-card>
                      <div class="session-card__head">
                        <div>
                          <div class="session-chip">Workstation</div>
                          <h4 class="mt-3 text-base font-semibold">Desktop Session</h4>
                        </div>
                        <button type="button" class="inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium session-remove" data-remove-session>Remove</button>
                      </div>
                      <div class="session-card__meta">
                        <div><strong>Browser:</strong> Chrome on Windows</div>
                        <div><strong>Location:</strong> Brussels, Belgium</div>
                        <div><strong>Last active:</strong> Yesterday at 18:42</div>
                      </div>
                    </article>
                  </div>

                  <div class="settings-tip">
                    <div class="flex items-start gap-3">
                      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 text-blue-500">
                        <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path>
                      </svg>
                      <div>
                        <h4 class="mb-1 text-sm font-medium">Security Tip</h4>
                        <p>If you notice any suspicious activity, immediately remove the session and change your account password. Enable two-factor authentication for additional security.</p>
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

        var avatarTrigger = document.querySelector('[data-avatar-trigger]');
        var avatarInput = document.querySelector('[data-avatar-input]');
        var avatarPreview = document.querySelector('[data-avatar-preview]');

        if (avatarTrigger && avatarInput && avatarPreview) {
          avatarTrigger.addEventListener('click', function () {
            avatarInput.click();
          });

          avatarInput.addEventListener('change', function () {
            var file = avatarInput.files && avatarInput.files[0];
            if (!file) return;

            if (!file.type.match(/^image\//)) return;

            var reader = new FileReader();
            reader.onload = function (event) {
              avatarPreview.innerHTML = '';
              var img = document.createElement('img');
              img.src = event.target.result;
              img.alt = 'Avatar preview';
              avatarPreview.appendChild(img);
            };
            reader.readAsDataURL(file);
          });
        }

        var availabilitySwitch = document.querySelector('[data-availability-switch]');
        var availabilityText = document.querySelector('[data-availability-text]');
        if (availabilitySwitch && availabilityText) {
          availabilitySwitch.addEventListener('click', function () {
            var isOn = availabilitySwitch.getAttribute('aria-checked') === 'true';
            availabilitySwitch.setAttribute('aria-checked', isOn ? 'false' : 'true');
            availabilitySwitch.classList.toggle('is-on', !isOn);
            availabilityText.textContent = isOn ? 'Offline' : 'Online';
          });
        }

        document.querySelectorAll('[data-password-toggle]').forEach(function (toggle) {
          toggle.addEventListener('click', function () {
            var inputId = toggle.getAttribute('data-password-toggle');
            var input = document.getElementById(inputId);
            if (!input) return;
            var isPassword = input.getAttribute('type') === 'password';
            input.setAttribute('type', isPassword ? 'text' : 'password');
            toggle.textContent = isPassword ? 'Hide' : 'Show';
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
