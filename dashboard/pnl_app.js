function pnlApp() {
    return {
        clientName: document.querySelector('[x-data]').dataset.client || 'Client',
        monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        currentTab: 'reports',
        tabs: [
            { id: 'reports', label: 'Reports', icon: 'folder' },
            { id: 'periods', label: 'Periods', icon: 'calendar' },
            { id: 'revenue', label: 'Revenue', icon: 'trending-up' },
            { id: 'cos', label: 'Cost of Sales', icon: 'package' },
            { id: 'expenses', label: 'Expenses', icon: 'receipt' },
            { id: 'statement', label: 'P&L Statement', icon: 'file-text' }
        ],
        reports: [], periods: [], allRevenue: [], allCOS: [], allExpenses: [],
        activeReport: null, activePeriodId: null,
        revenueItems: [], cosOpening: [], cosPurchases: [], cosClosing: [], opexItems: [], otherExpItems: [],
        showCreateModal: false, showCatalogEditor: false, saving: false, loadingReport: false, showCopyDeptModal: false, copyDeptSource: '', copyDeptTarget: '',
        stockCatalog: [], aiReportText: '', _chartInstances: {}, collapsedDepts: {}, pnlChartType: 'bar', openingLocked: false,
        prevPnl: { month: '', year: '', totalRevenue: 0, cos: 0, grossProfit: 0, totalOpex: 0, totalOther: 0, operatingProfit: 0, netProfit: 0, closingStock: 0 },
        prevPnlLoaded: false, showPdfSettings: false,
        pdfStyle: { fontFamily: 'Inter', bodySize: 11, headerSize: 14, tableHeaderSize: 9, tableBodySize: 11, footerSize: 8, headerBg: '#000000', headerText: '#f59e0b', accentColor: '#f59e0b', tableBg: '#000000', tableText: '#f59e0b', totalBg: '#000000', totalText: '#ffffff', pageBorder: '#000000' },
        rollupType: 'Q1', rollupYear: new Date().getFullYear(), rollupLoading: false,
        pnlPreparedBy: 'MIAUDITOPS', pnlReportStatus: 'draft', pnlPeriodLabel: 'End of Month',
        createForm: { title: '', industry: 'hospitality', report_month: new Date().getMonth() + 1, report_year: new Date().getFullYear(), location: '' },
        newPeriod: { from: '', to: '' },

        async init() {
            // Get client name from PHP-injected data attribute
            const el = document.querySelector('[data-client-name]');
            if (el) this.clientName = el.dataset.clientName;
            await this.loadReports();
            await this.loadCatalog();
            lucide.createIcons();
            this.$watch('currentTab', () => this.$nextTick(() => lucide.createIcons()));
        },

        switchTab(id) {
            if (id !== 'reports' && !this.activeReport) { this.toast('Select or create a report first', 'warning'); this.currentTab = 'reports'; return; }
            if (['revenue', 'cos', 'expenses'].includes(id) && !this.activePeriodId && this.periods.length) {
                this.activePeriodId = this.periods[0].id;
                this.loadPeriodData(this.activePeriodId);
            }
            this.currentTab = id;
            if (id === 'statement') this.$nextTick(() => { this.renderPnlCharts(); lucide.createIcons(); });
        },

        selectPeriod(pid) {
            this.activePeriodId = pid;
            this.loadPeriodData(pid);
        },

        periodLabel() {
            let p = this.periods.find(x => x.id == this.activePeriodId);
            return p ? this.activeReport.title + ' — ' + p.date_from + ' to ' + p.date_to : '';
        },

        itemTotal(item) {
            if (item.sub_entries && item.sub_entries.length > 0) return item.sub_entries.reduce((s, e) => s + (+e.amount || 0), 0);
            return +item.amount || 0;
        },

        // For purchase sub_entries (note+amount)
        cosItemTotal(item) {
            if (item.sub_entries && item.sub_entries.length > 0) return item.sub_entries.reduce((s, e) => s + (+e.amount || 0), 0);
            return +item.amount || 0;
        },

        fmt(v) { return '₦' + Number(v || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

        toast(msg, type = 'success') {
            let t = document.createElement('div');
            t.className = 'fixed bottom-6 right-6 z-[9999] px-5 py-3 rounded-xl text-white text-sm font-bold shadow-2xl transition-all';
            t.style.background = type === 'success' ? 'linear-gradient(135deg,#059669,#047857)' : type === 'warning' ? 'linear-gradient(135deg,#d97706,#b45309)' : 'linear-gradient(135deg,#dc2626,#b91c1c)';
            t.textContent = msg; document.body.appendChild(t);
            setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
        },

        get cosCalc() {
            let opening = this.cosOpening.reduce((s, i) => s + (+i.amount || 0), 0);
            let purchases = this.cosPurchases.reduce((s, i) => s + this.cosItemTotal(i), 0);
            let closing = this.cosClosing.reduce((s, i) => s + ((+i.qty || 0) * (+i.unit_cost || 0)), 0);
            let goodsAvailable = opening + purchases;
            return { opening, purchases, closingStock: closing, goodsAvailable, costOfSales: goodsAvailable - closing };
        },

        // Aggregated statement data (across ALL periods)
        get stmtRevenue() { return this._aggregate(this.allRevenue, 'label', 'amount'); },
        get stmtCOS() {
            let sp = (this.periods || []).slice().sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0) || (a.date_from || '').localeCompare(b.date_from || ''));
            let fp = sp.length ? sp[0].id : null;
            let lp = sp.length ? sp[sp.length - 1].id : null;
            let filtered = this.allCOS.filter(i => {
                if (i.entry_type === 'opening') return fp ? i.period_id == fp : true;
                if (i.entry_type === 'closing') return lp ? i.period_id == lp : true;
                return true; // purchases: all periods
            });
            // Aggregate but group closing items by department
            let agg = this._aggregateCOS(filtered.filter(i => i.entry_type !== 'closing'));
            // Add department-level closing totals
            let deptTotals = {};
            filtered.filter(i => i.entry_type === 'closing').forEach(i => {
                let dept = i.department || i.label || 'Stock';
                if (!deptTotals[dept]) deptTotals[dept] = 0;
                deptTotals[dept] += +(i.amount || 0);
            });
            Object.entries(deptTotals).forEach(([dept, total]) => {
                agg.push({ label: 'Department - ' + dept + ' Stock Value', amount: total, entry_type: 'closing' });
            });
            return agg;
        },
        get stmtOpex() { return this._aggregate(this.allExpenses.filter(e => e.category === 'operating'), 'label', 'amount'); },
        get stmtOther() { return this._aggregate(this.allExpenses.filter(e => e.category === 'other'), 'label', 'amount'); },

        // Build columnar weekly table HTML for on-page Notes
        buildWeeklyTableHTML(dataItems, category, totalLabel, totalColor) {
            const f = v => '₦' + (parseFloat(v) || 0).toLocaleString('en', { minimumFractionDigits: 2 });
            const esc = v => String(v || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            // Filter items based on category
            let items;
            if (category === 'revenue') items = this.allRevenue.filter(i => i.label && (+i.amount || 0) !== 0);
            else items = this.allExpenses.filter(i => i.category === category && i.label && (+i.amount || 0) !== 0);
            // Build data by period
            const byPeriod = {};
            const labels = new Set();
            items.forEach(i => {
                if (!byPeriod[i.period_id]) byPeriod[i.period_id] = {};
                byPeriod[i.period_id][i.label] = (byPeriod[i.period_id][i.label] || 0) + (+i.amount || 0);
                labels.add(i.label);
            });
            const allLabels = [...labels];
            const ps = this.periods.filter(p => byPeriod[p.id] && Object.keys(byPeriod[p.id]).length > 0);
            if (ps.length === 0 || allLabels.length === 0) return '';
            // Build table
            let h = '<table style="width:100%;border-collapse:collapse;font-size:10px"><thead><tr style="background:#000">';
            h += '<th style="padding:7px 6px;text-align:left;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px;font-size:8px">Item</th>';
            ps.forEach(p => {
                const d1 = p.date_from ? p.date_from.slice(5) : '';
                const d2 = p.date_to ? p.date_to.slice(5) : '';
                h += `<th style="padding:7px 4px;text-align:right;font-weight:700;color:#fff;font-size:7px;white-space:nowrap">${esc(d1)}<br>${esc(d2)}</th>`;
            });
            h += `<th style="padding:7px 6px;text-align:right;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px;font-size:8px">Total</th></tr></thead><tbody>`;
            allLabels.forEach(label => {
                h += '<tr style="border-bottom:1px solid #f1f5f9">';
                h += `<td style="padding:5px 6px;color:#334155;font-weight:600">${esc(label)}</td>`;
                let rowT = 0;
                ps.forEach(p => { const v = (byPeriod[p.id] && byPeriod[p.id][label]) || 0; rowT += v; h += `<td style="padding:5px 4px;text-align:right;color:#64748b">${f(v)}</td>`; });
                h += `<td style="padding:5px 6px;text-align:right;font-weight:800;color:#000">${f(rowT)}</td></tr>`;
            });
            h += `<tr style="background:#f8fafc;border-top:2px solid #000"><td style="padding:7px 6px;font-weight:900;color:#000">${totalLabel}</td>`;
            let grand = 0;
            ps.forEach(p => { const pt = Object.values(byPeriod[p.id] || {}).reduce((s, v) => s + v, 0); grand += pt; h += `<td style="padding:7px 4px;text-align:right;font-weight:800;color:#1e293b">${f(pt)}</td>`; });
            h += `<td style="padding:7px 6px;text-align:right;font-weight:900;color:${totalColor}">${f(grand)}</td></tr></tbody></table>`;
            return h;
        },

        _aggregate(items, labelKey, amountKey) {
            let map = {};
            items.forEach(i => {
                let k = i[labelKey] || '';
                if (!k) return;
                if (!map[k]) map[k] = { label: k, amount: 0 };
                map[k].amount += +(i[amountKey] || 0);
            });
            return Object.values(map);
        },

        _aggregateCOS(items) {
            let map = {};
            items.forEach(i => {
                let k = (i.label || '') + '|' + (i.entry_type || '');
                if (!i.label) return;
                if (!map[k]) map[k] = { label: i.label, amount: 0, entry_type: i.entry_type };
                map[k].amount += +(i.amount || 0);
            });
            return Object.values(map);
        },

        get closingStockByDept() {
            let sp = (this.periods || []).slice().sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0) || (a.date_from || '').localeCompare(b.date_from || ''));
            let lp = sp.length ? sp[sp.length - 1].id : null;
            let closingItems = this.allCOS.filter(i => i.entry_type === 'closing' && (lp ? i.period_id == lp : true));
            let depts = {};
            closingItems.forEach(i => {
                let dept = i.department || i.label || 'Uncategorized';
                if (!depts[dept]) depts[dept] = { name: dept, items: [], total: 0 };
                let subs = typeof i.sub_entries === 'string' ? JSON.parse(i.sub_entries || '[]') : (i.sub_entries || []);
                let qty = subs.length ? (+subs[0].qty || 0) : 0;
                let cost = subs.length ? (+subs[0].unit_cost || 0) : 0;
                let val = qty * cost || +(i.amount || 0);
                depts[dept].items.push({ label: i.label, qty, unit_cost: cost, value: val });
                depts[dept].total += val;
            });
            return Object.values(depts);
        },

        get pnl() {
            let totalRevenue = this.stmtRevenue.reduce((s, i) => s + i.amount, 0);
            // Opening stock = first period only; Closing stock = last period only (no accumulation)
            let sortedPeriods = (this.periods || []).slice().sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0) || (a.date_from || '').localeCompare(b.date_from || ''));
            let firstPid = sortedPeriods.length ? sortedPeriods[0].id : null;
            let lastPid = sortedPeriods.length ? sortedPeriods[sortedPeriods.length - 1].id : null;
            let opening = this.allCOS.filter(i => i.entry_type === 'opening' && (firstPid ? i.period_id == firstPid : true)).reduce((s, i) => s + (+i.amount || 0), 0);
            let purchases = this.allCOS.filter(i => i.entry_type === 'purchase').reduce((s, i) => s + (+i.amount || 0), 0);
            let closing = this.allCOS.filter(i => i.entry_type === 'closing' && (lastPid ? i.period_id == lastPid : true)).reduce((s, i) => s + (+i.amount || 0), 0);
            let cos = (opening + purchases) - closing;
            let grossProfit = totalRevenue - cos;
            let totalOpex = this.stmtOpex.reduce((s, i) => s + i.amount, 0);
            let totalOther = this.stmtOther.reduce((s, i) => s + i.amount, 0);
            let operatingProfit = grossProfit - totalOpex;
            let netProfit = operatingProfit - totalOther;
            let grossMargin = totalRevenue > 0 ? (grossProfit / totalRevenue) * 100 : 0;
            let netMargin = totalRevenue > 0 ? (netProfit / totalRevenue) * 100 : 0;
            let expenseRatio = totalRevenue > 0 ? ((totalOpex + totalOther) / totalRevenue) * 100 : 0;
            let cogsRatio = totalRevenue > 0 ? (cos / totalRevenue) * 100 : 0;
            return { totalRevenue, cos, grossProfit, totalOpex, totalOther, operatingProfit, netProfit, grossMargin, netMargin, expenseRatio, cogsRatio, opening, closing };
        },

        // ── API Calls ──
        async loadReports() {
            let r = await fetch('../ajax/pnl_api.php?action=get_reports');
            let d = await r.json(); if (d.success) this.reports = d.reports;
        },

        async createReport() {
            this.saving = true;
            try {
                let fd = new FormData(); fd.append('action', 'create_report');
                Object.entries(this.createForm).forEach(([k, v]) => fd.append(k, v));
                let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) {
                    this.showCreateModal = false;
                    this.createForm = { title: '', industry: 'hospitality', report_month: new Date().getMonth() + 1, report_year: new Date().getFullYear(), location: '' };
                    await this.loadReports(); await this.openReport(d.id);
                    this.toast('Report created — now add date periods');
                } else { this.toast(d.message || 'Failed', 'error'); }
            } catch (e) { this.toast('Save failed: ' + e.message, 'error'); } finally { this.saving = false; }
        },

        async openReport(id) {
            this.loadingReport = true;
            try {
                let r = await fetch('../ajax/pnl_api.php?action=get_report&id=' + id);
                let d = await r.json();
                if (d.success) {
                    this.activeReport = d.report;
                    this.periods = d.periods;
                    this.allRevenue = d.revenue.map(i => ({ ...i, amount: +i.amount }));
                    this.allCOS = d.cost_of_sales.map(i => ({ ...i, amount: +i.amount }));
                    this.allExpenses = d.expenses.map(i => ({ ...i, amount: +i.amount }));

                    this.aiReportText = d.report.ai_recommendation || '';
                    this.activePeriodId = this.periods.length ? this.periods[0].id : null;
                    if (this.activePeriodId) this.loadPeriodData(this.activePeriodId);
                    this.currentTab = 'periods';
                    this.$nextTick(() => lucide.createIcons());
                    // Restore saved previous P&L data
                    this.prevPnlLoaded = false;
                    if (d.report.prev_pnl_data) {
                        try {
                            let saved = JSON.parse(d.report.prev_pnl_data);
                            this.prevPnl = { month: saved.month || '', year: saved.year || '', totalRevenue: +saved.totalRevenue || 0, cos: +saved.cos || 0, grossProfit: +saved.grossProfit || 0, totalOpex: +saved.totalOpex || 0, totalOther: +saved.totalOther || 0, operatingProfit: +saved.operatingProfit || 0, netProfit: +saved.netProfit || 0, closingStock: +saved.closingStock || 0 };
                            this.prevPnlLoaded = true;
                        } catch (e) { this.fetchPrevPnl(); }
                    } else {
                        this.fetchPrevPnl();
                    }
                    // Restore PDF style
                    if (d.report.pdf_style) {
                        try { Object.assign(this.pdfStyle, this._defaultPdfStyle(), JSON.parse(d.report.pdf_style)); } catch (e) { }
                    } else {
                        this.pdfStyle = this._defaultPdfStyle();
                    }
                }
            } finally { this.loadingReport = false; }
        },

        async fetchPrevPnl() {
            try {
                let r = await fetch('../ajax/pnl_api.php?action=get_previous_month_pnl&report_id=' + this.activeReport.id);
                let d = await r.json();
                if (d.success && d.has_previous) {
                    this.prevPnl = { month: d.month, year: d.year, totalRevenue: +d.totalRevenue || 0, cos: +d.cos || 0, grossProfit: +d.grossProfit || 0, totalOpex: +d.totalOpex || 0, totalOther: +d.totalOther || 0, operatingProfit: +d.operatingProfit || 0, netProfit: +d.netProfit || 0, closingStock: +d.closingStock || 0 };
                    this.prevPnlLoaded = true;
                    this.savePrevPnl();
                    this.toast('Previous month P&L loaded');
                } else {
                    this.prevPnlLoaded = false;
                    this.prevPnl = { month: '', year: '', totalRevenue: 0, cos: 0, grossProfit: 0, totalOpex: 0, totalOther: 0, operatingProfit: 0, netProfit: 0, closingStock: 0 };
                }
            } catch (e) { this.prevPnlLoaded = false; }
        },

        async savePrevPnl() {
            if (!this.activeReport) return;
            try {
                let fd = new FormData();
                fd.append('action', 'save_prev_pnl');
                fd.append('report_id', this.activeReport.id);
                fd.append('prev_pnl_data', JSON.stringify(this.prevPnl));
                let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) this.toast('Previous month values saved');
            } catch (e) { }
        },

        _defaultPdfStyle() {
            return { fontFamily: 'Inter', bodySize: 11, headerSize: 14, tableHeaderSize: 12, tableBodySize: 11, footerSize: 8, headerBg: '#000000', headerText: '#f59e0b', accentColor: '#f59e0b', tableBg: '#000000', tableText: '#f59e0b', totalBg: '#000000', totalText: '#ffffff', pageBorder: '#000000' };
        },
        resetPdfStyle() {
            this.pdfStyle = this._defaultPdfStyle();
            this.savePdfStyle();
        },
        async savePdfStyle() {
            if (!this.activeReport) return;
            try {
                let fd = new FormData();
                fd.append('action', 'save_pdf_style');
                fd.append('report_id', this.activeReport.id);
                fd.append('pdf_style', JSON.stringify(this.pdfStyle));
                let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) this.toast('PDF style saved');
            } catch (e) { }
        },

        loadPeriodData(pid) {
            const parseSubs = (item) => {
                let subs = [];
                try { subs = typeof item.sub_entries === 'string' ? JSON.parse(item.sub_entries) : (item.sub_entries || []); } catch (e) { }
                if (!subs || subs.length === 0) subs = [{ note: '', amount: +item.amount || 0 }];
                return { ...item, amount: +item.amount, sub_entries: subs, _open: false };
            };
            this.revenueItems = this.allRevenue.filter(i => i.period_id == pid).map(i => ({ ...i }));
            // For new/empty periods: clone labels from nearest saved period in this report
            if (!this.revenueItems.length) {
                const otherPeriodIds = this.periods.map(p => p.id).filter(id => id != pid);
                const prevLabels = [];
                for (const opid of otherPeriodIds) {
                    const items = this.allRevenue.filter(i => i.period_id == opid && i.label);
                    if (items.length) { items.forEach(i => prevLabels.push(i.label)); break; }
                }
                if (prevLabels.length) {
                    this.revenueItems = prevLabels.map(l => ({ label: l, amount: 0 }));
                } else {
                    this.revenueItems = [{ label: '', amount: 0 }];
                }
            }

            // COS: split by entry_type
            let cosAll = this.allCOS.filter(i => i.period_id == pid);
            let openings = cosAll.filter(i => i.entry_type === 'opening');
            let purchases = cosAll.filter(i => i.entry_type === 'purchase');
            let closings = cosAll.filter(i => i.entry_type === 'closing');

            // Opening stock: always load from FIRST period; lock for non-first periods
            let sortedP = (this.periods || []).slice().sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0) || (a.date_from || '').localeCompare(b.date_from || ''));
            let firstPeriodId = sortedP.length ? sortedP[0].id : null;
            this.openingLocked = (firstPeriodId !== null && pid != firstPeriodId);
            let openingSource = this.openingLocked ? this.allCOS.filter(i => i.entry_type === 'opening' && i.period_id == firstPeriodId) : openings;
            this.cosOpening = openingSource.length ? openingSource.map(i => ({ ...i, amount: +i.amount })) : [{ label: 'Opening Stock', amount: 0, entry_type: 'opening' }];

            // Purchases: parse sub_entries
            this.cosPurchases = purchases.length ? purchases.map(item => {
                let subs = [];
                try { subs = typeof item.sub_entries === 'string' ? JSON.parse(item.sub_entries) : (item.sub_entries || []); } catch (e) { }
                if (!subs || subs.length === 0) subs = [{ note: '', amount: +item.amount || 0 }];
                return { ...item, amount: +item.amount, sub_entries: subs, _open: false };
            }) : [{ label: '', sub_entries: [{ note: '', amount: 0 }], entry_type: 'purchase', _open: true }];
            // For new/empty periods: clone purchase category labels from nearest saved period
            if (!purchases.length) {
                const otherPeriodIds = this.periods.map(p => p.id).filter(id => id != pid);
                for (const opid of otherPeriodIds) {
                    const purItems = this.allCOS.filter(i => i.period_id == opid && i.entry_type === 'purchase' && i.label);
                    if (purItems.length) {
                        this.cosPurchases = purItems.map(i => ({ label: i.label, sub_entries: [{ note: '', amount: 0 }], entry_type: 'purchase', _open: false }));
                        break;
                    }
                }
            }

            // Closing: build from catalog, overlay saved quantities
            let savedClosing = cosAll.filter(i => i.entry_type === 'closing');
            let savedMap = {};
            savedClosing.forEach(item => {
                savedMap[item.label] = item;
            });
            // Build from catalog items + any saved qty
            this.cosClosing = this.stockCatalog.map(cat => {
                let saved = savedMap[cat.item_name];
                let qty = 0;
                if (saved) {
                    // Try to get qty from sub_entries
                    let subs = [];
                    try { subs = typeof saved.sub_entries === 'string' ? JSON.parse(saved.sub_entries) : (saved.sub_entries || []); } catch (e) { }
                    if (subs && subs.length > 0 && subs[0].qty !== undefined) {
                        qty = +subs[0].qty || 0;
                    } else {
                        qty = (+saved.amount || 0) / (+cat.unit_cost || 1);
                    }
                }
                let totalQty = qty;
                let packs = 0, pieces = qty;
                const ps = Math.max(1, +cat.pack_size || 1);
                if (ps > 1 && qty > 0) { packs = Math.floor(qty / ps); pieces = qty % ps; }
                return { label: cat.item_name, unit_cost: +cat.unit_cost || 0, qty: totalQty, packs: packs, pieces: pieces, pack_size: ps, department: cat.department || '', category: cat.category || '', entry_type: 'closing' };
            });
            if (this.cosClosing.length === 0 && savedClosing.length > 0) {
                // Fallback: use saved data directly if catalog is empty
                this.cosClosing = savedClosing.map(item => {
                    let subs = [];
                    try { subs = typeof item.sub_entries === 'string' ? JSON.parse(item.sub_entries) : []; } catch (e) { }
                    let qty = (subs && subs[0]?.qty) ? +subs[0].qty : 0;
                    let uc = (subs && subs[0]?.unit_cost) ? +subs[0].unit_cost : (+item.amount || 0);
                    return { label: item.label, unit_cost: uc, qty: qty, packs: 0, pieces: qty, pack_size: 1, department: '', category: '', entry_type: 'closing' };
                });
            }

            let opex = this.allExpenses.filter(e => e.period_id == pid && e.category === 'operating');
            let other = this.allExpenses.filter(e => e.period_id == pid && e.category === 'other');
            this.opexItems = opex.length ? opex.map(parseSubs) : [{ label: '', sub_entries: [{ note: '', amount: 0 }], category: 'operating', _open: true }];
            this.otherExpItems = other.length ? other.map(parseSubs) : [{ label: '', sub_entries: [{ note: '', amount: 0 }], category: 'other', _open: true }];
            this.$nextTick(() => lucide.createIcons());
        },

        async createPeriod() {
            if (!this.newPeriod.from || !this.newPeriod.to) { this.toast('Both dates required', 'error'); return; }
            this.saving = true;
            let fd = new FormData(); fd.append('action', 'create_period');
            fd.append('report_id', this.activeReport.id);
            fd.append('date_from', this.newPeriod.from); fd.append('date_to', this.newPeriod.to);
            let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
            let d = await r.json(); this.saving = false;
            if (d.success) {
                this.newPeriod = { from: '', to: '' };
                await this.openReport(this.activeReport.id);
                this.activePeriodId = d.period_id;
                this.loadPeriodData(d.period_id);
                this.toast('Period added — enter data in Revenue tab');
            } else { this.toast(d.message || 'Failed', 'error'); }
        },

        async deletePeriod(pid) {
            if (!confirm('Delete this period and all its data?')) return;
            let fd = new FormData(); fd.append('action', 'delete_period'); fd.append('period_id', pid);
            let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
            let d = await r.json();
            if (d.success) {
                if (this.activePeriodId == pid) this.activePeriodId = null;
                await this.openReport(this.activeReport.id);
                this.toast('Period deleted');
            }
        },

        async updatePeriod(p) {
            let fd = new FormData(); fd.append('action', 'update_period');
            fd.append('period_id', p.id); fd.append('date_from', p.date_from); fd.append('date_to', p.date_to);
            let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
            let d = await r.json();
            if (d.success) this.toast('Period dates updated');
            else this.toast(d.message || 'Update failed', 'error');
        },

        async saveRevenue() {
            this.saving = true;
            try {
                let fd = new FormData(); fd.append('action', 'save_revenue');
                fd.append('report_id', this.activeReport.id); fd.append('period_id', this.activePeriodId);
                fd.append('items', JSON.stringify(this.revenueItems));
                let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) { this._updateAllData(); this.toast('Revenue saved'); } else this.toast(d.message || 'Failed', 'error');
            } catch (e) { this.toast('Save failed: ' + e.message, 'error'); } finally { this.saving = false; }
        },

        async saveCOS() {
            this.saving = true;
            try {
                let fd = new FormData(); fd.append('action', 'save_cost_of_sales');
                fd.append('report_id', this.activeReport.id); fd.append('period_id', this.activePeriodId);
                let all = [
                    ...(this.openingLocked ? [] : this.cosOpening.map(i => ({ label: i.label, amount: +i.amount || 0, entry_type: 'opening' }))),
                    ...this.cosPurchases.map(i => ({ label: i.label, entry_type: 'purchase', sub_entries: i.sub_entries, amount: this.cosItemTotal(i) })),
                    ...this.cosClosing.map(i => ({ label: i.label, department: i.department || '', entry_type: 'closing', sub_entries: [{ qty: +i.qty || 0, unit_cost: +i.unit_cost || 0 }], amount: (+i.qty || 0) * (+i.unit_cost || 0) }))
                ];
                fd.append('items', JSON.stringify(all));
                let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) { this._updateAllData(); this.toast('Cost of Sales saved'); } else this.toast(d.message || 'Failed', 'error');
            } catch (e) { this.toast('Save failed: ' + e.message, 'error'); } finally { this.saving = false; }
        },

        async saveExpenses() {
            this.saving = true;
            try {
                let fd = new FormData(); fd.append('action', 'save_expenses');
                fd.append('report_id', this.activeReport.id); fd.append('period_id', this.activePeriodId);
                let all = [
                    ...this.opexItems.map(i => ({ label: i.label, category: 'operating', sub_entries: i.sub_entries, amount: this.itemTotal(i) })),
                    ...this.otherExpItems.map(i => ({ label: i.label, category: 'other', sub_entries: i.sub_entries, amount: this.itemTotal(i) }))
                ];
                fd.append('items', JSON.stringify(all));
                let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) { this._updateAllData(); this.toast('Expenses saved'); } else this.toast(d.message || 'Failed', 'error');
            } catch (e) { this.toast('Save failed: ' + e.message, 'error'); } finally { this.saving = false; }
        },

        // Refresh all data from server after saves (for aggregated statement)
        async _updateAllData() {
            let r = await fetch('../ajax/pnl_api.php?action=get_report&id=' + this.activeReport.id);
            let d = await r.json();
            if (d.success) {
                this.allRevenue = d.revenue.map(i => ({ ...i, amount: +i.amount }));
                this.allCOS = d.cost_of_sales.map(i => ({ ...i, amount: +i.amount }));
                this.allExpenses = d.expenses.map(i => ({ ...i, amount: +i.amount }));
                this.periods = d.periods;
            }
        },

        async deleteReport(id) {
            if (!confirm('Delete this report and all its data?')) return;
            let fd = new FormData(); fd.append('action', 'delete_report'); fd.append('id', id);
            let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
            let d = await r.json();
            if (d.success) {
                if (this.activeReport && this.activeReport.id == id) { this.activeReport = null; this.periods = []; }
                await this.loadReports(); this.toast('Report deleted');
            }
        },

        async finalizeReport() {
            if (!confirm('Finalize? Report becomes read-only.')) return;
            let fd = new FormData(); fd.append('action', 'finalize_report'); fd.append('id', this.activeReport.id);
            let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
            let d = await r.json();
            if (d.success) { this.activeReport.status = 'finalized'; this.toast('Report finalized'); await this.loadReports(); }
        },

        // ── Stock Catalog ──
        async loadCatalog() {
            let r = await fetch('../ajax/pnl_api.php?action=get_stock_catalog');
            let d = await r.json();
            if (d.success) this.stockCatalog = d.items.map(i => ({ ...i, unit_cost: +i.unit_cost, pack_size: Math.max(1, +i.pack_size || 1) }));
        },

        async saveCatalog() {
            this.saving = true;
            try {
                let fd = new FormData(); fd.append('action', 'save_stock_catalog');
                fd.append('items', JSON.stringify(this.stockCatalog));
                let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) {
                    await this.loadCatalog();
                    if (this.activePeriodId) this.loadPeriodData(this.activePeriodId);
                    this.toast('Stock catalog saved');
                } else { this.toast(d.message || 'Failed', 'error'); }
            } catch (e) { this.toast('Save failed: ' + e.message, 'error'); } finally { this.saving = false; }
        },

        catalogDepartments() {
            let deps = {};
            this.stockCatalog.forEach(c => { if (c.department) deps[c.department] = (deps[c.department] || 0) + 1; });
            return Object.entries(deps).map(([name, count]) => ({ name, count }));
        },

        copyFromDepartment() {
            if (!this.copyDeptSource || !this.copyDeptTarget) { this.toast('Select source department and enter new department name', 'error'); return; }
            if (this.copyDeptSource === this.copyDeptTarget) { this.toast('Source and target must be different', 'error'); return; }
            let sourceItems = this.stockCatalog.filter(c => c.department === this.copyDeptSource);
            if (!sourceItems.length) { this.toast('No items in source department', 'error'); return; }
            let copied = sourceItems.map(c => ({ item_name: c.item_name, unit_cost: c.unit_cost, department: this.copyDeptTarget, category: c.category, pack_size: c.pack_size || 1 }));
            this.stockCatalog.push(...copied);
            this.showCopyDeptModal = false;
            this.copyDeptSource = ''; this.copyDeptTarget = '';
            this.toast(`${copied.length} items copied to ${this.copyDeptTarget}`);
        },

        downloadCatalogTemplate() {
            let csv = 'DEPARTMENT,CATEGORY,ITEM_NAME,PACK_SIZE,UNIT_COST\n';
            csv += 'STORE,BEVERAGE,Absolut Vodka,1,12500\n';
            csv += 'STORE,BEVERAGE,Star Lager Beer,1,800\n';
            csv += 'KITCHEN,FOOD,Chicken Wings,1,3500\n';
            let blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            let a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'stock_catalog_template.csv';
            a.click();
            this.toast('Template downloaded — fill it in Excel and save as CSV UTF-8');
        },

        importCatalogCSV() {
            let input = document.createElement('input');
            input.type = 'file';
            input.accept = '.csv,.txt';
            input.onchange = (e) => {
                let file = e.target.files[0];
                if (!file) return;
                let reader = new FileReader();
                reader.onload = (ev) => {
                    let text = ev.target.result.replace(/^\uFEFF/, ''); // strip BOM
                    let lines = text.split(/\r?\n/).filter(l => l.trim());
                    if (lines.length < 2) { this.toast('CSV is empty or has no data rows', 'error'); return; }
                    // Parse header
                    let header = lines[0].split(',').map(h => h.trim().toUpperCase().replace(/['"]/g, ''));
                    let colMap = {};
                    const keyMap = { 'DEPARTMENT': 'DEPARTMENT', 'CATEGORY': 'CATEGORY', 'ITEM_NAME': 'ITEM_NAME', 'ITEM NAME': 'ITEM_NAME', 'PACK_SIZE': 'PACK_SIZE', 'PACK SIZE': 'PACK_SIZE', 'UNIT_COST': 'UNIT_COST', 'UNIT COST': 'UNIT_COST', 'COST': 'UNIT_COST' };
                    Object.keys(keyMap).forEach(h => {
                        let idx = header.indexOf(h);
                        if (idx >= 0) colMap[keyMap[h]] = idx;
                    });
                    if (colMap['ITEM_NAME'] === undefined) { this.toast('CSV must have an ITEM_NAME column', 'error'); return; }
                    // Parse CSV rows (handle quoted fields)
                    function parseCSVLine(line) {
                        let cols = []; let cur = ''; let inQ = false;
                        for (let ch of line) {
                            if (ch === '"') { inQ = !inQ; }
                            else if (ch === ',' && !inQ) { cols.push(cur.trim()); cur = ''; }
                            else { cur += ch; }
                        }
                        cols.push(cur.trim());
                        return cols;
                    }
                    // Build existing item set for dedup
                    let existing = new Set(this.stockCatalog.map(c => (c.department || '').toLowerCase() + '|' + (c.item_name || '').toLowerCase()));
                    let imported = 0, skipped = 0;
                    for (let i = 1; i < lines.length; i++) {
                        let cols = parseCSVLine(lines[i]);
                        let name = (cols[colMap['ITEM_NAME']] || '').replace(/^["']|["']$/g, '').trim();
                        if (!name) continue;
                        let dept = colMap['DEPARTMENT'] !== undefined ? (cols[colMap['DEPARTMENT']] || '').replace(/^["']|["']$/g, '').trim() : '';
                        let cat = colMap['CATEGORY'] !== undefined ? (cols[colMap['CATEGORY']] || '').replace(/^["']|["']$/g, '').trim() : '';
                        let ps = colMap['PACK_SIZE'] !== undefined ? parseInt(cols[colMap['PACK_SIZE']]) || 1 : 1;
                        let cost = colMap['UNIT_COST'] !== undefined ? parseFloat((cols[colMap['UNIT_COST']] || '0').replace(/[,'"]/g, '')) || 0 : 0;
                        let key = dept.toLowerCase() + '|' + name.toLowerCase();
                        if (existing.has(key)) { skipped++; continue; }
                        existing.add(key);
                        this.stockCatalog.push({ item_name: name, unit_cost: cost, department: dept, category: cat, pack_size: ps });
                        imported++;
                    }
                    this.toast(`Imported ${imported} items` + (skipped ? `, ${skipped} duplicates skipped` : ''));
                };
                reader.readAsText(file, 'UTF-8');
            };
            input.click();
        },

        startCount(i) {
            let el = document.getElementById('cq' + i);
            if (el) { el.focus(); el.select(); }
        },

        // ── Chart Rendering ──
        renderPnlCharts() {
            const colors = ['#10b981', '#f59e0b', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6', '#ef4444', '#6366f1'];
            const destroy = (id) => { if (this._chartInstances[id]) { this._chartInstances[id].destroy(); delete this._chartInstances[id]; } };
            const opts = (type, labels, data, bgColors) => ({
                type, data: { labels, datasets: [{ data, backgroundColor: bgColors, borderWidth: 0, borderRadius: type === 'bar' ? 6 : 0 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, plugins: { legend: { position: type === 'doughnut' ? 'right' : 'bottom', labels: { font: { size: 9, weight: 700 }, padding: 8, usePointStyle: true, pointStyleWidth: 8 } } },
                    scales: type === 'bar' ? { y: { beginAtZero: true, ticks: { font: { size: 9 }, callback: v => '₦' + v.toLocaleString() } }, x: { ticks: { font: { size: 9 } } } } : {}
                }
            });

            // Revenue Breakdown
            let revEl = document.getElementById('pnlRevenueChart');
            if (revEl) { destroy('rev'); let r = this.stmtRevenue; this._chartInstances.rev = new Chart(revEl, opts('doughnut', r.map(i => i.label), r.map(i => i.amount), colors.slice(0, r.length))); }

            // Expense Breakdown
            let expEl = document.getElementById('pnlExpenseChart');
            if (expEl) { destroy('exp'); let all = [...this.stmtOpex, ...this.stmtOther]; this._chartInstances.exp = new Chart(expEl, opts('bar', all.map(i => i.label), all.map(i => i.amount), all.map((_, i) => colors[i % colors.length]))); }

            // COS Breakdown
            let cosEl = document.getElementById('pnlCOSChart');
            if (cosEl) { destroy('cos'); let cc = this.cosCalc; this._chartInstances.cos = new Chart(cosEl, opts('doughnut', ['Opening Stock', 'Purchases', 'Closing Stock'], [cc.opening, cc.purchases, cc.closingStock], ['#f59e0b', '#3b82f6', '#10b981'])); }

            // P&L Composition
            let compEl = document.getElementById('pnlCompositionChart');
            if (compEl) { destroy('comp'); let p = this.pnl; this._chartInstances.comp = new Chart(compEl, opts('doughnut', ['COS', 'Operating Exp', 'Other Exp', 'Net Profit'], [p.cos, p.totalOpex, p.totalOther, Math.max(0, p.netProfit)], ['#f59e0b', '#3b82f6', '#8b5cf6', '#10b981'])); }
        },

        generateAIReport() {
            let p = this.pnl, c = this.cosCalc, r = this.activeReport;
            let topRev = this.stmtRevenue.length ? this.stmtRevenue.reduce((a, b) => a.amount > b.amount ? a : b) : { label: 'N/A', amount: 0 };
            let topExp = this.stmtOpex.length ? this.stmtOpex.reduce((a, b) => a.amount > b.amount ? a : b) : { label: 'N/A', amount: 0 };
            let health = p.netMargin >= 15 ? 'STRONG' : p.netMargin >= 5 ? 'MODERATE' : p.netMargin >= 0 ? 'WEAK' : 'CRITICAL';
            let healthColor = p.netMargin >= 15 ? '#059669' : p.netMargin >= 5 ? '#f59e0b' : p.netMargin >= 0 ? '#dc2626' : '#7f1d1d';
            let recs = '';
            if (p.netProfit < 0) recs += '<li>\ud83d\udd34 <strong>URGENT:</strong> Operating at a loss. Review pricing, cut non-essential expenses, negotiate better supplier terms.</li>';
            if (p.grossMargin < 30) recs += '<li>\ud83d\udfe1 Gross margin below 30%. Consider renegotiating supplier contracts and reviewing product mix.</li>';
            if (p.expenseRatio > 35) recs += '<li>\ud83d\udfe1 Expenses consume over 35% of revenue. Audit each expense category and consider automation.</li>';
            if (p.netMargin >= 15) recs += '<li>\ud83d\udfe2 Strong net margin. Reinvest profits, build cash reserves, consider expansion.</li>';
            if (this.cosClosing.length > 0) recs += '<li>Total closing stock valuation: <strong>' + this.fmt(c.closingStock) + '</strong> across ' + this.cosClosing.filter(i => +i.qty > 0).length + ' items</li>';

            this.aiReportText = '<h2 style="font-size:16px;font-weight:900;margin-bottom:4px">AI FINANCIAL ANALYSIS REPORT</h2>'
                + '<p style="color:#64748b;font-size:10px;margin-bottom:12px"><strong>Client:</strong> ' + (r.client_name || r.title) + ' &nbsp;|&nbsp; <strong>Period:</strong> ' + this.monthNames[r.report_month - 1] + ' ' + r.report_year + ' &nbsp;|&nbsp; <strong>Industry:</strong> ' + r.industry.toUpperCase() + ' &nbsp;|&nbsp; <strong>Generated:</strong> ' + new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) + '</p>'
                + '<hr style="border:none;border-top:2px solid #e2e8f0;margin:12px 0">'
                + '<h3 style="font-size:13px;font-weight:800;margin-bottom:6px">1. EXECUTIVE SUMMARY</h3>'
                + '<p>Overall Financial Health: <strong style="color:' + healthColor + '">' + health + '</strong></p>'
                + '<p>The business generated total revenue of <strong>' + this.fmt(p.totalRevenue) + '</strong> with a net ' + (p.netProfit >= 0 ? 'profit' : 'loss') + ' of <strong>' + this.fmt(Math.abs(p.netProfit)) + '</strong>. Gross margin: <strong>' + p.grossMargin.toFixed(1) + '%</strong>, Net margin: <strong>' + p.netMargin.toFixed(1) + '%</strong>.</p>'
                + '<h3 style="font-size:13px;font-weight:800;margin:12px 0 6px">2. REVENUE ANALYSIS</h3>'
                + '<ul style="padding-left:20px;margin:6px 0"><li>Total Revenue: <strong>' + this.fmt(p.totalRevenue) + '</strong> across ' + this.stmtRevenue.length + ' stream(s)</li><li>Top Source: <strong>' + topRev.label + '</strong> — ' + this.fmt(topRev.amount) + ' (' + (p.totalRevenue > 0 ? (topRev.amount / p.totalRevenue * 100).toFixed(1) : '0') + '%)</li></ul>'
                + '<h3 style="font-size:13px;font-weight:800;margin:12px 0 6px">3. COST EFFICIENCY</h3>'
                + '<ul style="padding-left:20px;margin:6px 0"><li>Cost of Sales: <strong>' + this.fmt(p.cos) + '</strong> (' + p.cogsRatio.toFixed(1) + '% of revenue)</li><li>Opening: ' + this.fmt(c.opening) + ' + Purchases: ' + this.fmt(c.purchases) + ' − Closing: ' + this.fmt(c.closingStock) + '</li><li>' + (p.cogsRatio > 65 ? '\u26a0\ufe0f COGS exceeds 65%' : p.cogsRatio > 50 ? 'COGS acceptable, monitor' : '\u2705 COGS well-controlled') + '</li></ul>'
                + '<h3 style="font-size:13px;font-weight:800;margin:12px 0 6px">4. EXPENSE MANAGEMENT</h3>'
                + '<ul style="padding-left:20px;margin:6px 0"><li>Operating Expenses: <strong>' + this.fmt(p.totalOpex) + '</strong> | Other: <strong>' + this.fmt(p.totalOther) + '</strong></li><li>Expense Ratio: <strong>' + p.expenseRatio.toFixed(1) + '%</strong> | Highest: <strong>' + topExp.label + '</strong> at ' + this.fmt(topExp.amount) + '</li><li>' + (p.expenseRatio > 40 ? '\u26a0\ufe0f Expense ratio high' : '\u2705 Healthy expense ratio') + '</li></ul>'
                + '<h3 style="font-size:13px;font-weight:800;margin:12px 0 6px">5. KEY RECOMMENDATIONS</h3>'
                + '<ul style="padding-left:20px;margin:6px 0">' + recs + '</ul>'
                + '<hr style="border:none;border-top:2px solid #e2e8f0;margin:12px 0">'
                + '<p style="color:#94a3b8;font-size:9px;font-style:italic">Auto-generated. Review and modify before presenting to stakeholders. Powered by MIAUDITOPS.</p>';
            this.toast('AI report generated');
        },

        async saveAIReport() {
            if (!this.activeReport) return;
            this.saving = true;
            try {
                let fd = new FormData();
                fd.append('action', 'save_ai_report');
                fd.append('report_id', this.activeReport.id);
                fd.append('ai_recommendation', this.aiReportText);
                let r = await fetch('../ajax/pnl_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) this.toast('AI report saved');
                else this.toast(d.message || 'Failed', 'error');
            } catch (e) { this.toast('Save failed: ' + e.message, 'error'); } finally { this.saving = false; }
        },

        // ── Export PDF (standalone HTML in new window) ──
        exportPnlPDF() {
            if (!this.activeReport) { this.toast('No report selected', 'error'); return; }
            const N = '₦';
            const f = v => N + (parseFloat(v) || 0).toLocaleString('en', { minimumFractionDigits: 2 });
            const pnl = this.pnl;
            const rep = this.activeReport;
            const mn = this.monthNames;
            const clientName = rep.client_name || rep.title;
            const period = mn[rep.report_month - 1] + ' ' + rep.report_year;
            const location = rep.location || clientName;
            const industry = (rep.industry || '').toUpperCase();
            const genDate = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' });
            const preparedBy = this.pnlPreparedBy || 'MIAUDITOPS';
            const reportStatus = this.pnlReportStatus || 'draft';
            const cosCalc = this.cosCalc;
            const esc = v => String(v || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            const S = this.pdfStyle;
            const fontUrl = S.fontFamily === 'Arial' || S.fontFamily === 'Georgia' || S.fontFamily === 'Times New Roman' ? '' : `@import url('https://fonts.googleapis.com/css2?family=${S.fontFamily.replace(/ /g, '+')}:wght@300;400;500;600;700;800;900&display=swap');`;
            const css = `
                ${fontUrl}
                *{margin:0;padding:0;box-sizing:border-box}
                body{font-family:'${S.fontFamily}',sans-serif;color:#1e293b;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
                .page{width:210mm;min-height:297mm;padding:18mm 20mm 28mm 20mm;margin:0 auto;background:#fff;position:relative;page-break-after:always;page-break-inside:avoid;overflow:visible}
                .page:last-child{page-break-after:avoid}
                .page-auto{width:210mm;padding:4mm 20mm 4mm 20mm;margin:0 auto;background:#fff;position:relative;page-break-inside:auto;overflow:visible}
                .page-auto table{page-break-inside:auto}
                .page-auto tr{page-break-inside:avoid;page-break-after:auto}
                .page-auto thead{display:table-header-group}
                .pg-footer{position:absolute;bottom:18mm;left:20mm;right:20mm;border-top:1px solid #f1f5f9;padding-top:8px;display:flex;justify-content:space-between;opacity:.5}
                .pg-footer-inline{border-top:1px solid #f1f5f9;padding-top:8px;margin-top:16px;display:flex;justify-content:space-between;opacity:.5}
                @page{size:A4;margin:14mm 0 16mm 0}
                @media print{body{background:none}
                    .page{margin:0;width:100%;padding:4mm 20mm 14mm 20mm;min-height:auto}
                    .page-auto{margin:0;width:100%;padding:0 20mm 0 20mm}
                    .pg-footer{bottom:0;left:20mm;right:20mm}
                }
            `;

            const pageHeader = (num, title) => `
                <div style="display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid ${S.pageBorder};padding-bottom:8px;margin-bottom:24px">
                    <h3 style="font-size:${S.headerSize}px;font-weight:900;color:${S.pageBorder};text-transform:uppercase;letter-spacing:0.5px">${String(num).padStart(2, '0')}. ${title}</h3>
                    <span style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:2px">${esc(clientName)} — ${esc(period)}</span>
                </div>`;

            const pageFooter = (pg) => `
                <div class="pg-footer">
                    <p style="font-size:${S.footerSize}px;font-weight:900;text-transform:uppercase;letter-spacing:2px">Generated by MIAUDITOPS</p>
                    <p style="font-size:${S.footerSize}px">${genDate}</p>
                </div>`;

            // PAGE 1: COVER
            const page1 = `
            <div class="page" style="display:flex;flex-direction:column;justify-content:space-between">
                <div style="border-bottom:4px solid #000;padding-bottom:32px;display:flex;justify-content:space-between;align-items:flex-start">
                    <div>
                        <h2 style="font-size:24px;font-weight:900;color:#000;letter-spacing:-0.5px">${esc(clientName)}</h2>
                        <p style="font-size:10px;font-weight:700;color:#64748b;letter-spacing:3px;text-transform:uppercase">${esc(industry)}</p>
                    </div>
                    <div style="text-align:right">
                        <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px">Confidential Document</p>
                        <p style="font-size:8px;font-weight:600;color:#cbd5e1;letter-spacing:1px;margin-top:2px">miauditops.ng</p>
                    </div>
                </div>
                <div style="padding:60px 0;text-align:center">
                    <div style="display:inline-block;padding:6px 20px;margin-bottom:28px">
                        <span style="font-size:11px;font-weight:900;letter-spacing:5px;text-transform:uppercase">${esc(this.pnlPeriodLabel)}</span>
                    </div>
                    <h1 style="font-size:42px;font-weight:900;color:#000;letter-spacing:-2px;line-height:1.1">PROFIT &amp; LOSS<br>STATEMENT</h1>
                    <div style="width:80px;height:6px;background:${S.accentColor};margin:32px auto;border-radius:4px"></div>
                    <h3 style="font-size:18px;font-weight:700;color:#1e293b">${esc(location)}</h3>
                    <p style="color:#64748b;font-family:monospace;font-size:12px;margin-top:4px">${esc(period)}</p>
                </div>
                <div style="border-top:2px solid #000;padding-top:32px;display:grid;grid-template-columns:1fr 1fr;gap:40px">
                    <div>
                        <div style="margin-bottom:16px"><p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Prepared For</p><p style="font-size:14px;font-weight:700;color:#000">${esc(clientName)}</p></div>
                        <div><p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Prepared By</p><p style="font-size:14px;font-weight:700;color:#000">${esc(preparedBy)}</p></div>
                    </div>
                    <div style="text-align:right">
                        <div style="margin-bottom:16px"><p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Date Generated</p><p style="font-size:14px;font-weight:700;color:#000">${genDate}</p></div>
                        <div><p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Status</p><span style="display:inline-block;padding:4px 12px;background:#000;color:#fff;font-size:10px;font-weight:700;border-radius:4px;text-transform:uppercase">${esc(reportStatus)}</span></div>
                    </div>
                </div>
            </div>`;

            // PAGE 2: STATEMENT OF P&L (standard income statement - totals only)
            const page2 = `
            <div class="page">
                ${pageHeader('01', 'Statement of Profit & Loss')}
                <table style="width:100%;border-collapse:collapse;font-size:${S.tableBodySize}px">
                <tbody>
                    <tr style="border-bottom:1px solid #e2e8f0"><td style="padding:12px 0;font-weight:700;color:#000">Revenue</td><td style="padding:12px 0;text-align:right;font-weight:800;color:#000">${f(pnl.totalRevenue)}</td></tr>
                    <tr style="border-bottom:1px solid #e2e8f0"><td style="padding:12px 0;color:#64748b">Less: Cost of Sales</td><td style="padding:12px 0;text-align:right;font-weight:700;color:#dc2626">(${f(pnl.cos)})</td></tr>
                    <tr style="border-bottom:2px solid #000;background:#f8fafc"><td style="padding:14px 0;font-weight:900;color:#000;font-size:13px">Gross Profit</td><td style="padding:14px 0;text-align:right;font-weight:900;font-size:13px;color:${pnl.grossProfit >= 0 ? '#059669' : '#dc2626'}">${f(pnl.grossProfit)}</td></tr>
                    <tr><td colspan="2" style="padding:8px 0"></td></tr>
                    <tr style="border-bottom:1px solid #e2e8f0"><td style="padding:12px 0;color:#64748b">Less: Operating Expenses</td><td style="padding:12px 0;text-align:right;font-weight:700;color:#dc2626">(${f(pnl.totalOpex)})</td></tr>
                    <tr style="border-bottom:2px solid #000;background:#f8fafc"><td style="padding:14px 0;font-weight:900;color:#000;font-size:13px">Operating Profit</td><td style="padding:14px 0;text-align:right;font-weight:900;font-size:13px;color:${pnl.operatingProfit >= 0 ? '#059669' : '#dc2626'}">${f(pnl.operatingProfit)}</td></tr>
                    <tr><td colspan="2" style="padding:8px 0"></td></tr>
                    <tr style="border-bottom:1px solid #e2e8f0"><td style="padding:12px 0;color:#64748b">Less: Other Expenses</td><td style="padding:12px 0;text-align:right;font-weight:700;color:#dc2626">(${f(pnl.totalOther)})</td></tr>
                    <tr style="background:${S.totalBg}"><td style="padding:16px 10px;font-weight:900;color:${S.totalText};font-size:${S.headerSize}px;border-radius:8px 0 0 8px">Net Profit / (Loss)</td><td style="padding:16px 10px;text-align:right;font-weight:900;font-size:${S.headerSize}px;border-radius:0 8px 8px 0;color:${pnl.netProfit >= 0 ? '#34d399' : '#fca5a5'}">${f(pnl.netProfit)}</td></tr>
                </tbody></table>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:32px">
                    <div style="background:${S.headerBg};border-radius:12px;padding:16px;text-align:center"><p style="font-size:18px;font-weight:900;color:${S.accentColor}">${f(pnl.totalRevenue)}</p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;margin-top:4px">Total Revenue</p></div>
                    <div style="background:${S.headerBg};border-radius:12px;padding:16px;text-align:center"><p style="font-size:18px;font-weight:900;color:${S.accentColor}">${f(pnl.cos)}</p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;margin-top:4px">Cost of Sales</p></div>
                    <div style="border-radius:12px;padding:16px;text-align:center;background:${pnl.netProfit >= 0 ? '#059669' : '#dc2626'}"><p style="font-size:18px;font-weight:900;color:#fff">${f(pnl.netProfit)}</p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,.6);text-transform:uppercase;margin-top:4px">${pnl.netProfit >= 0 ? 'Net Profit' : 'Net Loss'}</p></div>
                </div>
                ${pageFooter(2)}
            </div>`;

            // PAGE 3: NOTES (columnar per-period/weekly breakdown)
            const noteTitle = (num, title) => `
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><div style="width:22px;height:22px;border-radius:6px;background:${S.tableBg};display:flex;align-items:center;justify-content:center"><span style="font-size:9px;font-weight:900;color:${S.tableText}">${num}</span></div><span style="font-size:${S.bodySize}px;font-weight:900;color:${S.pageBorder};text-transform:uppercase;letter-spacing:1px">${title}</span></div>`;

            // Helper: build columnar table (Item | Week1 | Week2 | ... | Total)
            const buildColumnTable = (dataByPeriod, allItems, totalLabel, totalColor) => {
                const ps = this.periods.filter(p => dataByPeriod[p.id] && Object.keys(dataByPeriod[p.id]).length > 0);
                const colCount = ps.length + 2; // Item + periods + Total
                const colW = ps.length > 0 ? Math.floor(60 / ps.length) : 30;
                // Header row
                let html = '<table style="width:100%;border-collapse:collapse;font-size:' + S.tableHeaderSize + 'px"><thead><tr style="background:' + S.tableBg + '">';
                html += '<th style="padding:7px 6px;text-align:left;font-weight:900;color:' + S.tableText + ';text-transform:uppercase;letter-spacing:.5px;font-size:' + S.tableHeaderSize + 'px">Item</th>';
                ps.forEach(p => {
                    const d1 = p.date_from ? p.date_from.slice(5) : '';
                    const d2 = p.date_to ? p.date_to.slice(5) : '';
                    html += `<th style="padding:7px 4px;text-align:right;font-weight:700;color:#fff;font-size:7px;white-space:nowrap">${esc(d1)}<br>${esc(d2)}</th>`;
                });
                html += '<th style="padding:7px 6px;text-align:right;font-weight:900;color:' + S.tableText + ';text-transform:uppercase;letter-spacing:.5px;font-size:' + S.tableHeaderSize + 'px">Total</th></tr></thead><tbody>';
                // Data rows
                allItems.forEach(item => {
                    html += '<tr style="border-bottom:1px solid #f1f5f9">';
                    html += `<td style="padding:5px 6px;color:#334155;font-weight:600">${esc(item.label)}</td>`;
                    let rowTotal = 0;
                    ps.forEach(p => {
                        const val = (dataByPeriod[p.id] && dataByPeriod[p.id][item.label]) || 0;
                        rowTotal += val;
                        html += `<td style="padding:5px 4px;text-align:right;color:#64748b">${f(val)}</td>`;
                    });
                    html += `<td style="padding:5px 6px;text-align:right;font-weight:800;color:#000">${f(rowTotal)}</td>`;
                    html += '</tr>';
                });
                // Total row
                html += `<tr style="background:#f8fafc;border-top:2px solid ${S.pageBorder}"><td style="padding:7px 6px;font-weight:900;color:${S.pageBorder}">${totalLabel}</td>`;
                let grandTotal = 0;
                ps.forEach(p => {
                    const pTotal = Object.values(dataByPeriod[p.id] || {}).reduce((s, v) => s + v, 0);
                    grandTotal += pTotal;
                    html += `<td style="padding:7px 4px;text-align:right;font-weight:800;color:#1e293b">${f(pTotal)}</td>`;
                });
                html += `<td style="padding:7px 6px;text-align:right;font-weight:900;color:${totalColor}">${f(grandTotal)}</td></tr>`;
                html += '</tbody></table>';
                return html;
            };

            // Gather revenue by period
            const revByPeriod = {};
            const revLabels = new Set();
            this.allRevenue.filter(i => i.label && (+i.amount || 0) !== 0).forEach(i => {
                if (!revByPeriod[i.period_id]) revByPeriod[i.period_id] = {};
                revByPeriod[i.period_id][i.label] = (revByPeriod[i.period_id][i.label] || 0) + (+i.amount || 0);
                revLabels.add(i.label);
            });
            const revItems = [...revLabels].map(l => ({ label: l }));

            // Gather opex by period
            const expByPeriod = {};
            const expLabels = new Set();
            this.allExpenses.filter(i => i.category === 'operating' && i.label && (+i.amount || 0) !== 0).forEach(i => {
                if (!expByPeriod[i.period_id]) expByPeriod[i.period_id] = {};
                expByPeriod[i.period_id][i.label] = (expByPeriod[i.period_id][i.label] || 0) + (+i.amount || 0);
                expLabels.add(i.label);
            });
            const expItems = [...expLabels].map(l => ({ label: l }));

            // COS rows (aggregated)
            const cosRows = this.stmtCOS.map(i => `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 0;color:#334155">${i.entry_type === 'closing' ? 'Less: ' : i.entry_type === 'purchase' ? 'Add: ' : ''}${esc(i.label)}</td><td style="padding:6px 0;text-align:right;font-weight:700;color:${i.entry_type === 'closing' ? '#dc2626' : '#000'}">${i.entry_type === 'closing' ? '(' + f(i.amount) + ')' : f(i.amount)}</td></tr>`).join('');
            const cosBlock = `<div style="margin-bottom:24px">${noteTitle(2, 'Cost of Sales Computation')}<table style="width:100%;border-collapse:collapse;font-size:${S.tableBodySize}px"><tbody>${cosRows}<tr style="background:#f8fafc;border-top:2px solid #000"><td style="padding:8px 0;font-weight:900;color:#000">Cost of Sales (Opening + Purchases − Closing)</td><td style="padding:8px 0;text-align:right;font-weight:900;color:#ea580c">${f(pnl.cos)}</td></tr></tbody></table></div>`;

            // Other expenses rows
            const otherRows = this.stmtOther.length > 0 ? this.stmtOther.map(i => `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 0;color:#334155">${esc(i.label)}</td><td style="padding:6px 0;text-align:right;font-weight:700;color:#000">${f(i.amount)}</td></tr>`).join('') : '<tr><td style="padding:6px 0;color:#94a3b8;font-style:italic" colspan="2">No other expenses recorded</td></tr>';
            const otherBlock = `<div style="margin-bottom:24px">${noteTitle(4, 'Other Expenses')}<table style="width:100%;border-collapse:collapse;font-size:${S.tableBodySize}px"><tbody>${otherRows}<tr style="background:#f8fafc;border-top:2px solid #000"><td style="padding:8px 0;font-weight:900;color:#000">Total Other Expenses</td><td style="padding:8px 0;text-align:right;font-weight:900;color:#7c3aed">${f(pnl.totalOther)}</td></tr></tbody></table></div>`;

            const page3 = `
            <div class="page">
                ${pageHeader('02', 'Notes to the Financial Statement')}
                <div style="margin-bottom:24px">
                    ${noteTitle(1, 'Revenue Breakdown (Weekly)')}
                    ${revItems.length > 0 && this.periods.length > 0 ? buildColumnTable(revByPeriod, revItems, 'Total Revenue', '#059669') : `<table style="width:100%;border-collapse:collapse;font-size:${S.tableBodySize}px"><tbody>${this.stmtRevenue.map(i => `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 0;color:#334155">${esc(i.label)}</td><td style="padding:6px 0;text-align:right;font-weight:700;color:#000">${f(i.amount)}</td></tr>`).join('')}<tr style="background:#f8fafc;border-top:2px solid #000"><td style="padding:8px 0;font-weight:900;color:#000">Total Revenue</td><td style="padding:8px 0;text-align:right;font-weight:900;color:#059669">${f(pnl.totalRevenue)}</td></tr></tbody></table>`}
                </div>
                ${cosBlock}
                <p style="font-size:8px;color:#94a3b8;margin-top:-18px;margin-bottom:12px;font-style:italic">COS = Opening Stock (${f(cosCalc.opening)}) + Purchases (${f(cosCalc.purchases)}) − Closing Stock (${f(cosCalc.closingStock)}) = ${f(pnl.cos)} — ${pnl.cogsRatio.toFixed(1)}% of revenue</p>
                <div style="margin-bottom:24px">
                    ${noteTitle(3, 'Operating Expenses Detail (Weekly)')}
                    ${expItems.length > 0 && this.periods.length > 0 ? buildColumnTable(expByPeriod, expItems, 'Total Operating Expenses', '#2563eb') : `<table style="width:100%;border-collapse:collapse;font-size:${S.tableBodySize}px"><tbody>${this.stmtOpex.map(i => `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 0;color:#334155">${esc(i.label)}</td><td style="padding:6px 0;text-align:right;font-weight:700;color:#000">${f(i.amount)}</td></tr>`).join('')}<tr style="background:#f8fafc;border-top:2px solid #000"><td style="padding:8px 0;font-weight:900;color:#000">Total Operating Expenses</td><td style="padding:8px 0;text-align:right;font-weight:900;color:#2563eb">${f(pnl.totalOpex)}</td></tr></tbody></table>`}
                </div>
                ${otherBlock}
                ${pageFooter(3)}
            </div>`;

            // PAGE 4: KEY METRICS & FINANCIAL RATIOS
            const metricCard = (val, label, color) => `<div style="background:#000;border-radius:10px;padding:14px 16px;text-align:center"><p style="font-size:16px;font-weight:900;color:${color || '#f59e0b'}">${val}</p><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;margin-top:4px">${label}</p></div>`;
            const opMargin = pnl.totalRevenue > 0 ? (pnl.operatingProfit / pnl.totalRevenue * 100) : 0;
            const breakEvenRev = pnl.grossMargin > 0 ? ((pnl.totalOpex + pnl.totalOther) / (pnl.grossMargin / 100)) : 0;
            const ratioRow = (label, value, unit, color, desc) => {
                const barW = Math.min(100, Math.abs(value));
                const status = value >= 30 ? 'Excellent' : value >= 15 ? 'Good' : value >= 5 ? 'Fair' : 'Low';
                const statusColor = value >= 30 ? '#059669' : value >= 15 ? '#2563eb' : value >= 5 ? '#d97706' : '#dc2626';
                return `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:7px 10px"><span style="font-size:10px;font-weight:700;color:#1e293b">${label}</span><br><span style="font-size:7px;color:#94a3b8">${desc}</span></td><td style="padding:7px 8px;text-align:right"><span style="font-size:12px;font-weight:900;color:${color}">${value.toFixed(1)}${unit}</span></td><td style="padding:7px 8px;width:80px"><div style="height:6px;background:#f1f5f9;border-radius:999px;overflow:hidden"><div style="height:100%;border-radius:999px;background:${color};width:${barW}%"></div></div></td><td style="padding:7px 8px;text-align:center"><span style="font-size:7px;font-weight:800;color:${statusColor};background:${statusColor}15;padding:2px 6px;border-radius:4px;text-transform:uppercase">${status}</span></td></tr>`;
            };
            const page4 = `
            <div class="page">
                ${pageHeader('03', 'Key Financial Metrics & Ratios')}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px">
                    ${metricCard(f(pnl.totalRevenue), 'Total Revenue', '#059669')}
                    ${metricCard(f(pnl.grossProfit), 'Gross Profit', pnl.grossProfit >= 0 ? '#34d399' : '#ef4444')}
                    ${metricCard(f(pnl.operatingProfit), 'Operating Profit', pnl.operatingProfit >= 0 ? '#f59e0b' : '#ef4444')}
                    ${metricCard(f(pnl.netProfit), 'Net Profit', pnl.netProfit >= 0 ? '#22d3ee' : '#ef4444')}
                </div>
                <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:20px">
                    <div style="background:#000;padding:10px 16px"><p style="font-size:9px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:1px">Financial Ratios</p></div>
                    <table style="width:100%;border-collapse:collapse;font-size:${S.tableBodySize}px">
                    <thead><tr style="background:#f8fafc"><th style="padding:8px 12px;text-align:left;font-size:${S.tableHeaderSize}px;font-weight:800;color:#94a3b8;text-transform:uppercase">Ratio</th><th style="padding:8px 12px;text-align:right;font-size:${S.tableHeaderSize}px;font-weight:800;color:#94a3b8;text-transform:uppercase">Value</th><th style="padding:8px 12px;font-size:${S.tableHeaderSize}px;font-weight:800;color:#94a3b8;text-transform:uppercase">Indicator</th><th style="padding:8px 12px;text-align:center;font-size:${S.tableHeaderSize}px;font-weight:800;color:#94a3b8;text-transform:uppercase">Health</th></tr></thead>
                    <tbody>
                    ${ratioRow('Gross Profit Margin', pnl.grossMargin, '%', '#059669', 'Revenue retained after COS')}
                    ${ratioRow('Net Profit Margin', pnl.netMargin, '%', pnl.netMargin >= 0 ? '#2563eb' : '#dc2626', 'Revenue retained after all expenses')}
                    ${ratioRow('Cost-to-Revenue Ratio', pnl.cogsRatio, '%', '#ea580c', 'Percentage of revenue consumed by COS')}
                    ${ratioRow('Operating Expense Ratio', pnl.expenseRatio, '%', '#7c3aed', 'OpEx + Other as percentage of revenue')}
                    ${ratioRow('Operating Profit Margin', opMargin, '%', opMargin >= 0 ? '#0891b2' : '#dc2626', 'Profit from core operations')}
                    </tbody>
                    </table>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div style="background:linear-gradient(135deg,#000,#1e293b);border-radius:12px;padding:20px;text-align:center">
                        <p style="font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Break-even Revenue</p>
                        <p style="font-size:20px;font-weight:900;color:#f59e0b">${f(breakEvenRev)}</p>
                        <p style="font-size:8px;color:#64748b;margin-top:4px">Min revenue needed to cover all fixed costs</p>
                    </div>
                    <div style="background:linear-gradient(135deg,#000,#1e293b);border-radius:12px;padding:20px;text-align:center">
                        <p style="font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Revenue vs Break-even</p>
                        <p style="font-size:20px;font-weight:900;color:${pnl.totalRevenue >= breakEvenRev ? '#059669' : '#ef4444'}">${pnl.totalRevenue >= breakEvenRev ? '✓ ABOVE' : '✗ BELOW'}</p>
                        <p style="font-size:8px;color:#64748b;margin-top:4px">Gap: ${f(Math.abs(pnl.totalRevenue - breakEvenRev))} ${pnl.totalRevenue >= breakEvenRev ? 'surplus' : 'shortfall'}</p>
                    </div>
                </div>
                ${pageFooter(4)}
            </div>`;

            // PAGE 4.5: MONTH-OVER-MONTH COMPARISON (if previous data exists)
            const momMn = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            let pageMoM = '';
            const hasPrevData = this.prevPnlLoaded || this.prevPnl.totalRevenue || this.prevPnl.cos || this.prevPnl.netProfit || this.prevPnl.closingStock;
            if (hasPrevData) {
                const pm = this.prevPnl;
                const curLabel = momMn[(this.activeReport.report_month || 1) - 1] + ' ' + this.activeReport.report_year;
                const prevLabel = pm.month ? (momMn[(pm.month || 1) - 1] + ' ' + pm.year) : 'Previous';
                const momRow = (label, cur, prev, isCurrency, bold) => {
                    const variance = cur - prev;
                    const pctChange = prev !== 0 ? ((variance / Math.abs(prev)) * 100).toFixed(1) : (cur !== 0 ? '∞' : '0.0');
                    const arrow = variance > 0 ? '▲' : variance < 0 ? '▼' : '—';
                    const arrowColor = label.includes('Cost') || label.includes('Expense') ? (variance <= 0 ? '#059669' : '#dc2626') : (variance >= 0 ? '#059669' : '#dc2626');
                    const display = isCurrency ? f : (v) => v.toFixed(1) + '%';
                    const weight = bold ? '900' : '600';
                    const bg = bold ? 'background:#f8fafc;' : '';
                    return `<tr style="border-bottom:1px solid #f1f5f9;${bg}"><td style="padding:10px 12px;font-weight:${weight};color:#1e293b;font-size:11px">${label}</td><td style="padding:10px 12px;text-align:right;font-weight:700;color:#334155;font-size:11px">${display(cur)}</td><td style="padding:10px 12px;text-align:right;font-weight:600;color:#64748b;font-size:11px">${display(prev)}</td><td style="padding:10px 12px;text-align:right;font-weight:700;color:${arrowColor};font-size:11px">${arrow} ${isCurrency ? f(Math.abs(variance)) : Math.abs(variance).toFixed(1) + '%'}</td><td style="padding:10px 12px;text-align:right;font-weight:700;color:${arrowColor};font-size:11px">${pctChange}%</td></tr>`;
                };
                const prevGM = pm.totalRevenue > 0 ? (pm.grossProfit / pm.totalRevenue * 100) : 0;
                const prevNM = pm.totalRevenue > 0 ? (pm.netProfit / pm.totalRevenue * 100) : 0;
                pageMoM = `
                <div class="page">
                    ${pageHeader('03b', 'Month-over-Month Comparison')}
                    <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
                        <table style="width:100%;border-collapse:collapse;font-size:${S.tableBodySize}px">
                        <thead><tr style="background:#000"><th style="padding:10px 12px;text-align:left;font-size:${S.tableHeaderSize}px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:.5px">Line Item</th><th style="padding:10px 12px;text-align:right;font-size:${S.tableHeaderSize}px;font-weight:900;color:#f59e0b;text-transform:uppercase">${esc(curLabel)}</th><th style="padding:10px 12px;text-align:right;font-size:${S.tableHeaderSize}px;font-weight:900;color:#94a3b8;text-transform:uppercase">${esc(prevLabel)}</th><th style="padding:10px 12px;text-align:right;font-size:${S.tableHeaderSize}px;font-weight:900;color:#fff;text-transform:uppercase">Variance</th><th style="padding:10px 12px;text-align:right;font-size:${S.tableHeaderSize}px;font-weight:900;color:#fff;text-transform:uppercase">% Change</th></tr></thead>
                        <tbody>
                        ${momRow('Total Revenue', pnl.totalRevenue, pm.totalRevenue, true, true)}
                        ${momRow('Cost of Sales', pnl.cos, pm.cos, true, false)}
                        ${momRow('Gross Profit', pnl.grossProfit, pm.grossProfit, true, true)}
                        ${momRow('Operating Expenses', pnl.totalOpex, pm.totalOpex, true, false)}
                        ${momRow('Other Expenses', pnl.totalOther, pm.totalOther, true, false)}
                        ${momRow('Operating Profit', pnl.operatingProfit, pm.operatingProfit, true, true)}
                        ${momRow('Net Profit', pnl.netProfit, pm.netProfit, true, true)}
                        ${momRow('Closing Stock Valuation', pnl.closing, pm.closingStock, true, false)}
                        <tr><td colspan="5" style="padding:6px;background:#f8fafc"></td></tr>
                        ${momRow('Gross Profit Margin', pnl.grossMargin, prevGM, false, false)}
                        ${momRow('Net Profit Margin', pnl.netMargin, prevNM, false, false)}
                        </tbody>
                        </table>
                    </div>
                    <div style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                        <div style="background:${pnl.totalRevenue >= pm.totalRevenue ? 'linear-gradient(135deg,#059669,#34d399)' : 'linear-gradient(135deg,#dc2626,#ef4444)'};border-radius:12px;padding:16px;text-align:center">
                            <p style="font-size:18px;font-weight:900;color:#fff">${pnl.totalRevenue >= pm.totalRevenue ? '▲' : '▼'} ${pm.totalRevenue !== 0 ? (((pnl.totalRevenue - pm.totalRevenue) / Math.abs(pm.totalRevenue)) * 100).toFixed(1) : '—'}%</p>
                            <p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;margin-top:4px">Revenue Change</p>
                        </div>
                        <div style="background:${pnl.netProfit >= pm.netProfit ? 'linear-gradient(135deg,#059669,#34d399)' : 'linear-gradient(135deg,#dc2626,#ef4444)'};border-radius:12px;padding:16px;text-align:center">
                            <p style="font-size:18px;font-weight:900;color:#fff">${pnl.netProfit >= pm.netProfit ? '▲' : '▼'} ${pm.netProfit !== 0 ? (((pnl.netProfit - pm.netProfit) / Math.abs(pm.netProfit)) * 100).toFixed(1) : '—'}%</p>
                            <p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;margin-top:4px">Net Profit Change</p>
                        </div>
                        <div style="background:${pnl.grossMargin >= prevGM ? 'linear-gradient(135deg,#2563eb,#60a5fa)' : 'linear-gradient(135deg,#d97706,#fbbf24)'};border-radius:12px;padding:16px;text-align:center">
                            <p style="font-size:18px;font-weight:900;color:#fff">${(pnl.grossMargin - prevGM).toFixed(1)}pp</p>
                            <p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;margin-top:4px">Margin Shift</p>
                        </div>
                    </div>
                    ${pageFooter(5)}
                </div>`;
            }

            // PAGE 5: STOCK VALUATION (always from LAST period's closing stock)
            const pdfLastPid = (() => { let sp = (this.periods || []).slice().sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0) || (a.date_from || '').localeCompare(b.date_from || '')); return sp.length ? sp[sp.length - 1].id : null; })();
            const pdfClosingSaved = this.allCOS.filter(i => i.entry_type === 'closing' && (pdfLastPid ? i.period_id == pdfLastPid : true));
            const pdfSavedMap = {}; pdfClosingSaved.forEach(item => { pdfSavedMap[item.label] = item; });
            const closingItems = this.stockCatalog.map(cat => {
                let saved = pdfSavedMap[cat.item_name];
                let qty = 0;
                if (saved) {
                    let subs = []; try { subs = typeof saved.sub_entries === 'string' ? JSON.parse(saved.sub_entries) : (saved.sub_entries || []); } catch (e) { }
                    if (subs && subs.length > 0 && subs[0].qty !== undefined) { qty = +subs[0].qty || 0; } else { qty = (+saved.amount || 0) / (+cat.unit_cost || 1); }
                }
                const ps = Math.max(1, +cat.pack_size || 1);
                let packs = 0, pieces = qty;
                if (ps > 1 && qty > 0) { packs = Math.floor(qty / ps); pieces = qty % ps; }
                return { label: cat.item_name, unit_cost: +cat.unit_cost || 0, qty, packs, pieces, pack_size: ps, department: cat.department || '', category: cat.category || '', entry_type: 'closing' };
            });
            const depts = [...new Set(closingItems.map(i => i.department || 'Uncategorized'))];
            let stockHTML = '';
            depts.forEach(dept => {
                const deptItems = closingItems.filter(i => (i.department || 'Uncategorized') === dept);
                const deptTotal = deptItems.reduce((s, i) => s + ((+i.qty || 0) * (+i.unit_cost || 0)), 0);
                stockHTML += `<tr style="background:#f8fafc"><td colspan="6" style="padding:8px 12px;font-weight:900;color:#000;font-size:10px;text-transform:uppercase;letter-spacing:1px;border-bottom:2px solid #e2e8f0">📂 ${esc(dept)}</td></tr>`;
                const cats = [...new Set(deptItems.map(i => i.category || ''))];
                cats.forEach(cat => {
                    if (cat) stockHTML += `<tr><td colspan="6" style="padding:4px 12px 4px 20px;font-size:8px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px">${esc(cat)}</td></tr>`;
                    deptItems.filter(i => (i.category || '') === cat).forEach(i => {
                        const ps = +i.pack_size || 1;
                        const pks = +i.packs || 0;
                        const pcs = +i.pieces || 0;
                        const totalQ = +i.qty || 0;
                        stockHTML += `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 12px 6px 28px;font-weight:600;color:#334155">${esc(i.label)}</td><td style="padding:6px 8px;text-align:right;color:#64748b">${f(i.unit_cost)}</td><td style="padding:6px 8px;text-align:center;color:#92400e;font-weight:600">${ps > 1 ? pks : '—'}</td><td style="padding:6px 8px;text-align:center;color:#1e40af;font-weight:600">${pcs}</td><td style="padding:6px 8px;text-align:center;font-weight:700;color:#334155">${totalQ}</td><td style="padding:6px 12px;text-align:right;font-weight:700;color:#000">${f(totalQ * (+i.unit_cost || 0))}</td></tr>`;
                    });
                });
                stockHTML += `<tr style="border-bottom:2px solid #e2e8f0"><td colspan="5" style="padding:6px 12px;font-weight:800;color:#475569;font-size:10px">Subtotal — ${esc(dept)}</td><td style="padding:6px 12px;text-align:right;font-weight:800;color:#1e293b">${f(deptTotal)}</td></tr>`;
            });
            const page5 = `
            <div class="page-auto">
                ${pageHeader('07', 'Stock Valuation Report')}
                ${closingItems.length > 0 ? `
                <div style="margin-top:8px">
                <table style="width:100%;border-collapse:collapse;font-size:${S.tableBodySize}px">
                <thead><tr style="background:#000"><th style="padding:8px 6px;text-align:left;font-size:${S.tableHeaderSize}px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px">Item</th><th style="padding:8px 6px;text-align:right;font-size:${S.tableHeaderSize}px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px">Unit Cost</th><th style="padding:8px 6px;text-align:center;font-size:${S.tableHeaderSize}px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px">Packs</th><th style="padding:8px 6px;text-align:center;font-size:${S.tableHeaderSize}px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px">Pieces</th><th style="padding:8px 6px;text-align:center;font-size:${S.tableHeaderSize}px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px">Total Qty</th><th style="padding:8px 6px;text-align:right;font-size:${S.tableHeaderSize}px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px">Stock Value</th></tr></thead>
                <tbody>${stockHTML}</tbody>
                <tfoot><tr style="background:#000"><td style="padding:10px 8px;font-weight:900;color:#fff;font-size:10px" colspan="5">Total Closing Stock Valuation</td><td style="padding:10px 8px;text-align:right;font-weight:900;color:#f59e0b;font-size:12px">${f(cosCalc.closingStock)}</td></tr></tfoot>
                </table></div>` : '<div style="padding:40px;text-align:center;color:#94a3b8;border:1px dashed #e2e8f0;border-radius:12px">No stock valuation data available</div>'}
                                <div class="pg-footer-inline"><p style="font-size:${S.footerSize}px;font-weight:900;text-transform:uppercase;letter-spacing:2px">Generated by MIAUDITOPS</p><p style="font-size:${S.footerSize}px">${genDate}</p></div>
            </div>`;

            // PAGE 6: FINANCIAL INFOGRAPHICS
            const rev = pnl.totalRevenue || 0;
            const cosPct = rev > 0 ? (pnl.cos / rev * 100).toFixed(1) : 0;
            const gpPct = rev > 0 ? (Math.abs(pnl.grossProfit) / rev * 100).toFixed(1) : 0;
            const expPct = rev > 0 ? ((pnl.totalOpex + pnl.totalOther) / rev * 100).toFixed(1) : 0;
            const npPct = rev > 0 ? (Math.abs(pnl.netProfit) / rev * 100).toFixed(1) : 0;
            const barStyle = 'height:14px;border-radius:999px;overflow:hidden;background:#f1f5f9';
            const wfBar = (color, pct) => `<div style="${barStyle}"><div style="height:100%;border-radius:999px;background:${color};width:${Math.min(100, pct)}%"></div></div>`;
            const wfRow = (label, val, color, pct, bold) => `<div style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;margin-bottom:2px"><span style="font-size:10px;font-weight:${bold ? 900 : 600};color:${bold ? '#000' : '#475569'}">${label}</span><span style="font-size:10px;font-weight:700;color:${color}">${f(val)}</span></div>${wfBar(color, pct)}</div>`;
            const allocRow = (label, val, color) => {
                const pct = rev > 0 ? (Math.abs(val) / rev * 100).toFixed(1) : 0;
                return `<div style="display:flex;align-items:center;margin-bottom:10px"><span style="font-size:10px;font-weight:600;color:#475569;width:140px">${label}</span><div style="flex:1;margin:0 12px;${barStyle}"><div style="height:100%;border-radius:999px;background:${color};width:${Math.min(100, pct)}%"></div></div><span style="font-size:10px;font-weight:700;color:${color};width:48px;text-align:right">${pct}%</span></div>`;
            };
            const page6 = `
            <div class="page">
                ${pageHeader('04', 'Financial Infographics')}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
                    <div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px">
                        <p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px">Profit Waterfall</p>
                        ${wfRow('Revenue', pnl.totalRevenue, '#059669', 100, false)}
                        ${wfRow('Less: Cost of Sales', pnl.cos, '#ea580c', cosPct, false)}
                        ${wfRow('= Gross Profit', pnl.grossProfit, pnl.grossProfit >= 0 ? '#059669' : '#dc2626', gpPct, false)}
                        ${wfRow('Less: Expenses', pnl.totalOpex + pnl.totalOther, '#2563eb', expPct, false)}
                        <div style="border-top:2px solid #000;padding-top:8px;margin-top:4px">
                        ${wfRow('= Net Profit', pnl.netProfit, pnl.netProfit >= 0 ? '#059669' : '#dc2626', npPct, true)}
                        </div>
                    </div>
                    <div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px">
                        <p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px">Revenue Allocation</p>
                        ${allocRow('Cost of Sales', pnl.cos, '#ea580c')}
                        ${allocRow('Operating Expenses', pnl.totalOpex, '#2563eb')}
                        ${allocRow('Other Expenses', pnl.totalOther, '#7c3aed')}
                        ${allocRow('Net Profit', pnl.netProfit, pnl.netProfit >= 0 ? '#059669' : '#dc2626')}
                    </div>
                </div>
                ${pageFooter(6)}
            </div>`;

            // PAGE 7: CHARTS & ANALYSIS
            const chartColors = ['#059669', '#2563eb', '#ea580c', '#7c3aed', '#0891b2', '#d97706', '#dc2626', '#4f46e5'];
            const buildBarChart = (title, items) => {
                if (!items || items.length === 0) return `<div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px"><p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">${title}</p><p style="color:#94a3b8;font-size:10px;text-align:center;padding:30px 0">No data</p></div>`;
                const total = items.reduce((s, i) => s + Math.abs(i.amount), 0) || 1;
                const maxVal = Math.max(...items.map(i => Math.abs(i.amount)), 1);
                let rows = items.map((item, idx) => {
                    const c = chartColors[idx % chartColors.length];
                    const barW = (Math.abs(item.amount) / maxVal * 100).toFixed(0);
                    const pct = (Math.abs(item.amount) / total * 100).toFixed(1);
                    return `<div style="display:flex;align-items:center;margin-bottom:4px"><span style="font-size:8px;font-weight:600;color:#475569;width:90px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(item.label)}</span><div style="flex:1;margin:0 4px;height:10px;background:#f1f5f9;border-radius:999px;overflow:hidden"><div style="height:100%;border-radius:999px;background:${c};width:${Math.min(100, barW)}%"></div></div><span style="font-size:7px;font-weight:700;color:${c};width:30px;text-align:right">${pct}%</span><span style="font-size:7px;font-weight:700;color:#334155;width:60px;text-align:right">${f(item.amount)}</span></div>`;
                }).join('');
                return `<div style="border:1px solid #e2e8f0;border-radius:10px;padding:14px"><p style="font-size:9px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">${title}</p>${rows}</div>`;
            };
            const buildPieChart = (title, items) => {
                if (!items || items.length === 0) return `<div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px"><p style="font-size:10px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">${title}</p><p style="color:#94a3b8;font-size:10px;text-align:center;padding:30px 0">No data</p></div>`;
                const total = items.reduce((s, i) => s + Math.abs(i.amount), 0) || 1;
                let angle = 0;
                const slices = items.map((item, idx) => {
                    const c = chartColors[idx % chartColors.length];
                    const pct = Math.abs(item.amount) / total * 100;
                    const start = angle;
                    angle += pct;
                    return { color: c, start, end: angle, label: item.label, amount: item.amount, pct };
                });
                const gradient = slices.map(s => `${s.color} ${s.start.toFixed(1)}% ${s.end.toFixed(1)}%`).join(', ');
                const legend = slices.slice(0, 12).map(s => `<div style="display:flex;align-items:center;gap:4px;margin-bottom:2px"><div style="width:7px;height:7px;border-radius:2px;background:${s.color};flex-shrink:0"></div><span style="font-size:7px;font-weight:600;color:#475569;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(s.label)}</span><span style="font-size:7px;font-weight:700;color:${s.color}">${s.pct.toFixed(1)}%</span><span style="font-size:7px;font-weight:600;color:#94a3b8">${f(s.amount)}</span></div>`).join('') + (slices.length > 12 ? `<p style="font-size:7px;color:#94a3b8;margin-top:2px">+${slices.length - 12} more items</p>` : '');
                return `<div style="border:1px solid #e2e8f0;border-radius:10px;padding:14px"><p style="font-size:9px;font-weight:900;color:#000;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">${title}</p><div style="display:flex;gap:12px;align-items:flex-start"><div style="width:90px;height:90px;border-radius:50%;background:conic-gradient(${gradient});flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,0.1)"></div><div style="flex:1;min-width:0;overflow:hidden">${legend}</div></div></div>`;
            };
            const buildChart = this.pnlChartType === 'pie' ? buildPieChart : buildBarChart;
            const chartRev = this.stmtRevenue.map(r => ({ label: r.label, amount: r.amount }));
            const chartExp = [...this.stmtOpex, ...this.stmtOther].map(e => ({ label: e.label, amount: e.amount }));
            const chartCos = this.stmtCOS.map(c => ({ label: c.label, amount: c.amount }));
            const chartComp = [{ label: 'Revenue', amount: pnl.totalRevenue }, { label: 'Cost of Sales', amount: pnl.cos }, { label: 'Operating Expenses', amount: pnl.totalOpex }, { label: 'Other Expenses', amount: pnl.totalOther }, { label: 'Net Profit', amount: pnl.netProfit }];
            const page7 = `
            <div class="page">
                ${pageHeader('05', 'Charts & Analysis')}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    ${buildChart('Revenue Breakdown', chartRev)}
                    ${buildChart('Expense Breakdown', chartExp)}
                    ${buildChart('Cost of Sales Breakdown', chartCos)}
                    ${buildChart('P&L Composition', chartComp)}
                </div>
                ${pageFooter(7)}
            </div>`;

            // PAGE 8: RECOMMENDATION REPORT
            const aiText = this.aiReportText || '';
            const page8 = `
            <div class="page">
                ${pageHeader('06', 'Recommendation Report')}
                <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
                    <div style="padding:12px 20px;background:#000;display:flex;align-items:center;gap:8px"><span style="font-size:10px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:1px">Financial Analysis</span></div>
                    <div style="padding:20px;font-size:11px;line-height:1.7;color:#334155">${aiText || '<span style="color:#94a3b8;font-style:italic">No AI report generated</span>'}</div>
                </div>
                ${pageFooter(8)}
            </div>`;

            // PAGE: WEEKLY PURCHASES BREAKDOWN (by category)
            const purByPeriod = {};
            const purLabels = new Set();
            this.allCOS.filter(i => i.entry_type === 'purchase' && i.label).forEach(i => {
                if (!purByPeriod[i.period_id]) purByPeriod[i.period_id] = {};
                const subs = typeof i.sub_entries === 'string' ? JSON.parse(i.sub_entries || '[]') : (i.sub_entries || []);
                const catTotal = subs.reduce((s, e) => s + (+e.amount || 0), 0) || (+i.amount || 0);
                purByPeriod[i.period_id][i.label] = (purByPeriod[i.period_id][i.label] || 0) + catTotal;
                purLabels.add(i.label);
            });
            const purItems = [...purLabels].map(l => ({ label: l }));
            let pagePurchases = '';
            if (purItems.length > 0) {
                pagePurchases = `
            <div class="page">
                ${pageHeader('', 'Weekly Purchases Breakdown')}
                <div style="margin-bottom:16px">
                    <p style="font-size:10px;color:#64748b;margin-bottom:16px">Purchase categories across all periods for <strong>${esc(clientName)}</strong></p>
                    <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
                    ${buildColumnTable(purByPeriod, purItems, 'Total Purchases', '#ea580c')}
                    </div>
                </div>
                ${pageFooter('')}
            </div>`;
            }

            // PAGE: DEPARTMENT STOCK VALUE ANALYSIS
            let pageStockAnalysis = '';
            const deptStock = this.closingStockByDept;
            if (deptStock.length > 0) {
                let stockRows = '';
                deptStock.forEach(dept => {
                    stockRows += `<tr style="background:#f8fafc;border-top:2px solid #e2e8f0"><td colspan="4" style="padding:10px 8px;font-weight:900;font-size:11px;color:#000">${esc(dept.name)} <span style="font-size:9px;color:#94a3b8;font-weight:600">(${dept.items.length} items)</span></td><td style="padding:10px 8px;text-align:right;font-weight:900;font-size:11px;color:#059669">${f(dept.total)}</td></tr>`;
                    dept.items.forEach(item => {
                        stockRows += `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:5px 8px 5px 24px;color:#334155;font-size:10px">${esc(item.label)}</td><td style="padding:5px 6px;text-align:center;font-size:10px;color:#64748b">${item.qty || '-'}</td><td style="padding:5px 6px;text-align:right;font-size:10px;color:#64748b">${item.unit_cost ? f(item.unit_cost) : '-'}</td><td style="padding:5px 6px;text-align:center;font-size:9px;color:#94a3b8">${item.qty && item.unit_cost ? item.qty + ' × ' + f(item.unit_cost) : ''}</td><td style="padding:5px 8px;text-align:right;font-weight:700;font-size:10px;color:#000">${f(item.value)}</td></tr>`;
                    });
                });
                let grandTotal = deptStock.reduce((s, d) => s + d.total, 0);
                stockRows += `<tr style="background:#000"><td colspan="4" style="padding:14px 8px;font-weight:900;font-size:12px;color:#fff;border-radius:0 0 0 8px">Total Closing Stock Value</td><td style="padding:14px 8px;text-align:right;font-weight:900;font-size:13px;color:#34d399;border-radius:0 0 8px 0">${f(grandTotal)}</td></tr>`;
                pageStockAnalysis = `
            <div class="page-auto">
                ${pageHeader('', 'Department Stock Value Analysis')}
                <p style="font-size:9px;color:#64748b;margin-bottom:12px">Detailed closing stock valuation grouped by department for <strong>${esc(clientName)}</strong></p>
                <table style="width:100%;border-collapse:collapse">
                <thead><tr style="background:#000"><th style="padding:6px 6px;text-align:left;font-weight:900;color:#f59e0b;font-size:8px;text-transform:uppercase;letter-spacing:.5px">Item</th><th style="padding:6px 5px;text-align:center;font-weight:900;color:#f59e0b;font-size:8px;text-transform:uppercase;letter-spacing:.5px">Qty</th><th style="padding:6px 5px;text-align:right;font-weight:900;color:#f59e0b;font-size:8px;text-transform:uppercase;letter-spacing:.5px">Unit Cost</th><th style="padding:6px 5px;text-align:center;font-weight:900;color:#f59e0b;font-size:8px;text-transform:uppercase;letter-spacing:.5px">Calculation</th><th style="padding:6px 6px;text-align:right;font-weight:900;color:#f59e0b;font-size:8px;text-transform:uppercase;letter-spacing:.5px">Value</th></tr></thead>
                <tbody>${stockRows}</tbody>
                </table>
                                <div class="pg-footer-inline"><p style="font-size:${S.footerSize}px;font-weight:900;text-transform:uppercase;letter-spacing:2px">Generated by MIAUDITOPS</p><p style="font-size:${S.footerSize}px">${genDate}</p></div>
            </div>`;
            }

            const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>P&L Statement — ${esc(clientName)}</title><style>${css}</style></head><body>${page1}${page2}${page3}${page4}${pageMoM}${page6}${page7}${page8}${pagePurchases}${page5}${pageStockAnalysis}</body></html>`;

            const pw = window.open('', '_blank');
            if (!pw) { this.toast('Pop-up blocked — please allow pop-ups for this site', 'error'); return; }
            pw.document.write(html);
            pw.document.close();
            pw.onload = () => { pw.focus(); pw.print(); };
        },

        // ── Quarterly / Annual Rollup PDF ──
        async generateRollupPDF() {
            this.rollupLoading = true;
            try {
                let r = await fetch(`../ajax/pnl_api.php?action=get_rollup&period_type=${this.rollupType}&year=${this.rollupYear}`);
                let d = await r.json();
                if (!d.success || !d.has_data) { this.toast('No P&L reports found for ' + this.rollupType + ' ' + this.rollupYear, 'error'); this.rollupLoading = false; return; }

                const f = (n) => '₦' + (+n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                const mn = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const clientName = this.clientName || 'Client';
                const periodLabel = d.period_type === 'annual' ? ('Annual ' + d.year) : (d.period_type + ' ' + d.year);
                const tot = d.totals;
                const months = d.months;

                const css = `@media print{@page{size:A4 portrait;margin:0}}*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Segoe UI',system-ui,sans-serif;background:#fff}.page{width:210mm;min-height:297mm;padding:0;page-break-after:always;position:relative}`;

                // PAGE 1: COVER
                const cover = `<div class="page" style="display:flex;flex-direction:column;justify-content:center;align-items:center;background:linear-gradient(135deg,#000 0%,#1e293b 100%)">
                    <div style="text-align:center;padding:60px">
                        <p style="font-size:10px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:4px;margin-bottom:20px">Miauditops</p>
                        <h1 style="font-size:32px;font-weight:900;color:#fff;margin-bottom:8px">P&L Rollup Report</h1>
                        <p style="font-size:16px;color:#94a3b8;font-weight:600">${esc(periodLabel)}</p>
                        <div style="width:80px;height:3px;background:#f59e0b;margin:24px auto"></div>
                        <p style="font-size:14px;color:#e2e8f0;font-weight:700">${esc(clientName)}</p>
                        <p style="font-size:10px;color:#64748b;margin-top:12px">${months.length} monthly report${months.length !== 1 ? 's' : ''} aggregated</p>
                    </div>
                </div>`;

                // PAGE 2: AGGREGATED P&L SUMMARY
                const summaryRow = (label, val, indent, bold, color) => {
                    const pl = indent ? 'padding-left:24px;' : '';
                    const fw = bold ? 'font-weight:900;' : 'font-weight:600;';
                    const fc = color || '#1e293b';
                    const bg = bold ? 'background:#f8fafc;' : '';
                    return `<tr style="border-bottom:1px solid #f1f5f9;${bg}"><td style="padding:10px 16px;${pl}${fw}color:${fc};font-size:11px">${label}</td><td style="padding:10px 16px;text-align:right;${fw}color:${fc};font-size:12px">${f(val)}</td></tr>`;
                };
                const page2 = `<div class="page">
                    <div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center"><p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">01. Aggregated P&L Summary</p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)">${esc(clientName)} — ${esc(periodLabel)}</p></div>
                    <div style="padding:32px 40px">
                        <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
                        <table style="width:100%;border-collapse:collapse">
                        ${summaryRow('Total Revenue', tot.totalRevenue, false, true, '#059669')}
                        ${summaryRow('Less: Cost of Sales', tot.cos, true, false, '#dc2626')}
                        ${summaryRow('Gross Profit', tot.grossProfit, false, true)}
                        ${summaryRow('Less: Operating Expenses', tot.totalOpex, true, false, '#ea580c')}
                        ${summaryRow('Operating Profit', tot.operatingProfit, false, true)}
                        ${summaryRow('Less: Other Expenses', tot.totalOther, true, false, '#7c3aed')}
                        ${summaryRow('Net Profit / (Loss)', tot.netProfit, false, true, tot.netProfit >= 0 ? '#059669' : '#dc2626')}
                        </table>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;margin-top:24px">
                            <div style="background:#000;border-radius:12px;padding:16px;text-align:center"><p style="font-size:18px;font-weight:900;color:#f59e0b">${f(tot.totalRevenue)}</p><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;margin-top:4px">Total Revenue</p></div>
                            <div style="background:#000;border-radius:12px;padding:16px;text-align:center"><p style="font-size:18px;font-weight:900;color:${tot.grossProfit >= 0 ? '#34d399' : '#ef4444'}">${f(tot.grossProfit)}</p><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;margin-top:4px">Gross Profit</p></div>
                            <div style="background:#000;border-radius:12px;padding:16px;text-align:center"><p style="font-size:18px;font-weight:900;color:#22d3ee">${(tot.totalRevenue > 0 ? (tot.grossProfit / tot.totalRevenue * 100) : 0).toFixed(1)}%</p><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;margin-top:4px">Gross Margin</p></div>
                            <div style="background:#000;border-radius:12px;padding:16px;text-align:center"><p style="font-size:18px;font-weight:900;color:${tot.netProfit >= 0 ? '#059669' : '#ef4444'}">${f(tot.netProfit)}</p><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;margin-top:4px">Net Profit</p></div>
                        </div>
                    </div>
                    <div style="background:#000;padding:8px 40px;text-align:center;position:absolute;bottom:0;left:0;right:0"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Page 2</p></div>
                </div>`;

                // PAGE 3: MONTH-BY-MONTH BREAKDOWN
                let mbRows = '';
                months.forEach(m => {
                    mbRows += `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 12px;font-weight:700;font-size:10px;color:#1e293b">${mn[m.month - 1]} ${m.year}</td><td style="padding:8px 12px;text-align:right;font-size:10px;font-weight:600">${f(m.totalRevenue)}</td><td style="padding:8px 12px;text-align:right;font-size:10px;font-weight:600;color:#dc2626">${f(m.cos)}</td><td style="padding:8px 12px;text-align:right;font-size:10px;font-weight:700">${f(m.grossProfit)}</td><td style="padding:8px 12px;text-align:right;font-size:10px;font-weight:600;color:#ea580c">${f(m.totalOpex)}</td><td style="padding:8px 12px;text-align:right;font-size:10px;font-weight:600;color:#7c3aed">${f(m.totalOther)}</td><td style="padding:8px 12px;text-align:right;font-size:10px;font-weight:900;color:${m.netProfit >= 0 ? '#059669' : '#dc2626'}">${f(m.netProfit)}</td></tr>`;
                });
                const page3 = `<div class="page">
                    <div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center"><p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">02. Month-by-Month Breakdown</p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)">${esc(clientName)} — ${esc(periodLabel)}</p></div>
                    <div style="padding:32px 40px">
                        <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
                        <table style="width:100%;border-collapse:collapse">
                        <thead><tr style="background:#000"><th style="padding:10px 12px;text-align:left;font-size:8px;font-weight:900;color:#fff;text-transform:uppercase">Month</th><th style="padding:10px 12px;text-align:right;font-size:8px;font-weight:900;color:#f59e0b;text-transform:uppercase">Revenue</th><th style="padding:10px 12px;text-align:right;font-size:8px;font-weight:900;color:#fff;text-transform:uppercase">COS</th><th style="padding:10px 12px;text-align:right;font-size:8px;font-weight:900;color:#fff;text-transform:uppercase">Gross Profit</th><th style="padding:10px 12px;text-align:right;font-size:8px;font-weight:900;color:#fff;text-transform:uppercase">OpEx</th><th style="padding:10px 12px;text-align:right;font-size:8px;font-weight:900;color:#fff;text-transform:uppercase">Other</th><th style="padding:10px 12px;text-align:right;font-size:8px;font-weight:900;color:#fff;text-transform:uppercase">Net Profit</th></tr></thead>
                        <tbody>${mbRows}
                        <tr style="background:#f8fafc;border-top:2px solid #000"><td style="padding:10px 12px;font-weight:900;font-size:10px;color:#000">TOTAL</td><td style="padding:10px 12px;text-align:right;font-size:10px;font-weight:900">${f(tot.totalRevenue)}</td><td style="padding:10px 12px;text-align:right;font-size:10px;font-weight:900;color:#dc2626">${f(tot.cos)}</td><td style="padding:10px 12px;text-align:right;font-size:10px;font-weight:900">${f(tot.grossProfit)}</td><td style="padding:10px 12px;text-align:right;font-size:10px;font-weight:900;color:#ea580c">${f(tot.totalOpex)}</td><td style="padding:10px 12px;text-align:right;font-size:10px;font-weight:900;color:#7c3aed">${f(tot.totalOther)}</td><td style="padding:10px 12px;text-align:right;font-size:10px;font-weight:900;color:${tot.netProfit >= 0 ? '#059669' : '#dc2626'}">${f(tot.netProfit)}</td></tr>
                        </tbody></table>
                        </div>
                    </div>
                    <div style="background:#000;padding:8px 40px;text-align:center;position:absolute;bottom:0;left:0;right:0"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Page 3</p></div>
                </div>`;

                // PAGE 4: AGGREGATED RATIOS
                const gm = tot.totalRevenue > 0 ? (tot.grossProfit / tot.totalRevenue * 100) : 0;
                const nm = tot.totalRevenue > 0 ? (tot.netProfit / tot.totalRevenue * 100) : 0;
                const cr = tot.totalRevenue > 0 ? (tot.cos / tot.totalRevenue * 100) : 0;
                const er = tot.totalRevenue > 0 ? ((tot.totalOpex + tot.totalOther) / tot.totalRevenue * 100) : 0;
                const opm = tot.totalRevenue > 0 ? (tot.operatingProfit / tot.totalRevenue * 100) : 0;
                const be = gm > 0 ? ((tot.totalOpex + tot.totalOther) / (gm / 100)) : 0;
                const ratRow = (label, val, unit, color, desc) => {
                    const bw = Math.min(100, Math.abs(val));
                    return `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:10px 12px"><span style="font-size:11px;font-weight:700;color:#1e293b">${label}</span><br><span style="font-size:8px;color:#94a3b8">${desc}</span></td><td style="padding:10px 12px;text-align:right"><span style="font-size:14px;font-weight:900;color:${color}">${val.toFixed(1)}${unit}</span></td><td style="padding:10px 12px;width:120px"><div style="height:8px;background:#f1f5f9;border-radius:999px;overflow:hidden"><div style="height:100%;border-radius:999px;background:${color};width:${bw}%"></div></div></td></tr>`;
                };
                const page4 = `<div class="page">
                    <div style="background:#000;padding:16px 40px;display:flex;justify-content:space-between;align-items:center"><p style="font-size:10px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:2px">03. Financial Ratios</p><p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.4)">${esc(clientName)} — ${esc(periodLabel)}</p></div>
                    <div style="padding:32px 40px">
                        <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:24px">
                        <div style="background:#000;padding:10px 16px"><p style="font-size:9px;font-weight:900;color:#f59e0b;text-transform:uppercase;letter-spacing:1px">${esc(periodLabel)} Financial Ratios</p></div>
                        <table style="width:100%;border-collapse:collapse">
                        <thead><tr style="background:#f8fafc"><th style="padding:8px 12px;text-align:left;font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase">Ratio</th><th style="padding:8px 12px;text-align:right;font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase">Value</th><th style="padding:8px 12px;font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase">Indicator</th></tr></thead>
                        <tbody>
                        ${ratRow('Gross Profit Margin', gm, '%', '#059669', 'Revenue retained after COS')}
                        ${ratRow('Net Profit Margin', nm, '%', nm >= 0 ? '#2563eb' : '#dc2626', 'Revenue retained after all expenses')}
                        ${ratRow('Cost-to-Revenue Ratio', cr, '%', '#ea580c', 'COS as % of revenue')}
                        ${ratRow('Expense Ratio', er, '%', '#7c3aed', 'Total expenses as % of revenue')}
                        ${ratRow('Operating Profit Margin', opm, '%', opm >= 0 ? '#0891b2' : '#dc2626', 'Core operations profitability')}
                        </tbody></table>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div style="background:linear-gradient(135deg,#000,#1e293b);border-radius:12px;padding:20px;text-align:center"><p style="font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Break-even Revenue</p><p style="font-size:20px;font-weight:900;color:#f59e0b">${f(be)}</p><p style="font-size:8px;color:#64748b;margin-top:4px">Min revenue needed (${esc(periodLabel)})</p></div>
                            <div style="background:linear-gradient(135deg,#000,#1e293b);border-radius:12px;padding:20px;text-align:center"><p style="font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Avg Monthly Revenue</p><p style="font-size:20px;font-weight:900;color:#22d3ee">${f(tot.totalRevenue / (months.length || 1))}</p><p style="font-size:8px;color:#64748b;margin-top:4px">Across ${months.length} month${months.length !== 1 ? 's' : ''}</p></div>
                        </div>
                    </div>
                    <div style="background:#000;padding:8px 40px;text-align:center;position:absolute;bottom:0;left:0;right:0"><p style="font-size:8px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:2px">Miauditops — Page 4</p></div>
                </div>`;

                const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>P&L Rollup — ${esc(clientName)} — ${esc(periodLabel)}</title><style>${css}</style></head><body>${cover}${page2}${page3}${page4}</body></html>`;
                const pw = window.open('', '_blank');
                if (!pw) { this.toast('Pop-up blocked', 'error'); this.rollupLoading = false; return; }
                pw.document.write(html); pw.document.close();
                pw.onload = () => { pw.focus(); pw.print(); };
                this.rollupLoading = false;
            } catch (e) { this.toast('Rollup failed: ' + e.message, 'error'); this.rollupLoading = false; }
        }
    }
}
