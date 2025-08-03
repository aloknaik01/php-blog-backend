

<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../response.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PUT");
header("Content-Type: application/json");

try {
    $data = json_decode(file_get_contents("php://input"), true);
     
    $postId = intval($_GET['id']);

    if ( !$postId || !isset($data['title']) || !isset($data['content'])) {
        sendError("Missing required fields", 400);
    }

    $id = $postId;
    $title = $conn->real_escape_string($data['title']);
    $content = $conn->real_escape_string($data['content']);

    $sql = "UPDATE posts SET title = '$title', content = '$content' WHERE id = $id";

    if ($conn->query($sql) === TRUE) {
        sendResponse(null, 200, "Post updated successfully");
    } else {
        sendError("Failed to update post: " . $conn->error, 500);
    }
} catch (Exception $e) {
    sendError("Unexpected error: " . $e->getMessage(), 500);
}
