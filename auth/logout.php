<?php
require_once '../vendor/autoload.php';
require_once '../db.php';
require_once __DIR__ . '/authMiddleware.php';
require_once __DIR__ . '/../response.php';

// Authenticate user (must be logged in)
$user = requireAuth();

// Get the cookie name from authenticated user
$cookieName = $user['cookie_name'] ?? null;

if ($cookieName && isset($_COOKIE[$cookieName])) {
    // Expire the cookie
    setcookie(
        $cookieName,
        '',
        time() - 3600,
        '/',
        '',
        isset($_SERVER['HTTPS']),
        true
    );
    sendResponse(null, 200, "Logout successful for {$user['email']}");
} else {
    sendError("No active session found", 400);
}
