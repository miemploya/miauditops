<?php
/**
 * MIAUDITOPS — Public Landing Page
 */
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MIAUDITOPS — Operational Intelligence & Financial Control System</title>
    <meta name="description" content="MIAUDITOPS by Miemploya: A centralized financial control, audit monitoring, stock intelligence, and requisition management system for revenue-sensitive businesses.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] }, colors: { brand: { 50:'#f5f3ff',100:'#ede9fe',200:'#ddd6fe',300:'#c4b5fd',400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed',700:'#6d28d9',800:'#5b21b6',900:'#4c1d95',950:'#2e1065' } } } }
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="assets/js/theme-toggle.js"></script>
    <style>
        @keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-20px)} }
        @keyframes float2 { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-15px)} }
        @keyframes pulse-slow { 0%,100%{opacity:0.3} 50%{opacity:0.7} }
        @keyframes slide-up { from{opacity:0;transform:translateY(40px)} to{opacity:1;transform:translateY(0)} }
        .float-1 { animation: float 6s ease-in-out infinite; }
        .float-2 { animation: float2 8s ease-in-out 1s infinite; }
        .pulse-slow { animation: pulse-slow 4s ease-in-out infinite; }
        .slide-up { animation: slide-up 0.8s ease-out forwards; }
        .slide-up-delay { animation: slide-up 0.8s ease-out 0.2s forwards; opacity: 0; }
        .slide-up-delay-2 { animation: slide-up 0.8s ease-out 0.4s forwards; opacity: 0; }
    </style>
</head>
<body class="font-sans bg-white dark:bg-slate-950 text-slate-800 dark:text-white transition-colors duration-300">

<!-- Navigation -->
<nav class="fixed top-0 left-0 right-0 z-50 bg-white/80 dark:bg-slate-950/80 backdrop-blur-xl border-b border-slate-200 dark:border-white/5 transition-colors">
    <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/30">
                <i data-lucide="shield-check" class="w-5 h-5 text-white"></i>
            </div>
            <span class="text-lg font-black tracking-tight">MIAUDITOPS</span>
        </div>
        <div class="flex items-center gap-3">
            <!-- Nav Links (Hidden on mobile) -->
            <a href="#features" class="hidden md:inline-block text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-colors">Features</a>
            <a href="pricing.php" class="hidden md:inline-flex items-center gap-1.5 text-sm font-semibold text-violet-600 dark:text-violet-400 hover:text-violet-700 dark:hover:text-violet-300 transition-colors">
                <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Pricing
            </a>
            <!-- Theme Toggle -->
            <button class="theme-toggle-btn w-9 h-9 rounded-xl bg-slate-100 dark:bg-white/10 border border-slate-200 dark:border-white/10 flex items-center justify-center hover:bg-slate-200 dark:hover:bg-white/20 transition-all" title="Toggle theme">
                <svg class="icon-sun w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                <svg class="icon-moon w-5 h-5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            </button>
            <a href="auth/login.php" class="text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-colors">Sign In</a>
            <a href="auth/signup.php" class="px-5 py-2 bg-gradient-to-r from-violet-600 to-purple-600 text-white text-sm font-bold rounded-xl shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-105 transition-all">
                Get Started
            </a>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-16">
    <!-- Background Orbs -->
    <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-violet-400/10 dark:bg-violet-600/20 rounded-full blur-3xl float-1"></div>
    <div class="absolute bottom-1/4 right-1/4 w-[500px] h-[500px] bg-blue-400/10 dark:bg-blue-600/15 rounded-full blur-3xl float-2"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[700px] h-[700px] bg-purple-400/5 dark:bg-purple-600/10 rounded-full blur-3xl pulse-slow"></div>

    <div class="relative z-10 max-w-5xl mx-auto px-6 text-center">
        <div class="slide-up">
            <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-violet-500/10 border border-violet-500/20 text-violet-600 dark:text-violet-300 text-sm font-semibold mb-6">
                <i data-lucide="sparkles" class="w-4 h-4"></i> Miemploya Operational Intelligence
            </span>
        </div>
        
        <h1 class="text-5xl sm:text-6xl lg:text-7xl font-black tracking-tight leading-tight slide-up">
            <span class="bg-gradient-to-r from-slate-800 via-slate-600 to-slate-500 dark:from-white dark:via-slate-200 dark:to-slate-400 bg-clip-text text-transparent">Financial Control.</span><br>
            <span class="bg-gradient-to-r from-violet-600 via-purple-500 to-blue-500 dark:from-violet-400 dark:via-purple-400 dark:to-blue-400 bg-clip-text text-transparent">Audit Intelligence.</span>
        </h1>
        
        <p class="text-lg text-slate-500 dark:text-slate-400 mt-6 max-w-2xl mx-auto leading-relaxed slide-up-delay">
            A centralized system for <strong class="text-slate-700 dark:text-slate-200">revenue reconciliation</strong>, <strong class="text-slate-700 dark:text-slate-200">stock control</strong>, <strong class="text-slate-700 dark:text-slate-200">profit monitoring</strong>, and <strong class="text-slate-700 dark:text-slate-200">procurement management</strong> — built for revenue-sensitive businesses.
        </p>

        <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mt-10 slide-up-delay-2">
            <a href="auth/signup.php" class="px-8 py-3.5 bg-gradient-to-r from-violet-600 to-purple-600 text-white font-bold rounded-xl shadow-2xl shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-105 transition-all text-sm flex items-center gap-2">
                <i data-lucide="rocket" class="w-5 h-5"></i> Start Free Trial
            </a>
            <a href="#features" class="px-8 py-3.5 bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 text-slate-700 dark:text-white font-semibold rounded-xl hover:bg-slate-200 dark:hover:bg-white/10 transition-all text-sm flex items-center gap-2">
                <i data-lucide="play" class="w-5 h-5"></i> See Features
            </a>
        </div>
    </div>
</section>

<!-- Feature Modules -->
<section id="features" class="py-24 relative">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <span class="text-sm font-bold uppercase tracking-[0.2em] text-violet-600 dark:text-violet-400">Core Modules</span>
            <h2 class="text-4xl font-black text-slate-800 dark:text-white mt-3">Everything You Need to Control Operations</h2>
            <p class="text-slate-500 dark:text-slate-400 mt-3 max-w-xl mx-auto">Five integrated modules working together to eliminate revenue leakage, stock manipulation, and unauthorized spending.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- Daily Audit -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-blue-400 dark:hover:border-blue-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-blue-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center shadow-xl shadow-blue-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="clipboard-check" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Daily Audit & Sales Control</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">POS vs Cash vs Transfer reconciliation, variance detection, shift closure validation, and dual sign-off audit workflows.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">Reconciliation</span>
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">Variance</span>
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">Sign-Off</span>
                </div>
            </div>

            <!-- Stock Control -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-emerald-400 dark:hover:border-emerald-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-emerald-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-xl shadow-emerald-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="package" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Stock & Inventory Intelligence</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">Real-time stock tracking, supplier deliveries, physical count verification, wastage logging, and automatic valuation.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 text-xs font-semibold">Tracking</span>
                    <span class="px-2 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 text-xs font-semibold">Valuation</span>
                    <span class="px-2 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 text-xs font-semibold">Alerts</span>
                </div>
            </div>

            <!-- Financial Control -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-amber-400 dark:hover:border-amber-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-amber-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-xl shadow-amber-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="trending-up" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Financial Control & P&L</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">Revenue aggregation, expense classification, cost center allocation, and auto-generated P&L statements with margin analysis.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-500/10 text-amber-600 dark:text-amber-300 text-xs font-semibold">P&L</span>
                    <span class="px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-500/10 text-amber-600 dark:text-amber-300 text-xs font-semibold">Margins</span>
                    <span class="px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-500/10 text-amber-600 dark:text-amber-300 text-xs font-semibold">Cost Centers</span>
                </div>
            </div>

            <!-- Requisitions -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-rose-400 dark:hover:border-rose-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-rose-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-rose-500 to-pink-600 flex items-center justify-center shadow-xl shadow-rose-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="file-text" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Requisition Management</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">Multi-level procurement approval workflow — from department request through audit verification to purchase order conversion.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-rose-100 dark:bg-rose-500/10 text-rose-600 dark:text-rose-300 text-xs font-semibold">Workflow</span>
                    <span class="px-2 py-1 rounded-lg bg-rose-100 dark:bg-rose-500/10 text-rose-600 dark:text-rose-300 text-xs font-semibold">Budget</span>
                    <span class="px-2 py-1 rounded-lg bg-rose-100 dark:bg-rose-500/10 text-rose-600 dark:text-rose-300 text-xs font-semibold">PO</span>
                </div>
            </div>

            <!-- Reporting -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-cyan-400 dark:hover:border-cyan-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-cyan-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-xl shadow-cyan-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="bar-chart-3" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Reporting Engine</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">Filterable, exportable reports across all modules. Audit summaries, financial overviews, stock movement, and budget impact — all in one place.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-cyan-100 dark:bg-cyan-500/10 text-cyan-600 dark:text-cyan-300 text-xs font-semibold">PDF/Excel</span>
                    <span class="px-2 py-1 rounded-lg bg-cyan-100 dark:bg-cyan-500/10 text-cyan-600 dark:text-cyan-300 text-xs font-semibold">Filters</span>
                    <span class="px-2 py-1 rounded-lg bg-cyan-100 dark:bg-cyan-500/10 text-cyan-600 dark:text-cyan-300 text-xs font-semibold">Scheduled</span>
                </div>
            </div>

            <!-- Executive Dashboard -->
            <div class="group relative bg-gradient-to-br from-violet-100 dark:from-violet-600/20 to-purple-100 dark:to-purple-600/20 backdrop-blur-sm rounded-2xl border border-violet-300 dark:border-violet-500/30 p-8 hover:bg-violet-200/50 dark:hover:bg-violet-600/30 transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-violet-500/20 rounded-full blur-2xl"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-xl shadow-violet-500/40 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="layout-dashboard" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Executive Dashboard</h3>
                <p class="text-slate-600 dark:text-slate-300 text-sm leading-relaxed mb-4">CEO-level overview with real-time KPIs — revenue, expenses, profit, stock value, variances, and pending requisitions, all on one screen.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-violet-200 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300 text-xs font-semibold">Real-time</span>
                    <span class="px-2 py-1 rounded-lg bg-violet-200 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300 text-xs font-semibold">KPIs</span>
                    <span class="px-2 py-1 rounded-lg bg-violet-200 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300 text-xs font-semibold">Charts</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="py-24 relative overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-br from-violet-100/50 dark:from-violet-600/10 via-purple-50 dark:via-purple-600/5 to-transparent"></div>
    <div class="relative z-10 max-w-3xl mx-auto px-6 text-center">
        <h2 class="text-4xl font-black text-slate-800 dark:text-white mb-4">Ready to Take Control?</h2>
        <p class="text-slate-500 dark:text-slate-400 text-lg mb-8">Join businesses that trust MIAUDITOPS for real-time financial visibility and operational intelligence.</p>
        <a href="auth/signup.php" class="inline-flex items-center gap-2 px-8 py-4 bg-gradient-to-r from-violet-600 to-purple-600 text-white font-bold rounded-xl shadow-2xl shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-105 transition-all text-sm">
            <i data-lucide="shield-check" class="w-5 h-5"></i> Register Your Company Now
        </a>
    </div>
</section>

<!-- Footer -->
<footer class="border-t border-slate-200 dark:border-white/5 py-8">
    <div class="max-w-7xl mx-auto px-6 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center">
                <i data-lucide="shield-check" class="w-4 h-4 text-white"></i>
            </div>
            <span class="text-sm font-bold">MIAUDITOPS</span>
        </div>
        <p class="text-xs text-slate-500">&copy; <?php echo date('Y'); ?> Miemploya. All rights reserved.</p>
    </div>
</footer>

<script>lucide.createIcons();</script>
</body>
</html>
