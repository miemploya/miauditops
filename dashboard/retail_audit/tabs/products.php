<?php
    $cat_stmt = $pdo->prepare("SELECT name FROM retail_categories WHERE company_id = ? AND outlet_id = ? ORDER BY name ASC");
    $cat_stmt->execute([$company_id, $active_outlet_id]);
    $cat_list = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sup_stmt = $pdo->prepare("SELECT id, name FROM retail_suppliers WHERE company_id = ? AND outlet_id = ? ORDER BY name ASC");
    $sup_stmt->execute([$company_id, $active_outlet_id]);
    $sup_list = $sup_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="flex justify-between items-center mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Product Registry</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">Manage items and set base prices. Only products defined here can be audited.</p>
    </div>
    <div class="flex flex-wrap gap-2 md:gap-3 w-full md:w-auto">
        <button onclick="openCategoryModal()" class="btn-secondary flex-1 md:flex-none flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition">
            <i data-lucide="tag" class="w-4 h-4"></i> Categories
        </button>
        <button onclick="document.getElementById('importProductsModal').classList.remove('hidden')" class="btn-secondary flex-1 md:flex-none flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition">
            <i data-lucide="upload-cloud" class="w-4 h-4"></i> Bulk Import
        </button>
        <button onclick="document.getElementById('addProductModal').classList.remove('hidden')" class="btn-primary flex-1 md:flex-none flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-pink-500 to-rose-600 font-bold text-white shadow-lg shadow-pink-500/30 hover:scale-105 transition-all">
            <i data-lucide="plus" class="w-4 h-4"></i> Add Product
        </button>
    </div>
</div>

<div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden shadow-sm">
    <div class="p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex flex-wrap gap-4 items-center justify-between">
        <div class="relative flex-1 min-w-[250px]">
            <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
            <input type="text" id="productSearch" placeholder="Search products, sku or categories..." class="w-full pl-10 pr-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm focus:ring-2 focus:ring-rose-500" onkeyup="filterProducts()">
        </div>
        <div class="flex items-center gap-3">
            <label class="text-sm font-bold text-slate-600 dark:text-slate-300 flex items-center gap-2 cursor-pointer">
                <input type="checkbox" id="expiryFilterToggle" class="rounded border-slate-300 text-rose-500 focus:ring-rose-500 bg-white dark:bg-slate-800" onchange="filterProducts()">
                <span class="px-2 py-1 bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 rounded-lg text-xs">Expiring Soon (30 Days)</span>
            </label>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse" id="productsTable">
            <thead class="bg-slate-100 dark:bg-slate-800 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                <tr>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700">S/N</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700">Category</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700">Item Name</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700">SKU</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700">Expiry</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">Pack Cost/Qty</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">Unit Cost</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">Selling Price</th>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">System Stock</th>
                    <?php if(is_admin_role()): ?>
                    <th class="p-4 border-b border-slate-200 dark:border-slate-700 text-right">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="productsTableBody" class="text-sm divide-y divide-slate-200 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                <!-- Injected via retail_engine.js -->
            </tbody>
        </table>
    </div>
</div>

<!-- Add Product Modal -->
<div id="addProductModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col max-h-[75vh]">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50 shrink-0">
            <h3 class="font-bold text-lg text-slate-900 dark:text-white">New Retail Product</h3>
            <button onclick="document.getElementById('addProductModal').classList.add('hidden')" class="text-slate-400 hover:text-red-500"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form id="addProductForm" class="flex flex-col overflow-hidden" onsubmit="submitProduct(event)">
            <div class="p-6 space-y-4 overflow-y-auto flex-1 auto-scroller">
                <input type="hidden" name="action" value="add_product">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Category</label>
                    <select name="category" id="category_list" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500">
                        <option value="">-- Select Category --</option>
                        <?php foreach($cat_list as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['name']); ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Supplier / Requisition Source</label>
                    <select name="supplier_id" id="supplier_list" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500">
                        <option value="">-- Select Source --</option>
                        <?php foreach($sup_list as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['id']); ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Expiry Date <span class="font-normal">(Optional)</span></label>
                <input type="date" name="expiry_date" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500 text-slate-500">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Product Name *</label>
                <input type="text" name="name" required placeholder="Coca Cola 50cl" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">SKU / Barcode</label>
                <input type="text" name="sku" placeholder="123456789" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Bulk Purchase Type</label>
                    <input type="text" name="bulk_unit" placeholder="e.g. Carton, Crate, Bag" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Retail Unit Type</label>
                    <input type="text" name="unit" placeholder="e.g. Bottle, Piece, Sachet" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500">
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Cost Price <span class="font-normal">(Total Bulk Cost)</span></label>
                    <input type="number" step="0.01" name="cost_price" placeholder="0.00" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Items Per Bulk <span>(Quantity inside)</span></label>
                    <input type="number" step="0.01" name="pack_qty" placeholder="1" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Unit Selling Price</label>
                    <input type="number" step="0.01" name="selling_price" placeholder="0.00" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500">
                </div>
            </div>
            </div>
            <div class="p-4 border-t border-slate-200 dark:border-slate-800 flex justify-end gap-3 shrink-0 bg-slate-50 dark:bg-slate-800/50">
                <button type="button" onclick="document.getElementById('addProductModal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-all">Cancel & Close</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-pink-500 to-rose-600 font-bold text-white shadow-lg hover:shadow-pink-500/30 transition-all">Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Import Modal -->
<div id="importProductsModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50">
            <h3 class="font-bold text-lg text-slate-900 dark:text-white">Bulk Excel/CSV Import</h3>
            <button onclick="document.getElementById('importProductsModal').classList.add('hidden')" class="text-slate-400 hover:text-red-500"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <div class="p-6">
            <div class="flex flex-col md:flex-row gap-4 mb-6">
                <!-- Info block -->
                <div class="flex-1 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 p-4 rounded-xl text-sm border border-amber-200 dark:border-amber-800 shadow-sm">
                    <p class="font-bold mb-1">Required Column Headers:</p>
                    <code class="text-xs bg-white/50 dark:bg-black/20 px-2 py-1 rounded select-all mb-2 block">Category, Item Name, SKU, Expiry Date, Bulk Type, Retail Type, Bulk Cost, Items Per Bulk, Selling Price, System Stock</code>
                    <p class="mt-2 text-xs opacity-80">Upload your Excel or CSV file. The system will auto-calculate Unit Cost from Bulk Cost and Items Per Bulk.</p>
                </div>
                <!-- Download Template Block -->
                <div class="md:w-48 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 rounded-xl p-4 flex flex-col justify-center items-center text-center">
                    <i data-lucide="downloadCloud" class="w-8 h-8 text-slate-400 mb-2"></i>
                    <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-3">Download a pre-formatted Excel template.</p>
                    <a href="data:text/csv;charset=utf-8,Category,Item%20Name,SKU,Expiry%20Date,Bulk%20Type,Retail%20Type,Bulk%20Cost,Items%20Per%20Bulk,Selling%20Price,System%20Stock%0ABeverages,Sample%20Drink,XYZ-123,2025-12-01,Carton,Bottle,5000.00,10,600.00,100" download="Product_Upload_Template.csv" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 text-xs font-bold hover:shadow-lg transition">Download</a>
                </div>
            </div>
            
            <div class="border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-xl p-8 text-center hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors cursor-pointer relative" id="importDropZone">
                <i data-lucide="file-spreadsheet" class="w-12 h-12 text-slate-400 mx-auto mb-3"></i>
                <p class="text-slate-600 dark:text-slate-300 font-medium">Click to browse or drag file here</p>
                <input type="file" id="productFile" accept=".csv, .xlsx, .xls" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="handleProductUpload(this)">
            </div>
            <div id="importStatus" class="mt-4 text-sm hidden font-mono"></div>
        </div>
        <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 gap-3">
            <button onclick="document.getElementById('importProductsModal').classList.add('hidden')" class="w-full px-5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 font-bold text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 transition-all">Cancel & Close</button>
        </div>
    </div>
</div>

<!-- Category Management Modal -->
<div id="categoryModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col max-h-[80vh]">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50">
            <h3 class="font-bold text-lg text-slate-900 dark:text-white">Product Categories</h3>
            <button onclick="document.getElementById('categoryModal').classList.add('hidden')" class="text-slate-400 hover:text-red-500"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <div class="p-6 border-b border-slate-200 dark:border-slate-800 shrink-0">
            <form onsubmit="addCategory(event)" class="flex gap-2">
                <input type="text" id="newCategoryName" required placeholder="e.g. Frozen Foods" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm focus:ring-2 focus:ring-rose-500">
                <button type="submit" id="catAddBtn" class="px-4 py-2 rounded-lg bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold text-sm shadow-sm hover:scale-105 transition">Add</button>
            </form>
        </div>
        <div class="flex-1 overflow-y-auto p-2" id="categoryListBody">
            <div class="p-4 text-center text-slate-500 text-sm">Loading categories...</div>
        </div>
        <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 shrink-0">
            <button onclick="document.getElementById('categoryModal').classList.add('hidden')" class="w-full px-5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 font-bold text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 transition-all">Close</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
