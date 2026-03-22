<!-- MIAUDITOPS — Dashboard Scripts -->
<style>
    .dark input, .dark textarea, .dark select { color: #f1f5f9 !important; }
    .dark input::placeholder, .dark textarea::placeholder { color: #94a3b8 !important; }
    /* Scroll Arrow Buttons */
    .scroll-arrow-wrap { position: relative; }
    .scroll-arrow {
        position: absolute; top: 50%; transform: translateY(-50%); z-index: 20;
        width: 32px; height: 32px; border-radius: 50%;
        background: linear-gradient(135deg, rgba(255,255,255,0.95), rgba(241,245,249,0.9));
        border: 1px solid rgba(148,163,184,0.3);
        box-shadow: 0 2px 8px rgba(0,0,0,0.12);
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; transition: all 0.2s; opacity: 0.85;
    }
    .scroll-arrow:hover { opacity: 1; transform: translateY(-50%) scale(1.12); box-shadow: 0 4px 14px rgba(0,0,0,0.18); }
    .scroll-arrow.left { left: 4px; }
    .scroll-arrow.right { right: 4px; }
    .scroll-arrow svg { width: 16px; height: 16px; stroke: #475569; stroke-width: 2.5; fill: none; }
    .dark .scroll-arrow { background: linear-gradient(135deg, rgba(30,41,59,0.95), rgba(15,23,42,0.9)); border-color: rgba(71,85,105,0.4); }
    .dark .scroll-arrow svg { stroke: #94a3b8; }
    .scroll-arrow.hidden-arrow { display: none !important; }
</style>
<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Lucide Icons
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // DOM References
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    const desktopCollapseBtn = document.getElementById('sidebar-collapse-btn');
    const sidebarExpandBtn = document.getElementById('sidebar-expand-btn');
    const collapsedToolbar = document.getElementById('collapsed-toolbar');
    const themeToggle = document.getElementById('themeToggle');

    // ==========================================
    // THEME MANAGEMENT
    // ==========================================
    function initTheme() {
        const stored = localStorage.getItem('miauditops_theme');
        if (stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    }
    initTheme();

    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            document.documentElement.classList.toggle('dark');
            const isDark = document.documentElement.classList.contains('dark');
            localStorage.setItem('miauditops_theme', isDark ? 'dark' : 'light');
            setTimeout(() => lucide.createIcons(), 50);
        });
    }

    // ==========================================
    // SIDEBAR MANAGEMENT
    // ==========================================
    
    // Restore sidebar state
    const sidebarCollapsed = localStorage.getItem('miauditops_sidebar_collapsed') === 'true';
    if (sidebarCollapsed && window.innerWidth >= 1024) {
        if (sidebar) {
            sidebar.classList.remove('w-64');
            sidebar.classList.add('w-0', 'overflow-hidden');
        }
        if (collapsedToolbar) {
            collapsedToolbar.classList.replace('toolbar-hidden', 'toolbar-visible');
        }
    }

    // Desktop Collapse
    function toggleSidebar() {
        if (!sidebar) return;
        const isCollapsed = sidebar.classList.contains('w-0');
        
        if (isCollapsed) {
            sidebar.classList.remove('w-0', 'overflow-hidden');
            sidebar.classList.add('w-64');
            if (collapsedToolbar) collapsedToolbar.classList.replace('toolbar-visible', 'toolbar-hidden');
            localStorage.setItem('miauditops_sidebar_collapsed', 'false');
        } else {
            sidebar.classList.remove('w-64');
            sidebar.classList.add('w-0', 'overflow-hidden');
            if (collapsedToolbar) collapsedToolbar.classList.replace('toolbar-hidden', 'toolbar-visible');
            localStorage.setItem('miauditops_sidebar_collapsed', 'true');
        }
    }

    if (desktopCollapseBtn) desktopCollapseBtn.addEventListener('click', toggleSidebar);
    if (sidebarExpandBtn) sidebarExpandBtn.addEventListener('click', toggleSidebar);

    // Mobile Toggle
    function openMobileSidebar() {
        if (sidebar) {
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('translate-x-0');
        }
        if (overlay) overlay.classList.remove('hidden');
    }

    function closeMobileSidebar() {
        if (sidebar) {
            sidebar.classList.remove('translate-x-0');
            sidebar.classList.add('-translate-x-full');
        }
        if (overlay) overlay.classList.add('hidden');
    }

    if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', openMobileSidebar);
    if (overlay) overlay.addEventListener('click', closeMobileSidebar);

    // ==========================================
    // AUTO SENTENCE CASE FOR TEXT INPUTS
    // ==========================================
    const skipTypes = ['password','email','url','number','date','time','datetime-local','month','week','search','hidden','color','range','file','tel'];
    
    function toSentenceCase(str) {
        if (!str) return str;
        // Capitalize first letter, then capitalize after sentence-ending punctuation
        return str.charAt(0).toUpperCase() + str.slice(1).replace(/([.!?]\s+)([a-z])/g, function(match, punct, letter) {
            return punct + letter.toUpperCase();
        });
    }

    document.addEventListener('blur', function(e) {
        const el = e.target;
        if (!el || !el.value) return;
        // Only apply to text inputs and textareas
        const isTextInput = el.tagName === 'INPUT' && !skipTypes.includes(el.type || 'text');
        const isTextarea = el.tagName === 'TEXTAREA';
        if (!isTextInput && !isTextarea) return;
        // Skip if the input has data-no-sentence-case attribute
        if (el.dataset.noSentenceCase !== undefined) return;
        
        const converted = toSentenceCase(el.value);
        if (converted !== el.value) {
            el.value = converted;
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, true);

    // ==========================================
    // HORIZONTAL SCROLL ARROWS FOR TABLES
    // ==========================================
    const chevronLeft = '<svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg>';
    const chevronRight = '<svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>';

    function setupScrollArrows(container) {
        if (container.dataset.scrollArrows === 'done') return;
        container.dataset.scrollArrows = 'done';

        // Use existing parent as the anchor — do NOT reparent (breaks Alpine.js)
        const parent = container.parentNode;
        if (!parent) return;
        parent.style.position = 'relative';

        const leftBtn = document.createElement('button');
        leftBtn.className = 'scroll-arrow left hidden-arrow';
        leftBtn.innerHTML = chevronLeft;
        leftBtn.title = 'Scroll left';
        leftBtn.type = 'button';

        const rightBtn = document.createElement('button');
        rightBtn.className = 'scroll-arrow right hidden-arrow';
        rightBtn.innerHTML = chevronRight;
        rightBtn.title = 'Scroll right';
        rightBtn.type = 'button';

        parent.appendChild(leftBtn);
        parent.appendChild(rightBtn);

        function updateArrows() {
            const { scrollLeft, scrollWidth, clientWidth } = container;
            leftBtn.classList.toggle('hidden-arrow', scrollLeft <= 5);
            rightBtn.classList.toggle('hidden-arrow', scrollLeft + clientWidth >= scrollWidth - 5);
        }

        leftBtn.addEventListener('click', () => { container.scrollBy({ left: -300, behavior: 'smooth' }); });
        rightBtn.addEventListener('click', () => { container.scrollBy({ left: 300, behavior: 'smooth' }); });
        container.addEventListener('scroll', updateArrows);

        // Initial check + recheck after content renders
        updateArrows();
        setTimeout(updateArrows, 500);
        new ResizeObserver(updateArrows).observe(container);
    }

    document.querySelectorAll('.overflow-x-auto').forEach(setupScrollArrows);

    // Also attach to dynamically added containers
    new MutationObserver(function(mutations) {
        mutations.forEach(m => {
            m.addedNodes.forEach(node => {
                if (node.nodeType === 1) {
                    if (node.classList && node.classList.contains('overflow-x-auto')) setupScrollArrows(node);
                    node.querySelectorAll && node.querySelectorAll('.overflow-x-auto').forEach(setupScrollArrows);
                }
            });
        });
    }).observe(document.body, { childList: true, subtree: true });

    // ==========================================
    // PWA — Only on live server (skip localhost)
    // ==========================================
    const _isLocalhost = ['localhost','127.0.0.1','[::1]'].includes(location.hostname) || location.hostname.startsWith('192.168.');
    if (!_isLocalhost) {
        // Dynamic head injection
        if (!document.querySelector('link[rel="manifest"]')) {
            const ml = document.createElement('link'); ml.rel = 'manifest'; ml.href = '/miiauditops/manifest.json'; document.head.appendChild(ml);
        }
        if (!document.querySelector('meta[name="theme-color"]')) {
            const tc = document.createElement('meta'); tc.name = 'theme-color'; tc.content = '#6d28d9'; document.head.appendChild(tc);
        }
        if (!document.querySelector('link[rel="apple-touch-icon"]')) {
            const ai = document.createElement('link'); ai.rel = 'apple-touch-icon'; ai.href = '/miiauditops/assets/images/pwa-icon-512.png'; document.head.appendChild(ai);
        }
        if (!document.querySelector('link[rel="icon"]')) {
            const fi = document.createElement('link'); fi.rel = 'icon'; fi.type = 'image/png'; fi.href = '/miiauditops/assets/images/pwa-icon-512.png'; document.head.appendChild(fi);
        }

        // Service Worker registration
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/miiauditops/service-worker.js', { scope: '/miiauditops/' })
            .then(reg => { console.log('[PWA] Service Worker registered:', reg.scope); })
            .catch(err => { console.warn('[PWA] SW registration failed:', err); });
        }

        // Install Prompt
        let _deferredInstallPrompt = null;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            _deferredInstallPrompt = e;
            const banner = document.createElement('div');
            banner.id = 'pwa-install-banner';
            banner.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:9999;display:flex;align-items:center;gap:12px;padding:14px 20px;background:linear-gradient(135deg,#6d28d9,#7c3aed);color:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(109,40,217,0.4);font-family:Inter,sans-serif;font-size:13px;max-width:420px;width:90%;animation:pwaSlideUp 0.4s ease-out';
            banner.innerHTML = `
                <div style="width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg style="width:20px;height:20px;stroke:#fff;fill:none;stroke-width:2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </div>
                <div style="flex:1">
                    <strong style="font-size:14px">Install MIAUDITOPS</strong><br>
                    <span style="opacity:0.85;font-size:11px">Add to home screen for quick access</span>
                </div>
                <button id="pwa-install-btn" style="padding:8px 16px;background:#fff;color:#6d28d9;border:none;border-radius:10px;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap">Install</button>
                <button id="pwa-dismiss-btn" style="background:none;border:none;color:rgba(255,255,255,0.6);cursor:pointer;font-size:18px;padding:4px;line-height:1">&times;</button>
            `;
            document.body.appendChild(banner);
            const style = document.createElement('style');
            style.textContent = '@keyframes pwaSlideUp{from{opacity:0;transform:translateX(-50%) translateY(100%)}to{opacity:1;transform:translateX(-50%) translateY(0)}}';
            document.head.appendChild(style);

            document.getElementById('pwa-install-btn').addEventListener('click', async () => {
                banner.remove();
                _deferredInstallPrompt.prompt();
                const { outcome } = await _deferredInstallPrompt.userChoice;
                console.log('[PWA] Install outcome:', outcome);
                _deferredInstallPrompt = null;
            });
            document.getElementById('pwa-dismiss-btn').addEventListener('click', () => {
                banner.remove();
                localStorage.setItem('miauditops_pwa_dismissed', Date.now());
            });

            const dismissed = localStorage.getItem('miauditops_pwa_dismissed');
            if (dismissed && (Date.now() - parseInt(dismissed)) < 7 * 24 * 60 * 60 * 1000) {
                banner.remove();
            }
        });
    } // end !_isLocalhost
});
</script>

