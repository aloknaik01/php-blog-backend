<?php
require_once '../response.php';

// List of potential cookie suffixes based on roles/names
$possibleCookies = array_keys($_COOKIE);
$cleared = false;

foreach ($possibleCookies as $cookieName) {
    if (str_ends_with($cookieName, 'Token')) {
        setcookie($cookieName, '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
        $cleared = true;
    }
}

// Optional: fallback if none matched
if (!$cleared && isset($_COOKIE['token'])) {
    setcookie("token", "", time() - 3600, "/", "", isset($_SERVER['HTTPS']), true);
}

sendResponse(null, 200, "Logged out successfully");
