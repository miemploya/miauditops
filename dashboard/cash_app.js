/**
 * MIAUDITOPS — Cash Module AlpineJS App
 */
function cashModule(initSales, initReqs, initCats, initDepts, userId, isApprover) {
    return {
        // State
        currentTab: 'sales',
        tabs: [
            {id:'sales', label:'Cash Sales', icon:'coins'},
            {id:'ledger', label:'Cash Ledger', icon:'book-open'},
            {id:'requisition', label:'Cash Requisition', icon:'hand-coins'},
            {id:'analysis', label:'Cash Analysis', icon:'pie-chart'},
            {id:'report', label:'Cash Report', icon:'file-text'}
        ],
        userId: userId,
        isApprover: isApprover,
        allSales: initSales || [],
        allReqs: initReqs || [],
        categories: initCats || [],
        departments: initDepts || [],
        
        // Forms
        showSaleForm: false,
        saleForm: { sale_date: new Date().toISOString().slice(0,10), amount: '', description: '', department: '' },
        showReqForm: false,
        reqForm: { type: 'expense', category_id: '', amount: '', description: '', bank_name: '', account_number: '' },
        showCatMgr: false,
        catForm: { name: '', description: '' },

        // Ledger
        ledgerMonth: new Date().toISOString().slice(0,7),
        ledgerEntries: [],
        ledgerOpening: 0,

        // Analysis
        analysisMonth: new Date().toISOString().slice(0,7),
        analysisBreakdown: [],
        analysisTotals: {},

        // Report
        reportMonth: new Date().toISOString().slice(0,7),
        reportData: {},

        // Computed
        get pendingSalesCount() { return this.allSales.filter(s => s.status === 'pending').length; },
        get pendingReqsCount() { return this.allReqs.filter(r => r.status === 'pending').length; },
        get monthlySales() { return this.groupByMonth(this.allSales, 'sale_date'); },
        get monthlyReqs() { return this.groupByMonth(this.allReqs, 'created_at'); },

        // Init
        init() {
            this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
        },

        // ── Helpers ──
        fmt(val) {
            const n = parseFloat(val) || 0;
            return '₦' + n.toLocaleString('en-NG', {minimumFractionDigits:2, maximumFractionDigits:2});
        },
        groupByMonth(items, dateField) {
            const groups = {};
            items.forEach(item => {
                const d = (item[dateField] || '').substring(0, 7);
                const label = d ? new Date(d + '-15').toLocaleDateString('en-US', {month:'long', year:'numeric'}) : 'Unknown';
                if (!groups[label]) groups[label] = {month: label, items: [], open: true};
                groups[label].items.push(item);
            });
            return Object.values(groups);
        },
        async api(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k,v]) => fd.append(k, v));
            const r = await fetch('../ajax/cash_api.php', {method:'POST', body: fd});
            return r.json();
        },
        toast(msg, type) {
            const colors = {success:'#059669', error:'#dc2626', info:'#2563eb'};
            const t = document.createElement('div');
            t.textContent = msg;
            t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;padding:14px 24px;border-radius:14px;font-size:13px;font-weight:700;color:#fff;box-shadow:0 8px 32px rgba(0,0,0,0.3);transition:opacity 0.3s;background:' + (colors[type]||colors.info);
            document.body.appendChild(t);
            setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3000);
        },

        // ── Cash Sales ──
        async postSale() {
            if (!this.saleForm.amount || this.saleForm.amount <= 0) return this.toast('Enter a valid amount', 'error');
            if (!this.saleForm.description) return this.toast('Description is required', 'error');
            const res = await this.api({action:'post_sale', ...this.saleForm});
            if (res.success) {
                this.toast('Cash sale posted!', 'success');
                this.showSaleForm = false;
                this.saleForm = {sale_date: new Date().toISOString().slice(0,10), amount:'', description:'', department:''};
                location.reload();
            } else { this.toast(res.message || 'Error', 'error'); }
        },
        async confirmSale(id) {
            if (!confirm('Confirm this cash sale has been received?')) return;
            const res = await this.api({action:'confirm_sale', sale_id: id});
            if (res.success) { this.toast('Sale confirmed ✓', 'success'); location.reload(); }
            else { this.toast(res.message || 'Error', 'error'); }
        },
        async rejectSale(id) {
            const reason = prompt('Reason for rejection:');
            if (reason === null) return;
            const res = await this.api({action:'reject_sale', sale_id: id, reason: reason || 'No reason given'});
            if (res.success) { this.toast('Sale rejected', 'info'); location.reload(); }
            else { this.toast(res.message || 'Error', 'error'); }
        },
        async deleteSale(id) {
            if (!confirm('Delete this sale entry?')) return;
            const res = await this.api({action:'delete_sale', sale_id: id});
            if (res.success) { this.toast('Sale deleted', 'info'); location.reload(); }
            else { this.toast(res.message || 'Error', 'error'); }
        },

        // ── Cash Requisitions ──
        async createRequisition() {
            if (!this.reqForm.description) return this.toast('Description is required', 'error');
            if (!this.reqForm.amount || this.reqForm.amount <= 0) return this.toast('Enter a valid amount', 'error');
            const res = await this.api({action:'create_requisition', ...this.reqForm});
            if (res.success) {
                this.toast('Requisition ' + res.requisition_number + ' submitted!', 'success');
                this.showReqForm = false;
                this.reqForm = {type:'expense', category_id:'', amount:'', description:'', bank_name:'', account_number:''};
                location.reload();
            } else { this.toast(res.message || 'Error', 'error'); }
        },
        async approveReq(id) {
            if (!confirm('Approve this cash requisition?')) return;
            const res = await this.api({action:'approve_requisition', req_id: id});
            if (res.success) { this.toast('Requisition approved ✓', 'success'); location.reload(); }
            else { this.toast(res.message || 'Error', 'error'); }
        },
        async rejectReq(id) {
            const reason = prompt('Reason for rejection:');
            if (reason === null) return;
            const res = await this.api({action:'reject_requisition', req_id: id, reason: reason || 'No reason given'});
            if (res.success) { this.toast('Requisition rejected', 'info'); location.reload(); }
            else { this.toast(res.message || 'Error', 'error'); }
        },

        // ── Categories ──
        async saveCategory() {
            if (!this.catForm.name) return this.toast('Category name required', 'error');
            const res = await this.api({action:'save_category', name: this.catForm.name, cat_description: this.catForm.description});
            if (res.success) { this.toast('Category saved', 'success'); this.catForm = {name:'',description:''}; location.reload(); }
            else { this.toast(res.message || 'Error', 'error'); }
        },
        async deleteCategory(id) {
            if (!confirm('Delete this category?')) return;
            const res = await this.api({action:'delete_category', category_id: id});
            if (res.success) { this.categories = this.categories.filter(c => c.id != id); this.toast('Category deleted', 'info'); }
        },

        // ── Ledger ──
        async loadLedger() {
            const res = await this.api({action:'get_ledger', month: this.ledgerMonth});
            if (res.success) {
                this.ledgerEntries = res.entries;
                this.ledgerOpening = res.opening_balance;
            }
            this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
        },

        // ── Analysis ──
        async loadAnalysis() {
            const res = await this.api({action:'get_analysis', month: this.analysisMonth});
            if (res.success) {
                this.analysisBreakdown = res.breakdown;
                this.analysisTotals = res.totals;
            }
        },

        // ── Report ──
        async loadReport() {
            const res = await this.api({action:'get_report', month: this.reportMonth});
            if (res.success) { this.reportData = res.report; }
        },
        printReport() {
            window.print();
        }
    };
}
