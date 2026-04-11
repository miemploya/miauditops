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
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
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
        .glass-card { background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(249,250,251,0.9) 100%); backdrop-filter: blur(20px); }
        .dark .glass-card { background: linear-gradient(135deg, rgba(15,23,42,0.95) 0%, rgba(30,41,59,0.9) 100%); }
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
                <i data-lucide="rocket" class="w-4 h-4"></i> Get Started
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
                <i data-lucide="rocket" class="w-5 h-5"></i> Subscribe Now
            </a>
            <a href="#features" class="px-8 py-3.5 bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 text-slate-700 dark:text-white font-semibold rounded-xl hover:bg-slate-200 dark:hover:bg-white/10 transition-all text-sm flex items-center gap-2">
                <i data-lucide="play" class="w-5 h-5"></i> See Features
            </a>
        </div>
    </div>
</section>

<!-- Interactive Demo Dashboard Mockup -->
<section class="max-w-6xl mx-auto px-4 sm:px-6 relative z-20 mt-8 mb-24 slide-up-delay-2" x-data="{
    activeTab: 'sales',
    revenue: 4850000,
    variance: -12500,
    stockValue: 12450000,
    financeRev: 12500000,
    get financeCos() { return this.financeRev * 0.656; },
    get financeProfit() { return this.financeRev - this.financeCos; },
    cashIn: 2100500,
    cashOut: 450000,
    reqDemoArr: [
        {id:'PO-8821', name:'Stationery Restock', amt:45000, status:'Pending', class:'bg-amber-100 text-amber-700'},
        {id:'PO-8820', name:'Bar Inventory', amt:420000, status:'Approved', class:'bg-blue-100 text-blue-700'}
    ],
    pumpEnd: 45890.25,
    get pumpVol() { return this.pumpEnd - 42500.00; },
    hotelBanked: 0,
    hotelExpected: 1450000,
    matchHotel() { this.hotelBanked = 1450000; },
    fmt(n) { return Number(n).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0}); }
}">
    <!-- Glassmorphic Dashboard Window -->
    <div class="rounded-3xl border-2 border-white dark:border-white/10 bg-white/40 dark:bg-slate-900/40 backdrop-blur-3xl shadow-2xl overflow-hidden ring-4 ring-slate-100 dark:ring-slate-800">
        
        <!-- Mac-style Window Header -->
        <div class="h-12 bg-white/60 dark:bg-slate-950/60 border-b border-slate-200/50 dark:border-white/5 flex items-center px-4 justify-between">
            <div class="flex gap-2">
                <div class="w-3 h-3 rounded-full bg-red-400"></div>
                <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                <div class="w-3 h-3 rounded-full bg-emerald-400"></div>
            </div>
            <div class="text-xs font-bold text-slate-400 dark:text-slate-500 font-mono tracking-wider">miauditops.app/dashboard</div>
            <div class="w-16"></div> <!-- spacer -->
        </div>

        <!-- Dashboard Layout -->
        <div class="flex h-[520px]">
            <!-- Sidebar -->
            <div class="w-16 sm:w-64 border-r border-slate-200/50 dark:border-white/5 bg-white/50 dark:bg-slate-950/50 p-3 sm:p-4 flex flex-col gap-1.5 overflow-y-auto" style="scrollbar-width: thin;">
                <div class="hidden sm:block mb-2 pt-1 px-2">
                    <span class="text-[10px] font-black tracking-widest text-violet-600 dark:text-violet-400 uppercase">Interactive Demo</span>
                </div>
                
                <button @click="activeTab='sales'" :class="activeTab==='sales' ? 'bg-blue-600 shadow-lg shadow-blue-500/30 text-white' : 'text-slate-600 dark:text-slate-400 hover:bg-white/50 dark:hover:bg-white/5'" class="w-full flex items-center justify-center sm:justify-start gap-3 p-2.5 rounded-xl transition-all">
                    <i data-lucide="clipboard-check" class="w-4 h-4"></i><span class="hidden sm:block text-sm font-semibold">Sales Audit</span>
                </button>
                <button @click="activeTab='stock'" :class="activeTab==='stock' ? 'bg-emerald-600 shadow-lg shadow-emerald-500/30 text-white' : 'text-slate-600 dark:text-slate-400 hover:bg-white/50 dark:hover:bg-white/5'" class="w-full flex items-center justify-center sm:justify-start gap-3 p-2.5 rounded-xl transition-all">
                    <i data-lucide="package" class="w-4 h-4"></i><span class="hidden sm:block text-sm font-semibold">Stock Audit</span>
                </button>
                <button @click="activeTab='finance'" :class="activeTab==='finance' ? 'bg-amber-600 shadow-lg shadow-amber-500/30 text-white' : 'text-slate-600 dark:text-slate-400 hover:bg-white/50 dark:hover:bg-white/5'" class="w-full flex items-center justify-center sm:justify-start gap-3 p-2.5 rounded-xl transition-all">
                    <i data-lucide="trending-up" class="w-4 h-4"></i><span class="hidden sm:block text-sm font-semibold">Financial Control</span>
                </button>
                <button @click="activeTab='req'" :class="activeTab==='req' ? 'bg-rose-600 shadow-lg shadow-rose-500/30 text-white' : 'text-slate-600 dark:text-slate-400 hover:bg-white/50 dark:hover:bg-white/5'" class="w-full flex items-center justify-center sm:justify-start gap-3 p-2.5 rounded-xl transition-all">
                    <i data-lucide="file-text" class="w-4 h-4"></i><span class="hidden sm:block text-sm font-semibold">Procurement</span>
                </button>
                <button @click="activeTab='cash'" :class="activeTab==='cash' ? 'bg-emerald-600 shadow-lg shadow-emerald-500/30 text-white' : 'text-slate-600 dark:text-slate-400 hover:bg-white/50 dark:hover:bg-white/5'" class="w-full flex items-center justify-center sm:justify-start gap-3 p-2.5 rounded-xl transition-all">
                    <i data-lucide="banknote" class="w-4 h-4"></i><span class="hidden sm:block text-sm font-semibold">Cash Mgmt</span>
                </button>
                <button @click="activeTab='station'" :class="activeTab==='station' ? 'bg-orange-600 shadow-lg shadow-orange-500/30 text-white' : 'text-slate-600 dark:text-slate-400 hover:bg-white/50 dark:hover:bg-white/5'" class="w-full flex items-center justify-center sm:justify-start gap-3 p-2.5 rounded-xl transition-all">
                    <i data-lucide="fuel" class="w-4 h-4"></i><span class="hidden sm:block text-sm font-semibold">Station Audit</span>
                </button>
                <button @click="activeTab='hotel'" :class="activeTab==='hotel' ? 'bg-fuchsia-600 shadow-lg shadow-fuchsia-500/30 text-white' : 'text-slate-600 dark:text-slate-400 hover:bg-white/50 dark:hover:bg-white/5'" class="w-full flex items-center justify-center sm:justify-start gap-3 p-2.5 rounded-xl transition-all">
                    <i data-lucide="building" class="w-4 h-4"></i><span class="hidden sm:block text-sm font-semibold">Hotel Audit & Fraud Suite</span>
                </button>
            </div>
            
            <!-- Main Content Area -->
            <div class="flex-1 p-6 sm:p-10 overflow-y-auto bg-slate-50/50 dark:bg-slate-900/30">
                
                <!-- Sales Tab -->
                <div x-show="activeTab==='sales'" x-transition:enter="transition-all duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-2xl font-black text-slate-800 dark:text-white">Live Audit</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Cross-referencing POS, Cash, and Transfers.</p>
                        </div>
                        <div class="hidden sm:flex items-center gap-2 bg-emerald-100 dark:bg-emerald-500/20 px-3 py-1.5 rounded-lg border border-emerald-200 dark:border-emerald-500/30">
                            <span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span>
                            <span class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-widest">Live Sync</span>
                        </div>
                    </div>

                    <!-- Metric Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
                        <div class="glass-card p-5 rounded-2xl shadow-lg border border-slate-200/60 dark:border-slate-700/60 transition-all hover:-translate-y-1">
                            <div class="flex justify-between items-start mb-2">
                                <div><h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider">Declared Revenue</h3></div>
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-gradient-to-br from-indigo-500 to-violet-600 shadow-lg shadow-indigo-500/30"><i data-lucide="trending-up" class="w-4 h-4 text-white"></i></div>
                            </div>
                            <div class="text-3xl font-black text-slate-800 dark:text-white">₦<span x-text="fmt(revenue)"></span></div>
                        </div>

                        <div class="glass-card p-5 rounded-2xl shadow-lg border relative overflow-hidden cursor-pointer transition-all hover:-translate-y-1 group"
                             :class="variance === 0 ? 'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50/50' : 'border-red-200 dark:border-red-500/30 bg-red-50/50'"
                             @click="variance = 0; revenue = 4862500">
                            <div class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider mb-2" :class="variance === 0 ? 'text-emerald-500' : 'text-red-500'">
                                <i data-lucide="alert-circle" class="w-4 h-4" x-show="variance !== 0"></i>
                                <i data-lucide="check-circle" class="w-4 h-4" x-show="variance === 0"></i>
                                <span x-text="variance === 0 ? 'Resolved & Matched' : 'Variance Detected'"></span>
                            </div>
                            <div class="text-3xl font-black transition-all" :class="variance === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">₦<span x-text="fmt(Math.abs(variance))"></span></div>
                            <!-- Interaction Hint -->
                            <div class="absolute inset-0 bg-red-600/90 backdrop-blur-sm flex items-center justify-center opacity-0 transition-opacity" :class="variance !== 0 ? 'group-hover:opacity-100' : ''">
                                <span class="text-white flex items-center gap-2 font-bold text-sm tracking-wide"><i data-lucide="mouse-pointer-click" class="w-4 h-4"></i> Click to Resolve Variance</span>
                            </div>
                        </div>
                    </div>

                    <!-- Authentic Table Layout -->
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-white/50 dark:bg-slate-900/50">
                            <h4 class="font-bold text-sm text-slate-800 dark:text-white flex items-center gap-2"><i data-lucide="list" class="w-4 h-4 text-violet-500"></i> Recent Declared Shifts</h4>
                        </div>
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-50 dark:bg-slate-800/80 text-slate-500">
                                <tr><th class="px-5 py-3 font-bold uppercase tracking-wider">Date</th><th class="px-5 py-3 font-bold uppercase tracking-wider">Source</th><th class="px-5 py-3 font-bold uppercase tracking-wider text-right">Amount</th></tr>
                            </thead>
                            <tbody>
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50/50 transition-colors"><td class="px-5 py-3 font-mono">Today, 10:42 AM</td><td class="px-5 py-3">POS Terminal 1</td><td class="px-5 py-3 text-right font-bold text-slate-800 dark:text-white">₦45,000</td></tr>
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50/50 transition-colors"><td class="px-5 py-3 font-mono">Today, 11:15 AM</td><td class="px-5 py-3">Cash Desk</td><td class="px-5 py-3 text-right font-bold text-slate-800 dark:text-white">₦12,500</td></tr>
                                <tr class="hover:bg-slate-50/50 transition-colors"><td class="px-5 py-3 font-mono">Today, 12:30 PM</td><td class="px-5 py-3">Bank Transfer</td><td class="px-5 py-3 text-right font-bold text-slate-800 dark:text-white">₦85,000</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Stock Tab -->
                <div x-show="activeTab==='stock'" x-transition:enter="transition-all duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0" style="display:none;">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-2xl font-black text-slate-800 dark:text-white">Stock Control</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Real-time inventory valuation.</p>
                        </div>
                    </div>
                    
                    <div class="glass-card bg-gradient-to-br from-emerald-500 to-teal-600 p-6 rounded-2xl shadow-xl border border-emerald-400/30 text-white mb-6 transform hover:-translate-y-1 hover:shadow-emerald-500/40 transition-all cursor-pointer group" @click="stockValue = stockValue + 450000">
                        <div class="text-emerald-100 text-xs font-bold mb-3 uppercase tracking-widest flex items-center justify-between">
                            <span>Current Company Valuation</span>
                            <span class="flex items-center gap-1.5 bg-emerald-700/50 px-3 py-1.5 rounded-lg text-[10px] group-hover:bg-white group-hover:text-emerald-600 transition-colors shadow-sm"><i data-lucide="arrow-down-to-line" class="w-3 h-3"></i> Receive Items</span>
                        </div>
                        <div class="text-4xl font-black tracking-tight">₦<span x-text="fmt(stockValue)"></span></div>
                    </div>

                    <div class="space-y-4">
                        <div class="glass-card p-4 rounded-xl flex items-center justify-between border border-slate-200/60 dark:border-slate-700/60 shadow-sm hover:shadow-md transition-shadow">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-orange-400 to-red-500 flex items-center justify-center text-white shadow-lg shadow-orange-500/20"><i data-lucide="warehouse" class="w-5 h-5"></i></div>
                                <div><div class="font-bold text-slate-800 dark:text-white text-sm">Warehouse Main</div><div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mt-0.5">1,245 Items • 42 SKUs</div></div>
                            </div>
                            <div class="font-black text-slate-800 dark:text-white text-lg">₦8,450,000</div>
                        </div>
                        <div class="glass-card p-4 rounded-xl flex items-center justify-between border border-slate-200/60 dark:border-slate-700/60 shadow-sm hover:shadow-md transition-shadow">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400 to-indigo-500 flex items-center justify-center text-white shadow-lg shadow-blue-500/20"><i data-lucide="store" class="w-5 h-5"></i></div>
                                <div><div class="font-bold text-slate-800 dark:text-white text-sm">Front Bar Outlet</div><div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mt-0.5">320 Items • 18 SKUs</div></div>
                            </div>
                            <div class="font-black text-slate-800 dark:text-white text-lg">₦4,000,000</div>
                        </div>
                    </div>
                </div>

                <!-- Finance Tab -->
                <div x-show="activeTab==='finance'" x-transition:enter="transition-all duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0" style="display:none;">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-2xl font-black text-slate-800 dark:text-white mb-1">Automated P&L</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Gross Margin computed efficiently.</p>
                        </div>
                        <button class="bg-violet-100 dark:bg-violet-500/20 text-violet-600 dark:text-violet-400 text-xs font-bold px-3 py-1.5 rounded-lg hover:bg-violet-200 transition-colors flex items-center gap-2" @click="financeRev = financeRev * 1.4">
                            <i data-lucide="trending-up" class="w-3.5 h-3.5"></i> Surge Month
                        </button>
                    </div>

                    <div class="glass-card rounded-3xl shadow-xl border border-slate-200/60 dark:border-slate-700/60 overflow-hidden">
                        <div class="p-5 border-b border-slate-100 dark:border-slate-800 bg-white/50 dark:bg-slate-900/50 flex justify-between items-center text-sm">
                            <span class="text-slate-500 font-bold text-xs uppercase tracking-widest flex items-center gap-2"><i data-lucide="bar-chart-3" class="w-4 h-4 text-violet-500"></i> Total Revenue</span>
                            <span class="font-black text-slate-800 dark:text-white text-lg">₦<span x-text="fmt(financeRev)"></span></span>
                        </div>
                        <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center text-sm pl-10 bg-slate-50/50 dark:bg-slate-800/20">
                            <span class="text-rose-500 font-bold text-xs tracking-wider uppercase">- Computed Cost of Sales</span>
                            <span class="font-black text-rose-500 text-lg">₦<span x-text="fmt(financeCos)"></span></span>
                        </div>
                        <div class="p-6 bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-900/20 dark:to-orange-900/20 flex justify-between items-center">
                            <span class="font-black text-amber-600 dark:text-amber-500 text-sm uppercase tracking-widest flex items-center gap-2"><i data-lucide="calculator" class="w-5 h-5"></i> Gross Profit</span>
                            <span class="font-black text-amber-600 dark:text-amber-500 text-3xl" id="demo-gp">₦<span x-text="fmt(financeProfit)"></span></span>
                        </div>
                    </div>
                </div>

                <!-- Cash Tab -->
                <div x-show="activeTab==='cash'" x-transition:enter="transition-all duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0" style="display:none;">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-2xl font-black text-slate-800 dark:text-white">Cash Manager</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Real-time ledger and requisitions.</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div class="glass-card p-5 rounded-2xl shadow-lg border border-emerald-200/60 dark:border-emerald-500/20 bg-gradient-to-br from-emerald-50/50 to-white dark:from-emerald-900/10 dark:to-slate-800 cursor-pointer hover:-translate-y-1 transition-all group" @click="cashIn += 50000">
                            <div class="text-emerald-600 dark:text-emerald-400 text-xs font-bold uppercase tracking-wider mb-2 flex justify-between items-center">
                                Total Inflow
                                <span class="hidden group-hover:flex items-center gap-1 bg-emerald-100 dark:bg-emerald-800 px-2 py-1 rounded text-[9px]"><i data-lucide="plus" class="w-3 h-3"></i> POS Sale</span>
                            </div>
                            <div class="text-3xl font-black text-emerald-700 dark:text-emerald-300">₦<span x-text="fmt(cashIn)"></span></div>
                        </div>
                        <div class="glass-card p-5 rounded-2xl shadow-lg border border-rose-200/60 dark:border-rose-500/20 bg-gradient-to-br from-rose-50/50 to-white dark:from-rose-900/10 dark:to-slate-800 cursor-pointer hover:-translate-y-1 transition-all group" @click="cashOut += 35000">
                            <div class="text-rose-600 dark:text-rose-400 text-xs font-bold uppercase tracking-wider mb-2 flex justify-between items-center">
                                Outflow (Reqs)
                                <span class="hidden group-hover:flex items-center gap-1 bg-rose-100 dark:bg-rose-800 px-2 py-1 rounded text-[9px]"><i data-lucide="file-minus" class="w-3 h-3"></i> Post Req</span>
                            </div>
                            <div class="text-3xl font-black text-rose-700 dark:text-rose-300">₦<span x-text="fmt(cashOut)"></span></div>
                        </div>
                    </div>

                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg p-5">
                        <h4 class="font-bold text-sm text-slate-800 dark:text-white mb-4 flex items-center gap-2"><i data-lucide="history" class="w-4 h-4 text-slate-400"></i> Ledger History</h4>
                        <div class="space-y-3 max-h-[160px] overflow-hidden pr-2">
                                <div class="flex items-center justify-between p-3.5 bg-slate-50/80 dark:bg-slate-900/80 border border-slate-100 dark:border-slate-800 rounded-xl hover:bg-white dark:hover:bg-slate-800 transition-colors">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-white dark:bg-slate-700 shadow-sm flex items-center justify-center border border-slate-100 dark:border-slate-600"><i data-lucide="file-minus" class="w-4 h-4 text-emerald-500"></i></div>
                                        <div><div class="text-xs font-bold text-slate-700 dark:text-slate-200">Payment Outflow</div><div class="text-[10px] text-slate-400 font-mono">LDG-002</div></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-black text-emerald-600 dark:text-emerald-400">₦25,000</div>
                                        <div class="text-[9px] font-bold mt-0.5 text-emerald-500 uppercase">Requisition</div>
                                    </div>
                                </div>
                        </div>
                    </div>
                </div>

                <!-- Procurement Tab -->
                <div x-show="activeTab==='req'" x-transition:enter="transition-all duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0" style="display:none;">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-2xl font-black text-slate-800 dark:text-white">Procurement</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Multi-stage purchase order approvals.</p>
                        </div>
                    </div>
                    
                    <div class="glass-card rounded-2xl shadow-lg border border-slate-200/60 dark:border-slate-700/60 overflow-hidden mb-6">
                        <div class="p-4 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3 bg-white/50 dark:bg-slate-900/50">
                            <span class="w-8 h-8 rounded-lg bg-rose-100 dark:bg-rose-500/20 text-rose-600 flex items-center justify-center"><i data-lucide="clipboard-signature" class="w-4 h-4"></i></span>
                            <span class="font-bold text-sm text-slate-800 dark:text-white">Awaiting Final Approval</span>
                        </div>
                        <template x-for="(po, i) in reqDemoArr" :key="po.id">
                            <div class="p-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between group hover:bg-slate-50/50 dark:hover:bg-slate-800/50 transition-colors">
                                <div>
                                    <div class="text-sm font-bold text-slate-800 dark:text-white mb-0.5" x-text="po.name"></div>
                                    <div class="text-[10px] font-mono text-slate-400" x-text="po.id"></div>
                                </div>
                                <div class="flex items-center gap-4">
                                    <div class="text-right mr-2"><div class="text-xs text-slate-400">Total</div><div class="text-sm font-black text-slate-800 dark:text-white">₦<span x-text="fmt(po.amt)"></span></div></div>
                                    <button @click="po.status = 'Approved'; po.class = 'bg-emerald-100 text-emerald-700';" 
                                            class="px-4 py-2 text-xs font-bold rounded-lg transition-colors border"
                                            :class="po.status==='Approved' ? 'bg-emerald-50 border-emerald-200 text-emerald-600' : 'bg-rose-600 text-white border-transparent hover:bg-rose-700 shadow-md shadow-rose-500/30'"
                                            x-text="po.status === 'Approved' ? 'Authorized' : 'Approve PO'"></button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Station Audit Tab -->
                <div x-show="activeTab==='station'" x-transition:enter="transition-all duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0" style="display:none;">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-2xl font-black text-slate-800 dark:text-white">Station Audit</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Pump meters and volume tracking.</p>
                        </div>
                    </div>
                    
                    <div class="glass-card bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-2xl relative overflow-hidden text-white group cursor-pointer" @click="pumpEnd += 135.5">
                        <div class="absolute -right-10 -top-10 w-32 h-32 bg-orange-500/20 blur-3xl rounded-full pointer-events-none transition-all group-hover:bg-orange-500/40"></div>
                        
                        <div class="flex justify-between items-center mb-6">
                            <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-xl bg-orange-500/20 text-orange-500 flex items-center justify-center border border-orange-500/30"><i data-lucide="fuel" class="w-5 h-5"></i></div> <span class="font-bold text-sm tracking-widest text-slate-300 uppercase">PMS Pump 1</span></div>
                            <span class="text-[10px] font-black uppercase tracking-widest px-2 py-1 bg-orange-500 text-slate-900 rounded select-none group-hover:bg-white transition-colors">Simulate Output</span>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-8 mb-4">
                            <div><div class="text-[10px] text-slate-500 uppercase tracking-widest mb-1 font-semibold">Start Meter</div><div class="font-mono text-xl text-slate-300">42,500.00</div></div>
                            <div><div class="text-[10px] text-orange-500 uppercase tracking-widest mb-1 font-semibold flex items-center gap-2">Closing Meter <i data-lucide="activity" class="w-3 h-3 animate-pulse"></i></div><div class="font-mono text-3xl font-bold text-white transition-all"><span x-text="fmt(pumpEnd)"></span></div></div>
                        </div>
                        
                        <div class="border-t border-slate-800 pt-4 flex justify-between items-center">
                            <span class="text-xs font-semibold text-slate-400">Total Volume Sold</span>
                            <span class="text-xl font-black text-orange-400"><span x-text="fmt(pumpVol)"></span> LTR</span>
                        </div>
                    </div>
                </div>

                <!-- Hotel Audit & Fraud Suite Tab -->
                <div x-show="activeTab==='hotel'" x-transition:enter="transition-all duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0" style="display:none;">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-2xl font-black text-slate-800 dark:text-white">Hotel Audit & Fraud Suite</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Multi-channel PMS data reconciliation.</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div class="glass-card p-5 rounded-2xl shadow-lg border border-fuchsia-200/60 dark:border-fuchsia-500/20 bg-gradient-to-br from-fuchsia-50/50 to-white dark:from-fuchsia-900/10 dark:to-slate-800 transition-all">
                            <div class="text-fuchsia-600 dark:text-fuchsia-400 text-xs font-bold uppercase tracking-wider mb-2 flex justify-between items-center">PMS Expected</div>
                            <div class="text-3xl font-black text-fuchsia-700 dark:text-fuchsia-300">₦<span x-text="fmt(hotelExpected)"></span></div>
                        </div>
                        <div class="glass-card p-5 rounded-2xl shadow-lg border border-slate-200/60 dark:border-slate-700/60 transition-all cursor-pointer group hover:border-emerald-400" @click="matchHotel()">
                            <div class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 flex justify-between items-center">Actual Banked <span class="hidden group-hover:flex items-center gap-1 bg-emerald-100 text-emerald-700 px-2 py-1 rounded text-[9px] transition-all"><i data-lucide="check-circle-2" class="w-3 h-3"></i> Post</span></div>
                            <div class="text-3xl font-black transition-colors" :class="hotelBanked === hotelExpected ? 'text-emerald-500' : 'text-slate-400'">₦<span x-text="fmt(hotelBanked)"></span></div>
                        </div>
                    </div>
                    
                    <div class="glass-card border border-rose-200 bg-rose-50/50 p-4 rounded-xl flex items-center justify-between transition-all" :class="hotelBanked === hotelExpected ? 'opacity-0 scale-95 pointer-events-none h-0 p-0 overflow-hidden border-0 mb-0' : 'opacity-100 scale-100 h-auto mb-4'">
                        <div class="flex items-center gap-3"><div class="w-8 h-8 rounded-full bg-rose-200 text-rose-600 flex items-center justify-center"><i data-lucide="alert-triangle" class="w-4 h-4"></i></div> <span class="text-sm font-bold text-rose-700">Missing Intake Detected</span></div>
                        <span class="text-lg font-black text-rose-600">₦<span x-text="fmt(hotelExpected - hotelBanked)"></span></span>
                    </div>

                </div>

            </div>
        </div>
    </div>
</section>

<!-- Feature Modules -->
<section id="features" class="py-24 relative">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <span class="text-sm font-bold uppercase tracking-[0.2em] text-violet-600 dark:text-violet-400">Core Modules</span>
            <h2 class="text-2xl sm:text-3xl md:text-4xl font-black text-slate-800 dark:text-white mt-3">Eight Powerful Modules. One Unified Control Center.</h2>
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
                        <div class="mt-6">
                            <a href="station_audit_guide.html" target="_blank" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-orange-600 text-white text-sm font-bold shadow-lg shadow-orange-500/30 hover:scale-105 transition-all">
                                <i data-lucide="file-down" class="w-4 h-4"></i> Official Operations Guide (PDF)
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hotel Audit & Fraud Suite (Featured) -->
            <div class="group relative md:col-span-2 lg:col-span-3 bg-gradient-to-br from-indigo-50 via-blue-50 dark:from-indigo-600/10 dark:via-blue-600/5 to-white dark:to-slate-950 rounded-2xl border-2 border-indigo-300 dark:border-indigo-500/30 p-8 lg:p-10 hover:bg-indigo-100/50 dark:hover:bg-indigo-600/15 transition-all duration-300">
                <div class="absolute top-4 right-4">
                    <span class="px-3 py-1 rounded-full bg-gradient-to-r from-indigo-500 to-blue-500 text-white text-[10px] font-bold uppercase tracking-wider shadow-lg shadow-indigo-500/30">Premium Suite</span>
                </div>
                <div class="lg:flex lg:items-start lg:gap-10">
                    <div class="flex-shrink-0 mb-6 lg:mb-0">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 via-blue-500 to-cyan-600 flex items-center justify-center shadow-xl shadow-indigo-500/40 group-hover:scale-110 transition-transform">
                            <i data-lucide="building" class="w-8 h-8 text-white"></i>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-2xl font-black text-slate-800 dark:text-white mb-3">Hotel Audit & Fraud Suite</h3>
                        <p class="text-slate-600 dark:text-slate-300 text-sm leading-relaxed mb-4">
                            The ultimate financial control suite for the hospitality industry. This module seamlessly ingests your daily expected room revenues and directly cross-references them against actual declared payment (CSV/Excel) to instantly expose missing funds, unrecorded inflows, and rate variances as well as overtime. Beyond strict financial reconciliation, it integrates rigorous Overtime Control and Check-in & Check-out tracking to prevent unauthorized manipulations. Complete with intelligent PDF parsing and professional, audit-ready cash management reports, this system ensures total financial integrity from your front desk to the bank.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <span class="px-2.5 py-1 rounded-lg bg-indigo-100 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-xs font-semibold">Booking Uploads</span>
                            <span class="px-2.5 py-1 rounded-lg bg-indigo-100 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-xs font-semibold">Revenue Assurance</span>
                            <span class="px-2.5 py-1 rounded-lg bg-indigo-100 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-xs font-semibold">Overtime Control</span>
                            <span class="px-2.5 py-1 rounded-lg bg-indigo-100 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-xs font-semibold">Shift Handovers</span>
                            <span class="px-2.5 py-1 rounded-lg bg-indigo-100 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-xs font-semibold">Fraud Detection</span>
                        </div>
                        <div class="mt-6">
                            <a href="hotel_audit_guide.html" target="_blank" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-sm font-bold shadow-lg shadow-slate-900/20 hover:scale-105 transition-all">
                                <i data-lucide="file-down" class="w-4 h-4"></i> Official Operations Guide (PDF)
                            </a>
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

            <!-- Cash Management Hub -->
            <div class="group relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 hover:border-blue-400 dark:hover:border-blue-500/30 hover:bg-slate-100 dark:hover:bg-white/[0.07] transition-all duration-300 hover:-translate-y-1">
                <div class="absolute -top-4 -right-4 w-32 h-32 bg-blue-500/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-xl shadow-blue-500/30 mb-6 group-hover:scale-110 transition-transform">
                    <i data-lucide="banknote" class="w-7 h-7 text-white"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-3">Cash Management Hub</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-4">Complete cash lifecycle visibility. Capture incoming cash sales instantly through our seamless interface, and manage operational expenditure via rigorous cash requisitions. Validate all flows against the dynamically generated cash ledger, print audit-ready PDF status reports, and identify variances with multi-department cash analysis dashboards.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">Cash Ledger</span>
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">Expense Requisitions</span>
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">Bank Deposits</span>
                    <span class="px-2 py-1 rounded-lg bg-blue-100 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 text-xs font-semibold">PDF Reports</span>
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


        </div>
    </div>
</section>
<!-- Pricing Section -->
<section id="pricing" class="py-24 relative">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <span class="text-sm font-bold uppercase tracking-[0.2em] text-violet-600 dark:text-violet-400">Pricing</span>
            <h2 class="text-2xl sm:text-3xl md:text-4xl font-black text-slate-800 dark:text-white mt-3">Simple, Transparent Pricing</h2>
            <p class="text-slate-500 dark:text-slate-400 mt-3 max-w-xl mx-auto">Pay once. Get instant access. Every plan includes core audit and stock control.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">


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
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> Retail Audit Module
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
                    Subscribe Now
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
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Retail Audit Module
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Unlimited Data Retention
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Priority Support
                    </li>
                </ul>
                <a href="pricing.php" class="block w-full text-center px-6 py-3 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold text-sm shadow-lg shadow-amber-500/30 hover:shadow-amber-500/50 hover:scale-[1.02] transition-all">
                    Subscribe Now
                </a>
            </div>

            <!-- Hotel Audit & Fraud Suite -->
            <div class="relative bg-gradient-to-b from-blue-50 dark:from-blue-600/10 to-white dark:to-slate-950 rounded-2xl border-2 border-blue-400 dark:border-blue-500/50 p-8 flex flex-col shadow-xl shadow-blue-500/10 hover:-translate-y-2 transition-all duration-300">
                <div class="mb-6">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center shadow-lg shadow-blue-500/30 mb-4">
                        <i data-lucide="building" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="text-xl font-black text-slate-800 dark:text-white">Hotel Audit & Fraud Suite</h3>
                    <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-300 text-[10px] font-bold uppercase tracking-wider">Premium Suite</span>
                </div>
                <div class="mb-6">
                    <span class="text-4xl font-black text-slate-800 dark:text-white">₦<?php echo $hotel_price; ?></span>
                    <span class="text-sm text-slate-500 dark:text-slate-400">/month</span>
                </div>
                <!-- Enterprise Inclusion Badge -->
                <div class="mb-4 p-2.5 rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 flex items-center gap-2">
                    <div class="w-6 h-6 rounded-lg bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shrink-0">
                        <i data-lucide="crown" class="w-3.5 h-3.5 text-white"></i>
                    </div>
                    <span class="text-xs font-bold text-amber-700 dark:text-amber-300">Includes Everything in Enterprise</span>
                </div>
                <ul class="space-y-3 mb-8 flex-1">
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Unlimited Users & Outlets
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> All Audit, Stock & Finance Modules
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Full P&L, Requisitions & Reports
                    </li>
                    <li class="flex items-start gap-2 text-sm font-semibold text-blue-600 dark:text-blue-400">
                        <i data-lucide="star" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Hotel Audit & Fraud Suite
                    </li>
                    <li class="flex items-start gap-2 text-sm font-semibold text-blue-600 dark:text-blue-400">
                        <i data-lucide="star" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Fixed Assets & Capital Allowance
                    </li>
                    <li class="flex items-start gap-2 text-sm font-semibold text-blue-600 dark:text-blue-400">
                        <i data-lucide="star" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Bank Reconciliation
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Unlimited Data Retention
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"></i> Premium Support
                    </li>
                </ul>
                <a href="pricing.php" class="block w-full text-center px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-500 to-blue-600 text-white font-bold text-sm shadow-lg shadow-blue-500/30 hover:shadow-blue-500/50 hover:scale-[1.02] transition-all">
                    Subscribe Now
                </a>
            </div>

        </div>

        <p class="text-center text-sm text-slate-400 dark:text-slate-500 mt-8">All plans are billed upfront. Choose monthly, quarterly, or annual billing. <a href="pricing.php" class="text-violet-600 dark:text-violet-400 font-bold hover:underline">View full comparison →</a></p>
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

