<?php
/**
 * MIAUDITOPS — Financial Control & P&L Module (Phase 4 — Full)
 * 4 Tabs: Revenue, Expenses, Cost Centers, P&L Statement
 */
require_once '../includes/functions.php';
require_login();
require_permission('finance');
require_active_client();
$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$client_name = $_SESSION['active_client_name'] ?? 'Client';
$company_name = $_SESSION['company_name'] ?? 'Company';
$page_title = 'Financial Control';

// Month/Year scope + custom date range
$month = intval($_GET['month'] ?? date('m'));
$year  = intval($_GET['year'] ?? date('Y'));
$custom_from = $_GET['from'] ?? '';
$custom_to   = $_GET['to'] ?? '';
$is_custom_range = (!empty($custom_from) && !empty($custom_to));
if ($is_custom_range) {
    $period_start = date('Y-m-d', strtotime($custom_from));
    $period_end   = date('Y-m-d', strtotime($custom_to));
    $month_label  = date('M j', strtotime($period_start)) . ' – ' . date('M j, Y', strtotime($period_end));
    $month = (int)date('m', strtotime($period_start));
    $year  = (int)date('Y', strtotime($period_start));
} else {
    $month_label = date('F Y', mktime(0,0,0,$month,1,$year));
}

// Expense Categories
$stmt = $pdo->prepare("SELECT * FROM expense_categories WHERE company_id = ? AND deleted_at IS NULL ORDER BY type, name");
$stmt->execute([$company_id]);
$categories = $stmt->fetchAll();

// Revenue (from sales — client-based)
$stmt = $pdo->prepare("SELECT transaction_date, COALESCE(SUM(pos_amount),0) as pos, COALESCE(SUM(cash_amount),0) as cash, COALESCE(SUM(transfer_amount),0) as transfer, COALESCE(SUM(actual_total),0) as total FROM sales_transactions WHERE company_id = ? AND client_id = ? AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ? AND deleted_at IS NULL GROUP BY transaction_date ORDER BY transaction_date");
$stmt->execute([$company_id, $client_id, $month, $year]);
$daily_revenue = $stmt->fetchAll();
$monthly_revenue = array_sum(array_column($daily_revenue, 'total'));
$monthly_pos = array_sum(array_column($daily_revenue, 'pos'));
$monthly_cash = array_sum(array_column($daily_revenue, 'cash'));
$monthly_transfer = array_sum(array_column($daily_revenue, 'transfer'));

// Expenses
$stmt = $pdo->prepare("SELECT e.*, ec.name as category_name, ec.type as category_type, u.first_name, u.last_name FROM expense_entries e LEFT JOIN expense_categories ec ON e.category_id = ec.id LEFT JOIN users u ON e.entered_by = u.id WHERE e.company_id = ? AND MONTH(e.entry_date) = ? AND YEAR(e.entry_date) = ? AND e.deleted_at IS NULL ORDER BY e.entry_date DESC, e.created_at DESC LIMIT 100");
$stmt->execute([$company_id, $month, $year]);
$expenses = $stmt->fetchAll();
$monthly_expenses = array_sum(array_column($expenses, 'amount'));

// Expense by category
$stmt = $pdo->prepare("SELECT ec.name, ec.type, COALESCE(SUM(ee.amount),0) as total FROM expense_entries ee JOIN expense_categories ec ON ee.category_id = ec.id WHERE ee.company_id = ? AND MONTH(ee.entry_date) = ? AND YEAR(ee.entry_date) = ? AND ee.deleted_at IS NULL GROUP BY ec.name, ec.type ORDER BY ec.type, total DESC");
$stmt->execute([$company_id, $month, $year]);
$breakdown = $stmt->fetchAll();

// Cost of Sales — from actual stock across ALL stores & departments
// Main Store stock value
$stmt = $pdo->prepare("SELECT COALESCE(SUM(p.current_stock * p.unit_cost), 0) as total FROM products p WHERE p.company_id = ? AND p.client_id = ? AND p.deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$cos_main = (float)$stmt->fetchColumn();

// Department stock values (all depts — Kitchen, Restaurant, etc.)
$cos_depts = 0;
$cos_dept_detail = [];
$stmt = $pdo->prepare("SELECT sd.id, sd.name, sd.type FROM stock_departments sd WHERE sd.company_id = ? AND sd.client_id = ? AND sd.deleted_at IS NULL ORDER BY sd.name");
$stmt->execute([$company_id, $client_id]);
$cos_departments = $stmt->fetchAll();

foreach ($cos_departments as $cd) {
    // Sum closing stock × unit_cost for each product in this dept
    $stmt = $pdo->prepare("
        SELECT ds.product_id, p.unit_cost,
               COALESCE(SUM(
                   COALESCE(ds.opening_stock,0) + COALESCE(ds.added,0) + COALESCE(ds.return_in,0)
                   - COALESCE(ds.transfer_out,0) - COALESCE(ds.transfer_to_main,0) - COALESCE(ds.qty_sold,0)
               ), 0) as closing
        FROM department_stock ds
        JOIN products p ON ds.product_id = p.id
        WHERE ds.department_id = ? AND ds.company_id = ? AND ds.client_id = ?
        GROUP BY ds.product_id, p.unit_cost
    ");
    $stmt->execute([$cd['id'], $company_id, $client_id]);
    $dept_val = 0;
    foreach ($stmt->fetchAll() as $row) {
        $closing = max(0, (int)$row['closing']);
        $dept_val += $closing * (float)$row['unit_cost'];
    }
    $cos_depts += $dept_val;
    $cos_dept_detail[] = ['name' => $cd['name'], 'type' => $cd['type'], 'value' => $dept_val];
}

$cos = $cos_main + $cos_depts;

// Operating vs admin vs other from expense breakdown
$opex = 0; $admin = 0; $other_exp = 0;
foreach ($breakdown as $b) {
    switch($b['type']) {
        case 'operating': $opex += $b['total']; break;
        case 'administrative': $admin += $b['total']; break;
        case 'cost_of_sales': break; // ignore expense-based CoS, using stock-based now
        default: $other_exp += $b['total'];
    }
}
$gross_profit = $monthly_revenue - $cos;
$net_profit = $gross_profit - $opex - $admin - $other_exp;
$margin = $monthly_revenue > 0 ? ($net_profit / $monthly_revenue) * 100 : 0;
$gross_margin = $monthly_revenue > 0 ? ($gross_profit / $monthly_revenue) * 100 : 0;

$js_cos_detail = json_encode(['main' => $cos_main, 'departments' => $cos_dept_detail], JSON_HEX_TAG | JSON_HEX_APOS);

// ===== P&L RESTRUCTURED DATA =====
// Period dates
if (!$is_custom_range) {
    $period_start = date('Y-m-01', mktime(0,0,0,$month,1,$year));
    $period_end   = date('Y-m-t', mktime(0,0,0,$month,1,$year));
}

// PURCHASES for the period (from supplier_deliveries)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM supplier_deliveries WHERE company_id = ? AND client_id = ? AND delivery_date BETWEEN ? AND ? AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id, $period_start, $period_end]);
$pnl_purchases = (float)$stmt->fetchColumn();

// Purchase breakdown (for Appendix)
$stmt = $pdo->prepare("SELECT sd.delivery_date, p.name as product_name, sd.supplier_name, sd.quantity, sd.unit_cost, sd.total_cost FROM supplier_deliveries sd LEFT JOIN products p ON sd.product_id = p.id WHERE sd.company_id = ? AND sd.client_id = ? AND sd.delivery_date BETWEEN ? AND ? AND sd.deleted_at IS NULL ORDER BY sd.delivery_date, p.name");
$stmt->execute([$company_id, $client_id, $period_start, $period_end]);
$purchase_details = $stmt->fetchAll();

// CLOSING STOCK (current valuation — main + departments, already computed above)
$pnl_closing = $cos; // = $cos_main + $cos_depts (already computed)

// OPENING STOCK — Historical closing stock on the day BEFORE the period starts
// E.g. for Feb 1-28 report → closing stock as of Jan 31
// E.g. for Feb 8-14 report → closing stock as of Feb 7
$opening_date = date('Y-m-d', strtotime($period_start . ' -1 day'));

// === Create client_opening_stock table for manual first-time input ===
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

// --- A) Main Store: historical stock at $opening_date ---
// current_stock is "today". To get stock on $opening_date:
// historical_stock = current_stock - (deliveries AFTER opening_date) + (outward movements AFTER opening_date)
// Fetch all products (reused later for Stock Valuation tab)
if (!isset($main_products)) {
    $stmt = $pdo->prepare("SELECT p.id, p.name, p.category, p.current_stock, p.unit_cost, p.selling_price FROM products p WHERE p.company_id = ? AND p.client_id = ? AND p.deleted_at IS NULL ORDER BY p.name");
    $stmt->execute([$company_id, $client_id]);
    $main_products = $stmt->fetchAll();
}
$hist_main = 0;
foreach ($main_products as $p) {
    $hist_qty = (int)$p['current_stock'];

    // Subtract deliveries that arrived AFTER the opening date (they weren't in stock yet)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM supplier_deliveries WHERE company_id = ? AND client_id = ? AND product_id = ? AND delivery_date > ? AND deleted_at IS NULL");
    $stmt->execute([$company_id, $client_id, $p['id'], $opening_date]);
    $hist_qty -= (int)$stmt->fetchColumn();

    // Add back outward movements that happened AFTER the opening date (they were still in stock then)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE company_id = ? AND client_id = ? AND product_id = ? AND DATE(created_at) > ? AND type NOT IN ('in','dept_return')");
    $stmt->execute([$company_id, $client_id, $p['id'], $opening_date]);
    $hist_qty += (int)$stmt->fetchColumn();

    // Add back department issues AFTER opening date (product was issued from main → was still in main on opening_date)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ds.added),0) FROM department_stock ds WHERE ds.company_id = ? AND ds.client_id = ? AND ds.product_id = ? AND ds.stock_date > ?");
    $stmt->execute([$company_id, $client_id, $p['id'], $opening_date]);
    $hist_qty += (int)$stmt->fetchColumn();

    if ($hist_qty > 0) {
        $hist_main += $hist_qty * (float)$p['unit_cost'];
    }
}

// --- B) Department Stock: historical closing at $opening_date ---
$hist_depts = 0;
foreach ($cos_departments as $cd) {
    $stmt = $pdo->prepare("
        SELECT ds.product_id, p.unit_cost,
               COALESCE(SUM(
                   COALESCE(ds.opening_stock,0) + COALESCE(ds.added,0) + COALESCE(ds.return_in,0)
                   - COALESCE(ds.transfer_out,0) - COALESCE(ds.transfer_to_main,0) - COALESCE(ds.qty_sold,0)
               ), 0) as closing
        FROM department_stock ds
        JOIN products p ON ds.product_id = p.id
        WHERE ds.department_id = ? AND ds.company_id = ? AND ds.client_id = ? AND ds.stock_date <= ?
        GROUP BY ds.product_id, p.unit_cost
    ");
    $stmt->execute([$cd['id'], $company_id, $client_id, $opening_date]);
    foreach ($stmt->fetchAll() as $row) {
        $closing = max(0, (int)$row['closing']);
        $hist_depts += $closing * (float)$row['unit_cost'];
    }
}

$pnl_opening = $hist_main + $hist_depts;
$needs_manual_opening = false;

// --- C) First-time client check: if computed opening is 0 and no prior activity exists ---
if ($pnl_opening == 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_deliveries WHERE company_id = ? AND client_id = ? AND delivery_date <= ? AND deleted_at IS NULL");
    $stmt->execute([$company_id, $client_id, $opening_date]);
    $has_prior_deliveries = (int)$stmt->fetchColumn() > 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE company_id = ? AND client_id = ? AND DATE(created_at) <= ?");
    $stmt->execute([$company_id, $client_id, $opening_date]);
    $has_prior_movements = (int)$stmt->fetchColumn() > 0;

    if (!$has_prior_deliveries && !$has_prior_movements) {
        // Check for manually saved opening stock for this period
        $stmt = $pdo->prepare("SELECT opening_value FROM client_opening_stock WHERE company_id = ? AND client_id = ? AND period_start = ?");
        $stmt->execute([$company_id, $client_id, $period_start]);
        $manual_opening = $stmt->fetchColumn();
        if ($manual_opening !== false) {
            $pnl_opening = (float)$manual_opening;
        } else {
            // Flag: no data at all — show manual input option on the UI
            $needs_manual_opening = true;
        }
    }
}

// Proper CoS = Opening + Purchases - Closing
$pnl_cos = $pnl_opening + $pnl_purchases - $pnl_closing;
$pnl_gross_profit = $monthly_revenue - $pnl_cos;
$pnl_net_profit = $pnl_gross_profit - $opex - $admin - $other_exp;
$pnl_gross_margin = $monthly_revenue > 0 ? ($pnl_gross_profit / $monthly_revenue) * 100 : 0;
$pnl_net_margin = $monthly_revenue > 0 ? ($pnl_net_profit / $monthly_revenue) * 100 : 0;

// Revenue by Outlet (selected client)
$stmt = $pdo->prepare("SELECT c.name as outlet_name, COALESCE(SUM(st.actual_total),0) as total, COUNT(*) as days FROM sales_transactions st JOIN clients c ON st.client_id = c.id WHERE st.company_id = ? AND st.client_id = ? AND MONTH(st.transaction_date) = ? AND YEAR(st.transaction_date) = ? AND st.deleted_at IS NULL GROUP BY c.name ORDER BY total DESC");
$stmt->execute([$company_id, $client_id, $month, $year]);
$revenue_by_outlet = $stmt->fetchAll();

// Outlet-level weekly sales breakdown for Notes
$stmt = $pdo->prepare("
    SELECT co.name as outlet_name, LEAST(CEIL(DAY(st.transaction_date) / 7), 4) as week_num,
           COALESCE(SUM(st.actual_total),0) as week_total, COUNT(*) as days
    FROM sales_transactions st
    JOIN client_outlets co ON st.outlet_id = co.id
    WHERE st.company_id = ? AND st.client_id = ? AND MONTH(st.transaction_date) = ? AND YEAR(st.transaction_date) = ? AND st.deleted_at IS NULL
    GROUP BY co.name, week_num
    ORDER BY co.name, week_num
");
$stmt->execute([$company_id, $client_id, $month, $year]);
$outlet_weekly_raw = $stmt->fetchAll();

// Pivot into outlet => [week1..week4] structure — always 4 weeks
$outlet_weekly = [];
$max_weeks = 4; // Always show 4 weeks
foreach ($outlet_weekly_raw as $ow) {
    $name = $ow['outlet_name'];
    $wn = (int)$ow['week_num'];
    if (!isset($outlet_weekly[$name])) $outlet_weekly[$name] = ['weeks' => [], 'total' => 0];
    $outlet_weekly[$name]['weeks'][$wn] = (float)$ow['week_total'];
    $outlet_weekly[$name]['total'] += (float)$ow['week_total'];
}

// Daily sales for Appendix (selected client)
$stmt = $pdo->prepare("SELECT st.transaction_date, c.name as outlet_name, st.pos_amount, st.cash_amount, st.transfer_amount, st.actual_total FROM sales_transactions st LEFT JOIN clients c ON st.client_id = c.id WHERE st.company_id = ? AND st.client_id = ? AND MONTH(st.transaction_date) = ? AND YEAR(st.transaction_date) = ? AND st.deleted_at IS NULL ORDER BY st.transaction_date");
$stmt->execute([$company_id, $client_id, $month, $year]);
$daily_sales_detail = $stmt->fetchAll();

// Closing stock detail for Appendix (main store products)
$stmt = $pdo->prepare("SELECT p.name, p.category, p.current_stock, p.unit_cost, (p.current_stock * p.unit_cost) as value FROM products p WHERE p.company_id = ? AND p.client_id = ? AND p.deleted_at IS NULL AND p.current_stock > 0 ORDER BY (p.current_stock * p.unit_cost) DESC");
$stmt->execute([$company_id, $client_id]);
$closing_stock_detail = $stmt->fetchAll();

$js_revenue_by_outlet = json_encode($revenue_by_outlet, JSON_HEX_TAG | JSON_HEX_APOS);
$js_daily_sales = json_encode($daily_sales_detail, JSON_HEX_TAG | JSON_HEX_APOS);
$js_purchase_details = json_encode($purchase_details, JSON_HEX_TAG | JSON_HEX_APOS);
$js_closing_stock = json_encode($closing_stock_detail, JSON_HEX_TAG | JSON_HEX_APOS);

// Cost Centers (expense by payment method)
$stmt = $pdo->prepare("SELECT payment_method, COUNT(*) as count, COALESCE(SUM(amount),0) as total FROM expense_entries WHERE company_id = ? AND MONTH(entry_date) = ? AND YEAR(entry_date) = ? AND deleted_at IS NULL GROUP BY payment_method ORDER BY total DESC");
$stmt->execute([$company_id, $month, $year]);
$by_payment = $stmt->fetchAll();

// Top vendors
$stmt = $pdo->prepare("SELECT vendor as vendor_name, COUNT(*) as count, COALESCE(SUM(amount),0) as total FROM expense_entries WHERE company_id = ? AND MONTH(entry_date) = ? AND YEAR(entry_date) = ? AND deleted_at IS NULL AND vendor != '' GROUP BY vendor ORDER BY total DESC LIMIT 10");
$stmt->execute([$company_id, $month, $year]);
$top_vendors = $stmt->fetchAll();

$js_categories = json_encode($categories, JSON_HEX_TAG | JSON_HEX_APOS);
$js_expenses = json_encode($expenses, JSON_HEX_TAG | JSON_HEX_APOS);
$js_daily_rev = json_encode($daily_revenue, JSON_HEX_TAG | JSON_HEX_APOS);

// ---- Stock Valuation ----
$valuation_date = $_GET['val_date'] ?? date('Y-m-d');

// Get all departments for this company
$stmt = $pdo->prepare("SELECT id, name, type FROM stock_departments WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL ORDER BY name");
$stmt->execute([$company_id, $client_id]);
$all_departments = $stmt->fetchAll();

// Main Store valuation: products with current_stock (or calculate from movements for historical)
$stmt = $pdo->prepare("SELECT p.id, p.name, p.category, p.current_stock, p.unit_cost, p.selling_price FROM products p WHERE p.company_id = ? AND p.client_id = ? AND p.deleted_at IS NULL ORDER BY p.name");
$stmt->execute([$company_id, $client_id]);
$main_products = $stmt->fetchAll();

$valuation_data = [];
$total_valuation = 0;

// Main Store section
$main_items = [];
$main_total = 0;
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
}
if (!empty($main_items)) {
    $valuation_data[] = [
        'dept_name' => 'Main Store',
        'dept_type' => 'main',
        'items' => $main_items,
        'total_cost' => $main_total,
    ];
    $total_valuation += $main_total;
}

// Department sections
foreach ($all_departments as $dept) {
    // Get all products ever issued to this department
    $stmt = $pdo->prepare("
        SELECT DISTINCT ds.product_id, p.name as product_name, p.category, p.unit_cost, p.selling_price
        FROM department_stock ds
        JOIN products p ON ds.product_id = p.id
        WHERE ds.department_id = ? AND ds.company_id = ? AND ds.client_id = ?
        ORDER BY p.name
    ");
    $stmt->execute([$dept['id'], $company_id, $client_id]);
    $dept_products = $stmt->fetchAll();
    
    $dept_items = [];
    $dept_total = 0;
    foreach ($dept_products as $dp) {
        // Calculate closing as of valuation_date: prior_balance + today's activity
        $pb_stmt = $pdo->prepare("
            SELECT COALESCE(SUM(
                COALESCE(d.opening_stock,0) + COALESCE(d.added,0) + COALESCE(d.return_in,0)
                - COALESCE(d.transfer_out,0) - COALESCE(d.transfer_to_main,0) - COALESCE(d.qty_sold,0)
            ), 0) as closing
            FROM department_stock d
            WHERE d.department_id = ? AND d.product_id = ? AND d.stock_date <= ?
              AND d.company_id = ? AND d.client_id = ?
        ");
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
    }
    if (!empty($dept_items)) {
        $valuation_data[] = [
            'dept_name' => $dept['name'],
            'dept_type' => $dept['type'] ?? 'department',
            'items' => $dept_items,
            'total_cost' => $dept_total,
        ];
        $total_valuation += $dept_total;
    }
}

$js_valuation = json_encode($valuation_data, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Control — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>[x-cloak]{display:none!important}.glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95) 0%,rgba(249,250,251,0.9) 100%);backdrop-filter:blur(20px)}.dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95) 0%,rgba(30,41,59,0.9) 100%)}</style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="financeApp()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8">
            <?php display_flash_message(); ?>

            <!-- Period Selector + KPI Strip -->
            <div class="flex flex-wrap items-end gap-3 mb-4">
                <form method="GET" class="flex items-end gap-2">
                    <div><label class="text-[10px] font-bold text-slate-400 block mb-1">Month</label>
                        <select name="month" class="px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                            <?php for ($m=1;$m<=12;$m++): ?><option value="<?php echo $m; ?>" <?php echo $m==$month?'selected':''; ?>><?php echo date('F',mktime(0,0,0,$m,1)); ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div><label class="text-[10px] font-bold text-slate-400 block mb-1">Year</label><input type="number" name="year" value="<?php echo $year; ?>" min="2020" max="2030" class="px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm w-24"></div>
                    <button type="submit" class="px-4 py-2 bg-gradient-to-r from-amber-500 to-orange-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all">Go</button>
                </form>
                <span class="text-xs font-semibold text-slate-400 ml-auto"><?php echo $month_label; ?></span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Revenue</p><p class="text-xl font-black text-emerald-600"><?php echo format_currency($monthly_revenue); ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Cost of Sales</p><p class="text-xl font-black text-orange-600"><?php echo format_currency($cos); ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Gross Profit</p><p class="text-xl font-black <?php echo $gross_profit>=0?'text-emerald-600':'text-red-600'; ?>"><?php echo format_currency($gross_profit); ?></p><p class="text-[10px] text-slate-400"><?php echo number_format($gross_margin,1); ?>% margin</p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Expenses</p><p class="text-xl font-black text-red-600"><?php echo format_currency($monthly_expenses); ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Net Profit</p><p class="text-xl font-black <?php echo $net_profit>=0?'text-emerald-600':'text-red-600'; ?>"><?php echo format_currency($net_profit); ?></p><p class="text-[10px] text-slate-400"><?php echo number_format($margin,1); ?>% margin</p></div>
            </div>

            <!-- Tabs -->
            <div class="mb-6 flex flex-wrap gap-1.5 p-1.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700">
                <template x-for="t in tabs" :key="t.id">
                    <button @click="currentTab = t.id" :class="currentTab === t.id ? 'bg-white dark:bg-slate-900 text-amber-600 shadow-sm border-amber-200' : 'text-slate-500 hover:bg-white/50 border-transparent'" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all border">
                        <i :data-lucide="t.icon" class="w-3.5 h-3.5"></i><span x-text="t.label"></span>
                    </button>
                </template>
            </div>

            <!-- ========== TAB: Revenue ========== -->
            <div x-show="currentTab === 'revenue'" x-transition>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Revenue Chart -->
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden p-6">
                        <h3 class="font-bold text-slate-900 dark:text-white text-sm mb-4">Daily Revenue Trend</h3>
                        <div class="h-64"><canvas id="revChart"></canvas></div>
                    </div>
                    <!-- Revenue Breakdown -->
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden p-6">
                        <h3 class="font-bold text-slate-900 dark:text-white text-sm mb-4">Payment Channel Breakdown</h3>
                        <div class="space-y-4 mt-6">
                            <div class="flex items-center justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                                <div class="flex items-center gap-3"><div class="w-3 h-3 rounded-full bg-blue-500"></div><span class="text-sm font-semibold text-slate-700 dark:text-slate-300">POS</span></div>
                                <div class="text-right"><span class="text-sm font-bold text-blue-600"><?php echo format_currency($monthly_pos); ?></span><span class="text-xs text-slate-400 ml-2"><?php echo $monthly_revenue > 0 ? number_format(($monthly_pos/$monthly_revenue)*100,1) : 0; ?>%</span></div>
                            </div>
                            <div class="flex items-center justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                                <div class="flex items-center gap-3"><div class="w-3 h-3 rounded-full bg-emerald-500"></div><span class="text-sm font-semibold text-slate-700 dark:text-slate-300">Cash</span></div>
                                <div class="text-right"><span class="text-sm font-bold text-emerald-600"><?php echo format_currency($monthly_cash); ?></span><span class="text-xs text-slate-400 ml-2"><?php echo $monthly_revenue > 0 ? number_format(($monthly_cash/$monthly_revenue)*100,1) : 0; ?>%</span></div>
                            </div>
                            <div class="flex items-center justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                                <div class="flex items-center gap-3"><div class="w-3 h-3 rounded-full bg-violet-500"></div><span class="text-sm font-semibold text-slate-700 dark:text-slate-300">Transfer</span></div>
                                <div class="text-right"><span class="text-sm font-bold text-violet-600"><?php echo format_currency($monthly_transfer); ?></span><span class="text-xs text-slate-400 ml-2"><?php echo $monthly_revenue > 0 ? number_format(($monthly_transfer/$monthly_revenue)*100,1) : 0; ?>%</span></div>
                            </div>
                        </div>
                        <!-- Revenue per Day table -->
                        <div class="mt-6 overflow-x-auto max-h-48 overflow-y-auto">
                            <table class="w-full text-xs"><thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0"><tr><th class="px-2 py-1.5 text-left font-bold text-slate-500">Date</th><th class="px-2 py-1.5 text-right font-bold text-slate-500">POS</th><th class="px-2 py-1.5 text-right font-bold text-slate-500">Cash</th><th class="px-2 py-1.5 text-right font-bold text-slate-500">Transfer</th><th class="px-2 py-1.5 text-right font-bold text-slate-500">Total</th></tr></thead>
                            <tbody><?php foreach ($daily_revenue as $dr): ?><tr class="border-b border-slate-100 dark:border-slate-800"><td class="px-2 py-1.5 font-mono"><?php echo $dr['transaction_date']; ?></td><td class="px-2 py-1.5 text-right"><?php echo format_currency($dr['pos']); ?></td><td class="px-2 py-1.5 text-right"><?php echo format_currency($dr['cash']); ?></td><td class="px-2 py-1.5 text-right"><?php echo format_currency($dr['transfer']); ?></td><td class="px-2 py-1.5 text-right font-bold"><?php echo format_currency($dr['total']); ?></td></tr><?php endforeach; ?>
                            <?php if (empty($daily_revenue)): ?><tr><td colspan="5" class="px-2 py-8 text-center text-slate-400">No revenue data</td></tr><?php endif; ?></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: Expenses ========== -->
            <div x-show="currentTab === 'expenses'" x-transition>
                <!-- Record Expense Form — Full Width Top -->
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden mb-6">
                    <div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30"><i data-lucide="plus" class="w-4 h-4 text-white"></i></div>
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm">Record Expense</h3>
                        </div>
                        <button type="button" @click="showCatForm = !showCatForm" class="text-[10px] font-bold text-amber-600 hover:text-amber-700 flex items-center gap-1 px-2 py-1 rounded-lg hover:bg-amber-50 transition-all">
                            <i data-lucide="plus-circle" class="w-3 h-3"></i>
                            <span x-text="showCatForm ? 'Cancel' : 'Add Category'"></span>
                        </button>
                    </div>
                    <!-- Inline Add Category (collapsible) -->
                    <div x-show="showCatForm" x-transition class="px-6 py-3 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-700">
                        <div class="flex items-end gap-3 flex-wrap">
                            <div class="flex-1 min-w-[180px]"><label class="text-[10px] font-bold text-amber-600 block mb-1">Category Name</label><input type="text" x-model="catForm.name" placeholder="e.g. Rent, Fuel, Repairs" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div class="w-44"><label class="text-[10px] font-bold text-amber-600 block mb-1">Type</label>
                                <select x-model="catForm.type" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="operating">Operating Expense</option>
                                    <option value="administrative">Administrative</option>
                                    <option value="cost_of_sales">Cost of Sales</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <button type="button" @click="saveCategory()" class="px-5 py-2 bg-amber-500 text-white text-xs font-bold rounded-xl hover:bg-amber-600 transition-all shadow-sm">Save Category</button>
                        </div>
                    </div>
                    <form @submit.prevent="saveExpense()" class="p-5">
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3 items-end">
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 block mb-1">Category *</label>
                                <select x-model="expenseForm.category_id" required class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="">Select...</option>
                                    <template x-for="c in categories" :key="c.id"><option :value="c.id" x-text="c.name + ' (' + c.type.replace('_',' ') + ')'"></option></template>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 block mb-1">Amount (₦) *</label>
                                <input type="number" step="0.01" x-model="expenseForm.amount" required class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 block mb-1">Description</label>
                                <input type="text" x-model="expenseForm.description" placeholder="Short note..." class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 block mb-1">Date</label>
                                <input type="date" x-model="expenseForm.entry_date" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 block mb-1">Payment</label>
                                <select x-model="expenseForm.payment_method" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="cash">Cash</option><option value="transfer">Transfer</option><option value="pos">POS</option><option value="cheque">Cheque</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 block mb-1">Vendor</label>
                                <input type="text" x-model="expenseForm.vendor" placeholder="Vendor name" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 block mb-1">&nbsp;</label>
                                <button type="submit" class="w-full py-2 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm">Save</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Expense List -->
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <!-- Header -->
                    <div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-slate-600 to-slate-800 flex items-center justify-center shadow"><i data-lucide="list-filter" class="w-3.5 h-3.5 text-white"></i></div>
                            <div>
                                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Expenses Analysis</h3>
                                <p class="text-[10px] text-slate-400" x-text="filteredExpenses.length + ' of ' + expenses.length + ' entries'"></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold" :class="filteredTotal >= 0 ? 'text-red-600':'text-slate-400'" x-text="'Total: ' + fmt(filteredTotal)"></span>
                        </div>
                    </div>

                    <!-- Filter Toolbar -->
                    <div class="px-5 py-3 bg-slate-50 dark:bg-slate-800/40 border-b border-slate-200 dark:border-slate-700">
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 items-end">
                            <!-- Category -->
                            <div>
                                <label class="text-[9px] font-bold text-slate-400 uppercase tracking-wider block mb-0.5">Category</label>
                                <select x-model="expFilter.category" class="w-full px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                    <option value="">All Categories</option>
                                    <template x-for="c in categories" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
                                </select>
                            </div>
                            <!-- Payment -->
                            <div>
                                <label class="text-[9px] font-bold text-slate-400 uppercase tracking-wider block mb-0.5">Payment</label>
                                <select x-model="expFilter.payment" class="w-full px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                    <option value="">All Methods</option>
                                    <option value="cash">Cash</option>
                                    <option value="transfer">Transfer</option>
                                    <option value="pos">POS</option>
                                    <option value="cheque">Cheque</option>
                                </select>
                            </div>
                            <!-- Vendor Search -->
                            <div>
                                <label class="text-[9px] font-bold text-slate-400 uppercase tracking-wider block mb-0.5">Vendor</label>
                                <input type="text" x-model="expFilter.vendor" placeholder="Search vendor..." class="w-full px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                            </div>
                            <!-- Date From -->
                            <div>
                                <label class="text-[9px] font-bold text-slate-400 uppercase tracking-wider block mb-0.5">From</label>
                                <input type="date" x-model="expFilter.dateFrom" class="w-full px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                            </div>
                            <!-- Date To -->
                            <div>
                                <label class="text-[9px] font-bold text-slate-400 uppercase tracking-wider block mb-0.5">To</label>
                                <input type="date" x-model="expFilter.dateTo" class="w-full px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                            </div>
                            <!-- Clear -->
                            <div>
                                <label class="text-[9px] block mb-0.5">&nbsp;</label>
                                <button @click="expFilter = {category:'',payment:'',vendor:'',dateFrom:'',dateTo:''}" class="w-full px-2.5 py-1.5 bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 rounded-lg text-xs font-bold transition-all flex items-center justify-center gap-1">
                                    <i data-lucide="x" class="w-3 h-3"></i>Clear
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto max-h-[500px] overflow-y-auto"><table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0"><tr>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Date</th>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Category</th>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Description</th>
                            <th class="px-3 py-2.5 text-right text-xs font-bold text-slate-500">Amount</th>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Vendor</th>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Payment</th>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">By</th>
                            <th class="px-3 py-2.5 text-center text-xs font-bold text-slate-500">Actions</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="e in filteredExpenses" :key="e.id">
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                    <td class="px-3 py-2.5 font-mono text-xs" x-text="e.entry_date"></td>
                                    <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold" :class="{'bg-orange-100 text-orange-700':e.category_type==='cost_of_sales','bg-blue-100 text-blue-700':e.category_type==='operating','bg-violet-100 text-violet-700':e.category_type==='administrative','bg-slate-100 text-slate-600':!e.category_type||e.category_type==='other'}" x-text="e.category_name || '—'"></span></td>
                                    <td class="px-3 py-2.5 text-xs max-w-[180px] truncate" x-text="e.description || '—'"></td>
                                    <td class="px-3 py-2.5 text-right font-bold text-red-600" x-text="fmt(e.amount)"></td>
                                    <td class="px-3 py-2.5 text-xs text-slate-500" x-text="e.vendor || '—'"></td>
                                    <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold uppercase" x-text="e.payment_method || '—'"></span></td>
                                    <td class="px-3 py-2.5 text-xs text-slate-500" x-text="(e.first_name||'')+' '+(e.last_name||'')"></td>
                                    <td class="px-3 py-2 text-center">
                                        <div class="flex gap-1 justify-center">
                                            <button @click="editExpense(e)" class="px-2 py-0.5 bg-slate-100 hover:bg-blue-100 text-slate-600 hover:text-blue-700 text-[10px] font-bold rounded-md transition-all" title="Edit">
                                                <i data-lucide="pencil" class="w-3 h-3 inline"></i>
                                            </button>
                                            <button @click="deleteExpense(e.id)" class="px-2 py-0.5 bg-slate-100 hover:bg-red-100 text-slate-600 hover:text-red-700 text-[10px] font-bold rounded-md transition-all" title="Delete">
                                                <i data-lucide="trash-2" class="w-3 h-3 inline"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="filteredExpenses.length === 0"><td colspan="8" class="px-4 py-12 text-center text-slate-400">
                                <template x-if="expenses.length === 0">No expenses recorded this month</template>
                                <template x-if="expenses.length > 0 && filteredExpenses.length === 0">No expenses match your filters</template>
                            </td></tr>
                        </tbody>
                        <!-- Filtered Total Footer -->
                        <tfoot x-show="filteredExpenses.length > 0" class="bg-slate-50 dark:bg-slate-800/50 border-t-2 border-slate-200 dark:border-slate-700">
                            <tr>
                                <td class="px-3 py-2.5 text-xs font-black text-slate-700 dark:text-white uppercase" colspan="3">Filtered Total</td>
                                <td class="px-3 py-2.5 text-right font-black text-red-600" x-text="fmt(filteredTotal)"></td>
                                <td colspan="4" class="px-3 py-2.5 text-xs text-slate-400" x-text="filteredExpenses.length + ' entries'"></td>
                            </tr>
                        </tfoot>
                    </table></div>
                </div>
            </div>

            <!-- ========== TAB: Cost Centers ========== -->
            <div x-show="currentTab === 'cost_centers'" x-transition>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- By Payment Method -->
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden p-6">
                        <h3 class="font-bold text-slate-900 dark:text-white text-sm mb-4">By Payment Method</h3>
                        <div class="space-y-3">
                            <?php foreach ($by_payment as $bp): ?>
                            <div class="flex items-center justify-between py-2">
                                <div class="flex items-center gap-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-slate-100 dark:bg-slate-800 text-slate-600"><?php echo htmlspecialchars($bp['payment_method']); ?></span>
                                    <span class="text-xs text-slate-400"><?php echo $bp['count']; ?> entries</span>
                                </div>
                                <span class="text-sm font-bold text-slate-800 dark:text-white"><?php echo format_currency($bp['total']); ?></span>
                            </div>
                            <div class="w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                <div class="h-full bg-gradient-to-r from-blue-400 to-indigo-500 rounded-full transition-all" style="width:<?php echo $monthly_expenses > 0 ? min(100, ($bp['total']/$monthly_expenses)*100) : 0; ?>%"></div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($by_payment)): ?><p class="text-sm text-slate-400 py-6 text-center">No data</p><?php endif; ?>
                        </div>
                    </div>
                    <!-- By Category Type -->
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden p-6">
                        <h3 class="font-bold text-slate-900 dark:text-white text-sm mb-4">By Expense Type</h3>
                        <div class="grid grid-cols-2 gap-4 mb-6">
                            <div class="p-4 bg-orange-50 dark:bg-orange-900/20 rounded-xl"><p class="text-[10px] font-bold text-orange-500 uppercase">Cost of Sales</p><p class="text-lg font-black text-orange-600"><?php echo format_currency($cos); ?></p></div>
                            <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl"><p class="text-[10px] font-bold text-blue-500 uppercase">Operating</p><p class="text-lg font-black text-blue-600"><?php echo format_currency($opex); ?></p></div>
                            <div class="p-4 bg-violet-50 dark:bg-violet-900/20 rounded-xl"><p class="text-[10px] font-bold text-violet-500 uppercase">Administrative</p><p class="text-lg font-black text-violet-600"><?php echo format_currency($admin); ?></p></div>
                            <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-xl"><p class="text-[10px] font-bold text-slate-500 uppercase">Other</p><p class="text-lg font-black text-slate-600"><?php echo format_currency($other_exp); ?></p></div>
                        </div>
                        <h4 class="text-xs font-bold text-slate-400 uppercase mb-3">Top Vendors</h4>
                        <?php foreach ($top_vendors as $tv): ?>
                        <div class="flex justify-between items-center py-2 border-b border-slate-100 dark:border-slate-800">
                            <span class="text-sm text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($tv['vendor_name']); ?></span>
                            <span class="text-sm font-bold text-red-600"><?php echo format_currency($tv['total']); ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($top_vendors)): ?><p class="text-xs text-slate-400 py-4 text-center">No vendor data</p><?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: P&L Statement ========== -->
            <div x-show="currentTab === 'pnl'" x-transition id="pnl-printable">

                <!-- Print CSS for PDF Export -->
                <style>
                    @media print {
                        body * { visibility: hidden !important; }
                        #pnl-printable, #pnl-printable * { visibility: visible !important; }
                        #pnl-printable { position: absolute; left: 0; top: 0; width: 100%; }
                        .print-hidden { display: none !important; }
                        .print-show { display: block !important; max-height: none !important; overflow: visible !important; }
                        .print-only { display: block !important; visibility: visible !important; }
                        .print-card { box-shadow: none !important; border-radius: 0 !important; border: 1px solid #e2e8f0 !important; }
                        .print-page-break { page-break-before: always; }
                        @page { margin: 15mm 12mm; size: A4; }
                        table { font-size: 11px !important; }
                        .dark\:bg-slate-900 { background: white !important; }
                        .dark\:text-white, .dark\:text-slate-300 { color: #1e293b !important; }
                        /* Cover page print styles */
                        .cover-page {
                            page-break-after: always;
                            min-height: 100vh;
                            display: flex !important;
                            align-items: center !important;
                            justify-content: center !important;
                            visibility: visible !important;
                        }
                        .cover-page * { visibility: visible !important; }
                        #viewer-banner { display: none !important; }
                    }
                </style>

                <!-- ════════ PERIOD SELECTOR ════════ -->
                <div class="max-w-4xl mx-auto mb-6 print-hidden" x-data="{ mode: '<?php echo $is_custom_range ? 'custom' : 'month'; ?>' }">
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 px-6 py-4">
                        <div class="flex flex-wrap items-center gap-3 mb-3">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Report Period:</span>
                            <div class="flex rounded-lg border border-slate-300 dark:border-slate-600 overflow-hidden text-xs font-bold">
                                <button @click="mode='month'" :class="mode==='month' ? 'bg-violet-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300'" class="px-3 py-1.5 transition-all">Monthly</button>
                                <button @click="mode='custom'" :class="mode==='custom' ? 'bg-violet-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300'" class="px-3 py-1.5 transition-all border-l border-slate-300 dark:border-slate-600">Custom Range</button>
                            </div>
                        </div>
                        <!-- Monthly Selector -->
                        <div x-show="mode==='month'" class="flex flex-wrap items-center gap-3">
                            <select id="pnl-month" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                                <?php endfor; ?>
                            </select>
                            <select id="pnl-year" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold">
                                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                            <button onclick="var m=document.getElementById('pnl-month').value,y=document.getElementById('pnl-year').value; window.location.href='finance.php?month='+m+'&year='+y+'#pnl';" class="px-4 py-1.5 bg-violet-600 hover:bg-violet-500 text-white text-xs font-bold rounded-lg transition-all flex items-center gap-1.5">
                                <i data-lucide="refresh-cw" class="w-3 h-3"></i> View Report
                            </button>
                        </div>
                        <!-- Custom Date Range -->
                        <div x-show="mode==='custom'" class="flex flex-wrap items-center gap-3">
                            <label class="text-xs text-slate-500 font-semibold">From:</label>
                            <input type="date" id="pnl-from" value="<?php echo $is_custom_range ? $period_start : date('Y-m-01'); ?>" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold">
                            <label class="text-xs text-slate-500 font-semibold">To:</label>
                            <input type="date" id="pnl-to" value="<?php echo $is_custom_range ? $period_end : date('Y-m-t'); ?>" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold">
                            <button onclick="var f=document.getElementById('pnl-from').value,t=document.getElementById('pnl-to').value; if(!f||!t){alert('Select both dates');return;} window.location.href='finance.php?from='+f+'&to='+t+'#pnl';" class="px-4 py-1.5 bg-violet-600 hover:bg-violet-500 text-white text-xs font-bold rounded-lg transition-all flex items-center gap-1.5">
                                <i data-lucide="refresh-cw" class="w-3 h-3"></i> View Report
                            </button>
                        </div>
                        <!-- Download PDF Button -->
                        <div class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                            <span class="text-[10px] text-slate-400">Current: <strong class="text-slate-600 dark:text-slate-300"><?php echo $month_label; ?></strong></span>
                            <button onclick="window.print()" class="px-5 py-2 bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-bold rounded-lg hover:opacity-80 transition-all flex items-center gap-2 shadow-lg">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Download PDF
                            </button>
                        </div>
                    </div>
                </div>

                <div class="max-w-4xl mx-auto space-y-6">

                    <!-- ════════════════════════════════════════════════════════ -->
                    <!--  COVER PAGE (Print Only)                                -->
                    <!-- ════════════════════════════════════════════════════════ -->
                    <div class="cover-page hidden print-only" style="display:none; align-items:center; justify-content:center;">
                        <div style="border: 4px double #1e293b; padding: 60px 50px; width: 100%; max-width: 600px; margin: auto; text-align: center;">
                            <div style="border: 1px solid #94a3b8; padding: 50px 40px;">
                                <p style="font-size: 22px; font-weight: 800; letter-spacing: 0.1em; color: #0f172a; text-transform: uppercase; margin-bottom: 30px;"><?php echo htmlspecialchars($client_name); ?></p>
                                <div style="width: 80px; height: 2px; background: #1e293b; margin: 0 auto 30px;"></div>
                                <h1 style="font-size: 28px; font-weight: 900; color: #0f172a; letter-spacing: 0.05em; margin-bottom: 20px; text-transform: uppercase;">Routine Audit Report</h1>
                                <div style="width: 120px; height: 3px; background: linear-gradient(90deg, #7c3aed, #6d28d9); margin: 0 auto 30px;"></div>
                                <p style="font-size: 16px; font-weight: 600; color: #334155; margin-bottom: 8px;">Statement of Profit or Loss</p>
                                <p style="font-size: 14px; color: #64748b; margin-bottom: 40px;">For the period ended <?php echo date('F j, Y', mktime(0,0,0,$month+1,0,$year)); ?></p>
                                <div style="width: 80px; height: 2px; background: #1e293b; margin: 0 auto 20px;"></div>
                                <p style="font-size: 10px; color: #94a3b8; letter-spacing: 0.15em; text-transform: uppercase;">Prepared: <?php echo date('d F Y'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- ════════════════════════════════════════════════════════ -->
                    <!--  SECTION 1: STATEMENT OF PROFIT OR LOSS                -->
                    <!-- ════════════════════════════════════════════════════════ -->
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden print-card">

                        <!-- Statement Header -->
                        <div class="px-8 py-6 bg-gradient-to-b from-slate-50 to-white dark:from-slate-800 dark:to-slate-900 border-b border-slate-200 dark:border-slate-700 text-center">
                            <p class="text-[18px] font-bold text-violet-700 uppercase tracking-wider mb-2"><?php echo htmlspecialchars($client_name); ?></p>
                            <h2 class="text-xl font-black text-slate-900 dark:text-white tracking-tight">STATEMENT OF PROFIT OR LOSS</h2>
                            <p class="text-sm text-slate-500 mt-1">For the period <?php echo $is_custom_range ? date('M j', strtotime($period_start)) . ' – ' . date('M j, Y', strtotime($period_end)) : 'ended ' . date('F j, Y', mktime(0,0,0,$month+1,0,$year)); ?></p>
                            <p class="text-[10px] text-slate-400 mt-1 print-hidden">Prepared: <?php echo date('d M Y'); ?></p>
                        </div>

                        <!-- Statement Body -->
                        <div class="px-8 py-6">
                            <table class="w-full text-sm" style="border-collapse: collapse;">
                                <colgroup>
                                    <col style="width: 55%">
                                    <col style="width: 22%">
                                    <col style="width: 23%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th class="text-left py-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider"></th>
                                        <th class="text-right py-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider pr-4">Notes</th>
                                        <th class="text-right py-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider"><?php echo date('M Y', mktime(0,0,0,$month,1,$year)); ?> (₦)</th>
                                    </tr>
                                    <tr><td colspan="3" class="border-b-2 border-slate-800 dark:border-slate-300"></td></tr>
                                </thead>
                                <tbody class="text-slate-700 dark:text-slate-300">

                                    <!-- ═══ REVENUE ═══ -->
                                    <tr>
                                        <td class="pt-5 pb-2 font-black text-slate-900 dark:text-white text-xs uppercase tracking-wider">Revenue</td>
                                        <td></td><td></td>
                                    </tr>
                                    <tr>
                                        <td class="py-1.5 pl-6">Sales Revenue</td>
                                        <td class="text-right pr-4 text-xs text-slate-400">1</td>
                                        <td class="text-right font-semibold tabular-nums"><?php echo number_format($monthly_revenue, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td></td><td></td>
                                        <td class="border-t border-slate-300 dark:border-slate-600 text-right font-bold pt-1 tabular-nums text-slate-900 dark:text-white"><?php echo number_format($monthly_revenue, 2); ?></td>
                                    </tr>

                                    <!-- ═══ COST OF SALES ═══ -->
                                    <tr>
                                        <td class="pt-5 pb-2 font-black text-slate-900 dark:text-white text-xs uppercase tracking-wider">Cost of Sales</td>
                                        <td class="text-right pr-4 text-xs text-slate-400 pt-5">2</td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td class="py-1.5 pl-6">Opening Stock
                                            <?php if ($needs_manual_opening): ?>
                                            <span class="text-[10px] text-amber-600 font-semibold ml-1">(First period — enter manually)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td></td>
                                        <td class="text-right font-semibold tabular-nums">
                                            <?php if ($needs_manual_opening): ?>
                                            <div class="flex items-center justify-end gap-1 print-hidden" id="opening-input-row">
                                                <span class="text-xs text-slate-400">₦</span>
                                                <input type="number" id="manual-opening-val" step="0.01" min="0" value="0" 
                                                    class="w-28 px-2 py-1 text-right text-xs font-bold border border-amber-400 rounded-lg bg-amber-50 dark:bg-amber-900/20 dark:border-amber-600 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-400" placeholder="0.00">
                                                <button onclick="saveManualOpening()" class="px-2 py-1 bg-amber-500 hover:bg-amber-600 text-white text-[10px] font-bold rounded-lg transition-all">Set</button>
                                            </div>
                                            <span class="hidden print-show"><?php echo number_format($pnl_opening, 2); ?></span>
                                            <?php else: ?>
                                            <?php echo number_format($pnl_opening, 2); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-1.5 pl-6">Add: Purchases</td>
                                        <td></td>
                                        <td class="text-right font-semibold tabular-nums"><?php echo number_format($pnl_purchases, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="py-1.5 pl-6">Less: Closing Stock</td>
                                        <td></td>
                                        <td class="text-right font-semibold tabular-nums">(<?php echo number_format($pnl_closing, 2); ?>)</td>
                                    </tr>
                                    <tr>
                                        <td class="pl-6 pt-1 font-semibold text-slate-600 dark:text-slate-400 text-xs">Cost of Sales Total</td>
                                        <td></td>
                                        <td class="border-t border-slate-300 dark:border-slate-600 text-right font-bold pt-1 tabular-nums text-red-600">(<?php echo number_format(abs($pnl_cos), 2); ?>)</td>
                                    </tr>

                                    <!-- ═══ GROSS PROFIT ═══ -->
                                    <tr><td colspan="3" class="pt-2"></td></tr>
                                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                                        <td class="py-3 pl-2 font-black text-slate-900 dark:text-white uppercase text-xs tracking-wider">Gross Profit</td>
                                        <td class="text-right pr-4 text-xs text-slate-400"><?php echo number_format($pnl_gross_margin, 1); ?>%</td>
                                        <td class="text-right font-black text-base tabular-nums <?php echo $pnl_gross_profit >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'; ?> border-t-2 border-b-2 border-slate-800 dark:border-slate-300">
                                            <?php echo $pnl_gross_profit < 0 ? '(' . number_format(abs($pnl_gross_profit), 2) . ')' : number_format($pnl_gross_profit, 2); ?>
                                        </td>
                                    </tr>

                                    <!-- ═══ OPERATING EXPENSES ═══ -->
                                    <tr>
                                        <td class="pt-5 pb-2 font-black text-slate-900 dark:text-white text-xs uppercase tracking-wider">Operational Expenses</td>
                                        <td class="text-right pr-4 text-xs text-slate-400 pt-5">3</td>
                                        <td class="text-right font-bold pt-5 tabular-nums text-red-600">(<?php echo number_format($opex, 2); ?>)</td>
                                    </tr>

                                    <!-- ═══ ADMINISTRATIVE EXPENSES ═══ -->
                                    <tr>
                                        <td class="pt-3 pb-2 font-black text-slate-900 dark:text-white text-xs uppercase tracking-wider">Administrative Expenses</td>
                                        <td class="text-right pr-4 text-xs text-slate-400 pt-3">4</td>
                                        <td class="text-right font-bold pt-3 tabular-nums text-red-600">(<?php echo number_format($admin, 2); ?>)</td>
                                    </tr>

                                    <!-- ═══ OTHER EXPENSES (if any) ═══ -->
                                    <?php if ($other_exp > 0): ?>
                                    <tr>
                                        <td class="pt-5 pb-2 font-black text-slate-900 dark:text-white text-xs uppercase tracking-wider">Other Expenses</td>
                                        <td></td><td></td>
                                    </tr>
                                    <?php foreach ($breakdown as $b): if (!in_array($b['type'], ['cost_of_sales','operating','administrative'])): ?>
                                    <tr>
                                        <td class="py-1.5 pl-6"><?php echo htmlspecialchars($b['name']); ?></td>
                                        <td></td>
                                        <td class="text-right font-semibold tabular-nums">(<?php echo number_format($b['total'], 2); ?>)</td>
                                    </tr>
                                    <?php endif; endforeach; ?>
                                    <tr>
                                        <td class="pl-6 font-semibold text-slate-600 dark:text-slate-400 text-xs uppercase pt-1">Total Other Expenses</td>
                                        <td></td>
                                        <td class="border-t border-slate-300 dark:border-slate-600 text-right font-bold pt-1 tabular-nums text-red-600">(<?php echo number_format($other_exp, 2); ?>)</td>
                                    </tr>
                                    <?php endif; ?>

                                    <!-- ═══ NET PROFIT / (LOSS) ═══ -->
                                    <tr><td colspan="3" class="pt-4"></td></tr>
                                    <tr class="<?php echo $pnl_net_profit >= 0 ? 'bg-emerald-50 dark:bg-emerald-900/10' : 'bg-red-50 dark:bg-red-900/10'; ?>">
                                        <td class="py-4 pl-2 font-black text-base uppercase tracking-wider <?php echo $pnl_net_profit >= 0 ? 'text-emerald-800 dark:text-emerald-300' : 'text-red-800 dark:text-red-300'; ?>">
                                            Net <?php echo $pnl_net_profit >= 0 ? 'Profit' : 'Loss'; ?> for the Period
                                        </td>
                                        <td class="text-right pr-4 text-xs font-bold <?php echo $pnl_net_profit >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>"><?php echo number_format(abs($pnl_net_margin), 1); ?>%</td>
                                        <td class="text-right text-xl font-black tabular-nums border-t-2 border-b-[3px] double border-slate-800 dark:border-slate-300 <?php echo $pnl_net_profit >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'; ?>">
                                            <?php echo $pnl_net_profit < 0 ? '(' . number_format(abs($pnl_net_profit), 2) . ')' : number_format($pnl_net_profit, 2); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Signatory Footer -->
                        <div class="px-8 py-5 border-t border-slate-200 dark:border-slate-700">
                            <div class="flex justify-between items-end">
                                <p class="text-[10px] text-slate-400">Figures in brackets ( ) denote expense / deduction items.</p>
                                <div class="text-right">
                                    <p class="text-[10px] text-slate-400">_______________________________</p>
                                    <p class="text-[10px] font-bold text-slate-500 mt-1">Authorized Signatory</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ════════════════════════════════════════════════════════ -->
                    <!--  SECTION 2: NOTES TO THE ACCOUNTS                       -->
                    <!-- ════════════════════════════════════════════════════════ -->
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden print-card">
                        <!-- Notes Header -->
                        <div class="px-8 py-5 bg-gradient-to-b from-slate-50 to-white dark:from-slate-800 dark:to-slate-900 border-b border-slate-200 dark:border-slate-700 text-center">
                            <p class="text-[18px] font-bold text-violet-700 uppercase tracking-wider mb-2"><?php echo htmlspecialchars($client_name); ?></p>
                            <h2 class="text-lg font-black text-slate-900 dark:text-white tracking-tight">NOTES TO THE FINANCIAL STATEMENTS</h2>
                            <p class="text-xs text-slate-500 mt-1">For the period ended <?php echo date('F j, Y', mktime(0,0,0,$month+1,0,$year)); ?></p>
                        </div>

                        <!-- Notes Body -->
                        <div class="px-8 py-6">
                            <table class="w-full text-sm" style="border-collapse: collapse;">
                                <colgroup>
                                    <col style="width: 8%">
                                    <col style="width: 92%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th class="text-left py-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider">Note</th>
                                        <th class="text-left py-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider">Description</th>
                                    </tr>
                                    <tr><td colspan="2" class="border-b-2 border-slate-800 dark:border-slate-300"></td></tr>
                                </thead>
                                <tbody class="text-slate-700 dark:text-slate-300">
                                    <!-- Note 1: Revenue -->
                                    <tr>
                                        <td class="py-4 align-top font-black text-slate-900 dark:text-white text-sm">1.</td>
                                        <td class="py-4">
                                            <p class="font-black text-slate-900 dark:text-white text-xs uppercase tracking-wider mb-2">Revenue</p>
                                            <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-400 mb-3">
                                                Revenue represents all sales recorded through the daily audit module for the period <strong class="text-slate-700 dark:text-slate-300"><?php echo $month_label; ?></strong>.
                                                This includes POS, cash, and transfer transactions across all outlets. Total revenue for the period amounted to
                                                <strong class="text-slate-700 dark:text-slate-300"><?php echo format_currency($monthly_revenue); ?></strong>.
                                            </p>
                                            <?php if (!empty($outlet_weekly)): ?>
                                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Outlet Sales — Weekly Breakdown</p>
                                            <table class="w-full text-xs mb-2" style="border-collapse: collapse;">
                                                <thead>
                                                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                                                        <th class="text-left py-2 px-3 text-[10px] font-bold text-slate-500 uppercase">Outlet</th>
                                                        <?php for ($w = 1; $w <= $max_weeks; $w++): ?>
                                                        <th class="text-right py-2 px-2 text-[10px] font-bold text-slate-500 uppercase">Wk <?php echo $w; ?></th>
                                                        <?php endfor; ?>
                                                        <th class="text-right py-2 px-3 text-[10px] font-bold text-slate-500 uppercase">Total</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $grand_total = 0; foreach ($outlet_weekly as $oname => $odata): $grand_total += $odata['total']; ?>
                                                    <tr class="border-b border-slate-100 dark:border-slate-800">
                                                        <td class="py-1.5 px-3 font-semibold text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($oname); ?></td>
                                                        <?php for ($w = 1; $w <= $max_weeks; $w++): ?>
                                                        <td class="py-1.5 px-2 text-right tabular-nums"><?php echo isset($odata['weeks'][$w]) ? number_format($odata['weeks'][$w], 2) : '—'; ?></td>
                                                        <?php endfor; ?>
                                                        <td class="py-1.5 px-3 text-right font-bold tabular-nums"><?php echo number_format($odata['total'], 2); ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                                                        <td class="py-2 px-3 font-black text-slate-800 dark:text-white text-xs uppercase">Grand Total</td>
                                                        <?php for ($w = 1; $w <= $max_weeks; $w++): ?>
                                                        <td class="py-2 px-2"></td>
                                                        <?php endfor; ?>
                                                        <td class="py-2 px-3 text-right font-black tabular-nums border-t-2 border-slate-800 dark:border-slate-300"><?php echo number_format($grand_total, 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <?php else: ?>
                                            <p class="text-[10px] text-slate-400 italic">No outlet-level breakdown available for this period.</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr><td colspan="2" class="border-b border-slate-100 dark:border-slate-800"></td></tr>

                                    <!-- Note 2: Cost of Sales -->
                                    <tr>
                                        <td class="py-4 align-top font-black text-slate-900 dark:text-white text-sm">2.</td>
                                        <td class="py-4">
                                            <p class="font-black text-slate-900 dark:text-white text-xs uppercase tracking-wider mb-2">Cost of Sales</p>
                                            <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-400 mb-3">
                                                Cost of Sales is computed using the standard accounting formula:
                                                <strong class="text-slate-700 dark:text-slate-300">Opening Stock + Purchases − Closing Stock</strong>.
                                            </p>
                                            <table class="w-full text-xs mb-2">
                                                <tr class="border-b border-slate-100 dark:border-slate-800">
                                                    <td class="py-1.5 pl-4 text-slate-500">Opening Stock (beginning of period)</td>
                                                    <td class="py-1.5 text-right font-semibold tabular-nums pr-4"><?php echo format_currency($pnl_opening); ?></td>
                                                </tr>
                                                <tr class="border-b border-slate-100 dark:border-slate-800">
                                                    <td class="py-1.5 pl-4 text-slate-500">Add: Purchases (supplier deliveries received)</td>
                                                    <td class="py-1.5 text-right font-semibold tabular-nums pr-4"><?php echo format_currency($pnl_purchases); ?></td>
                                                </tr>
                                                <tr class="border-b border-slate-100 dark:border-slate-800">
                                                    <td class="py-1.5 pl-4 text-slate-500">Less: Closing Stock (end of period valuation)</td>
                                                    <td class="py-1.5 text-right font-semibold tabular-nums pr-4">(<?php echo format_currency($pnl_closing); ?>)</td>
                                                </tr>
                                                <tr>
                                                    <td class="py-1.5 pl-4 font-bold text-slate-700 dark:text-slate-300">Cost of Sales</td>
                                                    <td class="py-1.5 text-right font-black tabular-nums pr-4 border-t-2 border-slate-800 dark:border-slate-300"><?php echo format_currency(abs($pnl_cos)); ?></td>
                                                </tr>
                                            </table>
                                            <p class="text-[10px] text-slate-400 italic">Opening stock is derived from the prior period closing stock valuation. Purchases include all supplier deliveries recorded during the period. Closing stock represents current inventory at cost across main store and all departments.</p>
                                        </td>
                                    </tr>
                                    <tr><td colspan="2" class="border-b border-slate-100 dark:border-slate-800"></td></tr>

                                    <!-- Note 3: Operational Expenses -->
                                    <tr>
                                        <td class="py-4 align-top font-black text-slate-900 dark:text-white text-sm">3.</td>
                                        <td class="py-4">
                                            <p class="font-black text-slate-900 dark:text-white text-xs uppercase tracking-wider mb-2">Operational Expenses</p>
                                            <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-400 mb-3">
                                                All expenses classified under the <strong class="text-slate-700 dark:text-slate-300">"Operating"</strong> category for the period.
                                            </p>
                                            <table class="w-full text-xs mb-2">
                                                <?php $has_opex_note = false; foreach ($breakdown as $b): if ($b['type'] === 'operating'): $has_opex_note = true; ?>
                                                <tr class="border-b border-slate-100 dark:border-slate-800">
                                                    <td class="py-1.5 pl-4 text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($b['name']); ?></td>
                                                    <td class="py-1.5 text-right font-semibold tabular-nums pr-4"><?php echo format_currency($b['total']); ?></td>
                                                </tr>
                                                <?php endif; endforeach; ?>
                                                <?php if (!$has_opex_note): ?>
                                                <tr><td class="py-1.5 pl-4 text-slate-400 italic" colspan="2">No operational expenses this period</td></tr>
                                                <?php endif; ?>
                                                <tr>
                                                    <td class="py-1.5 pl-4 font-bold text-slate-700 dark:text-slate-300">Total Operational Expenses</td>
                                                    <td class="py-1.5 text-right font-black tabular-nums pr-4 border-t-2 border-slate-800 dark:border-slate-300"><?php echo format_currency($opex); ?></td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr><td colspan="2" class="border-b border-slate-100 dark:border-slate-800"></td></tr>

                                    <!-- Note 4: Administrative Expenses -->
                                    <tr>
                                        <td class="py-4 align-top font-black text-slate-900 dark:text-white text-sm">4.</td>
                                        <td class="py-4">
                                            <p class="font-black text-slate-900 dark:text-white text-xs uppercase tracking-wider mb-2">Administrative Expenses</p>
                                            <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-400 mb-3">
                                                All expenses classified under the <strong class="text-slate-700 dark:text-slate-300">"Administrative"</strong> category for the period.
                                            </p>
                                            <table class="w-full text-xs mb-2">
                                                <?php $has_admin_note = false; foreach ($breakdown as $b): if ($b['type'] === 'administrative'): $has_admin_note = true; ?>
                                                <tr class="border-b border-slate-100 dark:border-slate-800">
                                                    <td class="py-1.5 pl-4 text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($b['name']); ?></td>
                                                    <td class="py-1.5 text-right font-semibold tabular-nums pr-4"><?php echo format_currency($b['total']); ?></td>
                                                </tr>
                                                <?php endif; endforeach; ?>
                                                <?php if (!$has_admin_note): ?>
                                                <tr><td class="py-1.5 pl-4 text-slate-400 italic" colspan="2">No administrative expenses this period</td></tr>
                                                <?php endif; ?>
                                                <tr>
                                                    <td class="py-1.5 pl-4 font-bold text-slate-700 dark:text-slate-300">Total Administrative Expenses</td>
                                                    <td class="py-1.5 text-right font-black tabular-nums pr-4 border-t-2 border-slate-800 dark:border-slate-300"><?php echo format_currency($admin); ?></td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Notes Footer -->
                        <div class="px-8 py-4 border-t border-slate-200 dark:border-slate-700">
                            <p class="text-[10px] text-slate-400">These notes form an integral part of the financial statements. All figures are stated in Nigerian Naira (₦) unless otherwise indicated.</p>
                        </div>
                    </div>

                    <!-- ════════════════════════════════════════════════════════ -->
                    <!--  SECTION 3: KEY PERFORMANCE METRICS                     -->
                    <!-- ════════════════════════════════════════════════════════ -->
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden print-card print-page-break">

                        <!-- Metrics Header -->
                        <div class="px-8 py-5 bg-gradient-to-b from-slate-50 to-white dark:from-slate-800 dark:to-slate-900 border-b border-slate-200 dark:border-slate-700 text-center">
                            <p class="text-[18px] font-bold text-violet-700 uppercase tracking-wider mb-2"><?php echo htmlspecialchars($client_name); ?></p>
                            <h2 class="text-lg font-black text-slate-900 dark:text-white tracking-tight">KEY PERFORMANCE METRICS</h2>
                            <p class="text-xs text-slate-500 mt-1">For the period ended <?php echo date('F j, Y', mktime(0,0,0,$month+1,0,$year)); ?></p>
                        </div>

                        <!-- Metrics Body -->
                        <div class="px-8 py-6">

                            <?php
                            // Dynamic Metrics Calculations
                            $total_expenses = $opex + $admin + $other_exp;
                            $days_in_period = date('t', mktime(0,0,0,$month,1,$year));
                            $trading_days = count($daily_revenue);
                            $avg_daily_revenue = $trading_days > 0 ? $monthly_revenue / $trading_days : 0;
                            $avg_daily_cos = $trading_days > 0 ? $pnl_cos / $trading_days : 0;
                            $expense_ratio = $monthly_revenue > 0 ? ($total_expenses / $monthly_revenue) * 100 : 0;
                            $cos_ratio = $monthly_revenue > 0 ? ($pnl_cos / $monthly_revenue) * 100 : 0;
                            $stock_turnover = $pnl_closing > 0 ? $pnl_cos / $pnl_closing : 0;
                            $stock_days = $stock_turnover > 0 ? 30 / $stock_turnover : 0;
                            $total_pos = $monthly_pos;
                            $total_cash = $monthly_cash;
                            $total_transfer = $monthly_revenue - $monthly_pos - $monthly_cash;
                            ?>

                            <!-- Sales Metrics -->
                            <div class="mb-6">
                                <p class="text-xs font-black text-slate-900 dark:text-white uppercase tracking-wider mb-3 pb-2 border-b-2 border-slate-800 dark:border-slate-300">Sales Metrics</p>
                                <table class="w-full text-sm">
                                    <tbody class="text-slate-700 dark:text-slate-300">
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Total Revenue</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($monthly_revenue, 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Trading Days</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo $trading_days; ?> of <?php echo $days_in_period; ?> days</td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Average Daily Revenue</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($avg_daily_revenue, 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">POS Revenue</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($total_pos, 2); ?> <span class="text-[10px] text-slate-400">(<?php echo $monthly_revenue > 0 ? number_format(($total_pos/$monthly_revenue)*100, 1) : 0; ?>%)</span></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Cash Revenue</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($total_cash, 2); ?> <span class="text-[10px] text-slate-400">(<?php echo $monthly_revenue > 0 ? number_format(($total_cash/$monthly_revenue)*100, 1) : 0; ?>%)</span></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Transfer Revenue</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($total_transfer, 2); ?> <span class="text-[10px] text-slate-400">(<?php echo $monthly_revenue > 0 ? number_format(($total_transfer/$monthly_revenue)*100, 1) : 0; ?>%)</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Cost Metrics -->
                            <div class="mb-6">
                                <p class="text-xs font-black text-slate-900 dark:text-white uppercase tracking-wider mb-3 pb-2 border-b-2 border-slate-800 dark:border-slate-300">Cost Metrics</p>
                                <table class="w-full text-sm">
                                    <tbody class="text-slate-700 dark:text-slate-300">
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Cost of Sales</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format(abs($pnl_cos), 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Cost of Sales Ratio</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($cos_ratio, 1); ?>%</td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Average Daily Cost of Sales</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($avg_daily_cos, 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Total Operating Expenses</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($opex, 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Total Administrative Expenses</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($admin, 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Expense-to-Revenue Ratio</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($expense_ratio, 1); ?>%</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Profitability Metrics -->
                            <div class="mb-6">
                                <p class="text-xs font-black text-slate-900 dark:text-white uppercase tracking-wider mb-3 pb-2 border-b-2 border-slate-800 dark:border-slate-300">Profitability Metrics</p>
                                <table class="w-full text-sm">
                                    <tbody class="text-slate-700 dark:text-slate-300">
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Gross Profit</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2 <?php echo $pnl_gross_profit >= 0 ? 'text-emerald-700' : 'text-red-600'; ?>"><?php echo number_format($pnl_gross_profit, 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Gross Profit Margin</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2 <?php echo $pnl_gross_margin >= 0 ? 'text-emerald-700' : 'text-red-600'; ?>"><?php echo number_format($pnl_gross_margin, 1); ?>%</td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Net <?php echo $pnl_net_profit >= 0 ? 'Profit' : 'Loss'; ?></td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2 <?php echo $pnl_net_profit >= 0 ? 'text-emerald-700' : 'text-red-600'; ?>"><?php echo $pnl_net_profit < 0 ? '(' . number_format(abs($pnl_net_profit), 2) . ')' : number_format($pnl_net_profit, 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Net Profit Margin</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2 <?php echo $pnl_net_margin >= 0 ? 'text-emerald-700' : 'text-red-600'; ?>"><?php echo number_format(abs($pnl_net_margin), 1); ?>%</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Stock Metrics -->
                            <div class="mb-4">
                                <p class="text-xs font-black text-slate-900 dark:text-white uppercase tracking-wider mb-3 pb-2 border-b-2 border-slate-800 dark:border-slate-300">Stock Metrics</p>
                                <table class="w-full text-sm">
                                    <tbody class="text-slate-700 dark:text-slate-300">
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Opening Stock Value</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($pnl_opening, 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Purchases (Inward)</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($pnl_purchases, 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Closing Stock Value</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($pnl_closing, 2); ?></td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Stock Turnover Ratio</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($stock_turnover, 2); ?>x</td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Days of Stock on Hand</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo number_format($stock_days, 0); ?> days</td>
                                        </tr>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2.5 pl-2">Stock Sections (Stores/Departments)</td>
                                            <td class="py-2.5 text-right font-bold tabular-nums pr-2"><?php echo count($valuation_data); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                        </div>

                        <!-- Metrics Footer -->
                        <div class="px-8 py-4 border-t border-slate-200 dark:border-slate-700">
                            <p class="text-[10px] text-slate-400">All metrics are automatically computed from the operational data for <?php echo htmlspecialchars($client_name); ?> during the reporting period. Figures in Nigerian Naira (₦).</p>
                        </div>
                    </div>

                    <!-- ════════════════════════════════════════════════════════ -->
                    <!--  SECTION 4: APPENDIX — SUPPORTING SCHEDULES             -->
                    <!-- ════════════════════════════════════════════════════════ -->
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden print-card print-page-break" x-data="{ showSales: false, showPurchases: false, showClosing: false }">

                        <!-- Appendix Header -->
                        <div class="px-8 py-5 bg-gradient-to-b from-slate-50 to-white dark:from-slate-800 dark:to-slate-900 border-b border-slate-200 dark:border-slate-700 text-center">
                            <p class="text-[18px] font-bold text-violet-700 uppercase tracking-wider mb-2"><?php echo htmlspecialchars($client_name); ?></p>
                            <h2 class="text-lg font-black text-slate-900 dark:text-white tracking-tight">APPENDIX — SUPPORTING SCHEDULES</h2>
                            <p class="text-xs text-slate-500 mt-1">For the period ended <?php echo date('F j, Y', mktime(0,0,0,$month+1,0,$year)); ?></p>
                        </div>

                        <!-- Schedule A: Daily Sales -->
                        <div class="border-b border-slate-200 dark:border-slate-700">
                            <button @click="showSales = !showSales; $nextTick(() => lucide.createIcons())" class="w-full px-8 py-4 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-all print-hidden">
                                <div class="flex items-center gap-3">
                                    <span class="text-xs font-black text-slate-900 dark:text-white uppercase tracking-wider">Schedule A</span>
                                    <span class="text-xs text-slate-500">— Daily Sales Analysis</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] text-slate-400 tabular-nums" x-text="dailySales.length + ' entries'"></span>
                                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 transition-transform" :class="showSales && 'rotate-180'"></i>
                                </div>
                            </button>
                            <!-- Print header (visible only in print) -->
                            <div class="hidden print-show px-8 py-3 border-b border-slate-200">
                                <p class="text-xs font-black text-slate-900 uppercase tracking-wider">Schedule A — Daily Sales Analysis</p>
                            </div>
                            <div x-show="showSales" x-transition class="overflow-x-auto print-show" style="display: none;">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                                        <tr>
                                            <th class="px-6 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Date</th>
                                            <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Outlet</th>
                                            <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">POS (₦)</th>
                                            <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Cash (₦)</th>
                                            <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Transfer (₦)</th>
                                            <th class="px-6 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Total (₦)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="s in dailySales" :key="s.transaction_date+s.outlet_name">
                                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                                <td class="px-6 py-2 font-mono text-xs" x-text="s.transaction_date"></td>
                                                <td class="px-4 py-2 text-xs" x-text="s.outlet_name || '—'"></td>
                                                <td class="px-4 py-2 text-right tabular-nums text-xs" x-text="fmt(s.pos_amount)"></td>
                                                <td class="px-4 py-2 text-right tabular-nums text-xs" x-text="fmt(s.cash_amount)"></td>
                                                <td class="px-4 py-2 text-right tabular-nums text-xs" x-text="fmt(s.transfer_amount)"></td>
                                                <td class="px-6 py-2 text-right font-semibold tabular-nums text-xs" x-text="fmt(s.actual_total)"></td>
                                            </tr>
                                        </template>
                                        <tr x-show="dailySales.length === 0"><td colspan="6" class="px-6 py-8 text-center text-slate-400 text-xs">No sales data for this period</td></tr>
                                    </tbody>
                                    <tfoot x-show="dailySales.length > 0" class="border-t-2 border-slate-800 dark:border-slate-300">
                                        <tr>
                                            <td class="px-6 py-2.5 font-black text-xs uppercase" colspan="2">Total</td>
                                            <td class="px-4 py-2.5 text-right font-bold tabular-nums text-xs" x-text="fmt(dailySales.reduce((s,r) => s+parseFloat(r.pos_amount||0),0))"></td>
                                            <td class="px-4 py-2.5 text-right font-bold tabular-nums text-xs" x-text="fmt(dailySales.reduce((s,r) => s+parseFloat(r.cash_amount||0),0))"></td>
                                            <td class="px-4 py-2.5 text-right font-bold tabular-nums text-xs" x-text="fmt(dailySales.reduce((s,r) => s+parseFloat(r.transfer_amount||0),0))"></td>
                                            <td class="px-6 py-2.5 text-right font-black tabular-nums text-xs border-b-[3px] double border-slate-800 dark:border-slate-300" x-text="fmt(dailySales.reduce((s,r) => s+parseFloat(r.actual_total||0),0))"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Schedule B: Purchases -->
                        <div class="border-b border-slate-200 dark:border-slate-700">
                            <button @click="showPurchases = !showPurchases; $nextTick(() => lucide.createIcons())" class="w-full px-8 py-4 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-all print-hidden">
                                <div class="flex items-center gap-3">
                                    <span class="text-xs font-black text-slate-900 dark:text-white uppercase tracking-wider">Schedule B</span>
                                    <span class="text-xs text-slate-500">— Purchases Analysis</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] text-slate-400 tabular-nums" x-text="purchaseBreakdown.length + ' entries'"></span>
                                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 transition-transform" :class="showPurchases && 'rotate-180'"></i>
                                </div>
                            </button>
                            <div class="hidden print-show px-8 py-3 border-b border-slate-200">
                                <p class="text-xs font-black text-slate-900 uppercase tracking-wider">Schedule B — Purchases Analysis</p>
                            </div>
                            <div x-show="showPurchases" x-transition class="overflow-x-auto print-show" style="display: none;">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                                        <tr>
                                            <th class="px-6 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Date</th>
                                            <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Product</th>
                                            <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Supplier</th>
                                            <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Qty</th>
                                            <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Unit Cost (₦)</th>
                                            <th class="px-6 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Total (₦)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="p in purchaseBreakdown" :key="p.delivery_date+p.product_name+p.supplier_name">
                                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                                <td class="px-6 py-2 font-mono text-xs" x-text="p.delivery_date"></td>
                                                <td class="px-4 py-2 text-xs" x-text="p.product_name || '—'"></td>
                                                <td class="px-4 py-2 text-xs text-slate-500" x-text="p.supplier_name || '—'"></td>
                                                <td class="px-4 py-2 text-right tabular-nums text-xs" x-text="p.quantity"></td>
                                                <td class="px-4 py-2 text-right tabular-nums text-xs" x-text="fmt(p.unit_cost)"></td>
                                                <td class="px-6 py-2 text-right font-semibold tabular-nums text-xs" x-text="fmt(p.total_cost)"></td>
                                            </tr>
                                        </template>
                                        <tr x-show="purchaseBreakdown.length === 0"><td colspan="6" class="px-6 py-8 text-center text-slate-400 text-xs">No purchases this period</td></tr>
                                    </tbody>
                                    <tfoot x-show="purchaseBreakdown.length > 0" class="border-t-2 border-slate-800 dark:border-slate-300">
                                        <tr>
                                            <td class="px-6 py-2.5 font-black text-xs uppercase" colspan="3">Total</td>
                                            <td class="px-4 py-2.5 text-right font-bold tabular-nums text-xs" x-text="purchaseBreakdown.reduce((s,r) => s+parseInt(r.quantity||0),0)"></td>
                                            <td class="px-4 py-2.5"></td>
                                            <td class="px-6 py-2.5 text-right font-black tabular-nums text-xs border-b-[3px] double border-slate-800 dark:border-slate-300" x-text="fmt(purchaseBreakdown.reduce((s,r) => s+parseFloat(r.total_cost||0),0))"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Schedule C: Full Stock Valuation (by Department) -->
                        <div>
                            <button @click="showClosing = !showClosing; $nextTick(() => lucide.createIcons())" class="w-full px-8 py-4 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-all print-hidden">
                                <div class="flex items-center gap-3">
                                    <span class="text-xs font-black text-slate-900 dark:text-white uppercase tracking-wider">Schedule C</span>
                                    <span class="text-xs text-slate-500">— Closing Stock Valuation</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] text-slate-400 tabular-nums"><?php echo count($valuation_data); ?> sections</span>
                                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 transition-transform" :class="showClosing && 'rotate-180'"></i>
                                </div>
                            </button>
                            <div class="hidden print-show px-8 py-3 border-b border-slate-200">
                                <p class="text-xs font-black text-slate-900 uppercase tracking-wider">Schedule C — Closing Stock Valuation</p>
                            </div>
                            <div x-show="showClosing" x-transition class="overflow-x-auto print-show" style="display: none;">
                                <?php foreach ($valuation_data as $vd): ?>
                                <!-- Department/Store Section -->
                                <div class="px-8 py-2 bg-slate-100 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-700">
                                    <p class="text-[11px] font-black text-slate-700 dark:text-slate-300 uppercase tracking-wider"><?php echo htmlspecialchars($vd['dept_name']); ?></p>
                                </div>
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                                        <tr>
                                            <th class="px-6 py-2 text-left text-[10px] font-bold text-slate-500 uppercase w-8">#</th>
                                            <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Product</th>
                                            <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Category</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">Qty</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">Unit Cost (₦)</th>
                                            <th class="px-6 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">Value (₦)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($vd['items'] as $idx => $item): ?>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="px-6 py-1.5 text-xs text-slate-400"><?php echo $idx + 1; ?></td>
                                            <td class="px-4 py-1.5 text-xs"><?php echo htmlspecialchars($item['product_name']); ?></td>
                                            <td class="px-4 py-1.5 text-xs text-slate-500"><?php echo htmlspecialchars($item['category'] ?: '—'); ?></td>
                                            <td class="px-4 py-1.5 text-right tabular-nums text-xs"><?php echo number_format($item['closing']); ?></td>
                                            <td class="px-4 py-1.5 text-right tabular-nums text-xs"><?php echo number_format($item['unit_cost'], 2); ?></td>
                                            <td class="px-6 py-1.5 text-right font-semibold tabular-nums text-xs"><?php echo number_format($item['cost_value'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="border-t border-slate-300 dark:border-slate-600">
                                        <tr>
                                            <td class="px-6 py-2 font-bold text-xs uppercase" colspan="5"><?php echo htmlspecialchars($vd['dept_name']); ?> Subtotal</td>
                                            <td class="px-6 py-2 text-right font-bold tabular-nums text-xs"><?php echo number_format($vd['total_cost'], 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <?php endforeach; ?>
                                <?php if (empty($valuation_data)): ?>
                                <div class="px-6 py-8 text-center text-slate-400 text-xs">No stock data available</div>
                                <?php endif; ?>
                                <!-- Grand Total -->
                                <div class="px-8 py-3 border-t-2 border-slate-800 dark:border-slate-300">
                                    <div class="flex justify-between items-center">
                                        <span class="font-black text-xs uppercase">Grand Total Closing Stock</span>
                                        <span class="font-black tabular-nums text-xs border-b-[3px] double border-slate-800 dark:border-slate-300 pb-0.5"><?php echo number_format($total_valuation, 2); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Appendix Footer -->
                        <div class="px-8 py-4 border-t border-slate-200 dark:border-slate-700">
                            <p class="text-[10px] text-slate-400">End of supporting schedules. These schedules should be read in conjunction with the Statement of Profit or Loss above.</p>
                        </div>
                    </div>

                </div>
            </div>


            <!-- ========== TAB: Stock Valuation ========== -->
            <div x-show="currentTab === 'valuation'" x-transition>
                <!-- Date Picker -->
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 flex items-center justify-center shadow-lg shadow-teal-500/30">
                            <i data-lucide="warehouse" class="w-4 h-4 text-white"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm">Stock Valuation Report</h3>
                            <p class="text-xs text-slate-500">Closing stock & cost value across all departments</p>
                        </div>
                    </div>
                    <form method="GET" class="flex items-center gap-2">
                        <input type="hidden" name="month" value="<?php echo $month; ?>">
                        <input type="hidden" name="year" value="<?php echo $year; ?>">
                        <label class="text-xs font-bold text-slate-500">As at:</label>
                        <input type="date" name="val_date" value="<?php echo $valuation_date; ?>" class="px-3 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-semibold">
                        <button type="submit" class="px-3 py-1.5 bg-gradient-to-r from-teal-500 to-cyan-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all">View</button>
                    </form>
                </div>

                <!-- Grand Total KPIs -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Departments</p>
                        <p class="text-xl font-black text-slate-800 dark:text-white" x-text="valuationData.length"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Stock Value (Cost)</p>
                        <p class="text-xl font-black text-teal-600" x-text="fmt(valuationData.reduce((s,d) => s + d.total_cost, 0))"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Items</p>
                        <p class="text-xl font-black text-blue-600" x-text="valuationData.reduce((s,d) => s + d.items.length, 0)"></p>
                    </div>
                </div>

                <!-- Valuation Table per Department -->
                <div class="space-y-4">
                    <template x-for="dept in valuationData" :key="dept.dept_name">
                        <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                            <div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between cursor-pointer"
                                 :class="{
                                     'bg-gradient-to-r from-teal-500/10 to-transparent': dept.dept_type === 'main',
                                     'bg-gradient-to-r from-amber-500/10 to-transparent': dept.dept_type === 'kitchen',
                                     'bg-gradient-to-r from-rose-500/10 to-transparent': dept.dept_type === 'restaurant',
                                     'bg-gradient-to-r from-indigo-500/10 to-transparent': !['main','kitchen','restaurant'].includes(dept.dept_type)
                                 }">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shadow-sm"
                                         :class="{
                                             'bg-teal-100 text-teal-600': dept.dept_type === 'main',
                                             'bg-amber-100 text-amber-600': dept.dept_type === 'kitchen',
                                             'bg-rose-100 text-rose-600': dept.dept_type === 'restaurant',
                                             'bg-indigo-100 text-indigo-600': !['main','kitchen','restaurant'].includes(dept.dept_type)
                                         }">
                                        <i :data-lucide="dept.dept_type === 'main' ? 'warehouse' : dept.dept_type === 'kitchen' ? 'chef-hat' : dept.dept_type === 'restaurant' ? 'utensils' : 'store'" class="w-4 h-4"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-sm text-slate-900 dark:text-white" x-text="dept.dept_name"></h4>
                                        <p class="text-[10px] text-slate-500" x-text="dept.items.length + ' products'"></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] font-bold uppercase text-slate-400">Total Value</p>
                                    <p class="text-sm font-black text-teal-600" x-text="fmt(dept.total_cost)"></p>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-bold text-slate-500">#</th>
                                            <th class="px-4 py-2 text-left text-xs font-bold text-slate-500">Product</th>
                                            <th class="px-4 py-2 text-left text-xs font-bold text-slate-500">Category</th>
                                            <th class="px-4 py-2 text-right text-xs font-bold text-slate-500">Closing Qty</th>
                                            <th class="px-4 py-2 text-right text-xs font-bold text-slate-500">Unit Cost</th>
                                            <th class="px-4 py-2 text-right text-xs font-bold text-slate-500">Selling Price</th>
                                            <th class="px-4 py-2 text-right text-xs font-bold text-teal-600">Cost Value</th>
                                            <th class="px-4 py-2 text-right text-xs font-bold text-blue-600">Sell Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(item, idx) in dept.items" :key="item.product_name">
                                            <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30">
                                                <td class="px-4 py-2 text-xs text-slate-400" x-text="idx + 1"></td>
                                                <td class="px-4 py-2 font-semibold text-slate-800 dark:text-white" x-text="item.product_name"></td>
                                                <td class="px-4 py-2 text-xs text-slate-500" x-text="item.category || '—'"></td>
                                                <td class="px-4 py-2 text-right font-bold text-slate-800 dark:text-white" x-text="item.closing"></td>
                                                <td class="px-4 py-2 text-right font-mono text-xs" x-text="fmt(item.unit_cost)"></td>
                                                <td class="px-4 py-2 text-right font-mono text-xs" x-text="fmt(item.selling_price)"></td>
                                                <td class="px-4 py-2 text-right font-bold text-teal-600" x-text="fmt(item.cost_value)"></td>
                                                <td class="px-4 py-2 text-right font-bold text-blue-600" x-text="fmt(item.sell_value)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                    <tfoot class="bg-slate-100 dark:bg-slate-800 font-bold text-xs">
                                        <tr>
                                            <td class="px-4 py-2" colspan="3">SUBTOTAL</td>
                                            <td class="px-4 py-2 text-right" x-text="dept.items.reduce((s,i) => s + i.closing, 0)"></td>
                                            <td class="px-4 py-2" colspan="2"></td>
                                            <td class="px-4 py-2 text-right text-teal-600" x-text="fmt(dept.total_cost)"></td>
                                            <td class="px-4 py-2 text-right text-blue-600" x-text="fmt(dept.items.reduce((s,i) => s + i.sell_value, 0))"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </template>
                    <div x-show="valuationData.length === 0" class="glass-card rounded-2xl border border-slate-200/60 p-12 text-center">
                        <i data-lucide="package-x" class="w-12 h-12 text-slate-300 mx-auto mb-3"></i>
                        <p class="text-slate-400 text-sm">No stock data found for this date</p>
                    </div>
                </div>

                <!-- Grand Total Footer -->
                <div x-show="valuationData.length > 0" class="mt-6 glass-card rounded-2xl border-2 border-teal-200 dark:border-teal-800 shadow-lg p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 flex items-center justify-center shadow-lg">
                                <i data-lucide="calculator" class="w-5 h-5 text-white"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase text-slate-400">Grand Total Stock Valuation</p>
                                <p class="text-xs text-slate-500">As at <?php echo $valuation_date; ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-bold uppercase text-slate-400">Cost Value</p>
                            <p class="text-2xl font-black text-teal-600" x-text="fmt(valuationData.reduce((s,d) => s + d.total_cost, 0))"></p>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- Edit Expense Modal -->
<div x-show="editingExpense" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm" @click.self="editingExpense = null">
    <div class="glass-card rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl w-full max-w-md mx-4 overflow-hidden" @click.stop>
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 to-transparent flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg">
                    <i data-lucide="pencil" class="w-4 h-4 text-white"></i>
                </div>
                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Edit Expense</h3>
            </div>
            <button @click="editingExpense = null" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-red-100 flex items-center justify-center transition-all">
                <i data-lucide="x" class="w-4 h-4 text-slate-500 hover:text-red-600"></i>
            </button>
        </div>
        <form @submit.prevent="updateExpense()" class="p-5 space-y-3">
            <div>
                <label class="text-[10px] font-bold text-slate-500 block mb-1">Category</label>
                <select x-model="editExpForm.category_id" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                    <template x-for="c in categories" :key="c.id"><option :value="c.id" x-text="c.name + ' (' + c.type.replace('_',' ') + ')'"></option></template>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] font-bold text-slate-500 block mb-1">Amount (₦)</label>
                    <input type="number" step="0.01" x-model="editExpForm.amount" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-slate-500 block mb-1">Date</label>
                    <input type="date" x-model="editExpForm.entry_date" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                </div>
            </div>
            <div>
                <label class="text-[10px] font-bold text-slate-500 block mb-1">Description</label>
                <input type="text" x-model="editExpForm.description" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] font-bold text-slate-500 block mb-1">Payment</label>
                    <select x-model="editExpForm.payment_method" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                        <option value="cash">Cash</option><option value="transfer">Transfer</option><option value="pos">POS</option><option value="cheque">Cheque</option>
                    </select>
                </div>
                <div>
                    <label class="text-[10px] font-bold text-slate-500 block mb-1">Vendor</label>
                    <input type="text" x-model="editExpForm.vendor" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                </div>
            </div>
            <div class="flex gap-2 pt-1">
                <button type="button" @click="editingExpense = null" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-sm transition-all">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl shadow-lg text-sm hover:scale-[1.02] transition-all">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
/* Save manual opening stock for first-time clients */
function saveManualOpening() {
    const val = parseFloat(document.getElementById('manual-opening-val').value) || 0;
    if (val < 0) { alert('Opening stock cannot be negative.'); return; }
    const fd = new FormData();
    fd.append('action', 'save_opening_stock');
    fd.append('opening_value', val);
    fd.append('period_start', '<?php echo $period_start; ?>');
    fetch('../ajax/finance_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                // Reload to recalculate P&L with saved opening
                window.location.reload();
            } else {
                alert(d.message || 'Error saving opening stock');
            }
        })
        .catch(() => alert('Failed to save opening stock'));
}

function financeApp() {
    return {
        currentTab: (location.hash.slice(1) || 'revenue'),
        tabs: [
            { id: 'revenue', label: 'Revenue', icon: 'trending-up' },
            { id: 'expenses', label: 'Expenses', icon: 'receipt' },
            { id: 'cost_centers', label: 'Cost Centers', icon: 'pie-chart' },
            { id: 'pnl', label: 'P&L Statement', icon: 'file-bar-chart' },
            { id: 'valuation', label: 'Stock Valuation', icon: 'warehouse' },
        ],
        categories: <?php echo $js_categories; ?>,
        expenses: <?php echo $js_expenses; ?>,
        valuationData: <?php echo $js_valuation; ?>,
        valuationDate: '<?php echo $valuation_date; ?>',
        expenseForm: { category_id:'', amount:0, description:'', entry_date: new Date().toISOString().split('T')[0], payment_method:'cash', vendor:'', receipt_number:'' },
        showCatForm: false,
        catForm: { name: '', type: 'operating' },
        cosDetail: <?php echo $js_cos_detail; ?>,
        dailySales: <?php echo $js_daily_sales; ?>,
        purchaseBreakdown: <?php echo $js_purchase_details; ?>,
        closingStock: <?php echo $js_closing_stock; ?>,
        editingExpense: null,
        editExpForm: { id: 0, category_id: '', amount: 0, description: '', entry_date: '', payment_method: 'cash', vendor: '' },
        expFilter: { category: '', payment: '', vendor: '', dateFrom: '', dateTo: '' },
        get filteredExpenses() {
            return this.expenses.filter(e => {
                if (this.expFilter.category && String(e.category_id) !== String(this.expFilter.category)) return false;
                if (this.expFilter.payment && e.payment_method !== this.expFilter.payment) return false;
                if (this.expFilter.vendor && !(e.vendor || '').toLowerCase().includes(this.expFilter.vendor.toLowerCase())) return false;
                if (this.expFilter.dateFrom && e.entry_date < this.expFilter.dateFrom) return false;
                if (this.expFilter.dateTo && e.entry_date > this.expFilter.dateTo) return false;
                return true;
            });
        },
        get filteredTotal() {
            return this.filteredExpenses.reduce((s, e) => s + parseFloat(e.amount || 0), 0);
        },
        fmt(v) { return '₦' + parseFloat(v||0).toLocaleString('en-NG',{minimumFractionDigits:2}); },
        init() {
            this.$watch('currentTab', (val) => { location.hash = val; setTimeout(() => lucide.createIcons(), 50); });
            this.$nextTick(() => this.initChart());
            window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (h && this.tabs.some(t => t.id === h)) this.currentTab = h; });
        },
        initChart() {
            const ctx = document.getElementById('revChart');
            if (!ctx) return;
            const data = <?php echo $js_daily_rev; ?>;
            new Chart(ctx, {
                type: 'bar', data: {
                    labels: data.map(d => d.transaction_date.substring(5)),
                    datasets: [
                        { label: 'POS', data: data.map(d => parseFloat(d.pos)), backgroundColor: 'rgba(59,130,246,0.6)', borderRadius: 4 },
                        { label: 'Cash', data: data.map(d => parseFloat(d.cash)), backgroundColor: 'rgba(16,185,129,0.6)', borderRadius: 4 },
                        { label: 'Transfer', data: data.map(d => parseFloat(d.transfer)), backgroundColor: 'rgba(139,92,246,0.6)', borderRadius: 4 },
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 10 } } } }, scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, grid: { color: 'rgba(148,163,184,0.1)' } } } }
            });
        },
        async saveCategory() {
            if (!this.catForm.name.trim()) { alert('Please enter a category name'); return; }
            const fd = new FormData();
            fd.append('action', 'add_category');
            fd.append('name', this.catForm.name.trim());
            fd.append('type', this.catForm.type);
            const r = await (await fetch('../ajax/finance_api.php', { method: 'POST', body: fd })).json();
            if (r.success) {
                this.categories.push({ id: r.id, name: this.catForm.name.trim(), type: this.catForm.type });
                this.expenseForm.category_id = r.id;
                this.catForm = { name: '', type: 'operating' };
                this.showCatForm = false;
                this.$nextTick(() => lucide.createIcons());
            } else { alert(r.message); }
        },
        async saveExpense() {
            const fd = new FormData(); fd.append('action','save_expense');
            Object.entries(this.expenseForm).forEach(([k,v]) => fd.append(k,v));
            const r = await (await fetch('../ajax/finance_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        editExpense(e) {
            this.editingExpense = e;
            this.editExpForm = {
                id: e.id,
                category_id: e.category_id || '',
                amount: parseFloat(e.amount || 0),
                description: e.description || '',
                entry_date: e.entry_date || '',
                payment_method: e.payment_method || 'cash',
                vendor: e.vendor || ''
            };
            this.$nextTick(() => lucide.createIcons());
        },
        async updateExpense() {
            const fd = new FormData();
            fd.append('action', 'update_expense');
            Object.entries(this.editExpForm).forEach(([k,v]) => fd.append(k,v));
            const r = await (await fetch('../ajax/finance_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        async deleteExpense(id) {
            if (!confirm('Delete this expense entry? This cannot be undone.')) return;
            const fd = new FormData();
            fd.append('action', 'delete_expense');
            fd.append('id', id);
            const r = await (await fetch('../ajax/finance_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
    }
}
</script>
<?php include '../includes/dashboard_scripts.php'; ?>
</body></html>
