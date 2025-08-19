<?php
require_once "../db.php";
require_once "../response.php";
require_once "../auth/authMiddleware.php";

header("Content-Type: application/json");

// Allow only DELETE method for unlike action
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(405, ["error" => "Only DELETE method allowed"]);
}

$user = authenticate(); // Verify and get the logged in user
$post_id = $_GET['id'] ?? null; // Post id should be passed in the URL like unlike-post.php?id=35

// Check if post id is provided
if (!$post_id) {
    sendResponse(400, ["error" => "Post id is required"]);
}

// Check if post exists
$stmt = $conn->prepare("SELECT id FROM posts WHERE id = ?");
$stmt->bind_param("i", $post_id);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();

if (!$post) {
    sendResponse(404, ["error" => "Post not found"]);
}

// Check if the user has already liked the post
$stmt = $conn->prepare("SELECT id FROM likes WHERE user_id = ? AND post_id = ?");
$stmt->bind_param("ii", $user['id'], $post_id);
$stmt->execute();
$like = $stmt->get_result()->fetch_assoc();

if (!$like) {
    sendResponse(400, ["error" => "You have not liked this post yet"]);
}

// Unlike means remove the record from likes table
$stmt = $conn->prepare("DELETE FROM likes WHERE id = ?");
$stmt->bind_param("i", $like['id']);
$stmt->execute();

// Instead of deleting notification completely, mark it inactive
$stmt = $conn->prepare("UPDATE notifications SET is_active = 0 WHERE type = 'like' AND user_id = ? AND post_id = ?");
$stmt->bind_param("ii", $user['id'], $post_id);
$stmt->execute();

// Log the unlike action in unlike_logs table
// This table should already be created at migration or setup time
$stmt = $conn->prepare("INSERT INTO unlike_logs (user_id, post_id, created_at) VALUES (?, ?, NOW())");
$stmt->bind_param("ii", $user['id'], $post_id);
$stmt->execute();

// Send response with extra information
sendResponse(200, [
    "message" => "Post unliked successfully",
    "actions" => [
        "can_like_again" => true,
        "like_url" => "/like-post.php?id=" . $post_id
    ]
]);
