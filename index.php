<?php
/**
 * MIAUDITOPS — Public Landing Page
 */
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard/index.php');
    exit;
}

// Load dynamic pricing from platform_settings (owner-controlled)
require_once 'config/db.php';
require_once 'config/paystack.php';
$dynamic_prices = get_dynamic_prices();
$pro_price  = number_format($dynamic_prices['professional_monthly']);
$ent_price  = number_format($dynamic_prices['enterprise_monthly']);
$hotel_price = number_format($dynamic_prices['hotel_revenue_monthly'] ?? 200000);

// Load active testimonials
$testimonials = [];
try {
    $t_stmt = @$pdo->query("SELECT name, title, testimony, photo FROM testimonials WHERE is_active = 1 ORDER BY sort_order ASC, id DESC");
    if ($t_stmt) {
        $testimonials = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {
    // Table may not exist yet
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
        @keyframes slideDown { from{opacity:0;max-height:0} to{opacity:1;max-height:400px} }
        .float-1 { animation: float 6s ease-in-out infinite; }
        .float-2 { animation: float2 8s ease-in-out 1s infinite; }
        .pulse-slow { animation: pulse-slow 4s ease-in-out infinite; }
        .slide-up { animation: slide-up 0.8s ease-out forwards; }
        .slide-up-delay { animation: slide-up 0.8s ease-out 0.2s forwards; opacity: 0; }
        .slide-up-delay-2 { animation: slide-up 0.8s ease-out 0.4s forwards; opacity: 0; }
        .mobile-nav-open { animation: slideDown 0.3s ease-out forwards; }
    </style>
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#6d28d9">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MIAUDITOPS">
    <link rel="apple-touch-icon" href="/uploads/branding/pwa/icon-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="MIAUDITOPS">
</head>
<body class="font-sans bg-white dark:bg-slate-950 text-slate-800 dark:text-white transition-colors duration-300">

<!-- Navigation -->
<nav class="fixed top-0 left-0 right-0 z-50 bg-white/80 dark:bg-slate-950/80 backdrop-blur-xl border-b border-slate-200 dark:border-white/5 transition-colors">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 h-14 sm:h-16 flex items-center justify-between">
        <a href="/" class="flex items-center gap-2">
            <div class="h-[40px] sm:h-[52px] w-[160px] sm:w-[208px] overflow-hidden relative">
                <img src="assets/images/logo.png" alt="MiAuditOps" class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-[180%] object-contain dark:hidden">
                <img src="assets/images/logo-dark.png" alt="MiAuditOps" class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-[180%] object-contain hidden dark:block">
            </div>
        </a>
        <!-- Desktop Nav -->
        <div class="hidden md:flex items-center gap-4">
            <a href="#features" class="text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-colors">Features</a>
            <a href="#pricing" class="inline-flex items-center gap-1.5 text-sm font-semibold text-violet-600 dark:text-violet-400 hover:text-violet-700 dark:hover:text-violet-300 transition-colors">
                <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Pricing
            </a>
            <button class="theme-toggle-btn w-9 h-9 rounded-xl bg-slate-100 dark:bg-white/10 border border-slate-200 dark:border-white/10 flex items-center justify-center hover:bg-slate-200 dark:hover:bg-white/20 transition-all" title="Toggle theme">
                <svg class="icon-sun w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                <svg class="icon-moon w-5 h-5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            </button>
            <a href="auth/login.php" class="text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-colors">Sign In</a>
            <button id="pwa-install-nav" onclick="pwaInstall()" class="hidden items-center gap-1.5 text-emerald-500 hover:text-emerald-600 font-semibold transition-colors" style="display:none">
                <i data-lucide="download" class="w-4 h-4"></i> Install App
            </button>
            <button id="pwa-install-cta" onclick="pwaInstall()" class="hidden items-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white px-5 py-2 rounded-xl font-bold text-sm transition-all shadow-lg shadow-emerald-500/30" style="display:none">
                <i data-lucide="smartphone" class="w-4 h-4"></i> Install App
            </button>
            <a href="auth/signup.php" class="px-5 py-2 bg-gradient-to-r from-violet-600 to-purple-600 text-white text-sm font-bold rounded-xl shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-105 transition-all">
                Get Started
            </a>
        </div>
        <!-- Mobile: Install + Theme + Hamburger -->
        <div class="flex md:hidden items-center gap-2">
            <button id="pwa-install-mobile" onclick="pwaInstall()" class="hidden items-center justify-center w-9 h-9 rounded-xl bg-emerald-500 text-white shadow-md" style="display:none" title="Install App">
                <i data-lucide="download" class="w-4 h-4"></i>
            </button>
            <button class="theme-toggle-btn w-9 h-9 rounded-xl bg-slate-100 dark:bg-white/10 border border-slate-200 dark:border-white/10 flex items-center justify-center" title="Toggle theme">
                <svg class="icon-sun w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                <svg class="icon-moon w-5 h-5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            </button>
            <button id="mobileMenuToggle" class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-white/10 border border-slate-200 dark:border-white/10 flex items-center justify-center hover:bg-slate-200 dark:hover:bg-white/20 transition-all" title="Menu">
                <svg id="menuIconOpen" class="w-5 h-5 text-slate-600 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                <svg id="menuIconClose" class="w-5 h-5 text-slate-600 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
    <!-- Mobile Nav Panel -->
    <div id="mobileNavPanel" class="md:hidden hidden bg-white/95 dark:bg-slate-950/95 backdrop-blur-xl border-t border-slate-200/60 dark:border-white/5 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 py-4 flex flex-col gap-2">
            <a href="#features" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-white/5 transition-colors">
                <i data-lucide="layers" class="w-4 h-4 text-violet-500"></i> Features
            </a>
            <a href="#pricing" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-white/5 transition-colors">
                <i data-lucide="sparkles" class="w-4 h-4 text-violet-500"></i> Pricing
            </a>
            <div class="border-t border-slate-200 dark:border-white/10 my-1"></div>
            <a href="auth/login.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-white/5 transition-colors">
                <i data-lucide="log-in" class="w-4 h-4 text-slate-400"></i> Sign In
            </a>
            <a href="auth/signup.php" class="flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 text-white text-sm font-bold shadow-lg shadow-violet-500/20">
                <i data-lucide="rocket" class="w-4 h-4"></i> Get Started Free
            </a>
            <button id="pwa-install-menu" onclick="pwaInstall()" class="hidden items-center justify-center gap-3 px-4 py-3 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-bold shadow-lg shadow-emerald-500/20 transition-colors" style="display:none">
                <i data-lucide="download" class="w-4 h-4"></i> Install App on Device
            </button>
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
        
        <h1 class="text-3xl sm:text-5xl md:text-6xl lg:text-7xl font-black tracking-tight leading-tight slide-up">
            <span class="bg-gradient-to-r from-slate-800 via-slate-600 to-slate-500 dark:from-white dark:via-slate-200 dark:to-slate-400 bg-clip-text text-transparent">Financial Control.</span><br>
            <span class="bg-gradient-to-r from-violet-600 via-purple-500 to-blue-500 dark:from-violet-400 dark:via-purple-400 dark:to-blue-400 bg-clip-text text-transparent">Audit Intelligence.</span>
        </h1>
        
        <p class="text-sm sm:text-base md:text-lg text-slate-500 dark:text-slate-400 mt-4 sm:mt-6 max-w-2xl mx-auto leading-relaxed slide-up-delay">
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
            <h2 class="text-2xl sm:text-3xl md:text-4xl font-black text-slate-800 dark:text-white mt-3">Seven Powerful Modules. One Unified Control Center.</h2>
            <p class="text-slate-500 dark:text-slate-400 mt-3 max-w-2xl mx-auto">From daily sales reconciliation to filling station pump audits — every module works together to eliminate revenue leakage, prevent stock manipulation, and enforce financial discipline across your entire operation.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- Daily Audit & Sales Control -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-blue-400 dark:hover:border-blue-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-blue-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center shadow-xl shadow-blue-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="clipboard-check" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Daily Audit & Sales Control</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">Record every naira that flows through your business — POS, cash, and bank transfers. Automatically detect discrepancies between system totals and declared amounts, flag variances instantly, and enforce dual sign-off workflows before any shift can close. Bank lodgment tracking ensures cash never disappears between the till and the bank.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">POS/Cash/Transfer</span>
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">Variance Detection</span>
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">Bank Lodgment</span>
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">Dual Sign-Off</span>
                </div>
            </div>

            <!-- Stock & Inventory Intelligence -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-emerald-400 dark:hover:border-emerald-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-emerald-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-xl shadow-emerald-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="package" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Stock & Inventory Intelligence</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">Track every item from supplier delivery to department issue. Log stock-in, returns, wastage, and physical counts with real-time balance updates. Multi-department stores let you monitor central warehouses and satellite locations independently. Automatic stock valuation keeps your balance sheet accurate down to the last unit.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 text-xs font-semibold">Live Tracking</span>
                    <span class="px-2 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 text-xs font-semibold">Multi-Department</span>
                    <span class="px-2 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 text-xs font-semibold">Valuation</span>
                    <span class="px-2 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 text-xs font-semibold">Wastage Log</span>
                </div>
            </div>

            <!-- Financial Control & P&L -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-amber-400 dark:hover:border-amber-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-amber-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-xl shadow-amber-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="trending-up" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Financial Control & P&L</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">Aggregate outlet-level revenue, categorize operational and administrative expenses, and allocate costs across departments. The system auto-generates monthly Profit & Loss statements using real stock-based Cost of Sales — calculated from opening stock, purchases, and closing stock — giving you true gross margin visibility, not estimates.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-500/10 text-amber-600 dark:text-amber-300 text-xs font-semibold">Revenue & Expenses</span>
                    <span class="px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-500/10 text-amber-600 dark:text-amber-300 text-xs font-semibold">P&L Statements</span>
                    <span class="px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-500/10 text-amber-600 dark:text-amber-300 text-xs font-semibold">Cost Centers</span>
                    <span class="px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-500/10 text-amber-600 dark:text-amber-300 text-xs font-semibold">Stock Valuation</span>
                </div>
            </div>

            <!-- Station Audit (Featured — full width on mobile, highlighted) -->
            <div class="group relative md:col-span-2 lg:col-span-3 bg-gradient-to-br from-orange-50 via-amber-50 dark:from-orange-600/10 dark:via-amber-600/5 to-white dark:to-slate-950 rounded-2xl border-2 border-orange-300 dark:border-orange-500/30 p-8 lg:p-10 hover:bg-orange-100/50 dark:hover:bg-orange-600/15 transition-all duration-300">
                <div class="absolute top-4 right-4">
                    <span class="px-3 py-1 rounded-full bg-gradient-to-r from-orange-500 to-red-500 text-white text-[10px] font-bold uppercase tracking-wider shadow-lg shadow-orange-500/30">Enterprise Exclusive</span>
                </div>
                <div class="lg:flex lg:items-start lg:gap-10">
                    <div class="flex-shrink-0 mb-6 lg:mb-0">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-orange-500 via-red-500 to-rose-600 flex items-center justify-center shadow-xl shadow-orange-500/40 group-hover:scale-110 transition-transform">
                            <i data-lucide="fuel" class="w-8 h-8 text-white"></i>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-2xl font-black text-slate-800 dark:text-white mb-3">Filling Station Audit Module</h3>
                        <p class="text-slate-600 dark:text-slate-300 text-sm leading-relaxed mb-4">
                            Purpose-built for petroleum downstream operations. Track every litre from delivery tanker to pump nozzle with session-based audits that cover the full station lifecycle. 
                            <strong class="text-slate-700 dark:text-white">System Sales</strong> captures POS terminal, cash, and transfer receipts with variance analysis against pump meter readings. 
                            <strong class="text-slate-700 dark:text-white">Pump Sales</strong> records opening and closing meter readings per nozzle to calculate exact litres sold, flagging discrepancies between metered and declared volumes. 
                            <strong class="text-slate-700 dark:text-white">Tank Dipping</strong> logs physical tank measurements — depth, volume, water level, temperature compensation, and capacity — to verify stock levels against book records and detect hidden shortages. 
                            <strong class="text-slate-700 dark:text-white">Haulage Tracking</strong> monitors every fuel delivery: tanker details, compartment volumes, product grades (PMS, AGO, LPG, DPK), seals, and waybill verification — ensuring what arrives matches what was dispatched. 
                            The module also includes a <strong class="text-slate-700 dark:text-white">Lubricant Store</strong> for managing oil, grease, and lube products with GRN receipts, issue tracking, and counter stock counts.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <span class="px-2.5 py-1 rounded-lg bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-300 text-xs font-semibold">Pump Meter Reading</span>
                            <span class="px-2.5 py-1 rounded-lg bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-300 text-xs font-semibold">Tank Dipping</span>
                            <span class="px-2.5 py-1 rounded-lg bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-300 text-xs font-semibold">Haulage & Delivery</span>
                            <span class="px-2.5 py-1 rounded-lg bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-300 text-xs font-semibold">Lube Store</span>
                            <span class="px-2.5 py-1 rounded-lg bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-300 text-xs font-semibold">Session Audit</span>
                            <span class="px-2.5 py-1 rounded-lg bg-orange-100 dark:bg-orange-500/15 text-orange-700 dark:text-orange-300 text-xs font-semibold">General Report</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Requisition Management -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-rose-400 dark:hover:border-rose-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-rose-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-rose-500 to-pink-600 flex items-center justify-center shadow-xl shadow-rose-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="file-text" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Requisition & Procurement</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">Enforce spending discipline with a multi-level approval pipeline. Staff submit purchase requests, supervisors approve, auditors verify, and management gives final sign-off — converting approved requisitions into traceable Purchase Orders. Budget visibility at every stage prevents unauthorized spending before it happens.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-rose-100 dark:bg-rose-500/10 text-rose-600 dark:text-rose-300 text-xs font-semibold">Approval Chain</span>
                    <span class="px-2 py-1 rounded-lg bg-rose-100 dark:bg-rose-500/10 text-rose-600 dark:text-rose-300 text-xs font-semibold">Purchase Orders</span>
                    <span class="px-2 py-1 rounded-lg bg-rose-100 dark:bg-rose-500/10 text-rose-600 dark:text-rose-300 text-xs font-semibold">Budget Control</span>
                    <span class="px-2 py-1 rounded-lg bg-rose-100 dark:bg-rose-500/10 text-rose-600 dark:text-rose-300 text-xs font-semibold">Audit Trail</span>
                </div>
            </div>

            <!-- Reporting Engine -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-cyan-400 dark:hover:border-cyan-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-cyan-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-xl shadow-cyan-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="bar-chart-3" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Reporting & Analytics</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">Consolidated, filterable reports across every module — sales reconciliation summaries, stock movement history, financial overviews, and requisition impact analysis. Export to PDF or Excel for board presentations, stakeholder reviews, or regulatory compliance. Date-range filters, outlet breakdowns, and trend charts turn raw data into actionable intelligence.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-cyan-100 dark:bg-cyan-500/10 text-cyan-600 dark:text-cyan-300 text-xs font-semibold">PDF / Excel Export</span>
                    <span class="px-2 py-1 rounded-lg bg-cyan-100 dark:bg-cyan-500/10 text-cyan-600 dark:text-cyan-300 text-xs font-semibold">Date Filters</span>
                    <span class="px-2 py-1 rounded-lg bg-cyan-100 dark:bg-cyan-500/10 text-cyan-600 dark:text-cyan-300 text-xs font-semibold">Trend Charts</span>
                    <span class="px-2 py-1 rounded-lg bg-cyan-100 dark:bg-cyan-500/10 text-cyan-600 dark:text-cyan-300 text-xs font-semibold">Outlet Breakdown</span>
                </div>
            </div>

            <!-- Executive Dashboard -->
            <div class="group relative bg-gradient-to-br from-violet-100 dark:from-violet-600/20 to-purple-100 dark:to-purple-600/20 backdrop-blur-sm rounded-2xl border border-violet-300 dark:border-violet-500/30 p-8 hover:bg-violet-200/50 dark:hover:bg-violet-600/30 transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-violet-500/20 rounded-full blur-2xl"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-xl shadow-violet-500/40 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="layout-dashboard" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Executive Dashboard</h3>
                <p class="text-slate-600 dark:text-slate-300 text-sm leading-relaxed mb-4">A CEO-level command center with real-time KPIs — total revenue, expenses, net profit, stock value, open variances, and pending requisitions. Interactive charts visualize daily sales trends, outlet performance comparisons, and expense breakdowns. One screen, complete operational visibility — no spreadsheets needed.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-violet-200 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300 text-xs font-semibold">Real-time KPIs</span>
                    <span class="px-2 py-1 rounded-lg bg-violet-200 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300 text-xs font-semibold">Interactive Charts</span>
                    <span class="px-2 py-1 rounded-lg bg-violet-200 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300 text-xs font-semibold">Outlet Comparisons</span>
                    <span class="px-2 py-1 rounded-lg bg-violet-200 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300 text-xs font-semibold">One-Screen View</span>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- Pricing Section -->
<section id="pricing" class="py-24 relative">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <span class="text-sm font-bold uppercase tracking-[0.2em] text-violet-600 dark:text-violet-400">Pricing</span>
            <h2 class="text-2xl sm:text-3xl md:text-4xl font-black text-slate-800 dark:text-white mt-3">Simple, Transparent Pricing</h2>
            <p class="text-slate-500 dark:text-slate-400 mt-3 max-w-xl mx-auto">Start free. Scale as you grow. Every plan includes core audit and stock control.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

            <!-- Starter -->
            <div class="relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 flex flex-col hover:border-slate-400 dark:hover:border-white/20 hover:-translate-y-1 transition-all duration-300">
                <div class="mb-6">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-slate-400 to-slate-600 flex items-center justify-center shadow-lg shadow-slate-500/20 mb-4">
                        <i data-lucide="rocket" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="text-xl font-black text-slate-800 dark:text-white">Starter</h3>
                    <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-slate-200 dark:bg-white/10 text-slate-600 dark:text-slate-400 text-[10px] font-bold uppercase tracking-wider">Free Forever</span>
                </div>
                <div class="mb-6">
                    <span class="text-4xl font-black text-slate-800 dark:text-white">₦0</span>
                    <span class="text-sm text-slate-500 dark:text-slate-400">/month</span>
                </div>
                <ul class="space-y-3 mb-8 flex-1">
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-emerald-500 mt-0.5 shrink-0"></i> 2 Users
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-emerald-500 mt-0.5 shrink-0"></i> 1 Client / 2 Outlets
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-emerald-500 mt-0.5 shrink-0"></i> 20 Products
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-emerald-500 mt-0.5 shrink-0"></i> Sales Entry + Stock In
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-emerald-500 mt-0.5 shrink-0"></i> 90 Days Data Retention
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-500 dark:text-slate-500">
                        <i data-lucide="x" class="w-4 h-4 text-slate-400 mt-0.5 shrink-0"></i> No PDF Export
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-500 dark:text-slate-500">
                        <i data-lucide="x" class="w-4 h-4 text-slate-400 mt-0.5 shrink-0"></i> No Finance Module
                    </li>
                </ul>
                <a href="auth/signup.php" class="block w-full text-center px-6 py-3 rounded-xl border-2 border-slate-300 dark:border-white/20 text-slate-700 dark:text-white font-bold text-sm hover:bg-slate-100 dark:hover:bg-white/5 transition-all">
                    Get Started Free
                </a>
            </div>

            <!-- Professional (Highlighted) -->
            <div class="relative bg-gradient-to-b from-violet-50 dark:from-violet-600/10 to-white dark:to-slate-950 rounded-2xl border-2 border-violet-400 dark:border-violet-500/50 p-8 flex flex-col shadow-xl shadow-violet-500/10 hover:-translate-y-2 transition-all duration-300">
                <div class="absolute -top-4 left-1/2 -translate-x-1/2">
                    <span class="px-4 py-1 rounded-full bg-gradient-to-r from-violet-600 to-purple-600 text-white text-xs font-bold shadow-lg shadow-violet-500/30">Most Popular</span>
                </div>
                <div class="mb-6">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/30 mb-4">
                        <i data-lucide="briefcase" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="text-xl font-black text-slate-800 dark:text-white">Professional</h3>
                    <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-violet-100 dark:bg-violet-500/20 text-violet-600 dark:text-violet-300 text-[10px] font-bold uppercase tracking-wider">Most Popular</span>
                </div>
                <div class="mb-6">
                    <span class="text-4xl font-black text-slate-800 dark:text-white">₦<?php echo $pro_price; ?></span>
                    <span class="text-sm text-slate-500 dark:text-slate-400">/month</span>
                </div>
                <ul class="space-y-3 mb-8 flex-1">
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> 4 Users
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> 1 Client / 10 Outlets
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> Unlimited Products
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> Full Audit + Stock Modules
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> Revenue & Expenses Tracking
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> Monthly P&L Statement
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> PDF Export
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> 1 Year Data Retention
                    </li>
                </ul>
                <a href="pricing.php" class="block w-full text-center px-6 py-3 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 text-white font-bold text-sm shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-[1.02] transition-all">
                    Start 7-Day Trial
                </a>
            </div>

            <!-- Enterprise -->
            <div class="relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 flex flex-col hover:border-amber-400 dark:hover:border-amber-500/30 hover:-translate-y-1 transition-all duration-300">
                <div class="mb-6">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30 mb-4">
                        <i data-lucide="crown" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="text-xl font-black text-slate-800 dark:text-white">Enterprise</h3>
                    <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-300 text-[10px] font-bold uppercase tracking-wider">Full Power</span>
                </div>
                <div class="mb-6">
                    <span class="text-4xl font-black text-slate-800 dark:text-white">₦<?php echo $ent_price; ?></span>
                    <span class="text-sm text-slate-500 dark:text-slate-400">/month</span>
                </div>
                <ul class="space-y-3 mb-8 flex-1">
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Unlimited Users
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> 3 Clients / 30 Outlets
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Unlimited Products & Departments
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> All Modules Unlocked
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Full P&L + Cost Centers
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Station Audit Module
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Unlimited Data Retention
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Priority Support
                    </li>
                </ul>
                <a href="pricing.php" class="block w-full text-center px-6 py-3 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold text-sm shadow-lg shadow-amber-500/30 hover:shadow-amber-500/50 hover:scale-[1.02] transition-all">
                    Start 7-Day Trial
                </a>
            </div>

            <!-- Hotel Revenue -->
            <div class="relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 flex flex-col hover:border-blue-400 dark:hover:border-blue-500/30 hover:-translate-y-1 transition-all duration-300">
                <div class="mb-6">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center shadow-lg shadow-blue-500/30 mb-4">
                        <i data-lucide="building" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="text-xl font-black text-slate-800 dark:text-white">Hotel Revenue</h3>
                    <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-300 text-[10px] font-bold uppercase tracking-wider">Premium Module</span>
                </div>
                <div class="mb-6">
                    <span class="text-4xl font-black text-slate-800 dark:text-white">₦<?php echo $hotel_price; ?></span>
                    <span class="text-sm text-slate-500 dark:text-slate-400">/month</span>
                </div>
                <ul class="space-y-3 mb-8 flex-1">
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Unlimited Users
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Hotel Revenue Audit
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Overtime Tracking
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Daily Reports
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Unlimited Data Retention
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-500 dark:text-slate-500">
                        <i data-lucide="x" class="w-4 h-4 text-slate-400 mt-0.5 shrink-0"></i> No Stock Control
                    </li>
                </ul>
                <a href="pricing.php" class="block w-full text-center px-6 py-3 rounded-xl border-2 border-slate-300 dark:border-white/20 text-slate-700 dark:text-white font-bold text-sm hover:bg-slate-100 dark:hover:bg-white/5 transition-all">
                    View Plan Features
                </a>
            </div>

        </div>

        <p class="text-center text-sm text-slate-400 dark:text-slate-500 mt-8">All paid plans include a <strong class="text-violet-600 dark:text-violet-400">7-day free trial</strong>. No credit card required to start. <a href="pricing.php" class="text-violet-600 dark:text-violet-400 font-bold hover:underline">View full comparison →</a></p>
    </div>
</section>

<?php if (!empty($testimonials)): ?>
<!-- Testimonials Section -->
<section class="py-24 relative">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <span class="text-sm font-bold uppercase tracking-[0.2em] text-violet-600 dark:text-violet-400">Testimonials</span>
            <h2 class="text-2xl sm:text-3xl md:text-4xl font-black text-slate-800 dark:text-white mt-3">What Our Users Say</h2>
            <p class="text-slate-500 dark:text-slate-400 mt-3 max-w-xl mx-auto">Real feedback from businesses using MIAUDITOPS for financial control and operational intelligence.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($testimonials as $t): ?>
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 text-center hover:border-violet-400 dark:hover:border-violet-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <!-- Quote Icon -->
                <div class="absolute -top-3 left-1/2 -translate-x-1/2 w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/30">
                    <i data-lucide="quote" class="w-4 h-4 text-white"></i>
                </div>
                <!-- Photo -->
                <div class="w-20 h-20 rounded-full mx-auto mb-4 mt-2 overflow-hidden border-2 border-violet-500/20 bg-slate-200 dark:bg-slate-800 flex items-center justify-center">
                    <?php if (!empty($t['photo'])): ?>
                        <img src="<?php echo htmlspecialchars($t['photo']); ?>" alt="<?php echo htmlspecialchars($t['name']); ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <span class="text-2xl font-black text-slate-400 dark:text-slate-600"><?php echo strtoupper(substr($t['name'], 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <!-- Content -->
                <p class="text-sm text-slate-600 dark:text-slate-300 italic leading-relaxed mb-5">&ldquo;<?php echo htmlspecialchars($t['testimony']); ?>&rdquo;</p>
                <div class="border-t border-slate-200 dark:border-white/10 pt-4">
                    <h4 class="text-sm font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($t['name']); ?></h4>
                    <?php if (!empty($t['title'])): ?>
                        <p class="text-xs text-violet-600 dark:text-violet-400 font-semibold"><?php echo htmlspecialchars($t['title']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- CTA Section -->
<section class="py-24 relative overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-br from-violet-100/50 dark:from-violet-600/10 via-purple-50 dark:via-purple-600/5 to-transparent"></div>
    <div class="relative z-10 max-w-3xl mx-auto px-6 text-center">
        <h2 class="text-2xl sm:text-3xl md:text-4xl font-black text-slate-800 dark:text-white mb-4">Ready to Take Control?</h2>
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

<script>
lucide.createIcons();
// Mobile Menu Toggle
const mobileMenuToggle = document.getElementById('mobileMenuToggle');
const mobileNavPanel = document.getElementById('mobileNavPanel');
const menuIconOpen = document.getElementById('menuIconOpen');
const menuIconClose = document.getElementById('menuIconClose');
if (mobileMenuToggle) {
    mobileMenuToggle.addEventListener('click', function() {
        const isOpen = !mobileNavPanel.classList.contains('hidden');
        if (isOpen) {
            closeMobileMenu();
        } else {
            mobileNavPanel.classList.remove('hidden');
            mobileNavPanel.classList.add('mobile-nav-open');
            menuIconOpen.style.display = 'none';
            menuIconClose.style.display = 'block';
            lucide.createIcons();
        }
    });
}
function closeMobileMenu() {
    if (mobileNavPanel) {
        mobileNavPanel.classList.add('hidden');
        mobileNavPanel.classList.remove('mobile-nav-open');
        if (menuIconOpen) menuIconOpen.style.display = 'block';
        if (menuIconClose) menuIconClose.style.display = 'none';
    }
}
</script>
<!-- PWA Install Prompt Logic -->
<script>
var pwaInstallEvent = null;
window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    pwaInstallEvent = e;
    // Show all install buttons
    ['pwa-install-nav','pwa-install-cta','pwa-install-mobile','pwa-install-menu'].forEach(function(id) {
        var btn = document.getElementById(id);
        if (btn) { btn.style.display = 'inline-flex'; btn.classList.remove('hidden'); }
    });
    lucide.createIcons();
});
function pwaInstall() {
    if (!pwaInstallEvent) return;
    pwaInstallEvent.prompt();
    pwaInstallEvent.userChoice.then(function(result) {
        if (result.outcome === 'accepted') {
            ['pwa-install-nav','pwa-install-cta','pwa-install-mobile','pwa-install-menu'].forEach(function(id) {
                var btn = document.getElementById(id);
                if (btn) btn.style.display = 'none';
            });
        }
        pwaInstallEvent = null;
    });
}
window.addEventListener('appinstalled', function() {
    ['pwa-install-nav','pwa-install-cta','pwa-install-mobile','pwa-install-menu'].forEach(function(id) {
        var btn = document.getElementById(id);
        if (btn) btn.style.display = 'none';
    });
    pwaInstallEvent = null;
});
</script>
<!-- PWA Service Worker (production only) -->
<script>
if ('serviceWorker' in navigator && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/service-worker.js', { scope: '/' })
            .then(function(reg) { console.log('[PWA] SW registered:', reg.scope); })
            .catch(function(err) { console.warn('[PWA] SW failed:', err); });
    });
}
</script>
</body>
</html>

