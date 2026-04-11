<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Physical Stock Count</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">Record the actual counted quantities of items on the shop floor and warehouse.</p>
    </div>
    <div class="flex gap-3 w-full md:w-auto">
        <button onclick="document.getElementById('importCountModal').classList.remove('hidden')" class="btn-secondary flex-1 md:flex-none flex justify-center items-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition">
            <i data-lucide="upload" class="w-4 h-4"></i> Upload Count
        </button>
        <button onclick="openCountModal()" class="btn-primary flex-1 md:flex-none flex justify-center items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-500 to-indigo-600 font-bold text-white shadow-lg shadow-blue-500/30 hover:scale-105 transition-all">
            <i data-lucide="edit-3" class="w-4 h-4"></i> Manual Entry
        </button>
    </div>
</div>

<div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden shadow-sm">
    <div class="p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex flex-wrap gap-4 items-center justify-between">
        <h3 class="font-bold text-slate-800 dark:text-slate-200">Recent Audit Snapshots</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse" id="countTable">
            <thead class="bg-slate-100 dark:bg-slate-800 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                <tr>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700">Audit Date</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700">Period Name</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-center">Items Counted</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">Physical Value (NBV)</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-center">Status</th>
                </tr>
            </thead>
            <tbody id="countTableBody" class="text-sm divide-y divide-slate-200 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                <!-- Injected via retail_engine.js -->
            </tbody>
        </table>
    </div>
</div>

<!-- Manual Count Entry Modal -->
<div id="addCountModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50 shrink-0">
            <h3 class="font-bold text-lg text-slate-900 dark:text-white">New Physical Count Session</h3>
            <button onclick="document.getElementById('addCountModal').classList.add('hidden')" class="text-slate-400 hover:text-red-500"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form id="addCountForm" class="flex flex-col overflow-hidden min-h-0 h-full" onsubmit="event.preventDefault(); submitCountAs('finalize');">
            <input type="hidden" name="action" value="save_audit_session">
            <input type="hidden" name="session_id" value="">
            <div class="p-6 shrink-0 border-b border-slate-200 dark:border-slate-800">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Session Title / Period</label>
                        <input type="text" name="session_name" required placeholder="e.g. October Week 1 Count" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Date Performed</label>
                        <input type="date" name="audit_date" required value="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex items-end">
                        <input type="text" id="countGridSearch" placeholder="Filter grid items..." class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm focus:ring-2 focus:ring-blue-500" onkeyup="filterCountGrid()">
                    </div>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-0 hide-scroll relative">
                <table class="w-full text-left" id="countEntryTable">
                    <thead class="sticky top-0 bg-slate-100 dark:bg-slate-800 shadow shadow-slate-200 dark:shadow-slate-800 z-10 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                        <tr>
                            <th class="p-3 pl-6">Product / SKU</th>
                            <th class="p-3 text-right">System Stock</th>
                            <th class="p-3 text-right">Actual Physical Count</th>
                        </tr>
                    </thead>
                    <tbody id="countGridBody" class="text-sm divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                        <!-- JS Generated Matrix -->
                    </tbody>
                </table>
            </div>
            <div class="p-6 pt-4 shrink-0 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex justify-between items-center">
                <div class="text-sm text-slate-500"><i data-lucide="info" class="w-4 h-4 inline-block -mt-1 mr-1"></i> Empty rows will be ignored.</div>
                <div class="flex gap-3">
                    <button type="button" onclick="document.getElementById('addCountModal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 font-bold text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 transition-all">Cancel</button>
                    <button type="button" id="btnDraftCount" onclick="submitCountAs('draft')" class="px-6 py-2.5 rounded-xl bg-slate-200 dark:bg-slate-700 font-bold text-slate-700 dark:text-slate-300 hover:bg-slate-300 dark:hover:bg-slate-600 transition-all">Save Draft & Exit</button>
                    <button type="button" id="btnFinalizeCount" onclick="submitCountAs('finalize')" class="px-8 py-2.5 rounded-xl bg-gradient-to-r from-blue-500 to-indigo-600 font-bold text-white shadow-lg hover:shadow-blue-500/30 transition-all">Finalize Session</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Upload Count Modal -->
<div id="importCountModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50">
            <h3 class="font-bold text-lg text-slate-900 dark:text-white">Batch Upload Count Sheet</h3>
            <button onclick="document.getElementById('importCountModal').classList.add('hidden')" class="text-slate-400 hover:text-red-500"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <div class="p-6">
            <div class="flex flex-col md:flex-row gap-4 mb-4">
                <div class="flex-1 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 p-4 rounded-xl text-sm border border-amber-200 dark:border-amber-800">
                    <p class="font-bold mb-1">Required Column Headers:</p>
                    <code class="text-xs bg-white/50 dark:bg-black/20 px-2 py-1 rounded select-all block mb-2">Item Name, Physical Count</code>
                    <p class="text-xs opacity-80">This will initiate a new session for today automatically using the file contents.</p>
                </div>
                <div class="md:w-48 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 rounded-xl p-4 flex flex-col justify-center items-center text-center">
                    <i data-lucide="downloadCloud" class="w-8 h-8 text-slate-400 mb-2"></i>
                    <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-3">Download generic Excel template.</p>
                    <a href="data:text/csv;charset=utf-8,Item%20Name,Physical%20Count%0ASample%20Drink,45" download="Physical_Count_Template.csv" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 text-xs font-bold hover:shadow-lg transition">Download</a>
                </div>
            </div>
            
            <div class="mb-6">
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Session Title</label>
                <input type="text" id="uploadCountTitle" placeholder="e.g. End of Month Upload" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-xl p-8 text-center hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors cursor-pointer relative" id="importCountDropZone">
                <i data-lucide="clipboard-check" class="w-12 h-12 text-slate-400 mx-auto mb-3"></i>
                <p class="text-slate-600 dark:text-slate-300 font-medium">Click to browse or drag file here</p>
                <input type="file" id="countFile" accept=".csv, .xlsx, .xls" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="handleCountUpload(this)">
            </div>
            <div id="importCountStatus" class="mt-4 text-sm hidden font-mono"></div>
        </div>
        <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 gap-3">
            <button onclick="document.getElementById('importCountModal').classList.add('hidden')" class="w-full px-5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 font-bold text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 transition-all">Cancel</button>
        </div>
    </div>
</div>
