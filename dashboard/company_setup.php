<?php
/**
 * MIAUDITOPS — Company Setup
 * Client & Outlet Management Dashboard
 */
require_once '../includes/functions.php';
require_once '../config/sector_config.php';
require_login();
require_permission('company_setup');

$company_id = $_SESSION['company_id'];
$page_title = 'Company Setup';

// Fetch all clients
$clients = get_clients($company_id);
$active_client_id = get_active_client();

// Fetch outlets for active client (if any)
$active_outlets = $active_client_id ? get_client_outlets($active_client_id) : [];
$active_client = $active_client_id ? get_client($active_client_id, $company_id) : null;

// Determine active client's sector
$active_sector_key = strtolower($active_client['industry'] ?? 'other');
$active_sector = get_sector_config($active_sector_key);
$js_sectors = json_encode($SECTORS);

// Stats
$total_clients = count($clients);
$total_outlets = 0;
$active_clients = 0;
foreach ($clients as $c) {
    $total_outlets += $c['outlet_count'];
    if ($c['is_active']) $active_clients++;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Setup — MIAUDITOPS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] }, colors: { brand: { 50:'#f5f3ff',100:'#ede9fe',200:'#ddd6fe',300:'#c4b5fd',400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed',700:'#6d28d9',800:'#5b21b6',900:'#4c1d95',950:'#2e1065' } } } }
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        .glass-card { background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(249,250,251,0.9) 100%); backdrop-filter: blur(20px); }
        .dark .glass-card { background: linear-gradient(135deg, rgba(15,23,42,0.95) 0%, rgba(30,41,59,0.9) 100%); }
    </style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="companySetup()">

<div class="flex h-screen w-full">
    
    <?php include '../includes/dashboard_sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        
        <?php include '../includes/dashboard_header.php'; ?>

        <main class="flex-1 overflow-y-auto p-6 lg:p-8 scroll-smooth">
            
            <?php display_flash_message(); ?>

            <!-- Page Header -->
            <div class="mb-8">
                <h2 class="text-2xl font-black text-slate-900 dark:text-white">Company Setup</h2>
                <p class="text-slate-500 dark:text-slate-400 mt-1">Manage your clients and their sales outlets</p>
            </div>

            <!-- Overview KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                <div class="glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Clients</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                            <i data-lucide="building-2" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-3xl font-black text-slate-900 dark:text-white"><?php echo $total_clients; ?></p>
                    <p class="text-xs text-slate-400 mt-1"><?php echo $active_clients; ?> active</p>
                </div>
                <div class="glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Outlets</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/30">
                            <i data-lucide="store" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-3xl font-black text-slate-900 dark:text-white"><?php echo $total_outlets; ?></p>
                    <p class="text-xs text-slate-400 mt-1">Across all clients</p>
                </div>
                <div class="glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Active Client</span>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/30">
                            <i data-lucide="check-circle" class="w-5 h-5 text-white"></i>
                        </div>
                    </div>
                    <p class="text-lg font-black text-slate-900 dark:text-white truncate"><?php echo $active_client ? htmlspecialchars($active_client['name']) : 'None Selected'; ?></p>
                    <p class="text-xs text-slate-400 mt-1"><?php echo $active_client ? count($active_outlets) . ' outlet(s)' : 'Select a client to begin'; ?></p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                <!-- Tab Headers -->
                <div class="flex border-b border-slate-200 dark:border-slate-700 bg-gradient-to-r from-slate-50 to-white dark:from-slate-900 dark:to-slate-800">
                    <button @click="switchTab('clients')" :class="activeTab==='clients' ? 'border-b-2 border-indigo-500 text-indigo-600 dark:text-indigo-400 bg-white dark:bg-slate-800' : 'text-slate-500 hover:text-slate-700'" class="flex items-center gap-2 px-6 py-4 text-sm font-bold transition-all">
                        <i data-lucide="building-2" class="w-4 h-4"></i> Clients
                    </button>
                    <button @click="switchTab('outlets')" :class="activeTab==='outlets' ? 'border-b-2 border-indigo-500 text-indigo-600 dark:text-indigo-400 bg-white dark:bg-slate-800' : 'text-slate-500 hover:text-slate-700'" class="flex items-center gap-2 px-6 py-4 text-sm font-bold transition-all">
                        <i data-lucide="store" class="w-4 h-4"></i> Outlets
                    </button>
                </div>

                <!-- ========== TAB 1: CLIENTS ========== -->
                <div x-show="activeTab==='clients'" class="p-6">
                    
                    <!-- Add Client Form -->
                    <div class="mb-6 p-5 rounded-xl bg-gradient-to-br from-indigo-50 to-blue-50 dark:from-indigo-900/20 dark:to-blue-900/20 border border-indigo-200/50 dark:border-indigo-800/50">
                        <h3 class="text-base font-bold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                            <i data-lucide="plus-circle" class="w-5 h-5 text-indigo-500"></i> Add New Client
                        </h3>
                        <form @submit.prevent="addClient()" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Business Name *</label>
                                <input type="text" x-model="clientForm.name" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="e.g. Golden Hotels Ltd">
                            </div>
                            <div>
                                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Contact Person</label>
                                <input type="text" x-model="clientForm.contact_person" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="John Doe">
                            </div>
                            <div>
                                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Email</label>
                                <input type="email" x-model="clientForm.email" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="info@company.com">
                            </div>
                            <div>
                                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Phone</label>
                                <input type="text" x-model="clientForm.phone" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="+234 xxx xxx xxxx">
                            </div>
                            <div>
                                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Industry / Sector *</label>
                                <select x-model="clientForm.industry" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500">
                                    <option value="">Select Sector...</option>
                                    <?php foreach ($SECTORS as $skey => $scfg): ?>
                                    <option value="<?php echo $skey; ?>"><?php echo htmlspecialchars($scfg['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p x-show="clientForm.industry" x-text="sectors[clientForm.industry]?.description || ''" class="text-[9px] text-slate-400 mt-1"></p>
                            </div>
                            <div>
                                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Address</label>
                                <input type="text" x-model="clientForm.address" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="123 Main Street, Lagos">
                            </div>
                            <div class="sm:col-span-2 lg:col-span-3 flex justify-end">
                                <button type="submit" :disabled="saving" class="inline-flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-indigo-500 to-blue-600 text-white text-sm font-bold rounded-xl shadow-lg shadow-indigo-500/30 hover:shadow-indigo-500/50 hover:scale-105 transition-all disabled:opacity-50">
                                    <i data-lucide="plus" class="w-4 h-4"></i> 
                                    <span x-text="saving ? 'Creating...' : 'Create Client'"></span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Clients Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-700">
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400">Client</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400">Code</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400">Contact</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400">Industry</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400">Outlets</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400">Status</th>
                                    <th class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-slate-400">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <?php if (empty($clients)): ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-12 text-center">
                                        <div class="flex flex-col items-center gap-3">
                                            <div class="w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center">
                                                <i data-lucide="building-2" class="w-8 h-8 text-indigo-400"></i>
                                            </div>
                                            <p class="text-sm font-bold text-slate-500">No clients yet</p>
                                            <p class="text-xs text-slate-400">Create your first client above to get started</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($clients as $cl): ?>
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center text-white text-xs font-bold shadow-md">
                                                <?php echo strtoupper(substr($cl['name'], 0, 2)); ?>
                                            </div>
                                            <div>
                                                <p class="text-sm font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($cl['name']); ?></p>
                                                <p class="text-xs text-slate-400"><?php echo htmlspecialchars($cl['email'] ?: 'No email'); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-1 rounded-md bg-slate-100 dark:bg-slate-800 text-xs font-mono font-bold text-slate-600 dark:text-slate-300"><?php echo $cl['code']; ?></span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($cl['contact_person'] ?: '—'); ?></td>
                                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($cl['industry'] ?: '—'); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 text-xs font-bold">
                                            <i data-lucide="store" class="w-3 h-3"></i> <?php echo $cl['outlet_count']; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($cl['is_active']): ?>
                                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 text-xs font-bold">Active</span>
                                        <?php else: ?>
                                        <span class="px-2.5 py-0.5 rounded-full bg-amber-50 dark:bg-amber-900/20 text-amber-600 text-xs font-bold">Suspended</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-1.5">
                                            <a href="../ajax/company_setup_api.php?action=set_active_client&client_id=<?php echo $cl['id']; ?>&redirect=company_setup.php" 
                                               class="px-2.5 py-1 rounded-lg text-xs font-bold transition-all <?php echo ($cl['id'] == $active_client_id) ? 'bg-indigo-100 text-indigo-700 cursor-default' : 'bg-indigo-50 text-indigo-600 hover:bg-indigo-100'; ?>">
                                                <?php echo ($cl['id'] == $active_client_id) ? '✓ Active' : 'Select'; ?>
                                            </a>
                                            <button onclick="openEditClient(<?php echo htmlspecialchars(json_encode([
                                                'id' => $cl['id'], 'name' => $cl['name'], 'contact_person' => $cl['contact_person'] ?? '',
                                                'email' => $cl['email'] ?? '', 'phone' => $cl['phone'] ?? '',
                                                'address' => $cl['address'] ?? '', 'industry' => $cl['industry'] ?? ''
                                            ])); ?>)" class="p-1.5 rounded-lg text-indigo-500 hover:text-indigo-700 hover:bg-indigo-50 transition-all" title="Edit">
                                                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                                            </button>
                                            <button onclick="toggleClient(<?php echo $cl['id']; ?>)" class="p-1.5 rounded-lg <?php echo $cl['is_active'] ? 'text-amber-500 hover:text-amber-700 hover:bg-amber-50' : 'text-emerald-500 hover:text-emerald-700 hover:bg-emerald-50'; ?> transition-all" title="<?php echo $cl['is_active'] ? 'Suspend' : 'Activate'; ?>">
                                                <i data-lucide="<?php echo $cl['is_active'] ? 'pause' : 'play'; ?>" class="w-3.5 h-3.5"></i>
                                            </button>
                                            <button onclick="deleteClient(<?php echo $cl['id']; ?>, '<?php echo addslashes($cl['name']); ?>')" class="p-1.5 rounded-lg text-red-400 hover:text-red-600 hover:bg-red-50 transition-all" title="Delete">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ========== TAB 2: OUTLETS ========== -->
                <div x-show="activeTab==='outlets'" class="p-6">
                    
                    <?php if (!$active_client): ?>
                    <div class="flex flex-col items-center justify-center py-16 gap-4">
                        <div class="w-20 h-20 rounded-2xl bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center">
                            <i data-lucide="alert-circle" class="w-10 h-10 text-amber-400"></i>
                        </div>
                        <p class="text-lg font-bold text-slate-700 dark:text-slate-300">No Client Selected</p>
                        <p class="text-sm text-slate-400 text-center">Please select a client from the Clients tab or from the sidebar dropdown to manage their outlets.</p>
                        <button @click="switchTab('clients')" class="px-4 py-2 bg-indigo-500 text-white text-sm font-bold rounded-xl hover:bg-indigo-600 transition-colors">
                            Go to Clients
                        </button>
                    </div>
                    <?php else: ?>
                    
                    <!-- Active Client Info Strip -->
                    <div class="mb-6 flex items-center gap-3 p-4 rounded-xl bg-gradient-to-r from-indigo-50 to-blue-50 dark:from-indigo-900/20 dark:to-blue-900/20 border border-indigo-200/50 dark:border-indigo-800/50">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center text-white font-bold text-lg shadow-lg shadow-indigo-500/30">
                            <?php echo strtoupper(substr($active_client['name'], 0, 2)); ?>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($active_client['name']); ?></p>
                            <p class="text-xs text-slate-400">Managing outlets for this client • Code: <?php echo $active_client['code']; ?></p>
                        </div>
                    </div>

                    <!-- Add Outlet Form -->
                    <div class="mb-6 p-5 rounded-xl bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-emerald-900/10 dark:to-teal-900/10 border border-emerald-200/50 dark:border-emerald-800/50">
                        <h3 class="text-base font-bold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                            <i data-lucide="plus-circle" class="w-5 h-5 text-emerald-500"></i> Add Sales Outlet
                        </h3>
                        <form @submit.prevent="addOutlet()" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div>
                                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Outlet Name *</label>
                                <input type="text" x-model="outletForm.name" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-emerald-500 focus:border-transparent" placeholder="e.g. Main Reception">
                            </div>
                            <div>
                                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Outlet Type *</label>
                                <select x-model="outletForm.type" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-emerald-500">
                                    <template x-for="(label, key) in (sectors[activeSector]?.outlet_types || {})" :key="key">
                                        <option :value="key" x-text="label"></option>
                                    </template>
                                    <option value="other">Other (Custom)</option>
                                </select>
                                <!-- Kitchen Count Selector (shown only when sector has_kitchen AND type is restaurant) -->
                                <div x-show="sectors[activeSector]?.has_kitchen && outletForm.type === 'restaurant'" x-transition class="mt-2">
                                    <label class="text-[10px] font-bold uppercase text-amber-600 mb-1 block">🍳 Number of Kitchens</label>
                                    <select x-model="outletForm.kitchen_count" class="w-full px-3 py-2.5 rounded-xl border border-amber-300 dark:border-amber-600 bg-amber-50 dark:bg-amber-900/20 text-sm font-bold text-slate-800 dark:text-white focus:ring-2 focus:ring-amber-500">
                                        <option value="0">0 — No Kitchen</option>
                                        <option value="1" selected>1 — Single Kitchen</option>
                                        <option value="2">2 — Two Kitchens</option>
                                        <option value="3">3 — Three Kitchens</option>
                                    </select>
                                    <p class="text-[9px] text-amber-500 mt-1">Kitchen departments will be auto-created for this restaurant</p>
                                </div>
                                <div x-show="outletForm.type === 'other'" x-transition class="mt-2">
                                    <input type="text" x-model="outletForm.custom_type" class="w-full px-3 py-2.5 rounded-xl border border-amber-300 dark:border-amber-600 bg-amber-50 dark:bg-amber-900/20 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-amber-500 focus:border-transparent" placeholder="Enter your custom type">
                                </div>
                                <!-- Sector Hint -->
                                <p class="text-[9px] text-slate-400 mt-1">
                                    <span class="font-bold" x-text="sectors[activeSector]?.label || 'General'"></span> sector outlet types
                                </p>
                            </div>
                            <div>
                                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Short Code</label>
                                <input type="text" x-model="outletForm.code" maxlength="10" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium font-mono text-slate-800 dark:text-white focus:ring-2 focus:ring-emerald-500 focus:border-transparent uppercase" placeholder="AUTO">
                            </div>
                            <div>
                                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Description</label>
                                <input type="text" x-model="outletForm.description" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-emerald-500 focus:border-transparent" placeholder="Ground floor entrance">
                            </div>
                            <div class="sm:col-span-2 lg:col-span-4 flex justify-end">
                                <button type="submit" :disabled="saving" class="inline-flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white text-sm font-bold rounded-xl shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 hover:scale-105 transition-all disabled:opacity-50">
                                    <i data-lucide="plus" class="w-4 h-4"></i>
                                    <span x-text="saving ? 'Adding...' : 'Add Outlet'"></span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Outlets Grid -->
                    <?php if (empty($active_outlets)): ?>
                    <div class="flex flex-col items-center py-12 gap-3">
                        <div class="w-16 h-16 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center">
                            <i data-lucide="store" class="w-8 h-8 text-emerald-400"></i>
                        </div>
                        <p class="text-sm font-bold text-slate-500">No outlets configured</p>
                        <p class="text-xs text-slate-400">Add outlets for your <strong><?php echo htmlspecialchars($active_sector['label']); ?></strong> client</p>
                    </div>
                    <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php 
                        $outlet_colors = [
                            // Hospitality
                            'reception' => ['from-blue-500 to-indigo-600', 'blue'],
                            'bar' => ['from-purple-500 to-violet-600', 'purple'],
                            'restaurant' => ['from-amber-500 to-orange-600', 'amber'],
                            'front_desk' => ['from-cyan-500 to-blue-600', 'cyan'],
                            'kitchen' => ['from-rose-500 to-red-600', 'rose'],
                            'room_kitchen' => ['from-red-500 to-rose-600', 'red'],
                            'cafe' => ['from-amber-400 to-yellow-600', 'amber'],
                            'hotel' => ['from-indigo-500 to-blue-600', 'indigo'],
                            'banquet' => ['from-fuchsia-500 to-pink-600', 'fuchsia'],
                            // Petroleum / Gas
                            'filling_station' => ['from-orange-500 to-amber-600', 'orange'],
                            'depot' => ['from-amber-600 to-orange-700', 'amber'],
                            'lpg_plant' => ['from-red-500 to-orange-600', 'red'],
                            'mini_mart' => ['from-emerald-500 to-teal-600', 'emerald'],
                            'lube_bay' => ['from-slate-500 to-zinc-600', 'slate'],
                            // Retail
                            'store' => ['from-emerald-500 to-green-600', 'emerald'],
                            'warehouse' => ['from-slate-500 to-zinc-600', 'slate'],
                            'kiosk' => ['from-lime-500 to-green-600', 'lime'],
                            'ecommerce' => ['from-violet-500 to-purple-600', 'violet'],
                            'showroom' => ['from-sky-500 to-blue-600', 'sky'],
                            // Healthcare
                            'clinic' => ['from-emerald-500 to-green-600', 'emerald'],
                            'pharmacy' => ['from-teal-500 to-cyan-600', 'teal'],
                            'lab' => ['from-blue-500 to-indigo-600', 'blue'],
                            'ward' => ['from-rose-500 to-pink-600', 'rose'],
                            'dental' => ['from-sky-500 to-cyan-600', 'sky'],
                            // Construction
                            'site' => ['from-amber-500 to-yellow-600', 'amber'],
                            'yard' => ['from-stone-500 to-zinc-600', 'stone'],
                            // Education
                            'campus' => ['from-violet-500 to-purple-600', 'violet'],
                            'admin' => ['from-indigo-500 to-blue-600', 'indigo'],
                            'hostel' => ['from-amber-500 to-orange-600', 'amber'],
                            'library' => ['from-emerald-500 to-teal-600', 'emerald'],
                            // Logistics
                            'fleet' => ['from-blue-500 to-cyan-600', 'blue'],
                            'loading' => ['from-orange-500 to-amber-600', 'orange'],
                            // Manufacturing
                            'factory' => ['from-indigo-500 to-slate-600', 'indigo'],
                            'assembly' => ['from-zinc-500 to-slate-600', 'zinc'],
                            'qc_lab' => ['from-teal-500 to-emerald-600', 'teal'],
                            // General
                            'branch' => ['from-indigo-500 to-blue-600', 'indigo'],
                            'office' => ['from-slate-500 to-zinc-600', 'slate'],
                            'lounge' => ['from-fuchsia-500 to-pink-600', 'fuchsia'],
                            'pool' => ['from-sky-500 to-blue-600', 'sky'],
                            'spa' => ['from-pink-500 to-rose-600', 'pink'],
                            'gym' => ['from-orange-500 to-red-600', 'orange'],
                            'other' => ['from-slate-500 to-slate-600', 'slate'],
                        ];
                        $outlet_icons = [
                            // Hospitality
                            'reception' => 'bell', 'bar' => 'wine', 'restaurant' => 'utensils',
                            'front_desk' => 'monitor', 'kitchen' => 'chef-hat', 'room_kitchen' => 'chef-hat',
                            'cafe' => 'coffee', 'hotel' => 'bed-double', 'banquet' => 'party-popper',
                            // Petroleum / Gas
                            'filling_station' => 'fuel', 'depot' => 'warehouse', 'lpg_plant' => 'flame',
                            'mini_mart' => 'shopping-bag', 'lube_bay' => 'wrench',
                            // Retail
                            'store' => 'shopping-bag', 'warehouse' => 'warehouse', 'kiosk' => 'store',
                            'ecommerce' => 'globe', 'showroom' => 'presentation',
                            // Healthcare
                            'clinic' => 'heart-pulse', 'pharmacy' => 'pill', 'lab' => 'flask-conical',
                            'ward' => 'bed', 'dental' => 'smile',
                            // Construction
                            'site' => 'hard-hat', 'yard' => 'truck',
                            // Education
                            'campus' => 'graduation-cap', 'admin' => 'building-2', 'hostel' => 'home',
                            'library' => 'book-open',
                            // Logistics
                            'fleet' => 'truck', 'loading' => 'container',
                            // Manufacturing
                            'factory' => 'factory', 'assembly' => 'cog', 'qc_lab' => 'microscope',
                            // General
                            'branch' => 'building-2', 'office' => 'briefcase',
                            'lounge' => 'sofa', 'pool' => 'waves', 'spa' => 'sparkles',
                            'gym' => 'dumbbell', 'other' => 'box'
                        ];
                        foreach ($active_outlets as $ol):
                            $ocolor = $outlet_colors[$ol['type']] ?? $outlet_colors['other'];
                            $oicon = $outlet_icons[$ol['type']] ?? 'box';
                        ?>
                        <div class="glass-card rounded-xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-md hover:shadow-lg transition-all group <?php echo !$ol['is_active'] ? 'opacity-60' : ''; ?>">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-11 h-11 rounded-xl bg-gradient-to-br <?php echo $ocolor[0]; ?> flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                                    <i data-lucide="<?php echo $oicon; ?>" class="w-5 h-5 text-white"></i>
                                </div>
                                <span class="px-2 py-0.5 rounded-md bg-<?php echo $ocolor[1]; ?>-50 dark:bg-<?php echo $ocolor[1]; ?>-900/20 text-<?php echo $ocolor[1]; ?>-600 text-[10px] font-bold uppercase"><?php echo str_replace('_',' ',$ol['type']); ?></span>
                            </div>
                            <h4 class="text-sm font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($ol['name']); ?></h4>
                            <p class="text-xs text-slate-400 mt-0.5">Code: <span class="font-mono font-bold"><?php echo $ol['code']; ?></span></p>
                            <?php if ($ol['description']): ?>
                            <p class="text-xs text-slate-400 mt-1"><?php echo htmlspecialchars($ol['description']); ?></p>
                            <?php endif; ?>
                            
                            <!-- Status -->
                            <div class="mt-3 mb-3">
                                <?php if ($ol['is_active']): ?>
                                <span class="text-[10px] font-bold text-emerald-600 flex items-center gap-1"><i data-lucide="check-circle" class="w-3 h-3"></i> Active</span>
                                <?php else: ?>
                                <span class="text-[10px] font-bold text-amber-500 flex items-center gap-1"><i data-lucide="pause-circle" class="w-3 h-3"></i> Suspended</span>
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex items-center gap-1.5 pt-3 border-t border-slate-100 dark:border-slate-800">
                                <button onclick="openEditOutlet(<?php echo htmlspecialchars(json_encode([
                                    'id' => $ol['id'], 'name' => $ol['name'], 'type' => $ol['type'],
                                    'code' => $ol['code'], 'description' => $ol['description'] ?? ''
                                ])); ?>)" class="flex-1 flex items-center justify-center gap-1 px-2 py-1.5 rounded-lg text-xs font-bold text-indigo-600 bg-indigo-50 dark:bg-indigo-900/20 hover:bg-indigo-100 dark:hover:bg-indigo-900/40 transition-all" title="Edit">
                                    <i data-lucide="pencil" class="w-3 h-3"></i> Edit
                                </button>
                                <button onclick="toggleOutlet(<?php echo $ol['id']; ?>)" class="flex-1 flex items-center justify-center gap-1 px-2 py-1.5 rounded-lg text-xs font-bold <?php echo $ol['is_active'] ? 'text-amber-600 bg-amber-50 dark:bg-amber-900/20 hover:bg-amber-100' : 'text-emerald-600 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100'; ?> transition-all" title="<?php echo $ol['is_active'] ? 'Suspend' : 'Activate'; ?>">
                                    <i data-lucide="<?php echo $ol['is_active'] ? 'pause' : 'play'; ?>" class="w-3 h-3"></i> <?php echo $ol['is_active'] ? 'Suspend' : 'Activate'; ?>
                                </button>
                                <button onclick="deleteOutlet(<?php echo $ol['id']; ?>, '<?php echo addslashes($ol['name']); ?>')" class="flex items-center justify-center gap-1 px-2 py-1.5 rounded-lg text-xs font-bold text-red-600 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition-all" title="Delete">
                                    <i data-lucide="trash-2" class="w-3 h-3"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- Edit Outlet Modal -->
<div id="editOutletModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm" onclick="if(event.target===this)closeEditOutlet()">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-full max-w-md mx-4 p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <i data-lucide="pencil" class="w-5 h-5 text-indigo-500"></i> Edit Outlet
            </h3>
            <button onclick="closeEditOutlet()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-all">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form onsubmit="event.preventDefault(); saveEditOutlet();" class="space-y-4">
            <input type="hidden" id="edit_outlet_id">
            <div>
                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Outlet Name *</label>
                <input type="text" id="edit_outlet_name" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
            </div>
            <div>
                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Outlet Type</label>
                <input type="text" id="edit_outlet_type" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="e.g. reception, bar, restaurant">
            </div>
            <div>
                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Short Code</label>
                <input type="text" id="edit_outlet_code" maxlength="10" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-mono font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent uppercase">
            </div>
            <div>
                <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Description</label>
                <input type="text" id="edit_outlet_desc" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeEditOutlet()" class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-all">Cancel</button>
                <button type="submit" id="editOutletBtn" class="flex-1 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-blue-600 text-white text-sm font-bold shadow-lg shadow-indigo-500/30 hover:shadow-indigo-500/50 transition-all">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Client Modal -->
<div id="editClientModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm" onclick="if(event.target===this)closeEditClient()">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-full max-w-lg mx-4 p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <i data-lucide="building-2" class="w-5 h-5 text-indigo-500"></i> Edit Client
            </h3>
            <button onclick="closeEditClient()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-all">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form onsubmit="event.preventDefault(); saveEditClient();" class="space-y-4">
            <input type="hidden" id="edit_client_id">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Client Name *</label>
                    <input type="text" id="edit_client_name" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                </div>
                <div>
                    <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Contact Person</label>
                    <input type="text" id="edit_client_contact" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                </div>
                <div>
                    <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Email</label>
                    <input type="email" id="edit_client_email" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                </div>
                <div>
                    <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Phone</label>
                    <input type="text" id="edit_client_phone" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                </div>
                <div>
                    <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Industry / Sector</label>
                    <select id="edit_client_industry" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500">
                        <option value="">Select Sector...</option>
                        <?php foreach ($SECTORS as $skey => $scfg): ?>
                        <option value="<?php echo $skey; ?>"><?php echo htmlspecialchars($scfg['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs font-bold uppercase text-slate-500 mb-1 block">Address</label>
                    <input type="text" id="edit_client_address" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeEditClient()" class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-all">Cancel</button>
                <button type="submit" id="editClientBtn" class="flex-1 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-blue-600 text-white text-sm font-bold shadow-lg shadow-indigo-500/30 hover:shadow-indigo-500/50 transition-all">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function companySetup() {
    return {
        activeTab: (window.location.hash === '#outlets') ? 'outlets' : 'clients',
        saving: false,
        sectors: <?php echo $js_sectors; ?>,
        activeSector: '<?php echo addslashes($active_sector_key); ?>',
        clientForm: { name: '', contact_person: '', email: '', phone: '', address: '', industry: '' },
        outletForm: { name: '', type: Object.keys(<?php echo json_encode($active_sector['outlet_types']); ?>)[0] || 'other', code: '', description: '', custom_type: '', kitchen_count: '1' },

        switchTab(tab) {
            this.activeTab = tab;
            window.location.hash = tab;
        },

        async addClient() {
            if (!this.clientForm.name) return;
            this.saving = true;
            const fd = new FormData();
            fd.append('action', 'add_client');
            Object.keys(this.clientForm).forEach(k => fd.append(k, this.clientForm[k]));
            try {
                const res = await fetch('../ajax/company_setup_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.message || 'Error creating client');
                }
            } catch(e) { alert('Error: ' + e.message); }
            this.saving = false;
        },

        async addOutlet() {
            if (!this.outletForm.name) return;
            if (this.outletForm.type === 'other' && !this.outletForm.custom_type.trim()) {
                alert('Please enter your custom outlet type.');
                return;
            }
            this.saving = true;
            const fd = new FormData();
            fd.append('action', 'add_outlet');
            fd.append('client_id', '<?php echo $active_client_id ?? 0; ?>');
            // Send custom_type as the type when "Other" is selected
            const finalType = this.outletForm.type === 'other' ? this.outletForm.custom_type.trim().toLowerCase().replace(/\s+/g, '_') : this.outletForm.type;
            fd.append('name', this.outletForm.name);
            fd.append('type', finalType);
            fd.append('code', this.outletForm.code);
            // Send kitchen_count for restaurant outlets (only when sector supports kitchens)
            const sectorCfg = this.sectors[this.activeSector];
            if (sectorCfg?.has_kitchen && finalType === 'restaurant') {
                fd.append('kitchen_count', this.outletForm.kitchen_count);
            }
            fd.append('description', this.outletForm.description);
            try {
                const res = await fetch('../ajax/company_setup_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.message || 'Error creating outlet');
                }
            } catch(e) { alert('Error: ' + e.message); }
            this.saving = false;
        }
    }
}

async function toggleClient(id) {
    if (!confirm('Toggle this client\'s status (suspend/activate)?')) return;
    const fd = new FormData();
    fd.append('action', 'toggle_client');
    fd.append('client_id', id);
    const res = await fetch('../ajax/company_setup_api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.message);
}

function openEditClient(client) {
    document.getElementById('edit_client_id').value = client.id;
    document.getElementById('edit_client_name').value = client.name;
    document.getElementById('edit_client_contact').value = client.contact_person || '';
    document.getElementById('edit_client_email').value = client.email || '';
    document.getElementById('edit_client_phone').value = client.phone || '';
    document.getElementById('edit_client_address').value = client.address || '';
    document.getElementById('edit_client_industry').value = client.industry || '';
    const modal = document.getElementById('editClientModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeEditClient() {
    const modal = document.getElementById('editClientModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

async function saveEditClient() {
    const id = document.getElementById('edit_client_id').value;
    const name = document.getElementById('edit_client_name').value.trim();
    if (!name) { alert('Client name is required'); return; }

    const btn = document.getElementById('editClientBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    const fd = new FormData();
    fd.append('action', 'update_client');
    fd.append('client_id', id);
    fd.append('name', name);
    fd.append('contact_person', document.getElementById('edit_client_contact').value.trim());
    fd.append('email', document.getElementById('edit_client_email').value.trim());
    fd.append('phone', document.getElementById('edit_client_phone').value.trim());
    fd.append('address', document.getElementById('edit_client_address').value.trim());
    fd.append('industry', document.getElementById('edit_client_industry').value.trim());

    try {
        const res = await fetch('../ajax/company_setup_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeEditClient();
            location.reload();
        } else {
            alert(data.message || 'Error updating client');
        }
    } catch(e) { alert('Error: ' + e.message); }
    btn.disabled = false;
    btn.textContent = 'Save Changes';
}

async function deleteClient(id, name) {
    if (!confirm(`Are you sure you want to delete the client "${name}"?\n\nThis will also delete all associated outlets. This action cannot be undone.`)) return;
    
    const fd = new FormData();
    fd.append('action', 'delete_client');
    fd.append('client_id', id);

    try {
        const res = await fetch('../ajax/company_setup_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Error deleting client');
        }
    } catch(e) { alert('Error: ' + e.message); }
}

async function toggleOutlet(id) {
    if (!confirm('Toggle this outlet\'s status?')) return;
    const fd = new FormData();
    fd.append('action', 'toggle_outlet');
    fd.append('outlet_id', id);
    const res = await fetch('../ajax/company_setup_api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.message);
}

function openEditOutlet(outlet) {
    document.getElementById('edit_outlet_id').value = outlet.id;
    document.getElementById('edit_outlet_name').value = outlet.name;
    document.getElementById('edit_outlet_type').value = outlet.type;
    document.getElementById('edit_outlet_code').value = outlet.code;
    document.getElementById('edit_outlet_desc').value = outlet.description || '';
    const modal = document.getElementById('editOutletModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    // Re-render Lucide icons inside modal
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeEditOutlet() {
    const modal = document.getElementById('editOutletModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

async function saveEditOutlet() {
    const id = document.getElementById('edit_outlet_id').value;
    const name = document.getElementById('edit_outlet_name').value.trim();
    if (!name) { alert('Outlet name is required'); return; }

    const btn = document.getElementById('editOutletBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    const fd = new FormData();
    fd.append('action', 'update_outlet');
    fd.append('outlet_id', id);
    fd.append('name', name);
    fd.append('type', document.getElementById('edit_outlet_type').value.trim().toLowerCase().replace(/\s+/g, '_'));
    fd.append('code', document.getElementById('edit_outlet_code').value.trim());
    fd.append('description', document.getElementById('edit_outlet_desc').value.trim());

    try {
        const res = await fetch('../ajax/company_setup_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeEditOutlet();
            location.reload();
        } else {
            alert(data.message || 'Error updating outlet');
        }
    } catch(e) { alert('Error: ' + e.message); }
    btn.disabled = false;
    btn.textContent = 'Save Changes';
}

async function deleteOutlet(id, name) {
    if (!confirm(`Are you sure you want to delete the outlet "${name}"?\n\nThis action cannot be undone.`)) return;
    
    const fd = new FormData();
    fd.append('action', 'delete_outlet');
    fd.append('outlet_id', id);

    try {
        const res = await fetch('../ajax/company_setup_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Error deleting outlet');
        }
    } catch(e) { alert('Error: ' + e.message); }
}
</script>

<?php include '../includes/dashboard_scripts.php'; ?>
</body>
</html>
