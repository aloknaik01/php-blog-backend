<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../response.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function authenticate() {
    global $conn;
    
    $jwt_secret = $_ENV['JWT_SECRET'] ?? null;
    if (!$jwt_secret) {
        sendError("JWT secret key not found", 500);
        return false;
    }
    
    // Get all cookies
    $cookies = $_COOKIE;
    $token = null;
    $cookieName = null;
    
    // Look for any token cookie (AdminToken, AlokToken, etc.)
    foreach ($cookies as $name => $value) {
        if (strpos($name, 'Token') !== false) {
            $token = $value;
            $cookieName = $name;
            break;
        }
    }
    
    if (!$token) {
        sendError("Authentication required - No token found", 401);
        return false;
    }
    
    try {
        $decoded = JWT::decode($token, new Key($jwt_secret, 'HS256'));
        
        // Verify user still exists and get fresh data
        $stmt = $conn->prepare("SELECT id, email, role FROM users WHERE id = ?");
        $stmt->bind_param("i", $decoded->id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!$user) {
            sendError("User not found", 401);
            return false;
        }
        
        // Extract name from email if no name column exists
        $name = explode('@', $user['email'])[0];
        
        // Return user data
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $name,
            'role' => $user['role'] ?? 'user', // Default to 'user' if role is null
            'cookie_name' => $cookieName
        ];
        
    } catch (Exception $e) {
        sendError("Invalid token: " . $e->getMessage(), 401);
        return false;
    }
}

function requireAuth() {
    $user = authenticate();
    if (!$user) {
        exit;
    }
    return $user;
}

function requireRole($allowedRoles) {
    $user = requireAuth();
    
    if (!in_array($user['role'], $allowedRoles)) {
        sendError("Access denied. Required roles: " . implode(', ', $allowedRoles), 403);
        exit;
    }
    
    return $user;
}

function canModifyPost($postId, $userId, $userRole) {
    global $conn;
    
    // Admin can modify any post
    if ($userRole === 'admin') {
        return true;
    }
    
    // Check if user is the author of the post
    $stmt = $conn->prepare("SELECT author_id FROM posts WHERE id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    $post = $result->fetch_assoc();
    
    if (!$post) {
        return false;
    }
    
    return $post['author_id'] == $userId;
}