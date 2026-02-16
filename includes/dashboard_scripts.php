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
});
</script>
