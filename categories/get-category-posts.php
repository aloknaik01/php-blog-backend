
<?php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Only GET method allowed", 405);
}

// Get category identifier (ID or slug)
$categoryIdentifier = $_GET['category'] ?? null;

if (!$categoryIdentifier) {
    sendError("Category ID or slug is required", 400);
}

// Pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
$offset = ($page - 1) * $limit;

// Filter parameters
$status = $_GET['status'] ?? 'published'; // published, draft, archived, all
$sort = $_GET['sort'] ?? 'created_at'; // created_at, title, views, updated_at
$order = $_GET['order'] ?? 'desc'; // asc, desc

// Validate parameters
$allowedStatuses = ['published', 'draft', 'archived', 'all'];
$allowedSorts = ['created_at', 'title', 'views', 'updated_at'];
$allowedOrders = ['asc', 'desc'];

if (!in_array($status, $allowedStatuses)) {
    sendError("Invalid status. Allowed: " . implode(', ', $allowedStatuses), 400);
}

if (!in_array($sort, $allowedSorts)) {
    sendError("Invalid sort field. Allowed: " . implode(', ', $allowedSorts), 400);
}

if (!in_array($order, $allowedOrders)) {
    sendError("Invalid order. Allowed: " . implode(', ', $allowedOrders), 400);
}

try {
    // First, get category information
    $categoryQuery = is_numeric($categoryIdentifier) 
        ? "SELECT * FROM categories WHERE id = ?"
        : "SELECT * FROM categories WHERE slug = ?";
    
    $categoryStmt = $conn->prepare($categoryQuery);
    
    if (is_numeric($categoryIdentifier)) {
        $categoryStmt->bind_param("i", $categoryIdentifier);
    } else {
        $categoryStmt->bind_param("s", $categoryIdentifier);
    }
    
    $categoryStmt->execute();
    $category = $categoryStmt->get_result()->fetch_assoc();
    
    if (!$category) {
        sendError("Category not found", 404);
    }
    
    $categoryId = $category['id'];
    
    // Build posts query
    $whereClause = "WHERE p.category_id = ?";
    $params = [$categoryId];
    $types = "i";
    
    // Add status filter
    if ($status !== 'all') {
        $whereClause .= " AND p.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    // Build ORDER BY clause
    $orderClause = "ORDER BY p.{$sort} " . strtoupper($order);
    
    // Main query to get posts
    $postsQuery = "
        SELECT 
            p.id,
            p.title,
            p.slug,
            p.content,
            p.featured_image,
            p.views,
            p.status,
            p.created_at,
            p.updated_at,
            u.id as author_id,
            u.username as author_username,
            u.email as author_email,
            c.name as category_name,
            c.slug as category_slug,
            (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comments_count,
            (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id) as likes_count
        FROM posts p
        JOIN users u ON p.author_id = u.id
        JOIN categories c ON p.category_id = c.id
        {$whereClause}
        {$orderClause}
        LIMIT ? OFFSET ?
    ";
    
    // Add pagination parameters
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $postsStmt = $conn->prepare($postsQuery);
    $postsStmt->bind_param($types, ...$params);
    $postsStmt->execute();
    $postsResult = $postsStmt->get_result();
    
    $posts = [];
    while ($row = $postsResult->fetch_assoc()) {
        $post = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'slug' => $row['slug'],
            'content' => substr($row['content'], 0, 200) . '...', // Truncated content
            'featured_image' => $row['featured_image'],
            'status' => $row['status'],
            'views' => (int)$row['views'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'author' => [
                'id' => (int)$row['author_id'],
                'username' => $row['author_username'],
                'email' => $row['author_email']
            ],
            'category' => [
                'id' => (int)$categoryId,
                'name' => $row['category_name'],
                'slug' => $row['category_slug']
            ],
            'stats' => [
                'comments_count' => (int)$row['comments_count'],
                'likes_count' => (int)$row['likes_count'],
                'views' => (int)$row['views']
            ]
        ];
        
        // Add reading time estimate (assuming 200 words per minute)
        $wordCount = str_word_count(strip_tags($row['content']));
        $post['reading_time_minutes'] = max(1, ceil($wordCount / 200));
        
        $posts[] = $post;
    }
    
    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(*) as total 
        FROM posts p 
        WHERE p.category_id = ?" . ($status !== 'all' ? " AND p.status = ?" : "");
    
    $countStmt = $conn->prepare($countQuery);
    
    if ($status !== 'all') {
        $countStmt->bind_param("is", $categoryId, $status);
    } else {
        $countStmt->bind_param("i", $categoryId);
    }
    
    $countStmt->execute();
    $totalCount = $countStmt->get_result()->fetch_assoc()['total'];
    
    // Calculate pagination info
    $totalPages = ceil($totalCount / $limit);
    $hasNextPage = $page < $totalPages;
    $hasPrevPage = $page > 1;
    
    // Get category statistics
    $statsQuery = "
        SELECT 
            COUNT(*) as total_posts,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published_posts,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_posts,
            SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived_posts,
            SUM(views) as total_views,
            AVG(views) as avg_views_per_post,
            MAX(created_at) as latest_post_date,
            MIN(created_at) as oldest_post_date
        FROM posts 
        WHERE category_id = ?
    ";
    
    $statsStmt = $conn->prepare($statsQuery);
    $statsStmt->bind_param("i", $categoryId);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    
    // Response data
    $responseData = [
        'category' => [
            'id' => (int)$category['id'],
            'name' => $category['name'],
            'slug' => $category['slug'],
            'description' => $category['description'],
            'created_at' => $category['created_at'],
            'updated_at' => $category['updated_at']
        ],
        'posts' => $posts,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => (int)$totalCount,
            'total_pages' => $totalPages,
            'has_next_page' => $hasNextPage,
            'has_prev_page' => $hasPrevPage,
            'showing' => count($posts)
        ],
        'filters' => [
            'status' => $status,
            'sort' => $sort,
            'order' => $order
        ],
        'category_stats' => [
            'total_posts' => (int)$stats['total_posts'],
            'published_posts' => (int)$stats['published_posts'],
            'draft_posts' => (int)$stats['draft_posts'],
            'archived_posts' => (int)$stats['archived_posts'],
            'total_views' => (int)$stats['total_views'],
            'avg_views_per_post' => round((float)$stats['avg_views_per_post'], 1),
            'latest_post_date' => $stats['latest_post_date'],
            'oldest_post_date' => $stats['oldest_post_date']
        ]
    ];
    
    // Add navigation URLs
    $baseUrl = "/categories/get-category-posts.php?category=" . urlencode($categoryIdentifier);
    $baseUrl .= "&status={$status}&sort={$sort}&order={$order}&limit={$limit}";
    
    if ($hasNextPage) {
        $responseData['pagination']['next_page_url'] = $baseUrl . "&page=" . ($page + 1);
    }
    
    if ($hasPrevPage) {
        $responseData['pagination']['prev_page_url'] = $baseUrl . "&page=" . ($page - 1);
    }
    
    // Add related URLs
    $responseData['urls'] = [
        'category_info' => "/categories/get-categories.php?search=" . urlencode($category['name']),
        'all_categories' => "/categories/get-categories.php",
        'rss_feed' => "/rss/category-" . $category['slug'] . ".xml"
    ];
    
    sendResponse($responseData, 200, "Category posts retrieved successfully");
    
} catch (Exception $e) {
    // Enhanced error logging
    $errorContext = [
        'category_identifier' => $categoryIdentifier,
        'page' => $page,
        'limit' => $limit,
        'status' => $status,
        'sort' => $sort,
        'order' => $order,
        'error_message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    error_log("Get Category Posts Error: " . json_encode($errorContext));
    
    sendError("Failed to retrieve category posts: " . $e->getMessage(), 500);
}
?>