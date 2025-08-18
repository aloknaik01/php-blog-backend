<?php
// posts/likes/like-post.php
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

// ✅ Get post_id from query parameter (?id=35)
$postId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$postId || $postId <= 0) {
    sendError("Valid post ID is required in query param (?id=35)", 400);
}

// Config: allow/disallow self-like (default: disallow)
$allowSelfLike = filter_var($_ENV['ALLOW_SELF_LIKE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

// Start transaction
$conn->begin_transaction();

try {
    // Verify post exists
    $postStmt = $conn->prepare("
        SELECT id, title, author_id, status 
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

    // Check if post is likeable
    if ($post['status'] !== 'published' && 
        $post['author_id'] !== $user['id'] && 
        $user['role'] !== 'admin') {
        $conn->rollback();
        sendError("This post is not available for likes", 403);
    }

    // Self-like restriction
    if (!$allowSelfLike && $post['author_id'] == $user['id']) {
        $conn->rollback();
        sendError("You cannot like your own post", 403);
    }

    // Insert like (UNIQUE constraint will handle duplicates)
    $insertStmt = $conn->prepare("
        INSERT INTO post_likes (post_id, user_id) 
        VALUES (?, ?)
    ");
    $insertStmt->bind_param("ii", $postId, $user['id']);

    if (!$insertStmt->execute()) {
        $err = $insertStmt->error ?? '';
        if (stripos($err, 'duplicate') !== false) {
            $conn->rollback();
            sendError("You have already liked this post", 409);
        }
        throw new Exception($err ?: "Failed to add like");
    }

    $likeId = $conn->insert_id;

    // Get created_at of this like
    $thisLikeTimeStmt = $conn->prepare("SELECT created_at FROM post_likes WHERE id = ?");
    $thisLikeTimeStmt->bind_param("i", $likeId);
    $thisLikeTimeStmt->execute();
    $thisLikeRow = $thisLikeTimeStmt->get_result()->fetch_assoc();
    $thisLikeCreatedAt = $thisLikeRow['created_at'];

    // Like count
    $statsStmt = $conn->prepare("SELECT COUNT(*) as total_likes FROM post_likes WHERE post_id = ?");
    $statsStmt->bind_param("i", $postId);
    $statsStmt->execute();
    $totalLikes = (int)$statsStmt->get_result()->fetch_assoc()['total_likes'];

    // Rank of this like
    $rankStmt = $conn->prepare("
        SELECT COUNT(*) AS like_rank
        FROM post_likes
        WHERE post_id = ?
          AND created_at <= ?
    ");
    $rankStmt->bind_param("is", $postId, $thisLikeCreatedAt);
    $rankStmt->execute();
    $likeRank = (int)$rankStmt->get_result()->fetch_assoc()['like_rank'];

    // Recent likes
    $recentLikesStmt = $conn->prepare("
        SELECT pl.created_at, u.id as user_id, u.username, u.email
        FROM post_likes pl
        JOIN users u ON pl.user_id = u.id
        WHERE pl.post_id = ?
        ORDER BY pl.created_at DESC
        LIMIT 5
    ");
    $recentLikesStmt->bind_param("i", $postId);
    $recentLikesStmt->execute();
    $recentLikesResult = $recentLikesStmt->get_result();

    $recentLikes = [];
    while ($row = $recentLikesResult->fetch_assoc()) {
        $likerName = !empty($row['username']) ? $row['username'] : explode('@', $row['email'])[0];
        $recentLikes[] = [
            'user_id' => (int)$row['user_id'],
            'name' => $likerName,
            'username' => $row['username'],
            'liked_at' => $row['created_at']
        ];
    }

    // Commit transaction
    $conn->commit();

    // Send notification (best effort)
    if ($post['author_id'] !== $user['id']) {
        try {
            createLikeNotification($conn, $post['author_id'], $user['id'], $postId, $user['name']);
        } catch (Exception $ne) {
            error_log("Create like notification failed: " . $ne->getMessage());
        }
    }

    // Response
    sendResponse([
        'like' => [
            'id' => $likeId,
            'post_id' => $postId,
            'user_id' => $user['id'],
            'created_at' => $thisLikeCreatedAt
        ],
        'post' => [
            'id' => $postId,
            'title' => $post['title'],
            'author_id' => (int)$post['author_id']
        ],
        'liker' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'role' => $user['role']
        ],
        'stats' => [
            'total_likes' => $totalLikes,
            'user_liked' => true,
            'like_rank' => $likeRank
        ],
        'recent_likes' => $recentLikes,
        'actions' => [
            'can_unlike' => true,
            'notification_sent' => ($post['author_id'] !== $user['id'])
        ]
    ], 201, "Post liked successfully");

} catch (Exception $e) {
    $conn->rollback();
    $msg = $e->getMessage() ?? '';
    if (stripos($msg, 'duplicate') !== false) {
        sendError("You have already liked this post", 409);
    }

    error_log("Like Post Error: " . json_encode([
        'post_id' => $postId,
        'user_id' => $user['id'],
        'error' => $msg,
        'timestamp' => date('Y-m-d H:i:s')
    ]));

    sendError("Failed to like post", 500);
}

// Notification helper
function createLikeNotification($conn, $authorId, $likerId, $postId, $likerName) {
    $stmt = $conn->prepare("
        INSERT INTO notifications 
        (user_id, type, title, message, related_id, related_type) 
        VALUES (?, 'post_like', ?, ?, ?, 'post')
    ");
    $title = "New like on your post";
    $message = "{$likerName} liked your post";
    $stmt->bind_param("issi", $authorId, $title, $message, $postId);
    if (!$stmt->execute()) {
        throw new Exception($stmt->error ?: "Notification insert failed");
    }
    return true;
}
