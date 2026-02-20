<?php
/**
 * MIAUDITOPS — Executive Management Dashboard
 * CEO/Business Owner high-level KPI view
 */
require_once '../includes/functions.php';
require_login();
require_active_client();
// Dashboard is accessible to ALL logged-in users — no permission guard here

$company_id   = $_SESSION['company_id'];
$client_id    = get_active_client();
$client_name  = $_SESSION['active_client_name'] ?? 'Client';
$page_title   = 'Dashboard';

// ========== SUBSCRIPTION DATA ==========
require_once '../config/subscription_plans.php';
$plan_key = 'starter';
$plan_cfg = get_plan_config('starter');
$sub_status = 'active';
$sub_expires = null;
$current_users = $current_clients = $current_outlets = $current_products = 0;
$max_users = $max_clients = $max_outlets = $max_products = 0;
try {
    $sub = get_company_subscription($company_id);
    $plan_key = $sub['plan_name'] ?? 'starter';
    $plan_cfg = get_plan_config($plan_key);
    $sub_status = $sub['status'] ?? 'active';
    $sub_expires = $sub['expires_at'] ?? null;

    // Current usage counts
    $user_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND deleted_at IS NULL");
    $user_count_stmt->execute([$company_id]);
    $current_users = (int)$user_count_stmt->fetchColumn();

    $client_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE company_id = ? AND deleted_at IS NULL");
    $client_count_stmt->execute([$company_id]);
    $current_clients = (int)$client_count_stmt->fetchColumn();

    $outlet_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM client_outlets WHERE company_id = ? AND deleted_at IS NULL");
    $outlet_count_stmt->execute([$company_id]);
    $current_outlets = (int)$outlet_count_stmt->fetchColumn();

    $product_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL");
    $product_count_stmt->execute([$company_id, $client_id]);
    $current_products = (int)$product_count_stmt->fetchColumn();

    // Limit values
    $max_users    = (int)($sub['max_users'] ?? $plan_cfg['max_users']);
    $max_clients  = (int)($sub['max_clients'] ?? $plan_cfg['max_clients']);
    $max_outlets  = (int)($sub['max_outlets'] ?? $plan_cfg['max_outlets']);
    $max_products = (int)($sub['max_products'] ?? $plan_cfg['max_products']);
} catch (Exception $e) {
    // fail silently — defaults already set above
}

// ========== FETCH DASHBOARD METRICS ==========

// Today's Revenue
$stmt = $pdo->prepare("SELECT COALESCE(SUM(actual_total), 0) as total FROM sales_transactions WHERE company_id = ? AND client_id = ? AND transaction_date = CURDATE() AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$today_revenue = $stmt->fetch()['total'];

// Today's Expenses
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expense_entries WHERE company_id = ? AND client_id = ? AND entry_date = CURDATE() AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$today_expenses = $stmt->fetch()['total'];

// Gross Profit Today
$today_profit = $today_revenue - $today_expenses;

// Monthly Revenue
$stmt = $pdo->prepare("SELECT COALESCE(SUM(actual_total), 0) as total FROM sales_transactions WHERE company_id = ? AND client_id = ? AND MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE()) AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$monthly_revenue = $stmt->fetch()['total'];

// Monthly Expenses
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expense_entries WHERE company_id = ? AND client_id = ? AND MONTH(entry_date) = MONTH(CURDATE()) AND YEAR(entry_date) = YEAR(CURDATE()) AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$monthly_expenses = $stmt->fetch()['total'];

// Monthly Net Profit
$monthly_profit = $monthly_revenue - $monthly_expenses;

// Total Stock Value
$stmt = $pdo->prepare("SELECT COALESCE(SUM(current_stock * unit_cost), 0) as total FROM products WHERE company_id = ? AND client_id = ? AND is_active = 1 AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$stock_value = $stmt->fetch()['total'];

// Pending Requisitions
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM requisitions WHERE company_id = ? AND client_id = ? AND status NOT IN ('closed','rejected','delivered') AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$pending_requisitions = $stmt->fetch()['count'];

// Open Variances
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM variance_reports WHERE company_id = ? AND client_id = ? AND status = 'open'");
$stmt->execute([$company_id, $client_id]);
$open_variances = $stmt->fetch()['count'];

// Recent Audit Status
$stmt = $pdo->prepare("SELECT status FROM daily_audit_signoffs WHERE company_id = ? AND client_id = ? AND audit_date = CURDATE() LIMIT 1");
$stmt->execute([$company_id, $client_id]);
$audit_row = $stmt->fetch();
$audit_status = $audit_row ? $audit_row['status'] : 'not_started';

// Low Stock Alerts
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE company_id = ? AND client_id = ? AND current_stock <= reorder_level AND is_active = 1 AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$low_stock_count = $stmt->fetch()['count'];

// Recent Sales (Last 7 days chart data)
$stmt = $pdo->prepare("SELECT transaction_date, COALESCE(SUM(actual_total), 0) as daily_total FROM sales_transactions WHERE company_id = ? AND client_id = ? AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND deleted_at IS NULL GROUP BY transaction_date ORDER BY transaction_date");
$stmt->execute([$company_id, $client_id]);
$recent_sales = $stmt->fetchAll();

$chart_labels = [];
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('D', strtotime($date));
    $found = false;
    foreach ($recent_sales as $row) {
        if ($row['transaction_date'] === $date) {
            $chart_data[] = (float)$row['daily_total'];
            $found = true;
            break;
        }
    }
    if (!$found) $chart_data[] = 0;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — MIAUDITOPS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] }, colors: { brand: { 50:'#f5f3ff',100:'#ede9fe',200:'#ddd6fe',300:'#c4b5fd',400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed',700:'#6d28d9',800:'#5b21b6',900:'#4c1d95',950:'#2e1065' } } } }
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        .glass-card { background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(249,250,251,0.9) 100%); backdrop-filter: blur(20px); }
        .dark .glass-card { background: linear-gradient(135deg, rgba(15,23,42,0.95) 0%, rgba(30,41,59,0.9) 100%); }
        @keyframes counter { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .counter-anim { animation: counter 0.6s ease-out forwards; }
    </style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data>

<div class="flex h-screen w-full">
    
    <!-- Sidebar -->
    <?php include '../includes/dashboard_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        
        <?php include '../includes/dashboard_header.php'; ?>

        <main class="flex-1 overflow-y-auto p-6 lg:p-8 scroll-smooth">
            
            <?php display_flash_message(); ?>

            <!-- Welcome Section -->
            <div class="mb-8">
                <h2 class="text-2xl font-black text-slate-900 dark:text-white">Good <?php echo date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening'); ?>, <?php echo htmlspecialchars($_SESSION['user_name']); ?> 👋</h2>
                <p class="text-slate-500 dark:text-slate-400 mt-1">Here's your operations overview for <span class="font-semibold text-slate-700 dark:text-slate-300"><?php echo date('l, F j, Y'); ?></span></p>
            </div>

            <!-- Subscription Plan Widget -->
            <?php
            // Use inline styles to avoid Tailwind CDN missing dynamic class names
            $plan_styles = [
                'starter'      => ['header_bg' => 'linear-gradient(to right, #475569, #1e293b)', 'bar_bg' => '#64748b', 'icon_color' => '#94a3b8', 'glow' => '0 20px 40px rgba(100,116,139,0.15)'],
                'professional' => ['header_bg' => 'linear-gradient(to right, #7c3aed, #3730a3)', 'bar_bg' => '#8b5cf6', 'icon_color' => '#a78bfa', 'glow' => '0 20px 40px rgba(139,92,246,0.20)'],
                'enterprise'   => ['header_bg' => 'linear-gradient(to right, #f59e0b, #c2410c)', 'bar_bg' => '#f59e0b', 'icon_color' => '#fbbf24', 'glow' => '0 20px 40px rgba(245,158,11,0.20)'],
            ];
            $pc = $plan_styles[$plan_key] ?? $plan_styles['starter'];
            $plan_icons = ['starter' => 'rocket', 'professional' => 'zap', 'enterprise' => 'crown'];
            $plan_icon = $plan_icons[$plan_key] ?? 'rocket';
            $days_left = $sub_expires ? max(0, (int)((strtotime($sub_expires) - time()) / 86400)) : null;
            $is_unlimited = ($plan_key === 'enterprise');
            ?>
            <div class="mb-6 rounded-2xl overflow-hidden border border-slate-200 dark:border-white/10"
                 style="box-shadow: <?php echo $pc['glow']; ?>;">
                <!-- Gradient Header -->
                <div class="p-5 relative overflow-hidden"
                     style="background: <?php echo $pc['header_bg']; ?>;">
                    <div class="absolute -top-10 -right-10 w-40 h-40 rounded-full" style="background:rgba(255,255,255,0.05);filter:blur(32px);"></div>
                    <div class="absolute bottom-0 left-0 w-32 h-32 rounded-full" style="background:rgba(255,255,255,0.05);filter:blur(40px);"></div>
                    <div class="relative flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow-lg"
                                 style="background:rgba(255,255,255,0.2);backdrop-filter:blur(8px);">
                                <i data-lucide="<?php echo $plan_icon; ?>" class="w-6 h-6" style="color:#fff;"></i>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="text-lg font-black text-white tracking-tight"><?php echo ucfirst($plan_key); ?> Plan</h3>
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider"
                                          style="<?php echo $sub_status === 'active'
                                              ? 'background:rgba(52,211,153,0.25);color:#a7f3d0;'
                                              : ($sub_status === 'trial'
                                                  ? 'background:rgba(251,191,36,0.25);color:#fde68a;'
                                                  : 'background:rgba(248,113,113,0.25);color:#fca5a5;'); ?>">
                                        <?php echo ucfirst($sub_status); ?>
                                    </span>
                                </div>
                                <p class="text-xs mt-0.5" style="color:rgba(255,255,255,0.65);">
                                    <?php if ($days_left !== null && $sub_status !== 'expired'): ?>
                                        <?php echo $days_left; ?> days remaining &middot; Expires <?php echo date('M j, Y', strtotime($sub_expires)); ?>
                                    <?php elseif ($sub_status === 'expired'): ?>
                                        Subscription expired
                                    <?php elseif ($plan_key === 'starter'): ?>
                                        Free forever &middot; No expiry
                                    <?php else: ?>
                                        Active subscription
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <?php if ($plan_key !== 'enterprise'): ?>
                        <a href="#" class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-white text-xs font-bold rounded-xl transition-all shadow-lg"
                           style="background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.15);"
                           onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                            <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Upgrade Plan
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Usage Stats -->
                <div class="bg-white dark:bg-slate-900 p-5">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <?php
                        $ui_colors = [
                            'users'    => ['bar' => '#3b82f6', 'icon' => '#3b82f6'],
                            'clients'  => ['bar' => '#6366f1', 'icon' => '#6366f1'],
                            'outlets'  => ['bar' => '#10b981', 'icon' => '#10b981'],
                            'products' => ['bar' => '#f59e0b', 'icon' => '#f59e0b'],
                        ];
                        $ui_icons = ['users' => 'users', 'clients' => 'building', 'outlets' => 'map-pin', 'products' => 'package'];
                        $usage_items = [
                            ['label' => 'Users',    'current' => $current_users,    'max' => $max_users,    'key' => 'users'],
                            ['label' => 'Clients',  'current' => $current_clients,  'max' => $max_clients,  'key' => 'clients'],
                            ['label' => 'Outlets',  'current' => $current_outlets,  'max' => $max_outlets,  'key' => 'outlets'],
                            ['label' => 'Products', 'current' => $current_products, 'max' => $max_products, 'key' => 'products'],
                        ];
                        foreach ($usage_items as $ui):
                            $uc   = $ui_colors[$ui['key']];
                            $ico  = $ui_icons[$ui['key']];
                            $unlimited = $is_unlimited || $ui['max'] <= 0;
                            $pct  = $unlimited ? 100 : min(100, round(($ui['current'] / max(1, $ui['max'])) * 100));
                            $near_limit = !$unlimited && $pct >= 80;
                            $bar_color  = $near_limit ? '#ef4444' : $uc['bar'];
                        ?>

                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <div class="flex items-center gap-1.5">
                                    <i data-lucide="<?php echo $ico; ?>" class="w-3.5 h-3.5" style="color:<?php echo $uc['icon']; ?>;"></i>
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?php echo $ui['label']; ?></span>
                                </div>
                                <span class="text-xs font-bold" style="color:<?php echo $near_limit ? '#ef4444' : 'inherit'; ?>;">
                                    <?php echo $ui['current']; ?> / <?php echo $unlimited ? '&infin;' : $ui['max']; ?>
                                </span>
                            </div>
                            <div class="w-full rounded-full h-1.5 overflow-hidden" style="background:#e2e8f0;">
                                <div class="h-full rounded-full transition-all duration-500"
                                     style="width:<?php echo $unlimited ? '100' : $pct; ?>%;background:<?php echo $bar_color; ?>;opacity:<?php echo $unlimited ? '0.35' : '1'; ?>;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($plan_key !== 'enterprise'): ?>
                    <div class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-4 text-[11px] text-slate-400">
                            <span class="flex items-center gap-1">
                                <i data-lucide="database" class="w-3 h-3"></i>
                                Data Retention: <strong class="text-slate-600 dark:text-slate-300"><?php echo $plan_cfg['data_retention_days'] > 0 ? $plan_cfg['data_retention_days'] . ' days' : 'Unlimited'; ?></strong>
                            </span>
                            <span class="flex items-center gap-1">
                                <i data-lucide="file-down" class="w-3 h-3"></i>
                                PDF Export: <strong style="color:<?php echo $plan_cfg['pdf_export'] ? '#10b981' : '#94a3b8'; ?>;"><?php echo $plan_cfg['pdf_export'] ? 'Enabled' : 'Locked'; ?></strong>
                            </span>
                        </div>
                        <a href="#" class="sm:hidden inline-flex items-center gap-1.5 px-3 py-1.5 text-white text-[10px] font-bold rounded-lg transition-all shadow-md"
                           style="background:linear-gradient(to right,#8b5cf6,#4f46e5);">
                            <i data-lucide="sparkles" class="w-3 h-3"></i> Upgrade
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KPI Strip — Top 4 -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                
                <!-- Today's Revenue -->
                <div class="group glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg hover:shadow-xl hover:scale-[1.02] transition-all duration-300 relative overflow-hidden">
                    <div class="absolute -top-6 -right-6 w-24 h-24 bg-emerald-400/10 rounded-full blur-2xl group-hover:bg-emerald-400/20 transition-all"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Today's Revenue</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center shadow-lg shadow-emerald-500/30 group-hover:scale-110 transition-transform">
                            <i data-lucide="trending-up" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-black text-slate-900 dark:text-white counter-anim"><?php echo format_currency($today_revenue); ?></p>
                    <p class="text-xs text-slate-400 mt-1">From all sales channels</p>
                </div>

                <!-- Today's Expenses -->
                <div class="group glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg hover:shadow-xl hover:scale-[1.02] transition-all duration-300 relative overflow-hidden">
                    <div class="absolute -top-6 -right-6 w-24 h-24 bg-red-400/10 rounded-full blur-2xl group-hover:bg-red-400/20 transition-all"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Today's Expenses</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center shadow-lg shadow-red-500/30 group-hover:scale-110 transition-transform">
                            <i data-lucide="trending-down" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-black text-slate-900 dark:text-white counter-anim"><?php echo format_currency($today_expenses); ?></p>
                    <p class="text-xs text-slate-400 mt-1">Operational costs</p>
                </div>

                <!-- Gross Profit -->
                <div class="group glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg hover:shadow-xl hover:scale-[1.02] transition-all duration-300 relative overflow-hidden">
                    <div class="absolute -top-6 -right-6 w-24 h-24 bg-violet-400/10 rounded-full blur-2xl group-hover:bg-violet-400/20 transition-all"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Gross Profit</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/30 group-hover:scale-110 transition-transform">
                            <i data-lucide="wallet" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-black <?php echo $today_profit >= 0 ? 'text-emerald-600' : 'text-red-600'; ?> counter-anim"><?php echo format_currency($today_profit); ?></p>
                    <p class="text-xs text-slate-400 mt-1">Revenue minus expenses</p>
                </div>

                <!-- Stock Value -->
                <div class="group glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg hover:shadow-xl hover:scale-[1.02] transition-all duration-300 relative overflow-hidden">
                    <div class="absolute -top-6 -right-6 w-24 h-24 bg-amber-400/10 rounded-full blur-2xl group-hover:bg-amber-400/20 transition-all"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Stock Value</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30 group-hover:scale-110 transition-transform">
                            <i data-lucide="package" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-black text-slate-900 dark:text-white counter-anim"><?php echo format_currency($stock_value); ?></p>
                    <p class="text-xs text-slate-400 mt-1">Total inventory value</p>
                </div>
            </div>

            <!-- Secondary KPI Strip -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                
                <!-- Monthly Net Profit -->
                <div class="group glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg hover:shadow-xl hover:scale-[1.02] transition-all duration-300 relative overflow-hidden">
                    <div class="absolute -bottom-6 -left-6 w-20 h-20 bg-blue-400/10 rounded-full blur-2xl"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Monthly Profit</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/30 group-hover:scale-110 transition-transform">
                            <i data-lucide="bar-chart-3" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-black <?php echo $monthly_profit >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>"><?php echo format_currency($monthly_profit); ?></p>
                    <p class="text-xs text-slate-400 mt-1"><?php echo date('F Y'); ?></p>
                </div>

                <!-- Pending Requisitions -->
                <div class="group glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg hover:shadow-xl hover:scale-[1.02] transition-all duration-300 relative overflow-hidden">
                    <div class="absolute -bottom-6 -left-6 w-20 h-20 bg-rose-400/10 rounded-full blur-2xl"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Pending Requests</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-rose-500 to-pink-600 flex items-center justify-center shadow-lg shadow-rose-500/30 group-hover:scale-110 transition-transform">
                            <i data-lucide="file-text" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $pending_requisitions; ?></p>
                    <p class="text-xs text-slate-400 mt-1">Awaiting approval</p>
                </div>

                <!-- Open Variances -->
                <div class="group glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg hover:shadow-xl hover:scale-[1.02] transition-all duration-300 relative overflow-hidden">
                    <div class="absolute -bottom-6 -left-6 w-20 h-20 bg-amber-400/10 rounded-full blur-2xl"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Variances</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-yellow-600 flex items-center justify-center shadow-lg shadow-amber-500/30 group-hover:scale-110 transition-transform">
                            <i data-lucide="alert-triangle" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-black <?php echo $open_variances > 0 ? 'text-amber-600' : 'text-emerald-600'; ?>"><?php echo $open_variances; ?></p>
                    <p class="text-xs text-slate-400 mt-1">Open investigations</p>
                </div>

                <!-- Low Stock Alerts -->
                <div class="group glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg hover:shadow-xl hover:scale-[1.02] transition-all duration-300 relative overflow-hidden">
                    <div class="absolute -bottom-6 -left-6 w-20 h-20 bg-cyan-400/10 rounded-full blur-2xl"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Low Stock</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-500 to-teal-600 flex items-center justify-center shadow-lg shadow-cyan-500/30 group-hover:scale-110 transition-transform">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-black <?php echo $low_stock_count > 0 ? 'text-red-600' : 'text-emerald-600'; ?>"><?php echo $low_stock_count; ?></p>
                    <p class="text-xs text-slate-400 mt-1">Products below reorder</p>
                </div>
            </div>

            <!-- Charts & Activity Row -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                
                <!-- Revenue Chart (Span 2) -->
                <div class="lg:col-span-2 glass-card rounded-2xl p-6 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Revenue Trend</h3>
                            <p class="text-sm text-slate-400">Last 7 days performance</p>
                        </div>
                        <div class="px-3 py-1 rounded-full bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 text-xs font-bold">
                            <?php echo format_currency($monthly_revenue); ?> this month
                        </div>
                    </div>
                    <div class="relative h-48">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="glass-card rounded-2xl p-6 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Quick Actions</h3>
                    <div class="space-y-3">
                        <a href="audit.php" class="flex items-center gap-3 p-3 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/30 text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition-all group">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center shadow-md">
                                <i data-lucide="clipboard-check" class="w-4 h-4 text-white"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-bold">Daily Audit</p>
                                <p class="text-xs opacity-70">Record today's sales</p>
                            </div>
                            <i data-lucide="chevron-right" class="w-4 h-4 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                        </a>

                        <a href="stock.php" class="flex items-center gap-3 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800/30 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition-all group">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-md">
                                <i data-lucide="package" class="w-4 h-4 text-white"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-bold">Stock Check</p>
                                <p class="text-xs opacity-70">Update inventory</p>
                            </div>
                            <i data-lucide="chevron-right" class="w-4 h-4 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                        </a>

                        <a href="finance.php" class="flex items-center gap-3 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/30 text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/40 transition-all group">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-md">
                                <i data-lucide="trending-up" class="w-4 h-4 text-white"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-bold">Record Expense</p>
                                <p class="text-xs opacity-70">Track spending</p>
                            </div>
                            <i data-lucide="chevron-right" class="w-4 h-4 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                        </a>

                        <a href="requisitions.php" class="flex items-center gap-3 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 border border-rose-100 dark:border-rose-800/30 text-rose-700 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/40 transition-all group">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-rose-500 to-pink-600 flex items-center justify-center shadow-md">
                                <i data-lucide="file-text" class="w-4 h-4 text-white"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-bold">New Requisition</p>
                                <p class="text-xs opacity-70">Submit request</p>
                            </div>
                            <i data-lucide="chevron-right" class="w-4 h-4 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Audit Status & Recent Activity -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <!-- Today's Audit Status -->
                <div class="glass-card rounded-2xl p-6 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Today's Audit Status</h3>
                    <div class="flex items-center gap-4">
                        <?php
                        $status_config = [
                            'not_started' => ['label' => 'Not Started', 'color' => 'slate', 'icon' => 'clock'],
                            'pending_auditor' => ['label' => 'Awaiting Auditor', 'color' => 'amber', 'icon' => 'user-check'],
                            'pending_manager' => ['label' => 'Awaiting Manager', 'color' => 'blue', 'icon' => 'user-check'],
                            'completed' => ['label' => 'Completed', 'color' => 'emerald', 'icon' => 'check-circle'],
                            'rejected' => ['label' => 'Rejected', 'color' => 'red', 'icon' => 'x-circle'],
                        ];
                        $cfg = $status_config[$audit_status];
                        ?>
                        <div class="w-14 h-14 rounded-2xl bg-<?php echo $cfg['color']; ?>-100 dark:bg-<?php echo $cfg['color']; ?>-900/30 flex items-center justify-center">
                            <i data-lucide="<?php echo $cfg['icon']; ?>" class="w-7 h-7 text-<?php echo $cfg['color']; ?>-600"></i>
                        </div>
                        <div>
                            <p class="text-lg font-bold text-slate-900 dark:text-white"><?php echo $cfg['label']; ?></p>
                            <p class="text-sm text-slate-400"><?php echo date('l, F j, Y'); ?></p>
                        </div>
                    </div>
                    <?php if ($audit_status === 'not_started'): ?>
                        <a href="audit.php" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-blue-500 to-indigo-600 text-white text-sm font-bold rounded-xl shadow-lg shadow-blue-500/30 hover:shadow-blue-500/50 hover:scale-105 transition-all">
                            <i data-lucide="play" class="w-4 h-4"></i> Start Audit
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Monthly Summary -->
                <div class="glass-card rounded-2xl p-6 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Monthly Summary — <?php echo date('F Y'); ?></h3>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between py-2 border-b border-slate-100 dark:border-slate-800">
                            <span class="text-sm text-slate-500">Total Revenue</span>
                            <span class="text-sm font-bold text-emerald-600"><?php echo format_currency($monthly_revenue); ?></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-slate-100 dark:border-slate-800">
                            <span class="text-sm text-slate-500">Total Expenses</span>
                            <span class="text-sm font-bold text-red-600"><?php echo format_currency($monthly_expenses); ?></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-slate-100 dark:border-slate-800">
                            <span class="text-sm text-slate-500">Net Profit</span>
                            <span class="text-sm font-bold <?php echo $monthly_profit >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>"><?php echo format_currency($monthly_profit); ?></span>
                        </div>
                        <div class="flex items-center justify-between py-2">
                            <span class="text-sm text-slate-500">Profit Margin</span>
                            <span class="text-sm font-bold text-violet-600"><?php echo $monthly_revenue > 0 ? number_format(($monthly_profit / $monthly_revenue) * 100, 1) : '0.0'; ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const ctx = document.getElementById('revenueChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode($chart_data); ?>,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointBackgroundColor: '#8b5cf6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,0.1)' }, ticks: { font: { size: 11 } } },
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } }
                }
            }
        });
    }
</script>

<?php include '../includes/dashboard_scripts.php'; ?>
</body>
</html>
