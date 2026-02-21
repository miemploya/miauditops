<?php
/**
 * MIAUDITOPS — Billing & Subscription Management
 * View subscription status, invoices, and make payments
 */
$page_title = 'Billing';
require_once '../config/db.php';
require_once '../config/subscription_plans.php';
require_once '../config/paystack.php';
require_once '../includes/functions.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> — MIAUDITOPS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script defer src="https://unpkg.com/alpinejs@3/dist/cdn.min.js"></script>
    <script src="../assets/js/theme-toggle.js"></script>
</head>
<body class="h-full font-sans bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-white transition-colors duration-300">
    <div class="flex h-full overflow-hidden">
        <?php include '../includes/dashboard_sidebar.php'; ?>
        <div class="flex-1 flex flex-col min-h-0 overflow-hidden">
            <?php include '../includes/dashboard_header.php'; ?>

            <main class="flex-1 overflow-y-auto p-6" x-data="billingDashboard()" x-init="init()">
                <div class="max-w-5xl mx-auto space-y-6">

                    <!-- Header -->
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/30">
                                <i data-lucide="credit-card" class="w-5 h-5 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-xl font-black text-slate-800 dark:text-white">Billing & Subscription</h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Manage your plan, invoices, and payments</p>
                            </div>
                        </div>
                    </div>

                    <!-- Loading State -->
                    <div x-show="loading" class="text-center py-16">
                        <div class="w-10 h-10 border-3 border-emerald-500 border-t-transparent rounded-full animate-spin mx-auto mb-3"></div>
                        <p class="text-sm text-slate-500">Loading billing information...</p>
                    </div>

                    <div x-show="!loading" x-transition>

                        <!-- ═══════════ Subscription Status Card ═══════════ -->
                        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl overflow-hidden">
                            <div class="p-6">
                                <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                                    <div class="flex items-center gap-4">
                                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center shadow-lg"
                                             :class="{
                                                 'bg-gradient-to-br from-slate-500 to-slate-600 shadow-slate-500/30': sub.plan_name === 'starter',
                                                 'bg-gradient-to-br from-violet-500 to-purple-600 shadow-violet-500/30': sub.plan_name === 'professional',
                                                 'bg-gradient-to-br from-amber-500 to-orange-600 shadow-amber-500/30': sub.plan_name === 'enterprise'
                                             }">
                                            <i :data-lucide="sub.plan_icon || 'rocket'" class="w-7 h-7 text-white"></i>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2 mb-1">
                                                <h3 class="text-lg font-black text-slate-800 dark:text-white" x-text="sub.plan_label + ' Plan'"></h3>
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                                      :class="{
                                                          'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400': sub.status === 'active',
                                                          'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400': sub.status === 'trial',
                                                          'bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400': sub.status === 'expired' || sub.status === 'suspended'
                                                      }" x-text="sub.status"></span>
                                            </div>
                                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                                <span x-text="sub.billing_cycle ? (sub.billing_cycle.charAt(0).toUpperCase() + sub.billing_cycle.slice(1)) + ' billing' : 'Free tier'"></span>
                                                <template x-if="sub.expires_at">
                                                    <span>
                                                        · Expires <span class="font-semibold" :class="sub.days_remaining <= 7 ? 'text-red-500' : 'text-slate-700 dark:text-slate-300'"
                                                                        x-text="formatDate(sub.expires_at)"></span>
                                                    </span>
                                                </template>
                                            </p>
                                            <template x-if="sub.days_remaining !== null">
                                                <p class="text-xs mt-1" :class="sub.days_remaining <= 7 ? 'text-red-500 font-bold' : 'text-slate-400'">
                                                    <span x-text="sub.days_remaining"></span> days remaining
                                                </p>
                                            </template>
                                        </div>
                                    </div>

                                    <!-- Expired Banner -->
                                    <template x-if="sub.status === 'expired' || sub.status === 'suspended'">
                                        <div class="flex items-center gap-2 px-4 py-2.5 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 rounded-xl">
                                            <i data-lucide="alert-triangle" class="w-4 h-4 text-red-500 shrink-0"></i>
                                            <p class="text-xs font-semibold text-red-600 dark:text-red-400">Your subscription has expired. Settle an invoice below to restore access.</p>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Days Remaining Bar (for active subscriptions) -->
                            <template x-if="sub.status === 'active' && sub.days_remaining !== null">
                                <div class="px-6 pb-4">
                                    <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-500"
                                             :class="{
                                                 'bg-emerald-500': sub.days_remaining > 14,
                                                 'bg-amber-500': sub.days_remaining > 7 && sub.days_remaining <= 14,
                                                 'bg-red-500': sub.days_remaining <= 7
                                             }"
                                             :style="'width:' + Math.min(100, Math.max(2, (sub.days_remaining / 30) * 100)) + '%'"></div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- ═══════════ Current Usage Stats ═══════════ -->
                        <template x-if="usage && sub.status !== 'expired'">
                            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl overflow-hidden">
                                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800">
                                    <h3 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
                                        <i data-lucide="bar-chart-3" class="w-4 h-4 text-violet-500"></i> Current Usage
                                    </h3>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-100 dark:divide-slate-800">
                                    <!-- Users -->
                                    <div class="p-5">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-xs font-semibold text-slate-500">Users</span>
                                            <span class="text-xs font-bold text-slate-800 dark:text-white">
                                                <span x-text="usage.users"></span> / <span x-text="usage.plan_limits.max_users == 0 ? '∞' : usage.plan_limits.max_users"></span>
                                            </span>
                                        </div>
                                        <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-1.5 overflow-hidden">
                                            <div class="h-full rounded-full bg-violet-500 transition-all" :style="'width:' + (usage.plan_limits.max_users == 0 ? 15 : Math.min(100, (usage.users / usage.plan_limits.max_users) * 100)) + '%'"></div>
                                        </div>
                                    </div>
                                    <!-- Outlets -->
                                    <div class="p-5">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-xs font-semibold text-slate-500">Outlets / Stations</span>
                                            <span class="text-xs font-bold text-slate-800 dark:text-white">
                                                <span x-text="usage.outlets"></span> / <span x-text="usage.plan_limits.max_outlets == 0 ? '∞' : usage.plan_limits.max_outlets"></span>
                                            </span>
                                        </div>
                                        <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-1.5 overflow-hidden">
                                            <div class="h-full rounded-full bg-emerald-500 transition-all" :style="'width:' + (usage.plan_limits.max_outlets == 0 ? 15 : Math.min(100, (usage.outlets / usage.plan_limits.max_outlets) * 100)) + '%'"></div>
                                        </div>
                                    </div>
                                    <!-- Departments -->
                                    <div class="p-5">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-xs font-semibold text-slate-500">Departments</span>
                                            <span class="text-xs font-bold text-slate-800 dark:text-white">
                                                <span x-text="usage.departments"></span> / <span x-text="usage.plan_limits.max_departments == 0 ? '∞' : usage.plan_limits.max_departments"></span>
                                            </span>
                                        </div>
                                        <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-1.5 overflow-hidden">
                                            <div class="h-full rounded-full bg-amber-500 transition-all" :style="'width:' + (usage.plan_limits.max_departments == 0 ? 15 : Math.min(100, (usage.departments / usage.plan_limits.max_departments) * 100)) + '%'"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- ═══════════ Tabs ═══════════ -->
                        <div class="flex items-center gap-2 border-b border-slate-200 dark:border-slate-800">
                            <button @click="tab = 'invoices'" class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-all cursor-pointer"
                                    :class="tab === 'invoices' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">
                                <i data-lucide="file-text" class="w-4 h-4 inline-block mr-1"></i> Invoices
                            </button>
                            <button @click="tab = 'upgrade'" class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-all cursor-pointer"
                                    :class="tab === 'upgrade' ? 'border-violet-500 text-violet-600 dark:text-violet-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">
                                <i data-lucide="arrow-up-circle" class="w-4 h-4 inline-block mr-1"></i> Upgrade Plan
                            </button>
                            <button @click="tab = 'history'" class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-all cursor-pointer"
                                    :class="tab === 'history' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">
                                <i data-lucide="history" class="w-4 h-4 inline-block mr-1"></i> Payment History
                            </button>
                        </div>

                        <!-- ═══════════ Invoices Tab ═══════════ -->
                        <div x-show="tab === 'invoices'" x-transition>
                            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl overflow-hidden">
                                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                                    <h3 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
                                        <i data-lucide="file-text" class="w-4 h-4 text-emerald-500"></i> Invoices
                                    </h3>
                                    <span class="text-xs text-slate-400" x-text="invoices.length + ' invoice(s)'"></span>
                                </div>

                                <!-- Empty State -->
                                <div x-show="invoices.length === 0" class="p-12 text-center">
                                    <i data-lucide="file-check" class="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-3"></i>
                                    <p class="text-sm font-semibold text-slate-400 dark:text-slate-500">No invoices yet</p>
                                    <p class="text-xs text-slate-400 dark:text-slate-600 mt-1">Invoices will be auto-generated when your subscription is due for renewal</p>
                                </div>

                                <!-- Invoices Table -->
                                <div x-show="invoices.length > 0" class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="bg-slate-50 dark:bg-slate-800/50">
                                                <th class="px-6 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Invoice #</th>
                                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Plan</th>
                                                <th class="px-4 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Period</th>
                                                <th class="px-4 py-3 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Amount</th>
                                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase tracking-wider text-slate-500">Due Date</th>
                                                <th class="px-4 py-3 text-center text-[10px] font-bold uppercase tracking-wider text-slate-500">Status</th>
                                                <th class="px-6 py-3 text-center text-[10px] font-bold uppercase tracking-wider text-slate-500">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                            <template x-for="inv in invoices" :key="inv.id">
                                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                                    <td class="px-6 py-3">
                                                        <span class="font-mono font-bold text-slate-800 dark:text-white text-xs" x-text="inv.invoice_number"></span>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span class="text-xs font-semibold text-slate-700 dark:text-slate-300" x-text="inv.plan_name.charAt(0).toUpperCase() + inv.plan_name.slice(1)"></span>
                                                        <span class="text-[10px] text-slate-400 block" x-text="inv.billing_cycle"></span>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span class="text-xs text-slate-500" x-text="inv.period_start ? (formatDate(inv.period_start) + ' → ' + formatDate(inv.period_end)) : '—'"></span>
                                                    </td>
                                                    <td class="px-4 py-3 text-right">
                                                        <span class="font-bold text-slate-800 dark:text-white" x-text="'₦' + Number(inv.amount_naira).toLocaleString()"></span>
                                                    </td>
                                                    <td class="px-4 py-3 text-center">
                                                        <span class="text-xs text-slate-500" x-text="formatDate(inv.due_date)"></span>
                                                    </td>
                                                    <td class="px-4 py-3 text-center">
                                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase"
                                                              :class="{
                                                                  'bg-slate-100 dark:bg-slate-700 text-slate-500': inv.status === 'draft',
                                                                  'bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400': inv.status === 'sent',
                                                                  'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400': inv.status === 'paid',
                                                                  'bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400': inv.status === 'overdue',
                                                                  'bg-slate-100 dark:bg-slate-700/50 text-slate-400': inv.status === 'cancelled'
                                                              }" x-text="inv.status"></span>
                                                    </td>
                                                    <td class="px-6 py-3 text-center">
                                                        <template x-if="inv.status !== 'paid' && inv.status !== 'cancelled'">
                                                            <button @click="payInvoice(inv)" :disabled="paying"
                                                                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-lg text-xs shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 hover:scale-[1.02] transition-all disabled:opacity-50 cursor-pointer">
                                                                <i data-lucide="credit-card" class="w-3 h-3"></i>
                                                                <span x-text="paying ? 'Processing...' : 'Pay Now'"></span>
                                                            </button>
                                                        </template>
                                                        <template x-if="inv.status === 'paid'">
                                                            <span class="text-xs text-emerald-500 font-semibold flex items-center justify-center gap-1">
                                                                <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Paid
                                                            </span>
                                                        </template>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ═══════════ Upgrade Plan Tab ═══════════ -->
                        <div x-show="tab === 'upgrade'" x-transition>
                            <div class="space-y-4">
                                <!-- Billing Cycle Selector -->
                                <div class="flex items-center justify-center gap-2 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-2">
                                    <template x-for="cycle in ['monthly', 'quarterly', 'annual']" :key="cycle">
                                        <button @click="selectedCycle = cycle" class="px-4 py-2 rounded-lg text-xs font-bold transition-all cursor-pointer"
                                                :class="selectedCycle === cycle ? 'bg-violet-500 text-white shadow-lg shadow-violet-500/30' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">
                                            <span x-text="cycle.charAt(0).toUpperCase() + cycle.slice(1)"></span>
                                            <template x-if="cycle === 'quarterly'"><span class="ml-1 text-[10px] opacity-80">-10%</span></template>
                                            <template x-if="cycle === 'annual'"><span class="ml-1 text-[10px] opacity-80">-20%</span></template>
                                        </button>
                                    </template>
                                </div>

                                <!-- Plan Cards -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <template x-for="plan in planOptions" :key="plan.key">
                                        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl overflow-hidden transition-all hover:shadow-2xl"
                                             :class="sub.plan_name === plan.key ? 'ring-2 ring-violet-500' : ''">
                                            <div class="p-6">
                                                <div class="flex items-center justify-between mb-4">
                                                    <div class="flex items-center gap-3">
                                                        <div class="w-10 h-10 rounded-xl flex items-center justify-center"
                                                             :class="{
                                                                 'bg-gradient-to-br from-violet-500 to-purple-600': plan.key === 'professional',
                                                                 'bg-gradient-to-br from-amber-500 to-orange-600': plan.key === 'enterprise'
                                                             }">
                                                            <i :data-lucide="plan.icon" class="w-5 h-5 text-white"></i>
                                                        </div>
                                                        <div>
                                                            <h4 class="text-sm font-black text-slate-800 dark:text-white" x-text="plan.label"></h4>
                                                            <p class="text-[10px] text-slate-400" x-text="plan.tag"></p>
                                                        </div>
                                                    </div>
                                                    <template x-if="sub.plan_name === plan.key">
                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-violet-100 dark:bg-violet-500/20 text-violet-600 dark:text-violet-400">Current</span>
                                                    </template>
                                                </div>

                                                <div class="mb-4">
                                                    <span class="text-3xl font-black text-slate-800 dark:text-white">₦<span x-text="Number(plan[selectedCycle]).toLocaleString()"></span></span>
                                                    <span class="text-xs text-slate-400" x-text="'/' + selectedCycle"></span>
                                                </div>

                                                <!-- Plan Features -->
                                                <div class="mb-4 space-y-1.5 max-h-44 overflow-y-auto pr-1">
                                                    <template x-for="feat in (plan.features || [])" :key="feat">
                                                        <div class="flex items-center gap-2">
                                                            <i data-lucide="check" class="w-3 h-3 text-emerald-500 shrink-0"></i>
                                                            <span class="text-[11px] text-slate-600 dark:text-slate-400" x-text="feat"></span>
                                                        </div>
                                                    </template>
                                                </div>

                                                <button @click="initializePayment(plan.key, selectedCycle)" :disabled="paying"
                                                        class="w-full py-3 rounded-xl font-bold text-sm transition-all cursor-pointer disabled:opacity-50"
                                                        :class="sub.plan_name === plan.key 
                                                            ? 'bg-slate-100 dark:bg-slate-800 text-slate-500 border border-slate-200 dark:border-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700' 
                                                            : 'bg-gradient-to-r from-violet-500 to-purple-600 text-white shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-[1.01]'">
                                                    <span x-text="sub.plan_name === plan.key ? 'Renew Plan' : 'Upgrade Now'"></span>
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- ═══════════ Payment History Tab ═══════════ -->
                        <div x-show="tab === 'history'" x-transition>
                            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl overflow-hidden">
                                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800">
                                    <h3 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
                                        <i data-lucide="history" class="w-4 h-4 text-blue-500"></i> Payment History
                                    </h3>
                                </div>

                                <div x-show="payments.length === 0" class="p-12 text-center">
                                    <i data-lucide="wallet" class="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-3"></i>
                                    <p class="text-sm font-semibold text-slate-400">No payment records</p>
                                </div>

                                <div x-show="payments.length > 0" class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <template x-for="pay in payments" :key="pay.id">
                                        <div class="px-6 py-4 flex items-center justify-between gap-4 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                            <div class="flex items-center gap-3 min-w-0">
                                                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                                                     :class="{
                                                         'bg-emerald-500/10': pay.status === 'success',
                                                         'bg-amber-500/10': pay.status === 'pending',
                                                         'bg-red-500/10': pay.status === 'failed'
                                                     }">
                                                    <i :data-lucide="pay.status === 'success' ? 'check-circle' : pay.status === 'pending' ? 'clock' : 'x-circle'"
                                                       class="w-4 h-4"
                                                       :class="{
                                                           'text-emerald-500': pay.status === 'success',
                                                           'text-amber-500': pay.status === 'pending',
                                                           'text-red-500': pay.status === 'failed'
                                                       }"></i>
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="text-xs font-bold text-slate-800 dark:text-white truncate" x-text="pay.plan_name.charAt(0).toUpperCase() + pay.plan_name.slice(1) + ' — ' + pay.billing_cycle"></p>
                                                    <p class="text-[10px] text-slate-400 font-mono truncate" x-text="pay.reference"></p>
                                                    <p class="text-[10px] text-slate-400" x-text="formatDate(pay.created_at)"></p>
                                                </div>
                                            </div>
                                            <div class="text-right shrink-0">
                                                <p class="text-sm font-bold text-slate-800 dark:text-white" x-text="'₦' + Number(pay.amount_kobo / 100).toLocaleString()"></p>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase"
                                                      :class="{
                                                          'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600': pay.status === 'success',
                                                          'bg-amber-100 dark:bg-amber-500/20 text-amber-600': pay.status === 'pending',
                                                          'bg-red-100 dark:bg-red-500/20 text-red-600': pay.status === 'failed'
                                                      }" x-text="pay.status"></span>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </main>

            <script>
            function billingDashboard() {
                return {
                    loading: true,
                    tab: 'invoices',
                    paying: false,
                    selectedCycle: 'monthly',

                    // Data from API
                    sub: { plan_name: 'starter', plan_label: 'Starter', status: 'active', plan_icon: 'rocket', billing_cycle: 'monthly', expires_at: null, days_remaining: null },
                    invoices: [],
                    payments: [],
                    planOptions: [],
                    usage: null,

                    async init() {
                        await this.loadBillingData();
                        this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
                    },

                    async loadBillingData() {
                        this.loading = true;
                        try {
                            const fd = new FormData();
                            fd.append('action', 'get_billing_data');
                            const res = await fetch('../ajax/payment_api.php', { method: 'POST', body: fd });
                            const data = await res.json();
                            if (data.success) {
                                this.sub = data.subscription;
                                this.invoices = data.invoices || [];
                                this.payments = data.payments || [];
                                this.planOptions = data.plan_options || [];
                                this.usage = data.usage || null;
                                // Default cycle to current subscription cycle
                                if (this.sub.billing_cycle) this.selectedCycle = this.sub.billing_cycle;
                            }
                        } catch (e) { console.error('Billing load error:', e); }
                        this.loading = false;
                        this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
                    },

                    async payInvoice(inv) {
                        if (!confirm('Pay invoice ' + inv.invoice_number + ' for ₦' + Number(inv.amount_naira).toLocaleString() + '?\n\nYou will be redirected to Paystack to complete payment.')) return;
                        this.paying = true;
                        try {
                            const fd = new FormData();
                            fd.append('action', 'pay_invoice');
                            fd.append('invoice_id', inv.id);
                            const res = await fetch('../ajax/payment_api.php', { method: 'POST', body: fd });
                            const data = await res.json();
                            if (data.success && data.authorization_url) {
                                window.location.href = data.authorization_url;
                            } else {
                                alert('✗ ' + (data.message || 'Failed to initialize payment'));
                            }
                        } catch (e) {
                            alert('Network error. Please try again.');
                        }
                        this.paying = false;
                    },

                    async initializePayment(planKey, cycle) {
                        const prices = {};
                        this.planOptions.forEach(p => { prices[p.key] = p; });
                        const plan = prices[planKey];
                        if (!plan) return;

                        const amount = plan[cycle] || 0;
                        if (amount <= 0) { alert('Invalid plan price.'); return; }

                        if (!confirm('Subscribe to ' + plan.label + ' (' + cycle + ') for ₦' + Number(amount).toLocaleString() + '?\n\nYou will be redirected to Paystack to complete payment.')) return;

                        this.paying = true;
                        try {
                            const fd = new FormData();
                            fd.append('action', 'initialize');
                            fd.append('plan', planKey);
                            fd.append('cycle', cycle);
                            const res = await fetch('../ajax/payment_api.php', { method: 'POST', body: fd });
                            const data = await res.json();
                            if (data.success && data.authorization_url) {
                                window.location.href = data.authorization_url;
                            } else {
                                alert('✗ ' + (data.message || 'Failed to initialize payment'));
                            }
                        } catch (e) {
                            alert('Network error. Please try again.');
                        }
                        this.paying = false;
                    },

                    formatDate(dateStr) {
                        if (!dateStr) return '—';
                        const d = new Date(dateStr);
                        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    }
                };
            }
            </script>

        </div>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>
