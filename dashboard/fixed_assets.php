<?php
require_once '../includes/functions.php';
require_login(); require_subscription('finance'); require_permission('finance'); require_active_client();
$company_id = $_SESSION['company_id']; $client_id = get_active_client();
$client_name = $_SESSION['active_client_name'] ?? 'Client';
$page_title = 'Fixed Assets';
?>
<!DOCTYPE html>
<html lang="en" class="h-full"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fixed Assets — MIAUDITOPS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<style>
[x-cloak]{display:none!important}
.glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95),rgba(249,250,251,0.9));backdrop-filter:blur(20px)}
.dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95),rgba(30,41,59,0.9))}
.fa-input{width:100%;padding:7px 10px;font-size:12px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;transition:border-color .2s,box-shadow .2s}
.fa-input:focus{outline:none;border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(139,92,246,.12)}
.dark .fa-input{background:#1e293b;border-color:#334155;color:#e2e8f0}
.sched-cell{padding:7px 10px;font-size:10px;text-align:right;font-variant-numeric:tabular-nums;border-bottom:1px solid #f1f5f9}
.sched-label{padding:7px 12px;font-size:10px;text-align:left;border-bottom:1px solid #f1f5f9}
</style>
</head>
<body class="h-full bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-200">
<div class="flex h-full" x-data="fixedAssetsApp()" x-init="init(); $nextTick(()=>lucide.createIcons())">
<?php include '../includes/dashboard_sidebar.php'; ?>
<div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
<?php include '../includes/dashboard_header.php'; ?>
<main class="flex-1 overflow-y-auto p-6 lg:p-8"><?php display_flash_message(); ?>

<!-- Loading -->
<div x-show="loading" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,.6);backdrop-filter:blur(4px)">
<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-8 flex flex-col items-center gap-4 border border-slate-200 dark:border-slate-700">
<div class="w-12 h-12 rounded-full border-4 border-slate-200 border-t-violet-500 animate-spin"></div>
<p class="text-sm font-bold">Loading Assets...</p>
</div></div>

<!-- Page Header -->
<div class="mb-6">
<div class="flex items-center justify-between mb-3">
<div class="flex items-center gap-3">
<div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/25"><i data-lucide="package-check" class="w-5 h-5 text-white"></i></div>
<div><h2 class="text-xl font-black text-slate-900 dark:text-white">Fixed Assets</h2><p class="text-xs text-slate-400 mt-0.5">Asset register & depreciation schedule for <strong class="text-violet-600"><?php echo htmlspecialchars($client_name); ?></strong></p></div>
</div>
<div class="flex items-center gap-2">
<button @click="openAddAsset(); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-violet-600 to-purple-600 text-white text-xs font-bold rounded-xl shadow-lg shadow-violet-500/30 hover:-translate-y-0.5 transition-all"><i data-lucide="plus" class="w-4 h-4"></i> Add Asset</button>
<button @click="exportPDF()" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-xs font-bold rounded-xl shadow-md hover:shadow-lg transition-all"><i data-lucide="download" class="w-4 h-4"></i> Export PDF</button>
</div>
</div>
<div class="bg-violet-50 dark:bg-violet-900/20 border border-violet-100 dark:border-violet-800/40 rounded-xl p-4">
<p class="text-xs text-violet-700 dark:text-violet-300 leading-relaxed"><strong>📋 Fixed Asset Management</strong> — Register your company's fixed assets (land, buildings, equipment, vehicles, etc.) and the system will auto-generate a <strong>Fixed Asset Schedule</strong> showing cost, depreciation, and net book value grouped by category with configurable depreciation rates.</p>
</div>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 p-4 shadow-sm">
<p class="text-[9px] font-black text-slate-400 uppercase tracking-wider">Total Assets</p>
<p class="text-2xl font-black text-slate-900 dark:text-white mt-1" x-text="assets.length"></p>
</div>
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 p-4 shadow-sm">
<p class="text-[9px] font-black text-slate-400 uppercase tracking-wider">Total Cost</p>
<p class="text-lg font-black text-blue-600 mt-1" x-text="fmtShort(totalCost)"></p>
</div>
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 p-4 shadow-sm">
<p class="text-[9px] font-black text-slate-400 uppercase tracking-wider">Accum. Depreciation</p>
<p class="text-lg font-black text-red-600 mt-1" x-text="fmtShort(totalAccumDep)"></p>
</div>
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 p-4 shadow-sm">
<p class="text-[9px] font-black text-slate-400 uppercase tracking-wider">Net Book Value</p>
<p class="text-lg font-black text-emerald-600 mt-1" x-text="fmtShort(totalNBV)"></p>
</div>
</div>

<!-- Tabs -->
<div class="flex gap-1 p-1 bg-slate-100 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 mb-6 w-fit">
<button @click="currentTab='register'" :class="currentTab==='register'?'bg-white dark:bg-slate-900 text-violet-600 shadow-sm':'text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-xs font-bold rounded-lg transition-all">📋 Asset Register</button>
<button @click="currentTab='schedule'" :class="currentTab==='schedule'?'bg-white dark:bg-slate-900 text-violet-600 shadow-sm':'text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-xs font-bold rounded-lg transition-all">📊 Asset Schedule</button>
<button @click="currentTab='categories'" :class="currentTab==='categories'?'bg-white dark:bg-slate-900 text-violet-600 shadow-sm':'text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-xs font-bold rounded-lg transition-all">⚙️ Categories & Rates</button>
</div>

<!-- ═══ TAB 1: ASSET REGISTER ═══ -->
<div x-show="currentTab==='register'">
<div class="flex items-center gap-3 mb-4">
<select x-model="filterCat" class="px-3 py-2 text-xs font-bold border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 shadow-sm">
<option value="">All Categories</option>
<template x-for="c in categories" :key="c.id"><option :value="c.name" x-text="c.name"></option></template>
</select>
<span class="text-[10px] text-slate-400" x-text="filteredAssets.length + ' asset(s)'"></span>
</div>
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/60 dark:border-slate-700/60 overflow-hidden shadow-sm">
<div class="overflow-x-auto">
<table class="w-full text-left">
<thead><tr class="bg-gradient-to-r from-slate-800 to-slate-900">
<th class="px-4 py-3 text-[9px] font-black text-slate-300 uppercase tracking-wider">Asset Name</th>
<th class="px-3 py-3 text-[9px] font-black text-slate-300 uppercase tracking-wider">Code</th>
<th class="px-3 py-3 text-[9px] font-black text-slate-300 uppercase tracking-wider">Category</th>
<th class="px-3 py-3 text-[9px] font-black text-slate-300 uppercase tracking-wider">Purchase Date</th>
<th class="px-3 py-3 text-[9px] font-black text-slate-300 uppercase tracking-wider text-right">Cost</th>
<th class="px-3 py-3 text-[9px] font-black text-slate-300 uppercase tracking-wider text-right">NBV</th>
<th class="px-3 py-3 text-[9px] font-black text-slate-300 uppercase tracking-wider text-center">Status</th>
<th class="px-3 py-3 text-[9px] font-black text-slate-300 uppercase tracking-wider text-center">Actions</th>
</tr></thead>
<tbody>
<template x-for="a in filteredAssets" :key="a.id">
<tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
<td class="px-4 py-2.5"><p class="text-xs font-bold text-slate-900 dark:text-white" x-text="a.asset_name"></p><p class="text-[9px] text-slate-400" x-text="a.location||''"></p></td>
<td class="px-3 py-2.5 text-[10px] font-mono text-slate-500" x-text="a.asset_code||'—'"></td>
<td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-md bg-violet-50 dark:bg-violet-900/30 text-violet-600 text-[9px] font-bold" x-text="a.category"></span></td>
<td class="px-3 py-2.5 text-[10px] text-slate-500" x-text="a.purchase_date||'—'"></td>
<td class="px-3 py-2.5 text-[10px] font-bold text-right" x-text="fmt(a.cost)"></td>
<td class="px-3 py-2.5 text-[10px] font-bold text-right text-emerald-600" x-text="fmt(calcDepreciation(a, scheduleYear).nbv)"></td>
<td class="px-3 py-2.5 text-center"><span class="px-2 py-0.5 rounded-full text-[8px] font-bold uppercase" :class="a.status==='active'?'bg-emerald-100 text-emerald-700':a.status==='disposed'?'bg-red-100 text-red-700':'bg-amber-100 text-amber-700'" x-text="a.status"></span></td>
<td class="px-3 py-2.5 text-center">
<div class="flex items-center justify-center gap-1">
<button @click="openEditAsset(a); $nextTick(()=>lucide.createIcons())" class="p-1.5 rounded-md hover:bg-violet-50 dark:hover:bg-violet-900/20 text-slate-400 hover:text-violet-600 transition-colors"><i data-lucide="pencil" class="w-3 h-3"></i></button>
<button @click="deleteAsset(a.id)" class="p-1.5 rounded-md hover:bg-red-50 dark:hover:bg-red-900/20 text-slate-400 hover:text-red-600 transition-colors"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
</div>
</td>
</tr>
</template>
</tbody>
</table>
</div>
<div x-show="filteredAssets.length===0" class="text-center py-12"><p class="text-sm font-bold text-slate-400">No assets registered yet</p><p class="text-xs text-slate-400 mt-1">Click "Add Asset" to get started</p></div>
</div>
</div>

<!-- ═══ TAB 2: ASSET SCHEDULE ═══ -->
<div x-show="currentTab==='schedule'">
<div class="flex items-center gap-3 mb-4">
<label class="text-xs font-bold text-slate-500">Schedule Year-End:</label>
<input type="number" x-model.number="scheduleYear" min="2000" max="2099" class="w-24 px-3 py-2 text-sm font-bold border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 shadow-sm text-center">
<p class="text-[10px] text-slate-400">As at 31st December <span x-text="scheduleYear"></span></p>
</div>

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/60 dark:border-slate-700/60 overflow-hidden shadow-sm">
<div class="bg-gradient-to-r from-slate-800 to-slate-900 px-5 py-3">
<h3 class="text-xs font-black text-white uppercase tracking-wider">Fixed Asset Schedule As At 31st December <span x-text="scheduleYear"></span></h3>
</div>
<div class="overflow-x-auto">
<table class="w-full">
<thead><tr class="bg-slate-700">
<th class="sched-label text-[8px] font-black text-slate-300 uppercase tracking-wider" style="min-width:140px"></th>
<template x-for="c in categories" :key="c.id"><th class="sched-cell text-[8px] font-black text-slate-300 uppercase tracking-wider text-center" style="min-width:100px" x-text="c.name"></th></template>
<th class="sched-cell text-[8px] font-black text-amber-400 uppercase tracking-wider text-center" style="min-width:110px">TOTAL</th>
</tr></thead>
<tbody>
<!-- COST Section -->
<tr class="bg-black"><td :colspan="categories.length + 2" class="px-4 py-2 text-[9px] font-black text-amber-400 uppercase tracking-widest">Cost</td></tr>
<tr class="bg-slate-50 dark:bg-slate-800/30">
<td class="sched-label font-bold" x-text="'AS AT 1/1/' + scheduleYear"></td>
<template x-for="s in scheduleData" :key="'oc'+s.category"><td class="sched-cell font-semibold" x-text="s.openingCost ? fmt(s.openingCost) : '—'"></td></template>
<td class="sched-cell font-black" x-text="fmt(scheduleTotal('openingCost'))"></td>
</tr>
<tr>
<td class="sched-label" style="padding-left:20px">Additions</td>
<template x-for="s in scheduleData" :key="'ad'+s.category"><td class="sched-cell" x-text="s.additions ? fmt(s.additions) : '—'"></td></template>
<td class="sched-cell font-bold" x-text="fmt(scheduleTotal('additions'))"></td>
</tr>
<tr>
<td class="sched-label" style="padding-left:20px">Disposal</td>
<template x-for="s in scheduleData" :key="'di'+s.category"><td class="sched-cell" x-text="s.disposals ? fmt(s.disposals) : '0'"></td></template>
<td class="sched-cell font-bold" x-text="fmt(scheduleTotal('disposals'))"></td>
</tr>
<tr class="bg-slate-100 dark:bg-slate-800/50 border-t-2 border-slate-300">
<td class="sched-label font-black" x-text="'AS AT 31/12/' + scheduleYear"></td>
<template x-for="s in scheduleData" :key="'cc'+s.category"><td class="sched-cell font-black" x-text="fmt(s.closingCost)"></td></template>
<td class="sched-cell font-black text-blue-700 dark:text-blue-400" x-text="fmt(scheduleTotal('closingCost'))"></td>
</tr>

<!-- DEPRECIATION Section -->
<tr class="bg-black"><td :colspan="categories.length + 2" class="px-4 py-2 text-[9px] font-black text-amber-400 uppercase tracking-widest">Depreciation</td></tr>
<tr class="bg-slate-50 dark:bg-slate-800/30">
<td class="sched-label font-bold" x-text="'AS AT 1/1/' + scheduleYear"></td>
<template x-for="s in scheduleData" :key="'od'+s.category"><td class="sched-cell font-semibold" x-text="s.openingDep ? fmt(s.openingDep) : '—'"></td></template>
<td class="sched-cell font-black" x-text="fmt(scheduleTotal('openingDep'))"></td>
</tr>
<tr>
<td class="sched-label" style="padding-left:20px">For the Year</td>
<template x-for="s in scheduleData" :key="'yd'+s.category"><td class="sched-cell" x-text="s.yearDep ? fmt(s.yearDep) : '0'"></td></template>
<td class="sched-cell font-bold" x-text="fmt(scheduleTotal('yearDep'))"></td>
</tr>
<tr class="bg-slate-100 dark:bg-slate-800/50 border-t-2 border-slate-300">
<td class="sched-label font-black" x-text="'AS AT 31/12/' + scheduleYear"></td>
<template x-for="s in scheduleData" :key="'cd'+s.category"><td class="sched-cell font-black" x-text="fmt(s.closingDep)"></td></template>
<td class="sched-cell font-black text-red-600" x-text="fmt(scheduleTotal('closingDep'))"></td>
</tr>

<!-- Spacer -->
<tr><td :colspan="categories.length + 2" style="height:12px;border:none"></td></tr>

<!-- NET BOOK VALUE -->
<tr class="bg-black">
<td class="px-4 py-2.5 text-[10px] font-black text-white uppercase tracking-wider" x-text="'AS AT 31/12/' + scheduleYear"></td>
<template x-for="s in scheduleData" :key="'nv'+s.category"><td class="sched-cell font-black text-emerald-400 dark:text-emerald-400" style="background:#000;border-color:#1e293b;color:#34d399" x-text="fmt(s.closingNBV)"></td></template>
<td class="sched-cell font-black" style="background:#000;border-color:#1e293b;color:#34d399" x-text="fmt(scheduleTotal('closingNBV'))"></td>
</tr>
<tr class="bg-slate-50 dark:bg-slate-800/30">
<td class="sched-label font-bold" x-text="'AS AT 31/12/' + (scheduleYear - 1)"></td>
<template x-for="s in scheduleData" :key="'pv'+s.category"><td class="sched-cell font-semibold" x-text="s.prevNBV ? fmt(s.prevNBV) : '—'"></td></template>
<td class="sched-cell font-black" x-text="fmt(scheduleTotal('prevNBV'))"></td>
</tr>

<!-- RATES -->
<tr class="bg-slate-50 dark:bg-slate-800/30 border-t-2 border-slate-200">
<td class="sched-label font-bold text-slate-500 italic">RATES</td>
<template x-for="s in scheduleData" :key="'rt'+s.category"><td class="sched-cell font-bold text-slate-500 italic" x-text="s.rate > 0 ? s.rate + '%' : 'N/A'"></td></template>
<td class="sched-cell"></td>
</tr>
</tbody>
</table>
</div>
</div>
</div>

<!-- ═══ TAB 3: CATEGORIES & RATES ═══ -->
<div x-show="currentTab==='categories'">
<div class="flex items-center justify-between mb-4">
<p class="text-xs text-slate-400">Define asset categories and their annual depreciation rates. Assets will be grouped into these columns on the schedule.</p>
<button @click="openAddCat(); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-2 px-4 py-2 bg-violet-600 text-white text-xs font-bold rounded-xl hover:bg-violet-700 transition-colors"><i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Category</button>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
<template x-for="c in categories" :key="c.id">
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/60 dark:border-slate-700/60 p-5 shadow-sm hover:shadow-md transition-all">
<div class="flex items-start justify-between mb-3">
<h4 class="text-sm font-black text-slate-900 dark:text-white" x-text="c.name"></h4>
<div class="flex items-center gap-1">
<button @click="openEditCat(c); $nextTick(()=>lucide.createIcons())" class="p-1.5 rounded-md hover:bg-violet-50 text-slate-400 hover:text-violet-600 transition-colors"><i data-lucide="pencil" class="w-3 h-3"></i></button>
<button @click="deleteCat(c.id)" class="p-1.5 rounded-md hover:bg-red-50 text-slate-400 hover:text-red-600 transition-colors"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
</div>
</div>
<div class="flex items-center gap-4">
<div><p class="text-[9px] font-bold text-slate-400 uppercase">Dep. Rate</p><p class="text-xl font-black" :class="+c.dep_rate > 0 ? 'text-orange-600' : 'text-slate-400'" x-text="(+c.dep_rate > 0 ? c.dep_rate + '%' : 'N/A')"></p></div>
<div><p class="text-[9px] font-bold text-slate-400 uppercase">Assets</p><p class="text-xl font-black text-violet-600" x-text="assets.filter(a=>a.category===c.name).length"></p></div>
</div>
</div>
</template>
</div>
</div>

<!-- ═══ ASSET MODAL ═══ -->
<div x-show="showAssetModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-6 w-full max-w-lg border border-slate-200 dark:border-slate-700 max-h-[90vh] overflow-y-auto" @click.away="showAssetModal=false">
<div class="flex items-center gap-3 mb-5">
<div class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center"><i data-lucide="package" class="w-4 h-4 text-white"></i></div>
<h3 class="text-lg font-black" x-text="editingAsset ? 'Edit Asset' : 'Add New Asset'"></h3>
</div>
<div class="space-y-3">
<div class="grid grid-cols-2 gap-3">
<div class="col-span-2"><label class="text-xs font-bold text-slate-500 mb-1 block">Asset Name *</label><input x-model="form.asset_name" class="fa-input" placeholder="e.g. Diesel Generator 100KVA"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Asset Code</label><input x-model="form.asset_code" class="fa-input" placeholder="e.g. FA-001"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Category</label>
<select x-model="form.category" class="fa-input"><template x-for="c in categories" :key="c.id"><option :value="c.name" x-text="c.name"></option></template></select></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Purchase Date</label><input type="date" x-model="form.purchase_date" class="fa-input"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Cost (₦)</label><input type="number" step="0.01" x-model.number="form.cost" class="fa-input"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Salvage Value (₦)</label><input type="number" step="0.01" x-model.number="form.salvage_value" class="fa-input"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Serial Number</label><input x-model="form.serial_number" class="fa-input" placeholder="Optional"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Location</label><input x-model="form.location" class="fa-input" placeholder="e.g. Head Office"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Status</label>
<select x-model="form.status" class="fa-input"><option value="active">Active</option><option value="disposed">Disposed</option><option value="maintenance">Under Maintenance</option></select></div>
<template x-if="form.status==='disposed'">
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Disposal Date</label><input type="date" x-model="form.disposal_date" class="fa-input"></div>
</template>
<template x-if="form.status==='disposed'">
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Disposal Amount (₦)</label><input type="number" step="0.01" x-model.number="form.disposal_amount" class="fa-input"></div>
</template>
<div class="col-span-2"><label class="text-xs font-bold text-slate-500 mb-1 block">Notes</label><textarea x-model="form.notes" rows="2" class="fa-input" placeholder="Optional notes"></textarea></div>
</div>
</div>
<div class="flex justify-end gap-2 mt-5">
<button @click="showAssetModal=false" class="px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-700 rounded-lg">Cancel</button>
<button @click="saveAsset()" :disabled="saving" class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-purple-600 text-white text-xs font-bold rounded-xl hover:shadow-lg transition-all disabled:opacity-50" x-text="saving ? 'Saving...' : (editingAsset ? 'Update Asset' : 'Add Asset')"></button>
</div>
</div>
</div>

<!-- ═══ CATEGORY MODAL ═══ -->
<div x-show="showCatModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-6 w-full max-w-sm border border-slate-200 dark:border-slate-700" @click.away="showCatModal=false">
<h3 class="text-lg font-black mb-4" x-text="editingCat ? 'Edit Category' : 'Add Category'"></h3>
<div class="space-y-3">
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Category Name *</label><input x-model="catForm.name" class="fa-input" placeholder="e.g. Motor Vehicles"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Depreciation Rate (%)</label><input type="number" step="0.5" x-model.number="catForm.dep_rate" class="fa-input" placeholder="e.g. 20"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Sort Order</label><input type="number" x-model.number="catForm.sort_order" class="fa-input"></div>
</div>
<div class="flex justify-end gap-2 mt-5">
<button @click="showCatModal=false" class="px-4 py-2 text-xs font-bold text-slate-500 rounded-lg">Cancel</button>
<button @click="saveCat()" :disabled="saving" class="px-5 py-2.5 bg-violet-600 text-white text-xs font-bold rounded-xl disabled:opacity-50">Save</button>
</div>
</div>
</div>

</main></div></div>
<script src="fixed_assets_app.js"></script>
<script>document.addEventListener('DOMContentLoaded', () => lucide.createIcons());</script>
</body></html>
