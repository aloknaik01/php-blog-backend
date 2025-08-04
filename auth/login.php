<?php
require_once '../db.php';
require_once '../response.php';
require_once '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// Read request body
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['email']) || !isset($data['password'])) {
    sendError("Email and password are required", 400);
}

$email = $data['email'];
$password = $data['password'];

try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user || !password_verify($password, $user['password'])) {
        sendError("Invalid email or password", 401);
    }

    // JWT Payload
    $payload = [
        'iss' => 'localhost',
        'aud' => 'localhost',
        'iat' => time(),
        'exp' => time() + (60 * 60), // Token expires in 1 hour
        'data' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ];

    $jwt = JWT::encode($payload, getenv("JWT_SECRET"), 'HS256');

    sendResponse([
        'token' => $jwt,
        'user' => $payload['data']
    ], 200, "Login successful");

} catch (Exception $e) {
    sendError("Login failed: " . $e->getMessage(), 500);
}
?>
