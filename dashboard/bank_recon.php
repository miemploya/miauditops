<?php
require_once '../includes/functions.php';
require_login(); require_subscription('finance'); require_permission('finance'); require_active_client();
$company_id = $_SESSION['company_id']; $client_id = get_active_client();
$client_name = $_SESSION['active_client_name'] ?? 'Client';
$page_title = 'Bank Reconciliation';
?>
<!DOCTYPE html>
<html lang="en" class="h-full"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bank Reconciliation — MIAUDITOPS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<style>
[x-cloak]{display:none!important}
.glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95),rgba(249,250,251,0.9));backdrop-filter:blur(20px)}
.dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95),rgba(30,41,59,0.9))}
.recon-input{width:100%;padding:7px 10px;font-size:12px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;transition:border-color .2s,box-shadow .2s}
.recon-input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12)}
.dark .recon-input{background:#1e293b;border-color:#334155;color:#e2e8f0}
.item-row{display:flex;gap:8px;align-items:center;background:#f8fafc;border:1px solid #f1f5f9;border-radius:10px;padding:8px 10px;margin-bottom:5px;transition:all .2s}
.item-row:hover{border-color:#cbd5e1;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.dark .item-row{background:#0f172a;border-color:#1e293b}
</style>
</head>
<body class="h-full bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-200">
<div class="flex h-full" x-data="bankReconApp()" x-init="init(); $nextTick(() => lucide.createIcons())">
<?php include '../includes/dashboard_sidebar.php'; ?>
<div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
<?php include '../includes/dashboard_header.php'; ?>
<main class="flex-1 overflow-y-auto p-6 lg:p-8"><?php display_flash_message(); ?>

<!-- Loading -->
<div x-show="loadingRecord" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,.6);backdrop-filter:blur(4px)">
<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-8 flex flex-col items-center gap-4 border border-slate-200 dark:border-slate-700">
<div class="w-12 h-12 rounded-full border-4 border-slate-200 border-t-blue-500 animate-spin"></div>
<p class="text-sm font-bold">Loading...</p>
</div></div>

<!-- ═══ LIST VIEW ═══ -->
<template x-if="!activeRecord">
<div>
<div class="mb-6">
<div class="flex items-center justify-between mb-3">
<div class="flex items-center gap-3">
<div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/25"><i data-lucide="landmark" class="w-5 h-5 text-white"></i></div>
<div><h2 class="text-xl font-black text-slate-900 dark:text-white">Bank Reconciliation</h2><p class="text-xs text-slate-400 mt-0.5">Manage reconciliations for <strong class="text-blue-600"><?php echo htmlspecialchars($client_name); ?></strong></p></div>
</div>
<button @click="showCreateModal=true; $nextTick(()=>lucide.createIcons())" class="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-xs font-bold rounded-xl shadow-lg shadow-blue-500/30 hover:-translate-y-0.5 transition-all"><i data-lucide="plus" class="w-4 h-4"></i> New Reconciliation</button>
</div>
<div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/50 rounded-xl p-4">
<p class="text-xs text-blue-700 dark:text-blue-300 leading-relaxed"><strong>📋 Bank Reconciliation</strong> — Compare your bank statement balance with your cashbook balance. Add deposits in transit, direct credits, then subtract unpresented cheques and bank charges. Use the Cashbook Adjustment section to record debit/credit entries that align both balances.</p>
</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
<template x-for="r in records" :key="r.id"><div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden hover:shadow-xl hover:-translate-y-0.5 transition-all cursor-pointer group" @click="openRecord(r.id)">
<div class="p-5"><div class="flex items-start justify-between mb-2"><p class="text-sm font-black text-slate-900 dark:text-white group-hover:text-blue-600 transition-colors" x-text="r.title"></p><span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase" :class="r.status==='finalized'?'bg-emerald-100 text-emerald-700':'bg-amber-100 text-amber-700'" x-text="r.status"></span></div>
<div class="flex gap-2"><span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-600 text-[10px] font-bold" x-text="r.bank_name||'No Bank'"></span><span class="px-2 py-0.5 rounded-md bg-violet-50 text-violet-600 text-[10px] font-bold" x-text="r.statement_date||''"></span></div></div>
<div class="px-5 py-2 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between"><span class="text-[10px] text-slate-400" x-text="'By '+(r.first_name||'')"></span><button @click.stop="deleteRecord(r.id)" class="p-1 text-slate-400 hover:text-red-500 transition-colors"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></div>
</div></template>
</div>
<div x-show="records.length===0" class="text-center py-16"><div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center"><i data-lucide="landmark" class="w-7 h-7 text-slate-300"></i></div><p class="text-sm font-bold text-slate-400">No reconciliations yet</p></div>

<!-- CREATE MODAL -->
<div x-show="showCreateModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl p-8 w-full max-w-md border border-slate-200 dark:border-slate-700" @click.away="showCreateModal=false">
<div class="flex items-center gap-3 mb-5"><div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center"><i data-lucide="landmark" class="w-4 h-4 text-white"></i></div><h3 class="text-lg font-black">New Reconciliation</h3></div>
<div class="space-y-3">
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Title *</label><input x-model="newTitle" class="recon-input" placeholder="e.g. February 2026 Reconciliation"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Bank Name</label><input x-model="newBank" class="recon-input" placeholder="e.g. First Bank"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Account Number</label><input x-model="newAcct" class="recon-input" placeholder="e.g. 0012345678"></div>
<div><label class="text-xs font-bold text-slate-500 mb-1 block">Statement Date</label><input type="date" x-model="newDate" class="recon-input"></div>
</div>
<div class="flex justify-end gap-2 mt-6"><button @click="showCreateModal=false" class="px-4 py-2 text-xs font-bold text-slate-500 rounded-lg">Cancel</button><button @click="createRecord()" :disabled="saving" class="px-5 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-xs font-bold rounded-xl disabled:opacity-50">Create</button></div>
</div></div>
</div>
</template>

<!-- ═══ EDITOR VIEW ═══ -->
<template x-if="activeRecord">
<div>
<!-- Header -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-4">
<div class="flex items-center gap-3">
<button @click="goBack()" class="w-9 h-9 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center hover:bg-slate-50 transition-all shadow-sm"><i data-lucide="arrow-left" class="w-4 h-4"></i></button>
<div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/25"><i data-lucide="landmark" class="w-4 h-4 text-white"></i></div>
<div><h2 class="text-lg font-black text-slate-900 dark:text-white" x-text="activeRecord.title"></h2><p class="text-[10px] text-slate-400" x-text="(activeRecord.bank_name||'') + (activeRecord.account_number ? ' • Acct: ' + activeRecord.account_number : '')"></p></div>
</div>
<div class="flex items-center gap-2">
<select x-model="status" class="px-3 py-2 text-xs font-bold border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 shadow-sm"><option value="draft">Draft</option><option value="finalized">Finalized</option></select>
<button @click="saveRecord()" :disabled="saving" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white text-xs font-bold rounded-xl shadow-md hover:shadow-lg transition-all"><i data-lucide="save" class="w-3.5 h-3.5"></i> Save</button>
<button @click="exportPDF()" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-xs font-bold rounded-xl shadow-md hover:shadow-lg transition-all"><i data-lucide="download" class="w-3.5 h-3.5"></i> PDF</button>
</div>
</div>

<!-- Description -->
<div class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border border-blue-100 dark:border-blue-800/40 rounded-xl p-3 mb-4">
<p class="text-[10px] text-blue-700 dark:text-blue-300 leading-relaxed"><strong>📋 How it works:</strong> <strong>Section 1</strong> starts with the bank statement balance, adds deposits in transit and direct credits, then subtracts charges to arrive at the adjusted cashbook balance. <strong>Section 2</strong> starts with the cashbook balance and records debit/credit adjustments. Both adjusted balances should match when reconciled.</p>
</div>

<!-- Bank Details -->
<div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-sm p-4 mb-4">
<p class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-2">🏦 Bank Account Details</p>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
<div><label class="text-[10px] font-bold text-slate-400 mb-1 block">Bank Name</label><input x-model="activeRecord.bank_name" class="recon-input font-semibold" placeholder="e.g. First Bank"></div>
<div><label class="text-[10px] font-bold text-slate-400 mb-1 block">Account Number</label><input x-model="activeRecord.account_number" class="recon-input font-semibold" placeholder="e.g. 0012345678"></div>
<div><label class="text-[10px] font-bold text-slate-400 mb-1 block">Statement Date</label><input type="date" x-model="activeRecord.statement_date" class="recon-input font-semibold"></div>
<div><label class="text-[10px] font-bold text-slate-400 mb-1 block">Title</label><input x-model="activeRecord.title" class="recon-input font-semibold"></div>
</div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

<!-- ═══ SECTION 1: BANK RECONCILIATION STATEMENT ═══ -->
<div class="rounded-2xl border border-blue-200/60 dark:border-blue-800/60 bg-white dark:bg-slate-900 overflow-hidden shadow-sm">
<div class="bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 text-white px-5 py-3.5 flex items-center gap-2">
<div class="w-7 h-7 rounded-lg bg-white/15 flex items-center justify-center"><i data-lucide="building-2" class="w-3.5 h-3.5"></i></div>
<div><h3 class="text-xs font-black uppercase tracking-wider">Section 1 — Bank Reconciliation Statement</h3><p class="text-[9px] text-blue-200 mt-0.5">Start from the bank statement closing balance</p></div>
</div>
<div class="p-5 space-y-4">

<!-- Bank Statement Balance -->
<div>
<label class="text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-1 block">Balance as per Bank Statement</label>
<input type="number" step="0.01" x-model.number="bankBalance" class="w-full px-4 py-3 text-base font-black border-2 border-blue-200 dark:border-blue-800 rounded-xl bg-blue-50/50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none transition-all">
</div>

<!-- ADD Items -->
<div class="rounded-xl border border-emerald-200 dark:border-emerald-800/50 overflow-hidden">
<div class="bg-emerald-50 dark:bg-emerald-900/20 px-4 py-2 flex items-center justify-between">
<div class="flex items-center gap-2"><span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 text-[9px] font-bold uppercase">+ Add</span><span class="text-[10px] font-bold text-emerald-700 dark:text-emerald-300">Deposits in Transit</span></div>
<button @click="addRow(addItems); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1 px-2.5 py-1 bg-emerald-600 text-white text-[9px] font-bold rounded-lg hover:bg-emerald-700 transition-colors"><i data-lucide="plus" class="w-3 h-3"></i> Add</button>
</div>
<div class="px-3 py-2">
<p class="text-[8px] text-slate-400 mb-2">Deposits already made by the company but not yet reflected on the bank statement</p>
<template x-for="(item, idx) in addItems" :key="idx">
<div class="item-row">
<input x-model="item.description" placeholder="e.g. Cash lodgement 15/02" class="recon-input text-[11px] flex-1">
<input type="number" step="0.01" x-model.number="item.amount" class="recon-input text-[11px] font-bold text-right w-32" placeholder="0.00">
<button @click="removeRow(addItems, idx)" class="w-6 h-6 rounded-md bg-red-50 text-red-400 hover:bg-red-100 hover:text-red-600 flex items-center justify-center flex-shrink-0"><i data-lucide="x" class="w-3 h-3"></i></button>
</div>
</template>
<div x-show="addItems.length===0" class="text-center py-2"><p class="text-[10px] text-slate-400 italic">No items — click "Add"</p></div>
<div class="flex justify-between pt-2 mt-1 border-t border-emerald-100"><span class="text-[10px] font-black text-emerald-700 uppercase">Total Add</span><span class="text-xs font-black text-emerald-700" x-text="fmt(totalAdd)"></span></div>
</div>
</div>

<!-- Adjusted Bank Balance -->
<div class="bg-emerald-100 dark:bg-emerald-900/30 rounded-xl p-4 text-center">
<p class="text-[9px] font-black text-emerald-600 uppercase tracking-widest mb-1">Adjusted Bank Balance</p>
<p class="text-2xl font-black text-emerald-700" x-text="fmt(adjustedBankBalance)"></p>
</div>

<!-- LESS Items -->
<div class="rounded-xl border border-red-200 dark:border-red-800/50 overflow-hidden">
<div class="bg-red-50 dark:bg-red-900/20 px-4 py-2 flex items-center justify-between">
<div class="flex items-center gap-2"><span class="px-2 py-0.5 rounded bg-red-100 text-red-700 text-[9px] font-bold uppercase">− Less</span><span class="text-[10px] font-bold text-red-700 dark:text-red-300">Unpresented Cheques</span></div>
<button @click="addRow(lessItems); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1 px-2.5 py-1 bg-red-600 text-white text-[9px] font-bold rounded-lg hover:bg-red-700 transition-colors"><i data-lucide="plus" class="w-3 h-3"></i> Add</button>
</div>
<div class="px-3 py-2">
<p class="text-[8px] text-slate-400 mb-2">Cheques issued by the company but not yet presented to the bank for payment</p>
<template x-for="(item, idx) in lessItems" :key="idx">
<div class="item-row">
<input x-model="item.description" placeholder="e.g. Cheque #00456 — ABC Ltd" class="recon-input text-[11px] flex-1">
<input type="number" step="0.01" x-model.number="item.amount" class="recon-input text-[11px] font-bold text-right w-32" placeholder="0.00">
<button @click="removeRow(lessItems, idx)" class="w-6 h-6 rounded-md bg-red-50 text-red-400 hover:bg-red-100 hover:text-red-600 flex items-center justify-center flex-shrink-0"><i data-lucide="x" class="w-3 h-3"></i></button>
</div>
</template>
<div x-show="lessItems.length===0" class="text-center py-2"><p class="text-[10px] text-slate-400 italic">No items — click "Add"</p></div>
<div class="flex justify-between pt-2 mt-1 border-t border-red-100"><span class="text-[10px] font-black text-red-700 uppercase">Total Less</span><span class="text-xs font-black text-red-700" x-text="'(' + fmt(totalLess) + ')'"></span></div>
</div>
</div>

<!-- Adjusted Cashbook Balance -->
<div class="bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 rounded-xl p-5 text-center shadow-lg shadow-blue-500/20">
<p class="text-[9px] font-black text-blue-200 uppercase tracking-widest mb-1">Adjusted Cashbook Balance</p>
<p class="text-3xl font-black text-white" x-text="fmt(adjustedCashbookBalance)"></p>
</div>

</div>
</div>

<!-- ═══ SECTION 2: CASHBOOK ADJUSTMENT ═══ -->
<div class="rounded-2xl border border-violet-200/60 dark:border-violet-800/60 bg-white dark:bg-slate-900 overflow-hidden shadow-sm">
<div class="bg-gradient-to-r from-violet-600 via-violet-700 to-purple-700 text-white px-5 py-3.5 flex items-center gap-2">
<div class="w-7 h-7 rounded-lg bg-white/15 flex items-center justify-center"><i data-lucide="book-open" class="w-3.5 h-3.5"></i></div>
<div><h3 class="text-xs font-black uppercase tracking-wider">Section 2 — Cashbook Adjustment</h3><p class="text-[9px] text-violet-200 mt-0.5">Adjust the cashbook balance with debit/credit entries</p></div>
</div>
<div class="p-5 space-y-4">

<!-- Cashbook Balance -->
<div>
<label class="text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-1 block">Balance as per Cashbook</label>
<input type="number" step="0.01" x-model.number="cashbookBalance" class="w-full px-4 py-3 text-base font-black border-2 border-violet-200 dark:border-violet-800 rounded-xl bg-violet-50/50 dark:bg-violet-900/20 text-violet-800 dark:text-violet-200 focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 outline-none transition-all">
</div>

<!-- Debit Entries (Add to cashbook) -->
<div class="rounded-xl border border-emerald-200 dark:border-emerald-800/50 overflow-hidden">
<div class="bg-emerald-50 dark:bg-emerald-900/20 px-4 py-2 flex items-center justify-between">
<div class="flex items-center gap-2"><span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 text-[9px] font-bold uppercase">Debit</span><span class="text-[10px] font-bold text-emerald-700 dark:text-emerald-300">Add to Cashbook</span></div>
<button @click="addRow(cbDebits); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1 px-2.5 py-1 bg-emerald-600 text-white text-[9px] font-bold rounded-lg hover:bg-emerald-700 transition-colors"><i data-lucide="plus" class="w-3 h-3"></i> Add</button>
</div>
<div class="px-3 py-2">
<p class="text-[8px] text-slate-400 mb-2">Interest received, direct bank credits, refunds — items in bank statement not in your cashbook</p>
<template x-for="(item, idx) in cbDebits" :key="idx">
<div class="item-row">
<input x-model="item.description" placeholder="e.g. Interest Received / Direct Credit" class="recon-input text-[11px] flex-1">
<input type="number" step="0.01" x-model.number="item.amount" class="recon-input text-[11px] font-bold text-right w-32" placeholder="0.00">
<button @click="removeRow(cbDebits, idx)" class="w-6 h-6 rounded-md bg-red-50 text-red-400 hover:bg-red-100 hover:text-red-600 flex items-center justify-center flex-shrink-0"><i data-lucide="x" class="w-3 h-3"></i></button>
</div>
</template>
<div x-show="cbDebits.length===0" class="text-center py-2"><p class="text-[10px] text-slate-400 italic">No debit entries</p></div>
<div class="flex justify-between pt-2 mt-1 border-t border-emerald-100"><span class="text-[10px] font-black text-emerald-700 uppercase">Total Debits</span><span class="text-xs font-black text-emerald-700" x-text="fmt(totalCbDebits)"></span></div>
</div>
</div>

<!-- Credit Entries (Subtract from cashbook) -->
<div class="rounded-xl border border-red-200 dark:border-red-800/50 overflow-hidden">
<div class="bg-red-50 dark:bg-red-900/20 px-4 py-2 flex items-center justify-between">
<div class="flex items-center gap-2"><span class="px-2 py-0.5 rounded bg-red-100 text-red-700 text-[9px] font-bold uppercase">Credit</span><span class="text-[10px] font-bold text-red-700 dark:text-red-300">Subtract from Cashbook</span></div>
<button @click="addRow(cbCredits); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1 px-2.5 py-1 bg-red-600 text-white text-[9px] font-bold rounded-lg hover:bg-red-700 transition-colors"><i data-lucide="plus" class="w-3 h-3"></i> Add</button>
</div>
<div class="px-3 py-2">
<p class="text-[8px] text-slate-400 mb-2">Bank charges, FGN stamp duty, NIP/transfer fees, account maintenance, VAT on maintenance</p>
<template x-for="(item, idx) in cbCredits" :key="idx">
<div class="item-row">
<input x-model="item.description" placeholder="e.g. Bank Charges / Stamp Duty / NIP" class="recon-input text-[11px] flex-1">
<input type="number" step="0.01" x-model.number="item.amount" class="recon-input text-[11px] font-bold text-right w-32" placeholder="0.00">
<button @click="removeRow(cbCredits, idx)" class="w-6 h-6 rounded-md bg-red-50 text-red-400 hover:bg-red-100 hover:text-red-600 flex items-center justify-center flex-shrink-0"><i data-lucide="x" class="w-3 h-3"></i></button>
</div>
</template>
<div x-show="cbCredits.length===0" class="text-center py-2"><p class="text-[10px] text-slate-400 italic">No credit entries</p></div>
<div class="flex justify-between pt-2 mt-1 border-t border-red-100"><span class="text-[10px] font-black text-red-700 uppercase">Total Credits</span><span class="text-xs font-black text-red-700" x-text="'(' + fmt(totalCbCredits) + ')'"></span></div>
</div>
</div>

<!-- Adjusted Cashbook Balance (Section 2) -->
<div class="bg-gradient-to-r from-violet-600 via-violet-700 to-purple-700 rounded-xl p-5 text-center shadow-lg shadow-violet-500/20">
<p class="text-[9px] font-black text-violet-200 uppercase tracking-widest mb-1">Adjusted Cashbook Balance</p>
<p class="text-3xl font-black text-white" x-text="fmt(adjustedCbBalance)"></p>
</div>

</div>
</div>
</div>

<!-- ═══ RECONCILIATION RESULT ═══ -->
<div class="mt-6 rounded-2xl border-2 overflow-hidden transition-all" :class="isReconciled ? 'border-emerald-400 shadow-lg shadow-emerald-500/10' : 'border-red-400 shadow-lg shadow-red-500/10'">
<div class="p-8 text-center" :class="isReconciled ? 'bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-emerald-900/20 dark:to-teal-900/20' : 'bg-gradient-to-br from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20'">
<div class="w-16 h-16 mx-auto mb-3 rounded-2xl flex items-center justify-center text-4xl" :class="isReconciled ? 'bg-emerald-100 dark:bg-emerald-800/30' : 'bg-red-100 dark:bg-red-800/30'" x-text="isReconciled ? '✅' : '❌'"></div>
<h3 class="text-lg font-black uppercase tracking-wide" :class="isReconciled ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'" x-text="isReconciled ? 'RECONCILED — Balances Match!' : 'DISCREPANCY DETECTED'"></h3>
<template x-if="!isReconciled"><p class="text-sm font-bold text-red-600 mt-1">Difference: <span class="text-base" x-text="fmt(Math.abs(discrepancy))"></span></p></template>
<div class="flex justify-center gap-8 mt-5">
<div class="bg-white dark:bg-slate-800 rounded-xl px-6 py-3 shadow-sm border border-slate-200 dark:border-slate-700"><p class="text-[9px] font-black text-blue-500 uppercase tracking-wider mb-0.5">Section 1 Result</p><p class="text-xl font-black text-blue-700 dark:text-blue-400" x-text="fmt(adjustedCashbookBalance)"></p></div>
<div class="bg-white dark:bg-slate-800 rounded-xl px-6 py-3 shadow-sm border border-slate-200 dark:border-slate-700"><p class="text-[9px] font-black text-violet-500 uppercase tracking-wider mb-0.5">Section 2 Result</p><p class="text-xl font-black text-violet-700 dark:text-violet-400" x-text="fmt(adjustedCbBalance)"></p></div>
</div>
</div>
</div>

<!-- Notes -->
<div class="mt-6 rounded-2xl border border-slate-200/60 dark:border-slate-700/60 bg-white dark:bg-slate-900 overflow-hidden shadow-sm">
<div class="bg-gradient-to-r from-slate-700 to-slate-800 px-5 py-3 flex items-center gap-2"><i data-lucide="file-text" class="w-3.5 h-3.5 text-slate-300"></i><h3 class="text-xs font-black text-white uppercase tracking-wider">Additional Notes</h3></div>
<div class="p-5"><div id="recon-notes-editor" contenteditable="true" @input="notes=$el.innerHTML" x-html="notes" style="width:100%;min-height:100px;font-size:12px;line-height:1.7;color:#334155;outline:none;border:1px solid #e2e8f0;border-radius:10px;padding:12px" x-init="$watch('notes', v => { if(document.activeElement !== $el) $el.innerHTML = v })"></div></div>
</div>

</div>
</template>

</main></div></div>
<script src="bank_recon_app.js"></script>
<script>document.addEventListener('DOMContentLoaded', () => lucide.createIcons());</script>
</body></html>
