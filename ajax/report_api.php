<?php
/**
 * MIAUDITOPS — Report API Handler
 * Provides dynamic report data via AJAX.
 * Actions: get_audit_report, get_financial_summary, get_stock_report, 
 *          get_expense_report, get_requisition_report, get_dashboard_kpis
 */
header('Content-Type: application/json');
require_once '../includes/functions.php';
require_login();

$company_id = $_SESSION['company_id'];
$user_id    = $_SESSION['user_id'];
$action     = $_GET['action'] ?? $_POST['action'] ?? '';

$date_from = $_GET['from'] ?? $_POST['from'] ?? date('Y-m-01');
$date_to   = $_GET['to']   ?? $_POST['to']   ?? date('Y-m-d');

try {
    switch ($action) {

        case 'get_audit_report':
            $stmt = $pdo->prepare("SELECT transaction_date, shift, pos_amount, cash_amount, transfer_amount, other_amount, declared_total, actual_total, variance FROM sales_transactions WHERE company_id = ? AND transaction_date BETWEEN ? AND ? AND deleted_at IS NULL ORDER BY transaction_date DESC");
            $stmt->execute([$company_id, $date_from, $date_to]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'get_financial_summary':
            // Revenue
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(actual_total),0) as revenue FROM sales_transactions WHERE company_id = ? AND transaction_date BETWEEN ? AND ? AND deleted_at IS NULL");
            $stmt->execute([$company_id, $date_from, $date_to]);
            $revenue = $stmt->fetch()['revenue'];

            // Expenses by category type
            $stmt = $pdo->prepare("SELECT ec.name, ec.type, COALESCE(SUM(ee.amount),0) as total FROM expense_entries ee JOIN expense_categories ec ON ee.category_id = ec.id WHERE ee.company_id = ? AND ee.entry_date BETWEEN ? AND ? AND ee.deleted_at IS NULL GROUP BY ec.name, ec.type ORDER BY total DESC");
            $stmt->execute([$company_id, $date_from, $date_to]);
            $breakdown = $stmt->fetchAll();

            $total_expenses = array_sum(array_column($breakdown, 'total'));
            $cos  = array_sum(array_column(array_filter($breakdown, fn($e) => $e['type']==='cost_of_sales'), 'total'));
            $opex = array_sum(array_column(array_filter($breakdown, fn($e) => $e['type']==='operating'), 'total'));

            echo json_encode([
                'success'   => true,
                'revenue'   => (float)$revenue,
                'cos'       => (float)$cos,
                'opex'      => (float)$opex,
                'total_expenses' => (float)$total_expenses,
                'gross_profit'   => (float)($revenue - $cos),
                'net_profit'     => (float)($revenue - $total_expenses),
                'breakdown'      => $breakdown
            ]);
            break;

        case 'get_stock_report':
            $stmt = $pdo->prepare("SELECT p.name, p.sku, p.category, p.current_stock, p.unit_cost, p.selling_price, p.reorder_level, (p.current_stock * p.unit_cost) as stock_value FROM products p WHERE p.company_id = ? AND p.deleted_at IS NULL ORDER BY p.name");
            $stmt->execute([$company_id]);
            $products = $stmt->fetchAll();

            $stmt = $pdo->prepare("SELECT sm.type, COUNT(*) as count, SUM(sm.quantity) as total_qty FROM stock_movements sm WHERE sm.company_id = ? AND DATE(sm.created_at) BETWEEN ? AND ? GROUP BY sm.type");
            $stmt->execute([$company_id, $date_from, $date_to]);
            $movements = $stmt->fetchAll();

            echo json_encode(['success' => true, 'products' => $products, 'movements' => $movements, 'total_value' => array_sum(array_column($products, 'stock_value'))]);
            break;

        case 'get_expense_report':
            $stmt = $pdo->prepare("SELECT ee.entry_date, ec.name as category, ee.description, ee.amount, ee.payment_method, ee.vendor as vendor_name, u.first_name, u.last_name FROM expense_entries ee LEFT JOIN expense_categories ec ON ee.category_id = ec.id LEFT JOIN users u ON ee.entered_by = u.id WHERE ee.company_id = ? AND ee.entry_date BETWEEN ? AND ? AND ee.deleted_at IS NULL ORDER BY ee.entry_date DESC");
            $stmt->execute([$company_id, $date_from, $date_to]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'get_requisition_report':
            $stmt = $pdo->prepare("SELECT r.requisition_number, r.department, r.purpose, r.total_amount, r.priority, r.status, r.created_at, u.first_name, u.last_name FROM requisitions r JOIN users u ON r.requested_by = u.id WHERE r.company_id = ? AND DATE(r.created_at) BETWEEN ? AND ? AND r.deleted_at IS NULL ORDER BY r.created_at DESC");
            $stmt->execute([$company_id, $date_from, $date_to]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'get_dashboard_kpis':
            // Revenue
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(actual_total),0) as revenue FROM sales_transactions WHERE company_id = ? AND transaction_date = CURDATE() AND deleted_at IS NULL");
            $stmt->execute([$company_id]);
            $today_revenue = $stmt->fetch()['revenue'];

            // Expenses today
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as expenses FROM expense_entries WHERE company_id = ? AND entry_date = CURDATE() AND deleted_at IS NULL");
            $stmt->execute([$company_id]);
            $today_expenses = $stmt->fetch()['expenses'];

            // Stock value
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(current_stock * unit_cost),0) as value FROM products WHERE company_id = ? AND deleted_at IS NULL");
            $stmt->execute([$company_id]);
            $stock_value = $stmt->fetch()['value'];

            // Pending requisitions
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM requisitions WHERE company_id = ? AND status NOT IN ('ceo_approved','po_created','rejected','cancelled') AND deleted_at IS NULL");
            $stmt->execute([$company_id]);
            $pending_reqs = $stmt->fetch()['cnt'];

            echo json_encode([
                'success' => true,
                'today_revenue'  => (float)$today_revenue,
                'today_expenses' => (float)$today_expenses,
                'today_profit'   => (float)($today_revenue - $today_expenses),
                'stock_value'    => (float)$stock_value,
                'pending_reqs'   => (int)$pending_reqs
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
