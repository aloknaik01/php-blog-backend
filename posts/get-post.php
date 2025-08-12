<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';
require_once '../auth/authMiddleware.php';

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Only GET method allowed", 405);
}

// Require authentication (any logged-in user can view posts)
$user = requireAuth();

// Get post ID from URL parameter
$postId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$includeComments = isset($_GET['include_comments']) && $_GET['include_comments'] === 'true';
$includeStats = isset($_GET['include_stats']) && $_GET['include_stats'] === 'true';

if (!$postId) {
    sendError("Post ID is required", 400);
}

try {
    // Get post with comprehensive information
    $stmt = $conn->prepare("
        SELECT 
            p.id, p.title, p.slug, p.content, p.featured_image, 
            p.views, p.status, p.created_at, p.updated_at, p.author_id, p.category_id,
            u.username, u.email as author_email, u.role as author_role,
            c.name as category_name, c.slug as category_slug,
            COUNT(DISTINCT cm.id) as comment_count,
            COUNT(DISTINCT pl.id) as like_count
        FROM posts p 
        LEFT JOIN users u ON p.author_id = u.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN comments cm ON p.id = cm.post_id
        LEFT JOIN post_likes pl ON p.id = pl.post_id
        WHERE p.id = ?
        GROUP BY p.id
    ");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    $post = $result->fetch_assoc();

    if (!$post) {
        sendError("Post not found", 404);
    }

    // Check if user can view this post (if it's draft, only author/admin can view)
    if ($post['status'] === 'draft' && !canModifyPost($post['id'], $user['id'], $user['role'])) {
        sendError("Access denied - Post is not published", 403);
    }

    // Update view count (only if not the author viewing their own post)
    if ($post['author_id'] != $user['id']) {
        $updateViews = $conn->prepare("UPDATE posts SET views = views + 1 WHERE id = ?");
        $updateViews->bind_param("i", $postId);
        $updateViews->execute();
        $post['views']++; // Update local variable
    }

    // Check if current user liked this post
    $likeStmt = $conn->prepare("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?");
    $likeStmt->bind_param("ii", $postId, $user['id']);
    $likeStmt->execute();
    $isLiked = $likeStmt->get_result()->num_rows > 0;

    $authorName = !empty($post['username']) ? $post['username'] : explode('@', $post['author_email'])[0];

    // Build response
    $response = [
        'post' => [
            'id' => (int)$post['id'],
            'title' => $post['title'],
            'slug' => $post['slug'],
            'content' => $post['content'],
            'featured_image' => $post['featured_image'],
            'status' => $post['status'],
            'views' => (int)$post['views'],
            'created_at' => $post['created_at'],
            'updated_at' => $post['updated_at'],
            'reading_time' => calculateReadingTime($post['content']),
            'author' => [
                'id' => (int)$post['author_id'],
                'name' => $authorName,
                'username' => $post['username'],
                'email' => $post['author_email'],
                'role' => $post['author_role']
            ],
            'category' => $post['category_name'] ? [
                'id' => (int)$post['category_id'],
                'name' => $post['category_name'],
                'slug' => $post['category_slug']
            ] : null,
            'engagement' => [
                'comment_count' => (int)$post['comment_count'],
                'like_count' => (int)$post['like_count'],
                'is_liked' => $isLiked
            ],
            'permissions' => [
                'can_edit' => canModifyPost($post['id'], $user['id'], $user['role']),
                'can_delete' => canModifyPost($post['id'], $user['id'], $user['role']),
                'can_comment' => true, // All authenticated users can comment
                'can_like' => true     // All authenticated users can like
            ]
        ],
        'viewer' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'role' => $user['role']
        ]
    ];

    // Include comments if requested
    if ($includeComments) {
        $commentsStmt = $conn->prepare("
            SELECT 
                cm.id, cm.comment, cm.created_at, cm.updated_at,
                u.id as user_id, u.username, u.email
            FROM comments cm
            JOIN users u ON cm.user_id = u.id
            WHERE cm.post_id = ?
            ORDER BY cm.created_at ASC
        ");
        $commentsStmt->bind_param("i", $postId);
        $commentsStmt->execute();
        $commentsResult = $commentsStmt->get_result();
        
        $comments = [];
        while ($comment = $commentsResult->fetch_assoc()) {
            $commentAuthor = !empty($comment['username']) ? $comment['username'] : explode('@', $comment['email'])[0];
            $comments[] = [
                'id' => (int)$comment['id'],
                'comment' => $comment['comment'],
                'created_at' => $comment['created_at'],
                'updated_at' => $comment['updated_at'],
                'author' => [
                    'id' => (int)$comment['user_id'],
                    'name' => $commentAuthor,
                    'username' => $comment['username']
                ],
                'can_edit' => ($comment['user_id'] == $user['id'] || $user['role'] === 'admin'),
                'can_delete' => ($comment['user_id'] == $user['id'] || $user['role'] === 'admin')
            ];
        }
        $response['comments'] = $comments;
    }

    // Include additional stats if requested
    if ($includeStats) {
        // Get related posts by same author
        $relatedStmt = $conn->prepare("
            SELECT id, title, slug, created_at 
            FROM posts 
            WHERE author_id = ? AND id != ? AND status = 'published'
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $relatedStmt->bind_param("ii", $post['author_id'], $postId);
        $relatedStmt->execute();
        $relatedResult = $relatedStmt->get_result();
        
        $relatedPosts = [];
        while ($related = $relatedResult->fetch_assoc()) {
            $relatedPosts[] = [
                'id' => (int)$related['id'],
                'title' => $related['title'],
                'slug' => $related['slug'],
                'created_at' => $related['created_at']
            ];
        }

        $response['stats'] = [
            'total_author_posts' => getTotalAuthorPosts($conn, $post['author_id']),
            'author_total_views' => getAuthorTotalViews($conn, $post['author_id']),
            'related_posts' => $relatedPosts,
            'post_rank' => getPostRank($conn, $postId, $post['views'])
        ];
    }

    sendResponse($response, 200, "Post retrieved successfully");

} catch (Exception $e) {
    error_log("Get Post Error: " . $e->getMessage());
    sendError("Failed to retrieve post: " . $e->getMessage(), 500);
}

// Helper Functions
function calculateReadingTime($content) {
    $wordCount = str_word_count(strip_tags($content));
    $readingTimeMinutes = ceil($wordCount / 200); // Average 200 words per minute
    return $readingTimeMinutes . " min read";
}

function getTotalAuthorPosts($conn, $authorId) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM posts WHERE author_id = ? AND status = 'published'");
    $stmt->bind_param("i", $authorId);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['total'];
}

function getAuthorTotalViews($conn, $authorId) {
    $stmt = $conn->prepare("SELECT SUM(views) as total_views FROM posts WHERE author_id = ?");
    $stmt->bind_param("i", $authorId);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['total_views'] ?? 0);
}

function getPostRank($conn, $postId, $views) {
    $stmt = $conn->prepare("SELECT COUNT(*) + 1 as rank FROM posts WHERE views > ? AND status = 'published'");
    $stmt->bind_param("i", $views);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['rank'];
}
?>