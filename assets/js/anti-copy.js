/**
 * MIAUDITOPS — Anti-Copy & Source Protection
 * Deters casual users from copying content or viewing source code.
 * NOTE: This is a deterrent, not absolute protection. Server-side code (PHP) is already safe.
 */
(function () {
    'use strict';

    // ── 1. Disable Right-Click Context Menu ──
    document.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        return false;
    });

    // ── 2. Block Keyboard Shortcuts ──
    document.addEventListener('keydown', function (e) {
        // Ctrl+U (View Source)
        if (e.ctrlKey && e.key === 'u') { e.preventDefault(); return false; }
        // Ctrl+Shift+I (DevTools Elements)
        if (e.ctrlKey && e.shiftKey && e.key === 'I') { e.preventDefault(); return false; }
        // Ctrl+Shift+J (DevTools Console)
        if (e.ctrlKey && e.shiftKey && e.key === 'J') { e.preventDefault(); return false; }
        // Ctrl+Shift+C (Inspect Element)
        if (e.ctrlKey && e.shiftKey && e.key === 'C') { e.preventDefault(); return false; }
        // F12 (DevTools)
        if (e.key === 'F12') { e.preventDefault(); return false; }
        // Ctrl+S (Save Page)
        if (e.ctrlKey && e.key === 's') { e.preventDefault(); return false; }
        // Ctrl+P (Print) — optional, remove if printing is needed
        if (e.ctrlKey && e.key === 'p') { e.preventDefault(); return false; }
        // Ctrl+A (Select All)
        if (e.ctrlKey && e.key === 'a') {
            // Allow inside input/textarea fields
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return true;
            e.preventDefault(); return false;
        }
        // Ctrl+C (Copy)
        if (e.ctrlKey && e.key === 'c') {
            // Allow inside input/textarea fields
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return true;
            e.preventDefault(); return false;
        }
    });

    // ── 3. Disable Text Selection (CSS-based, applied via JS) ──
    var style = document.createElement('style');
    style.textContent = '' +
        'body { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }' +
        'input, textarea, [contenteditable="true"], select { -webkit-user-select: text; -moz-user-select: text; -ms-user-select: text; user-select: text; }';
    document.head.appendChild(style);

    // ── 4. Disable Drag ──
    document.addEventListener('dragstart', function (e) {
        e.preventDefault();
        return false;
    });

    // ── 5. Disable Copy/Cut Events ──
    document.addEventListener('copy', function (e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return true;
        e.preventDefault();
        return false;
    });
    document.addEventListener('cut', function (e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return true;
        e.preventDefault();
        return false;
    });

    // ── 6. DevTools Detection (console size trick) ──
    var devToolsWarned = false;
    var threshold = 160;
    function checkDevTools() {
        var w = window.outerWidth - window.innerWidth > threshold;
        var h = window.outerHeight - window.innerHeight > threshold;
        if ((w || h) && !devToolsWarned) {
            devToolsWarned = true;
            console.clear();
            console.log('%c⚠️ WARNING', 'font-size:40px;color:red;font-weight:bold;');
            console.log('%cThis browser feature is intended for developers only.\nUnauthorized inspection of this application is prohibited.', 'font-size:14px;color:#333;');
        }
        if (!w && !h) {
            devToolsWarned = false;
        }
    }
    setInterval(checkDevTools, 1000);

    // ── 7. Disable "Save As" via beforeprint ──
    window.addEventListener('beforeprint', function (e) {
        // Optional: you can blank out content during print
        // document.body.style.visibility = 'hidden';
    });

})();
