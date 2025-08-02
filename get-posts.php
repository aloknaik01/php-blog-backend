<?php
header("Content-Type: application/json");

// Include DB connection
include 'db.php';

// Query posts
$sql = "SELECT id, title, content, created_at FROM posts ORDER BY created_at DESC";
$result = $conn->query($sql);

$posts = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $posts[] = $row;
    }
}

// Send response
echo json_encode($posts);

// Close connection
$conn->close();
?>
