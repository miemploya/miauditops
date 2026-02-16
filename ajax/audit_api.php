<?php
/**
 * MIAUDITOPS — Audit API (AJAX Handler)
 * Handles: save_sales, save_lodgment, confirm_lodgment, sign_off
 */
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
require_non_viewer(); // Viewer role cannot modify audit data
$user_id    = $_SESSION['user_id'];
$action     = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'save_sales':
            $date      = $_POST['date'] ?? date('Y-m-d');
            $date_to   = $_POST['date_to'] ?? '';
            $shift     = $_POST['shift'] ?? 'full_day';
            $outlet_id = intval($_POST['outlet_id'] ?? 0);
            $pos       = floatval($_POST['pos'] ?? 0);
            $cash      = floatval($_POST['cash'] ?? 0);
            $transfer  = floatval($_POST['transfer'] ?? 0);
            $declared  = floatval($_POST['declared'] ?? 0);
            $notes     = clean_input($_POST['notes'] ?? '');
            $actual    = $pos + $cash + $transfer;
            if ($date_to && $date_to !== $date) {
                $notes = "[Range: {$date} to {$date_to}] " . $notes;
            }
            
            if (!$outlet_id) {
                echo json_encode(['success' => false, 'message' => 'Please select an outlet']);
                break;
            }
            
            $stmt = $pdo->prepare("INSERT INTO sales_transactions (company_id, client_id, outlet_id, transaction_date, shift, pos_amount, cash_amount, transfer_amount, other_amount, actual_total, declared_total, notes, entered_by) VALUES (?,?,?,?,?,?,?,0,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $outlet_id, $date, $shift, $pos, $cash, $transfer, $actual, $declared, $notes, $user_id]);
            $txn_id = $pdo->lastInsertId();
            
            // Auto-flag variance
            $variance = $actual - $declared;
            if (abs($variance) > 0.01) {
                $severity = abs($variance) > 50000 ? 'critical' : (abs($variance) > 10000 ? 'major' : (abs($variance) > 1000 ? 'moderate' : 'minor'));
                $stmt = $pdo->prepare("INSERT INTO variance_reports (company_id, client_id, report_date, category, expected_amount, actual_amount, variance_amount, severity, description, reference_type, reference_id) VALUES (?,?,?,?,?,?,?,?,'Auto-detected: sales transaction variance','sales_transaction',?)");
                $stmt->execute([$company_id, $client_id, $date, 'sales', $declared, $actual, $variance, $severity, $txn_id]);
            }
            
            log_audit($company_id, $user_id, 'sales_recorded', 'audit', $txn_id, "Sales ₦" . number_format($actual,2) . " (Var: ₦" . number_format($variance,2) . ")");
            echo json_encode(['success' => true, 'id' => $txn_id]);
            break;
            
        case 'save_lodgment':
            $date      = $_POST['date'] ?? date('Y-m-d');
            $bank      = clean_input($_POST['bank'] ?? '');
            $account   = clean_input($_POST['account'] ?? '');
            $amount    = floatval($_POST['amount'] ?? 0);
            $reference = clean_input($_POST['reference'] ?? '');
            $linked_cash_txn = intval($_POST['linked_cash_txn_id'] ?? 0);
            
            // If linking to a pending cash transaction, auto-fill amount if not set
            $source = 'manual';
            if ($linked_cash_txn) {
                $chk = $pdo->prepare("SELECT cash_amount, transaction_date, outlet_id FROM sales_transactions WHERE id = ? AND company_id = ? AND cash_lodgment_status IN ('pending','deposited') AND deleted_at IS NULL");
                $chk->execute([$linked_cash_txn, $company_id]);
                $cash_txn = $chk->fetch();
                if ($cash_txn) {
                    if ($amount <= 0) $amount = floatval($cash_txn['cash_amount']);
                    $source = 'cash_deposit';
                } else {
                    $linked_cash_txn = 0; // Invalid or already confirmed
                }
            }
            
            $stmt = $pdo->prepare("INSERT INTO bank_lodgments (company_id, client_id, lodgment_date, bank_name, account_number, amount, reference_number, lodged_by, source, source_txn_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $date, $bank, $account, $amount, $reference, $user_id, $source, $linked_cash_txn ?: null]);
            $lid = $pdo->lastInsertId();
            
            // Mark linked cash transaction as 'deposited' (awaiting bank confirmation)
            if ($linked_cash_txn) {
                $stmt = $pdo->prepare("UPDATE sales_transactions SET cash_lodgment_status = 'deposited' WHERE id = ? AND company_id = ?");
                $stmt->execute([$linked_cash_txn, $company_id]);
            }
            
            log_audit($company_id, $user_id, 'lodgment_recorded', 'audit', $lid, "Bank lodgment ₦" . number_format($amount,2) . " to $bank" . ($linked_cash_txn ? " (linked to cash TXN #$linked_cash_txn)" : ''));
            echo json_encode(['success' => true, 'id' => $lid]);
            break;
            
        case 'confirm_lodgment':
            $id = intval($_POST['id'] ?? 0);
            
            // Get lodgment details to check for linked cash transaction
            $stmt = $pdo->prepare("SELECT source_txn_id, source FROM bank_lodgments WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);
            $lodgment = $stmt->fetch();
            
            // Confirm the lodgment
            $stmt = $pdo->prepare("UPDATE bank_lodgments SET status = 'confirmed', confirmed_by = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$user_id, $id, $company_id]);
            
            // If linked to a cash sales transaction, settle it as confirmed
            if ($lodgment && $lodgment['source_txn_id'] && in_array($lodgment['source'], ['cash_deposit', 'manual'])) {
                $stmt = $pdo->prepare("UPDATE sales_transactions SET cash_lodgment_status = 'confirmed' WHERE id = ? AND company_id = ? AND cash_lodgment_status IN ('pending','deposited')");
                $stmt->execute([$lodgment['source_txn_id'], $company_id]);
            }
            
            log_audit($company_id, $user_id, 'lodgment_confirmed', 'audit', $id);
            echo json_encode(['success' => true]);
            break;
            
        case 'sign_off':
            $role    = $_POST['role'] ?? '';
            $comments = clean_input($_POST['comments'] ?? '');
            $today   = date('Y-m-d');
            
            // Check existing
            $stmt = $pdo->prepare("SELECT id FROM daily_audit_signoffs WHERE company_id = ? AND client_id = ? AND audit_date = ?");
            $stmt->execute([$company_id, $client_id, $today]);
            $existing = $stmt->fetch();
            
            if ($role === 'auditor') {
                if ($existing) {
                    $stmt = $pdo->prepare("UPDATE daily_audit_signoffs SET auditor_id = ?, auditor_signed_at = NOW(), auditor_comments = ?, status = 'pending_manager' WHERE id = ?");
                    $stmt->execute([$user_id, $comments, $existing['id']]);
                } else {
                    // Get today's totals
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(actual_total),0) as total FROM sales_transactions WHERE company_id = ? AND client_id = ? AND transaction_date = ? AND deleted_at IS NULL");
                    $stmt->execute([$company_id, $client_id, $today]);
                    $total = $stmt->fetch()['total'];
                    
                    $stmt = $pdo->prepare("INSERT INTO daily_audit_signoffs (company_id, client_id, audit_date, total_revenue, total_variance, auditor_id, auditor_signed_at, auditor_comments, status) VALUES (?,?,?,?,0,?,NOW(),?,'pending_manager')");
                    $stmt->execute([$company_id, $client_id, $today, $total, $user_id, $comments]);
                }
                log_audit($company_id, $user_id, 'audit_signed_auditor', 'audit', null, "Auditor signed off for $today");
            } elseif ($role === 'manager' && $existing) {
                $stmt = $pdo->prepare("UPDATE daily_audit_signoffs SET manager_id = ?, manager_signed_at = NOW(), manager_comments = ?, status = 'completed' WHERE id = ?");
                $stmt->execute([$user_id, $comments, $existing['id']]);
                log_audit($company_id, $user_id, 'audit_signed_manager', 'audit', null, "Manager signed off for $today");
            }
            echo json_encode(['success' => true]);
            break;
            
        case 'approve_sales_payment':
            $txn_id = intval($_POST['txn_id'] ?? 0);
            $type   = $_POST['type'] ?? ''; // pos, transfer, cash
            
            if (!$txn_id || !in_array($type, ['pos', 'transfer', 'cash'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                break;
            }
            
            // Fetch the transaction (company-scoped)
            $stmt = $pdo->prepare("SELECT * FROM sales_transactions WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
            $stmt->execute([$txn_id, $company_id]);
            $txn = $stmt->fetch();
            
            if (!$txn) {
                echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                break;
            }
            
            if ($type === 'pos') {
                if ($txn['pos_approved']) {
                    echo json_encode(['success' => false, 'message' => 'POS already approved']);
                    break;
                }
                $amount = floatval($txn['pos_amount']);
                if ($amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'No POS amount to approve']);
                    break;
                }
                // Mark as approved
                $stmt = $pdo->prepare("UPDATE sales_transactions SET pos_approved = 1, pos_approved_by = ?, pos_approved_at = NOW() WHERE id = ?");
                $stmt->execute([$user_id, $txn_id]);
                // Auto-create bank lodgment
                $ref = 'AUTO-POS-' . $txn_id;
                $stmt = $pdo->prepare("INSERT INTO bank_lodgments (company_id, client_id, lodgment_date, bank_name, account_number, amount, reference_number, status, lodged_by, source, source_txn_id) VALUES (?,?,?,?,?,?,?,'confirmed',?,'auto_pos',?)");
                $stmt->execute([$company_id, $client_id, $txn['transaction_date'], 'POS Terminal', '', $amount, $ref, $user_id, $txn_id]);
                log_audit($company_id, $user_id, 'pos_approved', 'audit', $txn_id, "POS ₦" . number_format($amount, 2) . " approved as lodgment");
                
            } elseif ($type === 'transfer') {
                if ($txn['transfer_approved']) {
                    echo json_encode(['success' => false, 'message' => 'Transfer already approved']);
                    break;
                }
                $amount = floatval($txn['transfer_amount']);
                if ($amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'No Transfer amount to approve']);
                    break;
                }
                // Mark as approved
                $stmt = $pdo->prepare("UPDATE sales_transactions SET transfer_approved = 1, transfer_approved_by = ?, transfer_approved_at = NOW() WHERE id = ?");
                $stmt->execute([$user_id, $txn_id]);
                // Auto-create bank lodgment
                $ref = 'AUTO-TRF-' . $txn_id;
                $stmt = $pdo->prepare("INSERT INTO bank_lodgments (company_id, client_id, lodgment_date, bank_name, account_number, amount, reference_number, status, lodged_by, source, source_txn_id) VALUES (?,?,?,?,?,?,?,'confirmed',?,'auto_transfer',?)");
                $stmt->execute([$company_id, $client_id, $txn['transaction_date'], 'Bank Transfer', '', $amount, $ref, $user_id, $txn_id]);
                log_audit($company_id, $user_id, 'transfer_approved', 'audit', $txn_id, "Transfer ₦" . number_format($amount, 2) . " approved as lodgment");
                
            } elseif ($type === 'cash') {
                // Cash just updates status to pending deposit confirmation
                $stmt = $pdo->prepare("UPDATE sales_transactions SET cash_lodgment_status = 'deposited' WHERE id = ? AND cash_lodgment_status = 'pending'");
                $stmt->execute([$txn_id]);
                log_audit($company_id, $user_id, 'cash_deposit_noted', 'audit', $txn_id, "Cash ₦" . number_format($txn['cash_amount'], 2) . " marked for deposit confirmation");
            }
            
            echo json_encode(['success' => true]);
            break;
            
        case 'update_sales':
            $id        = intval($_POST['id'] ?? 0);
            $date      = $_POST['date'] ?? date('Y-m-d');
            $shift     = $_POST['shift'] ?? 'full_day';
            $outlet_id = intval($_POST['outlet_id'] ?? 0);
            $pos       = floatval($_POST['pos'] ?? 0);
            $cash      = floatval($_POST['cash'] ?? 0);
            $transfer  = floatval($_POST['transfer'] ?? 0);
            $declared  = floatval($_POST['declared'] ?? 0);
            $notes     = clean_input($_POST['notes'] ?? '');
            $actual    = $pos + $cash + $transfer;
            $variance  = $actual - $declared;

            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
                break;
            }

            // Verify ownership
            $chk = $pdo->prepare("SELECT id FROM sales_transactions WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
            $chk->execute([$id, $company_id]);
            if (!$chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                break;
            }

            $stmt = $pdo->prepare("UPDATE sales_transactions SET transaction_date = ?, shift = ?, outlet_id = ?, pos_amount = ?, cash_amount = ?, transfer_amount = ?, actual_total = ?, declared_total = ?, variance = ?, notes = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$date, $shift, $outlet_id, $pos, $cash, $transfer, $actual, $declared, $variance, $notes, $id, $company_id]);

            log_audit($company_id, $user_id, 'sales_updated', 'audit', $id, "Updated sales ₦" . number_format($actual, 2));
            echo json_encode(['success' => true]);
            break;

        case 'delete_sales':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
                break;
            }

            // Verify ownership
            $chk = $pdo->prepare("SELECT id, actual_total FROM sales_transactions WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
            $chk->execute([$id, $company_id]);
            $txn = $chk->fetch();
            if (!$txn) {
                echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                break;
            }

            // Soft delete
            $stmt = $pdo->prepare("UPDATE sales_transactions SET deleted_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);

            log_audit($company_id, $user_id, 'sales_deleted', 'audit', $id, "Deleted sales record ₦" . number_format($txn['actual_total'], 2));
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
