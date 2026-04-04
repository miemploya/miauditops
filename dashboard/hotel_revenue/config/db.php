<?php
/**
 * Database Configuration — Hotel Revenue Extractor
 * Bridges to MIIAUDITOPS Database
 */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Ensure user is logged in for API calls that include this
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please log in.']);
    exit;
}

$company_id = $_SESSION['company_id'];

// Enforce subscription optionally (if uncommented)
// if (!subscription_allows_module('hotel_revenue')) {
//    http_response_code(403);
//    echo json_encode(['error' => 'Upgrade required to use Hotel Revenue']);
//    exit;
// }

// Auto-create tables in the current MIIAUDITOPS DB if they don't exist
try {
    // Hotel Revenue Imports
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hotel_imports` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT NOT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `total_revenue` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `total_records` INT NOT NULL DEFAULT 0,
            `total_room_types` INT NOT NULL DEFAULT 0,
            `average_rate` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `import_date` DATE NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hotel_import_records` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `import_id` INT NOT NULL,
            `original_text` VARCHAR(500) NOT NULL,
            `room_type` VARCHAR(255) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (`import_id`) REFERENCES `hotel_imports`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hotel_import_breakdown` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `import_id` INT NOT NULL,
            `room_type` VARCHAR(255) NOT NULL,
            `count` INT NOT NULL DEFAULT 0,
            `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `percentage` DECIMAL(5,1) NOT NULL DEFAULT 0,
            FOREIGN KEY (`import_id`) REFERENCES `hotel_imports`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hotel_settings` (
            `company_id` INT PRIMARY KEY,
            `company_name` VARCHAR(255) NOT NULL DEFAULT 'Hotel Company',
            `company_address` VARCHAR(500) DEFAULT '',
            `company_phone` VARCHAR(50) DEFAULT '',
            `company_email` VARCHAR(255) DEFAULT '',
            `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '₦',
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");
} catch (Exception $e) {
    // Silently continue or log error
}
