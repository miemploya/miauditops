<?php
/**
 * MIAUDITOPS — Cash Management API Handler
 * Actions: post_sale, confirm_sale, reject_sale, create_requisition, approve_requisition,
 *          reject_requisition, save_category, delete_category, get_ledger, get_analysis, get_report
 */
header('Content-Type: application/json');
require_once '../includes/functions.php';
require_login();

$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$user_role  = get_user_role();
$action     = $_POST['action'] ?? '';

// Block write actions for viewer role
if (!in_array($action, ['get_ledger', 'get_analysis', 'get_report', 'get_categories'])) {
    require_non_viewer();
}

// Approver roles
$is_approver = in_array($user_role, ['business_owner', 'super_admin', 'auditor']);

// Auto-create tables if not exist (safe for first-time usage)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `cash_expense_categories` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `company_id` INT(11) NOT NULL,
        `client_id` INT(11) NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
        `deleted_at` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_cat_name` (`company_id`, `client_id`, `name`),
        KEY `idx_company_client` (`company_id`, `client_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `cash_sales` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `company_id` INT(11) NOT NULL,
        `client_id` INT(11) NOT NULL,
        `sale_date` DATE NOT NULL,
        `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `description` VARCHAR(500) DEFAULT NULL,
        `department` VARCHAR(100) DEFAULT NULL,
        `posted_by` INT(11) NOT NULL,
        `posted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
        `confirmed_by` INT(11) DEFAULT NULL,
        `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
        `status` ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
        `rejection_reason` TEXT DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
        `deleted_at` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_company_client_date` (`company_id`, `client_id`, `sale_date`),
        KEY `idx_status` (`company_id`, `client_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `cash_requisitions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `company_id` INT(11) NOT NULL,
        `client_id` INT(11) NOT NULL,
        `requisition_number` VARCHAR(50) NOT NULL,
        `requested_by` INT(11) NOT NULL,
        `category_id` INT(11) DEFAULT NULL,
        `description` VARCHAR(500) NOT NULL,
        `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `type` ENUM('expense','bank_deposit') NOT NULL DEFAULT 'expense',
        `bank_name` VARCHAR(100) DEFAULT NULL,
        `account_number` VARCHAR(50) DEFAULT NULL,
        `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `approved_by` INT(11) DEFAULT NULL,
        `approved_at` TIMESTAMP NULL DEFAULT NULL,
        `rejection_reason` TEXT DEFAULT NULL,
        `month_year` VARCHAR(7) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
        `deleted_at` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_req_number` (`company_id`, `requisition_number`),
        KEY `idx_company_client_status` (`company_id`, `client_id`, `status`),
        KEY `idx_month` (`company_id`, `client_id`, `month_year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `cash_ledger` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `company_id` INT(11) NOT NULL,
        `client_id` INT(11) NOT NULL,
        `entry_date` DATE NOT NULL,
        `reference_type` ENUM('sale','requisition','deposit','adjustment') NOT NULL,
        `reference_id` INT(11) DEFAULT NULL,
        `description` VARCHAR(500) DEFAULT NULL,
        `dr_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `cr_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `posted_by` INT(11) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
        PRIMARY KEY (`id`),
        KEY `idx_company_client_date` (`company_id`, `client_id`, `entry_date`),
        KEY `idx_reference` (`reference_type`, `reference_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) { /* tables already exist */ }

try {
    switch ($action) {

        // ═══════════ CASH SALES ═══════════
        case 'post_sale':
            $sale_date   = clean_input($_POST['sale_date'] ?? date('Y-m-d'));
            $amount      = floatval($_POST['amount'] ?? 0);
            $description = clean_input($_POST['description'] ?? '');
            $department  = clean_input($_POST['department'] ?? '');

            if ($amount <= 0) { echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']); break; }
            if (!$description) { echo json_encode(['success' => false, 'message' => 'Description is required']); break; }

            $stmt = $pdo->prepare("INSERT INTO cash_sales (company_id, client_id, sale_date, amount, description, department, posted_by, status) VALUES (?,?,?,?,?,?,?,'pending')");
            $stmt->execute([$company_id, $client_id, $sale_date, $amount, $description, $department, $user_id]);
            $sale_id = $pdo->lastInsertId();

            log_audit($company_id, $user_id, 'post_cash_sale', 'cash_sales', $sale_id, "Posted ₦" . number_format($amount, 2) . " — $description");
            echo json_encode(['success' => true, 'sale_id' => $sale_id]);
            break;

        case 'confirm_sale':
            if (!$is_approver) { echo json_encode(['success' => false, 'message' => 'Not authorized']); break; }
            $sale_id = intval($_POST['sale_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT * FROM cash_sales WHERE id = ? AND company_id = ? AND client_id = ? AND status = 'pending' AND deleted_at IS NULL");
            $stmt->execute([$sale_id, $company_id, $client_id]);
            $sale = $stmt->fetch();
            if (!$sale) { echo json_encode(['success' => false, 'message' => 'Sale not found or already processed']); break; }

            $pdo->beginTransaction();

            // Update sale status
            $stmt = $pdo->prepare("UPDATE cash_sales SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE id = ?");
            $stmt->execute([$user_id, $sale_id]);

            // Create DR ledger entry (cash received)
            $stmt = $pdo->prepare("INSERT INTO cash_ledger (company_id, client_id, entry_date, reference_type, reference_id, description, dr_amount, cr_amount, posted_by) VALUES (?,?,?,'sale',?,?,?,0,?)");
            $stmt->execute([$company_id, $client_id, $sale['sale_date'], $sale_id, 'Cash Sale: ' . $sale['description'], $sale['amount'], $user_id]);

            $pdo->commit();
            log_audit($company_id, $user_id, 'confirm_cash_sale', 'cash_sales', $sale_id, "Confirmed ₦" . number_format($sale['amount'], 2));
            echo json_encode(['success' => true]);
            break;

        case 'reject_sale':
            if (!$is_approver) { echo json_encode(['success' => false, 'message' => 'Not authorized']); break; }
            $sale_id = intval($_POST['sale_id'] ?? 0);
            $reason  = clean_input($_POST['reason'] ?? 'No reason given');

            $stmt = $pdo->prepare("UPDATE cash_sales SET status = 'rejected', rejection_reason = ?, confirmed_by = ?, confirmed_at = NOW() WHERE id = ? AND company_id = ? AND client_id = ? AND status = 'pending'");
            $stmt->execute([$reason, $user_id, $sale_id, $company_id, $client_id]);

            log_audit($company_id, $user_id, 'reject_cash_sale', 'cash_sales', $sale_id, "Rejected: $reason");
            echo json_encode(['success' => true]);
            break;

        case 'delete_sale':
            $sale_id = intval($_POST['sale_id'] ?? 0);
            // Staff can only delete own pending sales; approvers can delete any pending
            $where = "id = ? AND company_id = ? AND client_id = ? AND status = 'pending' AND deleted_at IS NULL";
            $params = [$sale_id, $company_id, $client_id];
            if (!$is_approver) { $where .= " AND posted_by = ?"; $params[] = $user_id; }

            $stmt = $pdo->prepare("UPDATE cash_sales SET deleted_at = NOW() WHERE $where");
            $stmt->execute($params);

            if ($stmt->rowCount() > 0) {
                log_audit($company_id, $user_id, 'delete_cash_sale', 'cash_sales', $sale_id, "Deleted cash sale #$sale_id");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Cannot delete this sale']);
            }
            break;

        // ═══════════ CASH REQUISITIONS ═══════════
        case 'create_requisition':
            $category_id  = intval($_POST['category_id'] ?? 0) ?: null;
            $description  = clean_input($_POST['description'] ?? '');
            $amount       = floatval($_POST['amount'] ?? 0);
            $type         = in_array($_POST['type'] ?? '', ['expense', 'bank_deposit']) ? $_POST['type'] : 'expense';
            $bank_name    = clean_input($_POST['bank_name'] ?? '');
            $account_no   = clean_input($_POST['account_number'] ?? '');

            if (!$description) { echo json_encode(['success' => false, 'message' => 'Description is required']); break; }
            if ($amount <= 0) { echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']); break; }

            // Generate requisition number
            $stmt = $pdo->prepare("SELECT COUNT(*)+1 as num FROM cash_requisitions WHERE company_id = ? AND client_id = ?");
            $stmt->execute([$company_id, $client_id]);
            $num = $stmt->fetch()['num'];
            $req_number = 'CR-' . str_pad($num, 5, '0', STR_PAD_LEFT);

            $month_year = date('Y-m');

            $stmt = $pdo->prepare("INSERT INTO cash_requisitions (company_id, client_id, requisition_number, requested_by, category_id, description, amount, type, bank_name, account_number, status, month_year) VALUES (?,?,?,?,?,?,?,?,?,?,'pending',?)");
            $stmt->execute([$company_id, $client_id, $req_number, $user_id, $category_id, $description, $amount, $type, $bank_name, $account_no, $month_year]);
            $req_id = $pdo->lastInsertId();

            log_audit($company_id, $user_id, 'create_cash_req', 'cash_requisitions', $req_id, "Created $req_number — ₦" . number_format($amount, 2) . " ($type)");
            echo json_encode(['success' => true, 'requisition_number' => $req_number]);
            break;

        case 'approve_requisition':
            if (!$is_approver) { echo json_encode(['success' => false, 'message' => 'Not authorized']); break; }
            $req_id = intval($_POST['req_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT * FROM cash_requisitions WHERE id = ? AND company_id = ? AND client_id = ? AND status = 'pending' AND deleted_at IS NULL");
            $stmt->execute([$req_id, $company_id, $client_id]);
            $req = $stmt->fetch();
            if (!$req) { echo json_encode(['success' => false, 'message' => 'Requisition not found or already processed']); break; }

            $pdo->beginTransaction();

            // Approve
            $stmt = $pdo->prepare("UPDATE cash_requisitions SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$user_id, $req_id]);

            // Create CR ledger entry (cash going out)
            $ref_type = $req['type'] === 'bank_deposit' ? 'deposit' : 'requisition';
            $ledger_desc = $req['type'] === 'bank_deposit'
                ? 'Bank Deposit: ' . $req['bank_name'] . ' — ' . $req['description']
                : 'Expense: ' . $req['description'];

            $stmt = $pdo->prepare("INSERT INTO cash_ledger (company_id, client_id, entry_date, reference_type, reference_id, description, dr_amount, cr_amount, posted_by) VALUES (?,?,CURDATE(),?,?,?,0,?,?)");
            $stmt->execute([$company_id, $client_id, $ref_type, $req_id, $ledger_desc, $req['amount'], $user_id]);

            $pdo->commit();

            // Notify requester
            $approver_name = ($_SESSION['user_name'] ?? 'An approver');
            try {
                app_notify($company_id, $req['requested_by'], '✅ Cash Requisition Approved', "$approver_name approved {$req['requisition_number']} (₦" . number_format($req['amount'], 2) . ").", 'success', 'cash.php');
            } catch (Exception $e) { /* notify may not exist */ }

            log_audit($company_id, $user_id, 'approve_cash_req', 'cash_requisitions', $req_id, "Approved {$req['requisition_number']} — ₦" . number_format($req['amount'], 2));
            echo json_encode(['success' => true]);
            break;

        case 'reject_requisition':
            if (!$is_approver) { echo json_encode(['success' => false, 'message' => 'Not authorized']); break; }
            $req_id = intval($_POST['req_id'] ?? 0);
            $reason = clean_input($_POST['reason'] ?? 'No reason given');

            $stmt = $pdo->prepare("UPDATE cash_requisitions SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND company_id = ? AND client_id = ? AND status = 'pending'");
            $stmt->execute([$reason, $user_id, $req_id, $company_id, $client_id]);

            log_audit($company_id, $user_id, 'reject_cash_req', 'cash_requisitions', $req_id, "Rejected: $reason");
            echo json_encode(['success' => true]);
            break;

        case 'delete_requisition':
            $req_id = intval($_POST['req_id'] ?? 0);
            $where = "id = ? AND company_id = ? AND client_id = ? AND status = 'pending' AND deleted_at IS NULL";
            $params = [$req_id, $company_id, $client_id];
            if (!$is_approver) { $where .= " AND requested_by = ?"; $params[] = $user_id; }

            $stmt = $pdo->prepare("UPDATE cash_requisitions SET deleted_at = NOW() WHERE $where");
            $stmt->execute($params);

            echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => $stmt->rowCount() > 0 ? '' : 'Cannot delete this requisition']);
            break;

        // ═══════════ EXPENSE CATEGORIES ═══════════
        case 'get_categories':
            $stmt = $pdo->prepare("SELECT * FROM cash_expense_categories WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL ORDER BY name");
            $stmt->execute([$company_id, $client_id]);
            echo json_encode(['success' => true, 'categories' => $stmt->fetchAll()]);
            break;

        case 'save_category':
            if (!$is_approver) { echo json_encode(['success' => false, 'message' => 'Not authorized']); break; }
            $cat_id = intval($_POST['category_id'] ?? 0);
            $name   = clean_input($_POST['name'] ?? '');
            $desc   = clean_input($_POST['cat_description'] ?? '');

            if (!$name) { echo json_encode(['success' => false, 'message' => 'Category name is required']); break; }

            if ($cat_id > 0) {
                $stmt = $pdo->prepare("UPDATE cash_expense_categories SET name = ?, description = ? WHERE id = ? AND company_id = ? AND client_id = ?");
                $stmt->execute([$name, $desc, $cat_id, $company_id, $client_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO cash_expense_categories (company_id, client_id, name, description) VALUES (?,?,?,?)");
                $stmt->execute([$company_id, $client_id, $name, $desc]);
                $cat_id = $pdo->lastInsertId();
            }
            echo json_encode(['success' => true, 'category_id' => $cat_id]);
            break;

        case 'delete_category':
            if (!$is_approver) { echo json_encode(['success' => false, 'message' => 'Not authorized']); break; }
            $cat_id = intval($_POST['category_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE cash_expense_categories SET deleted_at = NOW() WHERE id = ? AND company_id = ? AND client_id = ?");
            $stmt->execute([$cat_id, $company_id, $client_id]);
            echo json_encode(['success' => true]);
            break;

        // ═══════════ LEDGER ═══════════
        case 'get_ledger':
            $month = clean_input($_POST['month'] ?? date('Y-m'));
            $start_date = $month . '-01';
            $end_date = date('Y-m-t', strtotime($start_date));

            $stmt = $pdo->prepare("SELECT cl.*, u.first_name, u.last_name 
                FROM cash_ledger cl 
                LEFT JOIN users u ON cl.posted_by = u.id 
                WHERE cl.company_id = ? AND cl.client_id = ? AND cl.entry_date >= ? AND cl.entry_date <= ? 
                ORDER BY cl.entry_date ASC, cl.id ASC");
            $stmt->execute([$company_id, $client_id, $start_date, $end_date]);
            $entries = $stmt->fetchAll();

            // Compute running balance
            // First get all previous entries balance (opening for this month)
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(dr_amount),0) - COALESCE(SUM(cr_amount),0) as opening 
                FROM cash_ledger WHERE company_id = ? AND client_id = ? AND entry_date < ?");
            $stmt->execute([$company_id, $client_id, $start_date]);
            $opening_balance = floatval($stmt->fetchColumn());

            $running = $opening_balance;
            foreach ($entries as &$e) {
                $running += floatval($e['dr_amount']) - floatval($e['cr_amount']);
                $e['balance'] = $running;
            }
            unset($e);

            echo json_encode(['success' => true, 'entries' => $entries, 'opening_balance' => $opening_balance]);
            break;

        // ═══════════ ANALYSIS ═══════════
        case 'get_analysis':
            $month = clean_input($_POST['month'] ?? date('Y-m'));

            // Category-grouped breakdown of approved requisitions
            $stmt = $pdo->prepare("SELECT 
                    COALESCE(c.name, 'Uncategorized') as category_name,
                    cr.type,
                    COUNT(*) as count,
                    SUM(cr.amount) as total_amount
                FROM cash_requisitions cr
                LEFT JOIN cash_expense_categories c ON cr.category_id = c.id
                WHERE cr.company_id = ? AND cr.client_id = ? AND cr.status = 'approved' 
                AND cr.month_year = ? AND cr.deleted_at IS NULL
                GROUP BY COALESCE(c.name, 'Uncategorized'), cr.type
                ORDER BY total_amount DESC");
            $stmt->execute([$company_id, $client_id, $month]);
            $breakdown = $stmt->fetchAll();

            // Totals
            $stmt = $pdo->prepare("SELECT 
                    COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) as total_expenses,
                    COALESCE(SUM(CASE WHEN type='bank_deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                    COUNT(*) as total_count
                FROM cash_requisitions 
                WHERE company_id = ? AND client_id = ? AND status = 'approved' AND month_year = ? AND deleted_at IS NULL");
            $stmt->execute([$company_id, $client_id, $month]);
            $totals = $stmt->fetch();

            echo json_encode(['success' => true, 'breakdown' => $breakdown, 'totals' => $totals]);
            break;

        // ═══════════ REPORT ═══════════
        case 'get_report':
            $month = clean_input($_POST['month'] ?? date('Y-m'));
            $start_date = $month . '-01';
            $end_date = date('Y-m-t', strtotime($start_date));

            // Opening balance
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(dr_amount),0) - COALESCE(SUM(cr_amount),0) as opening 
                FROM cash_ledger WHERE company_id = ? AND client_id = ? AND entry_date < ?");
            $stmt->execute([$company_id, $client_id, $start_date]);
            $opening = floatval($stmt->fetchColumn());

            // Total sales (confirmed) for the month
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_sales 
                WHERE company_id = ? AND client_id = ? AND status = 'confirmed' AND sale_date >= ? AND sale_date <= ? AND deleted_at IS NULL");
            $stmt->execute([$company_id, $client_id, $start_date, $end_date]);
            $total_sales = floatval($stmt->fetchColumn());

            // Total expenses (approved requisitions type=expense)
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_requisitions 
                WHERE company_id = ? AND client_id = ? AND status = 'approved' AND type = 'expense' AND month_year = ? AND deleted_at IS NULL");
            $stmt->execute([$company_id, $client_id, $month]);
            $total_expenses = floatval($stmt->fetchColumn());

            // Total bank deposits
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_requisitions 
                WHERE company_id = ? AND client_id = ? AND status = 'approved' AND type = 'bank_deposit' AND month_year = ? AND deleted_at IS NULL");
            $stmt->execute([$company_id, $client_id, $month]);
            $total_deposits = floatval($stmt->fetchColumn());

            $closing = $opening + $total_sales - $total_expenses - $total_deposits;

            // Sales list
            $stmt = $pdo->prepare("SELECT cs.*, u.first_name as posted_first, u.last_name as posted_last, 
                    cu.first_name as confirmed_first, cu.last_name as confirmed_last
                FROM cash_sales cs 
                LEFT JOIN users u ON cs.posted_by = u.id 
                LEFT JOIN users cu ON cs.confirmed_by = cu.id
                WHERE cs.company_id = ? AND cs.client_id = ? AND cs.sale_date >= ? AND cs.sale_date <= ? AND cs.deleted_at IS NULL
                ORDER BY cs.sale_date DESC, cs.id DESC");
            $stmt->execute([$company_id, $client_id, $start_date, $end_date]);
            $sales = $stmt->fetchAll();

            // Requisitions list
            $stmt = $pdo->prepare("SELECT cr.*, c.name as category_name, 
                    u.first_name as req_first, u.last_name as req_last,
                    au.first_name as appr_first, au.last_name as appr_last
                FROM cash_requisitions cr 
                LEFT JOIN cash_expense_categories c ON cr.category_id = c.id
                LEFT JOIN users u ON cr.requested_by = u.id
                LEFT JOIN users au ON cr.approved_by = au.id
                WHERE cr.company_id = ? AND cr.client_id = ? AND cr.month_year = ? AND cr.deleted_at IS NULL
                ORDER BY cr.created_at DESC");
            $stmt->execute([$company_id, $client_id, $month]);
            $requisitions = $stmt->fetchAll();

            echo json_encode(['success' => true, 'report' => [
                'month'          => $month,
                'opening'        => $opening,
                'total_sales'    => $total_sales,
                'total_expenses' => $total_expenses,
                'total_deposits' => $total_deposits,
                'closing'        => $closing,
                'sales'          => $sales,
                'requisitions'   => $requisitions
            ]]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
