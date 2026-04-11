<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Final Consolidated Reports</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">Generate, view, and export standard professional reports of finalized physical counts.</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
    <!-- Sessions List (Left Sidebar) -->
    <div class="lg:col-span-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden shadow-sm flex flex-col h-[750px]">
        <div class="p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
            <h3 class="font-bold text-slate-800 dark:text-slate-200 mb-2">Finalized Reports</h3>
            <div class="relative">
                <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
                <input type="text" id="finalReportSearch" placeholder="Filter..." class="w-full pl-10 pr-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-xs focus:ring-2 focus:ring-purple-500" onkeyup="filterReportSessions()">
            </div>
        </div>
        <div class="flex-1 overflow-y-auto w-full p-2 space-y-1" id="finalReportSessionList">
            <div class="p-4 text-center text-slate-500 text-sm">Select a finalized audit...</div>
        </div>
    </div>

    <!-- Active Report View (Right Display) -->
    <div class="lg:col-span-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-y-auto shadow-sm flex flex-col h-[750px] relative hide-scroll">
        
        <div id="reportEmptyState" class="absolute inset-0 flex flex-col justify-center items-center p-8 bg-slate-50 dark:bg-slate-900/50 z-10 transition-all">
            <div class="w-20 h-20 bg-white dark:bg-slate-800 text-slate-300 dark:text-slate-600 rounded-full flex items-center justify-center shadow-lg mb-4">
                <i data-lucide="bar-chart-2" class="w-10 h-10"></i>
            </div>
            <h3 class="text-xl font-bold text-slate-400 dark:text-slate-500">No Target Selected</h3>
            <p class="text-slate-400 dark:text-slate-600">Select a finalized audit to generate its professional report.</p>
        </div>

        <!-- Normal UI state with session info + generate button -->
        <div id="reportDataState" class="hidden flex flex-col h-full w-full">
            <div id="reportUIHeader" class="p-8 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex flex-col gap-6 shrink-0 text-center items-center justify-center h-full">
                <div class="w-24 h-24 bg-white dark:bg-slate-900 rounded-full flex items-center justify-center shadow-md mb-2">
                    <i data-lucide="file-check-2" class="w-12 h-12 text-emerald-500"></i>
                </div>
                
                <div>
                    <h3 class="font-black text-3xl text-slate-900 dark:text-white" id="fr_sessionTitle">Session Name</h3>
                    <p class="text-lg text-slate-500 mt-2" id="fr_sessionMeta">Date: ...</p>
                </div>
                
                <div class="max-w-lg text-sm text-slate-500 dark:text-slate-400 mt-4 leading-relaxed">
                    This audit is finalized and ready for professional multi-page extraction. The engine will compile the expected sales targets, pie-chart breakdowns, reconciliation tables, and comprehensive profit margin schedules.
                </div>
                
                <div class="mt-8">
                    <button onclick="exportFinalConsolidatedPDF()" class="flex items-center justify-center gap-3 px-8 py-4 rounded-full bg-gradient-to-r from-blue-600 to-indigo-700 font-black text-white hover:scale-105 transition-all shadow-xl shadow-blue-500/30 w-full md:w-auto">
                        <i data-lucide="download-cloud" class="w-6 h-6"></i> Generate Professional PDF Report
                    </button>
                    <div id="fr_loadingState" class="hidden mt-4 text-sm font-bold text-indigo-600 dark:text-indigo-400 flex items-center justify-center gap-2">
                        <i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Assembling Report...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PDF Render Zone: This is the LIVE visible container that html2pdf will screenshot. 
     It starts hidden and is shown only during PDF generation. -->
<div id="pdfRenderZone" style="display:none; background:white; padding:0; margin:20px auto; max-width:800px;"></div>
