<?php
require_once '../response.php';

setcookie("token", "", time() - 3600, "/", "", isset($_SERVER['HTTPS']), true);

sendResponse(null, 200, "Logged out successfully");
