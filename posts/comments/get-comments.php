<?php
// posts/comments/get-comments.php
require_once '../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

require_once '../../db.php';
require_once '../../response.php';
require_once '../../auth/authMiddleware.php'; // ✅ auth added

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Only GET method allowed", 405);
}

// ✅ Require authentication (JWT via cookie)
$user = requireAuth(); 

// Get parameters
$postId = isset($_GET['post_id']) ? (int)$_GET['post_id'] : null;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;
$sort = isset($_GET['sort']) && in_array($_GET['sort'], ['newest', 'oldest', 'popular']) ? $_GET['sort'] : 'newest';

if (!$postId || $postId <= 0) {
    sendError("Valid post ID is required", 400);
}

$offset = ($page - 1) * $limit;

// Build ORDER BY clause
$orderClause = match($sort) {
    'oldest' => 'c.created_at ASC',
    'popular' => 'c.created_at DESC', // Can be improved with likes/replies count
    default => 'c.created_at DESC' // newest
};

try {
    // Verify post exists
    $postStmt = $conn->prepare("SELECT id, title, status FROM posts WHERE id = ?");
    $postStmt->bind_param("i", $postId);
    $postStmt->execute();
    $post = $postStmt->get_result()->fetch_assoc();
    
    if (!$post) {
        sendError("Post not found", 404);
    }
    
    // Get comments with pagination
    $commentsStmt = $conn->prepare("
        SELECT 
            c.id, 
            c.comment, 
            c.created_at, 
            c.updated_at,
            c.user_id,
            u.username, 
            u.email,
            u.role
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id = ?
        ORDER BY {$orderClause}
        LIMIT ? OFFSET ?
    ");
    
    $commentsStmt->bind_param("iii", $postId, $limit, $offset);
    $commentsStmt->execute();
    $result = $commentsStmt->get_result();
    
    $comments = [];
    while ($row = $result->fetch_assoc()) {
        $authorName = !empty($row['username']) ? $row['username'] : explode('@', $row['email'])[0];
        
        $comments[] = [
            'id' => (int)$row['id'],
            'comment' => $row['comment'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'author' => [
                'id' => (int)$row['user_id'],
                'name' => $authorName,
                'username' => $row['username'],
                'email' => $row['email'],
                'role' => $row['role']
            ],
            'is_edited' => $row['updated_at'] !== $row['created_at'],
            'time_ago' => timeAgo($row['created_at'])
        ];
    }
    
    // Get total count for pagination
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM comments WHERE post_id = ?");
    $countStmt->bind_param("i", $postId);
    $countStmt->execute();
    $totalCount = (int)$countStmt->get_result()->fetch_assoc()['total'];
    
    // Calculate pagination info
    $totalPages = ceil($totalCount / $limit);
    $hasNextPage = $page < $totalPages;
    $hasPrevPage = $page > 1;
    
    // Get comment statistics
    $statsStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_comments,
            COUNT(DISTINCT user_id) as unique_commenters,
            MIN(created_at) as first_comment_date,
            MAX(created_at) as latest_comment_date
        FROM comments 
        WHERE post_id = ?
    ");
    $statsStmt->bind_param("i", $postId);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    
    // ✅ Final response with success true
    $responseData = [
        'success' => true,
        'comments' => $comments,
        'post_info' => [
            'id' => (int)$post['id'],
            'title' => $post['title'],
            'status' => $post['status']
        ],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => $totalPages,
            'has_next_page' => $hasNextPage,
            'has_prev_page' => $hasPrevPage,
            'showing' => count($comments)
        ],
        'filters' => [
            'sort' => $sort
        ],
        'statistics' => [
            'total_comments' => (int)$stats['total_comments'],
            'unique_commenters' => (int)$stats['unique_commenters'],
            'first_comment_date' => $stats['first_comment_date'],
            'latest_comment_date' => $stats['latest_comment_date']
        ],
        'user' => $user // ✅ logged-in user info included
    ];
    
    // Add navigation URLs
    $baseUrl = "/posts/comments/get-comments.php?post_id={$postId}&sort={$sort}&limit={$limit}";
    
    if ($hasNextPage) {
        $responseData['pagination']['next_page_url'] = $baseUrl . "&page=" . ($page + 1);
    }
    
    if ($hasPrevPage) {
        $responseData['pagination']['prev_page_url'] = $baseUrl . "&page=" . ($page - 1);
    }
    
    sendResponse($responseData, 200, "Comments retrieved successfully");
    
} catch (Exception $e) {
    error_log("Get Comments Error: " . json_encode([
        'post_id' => $postId,
        'page' => $page,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]));
    
    sendError("Failed to retrieve comments: " . $e->getMessage(), 500);
}

// Helper function to calculate time ago
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . 'm ago';
    if ($time < 86400) return floor($time/3600) . 'h ago';
    if ($time < 2592000) return floor($time/86400) . 'd ago';
    if ($time < 31536000) return floor($time/2592000) . 'mo ago';
    return floor($time/31536000) . 'y ago';
}
?>
