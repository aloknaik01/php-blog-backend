<?php
// posts/create-post.php
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../response.php';
require_once __DIR__ . '/../auth/authMiddleware.php';
require_once __DIR__ . '/../services/cloudinary.php'; // service helper

// Allow only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Only POST method allowed", 405);
}

// Auth (cookie-based)
$user = requireRole(['admin', 'author']);

// Ensure form-data fields
$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int) $_POST['category_id'] : null;
$status = trim($_POST['status'] ?? 'draft');

// Basic validation
if ($title === '') sendError("Title is required", 400);
if (strlen($title) > 255) sendError("Title must not exceed 255 characters", 400);

if ($content === '') sendError("Content is required", 400);
if (strlen($content) > 10000) sendError("Content must not exceed 10,000 characters", 400);

$allowedStatuses = ['draft', 'published', 'archived'];
if (!in_array($status, $allowedStatuses)) sendError("Invalid status value", 400);

// Handle optional image upload (validate before uploading)
$featuredImageUrl = null;
if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    // Check PHP upload errors
    $fileErr = $_FILES['featured_image']['error'];
    if ($fileErr !== UPLOAD_ERR_OK) {
        sendError("Image upload error (code: $fileErr)", 400);
    }

    // Validate MIME type and size before sending to Cloudinary
    $tmpPath = $_FILES['featured_image']['tmp_name'];
    $mime = @mime_content_type($tmpPath);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMimes)) {
        sendError("Only JPG, PNG and WEBP images are allowed", 400);
    }

    $maxBytes = 5 * 1024 * 1024; // 5 MB
    if ($_FILES['featured_image']['size'] > $maxBytes) {
        sendError("Image size must not exceed 5MB", 400);
    }

    // Upload to Cloudinary via service helper
    try {
        $featuredImageUrl = uploadToCloudinary($tmpPath, 'blog_posts');
        if (!$featuredImageUrl) {
            sendError("Image upload failed", 500);
        }
    } catch (Exception $e) {
        sendError("Image upload failed: " . $e->getMessage(), 500);
    }
}

// Generate SEO-friendly unique slug
function generate_slug($title, $conn) {
    $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
    $slug = $base;
    $i = 1;

    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM posts WHERE slug = ?");
    if (!$stmt) {
        // DB prepare failed
        return $slug . '-' . time();
    }

    $count = 0; 
        while (true) {
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        if ($count == 0) break;
        $slug = $base . '-' . $i++;
    }
    $stmt->close();
    return $slug;
}

$slug = generate_slug($title, $conn);

// Insert post
try {
    $stmt = $conn->prepare("
        INSERT INTO posts (title, slug, content, featured_image, status, author_id, category_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) sendError("Database prepare failed", 500);

    $stmt->bind_param(
        "sssssis",
        $title,
        $slug,
        $content,
        $featuredImageUrl,
        $status,
        $user['id'],
        $category_id
    );

    if (!$stmt->execute()) {
        throw new Exception($stmt->error ?: "Insert failed");
    }

    $postId = $conn->insert_id;

    // Fetch and return created post with author info
    $getStmt = $conn->prepare("
        SELECT p.id, p.title, p.slug, p.content, p.featured_image, p.status, p.views, p.created_at, p.updated_at,
               p.author_id, u.email AS author_email
        FROM posts p
        JOIN users u ON p.author_id = u.id
        WHERE p.id = ?
        LIMIT 1
    ");
    if (!$getStmt) sendError("Database prepare failed (fetch)", 500);

    $getStmt->bind_param("i", $postId);
    $getStmt->execute();
    $res = $getStmt->get_result();
    $post = $res->fetch_assoc();

    if (!$post) sendError("Failed to retrieve created post", 500);

    $authorName = explode('@', $post['author_email'])[0];

    sendResponse([
        'post' => [
            'id' => (int)$post['id'],
            'title' => $post['title'],
            'slug' => $post['slug'],
            'content' => $post['content'],
            'featured_image' => $post['featured_image'],
            'status' => $post['status'],
            'views' => (int)$post['views'],
            'created_at' => $post['created_at'],
            'updated_at' => $post['updated_at'],
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
