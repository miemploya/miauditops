<?php
/**
 * MIAUDITOPS — Main Store (Central Inventory)
 * 5 Tabs: Products, Stock In (Deliveries), Stock Out, Stock Count, Wastage & Damage
 * This is the central store where all inventory lives before transfer to departments.
 */
require_once '../includes/functions.php';
require_login();
require_permission('main_store');
require_active_client();
$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$page_title = 'Main Store';

// === Auto-migration: ensure required tables exist ===
try {
    $cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'opening_stock'")->fetchAll();
    if (empty($cols)) { $pdo->exec("ALTER TABLE products ADD COLUMN opening_stock INT DEFAULT 0 AFTER selling_price"); }
    $pdo->exec("CREATE TABLE IF NOT EXISTS department_stock (
        id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, client_id INT NOT NULL,
        department_id INT NOT NULL, product_id INT NOT NULL, opening_stock INT DEFAULT 0,
        added INT DEFAULT 0, return_in INT DEFAULT 0, transfer_out INT DEFAULT 0, qty_sold INT DEFAULT 0,
        selling_price DECIMAL(15,2) DEFAULT 0.00, stock_date DATE NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_dept_product_date (department_id, product_id, stock_date),
        INDEX idx_dept (company_id, client_id, department_id),
        INDEX idx_date (company_id, client_id, stock_date)
    ) ENGINE=InnoDB");
    try { $pdo->exec("ALTER TABLE department_stock DROP INDEX uk_dept_product"); } catch (Exception $ignore) {}
    try { $pdo->exec("ALTER TABLE department_stock ADD UNIQUE KEY uk_dept_product_date (department_id, product_id, stock_date)"); } catch (Exception $ignore) {}
    try {
        $pdo->exec("ALTER TABLE department_stock MODIFY stock_date DATE NOT NULL");
        $pdo->exec("UPDATE department_stock SET stock_date = CURDATE() WHERE stock_date IS NULL OR stock_date = '0000-00-00'");
    } catch (Exception $ignore) {}
} catch (Exception $e) { error_log('Stock migration: ' . $e->getMessage()); }

// === Date filter (default = today) ===
$stock_date = $_GET['stock_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $stock_date)) $stock_date = date('Y-m-d');

// Products (scoped by client — product definitions are date-independent)
$stmt = $pdo->prepare("SELECT * FROM products WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL ORDER BY name");
$stmt->execute([$company_id, $client_id]);
$products = $stmt->fetchAll();

// Low stock
$low_stock = array_filter($products, fn($p) => $p['current_stock'] <= $p['reorder_level']);

// Movements for selected date
$stmt = $pdo->prepare("SELECT sm.*, p.name as product_name FROM stock_movements sm LEFT JOIN products p ON sm.product_id = p.id WHERE sm.company_id = ? AND sm.client_id = ? AND DATE(sm.created_at) = ? ORDER BY sm.created_at DESC");
$stmt->execute([$company_id, $client_id, $stock_date]);
$movements = $stmt->fetchAll();

// Stock value
$total_value = array_sum(array_map(fn($p) => $p['current_stock'] * $p['unit_cost'], $products));

// Deliveries for selected date
$stmt = $pdo->prepare("SELECT sd.*, p.name as product_name FROM supplier_deliveries sd LEFT JOIN products p ON sd.product_id = p.id WHERE sd.company_id = ? AND sd.client_id = ? AND sd.deleted_at IS NULL AND sd.delivery_date = ? ORDER BY sd.delivery_date DESC");
$stmt->execute([$company_id, $client_id, $stock_date]);
$deliveries = $stmt->fetchAll();

// ALL deliveries (for Supplier Ledger tab — not date-filtered)
$stmt = $pdo->prepare("SELECT sd.*, p.name as product_name FROM supplier_deliveries sd LEFT JOIN products p ON sd.product_id = p.id WHERE sd.company_id = ? AND sd.client_id = ? AND sd.deleted_at IS NULL ORDER BY sd.supplier_name, sd.delivery_date DESC");
$stmt->execute([$company_id, $client_id]);
$all_deliveries = $stmt->fetchAll();

// Physical counts for selected date
$stmt = $pdo->prepare("SELECT pc.*, p.name as product_name, p.unit_cost FROM physical_counts pc LEFT JOIN products p ON pc.product_id = p.id WHERE pc.company_id = ? AND pc.client_id = ? AND pc.count_date = ? ORDER BY pc.count_date DESC");
$stmt->execute([$company_id, $client_id, $stock_date]);
$counts = $stmt->fetchAll();

// Wastage for selected date
$stmt = $pdo->prepare("SELECT wl.*, p.name as product_name FROM wastage_log wl LEFT JOIN products p ON wl.product_id = p.id WHERE wl.company_id = ? AND wl.client_id = ? AND wl.wastage_date = ? ORDER BY wl.wastage_date DESC");
$stmt->execute([$company_id, $client_id, $stock_date]);
$wastage_records = $stmt->fetchAll();

// Departments for dynamic columns
$stmt = $pdo->prepare("SELECT id, name FROM stock_departments WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL ORDER BY name");
$stmt->execute([$company_id, $client_id]);
$departments = $stmt->fetchAll();

// Department stock for selected date only
$stmt = $pdo->prepare("SELECT ds.* FROM department_stock ds WHERE ds.company_id = ? AND ds.client_id = ? AND ds.stock_date = ? ORDER BY ds.department_id");
$stmt->execute([$company_id, $client_id, $stock_date]);
$dept_stock = $stmt->fetchAll();

// ALL department issue records (for Stock Out / Dept Issue history — not date-filtered)
$stmt = $pdo->prepare("SELECT ds.*, d.name as department_name, p.name as product_name FROM department_stock ds LEFT JOIN stock_departments d ON ds.department_id = d.id LEFT JOIN products p ON ds.product_id = p.id WHERE ds.company_id = ? AND ds.client_id = ? AND ds.added > 0 ORDER BY ds.stock_date DESC, ds.updated_at DESC");
$stmt->execute([$company_id, $client_id]);
$all_dept_issues = $stmt->fetchAll();
$js_all_dept_issues = json_encode($all_dept_issues, JSON_HEX_TAG | JSON_HEX_APOS);

// === Compute prior-day cumulative balances (everything BEFORE selected date) ===
// This makes the closing of day N-1 become the opening of day N.

// Prior deliveries (purchases before this date) — adds to opening
$stmt = $pdo->prepare("SELECT product_id, SUM(quantity) as total FROM supplier_deliveries WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL AND delivery_date < ? GROUP BY product_id");
$stmt->execute([$company_id, $client_id, $stock_date]);
$prior_deliveries = [];
foreach ($stmt->fetchAll() as $r) $prior_deliveries[$r['product_id']] = (int)$r['total'];

// Prior dept requisitions (stock sent to departments before this date) — reduces opening
$stmt = $pdo->prepare("SELECT product_id, SUM(added) as total FROM department_stock WHERE company_id = ? AND client_id = ? AND stock_date < ? GROUP BY product_id");
$stmt->execute([$company_id, $client_id, $stock_date]);
$prior_dept_req = [];
foreach ($stmt->fetchAll() as $r) $prior_dept_req[$r['product_id']] = (int)$r['total'];

// Prior return-in from departments (before this date) — adds to opening
$stmt = $pdo->prepare("SELECT product_id, SUM(transfer_out) as total FROM department_stock WHERE company_id = ? AND client_id = ? AND stock_date < ? GROUP BY product_id");
$stmt->execute([$company_id, $client_id, $stock_date]);
$prior_return_in = [];
foreach ($stmt->fetchAll() as $r) $prior_return_in[$r['product_id']] = (int)$r['total'];

// Prior stock movements (return_outward, adjustment_out) before this date — reduces opening
$stmt = $pdo->prepare("SELECT product_id, type, SUM(quantity) as total FROM stock_movements WHERE company_id = ? AND client_id = ? AND DATE(created_at) < ? AND type IN ('return_outward','adjustment_out','out') GROUP BY product_id, type");
$stmt->execute([$company_id, $client_id, $stock_date]);
$prior_movements_out = [];
foreach ($stmt->fetchAll() as $r) {
    $pid = $r['product_id'];
    $prior_movements_out[$pid] = ($prior_movements_out[$pid] ?? 0) + (int)$r['total'];
}

// Prior wastage before this date — reduces opening
$stmt = $pdo->prepare("SELECT product_id, SUM(quantity) as total FROM wastage_log WHERE company_id = ? AND client_id = ? AND wastage_date < ? GROUP BY product_id");
$stmt->execute([$company_id, $client_id, $stock_date]);
$prior_wastage = [];
foreach ($stmt->fetchAll() as $r) $prior_wastage[$r['product_id']] = (int)$r['total'];

// Build per-product prior balance map: net change before selected date
$prior_balances = [];
foreach ($products as $p) {
    $pid = $p['id'];
    $prior_balances[$pid] = 
        ($prior_deliveries[$pid] ?? 0)     // + purchases
        + ($prior_return_in[$pid] ?? 0)    // + return in from depts
        - ($prior_dept_req[$pid] ?? 0)     // - dept requisitions
        - ($prior_movements_out[$pid] ?? 0) // - return outward, adjustments, stock out
        - ($prior_wastage[$pid] ?? 0);      // - wastage
}
$js_prior_balances = json_encode($prior_balances, JSON_HEX_TAG | JSON_HEX_APOS);

// Product categories
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_categories (
        id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, client_id INT NOT NULL,
        name VARCHAR(100) NOT NULL, sort_order INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_cat_name (company_id, client_id, name)
    ) ENGINE=InnoDB");
} catch (Exception $ignore) {}
$stmt = $pdo->prepare("SELECT * FROM product_categories WHERE company_id = ? AND client_id = ? ORDER BY sort_order, name");
$stmt->execute([$company_id, $client_id]);
$categories = $stmt->fetchAll();

$js_products = json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS);
$js_movements = json_encode($movements, JSON_HEX_TAG | JSON_HEX_APOS);
$js_deliveries = json_encode($deliveries, JSON_HEX_TAG | JSON_HEX_APOS);
$js_counts = json_encode($counts, JSON_HEX_TAG | JSON_HEX_APOS);
$js_wastage = json_encode($wastage_records, JSON_HEX_TAG | JSON_HEX_APOS);
$js_departments = json_encode($departments, JSON_HEX_TAG | JSON_HEX_APOS);
$js_dept_stock = json_encode($dept_stock, JSON_HEX_TAG | JSON_HEX_APOS);
$js_categories = json_encode($categories, JSON_HEX_TAG | JSON_HEX_APOS);
$js_all_deliveries = json_encode($all_deliveries, JSON_HEX_TAG | JSON_HEX_APOS);

// Suppliers
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, client_id INT NOT NULL,
        name VARCHAR(150) NOT NULL, contact_person VARCHAR(150) DEFAULT '', phone VARCHAR(50) DEFAULT '',
        email VARCHAR(150) DEFAULT '', address TEXT, category VARCHAR(100) DEFAULT '', notes TEXT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_supplier_name (company_id, client_id, name)
    ) ENGINE=InnoDB");
} catch (Exception $ignore) {}
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE company_id = ? AND client_id = ? ORDER BY name");
$stmt->execute([$company_id, $client_id]);
$suppliers = $stmt->fetchAll();
$js_suppliers = json_encode($suppliers, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Main Store — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>[x-cloak]{display:none!important}.glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95) 0%,rgba(249,250,251,0.9) 100%);backdrop-filter:blur(20px)}.dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95) 0%,rgba(30,41,59,0.9) 100%)}</style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="mainStoreApp()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8">
            <?php display_flash_message(); ?>

            <!-- Back Link + Title + Date Picker -->
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <a href="stock.php" class="w-9 h-9 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center hover:bg-slate-50 dark:hover:bg-slate-700 transition-all shadow-sm">
                        <i data-lucide="arrow-left" class="w-4 h-4 text-slate-500"></i>
                    </a>
                    <div>
                        <h1 class="text-xl font-black text-slate-900 dark:text-white">Main Store</h1>
                        <p class="text-xs text-slate-500">Central inventory — all products and stock movements</p>
                    </div>
                </div>
                <!-- Date Navigator -->
                <div class="flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-2 py-1.5 shadow-sm">
                    <button @click="goDate(-1)" class="w-7 h-7 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center justify-center transition-all" title="Previous day">
                        <i data-lucide="chevron-left" class="w-4 h-4 text-slate-500"></i>
                    </button>
                    <input type="date" x-model="stockDate" @change="goToDate()" class="px-2 py-1 bg-transparent text-sm font-semibold text-slate-700 dark:text-slate-200 border-0 outline-none w-[130px] text-center cursor-pointer">
                    <button @click="goDate(1)" class="w-7 h-7 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center justify-center transition-all" title="Next day">
                        <i data-lucide="chevron-right" class="w-4 h-4 text-slate-500"></i>
                    </button>
                    <button @click="goToday()" class="ml-1 px-2.5 py-1 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-[10px] font-bold rounded-lg hover:bg-emerald-100 transition-all" x-show="stockDate !== todayStr">Today</button>
                </div>
            </div>

            <!-- Stock Count Flashing Banner (inline, under date) -->
            <div x-show="showCountBanner && physicalCounts.length < products.length"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="flex items-center justify-end -mt-2 mb-1">
                <div class="inline-flex items-center gap-2 bg-red-600 text-white px-3 py-1 rounded-lg text-xs font-bold shadow-md shadow-red-600/20">
                    <span class="w-1.5 h-1.5 bg-white rounded-full animate-pulse"></span>
                    <span>Stock Count:</span>
                    <span x-text="physicalCounts.length + ' / ' + products.length"></span>
                    <span class="bg-white/20 rounded px-1.5 py-0.5 text-[10px]" x-text="products.length ? Math.round(physicalCounts.length / products.length * 100) + '%' : '0%'"></span>
                </div>
            </div>

            <!-- Quick Navigation: All Stores & Departments -->
            <div class="flex items-center gap-2 mb-6 overflow-x-auto pb-1" style="scrollbar-width:thin">
                <!-- Main Store (active) -->
                <span class="flex-shrink-0 inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-bold bg-gradient-to-r from-emerald-500 to-teal-600 text-white shadow-lg shadow-emerald-500/30">
                    <i data-lucide="warehouse" class="w-3.5 h-3.5"></i> Main Store
                </span>
                <!-- Department links -->
                <template x-for="d in departments" :key="d.id">
                    <a :href="'department_store.php?dept_id=' + d.id" class="flex-shrink-0 inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-bold bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-300 dark:hover:bg-indigo-900/20 dark:hover:text-indigo-400 transition-all shadow-sm">
                        <i data-lucide="store" class="w-3.5 h-3.5"></i>
                        <span x-text="d.name"></span>
                    </a>
                </template>
                <span x-show="departments.length === 0" class="text-[10px] text-slate-400 italic">No departments created yet</span>
            </div>

            <!-- Tabs -->
            <div class="mb-6 flex flex-wrap gap-1.5 p-1.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700">
                <template x-for="t in tabs" :key="t.id">
                    <button @click="currentTab = t.id; showForm = false" :class="currentTab === t.id ? 'bg-white dark:bg-slate-900 text-emerald-600 shadow-sm border-emerald-200' : 'text-slate-500 hover:bg-white/50 border-transparent'" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all border">
                        <i :data-lucide="t.icon" class="w-3.5 h-3.5"></i><span x-text="t.label"></span>
                        <template x-if="t.id === 'products' && lowStockCount > 0"><span class="ml-0.5 px-1.5 py-0.5 rounded-full bg-red-500 text-white text-[9px] font-black" x-text="lowStockCount"></span></template>
                    </button>
                </template>
            </div>

            <!-- KPI Strip -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Products</p><p class="text-xl font-black text-slate-800 dark:text-white" x-text="products.length"></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Stock Value</p><p class="text-xl font-black text-emerald-600" x-text="fmt(totalValue)"></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Low Stock</p><p class="text-xl font-black text-red-600" x-text="lowStockCount"></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Movements</p><p class="text-xl font-black text-blue-600" x-text="movements.length"></p></div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60"><p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Wastage</p><p class="text-xl font-black text-amber-600" x-text="wastageRecords.length"></p></div>
            </div>

            <!-- ========== TAB: Product Catalog (Create Products & Categories) ========== -->
            <div x-show="currentTab === 'catalog'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <!-- Header Toolbar -->
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg shadow-indigo-500/30"><i data-lucide="package-plus" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Product Catalog</h3><p class="text-xs text-slate-500" x-text="products.length + ' products'"></p></div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="relative">
                                <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                                <input type="text" x-model="productSearch" placeholder="Search products..." class="pl-9 pr-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs w-48 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all">
                            </div>
                            <button @click="showCategoryModal = true; $nextTick(() => lucide.createIcons())" class="flex items-center gap-1.5 px-3 py-2 bg-amber-50 hover:bg-amber-100 dark:bg-amber-900/20 text-amber-600 text-xs font-bold rounded-xl border border-amber-200 dark:border-amber-800 transition-all">
                                <i data-lucide="tag" class="w-3.5 h-3.5"></i> Categories
                            </button>
                            <button @click="showForm = !showForm; $nextTick(() => lucide.createIcons())" :class="showForm ? 'from-red-500 to-rose-600 shadow-red-500/30' : 'from-indigo-500 to-violet-600 shadow-indigo-500/30'" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                                <i :data-lucide="showForm ? 'x' : 'plus'" class="w-3.5 h-3.5"></i>
                                <span x-text="showForm ? 'Close' : 'Add Product'"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Collapsible Product Creation Form -->
                    <div x-show="showForm" x-transition.duration.200ms class="border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-indigo-500/5 via-violet-500/3 to-transparent">
                        <form @submit.prevent="addCatalogProduct()" class="p-5">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Product Name *</label><input type="text" x-model="catalogForm.name" required placeholder="e.g. Coca-Cola 50cl" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">SKU</label><input type="text" x-model="catalogForm.sku" placeholder="Auto-generated if blank" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Category *</label>
                                    <select x-model="catalogForm.category" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                        <option value="">Select category...</option>
                                        <template x-for="c in categories" :key="c.id"><option :value="c.name" x-text="c.name"></option></template>
                                    </select>
                                </div>
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Unit</label>
                                    <select x-model="catalogForm.unit" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                        <option value="pcs">Pieces</option><option value="kg">Kilograms</option><option value="litres">Litres</option><option value="cartons">Cartons</option><option value="packs">Packs</option><option value="bags">Bags</option><option value="crates">Crates</option>
                                    </select>
                                </div>
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Unit Cost (₦)</label><input type="number" step="0.01" x-model="catalogForm.unit_cost" placeholder="0.00" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Selling Price (₦)</label><input type="number" step="0.01" x-model="catalogForm.selling_price" placeholder="0.00" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            </div>
                            <div class="flex items-center justify-between">
                                <div class="text-xs text-slate-400"><span class="font-semibold">Reorder Level:</span> <input type="number" x-model="catalogForm.reorder_level" class="w-16 px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center inline-block"></div>
                                <button type="submit" class="px-8 py-2.5 bg-gradient-to-r from-indigo-500 to-violet-600 text-white font-bold rounded-xl shadow-lg shadow-indigo-500/30 hover:scale-[1.02] transition-all text-sm"><i data-lucide="plus" class="w-4 h-4 inline mr-1"></i> Create Product</button>
                            </div>
                        </form>
                    </div>

                    <!-- Full-Width Products Table -->
                    <div class="overflow-x-auto max-h-[500px] overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                                <tr>
                                    <th class="px-3 py-3 text-center text-[10px] font-bold text-slate-500 uppercase w-10">S/N</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">Category</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">Product Name</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">SKU</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">Unit</th>
                                    <th class="px-4 py-3 text-right text-[10px] font-bold text-slate-500 uppercase">Unit Cost</th>
                                    <th class="px-4 py-3 text-right text-[10px] font-bold text-slate-500 uppercase">Selling Price</th>
                                    <th class="px-4 py-3 text-center text-[10px] font-bold text-slate-500 uppercase w-16">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(p, pIdx) in products.filter(p => !productSearch || p.name.toLowerCase().includes(productSearch.toLowerCase()) || (p.sku||'').toLowerCase().includes(productSearch.toLowerCase()) || (p.category||'').toLowerCase().includes(productSearch.toLowerCase()))" :key="p.id">
                                    <tr class="border-t border-slate-100 dark:border-slate-800 hover:bg-indigo-50/50 dark:hover:bg-slate-800/30">
                                        <td class="px-3 py-2.5 text-center text-xs font-bold text-slate-400" x-text="pIdx + 1"></td>
                                        <td class="px-4 py-2.5"><span class="px-2 py-0.5 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 text-[9px] font-bold uppercase" x-text="p.category || '—'"></span></td>
                                        <td class="px-4 py-2.5 font-semibold text-slate-800 dark:text-white" x-text="p.name"></td>
                                        <td class="px-4 py-2.5 font-mono text-xs text-slate-400" x-text="p.sku || '—'"></td>
                                        <td class="px-4 py-2.5 text-xs text-slate-500 capitalize" x-text="p.unit || 'pcs'"></td>
                                        <td class="px-4 py-2.5 text-right font-mono text-xs text-slate-600 dark:text-slate-300" x-text="fmt(p.unit_cost || 0)"></td>
                                        <td class="px-4 py-2.5 text-right font-mono text-xs font-semibold text-emerald-600" x-text="fmt(p.selling_price || 0)"></td>
                                        <td class="px-4 py-2.5 text-center">
                                            <div class="inline-flex items-center gap-1">
                                                <button @click="openEditCatalog(p)" class="w-7 h-7 rounded-lg bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 flex items-center justify-center transition-all" title="Edit Product">
                                                    <i data-lucide="pencil" class="w-3.5 h-3.5 text-blue-500"></i>
                                                </button>
                                                <button @click="deleteProduct(p)" class="w-7 h-7 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 flex items-center justify-center transition-all" title="Delete Product">
                                                    <i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-500"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="products.length === 0"><td colspan="8" class="px-4 py-12 text-center text-slate-400">No products created yet. Click "Add Product" to get started.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Category Management Modal -->
                <div x-show="showCategoryModal" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showCategoryModal = false">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden" @click.stop>
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30"><i data-lucide="tag" class="w-4 h-4 text-white"></i></div>
                                <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Manage Categories</h3><p class="text-xs text-slate-500" x-text="categories.length + ' categories'"></p></div>
                            </div>
                            <button @click="showCategoryModal = false" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 flex items-center justify-center transition-all"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                        </div>
                        <div class="p-5">
                            <div class="space-y-1.5 mb-4 max-h-64 overflow-y-auto">
                                <template x-for="cat in categories" :key="cat.id">
                                    <div class="flex items-center justify-between px-3 py-2.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                                        <span class="text-sm font-semibold text-slate-700 dark:text-slate-300" x-text="cat.name"></span>
                                        <button @click="deleteCatalogCategory(cat.id, cat.name)" class="w-7 h-7 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 flex items-center justify-center transition-all">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-500"></i>
                                        </button>
                                    </div>
                                </template>
                                <div x-show="categories.length === 0" class="text-center py-8 text-sm text-slate-400">
                                    <i data-lucide="tag" class="w-8 h-8 mx-auto mb-2 text-slate-300"></i>
                                    <p>No categories yet</p>
                                </div>
                            </div>
                            <form @submit.prevent="addCatalogCategory()" class="flex gap-2">
                                <input type="text" x-model="catForm.name" required placeholder="e.g. Beverages, Food, Cleaning..." class="flex-1 px-3 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all">
                                <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl shadow-lg shadow-amber-500/30 hover:scale-[1.02] transition-all text-sm"><i data-lucide="plus" class="w-4 h-4 inline"></i> Add</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: Store (Inventory) ========== -->
            <div x-show="currentTab === 'products'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <!-- Header with Add button -->
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/30"><i data-lucide="package" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Product Inventory</h3><p class="text-xs text-slate-500" x-text="filteredProducts.length + ' products'"></p></div>
                        </div>
                        <div class="flex items-center gap-2">
                            <select x-model="categoryFilter" class="px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                <option value="">All Categories</option>
                                <template x-for="c in uniqueCategories" :key="c"><option :value="c" x-text="c"></option></template>
                            </select>
                            <input type="text" x-model="search" placeholder="Search..." class="px-3 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs w-40">
                        </div>
                    </div>
                    <!-- Product Table (full-width) -->
                    <div class="overflow-x-auto max-h-[600px] overflow-y-auto"><table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                            <!-- Group headers -->
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="px-2 py-1"></th>
                                <th colspan="3" class="px-3 py-1"></th>
                                <th class="px-2 py-1"></th>
                                <th class="px-2 py-1"></th>
                                <!-- Return In group header: collapsed = 1 col, expanded = N cols -->
                                <th x-show="!showReturnIn && departments.length > 0" class="px-2 py-1 text-center text-[9px] font-bold text-cyan-600 uppercase border-l border-r border-slate-200 dark:border-slate-700 bg-cyan-50 dark:bg-cyan-900/10 cursor-pointer select-none" @click="showReturnIn = true">
                                    <span class="inline-flex items-center gap-1">Return In <i data-lucide="chevron-right" class="w-3 h-3"></i></span>
                                </th>
                                <template x-if="showReturnIn && departments.length > 0">
                                    <th :colspan="departments.length" class="px-2 py-1 text-center text-[9px] font-bold text-cyan-600 uppercase border-l border-r border-slate-200 dark:border-slate-700 bg-cyan-50 dark:bg-cyan-900/10 cursor-pointer select-none" @click="showReturnIn = false">
                                        <span class="inline-flex items-center gap-1" x-text="'Return In (' + departments.length + ' depts)'"></span> <i data-lucide="chevron-down" class="w-3 h-3 inline"></i>
                                    </th>
                                </template>
                                <th class="px-2 py-1"></th>
                                <!-- Dept Req group header: collapsed = 1 col, expanded = N cols -->
                                <th x-show="!showDeptReq && departments.length > 0" class="px-2 py-1 text-center text-[9px] font-bold text-rose-600 uppercase border-l border-r border-slate-200 dark:border-slate-700 bg-rose-50 dark:bg-rose-900/10 cursor-pointer select-none" @click="showDeptReq = true">
                                    <span class="inline-flex items-center gap-1">Dept Req <i data-lucide="chevron-right" class="w-3 h-3"></i></span>
                                </th>
                                <template x-if="showDeptReq && departments.length > 0">
                                    <th :colspan="departments.length" class="px-2 py-1 text-center text-[9px] font-bold text-rose-600 uppercase border-l border-r border-slate-200 dark:border-slate-700 bg-rose-50 dark:bg-rose-900/10 cursor-pointer select-none" @click="showDeptReq = false">
                                        <span class="inline-flex items-center gap-1" x-text="'Dept Req (' + departments.length + ' depts)'"></span> <i data-lucide="chevron-down" class="w-3 h-3 inline"></i>
                                    </th>
                                </template>
                                <th class="px-2 py-1 text-center text-[9px] font-bold text-orange-600 uppercase bg-orange-50 dark:bg-orange-900/10">Rtn Out</th>
                                <th class="px-2 py-1"></th>
                                <th class="px-2 py-1"></th>
                                <th class="px-2 py-1"></th>
                            </tr>
                            <!-- Column headers -->
                            <tr>
                                <th class="px-2 py-2 text-center text-[10px] font-bold text-slate-500 uppercase whitespace-nowrap w-10">S/N</th>
                                <th class="px-3 py-2 text-left text-[10px] font-bold text-slate-500 uppercase whitespace-nowrap">Product</th>
                                <th class="px-3 py-2 text-left text-[10px] font-bold text-slate-500 uppercase whitespace-nowrap">SKU</th>
                                <th class="px-3 py-2 text-left text-[10px] font-bold text-slate-500 uppercase whitespace-nowrap">Category</th>
                                <th class="px-2 py-2 text-right text-[10px] font-bold text-slate-500 uppercase whitespace-nowrap">Opening</th>
                                <th class="px-2 py-2 text-right text-[10px] font-bold text-blue-500 uppercase whitespace-nowrap">Purchase</th>
                                <!-- Return In: collapsed = summary col, expanded = per-dept cols -->
                                <th x-show="!showReturnIn && departments.length > 0" class="px-2 py-2 text-right text-[10px] font-bold text-cyan-600 uppercase whitespace-nowrap border-l border-r border-slate-100 dark:border-slate-800 bg-cyan-50/30 cursor-pointer" @click="showReturnIn = true">Rtn In</th>
                                <template x-if="showReturnIn">
                                    <template x-for="dept in departments" :key="'rh_'+dept.id">
                                        <th class="px-2 py-2 text-right text-[10px] font-bold text-cyan-600 uppercase whitespace-nowrap border-l border-slate-100 dark:border-slate-800" x-text="dept.name.replace(/ Dept$/i, '')"></th>
                                    </template>
                                </template>
                                <th class="px-2 py-2 text-right text-[10px] font-bold text-slate-700 dark:text-slate-300 uppercase whitespace-nowrap bg-slate-100/50 dark:bg-slate-700/30">Total</th>
                                <!-- Dept Req: collapsed = summary col, expanded = per-dept cols -->
                                <th x-show="!showDeptReq && departments.length > 0" class="px-2 py-2 text-right text-[10px] font-bold text-rose-500 uppercase whitespace-nowrap border-l border-r border-slate-100 dark:border-slate-800 bg-rose-50/30 cursor-pointer" @click="showDeptReq = true">Dept Req</th>
                                <template x-if="showDeptReq">
                                    <template x-for="dept in departments" :key="'dh_'+dept.id">
                                        <th class="px-2 py-2 text-right text-[10px] font-bold text-rose-500 uppercase whitespace-nowrap border-l border-slate-100 dark:border-slate-800" x-text="dept.name.replace(/ Dept$/i, '')"></th>
                                    </template>
                                </template>
                                <th class="px-2 py-2 text-right text-[10px] font-bold text-orange-600 uppercase whitespace-nowrap">Rtn Out</th>
                                <th class="px-2 py-2 text-right text-[10px] font-bold text-amber-600 uppercase whitespace-nowrap">Adjustment</th>
                                <th class="px-2 py-2 text-right text-[10px] font-bold text-emerald-600 uppercase whitespace-nowrap bg-emerald-50/50 dark:bg-emerald-900/10">Closing</th>
                                <th class="px-2 py-2 text-center text-[10px] font-bold text-slate-500 uppercase whitespace-nowrap">Actions</th>
                            </tr>
                        </thead>
                        <!-- Each category group gets its own tbody so Alpine x-for has a single root element -->
                        <template x-for="group in groupedProducts" :key="group.category">
                            <tbody>
                                <tr @click="toggleGroup(group.category)" class="bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                                    <td colspan="100%" class="px-4 py-2 border-y border-slate-200 dark:border-slate-700">
                                        <div class="flex items-center justify-between">
                                            <span class="inline-flex items-center gap-2 font-bold text-xs text-slate-700 dark:text-slate-300 uppercase tracking-wider">
                                                <i data-lucide="chevron-down" class="w-4 h-4 transition-transform duration-200" :class="isExpanded(group.category) ? 'rotate-0' : '-rotate-90'"></i>
                                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                                <span x-text="group.category"></span>
                                                <span class="text-slate-400 normal-case" x-text="'(' + group.items.length + ' items)'"></span>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                                <template x-for="(p, idx) in group.items" :key="p.id">
                                    <tr x-show="isExpanded(group.category)" x-transition class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                        <!-- S/N -->
                                        <td class="px-2 py-3 text-center text-xs font-bold text-slate-400 bg-white/50 dark:bg-slate-800/50" x-text="(() => { let n = 0; for (const g of groupedProducts) { if (g.category === group.category) { n += idx + 1; break; } n += g.items.length; } return n; })()"></td>
                                        <!-- Product Name -->
                                        <td class="px-3 py-3 text-sm font-semibold whitespace-nowrap text-slate-700 dark:text-slate-200" x-text="p.name"></td>
                                        <td class="px-3 py-3 font-mono text-xs text-slate-500" x-text="p.sku || '—'"></td>
                                        <td class="px-3 py-3 text-xs text-slate-500" x-text="p.category || '—'"></td>
                                        <td class="px-2 py-3 text-right font-mono text-sm" x-text="getOpening(p.id)"></td>
                                        <td class="px-2 py-3 text-right font-mono text-sm text-blue-600" x-text="getPurchase(p.id)"></td>
                                        <!-- Return In: collapsed = summary, expanded = per-dept -->
                                        <td x-show="!showReturnIn && departments.length > 0" class="px-2 py-3 text-right font-mono text-xs text-cyan-600 border-l border-r border-slate-50 dark:border-slate-800 bg-cyan-50/20 cursor-pointer" @click="showReturnIn = true" x-text="getTotalReturnIn(p.id)"></td>
                                        <template x-if="showReturnIn">
                                            <template x-for="dept in departments" :key="'r_'+dept.id+'_'+p.id">
                                                <td class="px-2 py-3 text-right font-mono text-xs text-cyan-600 border-l border-slate-50 dark:border-slate-800" x-text="getReturnIn(p.id, dept.id)"></td>
                                            </template>
                                        </template>
                                        <td class="px-2 py-3 text-right font-bold text-sm bg-slate-50/50 dark:bg-slate-800/30" x-text="getTotal(p.id)"></td>
                                        <!-- Dept Req: collapsed = summary, expanded = per-dept -->
                                        <td x-show="!showDeptReq && departments.length > 0" class="px-2 py-3 text-right font-mono text-xs text-rose-600 border-l border-r border-slate-50 dark:border-slate-800 bg-rose-50/20 cursor-pointer" @click="showDeptReq = true" x-text="getTotalDeptReq(p.id)"></td>
                                        <template x-if="showDeptReq">
                                            <template x-for="dept in departments" :key="'d_'+dept.id+'_'+p.id">
                                                <td class="px-2 py-3 text-right font-mono text-xs text-rose-600 border-l border-slate-50 dark:border-slate-800" x-text="getDeptReqFor(p.id, dept.id)"></td>
                                            </template>
                                        </template>
                                        <td class="px-2 py-3 text-right font-mono text-sm text-orange-600" x-text="getReturnOutward(p.id)"></td>
                                        <td class="px-2 py-3 text-right font-mono text-sm text-amber-600" x-text="getAdjustment(p.id)"></td>
                                        <td class="px-2 py-3 text-right font-bold text-sm bg-emerald-50/30 dark:bg-emerald-900/5" :class="getClosing(p.id) <= parseInt(p.reorder_level) ? 'text-red-600' : 'text-emerald-600'" x-text="getClosing(p.id)"></td>
                                        <td class="px-2 py-3 text-center">
                                            <div class="inline-flex items-center gap-1">
                                                <button @click.stop="openEditProduct(p)" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all shadow-sm" title="Update Stock">
                                                    <i data-lucide="pencil" class="w-3 h-3"></i> Update
                                                </button>
                                                <button @click.stop="deleteProduct(p)" class="p-1.5 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:hover:bg-red-900/40 rounded-lg transition-all" title="Delete Product">
                                                    <i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-500"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </template>
                        <tbody>
                            <tr x-show="filteredProducts.length === 0"><td colspan="9" class="px-4 py-12 text-center text-slate-400">No products found</td></tr>
                        </tbody>
                    </table></div>
                </div>
            </div>

            <!-- ===== Edit Catalog Product Modal ===== -->
            <div x-show="editCatalogModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="editCatalogModal = false">
                <div x-show="editCatalogModal" x-transition.scale.90 class="w-full max-w-lg glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-indigo-500/10 via-violet-500/5 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg"><i data-lucide="pencil" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Edit Product</h3><p class="text-[10px] text-slate-500" x-text="editCatalogForm.name"></p></div>
                        </div>
                        <button @click="editCatalogModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                    </div>
                    <form @submit.prevent="updateCatalogProduct()" class="p-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Product Name *</label><input type="text" x-model="editCatalogForm.name" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">SKU</label><input type="text" x-model="editCatalogForm.sku" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm" data-no-sentence-case></div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Category</label>
                                <select x-model="editCatalogForm.category" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="">Select...</option>
                                    <template x-for="c in categories" :key="c.id"><option :value="c.name" x-text="c.name"></option></template>
                                </select>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Unit</label>
                                <select x-model="editCatalogForm.unit" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="pcs">Pieces</option><option value="kg">Kilograms</option><option value="litres">Litres</option><option value="cartons">Cartons</option><option value="packs">Packs</option><option value="bags">Bags</option><option value="crates">Crates</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Unit Cost (₦)</label><input type="number" step="0.01" x-model="editCatalogForm.unit_cost" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Selling Price (₦)</label><input type="number" step="0.01" x-model="editCatalogForm.selling_price" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Reorder Level</label><input type="number" x-model="editCatalogForm.reorder_level" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        </div>
                        <div class="flex items-center justify-end gap-3">
                            <button type="button" @click="editCatalogModal = false" class="px-5 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                            <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-indigo-500 to-violet-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ===== Edit Product Modal ===== -->
            <div x-show="editModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="editModal = false">
                <div x-show="editModal" x-transition.scale.90 class="w-full max-w-lg glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/10 via-indigo-500/5 to-transparent flex items-center justify-between sticky top-0 z-10 bg-white dark:bg-slate-900">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg"><i data-lucide="pencil" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Stock Management</h3><p class="text-[10px] text-slate-500" x-text="editForm.name"></p></div>
                        </div>
                        <button @click="editModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                    </div>
                    <form @submit.prevent="updateProduct()" class="p-6 space-y-4">
                        <!-- Product Details -->
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Name *</label><input type="text" x-model="editForm.name" required class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">SKU</label><input type="text" x-model="editForm.sku" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Category</label><input type="text" x-model="editForm.category" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Reorder Level</label><input type="number" x-model="editForm.reorder_level" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-400">Unit Cost (₦) <span class="text-[9px] text-slate-400">🔒</span></label><input type="number" step="0.01" x-model="editForm.unit_cost" readonly class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm text-slate-400 cursor-not-allowed"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-400">Selling Price (₦) <span class="text-[9px] text-slate-400">🔒</span></label><input type="number" step="0.01" x-model="editForm.selling_price" readonly class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm text-slate-400 cursor-not-allowed"></div>
                        </div>
                        <!-- Stock Flow -->
                        <div class="p-4 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/10 dark:to-indigo-900/10 border border-blue-200 dark:border-blue-800 rounded-xl">
                            <p class="text-[10px] font-bold text-blue-600 mb-3 uppercase tracking-wider">Stock Flow</p>
                            <div class="grid grid-cols-3 gap-3 mb-3">
                                <div>
                                    <label class="text-[10px] font-bold mb-1 block text-slate-700 dark:text-slate-300">Opening Stock</label>
                                    <input type="number" x-model.number="editForm.raw_opening" class="w-full px-2.5 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold mb-1 block text-slate-700 dark:text-slate-300">Total (Calc)</label>
                                    <div class="w-full px-2.5 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold" x-text="editTotal()"></div>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold mb-1 block text-amber-600">Adjustment (+/-)</label>
                                    <input type="number" x-model.number="editForm.adjustment" class="w-full px-2.5 py-2 bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-700 rounded-lg text-sm text-center font-bold text-amber-600 focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all" placeholder="0">
                                </div>
                            </div>
                            <!-- Issue to Department -->
                            <template x-if="departments.length > 0">
                                <div class="mb-3">
                                    <p class="text-[9px] font-bold text-rose-500 mb-1.5 uppercase">Issue to Department</p>
                                    <div class="grid grid-cols-3 gap-2">
                                        <div class="col-span-2">
                                            <select x-model="editForm.issue_dept_id" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-rose-200 dark:border-rose-800 rounded-lg text-xs">
                                                <option value="">Select department...</option>
                                                <template x-for="dept in departments" :key="'eid_'+dept.id"><option :value="dept.id" x-text="dept.name"></option></template>
                                            </select>
                                        </div>
                                        <div>
                                            <input type="number" x-model.number="editForm.issue_dept_qty" min="0" placeholder="Qty" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-rose-200 dark:border-rose-800 rounded-lg text-xs text-center font-bold">
                                        </div>
                                    </div>
                                    <!-- Show existing dept requisitions -->
                                    <div class="grid gap-1 mt-2" :style="'grid-template-columns: repeat('+Math.min(departments.length,3)+',1fr)'">
                                        <template x-for="dept in departments" :key="'ed_'+dept.id">
                                            <div class="text-center">
                                                <span class="text-[8px] font-bold text-rose-400 block truncate" x-text="dept.name.replace(/ Dept$/i, '')"></span>
                                                <span class="text-[10px] font-bold text-rose-600" x-text="getDeptReqFor(editForm.product_id, dept.id)"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <div>
                                <label class="text-[10px] font-bold mb-1 block text-emerald-600">Closing</label>
                                <div class="w-full px-2.5 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold" :class="editClosing() <= 0 ? 'text-red-600' : 'text-emerald-600'" x-text="editClosing()"></div>
                            </div>
                        </div>
                        <div class="flex items-center justify-end gap-3">
                            <button type="button" @click="editModal = false" class="px-5 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                            <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ========== TAB: Stock In (Deliveries) ========== -->
            <div x-show="currentTab === 'stock_in'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/30"><i data-lucide="truck" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Goods Received Note (GRN)</h3><p class="text-xs text-slate-500" x-text="deliveries.length + ' deliveries received'"></p></div>
                        </div>
                        <button @click="showForm = !showForm" :class="showForm ? 'from-red-500 to-rose-600 shadow-red-500/30' : 'from-blue-500 to-indigo-600 shadow-blue-500/30'" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                            <i :data-lucide="showForm ? 'x' : 'truck'" class="w-3.5 h-3.5"></i>
                            <span x-text="showForm ? 'Close' : 'Receive Delivery'"></span>
                        </button>
                    </div>

                    <!-- Multi-Item Delivery Form -->
                    <div x-show="showForm" x-transition.duration.200ms class="border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/5 via-indigo-500/3 to-transparent">
                        <div class="p-5">
                            <!-- Delivery Header (Supplier, Date, Invoice) -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
                                <div>
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Supplier *</label>
                                    <select x-model="grnForm.supplier" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                        <option value="">Select supplier...</option>
                                        <template x-for="s in suppliers.filter(s => s.status === 'active')" :key="s.id"><option :value="s.name" x-text="s.name"></option></template>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Delivery Date</label>
                                    <input type="date" x-model="grnForm.delivery_date" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Invoice #</label>
                                    <input type="text" x-model="grnForm.invoice_number" placeholder="INV-001" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                </div>
                            </div>

                            <!-- Line Items Header -->
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-4 flex-1 mr-4">
                                    <h4 class="text-[11px] font-bold uppercase text-slate-400 shrink-0">Delivery Items</h4>
                                    <!-- Quick Add Search -->
                                    <div class="relative flex-1 max-w-sm" x-data="{ q: '', show: false }">
                                        <div class="relative">
                                            <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                                            <input type="text" x-model="q" @focus="show = true" @input="show = true" placeholder="Quick Search & Add Product..." class="w-full pl-9 pr-4 py-1.5 bg-white/50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all">
                                        </div>
                                        <div x-show="show && q.length > 0" @click.away="show = false" class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl shadow-2xl z-[60] max-h-60 overflow-y-auto">
                                            <template x-for="p in products.filter(p => p.name.toLowerCase().includes(q.toLowerCase()) || (p.sku||'').toLowerCase().includes(q.toLowerCase())).slice(0, 10)" :key="p.id">
                                                <button @click="grnForm.lines.push({ product_id: p.id, quantity: 1, total_amount: 0 }); q = ''; show = false; $nextTick(() => lucide.createIcons())" class="w-full text-left px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-800 border-b border-slate-100 dark:border-slate-800 last:border-0 flex items-center justify-between transition-colors">
                                                    <div>
                                                        <div class="text-xs font-bold text-slate-800 dark:text-white" x-text="p.name"></div>
                                                        <div class="text-[10px] text-slate-500" x-text="p.sku || p.category"></div>
                                                    </div>
                                                    <i data-lucide="plus" class="w-3 h-3 text-blue-500"></i>
                                                </button>
                                            </template>
                                            <div x-show="products.filter(p => p.name.toLowerCase().includes(q.toLowerCase())).length === 0" class="px-4 py-3 text-center text-xs text-slate-400">No products found</div>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" @click="addGrnLine()" class="flex items-center gap-1 px-3 py-1.5 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 text-blue-600 text-xs font-bold rounded-lg transition-all">
                                    <i data-lucide="plus" class="w-3 h-3"></i> Add Empty Row
                                </button>
                            </div>

                            <!-- Line Items Table -->
                            <div class="border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden mb-4">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                                        <tr>
                                            <th class="px-3 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Product</th>
                                            <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-24">Qty</th>
                                            <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-32">Total Amount (₦)</th>
                                            <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-28">Unit Cost (₦)</th>
                                            <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-12"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(line, idx) in grnForm.lines" :key="idx">
                                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                                <td class="px-3 py-2">
                                                    <select x-model="line.product_id" required class="w-full px-2 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm">
                                                        <option value="">Select product...</option>
                                                        <template x-for="p in products" :key="p.id"><option :value="p.id" x-text="p.name"></option></template>
                                                    </select>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input type="number" x-model.number="line.quantity" min="1" required placeholder="0" class="w-full px-2 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-semibold">
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input type="number" step="0.01" x-model.number="line.total_amount" min="0" required placeholder="0.00" class="w-full px-2 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-semibold">
                                                </td>
                                                <td class="px-3 py-2">
                                                    <div class="w-full px-2 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold text-blue-600"
                                                         x-text="line.quantity > 0 ? fmt(line.total_amount / line.quantity) : '₦0.00'"></div>
                                                </td>
                                                <td class="px-3 py-2 text-center">
                                                    <button type="button" @click="grnForm.lines.splice(idx, 1)" x-show="grnForm.lines.length > 1" class="w-7 h-7 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 flex items-center justify-center transition-all">
                                                        <i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-500"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Grand Total + Submit -->
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-6">
                                    <div class="flex flex-col">
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Items</span>
                                        <span class="text-lg font-black text-slate-900 dark:text-white" x-text="grnForm.lines.filter(l => l.product_id).length"></span>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Weight/Qty</span>
                                        <span class="text-lg font-black text-slate-900 dark:text-white" x-text="grnForm.lines.reduce((s,l) => s + (parseInt(l.quantity)||0), 0)"></span>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-[10px] font-bold text-blue-400 uppercase tracking-wider">Grand Total</span>
                                        <span class="text-lg font-black text-blue-600" x-text="fmt(grnForm.lines.reduce((s,l) => s + (parseFloat(l.total_amount)||0), 0))"></span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <button @click="grnForm.lines = [{ product_id:'', quantity:1, total_amount:0 }]; showForm = false" class="px-5 py-2.5 text-xs font-bold text-slate-500 hover:text-slate-700 transition-colors">Cancel</button>
                                    <button @click="submitGrn()" class="px-8 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-700 text-white text-xs font-black rounded-xl shadow-lg shadow-blue-500/30 hover:scale-[1.02] transition-all">
                                        Post Delivery to Store
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Delivery History Table -->
                    <div class="overflow-x-auto max-h-[600px] overflow-y-auto"><table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0"><tr>
                            <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Product</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Supplier</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Qty</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Unit Cost</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Total</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Invoice</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Actions</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="d in deliveries" :key="d.id">
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-blue-50/50 dark:hover:bg-slate-800/30">
                                    <td class="px-4 py-3 font-mono text-xs" x-text="d.delivery_date"></td>
                                    <td class="px-4 py-3 font-semibold" x-text="d.product_name"></td>
                                    <td class="px-4 py-3 text-xs text-slate-500" x-text="d.supplier_name"></td>
                                    <td class="px-4 py-3 text-right font-bold text-blue-600" x-text="d.quantity"></td>
                                    <td class="px-4 py-3 text-right font-mono text-xs" x-text="fmt(d.unit_cost)"></td>
                                    <td class="px-4 py-3 text-right font-bold text-xs" x-text="fmt(d.total_cost)"></td>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-500" x-text="d.invoice_number || '—'"></td>
                                    <td class="px-4 py-3 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <button @click="editDeliveryForm = { delivery_id: d.id, product_name: d.product_name, quantity: parseInt(d.quantity), unit_cost: parseFloat(d.unit_cost), supplier_name: d.supplier_name, invoice_number: d.invoice_number || '' }; editDeliveryModal = true; $nextTick(() => lucide.createIcons())" class="inline-flex items-center gap-1 px-2 py-1.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all shadow-sm" title="Edit Delivery">
                                                <i data-lucide="pencil" class="w-3 h-3"></i> Edit
                                            </button>
                                            <button @click="recallForm = { delivery_id: d.id, product_id: d.product_id, product_name: d.product_name, max_qty: parseInt(d.quantity), quantity: 0, notes: '' }; recallModal = true; $nextTick(() => lucide.createIcons())" class="inline-flex items-center gap-1 px-2 py-1.5 bg-gradient-to-r from-orange-500 to-amber-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all shadow-sm" title="Recall / Return Outward">
                                                <i data-lucide="undo-2" class="w-3 h-3"></i> Recall
                                            </button>
                                            <button @click="deleteDelivery(d)" class="p-1.5 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:hover:bg-red-900/40 rounded-lg transition-all" title="Delete Delivery">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-500"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="deliveries.length === 0"><td colspan="7" class="px-4 py-12 text-center text-slate-400">No deliveries received yet</td></tr>
                        </tbody>
                    </table></div>
                </div>
            </div>

            <!-- ===== Edit Delivery Modal ===== -->
            <div x-show="editDeliveryModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="editDeliveryModal = false">
                <div x-show="editDeliveryModal" x-transition.scale.90 class="w-full max-w-md glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/10 via-indigo-500/5 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/30"><i data-lucide="pencil" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Edit Delivery</h3><p class="text-[10px] text-slate-500" x-text="editDeliveryForm.product_name"></p></div>
                        </div>
                        <button @click="editDeliveryModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                    </div>
                    <form @submit.prevent="updateDeliveryRecord()" class="p-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[11px] font-semibold mb-1 block text-slate-500">Quantity *</label>
                                <input type="number" x-model.number="editDeliveryForm.quantity" required min="1" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-bold focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                            </div>
                            <div>
                                <label class="text-[11px] font-semibold mb-1 block text-slate-500">Unit Cost *</label>
                                <input type="number" step="0.01" x-model.number="editDeliveryForm.unit_cost" required min="0" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                            </div>
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold mb-1 block text-slate-500">Supplier</label>
                            <input type="text" x-model="editDeliveryForm.supplier_name" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold mb-1 block text-slate-500">Invoice Number</label>
                            <input type="text" x-model="editDeliveryForm.invoice_number" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                        </div>
                        <div class="p-3 rounded-xl bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800 text-xs text-blue-700 dark:text-blue-300">
                            <b>New Total:</b> <span x-text="fmt(editDeliveryForm.quantity * editDeliveryForm.unit_cost)"></span>
                        </div>
                        <div class="flex gap-3">
                            <button type="button" @click="editDeliveryModal = false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                            <button type="submit" class="flex-1 py-2.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white font-bold rounded-xl shadow-lg shadow-blue-500/30 hover:scale-[1.01] transition-all text-sm">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ===== Recall / Return Outward Modal ===== -->
            <div x-show="recallModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="recallModal = false">
                <div x-show="recallModal" x-transition.scale.90 class="w-full max-w-sm glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-orange-500/10 via-amber-500/5 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 flex items-center justify-center shadow-lg shadow-orange-500/30"><i data-lucide="undo-2" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Recall / Return Outward</h3><p class="text-[10px] text-slate-500" x-text="recallForm.product_name"></p></div>
                        </div>
                        <button @click="recallModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                    </div>
                    <form @submit.prevent="recallDelivery()" class="p-6 space-y-4">
                        <div class="p-3 rounded-xl bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800 text-xs text-orange-700 dark:text-orange-300">
                            <i data-lucide="alert-triangle" class="w-3.5 h-3.5 inline mr-1"></i>
                            This will reduce the store stock and record a <b>Return Outward</b> movement.
                            <span class="block mt-1 font-bold" x-text="'Delivered Qty: ' + recallForm.max_qty + ' units'"></span>
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold mb-1 block text-slate-500">Quantity to Recall *</label>
                            <input type="number" x-model.number="recallForm.quantity" required min="1" :max="recallForm.max_qty" placeholder="Enter qty..." class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-bold text-center focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition-all">
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold mb-1 block text-slate-500">Reason / Notes</label>
                            <input type="text" x-model="recallForm.notes" placeholder="e.g. Defective batch, wrong item..." class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition-all">
                        </div>
                        <div class="flex gap-3">
                            <button type="button" @click="recallModal = false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                            <button type="submit" class="flex-1 py-2.5 bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold rounded-xl shadow-lg shadow-orange-500/30 hover:scale-[1.01] transition-all text-sm">Recall Items</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ========== TAB: Stock Out (Dept Issues) ========== -->
            <div x-show="currentTab === 'stock_out'" x-transition>
                <!-- KPI Strip -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Departments</p>
                        <p class="text-xl font-black text-rose-600" x-text="departments.length"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Issues</p>
                        <p class="text-xl font-black text-pink-600" x-text="filteredDeptIssues.length"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Qty Issued</p>
                        <p class="text-xl font-black text-violet-600" x-text="filteredDeptIssues.reduce((s,r) => s + parseInt(r.added || 0), 0).toLocaleString()"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Unique Products</p>
                        <p class="text-xl font-black text-emerald-600" x-text="new Set(filteredDeptIssues.map(r => r.product_id)).size"></p>
                    </div>
                </div>

                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <!-- Header with Issue button -->
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-rose-500 to-pink-600 flex items-center justify-center shadow-lg shadow-rose-500/30"><i data-lucide="send" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Department Issues</h3><p class="text-xs text-slate-500">Goods issued from main store to departments</p></div>
                        </div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <!-- Date filter pills -->
                            <div class="flex items-center bg-slate-100 dark:bg-slate-800 rounded-xl p-0.5 gap-0.5">
                                <template x-for="opt in [{id:'all',label:'All Time'},{id:'today',label:'Today'},{id:'week',label:'This Week'},{id:'month',label:'This Month'},{id:'custom',label:'Custom'}]" :key="opt.id">
                                    <button type="button" @click="deptIssueDateFilter = opt.id" :class="deptIssueDateFilter === opt.id ? 'bg-white dark:bg-slate-700 shadow-sm text-rose-700 dark:text-rose-300' : 'text-slate-500 hover:text-slate-700'" class="px-2.5 py-1.5 text-[10px] font-bold rounded-lg transition-all" x-text="opt.label"></button>
                                </template>
                            </div>
                            <template x-if="deptIssueDateFilter === 'custom'">
                                <div class="flex items-center gap-2">
                                    <input type="date" x-model="deptIssueDateFrom" class="px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                    <span class="text-xs text-slate-400 font-bold">to</span>
                                    <input type="date" x-model="deptIssueDateTo" class="px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                </div>
                            </template>
                            <button @click="showForm = !showForm" :class="showForm ? 'from-red-500 to-rose-600 shadow-red-500/30' : 'from-rose-500 to-pink-600 shadow-rose-500/30'" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                                <i :data-lucide="showForm ? 'x' : 'send'" class="w-3.5 h-3.5"></i>
                                <span x-text="showForm ? 'Close' : 'Issue to Department'"></span>
                            </button>
                        </div>
                    </div>
                    <!-- Collapsible Issue Form -->
                    <div x-show="showForm" x-transition.duration.200ms class="border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-rose-500/5 via-pink-500/3 to-transparent">
                        <form @submit.prevent="issueToDepartment()" class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Department *</label>
                                <select x-model="deptIssueForm.department_id" required class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="">Select department...</option>
                                    <template x-for="d in departments" :key="d.id"><option :value="d.id" x-text="d.name"></option></template>
                                </select>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Product *</label>
                                <select x-model="deptIssueForm.product_id" required class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="">Select product...</option>
                                    <template x-for="p in products" :key="p.id"><option :value="p.id" x-text="p.name + ' (Stock: ' + p.current_stock + ')'"></option></template>
                                </select>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Quantity *</label><input type="number" x-model="deptIssueForm.quantity" required min="1" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div class="flex items-end"><button type="submit" class="w-full px-8 py-2.5 bg-gradient-to-r from-rose-500 to-pink-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm">Issue to Department</button></div>
                        </form>
                    </div>
                    <!-- Department Issues History Table -->
                    <div class="overflow-x-auto max-h-[600px] overflow-y-auto"><table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0"><tr>
                            <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Department</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Product</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Qty Issued</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Returned</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Net</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="r in filteredDeptIssues" :key="r.id">
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-rose-50/50 dark:hover:bg-slate-800/30">
                                    <td class="px-4 py-3 font-mono text-xs" x-text="r.stock_date"></td>
                                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-400 text-[10px] font-bold" x-text="r.department_name || 'Unknown'"></span></td>
                                    <td class="px-4 py-3 font-semibold" x-text="r.product_name"></td>
                                    <td class="px-4 py-3 text-right font-bold text-rose-600" x-text="r.added"></td>
                                    <td class="px-4 py-3 text-right font-bold text-emerald-600" x-text="r.transfer_out || 0"></td>
                                    <td class="px-4 py-3 text-right font-bold" :class="(parseInt(r.added || 0) - parseInt(r.transfer_out || 0)) > 0 ? 'text-rose-600' : 'text-emerald-600'" x-text="parseInt(r.added || 0) - parseInt(r.transfer_out || 0)"></td>
                                </tr>
                            </template>
                            <tr x-show="filteredDeptIssues.length === 0"><td colspan="6" class="px-4 py-12 text-center text-slate-400">No department issues yet</td></tr>
                        </tbody>
                    </table></div>
                </div>
            </div>

            <!-- ========== TAB: Stock Count ========== -->
            <div x-show="currentTab === 'count'" x-transition>
                <!-- Progress Strip -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Items</p>
                        <p class="text-2xl font-black text-violet-600" x-text="products.length"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-emerald-200/60 dark:border-emerald-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Counted</p>
                        <p class="text-2xl font-black text-emerald-600" x-text="countedProducts.length"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-amber-200/60 dark:border-amber-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Remaining</p>
                        <p class="text-2xl font-black text-amber-600" x-text="products.length - countedProducts.length"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Progress</p>
                        <div class="flex items-center gap-2 mt-1">
                            <div class="flex-1 h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500" :class="countProgress >= 100 ? 'bg-emerald-500' : 'bg-violet-500'" :style="'width:' + countProgress + '%'"></div>
                            </div>
                            <span class="text-sm font-black" :class="countProgress >= 100 ? 'text-emerald-600' : 'text-violet-600'" x-text="countProgress + '%'"></span>
                        </div>
                    </div>
                </div>

                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/30"><i data-lucide="clipboard-check" class="w-4 h-4 text-white"></i></div>
                            <div>
                                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Physical Stock Count</h3>
                                <p class="text-xs text-slate-500">Enter physical count for each item &mdash; counted items are marked with &#10003;</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <!-- Search -->
                            <div class="relative">
                                <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                                <input type="text" x-model="countSearch" placeholder="Search products..." class="pl-9 pr-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs w-56 focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all">
                            </div>
                            <!-- Filter pills -->
                            <div class="flex items-center bg-slate-100 dark:bg-slate-800 rounded-xl p-0.5 gap-0.5">
                                <button type="button" @click="countFilter = 'all'" :class="countFilter === 'all' ? 'bg-white dark:bg-slate-700 shadow-sm text-violet-700' : 'text-slate-500 hover:text-slate-700'" class="px-2.5 py-1.5 text-[10px] font-bold rounded-lg transition-all">All</button>
                                <button type="button" @click="countFilter = 'pending'" :class="countFilter === 'pending' ? 'bg-white dark:bg-slate-700 shadow-sm text-amber-700' : 'text-slate-500 hover:text-slate-700'" class="px-2.5 py-1.5 text-[10px] font-bold rounded-lg transition-all">Pending</button>
                                <button type="button" @click="countFilter = 'counted'" :class="countFilter === 'counted' ? 'bg-white dark:bg-slate-700 shadow-sm text-emerald-700' : 'text-slate-500 hover:text-slate-700'" class="px-2.5 py-1.5 text-[10px] font-bold rounded-lg transition-all">Counted</button>
                            </div>
                        </div>
                    </div>

                    <!-- Product Count List -->
                    <div class="overflow-x-auto max-h-[650px] overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                                <tr>
                                    <th class="px-4 py-3 text-center text-xs font-bold text-slate-500 w-12">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Product</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 w-28">Category</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-slate-500 w-20">System</th>
                                    <th class="px-4 py-3 text-center text-xs font-bold text-slate-500 w-28">Physical Count</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-slate-500 w-20">Variance</th>
                                    <th class="px-4 py-3 text-center text-xs font-bold text-slate-500 w-28">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="p in filteredCountProducts" :key="p.id">
                                    <tr class="border-b border-slate-100 dark:border-slate-800 transition-all duration-300"
                                        :class="isProductCounted(p.id) ? 'bg-emerald-50/50 dark:bg-emerald-900/10' : 'hover:bg-violet-50/50 dark:hover:bg-slate-800/30'">
                                        <!-- Status Icon -->
                                        <td class="px-4 py-3 text-center">
                                            <template x-if="isProductCounted(p.id)">
                                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                                    <i data-lucide="check" class="w-4 h-4 text-emerald-600"></i>
                                                </span>
                                            </template>
                                            <template x-if="!isProductCounted(p.id)">
                                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-100 dark:bg-slate-800">
                                                    <i data-lucide="minus" class="w-4 h-4 text-slate-400"></i>
                                                </span>
                                            </template>
                                        </td>
                                        <!-- Product Name -->
                                        <td class="px-4 py-3">
                                            <div class="font-semibold text-slate-800 dark:text-white" x-text="p.name"></div>
                                            <div class="text-[10px] text-slate-400" x-text="p.sku || ''" x-show="p.sku"></div>
                                        </td>
                                        <!-- Category -->
                                        <td class="px-4 py-3">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-400" x-text="p.category || 'General'"></span>
                                        </td>
                                        <!-- System Count -->
                                        <td class="px-4 py-3 text-right font-bold text-blue-600" x-text="getSystemCount(p.id)"></td>
                                        <!-- Physical Count Input -->
                                        <td class="px-4 py-3 text-center">
                                            <template x-if="!isProductCounted(p.id)">
                                                <input type="number" min="0"
                                                    :id="'count-input-' + p.id"
                                                    x-model.number="countInputs[p.id]"
                                                    @keydown.enter.prevent="saveCountItem(p.id)"
                                                    placeholder="0"
                                                    class="w-20 px-2 py-1.5 bg-white dark:bg-slate-900 border-2 border-violet-300 dark:border-violet-700 rounded-lg text-sm text-center font-bold focus:ring-2 focus:ring-violet-500/40 focus:border-violet-500 transition-all">
                                            </template>
                                            <template x-if="isProductCounted(p.id)">
                                                <span class="font-bold text-emerald-700" x-text="getCountedPhysical(p.id)"></span>
                                            </template>
                                        </td>
                                        <!-- Variance -->
                                        <td class="px-4 py-3 text-right">
                                            <template x-if="isProductCounted(p.id)">
                                                <span class="font-bold" :class="getCountedVariance(p.id) == 0 ? 'text-emerald-600' : 'text-red-600'" x-text="getCountedVariance(p.id)"></span>
                                            </template>
                                            <template x-if="!isProductCounted(p.id) && (countInputs[p.id] !== undefined && countInputs[p.id] !== '')">
                                                <span class="font-bold text-slate-400" x-text="(countInputs[p.id] || 0) - getSystemCount(p.id)"></span>
                                            </template>
                                            <template x-if="!isProductCounted(p.id) && (countInputs[p.id] === undefined || countInputs[p.id] === '')">
                                                <span class="text-slate-300">&mdash;</span>
                                            </template>
                                        </td>
                                        <!-- Action -->
                                        <td class="px-4 py-3 text-center">
                                            <template x-if="!isProductCounted(p.id)">
                                                <button @click="saveCountItem(p.id)"
                                                    :disabled="countInputs[p.id] === undefined || countInputs[p.id] === ''"
                                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-gradient-to-r from-violet-500 to-purple-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all shadow-sm disabled:opacity-40 disabled:hover:scale-100">
                                                    <i data-lucide="check" class="w-3 h-3"></i> Count
                                                </button>
                                            </template>
                                            <template x-if="isProductCounted(p.id)">
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 text-[10px] font-bold rounded-full">
                                                    <i data-lucide="check-circle" class="w-3 h-3"></i> Done
                                                </span>
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="filteredCountProducts.length === 0">
                                    <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                                        <i data-lucide="search-x" class="w-8 h-8 mx-auto mb-2 text-slate-300"></i>
                                        <p>No products match your filter</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Count History (collapsible) -->
                <div class="mt-6" x-data="{ showHistory: false }">
                    <button @click="showHistory = !showHistory; $nextTick(() => lucide.createIcons())"
                        class="flex items-center gap-2 text-xs font-bold text-slate-500 hover:text-violet-600 transition-colors mb-3">
                        <i :data-lucide="showHistory ? 'chevron-down' : 'chevron-right'" class="w-3.5 h-3.5"></i>
                        View Count History (<span x-text="physicalCounts.length"></span> records)
                    </button>
                    <div x-show="showHistory" x-transition class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="overflow-x-auto max-h-[400px] overflow-y-auto"><table class="w-full text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0"><tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Product</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Cost</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">System</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Physical</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Amount</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Variance</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Notes</th>
                            </tr></thead>
                            <tbody>
                                <template x-for="c in physicalCounts" :key="c.id">
                                    <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-violet-50/50 dark:hover:bg-slate-800/30">
                                        <td class="px-4 py-3 font-mono text-xs" x-text="c.count_date"></td>
                                        <td class="px-4 py-3 font-semibold" x-text="c.product_name"></td>
                                        <td class="px-4 py-3 text-right text-xs" x-text="fmt(c.unit_cost || 0)"></td>
                                        <td class="px-4 py-3 text-right" x-text="c.system_count"></td>
                                        <td class="px-4 py-3 text-right font-bold" x-text="c.physical_count"></td>
                                        <td class="px-4 py-3 text-right font-bold text-indigo-600" x-text="fmt(parseFloat(c.unit_cost || 0) * parseInt(c.physical_count || 0))"></td>
                                        <td class="px-4 py-3 text-right font-bold" :class="(c.physical_count - c.system_count) == 0 ? 'text-emerald-600' : 'text-red-600'" x-text="c.physical_count - c.system_count"></td>
                                        <td class="px-4 py-3 text-xs text-slate-500 truncate max-w-[200px]" x-text="c.notes || '—'"></td>
                                    </tr>
                                </template>
                                <tr x-show="physicalCounts.length === 0"><td colspan="8" class="px-4 py-12 text-center text-slate-400">No counts yet</td></tr>
                            </tbody>
                        </table></div>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: Wastage & Damage ========== -->
            <div x-show="currentTab === 'wastage'" x-transition>
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <!-- Header with Log button -->
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30"><i data-lucide="trash-2" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Wastage & Damage Log</h3><p class="text-xs text-slate-500" x-text="wastageRecords.length + ' records'"></p></div>
                        </div>
                        <button @click="showForm = !showForm" :class="showForm ? 'from-red-500 to-rose-600 shadow-red-500/30' : 'from-amber-500 to-orange-600 shadow-amber-500/30'" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                            <i :data-lucide="showForm ? 'x' : 'trash-2'" class="w-3.5 h-3.5"></i>
                            <span x-text="showForm ? 'Close' : 'Log Wastage / Damage'"></span>
                        </button>
                    </div>
                    <!-- Collapsible Form -->
                    <div x-show="showForm" x-transition.duration.200ms class="border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/5 via-orange-500/3 to-transparent">
                        <form @submit.prevent="logWastage()" class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Product *</label>
                                <select x-model="wastageForm.product_id" required class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="">Select product...</option>
                                    <template x-for="p in products" :key="p.id"><option :value="p.id" x-text="p.name + ' (Stock: ' + p.current_stock + ')'"></option></template>
                                </select>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Quantity *</label><input type="number" x-model="wastageForm.quantity" required min="1" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Reason Code</label>
                                <select x-model="wastageForm.reason_code" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="expired">Expired</option><option value="damaged">Damaged</option><option value="spoilage">Spoilage</option><option value="theft">Theft / Pilferage</option><option value="defective">Defective</option><option value="other">Other</option>
                                </select>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Description</label><input type="text" x-model="wastageForm.reason" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm" placeholder="Detailed reason..."></div>
                            <div class="sm:col-span-2 lg:col-span-4 flex justify-end">
                                <button type="submit" class="px-8 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm">Log Wastage</button>
                            </div>
                        </form>
                    </div>
                    <!-- Wastage Table (full-width) -->
                    <div class="overflow-x-auto max-h-[600px] overflow-y-auto"><table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0"><tr>
                            <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Product</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Qty</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Reason</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-slate-500">Value Lost</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="w in wastageRecords" :key="w.id">
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-amber-50/50 dark:hover:bg-slate-800/30">
                                    <td class="px-4 py-3 font-mono text-xs" x-text="w.wastage_date"></td>
                                    <td class="px-4 py-3 font-semibold" x-text="w.product_name"></td>
                                    <td class="px-4 py-3 text-right font-bold text-amber-600" x-text="w.quantity"></td>
                                    <td class="px-4 py-3 text-xs text-slate-500" x-text="w.reason || '—'"></td>
                                    <td class="px-4 py-3 text-right font-bold text-red-600 text-xs" x-text="fmt(w.quantity * (getProductCost(w.product_id)))"></td>
                                </tr>
                            </template>
                            <tr x-show="wastageRecords.length === 0"><td colspan="5" class="px-4 py-12 text-center text-slate-400">No wastage recorded</td></tr>
                        </tbody>
                    </table></div>
                </div>
            </div>

            <!-- ========== TAB: Suppliers ========== -->
            <div x-show="currentTab === 'suppliers'" x-transition>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Supplier Registration Form -->
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-cyan-500/10 via-blue-500/5 to-transparent">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-lg shadow-cyan-500/30"><i data-lucide="building-2" class="w-4 h-4 text-white"></i></div>
                                <div>
                                    <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="editingSupplier ? 'Edit Supplier' : 'Register Supplier'"></h3>
                                    <p class="text-[10px] text-slate-500">Add vendor/supplier details</p>
                                </div>
                            </div>
                        </div>
                        <form @submit.prevent="editingSupplier ? updateSupplier() : addSupplier()" class="p-5 space-y-3">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Company/Supplier Name *</label>
                                <input type="text" x-model="supplierForm.name" required placeholder="e.g. ABC Distributors Ltd" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition-all">
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Contact Person</label>
                                <input type="text" x-model="supplierForm.contact_person" placeholder="Full name" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Phone</label>
                                    <input type="text" x-model="supplierForm.phone" placeholder="08012345678" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                </div>
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Email</label>
                                    <input type="email" x-model="supplierForm.email" placeholder="info@supplier.com" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                </div>
                            </div>
                            <div>
                                <label class="text-[11px] font-semibold mb-1 block text-slate-500">Supply Categories</label>
                                <div class="relative">
                                    <button type="button" @click="supplierCatOpen = !supplierCatOpen" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm text-left flex items-center justify-between">
                                        <span class="truncate" x-text="supplierForm.category.length ? supplierForm.category.join(', ') : 'Select categories...'"></span>
                                        <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 flex-shrink-0"></i>
                                    </button>
                                    <div x-show="supplierCatOpen" @click.away="supplierCatOpen = false" x-transition class="absolute left-0 right-0 mt-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl shadow-xl z-30 max-h-48 overflow-y-auto p-2">
                                        <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer text-sm">
                                            <input type="checkbox" value="General" :checked="supplierForm.category.includes('General')" @change="$event.target.checked ? supplierForm.category.push('General') : supplierForm.category = supplierForm.category.filter(c => c !== 'General')" class="rounded border-slate-300">
                                            <span>General</span>
                                        </label>
                                        <template x-for="c in categories" :key="c.id">
                                            <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer text-sm">
                                                <input type="checkbox" :value="c.name" :checked="supplierForm.category.includes(c.name)" @change="$event.target.checked ? supplierForm.category.push(c.name) : supplierForm.category = supplierForm.category.filter(x => x !== c.name)" class="rounded border-slate-300">
                                                <span x-text="c.name"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Address</label>
                                <textarea x-model="supplierForm.address" rows="2" placeholder="Office address..." class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm resize-none"></textarea>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Notes</label>
                                <textarea x-model="supplierForm.notes" rows="2" placeholder="Payment terms, delivery schedule, etc." class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm resize-none"></textarea>
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" class="flex-1 py-2.5 bg-gradient-to-r from-cyan-500 to-blue-600 text-white font-bold rounded-xl shadow-lg shadow-cyan-500/30 hover:scale-[1.01] transition-all text-sm" x-text="editingSupplier ? 'Update Supplier' : 'Register Supplier'"></button>
                                <button x-show="editingSupplier" type="button" @click="cancelEditSupplier()" class="px-4 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-bold rounded-xl text-sm">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <!-- Supplier Directory -->
                    <div class="lg:col-span-2 glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-slate-500 to-slate-700 flex items-center justify-center shadow-lg"><i data-lucide="contact" class="w-4 h-4 text-white"></i></div>
                                <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Supplier Directory</h3><p class="text-xs text-slate-500" x-text="suppliers.length + ' suppliers'"></p></div>
                            </div>
                        </div>
                        <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Supplier</th>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Contact</th>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Phone</th>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Email</th>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500">Category</th>
                                        <th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Status</th>
                                        <th class="px-4 py-3 text-center text-xs font-bold text-slate-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="s in suppliers" :key="s.id">
                                        <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-cyan-50/50 dark:hover:bg-slate-800/30">
                                            <td class="px-4 py-3">
                                                <div class="font-semibold text-slate-800 dark:text-white" x-text="s.name"></div>
                                                <div class="text-[10px] text-slate-400 mt-0.5" x-text="s.address || '—'" x-show="s.address"></div>
                                            </td>
                                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300" x-text="s.contact_person || '—'"></td>
                                            <td class="px-4 py-3 font-mono text-xs" x-text="s.phone || '—'"></td>
                                            <td class="px-4 py-3 text-xs" x-text="s.email || '—'"></td>
                                            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-100 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-400" x-text="s.category || 'General'"></span></td>
                                            <td class="px-4 py-3 text-center">
                                                <span :class="s.status === 'active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-500'" class="px-2 py-0.5 rounded-full text-[10px] font-bold" x-text="s.status"></span>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <div class="flex items-center justify-center gap-1">
                                                    <button @click="editSupplier(s)" class="w-7 h-7 rounded-lg bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 flex items-center justify-center transition-all"><i data-lucide="pencil" class="w-3.5 h-3.5 text-blue-500"></i></button>
                                                    <button @click="deleteSupplier(s.id, s.name)" class="w-7 h-7 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 flex items-center justify-center transition-all"><i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-500"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr x-show="suppliers.length === 0"><td colspan="7" class="px-4 py-12 text-center text-slate-400"><i data-lucide="building-2" class="w-8 h-8 mx-auto mb-2 text-slate-300"></i><p>No suppliers registered yet</p></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: Supplier Ledger ========== -->
            <div x-show="currentTab === 'ledger'" x-transition>
                <!-- KPI Strip -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Active Suppliers</p>
                        <p class="text-xl font-black text-cyan-600" x-text="supplierLedger.length"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Deliveries</p>
                        <p class="text-xl font-black text-blue-600" x-text="ledgerFilteredDeliveries.length"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Qty Received</p>
                        <p class="text-xl font-black text-violet-600" x-text="ledgerTotalQty.toLocaleString()"></p>
                    </div>
                    <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Grand Total</p>
                        <p class="text-xl font-black text-emerald-600" x-text="fmt(ledgerGrandTotal)"></p>
                    </div>
                </div>

                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 flex items-center justify-center shadow-lg shadow-teal-500/30"><i data-lucide="receipt" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Supplier Delivery Ledger</h3><p class="text-xs text-slate-500">Accumulated deliveries &amp; spend by supplier</p></div>
                        </div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <!-- Date preset pills -->
                            <div class="flex items-center bg-slate-100 dark:bg-slate-800 rounded-xl p-0.5 gap-0.5">
                                <template x-for="opt in [{id:'all',label:'All Time'},{id:'today',label:'Today'},{id:'week',label:'This Week'},{id:'month',label:'This Month'},{id:'custom',label:'Custom'}]" :key="opt.id">
                                    <button type="button" @click="ledgerDateFilter = opt.id" :class="ledgerDateFilter === opt.id ? 'bg-white dark:bg-slate-700 shadow-sm text-teal-700 dark:text-teal-300' : 'text-slate-500 hover:text-slate-700'" class="px-2.5 py-1.5 text-[10px] font-bold rounded-lg transition-all" x-text="opt.label"></button>
                                </template>
                            </div>
                            <!-- Custom date range inputs -->
                            <template x-if="ledgerDateFilter === 'custom'">
                                <div class="flex items-center gap-2">
                                    <input type="date" x-model="ledgerDateFrom" class="px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                    <span class="text-xs text-slate-400 font-bold">to</span>
                                    <input type="date" x-model="ledgerDateTo" class="px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                </div>
                            </template>
                            <!-- Search -->
                            <div class="relative">
                                <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                                <input type="text" x-model="ledgerSearch" placeholder="Search supplier or product..." class="pl-9 pr-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs w-56 focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 transition-all">
                            </div>
                        </div>
                    </div>

                    <!-- Supplier Accordion Cards -->
                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        <template x-for="(entry, eIdx) in filteredLedger" :key="entry.supplier">
                            <div>
                                <!-- Supplier Summary Row (clickable) -->
                                <button @click="expandedSupplier = (expandedSupplier === entry.supplier) ? null : entry.supplier; $nextTick(() => lucide.createIcons())" class="w-full px-6 py-4 flex items-center justify-between hover:bg-slate-50/80 dark:hover:bg-slate-800/30 transition-colors text-left">
                                    <div class="flex items-center gap-4 flex-1 min-w-0">
                                        <div class="flex items-center justify-center w-8 h-8 rounded-xl text-xs font-black text-white shrink-0" :class="['bg-gradient-to-br from-cyan-500 to-blue-600', 'bg-gradient-to-br from-violet-500 to-purple-600', 'bg-gradient-to-br from-emerald-500 to-teal-600', 'bg-gradient-to-br from-amber-500 to-orange-600', 'bg-gradient-to-br from-rose-500 to-pink-600'][eIdx % 5]" x-text="entry.supplier.charAt(0).toUpperCase()"></div>
                                        <div class="min-w-0">
                                            <p class="font-bold text-sm text-slate-900 dark:text-white truncate" x-text="entry.supplier"></p>
                                            <p class="text-[10px] text-slate-400" x-text="entry.deliveryCount + ' deliveries • ' + entry.uniqueProducts + ' products • Last: ' + entry.lastDate"></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-6 shrink-0">
                                        <div class="text-right">
                                            <p class="text-[10px] font-bold uppercase text-slate-400">Total Qty</p>
                                            <p class="text-sm font-black text-blue-600" x-text="entry.totalQty.toLocaleString()"></p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-[10px] font-bold uppercase text-slate-400">Total Amount</p>
                                            <p class="text-sm font-black text-emerald-600" x-text="fmt(entry.totalAmount)"></p>
                                        </div>
                                        <i :data-lucide="expandedSupplier === entry.supplier ? 'chevron-up' : 'chevron-down'" class="w-4 h-4 text-slate-400"></i>
                                    </div>
                                </button>

                                <!-- Expanded: Delivery History Table -->
                                <div x-show="expandedSupplier === entry.supplier" x-transition.duration.200ms class="bg-slate-50/50 dark:bg-slate-900/30 border-t border-slate-100 dark:border-slate-800">
                                    <div class="overflow-x-auto max-h-[400px] overflow-y-auto">
                                        <table class="w-full text-sm">
                                            <thead class="bg-slate-100/80 dark:bg-slate-800/60 sticky top-0">
                                                <tr>
                                                    <th class="px-4 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-10">S/N</th>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Date</th>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Product</th>
                                                    <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Qty</th>
                                                    <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Unit Cost</th>
                                                    <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Total</th>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Invoice</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="(d, dIdx) in entry.deliveries" :key="d.id">
                                                    <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-white/60 dark:hover:bg-slate-800/30">
                                                        <td class="px-4 py-2 text-center text-xs font-bold text-slate-400" x-text="dIdx + 1"></td>
                                                        <td class="px-4 py-2 font-mono text-xs" x-text="d.delivery_date"></td>
                                                        <td class="px-4 py-2 font-semibold text-slate-800 dark:text-white" x-text="d.product_name"></td>
                                                        <td class="px-4 py-2 text-right font-bold text-blue-600" x-text="parseInt(d.quantity || 0)"></td>
                                                        <td class="px-4 py-2 text-right font-mono text-xs" x-text="fmt(d.unit_cost)"></td>
                                                        <td class="px-4 py-2 text-right font-bold text-emerald-600" x-text="fmt(d.total_cost)"></td>
                                                        <td class="px-4 py-2 font-mono text-xs text-slate-500" x-text="d.invoice_number || '—'"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                            <tfoot class="bg-slate-100/80 dark:bg-slate-800/60">
                                                <tr>
                                                    <td colspan="3" class="px-4 py-2.5 text-right text-xs font-black text-slate-600 uppercase">Totals</td>
                                                    <td class="px-4 py-2.5 text-right font-black text-blue-700" x-text="entry.totalQty.toLocaleString()"></td>
                                                    <td class="px-4 py-2.5"></td>
                                                    <td class="px-4 py-2.5 text-right font-black text-emerald-700" x-text="fmt(entry.totalAmount)"></td>
                                                    <td class="px-4 py-2.5"></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <div x-show="filteredLedger.length === 0" class="px-6 py-12 text-center text-slate-400">
                            <i data-lucide="receipt" class="w-10 h-10 mx-auto mb-3 text-slate-300"></i>
                            <p class="text-sm font-semibold">No delivery records found</p>
                            <p class="text-xs mt-1">Receive deliveries in the Stock In tab to populate this ledger</p>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>
<script>
function mainStoreApp() {
    return {
        currentTab: (location.hash.slice(1) || new URLSearchParams(window.location.search).get('tab') || 'catalog'), search: '', categoryFilter: '', showForm: false,
        editModal: false, editCatalogModal: false, showReturnIn: false, showDeptReq: false,
        stockDate: '<?php echo $stock_date; ?>',
        todayStr: new Date().toISOString().split('T')[0],
        editForm: { product_id:'', name:'', sku:'', category:'', unit_cost:0, selling_price:0, opening:0, purchase:0, reorder_level:10 },
        editCatalogForm: { id:'', name:'', sku:'', category:'', unit:'pcs', unit_cost:0, selling_price:0, reorder_level:10 },
        tabs: [
            { id: 'catalog', label: 'Product', icon: 'package-plus' },
            { id: 'products', label: 'Store', icon: 'package' },
            { id: 'stock_in', label: 'Stock In', icon: 'truck' },
            { id: 'stock_out', label: 'Stock Out', icon: 'send' },
            { id: 'count', label: 'Stock Count', icon: 'clipboard-check' },
            { id: 'wastage', label: 'Wastage & Damage', icon: 'trash-2' },
            { id: 'suppliers', label: 'Suppliers', icon: 'building-2' },
            { id: 'ledger', label: 'Supplier Ledger', icon: 'receipt' },
        ],
        products: <?php echo $js_products; ?>,
        movements: <?php echo $js_movements; ?>,
        deliveries: <?php echo $js_deliveries; ?>,
        departments: <?php echo $js_departments; ?>,
        deptStock: <?php echo $js_dept_stock; ?>,
        priorBalances: <?php echo $js_prior_balances; ?>,
        physicalCounts: <?php echo $js_counts; ?>,
        wastageRecords: <?php echo $js_wastage; ?>,
        categories: <?php echo $js_categories; ?>,
        suppliers: <?php echo $js_suppliers; ?>,
        allDeliveries: <?php echo $js_all_deliveries; ?>,
        ledgerSearch: '',
        expandedSupplier: null,
        expandedGroups: {}, // Track category expansion
        showCountBanner: false,
        ledgerDateFilter: 'all',
        ledgerDateFrom: '',
        ledgerDateTo: '',
        recallModal: false,
        recallForm: { delivery_id: 0, product_id: 0, product_name: '', max_qty: 0, quantity: 0, notes: '' },
        editDeliveryModal: false,
        editDeliveryForm: { delivery_id: 0, product_name: '', quantity: 0, unit_cost: 0, supplier_name: '', invoice_number: '' },

        productForm: { name:'', sku:'', category:'', unit:'pcs', unit_cost:0, selling_price:0, opening_stock:0, reorder_level:10 },
        catalogForm: { name:'', sku:'', category:'', unit:'pcs', unit_cost:0, selling_price:0, reorder_level:10 },
        catForm: { name:'' },
        productSearch: '',
        showCategoryModal: false,
        supplierForm: { name:'', contact_person:'', phone:'', email:'', address:'', category:[], notes:'' },
        supplierCatOpen: false,
        editingSupplier: null,
        deliveryForm: { product_id:'', supplier:'', quantity:0, unit_cost:0, invoice_number:'', delivery_date: '<?php echo $stock_date; ?>' },
        grnForm: {
            supplier: '', delivery_date: '<?php echo $stock_date; ?>', invoice_number: '',
            lines: [{ product_id: '', quantity: 0, total_amount: 0 }]
        },
        stockOutForm: { product_id:'', quantity:0, reason:'sales', notes:'' },
        deptIssueForm: { department_id:'', product_id:'', quantity:0 },
        allDeptIssues: <?php echo $js_all_dept_issues; ?>,
        deptIssueDateFilter: 'all',
        deptIssueDateFrom: '',
        deptIssueDateTo: '',
        countForm: { product_id:'', system_count:0, physical_count:0, notes:'' },
        countSearch: '',
        countFilter: 'all',
        countInputs: {},
        wastageForm: { product_id:'', quantity:0, reason_code:'expired', reason:'' },

        // Pre-calculated maps for O(1) row lookups in the store table
        get purchaseMap() {
            const map = {};
            this.deliveries.forEach(d => {
                const pid = d.product_id;
                map[pid] = (map[pid] || 0) + parseInt(d.quantity || 0);
            });
            return map;
        },
        get adjustmentMap() {
            const map = {};
            this.wastageRecords.forEach(w => { map[w.product_id] = (map[w.product_id] || 0) + parseInt(w.quantity || 0); });
            this.movements.forEach(m => {
                if (m.type === 'adjustment_out') map[m.product_id] = (map[m.product_id] || 0) + parseInt(m.quantity || 0);
                // if we had adjustment_in, we'd subtract or handle separately.
            });
            return map;
        },
        get deptReqMap() {
            const map = {};
            this.deptStock.forEach(ds => { map[ds.product_id] = (map[ds.product_id] || 0) + parseInt(ds.added || 0); });
            return map;
        },
        get returnInMap() {
            const map = {};
            this.deptStock.forEach(ds => { map[ds.product_id] = (map[ds.product_id] || 0) + parseInt(ds.transfer_to_main || 0); });
            return map;
        },
        get returnOutwardMap() {
            const map = {};
            this.movements.forEach(m => {
                if (m.type === 'return_outward') map[m.product_id] = (map[m.product_id] || 0) + parseInt(m.quantity || 0);
            });
            return map;
        },

        get totalValue() { return this.products.reduce((s,p) => s + (p.current_stock * p.unit_cost), 0); },
        get lowStockCount() { return this.products.filter(p => p.current_stock <= p.reorder_level).length; },
        get uniqueCategories() { return [...new Set(this.products.map(p => p.category).filter(Boolean))]; },
        get filteredProducts() {
            let list = this.products;
            const q = this.search.toLowerCase();
            if (q) list = list.filter(p => p.name.toLowerCase().includes(q) || (p.sku||'').toLowerCase().includes(q));
            if (this.categoryFilter) list = list.filter(p => p.category === this.categoryFilter);
            return list;
        },
        // Group filteredProducts by category for sectioned table display
        get groupedProducts() {
            const groups = {};
            this.filteredProducts.forEach(p => {
                const cat = p.category || 'Uncategorized';
                if (!groups[cat]) groups[cat] = [];
                groups[cat].push(p);
            });
            // Sort by category order from DB
            const catOrder = this.categories.map(c => c.name);
            return Object.keys(groups)
                .sort((a, b) => {
                    const ia = catOrder.indexOf(a), ib = catOrder.indexOf(b);
                    if (ia === -1 && ib === -1) return a.localeCompare(b);
                    if (ia === -1) return 1;
                    if (ib === -1) return -1;
                    return ia - ib;
                })
                .map(cat => ({ category: cat, items: groups[cat] }));
        },
        get outMovements() { return this.movements.filter(m => m.type === 'out' || m.type === 'adjustment_out'); },
        get filteredDeptIssues() {
            const today = new Date().toISOString().slice(0, 10);
            let from = '', to = '';
            if (this.deptIssueDateFilter === 'today') { from = to = today; }
            else if (this.deptIssueDateFilter === 'week') {
                const d = new Date(); const day = d.getDay() || 7;
                d.setDate(d.getDate() - day + 1); from = d.toISOString().slice(0,10); to = today;
            }
            else if (this.deptIssueDateFilter === 'month') { from = today.slice(0,8) + '01'; to = today; }
            else if (this.deptIssueDateFilter === 'custom') { from = this.deptIssueDateFrom; to = this.deptIssueDateTo; }
            if (!from && !to) return this.allDeptIssues;
            return this.allDeptIssues.filter(r => {
                const dd = r.stock_date;
                if (from && dd < from) return false;
                if (to && dd > to) return false;
                return true;
            });
        },

        // Supplier Ledger — date-filtered deliveries
        get ledgerFilteredDeliveries() {
            const today = new Date().toISOString().slice(0, 10);
            let from = '', to = '';
            if (this.ledgerDateFilter === 'today') { from = to = today; }
            else if (this.ledgerDateFilter === 'week') {
                const d = new Date(); const day = d.getDay() || 7;
                d.setDate(d.getDate() - day + 1); from = d.toISOString().slice(0,10); to = today;
            }
            else if (this.ledgerDateFilter === 'month') {
                from = today.slice(0,8) + '01'; to = today;
            }
            else if (this.ledgerDateFilter === 'custom') {
                from = this.ledgerDateFrom; to = this.ledgerDateTo;
            }
            // 'all' → no filter
            if (!from && !to) return this.allDeliveries;
            return this.allDeliveries.filter(d => {
                const dd = d.delivery_date;
                if (from && dd < from) return false;
                if (to && dd > to) return false;
                return true;
            });
        },
        // Group filtered deliveries by supplier
        get supplierLedger() {
            const map = {};
            this.ledgerFilteredDeliveries.forEach(d => {
                const name = d.supplier_name || 'Unknown';
                if (!map[name]) map[name] = { supplier: name, totalQty: 0, totalAmount: 0, deliveryCount: 0, products: new Set(), lastDate: '', deliveries: [] };
                const qty = parseInt(d.quantity || 0);
                const cost = parseFloat(d.total_cost || 0);
                map[name].totalQty += qty;
                map[name].totalAmount += cost;
                map[name].deliveryCount++;
                if (d.product_name) map[name].products.add(d.product_name);
                if (!map[name].lastDate || d.delivery_date > map[name].lastDate) map[name].lastDate = d.delivery_date;
                map[name].deliveries.push(d);
            });
            return Object.values(map)
                .map(s => ({ ...s, uniqueProducts: s.products.size, products: [...s.products] }))
                .sort((a, b) => b.totalAmount - a.totalAmount);
        },
        get filteredLedger() {
            const q = this.ledgerSearch.toLowerCase();
            if (!q) return this.supplierLedger;
            return this.supplierLedger.filter(s => s.supplier.toLowerCase().includes(q) || s.products.some(p => p.toLowerCase().includes(q)));
        },
        get ledgerGrandTotal() { return this.supplierLedger.reduce((s, x) => s + x.totalAmount, 0); },
        get ledgerTotalQty() { return this.supplierLedger.reduce((s, x) => s + x.totalQty, 0); },

        // Stock Count Checklist computed properties
        get countedProducts() {
            const countedIds = new Set(this.physicalCounts.filter(c => c.count_date === this.stockDate).map(c => String(c.product_id)));
            return this.products.filter(p => countedIds.has(String(p.id)));
        },
        get countProgress() {
            if (this.products.length === 0) return 0;
            return Math.round((this.countedProducts.length / this.products.length) * 100);
        },
        get filteredCountProducts() {
            let list = this.products;
            // search filter
            if (this.countSearch) {
                const q = this.countSearch.toLowerCase();
                list = list.filter(p => p.name.toLowerCase().includes(q) || (p.sku||'').toLowerCase().includes(q) || (p.category||'').toLowerCase().includes(q));
            }
            // status filter
            if (this.countFilter === 'pending') {
                list = list.filter(p => !this.isProductCounted(p.id));
            } else if (this.countFilter === 'counted') {
                list = list.filter(p => this.isProductCounted(p.id));
            }
            // Sort: pending items first, then counted
            return list.sort((a, b) => {
                const aCounted = this.isProductCounted(a.id) ? 1 : 0;
                const bCounted = this.isProductCounted(b.id) ? 1 : 0;
                return aCounted - bCounted;
            });
        },

        getSystemCount(pid) { const p = this.products.find(x => x.id == pid); return p ? p.current_stock : 0; },
        getProductCost(pid) { const p = this.products.find(x => x.id == pid); return p ? p.unit_cost : 0; },
        isProductCounted(pid) {
            return this.physicalCounts.some(c => String(c.product_id) === String(pid) && c.count_date === this.stockDate);
        },
        getCountedPhysical(pid) {
            const c = this.physicalCounts.find(c => String(c.product_id) === String(pid) && c.count_date === this.stockDate);
            return c ? parseInt(c.physical_count) : 0;
        },
        getCountedVariance(pid) {
            const c = this.physicalCounts.find(c => String(c.product_id) === String(pid) && c.count_date === this.stockDate);
            return c ? (parseInt(c.physical_count) - parseInt(c.system_count)) : 0;
        },
        async saveCountItem(pid) {
            const physCount = this.countInputs[pid];
            if (physCount === undefined || physCount === '') { alert('Enter a count value first'); return; }
            const sysCount = this.getSystemCount(pid);
            const fd = new FormData();
            fd.append('action', 'save_count');
            fd.append('product_id', pid);
            fd.append('system_count', sysCount);
            fd.append('physical_count', physCount);
            fd.append('notes', '');
            const r = await (await fetch('../ajax/stock_api.php', { method: 'POST', body: fd })).json();
            if (r.success) {
                // Add to physicalCounts locally so the UI updates instantly
                const p = this.products.find(x => x.id == pid);
                this.physicalCounts.push({
                    id: r.id || Date.now(),
                    product_id: pid,
                    product_name: p ? p.name : '',
                    system_count: sysCount,
                    physical_count: physCount,
                    unit_cost: p ? p.unit_cost : 0,
                    count_date: this.stockDate,
                    notes: ''
                });
                delete this.countInputs[pid];
                this.$nextTick(() => lucide.createIcons());
            } else {
                alert(r.message || 'Failed to save count');
            }
        },
        getOpening(pid) {
            const p = this.products.find(x => x.id == pid);
            const initial = p ? parseInt(p.opening_stock || 0) : 0;
            const prior = this.priorBalances[pid] || 0;
            return initial + prior;
        },
        getPurchase(pid) { return this.purchaseMap[pid] || 0; },
        // Return In from a department = that dept's transfer_to_main for this product
        getReturnIn(pid, deptId) { const r = this.deptStock.find(ds => ds.product_id == pid && ds.department_id == deptId); return r ? parseInt(r.transfer_to_main || 0) : 0; },
        getTotalReturnIn(pid) { return this.returnInMap[pid] || 0; },
        // Dept Req = what was sent to each dept = dept's added value
        getDeptReqFor(pid, deptId) { const r = this.deptStock.find(ds => ds.product_id == pid && ds.department_id == deptId); return r ? parseInt(r.added || 0) : 0; },
        getTotalDeptReq(pid) { return this.deptReqMap[pid] || 0; },
        // Adjustment = damages, write-offs from wastage + adjustment movements
        getAdjustment(pid) { return this.adjustmentMap[pid] || 0; },
        getReturnOutward(pid) { return this.returnOutwardMap[pid] || 0; },
        // Total = Opening + Purchase + Total Return In
        getTotal(pid) { return this.getOpening(pid) + this.getPurchase(pid) + this.getTotalReturnIn(pid); },
        // Closing = Total - Total Dept Req - Return Outward - Adjustment
        getClosing(pid) { return this.getTotal(pid) - this.getTotalDeptReq(pid) - this.getReturnOutward(pid) - this.getAdjustment(pid); },
        // Edit modal helpers
        editTotal() { 
            const prior = this.priorBalances[this.editForm.product_id] || 0;
            const currentOpening = (parseInt(this.editForm.raw_opening) || 0) + prior; 
            return currentOpening + (parseInt(this.editForm.purchase)||0) + this.getTotalReturnIn(this.editForm.product_id); 
        },
        editClosing() {
            // Base closing from formula: Total - existing DeptReq - RTN Out - new Adjustment
            const base = this.editTotal() - this.getTotalDeptReq(this.editForm.product_id) - this.getReturnOutward(this.editForm.product_id) - (parseInt(this.editForm.adjustment) || 0);
            // Preview: also subtract pending dept issue qty (not yet saved)
            return base - (parseInt(this.editForm.issue_dept_qty) || 0);
        },

        async deleteProduct(p) {
            if (!confirm('Delete "' + p.name + '" from the Main Store?\n\nThis will soft-delete the product. Stock records will be preserved.')) return;
            const fd = new FormData();
            fd.append('action', 'delete_product');
            fd.append('product_id', p.id);
            try {
                const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
                if (r.success) { location.reload(); }
                else { alert(r.message || 'Error deleting product'); }
            } catch(e) { alert('Connection error'); }
        },

        openEditCatalog(p) {
            this.editCatalogForm = {
                id: p.id,
                name: p.name || '',
                sku: p.sku || '',
                category: p.category || '',
                unit: p.unit || 'pcs',
                unit_cost: parseFloat(p.unit_cost || 0),
                selling_price: parseFloat(p.selling_price || 0),
                reorder_level: parseInt(p.reorder_level || 10)
            };
            this.editCatalogModal = true;
        },

        async updateCatalogProduct() {
            if (!this.editCatalogForm.name.trim()) { alert('Product name is required'); return; }
            const fd = new FormData();
            fd.append('action', 'update_catalog_product');
            fd.append('product_id', this.editCatalogForm.id);
            fd.append('name', this.editCatalogForm.name.trim());
            fd.append('sku', this.editCatalogForm.sku.trim());
            fd.append('category', this.editCatalogForm.category);
            fd.append('unit', this.editCatalogForm.unit);
            fd.append('unit_cost', this.editCatalogForm.unit_cost);
            fd.append('selling_price', this.editCatalogForm.selling_price);
            fd.append('reorder_level', this.editCatalogForm.reorder_level);
            try {
                const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
                if (r.success) { this.editCatalogModal = false; location.reload(); }
                else { alert(r.message || 'Error updating product'); }
            } catch(e) { alert('Connection error'); }
        },

        fmt(v) { return '₦' + parseFloat(v||0).toLocaleString('en-NG',{minimumFractionDigits:2}); },
        init() {
            this.$watch('currentTab', (val) => { location.hash = val; this.showForm = false; setTimeout(() => lucide.createIcons(), 50); });
            window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (h && this.tabs.some(t => t.id === h)) this.currentTab = h; });
            this.$watch('showForm', () => this.$nextTick(() => lucide.createIcons()));
            this.$watch('editModal', () => this.$nextTick(() => lucide.createIcons()));
            this.$watch('editCatalogModal', () => this.$nextTick(() => lucide.createIcons()));

            // Stock count flashing banner: show 5s, hide 3s, repeat until all counted
            const self = this;
            function flashBanner() {
                const counted = self.physicalCounts.length;
                const total = self.products.length;
                if (counted >= total && total > 0) { self.showCountBanner = false; return; }
                self.showCountBanner = true;
                self.$nextTick(() => lucide.createIcons());
                setTimeout(() => {
                    self.showCountBanner = false;
                    setTimeout(flashBanner, 3000); // rest for 3s
                }, 5000); // show for 5s
            }
            setTimeout(flashBanner, 2000); // initial delay
        },

        // Date navigation
        goDate(offset) {
            const d = new Date(this.stockDate);
            d.setDate(d.getDate() + offset);
            this.stockDate = d.toISOString().split('T')[0];
            this.goToDate();
        },
        toggleGroup(cat) {
            if (this.expandedGroups[cat] === undefined) this.expandedGroups[cat] = true;
            else this.expandedGroups[cat] = !this.expandedGroups[cat];
            this.$nextTick(() => lucide.createIcons());
        },
        isExpanded(cat) { return this.expandedGroups[cat] === true; },
        goToday() {
            this.stockDate = this.todayStr;
            this.goToDate();
        },
        goToDate() {
            window.location.href = 'main_store.php?stock_date=' + this.stockDate + '&tab=' + this.currentTab;
        },
        reloadTab() {
            window.location.href = 'main_store.php?stock_date=' + this.stockDate + '&tab=' + this.currentTab;
        },

        openEditProduct(p) {
            this.editForm = {
                product_id: p.id,
                name: p.name,
                sku: p.sku || '',
                category: p.category || '',
                unit_cost: parseFloat(p.unit_cost || 0),
                selling_price: parseFloat(p.selling_price || 0),
                opening: this.getOpening(p.id),
                raw_opening: p.opening_stock || 0,
                purchase: this.getPurchase(p.id),
                reorder_level: parseInt(p.reorder_level || 10),
                adjustment: 0,
                issue_dept_id: '',
                issue_dept_qty: 0
            };
            this.editModal = true;
        },

        async updateProduct() {
            const o = parseInt(this.editForm.opening) || 0;
            // Handle adjustment change
            const oldAdj = this.getAdjustment(this.editForm.product_id);
            const newAdj = parseInt(this.editForm.adjustment) || 0;
            const adjDiff = newAdj - oldAdj;
            if (adjDiff !== 0) {
                const adjFd = new FormData();
                adjFd.append('action', 'stock_adjustment');
                adjFd.append('product_id', this.editForm.product_id);
                adjFd.append('quantity', Math.abs(adjDiff));
                adjFd.append('type', adjDiff > 0 ? 'adjustment_out' : 'adjustment_in');
                adjFd.append('notes', 'Adjusted via Stock Management modal');
                await fetch('../ajax/stock_api.php', {method:'POST', body: adjFd});
            }
            // Handle department issue
            const issueQty = parseInt(this.editForm.issue_dept_qty) || 0;
            if (this.editForm.issue_dept_id && issueQty > 0) {
                const issFd = new FormData();
                issFd.append('action', 'issue_to_department');
                issFd.append('department_id', this.editForm.issue_dept_id);
                issFd.append('product_id', this.editForm.product_id);
                issFd.append('quantity', issueQty);
                issFd.append('issue_date', this.stockDate);
                const issR = await (await fetch('../ajax/stock_api.php', {method:'POST', body: issFd})).json();
                if (!issR.success) { alert('Department issue failed: ' + (issR.message || '')); }
            }
            // current_stock = closing computed from the formula (Total - DeptReq - RTNOut - Adjustment)
            // The dept issue and adjustment are now recorded as transactions, so include them
            const closing = this.editTotal() - this.getTotalDeptReq(this.editForm.product_id) - issueQty - this.getReturnOutward(this.editForm.product_id) - (parseInt(this.editForm.adjustment) || 0);
            const fd = new FormData(); fd.append('action','update_product');
            fd.append('product_id', this.editForm.product_id);
            fd.append('name', this.editForm.name);
            fd.append('sku', this.editForm.sku);
            fd.append('category', this.editForm.category);
            fd.append('unit_cost', this.editForm.unit_cost);
            fd.append('selling_price', this.editForm.selling_price);
            fd.append('opening_stock', this.editForm.raw_opening);
            fd.append('current_stock', closing);
            fd.append('reorder_level', this.editForm.reorder_level);
            fd.append('stock_date', this.stockDate);
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) { this.editModal = false; this.reloadTab(); } else alert(r.message);
        },

        async addProduct() {
            const fd = new FormData(); fd.append('action','add_product');
            Object.entries(this.productForm).forEach(([k,v]) => fd.append(k,v));
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) this.reloadTab(); else alert(r.message);
        },
        // Catalog tab methods
        async addCatalogProduct() {
            if (!this.catalogForm.name || !this.catalogForm.category) { alert('Name and category are required'); return; }
            const fd = new FormData(); fd.append('action','add_product');
            fd.append('name', this.catalogForm.name);
            fd.append('sku', this.catalogForm.sku);
            fd.append('category', this.catalogForm.category);
            fd.append('unit', this.catalogForm.unit);
            fd.append('unit_cost', this.catalogForm.unit_cost);
            fd.append('selling_price', this.catalogForm.selling_price);
            fd.append('opening_stock', 0);
            fd.append('reorder_level', this.catalogForm.reorder_level);
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                this.products.push({ id: r.id, name: this.catalogForm.name, sku: this.catalogForm.sku, category: this.catalogForm.category, unit: this.catalogForm.unit, unit_cost: this.catalogForm.unit_cost, selling_price: this.catalogForm.selling_price, current_stock: 0, reorder_level: this.catalogForm.reorder_level });
                this.catalogForm = { name:'', sku:'', category:'', unit:'pcs', unit_cost:0, selling_price:0, reorder_level:10 };
                this.$nextTick(() => lucide.createIcons());
            } else alert(r.message);
        },

        async addCatalogCategory() {
            if (!this.catForm.name.trim()) { alert('Please enter a category name'); return; }
            const fd = new FormData(); fd.append('action','add_category'); fd.append('name', this.catForm.name.trim());
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                this.catForm.name = '';
                this.showCategoryModal = false;
                window.location.href = 'main_store.php?stock_date=' + this.stockDate + '&tab=catalog';
            } else alert(r.message);
        },
        async deleteCatalogCategory(id, name) {
            if (!confirm('Delete category "' + name + '"?')) return;
            const fd = new FormData(); fd.append('action','delete_category'); fd.append('id', id);
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                this.categories = this.categories.filter(c => c.id !== id);
            } else alert(r.message);
        },
        async receiveDelivery() {
            const fd = new FormData(); fd.append('action','receive_delivery');
            Object.entries(this.deliveryForm).forEach(([k,v]) => fd.append(k,v));
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) this.reloadTab(); else alert(r.message);
        },
        addGrnLine() {
            this.grnForm.lines.push({ product_id: '', quantity: 0, total_amount: 0 });
            this.$nextTick(() => lucide.createIcons());
        },
        async submitGrn() {
            if (!this.grnForm.supplier) { alert('Please select a supplier'); return; }
            const validLines = this.grnForm.lines.filter(l => l.product_id && l.quantity > 0);
            if (validLines.length === 0) { alert('Please add at least one item with a product and quantity'); return; }
            let success = 0;
            for (const line of validLines) {
                const unitCost = line.quantity > 0 ? (parseFloat(line.total_amount) || 0) / line.quantity : 0;
                const fd = new FormData();
                fd.append('action', 'receive_delivery');
                fd.append('product_id', line.product_id);
                fd.append('supplier', this.grnForm.supplier);
                fd.append('quantity', line.quantity);
                fd.append('unit_cost', unitCost.toFixed(2));
                fd.append('invoice_number', this.grnForm.invoice_number);
                fd.append('delivery_date', this.grnForm.delivery_date);
                const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
                if (r.success) success++; else { alert('Error for item: ' + r.message); return; }
            }
            if (success === validLines.length) this.reloadTab();
        },
        async recallDelivery() {
            const qty = parseInt(this.recallForm.quantity);
            if (!qty || qty <= 0) { alert('Please enter a valid quantity'); return; }
            if (qty > this.recallForm.max_qty) { alert('Cannot recall more than the delivered quantity (' + this.recallForm.max_qty + ')'); return; }
            const fd = new FormData();
            fd.append('action', 'recall_delivery');
            fd.append('delivery_id', this.recallForm.delivery_id);
            fd.append('product_id', this.recallForm.product_id);
            fd.append('quantity', qty);
            fd.append('notes', this.recallForm.notes);
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) { this.recallModal = false; this.reloadTab(); } else alert(r.message);
        },
        async updateDeliveryRecord() {
            if (!this.editDeliveryForm.quantity || this.editDeliveryForm.quantity <= 0) { alert('Please enter a valid quantity'); return; }
            const fd = new FormData();
            fd.append('action', 'update_delivery');
            fd.append('delivery_id', this.editDeliveryForm.delivery_id);
            fd.append('quantity', this.editDeliveryForm.quantity);
            fd.append('unit_cost', this.editDeliveryForm.unit_cost);
            fd.append('supplier_name', this.editDeliveryForm.supplier_name);
            fd.append('invoice_number', this.editDeliveryForm.invoice_number);
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) { this.editDeliveryModal = false; this.reloadTab(); } else alert(r.message);
        },
        async deleteDelivery(d) {
            if (!confirm('Delete this delivery?\n\nProduct: ' + d.product_name + '\nQty: ' + d.quantity + ' units\nSupplier: ' + d.supplier_name + '\n\nThis will remove ' + d.quantity + ' units from the Purchase column in Product Inventory.')) return;
            const fd = new FormData();
            fd.append('action', 'delete_delivery');
            fd.append('delivery_id', d.id);
            try {
                const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
                if (r.success) { this.reloadTab(); }
                else { alert(r.message || 'Error deleting delivery'); }
            } catch(e) { alert('Connection error'); }
        },
        async recordStockOut() {
            const fd = new FormData(); fd.append('action','stock_out');
            Object.entries(this.stockOutForm).forEach(([k,v]) => fd.append(k,v));
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) this.reloadTab(); else alert(r.message);
        },
        async issueToDepartment() {
            if (!this.deptIssueForm.department_id) { alert('Please select a department'); return; }
            if (!this.deptIssueForm.product_id) { alert('Please select a product'); return; }
            if (!this.deptIssueForm.quantity || this.deptIssueForm.quantity <= 0) { alert('Please enter a valid quantity'); return; }
            const fd = new FormData();
            fd.append('action', 'issue_to_department');
            fd.append('department_id', this.deptIssueForm.department_id);
            fd.append('product_id', this.deptIssueForm.product_id);
            fd.append('quantity', this.deptIssueForm.quantity);
            fd.append('issue_date', this.stockDate);
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                this.deptIssueForm = { department_id:'', product_id:'', quantity:0 };
                this.reloadTab();
            } else alert(r.message);
        },
        async saveCount() {
            const fd = new FormData(); fd.append('action','save_count');
            Object.entries(this.countForm).forEach(([k,v]) => fd.append(k,v));
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) this.reloadTab(); else alert(r.message);
        },
        async logWastage() {
            const fd = new FormData(); fd.append('action','log_wastage');
            fd.append('product_id', this.wastageForm.product_id);
            fd.append('quantity', this.wastageForm.quantity);
            fd.append('reason', (this.wastageForm.reason_code + ': ' + this.wastageForm.reason).trim());
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) this.reloadTab(); else alert(r.message);
        },
        // Supplier tab methods
        async addSupplier() {
            if (!this.supplierForm.name.trim()) { alert('Supplier name is required'); return; }
            const fd = new FormData(); fd.append('action','add_supplier');
            const payload = { ...this.supplierForm, category: this.supplierForm.category.join(', ') };
            Object.entries(payload).forEach(([k,v]) => fd.append(k,v));
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                window.location.href = 'main_store.php?stock_date=' + this.stockDate + '&tab=suppliers';
            } else alert(r.message);
        },
        editSupplier(s) {
            this.editingSupplier = s.id;
            const cats = (s.category||'').split(',').map(c => c.trim()).filter(Boolean);
            this.supplierForm = { name: s.name, contact_person: s.contact_person||'', phone: s.phone||'', email: s.email||'', address: s.address||'', category: cats, notes: s.notes||'' };
        },
        async updateSupplier() {
            const fd = new FormData(); fd.append('action','update_supplier'); fd.append('id', this.editingSupplier);
            const payload = { ...this.supplierForm, category: this.supplierForm.category.join(', ') };
            Object.entries(payload).forEach(([k,v]) => fd.append(k,v));
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                const idx = this.suppliers.findIndex(s => s.id === this.editingSupplier);
                if (idx !== -1) this.suppliers[idx] = { ...this.suppliers[idx], ...payload };
                this.editingSupplier = null;
                this.supplierForm = { name:'', contact_person:'', phone:'', email:'', address:'', category:[], notes:'' };
                this.$nextTick(() => lucide.createIcons());
            } else alert(r.message);
        },
        async deleteSupplier(id, name) {
            if (!confirm('Delete supplier "' + name + '"?')) return;
            const fd = new FormData(); fd.append('action','delete_supplier'); fd.append('id', id);
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                this.suppliers = this.suppliers.filter(s => s.id !== id);
            } else alert(r.message);
        },
        cancelEditSupplier() {
            this.editingSupplier = null;
            this.supplierForm = { name:'', contact_person:'', phone:'', email:'', address:'', category:[], notes:'' };
        },
    }
}
</script>
<?php include '../includes/dashboard_scripts.php'; ?>
</body></html>
