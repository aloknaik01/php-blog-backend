<?php
require_once '../vendor/autoload.php';
require_once "cors.php";
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';
require_once './authMiddleware.php';


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Only GET method allowed", 405);
}

try {

    $user = requireAuth();
    global $conn;
    $stmt = $conn->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $freshUser = $result->fetch_assoc();

    if (!$freshUser) {
        sendError("User not found", 404);
    }


    sendResponse([
        'user' => [
            'id' => $freshUser['id'],
            'username' => $freshUser['username'],
            'email' => $freshUser['email'],
            'role' => $freshUser['role'],
            'cookie_name' => $user['cookie_name']
        ]
    ], 200, "User fetched successfully");

} catch (Exception $e) {
    sendError("Failed to fetch user: " . $e->getMessage(), 500);
}
