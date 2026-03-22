<?php
/**
 * MIAUDITOPS — Owner Portal: Testimonials Manager
 */
session_start();
if (empty($_SESSION['is_platform_owner'])) { header('Location: login.php'); exit; }
$owner_name = $_SESSION['owner_name'] ?? 'Owner';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Testimonials — Owner Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="font-sans bg-slate-950 text-white min-h-screen" x-data="testimonialsApp()" x-init="init()">

    <!-- Sidebar -->
    <aside class="fixed inset-y-0 left-0 w-64 bg-slate-900 border-r border-slate-800 flex flex-col z-40">
        <div class="p-5 border-b border-slate-800">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-600 to-orange-600 flex items-center justify-center shadow-lg shadow-red-600/30">
                    <i data-lucide="shield-check" class="w-5 h-5 text-white"></i>
                </div>
                <div>
                    <h1 class="text-sm font-black tracking-tight">Owner Portal</h1>
                    <p class="text-[10px] text-slate-500 uppercase tracking-wider">Platform Admin</p>
                </div>
            </div>
        </div>
        <nav class="flex-1 p-3 space-y-1">
            <a href="index.php" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-slate-400 hover:bg-slate-800 hover:text-slate-200 transition-all">
                <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard
            </a>
            <div class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold bg-red-600/20 text-red-400">
                <i data-lucide="message-square-quote" class="w-4 h-4"></i> Testimonials
            </div>
        </nav>
        <div class="p-4 border-t border-slate-800">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-red-600 to-orange-600 flex items-center justify-center text-white text-xs font-bold"><?php echo strtoupper(substr($owner_name, 0, 2)); ?></div>
                <div>
                    <p class="text-xs font-bold text-slate-200"><?php echo htmlspecialchars($owner_name); ?></p>
                    <p class="text-[10px] text-slate-500">Platform Owner</p>
                </div>
            </div>
            <a href="logout.php" class="flex items-center gap-2 px-3 py-2 text-xs text-red-400 hover:bg-red-900/20 rounded-lg transition-all font-semibold">
                <i data-lucide="log-out" class="w-3.5 h-3.5"></i> Sign Out
            </a>
        </div>
    </aside>

    <!-- Main -->
    <main class="ml-64 min-h-screen">
        <header class="sticky top-0 z-20 bg-slate-950/80 backdrop-blur-xl border-b border-slate-800 px-6 py-4 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold">Testimonials</h2>
                <p class="text-xs text-slate-500">Manage homepage testimonials</p>
            </div>
            <button @click="openForm()" class="px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl text-sm shadow-lg shadow-emerald-600/30 hover:scale-[1.02] transition-all flex items-center gap-2">
                <i data-lucide="plus-circle" class="w-4 h-4"></i> Add Testimonial
            </button>
        </header>

        <div class="p-6">
            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                    <p class="text-xs text-slate-500 font-semibold uppercase mb-1">Total</p>
                    <p class="text-3xl font-black" x-text="items.length">0</p>
                </div>
                <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                    <p class="text-xs text-emerald-400 font-semibold uppercase mb-1">Active</p>
                    <p class="text-3xl font-black text-emerald-400" x-text="items.filter(i=>i.is_active==1).length">0</p>
                </div>
                <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5">
                    <p class="text-xs text-slate-500 font-semibold uppercase mb-1">Hidden</p>
                    <p class="text-3xl font-black text-slate-500" x-text="items.filter(i=>i.is_active==0).length">0</p>
                </div>
            </div>

            <!-- Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <template x-for="item in items" :key="item.id">
                    <div class="bg-slate-900 rounded-2xl border border-slate-800 p-5 flex flex-col relative group hover:border-slate-700 transition-all"
                         :class="item.is_active == 0 ? 'opacity-50' : ''">
                        <!-- Actions -->
                        <div class="absolute top-3 right-3 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button @click="openForm(item)" class="p-1.5 rounded-lg bg-slate-800 text-slate-400 hover:text-blue-400 hover:bg-blue-900/20 transition-all" title="Edit">
                                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                            </button>
                            <button @click="toggleActive(item)" class="p-1.5 rounded-lg bg-slate-800 text-slate-400 hover:text-amber-400 hover:bg-amber-900/20 transition-all" :title="item.is_active==1?'Hide':'Show'">
                                <i :data-lucide="item.is_active==1?'eye-off':'eye'" class="w-3.5 h-3.5"></i>
                            </button>
                            <button @click="deleteItem(item.id)" class="p-1.5 rounded-lg bg-slate-800 text-slate-400 hover:text-red-400 hover:bg-red-900/20 transition-all" title="Delete">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                        <!-- Photo -->
                        <div class="w-20 h-20 rounded-full mx-auto mb-4 overflow-hidden border-2 border-violet-500/30 bg-slate-800 flex items-center justify-center">
                            <template x-if="item.photo">
                                <img :src="'../' + item.photo" class="w-full h-full object-cover" :alt="item.name">
                            </template>
                            <template x-if="!item.photo">
                                <span class="text-2xl font-black text-slate-600" x-text="(item.name||'?').charAt(0).toUpperCase()"></span>
                            </template>
                        </div>
                        <!-- Info -->
                        <h4 class="text-sm font-bold text-white text-center mb-0.5" x-text="item.name"></h4>
                        <p class="text-[10px] text-violet-400 font-semibold text-center mb-3" x-text="item.title || ''"></p>
                        <p class="text-xs text-slate-400 text-center leading-relaxed line-clamp-4 flex-1" x-text="item.testimony"></p>
                        <div class="flex items-center justify-between mt-3 pt-3 border-t border-slate-800">
                            <span class="text-[10px] text-slate-500">Order: <span x-text="item.sort_order" class="font-bold"></span></span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold" :class="item.is_active==1?'bg-emerald-500/20 text-emerald-400':'bg-slate-500/20 text-slate-500'" x-text="item.is_active==1?'Active':'Hidden'"></span>
                        </div>
                    </div>
                </template>
            </div>
            <template x-if="items.length === 0 && !loading">
                <div class="text-center py-16">
                    <i data-lucide="message-square-quote" class="w-12 h-12 text-slate-700 mx-auto mb-3"></i>
                    <p class="text-sm text-slate-500">No testimonials yet. Click "Add Testimonial" to create one.</p>
                </div>
            </template>
        </div>
    </main>

    <!-- Add/Edit Modal -->
    <div x-show="formOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="formOpen=false">
        <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-lg p-6 mx-4" @click.stop>
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-lg font-bold" x-text="form.id ? 'Edit Testimonial' : 'Add Testimonial'"></h3>
                <button @click="formOpen=false" class="p-1 text-slate-400 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>

            <div class="space-y-4">
                <!-- Photo Preview & Upload -->
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-full overflow-hidden border-2 border-slate-700 bg-slate-800 flex items-center justify-center shrink-0">
                        <template x-if="photoPreview">
                            <img :src="photoPreview" class="w-full h-full object-cover">
                        </template>
                        <template x-if="!photoPreview && form.photo">
                            <img :src="'../' + form.photo" class="w-full h-full object-cover">
                        </template>
                        <template x-if="!photoPreview && !form.photo">
                            <i data-lucide="user" class="w-6 h-6 text-slate-600"></i>
                        </template>
                    </div>
                    <div class="flex-1">
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Photo</label>
                        <input type="file" accept="image/*" @change="previewPhoto($event)" class="text-xs text-slate-400 file:mr-2 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-violet-500/20 file:text-violet-400 hover:file:bg-violet-500/30 cursor-pointer">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Full Name *</label>
                        <input type="text" x-model="form.name" placeholder="John Doe" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-sm text-white focus:outline-none focus:border-violet-500 transition-all">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Title / Role</label>
                        <input type="text" x-model="form.title" placeholder="CEO, Acme Ltd" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-sm text-white focus:outline-none focus:border-violet-500 transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Testimony *</label>
                    <textarea x-model="form.testimony" rows="4" placeholder="What the user said about the application..." class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-sm text-white focus:outline-none focus:border-violet-500 transition-all resize-none"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Sort Order</label>
                    <input type="number" x-model.number="form.sort_order" min="0" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-sm text-white focus:outline-none focus:border-violet-500 transition-all">
                    <p class="text-[10px] text-slate-500 mt-1">Lower numbers appear first</p>
                </div>

                <button @click="save()" :disabled="saving || !form.name?.trim() || !form.testimony?.trim()" class="w-full px-4 py-3 bg-gradient-to-r from-violet-600 to-purple-600 text-white rounded-xl text-sm font-bold shadow-lg shadow-violet-600/30 hover:scale-[1.02] transition-all disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                    <span x-text="saving ? 'Saving...' : (form.id ? 'Update Testimonial' : 'Add Testimonial')"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div x-show="toast.show" x-cloak x-transition
         class="fixed bottom-6 right-6 z-50 px-5 py-3 rounded-xl shadow-2xl text-sm font-semibold flex items-center gap-2"
         :class="toast.type==='success' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'">
        <i :data-lucide="toast.type==='success'?'check-circle':'alert-circle'" class="w-4 h-4"></i>
        <span x-text="toast.message"></span>
    </div>

<script>
function testimonialsApp() {
    return {
        items: [],
        loading: false,
        formOpen: false,
        saving: false,
        photoPreview: null,
        photoFile: null,
        form: { id: 0, name: '', title: '', testimony: '', photo: null, sort_order: 0 },
        toast: { show: false, message: '', type: 'success' },

        async init() {
            await this.load();
            this.$nextTick(() => lucide.createIcons());
        },

        async load() {
            this.loading = true;
            try {
                const r = await fetch('owner_api.php?action=list_testimonials');
                const d = await r.json();
                if (d.success) this.items = d.data;
            } catch (e) { console.error(e); }
            this.loading = false;
            this.$nextTick(() => lucide.createIcons());
        },

        openForm(item = null) {
            this.photoPreview = null;
            this.photoFile = null;
            if (item) {
                this.form = { id: item.id, name: item.name, title: item.title || '', testimony: item.testimony, photo: item.photo, sort_order: item.sort_order || 0 };
            } else {
                this.form = { id: 0, name: '', title: '', testimony: '', photo: null, sort_order: 0 };
            }
            this.formOpen = true;
            this.$nextTick(() => lucide.createIcons());
        },

        previewPhoto(e) {
            const file = e.target.files[0];
            if (!file) return;
            this.photoFile = file;
            this.photoPreview = URL.createObjectURL(file);
        },

        async save() {
            if (!this.form.name?.trim() || !this.form.testimony?.trim()) return;
            this.saving = true;
            try {
                const fd = new FormData();
                fd.append('action', 'save_testimonial');
                fd.append('id', this.form.id || 0);
                fd.append('name', this.form.name);
                fd.append('title', this.form.title || '');
                fd.append('testimony', this.form.testimony);
                fd.append('sort_order', this.form.sort_order || 0);
                if (this.photoFile) fd.append('photo', this.photoFile);

                const r = await fetch('owner_api.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.success) {
                    this.showToast(d.message, 'success');
                    this.formOpen = false;
                    await this.load();
                } else {
                    this.showToast(d.message || 'Error', 'error');
                }
            } catch (e) {
                this.showToast('Network error', 'error');
            }
            this.saving = false;
        },

        async toggleActive(item) {
            const newVal = item.is_active == 1 ? 0 : 1;
            const fd = new FormData();
            fd.append('action', 'toggle_testimonial');
            fd.append('id', item.id);
            fd.append('is_active', newVal);
            try {
                const r = await fetch('owner_api.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.success) {
                    item.is_active = newVal;
                    this.showToast(newVal ? 'Testimonial shown' : 'Testimonial hidden', 'success');
                }
            } catch (e) {}
            this.$nextTick(() => lucide.createIcons());
        },

        async deleteItem(id) {
            if (!confirm('Delete this testimonial?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_testimonial');
            fd.append('id', id);
            try {
                const r = await fetch('owner_api.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.success) {
                    this.showToast('Testimonial deleted', 'success');
                    await this.load();
                }
            } catch (e) {}
        },

        showToast(msg, type = 'success') {
            this.toast = { show: true, message: msg, type };
            this.$nextTick(() => lucide.createIcons());
            setTimeout(() => this.toast.show = false, 3000);
        }
    };
}
</script>
</body>
</html>
