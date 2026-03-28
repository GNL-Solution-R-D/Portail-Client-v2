<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: /connexion");
    exit();
}

require_once '../config_loader.php';
require_once '../include/two_factor.php';
require_once '../include/webauthn.php';
require_once '../include/account_sessions.php';
require_once '../data/dolbar_api.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit();
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

if (empty($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

$user = $_SESSION['user'] ?? [];
$profileAlert = null;
$passwordAlert = null;
$twoFactorAlert = null;
$twoFactorRecoveryCodes = [];
$sessionsAlert = null;
$isProfileSectionOpen = false;
$isPasswordSectionOpen = false;
$isTwoFactorSectionOpen = false;
$isSessionsSectionOpen = false;
$sessionRecords = [];
$currentSessionHash = '';
$companyInfo = null;
$companyInfoError = null;
$companyInfoErrorCode = null;

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
        $errors[] = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Le nouveau mot de passe doit contenir au moins une majuscule.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Le nouveau mot de passe doit contenir au moins une minuscule.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Le nouveau mot de passe doit contenir au moins un chiffre.';
    }
    if (!preg_match('/[!@#$%^&*]/', $password)) {
        $errors[] = 'Le nouveau mot de passe doit contenir au moins un caractère spécial parmi !@#$%^&*.';
    }

    return $errors;
}

function settingsCompanyDisplayValue($value): string
{
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : '—';
}

function settingsCompanyBoolLabel($value, string $yes = 'Oui', string $no = 'Non'): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return ((int)$value) > 0 ? $yes : $no;
}

function settingsCompanyDateDisplay($value): string
{
    $timestamp = dolbarApiDateToTimestamp($value);
    if ($timestamp !== null) {
        return date('d/m/Y', $timestamp);
    }

    return '—';
}

function settingsCompanyExtractRows(array $payload): array
{
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }

    foreach (['data', 'items', 'results', 'thirdparties', 'societes', 'companies'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    return [];
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
            'k8s_namespace' => $user['k8s_namespace'] ?? null,
            'k8sNamespace' => $user['k8sNamespace'] ?? null,
            'namespace_k8s' => $user['namespace_k8s'] ?? null,
            'k8s_ns' => $user['k8s_ns'] ?? null,
            'namespace' => $user['namespace'] ?? null,
        ]);
    }
} catch (Throwable $exception) {
    error_log('Erreur chargement profil utilisateur: ' . $exception->getMessage());
}

try {
    $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $user);
    $login = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $user);
    $password = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $user);
    $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $user);
    $sessionToken = trim((string)($_SESSION['dolibarr_token'] ?? ''));

    if ($apiUrl !== null) {
        $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);
        $query = ['sortfield' => 't.rowid', 'sortorder' => 'DESC', 'limit' => 200];

        if ($sessionToken !== '') {
            $rawCompanies = dolbarApiCallWithToken($apiUrl, '/thirdparties', $sessionToken, 'GET', $query, [], 12);
        } elseif ($login !== null && $password !== null) {
            $token = dolbarApiLoginToken($apiUrl, $login, $password, 8);
            $rawCompanies = dolbarApiCallWithToken($apiUrl, '/thirdparties', $token, 'GET', $query, [], 12);
        } elseif ($apiKey !== null) {
            $rawCompanies = dolbarApiCall($apiUrl, '/thirdparties', $apiKey, 'GET', $query, [], 12);
        } else {
            throw new RuntimeException(
                'Configuration Dolibarr incomplète (renseigner login/mot de passe ou clé API).',
                0
            );
        }

        $rows = settingsCompanyExtractRows($rawCompanies);
        $rows = array_values(array_filter($rows, static fn($row): bool => is_array($row)));

        if (!empty($rows)) {
            $companyInfo = $rows[0];
        }
    }
} catch (Throwable $exception) {
    $companyInfoError = $exception->getMessage();
    $companyInfoErrorCode = dolbarApiExtractErrorCode($exception) ?? 'DLB';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['settings_action'] ?? '') === 'update_personal_information') {
    $isProfileSectionOpen = true;

    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['settings_csrf_token'] ?? '');

    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $profileAlert = [
            'type' => 'error',
            'message' => 'La session de sécurité a expiré. Recharge la page et réessaie.',
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
                'message' => 'La civilité sélectionnée est invalide.',
            ];
        } elseif (!in_array($submittedLanguage, $allowedLanguages, true)) {
            $profileAlert = [
                'type' => 'error',
                'message' => 'La langue sélectionnée est invalide.',
            ];
        } elseif (!in_array($submittedTimezone, $allowedTimezones, true)) {
            $profileAlert = [
                'type' => 'error',
                'message' => 'Le fuseau horaire sélectionné est invalide.',
            ];
        } elseif (strlen($submittedFirstName) > 100 || strlen($submittedLastName) > 100) {
            $profileAlert = [
                'type' => 'error',
                'message' => 'Le prénom et le nom doivent contenir au maximum 100 caractères.',
            ];
        } else {
            try {
                $account = resolveUserAccount($pdo, $user, 'id');

                if (!$account || empty($account['id'])) {
                    $profileAlert = [
                        'type' => 'error',
                        'message' => 'Impossible de retrouver ton compte pour mettre à jour tes informations.',
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
                        'message' => 'Les informations personnelles ont bien été mises à jour.',
                    ];
                }
            } catch (Throwable $exception) {
                error_log('Erreur mise à jour informations personnelles: ' . $exception->getMessage());
                $profileAlert = [
                    'type' => 'error',
                    'message' => 'Une erreur est survenue pendant la mise à jour des informations personnelles.',
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
            'message' => 'La session de sécurité a expiré. Recharge la page et réessaie.',
        ];
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmNewPassword = (string)($_POST['confirm_new_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmNewPassword === '') {
            $passwordAlert = [
                'type' => 'error',
                'message' => 'Tous les champs du mot de passe sont obligatoires.',
            ];
        } elseif (!hash_equals($newPassword, $confirmNewPassword)) {
            $passwordAlert = [
                'type' => 'error',
                'message' => 'La confirmation du nouveau mot de passe ne correspond pas.',
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
                            'message' => 'Impossible de retrouver ton compte pour mettre à jour le mot de passe.',
                        ];
                    } elseif (!password_verify($currentPassword, (string)$account['password'])) {
                        $passwordAlert = [
                            'type' => 'error',
                            'message' => 'Le mot de passe actuel est incorrect.',
                        ];
                    } elseif (password_verify($newPassword, (string)$account['password'])) {
                        $passwordAlert = [
                            'type' => 'error',
                            'message' => "Le nouveau mot de passe doit être différent de l'actuel.",
                        ];
                    } else {
                        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $updateStmt = $pdo->prepare('UPDATE users SET password = ?, login_attempts = 0 WHERE id = ?');
                        $updateStmt->execute([$newPasswordHash, (int)$account['id']]);

                        session_regenerate_id(true);
                        $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));

                        $passwordAlert = [
                            'type' => 'success',
                            'message' => 'Le mot de passe a bien été mis à jour.',
                        ];
                    }
                } catch (Throwable $exception) {
                    error_log('Erreur changement mot de passe: ' . $exception->getMessage());
                    $passwordAlert = [
                        'type' => 'error',
                        'message' => 'Une erreur est survenue pendant la mise à jour du mot de passe.',
                    ];
                }
            }
        }
    }
}


$twoFactorAccount = resolveUserAccount($pdo, $user, 'id');
$twoFactorUserId = (int)($twoFactorAccount['id'] ?? ($user['id'] ?? 0));
$twoFactorPendingSecrets = isset($_SESSION['two_factor_pending_secret']) && is_array($_SESSION['two_factor_pending_secret'])
    ? $_SESSION['two_factor_pending_secret']
    : [];
$twoFactorPendingSecret = $twoFactorUserId > 0 ? (string)($twoFactorPendingSecrets[$twoFactorUserId] ?? '') : '';
$twoFactorConfig = $twoFactorUserId > 0 ? twoFactorGetConfig($pdo, $twoFactorUserId) : [
    'totp_secret' => null,
    'is_enabled' => 0,
    'phone_number' => null,
    'recovery_codes' => null,
    'preferred_method' => 'totp',
];
$webauthnAvailable = $twoFactorUserId > 0 && webauthnIsConfigured();
$webauthnCredentials = $webauthnAvailable ? webauthnGetCredentials($pdo, $twoFactorUserId) : [];
$webauthnRegistrationOptions = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['settings_action'] ?? ''), [
    'save_two_factor_phone',
    'enable_two_factor_totp',
    'disable_two_factor',
    'regenerate_two_factor_secret',
    'regenerate_recovery_codes',
    'register_webauthn_key',
    'delete_webauthn_credential',
], true)) {
    $isTwoFactorSectionOpen = true;
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['settings_csrf_token'] ?? '');
    $action = (string)($_POST['settings_action'] ?? '');

    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $twoFactorAlert = [
            'type' => 'error',
            'message' => 'La session de sécurité a expiré. Recharge la page et réessaie.',
        ];
    } elseif ($twoFactorUserId <= 0) {
        $twoFactorAlert = [
            'type' => 'error',
            'message' => 'Impossible de retrouver ton compte pour gérer la double authentification.',
        ];
    } else {
        try {
            if ($action === 'save_two_factor_phone') {
                $phoneInput = trim((string)($_POST['two_factor_phone'] ?? ''));
                if ($phoneInput !== '' && strlen($phoneInput) > 32) {
                    $twoFactorAlert = [
                        'type' => 'error',
                        'message' => 'Le numéro de téléphone est trop long.',
                    ];
                } else {
                    twoFactorUpsertConfig($pdo, $twoFactorUserId, [
                        'phone_number' => $phoneInput !== '' ? $phoneInput : null,
                    ]);
                    $twoFactorAlert = [
                        'type' => 'success',
                        'message' => 'Le numéro de secours 2FA a bien été enregistré.',
                    ];
                }
            }

            if ($action === 'regenerate_two_factor_secret') {
                $twoFactorPendingSecrets[$twoFactorUserId] = twoFactorGenerateSecret();
                $_SESSION['two_factor_pending_secret'] = $twoFactorPendingSecrets;
                $twoFactorPendingSecret = $twoFactorPendingSecrets[$twoFactorUserId];
                $twoFactorAlert = [
                    'type' => 'success',
                    'message' => 'Un nouveau secret TOTP a été généré. Scanne-le puis confirme avec un code à 6 chiffres.',
                ];
            }

            if ($action === 'enable_two_factor_totp') {
                $verificationCode = trim((string)($_POST['totp_verification_code'] ?? ''));
                if ($twoFactorPendingSecret === '') {
                    $twoFactorPendingSecret = twoFactorGenerateSecret();
                    $twoFactorPendingSecrets[$twoFactorUserId] = $twoFactorPendingSecret;
                    $_SESSION['two_factor_pending_secret'] = $twoFactorPendingSecrets;
                }

                if ($verificationCode === '') {
                    $twoFactorAlert = [
                        'type' => 'error',
                        'message' => 'Saisis le code de ton application pour confirmer l’activation.',
                    ];
                } elseif (!twoFactorVerifyTotpCode($twoFactorPendingSecret, $verificationCode)) {
                    $twoFactorAlert = [
                        'type' => 'error',
                        'message' => 'Le code de vérification est invalide ou expiré.',
                    ];
                } else {
                    $twoFactorRecoveryCodes = twoFactorGenerateRecoveryCodes();
                    twoFactorUpsertConfig($pdo, $twoFactorUserId, [
                        'totp_secret' => $twoFactorPendingSecret,
                        'is_enabled' => 1,
                        'preferred_method' => 'totp',
                        'recovery_codes' => twoFactorHashRecoveryCodes($twoFactorRecoveryCodes),
                    ]);
                    unset($twoFactorPendingSecrets[$twoFactorUserId]);
                    $_SESSION['two_factor_pending_secret'] = $twoFactorPendingSecrets;
                    $twoFactorPendingSecret = '';
                    $twoFactorAlert = [
                        'type' => 'success',
                        'message' => 'La double authentification est maintenant active. Conserve tes codes de secours.',
                    ];
                }
            }

            if ($action === 'disable_two_factor') {
                twoFactorUpsertConfig($pdo, $twoFactorUserId, [
                    'totp_secret' => null,
                    'is_enabled' => 0,
                    'preferred_method' => 'totp',
                    'recovery_codes' => null,
                ]);
                foreach (webauthnGetCredentials($pdo, $twoFactorUserId, false) as $credential) {
                    if (!empty($credential['id'])) {
                        webauthnDeleteCredential($pdo, $twoFactorUserId, (int) $credential['id']);
                    }
                }
                unset($twoFactorPendingSecrets[$twoFactorUserId]);
                $_SESSION['two_factor_pending_secret'] = $twoFactorPendingSecrets;
                $twoFactorPendingSecret = '';
                $twoFactorAlert = [
                    'type' => 'success',
                    'message' => 'La double authentification a été désactivée pour ce compte.',
                ];
            }

            if ($action === 'regenerate_recovery_codes') {
                $freshConfig = twoFactorGetConfig($pdo, $twoFactorUserId);
                if (empty($freshConfig['is_enabled']) || empty($freshConfig['totp_secret'])) {
                    $twoFactorAlert = [
                        'type' => 'error',
                        'message' => 'Active d’abord l’authenticator app avant de régénérer des codes de secours.',
                    ];
                } else {
                    $twoFactorRecoveryCodes = twoFactorGenerateRecoveryCodes();
                    twoFactorUpsertConfig($pdo, $twoFactorUserId, [
                        'recovery_codes' => twoFactorHashRecoveryCodes($twoFactorRecoveryCodes),
                    ]);
                    $twoFactorAlert = [
                        'type' => 'success',
                        'message' => 'De nouveaux codes de secours ont été générés. Les anciens ne sont plus valables.',
                    ];
                }
            }

            if ($action === 'register_webauthn_key') {
                if (!$webauthnAvailable) {
                    $twoFactorAlert = [
                        'type' => 'error',
                        'message' => 'La configuration WebAuthn est incomplète sur ce portail.',
                    ];
                } else {
                    $registrationPayload = trim((string)($_POST['webauthn_registration_response'] ?? ''));
                    $credentialLabel = trim((string)($_POST['webauthn_label'] ?? ''));
                    if ($registrationPayload === '') {
                        $twoFactorAlert = [
                            'type' => 'error',
                            'message' => 'Aucune réponse de clé de sécurité n’a été reçue.',
                        ];
                    } else {
                        $result = webauthnFinishRegistration($pdo, $twoFactorUserId, $registrationPayload, $credentialLabel);
                        if (empty($twoFactorConfig['recovery_codes'])) {
                            $twoFactorRecoveryCodes = twoFactorGenerateRecoveryCodes();
                        }

                        twoFactorUpsertConfig($pdo, $twoFactorUserId, [
                            'is_enabled' => 1,
                            'preferred_method' => 'webauthn',
                            'recovery_codes' => $twoFactorRecoveryCodes !== [] ? twoFactorHashRecoveryCodes($twoFactorRecoveryCodes) : ($twoFactorConfig['recovery_codes'] ?? null),
                        ]);

                        $twoFactorAlert = [
                            'type' => 'success',
                            'message' => sprintf(
                                'La clé de sécurité "%s" a bien été enregistrée.',
                                $credentialLabel !== '' ? $credentialLabel : ('Clé ' . substr((string)$result['credential_id'], 0, 8))
                            ),
                        ];
                    }
                }
            }

            if ($action === 'delete_webauthn_credential') {
                $credentialRowId = (int)($_POST['webauthn_credential_id'] ?? 0);
                if ($credentialRowId <= 0) {
                    $twoFactorAlert = [
                        'type' => 'error',
                        'message' => 'La clé de sécurité ciblée est invalide.',
                    ];
                } else {
                    webauthnDeleteCredential($pdo, $twoFactorUserId, $credentialRowId);
                    if (!twoFactorHasEnabledTotp($twoFactorConfig) && webauthnCountActiveCredentials($pdo, $twoFactorUserId) === 0) {
                        twoFactorUpsertConfig($pdo, $twoFactorUserId, [
                            'is_enabled' => 0,
                            'preferred_method' => 'totp',
                        ]);
                    }
                    $twoFactorAlert = [
                        'type' => 'success',
                        'message' => 'La clé de sécurité a été supprimée.',
                    ];
                }
            }

            $twoFactorConfig = twoFactorGetConfig($pdo, $twoFactorUserId);
            $webauthnCredentials = $webauthnAvailable ? webauthnGetCredentials($pdo, $twoFactorUserId) : [];
            $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            error_log('Erreur gestion 2FA: ' . $exception->getMessage());
            $twoFactorAlert = [
                'type' => 'error',
                'message' => 'Une erreur est survenue pendant la mise à jour de la double authentification.',
            ];
        }
        }
    }

if ($webauthnAvailable && $twoFactorUserId > 0) {
    try {
        $webauthnRegistrationOptions = webauthnCreateRegistrationOptions($pdo, $twoFactorUserId, $user);
        $webauthnCredentials = webauthnGetCredentials($pdo, $twoFactorUserId);
    } catch (Throwable $exception) {
        error_log('Erreur préparation WebAuthn: ' . $exception->getMessage());
        if ($twoFactorAlert === null) {
            $twoFactorAlert = [
                'type' => 'error',
                'message' => 'Impossible de préparer la configuration des clés de sécurité pour le moment.',
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['settings_action'] ?? ''), ['revoke_session', 'revoke_other_sessions'], true)) {
    $isSessionsSectionOpen = true;

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['settings_csrf_token'] ?? '');
    $sessionAction = (string) ($_POST['settings_action'] ?? '');
    $sessionUserId = (int) ($user['id'] ?? 0);

    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $sessionsAlert = [
            'type' => 'error',
            'message' => 'La session de sécurité a expiré. Recharge la page et réessaie.',
        ];
    } elseif ($sessionUserId <= 0) {
        $sessionsAlert = [
            'type' => 'error',
            'message' => 'Impossible d’identifier le compte utilisateur pour gérer les sessions actives.',
        ];
    } else {
        try {
            if ($sessionAction === 'revoke_session') {
                $sessionRecordId = (int) ($_POST['session_record_id'] ?? 0);
                $currentSessionHash = accountSessionsHashSessionId(accountSessionsCurrentSessionId());
                $currentSessionRecordId = 0;
                foreach (accountSessionsListForUser($pdo, $sessionUserId) as $sessionRow) {
                    if (($sessionRow['session_id_hash'] ?? '') === $currentSessionHash) {
                        $currentSessionRecordId = (int) ($sessionRow['id'] ?? 0);
                        break;
                    }
                }

                if ($sessionRecordId <= 0) {
                    $sessionsAlert = [
                        'type' => 'error',
                        'message' => 'Session invalide.',
                    ];
                } elseif ($currentSessionRecordId > 0 && $sessionRecordId === $currentSessionRecordId) {
                    $sessionsAlert = [
                        'type' => 'error',
                        'message' => 'Tu ne peux pas supprimer la session en cours depuis cette page. Utilise le bouton de déconnexion si nécessaire.',
                    ];
                } elseif (accountSessionsRevokeById($pdo, $sessionUserId, $sessionRecordId)) {
                    $sessionsAlert = [
                        'type' => 'success',
                        'message' => 'La session sélectionnée a été déconnectée.',
                    ];
                } else {
                    $sessionsAlert = [
                        'type' => 'error',
                        'message' => 'Impossible de supprimer cette session. Elle a peut-être déjà été fermée.',
                    ];
                }
            } else {
                $revokedSessions = accountSessionsRevokeOtherSessions($pdo, $sessionUserId);
                $sessionsAlert = [
                    'type' => 'success',
                    'message' => $revokedSessions > 0
                        ? sprintf('%d session(s) ont été déconnectée(s).', $revokedSessions)
                        : 'Aucune autre session active à déconnecter.',
                ];
            }

            $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
            accountSessionsTouchCurrent($pdo, $sessionUserId);
        } catch (Throwable $exception) {
            error_log('Erreur gestion sessions actives: ' . $exception->getMessage());
            $sessionsAlert = [
                'type' => 'error',
                'message' => 'Une erreur est survenue pendant la gestion des sessions actives.',
            ];
        }
    }
}

$civilityOptions = [
    '' => 'Sélectionner',
    'Madame' => 'Madame',
    'Monsieur' => 'Monsieur',
    'Docteur' => 'Docteur',
    'Professeur' => 'Professeur',
    'Autre' => 'Autre',
];
$languageOptions = [
    'fr' => 'Français',
    'en' => 'English',
    'es' => 'Español',
    'de' => 'Deutsch',
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
$phone = trim((string)(($twoFactorConfig['phone_number'] ?? '') ?: ($user['telephone'] ?? ($user['phone'] ?? ''))));
$location = trim((string)($user['ville'] ?? ($user['location'] ?? '')));
$profession = trim((string)($user['fonction'] ?? ($user['profession'] ?? '')));
$education = trim((string)($user['education'] ?? ''));
$language = normalizeLanguageCode((string)($user['langue_code'] ?? ($user['langue'] ?? 'fr')));
$timezone = trim((string)($user['timezone'] ?? 'Europe/Paris'));
$gender = normalizeCivilite((string)($user['civilite'] ?? ($user['genre'] ?? '')));
$birthDate = trim((string)($user['date_naissance'] ?? ($user['birth_date'] ?? '')));

$sessionUserId = (int) ($user['id'] ?? 0);
if ($sessionUserId > 0) {
    $currentSessionHash = accountSessionsHashSessionId(accountSessionsCurrentSessionId());
    $sessionRecords = accountSessionsListForUser($pdo, $sessionUserId);
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
      min-height: calc(100vh - var(--app-header-height, 0px));
      min-height: calc(100dvh - var(--app-header-height, 0px));
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



    .two-factor-summary {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.85rem;
      margin-bottom: 1.25rem;
      padding-bottom: 1.25rem;
      border-bottom: 1px solid var(--border);
    }

    .two-factor-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      border: 1px solid transparent;
      border-radius: 9999px;
      padding: 0.42rem 0.75rem;
      font-size: 0.78rem;
      font-weight: 700;
      line-height: 1;
      white-space: nowrap;
    }

    .two-factor-badge--success {
      border-color: color-mix(in oklab, rgb(34 197 94) 55%, var(--border) 45%);
      background: color-mix(in oklab, rgb(34 197 94) 14%, var(--background) 86%);
      color: rgb(21 128 61);
    }

    .two-factor-badge--muted {
      border-color: var(--border);
      background: color-mix(in oklab, var(--muted) 55%, transparent);
      color: var(--muted-foreground);
    }

    .two-factor-methods {
      display: grid;
      overflow: hidden;
      box-shadow: 0 6px 24px rgba(15, 23, 42, 0.04);
    }

    .two-factor-method {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 1.35rem 1.5rem;
      border-bottom: 1px solid var(--border);
    }

    .two-factor-method:last-child {
      border-bottom: 0;
    }

    .two-factor-method__body {
      display: flex;
      align-items: flex-start;
      gap: 1rem;
      min-width: 0;
      flex: 1 1 24rem;
    }

    .two-factor-method__icon,
    .two-factor-note__icon {
      width: 3rem;
      height: 3rem;
      border-radius: 1rem;
      border: 1px solid var(--border);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
      background: color-mix(in oklab, var(--muted) 60%, transparent);
      color: var(--foreground);
    }

    .two-factor-method__icon.is-active {
      background: color-mix(in oklab, var(--primary) 12%, var(--background) 88%);
      color: var(--primary);
    }

    .two-factor-method__title-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.55rem;
      margin-bottom: 0.35rem;
    }

    .two-factor-method__title {
      margin: 0;
      font-size: 0.98rem;
      font-weight: 700;
    }

    .two-factor-method__description,
    .two-factor-method__status,
    .two-factor-note__text {
      margin: 0;
      color: var(--muted-foreground);
      font-size: 0.92rem;
      line-height: 1.6;
    }

    .two-factor-method__status {
      margin-top: 0.35rem;
    }

    .two-factor-method__status.is-active {
      color: var(--foreground);
      font-weight: 600;
    }

    .two-factor-chip {
      display: inline-flex;
      align-items: center;
      border-radius: 9999px;
      border: 1px solid color-mix(in oklab, var(--primary) 36%, var(--border) 64%);
      background: color-mix(in oklab, var(--primary) 12%, var(--background) 88%);
      color: var(--primary);
      padding: 0.24rem 0.55rem;
      font-size: 0.72rem;
      font-weight: 700;
      line-height: 1;
      white-space: nowrap;
    }

    .two-factor-note {
      display: flex;
      align-items: flex-start;
      gap: 0.9rem;
      margin-top: 1.5rem;
      padding: 1rem 1.05rem;
      border-radius: 1rem;
      border: 1px solid var(--border);
      background: color-mix(in oklab, var(--muted) 38%, transparent);
    }

    .two-factor-note__content {
      flex: 1 1 auto;
      min-width: 0;
    }

    .two-factor-note__icon {
      width: 2.6rem;
      height: 2.6rem;
      border-radius: 0.9rem;
      color: rgb(59 130 246);
      background: color-mix(in oklab, rgb(59 130 246) 10%, var(--background) 90%);
    }

    .two-factor-qr {
      flex: 0 0 auto;
      display: grid;
      gap: 0.5rem;
      justify-items: center;
      width: 11.5rem;
      padding: 0.85rem;
      border-radius: 1rem;
      border: 1px solid color-mix(in oklab, var(--border) 72%, white 28%);
      background: var(--background);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5);
    }

    .two-factor-qr__canvas {
      width: 100%;
      aspect-ratio: 1;
      border-radius: 0.75rem;
      overflow: hidden;
      background: #fff;
      box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.06);
    }

    .two-factor-qr__canvas svg {
      display: block;
      width: 100%;
      height: 100%;
    }

    .two-factor-qr__caption {
      margin: 0;
      text-align: center;
      font-size: 0.8rem;
      line-height: 1.35;
      color: var(--muted-foreground);
    }

    .two-factor-qr__error {
      margin: 0;
      text-align: center;
      font-size: 0.8rem;
      line-height: 1.35;
      color: rgb(185 28 28);
    }

    @media (max-width: 900px) {
      .two-factor-note {
        flex-direction: column;
      }

      .two-factor-qr {
        width: min(100%, 14rem);
        margin-left: auto;
        margin-right: auto;
      }
    }

    .two-factor-link {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      margin-top: 0.65rem;
      border: 0;
      background: transparent;
      padding: 0;
      color: var(--primary);
      font-size: 0.9rem;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
    }

    .two-factor-link:hover {
      text-decoration: underline;
    }

    .two-factor-actions {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 0.75rem;
      margin-top: 1.5rem;
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
      <div class="app-shell-offset-min-height w-full bg-surface p-4 md:p-6 lg:p-8">
        <div class="w-full">
          <div class="settings-stack">
          <a class="text-muted-foreground hover:text-foreground" href="/dashboard">← Retour dashboard</a>

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
                    <h3 class="settings-subsection__title">Basic Details</h3>
                    <div class=" settings-form-grid settings-form-grid--4">
                      <div class="settings-field">
                        <label for="civilite">Civilité</label>
                        <select id="civilite" name="civilite" class="settings-select">
                          <?php foreach ($civilityOptions as $civiliteValue => $civiliteLabel): ?>
                            <option value="<?= e($civiliteValue) ?>"<?= $gender === $civiliteValue ? ' selected' : '' ?>><?= e($civiliteLabel) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="settings-field">
                        <label for="firstName">Prénom</label>
                        <input id="firstName" name="first_name" class="settings-input" type="text" value="<?= e($firstName) ?>" placeholder="Emma" maxlength="100">
                      </div>
                      <div class="settings-field">
                        <label for="lastName">Nom</label>
                        <input id="lastName" name="last_name" class="settings-input" type="text" value="<?= e($lastName) ?>" placeholder="Roberts" maxlength="100">
                      </div>
                    </div>
                  </div>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title">Professional Information - Soon...</h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="profession">Profession</label>
                        <input id="profession" name="profession" class="settings-input" type="text" value="<?= e($profession) ?>" placeholder="Product Designer" disabled>
                      </div>
                      <div class="settings-field">
                        <label for="education">Education</label>
                        <input id="education" name="education" class="settings-input" type="text" value="<?= e($education) ?>" placeholder="Bachelor's degree" disabled>
                      </div>
                    </div>
                  </div>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title">Contact Information - Soon...</h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="email">Email Address</label>
                        <input id="email" name="email" class="settings-input" type="email" value="<?= e($email) ?>" placeholder="emma@mail.com" disabled>
                      </div>
                      <div class="settings-field">
                        <label for="confirmEmail">Confirm Email</label>
                        <input id="confirmEmail" name="confirm_email" class="settings-input" type="email" value="<?= e($email) ?>" placeholder="emma@mail.com" disabled>
                      </div>
                      <div class="settings-field">
                        <label for="phone">Phone Number</label>
                        <input id="phone" name="phone" class="settings-input" type="tel" value="<?= e($phone) ?>" placeholder="+33 6 12 34 56 78" disabled>
                      </div>
                      <div class="settings-field">
                        <label for="location">Location</label>
                        <input id="location" name="location" class="settings-input" type="text" value="<?= e($location) ?>" placeholder="Paris, France" disabled>
                      </div>
                    </div>
                  </div>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title">Additional Information</h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="language">Preferred Language</label>
                        <select id="language" name="language" class="settings-select">
                          <?php foreach ($languageOptions as $languageCode => $languageLabel): ?>
                            <option value="<?= e($languageCode) ?>"<?= $language === $languageCode ? ' selected' : '' ?>><?= e($languageLabel) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="settings-field">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone" class="settings-select">
                          <?php foreach ($timezoneOptions as $tz): ?>
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
                aria-controls="settings-company-info"
              >
                <span class="settings-section__hero">
                  <span class="settings-section__icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                      <path d="M3 21h18"></path>
                      <path d="M5 21V7l8-4v18"></path>
                      <path d="M19 21V11l-6-4"></path>
                    </svg>
                  </span>
                  <span class="settings-section__copy">
                    <h2 class="text-base">Entreprise information</h2>
                    <p class="text-muted-foreground text-sm">Informations de votre fiche Entreprise synchronisées depuis Dolibarr.</p>
                  </span>
                </span>
                <span class="settings-section__chevron" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-right h-5 w-5">
                    <path d="m9 18 6-6-6-6"></path>
                  </svg>
                </span>
              </button>

              <div id="settings-company-info" data-slot="collapsible-content" class="settings-section__content" hidden>
                <?php
                  $companyName = $companyInfo['name'] ?? $companyInfo['nom'] ?? $companyInfo['socname'] ?? null;
                  $companyCodeClient = $companyInfo['code_client'] ?? $companyInfo['codeclient'] ?? null;
                  $companySiret = $companyInfo['siret'] ?? $companyInfo['idprof2'] ?? ($companyInfo['idprof']['2'] ?? null);
                  $companySiren = $companyInfo['siren'] ?? $companyInfo['idprof1'] ?? ($companyInfo['idprof']['1'] ?? null);
                  $companyTva = $companyInfo['tva_intra'] ?? null;
                  $companyEmail = $companyInfo['email'] ?? null;
                  $companyPhone = $companyInfo['phone'] ?? null;
                  $companyMobile = $companyInfo['phone_mobile'] ?? null;
                  $companyFax = $companyInfo['fax'] ?? null;
                  $companyWebsite = $companyInfo['url'] ?? null;
                  $companyEffectif = $companyInfo['effectif'] ?? null;
                  $companyTypent = $companyInfo['typent_code'] ?? $companyInfo['typent_label'] ?? null;
                  $companyCommercial = $companyInfo['commercial_id'] ?? null;
                  $companyIsSupplier = $companyInfo['fournisseur'] ?? null;
                  $companyCreatedAt = $companyInfo['date_creation'] ?? $companyInfo['datec'] ?? null;
                  $companyAddress = trim(implode(' ', array_filter([
                    $companyInfo['address'] ?? null,
                    $companyInfo['zip'] ?? null,
                    $companyInfo['town'] ?? null,
                    $companyInfo['country'] ?? null,
                  ], static fn($value): bool => trim((string)$value) !== '')));
                ?>

                <?php if ($companyInfoError !== null): ?>
                  <div class="settings-alert settings-alert--error" role="alert">
                    Impossible de charger les informations entreprise (code: <?= e($companyInfoErrorCode) ?>). <?= e($companyInfoError) ?>
                  </div>
                <?php elseif (!is_array($companyInfo)): ?>
                  <div class="settings-alert" role="status">
                    Aucune fiche entreprise trouvée pour votre compte.
                  </div>
                <?php else: ?>
                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title">Informations légales</h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="company_name">Raison sociale</label>
                        <input id="company_name" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyName)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_code_client">Code client</label>
                        <input id="company_code_client" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyCodeClient)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_siret">SIRET</label>
                        <input id="company_siret" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companySiret)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_siren">SIREN</label>
                        <input id="company_siren" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companySiren)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_tva">TVA intracom</label>
                        <input id="company_tva" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyTva)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_typent">Type d'entreprise</label>
                        <input id="company_typent" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyTypent)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_address">Adresse</label>
                        <input id="company_address" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyAddress)) ?>" readonly disabled>
                      </div>
                    </div>
                  </div>

                  <div class="settings-subsection">
                    <h3 class="settings-subsection__title">Contact & suivi</h3>
                    <div class="settings-two-cols">
                      <div class="settings-field">
                        <label for="company_phone">Téléphone</label>
                        <input id="company_phone" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyPhone)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_mobile">Mobile</label>
                        <input id="company_mobile" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyMobile)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_fax">Fax</label>
                        <input id="company_fax" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyFax)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_email">Email</label>
                        <input id="company_email" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyEmail)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_website">Site web</label>
                        <input id="company_website" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyWebsite)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_commercial">Commercial</label>
                        <input id="company_commercial" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyCommercial)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_effectif">Effectif</label>
                        <input id="company_effectif" class="settings-input" type="text" value="<?= e(settingsCompanyDisplayValue($companyEffectif)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_supplier">Fournisseur</label>
                        <input id="company_supplier" class="settings-input" type="text" value="<?= e(settingsCompanyBoolLabel($companyIsSupplier)) ?>" readonly disabled>
                      </div>
                      <div class="settings-field">
                        <label for="company_created_at">Créée le</label>
                        <input id="company_created_at" class="settings-input" type="text" value="<?= e(settingsCompanyDateDisplay($companyCreatedAt)) ?>" readonly disabled>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>
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
                      <label for="currentPassword">Current Password</label>
                      <div class="settings-input-wrap">
                        <input id="currentPassword" name="current_password" class="settings-input" type="password" autocomplete="current-password" required>
                        <button type="button" class="settings-inline-button" data-password-toggle="currentPassword">Show</button>
                      </div>
                    </div>

                    <div class="settings-field">
                      <label for="newPassword">New Password</label>
                      <div class="settings-input-wrap">
                        <input id="newPassword" name="new_password" class="settings-input" type="password" autocomplete="new-password" data-password-source required>
                        <button type="button" class="settings-inline-button" data-password-toggle="newPassword">Show</button>
                      </div>
                    </div>

                    <div class="settings-field">
                      <label for="confirmNewPassword">Confirm New Password</label>
                      <div class="settings-input-wrap">
                        <input id="confirmNewPassword" name="confirm_new_password" class="settings-input" type="password" autocomplete="new-password" required>
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
                aria-expanded="<?= $isTwoFactorSectionOpen ? 'true' : 'false' ?>"
                aria-controls="settings-two-factor"
              >
                <span class="settings-section__hero">
                  <span class="settings-section__icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                      <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path>
                    </svg>
                  </span>
                  <span class="settings-section__copy">
                    <h2 class="text-base">Two-Factor Authentication</h2>
                    <p class="text-muted-foreground text-sm">Add an extra layer of security to your account</p>
                  </span>
                  
                </span>
                <span class="settings-section__chevron" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-right h-5 w-5">
                    <path d="m9 18 6-6-6-6"></path>
                  </svg>
                </span>
              </button>

              <div id="settings-two-factor" data-slot="collapsible-content" class="bg-surface"<?= $isTwoFactorSectionOpen ? "" : " hidden" ?>>
                <?php
                  $hasSmsNumber = $phone !== '';
                  $totpEnabled = twoFactorHasEnabledTotp($twoFactorConfig);
                  $webAuthnCount = count($webauthnCredentials);
                  $hasWebauthnConfigured = $webAuthnCount > 0;
                  $twoFactorEnabled = $totpEnabled || $hasWebauthnConfigured;
                  $recoveryCodeCount = twoFactorCountRemainingRecoveryCodes($twoFactorConfig['recovery_codes'] ?? null);
                  if (!$totpEnabled && $twoFactorPendingSecret === '' && $twoFactorUserId > 0) {
                      $twoFactorPendingSecrets[$twoFactorUserId] = twoFactorGenerateSecret();
                      $_SESSION['two_factor_pending_secret'] = $twoFactorPendingSecrets;
                      $twoFactorPendingSecret = $twoFactorPendingSecrets[$twoFactorUserId];
                  }
                  $twoFactorIssuer = 'GNL Solution';
                  $twoFactorLabel = trim((string)($user['email'] ?? '')) !== ''
                      ? (string)$user['email']
                      : trim((string)($user['siret'] ?? '') . ':' . (string)($user['username'] ?? 'compte'));
                  $twoFactorProvisioningUri = $twoFactorPendingSecret !== ''
                      ? twoFactorProvisioningUri($twoFactorIssuer, $twoFactorLabel, $twoFactorPendingSecret)
                      : '';
                ?>

                <?php if ($twoFactorAlert): ?>
                  <div class="mb-6 rounded-2xl border px-4 py-3 text-sm <?= $twoFactorAlert['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700' ?>">
                    <?= e($twoFactorAlert['message']) ?>
                  </div>
                <?php endif; ?>

                <div class="two-factor-methods">
                  <div class="two-factor-method">
                    <div class="two-factor-method__body">
                      <span class="two-factor-method__icon <?= $hasWebauthnConfigured ? 'is-active' : '' ?>" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                          <path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"></path>
                          <circle cx="16.5" cy="7.5" r=".5" fill="currentColor"></circle>
                        </svg>
                      </span>
                      <div>
                        <div class="two-factor-method__title-row">
                          <p class="two-factor-method__title">Security Keys</p>
                          <span class="two-factor-chip"><?= $webauthnAvailable ? 'WebAuthn' : 'Config requise' ?></span>
                        </div>
                        <p class="two-factor-method__description">Enregistre une YubiKey, une Titan Key ou la sécurité matérielle intégrée à ton appareil pour valider la 2FA sans saisir de code.</p>
                        <p class="two-factor-method__status <?= $hasWebauthnConfigured ? 'is-active' : '' ?>">
                          <?php if (!$webauthnAvailable): ?>
                            Configuration WebAuthn absente côté portail
                          <?php elseif ($hasWebauthnConfigured): ?>
                            <?= $webAuthnCount ?> clé<?= $webAuthnCount > 1 ? 's' : '' ?> configurée<?= $webAuthnCount > 1 ? 's' : '' ?>
                          <?php else: ?>
                            Aucune clé matérielle configurée
                          <?php endif; ?>
                        </p>
                      </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                      <?php if ($webauthnAvailable): ?>
                        <input type="text" id="webauthn_label" name="webauthn_label" form="webauthn-registration-form" placeholder="Nom de la clé (optionnel)" class="border-input h-10 rounded-md border bg-transparent px-3 py-2 text-sm">
                        <button type="button" id="webauthn-register-button" class="inline-flex items-center justify-center whitespace-nowrap rounded-md border px-3 py-2 text-sm font-medium hover:bg-accent" data-options='<?= htmlspecialchars(json_encode($webauthnRegistrationOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'<?= $webauthnRegistrationOptions === null ? ' disabled' : '' ?>>Ajouter une clé</button>
                      <?php else: ?>
                        <button type="button" disabled class="inline-flex items-center justify-center whitespace-nowrap rounded-md border px-3 py-2 text-sm font-medium opacity-60">Indisponible</button>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="two-factor-method">
                    <div class="two-factor-method__body">
                      <span class="two-factor-method__icon <?= $totpEnabled ? 'is-active' : '' ?>" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                          <rect width="14" height="20" x="5" y="2" rx="2" ry="2"></rect>
                          <path d="M12 18h.01"></path>
                        </svg>
                      </span>
                      <div>
                        <div class="two-factor-method__title-row">
                          <p class="two-factor-method__title">Authenticator App</p>
                          <span class="two-factor-chip">Recommandé</span>
                        </div>
                        <p class="two-factor-method__description">Utilise Google Authenticator, 1Password, Authy ou toute application TOTP compatible RFC 6238.</p>
                        <p class="two-factor-method__status <?= $totpEnabled ? 'is-active' : '' ?>">
                          <?= $totpEnabled ? 'Application configurée et disponible après le mot de passe.' : 'En attente d’activation' ?>
                        </p>
                      </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                      <?php if ($twoFactorEnabled): ?>
                        <form method="POST" class="inline-flex">
                          <input type="hidden" name="csrf_token" value="<?= e((string)$_SESSION['settings_csrf_token']) ?>">
                          <input type="hidden" name="settings_action" value="disable_two_factor">
                          <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md border px-3 py-2 text-sm font-medium hover:bg-accent">Désactiver</button>
                        </form>
                      <?php else: ?>
                        <form method="POST" class="inline-flex">
                          <input type="hidden" name="csrf_token" value="<?= e((string)$_SESSION['settings_csrf_token']) ?>">
                          <input type="hidden" name="settings_action" value="regenerate_two_factor_secret">
                          <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md border px-3 py-2 text-sm font-medium hover:bg-accent">Nouveau secret</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="two-factor-method">
                    <div class="two-factor-method__body">
                      <span class="two-factor-method__icon <?= $hasSmsNumber ? 'is-active' : '' ?>" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                      </span>
                      <div>
                        <div class="two-factor-method__title-row">
                          <p class="two-factor-method__title">Numéro de secours</p>
                        </div>
                        <p class="two-factor-method__description">Conserve ici un numéro mobile de récupération. Il est prêt pour une future étape SMS, sans être utilisé comme facteur principal aujourd’hui.</p>
                        <p class="two-factor-method__status <?= $hasSmsNumber ? 'is-active' : '' ?>">
                          <?= $hasSmsNumber ? e(twoFactorMaskPhone($phone)) : 'Aucun numéro enregistré' ?>
                        </p>
                      </div>
                    </div>
                    <span class="text-xs text-muted-foreground">Prochainement...</span>
                  </div>
                </div>

                <?php if (!$totpEnabled): ?>
                  <div class="two-factor-note ml-8 mr-8">
                    <span class="two-factor-note__icon" aria-hidden="true">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" x2="12" y1="8" y2="12"></line>
                        <line x1="12" x2="12.01" y1="16" y2="16"></line>
                      </svg>
                    </span>
                    <div class="two-factor-note__content">
                      <h4 class="mb-2 text-sm font-medium">Associer une application TOTP</h4>
                      <p class="two-factor-note__text">Scanne le QR code avec Google Authenticator, 1Password, Authy ou une application compatible RFC 6238, puis saisis un code à 6 chiffres pour activer le fallback TOTP. URI de provisioning :</p>
                      <code class="mt-2 block overflow-x-auto rounded-lg bg-slate-950/95 px-3 py-3 text-xs text-slate-100"><?= e($twoFactorProvisioningUri) ?></code>
                      <p class="mt-3 text-sm"><strong>Secret manuel :</strong> <span class="font-mono"><?= e($twoFactorPendingSecret) ?></span></p>
                    </div>
                    <div class="two-factor-qr">
                      <div class="two-factor-qr__canvas" data-totp-qr data-qr-value="<?= e($twoFactorProvisioningUri) ?>"></div>
                      <p class="two-factor-qr__caption">Scanne ce QR code pour importer automatiquement le secret TOTP.</p>
                      <p class="two-factor-qr__error" data-qr-error hidden>Le QR code n’a pas pu être affiché. Utilise le secret manuel ci-dessus.</p>
                    </div>
                  </div>

                  <form method="POST" class="mt-6 grid gap-4 rounded-2xl p-4 sm:grid-cols-[1fr_auto] sm:items-end">
                    <input type="hidden" name="csrf_token" value="<?= e((string)$_SESSION['settings_csrf_token']) ?>">
                    <input type="hidden" name="settings_action" value="enable_two_factor_totp">
                    <div class="space-y-2">
                      <label for="totp_verification_code" class="text-sm font-medium">Code de confirmation TOTP</label>
                      <input id="totp_verification_code" name="totp_verification_code" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" class="border-input h-11 w-full rounded-md border bg-transparent px-3 py-2 text-sm" required>
                    </div>
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">Activer le TOTP</button>
                  </form>
                <?php endif; ?>

                <?php if ($twoFactorEnabled): ?>
                  <div class="two-factor-note ml-8 mr-8">
                    <span class="two-factor-note__icon" aria-hidden="true">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" x2="12" y1="8" y2="12"></line>
                        <line x1="12" x2="12.01" y1="16" y2="16"></line>
                      </svg>
                    </span>
                    <div>
                      <h4 class="mb-2 text-sm font-medium">Codes de secours</h4>
                      <p class="two-factor-note__text">Il te reste actuellement <?= $recoveryCodeCount ?> code<?= $recoveryCodeCount > 1 ? 's' : '' ?> de secours utilisable<?= $recoveryCodeCount > 1 ? 's' : '' ?>.</p>
                      <form method="POST" class="mt-3 inline-flex">
                        <input type="hidden" name="csrf_token" value="<?= e((string)$_SESSION['settings_csrf_token']) ?>">
                        <input type="hidden" name="settings_action" value="regenerate_recovery_codes">
                        <button type="submit" class="two-factor-link">Régénérer les codes de secours →</button>
                      </form>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($hasWebauthnConfigured): ?>
                  <div class="mt-6 rounded-2xl p-4">
                    <h4 class="text-sm font-semibold">Clés de sécurité enregistrées</h4>
                    <div class="mt-4 grid gap-3">
                      <?php foreach ($webauthnCredentials as $credential): ?>
                        <div class="flex flex-col gap-3 rounded-xl border border-border p-3 sm:flex-row sm:items-center sm:justify-between">
                          <div>
                            <p class="text-sm font-medium"><?= e(trim((string)($credential['label'] ?? '')) !== '' ? (string)$credential['label'] : ('Clé ' . substr((string)$credential['credential_id'], 0, 10))) ?></p>
                            <p class="text-xs text-muted-foreground">
                              Ajoutée le <?= e((string)($credential['created_at'] ?? '')) ?>
                              <?php if (!empty($credential['last_used_at'])): ?>
                                · Dernière utilisation <?= e((string)$credential['last_used_at']) ?>
                              <?php endif; ?>
                            </p>
                          </div>
                          <form method="POST" class="inline-flex">
                            <input type="hidden" name="csrf_token" value="<?= e((string)$_SESSION['settings_csrf_token']) ?>">
                            <input type="hidden" name="settings_action" value="delete_webauthn_credential">
                            <input type="hidden" name="webauthn_credential_id" value="<?= (int)($credential['id'] ?? 0) ?>">
                            <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md border px-3 py-2 text-sm font-medium hover:bg-accent">Supprimer</button>
                          </form>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($twoFactorRecoveryCodes !== []): ?>
                  <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <h4 class="text-sm font-semibold text-amber-900">Codes de secours à enregistrer maintenant</h4>
                    <p class="mt-2 text-sm text-amber-800">Ces codes ne seront plus réaffichés. Copie-les dans un gestionnaire de mots de passe ou imprime-les.</p>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                      <?php foreach ($twoFactorRecoveryCodes as $recoveryCode): ?>
                        <code class="rounded-lg bg-white px-3 py-2 text-center text-sm font-semibold text-slate-900 shadow-sm"><?= e($recoveryCode) ?></code>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <form method="POST" id="webauthn-registration-form" hidden>
                  <input type="hidden" name="csrf_token" value="<?= e((string)$_SESSION['settings_csrf_token']) ?>">
                  <input type="hidden" name="settings_action" value="register_webauthn_key">
                  <input type="hidden" name="webauthn_registration_response" id="webauthn_registration_response" value="">
                </form>
              </div>
            </section>


            <section data-slot="collapsible" class="settings-section">
              <button
                type="button"
                data-slot="collapsible-trigger"
                class="settings-section__trigger"
                aria-expanded="<?= $isSessionsSectionOpen ? 'true' : 'false' ?>"
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

              <div id="settings-sessions" data-slot="collapsible-content" class="settings-section__content"<?= $isSessionsSectionOpen ? '' : ' hidden' ?>>
                <div class="settings-grid">
                  <div>

                    <?php if ($sessionsAlert !== null): ?>
                      <div class="settings-alert settings-alert--<?= e($sessionsAlert['type'] ?? 'error') ?> mt-6" role="alert">
                        <?= e($sessionsAlert['message'] ?? '') ?>
                      </div>
                    <?php endif; ?>

                    <div class="session-grid mt-6">
                      <?php if ($sessionRecords !== []): ?>
                        <?php foreach ($sessionRecords as $sessionRecord): ?>
                          <?php $isCurrentSessionCard = ($sessionRecord['session_id_hash'] ?? '') === $currentSessionHash; ?>
                          <article class="session-card">
                            <div class="session-card__head">
                              <div>
                                <div class="session-chip"><?= $isCurrentSessionCard ? 'Current Session' : e((string) ($sessionRecord['device_label'] ?? 'Active Session')) ?></div>
                                <h4 class="mt-3 text-base font-semibold"><?= e((string) ($sessionRecord['device_label'] ?? 'Appareil inconnu')) ?></h4>
                              </div>
                              <?php if (!$isCurrentSessionCard): ?>
                                <form method="POST">
                                  <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['settings_csrf_token']) ?>">
                                  <input type="hidden" name="settings_action" value="revoke_session">
                                  <input type="hidden" name="session_record_id" value="<?= (int) ($sessionRecord['id'] ?? 0) ?>">
                                  <button type="submit" class="inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium session-remove">Remove</button>
                                </form>
                              <?php endif; ?>
                            </div>
                            <div class="session-card__meta">
                              <div><strong>Browser:</strong> <?= e((string) ($sessionRecord['user_agent'] ?? 'Navigateur inconnu')) ?></div>
                              <div><strong>IP:</strong> <?= e((string) ($sessionRecord['ip_address'] ?? 'Inconnue')) ?></div>
                              <div><strong>Started:</strong> <?= e(accountSessionsFormatDate($sessionRecord['created_at'] ?? null)) ?></div>
                              <div><strong>Last active:</strong> <?= e(accountSessionsFormatDate($sessionRecord['last_activity_at'] ?? null)) ?></div>
                            </div>
                          </article>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <div class="settings-tip">
                          <p class="text-sm text-muted-foreground">Aucune session active n’a été trouvée pour ce compte.</p>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="settings-tip">
                    <div class="flex items-start gap-3">
                      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 text-blue-500">
                        <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path>
                      </svg>
                      <div>
                        <h4 class="mb-1 text-sm font-medium">Security Tip</h4>
                        <p>If you notice any suspicious activity, remove the session immediately, change your password and keep two-factor authentication enabled.</p>
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

        var webauthnRegisterButton = document.getElementById('webauthn-register-button');
        var webauthnRegistrationForm = document.getElementById('webauthn-registration-form');
        var webauthnRegistrationResponse = document.getElementById('webauthn_registration_response');
        var webauthnLabelInput = document.getElementById('webauthn_label');
        if (webauthnRegisterButton && webauthnRegistrationForm && webauthnRegistrationResponse) {
          var decodeBase64Url = function (value) {
            var padding = '='.repeat((4 - (value.length % 4 || 4)) % 4);
            var normalized = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
            var binary = atob(normalized);
            return Uint8Array.from(binary, function (char) { return char.charCodeAt(0); });
          };

          var encodeBase64Url = function (buffer) {
            var bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
            var binary = '';
            bytes.forEach(function (byte) {
              binary += String.fromCharCode(byte);
            });
            return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
          };

          var toCreationOptions = function (options) {
            return {
              challenge: decodeBase64Url(options.challenge),
              rp: options.rp,
              user: {
                id: decodeBase64Url(options.user.id),
                name: options.user.name,
                displayName: options.user.displayName
              },
              pubKeyCredParams: options.pubKeyCredParams || [],
              timeout: options.timeout,
              attestation: options.attestation,
              authenticatorSelection: options.authenticatorSelection || undefined,
              excludeCredentials: Array.isArray(options.excludeCredentials)
                ? options.excludeCredentials.map(function (credential) {
                    return {
                      type: credential.type,
                      id: decodeBase64Url(credential.id),
                      transports: credential.transports || undefined
                    };
                  })
                : []
            };
          };

          webauthnRegisterButton.addEventListener('click', async function () {
            if (!window.PublicKeyCredential || !navigator.credentials) {
              window.alert('Votre navigateur ne prend pas en charge WebAuthn sur cette page.');
              return;
            }

            var initialLabel = webauthnRegisterButton.textContent;
            webauthnRegisterButton.disabled = true;
            webauthnRegisterButton.textContent = 'Enregistrement…';

            try {
              var options = JSON.parse(webauthnRegisterButton.dataset.options || 'null');
              if (!options || !options.challenge) {
                throw new Error('Les options WebAuthn ne sont pas disponibles.');
              }

              var credential = await navigator.credentials.create({
                publicKey: toCreationOptions(options)
              });

              var payload = {
                id: credential.id,
                rawId: encodeBase64Url(credential.rawId),
                type: credential.type,
                response: {
                  clientDataJSON: encodeBase64Url(credential.response.clientDataJSON),
                  attestationObject: encodeBase64Url(credential.response.attestationObject),
                  transports: typeof credential.response.getTransports === 'function' ? credential.response.getTransports() : []
                }
              };

              var existingLabelField = webauthnRegistrationForm.querySelector('input[name="webauthn_label"]');
              if (existingLabelField) {
                existingLabelField.remove();
              }

              if (webauthnLabelInput && webauthnLabelInput.value) {
                var labelField = document.createElement('input');
                labelField.type = 'hidden';
                labelField.name = 'webauthn_label';
                labelField.value = webauthnLabelInput.value;
                webauthnRegistrationForm.appendChild(labelField);
              }

              webauthnRegistrationResponse.value = JSON.stringify(payload);
              webauthnRegistrationForm.submit();
            } catch (error) {
              console.error(error);
              window.alert(error && error.message ? error.message : 'L’enregistrement de la clé de sécurité a échoué.');
              webauthnRegisterButton.disabled = false;
              webauthnRegisterButton.textContent = initialLabel;
            }
          });
        }

      });
    })();
  </script>

  <script src="../assets/js/totp-qr.js"></script>
  <script>
    if (typeof window.initTotpQrCodes === 'function') {
      window.initTotpQrCodes();
    }
  </script>

  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../assets/js/k8s_menu.js" defer></script>
</body>
</html>
