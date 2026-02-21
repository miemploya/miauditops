<?php
/**
 * Migration: Create support_tickets table
 * Enterprise clients can submit support tickets to the platform owner
 */
require_once __DIR__ . '/../config/db.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS support_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        user_id INT NOT NULL,
        category ENUM('complaint','enquiry','request','support') DEFAULT 'enquiry',
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
        admin_reply TEXT NULL,
        replied_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (company_id),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo "support_tickets table created.\n";
