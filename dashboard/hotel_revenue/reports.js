/**
 * MiAuditOps Reports Page Logic — Loads saved imports from DB,
 * displays them in a card grid, and generates PDFs from saved data.
 */

// ============ LOAD REPORTS ============
document.addEventListener('DOMContentLoaded', loadReports);

async function loadReports() {
    const list = document.getElementById('reportsList');
    const empty = document.getElementById('emptyReports');

    try {
        const res = await fetch('api/get_imports.php');
        const data = await res.json();

        if (!data.success || !data.imports || data.imports.length === 0) {
            list.innerHTML = '';
            empty.style.display = '';
            return;
        }

        empty.style.display = 'none';
        let html = '<div class="reports-grid">';

        data.imports.forEach(imp => {
            const date = new Date(imp.import_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
            const created = new Date(imp.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

            html += `
                <div class="glass report-card">
                    <div class="report-card-top">
                        <div class="report-card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="report-card-meta">
                            <div class="report-card-date">${date}</div>
                            <div class="report-card-file" title="${escHtml(imp.file_name)}">${escHtml(imp.file_name)}</div>
                        </div>
                    </div>
                    <div class="report-card-stats">
                        <div class="rstat">
                            <span class="rstat-val" style="color:var(--accent2)">₦${fmtN(imp.total_revenue)}</span>
                            <span class="rstat-lbl">Revenue</span>
                        </div>
                        <div class="rstat">
                            <span class="rstat-val" style="color:var(--accent5)">${imp.total_records}</span>
                            <span class="rstat-lbl">Records</span>
                        </div>
                        <div class="rstat">
                            <span class="rstat-val" style="color:var(--accent3)">${imp.total_room_types}</span>
                            <span class="rstat-lbl">Types</span>
                        </div>
                    </div>
                    <div class="report-card-actions">
                        <button class="btn btn-ghost btn-sm" onclick="viewReport(${imp.id})" title="View Details">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            View
                        </button>
                        <button class="btn btn-primary btn-sm" onclick="downloadReportPDF(${imp.id})" title="Download PDF">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                            PDF
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="deleteReport(${imp.id})" title="Delete" style="color:var(--accent3)">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                </div>
            `;
        });

        html += '</div>';
        list.innerHTML = html;

    } catch (err) {
        list.innerHTML = '<div class="empty"><h3>Failed to load reports</h3><p>' + err.message + '</p></div>';
    }
}

// ============ VIEW REPORT ============
async function viewReport(id) {
    const modal = document.getElementById('reportModal');
    const body = document.getElementById('reportModalBody');
    const title = document.getElementById('reportModalTitle');
    const pdfBtn = document.getElementById('reportPdfBtn');

    modal.classList.add('show');
    body.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:30px">Loading report...</p>';

    try {
        const res = await fetch('api/get_import.php?id=' + id);
        const data = await res.json();

        if (!data.success) throw new Error(data.error);

        const imp = data.import;
        const records = data.records;
        const breakdown = data.breakdown;
        const settings = data.settings;
        const date = new Date(imp.import_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

        title.textContent = (settings?.company_name || 'Hotel') + ' — ' + date;
        pdfBtn.setAttribute('onclick', `downloadReportPDF(${id})`);

        let html = `
            <div class="sum-grid" style="margin-bottom:20px">
                <div class="glass sum-card c1" style="padding:16px">
                    <div class="lbl">Total Revenue</div>
                    <div class="val" style="font-size:1.3rem">₦${fmtN(imp.total_revenue)}</div>
                </div>
                <div class="glass sum-card c2" style="padding:16px">
                    <div class="lbl">Records</div>
                    <div class="val" style="font-size:1.3rem">${imp.total_records}</div>
                </div>
                <div class="glass sum-card c3" style="padding:16px">
                    <div class="lbl">Room Types</div>
                    <div class="val" style="font-size:1.3rem">${imp.total_room_types}</div>
                </div>
                <div class="glass sum-card c4" style="padding:16px">
                    <div class="lbl">Average Rate</div>
                    <div class="val" style="font-size:1.3rem">₦${fmtN(imp.average_rate)}</div>
                </div>
            </div>
        `;

        // Breakdown table
        html += `<h4 style="margin-bottom:10px;font-size:.95rem">Room Type Breakdown</h4>
        <div style="overflow-x:auto;border-radius:10px;border:1px solid var(--border);margin-bottom:20px">
            <table class="tbl">
                <thead><tr><th>Room Type</th><th>Bookings</th><th>Subtotal</th><th>Share</th></tr></thead>
                <tbody>`;

        breakdown.forEach(b => {
            html += `<tr>
                <td style="font-weight:600">${escHtml(b.room_type)}</td>
                <td><span class="cnt-badge">${b.count}</span></td>
                <td class="amt" style="color:var(--accent2)">₦${fmtN(b.subtotal)}</td>
                <td>${parseFloat(b.percentage).toFixed(1)}%</td>
            </tr>`;
        });

        html += `</tbody>
            <tfoot><tr>
                <td style="font-weight:800">TOTAL</td>
                <td><span class="cnt-badge">${imp.total_records}</span></td>
                <td class="amt" style="color:var(--accent2);font-weight:800">₦${fmtN(imp.total_revenue)}</td>
                <td style="font-weight:800">100%</td>
            </tr></tfoot></table></div>`;

        // Records table
        html += `<h4 style="margin-bottom:10px;font-size:.95rem">All Records (${records.length})</h4>
        <div style="overflow-x:auto;max-height:300px;overflow-y:auto;border-radius:10px;border:1px solid var(--border)">
            <table class="tbl"><thead><tr><th>#</th><th>Room Type</th><th>Amount</th></tr></thead><tbody>`;

        records.forEach((r, i) => {
            html += `<tr>
                <td class="row-num">${i + 1}</td>
                <td style="font-weight:600">${escHtml(r.room_type)}</td>
                <td class="amt" style="color:var(--accent2)">₦${fmtN(r.amount)}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';

        body.innerHTML = html;

    } catch (err) {
        body.innerHTML = '<p style="color:var(--accent3);text-align:center;padding:30px">Error: ' + err.message + '</p>';
    }
}

function closeReportModal() {
    document.getElementById('reportModal').classList.remove('show');
}

// ============ DOWNLOAD REPORT PDF ============
async function downloadReportPDF(id) {
    try {
        const res = await fetch('api/get_import.php?id=' + id);
        const data = await res.json();
        if (!data.success) throw new Error(data.error);

        generateReportPDF(data.import, data.records, data.breakdown, data.settings);
    } catch (err) {
        alert('Failed to load report: ' + err.message);
    }
}

function generateReportPDF(imp, records, breakdown, settings) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'mm', 'a4');
    const W = doc.internal.pageSize.getWidth();
    const H = doc.internal.pageSize.getHeight();
    const m = 18;
    const cW = W - m * 2;
    const companyName = settings?.company_name || 'Hotel Company';
    const companyAddr = settings?.company_address || '';
    const companyPhone = settings?.company_phone || '';
    const companyEmail = settings?.company_email || '';
    const curr = settings?.currency_symbol || '₦';
    const date = new Date(imp.import_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' });
    const total = parseFloat(imp.total_revenue);

    // ===================== PAGE 1: COVER PAGE =====================
    // Full dark background
    doc.setFillColor(15, 15, 35);
    doc.rect(0, 0, W, H, 'F');

    // Decorative accent circle (top-right)
    doc.setFillColor(108, 99, 255);
    doc.circle(W + 20, -20, 80, 'F');

    // Decorative accent circle (bottom-left)
    doc.setFillColor(0, 180, 150);
    doc.circle(-30, H + 10, 60, 'F');

    // Accent bar at top
    doc.setFillColor(108, 99, 255);
    doc.rect(0, 0, W, 4, 'F');

    // Company Name — large centered
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(32);
    doc.setFont('helvetica', 'bold');
    const compLines = doc.splitTextToSize(companyName.toUpperCase(), cW);
    doc.text(compLines, W / 2, 75, { align: 'center' });

    // Divider line
    const divY = 75 + compLines.length * 14 + 10;
    doc.setDrawColor(108, 99, 255);
    doc.setLineWidth(1);
    doc.line(W / 2 - 30, divY, W / 2 + 30, divY);

    // Report Title
    doc.setFontSize(16);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(180, 180, 220);
    doc.text('Revenue Extraction Report', W / 2, divY + 18, { align: 'center' });

    // Date
    doc.setFontSize(12);
    doc.setTextColor(140, 140, 180);
    doc.text(date, W / 2, divY + 32, { align: 'center' });

    // Company details at bottom
    let btmY = H - 50;
    doc.setFontSize(9);
    doc.setTextColor(120, 120, 160);
    if (companyAddr) { doc.text(companyAddr, W / 2, btmY, { align: 'center' }); btmY += 6; }
    const contactLine = [companyPhone, companyEmail].filter(Boolean).join('  •  ');
    if (contactLine) { doc.text(contactLine, W / 2, btmY, { align: 'center' }); btmY += 6; }

    // Tiny footer
    doc.setFontSize(7);
    doc.setTextColor(80, 80, 120);
    doc.text('Generated by MiAuditOps Revenue Extractor', W / 2, H - 15, { align: 'center' });

    // ===================== PAGE 2: SUMMARY + BREAKDOWN =====================
    doc.addPage();

    // Header band
    doc.setFillColor(15, 15, 35);
    doc.rect(0, 0, W, 32, 'F');
    doc.setFillColor(108, 99, 255);
    doc.rect(0, 32, W, 1.5, 'F');

    doc.setTextColor(255, 255, 255);
    doc.setFontSize(14);
    doc.setFont('helvetica', 'bold');
    doc.text('Revenue Summary', m, 20);
    doc.setFontSize(8);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(180, 180, 210);
    doc.text(companyName + '  •  ' + date, m, 27);

    let y = 42;

    // ---- 4 Summary Cards ----
    const cardW = (cW - 9) / 4;
    const cards = [
        { label: 'TOTAL REVENUE', value: curr + fmtN(total), bg: [0, 180, 150], bgLight: [230, 252, 245] },
        { label: 'TOTAL RECORDS', value: String(imp.total_records), bg: [66, 165, 245], bgLight: [232, 245, 255] },
        { label: 'ROOM TYPES', value: String(imp.total_room_types), bg: [255, 107, 157], bgLight: [255, 235, 242] },
        { label: 'AVERAGE RATE', value: curr + fmtN(imp.average_rate), bg: [255, 167, 38], bgLight: [255, 243, 224] }
    ];

    cards.forEach((c, i) => {
        const x = m + i * (cardW + 3);

        // Card background
        doc.setFillColor(...c.bgLight);
        doc.roundedRect(x, y, cardW, 26, 2, 2, 'F');

        // Top accent bar
        doc.setFillColor(...c.bg);
        doc.rect(x, y, cardW, 2, 'F');

        // Label
        doc.setFontSize(6.5);
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(130, 130, 155);
        doc.text(c.label, x + 4, y + 10);

        // Value
        doc.setFontSize(13);
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(...c.bg);
        doc.text(c.value, x + 4, y + 21);
    });

    y += 38;

    // ---- Breakdown Table ----
    doc.setTextColor(40, 40, 60);
    doc.setFontSize(11);
    doc.setFont('helvetica', 'bold');
    doc.text('Room Type Breakdown', m, y);
    y += 5;

    const brkColors = [
        [108, 99, 255], [0, 180, 150], [255, 107, 157], [255, 167, 38],
        [66, 165, 245], [102, 187, 106], [171, 71, 188], [239, 83, 80]
    ];

    const brkRows = breakdown.map((b, i) => {
        const pct = parseFloat(b.percentage).toFixed(1) + '%';
        return [b.room_type, String(b.count), curr + fmtN(b.subtotal), pct];
    });
    brkRows.push(['TOTAL', String(imp.total_records), curr + fmtN(total), '100%']);

    doc.autoTable({
        startY: y,
        head: [['Room Type', 'Bookings', 'Subtotal', 'Share']],
        body: brkRows,
        margin: { left: m, right: m },
        styles: { font: 'helvetica', fontSize: 9, cellPadding: 5, lineColor: [220, 220, 235], lineWidth: 0.3 },
        headStyles: { fillColor: [15, 15, 35], textColor: [255, 255, 255], fontStyle: 'bold', fontSize: 8 },
        bodyStyles: { textColor: [50, 50, 70] },
        alternateRowStyles: { fillColor: [248, 248, 252] },
        columnStyles: {
            0: { fontStyle: 'bold', cellWidth: 65 },
            1: { halign: 'center', cellWidth: 25 },
            2: { halign: 'right', fontStyle: 'bold' },
            3: { halign: 'center', cellWidth: 22 }
        },
        didParseCell: (data) => {
            // Color the room type cell
            if (data.section === 'body' && data.column.index === 0 && data.row.index < brkRows.length - 1) {
                const c = brkColors[data.row.index % brkColors.length];
                data.cell.styles.textColor = c;
            }
            // Total row
            if (data.row.index === brkRows.length - 1) {
                data.cell.styles.fillColor = [15, 15, 35];
                data.cell.styles.textColor = [255, 255, 255];
                data.cell.styles.fontStyle = 'bold';
            }
        }
    });

    // ===================== PAGE 3: ALL RECORDS =====================
    y = doc.lastAutoTable.finalY + 15;
    if (y > H - 50) { doc.addPage(); y = 20; }

    doc.setTextColor(40, 40, 60);
    doc.setFontSize(11);
    doc.setFont('helvetica', 'bold');
    doc.text('All Extracted Records (' + records.length + ')', m, y);
    y += 5;

    const recRows = records.map((r, i) => [String(i + 1), r.room_type, curr + fmtN(r.amount)]);

    doc.autoTable({
        startY: y,
        head: [['#', 'Room Type', 'Amount']],
        body: recRows,
        margin: { left: m, right: m },
        styles: { font: 'helvetica', fontSize: 8, cellPadding: 4, lineColor: [220, 220, 235], lineWidth: 0.3 },
        headStyles: { fillColor: [15, 15, 35], textColor: [255, 255, 255], fontStyle: 'bold', fontSize: 7.5 },
        bodyStyles: { textColor: [50, 50, 70] },
        alternateRowStyles: { fillColor: [248, 248, 252] },
        columnStyles: {
            0: { halign: 'center', cellWidth: 12 },
            1: { fontStyle: 'bold' },
            2: { halign: 'right', fontStyle: 'bold', textColor: [0, 150, 130] }
        }
    });

    // ---- Footer on all pages ----
    const pages = doc.internal.getNumberOfPages();
    for (let p = 1; p <= pages; p++) {
        doc.setPage(p);
        if (p === 1) continue; // Skip cover page footer

        doc.setDrawColor(220, 220, 235);
        doc.setLineWidth(0.3);
        doc.line(m, H - 14, W - m, H - 14);

        doc.setFontSize(7);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(140, 140, 165);
        doc.text(companyName + '  •  Revenue Report', m, H - 8);
        doc.text('Page ' + (p - 1) + ' of ' + (pages - 1), W - m, H - 8, { align: 'right' });
    }

    doc.save(companyName.replace(/\s+/g, '_') + '_Revenue_' + imp.import_date + '.pdf');
}

// ============ DELETE REPORT ============
async function deleteReport(id) {
    if (!confirm('Are you sure you want to delete this report? This cannot be undone.')) return;

    // Show loading spinner
    const spinEl = document.getElementById('spinner');
    if (spinEl) spinEl.classList.add('show');

    try {
        const res = await fetch('api/delete_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (data.success) {
            await loadReports();
            if (spinEl) spinEl.classList.remove('show');
            showToast('Report deleted successfully.');
        } else {
            if (spinEl) spinEl.classList.remove('show');
            alert('Failed: ' + data.error);
        }
    } catch (err) {
        if (spinEl) spinEl.classList.remove('show');
        alert('Error: ' + err.message);
    }
}

/**
 * Show a small toast notification
 */
function showToast(msg) {
    let t = document.getElementById('miaudit-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'miaudit-toast';
        t.style.cssText = 'position:fixed;bottom:28px;right:28px;z-index:9999;padding:12px 22px;border-radius:10px;background:#6c63ff;color:#fff;font-size:.85rem;font-weight:600;font-family:Inter,sans-serif;box-shadow:0 6px 24px rgba(108,99,255,.35);opacity:0;transform:translateY(12px);transition:all .35s ease;pointer-events:none';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    t.style.transform = 'translateY(0)';
    clearTimeout(t._tid);
    t._tid = setTimeout(() => {
        t.style.opacity = '0';
        t.style.transform = 'translateY(12px)';
    }, 2800);
}

// ============ HELPERS ============
function fmtN(n) {
    return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
