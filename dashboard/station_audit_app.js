/**
 * MIAUDITOPS — Station Audit Alpine.js App
 */
function stationAudit() {
    return {
        // Data from PHP (injected via inline script)
        outlets: window.__SA_OUTLETS || [],
        sessions: window.__SA_SESSIONS || [],
        sessionFilterYear: '',
        sessionFilterQuarter: '',
        trashItems: [],
        showTrash: false,
        get sessionYears() {
            const years = [...new Set(this.sessions.map(s => s.date_from?.slice(0, 4)).filter(Boolean))];
            return years.sort().reverse();
        },
        get filteredSessions() {
            return this.sessions.filter(s => {
                if (this.sessionFilterYear && s.date_from?.slice(0, 4) !== this.sessionFilterYear) return false;
                if (this.sessionFilterQuarter) {
                    const m = parseInt(s.date_from?.slice(5, 7) || 0);
                    const q = Math.ceil(m / 3);
                    if (q !== parseInt(this.sessionFilterQuarter)) return false;
                }
                return true;
            });
        },
        products: ['PMS', 'AGO', 'DPK', 'LPG'],
        tabs: [
            { id: 'system_sales', label: 'System Sales', icon: 'credit-card', activeClass: 'bg-blue-100 text-blue-700 border-blue-300 dark:bg-blue-900/30 dark:text-blue-300' },
            { id: 'pump_sales', label: 'Pump Sales', icon: 'fuel', activeClass: 'bg-orange-100 text-orange-700 border-orange-300 dark:bg-orange-900/30 dark:text-orange-300' },
            { id: 'tank_dipping', label: 'Tank Dipping', icon: 'gauge', activeClass: 'bg-teal-100 text-teal-700 border-teal-300 dark:bg-teal-900/30 dark:text-teal-300' },
            { id: 'haulage', label: 'Haulage', icon: 'truck', activeClass: 'bg-indigo-100 text-indigo-700 border-indigo-300 dark:bg-indigo-900/30 dark:text-indigo-300' },
            { id: 'lubricants', label: 'Lubricants', icon: 'droplets', activeClass: 'bg-lime-100 text-lime-700 border-lime-300 dark:bg-lime-900/30 dark:text-lime-300' },
            { id: 'expenses', label: 'Expenses', icon: 'receipt', activeClass: 'bg-rose-100 text-rose-700 border-rose-300 dark:bg-rose-900/30 dark:text-rose-300' },
            { id: 'debtors', label: 'Debtors', icon: 'users', activeClass: 'bg-amber-100 text-amber-700 border-amber-300 dark:bg-amber-900/30 dark:text-amber-300' },
            { id: 'documents', label: 'Documents', icon: 'folder-open', activeClass: 'bg-cyan-100 text-cyan-700 border-cyan-300 dark:bg-cyan-900/30 dark:text-cyan-300' },
            { id: 'report', label: 'Report', icon: 'file-bar-chart', activeClass: 'bg-violet-100 text-violet-700 border-violet-300 dark:bg-violet-900/30 dark:text-violet-300' },
            { id: 'final_report', label: 'Final Report', icon: 'file-text', activeClass: 'bg-slate-100 text-slate-800 border-slate-400 dark:bg-slate-800/50 dark:text-slate-200' },
        ],

        // State
        currentTab: localStorage.getItem('sa_currentTab') || 'system_sales',
        activeSession: null,
        sessionData: null,
        selectedProduct: 'PMS',
        saving: false,
        signoffComments: '',
        showDenom: false,
        showPosTerminals: false,
        showTransferTerminals: false,

        // Denominations (Nigerian Naira)
        denominations: [
            { value: 1000, count: 0 }, { value: 500, count: 0 }, { value: 200, count: 0 }, { value: 100, count: 0 },
            { value: 50, count: 0 }, { value: 20, count: 0 }, { value: 10, count: 0 }, { value: 5, count: 0 }
        ],

        // Terminal breakdowns
        posTerminals: [],
        transferTerminals: [],

        // Form Models
        newSession: { outlet_id: '', date_from: new Date().toISOString().slice(0, 10), date_to: new Date().toISOString().slice(0, 10) },
        systemSales: { pos_amount: 0, cash_amount: 0, transfer_amount: 0, teller_amount: 0, notes: '', teller_proof_url: '', pos_proof_url: '', denomination_json: '', pos_terminals_json: '', transfer_terminals_json: '' },
        pumpTables: [],
        haulage: [],
        expenseCategories: [],
        activeExpenseCatId: null,
        newExpenseCatName: '',
        newExpenseLedgerEntry: { entry_date: new Date().toISOString().slice(0, 10), description: '', debit: 0, credit: 0, payment_method: 'cash' },
        debtorAccounts: [],
        activeDebtorId: null,
        newDebtorName: '',
        newLedgerEntry: { entry_date: new Date().toISOString().slice(0, 10), description: '', debit: 0, credit: 0 },
        lubeSections: [],

        // Report cover page (editable)
        reportCover: {
            title: 'Station Audit Close-Out Report',
            preparedBy: '',
            reviewedBy: '',
            reportingPeriod: '',
            notes: ''
        },
        finalReportCover: {
            title: 'RECONCILIATION REPORT',
            subtitle: 'Audit Close-Out',
            preparedFor: 'Operations Department',
            preparedBy: '',
            notes: ''
        },
        finalReportIncludePhotos: true,
        lubeStoreItems: [],
        lubeIssues: [],       // [{store_item_id, section_id, quantity}]
        lubeIssueLog: [],     // [{product_name, counter_name, quantity, created_at}]
        lubeSubTab: localStorage.getItem('sa_lubeSubTab') || 'products',  // 'products' | 'grn' | 'suppliers' | 'store' | 'counters'
        lubeIssueModal: false,
        lubeIssueForm: { store_item_id: null, store_item_name: '', section_id: '', quantity: 0 },

        // Document Storage
        documents: [],
        docStorage: { used: 0, limit: 1073741824, count: 0, percent: 0 },
        docUploading: false,
        docFilter: 'current', // 'current' | 'all'
        docEditingId: null,
        docEditLabel: '',

        // Product catalog
        lubeProducts: [],
        lubeProductForm: { id: 0, product_name: '', unit: 'Litre', cost_price: 0, selling_price: 0, reorder_level: 0 },
        lubeProductModal: false,

        // Suppliers
        lubeSuppliers: [],
        lubeSupplierForm: { id: 0, supplier_name: '', contact_person: '', phone: '', email: '', address: '' },
        lubeSupplierModal: false,

        // GRN
        lubeGrns: [],
        lubeGrnForm: { id: 0, supplier_id: '', grn_number: '', grn_date: new Date().toISOString().slice(0, 10), invoice_number: '', notes: '', items: [] },
        lubeGrnModal: false,
        lubeGrnLoaded: false,

        // Stock Counts
        lubeStockCounts: [],
        lubeStockCountForm: { id: 0, date_from: new Date().toISOString().slice(0, 10), date_to: new Date().toISOString().slice(0, 10), notes: '', items: [] },
        lubeStockCountModal: false,
        lubeStockCountView: null, // for viewing a saved count

        // Counter Stock Counts (per section)
        counterStockCounts: {},  // keyed by section_id
        counterStockCountForm: { id: 0, section_id: 0, date_from: new Date().toISOString().slice(0, 10), date_to: new Date().toISOString().slice(0, 10), notes: '', items: [] },
        counterStockCountModal: false,
        counterStockCountView: null,

        // ── Lifecycle ──
        init() {
            this.$watch('currentTab', val => localStorage.setItem('sa_currentTab', val));
            this.$watch('lubeSubTab', val => localStorage.setItem('sa_lubeSubTab', val));
        },

        // Computed
        get systemSalesTotal() {
            return (parseFloat(this.systemSales.pos_amount) || 0) + (parseFloat(this.systemSales.cash_amount) || 0) + (parseFloat(this.systemSales.transfer_amount) || 0) + (parseFloat(this.systemSales.teller_amount) || 0);
        },
        get denominationTotal() {
            return this.denominations.reduce((sum, d) => sum + (d.value * (d.count || 0)), 0);
        },
        get posTerminalsTotal() {
            return this.posTerminals.reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);
        },
        get transferTerminalsTotal() {
            return this.transferTerminals.reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);
        },
        get filteredPumpTables() {
            return this.pumpTables.filter(pt => pt.product === this.selectedProduct);
        },
        get totalPumpSales() {
            return this.pumpTables.reduce((sum, pt) => sum + this.pumpTableAmount(pt), 0);
        },
        get totalTankDiff() {
            return this.tankProductTotals.reduce((sum, t) => sum + t.diff, 0);
        },
        get reportVariance() {
            return this.systemSalesTotal - this.totalPumpSales;
        },
        get tankProductTotals() {
            // Group pump tables by product, ordered by sort_order
            const productPts = {};
            this.pumpTables.forEach(pt => {
                const prod = pt.product || 'PMS';
                if (!productPts[prod]) productPts[prod] = [];
                productPts[prod].push(pt);
            });

            const results = {};
            Object.entries(productPts).forEach(([prod, pts]) => {
                // Sort by sort_order to determine first and last
                const sorted = [...pts].sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0));
                const firstPt = sorted[0];
                const lastPt = sorted[sorted.length - 1];

                const isLpg = this.isLPG(prod);

                let opening, added, closing;

                if (isLpg) {
                    // For LPG: convert % readings to kg using capacity_kg per tank
                    opening = (firstPt.tanks || []).reduce((s, t) => s + this.lpgKgOpen(t), 0);
                    added = sorted.reduce((s, pt) => (pt.tanks || []).reduce((s2, t) => s2 + this.lpgKgAdded(t), s), 0);
                    closing = (lastPt.tanks || []).reduce((s, t) => s + this.lpgKgClose(t), 0);
                } else {
                    // For PMS/AGO/DPK etc: raw litre values
                    opening = (firstPt.tanks || []).reduce((s, t) => s + parseFloat(t.opening || 0), 0);
                    added = sorted.reduce((s, pt) => (pt.tanks || []).reduce((s2, t) => s2 + parseFloat(t.added || 0), s), 0);
                    closing = (lastPt.tanks || []).reduce((s, t) => s + parseFloat(t.closing || 0), 0);
                }

                results[prod] = {
                    product: prod,
                    isLpg: isLpg,
                    opening: opening,
                    added: added,
                    closing: closing,
                    diff: opening + added - closing
                };
            });
            return Object.values(results);
        },
        get productComparison() {
            const result = [];
            this.products.forEach(p => {
                const pumpLitres = this.pumpTables.filter(pt => pt.product === p).reduce((sum, pt) => sum + this.pumpTableLitres(pt), 0);
                const pumpAmount = this.pumpTables.filter(pt => pt.product === p).reduce((sum, pt) => sum + this.pumpTableAmount(pt), 0);
                const tankDiff = this.pumpTables.filter(pt => pt.product === p).reduce((sum, pt) => (pt.tanks || []).reduce((s, t) => s + (parseFloat(t.opening || 0) + parseFloat(t.added || 0) - parseFloat(t.closing || 0)), sum), 0);
                if (pumpLitres > 0 || tankDiff > 0) {
                    result.push({ product: p, pumpLitres, pumpAmount, tankDiff, variance: tankDiff - pumpLitres });
                }
            });
            return result;
        },
        get pumpSalesGrouped() {
            const groups = {};
            this.pumpTables.forEach(pt => {
                const litres = this.pumpTableLitres(pt);
                const amount = this.pumpTableAmount(pt);
                if (!groups[pt.product]) groups[pt.product] = { product: pt.product, rows: [], totalLitres: 0, totalAmount: 0 };
                groups[pt.product].rows.push({
                    id: pt.id, rate: parseFloat(pt.rate) || 0,
                    dateFrom: pt.date_from || '', dateTo: pt.date_to || '',
                    litres, amount
                });
                groups[pt.product].totalLitres += litres;
                groups[pt.product].totalAmount += amount;
            });
            // Flatten into a single array with type markers for easy template rendering
            const result = [];
            Object.values(groups).filter(g => g.totalLitres > 0 || g.totalAmount > 0).forEach(g => {
                g.rows.forEach((r, i) => {
                    result.push({ type: 'row', product: i === 0 ? g.product : '', ...r });
                });
                result.push({ type: 'subtotal', product: g.product, totalLitres: g.totalLitres, totalAmount: g.totalAmount, id: 'sub_' + g.product });
            });
            return result;
        },
        tanksForProduct(product) {
            const names = new Set();
            this.pumpTables.filter(pt => pt.product === product).forEach(pt => {
                (pt.tanks || []).forEach(t => { if (t.tank_name) names.add(t.tank_name); });
            });
            return [...names].sort();
        },

        // Find the pump table period that covers the given delivery date for a product.
        // Returns the matching pump table object, or null if none found.
        pumpTableForDelivery(product, date) {
            if (!product || !date) return null;
            const d = date.slice(0, 10);
            return this.pumpTables.find(pt =>
                pt.product === product &&
                (pt.date_from || '') <= d &&
                (pt.date_to || '') >= d
            ) || null;
        },
        get totalHaulageQty() {
            return this.haulage.reduce((sum, h) => sum + (parseFloat(h.quantity) || 0), 0);
        },
        get totalExpenses() {
            return this.expenseCategories.reduce((sum, c) => {
                return sum + (c.ledger || []).reduce((s, e) => s + (parseFloat(e.debit) || 0) - (parseFloat(e.credit) || 0), 0);
            }, 0);
        },
        expenseCatBalance(cat) {
            return (cat.ledger || []).reduce((s, e) => s + (parseFloat(e.debit) || 0) - (parseFloat(e.credit) || 0), 0);
        },
        get activeExpenseCat() {
            return this.expenseCategories.find(c => c.id == this.activeExpenseCatId) || null;
        },
        get totalDebtors() {
            return this.debtorAccounts.reduce((sum, a) => {
                const dr = (a.ledger || []).reduce((s, e) => s + (parseFloat(e.debit) || 0), 0);
                const cr = (a.ledger || []).reduce((s, e) => s + (parseFloat(e.credit) || 0), 0);
                return sum + (dr - cr);
            }, 0);
        },
        debtorBalance(acct) {
            const dr = (acct.ledger || []).reduce((s, e) => s + (parseFloat(e.debit) || 0), 0);
            const cr = (acct.ledger || []).reduce((s, e) => s + (parseFloat(e.credit) || 0), 0);
            return dr - cr;
        },
        get activeDebtor() {
            return this.debtorAccounts.find(a => a.id == this.activeDebtorId) || null;
        },
        get haulageByProduct() {
            const map = {};
            this.haulage.forEach(h => {
                const p = h.product || 'PMS';
                if (!map[p]) map[p] = { product: p, quantity: 0, waybill_qty: 0, count: 0 };
                map[p].quantity += parseFloat(h.quantity) || 0;
                map[p].waybill_qty += parseFloat(h.waybill_qty) || 0;
                map[p].count++;
            });
            return Object.values(map);
        },
        get totalPumpLitres() {
            return this.pumpTables.reduce((sum, pt) => sum + this.pumpTableLitres(pt), 0);
        },
        get lubeStoreTotalValue() {
            return this.lubeStoreItems.reduce((sum, si) => sum + (this.storeItemClosing(si) * (parseFloat(si.selling_price) || 0)), 0);
        },
        get monthCloseout() {
            const systemSales = this.totalPumpSales;
            const bankDeposit = parseFloat(this.systemSales.teller_amount) || 0;
            const totalBalance = systemSales - bankDeposit;

            // Expenses by category
            const expenseLines = this.expenseCategories.map(cat => ({
                name: cat.category_name || cat.name || 'Uncategorised',
                amount: this.expenseCatBalance(cat)
            })).filter(e => e.amount !== 0);
            const totalExpenses = expenseLines.reduce((s, e) => s + e.amount, 0);

            const posTransferSales = (parseFloat(this.systemSales.pos_amount) || 0) + (parseFloat(this.systemSales.transfer_amount) || 0);
            const cashAtHand = parseFloat(this.systemSales.cash_amount) || 0;
            const lubeUnsold = this.lubeStoreTotalValue;
            const receivables = this.totalDebtors;

            const expectedTotal = totalExpenses + posTransferSales + cashAtHand + lubeUnsold + receivables;
            const surplus = expectedTotal - totalBalance;

            return {
                systemSales, bankDeposit, totalBalance,
                expenseLines, totalExpenses,
                posTransferSales, cashAtHand, lubeUnsold, receivables,
                expectedTotal, surplus
            };
        },

        // Helpers
        fmt(n) { return (window.__NAIRA || '\u20A6') + (parseFloat(n) || 0).toLocaleString('en', { minimumFractionDigits: 2 }); },
        toTitleCase(str) { return str.replace(/\b\w/g, c => c.toUpperCase()); },

        // ── LPG helpers ────────────────────────────────────────────────────
        // Detect LPG product by name (case-insensitive)
        isLPG(product) { return /lpg|gas/i.test(product || ''); },

        // Convert stored % gauge reading → kg using configuredPercentage pattern
        // Formula: (gaugeReading% / maxFillPercent%) × capacity_kg
        lpgKgOpen(tank) {
            const mf = parseFloat(tank.max_fill_percent || 100);
            return (parseFloat(tank.opening || 0) / mf) * parseFloat(tank.capacity_kg || 0);
        },
        lpgKgClose(tank) {
            const mf = parseFloat(tank.max_fill_percent || 100);
            return (parseFloat(tank.closing || 0) / mf) * parseFloat(tank.capacity_kg || 0);
        },
        // Convert stored tons → kg  (delivery is entered in MT)
        lpgKgAdded(tank) { return parseFloat(tank.added || 0) * 1000; },
        // Full diff in kg for one LPG tank
        lpgKgDiff(tank) { return this.lpgKgOpen(tank) + this.lpgKgAdded(tank) - this.lpgKgClose(tank); },

        // ── LPG Discharge (truck gauge reading → kg delivered) ────────────
        // truck: { open_pct, close_pct, capacity_kg, max_fill_percent }
        lpgDischargeKg(truck) {
            const mf = parseFloat(truck.max_fill_percent || 100);
            const cap = parseFloat(truck.capacity_kg || 0);
            const openKg = (parseFloat(truck.open_pct || 0) / mf) * cap;
            const closeKg = (parseFloat(truck.close_pct || 0) / mf) * cap;
            return closeKg - openKg;   // discharge INCREASES the tank, so after > before
        },
        // ──────────────────────────────────────────────────────────────────
        pumpTableLitres(pt) {
            return (pt.readings || []).reduce((sum, r) => sum + ((r.closing || 0) - (r.opening || 0) - (r.rtt || 0)), 0);
        },
        pumpTableAmount(pt) {
            return this.pumpTableLitres(pt) * parseFloat(pt.rate || 0);
        },
        calcDenomination() {
            this.systemSales.cash_amount = this.denominationTotal;
        },
        calcPosTerminals() {
            this.systemSales.pos_amount = this.posTerminalsTotal;
        },
        calcTransferTerminals() {
            this.systemSales.transfer_amount = this.transferTerminalsTotal;
        },
        async uploadTellerProof(event) {
            const file = event.target.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('action', 'upload_teller_proof');
            fd.append('session_id', this.activeSession);
            fd.append('file', file);
            const res = await fetch('../ajax/station_audit_api.php', { method: 'POST', body: fd });
            const r = await res.json();
            if (r.success) {
                this.systemSales.teller_proof_url = r.url;
                this.toast('Deposit slip uploaded');
                this.$nextTick(() => lucide.createIcons());
            } else { this.toast(r.message || 'Upload failed', false); }
        },
        async uploadPosProof(event) {
            const file = event.target.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('action', 'upload_pos_proof');
            fd.append('session_id', this.activeSession);
            fd.append('file', file);
            const res = await fetch('../ajax/station_audit_api.php', { method: 'POST', body: fd });
            const r = await res.json();
            if (r.success) {
                this.systemSales.pos_proof_url = r.url;
                this.toast('POS slip uploaded');
                this.$nextTick(() => lucide.createIcons());
            } else { this.toast(r.message || 'Upload failed', false); }
        },
        toast(msg, ok = true) {
            const t = document.createElement('div');
            t.className = 'fixed bottom-6 right-6 z-50 px-6 py-3 rounded-xl text-sm font-bold text-white shadow-xl transition-opacity';
            t.style.background = ok ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#ef4444,#dc2626)';
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
        },
        async api(action, data = {}, method = 'POST') {
            const fd = new FormData();
            fd.append('action', action);
            Object.entries(data).forEach(([k, v]) => fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v));
            const res = await fetch('../ajax/station_audit_api.php', { method, body: fd });
            return res.json();
        },

        // Actions
        async createSession() {
            this.saving = true;
            const r = await this.api('create_session', this.newSession);
            this.saving = false;
            if (r.success) {
                this.toast('Audit session created');
                await this.loadSession(r.session_id);
            } else { this.toast(r.message, false); }
        },
        async updateSessionDates() {
            const s = this.sessionData?.session;
            if (!s) return;
            const r = await this.api('update_session_dates', {
                session_id: this.activeSession,
                date_from: s.date_from,
                date_to: s.date_to
            });
            if (r.success) this.toast('Session dates updated');
            else this.toast(r.message, false);
        },
        async saveOutletTerminals(type) {
            const outletId = this.sessionData?.session?.outlet_id;
            if (!outletId) return;
            const names = (type === 'pos' ? this.posTerminals : this.transferTerminals).map(t => t.name);
            const r = await this.api('save_outlet_terminals', {
                outlet_id: outletId,
                terminal_type: type,
                terminal_names: JSON.stringify(names)
            });
            if (r.success) this.toast(`${type.toUpperCase()} terminals saved to outlet`);
            else this.toast(r.message, false);
        },
        async deleteSession(sessionId, outletName) {
            console.log('deleteSession called', sessionId, outletName);
            if (!confirm(`Delete audit session for "${outletName || 'this station'}"?\n\nThis will permanently remove ALL data (sales, expenses, debtors, etc.) for this session.`)) return;
            try {
                const r = await this.api('delete_session', { session_id: sessionId });
                console.log('deleteSession response', r);
                if (r.success) {
                    this.sessions = this.sessions.filter(s => s.id != sessionId);
                    if (this.activeSession == sessionId) { this.activeSession = null; this.sessionData = null; this._updateHash(); }
                    this.toast('Audit session deleted');
                } else { this.toast(r.message || 'Failed to delete session', false); }
            } catch (err) {
                console.error('deleteSession error', err);
                this.toast('Error deleting session: ' + err.message, false);
            }
        },
        async loadTrash() {
            try {
                const r = await this.api('list_trash', { item_type: 'audit_session' });
                if (r.success) this.trashItems = r.items || [];
            } catch (e) { console.error('loadTrash error', e); }
        },
        async restoreSession(trashId) {
            if (!confirm('Restore this audit session? All its data will be recovered.')) return;
            try {
                const r = await this.api('restore_session', { trash_id: trashId });
                if (r.success) {
                    this.toast('Session restored successfully');
                    this.trashItems = this.trashItems.filter(t => t.id != trashId);
                    location.reload();
                } else { this.toast(r.message || 'Restore failed', false); }
            } catch (e) { console.error('restoreSession error', e); this.toast('Error restoring session', false); }
        },
        async permanentDeleteTrash(trashId) {
            if (!confirm('Permanently delete this item? This cannot be undone.')) return;
            try {
                const r = await this.api('permanent_delete_trash', { trash_id: trashId });
                if (r.success) {
                    this.trashItems = this.trashItems.filter(t => t.id != trashId);
                    this.toast('Permanently deleted');
                } else { this.toast(r.message || 'Delete failed', false); }
            } catch (e) { console.error('permanentDeleteTrash error', e); }
        },
        async loadSession(id) {
            const fd = new FormData();
            fd.append('action', 'get_session_data');
            fd.append('session_id', id);
            const res = await fetch('../ajax/station_audit_api.php', { method: 'POST', body: fd });
            const r = await res.json();
            if (r.success) {
                this.activeSession = id;
                this.sessionData = r;
                // Persist session in URL hash
                this._updateHash();
                const ss = r.system_sales || {};
                this.systemSales = {
                    pos_amount: ss.pos_amount || 0, cash_amount: ss.cash_amount || 0,
                    transfer_amount: ss.transfer_amount || 0, teller_amount: ss.teller_amount || 0,
                    notes: ss.notes || '', teller_proof_url: ss.teller_proof_url || '',
                    pos_proof_url: ss.pos_proof_url || '',
                    denomination_json: ss.denomination_json || '',
                    pos_terminals_json: ss.pos_terminals_json || '',
                    transfer_terminals_json: ss.transfer_terminals_json || ''
                };
                // Hydrate denominations
                if (ss.denomination_json) {
                    try {
                        const saved = JSON.parse(ss.denomination_json);
                        this.denominations.forEach(d => { d.count = saved[d.value] || 0; });
                    } catch (e) { }
                } else {
                    this.denominations.forEach(d => { d.count = 0; });
                }
                // Hydrate POS terminals
                if (ss.pos_terminals_json) {
                    try { this.posTerminals = JSON.parse(ss.pos_terminals_json); } catch (e) { this.posTerminals = []; }
                } else { this.posTerminals = []; }
                // Hydrate Transfer terminals
                if (ss.transfer_terminals_json) {
                    try { this.transferTerminals = JSON.parse(ss.transfer_terminals_json); } catch (e) { this.transferTerminals = []; }
                } else { this.transferTerminals = []; }
                // Auto-populate from outlet if no terminals saved in session yet
                if (this.posTerminals.length === 0 && this.transferTerminals.length === 0 && this.sessionData?.session?.outlet_id) {
                    const ot = await this.api('get_outlet_terminals', { outlet_id: this.sessionData.session.outlet_id });
                    if (ot.success && ot.terminals?.length) {
                        this.posTerminals = ot.terminals.filter(t => t.terminal_type === 'pos').map(t => ({ name: t.terminal_name, amount: 0 }));
                        this.transferTerminals = ot.terminals.filter(t => t.terminal_type === 'transfer').map(t => ({ name: t.terminal_name, amount: 0 }));
                    }
                }
                this.pumpTables = (r.pump_tables || []).map(pt => ({ ...pt, _editing: false, _tankOpen: false, readings: pt.readings || [], tanks: pt.tanks || [] }));
                this.haulage = r.haulage || [];
                this.expenseCategories = (r.expense_categories || []).map(c => ({ ...c, ledger: c.ledger || [] }));
                if (this.expenseCategories.length && !this.activeExpenseCatId) this.activeExpenseCatId = this.expenseCategories[0].id;
                this.debtorAccounts = (r.debtor_accounts || []).map(a => ({ ...a, ledger: a.ledger || [] }));
                if (this.debtorAccounts.length && !this.activeDebtorId) this.activeDebtorId = this.debtorAccounts[0].id;
                this.lubeStoreItems = (r.lube_store_items || []).map(si => ({ ...si, _editing: false }));
                this.lubeIssues = r.lube_issues || [];
                this.lubeIssueLog = r.lube_issue_log || [];
                this.lubeSections = (r.lube_sections || []).map(ls => ({ ...ls, items: ls.items || [], _editing: false }));
                // Hydrate counter stock counts keyed by section_id
                this.counterStockCounts = {};
                (r.counter_stock_counts || []).forEach(csc => {
                    const sid = csc.section_id;
                    if (!this.counterStockCounts[sid]) this.counterStockCounts[sid] = [];
                    this.counterStockCounts[sid].push(csc);
                });
                // Load products then sync to store
                await this.loadLubeData();
                this.syncStoreFromProducts();
                this.syncCountersFromStore();
                this.loadDocuments();
                // Recompute received from authoritative lubeIssues data
                this.lubeSections.forEach(ls => {
                    (ls.items || []).forEach(it => {
                        const issue = this.lubeIssues.find(i => i.section_id == ls.id && (i.store_item_id == it.store_item_id || this.lubeStoreItems.find(s => s.id == i.store_item_id)?.item_name === it.item_name));
                        const newReceived = issue ? parseFloat(issue.quantity || 0) : 0;
                        const oldReceived = parseFloat(it.received || 0);
                        const receivedDiff = newReceived - oldReceived;
                        it.received = newReceived;
                        // If closing hasn't been set by a stock count, keep it in sync
                        // closing should reflect: last physical count + any received since
                        it.closing = parseFloat(it.closing || 0) + Math.max(0, receivedDiff);
                    });
                });
                this.$nextTick(() => lucide.createIcons());
            } else { this.toast(r.message, false); }
        },

        // ═══════════════════════════════════════
        // DOCUMENT STORAGE
        // ═══════════════════════════════════════
        async loadDocuments() {
            const sid = this.docFilter === 'current' ? this.activeSession : '';
            const fd = new FormData();
            fd.append('action', 'list_documents');
            if (sid) fd.append('session_id', sid);
            const res = await fetch('../ajax/station_audit_api.php', { method: 'POST', body: fd });
            const r = await res.json();
            if (r.success) {
                let docs = r.documents || [];

                // Inject system sales proof images as virtual documents
                const ss = this.systemSales || {};
                const session = this.sessionData?.session || {};
                const sessionRef = session.id ? ('Session #' + session.id) : '';
                const sessionDate = session.date_from || '';
                const outletName = session.outlet_name || '';

                if (ss.teller_proof_url) {
                    docs.push({
                        id: 'sys_teller_proof',
                        doc_label: 'Bank Deposit / Teller Slip',
                        original_name: 'teller_proof',
                        file_path: ss.teller_proof_url,
                        file_size: 0,
                        created_at: sessionDate,
                        outlet_name: outletName,
                        _reference: sessionRef,
                        _system: true
                    });
                }
                if (ss.pos_proof_url) {
                    docs.push({
                        id: 'sys_pos_proof',
                        doc_label: 'POS Transaction Slip',
                        original_name: 'pos_proof',
                        file_path: ss.pos_proof_url,
                        file_size: 0,
                        created_at: sessionDate,
                        outlet_name: outletName,
                        _reference: sessionRef,
                        _system: true
                    });
                }

                this.documents = docs;
                this.docStorage = r.storage || this.docStorage;
                // Update count to include system docs
                this.docStorage.count = docs.length;
            }
        },

        async uploadDocument(e) {
            const files = e.target.files || e.dataTransfer?.files;
            if (!files || files.length === 0) return;

            for (const file of files) {
                if (file.size > 2 * 1024 * 1024) {
                    this.toast(`${file.name} exceeds 2MB limit`, false);
                    continue;
                }
                this.docUploading = true;
                const fd = new FormData();
                fd.append('action', 'upload_document');
                fd.append('session_id', this.activeSession || 0);
                fd.append('file', file);
                try {
                    const res = await fetch('../ajax/station_audit_api.php', { method: 'POST', body: fd });
                    const r = await res.json();
                    if (r.success) {
                        this.toast(`${file.name} uploaded`);
                    } else {
                        this.toast(r.message || 'Upload failed', false);
                    }
                } catch (err) {
                    this.toast('Upload error', false);
                }
            }
            this.docUploading = false;
            e.target.value = '';
            await this.loadDocuments();
        },

        async renameDocument(doc) {
            const newLabel = (this.docEditLabel || '').trim();
            if (!newLabel) return;
            const fd = new FormData();
            fd.append('action', 'rename_document');
            fd.append('doc_id', doc.id);
            fd.append('doc_label', newLabel);
            const res = await fetch('../ajax/station_audit_api.php', { method: 'POST', body: fd });
            const r = await res.json();
            if (r.success) {
                doc.doc_label = newLabel;
                this.docEditingId = null;
                this.docEditLabel = '';
                this.toast('Document renamed');
            } else {
                this.toast(r.message || 'Rename failed', false);
            }
        },

        async deleteDocument(doc) {
            if (!confirm(`Delete "${doc.doc_label || doc.original_name}"? This cannot be undone.`)) return;
            const fd = new FormData();
            fd.append('action', 'delete_document');
            fd.append('doc_id', doc.id);
            const res = await fetch('../ajax/station_audit_api.php', { method: 'POST', body: fd });
            const r = await res.json();
            if (r.success) {
                this.toast('Document deleted');
                await this.loadDocuments();
            } else {
                this.toast(r.message || 'Delete failed', false);
            }
        },

        formatFileSize(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const units = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            let size = parseFloat(bytes);
            while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
            return size.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
        },

        isImageFile(doc) {
            return /\.(jpg|jpeg|png|gif|webp)$/i.test(doc.original_name || '');
        },
        async saveSystemSales() {
            // Build denomination JSON map
            const denomMap = {};
            this.denominations.forEach(d => { if (d.count > 0) denomMap[d.value] = d.count; });
            this.systemSales.denomination_json = JSON.stringify(denomMap);
            // Build terminal JSONs
            this.systemSales.pos_terminals_json = JSON.stringify(this.posTerminals);
            this.systemSales.transfer_terminals_json = JSON.stringify(this.transferTerminals);
            this.saving = true;
            const r = await this.api('save_system_sales', { ...this.systemSales, session_id: this.activeSession });
            this.saving = false;
            r.success ? this.toast('System sales saved') : this.toast(r.message, false);
        },
        async createPumpTable() {
            const rate = prompt('Enter new rate per litre for ' + this.selectedProduct + ':');
            if (!rate || isNaN(rate) || parseFloat(rate) <= 0) {
                if (rate !== null) this.toast('Please enter a valid rate amount', false);
                return;
            }
            const dateFrom = this.sessionData?.session?.date_from || '';
            const dateTo = this.sessionData?.session?.date_to || '';
            const station = this.sessionData?.session?.outlet_name || '';

            // Find the latest table for this product (prefer active, fallback to last closed)
            const productTables = this.pumpTables.filter(pt => pt.product === this.selectedProduct);
            const prevTable = productTables.find(pt => pt.is_closed != 1) || productTables[productTables.length - 1];

            this.saving = true;

            if (prevTable) {
                // Save readings of previous table first if it's still active
                if (prevTable.is_closed != 1) {
                    await this.api('save_pump_readings', { pump_table_id: prevTable.id, readings: prevTable.readings });
                }

                // New period starts from the closing date of the previous period
                const newDateFrom = prevTable.date_to || dateFrom;

                // Use new_rate_period API: closes previous + copies pumps with closing→opening
                const r = await this.api('new_rate_period', {
                    prev_table_id: prevTable.id,
                    session_id: this.activeSession,
                    product: this.selectedProduct,
                    rate: rate,
                    date_from: newDateFrom,
                    date_to: dateTo,
                    station_location: station
                });
                this.saving = false;

                if (r.success) {
                    // Mark previous table as closed in UI
                    prevTable.is_closed = 1;

                    // Build new table with pumps carried over (closing→opening)
                    const copiedReadings = (prevTable.readings || []).map((pr, idx) => ({
                        id: 0, pump_name: pr.pump_name,
                        opening: parseFloat(pr.closing) || 0, rtt: 0, closing: 0
                    }));

                    // Tanks carried over from API (closing → opening)
                    const copiedTanks = r.tanks || [];

                    this.pumpTables.push({
                        id: r.id, product: this.selectedProduct, rate: rate,
                        date_from: newDateFrom, date_to: dateTo, station_location: station,
                        is_closed: 0, _tankOpen: false, readings: copiedReadings, tanks: copiedTanks
                    });
                    const parts = [];
                    if (r.pumps_copied) parts.push(r.pumps_copied + ' pump(s)');
                    if (r.tanks_copied) parts.push(r.tanks_copied + ' tank(s)');
                    this.toast('New rate period created' + (parts.length ? ' — ' + parts.join(', ') + ' carried over' : ''));
                    this.$nextTick(() => lucide.createIcons());
                } else { this.toast(r.message, false); }
            } else {
                // First table for this product — no previous pumps to copy
                const r = await this.api('save_pump_table', {
                    session_id: this.activeSession, product: this.selectedProduct,
                    rate: rate, date_from: dateFrom, date_to: dateTo, station_location: station
                });
                this.saving = false;
                if (r.success) {
                    this.pumpTables.push({
                        id: r.id, product: this.selectedProduct, rate: rate,
                        date_from: dateFrom, date_to: dateTo, station_location: station,
                        is_closed: 0, _tankOpen: false, readings: [], tanks: []
                    });
                    this.toast('First pump table created — add pumps to begin');
                    this.$nextTick(() => lucide.createIcons());
                } else { this.toast(r.message, false); }
            }
        },
        async addPumpToTable(ptId) {
            let name = prompt('Enter pump name (e.g., Pump 1):');
            if (!name) return;
            name = this.toTitleCase(name.trim());
            const r = await this.api('add_pump', { pump_table_id: ptId, pump_name: name });
            if (r.success) {
                const pt = this.pumpTables.find(p => p.id == ptId);
                if (pt) pt.readings.push({ id: r.id, pump_name: name, opening: 0, rtt: 0, closing: 0 });
                this.toast('Pump added');
            }
        },
        syncClosingToNext(pt, r) {
            // Find all tables for this product in order
            const productTables = this.pumpTables.filter(p => p.product === pt.product);
            const idx = productTables.findIndex(p => p.id === pt.id);
            if (idx < 0 || idx >= productTables.length - 1) return;
            // Next table
            const nextPt = productTables[idx + 1];
            // Find matching pump by name
            const nextPump = nextPt.readings.find(nr => nr.pump_name === r.pump_name);
            if (nextPump) nextPump.opening = parseFloat(r.closing) || 0;
        },
        async savePumpReadings(pt) {
            // Sync all closing values to next table before saving
            const productTables = this.pumpTables.filter(p => p.product === pt.product);
            const idx = productTables.findIndex(p => p.id === pt.id);
            if (idx >= 0 && idx < productTables.length - 1) {
                const nextPt = productTables[idx + 1];
                pt.readings.forEach(r => {
                    const nextPump = nextPt.readings.find(nr => nr.pump_name === r.pump_name);
                    if (nextPump) nextPump.opening = parseFloat(r.closing) || 0;
                });
                // Also save the next table's updated openings
                await this.api('save_pump_readings', { pump_table_id: nextPt.id, readings: nextPt.readings });
            }
            this.saving = true;
            const r = await this.api('save_pump_readings', { pump_table_id: pt.id, readings: pt.readings });
            this.saving = false;
            r.success ? (pt._editing = false, this.toast('Pump readings saved')) : this.toast(r.message, false);
        },
        async closePumpTable(pt) {
            if (!confirm('Close this pump table? Fields will be locked. You can create a new rate period after.')) return;
            // Save readings first
            await this.api('save_pump_readings', { pump_table_id: pt.id, readings: pt.readings });
            const r = await this.api('close_pump_table', { id: pt.id });
            if (r.success) {
                pt.is_closed = 1;
                this.toast('Pump table closed');
            }
        },
        async updatePumpTable(pt) {
            const r = await this.api('save_pump_table', {
                id: pt.id, session_id: this.activeSession, product: pt.product,
                rate: pt.rate, date_from: pt.date_from, date_to: pt.date_to,
                station_location: pt.station_location
            });
            r.success ? this.toast('Table updated') : this.toast(r.message, false);
        },
        async deletePumpTable(pt) {
            if (!confirm('DELETE this pump table and all its readings? This cannot be undone.')) return;
            const r = await this.api('delete_pump_table', { id: pt.id });
            if (r.success) {
                this.pumpTables = this.pumpTables.filter(p => p.id !== pt.id);
                this.toast('Pump table deleted');
            } else { this.toast(r.message, false); }
        },
        async addTank(pt) {
            let name = prompt('Enter tank name (e.g., Tank 1):');
            if (!name) return;
            name = this.toTitleCase(name.trim());
            const r = await this.api('add_tank', { pump_table_id: pt.id, tank_name: name });
            if (r.success) {
                if (!pt.tanks) pt.tanks = [];
                pt.tanks.push({ id: r.id, tank_name: name, product: pt.product, opening: 0, added: 0, closing: 0, capacity_kg: 0, max_fill_percent: 100 });
                this.toast('Tank added');
            } else { this.toast(r.message || 'Failed to add tank', false); }
        },
        async saveTankDipping(pt) {
            this.saving = true;
            const r = await this.api('save_tank_dipping', { pump_table_id: pt.id, tanks: pt.tanks || [] });
            this.saving = false;
            if (r.success) {
                // Sync closing → opening of next rate period's tanks in the UI
                const sameProd = this.pumpTables.filter(p => p.product === pt.product);
                const idx = sameProd.findIndex(p => p.id === pt.id);
                if (idx >= 0 && idx < sameProd.length - 1) {
                    const nextPt = sameProd[idx + 1];
                    if (nextPt.tanks && pt.tanks) {
                        pt.tanks.forEach(t => {
                            const match = nextPt.tanks.find(nt => nt.tank_name === t.tank_name);
                            if (match) match.opening = parseFloat(t.closing) || 0;
                        });
                    }
                }
                this.toast('Tank dipping saved' + (r.synced ? ' — next period updated' : ''));
            } else { this.toast(r.message, false); }
        },
        addHaulage() {
            this.haulage.push({
                delivery_date: new Date().toISOString().slice(0, 10),
                tank_name: '', product: 'PMS',
                quantity: 0, waybill_qty: 0,
                // LPG discharge fields (ignored for non-LPG products)
                _lpg_mode: 'direct',      // 'direct' | 'discharge'
                _truck_cap_kg: 0,         // truck tank capacity in kg
                _truck_max_fill: 100,     // truck configured max fill %
                _truck_open_pct: 0,       // gauge % before discharge
                _truck_close_pct: 0,      // gauge % after discharge
            });
            this.$nextTick(() => lucide.createIcons());
        },
        async saveHaulage() {
            // Validate: every row must match an existing pump table period
            const unmatched = this.haulage.filter(h => !this.pumpTableForDelivery(h.product, h.delivery_date));
            if (unmatched.length > 0) {
                const products = [...new Set(unmatched.map(h => h.product))].join(', ');
                this.toast(
                    `${unmatched.length} delivery(ies) for [${products}] have no matching rate period. ` +
                    `Go to Pump Sales → create or adjust a period that covers the delivery date(s) first.`,
                    false
                );
                return;
            }
            this.saving = true;
            const r = await this.api('save_haulage', { session_id: this.activeSession, entries: this.haulage });
            this.saving = false;
            if (r.success) {
                // Sync haulage totals to tank 'added' fields in the UI
                if (r.tank_updates) {
                    r.tank_updates.forEach(u => {
                        const pt = this.pumpTables.find(p => p.id == u.pump_table_id);
                        if (pt && pt.tanks) {
                            const tank = pt.tanks.find(t => t.tank_name === u.tank_name);
                            if (tank) tank.added = parseFloat(u.total_added) || 0;
                        }
                    });
                }
                this.toast('Haulage saved' + (r.tanks_updated ? ' — ' + r.tanks_updated + ' tank(s) updated' : ''));
            } else { this.toast(r.message, false); }
        },

        // ── Expense Categories & Ledger ──
        async createExpenseCategory() {
            if (!this.newExpenseCatName.trim()) return this.toast('Enter a category name', false);
            if (this.newExpenseCatName.includes('&')) return this.toast("Name cannot contain '&'. Use 'and' instead.", false);
            this.saving = true;
            const r = await this.api('create_expense_category', { session_id: this.activeSession, category_name: this.newExpenseCatName.trim() });
            this.saving = false;
            if (r.success) {
                const cat = { id: r.id, category_name: r.category_name, ledger: [] };
                this.expenseCategories.push(cat);
                this.activeExpenseCatId = r.id;
                this.newExpenseCatName = '';
                this.toast('Category created');
            } else { this.toast(r.message, false); }
        },
        async addExpenseEntry() {
            if (!this.activeExpenseCatId) return;
            const e = this.newExpenseLedgerEntry;
            if (!e.debit && !e.credit) return this.toast('Enter a debit or credit amount', false);
            this.saving = true;
            const r = await this.api('add_expense_entry', {
                category_id: this.activeExpenseCatId,
                entry_date: e.entry_date,
                description: e.description,
                debit: e.debit,
                credit: e.credit,
                payment_method: e.payment_method
            });
            this.saving = false;
            if (r.success) {
                const cat = this.expenseCategories.find(c => c.id == this.activeExpenseCatId);
                if (cat) cat.ledger.push({ id: r.id, entry_date: e.entry_date, description: e.description, debit: e.debit, credit: e.credit, payment_method: e.payment_method });
                this.newExpenseLedgerEntry = { entry_date: new Date().toISOString().slice(0, 10), description: '', debit: 0, credit: 0, payment_method: 'cash' };
                this.toast('Expense entry posted');
            } else { this.toast(r.message, false); }
        },
        toggleEditExpenseEntry(entry) {
            if (entry._editing) {
                entry._editing = false;
            } else {
                entry._editing = true;
                entry._edit = {
                    entry_date: entry.entry_date,
                    description: entry.description || '',
                    debit: parseFloat(entry.debit) || 0,
                    credit: parseFloat(entry.credit) || 0,
                    payment_method: entry.payment_method || 'cash'
                };
            }
        },
        async saveExpenseEntry(entry) {
            const e = entry._edit;
            this.saving = true;
            const r = await this.api('update_expense_entry', {
                entry_id: entry.id,
                entry_date: e.entry_date,
                description: e.description,
                debit: e.debit,
                credit: e.credit,
                payment_method: e.payment_method
            });
            this.saving = false;
            if (r.success) {
                entry.entry_date = e.entry_date;
                entry.description = e.description;
                entry.debit = e.debit;
                entry.credit = e.credit;
                entry.payment_method = e.payment_method;
                entry._editing = false;
                this.toast('Entry updated');
            } else { this.toast(r.message, false); }
        },
        async deleteExpenseEntry(entryId) {
            if (!confirm('Delete this expense entry?')) return;
            const r = await this.api('delete_expense_entry', { entry_id: entryId });
            if (r.success) {
                const cat = this.expenseCategories.find(c => c.id == this.activeExpenseCatId);
                if (cat) cat.ledger = cat.ledger.filter(e => e.id != entryId);
                this.toast('Entry deleted');
            } else { this.toast(r.message, false); }
        },
        // Aggregate expense entries by description across ALL categories
        get expenseDescriptionSummary() {
            const map = {};
            this.expenseCategories.forEach(cat => {
                (cat.ledger || []).forEach(e => {
                    const desc = (e.description || '(No description)').trim();
                    if (!map[desc]) map[desc] = { description: desc, totalDebit: 0, totalCredit: 0, count: 0 };
                    map[desc].totalDebit += parseFloat(e.debit) || 0;
                    map[desc].totalCredit += parseFloat(e.credit) || 0;
                    map[desc].count++;
                });
            });
            return Object.values(map).sort((a, b) => (b.totalDebit - b.totalCredit) - (a.totalDebit - a.totalCredit));
        },
        async deleteExpenseCategory(catId) {
            if (!confirm('Delete this expense category and all entries?')) return;
            const r = await this.api('delete_expense_category', { category_id: catId });
            if (r.success) {
                this.expenseCategories = this.expenseCategories.filter(c => c.id != catId);
                if (this.activeExpenseCatId == catId) this.activeExpenseCatId = this.expenseCategories.length ? this.expenseCategories[0].id : null;
                this.toast('Category deleted');
            } else { this.toast(r.message, false); }
        },
        async renameExpenseCategory(catId) {
            const cat = this.expenseCategories.find(c => c.id == catId);
            if (!cat) return;
            const newName = prompt('Rename expense category:', cat.category_name);
            if (!newName || !newName.trim() || newName.trim() === cat.category_name) return;
            if (newName.includes('&')) return this.toast("Name cannot contain '&'. Use 'and' instead.", false);
            const r = await this.api('rename_expense_category', { category_id: catId, new_name: newName.trim() });
            if (r.success) {
                cat.category_name = r.new_name;
                this.toast('Category renamed');
                this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
            } else { this.toast(r.message, false); }
        },

        // ── Debtor Accounts & Ledger ──
        async createDebtorAccount() {
            if (!this.newDebtorName.trim()) return this.toast('Enter a customer name', false);
            if (this.newDebtorName.includes('&')) return this.toast("Name cannot contain '&'. Use 'and' instead.", false);
            this.saving = true;
            const r = await this.api('create_debtor_account', { session_id: this.activeSession, customer_name: this.newDebtorName.trim() });
            this.saving = false;
            if (r.success) {
                const acct = { id: r.id, customer_name: r.customer_name, ledger: [] };
                this.debtorAccounts.push(acct);
                this.activeDebtorId = r.id;
                this.newDebtorName = '';
                this.toast('Debtor account created');
            } else { this.toast(r.message, false); }
        },
        async addDebtorEntry() {
            if (!this.activeDebtorId) return;
            const e = this.newLedgerEntry;
            if (!e.debit && !e.credit) return this.toast('Enter a debit or credit amount', false);
            this.saving = true;
            const r = await this.api('add_debtor_entry', {
                account_id: this.activeDebtorId,
                entry_date: e.entry_date,
                description: e.description,
                debit: e.debit,
                credit: e.credit
            });
            this.saving = false;
            if (r.success) {
                const acct = this.debtorAccounts.find(a => a.id == this.activeDebtorId);
                if (acct) acct.ledger.push({ id: r.id, entry_date: e.entry_date, description: e.description, debit: e.debit, credit: e.credit });
                this.newLedgerEntry = { entry_date: new Date().toISOString().slice(0, 10), description: '', debit: 0, credit: 0 };
                this.toast('Ledger entry added');
            } else { this.toast(r.message, false); }
        },
        toggleEditDebtorEntry(entry) {
            if (entry._editing) {
                entry._editing = false;
            } else {
                entry._editing = true;
                entry._edit = {
                    entry_date: entry.entry_date,
                    description: entry.description || '',
                    debit: parseFloat(entry.debit) || 0,
                    credit: parseFloat(entry.credit) || 0
                };
            }
        },
        async saveDebtorEntry(entry) {
            const e = entry._edit;
            this.saving = true;
            const r = await this.api('update_debtor_entry', {
                entry_id: entry.id,
                entry_date: e.entry_date,
                description: e.description,
                debit: e.debit,
                credit: e.credit
            });
            this.saving = false;
            if (r.success) {
                entry.entry_date = e.entry_date;
                entry.description = e.description;
                entry.debit = e.debit;
                entry.credit = e.credit;
                entry._editing = false;
                this.toast('Entry updated');
            } else { this.toast(r.message, false); }
        },
        async deleteDebtorEntry(entryId) {
            if (!confirm('Delete this ledger entry?')) return;
            const r = await this.api('delete_debtor_entry', { entry_id: entryId });
            if (r.success) {
                const acct = this.debtorAccounts.find(a => a.id == this.activeDebtorId);
                if (acct) acct.ledger = acct.ledger.filter(e => e.id != entryId);
                this.toast('Entry deleted');
            } else { this.toast(r.message, false); }
        },
        // Aggregate debtor entries by description across ALL accounts
        get debtorDescriptionSummary() {
            const map = {};
            this.debtorAccounts.forEach(acct => {
                (acct.ledger || []).forEach(e => {
                    const desc = (e.description || '(No description)').trim();
                    if (!map[desc]) map[desc] = { description: desc, totalDebit: 0, totalCredit: 0, count: 0 };
                    map[desc].totalDebit += parseFloat(e.debit) || 0;
                    map[desc].totalCredit += parseFloat(e.credit) || 0;
                    map[desc].count++;
                });
            });
            return Object.values(map).sort((a, b) => (b.totalDebit - b.totalCredit) - (a.totalDebit - a.totalCredit));
        },
        async deleteDebtorAccount(accountId) {
            if (!confirm('Delete this debtor account and all ledger entries?')) return;
            const r = await this.api('delete_debtor_account', { account_id: accountId });
            if (r.success) {
                this.debtorAccounts = this.debtorAccounts.filter(a => a.id != accountId);
                if (this.activeDebtorId == accountId) this.activeDebtorId = this.debtorAccounts.length ? this.debtorAccounts[0].id : null;
                this.toast('Debtor account deleted');
            } else { this.toast(r.message, false); }
        },
        async renameDebtorAccount(accountId) {
            const acct = this.debtorAccounts.find(a => a.id == accountId);
            if (!acct) return;
            const newName = prompt('Rename debtor/receivable:', acct.customer_name);
            if (!newName || !newName.trim() || newName.trim() === acct.customer_name) return;
            if (newName.includes('&')) return this.toast("Name cannot contain '&'. Use 'and' instead.", false);
            const r = await this.api('rename_debtor_account', { account_id: accountId, new_name: newName.trim() });
            if (r.success) {
                acct.customer_name = r.new_name;
                this.toast('Account renamed');
                this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
            } else { this.toast(r.message, false); }
        },

        // ── Lubricant Store methods ──
        addLubeStoreItem() {
            this.lubeStoreItems.push({ id: null, item_name: '', opening: 0, received: 0, return_out: 0, selling_price: 0, _editing: true });
        },
        // Auto-populate store items from the product catalog
        syncStoreFromProducts() {
            if (!this.lubeProducts.length) return;
            this.lubeProducts.forEach(p => {
                const exists = this.lubeStoreItems.some(si => si.item_name === p.product_name);
                if (!exists) {
                    this.lubeStoreItems.push({
                        id: null, item_name: p.product_name,
                        opening: 0, received: 0, return_out: 0, adjustment: 0,
                        selling_price: parseFloat(p.selling_price) || 0,
                        _editing: false
                    });
                }
            });
        },
        async saveLubeStoreItems() {
            this.saving = true;
            const r = await this.api('save_lube_store_items', { session_id: this.activeSession, items: this.lubeStoreItems });
            this.saving = false;
            if (r.success) {
                // Update IDs from server response
                if (r.ids) r.ids.forEach((id, i) => { if (this.lubeStoreItems[i]) this.lubeStoreItems[i].id = id; });
                this.lubeStoreItems.forEach(si => si._editing = false);
                this.toast('Lube store saved (' + r.count + ' items)');
            } else { this.toast(r.message, false); }
        },
        removeLubeStoreItem(idx) {
            this.lubeStoreItems.splice(idx, 1);
        },
        // Computed helpers for store
        storeItemIssued(si) {
            return this.lubeIssues
                .filter(iss => iss.store_item_id == si.id)
                .reduce((sum, iss) => sum + parseFloat(iss.quantity || 0), 0);
        },
        storeItemTotal(si) {
            return parseFloat(si.opening || 0) + parseFloat(si.received || 0) + parseFloat(si.return_out || 0);
        },
        storeItemClosing(si) {
            return this.storeItemTotal(si) - this.storeItemIssued(si) + parseFloat(si.adjustment || 0);
        },
        // Issue / Adjustment modal
        openAdjustModal(si) {
            this.lubeIssueForm = {
                store_item_id: si.id, store_item_name: si.item_name,
                section_id: this.lubeSections[0]?.id || '', quantity: 0,
                mode: 'issue', // 'issue' or 'adjust'
                adjustment_qty: 0, adjustment_reason: ''
            };
            this.lubeIssueModal = true;
            this.$nextTick(() => lucide.createIcons());
        },
        getIssuedQty(store_item_id, section_id) {
            const iss = this.lubeIssues.find(i => i.store_item_id == store_item_id && i.section_id == section_id);
            return iss ? parseFloat(iss.quantity) : 0;
        },
        async issueLubeToCounter() {
            const { store_item_id, section_id, quantity } = this.lubeIssueForm;
            if (!section_id) { this.toast('Select a counter', false); return; }
            this.saving = true;
            const r = await this.api('issue_lube_to_counter', { store_item_id, section_id, quantity });
            this.saving = false;
            if (r.success) {
                // Update local lubeIssues
                const existing = this.lubeIssues.find(i => i.store_item_id == store_item_id && i.section_id == section_id);
                if (existing) { existing.quantity = parseFloat(existing.quantity) + parseFloat(quantity); }
                else { this.lubeIssues.push({ store_item_id, section_id, quantity: parseFloat(quantity) }); }
                // Auto-update the counter's received for matching item
                const section = this.lubeSections.find(s => s.id == section_id);
                if (section) {
                    const si = this.lubeStoreItems.find(s => s.id == store_item_id);
                    let item = section.items.find(it => it.store_item_id == store_item_id || it.item_name === si?.item_name);
                    if (!item) {
                        item = { item_name: si?.item_name || '', store_item_id, opening: 0, received: 0, sold: 0, closing: 0, selling_price: parseFloat(si?.selling_price || 0) };
                        section.items.push(item);
                    }
                    item.received = parseFloat(item.received || 0) + parseFloat(quantity);
                    // Closing auto-increases when items are received (items are physically there now)
                    item.closing = parseFloat(item.closing || 0) + parseFloat(quantity);
                    item.store_item_id = store_item_id;
                }
                // Add to local issue log for history
                if (!this.lubeIssueLog) this.lubeIssueLog = [];
                const siItem = this.lubeStoreItems.find(s => s.id == store_item_id);
                const secItem = this.lubeSections.find(s => s.id == section_id);
                this.lubeIssueLog.unshift({
                    product_name: siItem?.item_name || '',
                    counter_name: secItem?.name || '',
                    quantity: parseFloat(quantity),
                    created_at: new Date().toISOString()
                });
                this.lubeIssueModal = false;
                this.toast('Issued ' + quantity + ' units to counter');
            } else { this.toast(r.message, false); }
        },
        async saveAdjustment() {
            const { store_item_id, adjustment_qty, adjustment_reason } = this.lubeIssueForm;
            if (!adjustment_reason.trim()) { this.toast('Please provide a reason for adjustment', false); return; }
            this.saving = true;
            const r = await this.api('save_lube_adjustment', { store_item_id, adjustment_qty, adjustment_reason });
            this.saving = false;
            if (r.success) {
                const si = this.lubeStoreItems.find(s => s.id == store_item_id);
                if (si) si.adjustment = parseFloat(adjustment_qty);
                this.lubeIssueModal = false;
                this.toast('Adjustment saved (' + (adjustment_qty >= 0 ? '+' : '') + adjustment_qty + ')');
            } else { this.toast(r.message, false); }
        },

        // ── Lubricant Counter methods ──
        async createLubeSection() {
            const name = prompt('Enter counter name (e.g., Main Counter):');
            if (!name) return;
            const r = await this.api('create_lube_section', { session_id: this.activeSession, name: this.toTitleCase(name.trim()) });
            if (r.success) {
                // Auto-populate items from Lube Store products with zero quantities
                const items = this.lubeStoreItems.map(si => ({
                    item_name: si.item_name, store_item_id: si.id,
                    opening: 0, received: 0, sold: 0, closing: 0,
                    selling_price: parseFloat(si.selling_price || 0)
                }));
                this.lubeSections.push({ id: r.id, name: this.toTitleCase(name.trim()), items, _editing: false });
                // Auto-save the items so they persist
                if (items.length > 0) {
                    await this.api('save_lube_items', { section_id: r.id, items });
                }
                this.$nextTick(() => lucide.createIcons());
                this.toast('Counter created with ' + items.length + ' product(s)');
            } else { this.toast(r.message, false); }
        },
        async deleteLubeSection(ls) {
            if (!confirm('Delete counter "' + ls.name + '" and all its items?')) return;
            const r = await this.api('delete_lube_section', { section_id: ls.id });
            if (r.success) {
                this.lubeSections = this.lubeSections.filter(s => s.id !== ls.id);
                this.toast('Counter deleted');
            } else { this.toast(r.message, false); }
        },
        addLubeItem(ls) {
            ls.items.push({ item_name: '', store_item_id: null, opening: 0, received: 0, sold: 0, closing: 0, selling_price: 0 });
        },
        // Sync all counters to have all Lube Store products
        syncCountersFromStore() {
            this.lubeSections.forEach(ls => {
                this.lubeStoreItems.forEach(si => {
                    const exists = ls.items.find(it => it.store_item_id == si.id || it.item_name === si.item_name);
                    if (!exists) {
                        ls.items.push({
                            item_name: si.item_name, store_item_id: si.id,
                            opening: 0, received: 0, sold: 0, closing: 0,
                            selling_price: parseFloat(si.selling_price || 0)
                        });
                    }
                });
            });
        },
        async saveLubeItems(ls) {
            this.saving = true;
            const r = await this.api('save_lube_items', { section_id: ls.id, items: ls.items });
            this.saving = false;
            if (r.success) { ls._editing = false; this.toast('Counter items saved'); }
            else { this.toast(r.message, false); }
        },
        // Sold = what has gone from the counter (Opening + Received − Closing)
        counterItemSold(it) {
            return Math.max(0, parseFloat(it.opening || 0) + parseFloat(it.received || 0) - parseFloat(it.closing || 0));
        },
        lubeSectionAmount(ls) {
            return (ls.items || []).reduce((sum, it) => sum + (this.counterItemSold(it) * (it.selling_price || 0)), 0);
        },
        get lubeTotalAmount() {
            return this.lubeSections.reduce((sum, ls) => sum + this.lubeSectionAmount(ls), 0);
        },

        // ── Stock Count: Lube Store (closing per product) ──
        get lubeStoreStockCount() {
            return this.lubeStoreItems.map(si => {
                const cp = parseFloat(this.lubeProducts.find(p => p.product_name === si.item_name)?.cost_price || 0);
                return {
                    product_name: si.item_name,
                    closing: this.storeItemClosing(si),
                    cost_price: cp,
                    value: this.storeItemClosing(si) * cp,
                };
            });
        },

        // ── Stock Count: Counters (closing per product, aggregated across all counters) ──
        get lubeCounterStockCount() {
            const map = {};
            this.lubeSections.forEach(ls => {
                (ls.items || []).forEach(it => {
                    const key = it.item_name || ('item_' + it.store_item_id);
                    const cp = parseFloat(this.lubeProducts.find(p => p.product_name === it.item_name)?.cost_price || 0);
                    if (!map[key]) map[key] = { product_name: it.item_name, closing: 0, cost_price: cp, value: 0 };
                    map[key].closing += parseFloat(it.closing || 0);
                    map[key].value += parseFloat(it.closing || 0) * cp;
                });
            });
            return Object.values(map);
        },

        // ── Consolidation: merge store closing + all counter closings per product ──
        get lubeConsolidation() {
            const map = {};
            // Store contributions
            this.lubeStoreItems.forEach(si => {
                const key = si.item_name;
                const cp = parseFloat(this.lubeProducts.find(p => p.product_name === key)?.cost_price || 0);
                if (!map[key]) map[key] = { product_name: key, store_closing: 0, counter_closing: 0, total_closing: 0, cost_price: cp };
                map[key].store_closing += this.storeItemClosing(si);
            });
            // Counter contributions
            this.lubeSections.forEach(ls => {
                (ls.items || []).forEach(it => {
                    const key = it.item_name;
                    const cp = parseFloat(this.lubeProducts.find(p => p.product_name === key)?.cost_price || 0);
                    if (!map[key]) map[key] = { product_name: key, store_closing: 0, counter_closing: 0, total_closing: 0, cost_price: cp };
                    map[key].counter_closing += parseFloat(it.closing || 0);
                });
            });
            // Compute totals
            Object.values(map).forEach(row => { row.total_closing = row.store_closing + row.counter_closing; row.total_value = row.total_closing * row.cost_price; });
            return Object.values(map);
        },
        get lubeConsolidationTotalValue() {
            return this.lubeConsolidation.reduce((s, r) => s + r.total_value, 0);
        },
        async signOff(role) {
            if (!confirm('Confirm sign-off as ' + role + '?')) return;
            const r = await this.api('sign_off', { session_id: this.activeSession, role: role, comments: this.signoffComments });
            if (r.success) {
                this.toast('Signed off as ' + role);
                await this.loadSession(this.activeSession);
            }
        },

        // ── Lube Products methods ──
        openLubeProductModal(p = null) {
            this.lubeProductForm = p ? { ...p } : { id: 0, product_name: '', unit: 'Litre', cost_price: 0, selling_price: 0, reorder_level: 0 };
            this.lubeProductModal = true;
            this.$nextTick(() => lucide.createIcons());
        },
        async saveLubeProduct() {
            if (!this.lubeProductForm.product_name) { this.toast('Product name required', false); return; }
            this.saving = true;
            const r = await this.api('save_lube_product', this.lubeProductForm);
            this.saving = false;
            if (r.success) {
                await this.loadLubeData();
                this.lubeProductModal = false;
                this.toast('Product saved');
            } else { this.toast(r.message, false); }
        },
        async deleteLubeProduct(p) {
            if (!confirm('Delete product "' + p.product_name + '"?')) return;
            const r = await this.api('delete_lube_product', { id: p.id });
            if (r.success) { this.lubeProducts = this.lubeProducts.filter(x => x.id !== p.id); this.toast('Product deleted'); }
            else { this.toast(r.message, false); }
        },

        // ── Lube Suppliers methods ──
        openLubeSupplierModal(s = null) {
            this.lubeSupplierForm = s ? { ...s } : { id: 0, supplier_name: '', contact_person: '', phone: '', email: '', address: '' };
            this.lubeSupplierModal = true;
            this.$nextTick(() => lucide.createIcons());
        },
        async saveLubeSupplier() {
            if (!this.lubeSupplierForm.supplier_name) { this.toast('Supplier name required', false); return; }
            this.saving = true;
            const r = await this.api('save_lube_supplier', this.lubeSupplierForm);
            this.saving = false;
            if (r.success) {
                await this.loadLubeData();
                this.lubeSupplierModal = false;
                this.toast('Supplier saved');
            } else { this.toast(r.message, false); }
        },
        async deleteLubeSupplier(s) {
            if (!confirm('Delete supplier "' + s.supplier_name + '"?')) return;
            const r = await this.api('delete_lube_supplier', { id: s.id });
            if (r.success) { this.lubeSuppliers = this.lubeSuppliers.filter(x => x.id !== s.id); this.toast('Supplier deleted'); }
            else { this.toast(r.message, false); }
        },

        // ── GRN methods ──
        openLubeGrnModal(g = null) {
            this.lubeGrnForm = g ? { ...g, items: (g.items || []).map(i => ({ ...i, total_cost: parseFloat(i.total_cost || i.line_total || 0) || (parseFloat(i.quantity || 0) * parseFloat(i.cost_price || 0)) })) }
                : { id: 0, supplier_id: '', grn_number: 'GRN-' + Date.now().toString().slice(-6), grn_date: new Date().toISOString().slice(0, 10), invoice_number: '', notes: '', items: [] };
            this.lubeGrnModal = true;
            this.$nextTick(() => lucide.createIcons());
        },
        addGrnItem() {
            this.lubeGrnForm.items.push({ product_id: '', product_name: '', unit: 'Litre', quantity: 0, cost_price: 0, selling_price: 0, total_cost: 0 });
        },
        grnItemTotal(it) {
            return parseFloat(it.total_cost || 0);
        },
        get grnFormTotal() {
            return this.lubeGrnForm.items.reduce((s, it) => s + this.grnItemTotal(it), 0);
        },
        calculateGrnItemCost(it) {
            const qty = parseFloat(it.quantity || 0);
            const total = parseFloat(it.total_cost || 0);
            it.cost_price = qty > 0 ? Math.round((total / qty) * 100) / 100 : 0;
        },
        fillGrnItemFromProduct(it) {
            const p = this.lubeProducts.find(x => x.id == it.product_id);
            if (p) {
                it.product_name = p.product_name;
                it.unit = p.unit;
                it.cost_price = parseFloat(p.cost_price || 0);
                it.selling_price = parseFloat(p.selling_price || 0);
                it.total_cost = parseFloat(it.quantity || 0) * it.cost_price;
            }
        },
        async saveLubeGrn() {
            if (!this.lubeGrnForm.grn_date) { this.toast('GRN date required', false); return; }
            this.saving = true;
            const r = await this.api('save_lube_grn', { ...this.lubeGrnForm, session_id: this.activeSession });
            this.saving = false;
            if (r.success) {
                await this.loadLubeData();
                // Reload session to pick up updated received quantities in store
                if (this.activeSession) await this.loadSession(this.activeSession);
                this.lubeGrnModal = false;
                this.toast('GRN saved (' + (window.__NAIRA || '\u20A6') + parseFloat(r.total_cost).toLocaleString('en', { minimumFractionDigits: 2 }) + ')');
            } else { this.toast(r.message, false); }
        },
        async deleteLubeGrn(g) {
            if (!confirm('Delete GRN ' + (g.grn_number || g.id) + '?')) return;
            const r = await this.api('delete_lube_grn', { id: g.id });
            if (r.success) { this.lubeGrns = this.lubeGrns.filter(x => x.id !== g.id); this.toast('GRN deleted'); }
            else { this.toast(r.message, false); }
        },

        // ── Stock Count methods ──
        openStockCountModal(sc = null) {
            if (sc) {
                // Edit existing
                this.lubeStockCountForm = { ...sc, items: (sc.items || []).map(i => ({ ...i, physical_count: parseInt(i.physical_count) || 0 })) };
            } else {
                // Create new: populate from current lube store balance
                const items = this.lubeStoreItems.map(si => {
                    const sysStock = Math.round(this.storeItemClosing(si));
                    const cp = this.lubeProducts.find(p => p.product_name === si.item_name)?.cost_price || 0;
                    const sp = parseFloat(si.selling_price) || 0;
                    const issued = Math.round(this.storeItemIssued(si));
                    return {
                        product_name: si.item_name,
                        system_stock: sysStock,
                        physical_count: 0,
                        variance: 0,
                        cost_price: parseFloat(cp),
                        selling_price: sp,
                        sold_qty: issued,
                        sold_value_cost: issued * parseFloat(cp)
                    };
                }).filter(i => i.product_name);
                this.lubeStockCountForm = {
                    id: 0,
                    date_from: new Date().toISOString().slice(0, 10),
                    date_to: new Date().toISOString().slice(0, 10),
                    notes: '',
                    items: items
                };
            }
            this.lubeStockCountModal = true;
            this.$nextTick(() => lucide.createIcons());
        },
        stockCountItemVariance(it) {
            return parseInt(it.physical_count || 0) - parseInt(it.system_stock || 0);
        },
        async saveLubeStockCount(closeModal = true) {
            if (!this.lubeStockCountForm.date_from || !this.lubeStockCountForm.date_to) {
                this.toast('Date range required', false); return;
            }
            // Recalculate variance and sold value before save
            this.lubeStockCountForm.items.forEach(it => {
                it.variance = this.stockCountItemVariance(it);
                it.sold_value_cost = parseInt(it.sold_qty || 0) * parseFloat(it.cost_price || 0);
            });
            this.saving = true;
            const r = await this.api('save_lube_stock_count', { ...this.lubeStockCountForm, session_id: this.activeSession });
            this.saving = false;
            if (r.success) {
                // Update form id to prevent duplicate inserts if saved again
                if (r.id) this.lubeStockCountForm.id = r.id;
                await this.loadLubeData();
                if (closeModal) this.lubeStockCountModal = false;
                this.toast('Stock count saved');
            } else { this.toast(r.message, false); }
        },
        async deleteLubeStockCount(sc) {
            if (!confirm('Delete this stock count?')) return;
            const r = await this.api('delete_lube_stock_count', { id: sc.id });
            if (r.success) { this.lubeStockCounts = this.lubeStockCounts.filter(x => x.id !== sc.id); this.toast('Stock count deleted'); }
            else { this.toast(r.message, false); }
        },
        async finalizeLubeStockCount(sc) {
            if (!confirm('Mark this stock count as Finalized?')) return;
            const r = await this.api('finalize_lube_stock_count', { id: sc.id });
            if (r.success) { sc.status = 'closed'; this.toast('Stock count finalized'); }
            else { this.toast(r.message, false); }
        },

        // ── Counter Stock Count methods (period-based per counter) ──
        getCounterStockCounts(sectionId) {
            return this.counterStockCounts[sectionId] || [];
        },
        openCounterStockCountModal(ls, sc = null) {
            if (sc) {
                // Edit existing
                this.counterStockCountForm = { ...sc, items: (sc.items || []).map(i => ({ ...i, physical_count: parseInt(i.physical_count) || 0 })) };
            } else {
                // Create new: auto-chain from last closed period
                const counts = this.getCounterStockCounts(ls.id);
                const lastClosed = counts.find(c => c.status === 'closed');
                const dateFrom = lastClosed ? lastClosed.date_to : new Date().toISOString().slice(0, 10);

                // Populate from counter items
                const items = (ls.items || []).map(it => {
                    // System Stock = current closing (last physical count + any received since)
                    const sysStock = Math.round(parseFloat(it.closing || 0));
                    const cp = parseFloat(this.lubeProducts.find(p => p.product_name === it.item_name)?.cost_price || 0);
                    return {
                        product_name: it.item_name,
                        system_stock: sysStock,
                        physical_count: 0,
                        variance: 0,
                        cost_price: cp,
                        sold_qty: 0,
                        sold_value: 0
                    };
                }).filter(i => i.product_name);

                this.counterStockCountForm = {
                    id: 0,
                    section_id: ls.id,
                    date_from: dateFrom,
                    date_to: new Date().toISOString().slice(0, 10),
                    notes: '',
                    items: items
                };
            }
            this.counterStockCountModal = true;
            this.$nextTick(() => lucide.createIcons());
        },
        counterStockCountItemVariance(it) {
            return parseInt(it.physical_count || 0) - parseInt(it.system_stock || 0);
        },
        counterStockCountItemSold(it) {
            // Sold = what was in system minus what is physically there
            return Math.max(0, parseInt(it.system_stock || 0) - parseInt(it.physical_count || 0));
        },
        async saveCounterStockCount(closeModal = true) {
            if (!this.counterStockCountForm.date_from || !this.counterStockCountForm.date_to) {
                this.toast('Date range required', false); return;
            }
            // Auto-calculate sold, variance, and sold value before save
            this.counterStockCountForm.items.forEach(it => {
                it.variance = this.counterStockCountItemVariance(it);
                it.sold_qty = this.counterStockCountItemSold(it);
                it.sold_value = it.sold_qty * parseFloat(it.cost_price || it.selling_price || 0);
            });
            this.saving = true;
            const r = await this.api('save_counter_stock_count', this.counterStockCountForm);
            this.saving = false;
            if (r.success) {
                // Reload counter stock counts for this section
                const sid = this.counterStockCountForm.section_id;
                const rsc = await this.api('get_counter_stock_counts', { section_id: sid });
                if (rsc.success) this.counterStockCounts[sid] = rsc.counts || [];

                // Push physical count → closing in counter table (sold is auto-computed)
                const section = this.lubeSections.find(s => s.id == sid);
                if (section) {
                    this.counterStockCountForm.items.forEach(scItem => {
                        const counterItem = (section.items || []).find(ci => ci.item_name === scItem.product_name);
                        if (counterItem) {
                            counterItem.closing = parseInt(scItem.physical_count || 0);
                            // sold is NOT written — it's always computed as Opening + Received − Closing
                        }
                    });
                    // Auto-save counter items to persist the updated closing
                    await this.api('save_lube_items', { section_id: section.id, items: section.items });
                }

                if (closeModal) this.counterStockCountModal = false;
                this.toast('Counter stock count saved');
            } else { this.toast(r.message, false); }
        },
        async deleteCounterStockCount(sc) {
            if (!confirm('Delete this counter stock count?')) return;
            const r = await this.api('delete_counter_stock_count', { id: sc.id });
            if (r.success) {
                const sid = sc.section_id;
                this.counterStockCounts[sid] = (this.counterStockCounts[sid] || []).filter(x => x.id !== sc.id);
                this.toast('Counter stock count deleted');
            } else { this.toast(r.message, false); }
        },
        async finalizeCounterStockCount(sc) {
            if (!confirm('Finalize this counter stock count period? It will be locked.')) return;
            const r = await this.api('finalize_counter_stock_count', { id: sc.id });
            if (r.success) { sc.status = 'closed'; this.toast('Counter stock count finalized'); }
            else { this.toast(r.message, false); }
        },

        // ── Load company-level lube data ──
        async loadLubeData() {
            const [rp, rs, rg, rsc] = await Promise.all([
                this.api('get_lube_products', {}, 'POST'),
                this.api('get_lube_suppliers', {}, 'POST'),
                this.api('get_lube_grns', { session_id: this.activeSession }, 'POST'),
                this.api('get_lube_stock_counts', { session_id: this.activeSession }, 'POST'),
            ]);
            if (rp.success) this.lubeProducts = rp.products || [];
            if (rs.success) this.lubeSuppliers = rs.suppliers || [];
            if (rg.success) this.lubeGrns = rg.grns || [];
            if (rsc.success) this.lubeStockCounts = rsc.counts || [];
            this.lubeGrnLoaded = true;
            this.syncStoreFromProducts();
        },

        // ── Report PDF Builder ──
        _buildReportHTML() {
            const N = window.__NAIRA || '\u20A6';
            const s = this.sessionData?.session || {};
            const station = s.outlet_name || 'Station';
            const dateFrom = s.date_from || '--';
            const dateTo = s.date_to || '--';
            const company = window.__SA_COMPANY || 'MIAUDITOPS';
            const preparer = this.reportCover.preparedBy || (window.__SA_USER?.name || 'Auditor');
            const reviewer = this.reportCover.reviewedBy || '';
            const coverTitle = this.reportCover.title || 'Station Audit Close-Out Report';
            const coverNotes = this.reportCover.notes || '';
            const period = this.reportCover.reportingPeriod || (dateFrom + ' to ' + dateTo);
            const genDate = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' });
            const genTime = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
            const fmtN = n => N + (parseFloat(n) || 0).toLocaleString('en', { minimumFractionDigits: 2 });
            const fmtL = n => (parseFloat(n) || 0).toLocaleString('en', { minimumFractionDigits: 2 });
            const esc = v => this._esc(v);

            // ── SVG icon map for professional PDF ──
            const svgIcons = {
                payment: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
                pump: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M3 22V6a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16"/><path d="M3 22h10"/><path d="M13 10h2a2 2 0 0 1 2 2v4a2 2 0 0 0 2 2h0a2 2 0 0 0 2-2V9l-3-3"/></svg>',
                tank: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M12 2a10 10 0 0 1 0 20 10 10 0 0 1 0-20z"/><path d="M12 6v6l4 2"/></svg>',
                haulage: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
                lube: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M8 2h8l2 4H6l2-4z"/><rect x="6" y="6" width="12" height="16" rx="1"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
                expense: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
                debtor: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                variance: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
                closeout: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>',
            };
            const sectionHeader = (svgKey, title, color) => `
                <div class="section-header" style="background:linear-gradient(135deg,${color} 0%,${color}dd 100%);padding:12px 18px;margin:0;border-radius:12px 12px 0 0;display:flex;align-items:center;gap:10px;box-shadow:0 2px 8px ${color}33">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:rgba(255,255,255,.2);border-radius:6px;color:#fff;flex-shrink:0">${svgIcons[svgKey] || ''}</span>
                    <h3 style="margin:0;font-size:14px;font-weight:800;color:#fff;letter-spacing:.6px;text-transform:uppercase">${esc(title)}</h3>
                </div>`;

            const kv = (label, value, bold = false) => `
                <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f1f5f9">
                    <span style="font-size:12px;color:#64748b">${esc(label)}</span>
                    <span style="font-size:12px;font-weight:${bold ? '800' : '600'};color:#1e293b">${value}</span>
                </div>`;

            const tableHead = (...cols) => `<thead><tr>${cols.map(c => `<th style="padding:9px 12px;background:linear-gradient(180deg,#f8fafc,#f1f5f9);font-size:11px;font-weight:700;color:#475569;text-align:${c.right ? 'right' : 'left'};border-bottom:2px solid #e2e8f0;white-space:nowrap;letter-spacing:.3px">${c.label}</th>`).join('')}</tr></thead>`;

            // ── 1. SYSTEM SALES ──
            const sysSalesHTML = `
                <div class="rpt-section">
                    ${sectionHeader('payment', 'Payment/Declared', '#2563eb')}
                    <div style="padding:14px 18px">
                        ${kv('POS Terminals', fmtN(this.systemSales.pos_amount))}
                        ${kv('Cash (Denomination)', fmtN(this.systemSales.cash_amount))}
                        ${kv('Bank Transfer', fmtN(this.systemSales.transfer_amount))}
                        ${kv('Teller / Deposit', fmtN(this.systemSales.teller_amount))}
                        ${kv('TOTAL PAYMENT/DECLARED', fmtN(this.systemSalesTotal), true)}
                        ${this.posTerminals.length > 0 ? `<div style="margin-top:10px;padding:10px 12px;background:#eff6ff;border-radius:8px"><div style="font-size:11px;font-weight:700;color:#3b82f6;margin-bottom:6px;letter-spacing:.3px">POS TERMINAL BREAKDOWN</div>${this.posTerminals.map(t => `<div style="display:flex;justify-content:space-between;font-size:11px;padding:3px 0"><span>${esc(t.name)}</span><span style="font-weight:600">${fmtN(t.amount)}</span></div>`).join('')}</div>` : ''}
                        ${this.transferTerminals.length > 0 ? `<div style="margin-top:10px;padding:10px 12px;background:#f5f3ff;border-radius:8px"><div style="font-size:11px;font-weight:700;color:#7c3aed;margin-bottom:6px;letter-spacing:.3px">TRANSFER TERMINAL BREAKDOWN</div>${this.transferTerminals.map(t => `<div style="display:flex;justify-content:space-between;font-size:11px;padding:3px 0"><span>${esc(t.name)}</span><span style="font-weight:600">${fmtN(t.amount)}</span></div>`).join('')}</div>` : ''}
                    </div>
                </div>`;

            // ── 2. PUMP SALES ──
            const pumpRows = this.pumpSalesGrouped.map(r => {
                if (r.type === 'subtotal') {
                    return `<tr style="background:#fef3c7"><td colspan="4" style="padding:7px 12px;font-size:12px;font-weight:800;color:#92400e">${esc(r.product)} Subtotal</td><td style="padding:7px 12px;text-align:right;font-size:12px;font-weight:700">${fmtL(r.totalLitres)} L</td><td style="padding:7px 12px;text-align:right;font-size:12px;font-weight:800;color:#d97706">${fmtN(r.totalAmount)}</td></tr>`;
                }
                return `<tr><td style="padding:6px 12px;font-size:11px;color:#0369a1;font-weight:700">${esc(r.product)}</td><td style="padding:6px 12px;font-size:11px">${esc(r.dateFrom)}</td><td style="padding:6px 12px;font-size:11px">${esc(r.dateTo)}</td><td style="padding:6px 12px;text-align:right;font-size:11px">${fmtN(r.rate)}/L</td><td style="padding:6px 12px;text-align:right;font-size:11px">${fmtL(r.litres)} L</td><td style="padding:6px 12px;text-align:right;font-size:11px;color:#059669;font-weight:600">${fmtN(r.amount)}</td></tr>`;
            }).join('');
            const pumpSalesHTML = `
                <div class="rpt-section">
                    ${sectionHeader('pump', 'Pump Sales', '#ea580c')}
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse">
                            ${tableHead({ label: 'Product' }, { label: 'From' }, { label: 'To' }, { label: 'Rate', right: true }, { label: 'Litres', right: true }, { label: 'Amount', right: true })}
                            <tbody>${pumpRows || '<tr><td colspan="6" style="padding:12px;text-align:center;color:#94a3b8;font-size:11px">No pump sales recorded</td></tr>'}</tbody>
                            <tfoot><tr style="background:linear-gradient(90deg,#ffedd5,#fed7aa)"><td colspan="4" style="padding:9px 12px;font-size:12px;font-weight:800;color:#9a3412">GRAND TOTAL</td><td style="padding:9px 12px;text-align:right;font-size:12px;font-weight:700">${fmtL(this.totalPumpLitres)} L</td><td style="padding:9px 12px;text-align:right;font-size:12px;font-weight:800;color:#c2410c">${fmtN(this.totalPumpSales)}</td></tr></tfoot>
                        </table>
                    </div>
                </div>`;

            // ── 3. TANK DIPPING ──
            const tankRows = this.tankProductTotals.map(t => `
                <tr>
                    <td style="padding:6px 12px;font-size:12px;font-weight:700;color:#0f766e">${esc(t.product)}</td>
                    <td style="padding:6px 12px;text-align:right;font-size:12px">${fmtL(t.opening)} L</td>
                    <td style="padding:6px 12px;text-align:right;font-size:12px">${fmtL(t.added)} L</td>
                    <td style="padding:6px 12px;text-align:right;font-size:12px">${fmtL(t.closing)} L</td>
                    <td style="padding:6px 12px;text-align:right;font-size:12px;font-weight:700;color:${t.diff >= 0 ? '#0f766e' : '#dc2626'}">${fmtL(t.diff)} L</td>
                </tr>`).join('');
            const tankHTML = `
                <div class="rpt-section">
                    ${sectionHeader('tank', 'Tank Dipping', '#0d9488')}
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse">
                            ${tableHead({ label: 'Product' }, { label: 'Opening', right: true }, { label: 'Added', right: true }, { label: 'Closing', right: true }, { label: 'Diff (Used)', right: true })}
                            <tbody>${tankRows || '<tr><td colspan="5" style="padding:12px;text-align:center;color:#94a3b8;font-size:11px">No tank dipping recorded</td></tr>'}</tbody>
                        </table>
                    </div>
                </div>`;

            // ── 4. HAULAGE ──
            const haulRows = this.haulageByProduct.map(h => `
                <tr>
                    <td style="padding:6px 12px;font-size:12px;font-weight:700;color:#4338ca">${esc(h.product)}</td>
                    <td style="padding:6px 12px;text-align:right;font-size:12px">${h.count}</td>
                    <td style="padding:6px 12px;text-align:right;font-size:12px">${fmtL(h.waybill_qty)} L</td>
                    <td style="padding:6px 12px;text-align:right;font-size:12px;font-weight:700;color:#4338ca">${fmtL(h.quantity)} L</td>
                </tr>`).join('');
            const haulageHTML = `
                <div class="rpt-section">
                    ${sectionHeader('haulage', 'Haulage (Receipts)', '#4338ca')}
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse">
                            ${tableHead({ label: 'Product' }, { label: 'Waybills', right: true }, { label: 'Waybill Qty', right: true }, { label: 'Actual Recv.', right: true })}
                            <tbody>${haulRows || '<tr><td colspan="4" style="padding:12px;text-align:center;color:#94a3b8;font-size:11px">No haulage recorded</td></tr>'}</tbody>
                            <tfoot><tr style="background:linear-gradient(90deg,#eef2ff,#e0e7ff)"><td style="padding:9px 12px;font-size:12px;font-weight:800;color:#3730a3">TOTAL</td><td style="padding:9px 12px;text-align:right;font-size:12px;font-weight:700">${this.haulage.length}</td><td colspan="2" style="padding:9px 12px;text-align:right;font-size:12px;font-weight:800;color:#3730a3">${fmtL(this.totalHaulageQty)} L</td></tr></tfoot>
                        </table>
                    </div>
                </div>`;

            // ── 5. LUBRICANTS ──
            const lubeRows = this.lubeSections.map(ls => `
                <tr>
                    <td style="padding:6px 12px;font-size:12px;font-weight:600">${esc(ls.section_name || ls.name)}</td>
                    <td style="padding:6px 12px;text-align:right;font-size:12px">${(ls.items || []).length}</td>
                    <td style="padding:6px 12px;text-align:right;font-size:12px;font-weight:700;color:#4d7c0f">${fmtN(this.lubeSectionAmount(ls))}</td>
                </tr>`).join('');
            const lubricantHTML = `
                <div class="rpt-section">
                    ${sectionHeader('lube', 'Lubricants', '#4d7c0f')}
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse">
                            ${tableHead({ label: 'Counter / Section' }, { label: 'Items', right: true }, { label: 'Sales', right: true })}
                            <tbody>${lubeRows || '<tr><td colspan="3" style="padding:12px;text-align:center;color:#94a3b8;font-size:11px">No lubricant sections</td></tr>'}</tbody>
                            <tfoot><tr style="background:linear-gradient(90deg,#f7fee7,#ecfccb)"><td colspan="2" style="padding:9px 12px;font-size:12px;font-weight:800;color:#365314">TOTAL LUBRICANT SALES</td><td style="padding:9px 12px;text-align:right;font-size:12px;font-weight:800;color:#4d7c0f">${fmtN(this.lubeTotalAmount)}</td></tr></tfoot>
                        </table>
                    </div>
                </div>`;

            // ── 6. EXPENSES ──
            const expenseRows = this.expenseCategories.map(cat => {
                const bal = this.expenseCatBalance(cat);
                return `<tr><td style="padding:6px 12px;font-size:12px;font-weight:600">${esc(cat.category_name || cat.name || 'Uncategorised')}</td><td style="padding:6px 12px;text-align:right;font-size:12px">${(cat.ledger || []).length}</td><td style="padding:6px 12px;text-align:right;font-size:12px;font-weight:700;color:#be123c">${fmtN(bal)}</td></tr>`;
            }).join('');
            const expensesHTML = `
                <div class="rpt-section">
                    ${sectionHeader('expense', 'Expenses', '#be123c')}
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse">
                            ${tableHead({ label: 'Category' }, { label: 'Entries', right: true }, { label: 'Net Balance', right: true })}
                            <tbody>${expenseRows || '<tr><td colspan="3" style="padding:12px;text-align:center;color:#94a3b8;font-size:11px">No expenses recorded</td></tr>'}</tbody>
                            <tfoot><tr style="background:linear-gradient(90deg,#fff1f2,#ffe4e6)"><td colspan="2" style="padding:9px 12px;font-size:12px;font-weight:800;color:#9f1239">TOTAL EXPENSES</td><td style="padding:9px 12px;text-align:right;font-size:12px;font-weight:800;color:#be123c">${fmtN(this.totalExpenses)}</td></tr></tfoot>
                        </table>
                    </div>
                </div>`;

            // ── 7. DEBTORS ──
            const debtorRows = this.debtorAccounts.map(acct => {
                const bal = this.debtorBalance(acct);
                return `<tr><td style="padding:6px 12px;font-size:12px;font-weight:600">${esc(acct.customer_name || acct.account_name || acct.name || 'Unknown')}</td><td style="padding:6px 12px;text-align:right;font-size:12px">${(acct.ledger || []).length}</td><td style="padding:6px 12px;text-align:right;font-size:12px;font-weight:700;color:${bal > 0 ? '#b45309' : '#059669'}">${fmtN(Math.abs(bal))} ${bal > 0 ? '(DR)' : '(CR)'}</td></tr>`;
            }).join('');
            const debtorsHTML = `
                <div class="rpt-section">
                    ${sectionHeader('debtor', 'Debtors', '#b45309')}
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse">
                            ${tableHead({ label: 'Account' }, { label: 'Entries', right: true }, { label: 'Balance', right: true })}
                            <tbody>${debtorRows || '<tr><td colspan="3" style="padding:12px;text-align:center;color:#94a3b8;font-size:11px">No debtor accounts</td></tr>'}</tbody>
                            <tfoot><tr style="background:linear-gradient(90deg,#fffbeb,#fef3c7)"><td colspan="2" style="padding:9px 12px;font-size:12px;font-weight:800;color:#92400e">TOTAL OUTSTANDING</td><td style="padding:9px 12px;text-align:right;font-size:12px;font-weight:800;color:#b45309">${fmtN(this.totalDebtors)}</td></tr></tfoot>
                        </table>
                    </div>
                </div>`;

            // ── 8. VARIANCE SUMMARY ──
            const variance = this.reportVariance;
            const tankVsPump = this.totalTankDiff - this.totalPumpLitres;
            const varianceHTML = `
                <div class="rpt-section">
                    ${sectionHeader('variance', 'Variance Summary', '#6d28d9')}
                    <div style="padding:14px 18px">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                            <div style="padding:14px;background:${variance === 0 ? '#f0fdf4' : '#fef2f2'};border-radius:10px;border:1px solid ${variance === 0 ? '#bbf7d0' : '#fecaca'}">
                                <div style="font-size:11px;font-weight:700;color:${variance === 0 ? '#166534' : '#991b1b'};text-transform:uppercase;margin-bottom:4px;letter-spacing:.4px">Sales Variance</div>
                                <div style="font-size:12px;color:#475569">Payment/Declared − Pump Sales</div>
                                <div style="font-size:16px;font-weight:800;color:${variance === 0 ? '#166534' : variance > 0 ? '#0369a1' : '#dc2626'};margin-top:6px">${fmtN(variance)} ${variance === 0 ? '&#10004; BALANCED' : variance > 0 ? '&#9650; OVER' : '&#9660; SHORT'}</div>
                            </div>
                            <div style="padding:14px;background:${Math.abs(tankVsPump) < 0.01 ? '#f0fdf4' : '#fef2f2'};border-radius:10px;border:1px solid ${Math.abs(tankVsPump) < 0.01 ? '#bbf7d0' : '#fecaca'}">
                                <div style="font-size:11px;font-weight:700;color:${Math.abs(tankVsPump) < 0.01 ? '#166534' : '#991b1b'};text-transform:uppercase;margin-bottom:4px;letter-spacing:.4px">Tank vs Pump</div>
                                <div style="font-size:12px;color:#475569">Tank Diff − Pump Litres</div>
                                <div style="font-size:16px;font-weight:800;color:${Math.abs(tankVsPump) < 0.01 ? '#166534' : tankVsPump > 0 ? '#0369a1' : '#dc2626'};margin-top:6px">${fmtL(tankVsPump)} L ${Math.abs(tankVsPump) < 0.01 ? '&#10004; OK' : tankVsPump > 0 ? '&#9650; EXCESS' : '&#9660; SHORT'}</div>
                            </div>
                        </div>
                        <div style="margin-top:14px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px;text-align:center">
                            <div style="padding:10px;background:linear-gradient(135deg,#eff6ff,#dbeafe);border-radius:8px"><div style="font-size:10px;color:#64748b;font-weight:600;letter-spacing:.3px">PAYMENT/DECLARED</div><div style="font-size:13px;font-weight:800;color:#2563eb;margin-top:2px">${fmtN(this.systemSalesTotal)}</div></div>
                            <div style="padding:10px;background:linear-gradient(135deg,#fff7ed,#ffedd5);border-radius:8px"><div style="font-size:10px;color:#64748b;font-weight:600;letter-spacing:.3px">PUMP SALES</div><div style="font-size:13px;font-weight:800;color:#ea580c;margin-top:2px">${fmtN(this.totalPumpSales)}</div></div>
                            <div style="padding:10px;background:linear-gradient(135deg,#fef2f2,#fee2e2);border-radius:8px"><div style="font-size:10px;color:#64748b;font-weight:600;letter-spacing:.3px">EXPENSES</div><div style="font-size:13px;font-weight:800;color:#be123c;margin-top:2px">${fmtN(this.totalExpenses)}</div></div>
                            <div style="padding:10px;background:linear-gradient(135deg,#fffbeb,#fef3c7);border-radius:8px"><div style="font-size:10px;color:#64748b;font-weight:600;letter-spacing:.3px">DEBTORS</div><div style="font-size:13px;font-weight:800;color:#b45309;margin-top:2px">${fmtN(this.totalDebtors)}</div></div>
                        </div>
                    </div>
                </div>`;

            // ── CSS ──
            const css = `
                *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
                body{margin:0;font-family:'Segoe UI',system-ui,-apple-system,Arial,sans-serif;background:#f8fafc;color:#1e293b;-webkit-font-smoothing:antialiased}
                .rpt-wrap{max-width:820px;margin:0 auto;padding:24px}
                .rpt-cover{background:linear-gradient(135deg,#1e3a5f 0%,#0f172a 60%,#1e3a5f 100%);color:#fff;padding:56px 40px 40px;border-radius:14px;margin-bottom:28px;position:relative;overflow:hidden;page-break-after:always}
                .rpt-cover::before{content:'';position:absolute;top:-60px;right:-60px;width:240px;height:240px;border-radius:50%;background:rgba(255,255,255,.04)}
                .rpt-cover::after{content:'';position:absolute;bottom:-40px;left:40px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.03)}
                .rpt-cover .badge{display:inline-block;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:4px 14px;font-size:10px;font-weight:700;letter-spacing:1px;color:rgba(255,255,255,.85);margin-bottom:24px;text-transform:uppercase}
                .rpt-cover h1{font-size:26px;font-weight:900;margin:0 0 8px;letter-spacing:-.5px;line-height:1.2}
                .rpt-cover .subtitle{font-size:13px;color:rgba(255,255,255,.65);margin:0 0 32px}
                .rpt-cover .meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;background:rgba(255,255,255,.08);border-radius:10px;padding:18px}
                .rpt-cover .meta-item label{font-size:9px;font-weight:700;letter-spacing:1px;color:rgba(255,255,255,.5);text-transform:uppercase;display:block;margin-bottom:2px}
                .rpt-cover .meta-item span{font-size:12px;font-weight:600;color:#fff}
                .rpt-cover .gold-line{height:3px;background:linear-gradient(90deg,#f59e0b,#fbbf24,#f59e0b);border-radius:2px;margin:24px 0 0}
                .rpt-section{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:22px;break-inside:avoid;box-shadow:0 1px 4px rgba(0,0,0,.04)}
                .rpt-section table{width:100%;border-collapse:collapse}
                .rpt-section tr:nth-child(even){background:#fafbfc}
                .rpt-section td{transition:background .15s}
                @media print{
                    body{background:#fff}
                    .rpt-wrap{padding:0}
                    .no-print{display:none!important}
                }`;

            const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><style>${css}</style></head><body>
                <div class="rpt-wrap">
                    <div class="rpt-cover">
                        <div class="badge">${esc(company)} &nbsp;|&nbsp; CONFIDENTIAL</div>
                        <h1>${esc(coverTitle)}</h1>
                        <p class="subtitle">${esc(station)}</p>
                        <div class="meta-grid">
                            <div class="meta-item"><label>Reporting Period</label><span>${esc(period)}</span></div>
                            <div class="meta-item"><label>Station</label><span>${esc(station)}</span></div>
                            <div class="meta-item"><label>Prepared By</label><span>${esc(preparer)}</span></div>
                            <div class="meta-item"><label>Reviewed By</label><span>${esc(reviewer || '—')}</span></div>
                            <div class="meta-item"><label>Generated</label><span>${genDate} ${genTime}</span></div>
                            <div class="meta-item"><label>Status</label><span>${esc(s.status ? s.status.toUpperCase() : 'DRAFT')}</span></div>
                        </div>
                        <div class="gold-line"></div>
                        ${coverNotes ? `<p style="margin:14px 0 0;font-size:11px;color:rgba(255,255,255,.7);font-style:italic">${esc(coverNotes)}</p>` : ''}
                    </div>
                    ${(() => {
                    const mc = this.monthCloseout;
                    const surplusColor = mc.surplus >= 0 ? '#059669' : '#dc2626';
                    const surplusLabel = mc.surplus >= 0 ? 'SURPLUS' : 'DEFICIT';
                    const expLines = mc.expenseLines.map(e => `
                            <tr><td style="padding:5px 12px;font-size:11px;color:#64748b;padding-left:28px">Add: ${esc(e.name)}</td><td style="padding:5px 12px;text-align:right;font-size:11px;font-weight:700;color:#1e293b">${fmtN(e.amount)}</td><td style="padding:5px 12px;text-align:right;font-size:11px;color:#94a3b8">—</td></tr>`).join('');
                    return `
                        <div class="rpt-section" style="margin-bottom:20px">
                            ${sectionHeader('closeout', 'Month Close-Out — Financial Reconciliation', '#334155')}
                            <div style="overflow-x:auto">
                                <table style="width:100%;border-collapse:collapse">
                                    <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0">
                                        <th style="padding:7px 12px;text-align:left;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px">Description</th>
                                        <th style="padding:7px 12px;text-align:right;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;width:140px">Debit (₦)</th>
                                        <th style="padding:7px 12px;text-align:right;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;width:140px">Credit (₦)</th>
                                    </tr></thead>
                                    <tbody>
                                        <tr><td style="padding:7px 12px;font-size:12px;font-weight:700;color:#1e293b"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#2563eb;margin-right:8px;vertical-align:middle"></span>System Sales</td><td style="padding:7px 12px;text-align:right;font-size:12px;color:#94a3b8">—</td><td style="padding:7px 12px;text-align:right;font-size:12px;font-weight:700;color:#1e293b">${fmtN(mc.systemSales)}</td></tr>
                                        <tr style="background:#fef2f2"><td style="padding:7px 12px;font-size:12px;color:#dc2626;font-weight:600"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc2626;margin-right:8px;vertical-align:middle"></span>Less: Bank Deposit</td><td style="padding:7px 12px;text-align:right;font-size:12px;font-weight:700;color:#dc2626">– ${fmtN(mc.bankDeposit)}</td><td style="padding:7px 12px;text-align:right;font-size:12px;color:#94a3b8">—</td></tr>
                                        <tr style="background:#f1f5f9"><td style="padding:9px 12px;font-size:13px;font-weight:800;color:#0f172a">Total Balance</td><td style="padding:9px 12px;text-align:right;font-size:12px;color:#94a3b8">—</td><td style="padding:9px 12px;text-align:right;font-size:13px;font-weight:800;color:#0f172a">${fmtN(mc.totalBalance)}</td></tr>
                                        ${mc.expenseLines.length > 0 ? `
                                        <tr><td colspan="3" style="padding:0"><div style="height:1px;background:#e2e8f0"></div></td></tr>
                                        ${expLines}` : `
                                        <tr style="background:#fff1f2"><td style="padding:7px 12px;font-size:11px;font-weight:600;color:#94a3b8"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#cbd5e1;margin-right:8px;vertical-align:middle"></span><em>No expense categories entered</em></td><td style="padding:7px 12px;text-align:right;font-size:11px;color:#94a3b8">${fmtN(0)}</td><td style="padding:7px 12px;text-align:right;font-size:11px;color:#94a3b8">—</td></tr>`}
                                        <tr><td colspan="3" style="padding:0"><div style="height:1px;background:#e2e8f0"></div></td></tr>
                                        <tr><td style="padding:7px 12px;font-size:12px;font-weight:600;color:#1e293b"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#7c3aed;margin-right:8px;vertical-align:middle"></span>Add: POS, Transfer Sales</td><td style="padding:7px 12px;text-align:right;font-size:12px;font-weight:700;color:#1e293b">${fmtN(mc.posTransferSales)}</td><td style="padding:7px 12px;text-align:right;font-size:12px;color:#94a3b8">—</td></tr>
                                        <tr><td style="padding:7px 12px;font-size:12px;font-weight:600;color:#1e293b"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#059669;margin-right:8px;vertical-align:middle"></span>Add: Cash At Hand</td><td style="padding:7px 12px;text-align:right;font-size:12px;font-weight:700;color:#1e293b">${fmtN(mc.cashAtHand)}</td><td style="padding:7px 12px;text-align:right;font-size:12px;color:#94a3b8">—</td></tr>
                                        <tr><td style="padding:7px 12px;font-size:12px;font-weight:600;color:#1e293b"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#4d7c0f;margin-right:8px;vertical-align:middle"></span>Add: Lube Stock Unsold</td><td style="padding:7px 12px;text-align:right;font-size:12px;font-weight:700;color:#1e293b">${fmtN(mc.lubeUnsold)}</td><td style="padding:7px 12px;text-align:right;font-size:12px;color:#94a3b8">—</td></tr>
                                        <tr><td style="padding:7px 12px;font-size:12px;font-weight:600;color:#1e293b"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#b45309;margin-right:8px;vertical-align:middle"></span>Add: Receivables/Debtors</td><td style="padding:7px 12px;text-align:right;font-size:12px;font-weight:700;color:#1e293b">${fmtN(mc.receivables)}</td><td style="padding:7px 12px;text-align:right;font-size:12px;color:#94a3b8">—</td></tr>
                                        <tr style="background:#f1f5f9"><td style="padding:9px 12px;font-size:13px;font-weight:800;color:#0f172a">Total</td><td style="padding:9px 12px;text-align:right;font-size:13px;font-weight:800;color:#0f172a">${fmtN(mc.expectedTotal)}</td><td style="padding:9px 12px;text-align:right;font-size:12px;color:#94a3b8">—</td></tr>
                                        <tr style="background:${mc.surplus >= 0 ? '#f0fdf4' : '#fef2f2'}"><td style="padding:11px 12px;font-size:13px;font-weight:800;color:${surplusColor}">${surplusLabel}</td><td colspan="2" style="padding:11px 12px;text-align:right;font-size:15px;font-weight:900;color:${surplusColor}">${fmtN(Math.abs(mc.surplus))}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>`;
                })()}
                    ${sysSalesHTML}
                    ${pumpSalesHTML}
                    ${tankHTML}
                    ${haulageHTML}
                    ${expensesHTML}
                    ${debtorsHTML}
                    ${lubricantHTML}
                    ${varianceHTML}
                    <div style="text-align:center;padding:22px 0;color:#94a3b8;font-size:11px;border-top:2px solid #f1f5f9;margin-top:28px;letter-spacing:.3px">
                        Generated by MIAUDITOPS &mdash; ${genDate} at ${genTime} &mdash; CONFIDENTIAL
                    </div>
                </div></body></html>`;

            return { html, station, dateFrom, dateTo };
        },

        // ── Preview Report (fullscreen overlay) ──
        previewReportPDF() {
            const { html } = this._buildReportHTML();
            const existing = document.getElementById('rpt-preview-overlay');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.id = 'rpt-preview-overlay';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.9);backdrop-filter:blur(8px);display:flex;flex-direction:column;';
            overlay.innerHTML = `
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 20px;background:linear-gradient(135deg,#1e293b,#0f172a);border-bottom:1px solid rgba(255,255,255,.1);flex-shrink:0">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div style="width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#7c3aed,#6d28d9);display:flex;align-items:center;justify-content:center;font-size:16px">📊</div>
                        <div>
                            <h3 style="color:#fff;font-size:13px;font-weight:800;margin:0">Report Preview</h3>
                            <p style="color:#94a3b8;font-size:10px;margin:0">Scroll to review all sections &bull; Zoom to adjust size</p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <button id="rpt-zoom-out" style="width:30px;height:30px;border-radius:6px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:#fff;cursor:pointer;font-size:15px;font-weight:700">−</button>
                        <span id="rpt-zoom-label" style="color:#94a3b8;font-size:11px;min-width:38px;text-align:center">100%</span>
                        <button id="rpt-zoom-in" style="width:30px;height:30px;border-radius:6px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:#fff;cursor:pointer;font-size:15px;font-weight:700">+</button>
                        <div style="width:1px;height:24px;background:rgba(255,255,255,.1);margin:0 4px"></div>
                        <button id="rpt-preview-download" style="padding:7px 14px;border-radius:8px;background:linear-gradient(135deg,#7c3aed,#6d28d9);border:none;color:#fff;cursor:pointer;font-size:11px;font-weight:700">⬇ Download PDF</button>
                        <button id="rpt-preview-close" style="width:30px;height:30px;border-radius:6px;background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.3);color:#fca5a5;cursor:pointer;font-size:16px;line-height:1">×</button>
                    </div>
                </div>
                <div style="flex:1;overflow-y:auto;padding:24px;display:flex;justify-content:center">
                    <div id="rpt-preview-scaler" style="transform-origin:top center;transition:transform .2s">
                        <iframe id="rpt-preview-frame" style="width:860px;border:none;border-radius:12px;box-shadow:0 24px 64px rgba(0,0,0,.5);background:#fff;" scrolling="no"></iframe>
                    </div>
                </div>`;

            document.body.appendChild(overlay);

            const frame = document.getElementById('rpt-preview-frame');
            frame.srcdoc = html;
            frame.onload = () => {
                frame.style.height = (frame.contentDocument.body.scrollHeight + 32) + 'px';
            };

            let zoom = 100;
            const scaler = document.getElementById('rpt-preview-scaler');
            const label = document.getElementById('rpt-zoom-label');
            const updateZoom = () => { scaler.style.transform = `scale(${zoom / 100})`; label.textContent = zoom + '%'; };

            document.getElementById('rpt-zoom-in').addEventListener('click', () => { if (zoom < 150) { zoom += 10; updateZoom(); } });
            document.getElementById('rpt-zoom-out').addEventListener('click', () => { if (zoom > 50) { zoom -= 10; updateZoom(); } });
            document.getElementById('rpt-preview-close').addEventListener('click', () => overlay.remove());
            document.getElementById('rpt-preview-download').addEventListener('click', () => this.downloadReportPDF());

            const esc = (e) => { if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', esc); } };
            document.addEventListener('keydown', esc);
        },

        // ── Download Report PDF ──
        downloadReportPDF() {
            const { html, station, dateFrom, dateTo } = this._buildReportHTML();
            const pw = window.open('', '_blank');
            if (!pw) { this.toast('Pop-up blocked — please allow pop-ups for this site', false); return; }
            pw.document.write(html);
            pw.document.close();
            pw.onload = () => { pw.focus(); pw.print(); };
        },

        // ══════════════════════════════════════════════
        // ── FINAL REPORT — Professional PDF Builder ──
        // ══════════════════════════════════════════════
        _buildFinalReportHTML() {
            const N = window.__NAIRA || '\u20A6';
            const s = this.sessionData?.session || {};
            const station = s.outlet_name || 'Station';
            const dateFrom = s.date_from || '--';
            const dateTo = s.date_to || '--';
            const company = window.__SA_COMPANY || 'MIAUDITOPS';
            const userName = this.finalReportCover.preparedBy || (window.__SA_USER?.name || 'Auditor');
            const userRole = window.__SA_USER?.role || 'Auditor';
            const coverTitle = this.finalReportCover.title || 'RECONCILIATION REPORT';
            const coverSubtitle = this.finalReportCover.subtitle || 'Audit Close-Out';
            const preparedFor = this.finalReportCover.preparedFor || 'Operations Department';
            const coverNotes = this.finalReportCover.notes || '';
            const status = s.status || 'draft';
            const genDate = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' });
            const esc = v => this._esc(v);
            const fmt = n => N + ' ' + (parseFloat(n) || 0).toLocaleString('en', { minimumFractionDigits: 2 });
            const fmtL = n => (parseFloat(n) || 0).toLocaleString('en', { minimumFractionDigits: 2 });
            const fmtPct = n => (parseFloat(n) || 0).toFixed(1) + '%';

            const mc = this.monthCloseout;
            const auditSales = mc.systemSales;
            const bankDeposit = mc.bankDeposit;
            const totalExpenses = mc.totalExpenses;
            const cashAtHand = mc.cashAtHand;
            const receivables = mc.receivables;
            const accounted = mc.expectedTotal;  // auto-sum of all Add items
            const variance = mc.surplus;          // expectedTotal − totalBalance
            const totalPages = 5 + 1 + (this.finalReportIncludePhotos && (this.systemSales.teller_proof_url || this.systemSales.pos_proof_url) ? 1 : 0);


            // ── Shared page footer helper ──
            const pageFooter = (pg) => `
                <div style="position:absolute;bottom:55px;left:75px;right:75px;border-top:1px solid #f1f5f9;padding-top:12px;display:flex;justify-content:space-between;align-items:center;opacity:.5">
                    <p style="font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:2px">Generated by ${esc(company)}</p>
                    <p style="font-size:8px;font-family:'JetBrains Mono',monospace">PAGE ${String(pg).padStart(2, '0')} OF ${String(totalPages).padStart(2, '0')}</p>
                </div>`;

            // ── Shared section title helper ──
            const sectionTitle = (num, title, sub) => `
                <h3 style="font-size:16px;font-weight:900;color:#000;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-end">
                    <span>${String(num).padStart(2, '0')}. ${esc(title).toUpperCase()}</span>
                    <span style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:2px">${esc(sub)}</span>
                </h3>`;

            // ── Shared table helper ──
            const tbl = (headers, bodyRows, footerRow) => {
                const ths = headers.map(h => `<th style="text-align:${h.right ? 'right' : 'left'};background:#000;color:#fff;text-transform:uppercase;font-size:10px;letter-spacing:.05em;padding:8px 12px;font-weight:700">${h.label}</th>`).join('');
                const foot = footerRow ? `<tfoot><tr style="background:#f8fafc;border-top:2px solid #000">${footerRow}</tr></tfoot>` : '';
                return `<table style="width:100%;border-collapse:collapse"><thead><tr>${ths}</tr></thead><tbody>${bodyRows}</tbody>${foot}</table>`;
            };

            const css = `
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;700&display=swap');
                * { margin:0; padding:0; box-sizing:border-box; }
                body { font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif; color:#1e293b; background:#fff; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
                .page { width:210mm; min-height:297mm; padding:18mm 20mm; margin:0 auto; background:#fff; position:relative; page-break-after:always; }
                .page:last-child { page-break-after:avoid; }
                .font-mono { font-family:'JetBrains Mono',monospace; }
                @media print { body { background:none; } .page { margin:0; width:100%; } }
                @page { size:A4; margin:0; }
            `;

            // ══════════════════════════════════════════
            // PAGE 1: COVER
            // ══════════════════════════════════════════
            const page1 = `
            <div class="page" style="display:flex;flex-direction:column;justify-content:space-between">
                <div style="border-bottom:4px solid #000;padding-bottom:32px;display:flex;justify-content:space-between;align-items:flex-start">
                    <div>
                        <h2 style="font-size:28px;font-weight:900;color:#000;line-height:1;margin-bottom:8px;letter-spacing:-.5px">${esc(company)}</h2>
                        <p style="font-size:10px;font-weight:700;color:#64748b;letter-spacing:3px;text-transform:uppercase">Enterprise Station Auditing</p>
                    </div>
                    <div style="text-align:right">
                        <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px">Confidential Document</p>
                        <p style="font-size:11px;font-weight:700;color:#000;margin-top:2px">REF: ${esc(s.id || '--')}</p>
                    </div>
                </div>
                <div style="padding:80px 0;text-align:center">
                    <div style="display:inline-block;padding:6px 20px;border:2px solid #000;margin-bottom:32px">
                        <span style="font-size:12px;font-weight:900;letter-spacing:5px;text-transform:uppercase">${esc(coverSubtitle)}</span>
                    </div>
                    <h1 style="font-size:48px;font-weight:900;color:#000;letter-spacing:-2px;line-height:1.1;margin-bottom:16px">${esc(coverTitle)}</h1>
                    <div style="width:96px;height:8px;background:#f59e0b;margin:40px auto;border-radius:4px"></div>
                    <div style="margin-top:16px">
                        <h3 style="font-size:22px;font-weight:700;color:#1e293b">${esc(station)}</h3>
                        <p style="color:#64748b;font-family:'JetBrains Mono',monospace;font-size:13px;margin-top:4px">${esc(dateFrom)} — ${esc(dateTo)}</p>
                    </div>
                    ${coverNotes ? `<p style="margin-top:20px;font-size:11px;color:#64748b;font-style:italic;max-width:400px;margin-left:auto;margin-right:auto">${esc(coverNotes)}</p>` : ''}
                </div>
                <div style="border-top:2px solid #000;padding-top:40px;display:grid;grid-template-columns:1fr 1fr;gap:48px">
                    <div>
                        <div style="margin-bottom:16px">
                            <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Prepared For</p>
                            <p style="font-size:16px;font-weight:700;color:#000">${esc(preparedFor)}</p>
                        </div>
                        <div>
                            <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Prepared By</p>
                            <p style="font-size:16px;font-weight:700;color:#000">${esc(userName)}</p>
                        </div>
                    </div>
                    <div style="text-align:right">
                        <div style="margin-bottom:16px">
                            <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Date Generated</p>
                            <p style="font-size:16px;font-weight:700;color:#000">${genDate}</p>
                        </div>
                        <div>
                            <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:4px">Status</p>
                            <span style="display:inline-block;padding:4px 12px;background:#000;color:#fff;font-size:10px;font-weight:700;border-radius:4px;text-transform:uppercase">${esc(status)}</span>
                        </div>
                    </div>
                </div>
            </div>`;

            // ══════════════════════════════════════════
            // PAGE 2: SECTION 01 — FINANCIAL RECONCILIATION
            // ══════════════════════════════════════════
            const surplusLabel = variance >= 0 ? 'Surplus' : 'Deficit';
            const varianceColor = variance >= 0 ? '#059669' : '#e11d48';
            const page2 = `
            <div class="page">
                ${sectionTitle(1, 'Financial Reconciliation', 'Settlement Summary')}
                <div style="margin-bottom:28px">
                    <div style="background:#f8fafc;padding:20px;border-left:4px solid #000;display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;text-align:center">
                        <div>
                            <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Audit Sales</p>
                            <p style="font-size:16px;font-weight:900;color:#000">${fmt(auditSales)}</p>
                        </div>
                        <div>
                            <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Total Added</p>
                            <p style="font-size:16px;font-weight:900;color:#000">${fmt(accounted)}</p>
                        </div>
                        <div>
                            <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">${esc(surplusLabel)}</p>
                            <p style="font-size:16px;font-weight:900;color:${varianceColor}">${fmt(Math.abs(variance))}</p>
                        </div>
                    </div>
                </div>
                ${tbl(
                [{ label: 'Description' }, { label: 'Debit (DR)', right: true }, { label: 'Credit (CR)', right: true }],
                `<tr style="background:#f8fafc"><td style="padding:8px 12px;font-weight:700;font-size:11px">System Sales</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px">—</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px">${fmt(auditSales)}</td></tr>
                     <tr><td style="padding:8px 12px;padding-left:32px;font-size:11px;font-weight:500;color:#dc2626">Less: Bank Deposit</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px;color:#dc2626">– ${fmt(bankDeposit)}</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px">—</td></tr>
                     <tr style="background:#f1f5f9"><td style="padding:9px 12px;font-size:12px;font-weight:800;color:#0f172a">Total Balance</td><td class="font-mono" style="text-align:right;padding:9px 12px;font-size:11px;color:#94a3b8">—</td><td class="font-mono" style="text-align:right;padding:9px 12px;font-size:12px;font-weight:800;color:#0f172a">${fmt(auditSales - bankDeposit)}</td></tr>
                     <tr><td colspan="3" style="padding:0"><div style="height:1px;background:#e2e8f0"></div></td></tr>
                     ${mc.expenseLines.length > 0 ? mc.expenseLines.map(e => `<tr><td style="padding:6px 12px;padding-left:32px;font-size:11px;font-weight:500">Add: ${esc(e.name)}</td><td class="font-mono" style="text-align:right;padding:6px 12px;font-size:11px">${fmt(e.amount)}</td><td class="font-mono" style="text-align:right;padding:6px 12px;font-size:11px;color:#94a3b8">—</td></tr>`).join('') : `<tr><td style="padding:6px 12px;padding-left:32px;font-size:11px;font-weight:500;color:#94a3b8"><em>No expense categories</em></td><td class="font-mono" style="text-align:right;padding:6px 12px;font-size:11px;color:#94a3b8">${fmt(0)}</td><td class="font-mono" style="text-align:right;padding:6px 12px;font-size:11px;color:#94a3b8">—</td></tr>`}
                     ${mc.posTransferSales > 0 ? `<tr><td style="padding:8px 12px;padding-left:32px;font-size:11px;font-weight:500">Add: POS, Transfer Sales</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px">${fmt(mc.posTransferSales)}</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px;color:#94a3b8">—</td></tr>` : ''}
                     <tr><td style="padding:8px 12px;padding-left:32px;font-size:11px;font-weight:500">Add: Cash At Hand</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px">${fmt(cashAtHand)}</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px;color:#94a3b8">—</td></tr>
                     ${mc.lubeUnsold > 0 ? `<tr><td style="padding:8px 12px;padding-left:32px;font-size:11px;font-weight:500">Add: Lube Stock Unsold</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px">${fmt(mc.lubeUnsold)}</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px;color:#94a3b8">—</td></tr>` : ''}
                     <tr><td style="padding:8px 12px;padding-left:32px;font-size:11px;font-weight:500">Add: Receivables/Debtors</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px">${fmt(receivables)}</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-size:11px;color:#94a3b8">—</td></tr>
                     <tr style="background:#f1f5f9"><td style="padding:9px 12px;font-size:12px;font-weight:800;color:#0f172a">Total</td><td class="font-mono" style="text-align:right;padding:9px 12px;font-size:12px;font-weight:800;color:#0f172a">${fmt(accounted)}</td><td class="font-mono" style="text-align:right;padding:9px 12px;font-size:11px;color:#94a3b8">—</td></tr>`,
                `<td style="padding:12px;font-weight:800;font-size:11px;text-transform:uppercase;letter-spacing:1px;background:#000;color:#fff">${surplusLabel}</td><td class="font-mono" style="text-align:right;padding:12px;font-weight:800;font-size:13px;color:${variance >= 0 ? '#6ee7b7' : '#fda4af'};background:#000" colspan="2">${fmt(Math.abs(variance))}</td>`
            )}

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;padding-top:24px">
                    <div>
                        <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;text-decoration:underline;margin-bottom:12px">Audit Analysis</h4>
                        <p style="font-size:10px;line-height:1.7;color:#64748b;font-style:italic">
                            The reconciliation compares the grand total of all added items (${fmt(accounted)}) against the total balance (${fmt(mc.totalBalance)}).
                            ${variance < 0 ? `A deficit of <strong style="color:#e11d48">${fmt(Math.abs(variance))}</strong> requires immediate management review.` : `A surplus of <strong style="color:#059669">${fmt(variance)}</strong>.`}
                        </p>
                    </div>
                    <div>
                        <div style="border-bottom:1px solid #e2e8f0;padding-bottom:8px">
                            <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:3px;margin-bottom:16px">Authored Signature</p>
                            <div style="height:32px"></div>
                            <p style="font-size:11px;font-weight:700;color:#000">${esc(userName)}</p>
                            <p style="font-size:9px;color:#64748b;text-transform:uppercase">Name</p>
                        </div>
                    </div>
                </div>
                ${pageFooter(2)}
            </div>`;

            // ══════════════════════════════════════════
            // PAGE 3: SECTION 02 (Pump & Tank) + 03 (Expenses) + 04 (Receivables)
            // ══════════════════════════════════════════

            // Pump breakdown
            const pumpGroups = {};
            this.pumpTables.forEach(pt => {
                const prod = pt.product || 'PMS';
                const litres = this.pumpTableLitres(pt);
                const amount = this.pumpTableAmount(pt);
                const rate = parseFloat(pt.rate) || 0;
                if (!pumpGroups[prod]) pumpGroups[prod] = { product: prod, litres: 0, amount: 0, rate: 0, count: 0 };
                pumpGroups[prod].litres += litres;
                pumpGroups[prod].amount += amount;
                pumpGroups[prod].rate += rate;
                pumpGroups[prod].count++;
            });
            const pumpBreakdown = Object.values(pumpGroups).filter(p => p.litres > 0 || p.amount > 0).map(p => ({
                product: p.product, litres: p.litres, rate: p.count > 0 ? p.rate / p.count : 0, amount: p.amount
            }));
            const pumpTotal = pumpBreakdown.reduce((s, p) => s + p.amount, 0);
            const pumpTotalL = pumpBreakdown.reduce((s, p) => s + p.litres, 0);

            const pumpRows = pumpBreakdown.map(p => `<tr><td style="padding:7px 12px;font-weight:700;font-size:11px;border-bottom:1px solid #e2e8f0">${esc(p.product)}</td><td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;border-bottom:1px solid #e2e8f0">${fmtL(p.litres)}</td><td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;border-bottom:1px solid #e2e8f0">${fmt(p.rate)}</td><td class="font-mono" style="text-align:right;padding:7px 12px;font-weight:700;font-size:11px;border-bottom:1px solid #e2e8f0">${fmt(p.amount)}</td></tr>`).join('');

            // Tank records
            const tankRecords = this.tankProductTotals;
            const tankRows = tankRecords.map(t => `<tr><td style="padding:7px 12px;font-weight:700;font-size:11px;border-bottom:1px solid #e2e8f0">${esc(t.product)}${t.isLpg ? ' (kg)' : ''}</td><td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;border-bottom:1px solid #e2e8f0">${fmtL(t.opening)}</td><td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;border-bottom:1px solid #e2e8f0">${fmtL(t.added)}</td><td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;border-bottom:1px solid #e2e8f0">${fmtL(t.closing)}</td><td class="font-mono" style="text-align:right;padding:7px 12px;font-weight:700;font-size:11px;border-bottom:1px solid #e2e8f0">${fmtL(t.diff)}</td></tr>`).join('');

            // Expense breakdown
            const expenseRows = this.expenseCategories.map(cat => {
                const bal = this.expenseCatBalance(cat);
                const entries = (cat.ledger || []).length;
                return `<tr><td style="padding:7px 12px;font-weight:600;font-size:11px;border-bottom:1px solid #e2e8f0">${esc(cat.category_name || cat.name || 'Uncategorised')}</td><td class="font-mono" style="text-align:center;padding:7px 12px;font-size:11px;border-bottom:1px solid #e2e8f0">${entries}</td><td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;font-weight:700;color:#be123c;border-bottom:1px solid #e2e8f0">${fmt(bal)}</td></tr>`;
            }).join('');

            // Debtor breakdown
            const debtorRows = this.debtorAccounts.map(acct => {
                const bal = this.debtorBalance(acct);
                const entries = (acct.ledger || []).length;
                const color = bal > 0 ? '#b45309' : '#059669';
                const tag = bal > 0 ? 'DR' : 'CR';
                return `<tr><td style="padding:7px 12px;font-weight:600;font-size:11px;border-bottom:1px solid #e2e8f0">${esc(acct.customer_name || acct.account_name || acct.name || 'Unknown')}</td><td class="font-mono" style="text-align:center;padding:7px 12px;font-size:11px;border-bottom:1px solid #e2e8f0">${entries}</td><td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;font-weight:700;color:${color};border-bottom:1px solid #e2e8f0">${fmt(Math.abs(bal))} (${tag})</td></tr>`;
            }).join('');

            const page3 = `
            <div class="page">
                ${sectionTitle(2, 'Pump Sales Breakdown', 'Revenue by Product')}
                <div style="margin-bottom:28px">
                    ${tbl(
                [{ label: 'Product' }, { label: 'Volume (L)', right: true }, { label: 'Avg Rate', right: true }, { label: 'Revenue', right: true }],
                pumpRows,
                `<td style="padding:10px 12px;font-weight:900;font-size:11px;text-transform:uppercase;letter-spacing:1px">Grand Total</td><td class="font-mono" style="text-align:right;padding:10px 12px;font-weight:700;font-size:11px">${fmtL(pumpTotalL)}</td><td style="text-align:right;padding:10px 12px;font-size:11px">—</td><td class="font-mono" style="text-align:right;padding:10px 12px;font-weight:900;font-size:11px">${fmt(pumpTotal)}</td>`
            )}
                </div>

                ${(() => {
                    // Section 02a: Audit Sales Analysis (Pumps) — per-pump detail grouped by product
                    const productOrder = ['PMS', 'AGO', 'DPK', 'LPG'];
                    const grouped = {};
                    this.pumpTables.forEach(pt => {
                        const prod = pt.product || 'PMS';
                        if (!grouped[prod]) grouped[prod] = [];
                        const litres = this.pumpTableLitres(pt);
                        const rate = parseFloat(pt.rate) || 0;
                        const amount = this.pumpTableAmount(pt);
                        if (litres > 0 || amount > 0) {
                            grouped[prod].push({ name: pt.name || 'Pump', litres, rate, amount });
                        }
                    });
                    const sortedProducts = productOrder.filter(p => grouped[p] && grouped[p].length > 0);
                    // Also include any products not in the predefined order
                    Object.keys(grouped).forEach(p => { if (!sortedProducts.includes(p) && grouped[p].length > 0) sortedProducts.push(p); });

                    if (sortedProducts.length === 0) return '';

                    let detailRows = '';
                    let grandLitres = 0, grandAmount = 0;

                    sortedProducts.forEach(prod => {
                        const items = grouped[prod];
                        let subLitres = 0, subAmount = 0;
                        items.forEach(item => {
                            subLitres += item.litres;
                            subAmount += item.amount;
                            detailRows += `<tr>
                                <td style="padding:6px 12px;font-size:11px;border-bottom:1px solid #f1f5f9">${esc(item.name)}</td>
                                <td style="padding:6px 12px;font-size:10px;color:#64748b;border-bottom:1px solid #f1f5f9">${esc(dateFrom)} to ${esc(dateTo)}</td>
                                <td class="font-mono" style="text-align:right;padding:6px 12px;font-size:11px;border-bottom:1px solid #f1f5f9">${fmt(item.rate)}</td>
                                <td class="font-mono" style="text-align:right;padding:6px 12px;font-size:11px;border-bottom:1px solid #f1f5f9">${fmtL(item.litres)}</td>
                                <td class="font-mono" style="text-align:right;padding:6px 12px;font-size:11px;font-weight:600;border-bottom:1px solid #f1f5f9">${fmt(item.amount)}</td>
                            </tr>`;
                        });
                        // Subtotal row
                        detailRows += `<tr style="background:#fef3c7">
                            <td colspan="3" style="padding:7px 12px;font-size:11px;font-weight:800;color:#92400e;border-bottom:2px solid #fcd34d">${esc(prod)} Subtotal</td>
                            <td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;font-weight:700;color:#92400e;border-bottom:2px solid #fcd34d">${fmtL(subLitres)}</td>
                            <td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;font-weight:800;color:#92400e;border-bottom:2px solid #fcd34d">${fmt(subAmount)}</td>
                        </tr>`;
                        grandLitres += subLitres;
                        grandAmount += subAmount;
                    });

                    return `
                    <div style="margin-top:24px;margin-bottom:28px">
                        <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;color:#000;display:flex;align-items:center;gap:6px">
                            <span style="display:inline-block;width:18px;height:18px;border-radius:5px;background:#f59e0b;color:#fff;text-align:center;line-height:18px;font-size:9px;font-weight:900">2a</span>
                            Audit Sales Analysis (Pumps)
                        </h4>
                        ${tbl(
                        [{ label: 'Pump / Nozzle' }, { label: 'Date Range' }, { label: 'Rate (₦)', right: true }, { label: 'Litres', right: true }, { label: 'Amount (₦)', right: true }],
                        detailRows,
                        '<td colspan="3" style="padding:10px 12px;font-weight:900;font-size:11px;text-transform:uppercase;letter-spacing:1px;background:#f0f9ff;color:#0c4a6e;border-top:2px solid #0ea5e9">Grand Total</td><td class="font-mono" style="text-align:right;padding:10px 12px;font-weight:800;font-size:11px;background:#f0f9ff;color:#0c4a6e;border-top:2px solid #0ea5e9">' + fmtL(grandLitres) + '</td><td class="font-mono" style="text-align:right;padding:10px 12px;font-weight:900;font-size:11px;background:#f0f9ff;color:#0c4a6e;border-top:2px solid #0ea5e9">' + fmt(grandAmount) + '</td>'
                    )}
                    </div>`;
                })()}

                <div style="margin-bottom:28px">
                    <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;color:#000">Tank Dipping Records</h4>
                    ${tbl(
                    [{ label: 'Product' }, { label: 'Opening', right: true }, { label: 'Received', right: true }, { label: 'Closing', right: true }, { label: 'Consumption', right: true }],
                    tankRows || '<tr><td colspan="5" style="text-align:center;color:#94a3b8;font-style:italic;padding:14px;font-size:11px">No tank records</td></tr>',
                    ''
                )}
                </div>

                ${sectionTitle(3, 'Expenses Breakdown', 'Operating Costs')}
                <div style="margin-bottom:28px">
                    ${tbl(
                    [{ label: 'Category' }, { label: 'Entries', right: false }, { label: 'Net Balance', right: true }],
                    expenseRows || '<tr><td colspan="3" style="text-align:center;color:#94a3b8;font-style:italic;padding:14px;font-size:11px">No expenses recorded</td></tr>',
                    `<td colspan="2" style="padding:10px 12px;font-weight:900;font-size:11px;text-transform:uppercase;background:#fef2f2;color:#9f1239">Total Expenses</td><td class="font-mono" style="text-align:right;padding:10px 12px;font-weight:900;font-size:11px;color:#be123c;background:#fef2f2">${fmt(totalExpenses)}</td>`
                )}
                </div>

                ${sectionTitle(4, 'Receivables', 'Outstanding Debtors')}
                <div style="margin-bottom:20px">
                    ${tbl(
                    [{ label: 'Account' }, { label: 'Entries', right: false }, { label: 'Balance', right: true }],
                    debtorRows || '<tr><td colspan="3" style="text-align:center;color:#94a3b8;font-style:italic;padding:14px;font-size:11px">No debtor accounts</td></tr>',
                    `<td colspan="2" style="padding:10px 12px;font-weight:900;font-size:11px;text-transform:uppercase;background:#fffbeb;color:#92400e">Total Outstanding</td><td class="font-mono" style="text-align:right;padding:10px 12px;font-weight:900;font-size:11px;color:#b45309;background:#fffbeb">${fmt(receivables)}</td>`
                )}
                </div>
                ${pageFooter(3)}
            </div>`;

            // ══════════════════════════════════════════
            // PAGE 4: SECTION 05 (Variance) + 06 (Lubricants)
            // ══════════════════════════════════════════

            // Variance by product
            const comparison = this.productComparison;
            const varianceRows = comparison.map(p => {
                const vColor = p.variance >= 0 ? '#059669' : '#e11d48';
                const vLabel = p.variance >= 0 ? 'Surplus' : 'Deficit';
                return `<tr>
                    <td style="padding:7px 12px;font-weight:700;font-size:11px;border-bottom:1px solid #e2e8f0">${esc(p.product)}</td>
                    <td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;border-bottom:1px solid #e2e8f0">${fmtL(p.pumpLitres)}</td>
                    <td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;border-bottom:1px solid #e2e8f0">${fmtL(p.tankDiff)}</td>
                    <td class="font-mono" style="text-align:right;padding:7px 12px;font-size:11px;font-weight:700;color:${vColor};border-bottom:1px solid #e2e8f0">${fmtL(Math.abs(p.variance))} <span style="font-size:9px;color:${vColor};font-weight:600">${vLabel}</span></td>
                </tr>`;
            }).join('');

            // Lubricant store items
            const lubeItems = this.lubeStoreItems || [];
            const lubeRows = lubeItems.map(si => {
                const opening = parseFloat(si.opening || 0);
                const received = parseFloat(si.received || 0);
                const issued = this.storeItemIssued(si);
                const closing = this.storeItemClosing(si);
                const price = parseFloat(si.selling_price || 0);
                const value = closing * price;
                return `<tr>
                    <td style="padding:6px 10px;font-weight:600;font-size:10px;border-bottom:1px solid #e2e8f0">${esc(si.item_name || si.product_name || '--')}</td>
                    <td class="font-mono" style="text-align:center;padding:6px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${opening}</td>
                    <td class="font-mono" style="text-align:center;padding:6px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${received}</td>
                    <td class="font-mono" style="text-align:center;padding:6px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${issued}</td>
                    <td class="font-mono" style="text-align:center;padding:6px 10px;font-weight:700;font-size:10px;border-bottom:1px solid #e2e8f0">${closing}</td>
                    <td class="font-mono" style="text-align:right;padding:6px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${fmt(price)}</td>
                    <td class="font-mono" style="text-align:right;padding:6px 10px;font-weight:700;font-size:10px;border-bottom:1px solid #e2e8f0">${fmt(value)}</td>
                </tr>`;
            }).join('');
            const lubeTotal = this.lubeStoreTotalValue;

            // Counter stock evaluation — per-counter breakdown
            const counters = this.lubeSections || [];
            const hasCounters = counters.length > 0 && counters.some(ls => (ls.items || []).length > 0);
            const counterBlocks = counters.map(ls => {
                const items = ls.items || [];
                if (items.length === 0) return '';
                const counterSalesTotal = this.lubeSectionAmount(ls);
                const rows = items.map(it => {
                    const o = parseFloat(it.opening || 0);
                    const r = parseFloat(it.received || 0);
                    const sold = this.counterItemSold(it);
                    const cl = parseFloat(it.closing || 0);
                    const sp = parseFloat(it.selling_price || 0);
                    const val = sold * sp;
                    return `<tr>
                        <td style="padding:5px 10px;font-weight:600;font-size:10px;border-bottom:1px solid #e2e8f0">${esc(it.item_name || '--')}</td>
                        <td class="font-mono" style="text-align:center;padding:5px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${o}</td>
                        <td class="font-mono" style="text-align:center;padding:5px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${r}</td>
                        <td class="font-mono" style="text-align:center;padding:5px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${sold}</td>
                        <td class="font-mono" style="text-align:center;padding:5px 10px;font-weight:700;font-size:10px;border-bottom:1px solid #e2e8f0">${cl}</td>
                        <td class="font-mono" style="text-align:right;padding:5px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${fmt(sp)}</td>
                        <td class="font-mono" style="text-align:right;padding:5px 10px;font-weight:700;font-size:10px;border-bottom:1px solid #e2e8f0">${fmt(val)}</td>
                    </tr>`;
                }).join('');
                return `
                    <div style="margin-bottom:20px">
                        <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;color:#000;display:flex;justify-content:space-between;align-items:baseline">
                            <span>📦 ${esc(ls.name || 'Counter')}</span>
                            <span class="font-mono" style="font-size:9px;font-weight:700;color:#059669">Sales: ${fmt(counterSalesTotal)}</span>
                        </h4>
                        ${tbl(
                    [{ label: 'Product' }, { label: 'Open', right: false }, { label: 'Rcvd', right: false }, { label: 'Sold', right: false }, { label: 'Close', right: false }, { label: 'Price', right: true }, { label: 'Sales Value', right: true }],
                    rows,
                    `<td colspan="6" style="padding:8px 12px;font-weight:800;font-size:10px;text-transform:uppercase;background:#ecfdf5;color:#059669">Counter Sales Total</td><td class="font-mono" style="text-align:right;padding:8px 12px;font-weight:900;font-size:10px;color:#059669;background:#ecfdf5">${fmt(counterSalesTotal)}</td>`
                )}
                    </div>`;
            }).filter(b => b).join('');
            const counterTotalSales = this.lubeTotalAmount;

            // Stock consolidation — merge store + counters
            const consolidation = this.lubeConsolidation || [];
            const hasConsolidation = consolidation.length > 0;
            const consolidationRows = consolidation.map(row => {
                return `<tr>
                    <td style="padding:6px 10px;font-weight:700;font-size:10px;border-bottom:1px solid #e2e8f0">${esc(row.product_name || '--')}</td>
                    <td class="font-mono" style="text-align:center;padding:6px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${row.store_closing}</td>
                    <td class="font-mono" style="text-align:center;padding:6px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${row.counter_closing}</td>
                    <td class="font-mono" style="text-align:center;padding:6px 10px;font-weight:800;font-size:10px;border-bottom:1px solid #e2e8f0">${row.total_closing}</td>
                    <td class="font-mono" style="text-align:right;padding:6px 10px;font-size:10px;border-bottom:1px solid #e2e8f0">${fmt(row.cost_price)}</td>
                    <td class="font-mono" style="text-align:right;padding:6px 10px;font-weight:800;font-size:10px;border-bottom:1px solid #e2e8f0">${fmt(row.total_value)}</td>
                </tr>`;
            }).join('');
            const consolidationTotalValue = this.lubeConsolidationTotalValue;

            // Build Section 06 HTML
            let section06 = `
                ${sectionTitle(6, 'Lubricant Sales & Stock Evaluation', 'Inventory Position')}

                <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;color:#000">🏪 Lube Store (Warehouse)</h4>
                <div style="margin-bottom:20px">
                    ${lubeItems.length > 0 ? tbl(
                [{ label: 'Product' }, { label: 'Open', right: false }, { label: 'Rcvd', right: false }, { label: 'Issued', right: false }, { label: 'Close', right: false }, { label: 'Unit Price', right: true }, { label: 'Stock Value', right: true }],
                lubeRows,
                `<td colspan="6" style="padding:10px 12px;font-weight:900;font-size:11px;text-transform:uppercase;background:#f0fdf4;color:#166534">Total Store Stock Value</td><td class="font-mono" style="text-align:right;padding:10px 12px;font-weight:900;font-size:11px;color:#166534;background:#f0fdf4">${fmt(lubeTotal)}</td>`
            ) : '<p style="text-align:center;color:#94a3b8;font-style:italic;padding:16px;font-size:11px">No lube store data</p>'}
                </div>`;

            // Append counter tables only when counters exist
            if (hasCounters) {
                section06 += `
                <div style="margin-top:20px;border-top:2px solid #e2e8f0;padding-top:16px">
                    <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:14px;color:#000;display:flex;justify-content:space-between;align-items:baseline">
                        <span>Counter Stock Evaluation</span>
                        <span class="font-mono" style="font-size:9px;font-weight:700;color:#059669">Total Counter Sales: ${fmt(counterTotalSales)}</span>
                    </h4>
                    ${counterBlocks}
                </div>`;
            }

            // Append consolidation only when data exists
            if (hasConsolidation) {
                section06 += `
                <div style="margin-top:20px;border-top:2px solid #000;padding-top:16px">
                    <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;color:#000">📊 Consolidated Stock Summary (Store + Counters)</h4>
                    ${tbl(
                    [{ label: 'Product' }, { label: 'Store Close', right: false }, { label: 'Counter Close', right: false }, { label: 'Total Close', right: false }, { label: 'Cost Price', right: true }, { label: 'Total Value', right: true }],
                    consolidationRows,
                    `<td colspan="5" style="padding:10px 12px;font-weight:900;font-size:11px;text-transform:uppercase;background:#eff6ff;color:#1e40af">Grand Total Stock Value</td><td class="font-mono" style="text-align:right;padding:10px 12px;font-weight:900;font-size:11px;color:#1e40af;background:#eff6ff">${fmt(consolidationTotalValue)}</td>`
                )}
                </div>`;
            }

            const page4 = `
            <div class="page">
                ${sectionTitle(5, 'Variance Analysis', 'Pump vs Tank Comparison')}
                <div style="margin-bottom:32px">
                    ${tbl(
                [{ label: 'Product' }, { label: 'Pump Sales (L)', right: true }, { label: 'Tank Consumption (L)', right: true }, { label: 'Variance (L)', right: true }],
                varianceRows || '<tr><td colspan="4" style="text-align:center;color:#94a3b8;font-style:italic;padding:14px;font-size:11px">No comparison data</td></tr>',
                ''
            )}
                    <div style="margin-top:12px;padding:12px 16px;background:#f8fafc;border-left:3px solid #000;font-size:10px;color:#64748b;font-style:italic">
                        <strong style="color:#1e293b">Note:</strong> Positive variance indicates tank consumption exceeded pump sales (possible leakage or measurement discrepancy). Negative indicates pump sales exceeded tank dip (possible meter over-read).
                    </div>
                </div>

                ${section06}
                ${pageFooter(4)}
            </div>`;

            // ══════════════════════════════════════════
            // PAGE 5: SECTION 07 (Metrics) + 08 (Infographics)
            // ══════════════════════════════════════════

            // Calculate key metrics
            const depositPct = auditSales > 0 ? (bankDeposit / auditSales * 100) : 0;
            const expensePct = auditSales > 0 ? (totalExpenses / auditSales * 100) : 0;
            const cashPct = auditSales > 0 ? (cashAtHand / auditSales * 100) : 0;
            const debtorPct = auditSales > 0 ? (receivables / auditSales * 100) : 0;
            const lubePct = auditSales > 0 ? (lubeTotal / auditSales * 100) : 0;
            const numProducts = pumpBreakdown.length;
            const avgDailySales = (() => {
                const d1 = new Date(dateFrom), d2 = new Date(dateTo);
                const days = Math.max(1, Math.ceil((d2 - d1) / 86400000) + 1);
                return auditSales / days;
            })();
            const topProduct = pumpBreakdown.length > 0 ? pumpBreakdown.reduce((a, b) => a.amount > b.amount ? a : b) : null;
            const totalVolume = pumpTotalL;
            const avgPricePerLitre = totalVolume > 0 ? pumpTotal / totalVolume : 0;
            const haulageCount = (this.haulage || []).length;
            const totalHaulage = this.totalHaulageQty;

            const metricCard = (label, value, sub, color) => `
                <div style="background:#f8fafc;padding:16px;border-radius:8px;border-left:4px solid ${color}">
                    <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">${label}</p>
                    <p style="font-size:18px;font-weight:900;color:#000;line-height:1">${value}</p>
                    ${sub ? `<p style="font-size:9px;color:#64748b;margin-top:4px">${sub}</p>` : ''}
                </div>`;

            // Infographic bars (max bar width based on highest value)
            const maxSalesAmt = Math.max(...pumpBreakdown.map(p => p.amount), 1);
            const barRows = pumpBreakdown.map(p => {
                const pct = (p.amount / maxSalesAmt * 100);
                return `<div style="margin-bottom:12px">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
                        <span style="font-size:11px;font-weight:800;color:#000">${esc(p.product)}</span>
                        <span class="font-mono" style="font-size:10px;font-weight:700;color:#64748b">${fmt(p.amount)}</span>
                    </div>
                    <div style="height:18px;background:#f1f5f9;border-radius:4px;overflow:hidden">
                        <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,#f59e0b,#d97706);border-radius:4px;transition:width .3s"></div>
                    </div>
                    <p style="font-size:8px;color:#94a3b8;margin-top:2px">${fmtL(p.litres)} litres · Avg ${fmt(p.rate)}/L</p>
                </div>`;
            }).join('');

            // Expense share pie-style bars
            const maxExpAmt = Math.max(...this.expenseCategories.map(c => Math.abs(this.expenseCatBalance(c))), 1);
            const expenseBars = this.expenseCategories.filter(c => this.expenseCatBalance(c) !== 0).map(cat => {
                const bal = Math.abs(this.expenseCatBalance(cat));
                const pct = (bal / maxExpAmt * 100);
                return `<div style="margin-bottom:10px">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:3px">
                        <span style="font-size:10px;font-weight:700;color:#000">${esc(cat.name)}</span>
                        <span class="font-mono" style="font-size:9px;font-weight:700;color:#be123c">${fmt(bal)}</span>
                    </div>
                    <div style="height:10px;background:#fff1f2;border-radius:3px;overflow:hidden">
                        <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,#fb7185,#e11d48);border-radius:3px"></div>
                    </div>
                </div>`;
            }).join('');

            // Reconciliation donut-style summary
            const reconItems = [
                { label: 'Bank Deposit', amount: bankDeposit, color: '#3b82f6' },
                { label: 'Expenses', amount: totalExpenses, color: '#e11d48' },
                { label: 'Cash at Hand', amount: cashAtHand, color: '#10b981' },
                { label: 'Receivables', amount: receivables, color: '#f59e0b' },
            ].filter(r => r.amount > 0);
            const reconMax = Math.max(...reconItems.map(r => r.amount), 1);
            const reconBars = reconItems.map(r => `
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                    <div style="width:8px;height:8px;border-radius:50%;background:${r.color};flex-shrink:0"></div>
                    <div style="flex:1">
                        <div style="display:flex;justify-content:space-between;margin-bottom:3px">
                            <span style="font-size:10px;font-weight:600;color:#000">${r.label}</span>
                            <span class="font-mono" style="font-size:9px;font-weight:700;color:#000">${fmt(r.amount)}</span>
                        </div>
                        <div style="height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden">
                            <div style="height:100%;width:${(r.amount / reconMax * 100)}%;background:${r.color};border-radius:3px"></div>
                        </div>
                    </div>
                </div>
            `).join('');

            const page5 = `
            <div class="page">
                ${sectionTitle(7, 'Key Metrics', 'Performance Dashboard')}
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:32px">
                    ${metricCard('Total Revenue', fmt(auditSales), `${numProducts} products`, '#000')}
                    ${metricCard('Avg Daily Sales', fmt(avgDailySales), `${esc(dateFrom)} – ${esc(dateTo)}`, '#3b82f6')}
                    ${metricCard('Total Volume', fmtL(totalVolume) + ' L', `Avg ${fmt(avgPricePerLitre)}/L`, '#f59e0b')}
                    ${metricCard('Deposit Rate', fmtPct(depositPct), fmt(bankDeposit) + ' deposited', '#10b981')}
                    ${metricCard('Expense Ratio', fmtPct(expensePct), fmt(totalExpenses) + ' spent', '#e11d48')}
                    ${metricCard('Lube Stock', fmt(lubeTotal), lubeItems.length + ' items in store', '#8b5cf6')}
                    ${metricCard('Cash Retained', fmtPct(cashPct), fmt(cashAtHand) + ' at hand', '#06b6d4')}
                    ${metricCard('Debtors', fmtPct(debtorPct), fmt(receivables) + ' outstanding', '#b45309')}
                    ${metricCard('Deliveries', haulageCount + ' trips', fmtL(totalHaulage) + ' L received', '#6366f1')}
                    ${topProduct ? metricCard('Top Product', esc(topProduct.product), fmt(topProduct.amount) + ' revenue', '#059669') : metricCard('Top Product', '—', 'No data', '#94a3b8')}
                </div>

                ${sectionTitle(8, 'Infographics', 'Visual Analysis')}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
                    <div>
                        <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;color:#000">Revenue by Product</h4>
                        ${barRows || '<p style="color:#94a3b8;font-size:10px;font-style:italic">No product data</p>'}
                    </div>
                    <div>
                        <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;color:#000">Expense Distribution</h4>
                        ${expenseBars || '<p style="color:#94a3b8;font-size:10px;font-style:italic">No expense data</p>'}

                        <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px;margin:20px 0 14px;color:#000">Fund Allocation</h4>
                        ${reconBars}
                    </div>
                </div>
                ${pageFooter(5)}
            </div>`;

            // ══════════════════════════════════════════
            // PAGE 6: SECTION 09 — 3D PIE CHART ANALYTICS
            // ══════════════════════════════════════════

            // Color palette for pie slices
            const pieColors = ['#3b82f6', '#f59e0b', '#10b981', '#e11d48', '#8b5cf6', '#06b6d4', '#f97316', '#84cc16', '#ec4899', '#6366f1'];

            // Helper: build a 3D pie chart with legend
            const pie3D = (items, emptyMsg) => {
                if (!items || items.length === 0) return `<p style="color:#94a3b8;font-size:10px;font-style:italic;text-align:center;padding:16px">${emptyMsg}</p>`;
                const total = items.reduce((s, i) => s + i.value, 0);
                if (total <= 0) return `<p style="color:#94a3b8;font-size:10px;font-style:italic;text-align:center;padding:16px">${emptyMsg}</p>`;

                // Build conic-gradient stops
                let angle = 0;
                const stops = items.map((item, idx) => {
                    const share = item.value / total;
                    const deg = share * 360;
                    const color = pieColors[idx % pieColors.length];
                    const start = angle;
                    angle += deg;
                    item._color = color;
                    item._pct = (share * 100).toFixed(1);
                    return `${color} ${start.toFixed(1)}deg ${angle.toFixed(1)}deg`;
                }).join(', ');

                // Legend rows
                const legend = items.map(item => `
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:5px">
                        <div style="width:8px;height:8px;border-radius:2px;background:${item._color};flex-shrink:0"></div>
                        <span style="font-size:9px;font-weight:600;color:#334155;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(item.label)}</span>
                        <span class="font-mono" style="font-size:8px;font-weight:700;color:#64748b;white-space:nowrap">${item.display}</span>
                        <span class="font-mono" style="font-size:8px;color:#94a3b8;white-space:nowrap">${item._pct}%</span>
                    </div>`).join('');

                return `
                <div style="display:flex;align-items:center;gap:14px">
                    <div style="position:relative;width:100px;height:100px;flex-shrink:0">
                        <div style="width:100px;height:100px;border-radius:50%;background:conic-gradient(${stops});transform:rotateX(55deg);box-shadow:0 8px 0 rgba(0,0,0,.12),0 12px 0 rgba(0,0,0,.06);position:relative"></div>
                        <div style="position:absolute;top:50%;left:50%;width:30px;height:30px;border-radius:50%;background:#fff;transform:translate(-50%,-50%) rotateX(55deg);box-shadow:inset 0 1px 3px rgba(0,0,0,.1)"></div>
                    </div>
                    <div style="flex:1;min-width:0">${legend}</div>
                </div>`;
            };

            // ── Data for each pie chart ──

            // 1. Sales Revenue by Product
            const salesPieItems = pumpBreakdown.map(p => ({
                label: p.product, value: p.amount, display: fmt(p.amount)
            }));

            // 2. Expense Distribution
            const expPieItems = this.expenseCategories
                .filter(c => this.expenseCatBalance(c) !== 0)
                .map(c => ({
                    label: c.name, value: Math.abs(this.expenseCatBalance(c)),
                    display: fmt(Math.abs(this.expenseCatBalance(c)))
                }));

            // 3. Debtors
            const debtorPieItems = this.debtorAccounts
                .filter(a => this.debtorBalance(a) !== 0)
                .map(a => ({
                    label: a.account_name || a.name,
                    value: Math.abs(this.debtorBalance(a)),
                    display: fmt(Math.abs(this.debtorBalance(a)))
                }));

            // 4. Purchases / Haulage
            const haulPieItems = (this.haulageByProduct || []).map(h => ({
                label: h.product, value: h.quantity, display: fmtL(h.quantity) + ' L'
            }));

            // 5. Lubricant (Counter + Main Store)
            const lubePieItems = [];
            // Add each counter section
            counters.filter(ls => (ls.items || []).length > 0).forEach(ls => {
                const val = this.lubeSectionAmount(ls);
                if (val > 0) lubePieItems.push({ label: ls.name || 'Counter', value: val, display: fmt(val) });
            });
            // Add Main Store
            if (lubeTotal > 0) lubePieItems.push({ label: 'Main Store', value: lubeTotal, display: fmt(lubeTotal) });

            // 6. Fund Allocation
            const fundPieItems = [
                { label: 'Bank Deposit', value: bankDeposit, display: fmt(bankDeposit) },
                { label: 'Expenses', value: totalExpenses, display: fmt(totalExpenses) },
                { label: 'Cash at Hand', value: cashAtHand, display: fmt(cashAtHand) },
                { label: 'Debtors', value: receivables, display: fmt(receivables) },
                { label: 'Lube Stock', value: lubeTotal, display: fmt(lubeTotal) },
            ].filter(f => f.value > 0);

            const chartTitle = (icon, text) => `<h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:12px;color:#000;border-bottom:1px solid #e2e8f0;padding-bottom:6px">${icon} ${esc(text)}</h4>`;

            const page6 = `
            <div class="page">
                ${sectionTitle(9, 'Charts & Analytics', '3D Pie Chart Breakdown')}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px">
                    <div>
                        ${chartTitle('&#128202;', 'Sales Revenue by Product')}
                        ${pie3D(salesPieItems, 'No sales data')}
                    </div>
                    <div>
                        ${chartTitle('&#128184;', 'Expense Distribution')}
                        ${pie3D(expPieItems, 'No expense data')}
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-top:28px">
                    <div>
                        ${chartTitle('&#129534;', 'Outstanding Debtors')}
                        ${pie3D(debtorPieItems, 'No debtors')}
                    </div>
                    <div>
                        ${chartTitle('&#128667;', 'Purchases / Haulage')}
                        ${pie3D(haulPieItems, 'No haulage records')}
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-top:28px">
                    <div>
                        ${chartTitle('&#129524;', 'Lubricant')}
                        ${pie3D(lubePieItems, 'No lubricant data')}
                    </div>
                    <div>
                        ${chartTitle('&#128176;', 'Fund Allocation')}
                        ${pie3D(fundPieItems, 'No allocation data')}
                    </div>
                </div>
                ${pageFooter(6)}
            </div>`;

            // ══════════════════════════════════════════
            // PAGE 7: SECTION 10 — PHOTO EVIDENCE (optional)
            // ══════════════════════════════════════════
            const tellerUrl = this.systemSales.teller_proof_url || '';
            const posUrl = this.systemSales.pos_proof_url || '';
            const showPhotos = this.finalReportIncludePhotos && (tellerUrl || posUrl);

            const page7 = showPhotos ? `
            <div class="page">
                ${sectionTitle(10, 'Photo Evidence', 'Supporting Documents')}
                <p style="font-size:10px;color:#64748b;margin-bottom:24px;font-style:italic">
                    The following supporting documents were uploaded during the audit period <strong>${esc(dateFrom)}</strong> to <strong>${esc(dateTo)}</strong> for <strong>${esc(station)}</strong>.
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px">
                    ${tellerUrl ? `
                    <div>
                        <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;color:#000;display:flex;align-items:center;gap:6px">
                            <span style="display:inline-block;width:20px;height:20px;border-radius:6px;background:#3b82f6;color:#fff;text-align:center;line-height:20px;font-size:10px;font-weight:900">T</span>
                            Teller / Deposit Slip
                        </h4>
                        <div style="border:2px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#f8fafc">
                            <img src="${esc(tellerUrl)}" alt="Teller Deposit Slip" style="width:100%;display:block;object-fit:contain;max-height:340px" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
                            <div style="display:none;height:200px;align-items:center;justify-content:center;color:#94a3b8;font-size:11px;font-style:italic">Image could not be loaded</div>
                        </div>
                        <p style="font-size:8px;color:#94a3b8;margin-top:6px;text-align:center">Teller deposit proof</p>
                    </div>` : ''}
                    ${posUrl ? `
                    <div>
                        <h4 style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;color:#000;display:flex;align-items:center;gap:6px">
                            <span style="display:inline-block;width:20px;height:20px;border-radius:6px;background:#10b981;color:#fff;text-align:center;line-height:20px;font-size:10px;font-weight:900">P</span>
                            POS Receipt
                        </h4>
                        <div style="border:2px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#f8fafc">
                            <img src="${esc(posUrl)}" alt="POS Receipt" style="width:100%;display:block;object-fit:contain;max-height:340px" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
                            <div style="display:none;height:200px;align-items:center;justify-content:center;color:#94a3b8;font-size:11px;font-style:italic">Image could not be loaded</div>
                        </div>
                        <p style="font-size:8px;color:#94a3b8;margin-top:6px;text-align:center">POS terminal receipt</p>
                    </div>` : ''}
                </div>

                <div style="margin-top:32px;padding:16px 20px;background:#f8fafc;border-left:4px solid #000;border-radius:0 8px 8px 0">
                    <p style="font-size:9px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:2px;margin-bottom:6px">Verification Note</p>
                    <p style="font-size:10px;color:#64748b;line-height:1.6">
                        These images serve as documentary evidence for the reconciliation process.
                        ${tellerUrl ? `The deposit slip confirms the bank deposit of <strong>${fmt(bankDeposit)}</strong>.` : ''}
                        ${posUrl ? `The POS receipt confirms POS transactions of <strong>${fmt(parseFloat(this.systemSales.pos_amount) || 0)}</strong>.` : ''}
                    </p>
                </div>
                ${pageFooter(totalPages)}
            </div>` : '';

            const html = `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Final Audit Report — ${esc(company)}</title><style>${css}</style></head><body>${page1}${page2}${page3}${page4}${page5}${page6}${page7}</body></html>`;
            return { html, station, dateFrom, dateTo };
        },

        // ── Preview Final Report (fullscreen overlay) ──
        previewFinalReport() {
            const { html } = this._buildFinalReportHTML();
            const existing = document.getElementById('final-rpt-preview-overlay');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.id = 'final-rpt-preview-overlay';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.92);backdrop-filter:blur(8px);display:flex;flex-direction:column;';
            overlay.innerHTML = `
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 20px;background:#000;border-bottom:1px solid rgba(255,255,255,.1);flex-shrink:0">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div style="width:34px;height:34px;border-radius:8px;background:#f59e0b;display:flex;align-items:center;justify-content:center">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2.5" width="18" height="18"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div>
                            <h3 style="color:#fff;font-size:13px;font-weight:800;margin:0">Final Report Preview</h3>
                            <p style="color:#94a3b8;font-size:10px;margin:0">Scroll to review all pages &bull; Zoom to adjust</p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <button id="frpt-zoom-out" style="width:30px;height:30px;border-radius:6px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:#fff;cursor:pointer;font-size:15px;font-weight:700">−</button>
                        <span id="frpt-zoom-label" style="color:#94a3b8;font-size:11px;min-width:38px;text-align:center">100%</span>
                        <button id="frpt-zoom-in" style="width:30px;height:30px;border-radius:6px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:#fff;cursor:pointer;font-size:15px;font-weight:700">+</button>
                        <div style="width:1px;height:24px;background:rgba(255,255,255,.1);margin:0 4px"></div>
                        <button id="frpt-preview-download" style="padding:7px 14px;border-radius:8px;background:#f59e0b;border:none;color:#000;cursor:pointer;font-size:11px;font-weight:800">⬇ Download PDF</button>
                        <button id="frpt-preview-close" style="width:30px;height:30px;border-radius:6px;background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.3);color:#fca5a5;cursor:pointer;font-size:16px;line-height:1">×</button>
                    </div>
                </div>
                <div style="flex:1;overflow-y:auto;padding:24px;display:flex;justify-content:center">
                    <div id="frpt-preview-scaler" style="transform-origin:top center;transition:transform .2s">
                        <iframe id="frpt-preview-frame" style="width:860px;border:none;border-radius:12px;box-shadow:0 24px 64px rgba(0,0,0,.5);background:#fff;" scrolling="no"></iframe>
                    </div>
                </div>`;

            document.body.appendChild(overlay);

            const frame = document.getElementById('frpt-preview-frame');
            frame.srcdoc = html;
            frame.onload = () => { frame.style.height = (frame.contentDocument.body.scrollHeight + 32) + 'px'; };

            let zoom = 100;
            const scaler = document.getElementById('frpt-preview-scaler');
            const label = document.getElementById('frpt-zoom-label');
            const updateZoom = () => { scaler.style.transform = `scale(${zoom / 100})`; label.textContent = zoom + '%'; };

            document.getElementById('frpt-zoom-in').addEventListener('click', () => { if (zoom < 150) { zoom += 10; updateZoom(); } });
            document.getElementById('frpt-zoom-out').addEventListener('click', () => { if (zoom > 50) { zoom -= 10; updateZoom(); } });
            document.getElementById('frpt-preview-close').addEventListener('click', () => overlay.remove());
            document.getElementById('frpt-preview-download').addEventListener('click', () => this.downloadFinalReport());

            const esc2 = (e) => { if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', esc2); } };
            document.addEventListener('keydown', esc2);
        },

        // ── Download Final Report PDF ──
        downloadFinalReport() {
            const { html } = this._buildFinalReportHTML();
            const pw = window.open('', '_blank');
            if (!pw) { this.toast('Pop-up blocked — please allow pop-ups for this site', false); return; }
            pw.document.write(html);
            pw.document.close();
            pw.onload = () => { pw.focus(); pw.print(); };
        },

        _esc(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        },

        // ── URL Hash helpers ──
        _updateHash() {
            if (this.activeSession) {
                window.location.hash = `session=${this.activeSession}&tab=${this.currentTab}`;
            } else {
                history.replaceState(null, '', window.location.pathname + window.location.search);
            }
        },

        init() {
            this.$nextTick(() => lucide.createIcons());
            this.$watch('currentTab', (tab) => {
                this.$nextTick(() => lucide.createIcons());
                if (tab === 'lubricants' && !this.lubeGrnLoaded) this.loadLubeData();
                this._updateHash();
            });

            // Restore session & tab from URL hash on reload
            const hash = window.location.hash.slice(1);
            if (hash) {
                const params = Object.fromEntries(hash.split('&').map(p => p.split('=')));
                if (params.session) {
                    this.loadSession(params.session).then(() => {
                        if (params.tab) this.currentTab = params.tab;
                    });
                }
            }
        }
    }
}