<?php
header("Content-Type: application/json");

// Only accept DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(["error" => "Only DELETE method allowed"]);
    exit();
}

// Get post ID from query string
if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Post ID is required"]);
    exit();
}

$postId = intval($_GET['id']);

include 'db.php';

// Delete query
$sql = "DELETE FROM posts WHERE id = $postId";

if ($conn->query($sql)) {
    if ($conn->affected_rows > 0) {
        echo json_encode(["message" => "Post deleted successfully"]);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Post not found"]);
    }
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to delete post"]);
}

$conn->close();
?>
