<?php
/**
 * Create payments table for Paystack integration
 */
require_once __DIR__ . '/../config/db.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        user_id INT NOT NULL,
        reference VARCHAR(100) UNIQUE NOT NULL,
        plan_name VARCHAR(50) NOT NULL,
        billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
        amount_kobo INT NOT NULL,
        currency VARCHAR(5) DEFAULT 'NGN',
        status ENUM('pending','success','failed') DEFAULT 'pending',
        paystack_response TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        verified_at TIMESTAMP NULL,
        INDEX idx_company (company_id),
        INDEX idx_reference (reference),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo "Payments table created successfully.\n";
?>
