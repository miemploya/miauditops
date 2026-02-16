-- =====================================================
-- MIAUDITOPS — Database Schema v1.0
-- Miemploya Audit Operations System
-- =====================================================

CREATE DATABASE IF NOT EXISTS miauditops CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE miauditops;

-- =====================================================
-- CORE TABLES
-- =====================================================

CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(20) UNIQUE,
    email VARCHAR(255),
    phone VARCHAR(50),
    address TEXT,
    logo_url VARCHAR(255),
    currency VARCHAR(10) DEFAULT 'NGN',
    fiscal_year_start TINYINT DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    avatar_url VARCHAR(255),
    role ENUM('super_admin','business_owner','auditor','finance_officer','store_officer','department_head') DEFAULT 'department_head',
    department VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_email_company (email, company_id)
) ENGINE=InnoDB;

CREATE TABLE audit_trail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50),
    record_id INT,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- DAILY AUDIT & SALES CONTROL
-- =====================================================

CREATE TABLE sales_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    shift VARCHAR(20) DEFAULT 'day',
    pos_amount DECIMAL(15,2) DEFAULT 0.00,
    cash_amount DECIMAL(15,2) DEFAULT 0.00,
    transfer_amount DECIMAL(15,2) DEFAULT 0.00,
    other_amount DECIMAL(15,2) DEFAULT 0.00,
    declared_total DECIMAL(15,2) DEFAULT 0.00,
    actual_total DECIMAL(15,2) GENERATED ALWAYS AS (pos_amount + cash_amount + transfer_amount + other_amount) STORED,
    variance DECIMAL(15,2) GENERATED ALWAYS AS ((pos_amount + cash_amount + transfer_amount + other_amount) - declared_total) STORED,
    notes TEXT,
    entered_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_date (company_id, transaction_date)
) ENGINE=InnoDB;

CREATE TABLE bank_lodgments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    lodgment_date DATE NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(50),
    amount DECIMAL(15,2) NOT NULL,
    reference_number VARCHAR(100),
    lodged_by INT,
    confirmed_by INT,
    status ENUM('pending','confirmed','disputed') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (lodged_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE shift_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    report_date DATE NOT NULL,
    shift VARCHAR(20) NOT NULL,
    opening_cash DECIMAL(15,2) DEFAULT 0.00,
    closing_cash DECIMAL(15,2) DEFAULT 0.00,
    total_sales DECIMAL(15,2) DEFAULT 0.00,
    total_refunds DECIMAL(15,2) DEFAULT 0.00,
    cashier_name VARCHAR(100),
    status ENUM('open','closed','validated') DEFAULT 'open',
    closed_by INT,
    validated_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE variance_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    report_date DATE NOT NULL,
    category ENUM('sales','stock','expense') NOT NULL,
    expected_amount DECIMAL(15,2) NOT NULL,
    actual_amount DECIMAL(15,2) NOT NULL,
    variance_amount DECIMAL(15,2) GENERATED ALWAYS AS (actual_amount - expected_amount) STORED,
    severity ENUM('minor','moderate','major','critical') DEFAULT 'minor',
    resolution TEXT,
    resolved_by INT,
    resolved_at TIMESTAMP NULL,
    status ENUM('open','investigating','resolved','escalated') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE daily_audit_signoffs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    audit_date DATE NOT NULL,
    auditor_id INT,
    manager_id INT,
    auditor_signed_at TIMESTAMP NULL,
    manager_signed_at TIMESTAMP NULL,
    total_revenue DECIMAL(15,2) DEFAULT 0.00,
    total_variance DECIMAL(15,2) DEFAULT 0.00,
    auditor_comments TEXT,
    manager_comments TEXT,
    status ENUM('pending_auditor','pending_manager','completed','rejected') DEFAULT 'pending_auditor',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (auditor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_audit_date (company_id, audit_date)
) ENGINE=InnoDB;

-- =====================================================
-- STOCK CONTROL & INVENTORY
-- =====================================================

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(50),
    category VARCHAR(100),
    unit VARCHAR(30) DEFAULT 'pcs',
    unit_cost DECIMAL(15,2) DEFAULT 0.00,
    selling_price DECIMAL(15,2) DEFAULT 0.00,
    reorder_level INT DEFAULT 10,
    current_stock INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_sku (company_id, sku)
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    product_id INT NOT NULL,
    movement_date DATE NOT NULL,
    movement_type ENUM('stock_in','stock_out','adjustment','wastage','damage','return') NOT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(15,2) DEFAULT 0.00,
    total_value DECIMAL(15,2) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    reference_number VARCHAR(100),
    supplier_name VARCHAR(255),
    reason TEXT,
    entered_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_movement_date (company_id, movement_date)
) ENGINE=InnoDB;

CREATE TABLE stock_counts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    product_id INT NOT NULL,
    count_date DATE NOT NULL,
    system_stock INT NOT NULL,
    physical_count INT NOT NULL,
    variance INT GENERATED ALWAYS AS (physical_count - system_stock) STORED,
    notes TEXT,
    counted_by INT,
    verified_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (counted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE supplier_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    supplier_name VARCHAR(255) NOT NULL,
    delivery_date DATE NOT NULL,
    invoice_number VARCHAR(100),
    total_items INT DEFAULT 0,
    total_value DECIMAL(15,2) DEFAULT 0.00,
    received_by INT,
    status ENUM('pending','received','partial','disputed') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE wastage_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    product_id INT NOT NULL,
    wastage_date DATE NOT NULL,
    quantity INT NOT NULL,
    reason_code ENUM('expired','damaged','spillage','theft','other') NOT NULL,
    estimated_value DECIMAL(15,2) DEFAULT 0.00,
    notes TEXT,
    recorded_by INT,
    approved_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Department-level stock tracking (per product per department)
CREATE TABLE IF NOT EXISTS department_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    client_id INT NOT NULL,
    department_id INT NOT NULL,
    product_id INT NOT NULL,
    opening_stock INT DEFAULT 0,
    added INT DEFAULT 0,
    return_in INT DEFAULT 0,
    transfer_out INT DEFAULT 0,
    qty_sold INT DEFAULT 0,
    selling_price DECIMAL(15,2) DEFAULT 0.00,
    stock_date DATE NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dept_product_date (department_id, product_id, stock_date),
    INDEX idx_dept (company_id, client_id, department_id),
    INDEX idx_date (company_id, client_id, stock_date),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- FINANCIAL CONTROL & P&L
-- =====================================================

CREATE TABLE expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('cost_of_sales','operating','administrative','financial','other') DEFAULT 'operating',
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cost_centers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20),
    budget_amount DECIMAL(15,2) DEFAULT 0.00,
    period_start DATE,
    period_end DATE,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE revenue_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    entry_date DATE NOT NULL,
    source VARCHAR(100) NOT NULL,
    description TEXT,
    amount DECIMAL(15,2) NOT NULL,
    payment_method VARCHAR(50),
    reference_number VARCHAR(100),
    cost_center_id INT,
    entered_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL,
    FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_entry_date (company_id, entry_date)
) ENGINE=InnoDB;

CREATE TABLE expense_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    entry_date DATE NOT NULL,
    category_id INT,
    cost_center_id INT,
    description TEXT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_method VARCHAR(50),
    receipt_number VARCHAR(100),
    vendor VARCHAR(255),
    approved_by INT,
    entered_by INT,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_entry_date (company_id, entry_date)
) ENGINE=InnoDB;

CREATE TABLE financial_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    period_name VARCHAR(100) NOT NULL,
    period_type ENUM('weekly','monthly','quarterly','annual') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_revenue DECIMAL(15,2) DEFAULT 0.00,
    total_expenses DECIMAL(15,2) DEFAULT 0.00,
    cost_of_sales DECIMAL(15,2) DEFAULT 0.00,
    gross_profit DECIMAL(15,2) DEFAULT 0.00,
    net_profit DECIMAL(15,2) DEFAULT 0.00,
    status ENUM('open','closed','locked') DEFAULT 'open',
    closed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- REQUISITION MODULE
-- =====================================================

CREATE TABLE budget_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    department VARCHAR(100) NOT NULL,
    fiscal_year INT NOT NULL,
    allocated_amount DECIMAL(15,2) DEFAULT 0.00,
    spent_amount DECIMAL(15,2) DEFAULT 0.00,
    remaining_amount DECIMAL(15,2) GENERATED ALWAYS AS (allocated_amount - spent_amount) STORED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_dept_year (company_id, department, fiscal_year)
) ENGINE=InnoDB;

CREATE TABLE requisitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    requisition_number VARCHAR(50) NOT NULL,
    department VARCHAR(100) NOT NULL,
    requested_by INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    total_amount DECIMAL(15,2) DEFAULT 0.00,
    budget_allocation_id INT,
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    status ENUM('draft','submitted','manager_approved','audit_verified','finance_approved','purchase_ordered','delivered','closed','rejected') DEFAULT 'draft',
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (budget_allocation_id) REFERENCES budget_allocations(id) ON DELETE SET NULL,
    UNIQUE KEY unique_req_number (company_id, requisition_number),
    INDEX idx_status (company_id, status)
) ENGINE=InnoDB;

CREATE TABLE requisition_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requisition_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    description TEXT,
    quantity INT DEFAULT 1,
    unit VARCHAR(30) DEFAULT 'pcs',
    estimated_unit_price DECIMAL(15,2) DEFAULT 0.00,
    total_price DECIMAL(15,2) GENERATED ALWAYS AS (quantity * estimated_unit_price) STORED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requisition_id) REFERENCES requisitions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE requisition_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requisition_id INT NOT NULL,
    approval_stage ENUM('manager','auditor','finance','ceo') NOT NULL,
    approved_by INT,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    comments TEXT,
    acted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requisition_id) REFERENCES requisitions(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    requisition_id INT,
    po_number VARCHAR(50) NOT NULL,
    supplier_name VARCHAR(255) NOT NULL,
    supplier_contact VARCHAR(100),
    total_amount DECIMAL(15,2) DEFAULT 0.00,
    status ENUM('draft','sent','acknowledged','delivered','cancelled') DEFAULT 'draft',
    expected_delivery DATE,
    actual_delivery DATE,
    created_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (requisition_id) REFERENCES requisitions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_po_number (company_id, po_number)
) ENGINE=InnoDB;

CREATE TABLE supplier_quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requisition_id INT NOT NULL,
    supplier_name VARCHAR(255) NOT NULL,
    quoted_amount DECIMAL(15,2) NOT NULL,
    delivery_timeline VARCHAR(100),
    is_selected TINYINT(1) DEFAULT 0,
    attachment_url VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requisition_id) REFERENCES requisitions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- DEFAULT DATA SEEDER
-- =====================================================

-- Default expense categories (inserted per-company during signup)
-- Cost of Sales, Utilities, Salaries, Logistics, Maintenance, Miscellaneous
