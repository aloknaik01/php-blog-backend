<?php
require_once '../db.php';
require_once '../response.php';

try {
    // Query params
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 10;
    $offset = ($page - 1) * $limit;

    $sort_by = $_GET['sort_by'] ?? 'latest'; // latest, oldest, views, most_commented
    $category_id = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
    $author_id = isset($_GET['author_id']) ? (int) $_GET['author_id'] : null;
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;

    // Sorting options mapping
    $sortOptions = [
        'latest' => 'p.created_at DESC',
        'oldest' => 'p.created_at ASC',
        'views' => 'p.views DESC',
        'most_commented' => 'comment_count DESC'
    ];
    $orderBy = $sortOptions[$sort_by] ?? $sortOptions['latest'];

    // Base query with author details
    $sql = "
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
            p.author_id,
            p.category_id,
            u.username AS author_name,
            NULL AS author_image,
            COUNT(c.id) AS comment_count
        FROM posts p
        LEFT JOIN users u ON p.author_id = u.id
        LEFT JOIN comments c ON p.id = c.post_id
        WHERE 1
    ";

    $params = [];
    $types = '';

    // Filters
    if (!empty($category_id)) {
        $sql .= " AND p.category_id = ? ";
        $params[] = $category_id;
        $types .= 'i';
    }

    if (!empty($author_id)) {
        $sql .= " AND p.author_id = ? ";
        $params[] = $author_id;
        $types .= 'i';
    }

    if (!empty($start_date) && !empty($end_date)) {
        $sql .= " AND DATE(p.created_at) BETWEEN ? AND ? ";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= 'ss';
    }

    $sql .= " GROUP BY p.id ORDER BY {$orderBy} LIMIT ? OFFSET ? ";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    // Prepare and execute
    $stmt = $conn->prepare($sql);
    
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    // Fetch posts using MySQLi method
    $posts = [];
    while ($row = $result->fetch_assoc()) {
        $posts[] = $row;
    }

    sendResponse($posts, 200, $posts ? "Posts fetched successfully" : "No posts found");

} catch (Exception $e) {
    sendError("Error: " . $e->getMessage(), 500);
}
?>


<!-- 
Based on your get-posts API code, here are comprehensive endpoint examples to test all the functionality:
Basic Endpoints:
1. Get All Posts (Default)
GET http://localhost/blog-app/posts/get-posts.php
2. Pagination
GET http://localhost/blog-app/posts/get-posts.php?page=1
GET http://localhost/blog-app/posts/get-posts.php?page=2
GET http://localhost/blog-app/posts/get-posts.php?page=1&limit=5
GET http://localhost/blog-app/posts/get-posts.php?page=2&limit=3
3. Sorting Options
GET http://localhost/blog-app/posts/get-posts.php?sort_by=latest
GET http://localhost/blog-app/posts/get-posts.php?sort_by=oldest  
GET http://localhost/blog-app/posts/get-posts.php?sort_by=views
GET http://localhost/blog-app/posts/get-posts.php?sort_by=most_commented
Filtering Endpoints:
4. Filter by Category
GET http://localhost/blog-app/posts/get-posts.php?category_id=1
GET http://localhost/blog-app/posts/get-posts.php?category_id=2&sort_by=views
5. Filter by Author
GET http://localhost/blog-app/posts/get-posts.php?author_id=23
GET http://localhost/blog-app/posts/get-posts.php?author_id=1&sort_by=latest
6. Date Range Filter
GET http://localhost/blog-app/posts/get-posts.php?start_date=2025-01-01&end_date=2025-12-31
GET http://localhost/blog-app/posts/get-posts.php?start_date=2025-08-01&end_date=2025-08-31
Combined Filters:
7. Multiple Filters Together
GET http://localhost/blog-app/posts/get-posts.php?page=1&limit=5&sort_by=views&category_id=1

GET http://localhost/blog-app/posts/get-posts.php?author_id=23&sort_by=latest&page=1&limit=3

GET http://localhost/blog-app/posts/get-posts.php?category_id=1&start_date=2025-08-01&end_date=2025-08-31&sort_by=most_commented

GET http://localhost/blog-app/posts/get-posts.php?author_id=23&category_id=1&sort_by=views&page=1&limit=10
Advanced Testing:
8. Edge Cases
GET http://localhost/blog-app/posts/get-posts.php?page=0        # Should default to page=1
GET http://localhost/blog-app/posts/get-posts.php?limit=0       # Should default to limit=10
GET http://localhost/blog-app/posts/get-posts.php?page=999      # Empty result
GET http://localhost/blog-app/posts/get-posts.php?category_id=999  # Non-existent category
9. Invalid Parameters
GET http://localhost/blog-app/posts/get-posts.php?sort_by=invalid   # Should default to 'latest'
GET http://localhost/blog-app/posts/get-posts.php?page=abc          # Should default to 1
Real Examples Based on Your Data:
10. Using Your Actual Data
# Get posts by your user (ID: 23)
GET http://localhost/blog-app/posts/get-posts.php?author_id=23

# Get first 3 posts sorted by latest  
GET http://localhost/blog-app/posts/get-posts.php?limit=3&sort_by=latest

# Search in August 2025
GET http://localhost/blog-app/posts/get-posts.php?start_date=2025-08-01&end_date=2025-08-31 -->