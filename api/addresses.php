<?php
/*********************************
 * MALAWI DELIVERY ADDRESS API
 * Hybrid Address System (GPS + Description)
 *********************************/

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

define('GOOGLE_MAPS_API_KEY', 'YOUR_GOOGLE_MAPS_API_KEY');

/*********************************
 * HELPER: RESPONSE
 *********************************/
function response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

/*********************************
 * HELPER: GOOGLE API
 *********************************/
function callGoogleAPI($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

/*********************************
 * SMART GEOCODING (MALAWI LOGIC)
 *********************************/
function smartGeocode($input) {

    $queries = [];

    if (!empty($input['landmark'])) {
        $queries[] = "{$input['landmark']}, {$input['primary_location']}, Lilongwe, Malawi";
    }

    if (!empty($input['street'])) {
        $queries[] = "{$input['street']}, {$input['primary_location']}, Lilongwe";
    }

    $queries[] = "{$input['primary_location']}, Lilongwe, Malawi";

    foreach ($queries as $q) {
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($q) . "&key=" . GOOGLE_MAPS_API_KEY;

        $res = callGoogleAPI($url);

        if ($res && $res['status'] === 'OK') {
            return [
                'lat' => $res['results'][0]['geometry']['location']['lat'],
                'lng' => $res['results'][0]['geometry']['location']['lng'],
                'place_id' => $res['results'][0]['place_id']
            ];
        }
    }

    return null;
}

/*********************************
 * FORMAT ADDRESS (DRIVER VIEW)
 *********************************/
function formatAddress($a) {
    $parts = [];

    if (!empty($a['primary_location'])) {
        $parts[] = $a['primary_location'];
    }

    if (!empty($a['sub_area'])) {
        $parts[] = $a['sub_area'];
    }

    if (!empty($a['sector'])) {
        $parts[] = $a['sector'];
    }

    if (!empty($a['street'])) {
        $parts[] = $a['street'];
    }

    if (!empty($a['landmark'])) {
        $parts[] = "Near " . $a['landmark'];
    }

    if (!empty($a['notes'])) {
        $parts[] = $a['notes'];
    }

    return implode(', ', $parts) . ", Lilongwe";
}

/*********************************
 * AUTH (SIMPLE)
 *********************************/
function getUserId() {
    return $_SERVER['HTTP_X_USER_ID'] ?? null;
}

/*********************************
 * CREATE ADDRESS
 *********************************/
function createAddress($conn, $input) {

    $userId = getUserId();
    if (!$userId) response(['error' => 'Unauthorized'], 401);

    if (empty($input['primary_location'])) {
        response(['error' => 'Primary location required'], 400);
    }

    $lat = $input['latitude'] ?? null;
    $lng = $input['longitude'] ?? null;

    // PRIORITY: GPS FIRST
    if (!$lat || !$lng) {
        $coords = smartGeocode($input);

        if ($coords) {
            $lat = $coords['lat'];
            $lng = $coords['lng'];
        } else {
            response(['error' => 'Move map pin to exact location'], 400);
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO addresses 
        (user_id, primary_location, sub_area, sector, street, landmark, notes, latitude, longitude, label)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $input['primary_location'],
        $input['sub_area'] ?? null,
        $input['sector'] ?? null,
        $input['street'] ?? null,
        $input['landmark'] ?? null,
        $input['notes'] ?? null,
        $lat,
        $lng,
        $input['label'] ?? 'Home'
    ]);

    response([
        'message' => 'Address saved',
        'id' => $conn->lastInsertId()
    ], 201);
}

/*********************************
 * GET USER ADDRESSES
 *********************************/
function getAddresses($conn) {

    $userId = getUserId();
    if (!$userId) response(['error' => 'Unauthorized'], 401);

    $stmt = $conn->prepare("
        SELECT * FROM addresses 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");

    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['full_address'] = formatAddress($r);
        $r['map_link'] = "https://www.google.com/maps?q={$r['latitude']},{$r['longitude']}";
    }

    response(['addresses' => $rows]);
}

/*********************************
 * DELETE ADDRESS
 *********************************/
function deleteAddress($conn, $id) {

    $userId = getUserId();
    if (!$userId) response(['error' => 'Unauthorized'], 401);

    $stmt = $conn->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);

    response(['message' => 'Deleted']);
}

/*********************************
 * AUTOCOMPLETE (LOCAL + GOOGLE)
 *********************************/
function autocomplete($conn, $q) {

    if (strlen($q) < 2) {
        response(['results' => []]);
    }

    // LOCAL FIRST
    $stmt = $conn->prepare("
        SELECT name FROM locations 
        WHERE name LIKE ? 
        LIMIT 5
    ");
    $stmt->execute(["%$q%"]);
    $local = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $results = [];

    foreach ($local as $l) {
        $results[] = [
            'name' => $l,
            'source' => 'local'
        ];
    }

    // GOOGLE
    $url = "https://maps.googleapis.com/maps/api/place/autocomplete/json?input=" . urlencode($q) . "&components=country:mw&key=" . GOOGLE_MAPS_API_KEY;

    $res = callGoogleAPI($url);

    if ($res && $res['status'] === 'OK') {
        foreach ($res['predictions'] as $p) {
            $results[] = [
                'name' => $p['description'],
                'place_id' => $p['place_id'],
                'source' => 'google'
            ];
        }
    }

    response(['results' => $results]);
}

/*********************************
 * ROUTER
 *********************************/
try {

    $db = new Database();
    $conn = $db->getConnection();

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {

        if (isset($_GET['autocomplete'])) {
            autocomplete($conn, $_GET['q'] ?? '');
        } else {
            getAddresses($conn);
        }

    } elseif ($method === 'POST') {

        $input = json_decode(file_get_contents('php://input'), true);
        createAddress($conn, $input);

    } elseif ($method === 'DELETE') {

        $id = $_GET['id'] ?? null;
        if (!$id) response(['error' => 'ID required'], 400);

        deleteAddress($conn, $id);

    } else {
        response(['error' => 'Method not allowed'], 405);
    }

} catch (Exception $e) {
    response(['error' => $e->getMessage()], 500);
}