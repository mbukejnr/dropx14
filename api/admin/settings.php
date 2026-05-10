<?php
// backend/api/admin/settings.php
// PROFESSIONAL SETTINGS API - DATA FROM DATABASE

// =============================================
// CORS HEADERS
// =============================================
$allowed_origins = [
    'https://frontend-pink-pi-70.vercel.app',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:5173'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://frontend-pink-pi-70.vercel.app");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =============================================
// REQUIREMENTS
// =============================================
require_once __DIR__ . '/../../config/admin_database.php';
require_once __DIR__ . '/../../includes/admin_auth.php';

$db = AdminDatabase::getInstance();
$conn = $db->getConnection();
$auth = new AdminAuth();

$admin = $auth->validateToken();
if (!$admin) {
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// =============================================
// CREATE SETTINGS TABLE (RUN ONCE)
// =============================================
function initSettingsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS admin_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT,
        setting_type ENUM('text', 'number', 'boolean') DEFAULT 'text',
        category VARCHAR(50) DEFAULT 'general',
        label VARCHAR(200),
        description TEXT,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $conn->exec($sql);
    
    // Check if table is empty
    $check = $conn->query("SELECT COUNT(*) FROM admin_settings")->fetchColumn();
    
    if ($check == 0) {
        // Insert default settings from database
        $defaults = [
            // Delivery Settings
            ['delivery_base_fee', '1500', 'number', 'delivery', 'Base Delivery Fee', 'Standard delivery fee in MWK', 1],
            ['delivery_free_threshold', '50000', 'number', 'delivery', 'Free Delivery Threshold', 'Minimum order for free delivery', 2],
            ['delivery_radius_km', '15', 'number', 'delivery', 'Delivery Radius', 'Maximum delivery distance in kilometers', 3],
            ['delivery_fee_per_km', '200', 'number', 'delivery', 'Extra Fee per KM', 'Additional fee per km beyond radius', 4],
            ['estimated_delivery_time', '45', 'number', 'delivery', 'Est. Delivery Time', 'Average delivery time in minutes', 5],
            
            // Payment Settings
            ['payment_cod', '1', 'boolean', 'payment', 'Cash on Delivery', 'Enable COD payment method', 1],
            ['payment_card', '1', 'boolean', 'payment', 'Card Payments', 'Enable card payments', 2],
            ['payment_mobile_money', '1', 'boolean', 'payment', 'Mobile Money', 'Enable mobile money payments', 3],
            ['payment_wallet', '1', 'boolean', 'payment', 'Wallet Payments', 'Enable wallet balance payments', 4],
            
            // Business Settings
            ['tax_rate', '16.5', 'number', 'business', 'Tax Rate', 'VAT percentage applied to orders', 1],
            ['commission_rate', '10', 'number', 'business', 'Commission Rate', 'Merchant commission percentage', 2],
            ['currency', 'MWK', 'text', 'business', 'Currency', 'Default currency code', 3],
            ['currency_symbol', 'MK', 'text', 'business', 'Currency Symbol', 'Symbol displayed with prices', 4],
            
            // Loyalty Settings
            ['loyalty_enabled', '1', 'boolean', 'loyalty', 'Enable Loyalty Points', 'Turn loyalty points system on/off', 1],
            ['points_per_order', '10', 'number', 'loyalty', 'Points per Order', 'Points awarded per completed order', 2],
            ['points_per_1000', '5', 'number', 'loyalty', 'Points per MK1000', 'Points per 1000 MWK spent', 3],
            ['points_expiry_days', '180', 'number', 'loyalty', 'Points Expiry', 'Days until points expire', 4],
            
            // Notification Settings
            ['email_notifications', '1', 'boolean', 'notifications', 'Email Notifications', 'Send email notifications', 1],
            ['sms_notifications', '1', 'boolean', 'notifications', 'SMS Notifications', 'Send SMS notifications', 2],
            ['order_status_sms', '1', 'boolean', 'notifications', 'Order Status SMS', 'Notify on order status change', 3],
            
            // App Settings
            ['app_name', 'DropX', 'text', 'app', 'App Name', 'Application name displayed', 1],
            ['app_version', '2.0.0', 'text', 'app', 'App Version', 'Current app version', 2],
            ['maintenance_mode', '0', 'boolean', 'app', 'Maintenance Mode', 'Put app in maintenance mode', 3],
            ['max_order_per_user', '5', 'number', 'app', 'Max Active Orders', 'Maximum active orders per user', 4]
        ];
        
        $stmt = $conn->prepare("INSERT INTO admin_settings (setting_key, setting_value, setting_type, category, label, description, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($defaults as $default) {
            $stmt->execute($default);
        }
    }
}

// Initialize table
initSettingsTable($conn);

// =============================================
// GET SETTINGS FROM DATABASE
// =============================================
if ($method === 'GET' && $action === 'get') {
    $category = isset($_GET['category']) ? $_GET['category'] : '';
    
    if ($category) {
        $stmt = $conn->prepare("SELECT * FROM admin_settings WHERE category = :category ORDER BY sort_order");
        $stmt->execute([':category' => $category]);
    } else {
        $stmt = $conn->prepare("SELECT * FROM admin_settings ORDER BY category, sort_order");
        $stmt->execute();
    }
    
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse values based on type
    foreach ($settings as &$setting) {
        if ($setting['setting_type'] === 'boolean') {
            $setting['setting_value'] = $setting['setting_value'] == '1';
        } elseif ($setting['setting_type'] === 'number') {
            $setting['setting_value'] = floatval($setting['setting_value']);
        }
    }
    
    $db->sendResponse(['settings' => $settings]);
}

// =============================================
// UPDATE SETTINGS IN DATABASE
// =============================================
elseif ($method === 'POST' && $action === 'update') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['settings']) || !is_array($data['settings'])) {
        $db->sendError("Invalid settings data", 400);
    }
    
    $updated = 0;
    $stmt = $conn->prepare("UPDATE admin_settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = :key");
    
    foreach ($data['settings'] as $key => $value) {
        // Convert boolean to string for database storage
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        
        if ($stmt->execute([':key' => $key, ':value' => $value])) {
            $updated++;
        }
    }
    
    $db->sendResponse(['updated' => $updated], "$updated settings updated successfully");
}

// =============================================
// RESET SETTINGS TO DEFAULTS
// =============================================
elseif ($method === 'POST' && $action === 'reset') {
    $defaults = [
        'delivery_base_fee' => '1500',
        'delivery_free_threshold' => '50000',
        'delivery_radius_km' => '15',
        'delivery_fee_per_km' => '200',
        'estimated_delivery_time' => '45',
        'payment_cod' => '1',
        'payment_card' => '1',
        'payment_mobile_money' => '1',
        'payment_wallet' => '1',
        'tax_rate' => '16.5',
        'commission_rate' => '10',
        'currency' => 'MWK',
        'currency_symbol' => 'MK',
        'loyalty_enabled' => '1',
        'points_per_order' => '10',
        'points_per_1000' => '5',
        'points_expiry_days' => '180',
        'email_notifications' => '1',
        'sms_notifications' => '1',
        'order_status_sms' => '1',
        'app_name' => 'DropX',
        'app_version' => '2.0.0',
        'maintenance_mode' => '0',
        'max_order_per_user' => '5'
    ];
    
    $resetCount = 0;
    $stmt = $conn->prepare("UPDATE admin_settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = :key");
    
    foreach ($defaults as $key => $value) {
        if ($stmt->execute([':key' => $key, ':value' => $value])) {
            $resetCount++;
        }
    }
    
    $db->sendResponse(['reset_count' => $resetCount], "$resetCount settings reset to defaults");
}

// =============================================
// GET DASHBOARD STATS FROM DATABASE
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    $stats = [];
    
    // Get pending orders count
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
    $stats['pending_orders'] = (int) $stmt->fetchColumn();
    
    // Get active deliveries (orders in progress)
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status IN ('confirmed', 'processing', 'delivering')");
    $stats['active_deliveries'] = (int) $stmt->fetchColumn();
    
    // Get average rating from reviews
    $stmt = $conn->query("SELECT AVG(rating) FROM reviews");
    $avgRating = $stmt->fetchColumn();
    $stats['avg_rating'] = $avgRating ? round($avgRating, 1) : 0;
    
    // Get total customers
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    $stats['total_customers'] = (int) $stmt->fetchColumn();
    
    // Get total merchants
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE status = 'active'");
    $stats['total_merchants'] = (int) $stmt->fetchColumn();
    
    $db->sendResponse(['stats' => $stats]);
}

// =============================================
// GET SINGLE SETTING FROM DATABASE
// =============================================
elseif ($method === 'GET' && $action === 'get-one') {
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    
    if (!$key) {
        $db->sendError("Setting key is required", 400);
    }
    
    $stmt = $conn->prepare("SELECT * FROM admin_settings WHERE setting_key = :key");
    $stmt->execute([':key' => $key]);
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$setting) {
        $db->sendError("Setting not found", 404);
    }
    
    if ($setting['setting_type'] === 'boolean') {
        $setting['setting_value'] = $setting['setting_value'] == '1';
    } elseif ($setting['setting_type'] === 'number') {
        $setting['setting_value'] = floatval($setting['setting_value']);
    }
    
    $db->sendResponse(['setting' => $setting]);
}

// =============================================
// GET CATEGORIES WITH COUNTS
// =============================================
elseif ($method === 'GET' && $action === 'categories') {
    $stmt = $conn->query("
        SELECT category, COUNT(*) as count 
        FROM admin_settings 
        GROUP BY category 
        ORDER BY MIN(sort_order)
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['categories' => $categories]);
}

// =============================================
// UPDATE SINGLE SETTING
// =============================================
elseif ($method === 'POST' && $action === 'update-one') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['key']) || !isset($data['value'])) {
        $db->sendError("Key and value are required", 400);
    }
    
    // Convert boolean to string for database
    $value = $data['value'];
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    
    $stmt = $conn->prepare("UPDATE admin_settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = :key");
    $stmt->execute([':key' => $data['key'], ':value' => $value]);
    
    if ($stmt->rowCount() > 0) {
        $db->sendResponse([], "Setting updated successfully");
    } else {
        $db->sendError("Setting not found or no changes made", 404);
    }
}

else {
    $db->sendError("Invalid action. Available: get, update, reset, stats, get-one, categories, update-one", 400);
}
?>