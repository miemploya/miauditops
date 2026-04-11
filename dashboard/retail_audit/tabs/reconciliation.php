<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Variance & Reconciliation</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">Review completed physical counts and map them against system expectations to calculate variances.</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
    <!-- Sessions List (Left Sidebar) -->
    <div class="lg:col-span-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden shadow-sm flex flex-col h-[600px]">
        <div class="p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
            <h3 class="font-bold text-slate-800 dark:text-slate-200 mb-2">Audit Snapshots</h3>
            <div class="relative">
                <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
                <input type="text" id="reconSessionSearch" placeholder="Filter..." class="w-full pl-10 pr-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-xs focus:ring-2 focus:ring-purple-500" onkeyup="filterReconSessions()">
            </div>
        </div>
        <div class="flex-1 overflow-y-auto w-full p-2 space-y-1" id="reconSessionList">
            <!-- Injected via JS -->
            <div class="p-4 text-center text-slate-500 text-sm">Select a recorded audit...</div>
        </div>
    </div>

    <!-- Active Reconciliation View (Right Display) -->
    <div class="lg:col-span-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden shadow-sm flex flex-col h-[600px] relative">
        <div id="reconEmptyState" class="absolute inset-0 flex flex-col justify-center items-center p-8 bg-slate-50 dark:bg-slate-900/50 z-10">
            <div class="w-20 h-20 bg-white dark:bg-slate-800 text-slate-300 dark:text-slate-600 rounded-full flex items-center justify-center shadow-lg mb-4">
                <i data-lucide="calculator" class="w-10 h-10"></i>
            </div>
            <h3 class="text-xl font-bold text-slate-400 dark:text-slate-500">No Snapshot Selected</h3>
            <p class="text-slate-400 dark:text-slate-600">Select an audit from the left to view the reconciliation math engine.</p>
        </div>

        <div id="reconDataState" class="hidden flex flex-col h-full w-full">
            <div class="p-4 md:p-6 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 shrink-0">
                <div>
                    <h3 class="font-bold text-lg text-slate-900 dark:text-white" id="r_sessionTitle">Session Name</h3>
                    <p class="text-sm text-slate-500" id="r_sessionMeta">Date: ...</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="exportReconPDF()" class="btn-secondary flex items-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition shadow-sm text-sm">
                        <i data-lucide="file-text" class="w-4 h-4 text-rose-500"></i> Export PDF
                    </button>
                    <!-- Frozen Print Btn -->
                    <button id="r_frozenBtn" onclick="downloadFrozenRecord()" class="btn-secondary flex items-center gap-2 px-4 py-2 rounded-xl bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800 font-bold hover:bg-indigo-100 transition shadow-sm text-sm hidden">
                        <i data-lucide="shield-check" class="w-4 h-4 text-indigo-500"></i> View Original
                    </button>
                    <button onclick="exportReconciliation()" class="btn-secondary flex items-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition shadow-sm text-sm">
                        <i data-lucide="download" class="w-4 h-4 text-emerald-500"></i> Export CSV
                    </button>
                    <!-- Unlock to Edit btn (visible only on finalized) -->
                    <button id="r_unlockBtn" onclick="unlockAudit()" class="btn-secondary flex items-center gap-2 px-4 py-2 rounded-xl bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400 border border-amber-200 dark:border-amber-800 font-bold hover:bg-amber-100 dark:hover:bg-amber-900/40 transition shadow-sm text-sm hidden">
                        <i data-lucide="unlock" class="w-4 h-4"></i> Unlock to Edit
                    </button>
                    <!-- Close Session btn -->
                    <button id="r_closeBtn" onclick="closeAuditSession()" class="btn-primary flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-red-500 to-rose-600 font-bold text-white shadow-lg shadow-red-500/30 hover:scale-105 transition-all text-sm hidden">
                        <i data-lucide="lock" class="w-4 h-4"></i> Lock & Finalize
                    </button>
                    <!-- Apply to Sys btn -->
                    <button id="r_applyBtn" onclick="applyAuditToSystem()" class="btn-primary flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-purple-500 to-indigo-600 font-bold text-white shadow-lg shadow-purple-500/30 hover:scale-105 transition-all text-sm hidden">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i> Update System Stock
                    </button>
                </div>
            </div>

            <!-- Recalibration Guide Alert -->
            <div data-html2canvas-ignore="true" class="mx-4 mt-4 mb-1 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl flex gap-3 text-sm text-blue-800 dark:text-blue-300">
                <i data-lucide="info" class="w-5 h-5 shrink-0 text-blue-500 mt-0.5"></i>
                <div class="leading-relaxed">
                    <strong>Need to forcefully update an old price or net worth?</strong> If you adjust a product's price or cost in the Master Registry <em>after</em> an audit is finalized here, this table strictly preserves the old historical price by default.<br>
                    To permanently apply your newly updated registry price to this math: simply click <strong>Unlock to Edit</strong> above -> and click <strong>Finalize Count</strong> without changing numbers. This math will instantly recalculate!
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 shrink-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800">
                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-700/50">
                    <div class="text-xs text-slate-500 dark:text-slate-400 font-semibold uppercase tracking-wide mb-1">Total Items Checked</div>
                    <div class="text-xl font-bold text-slate-900 dark:text-white" id="r_totalItems">0</div>
                </div>
                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-700/50">
                    <div class="text-xs text-slate-500 dark:text-slate-400 font-semibold uppercase tracking-wide mb-1">Net Book Value</div>
                    <div class="text-xl font-bold text-blue-600 dark:text-blue-400 font-mono" id="r_nbv">₦0.00</div>
                </div>
                <div class="p-3 bg-red-50 dark:bg-red-900/10 rounded-xl border border-red-100 dark:border-red-800/30">
                    <div class="text-xs text-red-600 dark:text-red-400 font-semibold uppercase tracking-wide mb-1">Shortage Loss</div>
                    <div class="text-xl font-bold text-red-600 dark:text-red-400 font-mono" id="r_shortageVal">₦0.00</div>
                </div>
                <div class="p-3 bg-emerald-50 dark:bg-emerald-900/10 rounded-xl border border-emerald-100 dark:border-emerald-800/30">
                    <div class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold uppercase tracking-wide mb-1">Overage Value</div>
                    <div class="text-xl font-bold text-emerald-600 dark:text-emerald-400 font-mono" id="r_overageVal">₦0.00</div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto w-full p-0">
                <table class="w-full text-left text-sm" id="reconTable">
                    <thead class="sticky top-0 bg-slate-100 dark:bg-slate-800 shadow-sm z-10 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                        <tr>
                            <th class="p-3 pl-4 border-b border-slate-200 dark:border-slate-700">Product</th>
                            <th class="p-3 text-right border-b border-slate-200 dark:border-slate-700">Sys. Qty</th>
                            <th class="p-3 text-right border-b border-slate-200 dark:border-slate-700">Phys. Qty</th>
                            <th class="p-3 text-right border-b border-slate-200 dark:border-slate-700">Variance</th>
                            <th class="p-3 pr-4 text-right border-b border-slate-200 dark:border-slate-700">Net Book Value</th>
                        </tr>
                    </thead>
                    <tbody id="reconTableBody" class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                        <!-- Loaded dynamically via JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
