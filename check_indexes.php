<?php
include 'config/db.php';
$tables = ['station_audit_sessions', 'station_system_sales', 'station_pump_tables', 'station_pump_readings', 'station_tank_dipping', 'station_expense_ledger', 'station_debtor_ledger', 'station_lube_items', 'station_counter_stock_count_items', 'station_lube_grn'];
foreach ($tables as $t) {
    echo "\n--- Table: $t ---\n";
    try {
        $stmt = $pdo->query("SHOW INDEX FROM $t");
        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $idx) {
            echo "Index: {$idx['Key_name']} | Column: {$idx['Column_name']} | Seq: {$idx['Seq_in_index']}\n";
        }
    } catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }
}
