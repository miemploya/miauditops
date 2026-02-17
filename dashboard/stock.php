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

// Auto-migration: parent_department_id for sub-department hierarchy
try {
    $cols = $pdo->query("SHOW COLUMNS FROM stock_departments LIKE 'parent_department_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE stock_departments ADD COLUMN parent_department_id INT DEFAULT NULL AFTER description");
    }
} catch (Exception $e) { error_log('Sub-dept migration: ' . $e->getMessage()); }

// Summary KPIs
$stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(current_stock * unit_cost),0) as value FROM products WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL");
$stmt->execute([$company_id, $client_id]);
$stock_summary = $stmt->fetch();

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM products WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL AND current_stock <= reorder_level");
$stmt->execute([$company_id, $client_id]);
$low_stock = $stmt->fetch()['cnt'];

// Departments
$stmt = $pdo->prepare("SELECT sd.*, sd.parent_department_id, co.name as outlet_name, u.first_name, u.last_name FROM stock_departments sd LEFT JOIN client_outlets co ON sd.outlet_id = co.id LEFT JOIN users u ON sd.created_by = u.id WHERE sd.company_id = ? AND sd.client_id = ? AND sd.deleted_at IS NULL ORDER BY sd.name");
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
$js_departments = $js_all_departments; // Alpine.js form uses this
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
            </div>

            <!-- ALL Departments Section (unified) -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-bold uppercase text-slate-400 tracking-wider">Departments</h2>
                    <p class="text-xs text-slate-500">Sales & production departments linked to outlets</p>
                </div>

                <?php
                // Build a lookup for parent names
                $dept_name_map = [];
                foreach ($departments as $_d) { $dept_name_map[$_d['id']] = $_d['name']; }

                // Separate: parents first (no parent_department_id), then sub-departments
                $parent_depts = [];
                $child_depts = [];
                foreach ($departments as $d) {
                    if (!empty($d['parent_department_id']) && isset($dept_name_map[$d['parent_department_id']])) {
                        $child_depts[$d['parent_department_id']][] = $d;
                    } else {
                        $parent_depts[] = $d;
                    }
                }

                // Type config: icon, gradient, badge, label
                $type_config = [
                    'standard' => ['icon' => 'building-2', 'from' => 'violet-500', 'to' => 'purple-600', 'shadow' => 'violet', 'badge_bg' => 'violet', 'emoji' => '🏬', 'label' => 'Department', 'desc' => 'Sales department linked to an outlet'],
                    'kitchen'  => ['icon' => 'chef-hat',   'from' => 'amber-500',  'to' => 'orange-600', 'shadow' => 'amber',  'badge_bg' => 'amber',  'emoji' => '🍳', 'label' => 'Kitchen',    'desc' => 'Food prep — receives ingredients, supplies finished goods'],
                    'shisha'   => ['icon' => 'wind',       'from' => 'teal-500',   'to' => 'cyan-600',   'shadow' => 'teal',   'badge_bg' => 'teal',   'emoji' => '🌬️', 'label' => 'Shisha',     'desc' => 'Tracks flavors, charcoal & accessories via recipes'],
                    'cocktail' => ['icon' => 'wine',       'from' => 'pink-500',   'to' => 'rose-600',   'shadow' => 'pink',   'badge_bg' => 'pink',   'emoji' => '🍹', 'label' => 'Cocktail',   'desc' => 'Tracks spirits, mixers & garnishes via recipes'],
                ];

                // Render function
                function render_dept_card($dept, $type_config, $dept_name_map, $child_depts, $is_child = false) {
                    $type = $dept['type'] ?? 'standard';
                    $tc = $type_config[$type] ?? $type_config['standard'];
                    $id = $dept['id'];
                    $name = htmlspecialchars($dept['name']);
                    $safe_name = addslashes(htmlspecialchars($dept['name']));
                    $children = $child_depts[$id] ?? [];
                    ?>
                    <div class="<?= $is_child ? 'ml-8 border-l-2 border-slate-200 dark:border-slate-700 pl-4' : '' ?>">
                        <div class="glass-card rounded-2xl border border-<?= $tc['badge_bg'] ?>-200/60 dark:border-<?= $tc['badge_bg'] ?>-700/40 shadow-md hover:shadow-lg transition-all overflow-hidden group <?= $is_child ? 'mt-2' : '' ?>">
                            <div class="flex items-center gap-4 p-5">
                                <a href="department_store.php?dept_id=<?= $id ?>" class="flex items-center gap-4 flex-1 cursor-pointer">
                                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-<?= $tc['from'] ?> to-<?= $tc['to'] ?> flex items-center justify-center shadow-md shadow-<?= $tc['shadow'] ?>-500/20">
                                        <i data-lucide="<?= $tc['icon'] ?>" class="w-5 h-5 text-white"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-bold text-slate-900 dark:text-white text-sm truncate"><?= $name ?></h3>
                                        <p class="text-[10px] text-slate-400 mt-0.5"><?= $tc['desc'] ?></p>
                                        <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                            <span class="px-2 py-0.5 rounded-full bg-<?= $tc['badge_bg'] ?>-100 dark:bg-<?= $tc['badge_bg'] ?>-900/30 text-<?= $tc['badge_bg'] ?>-700 dark:text-<?= $tc['badge_bg'] ?>-300 text-[10px] font-bold"><?= $tc['emoji'] ?> <?= $tc['label'] ?></span>
                                            <?php if (!empty($dept['outlet_name'])): ?>
                                                <span class="inline-flex items-center gap-1 text-[10px] text-slate-400"><i data-lucide="map-pin" class="w-3 h-3"></i> <?= htmlspecialchars($dept['outlet_name']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($dept['parent_department_id']) && isset($dept_name_map[$dept['parent_department_id']])): ?>
                                                <span class="px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500 text-[10px] font-bold">↳ under <?= htmlspecialchars($dept_name_map[$dept['parent_department_id']]) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($children)): ?>
                                                <span class="px-2 py-0.5 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-600 text-[10px] font-bold"><?= count($children) ?> sub-dept<?= count($children) > 1 ? 's' : '' ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                                <div class="flex gap-1.5">
                                    <button onclick="stockOverviewApp.renameKitchen(<?= $id ?>, '<?= $safe_name ?>')" class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center hover:bg-blue-100 transition-all" title="Rename">
                                        <i data-lucide="pencil" class="w-3.5 h-3.5 text-blue-600"></i>
                                    </button>
                                    <button onclick="stockOverviewApp.deleteKitchen(<?= $id ?>, '<?= $safe_name ?>')" class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-900/20 flex items-center justify-center hover:bg-red-100 transition-all" title="Delete">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-500"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php
                        // Render children indented
                        if (!empty($children)) {
                            foreach ($children as $child) {
                                render_dept_card($child, $type_config, $dept_name_map, $child_depts, true);
                            }
                        }
                        ?>
                    </div>
                    <?php
                }
                ?>

                <div class="space-y-3">
                    <?php if (empty($parent_depts)): ?>
                        <div class="glass-card rounded-2xl border border-dashed border-slate-300 dark:border-slate-700 p-12 text-center">
                            <div class="w-16 h-16 rounded-2xl bg-violet-50 dark:bg-violet-900/20 flex items-center justify-center mx-auto mb-4">
                                <i data-lucide="building-2" class="w-7 h-7 text-violet-400"></i>
                            </div>
                            <h3 class="font-bold text-slate-700 dark:text-slate-300 mb-1">No Departments Yet</h3>
                            <p class="text-sm text-slate-500 mb-4">Create departments to manage inventory at different locations linked to your sales outlets.</p>
                            <button @click="showDeptModal = true" class="px-4 py-2 bg-gradient-to-r from-violet-500 to-purple-600 text-white font-bold rounded-xl text-sm hover:scale-105 transition-all">+ Add Department</button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($parent_depts as $pd): ?>
                            <?php render_dept_card($pd, $type_config, $dept_name_map, $child_depts); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
                    <form @submit.prevent="createDepartment()" class="p-4 space-y-3">
                        <!-- Department Type Selector -->
                        <div>
                            <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Department Type *</label>
                            <div class="grid grid-cols-4 gap-1.5">
                                <button type="button" @click="deptForm.type = 'standard'"
                                    :class="deptForm.type === 'standard' ? 'ring-2 ring-violet-500 border-violet-400 bg-violet-50 dark:bg-violet-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50'"
                                    class="flex flex-col items-center gap-0.5 px-2 py-1.5 border rounded-lg text-center transition-all">
                                    <span class="text-base">🏬</span>
                                    <div class="text-[10px] font-bold text-slate-700 dark:text-slate-200">Standard</div>
                                </button>
                                <button type="button" @click="deptForm.type = 'kitchen'"
                                    :class="deptForm.type === 'kitchen' ? 'ring-2 ring-amber-500 border-amber-400 bg-amber-50 dark:bg-amber-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50'"
                                    class="flex flex-col items-center gap-0.5 px-2 py-1.5 border rounded-lg text-center transition-all">
                                    <span class="text-base">🍳</span>
                                    <div class="text-[10px] font-bold text-slate-700 dark:text-slate-200">Kitchen</div>
                                </button>
                                <button type="button" @click="deptForm.type = 'shisha'"
                                    :class="deptForm.type === 'shisha' ? 'ring-2 ring-teal-500 border-teal-400 bg-teal-50 dark:bg-teal-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50'"
                                    class="flex flex-col items-center gap-0.5 px-2 py-1.5 border rounded-lg text-center transition-all">
                                    <span class="text-base">🌬️</span>
                                    <div class="text-[10px] font-bold text-slate-700 dark:text-slate-200">Shisha</div>
                                </button>
                                <button type="button" @click="deptForm.type = 'cocktail'"
                                    :class="deptForm.type === 'cocktail' ? 'ring-2 ring-pink-500 border-pink-400 bg-pink-50 dark:bg-pink-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50'"
                                    class="flex flex-col items-center gap-0.5 px-2 py-1.5 border rounded-lg text-center transition-all">
                                    <span class="text-base">🍹</span>
                                    <div class="text-[10px] font-bold text-slate-700 dark:text-slate-200">Cocktail</div>
                                </button>
                            </div>
                        </div>

                        <!-- Guided Suggestions (compact) -->
                        <div class="rounded-lg border px-2.5 py-1.5 text-[10px] transition-all"
                            :class="{
                                'border-violet-200 bg-violet-50/50 text-violet-700': deptForm.type === 'standard',
                                'border-amber-200 bg-amber-50/50 text-amber-700': deptForm.type === 'kitchen',
                                'border-teal-200 bg-teal-50/50 text-teal-700': deptForm.type === 'shisha',
                                'border-pink-200 bg-pink-50/50 text-pink-700': deptForm.type === 'cocktail'
                            }">
                            <template x-if="deptForm.type === 'standard'"><p>💡 e.g. Bar 1, VIP Bar, Shop Floor — regular sales department</p></template>
                            <template x-if="deptForm.type === 'kitchen'"><p>💡 e.g. Main Kitchen — uses <strong>Recipe Builder</strong> for food cost tracking</p></template>
                            <template x-if="deptForm.type === 'shisha'"><p>💡 e.g. Shisha Lounge — uses <strong>Recipe Builder</strong> for tobacco & accessories</p></template>
                            <template x-if="deptForm.type === 'cocktail'"><p>💡 e.g. Cocktail Bar — uses <strong>Recipe Builder</strong> for spirits & mixers</p></template>
                        </div>

                        <!-- Name + Outlet on same row -->
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Department Name *</label>
                                <input type="text" x-model="deptForm.name" required
                                    :placeholder="deptForm.type === 'standard' ? 'e.g. Bar 1' : deptForm.type === 'kitchen' ? 'e.g. Main Kitchen' : deptForm.type === 'shisha' ? 'e.g. Shisha Lounge' : 'e.g. Cocktail Bar'"
                                    class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all">
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Linked Outlet *</label>
                                <select x-model="deptForm.outlet_id" required class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all">
                                    <option value="">Select outlet...</option>
                                    <template x-for="o in outlets" :key="o.id"><option :value="o.id" x-text="o.name"></option></template>
                                </select>
                            </div>
                        </div>

                        <!-- Parent Department (compact) -->
                        <div>
                            <label class="text-[11px] font-semibold mb-1 block text-slate-500">
                                Parent Dept <span class="font-normal text-slate-400">(optional)</span>
                            </label>
                            <select x-model="deptForm.parent_department_id" class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all">
                                <option value="0">Standalone (independent)</option>
                                <template x-for="pd in allDepartments.filter(d => d.type !== 'kitchen' || deptForm.type !== 'kitchen')" :key="'pd_'+pd.id">
                                    <option :value="pd.id" x-text="'↳ under ' + pd.name"></option>
                                </template>
                            </select>
                        </div>

                        <!-- Description (single-line) -->
                        <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Description <span class="font-normal text-slate-400">(optional)</span></label>
                            <input type="text" x-model="deptForm.description" placeholder="Optional notes..." class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all">
                        </div>

                        <div class="flex gap-3 pt-1">
                            <button type="button" @click="showDeptModal = false" class="flex-1 py-2 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-bold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                            <button type="submit" class="flex-1 py-2 bg-gradient-to-r from-violet-500 to-purple-600 text-white font-bold rounded-xl shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-[1.02] transition-all text-sm">Create Department</button>
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
        allDepartments: <?php echo $js_all_departments; ?>,
        outlets: <?php echo $js_outlets; ?>,
        productCategories: <?php echo $js_categories; ?>,
        deptForm: { name:'', outlet_id:'', description:'', type:'standard', parent_department_id: 0 },
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
        async renameKitchen(id, currentName) {
            const newName = prompt('Rename kitchen:', currentName);
            if (!newName || newName.trim() === '' || newName.trim() === currentName) return;
            const fd = new FormData();
            fd.append('action', 'rename_department');
            fd.append('id', id);
            fd.append('name', newName.trim());
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        async deleteKitchen(id, name) {
            if (!confirm('Delete kitchen "' + name + '"? This cannot be undone.')) return;
            const fd = new FormData();
            fd.append('action', 'delete_department');
            fd.append('id', id);
            const r = await (await fetch('../ajax/stock_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
    }
}
</script>
<?php include '../includes/dashboard_scripts.php'; ?>
</body></html>
