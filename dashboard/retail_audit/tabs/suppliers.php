<!-- RETAIL AUDIT: SUPPLIERS TAB -->
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-800 dark:text-white">Supplier / Requisition Directory</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">Register external suppliers or internal departments that supply products to this outlet.</p>
        </div>
        <button onclick="openSupplierModal()" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold rounded-xl shadow-md shadow-rose-500/30 transition-all flex items-center gap-2">
            <i data-lucide="plus" class="w-4 h-4"></i> Add Source
        </button>
    </div>

    <!-- Suppliers Container (Rendered via JS) -->
    <div id="suppliersContainer" class="space-y-6"></div>
</div>

<!-- Add Supplier Modal -->
<div id="supplierModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 animate-in fade-in">
    <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" @click.stop>
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center bg-slate-50/50 dark:bg-slate-800/50">
            <h3 class="text-lg font-bold text-slate-800 dark:text-white flex items-center gap-2">
                <i data-lucide="building-2" class="w-5 h-5 text-rose-500"></i> Register Supplier / Department
            </h3>
            <button onclick="closeSupplierModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form id="addSupplierForm" onsubmit="submitSupplier(event)" class="px-6 py-6 space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Supplier / Department Name *</label>
                <input type="text" name="name" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-rose-500">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Phone Number</label>
                <input type="tel" name="phone" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-rose-500">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Email Address</label>
                <input type="email" name="email" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-rose-500">
            </div>
            <div class="pt-2 flex justify-end gap-3">
                <button type="button" onclick="closeSupplierModal()" class="px-4 py-2.5 text-sm font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl">Cancel</button>
                <button type="submit" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold rounded-xl shadow-md transition-all">Save Source</button>
            </div>
        </form>
    </div>
</div>

<!-- View Supplier Items Modal -->
<div id="viewSupplierPurchasesModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 animate-in fade-in">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-3xl border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col max-h-[85vh]">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50 shrink-0">
            <div>
                <h3 class="text-lg font-bold text-slate-800 dark:text-white" id="vspSupplierName">Supplier Name</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400" id="vspMonthLabel">Month</p>
            </div>
            <button onclick="document.getElementById('viewSupplierPurchasesModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <div class="p-0 overflow-y-auto flex-1 auto-scroller">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-100 dark:bg-slate-800 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider sticky top-0">
                    <tr>
                        <th class="p-4 border-b border-slate-200 dark:border-slate-700">Date</th>
                        <th class="p-4 border-b border-slate-200 dark:border-slate-700">Reference</th>
                        <th class="p-4 border-b border-slate-200 dark:border-slate-700">Product</th>
                        <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">Qty</th>
                        <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">Cost (₦)</th>
                    </tr>
                </thead>
                <tbody id="vspTableBody" class="text-sm divide-y divide-slate-200 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <!-- Javascript Data -->
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50 shrink-0">
            <div class="text-sm">
                <span class="text-slate-500">Total Items:</span> <span class="font-bold text-slate-800 dark:text-white" id="vspTotalItems">0</span>
            </div>
            <button type="button" onclick="document.getElementById('viewSupplierPurchasesModal').classList.add('hidden')" class="px-5 py-2.5 text-sm font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl transition-all">Close Window</button>
        </div>
    </div>
</div>
