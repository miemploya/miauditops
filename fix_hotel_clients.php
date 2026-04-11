<?php
require_once 'config/db.php';
try {
    $stmt = $pdo->query("SELECT id, company_id, plan_name, max_clients FROM company_subscriptions WHERE plan_name = 'hotel_revenue'");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Current Hotel Revenue Subscriptions:\n";
    print_r($results);

    // Let's reset all hotel revenue subscriptions to max_clients = 0 (unlimited)
    $updateStmt = $pdo->query("UPDATE company_subscriptions SET max_clients = 0 WHERE plan_name = 'hotel_revenue'");
    echo "\nRows updated: " . $updateStmt->rowCount() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
