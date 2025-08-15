<?php
// categories/create-category.php
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

// Require admin role
$user = requireRole(['admin']);

// Get input data
$data = json_decode(file_get_contents("php://input"), true);

// Input validation
$name = trim($data['name'] ?? '');

if (empty($name)) {
    sendError("Category name is required", 400);
}

if (strlen($name) < 2) {
    sendError("Category name must be at least 2 characters long", 400);
}

if (strlen($name) > 100) {
    sendError("Category name cannot exceed 100 characters", 400);
}

// Generate slug from name
function generateSlug($text) {
    // Convert to lowercase
    $text = strtolower($text);
    
    // Replace spaces and special characters with hyphens
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    
    // Remove leading/trailing hyphens
    return trim($text, '-');
}

$slug = generateSlug($name);

if (empty($slug)) {
    sendError("Invalid category name. Please use alphanumeric characters", 400);
}

// Start transaction
$conn->begin_transaction();

try {
    // Check if category name already exists
    $checkStmt = $conn->prepare("SELECT id, name FROM categories WHERE name = ? OR slug = ?");
    $checkStmt->bind_param("ss", $name, $slug);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->fetch_assoc()) {
        $conn->rollback();
        sendError("Category with this name already exists", 409);
    }
    
    // Handle slug conflicts by appending number
    $originalSlug = $slug;
    $counter = 1;
    
    while (true) {
        $slugCheckStmt = $conn->prepare("SELECT id FROM categories WHERE slug = ?");
        $slugCheckStmt->bind_param("s", $slug);
        $slugCheckStmt->execute();
        $slugResult = $slugCheckStmt->get_result();
        
        if ($slugResult->num_rows === 0) {
            break; // Unique slug found
        }
        
        $slug = $originalSlug . '-' . $counter;
        $counter++;
        
        // Prevent infinite loop
        if ($counter > 100) {
            throw new Exception("Could not generate unique slug");
        }
    }
    
    // Create the category (only name and slug)
    $stmt = $conn->prepare("INSERT INTO categories (name, slug) VALUES (?, ?)");
    $stmt->bind_param("ss", $name, $slug);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create category: " . $stmt->error);
    }
    
    $categoryId = $conn->insert_id;
    
    // Commit transaction
    $conn->commit();
    
    // Get the created category with post counts
    $getStmt = $conn->prepare("
        SELECT 
            c.*,
            (SELECT COUNT(*) FROM posts WHERE category_id = c.id AND status = 'published') as published_posts_count,
            (SELECT COUNT(*) FROM posts WHERE category_id = c.id) as total_posts_count
        FROM categories c
        WHERE c.id = ?
    ");
    $getStmt->bind_param("i", $categoryId);
    $getStmt->execute();
    $categoryData = $getStmt->get_result()->fetch_assoc();
    
    // Format response
    sendResponse([
        'category' => [
            'id' => (int)$categoryData['id'],
            'name' => $categoryData['name'],
            'slug' => $categoryData['slug'],
            'published_posts_count' => (int)$categoryData['published_posts_count'],
            'total_posts_count' => (int)$categoryData['total_posts_count']
        ],
        'urls' => [
            'category_posts' => "/posts/get-posts.php?category=" . $categoryData['slug'],
            'all_categories' => "/categories/get-categories.php"
        ]
    ], 201, "Category created successfully");
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    // Error logging
    $errorContext = [
        'category_name' => $name,
        'attempted_slug' => $slug,
        'user_id' => $user['id'],
        'error_message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    error_log("Create Category Error: " . json_encode($errorContext));
    
    sendError("Failed to create category: " . $e->getMessage(), 500);
}
?>