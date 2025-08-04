<?php
// Include Composer's autoloader to use third-party packages
require_once '../vendor/autoload.php';

// Load environment variables from .env file in the parent directory
// This keeps sensitive data (like passwords) out of our code
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Include our database connection and response helper functions
require_once '../db.php';
require_once '../response.php';

// Import JWT classes for creating and managing tokens
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Only allow POST requests for login (security best practice)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Only POST method allowed", 405);
}

// Get the JSON data sent from the frontend/client
$data = json_decode(file_get_contents("php://input"));

// Extract and clean the email and password from the request
$email = trim($data->email ?? '');
$password = trim($data->password ?? '');

// Check if both email and password were provided
if (!$email || !$password) {
    sendError("Email and password are required", 400);
}

try {
    // Prepare a SQL statement to find the user by email
    // Using prepared statements prevents SQL injection attacks
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    
    // Get the result and fetch the user data
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    // Check if user exists and password is correct
    // password_verify() safely compares the plain password with the hashed one
    if (!$user || !password_verify($password, $user['password'])) {
        sendError("Invalid email or password", 401);
    }
    
    // Create the payload (data) that will be stored in the JWT token
    $payload = [
        'id' => $user['id'],           // User's unique ID
        'email' => $user['email'],     // User's email
        'role' => $user['role'],       // User's role (admin, user, etc.)
        'exp' => time() + (60 * 60 * 24) // Token expires in 24 hours
    ];
    
    // Get the secret key from environment variables
    // This key is used to sign the JWT token
    $jwt_secret = $_ENV['JWT_SECRET'] ?? null;
    
    // Make sure we have a secret key (security check)
    if (!$jwt_secret) {
        sendError("JWT secret key not found in environment", 500);
    }
    
    // Create the JWT token using our payload and secret key
    $token = JWT::encode($payload, $jwt_secret, 'HS256');
    
    // Create display name from email if name field doesn't exist in database
    $displayName = $user['name'] ?? explode('@', $user['email'])[0];
    
    // Send successful response with token and user information
    sendResponse([
        'token' => $token,              // JWT token for future requests
        'user' => [                     // User information for the frontend
            'id' => $user['id'],
            'name' => $displayName,
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ], 200, "Login successful");
    

    // Send successful response with token and user information
    
} catch (Exception $e) {
    // If anything goes wrong, send an error response
    sendError("Login failed: " . $e->getMessage(), 500);
}
?>