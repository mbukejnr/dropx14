<?php
// index.php - Refactored Main Router for DropX Delivery API
// Updated for Render + Vercel frontend

// ===================== ERROR REPORTING =====================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===================== CORS & HEADERS =====================
$frontend = 'https://dropx-frontend-seven.vercel.app';

if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $frontend) {
    header("Access-Control-Allow-Origin: $frontend");
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID');
header('Content-Type: application/json; charset=UTF-8');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ===================== SESSION =====================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30, // 30 days
        'path' => '/',
        'domain' => '',
        'secure' => true,        // Required for Render HTTPS
        'httponly' => true,
        'samesite' => 'None'     // Required for cross-domain
    ]);
    session_start();
}

// ===================== ROUTING =====================

// Dynamic base path detection
$base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$request_uri = $_SERVER['REQUEST_URI'];
$path = '/' . trim(str_replace($base_path, '', $request_uri), '/');

// Request method
$request_method = $_SERVER['REQUEST_METHOD'];

// ===================== API ROUTE MAP =====================
$routes = [
    // Auth routes
    'auth/register' => ['file' => 'api/register.php', 'method' => 'POST'],
    'auth/login'    => ['file' => 'api/login.php', 'method' => 'POST'],
    'auth/logout'   => ['file' => 'api/logout.php', 'method' => 'POST'],
    'auth/check'    => ['file' => 'api/auth.php', 'method' => 'GET', 'extra_get' => ['action' => 'check']],

    // Merchants
    'merchants'     => ['file' => 'api/merchant.php', 'method' => 'GET'],

    // Menu
    'menu'          => ['file' => 'api/menu.php', 'method' => 'GET'],

    // Cart
    'cart'          => ['file' => 'api/cart.php', 'method' => 'ALL'],

    // Profile
    'profile'       => ['file' => 'api/profile.php', 'method' => 'ALL'],

    // Addresses
    'address'       => ['file' => 'api/address.php', 'method' => 'ALL'],
    'addresses'     => ['file' => 'api/address.php', 'method' => 'ALL'],

    // Wallet
    'wallet'        => ['file' => 'api/wallet.php', 'method' => 'ALL'],

    // Health
    'health'        => ['file' => null, 'method' => 'GET', 'callback' => function() {
        echo json_encode([
            'status' => 'healthy',
            'service' => 'DropX Delivery API',
            'timestamp' => date('c'),
            'database' => 'connected',
            'version' => '1.0.0'
        ]);
        exit;
    }],
];

// ===================== HELPER FUNCTIONS =====================
function include_route_file($file, $extra_get = []) {
    if ($extra_get) {
        foreach ($extra_get as $key => $value) {
            $_GET[$key] = $value;
        }
    }
    if (file_exists($file)) {
        include_once $file;
        exit;
    }
    http_response_code(404);
    echo json_encode(['error' => "File not found: $file"]);
    exit;
}

// ===================== ROOT API INFO =====================
if ($path === '' || $path === '/') {
    echo json_encode([
        'api' => 'DropX Delivery API',
        'version' => '1.0',
        'status' => 'running',
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoints' => array_keys($routes),
        'base_url' => 'https://' . $_SERVER['HTTP_HOST'] . $base_path,
        'note' => 'Use the correct HTTP method for each endpoint'
    ]);
    exit;
}

// ===================== PATH PARSING =====================
$path_parts = explode('/', trim($path, '/'));
$first_part = $path_parts[0] ?? '';
$second_part = $path_parts[1] ?? null;
$third_part = $path_parts[2] ?? null;

// ===================== ROUTE HANDLING =====================
if ($first_part === 'api') {

    // Handle dynamic merchant sub-routes: /api/merchants/{id}/menu or /favorite
    if ($second_part === 'merchants' && isset($third_part)) {
        $merchant_id = $third_part;

        if (isset($path_parts[3]) && $path_parts[3] === 'menu') {
            $_GET['merchant_id'] = $merchant_id;
            include_route_file('api/menu.php');
        }

        if (isset($path_parts[3]) && $path_parts[3] === 'favorite' && $request_method === 'POST') {
            $_GET['action'] = 'toggle_favorite';
            $_GET['id'] = $merchant_id;
            include_route_file('api/merchant.php');
        }

        $_GET['action'] = 'get';
        $_GET['id'] = $merchant_id;
        include_route_file('api/merchant.php');
    }

    // Construct route key
    $route_key = $second_part . ($third_part ? '/' . $third_part : '');
    if (isset($routes[$route_key])) {
        $route = $routes[$route_key];
        if ($route['method'] === 'ALL' || $route['method'] === $request_method) {
            if (isset($route['callback']) && is_callable($route['callback'])) {
                $route['callback']();
            }
            include_route_file($route['file'], $route['extra_get'] ?? []);
        }
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }

    // Fallback: try second part only
    if (isset($routes[$second_part])) {
        $route = $routes[$second_part];
        if ($route['method'] === 'ALL' || $route['method'] === $request_method) {
            if (isset($route['callback']) && is_callable($route['callback'])) {
                $route['callback']();
            }
            include_route_file($route['file'], $route['extra_get'] ?? []);
        }
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }

    // Direct file access
    $filename = basename($path);
    if (strpos($filename, '.php') !== false) {
        $api_file = 'api/' . $filename;
        if (file_exists($api_file)) {
            include_route_file($api_file);
        }
    }
}

// ===================== 404 NOT FOUND =====================
http_response_code(404);
echo json_encode([
    'error' => 'Endpoint not found',
    'request' => [
        'method' => $request_method,
        'uri' => $request_uri,
        'path' => $path
    ],
    'available_endpoints' => array_keys($routes),
    'note' => 'Check your HTTP method (GET, POST, etc.)'
]);
exit;
