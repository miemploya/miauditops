<?php
require_once '../includes/functions.php';
require_login(); require_subscription('finance'); require_permission('finance'); require_active_client();
$company_id = $_SESSION['company_id']; $client_id = get_active_client();
$client_name = $_SESSION['active_client_name'] ?? 'Client';
$page_title = 'Create P&L';
$months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
?>
<!DOCTYPE html>
<html lang="en" class="h-full"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create P&L — MIAUDITOPS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}}</script>
<style>
[x-cloak]{display:none!important}
.glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95),rgba(249,250,251,0.9));backdrop-filter:blur(20px)}
.dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95),rgba(30,41,59,0.9))}
.pnl-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px}
.pnl-row.dotted{border-bottom:1px dotted #cbd5e1}.dark .pnl-row.dotted{border-bottom-color:#334155}
.pnl-row.total{font-weight:800;border-top:2px solid #1e293b;padding-top:10px;margin-top:6px}
.dark .pnl-row.total{border-top-color:#94a3b8}
.pnl-row.subtotal{font-weight:700;border-top:1px solid #e2e8f0;margin-top:4px;padding-top:8px}
.dark .pnl-row.subtotal{border-top-color:#475569}
@media print{nav,aside,header,.print-hidden,#collapsed-toolbar,#mobile-menu-btn,.dashboard-header,.dashboard-sidebar,.main-nav,#viewer-banner{display:none!important}main{margin:0!important;padding:0!important}@page{margin:15mm 12mm;size:A4}}
</style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="pnlApp()" x-cloak data-client-name="<?= htmlspecialchars($client_name) ?>">
<div class="flex h-screen w-full">
<?php include '../includes/dashboard_sidebar.php'; ?>
<div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
<?php include '../includes/dashboard_header.php'; ?>
<main class="flex-1 overflow-y-auto p-6 lg:p-8"><?php display_flash_message(); ?>

<!-- Loading Overlay -->
<div x-show="loadingReport" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.6);backdrop-filter:blur(4px)">
<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-8 flex flex-col items-center gap-4 border border-slate-200 dark:border-slate-700">
<div class="w-12 h-12 rounded-full border-4 border-slate-200 border-t-amber-500 animate-spin"></div>
<div class="text-center">
<p class="text-sm font-bold text-slate-900 dark:text-white">Loading Report</p>
<p class="text-xs text-slate-400 mt-1">Fetching periods, revenue, expenses & stock data...</p>
</div>
<div class="w-48 h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden"><div class="h-full bg-gradient-to-r from-amber-500 to-orange-500 rounded-full animate-pulse" style="width:80%"></div></div>
</div>
</div>

<!-- Tabs -->
<div class="mb-6 flex flex-wrap gap-1.5 p-1.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 print-hidden">
<template x-for="t in tabs" :key="t.id"><button @click="switchTab(t.id)" :class="currentTab===t.id?'bg-white dark:bg-slate-900 text-emerald-600 shadow-sm border-emerald-200':'text-slate-500 hover:bg-white/50 border-transparent'" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all border"><i :data-lucide="t.icon" class="w-3.5 h-3.5"></i><span x-text="t.label"></span></button></template>
</div>

<!-- Period Selector (shown on Revenue/COS/Expenses tabs) -->
<template x-if="['revenue','cos','expenses'].includes(currentTab) && activeReport && periods.length">
<div class="mb-4 flex flex-wrap gap-1.5 p-1 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 print-hidden">
<template x-for="p in periods" :key="p.id"><button @click="selectPeriod(p.id)" :class="activePeriodId==p.id?'bg-emerald-500 text-white shadow':'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'" class="px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all" x-text="p.date_from+' → '+p.date_to"></button></template>
</div>
</template>

<!-- TAB: Reports -->
<div x-show="currentTab==='reports'" x-transition>
<div class="flex items-center justify-between mb-5">
<div><h2 class="text-lg font-black text-slate-900 dark:text-white">P&L Reports</h2><p class="text-xs text-slate-400">Manage P&L for <span class="font-bold text-emerald-500" x-text="clientName"></span></p></div>
<button @click="showCreateModal=true" class="px-4 py-2 bg-gradient-to-r from-emerald-500 to-green-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all flex items-center gap-2"><i data-lucide="plus" class="w-4 h-4"></i> Create New P&L</button>
</div>
<!-- Rollup Filter Bar -->
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg p-4 mb-5">
<div class="flex flex-wrap items-center gap-3">
<div class="flex items-center gap-1.5">
<span class="text-[10px] font-black text-slate-500 uppercase tracking-wider">Rollup:</span>
<template x-for="pt in ['Q1','Q2','Q3','Q4','Annual']" :key="pt">
<button @click="rollupType=pt" :class="rollupType===pt ? 'bg-black text-amber-400 shadow' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:bg-slate-200'" class="px-3 py-1.5 rounded-lg text-[10px] font-bold transition-all" x-text="pt"></button>
</template>
</div>
<div class="flex items-center gap-1.5">
<span class="text-[10px] font-black text-slate-500 uppercase tracking-wider">Year:</span>
<input type="number" x-model.number="rollupYear" min="2020" max="2099" class="w-20 px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-bold text-center">
</div>
<button @click="generateRollupPDF()" :disabled="rollupLoading" class="ml-auto px-4 py-2 bg-gradient-to-r from-amber-500 to-orange-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all flex items-center gap-2 disabled:opacity-50">
<i data-lucide="file-bar-chart" class="w-4 h-4"></i>
<span x-text="rollupLoading ? 'Generating...' : 'Generate Rollup PDF'"></span>
</button>
</div>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
<template x-for="r in reports" :key="r.id"><div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden hover:shadow-xl transition-all cursor-pointer" @click="openReport(r.id)">
<div class="p-5"><div class="flex items-start justify-between mb-3"><div><p class="text-sm font-black text-slate-900 dark:text-white" x-text="r.title||r.client_name"></p><p class="text-[10px] text-slate-400 mt-0.5" x-text="r.location||''"></p></div><span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase" :class="r.status==='finalized'?'bg-emerald-100 text-emerald-700':'bg-amber-100 text-amber-700'" x-text="r.status"></span></div>
<div class="flex flex-wrap gap-2"><span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-600 text-[10px] font-bold" x-text="r.industry"></span><span class="px-2 py-0.5 rounded-md bg-violet-50 text-violet-600 text-[10px] font-bold" x-text="monthNames[r.report_month-1]+' '+r.report_year"></span><span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-500 text-[10px] font-bold" x-text="(r.period_count||0)+' periods'"></span></div></div>
<div class="px-5 py-2 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between"><span class="text-[10px] text-slate-400" x-text="'By '+(r.first_name||'')"></span><button @click.stop="deleteReport(r.id)" class="p-1 text-slate-400 hover:text-red-500"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></div>
</div></template>
</div>
<div x-show="reports.length===0" class="text-center py-20"><div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center"><i data-lucide="file-spreadsheet" class="w-8 h-8 text-slate-300"></i></div><p class="text-sm font-bold text-slate-400">No P&L reports yet</p></div>
</div>

<!-- TAB: Periods -->
<div x-show="currentTab==='periods'" x-transition>
<div x-show="!activeReport" class="text-center py-20"><p class="text-slate-400">Select a report first</p></div>
<template x-if="activeReport"><div>
<div class="flex items-center justify-between mb-5"><div><h2 class="text-lg font-black text-slate-900 dark:text-white">Date Periods</h2><p class="text-xs text-slate-400" x-text="activeReport.title+' — '+monthNames[activeReport.report_month-1]+' '+activeReport.report_year"></p></div></div>
<!-- Add Period Form -->
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg p-5 mb-5" x-show="activeReport.status==='draft'">
<h3 class="text-sm font-bold text-slate-700 dark:text-white mb-3">Add New Period</h3>
<div class="flex flex-wrap items-end gap-3">
<div><label class="text-[10px] font-bold text-slate-500 block mb-1">From</label><input type="date" x-model="newPeriod.from" class="px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
<div><label class="text-[10px] font-bold text-slate-500 block mb-1">To</label><input type="date" x-model="newPeriod.to" class="px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
<button @click="createPeriod()" :disabled="saving" class="px-5 py-2 bg-gradient-to-r from-emerald-500 to-green-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all disabled:opacity-50"><i data-lucide="plus" class="w-4 h-4 inline"></i> Add Period</button>
</div>
</div>
<!-- Periods List -->
<div class="space-y-3">
<template x-for="(p,i) in periods" :key="p.id"><div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
<div class="flex items-center justify-between px-5 py-4">
<div class="flex items-center gap-4"><div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center shadow text-white font-black text-sm" x-text="i+1"></div><div>
<div class="flex items-center gap-1.5"><input type="date" x-model="p.date_from" @change="updatePeriod(p)" class="px-2 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-bold w-32"><span class="text-xs text-slate-400">→</span><input type="date" x-model="p.date_to" @change="updatePeriod(p)" class="px-2 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-bold w-32"></div>
<p class="text-[10px] text-slate-400 mt-0.5">Period <span x-text="i+1"></span></p></div></div>
<div class="flex items-center gap-2">
<button @click="selectPeriod(p.id);switchTab('revenue')" class="px-3 py-1.5 bg-emerald-100 text-emerald-700 text-[10px] font-bold rounded-lg hover:bg-emerald-200 transition-all">Enter Data →</button>
<button @click="deletePeriod(p.id)" class="p-1.5 text-slate-400 hover:text-red-500"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
</div></div>
</div></template>
</div>
<div x-show="periods.length===0" class="text-center py-12 mt-4"><p class="text-sm text-slate-400">No periods yet. Add one above to start entering data.</p></div>
</div></template>
</div>

<!-- TAB: Revenue -->
<div x-show="currentTab==='revenue'" x-transition>
<div x-show="!activeReport||!activePeriodId" class="text-center py-20"><p class="text-slate-400" x-text="!activeReport?'Select a report first':'Select a period above'"></p></div>
<template x-if="activeReport&&activePeriodId"><div>
<div class="flex items-center justify-between mb-5"><div><h2 class="text-lg font-black text-slate-900 dark:text-white">Revenue Entry</h2><p class="text-xs text-slate-400" x-text="periodLabel()"></p></div>
<button @click="saveRevenue()" :disabled="saving" class="px-5 py-2 bg-gradient-to-r from-emerald-500 to-green-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all disabled:opacity-50 flex items-center gap-2"><i data-lucide="save" class="w-4 h-4"></i> <span x-text="saving?'Saving...':'Save Revenue'"></span></button></div>
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
<div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500/10 to-transparent flex items-center gap-3"><div class="w-8 h-8 rounded-xl bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center shadow"><i data-lucide="trending-up" class="w-4 h-4 text-white"></i></div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Revenue Line Items</h3></div>
<div class="p-6 space-y-3">
<template x-for="(item,i) in revenueItems" :key="i"><div class="flex items-center gap-3"><input type="text" x-model="item.label" class="flex-1 px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm" placeholder="Label"><div class="relative w-48"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">₦</span><input type="number" step="0.01" x-model.number="item.amount" class="w-full pl-7 pr-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm text-right" placeholder="0.00"></div><button @click="revenueItems.splice(i,1)" class="p-2 text-slate-400 hover:text-red-500"><i data-lucide="x" class="w-4 h-4"></i></button></div></template>
<button @click="revenueItems.push({label:'',amount:0})" class="flex items-center gap-2 text-emerald-600 text-xs font-bold mt-3"><i data-lucide="plus-circle" class="w-4 h-4"></i> Add Revenue Line</button>
<div class="mt-4 pt-4 border-t-2 border-slate-200 dark:border-slate-700 flex justify-between"><span class="text-sm font-black">Total Revenue</span><span class="text-sm font-black text-emerald-600" x-text="fmt(revenueItems.reduce((s,i)=>s+(+i.amount||0),0))"></span></div>
</div></div>
</div></template>
</div>

<!-- TAB: Cost of Sales -->
<div x-show="currentTab==='cos'" x-transition>
<div x-show="!activeReport||!activePeriodId" class="text-center py-20"><p class="text-slate-400" x-text="!activeReport?'Select a report first':'Select a period above'"></p></div>
<template x-if="activeReport&&activePeriodId"><div>
<div class="flex items-center justify-between mb-5"><div><h2 class="text-lg font-black text-slate-900 dark:text-white">Cost of Sales</h2><p class="text-xs text-slate-400" x-text="periodLabel()"></p></div>
<button @click="saveCOS()" :disabled="saving" class="px-5 py-2 bg-gradient-to-r from-orange-500 to-amber-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all disabled:opacity-50 flex items-center gap-2"><i data-lucide="save" class="w-4 h-4"></i> <span x-text="saving?'Saving...':'Save COS'"></span></button></div>

<!-- Opening Stock -->
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden mb-5">
<div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-slate-500/10 to-transparent flex items-center gap-3">
<div class="w-8 h-8 rounded-xl bg-gradient-to-br from-slate-500 to-slate-700 flex items-center justify-center shadow"><i data-lucide="archive" class="w-4 h-4 text-white"></i></div>
<h3 class="font-bold text-slate-900 dark:text-white text-sm">Opening Stock</h3>
<template x-if="openingLocked"><span class="ml-auto px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-[10px] font-black flex items-center gap-1"><i data-lucide="lock" class="w-3 h-3"></i> Locked — Edit in first period</span></template>
</div>
<div class="p-5 space-y-2" :class="openingLocked ? 'opacity-60 pointer-events-none' : ''">
<template x-for="(item,i) in cosOpening" :key="'co'+i"><div class="flex items-center gap-3">
<input type="text" x-model="item.label" :disabled="openingLocked" class="flex-1 px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm" placeholder="Item">
<div class="relative w-44"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="item.amount" :disabled="openingLocked" class="w-full pl-7 pr-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm text-right"></div>
<button x-show="!openingLocked" @click="cosOpening.splice(i,1)" class="p-1.5 text-slate-400 hover:text-red-500"><i data-lucide="x" class="w-4 h-4"></i></button>
</div></template>
<button x-show="!openingLocked" @click="cosOpening.push({label:'',amount:0,entry_type:'opening'})" class="flex items-center gap-2 text-slate-600 text-xs font-bold mt-2"><i data-lucide="plus-circle" class="w-4 h-4"></i> Add Opening Item</button>
<div class="mt-3 pt-3 border-t-2 border-slate-200 dark:border-slate-700 flex justify-between"><span class="text-sm font-black">Total Opening Stock</span><span class="text-sm font-black text-slate-700 dark:text-slate-300" x-text="fmt(cosOpening.reduce((s,i)=>s+(+i.amount||0),0))"></span></div>
</div></div>

<!-- Purchases Breakdown -->
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden mb-5">
<div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 to-transparent flex items-center gap-3"><div class="w-8 h-8 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow"><i data-lucide="shopping-cart" class="w-4 h-4 text-white"></i></div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Purchases Breakdown</h3></div>
<div class="p-5 space-y-1">
<template x-for="(item,i) in cosPurchases" :key="'cp'+i"><div class="border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
<div class="flex items-center gap-2 px-3 py-2 bg-slate-50 dark:bg-slate-800/50">
<button @click="item._open=!item._open" class="p-0.5 text-slate-400 hover:text-amber-500"><i :data-lucide="item._open?'chevron-down':'chevron-right'" class="w-3.5 h-3.5"></i></button>
<input type="text" x-model="item.label" class="flex-1 px-2 py-1 bg-transparent border-0 text-sm font-semibold focus:outline-none" placeholder="Purchase category">
<span class="text-xs font-black text-amber-600 mr-1" x-text="fmt(cosItemTotal(item))"></span>
<span class="text-[9px] text-slate-400 mr-1" x-text="(item.sub_entries||[]).length+'x'"></span>
<button @click="cosPurchases.splice(i,1)" class="p-1 text-slate-300 hover:text-red-500"><i data-lucide="x" class="w-3 h-3"></i></button>
</div>
<div x-show="item._open" x-transition class="px-3 py-2 space-y-1.5 bg-white dark:bg-slate-900">
<template x-for="(sub,j) in item.sub_entries" :key="'cps'+i+'_'+j"><div class="flex items-center gap-2">
<input type="text" x-model="sub.note" placeholder="Description" class="flex-1 px-2 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
<div class="relative w-32"><span class="absolute left-2 top-1/2 -translate-y-1/2 text-[10px] text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="sub.amount" class="w-full pl-5 pr-2 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs text-right"></div>
<button @click="item.sub_entries.splice(j,1)" class="p-0.5 text-slate-300 hover:text-red-500"><i data-lucide="minus-circle" class="w-3 h-3"></i></button>
</div></template>
<button @click="item.sub_entries.push({note:'',amount:0})" class="flex items-center gap-1 text-amber-500 text-[10px] font-bold mt-1"><i data-lucide="plus" class="w-3 h-3"></i> Add Entry</button>
</div>
</div></template>
<button @click="cosPurchases.push({label:'',sub_entries:[{note:'',amount:0}],entry_type:'purchase',_open:true})" class="flex items-center gap-2 text-amber-600 text-xs font-bold mt-2"><i data-lucide="plus-circle" class="w-4 h-4"></i> Add Purchase Category</button>
<div class="mt-3 pt-3 border-t-2 border-slate-200 dark:border-slate-700 flex justify-between"><span class="text-sm font-black">Total Purchases</span><span class="text-sm font-black text-amber-600" x-text="fmt(cosPurchases.reduce((s,i)=>s+cosItemTotal(i),0))"></span></div>
</div></div>

<!-- Closing Stock Valuation (Catalog-Based) -->
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden mb-5">
<div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-teal-500/10 to-transparent flex items-center justify-between">
<div class="flex items-center gap-3"><div class="w-8 h-8 rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 flex items-center justify-center shadow"><i data-lucide="clipboard-list" class="w-4 h-4 text-white"></i></div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Closing Stock Valuation</h3></div>
<button @click="showCatalogEditor=!showCatalogEditor;$nextTick(()=>lucide.createIcons())" class="px-3 py-1 text-[10px] font-bold rounded-lg transition-all" :class="showCatalogEditor?'bg-teal-500 text-white':'bg-teal-100 text-teal-700 hover:bg-teal-200'"><i data-lucide="settings" class="w-3 h-3 inline"></i> <span x-text="showCatalogEditor?'Done Editing':'Manage Catalog'"></span></button>
</div>
<!-- Catalog Editor -->
<div x-show="showCatalogEditor" x-transition class="p-4 bg-teal-50/50 dark:bg-teal-900/20 border-b border-teal-200 dark:border-teal-800">
<p class="text-[10px] text-teal-700 dark:text-teal-400 font-bold mb-2 uppercase tracking-wider">Stock Catalog (persists across all reports for this client)</p>
<div class="grid gap-1 text-[8px] font-bold text-slate-500 uppercase mb-1 px-1" style="grid-template-columns:2fr 1.5fr 3fr 2fr 1.5fr 24px"><span>Department</span><span>Category</span><span>Item Name</span><span class="text-center">Counting Unit</span><span class="text-right">Unit Cost</span><span></span></div>
<div class="space-y-1.5">
<template x-for="(cat,i) in stockCatalog" :key="'cat'+i"><div class="grid gap-1 items-center" style="grid-template-columns:2fr 1.5fr 3fr 2fr 1.5fr 24px">
<input type="text" x-model="cat.department" class="px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs" placeholder="e.g. Bar" list="deptList">
<input type="text" x-model="cat.category" class="px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs" placeholder="e.g. Drinks" list="catList">
<input type="text" x-model="cat.item_name" class="px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs" placeholder="Item name">
<div class="flex items-center gap-1">
<select @change="if($event.target.value==='pieces'){cat.pack_size=1}else{cat.pack_size=Math.max(2,+cat.pack_size||12)}" x-model="cat._unitType" x-init="cat._unitType=(+cat.pack_size||1)>1?'packs':'pieces'" class="px-1 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-[10px] font-bold">
<option value="pieces">Pieces</option><option value="packs">Packs</option>
</select>
<input x-show="cat._unitType==='packs'" type="number" min="2" step="1" x-model.number="cat.pack_size" class="w-12 px-1 py-1.5 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 rounded text-[10px] text-center font-bold" placeholder="12">
</div>
<div class="relative"><span class="absolute left-2 top-1/2 -translate-y-1/2 text-[10px] text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="cat.unit_cost" class="w-full pl-5 pr-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs text-right" placeholder="Cost"></div>
<button @click="stockCatalog.splice(i,1)" class="p-1 text-slate-300 hover:text-red-500 flex justify-center"><i data-lucide="x" class="w-3 h-3"></i></button>
</div></template>
</div>
<!-- Datalists for autocomplete -->
<datalist id="deptList"><template x-for="d in [...new Set(stockCatalog.map(c=>c.department).filter(Boolean))]"><option :value="d"></option></template></datalist>
<datalist id="catList"><template x-for="c in [...new Set(stockCatalog.map(c=>c.category).filter(Boolean))]"><option :value="c"></option></template></datalist>
<div class="flex flex-wrap gap-2 mt-2">
<button @click="stockCatalog.push({item_name:'',unit_cost:0,department:'',category:'',pack_size:1})" class="flex items-center gap-1 text-teal-600 text-[10px] font-bold"><i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> Add Item</button>
<button x-show="catalogDepartments().length" @click="showCopyDeptModal=!showCopyDeptModal;$nextTick(()=>lucide.createIcons())" class="flex items-center gap-1 text-violet-600 text-[10px] font-bold"><i data-lucide="copy" class="w-3.5 h-3.5"></i> Copy from Dept</button>
<button @click="downloadCatalogTemplate()" class="flex items-center gap-1 text-blue-600 text-[10px] font-bold"><i data-lucide="download" class="w-3.5 h-3.5"></i> Download Template</button>
<button @click="importCatalogCSV()" class="flex items-center gap-1 text-amber-600 text-[10px] font-bold"><i data-lucide="upload" class="w-3.5 h-3.5"></i> Import CSV</button>
<button @click="saveCatalog()" class="ml-auto px-3 py-1 bg-teal-500 text-white text-[10px] font-bold rounded-lg hover:bg-teal-600 transition-all" :disabled="saving" x-text="saving?'Saving...':'Save Catalog'"></button>
</div>
<!-- Copy from Department Panel -->
<div x-show="showCopyDeptModal" x-transition class="mt-2 p-3 bg-violet-50 dark:bg-violet-900/20 border border-violet-200 dark:border-violet-700 rounded-xl">
<p class="text-[10px] font-bold text-violet-700 dark:text-violet-400 uppercase tracking-wider mb-2">Copy items from an existing department</p>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
<div>
<label class="text-[9px] font-bold text-slate-500 mb-0.5 block">Source Department</label>
<select x-model="copyDeptSource" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-violet-300 dark:border-violet-700 rounded-lg text-xs font-semibold">
<option value="">— Select —</option>
<template x-for="d in catalogDepartments()" :key="d.name"><option :value="d.name" x-text="d.name+' ('+d.count+' items)'"></option></template>
</select>
</div>
<div>
<label class="text-[9px] font-bold text-slate-500 mb-0.5 block">New Department Name</label>
<input type="text" x-model="copyDeptTarget" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-violet-300 dark:border-violet-700 rounded-lg text-xs font-semibold" placeholder="e.g. KITCHEN">
</div>
<div class="flex items-end">
<button @click="copyFromDepartment()" class="w-full px-3 py-1.5 bg-gradient-to-r from-violet-500 to-purple-600 text-white text-[10px] font-bold rounded-lg hover:shadow-lg transition-all">Copy Items</button>
</div>
</div>
</div>
</div>
<!-- Count Table (grouped by department) -->
<div class="p-5">
<template x-for="dept in (()=>{let ds=[...new Set(cosClosing.map(i=>i.department||'Uncategorized'))]; return ds;})()"><div class="mb-4">
<div @click="collapsedDepts[dept]=!collapsedDepts[dept];$nextTick(()=>lucide.createIcons())" class="flex items-center gap-2 mb-2 border-b border-teal-200 dark:border-teal-800 pb-1 cursor-pointer hover:bg-teal-50 dark:hover:bg-teal-900/30 rounded px-1 -mx-1 transition-all select-none">
<i :data-lucide="collapsedDepts[dept]?'chevron-right':'chevron-down'" class="w-3.5 h-3.5 text-teal-500 transition-transform"></i>
<div class="w-5 h-5 rounded bg-teal-500/20 flex items-center justify-center"><i data-lucide="folder" class="w-3 h-3 text-teal-600"></i></div>
<span class="text-[11px] font-black text-teal-700 dark:text-teal-400 uppercase tracking-wider" x-text="dept"></span>
<span class="text-[9px] text-slate-400 ml-1" x-text="'('+cosClosing.filter(i=>(i.department||'Uncategorized')===dept).length+' items)'"></span>
<span class="text-[9px] font-bold text-teal-600 ml-auto" x-text="fmt(cosClosing.filter(i=>(i.department||'Uncategorized')===dept).reduce((s,i)=>s+((+i.qty||0)*(+i.unit_cost||0)),0))"></span>
</div>
<div x-show="!collapsedDepts[dept]" x-transition.duration.200ms>
<template x-for="cat in (()=>{let cs=[...new Set(cosClosing.filter(i=>(i.department||'Uncategorized')===dept).map(i=>i.category||''))]; return cs;})()"><div>
<div x-show="cat" class="text-[9px] font-bold text-slate-400 uppercase tracking-wider px-1 mb-1 mt-1" x-text="cat"></div>
<template x-for="(item,i) in cosClosing.filter(it=>(it.department||'Uncategorized')===dept&&(it.category||'')===cat)" :key="'cc'+dept+cat+i">
<!-- Pieces-only items (pack_size=1) -->
<div x-show="(+item.pack_size||1)<=1" class="grid gap-1.5 items-center py-1.5 border-b border-slate-100 dark:border-slate-800" style="grid-template-columns:3.5fr 1.5fr 2fr 1.5fr">
<span class="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate pl-2" x-text="item.label"></span>
<span class="text-xs text-slate-500 text-right" x-text="fmt(item.unit_cost)"></span>
<div class="flex flex-col items-center">
<input type="number" step="1" min="0" x-model.number="item.qty" @input="item.pieces=+item.qty||0;item.packs=0" class="w-full px-1 py-1 bg-blue-50 dark:bg-blue-900/20 border border-blue-300 dark:border-blue-700 rounded text-xs text-center font-bold" placeholder="0">
<span class="text-[7px] text-blue-600 mt-0.5">Qty (pcs)</span>
</div>
<span class="text-xs font-bold text-teal-600 text-right" x-text="fmt((+item.qty||0)*(+item.unit_cost||0))"></span>
</div>
<!-- Pack items (pack_size>1): Packs + Pieces -->
<div x-show="(+item.pack_size||1)>1" class="grid gap-1.5 items-center py-1.5 border-b border-slate-100 dark:border-slate-800" style="grid-template-columns:2.5fr 1fr 1.2fr 1.2fr 1fr 1.2fr">
<span class="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate pl-2" x-text="item.label"></span>
<span class="text-xs text-slate-500 text-right" x-text="fmt(item.unit_cost)"></span>
<div class="flex flex-col items-center">
<input type="number" step="1" min="0" x-model.number="item.packs" @input="item.qty=(+item.packs||0)*(+item.pack_size||1)+(+item.pieces||0)" class="w-full px-1 py-1 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 rounded text-xs text-center font-bold" placeholder="0">
<span class="text-[7px] text-amber-600 mt-0.5" x-text="'Packs ('+(+item.pack_size||1)+'ea)'"></span>
</div>
<div class="flex flex-col items-center">
<input type="number" step="1" min="0" x-model.number="item.pieces" @input="item.qty=(+item.packs||0)*(+item.pack_size||1)+(+item.pieces||0)" class="w-full px-1 py-1 bg-blue-50 dark:bg-blue-900/20 border border-blue-300 dark:border-blue-700 rounded text-xs text-center font-bold" placeholder="0">
<span class="text-[7px] text-blue-600 mt-0.5">Loose</span>
</div>
<span class="text-[10px] text-slate-500 text-center font-semibold" x-text="(+item.qty||0)+' pcs'"></span>
<span class="text-xs font-bold text-teal-600 text-right" x-text="fmt((+item.qty||0)*(+item.unit_cost||0))"></span>
</div>
</template>
</div></template>
</div>
</div></template>
<div x-show="cosClosing.length===0" class="text-center py-4"><p class="text-xs text-slate-400">No catalog items. Click "Manage Catalog" to set up your stock items.</p></div>
<div class="mt-3 pt-3 border-t-2 border-slate-200 dark:border-slate-700 flex justify-between"><span class="text-sm font-black">Total Closing Stock</span><span class="text-sm font-black text-teal-600" x-text="fmt(cosClosing.reduce((s,i)=>s+((+i.qty||0)*(+i.unit_cost||0)),0))"></span></div>
</div>
</div>




<!-- COS Summary -->
<div class="glass-card rounded-2xl border border-orange-200/60 dark:border-orange-700/30 shadow-lg p-5">
<div class="space-y-2 text-sm">
<div class="flex justify-between"><span class="text-slate-500">Opening Stock</span><span class="font-bold" x-text="fmt(cosCalc.opening)"></span></div>
<div class="flex justify-between"><span class="text-slate-500">Add: Total Purchases</span><span class="font-bold" x-text="fmt(cosCalc.purchases)"></span></div>
<div class="flex justify-between border-t border-slate-200 dark:border-slate-700 pt-2"><span class="font-semibold text-slate-600">Goods Available for Sale</span><span class="font-bold" x-text="fmt(cosCalc.goodsAvailable)"></span></div>
<div class="flex justify-between"><span class="text-slate-500">Less: Closing Stock</span><span class="font-bold text-red-500" x-text="'('+fmt(cosCalc.closingStock)+')'"></span></div>
<div class="flex justify-between border-t-2 border-orange-300 dark:border-orange-700 pt-2"><span class="font-black text-slate-900 dark:text-white">Cost of Sales</span><span class="font-black text-orange-600" x-text="fmt(cosCalc.costOfSales)"></span></div>
</div></div>

</div></template>
</div>

<!-- TAB: Expenses -->
<div x-show="currentTab==='expenses'" x-transition>
<div x-show="!activeReport||!activePeriodId" class="text-center py-20"><p class="text-slate-400" x-text="!activeReport?'Select a report first':'Select a period above'"></p></div>
<template x-if="activeReport&&activePeriodId"><div>
<div class="flex items-center justify-between mb-5"><div><h2 class="text-lg font-black text-slate-900 dark:text-white">Expenses Entry</h2><p class="text-xs text-slate-400" x-text="periodLabel()"></p></div>
<button @click="saveExpenses()" :disabled="saving" class="px-5 py-2 bg-gradient-to-r from-red-500 to-rose-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all disabled:opacity-50 flex items-center gap-2"><i data-lucide="save" class="w-4 h-4"></i> <span x-text="saving?'Saving...':'Save Expenses'"></span></button></div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<!-- Operating -->
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
<div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/10 to-transparent flex items-center gap-3"><div class="w-8 h-8 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow"><i data-lucide="receipt" class="w-4 h-4 text-white"></i></div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Operating Expenses</h3></div>
<div class="p-5 space-y-1">
<template x-for="(item,i) in opexItems" :key="'op'+i"><div class="border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
<div class="flex items-center gap-2 px-3 py-2 bg-slate-50 dark:bg-slate-800/50"><button @click="item._open=!item._open" class="p-0.5 text-slate-400 hover:text-blue-500"><i :data-lucide="item._open?'chevron-down':'chevron-right'" class="w-3.5 h-3.5"></i></button><input type="text" x-model="item.label" class="flex-1 px-2 py-1 bg-transparent border-0 text-sm font-semibold focus:outline-none" placeholder="Category"><span class="text-xs font-black text-blue-600 mr-1" x-text="fmt(itemTotal(item))"></span><span class="text-[9px] text-slate-400 mr-1" x-text="(item.sub_entries||[]).length+'x'"></span><button @click="opexItems.splice(i,1)" class="p-1 text-slate-300 hover:text-red-500"><i data-lucide="x" class="w-3 h-3"></i></button></div>
<div x-show="item._open" x-transition class="px-3 py-2 space-y-1.5 bg-white dark:bg-slate-900">
<template x-for="(sub,j) in item.sub_entries" :key="'os'+i+'_'+j"><div class="flex items-center gap-2"><input type="text" x-model="sub.note" placeholder="Note" class="flex-1 px-2 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs"><div class="relative w-32"><span class="absolute left-2 top-1/2 -translate-y-1/2 text-[10px] text-slate-400">₦</span><input type="number" step="0.01" x-model.number="sub.amount" class="w-full pl-5 pr-2 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs text-right"></div><button @click="item.sub_entries.splice(j,1)" class="p-0.5 text-slate-300 hover:text-red-500"><i data-lucide="minus-circle" class="w-3 h-3"></i></button></div></template>
<button @click="item.sub_entries.push({note:'',amount:0})" class="flex items-center gap-1 text-blue-500 text-[10px] font-bold mt-1"><i data-lucide="plus" class="w-3 h-3"></i> Add Entry</button>
</div></div></template>
<button @click="opexItems.push({label:'',sub_entries:[{note:'',amount:0}],category:'operating',_open:true})" class="flex items-center gap-2 text-blue-600 text-xs font-bold mt-2"><i data-lucide="plus-circle" class="w-4 h-4"></i> Add Category</button>
<div class="mt-3 pt-3 border-t-2 border-slate-200 dark:border-slate-700 flex justify-between"><span class="text-sm font-black">Total Operating</span><span class="text-sm font-black text-blue-600" x-text="fmt(opexItems.reduce((s,i)=>s+itemTotal(i),0))"></span></div>
</div></div>
<!-- Other -->
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
<div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-violet-500/10 to-transparent flex items-center gap-3"><div class="w-8 h-8 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow"><i data-lucide="landmark" class="w-4 h-4 text-white"></i></div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Finance & Other</h3></div>
<div class="p-5 space-y-1">
<template x-for="(item,i) in otherExpItems" :key="'ot'+i"><div class="border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
<div class="flex items-center gap-2 px-3 py-2 bg-slate-50 dark:bg-slate-800/50"><button @click="item._open=!item._open" class="p-0.5 text-slate-400 hover:text-violet-500"><i :data-lucide="item._open?'chevron-down':'chevron-right'" class="w-3.5 h-3.5"></i></button><input type="text" x-model="item.label" class="flex-1 px-2 py-1 bg-transparent border-0 text-sm font-semibold focus:outline-none" placeholder="Category"><span class="text-xs font-black text-violet-600 mr-1" x-text="fmt(itemTotal(item))"></span><span class="text-[9px] text-slate-400 mr-1" x-text="(item.sub_entries||[]).length+'x'"></span><button @click="otherExpItems.splice(i,1)" class="p-1 text-slate-300 hover:text-red-500"><i data-lucide="x" class="w-3 h-3"></i></button></div>
<div x-show="item._open" x-transition class="px-3 py-2 space-y-1.5 bg-white dark:bg-slate-900">
<template x-for="(sub,j) in item.sub_entries" :key="'ts'+i+'_'+j"><div class="flex items-center gap-2"><input type="text" x-model="sub.note" placeholder="Note" class="flex-1 px-2 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs"><div class="relative w-32"><span class="absolute left-2 top-1/2 -translate-y-1/2 text-[10px] text-slate-400">₦</span><input type="number" step="0.01" x-model.number="sub.amount" class="w-full pl-5 pr-2 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs text-right"></div><button @click="item.sub_entries.splice(j,1)" class="p-0.5 text-slate-300 hover:text-red-500"><i data-lucide="minus-circle" class="w-3 h-3"></i></button></div></template>
<button @click="item.sub_entries.push({note:'',amount:0})" class="flex items-center gap-1 text-violet-500 text-[10px] font-bold mt-1"><i data-lucide="plus" class="w-3 h-3"></i> Add Entry</button>
</div></div></template>
<button @click="otherExpItems.push({label:'',sub_entries:[{note:'',amount:0}],category:'other',_open:true})" class="flex items-center gap-2 text-violet-600 text-xs font-bold mt-2"><i data-lucide="plus-circle" class="w-4 h-4"></i> Add Category</button>
<div class="mt-3 pt-3 border-t-2 border-slate-200 dark:border-slate-700 flex justify-between"><span class="text-sm font-black">Total Other</span><span class="text-sm font-black text-violet-600" x-text="fmt(otherExpItems.reduce((s,i)=>s+itemTotal(i),0))"></span></div>
</div></div>
</div>
</div></template>
</div>

<!-- TAB: P&L Statement (8-Section Professional Report) -->
<div x-show="currentTab==='statement'" x-transition>
<div x-show="!activeReport" class="text-center py-20"><p class="text-slate-400">Select a report first</p></div>
<template x-if="activeReport"><div>

<!-- Action Bar -->
<div class="flex items-center justify-between mb-5 print-hidden">
<div><h2 class="text-lg font-black text-slate-900 dark:text-white">P&L Report</h2><p class="text-xs text-slate-400">Full report document</p></div>
<div class="flex gap-2 items-center">
<div class="flex items-center gap-2 mr-3">
<label class="text-xs font-bold text-slate-500">Prepared By:</label>
<input type="text" x-model="pnlPreparedBy" placeholder="Name" class="px-3 py-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-semibold w-40">
</div>
<div class="flex items-center gap-2 mr-3">
<label class="text-xs font-bold text-slate-500">Cover Label:</label>
<input type="text" x-model="pnlPeriodLabel" placeholder="End of Month" class="px-3 py-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-semibold w-36">
</div>
<div class="flex items-center gap-1.5 mr-3">
<label class="text-xs font-bold text-slate-500">Status:</label>
<button @click="pnlReportStatus='draft'" :class="pnlReportStatus==='draft'?'bg-amber-500 text-white':'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'" class="px-3 py-1.5 text-xs font-bold rounded-l-lg transition-all">Draft</button>
<button @click="pnlReportStatus='final'" :class="pnlReportStatus==='final'?'bg-emerald-500 text-white':'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'" class="px-3 py-1.5 text-xs font-bold rounded-r-lg transition-all">Final</button>
</div>
<div class="flex items-center gap-1.5 mr-3">
<label class="text-xs font-bold text-slate-500">Charts:</label>
<button @click="pnlChartType='bar'" :class="pnlChartType==='bar'?'bg-blue-500 text-white':'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'" class="px-2.5 py-1.5 text-[10px] font-bold rounded-l-lg transition-all flex items-center gap-1"><i data-lucide="bar-chart-3" class="w-3 h-3"></i> Bar</button>
<button @click="pnlChartType='pie'" :class="pnlChartType==='pie'?'bg-purple-500 text-white':'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'" class="px-2.5 py-1.5 text-[10px] font-bold rounded-r-lg transition-all flex items-center gap-1"><i data-lucide="pie-chart" class="w-3 h-3"></i> Pie</button>
</div>
<button @click="exportPnlPDF()" class="px-4 py-2 bg-gradient-to-r from-slate-800 to-slate-900 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all flex items-center gap-2"><i data-lucide="printer" class="w-4 h-4"></i> Download PDF</button>
<button @click="showPdfSettings=!showPdfSettings;$nextTick(()=>lucide.createIcons())" class="px-3 py-2 text-xs font-bold rounded-xl border transition-all flex items-center gap-1.5" :class="showPdfSettings?'bg-amber-500 text-white border-amber-500':'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-amber-400'"><i data-lucide="palette" class="w-3.5 h-3.5"></i> PDF Style</button>
<button x-show="activeReport.status==='draft'" @click="finalizeReport()" class="px-4 py-2 bg-gradient-to-r from-emerald-500 to-green-600 text-white text-xs font-bold rounded-xl shadow-lg flex items-center gap-2"><i data-lucide="check-circle" class="w-4 h-4"></i> Finalize</button>
</div>
</div>

<!-- PDF Style Settings Panel -->
<div x-show="showPdfSettings" x-transition class="mb-4 glass-card rounded-2xl border border-amber-200/60 dark:border-amber-700/40 shadow-lg overflow-hidden">
<div class="px-5 py-3 bg-gradient-to-r from-amber-500/10 to-transparent border-b border-amber-100 dark:border-amber-900 flex items-center justify-between">
<div class="flex items-center gap-2"><div class="w-7 h-7 rounded-lg bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow"><i data-lucide="palette" class="w-3.5 h-3.5 text-white"></i></div><h3 class="font-bold text-slate-900 dark:text-white text-sm">PDF Style Settings</h3></div>
<div class="flex items-center gap-2">
<button @click="savePdfStyle()" class="px-3 py-1.5 bg-gradient-to-r from-amber-500 to-orange-500 text-white text-[10px] font-bold rounded-lg hover:shadow-lg transition-all flex items-center gap-1"><i data-lucide="save" class="w-3 h-3"></i> Save</button>
<button @click="resetPdfStyle()" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-[10px] font-bold rounded-lg hover:bg-slate-200 transition-all flex items-center gap-1"><i data-lucide="rotate-ccw" class="w-3 h-3"></i> Reset Default</button>
</div>
</div>
<div class="p-5">
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
<!-- Font Settings -->
<div>
<p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-3">Typography</p>
<div class="space-y-3">
<div><label class="text-[10px] font-bold text-slate-600 block mb-1">Font Family</label>
<select x-model="pdfStyle.fontFamily" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-semibold">
<option value="Inter">Inter</option><option value="Roboto">Roboto</option><option value="Outfit">Outfit</option><option value="Arial">Arial</option><option value="Georgia">Georgia</option><option value="Times New Roman">Times New Roman</option>
</select></div>
<div><label class="text-[10px] font-bold text-slate-600 block mb-1">Body Text <span class="text-amber-500" x-text="pdfStyle.bodySize+'px'"></span></label>
<input type="number" min="8" max="20" step="1" x-model.number="pdfStyle.bodySize" class="w-20 px-2 py-1 text-xs font-bold border border-slate-200 rounded-lg text-center focus:outline-none focus:border-amber-500"></div>
<div><label class="text-[10px] font-bold text-slate-600 block mb-1">Page Header <span class="text-amber-500" x-text="pdfStyle.headerSize+'px'"></span></label>
<input type="number" min="10" max="24" step="1" x-model.number="pdfStyle.headerSize" class="w-20 px-2 py-1 text-xs font-bold border border-slate-200 rounded-lg text-center focus:outline-none focus:border-amber-500"></div>
<div><label class="text-[10px] font-bold text-slate-600 block mb-1">Table Header <span class="text-amber-500" x-text="pdfStyle.tableHeaderSize+'px'"></span></label>
<input type="number" min="7" max="20" step="1" x-model.number="pdfStyle.tableHeaderSize" class="w-20 px-2 py-1 text-xs font-bold border border-slate-200 rounded-lg text-center focus:outline-none focus:border-amber-500"></div>
<div><label class="text-[10px] font-bold text-slate-600 block mb-1">Table Body <span class="text-amber-500" x-text="pdfStyle.tableBodySize+'px'"></span></label>
<input type="number" min="8" max="20" step="1" x-model.number="pdfStyle.tableBodySize" class="w-20 px-2 py-1 text-xs font-bold border border-slate-200 rounded-lg text-center focus:outline-none focus:border-amber-500"></div>
<div><label class="text-[10px] font-bold text-slate-600 block mb-1">Footer <span class="text-amber-500" x-text="pdfStyle.footerSize+'px'"></span></label>
<input type="number" min="6" max="16" step="1" x-model.number="pdfStyle.footerSize" class="w-20 px-2 py-1 text-xs font-bold border border-slate-200 rounded-lg text-center focus:outline-none focus:border-amber-500"></div>
</div>
</div>
<!-- Color Settings -->
<div>
<p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-3">Header & Accent Colors</p>
<div class="space-y-3">
<div class="flex items-center gap-3"><input type="color" x-model="pdfStyle.headerBg" class="w-8 h-8 rounded-lg border border-slate-200 cursor-pointer"><div><label class="text-[10px] font-bold text-slate-600">Header Background</label><p class="text-[9px] text-slate-400" x-text="pdfStyle.headerBg"></p></div></div>
<div class="flex items-center gap-3"><input type="color" x-model="pdfStyle.headerText" class="w-8 h-8 rounded-lg border border-slate-200 cursor-pointer"><div><label class="text-[10px] font-bold text-slate-600">Header Text</label><p class="text-[9px] text-slate-400" x-text="pdfStyle.headerText"></p></div></div>
<div class="flex items-center gap-3"><input type="color" x-model="pdfStyle.accentColor" class="w-8 h-8 rounded-lg border border-slate-200 cursor-pointer"><div><label class="text-[10px] font-bold text-slate-600">Accent Color</label><p class="text-[9px] text-slate-400" x-text="pdfStyle.accentColor"></p></div></div>
<div class="flex items-center gap-3"><input type="color" x-model="pdfStyle.pageBorder" class="w-8 h-8 rounded-lg border border-slate-200 cursor-pointer"><div><label class="text-[10px] font-bold text-slate-600">Page Border</label><p class="text-[9px] text-slate-400" x-text="pdfStyle.pageBorder"></p></div></div>
</div>
</div>
<!-- Table Colors -->
<div>
<p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-3">Table Colors</p>
<div class="space-y-3">
<div class="flex items-center gap-3"><input type="color" x-model="pdfStyle.tableBg" class="w-8 h-8 rounded-lg border border-slate-200 cursor-pointer"><div><label class="text-[10px] font-bold text-slate-600">Table Header Background</label><p class="text-[9px] text-slate-400" x-text="pdfStyle.tableBg"></p></div></div>
<div class="flex items-center gap-3"><input type="color" x-model="pdfStyle.tableText" class="w-8 h-8 rounded-lg border border-slate-200 cursor-pointer"><div><label class="text-[10px] font-bold text-slate-600">Table Header Text</label><p class="text-[9px] text-slate-400" x-text="pdfStyle.tableText"></p></div></div>
<div class="flex items-center gap-3"><input type="color" x-model="pdfStyle.totalBg" class="w-8 h-8 rounded-lg border border-slate-200 cursor-pointer"><div><label class="text-[10px] font-bold text-slate-600">Total Row Background</label><p class="text-[9px] text-slate-400" x-text="pdfStyle.totalBg"></p></div></div>
<div class="flex items-center gap-3"><input type="color" x-model="pdfStyle.totalText" class="w-8 h-8 rounded-lg border border-slate-200 cursor-pointer"><div><label class="text-[10px] font-bold text-slate-600">Total Row Text</label><p class="text-[9px] text-slate-400" x-text="pdfStyle.totalText"></p></div></div>
</div>
<!-- Preview Swatch -->
<div class="mt-4 p-3 rounded-xl border border-slate-200 dark:border-slate-700">
<p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-2">Preview</p>
<div :style="'background:'+pdfStyle.headerBg+';padding:6px 10px;border-radius:6px 6px 0 0'"><span :style="'color:'+pdfStyle.headerText+';font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px'" x-text="'Sample Header'"></span></div>
<div :style="'background:'+pdfStyle.tableBg+';padding:4px 10px'"><span :style="'color:'+pdfStyle.tableText+';font-size:9px;font-weight:700'" x-text="'Table Header'"></span></div>
<div style="padding:4px 10px;background:#f8fafc;border-bottom:1px solid #e2e8f0"><span :style="'font-size:'+pdfStyle.tableBodySize+'px;font-family:'+pdfStyle.fontFamily">Sample Data Row</span></div>
<div :style="'background:'+pdfStyle.totalBg+';padding:4px 10px;border-radius:0 0 6px 6px'"><span :style="'color:'+pdfStyle.totalText+';font-size:10px;font-weight:900'" x-text="'Total: ₦1,000,000.00'"></span></div>
</div>
</div>
</div>
</div>
</div>

<!-- REPORT DOCUMENT -->
<div id="pnl-report-document" class="printable-summary bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden max-w-5xl mx-auto" style="font-family:Inter,sans-serif">

<!-- ════════════════════════════════════════════════════ -->
<!-- PAGE 1: COVER PAGE (matches final report exactly)  -->
<!-- ════════════════════════════════════════════════════ -->
<div style="min-height:700px;background:#fff;position:relative;display:flex;flex-direction:column;justify-content:space-between;page-break-after:always;padding:48px 40px">
<div style="border-bottom:4px solid #000;padding-bottom:32px;display:flex;justify-content:space-between;align-items:flex-start">
<div>
<h2 style="font-size:22px;font-weight:900;color:#000;line-height:1;margin-bottom:6px;letter-spacing:-0.5px" x-text="activeReport.client_name||activeReport.title"></h2>
<p style="font-size:10px;font-weight:700;color:#64748b;letter-spacing:3px;text-transform:uppercase" x-text="activeReport.industry.toUpperCase()"></p>
</div>
<div style="text-align:right">
<p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px">Confidential Document</p>
</div>
</div>
<div style="padding:60px 0;text-align:center">
<div style="display:inline-block;padding:6px 20px;margin-bottom:28px">
<span style="font-size:11px;font-weight:900;letter-spacing:5px;text-transform:uppercase" x-text="pnlPeriodLabel"></span>
</div>
<h1 style="font-size:36px;font-weight:900;color:#000;letter-spacing:-1.5px;line-height:1.1;margin-bottom:12px">PROFIT &amp; LOSS<br>STATEMENT</h1>
<div style="width:80px;height:6px;background:#f59e0b;margin:32px auto;border-radius:4px"></div>
<h3 style="font-size:18px;font-weight:700;color:#1e293b" x-text="activeReport.location||activeReport.client_name||activeReport.title"></h3>
<p style="color:#64748b;font-family:monospace;font-size:12px;margin-top:4px" x-text="monthNames[activeReport.report_month-1]+' '+activeReport.report_year"></p>
</div>
<div style="border-top:2px solid #000;padding-top:32px;display:grid;grid-template-columns:1fr 1fr;gap:40px">
<div>
<div style="margin-bottom:16px">
<p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Prepared For</p>
<p style="font-size:14px;font-weight:700;color:#000" x-text="activeReport.client_name||activeReport.title"></p>
</div>
<div>
<p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Prepared By</p>
<p style="font-size:14px;font-weight:700;color:#000" x-text="pnlPreparedBy||'MIAUDITOPS'"></p>
</div>
</div>
<div style="text-align:right">
<div style="margin-bottom:16px">
<p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Date Generated</p>
<p style="font-size:14px;font-weight:700;color:#000" x-text="new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'})"></p>
</div>
<div>
<p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Status</p>
<span style="display:inline-block;padding:4px 12px;background:#000;color:#fff;font-size:10px;font-weight:700;border-radius:4px;text-transform:uppercase" x-text="pnlReportStatus"></span>
</div>
</div>
</div>
</div>


<!-- ════════════════════════════════════════════════════ -->
<!-- PAGE 2: P&L SUMMARY (Standard Income Statement)    -->
<!-- ════════════════════════════════════════════════════ -->
<div style="page-break-before:always">
<div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center">
<p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">01. Statement of Profit &amp; Loss</p>
<p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)" x-text="(activeReport.client_name||activeReport.title)+' — '+monthNames[activeReport.report_month-1]+' '+activeReport.report_year"></p>
</div>
<div style="padding:40px">
<table style="width:100%;border-collapse:collapse;font-size:12px">
<tbody>
<tr style="border-bottom:1px solid #e2e8f0"><td style="padding:12px 0;font-weight:700;color:#000">Revenue</td><td style="padding:12px 0;text-align:right;font-weight:800;color:#000" x-text="fmt(pnl.totalRevenue)"></td></tr>
<tr style="border-bottom:1px solid #e2e8f0"><td style="padding:12px 0;color:#64748b">Less: Cost of Sales</td><td style="padding:12px 0;text-align:right;font-weight:700;color:#dc2626" x-text="'('+fmt(pnl.cos)+')'"></td></tr>
<tr style="border-bottom:2px solid #000;background:#f8fafc"><td style="padding:14px 0;font-weight:900;color:#000;font-size:13px">Gross Profit</td><td style="padding:14px 0;text-align:right;font-weight:900;font-size:13px" :style="'color:'+(pnl.grossProfit>=0?'#059669':'#dc2626')" x-text="fmt(pnl.grossProfit)"></td></tr>
<tr><td colspan="2" style="padding:8px 0"></td></tr>
<tr style="border-bottom:1px solid #e2e8f0"><td style="padding:12px 0;color:#64748b">Less: Operating Expenses</td><td style="padding:12px 0;text-align:right;font-weight:700;color:#dc2626" x-text="'('+fmt(pnl.totalOpex)+')'"></td></tr>
<tr style="border-bottom:2px solid #000;background:#f8fafc"><td style="padding:14px 0;font-weight:900;color:#000;font-size:13px">Operating Profit</td><td style="padding:14px 0;text-align:right;font-weight:900;font-size:13px" :style="'color:'+(pnl.operatingProfit>=0?'#059669':'#dc2626')" x-text="fmt(pnl.operatingProfit)"></td></tr>
<tr><td colspan="2" style="padding:8px 0"></td></tr>
<tr style="border-bottom:1px solid #e2e8f0"><td style="padding:12px 0;color:#64748b">Less: Other Expenses</td><td style="padding:12px 0;text-align:right;font-weight:700;color:#dc2626" x-text="'('+fmt(pnl.totalOther)+')'"></td></tr>
<tr style="background:#000"><td style="padding:16px 10px;font-weight:900;color:#fff;font-size:14px;border-radius:8px 0 0 8px">Net Profit / (Loss)</td><td style="padding:16px 10px;text-align:right;font-weight:900;font-size:14px;border-radius:0 8px 8px 0" :style="'color:'+(pnl.netProfit>=0?'#34d399':'#fca5a5')" x-text="fmt(pnl.netProfit)"></td></tr>
</tbody>
</table>
<!-- Summary metric cards -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:32px">
<div style="background:#000;border-radius:12px;padding:16px;text-align:center"><p style="font-size:18px;font-weight:900;color:#f59e0b" x-text="fmt(pnl.totalRevenue)"></p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;margin-top:4px">Total Revenue</p></div>
<div style="background:#000;border-radius:12px;padding:16px;text-align:center"><p style="font-size:18px;font-weight:900;color:#f59e0b" x-text="fmt(pnl.cos)"></p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;margin-top:4px">Cost of Sales</p></div>
<div style="border-radius:12px;padding:16px;text-align:center" :style="'background:'+(pnl.netProfit>=0?'#059669':'#dc2626')"><p style="font-size:18px;font-weight:900;color:#fff" x-text="fmt(pnl.netProfit)"></p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.6);text-transform:uppercase;margin-top:4px" x-text="pnl.netProfit>=0?'Net Profit':'Net Loss'"></p></div>
</div>
</div>
<div style="background:#000;padding:8px 40px;text-align:center"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Powered by Miemploya</p></div>
</div>

<!-- ════════════════════════════════════════════════════ -->
<!-- PAGE 3: NOTES TO THE P&L (Detailed Breakdown)      -->
<!-- ════════════════════════════════════════════════════ -->
<div style="page-break-before:always">
<div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center">
<p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">02. Notes to the Financial Statement</p>
<p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)" x-text="(activeReport.client_name||activeReport.title)+' — '+monthNames[activeReport.report_month-1]+' '+activeReport.report_year"></p>
</div>
<div style="padding:32px 40px">
<!-- Note 1: Revenue Breakdown (Weekly Columns) -->
<div style="margin-bottom:28px">
<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><div style="width:24px;height:24px;border-radius:6px;background:#000;display:flex;align-items:center;justify-content:center"><span style="font-size:10px;font-weight:900;color:#f59e0b">1</span></div><span style="font-size:11px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px">Revenue Breakdown (Weekly)</span></div>
<div x-show="periods.length > 0" x-html="buildWeeklyTableHTML(allRevenue,'revenue','Total Revenue','#059669')"></div>
<template x-if="periods.length === 0"><table style="width:100%;border-collapse:collapse;font-size:11px"><tbody>
<template x-for="item in stmtRevenue"><tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 0;color:#334155" x-text="item.label"></td><td style="padding:8px 0;text-align:right;font-weight:700;color:#000" x-text="fmt(item.amount)"></td></tr></template>
<tr style="background:#f8fafc;border-top:2px solid #000"><td style="padding:10px 0;font-weight:900;color:#000">Total Revenue</td><td style="padding:10px 0;text-align:right;font-weight:900;color:#059669" x-text="fmt(pnl.totalRevenue)"></td></tr>
</tbody></table></template>
</div>
<!-- Note 2: Cost of Sales Computation -->
<div style="margin-bottom:28px">
<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><div style="width:24px;height:24px;border-radius:6px;background:#000;display:flex;align-items:center;justify-content:center"><span style="font-size:10px;font-weight:900;color:#f59e0b">2</span></div><span style="font-size:11px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px">Cost of Sales Computation</span></div>
<table style="width:100%;border-collapse:collapse;font-size:11px"><tbody>
<template x-for="item in stmtCOS"><tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 0;color:#334155" x-text="(item.entry_type==='closing'?'Less: ':item.entry_type==='purchase'?'Add: ':'')+item.label"></td><td style="padding:8px 0;text-align:right;font-weight:700" :style="'color:'+(item.entry_type==='closing'?'#dc2626':'#000')" x-text="item.entry_type==='closing'?'('+fmt(item.amount)+')':fmt(item.amount)"></td></tr></template>
<tr style="background:#f8fafc;border-top:2px solid #000"><td style="padding:10px 0;font-weight:900;color:#000">Cost of Sales (Opening + Purchases − Closing)</td><td style="padding:10px 0;text-align:right;font-weight:900;color:#ea580c" x-text="fmt(pnl.cos)"></td></tr>
</tbody></table>
<p style="font-size:9px;color:#94a3b8;margin-top:6px;font-style:italic" x-text="'COS represents '+pnl.cogsRatio.toFixed(1)+'% of total revenue. Computed as: Opening Stock ('+fmt(cosCalc.opening)+') + Purchases ('+fmt(cosCalc.purchases)+') − Closing Stock ('+fmt(cosCalc.closingStock)+') = '+fmt(pnl.cos)"></p>
</div>
<!-- Note 3: Operating Expenses (Weekly Columns) -->
<div style="margin-bottom:28px">
<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><div style="width:24px;height:24px;border-radius:6px;background:#000;display:flex;align-items:center;justify-content:center"><span style="font-size:10px;font-weight:900;color:#f59e0b">3</span></div><span style="font-size:11px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px">Operating Expenses Detail (Weekly)</span></div>
<div x-show="periods.length > 0" x-html="buildWeeklyTableHTML(allExpenses,'operating','Total Operating Expenses','#2563eb')"></div>
<template x-if="periods.length === 0"><table style="width:100%;border-collapse:collapse;font-size:11px"><tbody>
<template x-for="item in stmtOpex"><tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 0;color:#334155" x-text="item.label"></td><td style="padding:8px 0;text-align:right;font-weight:700;color:#000" x-text="fmt(item.amount)"></td></tr></template>
<tr style="background:#f8fafc;border-top:2px solid #000"><td style="padding:10px 0;font-weight:900;color:#000">Total Operating Expenses</td><td style="padding:10px 0;text-align:right;font-weight:900;color:#2563eb" x-text="fmt(pnl.totalOpex)"></td></tr>
</tbody></table></template>
</div>
<!-- Note 4: Other Expenses -->
<div>
<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><div style="width:24px;height:24px;border-radius:6px;background:#000;display:flex;align-items:center;justify-content:center"><span style="font-size:10px;font-weight:900;color:#f59e0b">4</span></div><span style="font-size:11px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px">Other Expenses</span></div>
<table style="width:100%;border-collapse:collapse;font-size:11px"><tbody>
<template x-for="item in stmtOther"><tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 0;color:#334155" x-text="item.label"></td><td style="padding:8px 0;text-align:right;font-weight:700;color:#000" x-text="fmt(item.amount)"></td></tr></template>
<tr x-show="stmtOther.length===0" style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 0;color:#94a3b8;font-style:italic" colspan="2">No other expenses recorded</td></tr>
<tr style="background:#f8fafc;border-top:2px solid #000"><td style="padding:10px 0;font-weight:900;color:#000">Total Other Expenses</td><td style="padding:10px 0;text-align:right;font-weight:900;color:#7c3aed" x-text="fmt(pnl.totalOther)"></td></tr>
</tbody></table>
</div>
</div>
<div style="background:#000;padding:8px 40px;text-align:center"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Powered by Miemploya</p></div>
</div>

<!-- ════════════════════════════════════════════════════ -->
<!-- PAGE 4: KEY METRICS & RATIOS                        -->
<!-- ════════════════════════════════════════════════════ -->
<div style="page-break-before:always">
<div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center">
<p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">03. Key Financial Metrics &amp; Ratios</p>
<p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)" x-text="(activeReport.client_name||activeReport.title)+' — '+monthNames[activeReport.report_month-1]+' '+activeReport.report_year"></p>
</div>
<div style="padding:32px 40px">
<div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;margin-bottom:24px">
<div style="background:#000;border-radius:12px;padding:20px;text-align:center"><p style="font-size:22px;font-weight:900;color:#34d399" x-text="pnl.grossMargin.toFixed(1)+'%'"></p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;margin-top:6px">Gross Margin</p></div>
<div style="background:#000;border-radius:12px;padding:20px;text-align:center"><p style="font-size:22px;font-weight:900" :style="'color:'+(pnl.netMargin>=0?'#34d399':'#fca5a5')" x-text="pnl.netMargin.toFixed(1)+'%'"></p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;margin-top:6px">Net Margin</p></div>
<div style="background:#000;border-radius:12px;padding:20px;text-align:center"><p style="font-size:22px;font-weight:900;color:#f59e0b" x-text="pnl.cogsRatio.toFixed(1)+'%'"></p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;margin-top:6px">COGS Ratio</p></div>
<div style="background:#000;border-radius:12px;padding:20px;text-align:center"><p style="font-size:22px;font-weight:900;color:#a78bfa" x-text="pnl.expenseRatio.toFixed(1)+'%'"></p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;margin-top:6px">Expense Ratio</p></div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px">
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center"><p style="font-size:16px;font-weight:900;color:#000" x-text="fmt(pnl.operatingProfit)"></p><p style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-top:4px">Operating Profit</p></div>
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center"><p style="font-size:16px;font-weight:900;color:#000" x-text="fmt(pnl.totalRevenue)"></p><p style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-top:4px">Total Revenue</p></div>
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center"><p style="font-size:16px;font-weight:900;color:#000" x-text="fmt(pnl.cos)"></p><p style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-top:4px">Cost of Sales</p></div>
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center"><p style="font-size:16px;font-weight:900;color:#000" x-text="periods.length"></p><p style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-top:4px">Periods Covered</p></div>
</div>
</div>
<div style="background:#000;padding:8px 40px;text-align:center"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Powered by Miemploya</p></div>
</div>

<!-- ════════════════════════════════════════════════════ -->
<!-- PAGE 5: STOCK VALUATION REPORT                      -->
<!-- ════════════════════════════════════════════════════ -->
<div style="page-break-before:always">
<div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center">
<p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">04. Stock Valuation Report</p>
<p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)" x-text="(activeReport.client_name||activeReport.title)+' — '+monthNames[activeReport.report_month-1]+' '+activeReport.report_year"></p>
</div>
<div style="padding:32px 40px">
<table style="width:100%;border-collapse:collapse;font-size:11px">
<thead><tr style="background:#000">
<th style="padding:10px 12px;text-align:left;font-size:9px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:1px">Item</th>
<th style="padding:10px 12px;text-align:right;font-size:9px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:1px">Unit Cost</th>
<th style="padding:10px 12px;text-align:right;font-size:9px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:1px">Qty Counted</th>
<th style="padding:10px 12px;text-align:right;font-size:9px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:1px">Stock Value</th>
</tr></thead>
<tbody>
<template x-for="dept in [...new Set(cosClosing.map(i=>i.department||'Uncategorized'))]">
<template x-for="(row,ri) in [
  {type:'dept',label:dept},
  ...([...new Set(cosClosing.filter(i=>(i.department||'Uncategorized')===dept).map(i=>i.category||''))].flatMap(cat=>[
    cat?{type:'cat',label:cat}:null,
    ...cosClosing.filter(i=>(i.department||'Uncategorized')===dept&&(i.category||'')===cat).map(i=>({type:'item',item:i}))
  ].filter(Boolean))),
  {type:'sub',label:dept,total:cosClosing.filter(i=>(i.department||'Uncategorized')===dept).reduce((s,i)=>s+((+i.qty||0)*(+i.unit_cost||0)),0)}
]" :key="dept+ri">
<tr :style="row.type==='dept'?'background:#f8fafc':row.type==='sub'?'border-bottom:2px solid #e2e8f0':row.type==='cat'?'':'border-bottom:1px solid #f1f5f9'">
<td x-show="row.type==='dept'" :colspan="4" style="padding:8px 12px;font-weight:900;color:#000;font-size:10px;text-transform:uppercase;letter-spacing:1px;border-bottom:2px solid #e2e8f0" x-text="'📂 '+row.label"></td>
<td x-show="row.type==='cat'" :colspan="4" style="padding:4px 12px 4px 20px;font-size:8px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px" x-text="row.label"></td>
<td x-show="row.type==='item'" style="padding:6px 12px 6px 28px;font-weight:600;color:#334155" x-text="row.item?.label"></td>
<td x-show="row.type==='item'" style="padding:6px 12px;text-align:right;font-weight:600;color:#64748b" x-text="row.item?fmt(row.item.unit_cost):''"></td>
<td x-show="row.type==='item'" style="padding:6px 12px;text-align:right;font-weight:600;color:#64748b" x-text="row.item?(row.item.qty||0):''"></td>
<td x-show="row.type==='item'" style="padding:6px 12px;text-align:right;font-weight:700;color:#000" x-text="row.item?fmt((+row.item.qty||0)*(+row.item.unit_cost||0)):''"></td>
<td x-show="row.type==='sub'" :colspan="3" style="padding:6px 12px;font-weight:800;color:#475569;font-size:10px" x-text="'Subtotal — '+row.label"></td>
<td x-show="row.type==='sub'" style="padding:6px 12px;text-align:right;font-weight:800;color:#1e293b" x-text="fmt(row.total||0)"></td>
</tr>
</template>
</template>
</tbody>
<tfoot><tr style="background:#000">
<td style="padding:12px;font-weight:900;color:#fff;font-size:10px" colspan="3">Total Closing Stock Valuation</td>
<td style="padding:12px;text-align:right;font-weight:900;color:#f59e0b;font-size:12px" x-text="fmt(cosCalc.closingStock)"></td>
</tr></tfoot>
</table>
<div x-show="cosClosing.length===0" style="padding:40px;text-align:center;color:#94a3b8;font-size:12px;border:1px dashed #e2e8f0;border-radius:12px;margin-top:12px">No stock valuation data available</div>
</div>
<div style="background:#000;padding:8px 40px;text-align:center"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Powered by Miemploya</p></div>
</div>

<!-- ════════════════════════════════════════════════════ -->
<!-- PAGE 6: FINANCIAL INFOGRAPHICS                      -->
<!-- ════════════════════════════════════════════════════ -->
<div style="page-break-before:always">
<div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center">
<p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">05. Financial Infographics</p>
<p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)" x-text="(activeReport.client_name||activeReport.title)+' — '+monthNames[activeReport.report_month-1]+' '+activeReport.report_year"></p>
</div>
<div style="padding:32px 40px">
<div class="grid lg:grid-cols-2 gap-6">
<!-- Profit Waterfall -->
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px">
<p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px">Profit Waterfall</p>
<div class="space-y-2">
<div><div class="flex items-center justify-between mb-0.5"><span style="font-size:10px;font-weight:600;color:#475569">Revenue</span><span style="font-size:10px;font-weight:700;color:#059669" x-text="fmt(pnl.totalRevenue)"></span></div><div style="height:12px;background:#f1f5f9;border-radius:999px;overflow:hidden"><div style="height:100%;background:#059669;border-radius:999px;width:100%"></div></div></div>
<div><div class="flex items-center justify-between mb-0.5"><span style="font-size:10px;font-weight:600;color:#475569">Less: COS</span><span style="font-size:10px;font-weight:700;color:#ea580c" x-text="'('+fmt(pnl.cos)+')'"></span></div><div style="height:12px;background:#f1f5f9;border-radius:999px;overflow:hidden"><div style="height:100%;background:#ea580c;border-radius:999px" :style="'width:'+(pnl.totalRevenue>0?(pnl.cos/pnl.totalRevenue*100):0)+'%'"></div></div></div>
<div><div class="flex items-center justify-between mb-0.5"><span style="font-size:10px;font-weight:600;color:#475569">= Gross Profit</span><span style="font-size:10px;font-weight:700" :style="'color:'+(pnl.grossProfit>=0?'#059669':'#dc2626')" x-text="fmt(pnl.grossProfit)"></span></div><div style="height:12px;background:#f1f5f9;border-radius:999px;overflow:hidden"><div style="height:100%;border-radius:999px" :style="'background:'+(pnl.grossProfit>=0?'#34d399':'#fca5a5')+';width:'+Math.min(100,pnl.totalRevenue>0?Math.abs(pnl.grossProfit)/pnl.totalRevenue*100:0)+'%'"></div></div></div>
<div><div class="flex items-center justify-between mb-0.5"><span style="font-size:10px;font-weight:600;color:#475569">Less: Expenses</span><span style="font-size:10px;font-weight:700;color:#2563eb" x-text="'('+fmt(pnl.totalOpex+pnl.totalOther)+')'"></span></div><div style="height:12px;background:#f1f5f9;border-radius:999px;overflow:hidden"><div style="height:100%;background:#2563eb;border-radius:999px" :style="'width:'+(pnl.totalRevenue>0?((pnl.totalOpex+pnl.totalOther)/pnl.totalRevenue*100):0)+'%'"></div></div></div>
<div style="border-top:2px solid #000;padding-top:8px"><div class="flex items-center justify-between mb-0.5"><span style="font-size:11px;font-weight:900;color:#000">= Net Profit</span><span style="font-size:11px;font-weight:900" :style="'color:'+(pnl.netProfit>=0?'#059669':'#dc2626')" x-text="fmt(pnl.netProfit)"></span></div><div style="height:16px;background:#f1f5f9;border-radius:999px;overflow:hidden"><div style="height:100%;border-radius:999px" :style="'background:'+(pnl.netProfit>=0?'linear-gradient(90deg,#059669,#34d399)':'linear-gradient(90deg,#dc2626,#fca5a5)')+';width:'+Math.min(100,pnl.totalRevenue>0?Math.abs(pnl.netProfit)/pnl.totalRevenue*100:0)+'%'"></div></div></div>
</div>
</div>
<!-- Revenue Allocation -->
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px">
<p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px">Revenue Allocation</p>
<div class="space-y-3">
<template x-for="seg in [{label:'Cost of Sales',val:pnl.cos,color:'#ea580c'},{label:'Operating Expenses',val:pnl.totalOpex,color:'#2563eb'},{label:'Other Expenses',val:pnl.totalOther,color:'#7c3aed'},{label:'Net Profit',val:pnl.netProfit,color:pnl.netProfit>=0?'#059669':'#dc2626'}]">
<div class="flex items-center justify-between">
<span style="font-size:10px;font-weight:600;color:#475569;width:140px" x-text="seg.label"></span>
<div style="flex:1;margin:0 12px;height:12px;background:#f1f5f9;border-radius:999px;overflow:hidden"><div style="height:100%;border-radius:999px" :style="'background:'+seg.color+';width:'+Math.min(100,pnl.totalRevenue>0?Math.abs(seg.val)/pnl.totalRevenue*100:0)+'%'"></div></div>
<span style="font-size:10px;font-weight:700;width:48px;text-align:right" :style="'color:'+seg.color" x-text="(pnl.totalRevenue>0?(Math.abs(seg.val)/pnl.totalRevenue*100).toFixed(1):0)+'%'"></span>
</div>
</template>
</div>
</div>
</div>
</div>
<div style="background:#000;padding:8px 40px;text-align:center"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Powered by Miemploya</p></div>
</div>

<!-- ════════════════════════════════════════════════════ -->
<!-- PAGE 7: CHARTS & ANALYSIS                           -->
<!-- ════════════════════════════════════════════════════ -->
<div style="page-break-before:always">
<div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center">
<p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">06. Charts &amp; Analysis</p>
<p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)" x-text="(activeReport.client_name||activeReport.title)+' — '+monthNames[activeReport.report_month-1]+' '+activeReport.report_year"></p>
</div>
<div style="padding:32px 40px">
<div class="grid lg:grid-cols-2 gap-6">
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px"><p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">Revenue Breakdown</p><div style="height:220px"><canvas id="pnlRevenueChart"></canvas></div></div>
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px"><p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">Expense Breakdown</p><div style="height:220px"><canvas id="pnlExpenseChart"></canvas></div></div>
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px"><p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">COS Breakdown</p><div style="height:220px"><canvas id="pnlCOSChart"></canvas></div></div>
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px"><p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">P&amp;L Composition</p><div style="height:220px"><canvas id="pnlCompositionChart"></canvas></div></div>
</div>
</div>
<div style="background:#000;padding:8px 40px;text-align:center"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Powered by Miemploya</p></div>
</div>

<!-- ════════════════════════════════════════════════════ -->
<!-- PAGE 7b: PREVIOUS P&L COMPARISON                    -->
<!-- ════════════════════════════════════════════════════ -->
<div style="page-break-before:always">
<div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center">
<p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">Month-over-Month Comparison</p>
<p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)" x-text="(activeReport.client_name||activeReport.title)+' — '+monthNames[activeReport.report_month-1]+' '+activeReport.report_year"></p>
</div>
<div style="padding:32px 40px">
<!-- Action Bar -->
<div class="flex items-center gap-3 mb-6 no-print">
<button @click="fetchPrevPnl()" style="padding:8px 16px;background:#000;color:#f59e0b;font-size:10px;font-weight:800;border-radius:8px;border:1px solid rgba(245,158,11,0.3);display:flex;align-items:center;gap:6px;cursor:pointer"><i data-lucide="download" class="w-3.5 h-3.5"></i> Fetch Previous P&L</button>
<span x-show="prevPnlLoaded" style="font-size:9px;color:#059669;font-weight:700" x-text="'✓ Loaded: ' + (prevPnl.month ? monthNames[(prevPnl.month||1)-1] + ' ' + prevPnl.year : 'Previous')"></span>
<span x-show="!prevPnlLoaded" style="font-size:9px;color:#94a3b8;font-style:italic">No previous report found — enter values manually below</span>
</div>

<!-- Editable Previous Month Values + Comparison Table -->
<div class="grid lg:grid-cols-2 gap-6 mb-6">
<div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px" class="no-print">
<p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">Previous Month Values <span style="font-size:8px;color:#94a3b8;font-weight:600;text-transform:none">(editable)</span></p>
<div class="space-y-2">
<div class="flex items-center gap-3"><span style="width:140px;font-size:11px;font-weight:600;color:#475569">Total Revenue</span><div class="relative flex-1"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="prevPnl.totalRevenue" class="w-full pl-7 pr-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-right font-bold"></div></div>
<div class="flex items-center gap-3"><span style="width:140px;font-size:11px;font-weight:600;color:#475569">Cost of Sales</span><div class="relative flex-1"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="prevPnl.cos" class="w-full pl-7 pr-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-right font-bold"></div></div>
<div class="flex items-center gap-3"><span style="width:140px;font-size:11px;font-weight:600;color:#475569">Gross Profit</span><div class="relative flex-1"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="prevPnl.grossProfit" class="w-full pl-7 pr-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-right font-bold"></div></div>
<div class="flex items-center gap-3"><span style="width:140px;font-size:11px;font-weight:600;color:#475569">OpEx</span><div class="relative flex-1"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="prevPnl.totalOpex" class="w-full pl-7 pr-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-right font-bold"></div></div>
<div class="flex items-center gap-3"><span style="width:140px;font-size:11px;font-weight:600;color:#475569">Other Expenses</span><div class="relative flex-1"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="prevPnl.totalOther" class="w-full pl-7 pr-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-right font-bold"></div></div>
<div class="flex items-center gap-3"><span style="width:140px;font-size:11px;font-weight:600;color:#475569">Operating Profit</span><div class="relative flex-1"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="prevPnl.operatingProfit" class="w-full pl-7 pr-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-right font-bold"></div></div>
<div class="flex items-center gap-3"><span style="width:140px;font-size:11px;font-weight:600;color:#475569">Net Profit</span><div class="relative flex-1"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="prevPnl.netProfit" class="w-full pl-7 pr-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-right font-bold"></div></div>
<div class="flex items-center gap-3 pt-2 mt-2 border-t border-slate-200 dark:border-slate-700"><span style="width:140px;font-size:11px;font-weight:600;color:#0891b2">Closing Stock</span><div class="relative flex-1"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">&#8358;</span><input type="number" step="0.01" x-model.number="prevPnl.closingStock" class="w-full pl-7 pr-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-right font-bold"></div></div>
<div class="pt-3 mt-3 border-t border-slate-200 dark:border-slate-700"><button @click="savePrevPnl()" style="width:100%;padding:8px;background:#000;color:#f59e0b;font-size:10px;font-weight:800;border-radius:8px;border:1px solid rgba(245,158,11,0.3);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px"><i data-lucide="save" class="w-3.5 h-3.5"></i> Save Previous Values</button></div>
</div>
</div>

<!-- Live Comparison Table -->
<div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
<div style="background:#000;padding:10px 16px"><p style="font-size:9px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:1px">Comparison Summary</p></div>
<table style="width:100%;border-collapse:collapse;font-size:11px">
<thead><tr style="background:#f8fafc"><th style="padding:8px 12px;text-align:left;font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase">Item</th><th style="padding:8px 12px;text-align:right;font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase">Current</th><th style="padding:8px 12px;text-align:right;font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase">Previous</th><th style="padding:8px 12px;text-align:right;font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase">Variance</th></tr></thead>
<tbody>
<template x-for="row in [{l:'Total Revenue',c:pnl.totalRevenue,p:prevPnl.totalRevenue,up:true},{l:'Cost of Sales',c:pnl.cos,p:prevPnl.cos,up:false},{l:'Gross Profit',c:pnl.grossProfit,p:prevPnl.grossProfit,up:true},{l:'Operating Expenses',c:pnl.totalOpex,p:prevPnl.totalOpex,up:false},{l:'Other Expenses',c:pnl.totalOther,p:prevPnl.totalOther,up:false},{l:'Operating Profit',c:pnl.operatingProfit,p:prevPnl.operatingProfit,up:true},{l:'Net Profit',c:pnl.netProfit,p:prevPnl.netProfit,up:true},{l:'Closing Stock Valuation',c:pnl.closing,p:prevPnl.closingStock,up:true}]" :key="row.l">
<tr style="border-bottom:1px solid #f1f5f9">
<td style="padding:8px 12px;font-weight:700;color:#1e293b;font-size:11px" x-text="row.l"></td>
<td style="padding:8px 12px;text-align:right;font-weight:700;font-size:11px" x-text="fmt(row.c)"></td>
<td style="padding:8px 12px;text-align:right;font-weight:600;color:#64748b;font-size:11px" x-text="fmt(row.p)"></td>
<td style="padding:8px 12px;text-align:right;font-weight:700;font-size:11px" :style="'color:'+((row.up?(row.c-row.p)>=0:(row.c-row.p)<=0)?'#059669':'#dc2626')" x-text="((row.c-row.p)>=0?'▲ ':'▼ ')+fmt(Math.abs(row.c-row.p))"></td>
</tr>
</template>
</tbody>
</table>
</div>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-3 gap-4 mt-6" x-show="prevPnl.totalRevenue > 0 || prevPnl.netProfit !== 0">
<div :style="'background:'+(pnl.totalRevenue>=prevPnl.totalRevenue?'linear-gradient(135deg,#059669,#34d399)':'linear-gradient(135deg,#dc2626,#ef4444)')" style="border-radius:12px;padding:16px;text-align:center">
<p style="font-size:18px;font-weight:900;color:#fff" x-text="(pnl.totalRevenue>=prevPnl.totalRevenue?'▲ ':'▼ ')+(prevPnl.totalRevenue!==0?(((pnl.totalRevenue-prevPnl.totalRevenue)/Math.abs(prevPnl.totalRevenue))*100).toFixed(1):'—')+'%'"></p>
<p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;margin-top:4px">Revenue Change</p>
</div>
<div :style="'background:'+(pnl.netProfit>=prevPnl.netProfit?'linear-gradient(135deg,#059669,#34d399)':'linear-gradient(135deg,#dc2626,#ef4444)')" style="border-radius:12px;padding:16px;text-align:center">
<p style="font-size:18px;font-weight:900;color:#fff" x-text="(pnl.netProfit>=prevPnl.netProfit?'▲ ':'▼ ')+(prevPnl.netProfit!==0?(((pnl.netProfit-prevPnl.netProfit)/Math.abs(prevPnl.netProfit))*100).toFixed(1):'—')+'%'"></p>
<p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;margin-top:4px">Net Profit Change</p>
</div>
<div style="background:linear-gradient(135deg,#2563eb,#60a5fa);border-radius:12px;padding:16px;text-align:center">
<p style="font-size:18px;font-weight:900;color:#fff" x-text="((pnl.totalRevenue>0?(pnl.grossProfit/pnl.totalRevenue*100):0)-(prevPnl.totalRevenue>0?(prevPnl.grossProfit/prevPnl.totalRevenue*100):0)).toFixed(1)+'pp'"></p>
<p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;margin-top:4px">Margin Shift</p>
</div>
</div>

</div>
<div style="background:#000;padding:8px 40px;text-align:center"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Powered by Miemploya</p></div>
</div>

<!-- ════════════════════════════════════════════════════ -->
<!-- PAGE 8: AI RECOMMENDATION REPORT                    -->
<!-- ════════════════════════════════════════════════════ -->
<div style="page-break-before:always">
<div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center">
<p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">07. Recommendation Report</p>
<p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)" x-text="(activeReport.client_name||activeReport.title)+' — '+monthNames[activeReport.report_month-1]+' '+activeReport.report_year"></p>
</div>
<div style="padding:32px 40px">
<div class="flex items-center gap-2 mb-4 no-print">
<button @click="generateAIReport()" style="padding:8px 16px;background:#000;color:#f59e0b;font-size:10px;font-weight:800;border-radius:8px;border:1px solid rgba(245,158,11,0.3);display:flex;align-items:center;gap:6px;cursor:pointer"><i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Generate AI Report</button>
<button @click="saveAIReport()" style="padding:8px 16px;background:#f1f5f9;color:#000;font-size:10px;font-weight:800;border-radius:8px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:6px;cursor:pointer"><i data-lucide="save" class="w-3.5 h-3.5"></i> Save Report</button>
<span style="font-size:9px;color:#94a3b8;font-style:italic">Editable — modify the text below as needed</span>
</div>
<div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
<div style="padding:12px 20px;background:#000;display:flex;align-items:center;gap:8px"><div style="width:24px;height:24px;border-radius:6px;background:rgba(245,158,11,0.2);display:flex;align-items:center;justify-content:center"><i data-lucide="brain" class="w-3 h-3" style="color:#f59e0b"></i></div><span style="font-size:10px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:1px">Financial Analysis</span></div>
<div style="padding:20px">
<!-- Rich Text Toolbar -->
<div class="flex flex-wrap items-center gap-1 mb-2 p-2 bg-slate-50 dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 no-print">
<!-- Font Family -->
<select @change="document.execCommand('fontName',false,$event.target.value)" title="Font Style" class="h-7 px-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded text-[10px] font-semibold cursor-pointer">
<option value="" disabled selected>Font</option>
<option value="Inter" style="font-family:Inter">Inter</option>
<option value="Roboto" style="font-family:Roboto">Roboto</option>
<option value="Arial" style="font-family:Arial">Arial</option>
<option value="Georgia" style="font-family:Georgia">Georgia</option>
<option value="Times New Roman" style="font-family:'Times New Roman'">Times New Roman</option>
<option value="Courier New" style="font-family:'Courier New'">Courier New</option>
<option value="Verdana" style="font-family:Verdana">Verdana</option>
<option value="Trebuchet MS" style="font-family:'Trebuchet MS'">Trebuchet MS</option>
</select>
<div class="w-px h-5 bg-slate-300 dark:bg-slate-600 mx-0.5"></div>
<!-- Text Style -->
<button @click="document.execCommand('bold')" title="Bold" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-black">B</button>
<button @click="document.execCommand('italic')" title="Italic" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold italic">I</button>
<button @click="document.execCommand('underline')" title="Underline" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold underline">U</button>
<button @click="document.execCommand('strikeThrough')" title="Strikethrough" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold line-through">S</button>
<div class="w-px h-5 bg-slate-300 dark:bg-slate-600 mx-0.5"></div>
<!-- Headings -->
<button @click="document.execCommand('formatBlock','',`<h1>`)" title="Heading 1" class="px-1.5 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-[10px] font-black">H1</button>
<button @click="document.execCommand('formatBlock','',`<h2>`)" title="Heading 2" class="px-1.5 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-[10px] font-bold">H2</button>
<button @click="document.execCommand('formatBlock','',`<h3>`)" title="Heading 3" class="px-1.5 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-[10px] font-semibold">H3</button>
<button @click="document.execCommand('formatBlock','',`<p>`)" title="Normal text" class="px-1.5 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-[10px] font-medium">P</button>
<div class="w-px h-5 bg-slate-300 dark:bg-slate-600 mx-0.5"></div>
<!-- Lists -->
<button @click="document.execCommand('insertUnorderedList')" title="Bullet List" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700"><i data-lucide="list" class="w-3.5 h-3.5"></i></button>
<button @click="document.execCommand('insertOrderedList')" title="Numbered List" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700"><i data-lucide="list-ordered" class="w-3.5 h-3.5"></i></button>
<button @click="document.execCommand('insertHTML','','<div style=\'display:flex;align-items:center;gap:6px;margin:4px 0\'><input type=\'checkbox\' style=\'width:14px;height:14px;accent-color:#f59e0b\'><span>Task item</span></div>')" title="Checkbox" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700"><i data-lucide="check-square" class="w-3.5 h-3.5"></i></button>
<div class="w-px h-5 bg-slate-300 dark:bg-slate-600 mx-0.5"></div>
<!-- Alignment -->
<button @click="document.execCommand('justifyLeft')" title="Align Left" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700"><i data-lucide="align-left" class="w-3.5 h-3.5"></i></button>
<button @click="document.execCommand('justifyCenter')" title="Align Center" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700"><i data-lucide="align-center" class="w-3.5 h-3.5"></i></button>
<button @click="document.execCommand('justifyRight')" title="Align Right" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700"><i data-lucide="align-right" class="w-3.5 h-3.5"></i></button>
<div class="w-px h-5 bg-slate-300 dark:bg-slate-600 mx-0.5"></div>
<!-- Font Size -->
<div class="flex items-center gap-1">
<label class="text-[8px] font-bold text-slate-400">Size</label>
<input type="number" min="8" max="72" value="14" id="recFontSize" class="w-14 px-1.5 py-0.5 text-[10px] font-bold border border-slate-200 dark:border-slate-600 rounded text-center bg-white dark:bg-slate-800 focus:outline-none focus:border-amber-500" title="Font size in px">
<button @click="
    let size = document.getElementById('recFontSize').value || 14;
    let sel = window.getSelection();
    if (sel.rangeCount > 0 && !sel.isCollapsed) {
        let range = sel.getRangeAt(0);
        let span = document.createElement('span');
        span.style.fontSize = size + 'px';
        range.surroundContents(span);
    }
" title="Apply font size" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-[9px] font-bold text-amber-600">Go</button>
</div>
<!-- Colors -->
<div class="relative">
<input type="color" @input="document.execCommand('foreColor',false,$event.target.value)" title="Text Color" class="w-7 h-7 rounded cursor-pointer border border-slate-200" value="#000000">
</div>
<div class="relative">
<input type="color" @input="document.execCommand('hiliteColor',false,$event.target.value)" title="Highlight" class="w-7 h-7 rounded cursor-pointer border border-slate-200" value="#fef08a">
</div>
<div class="w-px h-5 bg-slate-300 dark:bg-slate-600 mx-0.5"></div>
<!-- Extras -->
<button @click="document.execCommand('insertHorizontalRule')" title="Horizontal Rule" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700"><i data-lucide="minus" class="w-3.5 h-3.5"></i></button>
<button @click="document.execCommand('removeFormat')" title="Clear Formatting" class="w-7 h-7 flex items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700"><i data-lucide="eraser" class="w-3.5 h-3.5"></i></button>
</div>
<!-- Editable Content Area -->
<div id="ai-report-editor" contenteditable="true" @input="aiReportText=$el.innerHTML" x-html="aiReportText" style="width:100%;min-height:300px;font-size:11px;line-height:1.7;color:#334155;outline:none;padding:4px 0" x-init="$watch('aiReportText', v => { if(document.activeElement !== $el) $el.innerHTML = v })"></div>
</div>
</div>
</div>
<div style="background:#000;padding:8px 40px;text-align:center"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Powered by Miemploya</p></div>
</div>

</div><!-- end report document -->
</div></template>
</div>


</div>

</main></div></div>

<!-- Create Report Modal -->
<div x-show="showCreateModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showCreateModal=false">
<div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-2xl p-6 w-full max-w-md mx-4 shadow-2xl" @click.stop>
<h3 class="text-lg font-black text-slate-900 dark:text-white mb-4">Create New P&L Report</h3>
<div class="space-y-3">
<div><label class="text-xs font-bold text-slate-500 block mb-1">Report Title</label><input type="text" x-model="createForm.title" placeholder="e.g. March Revenue Report" class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-xs font-bold text-slate-500 block mb-1">Industry *</label><select x-model="createForm.industry" class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"><option value="hospitality">Hospitality</option><option value="manufacturing">Manufacturing</option></select></div>
<div><label class="text-xs font-bold text-slate-500 block mb-1">Month *</label><select x-model="createForm.report_month" class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"><template x-for="(m,i) in monthNames" :key="i"><option :value="i+1" x-text="m"></option></template></select></div>
</div>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-xs font-bold text-slate-500 block mb-1">Year *</label><input type="number" x-model.number="createForm.report_year" min="2020" max="2099" class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
<div><label class="text-xs font-bold text-slate-500 block mb-1">Location / Branch</label><input type="text" x-model="createForm.location" placeholder="Branch name" class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
</div>
</div>
<div class="flex gap-3 mt-5">
<button @click="showCreateModal=false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 text-sm font-bold rounded-xl">Cancel</button>
<button @click="createReport()" :disabled="saving" class="flex-1 py-2.5 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-bold rounded-xl shadow-lg text-sm disabled:opacity-50" x-text="saving?'Creating...':'Create Report'"></button>
</div>
</div>
</div>

<?php include '../includes/dashboard_scripts.php'; ?>
<script src="pnl_app.js?v=<?= time() ?>"></script>
<script>lucide.createIcons();</script>
</body></html>
