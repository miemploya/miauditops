/**
 * MIAUDITOPS — Theme Toggle (Dark/Light)
 * Persists preference in localStorage. Defaults to dark.
 */
(function () {
    const STORAGE_KEY = 'miauditops-theme';

    function getPreferred() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) return saved;
        return 'light'; // default
    }

    function applyTheme(theme) {
        const html = document.documentElement;
        if (theme === 'dark') {
            html.classList.add('dark');
        } else {
            html.classList.remove('dark');
        }
        localStorage.setItem(STORAGE_KEY, theme);
        updateToggleIcons(theme);
    }

    function updateToggleIcons(theme) {
        document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
            const sunIcon = btn.querySelector('.icon-sun');
            const moonIcon = btn.querySelector('.icon-moon');
            if (sunIcon && moonIcon) {
                if (theme === 'dark') {
                    sunIcon.style.display = 'block';
                    moonIcon.style.display = 'none';
                } else {
                    sunIcon.style.display = 'none';
                    moonIcon.style.display = 'block';
                }
            }
        });
    }

    function toggle() {
        const current = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
        applyTheme(current === 'dark' ? 'light' : 'dark');
    }

    // Apply immediately (before DOM ready) to prevent flash
    applyTheme(getPreferred());

    // Bind click handlers once DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
        updateToggleIcons(getPreferred());
        document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
            btn.addEventListener('click', toggle);
        });
    });

    // Expose globally
    window.toggleTheme = toggle;
})();
