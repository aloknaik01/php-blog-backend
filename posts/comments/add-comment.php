<?php
// posts/comments/add-comment.php
require_once '../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

require_once '../../db.php';
require_once '../../response.php';
require_once '../../auth/authMiddleware.php';

// Only POST method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Only POST method allowed", 405);
}

// Require authentication
$user = requireAuth();

// Get input data
$data = json_decode(file_get_contents("php://input"), true);

$postId = isset($data['post_id']) ? (int)$data['post_id'] : null;
$comment = isset($data['comment']) ? trim($data['comment']) : '';

// Validation
if (!$postId || $postId <= 0) {
    sendError("Valid post ID is required", 400);
}

if (empty($comment)) {
    sendError("Comment cannot be empty", 400);
}

if (strlen($comment) > 1000) {
    sendError("Comment must not exceed 1000 characters", 400);
}

// Check for spam/profanity (basic implementation)
$bannedWords = ['spam', 'viagra', 'casino']; // Add more as needed
foreach ($bannedWords as $word) {
    if (stripos($comment, $word) !== false) {
        sendError("Comment contains inappropriate content", 400);
    }
}

// Start transaction
$conn->begin_transaction();

try {
    // Verify post exists and is published
    $postStmt = $conn->prepare("
        SELECT id, title, author_id, status 
        FROM posts 
        WHERE id = ? AND status IN ('published', 'draft')
    ");
    $postStmt->bind_param("i", $postId);
    $postStmt->execute();
    $post = $postStmt->get_result()->fetch_assoc();
    
    if (!$post) {
        $conn->rollback();
        sendError("Post not found or not available for comments", 404);
    }
    
    // Check if comments are allowed (only on published posts)
    if ($post['status'] !== 'published') {
        $conn->rollback();
        sendError("Comments are only allowed on published posts", 403);
    }
    
    // Check for duplicate comment (prevent spam)
    $duplicateStmt = $conn->prepare("
        SELECT id FROM comments 
        WHERE post_id = ? AND user_id = ? AND comment = ? 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $duplicateStmt->bind_param("iis", $postId, $user['id'], $comment);
    $duplicateStmt->execute();
    
    if ($duplicateStmt->get_result()->num_rows > 0) {
        $conn->rollback();
        sendError("Duplicate comment detected. Please wait before posting again", 409);
    }
    
    // Insert comment
    $insertStmt = $conn->prepare("
        INSERT INTO comments (post_id, user_id, comment, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $insertStmt->bind_param("iis", $postId, $user['id'], $comment);
    
    if (!$insertStmt->execute()) {
        throw new Exception("Failed to insert comment");
    }
    
    $commentId = $conn->insert_id;
    
    // Get the inserted comment with user details
    $getStmt = $conn->prepare("
        SELECT 
            c.id, c.comment, c.created_at, c.updated_at,
            u.id as user_id, u.username, u.email,
            p.title as post_title
        FROM comments c
        JOIN users u ON c.user_id = u.id
        JOIN posts p ON c.post_id = p.id
        WHERE c.id = ?
    ");
    $getStmt->bind_param("i", $commentId);
    $getStmt->execute();
    $commentData = $getStmt->get_result()->fetch_assoc();
    
    // Commit transaction
    $conn->commit();
    
    $authorName = !empty($commentData['username']) ? $commentData['username'] : explode('@', $commentData['email'])[0];
    
    // Prepare response
    sendResponse([
        'comment' => [
            'id' => (int)$commentData['id'],
            'comment' => $commentData['comment'],
            'created_at' => $commentData['created_at'],
            'updated_at' => $commentData['updated_at'],
            'author' => [
                'id' => (int)$commentData['user_id'],
                'name' => $authorName,
                'username' => $commentData['username'],
                'email' => $commentData['email']
            ],
            'post' => [
                'id' => $postId,
                'title' => $commentData['post_title']
            ],
            'permissions' => [
                'can_edit' => true,
                'can_delete' => true
            ]
        ],
        'stats' => [
            'total_comments' => getTotalComments($conn, $postId),
            'user_comments_on_post' => getUserCommentsCount($conn, $postId, $user['id'])
        ]
    ], 201, "Comment added successfully");
    
} catch (Exception $e) {
    $conn->rollback();
    
    // Log error
    error_log("Add Comment Error: " . json_encode([
        'post_id' => $postId,
        'user_id' => $user['id'],
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]));
    
    sendError("Failed to add comment: " . $e->getMessage(), 500);
}

// Helper Functions
function getTotalComments($conn, $postId) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM comments WHERE post_id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['total'];
}

function getUserCommentsCount($conn, $postId, $userId) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM comments WHERE post_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $postId, $userId);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['total'];
}
?>