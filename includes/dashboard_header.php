<?php
/**
 * MIAUDITOPS — Dashboard Header
 * Centralized top bar with page title, notifications, and user controls
 */
$current_user = get_user($_SESSION['user_id'] ?? 0);
if (!$current_user) $current_user = ['first_name' => 'User', 'last_name' => '', 'email' => '', 'role' => 'user'];
$company = get_company($_SESSION['company_id'] ?? 0);
if (!$company) $company = ['name' => 'MIAUDITOPS'];
$user_initials = strtoupper(substr($current_user['first_name'], 0, 1) . substr($current_user['last_name'], 0, 1));
$role_labels = [
    'super_admin' => 'Super Admin',
    'business_owner' => 'Business Owner / CEO',
    'auditor' => 'Auditor',
    'finance_officer' => 'Finance Officer',
    'store_officer' => 'Store Officer',
    'department_head' => 'Department Head'
];
$role_label = $role_labels[$current_user['role']] ?? 'User';

// ── Fetch unread notification count ──
$_notif_count = 0;
$_notif_items = [];
try {
    $__nStmt = $pdo->prepare("
        SELECT n.id, n.title, n.message, n.type, n.created_at
        FROM platform_notifications n
        WHERE n.is_active = 1
          AND (n.expires_at IS NULL OR n.expires_at > NOW())
          AND (n.target = 'all' OR (n.target = 'plan' AND n.target_plan = ?))
          AND n.id NOT IN (SELECT notification_id FROM notification_reads WHERE user_id = ?)
        ORDER BY n.created_at DESC
        LIMIT 10
    ");
    $_plan = $_current_plan_key ?? 'starter';
    $__nStmt->execute([$_plan, $_SESSION['user_id']]);
    $_notif_items = $__nStmt->fetchAll(PDO::FETCH_ASSOC);
    $_notif_count = count($_notif_items);
} catch (Exception $e) {
    // Table may not exist yet
}
?>

<style>
    .toolbar-hidden { transform: translateY(-100%); opacity: 0; pointer-events: none; height: 0; padding: 0; border: none; transition: all 0.3s ease; }
    .toolbar-visible { transform: translateY(0); opacity: 1; pointer-events: auto; height: auto; transition: all 0.3s ease; }
    .sidebar-transition { transition: width 0.3s ease, margin-left 0.3s ease, transform 0.3s ease, padding 0.3s ease; }
</style>

<header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-950/80 backdrop-blur-xl border-b border-slate-200/60 dark:border-slate-800/60 shrink-0">
    <div class="flex items-center justify-between h-16 px-4 lg:px-6">
        <!-- Left: Mobile menu + Page title -->
        <div class="flex items-center gap-3">
            <button id="mobile-menu-btn" class="lg:hidden p-2 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div>
                <h1 class="text-lg font-bold text-slate-900 dark:text-white"><?php echo $page_title ?? 'Dashboard'; ?></h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 hidden sm:block"><?php echo htmlspecialchars($company['name'] ?? 'MIAUDITOPS'); ?></p>
            </div>
        </div>

        <!-- Right: Controls -->
        <div class="flex items-center gap-2">

            <!-- Theme Toggle -->
            <button id="themeToggle" class="p-2.5 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                <i data-lucide="sun" class="w-5 h-5 dark:hidden"></i>
                <i data-lucide="moon" class="w-5 h-5 hidden dark:block"></i>
            </button>

            <!-- Notifications (Live) -->
            <div class="relative" x-data="notificationBell()" x-init="init()">
                <button @click="open = !open" class="relative p-2.5 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                    <i data-lucide="bell" class="w-5 h-5"></i>
                    <span x-show="unread > 0" x-cloak class="absolute top-1 right-1 min-w-[18px] h-[18px] flex items-center justify-center bg-red-500 text-white text-[10px] font-bold rounded-full ring-2 ring-white dark:ring-slate-950 px-1" x-text="unread"></span>
                </button>

                <!-- Notification Dropdown -->
                <div x-show="open" @click.outside="open = false" x-transition
                     class="absolute right-0 mt-2 w-80 bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 z-50 overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <h3 class="text-sm font-bold text-slate-800 dark:text-white">Notifications</h3>
                        <button x-show="unread > 0" @click="markAllRead()" class="text-[10px] font-semibold text-violet-600 dark:text-violet-400 hover:underline cursor-pointer">Mark all read</button>
                    </div>
                    <div class="max-h-72 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
                        <template x-if="items.length === 0">
                            <div class="px-4 py-8 text-center">
                                <i data-lucide="bell-off" class="w-8 h-8 text-slate-300 dark:text-slate-600 mx-auto mb-2"></i>
                                <p class="text-xs text-slate-400">No new notifications</p>
                            </div>
                        </template>
                        <template x-for="n in items" :key="n.id">
                            <div class="px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors cursor-pointer" @click="markRead(n.id)">
                                <div class="flex items-start gap-2">
                                    <span class="mt-0.5 w-2 h-2 rounded-full shrink-0"
                                          :class="{'bg-blue-500': n.type==='info', 'bg-amber-500': n.type==='warning', 'bg-emerald-500': n.type==='success', 'bg-red-500': n.type==='alert'}"></span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-bold text-slate-800 dark:text-white truncate" x-text="n.title"></p>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 line-clamp-2 mt-0.5" x-text="n.message"></p>
                                        <p class="text-[10px] text-slate-400 mt-1" x-text="n.ago"></p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- User Menu -->
            <div class="relative" x-data="{open: false}">
                <button @click="open = !open" class="flex items-center gap-2 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center text-white text-xs font-bold shadow-md shadow-violet-500/30">
                        <?php echo $user_initials; ?>
                    </div>
                    <div class="hidden md:block text-left">
                        <p class="text-sm font-semibold text-slate-800 dark:text-slate-200"><?php echo htmlspecialchars($current_user['first_name']); ?></p>
                        <p class="text-[10px] text-slate-400 uppercase tracking-wider"><?php echo $role_label; ?></p>
                    </div>
                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 hidden md:block"></i>
                </button>

                <!-- Dropdown -->
                <div x-show="open" @click.outside="open = false" x-transition
                     class="absolute right-0 mt-2 w-56 bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 py-2 z-50">
                    <div class="px-4 py-2 border-b border-slate-100 dark:border-slate-800">
                        <p class="text-sm font-semibold text-slate-800 dark:text-slate-200"><?php echo htmlspecialchars($current_user['first_name'] . ' ' . $current_user['last_name']); ?></p>
                        <p class="text-xs text-slate-400"><?php echo htmlspecialchars($current_user['email']); ?></p>
                    </div>
                    <a href="settings.php" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <i data-lucide="settings" class="w-4 h-4"></i> Settings
                    </a>
                    <a href="../auth/logout.php" class="flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
                        <i data-lucide="log-out" class="w-4 h-4"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

<script>
function notificationBell() {
    return {
        open: false,
        unread: <?= $_notif_count ?>,
        items: <?= json_encode(array_map(function($n) {
            $diff = time() - strtotime($n['created_at']);
            if ($diff < 60) $ago = 'Just now';
            elseif ($diff < 3600) $ago = floor($diff/60) . 'm ago';
            elseif ($diff < 86400) $ago = floor($diff/3600) . 'h ago';
            else $ago = floor($diff/86400) . 'd ago';
            return ['id' => $n['id'], 'title' => $n['title'], 'message' => $n['message'], 'type' => $n['type'], 'ago' => $ago];
        }, $_notif_items)) ?>,
        init() {
            if (typeof lucide !== 'undefined') this.$nextTick(() => lucide.createIcons());
        },
        async markRead(id) {
            const fd = new FormData();
            fd.append('action', 'mark_notification_read');
            fd.append('notification_id', id);
            await fetch('../ajax/notification_api.php', { method: 'POST', body: fd });
            this.items = this.items.filter(n => n.id !== id);
            this.unread = this.items.length;
        },
        async markAllRead() {
            const fd = new FormData();
            fd.append('action', 'mark_all_read');
            await fetch('../ajax/notification_api.php', { method: 'POST', body: fd });
            this.items = [];
            this.unread = 0;
        }
    };
}
</script>

<!-- Collapsed Toolbar (Pattern 244) — Show Menu + Nav Tabs -->
<div id="collapsed-toolbar" class="toolbar-hidden w-full bg-white dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 flex items-center px-4 shrink-0 shadow-sm z-20 gap-1 overflow-x-auto">
    <button id="sidebar-expand-btn" class="flex items-center gap-1.5 py-2 px-3 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 transition-all shrink-0 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800">
        <i data-lucide="menu" class="w-4 h-4"></i>
        <span class="text-xs font-semibold">Menu</span>
    </button>
    <div class="w-px h-6 bg-slate-200 dark:bg-slate-700 shrink-0 mx-1"></div>
    <?php
    // Render nav items as horizontal tabs (reuse $nav_sections from sidebar)
    if (isset($nav_sections)):
        foreach ($nav_sections as $section_name => $section):
            foreach ($section['items'] as $item):
                // Role check
                if ($item['roles'] !== 'all' && !in_array($user_role, $item['roles']) && $user_role !== 'super_admin' && $user_role !== 'business_owner') continue;
                $is_active = ($current_page === $item['href']);
    ?>
    <a href="<?php echo $item['href']; ?>" 
       class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all shrink-0 whitespace-nowrap
       <?php echo $is_active 
           ? 'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300 shadow-sm' 
           : 'text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-700 dark:hover:text-slate-200'; ?>">
        <span class="w-6 h-6 rounded-md bg-gradient-to-br <?php echo $item['gradient']; ?> flex items-center justify-center shadow-sm">
            <i data-lucide="<?php echo $item['icon']; ?>" class="w-3 h-3 text-white"></i>
        </span>
        <span><?php echo $item['label']; ?></span>
    </a>
    <?php
            endforeach;
        endforeach;
    endif;
    ?>
</div>

<!-- Anti-Copy Protection -->
<script src="../assets/js/anti-copy.js"></script>
