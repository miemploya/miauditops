<?php
/**
 * MIAUDITOPS — Global Trash
 * Centralized recycle bin for all deleted items across the application.
 * Items are retained for 60 days before automatic permanent deletion.
 */
require_once '../includes/functions.php';
require_once '../includes/trash_helper.php';
require_login();
require_permission('settings');
$company_id = $_SESSION['company_id'];
$user_id    = $_SESSION['user_id'];
$page_title = 'Trash';

ensure_trash_table($pdo);
purge_expired_trash($pdo);

// Get all trash items for initial load
$trash_items = list_trash($pdo, $company_id);
$js_trash = json_encode($trash_items, JSON_HEX_TAG | JSON_HEX_APOS);

// Get type summary counts
$type_counts = [];
foreach ($trash_items as $item) {
    $t = $item['item_type'];
    $type_counts[$t] = ($type_counts[$t] ?? 0) + 1;
}
$js_type_counts = json_encode($type_counts, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>[x-cloak]{display:none!important}.glass-card{background:linear-gradient(135deg,rgba(255,255,255,0.95) 0%,rgba(249,250,251,0.9) 100%);backdrop-filter:blur(20px)}.dark .glass-card{background:linear-gradient(135deg,rgba(15,23,42,0.95) 0%,rgba(30,41,59,0.9) 100%)}</style>
</head>
<body class="font-sans bg-slate-100 dark:bg-slate-950 h-full" x-data="trashApp()" x-cloak>
<div class="flex h-screen w-full">
    <?php include '../includes/dashboard_sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full overflow-hidden w-full relative">
        <?php include '../includes/dashboard_header.php'; ?>
        <main class="flex-1 overflow-y-auto p-6 lg:p-8">

            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center shadow-lg shadow-red-500/30">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-black text-slate-900 dark:text-white">Trash</h2>
                        <p class="text-xs text-slate-500">Deleted items are kept for <span class="font-bold text-red-500">60 days</span> before permanent removal</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button @click="loadTrash()" class="flex items-center gap-2 px-4 py-2 text-xs font-bold text-slate-600 hover:text-slate-800 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm hover:shadow-md transition-all">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                        Refresh
                    </button>
                    <button x-show="items.length > 0" @click="emptyTrash()" class="flex items-center gap-2 px-4 py-2 text-xs font-bold text-red-600 hover:text-white hover:bg-red-500 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl shadow-sm hover:shadow-md transition-all">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                        Empty Trash
                    </button>
                </div>
            </div>

            <!-- Type Filter Pills -->
            <div class="flex flex-wrap gap-2 mb-6">
                <button @click="filterType = ''" :class="filterType === '' ? 'bg-gradient-to-r from-slate-700 to-slate-900 text-white shadow-lg' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700 hover:bg-slate-50'" class="px-4 py-2 rounded-xl text-xs font-bold transition-all">
                    All <span class="ml-1 opacity-60" x-text="'(' + items.length + ')'"></span>
                </button>
                <template x-for="[type, count] in Object.entries(typeCounts)" :key="type">
                    <button @click="filterType = type" :class="filterType === type ? 'bg-gradient-to-r from-red-500 to-rose-600 text-white shadow-lg shadow-red-500/30' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700 hover:bg-slate-50'" class="px-4 py-2 rounded-xl text-xs font-bold transition-all capitalize">
                        <span x-text="type.replace(/_/g, ' ')"></span> <span class="ml-1 opacity-60" x-text="'(' + count + ')'"></span>
                    </button>
                </template>
            </div>

            <!-- Trash Items Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <template x-for="item in filteredItems" :key="item.id">
                    <div class="glass-card rounded-2xl border border-slate-200/60 dark:border-slate-700/60 shadow-lg overflow-hidden hover:shadow-xl transition-shadow group">
                        <!-- Card Header -->
                        <div class="px-5 py-4">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-md" :class="getTypeGradient(item.item_type)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-slate-800 dark:text-white truncate" x-text="item.item_label"></p>
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 capitalize" x-text="item.item_type.replace(/_/g, ' ')"></p>
                                    </div>
                                </div>
                                <!-- Days remaining badge -->
                                <span class="px-2 py-1 rounded-full text-[10px] font-black whitespace-nowrap"
                                      :class="item.days_remaining <= 7 ? 'bg-red-100 text-red-600 dark:bg-red-900/30' : item.days_remaining <= 30 ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/30' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'"
                                      x-text="item.days_remaining + 'd left'"></span>
                            </div>

                            <!-- Meta info -->
                            <div class="flex items-center gap-4 mb-4">
                                <div class="flex items-center gap-1.5 text-[11px] text-slate-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                    <span x-text="item.deleted_at"></span>
                                </div>
                            </div>

                            <!-- Progress bar showing remaining time -->
                            <div class="w-full h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden mb-1">
                                <div class="h-full rounded-full transition-all duration-500"
                                     :class="item.days_remaining <= 7 ? 'bg-gradient-to-r from-red-400 to-red-600' : item.days_remaining <= 30 ? 'bg-gradient-to-r from-amber-400 to-orange-500' : 'bg-gradient-to-r from-emerald-400 to-teal-500'"
                                     :style="'width:' + Math.max(5, (item.days_remaining / 60) * 100) + '%'"></div>
                            </div>
                            <p class="text-[9px] text-slate-400 text-right" x-text="item.days_remaining + ' of 60 days remaining'"></p>
                        </div>

                        <!-- Action Buttons -->
                        <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/20 flex items-center gap-2">
                            <button @click="restoreItem(item.id, item.item_type)" class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-[11px] font-bold text-emerald-600 hover:text-white hover:bg-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-xl transition-all">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                                Restore
                            </button>
                            <button @click="permanentDelete(item.id)" class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-[11px] font-bold text-red-500 hover:text-white hover:bg-red-500 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl transition-all">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                Delete Forever
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Empty State -->
            <div x-show="filteredItems.length === 0" class="mt-12 text-center">
                <div class="inline-flex w-20 h-20 rounded-full bg-slate-100 dark:bg-slate-800 items-center justify-center mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-slate-300 dark:text-slate-600"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </div>
                <h3 class="text-lg font-bold text-slate-400 dark:text-slate-500 mb-1" x-text="filterType ? 'No ' + filterType.replace(/_/g, ' ') + ' items in trash' : 'Trash is empty'"></h3>
                <p class="text-sm text-slate-400">Deleted items will appear here for 60 days before automatic removal</p>
            </div>

        </main>
    </div>
</div>

<!-- Toast -->
<div id="toast" x-show="toastMsg" x-transition class="fixed bottom-6 right-6 z-[9999] px-5 py-3 rounded-2xl text-sm font-bold shadow-2xl" :class="toastOk ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'" x-text="toastMsg" style="display:none"></div>

<script>
function trashApp() {
    return {
        items: <?php echo $js_trash; ?>,
        typeCounts: <?php echo $js_type_counts; ?>,
        filterType: '',
        toastMsg: '', toastOk: true,

        get filteredItems() {
            if (!this.filterType) return this.items;
            return this.items.filter(i => i.item_type === this.filterType);
        },

        getTypeGradient(type) {
            const map = {
                'audit_session': 'bg-gradient-to-br from-orange-500 to-amber-600',
                'client': 'bg-gradient-to-br from-indigo-500 to-blue-600',
                'outlet': 'bg-gradient-to-br from-blue-500 to-cyan-600',
                'user': 'bg-gradient-to-br from-violet-500 to-purple-600',
                'department': 'bg-gradient-to-br from-emerald-500 to-teal-600',
                'product': 'bg-gradient-to-br from-pink-500 to-rose-600',
                'expense': 'bg-gradient-to-br from-amber-500 to-orange-600',
            };
            return map[type] || 'bg-gradient-to-br from-red-500 to-rose-600';
        },

        async api(action, data = {}) {
            const fd = new FormData();
            fd.append('action', action);
            Object.entries(data).forEach(([k, v]) => fd.append(k, v));
            const res = await fetch('../ajax/trash_api.php', { method: 'POST', body: fd });
            return res.json();
        },

        async loadTrash() {
            const r = await this.api('list_trash');
            if (r.success) {
                this.items = r.items || [];
                this.recountTypes();
            }
        },

        recountTypes() {
            this.typeCounts = {};
            this.items.forEach(i => {
                this.typeCounts[i.item_type] = (this.typeCounts[i.item_type] || 0) + 1;
            });
        },

        async restoreItem(trashId, itemType) {
            if (!confirm('Restore this item? It will be recovered to its original location.')) return;
            try {
                const r = await this.api('restore_item', { trash_id: trashId, item_type: itemType });
                if (r.success) {
                    this.items = this.items.filter(i => i.id != trashId);
                    this.recountTypes();
                    this.toast(r.message || 'Restored successfully');
                } else { this.toast(r.message || 'Restore failed', false); }
            } catch (e) { this.toast('Error: ' + e.message, false); }
        },

        async permanentDelete(trashId) {
            if (!confirm('Permanently delete this item? This cannot be undone.')) return;
            try {
                const r = await this.api('permanent_delete', { trash_id: trashId });
                if (r.success) {
                    this.items = this.items.filter(i => i.id != trashId);
                    this.recountTypes();
                    this.toast('Permanently deleted');
                } else { this.toast(r.message || 'Delete failed', false); }
            } catch (e) { this.toast('Error: ' + e.message, false); }
        },

        async emptyTrash() {
            if (!confirm('Permanently delete ALL items in trash? This cannot be undone!')) return;
            try {
                for (const item of [...this.items]) {
                    await this.api('permanent_delete', { trash_id: item.id });
                }
                this.items = [];
                this.typeCounts = {};
                this.toast('Trash emptied');
            } catch (e) { this.toast('Error: ' + e.message, false); }
        },

        toast(msg, ok = true) {
            this.toastMsg = msg; this.toastOk = ok;
            setTimeout(() => { this.toastMsg = ''; }, 3500);
        },
    };
}
</script>
<?php include '../includes/dashboard_scripts.php'; ?>
</body></html>
