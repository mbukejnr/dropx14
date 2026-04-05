<?php
// includes/EnhancedProfile.php
require_once __DIR__ . '/ResponseHandler.php';
require_once __DIR__ . '/Database.php';

class EnhancedProfile {
    private $conn;
    
    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
        
        // Start session only if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    // Check if user is authenticated
    private function isAuthenticated() {
        return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    // Get complete user profile with all data
    public function getCompleteProfile() {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $userId = $_SESSION['user_id'];
            
            // Get user basic info
            $userQuery = "SELECT id, username, email, phone, avatar, bio, 
                         member_level, member_points, wallet_balance, rating, 
                         verified, created_at, updated_at 
                         FROM users WHERE id = :id";
            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->execute([':id' => $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                ResponseHandler::error('User not found', 404);
            }
            
            // Get addresses
            $addressQuery = "SELECT * FROM user_addresses WHERE user_id = :user_id ORDER BY is_default DESC, created_at DESC";
            $addressStmt = $this->conn->prepare($addressQuery);
            $addressStmt->execute([':user_id' => $userId]);
            $addresses = $addressStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get recent orders
            $orderQuery = "SELECT id, restaurant_name, items_count, total_amount, 
                          status, delivery_type, order_date 
                          FROM orders 
                          WHERE user_id = :user_id 
                          ORDER BY order_date DESC 
                          LIMIT 10";
            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->execute([':user_id' => $userId]);
            $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get favorites
            $favoriteQuery = "SELECT * FROM user_favorites WHERE user_id = :user_id ORDER BY created_at DESC";
            $favoriteStmt = $this->conn->prepare($favoriteQuery);
            $favoriteStmt->execute([':user_id' => $userId]);
            $favorites = $favoriteStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get payment methods
            $paymentQuery = "SELECT * FROM user_payment_methods WHERE user_id = :user_id ORDER BY is_default DESC";
            $paymentStmt = $this->conn->prepare($paymentQuery);
            $paymentStmt->execute([':user_id' => $userId]);
            $paymentMethods = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get notification preferences
            $notifQuery = "SELECT * FROM user_notifications WHERE user_id = :user_id";
            $notifStmt = $this->conn->prepare($notifQuery);
            $notifStmt->execute([':user_id' => $userId]);
            $notifications = $notifStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get wallet transactions
            $walletQuery = "SELECT * FROM wallet_transactions 
                           WHERE user_id = :user_id 
                           ORDER BY created_at DESC 
                           LIMIT 10";
            $walletStmt = $this->conn->prepare($walletQuery);
            $walletStmt->execute([':user_id' => $userId]);
            $transactions = $walletStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get order stats
            $statsQuery = "SELECT 
                          COUNT(*) as total_orders,
                          SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
                          SUM(total_amount) as total_spent
                          FROM orders 
                          WHERE user_id = :user_id";
            $statsStmt = $this->conn->prepare($statsQuery);
            $statsStmt->execute([':user_id' => $userId]);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
            
            ResponseHandler::success([
                'user' => $user,
                'addresses' => $addresses,
                'orders' => $orders,
                'favorites' => $favorites,
                'paymentMethods' => $paymentMethods,
                'notifications' => $notifications,
                'transactions' => $transactions,
                'stats' => $stats
            ], 'Complete profile retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
        }
    }
    
    // Update user profile
    public function updateProfile($data) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        $userId = $_SESSION['user_id'];
        $errors = [];
        
        // Validation
        if (isset($data['username']) && (empty($data['username']) || strlen($data['username']) < 3)) {
            $errors['username'] = 'Username must be at least 3 characters';
        }
        
        if (isset($data['email']) && (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'Valid email is required';
        }
        
        if (isset($data['phone']) && !empty($data['phone']) && !preg_match('/^\+?[0-9\s\-\(\)]+$/', $data['phone'])) {
            $errors['phone'] = 'Invalid phone number format';
        }
        
        if (isset($data['bio']) && strlen($data['bio']) > 500) {
            $errors['bio'] = 'Bio must be less than 500 characters';
        }
        
        if (!empty($errors)) {
            ResponseHandler::error('Validation failed', 422, $errors);
        }
        
        try {
            $this->conn->beginTransaction();
            
            // Check for duplicate email/username
            if (isset($data['email']) || isset($data['username'])) {
                $checkQuery = "SELECT id FROM users WHERE (email = :email OR username = :username) AND id != :id";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkParams = [':id' => $userId];
                
                if (isset($data['email'])) {
                    $checkParams[':email'] = trim($data['email']);
                } else {
                    $checkParams[':email'] = '';
                }
                
                if (isset($data['username'])) {
                    $checkParams[':username'] = trim($data['username']);
                } else {
                    $checkParams[':username'] = '';
                }
                
                $checkStmt->execute($checkParams);
                
                if ($checkStmt->rowCount() > 0) {
                    $this->conn->rollBack();
                    ResponseHandler::error('Email or username already exists', 409);
                }
            }
            
            // Build update query
            $updateFields = [];
            $params = [':id' => $userId];
            
            $fields = ['username', 'email', 'phone', 'bio'];
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = :$field";
                    $params[":$field"] = trim($data[$field]);
                }
            }
            
            if (empty($updateFields)) {
                $this->conn->rollBack();
                ResponseHandler::error('No fields to update', 400);
            }
            
            $updateFields[] = 'updated_at = NOW()';
            
            $query = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            // Update session data if username or email changed
            if (isset($data['username'])) {
                $_SESSION['username'] = $data['username'];
            }
            if (isset($data['email'])) {
                $_SESSION['user_email'] = $data['email'];
            }
            
            $this->conn->commit();
            $this->getCompleteProfile();
            
        } catch (PDOException $e) {
            $this->conn->rollBack();
            ResponseHandler::error('Update failed: ' . $e->getMessage(), 500);
        }
    }
    
    // Update password
    public function updatePassword($currentPassword, $newPassword) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        $userId = $_SESSION['user_id'];
        
        // Validation
        if (empty($currentPassword) || empty($newPassword)) {
            ResponseHandler::error('Current and new password are required', 400);
        }
        
        if (strlen($newPassword) < 6) {
            ResponseHandler::error('New password must be at least 6 characters', 400);
        }
        
        try {
            // Get current password hash
            $query = "SELECT password FROM users WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                ResponseHandler::error('User not found', 404);
            }
            
            // Verify current password
            if (!password_verify($currentPassword, $user['password'])) {
                ResponseHandler::error('Current password is incorrect', 401);
            }
            
            // Hash new password
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Update password
            $updateQuery = "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([
                ':password' => $newPasswordHash,
                ':id' => $userId
            ]);
            
            ResponseHandler::success([], 'Password updated successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Password update failed: ' . $e->getMessage(), 500);
        }
    }
    
    // Manage addresses
    public function getAddresses() {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $query = "SELECT * FROM user_addresses WHERE user_id = :user_id ORDER BY is_default DESC, created_at DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['addresses' => $addresses], 'Addresses retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get addresses: ' . $e->getMessage(), 500);
        }
    }
    
    public function addAddress($data) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        // Validation
        $errors = [];
        
        if (empty($data['name'])) {
            $errors['name'] = 'Address name is required';
        }
        
        if (empty($data['address'])) {
            $errors['address'] = 'Address is required';
        }
        
        if (!isset($data['type']) || !in_array($data['type'], ['home', 'work', 'other'])) {
            $errors['type'] = 'Valid address type is required';
        }
        
        if (!empty($errors)) {
            ResponseHandler::error('Validation failed', 422, $errors);
        }
        
        try {
            $this->conn->beginTransaction();
            
            $userId = $_SESSION['user_id'];
            
            // If this is set as default, update existing defaults
            if (isset($data['is_default']) && $data['is_default']) {
                $resetQuery = "UPDATE user_addresses SET is_default = FALSE WHERE user_id = :user_id";
                $resetStmt = $this->conn->prepare($resetQuery);
                $resetStmt->execute([':user_id' => $userId]);
            }
            
            // Insert new address
            $query = "INSERT INTO user_addresses (user_id, name, address, type, instructions, latitude, longitude, is_default) 
                     VALUES (:user_id, :name, :address, :type, :instructions, :latitude, :longitude, :is_default)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':user_id' => $userId,
                ':name' => trim($data['name']),
                ':address' => trim($data['address']),
                ':type' => $data['type'],
                ':instructions' => $data['instructions'] ?? null,
                ':latitude' => $data['latitude'] ?? null,
                ':longitude' => $data['longitude'] ?? null,
                ':is_default' => $data['is_default'] ?? false
            ]);
            
            $this->conn->commit();
            
            $addressId = $this->conn->lastInsertId();
            
            // Return the new address
            $getQuery = "SELECT * FROM user_addresses WHERE id = :id";
            $getStmt = $this->conn->prepare($getQuery);
            $getStmt->execute([':id' => $addressId]);
            $address = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['address' => $address], 'Address added successfully', 201);
            
        } catch (PDOException $e) {
            $this->conn->rollBack();
            ResponseHandler::error('Failed to add address: ' . $e->getMessage(), 500);
        }
    }
    
    public function updateAddress($addressId, $data) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $this->conn->beginTransaction();
            
            $userId = $_SESSION['user_id'];
            
            // Check if address belongs to user
            $checkQuery = "SELECT id FROM user_addresses WHERE id = :id AND user_id = :user_id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([':id' => $addressId, ':user_id' => $userId]);
            
            if ($checkStmt->rowCount() === 0) {
                $this->conn->rollBack();
                ResponseHandler::error('Address not found', 404);
            }
            
            // If setting as default, update existing defaults
            if (isset($data['is_default']) && $data['is_default']) {
                $resetQuery = "UPDATE user_addresses SET is_default = FALSE WHERE user_id = :user_id AND id != :id";
                $resetStmt = $this->conn->prepare($resetQuery);
                $resetStmt->execute([':user_id' => $userId, ':id' => $addressId]);
            }
            
            // Build update query
            $updateFields = [];
            $params = [':id' => $addressId];
            
            $fields = ['name', 'address', 'type', 'instructions', 'latitude', 'longitude', 'is_default'];
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }
            
            if (empty($updateFields)) {
                $this->conn->rollBack();
                ResponseHandler::error('No fields to update', 400);
            }
            
            $query = "UPDATE user_addresses SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            $this->conn->commit();
            
            ResponseHandler::success([], 'Address updated successfully');
            
        } catch (PDOException $e) {
            $this->conn->rollBack();
            ResponseHandler::error('Failed to update address: ' . $e->getMessage(), 500);
        }
    }
    
    public function deleteAddress($addressId) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $userId = $_SESSION['user_id'];
            
            // Check if address belongs to user and is not default
            $checkQuery = "SELECT is_default FROM user_addresses WHERE id = :id AND user_id = :user_id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([':id' => $addressId, ':user_id' => $userId]);
            $address = $checkStmt->fetch();
            
            if (!$address) {
                ResponseHandler::error('Address not found', 404);
            }
            
            if ($address['is_default']) {
                ResponseHandler::error('Cannot delete default address', 400);
            }
            
            // Delete address
            $deleteQuery = "DELETE FROM user_addresses WHERE id = :id AND user_id = :user_id";
            $deleteStmt = $this->conn->prepare($deleteQuery);
            $deleteStmt->execute([':id' => $addressId, ':user_id' => $userId]);
            
            ResponseHandler::success([], 'Address deleted successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to delete address: ' . $e->getMessage(), 500);
        }
    }
    
    public function setDefaultAddress($addressId) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $this->conn->beginTransaction();
            
            $userId = $_SESSION['user_id'];
            
            // Check if address belongs to user
            $checkQuery = "SELECT id FROM user_addresses WHERE id = :id AND user_id = :user_id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([':id' => $addressId, ':user_id' => $userId]);
            
            if ($checkStmt->rowCount() === 0) {
                $this->conn->rollBack();
                ResponseHandler::error('Address not found', 404);
            }
            
            // Reset all defaults
            $resetQuery = "UPDATE user_addresses SET is_default = FALSE WHERE user_id = :user_id";
            $resetStmt = $this->conn->prepare($resetQuery);
            $resetStmt->execute([':user_id' => $userId]);
            
            // Set new default
            $updateQuery = "UPDATE user_addresses SET is_default = TRUE WHERE id = :id AND user_id = :user_id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([':id' => $addressId, ':user_id' => $userId]);
            
            $this->conn->commit();
            
            ResponseHandler::success([], 'Default address updated successfully');
            
        } catch (PDOException $e) {
            $this->conn->rollBack();
            ResponseHandler::error('Failed to set default address: ' . $e->getMessage(), 500);
        }
    }
    
    // Manage orders
    public function getOrders($filters = []) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $userId = $_SESSION['user_id'];
            
            $query = "SELECT id, restaurant_name, items_count, total_amount, 
                     status, delivery_type, order_date 
                     FROM orders 
                     WHERE user_id = :user_id";
            
            $params = [':user_id' => $userId];
            
            // Apply filters
            if (!empty($filters['status'])) {
                $query .= " AND status = :status";
                $params[':status'] = $filters['status'];
            }
            
            if (!empty($filters['start_date'])) {
                $query .= " AND DATE(order_date) >= :start_date";
                $params[':start_date'] = $filters['start_date'];
            }
            
            if (!empty($filters['end_date'])) {
                $query .= " AND DATE(order_date) <= :end_date";
                $params[':end_date'] = $filters['end_date'];
            }
            
            $query .= " ORDER BY order_date DESC";
            
            // Add limit if specified
            if (!empty($filters['limit'])) {
                $query .= " LIMIT :limit";
                $params[':limit'] = (int)$filters['limit'];
            }
            
            $stmt = $this->conn->prepare($query);
            
            // Bind parameters
            foreach ($params as $key => $value) {
                if ($key === ':limit') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['orders' => $orders], 'Orders retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get orders: ' . $e->getMessage(), 500);
        }
    }
    
    public function getOrderDetails($orderId) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $userId = $_SESSION['user_id'];
            
            $query = "SELECT * FROM orders WHERE id = :id AND user_id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':id' => $orderId, ':user_id' => $userId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                ResponseHandler::error('Order not found', 404);
            }
            
            ResponseHandler::success(['order' => $order], 'Order details retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get order details: ' . $e->getMessage(), 500);
        }
    }
    
    // Manage favorites
    public function getFavorites() {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $query = "SELECT * FROM user_favorites WHERE user_id = :user_id ORDER BY created_at DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['favorites' => $favorites], 'Favorites retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get favorites: ' . $e->getMessage(), 500);
        }
    }
    
    public function addFavorite($data) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        if (empty($data['restaurant_name'])) {
            ResponseHandler::error('Restaurant name is required', 400);
        }
        
        try {
            $userId = $_SESSION['user_id'];
            
            // Check if already favorited
            $checkQuery = "SELECT id FROM user_favorites WHERE user_id = :user_id AND restaurant_name = :restaurant_name";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([
                ':user_id' => $userId,
                ':restaurant_name' => trim($data['restaurant_name'])
            ]);
            
            if ($checkStmt->rowCount() > 0) {
                ResponseHandler::error('Already in favorites', 409);
            }
            
            // Add to favorites
            $query = "INSERT INTO user_favorites (user_id, restaurant_name, cuisine_type, rating) 
                     VALUES (:user_id, :restaurant_name, :cuisine_type, :rating)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':user_id' => $userId,
                ':restaurant_name' => trim($data['restaurant_name']),
                ':cuisine_type' => $data['cuisine_type'] ?? null,
                ':rating' => $data['rating'] ?? null
            ]);
            
            $favoriteId = $this->conn->lastInsertId();
            
            // Return the new favorite
            $getQuery = "SELECT * FROM user_favorites WHERE id = :id";
            $getStmt = $this->conn->prepare($getQuery);
            $getStmt->execute([':id' => $favoriteId]);
            $favorite = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['favorite' => $favorite], 'Added to favorites successfully', 201);
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to add favorite: ' . $e->getMessage(), 500);
        }
    }
    
    public function removeFavorite($restaurantName) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $userId = $_SESSION['user_id'];
            
            $query = "DELETE FROM user_favorites WHERE user_id = :user_id AND restaurant_name = :restaurant_name";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':user_id' => $userId,
                ':restaurant_name' => $restaurantName
            ]);
            
            ResponseHandler::success([], 'Removed from favorites successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to remove favorite: ' . $e->getMessage(), 500);
        }
    }
    
    // Manage payment methods
    public function getPaymentMethods() {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $query = "SELECT * FROM user_payment_methods WHERE user_id = :user_id ORDER BY is_default DESC, created_at DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            $paymentMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['paymentMethods' => $paymentMethods], 'Payment methods retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get payment methods: ' . $e->getMessage(), 500);
        }
    }
    
    // Manage notifications
    public function getNotificationSettings() {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $query = "SELECT * FROM user_notifications WHERE user_id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['notifications' => $settings], 'Notification settings retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get notification settings: ' . $e->getMessage(), 500);
        }
    }
    
    public function updateNotificationSettings($data) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $userId = $_SESSION['user_id'];
            
            $updateFields = [];
            $params = [':user_id' => $userId];
            
            $fields = ['email_notifications', 'push_notifications', 'sms_notifications', 'promotional_offers'];
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = :$field";
                    $params[":$field"] = (bool)$data[$field];
                }
            }
            
            if (empty($updateFields)) {
                ResponseHandler::error('No fields to update', 400);
            }
            
            $query = "UPDATE user_notifications SET " . implode(', ', $updateFields) . " WHERE user_id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            ResponseHandler::success([], 'Notification settings updated successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to update notification settings: ' . $e->getMessage(), 500);
        }
    }
    
    // Wallet management
    public function getWalletBalance() {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $query = "SELECT wallet_balance FROM users WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['balance' => $user['wallet_balance']], 'Wallet balance retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get wallet balance: ' . $e->getMessage(), 500);
        }
    }
    
    public function getWalletTransactions($limit = 10) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $query = "SELECT * FROM wallet_transactions 
                     WHERE user_id = :user_id 
                     ORDER BY created_at DESC 
                     LIMIT :limit";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ResponseHandler::success(['transactions' => $transactions], 'Wallet transactions retrieved successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to get wallet transactions: ' . $e->getMessage(), 500);
        }
    }
    
    public function addWalletTransaction($amount, $type, $description) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        if (!in_array($type, ['credit', 'debit'])) {
            ResponseHandler::error('Invalid transaction type', 400);
        }
        
        if ($amount <= 0) {
            ResponseHandler::error('Amount must be positive', 400);
        }
        
        try {
            $this->conn->beginTransaction();
            
            $userId = $_SESSION['user_id'];
            
            // Get current balance
            $balanceQuery = "SELECT wallet_balance FROM users WHERE id = :id FOR UPDATE";
            $balanceStmt = $this->conn->prepare($balanceQuery);
            $balanceStmt->execute([':id' => $userId]);
            $user = $balanceStmt->fetch(PDO::FETCH_ASSOC);
            
            $currentBalance = $user['wallet_balance'];
            
            // Calculate new balance
            if ($type === 'credit') {
                $newBalance = $currentBalance + $amount;
            } else {
                if ($amount > $currentBalance) {
                    $this->conn->rollBack();
                    ResponseHandler::error('Insufficient balance', 400);
                }
                $newBalance = $currentBalance - $amount;
            }
            
            // Update user balance
            $updateQuery = "UPDATE users SET wallet_balance = :balance WHERE id = :id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([
                ':balance' => $newBalance,
                ':id' => $userId
            ]);
            
            // Record transaction
            $transactionQuery = "INSERT INTO wallet_transactions 
                               (user_id, type, amount, description, balance_after) 
                               VALUES (:user_id, :type, :amount, :description, :balance_after)";
            
            $transactionStmt = $this->conn->prepare($transactionQuery);
            $transactionStmt->execute([
                ':user_id' => $userId,
                ':type' => $type,
                ':amount' => $amount,
                ':description' => $description,
                ':balance_after' => $newBalance
            ]);
            
            $this->conn->commit();
            
            ResponseHandler::success([
                'new_balance' => $newBalance,
                'transaction_id' => $this->conn->lastInsertId()
            ], 'Transaction completed successfully');
            
        } catch (PDOException $e) {
            $this->conn->rollBack();
            ResponseHandler::error('Transaction failed: ' . $e->getMessage(), 500);
        }
    }
    
    // Upload avatar
    public function uploadAvatar($file) {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            ResponseHandler::error('File upload failed', 400);
        }
        
        $maxFileSize = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $maxFileSize) {
            ResponseHandler::error('File size must be less than 2MB', 400);
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileType = mime_content_type($file['tmp_name']);
        
        if (!in_array($fileType, $allowedTypes)) {
            ResponseHandler::error('Only JPG, PNG, GIF, and WebP images are allowed', 400);
        }
        
        try {
            $userId = $_SESSION['user_id'];
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'avatar_' . $userId . '_' . time() . '.' . $extension;
            $uploadDir = __DIR__ . '/../uploads/avatars/';
            
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // Delete old avatar if exists
            $oldAvatarQuery = "SELECT avatar FROM users WHERE id = :id";
            $oldAvatarStmt = $this->conn->prepare($oldAvatarQuery);
            $oldAvatarStmt->execute([':id' => $userId]);
            $oldAvatar = $oldAvatarStmt->fetchColumn();
            
            if ($oldAvatar && file_exists(__DIR__ . '/../' . $oldAvatar)) {
                unlink(__DIR__ . '/../' . $oldAvatar);
            }
            
            // Move uploaded file
            $destination = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                ResponseHandler::error('Failed to save uploaded file', 500);
            }
            
            // Update database with relative path
            $relativePath = 'uploads/avatars/' . $filename;
            $query = "UPDATE users SET avatar = :avatar, updated_at = NOW() WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':avatar' => $relativePath,
                ':id' => $userId
            ]);
            
            // Get full URL
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $avatarUrl = $protocol . '://' . $host . '/' . $relativePath;
            
            ResponseHandler::success([
                'avatar' => $relativePath,
                'avatar_url' => $avatarUrl
            ], 'Avatar uploaded successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Upload failed: ' . $e->getMessage(), 500);
        }
    }
    
    // Delete account
    public function deleteAccount() {
        if (!$this->isAuthenticated()) {
            ResponseHandler::error('Unauthorized', 401);
        }
        
        try {
            $userId = $_SESSION['user_id'];
            
            // Get avatar path
            $avatarQuery = "SELECT avatar FROM users WHERE id = :id";
            $avatarStmt = $this->conn->prepare($avatarQuery);
            $avatarStmt->execute([':id' => $userId]);
            $avatar = $avatarStmt->fetchColumn();
            
            // Delete avatar file if exists
            if ($avatar && file_exists(__DIR__ . '/../' . $avatar)) {
                unlink(__DIR__ . '/../' . $avatar);
            }
            
            // Delete user (cascade will delete all related data)
            $query = "DELETE FROM users WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':id' => $userId]);
            
            // Clear session
            $this->clearSession();
            
            ResponseHandler::success([], 'Account deleted successfully');
            
        } catch (PDOException $e) {
            ResponseHandler::error('Failed to delete account: ' . $e->getMessage(), 500);
        }
    }
    
    // Clear session
    private function clearSession() {
        $_SESSION = array();
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
}
?>