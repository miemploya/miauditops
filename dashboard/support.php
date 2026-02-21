<?php
/**
 * MIAUDITOPS — Support Services
 * Enterprise-only support ticket system
 */
$page_title = 'Support Services';
require_once '../config/db.php';
require_once '../config/subscription_plans.php';
require_once '../includes/functions.php';
require_login();

$_current_plan_key = get_current_plan();

// Enterprise gating
$is_enterprise = ($_current_plan_key === 'enterprise');
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

            <?php if (!$is_enterprise): ?>
            <!-- Enterprise Gate Screen -->
            <main class="flex-1 overflow-y-auto p-6">
                <div class="max-w-xl mx-auto mt-16 text-center">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-2xl shadow-amber-500/30 mx-auto mb-6">
                        <i data-lucide="crown" class="w-10 h-10 text-white"></i>
                    </div>
                    <h2 class="text-2xl font-black text-slate-800 dark:text-white mb-3">Enterprise Feature</h2>
                    <p class="text-slate-500 dark:text-slate-400 mb-6 leading-relaxed">
                        Priority Support Services is available exclusively on the <strong class="text-amber-500">Enterprise</strong> plan.<br>
                        Get direct communication with our team for complaints, enquiries, and requests.
                    </p>
                    <a href="subscriptions.php" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl shadow-lg shadow-amber-500/30 hover:shadow-amber-500/50 hover:scale-105 transition-all text-sm">
                        <i data-lucide="arrow-up-circle" class="w-4 h-4"></i> Upgrade to Enterprise
                    </a>
                </div>
            </main>
            <?php else: ?>
            <!-- Support Center -->
            <main class="flex-1 overflow-y-auto p-6" x-data="supportCenter()" x-init="init()">

                <!-- Header -->
                <div class="max-w-5xl mx-auto mb-6">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30">
                                <i data-lucide="headphones" class="w-5 h-5 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-xl font-black text-slate-800 dark:text-white">Support Center</h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Submit and track support tickets</p>
                            </div>
                        </div>
                        <button @click="showForm = !showForm" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl text-sm shadow-lg shadow-amber-500/30 hover:shadow-amber-500/50 hover:scale-[1.02] transition-all cursor-pointer">
                            <i data-lucide="plus" class="w-4 h-4"></i> New Ticket
                        </button>
                    </div>
                </div>

                <!-- New Ticket Form -->
                <div x-show="showForm" x-transition class="max-w-5xl mx-auto mb-6">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-900/10 dark:to-orange-900/10">
                            <h3 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
                                <i data-lucide="ticket" class="w-4 h-4 text-amber-500"></i> Submit a Support Ticket
                            </h3>
                        </div>
                        <div class="p-6 space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5">Category</label>
                                    <select x-model="form.category" class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500 transition-all">
                                        <option value="enquiry">📩 Enquiry</option>
                                        <option value="complaint">📢 Complaint</option>
                                        <option value="request">📋 Request</option>
                                        <option value="support">🛠️ Technical Support</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5">Subject</label>
                                    <input x-model="form.subject" type="text" placeholder="Brief description of your issue..."
                                           class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500 transition-all"
                                           maxlength="255">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5">Message</label>
                                <textarea x-model="form.message" rows="4" placeholder="Describe your issue, request, or enquiry in detail..."
                                          class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500 transition-all resize-none"></textarea>
                            </div>
                            <div class="flex items-center gap-3 justify-end">
                                <button @click="showForm = false" class="px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 transition-colors cursor-pointer">Cancel</button>
                                <button @click="submitTicket()" :disabled="submitting" class="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold rounded-xl text-sm shadow-lg shadow-amber-500/30 hover:shadow-amber-500/50 transition-all disabled:opacity-50 cursor-pointer">
                                    <i data-lucide="send" class="w-4 h-4"></i>
                                    <span x-text="submitting ? 'Sending...' : 'Submit Ticket'"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="max-w-5xl mx-auto mb-6 grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-blue-500/10 flex items-center justify-center">
                            <i data-lucide="inbox" class="w-5 h-5 text-blue-500"></i>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Total</p>
                            <p class="text-lg font-black text-slate-800 dark:text-white" x-text="tickets.length">0</p>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-amber-500/10 flex items-center justify-center">
                            <i data-lucide="clock" class="w-5 h-5 text-amber-500"></i>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Open</p>
                            <p class="text-lg font-black text-amber-500" x-text="tickets.filter(t => t.status === 'open').length">0</p>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-violet-500/10 flex items-center justify-center">
                            <i data-lucide="loader" class="w-5 h-5 text-violet-500"></i>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">In Progress</p>
                            <p class="text-lg font-black text-violet-500" x-text="tickets.filter(t => t.status === 'in_progress').length">0</p>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-emerald-500/10 flex items-center justify-center">
                            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-500"></i>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Resolved</p>
                            <p class="text-lg font-black text-emerald-500" x-text="tickets.filter(t => t.status === 'resolved' || t.status === 'closed').length">0</p>
                        </div>
                    </div>
                </div>

                <!-- Tickets List -->
                <div class="max-w-5xl mx-auto">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between flex-wrap gap-3">
                            <h3 class="text-sm font-bold text-slate-800 dark:text-white">Your Tickets</h3>
                            <div class="flex items-center gap-2">
                                <select x-model="filter" @change="loadTickets()" class="px-3 py-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/50">
                                    <option value="">All Status</option>
                                    <option value="open">Open</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="resolved">Resolved</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>
                        </div>

                        <!-- Loading State -->
                        <div x-show="loading" class="p-8 text-center">
                            <div class="w-8 h-8 border-2 border-amber-500 border-t-transparent rounded-full animate-spin mx-auto mb-2"></div>
                            <p class="text-xs text-slate-500">Loading tickets...</p>
                        </div>

                        <!-- Empty State -->
                        <div x-show="!loading && filteredTickets().length === 0" class="p-12 text-center">
                            <i data-lucide="ticket" class="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-3"></i>
                            <p class="text-sm font-semibold text-slate-400 dark:text-slate-500">No tickets found</p>
                            <p class="text-xs text-slate-400 dark:text-slate-600 mt-1">Submit a new ticket to get started</p>
                        </div>

                        <!-- Tickets -->
                        <div x-show="!loading && filteredTickets().length > 0" class="divide-y divide-slate-100 dark:divide-slate-800">
                            <template x-for="ticket in filteredTickets()" :key="ticket.id">
                                <div class="px-6 py-4 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors cursor-pointer" @click="toggleTicket(ticket.id)">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase"
                                                      :class="{
                                                          'bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400': ticket.category === 'enquiry',
                                                          'bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400': ticket.category === 'complaint',
                                                          'bg-violet-100 dark:bg-violet-500/20 text-violet-600 dark:text-violet-400': ticket.category === 'request',
                                                          'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400': ticket.category === 'support'
                                                      }" x-text="ticket.category"></span>
                                                <span class="text-[10px] text-slate-400" x-text="'#' + ticket.id"></span>
                                            </div>
                                            <p class="text-sm font-bold text-slate-800 dark:text-white truncate" x-text="ticket.subject"></p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 line-clamp-1" x-text="ticket.message"></p>
                                            <p class="text-[10px] text-slate-400 mt-1">
                                                <span x-text="ticket.first_name + ' ' + ticket.last_name"></span> · 
                                                <span x-text="formatDate(ticket.created_at)"></span>
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold"
                                                  :class="{
                                                      'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400': ticket.status === 'open',
                                                      'bg-violet-100 dark:bg-violet-500/20 text-violet-600 dark:text-violet-400': ticket.status === 'in_progress',
                                                      'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400': ticket.status === 'resolved',
                                                      'bg-slate-100 dark:bg-slate-500/20 text-slate-500 dark:text-slate-400': ticket.status === 'closed'
                                                  }" x-text="ticket.status.replace('_', ' ')"></span>
                                            <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 transition-transform" :class="expandedTicket === ticket.id ? 'rotate-180' : ''"></i>
                                        </div>
                                    </div>

                                    <!-- Expanded Reply Area -->
                                    <div x-show="expandedTicket === ticket.id" x-transition @click.stop class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800">
                                        <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl p-4 mb-3">
                                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Your Message:</p>
                                            <p class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-line" x-text="ticket.message"></p>
                                        </div>
                                        <template x-if="ticket.admin_reply">
                                            <div class="bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-900/10 dark:to-orange-900/10 rounded-xl p-4 border border-amber-200 dark:border-amber-800/30">
                                                <p class="text-xs font-bold text-amber-600 dark:text-amber-400 mb-1 flex items-center gap-1">
                                                    <i data-lucide="message-circle" class="w-3 h-3"></i> Admin Response
                                                </p>
                                                <p class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-line" x-text="ticket.admin_reply"></p>
                                                <p class="text-[10px] text-amber-500/70 mt-2" x-text="ticket.replied_at ? formatDate(ticket.replied_at) : ''"></p>
                                            </div>
                                        </template>
                                        <template x-if="!ticket.admin_reply">
                                            <div class="text-center py-4">
                                                <i data-lucide="clock" class="w-6 h-6 text-slate-300 dark:text-slate-600 mx-auto mb-1"></i>
                                                <p class="text-xs text-slate-400">Awaiting response from support team</p>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </main>

            <script>
            function supportCenter() {
                return {
                    showForm: false,
                    submitting: false,
                    loading: true,
                    tickets: [],
                    filter: '',
                    expandedTicket: null,
                    form: { category: 'enquiry', subject: '', message: '' },

                    async init() {
                        await this.loadTickets();
                        this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
                    },

                    async loadTickets() {
                        this.loading = true;
                        try {
                            const fd = new FormData();
                            fd.append('action', 'list_tickets');
                            const res = await fetch('../ajax/support_api.php', { method: 'POST', body: fd });
                            const data = await res.json();
                            if (data.success) this.tickets = data.data || [];
                        } catch (e) { console.error(e); }
                        this.loading = false;
                        this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
                    },

                    filteredTickets() {
                        if (!this.filter) return this.tickets;
                        return this.tickets.filter(t => t.status === this.filter);
                    },

                    toggleTicket(id) {
                        this.expandedTicket = this.expandedTicket === id ? null : id;
                        this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
                    },

                    async submitTicket() {
                        if (!this.form.subject.trim() || !this.form.message.trim()) {
                            alert('Please enter a subject and message.');
                            return;
                        }
                        this.submitting = true;
                        try {
                            const fd = new FormData();
                            fd.append('action', 'submit_ticket');
                            fd.append('category', this.form.category);
                            fd.append('subject', this.form.subject);
                            fd.append('message', this.form.message);
                            const res = await fetch('../ajax/support_api.php', { method: 'POST', body: fd });
                            const data = await res.json();
                            if (data.success) {
                                this.form = { category: 'enquiry', subject: '', message: '' };
                                this.showForm = false;
                                await this.loadTickets();
                                alert('✓ ' + (data.message || 'Ticket submitted successfully!'));
                            } else {
                                alert('✗ ' + (data.message || 'Failed to submit ticket.'));
                            }
                        } catch (e) {
                            alert('Network error. Please try again.');
                        }
                        this.submitting = false;
                    },

                    formatDate(dateStr) {
                        if (!dateStr) return '';
                        const d = new Date(dateStr);
                        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
                               d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                    }
                };
            }
            </script>
            <?php endif; ?>
        </div>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>
