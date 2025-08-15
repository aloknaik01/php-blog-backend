<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';

// ✅ Only GET method allow
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(["error" => "Method Not Allowed. Only GET is allowed."], 405);
}

try {
    $sql = "SELECT id, name, slug, created_at FROM categories ORDER BY created_at DESC";
    $result = $conn->query($sql);

    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }

    sendResponse([
        "message" => "✅ Categories fetched successfully!",
        "count" => count($categories),
        "categories" => $categories
    ], 200);

} catch (Exception $e) {
    sendResponse(["error" => "Something went wrong while fetching categories.", "details" => $e->getMessage()], 500);
}
