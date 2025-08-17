<?php
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

// Get comment ID from URL or request body
$commentId = null;
if (isset($_GET['id'])) {
    $commentId = (int) $_GET['id'];
} else {
    $data = json_decode(file_get_contents("php://input"), true);
    if (isset($data['id'])) {
        $commentId = (int) $data['id'];
    }
}

if (!$commentId || $commentId <= 0) {
    sendError("Valid comment ID is required", 400);
}

// Get deletion type (soft or hard delete)
$deleteType = $_GET['type'] ?? 'soft'; // soft, hard
$reason = $_GET['reason'] ?? 'user_request'; // user_request, admin_moderation, spam, inappropriate

// Start transaction
$conn->begin_transaction();

try {
    // Get comment details for permission check and logging
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

    // Permission check
    $canDelete = false;
    $deleteReason = '';

    if ($user['role'] === 'admin') {
        $canDelete = true;
        $deleteReason = 'admin_action';
    } elseif ($comment['user_id'] == $user['id']) {
        $canDelete = true;
        $deleteReason = 'author_delete';
    } elseif ($comment['post_author_id'] == $user['id']) {
        // Post author can delete comments on their posts
        $canDelete = true;
        $deleteReason = 'post_author_delete';
    }

    if (!$canDelete) {
        $conn->rollback();
        sendError("Access denied. You can only delete your own comments or comments on your posts", 403);
    }

    $authorName = !empty($comment['username']) ? $comment['username'] : explode('@', $comment['email'])[0];

    // Perform deletion based on type
    if ($deleteType === 'hard') {
        // Hard delete - completely remove from database
        $deleteStmt = $conn->prepare("DELETE FROM comments WHERE id = ?");
        $deleteStmt->bind_param("i", $commentId);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete comment");
        }

        if ($deleteStmt->affected_rows === 0) {
            $conn->rollback();
            sendError("Comment not found or already deleted", 404);
        }

        $deletionResult = [
            'type' => 'hard_delete',
            'recoverable' => false,
            'message' => 'Comment permanently deleted'
        ];

    } else {
        // Soft delete - mark as deleted but keep in database
        // First, add deleted_at column if it doesn't exist
        $conn->query("
            ALTER TABLE comments 
            ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL,
            ADD COLUMN IF NOT EXISTS deleted_by INT NULL,
            ADD COLUMN IF NOT EXISTS deletion_reason VARCHAR(100) NULL
        ");

        $softDeleteStmt = $conn->prepare("
            UPDATE comments 
            SET deleted_at = NOW(), deleted_by = ?, deletion_reason = ? 
            WHERE id = ?
        ");
        $softDeleteStmt->bind_param("isi", $user['id'], $reason, $commentId);

        if (!$softDeleteStmt->execute()) {
            throw new Exception("Failed to mark comment as deleted");
        }

        if ($softDeleteStmt->affected_rows === 0) {
            $conn->rollback();
            sendError("Comment not found or already deleted", 404);
        }

        $deletionResult = [
            'type' => 'soft_delete',
            'recoverable' => true,
            'message' => 'Comment marked as deleted (recoverable by admin)',
            'recovery_deadline' => date('Y-m-d H:i:s', strtotime('+30 days'))
        ];
    }

    // Create audit log entry
    $conn->query("
        CREATE TABLE IF NOT EXISTS comment_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            comment_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            original_comment TEXT NULL,
            post_id INT NOT NULL,
            comment_author_id INT NOT NULL,
            action_by_user_id INT NOT NULL,
            action_by_role VARCHAR(20) NOT NULL,
            reason VARCHAR(100) NULL,
            deletion_type VARCHAR(20) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            metadata JSON NULL,
            INDEX idx_comment_id (comment_id),
            INDEX idx_action_by (action_by_user_id),
            INDEX idx_created_at (created_at)
        )
    ");

    $auditStmt = $conn->prepare("
        INSERT INTO comment_audit_logs 
        (comment_id, action, original_comment, post_id, comment_author_id, 
         action_by_user_id, action_by_role, reason, deletion_type, ip_address, user_agent, metadata) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $action = $deleteType === 'hard' ? 'HARD_DELETE' : 'SOFT_DELETE';
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $metadata = json_encode([
        'delete_reason' => $deleteReason,
        'comment_length' => strlen($comment['comment']),
        'comment_age_hours' => round((time() - strtotime($comment['created_at'])) / 3600, 2)
    ]);

    $auditStmt->bind_param(
        "issiisssisss",
        $commentId,
        $action,
        $comment['comment'],
        $comment['post_id'],
        $comment['user_id'],
        $user['id'],
        $user['role'],
        $reason,
        $deleteType,
        $ipAddress,
        $userAgent,
        $metadata
    );

    if (!$auditStmt->execute()) {
        // Don't fail the operation for audit log failure, but log it
        error_log("Comment deletion audit log failed: " . $auditStmt->error);
    }

    // Update comment count cache in posts table if needed
    $updatePostStmt = $conn->prepare("
        UPDATE posts 
        SET updated_at = NOW() 
        WHERE id = ?
    ");
    $updatePostStmt->bind_param("i", $comment['post_id']);
    $updatePostStmt->execute();

    // Commit transaction
    $conn->commit();

    // Get updated comment count for the post
    $countStmt = $conn->prepare("
        SELECT COUNT(*) as total_comments 
        FROM comments 
        WHERE post_id = ? AND deleted_at IS NULL
    ");
    $countStmt->bind_param("i", $comment['post_id']);
    $countStmt->execute();
    $remainingComments = (int) $countStmt->get_result()->fetch_assoc()['total_comments'];

    // Prepare response
    sendResponse([
        'deleted_comment' => [
            'id' => (int) $comment['id'],
            'original_author' => [
                'id' => (int) $comment['user_id'],
                'name' => $authorName
            ],
            'post' => [
                'id' => (int) $comment['post_id'],
                'title' => $comment['post_title'],
                'remaining_comments' => $remainingComments
            ],
            'deletion_details' => $deletionResult
        ],
        'deleted_by' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'role' => $user['role'],
            'reason' => $deleteReason
        ],
        'audit' => [
            'logged' => true,
            'action' => $action,
            'timestamp' => date('Y-m-d H:i:s')
        ],
        'post_stats' => [
            'remaining_comments' => $remainingComments,
            'comment_removed' => true
        ]
    ], 200, "Comment deleted successfully");

} catch (Exception $e) {
    $conn->rollback();

    // Log error
    error_log("Delete Comment Error: " . json_encode([
        'comment_id' => $commentId,
        'user_id' => $user['id'],
        'delete_type' => $deleteType,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]));

    sendError("Failed to delete comment: " . $e->getMessage(), 500);
}
?>