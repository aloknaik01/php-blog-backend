<?php
// Composer's autoloader gives access to third-party packages (like JWT, dotenv, etc.)
require_once '../vendor/autoload.php';

//  Load environment variables from the .env file securely (like DB credentials, secret keys)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

//  Connect to the database and include helper functions for sending responses
require_once '../db.php';
require_once '../response.php';

// Use Firebase JWT classes to create and manage tokens securely
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

//  Only allow POST requests to prevent security risks from GET or other methods
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Only POST method allowed", 405); // Method Not Allowed
}

//  Read raw JSON data sent by the frontend (like React or Vue)
$data = json_decode(file_get_contents("php://input"));

//  Extract email and password, trimming extra spaces and handling null safely
$email = trim($data->email ?? '');
$password = trim($data->password ?? '');

//  If email or password is missing, return a user-friendly error
if (!$email || !$password) {
    sendError("Email and password are required", 400); // Bad Request
}

try {
    //  Prepare a secure SQL query using a prepared statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email); // Bind email as string
    $stmt->execute();
    
    //  Fetch user info from database
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    //  If user not found or password doesn't match the hashed password in DB
    if (!$user || !password_verify($password, $user['password'])) {
        sendError("Invalid email or password", 401); // Unauthorized
    }

    //  If login is successful, create the data (payload) that will go inside the JWT
    $payload = [
        'id' => $user['id'],               // User ID
        'email' => $user['email'],         // User Email
        'role' => $user['role'],           // User Role (admin or user)
        'exp' => time() + (60 * 60 * 24)   // Token expires after 24 hours
    ];

    //  Get the secret key from environment variables to sign the JWT
    $jwt_secret = $_ENV['JWT_SECRET'] ?? null;

    //  If JWT secret is missing in your .env, throw server error
    if (!$jwt_secret) {
        sendError("JWT secret key not found in environment", 500); // Internal Server Error
    }

    //  Generate the JWT token using HS256 algorithm and the secret key
    $token = JWT::encode($payload, $jwt_secret, 'HS256');

    //  Store the token in a secure HttpOnly cookie (not accessible by JavaScript)
    setcookie("token", $token, [
        'expires' => time() + 86400,           // Cookie valid for 1 day
        'path' => '/',                         // Available on entire domain
        'secure' => isset($_SERVER['HTTPS']), // Send cookie only on HTTPS
        'httponly' => true,                    // JavaScript cannot read this cookie
        'samesite' => 'Strict'                 // Only send cookie on same-site requests
    ]);

    //  Set a display name — use name from DB if available, else fallback to part before @
    $displayName = $user['name'] ?? explode('@', $user['email'])[0];

    //  Send a clean response (token is already stored in cookie)
    sendResponse([
        'user' => [
            'id' => $user['id'],
            'name' => $displayName,
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ], 200, "Login successful");

} catch (Exception $e) {
    //  If any exception occurs (like DB failure), show user-friendly error
    sendError("Login failed: " . $e->getMessage(), 500);
}
?>
