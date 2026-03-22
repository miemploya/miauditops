/**
 * MIAUDITOPS — Bank Reconciliation App (Alpine.js)
 * Nigerian standard format: Bank Recon Statement + Cashbook Adjustment
 */
function bankReconApp() {
    return {
        records: [], activeRecord: null, saving: false, loadingRecord: false,
        showCreateModal: false, newTitle: '', newBank: '', newAcct: '', newDate: new Date().toISOString().slice(0, 10),
        bankBalance: 0, cashbookBalance: 0,
        addItems: [], lessItems: [], cbDebits: [], cbCredits: [],
        notes: '', status: 'draft',

        // Computed
        get totalAdd() { return this.addItems.reduce((s, i) => s + (+i.amount || 0), 0); },
        get totalLess() { return this.lessItems.reduce((s, i) => s + (+i.amount || 0), 0); },
        get adjustedBankBalance() { return this.bankBalance + this.totalAdd; },
        get adjustedCashbookBalance() { return this.adjustedBankBalance - this.totalLess; },
        get totalCbDebits() { return this.cbDebits.reduce((s, i) => s + (+i.amount || 0), 0); },
        get totalCbCredits() { return this.cbCredits.reduce((s, i) => s + (+i.amount || 0), 0); },
        get adjustedCbBalance() { return this.cashbookBalance + this.totalCbDebits - this.totalCbCredits; },
        get isReconciled() { return Math.abs(this.adjustedCashbookBalance - this.adjustedCbBalance) < 0.01; },
        get discrepancy() { return this.adjustedCashbookBalance - this.adjustedCbBalance; },

        fmt(n) { return '\u20A6' + Number(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

        toast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = 'fixed bottom-6 right-6 z-50 px-5 py-3 rounded-xl text-sm font-bold shadow-2xl text-white transition-all';
            t.style.background = type === 'error' ? '#dc2626' : '#059669';
            t.textContent = msg; document.body.appendChild(t);
            setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3000);
        },

        async init() { await this.loadRecords(); },

        async loadRecords() {
            try {
                let r = await fetch('../ajax/bank_recon_api.php?action=list');
                let d = await r.json();
                if (d.success) this.records = d.records || [];
            } catch (e) { this.toast('Load failed', 'error'); }
        },

        async createRecord() {
            if (!this.newTitle) { this.toast('Title required', 'error'); return; }
            this.saving = true;
            try {
                let fd = new FormData();
                fd.append('action', 'create'); fd.append('title', this.newTitle);
                fd.append('bank_name', this.newBank); fd.append('account_number', this.newAcct);
                fd.append('statement_date', this.newDate);
                let r = await fetch('../ajax/bank_recon_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) { this.showCreateModal = false; this.newTitle = ''; this.newBank = ''; this.newAcct = ''; await this.loadRecords(); this.openRecord(d.id); }
            } catch (e) { this.toast('Error', 'error'); } finally { this.saving = false; }
        },

        async openRecord(id) {
            this.loadingRecord = true;
            try {
                let r = await fetch('../ajax/bank_recon_api.php?action=get&id=' + id);
                let d = await r.json();
                if (d.success) {
                    const rec = d.record;
                    this.activeRecord = rec;
                    this.bankBalance = +rec.bank_balance || 0;
                    this.cashbookBalance = +rec.cashbook_balance || 0;
                    this.addItems = JSON.parse(rec.add_items || '[]');
                    this.lessItems = JSON.parse(rec.less_items || '[]');
                    this.cbDebits = JSON.parse(rec.cb_debits || '[]');
                    this.cbCredits = JSON.parse(rec.cb_credits || '[]');
                    this.notes = rec.notes || '';
                    this.status = rec.status || 'draft';
                    this.$nextTick(() => lucide.createIcons());
                }
            } catch (e) { this.toast('Load failed', 'error'); } finally { this.loadingRecord = false; }
        },

        async saveRecord() {
            this.saving = true;
            try {
                let fd = new FormData();
                fd.append('action', 'save'); fd.append('id', this.activeRecord.id);
                fd.append('title', this.activeRecord.title);
                fd.append('bank_name', this.activeRecord.bank_name || '');
                fd.append('account_number', this.activeRecord.account_number || '');
                fd.append('statement_date', this.activeRecord.statement_date || '');
                fd.append('bank_balance', this.bankBalance);
                fd.append('cashbook_balance', this.cashbookBalance);
                fd.append('add_items', JSON.stringify(this.addItems));
                fd.append('less_items', JSON.stringify(this.lessItems));
                fd.append('cb_debits', JSON.stringify(this.cbDebits));
                fd.append('cb_credits', JSON.stringify(this.cbCredits));
                fd.append('notes', this.notes);
                fd.append('status', this.status);
                let r = await fetch('../ajax/bank_recon_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) this.toast('Saved successfully');
            } catch (e) { this.toast('Save failed', 'error'); } finally { this.saving = false; }
        },

        async deleteRecord(id) {
            if (!confirm('Delete this reconciliation?')) return;
            let fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
            await fetch('../ajax/bank_recon_api.php', { method: 'POST', body: fd });
            await this.loadRecords(); this.toast('Deleted');
        },

        goBack() { this.activeRecord = null; this.loadRecords(); },
        addRow(arr) { arr.push({ description: '', amount: 0 }); },
        removeRow(arr, idx) { arr.splice(idx, 1); },

        // ── PDF EXPORT ──
        exportPDF() {
            const rec = this.activeRecord;
            const f = this.fmt.bind(this);
            const esc = v => String(v || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
            const genDate = new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
            const stmtDate = rec.statement_date ? new Date(rec.statement_date).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : genDate;

            const css = `
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
                *{margin:0;padding:0;box-sizing:border-box}
                body{font-family:'Inter',sans-serif;color:#1e293b;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
                .page{width:210mm;min-height:297mm;padding:18mm 20mm 28mm 20mm;margin:0 auto;background:#fff;position:relative;page-break-after:always;page-break-inside:avoid;overflow:visible}
                .page:last-child{page-break-after:avoid}
                @page{size:A4;margin:14mm 0 16mm 0}
                @media print{body{background:none}.page{margin:0;width:100%;padding:4mm 20mm 14mm 20mm;min-height:auto}}
                table{width:100%;border-collapse:collapse}
                .footer{position:absolute;bottom:18mm;left:20mm;right:20mm;border-top:1px solid #f1f5f9;padding-top:8px;display:flex;justify-content:space-between;opacity:.5}
                @media print{.footer{bottom:0}}
            `;

            // Section 1: Bank Reconciliation Statement
            let sec1Rows = '';
            sec1Rows += `<tr style="background:#f0f9ff;border-bottom:2px solid #bae6fd"><td style="padding:10px 12px;font-weight:900;color:#0369a1;font-size:12px">Balance as per Bank Statement</td><td style="padding:10px 12px;text-align:right;font-weight:900;color:#0369a1;font-size:12px">${f(this.bankBalance)}</td></tr>`;
            this.addItems.forEach(item => {
                if (+item.amount) sec1Rows += `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:7px 12px 7px 24px;color:#059669;font-size:11px">Add: ${esc(item.description)}</td><td style="padding:7px 12px;text-align:right;font-weight:700;font-size:11px;color:#059669">${f(item.amount)}</td></tr>`;
            });
            sec1Rows += `<tr style="background:#f0fdf4;border-top:2px solid #86efac;border-bottom:2px solid #86efac"><td style="padding:10px 12px;font-weight:900;color:#166534;font-size:12px">Adjusted Bank Balance</td><td style="padding:10px 12px;text-align:right;font-weight:900;color:#166534;font-size:12px">${f(this.adjustedBankBalance)}</td></tr>`;
            sec1Rows += `<tr><td colspan="2" style="padding:6px"></td></tr>`;
            this.lessItems.forEach(item => {
                if (+item.amount) sec1Rows += `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:7px 12px 7px 24px;color:#dc2626;font-size:11px">Less: ${esc(item.description)}</td><td style="padding:7px 12px;text-align:right;font-weight:700;font-size:11px;color:#dc2626">(${f(item.amount)})</td></tr>`;
            });
            sec1Rows += `<tr style="background:#000"><td style="padding:12px;font-weight:900;color:#fff;font-size:12px;border-radius:0 0 0 8px">Adjusted Cashbook Balance</td><td style="padding:12px;text-align:right;font-weight:900;color:#34d399;font-size:13px;border-radius:0 0 8px 0">${f(this.adjustedCashbookBalance)}</td></tr>`;

            // Section 2: Cashbook Adjustment
            let sec2Rows = '';
            sec2Rows += `<tr style="background:#f0f9ff;border-bottom:2px solid #bae6fd"><td style="padding:10px 12px;font-weight:900;color:#0369a1;font-size:12px">Balance as per Cashbook</td><td style="padding:10px 12px;text-align:right;font-weight:900;font-size:12px;color:#0369a1">${f(this.cashbookBalance)}</td><td style="padding:10px 12px"></td></tr>`;
            this.cbDebits.forEach(item => {
                if (+item.amount) sec2Rows += `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:7px 12px 7px 24px;font-size:11px;color:#334155">${esc(item.description)}</td><td style="padding:7px 12px;text-align:right;font-weight:700;font-size:11px;color:#059669">${f(item.amount)}</td><td></td></tr>`;
            });
            this.cbCredits.forEach(item => {
                if (+item.amount) sec2Rows += `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:7px 12px 7px 24px;font-size:11px;color:#334155">${esc(item.description)}</td><td></td><td style="padding:7px 12px;text-align:right;font-weight:700;font-size:11px;color:#dc2626">${f(item.amount)}</td></tr>`;
            });
            sec2Rows += `<tr style="background:#000"><td style="padding:12px;font-weight:900;color:#fff;font-size:12px;border-radius:0 0 0 8px">Adjusted Cashbook Balance</td><td style="padding:12px;text-align:right;font-weight:900;color:#34d399;font-size:12px">${f(this.totalCbDebits + this.cashbookBalance)}</td><td style="padding:12px;text-align:right;font-weight:900;color:#fca5a5;font-size:12px;border-radius:0 0 8px 0">${f(this.totalCbCredits)}</td></tr>`;

            const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Bank Reconciliation — ${esc(rec.title)}</title><style>${css}</style></head><body>
            <div class="page">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #000;padding-bottom:12px;margin-bottom:20px">
                    <div><h1 style="font-size:16px;font-weight:900;text-transform:uppercase;letter-spacing:1px">Bank Reconciliation Statement</h1>
                    <p style="font-size:10px;color:#64748b;margin-top:4px">As at ${stmtDate}</p></div>
                    <div style="text-align:right"><p style="font-size:11px;font-weight:800">${esc(rec.title)}</p><p style="font-size:9px;color:#94a3b8">${esc(rec.bank_name || '')} ${rec.account_number ? '• Acct: ' + esc(rec.account_number) : ''}</p></div>
                </div>

                <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:24px">
                    <div style="background:#0369a1;padding:8px 12px"><p style="font-size:9px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:1px">Section 1 — Bank Reconciliation Statement</p></div>
                    <table><tbody>${sec1Rows}</tbody></table>
                </div>

                <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">
                    <div style="background:#0369a1;padding:8px 12px"><p style="font-size:9px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:1px">Section 2 — Cashbook Adjustment</p></div>
                    <table>
                    <thead><tr style="background:#f8fafc"><th style="padding:8px 12px;text-align:left;font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase">Description</th><th style="padding:8px 12px;text-align:right;font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase">Debit (\u20A6)</th><th style="padding:8px 12px;text-align:right;font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase">Credit (\u20A6)</th></tr></thead>
                    <tbody>${sec2Rows}</tbody></table>
                </div>

                ${this.isReconciled ? '<div style="margin-top:20px;background:#f0fdf4;border:2px solid #86efac;border-radius:10px;padding:12px;text-align:center"><p style="font-size:11px;font-weight:900;color:#166534">✅ RECONCILED — Both adjusted balances match</p></div>' : '<div style="margin-top:20px;background:#fef2f2;border:2px solid #fca5a5;border-radius:10px;padding:12px;text-align:center"><p style="font-size:11px;font-weight:900;color:#991b1b">❌ DISCREPANCY — Difference: ' + f(Math.abs(this.discrepancy)) + '</p></div>'}

                <div class="footer"><p style="font-size:7px;font-weight:900;text-transform:uppercase;letter-spacing:2px">Generated by MIAUDITOPS</p><p style="font-size:7px">${genDate}</p></div>
            </div></body></html>`;

            const w = window.open('', '_blank');
            w.document.write(html); w.document.close();
            setTimeout(() => { w.print(); }, 800);
        }
    };
}
