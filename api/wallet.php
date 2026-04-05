<?php
/*********************************
 * DROPX WALLET API
 * Malawi Kwacha (MWK) Wallet System
 * Supports: DropxWallet, Airtel Money, TNM Mpamba, Bank Transfers
 *********************************/

/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

/*********************************
 * CONSTANTS
 *********************************/
if (!defined('CURRENCY')) define('CURRENCY', 'MWK');
if (!defined('CURRENCY_SYMBOL')) define('CURRENCY_SYMBOL', 'MK');

// Payment method constants
define('PAYMENT_METHOD_DROPX_WALLET', 'dropx_wallet');
define('PAYMENT_METHOD_AIRTEL_MONEY', 'airtel_money');
define('PAYMENT_METHOD_TNM_MPAMBA', 'tnm_mpamba');
define('PAYMENT_METHOD_BANK_TRANSFER', 'bank_transfer');

// Bank list
define('BANKS', [
    'NBS Bank',
    'National Bank of Malawi',
    'Standard Bank',
    'FMB Capital Bank',
    'CDH Investment Bank',
    'First Capital Bank',
    'MyBucks Banking Corporation',
    'Opportunity Bank'
]);

// Mobile money providers
define('MOBILE_MONEY_PROVIDERS', [
    'Airtel Malawi' => ['code' => 'airtel', 'prefix' => '099'],
    'TNM' => ['code' => 'tnm', 'prefix' => '088']
]);

/*********************************
 * INITIALIZATION & HELPER FUNCTIONS
 *********************************/
function initDatabase() {
    $db = new Database();
    return $db->getConnection();
}

function getBaseUrl() {
    global $baseUrl;
    return $baseUrl;
}

/*********************************
 * AUTHENTICATION FUNCTION
 *********************************/
function authenticateUser($conn) {
    // Check session
    if (!empty($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }

    // Check Bearer token
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        return authenticateWithToken($conn, $token);
    }

    // Check API key in query params
    $apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? null;
    if ($apiKey) {
        return authenticateWithToken($conn, $apiKey);
    }

    return null;
}

function authenticateWithToken($conn, $token) {
    $stmt = $conn->prepare(
        "SELECT id FROM users WHERE api_token = :token 
         AND (api_token_expiry IS NULL OR api_token_expiry > NOW())"
    );
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        return $user['id'];
    }
    
    return null;
}

/*********************************
 * WALLET FUNCTIONS - Using dropx_wallets table
 *********************************/
function getOrCreateWallet($conn, $user_id) {
    // Check if wallet exists
    $stmt = $conn->prepare("SELECT * FROM dropx_wallets WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $user_id]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($wallet) {
        return $wallet;
    }

    // Create new wallet
    $stmt = $conn->prepare(
        "INSERT INTO dropx_wallets (user_id, balance, currency) VALUES (:user_id, 0.00, 'MWK')"
    );
    $stmt->execute([':user_id' => $user_id]);
    
    return [
        'id' => $conn->lastInsertId(),
        'user_id' => $user_id,
        'balance' => 0.00,
        'currency' => 'MWK',
        'is_active' => 1
    ];
}

function getWalletBalance($conn, $user_id) {
    $stmt = $conn->prepare(
        "SELECT balance, currency FROM dropx_wallets WHERE user_id = :user_id AND is_active = 1"
    );
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function updateWalletBalance($conn, $wallet_id, $new_balance) {
    $stmt = $conn->prepare(
        "UPDATE dropx_wallets SET balance = :balance WHERE id = :id"
    );
    return $stmt->execute([
        ':balance' => $new_balance,
        ':id' => $wallet_id
    ]);
}

/*********************************
 * TRANSACTION FUNCTIONS - Using wallet_transactions table
 *********************************/
function createTransaction($conn, $data) {
    $stmt = $conn->prepare(
        "INSERT INTO wallet_transactions 
         (user_id, amount, type, payment_method, reference_type, reference_id, 
          partner, partner_reference, status, description, metadata)
         VALUES 
         (:user_id, :amount, :type, :payment_method, :reference_type, :reference_id, 
          :partner, :partner_reference, :status, :description, :metadata)"
    );

    $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;

    return $stmt->execute([
        ':user_id' => $data['user_id'],
        ':amount' => $data['amount'],
        ':type' => $data['type'],
        ':payment_method' => $data['payment_method'] ?? null,
        ':reference_type' => $data['reference_type'] ?? null,
        ':reference_id' => $data['reference_id'] ?? null,
        ':partner' => $data['partner'] ?? null,
        ':partner_reference' => $data['partner_reference'] ?? null,
        ':status' => $data['status'] ?? 'completed',
        ':description' => $data['description'] ?? '',
        ':metadata' => $metadata
    ]);
}

function getUserTransactions($conn, $user_id, $limit = 50, $offset = 0, $payment_method = null) {
    $sql = "SELECT * FROM wallet_transactions 
            WHERE user_id = :user_id";
    
    if ($payment_method) {
        $sql .= " AND payment_method = :payment_method";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    if ($payment_method) {
        $stmt->bindParam(':payment_method', $payment_method, PDO::PARAM_STR);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*********************************
 * PAYMENT REQUEST FUNCTIONS
 *********************************/
function createPaymentRequest($conn, $user_id, $amount, $payment_method, $metadata = []) {
    // Generate unique reference (8 characters)
    $reference = strtoupper(substr(uniqid(), -8));
    
    // Check if reference exists
    while (paymentReferenceExists($conn, $reference)) {
        $reference = strtoupper(substr(uniqid(), -8));
    }
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Get current wallet balance if needed
    $wallet_balance = 0;
    if ($payment_method === PAYMENT_METHOD_DROPX_WALLET) {
        $wallet = getWalletBalance($conn, $user_id);
        $wallet_balance = $wallet['balance'] ?? 0;
    }

    $stmt = $conn->prepare(
        "INSERT INTO payment_requests 
         (reference, user_id, amount, payment_method, wallet_balance_at_request, 
          status, metadata, expires_at)
         VALUES 
         (:reference, :user_id, :amount, :payment_method, :wallet_balance, 
          'pending', :metadata, :expires_at)"
    );

    $stmt->execute([
        ':reference' => $reference,
        ':user_id' => $user_id,
        ':amount' => $amount,
        ':payment_method' => $payment_method,
        ':wallet_balance' => $wallet_balance,
        ':metadata' => json_encode($metadata),
        ':expires_at' => $expires_at
    ]);

    return [
        'id' => $conn->lastInsertId(),
        'reference' => $reference,
        'amount' => $amount,
        'payment_method' => $payment_method,
        'expires_at' => $expires_at,
        'metadata' => $metadata
    ];
}

function paymentReferenceExists($conn, $reference) {
    $stmt = $conn->prepare("SELECT id FROM payment_requests WHERE reference = :reference");
    $stmt->execute([':reference' => $reference]);
    return $stmt->fetch() ? true : false;
}

/*********************************
 * DROPX WALLET PAYMENT FUNCTIONS
 *********************************/
function processDropxWalletPayment($conn, $user_id, $amount, $reference, $description) {
    try {
        $conn->beginTransaction();

        // Get wallet with lock
        $walletStmt = $conn->prepare(
            "SELECT id, balance FROM dropx_wallets WHERE user_id = :user_id AND is_active = 1 FOR UPDATE"
        );
        $walletStmt->execute([':user_id' => $user_id]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            throw new Exception('Wallet not found');
        }

        if ($wallet['balance'] < $amount) {
            throw new Exception('Insufficient DropxWallet balance');
        }

        // Update balance
        $newBalance = $wallet['balance'] - $amount;
        updateWalletBalance($conn, $wallet['id'], $newBalance);

        // Create transaction record
        $transactionData = [
            'user_id' => $user_id,
            'amount' => $amount,
            'type' => 'debit',
            'payment_method' => PAYMENT_METHOD_DROPX_WALLET,
            'reference_type' => 'payment',
            'reference_id' => $reference,
            'partner' => 'dropx_wallet',
            'partner_reference' => $reference,
            'status' => 'completed',
            'description' => $description ?: 'Payment via DropxWallet',
            'metadata' => ['wallet_id' => $wallet['id']]
        ];
        createTransaction($conn, $transactionData);

        // Update payment request
        updatePaymentRequestStatus($conn, $reference, 'completed', [
            'wallet_id' => $wallet['id'],
            'new_balance' => $newBalance
        ]);

        $conn->commit();

        return [
            'success' => true,
            'transaction_id' => $conn->lastInsertId(),
            'amount' => $amount,
            'new_balance' => $newBalance,
            'payment_method' => PAYMENT_METHOD_DROPX_WALLET
        ];

    } catch (Exception $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/*********************************
 * MOBILE MONEY FUNCTIONS (Airtel Money & TNM Mpamba)
 *********************************/
function initiateMobileMoneyPayment($conn, $user_id, $amount, $provider, $phone_number, $metadata = []) {
    // Validate phone number
    if (!validateMobileMoneyNumber($phone_number, $provider)) {
        return [
            'success' => false,
            'error' => 'Invalid phone number for ' . $provider
        ];
    }

    // Create payment request
    $payment_request = createPaymentRequest($conn, $user_id, $amount, $provider, [
        'phone_number' => $phone_number,
        'provider' => $provider,
        ...$metadata
    ]);

    // Here you would integrate with actual mobile money API
    // For now, we'll simulate the payment initiation
    
    $ussd_code = getMobileMoneyUssdCode($provider);
    
    return [
        'success' => true,
        'reference' => $payment_request['reference'],
        'amount' => $amount,
        'provider' => $provider,
        'phone_number' => maskPhoneNumber($phone_number),
        'instructions' => getMobileMoneyInstructions($provider, $payment_request['reference'], $amount),
        'ussd_code' => $ussd_code,
        'expires_at' => $payment_request['expires_at']
    ];
}

function validateMobileMoneyNumber($phone_number, $provider) {
    // Remove any non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone_number);
    
    // Check length (Malawi numbers are 9 digits after removing leading 0)
    if (strlen($phone) === 10 && $phone[0] === '0') {
        $phone = substr($phone, 1);
    }
    
    if (strlen($phone) !== 9) {
        return false;
    }
    
    // Check provider prefix
    if ($provider === PAYMENT_METHOD_AIRTEL_MONEY) {
        return in_array(substr($phone, 0, 3), ['99', '999', '998']);
    } elseif ($provider === PAYMENT_METHOD_TNM_MPAMBA) {
        return in_array(substr($phone, 0, 3), ['88', '881', '882', '883', '884', '885']);
    }
    
    return false;
}

function getMobileMoneyUssdCode($provider) {
    $codes = [
        PAYMENT_METHOD_AIRTEL_MONEY => '*211#',
        PAYMENT_METHOD_TNM_MPAMBA => '*444#'
    ];
    
    return $codes[$provider] ?? null;
}

function getMobileMoneyInstructions($provider, $reference, $amount) {
    $instructions = [
        PAYMENT_METHOD_AIRTEL_MONEY => [
            'Dial *211#',
            'Select "Pay" or "Send Money"',
            "Enter merchant code: 787878",
            "Enter amount: MK " . number_format($amount, 2),
            "Enter reference: " . $reference,
            "Enter your PIN to confirm"
        ],
        PAYMENT_METHOD_TNM_MPAMBA => [
            'Dial *444#',
            'Select "Pay"',
            "Enter merchant number: 0888 888 888",
            "Enter amount: MK " . number_format($amount, 2),
            "Enter reference: " . $reference,
            "Enter your PIN to confirm"
        ]
    ];
    
    return $instructions[$provider] ?? ['Please complete payment using your mobile money app'];
}

/*********************************
 * BANK TRANSFER FUNCTIONS
 *********************************/
function initiateBankTransfer($conn, $user_id, $amount, $bank_name, $account_number, $account_name, $metadata = []) {
    // Validate bank
    if (!in_array($bank_name, BANKS)) {
        return [
            'success' => false,
            'error' => 'Invalid bank name. Supported banks: ' . implode(', ', BANKS)
        ];
    }
    
    // Generate unique payment reference
    $payment_reference = 'DROPX-' . strtoupper(uniqid());
    
    // Create payment request
    $payment_request = createPaymentRequest($conn, $user_id, $amount, PAYMENT_METHOD_BANK_TRANSFER, [
        'bank_name' => $bank_name,
        'account_number' => maskAccountNumber($account_number),
        'account_name' => $account_name,
        'payment_reference' => $payment_reference,
        ...$metadata
    ]);

    // Get bank details for transfer
    $bank_details = getBankTransferDetails($bank_name, $payment_reference);
    
    return [
        'success' => true,
        'reference' => $payment_request['reference'],
        'amount' => $amount,
        'bank_name' => $bank_name,
        'payment_reference' => $payment_reference,
        'bank_details' => $bank_details,
        'instructions' => [
            'Log in to your ' . $bank_name . ' internet banking',
            'Add ' . $bank_name . ' as a new payee (if not already added)',
            'Make a transfer using the details below',
            'Use the payment reference for verification',
            'Payment will be verified within 2-4 hours'
        ],
        'expires_at' => $payment_request['expires_at']
    ];
}

function getBankTransferDetails($bank_name, $payment_reference) {
    // These would be your actual bank account details
    $our_accounts = [
        'NBS Bank' => [
            'account_number' => '1234567890',
            'account_name' => 'DROPX LIMITED',
            'branch' => 'City Centre'
        ],
        'National Bank of Malawi' => [
            'account_number' => '0987654321',
            'account_name' => 'DROPX LIMITED',
            'branch' => 'Head Office'
        ],
        'Standard Bank' => [
            'account_number' => '5678901234',
            'account_name' => 'DROPX LIMITED',
            'branch' => 'Victoria Avenue'
        ]
    ];
    
    // Default to first bank if specific bank not found
    $account = $our_accounts[$bank_name] ?? $our_accounts['NBS Bank'];
    
    return [
        'bank_name' => $bank_name,
        'account_number' => $account['account_number'],
        'account_name' => $account['account_name'],
        'branch' => $account['branch'] ?? 'Main Branch',
        'payment_reference' => $payment_reference,
        'currency' => CURRENCY
    ];
}

/*********************************
 * VERIFY PAYMENT
 *********************************/
function verifyAndCompletePayment($conn, $reference) {
    try {
        $conn->beginTransaction();

        // Get the pending payment request
        $stmt = $conn->prepare(
            "SELECT * FROM payment_requests 
             WHERE reference = :reference AND status = 'pending' 
             AND expires_at > NOW() FOR UPDATE"
        );
        $stmt->execute([':reference' => $reference]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new Exception('Invalid or expired payment reference');
        }

        $user_id = $payment['user_id'];
        $amount = $payment['amount'];
        $payment_method = $payment['payment_method'];

        // Handle based on payment method
        if ($payment_method === PAYMENT_METHOD_DROPX_WALLET) {
            // For DropxWallet, we should have already processed it
            // This is just confirmation
            $result = [
                'success' => true,
                'amount' => $amount,
                'payment_method' => $payment_method,
                'message' => 'Payment completed'
            ];
        } else {
            // For external payments, we need to credit the wallet
            $walletStmt = $conn->prepare(
                "SELECT id, balance FROM dropx_wallets WHERE user_id = :user_id FOR UPDATE"
            );
            $walletStmt->execute([':user_id' => $user_id]);
            $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                throw new Exception('Wallet not found');
            }

            // Credit the wallet
            $newBalance = $wallet['balance'] + $amount;
            updateWalletBalance($conn, $wallet['id'], $newBalance);

            // Create transaction record
            $transactionData = [
                'user_id' => $user_id,
                'amount' => $amount,
                'type' => 'credit',
                'payment_method' => $payment_method,
                'reference_type' => 'payment_verification',
                'reference_id' => $payment['id'],
                'partner' => $payment_method,
                'partner_reference' => $reference,
                'status' => 'completed',
                'description' => 'Wallet top-up via ' . str_replace('_', ' ', $payment_method),
                'metadata' => json_decode($payment['metadata'], true)
            ];
            createTransaction($conn, $transactionData);

            $result = [
                'success' => true,
                'amount' => $amount,
                'new_balance' => $newBalance,
                'transaction_id' => $conn->lastInsertId(),
                'payment_method' => $payment_method
            ];
        }

        // Update payment request status
        updatePaymentRequestStatus($conn, $reference, 'completed', $result);

        $conn->commit();

        return $result;

    } catch (Exception $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function updatePaymentRequestStatus($conn, $reference, $status, $result_data = []) {
    $stmt = $conn->prepare(
        "UPDATE payment_requests SET status = :status, completed_at = NOW(), 
         result_data = :result_data WHERE reference = :reference"
    );
    return $stmt->execute([
        ':status' => $status,
        ':result_data' => json_encode($result_data),
        ':reference' => $reference
    ]);
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/
function maskPhoneNumber($phone) {
    // Show first 3 and last 3 digits
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) > 6) {
        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }
    return '****' . substr($phone, -3);
}

function maskAccountNumber($account) {
    // Show last 4 digits
    if (strlen($account) > 4) {
        return '****' . substr($account, -4);
    }
    return '****' . $account;
}

/*********************************
 * GET PAYMENT METHODS
 *********************************/
function getAvailablePaymentMethods($conn, $user_id) {
    // Get wallet balance
    $wallet = getWalletBalance($conn, $user_id);
    $balance = $wallet['balance'] ?? 0;
    
    return [
        [
            'id' => PAYMENT_METHOD_DROPX_WALLET,
            'name' => 'DropxWallet',
            'type' => 'wallet',
            'icon' => 'wallet',
            'description' => 'Pay using your DropxWallet balance',
            'balance' => floatval($balance),
            'formatted_balance' => CURRENCY_SYMBOL . ' ' . number_format($balance, 2),
            'min_amount' => 0,
            'max_amount' => $balance,
            'processing_time' => 'Instant',
            'fee' => 0,
            'fee_type' => 'none',
            'requires_authentication' => true
        ],
        [
            'id' => PAYMENT_METHOD_AIRTEL_MONEY,
            'name' => 'Airtel Money',
            'type' => 'mobile_money',
            'icon' => 'airtel',
            'description' => 'Pay using Airtel Money mobile wallet',
            'min_amount' => 100,
            'max_amount' => 1000000,
            'processing_time' => 'Instant',
            'fee' => 0.5,
            'fee_type' => 'percentage',
            'formatted_fee' => '0.5%',
            'provider' => 'Airtel Malawi',
            'ussd_code' => '*211#',
            'supported_networks' => ['Airtel']
        ],
        [
            'id' => PAYMENT_METHOD_TNM_MPAMBA,
            'name' => 'TNM Mpamba',
            'type' => 'mobile_money',
            'icon' => 'tnm',
            'description' => 'Pay using TNM Mpamba mobile wallet',
            'min_amount' => 100,
            'max_amount' => 1000000,
            'processing_time' => 'Instant',
            'fee' => 0.5,
            'fee_type' => 'percentage',
            'formatted_fee' => '0.5%',
            'provider' => 'TNM',
            'ussd_code' => '*444#',
            'supported_networks' => ['TNM']
        ],
        [
            'id' => PAYMENT_METHOD_BANK_TRANSFER,
            'name' => 'Bank Transfer',
            'type' => 'bank',
            'icon' => 'bank',
            'description' => 'Pay via bank transfer from your bank account',
            'min_amount' => 1000,
            'max_amount' => 10000000,
            'processing_time' => '2-4 hours',
            'fee' => 0,
            'fee_type' => 'none',
            'supported_banks' => BANKS,
            'requires_reference' => true
        ]
    ];
}

/*********************************
 * GET WALLET STATS
 *********************************/
function getWalletStats($conn, $user_id) {
    // Get wallet balance
    $balance = getWalletBalance($conn, $user_id);
    
    // Get transaction stats
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_transactions,
            SUM(CASE WHEN type = 'credit' THEN 1 ELSE 0 END) as total_credits,
            SUM(CASE WHEN type = 'debit' THEN 1 ELSE 0 END) as total_debits,
            SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as total_credited,
            SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as total_debited,
            COUNT(DISTINCT payment_method) as payment_methods_used
         FROM wallet_transactions 
         WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $user_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get payment methods breakdown
    $methodsStmt = $conn->prepare(
        "SELECT payment_method, COUNT(*) as count, SUM(amount) as total 
         FROM wallet_transactions 
         WHERE user_id = :user_id 
         GROUP BY payment_method"
    );
    $methodsStmt->execute([':user_id' => $user_id]);
    $methodsBreakdown = $methodsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'balance' => floatval($balance['balance'] ?? 0),
        'formatted_balance' => CURRENCY_SYMBOL . ' ' . number_format($balance['balance'] ?? 0, 2),
        'total_transactions' => intval($stats['total_transactions'] ?? 0),
        'total_credited' => floatval($stats['total_credited'] ?? 0),
        'total_debited' => floatval($stats['total_debited'] ?? 0),
        'formatted_credited' => CURRENCY_SYMBOL . ' ' . number_format($stats['total_credited'] ?? 0, 2),
        'formatted_debited' => CURRENCY_SYMBOL . ' ' . number_format($stats['total_debited'] ?? 0, 2),
        'payment_methods_used' => intval($stats['payment_methods_used'] ?? 0),
        'methods_breakdown' => $methodsBreakdown
    ];
}

/*********************************
 * FORMATTING FUNCTIONS
 *********************************/
function formatTransaction($t) {
    $currency = 'MK';
    $metadata = isset($t['metadata']) ? json_decode($t['metadata'], true) : null;
    
    // Get payment method display name
    $payment_method_names = [
        PAYMENT_METHOD_DROPX_WALLET => 'DropxWallet',
        PAYMENT_METHOD_AIRTEL_MONEY => 'Airtel Money',
        PAYMENT_METHOD_TNM_MPAMBA => 'TNM Mpamba',
        PAYMENT_METHOD_BANK_TRANSFER => 'Bank Transfer'
    ];
    
    $payment_method = $t['payment_method'] ?? 'unknown';
    $payment_method_name = $payment_method_names[$payment_method] ?? ucwords(str_replace('_', ' ', $payment_method));
    
    return [
        'id' => $t['id'],
        'amount' => floatval($t['amount']),
        'formatted_amount' => $currency . ' ' . number_format($t['amount'], 2),
        'description' => $t['description'],
        'type' => $t['type'],
        'payment_method' => $payment_method,
        'payment_method_name' => $payment_method_name,
        'status' => $t['status'],
        'date' => $t['created_at'],
        'formatted_date' => date('M d, Y • h:i A', strtotime($t['created_at'])),
        'partner' => $t['partner'],
        'reference' => $t['partner_reference'],
        'metadata' => $metadata
    ];
}

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];
    
    // Parse URL
    $path = parse_url($requestUri, PHP_URL_PATH);
    $queryString = parse_url($requestUri, PHP_URL_QUERY);
    parse_str($queryString ?? '', $queryParams);
    
    // Initialize database
    $conn = initDatabase();
    $baseUrl = getBaseUrl();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    // Get endpoint from query string
    $endpoint = $_GET['endpoint'] ?? '';
    
    // Public endpoints
    if ($endpoint === 'test') {
        ResponseHandler::success([
            'message' => 'Payment API is working',
            'supported_payment_methods' => [
                PAYMENT_METHOD_DROPX_WALLET,
                PAYMENT_METHOD_AIRTEL_MONEY,
                PAYMENT_METHOD_TNM_MPAMBA,
                PAYMENT_METHOD_BANK_TRANSFER
            ]
        ]);
    }
    
    // Authenticate for protected endpoints
    $userId = authenticateUser($conn);
    
    if (!$userId && !in_array($endpoint, ['test', 'login', 'register', 'payment-methods-public'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    // Route endpoints
    switch ($endpoint) {
        case 'balance':
            if ($method === 'GET') {
                $wallet = getOrCreateWallet($conn, $userId);
                $balance = getWalletBalance($conn, $userId);
                
                ResponseHandler::success([
                    'balance' => floatval($balance['balance'] ?? 0),
                    'display_balance' => CURRENCY_SYMBOL . ' ' . number_format($balance['balance'] ?? 0, 2),
                    'currency' => CURRENCY,
                    'wallet_id' => $wallet['id']
                ]);
            }
            break;
            
        case 'transactions':
            if ($method === 'GET') {
                $type = $_GET['type'] ?? 'recent';
                $payment_method = $_GET['payment_method'] ?? null;
                $limit = intval($_GET['limit'] ?? 50);
                $offset = intval($_GET['offset'] ?? 0);
                
                if ($type === 'recent') {
                    $limit = min($limit, 10);
                }
                
                $transactions = getUserTransactions($conn, $userId, $limit, $offset, $payment_method);
                $formatted = array_map('formatTransaction', $transactions);
                
                ResponseHandler::success([
                    'transactions' => $formatted,
                    'total' => count($formatted),
                    'type' => $type,
                    'payment_method' => $payment_method
                ]);
            }
            break;
            
        case 'stats':
            if ($method === 'GET') {
                $stats = getWalletStats($conn, $userId);
                ResponseHandler::success($stats);
            }
            break;
            
        case 'payment-methods':
            if ($method === 'GET') {
                $methods = getAvailablePaymentMethods($conn, $userId);
                ResponseHandler::success([
                    'payment_methods' => $methods,
                    'total_methods' => count($methods)
                ]);
            }
            break;
            
        case 'initiate-payment':
            if ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                if (!$input) $input = $_POST;
                
                $amount = floatval($input['amount'] ?? 0);
                $payment_method = $input['payment_method'] ?? '';
                $description = $input['description'] ?? 'Payment';
                
                // Validate amount
                if ($amount <= 0) {
                    ResponseHandler::error('Invalid amount', 400);
                }
                
                // Route to appropriate payment handler
                switch ($payment_method) {
                    case PAYMENT_METHOD_DROPX_WALLET:
                        $reference = $input['reference'] ?? uniqid();
                        $result = processDropxWalletPayment($conn, $userId, $amount, $reference, $description);
                        
                        if ($result['success']) {
                            ResponseHandler::success([
                                'transaction_id' => $result['transaction_id'],
                                'amount' => $result['amount'],
                                'formatted_amount' => CURRENCY_SYMBOL . ' ' . number_format($result['amount'], 2),
                                'new_balance' => $result['new_balance'],
                                'formatted_balance' => CURRENCY_SYMBOL . ' ' . number_format($result['new_balance'], 2),
                                'payment_method' => $result['payment_method']
                            ], 'Payment successful');
                        } else {
                            ResponseHandler::error($result['error'], 400);
                        }
                        break;
                        
                    case PAYMENT_METHOD_AIRTEL_MONEY:
                    case PAYMENT_METHOD_TNM_MPAMBA:
                        $phone_number = $input['phone_number'] ?? '';
                        
                        if (empty($phone_number)) {
                            ResponseHandler::error('Phone number required for mobile money', 400);
                        }
                        
                        $result = initiateMobileMoneyPayment(
                            $conn, 
                            $userId, 
                            $amount, 
                            $payment_method, 
                            $phone_number,
                            ['description' => $description]
                        );
                        
                        if ($result['success']) {
                            ResponseHandler::success($result, 'Mobile money payment initiated');
                        } else {
                            ResponseHandler::error($result['error'], 400);
                        }
                        break;
                        
                    case PAYMENT_METHOD_BANK_TRANSFER:
                        $bank_name = $input['bank_name'] ?? '';
                        $account_number = $input['account_number'] ?? '';
                        $account_name = $input['account_name'] ?? '';
                        
                        if (empty($bank_name) || empty($account_number) || empty($account_name)) {
                            ResponseHandler::error('Bank name, account number, and account name required', 400);
                        }
                        
                        $result = initiateBankTransfer(
                            $conn,
                            $userId,
                            $amount,
                            $bank_name,
                            $account_number,
                            $account_name,
                            ['description' => $description]
                        );
                        
                        if ($result['success']) {
                            ResponseHandler::success($result, 'Bank transfer initiated');
                        } else {
                            ResponseHandler::error($result['error'], 400);
                        }
                        break;
                        
                    default:
                        ResponseHandler::error('Invalid payment method. Supported: dropx_wallet, airtel_money, tnm_mpamba, bank_transfer', 400);
                }
            }
            break;
            
        case 'verify-payment':
            if ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                if (!$input) $input = $_POST;
                
                $reference = $input['reference'] ?? '';
                
                if (empty($reference)) {
                    ResponseHandler::error('Payment reference required', 400);
                }
                
                $result = verifyAndCompletePayment($conn, $reference);
                
                if ($result['success']) {
                    ResponseHandler::success($result, 'Payment verified successfully');
                } else {
                    ResponseHandler::error($result['error'], 400);
                }
            }
            break;
            
        case 'check-payment-status':
            if ($method === 'GET') {
                $reference = $_GET['reference'] ?? '';
                
                if (empty($reference)) {
                    ResponseHandler::error('Payment reference required', 400);
                }
                
                $stmt = $conn->prepare(
                    "SELECT * FROM payment_requests WHERE reference = :reference AND user_id = :user_id"
                );
                $stmt->execute([
                    ':reference' => $reference,
                    ':user_id' => $userId
                ]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$payment) {
                    ResponseHandler::error('Payment not found', 404);
                }
                
                ResponseHandler::success([
                    'reference' => $payment['reference'],
                    'amount' => floatval($payment['amount']),
                    'formatted_amount' => CURRENCY_SYMBOL . ' ' . number_format($payment['amount'], 2),
                    'payment_method' => $payment['payment_method'],
                    'status' => $payment['status'],
                    'created_at' => $payment['created_at'],
                    'expires_at' => $payment['expires_at'],
                    'completed_at' => $payment['completed_at'],
                    'metadata' => json_decode($payment['metadata'], true)
                ]);
            }
            break;
            
        default:
            if ($endpoint) {
                ResponseHandler::error('Invalid endpoint: ' . $endpoint, 404);
            } else {
                ResponseHandler::error('Endpoint parameter required. Use ?endpoint=...', 400);
            }
    }
    
} catch (Exception $e) {
    error_log("Payment API Error: " . $e->getMessage());
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}
?>