<?php 
require_once __DIR__ . '/../vendor/autoload.php'; 
require_once __DIR__ . '/../response.php'; 
 
use Firebase\JWT\JWT; 
use Firebase\JWT\Key; 
 
function authenticate() 
{ 
    // Look for any cookie that ends with 'Token'
    $token = null;
    $cookieName = null;
    
    foreach ($_COOKIE as $name => $value) {
        if (str_ends_with($name, 'Token')) {
            $token = $value;
            $cookieName = $name;
            break;
        }
    }
    
    if (!$token) {
        sendError("Unauthorized: No authentication token found", 401);
    } 
    $secret = $_ENV['JWT_SECRET'] ?? null; 
 
    if (!$secret) { 
        sendError("JWT secret not found", 500); 
    } 
 
    try { 
        $decoded = JWT::decode($token, new Key($secret, 'HS256')); 
        return (array) $decoded; 
 
    } catch (Exception $e) { 
        sendError("Invalid or expired token", 401); 
    } 
}
?>