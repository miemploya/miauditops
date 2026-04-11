<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Sales & Cash Math</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">Translate inventory shortages into expected revenues and reconcile against declared cash.</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
    <!-- Sessions List (Left Sidebar) -->
    <div class="lg:col-span-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden shadow-sm flex flex-col h-[750px]">
        <div class="p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
            <h3 class="font-bold text-slate-800 dark:text-slate-200 mb-2">Finalized Audits</h3>
            <div class="relative">
                <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
                <input type="text" id="salesSessionSearch" placeholder="Filter..." class="w-full pl-10 pr-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-xs focus:ring-2 focus:ring-purple-500" onkeyup="filterSalesSessions()">
            </div>
        </div>
        <div class="flex-1 overflow-y-auto w-full p-2 space-y-1" id="salesSessionList">
            <!-- Injected via JS -->
            <div class="p-4 text-center text-slate-500 text-sm">Select a finalized audit...</div>
        </div>
    </div>

    <!-- Active Sales Math View (Right Display) -->
    <div class="lg:col-span-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-y-auto shadow-sm flex flex-col h-[750px] relative hide-scroll">
        
        <div id="salesEmptyState" class="absolute inset-0 flex flex-col justify-center items-center p-8 bg-slate-50 dark:bg-slate-900/50 z-10 transition-all">
            <div class="w-20 h-20 bg-white dark:bg-slate-800 text-slate-300 dark:text-slate-600 rounded-full flex items-center justify-center shadow-lg mb-4">
                <i data-lucide="banknote" class="w-10 h-10"></i>
            </div>
            <h3 class="text-xl font-bold text-slate-400 dark:text-slate-500">No Snapshot Selected</h3>
            <p class="text-slate-400 dark:text-slate-600">Select a finalized audit to calculate sales variances.</p>
        </div>

        <div id="salesDataState" class="hidden flex flex-col h-full w-full">
            <!-- Header -->
            <div class="p-4 md:p-6 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 shrink-0">
                <div>
                    <h3 class="font-bold text-lg text-slate-900 dark:text-white" id="sr_sessionTitle">Session Name</h3>
                    <p class="text-sm text-slate-500" id="sr_sessionMeta">Date: ...</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="exportSalesMathPDF()" class="btn-secondary flex items-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition shadow-sm text-sm">
                        <i data-lucide="file-text" class="w-4 h-4 text-rose-500"></i> Export Math PDF
                    </button>
                    <!-- Save Declarations btn -->
                    <button id="sr_saveBtn" onclick="saveSalesDeclarations()" class="btn-primary flex items-center gap-2 px-6 py-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 font-bold text-white shadow-lg shadow-emerald-500/30 hover:scale-105 transition-all text-sm">
                        <i data-lucide="save" class="w-4 h-4"></i> Lock Financials
                    </button>
                </div>
            </div>

            <!-- Recalibration Guide Alert -->
            <div data-html2canvas-ignore="true" class="mx-6 mt-2 mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl flex gap-3 text-sm text-blue-800 dark:text-blue-300">
                <i data-lucide="info" class="w-5 h-5 shrink-0 text-blue-500 mt-0.5"></i>
                <div class="leading-relaxed">
                    <strong>Need to forcefully update an old price?</strong> If you adjust a price in the Master Registry <em>after</em> an audit is finalized here, this math strictly preserves the old historical price by default.<br>
                    To permanently apply your newly updated registry price to this math: hop over to the <strong>Variance & Reconciliation</strong> tab -> select this audit -> click <strong>Unlock to Edit</strong> -> and simply click <strong>Finalize Count</strong> without changing numbers. This math will instantly recalculate!
                </div>
            </div>

            <!-- Math Engine Layout -->
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-8 bg-white dark:bg-slate-900">
                
                <!-- LEFT PILLAR: Expectations & Adjustments -->
                <div class="space-y-6">
                    <div>
                        <div class="bg-indigo-50 dark:bg-indigo-900/10 border border-indigo-100 dark:border-indigo-800/30 rounded-xl p-5 shadow-sm">
                            <h4 class="text-xs font-black uppercase text-indigo-500 tracking-wider mb-1">Base Expected Sales</h4>
                            <p class="text-xs text-slate-500 mb-3">Calculated solely from missing stock multiplied by selling price.</p>
                            <div class="text-3xl font-mono font-bold text-indigo-700 dark:text-indigo-400" id="sr_baseExpected">₦0.00</div>
                        </div>
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
                        <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-4 border-b border-slate-200 dark:border-slate-700 pb-2">Manual Adjustments</h4>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <label class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-1/2">Add to Sales (+)</label>
                                <input type="number" step="0.01" id="adj_add_to_sales" class="w-1/2 px-3 py-1.5 text-right font-mono text-sm rounded bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-indigo-500 sales-input" onkeyup="recalcSalesMath()" value="0">
                            </div>
                            <div class="flex items-center justify-between">
                                <label class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-1/2">Less Damages (-)</label>
                                <input type="number" step="0.01" id="adj_damages" class="w-1/2 px-3 py-1.5 text-right font-mono text-sm rounded bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-indigo-500 sales-input" onkeyup="recalcSalesMath()" value="0">
                            </div>
                            <div class="flex items-center justify-between">
                                <label class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-1/2">Less Written Off (-)</label>
                                <input type="number" step="0.01" id="adj_written_off" class="w-1/2 px-3 py-1.5 text-right font-mono text-sm rounded bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-indigo-500 sales-input" onkeyup="recalcSalesMath()" value="0">
                            </div>
                            <div class="flex items-center justify-between">
                                <label class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-1/2">Less Complimentary (-)</label>
                                <input type="number" step="0.01" id="adj_complimentary" class="w-1/2 px-3 py-1.5 text-right font-mono text-sm rounded bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-indigo-500 sales-input" onkeyup="recalcSalesMath()" value="0">
                            </div>
                            <div class="flex items-center justify-between">
                                <label class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-1/2">Less Error Correction (-)</label>
                                <input type="number" step="0.01" id="adj_error" class="w-1/2 px-3 py-1.5 text-right font-mono text-sm rounded bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-indigo-500 sales-input" onkeyup="recalcSalesMath()" value="0">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT PILLAR: Declarations & Math -->
                <div class="space-y-6">
                    <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
                        <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-4 border-b border-slate-200 dark:border-slate-700 pb-2">Declarations Input</h4>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase mb-1">Declared POS</label>
                                <input type="number" step="0.01" id="dec_pos" class="w-full px-4 py-2 font-mono font-bold text-lg text-right rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-emerald-500 sales-input" onkeyup="recalcSalesMath()" placeholder="0.00" value="0">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase mb-1">Declared Transfer</label>
                                <input type="number" step="0.01" id="dec_transfer" class="w-full px-4 py-2 font-mono font-bold text-lg text-right rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-emerald-500 sales-input" onkeyup="recalcSalesMath()" placeholder="0.00" value="0">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase mb-1">Declared Cash</label>
                                <input type="number" step="0.01" id="dec_cash" class="w-full px-4 py-2 font-mono font-bold text-lg text-right rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 focus:ring-2 focus:ring-emerald-500 sales-input" onkeyup="recalcSalesMath()" placeholder="0.00" value="0">
                            </div>
                        </div>
                    </div>

                    <!-- Grand Total Banner -->
                    <div class="bg-emerald-50 dark:bg-emerald-900/10 border border-emerald-100 dark:border-emerald-800/30 rounded-xl p-5 shadow-sm text-right">
                        <h4 class="text-xs font-black uppercase text-emerald-600 dark:text-emerald-400 tracking-wider mb-1">Total Declared Value</h4>
                        <div class="text-3xl font-mono font-bold text-emerald-700 dark:text-emerald-400" id="sr_totalDeclared">₦0.00</div>
                    </div>
                </div>

            </div>

            <!-- FINAL VERDICT BANNER -->
            <div class="m-6 mt-0 p-6 rounded-xl border shadow-sm flex flex-col md:flex-row justify-between items-center gap-4 transition-colors" id="sr_verdictBanner">
                <div>
                    <h3 class="font-black text-xl mb-1 uppercase tracking-wider" id="sr_verdictTitle">Variance Math</h3>
                    <p class="text-sm opacity-80" id="sr_verdictDesc">Total Declared vs (Base Expected + Adds - Lesses)</p>
                </div>
                <div class="text-right">
                    <div class="text-sm font-semibold uppercase opacity-80 mb-1">Final Difference</div>
                    <div class="text-4xl font-mono font-black tracking-tight" id="sr_verdictAmount">₦0.00</div>
                </div>
            </div>

        </div>
    </div>
</div>
