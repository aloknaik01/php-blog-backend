<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../response.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

try {

    if (!isset($_GET['id'])) {
        sendError("Post ID is required", 400);
    }

    $id = intval($_GET['id']);
    $sql = "SELECT * FROM posts WHERE id = $id";
    $result = $conn->query($sql);

    if ($result->num_rows === 0) {
        sendError("Post not found", 404);
    }

    $post = $result->fetch_assoc();
    sendResponse($post, 200, "Post fetched successfully");
} catch (Exception $e) {
    sendError("Unexpected error: " . $e->getMessage(), 500);
}
