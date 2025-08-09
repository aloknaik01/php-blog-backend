<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// === Allow only POST method ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Only POST method allowed", 405);
}

$data = json_decode(file_get_contents("php://input"));

$email = trim($data->email ?? '');
$password = trim($data->password ?? '');

// === Required field check ===
if (!$email || !$password) {
    sendError("Email and password are required", 400);
}

// === Email format validation ===
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendError("Invalid email format", 400);
}

try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    // === Verify password ===
    if (!$user || !password_verify($password, $user['password'])) {
        sendError("Invalid email or password", 401);
    }

    // === JWT Payload ===
    $payload = [
        'id' => $user['id'],
        'username' => $user['username'], // added for convenience
        'email' => $user['email'],
        'role' => $user['role'],
        'exp' => time() + (60 * 60 * 24) // expires in 24 hours
    ];

    $jwt_secret = $_ENV['JWT_SECRET'] ?? null;
    if (!$jwt_secret) {
        sendError("JWT secret key not found in environment", 500);
    }

    // === Create JWT Token ===
    $token = JWT::encode($payload, $jwt_secret, 'HS256');

    // === Dynamic cookie name based on role ===
    $role = strtolower($user['role']);
    $namePart = $user['username'] ?? explode('@', $user['email'])[0];
    $cookieName = ($role === 'admin')
        ? strtolower($namePart) . 'AdminToken'
        : strtolower($namePart) . 'Token';

    // === Set secure HTTP-only cookie ===
    setcookie($cookieName, $token, [
        'expires' => time() + 86400,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    // === Final Response ===
    sendResponse([
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'cookie_name' => $cookieName
        ]
    ], 200, "Login successful");

} catch (Exception $e) {
    // Avoid sending sensitive DB or JWT errors directly to the client
    sendError("Login failed due to server error", 500);
}
