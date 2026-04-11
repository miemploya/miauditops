<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Stock Additions & Purchases</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">Log incoming deliveries. Additions will boost the available system stock directly.</p>
    </div>
    <div class="flex gap-3 w-full md:w-auto">
        <button onclick="document.getElementById('importPurchasesModal').classList.remove('hidden')" class="btn-secondary flex-1 md:flex-none flex justify-center items-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition">
            <i data-lucide="upload" class="w-4 h-4"></i> Upload Invoice
        </button>
        <button onclick="openAddPurchaseModal()" class="btn-primary flex-1 md:flex-none flex justify-center items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-emerald-500 to-green-600 font-bold text-white shadow-lg shadow-emerald-500/30 hover:scale-105 transition-all">
            <i data-lucide="plus-circle" class="w-4 h-4"></i> Log Delivery
        </button>
    </div>
</div>

<div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden shadow-sm">
    <div class="p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex flex-wrap gap-4 items-center justify-between">
        <div class="relative flex-1 min-w-[250px]">
            <i data-lucide="filter" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
            <input type="text" id="purchaseSearch" placeholder="Filter by reference or item name..." class="w-full pl-10 pr-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm focus:ring-2 focus:ring-emerald-500" onkeyup="filterPurchases()">
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse" id="purchasesTable">
            <thead class="bg-slate-100 dark:bg-slate-800 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                <tr>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700">Date</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700">Reference</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700">Item Name</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">Qty Added</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">Total Cost</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="purchasesTableBody" class="text-sm divide-y divide-slate-200 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                <!-- Injected via retail_engine.js -->
            </tbody>
        </table>
    </div>
</div>

<!-- Add Purchase Modal -->
<div id="addPurchaseModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50">
            <h3 class="font-bold text-lg text-slate-900 dark:text-white">Log Stock Delivery</h3>
            <button onclick="document.getElementById('addPurchaseModal').classList.add('hidden')" class="text-slate-400 hover:text-red-500"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form id="addPurchaseForm" class="p-6 space-y-4" onsubmit="submitPurchase(event)">
            <input type="hidden" name="action" value="add_purchase">
            <div>
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Select Product *</label>
                <select name="product_id" id="purchaseProductSelect" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-emerald-500">
                    <option value="">-- Choose Product --</option>
                    <!-- Populated via JS -->
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Select Supplier</label>
                <select name="supplier_id" id="purchaseSupplierSelect" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-emerald-500">
                    <option value="">-- No Supplier Map --</option>
                    <!-- Populated via JS -->
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Date Received *</label>
                    <input type="date" name="purchase_date" required value="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-emerald-500 text-slate-700 dark:text-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Inv / Waybill Ref</label>
                    <input type="text" name="reference" placeholder="INV-001" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-emerald-500">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Quantity Added *</label>
                    <input type="number" step="0.01" name="quantity_added" required placeholder="0.00" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Total Cost (₦)</label>
                    <input type="number" step="0.01" name="total_cost" id="addTotalCost" placeholder="0.00" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-emerald-500">
                </div>
            </div>
            <div id="costPreviewCard" class="hidden bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg p-3 text-emerald-800 dark:text-emerald-400 text-sm flex items-center justify-between">
                <span>Calculated New Unit Cost:</span>
                <span class="font-black" id="costPreviewAmount">₦0.00</span>
            </div>
            <div class="pt-4 mt-4 border-t border-slate-200 dark:border-slate-800 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('addPurchaseModal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-all">Cancel</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-green-600 font-bold text-white shadow-lg hover:shadow-emerald-500/30 transition-all">Save Delivery</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Import Purchases Modal -->
<div id="importPurchasesModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50">
            <h3 class="font-bold text-lg text-slate-900 dark:text-white">Batch Upload Deliveries</h3>
            <button onclick="document.getElementById('importPurchasesModal').classList.add('hidden')" class="text-slate-400 hover:text-red-500"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <div class="p-6">
            <div class="flex flex-col md:flex-row gap-4 mb-6">
                <div class="flex-1 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 p-4 rounded-xl text-sm border border-amber-200 dark:border-amber-800">
                    <p class="font-bold mb-1">Required Column Headers:</p>
                    <code class="text-xs bg-white/50 dark:bg-black/20 px-2 py-1 rounded select-all block mb-2">Date, Item Name, QTY, Cost, Reference</code>
                    <p class="text-xs opacity-80">System will match Item Names exactly. If an item doesn't exist in Registry, it will be skipped.</p>
                </div>
                <div class="md:w-48 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 rounded-xl p-4 flex flex-col justify-center items-center text-center">
                    <i data-lucide="downloadCloud" class="w-8 h-8 text-slate-400 mb-2"></i>
                    <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-3">Download Excel template format.</p>
                    <a href="data:text/csv;charset=utf-8,Date,Item%20Name,QTY,Cost,Reference%0A2025-10-15,Sample%20Drink,50,7500,INV-001" download="Addition_Upload_Template.csv" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 text-xs font-bold hover:shadow-lg transition">Download</a>
                </div>
            </div>
            
            <div class="mb-5">
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1 flex items-center gap-2">
                    <i data-lucide="building-2" class="w-3.5 h-3.5"></i> Assign to Supplier (Optional)
                </label>
                <select id="importSupplierSelect" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-950 text-sm focus:ring-2 focus:ring-emerald-500">
                    <option value="">-- No Supplier assigned --</option>
                </select>
                <p class="text-[10px] text-slate-400 mt-1">If selected, all items imported in this batch will be attributed to this supplier.</p>
            </div>

            <div class="border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-xl p-8 text-center hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors cursor-pointer relative" id="importPurchaseDropZone">
                <i data-lucide="truck" class="w-12 h-12 text-slate-400 mx-auto mb-3"></i>
                <p class="text-slate-600 dark:text-slate-300 font-medium">Click to browse or drag file here</p>
                <input type="file" id="purchaseFile" accept=".csv, .xlsx, .xls" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="handlePurchaseUpload(this)">
            </div>
            <div id="importPurchaseStatus" class="mt-4 text-sm hidden font-mono"></div>
        </div>
        <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 gap-3">
            <button onclick="document.getElementById('importPurchasesModal').classList.add('hidden')" class="w-full px-5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 font-bold text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 transition-all">Cancel</button>
        </div>
    </div>
</div>
