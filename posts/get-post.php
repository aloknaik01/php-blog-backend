<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';
require_once '../auth/authMiddleware.php';

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Only GET method allowed", 405);
}

// Require authentication (any logged-in user can view posts)
$user = requireAuth();

// Get post ID from URL parameter
$postId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$postId) {
    sendError("Post ID is required", 400);
}

try {
    // Get post with author information
    $stmt = $conn->prepare("
        SELECT p.id, p.title, p.content, p.created_at, p.author_id,
               u.email as author_email, u.role as author_role
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
    
    sendResponse([
        'post' => [
            'id' => (int)$post['id'],
            'title' => $post['title'],
            'content' => $post['content'],
            'created_at' => $post['created_at'],
            'author' => [
                'id' => (int)$post['author_id'],
                'name' => $authorName,
                'email' => $post['author_email'],
                'role' => $post['author_role']
            ],
            'permissions' => [
                'can_edit' => canModifyPost($post['id'], $user['id'], $user['role']),
                'can_delete' => canModifyPost($post['id'], $user['id'], $user['role'])
            ]
        ],
        'viewer' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'role' => $user['role']
        ]
    ], 200, "Post retrieved successfully");
    
} catch (Exception $e) {
    sendError("Failed to retrieve post: " . $e->getMessage(), 500);
}