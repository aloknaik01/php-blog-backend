<?php
header("Content-Type: application/json");

// Only accept PUT requests
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["error" => "Only PUT method allowed"]);
    exit();
}

// Get post ID from query string
if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Post ID is required"]);
    exit();
}

$postId = intval($_GET['id']);

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['title']) || !isset($data['content'])) {
    http_response_code(400);
    echo json_encode(["error" => "Title and content are required"]);
    exit();
}

include 'db.php';

$title = $conn->real_escape_string($data['title']);
$content = $conn->real_escape_string($data['content']);

// Update query
$sql = "UPDATE posts SET title = '$title', content = '$content' WHERE id = $postId";

if ($conn->query($sql)) {
    if ($conn->affected_rows > 0) {
        echo json_encode(["message" => "Post updated successfully"]);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Post not found or no changes made"]);
    }
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to update post"]);
}

$conn->close();
?>git add update-post.php
