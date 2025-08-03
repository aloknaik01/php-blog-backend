

<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../response.php';

try {
    $sql = "SELECT * FROM posts";
    $result = $conn->query($sql);

    $posts = [];
    while ($row = $result->fetch_assoc()) {
        $posts[] = $row;
    }

    sendResponse($posts, 200, "Posts fetched successfully");
} catch (Exception $e) {
    sendError("Failed to fetch posts: " . $e->getMessage(), 500);
}
