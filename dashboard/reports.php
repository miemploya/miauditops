<?php
/**
 * MIAUDITOPS - Reporting Engine (Phase 8 - Detailed Audit Design)
 * 6 Report Tabs with High-Fidelity Sales Reconciliation Cards
 */
require_once '../includes/functions.php';
require_login();
require_permission('reports');
require_active_client();
$company_id   = $_SESSION['company_id'];
$client_id    = get_active_client();
$client_name  = $_SESSION['active_client_name'] ?? 'Client';
$company_name = $_SESSION['company_name'] ?? 'Company';
$page_title   = 'Reports';

$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to   = $_GET['to']   ?? date('Y-m-d');
$date_label = date('M j', strtotime($date_from)) . ' - ' . date('M j, Y', strtotime($date_to));

// ---- 1. Sales Reconciliation (Grouped by Date) ----
$stmt = $pdo->prepare("SELECT st.transaction_date, st.shift, COALESCE(co.name, st.shift, 'Main') as outlet_name, st.pos_amount, st.cash_amount, st.transfer_amount, st.other_amount, st.declared_total, st.actual_total, st.variance, st.notes FROM sales_transactions st LEFT JOIN client_outlets co ON st.outlet_id = co.id WHERE st.company_id = ? AND st.client_id = ? AND st.transaction_date BETWEEN ? AND ? AND st.deleted_at IS NULL ORDER BY st.transaction_date DESC, outlet_name ASC");
$stmt->execute([$company_id, $client_id, $date_from, $date_to]);
$raw_sales = $stmt->fetchAll();

$sales_by_date = [];
$recon_stats = ['pos'=>0, 'cash'=>0, 'transfer'=>0, 'actual'=>0, 'variance'=>0];

foreach ($raw_sales as $row) {
    $date = $row['transaction_date'];
    if (!isset($sales_by_date[$date])) {
        $sales_by_date[$date] = [
            'date' => $date,
            'shifts' => [],
            'total_actual' => 0,
            'total_declared' => 0,
            'total_variance' => 0
        ];
    }
    $sales_by_date[$date]['shifts'][] = $row;
    $sales_by_date[$date]['total_actual'] += $row['actual_total'];
    $sales_by_date[$date]['total_declared'] += $row['declared_total'];
    $sales_by_date[$date]['total_variance'] += $row['variance'];

    // Grand totals
    $recon_stats['pos'] += $row['pos_amount'];
    $recon_stats['cash'] += $row['cash_amount'];
    $recon_stats['transfer'] += $row['other_amount'];
    $recon_stats['actual'] += $row['actual_total'];
    $recon_stats['variance'] += $row['variance'];
}

// Convert to indexed array for JS
$sales_recon_grouped = array_values($sales_by_date);

// ---- 2. Stock Count Variance ----
$stmt = $pdo->prepare("SELECT pc.count_date, p.name as product_name, p.category, p.unit_cost, pc.system_count, pc.physical_count, (pc.physical_count - pc.system_count) as variance, ((pc.physical_count - pc.system_count) * p.unit_cost) as variance_value, pc.notes, u.first_name, u.last_name FROM physical_counts pc LEFT JOIN products p ON pc.product_id = p.id LEFT JOIN users u ON pc.counted_by = u.id WHERE pc.company_id = ? AND pc.client_id = ? AND pc.count_date BETWEEN ? AND ? ORDER BY pc.count_date DESC, p.name");
$stmt->execute([$company_id, $client_id, $date_from, $date_to]);
$stock_counts = $stmt->fetchAll();
$count_shortages = array_filter($stock_counts, fn($c) => ($c['physical_count'] - $c['system_count']) < 0);
$count_surplus   = array_filter($stock_counts, fn($c) => ($c['physical_count'] - $c['system_count']) > 0);
$shortage_value  = array_sum(array_map(fn($c) => abs(($c['physical_count'] - $c['system_count']) * $c['unit_cost']), $count_shortages));
$surplus_value   = array_sum(array_map(fn($c) => (($c['physical_count'] - $c['system_count']) * $c['unit_cost']), $count_surplus));

// ---- 2B. Stock Count Reconciliation (Department vs Daily Audit) ----
// Get all departments WITH outlet links
$stmt = $pdo->prepare("SELECT sd.id, sd.name, sd.type, sd.outlet_id, COALESCE(co.name, '') as outlet_name 
    FROM stock_departments sd 
    LEFT JOIN client_outlets co ON sd.outlet_id = co.id 
    WHERE sd.company_id = ? AND sd.client_id = ? AND sd.deleted_at IS NULL ORDER BY sd.name");
$stmt->execute([$company_id, $client_id]);
$recon_departments = $stmt->fetchAll();

$recon_sections = [];
$recon_grand_stock_sales = 0;
$recon_grand_declared = 0;

foreach ($recon_departments as $rd) {
    $dept_id = $rd['id'];
    $outlet_id = intval($rd['outlet_id'] ?? 0);
    
    // Get stock count data for this department on the report date
    $stmt = $pdo->prepare("SELECT ds.product_id, p.name as product_name, p.selling_price,
        ds.opening_stock, ds.added, ds.return_in, ds.transfer_out, ds.transfer_to_main, 
        ds.adjustment_add, ds.adjustment_sub, ds.qty_sold, ds.id as stock_id
        FROM department_stock ds 
        JOIN products p ON ds.product_id = p.id 
        WHERE ds.department_id = ? AND ds.stock_date = ? AND ds.company_id = ? AND ds.client_id = ?
        ORDER BY p.name");
    $stmt->execute([$dept_id, $date_to, $company_id, $client_id]);
    $dept_stock = $stmt->fetchAll();
    
    // Calculate stock count sales
    $stock_sales_total = 0;
    $stock_sold_qty = 0;
    $stock_products = [];
    foreach ($dept_stock as $ds) {
        $sys_total = intval($ds['opening_stock']) + intval($ds['added']) + intval($ds['return_in']) 
                   - intval($ds['transfer_out']) - intval($ds['transfer_to_main']);
        $adj = intval($ds['adjustment_add']) - intval($ds['adjustment_sub']);
        $qty_sold = intval($ds['qty_sold']);
        $sell_price = floatval($ds['selling_price']);
        $sales_value = $qty_sold * $sell_price;
        $is_counted = !empty($ds['stock_id']);
        
        $stock_products[] = [
            'name' => $ds['product_name'],
            'system_total' => $sys_total,
            'adj' => $adj,
            'physical' => ($sys_total + $adj) - $qty_sold,
            'qty_sold' => $qty_sold,
            'price' => $sell_price,
            'sales_value' => $sales_value,
            'counted' => $is_counted,
        ];
        $stock_sales_total += $sales_value;
        $stock_sold_qty += $qty_sold;
    }
    
    // Get outlet sales from daily audit
    $outlet_sales = [];
    $declared_total = 0;
    $actual_total = 0;
    if ($outlet_id) {
        $stmt = $pdo->prepare("SELECT shift, 
            COALESCE(pos_amount,0) as pos_amount, 
            COALESCE(cash_amount,0) as cash_amount, 
            COALESCE(transfer_amount,0) as transfer_amount, 
            COALESCE(declared_total,0) as declared_total,
            (COALESCE(pos_amount,0)+COALESCE(cash_amount,0)+COALESCE(transfer_amount,0)) as actual_total
            FROM sales_transactions 
            WHERE company_id = ? AND client_id = ? AND outlet_id = ? AND transaction_date = ? AND deleted_at IS NULL 
            ORDER BY shift");
        $stmt->execute([$company_id, $client_id, $outlet_id, $date_to]);
        $outlet_sales = $stmt->fetchAll();
        $declared_total = array_sum(array_column($outlet_sales, 'declared_total'));
        $actual_total = array_sum(array_column($outlet_sales, 'actual_total'));
    }
    
    $variance = $declared_total - $stock_sales_total;
    
    // Determine status
    if (!$outlet_id) $recon_status = 'not_linked';
    elseif (empty($outlet_sales)) $recon_status = 'no_audit';
    elseif (empty($dept_stock)) $recon_status = 'no_count';
    elseif (abs($variance) < 0.01) $recon_status = 'matched';
    else $recon_status = 'variance';
    
    $recon_sections[] = [
        'dept_name' => $rd['name'],
        'dept_type' => $rd['type'],
        'outlet_id' => $outlet_id,
        'outlet_name' => $rd['outlet_name'],
        'products' => $stock_products,
        'stock_sales_total' => $stock_sales_total,
        'stock_sold_qty' => $stock_sold_qty,
        'outlet_sales' => $outlet_sales,
        'declared_total' => $declared_total,
        'actual_total' => $actual_total,
        'variance' => $variance,
        'status' => $recon_status,
    ];
    
    $recon_grand_stock_sales += $stock_sales_total;
    $recon_grand_declared += $declared_total;
}
$recon_grand_variance = $recon_grand_declared - $recon_grand_stock_sales;

// ---- 3. Stock Valuation (Full - Main Store + All Departments) ----
$valuation_date = $date_to;

// Get all departments for this client
$stmt = $pdo->prepare("SELECT id, name, type FROM stock_departments WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL ORDER BY name");
$stmt->execute([$company_id, $client_id]);
$report_departments = $stmt->fetchAll();

// Main Store valuation
$stmt = $pdo->prepare("SELECT p.id, p.name, p.category, p.current_stock, p.unit_cost, p.selling_price FROM products p WHERE p.company_id = ? AND p.client_id = ? AND p.deleted_at IS NULL ORDER BY p.name");
$stmt->execute([$company_id, $client_id]);
$main_products = $stmt->fetchAll();

$valuation_data = [];
$total_valuation = 0;

$main_items = [];
$main_total = 0;
$main_sell_total = 0;
foreach ($main_products as $p) {
    $closing = (int)$p['current_stock'];
    $cost_val = $closing * (float)$p['unit_cost'];
    $sell_val = $closing * (float)$p['selling_price'];
    if ($closing == 0) continue;
    $main_items[] = [
        'product_name' => $p['name'],
        'category' => $p['category'] ?? '',
        'closing' => $closing,
        'unit_cost' => (float)$p['unit_cost'],
        'selling_price' => (float)$p['selling_price'],
        'cost_value' => $cost_val,
        'sell_value' => $sell_val,
    ];
    $main_total += $cost_val;
    $main_sell_total += $sell_val;
}
if (!empty($main_items)) {
    $valuation_data[] = [
        'dept_name' => 'Main Store',
        'dept_type' => 'main',
        'items' => $main_items,
        'total_cost' => $main_total,
        'total_sell' => $main_sell_total,
    ];
    $total_valuation += $main_total;
}

// Department sections
foreach ($report_departments as $dept) {
    $stmt = $pdo->prepare("SELECT DISTINCT ds.product_id, p.name as product_name, p.category, p.unit_cost, p.selling_price FROM department_stock ds JOIN products p ON ds.product_id = p.id WHERE ds.department_id = ? AND ds.company_id = ? AND ds.client_id = ? ORDER BY p.name");
    $stmt->execute([$dept['id'], $company_id, $client_id]);
    $dept_products = $stmt->fetchAll();

    $dept_items = [];
    $dept_total = 0;
    $dept_sell_total = 0;
    foreach ($dept_products as $dp) {
        $pb_stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(d.opening_stock,0) + COALESCE(d.added,0) + COALESCE(d.return_in,0) - COALESCE(d.transfer_out,0) - COALESCE(d.transfer_to_main,0) - COALESCE(d.qty_sold,0)), 0) as closing FROM department_stock d WHERE d.department_id = ? AND d.product_id = ? AND d.stock_date <= ? AND d.company_id = ? AND d.client_id = ?");
        $pb_stmt->execute([$dept['id'], $dp['product_id'], $valuation_date, $company_id, $client_id]);
        $closing = (int)$pb_stmt->fetchColumn();
        if ($closing == 0) continue;
        $cost_val = $closing * (float)$dp['unit_cost'];
        $sell_val = $closing * (float)$dp['selling_price'];
        $dept_items[] = [
            'product_name' => $dp['product_name'],
            'category' => $dp['category'] ?? '',
            'closing' => $closing,
            'unit_cost' => (float)$dp['unit_cost'],
            'selling_price' => (float)$dp['selling_price'],
            'cost_value' => $cost_val,
            'sell_value' => $sell_val,
        ];
        $dept_total += $cost_val;
        $dept_sell_total += $sell_val;
    }
    if (!empty($dept_items)) {
        $valuation_data[] = [
            'dept_name' => $dept['name'],
            'dept_type' => $dept['type'] ?? 'department',
            'items' => $dept_items,
            'total_cost' => $dept_total,
            'total_sell' => $dept_sell_total,
        ];
        $total_valuation += $dept_total;
    }
}

// Backward-compat variables
$valuation_products = $main_products;
$valuation_total = $total_valuation;
$valuation_by_cat = [];
foreach ($main_items as $vp) {
    $cat = $vp['category'] ?: 'Uncategorized';
    if (!isset($valuation_by_cat[$cat])) $valuation_by_cat[$cat] = ['items' => [], 'total' => 0];
    $valuation_by_cat[$cat]['items'][] = $vp;
    $valuation_by_cat[$cat]['total'] += $vp['cost_value'];
}

// ---- 4. Financial Summary ----
$stmt = $pdo->prepare("SELECT COALESCE(SUM(actual_total),0) as revenue FROM sales_transactions WHERE company_id = ? AND client_id = ? AND transaction_date BETWEEN ? AND ? AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id, $date_from, $date_to]);
$total_revenue = $stmt->fetch()['revenue'];

$stmt = $pdo->prepare("SELECT ec.name, ec.type, COALESCE(SUM(ee.amount),0) as total FROM expense_entries ee JOIN expense_categories ec ON ee.category_id = ec.id WHERE ee.company_id = ? AND ee.client_id = ? AND ee.entry_date BETWEEN ? AND ? AND ee.deleted_at IS NULL GROUP BY ec.name, ec.type ORDER BY total DESC");
$stmt->execute([$company_id, $client_id, $date_from, $date_to]);
$expense_breakdown = $stmt->fetchAll();
$total_expenses = array_sum(array_column($expense_breakdown, 'total'));
$net = $total_revenue - $total_expenses;
$opex = array_sum(array_column(array_filter($expense_breakdown, fn($e) => $e['type']==='operating'), 'total'));

// ---- 5. Expense Detail ----
$stmt = $pdo->prepare("SELECT ee.entry_date, ec.name as category, ee.description, ee.amount, ee.payment_method, ee.vendor as vendor_name, u.first_name, u.last_name FROM expense_entries ee LEFT JOIN expense_categories ec ON ee.category_id = ec.id LEFT JOIN users u ON ee.entered_by = u.id WHERE ee.company_id = ? AND ee.client_id = ? AND ee.entry_date BETWEEN ? AND ? AND ee.deleted_at IS NULL ORDER BY ee.entry_date DESC");
$stmt->execute([$company_id, $client_id, $date_from, $date_to]);
$expense_detail = $stmt->fetchAll();

// ---- 6. Requisitions ----
$stmt = $pdo->prepare("SELECT r.requisition_number, r.department, r.title as purpose, r.total_amount, r.priority, r.status, r.created_at, u.first_name, u.last_name FROM requisitions r JOIN users u ON r.requested_by = u.id WHERE r.company_id = ? AND r.client_id = ? AND DATE(r.created_at) BETWEEN ? AND ? AND r.deleted_at IS NULL ORDER BY r.created_at DESC");
$stmt->execute([$company_id, $client_id, $date_from, $date_to]);
$req_report = $stmt->fetchAll();

// ---- 7. Bank Lodgments ----
$stmt = $pdo->prepare("SELECT bl.lodgment_date, bl.bank_name, bl.account_number, bl.amount, bl.reference_number, bl.source, bl.status, bl.confirmed_by, bl.notes, u.first_name as lodged_first, u.last_name as lodged_last, u2.first_name as conf_first, u2.last_name as conf_last FROM bank_lodgments bl LEFT JOIN users u ON bl.lodged_by = u.id LEFT JOIN users u2 ON bl.confirmed_by = u2.id WHERE bl.company_id = ? AND bl.client_id = ? AND bl.lodgment_date BETWEEN ? AND ? ORDER BY bl.lodgment_date DESC");
$stmt->execute([$company_id, $client_id, $date_from, $date_to]);
$lodgments = $stmt->fetchAll();
$total_lodged = array_sum(array_column($lodgments, 'amount'));
$confirmed_lodgments = count(array_filter($lodgments, fn($l) => $l['status'] === 'confirmed'));

// ---- 8. Variance Reports ----
$stmt = $pdo->prepare("SELECT v.id, v.category, v.severity, v.variance_amount, v.description, v.status, v.report_date, v.resolved_at, v.resolution, u.first_name as res_first, u.last_name as res_last FROM variance_reports v LEFT JOIN users u ON v.resolved_by = u.id WHERE v.company_id = ? AND v.client_id = ? AND v.report_date BETWEEN ? AND ? ORDER BY v.report_date DESC");
$stmt->execute([$company_id, $client_id, $date_from, $date_to]);
$variance_reports = $stmt->fetchAll();

// ---- 9. Audit Sign-Offs ----
$stmt = $pdo->prepare("SELECT das.audit_date, das.total_revenue, das.status, das.auditor_signed_at, das.auditor_comments, das.manager_signed_at, das.manager_comments, u1.first_name as aud_first, u1.last_name as aud_last, u2.first_name as mgr_first, u2.last_name as mgr_last FROM daily_audit_signoffs das LEFT JOIN users u1 ON das.auditor_id = u1.id LEFT JOIN users u2 ON das.manager_id = u2.id WHERE das.company_id = ? AND das.client_id = ? AND das.audit_date BETWEEN ? AND ? ORDER BY das.audit_date DESC");
$stmt->execute([$company_id, $client_id, $date_from, $date_to]);
$signoffs = $stmt->fetchAll();

// Today's transactions
$today_sales = array_filter($sales_recon_grouped, fn($d) => $d['date'] === $date_to);
$today_sales = array_values($today_sales);

// Last 14 days history
$hist_from = date('Y-m-d', strtotime('-14 days'));
$hist_to   = date('Y-m-d');
$stmt_hist = $pdo->prepare("SELECT st.transaction_date, st.shift, COALESCE(co.name, st.shift, 'Main') as outlet_name, st.pos_amount, st.cash_amount, st.transfer_amount, st.other_amount, st.declared_total, st.actual_total, st.variance, st.notes FROM sales_transactions st LEFT JOIN client_outlets co ON st.outlet_id = co.id WHERE st.company_id = ? AND st.client_id = ? AND st.transaction_date BETWEEN ? AND ? AND st.transaction_date != ? AND st.deleted_at IS NULL ORDER BY st.transaction_date DESC, outlet_name ASC");
$stmt_hist->execute([$company_id, $client_id, $hist_from, $hist_to, $date_to]);
$raw_hist = $stmt_hist->fetchAll();
$hist_by_date = [];
foreach ($raw_hist as $row) {
    $d = $row['transaction_date'];
    if (!isset($hist_by_date[$d])) $hist_by_date[$d] = ['date' => $d, 'shifts' => [], 'total_actual' => 0, 'total_declared' => 0, 'total_variance' => 0];
    $hist_by_date[$d]['shifts'][] = $row;
    $hist_by_date[$d]['total_actual'] += $row['actual_total'];
    $hist_by_date[$d]['total_declared'] += $row['declared_total'];
    $hist_by_date[$d]['total_variance'] += $row['variance'];
}
$history_sales = array_values($hist_by_date);

// ---- Option 1 Computed Values ----
$total_declared = array_sum(array_column($raw_sales, 'declared_total'));
$total_other    = array_sum(array_column($raw_sales, 'other_amount'));
$recon_rate     = $total_declared > 0 ? round(($recon_stats['actual'] / $total_declared) * 100, 1) : 0;

// Payment method percentages
$pm_total = $recon_stats['pos'] + $recon_stats['cash'] + $recon_stats['transfer'];
$pm_pos_pct = $pm_total > 0 ? round(($recon_stats['pos'] / $pm_total) * 100, 1) : 0;
$pm_cash_pct = $pm_total > 0 ? round(($recon_stats['cash'] / $pm_total) * 100, 1) : 0;
$pm_transfer_pct = $pm_total > 0 ? round(($recon_stats['transfer'] / $pm_total) * 100, 1) : 0;

// Per-day variance analysis
$shortage_days = 0; $surplus_days = 0; $balanced_days = 0;
foreach ($sales_recon_grouped as $day_data) {
    if ($day_data['total_variance'] < 0) $shortage_days++;
    elseif ($day_data['total_variance'] > 0) $surplus_days++;
    else $balanced_days++;
}
$total_days = $shortage_days + $surplus_days + $balanced_days;
$shortage_pct = $total_days > 0 ? round(($shortage_days / $total_days) * 100) : 0;
$surplus_pct  = $total_days > 0 ? round(($surplus_days / $total_days) * 100) : 0;
$balanced_pct = $total_days > 0 ? round(($balanced_days / $total_days) * 100) : 0;
// Donut chart: determine dominant status
$dominant_status = 'BALANCED';
$dominant_pct = $balanced_pct;
if ($shortage_pct > $surplus_pct && $shortage_pct > $balanced_pct) { $dominant_status = 'SHORT'; $dominant_pct = $shortage_pct; }
elseif ($surplus_pct > $shortage_pct && $surplus_pct > $balanced_pct) { $dominant_status = 'OVER'; $dominant_pct = $surplus_pct; }

// ---- Outlet Metrics Computation ----
$outlet_metrics = [];
foreach ($raw_sales as $row) {
    $name = $row['outlet_name'] ?? 'Main';
    if (!isset($outlet_metrics[$name])) {
        $outlet_metrics[$name] = ['name' => $name, 'transactions' => 0, 'pos' => 0, 'cash' => 0, 'transfer' => 0, 'actual' => 0, 'declared' => 0, 'variance' => 0];
    }
    $outlet_metrics[$name]['transactions']++;
    $outlet_metrics[$name]['pos'] += $row['pos_amount'];
    $outlet_metrics[$name]['cash'] += $row['cash_amount'];
    $outlet_metrics[$name]['transfer'] += $row['other_amount'];
    $outlet_metrics[$name]['actual'] += $row['actual_total'];
    $outlet_metrics[$name]['declared'] += $row['declared_total'];
    $outlet_metrics[$name]['variance'] += $row['variance'];
}
$outlet_metrics = array_values($outlet_metrics);
// Sort by actual amount descending
usort($outlet_metrics, fn($a, $b) => $b['actual'] <=> $a['actual']);
$total_outlet_actual = array_sum(array_column($outlet_metrics, 'actual'));

$js_today   = json_encode($today_sales, JSON_HEX_TAG|JSON_HEX_APOS);
$js_history = json_encode($history_sales, JSON_HEX_TAG|JSON_HEX_APOS);
$js_sales   = json_encode($sales_recon_grouped, JSON_HEX_TAG|JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>
        [x-cloak]{display:none!important}
        .glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95) 0%,rgba(249,250,251,0.9) 100%);backdrop-filter:blur(20px)}
        .dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95) 0%,rgba(30,41,59,0.9) 100%)}
        @media print {
            body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print, .print-header { display: none !important; }
            nav, aside, header, [x-data="sidebarComponent"], #collapsed-toolbar, #mobile-menu-btn, button, form { display: none !important; }
            .printable-summary { display: block !important; }
            .printable-summary .grid.lg\:grid-cols-2 {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                gap: 12px !important;
            }
            .printable-summary .grid.grid-cols-4 {
                display: grid !important;
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 8px !important;
            }
            .printable-summary .grid.grid-cols-3 {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 8px !important;
            }
            main { margin: 0 !important; padding: 0 !important; overflow: visible !important; height: auto !important; }
            #report-document { box-shadow: none !important; border: none !important; max-width: 100% !important; overflow: visible !important; }
            .dashboard-header, .dashboard-sidebar, .main-nav { display: none !important; }
            /* Fix h-screen/overflow clipping for print */
            .flex.h-screen { height: auto !important; overflow: visible !important; }
            .flex-1.overflow-y-auto, .flex-1.overflow-hidden { overflow: visible !important; height: auto !important; }
            /* Ensure Section 4 flex layout renders */
            .printable-summary .flex.items-center.gap-6 { display: flex !important; }
            .printable-summary .relative.w-28.h-28 { width: 112px !important; height: 112px !important; }
            /* Ensure all report content sections are visible */
            .printable-summary div { overflow: visible !important; }
            .printable-summary .space-y-8 > div { page-break-inside: avoid; }
            /* Print table fit — compact rows to fit page width */
            .printable-summary table { table-layout: fixed !important; width: 100% !important; font-size: 8px !important; }
            .printable-summary table th, .printable-summary table td { padding: 2px 4px !important; font-size: 8px !important; white-space: nowrap !important; }
            .printable-summary table th:first-child, .printable-summary table td:first-child { white-space: normal !important; width: 15% !important; }
            /* Print footer on every page */
            .print-page-footer { display: block !important; position: fixed; bottom: 0; left: 0; right: 0; text-align: center; padding: 8px 0; border-top: 1px solid #e2e8f0; background: white; z-index: 9999; }
            .print-page-footer p { margin: 0; font-size: 7px; color: #64748b; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
            /* Add bottom margin on every page to make room for footer */
            @page { margin-bottom: 40px; }
        }
    </style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="reportApp()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8">
    <!-- Filter Bar -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg"><i data-lucide="file-bar-chart" class="w-4 h-4 text-white"></i></span>
                Reports
            </h1>
            <p class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars($client_name); ?> &bull; <?php echo $date_label; ?></p>
        </div>
        <form method="GET" class="flex items-center gap-3 no-print">
            <div>
                <label class="text-[10px] font-bold text-slate-400 uppercase">From</label>
                <input type="date" name="from" value="<?php echo $date_from; ?>" class="px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-semibold w-36">
            </div>
            <div>
                <label class="text-[10px] font-bold text-slate-400 uppercase">To</label>
                <input type="date" name="to" value="<?php echo $date_to; ?>" class="px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-semibold w-36">
            </div>
            <button type="submit" class="mt-4 px-5 py-2 bg-gradient-to-r from-violet-500 to-purple-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all">Apply</button>
        </form>
        <div class="flex gap-2 no-print">
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>?from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>&export=csv" class="px-4 py-2 bg-emerald-500 text-white text-xs font-bold rounded-xl shadow hover:bg-emerald-600 transition-all flex items-center gap-2"><i data-lucide="file-spreadsheet" class="w-3.5 h-3.5"></i> CSV</a>
            <button onclick="window.print()" class="px-4 py-2 bg-gradient-to-r from-slate-800 to-slate-900 text-white text-xs font-bold rounded-xl shadow hover:scale-105 transition-all flex items-center gap-2"><i data-lucide="printer" class="w-3.5 h-3.5"></i> Download PDF</button>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="flex flex-wrap gap-1 mb-6 no-print">
        <template x-for="tab in tabs" :key="tab.id">
            <button @click="currentTab = tab.id"
                :class="currentTab === tab.id ? 'bg-white dark:bg-slate-800 shadow-lg text-violet-600 border-violet-200' : 'text-slate-500 hover:bg-white/50'"
                class="px-4 py-2 rounded-xl text-xs font-bold transition-all border border-transparent flex items-center gap-1.5">
                <i :data-lucide="tab.icon" class="w-3.5 h-3.5"></i>
                <span x-text="tab.label"></span>
            </button>
        </template>
    </div>

    <!-- ===================== TAB 1: SALES RECONCILIATION (PDF Report) ===================== -->
    <div x-show="currentTab === 'recon'" x-transition>
        <div id="report-document" class="printable-summary bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden max-w-5xl mx-auto">
            <!-- DOCUMENT HEADER -->
            <div class="px-10 py-6 border-b border-slate-200 flex justify-between items-start">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg"><i data-lucide="shield-check" class="w-6 h-6 text-white"></i></div>
                    <div>
                        <h2 class="text-[10px] font-black text-violet-600 uppercase tracking-widest">MIAUDITOPS</h2>
                        <h1 class="text-xl font-black text-slate-900 leading-tight">Daily Audit & Sales<br>Reconciliation Report</h1>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-sm font-black text-slate-900 uppercase"><?php echo htmlspecialchars($client_name); ?></p>
                    <p class="text-[10px] font-bold text-slate-400 uppercase"><?php echo htmlspecialchars($company_name); ?></p>
                    <p class="text-xs font-bold text-slate-500"><?php echo date('F j, Y', strtotime($date_to)); ?></p>
                </div>
            </div>
            <div class="px-10 pb-2 text-center border-b border-slate-100">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Miauditops: powered by Miemploya</p>
            </div>

            <!-- REPORT CONTENT -->
            <div class="px-10 py-6 space-y-8">
<!-- SECTION 1 - RECONCILIATION SUMMARY -->
<div>
    <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-4">Section 1 - Reconciliation Summary</p>
    <div class="grid grid-cols-4 gap-3">
        <div class="p-3 rounded-xl border border-blue-200 bg-gradient-to-br from-blue-50 to-blue-100/50 relative overflow-hidden">
            <p class="text-lg font-black text-blue-700"><?php echo format_currency($total_declared); ?></p>
            <p class="text-[9px] font-bold text-blue-400 uppercase mt-1">Total Declared</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full bg-blue-200 opacity-30"></div>
        </div>
        <div class="p-3 rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-emerald-100/50 relative overflow-hidden">
            <p class="text-lg font-black text-emerald-700"><?php echo format_currency($recon_stats['actual']); ?></p>
            <p class="text-[9px] font-bold text-emerald-400 uppercase mt-1">Total Actual</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full bg-emerald-200 opacity-30"></div>
        </div>
        <div class="p-3 rounded-xl border <?php echo $recon_stats['variance'] < 0 ? 'border-red-200 bg-gradient-to-br from-red-50 to-red-100/50' : ($recon_stats['variance'] > 0 ? 'border-amber-200 bg-gradient-to-br from-amber-50 to-amber-100/50' : 'border-slate-200 bg-gradient-to-br from-slate-50 to-slate-100/50'); ?> relative overflow-hidden">
            <div class="flex items-center gap-1">
                <p class="text-lg font-black <?php echo $recon_stats['variance'] < 0 ? 'text-red-700' : ($recon_stats['variance'] > 0 ? 'text-amber-700' : 'text-slate-700'); ?>"><?php echo format_currency($recon_stats['variance']); ?></p>
                <?php if ($recon_stats['variance'] != 0): ?>
                <svg class="w-4 h-4 <?php echo $recon_stats['variance'] < 0 ? 'text-red-500' : 'text-amber-500'; ?>" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                <?php endif; ?>
            </div>
            <p class="text-[9px] font-bold <?php echo $recon_stats['variance'] < 0 ? 'text-red-400' : 'text-amber-400'; ?> uppercase mt-1">Net Variance</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full <?php echo $recon_stats['variance'] < 0 ? 'bg-red-200' : 'bg-amber-200'; ?> opacity-30"></div>
        </div>
        <div class="p-3 rounded-xl border border-slate-200 bg-gradient-to-br from-slate-50 to-slate-100/50 relative overflow-hidden">
            <p class="text-lg font-black text-slate-700"><?php echo $recon_rate; ?>%</p>
            <p class="text-[9px] font-bold text-slate-400 uppercase mt-1">Reconciliation Rate</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full bg-slate-200 opacity-30"></div>
        </div>
    </div>
</div>

<!-- SECTION 2 & SECTION 4 SIDE BY SIDE -->
<div class="grid lg:grid-cols-2 gap-6">
    <!-- SECTION 2 - PAYMENT METHOD BREAKDOWN -->
    <div>
        <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-4">Section 2 - Payment Method Breakdown</p>
        <div class="border border-slate-200 rounded-xl p-4 space-y-3">
            <!-- POS -->
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold text-slate-700 w-44">POS: <?php echo format_currency($recon_stats['pos']); ?> (<?php echo $pm_pos_pct; ?>%)</span>
                <div class="flex-1 mx-3 h-3 bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full bg-blue-500 rounded-full transition-all" style="width: <?php echo $pm_pos_pct; ?>%"></div>
                </div>
                <span class="text-[10px] font-bold text-slate-500 w-12 text-right"><?php echo $pm_pos_pct; ?>%</span>
            </div>
            <!-- Cash -->
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold text-slate-700 w-44">Cash: <?php echo format_currency($recon_stats['cash']); ?> (<?php echo $pm_cash_pct; ?>%)</span>
                <div class="flex-1 mx-3 h-3 bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full bg-emerald-500 rounded-full transition-all" style="width: <?php echo $pm_cash_pct; ?>%"></div>
                </div>
                <span class="text-[10px] font-bold text-slate-500 w-12 text-right"><?php echo $pm_cash_pct; ?>%</span>
            </div>
            <!-- Transfer -->
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold text-slate-700 w-44">Transfer: <?php echo format_currency($recon_stats['transfer']); ?> (<?php echo $pm_transfer_pct; ?>%)</span>
                <div class="flex-1 mx-3 h-3 bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full bg-violet-500 rounded-full transition-all" style="width: <?php echo $pm_transfer_pct; ?>%"></div>
                </div>
                <span class="text-[10px] font-bold text-slate-500 w-12 text-right"><?php echo $pm_transfer_pct; ?>%</span>
            </div>
        </div>
    </div>

    <!-- SECTION 4 - VARIANCE ANALYSIS -->
    <div>
        <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-4">Section 4 - Variance Analysis</p>
        <div class="border border-slate-200 rounded-xl p-4 flex items-center gap-6">
            <!-- Day Counts -->
            <div class="space-y-3 flex-1">
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 rounded-full bg-red-500 shrink-0"></span>
                    <span class="text-[11px] font-semibold text-slate-700">Shortage Days:</span>
                    <span class="text-[11px] font-black text-red-600 ml-auto"><?php echo $shortage_days; ?></span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 rounded-full bg-blue-500 shrink-0"></span>
                    <span class="text-[11px] font-semibold text-slate-700">Surplus Days:</span>
                    <span class="text-[11px] font-black text-blue-600 ml-auto"><?php echo $surplus_days; ?></span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 rounded-full bg-emerald-500 shrink-0"></span>
                    <span class="text-[11px] font-semibold text-slate-700">Balanced Days:</span>
                    <span class="text-[11px] font-black text-emerald-600 ml-auto"><?php echo $balanced_days; ?></span>
                </div>
            </div>
            <!-- SVG Donut Chart -->
            <div class="relative w-28 h-28 shrink-0">
                <?php
                $r = 40; $cx = 50; $cy = 50; $circ = 2 * 3.14159 * $r;
                $seg1 = $circ * ($shortage_pct / 100); $seg2 = $circ * ($surplus_pct / 100); $seg3 = $circ * ($balanced_pct / 100);
                $off1 = 0; $off2 = $seg1; $off3 = $seg1 + $seg2;
                ?>
                <svg viewBox="0 0 100 100" class="w-full h-full -rotate-90">
                    <?php if ($shortage_pct > 0): ?>
                    <circle cx="<?php echo $cx; ?>" cy="<?php echo $cy; ?>" r="<?php echo $r; ?>" fill="none" stroke="#ef4444" stroke-width="12" stroke-dasharray="<?php echo $seg1; ?> <?php echo $circ - $seg1; ?>" stroke-dashoffset="-<?php echo $off1; ?>" stroke-linecap="round"/>
                    <?php endif; ?>
                    <?php if ($surplus_pct > 0): ?>
                    <circle cx="<?php echo $cx; ?>" cy="<?php echo $cy; ?>" r="<?php echo $r; ?>" fill="none" stroke="#3b82f6" stroke-width="12" stroke-dasharray="<?php echo $seg2; ?> <?php echo $circ - $seg2; ?>" stroke-dashoffset="-<?php echo $off2; ?>" stroke-linecap="round"/>
                    <?php endif; ?>
                    <?php if ($balanced_pct > 0): ?>
                    <circle cx="<?php echo $cx; ?>" cy="<?php echo $cy; ?>" r="<?php echo $r; ?>" fill="none" stroke="#10b981" stroke-width="12" stroke-dasharray="<?php echo $seg3; ?> <?php echo $circ - $seg3; ?>" stroke-dashoffset="-<?php echo $off3; ?>" stroke-linecap="round"/>
                    <?php endif; ?>
                    <?php if ($total_days == 0): ?>
                    <circle cx="<?php echo $cx; ?>" cy="<?php echo $cy; ?>" r="<?php echo $r; ?>" fill="none" stroke="#e2e8f0" stroke-width="12"/>
                    <?php endif; ?>
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-xl font-black text-slate-800"><?php echo $dominant_pct; ?>%</span>
                    <span class="text-[8px] font-bold text-slate-400 uppercase tracking-wider"><?php echo $dominant_status; ?></span>
                </div>
            </div>
        </div>
        <!-- Legend -->
        <div class="flex items-center gap-4 mt-2 justify-center">
            <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-red-500"></span><span class="text-[9px] text-slate-500 font-semibold">Shortage</span></div>
            <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-blue-500"></span><span class="text-[9px] text-slate-500 font-semibold">Surplus</span></div>
            <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-emerald-500"></span><span class="text-[9px] text-slate-500 font-semibold">Balanced</span></div>
        </div>
    </div>
</div><!-- SECTION 4B - OUTLET METRICS -->
<div>
    <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-4">Section 4B - Outlet Performance Metrics</p>
    <?php if (!empty($outlet_metrics)): ?>
    <div class="border border-slate-200 rounded-xl overflow-hidden">
        <table class="w-full text-[10px] text-left border-collapse">
            <thead>
                <tr class="bg-indigo-50 border-b border-indigo-200">
                    <th class="px-3 py-2 font-black text-indigo-500 uppercase text-[9px]">Outlet</th>
                    <th class="px-3 py-2 text-center font-black text-indigo-500 uppercase text-[9px]">Txns</th>
                    <th class="px-3 py-2 text-right font-black text-indigo-500 uppercase text-[9px]">POS</th>
                    <th class="px-3 py-2 text-right font-black text-indigo-500 uppercase text-[9px]">Cash</th>
                    <th class="px-3 py-2 text-right font-black text-indigo-500 uppercase text-[9px]">Transfer</th>
                    <th class="px-3 py-2 text-right font-black text-indigo-500 uppercase text-[9px]">Actual</th>
                    <th class="px-3 py-2 text-right font-black text-indigo-500 uppercase text-[9px]">Declared</th>
                    <th class="px-3 py-2 text-right font-black text-indigo-500 uppercase text-[9px]">Variance</th>
                    <th class="px-3 py-2 text-right font-black text-indigo-500 uppercase text-[9px]">Share</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($outlet_metrics as $om):
                    $share = $total_outlet_actual > 0 ? round(($om['actual'] / $total_outlet_actual) * 100, 1) : 0;
                ?>
                <tr class="border-b border-slate-100 hover:bg-indigo-50/30">
                    <td class="px-3 py-2 font-bold text-slate-700 capitalize"><?php echo htmlspecialchars($om['name']); ?></td>
                    <td class="px-3 py-2 text-center font-semibold text-slate-600"><?php echo $om['transactions']; ?></td>
                    <td class="px-3 py-2 text-right font-semibold"><?php echo format_currency($om['pos']); ?></td>
                    <td class="px-3 py-2 text-right font-semibold"><?php echo format_currency($om['cash']); ?></td>
                    <td class="px-3 py-2 text-right font-semibold"><?php echo format_currency($om['transfer']); ?></td>
                    <td class="px-3 py-2 text-right font-semibold"><?php echo format_currency($om['actual']); ?></td>
                    <td class="px-3 py-2 text-right font-bold text-emerald-700"><?php echo format_currency($om['declared']); ?></td>
                    <td class="px-3 py-2 text-right font-bold <?php echo $om['variance'] < 0 ? 'text-red-600' : ($om['variance'] > 0 ? 'text-blue-600' : ''); ?>">
                        <?php echo format_currency($om['variance']); ?>
                    </td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center gap-1.5 justify-end">
                            <div class="w-12 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full bg-indigo-500 rounded-full" style="width: <?php echo $share; ?>%"></div>
                            </div>
                            <span class="text-[9px] font-bold text-indigo-600"><?php echo $share; ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-indigo-300 bg-indigo-50 font-black text-slate-800">
                    <td class="px-3 py-2.5 text-[10px]">Total</td>
                    <td class="px-3 py-2.5 text-center text-[10px]"><?php echo array_sum(array_column($outlet_metrics, 'transactions')); ?></td>
                    <td class="px-3 py-2.5 text-right text-[10px]"><?php echo format_currency(array_sum(array_column($outlet_metrics, 'pos'))); ?></td>
                    <td class="px-3 py-2.5 text-right text-[10px]"><?php echo format_currency(array_sum(array_column($outlet_metrics, 'cash'))); ?></td>
                    <td class="px-3 py-2.5 text-right text-[10px]"><?php echo format_currency(array_sum(array_column($outlet_metrics, 'transfer'))); ?></td>
                    <td class="px-3 py-2.5 text-right text-[10px]"><?php echo format_currency($total_outlet_actual); ?></td>
                    <td class="px-3 py-2.5 text-right text-[10px]"><?php echo format_currency(array_sum(array_column($outlet_metrics, 'declared'))); ?></td>
                    <td class="px-3 py-2.5 text-right text-[10px] <?php echo array_sum(array_column($outlet_metrics, 'variance')) < 0 ? 'text-red-600' : ''; ?>">
                        <?php echo format_currency(array_sum(array_column($outlet_metrics, 'variance'))); ?>
                    </td>
                    <td class="px-3 py-2.5 text-right text-[10px] font-bold text-indigo-600">100%</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
    <div class="border border-slate-200 rounded-xl p-6 text-center">
        <p class="text-sm text-slate-400">No outlet data available for this period</p>
    </div>
    <?php endif; ?>
</div>

<!-- SECTION 3 - DAILY SALES TABLE -->
<div>
    <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-4">Section 3 - Daily Sales Transactions
    </p>

    <!-- Today's Transactions -->
    <?php if (!empty($today_sales)): ?>
    <div class="mb-5">
        <p class="text-[10px] font-black text-violet-500 uppercase mb-2">Today's Transactions -
            <?php echo date('M j, Y', strtotime($date_to)); ?>
        </p>
        <div class="border border-violet-200 rounded-xl overflow-hidden">
            <table class="w-full text-[10px] text-left border-collapse">
                <thead>
                    <tr class="bg-violet-50 border-b border-violet-200">
                        <th class="px-3 py-2 font-black text-violet-500 uppercase text-[9px]">Outlet</th>
                        <th class="px-3 py-2 text-right font-black text-violet-500 uppercase text-[9px]">POS</th>
                        <th class="px-3 py-2 text-right font-black text-violet-500 uppercase text-[9px]">Cash</th>
                        <th class="px-3 py-2 text-right font-black text-violet-500 uppercase text-[9px]">Transfer</th>
                        <th class="px-3 py-2 text-right font-black text-violet-500 uppercase text-[9px]">Actual</th>
                        <th class="px-3 py-2 text-right font-black text-violet-500 uppercase text-[9px]">Declared</th>
                        <th class="px-3 py-2 text-right font-black text-violet-500 uppercase text-[9px]">Variance</th>
                        <th class="px-3 py-2 text-center font-black text-violet-500 uppercase text-[9px]">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($today_sales as $td):
                                    foreach ($td['shifts'] as $sh): ?>
                    <tr class="border-b border-slate-100 hover:bg-violet-50/30">
                        <td class="px-3 py-2 font-bold text-slate-700 capitalize">
                            <?php echo htmlspecialchars($sh['outlet_name'] ?? 'Main'); ?>
                        </td>
                        <td class="px-3 py-2 text-right font-semibold">
                            <?php echo format_currency($sh['pos_amount']); ?>
                        </td>
                        <td class="px-3 py-2 text-right font-semibold">
                            <?php echo format_currency($sh['cash_amount']); ?>
                        </td>
                        <td class="px-3 py-2 text-right font-semibold">
                            <?php echo format_currency($sh['other_amount']); ?>
                        </td>
                        <td class="px-3 py-2 text-right font-semibold">
                            <?php echo format_currency($sh['declared_total']); ?>
                        </td>
                        <td class="px-3 py-2 text-right font-bold text-emerald-700">
                            <?php echo format_currency($sh['actual_total']); ?>
                        </td>
                        <td
                            class="px-3 py-2 text-right font-bold <?php echo $sh['variance'] < 0 ? 'text-red-600' : ''; ?>">
                            <?php echo format_currency($sh['variance']); ?>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <?php echo $sh['variance'] == 0 ? '<span class="text-emerald-500 text-[9px] font-black">BALANCED</span>' : ($sh['variance'] < 0 ? '<span class="text-red-500 text-[9px] font-black">SHORT</span>' : '<span class="text-blue-500 text-[9px] font-black">OVER</span>'); ?>
                        </td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- 14-Day Sales History -->
    <?php if (!empty($history_sales)):
                        $h_totals = ['pos'=>0, 'cash'=>0, 'transfer'=>0, 'other'=>0, 'declared'=>0, 'actual'=>0, 'variance'=>0];
                    ?>
    <div>
        <p class="text-[10px] font-black text-slate-500 uppercase mb-2">14-Day Sales History</p>
        <div class="border border-slate-200 rounded-xl overflow-hidden">
            <table class="w-full text-[10px] text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-200">
                        <th class="px-3 py-2 font-black text-slate-500 uppercase text-[9px]">Date</th>
                        <th class="px-3 py-2 text-right font-black text-slate-500 uppercase text-[9px]">POS</th>
                        <th class="px-3 py-2 text-right font-black text-slate-500 uppercase text-[9px]">Cash</th>
                        <th class="px-3 py-2 text-right font-black text-slate-500 uppercase text-[9px]">Transfer</th>
                        <th class="px-3 py-2 text-right font-black text-slate-500 uppercase text-[9px]">Actual</th>
                        <th class="px-3 py-2 text-right font-black text-slate-500 uppercase text-[9px]">Declared</th>
                        <th class="px-3 py-2 text-right font-black text-slate-500 uppercase text-[9px]">Variance</th>
                        <th class="px-3 py-2 text-center font-black text-slate-500 uppercase text-[9px]">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history_sales as $hd):
                                    $day_pos = array_sum(array_column($hd['shifts'], 'pos_amount'));
                                    $day_cash = array_sum(array_column($hd['shifts'], 'cash_amount'));
                                    $day_transfer = array_sum(array_column($hd['shifts'], 'other_amount'));
                                    $day_other = array_sum(array_column($hd['shifts'], 'other_amount'));
                                    $day_declared = $hd['total_declared'];
                                    $day_actual = $hd['total_actual'];
                                    $day_variance = $hd['total_variance'];
                                    $h_totals['pos'] += $day_pos;
                                    $h_totals['cash'] += $day_cash;
                                    $h_totals['transfer'] += $day_transfer;
                                    $h_totals['other'] += $day_other;
                                    $h_totals['declared'] += $day_declared;
                                    $h_totals['actual'] += $day_actual;
                                    $h_totals['variance'] += $day_variance;
                                ?>
                    <tr class="border-b border-slate-100 hover:bg-slate-50/40">
                        <td class="px-3 py-2 font-bold text-slate-700">
                            <?php echo date('M j', strtotime($hd['date'])); ?>
                        </td>
                        <td class="px-3 py-2 text-right font-semibold">
                            <?php echo format_currency($day_pos); ?>
                        </td>
                        <td class="px-3 py-2 text-right font-semibold">
                            <?php echo format_currency($day_cash); ?>
                        </td>
                        <td class="px-3 py-2 text-right font-semibold">
                            <?php echo format_currency($day_transfer); ?>
                        </td>
                        <td class="px-3 py-2 text-right font-semibold">
                            <?php echo format_currency($day_declared); ?>
                        </td>
                        <td class="px-3 py-2 text-right font-bold">
                            <?php echo format_currency($day_actual); ?>
                        </td>
                        <td
                            class="px-3 py-2 text-right font-bold <?php echo $day_variance < 0 ? 'text-red-600' : ''; ?>">
                            <?php echo format_currency($day_variance); ?>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <?php echo $day_variance == 0 ? '<span class="text-emerald-500 text-[9px] font-black">OK</span>' : ($day_variance < 0 ? '<span class="text-red-500 text-[9px] font-black">SHORT</span>' : '<span class="text-blue-500 text-[9px] font-black">OVER</span>'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-300 bg-slate-50 font-black text-slate-800">
                        <td class="px-3 py-2.5 text-[10px]" colspan="1">Period Total</td>
                        <td class="px-3 py-2.5 text-right text-[10px]">
                            <?php echo format_currency($h_totals['pos']); ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-[10px]">
                            <?php echo format_currency($h_totals['cash']); ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-[10px]">
                            <?php echo format_currency($h_totals['transfer']); ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-[10px]">
                            <?php echo format_currency($h_totals['declared']); ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-[10px]">
                            <?php echo format_currency($h_totals['actual']); ?>
                        </td>
                        <td
                            class="px-3 py-2.5 text-right text-[10px] <?php echo $h_totals['variance'] < 0 ? 'text-red-600' : ''; ?>">
                            <?php echo format_currency($h_totals['variance']); ?>
                        </td>
                        <td class="px-3 py-2.5 text-center"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<!-- SECTION 5 - STOCK COUNT RECONCILIATION -->
<div x-show="showStockRecon">
    <div class="flex items-center justify-between mb-4">
        <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest">Section 5 - Stock Count Reconciliation</p>
        <label class="flex items-center gap-2 no-print cursor-pointer">
            <input type="checkbox" x-model="showStockRecon" checked class="w-3.5 h-3.5 rounded border-slate-300 text-purple-600 focus:ring-purple-500">
            <span class="text-[9px] font-bold text-slate-400 uppercase">Show in Report</span>
        </label>
    </div>

    <!-- Grand Summary Cards -->
    <div class="grid grid-cols-4 gap-3 mb-5">
        <div class="p-3 rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-emerald-100/50 relative overflow-hidden">
            <p class="text-lg font-black text-emerald-700"><?php echo format_currency($recon_grand_stock_sales); ?></p>
            <p class="text-[9px] font-bold text-emerald-400 uppercase mt-1">Stock Count Sales</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full bg-emerald-200 opacity-30"></div>
        </div>
        <div class="p-3 rounded-xl border border-blue-200 bg-gradient-to-br from-blue-50 to-blue-100/50 relative overflow-hidden">
            <p class="text-lg font-black text-blue-700"><?php echo format_currency($recon_grand_declared); ?></p>
            <p class="text-[9px] font-bold text-blue-400 uppercase mt-1">System Sales (Audit)</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full bg-blue-200 opacity-30"></div>
        </div>
        <div class="p-3 rounded-xl border <?php echo $recon_grand_variance == 0 ? 'border-slate-200 bg-gradient-to-br from-slate-50 to-slate-100/50' : ($recon_grand_variance > 0 ? 'border-amber-200 bg-gradient-to-br from-amber-50 to-amber-100/50' : 'border-red-200 bg-gradient-to-br from-red-50 to-red-100/50'); ?> relative overflow-hidden">
            <p class="text-lg font-black <?php echo $recon_grand_variance == 0 ? 'text-slate-700' : ($recon_grand_variance > 0 ? 'text-amber-700' : 'text-red-700'); ?>"><?php echo ($recon_grand_variance > 0 ? '+' : '') . format_currency($recon_grand_variance); ?></p>
            <p class="text-[9px] font-bold <?php echo $recon_grand_variance == 0 ? 'text-slate-400' : ($recon_grand_variance > 0 ? 'text-amber-400' : 'text-red-400'); ?> uppercase mt-1">Grand Variance</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full <?php echo $recon_grand_variance > 0 ? 'bg-amber-200' : 'bg-red-200'; ?> opacity-30"></div>
        </div>
        <div class="p-3 rounded-xl border border-purple-200 bg-gradient-to-br from-purple-50 to-purple-100/50 relative overflow-hidden">
            <p class="text-lg font-black text-purple-700"><?php echo count($recon_sections); ?></p>
            <p class="text-[9px] font-bold text-purple-400 uppercase mt-1">Departments</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full bg-purple-200 opacity-30"></div>
        </div>
    </div>

    <!-- Per-Department Reconciliation Cards -->
    <?php foreach ($recon_sections as $rs): 
        $status_config = [
            'matched'    => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'label' => 'MATCHED', 'icon' => '✓'],
            'variance'   => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'label' => 'VARIANCE', 'icon' => '⚠'],
            'not_linked' => ['bg' => 'bg-slate-100', 'text' => 'text-slate-500', 'label' => 'NOT LINKED', 'icon' => '⊘'],
            'no_audit'   => ['bg' => 'bg-red-100', 'text' => 'text-red-600', 'label' => 'NO AUDIT', 'icon' => '✗'],
            'no_count'   => ['bg' => 'bg-slate-100', 'text' => 'text-slate-500', 'label' => 'NO COUNT', 'icon' => '—'],
        ];
        $sc = $status_config[$rs['status']] ?? $status_config['no_count'];
        $dept_colors = [
            'bar' => ['border' => 'border-indigo-200', 'header_bg' => 'bg-indigo-50', 'text' => 'text-indigo-700', 'dot' => 'bg-indigo-500'],
            'kitchen' => ['border' => 'border-amber-200', 'header_bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'dot' => 'bg-amber-500'],
            'restaurant' => ['border' => 'border-rose-200', 'header_bg' => 'bg-rose-50', 'text' => 'text-rose-700', 'dot' => 'bg-rose-500'],
        ];
        $dc = $dept_colors[$rs['dept_type']] ?? ['border' => 'border-purple-200', 'header_bg' => 'bg-purple-50', 'text' => 'text-purple-700', 'dot' => 'bg-purple-500'];
    ?>
    <div class="mb-4 border <?php echo $dc['border']; ?> rounded-xl overflow-hidden">
        <!-- Department Header -->
        <div class="<?php echo $dc['header_bg']; ?> px-4 py-2.5 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full <?php echo $dc['dot']; ?>"></span>
                <span class="text-[10px] font-black <?php echo $dc['text']; ?> uppercase tracking-widest"><?php echo htmlspecialchars($rs['dept_name']); ?></span>
                <?php if ($rs['outlet_id']): ?>
                <span class="text-[9px] font-semibold text-slate-400">→ <?php echo htmlspecialchars($rs['outlet_name']); ?></span>
                <?php else: ?>
                <span class="text-[9px] font-bold text-red-400 bg-red-50 px-1.5 py-0.5 rounded">⊘ No outlet linked</span>
                <?php endif; ?>
            </div>
            <span class="<?php echo $sc['bg']; ?> <?php echo $sc['text']; ?> text-[9px] font-black px-2.5 py-1 rounded-full"><?php echo $sc['icon']; ?> <?php echo $sc['label']; ?></span>
        </div>

        <!-- Summary Row -->
        <div class="grid grid-cols-3 gap-3 px-4 py-3 bg-white border-b border-slate-100">
            <div class="text-center">
                <p class="text-[9px] font-bold text-slate-400 uppercase">Stock Count Sales</p>
                <p class="text-sm font-black text-emerald-700"><?php echo format_currency($rs['stock_sales_total']); ?></p>
                <p class="text-[8px] text-slate-400"><?php echo $rs['stock_sold_qty']; ?> items sold</p>
            </div>
            <div class="text-center">
                <p class="text-[9px] font-bold text-slate-400 uppercase">System Sales</p>
                <p class="text-sm font-black text-blue-700"><?php echo $rs['outlet_id'] ? format_currency($rs['declared_total']) : '—'; ?></p>
                <p class="text-[8px] text-slate-400"><?php echo $rs['outlet_id'] ? count($rs['outlet_sales']).' txn(s)' : 'Not linked'; ?></p>
            </div>
            <div class="text-center">
                <p class="text-[9px] font-bold text-slate-400 uppercase">Variance</p>
                <p class="text-sm font-black <?php echo $rs['variance'] == 0 ? 'text-slate-600' : ($rs['variance'] > 0 ? 'text-amber-700' : 'text-red-700'); ?>">
                    <?php echo $rs['outlet_id'] ? (($rs['variance'] > 0 ? '+' : '') . format_currency($rs['variance'])) : '—'; ?>
                </p>
                <p class="text-[8px] text-slate-400"><?php echo $rs['outlet_id'] ? ($rs['variance'] == 0 ? 'Balanced' : ($rs['variance'] > 0 ? 'System higher' : 'Stock count higher')) : 'N/A'; ?></p>
            </div>
        </div>

        <?php if (!empty($rs['products'])): ?>
        <!-- Per-Product Table -->
        <table class="w-full text-[9px] text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-200">
                    <th class="px-3 py-1.5 font-black text-slate-500 uppercase">#</th>
                    <th class="px-3 py-1.5 font-black text-slate-500 uppercase">Product</th>
                    <th class="px-3 py-1.5 text-center font-black text-blue-500 uppercase">Sys Total</th>
                    <th class="px-3 py-1.5 text-center font-black text-amber-500 uppercase">Adj</th>
                    <th class="px-3 py-1.5 text-center font-black text-emerald-500 uppercase">Physical</th>
                    <th class="px-3 py-1.5 text-center font-black text-purple-500 uppercase">Sold</th>
                    <th class="px-3 py-1.5 text-right font-black text-slate-500 uppercase">Price</th>
                    <th class="px-3 py-1.5 text-right font-black text-emerald-600 uppercase">Sales Val</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rs['products'] as $pi => $p): ?>
                <tr class="border-b border-slate-100 hover:bg-slate-50/40">
                    <td class="px-3 py-1.5 text-slate-400"><?php echo $pi+1; ?></td>
                    <td class="px-3 py-1.5 font-semibold text-slate-700"><?php echo htmlspecialchars($p['name']); ?></td>
                    <td class="px-3 py-1.5 text-center font-bold text-blue-600"><?php echo $p['system_total']; ?></td>
                    <td class="px-3 py-1.5 text-center font-bold <?php echo $p['adj'] > 0 ? 'text-emerald-600' : ($p['adj'] < 0 ? 'text-red-600' : 'text-slate-400'); ?>">
                        <?php echo $p['adj'] != 0 ? ($p['adj'] > 0 ? '+'.$p['adj'] : $p['adj']) : '—'; ?>
                    </td>
                    <td class="px-3 py-1.5 text-center font-bold text-emerald-600"><?php echo $p['physical']; ?></td>
                    <td class="px-3 py-1.5 text-center font-bold text-purple-600"><?php echo $p['qty_sold']; ?></td>
                    <td class="px-3 py-1.5 text-right text-slate-500"><?php echo format_currency($p['price']); ?></td>
                    <td class="px-3 py-1.5 text-right font-bold text-emerald-600"><?php echo format_currency($p['sales_value']); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="border-t-2 border-slate-200 bg-slate-50 font-black">
                    <td colspan="5" class="px-3 py-2 text-right text-slate-500 uppercase">Totals</td>
                    <td class="px-3 py-2 text-center text-purple-600"><?php echo $rs['stock_sold_qty']; ?></td>
                    <td class="px-3 py-2"></td>
                    <td class="px-3 py-2 text-right text-emerald-600"><?php echo format_currency($rs['stock_sales_total']); ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($rs['outlet_sales'])): ?>
        <!-- Daily Audit Breakdown -->
        <div class="px-3 py-1.5 bg-blue-50/50 border-t border-blue-100">
            <p class="text-[8px] font-black text-blue-500 uppercase tracking-widest">Daily Audit — <?php echo htmlspecialchars($rs['outlet_name']); ?></p>
        </div>
        <table class="w-full text-[9px] text-left border-collapse">
            <thead>
                <tr class="bg-blue-50/30 border-b border-blue-100">
                    <th class="px-3 py-1.5 font-black text-blue-500 uppercase">Shift</th>
                    <th class="px-3 py-1.5 text-right font-black text-blue-500 uppercase">POS</th>
                    <th class="px-3 py-1.5 text-right font-black text-emerald-500 uppercase">Cash</th>
                    <th class="px-3 py-1.5 text-right font-black text-purple-500 uppercase">Transfer</th>
                    <th class="px-3 py-1.5 text-right font-black text-amber-500 uppercase">Declared</th>
                    <th class="px-3 py-1.5 text-right font-black text-slate-700 uppercase">System Sales</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rs['outlet_sales'] as $os): ?>
                <tr class="border-b border-slate-100">
                    <td class="px-3 py-1.5 font-semibold text-slate-600"><?php echo ucwords(str_replace('_',' ',$os['shift'])); ?></td>
                    <td class="px-3 py-1.5 text-right font-semibold text-blue-600"><?php echo format_currency($os['pos_amount']); ?></td>
                    <td class="px-3 py-1.5 text-right font-semibold text-emerald-600"><?php echo format_currency($os['cash_amount']); ?></td>
                    <td class="px-3 py-1.5 text-right font-semibold text-purple-600"><?php echo format_currency($os['transfer_amount']); ?></td>
                    <td class="px-3 py-1.5 text-right font-bold text-amber-600"><?php echo format_currency($os['actual_total']); ?></td>
                    <td class="px-3 py-1.5 text-right font-black text-slate-800"><?php echo format_currency($os['declared_total']); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="border-t-2 border-blue-200 bg-blue-50/50 font-black">
                    <td class="px-3 py-2 text-slate-500 uppercase">Total</td>
                    <td class="px-3 py-2 text-right text-blue-600"><?php echo format_currency(array_sum(array_column($rs['outlet_sales'], 'pos_amount'))); ?></td>
                    <td class="px-3 py-2 text-right text-emerald-600"><?php echo format_currency(array_sum(array_column($rs['outlet_sales'], 'cash_amount'))); ?></td>
                    <td class="px-3 py-2 text-right text-purple-600"><?php echo format_currency(array_sum(array_column($rs['outlet_sales'], 'transfer_amount'))); ?></td>
                    <td class="px-3 py-2 text-right text-amber-600"><?php echo format_currency($rs['actual_total']); ?></td>
                    <td class="px-3 py-2 text-right text-slate-800"><?php echo format_currency($rs['declared_total']); ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- SECTION 6 - STOCK VALUATION REPORT (All Departments & Store) -->
<div>
    <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-4">Section 6 - Stock Valuation Report
    </p>

    <!-- KPI Summary -->
    <div class="grid grid-cols-4 gap-3 mb-5">
        <div
            class="p-3 rounded-xl border border-teal-200 bg-gradient-to-br from-teal-50 to-teal-100/50 relative overflow-hidden">
            <p class="text-lg font-black text-teal-700">
                <?php echo format_currency($total_valuation); ?>
            </p>
            <p class="text-[9px] font-bold text-teal-400 uppercase mt-1">Total Stock Value (Cost)</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full bg-teal-200 opacity-30"></div>
        </div>
        <div
            class="p-3 rounded-xl border border-indigo-200 bg-gradient-to-br from-indigo-50 to-indigo-100/50 relative overflow-hidden">
            <p class="text-lg font-black text-indigo-700">
                <?php echo count($valuation_data); ?>
            </p>
            <p class="text-[9px] font-bold text-indigo-400 uppercase mt-1">Departments / Stores</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full bg-indigo-200 opacity-30"></div>
        </div>
        <div
            class="p-3 rounded-xl border border-sky-200 bg-gradient-to-br from-sky-50 to-sky-100/50 relative overflow-hidden">
            <?php $total_items = array_sum(array_map(fn($d) => count($d['items']), $valuation_data)); ?>
            <p class="text-lg font-black text-sky-700">
                <?php echo number_format($total_items); ?>
            </p>
            <p class="text-[9px] font-bold text-sky-400 uppercase mt-1">Total Products</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full bg-sky-200 opacity-30"></div>
        </div>
        <div
            class="p-3 rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-emerald-100/50 relative overflow-hidden">
            <?php $total_units = array_sum(array_map(fn($d) => array_sum(array_column($d['items'], 'closing')), $valuation_data)); ?>
            <p class="text-lg font-black text-emerald-700">
                <?php echo number_format($total_units); ?>
            </p>
            <p class="text-[9px] font-bold text-emerald-400 uppercase mt-1">Total Units</p>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full bg-emerald-200 opacity-30"></div>
        </div>
    </div>

    <?php if (!empty($valuation_data)): ?>
    <?php foreach ($valuation_data as $vd):
                        $type_colors = [
                            'main' => ['bg' => 'bg-teal-50', 'border' => 'border-teal-200', 'text' => 'text-teal-700', 'dot' => 'bg-teal-500'],
                            'kitchen' => ['bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'text' => 'text-amber-700', 'dot' => 'bg-amber-500'],
                            'restaurant' => ['bg' => 'bg-rose-50', 'border' => 'border-rose-200', 'text' => 'text-rose-700', 'dot' => 'bg-rose-500'],
                            'department' => ['bg' => 'bg-indigo-50', 'border' => 'border-indigo-200', 'text' => 'text-indigo-700', 'dot' => 'bg-indigo-500'],
                        ];
                        $tc = $type_colors[$vd['dept_type']] ?? $type_colors['department'];
                    ?>
    <div class="mb-4">
        <div
            class="<?php echo $tc['bg']; ?> <?php echo $tc['border']; ?> border rounded-t-xl px-4 py-2.5 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full <?php echo $tc['dot']; ?>"></span>
                <span class="text-[10px] font-black <?php echo $tc['text']; ?> uppercase tracking-widest">
                    <?php echo htmlspecialchars($vd['dept_name']); ?>
                </span>
                <span class="text-[9px] font-medium text-slate-400">(
                    <?php echo count($vd['items']); ?> products)
                </span>
            </div>
            <div class="text-right">
                <span class="text-[9px] font-bold text-slate-400 uppercase">Total Value: </span>
                <span class="text-[11px] font-black <?php echo $tc['text']; ?>">
                    <?php echo format_currency($vd['total_cost']); ?>
                </span>
            </div>
        </div>
        <div class="border border-t-0 <?php echo $tc['border']; ?> rounded-b-xl overflow-hidden">
            <table class="w-full text-[10px] text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-200">
                        <th class="px-2.5 py-2 font-black text-slate-500 uppercase text-[9px] w-6">#</th>
                        <th class="px-2.5 py-2 font-black text-slate-500 uppercase text-[9px]">Product</th>
                        <th class="px-2.5 py-2 font-black text-slate-500 uppercase text-[9px]">Category</th>
                        <th class="px-2.5 py-2 font-black text-slate-500 uppercase text-[9px] text-right">Closing Qty
                        </th>
                        <th class="px-2.5 py-2 font-black text-slate-500 uppercase text-[9px] text-right">Unit Cost</th>
                        <th class="px-2.5 py-2 font-black text-slate-500 uppercase text-[9px] text-right">Selling Price
                        </th>
                        <th class="px-2.5 py-2 font-black text-teal-600 uppercase text-[9px] text-right">Cost Value</th>
                        <th class="px-2.5 py-2 font-black text-blue-600 uppercase text-[9px] text-right">Sell Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $row_n = 0; foreach ($vd['items'] as $item): $row_n++; ?>
                    <tr class="border-b border-slate-100 hover:bg-slate-50/40">
                        <td class="px-2.5 py-1.5 text-slate-400">
                            <?php echo $row_n; ?>
                        </td>
                        <td class="px-2.5 py-1.5 font-semibold text-slate-700">
                            <?php echo htmlspecialchars($item['product_name']); ?>
                        </td>
                        <td class="px-2.5 py-1.5 text-slate-500 capitalize">
                            <?php echo htmlspecialchars($item['category'] ?: "\xe2\x80\x94"); ?>
                        </td>
                        <td class="px-2.5 py-1.5 text-right font-bold text-slate-800">
                            <?php echo number_format($item['closing']); ?>
                        </td>
                        <td class="px-2.5 py-1.5 text-right font-mono text-slate-600">
                            <?php echo format_currency($item['unit_cost']); ?>
                        </td>
                        <td class="px-2.5 py-1.5 text-right font-mono text-slate-600">
                            <?php echo format_currency($item['selling_price']); ?>
                        </td>
                        <td class="px-2.5 py-1.5 text-right font-bold text-teal-700">
                            <?php echo format_currency($item['cost_value']); ?>
                        </td>
                        <td class="px-2.5 py-1.5 text-right font-bold text-blue-600">
                            <?php echo format_currency($item['sell_value']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-300 bg-slate-50/80 font-black text-slate-700">
                        <td class="px-2.5 py-2 text-[9px]" colspan="3">SUBTOTAL</td>
                        <td class="px-2.5 py-2 text-right text-[9px]">
                            <?php echo number_format(array_sum(array_column($vd['items'], 'closing'))); ?>
                        </td>
                        <td class="px-2.5 py-2" colspan="2"></td>
                        <td class="px-2.5 py-2 text-right text-[9px] text-teal-700">
                            <?php echo format_currency($vd['total_cost']); ?>
                        </td>
                        <td class="px-2.5 py-2 text-right text-[9px] text-blue-600">
                            <?php echo format_currency($vd['total_sell']); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Grand Total Footer -->
    <div class="mt-3 border-2 border-teal-200 rounded-xl bg-teal-50/50 px-5 py-3 flex items-center justify-between">
        <div>
            <p class="text-[9px] font-black text-teal-600 uppercase tracking-widest">Grand Total Stock Valuation</p>
            <p class="text-[9px] font-medium text-slate-400">As at
                <?php echo date('M j, Y', strtotime($valuation_date)); ?> &bull;
                <?php echo count($valuation_data); ?> sections
            </p>
        </div>
        <p class="text-lg font-black text-teal-700">
            <?php echo format_currency($total_valuation); ?>
        </p>
    </div>
    <?php else: ?>
    <div class="border border-slate-200 rounded-xl px-6 py-8 text-center">
        <p class="text-slate-400 font-medium text-[10px]">No stock data found for this store</p>
    </div>
    <?php endif; ?>
</div>

</div><!-- end content -->

<!-- DOCUMENT FOOTER -->
<div class="px-10 py-4 border-t border-slate-200 bg-slate-50/50 flex justify-between items-center">
    <p class="text-[9px] text-slate-400">Generated by MIAUDITOPS &bull;
        <?php echo date('M j, Y g:i A'); ?>
    </p>
    <p class="text-[9px] text-slate-400">
        <?php echo htmlspecialchars($company_name); ?> &bull;
        <?php echo htmlspecialchars($client_name); ?>
    </p>
</div>
</div>
</div><!-- ===================== TAB 2: STOCK COUNT ===================== -->
<div x-show="currentTab === 'stock'" x-transition>
    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div
                    class="w-9 h-9 rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 flex items-center justify-center shadow-lg shadow-orange-500/30">
                    <i data-lucide="clipboard-list" class="w-4 h-4 text-white"></i></div>
                <div>
                    <h3 class="font-bold text-slate-900 dark:text-white text-sm">Stock Count Variance</h3>
                    <p class="text-xs text-slate-500">
                        <?php echo count($stock_counts); ?> items counted
                    </p>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Category</th>
                        <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">System</th>
                        <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Physical</th>
                        <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Variance</th>
                        <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Value</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Counted By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock_counts as $sc):
                            $v = $sc['physical_count'] - $sc['system_count'];
                            $vv = $v * ($sc['unit_cost'] ?? 0);
                        ?>
                    <tr
                        class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30">
                        <td class="px-4 py-2 text-xs font-mono">
                            <?php echo $sc['count_date']; ?>
                        </td>
                        <td class="px-4 py-2 font-semibold">
                            <?php echo htmlspecialchars($sc['product_name']); ?>
                        </td>
                        <td class="px-4 py-2 text-xs text-slate-500">
                            <?php echo htmlspecialchars($sc['category'] ?? '-'); ?>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <?php echo $sc['system_count']; ?>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <?php echo $sc['physical_count']; ?>
                        </td>
                        <td
                            class="px-4 py-2 text-right font-bold <?php echo $v < 0 ? 'text-red-600' : ($v > 0 ? 'text-blue-600' : 'text-emerald-600'); ?>">
                            <?php echo $v > 0 ? '+' . $v : $v; ?>
                        </td>
                        <td class="px-4 py-2 text-right font-bold <?php echo $vv < 0 ? 'text-red-600' : ''; ?>">
                            <?php echo format_currency(abs($vv)); ?>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <?php echo htmlspecialchars(($sc['first_name'] ?? '') . ' ' . ($sc['last_name'] ?? '')); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($stock_counts)): ?>
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-slate-400">No stock counts recorded for this
                            period</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===================== TAB 3: STOCK VALUATION ===================== -->
<div x-show="currentTab === 'valuation'" x-transition>
    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div
                    class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 flex items-center justify-center shadow-lg shadow-teal-500/30">
                    <i data-lucide="package-search" class="w-4 h-4 text-white"></i></div>
                <div>
                    <h3 class="font-bold text-slate-900 dark:text-white text-sm">Stock Valuation Report - Closing Stock
                    </h3>
                    <p class="text-xs text-slate-500">
                        <?php echo count($valuation_products); ?> products &bull; Total:
                        <?php echo format_currency($valuation_total); ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">#</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Category</th>
                        <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Stock</th>
                        <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Unit Cost</th>
                        <th class="px-4 py-3 text-right text-xs font-bold text-teal-600">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $vi = 0; foreach ($valuation_products as $vp):
                            if ((int)$vp['current_stock'] <= 0) continue;
                            $vi++;
                            $val = (int)$vp['current_stock'] * (float)$vp['unit_cost'];
                        ?>
                    <tr
                        class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30">
                        <td class="px-4 py-2 text-xs text-slate-400">
                            <?php echo $vi; ?>
                        </td>
                        <td class="px-4 py-2 font-semibold">
                            <?php echo htmlspecialchars($vp['name']); ?>
                        </td>
                        <td class="px-4 py-2 text-xs text-slate-500">
                            <?php echo htmlspecialchars($vp['category'] ?? '-'); ?>
                        </td>
                        <td class="px-4 py-2 text-right font-bold">
                            <?php echo number_format($vp['current_stock']); ?>
                        </td>
                        <td class="px-4 py-2 text-right font-mono text-xs">
                            <?php echo format_currency($vp['unit_cost']); ?>
                        </td>
                        <td class="px-4 py-2 text-right font-bold text-teal-600">
                            <?php echo format_currency($val); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-slate-100 dark:bg-slate-800 font-bold">
                    <tr>
                        <td class="px-4 py-3" colspan="3">GRAND TOTAL</td>
                        <td class="px-4 py-3 text-right">
                            <?php echo number_format(array_sum(array_column(array_filter($valuation_products, fn($p) => (int)$p['current_stock'] > 0), 'current_stock'))); ?>
                        </td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3 text-right text-teal-600">
                            <?php echo format_currency($valuation_total); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- ===================== TAB 4: FINANCIAL ===================== -->
<div x-show="currentTab === 'financial'" x-transition>
    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
            <div
                class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center shadow-lg">
                <i data-lucide="bar-chart-3" class="w-4 h-4 text-white"></i></div>
            <div>
                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Financial Summary</h3>
                <p class="text-xs text-slate-500">Revenue, expenses, and net position</p>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-4 p-6">
            <div class="glass-card rounded-xl p-4 border border-slate-200/60">
                <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Revenue</p>
                <p class="text-xl font-black text-emerald-600">
                    <?php echo format_currency($total_revenue); ?>
                </p>
            </div>
            <div class="glass-card rounded-xl p-4 border border-slate-200/60">
                <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Expenses</p>
                <p class="text-xl font-black text-red-600">
                    <?php echo format_currency($total_expenses); ?>
                </p>
            </div>
            <div class="glass-card rounded-xl p-4 border border-slate-200/60">
                <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Net Position</p>
                <p class="text-xl font-black <?php echo $net >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>">
                    <?php echo format_currency($net); ?>
                </p>
            </div>
        </div>
        <?php if (!empty($expense_breakdown)): ?>
        <div class="px-6 pb-6">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-bold text-slate-500">Category</th>
                        <th class="px-4 py-2 text-left text-xs font-bold text-slate-500">Type</th>
                        <th class="px-4 py-2 text-right text-xs font-bold text-slate-500">Amount</th>
                        <th class="px-4 py-2 text-right text-xs font-bold text-slate-500">% of Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expense_breakdown as $eb): ?>
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-2 font-semibold">
                            <?php echo htmlspecialchars($eb['name']); ?>
                        </td>
                        <td class="px-4 py-2 text-xs capitalize">
                            <?php echo $eb['type']; ?>
                        </td>
                        <td class="px-4 py-2 text-right font-bold">
                            <?php echo format_currency($eb['total']); ?>
                        </td>
                        <td class="px-4 py-2 text-right text-xs">
                            <?php echo $total_expenses > 0 ? number_format(($eb['total']/$total_expenses)*100,1) : 0; ?>%
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===================== TAB 5: EXPENSES ===================== -->
<div x-show="currentTab === 'expenses'" x-transition>
    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
            <div
                class="w-9 h-9 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center shadow-lg">
                <i data-lucide="receipt" class="w-4 h-4 text-white"></i></div>
            <div>
                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Expense Details</h3>
                <p class="text-xs text-slate-500">
                    <?php echo count($expense_detail); ?> entries &bull; Total:
                    <?php echo format_currency($total_expenses); ?>
                </p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Category</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Amount</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Payment</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Vendor</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Entered By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expense_detail as $ed): ?>
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-2 text-xs font-mono">
                            <?php echo $ed['entry_date']; ?>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <?php echo htmlspecialchars($ed['category'] ?? '-'); ?>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <?php echo htmlspecialchars($ed['description'] ?? '-'); ?>
                        </td>
                        <td class="px-4 py-2 text-right font-bold">
                            <?php echo format_currency($ed['amount']); ?>
                        </td>
                        <td class="px-4 py-2 text-xs capitalize">
                            <?php echo $ed['payment_method'] ?? '-'; ?>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <?php echo htmlspecialchars($ed['vendor_name'] ?? '-'); ?>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <?php echo htmlspecialchars(($ed['first_name'] ?? '') . ' ' . ($ed['last_name'] ?? '')); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($expense_detail)): ?>
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-slate-400">No expenses recorded</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===================== TAB 6: REQUISITIONS ===================== -->
<div x-show="currentTab === 'requisitions'" x-transition>
    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
            <div
                class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg">
                <i data-lucide="file-text" class="w-4 h-4 text-white"></i></div>
            <div>
                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Requisitions</h3>
                <p class="text-xs text-slate-500">
                    <?php echo count($req_report); ?> requests
                </p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Req #</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Department</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Purpose</th>
                        <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Amount</th>
                        <th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Priority</th>
                        <th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Requested By</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($req_report as $rr): ?>
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-2 font-mono text-xs">
                            <?php echo htmlspecialchars($rr['requisition_number']); ?>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <?php echo htmlspecialchars($rr['department'] ?? '-'); ?>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <?php echo htmlspecialchars($rr['purpose'] ?? '-'); ?>
                        </td>
                        <td class="px-4 py-2 text-right font-bold">
                            <?php echo format_currency($rr['total_amount']); ?>
                        </td>
                        <td class="px-4 py-2 text-center"><span
                                class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo ($rr['priority'] ?? '') === 'high' ? 'bg-red-100 text-red-700' : (($rr['priority'] ?? '') === 'medium' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'); ?>">
                                <?php echo ucfirst($rr['priority'] ?? 'normal'); ?>
                            </span></td>
                        <td class="px-4 py-2 text-center"><span
                                class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo ($rr['status'] ?? '') === 'approved' ? 'bg-emerald-100 text-emerald-700' : (($rr['status'] ?? '') === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'); ?>">
                                <?php echo ucfirst($rr['status'] ?? 'pending'); ?>
                            </span></td>
                        <td class="px-4 py-2 text-xs">
                            <?php echo htmlspecialchars(($rr['first_name'] ?? '') . ' ' . ($rr['last_name'] ?? '')); ?>
                        </td>
                        <td class="px-4 py-2 text-xs font-mono">
                            <?php echo date('M j', strtotime($rr['created_at'])); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($req_report)): ?>
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-slate-400">No requisitions found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    function fmt(n) { return '\u20A6' + Number(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function reportApp() {
        return {
            currentTab: 'recon',
            showStockRecon: true,
            tabs: [
                { id: 'recon', label: 'Sales Reconciliation', icon: 'scale' },
                { id: 'stock', label: 'Stock Count', icon: 'clipboard-list' },
                { id: 'valuation', label: 'Stock Valuation', icon: 'package-search' },
                { id: 'financial', label: 'Financial', icon: 'bar-chart-3' },
                { id: 'expenses', label: 'Expenses', icon: 'receipt' },
                { id: 'requisitions', label: 'Requisitions', icon: 'file-text' },
            ],
            init() { this.$nextTick(() => lucide.createIcons()); this.$watch('currentTab', () => this.$nextTick(() => lucide.createIcons())); }
        };
    }
</script>
<?php include '../includes/dashboard_scripts.php'; ?>
    </main>
    </div>
</div>
<!-- Print footer (appears on every PDF page) -->
<div class="print-page-footer" style="display:none;">
    <p>Miauditops: powered by Miemploya Audit &amp; Tax Compliance Support Services</p>
</div>
</body>
</html>