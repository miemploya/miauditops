<?php
require_once '../includes/functions.php';
require_login(); require_subscription('finance'); require_permission('finance'); require_active_client();
$company_id = $_SESSION['company_id']; $client_id = get_active_client();
$client_name = $_SESSION['active_client_name'] ?? 'Client';
$company = get_company($company_id);
$company_name = $company['name'] ?? 'Company';
$page_title = 'Capital Allowance';
?>
<!DOCTYPE html>
<html lang="en" class="h-full"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Capital Allowance — MIAUDITOPS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<style>
[x-cloak]{display:none!important}
.glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95),rgba(249,250,251,0.9));backdrop-filter:blur(20px)}
.ca-input{width:100%;padding:7px 10px;font-size:12px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;transition:border-color .2s,box-shadow .2s}
.ca-input:focus{outline:none;border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.12)}
.dark .ca-input{background:#1e293b;border-color:#334155;color:#e2e8f0}
.sched-cell{padding:5px 8px;font-size:10px;text-align:right;font-variant-numeric:tabular-nums;border-bottom:1px solid #f1f5f9}
.sched-label{padding:5px 10px;font-size:10px;text-align:left;border-bottom:1px solid #f1f5f9}
</style>
</head>
<body class="h-full bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-200">
<div class="flex h-full" x-data="capitalAllowanceApp()" x-init="init(); $nextTick(()=>lucide.createIcons())">
<?php include '../includes/dashboard_sidebar.php'; ?>
<div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
<?php include '../includes/dashboard_header.php'; ?>
<main class="flex-1 overflow-y-auto p-6 lg:p-8"><?php display_flash_message(); ?>

<!-- Loading -->
<div x-show="loading" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,.6);backdrop-filter:blur(4px)">
<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-8 flex flex-col items-center gap-4 border border-slate-200 dark:border-slate-700">
<div class="w-12 h-12 rounded-full border-4 border-slate-200 border-t-amber-500 animate-spin"></div>
<p class="text-sm font-bold">Loading...</p>
</div></div>

<!-- ═══ LIST VIEW ═══ -->
<template x-if="!activeRecord">
<div>
<div class="mb-6">
<div class="flex items-center justify-between mb-3">
<div class="flex items-center gap-3">
<div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/25"><i data-lucide="calculator" class="w-5 h-5 text-white"></i></div>
<div><h2 class="text-xl font-black text-slate-900 dark:text-white">Capital Allowance</h2><p class="text-xs text-slate-400 mt-0.5">Tax depreciation schedules for <strong class="text-amber-600"><?php echo htmlspecialchars($client_name); ?></strong></p></div>
</div>
<button @click="showCreateModal=true; $nextTick(()=>lucide.createIcons())" class="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white text-xs font-bold rounded-xl shadow-lg shadow-amber-500/30 hover:-translate-y-0.5 transition-all"><i data-lucide="plus" class="w-4 h-4"></i> New CA Schedule</button>
</div>
<div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/40 rounded-xl p-4">
<p class="text-xs text-amber-700 dark:text-amber-300 leading-relaxed"><strong>📋 Capital Allowance</strong> — Tax depreciation computed using Initial Allowance (IA) and Annual Allowance (AA) rates per Nigerian CITA rules. Create from the <strong>Asset Register</strong> or enter assets <strong>manually</strong>. Generate multi-year schedules with per-year additions.</p>
</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
<template x-for="r in records" :key="r.id"><div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden hover:shadow-xl hover:-translate-y-0.5 transition-all cursor-pointer group" @click="openRecord(r.id)">
<div class="p-5">
<div class="flex items-start justify-between mb-2"><p class="text-sm font-black text-slate-900 dark:text-white group-hover:text-amber-600 transition-colors" x-text="r.title"></p><span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase" :class="r.status==='finalized'?'bg-emerald-100 text-emerald-700':'bg-amber-100 text-amber-700'" x-text="r.status"></span></div>
<div class="flex gap-2"><span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-700 text-[10px] font-bold" x-text="r.start_year + ' – ' + r.end_year"></span><span class="px-2 py-0.5 rounded-md text-[10px] font-bold" :class="r.mode==='asset_register'?'bg-violet-50 text-violet-600':'bg-blue-50 text-blue-600'" x-text="r.mode==='asset_register'?'Asset Register':'Manual'"></span></div>
</div>
<div class="px-5 py-2 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between"><span class="text-[10px] text-slate-400" x-text="'By '+(r.first_name||'')"></span><button @click.stop="deleteRecord(r.id)" class="p-1 text-slate-400 hover:text-red-500 transition-colors"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></div>
</div></template>
</div>
<div x-show="records.length===0" class="text-center py-16"><div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center"><i data-lucide="calculator" class="w-7 h-7 text-slate-300"></i></div><p class="text-sm font-bold text-slate-400">No CA schedules yet</p></div>

<!-- CREATE MODAL -->
<div x-show="showCreateModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-8 w-full max-w-md border border-slate-200 dark:border-slate-700" @click.away="showCreateModal=false">
<div class="flex items-center gap-3 mb-5">
<div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center"><i data-lucide="calculator" class="w-4 h-4 text-white"></i></div>
<h3 class="text-lg font-black">New CA Schedule</h3>
</div>
<div class="space-y-3">
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Title *</label><input x-model="newTitle" class="ca-input" placeholder="e.g. Capital Allowance 2020-2025"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Data Source</label>
<div class="grid grid-cols-2 gap-2">
<button @click="newMode='asset_register'" :class="newMode==='asset_register'?'border-violet-500 bg-violet-50 text-violet-700':'border-slate-200 text-slate-500'" class="p-3 rounded-xl border-2 text-center transition-all"><p class="text-xs font-bold">📦 Asset Register</p><p class="text-[9px] mt-1 opacity-70">Auto-pull from Fixed Assets</p></button>
<button @click="newMode='manual'" :class="newMode==='manual'?'border-blue-500 bg-blue-50 text-blue-700':'border-slate-200 text-slate-500'" class="p-3 rounded-xl border-2 text-center transition-all"><p class="text-xs font-bold">✏️ Manual Entry</p><p class="text-[9px] mt-1 opacity-70">Enter from scratch</p></button>
</div></div>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Start Year</label><input type="number" x-model.number="newStartYear" class="ca-input"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">End Year</label><input type="number" x-model.number="newEndYear" class="ca-input"></div>
</div>
</div>
<div class="flex justify-end gap-2 mt-6"><button @click="showCreateModal=false" class="px-4 py-2 text-xs font-bold text-slate-500 rounded-lg">Cancel</button><button @click="createRecord()" :disabled="saving" class="px-5 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white text-xs font-bold rounded-xl disabled:opacity-50">Create</button></div>
</div>
</div>
</div>
</template>

<!-- ═══ EDITOR VIEW ═══ -->
<template x-if="activeRecord">
<div>
<!-- Header -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-4">
<div class="flex items-center gap-3">
<button @click="goBack()" class="w-9 h-9 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center hover:bg-slate-50 transition-all shadow-sm"><i data-lucide="arrow-left" class="w-4 h-4"></i></button>
<div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/25"><i data-lucide="calculator" class="w-4 h-4 text-white"></i></div>
<div><h2 class="text-lg font-black text-slate-900 dark:text-white" x-text="activeRecord.title"></h2><p class="text-[10px] text-slate-400"><span class="font-semibold text-indigo-600"><?= htmlspecialchars($company_name) ?></span> · <span class="px-1.5 py-0.5 rounded text-[9px] font-bold" :class="activeRecord.mode==='asset_register'?'bg-violet-100 text-violet-600':'bg-blue-100 text-blue-600'" x-text="activeRecord.mode==='asset_register'?'Asset Register':'Manual'"></span> <span x-text="activeRecord.start_year + ' – ' + activeRecord.end_year"></span></p></div>
</div>
<div class="flex items-center gap-2">
<button @click="showCategoriesPanel = !showCategoriesPanel; $nextTick(()=>lucide.createIcons())" :class="showCategoriesPanel ? 'bg-violet-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700'" class="flex items-center gap-2 px-4 py-2 text-xs font-bold rounded-xl shadow-sm hover:shadow-md transition-all"><i data-lucide="settings-2" class="w-3.5 h-3.5"></i> Categories</button>
<button @click="saveRecord()" :disabled="saving" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-amber-500 to-amber-600 text-white text-xs font-bold rounded-xl shadow-md hover:shadow-lg transition-all"><i data-lucide="save" class="w-3.5 h-3.5"></i> Save</button>
<button @click="exportPDF()" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-xs font-bold rounded-xl shadow-md hover:shadow-lg transition-all"><i data-lucide="download" class="w-3.5 h-3.5"></i> PDF</button>
</div>
</div>

<!-- Description -->
<div class="bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-900/20 dark:to-orange-900/20 border border-amber-100 dark:border-amber-800/40 rounded-xl p-3 mb-4">
<p class="text-[10px] text-amber-700 dark:text-amber-300 leading-relaxed"><strong>📋 How it works:</strong> Click a <strong>year tab</strong> to view that year's schedule. Use <strong>"+ Add Entry"</strong> to add asset additions, openings, or disposals for any year. The schedule <strong>auto-cascades</strong> — editing an earlier year recalculates all downstream years. IA applies only to additions; AA applies to the tax written-down value.</p>
</div>

<!-- Categories Management Panel -->
<div x-show="showCategoriesPanel" x-transition x-cloak class="bg-white dark:bg-slate-900 rounded-2xl border border-violet-200 dark:border-violet-800/40 shadow-lg mb-4 overflow-hidden">
<div class="bg-gradient-to-r from-violet-600 to-purple-700 px-5 py-3 flex items-center justify-between">
<div class="flex items-center gap-2">
<i data-lucide="layers" class="w-4 h-4 text-white"></i>
<h3 class="text-xs font-black text-white uppercase tracking-wider">Asset Categories & Rates</h3>
</div>
<button @click="openAddRate(); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1 px-3 py-1.5 bg-white/20 hover:bg-white/30 text-white text-[9px] font-bold rounded-lg transition-colors"><i data-lucide="plus" class="w-3 h-3"></i> Add Category</button>
</div>
<div class="p-4">
<p class="text-[10px] text-slate-400 mb-3">Define asset categories with their Initial Allowance (IA) and Annual Allowance (AA) rates. These rates determine how capital allowances are calculated.</p>
<div class="space-y-1.5">
<template x-for="r in rates" :key="r.id">
<div class="flex items-center gap-3 bg-slate-50 dark:bg-slate-800/30 rounded-xl px-4 py-2.5 group hover:bg-slate-100 dark:hover:bg-slate-800/50 transition-all">
<div class="w-7 h-7 rounded-lg bg-gradient-to-br from-violet-500/20 to-purple-500/20 flex items-center justify-center shrink-0"><i data-lucide="tag" class="w-3.5 h-3.5 text-violet-600"></i></div>
<div class="flex-1 min-w-0">
<p class="text-xs font-bold text-slate-700 dark:text-slate-200" x-text="r.category"></p>
<div class="flex gap-3 mt-0.5">
<span class="text-[9px] text-slate-400">IA: <strong class="text-emerald-600" x-text="r.ia_rate + '%'"></strong></span>
<span class="text-[9px] text-slate-400">AA: <strong class="text-blue-600" x-text="r.aa_rate + '%'"></strong></span>
</div>
</div>
<div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
<button @click="openEditRate(r); $nextTick(()=>lucide.createIcons())" class="p-1.5 rounded-lg text-slate-400 hover:text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-all" title="Edit"><i data-lucide="pencil" class="w-3 h-3"></i></button>
<button @click="deleteRate(r.id)" class="p-1.5 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all" title="Delete"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
</div>
</div>
</template>
</div>
<div x-show="rates.length === 0" class="text-center py-6"><p class="text-[10px] text-slate-400 italic">No categories defined — click "Add Category" to create one</p></div>
</div>
</div>

<!-- Year Tabs -->
<div class="flex gap-1 p-1 bg-slate-100 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 mb-4 overflow-x-auto">
<template x-for="y in years" :key="y">
<button @click="selectedYear=y" :class="selectedYear===y?'bg-white dark:bg-slate-900 text-amber-600 shadow-sm':'text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-xs font-bold rounded-lg transition-all whitespace-nowrap" x-text="y"></button>
</template>
</div>

<!-- Entries for selected year (Manual mode) -->
<template x-if="activeRecord.mode==='manual'">
<div class="glass-card rounded-xl border border-slate-200/60 dark:border-slate-700/60 p-4 mb-4">
<div class="flex items-center justify-between mb-3">
<p class="text-[10px] font-black text-slate-500 uppercase tracking-wider">📝 Entries for <span x-text="selectedYear"></span></p>
<button @click="openAddEntry(selectedYear); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1 px-3 py-1.5 bg-amber-600 text-white text-[9px] font-bold rounded-lg hover:bg-amber-700 transition-colors"><i data-lucide="plus" class="w-3 h-3"></i> Add Entry</button>
</div>
<div class="space-y-1">
<template x-for="(e, idx) in entries.filter(e => +e.year === selectedYear)" :key="idx">
<div class="flex items-center gap-2 bg-slate-50 dark:bg-slate-800/30 rounded-lg px-3 py-2">
<span class="px-1.5 py-0.5 rounded text-[8px] font-bold uppercase" :class="e.type==='addition'?'bg-emerald-100 text-emerald-700':e.type==='opening'?'bg-blue-100 text-blue-700':'bg-red-100 text-red-700'" x-text="e.type"></span>
<span class="text-[10px] font-bold text-slate-700 flex-1" x-text="e.category"></span>
<span class="text-[10px] font-bold text-slate-900" x-text="fmt(e.amount)"></span>
<span class="text-[9px] text-slate-400 max-w-[120px] truncate" x-text="e.description"></span>
<button @click="entries.splice(entries.indexOf(e), 1)" class="p-1 text-slate-400 hover:text-red-500"><i data-lucide="x" class="w-3 h-3"></i></button>
</div>
</template>
<div x-show="entries.filter(e => +e.year === selectedYear).length === 0" class="text-center py-4"><p class="text-[10px] text-slate-400 italic">No entries for this year — click "Add Entry" to start</p></div>
</div>
</div>
</template>

<!-- Schedule Table for Selected Year -->
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/60 dark:border-slate-700/60 overflow-hidden shadow-sm">
<div class="bg-gradient-to-r from-slate-800 to-slate-900 px-5 py-3">
<h3 class="text-xs font-black text-white uppercase tracking-wider">Capital Allowance Schedule — Year Ended 31st December <span x-text="selectedYear"></span></h3>
</div>
<div class="overflow-x-auto">
<table class="w-full">
<thead><tr class="bg-slate-700">
<th class="sched-label text-[8px] font-black text-slate-300 uppercase tracking-wider" style="min-width:130px"></th>
<template x-for="c in scheduleCategories()" :key="'h'+c"><th class="sched-cell text-[8px] font-black text-slate-300 uppercase tracking-wider text-center" style="min-width:90px" x-text="c"></th></template>
<th class="sched-cell text-[8px] font-black text-amber-400 uppercase tracking-wider text-center" style="min-width:100px">TOTAL</th>
</tr></thead>
<tbody>
<!-- COST -->
<tr class="bg-black"><td :colspan="scheduleCategories().length + 2" class="px-4 py-2 text-[9px] font-black text-amber-400 uppercase tracking-widest">Cost</td></tr>
<tr class="bg-slate-50 dark:bg-slate-800/30">
<td class="sched-label font-bold" x-text="'As at 1/1/' + selectedYear"></td>
<template x-for="c in scheduleCategories()" :key="'oc'+c"><td class="sched-cell font-semibold" x-text="getYearSchedule(selectedYear)[c]?.openingCost ? fmt(getYearSchedule(selectedYear)[c].openingCost) : '—'"></td></template>
<td class="sched-cell font-black" x-text="fmt(yearTotal(selectedYear,'openingCost'))"></td>
</tr>
<tr>
<td class="sched-label" style="padding-left:18px">Additions</td>
<template x-for="c in scheduleCategories()" :key="'ad'+c"><td class="sched-cell" x-text="getYearSchedule(selectedYear)[c]?.additions ? fmt(getYearSchedule(selectedYear)[c].additions) : '—'"></td></template>
<td class="sched-cell font-bold" x-text="fmt(yearTotal(selectedYear,'additions'))"></td>
</tr>
<tr>
<td class="sched-label" style="padding-left:18px">Disposal</td>
<template x-for="c in scheduleCategories()" :key="'dp'+c"><td class="sched-cell" x-text="getYearSchedule(selectedYear)[c]?.disposals ? fmt(getYearSchedule(selectedYear)[c].disposals) : '0'"></td></template>
<td class="sched-cell font-bold" x-text="fmt(yearTotal(selectedYear,'disposals'))"></td>
</tr>
<tr class="bg-slate-100 dark:bg-slate-800/50 border-t-2 border-slate-300">
<td class="sched-label font-black" x-text="'As at 31/12/' + selectedYear"></td>
<template x-for="c in scheduleCategories()" :key="'cc'+c"><td class="sched-cell font-black" x-text="fmt(getYearSchedule(selectedYear)[c]?.closingCost||0)"></td></template>
<td class="sched-cell font-black text-blue-700" x-text="fmt(yearTotal(selectedYear,'closingCost'))"></td>
</tr>

<!-- IA -->
<tr class="bg-black"><td :colspan="scheduleCategories().length + 2" class="px-4 py-2 text-[9px] font-black text-amber-400 uppercase tracking-widest">Initial Allowance (IA)</td></tr>
<tr class="bg-slate-50 dark:bg-slate-800/30">
<td class="sched-label font-bold italic text-slate-500">IA Rate</td>
<template x-for="c in scheduleCategories()" :key="'ir'+c"><td class="sched-cell italic text-slate-500" x-text="getYearSchedule(selectedYear)[c]?.iaRate > 0 ? getYearSchedule(selectedYear)[c].iaRate + '%' : 'N/A'"></td></template>
<td class="sched-cell"></td>
</tr>
<tr>
<td class="sched-label font-bold">IA on Additions</td>
<template x-for="c in scheduleCategories()" :key="'ia'+c"><td class="sched-cell font-bold text-emerald-700" x-text="getYearSchedule(selectedYear)[c]?.ia ? fmt(getYearSchedule(selectedYear)[c].ia) : '—'"></td></template>
<td class="sched-cell font-black text-emerald-700" x-text="fmt(yearTotal(selectedYear,'ia'))"></td>
</tr>

<!-- AA -->
<tr class="bg-black"><td :colspan="scheduleCategories().length + 2" class="px-4 py-2 text-[9px] font-black text-amber-400 uppercase tracking-widest">Annual Allowance (AA)</td></tr>
<tr class="bg-slate-50 dark:bg-slate-800/30">
<td class="sched-label font-bold italic text-slate-500">AA Rate</td>
<template x-for="c in scheduleCategories()" :key="'ar'+c"><td class="sched-cell italic text-slate-500" x-text="getYearSchedule(selectedYear)[c]?.aaRate > 0 ? getYearSchedule(selectedYear)[c].aaRate + '%' : 'N/A'"></td></template>
<td class="sched-cell"></td>
</tr>
<tr>
<td class="sched-label">Tax WDV b/f</td>
<template x-for="c in scheduleCategories()" :key="'wb'+c"><td class="sched-cell" x-text="getYearSchedule(selectedYear)[c]?.taxWdvBf ? fmt(getYearSchedule(selectedYear)[c].taxWdvBf) : '—'"></td></template>
<td class="sched-cell font-bold" x-text="fmt(yearTotal(selectedYear,'taxWdvBf'))"></td>
</tr>
<tr>
<td class="sched-label font-bold">AA for the Year</td>
<template x-for="c in scheduleCategories()" :key="'aa'+c"><td class="sched-cell font-bold text-blue-700" x-text="getYearSchedule(selectedYear)[c]?.aa ? fmt(getYearSchedule(selectedYear)[c].aa) : '—'"></td></template>
<td class="sched-cell font-black text-blue-700" x-text="fmt(yearTotal(selectedYear,'aa'))"></td>
</tr>

<!-- Spacer -->
<tr><td :colspan="scheduleCategories().length + 2" style="height:8px;border:none"></td></tr>

<!-- Tax WDV c/f -->
<tr class="bg-black">
<td class="px-4 py-2.5 text-[10px] font-black text-white uppercase tracking-wider" x-text="'Tax WDV c/f 31/12/' + selectedYear"></td>
<template x-for="c in scheduleCategories()" :key="'wc'+c"><td class="sched-cell font-black" style="background:#000;border-color:#1e293b;color:#34d399" x-text="fmt(getYearSchedule(selectedYear)[c]?.taxWdvCf||0)"></td></template>
<td class="sched-cell font-black" style="background:#000;border-color:#1e293b;color:#34d399" x-text="fmt(yearTotal(selectedYear,'taxWdvCf'))"></td>
</tr>

<!-- Spacer -->
<tr><td :colspan="scheduleCategories().length + 2" style="height:8px;border:none"></td></tr>

<!-- Total Allowance -->
<tr style="background:#059669">
<td class="px-4 py-2.5 text-[10px] font-black text-white uppercase tracking-wider">Total Allowance (IA + AA)</td>
<template x-for="c in scheduleCategories()" :key="'ta'+c"><td class="sched-cell font-black" style="background:#059669;border-color:#047857;color:#fff" x-text="fmt(getYearSchedule(selectedYear)[c]?.totalAllowance||0)"></td></template>
<td class="sched-cell font-black" style="background:#059669;border-color:#047857;color:#fff;font-size:12px" x-text="fmt(yearTotal(selectedYear,'totalAllowance'))"></td>
</tr>
</tbody>
</table>
</div>
</div>

<!-- Grand Total -->
<div class="mt-4 bg-gradient-to-r from-amber-500 to-orange-600 rounded-2xl p-5 text-center shadow-lg shadow-amber-500/20">
<p class="text-[9px] font-black text-amber-200 uppercase tracking-widest mb-1">Grand Total Allowance (<span x-text="activeRecord.start_year"></span> – <span x-text="activeRecord.end_year"></span>)</p>
<p class="text-3xl font-black text-white" x-text="fmt(grandTotalAllowance)"></p>
</div>

</div>
</template>

<!-- ═══ ADD ENTRY MODAL ═══ -->
<div x-show="showAdditionModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-6 w-full max-w-sm border border-slate-200 dark:border-slate-700" @click.away="showAdditionModal=false">
<h3 class="text-lg font-black mb-4">Add Entry — <span x-text="selectedYear"></span></h3>
<div class="space-y-3">
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Type</label>
<select x-model="addForm.type" class="ca-input"><option value="opening">Opening Balance</option><option value="addition">Addition</option><option value="disposal">Disposal</option></select></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Category</label>
<select x-model="addForm.category" class="ca-input"><template x-for="r in rates" :key="r.id"><option :value="r.category" x-text="r.category"></option></template></select></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Amount (₦)</label><input type="number" step="0.01" x-model.number="addForm.amount" class="ca-input"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Description</label><input x-model="addForm.description" class="ca-input" placeholder="e.g. New generator"></div>
</div>
<div class="flex justify-end gap-2 mt-5"><button @click="showAdditionModal=false" class="px-4 py-2 text-xs font-bold text-slate-500 rounded-lg">Cancel</button><button @click="addEntry()" class="px-5 py-2.5 bg-amber-600 text-white text-xs font-bold rounded-xl">Add</button></div>
</div>
</div>

<!-- ═══ RATE MODAL ═══ -->
<div x-show="showRateModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-6 w-full max-w-sm border border-slate-200 dark:border-slate-700" @click.away="showRateModal=false">
<h3 class="text-lg font-black mb-4" x-text="editingRate ? 'Edit Rate' : 'Add Rate'"></h3>
<div class="space-y-3">
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Category *</label><input x-model="rateForm.category" class="ca-input" placeholder="e.g. Motor Vehicles"></div>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-xs font-bold text-slate-500 mb-1 block">IA Rate (%)</label><input type="number" step="0.5" x-model.number="rateForm.ia_rate" class="ca-input"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">AA Rate (%)</label><input type="number" step="0.5" x-model.number="rateForm.aa_rate" class="ca-input"></div>
</div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Sort Order</label><input type="number" x-model.number="rateForm.sort_order" class="ca-input"></div>
</div>
<div class="flex justify-end gap-2 mt-5"><button @click="showRateModal=false" class="px-4 py-2 text-xs font-bold text-slate-500 rounded-lg">Cancel</button><button @click="saveRate()" :disabled="saving" class="px-5 py-2.5 bg-amber-600 text-white text-xs font-bold rounded-xl disabled:opacity-50">Save</button></div>
</div>
</div>

</main></div></div>
<script src="capital_allowance_app.js"></script>
<script>document.addEventListener('DOMContentLoaded', () => lucide.createIcons());</script>
</body></html>
