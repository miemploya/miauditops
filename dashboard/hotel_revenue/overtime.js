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
let mgtSalesData = []; // Management Sales reports
let earlyCheckinData = []; // Checked-in during 1 AM to 12 PM
let currentSessionId = null; // ID of the currently active investigation
let currentFileName = '';    // Source file name of active investigation
let investigationStatus = 'under_investigation'; // under_investigation | concluded | final

// Mapping State
let currentlyParsedRows = null;
let customHeaders = [];

// Policy State
let policyGraceMins = parseInt(localStorage.getItem('policyGraceMins')) || 0;
let policyHalfHours = parseFloat(localStorage.getItem('policyHalfHours')) || 3.0;
let policyFullHours = parseFloat(localStorage.getItem('policyFullHours')) || 8.0;

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
            detectAndRouteData(rows);
        } catch (err) {
            alert('Error: ' + err.message);
        } finally {
            spinner.classList.remove('show');
        }
    };
    reader.readAsArrayBuffer(file);
}

// ============ ROUTING ============
function detectAndRouteData(rows) {
    const headers = rows[0].map(h => String(h).trim().toLowerCase());
    
    // Check for Option 1 Features (PMS)
    let hasPMSCheckin = headers.some(h => h.includes('time of check') || (h.includes('check') && h.includes('in') && h.includes('out')));
    let hasPMSActualOut = headers.some(h => h.includes('default check') || h.includes('default checkout') || h.includes('actual check'));

    if (hasPMSCheckin && hasPMSActualOut) {
        if(typeof showToast === 'function') showToast('Detected PMS Export Format');
        processAudit(rows);
        return;
    }

    // Check for Option 2 Features (Standard Template)
    let hasTplRoom = headers.includes('room no');
    let hasTplCheckIn = headers.includes('check-in date/time');
    let hasTplExpOut = headers.includes('expected check-out');
    let hasTplActOut = headers.includes('actual check-out');

    if (hasTplRoom && hasTplCheckIn && hasTplExpOut && hasTplActOut) {
        if(typeof showToast === 'function') showToast('Detected Standard Template Format');
        processTemplateUpload(rows, headers);
        return;
    }

    // Fallback: Option 3 (Custom Mapping)
    currentlyParsedRows = rows;
    customHeaders = rows[0].map(h => String(h).trim());
    openMappingModal(customHeaders);
}

// ============ PROCESS AUDIT ============
function processAudit(rows) {
    const headers = rows[0].map(h => String(h).trim().toLowerCase());

    // Find columns
    let checkinCol = -1;   // "Time of check-in & check-out"
    let actualOutCol = -1; // "Default Check-Out Time"
    let roomTypeCol = -1;  // "Room type" (contains room rate)
    let roomCol = -1;      // "Room" (for double sales grouping)
    let nameCol = -1;      // "Customer Name" or "Guest"

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
        if (h.includes('name') || h.includes('guest') || h.includes('customer')) {
            nameCol = i;
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
        const customerName = nameCol !== -1 ? String(rows[r][nameCol] || '').trim() : 'N/A';

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
        let diffHours = diffMs / (1000 * 60 * 60);
        let isOvertime = diffHours > 0;

        if (isOvertime && (diffMs / 60000) <= policyGraceMins) {
            isOvertime = false;
        }

        // Overtime charge: tiered billing logic
        let overtimeCharge = 0;
        let capType = 'None';
        if (isOvertime && dailyRate > 0) {
            if (diffHours <= policyHalfHours) {
                capType = 'Fractional';
                overtimeCharge = (dailyRate / 24) * diffHours;
            } else if (diffHours > policyHalfHours && diffHours <= policyFullHours) {
                capType = 'Half-Day';
                overtimeCharge = dailyRate * 0.50;
            } else {
                capType = 'Full-Day';
                overtimeCharge = dailyRate;
            }
        }

        auditData.push({
            checkIn: dates.checkIn,
            expectedOut: dates.expectedOut,
            actualOut,
            roomName: roomNameRaw,
            customerName,
            roomType,
            amount,
            stayNights,
            dailyRate,
            diffHours,
            isOvertime,
            overtimeCharge,
            capType,
            checkinRaw,
            actualRaw
        });
    }

    finalizeAudit();
}

// ============ FINALIZE AUDIT ============
function finalizeAudit() {
    if (auditData.length === 0) { alert('No records could be parsed.'); return; }

    // Filter overtime records
    overtimeData = auditData.filter(d => d.isOvertime);

    // Calculate Double Sales
    // Calculate Double Sales and Early Check-ins
    calculateDoubleSales();
    calculateEarlyCheckins();

    // Reset recon fields
    document.getElementById('reconCash').value = '';
    document.getElementById('reconPOS').value = '';
    document.getElementById('reconTransfer').value = '';
    reconAdjustments = [];
    mgtSalesData = [];

    renderSummary();
    renderDoubleSalesTable();
    renderEarlyCheckinsTable();
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

// ============ CALCULATE DOUBLE SALES (HYBRID ENGINE) ============
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

        // Isolate each specific calendar day
        Object.keys(dateGroups).forEach(dateStr => {
            const dayBookings = dateGroups[dateStr];
            
            // Only care if there are MULTIPLE check-ins on the exact same day
            if (dayBookings.length > 1) {
                // Sort chronologically
                dayBookings.sort((a, b) => a.checkIn - b.checkIn);
                
                let dayFraudVal = 0;
                
                // Compare subsequent bookings against the previous one on that day
                for (let i = 1; i < dayBookings.length; i++) {
                    const current = dayBookings[i];
                    const prev = dayBookings[i-1];
                    const anchor = dayBookings[0];
                    
                    // 1. CRITICAL: Time Overlap (Current checked in BEFORE Previous checked out)
                    if (current.checkIn < prev.actualOut) {
                        current.dsFlagType = 'CRITICAL (Time Overlap)';
                    }
                    // 2. WARNING: Same-Day, but no explicit time overlap (sequential short-time)
                    else {
                        current.dsFlagType = 'WARNING (Same-Day Back-to-Back)';
                    }
                    
                    // Auto-clear ones that are already captured perfectly in Base Room Revenue
                    if (current.dailyRate > 0 && current.isVerified === undefined) {
                        current.isVerified = true;
                        current.autoCleared = true;
                    }
                    
                    // The financial value of the fraud is the actual price of the room
                    const trueLoss = anchor.dailyRate || current.dailyRate;
                    current.trueLossValue = trueLoss;
                    current.uncapturedLoss = Math.max(0, trueLoss - (current.dailyRate || 0));
                    
                    // Add fraud value for unverified double sales
                    if (!current.isVerified) {
                        dayFraudVal += trueLoss;
                        
                        if (current.dailyRate === 0) {
                            current.dsFlagType += ' [UNPRICED]';
                        }
                    }
                }
                
                // Push the single specific date group to the UI array
                doubleSalesData.push({
                    roomName: room,
                    dateStr: dateStr,
                    checkInLogs: dayBookings,
                    fraudValue: dayFraudVal
                });
            }
        });
    });

    // Sort by highest fraud value
    doubleSalesData.sort((a, b) => b.fraudValue - a.fraudValue);
}

// ============ CALCULATE EARLY CHECK-INS ============
function calculateEarlyCheckins() {
    earlyCheckinData = [];
    
    auditData.forEach(d => {
        if (!d.checkIn) return;
        const hr = d.checkIn.getHours();
        const min = d.checkIn.getMinutes();
        
        let riskLvl = null; // null | 'high' | 'warning'
        
        if (hr >= 0 && hr < 7) {
            // 12:00 AM (Midnight) to 6:59 AM
            riskLvl = 'high';
        }
        
        if (riskLvl) {
            // Detect "Stealth Checkout" pattern
            let isStealthCheckout = false;
            if (d.actualOut) {
                const sameDay = d.checkIn.toDateString() === d.actualOut.toDateString();
                const outHr = d.actualOut.getHours();
                if (sameDay && outHr < 9) {
                    isStealthCheckout = true;
                }
            }

            // Automatch with Base Revenue: if the cashier priced the room normally, auto-strike it
            if (d.dailyRate > 0 && d.isEarlyVerified === undefined) {
                d.isEarlyVerified = true;
                d.autoClearedEarly = true;
            }

            earlyCheckinData.push({
                record: d,
                risk: riskLvl,
                stealthOut: isStealthCheckout
            });
        }
    });

    // Sort by most extreme early time (closest to 1 AM)
    earlyCheckinData.sort((a, b) => {
        const timeA = a.record.checkIn.getHours() * 60 + a.record.checkIn.getMinutes();
        const timeB = b.record.checkIn.getHours() * 60 + b.record.checkIn.getMinutes();
        return timeA - timeB;
    });
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

    const sysBase = auditData.reduce((s, d) => s + (!d.revenueExemptType ? d.dailyRate : 0), 0);
    const sysOT = overtimeData.reduce((s, d) => s + (d.isRectified ? d.rectifiedAmount : d.overtimeCharge), 0);
    const sysFraud = doubleSalesData.reduce((s, d) => s + (d.isRectified ? d.rectifiedAmount : d.fraudValue), 0);
    
    // Only add fraud that WAS NOT already exported in the Base Room Revenue (to avoid double charging cashiers)
    let uncapturedFraud = 0;
    doubleSalesData.forEach(d => {
        if (d.isRectified && d.rectifiedAmount > 0) {
            // For forced manual rectifications, trust the auditor
            uncapturedFraud += d.rectifiedAmount;
        } else if (d.checkInLogs) {
            d.checkInLogs.forEach((log, j) => {
                if (j > 0 && !log.isVerified && log.uncapturedLoss) {
                    uncapturedFraud += log.uncapturedLoss;
                }
            });
        }
    });

    const sysTotal = sysBase + sysOT + uncapturedFraud;

    document.getElementById('reconBase').textContent = '₦' + formatNum(sysBase);
    document.getElementById('reconOT').textContent = '₦' + formatNum(sysOT);
    document.getElementById('reconFraud').textContent = '₦' + formatNum(sysFraud);
    document.getElementById('reconSysTotal').textContent = '₦' + formatNum(sysTotal);

    const declaredTotal = cash + pos + transfer;
    document.getElementById('reconDeclaredTotal').textContent = '₦' + formatNum(declaredTotal);

    let mgtTotal = 0;
    mgtSalesData.forEach(m => mgtTotal += m.amount);
    document.getElementById('reconMgtTotal').textContent = '₦' + formatNum(mgtTotal);

    let adjNet = 0;
    reconAdjustments.forEach(adj => {
        if (adj.type === '+') adjNet += adj.amount;
        if (adj.type === '-') adjNet -= adj.amount;
    });

    const netExpected = sysTotal + adjNet;
    document.getElementById('reconNetExpected').textContent = '₦' + formatNum(netExpected);

    // Hit 1: Declared vs Management
    const hit1 = declaredTotal - mgtTotal;
    const h1AmtEl = document.getElementById('reconHit1Amt');
    const h1StatEl = document.getElementById('reconHit1Status');
    h1AmtEl.textContent = (hit1 < 0 ? '-' : '') + '₦' + formatNum(Math.abs(hit1));
    if (hit1 === 0 && mgtTotal > 0) { h1AmtEl.style.color = 'var(--text)'; h1StatEl.className = 'v-status balanced'; h1StatEl.textContent = 'BALANCED'; }
    else if (hit1 < 0) { h1AmtEl.style.color = '#ef5350'; h1StatEl.className = 'v-status shortage'; h1StatEl.textContent = 'SHORTAGE'; }
    else if (hit1 > 0) { h1AmtEl.style.color = '#29b6f6'; h1StatEl.className = 'v-status surplus'; h1StatEl.textContent = 'SURPLUS'; }
    else { h1AmtEl.style.color = 'var(--text)'; h1StatEl.className = 'v-status balanced'; h1StatEl.textContent = '-'; }

    // Hit 2: Management vs Net Adjusted System
    const hit2 = mgtTotal - netExpected;
    const h2AmtEl = document.getElementById('reconHit2Amt');
    const h2StatEl = document.getElementById('reconHit2Status');
    h2AmtEl.textContent = (hit2 < 0 ? '-' : '') + '₦' + formatNum(Math.abs(hit2));
    if (hit2 === 0 && mgtTotal > 0) { h2AmtEl.style.color = 'var(--text)'; h2StatEl.className = 'v-status balanced'; h2StatEl.textContent = 'BALANCED'; }
    else if (hit2 < 0) { h2AmtEl.style.color = '#ef5350'; h2StatEl.className = 'v-status shortage'; h2StatEl.textContent = 'SHORTAGE'; }
    else if (hit2 > 0) { h2AmtEl.style.color = '#29b6f6'; h2StatEl.className = 'v-status surplus'; h2StatEl.textContent = 'SURPLUS'; }
    else { h2AmtEl.style.color = 'var(--text)'; h2StatEl.className = 'v-status balanced'; h2StatEl.textContent = '-'; }

    // Final Variance
    const variance = hit1 + hit2; // exactly equals (declared - netExpected)
    const vAmtEl = document.getElementById('reconVarianceAmt');
    const vStatusEl = document.getElementById('reconVarianceStatus');

    vAmtEl.textContent = (variance < 0 ? '-' : '') + '₦' + formatNum(Math.abs(variance));

    if (variance === 0 && declaredTotal > 0) {
        vAmtEl.style.color = 'var(--text)';
        vStatusEl.className = 'v-status balanced';
        vStatusEl.textContent = 'PERFECTLY BALANCED';
    } else if (variance < 0) {
        vAmtEl.style.color = '#ef5350';
        vStatusEl.className = 'v-status shortage';
        vStatusEl.textContent = 'SHORTAGE';
    } else if (variance > 0) {
        vAmtEl.style.color = '#29b6f6';
        vStatusEl.className = 'v-status surplus';
        vStatusEl.textContent = 'SURPLUS';
    } else {
        vAmtEl.style.color = 'var(--text)';
        vStatusEl.className = 'v-status balanced';
        vStatusEl.textContent = 'BALANCED';
    }

    // Auto-save on any recon change (debounced)
    clearTimeout(calculateRecon._debounce);
    calculateRecon._debounce = setTimeout(() => saveAuditState(false), 800);
}

function addMgtSales() {
    const lbl = document.getElementById('mgtSalesLabel').value.trim();
    const amt = parseFloat(document.getElementById('mgtSalesAmt').value) || 0;
    if (!lbl || amt <= 0) return;

    mgtSalesData.push({ id: Date.now(), label: lbl, amount: amt });
    document.getElementById('mgtSalesLabel').value = '';
    document.getElementById('mgtSalesAmt').value = '';
    renderMgtSales();
    calculateRecon();
    if (typeof saveAuditState === 'function') saveAuditState(false);
}

function removeMgtSales(id) {
    mgtSalesData = mgtSalesData.filter(m => m.id !== id);
    renderMgtSales();
    calculateRecon();
    if (typeof saveAuditState === 'function') saveAuditState(false);
}

function renderMgtSales() {
    const list = document.getElementById('mgtSalesList');
    if (mgtSalesData.length === 0) {
        list.innerHTML = '<div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:10px;text-align:center">No entries added.</div>';
        return;
    }
    list.innerHTML = mgtSalesData.map(a => `
        <div class="adj-item">
            <span>${escHtml(a.label)}</span>
            <div style="display:flex;gap:10px;align-items:center">
                <span style="font-weight:600;color:var(--text)">₦${formatNum(a.amount)}</span>
                <span class="a-del" onclick="removeMgtSales(${a.id})">✖</span>
            </div>
        </div>
    `).join('');
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
    if (!tbody) return;
    if (doubleSalesData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#66bb6a;font-weight:600">No double sales detected! All room check-ins appear legitimate today.</td></tr>';
        return;
    }

    let html = '';
    doubleSalesData.forEach((d, i) => {
        // Build the mini logs of all bookings
        let logsHTML = d.checkInLogs.map((log, j) => {
            const isVerified = !!log.isVerified;
            const strikeStyle = isVerified ? 'text-decoration:line-through;opacity:0.5;' : '';
            const isFraud = !!log.dsFlagType;
            let color = '#b4b4d2'; 
            if (isFraud) color = log.dsFlagType.includes('CRITICAL') ? '#ef5350' : '#ffa726';
            if (isVerified) color = '#66bb6a';

            const flagInfo = isFraud ? `<span style="color:${color};border:1px solid ${color};padding:1px 4px;border-radius:3px;font-size:9px;margin-left:5px;font-weight:bold">${log.dsFlagType}</span>` : '';
            const badgeText = log.autoCleared ? 'AUTO-CLEARED' : 'EXEMPTED';
            const verifiedBadge = isVerified ? `<span style="color:#66bb6a;border:1px solid rgba(102,187,106,0.3);padding:1px 4px;border-radius:3px;font-size:10px;margin-left:5px" title="${log.autoCleared ? 'System matched this to Base Room Revenue' : ''}">✓ ${badgeText}</span>` : '';
            
            const clickAttr = `onclick="toggleLogVerified(${i}, ${j})" style="cursor:pointer;${strikeStyle}font-size:0.75rem;margin-bottom:4px;color:${color}" title="Click to ${isVerified ? 'unmark' : 'mark as audited/exempted'}"`;
            return `<div ${clickAttr}>
                <strong>${j + 1}.</strong>
                Check-in: ${fmtDT(log.checkIn)} — Out: ${fmtDT(log.actualOut)}<br>
                <div style="padding-left:14px">Rate: ₦${formatNum(log.dailyRate)} / expected out: ${fmtDT(log.expectedOut)} ${flagInfo}${verifiedBadge}</div>
            </div>`;
        }).join('');

        const firstType = d.checkInLogs[0].roomType;
        const rate = d.checkInLogs[0].dailyRate;

        // Calculate fraud value excluding verified logs
        const unverifiedFraudLogs = d.checkInLogs.filter((log) => log.dsFlagType && !log.isVerified);
        let computedFraudValue = unverifiedFraudLogs.reduce((sum, log) => sum + (log.trueLossValue || log.dailyRate || 0), 0);
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

// ============ RENDER EARLY CHECK-INS TABLE ============
function renderEarlyCheckinsTable() {
    const tbody = document.getElementById('earlyCheckinsBody');
    if (!tbody) return;
    if (earlyCheckinData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-muted);font-weight:600">No early check-ins detected.</td></tr>';
        return;
    }

    let html = '';
    earlyCheckinData.forEach((item, i) => {
        const d = item.record;
        const isVerified = !!d.isEarlyVerified;
        const strikeStyle = isVerified ? 'text-decoration:line-through;opacity:0.5;' : '';
        const color = item.risk === 'high' ? '#ef5350' : '#ffa726';
        let badge = 'CRITICAL (12AM - 6AM)';
        if (d.dailyRate === 0) badge += ' [UNPRICED FRAUD]';
        
        if (d.autoClearedEarly) {
            badge += `<br><span style="display:inline-block;margin-top:3px;color:#66bb6a;border:1px solid #66bb6a;padding:1px 4px;border-radius:3px;font-size:9px" title="System matched this to Base Room Revenue">✓ AUTO-CLEARED</span>`;
        } else if (d.isEarlyVerified) {
            badge += `<br><span style="display:inline-block;margin-top:3px;color:#66bb6a;border:1px solid rgba(102,187,106,0.3);padding:1px 4px;border-radius:3px;font-size:9px">✓ EXEMPTED</span>`;
        }
        
        if (item.stealthOut) {
            badge += `<br><span style="display:inline-block;margin-top:3px;color:#d32f2f;background:rgba(211,47,47,0.1);border:1px solid #d32f2f;padding:1px 4px;border-radius:3px;font-size:8px">⚠️ STEALTH CHECKOUT</span>`;
        }

        html += `<tr style="${strikeStyle}">
            <td style="font-weight:600;font-size:.82rem">${d.checkIn.toDateString()}</td>
            <td style="font-weight:800;color:var(--text-main);font-size:.85rem">${escHtml(d.roomName)}</td>
            <td style="font-size:.8rem;color:var(--text-main)">${escHtml(d.customerName)}</td>
            <td>
                <span style="color:${color};border:1px solid ${color};padding:2px 6px;border-radius:4px;font-size:.7rem;font-weight:bold">
                    ${badge}
                </span>
            </td>
            <td style="font-size:.8rem;font-weight:bold;color:${color}">${fmtDT(d.checkIn)}</td>
            <td class="amt" style="font-weight:600;color:var(--text-main)">₦${formatNum(d.dailyRate)}</td>
            <td class="amt">
                <button class="btn-rectify ${isVerified ? 'done' : ''}" onclick="toggleEarlyVerified(${i})">
                    ${isVerified ? 'Un-mark' : 'Mark Verified'}
                </button>
            </td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function toggleEarlyVerified(idx) {
    const item = earlyCheckinData[idx];
    if (!item) return;
    item.record.isEarlyVerified = !item.record.isEarlyVerified;
    renderEarlyCheckinsTable();
    saveAuditState(false);
}

// ============ TOGGLE LOG VERIFIED (AUDIT CLEARANCE) ============
function toggleLogVerified(dsIdx, logIdx) {
    const d = doubleSalesData[dsIdx];
    if (!d || !d.checkInLogs || !d.checkInLogs[logIdx]) return;

    // Toggle verification
    d.checkInLogs[logIdx].isVerified = !d.checkInLogs[logIdx].isVerified;
    if (!d.checkInLogs[logIdx].isVerified) d.checkInLogs[logIdx].autoCleared = false; // drop autoClear flag if manually unmarked

    // Recalculate fraud value based on unverified logs only
    const unverifiedLogs = d.checkInLogs.filter((log) => log.dsFlagType && !log.isVerified);
    d.fraudValue = unverifiedLogs.reduce((sum, log) => sum + (log.trueLossValue || log.dailyRate || 0), 0);

    // Clear rectification if fraud value changed (user should re-rectify)
    if (!d.isRectified) {
        // no action needed
    }

    renderDoubleSalesTable();
    calculateRecon();
    saveAuditState(false);

    const status = d.checkInLogs[logIdx].isVerified ? 'exempted' : 'unmarked';
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
        tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:30px;color:var(--text-muted)">No overtime records found — all guests checked out on time!</td></tr>';
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
            <td style="font-weight:800;font-size:.82rem;color:var(--accent2)">${escHtml(d.roomName || 'N/A')}</td>
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
                ${d.capType === 'Half-Day' ? '<div style="font-size:0.55rem;color:var(--text-muted);font-weight:700">CAPPED: HALF-DAY</div>' : ''}
                ${d.capType === 'Full-Day' ? '<div style="font-size:0.55rem;color:var(--text-muted);font-weight:700">CAPPED: FULL-DAY</div>' : ''}
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
    const searchVal = document.getElementById('allSearchInput') ? document.getElementById('allSearchInput').value.toLowerCase().trim() : '';

    let filteredData = auditData;
    if (searchVal) {
        filteredData = auditData.filter(d => {
            const str = (d.roomName + ' ' + (d.customerName || '') + ' ' + d.roomType + ' ' + (d.isOvertime?'overtime':'on time') + ' ' + fmtDT(d.checkIn) + ' ' + fmtDT(d.expectedOut) + ' ' + fmtDT(d.actualOut)).toLowerCase();
            return str.includes(searchVal);
        });
    }

    label.textContent = filteredData.length + ' records';

    let html = '';
    filteredData.forEach((d, i) => {
        const statusColor = d.isOvertime ? '#ef5350' : '#00d4aa';
        const statusLabel = d.isOvertime ? 'OVERTIME' : 'ON TIME';
        const diffStr = d.isOvertime ? '+' + formatOvertimeDuration(d.diffHours)
            : d.diffHours < 0 ? formatOvertimeDuration(Math.abs(d.diffHours)) + ' early' : 'On time';

        const otChargeDisplay = d.isOvertime && d.overtimeCharge > 0 ? '₦' + formatNum(d.overtimeCharge) : '-';

        const otCapLabel = (d.isOvertime && (d.capType === 'Half-Day' || d.capType === 'Full-Day')) 
            ? `<div style="font-size:0.6rem;opacity:0.6">${d.capType}</div>` 
            : '';

        const originalIndex = auditData.indexOf(d);
        const exemptSelect = `
            <select class="input" style="padding:2px 5px; font-size:0.7rem; border-radius:4px" onchange="toggleRevenueExempt(${originalIndex}, this)">
                <option value="" ${!d.revenueExemptType ? 'selected' : ''}>Standard Billable</option>
                <option value="Complimentary" ${d.revenueExemptType === 'Complimentary' ? 'selected' : ''}>Complimentary</option>
                <option value="Voucher" ${d.revenueExemptType === 'Voucher' ? 'selected' : ''}>Voucher</option>
                <option value="Gift" ${d.revenueExemptType === 'Gift' ? 'selected' : ''}>Gift</option>
                <option value="Maintenance" ${d.revenueExemptType === 'Maintenance' ? 'selected' : ''}>Maintenance</option>
                <option value="Out Of Order" ${d.revenueExemptType === 'Out Of Order' ? 'selected' : ''}>Out Of Order</option>
                <option value="Canceled" ${d.revenueExemptType === 'Canceled' ? 'selected' : ''}>Canceled</option>
            </select>
        `;

        html += `<tr ${d.revenueExemptType ? 'style="opacity:0.65;background:var(--bg-card)" title="Excluded from Base Revenue due to '+d.revenueExemptType+'"' : ''}>
            <td class="row-num">${i + 1}</td>
            <td style="font-weight:800;font-size:.82rem;color:var(--accent2)">${escHtml(d.roomName || 'N/A')}</td>
            <td style="font-weight:600;font-size:.82rem">${escHtml(d.customerName || 'N/A')}</td>
            <td style="font-weight:600;font-size:.82rem">${escHtml(d.roomType)}</td>
            <td style="font-size:.78rem;color:var(--text-sub);white-space:nowrap">${fmtDT(d.checkIn)}</td>
            <td style="font-size:.78rem;color:var(--text-sub);white-space:nowrap">${fmtDT(d.expectedOut)}</td>
            <td style="font-size:.78rem;color:var(--text-sub);white-space:nowrap">${fmtDT(d.actualOut)}</td>
            <td style="font-size:.78rem;color:${statusColor};font-weight:600;white-space:nowrap">${diffStr}</td>
            <td style="font-weight:800;font-size:.78rem;color:${d.isOvertime ? '#ef5350' : 'var(--text-muted)'}">${d.revenueExemptType ? '<strike>'+otChargeDisplay+'</strike>' : otChargeDisplay}${otCapLabel}</td>
            <td><span style="padding:3px 10px;border-radius:5px;font-size:.68rem;font-weight:700;background:${statusColor}18;color:${statusColor}">${statusLabel}</span></td>
            <td>${exemptSelect}</td>
        </tr>`;
    });

    if (filteredData.length === 0) {
        html = '<tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text-muted)">No matching records found.</td></tr>';
    }

    tbody.innerHTML = html;
}

// ============ EXEMPTION TOGGLE ============
window.toggleRevenueExempt = function(idx, selectEl) {
    if (auditData[idx]) {
        auditData[idx].revenueExemptType = selectEl.value; // '', 'Complimentary', 'Voucher', 'Gift'
        renderAllTable();
        calculateRecon();
    }
};

// ============ EXPORT OVERTIME RECORDS PDF ============
function exportOvertimeRecordsPDF() {
    if (!overtimeData || overtimeData.length === 0) {
        alert('No overtime records to export.');
        return;
    }

    try {
        const sorted = [...overtimeData].sort((a, b) => b.diffHours - a.diffHours);

        const doc = new jspdf.jsPDF('l', 'mm', 'a4');
        const W = doc.internal.pageSize.getWidth();
        const H = doc.internal.pageSize.getHeight();
        const m = 15;
        const dateStr = new Date().toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

        let totalExportOT = 0;
        
        const rows = sorted.map((d, i) => {
            const chargeVal = d.isRectified ? d.rectifiedAmount : d.overtimeCharge;
            totalExportOT += chargeVal;
            
            const otHrs = d.diffHours;
            const severity = otHrs > 12 ? 'CRITICAL' : otHrs > 4 ? 'WARNING' : 'MINOR';
            
            let chargeDisplay = 'N' + formatNum(chargeVal);
            if(d.isRectified) chargeDisplay += ' (Rectified)';
            else if(d.capType && d.capType !== 'Fractional') chargeDisplay += ' (Cap: ' + d.capType + ')';

            return [
                String(i + 1),
                d.roomName || 'N/A',
                d.customerName || 'N/A',
                d.roomType,
                fmtDT(d.checkIn),
                fmtDT(d.expectedOut),
                fmtDT(d.actualOut),
                formatOvertimeDuration(otHrs),
                chargeDisplay,
                severity
            ];
        });

        doc.setFillColor(15, 15, 35);
        doc.rect(0, 0, W, 28, 'F');
        doc.setFillColor(239, 83, 80); // Red line indicator
        doc.rect(0, 28, W, 1.5, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(14);
        doc.setFont('helvetica', 'bold');
        doc.text('OVERTIME RECORDS EXPORT', m, 18);
        doc.setFontSize(8);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(180, 180, 210);
        doc.text('Total Records: ' + sorted.length + '  •  Total OT Value: N' + formatNum(totalExportOT) + '  •  ' + dateStr, m, 24);

        doc.autoTable({
            startY: 34,
            head: [['#', 'Room #', 'Guest Name', 'Room Type', 'Check-In', 'Expected Out', 'Actual Out', 'Duration Diff', 'OT Charge', 'Severity']],
            body: rows,
            foot: [['', '', '', '', '', '', '', 'TOTAL EXPORT OT', 'N' + formatNum(totalExportOT), '']],
            margin: { left: m, right: m },
            styles: { font: 'helvetica', fontSize: 7, cellPadding: 3, lineColor: [220, 220, 235], lineWidth: 0.2 },
            headStyles: { fillColor: [24, 24, 40], textColor: [255, 255, 255], fontStyle: 'bold' },
            footStyles: { fillColor: [255, 235, 238], textColor: [239, 83, 80], fontStyle: 'bold' },
            columnStyles: {
                0: { halign: 'center', cellWidth: 10 },
                1: { fontStyle: 'bold', textColor: [0, 212, 170] },
                2: { fontStyle: 'normal' },
                7: { fontStyle: 'bold', textColor: [239, 83, 80] },
                8: { halign: 'right', fontStyle: 'bold', textColor: [239, 83, 80] },
                9: { fontStyle: 'bold' }
            },
            didParseCell: function(data) {
                if(data.section === 'body' && data.column.index === 9) {
                    if (data.row.raw[9] === 'CRITICAL') data.cell.styles.textColor = [239, 83, 80];
                    else if (data.row.raw[9] === 'WARNING') data.cell.styles.textColor = [255, 167, 38];
                    else data.cell.styles.textColor = [102, 187, 106];
                }
            }
        });

        const pages = doc.internal.getNumberOfPages();
        for (let p = 1; p <= pages; p++) {
            doc.setPage(p);
            doc.setFontSize(6);
            doc.setTextColor(150);
            doc.text(`Page ${p} of ${pages} — Generated securely by MiAuditOps`, m, H - 10);
        }

        doc.save(`Overtime_Records_${new Date().getTime()}.pdf`);
    } catch(err) {
        alert('PDF Export Failed: ' + err.message);
        console.error(err);
    }
}

// ============ EXPORT ALL RECORDS PDF ============
function exportAllRecordsPDF() {
    if (!auditData || auditData.length === 0) {
        alert('No data to export.');
        return;
    }
    
    const searchVal = document.getElementById('allSearchInput').value.toLowerCase().trim();
    let filteredData = auditData;
    if (searchVal) {
        filteredData = auditData.filter(d => {
            const str = (d.roomName + ' ' + d.customerName + ' ' + d.roomType + ' ' + (d.isOvertime?'overtime':'on time') + ' ' + fmtDT(d.checkIn) + ' ' + fmtDT(d.expectedOut) + ' ' + fmtDT(d.actualOut)).toLowerCase();
            return str.includes(searchVal);
        });
    }

    try {
        const doc = new jspdf.jsPDF('l', 'mm', 'a4');
        const W = doc.internal.pageSize.getWidth();
        const H = doc.internal.pageSize.getHeight();
        const m = 15;
        const dateStr = new Date().toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

        let totalExportOT = 0;
        
        const rows = filteredData.map((d, i) => {
            const diffStr = d.isOvertime ? '+' + formatOvertimeDuration(d.diffHours) : d.diffHours < 0 ? formatOvertimeDuration(Math.abs(d.diffHours)) + ' early' : 'On time';
            const chargeVal = d.isOvertime ? (d.isRectified ? d.rectifiedAmount : d.overtimeCharge) : 0;
            totalExportOT += chargeVal;
            
            const otChargeDisplay = chargeVal > 0 ? 'N' + formatNum(chargeVal) : '-';
            const statusLabel = d.isOvertime ? 'OVERTIME' : 'ON TIME';

            return [
                String(i + 1),
                d.roomName || 'N/A',
                d.customerName || 'N/A',
                d.roomType,
                fmtDT(d.checkIn),
                fmtDT(d.expectedOut),
                fmtDT(d.actualOut),
                diffStr,
                otChargeDisplay,
                statusLabel
            ];
        });

        doc.setFillColor(15, 15, 35);
        doc.rect(0, 0, W, 28, 'F');
        doc.setFillColor(108, 99, 255);
        doc.rect(0, 28, W, 1.5, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(14);
        doc.setFont('helvetica', 'bold');
        doc.text('ALL RECORDS EXPORT', m, 18);
        doc.setFontSize(8);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(180, 180, 210);
        doc.text('Total Records: ' + filteredData.length + '  •  Total OT Value: N' + formatNum(totalExportOT) + '  •  ' + dateStr, m, 24);

        if (searchVal) {
            doc.setTextColor(50, 50, 70);
            doc.setFont('helvetica', 'italic');
            doc.text('Filtered by: "' + searchVal + '"', m, 36);
        }

        doc.autoTable({
            startY: searchVal ? 40 : 34,
            head: [['#', 'Room #', 'Guest Name', 'Room Type', 'Check-In', 'Expected Out', 'Actual Out', 'Duration Diff', 'OT Charge', 'Status']],
            body: rows,
            foot: [['', '', '', '', '', '', '', 'TOTAL EXPORT', 'N' + formatNum(totalExportOT), '']],
            margin: { left: m, right: m },
            styles: { font: 'helvetica', fontSize: 7, cellPadding: 3, lineColor: [220, 220, 235], lineWidth: 0.2 },
            headStyles: { fillColor: [24, 24, 40], textColor: [255, 255, 255], fontStyle: 'bold' },
            footStyles: { fillColor: [248, 248, 252], textColor: [239, 83, 80], fontStyle: 'bold' },
            columnStyles: {
                0: { halign: 'center', cellWidth: 10 },
                1: { fontStyle: 'bold', textColor: [0, 212, 170] },
                2: { fontStyle: 'normal' },
                7: { fontStyle: 'bold' },
                8: { halign: 'right', fontStyle: 'bold', textColor: [239, 83, 80] },
                9: { fontStyle: 'bold' }
            },
            didParseCell: function(data) {
                if(data.section === 'body') {
                    if (data.column.index === 7 || data.column.index === 9) {
                        const isOvertime = data.row.raw[9] === 'OVERTIME';
                        if (isOvertime) data.cell.styles.textColor = [239, 83, 80];
                        else data.cell.styles.textColor = [102, 187, 106];
                    }
                }
            }
        });

        const pages = doc.internal.getNumberOfPages();
        for (let p = 1; p <= pages; p++) {
            doc.setPage(p);
            doc.setFontSize(7);
            doc.setTextColor(140, 140, 165);
            doc.text('Page ' + p + ' of ' + pages, W - m, H - 6, { align: 'right' });
        }

        doc.save('All_Records_' + Date.now() + '.pdf');
    } catch (err) {
        alert("PDF Generation Error: " + err.message);
        console.error(err);
    }
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
            let r = 15, g = 15, b = 35;
            if (h && h.length === 7) {
                r = parseInt(h[1] + h[2], 16);
                g = parseInt(h[3] + h[4], 16);
                b = parseInt(h[5] + h[6], 16);
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
        const m1 = m;
        const m2 = m + 65;
        const m3 = m + 130;

        doc.setFontSize(7);
        doc.setFont('helvetica', 'bold'); doc.text('SYSTEM EXPECTED', m1, y);
        doc.setFont('helvetica', 'normal');
        doc.text(`Base Room Sales:`, m1, y + 5); doc.text(`N${formatNum(sysBase)}`, m1 + 55, y + 5, { align: 'right' });
        doc.text(`Overtime Charges:`, m1, y + 10); doc.text(`N${formatNum(sysOT)}`, m1 + 55, y + 10, { align: 'right' });
        doc.text(`Double Sales:`, m1, y + 15); doc.text(`N${formatNum(sysFraud)}`, m1 + 55, y + 15, { align: 'right' });
        doc.setFont('helvetica', 'bold');
        doc.text(`TOTAL SYSTEM BASE:`, m1, y + 23); doc.text(`N${formatNum(sysTotal)}`, m1 + 55, y + 23, { align: 'right' });

        // Tenders
        doc.text('DECLARED TENDERS', m2, y);
        doc.setFont('helvetica', 'normal');
        doc.text(`Cash:`, m2, y + 5); doc.text(`N${formatNum(cash)}`, m2 + 55, y + 5, { align: 'right' });
        doc.text(`POS:`, m2, y + 10); doc.text(`N${formatNum(pos)}`, m2 + 55, y + 10, { align: 'right' });
        doc.text(`Transfer:`, m2, y + 15); doc.text(`N${formatNum(transfer)}`, m2 + 55, y + 15, { align: 'right' });
        doc.setFont('helvetica', 'bold');
        doc.text(`TOTAL DECLARED:`, m2, y + 23); doc.text(`N${formatNum(declaredTotal)}`, m2 + 55, y + 23, { align: 'right' });

        // Mgt Sales
        let mgtTotal = 0;
        mgtSalesData.forEach(m => mgtTotal += m.amount);
        doc.text('MANAGEMENT SALES (PMS)', m3, y);
        doc.setFont('helvetica', 'normal');
        let my = y + 5;
        if (mgtSalesData.length === 0) {
            doc.text('None added', m3, my); my += 5;
        } else {
            mgtSalesData.forEach(mEntry => {
                if (my < y + 18) {
                    doc.text(`${mEntry.label.substring(0, 15)}:`, m3, my); doc.text(`N${formatNum(mEntry.amount)}`, m3 + 55, my, { align: 'right' }); my += 5;
                }
            });
        }
        doc.setFont('helvetica', 'bold');
        doc.text(`TOTAL MANAGEMENT:`, m3, y + 23); doc.text(`N${formatNum(mgtTotal)}`, m3 + 55, y + 23, { align: 'right' });

        y += 33;
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
        // Variance display (Two Hits)
        const hit1 = declaredTotal - mgtTotal;
        const hit2 = mgtTotal - netExpected;

        const h1StatusText = hit1 === 0 ? 'BALANCED' : hit1 < 0 ? 'SHORTAGE' : 'SURPLUS';
        const h1Col = hit1 === 0 ? [100, 100, 120] : hit1 < 0 ? [239, 83, 80] : [41, 182, 246];

        const h2StatusText = hit2 === 0 ? 'BALANCED' : hit2 < 0 ? 'SHORTAGE' : 'SURPLUS';
        const h2Col = hit2 === 0 ? [100, 100, 120] : hit2 < 0 ? [239, 83, 80] : [41, 182, 246];

        const valStatusText = variance === 0 ? 'BALANCED' : variance < 0 ? 'SHORTAGE' : 'SURPLUS';
        const vCol = variance === 0 ? [102, 187, 106] : variance < 0 ? [239, 83, 80] : [41, 182, 246];

        // Hit 1 Box
        doc.setFillColor(248, 248, 252); doc.rect(m, y, (W - m * 2)/2 - 2, 18, 'F');
        doc.setFontSize(8); doc.setTextColor(80, 80, 100); doc.text('1ST HIT : CASHIER DISCREPANCY', m + 5, y + 7);
        doc.setFontSize(10); doc.setTextColor(...h1Col); 
        doc.text(`${h1StatusText}   ${hit1 < 0 ? '-' : ''}N${formatNum(Math.abs(hit1))}`, m + 5, y + 14);

        // Hit 2 Box
        const midX = m + (W - m * 2)/2 + 2;
        doc.setFillColor(248, 248, 252); doc.rect(midX, y, (W - m * 2)/2 - 2, 18, 'F');
        doc.setFontSize(8); doc.setTextColor(80, 80, 100); doc.text('2ND HIT : SYSTEM DISCREPANCY', midX + 5, y + 7);
        doc.setFontSize(10); doc.setTextColor(...h2Col); 
        doc.text(`${h2StatusText}   ${hit2 < 0 ? '-' : ''}N${formatNum(Math.abs(hit2))}`, midX + 5, y + 14);

        y += 22;

        // Final Master Variance
        doc.setFillColor(240, 240, 248); doc.rect(m, y, W - m * 2, 14, 'F');
        doc.setFontSize(10); doc.setTextColor(80, 80, 100); doc.text('FINAL NET AUDIT VARIANCE:', m + 5, y + 9);
        doc.setFontSize(12); doc.setTextColor(...vCol);
        doc.text(`${valStatusText}   ${variance < 0 ? '-' : ''}N${formatNum(Math.abs(variance))}`, W - m - 5, y + 9, { align: 'right' });

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

                // Build check-in logs text block — exactly mirroring dashboard strings
                const logsText = logs.map((log, j) => {
                    const isVerified = !!log.isVerified;
                    let tag = '';
                    if (isVerified) {
                        tag = log.autoCleared ? ' [AUTO-CLEARED]' : ' [EXEMPTED]';
                    } else if (log.dsFlagType) {
                        tag = ` [${log.dsFlagType}]`;
                    }
                    return `${j + 1}. Check-in: ${fmtDT(log.checkIn)} — Out: ${fmtDT(log.actualOut)}\n     Rate: N${formatNum(log.dailyRate || 0)} / expected out: ${fmtDT(log.expectedOut)}${tag}`;
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
                        2: { cellWidth: 20, halign: 'center', fontStyle: 'bold', textColor: [0, 0, 0] },
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

                        // Overhaul column 4 text drawing to perfectly mirror dashboard colors and alignments
                        if (data.column.index === 4 && meta.logs) {
                            const cellX = data.cell.x;
                            const cellY = data.cell.y;
                            const cellW = data.cell.width;
                            const cellH = data.cell.height;
                            const padding = data.cell.styles.cellPadding || 3;
                            
                            // 1. Wipe out autoTable's native black text using background fill
                            const bColor = data.cell.styles.fillColor || [255, 255, 255];
                            doc.setFillColor(...(Array.isArray(bColor) ? bColor : [bColor]));
                            doc.rect(cellX + 0.1, cellY + 0.1, cellW - 0.2, cellH - 0.2, 'F');
                            
                            // 2. Draw our own precision colored string exactly like the Dashboard!
                            const totalLogs = meta.logs.length;
                            const logBlockH = (cellH - padding * 2) / Math.max(totalLogs, 1);
                            doc.setFontSize(data.cell.styles.fontSize);

                            meta.logs.forEach((log, j) => {
                                const isVerified = !!log.isVerified;
                                const isFraud = !!log.dsFlagType;
                                const blockStartY = cellY + padding + (j * logBlockH);
                                
                                const str1 = `${j + 1}. Check-in: ${fmtDT(log.checkIn)} — Out: ${fmtDT(log.actualOut)}`;
                                let tag = '';
                                if (isVerified) tag = log.autoCleared ? ' [AUTO-CLEARED]' : ' [EXEMPTED]';
                                else if (isFraud) tag = ` [${log.dsFlagType}]`;
                                
                                const str2 = `     Rate: N${formatNum(log.dailyRate || 0)} / expected out: ${fmtDT(log.expectedOut)}${tag}`;
                                
                                if (isVerified) {
                                    doc.setTextColor(150, 200, 155); // Muted green like dashboard string
                                    doc.setDrawColor(150, 200, 155);
                                } else if (isFraud) {
                                    if (log.dsFlagType.includes('CRITICAL')) doc.setTextColor(239, 83, 80);
                                    else doc.setTextColor(255, 167, 38);
                                } else {
                                    doc.setTextColor(50, 50, 70); // default text
                                }
                                
                                // Precise jsPDF text baselines (approximate vertical centers)
                                const line1Y = blockStartY + (logBlockH * 0.4);
                                const line2Y = blockStartY + (logBlockH * 0.85);
                                
                                doc.text(str1, cellX + padding, line1Y);
                                doc.text(str2, cellX + padding, line2Y);
                                
                                if (isVerified) {
                                    doc.setLineWidth(0.3);
                                    
                                    const w1 = doc.getTextWidth(str1);
                                    // Ignore the 5-space indentation prefix for drawing the second strikethrough!
                                    const indentX = doc.getTextWidth("     ");
                                    const w2 = doc.getTextWidth(str2) - indentX;
                                    
                                    const sOffset = data.cell.styles.fontSize * 0.3527 * 0.28;
                                    
                                    doc.line(cellX + padding, line1Y - sOffset, cellX + padding + w1, line1Y - sOffset);
                                    doc.line(cellX + padding + indentX, line2Y - sOffset, cellX + padding + indentX + w2, line2Y - sOffset);
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
            let totalExportOT = 0;
            
            const otRows = sorted.map((d, i) => {
                const chargeVal = d.isRectified ? d.rectifiedAmount : d.overtimeCharge;
                totalExportOT += chargeVal;
                
                const otHrs = d.diffHours;
                const severity = otHrs > 12 ? 'CRITICAL' : otHrs > 4 ? 'WARNING' : 'MINOR';
                
                let chargeDisplay = 'N' + formatNum(chargeVal);
                if(d.isRectified) chargeDisplay += ' (Rectified)';
                else if(d.capType && d.capType !== 'Fractional') chargeDisplay += ' (Cap: ' + d.capType + ')';

                return [
                    String(i + 1),
                    d.roomName || 'N/A',
                    d.customerName || 'N/A',
                    d.roomType,
                    fmtDT(d.checkIn),
                    fmtDT(d.expectedOut),
                    fmtDT(d.actualOut),
                    formatOvertimeDuration(otHrs),
                    chargeDisplay,
                    severity
                ];
            });

            doc.autoTable({
                startY: 40,
                head: [['#', 'Room #', 'Guest Name', 'Room Type', 'Check-In', 'Expected Out', 'Actual Out', 'Duration Diff', 'OT Charge', 'Severity']],
                body: otRows,
                foot: [['', '', '', '', '', '', '', 'TOTAL OT VALUE', 'N' + formatNum(totalExportOT), '']],
                margin: { left: m, right: m },
                styles: { font: 'helvetica', fontSize: 7, cellPadding: 3, lineColor: [220, 220, 235], lineWidth: 0.2 },
                headStyles: { fillColor: [24, 24, 40], textColor: [255, 255, 255], fontStyle: 'bold' },
                footStyles: { fillColor: [255, 235, 238], textColor: [239, 83, 80], fontStyle: 'bold' },
                columnStyles: {
                    0: { halign: 'center', cellWidth: 10 },
                    1: { fontStyle: 'bold', textColor: [0, 212, 170] },
                    2: { fontStyle: 'normal' },
                    7: { fontStyle: 'bold', textColor: [239, 83, 80] },
                    8: { halign: 'right', fontStyle: 'bold', textColor: [239, 83, 80] },
                    9: { fontStyle: 'bold' }
                },
                didParseCell: function(data) {
                    if(data.section === 'body' && data.column.index === 9) {
                        if (data.row.raw[9] === 'CRITICAL') data.cell.styles.textColor = [239, 83, 80];
                        else if (data.row.raw[9] === 'WARNING') data.cell.styles.textColor = [255, 167, 38];
                        else data.cell.styles.textColor = [102, 187, 106];
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

        // ==========================================
        // COMPLIMENTARY & EXEMPTIONS
        // ==========================================
        const exemptData = auditData.filter(d => d.revenueExemptType);
        if (exemptData.length > 0) {
            y += 10;
            if (y > H - 40) { doc.addPage(); y = m; }
            doc.setFillColor(24, 24, 40);
            doc.rect(m, y, W - m * 2, 9, 'F');
            doc.setTextColor(255);
            doc.setFontSize(8);
            doc.setFont('helvetica', 'bold');
            doc.text("COMPLIMENTARY, VOUCHER & GIFT LOGS", m + 5, y + 6.5);
            
            doc.autoTable({
                startY: y + 12,
                head: [['#', 'Room #', 'Guest', 'Date', 'Exemption Type', 'Waived Value']],
                body: exemptData.map((d, i) => [
                    String(i + 1),
                    d.roomName || 'N/A',
                    d.customerName || 'N/A',
                    fmtDT(d.checkIn),
                    d.revenueExemptType,
                    'N' + formatNum(d.dailyRate)
                ]),
                margin: { left: m, right: m },
                styles: { font: 'helvetica', fontSize: 7, cellPadding: 3 },
                headStyles: { fillColor: [240, 240, 245], textColor: [50, 50, 70] },
                columnStyles: { 5: { textColor: [239, 83, 80], fontStyle: 'bold' } }
            });
            y = doc.lastAutoTable.finalY + 10;
        }

        // ==========================================
        // PAGE: EARLY CHECK-INS ANOMALY
        // ==========================================
        if (typeof earlyCheckinData !== 'undefined' && earlyCheckinData.length > 0) {
            doc.addPage('a4', 'l');
            W = doc.internal.pageSize.getWidth();
            H = doc.internal.pageSize.getHeight();
            renderHeader('SUSPICIOUS EARLY CHECK-INS (12 AM - 7 AM)');
            
            const ecuRows = earlyCheckinData.map((item, i) => {
                const d = item.record;
                let isVerified = '';
                if (d.autoClearedEarly) isVerified = '[AUTO-CLEARED]\n';
                else if (d.isEarlyVerified) isVerified = '[EXEMPTED]\n';
                
                let badge = 'CRITICAL (12AM - 6AM)';
                if (d.dailyRate === 0) badge += '\n[UNPRICED FRAUD]';
                if (item.stealthOut) badge += '\n[STEALTH CHECKOUT]';
                
                return [
                    String(i + 1),
                    d.checkIn.toDateString(),
                    d.roomName || 'N/A',
                    d.customerName || 'N/A',
                    isVerified + badge,
                    fmtDT(d.checkIn),
                    'N' + formatNum(d.dailyRate)
                ];
            });

            doc.autoTable({
                startY: 40,
                head: [['#', 'Date', 'Room #', 'Guest Name', 'Risk Level', 'Time Checked In', 'Nightly Rate']],
                body: ecuRows,
                margin: { left: m, right: m },
                styles: { font: 'helvetica', fontSize: 8, cellPadding: 3, lineColor: [220, 220, 235], lineWidth: 0.2 },
                headStyles: { fillColor: [24, 24, 40], textColor: [255, 255, 255], fontStyle: 'bold' },
                columnStyles: {
                    0: { cellWidth: 15, halign: 'center' },
                    2: { fontStyle: 'bold', textColor: [0, 212, 170] },
                    4: { fontStyle: 'bold' },
                    5: { fontStyle: 'bold', textColor: [239, 83, 80] },
                    6: { halign: 'right', fontStyle: 'bold' }
                },
                didParseCell: function(data) {
                    if (data.section === 'body') {
                        const cellStr = data.row.raw[4] || '';
                        const isVerified = cellStr.includes('[EXEMPTED]') || cellStr.includes('[AUTO-CLEARED]');
                        if (isVerified) {
                            if (data.column.index === 4) {
                                data.cell.styles.textColor = [0, 100, 0]; // Dark Green for Auto-cleared
                            } else {
                                data.cell.styles.textColor = [0, 0, 0]; // Black for the rest of the row
                            }
                        } else if (data.column.index === 4) {
                            if (cellStr.includes('CRITICAL')) data.cell.styles.textColor = [239, 83, 80];
                        }
                    }
                }
            });
            y = doc.lastAutoTable.finalY + 10;
        }

        // ==========================================
        // AUDITOR'S WRITTEN REPORT
        // ==========================================
        const writtenReportStr = document.getElementById('pdfWrittenReport') ? document.getElementById('pdfWrittenReport').value.trim() : '';
        if (writtenReportStr) {
            y += 10;
            doc.setFillColor(24, 24, 40);
            doc.rect(m, y, W - m * 2, 9, 'F');
            doc.setTextColor(255);
            doc.setFontSize(8);
            doc.setFont('helvetica', 'bold');
            doc.text("AUDITOR'S WRITTEN REPORT", m + 5, y + 6.5);
            
            y += 16;
            doc.setTextColor(40, 40, 60);
            doc.setFontSize(9);
            doc.setFont('helvetica', 'normal');
            
            const splitReport = doc.splitTextToSize(writtenReportStr, W - m * 2);
            doc.text(splitReport, m, y);
        }

        // ==========================================
        // PAGE 9: ALL RECORDS EXPORT
        // ==========================================
        const includeAll = document.getElementById('pdfIncludeAll') ? document.getElementById('pdfIncludeAll').checked : true;
        
        if (includeAll) {
            doc.addPage('a4', 'l');
            W = doc.internal.pageSize.getWidth();
            H = doc.internal.pageSize.getHeight();
            renderHeader('ALL RECORDS (MASTER LEDGER)');

        if (auditData && auditData.length > 0) {
            const filteredData = [...auditData];
            let totalExportAll = 0;
            
            const rowsAll = filteredData.map((d, i) => {
                let chargeVal = d.overtimeCharge || 0;
                if(d.isRectified) chargeVal = d.rectifiedAmount || 0;
                
                totalExportAll += chargeVal;
                
                const stayHrs = d.stayNights * 24;
                const otHrs = d.diffHours;
                
                let otChargeDisplay = 'N' + formatNum(chargeVal);
                if (d.isOvertime || d.isRectified) {
                    if(d.isRectified) otChargeDisplay += ' (Rectified)';
                    else if(d.capType && d.capType !== 'Fractional') otChargeDisplay += ' (Cap: ' + d.capType + ')';
                } else {
                    otChargeDisplay = '—';
                }

                let statusLabel = 'CLEARED';
                if(d.isDoubleSold) statusLabel = 'DOUBLE SOLD';
                else if(d.isOvertime) statusLabel = 'OVERTIME';

                return [
                    String(i + 1),
                    d.roomName || 'N/A',
                    d.customerName || 'N/A',
                    d.roomType,
                    fmtDT(d.checkIn),
                    fmtDT(d.expectedOut),
                    fmtDT(d.actualOut),
                    stayHrs + 'h  +(' + (otHrs>0 ? formatOvertimeDuration(otHrs) : '0h') + ')',
                    otChargeDisplay,
                    statusLabel
                ];
            });

            doc.autoTable({
                startY: 40,
                head: [['#', 'Room #', 'Guest Name', 'Room Type', 'Check-In', 'Expected Out', 'Actual Out', 'Total Stay & OT', 'OT Charge', 'Status']],
                body: rowsAll,
                foot: [['', '', '', '', '', '', '', 'TOTAL EXPORT OT', 'N' + formatNum(totalExportAll), '']],
                margin: { left: m, right: m },
                styles: { font: 'helvetica', fontSize: 7, cellPadding: 3, lineColor: [220, 220, 235], lineWidth: 0.2 },
                headStyles: { fillColor: [24, 24, 40], textColor: [255, 255, 255], fontStyle: 'bold' },
                footStyles: { fillColor: [240, 240, 250], textColor: [108, 99, 255], fontStyle: 'bold' },
                columnStyles: {
                    0: { halign: 'center', cellWidth: 8 },
                    1: { fontStyle: 'bold', textColor: [0, 212, 170] },
                    2: { cellWidth: 25 },
                    7: { fontStyle: 'bold' },
                    8: { halign: 'right', fontStyle: 'bold' },
                    9: { fontStyle: 'bold' }
                },
                didParseCell: function(data) {
                    if(data.section === 'body' && data.column.index === 9) {
                        if (data.row.raw[9] === 'DOUBLE SOLD') data.cell.styles.textColor = [239, 83, 80];
                        else if (data.row.raw[9] === 'OVERTIME') data.cell.styles.textColor = [255, 167, 38];
                        else data.cell.styles.textColor = [102, 187, 106];
                    }
                    if(data.section === 'body' && data.column.index === 8) {
                        if(data.row.raw[8] !== '—') data.cell.styles.textColor = [239, 83, 80];
                    }
                }
            });
        }
        
        } // close includeAll block

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

// ============ 3-TIER UPLOAD LOGIC ============
function processTemplateUpload(rows, headers) {
    let checkinCol = headers.indexOf('check-in date/time');
    let expOutCol = headers.indexOf('expected check-out');
    let actOutCol = headers.indexOf('actual check-out');
    let rateCol = headers.indexOf('daily rate');
    let roomCol = headers.indexOf('room no');
    let typeCol = headers.indexOf('room type');
    let nameCol = headers.findIndex(h => h.includes('name') || h.includes('guest') || h.includes('customer'));

    auditData = [];
    for (let r = 1; r < rows.length; r++) {
        const row = rows[r];
        if(!row || typeof row[checkinCol] === 'undefined') continue;

        const checkinRaw = String(row[checkinCol] || '').trim();
        const expOutRaw = String(row[expOutCol] || '').trim();
        const actualRaw = String(row[actOutCol] || '').trim();
        
        if (!checkinRaw || !expOutRaw || !actualRaw) continue;

        const checkIn = parseDatetime(checkinRaw);
        const expectedOut = parseDatetime(expOutRaw);
        const actualOut = parseDatetime(actualRaw);

        if (!checkIn || !expectedOut || !actualOut) continue;

        const roomType = String(row[typeCol] || 'Unknown').trim();
        const roomNameRaw = String(row[roomCol] || '').trim();
        const customerName = nameCol !== -1 ? String(row[nameCol] || '').trim() : 'N/A';
        const amount = parseFloat(String(row[rateCol]||'').replace(/,/g, '')) || 0;

        const stayMs = expectedOut.getTime() - checkIn.getTime();
        const stayNights = Math.max(1, Math.round(stayMs / (1000 * 60 * 60 * 24)));
        const dailyRate = amount > 0 ? amount : 0;

        const diffMs = actualOut.getTime() - expectedOut.getTime();
        let diffHours = diffMs / (1000 * 60 * 60);
        let isOvertime = diffHours > 0;

        if (isOvertime && (diffMs / 60000) <= policyGraceMins) {
            isOvertime = false;
        }

        let overtimeCharge = 0;
        let capType = 'None';
        if (isOvertime && dailyRate > 0) {
            if (diffHours <= policyHalfHours) {
                capType = 'Fractional';
                overtimeCharge = (dailyRate / 24) * diffHours;
            } else if (diffHours > policyHalfHours && diffHours <= policyFullHours) {
                capType = 'Half-Day';
                overtimeCharge = dailyRate * 0.50;
            } else {
                capType = 'Full-Day';
                overtimeCharge = dailyRate;
            }
        }

        auditData.push({
            checkIn, expectedOut, actualOut,
            roomName: roomNameRaw, customerName, roomType, amount,
            stayNights, dailyRate, diffHours, isOvertime, overtimeCharge, capType,
            checkinRaw, actualRaw
        });
    }
    finalizeAudit();
}

function openMappingModal(headers) {
    const container = document.getElementById('mappingFieldsContainer');
    if(!container) return;
    
    let optionsHtml = '<option value="">-- Skip / Auto-detect --</option>';
    headers.forEach((h, i) => {
        if(h) optionsHtml += `<option value="${i}">${h}</option>`;
    });

    const fields = [
        { id: 'mapRoom', label: 'Room Number (Required)' },
        { id: 'mapName', label: 'Guest / Customer Name' },
        { id: 'mapType', label: 'Room Type' },
        { id: 'mapRate', label: 'Daily Rate' },
        { id: 'mapCheckin', label: 'Check-In DateTime' },
        { id: 'mapExpected', label: 'Expected Checkout' },
        { id: 'mapActual', label: 'Actual Checkout' }
    ];

    container.innerHTML = fields.map(f => `
        <div style="display:flex;flex-direction:column;gap:5px">
            <label style="font-size:0.8rem;font-weight:600;color:var(--text-main)">${f.label}</label>
            <select id="${f.id}" style="padding:8px;border-radius:6px;border:1px solid var(--border);background:rgba(0,0,0,0.1);color:var(--text)">
                ${optionsHtml}
            </select>
        </div>
    `).join('');

    document.getElementById('mappingModal').classList.add('show');
}

function closeMappingModal() {
    document.getElementById('mappingModal').classList.remove('show');
}

function applyCustomMapping() {
    const colRoom = document.getElementById('mapRoom').value;
    const colName = document.getElementById('mapName').value;
    const colType = document.getElementById('mapType').value;
    const colRate = document.getElementById('mapRate').value;
    const colCheckin = document.getElementById('mapCheckin').value;
    const colExpected = document.getElementById('mapExpected').value;
    const colActual = document.getElementById('mapActual').value;

    if (!colRoom || !colCheckin || !colActual) {
        alert('Room Number, Check-In DateTime, and Actual Checkout are mandatory mappings.');
        return;
    }

    closeMappingModal();
    spinner.classList.add('show');

    setTimeout(() => {
        try {
            auditData = [];
            const rows = currentlyParsedRows;
            
            for (let r = 1; r < rows.length; r++) {
                const row = rows[r];
                if (!row) continue;

                let checkIn = parseDatetime(row[colCheckin] || '');
                let actualOut = parseDatetime(row[colActual] || '');
                let expectedOut = colExpected !== '' ? parseDatetime(row[colExpected] || '') : null;
                
                // Fallback: If Expected Checkout is blank, try parsing Checkin as range 
                if(!expectedOut) {
                   const dates = parseCheckinPeriod(String(row[colCheckin]||''));
                   if(dates) {
                       checkIn = dates.checkIn;
                       expectedOut = dates.expectedOut;
                   }
                }

                if (!checkIn || !actualOut || !expectedOut) continue;

                const roomNameRaw = String(row[colRoom] || '').trim();
                const customerName = colName !== '' ? String(row[colName] || '').trim() : 'N/A';
                const actualRaw = String(row[colActual] || '').trim();
                const checkinRaw = String(row[colCheckin] || '').trim();

                let roomType = 'Unknown';
                let amount = 0;

                // If type is mapped, we can extract rate from it maybe
                if (colType !== '') {
                    const parsed = extractAmount(String(row[colType] || ''));
                    roomType = parsed.roomType || 'Unknown';
                    // if rate isn't mapped, fallback to type embedded amount
                    if (colRate === '') amount = parsed.amount || 0;
                }

                if (colRate !== '') {
                    amount = parseFloat(String(row[colRate]||'').replace(/,/g, '')) || 0;
                }

                const stayMs = expectedOut.getTime() - checkIn.getTime();
                const stayNights = Math.max(1, Math.round(stayMs / (1000 * 60 * 60 * 24)));
                const dailyRate = amount > 0 ? amount : 0;

                const diffMs = actualOut.getTime() - expectedOut.getTime();
                let diffHours = diffMs / (1000 * 60 * 60);
                let isOvertime = diffHours > 0;

                if (isOvertime && (diffMs / 60000) <= policyGraceMins) {
                    isOvertime = false;
                }

                let overtimeCharge = 0;
                let capType = 'None';
                if (isOvertime && dailyRate > 0) {
                    if (diffHours <= policyHalfHours) {
                        capType = 'Fractional';
                        overtimeCharge = (dailyRate / 24) * diffHours;
                    } else if (diffHours > policyHalfHours && diffHours <= policyFullHours) {
                        capType = 'Half-Day';
                        overtimeCharge = dailyRate * 0.50;
                    } else {
                        capType = 'Full-Day';
                        overtimeCharge = dailyRate;
                    }
                }

                auditData.push({
                    checkIn, expectedOut, actualOut,
                    roomName: roomNameRaw, customerName, roomType, amount,
                    stayNights, dailyRate, diffHours, isOvertime, overtimeCharge, capType,
                    checkinRaw, actualRaw
                });
            }
            
            finalizeAudit();
        } catch (err) {
            alert('Mapping error: ' + err.message);
        } finally {
            spinner.classList.remove('show');
        }
    }, 100);
}

function downloadStandardTemplate() {
    const ws_data = [
        ["Room No", "Guest Name", "Room Type", "Daily Rate", "Check-In Date/Time", "Expected Check-Out", "Actual Check-Out"],
        ["101", "John Doe", "Executive Deluxe", "150000", "2026-04-01 10:00:00", "2026-04-02 12:00:00", "2026-04-02 15:30:00"],
        ["102", "Jane Smith", "Standard Room", "50000", "2026-04-01 14:00:00", "2026-04-03 12:00:00", "2026-04-03 12:15:00"]
    ];
    const ws = XLSX.utils.aoa_to_sheet(ws_data);
    
    // Set some column widths
    ws['!cols'] = [
        {wch: 10}, 
        {wch: 20}, 
        {wch: 12}, 
        {wch: 22}, 
        {wch: 22}, 
        {wch: 22}
    ];

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Standard Template");
    XLSX.writeFile(wb, "Miauditops_Standard_Template.xlsx");
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
        mgtSalesData,
        earlyCheckinData,
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
        if(inv.earlyCheckinData) inv.earlyCheckinData.forEach(hydrateDates);
        inv.doubleSalesData.forEach(d => {
            if (d.checkInLogs) d.checkInLogs.forEach(hydrateDates);
        });

        auditData = inv.auditData;
        overtimeData = inv.overtimeData;
        doubleSalesData = inv.doubleSalesData;
        reconAdjustments = inv.reconAdjustments || [];
        mgtSalesData = inv.mgtSalesData || [];
        earlyCheckinData = inv.earlyCheckinData || [];
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
        renderEarlyCheckinsTable();
        renderOvertimeTable();
        renderAllTable();
        renderAdjustments();
        renderMgtSales();
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

// ============ POLICY SETTINGS ============
function openPolicyModal() {
    let modal = document.getElementById('policyModal');
    if (!modal) return;
    
    document.getElementById('polGrace').value = policyGraceMins;
    document.getElementById('polHalf').value = policyHalfHours;
    document.getElementById('polFull').value = policyFullHours;
    
    modal.classList.add('show');
}

function closePolicyModal() {
    const modal = document.getElementById('policyModal');
    if(modal) modal.classList.remove('show');
}

function applyPolicySettings() {
    const g = parseInt(document.getElementById('polGrace').value) || 0;
    const h = parseFloat(document.getElementById('polHalf').value) || 3.0;
    const f = parseFloat(document.getElementById('polFull').value) || 8.0;
    
    localStorage.setItem('policyGraceMins', g);
    localStorage.setItem('policyHalfHours', h);
    localStorage.setItem('policyFullHours', f);
    
    policyGraceMins = g;
    policyHalfHours = h;
    policyFullHours = f;
    
    closePolicyModal();
    if(typeof showToast === 'function') showToast('Hotel Policy Settings updated!');
    alert('Rules saved! Please re-upload your file to apply these custom rules to the audit.');
}
