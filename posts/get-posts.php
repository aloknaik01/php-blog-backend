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


