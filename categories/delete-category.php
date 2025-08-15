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

// Require admin role
$user = requireRole(['admin']);

// Get category ID
$categoryId = null;
if (isset($_GET['id'])) {
    $categoryId = (int)$_GET['id'];
} else {
    $data = json_decode(file_get_contents("php://input"), true);
    if (isset($data['id'])) {
        $categoryId = (int)$data['id'];
    }
}

if (!$categoryId || $categoryId <= 0) {
    sendError("Valid Category ID is required", 400);
}

// Get deletion strategy (default: reassign posts to null)
$deletionStrategy = $_GET['strategy'] ?? 'reassign_null';
$newCategoryId = null;

if ($deletionStrategy === 'reassign' && isset($_GET['new_category_id'])) {
    $newCategoryId = (int)$_GET['new_category_id'];
    if ($newCategoryId <= 0) {
        sendError("Valid new category ID required for reassignment", 400);
    }
}

// Start transaction
$conn->begin_transaction();

try {
    // Get category details before deletion (using only existing columns)
    $categoryStmt = $conn->prepare("
        SELECT 
            c.id,
            c.name,
            c.slug,
            (SELECT COUNT(*) FROM posts WHERE category_id = c.id AND status = 'published') as published_posts_count,
            (SELECT COUNT(*) FROM posts WHERE category_id = c.id) as total_posts_count
        FROM categories c
        WHERE c.id = ?
    ");
    $categoryStmt->bind_param("i", $categoryId);
    $categoryStmt->execute();
    $category = $categoryStmt->get_result()->fetch_assoc();
    
    if (!$category) {
        $conn->rollback();
        sendError("Category not found", 404);
    }
    
    $postsCount = (int)$category['total_posts_count'];
    $publishedPostsCount = (int)$category['published_posts_count'];
    
    // Handle posts based on deletion strategy
    $postsHandled = [
        'strategy' => $deletionStrategy,
        'affected_posts' => $postsCount,
        'published_posts_affected' => $publishedPostsCount
    ];
    
    if ($postsCount > 0) {
        switch ($deletionStrategy) {
            case 'force_delete':
                // Delete all posts in this category (CASCADE will handle comments and likes)
                $deletePostsStmt = $conn->prepare("DELETE FROM posts WHERE category_id = ?");
                $deletePostsStmt->bind_param("i", $categoryId);
                $deletePostsStmt->execute();
                $postsHandled['posts_deleted'] = $deletePostsStmt->affected_rows;
                break;
                
            case 'reassign':
                // Verify new category exists
                if ($newCategoryId) {
                    $newCategoryCheck = $conn->prepare("SELECT id, name FROM categories WHERE id = ?");
                    $newCategoryCheck->bind_param("i", $newCategoryId);
                    $newCategoryCheck->execute();
                    $newCategory = $newCategoryCheck->get_result()->fetch_assoc();
                    
                    if (!$newCategory) {
                        $conn->rollback();
                        sendError("New category for reassignment not found", 404);
                    }
                    
                    // Reassign posts to new category
                    $reassignStmt = $conn->prepare("UPDATE posts SET category_id = ? WHERE category_id = ?");
                    $reassignStmt->bind_param("ii", $newCategoryId, $categoryId);
                    $reassignStmt->execute();
                    $postsHandled['posts_reassigned'] = $reassignStmt->affected_rows;
                    $postsHandled['reassigned_to'] = [
                        'id' => $newCategoryId,
                        'name' => $newCategory['name']
                    ];
                } else {
                    $conn->rollback();
                    sendError("New category ID required for reassignment strategy", 400);
                }
                break;
                
            case 'reassign_null':
            default:
                // Set category_id to NULL (uncategorized posts)
                $nullifyStmt = $conn->prepare("UPDATE posts SET category_id = NULL WHERE category_id = ?");
                $nullifyStmt->bind_param("i", $categoryId);
                $nullifyStmt->execute();
                $postsHandled['posts_uncategorized'] = $nullifyStmt->affected_rows;
                break;
        }
    }
    
    // Delete the category
    $deleteCategoryStmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
    $deleteCategoryStmt->bind_param("i", $categoryId);
    
    if (!$deleteCategoryStmt->execute()) {
        throw new Exception("Failed to delete category from database");
    }
    
    if ($deleteCategoryStmt->affected_rows === 0) {
        $conn->rollback();
        sendError("Category not found or already deleted", 404);
    }
    
    // Log deletion activity (simple logging without audit table)
    $logData = [
        'action' => 'DELETE_CATEGORY',
        'category_id' => $categoryId,
        'category_name' => $category['name'],
        'posts_affected' => $postsCount,
        'published_posts_affected' => $publishedPostsCount,
        'deletion_strategy' => $deletionStrategy,
        'deleted_by_user_id' => $user['id'],
        'deleted_by_username' => $user['name'],
        'timestamp' => date('Y-m-d H:i:s'),
        'ip_address' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null
    ];
    
    error_log("Category Deletion: " . json_encode($logData));
    
    // Commit transaction
    $conn->commit();
    
    // Prepare response
    $responseData = [
        'deleted_category' => [
            'id' => (int)$category['id'],
            'name' => $category['name'],
            'slug' => $category['slug']
        ],
        'posts_handling' => $postsHandled,
        'deleted_by' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'role' => $user['role'],
            'timestamp' => date('Y-m-d H:i:s')
        ],
        'operation' => [
            'success' => true,
            'deletion_strategy' => $deletionStrategy
        ]
    ];
    
    // Add warnings or recommendations based on deletion impact
    $warnings = [];
    if ($publishedPostsCount > 0) {
        if ($deletionStrategy === 'force_delete') {
            $warnings[] = "Deleted {$publishedPostsCount} published posts. This may affect SEO and user bookmarks.";
        } elseif ($deletionStrategy === 'reassign_null') {
            $warnings[] = "{$publishedPostsCount} published posts are now uncategorized. Consider organizing them.";
        }
    }
    
    if (!empty($warnings)) {
        $responseData['warnings'] = $warnings;
    }
    
    // Add recommendations for cleanup
    $recommendations = [];
    if ($deletionStrategy === 'reassign_null' && $postsCount > 0) {
        $recommendations[] = "Review uncategorized posts and assign them to appropriate categories.";
    }
    
    if (!empty($recommendations)) {
        $responseData['recommendations'] = $recommendations;
    }
    
    sendResponse($responseData, 200, "Category deleted successfully");
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    // Enhanced error logging
    $errorContext = [
        'category_id' => $categoryId,
        'deletion_strategy' => $deletionStrategy,
        'new_category_id' => $newCategoryId,
        'user_id' => $user['id'],
        'error_message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    error_log("Delete Category Error: " . json_encode($errorContext));
    
    // Send specific error messages
    $errorMessage = "Failed to delete category";
    $statusCode = 500;
    
    if (strpos($e->getMessage(), 'not found') !== false) {
        $statusCode = 404;
        $errorMessage = "Category not found";
    } elseif (strpos($e->getMessage(), 'reassignment') !== false) {
        $statusCode = 400;
        $errorMessage = "Invalid reassignment configuration";
    }
    
    sendError($errorMessage . ": " . $e->getMessage(), $statusCode);
}

?>