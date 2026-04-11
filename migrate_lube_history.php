<?php
require_once 'config/db.php';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        session_id INT NOT NULL,
        transaction_id VARCHAR(50) NOT NULL,
        action_type ENUM('save','delete','issue','finalize','rollback','unpush') NOT NULL,
        action_description VARCHAR(255),
        entity_type VARCHAR(50) NOT NULL,
        entity_id INT NOT NULL,
        before_json LONGTEXT,
        after_json LONGTEXT,
        is_undone TINYINT(1) DEFAULT 0,
        performed_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (session_id, company_id),
        INDEX (created_at),
        INDEX (transaction_id)
    )");
    echo "SQL Migration: station_lube_history created successfully.\n";
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}
?>
