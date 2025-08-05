<?php
require_once '../vendor/autoload.php';
require_once '../db.php';
require_once '../response.php';
require_once '../auth/authMiddleware.php'; 

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Only POST method allowed", 405);
}

// Decode request body
$data = json_decode(file_get_contents("php://input"));

$title = trim($data->title ?? '');
$content = trim($data->content ?? '');

// Validate required fields
if (!$title || !$content) {
    sendError("Title and content are required", 400);
}

// Authorize only 'admin' and 'author' roles
$user = authorize(['admin', 'author']);
$author_id = $user['id'];

try {
    $stmt = $conn->prepare("INSERT INTO posts (title, content, author_id) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $title, $content, $author_id);
    $stmt->execute();

    $newPostId = $stmt->insert_id;

    sendResponse([
        'id' => $newPostId,
        'title' => $title,
        'content' => $content,
        'author_id' => $author_id,
        'created_at' => date("Y-m-d H:i:s")
    ], 201, "Post created successfully");

} catch (Exception $e) {
    sendError("Failed to create post: " . $e->getMessage(), 500);
}
