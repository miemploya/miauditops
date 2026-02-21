<?php
/**
 * MIAUDITOPS — Daily Audit & Sales Control Module
 * SPA with tabs: Sales Entry, Bank Lodgments, Variance Detection, Audit Sign-Off
 */
require_once '../includes/functions.php';
require_login();
require_subscription('audit');
require_permission('audit');
require_active_client();
$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id = $_SESSION['user_id'];
$page_title = 'Daily Audit';

// Fetch outlets for the active client
$client_outlets = get_client_outlets($client_id, $company_id);
$js_outlets = json_encode($client_outlets, JSON_HEX_TAG | JSON_HEX_APOS);

// Fetch today's summary (scoped by client)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(pos_amount),0) as pos, COALESCE(SUM(cash_amount),0) as cash, COALESCE(SUM(transfer_amount),0) as transfer, COALESCE(SUM(actual_total),0) as total, COALESCE(SUM(declared_total),0) as declared, COALESCE(SUM(variance),0) as variance FROM sales_transactions WHERE company_id = ? AND client_id = ? AND transaction_date = CURDATE() AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$today = $stmt->fetch();

// Fetch recent transactions with outlet name (scoped by client)
$stmt = $pdo->prepare("SELECT s.*, u.first_name, u.last_name, co.name as outlet_name FROM sales_transactions s LEFT JOIN users u ON s.entered_by = u.id LEFT JOIN client_outlets co ON s.outlet_id = co.id WHERE s.company_id = ? AND s.client_id = ? AND s.deleted_at IS NULL ORDER BY s.transaction_date DESC, s.created_at DESC LIMIT 200");
$stmt->execute([$company_id, $client_id]);
$transactions = $stmt->fetchAll();

// Fetch bank lodgments (scoped by client)
$stmt = $pdo->prepare("SELECT b.*, u1.first_name as lodger_first, u1.last_name as lodger_last, u2.first_name as confirmer_first, u2.last_name as confirmer_last FROM bank_lodgments b LEFT JOIN users u1 ON b.lodged_by = u1.id LEFT JOIN users u2 ON b.confirmed_by = u2.id WHERE b.company_id = ? AND b.client_id = ? AND b.deleted_at IS NULL ORDER BY b.lodgment_date DESC LIMIT 50");
$stmt->execute([$company_id, $client_id]);
$lodgments = $stmt->fetchAll();

// Fetch pending cash transactions (not yet confirmed)
$stmt = $pdo->prepare("SELECT s.id, s.transaction_date, s.cash_amount, s.cash_lodgment_status, s.shift, co.name as outlet_name FROM sales_transactions s LEFT JOIN client_outlets co ON s.outlet_id = co.id WHERE s.company_id = ? AND s.client_id = ? AND s.cash_amount > 0 AND s.cash_lodgment_status IN ('pending','deposited') AND s.deleted_at IS NULL ORDER BY s.transaction_date DESC");
$stmt->execute([$company_id, $client_id]);
$pending_cash = $stmt->fetchAll();
$js_pending_cash = json_encode($pending_cash, JSON_HEX_TAG | JSON_HEX_APOS);

// Fetch variances (scoped by client)
$stmt = $pdo->prepare("SELECT v.*, u.first_name, u.last_name FROM variance_reports v LEFT JOIN users u ON v.resolved_by = u.id WHERE v.company_id = ? AND v.client_id = ? ORDER BY v.created_at DESC LIMIT 50");
$stmt->execute([$company_id, $client_id]);
$variances = $stmt->fetchAll();


$js_transactions = json_encode($transactions, JSON_HEX_TAG | JSON_HEX_APOS);
$js_lodgments = json_encode($lodgments, JSON_HEX_TAG | JSON_HEX_APOS);
$js_variances = json_encode($variances, JSON_HEX_TAG | JSON_HEX_APOS);
$js_today = json_encode($today, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Audit — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']},colors:{brand:{50:'#f5f3ff',100:'#ede9fe',200:'#ddd6fe',300:'#c4b5fd',400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed',700:'#6d28d9',800:'#5b21b6',900:'#4c1d95',950:'#2e1065'}}}}}</script>
    <style>
        [x-cloak]{display:none!important}
        .glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95) 0%,rgba(249,250,251,0.9) 100%);backdrop-filter:blur(20px)}
        .dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95) 0%,rgba(30,41,59,0.9) 100%)}
    </style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="auditApp()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8 scroll-smooth">
            <?php display_flash_message(); ?>

            <!-- Tab Navigation -->
            <div class="mb-6">
                <div class="flex flex-wrap gap-1.5 p-1.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700">
                    <template x-for="t in tabs" :key="t.id">
                        <button @click="currentTab = t.id"
                                :class="currentTab === t.id ? t.activeClass : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 hover:bg-white/50 border-transparent'"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all duration-200 border">
                            <i :data-lucide="t.icon" class="w-3.5 h-3.5"></i>
                            <span x-text="t.label"></span>
                        </button>
                    </template>
                </div>
            </div>

            <!-- Today's Summary Strip -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">POS</p>
                    <p class="text-lg font-black text-blue-600" x-text="fmt(filteredSummary.pos)"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Cash</p>
                    <p class="text-lg font-black text-emerald-600" x-text="fmt(filteredSummary.cash)"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Transfer</p>
                    <p class="text-lg font-black text-violet-600" x-text="fmt(filteredSummary.transfer)"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Total</p>
                    <p class="text-lg font-black text-slate-800 dark:text-white" x-text="fmt(filteredSummary.total)"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Declared</p>
                    <p class="text-lg font-black text-amber-600" x-text="fmt(filteredSummary.declared)"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Variance</p>
                    <p class="text-lg font-black" :class="parseFloat(filteredSummary.variance) === 0 ? 'text-emerald-600' : 'text-red-600'" x-text="fmt(filteredSummary.variance)"></p>
                </div>
            </div>

            <!-- =============== TAB: Sales Entry =============== -->
            <div x-show="currentTab === 'sales'" x-transition>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Entry Form -->
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/10 via-indigo-500/5 to-transparent">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/30">
                                    <i data-lucide="plus" class="w-5 h-5 text-white"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-900 dark:text-white">Record Sales</h3>
                                    <p class="text-xs text-slate-500">Enter daily transaction</p>
                                </div>
                            </div>
                        </div>
                        <form @submit.prevent="saveSales()" class="p-6 space-y-4">
                            <div>
                                <label class="flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                    <span class="w-6 h-6 rounded-lg bg-indigo-100 flex items-center justify-center"><i data-lucide="store" class="w-3.5 h-3.5 text-indigo-600"></i></span> Outlet *
                                </label>
                                <select x-model="salesForm.outlet_id" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                    <option value="">— Select Outlet —</option>
                                    <template x-for="o in outlets" :key="o.id">
                                        <option :value="o.id" x-text="o.name + ' (' + o.type.replace('_',' ') + ')'"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                    <span class="w-6 h-6 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center"><i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-500"></i></span> Date
                                    <!-- Toggle: Single Day vs Range -->
                                    <div class="ml-auto flex gap-0.5 p-0.5 bg-slate-100 dark:bg-slate-800 rounded-lg">
                                        <button type="button" @click="salesForm.dateMode='single'" :class="salesForm.dateMode==='single' ? 'bg-white dark:bg-slate-700 shadow-sm text-blue-600 font-bold' : 'text-slate-400 hover:text-slate-600'" class="px-2 py-0.5 text-[10px] rounded-md transition-all">Single Day</button>
                                        <button type="button" @click="salesForm.dateMode='range'" :class="salesForm.dateMode==='range' ? 'bg-white dark:bg-slate-700 shadow-sm text-blue-600 font-bold' : 'text-slate-400 hover:text-slate-600'" class="px-2 py-0.5 text-[10px] rounded-md transition-all">Date Range</button>
                                    </div>
                                </label>
                                <!-- Single Day -->
                                <div x-show="salesForm.dateMode==='single'">
                                    <input type="date" x-model="salesForm.date" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                </div>
                                <!-- Date Range -->
                                <div x-show="salesForm.dateMode==='range'" class="grid grid-cols-2 gap-2">
                                    <div>
                                        <span class="text-[10px] font-bold text-slate-400 uppercase mb-0.5 block">From</span>
                                        <input type="date" x-model="salesForm.date_from" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                    </div>
                                    <div>
                                        <span class="text-[10px] font-bold text-slate-400 uppercase mb-0.5 block">To</span>
                                        <input type="date" x-model="salesForm.date_to" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                    <span class="w-6 h-6 rounded-lg bg-blue-100 flex items-center justify-center"><i data-lucide="clock" class="w-3.5 h-3.5 text-blue-600"></i></span> Shift
                                </label>
                                <select x-model="salesForm.shift" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                    <option value="morning">Morning</option>
                                    <option value="afternoon">Afternoon</option>
                                    <option value="evening">Evening</option>
                                    <option value="night">Night</option>
                                    <option value="full_day">Full Day</option>
                                </select>
                            </div>
                            <!-- ── System Sales (Actual Amounts) ── -->
                            <div class="p-3.5 rounded-xl bg-blue-50/70 dark:bg-blue-900/10 border border-blue-200/60 dark:border-blue-800/40">
                                <p class="text-[10px] font-black uppercase tracking-widest text-blue-500 mb-2.5 flex items-center gap-1.5">
                                    <i data-lucide="bar-chart-3" class="w-3 h-3"></i> System Sales
                                </p>
                                <div>
                                    <label class="text-[10px] font-bold text-blue-600/80 mb-0.5 block">Amount (₦)</label>
                                    <input type="number" step="0.01" x-model="salesForm.system_amount" class="w-full px-2.5 py-2 bg-white dark:bg-slate-900 border border-blue-200 dark:border-blue-800 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 transition-all">
                                </div>
                            </div>

                            <!-- ── Declared Amounts ── -->
                            <div class="p-3.5 rounded-xl bg-amber-50/70 dark:bg-amber-900/10 border border-amber-200/60 dark:border-amber-800/40">
                                <p class="text-[10px] font-black uppercase tracking-widest text-amber-500 mb-2.5 flex items-center gap-1.5">
                                    <i data-lucide="clipboard-check" class="w-3 h-3"></i> Declared Amounts
                                </p>
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="text-[10px] font-bold text-amber-600/80 mb-0.5 block">POS (₦)</label>
                                        <input type="number" step="0.01" x-model="salesForm.declared_pos" class="w-full px-2.5 py-2 bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-800 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500 transition-all">
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold text-amber-600/80 mb-0.5 block">Cash (₦)</label>
                                        <input type="number" step="0.01" x-model="salesForm.declared_cash" class="w-full px-2.5 py-2 bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-800 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500 transition-all">
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold text-amber-600/80 mb-0.5 block">Transfer (₦)</label>
                                        <input type="number" step="0.01" x-model="salesForm.declared_transfer" class="w-full px-2.5 py-2 bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-800 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500 transition-all">
                                    </div>
                                </div>
                                <div class="mt-2 flex justify-between items-center px-1">
                                    <span class="text-[10px] font-bold text-amber-400 uppercase">Declared Total</span>
                                    <span class="text-sm font-black text-amber-700 dark:text-amber-300" x-text="fmt(declaredTotal)"></span>
                                </div>
                            </div>

                            <!-- ── Variance & Comment ── -->
                            <div class="p-3.5 rounded-xl border-2 transition-colors"
                                 :class="liveVariance === 0 ? 'bg-emerald-50/70 border-emerald-300 dark:bg-emerald-900/15 dark:border-emerald-700' : (liveVariance > 0 ? 'bg-blue-50/70 border-blue-300 dark:bg-blue-900/15 dark:border-blue-700' : 'bg-red-50/70 border-red-300 dark:bg-red-900/15 dark:border-red-700')">
                                <p class="text-[10px] font-black uppercase tracking-widest mb-2 flex items-center gap-1.5"
                                   :class="liveVariance === 0 ? 'text-emerald-500' : (liveVariance > 0 ? 'text-blue-500' : 'text-red-500')">
                                    <i data-lucide="scale" class="w-3 h-3"></i>
                                    <span x-text="liveVariance === 0 ? 'Balanced' : (liveVariance > 0 ? 'Excess' : 'Shortage')"></span>
                                </p>
                                <div class="flex justify-between items-center mb-3">
                                    <div>
                                        <span class="text-xs text-slate-500 dark:text-slate-400">Declared − System</span>
                                    </div>
                                    <span class="text-2xl font-black"
                                          :class="liveVariance === 0 ? 'text-emerald-600' : (liveVariance > 0 ? 'text-blue-600' : 'text-red-600')"
                                          x-text="fmt(liveVariance)"></span>
                                </div>
                                <div class="flex items-center gap-2 text-[10px] mb-3"
                                     :class="liveVariance === 0 ? 'text-emerald-600' : (liveVariance > 0 ? 'text-blue-600' : 'text-red-600')">
                                    <i :data-lucide="liveVariance === 0 ? 'check-circle' : (liveVariance > 0 ? 'trending-up' : 'trending-down')" class="w-3.5 h-3.5"></i>
                                    <span x-text="liveVariance === 0 ? 'Amounts match — no variance' : (liveVariance > 0 ? 'Excess: Declared exceeds system by ' + fmt(liveVariance) : 'Shortage: System exceeds declared by ' + fmt(Math.abs(liveVariance)))"></span>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold uppercase mb-1 block"
                                           :class="liveVariance === 0 ? 'text-emerald-500' : (liveVariance > 0 ? 'text-blue-500' : 'text-red-500')">Comment / Explanation</label>
                                    <textarea x-model="salesForm.notes" rows="2" placeholder="Add comment or explain variance…"
                                              class="w-full px-2.5 py-2 bg-white/80 dark:bg-slate-900/80 border rounded-lg text-sm focus:ring-2 transition-all"
                                              :class="liveVariance === 0 ? 'border-emerald-200 dark:border-emerald-800 focus:ring-emerald-500/30 focus:border-emerald-500' : (liveVariance > 0 ? 'border-blue-200 dark:border-blue-800 focus:ring-blue-500/30 focus:border-blue-500' : 'border-red-200 dark:border-red-800 focus:ring-red-500/30 focus:border-red-500')"></textarea>
                                </div>
                            </div>

                            <button type="submit" :disabled="saving" class="w-full py-2.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white font-bold rounded-xl shadow-lg shadow-blue-500/30 hover:shadow-blue-500/50 hover:scale-[1.02] transition-all text-sm disabled:opacity-50">
                                <span x-show="!saving">Save Transaction</span>
                                <span x-show="saving">Saving...</span>
                            </button>
                        </form>
                    </div>

                    <!-- Transaction List -->
                    <div class="lg:col-span-2 glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-indigo-500/10 via-purple-500/5 to-transparent">
                            <div class="flex items-center justify-between flex-wrap gap-3">
                                <div>
                                    <h3 class="font-bold text-slate-900 dark:text-white">Sales Records</h3>
                                    <p class="text-xs text-slate-500" x-text="filteredTransactions.length + ' of ' + transactions.length + ' records'"></p>
                                </div>
                                <!-- Date Range Filter -->
                                <div class="flex items-center gap-2 flex-wrap">
                                    <div class="flex gap-1 p-1 bg-slate-100 dark:bg-slate-800 rounded-lg">
                                        <button @click="dateFilter='today'" :class="dateFilter==='today' ? 'bg-white dark:bg-slate-700 shadow-sm text-blue-600 font-bold' : 'text-slate-500 hover:text-slate-700'" class="px-2.5 py-1 text-xs rounded-md transition-all">Today</button>
                                        <button @click="dateFilter='week'" :class="dateFilter==='week' ? 'bg-white dark:bg-slate-700 shadow-sm text-blue-600 font-bold' : 'text-slate-500 hover:text-slate-700'" class="px-2.5 py-1 text-xs rounded-md transition-all">Week</button>
                                        <button @click="dateFilter='month'" :class="dateFilter==='month' ? 'bg-white dark:bg-slate-700 shadow-sm text-blue-600 font-bold' : 'text-slate-500 hover:text-slate-700'" class="px-2.5 py-1 text-xs rounded-md transition-all">Month</button>
                                        <button @click="dateFilter='all'" :class="dateFilter==='all' ? 'bg-white dark:bg-slate-700 shadow-sm text-blue-600 font-bold' : 'text-slate-500 hover:text-slate-700'" class="px-2.5 py-1 text-xs rounded-md transition-all">All</button>
                                        <button @click="dateFilter='custom'" :class="dateFilter==='custom' ? 'bg-white dark:bg-slate-700 shadow-sm text-blue-600 font-bold' : 'text-slate-500 hover:text-slate-700'" class="px-2.5 py-1 text-xs rounded-md transition-all">Custom</button>
                                    </div>
                                    <select x-model="outletFilter" class="px-2.5 py-1.5 text-xs bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg">
                                        <option value="all">All Outlets</option>
                                        <template x-for="o in outlets" :key="o.id">
                                            <option :value="o.id" x-text="o.name"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                            <!-- Custom Date Range -->
                            <div x-show="dateFilter==='custom'" x-transition class="flex items-center gap-2 mt-3">
                                <input type="date" x-model="customFrom" class="px-2.5 py-1.5 text-xs bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg">
                                <span class="text-xs text-slate-400">to</span>
                                <input type="date" x-model="customTo" class="px-2.5 py-1.5 text-xs bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg">
                            </div>
                        </div>

                        <!-- Per-Outlet Summary Strip -->
                        <div x-show="outletSummaries.length > 0" class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-50/50 to-transparent dark:from-emerald-900/10">
                            <p class="text-[10px] font-bold uppercase text-slate-400 mb-2">Outlet Performance (Filtered Period)</p>
                            <div class="flex gap-3 overflow-x-auto pb-1">
                                <template x-for="os in outletSummaries" :key="os.name">
                                    <div class="flex-shrink-0 px-3 py-2 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-sm">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase" x-text="os.name"></p>
                                        <p class="text-sm font-black text-slate-800 dark:text-white" x-text="fmt(os.total)"></p>
                                        <p class="text-[10px] text-slate-400" x-text="os.count + ' entries'"></p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm min-w-[800px]" style="table-layout:fixed">
                                <colgroup>
                                    <col style="width:32px">   <!-- Chevron -->
                                    <col style="width:14%">    <!-- Date -->
                                    <col style="width:14%">    <!-- Outlet -->
                                    <col style="width:8%">     <!-- Shift -->
                                    <col style="width:12%">    <!-- POS -->
                                    <col style="width:12%">    <!-- Cash -->
                                    <col style="width:12%">    <!-- Transfer -->
                                    <col style="width:12%">    <!-- Total -->
                                    <col style="width:10%">    <!-- Variance -->
                                    <col style="width:120px">  <!-- Actions -->
                                </colgroup>
                                <thead class="bg-slate-50 dark:bg-slate-800/50">
                                    <tr>
                                        <th class="pl-3 pr-1 py-3 text-left text-xs font-bold text-slate-500 uppercase"></th>
                                        <th class="px-2 py-3 text-left text-xs font-bold text-slate-500 uppercase">Date</th>
                                        <th class="px-2 py-3 text-left text-xs font-bold text-slate-500 uppercase">Outlet</th>
                                        <th class="px-2 py-3 text-left text-xs font-bold text-slate-500 uppercase">Shift</th>
                                        <th class="px-2 py-3 text-right text-xs font-bold text-slate-500 uppercase">POS</th>
                                        <th class="px-2 py-3 text-right text-xs font-bold text-slate-500 uppercase">Cash</th>
                                        <th class="px-2 py-3 text-right text-xs font-bold text-slate-500 uppercase">Transfer</th>
                                        <th class="px-2 py-3 text-right text-xs font-bold text-slate-500 uppercase">Total</th>
                                        <th class="px-2 py-3 text-right text-xs font-bold text-slate-500 uppercase">Variance</th>
                                        <th class="px-2 py-3 text-center text-xs font-bold text-slate-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody x-init="$nextTick(()=>lucide.createIcons())">
                                    <template x-for="group in groupedByDate" :key="group.date">
                                        <tbody>
                                            <!-- Date Summary Row (click to expand) -->
                                            <tr @click="toggleDate(group.date)" class="border-b border-slate-200 dark:border-slate-700 bg-slate-50/80 dark:bg-slate-800/60 cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                                                <td class="pl-3 pr-1 py-3">
                                                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 transition-transform duration-200" :class="expandedDates[group.date] ? 'rotate-0' : '-rotate-90'"></i>
                                                </td>
                                                <td class="px-2 py-3">
                                                    <span class="font-bold text-sm text-slate-800 dark:text-white" x-text="group.date"></span>
                                                    <span class="ml-1 px-1.5 py-0.5 rounded-md bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 text-[10px] font-bold" x-text="group.items.length + ' outlet' + (group.items.length > 1 ? 's' : '')"></span>
                                                </td>
                                                <td class="px-2 py-3" colspan="2"></td>
                                                <td class="px-2 py-3 text-right font-bold font-mono text-blue-600 text-xs" x-text="fmt(group.pos)"></td>
                                                <td class="px-2 py-3 text-right font-bold font-mono text-emerald-600 text-xs" x-text="fmt(group.cash)"></td>
                                                <td class="px-2 py-3 text-right font-bold font-mono text-violet-600 text-xs" x-text="fmt(group.transfer)"></td>
                                                <td class="px-2 py-3 text-right font-black text-slate-800 dark:text-white text-xs" x-text="fmt(group.total)"></td>
                                                <td class="px-2 py-3 text-right font-bold text-xs" :class="group.variance === 0 ? 'text-emerald-600' : 'text-red-600'" x-text="fmt(group.variance)"></td>
                                                <td class="px-2 py-3"></td>
                                            </tr>
                                            <!-- Child Rows (per outlet) -->
                                            <template x-for="t in group.items" :key="t.id">
                                                <tr x-show="expandedDates[group.date]" x-transition class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                                    <td class="pl-3 pr-1 py-2.5"></td>
                                                    <td class="px-2 py-2.5 font-mono text-xs text-slate-400" x-text="t.transaction_date"></td>
                                                    <td class="px-2 py-2.5"><span class="px-2 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 text-xs font-semibold" x-text="t.outlet_name || '—'"></span></td>
                                                    <td class="px-2 py-2.5"><span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 text-xs font-semibold capitalize" x-text="t.shift"></span></td>
                                                    <td class="px-2 py-2.5 text-right font-mono text-xs" x-text="fmt(t.pos_amount)"></td>
                                                    <td class="px-2 py-2.5 text-right font-mono text-xs" x-text="fmt(t.cash_amount)"></td>
                                                    <td class="px-2 py-2.5 text-right font-mono text-xs" x-text="fmt(t.transfer_amount)"></td>
                                                    <td class="px-2 py-2.5 text-right font-bold text-xs" x-text="fmt(t.actual_total)"></td>
                                                    <td class="px-2 py-2.5 text-right font-bold text-xs" :class="parseFloat(t.variance) === 0 ? 'text-emerald-600' : 'text-red-600'" x-text="fmt(t.variance)"></td>
                                                    <td class="px-2 py-2 text-center">
                                                        <div class="flex flex-nowrap gap-1 justify-center">
                                                            <!-- POS Approve -->
                                                            <template x-if="parseFloat(t.pos_amount) > 0 && !parseInt(t.pos_approved)">
                                                                <button @click.stop="approveSalesPayment(t.id, 'pos')" class="px-1.5 py-0.5 bg-blue-100 hover:bg-blue-200 text-blue-700 text-[10px] font-bold rounded-md transition-all whitespace-nowrap" title="Approve POS">✓POS</button>
                                                            </template>
                                                            <template x-if="parseFloat(t.pos_amount) > 0 && parseInt(t.pos_approved)">
                                                                <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-700 text-[10px] font-bold rounded-md whitespace-nowrap">✓POS</span>
                                                            </template>
                                                            <!-- Transfer Approve -->
                                                            <template x-if="parseFloat(t.transfer_amount) > 0 && !parseInt(t.transfer_approved)">
                                                                <button @click.stop="approveSalesPayment(t.id, 'transfer')" class="px-1.5 py-0.5 bg-purple-100 hover:bg-purple-200 text-purple-700 text-[10px] font-bold rounded-md transition-all whitespace-nowrap" title="Approve Transfer">✓TRF</button>
                                                            </template>
                                                            <template x-if="parseFloat(t.transfer_amount) > 0 && parseInt(t.transfer_approved)">
                                                                <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-700 text-[10px] font-bold rounded-md whitespace-nowrap">✓TRF</span>
                                                            </template>
                                                            <!-- Cash Status -->
                                                            <template x-if="parseFloat(t.cash_amount) > 0 && t.cash_lodgment_status === 'pending'">
                                                                <span class="px-1.5 py-0.5 bg-amber-100 text-amber-700 text-[10px] font-bold rounded-md whitespace-nowrap" title="Cash pending">⏳</span>
                                                            </template>
                                                            <template x-if="parseFloat(t.cash_amount) > 0 && t.cash_lodgment_status === 'deposited'">
                                                                <span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-bold rounded-md whitespace-nowrap">🏦</span>
                                                            </template>
                                                            <template x-if="parseFloat(t.cash_amount) > 0 && t.cash_lodgment_status === 'confirmed'">
                                                                <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-700 text-[10px] font-bold rounded-md whitespace-nowrap">✓$</span>
                                                            </template>
                                                            <!-- Edit & Delete -->
                                                            <button @click.stop="editSale(t)" class="px-1.5 py-0.5 bg-slate-100 hover:bg-blue-100 text-slate-600 hover:text-blue-700 text-[10px] font-bold rounded-md transition-all" title="Edit">
                                                                <i data-lucide="pencil" class="w-3 h-3 inline"></i>
                                                            </button>
                                                            <button @click.stop="deleteSale(t.id)" class="px-1.5 py-0.5 bg-slate-100 hover:bg-red-100 text-slate-600 hover:text-red-700 text-[10px] font-bold rounded-md transition-all" title="Delete">
                                                                <i data-lucide="trash-2" class="w-3 h-3 inline"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </template>
                                    <tr x-show="filteredTransactions.length === 0">
                                        <td colspan="10" class="px-4 py-12 text-center text-slate-400">No transactions match your filters</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =============== TAB: Bank Lodgments =============== -->
            <div x-show="currentTab === 'lodgments'" x-transition>

                <!-- Pending Cash Strip -->
                <div x-show="pendingCash.length > 0" class="mb-6 glass-card rounded-2xl border border-amber-200/60 dark:border-amber-700/40 shadow-lg overflow-hidden">
                    <div class="px-6 py-3 bg-gradient-to-r from-amber-500/10 via-orange-500/5 to-transparent border-b border-amber-200/40 dark:border-amber-800/40">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-md">
                                <i data-lucide="clock" class="w-4 h-4 text-white"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-sm text-slate-900 dark:text-white">Pending Cash Deposits</h3>
                                <p class="text-[10px] text-slate-500">Cash received from sales awaiting bank lodgment &amp; confirmation</p>
                            </div>
                            <span class="ml-auto px-2.5 py-1 bg-amber-100 text-amber-700 text-xs font-black rounded-lg" x-text="pendingCash.length + ' pending'"></span>
                        </div>
                    </div>
                    <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <template x-for="pc in pendingCash" :key="pc.id">
                            <div class="flex items-center justify-between p-3 rounded-xl border transition-all"
                                 :class="pc.cash_lodgment_status === 'deposited' ? 'bg-blue-50/50 border-blue-200 dark:bg-blue-900/10 dark:border-blue-800' : 'bg-amber-50/50 border-amber-200 dark:bg-amber-900/10 dark:border-amber-800'">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-bold text-slate-800 dark:text-white" x-text="fmt(pc.cash_amount)"></p>
                                    <p class="text-[10px] text-slate-500 truncate" x-text="pc.transaction_date + ' · ' + (pc.outlet_name || 'No outlet') + ' · ' + pc.shift"></p>
                                </div>
                                <div class="flex items-center gap-2 ml-2">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                          :class="pc.cash_lodgment_status === 'deposited' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700'"
                                          x-text="pc.cash_lodgment_status === 'deposited' ? '🏦 Deposited' : '⏳ Pending'"></span>
                                    <button x-show="pc.cash_lodgment_status === 'pending'" @click="linkCashToLodgment(pc)" class="px-2 py-1 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all shadow-sm" title="Create bank lodgment for this cash">
                                        <i data-lucide="arrow-right" class="w-3 h-3 inline"></i> Lodge
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500/10 via-teal-500/5 to-transparent">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/30">
                                    <i data-lucide="landmark" class="w-5 h-5 text-white"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-900 dark:text-white">Record Lodgment</h3>
                                    <p class="text-xs text-slate-500">Bank deposit entry</p>
                                </div>
                            </div>
                        </div>
                        <form @submit.prevent="saveLodgment()" class="p-6 space-y-4">
                            <!-- Link to Cash Sale -->
                            <div x-show="pendingCash.length > 0">
                                <label class="flex items-center gap-2 text-xs font-semibold text-slate-600 mb-1">
                                    <span class="w-5 h-5 rounded-md bg-amber-100 flex items-center justify-center"><i data-lucide="link" class="w-3 h-3 text-amber-600"></i></span>
                                    Link to Pending Cash Sale
                                </label>
                                <select x-model="lodgmentForm.linked_cash_txn_id" @change="onCashLinkChange()" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-700 rounded-xl text-sm focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all">
                                    <option value="">— No link (manual lodgment) —</option>
                                    <template x-for="pc in pendingCash" :key="pc.id">
                                        <option :value="pc.id" x-text="pc.transaction_date + ' • ' + (pc.outlet_name || 'N/A') + ' • ₦' + parseFloat(pc.cash_amount).toLocaleString('en-NG', {minimumFractionDigits:2}) + ' (' + pc.cash_lodgment_status + ')'"></option>
                                    </template>
                                </select>
                            </div>
                            <!-- Linked indicator -->
                            <div x-show="lodgmentForm.linked_cash_txn_id" class="flex items-center gap-2 px-3 py-2 bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800 rounded-lg">
                                <i data-lucide="info" class="w-4 h-4 text-amber-500"></i>
                                <span class="text-xs text-amber-700 dark:text-amber-300">This lodgment will settle the linked pending cash. Amount auto-filled.</span>
                            </div>
                            <div><label class="text-xs font-semibold text-slate-600 mb-1 block">Date</label><input type="date" x-model="lodgmentForm.date" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all"></div>
                            <div><label class="text-xs font-semibold text-slate-600 mb-1 block">Bank Name</label><input type="text" x-model="lodgmentForm.bank" placeholder="e.g. Access Bank" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all"></div>
                            <div><label class="text-xs font-semibold text-slate-600 mb-1 block">Account Number</label><input type="text" x-model="lodgmentForm.account" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all"></div>
                            <div><label class="text-xs font-semibold text-slate-600 mb-1 block">Amount (₦)</label><input type="number" step="0.01" x-model="lodgmentForm.amount" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all"></div>
                            <div><label class="text-xs font-semibold text-slate-600 mb-1 block">Reference</label><input type="text" x-model="lodgmentForm.reference" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all"></div>
                            <button type="submit" :disabled="saving" class="w-full py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 hover:scale-[1.02] transition-all text-sm disabled:opacity-50">Save Lodgment</button>
                        </form>
                    </div>
                    <div class="lg:col-span-2 glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800"><h3 class="font-bold text-slate-900 dark:text-white">Lodgment History</h3></div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Bank</th><th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Amount</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Reference</th><th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Source</th><th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Status</th><th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Actions</th></tr></thead>
                                <tbody>
                                    <template x-for="l in lodgments" :key="l.id">
                                        <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30">
                                            <td class="px-4 py-3 font-mono text-xs" x-text="l.lodgment_date"></td>
                                            <td class="px-4 py-3 font-semibold" x-text="l.bank_name"></td>
                                            <td class="px-4 py-3 text-right font-bold text-emerald-600" x-text="fmt(l.amount)"></td>
                                            <td class="px-4 py-3 font-mono text-xs" x-text="l.reference_number"></td>
                                            <td class="px-4 py-3 text-center">
                                                <span x-show="l.source === 'auto_pos'" class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 text-[10px] font-bold">⚡ POS</span>
                                                <span x-show="l.source === 'auto_transfer'" class="px-2 py-0.5 rounded-full bg-purple-50 text-purple-700 text-[10px] font-bold">⚡ Transfer</span>
                                                <span x-show="!l.source || l.source === 'manual'" class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold">✍️ Manual</span>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="l.status==='confirmed'?'bg-emerald-100 text-emerald-700':'bg-amber-100 text-amber-700'" x-text="l.status"></span>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <div class="flex items-center gap-1 justify-center">
                                                    <button x-show="l.status==='pending'" @click="confirmLodgment(l.id)" class="px-3 py-1 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-xs font-bold rounded-lg hover:scale-105 transition-all">Confirm</button>
                                                    <button @click="deleteLodgment(l.id)" class="px-2 py-1 bg-slate-100 hover:bg-red-100 text-slate-500 hover:text-red-600 text-xs font-bold rounded-lg transition-all" title="Delete">
                                                        <i data-lucide="trash-2" class="w-3 h-3 inline"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =============== TAB: Variance Detection =============== -->
            <div x-show="currentTab === 'variance'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 via-orange-500/5 to-transparent">
                        <h3 class="font-bold text-slate-900 dark:text-white">Variance Reports</h3>
                        <p class="text-xs text-slate-500">Discrepancies flagged by the system</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th><th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Category</th><th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Expected</th><th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Actual</th><th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Variance</th><th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Severity</th><th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Status</th></tr></thead>
                            <tbody>
                                <template x-for="v in variances" :key="v.id">
                                    <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30">
                                        <td class="px-4 py-3 font-mono text-xs" x-text="v.report_date"></td>
                                        <td class="px-4 py-3 capitalize" x-text="v.category"></td>
                                        <td class="px-4 py-3 text-right font-mono" x-text="fmt(v.expected_amount)"></td>
                                        <td class="px-4 py-3 text-right font-mono" x-text="fmt(v.actual_amount)"></td>
                                        <td class="px-4 py-3 text-right font-bold text-red-600" x-text="fmt(v.variance_amount)"></td>
                                        <td class="px-4 py-3 text-center"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="{'bg-yellow-100 text-yellow-700':v.severity==='minor','bg-amber-100 text-amber-700':v.severity==='moderate','bg-orange-100 text-orange-700':v.severity==='major','bg-red-100 text-red-700':v.severity==='critical'}" x-text="v.severity"></span></td>
                                        <td class="px-4 py-3 text-center"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="v.status==='resolved'?'bg-emerald-100 text-emerald-700':'bg-amber-100 text-amber-700'" x-text="v.status"></span></td>
                                    </tr>
                                </template>
                                <tr x-show="variances.length === 0"><td colspan="7" class="px-4 py-12 text-center text-slate-400">No variances detected — looking good!</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>


        </main>
    </div>
</div>

<!-- Edit Sales Modal -->
<div x-show="editingTx" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm" @click.self="editingTx = null">
    <div class="glass-card rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl w-full max-w-md mx-4 overflow-hidden" @click.stop>
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/10 to-transparent flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg">
                    <i data-lucide="pencil" class="w-4 h-4 text-white"></i>
                </div>
                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Edit Sales Record</h3>
            </div>
            <button @click="editingTx = null" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-red-100 flex items-center justify-center transition-all">
                <i data-lucide="x" class="w-4 h-4 text-slate-500 hover:text-red-600"></i>
            </button>
        </div>
        <form @submit.prevent="updateSale()" class="p-5 space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] font-bold text-slate-500 block mb-1">Date</label>
                    <input type="date" x-model="editForm.transaction_date" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-slate-500 block mb-1">Shift</label>
                    <select x-model="editForm.shift" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                        <option value="morning">Morning</option>
                        <option value="afternoon">Afternoon</option>
                        <option value="evening">Evening</option>
                        <option value="night">Night</option>
                        <option value="full_day">Full Day</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="text-[10px] font-bold text-slate-500 block mb-1">Outlet</label>
                <select x-model="editForm.outlet_id" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                    <template x-for="o in outlets" :key="o.id">
                        <option :value="o.id" x-text="o.name"></option>
                    </template>
                </select>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="text-[10px] font-bold text-blue-500 block mb-1">POS (₦)</label>
                    <input type="number" step="0.01" x-model="editForm.pos_amount" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-blue-200 dark:border-blue-800 rounded-xl text-sm">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-emerald-500 block mb-1">Cash (₦)</label>
                    <input type="number" step="0.01" x-model="editForm.cash_amount" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-emerald-200 dark:border-emerald-800 rounded-xl text-sm">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-violet-500 block mb-1">Transfer (₦)</label>
                    <input type="number" step="0.01" x-model="editForm.transfer_amount" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-violet-200 dark:border-violet-800 rounded-xl text-sm">
                </div>
            </div>
            <div>
                <label class="text-[10px] font-bold text-amber-500 block mb-1">Declared Total (₦)</label>
                <input type="number" step="0.01" x-model="editForm.declared_total" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-800 rounded-xl text-sm">
            </div>
            <div>
                <label class="text-[10px] font-bold text-slate-500 block mb-1">Notes</label>
                <textarea x-model="editForm.notes" rows="2" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></textarea>
            </div>
            <div class="flex gap-2">
                <button type="button" @click="editingTx = null" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-sm transition-all">Cancel</button>
                <button type="submit" :disabled="saving" class="flex-1 py-2.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white font-bold rounded-xl shadow-lg text-sm hover:scale-[1.02] transition-all disabled:opacity-50">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
function auditApp() {
    return {
        currentTab: (location.hash.slice(1) || 'sales'),
        saving: false,
        tabs: [
            { id: 'sales', label: 'Sales Entry', icon: 'receipt', activeClass: 'bg-white dark:bg-slate-900 text-blue-600 shadow-sm border-blue-200' },
            { id: 'lodgments', label: 'Bank Lodgments', icon: 'landmark', activeClass: 'bg-white dark:bg-slate-900 text-emerald-600 shadow-sm border-emerald-200' },
            { id: 'variance', label: 'Variance', icon: 'alert-triangle', activeClass: 'bg-white dark:bg-slate-900 text-amber-600 shadow-sm border-amber-200' },
        ],
        transactions: <?php echo $js_transactions; ?>,
        lodgments: <?php echo $js_lodgments; ?>,
        variances: <?php echo $js_variances; ?>,
        todaySummary: <?php echo $js_today; ?>,
        outlets: <?php echo $js_outlets; ?>,
        pendingCash: <?php echo $js_pending_cash; ?>,
        salesForm: { dateMode: 'single', date: new Date().toISOString().split('T')[0], date_from: new Date().toISOString().split('T')[0], date_to: new Date().toISOString().split('T')[0], shift: 'full_day', outlet_id: '', system_amount: 0, declared_pos: 0, declared_cash: 0, declared_transfer: 0, notes: '' },
        lodgmentForm: { date: new Date().toISOString().split('T')[0], bank: '', account: '', amount: 0, reference: '', linked_cash_txn_id: '' },
        dateFilter: 'today',
        outletFilter: 'all',
        customFrom: '',
        customTo: '',
        editingTx: null,
        expandedDates: {},
        editForm: { id: 0, transaction_date: '', shift: '', outlet_id: '', pos_amount: 0, cash_amount: 0, transfer_amount: 0, declared_total: 0, notes: '' },

        get systemTotal() {
            return parseFloat(this.salesForm.system_amount||0);
        },

        get declaredTotal() {
            return parseFloat(this.salesForm.declared_pos||0) + parseFloat(this.salesForm.declared_cash||0) + parseFloat(this.salesForm.declared_transfer||0);
        },

        get liveVariance() {
            return this.declaredTotal - this.systemTotal;
        },

        get filteredTransactions() {
            return this.transactions.filter(t => {
                // Date filter
                if (this.dateFilter === 'today') {
                    const todayStr = new Date().toISOString().split('T')[0];
                    if (t.transaction_date !== todayStr) return false;
                } else if (this.dateFilter === 'week') {
                    const weekAgo = new Date(); weekAgo.setDate(weekAgo.getDate() - 7);
                    if (new Date(t.transaction_date) < weekAgo) return false;
                } else if (this.dateFilter === 'month') {
                    const monthAgo = new Date(); monthAgo.setMonth(monthAgo.getMonth() - 1);
                    if (new Date(t.transaction_date) < monthAgo) return false;
                } else if (this.dateFilter === 'custom') {
                    if (this.customFrom && t.transaction_date < this.customFrom) return false;
                    if (this.customTo && t.transaction_date > this.customTo) return false;
                }
                // Outlet filter
                if (this.outletFilter !== 'all' && String(t.outlet_id) !== String(this.outletFilter)) return false;
                return true;
            });
        },

        get outletSummaries() {
            const map = {};
            this.filteredTransactions.forEach(t => {
                const name = t.outlet_name || 'Unassigned';
                if (!map[name]) map[name] = { name, total: 0, count: 0 };
                map[name].total += parseFloat(t.actual_total || 0);
                map[name].count++;
            });
            return Object.values(map).sort((a,b) => b.total - a.total);
        },

        get filteredSummary() {
            const s = { pos: 0, cash: 0, transfer: 0, total: 0, declared: 0, variance: 0 };
            this.filteredTransactions.forEach(t => {
                s.pos += parseFloat(t.pos_amount || 0);
                s.cash += parseFloat(t.cash_amount || 0);
                s.transfer += parseFloat(t.transfer_amount || 0);
                s.total += parseFloat(t.actual_total || 0);
                s.declared += parseFloat(t.declared_total || 0);
                s.variance += parseFloat(t.variance || 0);
            });
            return s;
        },

        get groupedByDate() {
            const map = {};
            this.filteredTransactions.forEach(t => {
                const d = t.transaction_date;
                if (!map[d]) map[d] = { date: d, items: [], pos: 0, cash: 0, transfer: 0, total: 0, variance: 0 };
                map[d].items.push(t);
                map[d].pos += parseFloat(t.pos_amount || 0);
                map[d].cash += parseFloat(t.cash_amount || 0);
                map[d].transfer += parseFloat(t.transfer_amount || 0);
                map[d].total += parseFloat(t.actual_total || 0);
                map[d].variance += parseFloat(t.variance || 0);
            });
            return Object.values(map).sort((a, b) => b.date.localeCompare(a.date));
        },

        toggleDate(date) {
            this.expandedDates[date] = !this.expandedDates[date];
            this.$nextTick(() => lucide.createIcons());
        },

        fmt(v) { return '₦' + parseFloat(v||0).toLocaleString('en-NG', {minimumFractionDigits: 2, maximumFractionDigits: 2}); },

        init() {
            this.$watch('currentTab', (val) => { location.hash = val; setTimeout(() => lucide.createIcons(), 50); });
            this.$watch('liveVariance', () => this.$nextTick(() => lucide.createIcons()));
            window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (h && this.tabs.some(t => t.id === h)) this.currentTab = h; });
        },

        async saveSales() {
            if (!this.salesForm.outlet_id) { alert('Please select an outlet'); return; }
            this.saving = true;
            try {
                const fd = new FormData();
                fd.append('action', 'save_sales');
                fd.append('date', this.salesForm.dateMode === 'range' ? this.salesForm.date_from : this.salesForm.date);
                fd.append('date_to', this.salesForm.dateMode === 'range' ? this.salesForm.date_to : '');
                fd.append('shift', this.salesForm.shift);
                fd.append('outlet_id', this.salesForm.outlet_id);
                fd.append('pos', this.salesForm.declared_pos);
                fd.append('cash', this.salesForm.declared_cash);
                fd.append('transfer', this.salesForm.declared_transfer);
                fd.append('declared', this.systemTotal);
                fd.append('notes', this.salesForm.notes);
                const res = await fetch('../ajax/audit_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { location.reload(); } else { alert(data.message || 'Error'); }
            } catch(e) { alert('Network error'); }
            this.saving = false;
        },

        async saveLodgment() {
            this.saving = true;
            try {
                const fd = new FormData();
                fd.append('action', 'save_lodgment');
                fd.append('date', this.lodgmentForm.date);
                fd.append('bank', this.lodgmentForm.bank);
                fd.append('account', this.lodgmentForm.account);
                fd.append('amount', this.lodgmentForm.amount);
                fd.append('reference', this.lodgmentForm.reference);
                if (this.lodgmentForm.linked_cash_txn_id) {
                    fd.append('linked_cash_txn_id', this.lodgmentForm.linked_cash_txn_id);
                }
                const res = await fetch('../ajax/audit_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { location.reload(); } else { alert(data.message || 'Error'); }
            } catch(e) { alert('Network error'); }
            this.saving = false;
        },

        // Link pending cash card to lodgment form
        linkCashToLodgment(pc) {
            this.lodgmentForm.linked_cash_txn_id = String(pc.id);
            this.lodgmentForm.amount = parseFloat(pc.cash_amount);
            this.lodgmentForm.date = pc.transaction_date;
            this.lodgmentForm.reference = 'CASH-DEP-' + pc.id;
            this.$nextTick(() => lucide.createIcons());
        },

        // When dropdown changes, auto-fill amount
        onCashLinkChange() {
            const id = this.lodgmentForm.linked_cash_txn_id;
            if (!id) { this.lodgmentForm.amount = 0; return; }
            const pc = this.pendingCash.find(p => String(p.id) === String(id));
            if (pc) {
                this.lodgmentForm.amount = parseFloat(pc.cash_amount);
                this.lodgmentForm.date = pc.transaction_date;
                this.lodgmentForm.reference = 'CASH-DEP-' + pc.id;
            }
        },

        async confirmLodgment(id) {
            const fd = new FormData();
            fd.append('action', 'confirm_lodgment');
            fd.append('id', id);
            const res = await fetch('../ajax/audit_api.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) { location.reload(); } else { alert(data.message || 'Error'); }
        },

        async approveSalesPayment(txnId, type) {
            const label = type === 'pos' ? 'POS' : (type === 'transfer' ? 'Transfer' : 'Cash');
            if (!confirm('Approve ' + label + ' as bank lodgment?')) return;
            try {
                const fd = new FormData();
                fd.append('action', 'approve_sales_payment');
                fd.append('txn_id', txnId);
                fd.append('type', type);
                const res = await fetch('../ajax/audit_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Error approving');
                }
            } catch(e) { alert('Network error'); }
        },

        async signOff(role) {
            const fd = new FormData();
            fd.append('action', 'sign_off');
            fd.append('role', role);
            fd.append('comments', role === 'auditor' ? this.signoffForm.auditor_comments : this.signoffForm.manager_comments);
            const res = await fetch('../ajax/audit_api.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) { location.reload(); } else { alert(data.message || 'Error'); }
        },

        editSale(t) {
            this.editingTx = t;
            this.editForm = {
                id: t.id,
                transaction_date: t.transaction_date,
                shift: t.shift,
                outlet_id: t.outlet_id || '',
                pos_amount: parseFloat(t.pos_amount || 0),
                cash_amount: parseFloat(t.cash_amount || 0),
                transfer_amount: parseFloat(t.transfer_amount || 0),
                declared_total: parseFloat(t.declared_total || 0),
                notes: t.notes || ''
            };
            this.$nextTick(() => lucide.createIcons());
        },

        async updateSale() {
            this.saving = true;
            try {
                const fd = new FormData();
                fd.append('action', 'update_sales');
                fd.append('id', this.editForm.id);
                fd.append('date', this.editForm.transaction_date);
                fd.append('shift', this.editForm.shift);
                fd.append('outlet_id', this.editForm.outlet_id);
                fd.append('pos', this.editForm.pos_amount);
                fd.append('cash', this.editForm.cash_amount);
                fd.append('transfer', this.editForm.transfer_amount);
                fd.append('declared', this.editForm.declared_total);
                fd.append('notes', this.editForm.notes);
                const res = await fetch('../ajax/audit_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { location.reload(); } else { alert(data.message || 'Error'); }
            } catch(e) { alert('Network error'); }
            this.saving = false;
        },

        async deleteSale(id) {
            if (!confirm('Are you sure you want to delete this sales record? This action cannot be undone.')) return;
            try {
                const fd = new FormData();
                fd.append('action', 'delete_sales');
                fd.append('id', id);
                const res = await fetch('../ajax/audit_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { location.reload(); } else { alert(data.message || 'Error'); }
            } catch(e) { alert('Network error'); }
        },

        async deleteLodgment(id) {
            if (!confirm('Are you sure you want to delete this lodgment record?')) return;
            try {
                const fd = new FormData();
                fd.append('action', 'delete_lodgment');
                fd.append('id', id);
                const res = await fetch('../ajax/audit_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { location.reload(); } else { alert(data.message || 'Error'); }
            } catch(e) { alert('Network error'); }
        }
    }
}
</script>
<?php include '../includes/dashboard_scripts.php'; ?>
</body>
</html>
