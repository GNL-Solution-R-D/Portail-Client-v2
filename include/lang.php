<?php
// Language Management
// Ensure session is started if not already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Default language logic
if (!isset($_SESSION['language'])) {
    // Priority 1: Cookie
    if (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], ['fr', 'en'])) {
        $_SESSION['language'] = $_COOKIE['lang'];
    }
    // Priority 2: Browser detection
    else {
        $browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'fr', 0, 2);
        $_SESSION['language'] = ($browserLang === 'en') ? 'en' : 'fr';
    }
}

// Check if language change is requested
if (isset($_GET['lang']) && in_array($_GET['lang'], ['fr', 'en'])) {
    $_SESSION['language'] = $_GET['lang'];

    // Check for cookie consent before setting the cookie
    $canSetCookie = false;
    if (isset($_COOKIE['slapia_consent'])) {
        $consent = json_decode($_COOKIE['slapia_consent'], true);
        if (isset($consent['preferences']) && $consent['preferences'] === true) {
            $canSetCookie = true;
        }
    }

    if ($canSetCookie) {
        // Set cookie for 1 year
        setcookie('lang', $_GET['lang'], time() + (365 * 24 * 60 * 60), '/', '', true, true);
    }
    else {
    // If consent is missing/denied, we might want to expire the cookie if it exists?
    // But for now, we just don't set/refresh it.
    }
}

$lang = $_SESSION['language'];

// Load Translation JSON
$langFile = __DIR__ . '/../assets/lang/' . $lang . '.json';
$translations = [];

if (file_exists($langFile)) {
    $json = file_get_contents($langFile);
    $translations = json_decode($json, true);
    if ($translations === null) {
        // Fallback or error logging could go here
        $translations = [];
    }
}

// Function to get translation
function t($key)
{
    global $translations;
    if (isset($translations[$key])) {
        return $translations[$key];
    }
    // Debug: return key if missing, or maybe fallback to default language
    return $key;
}
?>
