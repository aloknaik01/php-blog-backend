<?php
// posts/comments/update-comment.php
require_once '../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

require_once '../../db.php';
require_once '../../response.php';
require_once '../../auth/authMiddleware.php';

// Only PUT/PATCH methods allowed
if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'])) {
    sendError("Only PUT or PATCH method allowed", 405);
}

// Require authentication
$user = requireAuth();

// Get input data
$data = json_decode(file_get_contents("php://input"), true);

$commentId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($data['id']) ? (int)$data['id'] : null);
$newComment = isset($data['comment']) ? trim($data['comment']) : '';

// Validation
if (!$commentId || $commentId <= 0) {
    sendError("Valid comment ID is required", 400);
}

if (empty($newComment)) {
    sendError("Comment cannot be empty", 400);
}

if (strlen($newComment) > 1000) {
    sendError("Comment must not exceed 1000 characters", 400);
}

// Check for profanity (basic implementation)
$bannedWords = ['spam', 'viagra', 'casino'];
foreach ($bannedWords as $word) {
    if (stripos($newComment, $word) !== false) {
        sendError("Comment contains inappropriate content", 400);
    }
}

// Start transaction
$conn->begin_transaction();

try {
    // Get current comment details
    $stmt = $conn->prepare("
        SELECT 
            c.id, c.comment, c.user_id, c.post_id, c.created_at,
            u.username, u.email,
            p.title as post_title, p.author_id as post_author_id
        FROM comments c
        JOIN users u ON c.user_id = u.id
        JOIN posts p ON c.post_id = p.id
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $commentId);
    $stmt->execute();
    $comment = $stmt->get_result()->fetch_assoc();
    
    if (!$comment) {
        $conn->rollback();
        sendError("Comment not found", 404);
    }
    
    // Check permissions (user can edit own comment OR admin can edit any comment)
    if ($comment['user_id'] != $user['id'] && $user['role'] !== 'admin') {
        $conn->rollback();
        sendError("Access denied. You can only edit your own comments", 403);
    }
    
    // Check if comment is too old to edit (e.g., 24 hours)
    $commentAge = time() - strtotime($comment['created_at']);
    $editTimeLimit = 24 * 60 * 60; // 24 hours
    
    if ($commentAge > $editTimeLimit && $user['role'] !== 'admin') {
        $conn->rollback();
        sendError("Comment is too old to edit (24 hour limit)", 403);
    }
    
    // Check if comment is actually different
    if ($comment['comment'] === $newComment) {
        $conn->rollback();
        sendError("No changes detected", 400);
    }
    
    // Store original comment for audit (if needed)
    $originalComment = $comment['comment'];
    
    // Update comment
    $updateStmt = $conn->prepare("
        UPDATE comments 
        SET comment = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $updateStmt->bind_param("si", $newComment, $commentId);
    
    if (!$updateStmt->execute()) {
        throw new Exception("Failed to update comment");
    }
    
    if ($updateStmt->affected_rows === 0) {
        $conn->rollback();
        sendError("Failed to update comment", 500);
    }
    
    // Get updated comment details
    $getStmt = $conn->prepare("
        SELECT 
            c.id, c.comment, c.created_at, c.updated_at, c.user_id, c.post_id,
            u.username, u.email,
            p.title as post_title
        FROM comments c
        JOIN users u ON c.user_id = u.id
        JOIN posts p ON c.post_id = p.id
        WHERE c.id = ?
    ");
    $getStmt->bind_param("i", $commentId);
    $getStmt->execute();
    $updatedComment = $getStmt->get_result()->fetch_assoc();
    
    // Commit transaction
    $conn->commit();
    
    $authorName = !empty($updatedComment['username']) ? $updatedComment['username'] : explode('@', $updatedComment['email'])[0];
    
    // Log the edit activity
    error_log("Comment Edit: " . json_encode([
        'comment_id' => $commentId,
        'post_id' => $comment['post_id'],
        'edited_by_user_id' => $user['id'],
        'edited_by_role' => $user['role'],
        'original_length' => strlen($originalComment),
        'new_length' => strlen($newComment),
        'timestamp' => date('Y-m-d H:i:s')
    ]));
    
    // Prepare response
    sendResponse([
        'comment' => [
            'id' => (int)$updatedComment['id'],
            'comment' => $updatedComment['comment'],
            'created_at' => $updatedComment['created_at'],
            'updated_at' => $updatedComment['updated_at'],
            'author' => [
                'id' => (int)$updatedComment['user_id'],
                'name' => $authorName,
                'username' => $updatedComment['username'],
                'email' => $updatedComment['email']
            ],
            'post' => [
                'id' => (int)$updatedComment['post_id'],
                'title' => $updatedComment['post_title']
            ],
            'is_edited' => true,
            'permissions' => [
                'can_edit' => ($updatedComment['user_id'] == $user['id'] || $user['role'] === 'admin'),
                'can_delete' => ($updatedComment['user_id'] == $user['id'] || $user['role'] === 'admin')
            ]
        ],
        'edit_info' => [
            'edited_by' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'role' => $user['role']
            ],
            'edit_timestamp' => $updatedComment['updated_at'],
            'original_author' => $comment['user_id'] == $user['id'] ? 'self' : 'admin_edit'
        ],
        'changes' => [
            'character_difference' => strlen($newComment) - strlen($originalComment),
            'content_similarity' => calculateSimilarity($originalComment, $newComment)
        ]
    ], 200, "Comment updated successfully");
    
} catch (Exception $e) {
    $conn->rollback();
    
    // Log error
    error_log("Update Comment Error: " . json_encode([
        'comment_id' => $commentId,
        'user_id' => $user['id'],
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]));
    
    sendError("Failed to update comment: " . $e->getMessage(), 500);
}

// Helper function to calculate similarity percentage
function calculateSimilarity($str1, $str2) {
    $similarity = 0;
    similar_text($str1, $str2, $similarity);
    return round($similarity, 2);
}
?>