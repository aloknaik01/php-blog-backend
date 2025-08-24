<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
require_once '../db.php';
require_once "cors.php";
require_once '../response.php';


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Only POST method allowed", 405);
    exit;
}


$input = json_decode(file_get_contents("php://input"), true);


$username = trim($input['username'] ?? $_POST['username'] ?? '');
$email = trim($input['email'] ?? $_POST['email'] ?? '');
$password = trim($input['password'] ?? $_POST['password'] ?? '');

// Required fields
if (!$username || !$email || !$password) {
    sendError("All fields are required", 400);
    exit;
}

// Username validation 
// - At least 3 chars
// - Only letters, numbers, underscores
if (strlen($username) < 3) {
    sendError("Username must be at least 3 characters long", 400);
    exit;
}
if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    sendError("Username can only contain letters, numbers, and underscores", 400);
    exit;
}

// 3. Email validation 
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendError("Invalid email format", 400);
    exit;
}

//  4. Password validation 
// - At least 6 chars
// - Must contain at least one letter and one number
if (strlen($password) < 6) {
    sendError("Password must be at least 6 characters long", 400);
    exit;
}
if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    sendError("Password must contain at least one letter and one number", 400);
    exit;
}

//  5. Duplicate user/email check 
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
$stmt->bind_param("ss", $email, $username);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    sendError("Username or email already exists", 409);
    exit;
}

//  6. Insert user
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $email, $hashedPassword);

if ($stmt->execute()) {
    sendResponse(
        null,
        201,
        "User registered successfully"
    );
} else {
    sendError("Registration failed due to server error", 500);
}
