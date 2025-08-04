<?php
require_once '../db.php';
require_once '../response.php';
session_start();

$data = json_decode(file_get_contents("php://input"), true);
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role']
        ];
        sendResponse($_SESSION['user'], 200, "Login successful");
    } else {
        sendError("Invalid credentials", 401);
    }
} catch (Exception $e) {
    sendError("Login failed: " . $e->getMessage(), 500);
}
