<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../response.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: DELETE");
header("Content-Type: application/json");

try {
    $data = json_decode(file_get_contents("php://input"), true);

     $postId = intval($_GET['id']);
     
    // if ($postId) {
    //     sendError("Post ID is required", 400);
    // }

    $id = $postId;
    $sql = "DELETE FROM posts WHERE id = $id";

    if ($conn->query($sql) === TRUE) {
        sendResponse(null, 200, "Post deleted successfully");
    } else {
        sendError("Failed to delete post: " . $conn->error, 500);
    }
} catch (Exception $e) {
    sendError("Unexpected error: " . $e->getMessage(), 500);
}
