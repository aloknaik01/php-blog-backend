<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../response.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function authenticate()
{
    if (!isset($_COOKIE['token'])) {
        sendError("Unauthorized: No token found", 401);
    }

    $token = $_COOKIE['token'];
    $secret = $_ENV['JWT_SECRET'] ?? null;

    if (!$secret) {
        sendError("JWT secret not found", 500);
    }

    try {
        $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        return (array) $decoded;

    } catch (Exception $e) {
        sendError("Invalid or expired token", 401);
    }
}
