<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(["error" => "Method Not Allowed. Only GET is allowed."], 405);
}

// ✅ Check query params
$id = $_GET['id'] ?? null;
$slug = $_GET['slug'] ?? null;

if (!$id && !$slug) {
    sendResponse(["error" => "Please provide category id or slug."], 400);
}

try {
    if ($id) {
        $stmt = $conn->prepare("SELECT id, name, slug, created_at FROM categories WHERE id = ?");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $conn->prepare("SELECT id, name, slug, created_at FROM categories WHERE slug = ?");
        $stmt->bind_param("s", $slug);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $category = $result->fetch_assoc();

    if (!$category) {
        sendResponse(["error" => "Category not found."], 404);
    }

    sendResponse([
        "message" => "✅ Category fetched successfully!",
        "category" => $category
    ], 200);

} catch (Exception $e) {
    sendResponse(["error" => "Something went wrong while fetching category.", "details" => $e->getMessage()], 500);
}
