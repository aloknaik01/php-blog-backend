<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';
require_once '../auth/authMiddleware.php';

// Only DELETE method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendError("Only DELETE method allowed", 405);
}

// Require authentication
$user = requireAuth();

// Get post ID from URL or request body
$postId = null;
if (isset($_GET['id'])) {
    $postId = (int)$_GET['id'];
} else {
    $data = json_decode(file_get_contents("php://input"), true);
    if (isset($data['id'])) {
        $postId = (int)$data['id'];
    }
}

if (!$postId) {
    sendError("Post ID is required", 400);
}

try {
    // First, get the post to check if it exists and get details for response
    $stmt = $conn->prepare("
        SELECT p.id, p.title, p.author_id, u.email as author_email 
        FROM posts p 
        JOIN users u ON p.author_id = u.id 
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    $post = $result->fetch_assoc();
    
    if (!$post) {
        sendError("Post not found", 404);
    }
    
    $authorName = explode('@', $post['author_email'])[0]; // Extract name from email
    
    // Check if user can modify this post
    if (!canModifyPost($postId, $user['id'], $user['role'])) {
        sendError("Access denied. You can only delete your own posts (unless you're an admin)", 403);
    }
    
    // Delete the post
    $deleteStmt = $conn->prepare("DELETE FROM posts WHERE id = ?");
    $deleteStmt->bind_param("i", $postId);
    
    if (!$deleteStmt->execute()) {
        throw new Exception("Failed to delete post");
    }
    
    if ($deleteStmt->affected_rows === 0) {
        sendError("Post not found or already deleted", 404);
    }
    
    sendResponse([
        'deleted_post' => [
            'id' => (int)$post['id'],
            'title' => $post['title'],
            'author_name' => $authorName
        ],
        'deleted_by' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'role' => $user['role']
        ]
    ], 200, "Post deleted successfully");
    
} catch (Exception $e) {
    sendError("Failed to delete post: " . $e->getMessage(), 500);
}