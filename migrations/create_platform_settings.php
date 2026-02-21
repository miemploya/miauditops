<?php
/**
 * Create platform_settings table and seed default pricing.
 */
require_once __DIR__ . '/../config/db.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS platform_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Seed default pricing if not already present
$defaults = [
    'price_professional_monthly' => '25000',
    'price_professional_quarterly' => '67500',
    'price_professional_annual' => '240000',
    'price_enterprise_monthly' => '75000',
    'price_enterprise_quarterly' => '202500',
    'price_enterprise_annual' => '720000',
];

$stmt = $pdo->prepare("INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES (?, ?)");
foreach ($defaults as $key => $value) {
    $stmt->execute([$key, $value]);
}

echo "platform_settings table created and seeded.\n";

// Also create password_reset_tokens table for forgot password
$pdo->exec("
    CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        company_id INT NOT NULL,
        token VARCHAR(64) UNIQUE NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo "password_reset_tokens table created.\n";
?>
