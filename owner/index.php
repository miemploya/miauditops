<?php
/**
 * MIAUDITOPS — Owner Portal Dashboard
 * Cross-tenant management console: Companies, Users, Subscriptions
 */
session_start();
if (empty($_SESSION['is_platform_owner'])) { header('Location: login.php'); exit; }
require_once '../config/db.php';
$owner_name = $_SESSION['owner_name'] ?? 'Owner';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Portal — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="font-sans bg-slate-950 text-white min-h-screen" x-data="ownerApp()" x-init="init()">

    <!-- Sidebar -->
    <aside class="fixed inset-y-0 left-0 w-64 bg-slate-900 border-r border-slate-800 flex flex-col z-40">
        <!-- Brand -->
        <div class="p-5 border-b border-slate-800">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-600 to-orange-600 flex items-center justify-center shadow-lg shadow-red-600/30">
                    <i data-lucide="shield-check" class="w-5 h-5 text-white"></i>
                </div>
                <div>
                    <h1 class="text-sm font-black tracking-tight">Owner Portal</h1>
                    <p class="text-[10px] text-slate-500 uppercase tracking-wider">Platform Admin</p>
                </div>
            </div>
        </div>

        <!-- Nav -->
        <nav class="flex-1 p-3 space-y-1">
            <button @click="tab='dashboard'" :class="tab==='dashboard' ? 'bg-red-600/20 text-red-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition-all">
                <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard
            </button>
            <button @click="tab='companies'; loadCompanies()" :class="tab==='companies' ? 'bg-red-600/20 text-red-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition-all">
                <i data-lucide="building-2" class="w-4 h-4"></i> Companies
            </button>
            <button @click="tab='users'; loadUsers()" :class="tab==='users' ? 'bg-red-600/20 text-red-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition-all">
                <i data-lucide="users" class="w-4 h-4"></i> Users
            </button>
            <button @click="tab='subscriptions'; loadCompanies()" :class="tab==='subscriptions' ? 'bg-red-600/20 text-red-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition-all">
                <i data-lucide="credit-card" class="w-4 h-4"></i> Subscriptions
            </button>
        </nav>

        <!-- Footer -->
        <div class="p-4 border-t border-slate-800">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-red-600 to-orange-600 flex items-center justify-center text-white text-xs font-bold"><?php echo strtoupper(substr($owner_name, 0, 2)); ?></div>
                <div>
                    <p class="text-xs font-bold text-slate-200"><?php echo htmlspecialchars($owner_name); ?></p>
                    <p class="text-[10px] text-slate-500">Platform Owner</p>
                </div>
            </div>
            <a href="logout.php" class="flex items-center gap-2 px-3 py-2 text-xs text-red-400 hover:bg-red-900/20 rounded-lg transition-all font-semibold">
                <i data-lucide="log-out" class="w-3.5 h-3.5"></i> Sign Out
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="ml-64 min-h-screen">
        <!-- Header -->
        <header class="sticky top-0 z-20 bg-slate-950/80 backdrop-blur-xl border-b border-slate-800 px-6 py-4 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold" x-text="{ dashboard:'Dashboard', companies:'Companies', users:'Users', subscriptions:'Subscriptions' }[tab]"></h2>
                <p class="text-xs text-slate-500">MIAUDITOPS Platform Management</p>
            </div>
            <button @click="loadStats()" class="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-all" title="Refresh">
                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
            </button>
        </header>

        <div class="p-6">

            <!-- ═══════════ DASHBOARD TAB ═══════════ -->
            <div x-show="tab==='dashboard'" x-cloak>
                <!-- KPI Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                    <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-500/20 flex items-center justify-center"><i data-lucide="building-2" class="w-5 h-5 text-blue-400"></i></div>
                            <p class="text-xs text-slate-500 font-semibold uppercase">Companies</p>
                        </div>
                        <p class="text-3xl font-black text-white" x-text="stats.companies || 0"></p>
                    </div>
                    <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-xl bg-violet-500/20 flex items-center justify-center"><i data-lucide="users" class="w-5 h-5 text-violet-400"></i></div>
                            <p class="text-xs text-slate-500 font-semibold uppercase">Total Users</p>
                        </div>
                        <p class="text-3xl font-black text-white" x-text="stats.users || 0"></p>
                    </div>
                    <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center"><i data-lucide="check-circle" class="w-5 h-5 text-emerald-400"></i></div>
                            <p class="text-xs text-slate-500 font-semibold uppercase">Active Subs</p>
                        </div>
                        <p class="text-3xl font-black text-emerald-400" x-text="stats.active_subs || 0"></p>
                    </div>
                    <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-xl bg-amber-500/20 flex items-center justify-center"><i data-lucide="clock" class="w-5 h-5 text-amber-400"></i></div>
                            <p class="text-xs text-slate-500 font-semibold uppercase">On Trial</p>
                        </div>
                        <p class="text-3xl font-black text-amber-400" x-text="stats.trial_subs || 0"></p>
                    </div>
                    <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-xl bg-red-500/20 flex items-center justify-center"><i data-lucide="alert-triangle" class="w-5 h-5 text-red-400"></i></div>
                            <p class="text-xs text-slate-500 font-semibold uppercase">Expired / Suspended</p>
                        </div>
                        <p class="text-3xl font-black text-red-400" x-text="stats.expired_subs || 0"></p>
                    </div>
                </div>

                <!-- Recent Companies -->
                <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800 flex items-center gap-2">
                        <i data-lucide="clock" class="w-4 h-4 text-slate-500"></i>
                        <h3 class="text-sm font-bold">Recent Registrations</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-800">
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Company</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Code</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Users</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Plan</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Status</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="c in stats.recent || []" :key="c.id">
                                    <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                                        <td class="px-5 py-3 font-semibold text-slate-200" x-text="c.name"></td>
                                        <td class="px-5 py-3 font-mono text-xs text-slate-400" x-text="c.code"></td>
                                        <td class="px-5 py-3 text-slate-400" x-text="c.user_count"></td>
                                        <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-500/20 text-blue-400" x-text="c.plan_name || 'none'"></span></td>
                                        <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase" :class="subBadge(c.sub_status)" x-text="c.sub_status || 'unset'"></span></td>
                                        <td class="px-5 py-3 text-xs text-slate-500" x-text="c.created_at?.slice(0,10)"></td>
                                    </tr>
                                </template>
                                <tr x-show="!stats.recent?.length"><td colspan="6" class="px-5 py-8 text-center text-slate-500">No companies registered yet</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════ COMPANIES TAB ═══════════ -->
            <div x-show="tab==='companies'" x-cloak>
                <!-- Search -->
                <div class="mb-4">
                    <input type="text" x-model="companySearch" placeholder="Search companies..." class="w-full max-w-md px-4 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:border-red-500 transition-all">
                </div>
                <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-800">
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Company</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Code</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Email</th>
                                    <th class="text-center px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Users</th>
                                    <th class="text-center px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Outlets</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Plan</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Sub Status</th>
                                    <th class="text-center px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Active</th>
                                    <th class="text-center px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="c in filteredCompanies" :key="c.id">
                                    <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                                        <td class="px-5 py-3 font-semibold text-slate-200" x-text="c.name"></td>
                                        <td class="px-5 py-3 font-mono text-xs text-amber-400" x-text="c.code"></td>
                                        <td class="px-5 py-3 text-xs text-slate-400" x-text="c.email || '—'"></td>
                                        <td class="px-5 py-3 text-center text-slate-300" x-text="c.user_count"></td>
                                        <td class="px-5 py-3 text-center text-slate-300" x-text="c.outlet_count"></td>
                                        <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-500/20 text-blue-400" x-text="c.plan_name || 'none'"></span></td>
                                        <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase" :class="subBadge(c.sub_status)" x-text="c.sub_status || 'unset'"></span></td>
                                        <td class="px-5 py-3 text-center">
                                            <button @click="toggleCompany(c)" class="w-8 h-5 rounded-full relative transition-all" :class="c.is_active == 1 ? 'bg-emerald-500' : 'bg-slate-600'">
                                                <span class="absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-all" :class="c.is_active == 1 ? 'left-3.5' : 'left-0.5'"></span>
                                            </button>
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            <button @click="openSubModal(c)" class="p-1.5 rounded-lg text-slate-400 hover:text-amber-400 hover:bg-amber-900/20 transition-all" title="Manage Subscription">
                                                <i data-lucide="settings" class="w-4 h-4"></i>
                                            </button>
                                            <button @click="openPwdModal(c)" class="p-1.5 rounded-lg text-slate-400 hover:text-blue-400 hover:bg-blue-900/20 transition-all" title="Reset Password">
                                                <i data-lucide="key" class="w-4 h-4"></i>
                                            </button>
                                            <button @click="if(confirm('Delete this company? This cannot be undone.')) deleteCompany(c.id)" class="p-1.5 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-900/20 transition-all" title="Delete">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="!filteredCompanies.length"><td colspan="9" class="px-5 py-8 text-center text-slate-500">No companies found</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════ USERS TAB ═══════════ -->
            <div x-show="tab==='users'" x-cloak>
                <div class="flex gap-3 mb-4">
                    <input type="text" x-model="userSearch" placeholder="Search users..." class="flex-1 max-w-md px-4 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:border-red-500 transition-all">
                    <select x-model="userRoleFilter" class="px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white focus:outline-none focus:border-red-500">
                        <option value="">All Roles</option>
                        <option value="business_owner">Business Owner</option>
                        <option value="auditor">Auditor</option>
                        <option value="finance_officer">Finance Officer</option>
                        <option value="store_officer">Store Officer</option>
                        <option value="department_head">Department Head</option>
                        <option value="viewer">Viewer</option>
                    </select>
                </div>
                <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-800">
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Name</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Email</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Company</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Role</th>
                                    <th class="text-left px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Last Login</th>
                                    <th class="text-center px-5 py-3 text-xs text-slate-500 font-semibold uppercase">Active</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="u in filteredUsers" :key="u.id">
                                    <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                                        <td class="px-5 py-3 font-semibold text-slate-200" x-text="u.first_name + ' ' + u.last_name"></td>
                                        <td class="px-5 py-3 text-xs text-slate-400" x-text="u.email"></td>
                                        <td class="px-5 py-3"><span class="text-xs text-slate-300" x-text="u.company_name"></span><span class="ml-1 text-[10px] font-mono text-slate-500" x-text="'(' + u.company_code + ')'"></span></td>
                                        <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase" :class="roleBadge(u.role)" x-text="u.role?.replace(/_/g,' ')"></span></td>
                                        <td class="px-5 py-3 text-xs text-slate-500" x-text="u.last_login?.slice(0,16) || 'Never'"></td>
                                        <td class="px-5 py-3 text-center">
                                            <button @click="toggleUser(u)" class="w-8 h-5 rounded-full relative transition-all" :class="u.is_active == 1 ? 'bg-emerald-500' : 'bg-slate-600'">
                                                <span class="absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-all" :class="u.is_active == 1 ? 'left-3.5' : 'left-0.5'"></span>
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="!filteredUsers.length"><td colspan="6" class="px-5 py-8 text-center text-slate-500">No users found</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════ SUBSCRIPTIONS TAB ═══════════ -->
            <div x-show="tab==='subscriptions'" x-cloak>
                <div class="mb-4">
                    <input type="text" x-model="companySearch" placeholder="Search companies..." class="w-full max-w-md px-4 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:border-red-500 transition-all">
                </div>
                <div class="grid gap-4">
                    <template x-for="c in filteredCompanies" :key="c.id">
                        <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                            <div class="flex-1 min-w-0">
                                <h4 class="text-sm font-bold text-white" x-text="c.name"></h4>
                                <p class="text-xs text-slate-500 mt-0.5">Code: <span class="font-mono text-amber-400" x-text="c.code"></span> · <span x-text="c.user_count + ' users'"></span> · <span x-text="c.outlet_count + ' outlets'"></span></p>
                            </div>
                            <div class="flex items-center gap-3 flex-wrap">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase" :class="subBadge(c.sub_status)" x-text="c.sub_status || 'unset'"></span>
                                <span class="text-xs text-slate-400" x-show="c.expires_at" x-text="'Exp: ' + (c.expires_at || '')"></span>
                                <button @click="openSubModal(c)" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 text-slate-300 hover:bg-red-600/20 hover:text-red-400 transition-all flex items-center gap-1">
                                    <i data-lucide="edit-3" class="w-3 h-3"></i> Manage
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

        </div>
    </main>

    <!-- ═══ Subscription Modal ═══ -->
    <div x-show="subModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="subModal=false">
        <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-lg p-6 mx-4" @click.stop>
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-lg font-bold">Manage Subscription</h3>
                <button @click="subModal=false" class="p-1 text-slate-400 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <p class="text-sm text-slate-400 mb-4">Company: <strong class="text-white" x-text="subForm.company_name"></strong></p>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Plan Name</label>
                    <select x-model="subForm.plan_name" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-red-500">
                        <option value="free">Free</option>
                        <option value="starter">Starter</option>
                        <option value="professional">Professional</option>
                        <option value="enterprise">Enterprise</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Status</label>
                    <select x-model="subForm.status" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-red-500">
                        <option value="active">Active</option>
                        <option value="trial">Trial</option>
                        <option value="expired">Expired</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Expires At</label>
                    <input type="date" x-model="subForm.expires_at" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-red-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Max Users</label>
                    <input type="number" x-model.number="subForm.max_users" min="1" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-red-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Max Outlets</label>
                    <input type="number" x-model.number="subForm.max_outlets" min="1" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-red-500">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-semibold text-slate-400 mb-1">Notes</label>
                <textarea x-model="subForm.notes" rows="2" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-red-500 resize-none"></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button @click="subModal=false" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancel</button>
                <button @click="saveSub()" class="px-5 py-2 bg-gradient-to-r from-red-600 to-orange-600 text-white font-bold rounded-xl text-sm shadow-lg shadow-red-600/30 hover:scale-[1.02] transition-all">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- ═══ Password Reset Modal ═══ -->
    <div x-show="pwdModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="pwdModal=false">
        <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-md p-6 mx-4" @click.stop>
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-blue-500/20 flex items-center justify-center">
                        <i data-lucide="key" class="w-4 h-4 text-blue-400"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold">Reset Password</h3>
                        <p class="text-[11px] text-slate-400" x-text="pwdForm.company_name"></p>
                    </div>
                </div>
                <button @click="pwdModal=false" class="p-1 text-slate-400 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <p class="text-xs text-slate-400 mb-4 bg-blue-500/10 border border-blue-500/20 rounded-lg px-3 py-2">
                <i data-lucide="info" class="w-3 h-3 inline-block mr-1"></i>
                This will reset the password for the <strong class="text-white">primary admin user</strong> of this company.
            </p>
            <div class="space-y-3 mb-5">
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">New Password</label>
                    <input type="password" x-model="pwdForm.new_password" placeholder="Min. 6 characters" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-blue-500 transition-all">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Confirm Password</label>
                    <input type="password" x-model="pwdForm.confirm_password" placeholder="Re-enter password" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-blue-500 transition-all">
                </div>
                <p x-show="pwdError" class="text-red-400 text-xs" x-text="pwdError"></p>
            </div>
            <div class="flex justify-end gap-3">
                <button @click="pwdModal=false" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancel</button>
                <button @click="savePassword()" class="px-5 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-bold rounded-xl text-sm shadow-lg shadow-blue-600/30 hover:scale-[1.02] transition-all flex items-center gap-2">
                    <i data-lucide="key" class="w-3.5 h-3.5"></i> Reset Password
                </button>
            </div>
        </div>
    </div>

<script>
function ownerApp() {
    return {
        tab: 'dashboard',
        stats: {},
        pwdModal: false,
        pwdForm: { company_id: 0, company_name: '', new_password: '', confirm_password: '' },
        pwdError: '',
        companies: [],
        users: [],
        companySearch: '',
        userSearch: '',
        userRoleFilter: '',
        subModal: false,
        subForm: { company_id: 0, company_name: '', plan_name: 'free', status: 'trial', expires_at: '', max_users: 5, max_outlets: 3, notes: '' },

        async init() {
            await this.loadStats();
            this.$nextTick(() => lucide.createIcons());
        },

        async api(action, data = {}) {
            const fd = new FormData();
            fd.append('action', action);
            Object.entries(data).forEach(([k, v]) => fd.append(k, v));
            const res = await fetch('owner_api.php', { method: 'POST', body: fd });
            return res.json();
        },

        async loadStats() {
            const r = await this.api('stats');
            if (r.success) this.stats = r.data;
            this.$nextTick(() => lucide.createIcons());
        },

        async loadCompanies() {
            const r = await this.api('list_companies');
            if (r.success) this.companies = r.data;
        },

        async loadUsers() {
            const r = await this.api('list_users');
            if (r.success) this.users = r.data;
        },

        get filteredCompanies() {
            const q = this.companySearch.toLowerCase();
            return this.companies.filter(c => !q || c.name?.toLowerCase().includes(q) || c.code?.toLowerCase().includes(q) || c.email?.toLowerCase().includes(q));
        },

        get filteredUsers() {
            const q = this.userSearch.toLowerCase();
            const role = this.userRoleFilter;
            return this.users.filter(u => {
                if (role && u.role !== role) return false;
                if (!q) return true;
                return (u.first_name + ' ' + u.last_name + ' ' + u.email + ' ' + u.company_name).toLowerCase().includes(q);
            });
        },

        async toggleCompany(c) {
            const newVal = c.is_active == 1 ? 0 : 1;
            await this.api('toggle_company', { id: c.id, is_active: newVal });
            c.is_active = newVal;
        },

        async toggleUser(u) {
            const newVal = u.is_active == 1 ? 0 : 1;
            await this.api('toggle_user', { id: u.id, is_active: newVal });
            u.is_active = newVal;
        },

        async deleteCompany(id) {
            await this.api('delete_company', { id });
            this.companies = this.companies.filter(c => c.id !== id);
            this.loadStats();
        },

        openSubModal(c) {
            this.subForm = {
                company_id: c.id,
                company_name: c.name,
                plan_name: c.plan_name || 'free',
                status: c.sub_status || 'trial',
                expires_at: c.expires_at || '',
                max_users: c.max_users || 5,
                max_outlets: c.max_outlets || 3,
                notes: c.notes || ''
            };
            this.subModal = true;
            this.$nextTick(() => lucide.createIcons());
        },

        async saveSub() {
            await this.api('update_subscription', this.subForm);
            this.subModal = false;
            this.loadCompanies();
            this.loadStats();
        },

        openPwdModal(c) {
            this.pwdForm = { company_id: c.id, company_name: c.name, new_password: '', confirm_password: '' };
            this.pwdError = '';
            this.pwdModal = true;
            this.$nextTick(() => lucide.createIcons());
        },

        async savePassword() {
            this.pwdError = '';
            if (this.pwdForm.new_password.length < 6) {
                this.pwdError = 'Password must be at least 6 characters.';
                return;
            }
            if (this.pwdForm.new_password !== this.pwdForm.confirm_password) {
                this.pwdError = 'Passwords do not match.';
                return;
            }
            const r = await this.api('reset_password', {
                company_id: this.pwdForm.company_id,
                new_password: this.pwdForm.new_password
            });
            if (r.success) {
                this.pwdModal = false;
                alert('✓ ' + r.message);
            } else {
                this.pwdError = r.message || 'Reset failed.';
            }
        },

        subBadge(status) {
            return {
                active: 'bg-emerald-500/20 text-emerald-400',
                trial: 'bg-amber-500/20 text-amber-400',
                expired: 'bg-red-500/20 text-red-400',
                suspended: 'bg-slate-500/20 text-slate-400'
            }[status] || 'bg-slate-500/20 text-slate-400';
        },

        roleBadge(role) {
            return {
                business_owner: 'bg-violet-500/20 text-violet-400',
                auditor: 'bg-blue-500/20 text-blue-400',
                finance_officer: 'bg-emerald-500/20 text-emerald-400',
                store_officer: 'bg-amber-500/20 text-amber-400',
                department_head: 'bg-slate-500/20 text-slate-300',
                viewer: 'bg-slate-500/20 text-slate-500'
            }[role] || 'bg-slate-500/20 text-slate-400';
        }
    };
}
</script>
</body>
</html>
