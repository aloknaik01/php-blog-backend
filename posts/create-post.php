<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';
require_once '../auth/authMiddleware.php';

// Only POST method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Only POST method allowed", 405);
}

// Only admin and author can create posts
$user = requireRole(['admin', 'author']);

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);

// Validate required fields
$title = trim($data['title'] ?? '');
$content = trim($data['content'] ?? '');

if (empty($title)) {
    sendError("Title is required", 400);
}

if (empty($content)) {
    sendError("Content is required", 400);
}

// Validate title length
if (strlen($title) > 255) {
    sendError("Title must not exceed 255 characters", 400);
}

// Validate content length (optional - you can adjust this)
if (strlen($content) > 10000) {
    sendError("Content must not exceed 10,000 characters", 400);
}

try {
    // Insert new post
    $stmt = $conn->prepare("INSERT INTO posts (title, content, author_id) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $title, $content, $user['id']);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create post");
    }
    
    $postId = $conn->insert_id;
    
    // Get the created post with author info
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
    
    if (!$post) {
        throw new Exception("Failed to retrieve created post");
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
                'email' => $post['author_email']
            ]
        ],
        'message' => 'Post created successfully'
    ], 201, "Post created successfully");
    
} catch (Exception $e) {
    sendError("Failed to create post: " . $e->getMessage(), 500);
}