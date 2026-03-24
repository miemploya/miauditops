<?php
/**
 * MIAUDITOPS — Requisition API Handler
 * Actions: create, approve, reject, convert_to_po, update_purchase_prices, get_items, delete
 */
header('Content-Type: application/json');
require_once '../includes/functions.php';
require_login();

$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$user_role  = get_user_role();
$action     = $_POST['action'] ?? '';

// Generate company-based PO prefix from client name (e.g. 'Just Peckish' → 'JUSTP')
function generate_po_prefix($client_id, $company_id, $pdo) {
    $name = $_SESSION['active_client_name'] ?? '';
    if (!$name && $client_id) {
        $s = $pdo->prepare("SELECT name FROM clients WHERE id = ? AND company_id = ? LIMIT 1");
        $s->execute([$client_id, $company_id]);
        $name = $s->fetchColumn() ?: '';
    }
    if (!$name) {
        $s = $pdo->prepare("SELECT company_name FROM companies WHERE id = ? LIMIT 1");
        $s->execute([$company_id]);
        $name = $s->fetchColumn() ?: 'COMP';
    }
    // Extract first letters of each word, take first 4-5 chars
    $words = preg_split('/\s+/', trim($name));
    if (count($words) >= 2) {
        // Multi-word: first 2-3 chars of first word + first 1-2 chars of second word
        $prefix = strtoupper(substr($words[0], 0, min(3, strlen($words[0]))) . substr($words[1], 0, min(2, strlen($words[1]))));
    } else {
        // Single word: first 4 chars
        $prefix = strtoupper(substr($name, 0, min(4, strlen($name))));
    }
    $prefix = preg_replace('/[^A-Z]/', '', $prefix);
    return $prefix ?: 'CO';
}

// Block write actions for viewer role — only get_items/get_verification_items/search_items are read-only
if (!in_array($action, ['get_items', 'get_verification_items', 'search_items'])) {
    require_non_viewer();
}

// Auto-migrate new columns for item-level approval (each in its own try-catch so one failure doesn't skip the rest)
try { $pdo->exec("ALTER TABLE requisition_items ADD COLUMN initial_unit_price DECIMAL(15,2) DEFAULT 0.00 AFTER unit_price"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE requisition_items ADD COLUMN initial_quantity DECIMAL(10,2) DEFAULT 0.00 AFTER quantity"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE requisition_items ADD COLUMN status ENUM('active','rejected') DEFAULT 'active' AFTER total_price"); } catch(Exception $e) {}
try { $pdo->exec("UPDATE requisition_items SET initial_unit_price = unit_price WHERE initial_unit_price = 0.00 OR initial_unit_price IS NULL"); } catch(Exception $e) {}
try { $pdo->exec("UPDATE requisition_items SET initial_quantity = quantity WHERE initial_quantity = 0.00 OR initial_quantity IS NULL"); } catch(Exception $e) {}

// Ensure purchase_orders table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NULL,
        requisition_id INT NOT NULL,
        po_number VARCHAR(50) NOT NULL,
        total_amount DECIMAL(15,2) DEFAULT 0.00,
        status ENUM('issued','delivered','cancelled') DEFAULT 'issued',
        notes TEXT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company (company_id),
        INDEX idx_req (requisition_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}
// Ensure rejection_reason column exists on requisitions
try { $pdo->exec("ALTER TABLE requisitions ADD COLUMN rejection_reason TEXT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE requisitions ADD COLUMN approved_by INT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE requisitions ADD COLUMN approved_at DATETIME NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE requisitions ADD COLUMN updated_at DATETIME NULL"); } catch(Exception $e) {}
// Ensure client_id column exists in purchase_orders (may have been created without it)
try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN client_id INT NULL AFTER company_id"); } catch(Exception $e) {}

try {
    switch ($action) {

        case 'create':
            $department = clean_input($_POST['department'] ?? '');
            $purpose    = clean_input($_POST['purpose'] ?? '');
            $priority   = clean_input($_POST['priority'] ?? 'medium');
            $items_json = $_POST['items'] ?? '[]';
            $items      = json_decode($items_json, true) ?: [];

            if (!$department || !$purpose) { echo json_encode(['success' => false, 'message' => 'Department and Purpose required']); break; }
            if (empty($items)) { echo json_encode(['success' => false, 'message' => 'At least one item required']); break; }

            $total = 0;
            foreach ($items as $item) {
                $total += (floatval($item['quantity'] ?? 0) * floatval($item['unit_price'] ?? 0));
            }

            // Generate requisition number — use MAX across entire company to avoid cross-client duplicates
            $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(requisition_number, 5) AS UNSIGNED)) as max_num FROM requisitions WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $num = ($stmt->fetch()['max_num'] ?? 0) + 1;
            $req_number = 'REQ-' . str_pad($num, 5, '0', STR_PAD_LEFT);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO requisitions (company_id, client_id, requisition_number, department, purpose, priority, total_amount, status, requested_by) VALUES (?,?,?,?,?,?,?,'submitted',?)");
            $stmt->execute([$company_id, $client_id, $req_number, $department, $purpose, $priority, $total, $user_id]);
            $req_id = $pdo->lastInsertId();

            // Save line items - initial_unit_price = SOT catalogue cost (products.unit_cost), not the submitted price
            $stmt_cat = $pdo->prepare("SELECT unit_cost FROM products WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt_ins = $pdo->prepare("INSERT INTO requisition_items (requisition_id, product_id, description, quantity, initial_quantity, unit_price, initial_unit_price, total_price, status) VALUES (?,?,?,?,?,?,?,?,'active')");
            foreach ($items as $item) {
                $qty         = floatval($item['quantity'] ?? 0);
                $price       = floatval($item['unit_price'] ?? 0);
                $product_id  = (!empty($item['product_id']) && intval($item['product_id']) > 0) ? intval($item['product_id']) : null;
                $description = clean_input($item['description'] ?? '');
                // Get official catalogue cost as reference price
                $catalogue_cost = $price; // fallback for custom/unlisted items
                if ($product_id) {
                    $stmt_cat->execute([$product_id, $company_id]);
                    $cat = $stmt_cat->fetch(PDO::FETCH_ASSOC);
                    if ($cat && floatval($cat['unit_cost']) > 0) $catalogue_cost = floatval($cat['unit_cost']);
                }
                $stmt_ins->execute([$req_id, $product_id, $description, $qty, $qty, $price, $catalogue_cost, $qty * $price]);
            }

            $pdo->commit();
            log_audit($company_id, $user_id, 'create_requisition', 'requisitions', $req_id, "Created $req_number - ₦" . number_format($total, 2));

            // Notify approvers
            $requester_name = ($_SESSION['user_name'] ?? 'A team member');
            notify_approvers($company_id, '📝 New Requisition', "$requester_name submitted $req_number (₦" . number_format($total, 2) . ") for approval.", 'info', 'requisitions.php', $user_id);

            echo json_encode(['success' => true, 'requisition_number' => $req_number]);
            break;

        case 'update':
            $req_id     = intval($_POST['requisition_id'] ?? 0);
            $department = clean_input($_POST['department'] ?? '');
            $purpose    = clean_input($_POST['purpose'] ?? '');
            $priority   = clean_input($_POST['priority'] ?? 'medium');
            $items_json = $_POST['items'] ?? '[]';
            $items      = json_decode($items_json, true) ?: [];

            if (!$req_id) { echo json_encode(['success' => false, 'message' => 'Invalid requisition']); break; }
            if (!$department || !$purpose) { echo json_encode(['success' => false, 'message' => 'Department and Purpose required']); break; }
            if (empty($items)) { echo json_encode(['success' => false, 'message' => 'At least one item required']); break; }

            // Only allow editing own submitted requisitions
            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ? AND client_id = ? AND requested_by = ? AND status = 'submitted'");
            $stmt->execute([$req_id, $company_id, $client_id, $user_id]);
            $req = $stmt->fetch();
            if (!$req) { echo json_encode(['success' => false, 'message' => 'Cannot edit this requisition — only your own submitted requisitions can be edited']); break; }

            $total = 0;
            foreach ($items as $item) {
                $total += (floatval($item['quantity'] ?? 0) * floatval($item['unit_price'] ?? 0));
            }

            $pdo->beginTransaction();

            // Update requisition header
            $stmt = $pdo->prepare("UPDATE requisitions SET department = ?, purpose = ?, priority = ?, total_amount = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$department, $purpose, $priority, $total, $req_id]);

            // Delete old items and re-insert
            $pdo->prepare("DELETE FROM requisition_items WHERE requisition_id = ?")->execute([$req_id]);

            $stmt_cat = $pdo->prepare("SELECT unit_cost FROM products WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt_ins = $pdo->prepare("INSERT INTO requisition_items (requisition_id, product_id, description, quantity, initial_quantity, unit_price, initial_unit_price, total_price, status) VALUES (?,?,?,?,?,?,?,?,'active')");
            foreach ($items as $item) {
                $qty         = floatval($item['quantity'] ?? 0);
                $price       = floatval($item['unit_price'] ?? 0);
                $product_id  = (!empty($item['product_id']) && intval($item['product_id']) > 0) ? intval($item['product_id']) : null;
                $description = clean_input($item['description'] ?? '');
                $catalogue_cost = $price;
                if ($product_id) {
                    $stmt_cat->execute([$product_id, $company_id]);
                    $cat = $stmt_cat->fetch(PDO::FETCH_ASSOC);
                    if ($cat && floatval($cat['unit_cost']) > 0) $catalogue_cost = floatval($cat['unit_cost']);
                }
                $stmt_ins->execute([$req_id, $product_id, $description, $qty, $qty, $price, $catalogue_cost, $qty * $price]);
            }

            $pdo->commit();
            log_audit($company_id, $user_id, 'update_requisition', 'requisitions', $req_id, "Edited {$req['requisition_number']} — new total ₦" . number_format($total, 2));
            echo json_encode(['success' => true, 'requisition_number' => $req['requisition_number']]);
            break;

        case 'get_items':
            $req_id = intval($_POST['requisition_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT ri.*, p.name as product_name, p.sku as product_sku 
                FROM requisition_items ri 
                LEFT JOIN products p ON ri.product_id = p.id 
                WHERE ri.requisition_id = ?
                ORDER BY ri.id");
            $stmt->execute([$req_id]);
            $items = $stmt->fetchAll();
            echo json_encode(['success' => true, 'items' => $items]);
            break;

        case 'approve':
            $req_id = intval($_POST['requisition_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$req_id, $company_id, $client_id]);
            $req = $stmt->fetch();

            if (!$req) { echo json_encode(['success' => false, 'message' => 'Requisition not found']); break; }

            // Determine next status based on current status and user role
            // business_owner / super_admin ALWAYS fast-track directly to ceo_approved (final stage)
            $new_status = null;
            if (in_array($user_role, ['business_owner','super_admin'])) {
                if (in_array($req['status'], ['submitted','hod_approved','audit_approved'])) {
                    $new_status = 'ceo_approved'; // one-click final approval
                }
            } elseif ($req['status'] === 'submitted' && in_array($user_role, ['department_head','hod'])) {
                $new_status = 'hod_approved';
            } elseif ($req['status'] === 'hod_approved' && in_array($user_role, ['auditor'])) {
                $new_status = 'audit_approved';
            } elseif ($req['status'] === 'audit_approved' && in_array($user_role, ['ceo'])) {
                $new_status = 'ceo_approved';
            }

            if (!$new_status) { echo json_encode(['success' => false, 'message' => 'Not authorized to approve at this stage']); break; }

            $pdo->beginTransaction();

            // Process optional item adjustments (price changes, qty changes, status toggle)
            $adjustments_json = $_POST['items'] ?? '';
            if ($adjustments_json) {
                $adjustments = json_decode($adjustments_json, true) ?: [];
                $upd_item = $pdo->prepare("UPDATE requisition_items SET quantity = ?, unit_price = ?, total_price = ? * ?, status = ? WHERE id = ? AND requisition_id = ?");
                foreach ($adjustments as $adj) {
                    $item_id = intval($adj['id']);
                    $new_qty = floatval($adj['quantity'] ?? 0);
                    $new_price = floatval($adj['unit_price'] ?? 0);
                    $item_status = in_array($adj['status'] ?? '', ['active','rejected']) ? $adj['status'] : 'active';
                    $upd_item->execute([$new_qty, $new_price, $new_qty, $new_price, $item_status, $item_id, $req_id]);
                }
                
                // Recalculate total_amount for the requisition based only on active items
                $stmt_calc = $pdo->prepare("SELECT COALESCE(SUM(total_price), 0) FROM requisition_items WHERE requisition_id = ? AND status = 'active'");
                $stmt_calc->execute([$req_id]);
                $new_total = $stmt_calc->fetchColumn();
                
                $pdo->prepare("UPDATE requisitions SET total_amount = ? WHERE id = ?")->execute([$new_total, $req_id]);
                
                // Also update local copy of $req for PO creation
                $req['total_amount'] = $new_total;
            }

            $stmt = $pdo->prepare("UPDATE requisitions SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $user_id, $req_id]);
            log_audit($company_id, $user_id, 'approve_requisition', 'requisitions', $req_id, $req['requisition_number'] . " → $new_status");

            // ── Auto-create Purchase Order when CEO/MD approves ──
            $po_number = null;
            $po_error = null;
            if ($new_status === 'ceo_approved') {
                try {
                    $po_prefix = generate_po_prefix($client_id, $company_id, $pdo);
                    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(po_number, '-', -1) AS UNSIGNED)) as max_num FROM purchase_orders WHERE company_id = ? AND client_id = ?");
                    $stmt->execute([$company_id, $client_id]);
                    $num = ($stmt->fetch()['max_num'] ?? 0) + 1;
                    $po_number = $po_prefix . '/PO-' . str_pad($num, 5, '0', STR_PAD_LEFT);

                    // Try with client_id first, fall back without it
                    try {
                        $stmt = $pdo->prepare("INSERT INTO purchase_orders (company_id, client_id, requisition_id, po_number, total_amount, status, created_by) VALUES (?,?,?,?,?,'issued',?)");
                        $stmt->execute([$company_id, $client_id, $req_id, $po_number, $req['total_amount'], $user_id]);
                    } catch (Exception $col_err) {
                        $stmt = $pdo->prepare("INSERT INTO purchase_orders (company_id, requisition_id, po_number, total_amount, status, created_by) VALUES (?,?,?,?,'issued',?)");
                        $stmt->execute([$company_id, $req_id, $po_number, $req['total_amount'], $user_id]);
                    }

                    $pdo->prepare("UPDATE requisitions SET status = 'po_created' WHERE id = ?")->execute([$req_id]);
                    log_audit($company_id, $user_id, 'auto_create_po', 'requisitions', $req_id, "Auto-converted " . $req['requisition_number'] . " to $po_number");
                    $new_status = 'po_created';
                } catch (Exception $po_err) {
                    $po_error = $po_err->getMessage();
                    error_log('PO creation failed: ' . $po_error);
                }
            }

            $pdo->commit();

            // Notify the requester about approval
            $approver_name = ($_SESSION['user_name'] ?? 'An approver');
            app_notify($company_id, $req['requested_by'], '✅ Requisition Approved', "$approver_name approved your requisition {$req['requisition_number']} (→ $new_status)." . ($po_number ? " Purchase Order $po_number created." : ''), 'success', 'requisitions.php');

            echo json_encode(['success' => true, 'new_status' => $new_status, 'po_number' => $po_number, 'po_error' => $po_error]);
            break;

        case 'reject':
            $req_id = intval($_POST['requisition_id'] ?? 0);
            $reason = clean_input($_POST['reason'] ?? 'No reason given');

            $stmt = $pdo->prepare("UPDATE requisitions SET status = 'rejected', rejection_reason = ?, approved_by = ? WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$reason, $user_id, $req_id, $company_id, $client_id]);

            // Fetch requester to notify
            $rStmt = $pdo->prepare("SELECT requested_by, requisition_number FROM requisitions WHERE id = ?");
            $rStmt->execute([$req_id]);
            $rData = $rStmt->fetch();
            if ($rData) {
                $rejector_name = ($_SESSION['user_name'] ?? 'An approver');
                app_notify($company_id, $rData['requested_by'], '❌ Requisition Rejected', "$rejector_name rejected your requisition {$rData['requisition_number']}. Reason: $reason", 'alert', 'requisitions.php');
            }

            log_audit($company_id, $user_id, 'reject_requisition', 'requisitions', $req_id, "Rejected: $reason");
            echo json_encode(['success' => true]);
            break;

        case 'update_purchase_prices':
            // Only allowed on requisitions that have a PO created
            $req_id = intval($_POST['requisition_id'] ?? 0);
            $prices_json = $_POST['prices'] ?? '[]';
            $prices = json_decode($prices_json, true) ?: [];

            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ? AND client_id = ? AND status = 'po_created'");
            $stmt->execute([$req_id, $company_id, $client_id]);
            $req = $stmt->fetch();

            if (!$req) { echo json_encode(['success' => false, 'message' => 'Requisition not found or PO not yet created']); break; }
            if (empty($prices)) { echo json_encode(['success' => false, 'message' => 'No prices provided']); break; }

            $pdo->beginTransaction();
            $update_stmt = $pdo->prepare("UPDATE requisition_items SET actual_unit_price = ? WHERE id = ? AND requisition_id = ?");
            $new_total = 0;
            foreach ($prices as $p) {
                $item_id = intval($p['item_id'] ?? 0);
                $actual  = floatval($p['actual_unit_price'] ?? 0);
                $update_stmt->execute([$actual, $item_id, $req_id]);
                // Get qty for this item to calculate total
                $qty_stmt = $pdo->prepare("SELECT quantity FROM requisition_items WHERE id = ?");
                $qty_stmt->execute([$item_id]);
                $row = $qty_stmt->fetch();
                if ($row) $new_total += ($actual * floatval($row['quantity']));
            }

            // Update requisition total with actual prices
            $stmt = $pdo->prepare("UPDATE requisitions SET total_amount = ? WHERE id = ?");
            $stmt->execute([$new_total, $req_id]);

            $pdo->commit();
            log_audit($company_id, $user_id, 'update_purchase_prices', 'requisitions', $req_id, "Updated actual prices, new total: ₦" . number_format($new_total, 2));
            echo json_encode(['success' => true, 'new_total' => $new_total]);
            break;

        case 'convert_to_po':
            $req_id = intval($_POST['requisition_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ? AND client_id = ? AND status = 'ceo_approved'");
            $stmt->execute([$req_id, $company_id, $client_id]);
            $req = $stmt->fetch();

            if (!$req) { echo json_encode(['success' => false, 'message' => 'Requisition not found or not CEO-approved']); break; }

            // Generate PO number with company prefix
            $po_prefix = generate_po_prefix($client_id, $company_id, $pdo);
            $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(po_number, '-', -1) AS UNSIGNED)) as max_num FROM purchase_orders WHERE company_id = ? AND client_id = ?");
            $stmt->execute([$company_id, $client_id]);
            $num = ($stmt->fetch()['max_num'] ?? 0) + 1;
            $po_number = $po_prefix . '/PO-' . str_pad($num, 5, '0', STR_PAD_LEFT);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO purchase_orders (company_id, requisition_id, po_number, total_amount, status, created_by) VALUES (?,?,?,?,'issued',?)");
            $stmt->execute([$company_id, $req_id, $po_number, $req['total_amount'], $user_id]);

            $stmt = $pdo->prepare("UPDATE requisitions SET status = 'po_created' WHERE id = ?");
            $stmt->execute([$req_id]);

            $pdo->commit();
            log_audit($company_id, $user_id, 'create_po', 'requisitions', $req_id, "Converted to $po_number");
            echo json_encode(['success' => true, 'po_number' => $po_number]);
            break;

        case 'delete':
            $req_id = intval($_POST['requisition_id'] ?? 0);
            // Only allow delete of own requisitions that are still in submitted status
            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ? AND client_id = ? AND requested_by = ? AND status = 'submitted'");
            $stmt->execute([$req_id, $company_id, $client_id, $user_id]);
            $req = $stmt->fetch();

            // Admin can delete any
            if (!$req && in_array($user_role, ['business_owner','super_admin'])) {
                $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ? AND client_id = ? AND status IN ('submitted','rejected')");
                $stmt->execute([$req_id, $company_id, $client_id]);
                $req = $stmt->fetch();
            }

            if (!$req) { echo json_encode(['success' => false, 'message' => 'Cannot delete this requisition']); break; }

            $stmt = $pdo->prepare("UPDATE requisitions SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$req_id]);
            log_audit($company_id, $user_id, 'delete_requisition', 'requisitions', $req_id, "Deleted " . $req['requisition_number']);
            echo json_encode(['success' => true]);
            break;

        case 'search_items':
            $term = trim($_POST['term'] ?? '');
            if (strlen($term) < 1) { echo json_encode(['success' => true, 'items' => []]); break; }
            $like = '%' . $term . '%';
            $results = [];

            // 1. Stock Products (client-scoped)
            $stmt = $pdo->prepare("SELECT id, name, sku, category, unit, unit_cost, selling_price FROM products WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL AND (name LIKE ? OR sku LIKE ? OR category LIKE ?) ORDER BY name LIMIT 15");
            $stmt->execute([$company_id, $client_id, $like, $like, $like]);
            foreach ($stmt->fetchAll() as $p) {
                $results[] = [
                    'ref_id' => 'prod_' . $p['id'],
                    'product_id' => $p['id'],
                    'name' => $p['name'],
                    'source' => 'product',
                    'source_label' => 'Stock',
                    'category' => $p['category'],
                    'unit' => $p['unit'],
                    'sku' => $p['sku'],
                    'current_price' => floatval($p['unit_cost']),
                    'selling_price' => floatval($p['selling_price']),
                ];
            }

            // 2. Station Lube Products (company-scoped)
            try {
                $stmt = $pdo->prepare("SELECT id, product_name, unit, cost_price, selling_price FROM station_lube_products WHERE company_id = ? AND is_active = 1 AND product_name LIKE ? ORDER BY product_name LIMIT 10");
                $stmt->execute([$company_id, $like]);
                foreach ($stmt->fetchAll() as $lp) {
                    $results[] = [
                        'ref_id' => 'lube_' . $lp['id'],
                        'product_id' => null,
                        'name' => $lp['product_name'],
                        'source' => 'station_lube',
                        'source_label' => 'Station',
                        'category' => 'Lubricant',
                        'unit' => $lp['unit'],
                        'sku' => null,
                        'current_price' => floatval($lp['cost_price']),
                        'selling_price' => floatval($lp['selling_price']),
                    ];
                }
            } catch (Exception $e) { /* table may not exist */ }

            // 3. Expense Categories (company-scoped, for service/non-stock requisitions)
            try {
                $stmt = $pdo->prepare("SELECT id, name, type FROM expense_categories WHERE company_id = ? AND name LIKE ? ORDER BY name LIMIT 10");
                $stmt->execute([$company_id, $like]);
                foreach ($stmt->fetchAll() as $ec) {
                    $results[] = [
                        'ref_id' => 'exp_' . $ec['id'],
                        'product_id' => null,
                        'name' => $ec['name'],
                        'source' => 'expense',
                        'source_label' => 'Expense',
                        'category' => ucfirst(str_replace('_', ' ', $ec['type'])),
                        'unit' => 'service',
                        'sku' => null,
                        'current_price' => 0,
                        'selling_price' => 0,
                    ];
                }
            } catch (Exception $e) { /* table or column may not exist */ }

            // 4. P&L Stock Catalog (client-scoped closing stock items)
            try {
                $stmt = $pdo->prepare("SELECT id, item_name, unit_cost, department, category 
                    FROM pnl_stock_catalog 
                    WHERE company_id = ? AND client_id = ? AND active = 1 AND item_name LIKE ? 
                    ORDER BY item_name LIMIT 10");
                $stmt->execute([$company_id, $client_id, $like]);
                foreach ($stmt->fetchAll() as $sc) {
                    $results[] = [
                        'ref_id'        => 'pnl_' . $sc['id'],
                        'product_id'    => null,
                        'name'          => $sc['item_name'],
                        'source'        => 'pnl_catalog',
                        'source_label'  => 'P&L',
                        'category'      => $sc['category'] ?: $sc['department'],
                        'unit'          => 'pcs',
                        'sku'           => null,
                        'current_price' => floatval($sc['unit_cost']),
                        'selling_price' => 0,
                    ];
                }
            } catch (Exception $e) { /* table may not exist */ }

            // Lookup old prices from previous requisitions for each result
            foreach ($results as &$item) {
                $old_price = null;
                if ($item['product_id']) {
                    $op = $pdo->prepare("SELECT ri.unit_price FROM requisition_items ri JOIN requisitions r ON ri.requisition_id = r.id WHERE r.company_id = ? AND ri.product_id = ? ORDER BY ri.id DESC LIMIT 1");
                    $op->execute([$company_id, $item['product_id']]);
                    $row = $op->fetch();
                    if ($row) $old_price = floatval($row['unit_price']);
                } else {
                    $op = $pdo->prepare("SELECT ri.unit_price FROM requisition_items ri JOIN requisitions r ON ri.requisition_id = r.id WHERE r.company_id = ? AND ri.description = ? AND ri.product_id IS NULL ORDER BY ri.id DESC LIMIT 1");
                    $op->execute([$company_id, $item['name']]);
                    $row = $op->fetch();
                    if ($row) $old_price = floatval($row['unit_price']);
                }
                $item['old_price'] = $old_price;
            }
            unset($item);

            echo json_encode(['success' => true, 'items' => $results]);
            break;

        case 'get_verification_items':
            $req_id = intval($_POST['requisition_id'] ?? 0);
            // Auto-migrate: add verified_by, verified_at, and received columns if missing
            try {
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN verified_by INT NULL DEFAULT NULL AFTER actual_unit_price");
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN verified_at DATETIME NULL DEFAULT NULL AFTER verified_by");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN received_qty DECIMAL(10,2) NULL DEFAULT NULL AFTER verified_at");
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN received_unit_price DECIMAL(15,2) NULL DEFAULT NULL AFTER received_qty");
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN received_total DECIMAL(15,2) NULL DEFAULT NULL AFTER received_unit_price");
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN verification_note TEXT NULL AFTER received_total");
            } catch (Exception $e) {}

            $stmt = $pdo->prepare("SELECT ri.*, p.name as product_name, p.sku as product_sku,
                vu.first_name as verifier_first, vu.last_name as verifier_last
                FROM requisition_items ri
                LEFT JOIN products p ON ri.product_id = p.id
                LEFT JOIN users vu ON ri.verified_by = vu.id
                WHERE ri.requisition_id = ?
                ORDER BY ri.id");
            $stmt->execute([$req_id]);
            $items = $stmt->fetchAll();
            foreach ($items as &$item) {
                $item['verifier_name'] = $item['verifier_first'] ? ($item['verifier_first'] . ' ' . $item['verifier_last']) : null;
            }
            unset($item);
            echo json_encode(['success' => true, 'items' => $items]);
            break;

        case 'verify_delivery':
            $req_id = intval($_POST['requisition_id'] ?? 0);
            $item_data = json_decode($_POST['items_data'] ?? '[]', true) ?: [];
            $route_to = $_POST['route_to'] ?? 'none';

            if (empty($item_data)) { echo json_encode(['success' => false, 'message' => 'No items to verify']); break; }

            // Auto-migrate columns
            try {
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN verified_by INT NULL DEFAULT NULL AFTER actual_unit_price");
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN verified_at DATETIME NULL DEFAULT NULL AFTER verified_by");
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN received_qty DECIMAL(10,2) NULL DEFAULT NULL AFTER verified_at");
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN received_unit_price DECIMAL(15,2) NULL DEFAULT NULL AFTER received_qty");
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN received_total DECIMAL(15,2) NULL DEFAULT NULL AFTER received_unit_price");
                $pdo->exec("ALTER TABLE requisition_items ADD COLUMN verification_note TEXT NULL AFTER received_total");
            } catch (Exception $e) {}

            $stmt_upd = $pdo->prepare("UPDATE requisition_items SET verified_by = ?, verified_at = NOW(), received_qty = ?, received_unit_price = ?, received_total = ?, verification_note = ? WHERE id = ? AND requisition_id = ?");
            $verified_count = 0;
            $routed_items = [];

            // Get PO number for notes
            $po_stmt = $pdo->prepare("SELECT po_number FROM purchase_orders WHERE requisition_id = ? AND company_id = ? LIMIT 1");
            $po_stmt->execute([$req_id, $company_id]);
            $po_number = $po_stmt->fetchColumn() ?: 'REQ-' . $req_id;

            foreach ($item_data as $idata) {
                $item_id = intval($idata['id'] ?? 0);
                $rcv_qty = floatval($idata['received_qty'] ?? 0);
                $rcv_price = floatval($idata['received_unit_price'] ?? 0);
                $rcv_total = $rcv_qty * $rcv_price;
                $note = clean_input($idata['verification_note'] ?? '');

                $stmt_upd->execute([$user_id, $rcv_qty, $rcv_price, $rcv_total, $note, $item_id, $req_id]);
                if ($stmt_upd->rowCount() > 0) {
                    $verified_count++;
                    if ($route_to !== 'none') {
                        $itm_stmt = $pdo->prepare("SELECT product_name, description, quantity, actual_unit_price, unit_price FROM requisition_items WHERE id = ?");
                        $itm_stmt->execute([$item_id]);
                        if ($itm = $itm_stmt->fetch(PDO::FETCH_ASSOC)) {
                            // Use received values for routing
                            $itm['quantity'] = $rcv_qty;
                            $itm['actual_unit_price'] = $rcv_price;
                            $routed_items[] = $itm;
                        }
                    }
                }
            }

            // Route items if requested and any were verified
            if ($verified_count > 0 && count($routed_items) > 0) {
                if ($route_to === 'main_store') {
                    try { 
                        $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_deliveries (
                            id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, client_id INT NOT NULL, product_id INT NOT NULL, 
                            supplier_name VARCHAR(255) DEFAULT '', quantity DECIMAL(10,2) DEFAULT 0, unit_cost DECIMAL(15,2) DEFAULT 0.00, 
                            total_cost DECIMAL(15,2) DEFAULT 0.00, invoice_number VARCHAR(100) DEFAULT '', delivery_date DATE NOT NULL, 
                            received_by INT DEFAULT NULL, deleted_at TIMESTAMP NULL DEFAULT NULL
                        ) ENGINE=InnoDB"); 
                    } catch (Exception $e) {}

                    $check_prod = $pdo->prepare("SELECT id FROM products WHERE name = ? AND company_id = ? AND client_id = ? LIMIT 1");
                    $ins_prod = $pdo->prepare("INSERT INTO products (company_id, client_id, name, category, unit, unit_cost, current_stock, opening_stock) VALUES (?, ?, ?, 'Requisition Intake', 'pcs', ?, 0, 0)");
                    $upd_prod = $pdo->prepare("UPDATE products SET current_stock = current_stock + ?, unit_cost = ? WHERE id = ?");
                    $ins_deliv = $pdo->prepare("INSERT INTO supplier_deliveries (company_id, client_id, product_id, supplier_name, quantity, unit_cost, total_cost, invoice_number, delivery_date, received_by) VALUES (?,?,?,?,?,?,?,?,CURDATE(),?)");

                    foreach ($routed_items as $itm) {
                        $name = $itm['product_name'] ?: $itm['description'];
                        if (!$name) continue;
                        $qty = floatval($itm['quantity']);
                        $cost = floatval($itm['actual_unit_price'] ?: $itm['unit_price']);

                        $check_prod->execute([$name, $company_id, $client_id]);
                        $pid = $check_prod->fetchColumn();
                        if (!$pid) {
                            $ins_prod->execute([$company_id, $client_id, $name, $cost]);
                            $pid = $pdo->lastInsertId();
                        }
                        $upd_prod->execute([$qty, $cost, $pid]);
                        $ins_deliv->execute([$company_id, $client_id, $pid, "Internal PO $po_number", $qty, $cost, ($qty * $cost), $po_number, $user_id]);
                    }
                } 
                elseif ($route_to === 'pnl') {
                    $q_rpt = $pdo->prepare("SELECT id FROM pnl_reports WHERE company_id = ? AND client_id = ? AND status = 'draft' ORDER BY created_at DESC LIMIT 1");
                    $q_rpt->execute([$company_id, $client_id]);
                    $rpt_id = $q_rpt->fetchColumn();

                    if ($rpt_id) {
                        $q_per = $pdo->prepare("SELECT id FROM pnl_periods WHERE report_id = ? ORDER BY date_from DESC LIMIT 1");
                        $q_per->execute([$rpt_id]);
                        $per_id = $q_per->fetchColumn();

                        if ($per_id) {
                            $sub_entries = [];
                            $total_amt = 0;
                            foreach ($routed_items as $itm) {
                                $name = $itm['product_name'] ?: $itm['description'];
                                $qty = floatval($itm['quantity']);
                                $cost = floatval($itm['actual_unit_price'] ?: $itm['unit_price']);
                                $line_total = $qty * $cost;
                                $sub_entries[] = ['note' => "$name (Qty: $qty)", 'amount' => $line_total];
                                $total_amt += $line_total;
                            }
                            
                            $ins_cos = $pdo->prepare("INSERT INTO pnl_cost_of_sales (report_id, period_id, label, amount, entry_type, sub_entries, sort_order) VALUES (?, ?, ?, ?, 'purchase', ?, COALESCE((SELECT MAX(sort_order)+1 FROM (SELECT * FROM pnl_cost_of_sales WHERE period_id = ?) AS tmp), 0))");
                            $ins_cos->execute([$rpt_id, $per_id, "Req PO Delivery: $po_number", $total_amt, json_encode($sub_entries), $per_id]);
                        }
                    }
                }
            }

            log_audit($company_id, $user_id, 'verify_delivery', 'requisitions', $req_id, "Verified $verified_count item(s) for requisition #$req_id (Routed to: $route_to)");
            echo json_encode(['success' => true, 'verified_count' => $verified_count, 'routed' => $route_to]);
            break;

        case 'get_report_data':
            $date_from = $_POST['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $date_to   = $_POST['date_to'] ?? date('Y-m-d');
            $dept      = $_POST['department'] ?? '';

            $where = "company_id = ? AND client_id = ? AND deleted_at IS NULL AND DATE(created_at) >= ? AND DATE(created_at) <= ?";
            $params = [$company_id, $client_id, $date_from, $date_to];
            if ($dept) { $where .= " AND department = ?"; $params[] = $dept; }

            // Requisitions
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as val FROM requisitions WHERE $where");
            $stmt->execute($params);
            $req_totals = $stmt->fetch(PDO::FETCH_ASSOC);

            // Approved (ceo_approved + po_created)
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as val FROM requisitions WHERE $where AND status IN ('ceo_approved','po_created')");
            $stmt->execute($params);
            $app_totals = $stmt->fetch(PDO::FETCH_ASSOC);

            // POs
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as val FROM purchase_orders WHERE requisition_id IN (SELECT id FROM requisitions WHERE $where)");
            $stmt->execute($params);
            $po_totals = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verified
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(i.quantity * COALESCE(i.actual_unit_price, i.unit_price)), 0) as verified_val FROM requisition_items i JOIN requisitions r ON i.requisition_id = r.id WHERE r.company_id = ? AND r.client_id = ? AND i.verified_at IS NOT NULL AND DATE(r.created_at) >= ? AND DATE(r.created_at) <= ?" . ($dept ? " AND r.department = ?" : ""));
            $v_params = [$company_id, $client_id, $date_from, $date_to];
            if ($dept) $v_params[] = $dept;
            $stmt->execute($v_params);
            $verified_val = $stmt->fetchColumn();

            // Department breakdown (for chart)
            $stmt_dept = $pdo->prepare("SELECT department, COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as val, SUM(CASE WHEN status IN ('ceo_approved','po_created') THEN 1 ELSE 0 END) as approved_cnt FROM requisitions WHERE $where AND department IS NOT NULL AND department != '' GROUP BY department ORDER BY val DESC LIMIT 10");
            $stmt_dept->execute($params);
            $dept_breakdown = $stmt_dept->fetchAll(PDO::FETCH_ASSOC);

            // Status breakdown (for pie chart)
            $stmt_status = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM requisitions WHERE $where GROUP BY status");
            $stmt_status->execute($params);
            $status_breakdown = $stmt_status->fetchAll(PDO::FETCH_ASSOC);

            // Recent requisition breakdown list
            $stmt_list = $pdo->prepare("SELECT r.requisition_number, r.department, r.purpose, r.status, r.total_amount, r.priority, r.created_at, u.first_name, u.last_name FROM requisitions r LEFT JOIN users u ON r.requested_by = u.id WHERE $where ORDER BY r.created_at DESC LIMIT 20");
            $stmt_list->execute($params);
            $req_list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => [
                'total_reqs'      => intval($req_totals['cnt']),
                'total_reqs_val'  => floatval($req_totals['val']),
                'approved_cnt'    => intval($app_totals['cnt']),
                'approved_val'    => floatval($app_totals['val']),
                'po_cnt'          => intval($po_totals['cnt']),
                'po_val'          => floatval($po_totals['val']),
                'verified_val'    => floatval($verified_val),
                'variance_val'    => floatval($app_totals['val']) - floatval($verified_val),
                'dept_breakdown'  => $dept_breakdown,
                'status_breakdown'=> $status_breakdown,
                'req_list'        => $req_list,
            ]]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
