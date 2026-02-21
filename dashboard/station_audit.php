<?php
/**
 * MIAUDITOPS — Filling Station Audit Module
 * 5 Tabs: System Sales, Pump Sales, Tank Dipping, Haulage, General Report
 */
require_once '../includes/functions.php';
require_once '../config/sector_config.php';
require_login();
require_subscription('station_audit');
require_permission('audit');
require_active_client();
$company_id = $_SESSION['company_id'];
$client_id  = get_active_client();
$user_id    = $_SESSION['user_id'];
$page_title = 'Station Audit';

// ── Auto-migrate DB tables ──
$pdo->exec("CREATE TABLE IF NOT EXISTS station_audit_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, client_id INT NOT NULL, outlet_id INT NOT NULL,
    date_from DATE, date_to DATE, status ENUM('draft','submitted','approved') DEFAULT 'draft',
    created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    auditor_id INT NULL, auditor_signed_at DATETIME NULL, auditor_comments TEXT NULL,
    manager_id INT NULL, manager_signed_at DATETIME NULL, manager_comments TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_system_sales (
    id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL,
    pos_amount DECIMAL(15,2) DEFAULT 0, cash_amount DECIMAL(15,2) DEFAULT 0,
    transfer_amount DECIMAL(15,2) DEFAULT 0, teller_amount DECIMAL(15,2) DEFAULT 0,
    total DECIMAL(15,2) DEFAULT 0, notes TEXT,
    denomination_json TEXT, teller_proof_url VARCHAR(500), pos_proof_url VARCHAR(500),
    pos_terminals_json TEXT, transfer_terminals_json TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Add columns if missing (existing installs)
try { $pdo->exec("ALTER TABLE station_system_sales ADD COLUMN teller_amount DECIMAL(15,2) DEFAULT 0 AFTER transfer_amount"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE station_system_sales ADD COLUMN denomination_json TEXT AFTER notes"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE station_system_sales ADD COLUMN teller_proof_url VARCHAR(500) AFTER denomination_json"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE station_system_sales ADD COLUMN pos_proof_url VARCHAR(500) AFTER teller_proof_url"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE station_system_sales ADD COLUMN pos_terminals_json TEXT AFTER pos_proof_url"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE station_system_sales ADD COLUMN transfer_terminals_json TEXT AFTER pos_terminals_json"); } catch(Exception $e) {}
$pdo->exec("CREATE TABLE IF NOT EXISTS station_pump_tables (
    id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL,
    product VARCHAR(20) DEFAULT 'PMS', station_location VARCHAR(100),
    rate DECIMAL(10,2) DEFAULT 0, date_from DATE, date_to DATE,
    is_closed TINYINT(1) DEFAULT 0, sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_pump_readings (
    id INT AUTO_INCREMENT PRIMARY KEY, pump_table_id INT NOT NULL, company_id INT NOT NULL,
    pump_name VARCHAR(50), opening DECIMAL(15,2) DEFAULT 0, rtt DECIMAL(15,2) DEFAULT 0,
    closing DECIMAL(15,2) DEFAULT 0, sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_tank_dipping (
    id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL,
    tank_name VARCHAR(100), product VARCHAR(20) DEFAULT 'PMS',
    opening DECIMAL(15,2) DEFAULT 0, added DECIMAL(15,2) DEFAULT 0, closing DECIMAL(15,2) DEFAULT 0,
    capacity_kg DECIMAL(12,2) DEFAULT 0, max_fill_percent DECIMAL(5,2) DEFAULT 100
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("ALTER TABLE station_tank_dipping ADD COLUMN IF NOT EXISTS capacity_kg DECIMAL(12,2) DEFAULT 0");
$pdo->exec("ALTER TABLE station_tank_dipping ADD COLUMN IF NOT EXISTS max_fill_percent DECIMAL(5,2) DEFAULT 100");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_haulage (
    id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL,
    delivery_date DATE, tank_name VARCHAR(100), product VARCHAR(20) DEFAULT 'PMS',
    quantity DECIMAL(15,2) DEFAULT 0, waybill_qty DECIMAL(15,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_sections (
    id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL,
    name VARCHAR(100) DEFAULT 'Counter 1', sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_items (
    id INT AUTO_INCREMENT PRIMARY KEY, section_id INT NOT NULL, company_id INT NOT NULL,
    item_name VARCHAR(100), opening DECIMAL(12,2) DEFAULT 0, received DECIMAL(12,2) DEFAULT 0,
    sold DECIMAL(12,2) DEFAULT 0, closing DECIMAL(12,2) DEFAULT 0,
    selling_price DECIMAL(12,2) DEFAULT 0, sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_store_items (
    id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, company_id INT NOT NULL,
    item_name VARCHAR(100), opening DECIMAL(12,2) DEFAULT 0, received DECIMAL(12,2) DEFAULT 0,
    return_out DECIMAL(12,2) DEFAULT 0, selling_price DECIMAL(12,2) DEFAULT 0, sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_issues (
    id INT AUTO_INCREMENT PRIMARY KEY, store_item_id INT NOT NULL, section_id INT NOT NULL,
    company_id INT NOT NULL, quantity DECIMAL(12,2) DEFAULT 0,
    UNIQUE KEY uk_issue (store_item_id, section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Add store_item_id to lube_items if missing (existing installs)
try { $pdo->exec("ALTER TABLE station_lube_items ADD COLUMN store_item_id INT NULL AFTER section_id"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE station_lube_store_items ADD COLUMN adjustment DECIMAL(12,2) DEFAULT 0 AFTER return_out"); } catch(Exception $e) {}
// ── Issue log for audit history ──
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_issue_log (
    id INT AUTO_INCREMENT PRIMARY KEY, store_item_id INT NOT NULL, section_id INT NOT NULL,
    company_id INT NOT NULL, quantity DECIMAL(12,2) DEFAULT 0,
    product_name VARCHAR(150), counter_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// ── Lube product catalog (company-level, not session-scoped) ──
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_products (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL,
    product_name VARCHAR(150) NOT NULL, unit VARCHAR(50) DEFAULT 'Litre',
    cost_price DECIMAL(12,2) DEFAULT 0, selling_price DECIMAL(12,2) DEFAULT 0,
    reorder_level DECIMAL(12,2) DEFAULT 0, is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_prod (company_id, product_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// ── Lube suppliers (company-level) ──
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL,
    supplier_name VARCHAR(150) NOT NULL, contact_person VARCHAR(100),
    phone VARCHAR(30), email VARCHAR(100), address TEXT,
    is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// ── GRN header (company-level, linked to session optionally) ──
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_grn (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL,
    session_id INT NULL, supplier_id INT NULL,
    grn_number VARCHAR(50), grn_date DATE, invoice_number VARCHAR(100),
    total_cost DECIMAL(15,2) DEFAULT 0, notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// ── GRN line items ──
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_grn_items (
    id INT AUTO_INCREMENT PRIMARY KEY, grn_id INT NOT NULL, company_id INT NOT NULL,
    product_id INT NULL, product_name VARCHAR(150),
    quantity DECIMAL(12,2) DEFAULT 0, unit VARCHAR(50) DEFAULT 'Litre',
    cost_price DECIMAL(12,2) DEFAULT 0, selling_price DECIMAL(12,2) DEFAULT 0,
    line_total DECIMAL(15,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Lube Stock Count (period-based physical counts) ──
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_stock_counts (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL,
    session_id INT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL,
    status VARCHAR(20) DEFAULT 'open', notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_lube_stock_count_items (
    id INT AUTO_INCREMENT PRIMARY KEY, count_id INT NOT NULL, company_id INT NOT NULL,
    product_name VARCHAR(150), system_stock INT DEFAULT 0,
    physical_count INT DEFAULT 0, variance INT DEFAULT 0,
    cost_price DECIMAL(12,2) DEFAULT 0, selling_price DECIMAL(12,2) DEFAULT 0,
    sold_qty INT DEFAULT 0, sold_value_cost DECIMAL(15,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Counter Stock Count (period-based physical counts per counter) ──
$pdo->exec("CREATE TABLE IF NOT EXISTS station_counter_stock_counts (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL,
    section_id INT NOT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL,
    status VARCHAR(20) DEFAULT 'open', notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_counter_stock_count_items (
    id INT AUTO_INCREMENT PRIMARY KEY, count_id INT NOT NULL, company_id INT NOT NULL,
    product_name VARCHAR(150), system_stock INT DEFAULT 0,
    physical_count INT DEFAULT 0, variance INT DEFAULT 0,
    selling_price DECIMAL(12,2) DEFAULT 0,
    sold_qty INT DEFAULT 0, sold_value DECIMAL(15,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS station_outlet_terminals (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, outlet_id INT NOT NULL,
    terminal_name VARCHAR(150) NOT NULL, terminal_type ENUM('pos','transfer') NOT NULL,
    sort_order INT DEFAULT 0,
    UNIQUE KEY uk_outlet_terminal (company_id, outlet_id, terminal_name, terminal_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// ── Load data ──
$client_outlets = get_client_outlets($client_id, $company_id);
$js_outlets = json_encode($client_outlets, JSON_HEX_TAG | JSON_HEX_APOS);

$stmt = $pdo->prepare("SELECT s.*, co.name as outlet_name FROM station_audit_sessions s LEFT JOIN client_outlets co ON s.outlet_id = co.id WHERE s.company_id = ? AND s.client_id = ? ORDER BY s.created_at DESC LIMIT 50");
$stmt->execute([$company_id, $client_id]);
$sessions = $stmt->fetchAll();
$js_sessions = json_encode($sessions, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Station Audit — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>
        [x-cloak]{display:none!important}
        .glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95) 0%,rgba(249,250,251,0.9) 100%);backdrop-filter:blur(20px)}
        .dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95) 0%,rgba(30,41,59,0.9) 100%)}
    </style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="stationAudit()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8 scroll-smooth">
            <?php display_flash_message(); ?>

            <!-- ═══ SESSION SELECTOR / CREATOR ═══ -->
            <template x-if="!activeSession">
                <div class="max-w-3xl mx-auto space-y-6">
                    <!-- Create New -->
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-orange-500/10 via-amber-500/5 to-transparent">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 flex items-center justify-center shadow-lg shadow-orange-500/30">
                                    <i data-lucide="fuel" class="w-5 h-5 text-white"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-900 dark:text-white">New Station Audit</h3>
                                    <p class="text-xs text-slate-500">Create a new filling station audit session</p>
                                </div>
                            </div>
                        </div>
                        <form @submit.prevent="createSession()" class="p-6 space-y-4">
                            <div>
                                <label class="text-xs font-semibold text-slate-600 mb-1 block">Station / Outlet *</label>
                                <select x-model="newSession.outlet_id" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                    <option value="">— Select Station —</option>
                                    <template x-for="o in outlets" :key="o.id">
                                        <option :value="o.id" x-text="o.name + ' (' + o.type.replace('_',' ') + ')'"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-xs font-semibold text-slate-600 mb-1 block">Date From *</label>
                                    <input type="date" x-model="newSession.date_from" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-600 mb-1 block">Date To *</label>
                                    <input type="date" x-model="newSession.date_to" required class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                                </div>
                            </div>
                            <button type="submit" :disabled="saving" class="w-full py-2.5 bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all text-sm disabled:opacity-50">Create Audit Session</button>
                        </form>
                    </div>

                    <!-- Existing Sessions -->
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center justify-between flex-wrap gap-3">
                                <h3 class="font-bold text-slate-900 dark:text-white">Previous Audit Sessions
                                    <span class="ml-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300" x-text="filteredSessions.length"></span>
                                </h3>
                                <div class="flex items-center gap-2">
                                    <select x-model="sessionFilterYear" class="px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-semibold text-slate-600 focus:ring-2 focus:ring-amber-400/30 focus:border-amber-400 transition-all">
                                        <option value="">All Years</option>
                                        <template x-for="y in sessionYears" :key="y">
                                            <option :value="y" x-text="y"></option>
                                        </template>
                                    </select>
                                    <select x-model="sessionFilterQuarter" class="px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-semibold text-slate-600 focus:ring-2 focus:ring-amber-400/30 focus:border-amber-400 transition-all">
                                        <option value="">All Quarters</option>
                                        <option value="1">Q1 (Jan–Mar)</option>
                                        <option value="2">Q2 (Apr–Jun)</option>
                                        <option value="3">Q3 (Jul–Sep)</option>
                                        <option value="4">Q4 (Oct–Dec)</option>
                                    </select>
                                    <button x-show="sessionFilterYear || sessionFilterQuarter" @click="sessionFilterYear=''; sessionFilterQuarter=''" class="text-[10px] text-amber-600 hover:text-amber-800 font-bold transition-colors">Clear</button>
                                </div>
                            </div>
                        </div>
                        <div class="divide-y divide-slate-100 dark:divide-slate-800 max-h-[400px] overflow-y-auto">
                            <template x-for="s in filteredSessions" :key="s.id">
                                <div class="px-6 py-3 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-800/30 cursor-pointer group" @click="loadSession(s.id)">
                                    <div>
                                        <p class="text-sm font-bold text-slate-800 dark:text-white" x-text="(s.outlet_name || 'Station') + ' — ' + s.date_from + ' to ' + s.date_to"></p>
                                        <p class="text-[10px] text-slate-400" x-text="'Created: ' + s.created_at"></p>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold"
                                              :class="s.status==='approved'?'bg-emerald-100 text-emerald-700':s.status==='submitted'?'bg-blue-100 text-blue-700':'bg-amber-100 text-amber-700'"
                                              x-text="s.status"></span>
                                        <button @click.stop.prevent="deleteSession(s.id, s.outlet_name)" type="button" class="flex items-center gap-1 px-2 py-1 text-xs font-bold text-red-500 hover:text-white hover:bg-red-500 border border-red-300 rounded-lg transition-all" title="Delete this audit session">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <div x-show="filteredSessions.length === 0 && sessions.length > 0" class="px-6 py-8 text-center text-slate-400 text-sm">No sessions match the selected filters</div>
                            <div x-show="sessions.length === 0" class="px-6 py-10 text-center text-slate-400 text-sm">No audit sessions yet</div>
                        </div>
                    </div>

                    <!-- ─── Trash Section ─── -->
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden mt-6">
                        <div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between cursor-pointer"
                             @click="showTrash = !showTrash; if(showTrash && trashItems.length === 0) loadTrash()">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                <span class="font-bold text-slate-700 dark:text-slate-300 text-sm">Trash</span>
                                <span x-show="trashItems.length > 0" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-600" x-text="trashItems.length"></span>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400 transition-transform" :class="showTrash ? 'rotate-180' : ''"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </div>
                        <div x-show="showTrash" x-transition class="divide-y divide-slate-100 dark:divide-slate-800 max-h-[300px] overflow-y-auto">
                            <template x-for="t in trashItems" :key="t.id">
                                <div class="px-6 py-3 flex items-center justify-between gap-3 hover:bg-red-50/40 dark:hover:bg-red-900/10">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-300 truncate" x-text="t.item_label"></p>
                                        <div class="flex items-center gap-3 mt-0.5">
                                            <span class="text-[10px] text-slate-400" x-text="'Deleted: ' + t.deleted_at"></span>
                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full"
                                                  :class="t.days_remaining <= 7 ? 'bg-red-100 text-red-600' : t.days_remaining <= 30 ? 'bg-amber-100 text-amber-600' : 'bg-slate-100 text-slate-500'"
                                                  x-text="t.days_remaining + ' days left'"></span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1.5 flex-shrink-0">
                                        <button @click="restoreSession(t.id)" class="flex items-center gap-1 px-2.5 py-1 text-[11px] font-bold text-emerald-600 hover:text-white hover:bg-emerald-500 border border-emerald-300 rounded-lg transition-all" title="Restore">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                                            Restore
                                        </button>
                                        <button @click="permanentDeleteTrash(t.id)" class="flex items-center gap-1 px-2.5 py-1 text-[11px] font-bold text-red-500 hover:text-white hover:bg-red-500 border border-red-300 rounded-lg transition-all" title="Permanent delete">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <div x-show="trashItems.length === 0" class="px-6 py-6 text-center text-slate-400 text-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto mb-2 text-slate-300"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                Trash is empty
                            </div>
                        </div>
                        <div x-show="showTrash && trashItems.length > 0" class="px-6 py-2 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/30">
                            <p class="text-[10px] text-slate-400 text-center">Items are automatically purged after 60 days</p>
                        </div>
                    </div>

                </div>
            </template>

            <!-- ═══ ACTIVE SESSION VIEW ═══ -->
            <template x-if="activeSession">
                <div>
                    <!-- Session Header -->
                    <div class="flex items-center gap-4 mb-6 flex-wrap">
                        <button @click="activeSession=null;sessionData=null;_updateHash()" class="p-2 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 transition-all">
                            <i data-lucide="arrow-left" class="w-4 h-4 text-slate-600"></i>
                        </button>
                        <div>
                            <h2 class="text-lg font-black text-slate-900 dark:text-white" x-text="sessionData?.session?.outlet_name || 'Station Audit'"></h2>
                            <div class="flex items-center gap-2 mt-0.5">
                                <input type="date" :value="sessionData?.session?.date_from"
                                       @change="sessionData.session.date_from=$event.target.value; updateSessionDates()"
                                       class="px-2 py-0.5 bg-transparent border border-transparent hover:border-slate-300 focus:border-amber-400 rounded text-xs text-slate-500 focus:ring-1 focus:ring-amber-400/30 transition-all cursor-pointer">
                                <span class="text-xs text-slate-400">—</span>
                                <input type="date" :value="sessionData?.session?.date_to"
                                       @change="sessionData.session.date_to=$event.target.value; updateSessionDates()"
                                       class="px-2 py-0.5 bg-transparent border border-transparent hover:border-slate-300 focus:border-amber-400 rounded text-xs text-slate-500 focus:ring-1 focus:ring-amber-400/30 transition-all cursor-pointer">
                            </div>
                        </div>
                        <span class="px-3 py-1 rounded-full text-xs font-bold"
                              :class="sessionData?.session?.status==='approved'?'bg-emerald-100 text-emerald-700':sessionData?.session?.status==='submitted'?'bg-blue-100 text-blue-700':'bg-amber-100 text-amber-700'"
                              x-text="sessionData?.session?.status || 'draft'"></span>
                    </div>

                    <!-- Tab Navigation -->
                    <div class="mb-6">
                        <div class="flex flex-wrap gap-1.5 p-1.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700">
                            <template x-for="t in tabs" :key="t.id">
                                <button @click="currentTab = t.id"
                                        :class="currentTab === t.id ? t.activeClass : 'text-slate-500 hover:text-slate-700 hover:bg-white/50 border-transparent'"
                                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all border">
                                    <i :data-lucide="t.icon" class="w-3.5 h-3.5"></i>
                                    <span x-text="t.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- ═══ TAB 1: SYSTEM SALES ═══ -->
                    <div x-show="currentTab==='system_sales'" x-transition>
                        <div class="max-w-2xl mx-auto space-y-5">
                            <!-- Entry Form -->
                            <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/10 to-transparent">
                                    <h3 class="font-bold text-slate-900 dark:text-white">System Sales</h3>
                                    <p class="text-xs text-slate-500">Declared POS, Cash, Transfer & Teller for this audit period</p>
                                </div>
                                <div class="p-6 space-y-4">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div>
                                            <label class="text-[10px] font-bold text-blue-600 block mb-1">POS (₦) <span class="text-[8px] text-blue-400 font-normal">auto from terminals</span></label>
                                            <input type="number" step="0.01" x-model="systemSales.pos_amount" readonly class="w-full px-3 py-2.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl text-sm font-semibold cursor-not-allowed">
                                        </div>
                                        <div>
                                            <label class="text-[10px] font-bold text-emerald-600 block mb-1">Cash (₦) <span class="text-[8px] text-emerald-400 font-normal">auto from denom.</span></label>
                                            <input type="number" step="0.01" x-model="systemSales.cash_amount" readonly class="w-full px-3 py-2.5 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-xl text-sm font-semibold cursor-not-allowed">
                                        </div>
                                        <div>
                                            <label class="text-[10px] font-bold text-violet-600 block mb-1">Transfer (₦) <span class="text-[8px] text-violet-400 font-normal">auto from terminals</span></label>
                                            <input type="number" step="0.01" x-model="systemSales.transfer_amount" readonly class="w-full px-3 py-2.5 bg-violet-50 dark:bg-violet-900/20 border border-violet-200 dark:border-violet-800 rounded-xl text-sm font-semibold cursor-not-allowed">
                                        </div>
                                        <div>
                                            <label class="text-[10px] font-bold text-amber-600 block mb-1">Teller (₦)</label>
                                            <input type="number" step="0.01" x-model="systemSales.teller_amount" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-800 rounded-xl text-sm font-semibold">
                                        </div>
                                    </div>

                                    <!-- ── POS Terminal Breakdown ── -->
                                    <div class="rounded-xl border border-blue-200 dark:border-blue-800/50 overflow-hidden">
                                        <button type="button" @click="showPosTerminals = !showPosTerminals" class="w-full flex items-center justify-between px-4 py-2.5 bg-blue-50/80 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/30 transition-all">
                                            <div class="flex items-center gap-2">
                                                <i data-lucide="credit-card" class="w-3.5 h-3.5 text-blue-600"></i>
                                                <span class="text-[10px] font-bold text-blue-700 dark:text-blue-400 uppercase tracking-wide">POS Terminal Breakdown</span>
                                                <span class="text-[9px] px-1.5 py-0.5 rounded-full bg-blue-200 dark:bg-blue-800 text-blue-700 dark:text-blue-300 font-bold" x-text="posTerminals.length+' terminal'+(posTerminals.length!==1?'s':'')"></span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-black text-blue-700 dark:text-blue-400" x-text="fmt(posTerminalsTotal)"></span>
                                                <i :data-lucide="showPosTerminals ? 'chevron-up' : 'chevron-down'" class="w-3.5 h-3.5 text-blue-500"></i>
                                            </div>
                                        </button>
                                        <div x-show="showPosTerminals" x-transition class="p-4 bg-white dark:bg-slate-900/50 space-y-2">
                                            <template x-for="(t, idx) in posTerminals" :key="idx">
                                                <div class="flex items-center gap-2">
                                                    <input type="text" x-model="t.name" placeholder="Terminal name" class="flex-1 px-3 py-2 bg-white dark:bg-slate-900 border border-blue-200 dark:border-blue-800 rounded-lg text-xs font-semibold">
                                                    <input type="number" step="0.01" x-model.number="t.amount" @input="calcPosTerminals()" placeholder="0.00" class="w-32 px-3 py-2 bg-white dark:bg-slate-900 border border-blue-200 dark:border-blue-800 rounded-lg text-xs font-mono text-right">
                                                    <button @click="posTerminals.splice(idx,1); calcPosTerminals()" class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
                                                </div>
                                            </template>
                                            <div class="flex items-center gap-2">
                                                <button @click="posTerminals.push({name:'Terminal '+(posTerminals.length+1), amount:0}); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-all">
                                                    <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> Add POS Terminal
                                                </button>
                                                <button x-show="posTerminals.length > 0" @click="saveOutletTerminals('pos')" class="flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded-lg transition-all border border-emerald-200 dark:border-emerald-800">
                                                    <i data-lucide="save" class="w-3 h-3"></i> Save to Outlet
                                                </button>
                                            </div>
                                            <div class="p-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 flex justify-between items-center">
                                                <span class="text-[10px] font-bold text-blue-600 uppercase">POS Total</span>
                                                <span class="text-sm font-black text-blue-700 dark:text-blue-400" x-text="fmt(posTerminalsTotal)"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ── Transfer Terminal Breakdown ── -->
                                    <div class="rounded-xl border border-violet-200 dark:border-violet-800/50 overflow-hidden">
                                        <button type="button" @click="showTransferTerminals = !showTransferTerminals" class="w-full flex items-center justify-between px-4 py-2.5 bg-violet-50/80 dark:bg-violet-900/20 hover:bg-violet-100 dark:hover:bg-violet-900/30 transition-all">
                                            <div class="flex items-center gap-2">
                                                <i data-lucide="send" class="w-3.5 h-3.5 text-violet-600"></i>
                                                <span class="text-[10px] font-bold text-violet-700 dark:text-violet-400 uppercase tracking-wide">Transfer Terminal Breakdown</span>
                                                <span class="text-[9px] px-1.5 py-0.5 rounded-full bg-violet-200 dark:bg-violet-800 text-violet-700 dark:text-violet-300 font-bold" x-text="transferTerminals.length+' terminal'+(transferTerminals.length!==1?'s':'')"></span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-black text-violet-700 dark:text-violet-400" x-text="fmt(transferTerminalsTotal)"></span>
                                                <i :data-lucide="showTransferTerminals ? 'chevron-up' : 'chevron-down'" class="w-3.5 h-3.5 text-violet-500"></i>
                                            </div>
                                        </button>
                                        <div x-show="showTransferTerminals" x-transition class="p-4 bg-white dark:bg-slate-900/50 space-y-2">
                                            <template x-for="(t, idx) in transferTerminals" :key="idx">
                                                <div class="flex items-center gap-2">
                                                    <input type="text" x-model="t.name" placeholder="Terminal name" class="flex-1 px-3 py-2 bg-white dark:bg-slate-900 border border-violet-200 dark:border-violet-800 rounded-lg text-xs font-semibold">
                                                    <input type="number" step="0.01" x-model.number="t.amount" @input="calcTransferTerminals()" placeholder="0.00" class="w-32 px-3 py-2 bg-white dark:bg-slate-900 border border-violet-200 dark:border-violet-800 rounded-lg text-xs font-mono text-right">
                                                    <button @click="transferTerminals.splice(idx,1); calcTransferTerminals()" class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
                                                </div>
                                            </template>
                                            <div class="flex items-center gap-2">
                                                <button @click="transferTerminals.push({name:'Terminal '+(transferTerminals.length+1), amount:0}); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold text-violet-600 hover:bg-violet-50 dark:hover:bg-violet-900/20 rounded-lg transition-all">
                                                    <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> Add Transfer Terminal
                                                </button>
                                                <button x-show="transferTerminals.length > 0" @click="saveOutletTerminals('transfer')" class="flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded-lg transition-all border border-emerald-200 dark:border-emerald-800">
                                                    <i data-lucide="save" class="w-3 h-3"></i> Save to Outlet
                                                </button>
                                            </div>
                                            <div class="p-2 rounded-lg bg-violet-50 dark:bg-violet-900/20 flex justify-between items-center">
                                                <span class="text-[10px] font-bold text-violet-600 uppercase">Transfer Total</span>
                                                <span class="text-sm font-black text-violet-700 dark:text-violet-400" x-text="fmt(transferTerminalsTotal)"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ── Cash Denomination Breakdown ── -->
                                    <div class="rounded-xl border border-emerald-200 dark:border-emerald-800/50 overflow-hidden">
                                        <button type="button" @click="showDenom = !showDenom" class="w-full flex items-center justify-between px-4 py-2.5 bg-emerald-50/80 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/30 transition-all">
                                            <div class="flex items-center gap-2">
                                                <i data-lucide="coins" class="w-3.5 h-3.5 text-emerald-600"></i>
                                                <span class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wide">Cash Denomination Breakdown</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-black text-emerald-700 dark:text-emerald-400" x-text="fmt(denominationTotal)"></span>
                                                <i :data-lucide="showDenom ? 'chevron-up' : 'chevron-down'" class="w-3.5 h-3.5 text-emerald-500"></i>
                                            </div>
                                        </button>
                                        <div x-show="showDenom" x-transition class="p-4 bg-white dark:bg-slate-900/50">
                                            <div class="grid grid-cols-4 gap-2">
                                                <template x-for="d in denominations" :key="d.value">
                                                    <div class="flex flex-col">
                                                        <label class="text-[9px] font-bold text-emerald-600 mb-0.5" x-text="'₦'+d.value.toLocaleString()"></label>
                                                        <div class="flex items-center gap-1">
                                                            <input type="number" min="0" x-model.number="d.count" @input="calcDenomination()" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-emerald-200 dark:border-emerald-800 rounded-lg text-xs font-mono text-center" placeholder="0">
                                                        </div>
                                                        <span class="text-[8px] font-bold text-slate-400 mt-0.5 text-center" x-text="fmt(d.value * (d.count||0))"></span>
                                                    </div>
                                                </template>
                                            </div>
                                            <div class="mt-3 p-2 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 flex justify-between items-center">
                                                <span class="text-[10px] font-bold text-emerald-600 uppercase">Denomination Total</span>
                                                <span class="text-sm font-black text-emerald-700 dark:text-emerald-400" x-text="fmt(denominationTotal)"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ── POS Proof of Transaction ── -->
                                    <div class="rounded-xl border border-blue-200 dark:border-blue-800/50 overflow-hidden">
                                        <div class="px-4 py-2.5 bg-blue-50/80 dark:bg-blue-900/20">
                                            <div class="flex items-center gap-2 mb-2">
                                                <i data-lucide="file-check" class="w-3.5 h-3.5 text-blue-600"></i>
                                                <span class="text-[10px] font-bold text-blue-700 dark:text-blue-400 uppercase tracking-wide">POS — Proof of Transaction</span>
                                            </div>
                                            <div class="flex items-center gap-3 flex-wrap">
                                                <label class="cursor-pointer px-4 py-2 bg-gradient-to-r from-blue-500 to-indigo-500 text-white text-[10px] font-bold rounded-lg shadow-md hover:scale-105 transition-all flex items-center gap-1.5">
                                                    <i data-lucide="upload" class="w-3 h-3"></i>
                                                    Upload POS Slip
                                                    <input type="file" accept="image/*,.pdf" @change="uploadPosProof($event)" class="hidden">
                                                </label>
                                                <template x-if="systemSales.pos_proof_url">
                                                    <div class="flex items-center gap-2">
                                                        <a :href="systemSales.pos_proof_url" target="_blank" class="flex items-center gap-1 px-3 py-1.5 bg-white dark:bg-slate-800 border border-blue-200 dark:border-blue-700 rounded-lg text-[10px] font-bold text-blue-700 hover:bg-blue-50 transition-all">
                                                            <i data-lucide="eye" class="w-3 h-3"></i> View Slip
                                                        </a>
                                                        <button @click="systemSales.pos_proof_url=''" class="p-1 text-red-400 hover:text-red-600"><i data-lucide="x" class="w-3 h-3"></i></button>
                                                    </div>
                                                </template>
                                                <span x-show="!systemSales.pos_proof_url" class="text-[10px] text-slate-400 italic">No POS slip uploaded</span>
                                            </div>
                                            <template x-if="systemSales.pos_proof_url && !systemSales.pos_proof_url.endsWith('.pdf')">
                                                <div class="mt-2">
                                                    <img :src="systemSales.pos_proof_url" class="max-h-32 rounded-lg border border-blue-200 shadow-sm" alt="POS slip">
                                                </div>
                                            </template>
                                        </div>
                                    </div>

                                    <!-- ── Teller Proof of Deposit ── -->
                                    <div class="rounded-xl border border-amber-200 dark:border-amber-800/50 overflow-hidden">
                                        <div class="px-4 py-2.5 bg-amber-50/80 dark:bg-amber-900/20">
                                            <div class="flex items-center gap-2 mb-2">
                                                <i data-lucide="file-check" class="w-3.5 h-3.5 text-amber-600"></i>
                                                <span class="text-[10px] font-bold text-amber-700 dark:text-amber-400 uppercase tracking-wide">Teller — Proof of Deposit</span>
                                            </div>
                                            <div class="flex items-center gap-3 flex-wrap">
                                                <label class="cursor-pointer px-4 py-2 bg-gradient-to-r from-amber-500 to-orange-500 text-white text-[10px] font-bold rounded-lg shadow-md hover:scale-105 transition-all flex items-center gap-1.5">
                                                    <i data-lucide="upload" class="w-3 h-3"></i>
                                                    Upload Slip
                                                    <input type="file" accept="image/*,.pdf" @change="uploadTellerProof($event)" class="hidden">
                                                </label>
                                                <template x-if="systemSales.teller_proof_url">
                                                    <div class="flex items-center gap-2">
                                                        <a :href="systemSales.teller_proof_url" target="_blank" class="flex items-center gap-1 px-3 py-1.5 bg-white dark:bg-slate-800 border border-amber-200 dark:border-amber-700 rounded-lg text-[10px] font-bold text-amber-700 hover:bg-amber-50 transition-all">
                                                            <i data-lucide="eye" class="w-3 h-3"></i> View Slip
                                                        </a>
                                                        <button @click="systemSales.teller_proof_url=''" class="p-1 text-red-400 hover:text-red-600"><i data-lucide="x" class="w-3 h-3"></i></button>
                                                    </div>
                                                </template>
                                                <span x-show="!systemSales.teller_proof_url" class="text-[10px] text-slate-400 italic">No deposit slip uploaded</span>
                                            </div>
                                            <!-- Image preview -->
                                            <template x-if="systemSales.teller_proof_url && !systemSales.teller_proof_url.endsWith('.pdf')">
                                                <div class="mt-2">
                                                    <img :src="systemSales.teller_proof_url" class="max-h-32 rounded-lg border border-amber-200 shadow-sm" alt="Deposit slip">
                                                </div>
                                            </template>
                                        </div>
                                    </div>

                                    <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 flex justify-between items-center">
                                        <span class="text-xs font-bold text-slate-500 uppercase">Total</span>
                                        <span class="text-xl font-black text-slate-800 dark:text-white" x-text="fmt(systemSalesTotal)"></span>
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold text-slate-500 block mb-1">Notes</label>
                                        <textarea x-model="systemSales.notes" rows="2" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm"></textarea>
                                    </div>
                                    <button @click="saveSystemSales()" :disabled="saving" class="w-full py-2.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white font-bold rounded-xl shadow-lg text-sm hover:scale-[1.02] transition-all disabled:opacity-50">Save System Sales</button>
                                </div>
                            </div>

                            <!-- Breakdown Cards -->
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                <div class="glass-card rounded-xl border border-blue-200/60 dark:border-blue-800/40 p-4 relative overflow-hidden">
                                    <div class="absolute top-0 right-0 w-16 h-16 bg-gradient-to-bl from-blue-500/10 to-transparent rounded-bl-full"></div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center shadow-md shadow-blue-500/30">
                                            <i data-lucide="credit-card" class="w-3.5 h-3.5 text-white"></i>
                                        </div>
                                        <span class="text-[10px] font-bold uppercase text-blue-500 tracking-wide">POS</span>
                                    </div>
                                    <p class="text-lg font-black text-blue-700 dark:text-blue-400" x-text="fmt(parseFloat(systemSales.pos_amount)||0)"></p>
                                    <div class="mt-1.5 h-1 rounded-full bg-blue-100 dark:bg-blue-900/30 overflow-hidden">
                                        <div class="h-full rounded-full bg-blue-500 transition-all" :style="'width:'+Math.min((parseFloat(systemSales.pos_amount)||0)/Math.max(systemSalesTotal,1)*100,100)+'%'"></div>
                                    </div>
                                    <p class="text-[9px] font-bold text-blue-400 mt-1" x-text="systemSalesTotal>0 ? ((parseFloat(systemSales.pos_amount)||0)/systemSalesTotal*100).toFixed(1)+'%' : '0%'"></p>
                                </div>
                                <div class="glass-card rounded-xl border border-emerald-200/60 dark:border-emerald-800/40 p-4 relative overflow-hidden">
                                    <div class="absolute top-0 right-0 w-16 h-16 bg-gradient-to-bl from-emerald-500/10 to-transparent rounded-bl-full"></div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center shadow-md shadow-emerald-500/30">
                                            <i data-lucide="banknote" class="w-3.5 h-3.5 text-white"></i>
                                        </div>
                                        <span class="text-[10px] font-bold uppercase text-emerald-500 tracking-wide">Cash</span>
                                    </div>
                                    <p class="text-lg font-black text-emerald-700 dark:text-emerald-400" x-text="fmt(parseFloat(systemSales.cash_amount)||0)"></p>
                                    <div class="mt-1.5 h-1 rounded-full bg-emerald-100 dark:bg-emerald-900/30 overflow-hidden">
                                        <div class="h-full rounded-full bg-emerald-500 transition-all" :style="'width:'+Math.min((parseFloat(systemSales.cash_amount)||0)/Math.max(systemSalesTotal,1)*100,100)+'%'"></div>
                                    </div>
                                    <p class="text-[9px] font-bold text-emerald-400 mt-1" x-text="systemSalesTotal>0 ? ((parseFloat(systemSales.cash_amount)||0)/systemSalesTotal*100).toFixed(1)+'%' : '0%'"></p>
                                </div>
                                <div class="glass-card rounded-xl border border-violet-200/60 dark:border-violet-800/40 p-4 relative overflow-hidden">
                                    <div class="absolute top-0 right-0 w-16 h-16 bg-gradient-to-bl from-violet-500/10 to-transparent rounded-bl-full"></div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-violet-500 to-violet-600 flex items-center justify-center shadow-md shadow-violet-500/30">
                                            <i data-lucide="send" class="w-3.5 h-3.5 text-white"></i>
                                        </div>
                                        <span class="text-[10px] font-bold uppercase text-violet-500 tracking-wide">Transfer</span>
                                    </div>
                                    <p class="text-lg font-black text-violet-700 dark:text-violet-400" x-text="fmt(parseFloat(systemSales.transfer_amount)||0)"></p>
                                    <div class="mt-1.5 h-1 rounded-full bg-violet-100 dark:bg-violet-900/30 overflow-hidden">
                                        <div class="h-full rounded-full bg-violet-500 transition-all" :style="'width:'+Math.min((parseFloat(systemSales.transfer_amount)||0)/Math.max(systemSalesTotal,1)*100,100)+'%'"></div>
                                    </div>
                                    <p class="text-[9px] font-bold text-violet-400 mt-1" x-text="systemSalesTotal>0 ? ((parseFloat(systemSales.transfer_amount)||0)/systemSalesTotal*100).toFixed(1)+'%' : '0%'"></p>
                                </div>
                                <div class="glass-card rounded-xl border border-amber-200/60 dark:border-amber-800/40 p-4 relative overflow-hidden">
                                    <div class="absolute top-0 right-0 w-16 h-16 bg-gradient-to-bl from-amber-500/10 to-transparent rounded-bl-full"></div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-amber-500 to-amber-600 flex items-center justify-center shadow-md shadow-amber-500/30">
                                            <i data-lucide="receipt" class="w-3.5 h-3.5 text-white"></i>
                                        </div>
                                        <span class="text-[10px] font-bold uppercase text-amber-500 tracking-wide">Teller</span>
                                    </div>
                                    <p class="text-lg font-black text-amber-700 dark:text-amber-400" x-text="fmt(parseFloat(systemSales.teller_amount)||0)"></p>
                                    <div class="mt-1.5 h-1 rounded-full bg-amber-100 dark:bg-amber-900/30 overflow-hidden">
                                        <div class="h-full rounded-full bg-amber-500 transition-all" :style="'width:'+Math.min((parseFloat(systemSales.teller_amount)||0)/Math.max(systemSalesTotal,1)*100,100)+'%'"></div>
                                    </div>
                                    <p class="text-[9px] font-bold text-amber-400 mt-1" x-text="systemSalesTotal>0 ? ((parseFloat(systemSales.teller_amount)||0)/systemSalesTotal*100).toFixed(1)+'%' : '0%'"></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ TAB 2: PUMP SALES ═══ -->
                    <div x-show="currentTab==='pump_sales'" x-transition>
                        <div class="space-y-4">
                            <!-- Product Selector -->
                            <div class="flex items-center gap-3 flex-wrap">
                                <template x-for="p in products" :key="p">
                                    <button @click="selectedProduct=p"
                                            :class="selectedProduct===p ? 'bg-orange-500 text-white shadow-lg shadow-orange-500/30' : 'bg-white dark:bg-slate-800 text-slate-600 hover:bg-orange-50'"
                                            class="px-4 py-2 rounded-xl text-sm font-bold border border-slate-200 dark:border-slate-700 transition-all"
                                            x-text="p"></button>
                                </template>
                                <button @click="createPumpTable()" class="ml-auto px-4 py-2 bg-gradient-to-r from-orange-500 to-amber-600 text-white text-sm font-bold rounded-xl shadow-lg hover:scale-105 transition-all">
                                    <i data-lucide="plus" class="w-4 h-4 inline"></i> New Rate Period
                                </button>
                            </div>

                            <!-- Pump Tables for Selected Product -->
                            <template x-for="pt in filteredPumpTables" :key="pt.id">
                                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                    <!-- Table Header -->
                                    <div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800"
                                         :class="pt.is_closed == 1 ? 'bg-slate-50 dark:bg-slate-800/50' : 'bg-gradient-to-r from-orange-500/10 to-transparent'">
                                        <div class="flex items-center justify-between flex-wrap gap-2">
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-bold text-slate-800 dark:text-white" x-text="pt.station_location || 'Station'"></span>
                                                <span class="px-2 py-0.5 rounded-full text-[9px] font-bold"
                                                      :class="pt._editing ? 'bg-orange-100 text-orange-700' : 'bg-slate-200 text-slate-600'"
                                                      x-text="pt._editing ? 'EDITING' : 'CLOSED'"></span>
                                            </div>
                                            <div class="flex gap-2 flex-wrap">
                                                <button @click="pt._editing = !pt._editing; $nextTick(() => lucide.createIcons())"
                                                        class="px-3 py-1.5 text-[10px] font-bold rounded-lg transition-all"
                                                        :class="pt._editing ? 'bg-green-100 hover:bg-green-200 text-green-700' : 'bg-amber-100 hover:bg-amber-200 text-amber-700'">
                                                    <i :data-lucide="pt._editing ? 'check' : 'pencil'" class="w-3 h-3 inline"></i>
                                                    <span x-text="pt._editing ? ' Done' : ' Edit'"></span>
                                                </button>
                                                <template x-if="pt._editing">
                                                    <button @click="addPumpToTable(pt.id)" class="px-3 py-1.5 bg-blue-100 hover:bg-blue-200 text-blue-700 text-[10px] font-bold rounded-lg transition-all">+ Add Pump</button>
                                                </template>
                                                <template x-if="pt._editing">
                                                    <button @click="closePumpTable(pt)" class="px-3 py-1.5 bg-red-100 hover:bg-red-200 text-red-700 text-[10px] font-bold rounded-lg transition-all">Close Table</button>
                                                </template>
                                                <template x-if="pt._editing">
                                                    <button @click="deletePumpTable(pt)" class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-500 text-[10px] font-bold rounded-lg transition-all"><i data-lucide="trash-2" class="w-3 h-3 inline"></i></button>
                                                </template>
                                            </div>
                                        </div>
                                        <!-- Editable Date / Rate row -->
                                        <div class="flex items-center gap-3 mt-2 flex-wrap">
                                            <div class="flex items-center gap-1">
                                                <span class="text-[9px] font-bold text-slate-400 uppercase">From</span>
                                                <input type="date" x-model="pt.date_from" :disabled="!pt._editing"
                                                       @change="updatePumpTable(pt)"
                                                       class="px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-[10px] font-bold disabled:opacity-60 disabled:cursor-not-allowed">
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <span class="text-[9px] font-bold text-slate-400 uppercase">To</span>
                                                <input type="date" x-model="pt.date_to" :disabled="!pt._editing"
                                                       @change="updatePumpTable(pt)"
                                                       class="px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-[10px] font-bold disabled:opacity-60 disabled:cursor-not-allowed">
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <span class="text-[9px] font-bold text-orange-500 uppercase">Rate ₦</span>
                                                <input type="number" step="0.01" x-model="pt.rate" :disabled="!pt._editing"
                                                       @change="updatePumpTable(pt)"
                                                       class="w-24 px-2 py-1 bg-white dark:bg-slate-900 border border-orange-200 dark:border-orange-700 rounded-lg text-[10px] font-bold font-mono text-right disabled:opacity-60 disabled:cursor-not-allowed">
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Readings Table -->
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead class="bg-amber-50/50 dark:bg-amber-900/10">
                                                <tr>
                                                    <th class="px-4 py-2 text-left text-[10px] font-bold text-amber-700 uppercase">Pump</th>
                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-amber-700 uppercase">Opening</th>
                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-amber-700 uppercase">RTT</th>
                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-amber-700 uppercase">Closing</th>
                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-orange-700 uppercase">Litres</th>
                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-amber-700 uppercase">Rate</th>
                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-emerald-700 uppercase">Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="(r, ri) in pt.readings" :key="ri">
                                                    <tr class="border-b border-slate-100 dark:border-slate-800">
                                                        <td class="px-4 py-2 font-bold text-xs" x-text="r.pump_name"></td>
                                                        <td class="px-4 py-2 text-right"><input type="number" step="0.01" x-model.number="r.opening" :disabled="!pt._editing" class="w-24 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono disabled:opacity-60 disabled:bg-slate-50"></td>
                                                        <td class="px-4 py-2 text-right"><input type="number" step="0.01" x-model.number="r.rtt" :disabled="!pt._editing" class="w-20 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono disabled:opacity-60 disabled:bg-slate-50"></td>
                                                        <td class="px-4 py-2 text-right"><input type="number" step="0.01" x-model.number="r.closing" @input="syncClosingToNext(pt, r)" :disabled="!pt._editing" class="w-24 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono disabled:opacity-60 disabled:bg-slate-50"></td>
                                                        <td class="px-4 py-2 text-right font-bold text-orange-600 font-mono text-xs" x-text="((r.closing||0)-(r.opening||0)-(r.rtt||0)).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        <td class="px-4 py-2 text-right font-mono text-xs text-slate-500" x-text="parseFloat(pt.rate).toLocaleString()"></td>
                                                        <td class="px-4 py-2 text-right font-bold text-emerald-600 font-mono text-xs" x-text="fmt(((r.closing||0)-(r.opening||0)-(r.rtt||0))*parseFloat(pt.rate))"></td>
                                                    </tr>
                                                </template>
                                                <!-- Totals Row -->
                                                <tr class="bg-amber-50 dark:bg-amber-900/10 font-bold">
                                                    <td class="px-4 py-2 text-xs" colspan="4">TOTAL</td>
                                                    <td class="px-4 py-2 text-right text-orange-700 font-mono text-xs" x-text="pumpTableLitres(pt).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                    <td class="px-4 py-2"></td>
                                                    <td class="px-4 py-2 text-right text-emerald-700 font-mono text-xs" x-text="fmt(pumpTableAmount(pt))"></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Save Button (only in edit mode) -->
                                    <template x-if="pt._editing">
                                        <div class="px-6 py-3 border-t border-slate-100 dark:border-slate-800 flex items-center gap-3">
                                            <button @click="savePumpReadings(pt)" :disabled="saving" class="px-6 py-2 bg-gradient-to-r from-orange-500 to-amber-600 text-white text-sm font-bold rounded-xl shadow-lg hover:scale-105 transition-all disabled:opacity-50">Save Readings</button>
                                        </div>
                                    </template>

                                    <!-- ── Tank Dipping Section (inside pump table card) ── -->
                                    <div class="border-t-2 border-dashed border-teal-300 dark:border-teal-700 mt-2">
                                        <button type="button" @click="pt._tankOpen = !pt._tankOpen" class="w-full px-6 py-3 bg-gradient-to-r from-teal-500/10 to-transparent flex items-center justify-between hover:from-teal-500/20 transition-all cursor-pointer">
                                            <div class="flex items-center gap-2">
                                                <i data-lucide="gauge" class="w-4 h-4 text-teal-600"></i>
                                                <span class="text-xs font-bold text-teal-700 dark:text-teal-400 uppercase tracking-wide">Tank Dipping</span>
                                                <span class="text-[9px] px-1.5 py-0.5 rounded-full bg-teal-200 dark:bg-teal-800 text-teal-700 dark:text-teal-300 font-bold" x-text="(pt.tanks||[]).length+' tank'+((pt.tanks||[]).length!==1?'s':'')"></span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <button @click.stop="addTank(pt)" class="flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold text-teal-600 hover:bg-teal-50 dark:hover:bg-teal-900/20 rounded-lg transition-all">
                                                    <i data-lucide="plus-circle" class="w-3 h-3"></i> Add Tank
                                                </button>
                                                <i :data-lucide="pt._tankOpen ? 'chevron-up' : 'chevron-down'" class="w-4 h-4 text-teal-500 transition-transform"></i>
                                            </div>
                                        </button>
                                        <div x-show="pt._tankOpen" x-transition.duration.200ms>
                                            <template x-if="(pt.tanks||[]).length > 0">
                                                <div>

                                                    <div class="overflow-x-auto">
                                                        <table class="w-full text-sm">
                                                            <thead class="bg-teal-50/50 dark:bg-teal-900/10">
                                                                <tr>
                                                                    <th class="px-4 py-2 text-left text-[10px] font-bold text-teal-700 uppercase">Tank Name</th>
                                                                    <!-- LPG: capacity + max fill columns -->
                                                                    <template x-if="isLPG(pt.product)">
                                                                        <th class="px-2 py-2 text-right text-[10px] font-bold text-blue-700 uppercase">Capacity (kg)</th>
                                                                    </template>
                                                                    <template x-if="isLPG(pt.product)">
                                                                        <th class="px-2 py-2 text-right text-[10px] font-bold text-blue-600 uppercase">Max Fill %</th>
                                                                    </template>
                                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-teal-700 uppercase">
                                                                        <span x-text="isLPG(pt.product) ? 'Opening (%)' : 'Opening'"></span>
                                                                    </th>
                                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-teal-700 uppercase">
                                                                        <span x-text="isLPG(pt.product) ? 'Delivery (Tons)' : 'Added'"></span>
                                                                    </th>
                                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-teal-700 uppercase">
                                                                        <span x-text="isLPG(pt.product) ? 'Closing (%)' : 'Closing'"></span>
                                                                    </th>
                                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-emerald-700 uppercase">
                                                                        <span x-text="isLPG(pt.product) ? 'Used (kg)' : 'Difference'"></span>
                                                                    </th>
                                                                    <th class="px-4 py-2 text-center text-[10px] font-bold uppercase">Action</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <template x-for="(tank, ti) in pt.tanks" :key="ti">
                                                                    <tr class="border-b border-slate-100 dark:border-slate-800">
                                                                        <!-- Tank name -->
                                                                        <td class="px-4 py-2">
                                                                            <input type="text" x-model="tank.tank_name" :placeholder="pt.product+' Tank '+(ti+1)" class="w-28 px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-bold capitalize">
                                                                        </td>

                                                                        <!-- LPG: capacity_kg input -->
                                                                        <template x-if="isLPG(pt.product)">
                                                                            <td class="px-2 py-2 text-right">
                                                                                <input type="number" step="1" min="0" x-model.number="tank.capacity_kg" placeholder="kg" class="w-24 text-right px-2 py-1 bg-blue-50 dark:bg-blue-900/20 border border-blue-300 dark:border-blue-700 rounded-lg text-xs font-mono font-bold">
                                                                            </td>
                                                                        </template>
                                                                        <!-- LPG: max_fill_percent input -->
                                                                        <template x-if="isLPG(pt.product)">
                                                                            <td class="px-2 py-2 text-right">
                                                                                <div class="flex items-center gap-1 justify-end">
                                                                                    <input type="number" step="0.1" min="1" max="100" x-model.number="tank.max_fill_percent" placeholder="85" class="w-16 text-right px-2 py-1 bg-sky-50 dark:bg-sky-900/20 border border-sky-300 dark:border-sky-700 rounded-lg text-xs font-mono font-bold">
                                                                                    <span class="text-[9px] text-sky-600 font-bold">%</span>
                                                                                </div>
                                                                            </td>
                                                                        </template>

                                                                        <!-- Opening -->
                                                                        <td class="px-4 py-2 text-right">
                                                                            <div class="flex flex-col items-end gap-0.5">
                                                                                <div class="flex items-center gap-1">
                                                                                    <input type="number" step="0.01" :max="isLPG(pt.product)?100:undefined" x-model.number="tank.opening" class="w-20 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono">
                                                                                    <span x-show="isLPG(pt.product)" class="text-[9px] text-blue-500 font-bold">%</span>
                                                                                </div>
                                                                                <!-- LPG: live kg conversion badge -->
                                                                                <span x-show="isLPG(pt.product) && tank.capacity_kg > 0" class="text-[9px] text-blue-600 dark:text-blue-400 font-mono bg-blue-100 dark:bg-blue-900/30 px-1 rounded" x-text="lpgKgOpen(tank).toLocaleString('en',{minimumFractionDigits:0,maximumFractionDigits:0})+' kg'"></span>
                                                                            </div>
                                                                        </td>

                                                                        <!-- Added / Delivery -->
                                                                        <td class="px-4 py-2 text-right">
                                                                            <div class="flex flex-col items-end gap-0.5">
                                                                                <div class="flex items-center gap-1">
                                                                                    <input type="number" step="0.001" x-model.number="tank.added" class="w-20 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono">
                                                                                    <span x-show="isLPG(pt.product)" class="text-[9px] text-indigo-500 font-bold">MT</span>
                                                                                </div>
                                                                                <!-- LPG: live kg conversion badge -->
                                                                                <span x-show="isLPG(pt.product) && tank.added > 0" class="text-[9px] text-indigo-600 dark:text-indigo-400 font-mono bg-indigo-100 dark:bg-indigo-900/30 px-1 rounded" x-text="lpgKgAdded(tank).toLocaleString('en',{minimumFractionDigits:0,maximumFractionDigits:0})+' kg'"></span>
                                                                            </div>
                                                                        </td>

                                                                        <!-- Closing -->
                                                                        <td class="px-4 py-2 text-right">
                                                                            <div class="flex flex-col items-end gap-0.5">
                                                                                <div class="flex items-center gap-1">
                                                                                    <input type="number" step="0.01" :max="isLPG(pt.product)?100:undefined" x-model.number="tank.closing" class="w-20 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono">
                                                                                    <span x-show="isLPG(pt.product)" class="text-[9px] text-blue-500 font-bold">%</span>
                                                                                </div>
                                                                                <!-- LPG: live kg conversion badge -->
                                                                                <span x-show="isLPG(pt.product) && tank.capacity_kg > 0" class="text-[9px] text-blue-600 dark:text-blue-400 font-mono bg-blue-100 dark:bg-blue-900/30 px-1 rounded" x-text="lpgKgClose(tank).toLocaleString('en',{minimumFractionDigits:0,maximumFractionDigits:0})+' kg'"></span>
                                                                            </div>
                                                                        </td>

                                                                        <!-- Difference / Used -->
                                                                        <td class="px-4 py-2 text-right font-bold font-mono text-xs">
                                                                            <!-- LPG: show kg + MT -->
                                                                            <template x-if="isLPG(pt.product)">
                                                                                <div class="flex flex-col items-end gap-0.5">
                                                                                    <span class="text-emerald-600" x-text="lpgKgDiff(tank).toLocaleString('en',{minimumFractionDigits:0,maximumFractionDigits:0})+' kg'"></span>
                                                                                    <span class="text-[9px] text-slate-400 font-normal" x-text="(lpgKgDiff(tank)/1000).toFixed(3)+' MT'"></span>
                                                                                </div>
                                                                            </template>
                                                                            <!-- Others: plain litres -->
                                                                            <template x-if="!isLPG(pt.product)">
                                                                                <span class="text-emerald-600" x-text="(parseFloat(tank.opening||0)+parseFloat(tank.added||0)-parseFloat(tank.closing||0)).toLocaleString('en',{minimumFractionDigits:2})"></span>
                                                                            </template>
                                                                        </td>

                                                                        <td class="px-4 py-2 text-center">
                                                                            <button @click="pt.tanks.splice(ti,1)" class="p-1 text-red-400 hover:text-red-600"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                                                                        </td>
                                                                    </tr>
                                                                </template>
                                                                <!-- Tank total row -->
                                                                <tr class="bg-teal-50 dark:bg-teal-900/10 font-bold">
                                                                    <td class="px-4 py-2 text-xs" :colspan="isLPG(pt.product) ? 3 : 1">Total</td>
                                                                    <td class="px-4 py-2 text-right font-mono text-xs">
                                                                        <template x-if="isLPG(pt.product)">
                                                                            <span x-text="(pt.tanks||[]).reduce((s,t)=>s+lpgKgOpen(t),0).toLocaleString('en',{maximumFractionDigits:0})+' kg'"></span>
                                                                        </template>
                                                                        <template x-if="!isLPG(pt.product)">
                                                                            <span x-text="(pt.tanks||[]).reduce((s,t)=>s+parseFloat(t.opening||0),0).toLocaleString('en',{minimumFractionDigits:2})"></span>
                                                                        </template>
                                                                    </td>
                                                                    <td class="px-4 py-2 text-right font-mono text-xs">
                                                                        <template x-if="isLPG(pt.product)">
                                                                            <span x-text="(pt.tanks||[]).reduce((s,t)=>s+lpgKgAdded(t),0).toLocaleString('en',{maximumFractionDigits:0})+' kg'"></span>
                                                                        </template>
                                                                        <template x-if="!isLPG(pt.product)">
                                                                            <span x-text="(pt.tanks||[]).reduce((s,t)=>s+parseFloat(t.added||0),0).toLocaleString('en',{minimumFractionDigits:2})"></span>
                                                                        </template>
                                                                    </td>
                                                                    <td class="px-4 py-2 text-right font-mono text-xs">
                                                                        <template x-if="isLPG(pt.product)">
                                                                            <span x-text="(pt.tanks||[]).reduce((s,t)=>s+lpgKgClose(t),0).toLocaleString('en',{maximumFractionDigits:0})+' kg'"></span>
                                                                        </template>
                                                                        <template x-if="!isLPG(pt.product)">
                                                                            <span x-text="(pt.tanks||[]).reduce((s,t)=>s+parseFloat(t.closing||0),0).toLocaleString('en',{minimumFractionDigits:2})"></span>
                                                                        </template>
                                                                    </td>
                                                                    <td class="px-4 py-2 text-right font-mono text-xs text-emerald-700">
                                                                        <template x-if="isLPG(pt.product)">
                                                                            <div class="flex flex-col items-end gap-0.5">
                                                                                <span x-text="(pt.tanks||[]).reduce((s,t)=>s+lpgKgDiff(t),0).toLocaleString('en',{maximumFractionDigits:0})+' kg'"></span>
                                                                                <span class="text-[9px] text-slate-400 font-normal" x-text="((pt.tanks||[]).reduce((s,t)=>s+lpgKgDiff(t),0)/1000).toFixed(3)+' MT'"></span>
                                                                            </div>
                                                                        </template>
                                                                        <template x-if="!isLPG(pt.product)">
                                                                            <span x-text="(pt.tanks||[]).reduce((s,t)=>s+parseFloat(t.opening||0)+parseFloat(t.added||0)-parseFloat(t.closing||0),0).toLocaleString('en',{minimumFractionDigits:2})"></span>
                                                                        </template>
                                                                    </td>
                                                                    <td></td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <div class="px-6 py-3 border-t border-slate-100 dark:border-slate-800">
                                                        <button @click="saveTankDipping(pt)" :disabled="saving" class="px-5 py-1.5 bg-gradient-to-r from-teal-500 to-emerald-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-105 transition-all disabled:opacity-50">Save Tank Dipping</button>
                                                    </div>
                                                </div>
                                            </template>
                                            <template x-if="(pt.tanks||[]).length === 0">
                                                <p class="px-6 py-3 text-xs text-slate-400 italic">No tank records. Click "Add Tank" to record tank dipping for this period.</p>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <div x-show="filteredPumpTables.length===0" class="glass-card rounded-2xl p-10 text-center text-slate-400 border border-slate-200/60">
                                <i data-lucide="fuel" class="w-10 h-10 mx-auto mb-3 opacity-30"></i>
                                <p>No pump tables for <span x-text="selectedProduct" class="font-bold"></span> yet. Click "New Rate Period" to create one.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ TAB 3: TANK DIPPING (Summary) ═══ -->
                    <div x-show="currentTab==='tank_dipping'" x-transition>
                        <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-teal-500/10 to-transparent">
                                <div>
                                    <h3 class="font-bold text-slate-900 dark:text-white">Tank Dipping Summary</h3>
                                    <p class="text-xs text-slate-500">Tank records are entered per pump table rate period. Go to <button @click="currentTab='pump_sales'" class="text-orange-600 font-bold underline">Pump Sales</button> to add/edit tank dipping.</p>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-teal-50/50 dark:bg-teal-900/10">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-[10px] font-bold text-teal-700 uppercase">Product</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-teal-700 uppercase">Opening</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-teal-700 uppercase">Added</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-teal-700 uppercase">Closing</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-emerald-700 uppercase">Difference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="p in tankProductTotals" :key="p.product">
                                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                                <td class="px-4 py-2 text-xs font-bold flex items-center gap-1.5">
                                                    <span x-text="p.product"></span>
                                                    <span x-show="p.isLpg" class="text-[9px] px-1.5 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 font-bold">LPG · kg</span>
                                                    <span x-show="!p.isLpg" class="text-[9px] px-1.5 py-0.5 rounded-full bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300 font-bold">Litres</span>
                                                </td>
                                                <td class="px-4 py-2 text-right font-mono text-xs" x-text="p.opening.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                <td class="px-4 py-2 text-right font-mono text-xs" x-text="p.added.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                <td class="px-4 py-2 text-right font-mono text-xs" x-text="p.closing.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                <td class="px-4 py-2 text-right font-mono text-xs font-bold text-emerald-700">
                                                    <template x-if="p.isLpg">
                                                        <div class="flex flex-col items-end gap-0.5">
                                                            <span x-text="p.diff.toLocaleString('en',{maximumFractionDigits:0})+' kg'"></span>
                                                            <span class="text-[9px] text-slate-400 font-normal" x-text="(p.diff/1000).toFixed(3)+' MT'"></span>
                                                        </div>
                                                    </template>
                                                    <template x-if="!p.isLpg">
                                                        <span x-text="p.diff.toLocaleString('en',{minimumFractionDigits:2})"></span>
                                                    </template>
                                                </td>
                                            </tr>
                                        </template>
                                        <tr x-show="tankProductTotals.length === 0">
                                            <td colspan="5" class="px-4 py-6 text-center text-slate-400 text-xs">No tank dipping records yet. Add tanks in each pump table under Pump Sales.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ TAB 4: HAULAGE ═══ -->
                    <div x-show="currentTab==='haulage'" x-transition>
                        <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-indigo-500/10 to-transparent flex items-center justify-between">
                                <div>
                                    <h3 class="font-bold text-slate-900 dark:text-white">Haulage Report</h3>
                                    <p class="text-xs text-slate-500">Fuel deliveries for the audit period</p>
                                </div>
                                <button @click="addHaulage()" class="px-4 py-2 bg-gradient-to-r from-indigo-500 to-blue-600 text-white text-sm font-bold rounded-xl shadow-lg hover:scale-105 transition-all">+ Add Delivery</button>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-indigo-50/50 dark:bg-indigo-900/10">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-[10px] font-bold text-indigo-700 uppercase">Date</th>
                                            <th class="px-4 py-2 text-left text-[10px] font-bold text-indigo-700 uppercase">Product</th>
                                            <th class="px-4 py-2 text-left text-[10px] font-bold text-indigo-700 uppercase">Tank</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-indigo-700 uppercase">Quantity</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-indigo-700 uppercase">Waybill Qty</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-red-700 uppercase">Diff</th>
                                            <th class="px-4 py-2 text-center text-[10px] font-bold uppercase">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(h, hi) in haulage" :key="hi">
                                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                                 <td class="px-4 py-2">
                                                    <div class="flex flex-col gap-1">
                                                        <input type="date" x-model="h.delivery_date"
                                                            :class="pumpTableForDelivery(h.product, h.delivery_date)
                                                                ? 'border-emerald-300 dark:border-emerald-700'
                                                                : 'border-red-300 dark:border-red-700'"
                                                            class="px-2 py-1 bg-white dark:bg-slate-900 border rounded-lg text-xs transition-colors">
                                                        <!-- Period-match badge -->
                                                        <template x-if="pumpTableForDelivery(h.product, h.delivery_date)">
                                                            <span class="text-[9px] font-bold text-emerald-600 bg-emerald-50 dark:bg-emerald-900/20 px-1.5 py-0.5 rounded"
                                                                x-text="'✓ ' + (pumpTableForDelivery(h.product, h.delivery_date).date_from||'') + ' → ' + (pumpTableForDelivery(h.product, h.delivery_date).date_to||'')">
                                                            </span>
                                                        </template>
                                                        <template x-if="h.delivery_date && !pumpTableForDelivery(h.product, h.delivery_date)">
                                                            <span class="text-[9px] font-bold text-red-600 bg-red-50 dark:bg-red-900/20 px-1.5 py-0.5 rounded">
                                                                ⚠ No period — create in Pump Sales
                                                            </span>
                                                        </template>
                                                    </div>
                                                 </td>
                                                <td class="px-4 py-2">
                                                    <select x-model="h.product" @change="h.tank_name = ''; h._lpg_mode='direct'" class="px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                                        <template x-for="p in products" :key="p"><option :value="p" x-text="p"></option></template>
                                                    </select>
                                                </td>
                                                <td class="px-4 py-2">
                                                    <select x-model="h.tank_name" class="w-32 px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-bold">
                                                        <option value="">-- Select Tank --</option>
                                                        <template x-for="tn in tanksForProduct(h.product)" :key="tn"><option :value="tn" x-text="tn"></option></template>
                                                    </select>
                                                </td>

                                                <!-- Quantity cell — LPG gets discharge toggle, others get plain input -->
                                                <td class="px-4 py-2" colspan="2">
                                                    <template x-if="!isLPG(h.product)">
                                                        <!-- Non-LPG: plain quantity + waybill -->
                                                        <div class="flex items-center gap-2">
                                                            <input type="number" step="0.01" x-model.number="h.quantity" placeholder="Qty" class="w-24 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono">
                                                            <input type="number" step="0.01" x-model.number="h.waybill_qty" placeholder="Waybill" class="w-24 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono">
                                                        </div>
                                                    </template>

                                                    <template x-if="isLPG(h.product)">
                                                        <div class="space-y-2">
                                                            <!-- Mode toggle -->
                                                            <div class="flex items-center gap-2">
                                                                <button @click="h._lpg_mode='direct'"
                                                                    :class="h._lpg_mode==='direct' ? 'bg-indigo-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'"
                                                                    class="px-2 py-0.5 rounded text-[9px] font-bold transition-all">Direct MT</button>
                                                                <button @click="h._lpg_mode='discharge'"
                                                                    :class="h._lpg_mode==='discharge' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'"
                                                                    class="px-2 py-0.5 rounded text-[9px] font-bold transition-all">Truck Gauge</button>
                                                            </div>

                                                            <!-- Direct MT entry -->
                                                            <template x-if="h._lpg_mode === 'direct'">
                                                                <div class="flex items-center gap-2">
                                                                    <div class="flex flex-col gap-0.5">
                                                                        <div class="flex items-center gap-1">
                                                                            <input type="number" step="0.001" x-model.number="h.quantity" placeholder="MT" class="w-20 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono">
                                                                            <span class="text-[9px] text-indigo-500 font-bold">MT</span>
                                                                        </div>
                                                                        <span x-show="h.quantity > 0" class="text-[9px] text-indigo-600 font-mono bg-indigo-50 dark:bg-indigo-900/20 px-1 rounded" x-text="(h.quantity*1000).toLocaleString('en',{maximumFractionDigits:0})+' kg'"></span>
                                                                    </div>
                                                                    <div class="flex flex-col gap-0.5">
                                                                        <div class="flex items-center gap-1">
                                                                            <input type="number" step="0.001" x-model.number="h.waybill_qty" placeholder="Waybill MT" class="w-24 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono">
                                                                            <span class="text-[9px] text-slate-400 font-bold">MT</span>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </template>

                                                            <!-- Discharge calculator -->
                                                            <template x-if="h._lpg_mode === 'discharge'">
                                                                <div class="p-2 rounded-lg bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800/40 space-y-2">
                                                                    <p class="text-[9px] text-blue-700 dark:text-blue-300 font-bold">Truck discharge calculator — mirrors your standalone calculator</p>

                                                                    <!-- Row 1: Truck config -->
                                                                    <div class="flex items-center gap-2">
                                                                        <div class="flex flex-col gap-0.5">
                                                                            <span class="text-[8px] text-slate-500 font-bold uppercase">Storage Tank Capacity (kg)</span>
                                                                            <input type="number" step="1" min="0" x-model.number="h._truck_cap_kg" placeholder="e.g. 18000"
                                                                                class="w-24 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-blue-300 dark:border-blue-700 rounded text-xs font-mono font-bold">
                                                                        </div>
                                                                        <div class="flex flex-col gap-0.5">
                                                                            <span class="text-[8px] text-slate-500 font-bold uppercase">Max Fill %</span>
                                                                            <div class="flex items-center gap-1">
                                                                                <input type="number" step="0.1" min="1" max="100" x-model.number="h._truck_max_fill" placeholder="85"
                                                                                    class="w-16 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-sky-300 dark:border-sky-700 rounded text-xs font-mono font-bold">
                                                                                <span class="text-[9px] text-sky-600 font-bold">%</span>
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Row 2: Gauge before / after -->
                                                                    <div class="flex items-center gap-2">
                                                                        <div class="flex flex-col gap-0.5">
                                                                            <span class="text-[8px] text-slate-500 font-bold uppercase">Before Discharge %</span>
                                                                            <div class="flex items-center gap-1">
                                                                                <input type="number" step="0.01" min="0" max="100"
                                                                                    x-model.number="h._truck_open_pct"
                                                                                    @input="
                                                                                        const kg = lpgDischargeKg({open_pct:h._truck_open_pct, close_pct:h._truck_close_pct, capacity_kg:h._truck_cap_kg, max_fill_percent:h._truck_max_fill});
                                                                                        if(kg>0){ h.quantity = parseFloat((kg/1000).toFixed(4)); }
                                                                                    "
                                                                                    class="w-20 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-300 rounded text-xs font-mono">
                                                                                <span class="text-[9px] text-slate-500 font-bold">%</span>
                                                                            </div>
                                                                            <span x-show="h._truck_cap_kg > 0" class="text-[9px] text-slate-400 font-mono" x-text="((h._truck_open_pct||0)/(h._truck_max_fill||100)*h._truck_cap_kg).toLocaleString('en',{maximumFractionDigits:0})+' kg'"></span>
                                                                        </div>
                                                                        <div class="flex flex-col gap-0.5">
                                                                            <span class="text-[8px] text-slate-500 font-bold uppercase">After Discharge %</span>
                                                                            <div class="flex items-center gap-1">
                                                                                <input type="number" step="0.01" min="0" max="100"
                                                                                    x-model.number="h._truck_close_pct"
                                                                                    @input="
                                                                                        const kg = lpgDischargeKg({open_pct:h._truck_open_pct, close_pct:h._truck_close_pct, capacity_kg:h._truck_cap_kg, max_fill_percent:h._truck_max_fill});
                                                                                        if(kg>0){ h.quantity = parseFloat((kg/1000).toFixed(4)); }
                                                                                    "
                                                                                    class="w-20 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-300 rounded text-xs font-mono">
                                                                                <span class="text-[9px] text-slate-500 font-bold">%</span>
                                                                            </div>
                                                                            <span x-show="h._truck_cap_kg > 0" class="text-[9px] text-slate-400 font-mono" x-text="((h._truck_close_pct||0)/(h._truck_max_fill||100)*h._truck_cap_kg).toLocaleString('en',{maximumFractionDigits:0})+' kg'"></span>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Row 3: Result -->
                                                                    <div class="flex items-center gap-3 pt-1 border-t border-blue-200 dark:border-blue-800/40">
                                                                        <div class="flex flex-col">
                                                                            <span class="text-[8px] text-slate-500 font-bold uppercase">Discharged (kg)</span>
                                                                            <span class="text-sm font-black font-mono text-emerald-600" x-text="lpgDischargeKg({open_pct:h._truck_open_pct, close_pct:h._truck_close_pct, capacity_kg:h._truck_cap_kg, max_fill_percent:h._truck_max_fill}).toLocaleString('en',{maximumFractionDigits:0})+' kg'"></span>
                                                                        </div>
                                                                        <div class="flex flex-col">
                                                                            <span class="text-[8px] text-slate-500 font-bold uppercase">= MT (recorded)</span>
                                                                            <span class="text-sm font-black font-mono text-indigo-600" x-text="(lpgDischargeKg({open_pct:h._truck_open_pct, close_pct:h._truck_close_pct, capacity_kg:h._truck_cap_kg, max_fill_percent:h._truck_max_fill})/1000).toFixed(4)+' MT'"></span>
                                                                        </div>
                                                                        <div class="flex flex-col">
                                                                            <span class="text-[8px] text-slate-500 font-bold uppercase">Waybill MT</span>
                                                                            <input type="number" step="0.001" x-model.number="h.waybill_qty" placeholder="Waybill"
                                                                                class="w-20 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-300 rounded text-xs font-mono">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </td>

                                                <!-- Diff cell -->
                                                <td class="px-4 py-2 text-right font-bold font-mono text-xs" :class="((h.quantity||0)-(h.waybill_qty||0))===0?'text-emerald-600':'text-red-600'" x-text="((h.quantity||0)-(h.waybill_qty||0)).toLocaleString('en',{minimumFractionDigits:2})">
                                                </td>
                                                <td class="px-4 py-2 text-center"><button @click="haulage.splice(hi,1)" class="p-1 text-red-400 hover:text-red-600"><i data-lucide="trash-2" class="w-3 h-3"></i></button></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <div class="px-6 py-3 border-t border-slate-100 dark:border-slate-800">
                                <button @click="saveHaulage()" :disabled="saving" class="px-6 py-2 bg-gradient-to-r from-indigo-500 to-blue-600 text-white text-sm font-bold rounded-xl shadow-lg hover:scale-105 transition-all disabled:opacity-50">Save Haulage</button>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ TAB 5: LUBRICANTS ═══ -->
                    <div x-show="currentTab==='lubricants'" x-transition>
                        <div class="space-y-4">

                            <!-- Header -->
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="font-bold text-lg text-slate-900 dark:text-white">Lubricant Audit</h3>
                                    <p class="text-xs text-slate-500">Store supplies counters — track inventory from source to point of sale</p>
                                </div>
                            </div>

                            <!-- Sub-tab pills -->
                            <div class="flex flex-wrap gap-2 p-1 bg-slate-100 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 w-fit">
                                <button @click="lubeSubTab='products'" :class="lubeSubTab==='products' ? 'bg-white dark:bg-slate-900 text-violet-600 shadow-sm border-violet-200' : 'text-slate-500 hover:bg-white/50 border-transparent'" class="flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold transition-all border">
                                    <i data-lucide="package" class="w-3.5 h-3.5"></i> Products
                                    <span x-show="lubeProducts.length > 0" class="ml-1 px-1.5 py-0.5 rounded-full bg-violet-100 text-violet-700 text-[9px] font-black" x-text="lubeProducts.length"></span>
                                </button>
                                <button @click="lubeSubTab='grn'" :class="lubeSubTab==='grn' ? 'bg-white dark:bg-slate-900 text-blue-600 shadow-sm border-blue-200' : 'text-slate-500 hover:bg-white/50 border-transparent'" class="flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold transition-all border">
                                    <i data-lucide="clipboard-list" class="w-3.5 h-3.5"></i> GRN
                                    <span x-show="lubeGrns.length > 0" class="ml-1 px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[9px] font-black" x-text="lubeGrns.length"></span>
                                </button>
                                <button @click="lubeSubTab='suppliers'" :class="lubeSubTab==='suppliers' ? 'bg-white dark:bg-slate-900 text-orange-600 shadow-sm border-orange-200' : 'text-slate-500 hover:bg-white/50 border-transparent'" class="flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold transition-all border">
                                    <i data-lucide="truck" class="w-3.5 h-3.5"></i> Suppliers
                                    <span x-show="lubeSuppliers.length > 0" class="ml-1 px-1.5 py-0.5 rounded-full bg-orange-100 text-orange-700 text-[9px] font-black" x-text="lubeSuppliers.length"></span>
                                </button>
                                <div class="w-px bg-slate-300 dark:bg-slate-600 mx-1 self-stretch"></div>
                                <button @click="lubeSubTab='store'" :class="lubeSubTab==='store' ? 'bg-white dark:bg-slate-900 text-lime-600 shadow-sm border-lime-200' : 'text-slate-500 hover:bg-white/50 border-transparent'" class="flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold transition-all border">
                                    <i data-lucide="warehouse" class="w-3.5 h-3.5"></i> Lube Store
                                    <span x-show="lubeStoreItems.length > 0" class="ml-1 px-1.5 py-0.5 rounded-full bg-lime-100 text-lime-700 text-[9px] font-black" x-text="lubeStoreItems.length"></span>
                                </button>
                                <button @click="lubeSubTab='counters'" :class="lubeSubTab==='counters' ? 'bg-white dark:bg-slate-900 text-lime-600 shadow-sm border-lime-200' : 'text-slate-500 hover:bg-white/50 border-transparent'" class="flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold transition-all border">
                                    <i data-lucide="store" class="w-3.5 h-3.5"></i> Counters
                                    <span x-show="lubeSections.length > 0" class="ml-1 px-1.5 py-0.5 rounded-full bg-lime-100 text-lime-700 text-[9px] font-black" x-text="lubeSections.length"></span>
                                </button>
                                <div class="w-px bg-slate-300 dark:bg-slate-600 mx-1 self-stretch"></div>
                                <button @click="lubeSubTab='stock_count'" :class="lubeSubTab==='stock_count' ? 'bg-white dark:bg-slate-900 text-cyan-600 shadow-sm border-cyan-200' : 'text-slate-500 hover:bg-white/50 border-transparent'" class="flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold transition-all border">
                                    <i data-lucide="clipboard-check" class="w-3.5 h-3.5"></i> Stock Count
                                    <span x-show="lubeStockCounts.length > 0" class="ml-1 px-1.5 py-0.5 rounded-full bg-cyan-100 text-cyan-700 text-[9px] font-black" x-text="lubeStockCounts.length"></span>
                                </button>
                                <button @click="lubeSubTab='evaluation'" :class="lubeSubTab==='evaluation' ? 'bg-white dark:bg-slate-900 text-indigo-600 shadow-sm border-indigo-200' : 'text-slate-500 hover:bg-white/50 border-transparent'" class="flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold transition-all border">
                                    <i data-lucide="layers" class="w-3.5 h-3.5"></i> Evaluation
                                </button>
                            </div>

                            <!-- ── PRODUCTS SUB-TAB ── -->
                            <div x-show="lubeSubTab==='products'" x-transition>
                                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-violet-500/10 to-transparent flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 bg-gradient-to-br from-violet-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                                                <i data-lucide="package" class="w-4 h-4 text-white"></i>
                                            </div>
                                            <div>
                                                <h4 class="font-bold text-slate-900 dark:text-white text-sm">Product Catalog</h4>
                                                <p class="text-[10px] text-slate-500" x-text="lubeProducts.length + ' product(s) registered'"></p>
                                            </div>
                                        </div>
                                        <button @click="openLubeProductModal(); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-violet-500 to-purple-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                                            <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> Add Product
                                        </button>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead class="bg-violet-50/50 dark:bg-violet-900/10">
                                                <tr>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-violet-700 uppercase">Product Name</th>
                                                    <th class="px-4 py-2.5 text-center text-[10px] font-bold text-slate-500 uppercase">Unit</th>
                                                    <th class="px-4 py-2.5 text-right text-[10px] font-bold text-orange-600 uppercase">Cost Price</th>
                                                    <th class="px-4 py-2.5 text-right text-[10px] font-bold text-emerald-600 uppercase">Selling Price</th>
                                                    <th class="px-4 py-2.5 text-right text-[10px] font-bold text-blue-600 uppercase">Margin</th>
                                                    <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Reorder Lvl</th>
                                                    <th class="px-4 py-2.5 text-center text-[10px] font-bold uppercase">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="p in lubeProducts" :key="p.id">
                                                    <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-violet-50/20 transition-colors">
                                                        <td class="px-4 py-2.5 font-bold text-slate-800 dark:text-slate-200 text-xs" x-text="p.product_name"></td>
                                                        <td class="px-4 py-2.5 text-center text-xs text-slate-500" x-text="p.unit"></td>
                                                        <td class="px-4 py-2.5 text-right font-mono text-xs text-orange-600" x-text="'₦' + parseFloat(p.cost_price||0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        <td class="px-4 py-2.5 text-right font-mono text-xs text-emerald-600" x-text="'₦' + parseFloat(p.selling_price||0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        <td class="px-4 py-2.5 text-right font-mono text-xs" :class="(p.selling_price - p.cost_price) >= 0 ? 'text-blue-600' : 'text-red-600'" x-text="'₦' + (parseFloat(p.selling_price||0) - parseFloat(p.cost_price||0)).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        <td class="px-4 py-2.5 text-right font-mono text-xs text-slate-500" x-text="parseFloat(p.reorder_level||0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        <td class="px-4 py-2.5 text-center">
                                                            <div class="flex items-center justify-center gap-1">
                                                                <button @click="openLubeProductModal(p); $nextTick(()=>lucide.createIcons())" class="p-1.5 text-violet-500 hover:bg-violet-50 rounded-lg transition-all"><i data-lucide="pencil" class="w-3 h-3"></i></button>
                                                                <button @click="deleteLubeProduct(p)" class="p-1.5 text-red-400 hover:bg-red-50 rounded-lg transition-all"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </template>
                                                <tr x-show="lubeProducts.length === 0">
                                                    <td colspan="7" class="px-4 py-10 text-center text-slate-400 text-xs italic">
                                                        <i data-lucide="package" class="w-8 h-8 mx-auto mb-2 opacity-30"></i>
                                                        <p>No products yet. Click "Add Product" to create your lubricant catalog.</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- ── GRN SUB-TAB ── -->
                            <div x-show="lubeSubTab==='grn'" x-transition>
                                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/10 to-transparent flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
                                                <i data-lucide="clipboard-list" class="w-4 h-4 text-white"></i>
                                            </div>
                                            <div>
                                                <h4 class="font-bold text-slate-900 dark:text-white text-sm">Goods Received Notes (GRN)</h4>
                                                <p class="text-[10px] text-slate-500" x-text="lubeGrns.length + ' GRN(s) · Total: ₦' + lubeGrns.reduce((s,g)=>s+parseFloat(g.total_cost||0),0).toLocaleString('en',{minimumFractionDigits:2})"></p>
                                            </div>
                                        </div>
                                        <button @click="openLubeGrnModal(); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-blue-500 to-indigo-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                                            <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> New GRN
                                        </button>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead class="bg-blue-50/50 dark:bg-blue-900/10">
                                                <tr>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-blue-700 uppercase">GRN #</th>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Date</th>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Supplier</th>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Invoice #</th>
                                                    <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Items</th>
                                                    <th class="px-4 py-2.5 text-right text-[10px] font-bold text-emerald-600 uppercase">Total Cost</th>
                                                    <th class="px-4 py-2.5 text-center text-[10px] font-bold uppercase">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="g in lubeGrns" :key="g.id">
                                                    <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-blue-50/20 transition-colors">
                                                        <td class="px-4 py-2.5 font-bold text-blue-700 text-xs" x-text="g.grn_number || ('GRN-' + g.id)"></td>
                                                        <td class="px-4 py-2.5 text-xs text-slate-600" x-text="g.grn_date"></td>
                                                        <td class="px-4 py-2.5 text-xs text-slate-600" x-text="g.supplier_name || '—'"></td>
                                                        <td class="px-4 py-2.5 text-xs text-slate-500" x-text="g.invoice_number || '—'"></td>
                                                        <td class="px-4 py-2.5 text-right text-xs font-mono" x-text="(g.items||[]).length"></td>
                                                        <td class="px-4 py-2.5 text-right font-mono font-bold text-emerald-600 text-xs" x-text="'₦' + parseFloat(g.total_cost||0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        <td class="px-4 py-2.5 text-center">
                                                            <div class="flex items-center justify-center gap-1">
                                                                <button @click="openLubeGrnModal(g); $nextTick(()=>lucide.createIcons())" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-lg transition-all"><i data-lucide="pencil" class="w-3 h-3"></i></button>
                                                                <button @click="deleteLubeGrn(g)" class="p-1.5 text-red-400 hover:bg-red-50 rounded-lg transition-all"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </template>
                                                <tr x-show="lubeGrns.length === 0">
                                                    <td colspan="7" class="px-4 py-10 text-center text-slate-400 text-xs italic">
                                                        <i data-lucide="clipboard-list" class="w-8 h-8 mx-auto mb-2 opacity-30"></i>
                                                        <p>No GRNs yet. Click "New GRN" to record a goods receipt.</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- ── SUPPLIERS SUB-TAB ── -->
                            <div x-show="lubeSubTab==='suppliers'" x-transition>
                                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-orange-500/10 to-transparent flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 bg-gradient-to-br from-orange-500 to-amber-600 rounded-xl flex items-center justify-center shadow-lg">
                                                <i data-lucide="truck" class="w-4 h-4 text-white"></i>
                                            </div>
                                            <div>
                                                <h4 class="font-bold text-slate-900 dark:text-white text-sm">Suppliers</h4>
                                                <p class="text-[10px] text-slate-500" x-text="lubeSuppliers.length + ' supplier(s) registered'"></p>
                                            </div>
                                        </div>
                                        <button @click="openLubeSupplierModal(); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-orange-500 to-amber-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                                            <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> Add Supplier
                                        </button>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead class="bg-orange-50/50 dark:bg-orange-900/10">
                                                <tr>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-orange-700 uppercase">Supplier Name</th>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Contact Person</th>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Phone</th>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Email</th>
                                                    <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-500 uppercase">Address</th>
                                                    <th class="px-4 py-2.5 text-center text-[10px] font-bold uppercase">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="s in lubeSuppliers" :key="s.id">
                                                    <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-orange-50/20 transition-colors">
                                                        <td class="px-4 py-2.5 font-bold text-slate-800 dark:text-slate-200 text-xs" x-text="s.supplier_name"></td>
                                                        <td class="px-4 py-2.5 text-xs text-slate-600" x-text="s.contact_person || '—'"></td>
                                                        <td class="px-4 py-2.5 text-xs text-slate-600" x-text="s.phone || '—'"></td>
                                                        <td class="px-4 py-2.5 text-xs text-slate-500" x-text="s.email || '—'"></td>
                                                        <td class="px-4 py-2.5 text-xs text-slate-500 max-w-[150px] truncate" x-text="s.address || '—'"></td>
                                                        <td class="px-4 py-2.5 text-center">
                                                            <div class="flex items-center justify-center gap-1">
                                                                <button @click="openLubeSupplierModal(s); $nextTick(()=>lucide.createIcons())" class="p-1.5 text-orange-500 hover:bg-orange-50 rounded-lg transition-all"><i data-lucide="pencil" class="w-3 h-3"></i></button>
                                                                <button @click="deleteLubeSupplier(s)" class="p-1.5 text-red-400 hover:bg-red-50 rounded-lg transition-all"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </template>
                                                <tr x-show="lubeSuppliers.length === 0">
                                                    <td colspan="6" class="px-4 py-10 text-center text-slate-400 text-xs italic">
                                                        <i data-lucide="truck" class="w-8 h-8 mx-auto mb-2 opacity-30"></i>
                                                        <p>No suppliers yet. Click "Add Supplier" to register one.</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- ── LUBE STORE SUB-TAB ── -->
                            <div x-show="lubeSubTab==='store'" x-transition>
                                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                    <!-- Store header -->
                                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-lime-500/10 to-transparent flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 bg-gradient-to-br from-lime-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg shadow-lime-500/30">
                                                <i data-lucide="warehouse" class="w-4 h-4 text-white"></i>
                                            </div>
                                            <div>
                                                <h4 class="font-bold text-slate-900 dark:text-white text-sm">Lube Store — Master Inventory</h4>
                                                <p class="text-[10px] text-slate-500" x-text="lubeStoreItems.length + ' product(s) · All postings via action buttons'"></p>
                                            </div>
                                        </div>
                                        <button @click="saveLubeStoreItems()" :disabled="saving || lubeStoreItems.length===0" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-lime-500 to-green-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50">
                                            <i data-lucide="save" class="w-3.5 h-3.5"></i> Save Store
                                        </button>
                                    </div>

                                    <!-- Store table -->
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead class="bg-lime-50/50 dark:bg-lime-900/10 sticky top-0">
                                                <tr>
                                                    <th class="px-3 py-2.5 text-left text-[10px] font-bold text-lime-700 uppercase">Product</th>
                                                    <th class="px-3 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Opening</th>
                                                    <th class="px-3 py-2.5 text-right text-[10px] font-bold text-blue-600 uppercase">Received</th>
                                                    <th class="px-3 py-2.5 text-right text-[10px] font-bold text-teal-600 uppercase">Return In</th>
                                                    <th class="px-3 py-2.5 text-right text-[10px] font-bold text-slate-700 uppercase bg-slate-50/50">Total</th>
                                                    <th class="px-3 py-2.5 text-right text-[10px] font-bold text-rose-600 uppercase">Issued</th>
                                                    <th class="px-3 py-2.5 text-right text-[10px] font-bold text-indigo-600 uppercase">Adjustment</th>
                                                    <th class="px-3 py-2.5 text-right text-[10px] font-bold text-emerald-600 uppercase bg-emerald-50/30">Closing</th>
                                                    <th class="px-3 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Price</th>
                                                    <th class="px-3 py-2.5 text-center text-[10px] font-bold text-violet-600 uppercase">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="(si, sii) in lubeStoreItems" :key="sii">
                                                    <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-lime-50/30 dark:hover:bg-lime-900/5 transition-colors">
                                                        <td class="px-3 py-2.5 font-bold text-xs text-slate-800 dark:text-slate-200" x-text="si.item_name || '—'"></td>
                                                        <td class="px-3 py-2.5 text-right font-mono text-xs text-slate-700" x-text="Math.round(si.opening || 0)"></td>
                                                        <td class="px-3 py-2.5 text-right font-mono text-xs text-blue-600 font-bold" x-text="Math.round(si.received || 0)"></td>
                                                        <td class="px-3 py-2.5 text-right font-mono text-xs text-teal-600 font-bold" x-text="Math.round(si.return_out || 0)"></td>
                                                        <td class="px-3 py-2.5 text-right font-bold text-xs font-mono text-slate-700 bg-slate-50/50" x-text="Math.round(storeItemTotal(si))"></td>
                                                        <td class="px-3 py-2.5 text-right font-bold text-xs font-mono text-rose-600" x-text="Math.round(storeItemIssued(si))"></td>
                                                        <td class="px-3 py-2.5 text-right font-mono text-xs font-bold" :class="(si.adjustment||0) > 0 ? 'text-indigo-600' : (si.adjustment||0) < 0 ? 'text-red-600' : 'text-slate-400'" x-text="Math.round(si.adjustment || 0)"></td>
                                                        <td class="px-3 py-2.5 text-right font-bold text-xs font-mono bg-emerald-50/30" :class="storeItemClosing(si) < 0 ? 'text-red-600' : 'text-emerald-600'" x-text="Math.round(storeItemClosing(si))"></td>
                                                        <td class="px-3 py-2.5 text-right font-mono text-xs text-slate-500" x-text="'₦' + parseFloat(si.selling_price||0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        <td class="px-3 py-2.5 text-center">
                                                            <button @click="openAdjustModal(si); $nextTick(()=>lucide.createIcons())" :disabled="!si.id || lubeSections.length===0" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 dark:bg-rose-900/20 text-rose-600 text-[10px] font-bold rounded-lg border border-rose-200 dark:border-rose-800 transition-all disabled:opacity-40 disabled:cursor-not-allowed" title="Issue to counter">
                                                                <i data-lucide="arrow-right-circle" class="w-3 h-3"></i> Issue
                                                            </button>
                                                        </td>
                                                    </tr>
                                                </template>
                                                <tr x-show="lubeStoreItems.length === 0">
                                                    <td colspan="10" class="px-4 py-10 text-center text-slate-400 text-xs italic">
                                                        <i data-lucide="warehouse" class="w-8 h-8 mx-auto mb-2 opacity-30"></i>
                                                        <p>No products in lube store. Add products in the Products tab to auto-populate.</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Issue breakdown per counter (if items exist) -->
                                    <template x-if="lubeStoreItems.length > 0 && lubeSections.length > 0">
                                        <div class="px-6 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/20">
                                            <p class="text-[10px] font-bold text-slate-500 uppercase mb-2">Issues per Counter</p>
                                            <div class="flex flex-wrap gap-3">
                                                <template x-for="ls in lubeSections" :key="ls.id">
                                                    <div class="flex items-center gap-2 px-3 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg">
                                                        <i data-lucide="store" class="w-3 h-3 text-lime-600"></i>
                                                        <span class="text-[10px] font-bold text-slate-700 dark:text-slate-300" x-text="ls.name"></span>
                                                        <span class="text-[10px] font-mono text-rose-600" x-text="lubeIssues.filter(i=>i.section_id==ls.id).reduce((s,i)=>s+parseFloat(i.quantity||0),0).toLocaleString('en',{minimumFractionDigits:2}) + ' units'"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <!-- ── ISSUE HISTORY (Audit Trail) ── -->
                                <template x-if="lubeIssueLog.length > 0">
                                    <div class="border-t border-slate-200 dark:border-slate-700">
                                        <div class="px-6 py-3 bg-gradient-to-r from-rose-500/5 to-transparent flex items-center gap-2">
                                            <i data-lucide="history" class="w-3.5 h-3.5 text-rose-500"></i>
                                            <p class="text-[10px] font-bold text-rose-600 uppercase">Issue History</p>
                                            <span class="ml-auto text-[10px] text-slate-400 font-mono" x-text="lubeIssueLog.length + ' record(s)'"></span>
                                        </div>
                                        <div class="overflow-x-auto max-h-64 overflow-y-auto">
                                            <table class="w-full text-xs">
                                                <thead class="bg-rose-50/50 dark:bg-rose-900/10 sticky top-0">
                                                    <tr>
                                                        <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-500 uppercase">Date / Time</th>
                                                        <th class="px-4 py-2 text-left text-[10px] font-bold text-rose-600 uppercase">Product</th>
                                                        <th class="px-4 py-2 text-left text-[10px] font-bold text-lime-600 uppercase">Counter</th>
                                                        <th class="px-4 py-2 text-right text-[10px] font-bold text-slate-700 uppercase">Qty</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="(log, li) in lubeIssueLog" :key="li">
                                                        <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-rose-50/20 transition-colors">
                                                            <td class="px-4 py-1.5 font-mono text-[10px] text-slate-500" x-text="log.created_at ? new Date(log.created_at).toLocaleString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—'"></td>
                                                            <td class="px-4 py-1.5 font-semibold text-xs text-slate-700 dark:text-slate-300" x-text="log.product_name || '—'"></td>
                                                            <td class="px-4 py-1.5 text-xs text-lime-700" x-text="log.counter_name || '—'"></td>
                                                            <td class="px-4 py-1.5 text-right font-mono font-bold text-xs text-rose-600" x-text="parseFloat(log.quantity||0).toLocaleString('en')"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </template>

                                <!-- ── LUBE STORE STOCK COUNT ── -->
                                <div x-show="lubeStoreItems.length > 0" class="border-t border-slate-200 dark:border-slate-700">
                                    <div class="px-6 py-3 bg-gradient-to-r from-slate-500/5 to-transparent flex items-center gap-2">
                                        <i data-lucide="clipboard-check" class="w-3.5 h-3.5 text-slate-500"></i>
                                        <p class="text-[10px] font-bold text-slate-500 uppercase">Store Stock Count</p>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-xs">
                                            <thead class="bg-slate-50/80 dark:bg-slate-800/40">
                                                <tr>
                                                    <th class="px-4 py-2 text-left text-[10px] font-bold text-slate-600 uppercase">Product</th>
                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-emerald-600 uppercase">Closing Qty</th>
                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">Cost Price</th>
                                                    <th class="px-4 py-2 text-right text-[10px] font-bold text-blue-600 uppercase">Stock Value</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="row in lubeStoreStockCount" :key="row.product_name">
                                                    <tr class="border-t border-slate-100 dark:border-slate-800 hover:bg-slate-50/50 transition-colors">
                                                        <td class="px-4 py-2 font-semibold text-slate-700 dark:text-slate-300" x-text="row.product_name || '—'"></td>
                                                        <td class="px-4 py-2 text-right font-mono font-bold" :class="row.closing < 0 ? 'text-red-600' : 'text-emerald-600'" x-text="row.closing.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        <td class="px-4 py-2 text-right font-mono text-slate-500" x-text="'₦' + row.cost_price.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        <td class="px-4 py-2 text-right font-mono font-bold text-blue-700" x-text="'₦' + row.value.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                            <tfoot class="bg-slate-100/60 dark:bg-slate-800/60 border-t border-slate-200 dark:border-slate-700">
                                                <tr>
                                                    <td colspan="3" class="px-4 py-2 text-right text-[10px] font-bold text-slate-600 uppercase">Total Store Stock Value</td>
                                                    <td class="px-4 py-2 text-right font-black font-mono text-blue-800 dark:text-blue-300" x-text="'₦' + lubeStoreStockCount.reduce((s,r)=>s+r.value,0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>

                                <!-- Hint to save before issuing -->
                                <div x-show="lubeStoreItems.some(si=>!si.id)" class="flex items-center gap-2 px-4 py-2 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-700 font-semibold">
                                    <i data-lucide="info" class="w-3.5 h-3.5 flex-shrink-0"></i>
                                    Save the store first before issuing to counters.
                                </div>
                            </div>

                            <!-- ── COUNTERS SUB-TAB ── -->
                            <div x-show="lubeSubTab==='counters'" x-transition>
                                <div class="space-y-4">
                                    <!-- Counter header -->
                                    <div class="flex items-center justify-between">
                                        <p class="text-xs text-slate-500">Each counter receives stock from the Lube Store and tracks its own sales</p>
                                        <button @click="createLubeSection(); $nextTick(()=>lucide.createIcons())" x-show="lubeSections.length < 3" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-lime-500 to-green-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                                            <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> New Counter
                                        </button>
                                    </div>

                                    <!-- Counter cards -->
                                    <template x-for="(ls, lsi) in lubeSections" :key="ls.id">
                                        <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                            <!-- Counter header -->
                                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-lime-500/10 to-transparent flex items-center justify-between">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-8 h-8 bg-gradient-to-br from-lime-500 to-green-600 rounded-xl flex items-center justify-center text-white font-black text-sm" x-text="lsi+1"></div>
                                                    <div>
                                                        <h4 class="font-bold text-slate-900 dark:text-white text-sm" x-text="ls.name"></h4>
                                                        <p class="text-[10px] text-slate-500" x-text="(ls.items||[]).length + ' item(s) · Sales: ' + fmt(lubeSectionAmount(ls))"></p>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <button @click="ls._editing = !ls._editing" class="px-3 py-1.5 text-[10px] font-bold rounded-lg transition-all" :class="ls._editing ? 'bg-lime-100 text-lime-700 border border-lime-300' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 border border-slate-200'" x-text="ls._editing ? '✏️ Editing' : '✏️ Edit'"></button>
                                                    <button @click="deleteLubeSection(ls)" class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all">
                                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Items table -->
                                            <div class="overflow-x-auto">
                                                <table class="w-full text-sm">
                                                    <thead class="bg-lime-50/50 dark:bg-lime-900/10">
                                                        <tr>
                                                            <th class="px-3 py-2 text-left text-[10px] font-bold text-lime-700 uppercase">Item</th>
                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">Opening</th>
                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-rose-600 uppercase">Received<br><span class="text-[8px] normal-case font-normal">(from store)</span></th>
                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-700 uppercase bg-slate-50/50">Total</th>
                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-indigo-600 uppercase">Closing<br><span class="text-[8px] normal-case font-normal">(physical)</span></th>
                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-lime-700 uppercase">Sold<br><span class="text-[8px] normal-case font-normal">(computed)</span></th>
                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">Price</th>
                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-emerald-600 uppercase">Amount</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <template x-for="(it, ii) in ls.items" :key="ii">
                                                            <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-lime-50/20 transition-colors">
                                                                <td class="px-3 py-2.5 font-bold text-xs text-slate-800 dark:text-slate-200" x-text="it.item_name || '—'"></td>
                                                                <td class="px-3 py-2.5 text-right font-mono text-xs text-slate-700" x-text="Math.round(it.opening || 0)"></td>
                                                                <td class="px-3 py-2.5 text-right font-mono text-xs text-rose-600 font-bold" x-text="Math.round(it.received || 0)"></td>
                                                                <td class="px-3 py-2.5 text-right font-bold text-xs font-mono text-slate-700 bg-slate-50/50" x-text="Math.round((it.opening||0)+(it.received||0))"></td>
                                                                <td class="px-3 py-2.5 text-right font-mono text-xs font-bold text-indigo-700" x-text="Math.round(it.closing || 0)"></td>
                                                                <td class="px-3 py-2.5 text-right font-mono text-xs text-lime-700 font-bold" x-text="Math.round(counterItemSold(it))"></td>
                                                                <td class="px-3 py-2.5 text-right font-mono text-xs text-slate-500" x-text="'₦' + parseFloat(it.selling_price||0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                                <td class="px-3 py-2.5 text-right font-bold text-emerald-600 text-xs font-mono" x-text="fmt(counterItemSold(it)*(it.selling_price||0))"></td>
                                                            </tr>
                                                        </template>
                                                        <!-- Totals row -->
                                                        <tr x-show="(ls.items||[]).length > 0" class="bg-lime-50/60 dark:bg-lime-900/10 font-bold border-t-2 border-lime-200 dark:border-lime-800">
                                                            <td class="px-3 py-2 text-xs text-lime-700">Totals</td>
                                                            <td class="px-3 py-2 text-right font-mono text-xs" x-text="(ls.items||[]).reduce((s,i)=>parseFloat(i.opening||0)+s,0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-3 py-2 text-right font-mono text-xs text-rose-600" x-text="(ls.items||[]).reduce((s,i)=>parseFloat(i.received||0)+s,0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-3 py-2 text-right font-mono text-xs bg-slate-50/50" x-text="(ls.items||[]).reduce((s,i)=>parseFloat(i.opening||0)+parseFloat(i.received||0)+s,0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-3 py-2 text-right font-mono text-xs text-indigo-700" x-text="(ls.items||[]).reduce((s,i)=>parseFloat(i.closing||0)+s,0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-3 py-2 text-right font-mono text-xs text-lime-700" x-text="(ls.items||[]).reduce((s,i)=>counterItemSold(i)+s,0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-3 py-2"></td>
                                                            <td class="px-3 py-2 text-right font-mono text-xs text-emerald-700" x-text="fmt(lubeSectionAmount(ls))"></td>
                                                        </tr>
                                                        <tr x-show="(ls.items||[]).length === 0">
                                                            <td colspan="8" class="px-4 py-6 text-center text-slate-400 text-xs italic">No products in Lube Store yet. Add products to the store first — they'll appear here automatically.</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <!-- Save button -->
                                            <template x-if="ls._editing">
                                                <div class="px-6 py-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                                                    <p class="text-[10px] text-slate-400">Received column is auto-filled from store issuances but can be adjusted manually.</p>
                                                    <button @click="saveLubeItems(ls)" :disabled="saving" class="px-6 py-2 bg-gradient-to-r from-lime-500 to-green-600 text-white text-sm font-bold rounded-xl shadow-lg hover:scale-105 transition-all disabled:opacity-50">Save Counter</button>
                                                </div>
                                            </template>

                                            <!-- Receive History for this counter -->
                                            <template x-if="lubeIssueLog.filter(l => l.section_id == ls.id).length > 0">
                                                <div class="border-t border-slate-100 dark:border-slate-800">
                                                    <div class="px-6 py-2 bg-gradient-to-r from-blue-500/5 to-transparent flex items-center gap-2">
                                                        <i data-lucide="package-check" class="w-3 h-3 text-blue-500"></i>
                                                        <p class="text-[10px] font-bold text-blue-600 uppercase">Receive History</p>
                                                        <span class="ml-auto text-[10px] text-slate-400 font-mono" x-text="lubeIssueLog.filter(l => l.section_id == ls.id).length + ' record(s)'"></span>
                                                    </div>
                                                    <div class="overflow-x-auto max-h-48 overflow-y-auto">
                                                        <table class="w-full text-xs">
                                                            <thead class="bg-blue-50/50 dark:bg-blue-900/10 sticky top-0">
                                                                <tr>
                                                                    <th class="px-4 py-1.5 text-left text-[10px] font-bold text-slate-500 uppercase">Date / Time</th>
                                                                    <th class="px-4 py-1.5 text-left text-[10px] font-bold text-blue-600 uppercase">Product</th>
                                                                    <th class="px-4 py-1.5 text-right text-[10px] font-bold text-slate-700 uppercase">Qty Received</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <template x-for="(log, li) in lubeIssueLog.filter(l => l.section_id == ls.id)" :key="li">
                                                                    <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-blue-50/20 transition-colors">
                                                                        <td class="px-4 py-1 font-mono text-[10px] text-slate-500" x-text="log.created_at ? new Date(log.created_at).toLocaleString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—'"></td>
                                                                        <td class="px-4 py-1 font-semibold text-xs text-slate-700 dark:text-slate-300" x-text="log.product_name || '—'"></td>
                                                                        <td class="px-4 py-1 text-right font-mono font-bold text-xs text-blue-600" x-text="parseFloat(log.quantity||0).toLocaleString('en')"></td>
                                                                    </tr>
                                                                </template>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </template>

                                            <!-- ── Counter Stock Count Periods ── -->
                                            <div class="border-t border-slate-200 dark:border-slate-700 mt-2">
                                                <div class="px-4 py-3 flex items-center justify-between">
                                                    <div class="flex items-center gap-2">
                                                        <i data-lucide="calendar-range" class="w-3.5 h-3.5 text-cyan-600"></i>
                                                        <span class="text-[10px] font-bold text-slate-500 uppercase">Stock Count Periods</span>
                                                        <span class="px-1.5 py-0.5 text-[9px] font-bold rounded-full bg-cyan-100 text-cyan-700" x-text="getCounterStockCounts(ls.id).length"></span>
                                                    </div>
                                                    <button @click="openCounterStockCountModal(ls)" class="flex items-center gap-1 px-3 py-1.5 bg-gradient-to-r from-cyan-500 to-teal-500 text-white text-[10px] font-bold rounded-lg hover:shadow-lg hover:scale-105 transition-all">
                                                        <i data-lucide="plus" class="w-3 h-3"></i> New Stock Count
                                                    </button>
                                                </div>

                                                <!-- Period list -->
                                                <template x-for="sc in getCounterStockCounts(ls.id)" :key="sc.id">
                                                    <div class="border-t border-slate-100 dark:border-slate-800">
                                                        <div class="px-4 py-2.5 flex items-center justify-between hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors cursor-pointer" @click="counterStockCountView = (counterStockCountView === sc.id ? null : sc.id)">
                                                            <div class="flex items-center gap-3">
                                                                <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="counterStockCountView === sc.id && 'rotate-90'"></i>
                                                                <div>
                                                                    <div class="flex items-center gap-2">
                                                                        <span class="text-xs font-bold text-slate-700 dark:text-slate-300" x-text="new Date(sc.date_from).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) + ' → ' + new Date(sc.date_to).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})"></span>
                                                                        <span class="px-2 py-0.5 text-[9px] font-bold rounded-full" :class="sc.status === 'closed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'" x-text="sc.status === 'closed' ? 'Finalized' : 'Open'"></span>
                                                                    </div>
                                                                    <p class="text-[10px] text-slate-400" x-text="(sc.items||[]).length + ' product(s)' + (sc.notes ? ' · ' + sc.notes : '')"></p>
                                                                </div>
                                                            </div>
                                                            <div class="flex items-center gap-1" @click.stop>
                                                                <template x-if="sc.status !== 'closed'">
                                                                    <button @click="openCounterStockCountModal(ls, sc)" class="p-1.5 rounded-lg hover:bg-blue-100 text-blue-600 transition-colors" title="Edit">
                                                                        <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                                                                    </button>
                                                                </template>
                                                                <template x-if="sc.status !== 'closed'">
                                                                    <button @click="finalizeCounterStockCount(sc)" class="p-1.5 rounded-lg hover:bg-emerald-100 text-emerald-600 transition-colors" title="Finalize">
                                                                        <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                                                                    </button>
                                                                </template>
                                                                <button @click="deleteCounterStockCount(sc)" class="p-1.5 rounded-lg hover:bg-red-100 text-red-500 transition-colors" title="Delete">
                                                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <!-- Expanded detail -->
                                                        <div x-show="counterStockCountView === sc.id" x-transition class="px-4 pb-3">
                                                            <div class="overflow-x-auto rounded-xl border border-slate-200/60 dark:border-slate-700/60">
                                                                <table class="w-full text-xs">
                                                                    <thead class="bg-cyan-50/60 dark:bg-cyan-900/10">
                                                                        <tr>
                                                                            <th class="px-3 py-2 text-left text-[10px] font-bold text-cyan-700 uppercase">Product</th>
                                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-600 uppercase">System Stock</th>
                                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-blue-600 uppercase">Physical Count</th>
                                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-600 uppercase">Variance</th>
                                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-emerald-600 uppercase">Sold Qty</th>
                                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">Cost Price</th>
                                                                            <th class="px-3 py-2 text-right text-[10px] font-bold text-indigo-600 uppercase">Sold Value</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <template x-for="it in (sc.items||[])" :key="it.id">
                                                                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                                                                <td class="px-3 py-1.5 font-semibold text-slate-700 dark:text-slate-300" x-text="it.product_name"></td>
                                                                                <td class="px-3 py-1.5 text-right font-mono" x-text="it.system_stock"></td>
                                                                                <td class="px-3 py-1.5 text-right font-mono text-blue-700" x-text="it.physical_count"></td>
                                                                                <td class="px-3 py-1.5 text-right font-mono font-bold" :class="parseInt(it.variance) < 0 ? 'text-red-600' : parseInt(it.variance) > 0 ? 'text-amber-600' : 'text-emerald-600'" x-text="it.variance"></td>
                                                                                <td class="px-3 py-1.5 text-right font-mono text-emerald-700" x-text="it.sold_qty"></td>
                                                                                <td class="px-3 py-1.5 text-right font-mono text-slate-500" x-text="'₦' + parseFloat(it.cost_price||it.selling_price||0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                                                <td class="px-3 py-1.5 text-right font-mono font-bold text-indigo-700" x-text="'₦' + parseFloat(it.sold_value||0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                                            </tr>
                                                                        </template>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>

                                                <!-- No periods yet -->
                                                <div x-show="getCounterStockCounts(ls.id).length === 0" class="px-4 py-4 text-center text-slate-400 text-[11px] italic border-t border-slate-100">
                                                    No stock count periods yet. Click "New Stock Count" to start an audit period.
                                                </div>
                                            </div>

                                        </div>
                                    </template>

                                    <!-- Empty state -->
                                    <div x-show="lubeSections.length === 0" class="glass-card rounded-2xl p-10 text-center text-slate-400 border border-slate-200/60">
                                        <i data-lucide="store" class="w-10 h-10 mx-auto mb-3 opacity-30"></i>
                                        <p class="font-semibold mb-1">No counters yet</p>
                                        <p class="text-xs">Click "New Counter" to create a lube counter (up to 3).</p>
                                    </div>

                                    <!-- Counter Stock Count -->
                                    <div x-show="lubeSections.length > 0 && lubeCounterStockCount.length > 0" class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                        <div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-slate-500/5 to-transparent flex items-center gap-2">
                                            <i data-lucide="clipboard-check" class="w-3.5 h-3.5 text-slate-500"></i>
                                            <p class="text-[10px] font-bold text-slate-500 uppercase">Counter Stock Count — Closing Stock (All Counters Combined)</p>
                                        </div>
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-xs">
                                                <thead class="bg-slate-50/80 dark:bg-slate-800/40">
                                                    <tr>
                                                        <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-600 uppercase">Product</th>
                                                        <th class="px-4 py-2.5 text-right text-[10px] font-bold text-emerald-600 uppercase">Total Closing Qty</th>
                                                        <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Cost Price</th>
                                                        <th class="px-4 py-2.5 text-right text-[10px] font-bold text-blue-600 uppercase">Stock Value</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="row in lubeCounterStockCount" :key="row.product_name">
                                                        <tr class="border-t border-slate-100 dark:border-slate-800 hover:bg-slate-50/50 transition-colors">
                                                            <td class="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-300" x-text="row.product_name || '—'"></td>
                                                            <td class="px-4 py-2.5 text-right font-mono font-bold" :class="row.closing < 0 ? 'text-red-600' : 'text-emerald-600'" x-text="row.closing.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-4 py-2.5 text-right font-mono text-slate-500" x-text="'₦' + row.cost_price.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-4 py-2.5 text-right font-mono font-bold text-blue-700" x-text="'₦' + row.value.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                                <tfoot class="bg-slate-100/60 dark:bg-slate-800/60 border-t border-slate-200 dark:border-slate-700">
                                                    <tr>
                                                        <td colspan="3" class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-600 uppercase">Total Counter Stock Value</td>
                                                        <td class="px-4 py-2.5 text-right font-black font-mono text-blue-800 dark:text-blue-300" x-text="'₦' + lubeCounterStockCount.reduce((s,r)=>s+r.value,0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>

                                </div>
                            </div>

                            <!-- ── STOCK COUNT SUB-TAB ── -->
                            <div x-show="lubeSubTab==='stock_count'" x-transition>
                                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                    <!-- Header -->
                                    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-cyan-500/10 to-transparent flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 bg-gradient-to-br from-cyan-500 to-teal-600 rounded-xl flex items-center justify-center shadow-lg shadow-cyan-500/30">
                                                <i data-lucide="clipboard-check" class="w-4 h-4 text-white"></i>
                                            </div>
                                            <div>
                                                <h4 class="font-bold text-slate-900 dark:text-white text-sm">Stock Count — Physical Verification</h4>
                                                <p class="text-[10px] text-slate-500" x-text="lubeStockCounts.length + ' count(s) · Create periodic physical stock counts'"></p>
                                            </div>
                                        </div>
                                        <button @click="openStockCountModal(); $nextTick(()=>lucide.createIcons())" :disabled="lubeStoreItems.length===0" class="flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-cyan-500 to-teal-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50">
                                            <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> Create Stock Count
                                        </button>
                                    </div>

                                    <!-- List of stock counts -->
                                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                                        <template x-for="(sc, sci) in lubeStockCounts" :key="sc.id">
                                            <div class="px-6 py-4 hover:bg-cyan-50/30 dark:hover:bg-cyan-900/5 transition-colors">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center gap-3">
                                                        <div class="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black" :class="sc.status==='closed' ? 'bg-emerald-100 text-emerald-700' : 'bg-cyan-100 text-cyan-700'" x-text="sci+1"></div>
                                                        <div>
                                                            <p class="text-sm font-bold text-slate-800 dark:text-slate-200">
                                                                <span x-text="sc.date_from"></span> → <span x-text="sc.date_to"></span>
                                                            </p>
                                                            <p class="text-[10px] text-slate-500">
                                                                <span x-text="(sc.items||[]).length + ' items'"></span> ·
                                                                <span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold" :class="sc.status==='closed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'" x-text="sc.status==='closed' ? 'Finalized' : 'Open'"></span>
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center gap-1.5">
                                                        <button @click="lubeStockCountView = (lubeStockCountView === sc.id ? null : sc.id); $nextTick(()=>lucide.createIcons())" class="p-1.5 text-cyan-500 hover:bg-cyan-50 rounded-lg transition-all" title="View/Hide details">
                                                            <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                                        </button>
                                                        <button @click="openStockCountModal(sc); $nextTick(()=>lucide.createIcons())" class="p-1.5 text-cyan-500 hover:bg-cyan-50 rounded-lg transition-all" title="Edit">
                                                            <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                                                        </button>
                                                        <button x-show="sc.status!=='closed'" @click="finalizeLubeStockCount(sc)" class="p-1.5 text-emerald-500 hover:bg-emerald-50 rounded-lg transition-all" title="Finalize">
                                                            <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                                                        </button>
                                                        <button @click="deleteLubeStockCount(sc)" class="p-1.5 text-red-400 hover:bg-red-50 rounded-lg transition-all" title="Delete">
                                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                <!-- Expandable detail view -->
                                                <div x-show="lubeStockCountView === sc.id" x-transition class="mt-3 overflow-x-auto">
                                                    <table class="w-full text-xs">
                                                        <thead class="bg-cyan-50/50 dark:bg-cyan-900/10">
                                                            <tr>
                                                                <th class="px-3 py-2 text-left text-[10px] font-bold text-cyan-700 uppercase">Product</th>
                                                                <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">System Stock</th>
                                                                <th class="px-3 py-2 text-right text-[10px] font-bold text-cyan-600 uppercase">Physical Count</th>
                                                                <th class="px-3 py-2 text-right text-[10px] font-bold uppercase">Variance</th>
                                                                <th class="px-3 py-2 text-right text-[10px] font-bold text-emerald-600 uppercase">Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <template x-for="it in (sc.items||[])" :key="it.id">
                                                                <tr class="border-t border-slate-100 dark:border-slate-800">
                                                                    <td class="px-3 py-2 font-bold text-slate-800" x-text="it.product_name"></td>
                                                                    <td class="px-3 py-2 text-right font-mono text-slate-600" x-text="it.system_stock"></td>
                                                                    <td class="px-3 py-2 text-right font-mono text-cyan-700 font-bold" x-text="it.physical_count"></td>
                                                                    <td class="px-3 py-2 text-right font-mono font-bold" :class="it.variance < 0 ? 'text-red-600' : it.variance > 0 ? 'text-amber-600' : 'text-emerald-600'" x-text="it.variance"></td>
                                                                    <td class="px-3 py-2 text-right">
                                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold" :class="it.variance == 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'" x-text="it.variance == 0 ? 'Balanced' : 'Unbalanced'"></span>
                                                                    </td>
                                                                </tr>
                                                            </template>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </template>
                                        <div x-show="lubeStockCounts.length === 0" class="px-6 py-10 text-center text-slate-400 text-xs italic">
                                            <i data-lucide="clipboard-check" class="w-8 h-8 mx-auto mb-2 opacity-30"></i>
                                            <p>No stock counts yet. Click "Create Stock Count" to start a physical verification exercise.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ── EVALUATION SUB-TAB ── -->
                            <div x-show="lubeSubTab==='evaluation'" x-transition>
                                <div class="space-y-6">
                                    <!-- Consolidated Stock Evaluation -->
                                    <div x-show="lubeConsolidation.length > 0" class="glass-card rounded-2xl border border-indigo-200/60 dark:border-indigo-700/60 shadow-lg overflow-hidden">
                                        <div class="px-6 py-4 border-b border-indigo-100 dark:border-indigo-800 bg-gradient-to-r from-indigo-500/10 to-transparent flex items-center gap-3">
                                            <div class="w-9 h-9 bg-gradient-to-br from-indigo-500 to-violet-600 rounded-xl flex items-center justify-center shadow-lg">
                                                <i data-lucide="layers" class="w-4 h-4 text-white"></i>
                                            </div>
                                            <div>
                                                <h4 class="font-bold text-slate-900 dark:text-white text-sm">Consolidated Stock Evaluation</h4>
                                                <p class="text-[10px] text-slate-500">Lube Store closing + All Counter closings combined per product</p>
                                            </div>
                                        </div>
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-sm">
                                                <thead class="bg-indigo-50/50 dark:bg-indigo-900/10">
                                                    <tr>
                                                        <th class="px-4 py-2.5 text-left text-[10px] font-bold text-indigo-700 uppercase">Product</th>
                                                        <th class="px-4 py-2.5 text-right text-[10px] font-bold text-lime-600 uppercase">Store Closing</th>
                                                        <th class="px-4 py-2.5 text-right text-[10px] font-bold text-blue-600 uppercase">Counter Closing</th>
                                                        <th class="px-4 py-2.5 text-right text-[10px] font-bold text-indigo-700 uppercase bg-indigo-50/50">Total Closing</th>
                                                        <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Cost Price</th>
                                                        <th class="px-4 py-2.5 text-right text-[10px] font-bold text-emerald-600 uppercase">Total Value</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="row in lubeConsolidation" :key="row.product_name">
                                                        <tr class="border-t border-slate-100 dark:border-slate-800 hover:bg-indigo-50/20 transition-colors">
                                                            <td class="px-4 py-2.5 font-bold text-slate-800 dark:text-slate-200 text-xs" x-text="row.product_name || '—'"></td>
                                                            <td class="px-4 py-2.5 text-right font-mono text-xs text-lime-700" x-text="row.store_closing.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-4 py-2.5 text-right font-mono text-xs text-blue-700" x-text="row.counter_closing.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-4 py-2.5 text-right font-mono font-bold text-xs bg-indigo-50/30" :class="row.total_closing < 0 ? 'text-red-600' : 'text-indigo-700'" x-text="row.total_closing.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-4 py-2.5 text-right font-mono text-xs text-slate-500" x-text="'₦' + row.cost_price.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                            <td class="px-4 py-2.5 text-right font-mono font-bold text-xs text-emerald-700" x-text="'₦' + row.total_value.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                                <tfoot class="bg-indigo-50/60 dark:bg-indigo-900/20 border-t-2 border-indigo-200 dark:border-indigo-700">
                                                    <tr>
                                                        <td colspan="5" class="px-4 py-3 text-right text-xs font-bold text-indigo-700 uppercase">Grand Total Consolidated Stock Value</td>
                                                        <td class="px-4 py-3 text-right font-black font-mono text-indigo-800 dark:text-indigo-300 text-base" x-text="'₦' + lubeConsolidationTotalValue.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Grand Total Sales -->
                                    <div x-show="lubeSections.length > 0" class="glass-card rounded-2xl p-4 border border-lime-200/60 dark:border-lime-700/60 bg-gradient-to-r from-lime-500/5 to-transparent">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm font-bold text-slate-700 dark:text-slate-300">Grand Total — Lubricant Sales (All Counters)</span>
                                            <span class="text-lg font-black text-lime-700 dark:text-lime-400 font-mono" x-text="fmt(lubeTotalAmount)"></span>
                                        </div>
                                    </div>

                                    <!-- Empty state -->
                                    <div x-show="lubeConsolidation.length === 0 && lubeSections.length === 0" class="glass-card rounded-2xl p-10 text-center text-slate-400 border border-slate-200/60">
                                        <i data-lucide="layers" class="w-10 h-10 mx-auto mb-3 opacity-30"></i>
                                        <p class="font-semibold mb-1">No evaluation data yet</p>
                                        <p class="text-xs">Add products to the Lube Store and issue them to counters to see the consolidated evaluation.</p>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ═══ STOCK COUNT MODAL ═══ -->
                    <div x-show="lubeStockCountModal" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="lubeStockCountModal=false">
                        <div x-show="lubeStockCountModal" x-transition.scale.90 class="w-full max-w-4xl max-h-[90vh] glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden flex flex-col" @click.stop>
                            <!-- Header -->
                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-cyan-500/10 to-transparent flex items-center justify-between shrink-0">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-gradient-to-br from-cyan-500 to-teal-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="clipboard-check" class="w-4 h-4 text-white"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="lubeStockCountForm.id ? 'Edit Stock Count' : 'New Stock Count'"></h3>
                                        <p class="text-[10px] text-slate-500">Count physical stock and compare against system balance</p>
                                    </div>
                                </div>
                                <button @click="lubeStockCountModal=false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                            </div>
                            <!-- Guide -->
                            <div class="px-6 py-3 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-100 dark:border-amber-800 text-amber-800 dark:text-amber-200 text-xs flex items-start gap-2 shrink-0">
                                <i data-lucide="info" class="w-4 h-4 mt-0.5 shrink-0"></i>
                                <div>
                                    <p class="font-bold mb-1">Stock Count Guidelines:</p>
                                    <ul class="list-disc pl-4 space-y-0.5 opacity-80">
                                        <li>Always set the date to the <strong>beginning of the month</strong>.</li>
                                        <li>Ensure all <strong>outstanding items are posted to counters</strong> before starting the count.</li>
                                    </ul>
                                </div>
                            </div>
                            <!-- Date range -->
                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-end gap-4 shrink-0">
                                <div class="flex-1">
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Period From</label>
                                    <input type="date" x-model="lubeStockCountForm.date_from" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition-all">
                                </div>
                                <div class="flex-1">
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Period To</label>
                                    <input type="date" x-model="lubeStockCountForm.date_to" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition-all">
                                </div>
                                <div class="flex-1">
                                    <label class="text-[11px] font-semibold mb-1 block text-slate-500">Notes (optional)</label>
                                    <input type="text" x-model="lubeStockCountForm.notes" placeholder="e.g. Monthly stock take" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition-all">
                                </div>
                            </div>
                            <!-- Items table -->
                            <div class="overflow-y-auto flex-1">
                                <table class="w-full text-sm">
                                    <thead class="bg-cyan-50/50 dark:bg-cyan-900/10">
                                        <tr>
                                            <th class="px-3 py-2.5 text-left text-[10px] font-bold text-cyan-700 uppercase">Product</th>
                                            <th class="px-3 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">System Stock</th>
                                            <th class="px-3 py-2.5 text-right text-[10px] font-bold text-cyan-600 uppercase">Physical Count</th>
                                            <th class="px-3 py-2.5 text-right text-[10px] font-bold uppercase">Variance</th>
                                            <th class="px-3 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(it, ii) in lubeStockCountForm.items" :key="ii">
                                            <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-cyan-50/30 transition-colors">
                                                <td class="px-3 py-2.5 font-bold text-xs text-slate-800 dark:text-slate-200" x-text="it.product_name"></td>
                                                <td class="px-3 py-2.5 text-right font-mono text-xs text-slate-600" x-text="it.system_stock"></td>
                                                <td class="px-3 py-2.5 text-right">
                                                    <input type="number" step="1" x-model.number="it.physical_count" class="w-20 text-right px-2 py-1.5 bg-cyan-50 dark:bg-cyan-900/10 border border-cyan-200 dark:border-cyan-800 rounded-lg text-xs font-mono font-bold focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition-all">
                                                </td>
                                                <td class="px-3 py-2.5 text-right font-mono text-xs font-bold" :class="stockCountItemVariance(it) < 0 ? 'text-red-600' : stockCountItemVariance(it) > 0 ? 'text-amber-600' : 'text-emerald-600'" x-text="stockCountItemVariance(it)"></td>
                                                <td class="px-3 py-2.5 text-right">
                                                    <span class="px-2 py-1 rounded-full text-[10px] font-bold border" :class="stockCountItemVariance(it) === 0 ? 'bg-emerald-50 border-emerald-100 text-emerald-700' : 'bg-red-50 border-red-100 text-red-700'" x-text="stockCountItemVariance(it) === 0 ? 'Balanced' : 'Unbalanced'"></span>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Footer -->
                            <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
                                <p class="text-[10px] text-slate-400">Enter physical count for each product. Variance = Physical Count − System Stock.</p>
                                <div class="flex items-center gap-2">
                                    <button @click="lubeStockCountModal=false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition-all">Cancel</button>
                                    <button @click="saveLubeStockCount()" :disabled="saving" class="px-6 py-2 bg-gradient-to-r from-cyan-500 to-teal-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50">
                                        <span x-text="saving ? 'Saving...' : 'Save Stock Count'"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ COUNTER STOCK COUNT MODAL ═══ -->
                    <div x-show="counterStockCountModal" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="counterStockCountModal=false">
                        <div x-show="counterStockCountModal" x-transition.scale.90 class="w-full max-w-4xl max-h-[90vh] glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden flex flex-col" @click.stop>
                            <!-- Header -->
                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-cyan-500/10 to-transparent flex items-center justify-between shrink-0">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-gradient-to-br from-cyan-500 to-teal-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="calendar-range" class="w-4 h-4 text-white"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="counterStockCountForm.id ? 'Edit Counter Stock Count' : 'New Counter Stock Count'"></h3>
                                        <p class="text-[10px] text-slate-500">Period-based physical count for audit verification</p>
                                    </div>
                                </div>
                                <button @click="counterStockCountModal=false" class="p-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-700 transition-all">
                                    <i data-lucide="x" class="w-5 h-5 text-slate-400"></i>
                                </button>
                            </div>
                            <!-- Date range & notes -->
                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 grid grid-cols-3 gap-4 shrink-0">
                                <div>
                                    <label class="text-[10px] font-bold text-slate-500 uppercase mb-1 block">Date From</label>
                                    <input type="date" x-model="counterStockCountForm.date_from" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition-all">
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold text-slate-500 uppercase mb-1 block">Date To</label>
                                    <input type="date" x-model="counterStockCountForm.date_to" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition-all">
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold text-slate-500 uppercase mb-1 block">Notes</label>
                                    <input type="text" x-model="counterStockCountForm.notes" placeholder="Optional notes..." class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition-all">
                                </div>
                            </div>
                            <!-- Items table -->
                            <div class="overflow-y-auto flex-1">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50/80 dark:bg-slate-800/40 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-2.5 text-left text-[10px] font-bold text-slate-600 uppercase">Product</th>
                                            <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-600 uppercase">System Stock</th>
                                            <th class="px-4 py-2.5 text-center text-[10px] font-bold text-blue-600 uppercase">Physical Count</th>
                                            <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-600 uppercase">Variance</th>
                                            <th class="px-4 py-2.5 text-right text-[10px] font-bold text-emerald-600 uppercase">Sold Qty</th>
                                            <th class="px-4 py-2.5 text-right text-[10px] font-bold text-slate-500 uppercase">Cost Price</th>
                                            <th class="px-4 py-2.5 text-right text-[10px] font-bold text-indigo-600 uppercase">Sold Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(it, idx) in counterStockCountForm.items" :key="idx">
                                            <tr class="border-t border-slate-100 dark:border-slate-800 hover:bg-cyan-50/20 transition-colors">
                                                <td class="px-4 py-2 font-semibold text-slate-700 dark:text-slate-300 text-xs" x-text="it.product_name"></td>
                                                <td class="px-4 py-2 text-right font-mono text-xs" x-text="it.system_stock"></td>
                                                <td class="px-4 py-2 text-center">
                                                    <input type="number" x-model.number="it.physical_count" min="0" class="w-20 px-2 py-1 text-center text-xs font-mono border border-blue-200 rounded-lg focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 bg-blue-50/30 transition-all">
                                                </td>
                                                <td class="px-4 py-2 text-right font-mono font-bold text-xs" :class="counterStockCountItemVariance(it) < 0 ? 'text-red-600' : counterStockCountItemVariance(it) > 0 ? 'text-amber-600' : 'text-emerald-600'" x-text="counterStockCountItemVariance(it)"></td>
                                                <td class="px-4 py-2 text-right font-mono font-bold text-xs text-emerald-700" x-text="counterStockCountItemSold(it)"></td>
                                                <td class="px-4 py-2 text-right font-mono text-xs text-slate-500" x-text="'₦' + parseFloat(it.cost_price||it.selling_price||0).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                <td class="px-4 py-2 text-right font-mono font-bold text-xs text-indigo-700" x-text="'₦' + (counterStockCountItemSold(it) * parseFloat(it.cost_price||it.selling_price||0)).toLocaleString('en',{minimumFractionDigits:2})"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Footer -->
                            <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
                                <p class="text-[10px] text-slate-400">Enter physical count for each product. Variance = Physical − System.</p>
                                <div class="flex items-center gap-2">
                                    <button @click="counterStockCountModal=false" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition-all">Cancel</button>
                                    <button @click="saveCounterStockCount()" :disabled="saving" class="px-6 py-2 bg-gradient-to-r from-cyan-500 to-teal-600 text-white text-xs font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50">
                                        <span x-text="saving ? 'Saving...' : 'Save Stock Count'"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ ISSUE / ADJUSTMENT MODAL ═══ -->
                    <div x-show="lubeIssueModal" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="lubeIssueModal=false">
                        <div x-show="lubeIssueModal" x-transition.scale.90 class="w-full max-w-sm glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden" @click.stop>
                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-rose-500/10 to-transparent flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-gradient-to-br from-rose-500 to-red-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="arrow-right-circle" class="w-4 h-4 text-white"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 dark:text-white text-sm">Issue / Adjust Stock</h3>
                                        <p class="text-[10px] text-slate-500" x-text="'Product: ' + lubeIssueForm.store_item_name"></p>
                                    </div>
                                </div>
                                <button @click="lubeIssueModal=false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                            </div>
                            <div class="p-6 space-y-4">
                                <!-- Mode toggle -->
                                <div class="flex gap-1 p-1 bg-slate-100 dark:bg-slate-800/50 rounded-xl">
                                    <button @click="lubeIssueForm.mode='issue'" :class="lubeIssueForm.mode==='issue' ? 'bg-white dark:bg-slate-900 text-rose-600 shadow-sm' : 'text-slate-500'" class="flex-1 py-2 text-xs font-bold rounded-lg transition-all text-center">Issue to Counter</button>
                                    <button @click="lubeIssueForm.mode='adjust'" :class="lubeIssueForm.mode==='adjust' ? 'bg-white dark:bg-slate-900 text-indigo-600 shadow-sm' : 'text-slate-500'" class="flex-1 py-2 text-xs font-bold rounded-lg transition-all text-center">Adjustment</button>
                                </div>

                                <!-- ISSUE MODE -->
                                <div x-show="lubeIssueForm.mode==='issue'" class="space-y-4">
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Select Counter</label>
                                        <select x-model="lubeIssueForm.section_id" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 transition-all">
                                            <option value="">Choose counter...</option>
                                            <template x-for="ls in lubeSections" :key="ls.id">
                                                <option :value="ls.id" x-text="ls.name"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Quantity to Issue</label>
                                        <input type="number" step="1" x-model.number="lubeIssueForm.quantity" placeholder="0" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-mono text-center text-lg font-bold focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 transition-all">
                                    </div>
                                    <div x-show="lubeSections.length > 0" class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                                        <p class="text-[10px] font-bold text-slate-500 mb-2 uppercase">Current Issues</p>
                                        <div class="space-y-1">
                                            <template x-for="ls in lubeSections" :key="ls.id">
                                                <div class="flex items-center justify-between text-xs">
                                                    <span class="text-slate-600 dark:text-slate-400" x-text="ls.name"></span>
                                                    <span class="font-mono font-bold" :class="getIssuedQty(lubeIssueForm.store_item_id, ls.id) > 0 ? 'text-rose-600' : 'text-slate-300'" x-text="Math.round(getIssuedQty(lubeIssueForm.store_item_id, ls.id))"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="flex gap-3">
                                        <button @click="lubeIssueModal=false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                                        <button @click="issueLubeToCounter()" :disabled="saving || !lubeIssueForm.section_id" class="flex-1 py-2.5 bg-gradient-to-r from-rose-500 to-red-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50 text-sm">Issue Stock</button>
                                    </div>
                                </div>

                                <!-- ADJUST MODE -->
                                <div x-show="lubeIssueForm.mode==='adjust'" class="space-y-4">
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Adjustment Quantity <span class="text-[9px] text-slate-400">(+ to add, − to subtract)</span></label>
                                        <input type="number" step="1" x-model.number="lubeIssueForm.adjustment_qty" placeholder="0" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-indigo-200 dark:border-indigo-800 rounded-xl text-sm font-mono text-center text-lg font-bold focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all">
                                    </div>
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Reason for Adjustment <span class="text-red-500">*</span></label>
                                        <textarea x-model="lubeIssueForm.adjustment_reason" rows="2" placeholder="e.g. Damage, expired, correction, transfer..." class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all resize-none"></textarea>
                                    </div>
                                    <div class="p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl text-center">
                                        <p class="text-[10px] font-bold text-indigo-500 uppercase mb-1">Resulting Adjustment</p>
                                        <p class="text-2xl font-black font-mono" :class="lubeIssueForm.adjustment_qty > 0 ? 'text-indigo-600' : lubeIssueForm.adjustment_qty < 0 ? 'text-red-600' : 'text-slate-400'" x-text="(lubeIssueForm.adjustment_qty >= 0 ? '+' : '') + lubeIssueForm.adjustment_qty"></p>
                                    </div>
                                    <div class="flex gap-3">
                                        <button @click="lubeIssueModal=false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                                        <button @click="saveAdjustment()" :disabled="saving || !lubeIssueForm.adjustment_reason.trim()" class="flex-1 py-2.5 bg-gradient-to-r from-indigo-500 to-violet-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50 text-sm">Save Adjustment</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ PRODUCT MODAL ═══ -->
                    <div x-show="lubeProductModal" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="lubeProductModal=false">
                        <div x-show="lubeProductModal" x-transition.scale.90 class="w-full max-w-md glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden" @click.stop>
                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-violet-500/10 to-transparent flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-gradient-to-br from-violet-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="package" class="w-4 h-4 text-white"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="lubeProductForm.id ? 'Edit Product' : 'Add Product'"></h3>
                                        <p class="text-[10px] text-slate-500">Lubricant product catalog</p>
                                    </div>
                                </div>
                                <button @click="lubeProductModal=false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                            </div>
                            <div class="p-6 space-y-4">
                                <div>
                                    <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Product Name *</label>
                                    <input type="text" x-model="lubeProductForm.product_name" placeholder="e.g. Engine Oil 1L" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Unit</label>
                                        <select x-model="lubeProductForm.unit" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all">
                                            <option>Litre</option><option>Piece</option><option>Carton</option><option>Drum</option><option>Kg</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Reorder Level</label>
                                        <input type="number" step="0.01" x-model.number="lubeProductForm.reorder_level" placeholder="0.00" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-mono focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-all">
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-orange-600">Cost Price (₦)</label>
                                        <input type="number" step="0.01" x-model.number="lubeProductForm.cost_price" placeholder="0.00" class="w-full px-3 py-2.5 bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800 rounded-xl text-sm font-mono font-bold text-orange-700 focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition-all">
                                    </div>
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-emerald-600">Selling Price (₦)</label>
                                        <input type="number" step="0.01" x-model.number="lubeProductForm.selling_price" placeholder="0.00" class="w-full px-3 py-2.5 bg-emerald-50 dark:bg-emerald-900/10 border border-emerald-200 dark:border-emerald-800 rounded-xl text-sm font-mono font-bold text-emerald-700 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all">
                                    </div>
                                </div>
                                <!-- Margin preview -->
                                <div class="flex items-center justify-between px-4 py-2.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                                    <span class="text-[11px] font-semibold text-slate-500">Margin per unit</span>
                                    <span class="text-sm font-black font-mono" :class="(lubeProductForm.selling_price - lubeProductForm.cost_price) >= 0 ? 'text-blue-600' : 'text-red-600'" x-text="'₦' + (parseFloat(lubeProductForm.selling_price||0) - parseFloat(lubeProductForm.cost_price||0)).toLocaleString('en',{minimumFractionDigits:2})"></span>
                                </div>
                                <div class="flex gap-3 pt-1">
                                    <button @click="lubeProductModal=false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                                    <button @click="saveLubeProduct()" :disabled="saving" class="flex-1 py-2.5 bg-gradient-to-r from-violet-500 to-purple-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50 text-sm">Save Product</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ SUPPLIER MODAL ═══ -->
                    <div x-show="lubeSupplierModal" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="lubeSupplierModal=false">
                        <div x-show="lubeSupplierModal" x-transition.scale.90 class="w-full max-w-md glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden" @click.stop>
                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-orange-500/10 to-transparent flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-gradient-to-br from-orange-500 to-amber-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="truck" class="w-4 h-4 text-white"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="lubeSupplierForm.id ? 'Edit Supplier' : 'Add Supplier'"></h3>
                                        <p class="text-[10px] text-slate-500">Lubricant supplier details</p>
                                    </div>
                                </div>
                                <button @click="lubeSupplierModal=false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                            </div>
                            <div class="p-6 space-y-4">
                                <div>
                                    <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Supplier Name *</label>
                                    <input type="text" x-model="lubeSupplierForm.supplier_name" placeholder="e.g. Total Energies Nigeria" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition-all">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Contact Person</label>
                                        <input type="text" x-model="lubeSupplierForm.contact_person" placeholder="Full name" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition-all">
                                    </div>
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Phone</label>
                                        <input type="text" x-model="lubeSupplierForm.phone" placeholder="08012345678" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition-all">
                                    </div>
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Email</label>
                                    <input type="email" x-model="lubeSupplierForm.email" placeholder="supplier@example.com" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition-all">
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Address</label>
                                    <textarea x-model="lubeSupplierForm.address" rows="2" placeholder="Street, City, State" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition-all resize-none"></textarea>
                                </div>
                                <div class="flex gap-3 pt-1">
                                    <button @click="lubeSupplierModal=false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                                    <button @click="saveLubeSupplier()" :disabled="saving" class="flex-1 py-2.5 bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50 text-sm">Save Supplier</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ GRN MODAL ═══ -->
                    <div x-show="lubeGrnModal" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="lubeGrnModal=false">
                        <div x-show="lubeGrnModal" x-transition.scale.90 class="w-full max-w-2xl glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-2xl overflow-hidden max-h-[90vh] flex flex-col" @click.stop>
                            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-500/10 to-transparent flex items-center justify-between flex-shrink-0">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="clipboard-list" class="w-4 h-4 text-white"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="lubeGrnForm.id ? 'Edit GRN' : 'New GRN'"></h3>
                                        <p class="text-[10px] text-slate-500" x-text="lubeGrnForm.grn_number"></p>
                                    </div>
                                </div>
                                <button @click="lubeGrnModal=false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-100 transition-colors"><i data-lucide="x" class="w-4 h-4 text-slate-500"></i></button>
                            </div>
                            <div class="p-6 space-y-4 overflow-y-auto">
                                <!-- GRN header fields -->
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">GRN Number</label>
                                        <input type="text" x-model="lubeGrnForm.grn_number" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                    </div>
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Date *</label>
                                        <input type="date" x-model="lubeGrnForm.grn_date" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Supplier</label>
                                        <select x-model="lubeGrnForm.supplier_id" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                            <option value="">— No Supplier —</option>
                                            <template x-for="s in lubeSuppliers" :key="s.id">
                                                <option :value="s.id" x-text="s.supplier_name"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Invoice Number</label>
                                        <input type="text" x-model="lubeGrnForm.invoice_number" placeholder="INV-001" class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                    </div>
                                </div>
                                <!-- Line items -->
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-[11px] font-bold text-slate-500 uppercase">Line Items</p>
                                        <button @click="addGrnItem(); $nextTick(()=>lucide.createIcons())" class="flex items-center gap-1 px-3 py-1.5 text-[10px] font-bold text-blue-600 hover:bg-blue-50 rounded-lg border border-blue-200 transition-all">
                                            <i data-lucide="plus" class="w-3 h-3"></i> Add Item
                                        </button>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                                        <table class="w-full text-xs">
                                            <thead class="bg-blue-50/50 dark:bg-blue-900/10">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-[10px] font-bold text-blue-700 uppercase">Product</th>
                                                    <th class="px-3 py-2 text-center text-[10px] font-bold text-slate-500 uppercase">Unit</th>
                                                    <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-500 uppercase">Qty</th>
                                                    <th class="px-3 py-2 text-right text-[10px] font-bold text-slate-400 uppercase">Unit Cost</th>
                                                    <th class="px-3 py-2 text-right text-[10px] font-bold text-emerald-600 uppercase">Sell ₦</th>
                                                    <th class="px-3 py-2 text-right text-[10px] font-bold text-blue-700 uppercase">Total ₦</th>
                                                    <th class="px-2 py-2"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="(it, idx) in lubeGrnForm.items" :key="idx">
                                                    <tr class="border-t border-slate-100 dark:border-slate-800">
                                                        <td class="px-2 py-1.5">
                                                            <select x-model="it.product_id" @change="fillGrnItemFromProduct(it)" class="w-32 px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs focus:ring-1 focus:ring-blue-500">
                                                                <option value="">Custom...</option>
                                                                <template x-for="p in lubeProducts" :key="p.id">
                                                                    <option :value="p.id" x-text="p.product_name"></option>
                                                                </template>
                                                            </select>
                                                            <input x-show="!it.product_id" type="text" x-model="it.product_name" placeholder="Product name" class="mt-1 w-32 px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs focus:ring-1 focus:ring-blue-500">
                                                        </td>
                                                        <td class="px-2 py-1.5 text-center">
                                                            <input type="text" x-model="it.unit" class="w-16 text-center px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                                        </td>
                                                        <td class="px-2 py-1.5 text-right">
                                                            <input type="number" step="0.01" x-model.number="it.quantity" @input="calculateGrnItemCost(it)" class="w-16 text-right px-2 py-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono">
                                                        </td>
                                                        <td class="px-2 py-1.5 text-right">
                                                            <input type="number" step="0.01" x-model.number="it.cost_price" readonly class="w-20 text-right px-2 py-1 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-mono text-slate-500 cursor-not-allowed">
                                                        </td>
                                                        <td class="px-2 py-1.5 text-right">
                                                            <input type="number" step="0.01" x-model.number="it.selling_price" class="w-20 text-right px-2 py-1 bg-emerald-50 border border-emerald-200 rounded-lg text-xs font-mono text-emerald-700">
                                                        </td>
                                                        <td class="px-2 py-1.5 text-right">
                                                            <input type="number" step="0.01" x-model.number="it.total_cost" @input="calculateGrnItemCost(it)" class="w-24 text-right px-2 py-1 bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800 rounded-lg text-xs font-mono font-bold text-blue-700">
                                                        </td>
                                                        <td class="px-2 py-1.5 text-center">
                                                            <button @click="lubeGrnForm.items.splice(idx,1)" class="p-1 text-red-400 hover:bg-red-50 rounded-lg"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                                                        </td>
                                                    </tr>
                                                </template>
                                                <tr x-show="lubeGrnForm.items.length === 0">
                                                    <td colspan="7" class="px-3 py-4 text-center text-slate-400 text-xs italic">No items. Click "Add Item" above.</td>
                                                </tr>
                                            </tbody>
                                            <tfoot class="bg-slate-50 dark:bg-slate-800/50 border-t border-slate-200 dark:border-slate-700">
                                                <tr>
                                                    <td colspan="5" class="px-3 py-2 text-right text-xs font-bold text-slate-600">Grand Total Cost</td>
                                                    <td class="px-2 py-2 text-right font-black font-mono text-blue-700 text-sm" x-text="'₦' + grnFormTotal.toLocaleString('en',{minimumFractionDigits:2})"></td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold mb-1.5 block text-slate-500">Notes</label>
                                    <textarea x-model="lubeGrnForm.notes" rows="2" placeholder="Optional notes..." class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all resize-none"></textarea>
                                </div>
                                <div class="flex gap-3 pt-1">
                                    <button @click="lubeGrnModal=false" class="flex-1 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 font-semibold rounded-xl text-sm hover:bg-slate-200 transition-all">Cancel</button>
                                    <button @click="saveLubeGrn()" :disabled="saving" class="flex-1 py-2.5 bg-gradient-to-r from-blue-500 to-indigo-600 text-white font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50 text-sm">Save GRN</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ TAB 6: EXPENSES (Ledger System) ═══ -->
                    <div x-show="currentTab==='expenses'" x-transition>
                        <div class="space-y-4">

                            <!-- Summary Cards -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div class="glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                                    <div class="flex items-center gap-3 mb-2">
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-rose-500 to-pink-600 flex items-center justify-center shadow-lg shadow-rose-500/30"><i data-lucide="receipt" class="w-5 h-5 text-white"></i></div>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase">Total Expenses</p>
                                            <p class="text-xl font-black text-red-600" x-text="fmt(totalExpenses)"></p>
                                        </div>
                                    </div>
                                    <p class="text-[10px] text-slate-400" x-text="expenseCategories.length + ' categories'"></p>
                                </div>
                                <div class="glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                                    <div class="flex items-center gap-3 mb-2">
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center shadow-lg shadow-red-500/30"><i data-lucide="trending-up" class="w-5 h-5 text-white"></i></div>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase">Total Debits (DR)</p>
                                            <p class="text-xl font-black text-red-600" x-text="fmt(expenseCategories.reduce((s,c) => s + (c.ledger||[]).reduce((t,e) => t + (parseFloat(e.debit)||0), 0), 0))"></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                                    <div class="flex items-center gap-3 mb-2">
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center shadow-lg shadow-emerald-500/30"><i data-lucide="trending-down" class="w-5 h-5 text-white"></i></div>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase">Total Credits (CR)</p>
                                            <p class="text-xl font-black text-emerald-600" x-text="fmt(expenseCategories.reduce((s,c) => s + (c.ledger||[]).reduce((t,e) => t + (parseFloat(e.credit)||0), 0), 0))"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Main 2-Column Layout -->
                            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">

                                <!-- Left: Expense Categories List -->
                                <div class="lg:col-span-4">
                                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                        <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-rose-500/10 to-transparent">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-rose-500 to-pink-600 flex items-center justify-center shadow"><i data-lucide="folder" class="w-4 h-4 text-white"></i></div>
                                                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Expense Categories</h3>
                                            </div>
                                        </div>

                                        <!-- Create Category Form -->
                                        <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800 bg-rose-50/50 dark:bg-rose-900/10">
                                            <div class="flex gap-2">
                                                <input type="text" x-model="newExpenseCatName" @keydown.enter="createExpenseCategory()" placeholder="e.g. Admin, Operation, Drawings..." class="flex-1 px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-xs focus:ring-2 focus:ring-rose-400 focus:border-rose-400">
                                                <button @click="createExpenseCategory()" :disabled="saving" class="px-3 py-2 bg-gradient-to-r from-rose-500 to-pink-600 text-white text-xs font-bold rounded-xl shadow hover:shadow-lg hover:scale-[1.02] transition-all whitespace-nowrap">
                                                    <i data-lucide="plus" class="w-3.5 h-3.5 inline"></i> Add
                                                </button>
                                            </div>
                                            <p class="text-[9px] text-slate-400 mt-1.5">Create categories like <strong>Admin</strong>, <strong>Operation</strong>, <strong>Drawings</strong>, <strong>Maintenance</strong>, etc.</p>
                                        </div>

                                        <!-- Category List -->
                                        <div class="max-h-[420px] overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
                                            <template x-for="cat in expenseCategories" :key="cat.id">
                                                <div @click="activeExpenseCatId = cat.id" :class="activeExpenseCatId == cat.id ? 'bg-rose-50 dark:bg-rose-900/20 border-l-4 border-rose-500' : 'hover:bg-slate-50 dark:hover:bg-slate-800/30 border-l-4 border-transparent'" class="px-4 py-3 cursor-pointer transition-all group">
                                                    <div class="flex items-center justify-between">
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-sm font-bold text-slate-800 dark:text-white truncate" x-text="cat.category_name"></p>
                                                            <p class="text-[10px] text-slate-400 mt-0.5" x-text="(cat.ledger||[]).length + ' entries'"></p>
                                                        </div>
                                                        <div class="flex items-center gap-2 flex-shrink-0">
                                                            <span class="bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 px-2 py-0.5 rounded-full text-[10px] font-black" x-text="fmt(expenseCatBalance(cat))"></span>
                                                            <button @click.stop="renameExpenseCategory(cat.id)" class="p-1 text-slate-300 hover:text-blue-500 opacity-0 group-hover:opacity-100 transition-all rounded" title="Rename Category">
                                                                <i data-lucide="pencil" class="w-3 h-3"></i>
                                                            </button>
                                                            <button @click.stop="deleteExpenseCategory(cat.id)" class="p-1 text-slate-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all rounded" title="Delete Category">
                                                                <i data-lucide="trash-2" class="w-3 h-3"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                            <div x-show="expenseCategories.length === 0" class="px-4 py-8 text-center">
                                                <i data-lucide="folder-plus" class="w-10 h-10 text-slate-200 dark:text-slate-700 mx-auto mb-2"></i>
                                                <p class="text-xs text-slate-400">No expense categories yet</p>
                                                <p class="text-[10px] text-slate-300">Create one above (e.g. Admin, Operation)</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right: Expense Ledger Detail -->
                                <div class="lg:col-span-8">
                                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">

                                        <!-- Ledger Header -->
                                        <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-slate-100 to-transparent dark:from-slate-800/50">
                                            <template x-if="activeExpenseCat">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center gap-3">
                                                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-rose-500 to-pink-600 flex items-center justify-center text-white font-black text-sm shadow" x-text="activeExpenseCat.category_name.charAt(0).toUpperCase()"></div>
                                                        <div>
                                                            <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="activeExpenseCat.category_name + ' — Ledger'"></h3>
                                                            <p class="text-[10px] text-slate-400" x-text="(activeExpenseCat.ledger||[]).length + ' transactions'"></p>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Balance</p>
                                                        <p class="text-lg font-black text-red-600" x-text="fmt(expenseCatBalance(activeExpenseCat))"></p>
                                                    </div>
                                                </div>
                                            </template>
                                            <template x-if="!activeExpenseCat">
                                                <div class="flex items-center gap-3">
                                                    <i data-lucide="arrow-left" class="w-4 h-4 text-slate-300"></i>
                                                    <p class="text-sm text-slate-400">Select an expense category to view its ledger</p>
                                                </div>
                                            </template>
                                        </div>

                                        <!-- Ledger Table -->
                                        <template x-if="activeExpenseCat">
                                            <div>
                                                <div class="overflow-x-auto max-h-[350px] overflow-y-auto">
                                                    <table class="w-full text-sm">
                                                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                                                            <tr>
                                                                <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500 w-28">Date</th>
                                                                <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Description</th>
                                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-red-500 w-28">DR (₦)</th>
                                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-emerald-500 w-28">CR (₦)</th>
                                                                <th class="px-3 py-2.5 text-center text-xs font-bold text-slate-500 w-20">Payment</th>
                                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-slate-500 w-32">Balance (₦)</th>
                                                                <th class="px-3 py-2.5 w-10"></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <template x-for="(entry, idx) in (activeExpenseCat.ledger||[])" :key="entry.id">
                                                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors group">
                                                                    <!-- READ MODE -->
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 font-mono text-xs text-slate-600" x-text="entry.entry_date"></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 text-xs text-slate-700 dark:text-slate-300" x-text="entry.description || '—'"></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 text-right text-xs font-bold" :class="parseFloat(entry.debit) > 0 ? 'text-red-600' : 'text-slate-300'" x-text="parseFloat(entry.debit) > 0 ? fmt(entry.debit) : '—'"></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 text-right text-xs font-bold" :class="parseFloat(entry.credit) > 0 ? 'text-emerald-600' : 'text-slate-300'" x-text="parseFloat(entry.credit) > 0 ? fmt(entry.credit) : '—'"></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 text-center"><span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full" :class="entry.payment_method === 'cash' ? 'bg-blue-50 text-blue-600' : entry.payment_method === 'transfer' ? 'bg-violet-50 text-violet-600' : 'bg-slate-100 text-slate-500'" x-text="(entry.payment_method||'cash').charAt(0).toUpperCase() + (entry.payment_method||'cash').slice(1)"></span></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 text-right text-xs font-black text-red-600" x-text="(() => { let b = 0; for (let i = 0; i <= idx; i++) { b += parseFloat(activeExpenseCat.ledger[i].debit||0) - parseFloat(activeExpenseCat.ledger[i].credit||0); } return fmt(b); })()"></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-2 py-2 text-center">
                                                                            <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-all">
                                                                                <button @click="toggleEditExpenseEntry(entry)" class="p-1 text-slate-300 hover:text-blue-500 rounded" title="Edit"><i data-lucide="pencil" class="w-3 h-3"></i></button>
                                                                                <button @click="deleteExpenseEntry(entry.id)" class="p-1 text-slate-300 hover:text-red-500 rounded" title="Delete"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                                                                            </div>
                                                                        </td>
                                                                    </template>
                                                                    <!-- EDIT MODE -->
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1"><input type="date" x-model="entry._edit.entry_date" class="w-full px-1 py-1 bg-blue-50 dark:bg-slate-800 border border-blue-200 rounded text-xs"></td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1"><input type="text" x-model="entry._edit.description" class="w-full px-1 py-1 bg-blue-50 dark:bg-slate-800 border border-blue-200 rounded text-xs"></td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1"><input type="number" step="0.01" x-model="entry._edit.debit" class="w-full px-1 py-1 bg-blue-50 dark:bg-slate-800 border border-red-200 rounded text-xs text-right font-bold text-red-600"></td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1"><input type="number" step="0.01" x-model="entry._edit.credit" class="w-full px-1 py-1 bg-blue-50 dark:bg-slate-800 border border-emerald-200 rounded text-xs text-right font-bold text-emerald-600"></td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1">
                                                                            <select x-model="entry._edit.payment_method" class="w-full px-1 py-1 bg-blue-50 dark:bg-slate-800 border border-blue-200 rounded text-xs">
                                                                                <option value="cash">Cash</option><option value="transfer">Transfer</option><option value="pos">POS</option><option value="cheque">Cheque</option>
                                                                            </select>
                                                                        </td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1 text-center text-xs text-slate-400">—</td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1 text-center">
                                                                            <div class="flex items-center gap-0.5">
                                                                                <button @click="saveExpenseEntry(entry)" class="p-1 text-emerald-500 hover:text-emerald-700 rounded" title="Save"><i data-lucide="check" class="w-3.5 h-3.5"></i></button>
                                                                                <button @click="entry._editing = false" class="p-1 text-slate-400 hover:text-red-500 rounded" title="Cancel"><i data-lucide="x" class="w-3 h-3"></i></button>
                                                                            </div>
                                                                        </td>
                                                                    </template>
                                                                </tr>
                                                            </template>
                                                            <tr x-show="(activeExpenseCat.ledger||[]).length === 0">
                                                                <td colspan="7" class="px-4 py-8 text-center text-slate-400 text-xs">No ledger entries yet. Add one below.</td>
                                                            </tr>
                                                        </tbody>
                                                        <tfoot x-show="(activeExpenseCat.ledger||[]).length > 0" class="bg-slate-50 dark:bg-slate-800/50 border-t-2 border-slate-200 dark:border-slate-700">
                                                            <tr>
                                                                <td class="px-3 py-2.5 text-xs font-black text-slate-700 dark:text-white uppercase" colspan="2">Totals</td>
                                                                <td class="px-3 py-2.5 text-right font-black text-red-600" x-text="fmt((activeExpenseCat.ledger||[]).reduce((s,e) => s + (parseFloat(e.debit)||0), 0))"></td>
                                                                <td class="px-3 py-2.5 text-right font-black text-emerald-600" x-text="fmt((activeExpenseCat.ledger||[]).reduce((s,e) => s + (parseFloat(e.credit)||0), 0))"></td>
                                                                <td></td>
                                                                <td class="px-3 py-2.5 text-right font-black text-red-600" x-text="fmt(expenseCatBalance(activeExpenseCat))"></td>
                                                                <td></td>
                                                            </tr>
                                                        </tfoot>
                                                    </table>
                                                </div>

                                                <!-- Add Expense Entry Form -->
                                                <div class="px-5 py-3 border-t border-slate-200 dark:border-slate-700 bg-rose-50/30 dark:bg-rose-900/10">
                                                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-2">Add Expense Entry</p>
                                                    <div class="grid grid-cols-2 sm:grid-cols-6 gap-2 items-end">
                                                        <div>
                                                            <label class="text-[9px] font-bold text-slate-400 block mb-0.5">Date</label>
                                                            <input type="date" x-model="newExpenseLedgerEntry.entry_date" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                                        </div>
                                                        <div>
                                                            <label class="text-[9px] font-bold text-slate-400 block mb-0.5">Description</label>
                                                            <input type="text" x-model="newExpenseLedgerEntry.description" list="expenseDescList" placeholder="e.g. Office supplies" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                                            <datalist id="expenseDescList">
                                                                <template x-for="d in expenseDescriptionSummary" :key="d.description">
                                                                    <option :value="d.description"></option>
                                                                </template>
                                                            </datalist>
                                                        </div>
                                                        <div>
                                                            <label class="text-[9px] font-bold text-red-500 block mb-0.5">Debit (DR) ₦</label>
                                                            <input type="number" step="0.01" x-model="newExpenseLedgerEntry.debit" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-red-200 dark:border-red-700 rounded-lg text-xs text-right font-bold text-red-600">
                                                        </div>
                                                        <div>
                                                            <label class="text-[9px] font-bold text-emerald-500 block mb-0.5">Credit (CR) ₦</label>
                                                            <input type="number" step="0.01" x-model="newExpenseLedgerEntry.credit" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-emerald-200 dark:border-emerald-700 rounded-lg text-xs text-right font-bold text-emerald-600">
                                                        </div>
                                                        <div>
                                                            <label class="text-[9px] font-bold text-slate-400 block mb-0.5">Payment</label>
                                                            <select x-model="newExpenseLedgerEntry.payment_method" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                                                <option value="cash">Cash</option>
                                                                <option value="transfer">Transfer</option>
                                                                <option value="pos">POS</option>
                                                                <option value="cheque">Cheque</option>
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <button @click="addExpenseEntry()" :disabled="saving" class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-rose-500 to-pink-600 text-white text-xs font-bold rounded-xl shadow hover:shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50">
                                                                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Post
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>

                                        <!-- Empty state when no category selected -->
                                        <template x-if="!activeExpenseCat">
                                            <div class="px-4 py-16 text-center">
                                                <i data-lucide="file-text" class="w-12 h-12 text-slate-200 dark:text-slate-700 mx-auto mb-3"></i>
                                                <p class="text-sm text-slate-400">Select or create an expense category</p>
                                                <p class="text-[10px] text-slate-300 mt-1">Each category carries a full DR/CR ledger</p>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                            </div>

                            <!-- ═══════════ Expense Description Summary History ═══════════ -->
                            <div x-show="expenseDescriptionSummary.length > 0" class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-rose-500/10 to-transparent">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="history" class="w-4 h-4 text-rose-500"></i>
                                        <h3 class="font-bold text-slate-900 dark:text-white text-sm">Expense Summary by Description</h3>
                                        <span class="ml-auto text-[10px] text-slate-400">All categories combined — descriptions auto-summed</span>
                                    </div>
                                </div>
                                <div class="overflow-x-auto max-h-[300px] overflow-y-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                                            <tr>
                                                <th class="px-4 py-2.5 text-left text-xs font-bold text-slate-500">Description</th>
                                                <th class="px-3 py-2.5 text-center text-xs font-bold text-slate-400 w-16">#</th>
                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-red-500 w-32">Total DR (₦)</th>
                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-emerald-500 w-32">Total CR (₦)</th>
                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-slate-500 w-32">Balance (₦)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="row in expenseDescriptionSummary" :key="row.description">
                                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-rose-50/50 dark:hover:bg-rose-900/10 transition-colors">
                                                    <td class="px-4 py-2 text-xs font-semibold text-slate-700 dark:text-slate-300" x-text="row.description"></td>
                                                    <td class="px-3 py-2 text-center text-[10px] text-slate-400" x-text="row.count + 'x'"></td>
                                                    <td class="px-3 py-2 text-right text-xs font-bold text-red-600" x-text="fmt(row.totalDebit)"></td>
                                                    <td class="px-3 py-2 text-right text-xs font-bold text-emerald-600" x-text="fmt(row.totalCredit)"></td>
                                                    <td class="px-3 py-2 text-right text-xs font-black" :class="(row.totalDebit - row.totalCredit) > 0 ? 'text-red-600' : 'text-emerald-600'" x-text="fmt(row.totalDebit - row.totalCredit)"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ═══ TAB 7: DEBTORS (Ledger System) ═══ -->
                    <div x-show="currentTab==='debtors'" x-transition>
                        <div class="space-y-4">

                            <!-- Summary Cards -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div class="glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                                    <div class="flex items-center gap-3 mb-2">
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30"><i data-lucide="users" class="w-5 h-5 text-white"></i></div>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase">Total Outstanding</p>
                                            <p class="text-xl font-black text-amber-600" x-text="fmt(totalDebtors)"></p>
                                        </div>
                                    </div>
                                    <p class="text-[10px] text-slate-400" x-text="debtorAccounts.length + ' accounts'"></p>
                                </div>
                                <div class="glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                                    <div class="flex items-center gap-3 mb-2">
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center shadow-lg shadow-red-500/30"><i data-lucide="trending-up" class="w-5 h-5 text-white"></i></div>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase">Total Debits (DR)</p>
                                            <p class="text-xl font-black text-red-600" x-text="fmt(debtorAccounts.reduce((s,a) => s + (a.ledger||[]).reduce((t,e) => t + (parseFloat(e.debit)||0), 0), 0))"></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="glass-card rounded-2xl p-5 border border-slate-200/60 dark:border-slate-700/60 shadow-lg">
                                    <div class="flex items-center gap-3 mb-2">
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center shadow-lg shadow-emerald-500/30"><i data-lucide="trending-down" class="w-5 h-5 text-white"></i></div>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase">Total Credits (CR)</p>
                                            <p class="text-xl font-black text-emerald-600" x-text="fmt(debtorAccounts.reduce((s,a) => s + (a.ledger||[]).reduce((t,e) => t + (parseFloat(e.credit)||0), 0), 0))"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Main 2-Column Layout -->
                            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">

                                <!-- Left: Debtor Accounts List -->
                                <div class="lg:col-span-4">
                                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                        <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 to-transparent">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow"><i data-lucide="book-open" class="w-4 h-4 text-white"></i></div>
                                                <h3 class="font-bold text-slate-900 dark:text-white text-sm">Debtor Accounts</h3>
                                            </div>
                                        </div>

                                        <!-- Create Account Form -->
                                        <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800 bg-amber-50/50 dark:bg-amber-900/10">
                                            <div class="flex gap-2">
                                                <input type="text" x-model="newDebtorName" @keydown.enter="createDebtorAccount()" placeholder="New debtor name..." class="flex-1 px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-xs focus:ring-2 focus:ring-amber-400 focus:border-amber-400">
                                                <button @click="createDebtorAccount()" :disabled="saving" class="px-3 py-2 bg-gradient-to-r from-amber-500 to-orange-600 text-white text-xs font-bold rounded-xl shadow hover:shadow-lg hover:scale-[1.02] transition-all whitespace-nowrap">
                                                    <i data-lucide="plus" class="w-3.5 h-3.5 inline"></i> Add
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Account List -->
                                        <div class="max-h-[420px] overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
                                            <template x-for="acct in debtorAccounts" :key="acct.id">
                                                <div @click="activeDebtorId = acct.id" :class="activeDebtorId == acct.id ? 'bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500' : 'hover:bg-slate-50 dark:hover:bg-slate-800/30 border-l-4 border-transparent'" class="px-4 py-3 cursor-pointer transition-all group">
                                                    <div class="flex items-center justify-between">
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-sm font-bold text-slate-800 dark:text-white truncate" x-text="acct.customer_name"></p>
                                                            <p class="text-[10px] text-slate-400 mt-0.5" x-text="(acct.ledger||[]).length + ' entries'"></p>
                                                        </div>
                                                        <div class="flex items-center gap-2 flex-shrink-0">
                                                            <span :class="debtorBalance(acct) > 0 ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' : debtorBalance(acct) < 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-slate-100 text-slate-500'" class="px-2 py-0.5 rounded-full text-[10px] font-black" x-text="fmt(Math.abs(debtorBalance(acct)))"></span>
                                                            <button @click.stop="renameDebtorAccount(acct.id)" class="p-1 text-slate-300 hover:text-blue-500 opacity-0 group-hover:opacity-100 transition-all rounded" title="Rename Account">
                                                                <i data-lucide="pencil" class="w-3 h-3"></i>
                                                            </button>
                                                            <button @click.stop="deleteDebtorAccount(acct.id)" class="p-1 text-slate-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all rounded" title="Delete Account">
                                                                <i data-lucide="trash-2" class="w-3 h-3"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                            <div x-show="debtorAccounts.length === 0" class="px-4 py-8 text-center">
                                                <i data-lucide="user-plus" class="w-10 h-10 text-slate-200 dark:text-slate-700 mx-auto mb-2"></i>
                                                <p class="text-xs text-slate-400">No debtor accounts yet</p>
                                                <p class="text-[10px] text-slate-300">Create one above</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right: Ledger Detail -->
                                <div class="lg:col-span-8">
                                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">

                                        <!-- Ledger Header -->
                                        <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-slate-100 to-transparent dark:from-slate-800/50">
                                            <template x-if="activeDebtor">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center gap-3">
                                                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-white font-black text-sm shadow" x-text="activeDebtor.customer_name.charAt(0).toUpperCase()"></div>
                                                        <div>
                                                            <h3 class="font-bold text-slate-900 dark:text-white text-sm" x-text="activeDebtor.customer_name + ' — Ledger'"></h3>
                                                            <p class="text-[10px] text-slate-400" x-text="(activeDebtor.ledger||[]).length + ' transactions'"></p>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Balance</p>
                                                        <p :class="debtorBalance(activeDebtor) > 0 ? 'text-red-600' : 'text-emerald-600'" class="text-lg font-black" x-text="fmt(debtorBalance(activeDebtor))"></p>
                                                    </div>
                                                </div>
                                            </template>
                                            <template x-if="!activeDebtor">
                                                <div class="flex items-center gap-3">
                                                    <i data-lucide="arrow-left" class="w-4 h-4 text-slate-300"></i>
                                                    <p class="text-sm text-slate-400">Select a debtor account to view their ledger</p>
                                                </div>
                                            </template>
                                        </div>

                                        <!-- Ledger Table -->
                                        <template x-if="activeDebtor">
                                            <div>
                                                <div class="overflow-x-auto max-h-[350px] overflow-y-auto">
                                                    <table class="w-full text-sm">
                                                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                                                            <tr>
                                                                <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500 w-28">Date</th>
                                                                <th class="px-3 py-2.5 text-left text-xs font-bold text-slate-500">Description</th>
                                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-red-500 w-28">DR (₦)</th>
                                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-emerald-500 w-28">CR (₦)</th>
                                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-slate-500 w-32">Balance (₦)</th>
                                                                <th class="px-3 py-2.5 w-10"></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <template x-for="(entry, idx) in (activeDebtor.ledger||[])" :key="entry.id">
                                                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors group">
                                                                    <!-- READ MODE -->
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 font-mono text-xs text-slate-600" x-text="entry.entry_date"></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 text-xs text-slate-700 dark:text-slate-300" x-text="entry.description || '—'"></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 text-right text-xs font-bold" :class="parseFloat(entry.debit) > 0 ? 'text-red-600' : 'text-slate-300'" x-text="parseFloat(entry.debit) > 0 ? fmt(entry.debit) : '—'"></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 text-right text-xs font-bold" :class="parseFloat(entry.credit) > 0 ? 'text-emerald-600' : 'text-slate-300'" x-text="parseFloat(entry.credit) > 0 ? fmt(entry.credit) : '—'"></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-3 py-2 text-right text-xs font-black" :class="(() => { let b = 0; for (let i = 0; i <= idx; i++) { b += parseFloat(activeDebtor.ledger[i].debit||0) - parseFloat(activeDebtor.ledger[i].credit||0); } return b > 0 ? 'text-red-600' : 'text-emerald-600'; })()" x-text="(() => { let b = 0; for (let i = 0; i <= idx; i++) { b += parseFloat(activeDebtor.ledger[i].debit||0) - parseFloat(activeDebtor.ledger[i].credit||0); } return fmt(b); })()"></td>
                                                                    </template>
                                                                    <template x-if="!entry._editing">
                                                                        <td class="px-2 py-2 text-center">
                                                                            <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-all">
                                                                                <button @click="toggleEditDebtorEntry(entry)" class="p-1 text-slate-300 hover:text-blue-500 rounded" title="Edit"><i data-lucide="pencil" class="w-3 h-3"></i></button>
                                                                                <button @click="deleteDebtorEntry(entry.id)" class="p-1 text-slate-300 hover:text-red-500 rounded" title="Delete"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                                                                            </div>
                                                                        </td>
                                                                    </template>
                                                                    <!-- EDIT MODE -->
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1"><input type="date" x-model="entry._edit.entry_date" class="w-full px-1 py-1 bg-amber-50 dark:bg-slate-800 border border-amber-200 rounded text-xs"></td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1"><input type="text" x-model="entry._edit.description" class="w-full px-1 py-1 bg-amber-50 dark:bg-slate-800 border border-amber-200 rounded text-xs"></td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1"><input type="number" step="0.01" x-model="entry._edit.debit" class="w-full px-1 py-1 bg-amber-50 dark:bg-slate-800 border border-red-200 rounded text-xs text-right font-bold text-red-600"></td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1"><input type="number" step="0.01" x-model="entry._edit.credit" class="w-full px-1 py-1 bg-amber-50 dark:bg-slate-800 border border-emerald-200 rounded text-xs text-right font-bold text-emerald-600"></td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1 text-center text-xs text-slate-400">—</td>
                                                                    </template>
                                                                    <template x-if="entry._editing">
                                                                        <td class="px-1 py-1 text-center">
                                                                            <div class="flex items-center gap-0.5">
                                                                                <button @click="saveDebtorEntry(entry)" class="p-1 text-emerald-500 hover:text-emerald-700 rounded" title="Save"><i data-lucide="check" class="w-3.5 h-3.5"></i></button>
                                                                                <button @click="entry._editing = false" class="p-1 text-slate-400 hover:text-red-500 rounded" title="Cancel"><i data-lucide="x" class="w-3 h-3"></i></button>
                                                                            </div>
                                                                        </td>
                                                                    </template>
                                                                </tr>
                                                            </template>
                                                            <tr x-show="(activeDebtor.ledger||[]).length === 0">
                                                                <td colspan="6" class="px-4 py-8 text-center text-slate-400 text-xs">No ledger entries yet. Add one below.</td>
                                                            </tr>
                                                        </tbody>
                                                        <tfoot x-show="(activeDebtor.ledger||[]).length > 0" class="bg-slate-50 dark:bg-slate-800/50 border-t-2 border-slate-200 dark:border-slate-700">
                                                            <tr>
                                                                <td class="px-3 py-2.5 text-xs font-black text-slate-700 dark:text-white uppercase" colspan="2">Totals</td>
                                                                <td class="px-3 py-2.5 text-right font-black text-red-600" x-text="fmt((activeDebtor.ledger||[]).reduce((s,e) => s + (parseFloat(e.debit)||0), 0))"></td>
                                                                <td class="px-3 py-2.5 text-right font-black text-emerald-600" x-text="fmt((activeDebtor.ledger||[]).reduce((s,e) => s + (parseFloat(e.credit)||0), 0))"></td>
                                                                <td class="px-3 py-2.5 text-right font-black" :class="debtorBalance(activeDebtor) > 0 ? 'text-red-600' : 'text-emerald-600'" x-text="fmt(debtorBalance(activeDebtor))"></td>
                                                                <td></td>
                                                            </tr>
                                                        </tfoot>
                                                    </table>
                                                </div>

                                                <!-- Add Ledger Entry Form -->
                                                <div class="px-5 py-3 border-t border-slate-200 dark:border-slate-700 bg-amber-50/30 dark:bg-amber-900/10">
                                                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-2">Add Ledger Entry</p>
                                                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 items-end">
                                                        <div>
                                                            <label class="text-[9px] font-bold text-slate-400 block mb-0.5">Date</label>
                                                            <input type="date" x-model="newLedgerEntry.entry_date" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                                        </div>
                                                        <div>
                                                            <label class="text-[9px] font-bold text-slate-400 block mb-0.5">Description</label>
                                                            <input type="text" x-model="newLedgerEntry.description" list="debtorDescList" placeholder="e.g. Credit sale PMS" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                                                            <datalist id="debtorDescList">
                                                                <template x-for="d in debtorDescriptionSummary" :key="d.description">
                                                                    <option :value="d.description"></option>
                                                                </template>
                                                            </datalist>
                                                        </div>
                                                        <div>
                                                            <label class="text-[9px] font-bold text-red-500 block mb-0.5">Debit (DR) ₦</label>
                                                            <input type="number" step="0.01" x-model="newLedgerEntry.debit" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-red-200 dark:border-red-700 rounded-lg text-xs text-right font-bold text-red-600">
                                                        </div>
                                                        <div>
                                                            <label class="text-[9px] font-bold text-emerald-500 block mb-0.5">Credit (CR) ₦</label>
                                                            <input type="number" step="0.01" x-model="newLedgerEntry.credit" class="w-full px-2 py-1.5 bg-white dark:bg-slate-900 border border-emerald-200 dark:border-emerald-700 rounded-lg text-xs text-right font-bold text-emerald-600">
                                                        </div>
                                                        <div>
                                                            <button @click="addDebtorEntry()" :disabled="saving" class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white text-xs font-bold rounded-xl shadow hover:shadow-lg hover:scale-[1.02] transition-all disabled:opacity-50">
                                                                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Post Entry
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>

                                        <!-- Empty state when no debtor selected -->
                                        <template x-if="!activeDebtor">
                                            <div class="px-4 py-16 text-center">
                                                <i data-lucide="file-text" class="w-12 h-12 text-slate-200 dark:text-slate-700 mx-auto mb-3"></i>
                                                <p class="text-sm text-slate-400">Select or create a debtor account</p>
                                                <p class="text-[10px] text-slate-300 mt-1">Each account carries a full DR/CR ledger</p>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                            </div>

                            <!-- ═══════════ Debtor Description Summary History ═══════════ -->
                            <div x-show="debtorDescriptionSummary.length > 0" class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-500/10 to-transparent">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="history" class="w-4 h-4 text-amber-500"></i>
                                        <h3 class="font-bold text-slate-900 dark:text-white text-sm">Receivable Summary by Description</h3>
                                        <span class="ml-auto text-[10px] text-slate-400">All accounts combined — descriptions auto-summed</span>
                                    </div>
                                </div>
                                <div class="overflow-x-auto max-h-[300px] overflow-y-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0">
                                            <tr>
                                                <th class="px-4 py-2.5 text-left text-xs font-bold text-slate-500">Description</th>
                                                <th class="px-3 py-2.5 text-center text-xs font-bold text-slate-400 w-16">#</th>
                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-red-500 w-32">Total DR (₦)</th>
                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-emerald-500 w-32">Total CR (₦)</th>
                                                <th class="px-3 py-2.5 text-right text-xs font-bold text-slate-500 w-32">Balance (₦)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="row in debtorDescriptionSummary" :key="row.description">
                                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-amber-50/50 dark:hover:bg-amber-900/10 transition-colors">
                                                    <td class="px-4 py-2 text-xs font-semibold text-slate-700 dark:text-slate-300" x-text="row.description"></td>
                                                    <td class="px-3 py-2 text-center text-[10px] text-slate-400" x-text="row.count + 'x'"></td>
                                                    <td class="px-3 py-2 text-right text-xs font-bold text-red-600" x-text="fmt(row.totalDebit)"></td>
                                                    <td class="px-3 py-2 text-right text-xs font-bold text-emerald-600" x-text="fmt(row.totalCredit)"></td>
                                                    <td class="px-3 py-2 text-right text-xs font-black" :class="(row.totalDebit - row.totalCredit) > 0 ? 'text-red-600' : 'text-emerald-600'" x-text="fmt(row.totalDebit - row.totalCredit)"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>




                    <!-- â•â•â• TAB: REPORT â•â•â• -->

                    <div x-show="currentTab==='documents'" x-transition>
                        <div class="max-w-4xl mx-auto space-y-5">

                            <!-- Storage Usage Card -->
                            <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-cyan-500/10 to-transparent">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h3 class="font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                                <i data-lucide="hard-drive" class="w-5 h-5 text-cyan-500"></i>
                                                Enterprise Storage
                                            </h3>
                                            <p class="text-xs text-slate-500 mt-0.5">1 GB allocated &bull; 2MB max per file</p>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-lg font-black font-mono" :class="docStorage.percent > 80 ? 'text-red-600' : docStorage.percent > 50 ? 'text-amber-600' : 'text-cyan-600'" x-text="formatFileSize(docStorage.used)"></span>
                                            <span class="text-xs text-slate-400"> / </span>
                                            <span class="text-sm font-semibold text-slate-500" x-text="formatFileSize(docStorage.limit)"></span>
                                            <div class="text-xs text-slate-400 mt-0.5" x-text="docStorage.count + ' files uploaded'"></div>
                                        </div>
                                    </div>
                                    <div class="mt-3 h-3 bg-slate-200/80 dark:bg-slate-700 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-500"
                                             :class="docStorage.percent > 80 ? 'bg-gradient-to-r from-red-500 to-red-400' : docStorage.percent > 50 ? 'bg-gradient-to-r from-amber-500 to-amber-400' : 'bg-gradient-to-r from-cyan-500 to-blue-400'"
                                             :style="'width:' + Math.min(docStorage.percent, 100) + '%'"></div>
                                    </div>
                                    <div class="flex justify-between mt-1">
                                        <span class="text-[10px] font-mono text-slate-400" x-text="docStorage.percent.toFixed(1) + '% used'"></span>
                                        <span class="text-[10px] font-mono text-slate-400" x-text="formatFileSize(docStorage.limit - docStorage.used) + ' remaining'"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Upload Zone -->
                            <div class="glass-card rounded-2xl border-2 border-dashed border-slate-300 dark:border-slate-600 hover:border-cyan-400 dark:hover:border-cyan-500 transition-colors overflow-hidden"
                                 @dragover.prevent="$el.classList.add('border-cyan-400','bg-cyan-50/30')"
                                 @dragleave.prevent="$el.classList.remove('border-cyan-400','bg-cyan-50/30')"
                                 @drop.prevent="$el.classList.remove('border-cyan-400','bg-cyan-50/30'); uploadDocument($event)">
                                <label class="flex flex-col items-center justify-center py-8 cursor-pointer">
                                    <template x-if="!docUploading">
                                        <div class="text-center">
                                            <i data-lucide="cloud-upload" class="w-10 h-10 text-slate-400 mx-auto mb-2"></i>
                                            <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">Drag & drop files or <span class="text-cyan-500 underline">browse</span></p>
                                            <p class="text-xs text-slate-400 mt-1">Images, PDFs, Docs, Spreadsheets &bull; Max 2MB each</p>
                                        </div>
                                    </template>
                                    <template x-if="docUploading">
                                        <div class="text-center">
                                            <svg class="animate-spin h-8 w-8 text-cyan-500 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                            <p class="text-sm font-semibold text-cyan-600">Uploading...</p>
                                        </div>
                                    </template>
                                    <input type="file" class="hidden" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" @change="uploadDocument($event)">
                                </label>
                            </div>

                            <!-- Filter + Document List -->
                            <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">
                                <div class="px-6 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                                    <h4 class="font-bold text-sm text-slate-700 dark:text-slate-200">Uploaded Documents</h4>
                                    <div class="flex gap-1.5">
                                        <button @click="docFilter='current'; loadDocuments()" :class="docFilter==='current' ? 'bg-cyan-100 text-cyan-700 border-cyan-300' : 'text-slate-500 border-transparent hover:bg-slate-100'" class="px-3 py-1 text-xs font-semibold rounded-lg border transition-all">This Session</button>
                                        <button @click="docFilter='all'; loadDocuments()" :class="docFilter==='all' ? 'bg-cyan-100 text-cyan-700 border-cyan-300' : 'text-slate-500 border-transparent hover:bg-slate-100'" class="px-3 py-1 text-xs font-semibold rounded-lg border transition-all">All Sessions</button>
                                    </div>
                                </div>
                                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <template x-if="documents.length === 0">
                                        <div class="p-8 text-center">
                                            <i data-lucide="folder-open" class="w-12 h-12 text-slate-300 mx-auto mb-3"></i>
                                            <p class="text-sm text-slate-400 font-medium">No documents uploaded yet</p>
                                            <p class="text-xs text-slate-400 mt-1">Drag files above or click to browse</p>
                                        </div>
                                    </template>
                                    <template x-for="doc in documents" :key="doc.id">
                                        <div class="px-5 py-3 flex items-center gap-4 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                            <div class="w-10 h-10 rounded-lg overflow-hidden flex-shrink-0 bg-slate-100 dark:bg-slate-700 flex items-center justify-center">
                                                <template x-if="isImageFile(doc)">
                                                    <img :src="doc.file_path" class="w-full h-full object-cover" :alt="doc.doc_label">
                                                </template>
                                                <template x-if="!isImageFile(doc)">
                                                    <i data-lucide="file-text" class="w-5 h-5 text-slate-400"></i>
                                                </template>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <template x-if="docEditingId !== doc.id">
                                                    <div>
                                                        <p class="text-sm font-semibold text-slate-800 dark:text-slate-100 truncate" x-text="doc.doc_label || doc.original_name"></p>
                                                        <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                                            <template x-if="doc.file_size > 0">
                                                                <span class="text-[10px] text-slate-400 font-mono" x-text="formatFileSize(doc.file_size)"></span>
                                                            </template>
                                                            <template x-if="doc.created_at">
                                                                <span class="text-[10px] text-slate-400">
                                                                    <span class="text-slate-300">&bull;</span>
                                                                    <span x-text="new Date(doc.created_at).toLocaleDateString()"></span>
                                                                </span>
                                                            </template>
                                                            <template x-if="doc._reference">
                                                                <span class="text-[10px] text-cyan-600 bg-cyan-50 dark:bg-cyan-900/30 dark:text-cyan-400 px-1.5 py-0.5 rounded font-semibold" x-text="doc._reference"></span>
                                                            </template>
                                                            <template x-if="doc._system">
                                                                <span class="text-[10px] text-amber-600 bg-amber-50 dark:bg-amber-900/30 dark:text-amber-400 px-1.5 py-0.5 rounded font-semibold">System Sales</span>
                                                            </template>
                                                            <template x-if="doc.outlet_name">
                                                                <span class="text-[10px] text-slate-400">
                                                                    <span class="text-slate-300">&bull;</span>
                                                                    <span x-text="doc.outlet_name"></span>
                                                                </span>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>
                                                <template x-if="docEditingId === doc.id">
                                                    <div class="flex items-center gap-2">
                                                        <input type="text" x-model="docEditLabel" @keydown.enter="renameDocument(doc)" @keydown.escape="docEditingId=null" class="flex-1 px-2 py-1 text-sm border border-cyan-300 rounded-lg focus:ring-2 focus:ring-cyan-400/30" x-ref="docEditInput">
                                                        <button @click="renameDocument(doc)" class="px-2 py-1 text-xs font-semibold bg-cyan-500 text-white rounded-lg hover:bg-cyan-600">Save</button>
                                                        <button @click="docEditingId=null" class="px-2 py-1 text-xs text-slate-500 hover:text-slate-700">Cancel</button>
                                                    </div>
                                                </template>
                                            </div>
                                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                                <a :href="doc.file_path" target="_blank" class="p-1.5 rounded-lg text-slate-400 hover:text-blue-500 hover:bg-blue-50 transition-all" title="View / Download">
                                                    <i data-lucide="external-link" class="w-4 h-4"></i>
                                                </a>
                                                <template x-if="!doc._system">
                                                    <button @click="docEditingId = doc.id; docEditLabel = doc.doc_label || doc.original_name; $nextTick(() => $refs.docEditInput?.focus())" class="p-1.5 rounded-lg text-slate-400 hover:text-amber-500 hover:bg-amber-50 transition-all" title="Rename">
                                                        <i data-lucide="pencil" class="w-4 h-4"></i>
                                                    </button>
                                                </template>
                                                <template x-if="!doc._system">
                                                    <button @click="deleteDocument(doc)" class="p-1.5 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 transition-all" title="Delete">
                                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div x-show="currentTab==='report'" x-transition>

                        <div class="space-y-6">



                            <!-- Header + Action Bar -->

                            <div class="flex items-center justify-between flex-wrap gap-3">

                                <div>

                                    <h2 class="text-lg font-black text-slate-900 dark:text-white">Audit Close-Out Report</h2>

                                    <p class="text-xs text-slate-500" x-text="(sessionData?.session?.outlet_name || 'Station') + ' Â· ' + (sessionData?.session?.date_from || '') + ' to ' + (sessionData?.session?.date_to || '')"></p>

                                </div>

                                <div class="flex items-center gap-2">

                                    <button @click="previewReportPDF()"

                                        class="flex items-center gap-2 px-4 py-2 rounded-xl bg-violet-600 hover:bg-violet-700 text-white text-xs font-bold shadow-lg shadow-violet-500/30 transition-all hover:-translate-y-0.5">

                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i> Preview PDF

                                    </button>

                                    <button @click="downloadReportPDF()"

                                        class="flex items-center gap-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-lg shadow-indigo-500/30 transition-all hover:-translate-y-0.5">

                                        <i data-lucide="download" class="w-3.5 h-3.5"></i> Download PDF

                                    </button>

                                </div>

                            </div>



                            <!-- Two-column: Cover Editor + Live Summary -->

                            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">



                                <!-- COVER PAGE EDITOR -->

                                <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden">

                                    <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-violet-500/10 to-transparent flex items-center gap-3">

                                        <div class="w-7 h-7 rounded-lg bg-violet-100 dark:bg-violet-900/40 flex items-center justify-center">

                                            <i data-lucide="file-edit" class="w-3.5 h-3.5 text-violet-600 dark:text-violet-400"></i>

                                        </div>

                                        <h3 class="font-bold text-sm text-slate-900 dark:text-white">Cover Page Editor</h3>

                                        <span class="ml-auto text-[10px] bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300 px-2 py-0.5 rounded-full font-semibold">EDITABLE</span>

                                    </div>

                                    <div class="p-5 space-y-4">

                                        <div>

                                            <label class="text-[10px] font-bold uppercase text-slate-500 tracking-wide block mb-1.5">Report Title</label>

                                            <input type="text" x-model="reportCover.title"

                                                class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-semibold text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-violet-400"

                                                placeholder="Station Audit Close-Out Report">

                                        </div>

                                        <div class="grid grid-cols-2 gap-3">

                                            <div>

                                                <label class="text-[10px] font-bold uppercase text-slate-500 tracking-wide block mb-1.5">Prepared By</label>

                                                <input type="text" x-model="reportCover.preparedBy"

                                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-violet-400"

                                                    placeholder="Auditor Name">

                                            </div>

                                            <div>

                                                <label class="text-[10px] font-bold uppercase text-slate-500 tracking-wide block mb-1.5">Reviewed By</label>

                                                <input type="text" x-model="reportCover.reviewedBy"

                                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-violet-400"

                                                    placeholder="Manager / Supervisor">

                                            </div>

                                        </div>

                                        <div>

                                            <label class="text-[10px] font-bold uppercase text-slate-500 tracking-wide block mb-1.5">Reporting Period <span class="normal-case font-normal text-slate-400">(auto from session dates)</span></label>

                                            <input type="text" x-model="reportCover.reportingPeriod"

                                                class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-violet-400"

                                                :placeholder="(sessionData?.session?.date_from || '') + ' to ' + (sessionData?.session?.date_to || '')">

                                        </div>

                                        <div>

                                            <label class="text-[10px] font-bold uppercase text-slate-500 tracking-wide block mb-1.5">Cover Notes <span class="normal-case text-slate-400 font-normal">(optional)</span></label>

                                            <textarea x-model="reportCover.notes" rows="3"

                                                class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-violet-400 resize-none"

                                                placeholder="Any additional notes or disclaimers for the cover pageâ€¦"></textarea>

                                        </div>

                                        <!-- Live cover preview strip -->

                                        <div class="rounded-xl overflow-hidden border border-violet-200 dark:border-violet-800/50">

                                            <div class="bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 p-4">

                                                <div class="inline-block bg-white/10 border border-white/20 rounded-full px-3 py-0.5 text-[9px] font-bold text-white/80 uppercase tracking-widest mb-2"

                                                    x-text="(window.__SA_COMPANY || 'MIAUDITOPS') + ' Â· CONFIDENTIAL'"></div>

                                                <div class="text-sm font-black text-white leading-tight" x-text="reportCover.title || 'Station Audit Close-Out Report'"></div>

                                                <div class="text-[10px] text-white/60 mt-0.5" x-text="sessionData?.session?.outlet_name || 'Station'"></div>

                                                <div class="h-0.5 rounded-full bg-gradient-to-r from-amber-400 to-amber-500 mt-3"></div>

                                                <div class="flex items-center justify-between mt-2">

                                                    <span class="text-[9px] text-white/50" x-text="'Period: ' + (reportCover.reportingPeriod || ((sessionData?.session?.date_from||'') + ' â€“ ' + (sessionData?.session?.date_to||'')))"></span>

                                                    <span class="text-[9px] text-white/50" x-text="'By: ' + (reportCover.preparedBy || (window.__SA_USER?.name || 'Auditor'))"></span>

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>



                                <!-- LIVE DATA SUMMARY -->

                                <div class="space-y-3">



                                    <!-- System Sales -->

                                    <div class="glass-card rounded-xl border border-blue-200/60 dark:border-blue-800/40 overflow-hidden">

                                        <div class="flex items-center justify-between px-4 py-2.5 bg-gradient-to-r from-blue-500/10 to-transparent border-b border-blue-100 dark:border-blue-900/30">

                                            <div class="flex items-center gap-2">

                                                <div class="w-5 h-5 rounded-md bg-blue-500/20 flex items-center justify-center"><i data-lucide="credit-card" class="w-3 h-3 text-blue-600"></i></div>

                                                <span class="text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide">Payment/Declared</span>

                                            </div>

                                            <span class="text-sm font-black text-blue-700 dark:text-blue-300" x-text="fmt(systemSalesTotal)"></span>

                                        </div>

                                        <div class="grid grid-cols-4 gap-0 divide-x divide-slate-100 dark:divide-slate-800">

                                            <div class="px-3 py-2 text-center"><p class="text-[9px] text-slate-400 font-semibold">POS</p><p class="text-[11px] font-bold text-slate-700 dark:text-slate-200" x-text="fmt(systemSales.pos_amount)"></p></div>

                                            <div class="px-3 py-2 text-center"><p class="text-[9px] text-slate-400 font-semibold">Cash</p><p class="text-[11px] font-bold text-slate-700 dark:text-slate-200" x-text="fmt(systemSales.cash_amount)"></p></div>

                                            <div class="px-3 py-2 text-center"><p class="text-[9px] text-slate-400 font-semibold">Transfer</p><p class="text-[11px] font-bold text-slate-700 dark:text-slate-200" x-text="fmt(systemSales.transfer_amount)"></p></div>

                                            <div class="px-3 py-2 text-center"><p class="text-[9px] text-slate-400 font-semibold">Teller</p><p class="text-[11px] font-bold text-slate-700 dark:text-slate-200" x-text="fmt(systemSales.teller_amount)"></p></div>

                                        </div>

                                    </div>



                                    <!-- Pump Sales -->

                                    <div class="glass-card rounded-xl border border-orange-200/60 dark:border-orange-800/40 overflow-hidden">

                                        <div class="flex items-center justify-between px-4 py-2.5 bg-gradient-to-r from-orange-500/10 to-transparent border-b border-orange-100 dark:border-orange-900/30">

                                            <div class="flex items-center gap-2">

                                                <div class="w-5 h-5 rounded-md bg-orange-500/20 flex items-center justify-center"><i data-lucide="fuel" class="w-3 h-3 text-orange-600"></i></div>

                                                <span class="text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide">Pump Sales</span>

                                            </div>

                                            <div class="text-right">

                                                <span class="text-sm font-black text-orange-700 dark:text-orange-300" x-text="fmt(totalPumpSales)"></span>

                                                <span class="block text-[9px] text-slate-400 font-semibold" x-text="totalPumpLitres.toLocaleString('en',{minimumFractionDigits:2}) + ' L'"></span>

                                            </div>

                                        </div>

                                        <div class="px-4 py-2 space-y-0.5">

                                            <template x-for="pg in pumpSalesGrouped.filter(r => r.type==='subtotal')" :key="pg.product">

                                                <div class="flex justify-between items-center text-[11px]">

                                                    <span class="font-semibold text-slate-600 dark:text-slate-300" x-text="pg.product"></span>

                                                    <span class="font-bold text-orange-700 dark:text-orange-300" x-text="fmt(pg.totalAmount) + ' (' + pg.totalLitres.toLocaleString('en',{minimumFractionDigits:2}) + ' L)'"></span>

                                                </div>

                                            </template>

                                            <div x-show="pumpSalesGrouped.length === 0" class="text-[10px] text-slate-400 py-1">No pump sales data</div>

                                        </div>

                                    </div>



                                    <!-- Tank + Haulage row -->

                                    <div class="grid grid-cols-2 gap-3">

                                        <div class="glass-card rounded-xl border border-teal-200/60 dark:border-teal-800/40 overflow-hidden">

                                            <div class="px-3 py-2 bg-gradient-to-r from-teal-500/10 to-transparent border-b border-teal-100 dark:border-teal-900/30 flex items-center gap-2">

                                                <i data-lucide="gauge" class="w-3 h-3 text-teal-600"></i>

                                                <span class="text-[10px] font-bold text-slate-700 dark:text-slate-200 uppercase">Tank Dipping</span>

                                            </div>

                                            <div class="px-3 py-2 space-y-0.5">

                                                <template x-for="t in tankProductTotals" :key="t.product">

                                                    <div class="flex justify-between text-[10px]">

                                                        <span class="font-semibold text-slate-600 dark:text-slate-300" x-text="t.product"></span>

                                                        <span class="font-bold" :class="t.diff >= 0 ? 'text-teal-700 dark:text-teal-300' : 'text-red-600'" x-text="t.diff.toLocaleString('en',{minimumFractionDigits:2}) + ' L'"></span>

                                                    </div>

                                                </template>

                                                <div x-show="tankProductTotals.length === 0" class="text-[10px] text-slate-400">No data</div>

                                            </div>

                                        </div>

                                        <div class="glass-card rounded-xl border border-indigo-200/60 dark:border-indigo-800/40 overflow-hidden">

                                            <div class="px-3 py-2 bg-gradient-to-r from-indigo-500/10 to-transparent border-b border-indigo-100 dark:border-indigo-900/30 flex items-center gap-2">

                                                <i data-lucide="truck" class="w-3 h-3 text-indigo-600"></i>

                                                <span class="text-[10px] font-bold text-slate-700 dark:text-slate-200 uppercase">Haulage</span>

                                            </div>

                                            <div class="px-3 py-2 space-y-0.5">

                                                <template x-for="h in haulageByProduct" :key="h.product">

                                                    <div class="flex justify-between text-[10px]">

                                                        <span class="font-semibold text-slate-600 dark:text-slate-300" x-text="h.product"></span>

                                                        <span class="font-bold text-indigo-700 dark:text-indigo-300" x-text="h.quantity.toLocaleString('en',{minimumFractionDigits:2}) + ' L'"></span>

                                                    </div>

                                                </template>

                                                <div x-show="haulage.length === 0" class="text-[10px] text-slate-400">No data</div>

                                            </div>

                                        </div>

                                    </div>



                                    <!-- Lubricants + Expenses + Debtors -->

                                    <div class="grid grid-cols-3 gap-3">

                                        <div class="glass-card rounded-xl border border-lime-200/60 dark:border-lime-800/40 overflow-hidden text-center">

                                            <div class="px-3 py-2 bg-gradient-to-r from-lime-500/10 to-transparent border-b border-lime-100 dark:border-lime-900/30">

                                                <p class="text-[9px] font-bold uppercase text-lime-600 dark:text-lime-400">Lubricants</p>

                                            </div>

                                            <div class="py-3">

                                                <p class="text-sm font-black text-lime-700 dark:text-lime-300" x-text="fmt(lubeTotalAmount)"></p>

                                                <p class="text-[9px] text-slate-400" x-text="lubeSections.length + ' counter' + (lubeSections.length !== 1 ? 's' : '')"></p>

                                            </div>

                                        </div>

                                        <div class="glass-card rounded-xl border border-rose-200/60 dark:border-rose-800/40 overflow-hidden text-center">

                                            <div class="px-3 py-2 bg-gradient-to-r from-rose-500/10 to-transparent border-b border-rose-100 dark:border-rose-900/30">

                                                <p class="text-[9px] font-bold uppercase text-rose-600 dark:text-rose-400">Expenses</p>

                                            </div>

                                            <div class="py-3">

                                                <p class="text-sm font-black text-rose-700 dark:text-rose-300" x-text="fmt(totalExpenses)"></p>

                                                <p class="text-[9px] text-slate-400" x-text="expenseCategories.length + ' categor' + (expenseCategories.length !== 1 ? 'ies' : 'y')"></p>

                                            </div>

                                        </div>

                                        <div class="glass-card rounded-xl border border-amber-200/60 dark:border-amber-800/40 overflow-hidden text-center">

                                            <div class="px-3 py-2 bg-gradient-to-r from-amber-500/10 to-transparent border-b border-amber-100 dark:border-amber-900/30">

                                                <p class="text-[9px] font-bold uppercase text-amber-600 dark:text-amber-400">Debtors</p>

                                            </div>

                                            <div class="py-3">

                                                <p class="text-sm font-black text-amber-700 dark:text-amber-300" x-text="fmt(totalDebtors)"></p>

                                                <p class="text-[9px] text-slate-400" x-text="debtorAccounts.length + ' account' + (debtorAccounts.length !== 1 ? 's' : '')"></p>

                                            </div>

                                        </div>

                                    </div>



                                    <!-- Variance banner -->

                                    <div class="glass-card rounded-xl border overflow-hidden"

                                        :class="reportVariance === 0 ? 'border-emerald-200/60 dark:border-emerald-800/40' : 'border-red-200/60 dark:border-red-800/40'">

                                        <div class="px-4 py-2 border-b" :class="reportVariance === 0 ? 'bg-emerald-500/10 border-emerald-100 dark:border-emerald-900/30' : 'bg-red-500/10 border-red-100 dark:border-red-900/30'">

                                            <div class="flex items-center justify-between">

                                                <div class="flex items-center gap-2">

                                                    <i data-lucide="bar-chart-3" class="w-3 h-3" :class="reportVariance === 0 ? 'text-emerald-600' : 'text-red-600'"></i>

                                                    <span class="text-[10px] font-bold uppercase tracking-wide" :class="reportVariance === 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'">Variance Summary</span>

                                                </div>

                                                <span class="text-[10px] font-black px-2 py-0.5 rounded-full"

                                                    :class="reportVariance === 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'"

                                                    x-text="reportVariance === 0 ? '&#10004; BALANCED' : (reportVariance > 0 ? '&#9650; OVER' : '&#9660; SHORT')">

                                                </span>

                                            </div>

                                        </div>

                                        <div class="grid grid-cols-3 divide-x divide-slate-100 dark:divide-slate-800">

                                            <div class="px-4 py-3"><p class="text-[9px] font-semibold text-slate-400 uppercase">Payment/Declared</p><p class="text-sm font-black text-blue-700 dark:text-blue-300" x-text="fmt(systemSalesTotal)"></p></div>

                                            <div class="px-4 py-3"><p class="text-[9px] font-semibold text-slate-400 uppercase">Pump Sales</p><p class="text-sm font-black text-orange-700 dark:text-orange-300" x-text="fmt(totalPumpSales)"></p></div>

                                            <div class="px-4 py-3"><p class="text-[9px] font-semibold text-slate-400 uppercase">Variance</p><p class="text-sm font-black" :class="reportVariance === 0 ? 'text-emerald-700' : reportVariance > 0 ? 'text-blue-700' : 'text-red-700'" x-text="fmt(reportVariance)"></p></div>

                                        </div>

                                    </div>



                                </div>

                                <!-- end live summary -->



                            </div>

                            <!-- end two-column -->



                            <!-- ═══ MONTH CLOSE-OUT ═══ -->

                            <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-xl overflow-hidden">

                                <!-- Header -->
                                <div class="relative px-6 py-4 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 overflow-hidden">
                                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_70%_50%,rgba(124,58,237,0.15),transparent_60%)]"></div>
                                    <div class="relative flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-xl bg-white/10 backdrop-blur border border-white/20 flex items-center justify-center">
                                                <i data-lucide="calculator" class="w-4 h-4 text-amber-400"></i>
                                            </div>
                                            <div>
                                                <h3 class="text-sm font-black text-white tracking-wide">Month Close-Out</h3>
                                                <p class="text-[10px] text-white/50 font-medium">Financial reconciliation summary</p>
                                            </div>
                                        </div>
                                        <div class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                            :class="monthCloseout.surplus === 0 ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : monthCloseout.surplus > 0 ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30' : 'bg-red-500/20 text-red-300 border border-red-500/30'"
                                            x-text="monthCloseout.surplus === 0 ? 'Balanced' : (monthCloseout.surplus > 0 ? 'Surplus' : 'Deficit')">
                                        </div>
                                    </div>
                                </div>

                                <!-- Ledger Table -->
                                <div class="divide-y divide-slate-100 dark:divide-slate-800">

                                    <!-- System Sales -->
                                    <div class="flex items-center justify-between px-6 py-3.5 hover:bg-slate-50/70 dark:hover:bg-slate-800/30 transition-colors">
                                        <div class="flex items-center gap-3">
                                            <div class="w-7 h-7 rounded-lg bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center shrink-0">
                                                <i data-lucide="credit-card" class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400"></i>
                                            </div>
                                            <span class="text-[13px] font-semibold text-slate-700 dark:text-slate-200">Audit/Total Sales</span>
                                        </div>
                                        <span class="text-[13px] font-black text-slate-900 dark:text-white tabular-nums" x-text="fmt(monthCloseout.systemSales)"></span>
                                    </div>

                                    <!-- Less: Bank Deposit -->
                                    <div class="flex items-center justify-between px-6 py-3.5 hover:bg-slate-50/70 dark:hover:bg-slate-800/30 transition-colors">
                                        <div class="flex items-center gap-3">
                                            <div class="w-7 h-7 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center shrink-0">
                                                <i data-lucide="landmark" class="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400"></i>
                                            </div>
                                            <span class="text-[13px] font-semibold text-slate-600 dark:text-slate-300"><span class="text-red-500 font-bold mr-1">Less:</span> Bank Deposit</span>
                                        </div>
                                        <span class="text-[13px] font-bold text-red-600 dark:text-red-400 tabular-nums" x-text="'− ' + fmt(monthCloseout.bankDeposit)"></span>
                                    </div>

                                    <!-- Total Balance -->
                                    <div class="flex items-center justify-between px-6 py-3 bg-gradient-to-r from-slate-100/80 to-slate-50/50 dark:from-slate-800/60 dark:to-slate-800/30">
                                        <span class="text-[13px] font-black uppercase tracking-wide text-slate-800 dark:text-white pl-10">Total Balance</span>
                                        <span class="text-sm font-black text-slate-900 dark:text-white tabular-nums" x-text="fmt(monthCloseout.totalBalance)"></span>
                                    </div>

                                    <!-- Spacer / Section Divider -->
                                    <div class="h-1 bg-gradient-to-r from-violet-500/20 via-amber-400/30 to-violet-500/20"></div>

                                    <!-- Dynamic Expense Categories -->
                                    <template x-for="(exp, idx) in monthCloseout.expenseLines" :key="idx">
                                        <div class="flex items-center justify-between px-6 py-3 hover:bg-slate-50/70 dark:hover:bg-slate-800/30 transition-colors">
                                            <div class="flex items-center gap-3">
                                                <div class="w-7 h-7 rounded-lg bg-rose-100 dark:bg-rose-900/40 flex items-center justify-center shrink-0">
                                                    <i data-lucide="receipt" class="w-3.5 h-3.5 text-rose-600 dark:text-rose-400"></i>
                                                </div>
                                                <span class="text-[13px] font-semibold text-slate-600 dark:text-slate-300"><span class="text-emerald-600 dark:text-emerald-400 font-bold mr-1">Add:</span> <span x-text="exp.name"></span></span>
                                            </div>
                                            <span class="text-[13px] font-bold text-slate-700 dark:text-slate-200 tabular-nums" x-text="fmt(exp.amount)"></span>
                                        </div>
                                    </template>
                                    <div x-show="monthCloseout.expenseLines.length === 0" class="flex items-center justify-between px-6 py-3 hover:bg-slate-50/70 dark:hover:bg-slate-800/30 transition-colors">
                                        <div class="flex items-center gap-3">
                                            <div class="w-7 h-7 rounded-lg bg-rose-100 dark:bg-rose-900/40 flex items-center justify-center shrink-0">
                                                <i data-lucide="receipt" class="w-3.5 h-3.5 text-rose-600 dark:text-rose-400"></i>
                                            </div>
                                            <span class="text-[13px] text-slate-400 italic">No expense categories entered</span>
                                        </div>
                                        <span class="text-[13px] font-bold text-slate-400 tabular-nums">₦0.00</span>
                                    </div>

                                    <!-- POS, Transfer Sales -->
                                    <div class="flex items-center justify-between px-6 py-3 hover:bg-slate-50/70 dark:hover:bg-slate-800/30 transition-colors">
                                        <div class="flex items-center gap-3">
                                            <div class="w-7 h-7 rounded-lg bg-cyan-100 dark:bg-cyan-900/40 flex items-center justify-center shrink-0">
                                                <i data-lucide="smartphone" class="w-3.5 h-3.5 text-cyan-600 dark:text-cyan-400"></i>
                                            </div>
                                            <span class="text-[13px] font-semibold text-slate-600 dark:text-slate-300"><span class="text-emerald-600 dark:text-emerald-400 font-bold mr-1">Add:</span> POS, Transfer Sales</span>
                                        </div>
                                        <span class="text-[13px] font-bold text-slate-700 dark:text-slate-200 tabular-nums" x-text="fmt(monthCloseout.posTransferSales)"></span>
                                    </div>

                                    <!-- Cash At Hand -->
                                    <div class="flex items-center justify-between px-6 py-3 hover:bg-slate-50/70 dark:hover:bg-slate-800/30 transition-colors">
                                        <div class="flex items-center gap-3">
                                            <div class="w-7 h-7 rounded-lg bg-green-100 dark:bg-green-900/40 flex items-center justify-center shrink-0">
                                                <i data-lucide="banknote" class="w-3.5 h-3.5 text-green-600 dark:text-green-400"></i>
                                            </div>
                                            <span class="text-[13px] font-semibold text-slate-600 dark:text-slate-300"><span class="text-emerald-600 dark:text-emerald-400 font-bold mr-1">Add:</span> Cash At Hand</span>
                                        </div>
                                        <span class="text-[13px] font-bold text-slate-700 dark:text-slate-200 tabular-nums" x-text="fmt(monthCloseout.cashAtHand)"></span>
                                    </div>

                                    <!-- Lube Stock Unsold -->
                                    <div class="flex items-center justify-between px-6 py-3 hover:bg-slate-50/70 dark:hover:bg-slate-800/30 transition-colors">
                                        <div class="flex items-center gap-3">
                                            <div class="w-7 h-7 rounded-lg bg-lime-100 dark:bg-lime-900/40 flex items-center justify-center shrink-0">
                                                <i data-lucide="droplets" class="w-3.5 h-3.5 text-lime-600 dark:text-lime-400"></i>
                                            </div>
                                            <span class="text-[13px] font-semibold text-slate-600 dark:text-slate-300"><span class="text-emerald-600 dark:text-emerald-400 font-bold mr-1">Add:</span> Lube Stock Unsold</span>
                                        </div>
                                        <span class="text-[13px] font-bold text-slate-700 dark:text-slate-200 tabular-nums" x-text="fmt(monthCloseout.lubeUnsold)"></span>
                                    </div>

                                    <!-- Receivables / Debtors -->
                                    <div class="flex items-center justify-between px-6 py-3 hover:bg-slate-50/70 dark:hover:bg-slate-800/30 transition-colors">
                                        <div class="flex items-center gap-3">
                                            <div class="w-7 h-7 rounded-lg bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center shrink-0">
                                                <i data-lucide="users" class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400"></i>
                                            </div>
                                            <span class="text-[13px] font-semibold text-slate-600 dark:text-slate-300"><span class="text-emerald-600 dark:text-emerald-400 font-bold mr-1">Add:</span> Receivables/Debtors</span>
                                        </div>
                                        <span class="text-[13px] font-bold text-slate-700 dark:text-slate-200 tabular-nums" x-text="fmt(monthCloseout.receivables)"></span>
                                    </div>

                                    <!-- Total -->
                                    <div class="flex items-center justify-between px-6 py-3.5 bg-gradient-to-r from-slate-100/80 to-slate-50/50 dark:from-slate-800/60 dark:to-slate-800/30">
                                        <span class="text-[13px] font-black uppercase tracking-wide text-slate-800 dark:text-white pl-10">Total</span>
                                        <span class="text-sm font-black text-slate-900 dark:text-white tabular-nums" x-text="fmt(monthCloseout.expectedTotal)"></span>
                                    </div>

                                    <!-- Surplus / Deficit -->
                                    <div class="px-6 py-4 bg-gradient-to-r"
                                        :class="monthCloseout.surplus === 0 ? 'from-emerald-50 to-emerald-25 dark:from-emerald-950/30 dark:to-emerald-900/10' : monthCloseout.surplus > 0 ? 'from-blue-50 to-blue-25 dark:from-blue-950/30 dark:to-blue-900/10' : 'from-red-50 to-red-25 dark:from-red-950/30 dark:to-red-900/10'">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-3">
                                                <div class="w-9 h-9 rounded-xl flex items-center justify-center"
                                                    :class="monthCloseout.surplus === 0 ? 'bg-emerald-500/20' : monthCloseout.surplus > 0 ? 'bg-blue-500/20' : 'bg-red-500/20'">
                                                    <i data-lucide="trending-up" class="w-4 h-4"
                                                        :class="monthCloseout.surplus === 0 ? 'text-emerald-600' : monthCloseout.surplus > 0 ? 'text-blue-600' : 'text-red-600'"
                                                        x-show="monthCloseout.surplus >= 0"></i>
                                                    <i data-lucide="trending-down" class="w-4 h-4 text-red-600"
                                                        x-show="monthCloseout.surplus < 0"></i>
                                                </div>
                                                <div>
                                                    <span class="text-[13px] font-black uppercase tracking-wide"
                                                        :class="monthCloseout.surplus === 0 ? 'text-emerald-700 dark:text-emerald-300' : monthCloseout.surplus > 0 ? 'text-blue-700 dark:text-blue-300' : 'text-red-700 dark:text-red-300'">
                                                        Surplus / Deficit
                                                    </span>
                                                    <p class="text-[10px] font-medium"
                                                        :class="monthCloseout.surplus === 0 ? 'text-emerald-600/70' : monthCloseout.surplus > 0 ? 'text-blue-600/70' : 'text-red-600/70'"
                                                        x-text="monthCloseout.surplus === 0 ? 'Accounts are perfectly balanced' : (monthCloseout.surplus > 0 ? 'More accounted for than expected' : 'Unaccounted difference detected')">
                                                    </p>
                                                </div>
                                            </div>
                                            <span class="text-lg font-black tabular-nums"
                                                :class="monthCloseout.surplus === 0 ? 'text-emerald-700 dark:text-emerald-300' : monthCloseout.surplus > 0 ? 'text-blue-700 dark:text-blue-300' : 'text-red-700 dark:text-red-300'"
                                                x-text="fmt(monthCloseout.surplus)">
                                            </span>
                                        </div>
                                    </div>

                                </div>

                            </div>

                            <!-- end month close-out -->



                        </div>

                    </div>

                    <!-- â•â•â• END REPORT TAB â•â•â• -->

                    <!-- FINAL REPORT TAB -->
                    <div x-show="currentTab==='final_report'" x-transition>

                        <div class="space-y-6">

                            <!-- Header + Action Bar -->
                            <div class="flex items-center justify-between flex-wrap gap-3">
                                <div>
                                    <h2 class="text-lg font-black text-slate-900 dark:text-white">Final Audit Report</h2>
                                    <p class="text-xs text-slate-500" x-text="(sessionData?.session?.outlet_name || 'Station') + ' · ' + (sessionData?.session?.date_from || '') + ' to ' + (sessionData?.session?.date_to || '')"></p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center gap-2 cursor-pointer select-none mr-2">
                                        <input type="checkbox" x-model="finalReportIncludePhotos" class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-amber-500 focus:ring-amber-500 dark:bg-slate-700"/>
                                        <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Include Photos</span>
                                    </label>
                                    <button @click="previewFinalReport()" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-slate-900 hover:bg-black text-white text-xs font-bold transition shadow">
                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i> Preview
                                    </button>
                                    <button @click="downloadFinalReport()" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-black text-xs font-bold transition shadow">
                                        <i data-lucide="download" class="w-3.5 h-3.5"></i> Download PDF
                                    </button>
                                </div>
                            </div>

                            <!-- Cover Page Settings -->
                            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

                                <div class="px-6 py-4">
                                    <h3 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
                                        <i data-lucide="settings-2" class="w-4 h-4 text-amber-500"></i>
                                        Cover Page Settings
                                    </h3>
                                    <p class="text-[10px] text-slate-400 mt-0.5">Customize the cover page of your final report</p>
                                </div>

                                <div class="p-6 grid md:grid-cols-2 gap-5">

                                    <!-- Report Title -->
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Report Title</label>
                                        <input x-model="finalReportCover.title" type="text"
                                            class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-bold text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-amber-400"
                                            placeholder="RECONCILIATION REPORT">
                                    </div>

                                    <!-- Subtitle -->
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Subtitle Badge</label>
                                        <input x-model="finalReportCover.subtitle" type="text"
                                            class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-amber-400"
                                            placeholder="Audit Close-Out">
                                    </div>

                                    <!-- Prepared For -->
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Prepared For</label>
                                        <input x-model="finalReportCover.preparedFor" type="text"
                                            class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-amber-400"
                                            placeholder="Operations Department">
                                    </div>

                                    <!-- Prepared By -->
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Prepared By</label>
                                        <input x-model="finalReportCover.preparedBy" type="text"
                                            class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-amber-400"
                                            :placeholder="window.__SA_USER?.name || 'Auditor'">
                                    </div>

                                    <!-- Notes -->
                                    <div class="md:col-span-2">
                                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Cover Notes <span class="text-slate-300 dark:text-slate-600 font-normal">(optional)</span></label>
                                        <textarea x-model="finalReportCover.notes" rows="2"
                                            class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-amber-400 resize-none"
                                            placeholder="Any additional notes or disclaimers..."></textarea>
                                    </div>
                                </div>

                                <!-- Live cover preview strip -->
                                <div class="p-6">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Live Preview</p>
                                    <div class="rounded-xl overflow-hidden border border-slate-300 dark:border-slate-600">
                                        <div class="bg-white p-5" style="border-bottom: 4px solid #000">
                                            <div class="flex justify-between items-start mb-6">
                                                <div>
                                                    <h4 class="text-lg font-black text-black leading-none" x-text="window.__SA_COMPANY || 'MIAUDITOPS'"></h4>
                                                    <p class="text-[8px] font-bold text-slate-400 tracking-[3px] uppercase mt-1">Enterprise Station Auditing</p>
                                                </div>
                                                <div class="text-right">
                                                    <p class="text-[8px] font-black text-slate-300 uppercase tracking-widest">Confidential</p>
                                                </div>
                                            </div>

                                            <div class="text-center py-4">
                                                <div class="inline-block px-3 py-0.5 border-2 border-black mb-3">
                                                    <span class="text-[9px] font-black tracking-[4px] uppercase" x-text="finalReportCover.subtitle || 'Audit Close-Out'"></span>
                                                </div>
                                                <h3 class="text-xl font-black text-black tracking-tight leading-tight" x-text="finalReportCover.title || 'RECONCILIATION REPORT'"></h3>
                                                <div class="w-12 h-1 bg-amber-500 mx-auto my-3 rounded-full"></div>
                                                <p class="text-sm font-bold text-slate-700" x-text="sessionData?.session?.outlet_name || 'Station'"></p>
                                                <p class="text-[10px] text-slate-400 font-mono mt-0.5" x-text="(sessionData?.session?.date_from || '--') + ' — ' + (sessionData?.session?.date_to || '--')"></p>
                                            </div>

                                            <div class="flex justify-between items-end pt-4 border-t-2 border-black text-[9px]">
                                                <div class="space-y-1">
                                                    <div>
                                                        <span class="font-black text-slate-400 uppercase tracking-widest">For: </span>
                                                        <span class="font-bold text-black" x-text="finalReportCover.preparedFor || 'Operations Department'"></span>
                                                    </div>
                                                    <div>
                                                        <span class="font-black text-slate-400 uppercase tracking-widest">By: </span>
                                                        <span class="font-bold text-black" x-text="finalReportCover.preparedBy || (window.__SA_USER?.name || 'Auditor')"></span>
                                                    </div>
                                                </div>
                                                <div class="text-right space-y-1">
                                                    <div>
                                                        <span class="font-black text-slate-400 uppercase tracking-widest">Date: </span>
                                                        <span class="font-bold text-black" x-text="new Date().toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'})"></span>
                                                    </div>
                                                    <div>
                                                        <span class="inline-block px-2 py-0.5 bg-black text-white text-[8px] font-bold rounded uppercase" x-text="sessionData?.session?.status || 'draft'"></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <!-- Quick Data Summary -->
                            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 p-6">
                                <h3 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2 mb-4">
                                    <i data-lucide="bar-chart-3" class="w-4 h-4 text-amber-500"></i>
                                    Report Data Summary
                                </h3>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <div class="p-3 bg-slate-50 dark:bg-slate-700/50 rounded-xl text-center">
                                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Audit Sales</p>
                                        <p class="text-sm font-black text-slate-900 dark:text-white mt-1" x-text="fmt(monthCloseout.systemSales)"></p>
                                    </div>
                                    <div class="p-3 bg-slate-50 dark:bg-slate-700/50 rounded-xl text-center">
                                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Bank Deposit</p>
                                        <p class="text-sm font-black text-slate-900 dark:text-white mt-1" x-text="fmt(monthCloseout.bankDeposit)"></p>
                                    </div>
                                    <div class="p-3 bg-slate-50 dark:bg-slate-700/50 rounded-xl text-center">
                                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Expenses</p>
                                        <p class="text-sm font-black text-rose-600 mt-1" x-text="fmt(monthCloseout.totalExpenses)"></p>
                                    </div>
                                    <div class="p-3 rounded-xl text-center" :class="monthCloseout.surplus >= 0 ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-red-50 dark:bg-red-900/20'">
                                        <p class="text-[9px] font-bold uppercase tracking-wider" :class="monthCloseout.surplus >= 0 ? 'text-emerald-500' : 'text-red-500'" x-text="monthCloseout.surplus >= 0 ? 'Surplus' : 'Deficit'"></p>
                                        <p class="text-sm font-black mt-1" :class="monthCloseout.surplus >= 0 ? 'text-emerald-600' : 'text-red-600'" x-text="fmt(Math.abs(monthCloseout.surplus))"></p>
                                    </div>
                                </div>
                            </div>

                        </div>

                    </div>
                    <!-- END FINAL REPORT TAB -->


                </div>
            </template>

        </main>
    </div>
</div>

<?php include '../includes/dashboard_scripts.php'; ?>
<script>
    window.__SA_OUTLETS = <?php echo $js_outlets; ?>;
    window.__SA_SESSIONS = <?php echo $js_sessions; ?>;
    window.__SA_USER = { name: <?php echo json_encode(trim(($current_user['first_name'] ?? '') . ' ' . ($current_user['last_name'] ?? '')), JSON_HEX_TAG | JSON_HEX_APOS); ?>, role: <?php echo json_encode($role_label ?? 'Auditor', JSON_HEX_TAG | JSON_HEX_APOS); ?> };
    window.__SA_COMPANY = <?php echo json_encode($company['name'] ?? 'MIAUDITOPS', JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    window.__NAIRA = '\u20A6';
</script>
<script src="station_audit_app.js?v=<?php echo filemtime(__DIR__ . '/station_audit_app.js'); ?>"></script>
</body>
</html>
