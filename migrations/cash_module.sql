-- =====================================================
-- MIAUDITOPS — Cash Management Module Migration
-- Created: 2026-03-23
-- Tables: cash_expense_categories, cash_sales, cash_requisitions, cash_ledger
-- =====================================================

-- 1. Expense categories for cash requisitions (accountant-managed)
CREATE TABLE IF NOT EXISTS `cash_expense_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `client_id` INT(11) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cat_name` (`company_id`, `client_id`, `name`),
  KEY `idx_company_client` (`company_id`, `client_id`),
  CONSTRAINT `cash_exp_cat_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Cash sales posted by staff
CREATE TABLE IF NOT EXISTS `cash_sales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `client_id` INT(11) NOT NULL,
  `sale_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `description` VARCHAR(500) DEFAULT NULL,
  `department` VARCHAR(100) DEFAULT NULL,
  `posted_by` INT(11) NOT NULL,
  `posted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `confirmed_by` INT(11) DEFAULT NULL,
  `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
  `status` ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company_client_date` (`company_id`, `client_id`, `sale_date`),
  KEY `idx_status` (`company_id`, `client_id`, `status`),
  CONSTRAINT `cash_sales_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cash_sales_posted_fk` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cash_sales_confirmed_fk` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Cash requisitions (expenses + bank deposits)
CREATE TABLE IF NOT EXISTS `cash_requisitions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `client_id` INT(11) NOT NULL,
  `requisition_number` VARCHAR(50) NOT NULL,
  `requested_by` INT(11) NOT NULL,
  `category_id` INT(11) DEFAULT NULL,
  `description` VARCHAR(500) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `type` ENUM('expense','bank_deposit') NOT NULL DEFAULT 'expense',
  `bank_name` VARCHAR(100) DEFAULT NULL,
  `account_number` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` INT(11) DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `month_year` VARCHAR(7) DEFAULT NULL COMMENT 'Period grouping e.g. 2026-03',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_req_number` (`company_id`, `requisition_number`),
  KEY `idx_company_client_status` (`company_id`, `client_id`, `status`),
  KEY `idx_category` (`category_id`),
  KEY `idx_month` (`company_id`, `client_id`, `month_year`),
  CONSTRAINT `cash_req_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cash_req_user_fk` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cash_req_category_fk` FOREIGN KEY (`category_id`) REFERENCES `cash_expense_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cash_req_approved_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Cash ledger — DR/CR journal
CREATE TABLE IF NOT EXISTS `cash_ledger` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `client_id` INT(11) NOT NULL,
  `entry_date` DATE NOT NULL,
  `reference_type` ENUM('sale','requisition','deposit','adjustment') NOT NULL,
  `reference_id` INT(11) DEFAULT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `dr_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `cr_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `posted_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_company_client_date` (`company_id`, `client_id`, `entry_date`),
  KEY `idx_reference` (`reference_type`, `reference_id`),
  CONSTRAINT `cash_ledger_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cash_ledger_user_fk` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
