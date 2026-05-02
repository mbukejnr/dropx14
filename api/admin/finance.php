<?php
// backend/api/admin/finance.php
// COMPLETE FINANCE MANAGEMENT API WITH COMMISSIONS

// =============================================
// CORS CONFIGURATION
// =============================================
$production_frontend = getenv('FRONTEND_URL') ?: 'https://frontend-gf0q7vyz3-mbukejnrs-projects.vercel.app';

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
    header("Access-Control-Allow-Origin: $production_frontend");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
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
// 1. DASHBOARD STATS
// =============================================
if ($method === 'GET' && $action === 'dashboard') {
    checkPermission('view_finance', $auth, $db);
    
    $stats = [];
    
    // Today's revenue
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
    $stats['today_revenue'] = floatval($stmt->fetchColumn());
    
    // Today's orders
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
    $stats['today_orders'] = intval($stmt->fetchColumn());
    
    // Week revenue
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed' AND YEARWEEK(created_at) = YEARWEEK(CURDATE())");
    $stats['week_revenue'] = floatval($stmt->fetchColumn());
    
    // Month revenue
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['month_revenue'] = floatval($stmt->fetchColumn());
    
    // Total revenue
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed'");
    $stats['total_revenue'] = floatval($stmt->fetchColumn());
    
    // Total commissions pending
    $stmt = $conn->query("SELECT COALESCE(SUM(commission_amount), 0) FROM merchant_commissions WHERE status = 'pending'");
    $stats['pending_commissions'] = floatval($stmt->fetchColumn());
    
    // Total commissions paid
    $stmt = $conn->query("SELECT COALESCE(SUM(commission_amount), 0) FROM merchant_commissions WHERE status = 'paid'");
    $stats['paid_commissions'] = floatval($stmt->fetchColumn());
    
    // Pending payouts
    $stmt = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM merchant_payouts WHERE status = 'pending'");
    $stats['pending_payouts'] = floatval($stmt->fetchColumn());
    
    // Average order value
    $stmt = $conn->query("SELECT COALESCE(AVG(total_amount), 0) FROM orders WHERE status = 'completed'");
    $stats['avg_order_value'] = floatval($stmt->fetchColumn());
    
    // Total customers
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    $stats['total_customers'] = intval($stmt->fetchColumn());
    
    // Total merchants
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_active = 1");
    $stats['total_merchants'] = intval($stmt->fetchColumn());
    
    // Top merchants by revenue
    $stmt = $conn->query("
        SELECT m.id, m.name, COALESCE(SUM(o.total_amount), 0) as revenue, COUNT(o.id) as order_count
        FROM merchants m
        LEFT JOIN orders o ON m.id = o.merchant_id AND o.status = 'completed'
        GROUP BY m.id
        ORDER BY revenue DESC
        LIMIT 5
    ");
    $stats['top_merchants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Payment methods breakdown
    $stmt = $conn->query("
        SELECT payment_method, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total
        FROM orders WHERE status = 'completed' AND payment_method IS NOT NULL
        GROUP BY payment_method
    ");
    $stats['by_payment_method'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent transactions
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
    
    // End current active setting
    $stmt = $conn->prepare("
        UPDATE merchant_commission_settings 
        SET effective_to = CURDATE() 
        WHERE merchant_id = :merchant_id AND effective_to IS NULL
    ");
    $stmt->execute([':merchant_id' => $data['merchant_id']]);
    
    // Insert new setting
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
    
    $payoutNumber = 'PO-' . strtoupper(uniqid());
    
    // Calculate commission amount (assuming standard rate for now)
    $commissionAmount = $data['amount'] * 0.10; // 10% commission
    $netAmount = $data['amount'] - $commissionAmount;
    
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
    
    $db->sendResponse([
        'id' => $conn->lastInsertId(),
        'payout_number' => $payoutNumber
    ], 'Payout created successfully', 201);
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
    
    $stmt = $conn->prepare("
        UPDATE merchant_payouts 
        SET status = :status, 
            transaction_reference = :reference,
            processed_by = :admin_id,
            processed_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $data['status'],
        ':reference' => $data['transaction_reference'] ?? null,
        ':admin_id' => $admin['id'],
        ':id' => $payoutId
    ]);
    
    $db->sendResponse([], 'Payout updated successfully');
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
                WHERE status = 'completed' AND DATE(created_at) = :date
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
                WHERE status = 'completed' AND DATE(created_at) = :date
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
                WHERE status = 'completed' 
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
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="commissions_' . $period . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['ID', 'Merchant', 'Order #', 'Order Amount', 'Commission Rate', 'Commission Amount', 'Status', 'Date']);
    
    foreach ($commissions as $c) {
        fputcsv($output, [
            $c['id'],
            $c['merchant_name'],
            $c['order_number'],
            $c['order_amount'],
            $c['commission_rate'] . '%',
            $c['commission_amount'],
            $c['status'],
            $c['created_at']
        ]);
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
// Invalid action handler
// =============================================
else {
    $db->sendError('Invalid action. Available actions: dashboard, commissions, commission-summary, update-commission, bulk-update-commissions, commission-settings, update-commission-settings, payouts, create-payout, update-payout, revenue-chart, export-commissions, transactions, orders', 400);
}
?>