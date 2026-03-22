<?php
/**
 * MIAUDITOPS — P&L Generator API (Period-Based)
 * Reports scoped to a month; each report has multiple date-range periods.
 * Revenue, COS, Expenses are entered per period. P&L aggregates all periods.
 */
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!is_logged_in()) { echo json_encode(['success' => false, 'message' => 'Not authenticated']); exit; }

// Sanitize input WITHOUT htmlspecialchars (Alpine x-text and esc() handle escaping on output)
function pnl_clean($data) { return trim(stripslashes($data)); }

// One-time cleanup: remove stale catalog tables and phantom entries
try {
    $pdo->exec("UPDATE pnl_revenue SET label = REPLACE(REPLACE(label, '&amp;amp;', '&amp;'), '&amp;', '&') WHERE label LIKE '%&amp;%'");
    $pdo->exec("UPDATE pnl_cost_of_sales SET label = REPLACE(REPLACE(label, '&amp;amp;', '&amp;'), '&amp;', '&') WHERE label LIKE '%&amp;%'");
    $pdo->exec("UPDATE pnl_expenses SET label = REPLACE(REPLACE(label, '&amp;amp;', '&amp;'), '&amp;', '&') WHERE label LIKE '%&amp;%'");
    $pdo->exec("DROP TABLE IF EXISTS pnl_revenue_catalog");
    $pdo->exec("DROP TABLE IF EXISTS pnl_purchase_catalog");
} catch (Exception $e) {}

$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Auto-migrate ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pnl_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        title VARCHAR(255) DEFAULT '',
        industry ENUM('hospitality','manufacturing') DEFAULT 'hospitality',
        report_month INT NOT NULL DEFAULT 1,
        report_year INT NOT NULL DEFAULT 2026,
        location VARCHAR(150) DEFAULT '',
        status ENUM('draft','finalized') DEFAULT 'draft',
        ai_recommendation TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_company_client (company_id, client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure ai_recommendation column exists
    try { $pdo->exec("ALTER TABLE pnl_reports ADD COLUMN ai_recommendation TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE pnl_reports ADD COLUMN prev_pnl_data TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE pnl_reports ADD COLUMN pdf_style TEXT DEFAULT NULL"); } catch (Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS pnl_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        date_from DATE NOT NULL,
        date_to DATE NOT NULL,
        sort_order INT DEFAULT 0,
        KEY idx_report (report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pnl_revenue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        period_id INT NOT NULL,
        label VARCHAR(150) NOT NULL DEFAULT '',
        amount DECIMAL(15,2) DEFAULT 0.00,
        sort_order INT DEFAULT 0,
        KEY idx_period (period_id),
        KEY idx_report (report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pnl_cost_of_sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        period_id INT NOT NULL,
        label VARCHAR(150) NOT NULL DEFAULT '',
        amount DECIMAL(15,2) DEFAULT 0.00,
        entry_type ENUM('opening','purchase','closing') DEFAULT 'opening',
        sub_entries TEXT DEFAULT NULL,
        sort_order INT DEFAULT 0,
        KEY idx_period (period_id),
        KEY idx_report (report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Auto-add sub_entries column if table already exists without it
    try { $pdo->exec("ALTER TABLE pnl_cost_of_sales ADD COLUMN sub_entries TEXT DEFAULT NULL AFTER entry_type"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE pnl_cost_of_sales ADD COLUMN department VARCHAR(100) DEFAULT '' AFTER sub_entries"); } catch (Exception $ignore) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS pnl_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        period_id INT NOT NULL,
        label VARCHAR(150) NOT NULL DEFAULT '',
        amount DECIMAL(15,2) DEFAULT 0.00,
        category ENUM('operating','other') DEFAULT 'operating',
        sub_entries TEXT DEFAULT NULL,
        sort_order INT DEFAULT 0,
        KEY idx_period (period_id),
        KEY idx_report (report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Persistent stock catalog per client
    $pdo->exec("CREATE TABLE IF NOT EXISTS pnl_stock_catalog (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        client_id INT NOT NULL,
        item_name VARCHAR(150) NOT NULL DEFAULT '',
        unit_cost DECIMAL(15,2) DEFAULT 0.00,
        department VARCHAR(100) DEFAULT '',
        category VARCHAR(100) DEFAULT '',
        pack_size INT DEFAULT 1,
        sort_order INT DEFAULT 0,
        active TINYINT DEFAULT 1,
        KEY idx_company_client (company_id, client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Auto-add department/category columns if table already exists
    try { $pdo->exec("ALTER TABLE pnl_stock_catalog ADD COLUMN department VARCHAR(100) DEFAULT '' AFTER unit_cost"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE pnl_stock_catalog ADD COLUMN category VARCHAR(100) DEFAULT '' AFTER department"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE pnl_stock_catalog ADD COLUMN pack_size INT DEFAULT 1 AFTER category"); } catch (Exception $e) {}
} catch (Exception $ignore) {}

try {
    switch ($action) {

        // ── List reports ──
        case 'get_reports':
            $stmt = $pdo->prepare("
                SELECT r.*, u.first_name, u.last_name, c.name as client_name,
                       (SELECT COUNT(*) FROM pnl_periods WHERE report_id = r.id) as period_count
                FROM pnl_reports r
                LEFT JOIN users u ON u.id = r.created_by
                LEFT JOIN clients c ON c.id = r.client_id
                WHERE r.company_id = ? AND r.client_id = ?
                ORDER BY r.report_year DESC, r.report_month DESC, r.created_at DESC
            ");
            $stmt->execute([$company_id, $client_id]);
            echo json_encode(['success' => true, 'reports' => $stmt->fetchAll()]);
            break;

        // ── Get single report with periods + all line items ──
        case 'get_report':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Report ID required']); break; }

            $stmt = $pdo->prepare("SELECT r.*, c.name as client_name FROM pnl_reports r LEFT JOIN clients c ON c.id = r.client_id WHERE r.id = ? AND r.company_id = ?");
            $stmt->execute([$id, $company_id]);
            $report = $stmt->fetch();
            if (!$report) { echo json_encode(['success' => false, 'message' => 'Report not found']); break; }

            // Periods
            $stmt = $pdo->prepare("SELECT * FROM pnl_periods WHERE report_id = ? ORDER BY sort_order, date_from");
            $stmt->execute([$id]);
            $periods = $stmt->fetchAll();

            // All line items (grouped by period on frontend)
            $stmt = $pdo->prepare("SELECT * FROM pnl_revenue WHERE report_id = ? ORDER BY period_id, sort_order, id");
            $stmt->execute([$id]); $revenue = $stmt->fetchAll();

            $stmt = $pdo->prepare("SELECT * FROM pnl_cost_of_sales WHERE report_id = ? ORDER BY period_id, sort_order, id");
            $stmt->execute([$id]); $cos = $stmt->fetchAll();

            $stmt = $pdo->prepare("SELECT * FROM pnl_expenses WHERE report_id = ? ORDER BY period_id, category, sort_order, id");
            $stmt->execute([$id]); $expenses = $stmt->fetchAll();

            echo json_encode(['success' => true, 'report' => $report, 'periods' => $periods, 'revenue' => $revenue, 'cost_of_sales' => $cos, 'expenses' => $expenses]);
            break;

        // ── Get previous month's P&L aggregated totals (for MoM comparison) ──
        case 'get_previous_month_pnl':
            $report_id = intval($_GET['report_id'] ?? 0);
            if (!$report_id) { echo json_encode(['success' => false]); break; }

            // Get current report's month/year/client
            $stmt = $pdo->prepare("SELECT client_id, report_month, report_year FROM pnl_reports WHERE id = ? AND company_id = ?");
            $stmt->execute([$report_id, $company_id]);
            $cur = $stmt->fetch();
            if (!$cur) { echo json_encode(['success' => false]); break; }

            // Calculate previous month
            $pm = intval($cur['report_month']) - 1;
            $py = intval($cur['report_year']);
            if ($pm < 1) { $pm = 12; $py--; }

            // Find previous month's report
            $stmt = $pdo->prepare("SELECT id, report_month, report_year FROM pnl_reports WHERE company_id = ? AND client_id = ? AND report_month = ? AND report_year = ? LIMIT 1");
            $stmt->execute([$company_id, $cur['client_id'], $pm, $py]);
            $prev = $stmt->fetch();
            if (!$prev) { echo json_encode(['success' => true, 'has_previous' => false]); break; }

            $pid = $prev['id'];
            // Aggregate revenue
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM pnl_revenue WHERE report_id = ?");
            $stmt->execute([$pid]); $totalRev = floatval($stmt->fetchColumn());

            // Aggregate COS
            $stmt = $pdo->prepare("SELECT entry_type, COALESCE(SUM(amount),0) as total FROM pnl_cost_of_sales WHERE report_id = ? GROUP BY entry_type");
            $stmt->execute([$pid]);
            $cosData = $stmt->fetchAll();
            $opening = 0; $purchases = 0; $closing = 0;
            foreach ($cosData as $c) {
                if ($c['entry_type'] === 'opening') $opening = floatval($c['total']);
                elseif ($c['entry_type'] === 'purchase') $purchases = floatval($c['total']);
                elseif ($c['entry_type'] === 'closing') $closing = floatval($c['total']);
            }
            $totalCOS = ($opening + $purchases) - $closing;

            // Aggregate expenses
            $stmt = $pdo->prepare("SELECT category, COALESCE(SUM(amount),0) as total FROM pnl_expenses WHERE report_id = ? GROUP BY category");
            $stmt->execute([$pid]);
            $expData = $stmt->fetchAll();
            $totalOpex = 0; $totalOther = 0;
            foreach ($expData as $e) {
                if ($e['category'] === 'operating') $totalOpex = floatval($e['total']);
                else $totalOther = floatval($e['total']);
            }

            $grossProfit = $totalRev - $totalCOS;
            $operatingProfit = $grossProfit - $totalOpex;
            $netProfit = $operatingProfit - $totalOther;

            echo json_encode([
                'success' => true, 'has_previous' => true,
                'month' => $pm, 'year' => $py,
                'totalRevenue' => $totalRev, 'cos' => $totalCOS,
                'grossProfit' => $grossProfit, 'totalOpex' => $totalOpex,
                'totalOther' => $totalOther, 'operatingProfit' => $operatingProfit,
                'netProfit' => $netProfit, 'closingStock' => $closing
            ]);
            break;

        // ── Get rollup data (quarterly/annual aggregate) ──
        case 'get_rollup':
            $period_type = $_GET['period_type'] ?? 'annual'; // Q1,Q2,Q3,Q4,annual
            $year = intval($_GET['year'] ?? date('Y'));
            // Determine months
            $months = [];
            switch ($period_type) {
                case 'Q1': $months = [1,2,3]; break;
                case 'Q2': $months = [4,5,6]; break;
                case 'Q3': $months = [7,8,9]; break;
                case 'Q4': $months = [10,11,12]; break;
                default: $months = range(1,12); break;
            }
            $placeholders = implode(',', array_fill(0, count($months), '?'));
            $params = array_merge([$company_id, $client_id, $year], $months);
            $stmt = $pdo->prepare("SELECT * FROM pnl_reports WHERE company_id = ? AND client_id = ? AND report_year = ? AND report_month IN ($placeholders) ORDER BY report_month ASC");
            $stmt->execute($params);
            $rpts = $stmt->fetchAll();

            if (empty($rpts)) { echo json_encode(['success' => true, 'has_data' => false]); break; }

            $monthlyData = [];
            $grandRevenue = 0; $grandCOS = 0; $grandOpex = 0; $grandOther = 0;

            foreach ($rpts as $rpt) {
                $rid = $rpt['id'];
                // Revenue
                $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pnl_revenue WHERE report_id = ?");
                $s->execute([$rid]); $rev = floatval($s->fetchColumn());
                // COS
                $s = $pdo->prepare("SELECT entry_type, COALESCE(SUM(amount),0) as total FROM pnl_cost_of_sales WHERE report_id = ? GROUP BY entry_type");
                $s->execute([$rid]); $cd = $s->fetchAll();
                $op = 0; $pu = 0; $cl = 0;
                foreach ($cd as $c) {
                    if ($c['entry_type'] === 'opening') $op = floatval($c['total']);
                    elseif ($c['entry_type'] === 'purchase') $pu = floatval($c['total']);
                    elseif ($c['entry_type'] === 'closing') $cl = floatval($c['total']);
                }
                $cos = ($op + $pu) - $cl;
                // Expenses
                $s = $pdo->prepare("SELECT category, COALESCE(SUM(amount),0) as total FROM pnl_expenses WHERE report_id = ? GROUP BY category");
                $s->execute([$rid]); $ed = $s->fetchAll();
                $opex = 0; $oth = 0;
                foreach ($ed as $e) {
                    if ($e['category'] === 'operating') $opex = floatval($e['total']);
                    else $oth = floatval($e['total']);
                }
                $gp = $rev - $cos; $opProfit = $gp - $opex; $np = $opProfit - $oth;
                $monthlyData[] = [
                    'month' => intval($rpt['report_month']), 'year' => intval($rpt['report_year']),
                    'title' => $rpt['title'], 'totalRevenue' => $rev, 'cos' => $cos,
                    'grossProfit' => $gp, 'totalOpex' => $opex, 'totalOther' => $oth,
                    'operatingProfit' => $opProfit, 'netProfit' => $np
                ];
                $grandRevenue += $rev; $grandCOS += $cos; $grandOpex += $opex; $grandOther += $oth;
            }
            $grandGP = $grandRevenue - $grandCOS;
            $grandOpProfit = $grandGP - $grandOpex;
            $grandNP = $grandOpProfit - $grandOther;

            echo json_encode([
                'success' => true, 'has_data' => true,
                'period_type' => $period_type, 'year' => $year,
                'months' => $monthlyData,
                'totals' => [
                    'totalRevenue' => $grandRevenue, 'cos' => $grandCOS,
                    'grossProfit' => $grandGP, 'totalOpex' => $grandOpex,
                    'totalOther' => $grandOther, 'operatingProfit' => $grandOpProfit,
                    'netProfit' => $grandNP
                ]
            ]);
            break;

        // ── Create report (month-scoped) ──
        case 'create_report':
            require_non_viewer();
            $title    = pnl_clean($_POST['title'] ?? '');
            $industry = in_array($_POST['industry'] ?? '', ['hospitality','manufacturing']) ? $_POST['industry'] : 'hospitality';
            $month    = max(1, min(12, intval($_POST['report_month'] ?? date('n'))));
            $year     = max(2020, min(2099, intval($_POST['report_year'] ?? date('Y'))));
            $location = pnl_clean($_POST['location'] ?? '');

            if (!$client_id) { echo json_encode(['success' => false, 'message' => 'Please select a client first']); break; }

            $stmt = $pdo->prepare("INSERT INTO pnl_reports (company_id, client_id, title, industry, report_month, report_year, location, created_by) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$company_id, $client_id, $title, $industry, $month, $year, $location, $user_id]);
            $rid = $pdo->lastInsertId();

            log_audit($company_id, $user_id, 'pnl_report_created', 'pnl', $rid, "Created P&L report: $title ($industry, $month/$year)");
            echo json_encode(['success' => true, 'id' => $rid]);
            break;

        // ── Create period within a report ──
        case 'create_period':
            require_non_viewer();
            $report_id = intval($_POST['report_id'] ?? 0);
            $date_from = $_POST['date_from'] ?? '';
            $date_to   = $_POST['date_to'] ?? '';

            if (!$report_id || !$date_from || !$date_to) {
                echo json_encode(['success' => false, 'message' => 'Report ID and dates are required']); break;
            }

            // Verify ownership & draft
            $chk = $pdo->prepare("SELECT id, industry FROM pnl_reports WHERE id = ? AND company_id = ? AND status = 'draft'");
            $chk->execute([$report_id, $company_id]);
            $rpt = $chk->fetch();
            if (!$rpt) { echo json_encode(['success' => false, 'message' => 'Report not found or finalized']); break; }

            // Get next sort order
            $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM pnl_periods WHERE report_id = ?");
            $s->execute([$report_id]);
            $sort = $s->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO pnl_periods (report_id, date_from, date_to, sort_order) VALUES (?,?,?,?)");
            $stmt->execute([$report_id, $date_from, $date_to, $sort]);
            $pid = $pdo->lastInsertId();

            // Pre-fill templates for this period
            $industry = $rpt['industry'];
            if ($industry === 'hospitality') {
                $rev_labels = ['Room Sales','Food Sales','Beverage Sales','Hall/Event Rental','Laundry Income','Other Income'];
                $cos_items  = [['Opening Stock','opening'],['Food Purchases','purchase'],['Beverage Purchases','purchase'],['Other Purchases','purchase'],['Closing Stock','closing']];
            } else {
                $rev_labels = ['Product Sales','Service Revenue','Scrap/By-Product Sales','Other Income'];
                $cos_items  = [['Opening Stock','opening'],['Raw Material Purchases','purchase'],['Direct Labour','purchase'],['Manufacturing Overhead','purchase'],['Closing Stock','closing']];
            }

            $ins = $pdo->prepare("INSERT INTO pnl_revenue (report_id, period_id, label, amount, sort_order) VALUES (?,?,?,0,?)");
            foreach ($rev_labels as $i => $lbl) { $ins->execute([$report_id, $pid, $lbl, $i]); }

            $ins = $pdo->prepare("INSERT INTO pnl_cost_of_sales (report_id, period_id, label, amount, entry_type, sort_order) VALUES (?,?,?,0,?,?)");
            foreach ($cos_items as $i => $item) { $ins->execute([$report_id, $pid, $item[0], $item[1], $i]); }

            $opex = ['Staff Salaries','Electricity','Diesel / Fuel','Maintenance & Repairs','Security','Cleaning Materials','Marketing / Advertising','Transport','Office Expenses'];
            $other = ['Bank Charges','Loan Interest','Finance Charges','Depreciation'];

            $ins = $pdo->prepare("INSERT INTO pnl_expenses (report_id, period_id, label, amount, category, sub_entries, sort_order) VALUES (?,?,?,0,?,?,?)");
            foreach ($opex as $i => $lbl) { $ins->execute([$report_id, $pid, $lbl, 'operating', json_encode([['note'=>'','amount'=>0]]), $i]); }
            foreach ($other as $i => $lbl) { $ins->execute([$report_id, $pid, $lbl, 'other', json_encode([['note'=>'','amount'=>0]]), $i]); }

            echo json_encode(['success' => true, 'period_id' => $pid]);
            break;

        // ── Delete period + cascading data ──
        case 'delete_period':
            require_non_viewer();
            $period_id = intval($_POST['period_id'] ?? 0);
            if (!$period_id) { echo json_encode(['success' => false, 'message' => 'Period ID required']); break; }

            // Verify ownership via report
            $chk = $pdo->prepare("SELECT p.id, p.report_id FROM pnl_periods p JOIN pnl_reports r ON r.id = p.report_id WHERE p.id = ? AND r.company_id = ? AND r.status = 'draft'");
            $chk->execute([$period_id, $company_id]);
            if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Period not found or report finalized']); break; }

            $pdo->prepare("DELETE FROM pnl_revenue WHERE period_id = ?")->execute([$period_id]);
            $pdo->prepare("DELETE FROM pnl_cost_of_sales WHERE period_id = ?")->execute([$period_id]);
            $pdo->prepare("DELETE FROM pnl_expenses WHERE period_id = ?")->execute([$period_id]);
            $pdo->prepare("DELETE FROM pnl_periods WHERE id = ?")->execute([$period_id]);

            echo json_encode(['success' => true]);
            break;

        // ── Update period dates ──
        case 'update_period':
            require_non_viewer();
            $period_id = intval($_POST['period_id'] ?? 0);
            $date_from = pnl_clean($_POST['date_from'] ?? '');
            $date_to   = pnl_clean($_POST['date_to'] ?? '');
            if (!$period_id || !$date_from || !$date_to) { echo json_encode(['success' => false, 'message' => 'Missing data']); break; }

            $chk = $pdo->prepare("SELECT p.id FROM pnl_periods p JOIN pnl_reports r ON r.id = p.report_id WHERE p.id = ? AND r.company_id = ? AND r.status = 'draft'");
            $chk->execute([$period_id, $company_id]);
            if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Period not found or report finalized']); break; }

            $pdo->prepare("UPDATE pnl_periods SET date_from = ?, date_to = ? WHERE id = ?")->execute([$date_from, $date_to, $period_id]);
            echo json_encode(['success' => true]);
            break;

        // ── Save revenue for a period ──
        case 'save_revenue':
            require_non_viewer();
            $period_id = intval($_POST['period_id'] ?? 0);
            $report_id = intval($_POST['report_id'] ?? 0);
            $items     = json_decode($_POST['items'] ?? '[]', true);
            if (!$period_id || !$report_id || !is_array($items)) { echo json_encode(['success' => false, 'message' => 'Invalid data']); break; }

            $chk = $pdo->prepare("SELECT id FROM pnl_reports WHERE id = ? AND company_id = ? AND status = 'draft'");
            $chk->execute([$report_id, $company_id]);
            if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Report not found or finalized']); break; }

            $pdo->prepare("DELETE FROM pnl_revenue WHERE period_id = ?")->execute([$period_id]);
            $s = $pdo->prepare("INSERT INTO pnl_revenue (report_id, period_id, label, amount, sort_order) VALUES (?,?,?,?,?)");
            foreach ($items as $i => $item) {
                $label = pnl_clean($item['label'] ?? '');
                $amount = floatval($item['amount'] ?? 0);
                if (!empty($label)) {
                    $s->execute([$report_id, $period_id, $label, $amount, $i]);
                }
            }
            echo json_encode(['success' => true]);
            break;

        // ── Save cost of sales for a period ──
        case 'save_cost_of_sales':
            require_non_viewer();
            $period_id = intval($_POST['period_id'] ?? 0);
            $report_id = intval($_POST['report_id'] ?? 0);
            $items     = json_decode($_POST['items'] ?? '[]', true);
            if (!$period_id || !$report_id || !is_array($items)) { echo json_encode(['success' => false, 'message' => 'Invalid data']); break; }

            $chk = $pdo->prepare("SELECT id FROM pnl_reports WHERE id = ? AND company_id = ? AND status = 'draft'");
            $chk->execute([$report_id, $company_id]);
            if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Report not found or finalized']); break; }

            $pdo->prepare("DELETE FROM pnl_cost_of_sales WHERE period_id = ?")->execute([$period_id]);
            $s = $pdo->prepare("INSERT INTO pnl_cost_of_sales (report_id, period_id, label, amount, entry_type, sub_entries, department, sort_order) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($items as $i => $item) {
                $label = pnl_clean($item['label'] ?? '');
                $type = in_array($item['entry_type'] ?? '', ['opening','purchase','closing']) ? $item['entry_type'] : 'opening';
                $dept = pnl_clean($item['department'] ?? '');
                $subs = $item['sub_entries'] ?? null;
                $amount = 0;
                if (is_array($subs) && count($subs) > 0) {
                    if ($type === 'closing') {
                        // Closing: sub_entries have qty + unit_cost → value = qty × unit_cost
                        foreach ($subs as $sub) { $amount += floatval($sub['qty'] ?? 0) * floatval($sub['unit_cost'] ?? 0); }
                    } else {
                        // Purchase/Opening: sub_entries have note + amount
                        foreach ($subs as $sub) { $amount += floatval($sub['amount'] ?? 0); }
                    }
                } else {
                    $amount = floatval($item['amount'] ?? 0);
                }
                $subs_json = is_array($subs) ? json_encode($subs) : null;
                if (!empty($label)) {
                    $s->execute([$report_id, $period_id, $label, $amount, $type, $subs_json, $dept, $i]);
                }
            }
            echo json_encode(['success' => true]);
            break;

        // ── Save expenses for a period ──
        case 'save_expenses':
            require_non_viewer();
            $period_id = intval($_POST['period_id'] ?? 0);
            $report_id = intval($_POST['report_id'] ?? 0);
            $items     = json_decode($_POST['items'] ?? '[]', true);
            if (!$period_id || !$report_id || !is_array($items)) { echo json_encode(['success' => false, 'message' => 'Invalid data']); break; }

            $chk = $pdo->prepare("SELECT id FROM pnl_reports WHERE id = ? AND company_id = ? AND status = 'draft'");
            $chk->execute([$report_id, $company_id]);
            if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Report not found or finalized']); break; }

            $pdo->prepare("DELETE FROM pnl_expenses WHERE period_id = ?")->execute([$period_id]);
            $s = $pdo->prepare("INSERT INTO pnl_expenses (report_id, period_id, label, amount, category, sub_entries, sort_order) VALUES (?,?,?,?,?,?,?)");
            foreach ($items as $i => $item) {
                $label = pnl_clean($item['label'] ?? '');
                $cat = in_array($item['category'] ?? '', ['operating','other']) ? $item['category'] : 'operating';
                $subs = $item['sub_entries'] ?? [];
                $amount = 0;
                if (is_array($subs) && count($subs) > 0) {
                    foreach ($subs as $sub) { $amount += floatval($sub['amount'] ?? 0); }
                } else {
                    $amount = floatval($item['amount'] ?? 0);
                    $subs = [['note' => '', 'amount' => $amount]];
                }
                if (!empty($label)) $s->execute([$report_id, $period_id, $label, $amount, $cat, json_encode($subs), $i]);
            }
            echo json_encode(['success' => true]);
            break;

        // ── Delete report ──
        case 'delete_report':
            require_non_viewer();
            $id = intval($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid report']); break; }

            $chk = $pdo->prepare("SELECT id, title FROM pnl_reports WHERE id = ? AND company_id = ?");
            $chk->execute([$id, $company_id]);
            $rpt = $chk->fetch();
            if (!$rpt) { echo json_encode(['success' => false, 'message' => 'Report not found']); break; }

            $pdo->prepare("DELETE FROM pnl_revenue WHERE report_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM pnl_cost_of_sales WHERE report_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM pnl_expenses WHERE report_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM pnl_periods WHERE report_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM pnl_reports WHERE id = ? AND company_id = ?")->execute([$id, $company_id]);

            log_audit($company_id, $user_id, 'pnl_report_deleted', 'pnl', $id, "Deleted P&L report: " . $rpt['title']);
            echo json_encode(['success' => true]);
            break;

        // ── Finalize report ──
        case 'finalize_report':
            require_non_viewer();
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE pnl_reports SET status = 'finalized' WHERE id = ? AND company_id = ?")->execute([$id, $company_id]);
            log_audit($company_id, $user_id, 'pnl_report_finalized', 'pnl', $id, "Finalized P&L report ID: $id");
            echo json_encode(['success' => true]);
            break;

        // ── Get stock catalog for client ──
        case 'get_stock_catalog':
            $stmt = $pdo->prepare("SELECT * FROM pnl_stock_catalog WHERE company_id = ? AND client_id = ? AND active = 1 ORDER BY department, category, sort_order, id");
            $stmt->execute([$company_id, $client_id]);
            echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);
            break;

        // ── Save stock catalog (add/update/remove) ──
        case 'save_stock_catalog':
            require_non_viewer();
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($items)) { echo json_encode(['success' => false, 'message' => 'Invalid data']); break; }

            // Soft-delete all then re-insert active ones
            $pdo->prepare("UPDATE pnl_stock_catalog SET active = 0 WHERE company_id = ? AND client_id = ?")->execute([$company_id, $client_id]);

            foreach ($items as $i => $item) {
                $name = pnl_clean($item['item_name'] ?? '');
                $cost = floatval($item['unit_cost'] ?? 0);
                $dept = pnl_clean($item['department'] ?? '');
                $cat  = pnl_clean($item['category'] ?? '');
                $ps   = max(1, intval($item['pack_size'] ?? 1));
                if (!empty($name)) {
                    $ins = $pdo->prepare("INSERT INTO pnl_stock_catalog (company_id, client_id, item_name, unit_cost, department, category, pack_size, sort_order, active) VALUES (?,?,?,?,?,?,?,?,1)");
                    $ins->execute([$company_id, $client_id, $name, $cost, $dept, $cat, $ps, $i]);
                }
            }

            // Remove truly orphaned inactive items
            $pdo->prepare("DELETE FROM pnl_stock_catalog WHERE company_id = ? AND client_id = ? AND active = 0")->execute([$company_id, $client_id]);

            echo json_encode(['success' => true]);
            break;

        // ── Save AI Recommendation ──
        case 'save_ai_report':
            require_non_viewer();
            $report_id = intval($_POST['report_id'] ?? 0);
            $text = $_POST['ai_recommendation'] ?? '';
            $pdo->prepare("UPDATE pnl_reports SET ai_recommendation = ? WHERE id = ? AND company_id = ? AND client_id = ?")->execute([$text, $report_id, $company_id, $client_id]);
            echo json_encode(['success' => true]);
            break;

        // ── Save previous P&L data ──
        case 'save_prev_pnl':
            require_non_viewer();
            $report_id = intval($_POST['report_id'] ?? 0);
            $data = $_POST['prev_pnl_data'] ?? '{}';
            $pdo->prepare("UPDATE pnl_reports SET prev_pnl_data = ? WHERE id = ? AND company_id = ? AND client_id = ?")->execute([$data, $report_id, $company_id, $client_id]);
            echo json_encode(['success' => true]);
            break;

        // ── Save PDF style settings ──
        case 'save_pdf_style':
            require_non_viewer();
            $report_id = intval($_POST['report_id'] ?? 0);
            $style = $_POST['pdf_style'] ?? '{}';
            $pdo->prepare("UPDATE pnl_reports SET pdf_style = ? WHERE id = ? AND company_id = ? AND client_id = ?")->execute([$style, $report_id, $company_id, $client_id]);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
