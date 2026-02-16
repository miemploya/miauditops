<?php
/**
 * MIAUDITOPS — Stock Control Overview
 * Hub page: Main Store card + Department management
 * Main Store (main_store.php) contains Products, Stock In/Out, Count, Wastage tabs
 */
require_once '../includes/functions.php';
require_login();
require_permission('stock');
require_active_client();
$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$page_title = 'Stock Audit';

// Summary KPIs
$stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(current_stock * unit_cost),0) as value FROM products WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$stock_summary = $stmt->fetch();

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM products WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL AND current_stock <= reorder_level");
$stmt->execute([$company_id, $client_id]);
$low_stock = $stmt->fetch()['cnt'];

// Departments
$stmt = $pdo->prepare("SELECT sd.*, co.name as outlet_name, u.first_name, u.last_name FROM stock_departments sd LEFT JOIN client_outlets co ON sd.outlet_id = co.id LEFT JOIN users u ON sd.created_by = u.id WHERE sd.company_id = ? AND sd.client_id = ? AND sd.deleted_at IS NULL ORDER BY sd.name");
$stmt->execute([$company_id, $client_id]);
$departments = $stmt->fetchAll();

// Outlets for department creation — direct query for reliability
$stmt = $pdo->prepare("SELECT id, name FROM client_outlets WHERE client_id = ? AND company_id = ? AND (deleted_at IS NULL) AND is_active = 1 ORDER BY name");
$stmt->execute([$client_id, $company_id]);
$client_outlets = $stmt->fetchAll();
// Fallback: if nothing found, try without is_active filter
if (empty($client_outlets)) {
    $stmt = $pdo->prepare("SELECT id, name FROM client_outlets WHERE client_id = ? AND company_id = ? AND deleted_at IS NULL ORDER BY name");
    $stmt->execute([$client_id, $company_id]);
    $client_outlets = $stmt->fetchAll();
}
// Last resort: all outlets for this company
if (empty($client_outlets)) {
    $stmt = $pdo->prepare("SELECT id, name FROM client_outlets WHERE company_id = ? AND deleted_at IS NULL ORDER BY name");
    $stmt->execute([$company_id]);
    $client_outlets = $stmt->fetchAll();
}

$js_all_departments = json_encode($departments, JSON_HEX_TAG | JSON_HEX_APOS);
// Separate kitchen from regular departments
$kitchen = null;
$regular_departments = [];
foreach ($departments as $d) {
    if (($d['type'] ?? '') === 'kitchen') {
        $kitchen = $d;
    } else {
        $regular_departments[] = $d;
    }
}
$js_departments = json_encode($regular_departments, JSON_HEX_TAG | JSON_HEX_APOS);
$js_kitchen = json_encode($kitchen, JSON_HEX_TAG | JSON_HEX_APOS);
$js_outlets = json_encode($client_outlets, JSON_HEX_TAG | JSON_HEX_APOS);

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
$product_categories = $stmt->fetchAll();
$js_categories = json_encode($product_categories, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Audit — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>[x-cloak]{display:none!important}.glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95) 0%,rgba(249,250,251,0.9) 100%);backdrop-filter:blur(20px)}.dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95) 0%,rgba(30,41,59,0.9) 100%)}</style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="stockOverview()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8">
            <?php display_flash_message(); ?>

            <!-- Page Header -->
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h1 class="text-2xl font-black text-slate-900 dark:text-white">Stock Audit</h1>
                    <p class="text-sm text-slate-500 mt-1">Manage your central store and department inventories</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="showCatModal = true" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl shadow-lg shadow-amber-500/30 hover:shadow-amber-500/50 hover:scale-[1.02] transition-all text-sm">
                        <i data-lucide="tag" class="w-4 h-4"></i> Add Category
                    </button>
                    <button @click="showDeptModal = true" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-violet-500 to-purple-600 text-white font-bold rounded-xl shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-[1.02] transition-all text-sm">
                        <i data-lucide="plus" class="w-4 h-4"></i> Add Department
                    </button>
                </div>
            </div>

            <!-- KPI Strip -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
                <div class="glass-card rounded-xl p-5 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Products</p>
                    <p class="text-2xl font-black text-slate-800 dark:text-white"><?= number_format($stock_summary['total']) ?></p>
                </div>
                <div class="glass-card rounded-xl p-5 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Stock Value</p>
                    <p class="text-2xl font-black text-emerald-600">₦<?= number_format($stock_summary['value'], 2) ?></p>
                </div>
                <div class="glass-card rounded-xl p-5 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Low Stock Alerts</p>
                    <p class="text-2xl font-black text-red-600"><?= $low_stock ?></p>
                </div>
                <div class="glass-card rounded-xl p-5 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Departments</p>
                    <p class="text-2xl font-black text-violet-600" x-text="departments.length"></p>
                </div>
            </div>

            <!-- Main Store Card -->
            <div class="mb-8">
                <h2 class="text-xs font-bold uppercase text-slate-400 mb-3 tracking-wider">Central Inventory</h2>
                <a href="main_store.php" class="block glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg hover:shadow-xl hover:scale-[1.005] transition-all overflow-hidden group cursor-pointer">
                    <div class="flex items-center gap-5 p-6">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/30 group-hover:shadow-emerald-500/50 transition-all">
                            <i data-lucide="warehouse" class="w-7 h-7 text-white"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-black text-slate-900 dark:text-white group-hover:text-emerald-600 transition-colors">Main Store</h3>
                            <p class="text-sm text-slate-500 mt-0.5">Central inventory — Products, Stock In/Out, Physical Count, Wastage & Damage</p>
                            <div class="flex items-center gap-4 mt-2">
                                <span class="text-xs font-bold text-slate-600 dark:text-slate-400"><?= number_format($stock_summary['total']) ?> products</span>
                                <span class="text-xs font-bold text-emerald-600">₦<?= number_format($stock_summary['value'], 2) ?> total value</span>
                                <?php if ($low_stock > 0): ?><span class="text-xs font-bold text-red-600"><?= $low_stock ?> low stock</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center group-hover:bg-emerald-100 dark:group-hover:bg-emerald-900/40 transition-all">
                            <i data-lucide="arrow-right" class="w-5 h-5 text-emerald-600"></i>
                        </div>
                    </div>
                </a>

                <!-- Kitchen Card (shown only if a Kitchen exists) -->
                <?php if ($kitchen): ?>
                <a href="department_store.php?dept_id=<?= $kitchen['id'] ?>" class="block glass-card rounded-2xl border border-amber-200/60 dark:border-amber-700/40 shadow-lg hover:shadow-xl hover:scale-[1.005] transition-all overflow-hidden group cursor-pointer mt-3">
                    <div class="flex items-center gap-5 p-6">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30 group-hover:shadow-amber-500/50 transition-all">
                            <i data-lucide="chef-hat" class="w-7 h-7 text-white"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-black text-slate-900 dark:text-white group-hover:text-amber-600 transition-colors">Kitchen</h3>
                            <p class="text-sm text-slate-500 mt-0.5">Food preparation — Receives raw materials from Main Store, supplies finished goods to Restaurant outlets</p>
                            <div class="flex items-center gap-4 mt-2">
                                <span class="px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 text-[10px] font-bold">🍳 Shared Kitchen</span>
                                <span class="text-xs text-slate-400">Serves all Restaurant departments</span>
                            </div>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center group-hover:bg-amber-100 dark:group-hover:bg-amber-900/40 transition-all">
                            <i data-lucide="arrow-right" class="w-5 h-5 text-amber-600"></i>
                        </div>
                    </div>
                </a>
                <?php endif; ?>
            </div>

            <!-- Departments Section -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-bold uppercase text-slate-400 tracking-wider">Departments</h2>
                    <p class="text-xs text-slate-500">Departments are linked to sales outlets for audit reconciliation</p>
                </div>

                <!-- Department Cards Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <template x-for="d in departments" :key="d.id">
                        <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-md hover:shadow-lg transition-all overflow-hidden">
                            <a :href="'department_store.php?dept_id=' + d.id" class="block p-5 hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-md">
                                            <i data-lucide="building-2" class="w-4.5 h-4.5 text-white"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="d.name"></h3>
                                            <p class="text-[10px] text-slate-500" x-text="'Created by ' + (d.first_name||'') + ' ' + (d.last_name||'')"></p>
                                        </div>
                                    </div>
                                    <button @click.prevent="deleteDepartment(d.id, d.name)" class="w-7 h-7 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 flex items-center justify-center transition-all">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-500"></i>
                                    </button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <i data-lucide="map-pin" class="w-3 h-3 text-indigo-500"></i>
                                    <span class="px-2.5 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 text-xs font-semibold" x-text="d.outlet_name || 'Not linked'"></span>
                                </div>
                                <template x-if="d.description">
                                    <p class="text-xs text-slate-500 mt-2 line-clamp-2" x-text="d.description"></p>
                                </template>
                                <div class="mt-3 flex items-center gap-1.5 text-[10px] font-semibold text-violet-600">
                                    <span>View Inventory</span>
                                    <i data-lucide="arrow-right" class="w-3 h-3"></i>
                                </div>
                            </a>
                        </div>
                    </template>

                    <!-- Empty State -->
                    <div x-show="departments.length === 0" class="col-span-full">
                        <div class="glass-card rounded-2xl border border-dashed border-slate-300 dark:border-slate-700 p-12 text-center">
                            <div class="w-16 h-16 rounded-2xl bg-violet-50 dark:bg-violet-900/20 flex items-center justify-center mx-auto mb-4">
                                <i data-lucide="building-2" class="w-7 h-7 text-violet-400"></i>
                            </div>
                            <h3 class="font-bold text-slate-700 dark:text-slate-300 mb-1">No Departments Yet</h3>
                            <p class="text-sm text-slate-500 mb-4">Create departments to manage inventory at different locations linked to your sales outlets.</p>
                            <button @click="showDeptModal = true" class="px-4 py-2 bg-gradient-to-r from-violet-500 to-purple-600 text-white font-bold rounded-xl text-sm hover:scale-105 transition-all">+ Add Department</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Create Department Modal -->
            <div x-show="showDeptModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="showDeptModal = false">
                <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden" x-transition.scale.95>
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-violet-500/10 via-purple-500/5 to-transparent">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/30"><i data-lucide="building-2" class="w-4 h-4 text-white"></i></div>
                                <div>
                                    <h3 class="font-bold text-slate-900 dark:text-white text-sm">Create Department</h3>
                                    <p class="text-[10px] text-slate-500">Sub-store linked to a sales outlet</p>
                                </div>
                            </div>
                            <button @click="showDeptModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-slate-200 transition-all"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                        </div>
                    </div>
                    <form @submit.prevent="createDepartment()" class="p-6 space-y-4">
                        <div><label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Department Name *</label><input type="text" x-model="deptForm.name" required placeholder="e.g. Kitchen, Bar, Shop Floor" class="w-full px-3 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all"></div>
                        <div><label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Linked Sales Outlet *</label>
                            <select x-model="deptForm.outlet_id" required class="w-full px-3 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all">
                                <option value="">Select an outlet...</option>
                                <template x-for="o in outlets" :key="o.id"><option :value="o.id" x-text="o.name"></option></template>
                            </select>
                            <p class="text-[10px] text-slate-400 mt-1.5">Linking to an outlet enables audit reconciliation: department stock count vs outlet sales</p>
                        </div>
                        <div><label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Description</label><textarea x-model="deptForm.description" rows="2" placeholder="Optional notes about this department..." class="w-full px-3 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all resize-none"></textarea></div>
                        <div class="flex gap-3 pt-2">
                            <button type="button" @click="showDeptModal = false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-bold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                            <button type="submit" class="flex-1 py-2.5 bg-gradient-to-r from-violet-500 to-purple-600 text-white font-bold rounded-xl shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-[1.02] transition-all text-sm">Create Department</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Category Management Modal -->
            <div x-show="showCatModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="showCatModal = false">
                <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden" x-transition.scale.95>
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 via-orange-500/5 to-transparent">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30"><i data-lucide="tag" class="w-4 h-4 text-white"></i></div>
                                <div>
                                    <h3 class="font-bold text-slate-900 dark:text-white text-sm">Product Categories</h3>
                                    <p class="text-[10px] text-slate-500">Manage product categories for inventory grouping</p>
                                </div>
                            </div>
                            <button @click="showCatModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-slate-200 transition-all"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                        </div>
                    </div>
                    <div class="p-6">
                        <!-- Existing Categories -->
                        <div class="mb-4">
                            <h4 class="text-[11px] font-bold uppercase text-slate-400 mb-2">Existing Categories</h4>
                            <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                <template x-for="cat in productCategories" :key="cat.id">
                                    <div class="flex items-center justify-between px-3 py-2 bg-slate-50 dark:bg-slate-800/50 rounded-lg">
                                        <span class="text-sm font-semibold text-slate-700 dark:text-slate-300" x-text="cat.name"></span>
                                        <button @click="deleteCategory(cat.id, cat.name)" class="w-6 h-6 rounded bg-red-50 hover:bg-red-100 dark:bg-red-900/20 flex items-center justify-center transition-all">
                                            <i data-lucide="trash-2" class="w-3 h-3 text-red-500"></i>
                                        </button>
                                    </div>
                                </template>
                                <div x-show="productCategories.length === 0" class="text-center py-4 text-xs text-slate-400">No categories yet. Add one below.</div>
                            </div>
                        </div>
                        <!-- Add New Category -->
                        <form @submit.prevent="addCategory()" class="flex gap-2">
                            <input type="text" x-model="catForm.name" required placeholder="e.g. Beverages, Food, Cleaning" class="flex-1 px-3 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all">
                            <button type="submit" class="px-4 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl shadow-lg shadow-amber-500/30 hover:scale-[1.02] transition-all text-sm">Add</button>
                        </form>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>
<script>
function stockOverview() {
    return {
        showDeptModal: false,
        showCatModal: false,
        departments: <?php echo $js_departments; ?>,
        outlets: <?php echo $js_outlets; ?>,
        productCategories: <?php echo $js_categories; ?>,
        deptForm: { name:'', outlet_id:'', description:'' },
        catForm: { name:'' },

        async createDepartment() {
            if (!this.deptForm.name || !this.deptForm.outlet_id) { alert('Please enter a name and select an outlet'); return; }
            if (this.productCategories.length === 0) { alert('Please create at least one product category before adding departments.'); return; }
            const fd = new FormData(); fd.append('action','create_department');
            Object.entries(this.deptForm).forEach(([k,v]) => fd.append(k,v));
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        async deleteDepartment(id, name) {
            if (!confirm('Delete department "' + name + '"?')) return;
            const fd = new FormData(); fd.append('action','delete_department'); fd.append('id', id);
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        async addCategory() {
            if (!this.catForm.name.trim()) { alert('Please enter a category name'); return; }
            const fd = new FormData(); fd.append('action','add_category'); fd.append('name', this.catForm.name.trim());
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                location.reload();
            } else alert(r.message);
        },
        async deleteCategory(id, name) {
            if (!confirm('Delete category "' + name + '"? Products with this category will become uncategorized.')) return;
            const fd = new FormData(); fd.append('action','delete_category'); fd.append('id', id);
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) {
                this.productCategories = this.productCategories.filter(c => c.id !== id);
            } else alert(r.message);
        },
    }
}
</script>
<?php include '../includes/dashboard_scripts.php'; ?>
</body></html>
