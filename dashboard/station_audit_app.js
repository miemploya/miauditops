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
            { id: 'report', label: 'Report', icon: 'bar-chart-3', activeClass: 'bg-violet-100 text-violet-700 border-violet-300 dark:bg-violet-900/30 dark:text-violet-300' },
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
        lubeStoreItems: [],
        lubeIssues: [],       // [{store_item_id, section_id, quantity}]
        lubeIssueLog: [],     // [{product_name, counter_name, quantity, created_at}]
        lubeSubTab: localStorage.getItem('sa_lubeSubTab') || 'products',  // 'products' | 'grn' | 'suppliers' | 'store' | 'counters'
        lubeIssueModal: false,
        lubeIssueForm: { store_item_id: null, store_item_name: '', section_id: '', quantity: 0 },

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

                // Opening = sum of tank openings from FIRST rate period only
                const opening = (firstPt.tanks || []).reduce((s, t) => s + parseFloat(t.opening || 0), 0);

                // Added = accumulated across ALL rate periods
                const added = sorted.reduce((s, pt) => (pt.tanks || []).reduce((s2, t) => s2 + parseFloat(t.added || 0), s), 0);

                // Closing = sum of tank closings from LAST rate period only
                const closing = (lastPt.tanks || []).reduce((s, t) => s + parseFloat(t.closing || 0), 0);

                results[prod] = {
                    product: prod,
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

        // Helpers
        fmt(n) { return '₦' + (parseFloat(n) || 0).toLocaleString('en', { minimumFractionDigits: 2 }); },
        toTitleCase(str) { return str.replace(/\b\w/g, c => c.toUpperCase()); },
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

                // Use new_rate_period API: closes previous + copies pumps with closing→opening
                const r = await this.api('new_rate_period', {
                    prev_table_id: prevTable.id,
                    session_id: this.activeSession,
                    product: this.selectedProduct,
                    rate: rate,
                    date_from: dateFrom,
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
                        date_from: dateFrom, date_to: dateTo, station_location: station,
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
                pt.tanks.push({ id: r.id, tank_name: name, product: pt.product, opening: 0, added: 0, closing: 0 });
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
            this.haulage.push({ delivery_date: new Date().toISOString().slice(0, 10), tank_name: '', product: 'PMS', quantity: 0, waybill_qty: 0 });
            this.$nextTick(() => lucide.createIcons());
        },
        async saveHaulage() {
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
        async deleteExpenseEntry(entryId) {
            if (!confirm('Delete this expense entry?')) return;
            const r = await this.api('delete_expense_entry', { entry_id: entryId });
            if (r.success) {
                const cat = this.expenseCategories.find(c => c.id == this.activeExpenseCatId);
                if (cat) cat.ledger = cat.ledger.filter(e => e.id != entryId);
                this.toast('Entry deleted');
            } else { this.toast(r.message, false); }
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

        // ── Debtor Accounts & Ledger ──
        async createDebtorAccount() {
            if (!this.newDebtorName.trim()) return this.toast('Enter a customer name', false);
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
        async deleteDebtorEntry(entryId) {
            if (!confirm('Delete this ledger entry?')) return;
            const r = await this.api('delete_debtor_entry', { entry_id: entryId });
            if (r.success) {
                const acct = this.debtorAccounts.find(a => a.id == this.activeDebtorId);
                if (acct) acct.ledger = acct.ledger.filter(e => e.id != entryId);
                this.toast('Entry deleted');
            } else { this.toast(r.message, false); }
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
                this.toast('GRN saved (₦' + parseFloat(r.total_cost).toLocaleString('en', { minimumFractionDigits: 2 }) + ')');
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
                this.api('get_lube_grns', {}, 'POST'),
                this.api('get_lube_stock_counts', { session_id: this.activeSession }, 'POST'),
            ]);
            if (rp.success) this.lubeProducts = rp.products || [];
            if (rs.success) this.lubeSuppliers = rs.suppliers || [];
            if (rg.success) this.lubeGrns = rg.grns || [];
            if (rsc.success) this.lubeStockCounts = rsc.counts || [];
            this.lubeGrnLoaded = true;
            this.syncStoreFromProducts();
        },

        // ── PDF Download (Redesigned – matches report page layout & colors) ──
        async downloadReportPDF() {
            const s = this.sessionData?.session || {};
            const station = s.outlet_name || 'Station';
            const dateFrom = s.date_from || '';
            const dateTo = s.date_to || '';
            const company = window.__SA_COMPANY || 'MIAUDITOPS';
            const preparer = window.__SA_USER?.name || 'Auditor';
            const preparerRole = window.__SA_USER?.role || '';
            const esc = v => this._esc(v);
            const fmtN = n => (parseFloat(n) || 0).toLocaleString('en', { minimumFractionDigits: 2 });
            const fmt = n => this.fmt(n);
            const tankVar = this.totalTankDiff - this.totalPumpLitres;

            /* ── Build Pump Sales rows ── */
            const pumpRows = this.pumpSalesGrouped.map(r => {
                if (r.type === 'row') {
                    return `<tr class="pdf-row"><td class="fw700">${esc(r.product)}</td><td class="mono sl500" style="font-size:10px">${r.dateFrom} → ${r.dateTo}</td><td class="text-right mono">${fmt(r.rate)}</td><td class="text-right mono fw700" style="color:#ea580c">${fmtN(r.litres)}</td><td class="text-right mono fw700" style="color:#16a34a">${fmt(r.amount)}</td></tr>`;
                } else {
                    return `<tr class="subtotal-row"><td class="fw900 up" style="color:#4338ca" colspan="3">${esc(r.product)} Total</td><td class="text-right mono fw900" style="color:#4338ca">${fmtN(r.totalLitres)}</td><td class="text-right mono fw900" style="color:#4338ca">${fmt(r.totalAmount)}</td></tr>`;
                }
            }).join('');

            /* ── Build Tank Reconciliation rows ── */
            const reconRows = this.productComparison.map(c => {
                const varColor = Math.abs(c.variance) < 0.01 ? '#16a34a' : '#dc2626';
                const badge = Math.abs(c.variance) < 0.01
                    ? '<span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:99px;font-weight:800;font-size:8px">BALANCED</span>'
                    : '<span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:99px;font-weight:800;font-size:8px">VARIANCE</span>';
                return `<tr class="pdf-row"><td class="fw700">${esc(c.product)}</td><td class="text-right mono">${fmtN(c.pumpLitres)}</td><td class="text-right mono">${fmtN(c.tankDiff)}</td><td class="text-right mono fw700" style="color:${varColor}">${fmtN(c.variance)}</td><td class="text-right mono fw700" style="color:#16a34a">${fmt(c.pumpAmount)}</td><td class="text-center">${badge}</td></tr>`;
            }).join('');

            /* ── Build Tank Dipping Summary rows ── */
            const tankRows = (this.tankProductTotals || []).map(t => {
                return `<tr class="pdf-row"><td class="fw700">${esc(t.product)}</td><td class="text-right mono">${fmtN(t.opening)}</td><td class="text-right mono" style="color:#16a34a">${fmtN(t.added)}</td><td class="text-right mono">${fmtN(t.closing)}</td><td class="text-right mono fw700" style="color:#0891b2">${fmtN(t.diff)}</td></tr>`;
            }).join('') || '<tr><td colspan="5" class="text-center sl400" style="padding:12px">No tank dipping records</td></tr>';

            /* ── Build Haulage rows ── */
            const haulRows = (this.haulageByProduct || []).map(h => {
                const diff = h.quantity - h.waybill_qty;
                const diffColor = diff === 0 ? '#16a34a' : '#d97706';
                return `<tr class="pdf-row"><td class="fw700">${esc(h.product)}</td><td class="text-right mono">${h.count}</td><td class="text-right mono fw700" style="color:#4f46e5">${fmtN(h.quantity)}</td><td class="text-right mono">${fmtN(h.waybill_qty)}</td><td class="text-right mono fw700" style="color:${diffColor}">${fmtN(diff)}</td></tr>`;
            }).join('') || '<tr><td colspan="5" class="text-center sl400" style="padding:12px">No haulage records</td></tr>';

            /* ── Lube Store rows ── */
            const lubeStoreRows = (this.lubeStoreItems || []).map(si => {
                const cls = typeof this.storeItemClosing === 'function' ? this.storeItemClosing(si) : 0;
                return `<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed #f1f5f9"><span style="font-size:10px;color:#475569">${esc(si.item_name || si.product_name || '')}</span><span style="font-size:10px;font-family:monospace"><span style="color:#94a3b8">Cls: ${cls}</span> <span style="color:#9333ea;font-weight:700;margin-left:6px">@ ${fmt(si.selling_price)}</span></span></div>`;
            }).join('') || '<p style="font-size:10px;color:#94a3b8;text-align:center;padding:8px">No store items</p>';

            /* ── Lube Counter Sales rows ── */
            const lubeCounterRows = (this.lubeSections || []).map(ls => {
                const secAmt = typeof this.lubeSectionAmount === 'function' ? this.lubeSectionAmount(ls) : 0;
                const items = (ls.items || []).map(it => {
                    const sold = typeof this.counterItemSold === 'function' ? this.counterItemSold(it) : 0;
                    return `<div style="display:flex;justify-content:space-between;padding:2px 0 2px 12px;border-bottom:1px dashed #f8fafc"><span style="font-size:9px;color:#64748b">${esc(it.item_name || '')}</span><span style="font-size:9px;font-family:monospace;color:#64748b">${sold} × ${fmt(it.selling_price)}</span></div>`;
                }).join('');
                return `<div style="display:flex;justify-content:space-between;padding:5px 0"><span style="font-size:11px;font-weight:700;color:#334155">${esc(ls.name)}</span><span style="font-size:11px;font-weight:900;color:#7c3aed">${fmt(secAmt)}</span></div>${items}`;
            }).join('') || '<p style="font-size:10px;color:#94a3b8;text-align:center;padding:8px">No counter data</p>';

            /* ── Expense rows ── */
            const expenseRows = (this.expenseCategories || []).map(cat => {
                const bal = this.expenseCatBalance(cat);
                const entries = (cat.ledger || []).map(e => {
                    const drCr = (parseFloat(e.debit) || 0) > 0
                        ? `<span style="color:#e11d48">Dr ${fmt(e.debit)}</span>`
                        : (parseFloat(e.credit) || 0) > 0 ? `<span style="color:#16a34a">Cr ${fmt(e.credit)}</span>` : '';
                    return `<div style="display:flex;justify-content:space-between;padding:2px 0 2px 12px;border-bottom:1px dashed #fdf2f8"><span style="font-size:9px;color:#64748b">${esc(e.description || e.entry_date || '')}</span><span style="font-size:9px;font-family:monospace">${drCr}</span></div>`;
                }).join('');
                return `<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed #f1f5f9"><span style="font-size:11px;font-weight:600;color:#475569">${esc(cat.name)}</span><span style="font-size:11px;font-weight:900;font-family:monospace;color:#e11d48">${fmt(bal)}</span></div>${entries}`;
            }).join('') || '<p style="font-size:10px;color:#94a3b8;text-align:center;padding:8px">No expense records</p>';

            /* ── Debtor rows ── */
            const debtorRows = (this.debtorAccounts || []).map(acct => {
                const bal = this.debtorBalance(acct);
                const balColor = bal > 0 ? '#d97706' : '#16a34a';
                const entries = (acct.ledger || []).map(e => {
                    const drCr = (parseFloat(e.debit) || 0) > 0
                        ? `<span style="color:#d97706">Dr ${fmt(e.debit)}</span>`
                        : (parseFloat(e.credit) || 0) > 0 ? `<span style="color:#16a34a">Cr ${fmt(e.credit)}</span>` : '';
                    const label = (e.entry_date || '') + (e.description ? ' — ' + e.description : '');
                    return `<div style="display:flex;justify-content:space-between;padding:2px 0 2px 12px;border-bottom:1px dashed #fffbeb"><span style="font-size:9px;color:#64748b">${esc(label)}</span><span style="font-size:9px;font-family:monospace">${drCr}</span></div>`;
                }).join('');
                return `<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed #f1f5f9"><span style="font-size:11px;font-weight:600;color:#475569">${esc(acct.name)}</span><span style="font-size:11px;font-weight:900;font-family:monospace;color:${balColor}">${fmt(bal)}</span></div>${entries}`;
            }).join('') || '<p style="font-size:10px;color:#94a3b8;text-align:center;padding:8px">No debtor records</p>';

            /* ──────────────────── FULL HTML ──────────────────── */
            const html = `<div class="rpt">
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap');
                .rpt{font-family:'Inter',system-ui,sans-serif;color:#0f172a;line-height:1.5;width:100%;max-width:1050px;box-sizing:border-box;background:#fff;padding:8px 14px}
                .rpt *{box-sizing:border-box}
                /* Cover */
                .cover{min-height:500px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;page-break-after:always}
                /* Section cards */
                .card{border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:14px;page-break-inside:avoid}
                .card-hdr{padding:10px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:#0f172a}
                .card-hdr .dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
                /* Tables */
                .tbl{width:100%;border-collapse:collapse;font-size:10px}
                .tbl th{text-align:left;padding:6px 10px;background:#f8fafc;color:#64748b;font-weight:700;text-transform:uppercase;font-size:9px;border-bottom:1px solid #e2e8f0}
                .tbl td{padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#334155}
                .tbl .text-right{text-align:right}
                .tbl .text-center{text-align:center}
                .tbl .mono{font-family:'SFMono-Regular',Consolas,monospace;font-size:10px}
                .tbl .fw700{font-weight:700}
                .tbl .fw900{font-weight:900}
                .tbl .sl400{color:#94a3b8}
                .tbl .sl500{color:#64748b}
                .tbl .up{text-transform:uppercase;font-size:9px}
                .pdf-row td{border-bottom:1px solid #f1f5f9}
                .subtotal-row td{background:#f1f5f9;border-bottom:2px solid #c7d2fe}
                .foot-row td{font-weight:900;font-size:11px;border-top:2px solid currentColor}
                /* Grids */
                .g6{display:grid;grid-template-columns:repeat(6,1fr);gap:6px;margin-bottom:12px;page-break-inside:avoid}
                .g3{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;page-break-inside:avoid}
                .g2{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;page-break-inside:avoid}
                .g2-eq{display:grid;grid-template-columns:1fr 1fr;gap:0;page-break-inside:avoid}
                .scard{border-radius:10px;padding:8px 6px;border:1px solid;text-align:center;overflow:hidden}
                .scard .lbl{font-size:7px;font-weight:800;text-transform:uppercase;margin-bottom:2px;white-space:nowrap}
                .scard .val{font-size:13px;font-weight:900;white-space:nowrap}
                /* Sign-off */
                .sign{border:1px dashed #cbd5e1;border-radius:12px;padding:14px;text-align:center;background:#f8fafc}
                /* Page header */
                .pg-hdr{text-align:center;margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid #f1f5f9}
                .pg-hdr h2{font-size:16px;font-weight:900;margin:0 0 2px}
                .pg-hdr p{font-size:10px;color:#64748b;margin:0}
                /* Variance cards */
                .vcard{text-align:center;padding:14px;border-radius:12px}
                .vcard .lbl{font-size:8px;font-weight:800;text-transform:uppercase;margin-bottom:2px}
                .vcard .sub{font-size:9px;color:#64748b;margin-bottom:4px}
                .vcard .val{font-size:16px;font-weight:900}
                .vcard .badge{font-size:8px;font-weight:900;padding:2px 8px;border-radius:99px;display:inline-block;margin-top:4px}
                /* Dashed line items */
                .dash-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed #f1f5f9}
                .dash-row .k{font-size:10px;font-weight:600;color:#475569}
                .dash-row .v{font-size:10px;font-weight:900;font-family:monospace}
                .total-bar{display:flex;justify-content:space-between;padding:8px 12px;border-radius:8px;margin-top:8px}
                .total-bar .k{font-size:9px;font-weight:900;text-transform:uppercase}
                .total-bar .v{font-size:11px;font-weight:900}
            </style>

            <!-- ═══ COVER PAGE ═══ -->
            <div class="cover">
                <p style="font-size:13px;letter-spacing:6px;text-transform:uppercase;color:#6366f1;font-weight:700;margin-bottom:6px">MIAUDITOPS</p>
                <div style="width:70px;height:3px;background:linear-gradient(90deg,#6366f1,#8b5cf6);margin:0 auto 36px"></div>
                <h1 style="font-size:32px;font-weight:900;color:#0f172a;margin:0 0 10px">Audit Routine Report</h1>
                <div style="width:100px;height:2px;background:#e2e8f0;margin:0 auto 36px"></div>
                <h2 style="font-size:22px;font-weight:800;color:#1e293b;margin:0 0 6px">${esc(station)}</h2>
                <p style="font-size:12px;color:#64748b;margin:0 0 28px">${esc(company)}</p>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 36px;display:inline-block;margin-bottom:36px">
                    <p style="font-size:9px;text-transform:uppercase;letter-spacing:2px;color:#94a3b8;font-weight:700;margin:0 0 4px">Audit Period</p>
                    <p style="font-size:16px;font-weight:800;color:#334155;margin:0">${dateFrom}  —  ${dateTo}</p>
                </div>
                <div>
                    <p style="font-size:9px;text-transform:uppercase;letter-spacing:2px;color:#94a3b8;font-weight:700;margin:0 0 4px">Prepared By</p>
                    <p style="font-size:14px;font-weight:700;color:#334155;margin:0">${esc(preparer)}</p>
                    <p style="font-size:10px;color:#64748b;margin:3px 0 0">${esc(preparerRole)}</p>
                </div>
            </div>

            <!-- ═══ REPORT BODY ═══ -->
            <div style="padding:10px 0">
                <div class="pg-hdr">
                    <h2>Audit Close-Out Report</h2>
                    <p>${esc(station)} • ${esc(company)} • ${dateFrom} to ${dateTo}</p>
                </div>

                <!-- ── Summary Cards (6 columns like report page) ── -->
                <div class="g6">
                    <div class="scard" style="border-color:#bfdbfe60;background:#eff6ff">
                        <div class="lbl" style="color:#60a5fa">System Sales</div>
                        <div class="val" style="color:#2563eb">${fmt(this.systemSalesTotal)}</div>
                    </div>
                    <div class="scard" style="border-color:#fed7aa60;background:#fff7ed">
                        <div class="lbl" style="color:#fb923c">Pump Sales</div>
                        <div class="val" style="color:#ea580c">${fmt(this.totalPumpSales)}</div>
                    </div>
                    <div class="scard" style="border-color:#99f6e460;background:#f0fdfa">
                        <div class="lbl" style="color:#2dd4bf">Total Litres</div>
                        <div class="val" style="color:#0d9488">${fmtN(this.totalPumpLitres)} L</div>
                    </div>
                    <div class="scard" style="border-color:#c7d2fe60;background:#eef2ff">
                        <div class="lbl" style="color:#818cf8">Haulage Received</div>
                        <div class="val" style="color:#4f46e5">${fmtN(this.totalHaulageQty)} L</div>
                    </div>
                    <div class="scard" style="border-color:#e9d5ff60;background:#faf5ff">
                        <div class="lbl" style="color:#a78bfa">Lubricant Sales</div>
                        <div class="val" style="color:#9333ea">${fmt(this.lubeTotalAmount)}</div>
                    </div>
                    <div class="scard" style="border-color:${this.reportVariance === 0 ? '#bbf7d060' : '#fecaca60'};background:${this.reportVariance === 0 ? '#f0fdf4' : '#fef2f2'}">
                        <div class="lbl" style="color:${this.reportVariance === 0 ? '#4ade80' : '#f87171'}">${this.reportVariance === 0 ? '✓ ' : ''} Variance</div>
                        <div class="val" style="color:${this.reportVariance === 0 ? '#16a34a' : '#dc2626'}">${fmt(this.reportVariance)}</div>
                    </div>
                </div>

                <!-- ── Two column: System Sales + Pump Sales ── -->
                <div class="g2">
                    <!-- System Sales Breakdown -->
                    <div class="card">
                        <div class="card-hdr" style="background:linear-gradient(90deg,rgba(59,130,246,0.08),transparent)">
                            <div class="dot" style="background:#3b82f6"></div> System Sales Breakdown
                        </div>
                        <div style="padding:10px 14px">
                            <div class="dash-row"><span class="k">POS Terminals</span><span class="v" style="color:#0f172a">${fmt(this.systemSales.pos_amount)}</span></div>
                            <div class="dash-row"><span class="k">Cash (Denomination)</span><span class="v" style="color:#0f172a">${fmt(this.systemSales.cash_amount)}</span></div>
                            <div class="dash-row"><span class="k">Transfer</span><span class="v" style="color:#0f172a">${fmt(this.systemSales.transfer_amount)}</span></div>
                            <div class="dash-row"><span class="k">Teller/Credit</span><span class="v" style="color:#0f172a">${fmt(this.systemSales.teller_amount)}</span></div>
                            <div class="total-bar" style="background:#eff6ff"><span class="k" style="color:#1d4ed8">Total System Sales</span><span class="v" style="color:#1d4ed8">${fmt(this.systemSalesTotal)}</span></div>
                        </div>
                    </div>

                    <!-- Pump Sales Per Product -->
                    <div class="card">
                        <div class="card-hdr" style="background:linear-gradient(90deg,rgba(249,115,22,0.08),transparent)">
                            <div class="dot" style="background:#f97316"></div> Pump Sales Per Product
                        </div>
                        <table class="tbl">
                            <thead><tr><th>Product</th><th>Period</th><th class="text-right">Rate</th><th class="text-right" style="color:#ea580c">Litres</th><th class="text-right" style="color:#16a34a">Amount</th></tr></thead>
                            <tbody>${pumpRows}</tbody>
                            <tfoot><tr class="foot-row" style="color:#ea580c"><td colspan="3" style="padding:8px 10px;background:#fff7ed;text-transform:uppercase;font-size:10px">Grand Total</td><td class="text-right mono" style="padding:8px 10px;background:#fff7ed">${fmtN(this.totalPumpLitres)}</td><td class="text-right mono" style="padding:8px 10px;background:#fff7ed">${fmt(this.totalPumpSales)}</td></tr></tfoot>
                        </table>
                    </div>
                </div>

                <!-- ── Pump Litres vs Tank Dipping Reconciliation (full width) ── -->
                <div class="card">
                    <div class="card-hdr" style="background:linear-gradient(90deg,rgba(20,184,166,0.08),transparent)">
                        <div class="dot" style="background:#14b8a6"></div> Pump Litres vs Tank Dipping Reconciliation
                    </div>
                    <table class="tbl">
                        <thead><tr><th>Product</th><th class="text-right" style="color:#ea580c">Pump Litres Sold</th><th class="text-right" style="color:#0d9488">Tank Usage (Dip)</th><th class="text-right">Variance (L)</th><th class="text-right" style="color:#16a34a">Pump Amount</th><th class="text-center">Status</th></tr></thead>
                        <tbody>${reconRows}</tbody>
                        <tfoot><tr class="foot-row" style="color:#0f766e"><td style="padding:8px 10px;background:#f0fdfa;text-transform:uppercase;font-size:10px">Total</td><td class="text-right mono" style="padding:8px 10px;background:#f0fdfa">${fmtN(this.totalPumpLitres)}</td><td class="text-right mono" style="padding:8px 10px;background:#f0fdfa">${fmtN(this.totalTankDiff)}</td><td class="text-right mono" style="padding:8px 10px;background:#f0fdfa;color:${Math.abs(tankVar) < 0.01 ? '#16a34a' : '#dc2626'}">${fmtN(tankVar)}</td><td class="text-right mono" style="padding:8px 10px;background:#f0fdfa">${fmt(this.totalPumpSales)}</td><td style="background:#f0fdfa"></td></tr></tfoot>
                    </table>
                </div>

                <!-- ── Two column: Tank Dipping + Haulage ── -->
                <div class="g2">
                    <!-- Tank Dipping Summary -->
                    <div class="card">
                        <div class="card-hdr" style="background:linear-gradient(90deg,rgba(6,182,212,0.08),transparent)">
                            <div class="dot" style="background:#06b6d4"></div> Tank Dipping Summary
                        </div>
                        <table class="tbl">
                            <thead><tr><th>Product</th><th class="text-right">Opening (L)</th><th class="text-right" style="color:#16a34a">Added (L)</th><th class="text-right">Closing (L)</th><th class="text-right" style="color:#0891b2">Usage (L)</th></tr></thead>
                            <tbody>${tankRows}</tbody>
                        </table>
                    </div>

                    <!-- Haulage / Deliveries -->
                    <div class="card">
                        <div class="card-hdr" style="background:linear-gradient(90deg,rgba(99,102,241,0.08),transparent)">
                            <div class="dot" style="background:#6366f1"></div> Haulage / Deliveries Received
                        </div>
                        <table class="tbl">
                            <thead><tr><th>Product</th><th class="text-right">Deliveries</th><th class="text-right" style="color:#4f46e5">Qty Received (L)</th><th class="text-right">Waybill Qty (L)</th><th class="text-right" style="color:#d97706">Short/Over</th></tr></thead>
                            <tbody>${haulRows}</tbody>
                            ${(this.haulageByProduct || []).length > 0 ? `<tfoot><tr class="foot-row" style="color:#4f46e5"><td colspan="2" style="padding:8px 10px;background:#eef2ff;text-transform:uppercase;font-size:10px">Total</td><td class="text-right mono" style="padding:8px 10px;background:#eef2ff">${fmtN(this.totalHaulageQty)}</td><td class="text-right mono" style="padding:8px 10px;background:#eef2ff">${fmtN((this.haulageByProduct || []).reduce((s, h) => s + h.waybill_qty, 0))}</td><td style="background:#eef2ff"></td></tr></tfoot>` : ''}
                        </table>
                    </div>
                </div>

                <!-- ── Lubricants Summary ── -->
                <div class="card">
                    <div class="card-hdr" style="background:linear-gradient(90deg,rgba(168,85,247,0.08),transparent)">
                        <div class="dot" style="background:#a855f7"></div> Lubricants Summary
                    </div>
                    <div class="g2-eq" style="border-top:none">
                        <!-- Lube Store -->
                        <div style="padding:12px 14px;border-right:1px solid #f1f5f9">
                            <p style="font-size:9px;font-weight:900;text-transform:uppercase;color:#9333ea;margin-bottom:8px">◼ Lube Store</p>
                            ${lubeStoreRows}
                            <div class="total-bar" style="background:#faf5ff;margin-top:10px"><span class="k" style="color:#7e22ce">Store Closing Value</span><span class="v" style="color:#7e22ce">${fmt(this.lubeStoreTotalValue)}</span></div>
                        </div>
                        <!-- Counter Sales -->
                        <div style="padding:12px 14px">
                            <p style="font-size:9px;font-weight:900;text-transform:uppercase;color:#7c3aed;margin-bottom:8px">◼ Counter Sales</p>
                            ${lubeCounterRows}
                            <div class="total-bar" style="background:#ede9fe;margin-top:10px"><span class="k" style="color:#6d28d9">Total Counter Sales</span><span class="v" style="color:#6d28d9">${fmt(this.lubeTotalAmount)}</span></div>
                        </div>
                    </div>
                </div>

                <!-- ── Expenses & Debtors (side by side) ── -->
                <div class="g2">
                    <!-- Expenses Summary -->
                    <div class="card">
                        <div class="card-hdr" style="background:linear-gradient(90deg,rgba(225,29,72,0.08),transparent)">
                            <div class="dot" style="background:#e11d48"></div> Expenses Summary
                        </div>
                        <div style="padding:10px 14px">
                            ${expenseRows}
                            <div class="total-bar" style="background:#fff1f2;margin-top:10px"><span class="k" style="color:#be123c">Total Expenses</span><span class="v" style="color:#be123c">${fmt(this.totalExpenses)}</span></div>
                        </div>
                    </div>

                    <!-- Debtors Summary -->
                    <div class="card">
                        <div class="card-hdr" style="background:linear-gradient(90deg,rgba(245,158,11,0.08),transparent)">
                            <div class="dot" style="background:#f59e0b"></div> Debtors Summary
                        </div>
                        <div style="padding:10px 14px">
                            ${debtorRows}
                            <div class="total-bar" style="background:#fffbeb;margin-top:10px"><span class="k" style="color:#b45309">Total Outstanding</span><span class="v" style="color:#b45309">${fmt(this.totalDebtors)}</span></div>
                        </div>
                    </div>
                </div>

                <!-- ═══ Variance Analysis ═══ -->
                <div class="card" style="border-color:${this.reportVariance === 0 && Math.abs(tankVar) < 0.01 ? '#bbf7d060' : '#fecaca60'}">
                    <div class="card-hdr" style="background:linear-gradient(90deg,${this.reportVariance === 0 ? 'rgba(16,185,129,0.08)' : 'rgba(239,68,68,0.08)'},transparent)">
                        <div class="dot" style="background:${this.reportVariance === 0 ? '#10b981' : '#ef4444'}"></div> Variance Analysis
                    </div>
                    <div style="padding:14px">
                        <div class="g3">
                            <div class="vcard" style="background:${this.reportVariance === 0 ? '#f0fdf4' : '#fef2f2'}">
                                <div class="lbl" style="color:${this.reportVariance === 0 ? '#22c55e' : '#ef4444'}">Sales Variance</div>
                                <div class="sub">System Sales − Pump Sales</div>
                                <div class="val" style="color:${this.reportVariance === 0 ? '#15803d' : '#dc2626'}">${fmt(this.reportVariance)}</div>
                                <span class="badge" style="background:${this.reportVariance === 0 ? '#dcfce7' : '#fee2e2'};color:${this.reportVariance === 0 ? '#166534' : '#991b1b'}">${this.reportVariance === 0 ? '✓ BALANCED' : this.reportVariance > 0 ? '▲ OVER' : '▼ SHORT'}</span>
                            </div>
                            <div class="vcard" style="background:${Math.abs(tankVar) < 0.01 ? '#f0fdf4' : '#fffbeb'}">
                                <div class="lbl" style="color:${Math.abs(tankVar) < 0.01 ? '#22c55e' : '#f59e0b'}">Tank Variance</div>
                                <div class="sub">Tank Usage − Pump Litres</div>
                                <div class="val" style="color:${Math.abs(tankVar) < 0.01 ? '#15803d' : '#b45309'}">${fmtN(tankVar)} L</div>
                                <span class="badge" style="background:${Math.abs(tankVar) < 0.01 ? '#dcfce7' : '#fef3c7'};color:${Math.abs(tankVar) < 0.01 ? '#166534' : '#92400e'}">${Math.abs(tankVar) < 0.01 ? '✓ BALANCED' : tankVar > 0 ? '▲ OVER' : '▼ SHORT'}</span>
                            </div>
                            <div class="vcard" style="background:#eff6ff">
                                <div class="lbl" style="color:#3b82f6">Total Revenue</div>
                                <div class="sub">Pump Sales + Lubricants</div>
                                <div class="val" style="color:#1d4ed8">${fmt(this.totalPumpSales + (this.lubeTotalAmount || 0))}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ Audit Sign-Off ═══ -->
                <div class="card">
                    <div class="card-hdr" style="background:linear-gradient(90deg,rgba(139,92,246,0.08),transparent)">
                        <div class="dot" style="background:#8b5cf6"></div> Audit Sign-Off
                    </div>
                    <div style="padding:14px">
                        <div class="g2">
                            <div class="sign" style="border-color:${s.auditor_signed_at ? '#bbf7d0' : '#bfdbfe'};background:${s.auditor_signed_at ? '#f0fdf450' : '#eff6ff50'}">
                                <p style="font-size:9px;font-weight:900;text-transform:uppercase;color:${s.auditor_signed_at ? '#15803d' : '#2563eb'};margin-bottom:6px">Auditor</p>
                                ${s.auditor_signed_at
                    ? `<p style="font-size:11px;font-weight:700;color:#15803d">✓ Signed off</p><p style="font-size:9px;color:#64748b;margin-top:3px">Signed: ${s.auditor_signed_at}</p>${s.auditor_comments ? `<p style="font-size:10px;color:#475569;margin-top:6px;font-style:italic">"${esc(s.auditor_comments)}"</p>` : ''}`
                    : '<p style="font-size:10px;color:#94a3b8;font-style:italic">Pending</p>'
                }
                            </div>
                            <div class="sign" style="border-color:${s.manager_signed_at ? '#bbf7d0' : s.status === 'submitted' ? '#ddd6fe' : '#e2e8f0'};background:${s.manager_signed_at ? '#f0fdf450' : s.status === 'submitted' ? '#f5f3ff50' : '#f8fafc'}">
                                <p style="font-size:9px;font-weight:900;text-transform:uppercase;color:${s.manager_signed_at ? '#15803d' : s.status === 'submitted' ? '#7c3aed' : '#94a3b8'};margin-bottom:6px">Manager</p>
                                ${s.manager_signed_at
                    ? `<p style="font-size:11px;font-weight:700;color:#15803d">✓ Signed off</p><p style="font-size:9px;color:#64748b;margin-top:3px">Signed: ${s.manager_signed_at}</p>${s.manager_comments ? `<p style="font-size:10px;color:#475569;margin-top:6px;font-style:italic">"${esc(s.manager_comments)}"</p>` : ''}`
                    : s.status === 'submitted'
                        ? '<p style="font-size:10px;color:#7c3aed;font-style:italic">Awaiting manager sign-off</p>'
                        : '<p style="font-size:10px;color:#94a3b8;font-style:italic">Awaiting auditor sign-off first</p>'
                }
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </div>`;

            // ── Render to PDF ──
            const container = document.createElement('div');
            container.id = 'print-pdf-wrap';
            container.style.cssText = 'position:absolute;top:0;left:0;width:1050px;z-index:99999;background:#fff;overflow:hidden;padding:0;';
            container.innerHTML = html;
            document.body.appendChild(container);

            await new Promise(r => setTimeout(r, 600));

            const filename = `Audit_Report_${station.replace(/\s+/g, '_')}_${dateFrom}_to_${dateTo}.pdf`;
            const opt = {
                margin: [6, 10, 6, 10],
                filename,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, letterRendering: true, scrollY: -window.scrollY, scrollX: 0, windowWidth: 1050 },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
                pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
            };

            try {
                this.toast('Generating PDF…', 'info');
                const target = container.querySelector('.rpt');
                await html2pdf().set(opt).from(target).save();
                this.toast('PDF Downloaded');
            } catch (e) {
                console.error('PDF generation error:', e);
                this.toast('PDF Generation Failed', false);
            } finally {
                document.body.removeChild(container);
            }
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
