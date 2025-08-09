<?php
require_once '../db.php';
require_once '../response.php';

$data = json_decode(file_get_contents("php://input"), true);

// === Basic field validation ===
if (!isset($data['username'], $data['email'], $data['password'])) {
    sendError("All fields are required", 400);
}

$username = trim($data['username']);
$email = trim($data['email']);
$password = trim($data['password']);
$role = $data['role'] ?? 'user';

// === Email format validation ===
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendError("Invalid email format", 400);
}

// === Password length validation ===
if (strlen($password) < 6) {
    sendError("Password must be at least 6 characters long", 400);
}

// === Allow only one admin in the system ===
if ($role === 'admin') {
    $adminCheck = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role = 'admin'");
    $adminCount = $adminCheck->fetch_assoc()['count'] ?? 0;
    if ($adminCount > 0) {
        sendError("Only one admin account is allowed", 403);
    }
}

// === Check for duplicate username or email ===
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
$stmt->bind_param("ss", $username, $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    sendError("Username or email already exists", 409);
}
$stmt->close();

// === Hash the password securely ===
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

try {
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $email, $hashedPassword, $role);
    $stmt->execute();

    sendResponse(null, 201, "User registered successfully");
} catch (Exception $e) {
    // Do not expose full DB error to the user for security reasons
    sendError("Registration failed due to server error", 500);
}
