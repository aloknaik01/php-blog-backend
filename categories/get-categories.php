<?php
// categories/get-categories.php
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

require_once '../db.php';
require_once '../response.php';

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError("Only GET method allowed", 405);
}

// Get query parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) && in_array($_GET['sort'], ['name', 'posts_count']) ? $_GET['sort'] : 'name';
$order = isset($_GET['order']) && in_array(strtolower($_GET['order']), ['asc', 'desc']) ? strtolower($_GET['order']) : 'asc';
$include_posts = isset($_GET['include_posts']) ? filter_var($_GET['include_posts'], FILTER_VALIDATE_BOOLEAN) : true;

// Calculate offset for pagination
$offset = ($page - 1) * $limit;

try {
    // Build the base query
    $whereClause = "WHERE 1=1";
    $params = [];
    $types = "";
    
    // Add search functionality (only search in name since no description column)
    if (!empty($search)) {
        $whereClause .= " AND c.name LIKE ?";
        $searchTerm = "%" . $search . "%";
        $params[] = $searchTerm;
        $types .= "s";
    }
    
    // Build ORDER BY clause
    $orderClause = "";
    switch ($sort) {
        case 'posts_count':
            $orderClause = "ORDER BY published_posts_count " . strtoupper($order);
            break;
        case 'name':
        default:
            $orderClause = "ORDER BY c.name " . strtoupper($order);
            break;
    }
    
    // Main query to get categories (using only existing columns)
    $query = "
        SELECT 
            c.id,
            c.name,
            c.slug,
            (SELECT COUNT(*) FROM posts WHERE category_id = c.id AND status = 'published') as published_posts_count,
            (SELECT COUNT(*) FROM posts WHERE category_id = c.id) as total_posts_count
        FROM categories c
        {$whereClause}
        {$orderClause}
        LIMIT ? OFFSET ?
    ";
    
    // Add pagination parameters
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($query);
    
    if (!empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $category = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'published_posts_count' => (int)$row['published_posts_count'],
            'total_posts_count' => (int)$row['total_posts_count']
        ];
        
        // Add recent posts if requested
        if ($include_posts && $row['published_posts_count'] > 0) {
            $postsStmt = $conn->prepare("
                SELECT 
                    p.id, p.title, p.slug, p.featured_image, p.created_at, p.views,
                    u.username as author_username
                FROM posts p
                JOIN users u ON p.author_id = u.id
                WHERE p.category_id = ? AND p.status = 'published'
                ORDER BY p.created_at DESC
                LIMIT 3
            ");
            $postsStmt->bind_param("i", $row['id']);
            $postsStmt->execute();
            $postsResult = $postsStmt->get_result();
            
            $recentPosts = [];
            while ($postRow = $postsResult->fetch_assoc()) {
                $recentPosts[] = [
                    'id' => (int)$postRow['id'],
                    'title' => $postRow['title'],
                    'slug' => $postRow['slug'],
                    'featured_image' => $postRow['featured_image'],
                    'author_username' => $postRow['author_username'],
                    'created_at' => $postRow['created_at'],
                    'views' => (int)$postRow['views']
                ];
            }
            
            $category['recent_posts'] = $recentPosts;
        }
        
        // Add useful URLs
        $category['urls'] = [
            'posts' => "/posts/get-posts.php?category_id=" . $row['id'],
            'category_page' => "/category/" . $row['slug']
        ];
        
        $categories[] = $category;
    }
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM categories c {$whereClause}";
    $countStmt = $conn->prepare($countQuery);
    
    if (!empty($search)) {
        $countStmt->bind_param("s", $searchTerm);
    }
    
    $countStmt->execute();
    $totalCount = $countStmt->get_result()->fetch_assoc()['total'];
    
    // Calculate pagination info
    $totalPages = ceil($totalCount / $limit);
    $hasNextPage = $page < $totalPages;
    $hasPrevPage = $page > 1;
    
    // Prepare response
    $responseData = [
        'categories' => $categories,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => (int)$totalCount,
            'total_pages' => $totalPages,
            'has_next_page' => $hasNextPage,
            'has_prev_page' => $hasPrevPage
        ],
        'filters' => [
            'search' => $search,
            'sort' => $sort,
            'order' => $order,
            'include_posts' => $include_posts
        ]
    ];
    
    // Add navigation URLs
    if ($hasNextPage) {
        $nextUrl = "/categories/get-categories.php?page=" . ($page + 1) . "&limit={$limit}";
        if (!empty($search)) $nextUrl .= "&search=" . urlencode($search);
        $responseData['pagination']['next_page_url'] = $nextUrl;
    }
    
    if ($hasPrevPage) {
        $prevUrl = "/categories/get-categories.php?page=" . ($page - 1) . "&limit={$limit}";
        if (!empty($search)) $prevUrl .= "&search=" . urlencode($search);
        $responseData['pagination']['prev_page_url'] = $prevUrl;
    }
    
    // Add summary statistics
    $statsQuery = "
        SELECT 
            COUNT(*) as total_categories,
            SUM((SELECT COUNT(*) FROM posts WHERE category_id = categories.id AND status = 'published')) as total_published_posts,
            SUM((SELECT COUNT(*) FROM posts WHERE category_id = categories.id)) as total_posts,
            (SELECT COUNT(*) FROM categories WHERE (SELECT COUNT(*) FROM posts WHERE category_id = categories.id) = 0) as empty_categories
        FROM categories
    ";
    
    $statsResult = $conn->query($statsQuery);
    $stats = $statsResult->fetch_assoc();
    
    $responseData['summary'] = [
        'total_categories' => (int)$stats['total_categories'],
        'total_published_posts' => (int)$stats['total_published_posts'],
        'total_posts' => (int)$stats['total_posts'],
        'empty_categories' => (int)$stats['empty_categories'],
        'categories_shown' => count($categories)
    ];
    
    sendResponse($responseData, 200, "Categories retrieved successfully");
    
} catch (Exception $e) {
    // Enhanced error logging
    $errorContext = [
        'page' => $page,
        'limit' => $limit,
        'search' => $search,
        'sort' => $sort,
        'order' => $order,
        'error_message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    error_log("Get Categories Error: " . json_encode($errorContext));
    
    sendError("Failed to retrieve categories: " . $e->getMessage(), 500);
}
?>