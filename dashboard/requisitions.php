<?php
/**
 * MIAUDITOPS — Requisition Management (Stock-In Style)
 * 3 Tabs: Requisitions, Approvals Queue, Purchase Orders
 */
require_once '../includes/functions.php';
require_login();
require_subscription('requisitions');
require_permission('requisitions');
require_active_client();
$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$user_role  = get_user_role();
$company    = get_company($company_id);
$company_name = $company['name'] ?? 'Company';
$client_name  = $_SESSION['active_client_name'] ?? 'Client';
$page_title = 'Requisitions';

// Departments created by client (from Stock Audit)
$stmt = $pdo->prepare("SELECT sd.id, sd.name, sd.type FROM stock_departments sd WHERE sd.company_id = ? AND sd.client_id = ? AND sd.deleted_at IS NULL ORDER BY sd.name");
$stmt->execute([$company_id, $client_id]);
$client_departments = $stmt->fetchAll();

// Products for line items (client-specific)
$stmt = $pdo->prepare("SELECT id, name, sku, unit, unit_cost, category FROM products WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL ORDER BY category, name");
$stmt->execute([$company_id, $client_id]);
$products = $stmt->fetchAll();

// All requisitions with requestor info (client-scoped)
$stmt = $pdo->prepare("SELECT r.*, u.first_name, u.last_name FROM requisitions r JOIN users u ON r.requested_by = u.id WHERE r.company_id = ? AND r.client_id = ? AND r.deleted_at IS NULL ORDER BY r.created_at DESC LIMIT 200");
$stmt->execute([$company_id, $client_id]);
$all_reqs = $stmt->fetchAll();

// My requisitions
$my_reqs = array_values(array_filter($all_reqs, fn($r) => $r['requested_by'] == $user_id));

// Pending approvals for this user's role
$pending_for_me = array_values(array_filter($all_reqs, function($r) use ($user_role) {
    if ($r['status'] === 'submitted' && in_array($user_role, ['business_owner','super_admin','department_head'])) return true;
    if ($r['status'] === 'hod_approved' && in_array($user_role, ['auditor','business_owner','super_admin'])) return true;
    if ($r['status'] === 'audit_approved' && in_array($user_role, ['business_owner','super_admin'])) return true;
    return false;
}));

// Approved requisitions visible to approving officials
// business_owner/super_admin see ALL approved reqs; others see only reqs they personally approved
if (in_array($user_role, ['business_owner','super_admin'])) {
    $approved_reqs = array_values(array_filter($all_reqs, fn($r) => in_array($r['status'], ['ceo_approved','po_created'])));
} else {
    $approved_reqs = array_values(array_filter($all_reqs, fn($r) => in_array($r['status'], ['ceo_approved','po_created']) && ($r['approved_by'] ?? 0) == $user_id));
}

// Purchase Orders (client-scoped, wrapped in try-catch)
$pos = [];
try {
    $stmt = $pdo->prepare("SELECT po.*, r.requisition_number, r.department, r.id as requisition_id, u.first_name, u.last_name FROM purchase_orders po JOIN requisitions r ON po.requisition_id = r.id JOIN users u ON po.created_by = u.id WHERE po.company_id = ? AND r.client_id = ? ORDER BY po.created_at DESC LIMIT 100");
    $stmt->execute([$company_id, $client_id]);
    $pos = $stmt->fetchAll();
} catch (Exception $e) { $pos = []; }

// Stats
$total_reqs = count($all_reqs);
$approved_count = count(array_filter($all_reqs, fn($r) => in_array($r['status'], ['ceo_approved','po_created'])));
$rejected_count = count(array_filter($all_reqs, fn($r) => $r['status'] === 'rejected'));
$pending_count  = count(array_filter($all_reqs, fn($r) => !in_array($r['status'], ['ceo_approved','po_created','rejected','cancelled'])));
$total_value = array_sum(array_map(fn($r) => $r['total_amount'] ?? 0, $all_reqs));

// Departments list (client-scoped)
$stmt = $pdo->prepare("SELECT DISTINCT department FROM requisitions WHERE company_id = ? AND client_id = ? AND department != '' AND deleted_at IS NULL ORDER BY department");
$stmt->execute([$company_id, $client_id]);
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

$js_products  = json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS);
$js_my_reqs   = json_encode($my_reqs, JSON_HEX_TAG | JSON_HEX_APOS);
$js_all_reqs  = json_encode($all_reqs, JSON_HEX_TAG | JSON_HEX_APOS);
$js_pending   = json_encode($pending_for_me, JSON_HEX_TAG | JSON_HEX_APOS);
$js_approved  = json_encode($approved_reqs, JSON_HEX_TAG | JSON_HEX_APOS);
$js_pos       = json_encode($pos, JSON_HEX_TAG | JSON_HEX_APOS);
$js_depts     = json_encode($departments, JSON_HEX_TAG | JSON_HEX_APOS);
$js_client_depts = json_encode($client_departments, JSON_HEX_TAG | JSON_HEX_APOS);
$js_company_name = json_encode($company_name, JSON_HEX_TAG | JSON_HEX_APOS);
$js_client_name  = json_encode($client_name, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requisitions — MIAUDITOPS</title>
    <?php include '../includes/pwa_head.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']},colors:{brand:{50:'#f5f3ff',100:'#ede9fe',200:'#ddd6fe',300:'#c4b5fd',400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed',700:'#6d28d9',800:'#5b21b6',900:'#4c1d95',950:'#2e1065'}}}}}</script>
    <style>[x-cloak]{display:none!important}.glass-card{background:linear-gradient(135deg,rgba(255,255,255,.95),rgba(249,250,251,.9));backdrop-filter:blur(20px)}.dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,.95),rgba(30,41,59,.9))}</style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="reqApp()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8">
            <?php display_flash_message(); ?>

            <!-- Page Header -->
            <div class="flex items-center justify-between mb-4">
                <div><h1 class="text-2xl font-black text-slate-900 dark:text-white">Requisitions</h1><p class="text-sm text-slate-500">Purchase request & approval management</p></div>
            </div>

            <!-- Guide Note -->
            <div class="mb-6 rounded-2xl border border-blue-200/60 dark:border-blue-800/40 bg-gradient-to-r from-blue-50 via-indigo-50/50 to-transparent dark:from-blue-950/30 dark:via-indigo-950/20 dark:to-transparent overflow-hidden">
                <div class="px-5 py-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/20 flex-shrink-0 mt-0.5">
                        <i data-lucide="lightbulb" class="w-4 h-4 text-white"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-slate-800 dark:text-white mb-1">How does this module work?</h4>
                        <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed mb-2">
                            This module streamlines your <strong>procurement workflow</strong> across your business. 
                            Team members and managers can formally <strong>request goods, services, or materials</strong>, 
                            route requests through a <strong>multi-level approval chain</strong> (HOD → Auditor → CEO/MD), 
                            and management can <strong>approve before purchasing</strong> — creating a clear, auditable procurement trail from 
                            <span class="font-semibold text-indigo-600 dark:text-indigo-400">Requisition → Approval → Purchase Order</span>.
                        </p>
                        <div class="flex items-start gap-2 px-3 py-2.5 bg-amber-50/80 dark:bg-amber-950/20 border border-amber-200/60 dark:border-amber-800/40 rounded-xl">
                            <i data-lucide="user-plus" class="w-3.5 h-3.5 text-amber-600 flex-shrink-0 mt-0.5"></i>
                            <p class="text-[11px] text-amber-800 dark:text-amber-300 leading-relaxed">
                                <strong>Setup required:</strong> To use this module, the admin must first create 
                                <strong>user accounts</strong> (username &amp; password) for managers, department heads, and 
                                staff who need access to submit or approve requisitions. 
                                Go to <strong class="text-amber-900 dark:text-amber-200">Settings → User Management</strong> to add team members and assign their roles.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI Strip -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total</p><p class="text-xl font-black text-slate-800 dark:text-white"><?= $total_reqs ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Pending</p><p class="text-xl font-black text-amber-600"><?= $pending_count ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Approved</p><p class="text-xl font-black text-emerald-600"><?= $approved_count ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Rejected</p><p class="text-xl font-black text-red-600"><?= $rejected_count ?></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Value</p><p class="text-xl font-black text-blue-600"><?= format_currency($total_value) ?></p></div>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-1 mb-6 bg-slate-200/60 dark:bg-slate-800/60 rounded-xl p-1 w-fit">
                <template x-for="t in tabs" :key="t.id">
                    <button @click="currentTab = t.id" :class="currentTab === t.id ? 'bg-white dark:bg-slate-700 shadow text-slate-900 dark:text-white' : 'text-slate-500 hover:text-slate-700'" class="px-4 py-2 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5">
                        <i :data-lucide="t.icon" class="w-3.5 h-3.5"></i> <span x-text="t.label"></span>
                        <template x-if="t.id === 'approvals' && pendingApprovals.length > 0"><span class="ml-1 w-5 h-5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center" x-text="pendingApprovals.length"></span></template>
                    </button>
                </template>
            </div>

            <!-- ========== TAB: Requisitions ========== -->
            <div x-show="currentTab === 'requisitions'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg shadow-indigo-500/30"><i data-lucide="file-plus" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Requisitions</h3><p class="text-xs text-slate-500" x-text="allReqs.length + ' total requests'"></p></div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button @click="printRequisitions()" class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-bold rounded-lg transition-all" title="Download PDF"><i data-lucide="download" class="w-3.5 h-3.5"></i> PDF</button>
                            <button @click="showForm = !showForm; $nextTick(() => lucide.createIcons())" :class="showForm ? 'from-red-500 to-rose-600 shadow-red-500/30' : 'from-indigo-500 to-violet-600 shadow-indigo-500/30'" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                                <i :data-lucide="showForm ? 'x' : 'file-plus'" class="w-3.5 h-3.5"></i>
                                <span x-text="showForm ? 'Close' : 'New Requisition'"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Inline Create Form (Stock-In style) -->
                    <div x-show="showForm" x-transition.duration.200ms class="border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-indigo-500/5 via-violet-500/3 to-transparent">
                        <div class="p-5">
                            <!-- Form Header: Department, Purpose, Priority -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
                                <div>
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Department *</label>
                                    <select x-model="reqForm.department" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all">
                                        <option value="">Select Department...</option>
                                        <option value="Main Store">Main Store</option>
                                        <template x-for="dept in clientDepartments" :key="dept.id">
                                            <option :value="dept.name" x-text="dept.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Purpose / Description *</label>
                                    <input type="text" x-model="reqForm.purpose" placeholder="What is this for?" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all">
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Priority</label>
                                    <select x-model="reqForm.priority" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                        <option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Line Items Header + Unified Search -->
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-4 flex-1 mr-4">
                                    <h4 class="text-[11px] font-bold uppercase text-slate-400 shrink-0">Requisition Items</h4>
                                    <div class="relative flex-1 max-w-md">
                                        <div class="relative">
                                            <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                                            <input type="text" x-model="searchTerm" @input.debounce.300ms="searchItems()" @focus="showSearchResults = searchResults.length > 0" placeholder="Search products, station items, expenses..." class="w-full pl-9 pr-4 py-2 bg-white/50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg text-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition-all">
                                        </div>
                                        <div x-show="showSearchResults && searchResults.length > 0" @click.away="showSearchResults = false" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl shadow-2xl z-[60] max-h-72 overflow-y-auto">
                                            <template x-for="item in searchResults" :key="item.ref_id">
                                                <button @click="addSearchedItem(item)" class="w-full text-left px-4 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800 border-b border-slate-100 dark:border-slate-800 last:border-0 transition-colors">
                                                    <div class="flex items-center justify-between">
                                                        <div class="flex items-center gap-2 flex-1 min-w-0">
                                                            <span class="shrink-0 px-1.5 py-0.5 rounded text-[8px] font-black uppercase tracking-wider" :class="{'bg-emerald-100 text-emerald-700': item.source==='product', 'bg-orange-100 text-orange-700': item.source==='station_lube', 'bg-blue-100 text-blue-700': item.source==='expense'}" x-text="item.source_label"></span>
                                                            <div class="min-w-0">
                                                                <div class="text-xs font-bold text-slate-800 dark:text-white truncate" x-text="item.name"></div>
                                                                <div class="text-[10px] text-slate-400" x-text="(item.category || '') + (item.sku ? ' · ' + item.sku : '')"></div>
                                                            </div>
                                                        </div>
                                                        <div class="shrink-0 text-right ml-3">
                                                            <div class="text-xs font-bold text-indigo-600" x-text="item.current_price ? fmt(item.current_price) : '—'"></div>
                                                            <template x-if="item.old_price && item.old_price !== item.current_price">
                                                                <div class="text-[9px] text-slate-400 line-through" x-text="'was ' + fmt(item.old_price)"></div>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </button>
                                            </template>
                                            <div x-show="searchResults.length === 0 && searchTerm.length > 0" class="px-4 py-3 text-center text-xs text-slate-400">No items found across any source</div>
                                        </div>
                                        <div x-show="searchLoading" class="absolute right-3 top-1/2 -translate-y-1/2"><div class="w-3.5 h-3.5 border-2 border-indigo-400 border-t-transparent rounded-full animate-spin"></div></div>
                                    </div>
                                </div>
                                <button type="button" @click="addLineItem()" class="flex items-center gap-1 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-900/20 text-indigo-600 text-xs font-bold rounded-lg transition-all">
                                    <i data-lucide="plus" class="w-3 h-3"></i> Add Row
                                </button>
                            </div>

                            <!-- Line Items Table -->
                            <div class="border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden mb-4">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                                        <tr>
                                            <th class="px-3 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Item / Product</th>
                                            <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-20">Qty</th>
                                            <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-32">Est. Unit Price (₦)</th>
                                            <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-28">Est. Total (₦)</th>
                                            <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-12"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(line, idx) in reqForm.items" :key="idx">
                                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                                <td class="px-3 py-2">
                                                    <div class="space-y-1.5">
                                                        <div class="flex items-center gap-1.5">
                                                            <template x-if="line.source_label">
                                                                <span class="shrink-0 px-1 py-0.5 rounded text-[7px] font-black uppercase" :class="{'bg-emerald-100 text-emerald-700': line.source==='product', 'bg-orange-100 text-orange-700': line.source==='station_lube', 'bg-blue-100 text-blue-700': line.source==='expense', 'bg-slate-100 text-slate-500': !line.source || line.source==='custom'}" x-text="line.source_label"></span>
                                                            </template>
                                                            <select x-model="line.product_id" @change="autoFillProduct(line)" class="flex-1 px-2 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                                                <option value="">— Select Product or type custom below —</option>
                                                                <option value="custom" class="font-bold text-indigo-600">✏️ Custom / Unlisted Item</option>
                                                                <template x-for="p in products" :key="p.id"><option :value="p.id" x-text="(p.category ? '[' + p.category + '] ' : '') + p.name + (p.sku ? ' (' + p.sku + ')' : '')"></option></template>
                                                            </select>
                                                        </div>
                                                        <template x-if="!line.product_id || line.product_id === 'custom'">
                                                            <input type="text" x-model="line.description" placeholder="Type item name / description..." class="w-full px-2 py-2 bg-amber-50 dark:bg-amber-900/10 border border-amber-300 dark:border-amber-700 rounded-lg text-xs placeholder-amber-400 focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all">
                                                        </template>
                                                    </div>
                                                </td>
                                                <td class="px-3 py-2"><input type="number" x-model.number="line.quantity" min="1" class="w-full px-2 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-semibold"></td>
                                                <td class="px-3 py-2">
                                                    <input type="number" step="0.01" x-model.number="line.unit_price" min="0" class="w-full px-2 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-semibold" :placeholder="line.old_price ? 'Old: ' + line.old_price : '0'">
                                                    <template x-if="line.old_price && line.unit_price && line.unit_price != line.old_price">
                                                        <div class="text-[9px] text-center mt-0.5">
                                                            <span class="text-slate-400 line-through" x-text="fmt(line.old_price)"></span>
                                                            <span class="ml-1" :class="line.unit_price > line.old_price ? 'text-red-500' : 'text-emerald-500'" x-text="line.unit_price > line.old_price ? '▲' : '▼'"></span>
                                                        </div>
                                                    </template>
                                                    <template x-if="line.old_price && (!line.unit_price || line.unit_price == 0)">
                                                        <div class="text-[9px] text-center mt-0.5 text-amber-500">Will use old price: <span x-text="fmt(line.old_price)"></span></div>
                                                    </template>
                                                </td>
                                                <td class="px-3 py-2"><div class="w-full px-2 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold text-indigo-600" x-text="fmt((line.unit_price || line.old_price || 0) * line.quantity)"></div></td>
                                                <td class="px-3 py-2 text-center"><button type="button" @click="reqForm.items.splice(idx, 1)" x-show="reqForm.items.length > 1" class="w-7 h-7 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 flex items-center justify-center transition-all"><i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-500"></i></button></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Grand Total + Submit -->
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-6">
                                    <div class="flex flex-col"><span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Items</span><span class="text-lg font-black text-slate-900 dark:text-white" x-text="reqForm.items.length"></span></div>
                                    <div class="flex flex-col"><span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Qty</span><span class="text-lg font-black text-slate-900 dark:text-white" x-text="reqForm.items.reduce((s,l) => s + (parseInt(l.quantity)||0), 0)"></span></div>
                                    <div class="flex flex-col"><span class="text-[10px] font-bold text-indigo-400 uppercase tracking-wider">Grand Total</span><span class="text-lg font-black text-indigo-600" x-text="fmt(reqFormTotal)"></span></div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <button @click="resetForm(); showForm = false" class="px-5 py-2.5 text-xs font-bold text-slate-500 hover:text-slate-700 transition-colors">Cancel</button>
                                    <button @click="createRequisition()" class="px-8 py-2.5 bg-gradient-to-r from-indigo-600 to-violet-700 text-white text-xs font-black rounded-xl shadow-lg shadow-indigo-500/30 hover:scale-[1.02] transition-all">Submit Requisition</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter bar -->
                    <div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
                        <select x-model="statusFilter" class="px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                            <option value="">All Status</option><option value="submitted">Submitted</option><option value="hod_approved">HOD Approved</option><option value="audit_approved">Audit Approved</option><option value="ceo_approved">CEO Approved</option><option value="po_created">PO Created</option><option value="rejected">Rejected</option>
                        </select>
                        <label class="flex items-center gap-1.5 text-xs text-slate-500"><input type="checkbox" x-model="showMineOnly" class="rounded border-slate-300"> Mine only</label>
                    </div>

                    <!-- Requisition History — Monthly Grouped -->
                    <div class="overflow-x-auto max-h-[700px] overflow-y-auto">
                        <template x-for="group in monthlyReqs" :key="group.month">
                            <div>
                                <!-- Month header -->
                                <div @click="group.open = !group.open" class="flex items-center justify-between px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 border-b border-indigo-100 dark:border-indigo-900/30 cursor-pointer hover:bg-indigo-100/60 dark:hover:bg-indigo-900/40 select-none">
                                    <div class="flex items-center gap-2">
                                        <i :data-lucide="group.open ? 'chevron-down' : 'chevron-right'" class="w-3.5 h-3.5 text-indigo-500"></i>
                                        <span class="text-xs font-black text-indigo-700 dark:text-indigo-300" x-text="group.month"></span>
                                        <span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-indigo-200 dark:bg-indigo-800 text-indigo-700 dark:text-indigo-200" x-text="group.reqs.length + ' req' + (group.reqs.length===1?'':'s')"></span>
                                    </div>
                                    <span class="text-xs font-bold text-indigo-600 dark:text-indigo-300" x-text="fmt(group.reqs.reduce((s,r) => s + parseFloat(r.total_amount||0), 0))"></span>
                                </div>
                                <!-- Rows -->
                                <table class="w-full text-sm" x-show="group.open" x-transition>
                                    <tbody>
                                        <template x-for="r in group.reqs" :key="r.id">
                                            <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-indigo-50/50 dark:hover:bg-slate-800/30 cursor-pointer" @click="toggleExpand(r.id)">
                                                <td class="px-3 py-2.5 font-mono text-xs font-bold text-indigo-600 dark:text-indigo-400" x-text="r.requisition_number"></td>
                                                <td class="px-3 py-2.5 text-xs text-slate-700 dark:text-slate-200" x-text="r.first_name + ' ' + r.last_name"></td>
                                                <td class="px-3 py-2.5 text-xs text-slate-700 dark:text-slate-300" x-text="r.department"></td>
                                                <td class="px-3 py-2.5 text-xs max-w-[150px] truncate text-slate-700 dark:text-slate-300" x-text="r.purpose"></td>
                                                <td class="px-3 py-2.5 text-right font-bold text-slate-800 dark:text-white" x-text="fmt(r.total_amount)"></td>
                                                <td class="px-3 py-2.5 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="getPriorityColor(r.priority)" x-text="r.priority"></span></td>
                                                <td class="px-3 py-2.5 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="getStatusColor(r.status)" x-text="(r.status === 'ceo_approved' || r.status === 'po_created') ? (r.status === 'po_created' ? 'PO Created' : 'Approved') : r.status?.replace(/_/g,' ')"></span></td>
                                                <td class="px-3 py-2.5 font-mono text-xs text-slate-400 dark:text-slate-400" x-text="r.created_at?.substring(0,10)"></td>
                                                <td class="px-3 py-2.5 text-center" @click.stop>
                                                    <div class="flex items-center justify-center gap-1">
                                                        <template x-if="r.status === 'po_created'"><button @click="openPriceModal(r)" class="px-2 py-1 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all" title="Fill Actual Purchase Prices">₦ Price</button></template>
                                                        <template x-if="r.status === 'submitted' && r.requested_by == userId"><button @click="deleteReq(r.id)" class="p-1 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 rounded-lg transition-all"><i data-lucide="trash-2" class="w-3 h-3 text-red-500"></i></button></template>
                                                    </div>
                                                </td>
                                            </tr>
                                            <!-- Expanded items -->
                                            <tr x-show="expandedId === r.id && expandedItems.length > 0" class="bg-indigo-50/30 dark:bg-slate-800/50">
                                                <td colspan="9" class="px-6 py-3">
                                                    <div class="text-[10px] font-bold uppercase text-slate-400 mb-2">Line Items</div>
                                                    <table class="w-full text-xs">
                                                        <thead><tr class="text-slate-400"><th class="text-left pb-1">Item</th><th class="text-center pb-1">Qty</th><th class="text-right pb-1">Est. Price</th><th class="text-right pb-1">Est. Total</th><th class="text-right pb-1">Actual Price</th></tr></thead>
                                                        <tbody>
                                                            <template x-for="item in expandedItems" :key="item.id">
                                                                <tr class="border-t border-slate-200/50 dark:border-slate-700/50">
                                                                    <td class="py-1.5 font-semibold" x-text="item.product_name || item.description"></td>
                                                                    <td class="py-1.5 text-center" x-text="item.quantity"></td>
                                                                    <td class="py-1.5 text-right" x-text="fmt(item.unit_price)"></td>
                                                                    <td class="py-1.5 text-right font-bold" x-text="fmt(item.total_price)"></td>
                                                                    <td class="py-1.5 text-right" :class="item.actual_unit_price ? 'font-bold text-emerald-600' : 'text-slate-300'" x-text="item.actual_unit_price ? fmt(item.actual_unit_price) : '—'"></td>
                                                                </tr>
                                                            </template>
                                                        </tbody>
                                                    </table>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <div x-show="monthlyReqs.length === 0" class="px-4 py-12 text-center text-slate-400 text-sm">No requisitions found</div>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: Approvals Queue ========== -->
            <div x-show="currentTab === 'approvals'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30"><i data-lucide="check-square" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Pending Approvals</h3><p class="text-xs text-slate-500" x-text="pendingApprovals.length + ' awaiting your action'"></p></div>
                        </div>
                        <button @click="printApprovals()" class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-bold rounded-lg transition-all" title="Download PDF"><i data-lucide="download" class="w-3.5 h-3.5"></i> PDF</button>
                    </div>
                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        <template x-for="r in pendingApprovals" :key="r.id">
                            <div class="p-5 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="font-mono text-sm font-bold text-indigo-600" x-text="r.requisition_number"></span>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="getPriorityColor(r.priority)" x-text="r.priority"></span>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="getStatusColor(r.status)" x-text="r.status?.replace(/_/g,' ')"></span>
                                        </div>
                                        <p class="text-sm text-slate-700 dark:text-slate-300 mb-1" x-text="r.purpose"></p>
                                        <div class="flex items-center gap-4 text-xs text-slate-400">
                                            <span x-text="'By: ' + r.first_name + ' ' + r.last_name"></span>
                                            <span x-text="'Dept: ' + r.department"></span>
                                            <span x-text="'Date: ' + r.created_at?.substring(0,10)"></span>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-lg font-black text-slate-800 dark:text-white" x-text="fmt(r.total_amount)"></p>
                                        <div class="flex gap-2 mt-2">
                                            <button @click="openApprovalModal(r)" class="px-3 py-1.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-xs font-bold rounded-lg shadow-sm hover:scale-105 transition-all">✓ Review & Approve</button>
                                            <button @click="rejectReq(r.id)" class="px-3 py-1.5 bg-gradient-to-r from-red-500 to-rose-600 text-white text-xs font-bold rounded-lg shadow-sm hover:scale-105 transition-all">✕ Reject</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <div x-show="pendingApprovals.length === 0" class="p-12 text-center">
                            <i data-lucide="check-circle-2" class="w-12 h-12 mx-auto text-emerald-300 mb-3"></i>
                            <p class="text-sm font-semibold text-slate-500">All caught up — no pending approvals!</p>
                        </div>
                    </div>
                </div>

                <!-- ===== Approved Requisitions History (Monthly Grouped) ===== -->
                <div class="mt-6 glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500/10 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center"><i data-lucide="check-circle" class="w-4 h-4 text-white"></i></div>
                            <div>
                                <h4 class="font-bold text-sm text-slate-700 dark:text-white">Approved Requisitions</h4>
                                <p class="text-[10px] text-slate-400">All CEO-approved / PO-created requisitions</p>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300" x-text="approvedReqs.length + ' total'"></span>
                    </div>
                    <div class="overflow-x-auto max-h-[500px] overflow-y-auto">
                        <template x-for="grp in _approvedGroups" :key="grp.key">
                            <div>
                                <!-- Month header -->
                                <div @click="grp.open = !grp.open" class="flex items-center justify-between px-4 py-2 bg-emerald-50 dark:bg-emerald-900/20 border-b border-emerald-100 dark:border-emerald-900/30 cursor-pointer hover:bg-emerald-100/60 dark:hover:bg-emerald-900/40 select-none">
                                    <div class="flex items-center gap-2">
                                        <i :data-lucide="grp.open ? 'chevron-down' : 'chevron-right'" class="w-3.5 h-3.5 text-emerald-500"></i>
                                        <span class="text-xs font-black text-emerald-700 dark:text-emerald-300" x-text="grp.month"></span>
                                        <span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-emerald-200 dark:bg-emerald-800 text-emerald-700 dark:text-emerald-200" x-text="grp.reqs.length + ' req' + (grp.reqs.length===1?'':'s')"></span>
                                    </div>
                                    <span class="text-xs font-bold text-emerald-600 dark:text-emerald-300" x-text="fmt(grp.reqs.reduce((s,r) => s + parseFloat(r.total_amount||0), 0))"></span>
                                </div>
                                <!-- Rows -->
                                <table class="w-full text-xs" x-show="grp.open" x-transition>
                                    <tbody>
                                        <template x-for="r in grp.reqs" :key="r.id">
                                            <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-emerald-50/30 dark:hover:bg-slate-800/30 cursor-pointer transition-colors" @click="toggleApprovedExpand(r.id)">
                                                <td class="px-4 py-2.5 font-mono font-bold text-indigo-600 dark:text-indigo-400" x-text="r.requisition_number"></td>
                                                <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300" x-text="r.department || '—'"></td>
                                                <td class="px-4 py-2.5 text-slate-700 dark:text-slate-300 max-w-[150px] truncate" x-text="r.purpose"></td>
                                                <td class="px-4 py-2.5 text-slate-500 dark:text-slate-300" x-text="r.first_name + ' ' + r.last_name"></td>
                                                <td class="px-4 py-2.5 text-right font-bold text-slate-700 dark:text-white" x-text="fmt(r.total_amount)"></td>
                                                <td class="px-4 py-2.5 text-center"><span class="px-2 py-0.5 rounded-full text-[9px] font-bold" :class="getStatusColor(r.status)" x-text="r.status === 'po_created' ? 'PO Created' : 'Approved'"></span></td>
                                                <td class="px-4 py-2.5 font-mono text-slate-400" x-text="r.created_at?.substring(0,10)"></td>
                                                <td class="px-4 py-2.5 text-center" @click.stop><button @click="printApprovedReq(r)" class="p-1 rounded-lg bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:hover:bg-indigo-900/50 text-indigo-600 dark:text-indigo-400 transition-colors" title="Download PDF"><i data-lucide="download" class="w-3.5 h-3.5"></i></button></td>
                                            </tr>
                                            <!-- Expanded items -->
                                            <tr x-show="expandedApprovedId === r.id && expandedApprovedItems.length > 0" class="bg-emerald-50/30 dark:bg-slate-800/40">
                                                <td colspan="8" class="px-6 py-3">
                                                    <div class="text-[10px] font-bold uppercase text-slate-400 mb-2">Approved Items</div>
                                                    <table class="w-full text-xs">
                                                        <thead><tr class="text-slate-400"><th class="text-left pb-1">Item</th><th class="text-center pb-1">Qty</th><th class="text-right pb-1">Unit Price</th><th class="text-right pb-1">Total</th><th class="text-center pb-1">Status</th></tr></thead>
                                                        <tbody>
                                                            <template x-for="item in expandedApprovedItems" :key="item.id">
                                                                <tr class="border-t border-slate-200/50 dark:border-slate-700/50">
                                                                    <td class="py-1.5 font-semibold text-slate-700 dark:text-slate-200" x-text="item.product_name || item.description"></td>
                                                                    <td class="py-1.5 text-center text-slate-600 dark:text-slate-300" x-text="item.quantity"></td>
                                                                    <td class="py-1.5 text-right text-slate-600 dark:text-slate-300" x-text="fmt(item.unit_price)"></td>
                                                                    <td class="py-1.5 text-right font-bold text-emerald-600 dark:text-emerald-400" x-text="fmt(item.total_price)"></td>
                                                                    <td class="py-1.5 text-center"><span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold" :class="(item.status||'active')==='rejected'?'bg-red-100 dark:bg-red-900/40 text-red-600 dark:text-red-400':'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300'" x-text="item.status||'active'"></span></td>
                                                                </tr>
                                                            </template>
                                                        </tbody>
                                                    </table>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <div x-show="approvedReqs.length === 0" class="px-4 py-10 text-center text-slate-400 text-sm">No approved requisitions yet.</div>
                    </div>
                </div>

                <!-- Approval Flow Diagram -->
                <div class="mt-6 glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 p-6 shadow-lg">
                    <h4 class="font-bold text-sm text-slate-900 dark:text-white mb-4">Approval Workflow</h4>
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-2 text-xs"><div class="w-8 h-8 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center"><i data-lucide="send" class="w-4 h-4 text-amber-600"></i></div><span class="font-semibold text-amber-600">Submitted</span></div>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-slate-300"></i>
                        <div class="flex items-center gap-2 text-xs"><div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center"><i data-lucide="user-check" class="w-4 h-4 text-blue-600"></i></div><span class="font-semibold text-blue-600">HOD</span></div>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-slate-300"></i>
                        <div class="flex items-center gap-2 text-xs"><div class="w-8 h-8 rounded-full bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center"><i data-lucide="shield-check" class="w-4 h-4 text-violet-600"></i></div><span class="font-semibold text-violet-600">Audit</span></div>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-slate-300"></i>
                        <div class="flex items-center gap-2 text-xs"><div class="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center"><i data-lucide="crown" class="w-4 h-4 text-emerald-600"></i></div><span class="font-semibold text-emerald-600">CEO</span></div>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-slate-300"></i>
                        <div class="flex items-center gap-2 text-xs"><div class="w-8 h-8 rounded-full bg-cyan-100 dark:bg-cyan-900/30 flex items-center justify-center"><i data-lucide="file-check" class="w-4 h-4 text-cyan-600"></i></div><span class="font-semibold text-cyan-600">PO</span></div>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: Purchase Orders ========== -->
            <div x-show="currentTab === 'pos'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-cyan-600 flex items-center justify-center shadow-lg shadow-blue-500/30"><i data-lucide="file-check" class="w-4 h-4 text-white"></i></div>
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm">Purchase Orders</h3>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-slate-500" x-text="purchaseOrders.length + ' orders'"></span>
                            <button @click="printPOs()" class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-bold rounded-lg transition-all" title="Print / Save as PDF">
                                <i data-lucide="printer" class="w-3.5 h-3.5"></i> Print
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto"><table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">PO #</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Requisition</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Dept</th><th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Amount</th><th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Status</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Created By</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th><th class="px-4 py-3 text-center text-xs font-bold text-slate-500">PDF</th></tr></thead>
                        <tbody>
                            <template x-for="po in purchaseOrders" :key="po.id">
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-blue-50/50 dark:hover:bg-slate-800/30 cursor-pointer" @click="togglePOExpand(po.id)">
                                    <td class="px-4 py-3 font-mono text-xs font-bold text-blue-600 dark:text-blue-400" x-text="po.po_number"></td>
                                    <td class="px-4 py-3 font-mono text-xs text-indigo-500 dark:text-indigo-400" x-text="po.requisition_number"></td>
                                    <td class="px-4 py-3 text-xs text-slate-700 dark:text-slate-300" x-text="po.department || '—'"></td>
                                    <td class="px-4 py-3 text-right font-bold text-slate-800 dark:text-white" x-text="fmt(po.total_amount)"></td>
                                    <td class="px-4 py-3 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="po.status==='delivered'?'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300':'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300'" x-text="po.status"></span></td>
                                    <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-300" x-text="po.first_name + ' ' + po.last_name"></td>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400" x-text="po.created_at?.substring(0,10)"></td>
                                    <td class="px-4 py-3 text-center" @click.stop><button @click="printPO(po)" class="p-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/30 dark:hover:bg-blue-900/50 text-blue-600 dark:text-blue-400 transition-colors" title="Download PO as PDF"><i data-lucide="file-down" class="w-3.5 h-3.5"></i></button></td>
                                </tr>
                                <!-- Expanded PO items -->
                                <tr x-show="expandedPOId === po.id" class="bg-blue-50/30 dark:bg-slate-800/50">
                                    <td colspan="7" class="px-6 py-3">
                                        <div x-show="poItems.length === 0" class="text-xs text-slate-400 py-2">Loading items...</div>
                                        <table class="w-full text-xs" x-show="poItems.length > 0">
                                            <thead><tr class="text-slate-400 text-[10px]"><th class="text-left pb-1">Item</th><th class="pb-1 text-center">Qty (Approved)</th><th class="pb-1 text-right">Unit Price</th><th class="pb-1 text-right">Total</th><th class="pb-1 text-center">Item Status</th></tr></thead>
                                            <tbody>
                                                <template x-for="item in poItems" :key="item.id">
                                                    <tr class="border-t border-slate-100 dark:border-slate-700/40">
                                                        <td class="py-1.5 font-semibold" x-text="item.product_name || item.description"></td>
                                                        <td class="py-1.5 text-center" x-text="item.quantity"></td>
                                                        <td class="py-1.5 text-right" x-text="fmt(item.unit_price)"></td>
                                                        <td class="py-1.5 text-right font-bold text-blue-600" x-text="fmt(item.total_price)"></td>
                                                        <td class="py-1.5 text-center"><span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold" :class="item.status==='rejected'?'bg-red-100 text-red-600':'bg-emerald-100 text-emerald-700'" x-text="item.status||'active'"></span></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="purchaseOrders.length === 0"><td colspan="7" class="px-4 py-12 text-center text-slate-400">No purchase orders yet. POs are auto-created when a CEO/MD approves a requisition.</td></tr>
                        </tbody>
                    </table></div>
                </div>
            </div>

            <!-- ========== TAB: Verification ========== -->
            <div x-show="currentTab === 'verification'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500/10 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/30"><i data-lucide="clipboard-check" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Delivery Verification</h3><p class="text-xs text-slate-500">Confirm received items from purchase orders</p></div>
                        </div>
                    </div>
                    <div class="p-5">
                        <p class="text-[10px] text-slate-400 mb-4">Select a Purchase Order to verify its delivered items. Enter the actual received qty/price for each item. The system will calculate variances.</p>
                        <!-- PO Selector -->
                        <div class="mb-4">
                            <select @change="loadVerificationItems($event.target.value)" class="w-full max-w-md px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                <option value="">— Select Purchase Order —</option>
                                <template x-for="po in purchaseOrders" :key="po.id">
                                    <option :value="po.requisition_id" x-text="po.po_number + ' — ' + po.requisition_number + ' (₦' + parseFloat(po.total_amount||0).toLocaleString() + ') — ' + (po.created_at||'').substring(0,10)"></option>
                                </template>
                            </select>
                        </div>

                        <!-- Items Verification Table -->
                        <div x-show="verifyItems.length > 0" class="border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                                        <tr>
                                            <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-10">✓</th>
                                            <th class="px-3 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Item</th>
                                            <th colspan="3" class="px-3 py-2 text-center text-[10px] font-bold text-blue-600 dark:text-blue-300 uppercase bg-blue-50/50 dark:bg-blue-900/30 border-x border-blue-100 dark:border-blue-800/40">Approved (PO)</th>
                                            <th colspan="3" class="px-3 py-2 text-center text-[10px] font-bold text-emerald-600 dark:text-emerald-300 uppercase bg-emerald-50/50 dark:bg-emerald-900/30 border-x border-emerald-100 dark:border-emerald-800/40">Received (Supervisor)</th>
                                            <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-36">Per-Item Variance</th>
                                            <th class="px-3 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Note / Status</th>
                                        </tr>
                                        <tr class="bg-slate-50/50 dark:bg-slate-800/50">
                                            <th></th><th></th>
                                            <th class="px-2 py-1 text-center text-[9px] text-blue-500 dark:text-blue-300 bg-blue-50/50 dark:bg-blue-900/20">Qty</th>
                                            <th class="px-2 py-1 text-right text-[9px] text-blue-500 dark:text-blue-300 bg-blue-50/50 dark:bg-blue-900/20">Unit</th>
                                            <th class="px-2 py-1 text-right text-[9px] text-blue-500 dark:text-blue-300 bg-blue-50/50 dark:bg-blue-900/20">Total</th>
                                            <th class="px-2 py-1 text-center text-[9px] text-emerald-500 dark:text-emerald-300 bg-emerald-50/50 dark:bg-emerald-900/20">Qty</th>
                                            <th class="px-2 py-1 text-right text-[9px] text-emerald-500 dark:text-emerald-300 bg-emerald-50/50 dark:bg-emerald-900/20">Unit</th>
                                            <th class="px-2 py-1 text-right text-[9px] text-emerald-500 dark:text-emerald-300 bg-emerald-50/50 dark:bg-emerald-900/20">Total</th>
                                            <th></th><th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(item, idx) in verifyItems" :key="item.id">
                                            <tr class="border-t border-slate-100 dark:border-slate-800 transition-colors"
                                                :class="item.verified_at ? 'bg-emerald-50/30 dark:bg-emerald-900/10' : (item.checked ? 'bg-yellow-50/30' : '')">
                                                <td class="px-3 py-2 text-center">
                                                    <input type="checkbox" x-model="item.checked" :disabled="!!item.verified_at" class="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                                </td>
                                                <td class="px-3 py-2 font-semibold text-xs" x-text="item.product_name || item.description"></td>
                                                <!-- Approved columns -->
                                                <td class="px-2 py-2 text-center text-xs bg-blue-50/20 dark:bg-blue-900/10" x-text="item.quantity"></td>
                                                <td class="px-2 py-2 text-right text-xs bg-blue-50/20 dark:bg-blue-900/10" x-text="fmt(item.actual_unit_price || item.unit_price)"></td>
                                                <td class="px-2 py-2 text-right text-xs font-bold text-blue-600 dark:text-blue-400 bg-blue-50/20 dark:bg-blue-900/10" x-text="fmt((item.actual_unit_price || item.unit_price) * item.quantity)"></td>
                                                <!-- Received columns (editable if not yet verified) -->
                                                <td class="px-1 py-1 bg-emerald-50/20 dark:bg-emerald-900/10">
                                                    <template x-if="!item.verified_at">
                                                        <input type="number" step="1" min="0" x-model.number="item.received_qty" @input="item.received_total = (item.received_qty||0) * (item.received_unit_price||0)" class="w-16 px-1.5 py-1 bg-white dark:bg-slate-900 border border-emerald-200 dark:border-emerald-700 rounded text-xs text-center font-bold focus:ring-1 focus:ring-emerald-400">
                                                    </template>
                                                    <template x-if="item.verified_at">
                                                        <span class="text-xs text-center block" x-text="item.received_qty"></span>
                                                    </template>
                                                </td>
                                                <td class="px-1 py-1 bg-emerald-50/20 dark:bg-emerald-900/10">
                                                    <template x-if="!item.verified_at">
                                                        <input type="number" step="0.01" min="0" x-model.number="item.received_unit_price" @input="item.received_total = (item.received_qty||0) * (item.received_unit_price||0)" class="w-24 px-1.5 py-1 bg-white dark:bg-slate-900 border border-emerald-200 dark:border-emerald-700 rounded text-xs text-right font-bold focus:ring-1 focus:ring-emerald-400">
                                                    </template>
                                                    <template x-if="item.verified_at">
                                                        <span class="text-xs text-right block" x-text="fmt(item.received_unit_price)"></span>
                                                    </template>
                                                </td>
                                                <td class="px-2 py-2 text-right text-xs font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50/20 dark:bg-emerald-900/10" x-text="fmt((item.received_qty||0) * (item.received_unit_price||0))"></td>
                                                <!-- Variance -->
                                                <td class="px-2 py-2 text-center text-xs font-bold"
                                                    :class="(() => { const appr = (item.actual_unit_price || item.unit_price) * item.quantity; const rcv = (item.received_qty||0) * (item.received_unit_price||0); return rcv > appr ? 'text-red-600' : rcv < appr ? 'text-amber-600' : 'text-emerald-600'; })()"
                                                    x-text="(() => { const appr = (item.actual_unit_price || item.unit_price) * item.quantity; const rcv = (item.received_qty||0) * (item.received_unit_price||0); const diff = rcv - appr; return diff === 0 ? 'Matched' : fmt(Math.abs(diff)) + (diff > 0 ? ' OVER' : ' UNDER'); })()">
                                                </td>
                                                <!-- Note -->
                                                <td class="px-1 py-1">
                                                    <template x-if="!item.verified_at">
                                                        <input type="text" x-model="item.verification_note" placeholder="Note..." class="w-full px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 rounded text-xs focus:ring-1 focus:ring-slate-300">
                                                    </template>
                                                    <template x-if="item.verified_at">
                                                        <div class="text-[10px]">
                                                            <span class="text-emerald-600 font-semibold" x-text="(item.verifier_name || 'Verified') + ' · ' + item.verified_at?.substring(0,16).replace('T',' ')"></span>
                                                            <span x-show="item.verification_note" class="block text-slate-500 mt-0.5" x-text="item.verification_note"></span>
                                                        </div>
                                                    </template>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- ═══ Variance Summary Panel ═══ -->
                        <div x-show="verifyItems.length > 0" class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                            <!-- Approved Total -->
                            <div class="rounded-xl border border-blue-200 bg-gradient-to-br from-blue-50 to-blue-100/30 dark:from-blue-900/20 dark:to-blue-900/10 dark:border-blue-800 p-4">
                                <div class="text-[10px] font-bold uppercase text-blue-400 mb-1">Approved (PO) Total</div>
                                <div class="text-lg font-black text-blue-700 dark:text-blue-300" x-text="fmt(verifyItems.reduce((s,i) => s + ((i.actual_unit_price || i.unit_price) * i.quantity), 0))"></div>
                                <div class="text-[10px] text-blue-400 mt-1" x-text="verifyItems.length + ' line items'"></div>
                            </div>
                            <!-- Received Total -->
                            <div class="rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-emerald-100/30 dark:from-emerald-900/20 dark:to-emerald-900/10 dark:border-emerald-800 p-4">
                                <div class="text-[10px] font-bold uppercase text-emerald-400 mb-1">Received Total</div>
                                <div class="text-lg font-black text-emerald-700 dark:text-emerald-300" x-text="fmt(verifyItems.reduce((s,i) => s + ((i.received_qty||0) * (i.received_unit_price||0)), 0))"></div>
                                <div class="text-[10px] text-emerald-400 mt-1" x-text="verifyItems.filter(i => (i.received_qty||0) > 0).length + ' items entered'"></div>
                            </div>
                            <!-- Variance / Who Owes Who -->
                            <div class="rounded-xl border p-4" :class="(() => {
                                const appr = verifyItems.reduce((s,i) => s + ((i.actual_unit_price || i.unit_price) * i.quantity), 0);
                                const rcv = verifyItems.reduce((s,i) => s + ((i.received_qty||0) * (i.received_unit_price||0)), 0);
                                const diff = rcv - appr;
                                return diff > 0 ? 'border-red-200 bg-gradient-to-br from-red-50 to-red-100/30 dark:from-red-900/20 dark:to-red-900/10 dark:border-red-800' : diff < 0 ? 'border-amber-200 bg-gradient-to-br from-amber-50 to-amber-100/30 dark:from-amber-900/20 dark:to-amber-900/10 dark:border-amber-800' : 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-emerald-100/30 dark:from-emerald-900/20 dark:to-emerald-900/10 dark:border-emerald-800';
                            })()">
                                <div class="text-[10px] font-bold uppercase mb-1" :class="(() => {
                                    const diff = verifyItems.reduce((s,i) => s + ((i.received_qty||0)*(i.received_unit_price||0)), 0) - verifyItems.reduce((s,i) => s + ((i.actual_unit_price||i.unit_price)*i.quantity), 0);
                                    return diff > 0 ? 'text-red-400' : diff < 0 ? 'text-amber-400' : 'text-emerald-400';
                                })()" x-text="(() => {
                                    const diff = verifyItems.reduce((s,i) => s + ((i.received_qty||0)*(i.received_unit_price||0)), 0) - verifyItems.reduce((s,i) => s + ((i.actual_unit_price||i.unit_price)*i.quantity), 0);
                                    return diff > 0 ? 'Company Owes Supplier' : diff < 0 ? 'Supplier Owes Company' : 'No Variance';
                                })()"></div>
                                <div class="text-lg font-black" :class="(() => {
                                    const diff = verifyItems.reduce((s,i) => s + ((i.received_qty||0)*(i.received_unit_price||0)), 0) - verifyItems.reduce((s,i) => s + ((i.actual_unit_price||i.unit_price)*i.quantity), 0);
                                    return diff > 0 ? 'text-red-700 dark:text-red-300' : diff < 0 ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300';
                                })()" x-text="(() => {
                                    const diff = verifyItems.reduce((s,i) => s + ((i.received_qty||0)*(i.received_unit_price||0)), 0) - verifyItems.reduce((s,i) => s + ((i.actual_unit_price||i.unit_price)*i.quantity), 0);
                                    return diff === 0 ? '₦0.00 — Balanced' : fmt(Math.abs(diff));
                                })()"></div>
                                <div class="text-[10px] mt-1" :class="(() => {
                                    const diff = verifyItems.reduce((s,i) => s + ((i.received_qty||0)*(i.received_unit_price||0)), 0) - verifyItems.reduce((s,i) => s + ((i.actual_unit_price||i.unit_price)*i.quantity), 0);
                                    return diff > 0 ? 'text-red-400' : diff < 0 ? 'text-amber-400' : 'text-emerald-400';
                                })()" x-text="(() => {
                                    const appr = verifyItems.reduce((s,i) => s + ((i.actual_unit_price||i.unit_price)*i.quantity), 0);
                                    const rcv = verifyItems.reduce((s,i) => s + ((i.received_qty||0)*(i.received_unit_price||0)), 0);
                                    const diff = rcv - appr;
                                    if (diff === 0) return 'Received matches approved amount exactly';
                                    if (diff > 0) return 'Supplier delivered more than approved — company pays extra ' + fmt(diff);
                                    return 'Supplier delivered less than approved — refund/credit of ' + fmt(Math.abs(diff)) + ' due';
                                })()"></div>
                            </div>
                        </div>

                        <!-- Action Bar -->
                        <div x-show="verifyItems.length > 0" class="mt-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <p class="text-xs text-slate-400">
                                <span class="font-bold text-emerald-600" x-text="verifyItems.filter(i => i.checked || i.verified_at).length"></span> / <span x-text="verifyItems.length"></span> items verified
                            </p>
                            <div class="flex items-center gap-3">
                                <select x-model="verifyRoute" class="text-xs px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-emerald-500/20 font-medium">
                                    <option value="none">Do not route stock</option>
                                    <option value="main_store">Route to Main Store</option>
                                    <option value="pnl">Route to P&L Purchases</option>
                                </select>
                                <button @click="printVerification()" class="px-4 py-2.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all whitespace-nowrap"><i data-lucide="file-down" class="w-3.5 h-3.5 inline-block mr-1 -mt-0.5"></i> Download Report</button>
                                <button @click="saveVerification()" :disabled="verifyItems.filter(i => i.checked && !i.verified_at && (i.received_qty||0) > 0).length === 0" class="px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap">✓ Confirm Delivery</button>
                            </div>
                        </div>
                        <div x-show="verifyItems.length === 0 && currentTab === 'verification'" class="text-center py-12">
                            <i data-lucide="clipboard-check" class="w-12 h-12 mx-auto text-slate-300 mb-3"></i>
                            <p class="text-sm font-semibold text-slate-500">Select a Purchase Order above to verify delivered items</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: Reports ========== -->
            <div x-show="currentTab === 'reports'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-indigo-500/10 to-transparent flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg"><i data-lucide="bar-chart-2" class="w-4 h-4 text-white"></i></div>
                            <div>
                                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Requisition Analytics</h3>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="date" x-model="reportFilter.date_from" @change="loadReportData()" class="text-xs px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg">
                            <span class="text-xs text-slate-400">to</span>
                            <input type="date" x-model="reportFilter.date_to" @change="loadReportData()" class="text-xs px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg">
                            <select x-model="reportFilter.department" @change="loadReportData()" class="text-xs px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg">
                                <option value="">All Departments</option>
                                <option value="Main Store">Main Store</option>
                                <template x-for="dept in clientDepartments" :key="dept.id"><option :value="dept.name" x-text="dept.name"></option></template>
                            </select>
                            <button @click="printReportData()" class="flex items-center gap-1.5 px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-bold rounded-lg transition-all"><i data-lucide="printer" class="w-3.5 h-3.5"></i> Export PDF</button>
                        </div>
                    </div>
                    
                    <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="p-4 rounded-xl border border-slate-100 dark:border-slate-800 bg-white/50 dark:bg-slate-900/50 flex flex-col justify-between">
                            <div class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Total Req. Drafts</div>
                            <div class="flex items-end justify-between">
                                <span class="text-2xl font-black text-slate-700 dark:text-white" x-text="reportData.total_reqs"></span>
                                <span class="text-sm font-bold text-slate-500" x-text="fmt(reportData.total_reqs_val)"></span>
                            </div>
                        </div>
                        <div class="p-4 rounded-xl border border-indigo-100 dark:border-indigo-900/30 bg-indigo-50/50 dark:bg-indigo-900/10 flex flex-col justify-between">
                            <div class="text-xs font-bold text-indigo-500 uppercase tracking-wider mb-2">Total Approved</div>
                            <div class="flex items-end justify-between">
                                <span class="text-2xl font-black text-indigo-700 dark:text-indigo-400" x-text="reportData.approved_cnt"></span>
                                <span class="text-sm font-bold text-indigo-600 dark:text-indigo-300" x-text="fmt(reportData.approved_val)"></span>
                            </div>
                        </div>
                        <div class="p-4 rounded-xl border border-cyan-100 dark:border-cyan-900/30 bg-cyan-50/50 dark:bg-cyan-900/10 flex flex-col justify-between">
                            <div class="text-xs font-bold text-cyan-500 uppercase tracking-wider mb-2">Total Ordered (PO)</div>
                            <div class="flex items-end justify-between">
                                <span class="text-2xl font-black text-cyan-700 dark:text-cyan-400" x-text="reportData.po_cnt"></span>
                                <span class="text-sm font-bold text-cyan-600 dark:text-cyan-300" x-text="fmt(reportData.po_val)"></span>
                            </div>
                        </div>
                        <div class="p-4 rounded-xl border border-emerald-100 dark:border-emerald-900/30 bg-emerald-50/50 dark:bg-emerald-900/10 flex flex-col justify-between">
                            <div class="text-xs font-bold text-emerald-500 uppercase tracking-wider mb-2">Total Verified Items</div>
                            <div class="flex items-end justify-between">
                                <div><span class="text-2xl font-black text-emerald-700 dark:text-emerald-400" x-text="fmt(reportData.verified_val)"></span></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="px-6 pb-6">
                        <div class="p-4 rounded-xl bg-gradient-to-r" :class="reportData.variance_val > 0 ? 'from-amber-50 to-orange-50 dark:from-amber-900/20 dark:to-orange-900/20 border border-amber-200 dark:border-amber-800' : 'from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-900 border border-slate-200 dark:border-slate-700'">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="text-sm font-bold mb-1" :class="reportData.variance_val > 0 ? 'text-amber-800 dark:text-amber-400' : 'text-slate-700 dark:text-slate-300'">Value Variance (Approved vs Verified)</h4>
                                    <p class="text-xs opacity-80" :class="reportData.variance_val > 0 ? 'text-amber-700 dark:text-amber-500' : 'text-slate-500'">Value of approved requisitions that have not yet been fully received.</p>
                                </div>
                                <div class="text-right">
                                    <div class="text-xl font-black" :class="reportData.variance_val > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-600 dark:text-slate-400'" x-text="fmt(reportData.variance_val)"></div>
                                </div>
                            </div>
                        </div>
                    </div>

            <!-- ========== Charts Row ========== -->
            <div x-show="currentTab === 'reports'" class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Status Doughnut -->
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg p-5">
                    <h4 class="font-bold text-sm text-slate-700 dark:text-white mb-4 flex items-center gap-2"><i data-lucide="pie-chart" class="w-4 h-4 text-indigo-500"></i> Requisition Status Breakdown</h4>
                    <div class="flex items-center justify-center" style="height:220px">
                        <canvas id="reqStatusChart"></canvas>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 justify-center" id="reqStatusLegend"></div>
                </div>
                <!-- Dept Bar -->
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg p-5">
                    <h4 class="font-bold text-sm text-slate-700 dark:text-white mb-4 flex items-center gap-2"><i data-lucide="bar-chart-horizontal" class="w-4 h-4 text-emerald-500"></i> Top Departments by Value</h4>
                    <div style="height:220px">
                        <canvas id="reqDeptChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Breakdown Table -->
            <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-slate-700 to-slate-900 flex items-center justify-center"><i data-lucide="list" class="w-4 h-4 text-white"></i></div>
                    <h4 class="font-bold text-sm text-slate-700 dark:text-white">Requisition Breakdown (Recent 20)</h4>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/50">
                            <tr>
                                <th class="px-4 py-3 text-left font-bold text-slate-500 uppercase text-[10px]">Req #</th>
                                <th class="px-4 py-3 text-left font-bold text-slate-500 uppercase text-[10px]">Department</th>
                                <th class="px-4 py-3 text-left font-bold text-slate-500 uppercase text-[10px]">Purpose / Note</th>
                                <th class="px-4 py-3 text-left font-bold text-slate-500 uppercase text-[10px]">Requested By</th>
                                <th class="px-4 py-3 text-center font-bold text-slate-500 uppercase text-[10px]">Priority</th>
                                <th class="px-4 py-3 text-center font-bold text-slate-500 uppercase text-[10px]">Status</th>
                                <th class="px-4 py-3 text-right font-bold text-slate-500 uppercase text-[10px]">Amount</th>
                                <th class="px-4 py-3 text-left font-bold text-slate-500 uppercase text-[10px]">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="req in reportData.req_list ?? []" :key="req.requisition_number">
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-indigo-50/30 dark:hover:bg-slate-800/20 transition-colors">
                                    <td class="px-4 py-3 font-mono font-bold text-indigo-600" x-text="req.requisition_number"></td>
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-400" x-text="req.department || '—'"></td>
                                    <td class="px-4 py-3 text-slate-700 dark:text-slate-300 max-w-xs truncate" x-text="req.purpose"></td>
                                    <td class="px-4 py-3 text-slate-500" x-text="(req.first_name||'') + ' ' + (req.last_name||'')"></td>
                                    <td class="px-4 py-3 text-center"><span class="px-2 py-0.5 rounded-full text-[9px] font-bold capitalize" :class="getPriorityColor(req.priority)" x-text="req.priority"></span></td>
                                    <td class="px-4 py-3 text-center"><span class="px-2 py-0.5 rounded-full text-[9px] font-bold capitalize" :class="getStatusColor(req.status)" x-text="req.status?.replace(/_/g,' ')"></span></td>
                                    <td class="px-4 py-3 text-right font-bold text-slate-700 dark:text-white" x-text="fmt(req.total_amount)"></td>
                                    <td class="px-4 py-3 font-mono text-slate-400" x-text="req.created_at?.substring(0,10)"></td>
                                </tr>
                            </template>
                            <tr x-show="!(reportData.req_list ?? []).length"><td colspan="8" class="px-4 py-10 text-center text-slate-400">No requisitions found for the selected period.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>

        </main>
    </div>
</div>

<!-- Approval Review Modal -->
<div x-show="approvalModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="approvalModal = false">
    <div x-show="approvalModal" x-transition.scale.90 class="w-full max-w-3xl glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500/10 to-transparent flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg"><i data-lucide="check-circle" class="w-4 h-4 text-white"></i></div>
                <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Review & Approve Requisition</h3><p class="text-[10px] text-slate-500" x-text="approvalReq?.requisition_number"></p></div>
            </div>
            <button @click="approvalModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-slate-200 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
        </div>
        <div class="p-6 overflow-y-auto flex-1">
            <p class="text-[11px] text-slate-500 mb-4">Review the requested items. You can adjust the approved price or strike out (remove) items before finalizing your approval.</p>
            <table class="w-full text-sm mb-4">
                <thead><tr class="text-left text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-200 dark:border-slate-700 pb-2"><th class="pb-2 w-8 text-center">+/-</th><th class="pb-2">Item Description</th><th class="text-center pb-2">Qty</th><th class="text-right pb-2">Unit Price (₦)</th><th class="text-right pb-2">Total (₦)</th></tr></thead>
                <tbody>
                    <template x-for="(item, idx) in approvalItems" :key="item.id">
                        <tr class="border-b border-slate-100 dark:border-slate-800 transition-all" :class="item.status === 'rejected' ? 'opacity-50 grayscale bg-red-50/30' : ''">
                            <td class="py-2 text-center">
                                <button @click="item.status = item.status === 'rejected' ? 'active' : 'rejected'; $nextTick(() => lucide.createIcons())" class="w-6 h-6 rounded-full inline-flex items-center justify-center transition-colors shadow-sm" :class="item.status === 'rejected' ? 'bg-emerald-100 text-emerald-600 hover:bg-emerald-200' : 'bg-red-100 text-red-600 hover:bg-red-200'" :title="item.status === 'rejected' ? 'Restore Item' : 'Strike/Remove Item'">
                                    <i :data-lucide="item.status === 'rejected' ? 'rotate-ccw' : 'trash-2'" class="w-3 h-3"></i>
                                </button>
                            </td>
                            <td class="py-3 font-semibold text-xs" :class="item.status === 'rejected' ? 'line-through text-slate-400' : 'text-slate-700 dark:text-slate-200'">
                                <span x-text="item.product_name || item.description"></span>
                                <div x-show="item.initial_unit_price && parseFloat(item.unit_price) !== parseFloat(item.initial_unit_price)" class="mt-0.5 text-[9px] font-bold text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded inline-block" x-text="'Was: ' + fmt(item.initial_unit_price)"></div>
                            </td>
                            <td class="py-3 text-center">
                                <input type="number" step="0.01" x-model.number="item.quantity" :disabled="item.status === 'rejected'" class="w-16 px-2 py-1 mx-auto bg-white dark:bg-slate-900 border rounded text-xs text-center font-bold focus:ring-2 focus:ring-emerald-500/20 disabled:opacity-50 block" :class="item.status === 'rejected' ? 'border-transparent text-slate-400 line-through' : 'border-slate-200 dark:border-slate-700 text-slate-700'">
                                <div x-show="item.initial_quantity && parseFloat(item.quantity) !== parseFloat(item.initial_quantity)" class="mt-1 text-[9px] font-bold text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded inline-block" x-text="'Was: ' + item.initial_quantity"></div>
                            </td>
                            <td class="py-3 text-right">
                                <input type="number" step="0.01" x-model.number="item.unit_price" :disabled="item.status === 'rejected'" class="w-24 px-2 py-1 bg-white dark:bg-slate-900 border rounded text-xs text-right font-bold focus:ring-2 focus:ring-emerald-500/20 disabled:opacity-50" :class="item.status === 'rejected' ? 'border-transparent text-slate-400 line-through' : 'border-slate-200 dark:border-slate-700 text-slate-700'">
                            </td>
                            <td class="py-3 text-right font-bold text-xs" :class="item.status === 'rejected' ? 'line-through text-slate-400' : 'text-emerald-600'" x-text="fmt((item.unit_price||0) * item.quantity)"></td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/50">
                        <td colspan="4" class="py-3 px-4 text-right font-bold text-sm text-slate-700 dark:text-slate-200">New Grand Total:</td>
                        <td class="py-3 pr-2 text-right font-black text-emerald-600 text-base" x-text="fmt(approvalItems.filter(i=>i.status!=='rejected').reduce((s,i) => s + ((i.unit_price||0) * i.quantity), 0))"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/20 flex flex-wrap items-center justify-between gap-4 shrink-0">
            <div class="text-[10px] text-slate-400 flex items-center gap-1.5"><i data-lucide="info" class="w-3 h-3"></i> Only active items will be approved and passed to the next stage.</div>
            <div class="flex gap-3">
                <button @click="approvalModal = false" class="px-5 py-2.5 text-xs font-bold text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl transition-colors">Cancel</button>
                <button @click="submitApproval()" class="px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all flex items-center gap-2">
                    <i data-lucide="check-check" class="w-4 h-4"></i> Confirm & Approve Workflow
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Purchase Price Modal -->
<div x-show="priceModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="priceModal = false">
    <div x-show="priceModal" x-transition.scale.90 class="w-full max-w-2xl glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500/10 to-transparent flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg"><i data-lucide="receipt" class="w-4 h-4 text-white"></i></div>
                <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Actual Purchase Prices</h3><p class="text-[10px] text-slate-500" x-text="priceReq?.requisition_number"></p></div>
            </div>
            <button @click="priceModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-slate-200 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
        </div>
        <div class="p-6">
            <table class="w-full text-sm mb-4">
                <thead><tr class="text-xs text-slate-400"><th class="text-left pb-2">Item</th><th class="text-center pb-2">Qty</th><th class="text-right pb-2">Est. Price</th><th class="text-right pb-2">Actual Price (₦)</th><th class="text-right pb-2">Actual Total</th></tr></thead>
                <tbody>
                    <template x-for="(item, idx) in priceItems" :key="item.id">
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-2 font-semibold text-xs" x-text="item.product_name || item.description"></td>
                            <td class="py-2 text-center text-xs" x-text="item.quantity"></td>
                            <td class="py-2 text-right text-xs text-slate-500" x-text="fmt(item.unit_price)"></td>
                            <td class="py-2 text-right"><input type="number" step="0.01" x-model.number="item.actual_unit_price" class="w-28 px-2 py-1.5 bg-white dark:bg-slate-900 border border-emerald-300 dark:border-emerald-700 rounded-lg text-sm text-right font-bold text-emerald-700 focus:ring-2 focus:ring-emerald-500/20"></td>
                            <td class="py-2 text-right font-bold text-emerald-600 text-xs" x-text="fmt((item.actual_unit_price||0) * item.quantity)"></td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-300 dark:border-slate-600"><td colspan="4" class="py-2 text-right font-bold text-sm">Actual Grand Total:</td><td class="py-2 text-right font-black text-emerald-600" x-text="fmt(priceItems.reduce((s,i) => s + ((i.actual_unit_price||0) * i.quantity), 0))"></td></tr>
                </tfoot>
            </table>
            <div class="flex justify-end gap-3">
                <button @click="priceModal = false" class="px-4 py-2 text-sm font-bold text-slate-500">Cancel</button>
                <button @click="savePricesAndPO()" class="px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-sm font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">Save Prices & Create PO</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function reqApp() {
    return {
        currentTab: (location.hash.slice(1) || 'requisitions'), statusFilter: '', showMineOnly: false, showForm: false,
        expandedId: null, expandedItems: [],
        priceModal: false, priceReq: null, priceItems: [],
        approvalModal: false, approvalReq: null, approvalItems: [],
        userId: <?= $user_id ?>, userRole: '<?= $user_role ?>',
        tabs: [
            { id: 'requisitions', label: 'Requisitions', icon: 'file-plus' },
            { id: 'approvals', label: 'Approvals', icon: 'check-square' },
            { id: 'pos', label: 'Purchase Orders', icon: 'file-check' },
            { id: 'verification', label: 'Verification', icon: 'clipboard-check' },
            { id: 'reports', label: 'Reports', icon: 'bar-chart-2' },
        ],
        companyName: <?= $js_company_name ?>,
        clientName: <?= $js_client_name ?>,
        products: <?= $js_products ?>,
        clientDepartments: <?= $js_client_depts ?>,
        myReqs: <?= $js_my_reqs ?>,
        allReqs: <?= $js_all_reqs ?>,
        pendingApprovals: <?= $js_pending ?>,
        approvedReqs: <?= $js_approved ?>,
        purchaseOrders: <?= $js_pos ?>,
        reqForm: { department:'', purpose:'', priority:'medium', items:[{product_id:'', description:'', quantity:1, unit_price:0, old_price:null, source:'', source_label:''}] },
        verifyItems: [], verifyReqId: null, verifyRoute: 'none',
        searchTerm: '', searchResults: [], showSearchResults: false, searchLoading: false,
        expandedPOId: null, poItems: [],
        expandedApprovedId: null, expandedApprovedItems: [],
        _approvedGroups: [],
        reportData: { total_reqs:0, total_reqs_val:0, approved_cnt:0, approved_val:0, po_cnt:0, po_val:0, verified_val:0, variance_val:0 },
        reportFilter: { date_from: new Date(new Date().setDate(new Date().getDate()-30)).toISOString().split('T')[0], date_to: new Date().toISOString().split('T')[0], department: '' },

        get reqFormTotal() { return this.reqForm.items.reduce((s,i) => s + ((i.quantity||0) * (i.unit_price || i.old_price || 0)), 0); },
        get displayReqs() {
            let list = this.showMineOnly ? this.myReqs : this.allReqs;
            if (this.statusFilter) list = list.filter(r => r.status === this.statusFilter);
            return list;
        },
        get monthlyReqs() {
            const groups = {};
            for (const r of this.displayReqs) {
                const d = r.created_at ? new Date(r.created_at) : new Date();
                const key = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
                const label = d.toLocaleString('en-US', {month:'long', year:'numeric'});
                if (!groups[key]) groups[key] = { month: label, key, open: false, reqs: [] };
                groups[key].reqs.push(r);
            }
            const sorted = Object.keys(groups).sort((a,b) => b.localeCompare(a)).map(k => groups[k]);
            if (sorted.length > 0) sorted[0].open = true; // latest month open by default
            return sorted;
        },
        get monthlyApprovedReqs() {
            const groups = {};
            for (const r of this.approvedReqs) {
                const d = r.created_at ? new Date(r.created_at) : new Date();
                const key = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
                const label = d.toLocaleString('en-US', {month:'long', year:'numeric'});
                if (!groups[key]) groups[key] = { month: label, key, open: true, reqs: [] };
                groups[key].reqs.push(r);
            }
            const sorted = Object.keys(groups).sort((a,b) => b.localeCompare(a)).map(k => groups[k]);
            return sorted;
        },
        buildApprovedGroups() {
            const oldOpen = {};
            this._approvedGroups.forEach(g => { oldOpen[g.key] = g.open; });
            const groups = {};
            for (const r of this.approvedReqs) {
                const d = r.created_at ? new Date(r.created_at) : new Date();
                const key = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
                const label = d.toLocaleString('en-US', {month:'long', year:'numeric'});
                if (!groups[key]) groups[key] = { month: label, key, open: oldOpen[key] !== undefined ? oldOpen[key] : true, reqs: [] };
                groups[key].reqs.push(r);
            }
            this._approvedGroups = Object.keys(groups).sort((a,b) => b.localeCompare(a)).map(k => groups[k]);
        },

        addLineItem() { this.reqForm.items.push({ product_id:'', description:'', quantity:1, unit_price:0, old_price:null, source:'', source_label:'' }); this.$nextTick(() => lucide.createIcons()); },
        resetForm() { this.reqForm = { department:'', purpose:'', priority:'medium', items:[{product_id:'', description:'', quantity:1, unit_price:0, old_price:null, source:'', source_label:''}] }; },
        autoFillProduct(line) {
            if (line.product_id === 'custom') {
                line.description = '';
                line.unit_price = 0;
                line.old_price = null;
                line.source = '';
                line.source_label = '';
                return;
            }
            if (line.product_id) {
                const p = this.products.find(p => p.id == line.product_id);
                if (p) { line.description = p.name; line.unit_price = parseFloat(p.unit_cost) || 0; }
            } else {
                line.description = '';
                line.unit_price = 0;
            }
        },
        fmt(v) { return '₦' + parseFloat(v||0).toLocaleString('en-NG',{minimumFractionDigits:2}); },
        init() {
            this.$watch('currentTab', (val) => { 
                location.hash = val; 
                setTimeout(() => lucide.createIcons(), 50); 
                if (val === 'reports') this.loadReportData();
            });
            window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (h && this.tabs.some(t => t.id === h)) this.currentTab = h; });
            if (this.currentTab === 'reports') this.loadReportData();
            this.buildApprovedGroups();
        },

        getPriorityColor(p) { return { low:'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200', medium:'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300', high:'bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300', urgent:'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300' }[p] || 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200'; },
        getStatusColor(s) { return { submitted:'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300', hod_approved:'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300', audit_approved:'bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300', ceo_approved:'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300', po_created:'bg-cyan-100 dark:bg-cyan-900/40 text-cyan-700 dark:text-cyan-300', rejected:'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300' }[s] || 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200'; },

        async toggleExpand(id) {
            if (this.expandedId === id) { this.expandedId = null; this.expandedItems = []; return; }
            this.expandedId = id;
            const fd = new FormData(); fd.append('action','get_items'); fd.append('requisition_id', id);
            try {
                const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
                this.expandedItems = r.success ? r.items : [];
            } catch(e) { this.expandedItems = []; }
        },
        async togglePOExpand(id) {
            if (this.expandedPOId === id) { this.expandedPOId = null; this.poItems = []; return; }
            this.expandedPOId = id;
            this.poItems = [];
            const po = this.purchaseOrders.find(p => p.id == id);
            if (!po) return;
            const fd = new FormData(); fd.append('action','get_items'); fd.append('requisition_id', po.requisition_id);
            try {
                const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
                this.poItems = r.success ? r.items : [];
            } catch(e) { this.poItems = []; }
        },
        async toggleApprovedExpand(id) {
            if (this.expandedApprovedId === id) { this.expandedApprovedId = null; this.expandedApprovedItems = []; return; }
            this.expandedApprovedId = id;
            this.expandedApprovedItems = [];
            const fd = new FormData(); fd.append('action','get_items'); fd.append('requisition_id', id);
            try {
                const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
                this.expandedApprovedItems = r.success ? r.items : [];
            } catch(e) { this.expandedApprovedItems = []; }
        },

        async createRequisition() {
            if (!this.reqForm.department || !this.reqForm.purpose) { alert('Department and Purpose are required'); return; }
            if (this.reqForm.items.length === 0 || !this.reqForm.items.some(i => i.description)) { alert('Add at least one item'); return; }
            const fd = new FormData(); fd.append('action','create');
            fd.append('department', this.reqForm.department);
            fd.append('purpose', this.reqForm.purpose);
            fd.append('priority', this.reqForm.priority);
            fd.append('items', JSON.stringify(this.reqForm.items));
            const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },

        async openApprovalModal(req) {
            this.approvalReq = req;
            const fd = new FormData(); fd.append('action','get_items'); fd.append('requisition_id', req.id);
            try {
                const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
                if (r.success) {
                    this.approvalItems = r.items.map(i => ({...i, status: i.status || 'active', unit_price: parseFloat(i.unit_price), initial_unit_price: parseFloat(i.initial_unit_price || i.unit_price), quantity: parseFloat(i.quantity), initial_quantity: parseFloat(i.initial_quantity || i.quantity) }));
                    this.approvalModal = true;
                    this.$nextTick(() => lucide.createIcons());
                } else alert(r.message);
            } catch(e) { alert('Error loading items'); }
        },
        async submitApproval() {
            if (!confirm('Finalize and approve this requisition processing?')) return;
            const fd = new FormData(); fd.append('action','approve'); fd.append('requisition_id', this.approvalReq.id);
            fd.append('items', JSON.stringify(this.approvalItems));
            const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                if (r.po_error) alert('Approved, but PO creation failed: ' + r.po_error);
                else if (r.po_number) alert('Approved! Purchase Order ' + r.po_number + ' has been auto-created.');
                location.reload();
            } else alert(r.message);
        },
        async approveReq(id) {
            if (!confirm('Approve this requisition?')) return;
            const fd = new FormData(); fd.append('action','approve'); fd.append('requisition_id',id);
            const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                if (r.po_error) alert('Approved, but PO creation failed: ' + r.po_error);
                else if (r.po_number) alert('Approved! Purchase Order ' + r.po_number + ' has been auto-created.');
                location.reload();
            } else alert(r.message);
        },
        async rejectReq(id) {
            const reason = prompt('Rejection reason:');
            if (!reason) return;
            const fd = new FormData(); fd.append('action','reject'); fd.append('requisition_id',id); fd.append('reason',reason);
            const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        async convertToPO(id) {
            if (!confirm('Convert to Purchase Order?')) return;
            const fd = new FormData(); fd.append('action','convert_to_po'); fd.append('requisition_id',id);
            const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) { alert('Created ' + r.po_number); location.reload(); } else alert(r.message);
        },
        async deleteReq(id) {
            if (!confirm('Delete this requisition?')) return;
            const fd = new FormData(); fd.append('action','delete'); fd.append('requisition_id',id);
            const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },

        async openPriceModal(req) {
            this.priceReq = req;
            const fd = new FormData(); fd.append('action','get_items'); fd.append('requisition_id', req.id);
            const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                this.priceItems = r.items.map(i => ({...i, actual_unit_price: parseFloat(i.actual_unit_price) || parseFloat(i.unit_price) || 0}));
                this.priceModal = true;
                this.$nextTick(() => lucide.createIcons());
            }
        },
        printPOs() {
            printReport({
                title: 'Purchase Orders',
                subtitle: this.purchaseOrders.length + ' orders',
                orientation: 'landscape',
                columns: [
                    { label: 'PO #', key: 'po_number', bold: true },
                    { label: 'Requisition', key: 'requisition_number' },
                    { label: 'Dept', key: 'department' },
                    { label: 'Amount', key: 'total_amount', align: 'right', fmt: v => _pFmt(v) },
                    { label: 'Status', key: 'status' },
                    { label: 'Created By', key: '_created_by' },
                    { label: 'Date', key: '_date' },
                ],
                rows: this.purchaseOrders.map(po => ({
                    ...po,
                    department: po.department || '—',
                    _created_by: po.first_name + ' ' + po.last_name,
                    _date: (po.created_at || '').substring(0, 10),
                })),
                footer: `<td colspan="3" style="text-align:right;font-weight:800;">Total:</td><td style="text-align:right;font-weight:900;">${_pFmt(this.purchaseOrders.reduce((s,po) => s + parseFloat(po.total_amount||0), 0))}</td><td colspan="3"></td>`,
            });
        },
        async savePricesAndPO() {
            if (!this.priceReq) return;
            if (this.priceItems.some(i => !i.actual_unit_price || i.actual_unit_price <= 0)) { alert('Please fill all actual prices'); return; }
            const prices = this.priceItems.map(i => ({ item_id: i.id, actual_unit_price: i.actual_unit_price }));
            // Save prices only — PO was already auto-created on CEO approval
            let fd = new FormData(); fd.append('action','update_purchase_prices'); fd.append('requisition_id', this.priceReq.id); fd.append('prices', JSON.stringify(prices));
            let r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) { alert('Purchase prices updated!'); location.reload(); } else alert(r.message);
        },

        // === PDF / Print Functions ===
        printRequisitions() {
            printReport({
                title: 'Requisitions Report',
                subtitle: 'All Requisitions — ' + this.displayReqs.length + ' records',
                companyName: this.companyName,
                orientation: 'landscape',
                columns: [
                    { label: 'Req #', key: 'requisition_number', bold: true },
                    { label: 'Requestor', key: '_requestor' },
                    { label: 'Dept', key: 'department' },
                    { label: 'Purpose', key: 'purpose' },
                    { label: 'Amount', key: 'total_amount', align: 'right', fmt: v => _pFmt(v) },
                    { label: 'Priority', key: 'priority' },
                    { label: 'Status', key: '_status' },
                    { label: 'Date', key: '_date' },
                ],
                rows: this.displayReqs.map(r => ({
                    ...r,
                    _requestor: r.first_name + ' ' + r.last_name,
                    _status: (r.status || '').replace(/_/g, ' '),
                    _date: (r.created_at || '').substring(0, 10),
                })),
                footer: `<td colspan="4" style="text-align:right;font-weight:800;">Total:</td><td style="text-align:right;font-weight:900;">${_pFmt(this.displayReqs.reduce((s,r) => s + parseFloat(r.total_amount||0), 0))}</td><td colspan="3"></td>`,
            });
        },
        printApprovals() {
            printReport({
                title: 'Pending Approvals',
                subtitle: this.pendingApprovals.length + ' awaiting action',
                companyName: this.companyName,
                orientation: 'landscape',
                columns: [
                    { label: 'Req #', key: 'requisition_number', bold: true },
                    { label: 'Requestor', key: '_requestor' },
                    { label: 'Dept', key: 'department' },
                    { label: 'Purpose', key: 'purpose' },
                    { label: 'Amount', key: 'total_amount', align: 'right', fmt: v => _pFmt(v) },
                    { label: 'Priority', key: 'priority' },
                    { label: 'Status', key: '_status' },
                    { label: 'Date', key: '_date' },
                ],
                rows: this.pendingApprovals.map(r => ({
                    ...r,
                    _requestor: r.first_name + ' ' + r.last_name,
                    _status: (r.status || '').replace(/_/g, ' '),
                    _date: (r.created_at || '').substring(0, 10),
                })),
                footer: `<td colspan="4" style="text-align:right;font-weight:800;">Total:</td><td style="text-align:right;font-weight:900;">${_pFmt(this.pendingApprovals.reduce((s,r) => s + parseFloat(r.total_amount||0), 0))}</td><td colspan="3"></td>`,
            });
        },

        // === Verification Functions ===
        async loadVerificationItems(reqId) {
            if (!reqId) { this.verifyItems = []; this.verifyReqId = null; return; }
            this.verifyReqId = reqId;
            const fd = new FormData(); fd.append('action','get_verification_items'); fd.append('requisition_id', reqId);
            try {
                const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
                if (r.success) {
                    this.verifyItems = r.items.map(i => ({
                        ...i,
                        checked: !!i.verified_at,
                        received_qty: i.received_qty !== null ? parseFloat(i.received_qty) : parseFloat(i.quantity),
                        received_unit_price: i.received_unit_price !== null ? parseFloat(i.received_unit_price) : parseFloat(i.actual_unit_price || i.unit_price),
                        received_total: i.received_total !== null ? parseFloat(i.received_total) : 0,
                        verification_note: i.verification_note || ''
                    }));
                    this.$nextTick(() => lucide.createIcons());
                }
            } catch(e) { this.verifyItems = []; }
        },
        async saveVerification() {
            const toVerify = this.verifyItems.filter(i => i.checked && !i.verified_at && (i.received_qty || 0) > 0);
            if (toVerify.length === 0) { alert('No items to verify. Check items and enter received qty/price.'); return; }
            if (!confirm('Confirm delivery of ' + toVerify.length + ' item(s)?')) return;
            const itemsData = toVerify.map(i => ({
                id: i.id,
                received_qty: i.received_qty || 0,
                received_unit_price: i.received_unit_price || 0,
                verification_note: i.verification_note || ''
            }));
            const fd = new FormData(); fd.append('action','verify_delivery');
            fd.append('requisition_id', this.verifyReqId);
            fd.append('items_data', JSON.stringify(itemsData));
            fd.append('route_to', this.verifyRoute);
            const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) { 
                alert('Delivery verified!' + (r.routed && r.routed !== 'none' ? ' Stock routed successfully.' : '')); 
                this.loadVerificationItems(this.verifyReqId); 
            } else alert(r.message);
        },
        printVerification() {
            if (!this.verifyItems.length) return;
            const po = this.purchaseOrders.find(p => p.requisition_id == this.verifyReqId);
            const poLabel = po ? po.po_number + ' — ' + po.requisition_number : 'PO';
            const approvedTotal = this.verifyItems.reduce((s,i) => s + ((parseFloat(i.actual_unit_price) || parseFloat(i.unit_price)) * parseFloat(i.quantity)), 0);
            const receivedTotal = this.verifyItems.reduce((s,i) => s + ((i.received_qty||0) * (i.received_unit_price||0)), 0);
            const variance = receivedTotal - approvedTotal;
            const oweLabel = variance > 0 ? 'Company Owes Supplier' : variance < 0 ? 'Supplier Owes Company' : 'No Variance — Balanced';

            printReport({
                title: 'Delivery Verification Report',
                subtitle: `${this.clientName}  |  ${poLabel}  |  Date: ${new Date().toISOString().substring(0,10)}`,
                companyName: this.companyName,
                orientation: 'landscape',
                columns: [
                    { label: 'Item', key: 'name', bold: true },
                    { label: 'Appr Qty', key: 'app_qty', align: 'center' },
                    { label: 'Appr Unit', key: 'app_unit', align: 'right' },
                    { label: 'Appr Total', key: 'app_total', align: 'right' },
                    { label: 'Rcvd Qty', key: 'rcv_qty', align: 'center' },
                    { label: 'Rcvd Unit', key: 'rcv_unit', align: 'right' },
                    { label: 'Rcvd Total', key: 'rcv_total', align: 'right' },
                    { label: 'Variance', key: 'var_label', align: 'center' },
                    { label: 'Note', key: 'note' },
                ],
                rows: this.verifyItems.map(i => {
                    const aPrice = parseFloat(i.actual_unit_price) || parseFloat(i.unit_price);
                    const aTotal = aPrice * parseFloat(i.quantity);
                    const rQty = i.received_qty || 0;
                    const rPrice = i.received_unit_price || 0;
                    const rTotal = rQty * rPrice;
                    const diff = rTotal - aTotal;
                    return {
                        name: i.product_name || i.description,
                        app_qty: i.quantity,
                        app_unit: _pFmt(aPrice),
                        app_total: _pFmt(aTotal),
                        rcv_qty: rQty,
                        rcv_unit: _pFmt(rPrice),
                        rcv_total: _pFmt(rTotal),
                        var_label: diff === 0 ? 'Matched' : _pFmt(Math.abs(diff)) + (diff > 0 ? ' OVER' : ' UNDER'),
                        note: (i.verification_note || '') + (i.verified_at ? ' [Verified ' + i.verified_at.substring(0,16).replace('T',' ') + ']' : ''),
                    };
                }),
                footer: `<td colspan="3" style="text-align:right;font-weight:800;">Approved Total:</td><td style="text-align:right;font-weight:900;">${_pFmt(approvedTotal)}</td><td colspan="2" style="text-align:right;font-weight:800;">Received Total:</td><td style="text-align:right;font-weight:900;">${_pFmt(receivedTotal)}</td><td style="text-align:center;font-weight:900;color:${variance > 0 ? '#dc2626' : variance < 0 ? '#d97706' : '#059669'}">${oweLabel}: ${_pFmt(Math.abs(variance))}</td><td></td>`,
            });
        },
        async searchItems() {
            if (this.searchTerm.length < 2) { this.searchResults = []; this.showSearchResults = false; return; }
            this.searchLoading = true;
            try {
                const fd = new FormData(); fd.append('action','search_items'); fd.append('term', this.searchTerm);
                const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
                if (r.success) {
                    this.searchResults = r.items;
                    this.showSearchResults = this.searchResults.length > 0;
                    this.$nextTick(() => lucide.createIcons());
                }
            } catch(e) {}
            this.searchLoading = false;
        },
        addSearchedItem(item) {
            let emptyRow = this.reqForm.items.find(i => !i.description && !i.product_id);
            if (emptyRow) {
                emptyRow.description = item.name;
                emptyRow.unit_price = parseFloat(item.current_price) || 0;
                emptyRow.source = item.source;
                emptyRow.source_label = item.source_label;
                emptyRow.product_id = item.product_id ? item.product_id : 'custom'; 
            } else {
                this.reqForm.items.push({
                    product_id: item.product_id ? item.product_id : 'custom',
                    description: item.name,
                    quantity: 1,
                    unit_price: parseFloat(item.current_price) || 0,
                    old_price: null,
                    source: item.source,
                    source_label: item.source_label
                });
            }
            this.searchTerm = '';
            this.showSearchResults = false;
            this.searchResults = [];
            this.$nextTick(() => lucide.createIcons());
        },
        async loadReportData() {
            const fd = new FormData(); fd.append('action','get_report_data');
            fd.append('date_from', this.reportFilter.date_from);
            fd.append('date_to', this.reportFilter.date_to);
            if (this.reportFilter.department) fd.append('department', this.reportFilter.department);
            try {
                const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
                if (r.success) {
                    this.reportData = r.data;
                    this.$nextTick(() => this.drawCharts());
                }
            } catch(e) {}
        },
        _statusChartInst: null, _deptChartInst: null,
        drawCharts() {
            // --- Status Doughnut ---
            const sc = document.getElementById('reqStatusChart');
            if (sc) {
                if (this._statusChartInst) this._statusChartInst.destroy();
                const statusMap = { submitted:'#f59e0b', hod_approved:'#60a5fa', audit_approved:'#a78bfa', ceo_approved:'#34d399', po_created:'#22d3ee', rejected:'#f87171' };
                const labels = (this.reportData.status_breakdown || []).map(s => s.status.replace(/_/g,' '));
                const vals   = (this.reportData.status_breakdown || []).map(s => parseInt(s.cnt));
                const colors = (this.reportData.status_breakdown || []).map(s => statusMap[s.status] || '#94a3b8');
                this._statusChartInst = new Chart(sc, {
                    type: 'doughnut',
                    data: { labels, datasets: [{ data: vals, backgroundColor: colors, borderWidth: 2, borderColor: '#ffffff', hoverOffset: 6 }] },
                    options: { responsive: true, maintainAspectRatio: true, cutout: '65%',
                        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.parsed}` } } }
                    }
                });
                // Custom inline legend
                const lg = document.getElementById('reqStatusLegend');
                if (lg) lg.innerHTML = labels.map((l,i) => `<span style="background:${colors[i]}" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-white text-[10px] font-bold">${l}: ${vals[i]}</span>`).join('');
            }
            // --- Dept Bar ---
            const dc = document.getElementById('reqDeptChart');
            if (dc) {
                if (this._deptChartInst) this._deptChartInst.destroy();
                const depts = (this.reportData.dept_breakdown || []);
                const dLabels = depts.map(d => d.department);
                const dVals   = depts.map(d => parseFloat(d.val));
                this._deptChartInst = new Chart(dc, {
                    type: 'bar',
                    data: { labels: dLabels, datasets: [{ label: 'Total Value (₦)', data: dVals, backgroundColor: 'rgba(99,102,241,0.75)', borderRadius: 6, borderSkipped: false }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { x: { ticks: { callback: v => '₦' + (v/1000).toFixed(0) + 'k', font: { size: 9 } }, grid: { color: 'rgba(0,0,0,0.05)' } }, y: { ticks: { font: { size: 9 } } } }
                    }
                });
            }
        },
        printReportData() {
            const summaryRows = [
                { metric: '1. Requisitions Initiated', count: this.reportData.total_reqs, amount: _pFmt(this.reportData.total_reqs_val) },
                { metric: '2. Total Approved', count: this.reportData.approved_cnt, amount: _pFmt(this.reportData.approved_val) },
                { metric: '3. Purchase Orders Created', count: this.reportData.po_cnt, amount: _pFmt(this.reportData.po_val) },
                { metric: '4. Physically Verified Deliveries', count: '—', amount: _pFmt(this.reportData.verified_val) },
                { metric: '5. Value Variance (Approved vs Verified)', count: '—', amount: _pFmt(this.reportData.variance_val) },
            ];
            // Build dept breakdown rows
            const deptRows = (this.reportData.dept_breakdown || []).map((d,i) => ({
                rank: i+1, department: d.department, reqs: d.cnt, approved: d.approved_cnt, amount: _pFmt(d.val)
            }));
            // Build status breakdown rows
            const statusRows = (this.reportData.status_breakdown || []).map(s => ({
                status: s.status.replace(/_/g,' '), count: s.cnt
            }));
            // Build recent requisitions rows
            const recentRows = (this.reportData.req_list || []).map(r => ({
                req: r.requisition_number,
                dept: r.department || '—',
                purpose: (r.purpose || '').substring(0,40),
                by: (r.first_name||'') + ' ' + (r.last_name||''),
                status: (r.status||'').replace(/_/g,' '),
                amount: _pFmt(r.total_amount),
                date: (r.created_at||'').substring(0,10)
            }));
            
            // Build multi-section HTML directly
            const w = window.open('','_blank','width=900,height=700');
            const now = new Date();
            w.document.write(`<html><head><title>Requisition Analytics Report</title>
            <style>
                @media print { @page { size: A4 portrait; margin: 12mm; } }
                body { font-family: 'Segoe UI', Arial, sans-serif; color: #1e293b; padding: 20px; }
                h1 { font-size: 18px; margin: 0 0 2px; } h2 { font-size: 13px; margin: 18px 0 6px; color: #334155; border-bottom: 2px solid #e2e8f0; padding-bottom: 4px; }
                .sub { font-size: 10px; color: #64748b; margin-bottom: 16px; }
                table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 14px; }
                th { background: #f1f5f9; text-align: left; padding: 6px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #e2e8f0; }
                td { padding: 5px 8px; border-bottom: 1px solid #f1f5f9; }
                .right { text-align: right; } .center { text-align: center; } .bold { font-weight: 700; }
                .summary-card { display: inline-block; padding: 10px 16px; margin: 0 8px 8px 0; border-radius: 8px; border: 1px solid #e2e8f0; }
                .summary-card .label { font-size: 9px; color: #64748b; text-transform: uppercase; font-weight: 700; }
                .summary-card .val { font-size: 18px; font-weight: 900; }
                .footer { text-align: center; font-size: 9px; color: #94a3b8; margin-top: 20px; padding-top: 10px; border-top: 1px solid #e2e8f0; }
                .company { float: right; text-align: right; font-size: 10px; color: #64748b; }
            </style></head><body>
            <div class="company">${this.companyName}<br>${now.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})} ${now.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'})}</div>
            <h1>Requisition Analytics Report</h1>
            <div class="sub">${this.clientName} | Period: ${this.reportFilter.date_from} to ${this.reportFilter.date_to}${this.reportFilter.department ? ' | Dept: '+this.reportFilter.department : ''}</div>

            <div style="margin-bottom:16px">
                <div class="summary-card"><div class="label">Total Requisitions</div><div class="val">${this.reportData.total_reqs} <span style="font-size:12px;color:#6366f1">${_pFmt(this.reportData.total_reqs_val)}</span></div></div>
                <div class="summary-card"><div class="label">Approved</div><div class="val" style="color:#059669">${this.reportData.approved_cnt} <span style="font-size:12px">${_pFmt(this.reportData.approved_val)}</span></div></div>
                <div class="summary-card"><div class="label">POs Created</div><div class="val" style="color:#0891b2">${this.reportData.po_cnt} <span style="font-size:12px">${_pFmt(this.reportData.po_val)}</span></div></div>
                <div class="summary-card"><div class="label">Verified Total</div><div class="val" style="color:#16a34a">${_pFmt(this.reportData.verified_val)}</div></div>
                <div class="summary-card"><div class="label">Variance</div><div class="val" style="color:${this.reportData.variance_val > 0 ? '#d97706' : '#059669'}">${_pFmt(this.reportData.variance_val)}</div></div>
            </div>

            <h2>Status Breakdown</h2>
            <table><thead><tr><th>Status</th><th class="center">Count</th></tr></thead><tbody>
            ${statusRows.map(s => '<tr><td class="bold" style="text-transform:capitalize">'+s.status+'</td><td class="center">'+s.count+'</td></tr>').join('')}
            </tbody></table>

            <h2>Department Analysis</h2>
            <table><thead><tr><th>#</th><th>Department</th><th class="center">Total Reqs</th><th class="center">Approved</th><th class="right">Value</th></tr></thead><tbody>
            ${deptRows.map(d => '<tr><td>'+d.rank+'</td><td class="bold">'+d.department+'</td><td class="center">'+d.reqs+'</td><td class="center">'+d.approved+'</td><td class="right bold">'+d.amount+'</td></tr>').join('')}
            </tbody></table>

            <h2>Recent Requisitions (${recentRows.length})</h2>
            <table><thead><tr><th>Req #</th><th>Dept</th><th>Purpose</th><th>By</th><th class="center">Status</th><th class="right">Amount</th><th>Date</th></tr></thead><tbody>
            ${recentRows.map(r => '<tr><td class="bold" style="color:#4f46e5">'+r.req+'</td><td>'+r.dept+'</td><td>'+r.purpose+'</td><td>'+r.by+'</td><td class="center" style="text-transform:capitalize">'+r.status+'</td><td class="right bold">'+r.amount+'</td><td>'+r.date+'</td></tr>').join('')}
            </tbody></table>

            <div class="footer">Generated by ${this.companyName} — ${now.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</div>
            </body></html>`);
            w.document.close();
            setTimeout(() => { w.print(); }, 400);
        },
        async printPO(po) {
            let items = [];
            try {
                const fd = new FormData(); fd.append('action','get_items'); fd.append('requisition_id', po.requisition_id);
                const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
                if (r.success) items = r.items.filter(i => (i.status||'active') !== 'rejected');
            } catch(e) {}
            printReport({
                title: 'Purchase Order — ' + po.po_number,
                subtitle: `${this.clientName}  |  Requisition: ${po.requisition_number}  |  Dept: ${po.department||'—'}  |  Date: ${(po.created_at||'').substring(0,10)}`,
                companyName: this.companyName,
                orientation: 'landscape',
                columns: [
                    { label: 'Item / Description', key: 'desc', bold: true },
                    { label: 'Qty', key: 'qty', align: 'center' },
                    { label: 'Unit Price', key: 'price', align: 'right' },
                    { label: 'Total', key: 'total', align: 'right' },
                ],
                rows: items.map(i => ({ desc: i.product_name||i.description, qty: i.quantity, price: _pFmt(i.unit_price), total: _pFmt(i.total_price) })),
                footer: `<td colspan="2" style="text-align:right;font-weight:800;">Grand Total (${items.length} items):</td><td style="text-align:right;font-weight:900;">${_pFmt(po.total_amount)}</td><td></td>`,
            });
        },
        printApprovedReq(r) {
            printReport({
                title: 'Approved Requisition — ' + r.requisition_number,
                subtitle: `${this.clientName}  |  Dept: ${r.department||'—'}  |  By: ${r.first_name} ${r.last_name}  |  Status: ${(r.status||'').replace(/_/g,' ')}`,
                companyName: this.companyName,
                orientation: 'portrait',
                columns: [
                    { label: 'Field', key: 'field', bold: true },
                    { label: 'Details', key: 'value' },
                ],
                rows: [
                    { field: 'Requisition #', value: r.requisition_number },
                    { field: 'Department', value: r.department||'—' },
                    { field: 'Purpose', value: r.purpose },
                    { field: 'Priority', value: r.priority },
                    { field: 'Status', value: (r.status||'').replace(/_/g,' ') },
                    { field: 'Total Amount', value: _pFmt(r.total_amount) },
                    { field: 'Date Submitted', value: (r.created_at||'').substring(0,10) },
                ]
            });
        }
    }
}
</script>
<script src="../assets/js/print-utils.js"></script>
<?php include '../includes/dashboard_scripts.php'; ?>
</body></html>
