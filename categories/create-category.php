<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';
require_once '../auth/authMiddleware.php';


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(["error" => "Method Not Allowed. Only POST is allowed."], 405);
}


$user = authenticate();
if (!$user) {
    sendResponse(["error" => "Unauthorized. Please login again."], 401);
}


if (!in_array($user['role'], ['admin', 'author'])) {
    sendResponse(["error" => "Forbidden. Only admin or author can create categories."], 403);
}


$data = json_decode(file_get_contents("php://input"), true);
$categoryName = trim($data['name'] ?? '');


if (empty($categoryName)) {
    sendResponse(["error" => "Category name is required."], 400);
}

try {

    $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
    $stmt->bind_param("s", $categoryName);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->fetch_assoc()) {
        sendResponse(["error" => "Category '{$categoryName}' already exists."], 400);
    }


    $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
    $stmt->bind_param("s", $categoryName);
    $stmt->execute();

    sendResponse([
        "message" => " Category created successfully!",
        "category" => [
            "id" => $conn->insert_id,
            "name" => $categoryName
        ],

    ], 201);

} catch (Exception $e) {
    sendResponse(["error" => "Something went wrong while creating category.", "details" => $e->getMessage()], 500);
}
