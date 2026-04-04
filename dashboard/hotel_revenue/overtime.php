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
    <title>Overtime Audit — MiAuditOps Revenue Extractor</title>
    <meta name="description"
        content="Audit guest overtime stays and calculate expected remittance from late checkouts.">
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
        <p>Analyzing checkout times…</p>
    </div>

    <!-- Mobile Toggle -->
    <button class="sidebar-toggle" id="sidebarToggle"
        onclick="document.getElementById('sidebar').classList.toggle('open')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="12" x2="21" y2="12" />
            <line x1="3" y1="6" x2="21" y2="6" />
            <line x1="3" y1="18" x2="21" y2="18" />
        </svg>
    </button>

    <div class="app-layout">

        <!-- ====== SIDEBAR ====== -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <div style="display:flex;align-items:center;gap:12px;flex:1;overflow:hidden">
                    <div class="logo-box">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                        </svg>
                    </div>
                    <div class="brand-text">
                        <div class="brand-name">MiAuditOps</div>
                        <div class="brand-sub">Revenue Extractor</div>
                    </div>
                </div>
                <button class="collapse-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-label">Main</div>
                <a href="index.php" class="nav-item">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                        <line x1="3" y1="9" x2="21" y2="9" />
                        <line x1="9" y1="21" x2="9" y2="9" />
                    </svg>
                    <span>Booking Extraction</span>
                </a>
                <a href="overtime.php" class="nav-item active">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <polyline points="12 6 12 12 16 14" />
                    </svg>
                    <span>Audit & Imports</span>
                </a>
                <a href="reports.php" class="nav-item">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                        <polyline points="14 2 14 8 20 8" />
                        <line x1="16" y1="13" x2="8" y2="13" />
                        <line x1="16" y1="17" x2="8" y2="17" />
                    </svg>
                    <span>Reports</span>
                </a>
                <div class="nav-label">Quick Links</div>
                <a href="../index.php" class="nav-item" target="_blank">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                        <polyline points="15 3 21 3 21 9" />
                        <line x1="10" y1="14" x2="21" y2="3" />
                    </svg>
                    <span>MiAuditOps Portal</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="index.php" class="nav-item" style="font-size:.8rem">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" />
                        <path
                            d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                    </svg>
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

            <!-- ====== ACTIVE AUDIT HEADER ====== -->
            <div class="audit-header" id="auditHeader">
                <div class="audit-id">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                    </svg>
                    <span id="auditIdText">AUDIT-XXXXX</span>
                </div>
                <div class="audit-actions" style="display:flex;gap:8px;align-items:center">
                    <select id="investigationStatus" onchange="updateInvestigationStatus(this.value)" style="background:var(--bg-glass);border:1px solid var(--border);color:var(--text-main);padding:5px 10px;border-radius:8px;font-size:.75rem;font-weight:600;cursor:pointer">
                        <option value="under_investigation">🔍 Under Investigation</option>
                        <option value="concluded">✅ Concluded</option>
                        <option value="final">📋 Final Report</option>
                    </select>
                    <button class="btn btn-sm" onclick="saveAuditState(true)"
                        style="background:var(--bg-glass);border:1px solid var(--border)">Save Progress</button>
                    <button class="btn btn-sm" onclick="clearLocalSession()"
                        style="background:rgba(239,83,80,0.12);color:#ef5350;border:1px solid rgba(239,83,80,0.2)">Close
                        Session</button>
                </div>
            </div>

            <div class="main-inner">

                <!-- Page Header -->
                <div class="topbar anim">
                    <div class="topbar-left">
                        <h1>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10" />
                                <polyline points="12 6 12 12 16 14" />
                            </svg>
                            Audit & Double Sales Analysis
                        </h1>
                        <p>Analyze late checkouts, calculate expected remittance, and detect double-sold rooms.</p>
                    </div>
                </div>

                <!-- Upload Zone -->
                <div class="upload-zone anim-d1" id="uploadZone" onclick="document.getElementById('fileInput').click()">
                    <div class="uico">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="17 8 12 3 7 8" />
                            <line x1="12" y1="3" x2="12" y2="15" />
                        </svg>
                    </div>
                    <h3>Drop your check-in Excel file here</h3>
                    <p>Must contain "Room", "Time of check-in & check-out", and "Default Check-Out Time" columns</p>
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
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                            <polyline points="14 2 14 8 20 8" />
                        </svg>
                    </div>
                    <div class="fdet">
                        <div class="fname" id="fileName">—</div>
                        <div class="fmeta" id="fileMeta">—</div>
                    </div>
                    <button class="fremove" onclick="clearFile()" title="Remove file">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>

                <!-- ====== SAVED INVESTIGATIONS ====== -->
                <div id="savedInvestigationsPanel" style="margin-bottom:24px">
                    <div class="sec-title" style="cursor:pointer" onclick="toggleSection('savedInvWrap', this)">
                        <h2>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                            </svg>
                            Saved Investigations
                            <span class="inv-count-badge" id="invCountBadge">0</span>
                        </h2>
                        <div class="acts">
                            <svg class="chevron rotate-up" width="20" height="20" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9" />
                            </svg>
                        </div>
                    </div>
                    <div class="col-wrap open" id="savedInvWrap">
                        <div id="savedInvGrid" class="inv-grid">
                            <!-- Cards rendered by JS -->
                        </div>
                        <div class="inv-empty" id="invEmpty">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                            </svg>
                            <p>No saved investigations yet. Upload a check-in file to start your first audit case.</p>
                        </div>
                    </div>
                </div>

                <!-- ====== RESULTS ====== -->
                <div class="results" id="results">

                    <!-- Audit Summary Cards -->
                    <div class="sum-grid">
                        <div class="glass sum-card c1">
                            <div class="lbl">Total Records</div>
                            <div class="val" id="otTotalRecords">0</div>
                            <div class="ico-r">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                                </svg>
                            </div>
                        </div>
                        <div class="glass sum-card c3">
                            <div class="lbl">Overtime Guests</div>
                            <div class="val" id="otOvertimeCount">0</div>
                            <div class="ico-r">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <polyline points="12 6 12 12 16 14" />
                                </svg>
                            </div>
                        </div>
                        <div class="glass sum-card c4">
                            <div class="lbl">Total Overtime Hours</div>
                            <div class="val" id="otTotalHours">0h</div>
                            <div class="ico-r">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <polyline points="12 6 12 12 16 14" />
                                </svg>
                            </div>
                        </div>
                        <div class="glass sum-card" style="border-top:3px solid #ffba08">
                            <div class="lbl">Double Sold Rooms</div>
                            <div class="val" id="dsTotalCount" style="color:#ffba08">0</div>
                            <div class="ico-r" style="background:rgba(255,186,8,.1);color:#ffba08">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                </svg>
                            </div>
                        </div>
                        <div class="glass sum-card" style="position:relative;overflow:hidden">
                            <div
                                style="position:absolute;top:0;left:0;right:0;height:3px;border-radius:16px 16px 0 0;background:linear-gradient(90deg,#ef5350,#ff6b9d)">
                            </div>
                            <div class="lbl">Total Double Sales Value</div>
                            <div class="val" style="color:#ef5350" id="TotalFraudAmount">₦0</div>
                            <div class="ico-r" style="background:rgba(239,83,80,.1);color:#ef5350">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Sales Reconciliation Calculator -->
                    <div style="margin-bottom:24px" id="reconSection">
                        <div class="sec-title" style="cursor:pointer" onclick="toggleSection('reconWrap', this)">
                            <h2>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <rect x="2" y="4" width="20" height="16" rx="2" ry="2" />
                                    <line x1="12" y1="4" x2="12" y2="20" />
                                    <polyline points="8 15 12 11 16 15" />
                                </svg>
                                Audit Reconciliation
                            </h2>
                            <div class="acts">
                                <svg class="chevron rotate-up" width="20" height="20" xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 12 15 18 9" />
                                </svg>
                            </div>
                        </div>
                        <div class="glass col-wrap open" id="reconWrap" style="padding:20px;max-height:none">
                            <div class="recon-grid">
                                <!-- Col 1: Expected Revenue -->
                                <div class="recon-box">
                                    <h3>1. System Expected</h3>
                                    <div class="r-row"><span>Base Room Revenue</span><span id="reconBase">₦0</span>
                                    </div>
                                    <div class="r-row"><span>Overtime Charges</span><span id="reconOT"
                                            style="color:#ffba08">₦0</span></div>
                                    <div class="r-row"><span>Double Sales</span><span id="reconFraud"
                                            style="color:#ef5350">₦0</span></div>
                                    <div class="r-divider"></div>
                                    <div class="r-total"><span>Total System Base</span><span
                                            id="reconSysTotal">₦0</span></div>
                                </div>

                                <!-- Col 2: Declared Tenders -->
                                <div class="recon-box">
                                    <h3>2. Declared Tenders</h3>
                                    <div class="r-input-row">
                                        <label>Cash</label>
                                        <input type="number" id="reconCash" placeholder="0" oninput="calculateRecon()">
                                    </div>
                                    <div class="r-input-row">
                                        <label>POS</label>
                                        <input type="number" id="reconPOS" placeholder="0" oninput="calculateRecon()">
                                    </div>
                                    <div class="r-input-row">
                                        <label>Transfer</label>
                                        <input type="number" id="reconTransfer" placeholder="0"
                                            oninput="calculateRecon()">
                                    </div>
                                    <div class="r-divider"></div>
                                    <div class="r-total"><span>Total Declared</span><span id="reconDeclaredTotal"
                                            style="color:#66bb6a">₦0</span></div>
                                </div>

                                <!-- Col 3: Adjustments -->
                                <div class="recon-box">
                                    <h3>3. Adjustments</h3>
                                    <div id="adjList"></div>
                                    <div class="adj-controls">
                                        <div style="display:flex;gap:5px">
                                            <input type="text" id="adjLabel" placeholder="E.g. Spillover"
                                                style="flex:2">
                                            <select id="adjType" style="flex:1">
                                                <option value="-">Less (-)</option>
                                                <option value="+">Add (+)</option>
                                            </select>
                                        </div>
                                        <div style="display:flex;gap:5px;margin-top:5px">
                                            <input type="number" id="adjAmt" placeholder="Amount" style="flex:2">
                                            <button class="btn btn-sm" onclick="addAdjustment()"
                                                style="flex:1">Add</button>
                                        </div>
                                    </div>
                                    <div class="r-divider"></div>
                                    <div class="r-total"><span>Net Adjusted Expected</span><span id="reconNetExpected"
                                            style="color:#ffa726">₦0</span></div>
                                </div>
                            </div>

                            <!-- Master Variance -->
                            <div class="recon-variance" id="reconVarianceBox">
                                <div class="v-label">AUDIT VARIANCE (SHORTAGE / SURPLUS)</div>
                                <div class="v-amount" id="reconVarianceAmt">₦0</div>
                                <div class="v-status" id="reconVarianceStatus">BALANCED</div>
                            </div>
                        </div>
                    </div>

                    <!-- Double Sales Records Table -->
                    <div style="margin-bottom:24px">
                        <div class="sec-title" style="cursor:pointer" onclick="toggleSection('doubleSalesWrap', this)">
                            <h2 style="color:#ffba08">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                </svg>
                                Double Sold Rooms (Same Day Check-ins)
                            </h2>
                            <div class="acts">
                                <svg class="chevron rotate-up" width="20" height="20" xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 12 15 18 9" />
                                </svg>
                            </div>
                        </div>
                        <div class="glass col-wrap open" id="doubleSalesWrap">
                            <div class="data-wrap" style="max-height:none">
                                <table class="tbl" id="doubleSalesTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Room #</th>
                                            <th># Times Sold</th>
                                            <th>Room Type</th>
                                            <th>Check-In Logs</th>
                                            <th style="text-align:center">DS Count</th>
                                            <th style="text-align:right">Total DS Value</th>
                                            <th class="amt">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="doubleSalesBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Risk Analysis Summary -->
                    <div style="margin-bottom:24px" id="riskSection">
                        <div class="sec-title">
                            <h2>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path
                                        d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                    <line x1="12" y1="9" x2="12" y2="13" />
                                    <line x1="12" y1="17" x2="12.01" y2="17" />
                                </svg>
                                Overtime Risk Analysis
                            </h2>
                            <div class="acts" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                                <div class="pdf-settings" style="display:flex;gap:15px;align-items:center;background:var(--bg-glass);padding:8px 12px;border-radius:8px;border:1px solid var(--border)">
                                    <div style="display:flex;flex-direction:column;gap:4px">
                                        <label for="pdfReportTitle" style="font-size:9px;color:var(--text-muted);font-weight:700;letter-spacing:0.5px">EDIT COVER TITLE</label>
                                        <input type="text" id="pdfReportTitle" value="AUDIT & DOUBLE SALES REPORT" placeholder="Type title here..." style="background:#ffffff;border:1px solid #ccc;color:#333;padding:4px 8px;border-radius:4px;font-size:.75rem;width:220px;font-weight:600">
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:center" title="Cover Background Color">
                                        <label for="pdfBgColor" style="font-size:9px;color:var(--text-muted);font-weight:700;letter-spacing:0.5px">BG COLOR</label>
                                        <input type="color" id="pdfBgColor" value="#0f0f23" style="width:26px;height:26px;padding:0;border:none;border-radius:4px;cursor:pointer">
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:center" title="Cover Text Color">
                                        <label for="pdfTextColor" style="font-size:9px;color:var(--text-muted);font-weight:700;letter-spacing:0.5px">TEXT COLOR</label>
                                        <input type="color" id="pdfTextColor" value="#ffffff" style="width:26px;height:26px;padding:0;border:none;border-radius:4px;cursor:pointer">
                                    </div>
                                </div>
                                <button class="btn btn-ghost btn-sm" onclick="exportOvertimePDF()">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path
                                            d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                                        <polyline points="14 2 14 8 20 8" />
                                    </svg>
                                    Export Full PDF Report
                                </button>
                            </div>
                        </div>
                        <div class="sum-grid" style="grid-template-columns: repeat(auto-fit,minmax(250px,1fr))">
                            <div class="glass sum-card" style="border-top:3px solid #66bb6a">
                                <div class="lbl">Minor Risk (≤ 4 hrs)</div>
                                <div class="val" id="riskMinorCount">0</div>
                                <div style="color:var(--text-muted);font-size:.78rem;margin-top:8px;font-weight:600">
                                    Expected: <span id="riskMinorAmt" style="color:#66bb6a;font-size:.85rem">₦0</span>
                                </div>
                                <div class="ico-r" style="background:rgba(102,187,106,.1);color:#66bb6a"><svg
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10" />
                                        <polyline points="12 6 12 12 16 14" />
                                    </svg></div>
                            </div>
                            <div class="glass sum-card" style="border-top:3px solid #ffa726">
                                <div class="lbl">Warning (4 - 12 hrs)</div>
                                <div class="val" id="riskWarningCount">0</div>
                                <div style="color:var(--text-muted);font-size:.78rem;margin-top:8px;font-weight:600">
                                    Expected: <span id="riskWarningAmt" style="color:#ffa726;font-size:.85rem">₦0</span>
                                </div>
                                <div class="ico-r" style="background:rgba(255,167,38,.1);color:#ffa726"><svg
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path
                                            d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                        <line x1="12" y1="9" x2="12" y2="13" />
                                        <line x1="12" y1="17" x2="12.01" y2="17" />
                                    </svg></div>
                            </div>
                            <div class="glass sum-card" style="border-top:3px solid #ef5350">
                                <div class="lbl">Critical Risk (> 12 hrs)</div>
                                <div class="val" id="riskCriticalCount">0</div>
                                <div style="color:var(--text-muted);font-size:.78rem;margin-top:8px;font-weight:600">
                                    Expected: <span id="riskCriticalAmt"
                                        style="color:#ef5350;font-size:.85rem">₦0</span></div>
                                <div class="ico-r" style="background:rgba(239,83,80,.1);color:#ef5350"><svg
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <polygon
                                            points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2" />
                                        <line x1="15" y1="9" x2="9" y2="15" />
                                        <line x1="9" y1="9" x2="15" y2="15" />
                                    </svg></div>
                            </div>
                        </div>
                    </div>

                    <!-- Overtime Records Table -->
                    <div style="margin-bottom:24px">
                        <div class="sec-title" style="cursor:pointer" onclick="toggleSection('overtimeWrap', this)">
                            <h2>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <polyline points="12 6 12 12 16 14" />
                                </svg>
                                Overtime Records
                            </h2>
                            <div class="acts">
                                <svg class="chevron rotate-up" width="20" height="20" xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 12 15 18 9" />
                                </svg>
                            </div>
                        </div>
                        <div class="glass col-wrap open" id="overtimeWrap">
                            <div class="data-wrap" style="max-height:none">
                                <table class="tbl" id="overtimeTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Room Type</th>
                                            <th>Check-In</th>
                                            <th>Expected Checkout</th>
                                            <th>Actual Checkout</th>
                                            <th>Overtime</th>
                                            <th>Room Rate</th>
                                            <th>OT Charge</th>
                                            <th>Status</th>
                                            <th class="amt">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="overtimeBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- All Records Table -->
                    <div>
                        <div class="sec-title" style="cursor:pointer" onclick="toggleSection('allWrap', this)">
                            <h2>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                                    <line x1="3" y1="9" x2="21" y2="9" />
                                    <line x1="9" y1="21" x2="9" y2="9" />
                                </svg>
                                All Records
                            </h2>
                            <div class="acts">
                                <span style="font-size:.78rem;color:var(--text-muted);margin-right:10px"
                                    id="allCountLabel"></span>
                                <svg class="chevron rotate-up" width="20" height="20" xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 12 15 18 9" />
                                </svg>
                            </div>
                        </div>
                        <div class="glass col-wrap open" id="allWrap">
                            <div class="data-wrap" style="max-height:500px;overflow-y:auto">
                                <table class="tbl" id="allTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Room Type</th>
                                            <th>Check-In</th>
                                            <th>Expected Out</th>
                                            <th>Actual Out</th>
                                            <th>Diff</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="allBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Empty State -->
                <div class="empty anim-d2" id="emptyState">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <polyline points="12 6 12 12 16 14" />
                    </svg>
                    <h3>Ready to audit</h3>
                    <p>Upload a check-in Excel file to detect overtime stays and calculate expected remittance.</p>
                </div>

            </div>
        </main>

    </div>

    <!-- Rectify Modal -->
    <div class="modal-overlay" id="rectifyModal">
        <div class="rectify-modal">
            <div class="rm-header">
                <h3>Rectify Finding</h3>
                <div class="rm-close" onclick="closeRectifyModal()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </div>
            </div>
            <div class="rm-body">
                <input type="hidden" id="rectIdx">
                <input type="hidden" id="rectSource">

                <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:15px;" id="rectDesc">
                    Room 101 - Overtime Penalty
                </div>

                <div class="rm-row" style="display:flex;justify-content:space-between;align-items:center">
                    <label style="margin:0">System Target Value</label>
                    <div class="rm-sys-val" id="rectSysVal">₦0</div>
                </div>

                <div class="rm-row">
                    <label>Actual Sighted Value (₦)</label>
                    <input type="number" id="rectInputVal" placeholder="Enter verified amount...">
                </div>

                <div class="rm-row">
                    <label>Auditor Remarks (Optional)</label>
                    <textarea id="rectNote"
                        placeholder="E.g. Verified transfer receipt and confirmed no penalty due."></textarea>
                </div>
            </div>
            <div class="rm-footer">
                <button class="btn btn-sm"
                    style="background:transparent;border:1px solid var(--border);color:var(--text)"
                    onclick="closeRectifyModal()">Cancel</button>
                <button class="btn btn-sm" style="background:#ffba08;color:#000" onclick="saveRectification()">Save
                    Rectification</button>
            </div>
        </div>
    </div>

    <!-- Dependencies -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
    <script src="overtime.js?v=22"></script>

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
                if (toggle) toggle.checked = true;
            }
        });
    </script>
</body>

</html>

