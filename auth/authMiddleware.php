<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../response.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/cors.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;


function __pickTokenFromRequest(): array
{
    // 1) Authorization header 
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth && stripos($auth, 'Bearer ') === 0) {
        return ['token' => trim(substr($auth, 7)), 'cookieName' => null];
    }

    // 2) Cookies (multi-cookie style)
    $cookies = $_COOKIE ?? [];
    $adminCandidate = null;
    $userCandidate = null;
    $adminName = null;
    $userName = null;

    foreach ($cookies as $name => $value) {
        if (!is_string($name) || !is_string($value))
            continue;
        if (stripos($name, 'admintoken') !== false) {
            $adminCandidate = $value;
            $adminName = $name;

        } elseif (stripos($name, 'token') !== false) {
            $userCandidate = $value;
            $userName = $name;
        }
    }

    if ($adminCandidate)
        return ['token' => $adminCandidate, 'cookieName' => $adminName];
    if ($userCandidate)
        return ['token' => $userCandidate, 'cookieName' => $userName];

    return ['token' => null, 'cookieName' => null];
}

function authenticate()
{
    global $conn;

    $jwt_secret = $_ENV['JWT_SECRET'] ?? null;
    if (!$jwt_secret) {
        sendError("Server configuration error", 500);
        return false;
    }


    $picked = __pickTokenFromRequest();
    $token = $picked['token'];
    $cookieName = $picked['cookieName'];

    if (!$token) {
        sendError("Authentication required", 401);
        return false;
    }

    // Decode + verify token
    try {
        $decoded = JWT::decode($token, new Key($jwt_secret, 'HS256'));
    } catch (Exception $e) {
        sendError("Unauthorized", 401);
        return false;
    }


    $userId = isset($decoded->id) ? (int) $decoded->id : 0;
    if ($userId <= 0) {
        sendError("Unauthorized", 401);
        return false;
    }


    $stmt = $conn->prepare("SELECT id, username, email, role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $userRow = $result->fetch_assoc();
    $stmt->close();

    if (!$userRow) {
        sendError("Unauthorized", 401);
        return false;
    }


    $username = $userRow['username'] ?? null;
    if (!$username || $username === '') {
        $username = explode('@', (string) $userRow['email'])[0];
    }


    $user = [
        'id' => (int) $userRow['id'],
        'username' => $username,
        'email' => $userRow['email'],
        'role' => $userRow['role'] ?? 'user',
        'cookie_name' => $cookieName,
    ];

    return $user;
}


function requireAuth()
{
    $user = authenticate();
    if (!$user) {
        exit;
    }
    return $user;
}

/**
 * @param string[] $allowedRoles
 */
function requireRole($allowedRoles)
{
    $user = requireAuth();


    $allowed = array_map(static fn($r) => strtolower((string) $r), $allowedRoles);
    $role = strtolower((string) $user['role']);

    if (!in_array($role, $allowed, true)) {
        sendError("Access denied", 403);
        exit;
    }
    return $user;
}

function canModifyPost($postId, $userId, $userRole)
{
    global $conn;

    if (strtolower((string) $userRole) === 'admin') {
        return true;
    }

    $stmt = $conn->prepare("SELECT author_id FROM posts WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    $post = $result->fetch_assoc();
    $stmt->close();

    if (!$post) {
        return false;
    }

    return ((int) $post['author_id']) === ((int) $userId);
}
