<?php
/**
 * Create billing_invoices table for invoice management.
 * Columns derived from payment_api.php (auto_generate_invoice, pay_invoice)
 * and payment_callback.php (mark invoice paid).
 */
require_once __DIR__ . '/../config/db.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS billing_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        invoice_number VARCHAR(50) NOT NULL,
        plan_name VARCHAR(50) NOT NULL,
        billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
        amount_naira DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
        due_date DATE NULL,
        period_start DATE NULL,
        period_end DATE NULL,
        payment_reference VARCHAR(100) NULL,
        paid_at TIMESTAMP NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company (company_id),
        INDEX idx_status (status),
        INDEX idx_due_date (due_date),
        INDEX idx_payment_ref (payment_reference)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo "billing_invoices table created successfully.\n";

// Also ensure addon_client_packs column exists on company_subscriptions
try {
    $pdo->exec("ALTER TABLE company_subscriptions ADD COLUMN IF NOT EXISTS addon_client_packs INT DEFAULT 0 AFTER notes");
    echo "addon_client_packs column ensured on company_subscriptions.\n";
} catch (Exception $e) {
    echo "addon_client_packs column check: " . $e->getMessage() . "\n";
}
?>
