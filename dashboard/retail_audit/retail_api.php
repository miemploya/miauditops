<?php
require_once '../../includes/functions.php';
require_login();

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$company_id = $_SESSION['company_id'];
$client_id = get_active_client();
$outlet_id = $_SESSION['retail_audit_outlet_id'] ?? null;

if (!$client_id || !$outlet_id) {
    echo json_encode(['success' => false, 'message' => 'Missing Active Client or Outlet context.']);
    exit;
}

try {
    // Auto-migration: ensure ALL required columns exist on retail_audit_sessions before any action
foreach ([
    'session_name VARCHAR(150) NULL',
    "status VARCHAR(50) DEFAULT 'finalized'",
    'total_items_counted INT DEFAULT 0',
    'total_physical_value DECIMAL(15,2) DEFAULT 0.00',
    'original_frozen_json LONGTEXT NULL'
] as $col) {
    try { $pdo->exec("ALTER TABLE retail_audit_sessions ADD COLUMN $col"); } catch(\Throwable $e) {}
}

switch ($action) {
        // ==========================
        // CATEGORIES
        // ==========================
        case 'get_categories':
            $stmt = $pdo->prepare("SELECT * FROM retail_categories WHERE company_id = ? AND client_id = ? AND outlet_id = ? ORDER BY name ASC");
            $stmt->execute([$company_id, $client_id, $outlet_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'add_category':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $name = trim($_POST['name'] ?? '');
            if(empty($name)) { echo json_encode(['success'=>false, 'message'=>'Category name is required.']); exit; }
            
            // Check if exists
            $stmt = $pdo->prepare("SELECT id FROM retail_categories WHERE company_id = ? AND client_id = ? AND outlet_id = ? AND LOWER(name) = ?");
            $stmt->execute([$company_id, $client_id, $outlet_id, strtolower($name)]);
            if($stmt->fetch()) { echo json_encode(['success'=>false, 'message'=>'Category already exists.']); exit; }

            $stmt = $pdo->prepare("INSERT INTO retail_categories (company_id, client_id, outlet_id, name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$company_id, $client_id, $outlet_id, $name]);
            echo json_encode(['success' => true, 'message' => 'Category created!']);
            break;

        case 'delete_category':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $cid = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM retail_categories WHERE id = ? AND company_id = ? AND client_id = ? AND outlet_id = ?");
            $stmt->execute([$cid, $company_id, $client_id, $outlet_id]);
            echo json_encode(['success' => true, 'message' => 'Category removed.']);
            break;

        // ==========================
        // PRODUCTS REGISTRY
        // ==========================
        case 'get_products':
            $stmt = $pdo->prepare("SELECT * FROM retail_products WHERE company_id = ? AND client_id = ? AND outlet_id = ? ORDER BY name ASC");
            $stmt->execute([$company_id, $client_id, $outlet_id]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $products]);
            break;

        case 'add_product':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $sku = trim($_POST['sku'] ?? '');
            $expiry = trim($_POST['expiry_date'] ?? '');
            $expiry = empty($expiry) ? null : $expiry;
            $cost_price = (float)($_POST['cost_price'] ?? 0);
            $pack_qty = (float)($_POST['pack_qty'] ?? 1);
            if ($pack_qty <= 0) $pack_qty = 1; // Prevent division by zero
            $unit_cost = $cost_price / $pack_qty;
            
            $price = (float)($_POST['selling_price'] ?? 0);
            
            $bulk_unit = trim($_POST['bulk_unit'] ?? 'Pack');
            $unit = trim($_POST['unit'] ?? 'pcs');
            $supplier_id = empty($_POST['supplier_id']) ? null : (int)$_POST['supplier_id'];
            
            if (empty($name)) { echo json_encode(['success'=>false, 'message'=>'Product name is required.']); exit; }

            $stmt = $pdo->prepare("INSERT INTO retail_products (company_id, client_id, outlet_id, name, category, sku, expiry_date, unit_cost, selling_price, cost_price, pack_qty, bulk_unit, unit, supplier_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$company_id, $client_id, $outlet_id, $name, $category, $sku, $expiry, $unit_cost, $price, $cost_price, $pack_qty, $bulk_unit, $unit, $supplier_id]);
            echo json_encode(['success' => true, 'message' => 'Product added successfully.']);
            break;

        case 'update_product':
            if (!is_admin_role()) { echo json_encode(['success'=>false, 'message'=>'Only owners and admins can edit products.']); exit; }
            $pid = (int)($_POST['product_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $sku = trim($_POST['sku'] ?? '');
            $expiry = trim($_POST['expiry_date'] ?? '');
            $expiry = empty($expiry) ? null : $expiry;
            $cost_price = (float)($_POST['cost_price'] ?? 0);
            $pack_qty = (float)($_POST['pack_qty'] ?? 1);
            if ($pack_qty <= 0) $pack_qty = 1;
            $unit_cost = $cost_price / $pack_qty;
            
            $price = (float)($_POST['selling_price'] ?? 0);
            
            $bulk_unit = trim($_POST['bulk_unit'] ?? 'Pack');
            $unit = trim($_POST['unit'] ?? 'pcs');
            $supplier_id = empty($_POST['supplier_id']) ? null : (int)$_POST['supplier_id'];
            
            if (empty($name)) { echo json_encode(['success'=>false, 'message'=>'Product name is required.']); exit; }

            $stmt = $pdo->prepare("UPDATE retail_products SET name=?, category=?, sku=?, expiry_date=?, unit_cost=?, selling_price=?, cost_price=?, pack_qty=?, bulk_unit=?, unit=?, supplier_id=? WHERE id=? AND company_id=? AND client_id=? AND outlet_id=?");
            $stmt->execute([$name, $category, $sku, $expiry, $unit_cost, $price, $cost_price, $pack_qty, $bulk_unit, $unit, $supplier_id, $pid, $company_id, $client_id, $outlet_id]);
            echo json_encode(['success' => true, 'message' => 'Product updated successfully.']);
            break;

        case 'delete_product':
            if (!is_admin_role()) { echo json_encode(['success'=>false, 'message'=>'Only owners and admins can delete products.']); exit; }
            $pid = (int)($_POST['id'] ?? 0);
            
            // Check if product has active purchases or audits that would break records
            $chk1 = $pdo->prepare("SELECT id FROM retail_purchases WHERE product_id = ? AND company_id = ? LIMIT 1");
            $chk1->execute([$pid, $company_id]);
            if($chk1->fetch()) { echo json_encode(['success'=>false, 'message'=>'Cannot delete product: It is linked to existing delivery records.']); exit; }
            
            $chk2 = $pdo->prepare("SELECT id FROM retail_audit_lines WHERE product_id = ? LIMIT 1");
            $chk2->execute([$pid]);
            if($chk2->fetch()) { echo json_encode(['success'=>false, 'message'=>'Cannot delete product: It has been used in previous physical counts.']); exit; }

            $stmt = $pdo->prepare("DELETE FROM retail_products WHERE id=? AND company_id=? AND client_id=? AND outlet_id=?");
            $stmt->execute([$pid, $company_id, $client_id, $outlet_id]);
            echo json_encode(['success' => true, 'message' => 'Product deleted forever.']);
            break;


        case 'import_products':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (empty($items)) { echo json_encode(['success'=>false, 'message'=>'No valid items provided.']); exit; }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO retail_products (company_id, client_id, outlet_id, name, category, sku, expiry_date, unit_cost, selling_price, current_system_stock, cost_price, pack_qty, bulk_unit, unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $added = 0;
            foreach ($items as $item) {
                // Ignore empty rows
                if (empty(trim($item['name'] ?? ''))) continue;
                $expiry = trim($item['expiry_date'] ?? '');
                $expiry = empty($expiry) ? null : $expiry;
                
                $cost_price = (float)($item['cost_price'] ?? 0);
                $pack_qty = (float)($item['pack_qty'] ?? 1);
                if ($pack_qty <= 0) $pack_qty = 1;
                $unit_cost = $cost_price / $pack_qty;
                
                $bulk_unit = trim($item['bulk_unit'] ?? 'Pack');
                $unit = trim($item['unit'] ?? 'pcs');
                if (empty($bulk_unit)) $bulk_unit = 'Pack';
                if (empty($unit)) $unit = 'pcs';
                
                $stmt->execute([
                    $company_id, $client_id, $outlet_id, 
                    trim($item['name']), 
                    trim($item['category'] ?? 'Uncategorized'), 
                    trim($item['sku'] ?? ''), 
                    $expiry,
                    $unit_cost, 
                    (float)($item['selling_price'] ?? 0),
                    (float)($item['qty'] ?? 0),
                    $cost_price,
                    $pack_qty,
                    $bulk_unit,
                    $unit
                ]);
                $added++;
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "$added products officially imported."]);
            break;

        // ==========================
        // SUPPLIERS
        // ==========================
        case 'get_suppliers':
            $stmt = $pdo->prepare("
                SELECT s.*, 
                       COALESCE(SUM(p.quantity_added), 0) as units_supplied, 
                       COALESCE(SUM(p.total_cost), 0) as total_value_supplied 
                FROM retail_suppliers s 
                LEFT JOIN retail_purchases p ON s.id = p.supplier_id 
                WHERE s.company_id = ? AND s.client_id = ? AND s.outlet_id = ? 
                GROUP BY s.id 
                ORDER BY s.name ASC
            ");
            $stmt->execute([$company_id, $client_id, $outlet_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'get_supplier_history':
            $stmt = $pdo->prepare("
                SELECT s.id, s.name, s.phone, s.email,
                       DATE_FORMAT(p.purchase_date, '%M %Y') as month_label,
                       DATE_FORMAT(p.purchase_date, '%Y-%m') as sort_month,
                       COALESCE(SUM(p.quantity_added), 0) as units_supplied, 
                       COALESCE(SUM(p.total_cost), 0) as total_value_supplied 
                FROM retail_suppliers s 
                LEFT JOIN retail_purchases p ON s.id = p.supplier_id 
                WHERE s.company_id = ? AND s.client_id = ? AND s.outlet_id = ? 
                GROUP BY s.id, sort_month, month_label
                ORDER BY sort_month DESC, s.name ASC
            ");
            $stmt->execute([$company_id, $client_id, $outlet_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'add_supplier':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $name = trim($_POST['name'] ?? '');
            if(empty($name)) { echo json_encode(['success'=>false, 'message'=>'Supplier name is required.']); exit; }
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            $stmt = $pdo->prepare("SELECT id FROM retail_suppliers WHERE company_id = ? AND client_id = ? AND outlet_id = ? AND LOWER(name) = ?");
            $stmt->execute([$company_id, $client_id, $outlet_id, strtolower($name)]);
            if($stmt->fetch()) { echo json_encode(['success'=>false, 'message'=>'Supplier already exists.']); exit; }

            $stmt = $pdo->prepare("INSERT INTO retail_suppliers (company_id, client_id, outlet_id, name, phone, email) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$company_id, $client_id, $outlet_id, $name, $phone, $email]);
            echo json_encode(['success' => true, 'message' => 'Supplier added!']);
            break;

        case 'delete_supplier':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $sid = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM retail_suppliers WHERE id = ? AND company_id = ? AND client_id = ? AND outlet_id = ?");
            $stmt->execute([$sid, $company_id, $client_id, $outlet_id]);
            echo json_encode(['success' => true, 'message' => 'Supplier removed.']);
            break;

        // ==========================
        // PURCHASES / ADDITIONS
        // ==========================
        case 'get_purchases':
            $stmt = $pdo->prepare("SELECT p.*, pr.name as product_name FROM retail_purchases p JOIN retail_products pr ON p.product_id = pr.id WHERE p.company_id = ? AND p.client_id = ? AND p.outlet_id = ? ORDER BY p.purchase_date DESC, p.id DESC");
            $stmt->execute([$company_id, $client_id, $outlet_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'delete_purchase':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $id = (int)$_POST['id'];
            $reason = trim($_POST['reason'] ?? '');
            if(empty($reason)) { echo json_encode(['success'=>false, 'message'=>'A reason is required to delete an addition.']); exit; }
            
            $pdo->beginTransaction();
            // Fetch the old purchase
            $stmt = $pdo->prepare("SELECT * FROM retail_purchases WHERE id = ? AND company_id = ? AND client_id = ? AND outlet_id = ? FOR UPDATE");
            $stmt->execute([$id, $company_id, $client_id, $outlet_id]);
            $pur = $stmt->fetch();
            if(!$pur) { $pdo->rollBack(); echo json_encode(['success'=>false, 'message'=>'Record not found']); exit; }
            
            // Revert master stock
            $stmt2 = $pdo->prepare("UPDATE retail_products SET current_system_stock = current_system_stock - ? WHERE id = ?");
            $stmt2->execute([$pur['quantity_added'], $pur['product_id']]);
            
            // Audit Log
            $stmtAudit = $pdo->prepare("INSERT INTO audit_trail (company_id, user_id, action, module, record_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $details = "Deleted Retail Addition. Quantity Removed: " . $pur['quantity_added'] . ". Cost Removed: " . $pur['total_cost'] . ". Reason: " . $reason;
            $stmtAudit->execute([$company_id, $active_user['id'] ?? null, 'DELETE RETAIL ADDITION', 'Retail Audit', $pur['product_id'], $details, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            // Delete record
            $stmtDel = $pdo->prepare("DELETE FROM retail_purchases WHERE id = ?");
            $stmtDel->execute([$id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Addition deleted and stock reverted.']);
            break;

        case 'update_purchase':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $id = (int)$_POST['purchase_id'];
            $product_id = (int)$_POST['product_id'];
            $new_qty = (float)$_POST['quantity_added'];
            $date = trim($_POST['purchase_date']);
            $cost = (float)($_POST['total_cost'] ?? 0);
            $ref = trim($_POST['reference'] ?? '');
            $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;

            if ($id <= 0 || $product_id <= 0 || $new_qty <= 0) { echo json_encode(['success'=>false, 'message'=>'Invalid product or quantity.']); exit; }

            $pdo->beginTransaction();
            // Fetch old
            $stmt = $pdo->prepare("SELECT * FROM retail_purchases WHERE id = ? AND company_id = ? AND client_id = ? AND outlet_id = ? FOR UPDATE");
            $stmt->execute([$id, $company_id, $client_id, $outlet_id]);
            $old_pur = $stmt->fetch();
            if(!$old_pur) { $pdo->rollBack(); echo json_encode(['success'=>false, 'message'=>'Record not found']); exit; }
            
            // Revert old stock entirely from master
            $stmtRev = $pdo->prepare("UPDATE retail_products SET current_system_stock = current_system_stock - ? WHERE id = ?");
            $stmtRev->execute([$old_pur['quantity_added'], $old_pur['product_id']]);
            
            // Apply new stock entirely to master
            $new_unit_cost = ($new_qty > 0 && $cost > 0) ? ($cost / $new_qty) : 0;
            if ($new_unit_cost > 0) {
                $stmtAdd = $pdo->prepare("UPDATE retail_products SET current_system_stock = current_system_stock + ?, unit_cost = ? WHERE id = ?");
                $stmtAdd->execute([$new_qty, $new_unit_cost, $product_id]);
            } else {
                $stmtAdd = $pdo->prepare("UPDATE retail_products SET current_system_stock = current_system_stock + ? WHERE id = ?");
                $stmtAdd->execute([$new_qty, $product_id]);
            }
            
            // Update purchase record
            $stmtUp = $pdo->prepare("UPDATE retail_purchases SET product_id=?, quantity_added=?, total_cost=?, purchase_date=?, reference=?, supplier_id=? WHERE id=?");
            $stmtUp->execute([$product_id, $new_qty, $cost, $date, $ref, $supplier_id, $id]);
            
            // Audit logic
            $stmtAudit = $pdo->prepare("INSERT INTO audit_trail (company_id, user_id, action, module, record_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $details = "Edited Retail Addition. Old Qty: " . $old_pur['quantity_added'] . ", New Qty: $new_qty. Reason: System Edit.";
            $stmtAudit->execute([$company_id, $active_user['id'] ?? null, 'EDIT RETAIL ADDITION', 'Retail Audit', $id, $details, $_SERVER['REMOTE_ADDR'] ?? '']);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Addition updated successfully.']);
            break;

        case 'add_purchase':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $product_id = (int)$_POST['product_id'];
            $qty = (float)$_POST['quantity_added'];
            $date = trim($_POST['purchase_date']);
            $cost = (float)($_POST['total_cost'] ?? 0);
            $ref = trim($_POST['reference'] ?? '');

            $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;

            if ($product_id <= 0 || $qty <= 0) { echo json_encode(['success'=>false, 'message'=>'Invalid product or quantity.']); exit; }
            
            // Calculate new unit cost inherently
            $new_unit_cost = ($qty > 0 && $cost > 0) ? ($cost / $qty) : 0;

            $pdo->beginTransaction();
            // Add purchase
            $stmt = $pdo->prepare("INSERT INTO retail_purchases (company_id, client_id, outlet_id, product_id, quantity_added, total_cost, purchase_date, reference, supplier_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$company_id, $client_id, $outlet_id, $product_id, $qty, $cost, $date, $ref, $supplier_id]);
            
            // Update master stock & unit cost natively
            if ($new_unit_cost > 0) {
                $st2 = $pdo->prepare("UPDATE retail_products SET current_system_stock = current_system_stock + ?, unit_cost = ? WHERE id = ?");
                $st2->execute([$qty, $new_unit_cost, $product_id]);
            } else {
                $st2 = $pdo->prepare("UPDATE retail_products SET current_system_stock = current_system_stock + ? WHERE id = ?");
                $st2->execute([$qty, $product_id]);
            }
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Delivery logged successfully.']);
            break;

        case 'import_purchases':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (empty($items)) { echo json_encode(['success'=>false, 'message'=>'No valid items provided.']); exit; }

            $global_supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;

            // We need a lookup for product names
            $stmt = $pdo->prepare("SELECT id, name FROM retail_products WHERE company_id = ? AND client_id = ? AND outlet_id = ?");
            $stmt->execute([$company_id, $client_id, $outlet_id]);
            $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $productMap = [];
            foreach($prods as $p) { $productMap[strtolower(trim($p['name']))] = $p['id']; }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO retail_purchases (company_id, client_id, outlet_id, product_id, quantity_added, total_cost, purchase_date, reference, supplier_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st2 = $pdo->prepare("UPDATE retail_products SET current_system_stock = current_system_stock + ? WHERE id = ?");
            $st2_cost = $pdo->prepare("UPDATE retail_products SET current_system_stock = current_system_stock + ?, unit_cost = ? WHERE id = ?");
            
            $added = 0;
            $skipped = 0;
            foreach ($items as $item) {
                $nameKey = strtolower(trim($item['name'] ?? ''));
                if (empty($nameKey) || !isset($productMap[$nameKey])) { $skipped++; continue; }
                
                $pid = $productMap[$nameKey];
                $qty = (float)($item['qty'] ?? 0);
                if ($qty <= 0) continue;

                $date = trim($item['date'] ?? '');
                if (empty($date)) $date = date('Y-m-d');
                $cost = (float)($item['cost'] ?? 0);

                $stmt->execute([
                    $company_id, $client_id, $outlet_id, 
                    $pid, 
                    $qty, 
                    $cost, 
                    $date,
                    trim($item['reference'] ?? ''),
                    $global_supplier_id
                ]);
                
                $new_unit_cost = ($qty > 0 && $cost > 0) ? ($cost / $qty) : 0;
                if ($new_unit_cost > 0) {
                    $st2_cost->execute([$qty, $new_unit_cost, $pid]);
                } else {
                    $st2->execute([$qty, $pid]);
                }
                $added++;
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "$added deliveries logged. $skipped skipped due to unmatched names."]);
            break;

        // ==========================
        // PHYSICAL COUNT & AUDIT
        // ==========================
        case 'get_audit_sessions':
            $stmt = $pdo->prepare("SELECT id, company_id, client_id, outlet_id, session_name, audit_date, status, total_items_counted, total_physical_value, declared_pos, declared_transfer, declared_cash, adj_add_to_sales, adj_damages, adj_written_off, adj_complimentary, adj_error, total_expected_sales, IF(original_frozen_json IS NULL, 0, 1) as has_frozen_record FROM retail_audit_sessions WHERE company_id = ? AND client_id = ? AND outlet_id = ? ORDER BY audit_date DESC, id DESC");
            $stmt->execute([$company_id, $client_id, $outlet_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'save_audit_session':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            
            // retail_audit_lines — ensure every column the INSERT needs actually exists
            foreach ([
                'system_qty DECIMAL(12,2) DEFAULT 0',
                'physical_qty DECIMAL(12,2) DEFAULT 0',
                'unit_cost DECIMAL(12,2) DEFAULT 0',
                'selling_price DECIMAL(12,2) DEFAULT 0'
            ] as $col) {
                try { $pdo->exec("ALTER TABLE retail_audit_lines ADD COLUMN $col"); } catch(\Throwable $e) {}
            }

            $session_id = (int)($_POST['session_id'] ?? 0);
            $session_name = trim($_POST['session_name'] ?? '');
            $audit_date = trim($_POST['audit_date'] ?? date('Y-m-d'));
            $action_type = trim($_POST['action_type'] ?? 'finalize');
            $lines = json_decode($_POST['lines'] ?? '[]', true);

            if (empty($session_name)) { echo json_encode(['success'=>false, 'message'=>'Session name needed.']); exit; }
            if (empty($lines)) { echo json_encode(['success'=>false, 'message'=>'No count lines provided.']); exit; }

            $pdo->beginTransaction();
            $status_val = ($action_type === 'draft') ? 'draft' : 'finalized';

            if ($session_id > 0) {
                // Assert it exists and is still a draft
                $stmtCheck = $pdo->prepare("SELECT status FROM retail_audit_sessions WHERE id = ? AND company_id = ? AND client_id = ? AND outlet_id = ? FOR UPDATE");
                $stmtCheck->execute([$session_id, $company_id, $client_id, $outlet_id]);
                $sessRow = $stmtCheck->fetch();
                if (!$sessRow) { $pdo->rollBack(); echo json_encode(['success'=>false, 'message'=>'Session not found.']); exit; }
                
                $edit_reason = trim($_POST['edit_reason'] ?? '');
                
                if ($sessRow['status'] === 'finalized') {
                    if (empty($edit_reason)) {
                        $pdo->rollBack(); echo json_encode(['success'=>false, 'message'=>'Session already finalized. Reason required to unlock.']); exit;
                    }
                    // Log the override
                    $at = $pdo->prepare("INSERT INTO audit_trail (company_id, user_id, action, module, record_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $at->execute([$company_id, $active_user['id'] ?? null, 'unlocked_physical_count', 'retail_audit', $session_id, "User unlocked and overrode a finalized physical count. Reason: $edit_reason", $_SERVER['REMOTE_ADDR'] ?? '']);
                }
                
                $updS = $pdo->prepare("UPDATE retail_audit_sessions SET session_name = ?, audit_date = ?, status = ? WHERE id = ?");
                $updS->execute([$session_name, $audit_date, $status_val, $session_id]);
                
                // Clear old lines for total rewrite
                $del = $pdo->prepare("DELETE FROM retail_audit_lines WHERE session_id = ?");
                $del->execute([$session_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO retail_audit_sessions (company_id, client_id, outlet_id, session_name, audit_date, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$company_id, $client_id, $outlet_id, $session_name, $audit_date, $status_val]);
                $session_id = $pdo->lastInsertId();
            }

            $total_items = 0;
            $nbv = 0;

            $stL = $pdo->prepare("INSERT INTO retail_audit_lines (session_id, product_id, system_qty, physical_qty, unit_cost, selling_price) VALUES (?, ?, ?, ?, ?, ?)");
            
            if ($action_type === 'finalize') {
                $stUpd = $pdo->prepare("UPDATE retail_products SET current_system_stock = ? WHERE id = ?");
            }

            foreach ($lines as $ln) {
                $pid = (int)($ln['product_id'] ?? 0);
                if ($pid <= 0) continue;
                $pQty = trim($ln['physical_qty']);
                if ($pQty === '') continue; // Skip blank inputs
                
                $pQty = (float)$pQty;
                $sQty = (float)($ln['system_qty'] ?? 0);
                $cost = (float)($ln['unit_cost'] ?? 0);
                $price = (float)($ln['selling_price'] ?? 0);

                $stL->execute([$session_id, $pid, $sQty, $pQty, $cost, $price]);
                if ($action_type === 'finalize') {
                    $stUpd->execute([$pQty, $pid]);
                }

                $total_items++;
                $nbv += ($pQty * $cost);
            }

            $upd = $pdo->prepare("UPDATE retail_audit_sessions SET total_items_counted = ?, total_physical_value = ? WHERE id = ?");
            $upd->execute([$total_items, $nbv, $session_id]);
            
            // Auto-Archiver: Freeze the original mathematical lines ONLY upon first finalize
            $is_first_finalize = false;
            if ($action_type === 'finalize') {
                if (isset($sessRow) && empty($sessRow['original_frozen_json'])) {
                    $is_first_finalize = true;
                } else if (!isset($sessRow)) {
                    $is_first_finalize = true;
                }
            }

            if ($is_first_finalize) {
                $stmtFz = $pdo->prepare("
                    SELECT l.*, p.name as product_name, p.sku 
                    FROM retail_audit_lines l 
                    JOIN retail_products p ON l.product_id = p.id 
                    WHERE l.session_id = ?
                    ORDER BY p.name ASC
                ");
                $stmtFz->execute([$session_id]);
                $frozenData = $stmtFz->fetchAll(PDO::FETCH_ASSOC);
                
                $updFz = $pdo->prepare("UPDATE retail_audit_sessions SET original_frozen_json = ? WHERE id = ?");
                $updFz->execute([json_encode($frozenData), $session_id]);
            }
            
            $pdo->commit();
            
            $msg = ($action_type === 'draft') ? "Draft saved successfully!" : "Audit session finalized and stock updated.";
            echo json_encode(['success' => true, 'message' => $msg]);
            break;

        case 'get_audit_lines':
            $sid = (int)($_POST['session_id'] ?? $_GET['session_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT l.*, p.name as product_name, p.sku 
                FROM retail_audit_lines l 
                JOIN retail_products p ON l.product_id = p.id 
                WHERE l.session_id = ?
                ORDER BY p.name ASC
            ");
            $stmt->execute([$sid]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        case 'save_sales_declarations':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $sid = (int)($_POST['session_id'] ?? 0);
            
            // Auto-migration for sales reconciliation columns
            foreach ([
                'declared_pos DECIMAL(15,2) DEFAULT 0.00',
                'declared_transfer DECIMAL(15,2) DEFAULT 0.00',
                'declared_cash DECIMAL(15,2) DEFAULT 0.00',
                'adj_add_to_sales DECIMAL(15,2) DEFAULT 0.00',
                'adj_damages DECIMAL(15,2) DEFAULT 0.00',
                'adj_written_off DECIMAL(15,2) DEFAULT 0.00',
                'adj_complimentary DECIMAL(15,2) DEFAULT 0.00',
                'adj_error DECIMAL(15,2) DEFAULT 0.00',
                'total_expected_sales DECIMAL(15,2) DEFAULT 0.00' // caching for reports
            ] as $col) {
                try { $pdo->exec("ALTER TABLE retail_audit_sessions ADD COLUMN $col"); } catch(\Throwable $e) {}
            }

            $pos = (float)($_POST['declared_pos'] ?? 0);
            $trans = (float)($_POST['declared_transfer'] ?? 0);
            $cash = (float)($_POST['declared_cash'] ?? 0);
            $adjSale = (float)($_POST['adj_add_to_sales'] ?? 0);
            $adjDamg = (float)($_POST['adj_damages'] ?? 0);
            $adjWOff = (float)($_POST['adj_written_off'] ?? 0);
            $adjComp = (float)($_POST['adj_complimentary'] ?? 0);
            $adjErr = (float)($_POST['adj_error'] ?? 0);
            $expSales = (float)($_POST['total_expected_sales'] ?? 0);

            $upd = $pdo->prepare("UPDATE retail_audit_sessions SET 
                declared_pos = ?, declared_transfer = ?, declared_cash = ?,
                adj_add_to_sales = ?, adj_damages = ?, adj_written_off = ?, adj_complimentary = ?, adj_error = ?,
                total_expected_sales = ? 
                WHERE id = ? AND company_id = ? AND client_id = ? AND outlet_id = ?");
            
            $upd->execute([
                $pos, $trans, $cash,
                $adjSale, $adjDamg, $adjWOff, $adjComp, $adjErr,
                $expSales,
                $sid, $company_id, $client_id, $outlet_id
            ]);

            echo json_encode(['success'=>true, 'message'=>"Sales reconciliation saved successfully."]);
            break;

        case 'lock_audit_session':
            if (!has_permission('settings')) { echo json_encode(['success'=>false, 'message'=>'Unauthorized']); exit; }
            $sid = (int)($_POST['session_id'] ?? 0);
            
            // Wait, locking just sets status=closed. Should we sync the system stock?
            // "Sync System Stock" should be a separate button or done concurrently. The user wanted to do this!
            $sync = (int)($_POST['sync_system'] ?? 0);
            
            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE retail_audit_sessions SET status = 'closed' WHERE id = ? AND company_id = ? AND client_id = ? AND outlet_id = ?");
            $upd->execute([$sid, $company_id, $client_id, $outlet_id]);
            if ($upd->rowCount() == 0) {
                $pdo->rollBack();
                echo json_encode(['success'=>false, 'message'=>'Invalid session or already closed.']); exit;
            }

            if ($sync === 1) {
                $lines = $pdo->prepare("SELECT product_id, physical_qty FROM retail_audit_lines WHERE session_id = ?");
                $lines->execute([$sid]);
                $stUpd = $pdo->prepare("UPDATE retail_products SET current_system_stock = ? WHERE id = ?");
                foreach ($lines->fetchAll() as $l) {
                    $stUpd->execute([$l['physical_qty'], $l['product_id']]);
                }
            }
            
            $pdo->commit();
            echo json_encode(['success'=>true, 'message'=>"Audit session finalized".($sync ? " and system stock updated." : ".")]);
            break;

        case 'download_frozen_html':
            $sid = (int)($_GET['session_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM retail_audit_sessions WHERE id = ?");
            $stmt->execute([$sid]);
            $session = $stmt->fetch();
            if(!$session || empty($session['original_frozen_json'])) {
                die("<h3 style='font-family:sans-serif; color:#ef4444; padding:20px;'>No frozen record exists. This audit may not have been finalized yet, or predates the Auto-Archiver upgrade.</h3>");
            }
            
            $lines = json_decode($session['original_frozen_json'], true);
            $date = htmlspecialchars($session['audit_date']);
            $name = htmlspecialchars($session['session_name']);
            
            $html = "<!DOCTYPE html><html><head><title>Original Audit Record: $name</title>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; padding: 40px; color: #1e293b; background: #fff; max-width: 900px; margin: 0 auto; }
                .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 30px; }
                .brand { font-size: 24px; font-weight: 900; color: #6366f1; letter-spacing: 1px; margin: 0; }
                .sub-brand { font-size: 13px; color: #94a3b8; font-weight: 600; text-transform: uppercase; }
                .title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 5px; }
                .meta { font-size: 14px; color: #64748b; }
                table { width: 100%; border-collapse: collapse; font-size: 13px; }
                th, td { padding: 12px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
                th { background: #f8fafc; font-weight: 700; color: #475569; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
                td.num { text-align: right; font-family: monospace; font-size: 14px; }
                .var-neg { color: #ef4444; font-weight: bold; }
                .var-pos { color: #10b981; font-weight: bold; }
                .var-zero { color: #94a3b8; }
                .footer { margin-top: 50px; padding-top: 20px; border-top: 1px dashed #cbd5e1; font-size: 11px; color: #94a3b8; text-align: center; }
            </style>
            </head><body>
            <div class='header'>
                <div>
                    <h1 class='title'>Original Frozen Audit Record</h1>
                    <div class='meta'><strong>Session:</strong> $name &nbsp;|&nbsp; <strong>Date:</strong> $date</div>
                </div>
                <div style='text-align:right;'>
                    <h2 class='brand'>MIAUDITOPS</h2>
                    <div class='sub-brand'>by Miemploya</div>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Product Info</th>
                        <th style='text-align:right;'>System Qty</th>
                        <th style='text-align:right;'>Physical Qty</th>
                        <th style='text-align:right;'>Variance</th>
                        <th style='text-align:right;'>Frozen Unit Cost</th>
                        <th style='text-align:right;'>Frozen Selling Price</th>
                    </tr>
                </thead>
                <tbody>";
                
            foreach($lines as $l) {
                $sys = (float)$l['system_qty'];
                $phys = (float)$l['physical_qty'];
                $var = $phys - $sys;
                $cost = number_format((float)$l['unit_cost'], 2);
                $sp = number_format((float)$l['selling_price'], 2);
                $pname = htmlspecialchars($l['product_name']);
                $psku = htmlspecialchars($l['sku']);
                
                $varClass = 'var-zero';
                if ($var < 0) $varClass = 'var-neg';
                else if ($var > 0) $varClass = 'var-pos';
                
                $html .= "<tr>
                    <td><strong>$pname</strong><br><span style='color:#94a3b8;font-size:11px;'>$psku</span></td>
                    <td class='num' style='color:#64748b;'>$sys</td>
                    <td class='num' style='font-weight:bold;'>$phys</td>
                    <td class='num $varClass'>" . ($var > 0 ? "+".$var : $var) . "</td>
                    <td class='num' style='color:#64748b;'>₦$cost</td>
                    <td class='num' style='color:#64748b;'>₦$sp</td>
                </tr>";
            }
            
            $html .= "</tbody></table>
            <div class='footer'>
                <strong>SECURITY NOTICE:</strong> This is a server-generated, immutable HTML receipt. This file acts as concrete historical evidence mathematically frozen at the exact millisecond the audit was originally finalized. It cannot be altered by subsequent unlock maneuvers or registry optimizations.
            </div>
            <script>window.onload = function() { window.print(); }</script>
            </body></html>";
            
            echo $html;
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown API action.']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("Retail API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
