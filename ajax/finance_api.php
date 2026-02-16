<?php
/**
 * MIAUDITOPS — Finance API (AJAX Handler)
 * Handles: save_expense, get_pnl
 */
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!is_logged_in()) { echo json_encode(['success' => false, 'message' => 'Not authenticated']); exit; }

$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'save_expense':
            require_non_viewer(); // Viewer cannot create expenses
            $category_id = intval($_POST['category_id'] ?? 0);
            $amount      = floatval($_POST['amount'] ?? 0);
            $description = clean_input($_POST['description'] ?? '');
            $date        = $_POST['entry_date'] ?? date('Y-m-d');
            $payment     = clean_input($_POST['payment_method'] ?? 'cash');
            $vendor      = clean_input($_POST['vendor'] ?? '');
            $receipt     = clean_input($_POST['receipt_number'] ?? '');
            
            $stmt = $pdo->prepare("INSERT INTO expense_entries (company_id, client_id, amount, description, entry_date, payment_method, vendor, receipt_number, entered_by, category_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $amount, $description, $date, $payment, $vendor, $receipt, $user_id, $category_id ?: null]);
            $eid = $pdo->lastInsertId();
            
            log_audit($company_id, $user_id, 'expense_recorded', 'finance', $eid, "Expense ₦" . number_format($amount,2) . ": $description");
            echo json_encode(['success' => true, 'id' => $eid]);
            break;
            
        case 'add_category':
            $name = clean_input($_POST['name'] ?? '');
            $type = clean_input($_POST['type'] ?? 'operating');
            if (empty($name)) { echo json_encode(['success' => false, 'message' => 'Category name is required']); break; }
            $valid_types = ['cost_of_sales', 'operating', 'administrative', 'other'];
            if (!in_array($type, $valid_types)) $type = 'other';
            
            // Check for duplicates
            $chk = $pdo->prepare("SELECT id FROM expense_categories WHERE company_id = ? AND name = ? AND deleted_at IS NULL");
            $chk->execute([$company_id, $name]);
            if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Category already exists']); break; }
            
            $stmt = $pdo->prepare("INSERT INTO expense_categories (company_id, name, type, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
            $stmt->execute([$company_id, $name, $type]);
            $cid = $pdo->lastInsertId();
            log_audit($company_id, $user_id, 'category_created', 'finance', $cid, "New expense category: $name ($type)");
            echo json_encode(['success' => true, 'id' => $cid]);
            break;
            
        case 'get_pnl':
            $month = intval($_GET['month'] ?? date('m'));
            $year  = intval($_GET['year'] ?? date('Y'));
            
            // Revenue
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(actual_total), 0) as total FROM sales_transactions WHERE company_id = ? AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ? AND deleted_at IS NULL");
            $stmt->execute([$company_id, $month, $year]);
            $revenue = $stmt->fetch()['total'];
            
            // Expenses by category type
            $stmt = $pdo->prepare("SELECT ec.type, ec.name as category_name, COALESCE(SUM(ee.amount), 0) as total FROM expense_entries ee JOIN expense_categories ec ON ee.category_id = ec.id WHERE ee.company_id = ? AND MONTH(ee.entry_date) = ? AND YEAR(ee.entry_date) = ? AND ee.deleted_at IS NULL GROUP BY ec.type, ec.name ORDER BY ec.type, total DESC");
            $stmt->execute([$company_id, $month, $year]);
            $expenses_by_cat = $stmt->fetchAll();
            
            // Total expenses
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expense_entries WHERE company_id = ? AND MONTH(entry_date) = ? AND YEAR(entry_date) = ? AND deleted_at IS NULL");
            $stmt->execute([$company_id, $month, $year]);
            $total_expenses = $stmt->fetch()['total'];
            
            $net_profit = $revenue - $total_expenses;
            $margin = $revenue > 0 ? ($net_profit / $revenue) * 100 : 0;
            
            echo json_encode([
                'success' => true,
                'revenue' => (float)$revenue,
                'total_expenses' => (float)$total_expenses,
                'net_profit' => (float)$net_profit,
                'margin' => round($margin, 1),
                'expenses_breakdown' => $expenses_by_cat
            ]);
            break;
            
        case 'update_expense':
            $id          = intval($_POST['id'] ?? 0);
            $category_id = intval($_POST['category_id'] ?? 0);
            $amount      = floatval($_POST['amount'] ?? 0);
            $description = clean_input($_POST['description'] ?? '');
            $date        = $_POST['entry_date'] ?? date('Y-m-d');
            $payment     = clean_input($_POST['payment_method'] ?? 'cash');
            $vendor      = clean_input($_POST['vendor'] ?? '');

            if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid expense ID']); break; }

            // Verify ownership
            $chk = $pdo->prepare("SELECT id FROM expense_entries WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
            $chk->execute([$id, $company_id]);
            if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Expense not found']); break; }

            $stmt = $pdo->prepare("UPDATE expense_entries SET category_id = ?, amount = ?, description = ?, entry_date = ?, payment_method = ?, vendor = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$category_id ?: null, $amount, $description, $date, $payment, $vendor, $id, $company_id]);

            log_audit($company_id, $user_id, 'expense_updated', 'finance', $id, "Updated expense ₦" . number_format($amount, 2));
            echo json_encode(['success' => true]);
            break;

        case 'delete_expense':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid expense ID']); break; }

            $chk = $pdo->prepare("SELECT id, amount FROM expense_entries WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
            $chk->execute([$id, $company_id]);
            $exp = $chk->fetch();
            if (!$exp) { echo json_encode(['success' => false, 'message' => 'Expense not found']); break; }

            $stmt = $pdo->prepare("UPDATE expense_entries SET deleted_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);

            log_audit($company_id, $user_id, 'expense_deleted', 'finance', $id, "Deleted expense ₦" . number_format($exp['amount'], 2));
            echo json_encode(['success' => true]);
            break;

        case 'save_opening_stock':
            $opening_value = floatval($_POST['opening_value'] ?? 0);
            $period_start  = clean_input($_POST['period_start'] ?? '');
            if (empty($period_start)) { echo json_encode(['success' => false, 'message' => 'Period start date is required']); break; }

            // Create table if not exists
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS client_opening_stock (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    client_id INT NOT NULL,
                    period_start DATE NOT NULL,
                    opening_value DECIMAL(15,2) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_client_period (company_id, client_id, period_start)
                ) ENGINE=InnoDB");
            } catch (Exception $ignore) {}

            $stmt = $pdo->prepare("INSERT INTO client_opening_stock (company_id, client_id, period_start, opening_value) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE opening_value = VALUES(opening_value)");
            $stmt->execute([$company_id, $client_id, $period_start, $opening_value]);

            log_audit($company_id, $user_id, 'opening_stock_set', 'finance', $client_id, "Manual opening stock ₦" . number_format($opening_value, 2) . " for period $period_start");
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
