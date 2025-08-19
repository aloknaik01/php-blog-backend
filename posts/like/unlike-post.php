<?php
// posts/likes/unlike-post.php
require_once '../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

require_once '../../db.php';
require_once '../../response.php';
require_once '../../auth/authMiddleware.php';

// Only DELETE method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendError("Only DELETE method allowed", 405);
}

// Require authentication
$user = requireAuth();

// Get post ID from query parameters
$postId = isset($_GET['post_id']) ? (int)$_GET['post_id'] : null;

// Validation
if (!$postId || $postId <= 0) {
    sendError("Valid post ID is required", 400);
}

// Start transaction
$conn->begin_transaction();

try {
    // Verify post exists
    $postStmt = $conn->prepare("
        SELECT id 
        FROM posts 
        WHERE id = ?
    ");
    $postStmt->bind_param("i", $postId);
    $postStmt->execute();
    $post = $postStmt->get_result()->fetch_assoc();
    
    if (!$post) {
        $conn->rollback();
        sendError("Post not found", 404);
    }
    
    // Check if user has liked this post
    $likeStmt = $conn->prepare("
        SELECT id 
        FROM post_likes 
        WHERE post_id = ? AND user_id = ?
    ");
    $likeStmt->bind_param("ii", $postId, $user['id']);
    $likeStmt->execute();
    $like = $likeStmt->get_result()->fetch_assoc();
    
    if (!$like) {
        $conn->rollback();
        sendError("You have not liked this post", 404);
    }
    
    // Remove the like
    $deleteStmt = $conn->prepare("
        DELETE FROM post_likes 
        WHERE post_id = ? AND user_id = ?
    ");
    $deleteStmt->bind_param("ii", $postId, $user['id']);
    
    if (!$deleteStmt->execute()) {
        throw new Exception("Failed to remove like");
    }
    
    if ($deleteStmt->affected_rows === 0) {
        $conn->rollback();
        sendError("Like not found or already removed", 404);
    }
    
    // Get updated like count
    $statsStmt = $conn->prepare("
        SELECT COUNT(*) as total_likes
        FROM post_likes 
        WHERE post_id = ?
    ");
    $statsStmt->bind_param("i", $postId);
    $statsStmt->execute();
    $totalLikes = (int)$statsStmt->get_result()->fetch_assoc()['total_likes'];
    
    // Commit transaction
    $conn->commit();
    
    // Simple response
    sendResponse([
        'post_id' => $postId,
        'total_likes' => $totalLikes,
        'user_liked' => false
    ], 200, "Post unliked successfully");
    
} catch (Exception $e) {
    $conn->rollback();
    
    // Log error
    error_log("Unlike Post Error: " . json_encode([
        'post_id' => $postId,
        'user_id' => $user['id'],
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]));
    
    sendError("Failed to unlike post: " . $e->getMessage(), 500);
}
?>