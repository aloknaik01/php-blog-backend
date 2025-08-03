<?php
header("Content-Type: application/json");

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Post ID is required"]);
    exit();
}

$postId = intval($_GET['id']);

include 'db.php';

$sql = "SELECT id, title, content, created_at FROM posts WHERE id = $postId";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $post = $result->fetch_assoc();
    echo json_encode($post);
} else {
    http_response_code(404);
    echo json_encode(["error" => "Post not found"]);
}

$conn->close();
?>
