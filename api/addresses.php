<?php
/*********************************
 * MALAWI DELIVERY ADDRESS API
 * Google Maps Only - Simplified
 *********************************/

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id");
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
 * HELPER: GET USER ID
 *********************************/
function getUserId() {
    return $_SERVER['HTTP_X_USER_ID'] ?? null;
}

/*********************************
 * HELPER: CALL GOOGLE API
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
 * CREATE ADDRESS TABLE IF NOT EXISTS
 *********************************/
function createTableIfNotExists($conn) {
    $sql = "
    CREATE TABLE IF NOT EXISTS addresses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        place_id VARCHAR(255) NULL,
        formatted_address TEXT NULL,
        latitude DECIMAL(10, 7) NOT NULL,
        longitude DECIMAL(10, 7) NOT NULL,
        label VARCHAR(50) DEFAULT 'Home',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id)
    )";
    $conn->exec($sql);
}

/*********************************
 * CREATE ADDRESS
 *********************************/
function createAddress($conn, $input) {
    $userId = getUserId();
    if (!$userId) {
        response(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    // Validate required fields
    if (empty($input['latitude']) || empty($input['longitude'])) {
        response(['success' => false, 'error' => 'Latitude and longitude are required'], 400);
    }

    $stmt = $conn->prepare("
        INSERT INTO addresses 
        (user_id, place_id, formatted_address, latitude, longitude, label)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $input['place_id'] ?? null,
        $input['formatted_address'] ?? null,
        $input['latitude'],
        $input['longitude'],
        $input['label'] ?? 'Home'
    ]);

    $id = $conn->lastInsertId();

    response([
        'success' => true,
        'message' => 'Address saved successfully',
        'id' => $id
    ], 201);
}

/*********************************
 * GET USER ADDRESSES
 *********************************/
function getAddresses($conn) {
    $userId = getUserId();
    if (!$userId) {
        response(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    $stmt = $conn->prepare("
        SELECT id, place_id, formatted_address, latitude, longitude, label, created_at
        FROM addresses 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $addresses = [];
    foreach ($rows as $row) {
        $addresses[] = [
            'id' => $row['id'],
            'place_id' => $row['place_id'],
            'formatted_address' => $row['formatted_address'],
            'latitude' => floatval($row['latitude']),
            'longitude' => floatval($row['longitude']),
            'label' => $row['label'],
            'created_at' => $row['created_at'],
            'map_link' => "https://www.google.com/maps?q={$row['latitude']},{$row['longitude']}"
        ];
    }

    response([
        'success' => true,
        'addresses' => $addresses
    ]);
}

/*********************************
 * DELETE ADDRESS
 *********************************/
function deleteAddress($conn, $id) {
    $userId = getUserId();
    if (!$userId) {
        response(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    $stmt = $conn->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);

    if ($stmt->rowCount() > 0) {
        response([
            'success' => true,
            'message' => 'Address deleted successfully'
        ]);
    } else {
        response([
            'success' => false,
            'error' => 'Address not found'
        ], 404);
    }
}

/*********************************
 * GOOGLE PLACES AUTOCOMPLETE
 *********************************/
function autocompletePlaces($input) {
    if (strlen($input) < 2) {
        response(['success' => true, 'suggestions' => []]);
    }

    $url = "https://maps.googleapis.com/maps/api/place/autocomplete/json?"
        . "input=" . urlencode($input)
        . "&components=country:mw"
        . "&key=" . GOOGLE_MAPS_API_KEY;

    $data = callGoogleAPI($url);
    $suggestions = [];

    if ($data && $data['status'] === 'OK') {
        foreach ($data['predictions'] as $prediction) {
            $suggestions[] = [
                'name' => $prediction['description'],
                'place_id' => $prediction['place_id']
            ];
        }
    }

    response([
        'success' => true,
        'suggestions' => $suggestions
    ]);
}

/*********************************
 * GET PLACE DETAILS FROM PLACE ID
 *********************************/
function getPlaceDetails($placeId) {
    if (empty($placeId)) {
        response(['success' => false, 'error' => 'Place ID is required'], 400);
    }

    $url = "https://maps.googleapis.com/maps/api/place/details/json?"
        . "place_id=" . urlencode($placeId)
        . "&key=" . GOOGLE_MAPS_API_KEY;

    $data = callGoogleAPI($url);

    if ($data && $data['status'] === 'OK') {
        $result = $data['result'];
        response([
            'success' => true,
            'place_id' => $result['place_id'],
            'formatted_address' => $result['formatted_address'],
            'latitude' => $result['geometry']['location']['lat'],
            'longitude' => $result['geometry']['location']['lng']
        ]);
    } else {
        response([
            'success' => false,
            'error' => 'Failed to get place details'
        ], 400);
    }
}

/*********************************
 * REVERSE GEOCODE FROM LATITUDE/LONGITUDE
 *********************************/
function reverseGeocode($lat, $lng) {
    if (empty($lat) || empty($lng)) {
        response(['success' => false, 'error' => 'Latitude and longitude are required'], 400);
    }

    $url = "https://maps.googleapis.com/maps/api/geocode/json?"
        . "latlng={$lat},{$lng}"
        . "&key=" . GOOGLE_MAPS_API_KEY;

    $data = callGoogleAPI($url);

    if ($data && $data['status'] === 'OK' && count($data['results']) > 0) {
        $result = $data['results'][0];
        response([
            'success' => true,
            'formatted_address' => $result['formatted_address'],
            'place_id' => $result['place_id'],
            'latitude' => floatval($lat),
            'longitude' => floatval($lng)
        ]);
    } else {
        response([
            'success' => false,
            'error' => 'Could not get address for this location'
        ], 400);
    }
}

/*********************************
 * ROUTER
 *********************************/
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Create table if not exists
    createTableIfNotExists($conn);

    $method = $_SERVER['REQUEST_METHOD'];

    // GET requests
    if ($method === 'GET') {
        // Check for autocomplete
        if (isset($_GET['autocomplete'])) {
            autocompletePlaces($_GET['input'] ?? '');
        } 
        // Check for place details by place_id
        elseif (isset($_GET['place_details']) && isset($_GET['place_id'])) {
            getPlaceDetails($_GET['place_id'] ?? '');
        }
        // Check for reverse geocode (lat/lng)
        elseif (isset($_GET['lat']) && isset($_GET['lng'])) {
            reverseGeocode($_GET['lat'], $_GET['lng']);
        }
        // Default: get user addresses
        else {
            getAddresses($conn);
        }
    } 
    // POST requests
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Check if it's a place details request
        if (isset($input['action']) && $input['action'] === 'place_details') {
            getPlaceDetails($input['place_id'] ?? '');
        } 
        // Default: create address
        else {
            createAddress($conn, $input);
        }
    } 
    // DELETE requests
    elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            response(['success' => false, 'error' => 'Address ID required'], 400);
        }
        deleteAddress($conn, $id);
    } 
    else {
        response(['success' => false, 'error' => 'Method not allowed'], 405);
    }

} catch (Exception $e) {
    response([
        'success' => false, 
        'error' => $e->getMessage()
    ], 500);
}
?>