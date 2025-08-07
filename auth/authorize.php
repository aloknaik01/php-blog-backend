<?php

require_once __DIR__ . '/authMiddleware.php'; // Include the authenticate function
require_once __DIR__ . '/../db.php'; // Include database connection

function authorize(array $allowedRoles) {
    $user = authenticate(); // Call the correct function name
    
    if (!isset($user['role'])) {
        http_response_code(401);
        echo json_encode([
            'status' => false,
            'message' => 'Unauthorized: Missing role'
        ]);
        exit;
    }
    
    if (!in_array($user['role'], $allowedRoles)) {
        http_response_code(403);
        echo json_encode([
            'status' => false,
            'message' => 'Forbidden: You do not have permission'
        ]);
        exit;
    }
    
    return $user; // return user data for further use
}

/**
 * Check if a user can modify a specific post
 */
function canModifyPost($userId, $userRole, $postId) {
    global $conn;
    
    // Admins can modify any post
    if ($userRole === 'admin') {
        return true;
    }
    
    // Check if user owns the post
    $stmt = $conn->prepare("SELECT author_id FROM posts WHERE id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || $result->num_rows === 0) {
        return false; // Post doesn't exist
    }
    
    $post = $result->fetch_assoc();
    return $post['author_id'] == $userId;
}

/**
 * Get the owner information of a specific post
 */
function getPostOwner($postId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.role 
        FROM posts p 
        JOIN users u ON p.author_id = u.id 
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || $result->num_rows === 0) {
        return null; // Post doesn't exist
    }
    
    return $result->fetch_assoc();
}

/**
 * Check if a post exists
 */
function postExists($postId) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT id FROM posts WHERE id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result && $result->num_rows > 0;
}

// New function for post-specific authorization
function authorizePostAccess(array $allowedRoles, $postId = null, $requireOwnership = false) {
    $user = authenticate();
    
    if (!isset($user['role'])) {
        http_response_code(401);
        echo json_encode([
            'status' => false,
            'message' => 'Unauthorized: Missing role'
        ]);
        exit;
    }
    
    if (!in_array($user['role'], $allowedRoles)) {
        http_response_code(403);
        echo json_encode([
            'status' => false,
            'message' => 'Forbidden: You do not have permission'
        ]);
        exit;
    }
    
    // If post-specific access is required
    if ($requireOwnership && $postId) {
        // First check if post exists
        if (!postExists($postId)) {
            http_response_code(404);
            echo json_encode([
                'status' => false,
                'message' => 'Post not found'
            ]);
            exit;
        }
        
        if (!canModifyPost($user['id'], $user['role'], $postId)) {
            $postOwner = getPostOwner($postId);
            $ownerName = $postOwner ? $postOwner['name'] : 'Unknown';
            
            http_response_code(403);
            echo json_encode([
                'status' => false,
                'message' => "Forbidden: You can only modify your own posts. This post belongs to {$ownerName}",
                'post_owner' => $ownerName,
                'your_role' => $user['role']
            ]);
            exit;
        }
    }
    
    return $user;
}

// Simplified function specifically for post modification
function authorizePostModification($postId) {
    return authorizePostAccess(['admin', 'author'], $postId, true);
}

?>