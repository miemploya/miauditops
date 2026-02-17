<?php
/**
 * MIAUDITOPS — Department Store
 * Per-department inventory view with columns:
 * Product, SKU, Category, Opening, Added, Return In, Total, Transfer, Qty Sold, Closing, Selling Price, Amount, Actions
 */
require_once '../includes/functions.php';
require_login();
require_permission('department_store');
require_active_client();
$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();

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

// === Auto-migration: kitchen_recipes table ===
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_recipes (
        id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, client_id INT NOT NULL,
        product_id INT NOT NULL, ingredient_product_id INT NOT NULL,
        qty_per_plate DECIMAL(10,3) NOT NULL DEFAULT 1,
        unit VARCHAR(30) DEFAULT 'portions',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_recipe_ingredient (product_id, ingredient_product_id),
        INDEX idx_company (company_id, client_id),
        INDEX idx_product (product_id)
    ) ENGINE=InnoDB");
} catch (Exception $e) { error_log('Kitchen recipes migration: ' . $e->getMessage()); }

// === Date filter (default = today) ===
$stock_date = $_GET['stock_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $stock_date)) $stock_date = date('Y-m-d');

$dept_id = intval($_GET['dept_id'] ?? 0);
if (!$dept_id) { header('Location: stock.php'); exit; }

// Load department info
$stmt = $pdo->prepare("SELECT sd.*, co.name as outlet_name, co.type as outlet_type FROM stock_departments sd LEFT JOIN client_outlets co ON sd.outlet_id = co.id WHERE sd.id = ? AND sd.company_id = ? AND sd.client_id = ? AND sd.deleted_at IS NULL");
$stmt->execute([$dept_id, $company_id, $client_id]);
$dept = $stmt->fetch();
if (!$dept) { header('Location: stock.php'); exit; }

$dept_type = $dept['type'] ?? 'standard';
$is_kitchen = in_array($dept_type, ['kitchen', 'shisha', 'cocktail']);
$is_restaurant = strtolower($dept['outlet_type'] ?? '') === 'restaurant';

// Type-aware labels for UI
$recipe_labels = ['kitchen' => 'Kitchen', 'shisha' => 'Shisha', 'cocktail' => 'Cocktail'];
$recipe_emojis = ['kitchen' => '🍳', 'shisha' => '🌬️', 'cocktail' => '🍹'];
$recipe_label = $recipe_labels[$dept_type] ?? 'Recipe';
$recipe_emoji = $recipe_emojis[$dept_type] ?? '📋';

// Get parent department for sub-department breadcrumb
$parent_dept = null;
if (!empty($dept['parent_department_id'])) {
    $stmt = $pdo->prepare("SELECT id, name FROM stock_departments WHERE id = ? AND company_id = ? AND client_id = ? AND deleted_at IS NULL");
    $stmt->execute([$dept['parent_department_id'], $company_id, $client_id]);
    $parent_dept = $stmt->fetch();
}

$page_title = $dept['name'];

// Load ALL products ever associated with this department to ensure daily persistence
// Logic: Fetch product details + Left Join today's stock + Subquery for Prior Balance (Opening)
$stmt = $pdo->prepare("
    SELECT 
        p.id as product_id, p.name as product_name, p.sku, p.category, p.unit_cost, p.selling_price as master_price, p.parent_product_id, p.selling_unit,
        ds.id, ds.opening_stock, ds.added, ds.return_in, ds.transfer_out, ds.transfer_to_main, ds.qty_sold, ds.selling_price,
        COALESCE(ds.adjustment_add, 0) as adjustment_add, COALESCE(ds.adjustment_sub, 0) as adjustment_sub,
        (
            SELECT COALESCE(SUM(d2.added + d2.return_in + COALESCE(d2.adjustment_add,0) - d2.transfer_out - d2.transfer_to_main - d2.qty_sold - COALESCE(d2.adjustment_sub,0)), 0)
            FROM department_stock d2 
            WHERE d2.department_id = ? AND d2.company_id = ? AND d2.client_id = ? AND d2.product_id = p.id AND d2.stock_date < ?
        ) as prior_balance
    FROM products p
    JOIN (SELECT DISTINCT product_id FROM department_stock WHERE department_id = ? AND company_id = ? AND client_id = ?) used ON p.id = used.product_id
    LEFT JOIN department_stock ds ON ds.department_id = ? AND ds.company_id = ? AND ds.client_id = ? AND ds.product_id = p.id AND ds.stock_date = ?
    ORDER BY p.name
");
$stmt->execute([
    $dept_id, $company_id, $client_id, $stock_date, 
    $dept_id, $company_id, $client_id, 
    $dept_id, $company_id, $client_id, $stock_date
]);
$dept_stock = $stmt->fetchAll();

// Normalize data for JS: use prior_balance as Opening if today's opening isn't explicitly set (or 0)
// Actually, prior_balance IS the opening stock for today.
foreach ($dept_stock as &$row) {
    // If no record for today, opening is prior_balance
    $row['opening_stock'] = (int)($row['prior_balance'] ?? 0);
    // Fallback to master price if dept price is 0
    if (empty($row['selling_price']) || floatval($row['selling_price']) == 0) {
        $row['selling_price'] = $row['master_price'];
    }
}
unset($row);

// Load all products for "Add Product" dropdown (exclude already-added if they have stock today? No, exclude if they are already in the list)
$existing_ids = array_map(fn($r) => $r['product_id'], $dept_stock);
$stmt = $pdo->prepare("SELECT id, name, sku, category, selling_price, selling_unit, yield_per_unit, selling_unit_price FROM products WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL ORDER BY name");
$stmt->execute([$company_id, $client_id]);
$all_products = $stmt->fetchAll();
$available_products = array_filter($all_products, fn($p) => !in_array($p['id'], $existing_ids));

$js_dept_stock = json_encode($dept_stock, JSON_HEX_TAG | JSON_HEX_APOS);
$js_available  = json_encode(array_values($available_products), JSON_HEX_TAG | JSON_HEX_APOS);

// Load all departments (for transfer destination dropdown)
$stmt = $pdo->prepare("SELECT id, name FROM stock_departments WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL ORDER BY name");
$stmt->execute([$company_id, $client_id]);
$all_departments = $stmt->fetchAll();
// Exclude current department
$other_departments = array_values(array_filter($all_departments, fn($d) => $d['id'] != $dept_id));
$js_departments = json_encode($other_departments, JSON_HEX_TAG | JSON_HEX_APOS);

// For Restaurant departments: load Kitchen products as an additional source (from ALL kitchens)
$kitchen_products = [];
if ($is_restaurant) {
    // Find ALL Kitchen departments
    $stmt = $pdo->prepare("SELECT id FROM stock_departments WHERE company_id = ? AND client_id = ? AND type = 'kitchen' AND deleted_at IS NULL");
    $stmt->execute([$company_id, $client_id]);
    $kitchen_depts = $stmt->fetchAll();
    $all_kitchen_products = [];
    foreach ($kitchen_depts as $kd) {
        $kitchen_id = $kd['id'];
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.id, p.name, p.sku, p.category, p.selling_price 
            FROM products p 
            JOIN department_stock ds ON ds.product_id = p.id AND ds.department_id = ? AND ds.company_id = ? AND ds.client_id = ?
            WHERE p.deleted_at IS NULL
            ORDER BY p.name
        ");
        $stmt->execute([$kitchen_id, $company_id, $client_id]);
        foreach ($stmt->fetchAll() as $kp) {
            $all_kitchen_products[$kp['id']] = $kp; // deduplicate by product ID
        }
    }
    // Exclude products already in this department
    $kitchen_products = array_values(array_filter($all_kitchen_products, fn($p) => !in_array($p['id'], $existing_ids)));
}
$js_kitchen_products = json_encode($kitchen_products, JSON_HEX_TAG | JSON_HEX_APOS);
$js_is_kitchen = $is_kitchen ? 'true' : 'false';
$js_is_restaurant = $is_restaurant ? 'true' : 'false';

// Kitchen Catalog: products created by this kitchen
$kitchen_catalog_products = [];
if ($is_kitchen) {
    // Ensure kitchen_id column exists on products
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'kitchen_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN kitchen_id INT DEFAULT NULL AFTER reorder_level");
        }
    } catch (Exception $ignore) {}
    // Load products tagged to this kitchen
    $stmt = $pdo->prepare("SELECT * FROM products WHERE company_id = ? AND client_id = ? AND kitchen_id = ? AND deleted_at IS NULL ORDER BY name");
    $stmt->execute([$company_id, $client_id, $dept_id]);
    $kitchen_catalog_products = $stmt->fetchAll();
    
    // Load recipe ingredients for all kitchen products
    $recipe_ingredients = [];
    $product_ids = array_column($kitchen_catalog_products, 'id');
    if (!empty($product_ids)) {
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT kr.*, p.name as ingredient_name, p.sku as ingredient_sku, p.unit as ingredient_unit,
                   p.unit_cost as catalog_cost,
                   (SELECT sd.unit_cost FROM supplier_deliveries sd 
                    WHERE sd.product_id = kr.ingredient_product_id AND sd.company_id = ? AND sd.client_id = ?
                    ORDER BY sd.delivery_date DESC, sd.id DESC LIMIT 1) as supplier_cost,
                   (SELECT sd.delivery_date FROM supplier_deliveries sd 
                    WHERE sd.product_id = kr.ingredient_product_id AND sd.company_id = ? AND sd.client_id = ?
                    ORDER BY sd.delivery_date DESC, sd.id DESC LIMIT 1) as supplier_date,
                   p.updated_at as catalog_date
            FROM kitchen_recipes kr
            JOIN products p ON p.id = kr.ingredient_product_id
            WHERE kr.product_id IN ($placeholders) AND kr.company_id = ? AND kr.client_id = ?
            ORDER BY p.name
        ");
        $params = [$company_id, $client_id, $company_id, $client_id];
        $params = array_merge($params, $product_ids, [$company_id, $client_id]);
        $stmt->execute($params);
        $all_ingredients = $stmt->fetchAll();
        
        // Group by product_id and pick latest cost
        foreach ($all_ingredients as &$ing) {
            $sup_cost = floatval($ing['supplier_cost'] ?? 0);
            $cat_cost = floatval($ing['catalog_cost'] ?? 0);
            $sup_date = $ing['supplier_date'] ?? '1970-01-01';
            $cat_date = $ing['catalog_date'] ?? '1970-01-01';
            $ing['latest_cost'] = ($sup_date >= $cat_date && $sup_cost > 0) ? $sup_cost : $cat_cost;
            $recipe_ingredients[$ing['product_id']][] = $ing;
        }
        unset($ing);
    }
    
    // Load raw materials available in this kitchen (for ingredient picker)
    $raw_materials = [];
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.id, p.name, p.sku, p.category, p.unit, p.unit_cost,
               (SELECT sd.unit_cost FROM supplier_deliveries sd 
                WHERE sd.product_id = p.id AND sd.company_id = ? AND sd.client_id = ?
                ORDER BY sd.delivery_date DESC, sd.id DESC LIMIT 1) as supplier_cost,
               (SELECT sd.delivery_date FROM supplier_deliveries sd 
                WHERE sd.product_id = p.id AND sd.company_id = ? AND sd.client_id = ?
                ORDER BY sd.delivery_date DESC, sd.id DESC LIMIT 1) as supplier_date,
               p.updated_at as catalog_date
        FROM products p
        JOIN department_stock ds ON ds.product_id = p.id AND ds.department_id = ? AND ds.company_id = ? AND ds.client_id = ?
        WHERE p.deleted_at IS NULL AND p.kitchen_id IS NULL
        ORDER BY p.name
    ");
    $stmt->execute([$company_id, $client_id, $company_id, $client_id, $dept_id, $company_id, $client_id]);
    $raw_materials = $stmt->fetchAll();
    foreach ($raw_materials as &$rm) {
        $sup_cost = floatval($rm['supplier_cost'] ?? 0);
        $cat_cost = floatval($rm['unit_cost'] ?? 0);
        $sup_date = $rm['supplier_date'] ?? '1970-01-01';
        $cat_date = $rm['catalog_date'] ?? '1970-01-01';
        $rm['latest_cost'] = ($sup_date >= $cat_date && $sup_cost > 0) ? $sup_cost : $cat_cost;
    }
    unset($rm);

    // Load issue history for reconciliation (from stock_movements)
    $issue_history = [];
    if (!empty($product_ids)) {
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $params = array_merge([$company_id, $client_id], $product_ids);
        $stmt = $pdo->prepare("
            SELECT sm.product_id, p.name as product_name, p.selling_price, p.unit_cost, 
                   sm.quantity, sm.notes, sm.created_at,
                   sm.reference_type
            FROM stock_movements sm
            JOIN products p ON p.id = sm.product_id
            WHERE sm.company_id = ? AND sm.client_id = ? 
              AND sm.product_id IN ($placeholders)
              AND sm.reference_type = 'kitchen_issue'
            ORDER BY sm.created_at DESC
        ");
        $stmt->execute($params);
        $issue_history = $stmt->fetchAll();
    }

    // Load departments that are NOT this kitchen (for issue destination)
    $stmt = $pdo->prepare("SELECT id, name, type FROM stock_departments WHERE company_id = ? AND client_id = ? AND id != ? AND deleted_at IS NULL ORDER BY name");
    $stmt->execute([$company_id, $client_id, $dept_id]);
    $issue_destinations = $stmt->fetchAll();

    // Load reconciliation: ALL product+destination combos ever issued, with today's data + prior_balance
    // This mirrors the main inventory pattern: products persist across dates, prior_balance = opening
    $reconciliation_data = [];
    if (!empty($product_ids)) {
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        // Step 1: find all unique (product_id, department_id) combos that were ever in a non-kitchen dept
        $combo_params = array_merge([$company_id, $client_id], $product_ids, [$dept_id]);
        $stmt = $pdo->prepare("
            SELECT DISTINCT ds.product_id, ds.department_id
            FROM department_stock ds
            WHERE ds.company_id = ? AND ds.client_id = ?
              AND ds.product_id IN ($placeholders)
              AND ds.department_id != ?
        ");
        $stmt->execute($combo_params);
        $combos = $stmt->fetchAll();

        foreach ($combos as $combo) {
            $pid = $combo['product_id'];
            $did = $combo['department_id'];

            // prior_balance = SUM of all activity before today
            $pb = $pdo->prepare("
                SELECT COALESCE(SUM(
                    COALESCE(d2.opening_stock,0) + COALESCE(d2.added,0) + COALESCE(d2.return_in,0) 
                    - COALESCE(d2.transfer_out,0) - COALESCE(d2.transfer_to_main,0) - COALESCE(d2.qty_sold,0)
                ), 0) as prior_bal
                FROM department_stock d2
                WHERE d2.department_id = ? AND d2.product_id = ? AND d2.stock_date < ?
                  AND d2.company_id = ? AND d2.client_id = ?
            ");
            $pb->execute([$did, $pid, $stock_date, $company_id, $client_id]);
            $prior_balance = (int)$pb->fetchColumn();

            // Today's row (may not exist)
            $today = $pdo->prepare("
                SELECT COALESCE(ds.added,0) as issued, COALESCE(ds.qty_sold,0) as sold,
                       COALESCE(ds.transfer_out,0) + COALESCE(ds.transfer_to_main,0) as returned,
                       COALESCE(ds.return_in,0) as return_in
                FROM department_stock ds
                WHERE ds.department_id = ? AND ds.product_id = ? AND ds.stock_date = ?
                  AND ds.company_id = ? AND ds.client_id = ?
            ");
            $today->execute([$did, $pid, $stock_date, $company_id, $client_id]);
            $t = $today->fetch();

            $issued   = $t ? (int)$t['issued'] : 0;
            $sold     = $t ? (int)$t['sold'] : 0;
            $returned = $t ? (int)$t['returned'] : 0;
            $return_in = $t ? (int)$t['return_in'] : 0;
            $opening  = $prior_balance;
            $closing  = $opening + $issued + $return_in - $returned - $sold;

            // Skip rows where opening=0 and no activity today (product was fully returned/sold previously)
            if ($opening == 0 && $issued == 0 && $sold == 0 && $returned == 0) continue;

            // Get dept name and product info
            $dept_nm = $pdo->prepare("SELECT name FROM stock_departments WHERE id = ?");
            $dept_nm->execute([$did]);
            $prod_nm = $pdo->prepare("SELECT name, selling_price, unit_cost FROM products WHERE id = ?");
            $prod_nm->execute([$pid]);
            $pinfo = $prod_nm->fetch();

            $reconciliation_data[] = [
                'product_id' => $pid,
                'department_id' => $did,
                'dept_name' => $dept_nm->fetchColumn() ?: "Dept #$did",
                'product_name' => $pinfo['name'] ?? '',
                'selling_price' => $pinfo['selling_price'] ?? 0,
                'unit_cost' => $pinfo['unit_cost'] ?? 0,
                'opening' => $opening,
                'issued' => $issued,
                'sold' => $sold,
                'returned' => $returned,
                'closing' => $closing,
                'stock_date' => $stock_date,
            ];
        }
        // Sort by product name, then dept name
        usort($reconciliation_data, function($a, $b) {
            return strcmp($a['product_name'], $b['product_name']) ?: strcmp($a['dept_name'], $b['dept_name']);
        });
    }
}
$js_kitchen_catalog = json_encode($kitchen_catalog_products, JSON_HEX_TAG | JSON_HEX_APOS);
$js_recipe_ingredients = json_encode($recipe_ingredients ?? [], JSON_HEX_TAG | JSON_HEX_APOS);
$js_raw_materials = json_encode($raw_materials ?? [], JSON_HEX_TAG | JSON_HEX_APOS);
$js_issue_destinations = json_encode($issue_destinations ?? [], JSON_HEX_TAG | JSON_HEX_APOS);
$js_reconciliation = json_encode($reconciliation_data ?? [], JSON_HEX_TAG | JSON_HEX_APOS);

// Load Daily Audit sales for linked outlet (reconciliation)
$outlet_sales = [];
$outlet_sales_total = 0;
$outlet_id = intval($dept['outlet_id'] ?? 0);
if ($outlet_id) {
    $stmt = $pdo->prepare("SELECT id, transaction_date, shift, 
        COALESCE(pos_amount,0) as pos_amount, 
        COALESCE(cash_amount,0) as cash_amount, 
        COALESCE(transfer_amount,0) as transfer_amount, 
        COALESCE(declared_total,0) as declared_total,
        (COALESCE(pos_amount,0) + COALESCE(cash_amount,0) + COALESCE(transfer_amount,0)) as actual_total,
        notes
        FROM sales_transactions 
        WHERE company_id = ? AND client_id = ? AND outlet_id = ? AND transaction_date = ? AND deleted_at IS NULL 
        ORDER BY shift, id");
    $stmt->execute([$company_id, $client_id, $outlet_id, $stock_date]);
    $outlet_sales = $stmt->fetchAll();
    $outlet_sales_total = array_sum(array_column($outlet_sales, 'actual_total'));
}
$js_outlet_sales = json_encode($outlet_sales, JSON_HEX_TAG | JSON_HEX_APOS);

// Load categories for catalog form
$stmt = $pdo->prepare("SELECT DISTINCT category FROM products WHERE company_id = ? AND client_id = ? AND category IS NOT NULL AND category != '' AND deleted_at IS NULL ORDER BY category");
$stmt->execute([$company_id, $client_id]);
$product_categories = array_column($stmt->fetchAll(), 'category');
$js_product_categories = json_encode($product_categories, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($dept['name']); ?> — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>[x-cloak]{display:none!important}.glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95) 0%,rgba(249,250,251,0.9) 100%);backdrop-filter:blur(20px)}.dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95) 0%,rgba(30,41,59,0.9) 100%)}</style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="deptStoreApp()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8">
            <?php display_flash_message(); ?>

            <!-- Page Header -->
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-4">
                    <a href="stock.php" class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                        <i data-lucide="arrow-left" class="w-5 h-5 text-slate-600"></i>
                    </a>
                    <div>
                        <?php if ($parent_dept): ?>
                        <div class="flex items-center gap-1.5 text-[11px] text-slate-400 mb-0.5">
                            <a href="department_store.php?dept_id=<?= $parent_dept['id'] ?>" class="hover:text-indigo-500 transition-colors"><?= htmlspecialchars($parent_dept['name']) ?></a>
                            <i data-lucide="chevron-right" class="w-3 h-3"></i>
                            <span class="text-slate-600 dark:text-slate-300 font-semibold"><?= htmlspecialchars($dept['name']) ?></span>
                        </div>
                        <?php endif; ?>
                        <h2 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($dept['name']); ?></h2>
                        <p class="text-xs text-slate-500">
                            <?php if ($dept_type === 'kitchen'): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 text-[10px] font-bold mr-1">🍳 Shared Kitchen</span>
                                Receives from Main Store — Supplies finished goods to Restaurant outlets
                            <?php elseif ($dept_type === 'shisha'): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300 text-[10px] font-bold mr-1">🌬️ Shisha Lounge</span>
                                Receives tobacco, charcoal & accessories from Main Store — Tracks ingredient usage via recipes
                            <?php elseif ($dept_type === 'cocktail'): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-pink-100 dark:bg-pink-900/30 text-pink-700 dark:text-pink-300 text-[10px] font-bold mr-1">🍹 Cocktail Bar</span>
                                Receives spirits, mixers & garnishes from Main Store — Tracks ingredient usage via recipes
                            <?php else: ?>
                                Department inventory — linked to <span class="font-semibold text-indigo-600"><?php echo htmlspecialchars($dept['outlet_name'] ?? 'N/A'); ?></span>
                                <?php if ($is_restaurant): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 text-[10px] font-bold ml-1">🍽️ Restaurant</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
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

            <div x-show="showCountBanner && countedProducts.length < stock.length"
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
                    <span x-text="countedProducts.length + ' / ' + stock.length"></span>
                    <span class="bg-white/20 rounded px-1.5 py-0.5 text-[10px]" x-text="stock.length ? Math.round(countedProducts.length / stock.length * 100) + '%' : '0%'"></span>
                </div>
            </div>

            <!-- Quick Navigation: All Stores & Departments -->
            <div class="flex items-center gap-2 mb-6 overflow-x-auto pb-1" style="scrollbar-width:thin">
                <!-- Main Store link -->
                <a href="main_store.php" class="flex-shrink-0 inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-bold bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:bg-emerald-50 hover:text-emerald-600 hover:border-emerald-300 dark:hover:bg-emerald-900/20 dark:hover:text-emerald-400 transition-all shadow-sm">
                    <i data-lucide="warehouse" class="w-3.5 h-3.5"></i> Main Store
                </a>
                <!-- All departments -->
                <?php foreach ($all_departments as $nav_dept): ?>
                    <?php if ($nav_dept['id'] == $dept_id): ?>
                        <span class="flex-shrink-0 inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-bold bg-gradient-to-r from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30">
                            <i data-lucide="store" class="w-3.5 h-3.5"></i> <?php echo htmlspecialchars($nav_dept['name']); ?>
                        </span>
                    <?php else: ?>
                        <a href="department_store.php?dept_id=<?php echo $nav_dept['id']; ?>" class="flex-shrink-0 inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-bold bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-300 dark:hover:bg-indigo-900/20 dark:hover:text-indigo-400 transition-all shadow-sm">
                            <i data-lucide="store" class="w-3.5 h-3.5"></i> <?php echo htmlspecialchars($nav_dept['name']); ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- KPI Strip -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Products</p>
                    <p class="text-xl font-black text-slate-800 dark:text-white" x-text="stock.length"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Added</p>
                    <p class="text-xl font-black text-blue-600" x-text="stock.reduce((s,r)=>s+parseInt(r.added||0),0)"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60" x-show="!isKitchen">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Sold</p>
                    <p class="text-xl font-black text-emerald-600" x-text="stock.reduce((s,r)=>s+parseInt(r.qty_sold||0),0)"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60" x-show="isKitchen">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Issued to Restaurants</p>
                    <p class="text-xl font-black text-amber-600" x-text="stock.reduce((s,r)=>s+parseInt(r.transfer_out||0),0)"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60" x-show="!isKitchen">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Revenue</p>
                    <p class="text-xl font-black text-amber-600" x-text="'₦'+stock.reduce((s,r)=>s+parseInt(r.qty_sold||0)*parseFloat(r.selling_price||0),0).toLocaleString()"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60" x-show="isKitchen">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Total Closing</p>
                    <p class="text-xl font-black text-emerald-600" x-text="stock.reduce((s,r)=>s+getClosing(r),0)"></p>
                </div>
                <div class="glass-card rounded-xl p-4 border border-slate-200/60 dark:border-slate-700/60 relative overflow-hidden">
                    <p class="text-[10px] font-bold uppercase text-slate-400 mb-1">Stock Counted</p>
                    <p class="text-xl font-black text-indigo-600">
                        <span x-text="countedProducts.length"></span>
                        <span class="text-sm font-semibold text-slate-400">/ <span x-text="stock.length"></span></span>
                    </p>
                    <!-- Progress bar -->
                    <div class="mt-2 w-full bg-slate-200 dark:bg-slate-700 rounded-full h-1.5">
                        <div class="bg-indigo-500 h-1.5 rounded-full transition-all duration-300" :style="'width:' + (stock.length ? Math.round(countedProducts.length / stock.length * 100) : 0) + '%'"></div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="mb-4 bg-white/50 dark:bg-slate-800/50 backdrop-blur-sm border-b border-slate-200 dark:border-slate-700">
                <nav class="-mb-px flex gap-6" aria-label="Tabs">
                    <button @click="activeTab = 'inventory'" :class="activeTab === 'inventory' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                        Inventory Overview
                    </button>
                    <template x-if="isKitchen">
                        <button @click="activeTab = 'catalog'" :class="activeTab === 'catalog' ? 'border-amber-500 text-amber-600 dark:text-amber-400' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                            <?= $recipe_emoji ?> <?= $recipe_label ?> Catalog
                        </button>
                    </template>
                    <button @click="activeTab = 'count'" :class="activeTab === 'count' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                        Stock Count
                    </button>
                    <button @click="activeTab = 'recon'" :class="activeTab === 'recon' ? 'border-purple-500 text-purple-600 dark:text-purple-400' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                        Reconciliation
                    </button>
                    <template x-if="isKitchen">
                        <button @click="activeTab = 'kitchen_recon'" :class="activeTab === 'kitchen_recon' ? 'border-purple-500 text-purple-600 dark:text-purple-400' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                            ⚖️ <?= $recipe_label ?> Reconciliation
                        </button>
                    </template>
                </nav>
            </div>

            <!-- Main Card -->
            <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br" :class="isKitchen ? 'from-amber-500 to-orange-600' : 'from-violet-500 to-purple-600'" style="display:flex;align-items:center;justify-content:center;" class="shadow-lg">
                            <i :data-lucide="isKitchen ? 'chef-hat' : 'building-2'" class="w-4 h-4 text-white"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="isKitchen ? '<?= $recipe_label ?> Inventory' : 'Department Inventory'"></h3>
                            <p class="text-[10px] text-slate-500" x-text="stock.length + ' products'"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <!-- Category Filter -->
                        <select x-model="categoryFilter" class="px-3 py-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs w-40 cursor-pointer">
                            <option value="">All Categories</option>
                            <template x-for="cat in uniqueCategories" :key="cat"><option :value="cat" x-text="cat"></option></template>
                        </select>
                        <input type="text" x-model="search" placeholder="Search..." class="px-3 py-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs w-40">
                        <button @click="showAddModal = true" class="px-4 py-2 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl text-xs hover:scale-105 transition-all shadow-lg flex items-center gap-1.5">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Product
                        </button>
                    </div>
                </div>

                <!-- Inventory Table -->
                <div x-show="activeTab === 'inventory'" class="overflow-x-auto max-h-[600px] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                            <tr>
                                <th class="px-3 py-3 text-center text-[10px] font-bold text-slate-400 w-10">#</th>
                                <th class="px-3 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">Product</th>
                                <th class="px-3 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">SKU</th>
                                <th class="px-3 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">Category</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-slate-500 uppercase">Opening</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-blue-500 uppercase">Added</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-emerald-500 uppercase">Adj(+)</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-red-500 uppercase">Adj(-)</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-cyan-500 uppercase" x-show="!isKitchen">Inter-Dep TRF</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-slate-700 dark:text-slate-300 uppercase">Total</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-rose-500 uppercase" x-show="!isKitchen">Dept TRF Out</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-amber-500 uppercase" x-show="isKitchen">Issued to Rest.</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-orange-500 uppercase">Store TRF Out</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-amber-600 uppercase" x-show="!isKitchen">Qty Sold</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-emerald-600 uppercase">Closing</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-indigo-600 uppercase" x-show="!isKitchen">Selling Price</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-purple-600 uppercase" x-show="!isKitchen">Amount</th>
                                <th class="px-3 py-3 text-center text-[10px] font-bold text-slate-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <template x-for="group in groupedStock" :key="group.category">
                        <tbody>
                            <tr @click="toggleGroup(group.category)" class="bg-gradient-to-r from-slate-100 to-slate-50 dark:from-slate-800 dark:to-slate-800/50 cursor-pointer hover:from-slate-200 hover:to-slate-100 dark:hover:from-slate-700 dark:hover:to-slate-700/50 transition-all">
                                <td colspan="15" class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="chevron-down" class="w-4 h-4 text-slate-500 transition-transform duration-200" :class="isExpanded(group.category) ? 'rotate-0' : '-rotate-90'"></i>
                                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                        <span class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider" x-text="group.category"></span>
                                        <span class="text-[10px] text-slate-400 font-medium ml-1" x-text="'(' + group.items.length + ' items)'"></span>
                                    </div>
                                </td>
                            </tr>
                            <template x-for="r in group.items" :key="r.product_id">
                                <tr x-show="isExpanded(group.category)" x-transition class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                    <td class="px-3 py-3 text-center text-xs text-slate-400 font-mono" x-text="r._sn"></td>
                                    <td class="px-3 py-3 font-semibold text-sm">
                                        <template x-if="r.parent_product_id && parseInt(r.parent_product_id) > 0">
                                            <span x-html="(() => { const su = (r.selling_unit || '').charAt(0).toUpperCase() + (r.selling_unit || '').slice(1); const idx = r.product_name.lastIndexOf(' ' + su); if (idx > 0) return r.product_name.substring(0, idx) + ' <span class=\'text-red-500 font-bold\'>' + su + '</span>'; return r.product_name; })()"></span>
                                        </template>
                                        <template x-if="!r.parent_product_id || parseInt(r.parent_product_id) === 0">
                                            <span x-text="r.product_name"></span>
                                        </template>
                                    </td>
                                    <td class="px-3 py-3 font-mono text-xs text-slate-500" x-text="r.sku || '—'"></td>
                                    <td class="px-3 py-3 text-xs text-slate-500" x-text="r.category || '—'"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm" x-text="int(r.opening_stock)"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm text-blue-600" x-text="int(r.added)"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm text-emerald-600" x-text="int(r.adjustment_add)" :class="int(r.adjustment_add) > 0 ? 'font-bold' : ''"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm text-red-600" x-text="int(r.adjustment_sub)" :class="int(r.adjustment_sub) > 0 ? 'font-bold' : ''"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm text-cyan-600" x-show="!isKitchen" x-text="int(r.return_in)"></td>
                                    <td class="px-3 py-3 text-right font-bold text-sm" x-text="getTotal(r)"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm text-rose-600" x-show="!isKitchen" x-text="int(r.transfer_out)"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm text-amber-600 font-bold" x-show="isKitchen" x-text="int(r.transfer_out)"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm text-orange-600" x-text="int(r.transfer_to_main)"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm text-amber-600" x-show="!isKitchen" x-text="int(r.qty_sold)"></td>
                                    <td class="px-3 py-3 text-right font-bold text-sm" :class="getClosing(r) <= 0 ? 'text-red-600' : 'text-emerald-600'" x-text="getClosing(r)"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm text-indigo-600" x-show="!isKitchen" x-text="'₦'+parseFloat(r.selling_price||0).toLocaleString()"></td>
                                    <td class="px-3 py-3 text-right font-bold text-sm text-purple-600" x-show="!isKitchen" x-text="'₦'+(int(r.qty_sold)*parseFloat(r.selling_price||0)).toLocaleString()"></td>
                                    <td class="px-3 py-3 text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            <button @click.stop="openEdit(r)" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all shadow-sm" title="Update">
                                                <i data-lucide="pencil" class="w-3 h-3"></i> Update
                                            </button>
                                            <button @click.stop="openAdjustment(r)" class="inline-flex items-center gap-1 px-2 py-1.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all shadow-sm" title="Adjust Stock">
                                                <i data-lucide="plus-minus" class="w-3 h-3"></i>
                                            </button>
                                            <button @click.stop="removeProduct(r)" class="p-1.5 text-red-500 hover:bg-red-50 rounded-lg transition-colors" title="Remove from Department">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        </template>
                        <tbody x-show="filteredStock.length === 0">
                            <tr>
                                <td colspan="15" class="px-4 py-12 text-center text-slate-400">No products in this department. Click "Add Product" to start.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Stock Count Table -->
                <div x-show="activeTab === 'count'" class="overflow-x-auto max-h-[600px] overflow-y-auto" style="display: none;">
                    <div class="p-4 bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-200 text-xs border-b border-amber-100 dark:border-amber-800">
                        <div class="flex justify-between items-center">
                            <p><i data-lucide="alert-triangle" class="w-3 h-3 inline mr-1"></i> Enter <strong>Physical Closing Stock</strong>. The system will automatically calculate <template x-if="!isKitchen"><span><strong>Qty Sold</strong> and <strong>Revenue</strong></span></template><template x-if="isKitchen"><span><strong>Issued Qty</strong></span></template>.</p>
                            <button @click="showCountGuide = !showCountGuide" class="underline hover:text-amber-900 whitespace-nowrap ml-4 flex items-center gap-1">
                                <i data-lucide="help-circle" class="w-3 h-3"></i> <span x-text="showCountGuide ? 'Hide Guide' : 'Stock Count Guide'"></span>
                            </button>
                        </div>
                        <div x-show="showCountGuide" x-transition class="mt-3 bg-white dark:bg-slate-800 rounded-lg p-4 border border-amber-200 dark:border-amber-700 text-[11px] text-slate-700 dark:text-slate-300 space-y-3">
                            <p class="font-bold text-sm text-slate-800 dark:text-slate-100 flex items-center gap-1.5"><i data-lucide="book-open" class="w-4 h-4 text-amber-600"></i> Stock Count Guide</p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 border border-blue-100 dark:border-blue-800">
                                    <p class="font-bold text-blue-700 dark:text-blue-300 mb-1">📋 How It Works</p>
                                    <ol class="list-decimal list-inside space-y-1 text-blue-800 dark:text-blue-200">
                                        <li><strong>System Total</strong> = Opening + Added + Returns − Transfers</li>
                                        <li><strong>Adj</strong> column shows any adjustments applied</li>
                                        <li>Enter the <strong>Physical Count</strong> from the shelf</li>
                                        <li>Click <strong>Count</strong> to save (or <strong>Update</strong> to re-save)</li>
                                        <li><strong>Calc. Sold</strong> = (System + Adj) − Physical Count</li>
                                    </ol>
                                </div>
                                <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-lg p-3 border border-emerald-100 dark:border-emerald-800">
                                    <p class="font-bold text-emerald-700 dark:text-emerald-300 mb-1">📦 Excess Stock Discovered?</p>
                                    <p class="mb-1">If physical count is <strong>higher</strong> than system total (positive variance), you have two options:</p>
                                    <ul class="space-y-1">
                                        <li class="flex items-start gap-1"><span class="text-emerald-600 font-bold">✓</span> <strong>Leave on shelf</strong> — simply enter the real count and save. The system records the surplus.</li>
                                        <li class="flex items-start gap-1"><span class="text-amber-600 font-bold">±</span> <strong>Adjust & Transfer</strong> — go to the <em>Inventory</em> tab, use the <strong>± Adjust</strong> button to add the excess back, then use <strong>Transfer to Main</strong> to return it to the store.</li>
                                    </ul>
                                </div>
                                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3 border border-red-100 dark:border-red-800">
                                    <p class="font-bold text-red-700 dark:text-red-300 mb-1">⚠️ Shortage / Missing Stock?</p>
                                    <p>If physical count is <strong>lower</strong> than expected (negative variance), enter the real count. The difference is recorded as <strong>Qty Sold</strong>. If it's not a sale (e.g. damage, theft), use the <strong>± Adjust</strong> button in the <em>Inventory</em> tab to subtract and log the reason.</p>
                                </div>
                                <div class="bg-violet-50 dark:bg-violet-900/20 rounded-lg p-3 border border-violet-100 dark:border-violet-800">
                                    <p class="font-bold text-violet-700 dark:text-violet-300 mb-1">💡 Tips</p>
                                    <ul class="space-y-1">
                                        <li>• Count <strong>before</strong> any new stock is issued for the day</li>
                                        <li>• Use <strong>Update</strong> to correct a previously saved count</li>
                                        <li>• Adjustments show in the <strong>Adj</strong> column automatically</li>
                                        <li>• Revenue is calculated using the product's selling price</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                            <tr>
                                <th class="px-3 py-3 text-center text-[10px] font-bold text-slate-400 w-10">#</th>
                                <th class="px-3 py-3 text-left text-[10px] font-bold text-slate-500 uppercase">Product</th>
                                <th class="px-3 py-3 text-center text-[10px] font-bold text-slate-500 uppercase bg-slate-100/50">System Total</th>
                                <th class="px-3 py-3 text-center text-[10px] font-bold text-amber-600 uppercase bg-amber-50/50">Adj</th>
                                <th class="px-3 py-3 text-center text-[10px] font-bold text-emerald-600 uppercase bg-emerald-50/50 w-32">Physical Closing</th>
                                <th class="px-3 py-3 text-center text-[10px] font-bold text-blue-600 uppercase bg-blue-50/50" x-text="isKitchen ? 'Calc. Issued' : 'Calc. Sold'">Calc. Sold</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-slate-500 uppercase" x-show="!isKitchen">Price</th>
                                <th class="px-3 py-3 text-right text-[10px] font-bold text-slate-500 uppercase" x-show="!isKitchen">Revenue</th>
                                <th class="px-3 py-3 text-center text-[10px] font-bold text-slate-500 uppercase w-20">Action</th>
                            </tr>
                        </thead>
                        <template x-for="group in groupedStock" :key="group.category + '_count'">
                        <tbody>
                            <tr @click="toggleGroup(group.category)" class="bg-gradient-to-r from-slate-100 to-slate-50 dark:from-slate-800 dark:to-slate-800/50 cursor-pointer hover:from-slate-200 hover:to-slate-100 dark:hover:from-slate-700 dark:hover:to-slate-700/50 transition-all">
                                <td colspan="9" class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="chevron-down" class="w-4 h-4 text-slate-500 transition-transform duration-200" :class="isExpanded(group.category) ? 'rotate-0' : '-rotate-90'"></i>
                                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                        <span class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider" x-text="group.category"></span>
                                        <span class="text-[10px] text-slate-400 font-medium ml-1" x-text="'(' + group.items.length + ' items)'"></span>
                                    </div>
                                </td>
                            </tr>
                            <template x-for="r in group.items" :key="r.product_id + '_count'">
                                <tr x-show="isExpanded(group.category)" x-transition class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors" :class="{'bg-green-50/30': r.qty_sold > 0}">
                                    <td class="px-3 py-3 text-center text-xs text-slate-400 font-mono" x-text="r._sn"></td>
                                    <td class="px-3 py-3 font-semibold text-sm">
                                        <div>
                                            <template x-if="r.parent_product_id && parseInt(r.parent_product_id) > 0">
                                                <span x-html="(() => { const su = (r.selling_unit || '').charAt(0).toUpperCase() + (r.selling_unit || '').slice(1); const idx = r.product_name.lastIndexOf(' ' + su); if (idx > 0) return r.product_name.substring(0, idx) + ' <span class=\'text-red-500 font-bold\'>' + su + '</span>'; return r.product_name; })()"></span>
                                            </template>
                                            <template x-if="!r.parent_product_id || parseInt(r.parent_product_id) === 0">
                                                <span x-text="r.product_name"></span>
                                            </template>
                                        </div>
                                        <div class="text-[10px] text-slate-400" x-text="r.sku || '—'"></div>
                                    </td>
                                    <td class="px-3 py-3 text-center font-mono text-sm font-bold text-slate-600" x-text="getSystemTotal(r)"></td>
                                    <td class="px-3 py-3 text-center font-mono text-sm font-bold"
                                        :class="getNetAdjustment(r) < 0 ? 'text-red-600' : (getNetAdjustment(r) > 0 ? 'text-emerald-600' : 'text-slate-400')"
                                        x-text="getNetAdjustment(r) > 0 ? '+' + getNetAdjustment(r) : getNetAdjustment(r)"></td>
                                    <td class="px-3 py-1 text-center">
                                        <input type="number" 
                                            :value="getPhysicalClosing(r)" 
                                            @input="setPhysicalClosing(r, $event.target.value); if (countedProducts.includes(r.product_id) && !dirtyProducts.includes(r.product_id)) dirtyProducts.push(r.product_id)"
                                            class="w-full text-center font-bold bg-white dark:bg-slate-900 border-2 rounded-lg shadow-sm focus:ring-1 transition-colors"
                                            :class="countedProducts.includes(r.product_id) 
                                                ? 'text-emerald-600 border-emerald-400 focus:border-emerald-500 focus:ring-emerald-500 bg-emerald-50/50' 
                                                : 'text-violet-600 border-violet-300 focus:border-violet-500 focus:ring-violet-500/40'"
                                        >
                                    </td>
                                    <td class="px-3 py-3 text-center font-mono text-sm font-bold text-blue-600" x-text="getCalculatedSold(r)"></td>
                                    <td class="px-3 py-3 text-right font-mono text-xs text-slate-500" x-show="!isKitchen" x-text="'₦'+parseFloat(r.selling_price||0).toLocaleString()"></td>
                                    <td class="px-3 py-3 text-right font-mono text-sm font-bold text-slate-700 dark:text-slate-300" x-show="!isKitchen" x-text="'₦'+getCalculatedRevenue(r).toLocaleString()"></td>
                                    <td class="px-3 py-3 text-center">
                                        <!-- Not counted yet: purple Count button -->
                                        <template x-if="!countedProducts.includes(r.product_id)">
                                            <button @click="saveCount(r)"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all shadow-sm bg-gradient-to-r from-violet-500 to-purple-600">
                                                <i data-lucide="check" class="w-3 h-3"></i>
                                                <span>Count</span>
                                            </button>
                                        </template>
                                        <!-- Counted & not edited: green Done badge -->
                                        <template x-if="countedProducts.includes(r.product_id) && !dirtyProducts.includes(r.product_id)">
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 text-[10px] font-bold rounded-full">
                                                <i data-lucide="check-circle" class="w-3 h-3"></i> Done
                                            </span>
                                        </template>
                                        <!-- Counted but user edited: green Update button -->
                                        <template x-if="countedProducts.includes(r.product_id) && dirtyProducts.includes(r.product_id)">
                                            <button @click="saveCount(r)"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all shadow-sm bg-gradient-to-r from-emerald-500 to-green-600">
                                                <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                                                <span>Update</span>
                                            </button>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        </template>
                    </table>
                </div>

                <!-- Totals Row -->
                <div class="px-6 py-3 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500">TOTALS</span>
                    <div class="flex items-center gap-6 text-xs font-bold">
                        <span>Added: <span class="text-blue-600" x-text="stock.reduce((s,r)=>s+int(r.added),0)"></span></span>
                        <template x-if="!isKitchen">
                            <span>Sold: <span class="text-amber-600" x-text="stock.reduce((s,r)=>s+int(r.qty_sold),0)"></span></span>
                        </template>
                        <template x-if="isKitchen">
                            <span>Issued: <span class="text-amber-600" x-text="stock.reduce((s,r)=>s+int(r.transfer_out),0)"></span></span>
                        </template>
                        <template x-if="!isKitchen">
                            <span>Revenue: <span class="text-purple-600" x-text="'₦'+stock.reduce((s,r)=>s+int(r.qty_sold)*parseFloat(r.selling_price||0),0).toLocaleString()"></span></span>
                        </template>
                        <template x-if="isKitchen">
                            <span>Closing: <span class="text-emerald-600" x-text="stock.reduce((s,r)=>s+getClosing(r),0)"></span></span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- ===== Reconciliation Tab ===== -->
            <div x-show="activeTab === 'recon'" class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden mt-6" style="display:none;">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-purple-500/10 via-indigo-500/5 to-transparent flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center">
                            <i data-lucide="scale" class="w-5 h-5 text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-800 dark:text-white">Stock Count Reconciliation</h3>
                            <p class="text-[11px] text-slate-400">Comparing stock count sales vs Daily Audit system sales — linked to <span class="font-semibold text-indigo-500"><?= htmlspecialchars($dept['outlet_name'] ?? 'N/A') ?></span></p>
                        </div>
                    </div>
                    <div class="px-3 py-1.5 bg-purple-50 dark:bg-purple-900/30 rounded-lg">
                        <span class="text-[10px] font-bold text-purple-500 uppercase">Date:</span>
                        <span class="text-sm font-bold text-purple-700" x-text="stockDate"><?= $stock_date ?></span>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-6">
                    <!-- Stock Count Sales -->
                    <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-xl p-4 border border-emerald-200 dark:border-emerald-800">
                        <p class="text-[10px] font-bold uppercase text-emerald-500 mb-1">Stock Count Sales</p>
                        <p class="text-xl font-black text-emerald-700" x-text="'₦'+getStockCountSalesTotal().toLocaleString()"></p>
                        <p class="text-[10px] text-emerald-500 mt-1" x-text="getStockCountSoldQty() + ' items sold'"></p>
                    </div>
                    <!-- System Sales (Daily Audit) -->
                    <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4 border border-blue-200 dark:border-blue-800">
                        <p class="text-[10px] font-bold uppercase text-blue-500 mb-1">System Sales (Daily Audit)</p>
                        <p class="text-xl font-black text-blue-700" x-text="'₦'+getSystemSalesTotal().toLocaleString()"></p>
                        <p class="text-[10px] text-blue-500 mt-1" x-text="outletSales.length + ' transaction(s)'"></p>
                    </div>
                    <!-- Variance -->
                    <div class="rounded-xl p-4 border" :class="getReconVariance() === 0 ? 'bg-slate-50 dark:bg-slate-800/50 border-slate-200 dark:border-slate-700' : (getReconVariance() > 0 ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800')">
                        <p class="text-[10px] font-bold uppercase mb-1" :class="getReconVariance() === 0 ? 'text-slate-500' : (getReconVariance() > 0 ? 'text-amber-500' : 'text-red-500')">Variance</p>
                        <p class="text-xl font-black" :class="getReconVariance() === 0 ? 'text-slate-600' : (getReconVariance() > 0 ? 'text-amber-700' : 'text-red-700')" x-text="(getReconVariance() > 0 ? '+' : '') + '₦'+getReconVariance().toLocaleString()"></p>
                        <p class="text-[10px] mt-1" :class="getReconVariance() === 0 ? 'text-slate-400' : (getReconVariance() > 0 ? 'text-amber-500' : 'text-red-500')" x-text="getReconVariance() === 0 ? 'Balanced' : (getReconVariance() > 0 ? 'System higher than stock count' : 'Stock count higher than system')"></p>
                    </div>
                    <!-- Status -->
                    <div class="rounded-xl p-4 border" :class="getReconStatus() === 'matched' ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800' : (getReconStatus() === 'variance' ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800' : 'bg-slate-50 dark:bg-slate-800/50 border-slate-200 dark:border-slate-700')">
                        <p class="text-[10px] font-bold uppercase mb-1" :class="getReconStatus() === 'matched' ? 'text-emerald-500' : (getReconStatus() === 'variance' ? 'text-amber-500' : 'text-slate-400')">Status</p>
                        <div class="flex items-center gap-2">
                            <template x-if="getReconStatus() === 'matched'">
                                <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-200 text-emerald-800 text-xs font-bold rounded-full"><i data-lucide="check-circle" class="w-4 h-4"></i> Matched</span>
                            </template>
                            <template x-if="getReconStatus() === 'variance'">
                                <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-amber-200 text-amber-800 text-xs font-bold rounded-full"><i data-lucide="alert-triangle" class="w-4 h-4"></i> Variance</span>
                            </template>
                            <template x-if="getReconStatus() === 'incomplete'">
                                <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-200 text-slate-600 text-xs font-bold rounded-full"><i data-lucide="clock" class="w-4 h-4"></i> Incomplete</span>
                            </template>
                            <template x-if="getReconStatus() === 'no_audit'">
                                <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-red-100 text-red-600 text-xs font-bold rounded-full"><i data-lucide="x-circle" class="w-4 h-4"></i> No Audit Entry</span>
                            </template>
                        </div>
                        <p class="text-[10px] mt-1.5" :class="getReconStatus() === 'matched' ? 'text-emerald-500' : (getReconStatus() === 'variance' ? 'text-amber-500' : 'text-slate-400')" x-text="countedProducts.length + '/' + stock.length + ' products counted'"></p>
                    </div>
                </div>

                <!-- Per-Product Breakdown -->
                <div class="px-6 pb-2">
                    <h4 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
                        <i data-lucide="package" class="w-4 h-4 text-purple-500"></i> Stock Count — Per Product Breakdown
                    </h4>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/60">
                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase text-slate-400">#</th>
                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase text-slate-500">Product</th>
                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase text-blue-500">System Total</th>
                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase text-amber-500">Adj</th>
                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase text-emerald-500">Physical Count</th>
                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase text-purple-500">Qty Sold</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase text-slate-500">Unit Price</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase text-emerald-600">Sales Value</th>
                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase text-slate-400">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(r, idx) in stock" :key="r.product_id">
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors border-t border-slate-100 dark:border-slate-800">
                                    <td class="px-4 py-2.5 text-center text-xs text-slate-400" x-text="idx+1"></td>
                                    <td class="px-4 py-2.5">
                                        <p class="font-semibold text-slate-700 dark:text-slate-200" x-text="r.product_name"></p>
                                    </td>
                                    <td class="px-4 py-2.5 text-center font-bold text-blue-600" x-text="getSystemTotal(r)"></td>
                                    <td class="px-4 py-2.5 text-center font-bold" :class="getNetAdjustment(r) > 0 ? 'text-emerald-600' : (getNetAdjustment(r) < 0 ? 'text-red-600' : 'text-slate-400')" x-text="getNetAdjustment(r) !== 0 ? (getNetAdjustment(r) > 0 ? '+'+getNetAdjustment(r) : getNetAdjustment(r)) : '—'"></td>
                                    <td class="px-4 py-2.5 text-center font-bold" :class="countedProducts.includes(r.product_id) ? 'text-emerald-600' : 'text-slate-400'" x-text="countedProducts.includes(r.product_id) ? getPhysicalClosing(r) : '—'"></td>
                                    <td class="px-4 py-2.5 text-center font-bold text-purple-600" x-text="countedProducts.includes(r.product_id) ? getCalculatedSold(r) : '—'"></td>
                                    <td class="px-4 py-2.5 text-right text-xs text-slate-500" x-text="'₦'+parseFloat(r.selling_price||0).toLocaleString()"></td>
                                    <td class="px-4 py-2.5 text-right font-bold text-emerald-600" x-text="countedProducts.includes(r.product_id) ? '₦'+(getCalculatedSold(r) * parseFloat(r.selling_price||0)).toLocaleString() : '—'"></td>
                                    <td class="px-4 py-2.5 text-center">
                                        <template x-if="countedProducts.includes(r.product_id)">
                                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 bg-emerald-100 text-emerald-700 text-[9px] font-bold rounded-full"><i data-lucide="check" class="w-2.5 h-2.5"></i> Counted</span>
                                        </template>
                                        <template x-if="!countedProducts.includes(r.product_id)">
                                            <span class="inline-flex items-center gap-0.5 px-2 py-0.5 bg-slate-100 text-slate-500 text-[9px] font-bold rounded-full"><i data-lucide="clock" class="w-2.5 h-2.5"></i> Pending</span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                            <!-- Totals -->
                            <tr class="bg-slate-50 dark:bg-slate-800/60 font-bold border-t-2 border-slate-200 dark:border-slate-700">
                                <td colspan="5" class="px-4 py-3 text-right text-xs uppercase text-slate-500">TOTALS</td>
                                <td class="px-4 py-3 text-center text-purple-600" x-text="getStockCountSoldQty()"></td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3 text-right text-emerald-600" x-text="'₦'+getStockCountSalesTotal().toLocaleString()"></td>
                                <td class="px-4 py-3 text-center text-xs" x-text="countedProducts.length+'/'+stock.length"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Daily Audit Transactions -->
                <div class="px-6 pt-6 pb-2 border-t border-slate-200 dark:border-slate-700">
                    <h4 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
                        <i data-lucide="file-text" class="w-4 h-4 text-blue-500"></i> Daily Audit — System Sales for <span class="text-indigo-600"><?= htmlspecialchars($dept['outlet_name'] ?? 'N/A') ?></span>
                    </h4>
                </div>
                <div class="overflow-x-auto pb-4">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-blue-50/50 dark:bg-blue-900/10">
                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase text-slate-400">#</th>
                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase text-slate-500">Shift</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase text-blue-500">POS</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase text-emerald-500">Cash</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase text-purple-500">Transfer</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase text-amber-500">Declared</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase text-slate-700">System Sales</th>
                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase text-slate-400">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(s, idx) in outletSales" :key="s.id">
                                <tr class="hover:bg-blue-50/30 dark:hover:bg-blue-900/10 transition-colors border-t border-slate-100 dark:border-slate-800">
                                    <td class="px-4 py-2.5 text-center text-xs text-slate-400" x-text="idx+1"></td>
                                    <td class="px-4 py-2.5">
                                        <span class="px-2 py-0.5 bg-slate-100 dark:bg-slate-800 text-slate-600 text-[10px] font-bold rounded-full" x-text="s.shift.replace('_',' ').replace(/\b\w/g, l => l.toUpperCase())"></span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-bold text-blue-600" x-text="'₦'+parseFloat(s.pos_amount||0).toLocaleString()"></td>
                                    <td class="px-4 py-2.5 text-right font-bold text-emerald-600" x-text="'₦'+parseFloat(s.cash_amount||0).toLocaleString()"></td>
                                    <td class="px-4 py-2.5 text-right font-bold text-purple-600" x-text="'₦'+parseFloat(s.transfer_amount||0).toLocaleString()"></td>
                                    <td class="px-4 py-2.5 text-right font-bold text-amber-600" x-text="'₦'+parseFloat(s.actual_total||0).toLocaleString()"></td>
                                    <td class="px-4 py-2.5 text-right font-black text-slate-800 dark:text-white" x-text="'₦'+parseFloat(s.declared_total||0).toLocaleString()"></td>
                                    <td class="px-4 py-2.5 text-xs text-slate-400 max-w-[150px] truncate" x-text="s.notes || '—'"></td>
                                </tr>
                            </template>
                            <tr x-show="outletSales.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center text-slate-400">
                                    <i data-lucide="file-x" class="w-8 h-8 mx-auto mb-2 opacity-40"></i>
                                    <p>No Daily Audit sales recorded for <strong><?= htmlspecialchars($dept['outlet_name'] ?? 'this outlet') ?></strong> on this date.</p>
                                    <p class="text-[10px] mt-1">Go to <strong>Daily Audit</strong> to enter sales for this outlet.</p>
                                </td>
                            </tr>
                            <!-- Totals -->
                            <tr x-show="outletSales.length > 0" class="bg-blue-50/50 dark:bg-blue-900/10 font-bold border-t-2 border-slate-200 dark:border-slate-700">
                                <td colspan="2" class="px-4 py-3 text-right text-xs uppercase text-slate-500">TOTALS</td>
                                <td class="px-4 py-3 text-right text-blue-600" x-text="'₦'+outletSales.reduce((s,r) => s+parseFloat(r.pos_amount||0),0).toLocaleString()"></td>
                                <td class="px-4 py-3 text-right text-emerald-600" x-text="'₦'+outletSales.reduce((s,r) => s+parseFloat(r.cash_amount||0),0).toLocaleString()"></td>
                                <td class="px-4 py-3 text-right text-purple-600" x-text="'₦'+outletSales.reduce((s,r) => s+parseFloat(r.transfer_amount||0),0).toLocaleString()"></td>
                                <td class="px-4 py-3 text-right text-amber-600" x-text="'₦'+outletSales.reduce((s,r) => s+parseFloat(r.actual_total||0),0).toLocaleString()"></td>
                                <td class="px-4 py-3 text-right text-slate-800 dark:text-white" x-text="'₦'+outletSales.reduce((s,r) => s+parseFloat(r.declared_total||0),0).toLocaleString()"></td>
                                <td class="px-4 py-3"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== Kitchen Product Catalog with Recipe Builder ===== -->
            <div x-show="isKitchen && activeTab === 'catalog'" class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden" style="display:none;">
                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 via-orange-500/5 to-transparent flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg"><i data-lucide="chef-hat" class="w-4 h-4 text-white"></i></div>
                        <div>
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm"><?= $recipe_emoji ?> <?= $recipe_label ?> Product Catalog</h3>
                            <p class="text-[10px] text-slate-500">Create finished items from raw materials. Add ingredients to auto-calculate cost per plate.</p>
                        </div>
                    </div>
                </div>

                <!-- Create Product Form -->
                <form @submit.prevent="createKitchenProduct()" class="p-5 border-b border-slate-100 dark:border-slate-800">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <div>
                            <label class="text-[11px] font-semibold mb-1 block text-slate-500">Product Name *</label>
                            <input type="text" x-model="kitchenCatalogForm.name" required placeholder="e.g. Jollof Rice Plate" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold mb-1 block text-slate-500">Category *</label>
                            <select x-model="kitchenCatalogForm.category" required class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                <option value="">Select category...</option>
                                <template x-for="c in availableCategories" :key="c"><option :value="c" x-text="c"></option></template>
                                <option value="__new">+ New Category</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold mb-1 block text-slate-500">Unit</label>
                            <select x-model="kitchenCatalogForm.unit" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                <option value="plates">Plates</option>
                                <option value="portions">Portions</option>
                                <option value="pcs">Pieces</option>
                                <option value="bowls">Bowls</option>
                                <option value="cups">Cups</option>
                                <option value="wraps">Wraps</option>
                                <option value="kg">Kilograms</option>
                                <option value="litres">Litres</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold mb-1 block text-slate-500">Selling Price (₦) *</label>
                            <input type="number" step="0.01" x-model="kitchenCatalogForm.selling_price" required placeholder="0.00" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                        </div>
                    </div>
                    <template x-if="kitchenCatalogForm.category === '__new'">
                        <div class="mt-2">
                            <input type="text" x-model="kitchenCatalogForm.newCategory" placeholder="Enter new category name" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-amber-300 dark:border-amber-700 rounded-xl text-sm">
                        </div>
                    </template>
                    <div class="flex items-center justify-between mt-3">
                        <p class="text-[10px] text-slate-400">💡 After creating, click the recipe icon to add ingredients & auto-calculate cost</p>
                        <button type="submit" class="px-6 py-2 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl shadow-lg shadow-amber-500/30 hover:scale-[1.02] transition-all text-sm">
                            <i data-lucide="plus" class="w-4 h-4 inline mr-1"></i> Create Product
                        </button>
                    </div>
                </form>

                <!-- Kitchen Products Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                            <tr>
                                <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-400 w-8">#</th>
                                <th class="px-3 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Product Name</th>
                                <th class="px-3 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Category</th>
                                <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase">Ingredients</th>
                                <th class="px-3 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Cost/Plate</th>
                                <th class="px-3 py-2.5 text-right text-[10px] font-bold text-blue-500 uppercase">Sell Price</th>
                                <th class="px-3 py-2.5 text-right text-[10px] font-bold text-emerald-500 uppercase">Margin</th>
                                <th class="px-3 py-2.5 text-center text-[10px] font-bold text-purple-500 uppercase">Portions Avail</th>
                                <th class="px-3 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase w-28">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(p, idx) in kitchenCatalogProducts" :key="p.id">
                                <tr>
                                    <!-- Main Row -->
                                    <td class="px-3 py-2.5 text-center text-xs font-bold text-slate-400 border-t border-slate-100 dark:border-slate-800" x-text="idx + 1"></td>
                                    <td class="px-3 py-2.5 font-semibold text-slate-800 dark:text-white border-t border-slate-100 dark:border-slate-800" x-text="p.name"></td>
                                    <td class="px-3 py-2.5 border-t border-slate-100 dark:border-slate-800"><span class="px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 text-[9px] font-bold uppercase" x-text="p.category || '—'"></span></td>
                                    <td class="px-3 py-2.5 text-center border-t border-slate-100 dark:border-slate-800">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                            :class="(recipeIngredients[p.id]||[]).length > 0 ? 'bg-blue-100 text-blue-600' : 'bg-slate-100 text-slate-400'"
                                            x-text="(recipeIngredients[p.id]||[]).length + ' items'"></span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-mono text-xs border-t border-slate-100 dark:border-slate-800"
                                        :class="parseFloat(p.unit_cost||0) > 0 ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400'"
                                        x-text="'₦'+parseFloat(p.unit_cost||0).toLocaleString()"></td>
                                    <td class="px-3 py-2.5 text-right font-mono text-xs font-bold text-blue-600 border-t border-slate-100 dark:border-slate-800" x-text="'₦'+parseFloat(p.selling_price||0).toLocaleString()"></td>
                                    <td class="px-3 py-2.5 text-right font-mono text-xs font-bold border-t border-slate-100 dark:border-slate-800"
                                        :class="(parseFloat(p.selling_price||0) - parseFloat(p.unit_cost||0)) >= 0 ? 'text-emerald-600' : 'text-red-500'"
                                        x-text="'₦'+(parseFloat(p.selling_price||0) - parseFloat(p.unit_cost||0)).toLocaleString()"></td>
                                    <td class="px-3 py-2.5 text-center border-t border-slate-100 dark:border-slate-800">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                            :class="getPortionsAvailable(p.id) > 0 ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400'"
                                            x-text="getPortionsAvailable(p.id)"></span>
                                    </td>
                                    <td class="px-3 py-2.5 text-center border-t border-slate-100 dark:border-slate-800">
                                        <div class="flex items-center justify-center gap-1">
                                            <button @click="openRecipeModal(p)" class="inline-flex items-center gap-1 px-2 py-1 bg-gradient-to-r from-amber-500 to-orange-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all" title="Manage Recipe">
                                                <i data-lucide="book-open" class="w-3 h-3"></i> Recipe
                                            </button>
                                            <button @click="openIssueModal(p)" class="inline-flex items-center gap-1 px-2 py-1 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-[10px] font-bold rounded-lg hover:scale-105 transition-all" title="Issue to Restaurant">
                                                <i data-lucide="send" class="w-3 h-3"></i> Issue
                                            </button>
                                            <button @click="deleteKitchenProduct(p.id, p.name)" class="p-1 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 rounded-lg transition-all" title="Delete">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5 text-red-500"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="kitchenCatalogProducts.length === 0">
                                <td colspan="9" class="px-4 py-12 text-center text-slate-400">No kitchen products yet. Create your first finished item above.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== Issue to Restaurant Modal ===== -->
            <div x-show="issueModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="issueModal = false">
                <div x-show="issueModal" x-transition.scale.90 class="w-full max-w-md glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500 to-teal-600">
                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                            <i data-lucide="send" class="w-5 h-5"></i> Issue to Restaurant
                        </h3>
                        <p class="text-emerald-100 text-xs mt-0.5" x-text="issueProduct ? issueProduct.name : ''"></p>
                    </div>
                    <div class="p-6 space-y-4">
                        <!-- Product Info Summary -->
                        <div class="grid grid-cols-3 gap-3" x-show="issueProduct">
                            <div class="text-center p-2 bg-slate-50 dark:bg-slate-800/60 rounded-xl">
                                <p class="text-[9px] font-bold uppercase text-slate-400">Cost/Plate</p>
                                <p class="text-sm font-black text-slate-700 dark:text-slate-200" x-text="'₦' + parseFloat(issueProduct?.unit_cost || 0).toLocaleString()"></p>
                            </div>
                            <div class="text-center p-2 bg-slate-50 dark:bg-slate-800/60 rounded-xl">
                                <p class="text-[9px] font-bold uppercase text-slate-400">Sell Price</p>
                                <p class="text-sm font-black text-emerald-600" x-text="'₦' + parseFloat(issueProduct?.selling_price || 0).toLocaleString()"></p>
                            </div>
                            <div class="text-center p-2 bg-slate-50 dark:bg-slate-800/60 rounded-xl">
                                <p class="text-[9px] font-bold uppercase text-slate-400">Available</p>
                                <p class="text-sm font-black" :class="getPortionsAvailable(issueProduct?.id) > 0 ? 'text-blue-600' : 'text-red-500'" x-text="getPortionsAvailable(issueProduct?.id) + ' plates'"></p>
                            </div>
                        </div>

                        <!-- Destination -->
                        <div>
                            <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Destination Restaurant / Department *</label>
                            <select x-model="issueForm.destination_dept_id" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                <option value="">— Select Destination —</option>
                                <template x-for="d in issueDestinations" :key="d.id">
                                    <option :value="d.id" x-text="'🍽️ ' + d.name"></option>
                                </template>
                            </select>
                        </div>

                        <!-- Quantity -->
                        <div>
                            <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Quantity (Plates) *</label>
                            <input type="number" x-model.number="issueForm.quantity" min="1" :max="getPortionsAvailable(issueProduct?.id)" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm text-center text-lg font-bold">
                            <p class="text-[10px] mt-1 text-slate-400">Max available: <span class="font-bold" x-text="getPortionsAvailable(issueProduct?.id)"></span> plates</p>
                        </div>

                        <!-- Cost Preview -->
                        <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-xl p-3 text-center" x-show="issueForm.quantity > 0">
                            <p class="text-[10px] font-bold text-emerald-600 uppercase">Total Value</p>
                            <p class="text-xl font-black text-emerald-700" x-text="'₦' + (issueForm.quantity * parseFloat(issueProduct?.selling_price || 0)).toLocaleString()"></p>
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex gap-3">
                        <button @click="issueModal = false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 font-bold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                        <button @click="issueKitchenProduct()" :disabled="!issueForm.destination_dept_id || issueForm.quantity <= 0" class="flex-1 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed">Issue Now</button>
                    </div>
                </div>
            </div>

            <!-- ===== Reconciliation Tab ===== -->
            <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 overflow-hidden mt-6" x-show="activeTab === 'kitchen_recon'">
                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center">
                            <i data-lucide="scale" class="w-5 h-5 text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-800 dark:text-white"><?= $recipe_label ?> Reconciliation</h3>
                            <p class="text-[11px] text-slate-400">Daily tracking: Opening → Issued → Sold → Returned → Closing</p>
                        </div>
                    </div>
                    <div class="px-3 py-1.5 bg-purple-50 dark:bg-purple-900/30 rounded-lg">
                        <span class="text-[10px] font-bold text-purple-500 uppercase">Date:</span>
                        <span class="text-sm font-bold text-purple-700" x-text="stockDate"><?php echo $stock_date; ?></span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/60">
                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase text-slate-400">Product</th>
                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase text-slate-400">Destination</th>
                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase text-slate-500">Opening</th>
                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase text-blue-500">Issued</th>
                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase text-emerald-500">Sold</th>
                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase text-amber-500">Returned</th>
                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase text-indigo-500">Closing</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase text-emerald-600">Revenue</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase text-rose-500">Cost</th>
                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase text-purple-500">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(r, idx) in reconciliation" :key="idx">
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors">
                                    <td class="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200 border-t border-slate-100 dark:border-slate-800" x-text="r.product_name"></td>
                                    <td class="px-4 py-2.5 border-t border-slate-100 dark:border-slate-800">
                                        <span class="px-2 py-0.5 bg-purple-50 dark:bg-purple-900/30 text-purple-600 text-[10px] font-bold rounded-full" x-text="r.dept_name"></span>
                                    </td>
                                    <td class="px-4 py-2.5 text-center font-bold text-slate-500 border-t border-slate-100 dark:border-slate-800" x-text="parseInt(r.opening || 0)"></td>
                                    <td class="px-4 py-2.5 text-center font-bold text-blue-600 border-t border-slate-100 dark:border-slate-800" x-text="parseInt(r.issued || 0)"></td>
                                    <td class="px-4 py-2.5 text-center font-bold text-emerald-600 border-t border-slate-100 dark:border-slate-800" x-text="parseInt(r.sold || 0)"></td>
                                    <td class="px-4 py-2.5 text-center font-bold text-amber-600 border-t border-slate-100 dark:border-slate-800" x-text="parseInt(r.returned || 0)"></td>
                                    <td class="px-4 py-2.5 text-center border-t border-slate-100 dark:border-slate-800">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                            :class="parseInt(r.closing || 0) > 0 ? 'bg-indigo-100 text-indigo-600' : 'bg-red-100 text-red-600'"
                                            x-text="parseInt(r.closing || 0)"></span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-bold text-emerald-600 border-t border-slate-100 dark:border-slate-800" x-text="'₦' + (parseInt(r.sold || 0) * parseFloat(r.selling_price || 0)).toLocaleString()"></td>
                                    <td class="px-4 py-2.5 text-right font-bold text-rose-500 border-t border-slate-100 dark:border-slate-800" x-text="'₦' + ((parseInt(r.opening || 0) + parseInt(r.issued || 0)) * parseFloat(r.unit_cost || 0)).toLocaleString()"></td>
                                    <td class="px-4 py-2.5 text-right font-bold border-t border-slate-100 dark:border-slate-800" 
                                        :class="(parseInt(r.sold || 0) * parseFloat(r.selling_price || 0)) - ((parseInt(r.opening || 0) + parseInt(r.issued || 0)) * parseFloat(r.unit_cost || 0)) >= 0 ? 'text-purple-600' : 'text-red-600'"
                                        x-text="'₦' + ((parseInt(r.sold || 0) * parseFloat(r.selling_price || 0)) - ((parseInt(r.opening || 0) + parseInt(r.issued || 0)) * parseFloat(r.unit_cost || 0))).toLocaleString()"></td>
                                </tr>
                            </template>
                            <tr x-show="reconciliation.length === 0">
                                <td colspan="10" class="px-4 py-10 text-center text-slate-400">
                                    <i data-lucide="package-open" class="w-8 h-8 mx-auto mb-2 opacity-40"></i>
                                    <p>No kitchen products issued for this date. Use the <strong>Issue</strong> button above to send items to restaurants.</p>
                                </td>
                            </tr>
                            <!-- Totals row -->
                            <tr x-show="reconciliation.length > 0" class="bg-slate-50 dark:bg-slate-800/60 font-bold">
                                <td colspan="2" class="px-4 py-3 text-right text-xs uppercase text-slate-500 border-t-2 border-slate-200 dark:border-slate-700">TOTALS</td>
                                <td class="px-4 py-3 text-center text-slate-500 border-t-2 border-slate-200 dark:border-slate-700" x-text="reconciliation.reduce((s,r) => s + parseInt(r.opening || 0), 0)"></td>
                                <td class="px-4 py-3 text-center text-blue-600 border-t-2 border-slate-200 dark:border-slate-700" x-text="reconciliation.reduce((s,r) => s + parseInt(r.issued || 0), 0)"></td>
                                <td class="px-4 py-3 text-center text-emerald-600 border-t-2 border-slate-200 dark:border-slate-700" x-text="reconciliation.reduce((s,r) => s + parseInt(r.sold || 0), 0)"></td>
                                <td class="px-4 py-3 text-center text-amber-600 border-t-2 border-slate-200 dark:border-slate-700" x-text="reconciliation.reduce((s,r) => s + parseInt(r.returned || 0), 0)"></td>
                                <td class="px-4 py-3 text-center text-indigo-600 border-t-2 border-slate-200 dark:border-slate-700" x-text="reconciliation.reduce((s,r) => s + parseInt(r.closing || 0), 0)"></td>
                                <td class="px-4 py-3 text-right text-emerald-600 border-t-2 border-slate-200 dark:border-slate-700" x-text="'₦' + reconciliation.reduce((s,r) => s + parseInt(r.sold || 0) * parseFloat(r.selling_price || 0), 0).toLocaleString()"></td>
                                <td class="px-4 py-3 text-right text-rose-500 border-t-2 border-slate-200 dark:border-slate-700" x-text="'₦' + reconciliation.reduce((s,r) => s + (parseInt(r.opening || 0) + parseInt(r.issued || 0)) * parseFloat(r.unit_cost || 0), 0).toLocaleString()"></td>
                                <td class="px-4 py-3 text-right text-purple-600 border-t-2 border-slate-200 dark:border-slate-700" x-text="'₦' + (reconciliation.reduce((s,r) => s + parseInt(r.sold || 0) * parseFloat(r.selling_price || 0), 0) - reconciliation.reduce((s,r) => s + (parseInt(r.opening || 0) + parseInt(r.issued || 0)) * parseFloat(r.unit_cost || 0), 0)).toLocaleString()"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== Recipe Modal ===== -->
            <div x-show="recipeModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="recipeModal = false" style="display:none;">
                <div x-show="recipeModal" x-transition.scale.90 class="w-full max-w-2xl glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden max-h-[85vh] flex flex-col">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 via-orange-500/5 to-transparent flex items-center justify-between flex-shrink-0">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg"><i data-lucide="book-open" class="w-4 h-4 text-white"></i></div>
                            <div>
                                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Recipe Builder</h3>
                                <p class="text-[10px] text-slate-500" x-text="recipeProduct ? recipeProduct.name : ''"></p>
                            </div>
                        </div>
                        <button @click="recipeModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                    </div>

                    <!-- Scrollable Content -->
                    <div class="flex-1 overflow-y-auto p-5 space-y-4">
                        <!-- Cost & Portions Summary -->
                        <div class="grid grid-cols-3 gap-3">
                            <div class="p-3 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/10 dark:to-indigo-900/10 rounded-xl border border-blue-200 dark:border-blue-800 text-center">
                                <p class="text-[9px] font-bold text-blue-500 uppercase">Cost / Plate</p>
                                <p class="text-lg font-bold text-blue-700 dark:text-blue-300" x-text="recipeProduct ? '₦'+parseFloat(recipeProduct.unit_cost||0).toLocaleString() : '₦0'"></p>
                            </div>
                            <div class="p-3 bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-emerald-900/10 dark:to-teal-900/10 rounded-xl border border-emerald-200 dark:border-emerald-800 text-center">
                                <p class="text-[9px] font-bold text-emerald-500 uppercase">Sell Price</p>
                                <p class="text-lg font-bold text-emerald-700 dark:text-emerald-300" x-text="recipeProduct ? '₦'+parseFloat(recipeProduct.selling_price||0).toLocaleString() : '₦0'"></p>
                            </div>
                            <div class="p-3 bg-gradient-to-br from-purple-50 to-pink-50 dark:from-purple-900/10 dark:to-pink-900/10 rounded-xl border border-purple-200 dark:border-purple-800 text-center">
                                <p class="text-[9px] font-bold text-purple-500 uppercase">Portions Available</p>
                                <p class="text-lg font-bold text-purple-700 dark:text-purple-300" x-text="recipeProduct ? getPortionsAvailable(recipeProduct.id) : 0"></p>
                            </div>
                        </div>

                        <!-- Add Ingredient Form -->
                        <div class="p-4 bg-gradient-to-br from-amber-50 to-orange-50 dark:from-amber-900/10 dark:to-orange-900/10 rounded-xl border border-amber-200 dark:border-amber-800">
                            <p class="text-[10px] font-bold text-amber-600 mb-2 uppercase tracking-wider">Add Ingredient</p>
                            <div class="grid grid-cols-12 gap-2 items-end">
                                <div class="col-span-5">
                                    <label class="text-[10px] font-bold mb-0.5 block text-slate-500">Raw Material</label>
                                    <select x-model="ingredientForm.ingredient_product_id" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm">
                                        <option value="">Select ingredient...</option>
                                        <template x-for="rm in rawMaterials" :key="rm.id">
                                            <option :value="rm.id" x-text="rm.name + ' (₦' + parseFloat(rm.latest_cost||0).toLocaleString() + '/' + (rm.unit||'unit') + ')'"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="col-span-2">
                                    <label class="text-[10px] font-bold mb-0.5 block text-slate-500">Qty/Plate</label>
                                    <input type="number" step="0.001" min="0.001" x-model.number="ingredientForm.qty_per_plate" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center">
                                </div>
                                <div class="col-span-3">
                                    <label class="text-[10px] font-bold mb-0.5 block text-slate-500">Unit</label>
                                    <select x-model="ingredientForm.unit" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm">
                                        <option value="portions">Portions</option>
                                        <option value="cups">Cups</option>
                                        <option value="tablespoons">Tablespoons</option>
                                        <option value="teaspoons">Teaspoons</option>
                                        <option value="pieces">Pieces</option>
                                        <option value="wraps">Wraps</option>
                                        <option value="mudu">Mudu</option>
                                        <option value="derica">Derica</option>
                                        <option value="kg">Kilograms</option>
                                        <option value="grams">Grams</option>
                                        <option value="litres">Litres</option>
                                        <option value="ml">Millilitres</option>
                                    </select>
                                </div>
                                <div class="col-span-2">
                                    <button @click="saveRecipeIngredient()" type="button" class="w-full px-3 py-1.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-lg text-sm hover:scale-[1.02] transition-all">
                                        + Add
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Ingredients Table -->
                        <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 dark:bg-slate-800/50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Ingredient</th>
                                        <th class="px-3 py-2 text-center text-[10px] font-bold text-slate-500 uppercase">Qty/Plate</th>
                                        <th class="px-3 py-2 text-center text-[10px] font-bold text-slate-500 uppercase">Unit</th>
                                        <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">Unit Cost</th>
                                        <th class="px-3 py-2 text-right text-[10px] font-bold text-blue-500 uppercase">Line Cost</th>
                                        <th class="px-3 py-2 text-center text-[10px] font-bold text-slate-500 uppercase w-12"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="ing in (recipeIngredients[recipeProduct?.id] || [])" :key="ing.ingredient_product_id">
                                        <tr class="border-t border-slate-100 dark:border-slate-800 hover:bg-amber-50/30">
                                            <td class="px-3 py-2 font-semibold text-slate-700 dark:text-slate-200" x-text="ing.ingredient_name"></td>
                                            <td class="px-3 py-2 text-center font-mono text-xs" x-text="parseFloat(ing.qty_per_plate)"></td>
                                            <td class="px-3 py-2 text-center text-xs text-slate-500 capitalize" x-text="ing.unit || 'portions'"></td>
                                            <td class="px-3 py-2 text-right font-mono text-xs text-slate-600" x-text="'₦'+parseFloat(ing.latest_cost||0).toLocaleString()"></td>
                                            <td class="px-3 py-2 text-right font-mono text-xs font-bold text-blue-600" x-text="'₦'+(parseFloat(ing.qty_per_plate) * parseFloat(ing.latest_cost||0)).toLocaleString()"></td>
                                            <td class="px-3 py-2 text-center">
                                                <button @click="deleteRecipeIngredient(ing)" class="w-6 h-6 rounded bg-red-50 hover:bg-red-100 dark:bg-red-900/20 flex items-center justify-center transition-all mx-auto">
                                                    <i data-lucide="x" class="w-3 h-3 text-red-500"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr x-show="(recipeIngredients[recipeProduct?.id] || []).length === 0">
                                        <td colspan="6" class="px-4 py-8 text-center text-slate-400 text-xs">No ingredients added yet. This product will use manual cost.</td>
                                    </tr>
                                </tbody>
                                <tfoot x-show="(recipeIngredients[recipeProduct?.id] || []).length > 0" class="bg-blue-50 dark:bg-blue-900/10 border-t-2 border-blue-200 dark:border-blue-800">
                                    <tr>
                                        <td colspan="4" class="px-3 py-2 text-right text-[11px] font-bold text-blue-600 uppercase">Total Cost / Plate</td>
                                        <td class="px-3 py-2 text-right font-mono text-sm font-bold text-blue-700" x-text="recipeProduct ? '₦'+parseFloat(recipeProduct.unit_cost||0).toLocaleString() : '₦0'"></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Add Product Modal ===== -->
            <div x-show="showAddModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="showAddModal = false">
                <div x-show="showAddModal" x-transition.scale.90 class="w-full max-w-lg glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500/10 via-teal-500/5 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg"><i data-lucide="plus" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Add Product to Department</h3></div>
                        </div>
                        <button @click="showAddModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                    </div>
                    
                    <!-- Tabs: Single Product | By Category -->
                    <div class="px-6 pt-4 flex gap-4 border-b border-slate-100 dark:border-slate-800">
                        <button @click="addMode = 'single'" :class="addMode === 'single' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-slate-400 hover:text-slate-600'" class="pb-3 border-b-2 text-xs font-bold transition-colors">Single Product</button>
                        <button @click="addMode = 'category'" :class="addMode === 'category' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-slate-400 hover:text-slate-600'" class="pb-3 border-b-2 text-xs font-bold transition-colors">By Category</button>
                    </div>

                    <!-- Source Tabs for Restaurant departments -->
                    <template x-if="isRestaurant && addMode === 'single'">
                        <div class="px-6 pt-2">
                            <div class="flex rounded-lg bg-slate-100 dark:bg-slate-800 p-1 gap-1">
                                <button @click="addSource = 'main_store'" :class="addSource === 'main_store' ? 'bg-white dark:bg-slate-700 shadow-sm text-emerald-600 font-bold' : 'text-slate-500 hover:text-slate-700'" class="flex-1 px-3 py-2 rounded-md text-xs transition-all flex items-center justify-center gap-1.5">
                                    <i data-lucide="warehouse" class="w-3 h-3"></i> Main Store
                                </button>
                                <button @click="addSource = 'kitchen'" :class="addSource === 'kitchen' ? 'bg-white dark:bg-slate-700 shadow-sm text-amber-600 font-bold' : 'text-slate-500 hover:text-slate-700'" class="flex-1 px-3 py-2 rounded-md text-xs transition-all flex items-center justify-center gap-1.5">
                                    <i data-lucide="chef-hat" class="w-3 h-3"></i> Kitchen
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- Single Product Add -->
                    <form x-show="addMode === 'single'" @submit.prevent="addProduct()" class="p-6 space-y-4">
                        <div>
                            <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Select Product *</label>
                            <select x-model="addForm.product_id" @change="onProductSelect()" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                <option value="">Choose a product...</option>
                                <template x-for="p in currentSourceProducts" :key="p.id"><option :value="p.id" x-text="p.name + (p.sku ? ' ('+p.sku+')' : '') + ' — ' + p.category"></option></template>
                            </select>
                            <p x-show="isRestaurant" class="text-[10px] mt-1" :class="addSource === 'kitchen' ? 'text-amber-500' : 'text-emerald-500'">
                                <span x-show="addSource === 'main_store'">Showing products from Main Store (direct supplies)</span>
                                <span x-show="addSource === 'kitchen'">Showing finished goods from Kitchen</span>
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[11px] font-semibold mb-1 block text-slate-500">Opening Stock</label>
                                <input type="number" x-model="addForm.opening_stock" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                            </div>
                            <div>
                                <label class="text-[11px] font-semibold mb-1 block text-slate-500">Selling Price (₦) <span class="text-[9px]" x-text="addForm._selling_unit ? '(per ' + addForm._selling_unit + ')' : ''"></span></label>
                                <input type="number" step="0.01" x-model="addForm.selling_price" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                <p class="text-[10px] text-slate-400 mt-1" x-show="addForm._default_price > 0">Default: ₦<span x-text="parseFloat(addForm._default_price).toLocaleString()"></span> — <span class="text-emerald-600 font-semibold" x-show="parseFloat(addForm.selling_price) != parseFloat(addForm._default_price)">Overridden</span></p>
                            </div>
                        </div>
                        <!-- Per-department selling unit override -->
                        <div x-show="addForm._hasSellingUnit" x-transition class="p-3 rounded-xl bg-violet-50/50 dark:bg-violet-900/10 border border-violet-200/50 dark:border-violet-800/30">
                            <p class="text-[11px] font-bold text-violet-600 mb-3">🥃 This product is sold in <span x-text="addForm._selling_unit + 's'"></span> (default: <span x-text="addForm._yield_per_unit"></span> per <span x-text="addForm._store_unit"></span>)</p>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Selling Unit for this Dept</label>
                                    <select x-model="addForm.selling_unit" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                        <option value="shot">Shot</option><option value="glass">Glass</option><option value="tot">Tot</option><option value="portion">Portion</option><option value="cup">Cup</option><option value="slice">Slice</option><option value="piece">Piece</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Yield per <span x-text="addForm._store_unit || 'unit'" class="capitalize"></span></label>
                                    <input type="number" min="1" x-model.number="addForm.yield_per_unit" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                </div>
                            </div>
                            <p class="text-[10px] text-violet-500 mt-2">💡 When 1 <span x-text="addForm._store_unit" class="capitalize"></span> is issued, this dept receives <span x-text="addForm.yield_per_unit" class="font-bold"></span> <span x-text="addForm.selling_unit + 's'" class="capitalize"></span></p>
                        </div>
                        <div class="flex gap-3 pt-2">
                            <button type="button" @click="showAddModal = false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 font-bold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                            <button type="submit" class="flex-1 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm">Add Product</button>
                        </div>
                    </form>

                    <!-- Category Bulk Add -->
                    <div x-show="addMode === 'category'" class="p-6 space-y-4" style="display:none;">
                        <div>
                            <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Select Category *</label>
                            <select x-model="addCategoryName" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                <option value="">Choose a category...</option>
                                <template x-for="cat in availableCategories" :key="cat"><option :value="cat" x-text="cat"></option></template>
                            </select>
                        </div>
                        <div x-show="addCategoryName" class="bg-slate-50 dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-3 max-h-40 overflow-y-auto">
                            <p class="text-[10px] font-bold text-slate-400 uppercase mb-2">Products to be added:</p>
                            <template x-for="p in productsInCategory" :key="p.id">
                                <div class="flex items-center justify-between py-1 text-xs">
                                    <span x-text="p.name" class="font-medium text-slate-700 dark:text-slate-200"></span>
                                    <span class="text-slate-400" x-text="'₦' + parseFloat(p.selling_price||0).toLocaleString()"></span>
                                </div>
                            </template>
                            <p x-show="productsInCategory.length === 0" class="text-xs text-slate-400">No available products in this category.</p>
                        </div>
                        <div class="flex gap-3 pt-2">
                            <button type="button" @click="showAddModal = false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 font-bold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                            <button type="button" @click="addByCategory()" :disabled="!addCategoryName || productsInCategory.length === 0" class="flex-1 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed">Add All (<span x-text="productsInCategory.length"></span>)</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Edit Stock Modal ===== -->
            <div x-show="editModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="editModal = false">
                <div x-show="editModal" x-transition.scale.90 class="w-full max-w-lg glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/10 via-indigo-500/5 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg"><i data-lucide="pencil" class="w-4 h-4 text-white"></i></div>
                            <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Update Stock</h3><p class="text-[10px] text-slate-500" x-text="editForm.product_name"></p></div>
                        </div>
                        <button @click="editModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                    </div>
                    <form @submit.prevent="updateStock()" class="p-4 space-y-2.5">
                        <!-- Selling Price -->
                        <div>
                            <label class="text-[11px] font-semibold mb-0.5 block text-slate-500">Selling Price (₦)</label>
                            <input type="number" step="0.01" x-model="editForm.selling_price" class="w-full px-3 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm">
                        </div>
                        <!-- Stock Flow Fields -->
                        <div class="p-3 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/10 dark:to-indigo-900/10 border border-blue-200 dark:border-blue-800 rounded-xl">
                            <p class="text-[10px] font-bold text-blue-600 mb-2 uppercase tracking-wider">Stock Flow</p>
                            <div class="grid grid-cols-3 gap-2 mb-2">
                                <div>
                                    <label class="text-[10px] font-bold mb-0.5 block text-slate-500">Opening</label>
                                    <input type="number" x-model.number="editForm.opening_stock" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-semibold">
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold mb-0.5 block text-blue-500">Added</label>
                                    <div class="w-full px-2 py-1.5 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold text-blue-600" x-text="parseInt(editForm.added)||0"></div>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold mb-0.5 block text-cyan-500">Inter-Dep TRF</label>
                                    <div class="w-full px-2 py-1.5 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold text-cyan-600" x-text="parseInt(editForm.return_in)||0"></div>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-2 mb-2">
                                <div>
                                    <label class="text-[10px] font-bold mb-0.5 block text-slate-700 dark:text-slate-300">Total</label>
                                    <div class="w-full px-2 py-1.5 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold"
                                         x-text="(parseInt(editForm.opening_stock)||0)+(parseInt(editForm.added)||0)+(parseInt(editForm.return_in)||0)"></div>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold mb-0.5 block text-amber-600">Qty Sold</label>
                                    <div class="w-full px-2 py-1.5 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold text-amber-600" x-text="parseInt(editForm.qty_sold)||0"></div>
                                </div>
                                <div></div>
                            </div>
                            <!-- Transfer Out Section -->
                            <div class="p-2.5 bg-gradient-to-br from-rose-50 to-orange-50 dark:from-rose-900/10 dark:to-orange-900/10 border border-rose-200 dark:border-rose-800 rounded-lg mb-2">
                                <p class="text-[10px] font-bold text-rose-600 mb-1.5 uppercase tracking-wider">Transfers Out</p>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="text-[10px] font-bold mb-0.5 block text-rose-500">To Department</label>
                                        <input type="number" x-model.number="editForm.transfer_out" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-rose-300 dark:border-rose-700 rounded-lg text-sm text-center font-semibold text-rose-600">
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold mb-0.5 block text-orange-500">To Main Store</label>
                                        <input type="number" x-model.number="editForm.transfer_to_main" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-orange-300 dark:border-orange-700 rounded-lg text-sm text-center font-semibold text-orange-600">
                                    </div>
                                </div>
                            </div>
                            <!-- Department Transfer Destination -->
                            <div x-show="editForm.transfer_out > 0" x-transition class="mb-2">
                                <label class="text-[10px] font-bold mb-0.5 block text-rose-500">Dept Transfer Destination</label>
                                <select x-model="editForm.transfer_destination" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-rose-300 dark:border-rose-700 rounded-lg text-sm font-semibold text-rose-600">
                                    <option value="">— Select Department —</option>
                                    <template x-for="d in departments" :key="d.id">
                                        <option :value="d.id" x-text="'🏬 ' + d.name"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[10px] font-bold mb-0.5 block text-emerald-600">Closing</label>
                                    <div class="w-full px-2 py-1.5 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold"
                                         :class="editClosing() <= 0 ? 'text-red-600' : 'text-emerald-600'"
                                         x-text="editClosing()"></div>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold mb-0.5 block text-purple-600">Amount</label>
                                    <div class="w-full px-2 py-1.5 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-center font-bold text-purple-600"
                                         x-text="'₦'+((parseInt(editForm.qty_sold)||0)*parseFloat(editForm.selling_price||0)).toLocaleString()"></div>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-end gap-3 pt-1">
                            <button type="button" @click="editModal = false" class="px-5 py-2 bg-slate-100 dark:bg-slate-800 text-slate-600 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                            <button type="submit" class="px-6 py-2 bg-gradient-to-r from-blue-500 to-indigo-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stock Adjustment Modal -->
            <div x-show="adjustModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @click.self="adjustModal = false">
                <div x-show="adjustModal" x-transition.scale.90 class="w-full max-w-sm glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 via-orange-500/5 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg"><i data-lucide="plus-minus" class="w-4 h-4 text-white"></i></div>
                            <div>
                                <h3 class="font-bold text-sm text-slate-900 dark:text-white">Stock Adjustment</h3>
                                <p class="text-[10px] text-slate-500" x-text="adjustForm.product_name"></p>
                            </div>
                        </div>
                        <button @click="adjustModal = false" class="w-7 h-7 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 transition-colors"><i data-lucide="x" class="w-3.5 h-3.5 text-slate-500"></i></button>
                    </div>
                    <form @submit.prevent="submitAdjustment()" class="p-4 space-y-3">
                        <!-- Direction -->
                        <div>
                            <label class="text-[10px] font-bold mb-1.5 block text-slate-500">Direction</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="adjustForm.direction = 'subtract'"
                                    :class="adjustForm.direction === 'subtract' ? 'ring-2 ring-red-500 border-red-400 bg-red-50 dark:bg-red-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50'"
                                    class="flex items-center gap-2 px-3 py-2 border rounded-lg text-left transition-all">
                                    <i data-lucide="minus-circle" class="w-4 h-4 text-red-500"></i>
                                    <div class="text-xs font-bold text-slate-700 dark:text-slate-200">Subtract</div>
                                </button>
                                <button type="button" @click="adjustForm.direction = 'add'"
                                    :class="adjustForm.direction === 'add' ? 'ring-2 ring-emerald-500 border-emerald-400 bg-emerald-50 dark:bg-emerald-900/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50'"
                                    class="flex items-center gap-2 px-3 py-2 border rounded-lg text-left transition-all">
                                    <i data-lucide="plus-circle" class="w-4 h-4 text-emerald-500"></i>
                                    <div class="text-xs font-bold text-slate-700 dark:text-slate-200">Add</div>
                                </button>
                            </div>
                        </div>

                        <!-- Reason -->
                        <div>
                            <label class="text-[10px] font-bold mb-1.5 block text-slate-500">Reason *</label>
                            <select x-model="adjustForm.reason" required class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all">
                                <option value="damage">🔴 Damage</option>
                                <option value="write_off">📝 Write-off</option>
                                <option value="error_correction">⚠️ Error Correction</option>
                                <option value="donation">🎁 Donation</option>
                                <option value="expired">⏰ Expired</option>
                                <option value="theft">🚨 Theft / Shortage</option>
                                <option value="other">📋 Other</option>
                            </select>
                        </div>

                        <!-- Quantity -->
                        <div>
                            <label class="text-[10px] font-bold mb-1.5 block text-slate-500">Quantity *</label>
                            <input type="number" x-model.number="adjustForm.quantity" min="1" required
                                class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm font-mono focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all"
                                placeholder="Enter quantity">
                        </div>

                        <!-- Notes -->
                        <div>
                            <label class="text-[10px] font-bold mb-1.5 block text-slate-500">Notes <span class="font-normal text-slate-400">(optional)</span></label>
                            <input type="text" x-model="adjustForm.notes" placeholder="Brief description..."
                                class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all">
                        </div>

                        <!-- Preview -->
                        <div class="rounded-lg p-2.5 text-[11px]"
                            :class="adjustForm.direction === 'subtract' ? 'bg-red-50 dark:bg-red-900/20 text-red-700' : 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700'">
                            <span x-text="adjustForm.direction === 'subtract' ? '⬇️ Will subtract' : '⬆️ Will add'"></span>
                            <strong x-text="adjustForm.quantity || 0"></strong>
                            <span>units</span>
                            <span x-text="adjustForm.direction === 'subtract' ? 'from' : 'to'"></span>
                            <strong x-text="adjustForm.product_name"></strong>
                        </div>

                        <div class="flex gap-3 pt-1">
                            <button type="button" @click="adjustModal = false" class="flex-1 py-2 bg-slate-100 dark:bg-slate-800 text-slate-600 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                            <button type="submit" class="flex-1 py-2 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm">Apply Adjustment</button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
</div>
<script>
function deptStoreApp() {
    return {
        stock: <?php echo $js_dept_stock; ?>,
        availableProducts: <?php echo $js_available; ?>,
        kitchenProducts: <?php echo $js_kitchen_products; ?>,
        departments: <?php echo $js_departments; ?>,
        isKitchen: <?php echo $js_is_kitchen; ?>,
        isRestaurant: <?php echo $js_is_restaurant; ?>,
        activeTab: 'inventory', // 'inventory' or 'count'
        search: '',
        categoryFilter: '',
        expandedGroups: {},
        showCountBanner: false,
        showAddModal: false,
        editModal: false,
        stockDate: '<?php echo $stock_date; ?>',
        todayStr: new Date().toISOString().split('T')[0],
        addForm: { product_id:'', opening_stock: 0, selling_price: 0, _default_price: 0, source: 'main_store', selling_unit:'', yield_per_unit:1, _hasSellingUnit: false, _selling_unit:'', _yield_per_unit:1, _store_unit:'pcs' },
        addMode: 'single',
        addSource: 'main_store',
        addCategoryName: '',
        editForm: { id:'', product_id:'', product_name:'', opening_stock:0, added:0, return_in:0, transfer_out:0, qty_sold:0, selling_price:0, transfer_destination:'', adjustment_add:0, adjustment_sub:0 },
        adjustModal: false,
        adjustForm: { product_id:'', product_name:'', direction:'subtract', reason:'damage', quantity:1, notes:'' },
        countedProducts: [],
        dirtyProducts: [],
        showCountGuide: false,
        kitchenCatalogProducts: <?php echo $js_kitchen_catalog; ?>,
        kitchenCatalogForm: { name:'', sku:'', category:'', unit:'plates', unit_cost:0, selling_price:0, newCategory:'' },
        recipeIngredients: <?php echo $js_recipe_ingredients; ?>,
        rawMaterials: <?php echo $js_raw_materials; ?>,
        recipeModal: false,
        recipeProduct: null,
        ingredientForm: { ingredient_product_id:'', qty_per_plate: 1, unit:'portions' },
        expandedRecipe: {},
        issueModal: false,
        issueProduct: null,
        issueForm: { quantity: 1, destination_dept_id: '' },
        issueDestinations: <?php echo $js_issue_destinations; ?>,
        reconciliation: <?php echo $js_reconciliation; ?>,
        outletSales: <?php echo $js_outlet_sales; ?>,
        productCategories: <?php echo $js_product_categories; ?>,

        get currentSourceProducts() {
            if (this.isRestaurant && this.addSource === 'kitchen') {
                return this.kitchenProducts;
            }
            return this.availableProducts;
        },

        int(v) { return parseInt(v) || 0; },
        toggleGroup(cat) {
            if (this.expandedGroups[cat] === undefined) this.expandedGroups[cat] = true;
            else this.expandedGroups[cat] = !this.expandedGroups[cat];
            this.$nextTick(() => lucide.createIcons());
        },
        isExpanded(cat) { return this.expandedGroups[cat] === true; },
        getTotal(r) { return this.int(r.opening_stock) + this.int(r.added) + this.int(r.return_in) + this.int(r.adjustment_add); },
        getClosing(r) { return this.getTotal(r) - this.int(r.transfer_out) - this.int(r.transfer_to_main) - this.int(r.qty_sold) - this.int(r.adjustment_sub); },
        editClosing() {
            const t = (parseInt(this.editForm.opening_stock)||0)+(parseInt(this.editForm.added)||0)+(parseInt(this.editForm.return_in)||0)+(parseInt(this.editForm.adjustment_add)||0);
            return t - (parseInt(this.editForm.transfer_out)||0) - (parseInt(this.editForm.transfer_to_main)||0) - (parseInt(this.editForm.qty_sold)||0) - (parseInt(this.editForm.adjustment_sub)||0);
        },

        // Stock Count Helpers
        // System Total = raw system figure BEFORE adjustments (Opening + Added + Return In - Transfers)
        getSystemTotal(r) { return this.int(r.opening_stock) + this.int(r.added) + this.int(r.return_in) - this.int(r.transfer_out) - this.int(r.transfer_to_main); },
        // Net adjustment: positive = added, negative = subtracted
        getNetAdjustment(r) { return this.int(r.adjustment_add) - this.int(r.adjustment_sub); },
        // Adjusted system total (what we compare physical count against)
        getAdjustedSystemTotal(r) { return this.getSystemTotal(r) + this.getNetAdjustment(r); },
        getPhysicalClosing(r) { return this.getAdjustedSystemTotal(r) - this.int(r.qty_sold); },
        setPhysicalClosing(r, val) {
            const closing = parseInt(val) || 0;
            const adjustedTotal = this.getAdjustedSystemTotal(r);
            const sold = adjustedTotal - closing;
            r.qty_sold = sold; // Update local model
        },
        getCalculatedSold(r) { return this.int(r.qty_sold); },
        getCalculatedRevenue(r) { return this.int(r.qty_sold) * parseFloat(r.selling_price || 0); },

        // Reconciliation helpers
        getStockCountSalesTotal() {
            return this.stock.reduce((sum, r) => {
                if (!this.countedProducts.includes(r.product_id)) return sum;
                return sum + (this.int(r.qty_sold) * parseFloat(r.selling_price || 0));
            }, 0);
        },
        getStockCountSoldQty() {
            return this.stock.reduce((sum, r) => {
                if (!this.countedProducts.includes(r.product_id)) return sum;
                return sum + this.int(r.qty_sold);
            }, 0);
        },
        getSystemSalesTotal() {
            return this.outletSales.reduce((sum, s) => sum + parseFloat(s.declared_total || 0), 0);
        },
        getDeclaredSalesTotal() {
            return this.outletSales.reduce((sum, s) => sum + parseFloat(s.actual_total || 0), 0);
        },
        getReconVariance() {
            return this.getSystemSalesTotal() - this.getStockCountSalesTotal();
        },
        getReconStatus() {
            if (this.outletSales.length === 0) return 'no_audit';
            if (this.countedProducts.length < this.stock.length) return 'incomplete';
            if (Math.abs(this.getReconVariance()) < 0.01) return 'matched';
            return 'variance';
        },

        async saveCount(r) {
           const fd = new FormData();
           fd.append('action', 'update_dept_stock');
           fd.append('id', r.id || '');
           fd.append('department_id', <?php echo $dept_id; ?>);
           fd.append('product_id', r.product_id);
           fd.append('stock_date', this.stockDate);
           fd.append('opening_stock', r.opening_stock);
           fd.append('added', r.added);
           fd.append('return_in', r.return_in);
           fd.append('transfer_out', r.transfer_out);
           fd.append('transfer_to_main', r.transfer_to_main || 0);
           fd.append('qty_sold', r.qty_sold); 
           fd.append('selling_price', r.selling_price);

           try {
               const res = await (await fetch('../ajax/stock_api.php', { method: 'POST', body: fd })).json();
               if (res.success) {
                   if (!this.countedProducts.includes(r.product_id)) {
                       this.countedProducts.push(r.product_id);
                   }
                   // Remove from dirty list (it's saved now)
                   this.dirtyProducts = this.dirtyProducts.filter(pid => pid !== r.product_id);
                   this.notify('Stock count saved', 'success');
                   this.$nextTick(() => lucide.createIcons());
               } else {
                   this.notify(res.message || 'Error saving count', 'error');
               }
           } catch(e) { this.notify('Connection error', 'error'); }
        },

        // Date navigation
        goDate(offset) {
            const d = new Date(this.stockDate);
            d.setDate(d.getDate() + offset);
            this.stockDate = d.toISOString().split('T')[0];
            this.goToDate();
        },
        goToday() {
            this.stockDate = this.todayStr;
            this.goToDate();
        },
        goToDate() {
            window.location.href = 'department_store.php?dept_id=<?php echo $dept_id; ?>&stock_date=' + this.stockDate;
        },

        get uniqueCategories() {
            return [...new Set(this.stock.map(r => r.category).filter(Boolean))];
        },

        get filteredStock() {
            const q = this.search.toLowerCase();
            let list = this.stock;
            if (this.categoryFilter) list = list.filter(r => r.category === this.categoryFilter);
            if (q) list = list.filter(r => (r.product_name||'').toLowerCase().includes(q) || (r.sku||'').toLowerCase().includes(q));
            return list;
        },

        get groupedStock() {
            const groups = {};
            let sn = 0;
            this.filteredStock.forEach(r => {
                const cat = r.category || 'Uncategorized';
                if (!groups[cat]) groups[cat] = [];
                groups[cat].push(r);
            });
            return Object.keys(groups).sort().map(cat => ({
                category: cat,
                items: groups[cat].map(r => { r._sn = ++sn; return r; })
            }));
        },

        openEdit(r) {
            this.editForm = {
                id: r.id,
                product_id: r.product_id,
                product_name: r.product_name,
                opening_stock: parseInt(r.opening_stock || 0),
                added: parseInt(r.added || 0),
                return_in: parseInt(r.return_in || 0),
                transfer_out: parseInt(r.transfer_out || 0),
                transfer_to_main: parseInt(r.transfer_to_main || 0),
                qty_sold: parseInt(r.qty_sold || 0),
                selling_price: parseFloat(r.selling_price || 0),
                transfer_destination: ''
            };
            this.editModal = true;
        },

        openAdjustment(r) {
            this.adjustForm = {
                product_id: r.product_id,
                product_name: r.product_name,
                direction: 'subtract',
                reason: 'damage',
                quantity: 1,
                notes: ''
            };
            this.adjustModal = true;
            this.$nextTick(() => lucide.createIcons());
        },

        async submitAdjustment() {
            if (this.adjustForm.quantity <= 0) { alert('Quantity must be greater than 0'); return; }
            const fd = new FormData();
            fd.append('action', 'dept_stock_adjustment');
            fd.append('department_id', <?php echo $dept_id; ?>);
            fd.append('product_id', this.adjustForm.product_id);
            fd.append('quantity', this.adjustForm.quantity);
            fd.append('direction', this.adjustForm.direction);
            fd.append('reason', this.adjustForm.reason);
            fd.append('notes', this.adjustForm.notes);
            fd.append('stock_date', this.stockDate);
            const res = await fetch('../ajax/stock_api.php', { method: 'POST', body: fd });
            const r = await res.json();
            if (r.success) {
                // Update local stock array immediately
                const row = this.stock.find(s => s.product_id == this.adjustForm.product_id);
                if (row) {
                    if (this.adjustForm.direction === 'add') {
                        row.adjustment_add = (parseInt(row.adjustment_add) || 0) + this.adjustForm.quantity;
                    } else {
                        row.adjustment_sub = (parseInt(row.adjustment_sub) || 0) + this.adjustForm.quantity;
                    }
                }
                this.adjustModal = false;
                this.$nextTick(() => lucide.createIcons());
            } else {
                alert(r.message || 'Adjustment failed');
            }
        },

        async addProduct() {
            if (!this.addForm.product_id) { alert('Please select a product'); return; }
            const fd = new FormData();
            fd.append('action', 'add_dept_product');
            fd.append('department_id', <?php echo $dept_id; ?>);
            fd.append('product_id', this.addForm.product_id);
            fd.append('opening_stock', this.addForm.opening_stock);
            fd.append('selling_price', this.addForm.selling_price);
            fd.append('stock_date', this.stockDate);
            fd.append('source', this.addSource);
            if (this.addForm._hasSellingUnit && this.addForm.selling_unit) {
                fd.append('selling_unit', this.addForm.selling_unit);
                fd.append('yield_per_unit', this.addForm.yield_per_unit);
            }
            const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
            if (r.success) { this.showAddModal = false; location.reload(); } else alert(r.message);
        },

        onProductSelect() {
            const prod = this.currentSourceProducts.find(p => p.id == this.addForm.product_id);
            if (prod) {
                // If product has a selling_unit (shot product), use selling_unit_price as the selling price
                if (prod.selling_unit) {
                    this.addForm.selling_price = parseFloat(prod.selling_unit_price || prod.selling_price || 0);
                    this.addForm._default_price = parseFloat(prod.selling_unit_price || prod.selling_price || 0);
                    this.addForm._hasSellingUnit = true;
                    this.addForm.selling_unit = prod.selling_unit;
                    this.addForm.yield_per_unit = parseInt(prod.yield_per_unit || 1);
                    this.addForm._selling_unit = prod.selling_unit;
                    this.addForm._yield_per_unit = parseInt(prod.yield_per_unit || 1);
                    this.addForm._store_unit = prod.unit || 'unit';
                } else {
                    this.addForm.selling_price = parseFloat(prod.selling_price || 0);
                    this.addForm._default_price = parseFloat(prod.selling_price || 0);
                    this.addForm._hasSellingUnit = false;
                    this.addForm.selling_unit = '';
                    this.addForm.yield_per_unit = 1;
                    this.addForm._selling_unit = '';
                    this.addForm._yield_per_unit = 1;
                    this.addForm._store_unit = prod.unit || 'unit';
                }
            } else {
                this.addForm.selling_price = 0;
                this.addForm._default_price = 0;
                this.addForm._hasSellingUnit = false;
                this.addForm.selling_unit = '';
                this.addForm.yield_per_unit = 1;
            }
        },

        get availableCategories() {
            const fromProducts = this.availableProducts.map(p => p.category).filter(Boolean);
            const all = [...new Set([...fromProducts, ...this.productCategories])].sort();
            return all;
        },

        get productsInCategory() {
            if (!this.addCategoryName) return [];
            return this.availableProducts.filter(p => p.category === this.addCategoryName);
        },

        async addByCategory() {
            const products = this.productsInCategory;
            if (!products.length) return;
            if (!confirm('Add ' + products.length + ' products from "' + this.addCategoryName + '" to this department?')) return;
            let added = 0;
            for (const p of products) {
                const fd = new FormData();
                fd.append('action', 'add_dept_product');
                fd.append('department_id', <?php echo $dept_id; ?>);
                fd.append('product_id', p.id);
                fd.append('opening_stock', 0);
                fd.append('selling_price', p.selling_price || 0);
                fd.append('stock_date', this.stockDate);
                try {
                    const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
                    if (r.success) added++;
                } catch(e) {}
            }
            alert(added + ' of ' + products.length + ' products added successfully.');
            location.reload();
        },

        async removeProduct(r) {
            if (!confirm('Remove "' + r.product_name + '" from this department? This will delete all stock records for this product in this dept.')) return;
            const fd = new FormData();
            fd.append('action', 'remove_dept_product');
            fd.append('department_id', <?php echo $dept_id; ?>);
            fd.append('product_id', r.product_id);
            try {
                const res = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
                if (res.success) { location.reload(); }
                else { alert(res.message || 'Error removing product'); }
            } catch(e) { alert('Connection error'); }
        },

        async updateStock() {
            if (this.editForm.transfer_out > 0 && !this.editForm.transfer_destination) {
                alert('Please select a department destination for the transfer');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'update_dept_stock');
            fd.append('id', this.editForm.id || '');
            fd.append('department_id', <?php echo $dept_id; ?>);
            fd.append('product_id', this.editForm.product_id);
            fd.append('opening_stock', this.editForm.opening_stock);
            fd.append('added', this.editForm.added);
            fd.append('return_in', this.editForm.return_in);
            fd.append('transfer_out', this.editForm.transfer_out);
            fd.append('transfer_to_main', this.editForm.transfer_to_main);
            fd.append('qty_sold', this.editForm.qty_sold);
            fd.append('selling_price', this.editForm.selling_price);
            fd.append('stock_date', this.stockDate);
            fd.append('transfer_destination', this.editForm.transfer_destination);
            const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
            if (r.success) { this.editModal = false; location.reload(); } else alert(r.message);
        },

        async createKitchenProduct() {
            if (!this.kitchenCatalogForm.name.trim()) { alert('Product name is required'); return; }
            const cat = this.kitchenCatalogForm.category === '__new' 
                ? this.kitchenCatalogForm.newCategory.trim() 
                : this.kitchenCatalogForm.category;
            if (!cat) { alert('Please select or enter a category'); return; }
            const fd = new FormData();
            fd.append('action', 'add_kitchen_product');
            fd.append('kitchen_id', <?php echo $dept_id; ?>);
            fd.append('name', this.kitchenCatalogForm.name.trim());
            fd.append('sku', this.kitchenCatalogForm.sku.trim());
            fd.append('category', cat);
            fd.append('unit', this.kitchenCatalogForm.unit);
            fd.append('unit_cost', this.kitchenCatalogForm.unit_cost || 0);
            fd.append('selling_price', this.kitchenCatalogForm.selling_price || 0);
            try {
                const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
                if (r.success) { location.reload(); } else alert(r.message || 'Error creating product');
            } catch(e) { alert('Connection error'); }
        },

        async deleteKitchenProduct(id, name) {
            if (!confirm('Delete kitchen product "' + name + '"? This cannot be undone.')) return;
            const fd = new FormData();
            fd.append('action', 'delete_kitchen_product');
            fd.append('product_id', id);
            try {
                const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
                if (r.success) { location.reload(); } else alert(r.message || 'Error deleting product');
            } catch(e) { alert('Connection error'); }
        },

        // ===== Issue to Restaurant =====
        openIssueModal(product) {
            this.issueProduct = product;
            this.issueForm = { quantity: 1, destination_dept_id: '' };
            this.issueModal = true;
        },

        async issueKitchenProduct() {
            if (!this.issueForm.destination_dept_id) { alert('Please select a destination'); return; }
            if (!this.issueForm.quantity || this.issueForm.quantity <= 0) { alert('Quantity must be greater than 0'); return; }
            const fd = new FormData();
            fd.append('action', 'issue_kitchen_product');
            fd.append('product_id', this.issueProduct.id);
            fd.append('quantity', this.issueForm.quantity);
            fd.append('destination_dept_id', this.issueForm.destination_dept_id);
            fd.append('stock_date', this.stockDate);
            try {
                const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
                if (r.success) { 
                    alert(r.message || 'Issued successfully!');
                    this.issueModal = false; 
                    location.reload(); 
                } else alert(r.message || 'Error issuing product');
            } catch(e) { alert('Connection error'); }
        },

        // ===== Recipe Management =====
        openRecipeModal(product) {
            this.recipeProduct = product;
            this.ingredientForm = { ingredient_product_id:'', qty_per_plate: 1, unit:'portions' };
            this.recipeModal = true;
        },

        async saveRecipeIngredient() {
            if (!this.ingredientForm.ingredient_product_id) { alert('Please select an ingredient'); return; }
            if (!this.ingredientForm.qty_per_plate || this.ingredientForm.qty_per_plate <= 0) { alert('Quantity must be greater than 0'); return; }
            const fd = new FormData();
            fd.append('action', 'save_recipe_ingredient');
            fd.append('product_id', this.recipeProduct.id);
            fd.append('ingredient_product_id', this.ingredientForm.ingredient_product_id);
            fd.append('qty_per_plate', this.ingredientForm.qty_per_plate);
            fd.append('unit', this.ingredientForm.unit);
            try {
                const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
                if (r.success) {
                    // Update local cost
                    this.recipeProduct.unit_cost = r.total_cost;
                    // Also update in the kitchenCatalogProducts array
                    const p = this.kitchenCatalogProducts.find(kp => kp.id == this.recipeProduct.id);
                    if (p) p.unit_cost = r.total_cost;
                    // Reload to get fresh ingredient data
                    location.reload();
                } else alert(r.message || 'Error saving ingredient');
            } catch(e) { alert('Connection error'); }
        },

        async deleteRecipeIngredient(ing) {
            if (!confirm('Remove "' + ing.ingredient_name + '" from recipe?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_recipe_ingredient');
            fd.append('product_id', ing.product_id);
            fd.append('ingredient_product_id', ing.ingredient_product_id);
            try {
                const r = await (await fetch('../ajax/stock_api.php', {method:'POST', body:fd})).json();
                if (r.success) {
                    this.recipeProduct.unit_cost = r.total_cost;
                    const p = this.kitchenCatalogProducts.find(kp => kp.id == ing.product_id);
                    if (p) p.unit_cost = r.total_cost;
                    // Remove from local array
                    const arr = this.recipeIngredients[ing.product_id] || [];
                    const idx = arr.findIndex(i => i.ingredient_product_id == ing.ingredient_product_id);
                    if (idx > -1) arr.splice(idx, 1);
                    this.$nextTick(() => lucide.createIcons());
                } else alert(r.message || 'Error removing ingredient');
            } catch(e) { alert('Connection error'); }
        },

        getPortionsAvailable(productId) {
            const ingredients = this.recipeIngredients[productId] || [];
            if (ingredients.length === 0) return '—';
            let minPortions = Infinity;
            for (const ing of ingredients) {
                // Find this ingredient in the kitchen's current stock
                const stockRow = this.stock.find(s => s.product_id == ing.ingredient_product_id);
                if (!stockRow) { minPortions = 0; break; }
                const available = this.getClosing(stockRow);
                const qtyPerPlate = parseFloat(ing.qty_per_plate) || 1;
                const portions = Math.floor(available / qtyPerPlate);
                if (portions < minPortions) minPortions = portions;
            }
            return minPortions === Infinity ? '—' : Math.max(0, minPortions);
        },

        init() {
            this.$nextTick(() => lucide.createIcons());
            this.$watch('showAddModal', () => this.$nextTick(() => lucide.createIcons()));
            this.$watch('editModal', () => this.$nextTick(() => lucide.createIcons()));
            this.$watch('recipeModal', () => this.$nextTick(() => lucide.createIcons()));
            this.$watch('activeTab', () => this.$nextTick(() => lucide.createIcons()));

            // Pre-populate countedProducts from existing stock data (items with an id = already in DB)
            this.stock.forEach(r => {
                if (r.id && parseInt(r.id) > 0) {
                    if (!this.countedProducts.includes(r.product_id)) {
                        this.countedProducts.push(r.product_id);
                    }
                }
            });

            // Stock count flashing banner: show 5s, hide 3s, repeat until all counted
            const self = this;
            function flashBanner() {
                const counted = self.countedProducts.length;
                const total = self.stock.length;
                if (counted >= total && total > 0) { self.showCountBanner = false; return; }
                self.showCountBanner = true;
                self.$nextTick(() => lucide.createIcons());
                setTimeout(() => {
                    self.showCountBanner = false;
                    setTimeout(flashBanner, 3000); // rest for 3s
                }, 5000); // show for 5s
            }
            setTimeout(flashBanner, 2000); // initial delay
        }
    }
}
</script>
<?php include '../includes/dashboard_scripts.php'; ?>
</body></html>
