<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';
require_once '../auth/authMiddleware.php';
require_once '../services/cloudinary.php';

// Only POST method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Only POST method allowed", 405);
}

// Authenticated user (admin/author)
$user = requireRole(['admin', 'author']);

// Check if form-data is sent
if (!isset($_POST['title']) || !isset($_POST['content'])) {
    sendError("Title and content are required", 400);
}

$title = trim($_POST['title']);
$content = trim($_POST['content']);

// Title validation
if ($title === '') {
    sendError("Title is required", 400);
}
if (strlen($title) > 255) {
    sendError("Title must not exceed 255 characters", 400);
}

// Content validation
if ($content === '') {
    sendError("Content is required", 400);
}
if (strlen($content) > 10000) {
    sendError("Content must not exceed 10,000 characters", 400);
}

// Image upload handling
$featuredImageUrl = null;
if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    
    if ($_FILES['featured_image']['error'] !== UPLOAD_ERR_OK) {
        sendError("Image upload error", 400);
    }

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array(mime_content_type($_FILES['featured_image']['tmp_name']), $allowedTypes)) {
        sendError("Only JPG, PNG, and WEBP images are allowed", 400);
    }

    // Validate file size (max 5MB)
    if ($_FILES['featured_image']['size'] > 5 * 1024 * 1024) {
        sendError("Image size must not exceed 5MB", 400);
    }

    // Upload to Cloudinary
    $tmpFilePath = $_FILES['featured_image']['tmp_name'];
    $uploadUrl = uploadToCloudinary($tmpFilePath, 'blog-app/posts');
    if ($uploadUrl) {
        $featuredImageUrl = $uploadUrl;
    } else {
        sendError("Image upload failed", 500);
    }
}

try {
    // Insert into DB
    $stmt = $conn->prepare("
        INSERT INTO posts (title, content, author_id, featured_image) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("ssis", $title, $content, $user['id'], $featuredImageUrl);

    if (!$stmt->execute()) {
        throw new Exception("Failed to create post");
    }

    $postId = $conn->insert_id;

    // Fetch created post
    $getStmt = $conn->prepare("
        SELECT p.id, p.title, p.content, p.featured_image, p.created_at, p.author_id,
               u.email as author_email
        FROM posts p
        JOIN users u ON p.author_id = u.id
        WHERE p.id = ?
    ");
    $getStmt->bind_param("i", $postId);
    $getStmt->execute();
    $result = $getStmt->get_result();
    $post = $result->fetch_assoc();

    $authorName = explode('@', $post['author_email'])[0];

    sendResponse([
        'post' => [
            'id' => (int)$post['id'],
            'title' => $post['title'],
            'content' => $post['content'],
            'featured_image' => $post['featured_image'],
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
