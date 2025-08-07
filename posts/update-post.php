<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';
require_once '../auth/authMiddleware.php';

// Only PUT/PATCH methods allowed
if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'])) {
    sendError("Only PUT or PATCH method allowed", 405);
}

// Require authentication
$user = requireAuth();

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);

// Get post ID from URL or request body
$postId = null;
if (isset($_GET['id'])) {
    $postId = (int)$_GET['id'];
} elseif (isset($data['id'])) {
    $postId = (int)$data['id'];
}

if (!$postId) {
    sendError("Post ID is required", 400);
}

// Check if user can modify this post
if (!canModifyPost($postId, $user['id'], $user['role'])) {
    sendError("Access denied. You can only update your own posts (unless you're an admin)", 403);
}

// Get current post data
try {
    $stmt = $conn->prepare("SELECT * FROM posts WHERE id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    $currentPost = $result->fetch_assoc();
    
    if (!$currentPost) {
        sendError("Post not found", 404);
    }
    
} catch (Exception $e) {
    sendError("Failed to retrieve post: " . $e->getMessage(), 500);
}

// Validate and prepare update data
$title = isset($data['title']) ? trim($data['title']) : $currentPost['title'];
$content = isset($data['content']) ? trim($data['content']) : $currentPost['content'];

// Validate if new values are provided
if (isset($data['title'])) {
    if (empty($title)) {
        sendError("Title cannot be empty", 400);
    }
    if (strlen($title) > 255) {
        sendError("Title must not exceed 255 characters", 400);
    }
}

if (isset($data['content'])) {
    if (empty($content)) {
        sendError("Content cannot be empty", 400);
    }
    if (strlen($content) > 10000) {
        sendError("Content must not exceed 10,000 characters", 400);
    }
}

try {
    // Update post
    $stmt = $conn->prepare("UPDATE posts SET title = ?, content = ? WHERE id = ?");
    $stmt->bind_param("ssi", $title, $content, $postId);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to update post");
    }
    
    if ($stmt->affected_rows === 0) {
        sendError("No changes made or post not found", 400);
    }
    
    // Get updated post with author info
    $getStmt = $conn->prepare("
        SELECT p.id, p.title, p.content, p.created_at, p.author_id,
               u.email as author_email
        FROM posts p 
        JOIN users u ON p.author_id = u.id 
        WHERE p.id = ?
    ");
    $getStmt->bind_param("i", $postId);
    $getStmt->execute();
    $result = $getStmt->get_result();
    $post = $result->fetch_assoc();
    
    $authorName = explode('@', $post['author_email'])[0]; // Extract name from email
    
    sendResponse([
        'post' => [
            'id' => (int)$post['id'],
            'title' => $post['title'],
            'content' => $post['content'],
            'created_at' => $post['created_at'],
            'author' => [
                'id' => (int)$post['author_id'],
                'name' => $authorName,
                'email' => $post['author_email']
            ]
        ],
        'changes' => [
            'title_updated' => isset($data['title']) && $data['title'] !== $currentPost['title'],
            'content_updated' => isset($data['content']) && $data['content'] !== $currentPost['content']
        ]
    ], 200, "Post updated successfully");
    
} catch (Exception $e) {
    sendError("Failed to update post: " . $e->getMessage(), 500);
}