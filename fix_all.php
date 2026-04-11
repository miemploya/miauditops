<?php
require_once 'config/db.php';
try {
    $updateStmt = $pdo->query("UPDATE company_subscriptions SET max_users = 0, max_clients = 0, max_outlets = 0, max_products = 0, max_departments = 0 WHERE plan_name = 'hotel_revenue'");
    echo "\nRows updated (hotel revenue reset to unlimited): " . $updateStmt->rowCount() . "\n";
    
    // Print all to verify
    $stmt = $pdo->query("SELECT company_id, plan_name, max_users, max_clients, max_outlets FROM company_subscriptions");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
