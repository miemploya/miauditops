<?php
require_once '../../includes/functions.php';
require_login();
require_subscription('hotel_revenue');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — MiAuditOps Revenue Extractor</title>
    <meta name="description" content="View and manage saved revenue extraction reports.">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- Background Orbs -->
    <div class="bg-orbs">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
    </div>

    <!-- Loading Spinner -->
    <div class="spinner-wrap" id="spinner">
        <div class="spinner"></div>
        <p>Processing…</p>
    </div>

    <!-- Mobile Toggle -->
    <button class="sidebar-toggle" id="sidebarToggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>

    <div class="app-layout">

        <!-- ====== SIDEBAR ====== -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <div style="display:flex;align-items:center;gap:12px;flex:1;overflow:hidden">
                    <div class="logo-box">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div class="brand-text">
                        <div class="brand-name">MiAuditOps</div>
                        <div class="brand-sub">Revenue Extractor</div>
                    </div>
                </div>
                <button class="collapse-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </button>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-label">Main</div>
                <a href="index.php" class="nav-item">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    <span>Booking Extraction</span>
                </a>
                <a href="reports.php" class="nav-item active">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <span>Reports</span>
                </a>
                <a href="overtime.php" class="nav-item">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Audit & Fraud</span>
                </a>
                <div class="nav-label">Quick Links</div>
                <a href="../index.php" class="nav-item" target="_blank">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    <span>MiAuditOps Portal</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="index.php" class="nav-item" style="color:var(--text-muted);font-size:.75rem">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span>Settings</span>
                </a>
                <div class="theme-switch-wrapper">
                    <span>Light Theme</span>
                    <label class="theme-switch" for="themeCheckbox">
                        <input type="checkbox" id="themeCheckbox" onchange="toggleTheme(this.checked)" />
                        <div class="slider"></div>
                    </label>
                </div>
            </div>
        </aside>

        <!-- ====== MAIN CONTENT ====== -->
        <main class="main-content">
            <div class="main-inner">

                <!-- Page Header -->
                <div class="topbar anim">
                    <div class="topbar-left">
                        <h1>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            Saved Reports
                        </h1>
                        <p>View, download, and manage your saved revenue extractions.</p>
                    </div>
                    <div class="topbar-right">
                        <a href="index.php" class="btn btn-primary btn-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            New Import
                        </a>
                    </div>
                </div>

                <!-- Reports List -->
                <div id="reportsList" class="anim-d1"></div>

                <!-- Empty State -->
                <div class="empty anim-d2" id="emptyReports" style="display:none">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <h3>No reports saved yet</h3>
                    <p>Import an Excel file on the Booking Extraction page and click "Save to DB" to create your first report.</p>
                    <a href="index.php" class="btn btn-primary btn-sm" style="margin-top:16px">Go to Booking Extraction</a>
                </div>

                <!-- Report Detail Modal -->
                <div class="modal-overlay" id="reportModal">
                    <div class="modal-box glass" style="max-width:900px;max-height:90vh;overflow-y:auto">
                        <div class="modal-header">
                            <h3 id="reportModalTitle">Report Detail</h3>
                            <button class="fremove" onclick="closeReportModal()">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <div class="modal-body" id="reportModalBody">
                            <p style="color:var(--text-muted)">Loading...</p>
                        </div>
                        <div class="modal-footer" id="reportModalFooter">
                            <button class="btn btn-ghost btn-sm" onclick="closeReportModal()">Close</button>
                            <button class="btn btn-primary btn-sm" id="reportPdfBtn" onclick="">Download PDF</button>
                        </div>
                    </div>
                </div>

            </div>
        </main>

    </div>

    <!-- Dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.2/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script src="reports.js"></script>

    <script>
        // Layout logic
        function toggleSidebar() {
            const sb = document.getElementById('sidebar');
            const layout = document.querySelector('.app-layout');
            sb.classList.toggle('collapsed');
            layout.classList.toggle('collapsed-layout');
            localStorage.setItem('miaudit_sidebar_collapsed', sb.classList.contains('collapsed'));
        }
        function toggleTheme(isLight) {
            if (isLight) {
                document.body.classList.add('light-theme');
                localStorage.setItem('miaudit_theme', 'light');
            } else {
                document.body.classList.remove('light-theme');
                localStorage.setItem('miaudit_theme', 'dark');
            }
        }
        // Init
        document.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('miaudit_sidebar_collapsed') === 'true') {
                document.getElementById('sidebar').classList.add('collapsed');
                document.querySelector('.app-layout').classList.add('collapsed-layout');
            }
            if (localStorage.getItem('miaudit_theme') === 'light') {
                document.body.classList.add('light-theme');
                const toggle = document.getElementById('themeCheckbox');
                if(toggle) toggle.checked = true;
            }
        });
    </script>
</body>
</html>


