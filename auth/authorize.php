<?php
require_once __DIR__ . '/authMiddleware.php'; // this gives you $user

function authorize(array $allowedRoles) {
    $user = authMiddleware();

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

    return $user; // return user in case you need it
}
