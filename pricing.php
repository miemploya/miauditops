<?php
/**
 * MIAUDITOPS — Pricing Page
 * Public pricing page driven by config/subscription_plans.php
 * Logged-in users can upgrade via Paystack; guests can view plans.
 */
session_start();
require_once 'config/subscription_plans.php';
require_once 'config/db.php';
require_once 'config/paystack.php';
$plans = get_all_plans();
$cycles = get_billing_cycles();
$is_logged_in = isset($_SESSION['user_id']);

// Dynamic prices from platform_settings table
$dynamic_prices = get_dynamic_prices();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing — MIAUDITOPS</title>
    <meta name="description" content="Choose the MIAUDITOPS plan that fits your business. From free starter plans to full enterprise control.">
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
    <script src="assets/js/anti-copy.js"></script>
    <style>
        @keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-20px)} }
        @keyframes slide-up { from{opacity:0;transform:translateY(40px)} to{opacity:1;transform:translateY(0)} }
        .float-1 { animation: float 6s ease-in-out infinite; }
        .slide-up { animation: slide-up 0.8s ease-out forwards; }
        .slide-up-delay { animation: slide-up 0.8s ease-out 0.2s forwards; opacity: 0; }
        .slide-up-delay-2 { animation: slide-up 0.8s ease-out 0.4s forwards; opacity: 0; }
        .card-glow { transition: all 0.4s ease; }
        .card-glow:hover { transform: translateY(-8px); }
    </style>
</head>
<body class="font-sans bg-white dark:bg-slate-950 text-slate-800 dark:text-white transition-colors duration-300" x-data="pricingApp()">

<!-- Navigation -->
<nav class="fixed top-0 left-0 right-0 z-50 bg-white/80 dark:bg-slate-950/80 backdrop-blur-xl border-b border-slate-200 dark:border-white/5 transition-colors">
    <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
        <a href="index.php" class="flex items-center gap-2">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/30">
                <i data-lucide="shield-check" class="w-5 h-5 text-white"></i>
            </div>
            <span class="text-lg font-black tracking-tight">MIAUDITOPS</span>
        </a>
        <div class="flex items-center gap-3">
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

<!-- Hero -->
<section class="relative pt-32 pb-16 overflow-hidden">
    <div class="absolute top-1/3 left-1/4 w-96 h-96 bg-violet-400/10 dark:bg-violet-600/20 rounded-full blur-3xl float-1"></div>
    <div class="absolute bottom-1/4 right-1/4 w-[400px] h-[400px] bg-blue-400/10 dark:bg-blue-600/15 rounded-full blur-3xl"></div>

    <div class="relative z-10 max-w-4xl mx-auto px-6 text-center slide-up">
        <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-violet-500/10 border border-violet-500/20 text-violet-600 dark:text-violet-300 text-sm font-semibold mb-6">
            <i data-lucide="sparkles" class="w-4 h-4"></i> Simple, Transparent Pricing
        </span>
        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight leading-tight">
            <span class="bg-gradient-to-r from-slate-800 via-slate-600 to-slate-500 dark:from-white dark:via-slate-200 dark:to-slate-400 bg-clip-text text-transparent">Choose Your</span><br>
            <span class="bg-gradient-to-r from-violet-600 via-purple-500 to-blue-500 dark:from-violet-400 dark:via-purple-400 dark:to-blue-400 bg-clip-text text-transparent">Control Level</span>
        </h1>
        <p class="text-lg text-slate-500 dark:text-slate-400 mt-6 max-w-2xl mx-auto leading-relaxed">
            Start free. Scale as you grow. Every plan includes core audit and stock control — upgrade for advanced financial modules and unlimited capacity.
        </p>
    </div>
</section>

<!-- Billing Toggle -->
<section class="pb-8">
    <div class="flex items-center justify-center gap-2 slide-up-delay">
        <button @click="cycle='monthly'" :class="cycle==='monthly' ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/30' : 'bg-slate-100 dark:bg-white/5 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-white/10'" class="px-5 py-2 rounded-xl text-sm font-bold transition-all">
            Monthly
        </button>
        <button @click="cycle='quarterly'" :class="cycle==='quarterly' ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/30' : 'bg-slate-100 dark:bg-white/5 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-white/10'" class="px-5 py-2 rounded-xl text-sm font-bold transition-all">
            Quarterly <span class="text-[10px] ml-1 opacity-75">-10%</span>
        </button>
        <button @click="cycle='annual'" :class="cycle==='annual' ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/30' : 'bg-slate-100 dark:bg-white/5 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-white/10'" class="px-5 py-2 rounded-xl text-sm font-bold transition-all">
            Annual <span class="text-[10px] ml-1 opacity-75">-20%</span>
        </button>
    </div>
</section>

<!-- Pricing Cards -->
<section class="pb-24">
    <div class="max-w-6xl mx-auto px-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 slide-up-delay-2">

            <!-- Starter -->
            <div class="card-glow relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 flex flex-col">
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
                        <i data-lucide="check" class="w-4 h-4 text-emerald-500 mt-0.5 shrink-0"></i> 1 Department
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
            <div class="card-glow relative bg-gradient-to-b from-violet-50 dark:from-violet-600/10 to-white dark:to-slate-950 rounded-2xl border-2 border-violet-400 dark:border-violet-500/50 p-8 flex flex-col shadow-xl shadow-violet-500/10">
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
                    <span class="text-4xl font-black text-slate-800 dark:text-white" x-text="'₦' + getPrice('professional').toLocaleString()"></span>
                    <span class="text-sm text-slate-500 dark:text-slate-400" x-text="'/' + (cycle === 'monthly' ? 'mo' : cycle === 'quarterly' ? 'qtr' : 'yr')"></span>
                    <div x-show="cycle !== 'monthly'" class="text-xs text-violet-600 dark:text-violet-400 font-semibold mt-1">
                        Save <span x-text="cycle === 'quarterly' ? '10%' : '20%'"></span>
                    </div>
                </div>
                <ul class="space-y-3 mb-8 flex-1">
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> 4 Users
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> 3 Clients / 10 Outlets
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> Unlimited Products
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> 10 Departments
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> Full Audit + Stock Modules
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> Revenue & Expenses Tracking
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> PDF Export
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-violet-500 mt-0.5 shrink-0"></i> 1 Year Data Retention
                    </li>
                </ul>
                <button @click="startPayment('professional')" class="block w-full text-center px-6 py-3 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 text-white font-bold text-sm shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-[1.02] transition-all cursor-pointer" :disabled="paying" x-text="paying ? 'Processing...' : 'Start 7-Day Trial'">
                </button>
            </div>

            <!-- Enterprise -->
            <div class="card-glow relative bg-slate-50 dark:bg-white/5 backdrop-blur-sm rounded-2xl border border-slate-200 dark:border-white/10 p-8 flex flex-col">
                <div class="mb-6">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30 mb-4">
                        <i data-lucide="crown" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="text-xl font-black text-slate-800 dark:text-white">Enterprise</h3>
                    <span class="inline-block mt-1 px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-300 text-[10px] font-bold uppercase tracking-wider">Full Power</span>
                </div>
                <div class="mb-6">
                    <span class="text-4xl font-black text-slate-800 dark:text-white" x-text="'₦' + getPrice('enterprise').toLocaleString()"></span>
                    <span class="text-sm text-slate-500 dark:text-slate-400" x-text="'/' + (cycle === 'monthly' ? 'mo' : cycle === 'quarterly' ? 'qtr' : 'yr')"></span>
                    <div x-show="cycle !== 'monthly'" class="text-xs text-amber-600 dark:text-amber-400 font-semibold mt-1">
                        Save <span x-text="cycle === 'quarterly' ? '10%' : '20%'"></span>
                    </div>
                </div>
                <ul class="space-y-3 mb-8 flex-1">
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Unlimited Users
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Unlimited Clients & Outlets
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
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Viewer Role Access
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Unlimited Data Retention
                    </li>
                    <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <i data-lucide="check" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0"></i> Priority Support Services
                    </li>
                </ul>
                <button @click="startPayment('enterprise')" class="block w-full text-center px-6 py-3 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold text-sm shadow-lg shadow-amber-500/30 hover:shadow-amber-500/50 hover:scale-[1.02] transition-all cursor-pointer" :disabled="paying" x-text="paying ? 'Processing...' : 'Subscribe Now'">
                </button>
            </div>

        </div>
    </div>
</section>

<!-- Feature Comparison Table -->
<section class="pb-24">
    <div class="max-w-5xl mx-auto px-6">
        <h2 class="text-3xl font-black text-center text-slate-800 dark:text-white mb-10">Compare Plans</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 dark:bg-white/5">
                        <th class="text-left px-6 py-4 font-bold text-slate-700 dark:text-slate-300">Feature</th>
                        <th class="text-center px-6 py-4 font-bold text-slate-500 dark:text-slate-400">Starter</th>
                        <th class="text-center px-6 py-4 font-bold text-violet-600 dark:text-violet-400">Professional</th>
                        <th class="text-center px-6 py-4 font-bold text-amber-600 dark:text-amber-400">Enterprise</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Users</td><td class="px-6 py-3 text-center text-slate-500 dark:text-slate-400">2</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">4</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">Unlimited</td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Clients</td><td class="px-6 py-3 text-center text-slate-500 dark:text-slate-400">1</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">3</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">Unlimited</td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Outlets</td><td class="px-6 py-3 text-center text-slate-500 dark:text-slate-400">2</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">10</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">Unlimited</td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Products</td><td class="px-6 py-3 text-center text-slate-500 dark:text-slate-400">20</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">Unlimited</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">Unlimited</td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Departments</td><td class="px-6 py-3 text-center text-slate-500 dark:text-slate-400">1</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">10</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">Unlimited</td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Data Retention</td><td class="px-6 py-3 text-center text-slate-500 dark:text-slate-400">90 Days</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">1 Year</td><td class="px-6 py-3 text-center text-slate-600 dark:text-slate-300">Unlimited</td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Daily Audit</td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Stock Control</td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Finance Module</td><td class="px-6 py-3 text-center"><i data-lucide="x" class="w-4 h-4 text-slate-400 mx-auto"></i></td><td class="px-6 py-3 text-center text-xs text-slate-500">Revenue + Expenses</td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Reports</td><td class="px-6 py-3 text-center"><i data-lucide="x" class="w-4 h-4 text-slate-400 mx-auto"></i></td><td class="px-6 py-3 text-center text-xs text-slate-500">Sales + Stock</td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">PDF Export</td><td class="px-6 py-3 text-center"><i data-lucide="x" class="w-4 h-4 text-slate-400 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Station Audit</td><td class="px-6 py-3 text-center"><i data-lucide="x" class="w-4 h-4 text-slate-400 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="x" class="w-4 h-4 text-slate-400 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Viewer Role</td><td class="px-6 py-3 text-center"><i data-lucide="x" class="w-4 h-4 text-slate-400 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="x" class="w-4 h-4 text-slate-400 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td></tr>
                    <tr><td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">Priority Support</td><td class="px-6 py-3 text-center"><i data-lucide="x" class="w-4 h-4 text-slate-400 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="x" class="w-4 h-4 text-slate-400 mx-auto"></i></td><td class="px-6 py-3 text-center"><i data-lucide="check" class="w-4 h-4 text-emerald-500 mx-auto"></i></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="py-24 relative overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-br from-violet-100/50 dark:from-violet-600/10 via-purple-50 dark:via-purple-600/5 to-transparent"></div>
    <div class="relative z-10 max-w-3xl mx-auto px-6 text-center">
        <h2 class="text-4xl font-black text-slate-800 dark:text-white mb-4">Still Have Questions?</h2>
        <p class="text-slate-500 dark:text-slate-400 text-lg mb-8">Start with the free plan — no credit card required. Upgrade anytime as your business grows.</p>
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

<script defer src="https://unpkg.com/alpinejs@3/dist/cdn.min.js"></script>
<script>
function pricingApp() {
    return {
        cycle: 'monthly',
        paying: false,
        isLoggedIn: <?= $is_logged_in ? 'true' : 'false' ?>,
        prices: <?= json_encode($dynamic_prices) ?>,
        getPrice(plan) {
            const key = plan + '_' + this.cycle;
            return this.prices[key] || 0;
        },
        async startPayment(plan) {
            // If not logged in, redirect to signup with plan info
            if (!this.isLoggedIn) {
                window.location.href = 'auth/signup.php?plan=' + plan + '&cycle=' + this.cycle;
                return;
            }

            this.paying = true;
            try {
                const fd = new FormData();
                fd.append('action', 'initialize');
                fd.append('plan', plan);
                fd.append('cycle', this.cycle);

                const res = await fetch('ajax/payment_api.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success && data.authorization_url) {
                    // Redirect to Paystack checkout
                    window.location.href = data.authorization_url;
                } else {
                    alert(data.message || 'Failed to initialize payment. Please try again.');
                }
            } catch (err) {
                alert('Network error. Please check your connection and try again.');
            }
            this.paying = false;
        }
    };
}
</script>
<script>lucide.createIcons();</script>
</body>
</html>
