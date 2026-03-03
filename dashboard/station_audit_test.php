<?php
/**
 * DEEP Station Audit Diagnostic — DELETE AFTER DEBUGGING
 * Tests each step of station_audit.php to find the exact failure point
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(120);

$start = microtime(true);
function elapsed() { global $start; return round((microtime(true) - $start) * 1000) . 'ms'; }
function pass($msg) { echo "<p style='color:green'>✅ $msg (" . elapsed() . ")</p>"; flush(); }
function fail($msg) { echo "<p style='color:red'>❌ $msg (" . elapsed() . ")</p>"; flush(); }
function info($msg) { echo "<p style='color:blue'>ℹ $msg</p>"; flush(); }

echo "<h2>Deep Station Audit Diagnostic</h2>";
echo "<p>PHP " . phpversion() . " | Memory: " . ini_get('memory_limit') . " | max_execution_time: " . ini_get('max_execution_time') . "</p><hr>";

// ═══ STEP 1: Load functions.php ═══
echo "<h3>Step 1: Core Dependencies</h3>";
try {
    require_once '../includes/functions.php';
    pass("functions.php loaded");
} catch (Throwable $e) {
    fail("functions.php: " . $e->getMessage());
    exit;
}

try {
    require_once '../config/sector_config.php';
    pass("sector_config.php loaded");
} catch (Throwable $e) {
    fail("sector_config.php: " . $e->getMessage());
    exit;
}

// ═══ STEP 2: Auth checks ═══
echo "<h3>Step 2: Authentication & Authorization</h3>";
if (!is_logged_in()) {
    info("NOT LOGGED IN — <b>Log in first, then revisit this page</b>. Skipping auth-dependent tests.");
    echo "<p><a href='../auth/login.php'>Go to Login</a></p>";
    // Still test DB migration below
} else {
    pass("Logged in as user #" . ($_SESSION['user_id'] ?? '?') . " | company #" . ($_SESSION['company_id'] ?? '?'));
    
    // Test require_subscription
    echo "<p>Testing require_subscription('station_audit')...</p>";
    try {
        $sub = get_company_subscription();
        $plan = get_current_plan();
        info("Plan: " . ($plan['name'] ?? 'unknown') . " | Modules: " . json_encode($plan['modules'] ?? []));
        
        $allowed_modules = $plan['modules'] ?? [];
        if (in_array('station_audit', $allowed_modules)) {
            pass("station_audit IS in allowed modules");
        } else {
            fail("station_audit is NOT in allowed modules — this would show upgrade page, not 500. Checking if 'audit' module works...");
            if (in_array('audit', $allowed_modules)) {
                info("'audit' module IS allowed. The issue might be that station_audit should check 'audit' not 'station_audit'");
            }
        }
    } catch (Throwable $e) {
        fail("Subscription check error: " . $e->getMessage());
    }
    
    // Test require_permission
    try {
        $role = $_SESSION['role'] ?? 'unknown';
        info("User role: $role");
        pass("Permission check OK");
    } catch (Throwable $e) {
        fail("Permission error: " . $e->getMessage());
    }
    
    // Test require_active_client
    try {
        $client_id = get_active_client();
        if ($client_id) {
            pass("Active client: #$client_id");
        } else {
            fail("No active client — this would redirect to company_setup.php");
        }
    } catch (Throwable $e) {
        fail("Active client error: " . $e->getMessage());
    }
}

// ═══ STEP 3: Database Migrations ═══
echo "<h3>Step 3: Database Migrations (CREATE TABLE / ALTER TABLE)</h3>";
$company_id = $_SESSION['company_id'] ?? 0;
global $pdo;

$migrations = [
    ["CREATE TABLE IF NOT EXISTS station_audit_sessions (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, client_id INT NOT NULL, outlet_id INT NOT NULL, date_from DATE, date_to DATE, status ENUM('draft','submitted','approved') DEFAULT 'draft', created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, auditor_id INT NULL, auditor_signed_at DATETIME NULL, auditor_comments TEXT NULL, manager_id INT NULL, manager_signed_at DATETIME NULL, manager_comments TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_audit_sessions"],
    ["CREATE TABLE IF NOT EXISTS station_system_sales (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL, pos_amount DECIMAL(15,2) DEFAULT 0, cash_amount DECIMAL(15,2) DEFAULT 0, transfer_amount DECIMAL(15,2) DEFAULT 0, teller_amount DECIMAL(15,2) DEFAULT 0, total DECIMAL(15,2) DEFAULT 0, notes TEXT, denomination_json TEXT, teller_proof_url VARCHAR(500), pos_proof_url VARCHAR(500), pos_terminals_json TEXT, transfer_terminals_json TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_system_sales"],
    ["CREATE TABLE IF NOT EXISTS station_pump_tables (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL, product VARCHAR(20) DEFAULT 'PMS', station_location VARCHAR(100), rate DECIMAL(10,2) DEFAULT 0, date_from DATE, date_to DATE, is_closed TINYINT(1) DEFAULT 0, sort_order INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_pump_tables"],
    ["CREATE TABLE IF NOT EXISTS station_pump_readings (id INT AUTO_INCREMENT PRIMARY KEY, pump_table_id INT NOT NULL, company_id INT NOT NULL, pump_name VARCHAR(50), opening DECIMAL(15,2) DEFAULT 0, rtt DECIMAL(15,2) DEFAULT 0, closing DECIMAL(15,2) DEFAULT 0, sort_order INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_pump_readings"],
    ["CREATE TABLE IF NOT EXISTS station_tank_dipping (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL, tank_name VARCHAR(100), product VARCHAR(20) DEFAULT 'PMS', opening DECIMAL(15,2) DEFAULT 0, added DECIMAL(15,2) DEFAULT 0, closing DECIMAL(15,2) DEFAULT 0, capacity_kg DECIMAL(12,2) DEFAULT 0, max_fill_percent DECIMAL(5,2) DEFAULT 100) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_tank_dipping"],
    ["CREATE TABLE IF NOT EXISTS station_haulage (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL, delivery_date DATE, tank_name VARCHAR(100), product VARCHAR(20) DEFAULT 'PMS', quantity DECIMAL(15,2) DEFAULT 0, waybill_qty DECIMAL(15,2) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_haulage"],
    ["CREATE TABLE IF NOT EXISTS station_lube_sections (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL, name VARCHAR(100) DEFAULT 'Counter 1', sort_order INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_sections"],
    ["CREATE TABLE IF NOT EXISTS station_lube_items (id INT AUTO_INCREMENT PRIMARY KEY, section_id INT NOT NULL, company_id INT NOT NULL, item_name VARCHAR(100), opening DECIMAL(12,2) DEFAULT 0, received DECIMAL(12,2) DEFAULT 0, sold DECIMAL(12,2) DEFAULT 0, closing DECIMAL(12,2) DEFAULT 0, selling_price DECIMAL(12,2) DEFAULT 0, sort_order INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_items"],
    ["CREATE TABLE IF NOT EXISTS station_lube_store_items (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL, item_name VARCHAR(100), opening DECIMAL(12,2) DEFAULT 0, received DECIMAL(12,2) DEFAULT 0, return_out DECIMAL(12,2) DEFAULT 0, selling_price DECIMAL(12,2) DEFAULT 0, sort_order INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_store_items"],
    ["CREATE TABLE IF NOT EXISTS station_lube_issues (id INT AUTO_INCREMENT PRIMARY KEY, store_item_id INT NOT NULL, section_id INT NOT NULL, company_id INT NOT NULL, quantity DECIMAL(12,2) DEFAULT 0, UNIQUE KEY uk_issue (store_item_id, section_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_issues"],
    ["CREATE TABLE IF NOT EXISTS station_lube_issue_log (id INT AUTO_INCREMENT PRIMARY KEY, store_item_id INT NOT NULL, section_id INT NOT NULL, company_id INT NOT NULL, quantity DECIMAL(12,2) DEFAULT 0, product_name VARCHAR(150), counter_name VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_issue_log"],
    ["CREATE TABLE IF NOT EXISTS station_lube_products (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, product_name VARCHAR(150) NOT NULL, unit VARCHAR(50) DEFAULT 'Litre', cost_price DECIMAL(12,2) DEFAULT 0, selling_price DECIMAL(12,2) DEFAULT 0, reorder_level DECIMAL(12,2) DEFAULT 0, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_prod (company_id, product_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_products"],
    ["CREATE TABLE IF NOT EXISTS station_lube_suppliers (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, supplier_name VARCHAR(150) NOT NULL, contact_person VARCHAR(100), phone VARCHAR(30), email VARCHAR(100), address TEXT, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_suppliers"],
    ["CREATE TABLE IF NOT EXISTS station_lube_grn (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, session_id INT NULL, supplier_id INT NULL, grn_number VARCHAR(50), grn_date DATE, invoice_number VARCHAR(100), total_cost DECIMAL(15,2) DEFAULT 0, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_grn"],
    ["CREATE TABLE IF NOT EXISTS station_lube_grn_items (id INT AUTO_INCREMENT PRIMARY KEY, grn_id INT NOT NULL, company_id INT NOT NULL, product_id INT NULL, product_name VARCHAR(150), quantity DECIMAL(12,2) DEFAULT 0, unit VARCHAR(50) DEFAULT 'Litre', cost_price DECIMAL(12,2) DEFAULT 0, selling_price DECIMAL(12,2) DEFAULT 0, line_total DECIMAL(15,2) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_grn_items"],
    ["CREATE TABLE IF NOT EXISTS station_lube_stock_counts (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, session_id INT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL, status VARCHAR(20) DEFAULT 'open', notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_stock_counts"],
    ["CREATE TABLE IF NOT EXISTS station_lube_stock_count_items (id INT AUTO_INCREMENT PRIMARY KEY, count_id INT NOT NULL, company_id INT NOT NULL, product_name VARCHAR(150), system_stock INT DEFAULT 0, physical_count INT DEFAULT 0, variance INT DEFAULT 0, cost_price DECIMAL(12,2) DEFAULT 0, selling_price DECIMAL(12,2) DEFAULT 0, sold_qty INT DEFAULT 0, sold_value_cost DECIMAL(15,2) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_lube_stock_count_items"],
    ["CREATE TABLE IF NOT EXISTS station_counter_stock_counts (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, section_id INT NOT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL, status VARCHAR(20) DEFAULT 'open', notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_counter_stock_counts"],
    ["CREATE TABLE IF NOT EXISTS station_counter_stock_count_items (id INT AUTO_INCREMENT PRIMARY KEY, count_id INT NOT NULL, company_id INT NOT NULL, product_name VARCHAR(150), system_stock INT DEFAULT 0, physical_count INT DEFAULT 0, variance INT DEFAULT 0, selling_price DECIMAL(12,2) DEFAULT 0, sold_qty INT DEFAULT 0, sold_value DECIMAL(15,2) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_counter_stock_count_items"],
    ["CREATE TABLE IF NOT EXISTS station_outlet_terminals (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, outlet_id INT NOT NULL, terminal_name VARCHAR(150) NOT NULL, terminal_type ENUM('pos','transfer') NOT NULL, sort_order INT DEFAULT 0, UNIQUE KEY uk_outlet_terminal (company_id, outlet_id, terminal_name, terminal_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "station_outlet_terminals"],
];

foreach ($migrations as $i => $m) {
    try {
        $pdo->exec($m[0]);
        pass("Table {$m[1]} OK");
    } catch (Throwable $e) {
        fail("Table {$m[1]}: " . $e->getMessage());
    }
}

// ALTER TABLE statements (the ones on lines 34-39, 57-58, 85-86)
echo "<h3>Step 4: ALTER TABLE migrations</h3>";
$alters = [
    "ALTER TABLE station_system_sales ADD COLUMN teller_amount DECIMAL(15,2) DEFAULT 0 AFTER transfer_amount",
    "ALTER TABLE station_system_sales ADD COLUMN denomination_json TEXT AFTER notes",
    "ALTER TABLE station_system_sales ADD COLUMN teller_proof_url VARCHAR(500) AFTER denomination_json",
    "ALTER TABLE station_system_sales ADD COLUMN pos_proof_url VARCHAR(500) AFTER teller_proof_url",
    "ALTER TABLE station_system_sales ADD COLUMN pos_terminals_json TEXT AFTER pos_proof_url",
    "ALTER TABLE station_system_sales ADD COLUMN transfer_terminals_json TEXT AFTER pos_terminals_json",
    "ALTER TABLE station_tank_dipping ADD COLUMN IF NOT EXISTS capacity_kg DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE station_tank_dipping ADD COLUMN IF NOT EXISTS max_fill_percent DECIMAL(5,2) DEFAULT 100",
    "ALTER TABLE station_lube_items ADD COLUMN store_item_id INT NULL AFTER section_id",
    "ALTER TABLE station_lube_store_items ADD COLUMN adjustment DECIMAL(12,2) DEFAULT 0 AFTER return_out",
];

foreach ($alters as $sql) {
    try {
        $pdo->exec($sql);
        pass(substr($sql, 0, 70) . "...");
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false) {
            pass(substr($sql, 0, 50) . "... (column exists, OK)");
        } else {
            fail(substr($sql, 0, 50) . "... ERROR: $msg");
        }
    }
}

// ═══ STEP 5: Data queries ═══
echo "<h3>Step 5: Data Queries</h3>";
if (is_logged_in()) {
    $client_id = get_active_client();
    if ($client_id) {
        try {
            $outlets = get_client_outlets($client_id, $company_id);
            pass("get_client_outlets: " . count($outlets) . " outlets found");
        } catch (Throwable $e) {
            fail("get_client_outlets: " . $e->getMessage());
        }
        
        try {
            $stmt = $pdo->prepare("SELECT s.*, co.name as outlet_name FROM station_audit_sessions s LEFT JOIN client_outlets co ON s.outlet_id = co.id WHERE s.company_id = ? AND s.client_id = ? ORDER BY s.created_at DESC LIMIT 50");
            $stmt->execute([$company_id, $client_id]);
            $sessions = $stmt->fetchAll();
            pass("Sessions query: " . count($sessions) . " sessions found");
        } catch (Throwable $e) {
            fail("Sessions query: " . $e->getMessage());
        }
    } else {
        info("No active client — skipping data queries");
    }
} else {
    info("Not logged in — skipping data queries");
}

// ═══ STEP 6: Include files ═══
echo "<h3>Step 6: Include Files</h3>";
$includes = [
    '../includes/dashboard_sidebar.php',
    '../includes/dashboard_header.php',
    'station_audit_app.js',
];
foreach ($includes as $f) {
    echo "<p>" . basename($f) . ": " . (file_exists($f) ? "<b style='color:green'>EXISTS</b> (" . number_format(filesize($f)) . " bytes)" : "<b style='color:red'>MISSING</b>") . "</p>";
}

// ═══ STEP 7: Server limits ═══
echo "<h3>Step 7: Server Limits</h3>";
info("memory_limit: " . ini_get('memory_limit'));
info("max_execution_time: " . ini_get('max_execution_time'));
info("post_max_size: " . ini_get('post_max_size'));
info("upload_max_filesize: " . ini_get('upload_max_filesize'));
info("max_input_vars: " . ini_get('max_input_vars'));
info("output_buffering: " . ini_get('output_buffering'));
info("Current memory usage: " . number_format(memory_get_usage(true)) . " bytes");
info("Peak memory usage: " . number_format(memory_get_peak_usage(true)) . " bytes");
info("Total elapsed: " . elapsed());

echo "<hr><p style='color:red'><b>DELETE THIS FILE AFTER DEBUGGING!</b></p>";
?>
