<?php
require_once __DIR__ . '/../config/database.php';
session_start();

$db = new Database();
$conn = $db->getConnection();

$userId = 1; // Your user ID
$sessionId = session_id();

echo "=== CART DEBUG ===\n";
echo "User ID: $userId\n";
echo "Session ID: $sessionId\n\n";

// Show all carts
$stmt = $conn->prepare("SELECT * FROM carts ORDER BY created_at DESC");
$stmt->execute();
$carts = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "All carts:\n";
print_r($carts);

// Show all cart items
$stmt = $conn->prepare("SELECT * FROM cart_items ORDER BY created_at DESC");
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nAll cart items:\n";
print_r($items);

// Try to find the most recent cart for this user
$stmt = $conn->prepare(
    "SELECT * FROM carts 
     WHERE user_id = :user_id OR session_id = :session_id 
     ORDER BY updated_at DESC LIMIT 1"
);
$stmt->execute([':user_id' => $userId, ':session_id' => $sessionId]);
$latestCart = $stmt->fetch(PDO::FETCH_ASSOC);
echo "\nLatest cart for user/session:\n";
print_r($latestCart);