<?php
/**
 * MIAUDITOPS — Cash Management Module
 * 5 Tabs: Cash Sales, Cash Ledger, Cash Requisition, Cash Analysis, Cash Report
 */
require_once '../includes/functions.php';
require_login();
require_subscription('cash');
require_permission('cash');
require_active_client();
$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$user_role  = get_user_role();
$company    = get_company($company_id);
$company_name = $company['name'] ?? 'Company';
$client_name  = $_SESSION['active_client_name'] ?? 'Client';
$page_title = 'Cash Management';
$user_perms = get_user_permissions($user_id);
$is_approver = in_array($user_role, ['business_owner','super_admin','auditor']) || !empty(array_filter($user_perms, fn($p) => str_starts_with($p, 'cash.')));

// Departments
$stmt = $pdo->prepare("SELECT sd.id, sd.name FROM stock_departments sd WHERE sd.company_id = ? AND sd.client_id = ? AND sd.deleted_at IS NULL ORDER BY sd.name");
$stmt->execute([$company_id, $client_id]);
$departments = $stmt->fetchAll();

// Expense categories
try {
    $stmt = $pdo->prepare("SELECT * FROM cash_expense_categories WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL ORDER BY name");
    $stmt->execute([$company_id, $client_id]);
    $categories = $stmt->fetchAll();
} catch (Exception $e) { $categories = []; }

// Cash sales
try {
    $stmt = $pdo->prepare("SELECT cs.*, u.first_name as posted_first, u.last_name as posted_last, cu.first_name as confirmed_first, cu.last_name as confirmed_last FROM cash_sales cs LEFT JOIN users u ON cs.posted_by = u.id LEFT JOIN users cu ON cs.confirmed_by = cu.id WHERE cs.company_id = ? AND cs.client_id = ? AND cs.deleted_at IS NULL ORDER BY cs.sale_date DESC, cs.id DESC LIMIT 200");
    $stmt->execute([$company_id, $client_id]);
    $sales = $stmt->fetchAll();
} catch (Exception $e) { $sales = []; }

// Cash requisitions
try {
    $stmt = $pdo->prepare("SELECT cr.*, c.name as category_name, u.first_name as req_first, u.last_name as req_last, au.first_name as appr_first, au.last_name as appr_last FROM cash_requisitions cr LEFT JOIN cash_expense_categories c ON cr.category_id = c.id LEFT JOIN users u ON cr.requested_by = u.id LEFT JOIN users au ON cr.approved_by = au.id WHERE cr.company_id = ? AND cr.client_id = ? AND cr.deleted_at IS NULL ORDER BY cr.created_at DESC LIMIT 200");
    $stmt->execute([$company_id, $client_id]);
    $requisitions = $stmt->fetchAll();
} catch (Exception $e) { $requisitions = []; }

// KPIs
$total_in = array_sum(array_map(fn($s) => $s['status'] === 'confirmed' ? floatval($s['amount']) : 0, $sales));
$total_out = array_sum(array_map(fn($r) => $r['status'] === 'approved' ? floatval($r['amount']) : 0, $requisitions));
$pending_sales = count(array_filter($sales, fn($s) => $s['status'] === 'pending'));
$pending_reqs  = count(array_filter($requisitions, fn($r) => $r['status'] === 'pending'));

$js_sales  = json_encode($sales, JSON_HEX_TAG|JSON_HEX_APOS);
$js_reqs   = json_encode($requisitions, JSON_HEX_TAG|JSON_HEX_APOS);
$js_cats   = json_encode($categories, JSON_HEX_TAG|JSON_HEX_APOS);
$js_depts  = json_encode($departments, JSON_HEX_TAG|JSON_HEX_APOS);

// Build allowed tabs based on sub-permissions
$cash_tab_map = [
    'cash.sales'       => 'sales',
    'cash.ledger'      => 'ledger',
    'cash.requisition' => 'requisition',
    'cash.analysis'    => 'analysis',
    'cash.report'      => 'report',
];
$cash_sub_perms = array_filter($user_perms, fn($p) => str_starts_with($p, 'cash.'));
// If admin or no sub-permissions set, allow all tabs
if (is_admin_role() || empty($cash_sub_perms)) {
    $cash_allowed_tabs = ['sales','ledger','requisition','analysis','report'];
} else {
    $cash_allowed_tabs = array_values(array_unique(array_filter(array_map(fn($p) => $cash_tab_map[$p] ?? null, $cash_sub_perms))));
}
$js_cash_allowed = json_encode($cash_allowed_tabs);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Management — MIAUDITOPS</title>
    <?php include '../includes/pwa_head.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']},colors:{brand:{50:'#f5f3ff',100:'#ede9fe',200:'#ddd6fe',300:'#c4b5fd',400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed',700:'#6d28d9',800:'#5b21b6',900:'#4c1d95',950:'#2e1065'}}}}}</script>
    <style>
        [x-cloak]{display:none!important}
        .glass-card{background:linear-gradient(135deg,rgba(255,255,255,.95),rgba(249,250,251,.9));backdrop-filter:blur(20px)}
        .dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,.95),rgba(30,41,59,.9))}
        .print-only { display: none; }
        @media print {
            body, html { background: white !important; color: black !important; height: auto !important; min-height: auto !important; overflow: visible !important; }
            .h-screen, .h-full, .flex-1, .flex-col, .overflow-hidden { height: auto !important; overflow: visible !important; display: block !important; }
            aside, header, nav { display: none !important; }
            .print-only { display: block !important; }
            .cover-page { display: flex !important; height: 100vh !important; width: 100% !important; align-items: center; justify-content: center; page-break-after: always; }
            .print-no-border { border: none !important; box-shadow: none !important; background: transparent !important; }
            .flex, main, #cash-report-content { overflow: visible !important; height: auto !important; width: 100% !important; padding: 0 !important; margin: 0 !important; display: block !important; }
            div[x-show="currentTab === 'sales'"], div[x-show="currentTab === 'ledger'"], div[x-show="currentTab === 'requisition'"], div[x-show="currentTab === 'analysis'"] { display: none !important; }
            div[x-show="currentTab === 'report'"] { display: block !important; }
            table th { background-color: #f8fafc !important; color: #475569 !important; border-bottom: 2px solid #cbd5e1 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table td { border-bottom: 1px solid #e2e8f0 !important; color: #000 !important; }
            button, input, select { display: none !important; }
            
            /* KPI Backgrounds */
            .bg-slate-50 { background-color: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bg-emerald-50 { background-color: #ecfdf5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bg-amber-50 { background-color: #fffbeb !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bg-blue-50 { background-color: #eff6ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bg-indigo-50 { background-color: #eef2ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            
            /* Typography adjustments */
            .text-emerald-700 { color: #047857 !important; }
            .text-amber-700 { color: #b45309 !important; }
            .text-blue-700 { color: #1d4ed8 !important; }
            .text-indigo-700 { color: #4338ca !important; }
            
            /* HIGHEST SPECIFICITY FOR HIDING */
            .print-hidden, [class*="print-hidden"] { display: none !important; }
        }
    </style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="cashApp()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8">
            <?php display_flash_message(); ?>

            <!-- Page Header -->
            <div class="flex items-center justify-between mb-4 print-hidden">
                <div><h1 class="text-2xl font-black text-slate-900 dark:text-white">Cash Management</h1><p class="text-sm text-slate-500">Track cash sales, expenses & bank deposits</p></div>
            </div>

            <!-- Guide -->
            <div class="mb-6 rounded-2xl border border-emerald-200/60 dark:border-emerald-800/40 bg-gradient-to-r from-emerald-50 via-green-50/50 to-transparent dark:from-emerald-950/30 dark:via-green-950/20 dark:to-transparent overflow-hidden print-hidden">
                <div class="px-5 py-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center shadow-lg shadow-emerald-500/20 flex-shrink-0 mt-0.5"><i data-lucide="banknote" class="w-4 h-4 text-white"></i></div>
                    <div>
                        <h4 class="text-sm font-bold text-slate-800 dark:text-white mb-1">How does this module work?</h4>
                        <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                            Staff post <strong>cash sales</strong> which await <strong>accountant/cashier confirmation</strong>.
                            Confirmed sales increase the <strong>cash ledger balance (DR)</strong>.
                            Staff can also submit <strong>cash requisitions</strong> for expenses or bank deposits — once approved, they reduce the balance <strong>(CR)</strong>.
                            Use <strong>Cash Analysis</strong> & <strong>Cash Report</strong> for categorized insights and PDF exports.
                        </p>
                    </div>
                </div>
            </div>

            <!-- KPI Strip -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6 print-hidden">
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Cash In (DR)</p><p class="text-xl font-black text-emerald-600"><?= format_currency($total_in) ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Cash Out (CR)</p><p class="text-xl font-black text-red-600"><?= format_currency($total_out) ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Balance</p><p class="text-xl font-black text-blue-600"><?= format_currency($total_in - $total_out) ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Pending Sales</p><p class="text-xl font-black text-amber-600"><?= $pending_sales ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Pending Reqs</p><p class="text-xl font-black text-amber-600"><?= $pending_reqs ?></p></div>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-1 mb-6 bg-slate-200/60 dark:bg-slate-800/60 rounded-xl p-1 w-fit flex-wrap print-hidden">
                <template x-for="t in tabs" :key="t.id">
                    <button @click="currentTab = t.id; if(t.id==='ledger') loadLedger(); if(t.id==='analysis') loadAnalysis(); if(t.id==='report') loadReport();" :class="currentTab === t.id ? 'bg-white dark:bg-slate-700 shadow text-slate-900 dark:text-white' : 'text-slate-500 hover:text-slate-700'" class="px-4 py-2 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5">
                        <i :data-lucide="t.icon" class="w-3.5 h-3.5"></i> <span x-text="t.label"></span>
                        <template x-if="t.id === 'sales' && pendingSalesCount > 0"><span class="ml-1 w-5 h-5 rounded-full bg-amber-500 text-white text-[10px] font-bold flex items-center justify-center" x-text="pendingSalesCount"></span></template>
                        <template x-if="t.id === 'requisition' && pendingReqsCount > 0"><span class="ml-1 w-5 h-5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center" x-text="pendingReqsCount"></span></template>
                    </button>
                </template>
            </div>

            <!-- ═══ TAB: CASH SALES ═══ -->
            <div x-show="currentTab === 'sales'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center shadow-lg shadow-emerald-500/30"><i data-lucide="plus-circle" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Cash Sales</h3><p class="text-xs text-slate-500" x-text="allSales.length + ' entries'"></p></div>
                        </div>
                        <button @click="showSaleForm = !showSaleForm; $nextTick(() => lucide.createIcons())" :class="showSaleForm ? 'from-red-500 to-rose-600 shadow-red-500/30' : 'from-emerald-500 to-green-600 shadow-emerald-500/30'" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                            <i :data-lucide="showSaleForm ? 'x' : 'plus'" class="w-3.5 h-3.5"></i>
                            <span x-text="showSaleForm ? 'Close' : 'Post Cash Sale'"></span>
                        </button>
                    </div>

                    <!-- Sale Form -->
                    <div x-show="showSaleForm" x-transition class="border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500/5 via-green-500/3 to-transparent p-5">
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Date *</label><input type="date" x-model="saleForm.sale_date" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Amount (₦) *</label><input type="number" step="0.01" x-model.number="saleForm.amount" placeholder="0.00" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-bold"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Department</label>
                                <select x-model="saleForm.department" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="">— General —</option>
                                    <template x-for="d in departments" :key="d.id"><option :value="d.name" x-text="d.name"></option></template>
                                </select>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Description *</label><input type="text" x-model="saleForm.description" placeholder="What is this cash sale for?" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button @click="showSaleForm=false" class="px-5 py-2.5 text-xs font-bold text-slate-500">Cancel</button>
                            <button @click="postSale()" class="px-8 py-2.5 bg-gradient-to-r from-emerald-600 to-green-700 text-white text-xs font-black rounded-xl shadow-lg shadow-emerald-500/30 hover:scale-[1.02] transition-all">Post Sale</button>
                        </div>
                    </div>

                    <!-- Sales History (Monthly Grouped) -->
                    <div class="overflow-x-auto max-h-[700px] overflow-y-auto">
                        <template x-for="grp in monthlySales" :key="grp.month">
                            <div>
                                <div @click="grp.open = !grp.open" class="flex items-center justify-between px-4 py-2 bg-emerald-50 dark:bg-emerald-900/20 border-b border-emerald-100 dark:border-emerald-900/30 cursor-pointer hover:bg-emerald-100/60 select-none">
                                    <div class="flex items-center gap-2">
                                        <i :data-lucide="grp.open ? 'chevron-down' : 'chevron-right'" class="w-3.5 h-3.5 text-emerald-500"></i>
                                        <span class="text-xs font-black text-emerald-700 dark:text-emerald-300" x-text="grp.month"></span>
                                        <span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-emerald-200 dark:bg-emerald-800 text-emerald-700 dark:text-emerald-200" x-text="grp.items.length + ' sale' + (grp.items.length===1?'':'s')"></span>
                                    </div>
                                    <span class="text-xs font-bold text-emerald-600" x-text="fmt(grp.items.reduce((s,r) => s + parseFloat(r.amount||0), 0))"></span>
                                </div>
                                <table class="w-full text-sm" x-show="grp.open" x-transition>
                                    <thead class="bg-slate-50 dark:bg-slate-800/50"><tr>
                                        <th class="px-3 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Date</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Posted By</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Description</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Department</th>
                                        <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">Amount</th>
                                        <th class="px-3 py-2 text-center text-[10px] font-bold text-slate-500 uppercase">Status</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Confirmed By</th>
                                        <th class="px-3 py-2 text-center text-[10px] font-bold text-slate-500 uppercase">Action</th>
                                    </tr></thead>
                                    <tbody>
                                        <template x-for="s in grp.items" :key="s.id">
                                            <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-emerald-50/30 transition-colors">
                                                <td class="px-3 py-2.5 font-mono text-xs" x-text="s.sale_date"></td>
                                                <td class="px-3 py-2.5 text-xs" x-text="(s.posted_first||'')+' '+(s.posted_last||'')"></td>
                                                <td class="px-3 py-2.5 text-xs max-w-[200px] truncate" x-text="s.description"></td>
                                                <td class="px-3 py-2.5 text-xs" x-text="s.department || '—'"></td>
                                                <td class="px-3 py-2.5 text-right font-bold" x-text="fmt(s.amount)"></td>
                                                <td class="px-3 py-2.5 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold" :class="s.status==='confirmed'?'bg-emerald-100 text-emerald-700':s.status==='rejected'?'bg-red-100 text-red-700':'bg-amber-100 text-amber-700'" x-text="s.status"></span></td>
                                                <td class="px-3 py-2.5 text-xs">
                                                    <span x-show="s.confirmed_by" x-text="(s.confirmed_first||'')+' '+(s.confirmed_last||'')"></span>
                                                    <span x-show="s.confirmed_at" class="block text-[10px] text-slate-400" x-text="s.confirmed_at?.substring(0,16).replace('T',' ')"></span>
                                                </td>
                                                <td class="px-3 py-2.5 text-center">
                                                    <template x-if="s.status==='pending' && isApprover">
                                                        <div class="flex gap-1 justify-center">
                                                            <button @click="confirmSale(s.id)" class="px-2 py-1 bg-emerald-500 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all">✓ Confirm</button>
                                                            <button @click="rejectSale(s.id)" class="px-2 py-1 bg-red-500 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all">✕</button>
                                                        </div>
                                                    </template>
                                                    <template x-if="s.status==='pending' && s.posted_by==userId && !isApprover">
                                                        <button @click="deleteSale(s.id)" class="p-1 bg-red-50 hover:bg-red-100 rounded-lg"><i data-lucide="trash-2" class="w-3 h-3 text-red-500"></i></button>
                                                    </template>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <div x-show="allSales.length === 0" class="px-4 py-12 text-center text-slate-400 text-sm">No cash sales posted yet</div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: CASH LEDGER ═══ -->
            <div x-show="currentTab === 'ledger'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/30"><i data-lucide="book-open" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Cash Ledger</h3><p class="text-xs text-slate-500">DR/CR journal with running balance</p></div>
                        </div>
                        <input type="month" x-model="ledgerMonth" @change="loadLedger()" class="text-xs px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg">
                    </div>
                    <div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center gap-4">
                        <div class="text-xs"><span class="text-slate-400">Opening Balance:</span> <span class="font-bold text-blue-600" x-text="fmt(ledgerOpening)"></span></div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800/50"><tr>
                                <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">Description</th>
                                <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">Posted By</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold text-emerald-600 uppercase">DR (In)</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold text-red-600 uppercase">CR (Out)</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold text-blue-600 uppercase">Balance</th>
                            </tr></thead>
                            <tbody>
                                <template x-for="e in ledgerEntries" :key="e.id">
                                    <tr class="border-b border-slate-100 dark:border-slate-800">
                                        <td class="px-4 py-2.5 font-mono text-xs" x-text="e.entry_date"></td>
                                        <td class="px-4 py-2.5 text-xs" x-text="e.description"></td>
                                        <td class="px-4 py-2.5 text-xs" x-text="(e.first_name||'')+' '+(e.last_name||'')"></td>
                                        <td class="px-4 py-2.5 text-right font-bold text-emerald-600" x-text="parseFloat(e.dr_amount) > 0 ? fmt(e.dr_amount) : ''"></td>
                                        <td class="px-4 py-2.5 text-right font-bold text-red-600" x-text="parseFloat(e.cr_amount) > 0 ? fmt(e.cr_amount) : ''"></td>
                                        <td class="px-4 py-2.5 text-right font-black text-blue-700 dark:text-blue-300" x-text="fmt(e.balance)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div x-show="ledgerEntries.length === 0" class="px-4 py-12 text-center text-slate-400 text-sm">No ledger entries for this month</div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: CASH REQUISITION ═══ -->
            <div x-show="currentTab === 'requisition'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 flex items-center justify-center shadow-lg shadow-orange-500/30"><i data-lucide="hand-coins" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Cash Requisitions</h3><p class="text-xs text-slate-500" x-text="allReqs.length + ' requests'"></p></div>
                        </div>
                        <div class="flex gap-2">
                            <template x-if="isApprover"><button @click="showCatMgr=!showCatMgr; $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1.5 px-3 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-600 text-xs font-bold rounded-lg transition-all"><i data-lucide="tags" class="w-3.5 h-3.5"></i> Categories</button></template>
                            <button @click="showReqForm=!showReqForm; $nextTick(()=>lucide.createIcons())" :class="showReqForm?'from-red-500 to-rose-600':'from-orange-500 to-amber-600'" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                                <i :data-lucide="showReqForm?'x':'plus'" class="w-3.5 h-3.5"></i> <span x-text="showReqForm?'Close':'New Request'"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Category Manager (approver only) -->
                    <div x-show="showCatMgr" x-transition class="border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-slate-500/5 to-transparent p-5">
                        <h4 class="text-[11px] font-bold uppercase text-slate-400 mb-3">Manage Expense Categories</h4>
                        <div class="flex gap-3 mb-3">
                            <input type="text" x-model="catForm.name" placeholder="Category Name" class="flex-1 px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                            <input type="text" x-model="catForm.description" placeholder="Description (optional)" class="flex-1 px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                            <button @click="saveCategory()" class="px-4 py-2 bg-indigo-600 text-white text-xs font-bold rounded-lg">Save</button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="c in categories" :key="c.id">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-lg text-xs font-semibold text-indigo-700 dark:text-indigo-300">
                                    <span x-text="c.name"></span>
                                    <button @click="deleteCategory(c.id)" class="text-red-400 hover:text-red-600">×</button>
                                </span>
                            </template>
                        </div>
                    </div>

                    <!-- Requisition Form -->
                    <div x-show="showReqForm" x-transition class="border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-orange-500/5 via-amber-500/3 to-transparent p-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Type *</label>
                                <select x-model="reqForm.type" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="expense">Office Expense</option><option value="bank_deposit">Bank Deposit</option>
                                </select>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Category</label>
                                <select x-model="reqForm.category_id" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="">— None —</option>
                                    <template x-for="c in categories" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
                                </select>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Amount (₦) *</label><input type="number" step="0.01" x-model.number="reqForm.amount" placeholder="0.00" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-bold"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Description *</label><input type="text" x-model="reqForm.description" placeholder="What is the money for?" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        </div>
                        <div x-show="reqForm.type==='bank_deposit'" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Bank Name</label><input type="text" x-model="reqForm.bank_name" placeholder="GTBank, First Bank..." class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Account Number</label><input type="text" x-model="reqForm.account_number" placeholder="0123456789" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button @click="showReqForm=false" class="px-5 py-2.5 text-xs font-bold text-slate-500">Cancel</button>
                            <button @click="createRequisition()" class="px-8 py-2.5 bg-gradient-to-r from-orange-600 to-amber-700 text-white text-xs font-black rounded-xl shadow-lg shadow-orange-500/30 hover:scale-[1.02] transition-all">Submit Request</button>
                        </div>
                    </div>

                    <!-- Requisitions History -->
                    <div class="overflow-x-auto max-h-[700px] overflow-y-auto">
                        <template x-for="grp in monthlyReqs" :key="grp.month">
                            <div>
                                <div @click="grp.open=!grp.open" class="flex items-center justify-between px-4 py-2 bg-orange-50 dark:bg-orange-900/20 border-b border-orange-100 dark:border-orange-900/30 cursor-pointer hover:bg-orange-100/60 select-none">
                                    <div class="flex items-center gap-2">
                                        <i :data-lucide="grp.open?'chevron-down':'chevron-right'" class="w-3.5 h-3.5 text-orange-500"></i>
                                        <span class="text-xs font-black text-orange-700 dark:text-orange-300" x-text="grp.month"></span>
                                        <span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-orange-200 dark:bg-orange-800 text-orange-700 dark:text-orange-200" x-text="grp.items.length"></span>
                                    </div>
                                    <span class="text-xs font-bold text-orange-600" x-text="fmt(grp.items.reduce((s,r)=>s+parseFloat(r.amount||0),0))"></span>
                                </div>
                                <table class="w-full text-sm" x-show="grp.open" x-transition>
                                    <tbody>
                                        <template x-for="r in grp.items" :key="r.id">
                                            <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-orange-50/30 transition-colors">
                                                <td class="px-3 py-2.5 font-mono text-xs font-bold text-orange-600" x-text="r.requisition_number"></td>
                                                <td class="px-3 py-2.5 text-xs" x-text="(r.req_first||'')+' '+(r.req_last||'')"></td>
                                                <td class="px-3 py-2.5"><span class="px-1.5 py-0.5 rounded text-[9px] font-bold" :class="r.type==='bank_deposit'?'bg-blue-100 text-blue-700':'bg-amber-100 text-amber-700'" x-text="r.type==='bank_deposit'?'Bank Deposit':'Expense'"></span></td>
                                                <td class="px-3 py-2.5 text-xs" x-text="r.category_name||'—'"></td>
                                                <td class="px-3 py-2.5 text-xs max-w-[150px] truncate" x-text="r.description"></td>
                                                <td class="px-3 py-2.5 text-right font-bold" x-text="fmt(r.amount)"></td>
                                                <td class="px-3 py-2.5 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold" :class="r.status==='approved'?'bg-emerald-100 text-emerald-700':r.status==='rejected'?'bg-red-100 text-red-700':'bg-amber-100 text-amber-700'" x-text="r.status"></span></td>
                                                <td class="px-3 py-2.5 text-xs" x-text="r.created_at?.substring(0,10)"></td>
                                                <td class="px-3 py-2.5 text-center">
                                                    <template x-if="r.status==='pending' && isApprover">
                                                        <div class="flex gap-1 justify-center">
                                                            <button @click="approveReq(r.id)" class="px-2 py-1 bg-emerald-500 text-white text-[10px] font-bold rounded-lg">✓ Approve</button>
                                                            <button @click="rejectReq(r.id)" class="px-2 py-1 bg-red-500 text-white text-[10px] font-bold rounded-lg">✕</button>
                                                        </div>
                                                    </template>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <div x-show="allReqs.length===0" class="px-4 py-12 text-center text-slate-400 text-sm">No cash requisitions yet</div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: CASH ANALYSIS ═══ -->
            <div x-show="currentTab === 'analysis'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg"><i data-lucide="pie-chart" class="w-4 h-4 text-white"></i></div>
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm">Cash Analysis</h3>
                        </div>
                        <input type="month" x-model="analysisMonth" @change="loadAnalysis()" class="text-xs px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg">
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                            <div class="p-4 rounded-xl border border-amber-100 bg-amber-50/50 dark:bg-amber-900/10 dark:border-amber-800"><div class="text-[10px] font-bold uppercase text-amber-500 mb-1">Total Expenses</div><div class="text-xl font-black text-amber-700 dark:text-amber-300" x-text="fmt(analysisTotals.total_expenses||0)"></div></div>
                            <div class="p-4 rounded-xl border border-blue-100 bg-blue-50/50 dark:bg-blue-900/10 dark:border-blue-800"><div class="text-[10px] font-bold uppercase text-blue-500 mb-1">Total Deposits</div><div class="text-xl font-black text-blue-700 dark:text-blue-300" x-text="fmt(analysisTotals.total_deposits||0)"></div></div>
                            <div class="p-4 rounded-xl border border-slate-100 bg-slate-50/50 dark:bg-slate-800 dark:border-slate-700"><div class="text-[10px] font-bold uppercase text-slate-500 mb-1">Total Count</div><div class="text-xl font-black text-slate-700 dark:text-white" x-text="analysisTotals.total_count||0"></div></div>
                        </div>
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800/50"><tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Category</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Type</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Count</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Total (₦)</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">% of Total</th>
                            </tr></thead>
                            <tbody>
                                <template x-for="(row, i) in analysisBreakdown" :key="i">
                                    <tr class="border-b border-slate-100 dark:border-slate-800">
                                        <td class="px-4 py-2.5 font-semibold text-xs" x-text="row.category_name"></td>
                                        <td class="px-4 py-2.5 text-center"><span class="px-1.5 py-0.5 rounded text-[9px] font-bold" :class="row.type==='bank_deposit'?'bg-blue-100 text-blue-700':'bg-amber-100 text-amber-700'" x-text="row.type==='bank_deposit'?'Deposit':'Expense'"></span></td>
                                        <td class="px-4 py-2.5 text-center font-bold" x-text="row.count"></td>
                                        <td class="px-4 py-2.5 text-right font-bold" x-text="fmt(row.total_amount)"></td>
                                        <td class="px-4 py-2.5 text-right text-xs" x-text="((parseFloat(row.total_amount)/(parseFloat(analysisTotals.total_expenses||0)+parseFloat(analysisTotals.total_deposits||0)||1))*100).toFixed(1)+'%'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div x-show="analysisBreakdown.length===0" class="py-12 text-center text-slate-400 text-sm">No approved requisitions for this month</div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: CASH REPORT ═══ -->
            <div x-show="currentTab === 'report'" x-transition class="w-full">
                
                <!-- PROFESSIONAL PRINT DOCUMENT (HIDDEN ON SCREEN) -->
                <div class="print-only">
                    <!-- SUMMARY & ANALYSIS PAGE (FIRST PAGE) -->
                    <div style="page-break-after: always;">
                        <!-- Report Header -->
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h1 style="font-size: 28px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 5px;"><?= htmlspecialchars($client_name) ?></h1>
                            <h2 style="font-size: 18px; font-weight: 800; color: #3b82f6; text-transform: uppercase; letter-spacing: 1px;">Comprehensive Cash Report</h2>
                            <p style="font-size: 12px; font-weight: 600; color: #64748b; margin-top: 5px;">
                                For the Period: <span x-text="new Date(reportMonth + '-15').toLocaleDateString('en-US', {month:'long', year:'numeric'})"></span>
                                | Generated: <?= date('F j, Y') ?>
                            </p>
                        </div>

                        <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">Executive Summary</h3>
                        
                        <div style="display: flex; gap: 15px; margin-bottom: 40px;">
                            <div style="flex: 1; padding: 15px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;"><div style="font-size: 10px; font-weight: bold; text-transform: uppercase; color: #64748b; margin-bottom: 5px;">Opening</div><div style="font-size: 18px; font-weight: 900;" x-text="fmt(reportData.opening||0)"></div></div>
                            <div style="flex: 1; padding: 15px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px;"><div style="font-size: 10px; font-weight: bold; text-transform: uppercase; color: #059669; margin-bottom: 5px;">+ Sales</div><div style="font-size: 18px; font-weight: 900; color: #047857;" x-text="fmt(reportData.total_sales||0)"></div></div>
                            <div style="flex: 1; padding: 15px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;"><div style="font-size: 10px; font-weight: bold; text-transform: uppercase; color: #d97706; margin-bottom: 5px;">− Expenses</div><div style="font-size: 18px; font-weight: 900; color: #b45309;" x-text="fmt(reportData.total_expenses||0)"></div></div>
                            <div style="flex: 1; padding: 15px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;"><div style="font-size: 10px; font-weight: bold; text-transform: uppercase; color: #2563eb; margin-bottom: 5px;">− Deposits</div><div style="font-size: 18px; font-weight: 900; color: #1d4ed8;" x-text="fmt(reportData.total_deposits||0)"></div></div>
                            <div style="flex: 1; padding: 15px; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px;"><div style="font-size: 10px; font-weight: bold; text-transform: uppercase; color: #4f46e5; margin-bottom: 5px;">= Closing</div><div style="font-size: 18px; font-weight: 900; color: #4338ca;" x-text="fmt(reportData.closing||0)"></div></div>
                        </div>

                        <h4 style="font-size: 14px; font-weight: 800; color: #475569; margin-bottom: 10px;">Cash Analysis Breakdown</h4>
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 20px;">
                            <thead>
                                <tr>
                                    <th style="padding: 10px; text-align: left; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Category</th>
                                    <th style="padding: 10px; text-align: center; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Type</th>
                                    <th style="padding: 10px; text-align: right; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Count</th>
                                    <th style="padding: 10px; text-align: right; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Total Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, i) in analysisBreakdown" :key="i">
                                    <tr>
                                        <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;" x-text="row.category_name"></td>
                                        <td style="padding: 10px; text-align: center; border-bottom: 1px solid #e2e8f0;" x-text="row.type==='bank_deposit'?'Deposit':'Expense'"></td>
                                        <td style="padding: 10px; text-align: right; border-bottom: 1px solid #e2e8f0;" x-text="row.count"></td>
                                        <td style="padding: 10px; text-align: right; font-weight: bold; border-bottom: 1px solid #e2e8f0;" x-text="fmt(row.total_amount)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- LEDGER PAGE -->
                    <div style="page-break-after: always;">
                        <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">Cash Ledger (Statement)</h3>
                        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                            <thead>
                                <tr>
                                    <th style="padding: 8px; text-align: left; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Date</th>
                                    <th style="padding: 8px; text-align: left; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Description</th>
                                    <th style="padding: 8px; text-align: left; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Posted By</th>
                                    <th style="padding: 8px; text-align: right; background: #f8fafc; border-bottom: 2px solid #cbd5e1; color: #059669;">DR (In)</th>
                                    <th style="padding: 8px; text-align: right; background: #f8fafc; border-bottom: 2px solid #cbd5e1; color: #dc2626;">CR (Out)</th>
                                    <th style="padding: 8px; text-align: right; background: #f8fafc; border-bottom: 2px solid #cbd5e1; color: #2563eb;">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="e in ledgerEntries" :key="e.id">
                                    <tr>
                                        <td style="padding: 8px; border-bottom: 1px solid #e2e8f0;" x-text="e.entry_date"></td>
                                        <td style="padding: 8px; border-bottom: 1px solid #e2e8f0; max-width: 250px;" x-text="e.description"></td>
                                        <td style="padding: 8px; border-bottom: 1px solid #e2e8f0;" x-text="(e.first_name||'')+' '+(e.last_name||'')"></td>
                                        <td style="padding: 8px; text-align: right; font-weight: bold; border-bottom: 1px solid #e2e8f0; color: #059669;" x-text="parseFloat(e.dr_amount) > 0 ? fmt(e.dr_amount) : ''"></td>
                                        <td style="padding: 8px; text-align: right; font-weight: bold; border-bottom: 1px solid #e2e8f0; color: #dc2626;" x-text="parseFloat(e.cr_amount) > 0 ? fmt(e.cr_amount) : ''"></td>
                                        <td style="padding: 8px; text-align: right; font-weight: 900; border-bottom: 1px solid #e2e8f0; color: #2563eb;" x-text="fmt(e.balance)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- SALES PAGE -->
                    <div style="page-break-after: always;">
                        <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">Cash Sales Transactions</h3>
                        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                            <thead>
                                <tr>
                                    <th style="padding: 8px; text-align: left; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Date</th>
                                    <th style="padding: 8px; text-align: left; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Description</th>
                                    <th style="padding: 8px; text-align: left; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Posted By</th>
                                    <th style="padding: 8px; text-align: center; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Status</th>
                                    <th style="padding: 8px; text-align: right; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="s in (reportData.sales||[])" :key="s.id">
                                    <tr>
                                        <td style="padding: 8px; border-bottom: 1px solid #e2e8f0;" x-text="s.sale_date"></td>
                                        <td style="padding: 8px; border-bottom: 1px solid #e2e8f0;" x-text="s.description"></td>
                                        <td style="padding: 8px; border-bottom: 1px solid #e2e8f0;" x-text="(s.posted_first||'')+' '+(s.posted_last||'')"></td>
                                        <td style="padding: 8px; text-align: center; border-bottom: 1px solid #e2e8f0;" x-text="s.status"></td>
                                        <td style="padding: 8px; text-align: right; font-weight: bold; border-bottom: 1px solid #e2e8f0;" x-text="fmt(s.amount)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- REQUISITIONS PAGE -->
                    <div>
                        <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">Requisitions & Deposits</h3>
                        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                            <thead>
                                <tr>
                                    <th style="padding: 8px; text-align: left; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Req #</th>
                                    <th style="padding: 8px; text-align: left; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Type / Category</th>
                                    <th style="padding: 8px; text-align: left; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Description</th>
                                    <th style="padding: 8px; text-align: center; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Status</th>
                                    <th style="padding: 8px; text-align: right; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="r in (reportData.requisitions||[])" :key="r.id">
                                    <tr>
                                        <td style="padding: 8px; border-bottom: 1px solid #e2e8f0; font-family: monospace; font-weight: bold; color: #ea580c;" x-text="r.requisition_number"></td>
                                        <td style="padding: 8px; border-bottom: 1px solid #e2e8f0;" x-text="(r.type==='bank_deposit'?'Deposit':'Expense') + (r.category_name ? ' — '+r.category_name : '')"></td>
                                        <td style="padding: 8px; border-bottom: 1px solid #e2e8f0; max-width: 250px;" x-text="r.description"></td>
                                        <td style="padding: 8px; text-align: center; border-bottom: 1px solid #e2e8f0;" x-text="r.status"></td>
                                        <td style="padding: 8px; text-align: right; font-weight: bold; border-bottom: 1px solid #e2e8f0;" x-text="fmt(r.amount)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ON-SCREEN INTERACTIVE UI (HIDDEN IN PRINT) -->
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden print-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg"><i data-lucide="file-text" class="w-4 h-4 text-white"></i></div>
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm">Cash Report Builder</h3>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="month" x-model="reportMonth" @change="loadReport()" class="text-xs px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg">
                            <button @click="printReport()" class="flex items-center gap-1.5 px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-700 text-xs font-bold rounded-lg"><i data-lucide="printer" class="w-3.5 h-3.5"></i> Comprehensive PDF</button>
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="mb-4 text-sm text-slate-500 text-center py-10 bg-slate-50 dark:bg-slate-900/50 rounded-xl border border-dashed border-slate-300 dark:border-slate-700">
                            <i data-lucide="book-open" class="w-12 h-12 text-indigo-200 mx-auto mb-3"></i>
                            <h4 class="font-bold text-slate-700 dark:text-slate-300">Ready to Print</h4>
                            <p class="max-w-xs mx-auto mt-2">Click the button above to generate the full, multi-page comprehensive cash report.</p>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>
<?php include '../includes/dashboard_scripts.php'; ?>
<script src="cash_app.js?v=<?= filemtime(__DIR__ . '/cash_app.js') ?>"></script>
<script>
function cashApp() { return cashModule(<?= $js_sales ?>, <?= $js_reqs ?>, <?= $js_cats ?>, <?= $js_depts ?>, <?= $user_id ?>, <?= $is_approver ? 'true' : 'false' ?>, <?= $js_cash_allowed ?>); }
</script>
</body>
</html>
