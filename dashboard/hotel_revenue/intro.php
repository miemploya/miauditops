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
    <title>MiAuditOps Revenue Extractor</title>
    <meta name="description" content="Extract and analyze revenue figures from hotel check-in Excel records instantly.">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- Background Orbs -->
    <div class="bg-orbs">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
    </div>

    <!-- Navbar -->
    <nav class="navbar">
        <div class="container">
            <a href="index.html" class="brand">
                <div class="logo-box">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
                <span>MiAuditOps</span>
            </a>
            <div class="nav-links">
                <a href="index.html" class="active">Home</a>
                <a href="index.php">Dashboard</a>
                <a href="reports.php">Reports</a>
                <a href="index.php" class="btn btn-primary btn-sm">Open Dashboard</a>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="hero">
        <div class="container">
            <div class="hero-inner">
                <div class="badge">
                    <span class="dot"></span>
                    MiAuditOps Revenue Tool
                </div>
                <h1>Extract <span class="grad">Revenue Figures</span> from Check-In Records</h1>
                <p>Upload your hotel Excel export and instantly extract, separate, and summarize all room rate amounts — no manual work needed.</p>
                <div class="hero-btns">
                    <a href="index.php" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                        Get Started
                    </a>
                    <a href="#how" class="btn btn-ghost">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        How It Works
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="features" id="features">
        <div class="container">
            <div class="features-head">
                <h2>Why Use This Tool?</h2>
                <p>Built to solve one problem perfectly — extracting revenue data from messy Excel exports.</p>
            </div>
            <div class="features-grid">
                <div class="glass feat anim">
                    <div class="ico i1">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <h3>Smart Extraction</h3>
                    <p>Automatically detects and separates monetary amounts embedded within room type text — like "EXECUTIVE DELUXE 150000.00".</p>
                </div>
                <div class="glass feat anim-d1">
                    <div class="ico i2">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                    </div>
                    <h3>Visual Breakdown</h3>
                    <p>See revenue split by room type with interactive charts and detailed breakdown tables at a glance.</p>
                </div>
                <div class="glass feat anim-d2">
                    <div class="ico i3">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <h3>100% Private</h3>
                    <p>Files are parsed entirely in your browser. Nothing is uploaded to any server — your data stays on your machine.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="steps-section" id="how">
        <div class="container">
            <div class="features-head">
                <h2>Three Simple Steps</h2>
                <p>From raw Excel to clean revenue report in seconds.</p>
            </div>
            <div class="steps-row">
                <div class="glass step">
                    <div class="step-num">1</div>
                    <h4>Upload Excel</h4>
                    <p>Drag and drop or browse for your check-in records file (.xlsx, .xls, or .csv).</p>
                </div>
                <div class="glass step">
                    <div class="step-num">2</div>
                    <h4>Auto Extract</h4>
                    <p>The system finds the Room Type column and separates the amount from the text automatically.</p>
                </div>
                <div class="glass step">
                    <div class="step-num">3</div>
                    <h4>View Summary</h4>
                    <p>Get total revenue, room type breakdown, charts, and a clean exportable table instantly.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section style="padding:60px 0 80px">
        <div class="container" style="text-align:center">
            <div class="glass" style="padding:50px 30px;max-width:600px;margin:0 auto">
                <h2 style="font-size:1.6rem;margin-bottom:12px">Ready to Extract?</h2>
                <p style="color:var(--text-sub);margin-bottom:28px;font-size:.95rem">Upload your first file and see the magic happen.</p>
                <a href="index.php" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                    Launch Dashboard
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2026 <a href="https://miauditops.ng" style="color:var(--accent);text-decoration:none">MiAuditOps</a> — Revenue Extractor</p>
        </div>
    </footer>

</body>
</html>

