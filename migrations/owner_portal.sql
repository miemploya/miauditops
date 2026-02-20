-- =====================================================
-- OWNER PORTAL — Database Migration
-- Platform owner management & subscription tracking
-- =====================================================

-- Platform owners (separate from tenant users)
CREATE TABLE IF NOT EXISTS platform_owners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(200) NOT NULL,
    email VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Subscription tracking per company
CREATE TABLE IF NOT EXISTS company_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    plan_name VARCHAR(100) DEFAULT 'free',
    status ENUM('active','trial','expired','suspended') DEFAULT 'trial',
    started_at DATE,
    expires_at DATE,
    max_users INT DEFAULT 5,
    max_outlets INT DEFAULT 3,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed default owner (CHANGE IN PRODUCTION)
INSERT INTO platform_owners (username, password, name, email)
VALUES ('miemploya', '$2y$10$mJPZlwKkDKD7JrDS9ha4fO7k3bs3AoqGnl1B9r.R7XLalhHE2ROUO', 'Platform Owner', 'admin@miauditops.com')
ON DUPLICATE KEY UPDATE username = username;
