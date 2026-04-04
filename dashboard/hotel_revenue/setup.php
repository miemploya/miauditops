<?php
/**
 * Database Setup Script — Run once to create tables
 */

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'hotel_revenue';

try {
    // Connect without database first to create it
    $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");

    // Company settings table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_name` VARCHAR(255) NOT NULL DEFAULT 'Hotel Company',
            `company_address` VARCHAR(500) DEFAULT '',
            `company_phone` VARCHAR(50) DEFAULT '',
            `company_email` VARCHAR(255) DEFAULT '',
            `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '₦',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    // Insert default settings if empty
    $check = $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("INSERT INTO settings (company_name) VALUES ('Hotel Company')");
    }

    // Imports (each uploaded file = one import)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `imports` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
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

    // Import records (each extracted row)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `import_records` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `import_id` INT NOT NULL,
            `original_text` VARCHAR(500) NOT NULL,
            `room_type` VARCHAR(255) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (`import_id`) REFERENCES `imports`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    // Import breakdown (aggregated per room type per import)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `import_breakdown` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `import_id` INT NOT NULL,
            `room_type` VARCHAR(255) NOT NULL,
            `count` INT NOT NULL DEFAULT 0,
            `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `percentage` DECIMAL(5,1) NOT NULL DEFAULT 0,
            FOREIGN KEY (`import_id`) REFERENCES `imports`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    echo "<!DOCTYPE html><html><head><title>Setup Complete</title><style>
        body{font-family:'Inter',sans-serif;background:#06060f;color:#eee;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
        .box{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:40px;text-align:center;max-width:450px}
        h1{color:#00d4aa;font-size:1.5rem;margin-bottom:12px}
        p{color:#8b8ba7;font-size:.9rem;margin-bottom:20px}
        a{display:inline-block;padding:10px 24px;background:linear-gradient(135deg,#6c63ff,#00d4aa);color:#fff;border-radius:10px;text-decoration:none;font-weight:600;font-size:.88rem}
        a:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(108,99,255,.4)}
        .check{width:60px;height:60px;border-radius:50%;background:rgba(0,212,170,.12);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px}
    </style></head><body>
    <div class='box'>
        <div class='check'>✓</div>
        <h1>Database Ready!</h1>
        <p>All tables have been created successfully. You can now start importing Excel files.</p>
        <a href='dashboard.html'>Go to Dashboard</a>
    </div></body></html>";

} catch (PDOException $e) {
    echo "<h1 style='color:red'>Setup Failed</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
