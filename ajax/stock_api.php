<?php
/**
 * MIAUDITOPS — Stock API (AJAX Handler)
 * Handles: add_product, update_stock, receive_delivery, log_wastage, save_count
 */
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
require_non_viewer(); // Viewer role cannot modify stock data
$user_id    = $_SESSION['user_id'];
$action     = $_POST['action'] ?? '';

// === Auto-migration: ensure required columns & tables exist ===
try {
    // Add opening_stock to products if missing
    $cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'opening_stock'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN opening_stock INT DEFAULT 0 AFTER selling_price");
    }
    // Create department_stock table if missing
    $pdo->exec("CREATE TABLE IF NOT EXISTS department_stock (
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
        INDEX idx_date (company_id, client_id, stock_date)
    ) ENGINE=InnoDB");
    // Migrate old unique key if exists (add stock_date to unique constraint)
    try {
        $pdo->exec("ALTER TABLE department_stock DROP INDEX uk_dept_product");
        $pdo->exec("ALTER TABLE department_stock ADD UNIQUE KEY uk_dept_product_date (department_id, product_id, stock_date)");
    } catch (Exception $ignore) { /* already migrated */ }
    // Ensure stock_date NOT NULL with default
    try {
        $pdo->exec("ALTER TABLE department_stock MODIFY stock_date DATE NOT NULL");
        $pdo->exec("UPDATE department_stock SET stock_date = CURDATE() WHERE stock_date IS NULL OR stock_date = '0000-00-00'");
    } catch (Exception $ignore) {}
} catch (Exception $e) { error_log('Stock migration: ' . $e->getMessage()); }

// Ensure product_categories table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_cat_name (company_id, client_id, name)
    ) ENGINE=InnoDB");
    // Fix any previously double-encoded category names in products
    $pdo->exec("UPDATE products SET category = REPLACE(REPLACE(category, '&amp;amp;', '&'), '&amp;', '&') WHERE category LIKE '%&amp;%'");
} catch (Exception $e) { error_log('Categories migration: ' . $e->getMessage()); }

// Ensure suppliers table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        contact_person VARCHAR(150) DEFAULT '',
        phone VARCHAR(50) DEFAULT '',
        email VARCHAR(150) DEFAULT '',
        address TEXT,
        category VARCHAR(100) DEFAULT '',
        notes TEXT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_supplier_name (company_id, client_id, name)
    ) ENGINE=InnoDB");
} catch (Exception $e) { error_log('Suppliers migration: ' . $e->getMessage()); }

// Ensure supplier_deliveries table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        product_id INT NOT NULL,
        supplier_name VARCHAR(150) DEFAULT '',
        quantity INT DEFAULT 0,
        unit_cost DECIMAL(15,2) DEFAULT 0.00,
        total_cost DECIMAL(15,2) DEFAULT 0.00,
        invoice_number VARCHAR(100) DEFAULT '',
        delivery_date DATE NOT NULL,
        received_by INT DEFAULT NULL,
        deleted_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (company_id, client_id),
        INDEX idx_product (company_id, client_id, product_id),
        INDEX idx_date (company_id, client_id, delivery_date)
    ) ENGINE=InnoDB");
    // Add missing columns if table already exists
    try { $pdo->exec("ALTER TABLE supplier_deliveries ADD COLUMN client_id INT NOT NULL DEFAULT 0 AFTER company_id"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE supplier_deliveries ADD COLUMN quantity INT DEFAULT 0 AFTER supplier_name"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE supplier_deliveries ADD COLUMN unit_cost DECIMAL(15,2) DEFAULT 0.00 AFTER quantity"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE supplier_deliveries ADD COLUMN total_cost DECIMAL(15,2) DEFAULT 0.00 AFTER unit_cost"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE supplier_deliveries ADD COLUMN invoice_number VARCHAR(100) DEFAULT '' AFTER total_cost"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE supplier_deliveries ADD COLUMN received_by INT DEFAULT NULL AFTER delivery_date"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE supplier_deliveries ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER received_by"); } catch (Exception $ignore) {}
    // Backfill client_id for existing rows
    try { $pdo->exec("UPDATE supplier_deliveries SET client_id = (SELECT MIN(client_id) FROM products WHERE products.company_id = supplier_deliveries.company_id LIMIT 1) WHERE client_id = 0"); } catch (Exception $ignore) {}
} catch (Exception $e) { error_log('Deliveries migration: ' . $e->getMessage()); }

// Ensure stock_movements table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        product_id INT NOT NULL,
        type VARCHAR(30) NOT NULL DEFAULT 'in',
        quantity INT DEFAULT 0,
        reference_type VARCHAR(50) DEFAULT '',
        notes TEXT,
        performed_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (company_id, client_id),
        INDEX idx_product (company_id, client_id, product_id),
        INDEX idx_type (company_id, client_id, type)
    ) ENGINE=InnoDB");
    try { $pdo->exec("ALTER TABLE stock_movements ADD COLUMN client_id INT NOT NULL DEFAULT 0 AFTER company_id"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE stock_movements ADD COLUMN type VARCHAR(30) NOT NULL DEFAULT 'in' AFTER product_id"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE stock_movements ADD COLUMN quantity INT DEFAULT 0 AFTER type"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE stock_movements ADD COLUMN reference_type VARCHAR(50) DEFAULT '' AFTER quantity"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE stock_movements ADD COLUMN notes TEXT AFTER reference_type"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE stock_movements ADD COLUMN performed_by INT DEFAULT NULL AFTER notes"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE stock_movements MODIFY type VARCHAR(30) NOT NULL DEFAULT 'in'"); } catch (Exception $ignore) {}
} catch (Exception $e) { error_log('Movements migration: ' . $e->getMessage()); }

// Ensure wastage_log table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS wastage_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT DEFAULT 0,
        reason TEXT,
        wastage_date DATE NOT NULL,
        reported_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (company_id, client_id),
        INDEX idx_product (company_id, client_id, product_id),
        INDEX idx_date (company_id, client_id, wastage_date)
    ) ENGINE=InnoDB");
    try { $pdo->exec("ALTER TABLE wastage_log ADD COLUMN client_id INT NOT NULL DEFAULT 0 AFTER company_id"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE wastage_log ADD COLUMN quantity INT DEFAULT 0 AFTER product_id"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE wastage_log ADD COLUMN reason TEXT AFTER quantity"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE wastage_log ADD COLUMN wastage_date DATE NOT NULL AFTER reason"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE wastage_log ADD COLUMN reported_by INT DEFAULT NULL AFTER wastage_date"); } catch (Exception $ignore) {}
} catch (Exception $e) { error_log('Wastage migration: ' . $e->getMessage()); }

// Ensure physical_counts table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS physical_counts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        product_id INT NOT NULL,
        count_date DATE NOT NULL,
        system_count INT DEFAULT 0,
        physical_count INT DEFAULT 0,
        notes TEXT,
        counted_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (company_id, client_id),
        INDEX idx_product (company_id, client_id, product_id),
        INDEX idx_date (company_id, client_id, count_date)
    ) ENGINE=InnoDB");
    try { $pdo->exec("ALTER TABLE physical_counts ADD COLUMN client_id INT NOT NULL DEFAULT 0 AFTER company_id"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE physical_counts ADD COLUMN count_date DATE NOT NULL AFTER product_id"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE physical_counts ADD COLUMN system_count INT DEFAULT 0 AFTER count_date"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE physical_counts ADD COLUMN physical_count INT DEFAULT 0 AFTER system_count"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE physical_counts ADD COLUMN notes TEXT AFTER physical_count"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE physical_counts ADD COLUMN counted_by INT DEFAULT NULL AFTER notes"); } catch (Exception $ignore) {}
} catch (Exception $e) { error_log('Counts migration: ' . $e->getMessage()); }

try {
    switch ($action) {
        case 'add_product':
            $name     = clean_input($_POST['name'] ?? '');
            $sku      = clean_input($_POST['sku'] ?? '');
            $category = trim($_POST['category'] ?? '');  // Don't htmlspecialchars — stored raw, escaped on output
            $unit     = clean_input($_POST['unit'] ?? 'pcs');
            $cost     = floatval($_POST['unit_cost'] ?? 0);
            $price    = floatval($_POST['selling_price'] ?? 0);
            $stock    = intval($_POST['opening_stock'] ?? 0);
            $reorder  = intval($_POST['reorder_level'] ?? 10);
            
            $stmt = $pdo->prepare("INSERT INTO products (company_id, client_id, name, sku, category, unit, unit_cost, selling_price, current_stock, reorder_level) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $name, $sku, $category, $unit, $cost, $price, $stock, $reorder]);
            $pid = $pdo->lastInsertId();
            
            log_audit($company_id, $user_id, 'product_added', 'stock', $pid, "Product '$name' added with stock $stock");
            echo json_encode(['success' => true, 'id' => $pid]);
            break;

        case 'delete_product':
            $pid = intval($_POST['product_id'] ?? 0);
            if (!$pid) { echo json_encode(['success' => false, 'message' => 'Product ID required']); break; }
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$pid, $company_id, $client_id]);
            log_audit($company_id, $user_id, 'product_deleted', 'stock', $pid, "Product #$pid deleted");
            echo json_encode(['success' => true]);
            break;
            
        case 'receive_delivery':
            $product_id = intval($_POST['product_id'] ?? 0);
            $supplier   = clean_input($_POST['supplier'] ?? '');
            $qty        = intval($_POST['quantity'] ?? 0);
            $cost       = floatval($_POST['unit_cost'] ?? 0);
            $invoice    = clean_input($_POST['invoice_number'] ?? '');
            $date       = $_POST['delivery_date'] ?? date('Y-m-d');
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO supplier_deliveries (company_id, client_id, product_id, supplier_name, quantity, unit_cost, total_cost, invoice_number, delivery_date, received_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $product_id, $supplier, $qty, $cost, $qty * $cost, $invoice, $date, $user_id]);
            
            // Update stock
            $stmt = $pdo->prepare("UPDATE products SET current_stock = current_stock + ?, unit_cost = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$qty, $cost, $product_id, $company_id]);
            
            // Log movement
            $stmt = $pdo->prepare("INSERT INTO stock_movements (company_id, client_id, product_id, type, quantity, reference_type, notes, performed_by) VALUES (?,?,?,'in',?,'delivery',?,?)");
            $stmt->execute([$company_id, $client_id, $product_id, $qty, "Delivery from $supplier", $user_id]);
            
            $pdo->commit();
            log_audit($company_id, $user_id, 'delivery_received', 'stock', $product_id, "$qty units from $supplier");
            echo json_encode(['success' => true]);
            break;

        case 'update_delivery':
            $delivery_id = intval($_POST['delivery_id'] ?? 0);
            $new_qty     = intval($_POST['quantity'] ?? 0);
            $new_cost    = floatval($_POST['unit_cost'] ?? 0);
            $new_supplier = clean_input($_POST['supplier_name'] ?? '');
            $new_invoice  = clean_input($_POST['invoice_number'] ?? '');
            
            if ($new_qty <= 0) {
                echo json_encode(['success' => false, 'message' => 'Quantity must be greater than 0']);
                break;
            }
            
            // Get old delivery to calculate difference
            $stmt = $pdo->prepare("SELECT * FROM supplier_deliveries WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$delivery_id, $company_id, $client_id]);
            $old = $stmt->fetch();
            
            if (!$old) {
                echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                break;
            }
            
            $qty_diff = $new_qty - intval($old['quantity']);
            $product_id = $old['product_id'];
            
            $pdo->beginTransaction();
            
            // Update the delivery record
            $stmt = $pdo->prepare("UPDATE supplier_deliveries SET quantity = ?, unit_cost = ?, total_cost = ?, supplier_name = ?, invoice_number = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$new_qty, $new_cost, $new_qty * $new_cost, $new_supplier, $new_invoice, $delivery_id, $company_id]);
            
            // Adjust product stock by the difference
            if ($qty_diff != 0) {
                $stmt = $pdo->prepare("UPDATE products SET current_stock = current_stock + ?, unit_cost = ? WHERE id = ? AND company_id = ?");
                $stmt->execute([$qty_diff, $new_cost, $product_id, $company_id]);
            } else {
                // Even if qty didn't change, update unit_cost
                $stmt = $pdo->prepare("UPDATE products SET unit_cost = ? WHERE id = ? AND company_id = ?");
                $stmt->execute([$new_cost, $product_id, $company_id]);
            }
            
            $pdo->commit();
            log_audit($company_id, $user_id, 'delivery_updated', 'stock', $product_id, "Delivery #$delivery_id updated: qty {$old['quantity']}->$new_qty, cost {$old['unit_cost']}->$new_cost");
            echo json_encode(['success' => true]);
            break;

        case 'delete_delivery':
            $delivery_id = intval($_POST['delivery_id'] ?? 0);
            if (!$delivery_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid delivery ID']);
                break;
            }

            // Get delivery details before deleting
            $stmt = $pdo->prepare("SELECT * FROM supplier_deliveries WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$delivery_id, $company_id, $client_id]);
            $del = $stmt->fetch();

            if (!$del) {
                echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                break;
            }

            $product_id = $del['product_id'];
            $del_qty    = intval($del['quantity']);

            $pdo->beginTransaction();

            // Delete the delivery record
            $stmt = $pdo->prepare("DELETE FROM supplier_deliveries WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$delivery_id, $company_id, $client_id]);

            // Reduce product stock by the deleted quantity
            $stmt = $pdo->prepare("UPDATE products SET current_stock = GREATEST(current_stock - ?, 0) WHERE id = ? AND company_id = ?");
            $stmt->execute([$del_qty, $product_id, $company_id]);

            $pdo->commit();
            log_audit($company_id, $user_id, 'delivery_deleted', 'stock', $product_id, "Delivery #$delivery_id deleted: qty $del_qty removed from stock");
            echo json_encode(['success' => true]);
            break;

        case 'issue_to_department':
            $department_id = intval($_POST['department_id'] ?? 0);
            $product_id    = intval($_POST['product_id'] ?? 0);
            $qty           = intval($_POST['quantity'] ?? 0);
            $issue_date    = $_POST['issue_date'] ?? date('Y-m-d');
            
            if ($qty <= 0) {
                echo json_encode(['success' => false, 'message' => 'Quantity must be greater than 0']);
                break;
            }
            if (!$department_id || !$product_id) {
                echo json_encode(['success' => false, 'message' => 'Department and Product are required']);
                break;
            }

            // Validate destination department exists
            $stmt = $pdo->prepare("SELECT name FROM stock_departments WHERE id = ? AND company_id = ? AND client_id = ? AND deleted_at IS NULL");
            $stmt->execute([$department_id, $company_id, $client_id]);
            $dept_row = $stmt->fetch();
            if (!$dept_row) {
                echo json_encode(['success' => false, 'message' => 'The selected department does not exist or has been deleted. Please go to Stock Audit and create a department first.']);
                break;
            }
            $dept_name = $dept_row['name'];

            // Get product name for messages
            $stmt = $pdo->prepare("SELECT name FROM products WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$product_id, $company_id, $client_id]);
            $prod_name = $stmt->fetchColumn() ?: 'Product#' . $product_id;

            // Validate product is registered in destination department
            $stmt = $pdo->prepare("SELECT id FROM department_stock WHERE department_id = ? AND product_id = ? AND company_id = ? AND client_id = ? LIMIT 1");
            $stmt->execute([$department_id, $product_id, $company_id, $client_id]);
            if (!$stmt->fetch()) {
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot issue stock: \"$prod_name\" has not been added to \"$dept_name\" yet.\n\nPlease go to $dept_name department and add this product first using the \"+ Add Product\" button, then retry.",
                    'code' => 'DEST_PRODUCT_MISSING'
                ]);
                break;
            }
            
            $pdo->beginTransaction();
            
            // Check stock availability
            $stmt = $pdo->prepare("SELECT current_stock FROM products WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$product_id, $company_id, $client_id]);
            $current = $stmt->fetchColumn();
            if ($current === false || $current < $qty) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Insufficient stock (available: ' . ($current ?: 0) . ')']);
                break;
            }
            
            // Insert or update department_stock record (upsert)
            $stmt = $pdo->prepare("INSERT INTO department_stock (company_id, client_id, department_id, product_id, added, stock_date) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE added = added + VALUES(added)");
            $stmt->execute([$company_id, $client_id, $department_id, $product_id, $qty, $issue_date]);
            
            $pdo->commit();
            
            log_audit($company_id, $user_id, 'issue_to_department', 'stock', $product_id, "$qty units of $prod_name issued to $dept_name");
            echo json_encode(['success' => true]);
            break;

        case 'add_dept_product':
            $dept_id = intval($_POST['department_id'] ?? 0);
            $prod_id = intval($_POST['product_id'] ?? 0);
            $opening = intval($_POST['opening_stock'] ?? 0);
            $price   = floatval($_POST['selling_price'] ?? 0);
            $date    = $_POST['stock_date'] ?? date('Y-m-d');
            
            if (!$dept_id || !$prod_id) { echo json_encode(['success'=>false,'message'=>'Invalid Dept/Product']); break; }
            
            // Ensure source column exists
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM department_stock LIKE 'source'")->fetchAll();
                if (empty($cols)) {
                    $pdo->exec("ALTER TABLE department_stock ADD COLUMN source VARCHAR(20) DEFAULT 'main_store'");
                }
            } catch (Exception $ignore) {}
            
            $source = clean_input($_POST['source'] ?? 'main_store');
            if (!in_array($source, ['main_store', 'kitchen'])) $source = 'main_store';
            
            // UPSERT
            $stmt = $pdo->prepare("INSERT INTO department_stock (company_id, client_id, department_id, product_id, stock_date, opening_stock, selling_price, source) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE opening_stock = VALUES(opening_stock), selling_price = VALUES(selling_price), source = VALUES(source)");
            $stmt->execute([$company_id, $client_id, $dept_id, $prod_id, $date, $opening, $price, $source]);
            
            echo json_encode(['success' => true]);
            break;

        case 'remove_dept_product':
            $dept_id = intval($_POST['department_id'] ?? 0);
            $prod_id = intval($_POST['product_id'] ?? 0);
            
            if (!$dept_id || !$prod_id) { echo json_encode(['success'=>false,'message'=>'Invalid Dept/Product']); break; }
            
            $stmt = $pdo->prepare("DELETE FROM department_stock WHERE department_id = ? AND product_id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$dept_id, $prod_id, $company_id, $client_id]);
            
            log_audit($company_id, $user_id, 'dept_product_removed', 'stock', $prod_id, "Product removed from dept $dept_id");
            echo json_encode(['success' => true]);
            break;

        case 'add_kitchen_product':
            $kitchen_id = intval($_POST['kitchen_id'] ?? 0);
            $name       = clean_input($_POST['name'] ?? '');
            $sku        = clean_input($_POST['sku'] ?? '');
            $category   = clean_input($_POST['category'] ?? '');
            $unit       = clean_input($_POST['unit'] ?? 'plates');
            $unit_cost  = floatval($_POST['unit_cost'] ?? 0);
            $sell_price = floatval($_POST['selling_price'] ?? 0);

            if (!$kitchen_id || !$name) {
                echo json_encode(['success' => false, 'message' => 'Product name and kitchen are required']);
                break;
            }

            // Auto-generate SKU if empty
            if (!$sku) {
                $initials = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), explode(' ', $name))));
                $sku = 'K' . $initials . rand(100, 999);
            }

            // Ensure kitchen_id column exists
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'kitchen_id'")->fetchAll();
                if (empty($cols)) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN kitchen_id INT DEFAULT NULL AFTER reorder_level");
                }
            } catch (Exception $ignore) {}

            $stmt = $pdo->prepare("INSERT INTO products (company_id, client_id, name, sku, category, unit, unit_cost, selling_price, kitchen_id) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $name, $sku, $category, $unit, $unit_cost, $sell_price, $kitchen_id]);

            log_audit($company_id, $user_id, 'kitchen_product_created', 'stock', $pdo->lastInsertId(), "Kitchen product: $name");
            echo json_encode(['success' => true, 'message' => 'Kitchen product created']);
            break;

        case 'delete_kitchen_product':
            $prod_id = intval($_POST['product_id'] ?? 0);
            if (!$prod_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid product']);
                break;
            }
            // Soft delete — only kitchen products
            $stmt = $pdo->prepare("UPDATE products SET deleted_at = NOW() WHERE id = ? AND company_id = ? AND client_id = ? AND kitchen_id IS NOT NULL");
            $stmt->execute([$prod_id, $company_id, $client_id]);

            log_audit($company_id, $user_id, 'kitchen_product_deleted', 'stock', $prod_id, "Kitchen product deleted");
            echo json_encode(['success' => true]);
            break;

        case 'save_recipe_ingredient':
            $product_id = intval($_POST['product_id'] ?? 0);
            $ingredient_id = intval($_POST['ingredient_product_id'] ?? 0);
            $qty_per_plate = floatval($_POST['qty_per_plate'] ?? 1);
            $unit = clean_input($_POST['unit'] ?? 'portions');

            if (!$product_id || !$ingredient_id || $qty_per_plate <= 0) {
                echo json_encode(['success' => false, 'message' => 'Product, ingredient, and quantity are required']);
                break;
            }

            // Upsert ingredient
            $stmt = $pdo->prepare("INSERT INTO kitchen_recipes (company_id, client_id, product_id, ingredient_product_id, qty_per_plate, unit) 
                VALUES (?,?,?,?,?,?) 
                ON DUPLICATE KEY UPDATE qty_per_plate = VALUES(qty_per_plate), unit = VALUES(unit)");
            $stmt->execute([$company_id, $client_id, $product_id, $ingredient_id, $qty_per_plate, $unit]);

            // Recalculate total cost from all ingredients
            $total_cost = 0;
            $stmt = $pdo->prepare("SELECT kr.qty_per_plate, p.unit_cost as catalog_cost, p.updated_at as catalog_date,
                (SELECT sd.unit_cost FROM supplier_deliveries sd WHERE sd.product_id = kr.ingredient_product_id AND sd.company_id = ? AND sd.client_id = ? ORDER BY sd.delivery_date DESC, sd.id DESC LIMIT 1) as supplier_cost,
                (SELECT sd.delivery_date FROM supplier_deliveries sd WHERE sd.product_id = kr.ingredient_product_id AND sd.company_id = ? AND sd.client_id = ? ORDER BY sd.delivery_date DESC, sd.id DESC LIMIT 1) as supplier_date
                FROM kitchen_recipes kr JOIN products p ON p.id = kr.ingredient_product_id
                WHERE kr.product_id = ? AND kr.company_id = ? AND kr.client_id = ?");
            $stmt->execute([$company_id, $client_id, $company_id, $client_id, $product_id, $company_id, $client_id]);
            foreach ($stmt->fetchAll() as $row) {
                $sup_cost = floatval($row['supplier_cost'] ?? 0);
                $cat_cost = floatval($row['catalog_cost'] ?? 0);
                $sup_date = $row['supplier_date'] ?? '1970-01-01';
                $cat_date = $row['catalog_date'] ?? '1970-01-01';
                $latest = ($sup_date >= $cat_date && $sup_cost > 0) ? $sup_cost : $cat_cost;
                $total_cost += $row['qty_per_plate'] * $latest;
            }
            // Update product unit_cost
            $stmt = $pdo->prepare("UPDATE products SET unit_cost = ? WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([round($total_cost, 2), $product_id, $company_id, $client_id]);

            log_audit($company_id, $user_id, 'recipe_ingredient_saved', 'stock', $product_id, "Ingredient #$ingredient_id: qty=$qty_per_plate $unit");
            echo json_encode(['success' => true, 'total_cost' => round($total_cost, 2)]);
            break;

        case 'delete_recipe_ingredient':
            $product_id = intval($_POST['product_id'] ?? 0);
            $ingredient_id = intval($_POST['ingredient_product_id'] ?? 0);

            if (!$product_id || !$ingredient_id) {
                echo json_encode(['success' => false, 'message' => 'Product and ingredient required']);
                break;
            }

            $stmt = $pdo->prepare("DELETE FROM kitchen_recipes WHERE product_id = ? AND ingredient_product_id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$product_id, $ingredient_id, $company_id, $client_id]);

            // Recalculate total cost
            $total_cost = 0;
            $stmt = $pdo->prepare("SELECT kr.qty_per_plate, p.unit_cost as catalog_cost, p.updated_at as catalog_date,
                (SELECT sd.unit_cost FROM supplier_deliveries sd WHERE sd.product_id = kr.ingredient_product_id AND sd.company_id = ? AND sd.client_id = ? ORDER BY sd.delivery_date DESC, sd.id DESC LIMIT 1) as supplier_cost,
                (SELECT sd.delivery_date FROM supplier_deliveries sd WHERE sd.product_id = kr.ingredient_product_id AND sd.company_id = ? AND sd.client_id = ? ORDER BY sd.delivery_date DESC, sd.id DESC LIMIT 1) as supplier_date
                FROM kitchen_recipes kr JOIN products p ON p.id = kr.ingredient_product_id
                WHERE kr.product_id = ? AND kr.company_id = ? AND kr.client_id = ?");
            $stmt->execute([$company_id, $client_id, $company_id, $client_id, $product_id, $company_id, $client_id]);
            foreach ($stmt->fetchAll() as $row) {
                $sup_cost = floatval($row['supplier_cost'] ?? 0);
                $cat_cost = floatval($row['catalog_cost'] ?? 0);
                $sup_date = $row['supplier_date'] ?? '1970-01-01';
                $cat_date = $row['catalog_date'] ?? '1970-01-01';
                $latest = ($sup_date >= $cat_date && $sup_cost > 0) ? $sup_cost : $cat_cost;
                $total_cost += $row['qty_per_plate'] * $latest;
            }
            $stmt = $pdo->prepare("UPDATE products SET unit_cost = ? WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([round($total_cost, 2), $product_id, $company_id, $client_id]);

            log_audit($company_id, $user_id, 'recipe_ingredient_deleted', 'stock', $product_id, "Ingredient #$ingredient_id removed");
            echo json_encode(['success' => true, 'total_cost' => round($total_cost, 2)]);
            break;

        case 'issue_kitchen_product':
            $product_id = intval($_POST['product_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 0);
            $dest_dept_id = intval($_POST['destination_dept_id'] ?? 0);
            $stock_date = $_POST['stock_date'] ?? date('Y-m-d');

            if (!$product_id || $quantity <= 0 || !$dest_dept_id) {
                echo json_encode(['success' => false, 'message' => 'Product, quantity, and destination are required']);
                break;
            }

            // Verify product is a kitchen product
            $stmt = $pdo->prepare("SELECT id, name, kitchen_id, selling_price, unit_cost FROM products WHERE id = ? AND company_id = ? AND client_id = ? AND kitchen_id IS NOT NULL AND deleted_at IS NULL");
            $stmt->execute([$product_id, $company_id, $client_id]);
            $kitchen_product = $stmt->fetch();
            if (!$kitchen_product) {
                echo json_encode(['success' => false, 'message' => 'Invalid kitchen product']);
                break;
            }
            $kitchen_id = $kitchen_product['kitchen_id'];

            // Get recipe ingredients
            $stmt = $pdo->prepare("SELECT kr.ingredient_product_id, kr.qty_per_plate, p.name as ingredient_name 
                FROM kitchen_recipes kr JOIN products p ON p.id = kr.ingredient_product_id 
                WHERE kr.product_id = ? AND kr.company_id = ? AND kr.client_id = ?");
            $stmt->execute([$product_id, $company_id, $client_id]);
            $recipe = $stmt->fetchAll();

            // If recipe has ingredients, check availability and deduct
            if (!empty($recipe)) {
                // Check availability for each ingredient
                foreach ($recipe as $ing) {
                    $required = $quantity * $ing['qty_per_plate'];
                    $stmt = $pdo->prepare("SELECT *, (COALESCE(opening_stock,0) + COALESCE(added,0) + COALESCE(return_in,0) - COALESCE(transfer_out,0) - COALESCE(transfer_to_main,0) - COALESCE(qty_sold,0)) as closing 
                        FROM department_stock WHERE department_id = ? AND product_id = ? AND stock_date = ? AND company_id = ? AND client_id = ?");
                    $stmt->execute([$kitchen_id, $ing['ingredient_product_id'], $stock_date, $company_id, $client_id]);
                    $stock_row = $stmt->fetch();
                    $available = $stock_row ? floatval($stock_row['closing']) : 0;
                    if ($available < $required) {
                        echo json_encode(['success' => false, 'message' => 'Insufficient "' . $ing['ingredient_name'] . '" — need ' . $required . ' but only ' . $available . ' available']);
                        break 2; // Break out of both foreach and switch
                    }
                }

                // Deduct raw materials from kitchen inventory (add to qty_sold)
                foreach ($recipe as $ing) {
                    $deduction = $quantity * $ing['qty_per_plate'];
                    $stmt = $pdo->prepare("UPDATE department_stock SET qty_sold = COALESCE(qty_sold, 0) + ? 
                        WHERE department_id = ? AND product_id = ? AND stock_date = ? AND company_id = ? AND client_id = ?");
                    $stmt->execute([$deduction, $kitchen_id, $ing['ingredient_product_id'], $stock_date, $company_id, $client_id]);

                    // Log the deduction
                    $stmt_mv = $pdo->prepare("INSERT INTO stock_movements (company_id, client_id, product_id, type, quantity, reference_type, notes, performed_by) VALUES (?,?,?,'out',?,'kitchen_consumption',?,?)");
                    $stmt_mv->execute([$company_id, $client_id, $ing['ingredient_product_id'], $deduction, 
                        "Consumed for {$quantity}x {$kitchen_product['name']} issued to dept #$dest_dept_id", $user_id]);
                }
            }

            // Create/update restaurant dept_stock record for the finished product
            $sell_price = floatval($kitchen_product['selling_price']);
            $chk = $pdo->prepare("SELECT id, added FROM department_stock WHERE department_id = ? AND product_id = ? AND stock_date = ? AND company_id = ? AND client_id = ?");
            $chk->execute([$dest_dept_id, $product_id, $stock_date, $company_id, $client_id]);
            $existing = $chk->fetch();

            if ($existing) {
                // Add to existing added quantity
                $stmt = $pdo->prepare("UPDATE department_stock SET added = COALESCE(added, 0) + ?, selling_price = ? WHERE id = ?");
                $stmt->execute([$quantity, $sell_price, $existing['id']]);
            } else {
                // Create new dept_stock record  
                $stmt = $pdo->prepare("INSERT INTO department_stock (company_id, client_id, department_id, product_id, opening_stock, added, selling_price, stock_date) VALUES (?,?,?,?,0,?,?,?)");
                $stmt->execute([$company_id, $client_id, $dest_dept_id, $product_id, $quantity, $sell_price, $stock_date]);
            }

            // Log the issue
            $dest_name_q = $pdo->prepare("SELECT name FROM stock_departments WHERE id = ?");
            $dest_name_q->execute([$dest_dept_id]);
            $dest_name = $dest_name_q->fetchColumn() ?: "Dept #$dest_dept_id";

            $stmt_mv = $pdo->prepare("INSERT INTO stock_movements (company_id, client_id, product_id, type, quantity, reference_type, notes, performed_by) VALUES (?,?,?,'out',?,'kitchen_issue',?,?)");
            $stmt_mv->execute([$company_id, $client_id, $product_id, $quantity, "Issued {$quantity}x {$kitchen_product['name']} to $dest_name on $stock_date", $user_id]);

            log_audit($company_id, $user_id, 'kitchen_product_issued', 'stock', $product_id, "Issued {$quantity}x {$kitchen_product['name']} to $dest_name");
            echo json_encode(['success' => true, 'message' => "Issued $quantity {$kitchen_product['name']} to $dest_name"]);
            break;

        case 'delete_product':
            $prod_id = intval($_POST['product_id'] ?? 0);
            if (!$prod_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid product']);
                break;
            }
            // Soft delete
            $stmt = $pdo->prepare("UPDATE products SET deleted_at = NOW() WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$prod_id, $company_id, $client_id]);

            log_audit($company_id, $user_id, 'product_deleted', 'stock', $prod_id, "Product soft-deleted from Main Store");
            echo json_encode(['success' => true]);
            break;

        case 'update_catalog_product':
            $prod_id  = intval($_POST['product_id'] ?? 0);
            $name     = clean_input($_POST['name'] ?? '');
            $sku      = clean_input($_POST['sku'] ?? '');
            $category = clean_input($_POST['category'] ?? '');
            $unit     = clean_input($_POST['unit'] ?? 'pcs');
            $unit_cost    = floatval($_POST['unit_cost'] ?? 0);
            $sell_price   = floatval($_POST['selling_price'] ?? 0);
            $reorder      = intval($_POST['reorder_level'] ?? 10);

            if (!$prod_id || !$name) {
                echo json_encode(['success' => false, 'message' => 'Product ID and name are required']);
                break;
            }

            $stmt = $pdo->prepare("UPDATE products SET name = ?, sku = ?, category = ?, unit = ?, unit_cost = ?, selling_price = ?, reorder_level = ? WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$name, $sku, $category, $unit, $unit_cost, $sell_price, $reorder, $prod_id, $company_id, $client_id]);

            log_audit($company_id, $user_id, 'catalog_product_updated', 'stock', $prod_id, "Product updated: $name");
            echo json_encode(['success' => true]);
            break;

        case 'log_wastage':
            $product_id = intval($_POST['product_id'] ?? 0);
            $qty        = intval($_POST['quantity'] ?? 0);
            $reason     = clean_input($_POST['reason'] ?? '');
            $date       = $_POST['date'] ?? date('Y-m-d');
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO wastage_log (company_id, client_id, product_id, quantity, reason, wastage_date, reported_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $product_id, $qty, $reason, $date, $user_id]);
            
            $stmt = $pdo->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ? AND company_id = ? AND current_stock >= ?");
            $stmt->execute([$qty, $product_id, $company_id, $qty]);
            
            $stmt = $pdo->prepare("INSERT INTO stock_movements (company_id, client_id, product_id, type, quantity, reference_type, notes, performed_by) VALUES (?,?,?,'out',?,'wastage',?,?)");
            $stmt->execute([$company_id, $client_id, $product_id, $qty, $reason, $user_id]);
            
            $pdo->commit();
            log_audit($company_id, $user_id, 'wastage_logged', 'stock', $product_id, "$qty units wasted: $reason");
            echo json_encode(['success' => true]);
            break;
            
        case 'save_count':
            $product_id   = intval($_POST['product_id'] ?? 0);
            $system_count = intval($_POST['system_count'] ?? 0);
            $physical_count = intval($_POST['physical_count'] ?? 0);
            $date         = $_POST['date'] ?? date('Y-m-d');
            $notes        = clean_input($_POST['notes'] ?? '');
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO physical_counts (company_id, client_id, product_id, count_date, system_count, physical_count, notes, counted_by) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $product_id, $date, $system_count, $physical_count, $notes, $user_id]);
            
            // Update actual stock
            $stmt = $pdo->prepare("UPDATE products SET current_stock = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$physical_count, $product_id, $company_id]);
            
            $diff = $physical_count - $system_count;
            if ($diff != 0) {
                $type = $diff > 0 ? 'adjustment_in' : 'adjustment_out';
                $stmt = $pdo->prepare("INSERT INTO stock_movements (company_id, client_id, product_id, type, quantity, reference_type, notes, performed_by) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$company_id, $client_id, $product_id, $type, abs($diff), 'physical_count', "Adjusted from $system_count to $physical_count", $user_id]);
            }
            
            $pdo->commit();
            log_audit($company_id, $user_id, 'physical_count', 'stock', $product_id, "System: $system_count, Physical: $physical_count, Diff: $diff");
            echo json_encode(['success' => true]);
            break;

        case 'stock_adjustment':
            $product_id = intval($_POST['product_id'] ?? 0);
            $qty        = intval($_POST['quantity'] ?? 0);
            $type       = clean_input($_POST['type'] ?? 'adjustment_out');
            $notes      = clean_input($_POST['notes'] ?? '');
            
            if ($qty <= 0) {
                echo json_encode(['success' => false, 'message' => 'Quantity must be greater than 0']);
                break;
            }
            
            $pdo->beginTransaction();
            
            // Record the movement
            $stmt = $pdo->prepare("INSERT INTO stock_movements (company_id, client_id, product_id, type, quantity, reference_type, notes, performed_by) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $product_id, $type, $qty, 'manual_adjustment', $notes, $user_id]);
            
            $pdo->commit();
            log_audit($company_id, $user_id, 'stock_adjustment', 'stock', $product_id, "$type: $qty units - $notes");
            echo json_encode(['success' => true]);
            break;
            
        case 'stock_out':
            $product_id = intval($_POST['product_id'] ?? 0);
            $qty        = intval($_POST['quantity'] ?? 0);
            $reason     = clean_input($_POST['reason'] ?? 'sales');
            $notes      = clean_input($_POST['notes'] ?? '');
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ? AND company_id = ? AND current_stock >= ?");
            $result = $stmt->execute([$qty, $product_id, $company_id, $qty]);
            
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Insufficient stock']);
                break;
            }
            
            $stmt = $pdo->prepare("INSERT INTO stock_movements (company_id, client_id, product_id, type, quantity, reference_type, notes, performed_by) VALUES (?,?,?,'out',?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $product_id, $qty, $reason, $notes, $user_id]);
            
            $pdo->commit();
            log_audit($company_id, $user_id, 'stock_out', 'stock', $product_id, "$qty units out: $reason");
            echo json_encode(['success' => true]);
            break;

        case 'recall_delivery':
            $delivery_id = intval($_POST['delivery_id'] ?? 0);
            $product_id  = intval($_POST['product_id'] ?? 0);
            $qty         = intval($_POST['quantity'] ?? 0);
            $notes       = clean_input($_POST['notes'] ?? '');
            
            if ($qty <= 0) {
                echo json_encode(['success' => false, 'message' => 'Quantity must be greater than 0']);
                break;
            }

            $pdo->beginTransaction();
            
            // Reduce product current_stock
            $stmt = $pdo->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ? AND company_id = ? AND current_stock >= ?");
            $stmt->execute([$qty, $product_id, $company_id, $qty]);
            
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Insufficient stock to recall']);
                break;
            }
            
            // Record a return_outward stock movement
            $stmt = $pdo->prepare("INSERT INTO stock_movements (company_id, client_id, product_id, type, quantity, reference_type, notes, performed_by) VALUES (?,?,?,'return_outward',?,'recall',?,?)");
            $stmt->execute([$company_id, $client_id, $product_id, $qty, $notes ? $notes : "Recall from delivery #$delivery_id", $user_id]);
            
            $pdo->commit();
            log_audit($company_id, $user_id, 'recall_delivery', 'stock', $product_id, "$qty units returned outward (delivery #$delivery_id)");
            echo json_encode(['success' => true]);
            break;
            
        case 'create_department':
            $name        = clean_input($_POST['name'] ?? '');
            $outlet_id   = intval($_POST['outlet_id'] ?? 0);
            $description = clean_input($_POST['description'] ?? '');
            
            // Auto-append "Dept" if not already ending with it
            if ($name && !preg_match('/\bDept$/i', trim($name))) {
                $name = trim($name) . ' Dept';
            }
            
            if (!$name || !$outlet_id) {
                echo json_encode(['success' => false, 'message' => 'Name and outlet are required']);
                break;
            }
            
            $stmt = $pdo->prepare("INSERT INTO stock_departments (company_id, client_id, name, outlet_id, description, created_by) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $name, $outlet_id, $description, $user_id]);
            
            log_audit($company_id, $user_id, 'department_created', 'stock', $pdo->lastInsertId(), "Department '$name' created");
            echo json_encode(['success' => true]);
            break;
            
        case 'delete_department':
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE stock_departments SET deleted_at = NOW() WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$id, $company_id, $client_id]);
            
            log_audit($company_id, $user_id, 'department_deleted', 'stock', $id, "Department soft-deleted");
            echo json_encode(['success' => true]);
            break;
            
        case 'update_product':
            $pid           = intval($_POST['product_id'] ?? 0);
            $name          = clean_input($_POST['name'] ?? '');
            $sku           = clean_input($_POST['sku'] ?? '');
            $category      = trim($_POST['category'] ?? '');  // raw, not htmlspecialchars
            $unit_cost     = floatval($_POST['unit_cost'] ?? 0);
            $selling_price = floatval($_POST['selling_price'] ?? 0);
            $opening_stock = intval($_POST['opening_stock'] ?? 0);
            $current_stock = intval($_POST['current_stock'] ?? 0);
            $reorder_level = intval($_POST['reorder_level'] ?? 10);
            
            if (!$pid || !$name) {
                echo json_encode(['success' => false, 'message' => 'Product ID and name are required']);
                break;
            }
            
            $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, category=?, unit_cost=?, selling_price=?, opening_stock=?, current_stock=?, reorder_level=?, updated_at=NOW() WHERE id=? AND company_id=? AND client_id=?");
            $stmt->execute([$name, $sku, $category, $unit_cost, $selling_price, $opening_stock, $current_stock, $reorder_level, $pid, $company_id, $client_id]);
            
            log_audit($company_id, $user_id, 'product_updated', 'stock', $pid, "Product '$name' updated, stock=$current_stock");
            echo json_encode(['success' => true]);
            break;
            
        case 'add_dept_product':
            $dept_id    = intval($_POST['department_id'] ?? 0);
            $product_id = intval($_POST['product_id'] ?? 0);
            $opening    = intval($_POST['opening_stock'] ?? 0);
            $sell_price = floatval($_POST['selling_price'] ?? 0);
            $stock_date = $_POST['stock_date'] ?? date('Y-m-d');
            
            if (!$dept_id || !$product_id) {
                echo json_encode(['success' => false, 'message' => 'Department and product required']);
                break;
            }
            
            // Check if already exists for this date
            $chk = $pdo->prepare("SELECT id FROM department_stock WHERE department_id=? AND product_id=? AND stock_date=?");
            $chk->execute([$dept_id, $product_id, $stock_date]);
            if ($chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Product already in this department for this date']);
                break;
            }
            
            $stmt = $pdo->prepare("INSERT INTO department_stock (company_id, client_id, department_id, product_id, opening_stock, selling_price, stock_date) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $dept_id, $product_id, $opening, $sell_price, $stock_date]);
            
            log_audit($company_id, $user_id, 'dept_product_added', 'stock', $product_id, "Product added to dept $dept_id on $stock_date, opening=$opening");
            echo json_encode(['success' => true]);
            break;
            
        case 'update_dept_stock':
            $ds_id          = intval($_POST['id'] ?? 0);
            $dept_id_post   = intval($_POST['department_id'] ?? 0);
            $prod_id_post   = intval($_POST['product_id'] ?? 0);
            $opening        = intval($_POST['opening_stock'] ?? 0);
            $added          = intval($_POST['added'] ?? 0);
            $return_in      = intval($_POST['return_in'] ?? 0);
            $transfer_out   = intval($_POST['transfer_out'] ?? 0);
            $transfer_to_main = intval($_POST['transfer_to_main'] ?? 0);
            $qty_sold       = intval($_POST['qty_sold'] ?? 0);
            $sell_price     = floatval($_POST['selling_price'] ?? 0);
            $stock_date     = $_POST['stock_date'] ?? date('Y-m-d');
            $transfer_dest  = trim($_POST['transfer_destination'] ?? '');
            
            // We need product_id either from existing record or from POST
            $src_product_id = $prod_id_post;
            $src_dept_id    = $dept_id_post;
            $old_transfer   = 0;
            $old_transfer_main = 0;
            
            // If we have an existing record ID, get old data from it
            if ($ds_id) {
                $old_rec = $pdo->prepare("SELECT product_id, department_id, transfer_out as old_transfer, transfer_to_main as old_transfer_main FROM department_stock WHERE id = ? AND company_id = ? AND client_id = ?");
                $old_rec->execute([$ds_id, $company_id, $client_id]);
                $old_data = $old_rec->fetch();
                if ($old_data) {
                    $old_transfer = intval($old_data['old_transfer']);
                    $old_transfer_main = intval($old_data['old_transfer_main']);
                    $src_product_id = $old_data['product_id'];
                    $src_dept_id = $old_data['department_id'];
                }
            }
            
            if (!$src_product_id) {
                echo json_encode(['success' => false, 'message' => 'Product ID required']);
                break;
            }
            
            // If transferring to another dept, validate destination has the product
            if ($transfer_out > 0 && $transfer_dest) {
                $dest_dept_id = intval($transfer_dest);
                
                // Check if destination department has this product
                $chk = $pdo->prepare("SELECT COUNT(*) FROM department_stock WHERE department_id = ? AND product_id = ? AND company_id = ? AND client_id = ?");
                $chk->execute([$dest_dept_id, $src_product_id, $company_id, $client_id]);
                $exists = $chk->fetchColumn();
                
                if (!$exists) {
                    $dname = $pdo->prepare("SELECT name FROM stock_departments WHERE id = ? AND company_id = ? AND client_id = ?");
                    $dname->execute([$dest_dept_id, $company_id, $client_id]);
                    $dest_name = $dname->fetchColumn() ?: "Department #$dest_dept_id";
                    
                    $pname = $pdo->prepare("SELECT name FROM products WHERE id = ? AND company_id = ? AND client_id = ?");
                    $pname->execute([$src_product_id, $company_id, $client_id]);
                    $prod_name = $pname->fetchColumn() ?: "this product";
                    
                    echo json_encode([
                        'success' => false, 
                        'message' => "Transfer rejected: \"$prod_name\" has not been added to \"$dest_name\" yet. Please go to $dest_name and add the product first using the \"+ Add Product\" button, then retry the transfer.",
                        'code' => 'DEST_PRODUCT_MISSING'
                    ]);
                    break;
                }
            }
            
            // Save: UPDATE if we have an ID, otherwise UPSERT for virtual rows
            if ($ds_id) {
                $stmt = $pdo->prepare("UPDATE department_stock SET opening_stock=?, added=?, return_in=?, transfer_out=?, transfer_to_main=?, qty_sold=?, selling_price=?, stock_date=?, updated_at=NOW() WHERE id=? AND company_id=? AND client_id=?");
                $stmt->execute([$opening, $added, $return_in, $transfer_out, $transfer_to_main, $qty_sold, $sell_price, $stock_date, $ds_id, $company_id, $client_id]);
            } else {
                // Virtual row — UPSERT by unique key (department_id, product_id, stock_date)
                $stmt = $pdo->prepare("INSERT INTO department_stock (company_id, client_id, department_id, product_id, stock_date, opening_stock, added, return_in, transfer_out, transfer_to_main, qty_sold, selling_price) 
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?) 
                    ON DUPLICATE KEY UPDATE 
                    opening_stock = VALUES(opening_stock), added = VALUES(added), return_in = VALUES(return_in),
                    transfer_out = VALUES(transfer_out), transfer_to_main = VALUES(transfer_to_main), qty_sold = VALUES(qty_sold), selling_price = VALUES(selling_price)");
                $stmt->execute([$company_id, $client_id, $src_dept_id, $src_product_id, $stock_date, $opening, $added, $return_in, $transfer_out, $transfer_to_main, $qty_sold, $sell_price]);
            }
            
            // Auto-receive: if transferring to another department, add to destination's return_in
            if ($transfer_out > 0 && $transfer_dest && $src_product_id) {
                $dest_dept_id = intval($transfer_dest);
                $transfer_delta = $transfer_out - $old_transfer; // Only add the new increment
                
                if ($transfer_delta > 0) {
                    // Update destination's return_in for this product on this date
                    $upd = $pdo->prepare("UPDATE department_stock SET return_in = return_in + ?, updated_at = NOW() WHERE department_id = ? AND product_id = ? AND stock_date = ? AND company_id = ? AND client_id = ?");
                    $upd->execute([$transfer_delta, $dest_dept_id, $src_product_id, $stock_date, $company_id, $client_id]);
                    
                    log_audit($company_id, $user_id, 'inter_dept_transfer', 'stock', $src_product_id, "Inter-dept transfer: $transfer_delta units to dept $dest_dept_id on $stock_date");
                }
            }
            
            // Auto-receive: if transferring to main store, add back to product's current_stock
            if ($transfer_to_main > 0 && $src_product_id) {
                $main_delta = $transfer_to_main - $old_transfer_main;
                if ($main_delta > 0) {
                    $upd = $pdo->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ? AND company_id = ? AND client_id = ?");
                    $upd->execute([$main_delta, $src_product_id, $company_id, $client_id]);
                    
                    // Log the movement
                    $stmt_mv = $pdo->prepare("INSERT INTO stock_movements (company_id, client_id, product_id, type, quantity, reference_type, notes, performed_by) VALUES (?,?,?,'in',?,'dept_return',?,?)");
                    $dept_name_q = $pdo->prepare("SELECT name FROM stock_departments WHERE id = ?");
                    $dept_name_q->execute([$src_dept_id]);
                    $dept_nm = $dept_name_q->fetchColumn() ?: "Dept #$src_dept_id";
                    $stmt_mv->execute([$company_id, $client_id, $src_product_id, $main_delta, "Returned from $dept_nm on $stock_date", $user_id]);
                    
                    log_audit($company_id, $user_id, 'dept_to_main_transfer', 'stock', $src_product_id, "Dept return to main store: $main_delta units from dept $src_dept_id on $stock_date");
                }
            }
            
            log_audit($company_id, $user_id, 'dept_stock_updated', 'stock', $ds_id ?: $src_product_id, "Dept stock updated on $stock_date: opening=$opening, added=$added, sold=$qty_sold, transfer=$transfer_out dest=$transfer_dest");
            echo json_encode(['success' => true]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }

    // ---- Category management (outside switch for cleanliness, re-check action) ----
    if ($action === 'add_category') {
        $cat_name = trim($_POST['name'] ?? '');
        if (!$cat_name) { echo json_encode(['success' => false, 'message' => 'Category name required']); exit; }
        try {
            $stmt = $pdo->prepare("INSERT INTO product_categories (company_id, client_id, name) VALUES (?,?,?)");
            $stmt->execute([$company_id, $client_id, $cat_name]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) echo json_encode(['success' => false, 'message' => 'Category already exists']);
            else throw $e;
        }
        exit;
    }
    if ($action === 'delete_category') {
        $cat_id = intval($_POST['id'] ?? 0);
        if (!$cat_id) { echo json_encode(['success' => false, 'message' => 'Category ID required']); exit; }
        $stmt = $pdo->prepare("DELETE FROM product_categories WHERE id = ? AND company_id = ? AND client_id = ?");
        $stmt->execute([$cat_id, $company_id, $client_id]);
        echo json_encode(['success' => true]);
        exit;
    }
    // ---- Supplier management ----
    if ($action === 'add_supplier') {
        $name    = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_person'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $cat     = trim($_POST['category'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');
        if (!$name) { echo json_encode(['success' => false, 'message' => 'Supplier name required']); exit; }
        try {
            $stmt = $pdo->prepare("INSERT INTO suppliers (company_id, client_id, name, contact_person, phone, email, address, category, notes) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $name, $contact, $phone, $email, $address, $cat, $notes]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) echo json_encode(['success' => false, 'message' => 'Supplier already exists']);
            else throw $e;
        }
        exit;
    }
    if ($action === 'update_supplier') {
        $sid     = intval($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_person'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $cat     = trim($_POST['category'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');
        $status  = trim($_POST['status'] ?? 'active');
        if (!$sid || !$name) { echo json_encode(['success' => false, 'message' => 'Supplier ID and name required']); exit; }
        $stmt = $pdo->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, category=?, notes=?, status=? WHERE id=? AND company_id=? AND client_id=?");
        $stmt->execute([$name, $contact, $phone, $email, $address, $cat, $notes, $status, $sid, $company_id, $client_id]);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'delete_supplier') {
        $sid = intval($_POST['id'] ?? 0);
        if (!$sid) { echo json_encode(['success' => false, 'message' => 'Supplier ID required']); exit; }
        $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ? AND company_id = ? AND client_id = ?");
        $stmt->execute([$sid, $company_id, $client_id]);
        echo json_encode(['success' => true]);
        exit;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
