<?php
require_once '../../includes/functions.php';
require_login();
require_subscription('retail_audit');

// We use the active client context.
$client_id = get_active_client();
if (!$client_id) {
    die("Please select an active client from the sidebar first.");
}

$company_id = $_SESSION['company_id'];

// Fetch outlets for this client to populate the selector
$outlets_stmt = $pdo->prepare("SELECT id, name FROM client_outlets WHERE company_id = ? AND client_id = ? AND deleted_at IS NULL");
$outlets_stmt->execute([$company_id, $client_id]);
$client_outlets = $outlets_stmt->fetchAll();

// Handle outlet selection (save in session)
if (isset($_POST['action']) && $_POST['action'] === 'set_retail_outlet') {
    $_SESSION['retail_audit_outlet_id'] = (int)$_POST['outlet_id'];
    header("Location: index.php");
    exit;
}

$active_outlet_id = $_SESSION['retail_audit_outlet_id'] ?? null;
// Validate that the active outlet still belongs to the client
$valid_outlet = false;
$active_outlet_name = "";
foreach ($client_outlets as $co) {
    if ($co['id'] == $active_outlet_id) {
        $valid_outlet = true;
        $active_outlet_name = $co['name'];
        break;
    }
}
if (!$valid_outlet) {
    // Auto-select first available outlet so user doesn't see empty state
    if (!empty($client_outlets)) {
        $active_outlet_id = $client_outlets[0]['id'];
        $active_outlet_name = $client_outlets[0]['name'];
        $_SESSION['retail_audit_outlet_id'] = $active_outlet_id;
    } else {
        $active_outlet_id = null;
        unset($_SESSION['retail_audit_outlet_id']);
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retail Audit — MIAUDITOPS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] }, colors: { brand: { 50:'#f5f3ff',100:'#ede9fe',200:'#ddd6fe',300:'#c4b5fd',400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed',700:'#6d28d9',800:'#5b21b6',900:'#4c1d95',950:'#2e1065' } } } }
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        .glass-card { background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(249,250,251,0.95) 100%); backdrop-filter: blur(20px); border: 1px solid rgba(15,23,42,0.08); box-shadow: 0 4px 20px rgba(0,0,0,0.04); }
        .dark .glass-card { background: linear-gradient(135deg, rgba(15,23,42,0.95) 0%, rgba(30,41,59,0.9) 100%); border-color: rgba(255,255,255,0.08); }
        .tab-btn.active { color: #f43f5e; border-bottom: 2px solid #f43f5e; font-weight: 700; background: rgba(244,63,94,0.05); }
        .tab-btn { transition: all 0.2s; border-bottom: 2px solid transparent; }
        .tab-btn:hover:not(.active) { color: #475569; border-bottom-color: #cbd5e1; }
        .dark .tab-btn:hover:not(.active) { color: #f8fafc; border-bottom-color: #475569; }
    </style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="{ currentTab: 'products' }">

<div class="flex h-screen w-full">
    
    <!-- Sidebar -->
    <?php $sidebar_base = '../'; include '../../includes/dashboard_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        
        <?php include '../../includes/dashboard_header.php'; ?>

        <main class="flex-1 overflow-y-auto p-6 lg:p-8 scroll-smooth" id="mainLayout">
            
            <?php display_flash_message(); ?>

            <!-- Page Header -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-black text-slate-900 dark:text-white flex items-center gap-3">
                        <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-pink-500 to-rose-600 flex items-center justify-center shadow-lg shadow-pink-500/30">
                            <i data-lucide="shopping-cart" class="w-5 h-5 text-white"></i>
                        </span>
                        Retail Audit Scope
                    </h1>
                </div>
            </div>

            <!-- Module Description Banner -->
            <div class="mb-8 bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-900/40 rounded-2xl p-5 md:p-6 shadow-sm flex flex-col md:flex-row gap-4 items-start">
                <div class="p-3 bg-blue-100 dark:bg-blue-800/50 rounded-xl text-blue-600 dark:text-blue-400 shrink-0">
                    <i data-lucide="info" class="w-6 h-6"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-blue-900 dark:text-blue-300 mb-1">How This Module Works</h3>
                    <p class="text-sm text-blue-800/80 dark:text-blue-200/70 mb-3 leading-relaxed">
                        The <strong>Retail Audit</strong> is designed exclusively for high-speed, point-of-sale efficiency. Unlike complex inventory systems tracking movement across multiple internal departments, this engine focuses purely on the endpoint: reconciling total bulk purchases/requisitions directly against final customer sales.
                    </p>
                    <div class="flex items-center gap-2 text-xs font-semibold text-blue-700 dark:text-blue-400">
                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                        <span>Need to track goods moving between internal departments? Please use the comprehensive <a href="../stock_audit/index.php" class="underline hover:text-blue-900 dark:hover:text-blue-200">Stock Audit</a> module instead.</span>
                    </div>
                </div>
            </div>

            <!-- Outlet Selector Form -->
            <div class="glass-card rounded-2xl p-6 mb-8 relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4 opacity-10">
                    <i data-lucide="building" class="w-24 h-24 text-slate-900 dark:text-white"></i>
                </div>
                <div class="relative z-10">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Location Context</h2>
                    <?php if (empty($client_outlets)): ?>
                        <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl text-amber-700 dark:text-amber-300">
                            <p class="font-bold">No Retail Outlets Detected.</p>
                            <p class="text-sm">You must create at least one Outlet in the <a href="../company_setup.php" class="underline">Company Setup</a> to begin using the Retail module.</p>
                        </div>
                    <?php else: ?>
                        <form method="POST" class="flex flex-col sm:flex-row gap-4 max-w-2xl">
                            <input type="hidden" name="action" value="set_retail_outlet">
                            <select name="outlet_id" class="flex-1 px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-rose-500">
                                <option value="" disabled selected>Select an active Retail Outlet...</option>
                                <?php foreach($client_outlets as $co): ?>
                                    <option value="<?php echo $co['id']; ?>" <?php echo ($active_outlet_id == $co['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($co['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="px-6 py-2.5 rounded-xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold hover:shadow-lg transition-all">Submit Context</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($active_outlet_id): ?>
            <!-- Tabs Navigation -->
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm mb-6 flex overflow-x-auto hide-scroll">
                <button @click="currentTab = 'products'" :class="{ 'active': currentTab === 'products' }" class="tab-btn flex items-center gap-2 px-6 py-4 text-sm font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap">
                    <i data-lucide="package"></i> Registry
                </button>
                <button @click="currentTab = 'suppliers'" :class="{ 'active': currentTab === 'suppliers' }" class="tab-btn flex items-center gap-2 px-6 py-4 text-sm font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap">
                    <i data-lucide="users"></i> Suppliers / Requisition
                </button>
                <button @click="currentTab = 'purchases'" :class="{ 'active': currentTab === 'purchases' }" class="tab-btn flex items-center gap-2 px-6 py-4 text-sm font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap">
                    <i data-lucide="truck"></i> Additions
                </button>
                <button @click="currentTab = 'audit'" :class="{ 'active': currentTab === 'audit' }" class="tab-btn flex items-center gap-2 px-6 py-4 text-sm font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap">
                    <i data-lucide="clipboard-list"></i> Physical Count
                </button>
                <button @click="currentTab = 'reconciliation'" :class="{ 'active': currentTab === 'reconciliation' }" class="tab-btn flex items-center gap-2 px-6 py-4 text-sm font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap">
                    <i data-lucide="calculator"></i> Reconciliation
                </button>
                <button @click="currentTab = 'sales_reconciliation'" :class="{ 'active': currentTab === 'sales_reconciliation' }" class="tab-btn flex items-center gap-2 px-6 py-4 text-sm font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap">
                    <i data-lucide="banknote"></i> Sales Math
                </button>
                <button @click="currentTab = 'report'" :class="{ 'active': currentTab === 'report' }" class="tab-btn flex items-center gap-2 px-6 py-4 text-sm font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap">
                    <i data-lucide="file-check"></i> Report
                </button>
            </div>

            <!-- Tab Contents -->
            <div class="glass-card rounded-xl p-6 min-h-[500px]">
                
                <!-- TAB 1: PRODUCT REGISTRY -->
                <div x-show="currentTab === 'products'" x-cloak>
                    <?php include 'tabs/products.php'; ?>
                </div>

                <!-- TAB 1.5: SUPPLIERS -->
                <div x-show="currentTab === 'suppliers'" x-cloak>
                    <?php include 'tabs/suppliers.php'; ?>
                </div>

                <!-- TAB 2: PURCHASES / ADDITIONS -->
                <div x-show="currentTab === 'purchases'" x-cloak>
                    <?php include 'tabs/purchases.php'; ?>
                </div>

                <!-- TAB 3: PHYSICAL COUNT -->
                <div x-show="currentTab === 'audit'" x-cloak>
                    <?php include 'tabs/physical_count.php'; ?>
                </div>

                <!-- TAB 4: RECONCILIATION -->
                <div x-show="currentTab === 'reconciliation'" x-cloak>
                    <?php include 'tabs/reconciliation.php'; ?>
                </div>

                <!-- TAB 4.5: SALES RECONCILIATION -->
                <div x-show="currentTab === 'sales_reconciliation'" x-cloak>
                    <?php include 'tabs/sales_reconciliation.php'; ?>
                </div>

                <!-- TAB 5: REPORTS -->
                <div x-show="currentTab === 'report'" x-cloak>
                    <?php include 'tabs/reports.php'; ?>
                </div>

            </div>
            <?php else: ?>
                <!-- Empty State When No Outlet Selected -->
                <div class="glass-card rounded-2xl p-12 text-center border-dashed border-2 border-slate-300 dark:border-slate-700 max-w-2xl mx-auto">
                    <div class="w-20 h-20 bg-slate-100 dark:bg-slate-800 text-slate-400 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="store" class="w-10 h-10"></i>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">No Active Retail Selected</h3>
                    <p class="text-slate-500 dark:text-slate-400">The retail engine operates on a strictly localized basis. Select the specific Outlet you intend to audit in the locator above.</p>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<!-- Core Modals Container -->
<div id="modalContainer"></div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Initialize Lucide Icons
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>
<script>
    const userIsAdmin = <?php echo is_admin_role() ? 'true' : 'false'; ?>;
    const pdfClientName = <?php echo json_encode($_SESSION['active_client_name'] ?? 'Client'); ?>;
    const pdfOutletName = <?php echo json_encode($active_outlet_name ?: 'Outlet'); ?>;
</script>
<script src="retail_engine.js"></script>
<?php include '../../includes/dashboard_scripts.php'; ?>
</body>
</html>
