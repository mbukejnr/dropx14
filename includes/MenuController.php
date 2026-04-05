<?php
// includes/MenuController.php - Complete version
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ResponseHandler.php';

class MenuController {
    private $conn;
    private $response;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->response = new ResponseHandler();
    }
    
    public function getMenuItems($merchantId, $filters = []) {
        try {
            $defaults = [
                'category' => 'all',
                'search' => '',
                'sort_by' => 'recommended',
                'sort_dir' => 'desc',
                'min_price' => 0,
                'max_price' => 50000,
                'in_stock' => true,
                'limit' => 100,
                'offset' => 0
            ];
            
            $filters = array_merge($defaults, $filters);
            
            $query = "
                SELECT 
                    mi.*,
                    mc.name as category_name,
                    mc.slug as category_slug
                FROM menu_items mi
                LEFT JOIN merchant_categories mc ON mi.category_id = mc.id
                WHERE mi.restaurant_id = :merchant_id 
                  AND mi.is_active = 1
            ";
            
            $params = [':merchant_id' => $merchantId];
            
            if ($filters['category'] !== 'all') {
                $query .= " AND mc.slug = :category";
                $params[':category'] = $filters['category'];
            }
            
            if (!empty($filters['search'])) {
                $query .= " AND (mi.name LIKE :search OR mi.description LIKE :search)";
                $params[':search'] = "%{$filters['search']}%";
            }
            
            if ($filters['in_stock']) {
                $query .= " AND mi.in_stock = 1";
            }
            
            if ($filters['min_price'] > 0) {
                $query .= " AND mi.price >= :min_price";
                $params[':min_price'] = $filters['min_price'];
            }
            
            if ($filters['max_price'] < 50000) {
                $query .= " AND mi.price <= :max_price";
                $params[':max_price'] = $filters['max_price'];
            }
            
            $orderBy = $this->getOrderByClause($filters['sort_by'], $filters['sort_dir']);
            $query .= " ORDER BY {$orderBy}";
            $query .= " LIMIT :limit OFFSET :offset";
            
            $params[':limit'] = (int) $filters['limit'];
            $params[':offset'] = (int) $filters['offset'];
            
            $stmt = $this->conn->prepare($query);
            
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $type);
            }
            
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count for pagination
            $countQuery = "SELECT COUNT(*) as total 
                          FROM menu_items mi
                          LEFT JOIN merchant_categories mc ON mi.category_id = mc.id
                          WHERE mi.restaurant_id = :merchant_id AND mi.is_active = 1";
            
            $countParams = [':merchant_id' => $merchantId];
            
            if ($filters['category'] !== 'all') {
                $countQuery .= " AND mc.slug = :category";
                $countParams[':category'] = $filters['category'];
            }
            
            if ($filters['in_stock']) {
                $countQuery .= " AND mi.in_stock = 1";
            }
            
            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->execute($countParams);
            $total = $countStmt->fetch()['total'];
            
            $formattedItems = array_map([$this, 'formatMenuItem'], $items);
            
            return $this->response->success([
                'items' => $formattedItems,
                'pagination' => [
                    'total' => (int) $total,
                    'limit' => (int) $filters['limit'],
                    'offset' => (int) $filters['offset'],
                    'hasMore' => ($filters['offset'] + count($formattedItems)) < $total
                ]
            ], 'Menu items retrieved');
            
        } catch (Exception $e) {
            error_log("Get Menu Items Error: " . $e->getMessage());
            return $this->response->serverError();
        }
    }
    
    public function getMenuItem($itemId) {
        try {
            $query = "
                SELECT 
                    mi.*,
                    mc.name as category_name,
                    mc.slug as category_slug,
                    r.name as restaurant_name,
                    r.id as restaurant_id,
                    r.delivery_time,
                    r.delivery_fee
                FROM menu_items mi
                JOIN merchant_categories mc ON mi.category_id = mc.id
                JOIN restaurants r ON mi.restaurant_id = r.id
                WHERE mi.id = :item_id OR mi.uuid = :item_id
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':item_id' => $itemId]);
            $item = $stmt->fetch();
            
            if (!$item) {
                return $this->response->notFound('Menu item not found');
            }
            
            $formattedItem = $this->formatMenuItem($item);
            $formattedItem['restaurant'] = [
                'id' => $item['restaurant_id'],
                'name' => $item['restaurant_name'],
                'deliveryTime' => $item['delivery_time'],
                'deliveryFee' => $item['delivery_fee']
            ];
            
            // Get similar items
            $similarItems = $this->getSimilarItems($item['restaurant_id'], $item['category_id'], $item['id']);
            $formattedItem['similarItems'] = $similarItems;
            
            return $this->response->success($formattedItem, 'Menu item retrieved');
            
        } catch (Exception $e) {
            error_log("Get Menu Item Error: " . $e->getMessage());
            return $this->response->serverError();
        }
    }
    
    public function searchMenuItems($searchTerm, $filters = []) {
        try {
            $defaults = [
                'merchant_id' => null,
                'category' => null,
                'min_price' => 0,
                'max_price' => 50000,
                'limit' => 20,
                'offset' => 0
            ];
            
            $filters = array_merge($defaults, $filters);
            
            $query = "
                SELECT 
                    mi.*,
                    mc.name as category_name,
                    mc.slug as category_slug,
                    r.name as restaurant_name,
                    r.id as restaurant_id
                FROM menu_items mi
                JOIN merchant_categories mc ON mi.category_id = mc.id
                JOIN restaurants r ON mi.restaurant_id = r.id
                WHERE mi.is_active = 1 
                  AND mi.in_stock = 1
                  AND r.status = 'active'
                  AND (mi.name LIKE :search OR mi.description LIKE :search)
            ";
            
            $params = [':search' => "%{$searchTerm}%"];
            
            if ($filters['merchant_id']) {
                $query .= " AND mi.restaurant_id = :merchant_id";
                $params[':merchant_id'] = $filters['merchant_id'];
            }
            
            if ($filters['category']) {
                $query .= " AND mc.slug = :category";
                $params[':category'] = $filters['category'];
            }
            
            if ($filters['min_price'] > 0) {
                $query .= " AND mi.price >= :min_price";
                $params[':min_price'] = $filters['min_price'];
            }
            
            if ($filters['max_price'] < 50000) {
                $query .= " AND mi.price <= :max_price";
                $params[':max_price'] = $filters['max_price'];
            }
            
            $query .= " ORDER BY mi.is_popular DESC, mi.rating DESC
                      LIMIT :limit OFFSET :offset";
            
            $params[':limit'] = (int) $filters['limit'];
            $params[':offset'] = (int) $filters['offset'];
            
            $stmt = $this->conn->prepare($query);
            
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $type);
            }
            
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $formattedItems = array_map(function($item) {
                $formatted = $this->formatMenuItem($item);
                $formatted['restaurant'] = [
                    'id' => $item['restaurant_id'],
                    'name' => $item['restaurant_name']
                ];
                return $formatted;
            }, $items);
            
            return $this->response->success([
                'items' => $formattedItems,
                'searchTerm' => $searchTerm,
                'count' => count($formattedItems)
            ], 'Search results');
            
        } catch (Exception $e) {
            error_log("Search Menu Items Error: " . $e->getMessage());
            return $this->response->serverError();
        }
    }
    
    public function getCategories($merchantId) {
        try {
            $query = "
                SELECT 
                    id,
                    name,
                    slug,
                    description,
                    display_order,
                    item_count
                FROM merchant_categories 
                WHERE restaurant_id = :merchant_id 
                  AND is_active = 1
                ORDER BY display_order, name
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':merchant_id' => $merchantId]);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $formattedCategories = array_map(function($category) {
                return [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'slug' => $category['slug'],
                    'description' => $category['description'],
                    'itemCount' => (int) $category['item_count']
                ];
            }, $categories);
            
            return $this->response->success([
                'categories' => $formattedCategories
            ], 'Categories retrieved');
            
        } catch (Exception $e) {
            error_log("Get Categories Error: " . $e->getMessage());
            return $this->response->serverError();
        }
    }
    
    public function addItemReview($reviewData) {
        try {
            $this->conn->beginTransaction();
            
            // Check if user has ordered this item
            $orderCheck = "
                SELECT oi.id 
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE o.user_id = :user_id 
                  AND oi.item_name LIKE CONCAT('%', :item_name, '%')
                LIMIT 1
            ";
            
            $itemStmt = $this->conn->prepare("SELECT name FROM menu_items WHERE id = :item_id");
            $itemStmt->execute([':item_id' => $reviewData['item_id']]);
            $item = $itemStmt->fetch();
            
            if (!$item) {
                throw new Exception('Menu item not found');
            }
            
            $checkStmt = $this->conn->prepare($orderCheck);
            $checkStmt->execute([
                ':user_id' => $reviewData['user_id'],
                ':item_name' => $item['name']
            ]);
            $hasOrdered = $checkStmt->fetch() !== false;
            
            // Insert review
            $insertQuery = "
                INSERT INTO menu_item_reviews 
                (menu_item_id, user_id, rating, comment, images, is_verified, status, created_at)
                VALUES (:item_id, :user_id, :rating, :comment, :images, :verified, 'pending', NOW())
            ";
            
            $insertStmt = $this->conn->prepare($insertQuery);
            $insertStmt->execute([
                ':item_id' => $reviewData['item_id'],
                ':user_id' => $reviewData['user_id'],
                ':rating' => $reviewData['rating'],
                ':comment' => $reviewData['comment'],
                ':images' => json_encode($reviewData['images']),
                ':verified' => $hasOrdered ? 1 : 0
            ]);
            
            $this->conn->commit();
            
            return $this->response->success([
                'reviewId' => $this->conn->lastInsertId(),
                'isVerified' => $hasOrdered
            ], 'Review submitted successfully');
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Add Item Review Error: " . $e->getMessage());
            return $this->response->serverError();
        }
    }
    
    public function updateItemStock($updateData) {
        try {
            $this->conn->beginTransaction();
            
            $query = "
                UPDATE menu_items 
                SET in_stock = :in_stock,
                    updated_at = NOW()
                WHERE id = :item_id OR uuid = :item_id
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':in_stock' => $updateData['in_stock'] ? 1 : 0,
                ':item_id' => $updateData['item_id']
            ]);
            
            $this->conn->commit();
            
            return $this->response->success([
                'itemId' => $updateData['item_id'],
                'inStock' => (bool) $updateData['in_stock']
            ], 'Item stock updated');
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Update Item Stock Error: " . $e->getMessage());
            return $this->response->serverError();
        }
    }
    
    private function formatMenuItem($item) {
        $tags = json_decode($item['tags'] ?? '[]', true);
        
        // Add emoji tags based on item properties (for frontend display)
        if ($item['is_popular']) {
            $tags[] = 'Popular';
        }
        if ($item['is_signature']) {
            $tags[] = 'Signature';
        }
        if ($item['is_organic']) {
            $tags[] = 'Organic';
        }
        if ($item['is_healthy']) {
            $tags[] = 'Healthy';
        }
        
        return [
            'id' => $item['uuid'] ?? $item['id'],
            'name' => $item['name'],
            'description' => $item['description'],
            'price' => (float) $item['price'],
            'discountedPrice' => $item['discounted_price'] ? (float) $item['discounted_price'] : null,
            'category' => $item['category_slug'],
            'categoryName' => $item['category_name'],
            'image' => $item['image_url'], // From database, could be NULL
            'tags' => $tags,
            'prepTime' => $item['prep_time'],
            'calories' => $item['calories'] ? (int) $item['calories'] : null,
            'dietary' => json_decode($item['dietary'] ?? '[]', true),
            'allergens' => json_decode($item['allergens'] ?? '[]', true),
            'rating' => (float) $item['rating'],
            'reviewCount' => (int) $item['review_count'],
            'inStock' => (bool) $item['in_stock'],
            'isOrganic' => (bool) $item['is_organic'],
            'isPopular' => (bool) $item['is_popular'],
            'isSignature' => (bool) $item['is_signature'],
            'isHealthy' => (bool) $item['is_healthy'],
            'unit' => $item['unit'],
            'customizationOptions' => json_decode($item['customization_options'] ?? '[]', true),
            'nutritionalInfo' => json_decode($item['nutritional_info'] ?? '{}', true)
        ];
    }
    
    private function getOrderByClause($sortBy, $sortDir) {
        $direction = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
        
        switch ($sortBy) {
            case 'price-low':
                return "mi.price ASC";
            case 'price-high':
                return "mi.price DESC";
            case 'rating':
                return "mi.rating {$direction}, mi.review_count DESC";
            case 'name':
                return "mi.name {$direction}";
            case 'popular':
                return "mi.is_popular DESC, mi.rating DESC";
            default: // recommended
                return "mi.is_popular DESC, mi.is_signature DESC, mi.rating DESC, 
                       mi.review_count DESC, mi.display_order ASC";
        }
    }
    
    private function getSimilarItems($restaurantId, $categoryId, $excludeItemId, $limit = 6) {
        try {
            $query = "
                SELECT mi.*
                FROM menu_items mi
                WHERE mi.restaurant_id = :restaurant_id
                  AND mi.id != :exclude_id
                  AND mi.is_active = 1
                  AND mi.in_stock = 1
                ORDER BY 
                  CASE WHEN mi.category_id = :category_id THEN 1 ELSE 2 END,
                  mi.is_popular DESC,
                  mi.rating DESC
                LIMIT :limit
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':restaurant_id' => $restaurantId,
                ':exclude_id' => $excludeItemId,
                ':category_id' => $categoryId,
                ':limit' => $limit
            ]);
            
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return array_map([$this, 'formatMenuItem'], $items);
            
        } catch (Exception $e) {
            error_log("Get Similar Items Error: " . $e->getMessage());
            return [];
        }
    }
}
?>