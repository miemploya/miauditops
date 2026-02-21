<?php
/**
 * Create platform_notifications table for owner broadcast messages.
 */
require_once __DIR__ . '/../config/db.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS platform_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('info','warning','success','alert') DEFAULT 'info',
        target ENUM('all','plan') DEFAULT 'all',
        target_plan VARCHAR(50) NULL COMMENT 'If target=plan, which plan',
        created_by VARCHAR(100) DEFAULT 'platform_owner',
        is_active TINYINT(1) DEFAULT 1,
        expires_at DATETIME NULL COMMENT 'Auto-hide after this date',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_active (is_active, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add expires_at column if table already exists without it
try {
    $pdo->exec("ALTER TABLE platform_notifications ADD COLUMN expires_at DATETIME NULL COMMENT 'Auto-hide after this date' AFTER is_active");
} catch (PDOException $e) {
    // Column already exists — ignore
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS notification_reads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        notification_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_read (notification_id, user_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo "platform_notifications table created.\n";
echo "notification_reads table created.\n";
?>
