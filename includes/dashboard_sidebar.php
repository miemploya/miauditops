<?php
/**
 * MIAUDITOPS — Dashboard Sidebar
 * Centralized vertical navigation with module groups
 */
require_once __DIR__ . '/../config/sector_config.php';
$current_page = basename($_SERVER['PHP_SELF']);
$company = $company ?? get_company($_SESSION['company_id']);
$company_initial = strtoupper(substr($company['name'] ?? 'M', 0, 1));
$user_role = get_user_role();
$active_client_id = get_active_client();
$active_client_name = $_SESSION['active_client_name'] ?? null;
$active_client_code = $_SESSION['active_client_code'] ?? null;

// Fetch clients for selector — filtered by assignment for non-admins
$sidebar_clients = get_clients_for_user($_SESSION['company_id']);

// Detect active client's sector for conditional menu items
$_sidebar_client_sector = 'other';
if ($active_client_id) {
    $_sidebar_stmt = $pdo->prepare("SELECT industry FROM clients WHERE id = ? AND company_id = ?");
    $_sidebar_stmt->execute([$active_client_id, $_SESSION['company_id']]);
    $_sidebar_client_sector = strtolower($_sidebar_stmt->fetchColumn() ?: 'other');
}
$_is_petroleum = ($_sidebar_client_sector === 'petroleum');

// Map page hrefs to permission keys for sidebar filtering
$page_permission_map = [
    'index.php'            => 'dashboard',
    'company_setup.php'    => 'company_setup',
    'audit.php'            => 'audit',
    'station_audit.php'    => 'station_audit',
    'stock.php'            => 'stock',
    'main_store.php'       => 'main_store',
    'department_store.php' => 'department_store',
    'finance.php'          => 'finance',
    'requisitions.php'     => 'requisitions',
    'reports.php'          => 'reports',
    'settings.php'         => 'settings',
    'trash.php'            => 'trash',
    'support.php'          => 'support',
    'billing.php'          => 'billing',
    'pnl_generator.php'    => 'finance',
    'bank_recon.php'       => 'finance',
    'fixed_assets.php'     => 'finance',
    'capital_allowance.php' => 'finance',
];

// Map page hrefs to subscription module keys (may differ from permission keys)
$page_subscription_map = [
    'index.php'            => 'dashboard',
    'company_setup.php'    => 'company_setup',
    'audit.php'            => 'audit',
    'station_audit.php'    => 'station_audit',
    'stock.php'            => 'stock',
    'main_store.php'       => 'main_store',
    'department_store.php' => 'department_store',
    'finance.php'          => 'finance',
    'requisitions.php'     => 'requisitions',
    'reports.php'          => 'reports',
    'settings.php'         => 'settings',
    'trash.php'            => 'trash',
    'support.php'          => 'support',
    'billing.php'          => 'billing',
    'pnl_generator.php'    => 'finance',
    'bank_recon.php'       => 'finance',
    'fixed_assets.php'     => 'finance',
    'capital_allowance.php' => 'finance',
];
try {
    $_current_plan_key = get_current_plan();
} catch (Exception $e) {
    $_current_plan_key = 'starter';
}

// Navigation definition with role-based visibility
$nav_sections = [
    'main' => [
        'items' => [
            ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'href' => 'index.php', 'gradient' => 'from-violet-500 to-purple-600', 'roles' => 'all'],
        ]
    ],
    'Setup' => [
        'items' => [
            ['label' => 'Company Setup', 'icon' => 'building-2', 'href' => 'company_setup.php', 'gradient' => 'from-indigo-500 to-blue-600', 'roles' => 'all'],
        ]
    ],
    'Audit & Sales' => [
        'items' => [
            ['label' => 'Daily Audit', 'icon' => 'clipboard-check', 'href' => 'audit.php', 'gradient' => 'from-blue-500 to-blue-600', 'roles' => 'all'],
        ]
    ],
    'Inventory' => [
        'items' => [
            ['label' => 'Stock Audit', 'icon' => 'package', 'href' => 'stock.php', 'gradient' => 'from-emerald-500 to-teal-600', 'roles' => 'all'],
        ]
    ],
    'Finance' => [
        'items' => [
            ['label' => 'Financial Control', 'icon' => 'trending-up', 'href' => 'finance.php', 'gradient' => 'from-amber-500 to-orange-600', 'roles' => 'all'],
        ]
    ],
    'Procurement' => [
        'items' => [
            ['label' => 'Requisitions', 'icon' => 'file-text', 'href' => 'requisitions.php', 'gradient' => 'from-rose-500 to-pink-600', 'roles' => 'all'],
        ]
    ],
    'Intelligence' => [
        'items' => [
            ['label' => 'Reports', 'icon' => 'bar-chart-3', 'href' => 'reports.php', 'gradient' => 'from-cyan-500 to-blue-600', 'roles' => 'all'],
        ]
    ],
];

// Add Station Audit: show if plan includes station_audit module OR ANY of user's clients is petroleum
$_has_petroleum_client = $_is_petroleum;
if (!$_has_petroleum_client && !empty($sidebar_clients)) {
    foreach ($sidebar_clients as $_sc) {
        if (strtolower($_sc['industry'] ?? '') === 'petroleum') {
            $_has_petroleum_client = true;
            break;
        }
    }
}
if ($_has_petroleum_client || plan_includes_module($_current_plan_key, 'station_audit')) {
    $nav_sections['Station'] = [
        'items' => [
            ['label' => 'Station Audit', 'icon' => 'fuel', 'href' => 'station_audit.php', 'gradient' => 'from-orange-500 to-amber-600', 'roles' => 'all', 'badge' => 'NEW'],
        ]
    ];
}

// Create P&L — after Station Audit
$nav_sections['P&L'] = [
    'items' => [
        ['label' => 'Create P&L', 'icon' => 'file-spreadsheet', 'href' => 'pnl_generator.php', 'gradient' => 'from-emerald-500 to-green-600', 'roles' => 'all', 'badge' => 'NEW'],
        ['label' => 'Bank Recon', 'icon' => 'landmark', 'href' => 'bank_recon.php', 'gradient' => 'from-blue-500 to-indigo-600', 'roles' => 'all', 'badge' => 'NEW'],
        ['label' => 'Fixed Assets', 'icon' => 'package-check', 'href' => 'fixed_assets.php', 'gradient' => 'from-violet-500 to-purple-600', 'roles' => 'all', 'badge' => 'NEW'],
        ['label' => 'Capital Allow.', 'icon' => 'calculator', 'href' => 'capital_allowance.php', 'gradient' => 'from-amber-500 to-orange-600', 'roles' => 'all', 'badge' => 'NEW'],
    ]
];

// Billing — always visible, placed after Station Audit
$nav_sections['Billing'] = [
    'items' => [
        ['label' => 'Billing', 'icon' => 'credit-card', 'href' => 'billing.php', 'gradient' => 'from-emerald-500 to-teal-600', 'roles' => 'all'],
    ]
];

// Support Services
$nav_sections['Support'] = [
    'items' => [
        ['label' => 'Support Services', 'icon' => 'headphones', 'href' => 'support.php', 'gradient' => 'from-amber-500 to-orange-600', 'roles' => 'all'],
    ]
];

// Trash — after Support Services
$nav_sections['Cleanup'] = [
    'items' => [
        ['label' => 'Trash', 'icon' => 'trash-2', 'href' => 'trash.php', 'gradient' => 'from-red-500 to-rose-600', 'roles' => 'all'],
    ]
];

// Settings — always last on the sidebar
$nav_sections['Admin'] = [
    'items' => [
        ['label' => 'Settings', 'icon' => 'settings', 'href' => 'settings.php', 'gradient' => 'from-slate-500 to-slate-600', 'roles' => 'all'],
    ]
];
?>

<!-- Mobile Overlay -->
<div id="overlay" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 hidden lg:hidden"></div>

<!-- Sidebar -->
<aside id="sidebar" class="sidebar-transition fixed lg:static inset-y-0 left-0 z-50 w-64 bg-gradient-to-b from-slate-50 via-white to-slate-50 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 border-r border-slate-200/70 dark:border-slate-800/70 flex flex-col -translate-x-full lg:translate-x-0 shadow-xl lg:shadow-none">
    
    <!-- Company Branding Card -->
    <div class="p-4 border-b border-slate-200/50 dark:border-slate-800/50">
        <div class="relative group w-full flex items-center gap-3 p-3 rounded-xl bg-gradient-to-br from-slate-50 to-slate-100/50 dark:from-slate-800 dark:to-slate-900/50 border border-slate-200/50 dark:border-slate-700/50 transition-all shadow-sm">
            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center text-white font-bold text-lg shadow-lg shadow-violet-500/30">
                <?php echo $company_initial; ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Operations Hub</p>
                <p class="text-sm font-bold text-slate-800 dark:text-slate-200 truncate"><?php echo htmlspecialchars($company['name'] ?? 'Company'); ?></p>
            </div>
            <div class="absolute inset-0 rounded-xl bg-gradient-to-r from-transparent via-white/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none"></div>
        </div>

        <!-- Active Client Selector -->
        <?php if (!empty($sidebar_clients)): ?>
        <div class="mt-3" x-data="{ open: false }">
            <button @click="open = !open" class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-semibold transition-all border <?php echo $active_client_id ? 'bg-indigo-50 dark:bg-indigo-900/20 border-indigo-200 dark:border-indigo-800 text-indigo-700 dark:text-indigo-300' : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300'; ?> hover:shadow-sm">
                <i data-lucide="<?php echo $active_client_id ? 'building' : 'alert-circle'; ?>" class="w-3.5 h-3.5"></i>
                <span class="flex-1 text-left truncate"><?php echo $active_client_name ? htmlspecialchars($active_client_name) : '⚠ Select Client'; ?></span>
                <i data-lucide="chevron-down" class="w-3 h-3 transition-transform" :class="open ? 'rotate-180' : ''"></i>
            </button>
            <div x-show="open" @click.away="open=false" x-transition class="mt-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg max-h-48 overflow-y-auto z-50 relative">
                <?php foreach ($sidebar_clients as $sc): ?>
                <a href="../ajax/company_setup_api.php?action=set_active_client&client_id=<?php echo $sc['id']; ?>&redirect=<?php echo urlencode($current_page); ?>" 
                   class="flex items-center gap-2 px-3 py-2 text-xs hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors <?php echo ($sc['id'] == $active_client_id) ? 'bg-indigo-50 dark:bg-indigo-900/30 font-bold text-indigo-700' : 'text-slate-600 dark:text-slate-400'; ?>">
                    <span class="w-6 h-6 rounded-md bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center text-white text-[9px] font-bold"><?php echo strtoupper(substr($sc['name'], 0, 2)); ?></span>
                    <span class="truncate"><?php echo htmlspecialchars($sc['name']); ?></span>
                    <?php if ($sc['id'] == $active_client_id): ?><i data-lucide="check" class="w-3 h-3 ml-auto text-indigo-600"></i><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto p-3 space-y-1" style="scroll-behavior:smooth; scrollbar-width:thin; scrollbar-color:rgba(148,163,184,0.3) transparent;">
        
        <?php foreach ($nav_sections as $section_name => $section): ?>
            
            <?php foreach ($section['items'] as $item): ?>
                <?php
                // Permission-based check using the page_permission_map
                $perm_key = $page_permission_map[$item['href']] ?? null;
                if ($perm_key && !has_permission($perm_key)) continue;
                // Viewers cannot see Settings
                if ($item['href'] === 'settings.php' && is_viewer()) continue;
                $is_active = ($current_page === $item['href']);

                // Subscription gating: check if module is in current plan
                $sub_key = $page_subscription_map[$item['href']] ?? null;
                $is_locked = $sub_key && !plan_includes_module($_current_plan_key, $sub_key);
                ?>
                <?php if ($is_locked): ?>
                <a href="<?php echo $item['href']; ?>" 
                   class="group flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-xl transition-all duration-200 text-slate-400 dark:text-slate-600 opacity-60 hover:opacity-80 border-l-2 border-transparent cursor-pointer"
                   title="Upgrade to access <?php echo $item['label']; ?>">
                    <span class="w-8 h-8 rounded-lg bg-slate-300 dark:bg-slate-700 flex items-center justify-center shadow-sm">
                        <i data-lucide="lock" class="w-4 h-4 text-slate-400 dark:text-slate-500"></i>
                    </span>
                    <span class="font-semibold"><?php echo $item['label']; ?></span>
                    <span class="ml-auto px-1.5 py-0.5 rounded-md text-[8px] font-black tracking-wider bg-gradient-to-r from-violet-500 to-amber-500 text-white">PRO</span>
                </a>
                <?php else: ?>
                <a href="<?php echo $item['href']; ?>" 
                   class="group flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-xl transition-all duration-200 hover:translate-x-0.5
                   <?php echo $is_active 
                       ? 'text-violet-700 dark:text-violet-300 bg-gradient-to-r from-violet-50 to-violet-100/50 dark:from-violet-900/30 dark:to-violet-800/20 border-l-2 border-violet-500' 
                       : 'text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 hover:bg-slate-100/80 dark:hover:bg-slate-800/50 border-l-2 border-transparent'; ?>">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br <?php echo $item['gradient']; ?> flex items-center justify-center shadow-md transition-shadow group-hover:shadow-lg">
                        <i data-lucide="<?php echo $item['icon']; ?>" class="w-4 h-4 text-white"></i>
                    </span>
                    <span class="font-semibold"><?php echo $item['label']; ?></span>
                    <?php if (!empty($item['badge'])): ?>
                    <span class="ml-auto px-1.5 py-0.5 rounded-md text-[9px] font-black tracking-wider bg-red-500 text-white shadow-lg shadow-red-500/40 animate-pulse"><?php echo $item['badge']; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>
            
        <?php endforeach; ?>
    </nav>

    <!-- Footer -->
    <div class="hidden lg:flex py-3 px-4 border-t border-slate-200/50 dark:border-slate-800/50 bg-gradient-to-r from-slate-50 to-transparent dark:from-slate-900 dark:to-transparent justify-between items-center">
        <span class="text-[10px] font-medium text-slate-400 uppercase tracking-wide">MIAUDITOPS v1.0</span>
        <button id="sidebar-collapse-btn" class="p-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-all hover:scale-110">
            <i data-lucide="chevrons-left" class="w-5 h-5"></i>
        </button>
    </div>
</aside>

<?php if (is_viewer()): ?>
<!-- ═══════════ VIEWER ROLE: FULL READ-ONLY LOCK ═══════════ -->
<div id="viewer-banner" style="position:fixed; top:0; left:0; right:0; z-index:9999; background:linear-gradient(90deg,#f59e0b,#d97706); color:white; text-align:center; padding:8px 16px; font-size:12px; font-weight:700; letter-spacing:0.05em; box-shadow:0 4px 12px rgba(0,0,0,0.15);">
    👁 VIEW ONLY MODE — You have read-only access
</div>
<style>
    body { padding-top: 36px !important; }
    @media print { #viewer-banner { display: none !important; } body { padding-top: 0 !important; } }
</style>
<script>
(function(){
    /* ── Layer 1: Intercept ALL API POST requests ── */
    var _fetch = window.fetch;
    window.fetch = function(url, opts) {
        if (opts && opts.method && opts.method.toUpperCase() === 'POST' && String(url).indexOf('_api.php') !== -1) {
            _viewerToast();
            return Promise.resolve(new Response(JSON.stringify({success:false, message:'Your account has view-only access. You cannot make changes.'}), {status:200, headers:{'Content-Type':'application/json'}}));
        }
        return _fetch.apply(this, arguments);
    };
    var _xOpen = XMLHttpRequest.prototype.open, _xSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function(m,u){ this._vm=m; this._vu=u; return _xOpen.apply(this,arguments); };
    XMLHttpRequest.prototype.send = function(d){
        if (this._vm && this._vm.toUpperCase()==='POST' && String(this._vu).indexOf('_api.php')!==-1) { _viewerToast(); return; }
        return _xSend.apply(this,arguments);
    };

    /* ── Layer 2: Toast notification ── */
    function _viewerToast() {
        var t = document.getElementById('viewer-toast');
        if (!t) {
            t = document.createElement('div'); t.id = 'viewer-toast';
            t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;background:#dc2626;color:white;padding:14px 24px;border-radius:14px;font-size:13px;font-weight:700;box-shadow:0 8px 32px rgba(220,38,38,0.4);transition:opacity 0.3s;pointer-events:none;';
            document.body.appendChild(t);
        }
        t.textContent = '🔒 View-only access — You cannot make changes';
        t.style.opacity = '1';
        clearTimeout(window._vtTimer);
        window._vtTimer = setTimeout(function(){ t.style.opacity = '0'; }, 3500);
    }

    /* ── Layer 3: Hide action buttons & disable form inputs ── */
    var actionRx = /\b(save|submit|create|add\s|add$|delete|remove|approve|reject|update|record|new\s|new$|confirm|convert|sign|lodge|import|upload)\b/i;
    var safeRx   = /\b(view|filter|search|show|hide|expand|collapse|close|cancel|back|tab|switch|print|export|download|refresh|select|period|clear|reset)\b/i;

    function lockUI() {
        /* Hide action buttons — skip sidebar, nav, tabs, filter controls */
        document.querySelectorAll('button, a[role="button"], input[type="submit"]').forEach(function(btn){
            if (btn.closest('#sidebar, aside, nav, [role="tablist"], #viewer-banner')) return;
            if (btn.dataset.viewerChecked) return;
            btn.dataset.viewerChecked = '1';
            var text = (btn.textContent || btn.value || '').trim();
            if (safeRx.test(text)) return;
            if (actionRx.test(text) || btn.type === 'submit') {
                btn.style.setProperty('display', 'none', 'important');
            }
        });

        /* Disable editable inputs inside the main content area (not sidebar/nav/filters) */
        document.querySelectorAll('input:not([type="hidden"]), textarea').forEach(function(el){
            if (el.closest('#sidebar, aside, nav, #viewer-banner, .print-hidden')) return;
            if (el.dataset.viewerChecked) return;
            if (el.type === 'search' || el.type === 'checkbox' || el.type === 'radio') return;
            if (el.placeholder && /search|filter/i.test(el.placeholder)) return;
            el.dataset.viewerChecked = '1';
            el.readOnly = true;
            el.style.opacity = '0.6';
            el.style.cursor = 'not-allowed';
        });

        /* Disable select dropdowns inside forms (not filters/period selectors) */
        document.querySelectorAll('select').forEach(function(el){
            if (el.closest('#sidebar, aside, nav, #viewer-banner, .print-hidden')) return;
            if (el.dataset.viewerChecked) return;
            if (el.id && /pnl|filter|month|year|period|sort/i.test(el.id)) return;
            if (el.name && /filter|sort|search/i.test(el.name)) return;
            el.dataset.viewerChecked = '1';
            el.disabled = true;
            el.style.opacity = '0.6';
            el.style.cursor = 'not-allowed';
        });
    }

    document.addEventListener('DOMContentLoaded', function(){
        lockUI();
        /* Re-run whenever Alpine.js or AJAX adds new elements */
        var observer = new MutationObserver(function(){ setTimeout(lockUI, 150); });
        observer.observe(document.body, {childList: true, subtree: true});
    });

    window.IS_VIEWER = true;
})();
</script>
<?php endif; ?>

<!-- ═══════════ NETWORK STATUS NOTIFICATION ═══════════ -->
<div id="net-status-bar" style="
    position: fixed; top: -80px; left: 50%; transform: translateX(-50%);
    z-index: 999999; min-width: 320px; max-width: 480px; padding: 14px 24px;
    border-radius: 0 0 16px 16px; font-size: 13px; font-weight: 700;
    display: flex; align-items: center; gap: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.2);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    transition: top 0.5s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.4s ease;
    opacity: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
">
    <div id="net-status-icon" style="
        width: 36px; height: 36px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; transition: all 0.3s ease;
    ">
        <svg id="net-icon-offline" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:none">
            <line x1="2" y1="2" x2="22" y2="22"></line>
            <path d="M8.5 16.5a5 5 0 0 1 7 0"></path>
            <path d="M2 8.82a15 15 0 0 1 4.17-2.65"></path>
            <path d="M10.66 5c4.01-.36 8.14.9 11.34 3.76"></path>
            <path d="M16.85 11.25a10 10 0 0 1 2.22 1.68"></path>
            <path d="M5 12.86a10 10 0 0 1 5.17-2.97"></path>
            <line x1="12" y1="20" x2="12.01" y2="20"></line>
        </svg>
        <svg id="net-icon-online" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:none">
            <path d="M5 12.55a11 11 0 0 1 14.08 0"></path>
            <path d="M1.42 9a16 16 0 0 1 21.16 0"></path>
            <path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path>
            <line x1="12" y1="20" x2="12.01" y2="20"></line>
        </svg>
    </div>
    <div style="flex:1; min-width:0;">
        <div id="net-status-title" style="font-size:13px; font-weight:800; letter-spacing:0.02em;"></div>
        <div id="net-status-sub" style="font-size:10px; font-weight:500; opacity:0.8; margin-top:2px;"></div>
    </div>
    <div id="net-status-pulse" style="
        width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
        transition: all 0.3s ease;
    "></div>
</div>

<style>
@keyframes net-pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.6); opacity: 0.4; }
}
@keyframes net-shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-2px); }
    20%, 40%, 60%, 80% { transform: translateX(2px); }
}
#net-status-bar.offline {
    background: linear-gradient(135deg, rgba(220, 38, 38, 0.95), rgba(185, 28, 28, 0.95));
    color: #fff; border: 1px solid rgba(255,255,255,0.15); border-top: none;
}
#net-status-bar.offline #net-status-icon {
    background: rgba(255,255,255,0.15); color: #fff;
}
#net-status-bar.offline #net-status-pulse {
    background: #fca5a5; animation: net-pulse 1.2s ease-in-out infinite;
    box-shadow: 0 0 8px rgba(252,165,165,0.6);
}
#net-status-bar.online {
    background: linear-gradient(135deg, rgba(5, 150, 105, 0.95), rgba(4, 120, 87, 0.95));
    color: #fff; border: 1px solid rgba(255,255,255,0.15); border-top: none;
}
#net-status-bar.online #net-status-icon {
    background: rgba(255,255,255,0.15); color: #fff;
}
#net-status-bar.online #net-status-pulse {
    background: #6ee7b7; box-shadow: 0 0 8px rgba(110,231,183,0.6);
}
@media print { #net-status-bar { display: none !important; } }
</style>

<script>
(function(){
    var bar = document.getElementById('net-status-bar');
    var title = document.getElementById('net-status-title');
    var sub = document.getElementById('net-status-sub');
    var iconOff = document.getElementById('net-icon-offline');
    var iconOn = document.getElementById('net-icon-online');
    var hideTimer = null;
    var wasOffline = false;

    function showBar(type) {
        clearTimeout(hideTimer);
        bar.className = type; // 'offline' or 'online'
        if (type === 'offline') {
            title.textContent = 'You are offline';
            sub.textContent = 'Check your internet connection';
            iconOff.style.display = 'block';
            iconOn.style.display = 'none';
            bar.style.animation = 'net-shake 0.5s ease';
            setTimeout(function(){ bar.style.animation = ''; }, 600);
        } else {
            title.textContent = 'Back online';
            sub.textContent = 'Connection restored';
            iconOff.style.display = 'none';
            iconOn.style.display = 'block';
        }
        bar.style.top = '0';
        bar.style.opacity = '1';

        if (type === 'online') {
            hideTimer = setTimeout(function(){
                bar.style.top = '-80px';
                bar.style.opacity = '0';
            }, 4000);
        }
    }

    window.addEventListener('offline', function(){
        wasOffline = true;
        showBar('offline');
    });

    window.addEventListener('online', function(){
        if (wasOffline) {
            showBar('online');
            wasOffline = false;
        }
    });

    // Check initial state
    if (!navigator.onLine) {
        wasOffline = true;
        setTimeout(function(){ showBar('offline'); }, 500);
    }
})();
</script>
