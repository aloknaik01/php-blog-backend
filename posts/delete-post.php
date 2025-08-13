<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';
require_once '../auth/authMiddleware.php';
require_once '../services/cloudinary.php';

// Only DELETE method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendError("Only DELETE method allowed", 405);
}

// Require authentication
$user = requireAuth();

// Get post ID from URL or request body with validation
$postId = null;
if (isset($_GET['id'])) {
    $postId = (int)$_GET['id'];
} else {
    $data = json_decode(file_get_contents("php://input"), true);
    if (isset($data['id'])) {
        $postId = (int)$data['id'];
    }
}

// Enhanced input validation
if (!$postId || $postId <= 0) {
    sendError("Valid Post ID is required", 400);
}

// Hard delete only (no soft delete option)
$softDelete = false;

// Start transaction for data consistency
$conn->begin_transaction();

try {
    // Get complete post details for cleanup and logging
    $stmt = $conn->prepare("
        SELECT 
            p.id, 
            p.title, 
            p.slug,
            p.featured_image, 
            p.author_id, 
            p.status,
            p.created_at,
            u.email as author_email,
            u.username as author_username,
            (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comments_count,
            (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id) as likes_count
        FROM posts p 
        JOIN users u ON p.author_id = u.id 
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    $post = $result->fetch_assoc();
    
    if (!$post) {
        $conn->rollback();
        sendError("Post not found or already deleted", 404);
    }
    
    $authorName = $post['author_username'] ?: explode('@', $post['author_email'])[0];
    
    // Check if user can modify this post
    if (!canModifyPost($postId, $user['id'], $user['role'])) {
        $conn->rollback();
        sendError("Access denied. You can only delete your own posts (unless you're an admin)", 403);
    }
    
    // Prepare cleanup data
    $cleanupResults = [
        'featured_image_deleted' => false,
        'comments_deleted' => 0,
        'likes_deleted' => 0,
        'cloudinary_cleanup' => false
    ];
    
    // Hard Delete Implementation
    
    // 1. File Management - Delete featured image from Cloudinary
    if (!empty($post['featured_image'])) {
        try {
            // Extract public_id from Cloudinary URL
            $publicId = extractCloudinaryPublicId($post['featured_image']);
            if ($publicId) {
                $cloudinary = getCloudinaryInstance();
                $cloudinary->uploadApi()->destroy($publicId);
                $cleanupResults['featured_image_deleted'] = true;
                $cleanupResults['cloudinary_cleanup'] = true;
            }
        } catch (Exception $e) {
            // Log error but don't fail the operation
            error_log("Cloudinary cleanup failed for post {$postId}: " . $e->getMessage());
        }
    }
    
    // 2. Delete related data (comments will be deleted by CASCADE)
    // Get counts before deletion for logging
    $cleanupResults['comments_deleted'] = $post['comments_count'];
    $cleanupResults['likes_deleted'] = $post['likes_count'];
    
    // 3. Delete the post (CASCADE will handle comments and likes)
    $deleteStmt = $conn->prepare("DELETE FROM posts WHERE id = ?");
    $deleteStmt->bind_param("i", $postId);
    
    if (!$deleteStmt->execute()) {
        throw new Exception("Failed to delete post from database");
    }
    
    $deleteType = 'hard';
    
    if ($deleteStmt->affected_rows === 0) {
        $conn->rollback();
        sendError("Post not found or already deleted", 404);
    }
    
    // 4. Audit Logging - Create audit log table if doesn't exist
    $conn->query("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(50) NOT NULL,
            table_name VARCHAR(50) NOT NULL,
            record_id INT NOT NULL,
            old_data JSON NULL,
            new_data JSON NULL,
            user_id INT NOT NULL,
            user_role VARCHAR(20) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            metadata JSON NULL,
            INDEX idx_action_table (action, table_name),
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        )
    ");
    
    // Insert audit log entry
    $auditStmt = $conn->prepare("
        INSERT INTO audit_logs 
        (action, table_name, record_id, old_data, new_data, user_id, user_role, ip_address, user_agent, metadata) 
        VALUES (?, 'posts', ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $action = 'HARD_DELETE';
    $oldData = json_encode($post);
    $newData = null; // Hard delete means no new data
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $metadata = json_encode([
        'delete_type' => $deleteType,
        'cleanup_results' => $cleanupResults,
        'post_stats' => [
            'comments_count' => $post['comments_count'],
            'likes_count' => $post['likes_count']
        ]
    ]);
    
    $auditStmt->bind_param(
        "sissiisss", 
        $action, $postId, $oldData, $newData, $user['id'], $user['role'], 
        $ipAddress, $userAgent, $metadata
    );
    
    if (!$auditStmt->execute()) {
        // Don't fail the operation for audit log failure, but log it
        error_log("Audit log insertion failed for post deletion: " . $auditStmt->error);
    }
    
    // Commit transaction
    $conn->commit();
    
    // 5. Enhanced Response
    sendResponse([
        'deleted_post' => [
            'id' => (int)$post['id'],
            'title' => $post['title'],
            'slug' => $post['slug'],
            'author_name' => $authorName,
            'author_id' => (int)$post['author_id'],
            'delete_type' => $deleteType,
            'had_featured_image' => !empty($post['featured_image'])
        ],
        'cleanup_summary' => $cleanupResults,
        'affected_records' => [
            'comments_affected' => (int)$cleanupResults['comments_deleted'],
            'likes_affected' => (int)$cleanupResults['likes_deleted']
        ],
        'deleted_by' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'role' => $user['role']
        ],
        'audit' => [
            'logged' => true,
            'action' => $action,
            'timestamp' => date('Y-m-d H:i:s')
        ],
        'recovery_info' => [
            'recoverable' => false,
            'message' => 'Hard delete - recovery not possible'
        ]
    ], 200, "Hard delete completed successfully");
    
} catch (Exception $e) {
    // Rollback transaction on any error
    $conn->rollback();
    
    // Enhanced Error Logging
    $errorContext = [
        'post_id' => $postId,
        'user_id' => $user['id'],
        'user_role' => $user['role'],
        'delete_type' => $deleteType ?? 'unknown',
        'error_message' => $e->getMessage(),
        'stack_trace' => $e->getTraceAsString(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    error_log("Delete Post API Error: " . json_encode($errorContext));
    
    // Send specific error based on error type
    $errorMessage = "Failed to delete post";
    $statusCode = 500;
    
    if (strpos($e->getMessage(), 'not found') !== false) {
        $statusCode = 404;
        $errorMessage = "Post not found";
    } elseif (strpos($e->getMessage(), 'Access denied') !== false) {
        $statusCode = 403;
        $errorMessage = "Access denied";
    } elseif (strpos($e->getMessage(), 'Cloudinary') !== false) {
        $errorMessage = "Post deleted but file cleanup failed";
        $statusCode = 206; // Partial success
    }
    
    sendError($errorMessage . ": " . $e->getMessage(), $statusCode);
}

/**
 * Extract Cloudinary public_id from URL
 * @param string $url - Cloudinary URL
 * @return string|null - Public ID
 */
function extractCloudinaryPublicId($url) {
    if (empty($url)) return null;
    
    // Extract public_id from Cloudinary URL
    // Format: https://res.cloudinary.com/cloud_name/image/upload/v1234567890/folder/public_id.jpg
    $pattern = '/\/([^\/]+)\.(?:jpg|jpeg|png|gif|webp)$/i';
    if (preg_match($pattern, $url, $matches)) {
        return $matches[1];
    }
    
    // For nested folders: folder/subfolder/public_id
    $urlParts = parse_url($url);
    if (isset($urlParts['path'])) {
        $pathParts = explode('/', trim($urlParts['path'], '/'));
        // Remove 'image', 'upload', version part
        $relevantParts = array_slice($pathParts, 4); // Skip cloud_name, image, upload, version
        $publicIdWithExt = end($relevantParts);
        return pathinfo($publicIdWithExt, PATHINFO_FILENAME);
    }
    
    return null;
}
?>