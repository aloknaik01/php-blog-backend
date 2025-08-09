<?php
// Function to send a successful JSON response
function sendResponse($data = null, $status = 200, $message = "Success") {
    // Check if headers are already sent, if not then set the HTTP status code and content type header
    if (!headers_sent()) {
        http_response_code($status);           // Set HTTP response status code (e.g., 200 for OK)
        header('Content-Type: application/json'); // Set response content type to JSON
    }
    
    // Encode the response data as JSON and output it
    echo json_encode([
        'status' => $status,   // HTTP status code
        'message' => $message, // Custom message like "Success"
        'data' => $data        // The actual data you want to send in response
    ]);
    
    exit; // Stop script execution after sending response
}

// Function to send an error JSON response
function sendError($message = "Something went wrong", $status = 500) {
    // Check if headers are already sent, if not then set the HTTP status code and content type header
    if (!headers_sent()) {
        http_response_code($status);           // Set HTTP response status code (e.g., 500 for Internal Server Error)
        header('Content-Type: application/json'); // Set response content type to JSON
    }
    
    // Encode the error message as JSON and output it
    echo json_encode([
        'status' => $status,   // HTTP status code
        'error' => $message    // Error message to send
    ]);
    
    exit; // Stop script execution after sending error response
}
