<?php
/**
 * MIAUDITOPS — Settings & Configuration
 * Tabs: Company Profile, User Management, Expense Categories, System Config
 */
require_once '../includes/functions.php';
require_login();
require_subscription('settings');
require_permission('settings');
$company_id = $_SESSION['company_id'];
$user_id    = $_SESSION['user_id'];
$user_role  = get_user_role();
$page_title = 'Settings';

// Company
$company = get_company($company_id);
$current_user = get_user($user_id);

// Users with permission & client counts
$stmt = $pdo->prepare("SELECT u.*,
    (SELECT COUNT(*) FROM user_permissions up WHERE up.user_id = u.id) as perm_count,
    (SELECT COUNT(*) FROM user_clients uc JOIN clients c ON c.id = uc.client_id AND c.deleted_at IS NULL WHERE uc.user_id = u.id) as client_count
    FROM users u WHERE u.company_id = ? AND u.deleted_at IS NULL ORDER BY u.role, u.first_name");
$stmt->execute([$company_id]);
$users = $stmt->fetchAll();

// Enrich users with their permissions and client IDs for JS
foreach ($users as &$u) {
    $u['permissions'] = get_user_permissions($u['id']);
    $u['client_ids']  = get_user_clients($u['id'], $company_id);
}
unset($u);

// All clients for assignment dropdown
$all_clients = get_clients($company_id);

// All available permissions
$all_permissions = get_all_permissions();

// Expense categories
$cat_stmt = $pdo->prepare("SELECT * FROM expense_categories WHERE company_id = ? AND deleted_at IS NULL ORDER BY name");
$cat_stmt->execute([$company_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

$roles = [
    'business_owner' => 'Business Owner',
    'auditor' => 'Auditor',
    'finance_officer' => 'Finance Officer',
    'store_officer' => 'Store Officer',
    'department_head' => 'Department Head',
    'hod' => 'HOD (Requisition Approver)',
    'ceo' => 'CEO (Final Approver)',
    'viewer' => 'Viewer (Read Only)',
];

$js_users      = json_encode($users, JSON_HEX_TAG | JSON_HEX_APOS);
$js_clients    = json_encode($all_clients, JSON_HEX_TAG | JSON_HEX_APOS);
$js_all_perms  = json_encode($all_permissions, JSON_HEX_TAG | JSON_HEX_APOS);

// ── Tab-lock enforcement ──
$settings_tabs = ['company', 'users', 'trail', 'config'];
$js_allowed_tabs = json_encode(array_values(array_filter($settings_tabs, function($t) {
    return subscription_allows_tab('settings', $t);
})));
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>[x-cloak]{display:none!important}.glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95) 0%,rgba(249,250,251,0.9) 100%);backdrop-filter:blur(20px)}.dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95) 0%,rgba(30,41,59,0.9) 100%)}</style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="settingsApp()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8">
            <?php display_flash_message(); ?>

            <!-- Tabs -->
            <div class="mb-6 flex flex-wrap gap-1.5 p-1.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700">
                <template x-for="t in tabs" :key="t.id">
                    <button @click="!t.locked && (currentTab = t.id)" :class="t.locked ? 'opacity-50 cursor-not-allowed text-slate-400 dark:text-slate-600 border-transparent' : (currentTab === t.id ? 'bg-white dark:bg-slate-900 text-slate-800 dark:text-white shadow-sm border-slate-300' : 'text-slate-500 hover:bg-white/50 border-transparent')" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all border" :title="t.locked ? 'Upgrade your plan to access ' + t.label : ''">
                        <i :data-lucide="t.locked ? 'lock' : t.icon" class="w-3.5 h-3.5"></i><span x-text="t.label"></span>
                        <template x-if="t.locked"><span class="ml-1 px-1 py-0.5 rounded text-[7px] font-black tracking-wider bg-gradient-to-r from-violet-500 to-amber-500 text-white">PRO</span></template>
                    </button>
                </template>
            </div>

            <!-- ========== TAB: Company Profile ========== -->
            <div x-show="currentTab === 'company'" x-transition>
                <div class="max-w-2xl">
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/10 to-transparent">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/30"><i data-lucide="building" class="w-4 h-4 text-white"></i></div>
                                <div><h3 class="font-bold text-slate-900 dark:text-white text-sm">Company Details</h3><p class="text-xs text-slate-500">Code: <span class="font-mono font-bold text-indigo-600"><?php echo htmlspecialchars($company['code'] ?? 'N/A'); ?></span></p></div>
                            </div>
                        </div>
                        <form @submit.prevent="updateCompany()" class="p-6 space-y-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Company Name *</label><input type="text" x-model="companyForm.name" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"></div>
                            <div class="grid grid-cols-2 gap-4">
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Email *</label><input type="email" x-model="companyForm.email" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Phone</label><input type="text" x-model="companyForm.phone" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            </div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Address</label><textarea x-model="companyForm.address" rows="2" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></textarea></div>
                            <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white font-bold rounded-xl shadow-lg shadow-blue-500/30 hover:scale-[1.02] transition-all text-sm">Save Changes</button>
                        </form>
                    </div>

                    <!-- Password Change -->
                    <div class="mt-6 glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800">
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm">Change Password</h3>
                        </div>
                        <form @submit.prevent="changePassword()" class="p-6 space-y-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Current Password *</label><input type="password" x-model="pwForm.current" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div class="grid grid-cols-2 gap-4">
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">New Password *</label><input type="password" x-model="pwForm.new_password" required minlength="6" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                                <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Confirm Password *</label><input type="password" x-model="pwForm.confirm" required class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            </div>
                            <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-slate-700 to-slate-900 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm">Update Password</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: User Management ========== -->
            <div x-show="currentTab === 'users'" x-transition>

                <!-- Header Row -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg font-bold text-slate-800 dark:text-white">Team Members</h2>
                        <p class="text-xs text-slate-500" x-text="usersList.length + ' users registered'"></p>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-500 cursor-pointer select-none">
                            <input type="checkbox" @change="toggleSelectAll($event.target.checked)" :checked="selectedUsers.length === usersList.filter(u => u.id != <?php echo $user_id; ?>).length && selectedUsers.length > 0" class="w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            Select All
                        </label>
                        <button @click="openAddUser()" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl shadow-lg shadow-emerald-500/30 hover:scale-[1.02] transition-all text-sm">
                            <i data-lucide="user-plus" class="w-4 h-4"></i> Add User
                        </button>
                    </div>
                </div>

                <!-- Bulk Actions Toolbar -->
                <div x-show="selectedUsers.length > 0" x-transition class="mb-4 p-3 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800/40 rounded-xl flex items-center justify-between gap-3 flex-wrap">
                    <p class="text-xs font-bold text-indigo-700 dark:text-indigo-300">
                        <span x-text="selectedUsers.length"></span> user(s) selected
                    </p>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button @click="bulkToggle(0)" class="px-3 py-1.5 bg-amber-500 text-white text-[11px] font-bold rounded-lg hover:bg-amber-600 transition-colors">⏸ Deactivate</button>
                        <button @click="bulkToggle(1)" class="px-3 py-1.5 bg-emerald-500 text-white text-[11px] font-bold rounded-lg hover:bg-emerald-600 transition-colors">▶ Activate</button>
                        <select x-model="bulkRole" class="px-2 py-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-[11px] font-bold">
                            <option value="">Change Role…</option>
                            <?php foreach ($roles as $val => $label): ?><option value="<?php echo $val; ?>"><?php echo $label; ?></option><?php endforeach; ?>
                        </select>
                        <button @click="bulkChangeRole()" :disabled="!bulkRole" class="px-3 py-1.5 bg-violet-500 text-white text-[11px] font-bold rounded-lg hover:bg-violet-600 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">Apply Role</button>
                        <button @click="bulkDelete()" class="px-3 py-1.5 bg-red-500 text-white text-[11px] font-bold rounded-lg hover:bg-red-600 transition-colors">🗑 Delete</button>
                    </div>
                </div>

                <!-- Users Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <template x-for="u in usersList" :key="u.id">
                        <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden hover:shadow-xl transition-shadow" :class="selectedUsers.includes(u.id) ? 'ring-2 ring-indigo-500' : ''">
                            <!-- User Card Header -->
                            <div class="p-5">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex items-center gap-3">
                                        <!-- Bulk Select Checkbox -->
                                        <template x-if="u.id != <?php echo $user_id; ?>">
                                            <input type="checkbox" :checked="selectedUsers.includes(u.id)" @change="toggleSelect(u.id)" @click.stop class="w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 shrink-0">
                                        </template>
                                        <div class="w-11 h-11 rounded-full flex items-center justify-center text-sm font-bold text-white shadow-lg" :class="getRoleGradient(u.role)" x-text="u.first_name?.charAt(0) + u.last_name?.charAt(0)"></div>
                                        <div>
                                            <p class="font-bold text-sm text-slate-800 dark:text-white" x-text="u.first_name + ' ' + u.last_name"></p>
                                            <p class="text-[11px] text-slate-500" x-text="u.email"></p>
                                        </div>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="u.is_active == 1 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'" x-text="u.is_active == 1 ? 'Active' : 'Inactive'"></span>
                                </div>

                                <!-- Role & Department Badge -->
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="getRoleBadge(u.role)" x-text="u.role?.replace(/_/g,' ')"></span>
                                    <span x-show="u.department" class="text-[10px] text-slate-400" x-text="u.department"></span>
                                </div>

                                <!-- Stats Row -->
                                <div class="grid grid-cols-2 gap-2 mb-4">
                                    <div class="p-2 bg-slate-50 dark:bg-slate-800/50 rounded-lg text-center">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Clients</p>
                                        <p class="text-sm font-black text-indigo-600" x-text="u.client_ids?.length || 0"></p>
                                    </div>
                                    <div class="p-2 bg-slate-50 dark:bg-slate-800/50 rounded-lg text-center">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Permissions</p>
                                        <p class="text-sm font-black text-violet-600" x-text="u.permissions?.length || 0"></p>
                                    </div>
                                    <div class="p-2 bg-slate-50 dark:bg-slate-800/50 rounded-lg text-center">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Phone</p>
                                        <p class="text-[11px] font-semibold text-slate-600 dark:text-slate-400 truncate" x-text="u.phone || '—'"></p>
                                    </div>
                                    <div class="p-2 bg-slate-50 dark:bg-slate-800/50 rounded-lg text-center">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Last Login</p>
                                        <p class="text-[11px] font-semibold truncate" :class="u.last_login ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'" x-text="u.last_login ? formatLastLogin(u.last_login) : 'Never'"></p>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex gap-2">
                                    <button @click="openEditUser(u)" class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 text-[11px] font-bold rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/40 transition-colors">
                                        <i data-lucide="edit-3" class="w-3 h-3"></i> Edit
                                    </button>
                                    <button @click="openPermissions(u)" class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 bg-violet-50 dark:bg-violet-900/20 text-violet-700 dark:text-violet-400 text-[11px] font-bold rounded-lg hover:bg-violet-100 dark:hover:bg-violet-900/40 transition-colors">
                                        <i data-lucide="shield" class="w-3 h-3"></i> Permissions
                                    </button>
                                    <button @click="openClientAssign(u)" class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-400 text-[11px] font-bold rounded-lg hover:bg-indigo-100 dark:hover:bg-indigo-900/40 transition-colors">
                                        <i data-lucide="building" class="w-3 h-3"></i> Clients
                                    </button>
                                </div>
                            </div>

                            <!-- Footer Actions -->
                            <template x-if="u.id != <?php echo $user_id; ?>">
                                <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/20">
                                    <button @click="toggleUserStatus(u.id, u.is_active)" class="text-[11px] font-bold" :class="u.is_active == 1 ? 'text-amber-600 hover:text-amber-700' : 'text-emerald-600 hover:text-emerald-700'" x-text="u.is_active == 1 ? '⏸ Deactivate' : '▶ Activate'"></button>
                                    <div class="flex gap-3">
                                        <button @click="openResetPassword(u)" class="text-[11px] font-bold text-slate-500 hover:text-blue-600 transition-colors">🔑 Reset PW</button>
                                        <button @click="deleteUser(u.id, u.first_name + ' ' + u.last_name)" class="text-[11px] font-bold text-slate-400 hover:text-red-600 transition-colors">🗑 Delete</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                    <div x-show="usersList.length === 0" class="col-span-full p-12 text-center text-slate-400">No users</div>
                </div>
            </div>

            <!-- ========== MODAL: Add / Edit User ========== -->
            <div x-show="showUserModal" x-transition.opacity class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="showUserModal = false">
                <div x-show="showUserModal" x-transition class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto border border-slate-200 dark:border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500/10 to-transparent flex items-center justify-between sticky top-0 bg-white dark:bg-slate-900 z-10">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg"><i data-lucide="user-plus" class="w-4 h-4 text-white"></i></div>
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="editingUserId ? 'Edit User' : 'Add New User'"></h3>
                        </div>
                        <button @click="showUserModal = false" class="p-1 text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
                    </div>
                    <form @submit.prevent="editingUserId ? saveEditUser() : addUser()" class="p-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">First Name *</label><input type="text" x-model="userForm.first_name" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Last Name *</label><input type="text" x-model="userForm.last_name" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        </div>
                        <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Email *</label><input type="email" x-model="userForm.email" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Phone</label><input type="text" x-model="userForm.phone" class="w-full px-3 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Department</label><input type="text" x-model="userForm.department" class="w-full px-3 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        </div>
                        <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Role *</label>
                            <select x-model="userForm.role" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                <?php foreach ($roles as $val => $label): ?><option value="<?php echo $val; ?>"><?php echo $label; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <template x-if="!editingUserId">
                            <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">Password *</label><input type="password" x-model="userForm.password" required minlength="6" class="w-full px-3 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        </template>

                        <!-- Permissions Section (Add only) -->
                        <template x-if="!editingUserId">
                            <div>
                                <label class="text-[11px] font-semibold mb-1 block text-slate-500">Module Permissions</label>
                                <template x-if="userForm.role === 'hod' || userForm.role === 'ceo'">
                                    <div class="p-3 bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-700 text-xs text-amber-700 dark:text-amber-300">
                                        <i data-lucide="info" class="w-3.5 h-3.5 inline -mt-0.5 mr-1"></i>
                                        <strong x-text="userForm.role === 'hod' ? 'HOD' : 'CEO'"></strong> role defaults to <strong>Dashboard</strong>, <strong>Company Setup</strong> &amp; <strong>Requisitions</strong> (approval workflow). You can add more permissions after creating the user.
                                    </div>
                                </template>
                                <div x-show="userForm.role !== 'hod' && userForm.role !== 'ceo'" class="grid grid-cols-2 gap-2 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700">
                                    <template x-for="(info, key) in allPermissions" :key="key">
                                        <label class="flex items-center gap-2 p-2 rounded-lg hover:bg-white dark:hover:bg-slate-700/50 transition-colors cursor-pointer">
                                            <input type="checkbox" :value="key" x-model="userForm.permissions" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                            <div>
                                                <span class="text-xs font-semibold text-slate-700 dark:text-slate-300" x-text="info.label"></span>
                                            </div>
                                        </label>
                                    </template>
                                </div>
                                <div class="mt-2 p-2.5 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-700 text-[10px] text-blue-600 dark:text-blue-400">
                                    <i data-lucide="info" class="w-3 h-3 inline -mt-0.5 mr-1"></i>
                                    <strong>Tip:</strong> <strong>Dashboard</strong> &amp; <strong>Company Setup</strong> are essential for access. After creating the user, click <strong>Permissions</strong> on their card to review and adjust module access.
                                </div>
                            </div>
                        </template>

                        <!-- Client Assignment (Add only) -->
                        <template x-if="!editingUserId && allClients.length > 0">
                            <div>
                                <label class="text-[11px] font-semibold mb-2 block text-slate-500">Assign to Clients</label>
                                <div class="grid grid-cols-2 gap-2 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 max-h-40 overflow-y-auto">
                                    <template x-for="c in allClients" :key="c.id">
                                        <label class="flex items-center gap-2 p-2 rounded-lg hover:bg-white dark:hover:bg-slate-700/50 transition-colors cursor-pointer">
                                            <input type="checkbox" :value="String(c.id)" x-model="userForm.client_ids" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300" x-text="c.name"></span>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <button type="submit" class="w-full py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl shadow-lg shadow-emerald-500/30 hover:scale-[1.02] transition-all text-sm" x-text="editingUserId ? 'Save Changes' : 'Create User'"></button>
                    </form>
                </div>
            </div>

            <!-- ========== MODAL: Permissions ========== -->
            <div x-show="showPermModal" x-transition.opacity class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="showPermModal = false">
                <div x-show="showPermModal" x-transition class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md border border-slate-200 dark:border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-violet-500/10 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg"><i data-lucide="shield" class="w-4 h-4 text-white"></i></div>
                            <div>
                                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Module Permissions</h3>
                                <p class="text-[11px] text-slate-500" x-text="permUserName"></p>
                            </div>
                        </div>
                        <button @click="showPermModal = false" class="p-1 text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
                    </div>
                    <div class="p-5 space-y-2 max-h-[60vh] overflow-y-auto">
                        <template x-for="(info, key) in allPermissions" :key="key">
                            <div>
                                <label class="flex items-center justify-between p-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors cursor-pointer border border-transparent hover:border-slate-200 dark:hover:border-slate-700">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-500/20 to-purple-500/20 flex items-center justify-center">
                                            <i :data-lucide="info.icon" class="w-4 h-4 text-violet-600"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-700 dark:text-slate-300" x-text="info.label"></p>
                                            <p class="text-[10px] text-slate-400" x-text="info.desc"></p>
                                        </div>
                                    </div>
                                    <div class="relative">
                                        <input type="checkbox" :value="key" x-model="permSelectedPerms" class="sr-only peer">
                                        <div class="w-10 h-5 bg-slate-200 peer-checked:bg-violet-500 rounded-full transition-colors cursor-pointer after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-transform peer-checked:after:translate-x-5 shadow-inner"></div>
                                    </div>
                                </label>
                                <!-- Requisition Sub-Permissions -->
                                <template x-if="key === 'requisitions' && permSelectedPerms.includes('requisitions')">
                                    <div class="ml-11 mr-3 mb-2 p-3 bg-indigo-50/50 dark:bg-indigo-900/10 rounded-xl border border-indigo-200/60 dark:border-indigo-800/40">
                                        <p class="text-[10px] font-bold uppercase text-indigo-400 mb-2">Requisition Access Level</p>
                                        <div class="grid grid-cols-2 gap-1.5">
                                            <label class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-white dark:hover:bg-slate-800 cursor-pointer transition-colors">
                                                <input type="checkbox" value="requisitions.submit" x-model="permSelectedPerms" class="w-3.5 h-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                                <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">Submit</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-white dark:hover:bg-slate-800 cursor-pointer transition-colors">
                                                <input type="checkbox" value="requisitions.approve_hod" x-model="permSelectedPerms" class="w-3.5 h-3.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                                <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">Approve (HOD)</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-white dark:hover:bg-slate-800 cursor-pointer transition-colors">
                                                <input type="checkbox" value="requisitions.approve_audit" x-model="permSelectedPerms" class="w-3.5 h-3.5 rounded border-slate-300 text-violet-600 focus:ring-violet-500">
                                                <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">Approve (Audit)</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-white dark:hover:bg-slate-800 cursor-pointer transition-colors">
                                                <input type="checkbox" value="requisitions.approve_ceo" x-model="permSelectedPerms" class="w-3.5 h-3.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                                <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">Approve (CEO)</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-white dark:hover:bg-slate-800 cursor-pointer transition-colors">
                                                <input type="checkbox" value="requisitions.purchase_orders" x-model="permSelectedPerms" class="w-3.5 h-3.5 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">
                                                <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">Purchase Orders</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-white dark:hover:bg-slate-800 cursor-pointer transition-colors">
                                                <input type="checkbox" value="requisitions.verify" x-model="permSelectedPerms" class="w-3.5 h-3.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                                <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">Verify Delivery</span>
                                            </label>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex gap-2">
                            <button @click="permSelectedPerms = Object.keys(allPermissions)" class="px-3 py-1.5 text-[11px] font-bold text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors">Select All</button>
                            <button @click="permSelectedPerms = []" class="px-3 py-1.5 text-[11px] font-bold text-slate-500 hover:bg-slate-100 rounded-lg transition-colors">Clear All</button>
                        </div>
                        <button @click="savePermissions()" class="px-5 py-2 bg-gradient-to-r from-violet-500 to-purple-600 text-white font-bold rounded-xl shadow-lg text-sm hover:scale-[1.02] transition-all">Save Permissions</button>
                    </div>
                </div>
            </div>

            <!-- ========== MODAL: Client Assignment ========== -->
            <div x-show="showClientModal" x-transition.opacity class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="showClientModal = false">
                <div x-show="showClientModal" x-transition class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md border border-slate-200 dark:border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-indigo-500/10 to-transparent flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center shadow-lg"><i data-lucide="building" class="w-4 h-4 text-white"></i></div>
                            <div>
                                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Client Assignments</h3>
                                <p class="text-[11px] text-slate-500" x-text="clientUserName"></p>
                            </div>
                        </div>
                        <button @click="showClientModal = false" class="p-1 text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
                    </div>
                    <div class="p-5 space-y-2 max-h-[60vh] overflow-y-auto">
                        <template x-for="c in allClients" :key="c.id">
                            <label class="flex items-center justify-between p-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors cursor-pointer border border-transparent hover:border-slate-200 dark:hover:border-slate-700">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center text-white text-[10px] font-bold" x-text="c.name?.substring(0,2).toUpperCase()"></div>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-300" x-text="c.name"></p>
                                        <p class="text-[10px] text-slate-400" x-text="(c.outlet_count || 0) + ' outlets'"></p>
                                    </div>
                                </div>
                                <div class="relative">
                                    <input type="checkbox" :value="String(c.id)" x-model="clientSelectedIds" class="sr-only peer">
                                    <div class="w-10 h-5 bg-slate-200 peer-checked:bg-indigo-500 rounded-full transition-colors cursor-pointer after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-transform peer-checked:after:translate-x-5 shadow-inner"></div>
                                </div>
                            </label>
                        </template>
                        <div x-show="allClients.length === 0" class="p-8 text-center text-slate-400 text-sm">No clients created yet. Add clients in Company Setup.</div>
                    </div>
                    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex gap-2">
                            <button @click="clientSelectedIds = allClients.map(c => String(c.id))" class="px-3 py-1.5 text-[11px] font-bold text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors">Select All</button>
                            <button @click="clientSelectedIds = []" class="px-3 py-1.5 text-[11px] font-bold text-slate-500 hover:bg-slate-100 rounded-lg transition-colors">Clear All</button>
                        </div>
                        <button @click="saveClientAssignments()" class="px-5 py-2 bg-gradient-to-r from-indigo-500 to-blue-600 text-white font-bold rounded-xl shadow-lg text-sm hover:scale-[1.02] transition-all">Save Assignments</button>
                    </div>
                </div>
            </div>

            <!-- ========== MODAL: Reset Password ========== -->
            <div x-show="showResetModal" x-transition.opacity class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="showResetModal = false">
                <div x-show="showResetModal" x-transition class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm border border-slate-200 dark:border-slate-700">
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="font-bold text-slate-900 dark:text-white text-sm">Reset Password</h3>
                        <p class="text-[11px] text-slate-500" x-text="resetUserName"></p>
                    </div>
                    <form @submit.prevent="resetPassword()" class="p-6 space-y-4">
                        <div><label class="text-[11px] font-semibold mb-1 block text-slate-500">New Password *</label><input type="password" x-model="resetPw" required minlength="6" class="w-full px-3 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></div>
                        <button type="submit" class="w-full py-2.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white font-bold rounded-xl shadow-lg text-sm">Reset Password</button>
                    </form>
                </div>
            </div>

            <!-- ========== TAB: Audit Trail ========== -->
            <div x-show="currentTab === 'trail'" x-transition x-init="$watch('currentTab', v => { if (v === 'trail' && auditLogs.length === 0) fetchAuditLogs(); })">
                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-violet-500/10 to-transparent">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/30"><i data-lucide="scroll-text" class="w-4 h-4 text-white"></i></div>
                                <div>
                                    <h3 class="font-bold text-slate-900 dark:text-white text-sm">Audit Trail</h3>
                                    <p class="text-[10px] text-slate-400" x-text="'Total: ' + auditTotal + ' entries'"></p>
                                </div>
                            </div>
                            <button @click="fetchAuditLogs()" class="p-2 rounded-lg text-slate-400 hover:text-violet-600 hover:bg-violet-50 dark:hover:bg-violet-900/20 transition-all" title="Refresh">
                                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="px-6 py-4 bg-slate-50/50 dark:bg-slate-800/30 border-b border-slate-100 dark:border-slate-800">
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                            <div>
                                <label class="text-[10px] font-bold uppercase text-slate-400 mb-1 block">From</label>
                                <input type="date" x-model="auditFilters.date_from" @change="fetchAuditLogs()" class="w-full px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-slate-400 mb-1 block">To</label>
                                <input type="date" x-model="auditFilters.date_to" @change="fetchAuditLogs()" class="w-full px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-slate-400 mb-1 block">Module</label>
                                <select x-model="auditFilters.module" @change="fetchAuditLogs()" class="w-full px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                    <option value="">All Modules</option>
                                    <option value="setup">Company Setup</option>
                                    <option value="audit">Daily Audit</option>
                                    <option value="stock">Stock Audit</option>
                                    <option value="finance">Finance</option>
                                    <option value="requisitions">Requisitions</option>
                                    <option value="settings">Settings</option>
                                    <option value="core">Core / System</option>
                                    <option value="trash">Trash</option>
                                    <option value="billing">Billing</option>
                                    <option value="support">Support</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-slate-400 mb-1 block">User</label>
                                <select x-model="auditFilters.user_id" @change="fetchAuditLogs()" class="w-full px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                    <option value="">All Users</option>
                                    <template x-for="u in usersList" :key="u.id">
                                        <option :value="u.id" x-text="u.first_name + ' ' + u.last_name"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold uppercase text-slate-400 mb-1 block">Search</label>
                                <input type="text" x-model="auditFilters.search" @input.debounce.400ms="fetchAuditLogs()" placeholder="Search actions..." class="w-full px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                            </div>
                            <div class="flex items-end">
                                <button @click="auditFilters = {date_from:'', date_to:'', module:'', user_id:'', search:''}; fetchAuditLogs()" class="w-full px-2.5 py-1.5 text-xs font-bold text-slate-500 hover:text-red-600 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg hover:border-red-300 transition-all">
                                    Clear Filters
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Audit Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">Time</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">User</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">Action</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">Module</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">Details</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="log in auditLogs" :key="log.id">
                                    <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                        <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap" x-text="formatAuditDate(log.created_at)"></td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-2">
                                                <div class="w-6 h-6 rounded-full bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center text-white text-[9px] font-bold" x-text="(log.first_name || '?').charAt(0) + (log.last_name || '').charAt(0)"></div>
                                                <span class="text-xs font-semibold text-slate-700 dark:text-slate-300" x-text="(log.first_name || 'System') + ' ' + (log.last_name || '')"></span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold capitalize" :class="getActionBadge(log.action)" x-text="log.action.replace(/_/g, ' ')"></span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="text-xs font-semibold text-slate-500 capitalize" x-text="(log.module || '—').replace(/_/g, ' ')"></span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-500 max-w-xs truncate" x-text="log.details || '—'" :title="log.details"></td>
                                        <td class="px-4 py-3 text-[10px] font-mono text-slate-400" x-text="log.ip_address || '—'"></td>
                                    </tr>
                                </template>
                                <tr x-show="auditLogs.length === 0 && !auditLoading">
                                    <td colspan="6" class="px-4 py-12 text-center">
                                        <div class="flex flex-col items-center gap-2">
                                            <div class="w-12 h-12 rounded-xl bg-violet-50 dark:bg-violet-900/20 flex items-center justify-center"><i data-lucide="scroll-text" class="w-6 h-6 text-violet-400"></i></div>
                                            <p class="text-sm font-bold text-slate-500">No audit entries found</p>
                                            <p class="text-xs text-slate-400">Try adjusting your filters</p>
                                        </div>
                                    </td>
                                </tr>
                                <tr x-show="auditLoading">
                                    <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-400">Loading audit entries...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div x-show="auditTotalPages > 1" class="px-6 py-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/30">
                        <p class="text-[10px] text-slate-400" x-text="'Page ' + auditPage + ' of ' + auditTotalPages + ' (' + auditTotal + ' entries)'"></p>
                        <div class="flex gap-1">
                            <button @click="auditPage = Math.max(1, auditPage - 1); fetchAuditLogs()" :disabled="auditPage <= 1" class="px-2.5 py-1 rounded-lg text-xs font-bold border border-slate-200 dark:border-slate-700 hover:bg-white dark:hover:bg-slate-800 disabled:opacity-40 transition-all">← Prev</button>
                            <button @click="auditPage = Math.min(auditTotalPages, auditPage + 1); fetchAuditLogs()" :disabled="auditPage >= auditTotalPages" class="px-2.5 py-1 rounded-lg text-xs font-bold border border-slate-200 dark:border-slate-700 hover:bg-white dark:hover:bg-slate-800 disabled:opacity-40 transition-all">Next →</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: System Config ========== -->
            <div x-show="currentTab === 'config'" x-transition>
                <div class="max-w-2xl space-y-6">
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden p-6">
                        <h3 class="font-bold text-slate-900 dark:text-white text-sm mb-4">System Information</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl"><p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Platform</p><p class="text-sm font-bold text-slate-800 dark:text-white">MIAUDITOPS v1.0</p></div>
                            <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl"><p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Company Code</p><p class="text-sm font-mono font-bold text-indigo-600"><?php echo htmlspecialchars($company['code'] ?? 'N/A'); ?></p></div>
                            <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl"><p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Your Role</p><p class="text-sm font-bold capitalize text-slate-800 dark:text-white"><?php echo str_replace('_',' ',$user_role); ?></p></div>
                            <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl"><p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Total Users</p><p class="text-sm font-bold text-slate-800 dark:text-white"><?php echo count($users); ?></p></div>
                            <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl"><p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Expense Categories</p><p class="text-sm font-bold text-slate-800 dark:text-white"><?php echo count($categories); ?></p></div>
                            <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl"><p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Member Since</p><p class="text-sm font-bold text-slate-800 dark:text-white"><?php echo date('M Y', strtotime($company['created_at'])); ?></p></div>
                        </div>
                    </div>
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden p-6">
                        <h3 class="font-bold text-slate-900 dark:text-white text-sm mb-4">Preferences</h3>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                                <div><p class="text-sm font-semibold text-slate-700 dark:text-slate-300">Currency Symbol</p><p class="text-xs text-slate-400">Used in all financial displays</p></div>
                                <span class="text-lg font-black text-emerald-600">₦</span>
                            </div>
                            <div class="flex items-center justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                                <div><p class="text-sm font-semibold text-slate-700 dark:text-slate-300">Financial Year</p><p class="text-xs text-slate-400">January to December</p></div>
                                <span class="text-sm font-bold text-slate-600"><?php echo date('Y'); ?></span>
                            </div>
                            <div class="flex items-center justify-between py-3">
                                <div><p class="text-sm font-semibold text-slate-700 dark:text-slate-300">Multi-tenancy</p><p class="text-xs text-slate-400">Data isolated by company</p></div>
                                <span class="px-3 py-1 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 text-xs font-bold rounded-full">✓ Active</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>
<script>
function settingsApp() {
    return {
        currentTab: (() => { const allowed = <?php echo $js_allowed_tabs; ?>; const hash = location.hash.slice(1); return (hash && allowed.includes(hash)) ? hash : allowed[0] || 'company'; })(),
        tabs: [
            { id: 'company', label: 'Company Profile', icon: 'building' },
            { id: 'users', label: 'User Management', icon: 'users' },
            { id: 'trail', label: 'Audit Trail', icon: 'scroll-text' },
            { id: 'config', label: 'System Config', icon: 'settings' },
        ].map(t => ({ ...t, locked: !<?php echo $js_allowed_tabs; ?>.includes(t.id) })),
        usersList: <?php echo $js_users; ?>,
        allClients: <?php echo $js_clients; ?>,
        allPermissions: <?php echo $js_all_perms; ?>,
        companyForm: { name: <?php echo json_encode($company['name']); ?>, email: <?php echo json_encode($company['email']); ?>, phone: <?php echo json_encode($company['phone'] ?? ''); ?>, address: <?php echo json_encode($company['address'] ?? ''); ?> },
        pwForm: { current:'', new_password:'', confirm:'' },
        userForm: { first_name:'', last_name:'', email:'', phone:'', role:'department_head', department:'', password:'', permissions: [], client_ids: [] },

        // Bulk operations
        selectedUsers: [],
        bulkRole: '',

        // Modal states
        showUserModal: false,
        editingUserId: null,
        showPermModal: false,
        permUserId: null,
        permUserName: '',
        permSelectedPerms: [],
        showClientModal: false,
        clientUserId: null,
        clientUserName: '',
        clientSelectedIds: [],
        showResetModal: false,
        resetUserId: null,
        resetUserName: '',
        resetPw: '',

        init() {
            this.$watch('currentTab', (val) => { location.hash = val; setTimeout(() => lucide.createIcons(), 50); });
            window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (h && this.tabs.some(t => t.id === h)) this.currentTab = h; });
        },

        getRoleGradient(r) { return { business_owner:'bg-gradient-to-br from-violet-500 to-purple-600', auditor:'bg-gradient-to-br from-blue-500 to-indigo-600', finance_officer:'bg-gradient-to-br from-emerald-500 to-teal-600', store_officer:'bg-gradient-to-br from-amber-500 to-orange-600', department_head:'bg-gradient-to-br from-slate-500 to-slate-700', hod:'bg-gradient-to-br from-rose-500 to-pink-600', ceo:'bg-gradient-to-br from-yellow-500 to-amber-600', viewer:'bg-gradient-to-br from-gray-400 to-gray-500' }[r] || 'bg-gradient-to-br from-slate-500 to-slate-700'; },
        getRoleBadge(r) { return { business_owner:'bg-violet-100 text-violet-700', auditor:'bg-blue-100 text-blue-700', finance_officer:'bg-emerald-100 text-emerald-700', store_officer:'bg-amber-100 text-amber-700', department_head:'bg-slate-100 text-slate-600', hod:'bg-rose-100 text-rose-700', ceo:'bg-yellow-100 text-yellow-700', viewer:'bg-gray-100 text-gray-600' }[r] || 'bg-slate-100 text-slate-600'; },

        // === Company ===
        async updateCompany() {
            const fd = new FormData(); fd.append('action','update_company');
            Object.entries(this.companyForm).forEach(([k,v]) => fd.append(k,v));
            const r = await (await fetch('../ajax/settings_api.php',{method:'POST',body:fd})).json();
            if (r.success) { alert('Company updated!'); location.reload(); } else alert(r.message);
        },
        async changePassword() {
            if (this.pwForm.new_password !== this.pwForm.confirm) { alert('Passwords do not match'); return; }
            const fd = new FormData(); fd.append('action','change_password');
            fd.append('current_password', this.pwForm.current); fd.append('new_password', this.pwForm.new_password);
            const r = await (await fetch('../ajax/settings_api.php',{method:'POST',body:fd})).json();
            if (r.success) { alert('Password changed!'); this.pwForm={current:'',new_password:'',confirm:''}; } else alert(r.message);
        },

        // === User CRUD ===
        openAddUser() {
            this.editingUserId = null;
            this.userForm = { first_name:'', last_name:'', email:'', phone:'', role:'department_head', department:'', password:'', permissions: Object.keys(this.allPermissions), client_ids: this.allClients.map(c => String(c.id)) };
            this.showUserModal = true;
            this.$nextTick(() => lucide.createIcons());
            this.$watch('userForm.role', (val) => {
                if (val === 'hod' || val === 'ceo') {
                    this.userForm.permissions = ['dashboard', 'company_setup', 'requisitions'];
                }
            });
        },
        openEditUser(u) {
            this.editingUserId = u.id;
            this.userForm = { first_name: u.first_name, last_name: u.last_name, email: u.email, phone: u.phone || '', role: u.role, department: u.department || '', password:'', permissions: [], client_ids: [] };
            this.showUserModal = true;
            this.$nextTick(() => lucide.createIcons());
        },
        async addUser() {
            const fd = new FormData(); fd.append('action','add_user');
            ['first_name','last_name','email','phone','role','department','password'].forEach(k => fd.append(k, this.userForm[k]));
            fd.append('permissions', JSON.stringify(this.userForm.permissions));
            fd.append('client_ids', JSON.stringify(this.userForm.client_ids));
            const r = await (await fetch('../ajax/settings_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        async saveEditUser() {
            const fd = new FormData(); fd.append('action','update_user'); fd.append('user_id', this.editingUserId);
            ['first_name','last_name','email','phone','role','department'].forEach(k => fd.append(k, this.userForm[k]));
            const r = await (await fetch('../ajax/settings_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        async toggleUserStatus(uid, active) {
            if (!confirm(active == 1 ? 'Deactivate this user?' : 'Activate this user?')) return;
            const fd = new FormData(); fd.append('action','toggle_user'); fd.append('user_id',uid);
            const r = await (await fetch('../ajax/settings_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        async deleteUser(uid, name) {
            if (!confirm('Permanently delete ' + name + '? This cannot be undone.')) return;
            const fd = new FormData(); fd.append('action','delete_user'); fd.append('user_id',uid);
            const r = await (await fetch('../ajax/settings_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },

        // === Bulk Operations ===
        toggleSelect(uid) {
            const idx = this.selectedUsers.indexOf(uid);
            if (idx > -1) this.selectedUsers.splice(idx, 1);
            else this.selectedUsers.push(uid);
        },
        toggleSelectAll(checked) {
            if (checked) {
                this.selectedUsers = this.usersList.filter(u => u.id != <?php echo $user_id; ?>).map(u => u.id);
            } else {
                this.selectedUsers = [];
            }
        },
        async bulkToggle(activate) {
            const label = activate ? 'activate' : 'deactivate';
            if (!confirm(`${label.charAt(0).toUpperCase() + label.slice(1)} ${this.selectedUsers.length} user(s)?`)) return;
            const fd = new FormData();
            fd.append('action', 'bulk_toggle_users');
            fd.append('user_ids', JSON.stringify(this.selectedUsers));
            fd.append('activate', activate);
            const r = await (await fetch('../ajax/settings_api.php', { method: 'POST', body: fd })).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        async bulkChangeRole() {
            if (!this.bulkRole) return;
            if (!confirm(`Change role to "${this.bulkRole.replace(/_/g, ' ')}" for ${this.selectedUsers.length} user(s)?`)) return;
            const fd = new FormData();
            fd.append('action', 'bulk_change_role');
            fd.append('user_ids', JSON.stringify(this.selectedUsers));
            fd.append('role', this.bulkRole);
            const r = await (await fetch('../ajax/settings_api.php', { method: 'POST', body: fd })).json();
            if (r.success) location.reload(); else alert(r.message);
        },
        async bulkDelete() {
            if (!confirm(`Permanently delete ${this.selectedUsers.length} user(s)? This cannot be undone.`)) return;
            const fd = new FormData();
            fd.append('action', 'bulk_delete_users');
            fd.append('user_ids', JSON.stringify(this.selectedUsers));
            const r = await (await fetch('../ajax/settings_api.php', { method: 'POST', body: fd })).json();
            if (r.success) location.reload(); else alert(r.message);
        },

        // === Date Formatting ===
        formatLastLogin(dt) {
            if (!dt) return 'Never';
            const d = new Date(dt);
            const now = new Date();
            const diff = Math.floor((now - d) / 1000);
            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        },

        // === Permissions ===
        openPermissions(u) {
            this.permUserId = u.id;
            this.permUserName = u.first_name + ' ' + u.last_name;
            this.permSelectedPerms = [...(u.permissions || [])];
            this.showPermModal = true;
            this.$nextTick(() => lucide.createIcons());
        },
        async savePermissions() {
            const fd = new FormData(); fd.append('action','update_user_permissions');
            fd.append('user_id', this.permUserId);
            fd.append('permissions', JSON.stringify(this.permSelectedPerms));
            const r = await (await fetch('../ajax/settings_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },

        // === Client Assignments ===
        openClientAssign(u) {
            this.clientUserId = u.id;
            this.clientUserName = u.first_name + ' ' + u.last_name;
            this.clientSelectedIds = (u.client_ids || []).map(id => String(id));
            this.showClientModal = true;
            this.$nextTick(() => lucide.createIcons());
        },
        async saveClientAssignments() {
            const fd = new FormData(); fd.append('action','update_user_clients');
            fd.append('user_id', this.clientUserId);
            fd.append('client_ids', JSON.stringify(this.clientSelectedIds.map(Number)));
            const r = await (await fetch('../ajax/settings_api.php',{method:'POST',body:fd})).json();
            if (r.success) location.reload(); else alert(r.message);
        },

        // === Reset Password ===
        openResetPassword(u) {
            this.resetUserId = u.id;
            this.resetUserName = u.first_name + ' ' + u.last_name;
            this.resetPw = '';
            this.showResetModal = true;
            this.$nextTick(() => lucide.createIcons());
        },
        async resetPassword() {
            const fd = new FormData(); fd.append('action','reset_user_password');
            fd.append('user_id', this.resetUserId); fd.append('new_password', this.resetPw);
            const r = await (await fetch('../ajax/settings_api.php',{method:'POST',body:fd})).json();
            if (r.success) { alert('Password reset successfully!'); this.showResetModal = false; } else alert(r.message);
        },

        // === Audit Trail ===
        auditLogs: [],
        auditTotal: 0,
        auditPage: 1,
        auditPerPage: 25,
        auditTotalPages: 1,
        auditLoading: false,
        auditFilters: { date_from: '', date_to: '', module: '', user_id: '', search: '' },

        async fetchAuditLogs() {
            this.auditLoading = true;
            const params = new URLSearchParams({
                action: 'get_audit_trail',
                page: this.auditPage,
                per_page: this.auditPerPage,
                ...this.auditFilters
            });
            try {
                const res = await fetch('../ajax/settings_api.php', { method: 'POST', body: params, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
                const data = await res.json();
                if (data.success) {
                    this.auditLogs = data.logs;
                    this.auditTotal = data.total;
                    this.auditTotalPages = data.total_pages;
                    this.$nextTick(() => lucide.createIcons());
                }
            } catch (e) { console.error('Audit fetch error:', e); }
            this.auditLoading = false;
        },

        formatAuditDate(dt) {
            if (!dt) return '—';
            const d = new Date(dt);
            const day = d.getDate().toString().padStart(2, '0');
            const mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
            const h = d.getHours().toString().padStart(2, '0');
            const m = d.getMinutes().toString().padStart(2, '0');
            return `${day} ${mon} ${h}:${m}`;
        },

        getActionBadge(action) {
            if (action.includes('created') || action.includes('add') || action.includes('registered')) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400';
            if (action.includes('updated') || action.includes('update') || action.includes('change') || action.includes('reset')) return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400';
            if (action.includes('deleted') || action.includes('delete')) return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
            if (action.includes('toggled') || action.includes('toggle')) return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
            return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400';
        },
    }
}
</script>
<?php include '../includes/dashboard_scripts.php'; ?>
</body></html>
