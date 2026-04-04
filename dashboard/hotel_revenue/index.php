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
    <title>Booking Extraction — MiAuditOps Revenue Extractor</title>
    <meta name="description" content="Import hotel check-in Excel files and extract revenue figures instantly.">
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
        <p>Processing your file…</p>
    </div>

    <!-- Mobile Toggle -->
    <button class="sidebar-toggle" id="sidebarToggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>

    <div class="app-layout">

        <!-- ====== SIDEBAR ====== -->
        <aside class="sidebar" id="sidebar">
            <!-- Brand -->
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

            <!-- Navigation -->
            <nav class="sidebar-nav">
                <div class="nav-label">Main</div>
                <a href="index.php" class="nav-item active">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    <span>Booking Extraction</span>
                </a>
                <a href="reports.php" class="nav-item">
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

            <!-- Sidebar Footer -->
            <div class="sidebar-footer">
                <button class="nav-item" onclick="openSettings()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span>Settings</span>
                </button>
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

                <!-- Settings Modal -->
                <div class="modal-overlay" id="settingsModal">
                    <div class="modal-box glass">
                        <div class="modal-header">
                            <h3>Company Settings</h3>
                            <button class="fremove" onclick="closeSettings()">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Company Name</label>
                                <input type="text" id="settCompanyName" placeholder="e.g. Vertus Hotel" class="input">
                            </div>
                            <div class="form-group">
                                <label>Address</label>
                                <input type="text" id="settAddress" placeholder="Company address" class="input">
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="text" id="settPhone" placeholder="+234..." class="input">
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" id="settEmail" placeholder="info@hotel.com" class="input">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Currency Symbol</label>
                                <input type="text" id="settCurrency" placeholder="₦" class="input" style="width:80px">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-ghost btn-sm" onclick="closeSettings()">Cancel</button>
                            <button class="btn btn-primary btn-sm" onclick="saveSettings()">Save Settings</button>
                        </div>
                    </div>
                </div>

                <!-- Page Header -->
                <div class="topbar anim">
                    <div class="topbar-left">
                        <h1>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                            Booking Extraction
                        </h1>
                        <p>Upload a check-in Excel file to extract revenue figures from room types.</p>
                    </div>
                </div>

                <!-- Upload Zone -->
                <div class="upload-zone anim-d1" id="uploadZone" onclick="document.getElementById('fileInput').click()">
                    <div class="uico">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    </div>
                    <h3>Drop your Excel file here</h3>
                    <p>or click to browse your files</p>
                    <div class="ftypes">
                        <span>.xlsx</span>
                        <span>.xls</span>
                        <span>.csv</span>
                    </div>
                    <input type="file" id="fileInput" accept=".xlsx,.xls,.csv">
                </div>

                <!-- File Info Strip -->
                <div class="file-strip" id="fileStrip">
                    <div class="fico">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="fdet">
                        <div class="fname" id="fileName">—</div>
                        <div class="fmeta" id="fileMeta">—</div>
                    </div>
                    <button class="fremove" onclick="clearFile()" title="Remove file">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <!-- ====== RESULTS ====== -->
                <div class="results" id="results">

                    <!-- Summary Cards -->
                    <div class="sum-grid">
                        <div class="glass sum-card c1">
                            <div class="lbl">Total Revenue</div>
                            <div class="val" id="totalRevenue">₦0</div>
                            <div class="ico-r">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            </div>
                        </div>
                        <div class="glass sum-card c2">
                            <div class="lbl">Total Records</div>
                            <div class="val" id="totalRecords">0</div>
                            <div class="ico-r">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                            </div>
                        </div>
                        <div class="glass sum-card c3">
                            <div class="lbl">Room Types</div>
                            <div class="val" id="totalTypes">0</div>
                            <div class="ico-r">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            </div>
                        </div>
                        <div class="glass sum-card c4">
                            <div class="lbl">Average Rate</div>
                            <div class="val" id="avgRate">₦0</div>
                            <div class="ico-r">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="chart-row">
                        <div class="glass chart-box">
                            <h3>Revenue by Room Type</h3>
                            <div class="chart-wrap">
                                <canvas id="pieChart"></canvas>
                            </div>
                        </div>
                        <div class="glass chart-box">
                            <h3>Booking Count by Room Type</h3>
                            <div class="chart-wrap">
                                <canvas id="barChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Weekly Summary Pivot -->
                    <div style="margin-bottom:24px" id="weeklySection">
                        <div class="sec-title">
                            <h2>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                Weekly Summary
                            </h2>
                            <div class="acts">
                                <span id="weeklyLabel" style="font-size:.78rem;color:var(--text-muted)"></span>
                            </div>
                        </div>
                        <div class="glass" style="padding:0;overflow:hidden">
                            <div class="data-wrap" style="max-height:none">
                                <table class="tbl pivot-tbl" id="weeklyTable">
                                    <thead id="weeklyHead"></thead>
                                    <tbody id="weeklyBody"></tbody>
                                    <tfoot id="weeklyFoot"></tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Room Type Breakdown (Monthly) -->
                    <div style="margin-bottom:24px">
                        <div class="sec-title">
                            <h2>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                                Room Type Breakdown
                            </h2>
                            <div class="acts">
                                <button class="btn btn-primary btn-sm" onclick="saveToDatabase()">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                                    Save to DB
                                </button>
                                <button class="btn btn-ghost btn-sm" onclick="exportPDF()">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    PDF
                                </button>
                                <button class="btn btn-ghost btn-sm" onclick="exportCSV()">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    CSV
                                </button>
                            </div>
                        </div>
                        <div class="glass" style="padding:0;overflow:hidden">
                            <table class="tbl" id="breakdownTable">
                                <thead>
                                    <tr>
                                        <th>Room Type</th>
                                        <th>Bookings</th>
                                        <th>Subtotal</th>
                                        <th>Share</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="breakdownBody"></tbody>
                                <tfoot id="breakdownFoot"></tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Full Data Table -->
                    <div>
                        <div class="sec-title">
                            <h2>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                                All Extracted Records
                            </h2>
                            <div class="acts">
                                <span style="font-size:.78rem;color:var(--text-muted)" id="recordCountLabel"></span>
                            </div>
                        </div>
                        <div class="glass" style="padding:0;overflow:hidden">
                            <div class="data-wrap">
                                <table class="tbl" id="dataTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Original Text</th>
                                            <th>Room Type</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dataBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Empty State -->
                <div class="empty anim-d2" id="emptyState">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    <h3>No data imported yet</h3>
                    <p>Upload an Excel file above to get started with revenue extraction.</p>
                </div>

            </div>
        </main>

    </div>

    <!-- Dependencies -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.2/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script src="app.js"></script>

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


