

<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ .'/../response.php';

$data = json_decode(file_get_contents("php://input"), true);
$title = $data['title'] ?? '';
$content = $data['content'] ?? '';

if (!$title || !$content) {
    sendError("Title and content are required", 400);
}

try {
    $stmt = $conn->prepare("INSERT INTO posts (title, content) VALUES (?, ?)");
    $stmt->bind_param("ss", $title, $content);
    $stmt->execute();

    sendResponse(null, 201, "Post created");
} catch (Exception $e) {
    sendError("Failed to create post: " . $e->getMessage(), 500);
}

