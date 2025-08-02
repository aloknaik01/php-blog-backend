<?php
header("Content-Type: application/json");

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Only POST method allowed"]);
    exit();
}

// Include DB connection
include 'db.php';

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);

// Validate input
if (!isset($data['title']) || !isset($data['content'])) {
    http_response_code(400);
    echo json_encode(["error" => "Title and content are required"]);
    exit();
}

$title = $conn->real_escape_string($data['title']);
$content = $conn->real_escape_string($data['content']);

// Insert query
$sql = "INSERT INTO posts (title, content) VALUES ('$title', '$content')";

if ($conn->query($sql)) {
    http_response_code(201);
    echo json_encode(["message" => "Post created successfully", "id" => $conn->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to create post"]);
}

$conn->close();
?>
