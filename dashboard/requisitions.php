<?php
/**
 * MIAUDITOPS — Requisition Management (Stock-In Style)
 * 3 Tabs: Requisitions, Approvals Queue, Purchase Orders
 */
require_once '../includes/functions.php';
require_login();
require_permission('requisitions');
require_active_client();
$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$user_role  = get_user_role();
$page_title = 'Requisitions';

// Products for line items
$stmt = $pdo->prepare("SELECT id, name, sku, unit, unit_cost FROM products WHERE company_id = ? AND deleted_at IS NULL ORDER BY name");
$stmt->execute([$company_id]);
$products = $stmt->fetchAll();

// All requisitions with requestor info
$stmt = $pdo->prepare("SELECT r.*, u.first_name, u.last_name FROM requisitions r JOIN users u ON r.requested_by = u.id WHERE r.company_id = ? AND r.deleted_at IS NULL ORDER BY r.created_at DESC LIMIT 200");
$stmt->execute([$company_id]);
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

// Purchase Orders
$stmt = $pdo->prepare("SELECT po.*, r.requisition_number, r.department, u.first_name, u.last_name FROM purchase_orders po JOIN requisitions r ON po.requisition_id = r.id JOIN users u ON po.created_by = u.id WHERE po.company_id = ? ORDER BY po.created_at DESC LIMIT 50");
$stmt->execute([$company_id]);
$pos = $stmt->fetchAll();

// Stats
$total_reqs = count($all_reqs);
$approved_count = count(array_filter($all_reqs, fn($r) => in_array($r['status'], ['ceo_approved','po_created'])));
$rejected_count = count(array_filter($all_reqs, fn($r) => $r['status'] === 'rejected'));
$pending_count  = count(array_filter($all_reqs, fn($r) => !in_array($r['status'], ['ceo_approved','po_created','rejected','cancelled'])));
$total_value = array_sum(array_map(fn($r) => $r['total_amount'] ?? 0, $all_reqs));

// Departments list
$stmt = $pdo->prepare("SELECT DISTINCT department FROM requisitions WHERE company_id = ? AND department != '' AND deleted_at IS NULL ORDER BY department");
$stmt->execute([$company_id]);
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

$js_products = json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS);
$js_my_reqs  = json_encode($my_reqs, JSON_HEX_TAG | JSON_HEX_APOS);
$js_all_reqs = json_encode($all_reqs, JSON_HEX_TAG | JSON_HEX_APOS);
$js_pending  = json_encode($pending_for_me, JSON_HEX_TAG | JSON_HEX_APOS);
$js_pos      = json_encode($pos, JSON_HEX_TAG | JSON_HEX_APOS);
$js_depts    = json_encode($departments, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requisitions — MIAUDITOPS</title>
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
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-black text-slate-900 dark:text-white">Requisitions</h1><p class="text-sm text-slate-500">Purchase request & approval management</p></div>
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
                        <button @click="showForm = !showForm; $nextTick(() => lucide.createIcons())" :class="showForm ? 'from-red-500 to-rose-600 shadow-red-500/30' : 'from-indigo-500 to-violet-600 shadow-indigo-500/30'" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                            <i :data-lucide="showForm ? 'x' : 'file-plus'" class="w-3.5 h-3.5"></i>
                            <span x-text="showForm ? 'Close' : 'New Requisition'"></span>
                        </button>
                    </div>

                    <!-- Inline Create Form (Stock-In style) -->
                    <div x-show="showForm" x-transition.duration.200ms class="border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-indigo-500/5 via-violet-500/3 to-transparent">
                        <div class="p-5">
                            <!-- Form Header: Department, Purpose, Priority -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
                                <div>
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Department *</label>
                                    <input type="text" x-model="reqForm.department" list="dept-list" placeholder="e.g. Operations" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all">
                                    <datalist id="dept-list"><?php foreach($departments as $d): ?><option value="<?= htmlspecialchars($d) ?>"><?php endforeach; ?></datalist>
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

                            <!-- Line Items Header + Quick Search -->
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-4 flex-1 mr-4">
                                    <h4 class="text-[11px] font-bold uppercase text-slate-400 shrink-0">Requisition Items</h4>
                                    <div class="relative flex-1 max-w-sm" x-data="{ q: '', show: false }">
                                        <div class="relative">
                                            <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                                            <input type="text" x-model="q" @focus="show = true" @input="show = true" placeholder="Quick Search & Add Product..." class="w-full pl-9 pr-4 py-1.5 bg-white/50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg text-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition-all">
                                        </div>
                                        <div x-show="show && q.length > 0" @click.away="show = false" class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl shadow-2xl z-[60] max-h-60 overflow-y-auto">
                                            <template x-for="p in products.filter(p => p.name.toLowerCase().includes(q.toLowerCase()) || (p.sku||'').toLowerCase().includes(q.toLowerCase())).slice(0, 10)" :key="p.id">
                                                <button @click="reqForm.items.push({ product_id: p.id, description: p.name, quantity: 1, unit_price: parseFloat(p.unit_cost)||0 }); q = ''; show = false; $nextTick(() => lucide.createIcons())" class="w-full text-left px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-800 border-b border-slate-100 dark:border-slate-800 last:border-0 flex items-center justify-between transition-colors">
                                                    <div><div class="text-xs font-bold text-slate-800 dark:text-white" x-text="p.name"></div><div class="text-[10px] text-slate-500" x-text="p.sku || p.unit"></div></div>
                                                    <i data-lucide="plus" class="w-3 h-3 text-indigo-500"></i>
                                                </button>
                                            </template>
                                            <div x-show="products.filter(p => p.name.toLowerCase().includes(q.toLowerCase())).length === 0" class="px-4 py-3 text-center text-xs text-slate-400">No products found</div>
                                        </div>
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
                                                    <div class="flex gap-2">
                                                        <select x-model="line.product_id" @change="autoFillProduct(line)" class="w-1/2 px-2 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                                            <option value="">— or type below —</option>
                                                            <template x-for="p in products" :key="p.id"><option :value="p.id" x-text="p.name"></option></template>
                                                        </select>
                                                        <input type="text" x-model="line.description" placeholder="Item description..." class="w-1/2 px-2 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                                    </div>
                                                </td>
                                                <td class="px-3 py-2"><input type="number" x-model.number="line.quantity" min="1" class="w-full px-2 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-semibold"></td>
                                                <td class="px-3 py-2"><input type="number" step="0.01" x-model.number="line.unit_price" min="0" class="w-full px-2 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-semibold"></td>
                                                <td class="px-3 py-2"><div class="w-full px-2 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold text-indigo-600" x-text="fmt(line.quantity * line.unit_price)"></div></td>
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

                    <!-- Requisition History Table -->
                    <div class="overflow-x-auto max-h-[600px] overflow-y-auto"><table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0"><tr>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Req #</th>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Requestor</th>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Dept</th>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Purpose</th>
                            <th class="px-3 py-2.5 text-right text-xs font-bold text-slate-500">Amount</th>
                            <th class="px-3 py-2.5 text-center text-xs font-bold text-slate-500">Priority</th>
                            <th class="px-3 py-2.5 text-center text-xs font-bold text-slate-500">Status</th>
                            <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Date</th>
                            <th class="px-3 py-2.5 text-center text-xs font-bold text-slate-500">Actions</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="r in displayReqs" :key="r.id">
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-indigo-50/50 dark:hover:bg-slate-800/30 cursor-pointer" @click="toggleExpand(r.id)">
                                    <td class="px-3 py-2.5 font-mono text-xs font-bold text-indigo-600" x-text="r.requisition_number"></td>
                                    <td class="px-3 py-2.5 text-xs" x-text="r.first_name + ' ' + r.last_name"></td>
                                    <td class="px-3 py-2.5 text-xs" x-text="r.department"></td>
                                    <td class="px-3 py-2.5 text-xs max-w-[150px] truncate" x-text="r.purpose"></td>
                                    <td class="px-3 py-2.5 text-right font-bold" x-text="fmt(r.total_amount)"></td>
                                    <td class="px-3 py-2.5 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="getPriorityColor(r.priority)" x-text="r.priority"></span></td>
                                    <td class="px-3 py-2.5 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="getStatusColor(r.status)" x-text="r.status?.replace(/_/g,' ')"></span></td>
                                    <td class="px-3 py-2.5 font-mono text-xs text-slate-500" x-text="r.created_at?.substring(0,10)"></td>
                                    <td class="px-3 py-2.5 text-center" @click.stop>
                                        <div class="flex items-center justify-center gap-1">
                                            <template x-if="r.status === 'ceo_approved'">
                                                <button @click="openPriceModal(r)" class="px-2 py-1 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all" title="Fill Actual Prices & Create PO">₦ Price</button>
                                            </template>
                                            <template x-if="r.status === 'ceo_approved'">
                                                <button @click="convertToPO(r.id)" class="px-2 py-1 bg-gradient-to-r from-blue-500 to-cyan-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all">→ PO</button>
                                            </template>
                                            <template x-if="r.status === 'submitted' && r.requested_by == userId">
                                                <button @click="deleteReq(r.id)" class="p-1 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 rounded-lg transition-all"><i data-lucide="trash-2" class="w-3 h-3 text-red-500"></i></button>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <!-- Expandable item rows -->
                            <template x-for="r in displayReqs" :key="'exp-'+r.id">
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
                            <tr x-show="displayReqs.length === 0"><td colspan="9" class="px-4 py-12 text-center text-slate-400">No requisitions found</td></tr>
                        </tbody>
                    </table></div>
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
                                            <button @click="approveReq(r.id)" class="px-3 py-1.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-xs font-bold rounded-lg shadow-sm hover:scale-105 transition-all">✓ Approve</button>
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
                        <span class="text-xs text-slate-500" x-text="purchaseOrders.length + ' orders'"></span>
                    </div>
                    <div class="overflow-x-auto"><table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">PO #</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Requisition</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Dept</th><th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Amount</th><th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Status</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Created By</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th></tr></thead>
                        <tbody>
                            <template x-for="po in purchaseOrders" :key="po.id">
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-blue-50/50 dark:hover:bg-slate-800/30">
                                    <td class="px-4 py-3 font-mono text-xs font-bold text-blue-600" x-text="po.po_number"></td>
                                    <td class="px-4 py-3 font-mono text-xs text-indigo-500" x-text="po.requisition_number"></td>
                                    <td class="px-4 py-3 text-xs" x-text="po.department || '—'"></td>
                                    <td class="px-4 py-3 text-right font-bold" x-text="fmt(po.total_amount)"></td>
                                    <td class="px-4 py-3 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="po.status==='delivered'?'bg-emerald-100 text-emerald-700':'bg-blue-100 text-blue-700'" x-text="po.status"></span></td>
                                    <td class="px-4 py-3 text-xs text-slate-500" x-text="po.first_name + ' ' + po.last_name"></td>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-500" x-text="po.created_at?.substring(0,10)"></td>
                                </tr>
                            </template>
                            <tr x-show="purchaseOrders.length === 0"><td colspan="7" class="px-4 py-12 text-center text-slate-400">No purchase orders</td></tr>
                        </tbody>
                    </table></div>
                </div>
            </div>

        </main>
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

<script>
function reqApp() {
    return {
        currentTab: (location.hash.slice(1) || 'requisitions'), statusFilter: '', showMineOnly: false, showForm: false,
        expandedId: null, expandedItems: [],
        priceModal: false, priceReq: null, priceItems: [],
        userId: <?= $user_id ?>, userRole: '<?= $user_role ?>',
        tabs: [
            { id: 'requisitions', label: 'Requisitions', icon: 'file-plus' },
            { id: 'approvals', label: 'Approvals', icon: 'check-square' },
            { id: 'pos', label: 'Purchase Orders', icon: 'file-check' },
        ],
        products: <?= $js_products ?>,
        myReqs: <?= $js_my_reqs ?>,
        allReqs: <?= $js_all_reqs ?>,
        pendingApprovals: <?= $js_pending ?>,
        purchaseOrders: <?= $js_pos ?>,
        reqForm: { department:'', purpose:'', priority:'medium', items:[{product_id:'', description:'', quantity:1, unit_price:0}] },

        get reqFormTotal() { return this.reqForm.items.reduce((s,i) => s + ((i.quantity||0) * (i.unit_price||0)), 0); },
        get displayReqs() {
            let list = this.showMineOnly ? this.myReqs : this.allReqs;
            if (this.statusFilter) list = list.filter(r => r.status === this.statusFilter);
            return list;
        },

        addLineItem() { this.reqForm.items.push({product_id:'', description:'', quantity:1, unit_price:0}); this.$nextTick(() => lucide.createIcons()); },
        resetForm() { this.reqForm = { department:'', purpose:'', priority:'medium', items:[{product_id:'', description:'', quantity:1, unit_price:0}] }; },
        autoFillProduct(line) {
            if (line.product_id) {
                const p = this.products.find(p => p.id == line.product_id);
                if (p) { line.description = p.name; line.unit_price = parseFloat(p.unit_cost) || 0; }
            }
        },
        fmt(v) { return '₦' + parseFloat(v||0).toLocaleString('en-NG',{minimumFractionDigits:2}); },
        init() {
            this.$watch('currentTab', () => { location.hash = this.currentTab; setTimeout(() => lucide.createIcons(), 50); });
            window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (h && this.tabs.some(t => t.id === h)) this.currentTab = h; });
        },

        getPriorityColor(p) { return { low:'bg-slate-100 text-slate-600', medium:'bg-blue-100 text-blue-700', high:'bg-orange-100 text-orange-700', urgent:'bg-red-100 text-red-700' }[p] || 'bg-slate-100 text-slate-600'; },
        getStatusColor(s) { return { submitted:'bg-amber-100 text-amber-700', hod_approved:'bg-blue-100 text-blue-700', audit_approved:'bg-violet-100 text-violet-700', ceo_approved:'bg-emerald-100 text-emerald-700', po_created:'bg-cyan-100 text-cyan-700', rejected:'bg-red-100 text-red-700' }[s] || 'bg-slate-100 text-slate-600'; },

        async toggleExpand(id) {
            if (this.expandedId === id) { this.expandedId = null; this.expandedItems = []; return; }
            this.expandedId = id;
            const fd = new FormData(); fd.append('action','get_items'); fd.append('requisition_id', id);
            try {
                const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
                this.expandedItems = r.success ? r.items : [];
            } catch(e) { this.expandedItems = []; }
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

        async approveReq(id) {
            if (!confirm('Approve this requisition?')) return;
            const fd = new FormData(); fd.append('action','approve'); fd.append('requisition_id',id);
            const r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
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
        async savePricesAndPO() {
            if (!this.priceReq) return;
            if (this.priceItems.some(i => !i.actual_unit_price || i.actual_unit_price <= 0)) { alert('Please fill all actual prices'); return; }
            const prices = this.priceItems.map(i => ({ item_id: i.id, actual_unit_price: i.actual_unit_price }));
            // Save prices
            let fd = new FormData(); fd.append('action','update_purchase_prices'); fd.append('requisition_id', this.priceReq.id); fd.append('prices', JSON.stringify(prices));
            let r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (!r.success) { alert(r.message); return; }
            // Convert to PO
            fd = new FormData(); fd.append('action','convert_to_po'); fd.append('requisition_id', this.priceReq.id);
            r = await (await fetch('../ajax/requisition_api.php',{method:'POST',body:fd})).json();
            if (r.success) { alert('Purchase Order ' + r.po_number + ' created!'); location.reload(); } else alert(r.message);
        },
    }
}
</script>
<?php include '../includes/dashboard_scripts.php'; ?>
</body></html>
