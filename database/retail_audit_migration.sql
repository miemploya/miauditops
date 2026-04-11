-- =========================================================
-- COMPLETE RETAIL AUDIT & INVENTORY SUITE (MIGRATION FILE)
-- =========================================================
-- Use this file to set up the retail features in production.

CREATE TABLE IF NOT EXISTS `retail_categories` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `company_id` int(11) NOT NULL,
    `client_id` int(11) NOT NULL,
    `outlet_id` int(11) NOT NULL,
    `name` varchar(255) NOT NULL,
    `created_at` timestamp DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `retail_suppliers` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `company_id` int(11) NOT NULL,
    `client_id` int(11) NOT NULL,
    `outlet_id` int(11) NOT NULL,
    `name` varchar(255) NOT NULL,
    `phone` varchar(50) DEFAULT NULL,
    `email` varchar(100) DEFAULT NULL,
    `created_at` timestamp DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `retail_products` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `company_id` int(11) NOT NULL,
    `client_id` int(11) NOT NULL,
    `outlet_id` int(11) NOT NULL,
    `supplier_id` int(11) DEFAULT NULL,
    `name` varchar(255) NOT NULL,
    `category` varchar(150) DEFAULT NULL,
    `sku` varchar(100) DEFAULT NULL,
    `unit` varchar(50) DEFAULT 'pcs',
    `unit_cost` decimal(15,2) DEFAULT '0.00',
    `selling_price` decimal(15,2) DEFAULT '0.00',
    `current_system_stock` decimal(15,2) DEFAULT '0.00',
    `expiry_date` date DEFAULT NULL,
    `cost_price` decimal(10,2) DEFAULT '0.00',
    `pack_qty` decimal(10,2) DEFAULT '1.00',
    `bulk_unit` varchar(50) DEFAULT 'Pack',
    `created_at` timestamp DEFAULT current_timestamp(),
    `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `retail_purchases` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `company_id` int(11) NOT NULL,
    `client_id` int(11) NOT NULL,
    `outlet_id` int(11) NOT NULL,
    `product_id` int(11) NOT NULL,
    `supplier_id` int(11) DEFAULT NULL,
    `quantity_added` decimal(15,2) NOT NULL,
    `total_cost` decimal(15,2) DEFAULT '0.00',
    `purchase_date` date NOT NULL,
    `reference` varchar(100) DEFAULT NULL,
    `created_at` timestamp DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `retail_audit_sessions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `company_id` int(11) NOT NULL,
    `client_id` int(11) NOT NULL,
    `outlet_id` int(11) NOT NULL,
    `audit_date` date NOT NULL,
    `audit_range_start` date DEFAULT NULL,
    `audit_range_end` date DEFAULT NULL,
    `status` enum('draft','finalized') DEFAULT 'draft',
    `auditor_notes` text DEFAULT NULL,
    `created_by` int(11) DEFAULT '0',
    `created_at` timestamp DEFAULT current_timestamp(),
    `session_name` varchar(150) DEFAULT NULL,
    `total_items_counted` int(11) DEFAULT '0',
    `total_physical_value` decimal(15,2) DEFAULT '0.00',
    `declared_pos` decimal(15,2) DEFAULT '0.00',
    `declared_transfer` decimal(15,2) DEFAULT '0.00',
    `declared_cash` decimal(15,2) DEFAULT '0.00',
    `adj_add_to_sales` decimal(15,2) DEFAULT '0.00',
    `adj_damages` decimal(15,2) DEFAULT '0.00',
    `adj_written_off` decimal(15,2) DEFAULT '0.00',
    `adj_complimentary` decimal(15,2) DEFAULT '0.00',
    `adj_error` decimal(15,2) DEFAULT '0.00',
    `total_expected_sales` decimal(15,2) DEFAULT '0.00',
    `original_frozen_json` longtext DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `retail_audit_lines` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` int(11) NOT NULL,
    `product_id` int(11) NOT NULL,
    `opening_stock` decimal(15,2) DEFAULT '0.00',
    `purchases_in_period` decimal(15,2) DEFAULT '0.00',
    `expected_stock` decimal(15,2) DEFAULT '0.00',
    `physical_count` decimal(15,2) DEFAULT '0.00',
    `system_sold_qty` decimal(15,2) DEFAULT '0.00',
    `calculated_sold_qty` decimal(15,2) DEFAULT '0.00',
    `variance_qty` decimal(15,2) DEFAULT '0.00',
    `variance_value` decimal(15,2) DEFAULT '0.00',
    `system_qty` decimal(12,2) DEFAULT '0.00',
    `unit_cost` decimal(12,2) DEFAULT '0.00',
    `selling_price` decimal(12,2) DEFAULT '0.00',
    `physical_qty` decimal(12,2) DEFAULT '0.00',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
