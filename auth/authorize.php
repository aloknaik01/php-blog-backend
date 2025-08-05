<?php 
require_once __DIR__ . '/authMiddleware.php'; // Include the authenticate function
 
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