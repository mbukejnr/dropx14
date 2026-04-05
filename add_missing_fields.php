<?php
// add_missing_fields.php
header("Content-Type: text/html; charset=utf-8");

try {
    require_once 'config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "<h2>Adding Missing Fields to Users Table</h2>";
    
    // Check current structure
    $stmt = $conn->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    echo "<p>Current columns: " . implode(', ', $columns) . "</p>";
    
    // Add missing columns
    $missingColumns = [
        'name' => "VARCHAR(100) NOT NULL DEFAULT ''",
        'phone' => "VARCHAR(20) NOT NULL DEFAULT ''",
        'address' => "TEXT"
    ];
    
    foreach ($missingColumns as $column => $definition) {
        if (!in_array($column, $columns)) {
            try {
                $conn->exec("ALTER TABLE users ADD COLUMN $column $definition");
                echo "<p style='color:green;'>✅ Added column: $column</p>";
                
                // Update existing rows with default values
                if ($column === 'name') {
                    $conn->exec("UPDATE users SET name = username WHERE name = ''");
                }
            } catch (PDOException $e) {
                echo "<p style='color:red;'>❌ Failed to add $column: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p>Column already exists: $column</p>";
        }
    }
    
    // Show updated structure
    echo "<h3>Updated Table Structure:</h3>";
    $stmt = $conn->query("DESCRIBE users");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<h3 style='color:red;'>Error: " . $e->getMessage() . "</h3>";
}
?>