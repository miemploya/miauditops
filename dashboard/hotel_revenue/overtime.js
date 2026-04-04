/**
 * MiAuditOps Overtime Audit Logic
 * Parses Excel files, compares actual vs expected checkout times,
 * calculates overtime hours and expected remittance.
 */

// ============ STATE ============
let auditData = [];      // All parsed records
let overtimeData = [];   // Only overtime records
let doubleSalesData = []; // Suspected double sold instances
let reconAdjustments = []; // Custom recon line items
let currentSessionId = null; // ID of the currently active investigation
let currentFileName = '';    // Source file name of active investigation
let investigationStatus = 'under_investigation'; // under_investigation | concluded | final

// ============ DOM REFS ============
const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('fileInput');
const fileStrip = document.getElementById('fileStrip');
const fileNameEl = document.getElementById('fileName');
const fileMeta = document.getElementById('fileMeta');
const spinner = document.getElementById('spinner');
const results = document.getElementById('results');
const emptyState = document.getElementById('emptyState');

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

    // New upload = new investigation (don't overwrite any currently loaded case)
    currentSessionId = null;
    currentFileName = file.name;

    fileNameEl.textContent = file.name;
    fileMeta.textContent = formatSize(file.size) + ' • ' + new Date().toLocaleTimeString();
    fileStrip.classList.add('show');
    spinner.classList.add('show');

    const reader = new FileReader();
    reader.onload = function (e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheet = workbook.Sheets[workbook.SheetNames[0]];
            const rows = XLSX.utils.sheet_to_json(sheet, { header: 1 });
            if (rows.length < 2) { alert('File is empty.'); spinner.classList.remove('show'); return; }
            processAudit(rows);
        } catch (err) {
            alert('Error: ' + err.message);
        } finally {
            spinner.classList.remove('show');
        }
    };
    reader.readAsArrayBuffer(file);
}

// ============ PROCESS AUDIT ============
function processAudit(rows) {
    const headers = rows[0].map(h => String(h).trim().toLowerCase());

    // Find columns
    let checkinCol = -1;   // "Time of check-in & check-out"
    let actualOutCol = -1; // "Default Check-Out Time"
    let roomTypeCol = -1;  // "Room type" (contains room rate)
    let roomCol = -1;      // "Room" (for double sales grouping)

    for (let i = 0; i < headers.length; i++) {
        const h = headers[i];
        if (h.includes('time of check') || (h.includes('check') && h.includes('in') && h.includes('out'))) {
            checkinCol = i;
        }
        if (h.includes('default check') || h.includes('default checkout') || h.includes('actual check')) {
            actualOutCol = i;
        }
        if (h.includes('room type') || h.includes('roomtype') || h.includes('room_type')) {
            roomTypeCol = i;
        }
        if (h === 'room' || h === 'room no' || h === 'room number') {
            roomCol = i;
        }
    }

    if (checkinCol === -1) { alert('Column "Time of check-in & check-out" not found.'); return; }
    if (actualOutCol === -1) { alert('Column "Default Check-Out Time" not found.'); return; }
    if (roomCol === -1) {
        // Try fallback for room column (just "room" string anywhere in header, not "room type")
        for (let i = 0; i < headers.length; i++) {
            if (headers[i].includes('room') && !headers[i].includes('type')) { roomCol = i; break; }
        }
    }

    // Parse all records
    auditData = [];
    for (let r = 1; r < rows.length; r++) {
        const checkinRaw = String(rows[r][checkinCol] || '').trim();
        const actualRaw = String(rows[r][actualOutCol] || '').trim();
        const roomTypeRaw = roomTypeCol !== -1 ? String(rows[r][roomTypeCol] || '').trim() : '';
        const roomNameRaw = roomCol !== -1 ? String(rows[r][roomCol] || '').trim() : '';

        if (!checkinRaw || !actualRaw) continue;

        // Parse check-in period: "2025.12.31 22:20:12 - 2026.01.08 12:00:00"
        const dates = parseCheckinPeriod(checkinRaw);
        if (!dates) continue;

        // Parse actual checkout
        const actualOut = parseDatetime(actualRaw);
        if (!actualOut) continue;

        // Extract room type and amount
        let roomType = 'Unknown';
        let amount = 0;
        if (roomTypeRaw) {
            const parsed = extractAmount(roomTypeRaw);
            roomType = parsed.roomType || 'Unknown';
            amount = parsed.amount || 0;
        }

        // Calculate stay duration in nights
        const stayMs = dates.expectedOut.getTime() - dates.checkIn.getTime();
        const stayNights = Math.max(1, Math.round(stayMs / (1000 * 60 * 60 * 24)));

        // The embedded amount in the text (e.g., '150000.00' in 'EXECUTIVE DELUXE 150000.00') 
        // is the fixed daily rate, not the total stay value.
        const dailyRate = amount > 0 ? amount : 0;

        // Calculate overtime
        const diffMs = actualOut.getTime() - dates.expectedOut.getTime();
        const diffHours = diffMs / (1000 * 60 * 60);
        const isOvertime = diffHours > 0;

        // Overtime charge: proportional to daily rate
        let overtimeCharge = 0;
        if (isOvertime && dailyRate > 0) {
            overtimeCharge = (dailyRate / 24) * diffHours;
        }

        auditData.push({
            checkIn: dates.checkIn,
            expectedOut: dates.expectedOut,
            actualOut,
            roomName: roomNameRaw,
            roomType,
            amount,
            stayNights,
            dailyRate,
            diffHours,
            isOvertime,
            overtimeCharge,
            checkinRaw,
            actualRaw
        });
    }

    if (auditData.length === 0) { alert('No records could be parsed.'); return; }

    // Filter overtime records
    overtimeData = auditData.filter(d => d.isOvertime);

    // Calculate Double Sales
    calculateDoubleSales();

    // Reset recon fields
    document.getElementById('reconCash').value = '';
    document.getElementById('reconPOS').value = '';
    document.getElementById('reconTransfer').value = '';
    reconAdjustments = [];

    renderSummary();
    renderDoubleSalesTable();
    renderOvertimeTable();
    renderAllTable();

    renderAdjustments();
    calculateRecon();

    // Assign Audit ID for new investigations
    if (!currentSessionId) {
        const randomHex = Math.random().toString(16).slice(2, 7).toUpperCase();
        const dateStrId = new Date().toISOString().slice(0, 10).replace(/-/g, '');
        currentSessionId = `AUDIT-${dateStrId}-${randomHex}`;
    }
    document.getElementById('auditIdText').textContent = currentSessionId;
    document.getElementById('auditHeader').classList.add('show');

    results.classList.add('show');
    emptyState.style.display = 'none';

    saveAuditState(false);
    showToast('Investigation saved: ' + currentFileName);
}

// ============ CALCULATE DOUBLE SALES ============
function calculateDoubleSales() {
    doubleSalesData = [];
    const roomsMap = {};

    // Group all by physical room
    auditData.forEach(d => {
        if (!d.roomName) return;
        if (!roomsMap[d.roomName]) roomsMap[d.roomName] = [];
        roomsMap[d.roomName].push(d);
    });

    Object.keys(roomsMap).forEach(room => {
        const bookings = roomsMap[room];
        const dateGroups = {};

        // Group by exact calendar check-in date
        bookings.forEach(b => {
            const dateStr = b.checkIn.toDateString();
            if (!dateGroups[dateStr]) dateGroups[dateStr] = [];
            dateGroups[dateStr].push(b);
        });

        Object.keys(dateGroups).forEach(dateStr => {
            if (dateGroups[dateStr].length > 1) {
                // Sort by checkIn time
                const dayBookings = dateGroups[dateStr].sort((a, b) => a.checkIn - b.checkIn);

                // Calculate total fraud value (sum of dailyRates for 2nd booking onwards)
                let fraudVal = 0;
                for (let i = 1; i < dayBookings.length; i++) {
                    fraudVal += dayBookings[i].dailyRate;
                }

                doubleSalesData.push({
                    roomName: room,
                    dateStr: dateStr,
                    checkInLogs: dayBookings,
                    fraudValue: fraudVal
                });
            }
        });
    });

    // Sort by highest fraud value
    doubleSalesData.sort((a, b) => b.fraudValue - a.fraudValue);
}

// ============ PARSE CHECKIN PERIOD ============
// "2025.12.31 22:20:12 - 2026.01.08 12:00:00" → { checkIn, expectedOut }
function parseCheckinPeriod(str) {
    const parts = str.split(' - ');
    if (parts.length !== 2) return null;

    const checkIn = parseDatetime(parts[0].trim());
    const expectedOut = parseDatetime(parts[1].trim());

    if (!checkIn || !expectedOut) return null;
    return { checkIn, expectedOut };
}

// ============ PARSE DATETIME ============
// Handle: "2025.12.31 22:20:12", "2025.12.31", Excel serial numbers
function parseDatetime(val) {
    if (!val) return null;

    if (typeof val === 'number') {
        const epoch = new Date(1899, 11, 30);
        const d = new Date(epoch.getTime() + val * 86400000);
        return isNaN(d.getTime()) ? null : d;
    }

    let str = String(val).trim();

    // "2025.12.31 22:20:12"
    const dotMatch = str.match(/^(\d{4})\.(\d{2})\.(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/);
    if (dotMatch) {
        const [, y, mo, d, h, mi, s] = dotMatch;
        return new Date(+y, +mo - 1, +d, +h, +mi, +s);
    }

    // "2025.12.31" (no time)
    const dotDateOnly = str.match(/^(\d{4})\.(\d{2})\.(\d{2})$/);
    if (dotDateOnly) {
        const [, y, mo, d] = dotDateOnly;
        return new Date(+y, +mo - 1, +d);
    }

    // ISO format: "2025-12-31 22:20:12"
    const isoMatch = str.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/);
    if (isoMatch) {
        const [, y, mo, d, h, mi, s] = isoMatch;
        return new Date(+y, +mo - 1, +d, +h, +mi, +s);
    }

    // "2025 12.31 22:20:12" (space between year and rest)
    const spaceMatch = str.match(/^(\d{4})\s+(\d{2})\.(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/);
    if (spaceMatch) {
        const [, y, mo, d, h, mi, s] = spaceMatch;
        return new Date(+y, +mo - 1, +d, +h, +mi, +s);
    }

    const d = new Date(str);
    return isNaN(d.getTime()) ? null : d;
}

// ============ EXTRACT AMOUNT FROM ROOM TYPE TEXT ============
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

// ============ RECONCILIATION LOGIC ============
function calculateRecon() {
    const cash = parseFloat(document.getElementById('reconCash').value) || 0;
    const pos = parseFloat(document.getElementById('reconPOS').value) || 0;
    const transfer = parseFloat(document.getElementById('reconTransfer').value) || 0;

    const sysBase = auditData.reduce((s, d) => s + d.dailyRate, 0);
    const sysOT = overtimeData.reduce((s, d) => s + (d.isRectified ? d.rectifiedAmount : d.overtimeCharge), 0);
    const sysFraud = doubleSalesData.reduce((s, d) => s + (d.isRectified ? d.rectifiedAmount : d.fraudValue), 0);
    const sysTotal = sysBase + sysOT + sysFraud;

    document.getElementById('reconBase').textContent = '₦' + formatNum(sysBase);
    document.getElementById('reconOT').textContent = '₦' + formatNum(sysOT);
    document.getElementById('reconFraud').textContent = '₦' + formatNum(sysFraud);
    document.getElementById('reconSysTotal').textContent = '₦' + formatNum(sysTotal);

    const declaredTotal = cash + pos + transfer;
    document.getElementById('reconDeclaredTotal').textContent = '₦' + formatNum(declaredTotal);

    let adjNet = 0;
    reconAdjustments.forEach(adj => {
        if (adj.type === '+') adjNet += adj.amount;
        if (adj.type === '-') adjNet -= adj.amount;
    });

    const netExpected = sysTotal + adjNet;
    document.getElementById('reconNetExpected').textContent = '₦' + formatNum(netExpected);

    const variance = declaredTotal - netExpected;
    const vAmtEl = document.getElementById('reconVarianceAmt');
    const vStatusEl = document.getElementById('reconVarianceStatus');

    vAmtEl.textContent = (variance < 0 ? '-' : '') + '₦' + formatNum(Math.abs(variance));

    if (variance === 0) {
        vAmtEl.style.color = 'var(--text)';
        vStatusEl.className = 'v-status balanced';
        vStatusEl.textContent = 'PERFECTLY BALANCED';
    } else if (variance < 0) {
        vAmtEl.style.color = '#ef5350';
        vStatusEl.className = 'v-status shortage';
        vStatusEl.textContent = 'SHORTAGE';
    } else {
        vAmtEl.style.color = '#29b6f6';
        vStatusEl.className = 'v-status surplus';
        vStatusEl.textContent = 'SURPLUS';
    }

    // Auto-save on any recon change (debounced)
    clearTimeout(calculateRecon._debounce);
    calculateRecon._debounce = setTimeout(() => saveAuditState(false), 800);
}

function addAdjustment() {
    const lbl = document.getElementById('adjLabel').value.trim();
    const type = document.getElementById('adjType').value;
    const amt = parseFloat(document.getElementById('adjAmt').value) || 0;
    if (!lbl || amt <= 0) return;

    reconAdjustments.push({ id: Date.now(), label: lbl, type, amount: amt });
    document.getElementById('adjLabel').value = '';
    document.getElementById('adjAmt').value = '';
    renderAdjustments();
    calculateRecon();
    if (typeof saveAuditState === 'function') saveAuditState(false);
}

function removeAdjustment(id) {
    reconAdjustments = reconAdjustments.filter(a => a.id !== id);
    renderAdjustments();
    calculateRecon();
    if (typeof saveAuditState === 'function') saveAuditState(false);
}

function renderAdjustments() {
    const list = document.getElementById('adjList');
    if (reconAdjustments.length === 0) {
        list.innerHTML = '<div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:10px;text-align:center">No adjustments added.</div>';
        return;
    }
    list.innerHTML = reconAdjustments.map(a => `
        <div class="adj-item">
            <span>${a.type === '+' ? '+' : '-'} ${escHtml(a.label)}</span>
            <div style="display:flex;gap:10px;align-items:center">
                <span style="font-weight:600;color:${a.type === '+' ? '#66bb6a' : '#ef5350'}">₦${formatNum(a.amount)}</span>
                <span class="a-del" onclick="removeAdjustment(${a.id})">✖</span>
            </div>
        </div>
    `).join('');
}

// ============ RENDER SUMMARY ============
function renderSummary() {
    const totalOvertimeHours = overtimeData.reduce((s, d) => s + d.diffHours, 0);
    const totalExpected = overtimeData.reduce((s, d) => s + d.overtimeCharge, 0);

    const dsRooms = doubleSalesData.length;
    const dsFraudVal = doubleSalesData.reduce((s, d) => s + d.fraudValue, 0);

    animateValue('otTotalRecords', auditData.length.toLocaleString());
    animateValue('otOvertimeCount', overtimeData.length.toLocaleString());
    animateValue('dsTotalCount', dsRooms.toLocaleString());
    animateValue('TotalFraudAmount', '₦' + formatNum(dsFraudVal));

    // Risk Analysis calculations
    const riskMinor = overtimeData.filter(d => d.diffHours > 0 && d.diffHours <= 4);
    const riskWarning = overtimeData.filter(d => d.diffHours > 4 && d.diffHours <= 12);
    const riskCritical = overtimeData.filter(d => d.diffHours > 12);

    animateValue('riskMinorCount', riskMinor.length.toLocaleString());
    document.getElementById('riskMinorAmt').textContent = '₦' + formatNum(riskMinor.reduce((s, d) => s + d.overtimeCharge, 0));

    animateValue('riskWarningCount', riskWarning.length.toLocaleString());
    document.getElementById('riskWarningAmt').textContent = '₦' + formatNum(riskWarning.reduce((s, d) => s + d.overtimeCharge, 0));

    animateValue('riskCriticalCount', riskCritical.length.toLocaleString());
    document.getElementById('riskCriticalAmt').textContent = '₦' + formatNum(riskCritical.reduce((s, d) => s + d.overtimeCharge, 0));
}

// ============ RENDER DOUBLE SALES TABLE ============
function renderDoubleSalesTable() {
    const tbody = document.getElementById('doubleSalesBody');
    if (doubleSalesData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#66bb6a;font-weight:600">No double sales detected! All room check-ins appear legitimate today.</td></tr>';
        return;
    }

    let html = '';
    doubleSalesData.forEach((d, i) => {
        // Build the mini logs of all bookings — with clickable verification toggle
        let logsHTML = d.checkInLogs.map((log, j) => {
            const isFraud = j > 0; // First is legit, rest are fraud
            const isVerified = !!log.isVerified;
            const strikeStyle = isVerified ? 'text-decoration:line-through;opacity:0.5;' : '';
            const color = isVerified ? '#66bb6a' : (isFraud ? '#ef5350' : '#b4b4d2');
            const flag = isFraud && !isVerified ? '<span style="color:#ef5350;border:1px solid rgba(239,83,80,0.3);padding:1px 4px;border-radius:3px;font-size:10px;margin-left:5px">UNREMITTED</span>' : '';
            const verifiedBadge = isVerified ? '<span style="color:#66bb6a;border:1px solid rgba(102,187,106,0.3);padding:1px 4px;border-radius:3px;font-size:10px;margin-left:5px">✓ CLEARED</span>' : '';
            const clickAttr = `onclick="toggleLogVerified(${i}, ${j})" style="cursor:pointer;${strikeStyle}font-size:0.75rem;margin-bottom:4px;color:${color}" title="Click to ${isVerified ? 'unmark' : 'mark as audited/cleared'}"`;
            return `<div ${clickAttr}>
                <strong>${j + 1}.</strong>
                Check-in: ${fmtDT(log.checkIn)} — Out: ${fmtDT(log.actualOut)}<br>
                <div style="padding-left:14px">Rate: ₦${formatNum(log.dailyRate)} / expected out: ${fmtDT(log.expectedOut)} ${flag}${verifiedBadge}</div>
            </div>`;
        }).join('');

        const firstType = d.checkInLogs[0].roomType;
        const rate = d.checkInLogs[0].dailyRate;

        // Calculate fraud value & count excluding verified logs
        const unverifiedFraudLogs = d.checkInLogs.filter((log, j) => j > 0 && !log.isVerified);
        let computedFraudValue = unverifiedFraudLogs.reduce((sum, log) => sum + (log.dailyRate || 0), 0);
        const activeValue = d.isRectified ? d.rectifiedAmount : computedFraudValue;

        let fraudCount = 0;
        if (rate > 0) {
            fraudCount = +(activeValue / rate).toFixed(2);
        } else {
            fraudCount = unverifiedFraudLogs.length;
        }

        html += `<tr>
            <td style="font-weight:600;font-size:.82rem">${d.dateStr}</td>
            <td style="font-weight:800;color:var(--text-main);font-size:.85rem">${escHtml(d.roomName)}</td>
            <td style="font-size:.8rem;color:#ef5350;font-weight:700">${d.checkInLogs.length} times</td>
            <td style="font-size:.78rem;color:var(--text-sub)">${escHtml(firstType)}</td>
            <td>${logsHTML}</td>
            <td class="amt" style="font-size:.85rem;color:var(--text-main);font-weight:700;text-align:center">${fraudCount}</td>
            <td class="amt" style="text-align:right">
                ${d.isRectified ? `
                    <div class="rectified-cell">
                        <span class="stricken-val">₦${formatNum(d.fraudValue)}</span>
                        <span class="rectified-val">₦${formatNum(d.rectifiedAmount)}</span>
                        ${d.rectifiedNote ? `<span class="rect-note" title="${escHtml(d.rectifiedNote)}">🗒️ Sighted</span>` : ''}
                    </div>
                ` : `<span style="color:#ef5350;font-weight:800;font-size:.9rem">₦${formatNum(computedFraudValue)}</span>`}
            </td>
            <td class="amt">
                <button class="btn-rectify ${d.isRectified ? 'done' : ''}" onclick="openRectifyModal('ds', ${i})">${d.isRectified ? 'Edit Rectification' : 'Rectify'}</button>
            </td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

// ============ TOGGLE LOG VERIFIED (AUDIT CLEARANCE) ============
function toggleLogVerified(dsIdx, logIdx) {
    const d = doubleSalesData[dsIdx];
    if (!d || !d.checkInLogs || !d.checkInLogs[logIdx]) return;
    
    // Toggle verification
    d.checkInLogs[logIdx].isVerified = !d.checkInLogs[logIdx].isVerified;
    
    // Recalculate fraud value based on unverified logs only
    const unverifiedLogs = d.checkInLogs.filter((log, j) => j > 0 && !log.isVerified);
    d.fraudValue = unverifiedLogs.reduce((sum, log) => sum + (log.dailyRate || 0), 0);
    
    // Clear rectification if fraud value changed (user should re-rectify)
    if (!d.isRectified) {
        // no action needed
    }
    
    renderDoubleSalesTable();
    calculateRecon();
    saveAuditState(false);
    
    const status = d.checkInLogs[logIdx].isVerified ? 'cleared' : 'unmarked';
    showToast(`Log entry ${logIdx + 1} ${status} — double sales recalculated.`);
}

// ============ UPDATE INVESTIGATION STATUS ============
function updateInvestigationStatus(val) {
    investigationStatus = val;
    saveAuditState(false);
    const labels = { under_investigation: '🔍 Under Investigation', concluded: '✅ Concluded', final: '📋 Final Report' };
    showToast(`Status updated: ${labels[val] || val}`);
}

// ============ RENDER OVERTIME TABLE ============
function renderOvertimeTable() {
    const tbody = document.getElementById('overtimeBody');
    if (overtimeData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted)">No overtime records found — all guests checked out on time!</td></tr>';
        return;
    }

    // Sort by overtime hours (most overtime first)
    const sorted = [...overtimeData].sort((a, b) => b.diffHours - a.diffHours);

    let html = '';
    sorted.forEach((d, i) => {
        const otHrs = d.diffHours;
        const severity = otHrs > 12 ? 'high' : otHrs > 4 ? 'medium' : 'low';
        const severityColor = severity === 'high' ? '#ef5350' : severity === 'medium' ? '#ffa726' : '#66bb6a';
        const severityLabel = severity === 'high' ? 'CRITICAL' : severity === 'medium' ? 'WARNING' : 'MINOR';
        const otIdx = overtimeData.indexOf(d);

        html += `<tr>
            <td class="row-num">${i + 1}</td>
            <td style="font-weight:600;font-size:.82rem">${escHtml(d.roomType)}</td>
            <td style="font-size:.78rem;color:var(--text-sub)">${fmtDT(d.checkIn)}</td>
            <td style="font-size:.78rem;color:var(--text-sub)">${fmtDT(d.expectedOut)}</td>
            <td style="font-size:.78rem;color:${severityColor};font-weight:600">${fmtDT(d.actualOut)}</td>
            <td style="font-weight:800;color:${severityColor}">${formatOvertimeDuration(otHrs)}</td>
            <td class="amt" style="font-size:.82rem">₦${formatNum(d.dailyRate)}<span style="color:var(--text-muted);font-weight:400;font-size:.68rem">/night</span></td>
            <td class="amt" style="text-align:right">
                ${d.isRectified ? `
                    <div class="rectified-cell">
                        <span class="stricken-val">₦${formatNum(d.overtimeCharge)}</span>
                        <span class="rectified-val">₦${formatNum(d.rectifiedAmount)}</span>
                        ${d.rectifiedNote ? `<span class="rect-note" title="${escHtml(d.rectifiedNote)}">🗒️ Sighted</span>` : ''}
                    </div>
                ` : `<span style="color:#ef5350;font-weight:800">₦${formatNum(d.overtimeCharge)}</span>`}
            </td>
            <td><span style="padding:3px 10px;border-radius:5px;font-size:.68rem;font-weight:700;background:${severityColor}18;color:${severityColor}">${severityLabel}</span></td>
            <td class="amt">
                <button class="btn-rectify ${d.isRectified ? 'done' : ''}" onclick="openRectifyModal('ot', ${otIdx})">${d.isRectified ? 'Edit Rectification' : 'Rectify'}</button>
            </td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

// ============ RENDER ALL RECORDS TABLE ============
function renderAllTable() {
    const tbody = document.getElementById('allBody');
    const label = document.getElementById('allCountLabel');
    label.textContent = auditData.length + ' records';

    let html = '';
    auditData.forEach((d, i) => {
        const statusColor = d.isOvertime ? '#ef5350' : '#00d4aa';
        const statusLabel = d.isOvertime ? 'OVERTIME' : 'ON TIME';
        const diffStr = d.isOvertime ? '+' + formatOvertimeDuration(d.diffHours)
            : d.diffHours < 0 ? formatOvertimeDuration(Math.abs(d.diffHours)) + ' early' : 'On time';

        html += `<tr>
            <td class="row-num">${i + 1}</td>
            <td style="font-weight:600;font-size:.82rem">${escHtml(d.roomType)}</td>
            <td style="font-size:.78rem;color:var(--text-sub)">${fmtDT(d.checkIn)}</td>
            <td style="font-size:.78rem;color:var(--text-sub)">${fmtDT(d.expectedOut)}</td>
            <td style="font-size:.78rem;color:var(--text-sub)">${fmtDT(d.actualOut)}</td>
            <td style="font-size:.78rem;color:${statusColor};font-weight:600">${diffStr}</td>
            <td><span style="padding:3px 10px;border-radius:5px;font-size:.68rem;font-weight:700;background:${statusColor}18;color:${statusColor}">${statusLabel}</span></td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

// ============ EXPORT DATA CALCULATIONS ============
function getWeekOfMonth(date) {
    if (!date) return 0;
    const day = date.getDate();
    return Math.ceil(day / 7);
}

function getExportWeeklyData() {
    const pivot = {};
    const weekSet = new Set();
    const typeSet = new Set();
    const weekTotals = {};
    const typeTotals = {};
    let grandTotal = 0;

    auditData.forEach(d => {
        const wk = d.checkIn ? getWeekOfMonth(d.checkIn) : 1;
        const type = (d.roomType || 'Unknown').toUpperCase();

        weekSet.add(wk);
        typeSet.add(type);

        if (!pivot[type]) pivot[type] = {};
        pivot[type][wk] = (pivot[type][wk] || 0) + d.dailyRate;

        weekTotals[wk] = (weekTotals[wk] || 0) + d.dailyRate;
        typeTotals[type] = (typeTotals[type] || 0) + d.dailyRate;
        grandTotal += d.dailyRate;
    });

    const weeks = Array.from(weekSet).sort((a, b) => a - b);
    const roomTypes = Array.from(typeSet).sort((a, b) => (typeTotals[b] || 0) - (typeTotals[a] || 0));

    return { weeks, roomTypes, pivot, weekTotals, typeTotals, grandTotal };
}

function getExportBreakdownData() {
    const map = {};
    let total = 0;
    auditData.forEach(d => {
        const key = (d.roomType || 'Unknown').toUpperCase();
        if (!map[key]) map[key] = { roomType: key, count: 0, subtotal: 0 };
        map[key].count++;
        map[key].subtotal += d.dailyRate;
        total += d.dailyRate;
    });
    const arr = Object.values(map).sort((a, b) => b.subtotal - a.subtotal);
    return { breakdown: arr, total };
}

// ============ EXPORT OVERTIME PDF ============
function exportOvertimePDF() {
    try {
        if (auditData.length === 0) { alert('No records to export.'); return; }

        if (!window.jspdf && !window.jsPDF) {
            alert('PDF Library is not loaded! Please check your internet connection or disable adblockers.');
            return;
        }
        const jsPDF = window.jspdf ? window.jspdf.jsPDF : window.jsPDF;
        const doc = new jsPDF('p', 'mm', 'a4'); // Starts Portrait
        let W = doc.internal.pageSize.getWidth();
        let H = doc.internal.pageSize.getHeight();
        const m = 18;

        const dateStr = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' });
        const authId = currentSessionId || 'LIVE-AUDIT';

        // Global Values extraction
        const cash = parseFloat(document.getElementById('reconCash').value) || 0;
        const pos = parseFloat(document.getElementById('reconPOS').value) || 0;
        const transfer = parseFloat(document.getElementById('reconTransfer').value) || 0;

        const sysBase = auditData.reduce((s, d) => s + d.dailyRate, 0);
        const sysOT = overtimeData.reduce((s, d) => s + (d.isRectified ? d.rectifiedAmount : d.overtimeCharge), 0);
        const sysFraud = doubleSalesData.reduce((s, d) => s + (d.isRectified ? d.rectifiedAmount : d.fraudValue), 0);
        const sysTotal = sysBase + sysOT + sysFraud;

        const declaredTotal = cash + pos + transfer;
        let adjNet = 0;
        reconAdjustments.forEach(adj => {
            if (adj.type === '+') adjNet += adj.amount;
            if (adj.type === '-') adjNet -= adj.amount;
        });
        const netExpected = sysTotal + adjNet;
        const variance = declaredTotal - netExpected;

        function renderHeader(title) {
            doc.setFillColor(15, 15, 35);
            doc.rect(0, 0, W, 28, 'F');
            doc.setFillColor(108, 99, 255);
            doc.rect(0, 28, W, 1.5, 'F');
            doc.setTextColor(255, 255, 255);
            doc.setFontSize(14);
            doc.setFont('helvetica', 'bold');
            doc.text(title, m, 18);
            doc.setFontSize(8);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(180, 180, 210);
            doc.text('Overtime Audit Module  •  ' + dateStr, m, 24);
        }

        // Setup user defined PDF cover settings
        const pdfBgColorHex = document.getElementById('pdfBgColor') ? document.getElementById('pdfBgColor').value : '#0f0f23';
        const pdfTextColorHex = document.getElementById('pdfTextColor') ? document.getElementById('pdfTextColor').value : '#ffffff';
        const pdfReportTitle = document.getElementById('pdfReportTitle') ? document.getElementById('pdfReportTitle').value || 'AUDIT & DOUBLE SALES\nIMPORT REPORT' : 'AUDIT & DOUBLE SALES\nIMPORT REPORT';
        
        function hexToRgb(h) {
            let r=15, g=15, b=35;
            if (h && h.length === 7) {
                r = parseInt(h[1]+h[2], 16);
                g = parseInt(h[3]+h[4], 16);
                b = parseInt(h[5]+h[6], 16);
            }
            return [r, g, b];
        }
        
        const bgRgb = hexToRgb(pdfBgColorHex);
        const textRgb = hexToRgb(pdfTextColorHex);

        // ==========================================
        // PAGE 1: COVER PAGE
        // ==========================================
        doc.setFillColor(bgRgb[0], bgRgb[1], bgRgb[2]);
        doc.rect(0, 0, W, H, 'F');
        doc.setFillColor(108, 99, 255);
        doc.circle(W + 20, -20, 80, 'F');
        doc.setFillColor(0, 180, 150);
        doc.circle(-30, H + 10, 60, 'F');

        doc.setTextColor(textRgb[0], textRgb[1], textRgb[2]);
        doc.setFontSize(32);
        doc.setFont('helvetica', 'bold');
        
        const splitTitle = doc.splitTextToSize(pdfReportTitle, W - 40);
        doc.text(splitTitle, W / 2, 80, { align: 'center' });
        
        const titleHeightMod = splitTitle.length;
        const lineY = 80 + (titleHeightMod * 12) + 6;

        doc.setDrawColor(108, 99, 255);
        doc.setLineWidth(1);
        doc.line(W / 2 - 30, lineY, W / 2 + 30, lineY);

        doc.setFontSize(12);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(textRgb[0], textRgb[1], textRgb[2]);
        doc.text(`ID: ${authId}  •  ${dateStr}`, W / 2, lineY + 15, { align: 'center' });
        
        const badgeY = lineY + 24;

        // Investigation Status Badge
        const statusLabels = { under_investigation: 'UNDER INVESTIGATION', concluded: 'CONCLUDED', final: 'FINAL REPORT' };
        const statusColors = { under_investigation: [255, 186, 8], concluded: [102, 187, 106], final: [108, 99, 255] };
        const stLabel = statusLabels[investigationStatus] || 'UNDER INVESTIGATION';
        const stColor = statusColors[investigationStatus] || [255, 186, 8];

        doc.setFontSize(10);
        doc.setFont('helvetica', 'bold');
        const stWidth = doc.getTextWidth(stLabel) + 16;
        doc.setFillColor(stColor[0], stColor[1], stColor[2]);
        doc.roundedRect(W / 2 - stWidth / 2, badgeY, stWidth, 10, 2, 2, 'F');
        doc.setTextColor(bgRgb[0], bgRgb[1], bgRgb[2]);
        doc.text(stLabel, W / 2, badgeY + 7, { align: 'center' });

        // ==========================================
        // PAGE 2: METRICS
        // ==========================================
        doc.addPage();
        renderHeader('AUDIT METRICS OVERVIEW');

        doc.setFontSize(10);
        doc.setTextColor(50, 50, 70);
        doc.setFont('helvetica', 'bold');
        let y = 45;

        const mCards = [
            { l: 'Total Records', v: String(auditData.length), c: [0, 212, 170] },
            { l: 'Overtime Guests', v: String(overtimeData.length), c: [255, 107, 157] },
            { l: 'Total Overtime Hrs', v: document.getElementById('otTotalHours').textContent, c: [255, 167, 38] },
            { l: 'Double Sold Rooms', v: String(doubleSalesData.length), c: [255, 186, 8] },
            { l: 'Total Double Sales Value', v: 'N' + formatNum(sysFraud), c: [239, 83, 80] }
        ];

        mCards.forEach((c, idx) => {
            doc.setFillColor(248, 248, 252);
            doc.roundedRect(m, y, W - m * 2, 22, 2, 2, 'F');
            doc.setFillColor(...c.c);
            doc.rect(m, y, 4, 22, 'F');

            doc.setFontSize(8);
            doc.setTextColor(100, 100, 120);
            doc.text(c.l, m + 10, y + 8);
            doc.setFontSize(14);
            doc.setTextColor(...c.c);
            doc.text(c.v, m + 10, y + 17);
            y += 28;
        });

        // ==========================================
        // PAGE 3: WEEKLY SUMMARY
        // ==========================================
        doc.addPage();
        renderHeader('WEEKLY SUMMARY OF IMPORT');

        const wData = getExportWeeklyData();
        let wHead = ['Room Type'];
        wData.weeks.forEach(wk => wHead.push(`Wk${wk}`));
        wHead.push('Total');

        let wRows = [];
        wData.roomTypes.forEach(rt => {
            let row = [rt];
            wData.weeks.forEach(wk => {
                const val = (wData.pivot[rt] && wData.pivot[rt][wk]) || 0;
                row.push('N' + formatNum(val));
            });
            row.push('N' + formatNum(wData.typeTotals[rt] || 0));
            wRows.push(row);
        });

        let totalRow = ['TOTAL'];
        wData.weeks.forEach(wk => totalRow.push('N' + formatNum(wData.weekTotals[wk] || 0)));
        totalRow.push('N' + formatNum(wData.grandTotal));
        wRows.push(totalRow);

        doc.autoTable({
            startY: 40,
            head: [wHead],
            body: wRows,
            margin: { left: m, right: m },
            styles: { font: 'helvetica', fontSize: 8, cellPadding: 4, lineColor: [220, 220, 235], lineWidth: 0.3 },
            headStyles: { fillColor: [24, 24, 40], textColor: [255, 255, 255], fontStyle: 'bold' },
            didParseCell: (data) => {
                if (data.row.index === wRows.length - 1) {
                    data.cell.styles.fillColor = [240, 240, 245];
                    data.cell.styles.fontStyle = 'bold';
                }
            }
        });

        // ==========================================
        // PAGE 4: ROOM TYPE BREAKDOWN
        // ==========================================
        doc.addPage();
        renderHeader('ROOM TYPE BREAKDOWN');

        const bData = getExportBreakdownData();
        let bRows = bData.breakdown.map(b => [
            b.roomType,
            String(b.count),
            'N' + formatNum(b.subtotal),
            ((b.subtotal / bData.total) * 100).toFixed(1) + '%'
        ]);
        bRows.push(['TOTAL', String(auditData.length), 'N' + formatNum(bData.total), '100%']);

        doc.autoTable({
            startY: 40,
            head: [['Room Type', 'Bookings', 'Subtotal', 'Share']],
            body: bRows,
            margin: { left: m, right: m },
            styles: { font: 'helvetica', fontSize: 8, cellPadding: 4, lineColor: [220, 220, 235], lineWidth: 0.3 },
            headStyles: { fillColor: [24, 24, 40], textColor: [255, 255, 255], fontStyle: 'bold' },
            didParseCell: (data) => {
                if (data.row.index === bRows.length - 1) {
                    data.cell.styles.fillColor = [240, 240, 245];
                    data.cell.styles.fontStyle = 'bold';
                }
            }
        });

        // ---- PIE CHART below the breakdown table ----
        const pieColors = [
            [255, 186, 8],    // Gold
            [41, 182, 246],   // Blue
            [239, 83, 80],    // Red
            [102, 187, 106],  // Green
            [171, 71, 188],   // Purple
            [255, 138, 101],  // Orange
            [77, 208, 225],   // Cyan
            [255, 213, 79],   // Yellow
            [149, 117, 205],  // Lavender
            [129, 199, 132],  // Lime
        ];

        const tableEndY = doc.lastAutoTable.finalY || 120;
        const chartTopY = tableEndY + 15;
        const centerX = W / 2 - 30;
        const centerY = chartTopY + 45;
        const radius = 38;

        // Title
        doc.setFontSize(10);
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(30, 30, 50);
        doc.text('Revenue Distribution by Room Type', W / 2, chartTopY - 3, { align: 'center' });

        // Draw pie slices
        const slices = bData.breakdown.filter(b => b.subtotal > 0);
        let startAngle = -Math.PI / 2; // Start from top

        slices.forEach((sl, idx) => {
            const share = sl.subtotal / bData.total;
            const sweepAngle = share * 2 * Math.PI;
            const endAngle = startAngle + sweepAngle;
            const col = pieColors[idx % pieColors.length];

            // Draw filled arc segment
            doc.setFillColor(col[0], col[1], col[2]);
            doc.setDrawColor(255, 255, 255);
            doc.setLineWidth(0.5);

            // Build path for pie slice using small arc segments
            const steps = Math.max(Math.ceil(sweepAngle / 0.05), 8);
            const angleStep = sweepAngle / steps;
            const points = [[centerX, centerY]];
            for (let s = 0; s <= steps; s++) {
                const a = startAngle + s * angleStep;
                points.push([
                    centerX + radius * Math.cos(a),
                    centerY + radius * Math.sin(a)
                ]);
            }

            // Draw the slice as a filled polygon
            doc.setFillColor(col[0], col[1], col[2]);
            const lines = [];
            for (let p = 1; p < points.length; p++) {
                lines.push({ op: 'l', c: [points[p][0], points[p][1]] });
            }
            lines.push({ op: 'h', c: [] }); // close path

            doc.internal.write(
                `${points[0][0].toFixed(2)} ${(H - points[0][1]).toFixed(2)} m`
            );
            for (let p = 1; p < points.length; p++) {
                doc.internal.write(
                    `${points[p][0].toFixed(2)} ${(H - points[p][1]).toFixed(2)} l`
                );
            }
            doc.internal.write('h');
            doc.internal.write(`${col[0] / 255} ${col[1] / 255} ${col[2] / 255} rg`);
            doc.internal.write('f');

            // White border line from center to start of slice
            doc.setDrawColor(255, 255, 255);
            doc.setLineWidth(0.8);
            doc.line(centerX, centerY,
                centerX + radius * Math.cos(startAngle),
                centerY + radius * Math.sin(startAngle));

            startAngle = endAngle;
        });

        // Final border line to close last slice
        doc.setDrawColor(255, 255, 255);
        doc.setLineWidth(0.8);
        doc.line(centerX, centerY,
            centerX + radius * Math.cos(startAngle),
            centerY + radius * Math.sin(startAngle));

        // ---- LEGEND ----
        const legendX = centerX + radius + 20;
        let legendY = centerY - (slices.length * 7) / 2;

        slices.forEach((sl, idx) => {
            const col = pieColors[idx % pieColors.length];
            const pct = ((sl.subtotal / bData.total) * 100).toFixed(1);

            // Color swatch
            doc.setFillColor(col[0], col[1], col[2]);
            doc.rect(legendX, legendY - 2.5, 4, 4, 'F');

            // Text
            doc.setFontSize(7);
            doc.setFont('helvetica', 'bold');
            doc.setTextColor(30, 30, 50);
            doc.text(`${sl.roomType}`, legendX + 6, legendY);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(100, 100, 120);
            doc.text(`${pct}%  •  N${formatNum(sl.subtotal)}`, legendX + 6, legendY + 3.5);

            legendY += 10;
        });

        // ==========================================
        // PAGE 5: AUDIT RECONCILIATION
        // ==========================================
        doc.addPage();
        renderHeader('AUDIT RECONCILIATION');

        y = 50;
        doc.setFontSize(10);
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(30, 30, 50);
        doc.text('SHIFT RECONCILIATION SUMMARY', m, y);
        y += 8;

        doc.setFontSize(8);
        doc.setTextColor(50, 50, 70);

        // System Expected Box
        doc.setFont('helvetica', 'bold'); doc.text('SYSTEM EXPECTED REVENUE', m, y);
        doc.setFont('helvetica', 'normal');
        doc.text(`Base Room Sales:`, m, y + 6); doc.text(`N${formatNum(sysBase)}`, m + 60, y + 6, { align: 'right' });
        doc.text(`Overtime Charges:`, m, y + 12); doc.text(`N${formatNum(sysOT)}`, m + 60, y + 12, { align: 'right' });
        doc.text(`Double Sales:`, m, y + 18); doc.text(`N${formatNum(sysFraud)}`, m + 60, y + 18, { align: 'right' });
        doc.setFont('helvetica', 'bold');
        doc.text(`TOTAL SYSTEM BASE:`, m, y + 26); doc.text(`N${formatNum(sysTotal)}`, m + 60, y + 26, { align: 'right' });

        // Tenders
        const m2 = m + 80;
        doc.setFont('helvetica', 'bold'); doc.text('DECLARED TENDERS', m2, y);
        doc.setFont('helvetica', 'normal');
        doc.text(`Cash:`, m2, y + 6); doc.text(`N${formatNum(cash)}`, m2 + 55, y + 6, { align: 'right' });
        doc.text(`POS:`, m2, y + 12); doc.text(`N${formatNum(pos)}`, m2 + 55, y + 12, { align: 'right' });
        doc.text(`Transfer:`, m2, y + 18); doc.text(`N${formatNum(transfer)}`, m2 + 55, y + 18, { align: 'right' });
        doc.setFont('helvetica', 'bold');
        doc.text(`TOTAL DECLARED:`, m2, y + 26); doc.text(`N${formatNum(declaredTotal)}`, m2 + 55, y + 26, { align: 'right' });

        y += 35;
        // Adjustments & Final Variance
        doc.setFont('helvetica', 'bold'); doc.text('NET ADJUSTMENTS', m, y);
        doc.setFont('helvetica', 'normal');
        let ay = y + 6;
        if (reconAdjustments.length === 0) {
            doc.text('No adjustments included.', m, ay); ay += 6;
        } else {
            reconAdjustments.forEach(adj => {
                const sym = adj.type === '+' ? '+' : '-';
                doc.text(`${sym} ${adj.label.substring(0, 20)}:  N${formatNum(adj.amount)}`, m, ay); ay += 6;
            });
        }
        doc.setFont('helvetica', 'bold');
        doc.text(`ADJUSTED EXPECTED: N${formatNum(netExpected)}`, m, ay);

        y = ay + 15;
        // Variance display
        doc.setFillColor(240, 240, 248);
        doc.rect(m, y, W - m * 2, 14, 'F');
        const statusText = variance === 0 ? 'BALANCED' : variance < 0 ? 'SHORTAGE' : 'SURPLUS';
        const vCol = variance === 0 ? [102, 187, 106] : variance < 0 ? [239, 83, 80] : [41, 182, 246];

        doc.setFontSize(10);
        doc.setTextColor(80, 80, 100);
        doc.text('FINAL AUDIT VARIANCE:', m + 5, y + 9);
        doc.setFontSize(12);
        doc.setTextColor(vCol[0], vCol[1], vCol[2]);
        const vSign = variance < 0 ? '-' : '';
        doc.text(`${statusText}   ${vSign}N${formatNum(Math.abs(variance))}`, W - m - 5, y + 9, { align: 'right' });

        // ==========================================
        // PAGE 6: DOUBLE SOLD ROOMS
        // ==========================================
        doc.addPage('a4', 'l');
        W = doc.internal.pageSize.getWidth();
        H = doc.internal.pageSize.getHeight();
        renderHeader('DOUBLE SOLD ROOMS (SAME DAY CHECK-INS)');

        if (doubleSalesData.length > 0) {
            // Build rows with full check-in log text — with defensive null checks
            const dsRows = [];
            const dsRectifiedMeta = []; // track rectification state per row
            doubleSalesData.forEach(d => {
                const logs = d.checkInLogs || [];
                if (logs.length === 0) return; // skip corrupt entries

                const firstType = logs[0].roomType || 'Unknown';
                const rate = logs[0].dailyRate || 0;

                // Calculate fraud excluding verified/cleared entries
                const unverifiedLogs = logs.filter((log, j) => j > 0 && !log.isVerified);
                const computedFraudValue = unverifiedLogs.reduce((sum, log) => sum + (log.dailyRate || 0), 0);
                const activeValue = d.isRectified ? (d.rectifiedAmount || 0) : computedFraudValue;

                let fraudCount = 0;
                if (rate > 0) {
                    fraudCount = +(activeValue / rate).toFixed(2);
                } else {
                    fraudCount = unverifiedLogs.length;
                }

                // Build check-in logs text block — with verification status
                const logsText = logs.map((log, j) => {
                    const isVerified = !!log.isVerified;
                    const isFraud = j > 0;
                    let tag = '';
                    if (isVerified) {
                        tag = '  [CLEARED]';
                    } else if (isFraud) {
                        tag = '  [UNREMITTED]';
                    }
                    const prefix = isVerified ? '~' : '';
                    return `${prefix}${j + 1}. In: ${fmtDT(log.checkIn)}  Out: ${fmtDT(log.actualOut)}\n   Rate: N${formatNum(log.dailyRate || 0)} / Exp: ${fmtDT(log.expectedOut)}${tag}`;
                }).join('\n');

                // DS value display — two lines if rectified
                let fraudDisplay = 'N' + formatNum(activeValue);
                if (d.isRectified) {
                    fraudDisplay = 'N' + formatNum(d.fraudValue || 0) + '\nN' + formatNum(d.rectifiedAmount || 0);
                }

                dsRows.push([
                    d.dateStr || '—',
                    d.roomName || '—',
                    logs.length + ' times',
                    firstType,
                    logsText,
                    String(fraudCount),
                    fraudDisplay
                ]);

                dsRectifiedMeta.push({
                    isRectified: !!d.isRectified,
                    originalVal: 'N' + formatNum(d.fraudValue || 0),
                    rectifiedVal: 'N' + formatNum(d.rectifiedAmount || 0),
                    note: d.rectifiedNote || '',
                    logs: logs // pass logs for per-entry strikethrough
                });
            });

            if (dsRows.length > 0) {
                doc.autoTable({
                    startY: 40,
                    head: [['Date', 'Room #', '# Times Sold', 'Room Type', 'Check-In Logs', 'DS Count', 'Total DS Value']],
                    body: dsRows,
                    margin: { left: m, right: m },
                    styles: { font: 'helvetica', fontSize: 7, cellPadding: 3, lineColor: [220, 220, 235], lineWidth: 0.3, overflow: 'linebreak', textColor: [30, 30, 50] },
                    headStyles: { fillColor: [24, 24, 40], textColor: [255, 186, 8], fontStyle: 'bold', fontSize: 7.5 },
                    columnStyles: {
                        0: { cellWidth: 28 },
                        1: { cellWidth: 30, fontStyle: 'bold' },
                        2: { cellWidth: 20, halign: 'center', fontStyle: 'bold', textColor: [255, 186, 8] },
                        3: { cellWidth: 30 },
                        4: { cellWidth: 'auto', fontSize: 6.5, textColor: [30, 30, 50] },
                        5: { cellWidth: 18, halign: 'center', fontStyle: 'bold' },
                        6: { cellWidth: 30, halign: 'right', fontStyle: 'bold', textColor: [239, 83, 80] }
                    },
                    didDrawCell: (data) => {
                        if (data.section !== 'body') return;
                        const rowIdx = data.row.index;
                        const meta = dsRectifiedMeta[rowIdx];
                        if (!meta) return;

                        // Strikethrough on original fraud value for rectified entries
                        if (data.column.index === 6 && meta.isRectified) {
                            const cellX = data.cell.x;
                            const cellY = data.cell.y;
                            const cellW = data.cell.width;
                            const padding = data.cell.styles.cellPadding || 3;

                            const lineY = cellY + padding + 2.5;
                            doc.setDrawColor(150, 150, 150);
                            doc.setLineWidth(0.4);
                            doc.line(cellX + cellW - padding - doc.getTextWidth(meta.originalVal) - 1, lineY, cellX + cellW - padding + 1, lineY);

                            const rectY = lineY + 5;
                            doc.setTextColor(102, 187, 106);
                            doc.setFontSize(7);
                            doc.setFont('helvetica', 'bold');
                            doc.text(meta.rectifiedVal, cellX + cellW - padding, rectY, { align: 'right' });

                            if (meta.note) {
                                doc.setFontSize(5);
                                doc.setTextColor(180, 180, 200);
                                doc.text('Sighted', cellX + cellW - padding, rectY + 4, { align: 'right' });
                            }
                        }

                        // Draw strikethrough lines on verified/cleared log entries
                        if (data.column.index === 4 && meta.logs) {
                            const cellX = data.cell.x;
                            const cellY = data.cell.y;
                            const cellW = data.cell.width;
                            const padding = data.cell.styles.cellPadding || 3;
                            const totalLogs = meta.logs.length;
                            const logBlockH = (data.cell.height - padding * 2) / Math.max(totalLogs, 1);

                            meta.logs.forEach((log, j) => {
                                if (log.isVerified) {
                                    // Draw strikethrough line across the log entry
                                    const entryY = cellY + padding + (j * logBlockH) + logBlockH / 2;
                                    doc.setDrawColor(102, 187, 106);
                                    doc.setLineWidth(0.3);
                                    doc.line(cellX + padding, entryY, cellX + cellW - padding, entryY);
                                }
                            });
                        }
                    }
                });
            } else {
                doc.setFontSize(10); doc.setTextColor(30, 30, 50); doc.text("No valid double sales data.", m, 45);
            }
        } else {
            doc.setFontSize(10); doc.setTextColor(30, 30, 50); doc.text("No double sales detected.", m, 45);
        }

        // ==========================================
        // PAGE 7: OVERTIME RECORDS
        // ==========================================
        doc.addPage('a4', 'l');
        renderHeader('OVERTIME RECORDS');

        if (overtimeData.length > 0) {
            const sorted = [...overtimeData].sort((a, b) => b.diffHours - a.diffHours);
            const otRectMeta = []; // track rectification per row
            const otRows = sorted.map((d, i) => {
                // Show both values on separate lines if rectified
                let chargeDisplay = 'N' + formatNum(d.overtimeCharge);
                if (d.isRectified) {
                    chargeDisplay = 'N' + formatNum(d.overtimeCharge) + '\nN' + formatNum(d.rectifiedAmount);
                }
                otRectMeta.push({
                    isRectified: !!d.isRectified,
                    originalVal: 'N' + formatNum(d.overtimeCharge),
                    rectifiedVal: 'N' + formatNum(d.rectifiedAmount || 0),
                    note: d.rectifiedNote || ''
                });
                return [
                    String(i + 1),
                    d.roomType,
                    fmtDT(d.checkIn),
                    fmtDT(d.expectedOut),
                    fmtDT(d.actualOut),
                    formatOvertimeDuration(d.diffHours),
                    'N' + formatNum(d.dailyRate) + '/ng',
                    chargeDisplay
                ];
            });

            doc.autoTable({
                startY: 40,
                head: [['#', 'Room Type', 'Check-In', 'Expected Out', 'Actual Out', 'Overtime', 'Daily Rate', 'OT Charge']],
                body: otRows,
                margin: { left: m, right: m },
                styles: { font: 'helvetica', fontSize: 7.5, cellPadding: 3, lineColor: [220, 220, 235], lineWidth: 0.3 },
                headStyles: { fillColor: [15, 15, 35], textColor: [255, 255, 255], fontStyle: 'bold' },
                columnStyles: {
                    0: { halign: 'center', cellWidth: 10 },
                    1: { fontStyle: 'bold' },
                    5: { fontStyle: 'bold', textColor: [239, 83, 80] },
                    7: { halign: 'right', fontStyle: 'bold', textColor: [239, 83, 80] }
                },
                didDrawCell: (data) => {
                    // Strikethrough on original OT charge for rectified entries
                    if (data.column.index === 7 && data.section === 'body') {
                        const meta = otRectMeta[data.row.index];
                        if (meta && meta.isRectified) {
                            const cellX = data.cell.x;
                            const cellY = data.cell.y;
                            const cellW = data.cell.width;
                            const padding = data.cell.styles.cellPadding || 3;

                            // Strikethrough line on original value
                            const lineY = cellY + padding + 2.5;
                            doc.setDrawColor(150, 150, 150);
                            doc.setLineWidth(0.4);
                            doc.line(cellX + cellW - padding - doc.getTextWidth(meta.originalVal) - 1, lineY, cellX + cellW - padding + 1, lineY);

                            // Green rectified value below
                            const rectY = lineY + 5;
                            doc.setTextColor(102, 187, 106);
                            doc.setFontSize(7.5);
                            doc.setFont('helvetica', 'bold');
                            doc.text(meta.rectifiedVal, cellX + cellW - padding, rectY, { align: 'right' });

                            if (meta.note) {
                                doc.setFontSize(5);
                                doc.setTextColor(180, 180, 200);
                                doc.text('Sighted', cellX + cellW - padding, rectY + 4, { align: 'right' });
                            }
                        }
                    }
                }
            });
        } else {
            doc.setFontSize(10); doc.setTextColor(100, 100, 120); doc.text("No overtime guests detected.", m, 45);
        }

        // ==========================================
        // PAGE 8: OVERTIME RISK ANALYSIS
        // ==========================================
        doc.addPage('a4', 'p');
        W = doc.internal.pageSize.getWidth(); // Reset W/H for Portrait
        H = doc.internal.pageSize.getHeight();
        renderHeader('OVERTIME RISK ANALYSIS');

        y = 45;

        const rM = document.getElementById('riskMinorCount').textContent;
        const rMA = document.getElementById('riskMinorAmt').textContent;
        const rW = document.getElementById('riskWarningCount').textContent;
        const rWA = document.getElementById('riskWarningAmt').textContent;
        const rC = document.getElementById('riskCriticalCount').textContent;
        const rCA = document.getElementById('riskCriticalAmt').textContent;

        const rCards = [
            { l: 'Minor Risk (<= 4 hrs)', v: rM, a: rMA, c: [102, 187, 106] },
            { l: 'Warning (4 - 12 hrs)', v: rW, a: rWA, c: [255, 167, 38] },
            { l: 'Critical Risk (> 12 hrs)', v: rC, a: rCA, c: [239, 83, 80] }
        ];

        doc.setFont('helvetica', 'normal');
        doc.setTextColor(100, 100, 120);
        doc.setFontSize(9);
        doc.text("Breakdown of risk categorizations based on overtime durations.", m, y);
        y += 10;

        rCards.forEach(c => {
            doc.setFillColor(248, 248, 252);
            doc.roundedRect(m, y, W - m * 2, 28, 2, 2, 'F');
            doc.setFillColor(...c.c);
            doc.rect(m, y, 4, 28, 'F');

            doc.setFontSize(9);
            doc.setTextColor(80, 80, 100);
            doc.setFont('helvetica', 'bold');
            doc.text(c.l, m + 10, y + 8);

            doc.setFontSize(18);
            doc.setTextColor(...c.c);
            doc.text(c.v, m + 10, y + 17);

            doc.setFontSize(8);
            doc.setTextColor(120, 120, 140);
            doc.text(`Expected:  `, m + 10, y + 24);
            doc.setTextColor(...c.c);
            let rawStr = c.a.replace(/₦|N/g, '').trim();
            doc.text(`N${rawStr}`, m + 26, y + 24);

            y += 34;
        });

        // FOOTER FOR ALL PAGES (EXCEPT COVER)
        const pages = doc.internal.getNumberOfPages();
        for (let p = 2; p <= pages; p++) {
            doc.setPage(p);
            let currW = doc.internal.pageSize.getWidth();
            let currH = doc.internal.pageSize.getHeight();

            doc.setDrawColor(220, 220, 235);
            doc.setLineWidth(0.3);
            doc.line(m, currH - 12, currW - m, currH - 12);
            doc.setFontSize(7);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(140, 140, 165);
            doc.text('MiAuditOps  •  ' + dateStr, m, currH - 6);
            doc.text('Page ' + p + ' of ' + pages, currW - m, currH - 6, { align: 'right' });
        }

        doc.save(`Audit_Export_${authId}_${new Date().toISOString().slice(0, 10).replace(/-/g, '')}.pdf`);
    } catch (err) {
        alert("PDF Generation Error: " + err.message);
        console.error(err);
    }
}

// ============ CLEAR FILE ============
function clearFile() {
    fileInput.value = '';
    fileStrip.classList.remove('show');
    results.classList.remove('show');
    emptyState.style.display = '';
    auditData = [];
    overtimeData = [];
    doubleSalesData = [];
    reconAdjustments = [];
    document.getElementById('reconCash').value = '';
    document.getElementById('reconPOS').value = '';
    document.getElementById('reconTransfer').value = '';
}

// ============ HELPERS ============
function toggleSection(id, el) {
    const wrap = document.getElementById(id);
    const chev = el.querySelector('.chevron');
    if (wrap.classList.contains('open')) {
        wrap.classList.remove('open');
        if (chev) { chev.classList.remove('rotate-up'); }
    } else {
        wrap.classList.add('open');
        if (chev) { chev.classList.add('rotate-up'); }
    }
}

function formatNum(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); }
function formatSize(bytes) { if (bytes < 1024) return bytes + ' B'; if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'; return (bytes / 1048576).toFixed(1) + ' MB'; }
function escHtml(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }

function formatOvertimeDuration(hours) {
    if (hours < 1) return Math.round(hours * 60) + 'min';
    const h = Math.floor(hours);
    const m = Math.round((hours - h) * 60);
    if (m === 0) return h + 'h';
    return h + 'h ' + m + 'min';
}

function fmtDT(date) {
    if (!date) return '—';
    const d = date.getDate().toString().padStart(2, '0');
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const mo = months[date.getMonth()];
    const y = date.getFullYear();
    const h = date.getHours().toString().padStart(2, '0');
    const mi = date.getMinutes().toString().padStart(2, '0');
    return `${d} ${mo} ${y}, ${h}:${mi}`;
}

function animateValue(id, finalText) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.opacity = '0';
    el.style.transform = 'translateY(8px)';
    setTimeout(() => {
        el.textContent = finalText;
        el.style.transition = 'all 0.4s ease-out';
        el.style.opacity = '1';
        el.style.transform = 'translateY(0)';
    }, 100);
}

// ============ RECTIFICATION ============
function openRectifyModal(source, idx) {
    const rm = document.getElementById('rectifyModal');
    if (!rm) { console.error('rectifyModal element not found'); return; }
    const d = source === 'ot' ? overtimeData[idx] : doubleSalesData[idx];
    if (!d) { console.error('No data found for index', idx, 'source', source); return; }

    document.getElementById('rectIdx').value = idx;
    document.getElementById('rectSource').value = source;

    if (source === 'ot') {
        document.getElementById('rectDesc').textContent = `Room ${d.roomType} - Overtime Penalty`;
        document.getElementById('rectSysVal').textContent = '₦' + formatNum(d.overtimeCharge);
        document.getElementById('rectInputVal').value = d.isRectified ? d.rectifiedAmount : d.overtimeCharge;
    } else {
        document.getElementById('rectDesc').textContent = `Room ${d.roomName} - Double Sales Fraud`;
        document.getElementById('rectSysVal').textContent = '₦' + formatNum(d.fraudValue);
        document.getElementById('rectInputVal').value = d.isRectified ? d.rectifiedAmount : d.fraudValue;
    }

    document.getElementById('rectNote').value = d.rectifiedNote || '';
    rm.classList.add('show');
}

function closeRectifyModal() {
    document.getElementById('rectifyModal').classList.remove('show');
}

function saveRectification() {
    const source = document.getElementById('rectSource').value;
    const idx = parseInt(document.getElementById('rectIdx').value, 10);
    const amt = parseFloat(document.getElementById('rectInputVal').value) || 0;
    const note = document.getElementById('rectNote').value.trim();

    const d = source === 'ot' ? overtimeData[idx] : doubleSalesData[idx];
    const sysVal = source === 'ot' ? d.overtimeCharge : d.fraudValue;

    // Only mark rectified if it's different from system, or has a note
    if (amt !== sysVal || note) {
        d.isRectified = true;
        d.rectifiedAmount = amt;
        d.rectifiedNote = note;
    } else {
        d.isRectified = false;
        d.rectifiedAmount = null;
        d.rectifiedNote = null;
    }

    closeRectifyModal();
    saveAuditState(false);

    if (source === 'ot') renderOvertimeTable();
    else renderDoubleSalesTable();

    calculateRecon();
}

// ============ PERSISTENCE — MULTI-INVESTIGATION SYSTEM ============

const INV_STORAGE_KEY = 'miauditops_investigations';

/**
 * Get all saved investigations from localStorage
 */
function getInvestigations() {
    try {
        return JSON.parse(localStorage.getItem(INV_STORAGE_KEY) || '[]');
    } catch (e) {
        return [];
    }
}

/**
 * Save the investigations registry back to localStorage
 */
function setInvestigations(list) {
    localStorage.setItem(INV_STORAGE_KEY, JSON.stringify(list));
}

/**
 * Save or update current audit state into the investigations registry
 */
function saveAuditState(manual = false) {
    if (auditData.length === 0) return;

    const id = currentSessionId || document.getElementById('auditIdText').textContent;
    currentSessionId = id;

    const state = {
        id,
        fileName: currentFileName || 'Unnamed File',
        savedAt: new Date().toISOString(),
        createdAt: null, // will be set below
        status: 'open',
        investigationStatus: investigationStatus || 'under_investigation',
        totalRecords: auditData.length,
        overtimeCount: overtimeData.length,
        doubleSalesCount: doubleSalesData.length,
        doubleSalesValue: doubleSalesData.reduce((s, d) => s + (d.isRectified ? d.rectifiedAmount : d.fraudValue), 0),
        auditData,
        overtimeData,
        doubleSalesData,
        reconAdjustments,
        tenders: {
            cash: document.getElementById('reconCash').value,
            pos: document.getElementById('reconPOS').value,
            transfer: document.getElementById('reconTransfer').value
        }
    };

    const investigations = getInvestigations();
    const existingIdx = investigations.findIndex(inv => inv.id === id);

    if (existingIdx !== -1) {
        // Update existing — preserve createdAt & status
        state.createdAt = investigations[existingIdx].createdAt;
        state.status = investigations[existingIdx].status;
        investigations[existingIdx] = state;
    } else {
        // New investigation
        state.createdAt = new Date().toISOString();
        investigations.unshift(state); // newest first
    }

    setInvestigations(investigations);

    // Also keep as active session for quick restore
    localStorage.setItem('miauditops_active_session', id);

    renderSavedInvestigations();

    if (manual) {
        showToast('Investigation saved successfully.');
    }
}

/**
 * Load an investigation by ID
 */
function loadInvestigation(id) {
    const investigations = getInvestigations();
    const inv = investigations.find(i => i.id === id);
    if (!inv) { alert('Investigation not found.'); return false; }

    // Show loading spinner
    const spin = document.getElementById('spinner');
    if (spin) spin.classList.add('show');
    try {
        // Re-hydrate dates
        const hydrateDates = (d) => {
            if (d.checkIn) d.checkIn = new Date(d.checkIn);
            if (d.expectedOut) d.expectedOut = new Date(d.expectedOut);
            if (d.actualOut) d.actualOut = new Date(d.actualOut);
        };

        inv.auditData.forEach(hydrateDates);
        inv.overtimeData.forEach(hydrateDates);
        inv.doubleSalesData.forEach(d => {
            if (d.checkInLogs) d.checkInLogs.forEach(hydrateDates);
        });

        auditData = inv.auditData;
        overtimeData = inv.overtimeData;
        doubleSalesData = inv.doubleSalesData;
        reconAdjustments = inv.reconAdjustments || [];
        currentSessionId = inv.id;
        currentFileName = inv.fileName || '';
        investigationStatus = inv.investigationStatus || 'under_investigation';

        // Restore investigation status dropdown
        const statusEl = document.getElementById('investigationStatus');
        if (statusEl) statusEl.value = investigationStatus;

        document.getElementById('auditIdText').textContent = inv.id;
        document.getElementById('reconCash').value = inv.tenders?.cash || '';
        document.getElementById('reconPOS').value = inv.tenders?.pos || '';
        document.getElementById('reconTransfer').value = inv.tenders?.transfer || '';

        renderSummary();
        renderDoubleSalesTable();
        renderOvertimeTable();
        renderAllTable();
        renderAdjustments();
        calculateRecon();

        document.getElementById('auditHeader').classList.add('show');
        document.getElementById('results').classList.add('show');
        document.getElementById('fileStrip').classList.add('show');
        document.getElementById('fileName').textContent = inv.fileName || 'Restored Session';
        document.getElementById('fileMeta').textContent = `${auditData.length} records • Loaded from saved investigation`;
        document.getElementById('emptyState').style.display = 'none';

        // Track active session
        localStorage.setItem('miauditops_active_session', id);

        renderSavedInvestigations();

        // Scroll to top of results
        document.getElementById('auditHeader').scrollIntoView({ behavior: 'smooth' });

        // Hide spinner
        if (spin) spin.classList.remove('show');

        return true;
    } catch (e) {
        if (spin) spin.classList.remove('show');
        console.error('Error loading investigation', e);
        alert('Failed to load investigation: ' + e.message);
        return false;
    }
}

/**
 * Delete an investigation by ID
 */
function deleteInvestigation(id, e) {
    if (e) e.stopPropagation();
    if (!confirm('Delete this investigation permanently? This cannot be undone.')) return;

    // Show loading spinner
    const spin = document.getElementById('spinner');
    if (spin) spin.classList.add('show');

    // Brief delay for visual feedback
    setTimeout(() => {
        let investigations = getInvestigations();
        investigations = investigations.filter(i => i.id !== id);
        setInvestigations(investigations);

        // If we deleted the active session, clear the workspace
        if (currentSessionId === id) {
            currentSessionId = null;
            currentFileName = '';
            localStorage.removeItem('miauditops_active_session');
            clearFile();
            document.getElementById('auditHeader').classList.remove('show');
        }

        renderSavedInvestigations();
        if (spin) spin.classList.remove('show');
        showToast('Investigation deleted.');
    }, 400);
}

/**
 * Mark/toggle investigation status (open/closed)
 */
function toggleInvestigationStatus(id, e) {
    if (e) e.stopPropagation();
    const investigations = getInvestigations();
    const inv = investigations.find(i => i.id === id);
    if (!inv) return;

    inv.status = inv.status === 'open' ? 'closed' : 'open';
    setInvestigations(investigations);
    renderSavedInvestigations();

    showToast(inv.status === 'closed' ? 'Investigation marked as resolved.' : 'Investigation reopened.');
}

/**
 * Render saved investigations cards
 */
function renderSavedInvestigations() {
    const investigations = getInvestigations();
    const grid = document.getElementById('savedInvGrid');
    const empty = document.getElementById('invEmpty');
    const badge = document.getElementById('invCountBadge');

    if (!grid) return;

    badge.textContent = investigations.length;

    if (investigations.length === 0) {
        grid.innerHTML = '';
        grid.style.display = 'none';
        empty.style.display = '';
        return;
    }

    grid.style.display = '';
    empty.style.display = 'none';

    grid.innerHTML = investigations.map(inv => {
        const isActive = currentSessionId === inv.id;
        const isOpen = inv.status !== 'closed';
        const savedDate = new Date(inv.savedAt);
        const createdDate = new Date(inv.createdAt);
        const dateStr = savedDate.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        const timeStr = savedDate.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        const createdStr = createdDate.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        const fraudVal = inv.fraudValue || inv.doubleSalesData?.reduce((s, d) => s + (d.isRectified ? d.rectifiedAmount : d.fraudValue), 0) || 0;

        return `<div class="inv-card ${isActive ? 'active-case' : ''}" ondblclick="loadInvestigation('${escHtml(inv.id)}')">
            <div class="inv-card-header">
                <div class="inv-card-icon ${isOpen ? 'open' : 'closed'}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        ${isOpen
                ? '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'
                : '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'}
                    </svg>
                </div>
                <div class="inv-header-text">
                    <div class="inv-title" title="Click to rename" style="cursor:pointer" onclick="renameInvestigation('${escHtml(inv.id)}', event)">
                        ${escHtml(inv.fileName)}
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;opacity:.4;margin-left:4px;flex-shrink:0"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </div>
                    <div class="inv-audit-id">${escHtml(inv.id)}</div>
                    <div class="inv-date">Created: ${createdStr} • Updated: ${dateStr}, ${timeStr}</div>
                </div>
            </div>
            <div class="inv-stats">
                <div class="inv-stat">
                    <span class="inv-stat-val">${inv.totalRecords || inv.auditData?.length || 0}</span>
                    <span class="inv-stat-lbl">Records</span>
                </div>
                <div class="inv-stat">
                    <span class="inv-stat-val" style="color:var(--accent3)">${inv.overtimeCount || inv.overtimeData?.length || 0}</span>
                    <span class="inv-stat-lbl">Overtime</span>
                </div>
                <div class="inv-stat">
                    <span class="inv-stat-val" style="color:#ffba08">${inv.doubleSalesCount || inv.doubleSalesData?.length || 0}</span>
                    <span class="inv-stat-lbl">Double Sales</span>
                </div>
                <div class="inv-stat">
                    <span class="inv-stat-val" style="color:#ef5350">₦${formatNum(fraudVal)}</span>
                    <span class="inv-stat-lbl">Fraud Value</span>
                </div>
            </div>
            <div class="inv-footer">
                <button class="inv-btn inv-btn-load" onclick="loadInvestigation('${escHtml(inv.id)}')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><path d="M21 3l-7 7"/><path d="M21 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h6"/></svg>
                    Open
                </button>
                <button class="inv-btn" onclick="toggleInvestigationStatus('${escHtml(inv.id)}', event)">
                    ${isOpen
                ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>Resolve'
                : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>Reopen'}
                </button>
                <button class="inv-btn inv-btn-danger" onclick="deleteInvestigation('${escHtml(inv.id)}', event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    Delete
                </button>
            </div>
        </div>`;
    }).join('');
}

/**
 * Rename an investigation title — inline edit
 */
function renameInvestigation(id, e) {
    if (e) { e.stopPropagation(); e.preventDefault(); }

    const investigations = getInvestigations();
    const inv = investigations.find(i => i.id === id);
    if (!inv) return;

    // Find the title element that was clicked
    const titleEl = e ? e.currentTarget : null;
    if (!titleEl) return;

    // Prevent double-activation
    if (titleEl.querySelector('input')) return;

    // Create inline input
    const input = document.createElement('input');
    input.type = 'text';
    input.value = inv.fileName;
    input.style.cssText = 'width:100%;padding:4px 8px;border:2px solid var(--accent);border-radius:6px;background:var(--bg-card);color:var(--text);font-size:.85rem;font-weight:600;font-family:inherit;outline:none;box-sizing:border-box';

    // Replace title content with input
    titleEl.innerHTML = '';
    titleEl.appendChild(input);
    input.focus();
    input.select();

    // Save handler
    const save = () => {
        const newName = input.value.trim();
        if (newName && newName !== inv.fileName) {
            inv.fileName = newName;
            setInvestigations(investigations);

            if (currentSessionId === id) {
                currentFileName = newName;
                const nameEl = document.getElementById('fileName');
                if (nameEl) nameEl.textContent = newName;
            }
            showToast('Investigation renamed.');
        }
        renderSavedInvestigations();
    };

    input.addEventListener('blur', save);
    input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); input.blur(); }
        if (ev.key === 'Escape') { renderSavedInvestigations(); }
    });
}

/**
 * Show a small toast notification
 */
function showToast(msg) {
    let t = document.getElementById('miaudit-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'miaudit-toast';
        t.style.cssText = 'position:fixed;bottom:28px;right:28px;z-index:9999;padding:12px 22px;border-radius:10px;background:var(--accent);color:#fff;font-size:.85rem;font-weight:600;font-family:Inter,sans-serif;box-shadow:0 6px 24px rgba(108,99,255,.35);opacity:0;transform:translateY(12px);transition:all .35s ease;pointer-events:none';
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

/**
 * Legacy: load the last active session on page load
 */
function loadAuditState() {
    const activeId = localStorage.getItem('miauditops_active_session');
    if (!activeId) return false;

    const investigations = getInvestigations();
    const inv = investigations.find(i => i.id === activeId);
    if (!inv) {
        // Clean up stale reference
        localStorage.removeItem('miauditops_active_session');
        return false;
    }

    return loadInvestigation(activeId);
}

/**
 * Close the active session (workspace only — investigation stays saved)
 */
function clearLocalSession() {
    if (confirm('Close the active workspace? Your investigation is already saved and can be reopened from the Saved Investigations panel.')) {
        currentSessionId = null;
        currentFileName = '';
        localStorage.removeItem('miauditops_active_session');
        clearFile();
        document.getElementById('auditHeader').classList.remove('show');
        renderSavedInvestigations();
    }
}

// One-time migration: move legacy single-session into investigations registry
function migrateLegacySession() {
    const raw = localStorage.getItem('miauditops_active_session');
    if (!raw) return;

    // If it's a JSON blob (old format), migrate it
    try {
        const state = JSON.parse(raw);
        if (state && state.auditData && state.auditData.length > 0) {
            const investigations = getInvestigations();
            // Check not already migrated
            if (!investigations.some(i => i.id === state.id)) {
                // Try to get the file name from the file strip element
                const fileEl = document.getElementById('fileName');
                const legacyName = (fileEl && fileEl.textContent && fileEl.textContent !== '—')
                    ? fileEl.textContent
                    : (state.fileName || 'Imported Session');

                const inv = {
                    id: state.id,
                    fileName: legacyName,
                    savedAt: new Date().toISOString(),
                    createdAt: new Date().toISOString(),
                    status: 'open',
                    totalRecords: state.auditData.length,
                    overtimeCount: state.overtimeData?.length || 0,
                    doubleSalesCount: state.doubleSalesData?.length || 0,
                    fraudValue: 0,
                    auditData: state.auditData,
                    overtimeData: state.overtimeData || [],
                    doubleSalesData: state.doubleSalesData || [],
                    reconAdjustments: state.reconAdjustments || [],
                    tenders: state.tenders || {}
                };
                investigations.unshift(inv);
                setInvestigations(investigations);
            }
            // Replace the old JSON blob with just the ID
            localStorage.setItem('miauditops_active_session', state.id);
        }
    } catch (e) {
        // Not JSON (already an ID string) — no migration needed
    }
}

document.addEventListener('DOMContentLoaded', () => {
    migrateLegacySession();
    renderSavedInvestigations();
    loadAuditState();
});
