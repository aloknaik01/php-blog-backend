<?php
require_once '../db.php';
require_once '../response.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['username'], $data['email'], $data['password'])) {
    sendError("All fields are required", 400);
}

$username = $data['username'];
$email = $data['email'];
$password = password_hash($data['password'], PASSWORD_BCRYPT);
$role = $data['role'] ?? 'user';

try {
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $email, $password, $role);
    $stmt->execute();

    sendResponse(null, 201, "User registered successfully");
} catch (Exception $e) {
    sendError("Registration failed: " . $e->getMessage(), 500);
}
