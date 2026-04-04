/**
 * MiAuditOps Revenue Extractor — App Logic
 * Parses Excel files, extracts amounts from room type text,
 * saves to database, and renders summary, charts, and tables.
 */

// ============ STATE ============
let extractedData = [];
let breakdownData = [];
let pieChart = null;
let barChart = null;
let companySettings = {};
let weeklyData = { weeks: [], roomTypes: [], pivot: {}, weekTotals: {}, typeTotals: {}, grandTotal: 0 };

// ============ DOM REFS ============
const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('fileInput');
const fileStrip = document.getElementById('fileStrip');
const fileName = document.getElementById('fileName');
const fileMeta = document.getElementById('fileMeta');
const spinner = document.getElementById('spinner');
const results = document.getElementById('results');
const emptyState = document.getElementById('emptyState');

// ============ INIT: Load Settings ============
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const res = await fetch('api/settings.php');
        const data = await res.json();
        if (data.success && data.settings) {
            companySettings = data.settings;
        }
    } catch (e) {
        console.warn('Could not load settings:', e.message);
    }
});

// ============ SETTINGS MODAL ============
async function openSettings() {
    const modal = document.getElementById('settingsModal');
    modal.classList.add('show');

    // Load current
    try {
        const res = await fetch('api/settings.php');
        const data = await res.json();
        if (data.success && data.settings) {
            companySettings = data.settings;
            document.getElementById('settCompanyName').value = data.settings.company_name || '';
            document.getElementById('settAddress').value = data.settings.company_address || '';
            document.getElementById('settPhone').value = data.settings.company_phone || '';
            document.getElementById('settEmail').value = data.settings.company_email || '';
            document.getElementById('settCurrency').value = data.settings.currency_symbol || '₦';
        }
    } catch (e) { /* OK */ }
}

function closeSettings() {
    document.getElementById('settingsModal').classList.remove('show');
}

async function saveSettings() {
    const payload = {
        company_name: document.getElementById('settCompanyName').value.trim() || 'Hotel Company',
        company_address: document.getElementById('settAddress').value.trim(),
        company_phone: document.getElementById('settPhone').value.trim(),
        company_email: document.getElementById('settEmail').value.trim(),
        currency_symbol: document.getElementById('settCurrency').value.trim() || '₦'
    };

    try {
        const res = await fetch('api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            companySettings = payload;
            closeSettings();
            showToast('Settings saved successfully!');
        }
    } catch (e) {
        alert('Failed to save settings: ' + e.message);
    }
}

// ============ SAVE TO DATABASE ============
async function saveToDatabase() {
    if (extractedData.length === 0) return;

    const total = extractedData.reduce((s, d) => s + d.amount, 0);

    const brkWithPct = breakdownData.map(d => ({
        roomType: d.roomType,
        count: d.count,
        subtotal: d.subtotal,
        percentage: total > 0 ? parseFloat(((d.subtotal / total) * 100).toFixed(1)) : 0
    }));

    const payload = {
        fileName: fileName.textContent || 'Unknown',
        importDate: new Date().toISOString().slice(0, 10),
        notes: '',
        records: extractedData,
        breakdown: brkWithPct
    };

    try {
        const res = await fetch('api/save_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            showToast('Saved to database! Import #' + data.importId);
        } else {
            alert('Save failed: ' + (data.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Save failed: ' + e.message);
    }
}

// ============ TOAST NOTIFICATION ============
function showToast(msg) {
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:rgba(0,212,170,.15);border:1px solid rgba(0,212,170,.3);color:#00d4aa;padding:12px 22px;border-radius:10px;font-size:.88rem;font-weight:600;z-index:300;animation:fadeUp .3s ease-out;font-family:Inter,sans-serif;backdrop-filter:blur(10px)';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity .3s'; setTimeout(() => toast.remove(), 300); }, 3000);
}

// ============ DRAG & DROP ============
if (uploadZone) {
    ['dragenter', 'dragover'].forEach(evt => {
        uploadZone.addEventListener(evt, e => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
    });
    ['dragleave', 'drop'].forEach(evt => {
        uploadZone.addEventListener(evt, e => { e.preventDefault(); uploadZone.classList.remove('drag-over'); });
    });
    uploadZone.addEventListener('drop', e => {
        const file = e.dataTransfer.files[0];
        if (file) handleFile(file);
    });
}

// ============ FILE INPUT ============
if (fileInput) {
    fileInput.addEventListener('change', e => {
        const file = e.target.files[0];
        if (file) handleFile(file);
    });
}

// ============ HANDLE FILE ============
function handleFile(file) {
    const validExts = ['.xlsx', '.xls', '.csv'];
    const ext = '.' + file.name.split('.').pop().toLowerCase();
    if (!validExts.includes(ext)) { alert('Please upload .xlsx, .xls, or .csv'); return; }

    fileName.textContent = file.name;
    fileMeta.textContent = formatSize(file.size) + ' • ' + new Date().toLocaleTimeString();
    fileStrip.classList.add('show');
    spinner.classList.add('show');

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheet = workbook.Sheets[workbook.SheetNames[0]];
            const rows = XLSX.utils.sheet_to_json(sheet, { header: 1 });
            if (rows.length < 2) { alert('File is empty.'); spinner.classList.remove('show'); return; }
            processData(rows);
        } catch (err) {
            alert('Error: ' + err.message);
        } finally {
            spinner.classList.remove('show');
        }
    };
    reader.readAsArrayBuffer(file);
}

// ============ PROCESS DATA ============
function processData(rows) {
    const headers = rows[0].map(h => String(h).trim().toLowerCase());

    // Find room type column
    let roomTypeCol = -1;
    for (let i = 0; i < headers.length; i++) {
        const h = headers[i];
        if (h.includes('room type') || h.includes('roomtype') || h.includes('room_type')) { roomTypeCol = i; break; }
    }
    if (roomTypeCol === -1) {
        for (let i = 0; i < headers.length; i++) {
            for (let r = 1; r < Math.min(rows.length, 6); r++) {
                if (/[A-Za-z]+.*\d+\.?\d*$/.test(String(rows[r][i] || '').trim())) { roomTypeCol = i; break; }
            }
            if (roomTypeCol !== -1) break;
        }
    }
    if (roomTypeCol === -1) { alert('No "Room Type" column found.'); return; }

    // Find date column
    let dateCol = -1;
    const dateKeywords = ['time of check', 'check in', 'checkin', 'check_in', 'check-in', 'arrival', 'check in date', 'arrival date', 'date'];
    for (let i = 0; i < headers.length; i++) {
        const h = headers[i];
        for (const kw of dateKeywords) {
            if (h.includes(kw)) { dateCol = i; break; }
        }
        if (dateCol !== -1) break;
    }

    // Extract data with dates
    extractedData = [];
    for (let r = 1; r < rows.length; r++) {
        const raw = String(rows[r][roomTypeCol] || '').trim();
        if (!raw) continue;
        const parsed = extractAmount(raw);
        if (parsed.amount > 0) {
            let recordDate = null;
            if (dateCol !== -1) {
                recordDate = parseExcelDate(rows[r][dateCol]);
            }
            extractedData.push({ original: raw, roomType: parsed.roomType, amount: parsed.amount, date: recordDate });
        }
    }

    if (extractedData.length === 0) { alert('No revenue figures extracted.'); return; }

    buildBreakdown();
    buildWeeklyData();
    renderSummary();
    renderWeeklyTable();
    renderBreakdownTable();
    renderDataTable();
    renderCharts();

    results.classList.add('show');
    emptyState.style.display = 'none';
}

// ============ PARSE EXCEL DATE ============
function parseExcelDate(val) {
    if (!val) return null;

    // If numeric (Excel serial date number)
    if (typeof val === 'number') {
        const epoch = new Date(1899, 11, 30);
        const d = new Date(epoch.getTime() + val * 86400000);
        return isNaN(d.getTime()) ? null : d;
    }

    let str = String(val).trim();

    // Handle TTHotel format: "2025.12.31 22:20:12 - 2026.01.08 12:00:00"
    // Extract just the CHECK-IN date (part before " - ")
    if (str.includes(' - ')) {
        str = str.split(' - ')[0].trim();
    }

    // Handle dot-separated dates: "2025.12.31" or "2025.12.31 22:20:12"
    // Convert "YYYY.MM.DD" → "YYYY/MM/DD" so Date() can parse it
    const dotMatch = str.match(/^(\d{4})\.(\d{2})\.(\d{2})/);
    if (dotMatch) {
        const [, year, month, day] = dotMatch;
        return new Date(parseInt(year), parseInt(month) - 1, parseInt(day));
    }

    // Handle slash/dash separated: "31/12/2025", "12-31-2025", "2025-12-31", etc.
    const slashMatch = str.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/);
    if (slashMatch) {
        const [, a, b, year] = slashMatch;
        // Assume DD/MM/YYYY (common in Nigeria/UK)
        return new Date(parseInt(year), parseInt(b) - 1, parseInt(a));
    }

    const isoMatch = str.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/);
    if (isoMatch) {
        const [, year, month, day] = isoMatch;
        return new Date(parseInt(year), parseInt(month) - 1, parseInt(day));
    }

    // Fallback: try native parsing
    const d = new Date(str);
    return isNaN(d.getTime()) ? null : d;
}

// ============ GET WEEK NUMBER FROM DATE ============
function getWeekOfMonth(date) {
    if (!date) return 0;
    const day = date.getDate();
    return Math.ceil(day / 7); // 1-7=Wk1, 8-14=Wk2, 15-21=Wk3, 22-28=Wk4, 29-31=Wk5
}

// ============ BUILD WEEKLY PIVOT DATA ============
function buildWeeklyData() {
    const pivot = {};       // { roomType: { wk: amount } }
    const weekSet = new Set();
    const typeSet = new Set();
    const weekTotals = {};  // { wk: totalAmount }
    const typeTotals = {};  // { roomType: totalAmount }
    let grandTotal = 0;
    let hasAnyDate = false;

    extractedData.forEach(d => {
        const wk = d.date ? getWeekOfMonth(d.date) : 0;
        if (wk > 0) hasAnyDate = true;
        const weekKey = wk > 0 ? wk : 1; // If no date, put all in Wk1
        const type = d.roomType.toUpperCase();

        weekSet.add(weekKey);
        typeSet.add(type);

        if (!pivot[type]) pivot[type] = {};
        pivot[type][weekKey] = (pivot[type][weekKey] || 0) + d.amount;

        weekTotals[weekKey] = (weekTotals[weekKey] || 0) + d.amount;
        typeTotals[type] = (typeTotals[type] || 0) + d.amount;
        grandTotal += d.amount;
    });

    const weeks = Array.from(weekSet).sort((a, b) => a - b);
    const roomTypes = Array.from(typeSet).sort((a, b) => (typeTotals[b] || 0) - (typeTotals[a] || 0));

    weeklyData = { weeks, roomTypes, pivot, weekTotals, typeTotals, grandTotal, hasAnyDate };
}

// ============ EXTRACT AMOUNT FROM TEXT ============
function extractAmount(text) {
    const match = text.match(/^(.*?)\s*([\d,]+\.?\d*)$/);
    if (match && match[2]) {
        const roomType = match[1].trim().replace(/\s+/g, ' ');
        const amount = parseFloat(match[2].replace(/,/g, ''));
        if (!isNaN(amount) && amount > 0) return { roomType: roomType || 'Unknown', amount };
    }
    const nums = text.match(/[\d,]+\.?\d+/g);
    if (nums && nums.length > 0) {
        const amount = parseFloat(nums[nums.length - 1].replace(/,/g, ''));
        const roomType = text.replace(nums[nums.length - 1], '').trim().replace(/\s+/g, ' ') || 'Unknown';
        if (!isNaN(amount) && amount > 0) return { roomType, amount };
    }
    return { roomType: text, amount: 0 };
}

// ============ RENDER WEEKLY PIVOT TABLE ============
function renderWeeklyTable() {
    const { weeks, roomTypes, pivot, weekTotals, typeTotals, grandTotal, hasAnyDate } = weeklyData;
    const curr = companySettings.currency_symbol || '₦';
    const thead = document.getElementById('weeklyHead');
    const tbody = document.getElementById('weeklyBody');
    const tfoot = document.getElementById('weeklyFoot');
    const label = document.getElementById('weeklyLabel');
    const section = document.getElementById('weeklySection');

    if (weeks.length === 0) { section.style.display = 'none'; return; }
    section.style.display = '';

    if (!hasAnyDate) {
        label.textContent = 'No date column found — showing all data as Wk1';
    } else {
        label.textContent = weeks.length + ' week' + (weeks.length !== 1 ? 's' : '') + ' detected';
    }

    // Header row: Room Type | Wk1 | Wk2 | ... | Total
    let headHtml = '<tr><th class="wk-header" style="min-width:160px">Room Type</th>';
    weeks.forEach(wk => {
        headHtml += `<th class="wk-header wk-h-${wk}">Wk${wk}</th>`;
    });
    headHtml += '<th class="wk-header wk-total">Total</th></tr>';
    thead.innerHTML = headHtml;

    // Color palette for room type text
    const typeColors = ['#6c63ff','#00d4aa','#ff6b9d','#ffa726','#42a5f5','#66bb6a','#ab47bc','#ef5350'];

    // Body rows: one per room type
    let bodyHtml = '';
    roomTypes.forEach((type, idx) => {
        const tc = typeColors[idx % typeColors.length];
        bodyHtml += `<tr><td style="color:${tc}">${escHtml(type)}</td>`;
        weeks.forEach(wk => {
            const val = (pivot[type] && pivot[type][wk]) || 0;
            bodyHtml += `<td class="amt" style="font-size:.82rem;${val > 0 ? 'color:var(--text)' : 'color:var(--text-muted)'}">` +
                (val > 0 ? curr + formatNum(val) : '—') + '</td>';
        });
        const rowTotal = typeTotals[type] || 0;
        bodyHtml += `<td class="amt col-total">${curr}${formatNum(rowTotal)}</td></tr>`;
    });
    tbody.innerHTML = bodyHtml;

    // Footer row: totals per week
    let footHtml = '<tr><td style="font-weight:800">TOTAL</td>';
    weeks.forEach(wk => {
        footHtml += `<td class="amt col-total">${curr}${formatNum(weekTotals[wk] || 0)}</td>`;
    });
    footHtml += `<td class="amt col-total" style="font-size:.95rem">${curr}${formatNum(grandTotal)}</td></tr>`;
    tfoot.innerHTML = footHtml;
}

// ============ BUILD BREAKDOWN ============
function buildBreakdown() {
    const map = {};
    extractedData.forEach(d => {
        const key = d.roomType.toUpperCase();
        if (!map[key]) map[key] = { roomType: d.roomType.toUpperCase(), count: 0, subtotal: 0 };
        map[key].count++;
        map[key].subtotal += d.amount;
    });
    breakdownData = Object.values(map).sort((a, b) => b.subtotal - a.subtotal);
}

// ============ RENDER SUMMARY CARDS ============
function renderSummary() {
    const curr = companySettings.currency_symbol || '₦';
    const total = extractedData.reduce((s, d) => s + d.amount, 0);
    const count = extractedData.length;
    const types = breakdownData.length;
    const avg = count > 0 ? total / count : 0;

    animateValue('totalRevenue', curr + formatNum(total));
    animateValue('totalRecords', count.toLocaleString());
    animateValue('totalTypes', types.toString());
    animateValue('avgRate', curr + formatNum(avg));
}

// ============ RENDER BREAKDOWN TABLE ============
function renderBreakdownTable() {
    const curr = companySettings.currency_symbol || '₦';
    const total = extractedData.reduce((s, d) => s + d.amount, 0);
    const tbody = document.getElementById('breakdownBody');
    const tfoot = document.getElementById('breakdownFoot');

    const colors = [
        { bg: 'rgba(108,99,255,.12)', text: '#6c63ff' },
        { bg: 'rgba(0,212,170,.12)', text: '#00d4aa' },
        { bg: 'rgba(255,107,157,.12)', text: '#ff6b9d' },
        { bg: 'rgba(255,167,38,.12)', text: '#ffa726' },
        { bg: 'rgba(66,165,245,.12)', text: '#42a5f5' },
        { bg: 'rgba(102,187,106,.12)', text: '#66bb6a' },
        { bg: 'rgba(171,71,188,.12)', text: '#ab47bc' },
        { bg: 'rgba(239,83,80,.12)', text: '#ef5350' }
    ];

    let html = '';
    breakdownData.forEach((d, i) => {
        const pct = total > 0 ? ((d.subtotal / total) * 100).toFixed(1) : 0;
        const c = colors[i % colors.length];
        html += `<tr>
            <td><span class="type-badge" style="background:${c.bg};color:${c.text}"><span style="width:8px;height:8px;border-radius:50%;background:${c.text};display:inline-block"></span> ${escHtml(d.roomType)}</span></td>
            <td><span class="cnt-badge">${d.count}</span></td>
            <td class="amt" style="color:var(--accent2)">${curr}${formatNum(d.subtotal)}</td>
            <td>${pct}%</td>
            <td><div class="pct-bar"><div class="fill" style="width:${pct}%"></div></div></td>
        </tr>`;
    });
    tbody.innerHTML = html;

    tfoot.innerHTML = `<tr>
        <td>TOTAL</td>
        <td><span class="cnt-badge">${extractedData.length}</span></td>
        <td class="amt" style="color:var(--accent2)">${curr}${formatNum(total)}</td>
        <td>100%</td><td></td>
    </tr>`;
}

// ============ RENDER DATA TABLE ============
function renderDataTable() {
    const curr = companySettings.currency_symbol || '₦';
    const tbody = document.getElementById('dataBody');
    let html = '';
    extractedData.forEach((d, i) => {
        html += `<tr>
            <td class="row-num">${i + 1}</td>
            <td style="color:var(--text-muted);font-size:.82rem">${escHtml(d.original)}</td>
            <td style="font-weight:600">${escHtml(d.roomType)}</td>
            <td class="amt" style="color:var(--accent2)">${curr}${formatNum(d.amount)}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
    const label = document.getElementById('recordCountLabel');
    if (label) label.textContent = extractedData.length + ' record' + (extractedData.length !== 1 ? 's' : '');
}

// ============ RENDER CHARTS ============
function renderCharts() {
    const labels = breakdownData.map(d => d.roomType);
    const amounts = breakdownData.map(d => d.subtotal);
    const counts = breakdownData.map(d => d.count);
    const chartColors = ['#6c63ff','#00d4aa','#ff6b9d','#ffa726','#42a5f5','#66bb6a','#ab47bc','#ef5350','#26c6da','#d4e157'];

    if (pieChart) pieChart.destroy();
    if (barChart) barChart.destroy();

    pieChart = new Chart(document.getElementById('pieChart').getContext('2d'), {
        type: 'doughnut',
        data: { labels, datasets: [{ data: amounts, backgroundColor: chartColors.slice(0, labels.length), borderColor: 'rgba(6,6,15,0.8)', borderWidth: 3, hoverOffset: 8 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '60%',
            plugins: { legend: { position: 'bottom', labels: { color: '#8b8ba7', font: { family: 'Inter', size: 11, weight: '500' }, padding: 14, usePointStyle: true, pointStyleWidth: 10 } },
                tooltip: { backgroundColor: 'rgba(15,15,35,0.95)', borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1, padding: 12,
                    callbacks: { label: ctx => { const t = amounts.reduce((a,b)=>a+b,0); return ` ₦${formatNum(ctx.parsed)} (${((ctx.parsed/t)*100).toFixed(1)}%)`; } } } }
        }
    });

    barChart = new Chart(document.getElementById('barChart').getContext('2d'), {
        type: 'bar',
        data: { labels, datasets: [{ label: 'Bookings', data: counts, backgroundColor: chartColors.slice(0,labels.length).map(c=>c+'40'), borderColor: chartColors.slice(0,labels.length), borderWidth: 2, borderRadius: 8, borderSkipped: false }] },
        options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(15,15,35,0.95)', borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1, padding: 12 } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#55556e', font: { family: 'Inter', size: 11 }, stepSize: 1 } },
                x: { grid: { display: false }, ticks: { color: '#8b8ba7', font: { family: 'Inter', size: 10, weight: '500' }, maxRotation: 45 } } }
        }
    });
}

// ============ EXPORT PDF (from Dashboard — uses live data) ============
function exportPDF() {
    if (extractedData.length === 0) return;

    const total = extractedData.reduce((s, d) => s + d.amount, 0);

    // Build import-like object for the shared PDF generator
    const imp = {
        import_date: new Date().toISOString().slice(0, 10),
        total_revenue: total,
        total_records: extractedData.length,
        total_room_types: breakdownData.length,
        average_rate: extractedData.length > 0 ? total / extractedData.length : 0,
        file_name: fileName.textContent || 'Live Import'
    };

    const records = extractedData.map(d => ({ room_type: d.roomType, amount: d.amount, original_text: d.original }));
    const breakdown = breakdownData.map(d => {
        return { room_type: d.roomType, count: d.count, subtotal: d.subtotal, percentage: total > 0 ? ((d.subtotal / total) * 100).toFixed(1) : 0 };
    });

    generateFullPDF(imp, records, breakdown, companySettings);
}

// ============ FULL PDF GENERATOR (shared by dashboard + reports) ============
function generateFullPDF(imp, records, breakdown, settings) {
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
    doc.setFillColor(15, 15, 35);
    doc.rect(0, 0, W, H, 'F');

    // Decorative gradient circles
    doc.setFillColor(108, 99, 255);
    doc.circle(W + 20, -20, 80, 'F');
    doc.setFillColor(0, 180, 150);
    doc.circle(-30, H + 10, 60, 'F');

    // Top accent bar
    doc.setFillColor(108, 99, 255);
    doc.rect(0, 0, W, 4, 'F');

    // Company Name
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(32);
    doc.setFont('helvetica', 'bold');
    const compLines = doc.splitTextToSize(companyName.toUpperCase(), cW);
    doc.text(compLines, W / 2, 75, { align: 'center' });

    // Divider
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

    // Revenue highlight on cover
    doc.setFontSize(9);
    doc.setTextColor(100, 100, 140);
    doc.text('TOTAL REVENUE', W / 2, divY + 55, { align: 'center' });
    doc.setFontSize(28);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(0, 212, 170);
    doc.text(curr + formatNum(total), W / 2, divY + 70, { align: 'center' });

    // Company details at bottom
    let btmY = H - 50;
    doc.setFontSize(9);
    doc.setTextColor(120, 120, 160);
    if (companyAddr) { doc.text(companyAddr, W / 2, btmY, { align: 'center' }); btmY += 6; }
    const contactLine = [companyPhone, companyEmail].filter(Boolean).join('  •  ');
    if (contactLine) { doc.text(contactLine, W / 2, btmY, { align: 'center' }); }

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

    // Summary Cards
    const cardW = (cW - 9) / 4;
    const cards = [
        { label: 'TOTAL REVENUE', value: curr + formatNum(total), bg: [0, 180, 150], bgL: [230, 252, 245] },
        { label: 'TOTAL RECORDS', value: String(imp.total_records), bg: [66, 165, 245], bgL: [232, 245, 255] },
        { label: 'ROOM TYPES', value: String(imp.total_room_types), bg: [255, 107, 157], bgL: [255, 235, 242] },
        { label: 'AVERAGE RATE', value: curr + formatNum(imp.average_rate), bg: [255, 167, 38], bgL: [255, 243, 224] }
    ];

    cards.forEach((c, i) => {
        const x = m + i * (cardW + 3);
        doc.setFillColor(...c.bgL);
        doc.roundedRect(x, y, cardW, 26, 2, 2, 'F');
        doc.setFillColor(...c.bg);
        doc.rect(x, y, cardW, 2, 'F');
        doc.setFontSize(6.5);
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(130, 130, 155);
        doc.text(c.label, x + 4, y + 10);
        doc.setFontSize(13);
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(...c.bg);
        doc.text(c.value, x + 4, y + 21);
    });

    y += 38;

    // Breakdown Table
    doc.setTextColor(40, 40, 60);
    doc.setFontSize(11);
    doc.setFont('helvetica', 'bold');
    doc.text('Room Type Breakdown', m, y);
    y += 5;

    const brkColors = [[108,99,255],[0,180,150],[255,107,157],[255,167,38],[66,165,245],[102,187,106],[171,71,188],[239,83,80]];
    const brkRows = breakdown.map(b => [b.room_type, String(b.count), curr + formatNum(b.subtotal), parseFloat(b.percentage).toFixed(1) + '%']);
    brkRows.push(['TOTAL', String(imp.total_records), curr + formatNum(total), '100%']);

    doc.autoTable({
        startY: y,
        head: [['Room Type', 'Bookings', 'Subtotal', 'Share']],
        body: brkRows,
        margin: { left: m, right: m },
        styles: { font: 'helvetica', fontSize: 9, cellPadding: 5, lineColor: [220, 220, 235], lineWidth: 0.3 },
        headStyles: { fillColor: [15, 15, 35], textColor: [255, 255, 255], fontStyle: 'bold', fontSize: 8 },
        bodyStyles: { textColor: [50, 50, 70] },
        alternateRowStyles: { fillColor: [248, 248, 252] },
        columnStyles: { 0: { fontStyle: 'bold', cellWidth: 65 }, 1: { halign: 'center', cellWidth: 25 }, 2: { halign: 'right', fontStyle: 'bold' }, 3: { halign: 'center', cellWidth: 22 } },
        didParseCell: (data) => {
            if (data.section === 'body' && data.column.index === 0 && data.row.index < brkRows.length - 1) {
                data.cell.styles.textColor = brkColors[data.row.index % brkColors.length];
            }
            if (data.row.index === brkRows.length - 1) {
                data.cell.styles.fillColor = [15, 15, 35];
                data.cell.styles.textColor = [255, 255, 255];
                data.cell.styles.fontStyle = 'bold';
            }
        }
    });

    // ===================== PAGE 3+: ALL RECORDS =====================
    y = doc.lastAutoTable.finalY + 15;
    if (y > H - 50) { doc.addPage(); y = 20; }

    doc.setTextColor(40, 40, 60);
    doc.setFontSize(11);
    doc.setFont('helvetica', 'bold');
    doc.text('All Extracted Records (' + records.length + ')', m, y);
    y += 5;

    const recRows = records.map((r, i) => [String(i + 1), r.room_type, curr + formatNum(r.amount)]);

    doc.autoTable({
        startY: y,
        head: [['#', 'Room Type', 'Amount']],
        body: recRows,
        margin: { left: m, right: m },
        styles: { font: 'helvetica', fontSize: 8, cellPadding: 4, lineColor: [220, 220, 235], lineWidth: 0.3 },
        headStyles: { fillColor: [15, 15, 35], textColor: [255, 255, 255], fontStyle: 'bold', fontSize: 7.5 },
        bodyStyles: { textColor: [50, 50, 70] },
        alternateRowStyles: { fillColor: [248, 248, 252] },
        columnStyles: { 0: { halign: 'center', cellWidth: 12 }, 1: { fontStyle: 'bold' }, 2: { halign: 'right', fontStyle: 'bold', textColor: [0, 150, 130] } }
    });

    // Footer on all pages except cover
    const pages = doc.internal.getNumberOfPages();
    for (let p = 2; p <= pages; p++) {
        doc.setPage(p);
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

// ============ EXPORT CSV ============
function exportCSV() {
    if (extractedData.length === 0) return;
    const curr = companySettings.currency_symbol || '₦';
    let csv = 'Room Type,Amount\n';
    breakdownData.forEach(d => { csv += `"${d.roomType}",${d.subtotal.toFixed(2)}\n`; });
    const total = extractedData.reduce((s, d) => s + d.amount, 0);
    csv += `\nTOTAL,${total.toFixed(2)}\n\n--- All Records ---\n#,Original,Room Type,Amount\n`;
    extractedData.forEach((d, i) => { csv += `${i + 1},"${d.original}","${d.roomType}",${d.amount.toFixed(2)}\n`; });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'revenue_extract_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
}

// ============ CLEAR FILE ============
function clearFile() {
    fileInput.value = '';
    fileStrip.classList.remove('show');
    results.classList.remove('show');
    emptyState.style.display = '';
    extractedData = [];
    breakdownData = [];
    if (pieChart) { pieChart.destroy(); pieChart = null; }
    if (barChart) { barChart.destroy(); barChart = null; }
}

// ============ HELPERS ============
function formatNum(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function formatSize(bytes) { if (bytes < 1024) return bytes + ' B'; if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'; return (bytes / 1048576).toFixed(1) + ' MB'; }
function escHtml(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
function animateValue(id, finalText) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.opacity = '0';
    el.style.transform = 'translateY(8px)';
    setTimeout(() => { el.textContent = finalText; el.style.transition = 'all 0.4s ease-out'; el.style.opacity = '1'; el.style.transform = 'translateY(0)'; }, 100);
}
