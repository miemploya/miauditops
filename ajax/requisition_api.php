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

// Block write actions for viewer role — only get_items is read-only
if ($action !== 'get_items') {
    require_non_viewer();
}

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

            // Generate requisition number
            $stmt = $pdo->prepare("SELECT COUNT(*)+1 as num FROM requisitions WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $num = $stmt->fetch()['num'];
            $req_number = 'REQ-' . str_pad($num, 5, '0', STR_PAD_LEFT);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO requisitions (company_id, client_id, requisition_number, department, purpose, priority, total_amount, status, requested_by) VALUES (?,?,?,?,?,?,?,'submitted',?)");
            $stmt->execute([$company_id, $client_id, $req_number, $department, $purpose, $priority, $total, $user_id]);
            $req_id = $pdo->lastInsertId();

            // Save line items — support both product_id and free-text description
            $stmt = $pdo->prepare("INSERT INTO requisition_items (requisition_id, product_id, description, quantity, unit_price, total_price) VALUES (?,?,?,?,?,?)");
            foreach ($items as $item) {
                $qty   = floatval($item['quantity'] ?? 0);
                $price = floatval($item['unit_price'] ?? 0);
                $product_id  = !empty($item['product_id']) ? intval($item['product_id']) : null;
                $description = clean_input($item['description'] ?? '');
                $stmt->execute([$req_id, $product_id, $description, $qty, $price, $qty * $price]);
            }

            $pdo->commit();
            log_audit($company_id, $user_id, 'create_requisition', 'requisitions', $req_id, "Created $req_number - ₦" . number_format($total, 2));
            echo json_encode(['success' => true, 'requisition_number' => $req_number]);
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
            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ?");
            $stmt->execute([$req_id, $company_id]);
            $req = $stmt->fetch();

            if (!$req) { echo json_encode(['success' => false, 'message' => 'Requisition not found']); break; }

            // Determine next status based on current status and user role
            $new_status = null;
            if ($req['status'] === 'submitted' && in_array($user_role, ['department_head','hod','business_owner','super_admin'])) {
                $new_status = 'hod_approved';
            } elseif ($req['status'] === 'hod_approved' && in_array($user_role, ['auditor','business_owner','super_admin'])) {
                $new_status = 'audit_approved';
            } elseif ($req['status'] === 'audit_approved' && in_array($user_role, ['ceo','business_owner','super_admin'])) {
                $new_status = 'ceo_approved';
            }

            // Business owner can fast-track any status
            if (!$new_status && in_array($user_role, ['business_owner','super_admin'])) {
                if (in_array($req['status'], ['submitted','hod_approved','audit_approved'])) {
                    $new_status = 'ceo_approved';
                }
            }

            if (!$new_status) { echo json_encode(['success' => false, 'message' => 'Not authorized to approve at this stage']); break; }

            $stmt = $pdo->prepare("UPDATE requisitions SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $user_id, $req_id]);
            log_audit($company_id, $user_id, 'approve_requisition', 'requisitions', $req_id, $req['requisition_number'] . " → $new_status");
            echo json_encode(['success' => true, 'new_status' => $new_status]);
            break;

        case 'reject':
            $req_id = intval($_POST['requisition_id'] ?? 0);
            $reason = clean_input($_POST['reason'] ?? 'No reason given');

            $stmt = $pdo->prepare("UPDATE requisitions SET status = 'rejected', rejection_reason = ?, approved_by = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$reason, $user_id, $req_id, $company_id]);
            log_audit($company_id, $user_id, 'reject_requisition', 'requisitions', $req_id, "Rejected: $reason");
            echo json_encode(['success' => true]);
            break;

        case 'update_purchase_prices':
            // Only allowed on CEO-approved requisitions
            $req_id = intval($_POST['requisition_id'] ?? 0);
            $prices_json = $_POST['prices'] ?? '[]';
            $prices = json_decode($prices_json, true) ?: [];

            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ? AND status = 'ceo_approved'");
            $stmt->execute([$req_id, $company_id]);
            $req = $stmt->fetch();

            if (!$req) { echo json_encode(['success' => false, 'message' => 'Requisition not found or not yet CEO-approved']); break; }
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

            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ? AND status = 'ceo_approved'");
            $stmt->execute([$req_id, $company_id]);
            $req = $stmt->fetch();

            if (!$req) { echo json_encode(['success' => false, 'message' => 'Requisition not found or not CEO-approved']); break; }

            // Generate PO number
            $stmt = $pdo->prepare("SELECT COUNT(*)+1 as num FROM purchase_orders WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $num = $stmt->fetch()['num'];
            $po_number = 'PO-' . str_pad($num, 5, '0', STR_PAD_LEFT);

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
            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ? AND requested_by = ? AND status = 'submitted'");
            $stmt->execute([$req_id, $company_id, $user_id]);
            $req = $stmt->fetch();

            // Admin can delete any
            if (!$req && in_array($user_role, ['business_owner','super_admin'])) {
                $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND company_id = ? AND status IN ('submitted','rejected')");
                $stmt->execute([$req_id, $company_id]);
                $req = $stmt->fetch();
            }

            if (!$req) { echo json_encode(['success' => false, 'message' => 'Cannot delete this requisition']); break; }

            $stmt = $pdo->prepare("UPDATE requisitions SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$req_id]);
            log_audit($company_id, $user_id, 'delete_requisition', 'requisitions', $req_id, "Deleted " . $req['requisition_number']);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
