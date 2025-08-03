<?php
function sendResponse($data = null, $status = 200, $message = "Success") {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

function sendError($message = "Something went wrong", $status = 500) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'error' => $message
    ]);
    exit;
}
