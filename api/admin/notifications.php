<?php
// backend/api/admin/notifications.php

// =============================================
// CORS CONFIGURATION - EXACT MATCH
// =============================================

// Allow your specific frontend URL
header("Access-Control-Allow-Origin: https://frontend-pink-pi-70.vercel.app");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Simple response for now
echo json_encode([
    'success' => true,
    'data' => []
]);
exit();
?>