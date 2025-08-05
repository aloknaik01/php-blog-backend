<?php
require_once '../vendor/autoload.php';
require_once '../db.php';
require_once '../response.php';
require_once '../auth/authorize.php';

// Only allow PUT requests
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendError("Only PUT method allowed", 405);
}

// Get post ID from URL parameter
$postId = $_GET['id'] ?? null;
if (!$postId) {
    sendError("Post ID is required", 400);
}

// Decode request body
$data = json_decode(file_get_contents("php://input"));

$title = trim($data->title ?? '');
$content = trim($data->content ?? '');

// Validate required fields
if (!$title || !$content) {
    sendError("Title and content are required", 400);
}

// NEW: Use the advanced authorization that checks post ownership
$user = authorizePostModification($postId);

try {
    // Update the post (we already verified ownership in authorization)
    $stmt = $conn->prepare("UPDATE posts SET title = ?, content = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $title, $content, $postId);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        sendError("No changes made to the post", 400);
    }
    
    // Get the updated post details
    $getStmt = $conn->prepare("
        SELECT p.*, u.name as author_name 
        FROM posts p 
        JOIN users u ON p.author_id = u.id 
        WHERE p.id = ?
    ");
    $getStmt->bind_param("i", $postId);
    $getStmt->execute();
    $updatedPost = $getStmt->get_result()->fetch_assoc();
    
    sendResponse([
        'id' => $postId,
        'title' => $title,
        'content' => $content,
        'author_name' => $updatedPost['author_name'],
        'updated_by' => $user['role'] === 'admin' ? 'Admin' : 'Post Owner',
        'updated_at' => $updatedPost['updated_at']
    ], 200, "Post updated successfully");

} catch (Exception $e) {
    sendError("Failed to update post: " . $e->getMessage(), 500);
}
?>