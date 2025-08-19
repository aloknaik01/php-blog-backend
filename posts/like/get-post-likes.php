<?php
// posts/likes/get-post-likes.php
require_once '../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

require_once '../../db.php';
require_once '../../response.php';
require_once '../../auth/authMiddleware.php';

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Only GET method allowed", 405);
}

// Optional authentication (for sensitive operations)
$user = null;
try {
    $user = requireAuth();
} catch (Exception $e) {
    // Continue without authentication for public data
    $user = null;
}

// Rate limiting check
$userIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (rateLimitExceeded($userIP)) {
    sendError("Too many requests. Please try again later.", 429);
}

// Get and validate parameters
$postId = isset($_GET['post_id']) ? (int) $_GET['post_id'] : null;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = isset($_GET['limit']) ? min(50, max(1, (int) $_GET['limit'])) : 20; // Reduced max limit
$sort = isset($_GET['sort']) && in_array($_GET['sort'], ['newest', 'oldest']) ? $_GET['sort'] : 'newest';

if (!$postId || $postId <= 0) {
    sendError("Valid post ID is required", 400);
}

$offset = ($page - 1) * $limit;
$orderClause = $sort === 'oldest' ? 'pl.created_at ASC' : 'pl.created_at DESC';

try {
    // Single optimized query to get post info and check existence
    $postStmt = $conn->prepare("
        SELECT id, title, status
        FROM posts 
        WHERE id = ? AND status = 'published'
    ");
    $postStmt->bind_param("i", $postId);
    $postStmt->execute();
    $post = $postStmt->get_result()->fetch_assoc();

    if (!$post) {
        sendError("Post not found or not published", 404);
    }

    // Optimized single query for likes with user info
    $likesStmt = $conn->prepare("
        SELECT 
            pl.created_at as liked_at,
            u.id as user_id,
            u.username,
            u.email
        FROM post_likes pl
        JOIN users u ON pl.user_id = u.id
        WHERE pl.post_id = ?
        ORDER BY {$orderClause}
        LIMIT ? OFFSET ?
    ");

    $likesStmt->bind_param("iii", $postId, $limit, $offset);
    $likesStmt->execute();
    $result = $likesStmt->get_result();

    $likes = [];
    while ($row = $result->fetch_assoc()) {
        $likerName = !empty($row['username']) ? $row['username'] : explode('@', $row['email'])[0];

        $likes[] = [
            'user_id' => (int) $row['user_id'],
            'name' => $likerName,
            'username' => $row['username'],
            'liked_at' => $row['liked_at'],
            'time_ago' => timeAgo($row['liked_at'])
        ];
    }

    // Single query for total count
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM post_likes WHERE post_id = ?");
    $countStmt->bind_param("i", $postId);
    $countStmt->execute();
    $totalCount = (int) $countStmt->get_result()->fetch_assoc()['total'];

    // Calculate pagination info
    $totalPages = ceil($totalCount / $limit);
    $hasMore = $page < $totalPages;

    // Simple statistics (single query)
    $statsStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_likes,
            COUNT(DISTINCT user_id) as unique_likers,
            MAX(created_at) as latest_like_date
        FROM post_likes 
        WHERE post_id = ?
    ");
    $statsStmt->bind_param("i", $postId);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();

    // Check if current user liked this post (if authenticated)
    $currentUserLiked = false;
    if ($user) {
        $userLikeStmt = $conn->prepare("
            SELECT id FROM post_likes 
            WHERE post_id = ? AND user_id = ?
        ");
        $userLikeStmt->bind_param("ii", $postId, $user['id']);
        $userLikeStmt->execute();
        $currentUserLiked = $userLikeStmt->get_result()->num_rows > 0;
    }

    // Simplified response structure
    $responseData = [
        'total_likes' => (int) $stats['total_likes'],
        'unique_likers' => (int) $stats['unique_likers'],
        'latest_like_date' => $stats['latest_like_date'],
        'current_user_liked' => $currentUserLiked,
        'likes' => $likes,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => $totalPages,
            'has_more' => $hasMore,
            'showing' => count($likes)
        ],
        'post_info' => [
            'id' => (int) $post['id'],
            'title' => $post['title']
        ]
    ];

    sendResponse($responseData, 200, "Post likes retrieved successfully");

} catch (Exception $e) {
    error_log("Get Post Likes Error: " . json_encode([
        'post_id' => $postId,
        'page' => $page,
        'user_id' => $user ? $user['id'] : null,
        'ip' => $userIP,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]));

    sendError("Failed to retrieve post likes", 500);
}

// Helper Functions
function timeAgo($datetime)
{
    $time = time() - strtotime($datetime);

    if ($time < 60)
        return 'just now';
    if ($time < 3600)
        return floor($time / 60) . 'm ago';
    if ($time < 86400)
        return floor($time / 3600) . 'h ago';
    if ($time < 2592000)
        return floor($time / 86400) . 'd ago';
    if ($time < 31536000)
        return floor($time / 2592000) . 'mo ago';
    return floor($time / 31536000) . 'y ago';
}

function rateLimitExceeded($userIP)
{
    // Simple file-based rate limiting (100 requests per hour per IP)
    $rateLimitFile = sys_get_temp_dir() . '/rate_limit_' . md5($userIP) . '.txt';
    $currentTime = time();
    $hourAgo = $currentTime - 3600;

    // Clean up old entries and count current requests
    $requests = [];
    if (file_exists($rateLimitFile)) {
        $existingRequests = file($rateLimitFile, FILE_IGNORE_NEW_LINES);
        foreach ($existingRequests as $timestamp) {
            if ((int) $timestamp > $hourAgo) {
                $requests[] = $timestamp;
            }
        }
    }

    // Check if limit exceeded
    if (count($requests) >= 100) {
        return true;
    }

    // Add current request
    $requests[] = $currentTime;
    file_put_contents($rateLimitFile, implode("\n", $requests));

    return false;
}
?>