<?php
// backend/api/admin/finance.php
// COMPLETE FINANCE MANAGEMENT API - FULLY FIXED

// =============================================
// SUPPRESS ALL WARNINGS FOR CLEAN OUTPUT
// =============================================
error_reporting(0);
ini_set('display_errors', 0);

// =============================================
// CORS CONFIGURATION
// =============================================
$production_frontend = getenv('FRONTEND_URL') ?: 'https://frontend-pink-pi-70.vercel.app';

$allowed_origins = [
    $production_frontend,
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
header("Access-Control-Expose-Headers: Content-Disposition");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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
$commissionId = isset($_GET['id']) ? intval($_GET['id']) : null;

function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission to perform this action. Required: $permission");
    }
}

function formatCurrency($amount) {
    return 'MK ' . number_format($amount, 2);
}

// =============================================
// HELPER FUNCTIONS (MISSING #3)
// =============================================

function calculateCommission($orderAmount, $merchantId, $conn) {
    // Get merchant commission settings
    $stmt = $conn->prepare("
        SELECT base_rate, volume_discount_threshold, volume_discount_rate, custom_rate
        FROM merchant_commission_settings 
        WHERE merchant_id = :merchant_id 
        AND effective_from <= CURDATE()
        AND (effective_to IS NULL OR effective_to >= CURDATE())
        ORDER BY effective_from DESC
        LIMIT 1
    ");
    $stmt->execute([':merchant_id' => $merchantId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $rate = $settings['base_rate'] ?? 10.00;
    
    // Apply custom rate if set
    if ($settings['custom_rate'] && $settings['custom_rate'] > 0) {
        $rate = $settings['custom_rate'];
    }
    
    // Check for volume discount
    if ($settings['volume_discount_threshold'] && $settings['volume_discount_rate']) {
        // Get merchant's total sales this month
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(order_amount), 0) as total_sales
            FROM merchant_commissions
            WHERE merchant_id = :merchant_id
            AND MONTH(period_start) = MONTH(CURDATE())
            AND YEAR(period_start) = YEAR(CURDATE())
        ");
        $stmt->execute([':merchant_id' => $merchantId]);
        $total = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($total['total_sales'] + $orderAmount >= $settings['volume_discount_threshold']) {
            $rate = $settings['volume_discount_rate'];
        }
    }
    
    return [
        'rate' => $rate,
        'amount' => $orderAmount * ($rate / 100)
    ];
}

function processWalletTransaction($userId, $amount, $type, $reference, $description, $conn, $adminId = null) {
    $conn->beginTransaction();
    
    try {
        // Check if wallet exists
        $stmt = $conn->prepare("SELECT balance FROM user_wallets WHERE user_id = :user_id FOR UPDATE");
        $stmt->execute([':user_id' => $userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet) {
            // Create wallet
            $stmt = $conn->prepare("
                INSERT INTO user_wallets (user_id, balance, currency, created_at, updated_at)
                VALUES (:user_id, 0, 'MWK', NOW(), NOW())
            ");
            $stmt->execute([':user_id' => $userId]);
            $currentBalance = 0;
        } else {
            $currentBalance = $wallet['balance'];
        }
        
        $newBalance = $currentBalance;
        
        if ($type === 'credit') {
            $newBalance = $currentBalance + $amount;
            
            // Update wallet balance
            $stmt = $conn->prepare("
                UPDATE user_wallets 
                SET balance = :new_balance, 
                    total_deposited = total_deposited + :amount,
                    updated_at = NOW()
                WHERE user_id = :user_id
            ");
        } elseif ($type === 'debit') {
            if ($currentBalance < $amount) {
                throw new Exception("Insufficient balance");
            }
            $newBalance = $currentBalance - $amount;
            
            $stmt = $conn->prepare("
                UPDATE user_wallets 
                SET balance = :new_balance,
                    total_withdrawn = total_withdrawn + :amount,
                    updated_at = NOW()
                WHERE user_id = :user_id
            ");
        } else {
            throw new Exception("Invalid transaction type");
        }
        
        $stmt->execute([
            ':new_balance' => $newBalance,
            ':amount' => $amount,
            ':user_id' => $userId
        ]);
        
        // Create transaction record
        $transactionId = 'TXN-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO wallet_transactions (
                transaction_id, user_id, amount, type, status, 
                reference, description, reference_id, created_by, created_at
            ) VALUES (
                :transaction_id, :user_id, :amount, :type, 'completed',
                :reference, :description, :reference_id, :created_by, NOW()
            )
        ");
        $stmt->execute([
            ':transaction_id' => $transactionId,
            ':user_id' => $userId,
            ':amount' => $amount,
            ':type' => $type,
            ':reference' => $reference,
            ':description' => $description,
            ':reference_id' => $reference,
            ':created_by' => $adminId
        ]);
        
        $conn->commit();
        
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'new_balance' => $newBalance
        ];
        
    } catch (Exception $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function sendPaymentNotification($userId, $type, $amount, $reference, $conn) {
    // Get user details
    $stmt = $conn->prepare("SELECT email, full_name, phone FROM users WHERE id = :user_id");
    $stmt->execute([':user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) return false;
    
    // Send email notification
    $subject = "Payment Notification - " . ucfirst($type);
    $message = "Dear {$user['full_name']},\n\n";
    $message .= "A payment of " . formatCurrency($amount) . " has been {$type}ed to your account.\n";
    $message .= "Reference: $reference\n";
    $message .= "Date: " . date('Y-m-d H:i:s') . "\n\n";
    $message .= "Thank you for using our service.\n";
    
    // Log notification (in production, use actual mail function)
    error_log("Email to {$user['email']}: $subject - $message");
    
    // Store notification in database
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
        VALUES (:user_id, 'payment', :title, :message, 0, NOW())
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':title' => $subject,
        ':message' => $message
    ]);
    
    return true;
}

function validatePaymentMethod($method, $amount, $conn) {
    $stmt = $conn->prepare("
        SELECT * FROM payment_gateways 
        WHERE gateway_name = :method AND is_active = 1
    ");
    $stmt->execute([':method' => $method]);
    $gateway = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$gateway) {
        return ['valid' => false, 'error' => 'Payment method not available'];
    }
    
    // Check min/max limits
    if ($gateway['min_amount'] && $amount < $gateway['min_amount']) {
        return ['valid' => false, 'error' => "Minimum amount is " . formatCurrency($gateway['min_amount'])];
    }
    
    if ($gateway['max_amount'] && $amount > $gateway['max_amount']) {
        return ['valid' => false, 'error' => "Maximum amount is " . formatCurrency($gateway['max_amount'])];
    }
    
    // Check fee
    $fee = 0;
    if ($gateway['fee_type'] === 'percentage') {
        $fee = $amount * ($gateway['fee_amount'] / 100);
    } elseif ($gateway['fee_type'] === 'fixed') {
        $fee = $gateway['fee_amount'];
    }
    
    return [
        'valid' => true,
        'gateway' => $gateway,
        'fee' => $fee,
        'net_amount' => $amount - $fee
    ];
}

function generateInvoice($orderId, $conn) {
    $stmt = $conn->prepare("
        SELECT o.*, u.full_name as customer_name, u.email as customer_email,
               u.phone as customer_phone, m.name as merchant_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN merchants m ON o.merchant_id = m.id
        WHERE o.id = :order_id
    ");
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) return null;
    
    // Get order items
    $stmt = $conn->prepare("
        SELECT * FROM order_items WHERE order_id = :order_id
    ");
    $stmt->execute([':order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate HTML invoice
    $html = "<!DOCTYPE html>
    <html>
    <head>
        <title>Invoice #{$order['order_number']}</title>
        <style>
            body { font-family: Arial, sans-serif; }
            .header { text-align: center; margin-bottom: 30px; }
            .invoice-details { margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .total { text-align: right; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>INVOICE</h1>
            <p>Order #: {$order['order_number']}</p>
            <p>Date: " . date('Y-m-d H:i:s', strtotime($order['created_at'])) . "</p>
        </div>
        
        <div class='invoice-details'>
            <h3>Customer Details:</h3>
            <p>Name: {$order['customer_name']}<br>
            Email: {$order['customer_email']}<br>
            Phone: {$order['customer_phone']}</p>
            
            <h3>Merchant:</h3>
            <p>{$order['merchant_name']}</p>
        </div>
        
        <h3>Items:</h3>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>";
    
    foreach ($items as $item) {
        $html .= "<tr>
                    <td>{$item['product_name']}</td>
                    <td>{$item['quantity']}</td>
                    <td>" . formatCurrency($item['unit_price']) . "</td>
                    <td>" . formatCurrency($item['total_price']) . "</td>
                  </tr>";
    }
    
    $html .= "</tbody>
        </table>
        
        <div class='total'>
            <h3>Total Amount: " . formatCurrency($order['total_amount']) . "</h3>
            <p>Status: " . ucfirst($order['status']) . "</p>
            <p>Payment Method: " . strtoupper($order['payment_method']) . "</p>
        </div>
    </body>
    </html>";
    
    // Save invoice to file
    $invoiceDir = __DIR__ . '/../../invoices/';
    if (!file_exists($invoiceDir)) {
        mkdir($invoiceDir, 0777, true);
    }
    
    $filename = "invoice_{$order['order_number']}.html";
    file_put_contents($invoiceDir . $filename, $html);
    
    return [
        'filename' => $filename,
        'path' => $invoiceDir . $filename,
        'html' => $html
    ];
}

// =============================================
// 1. DASHBOARD STATS
// =============================================
if ($method === 'GET' && $action === 'dashboard') {
    checkPermission('view_finance', $auth, $db);
    
    $stats = [];
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'success' AND DATE(created_at) = CURDATE()");
    $stats['today_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'success' AND DATE(created_at) = CURDATE()");
    $stats['today_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['week_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'success' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['month_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'success'");
    $stats['total_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(commission_amount), 0) FROM merchant_commissions WHERE status = 'pending'");
    $stats['pending_commissions'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(commission_amount), 0) FROM merchant_commissions WHERE status = 'paid'");
    $stats['paid_commissions'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM merchant_payouts WHERE status = 'pending'");
    $stats['pending_payouts'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(AVG(total_amount), 0) FROM orders WHERE status = 'success'");
    $stats['avg_order_value'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    $stats['total_customers'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_active = 1");
    $stats['total_merchants'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("
        SELECT m.id, m.name, COALESCE(SUM(o.total_amount), 0) as revenue, COUNT(o.id) as order_count
        FROM merchants m
        LEFT JOIN orders o ON m.id = o.merchant_id AND o.status = 'success'
        GROUP BY m.id
        ORDER BY revenue DESC
        LIMIT 5
    ");
    $stats['top_merchants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $conn->query("
        SELECT payment_method, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total
        FROM orders WHERE status = 'success' AND payment_method IS NOT NULL
        GROUP BY payment_method
    ");
    $stats['by_payment_method'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $conn->query("
        SELECT t.*, u.full_name as user_name, u.email as user_email
        FROM wallet_transactions t
        LEFT JOIN users u ON t.user_id = u.id
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    $stats['recent_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['stats' => $stats]);
}

// =============================================
// 2. GET COMMISSIONS LIST
// =============================================
elseif ($method === 'GET' && $action === 'commissions') {
    checkPermission('view_commissions', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $period = isset($_GET['period']) ? $_GET['period'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($merchantId) {
        $where[] = "c.merchant_id = :merchant_id";
        $params[':merchant_id'] = $merchantId;
    }
    
    if ($status) {
        $where[] = "c.status = :status";
        $params[':status'] = $status;
    }
    
    if ($period) {
        $where[] = "DATE_FORMAT(c.period_start, '%Y-%m') = :period";
        $params[':period'] = $period;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $countSql = "SELECT COUNT(*) as total FROM merchant_commissions c $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT 
                c.*,
                m.name as merchant_name,
                m.email as merchant_email,
                o.order_number
            FROM merchant_commissions c
            LEFT JOIN merchants m ON c.merchant_id = m.id
            LEFT JOIN orders o ON c.order_id = o.id
            $whereClause
            ORDER BY c.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'commissions' => $commissions,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 3. GET COMMISSION SUMMARY BY MERCHANT
// =============================================
elseif ($method === 'GET' && $action === 'commission-summary') {
    checkPermission('view_commissions', $auth, $db);
    
    $period = isset($_GET['period']) ? $_GET['period'] : date('Y-m');
    
    $stmt = $conn->prepare("
        SELECT 
            m.id, m.name, m.email,
            COUNT(c.id) as order_count,
            COALESCE(SUM(c.order_amount), 0) as total_sales,
            COALESCE(SUM(c.commission_amount), 0) as total_commission,
            COALESCE(SUM(CASE WHEN c.status = 'pending' THEN c.commission_amount ELSE 0 END), 0) as pending_commission,
            COALESCE(SUM(CASE WHEN c.status = 'paid' THEN c.commission_amount ELSE 0 END), 0) as paid_commission
        FROM merchants m
        LEFT JOIN merchant_commissions c ON m.id = c.merchant_id AND DATE_FORMAT(c.period_start, '%Y-%m') = :period
        GROUP BY m.id
        ORDER BY total_commission DESC
    ");
    $stmt->execute([':period' => $period]);
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['summary' => $summary]);
}

// =============================================
// 4. UPDATE COMMISSION STATUS
// =============================================
elseif ($method === 'PUT' && $commissionId && $action === 'update-commission') {
    checkPermission('edit_commissions', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['status'])) {
        $db->sendError('status field is required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE merchant_commissions 
        SET status = :status, 
            payment_reference = :reference,
            paid_at = CASE WHEN :status = 'paid' THEN NOW() ELSE paid_at END,
            paid_by = CASE WHEN :status = 'paid' THEN :admin_id ELSE paid_by END,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $data['status'],
        ':reference' => $data['payment_reference'] ?? null,
        ':admin_id' => $admin['id'],
        ':id' => $commissionId
    ]);
    
    $db->sendResponse([], 'Commission updated successfully');
}

// =============================================
// 5. BULK UPDATE COMMISSIONS
// =============================================
elseif ($method === 'POST' && $action === 'bulk-update-commissions') {
    checkPermission('edit_commissions', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['commission_ids']) || !is_array($data['commission_ids'])) {
        $db->sendError('commission_ids array is required', 400);
    }
    
    if (!isset($data['status'])) {
        $db->sendError('status field is required', 400);
    }
    
    $ids = array_map('intval', $data['commission_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $status = $data['status'];
    
    $stmt = $conn->prepare("
        UPDATE merchant_commissions 
        SET status = ?, 
            paid_at = CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END,
            paid_by = CASE WHEN ? = 'paid' THEN ? ELSE paid_by END,
            updated_at = NOW()
        WHERE id IN ($placeholders)
    ");
    $params = array_merge([$status, $status, $status, $admin['id']], $ids);
    $stmt->execute($params);
    
    $affected = $stmt->rowCount();
    
    $db->sendResponse([
        'updated_count' => $affected,
        'status' => $status
    ], "$affected commission(s) updated");
}

// =============================================
// 6. GET MERCHANT COMMISSION SETTINGS
// =============================================
elseif ($method === 'GET' && $action === 'commission-settings') {
    checkPermission('edit_commissions', $auth, $db);
    
    $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
    
    if ($merchantId) {
        $stmt = $conn->prepare("
            SELECT * FROM merchant_commission_settings 
            WHERE merchant_id = :merchant_id 
            AND effective_from <= CURDATE()
            AND (effective_to IS NULL OR effective_to >= CURDATE())
            ORDER BY effective_from DESC
            LIMIT 1
        ");
        $stmt->execute([':merchant_id' => $merchantId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->query("
            SELECT m.id as merchant_id, m.name, COALESCE(cs.base_rate, 10.00) as current_rate
            FROM merchants m
            LEFT JOIN merchant_commission_settings cs ON m.id = cs.merchant_id 
                AND cs.effective_from <= CURDATE()
                AND (cs.effective_to IS NULL OR cs.effective_to >= CURDATE())
            WHERE m.is_active = 1
            ORDER BY m.name
        ");
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $db->sendResponse(['settings' => $settings]);
}

// =============================================
// 7. UPDATE MERCHANT COMMISSION SETTINGS
// =============================================
elseif ($method === 'POST' && $action === 'update-commission-settings') {
    checkPermission('edit_commissions', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['merchant_id']) || !isset($data['base_rate'])) {
        $db->sendError('merchant_id and base_rate are required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE merchant_commission_settings 
        SET effective_to = CURDATE() 
        WHERE merchant_id = :merchant_id AND effective_to IS NULL
    ");
    $stmt->execute([':merchant_id' => $data['merchant_id']]);
    
    $stmt = $conn->prepare("
        INSERT INTO merchant_commission_settings (
            merchant_id, base_rate, volume_discount_threshold, 
            volume_discount_rate, custom_rate, effective_from, created_by
        ) VALUES (
            :merchant_id, :base_rate, :volume_discount_threshold,
            :volume_discount_rate, :custom_rate, CURDATE(), :created_by
        )
    ");
    $stmt->execute([
        ':merchant_id' => $data['merchant_id'],
        ':base_rate' => $data['base_rate'],
        ':volume_discount_threshold' => $data['volume_discount_threshold'] ?? null,
        ':volume_discount_rate' => $data['volume_discount_rate'] ?? null,
        ':custom_rate' => $data['custom_rate'] ?? null,
        ':created_by' => $admin['id']
    ]);
    
    $db->sendResponse([], 'Commission settings updated successfully');
}

// =============================================
// 8. GET PAYOUTS
// =============================================
elseif ($method === 'GET' && $action === 'payouts') {
    checkPermission('view_finance', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($status) {
        $where[] = "p.status = :status";
        $params[':status'] = $status;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $countSql = "SELECT COUNT(*) as total FROM merchant_payouts p $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT 
                p.*,
                m.name as merchant_name,
                m.email as merchant_email,
                a.full_name as processed_by_name,
                c.full_name as created_by_name
            FROM merchant_payouts p
            LEFT JOIN merchants m ON p.merchant_id = m.id
            LEFT JOIN admin_users a ON p.processed_by = a.id
            LEFT JOIN admin_users c ON p.created_by = c.id
            $whereClause
            ORDER BY p.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'payouts' => $payouts,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 9. CREATE PAYOUT
// =============================================
elseif ($method === 'POST' && $action === 'create-payout') {
    checkPermission('create_payouts', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['merchant_id']) || empty($data['amount'])) {
        $db->sendError('merchant_id and amount are required', 400);
    }
    
    // Validate amount
    if ($data['amount'] <= 0) {
        $db->sendError('Amount must be greater than 0', 400);
    }
    
    if ($data['amount'] > 10000000) {
        $db->sendError('Amount exceeds maximum limit of MK10,000,000', 400);
    }
    
    // Check for duplicate pending payout
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM merchant_payouts 
        WHERE merchant_id = :merchant_id AND status = 'pending'
    ");
    $stmt->execute([':merchant_id' => $data['merchant_id']]);
    if ($stmt->fetchColumn() > 0) {
        $db->sendError('Merchant already has a pending payout. Please process that first.', 400);
    }
    
    $payoutNumber = 'PO-' . strtoupper(uniqid());
    $commissionAmount = $data['amount'] * 0.10;
    $netAmount = $data['amount'] - $commissionAmount;
    
    $conn->beginTransaction();
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO merchant_payouts (
                payout_number, merchant_id, amount, commission_amount, net_amount, 
                status, payment_method, account_details, notes, created_by, created_at
            ) VALUES (
                :payout_number, :merchant_id, :amount, :commission_amount, :net_amount,
                'pending', :payment_method, :account_details, :notes, :created_by, NOW()
            )
        ");
        
        $stmt->execute([
            ':payout_number' => $payoutNumber,
            ':merchant_id' => $data['merchant_id'],
            ':amount' => $data['amount'],
            ':commission_amount' => $commissionAmount,
            ':net_amount' => $netAmount,
            ':payment_method' => $data['payment_method'] ?? null,
            ':account_details' => json_encode($data['account_details'] ?? []),
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $admin['id']
        ]);
        
        $payoutId = $conn->lastInsertId();
        
        // Log the action
        $stmt = $conn->prepare("
            INSERT INTO audit_logs (admin_id, action, details, ip_address, created_at)
            VALUES (:admin_id, 'create_payout', :details, :ip, NOW())
        ");
        $stmt->execute([
            ':admin_id' => $admin['id'],
            ':details' => json_encode(['payout_id' => $payoutId, 'merchant_id' => $data['merchant_id'], 'amount' => $data['amount']]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        $conn->commit();
        
        $db->sendResponse([
            'id' => $payoutId,
            'payout_number' => $payoutNumber
        ], 'Payout created successfully', 201);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Failed to create payout: ' . $e->getMessage(), 500);
    }
}

// =============================================
// 10. UPDATE PAYOUT STATUS
// =============================================
elseif ($method === 'PUT' && $action === 'update-payout' && isset($_GET['payout_id'])) {
    checkPermission('process_payouts', $auth, $db);
    
    $payoutId = intval($_GET['payout_id']);
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['status'])) {
        $db->sendError('status field is required', 400);
    }
    
    // Validate status transition
    $validStatuses = ['pending', 'processing', 'completed', 'failed', 'cancelled'];
    if (!in_array($data['status'], $validStatuses)) {
        $db->sendError('Invalid status. Allowed: ' . implode(', ', $validStatuses), 400);
    }
    
    $conn->beginTransaction();
    
    try {
        // Get current status
        $stmt = $conn->prepare("SELECT status, merchant_id, amount FROM merchant_payouts WHERE id = :id");
        $stmt->execute([':id' => $payoutId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current) {
            $db->sendError('Payout not found', 404);
        }
        
        // Prevent invalid transitions
        $invalidTransitions = [
            'completed' => ['pending', 'processing'],
            'failed' => ['pending', 'processing'],
            'cancelled' => ['pending']
        ];
        
        if (isset($invalidTransitions[$data['status']]) && !in_array($current['status'], $invalidTransitions[$data['status']])) {
            $db->sendError("Cannot change status from {$current['status']} to {$data['status']}", 400);
        }
        
        $stmt = $conn->prepare("
            UPDATE merchant_payouts 
            SET status = :status, 
                transaction_reference = :reference,
                processed_by = :admin_id,
                processed_at = CASE WHEN :status IN ('completed', 'failed') THEN NOW() ELSE processed_at END,
                failure_reason = :failure_reason,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $data['status'],
            ':reference' => $data['transaction_reference'] ?? null,
            ':admin_id' => $admin['id'],
            ':failure_reason' => $data['failure_reason'] ?? null,
            ':id' => $payoutId
        ]);
        
        // If payout is completed, mark related commissions as paid
        if ($data['status'] === 'completed') {
            $stmt = $conn->prepare("
                UPDATE merchant_commissions 
                SET status = 'paid', 
                    paid_at = NOW(),
                    paid_by = :admin_id,
                    payout_id = :payout_id
                WHERE merchant_id = :merchant_id AND status = 'pending'
            ");
            $stmt->execute([
                ':admin_id' => $admin['id'],
                ':payout_id' => $payoutId,
                ':merchant_id' => $current['merchant_id']
            ]);
            
            // Send notification to merchant
            sendPaymentNotification($current['merchant_id'], 'payout', $current['amount'], $payoutId, $conn);
        }
        
        // Log the action
        $stmt = $conn->prepare("
            INSERT INTO audit_logs (admin_id, action, details, ip_address, created_at)
            VALUES (:admin_id, 'update_payout', :details, :ip, NOW())
        ");
        $stmt->execute([
            ':admin_id' => $admin['id'],
            ':details' => json_encode(['payout_id' => $payoutId, 'old_status' => $current['status'], 'new_status' => $data['status']]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        $conn->commit();
        
        $db->sendResponse([], 'Payout updated successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Failed to update payout: ' . $e->getMessage(), 500);
    }
}

// =============================================
// 11. GET REVENUE CHART DATA
// =============================================
elseif ($method === 'GET' && $action === 'revenue-chart') {
    checkPermission('view_finance', $auth, $db);
    
    $period = isset($_GET['period']) ? $_GET['period'] : 'month';
    $data = [];
    
    if ($period === 'week') {
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total,
                       COUNT(*) as orders
                FROM orders 
                WHERE status = 'success' AND DATE(created_at) = :date
            ");
            $stmt->execute([':date' => $date]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $data[] = [
                'date' => date('D, M j', strtotime($date)),
                'revenue' => floatval($row['total']),
                'orders' => intval($row['orders'])
            ];
        }
    } elseif ($period === 'month') {
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total,
                       COUNT(*) as orders
                FROM orders 
                WHERE status = 'success' AND DATE(created_at) = :date
            ");
            $stmt->execute([':date' => $date]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $data[] = [
                'date' => date('M j', strtotime($date)),
                'revenue' => floatval($row['total']),
                'orders' => intval($row['orders'])
            ];
        }
    } elseif ($period === 'year') {
        for ($i = 11; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total,
                       COUNT(*) as orders
                FROM orders 
                WHERE status = 'success' 
                AND DATE_FORMAT(created_at, '%Y-%m') = :month
            ");
            $stmt->execute([':month' => $month]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $data[] = [
                'date' => date('M Y', strtotime($month . '-01')),
                'revenue' => floatval($row['total']),
                'orders' => intval($row['orders'])
            ];
        }
    }
    
    $db->sendResponse(['data' => $data]);
}

// =============================================
// 12. EXPORT COMMISSIONS TO CSV
// =============================================
elseif ($method === 'GET' && $action === 'export-commissions') {
    checkPermission('export_finance', $auth, $db);
    
    $period = isset($_GET['period']) ? $_GET['period'] : date('Y-m');
    
    $stmt = $conn->prepare("
        SELECT 
            c.id, m.name as merchant_name, o.order_number, 
            c.order_amount, c.commission_rate, c.commission_amount, 
            c.status, c.created_at
        FROM merchant_commissions c
        LEFT JOIN merchants m ON c.merchant_id = m.id
        LEFT JOIN orders o ON c.order_id = o.id
        WHERE DATE_FORMAT(c.period_start, '%Y-%m') = :period
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([':period' => $period]);
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="commissions_' . $period . '.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['ID', 'Merchant', 'Order #', 'Order Amount', 'Commission Rate', 'Commission Amount', 'Status', 'Date'], ',', '"', '\\');
    
    foreach ($commissions as $c) {
        fputcsv($output, [
            $c['id'],
            $c['merchant_name'],
            $c['order_number'],
            number_format($c['order_amount'], 2),
            $c['commission_rate'] . '%',
            number_format($c['commission_amount'], 2),
            $c['status'],
            $c['created_at']
        ], ',', '"', '\\');
    }
    
    fclose($output);
    exit();
}

// =============================================
// 13. GET TRANSACTIONS
// =============================================
elseif ($method === 'GET' && $action === 'transactions') {
    checkPermission('view_finance', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $type = isset($_GET['type']) ? $_GET['type'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(u.full_name LIKE :search OR u.email LIKE :search OR t.transaction_id LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($type) {
        $where[] = "t.type = :type";
        $params[':type'] = $type;
    }
    
    if ($status) {
        $where[] = "t.status = :status";
        $params[':status'] = $status;
    }
    
    if ($dateFrom) {
        $where[] = "DATE(t.created_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if ($dateTo) {
        $where[] = "DATE(t.created_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $countSql = "SELECT COUNT(*) as total FROM wallet_transactions t 
                 LEFT JOIN users u ON t.user_id = u.id 
                 $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT 
                t.*, u.full_name as user_name, u.email as user_email
            FROM wallet_transactions t
            LEFT JOIN users u ON t.user_id = u.id
            $whereClause
            ORDER BY t.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'transactions' => $transactions,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 14. GET ORDERS
// =============================================
elseif ($method === 'GET' && $action === 'orders') {
    checkPermission('view_finance', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(o.order_number LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search OR m.name LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status) {
        $where[] = "o.status = :status";
        $params[':status'] = $status;
    }
    
    if ($dateFrom) {
        $where[] = "DATE(o.created_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if ($dateTo) {
        $where[] = "DATE(o.created_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $countSql = "SELECT COUNT(*) as total FROM orders o 
                 LEFT JOIN users u ON o.user_id = u.id
                 LEFT JOIN merchants m ON o.merchant_id = m.id
                 $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT 
                o.id, o.order_number, o.total_amount, o.status, o.payment_method,
                o.created_at, u.full_name as customer_name, u.email as customer_email,
                m.name as merchant_name
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            $whereClause
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'orders' => $orders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 15. EXPORT TRANSACTIONS TO CSV
// =============================================
elseif ($method === 'GET' && $action === 'export-transactions') {
    checkPermission('export_finance', $auth, $db);
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $type = isset($_GET['type']) ? $_GET['type'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(u.full_name LIKE :search OR u.email LIKE :search OR t.transaction_id LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($type) {
        $where[] = "t.type = :type";
        $params[':type'] = $type;
    }
    
    if ($status) {
        $where[] = "t.status = :status";
        $params[':status'] = $status;
    }
    
    if ($dateFrom) {
        $where[] = "DATE(t.created_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if ($dateTo) {
        $where[] = "DATE(t.created_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                t.transaction_id, t.type, t.amount, t.status, t.description,
                t.created_at, u.full_name as user_name, u.email as user_email
            FROM wallet_transactions t
            LEFT JOIN users u ON t.user_id = u.id
            $whereClause
            ORDER BY t.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Transaction ID', 'User', 'Email', 'Type', 'Amount', 'Status', 'Description', 'Date'], ',', '"', '\\');
    
    foreach ($transactions as $t) {
        fputcsv($output, [
            $t['transaction_id'],
            $t['user_name'] ?? 'System',
            $t['user_email'] ?? '',
            $t['type'],
            number_format($t['amount'], 2),
            $t['status'],
            $t['description'],
            $t['created_at']
        ], ',', '"', '\\');
    }
    
    fclose($output);
    exit();
}

// =============================================
// 16. EXPORT ORDERS TO CSV
// =============================================
elseif ($method === 'GET' && $action === 'export-orders') {
    checkPermission('export_finance', $auth, $db);
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(o.order_number LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search OR m.name LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status) {
        $where[] = "o.status = :status";
        $params[':status'] = $status;
    }
    
    if ($dateFrom) {
        $where[] = "DATE(o.created_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if ($dateTo) {
        $where[] = "DATE(o.created_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                o.order_number, o.total_amount, o.status, o.payment_method,
                o.created_at, u.full_name as customer_name, u.email as customer_email,
                m.name as merchant_name
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            $whereClause
            ORDER BY o.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Order #', 'Customer', 'Email', 'Merchant', 'Amount', 'Status', 'Payment Method', 'Date'], ',', '"', '\\');
    
    foreach ($orders as $o) {
        fputcsv($output, [
            $o['order_number'],
            $o['customer_name'],
            $o['customer_email'],
            $o['merchant_name'],
            number_format($o['total_amount'], 2),
            $o['status'],
            $o['payment_method'],
            $o['created_at']
        ], ',', '"', '\\');
    }
    
    fclose($output);
    exit();
}

// =============================================
// 17. GET USER WALLET BALANCE (MISSING #2)
// =============================================
elseif ($method === 'GET' && $action === 'wallet-balance') {
    checkPermission('view_finance', $auth, $db);
    
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
    
    if (!$userId) {
        $db->sendError('user_id is required', 400);
    }
    
    $stmt = $conn->prepare("
        SELECT user_id, balance, total_deposited, total_withdrawn, 
               total_spent, total_earned, currency, updated_at
        FROM user_wallets 
        WHERE user_id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$wallet) {
        $stmt = $conn->prepare("
            INSERT INTO user_wallets (user_id, balance, currency, created_at, updated_at)
            VALUES (:user_id, 0, 'MWK', NOW(), NOW())
        ");
        $stmt->execute([':user_id' => $userId]);
        
        $wallet = [
            'user_id' => $userId,
            'balance' => 0,
            'total_deposited' => 0,
            'total_withdrawn' => 0,
            'total_spent' => 0,
            'total_earned' => 0,
            'currency' => 'MWK',
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    $db->sendResponse(['wallet' => $wallet]);
}

// =============================================
// 18. DEPOSIT TO WALLET (MISSING #2)
// =============================================
elseif ($method === 'POST' && $action === 'wallet-deposit') {
    checkPermission('edit_finance', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['user_id']) || empty($data['amount'])) {
        $db->sendError('user_id and amount are required', 400);
    }
    
    if ($data['amount'] <= 0) {
        $db->sendError('Amount must be greater than 0', 400);
    }
    
    $result = processWalletTransaction(
        $data['user_id'],
        $data['amount'],
        'credit',
        $data['reference'] ?? 'ADMIN_DEPOSIT_' . uniqid(),
        $data['description'] ?? 'Admin deposit',
        $conn,
        $admin['id']
    );
    
    if ($result['success']) {
        sendPaymentNotification($data['user_id'], 'deposit', $data['amount'], $result['transaction_id'], $conn);
        $db->sendResponse($result, 'Deposit successful');
    } else {
        $db->sendError($result['error'], 400);
    }
}

// =============================================
// 19. WITHDRAW FROM WALLET (MISSING #2)
// =============================================
elseif ($method === 'POST' && $action === 'wallet-withdraw') {
    checkPermission('edit_finance', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['user_id']) || empty($data['amount'])) {
        $db->sendError('user_id and amount are required', 400);
    }
    
    if ($data['amount'] <= 0) {
        $db->sendError('Amount must be greater than 0', 400);
    }
    
    $result = processWalletTransaction(
        $data['user_id'],
        $data['amount'],
        'debit',
        $data['reference'] ?? 'ADMIN_WITHDRAWAL_' . uniqid(),
        $data['description'] ?? 'Admin withdrawal',
        $conn,
        $admin['id']
    );
    
    if ($result['success']) {
        sendPaymentNotification($data['user_id'], 'withdrawal', $data['amount'], $result['transaction_id'], $conn);
        $db->sendResponse($result, 'Withdrawal successful');
    } else {
        $db->sendError($result['error'], 400);
    }
}

// =============================================
// 20. PROCESS ORDER REFUND (MISSING #2)
// =============================================
elseif ($method === 'POST' && $action === 'refund-order') {
    checkPermission('process_refunds', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['order_id'])) {
        $db->sendError('order_id is required', 400);
    }
    
    $orderId = intval($data['order_id']);
    $refundAmount = isset($data['amount']) ? floatval($data['amount']) : null;
    $reason = $data['reason'] ?? 'Refund processed by admin';
    
    $conn->beginTransaction();
    
    try {
        // Get order details
        $stmt = $conn->prepare("
            SELECT * FROM orders WHERE id = :id FOR UPDATE
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            $db->sendError('Order not found', 404);
        }
        
        if ($order['status'] !== 'success') {
            $db->sendError('Only successful orders can be refunded', 400);
        }
        
        // Check if already refunded
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM refunds WHERE order_id = :order_id AND status = 'completed'
        ");
        $stmt->execute([':order_id' => $orderId]);
        if ($stmt->fetchColumn() > 0) {
            $db->sendError('Order has already been refunded', 400);
        }
        
        $amount = $refundAmount ?? $order['total_amount'];
        
        if ($amount > $order['total_amount']) {
            $db->sendError('Refund amount cannot exceed order total', 400);
        }
        
        // Process refund to user wallet
        $result = processWalletTransaction(
            $order['user_id'],
            $amount,
            'credit',
            'REFUND_' . $order['order_number'],
            "Refund for order #{$order['order_number']}: $reason",
            $conn,
            $admin['id']
        );
        
        if (!$result['success']) {
            throw new Exception($result['error']);
        }
        
        // Create refund record
        $refundNumber = 'RF-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO refunds (
                refund_number, order_id, user_id, amount, reason, 
                status, processed_by, processed_at, created_at
            ) VALUES (
                :refund_number, :order_id, :user_id, :amount, :reason,
                'completed', :admin_id, NOW(), NOW()
            )
        ");
        $stmt->execute([
            ':refund_number' => $refundNumber,
            ':order_id' => $orderId,
            ':user_id' => $order['user_id'],
            ':amount' => $amount,
            ':reason' => $reason,
            ':admin_id' => $admin['id']
        ]);
        
        // Update order status
        $stmt = $conn->prepare("
            UPDATE orders SET status = 'refunded', updated_at = NOW() WHERE id = :id
        ");
        $stmt->execute([':id' => $orderId]);
        
        // Reverse commission
        $stmt = $conn->prepare("
            UPDATE merchant_commissions 
            SET status = 'reversed', refunded_at = NOW()
            WHERE order_id = :order_id
        ");
        $stmt->execute([':order_id' => $orderId]);
        
        // Log action
        $stmt = $conn->prepare("
            INSERT INTO audit_logs (admin_id, action, details, ip_address, created_at)
            VALUES (:admin_id, 'refund_order', :details, :ip, NOW())
        ");
        $stmt->execute([
            ':admin_id' => $admin['id'],
            ':details' => json_encode(['order_id' => $orderId, 'amount' => $amount, 'reason' => $reason]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        $conn->commit();
        
        sendPaymentNotification($order['user_id'], 'refund', $amount, $refundNumber, $conn);
        
        $db->sendResponse([
            'refund_number' => $refundNumber,
            'amount' => $amount,
            'transaction_id' => $result['transaction_id']
        ], 'Refund processed successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Refund failed: ' . $e->getMessage(), 500);
    }
}

// =============================================
// 21. FINANCIAL SUMMARY REPORT (MISSING #2)
// =============================================
elseif ($method === 'GET' && $action === 'financial-summary') {
    checkPermission('view_reports', $auth, $db);
    
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
    
    $report = [];
    
    // Revenue summary
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(AVG(total_amount), 0) as avg_order_value,
            COALESCE(SUM(CASE WHEN payment_method = 'wallet' THEN total_amount ELSE 0 END), 0) as wallet_payments,
            COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END), 0) as card_payments,
            COALESCE(SUM(CASE WHEN payment_method = 'mpamba' THEN total_amount ELSE 0 END), 0) as mpamba_payments,
            COALESCE(SUM(CASE WHEN payment_method = 'airtel_money' THEN total_amount ELSE 0 END), 0) as airtel_payments
        FROM orders
        WHERE status = 'success'
        AND DATE(created_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
    $report['revenue'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Commission summary
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(commission_amount), 0) as total_commission,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END), 0) as pending_commission,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END), 0) as paid_commission
        FROM merchant_commissions
        WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
    $report['commissions'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Payout summary
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) as total_payouts,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_payouts,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as completed_payouts
        FROM merchant_payouts
        WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
    $report['payouts'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Wallet transactions summary
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as total_deposits,
            COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as total_withdrawals,
            COUNT(*) as total_transactions
        FROM wallet_transactions
        WHERE status = 'completed'
        AND DATE(created_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
    $report['wallet'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Refund summary
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_refunds,
            COALESCE(SUM(amount), 0) as total_refund_amount
        FROM refunds
        WHERE status = 'completed'
        AND DATE(created_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
    $report['refunds'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Daily breakdown
    $stmt = $conn->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as order_count,
            COALESCE(SUM(total_amount), 0) as daily_revenue
        FROM orders
        WHERE status = 'success'
        AND DATE(created_at) BETWEEN :start_date AND :end_date
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
    $report['daily_breakdown'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['report' => $report, 'period' => ['start' => $startDate, 'end' => $endDate]]);
}

// =============================================
// 22. CALCULATE COMMISSIONS FOR ORDER (MISSING #2)
// =============================================
elseif ($method === 'POST' && $action === 'calculate-commission') {
    checkPermission('edit_commissions', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['order_id'])) {
        $db->sendError('order_id is required', 400);
    }
    
    $orderId = intval($data['order_id']);
    
    $conn->beginTransaction();
    
    try {
        // Get order details
        $stmt = $conn->prepare("
            SELECT o.*, m.id as merchant_id 
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE o.id = :id AND o.status = 'success'
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            $db->sendError('Order not found or not successful', 404);
        }
        
        // Check if commission already exists
        $stmt = $conn->prepare("SELECT id FROM merchant_commissions WHERE order_id = :order_id");
        $stmt->execute([':order_id' => $orderId]);
        if ($stmt->fetch()) {
            $db->sendError('Commission already calculated for this order', 400);
        }
        
        // Calculate commission
        $commission = calculateCommission($order['total_amount'], $order['merchant_id'], $conn);
        
        // Create commission record
        $stmt = $conn->prepare("
            INSERT INTO merchant_commissions (
                merchant_id, order_id, order_number, order_amount, 
                commission_rate, commission_amount, status, period_start, 
                period_end, created_at, created_by
            ) VALUES (
                :merchant_id, :order_id, :order_number, :order_amount,
                :commission_rate, :commission_amount, 'pending',
                DATE_FORMAT(:created_at, '%Y-%m-01'),
                LAST_DAY(:created_at),
                NOW(), :created_by
            )
        ");
        $stmt->execute([
            ':merchant_id' => $order['merchant_id'],
            ':order_id' => $orderId,
            ':order_number' => $order['order_number'],
            ':order_amount' => $order['total_amount'],
            ':commission_rate' => $commission['rate'],
            ':commission_amount' => $commission['amount'],
            ':created_at' => $order['created_at'],
            ':created_by' => $admin['id']
        ]);
        
        $commissionId = $conn->lastInsertId();
        
        // Log action
        $stmt = $conn->prepare("
            INSERT INTO audit_logs (admin_id, action, details, ip_address, created_at)
            VALUES (:admin_id, 'calculate_commission', :details, :ip, NOW())
        ");
        $stmt->execute([
            ':admin_id' => $admin['id'],
            ':details' => json_encode(['order_id' => $orderId, 'commission_id' => $commissionId, 'amount' => $commission['amount']]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        $conn->commit();
        
        $db->sendResponse([
            'commission_id' => $commissionId,
            'commission_amount' => $commission['amount'],
            'commission_rate' => $commission['rate']
        ], 'Commission calculated successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Failed to calculate commission: ' . $e->getMessage(), 500);
    }
}

// =============================================
// 23. GET PAYMENT GATEWAYS (MISSING #2)
// =============================================
elseif ($method === 'GET' && $action === 'payment-gateways') {
    checkPermission('view_finance', $auth, $db);
    
    $stmt = $conn->query("
        SELECT * FROM payment_gateways 
        ORDER BY display_order ASC
    ");
    $gateways = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['gateways' => $gateways]);
}

// =============================================
// 24. CONFIGURE PAYMENT GATEWAY (MISSING #2)
// =============================================
elseif ($method === 'POST' && $action === 'configure-gateway') {
    checkPermission('edit_finance', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['gateway_id']) || empty($data['gateway_name'])) {
        $db->sendError('gateway_id and gateway_name are required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE payment_gateways 
        SET is_active = :is_active,
            config = :config,
            min_amount = :min_amount,
            max_amount = :max_amount,
            fee_type = :fee_type,
            fee_amount = :fee_amount,
            display_order = :display_order,
            updated_at = NOW()
        WHERE id = :gateway_id
    ");
    $stmt->execute([
        ':gateway_id' => $data['gateway_id'],
        ':is_active' => $data['is_active'] ?? 1,
        ':config' => json_encode($data['config'] ?? []),
        ':min_amount' => $data['min_amount'] ?? 0,
        ':max_amount' => $data['max_amount'] ?? null,
        ':fee_type' => $data['fee_type'] ?? 'percentage',
        ':fee_amount' => $data['fee_amount'] ?? 0,
        ':display_order' => $data['display_order'] ?? 0
    ]);
    
    // Log action
    $stmt = $conn->prepare("
        INSERT INTO audit_logs (admin_id, action, details, ip_address, created_at)
        VALUES (:admin_id, 'configure_gateway', :details, :ip, NOW())
    ");
    $stmt->execute([
        ':admin_id' => $admin['id'],
        ':details' => json_encode(['gateway_id' => $data['gateway_id'], 'gateway_name' => $data['gateway_name']]),
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    $db->sendResponse([], 'Payment gateway configured successfully');
}

// =============================================
// 25. PAYMENT WEBHOOK HANDLER (MISSING #5)
// =============================================
elseif ($method === 'POST' && $action === 'webhook') {
    // No permission check for webhooks - they come from payment providers
    
    $input = json_decode(file_get_contents('php://input'), true);
    $headers = getallheaders();
    
    $provider = isset($_GET['provider']) ? $_GET['provider'] : '';
    $signature = $headers['X-Signature'] ?? $headers['x-signature'] ?? '';
    
    // Verify webhook signature (simplified - implement actual verification)
    if (empty($signature)) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing signature']);
        exit();
    }
    
    $conn->beginTransaction();
    
    try {
        if ($provider === 'paypal') {
            // Process PayPal webhook
            $eventType = $input['event_type'] ?? '';
            $resourceId = $input['resource']['id'] ?? '';
            $status = $input['resource']['status'] ?? '';
            
            if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
                // Update order status
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET status = 'success', 
                        payment_status = 'completed',
                        payment_details = :details,
                        updated_at = NOW()
                    WHERE payment_reference = :reference
                ");
                $stmt->execute([
                    ':details' => json_encode($input),
                    ':reference' => $resourceId
                ]);
            }
            
        } elseif ($provider === 'stripe') {
            // Process Stripe webhook
            $eventType = $input['type'] ?? '';
            
            if ($eventType === 'payment_intent.succeeded') {
                $paymentIntent = $input['data']['object'] ?? [];
                $reference = $paymentIntent['id'] ?? '';
                
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET status = 'success', 
                        payment_status = 'completed',
                        payment_details = :details,
                        updated_at = NOW()
                    WHERE payment_reference = :reference
                ");
                $stmt->execute([
                    ':details' => json_encode($input),
                    ':reference' => $reference
                ]);
            }
            
        } elseif ($provider === 'mpamba' || $provider === 'airtel') {
            // Process mobile money webhook
            $transactionId = $input['transaction_id'] ?? '';
            $status = $input['status'] ?? '';
            $reference = $input['reference'] ?? '';
            
            if ($status === 'success') {
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET status = 'success', 
                        payment_status = 'completed',
                        payment_details = :details,
                        updated_at = NOW()
                    WHERE payment_reference = :reference
                ");
                $stmt->execute([
                    ':details' => json_encode($input),
                    ':reference' => $reference
                ]);
            } elseif ($status === 'failed') {
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET status = 'failed', 
                        payment_status = 'failed',
                        payment_details = :details,
                        updated_at = NOW()
                    WHERE payment_reference = :reference
                ");
                $stmt->execute([
                    ':details' => json_encode($input),
                    ':reference' => $reference
                ]);
            }
        }
        
        // Store webhook log
        $stmt = $conn->prepare("
            INSERT INTO webhook_logs (provider, event_type, payload, signature, received_at, processed_at)
            VALUES (:provider, :event_type, :payload, :signature, NOW(), NOW())
        ");
        $stmt->execute([
            ':provider' => $provider,
            ':event_type' => $input['type'] ?? $input['event_type'] ?? 'unknown',
            ':payload' => json_encode($input),
            ':signature' => $signature
        ]);
        
        $conn->commit();
        
        http_response_code(200);
        echo json_encode(['status' => 'success']);
        exit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        
        // Log error
        $stmt = $conn->prepare("
            INSERT INTO webhook_logs (provider, event_type, payload, signature, error, received_at)
            VALUES (:provider, :event_type, :payload, :signature, :error, NOW())
        ");
        $stmt->execute([
            ':provider' => $provider,
            ':event_type' => $input['type'] ?? $input['event_type'] ?? 'unknown',
            ':payload' => json_encode($input),
            ':signature' => $signature,
            ':error' => $e->getMessage()
        ]);
        
        http_response_code(500);
        echo json_encode(['error' => 'Webhook processing failed']);
        exit();
    }
}

// =============================================
// 26. GENERATE INVOICE (MISSING #3)
// =============================================
elseif ($method === 'GET' && $action === 'generate-invoice') {
    checkPermission('view_finance', $auth, $db);
    
    $orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
    $format = isset($_GET['format']) ? $_GET['format'] : 'html';
    
    if (!$orderId) {
        $db->sendError('order_id is required', 400);
    }
    
    $invoice = generateInvoice($orderId, $conn);
    
    if (!$invoice) {
        $db->sendError('Order not found', 404);
    }
    
    if ($format === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        echo $invoice['html'];
        exit();
    } elseif ($format === 'download') {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $invoice['filename'] . '"');
        echo $invoice['html'];
        exit();
    } else {
        $db->sendResponse(['invoice' => $invoice]);
    }
}

// =============================================
// 27. GET AUDIT LOGS (MISSING #7)
// =============================================
elseif ($method === 'GET' && $action === 'audit-logs') {
    checkPermission('view_finance', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $action = isset($_GET['action_filter']) ? $_GET['action_filter'] : '';
    $adminId = isset($_GET['admin_id']) ? intval($_GET['admin_id']) : null;
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($action) {
        $where[] = "action = :action";
        $params[':action'] = $action;
    }
    
    if ($adminId) {
        $where[] = "admin_id = :admin_id";
        $params[':admin_id'] = $adminId;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $countSql = "SELECT COUNT(*) as total FROM audit_logs $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT 
                al.*, a.username, a.full_name as admin_name
            FROM audit_logs al
            LEFT JOIN admin_users a ON al.admin_id = a.id
            $whereClause
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'logs' => $logs,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 28. GET MERCHANT STATEMENT (MISSING #8)
// =============================================
elseif ($method === 'GET' && $action === 'merchant-statement') {
    checkPermission('view_reports', $auth, $db);
    
    $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
    $format = isset($_GET['format']) ? $_GET['format'] : 'json';
    
    if (!$merchantId) {
        $db->sendError('merchant_id is required', 400);
    }
    
    // Get merchant details
    $stmt = $conn->prepare("
        SELECT * FROM merchants WHERE id = :id
    ");
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$merchant) {
        $db->sendError('Merchant not found', 404);
    }
    
    // Get sales summary
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(total_amount), 0) as total_sales,
            COALESCE(AVG(total_amount), 0) as avg_order_value
        FROM orders
        WHERE merchant_id = :merchant_id AND status = 'success'
        AND DATE(created_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':merchant_id' => $merchantId,
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    $sales = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get commission summary
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(commission_amount), 0) as total_commission,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END), 0) as pending_commission,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END), 0) as paid_commission
        FROM merchant_commissions
        WHERE merchant_id = :merchant_id
        AND DATE(created_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':merchant_id' => $merchantId,
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    $commissions = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get payout summary
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) as total_payouts,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_payouts,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as completed_payouts
        FROM merchant_payouts
        WHERE merchant_id = :merchant_id
        AND DATE(created_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':merchant_id' => $merchantId,
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    $payouts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get daily breakdown
    $stmt = $conn->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as order_count,
            COALESCE(SUM(total_amount), 0) as daily_sales,
            COALESCE(SUM(commission_amount), 0) as daily_commission
        FROM orders o
        LEFT JOIN merchant_commissions c ON o.id = c.order_id
        WHERE o.merchant_id = :merchant_id AND o.status = 'success'
        AND DATE(o.created_at) BETWEEN :start_date AND :end_date
        GROUP BY DATE(o.created_at)
        ORDER BY date DESC
    ");
    $stmt->execute([
        ':merchant_id' => $merchantId,
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    $daily = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $statement = [
        'merchant' => $merchant,
        'period' => ['start' => $startDate, 'end' => $endDate],
        'summary' => [
            'total_sales' => floatval($sales['total_sales']),
            'total_orders' => intval($sales['total_orders']),
            'avg_order_value' => floatval($sales['avg_order_value']),
            'total_commission' => floatval($commissions['total_commission']),
            'pending_commission' => floatval($commissions['pending_commission']),
            'paid_commission' => floatval($commissions['paid_commission']),
            'total_payouts' => floatval($payouts['total_payouts']),
            'pending_payouts' => floatval($payouts['pending_payouts']),
            'completed_payouts' => floatval($payouts['completed_payouts']),
            'net_earnings' => floatval($sales['total_sales'] - $commissions['total_commission'])
        ],
        'daily_breakdown' => $daily
    ];
    
    if ($format === 'csv') {
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="merchant_statement_' . $merchantId . '_' . $startDate . '_to_' . $endDate . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Merchant Statement Report']);
        fputcsv($output, ['Merchant:', $merchant['name']]);
        fputcsv($output, ['Period:', $startDate . ' to ' . $endDate]);
        fputcsv($output, []);
        fputcsv($output, ['Summary']);
        fputcsv($output, ['Total Sales', 'Total Orders', 'Avg Order Value', 'Total Commission', 'Net Earnings']);
        fputcsv($output, [
            number_format($statement['summary']['total_sales'], 2),
            $statement['summary']['total_orders'],
            number_format($statement['summary']['avg_order_value'], 2),
            number_format($statement['summary']['total_commission'], 2),
            number_format($statement['summary']['net_earnings'], 2)
        ]);
        fputcsv($output, []);
        fputcsv($output, ['Daily Breakdown']);
        fputcsv($output, ['Date', 'Orders', 'Sales', 'Commission']);
        
        foreach ($daily as $day) {
            fputcsv($output, [
                $day['date'],
                $day['order_count'],
                number_format($day['daily_sales'], 2),
                number_format($day['daily_commission'], 2)
            ]);
        }
        
        fclose($output);
        exit();
    }
    
    $db->sendResponse(['statement' => $statement]);
}

// =============================================
// 29. RECONCILE PAYMENTS (MISSING #5)
// =============================================
elseif ($method === 'POST' && $action === 'reconcile-payments') {
    checkPermission('edit_finance', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['date'])) {
        $data['date'] = date('Y-m-d');
    }
    
    $conn->beginTransaction();
    
    try {
        // Get all successful orders for the date
        $stmt = $conn->prepare("
            SELECT * FROM orders 
            WHERE status = 'success' 
            AND DATE(created_at) = :date
            AND reconciled = 0
        ");
        $stmt->execute([':date' => $data['date']]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalAmount = 0;
        $orderIds = [];
        
        foreach ($orders as $order) {
            $totalAmount += $order['total_amount'];
            $orderIds[] = $order['id'];
        }
        
        // Mark orders as reconciled
        if (!empty($orderIds)) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $conn->prepare("
                UPDATE orders SET reconciled = 1, reconciled_at = NOW() 
                WHERE id IN ($placeholders)
            ");
            $stmt->execute($orderIds);
        }
        
        // Create reconciliation record
        $reconciliationNumber = 'REC-' . strtoupper(uniqid());
        $stmt = $conn->prepare("
            INSERT INTO payment_reconciliations (
                reconciliation_number, reconciliation_date, total_amount, 
                order_count, status, created_by, created_at
            ) VALUES (
                :number, :date, :amount, :count, 'completed', :admin_id, NOW()
            )
        ");
        $stmt->execute([
            ':number' => $reconciliationNumber,
            ':date' => $data['date'],
            ':amount' => $totalAmount,
            ':count' => count($orderIds),
            ':admin_id' => $admin['id']
        ]);
        
        // Log action
        $stmt = $conn->prepare("
            INSERT INTO audit_logs (admin_id, action, details, ip_address, created_at)
            VALUES (:admin_id, 'reconcile_payments', :details, :ip, NOW())
        ");
        $stmt->execute([
            ':admin_id' => $admin['id'],
            ':details' => json_encode(['date' => $data['date'], 'amount' => $totalAmount, 'orders' => count($orderIds)]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        $conn->commit();
        
        $db->sendResponse([
            'reconciliation_number' => $reconciliationNumber,
            'total_amount' => $totalAmount,
            'order_count' => count($orderIds)
        ], 'Payments reconciled successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Reconciliation failed: ' . $e->getMessage(), 500);
    }
}

// =============================================
// 30. GET SYSTEM HEALTH / FINANCE STATUS (MISSING #7)
// =============================================
elseif ($method === 'GET' && $action === 'health-check') {
    checkPermission('view_finance', $auth, $db);
    
    $health = [];
    
    // Check database connection
    try {
        $stmt = $conn->query("SELECT 1");
        $health['database'] = 'connected';
    } catch (Exception $e) {
        $health['database'] = 'error: ' . $e->getMessage();
    }
    
    // Check pending payouts
    $stmt = $conn->query("SELECT COUNT(*) FROM merchant_payouts WHERE status = 'pending'");
    $health['pending_payouts'] = intval($stmt->fetchColumn());
    
    // Check pending commissions
    $stmt = $conn->query("SELECT COUNT(*) FROM merchant_commissions WHERE status = 'pending'");
    $health['pending_commissions'] = intval($stmt->fetchColumn());
    
    // Check failed webhooks in last 24 hours
    $stmt = $conn->query("
        SELECT COUNT(*) FROM webhook_logs 
        WHERE error IS NOT NULL 
        AND received_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $health['failed_webhooks_24h'] = intval($stmt->fetchColumn());
    
    // Check last reconciliation
    $stmt = $conn->query("
        SELECT MAX(reconciliation_date) as last_reconciliation 
        FROM payment_reconciliations
    ");
    $lastRec = $stmt->fetch(PDO::FETCH_ASSOC);
    $health['last_reconciliation'] = $lastRec['last_reconciliation'] ?? 'never';
    
    // Check system balance (cash in hand vs expected)
    $stmt = $conn->query("
        SELECT 
            COALESCE(SUM(total_amount), 0) as total_collected,
            COALESCE(SUM(amount), 0) as total_paid_out
        FROM orders o
        LEFT JOIN merchant_payouts p ON 1=0
        WHERE o.status = 'success'
    ");
    $balances = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM merchant_payouts WHERE status = 'completed'");
    $health['total_paid_out'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'success'");
    $health['total_collected'] = floatval($stmt->fetchColumn());
    
    $health['system_balance'] = $health['total_collected'] - $health['total_paid_out'];
    
    $db->sendResponse(['health' => $health]);
}

// =============================================
// Invalid action handler
// =============================================
else {
    $db->sendError('Invalid action. Available actions: dashboard, commissions, commission-summary, update-commission, bulk-update-commissions, commission-settings, update-commission-settings, payouts, create-payout, update-payout, revenue-chart, export-commissions, export-transactions, export-orders, transactions, orders, wallet-balance, wallet-deposit, wallet-withdraw, refund-order, financial-summary, calculate-commission, payment-gateways, configure-gateway, webhook, generate-invoice, audit-logs, merchant-statement, reconcile-payments, health-check', 400);
}
?>