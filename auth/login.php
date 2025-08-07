<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Only POST method allowed", 405);
}

$data = json_decode(file_get_contents("php://input"));

$email = trim($data->email ?? '');
$password = trim($data->password ?? '');

if (!$email || !$password) {
    sendError("Email and password are required", 400);
}

try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user || !password_verify($password, $user['password'])) {
        sendError("Invalid email or password", 401);
    }

    $payload = [
        'id' => $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'exp' => time() + (60 * 60 * 24) // 24 hours
    ];

    $jwt_secret = $_ENV['JWT_SECRET'] ?? null;
    if (!$jwt_secret) {
        sendError("JWT secret key not found in environment", 500);
    }

    $token = JWT::encode($payload, $jwt_secret, 'HS256');

    // === DYNAMIC COOKIE NAME ===
    $role = strtolower($user['role']);
    $namePart = $user['name'] ?? explode('@', $user['email'])[0];
    $cookieName = '';

    if ($role === 'admin') {
        $cookieName = strtolower($namePart) . 'AdminToken';
    } elseif ($role === 'author') {
        $cookieName = strtolower($namePart) . 'Token'; // e.g., aliceToken, bobToken
    } else {
        $cookieName = strtolower($namePart) . 'Token'; // e.g., charlieToken
    }

    // === SET COOKIE ===
    setcookie($cookieName, $token, [
        'expires' => time() + 86400,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    // === RESPONSE ===
    sendResponse([
        'user' => [
            'id' => $user['id'],
            'name' => $namePart,
            'email' => $user['email'],
            'role' => $user['role'],
            'cookie_name' => $cookieName
        ]
    ], 200, "Login successful");

} catch (Exception $e) {
    sendError("Login failed: " . $e->getMessage(), 500);
}
