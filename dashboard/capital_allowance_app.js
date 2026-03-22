/**
 * MIAUDITOPS — Capital Allowance App (Alpine.js)
 */
function capitalAllowanceApp() {
    return {
        records: [], rates: [], activeRecord: null, entries: [], assets: [],
        saving: false, loading: false, showCreateModal: false, showRateModal: false, showAdditionModal: false, showCategoriesPanel: false,
        editingRate: null,
        selectedYear: null,
        newTitle: '', newMode: 'manual', newStartYear: new Date().getFullYear() - 5, newEndYear: new Date().getFullYear(),
        rateForm: { category: '', ia_rate: 15, aa_rate: 0, sort_order: 0 },
        addForm: { category: '', amount: 0, description: '', type: 'addition' },
        notes: '',

        fmt(n) { return '\u20A6' + Number(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        fmtShort(n) { const v = Math.abs(n || 0); if (v >= 1e9) return '\u20A6' + (n / 1e9).toFixed(1) + 'B'; if (v >= 1e6) return '\u20A6' + (n / 1e6).toFixed(1) + 'M'; if (v >= 1e3) return '\u20A6' + (n / 1e3).toFixed(0) + 'K'; return this.fmt(n); },

        toast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = 'fixed bottom-6 right-6 z-50 px-5 py-3 rounded-xl text-sm font-bold shadow-2xl text-white transition-all';
            t.style.background = type === 'error' ? '#dc2626' : '#059669';
            t.textContent = msg; document.body.appendChild(t);
            setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3000);
        },

        async init() {
            this.loading = true;
            try {
                let [rateR, recR] = await Promise.all([
                    fetch('../ajax/capital_allowance_api.php?action=list_rates'),
                    fetch('../ajax/capital_allowance_api.php?action=list')
                ]);
                let rateD = await rateR.json(), recD = await recR.json();
                if (rateD.success) this.rates = rateD.rates || [];
                if (recD.success) this.records = recD.records || [];
            } catch (e) { this.toast('Load failed', 'error'); } finally { this.loading = false; }
        },

        // ─── RECORD CRUD ───
        async createRecord() {
            if (!this.newTitle) { this.toast('Title required', 'error'); return; }
            this.saving = true;
            try {
                let fd = new FormData();
                fd.append('action', 'create'); fd.append('title', this.newTitle); fd.append('mode', this.newMode);
                fd.append('start_year', this.newStartYear); fd.append('end_year', this.newEndYear);
                let r = await fetch('../ajax/capital_allowance_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) { this.showCreateModal = false; this.newTitle = ''; await this.init(); this.openRecord(d.id); }
            } catch (e) { this.toast('Error', 'error'); } finally { this.saving = false; }
        },

        async openRecord(id) {
            this.loading = true;
            try {
                let r = await fetch('../ajax/capital_allowance_api.php?action=get&id=' + id);
                let d = await r.json();
                if (d.success) {
                    this.activeRecord = d.record;
                    this.entries = d.entries || [];
                    this.assets = d.assets || [];
                    this.notes = d.record.notes || '';
                    this.selectedYear = +d.record.start_year;
                    this.$nextTick(() => lucide.createIcons());
                }
            } catch (e) { this.toast('Load failed', 'error'); } finally { this.loading = false; }
        },

        async saveRecord() {
            this.saving = true;
            try {
                let fd = new FormData();
                fd.append('action', 'save'); fd.append('id', this.activeRecord.id);
                fd.append('title', this.activeRecord.title);
                fd.append('start_year', this.activeRecord.start_year);
                fd.append('end_year', this.activeRecord.end_year);
                fd.append('status', this.activeRecord.status);
                fd.append('notes', this.notes);
                await fetch('../ajax/capital_allowance_api.php', { method: 'POST', body: fd });

                // Save entries
                let fd2 = new FormData();
                fd2.append('action', 'save_entries'); fd2.append('record_id', this.activeRecord.id);
                fd2.append('entries', JSON.stringify(this.entries));
                await fetch('../ajax/capital_allowance_api.php', { method: 'POST', body: fd2 });

                this.toast('Saved successfully');
            } catch (e) { this.toast('Save failed', 'error'); } finally { this.saving = false; }
        },

        async deleteRecord(id) {
            if (!confirm('Delete this CA record?')) return;
            let fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
            await fetch('../ajax/capital_allowance_api.php', { method: 'POST', body: fd });
            await this.init(); this.toast('Deleted');
        },

        goBack() { this.activeRecord = null; this.init(); },

        // ─── RATE CRUD ───
        openAddRate() { this.editingRate = null; this.rateForm = { category: '', ia_rate: 15, aa_rate: 0, sort_order: this.rates.length }; this.showRateModal = true; },
        openEditRate(r) { this.editingRate = r; this.rateForm = { category: r.category, ia_rate: +r.ia_rate, aa_rate: +r.aa_rate, sort_order: +r.sort_order }; this.showRateModal = true; },

        async saveRate() {
            if (!this.rateForm.category) { this.toast('Category required', 'error'); return; }
            this.saving = true;
            try {
                let fd = new FormData(); fd.append('action', 'save_rate');
                if (this.editingRate) fd.append('id', this.editingRate.id);
                fd.append('category', this.rateForm.category); fd.append('ia_rate', this.rateForm.ia_rate);
                fd.append('aa_rate', this.rateForm.aa_rate); fd.append('sort_order', this.rateForm.sort_order);
                let r = await fetch('../ajax/capital_allowance_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) { this.showRateModal = false; let [rR] = await Promise.all([fetch('../ajax/capital_allowance_api.php?action=list_rates')]); let rD = await rR.json(); if (rD.success) this.rates = rD.rates; this.toast('Rate saved'); }
            } catch (e) { this.toast('Error', 'error'); } finally { this.saving = false; }
        },

        async deleteRate(id) {
            if (!confirm('Delete?')) return;
            let fd = new FormData(); fd.append('action', 'delete_rate'); fd.append('id', id);
            await fetch('../ajax/capital_allowance_api.php', { method: 'POST', body: fd });
            let rR = await fetch('../ajax/capital_allowance_api.php?action=list_rates'); let rD = await rR.json(); if (rD.success) this.rates = rD.rates;
            this.toast('Deleted');
        },

        // ─── ENTRY MANAGEMENT ───
        openAddEntry(year) {
            this.addForm = { category: this.rates[0]?.category || '', amount: 0, description: '', type: 'addition' };
            this.selectedYear = year;
            this.showAdditionModal = true;
        },

        addEntry() {
            if (!this.addForm.category || !this.addForm.amount) { this.toast('Category and amount required', 'error'); return; }
            this.entries.push({
                category: this.addForm.category,
                year: this.selectedYear,
                type: this.addForm.type,
                amount: +this.addForm.amount,
                description: this.addForm.description
            });
            this.showAdditionModal = false;
            this.toast('Entry added — remember to Save');
        },

        removeEntry(idx) {
            this.entries.splice(idx, 1);
        },

        getEntriesForYear(year) {
            return this.entries.filter(e => +e.year === year);
        },

        // ─── YEARS ───
        get years() {
            if (!this.activeRecord) return [];
            const s = +this.activeRecord.start_year, e = +this.activeRecord.end_year;
            const arr = [];
            for (let y = s; y <= e; y++) arr.push(y);
            return arr;
        },

        // ─── SCHEDULE ENGINE ───
        getRate(category) {
            const r = this.rates.find(r => r.category === category);
            return r ? { ia: +r.ia_rate, aa: +r.aa_rate } : { ia: 0, aa: 0 };
        },

        // Build the full multi-year schedule
        buildSchedule() {
            const mode = this.activeRecord?.mode || 'manual';
            const categories = this.rates.map(r => r.category);
            const startYear = +this.activeRecord?.start_year || new Date().getFullYear();
            const endYear = +this.activeRecord?.end_year || new Date().getFullYear();
            const schedule = {}; // year -> { category -> data }

            // Initialize carry-forward state per category
            const carry = {};
            categories.forEach(cat => { carry[cat] = { costBf: 0, totalIA: 0, totalAA: 0 }; });

            for (let year = startYear; year <= endYear; year++) {
                schedule[year] = {};

                categories.forEach(cat => {
                    const rate = this.getRate(cat);
                    let openingCost = carry[cat].costBf;
                    let additions = 0;
                    let disposals = 0;

                    if (mode === 'asset_register') {
                        // From fixed assets
                        this.assets.forEach(a => {
                            if (a.category !== cat) return;
                            const pYear = a.purchase_date ? new Date(a.purchase_date).getFullYear() : year + 1;
                            if (pYear < year) { /* already in opening */ }
                            else if (pYear === year) { additions += +a.cost || 0; }
                            if (a.status === 'disposed' && a.disposal_date) {
                                const dYear = new Date(a.disposal_date).getFullYear();
                                if (dYear === year) disposals += +a.cost || 0;
                            }
                        });
                        // Opening cost for asset_register: all assets purchased before this year
                        if (year === startYear) {
                            openingCost = 0;
                            this.assets.forEach(a => {
                                if (a.category !== cat) return;
                                const pYear = a.purchase_date ? new Date(a.purchase_date).getFullYear() : year + 1;
                                if (pYear < year) openingCost += +a.cost || 0;
                            });
                        }
                    } else {
                        // Manual entries
                        if (year === startYear) {
                            this.entries.filter(e => +e.year === year && e.category === cat && e.type === 'opening').forEach(e => { openingCost += +e.amount || 0; });
                        }
                        this.entries.filter(e => +e.year === year && e.category === cat && e.type === 'addition').forEach(e => { additions += +e.amount || 0; });
                        this.entries.filter(e => +e.year === year && e.category === cat && e.type === 'disposal').forEach(e => { disposals += +e.amount || 0; });
                    }

                    const closingCost = openingCost + additions - disposals;

                    // Initial Allowance (only on additions)
                    const ia = additions * (rate.ia / 100);

                    // Tax WDV b/f = prior closingCost - prior cumulative IA - prior cumulative AA
                    let priorIA = carry[cat].totalIA;
                    let priorAA = carry[cat].totalAA;
                    const taxWdvBf = openingCost - priorIA - priorAA;

                    // For additions in this year, they get IA but AA starts from WDV after IA
                    // AA = (taxWdvBf + additions - disposals - ia) * aa_rate
                    const aaBase = Math.max(0, taxWdvBf + additions - disposals - ia);
                    const aa = aaBase * (rate.aa / 100);

                    const taxWdvCf = Math.max(0, aaBase - aa);
                    const totalAllowance = ia + aa;

                    schedule[year][cat] = {
                        openingCost, additions, disposals, closingCost,
                        iaRate: rate.ia, ia,
                        aaRate: rate.aa, taxWdvBf: Math.max(0, taxWdvBf), aa, taxWdvCf,
                        totalAllowance
                    };

                    // Update carry-forward
                    carry[cat] = {
                        costBf: closingCost,
                        totalIA: priorIA + ia,
                        totalAA: priorAA + aa
                    };
                });
            }

            return schedule;
        },

        get fullSchedule() { return this.buildSchedule(); },

        getYearSchedule(year) {
            const s = this.fullSchedule;
            return s[year] || {};
        },

        scheduleCategories() {
            return this.rates.map(r => r.category);
        },

        yearTotal(year, field) {
            const ys = this.getYearSchedule(year);
            return Object.values(ys).reduce((s, d) => s + (d[field] || 0), 0);
        },

        // Grand totals across all years
        get grandTotalAllowance() {
            let total = 0;
            this.years.forEach(y => { total += this.yearTotal(y, 'totalAllowance'); });
            return total;
        },

        // ─── PDF EXPORT ───
        exportPDF() {
            const rec = this.activeRecord;
            const cats = this.scheduleCategories();
            const f = this.fmt.bind(this);
            const esc = v => String(v || '').replace(/&/g, '&amp;').replace(/</, '&lt;');
            const genDate = new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

            const css = `
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
                *{margin:0;padding:0;box-sizing:border-box}
                body{font-family:'Inter',sans-serif;color:#1e293b;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
                .page{width:297mm;min-height:210mm;padding:14mm 16mm 20mm 16mm;margin:0 auto;background:#fff;position:relative;page-break-after:always;overflow:visible}
                .page:last-child{page-break-after:avoid}
                @media print{body{background:none}.page{margin:0;width:100%}}
                @page{size:A4 landscape;margin:0}
                table{width:100%;border-collapse:collapse}
                th,td{padding:5px 7px;font-size:8.5px;border:1px solid #d1d5db}
                th{background:#1e293b;color:#fff;font-weight:800;text-transform:uppercase;letter-spacing:.5px;font-size:7.5px}
                .sec{background:#000;color:#f59e0b;font-weight:900;font-size:7.5px;text-transform:uppercase;letter-spacing:1px}
                .bold-row{font-weight:900;background:#f1f5f9}
                .nbv-row{background:#000;color:#fff;font-weight:900}
                .rate-row{background:#f8fafc;color:#64748b;font-weight:700;font-style:italic}
                td.num{text-align:right;font-variant-numeric:tabular-nums}
                .footer{position:absolute;bottom:8mm;left:16mm;right:16mm;border-top:1px solid #f1f5f9;padding-top:4px;display:flex;justify-content:space-between;opacity:.5;font-size:6.5px}
            `;

            let pages = '';
            this.years.forEach(year => {
                const ys = this.getYearSchedule(year);
                const colH = cats.map(c => `<th style="text-align:center;min-width:70px">${esc(c)}</th>`).join('');
                const fv = (field) => cats.map(c => `<td class="num">${ys[c] && ys[c][field] ? f(ys[c][field]) : '—'}</td>`).join('');
                const tot = (field) => `<td class="num" style="font-weight:900">${f(this.yearTotal(year, field))}</td>`;
                const rv = (field) => cats.map(c => `<td class="num">${ys[c] ? (ys[c][field] > 0 ? ys[c][field] + '%' : 'N/A') : '—'}</td>`).join('');

                pages += `
                <div class="page">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">
                        <div><h1 style="font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:1px">Capital Allowance Schedule</h1>
                        <p style="font-size:9px;color:#64748b;margin-top:2px">Year Ended 31st December ${year}</p></div>
                        <div style="text-align:right"><p style="font-size:10px;font-weight:800">${esc(rec.title)}</p><p style="font-size:7px;color:#94a3b8;text-transform:uppercase;letter-spacing:2px">Confidential</p></div>
                    </div>
                    <table>
                        <thead><tr><th style="text-align:left;min-width:120px"></th>${colH}<th style="text-align:right;min-width:90px">TOTAL</th></tr></thead>
                        <tbody>
                            <tr class="sec"><td colspan="${cats.length + 2}">Cost</td></tr>
                            <tr><td style="font-weight:700">As at 1/1/${year}</td>${fv('openingCost')}${tot('openingCost')}</tr>
                            <tr><td style="padding-left:14px">Additions</td>${fv('additions')}${tot('additions')}</tr>
                            <tr><td style="padding-left:14px">Disposal</td>${fv('disposals')}${tot('disposals')}</tr>
                            <tr class="bold-row"><td style="font-weight:900">As at 31/12/${year}</td>${fv('closingCost')}${tot('closingCost')}</tr>

                            <tr class="sec"><td colspan="${cats.length + 2}">Initial Allowance (IA)</td></tr>
                            <tr class="rate-row"><td>IA Rate</td>${rv('iaRate')}<td></td></tr>
                            <tr><td style="font-weight:700">IA on Additions</td>${fv('ia')}${tot('ia')}</tr>

                            <tr class="sec"><td colspan="${cats.length + 2}">Annual Allowance (AA)</td></tr>
                            <tr class="rate-row"><td>AA Rate</td>${rv('aaRate')}<td></td></tr>
                            <tr><td>Tax WDV b/f</td>${fv('taxWdvBf')}${tot('taxWdvBf')}</tr>
                            <tr><td style="font-weight:700">AA for the Year</td>${fv('aa')}${tot('aa')}</tr>

                            <tr><td colspan="${cats.length + 2}" style="border:none;height:6px"></td></tr>
                            <tr class="nbv-row"><td style="font-weight:900">Tax WDV c/f 31/12/${year}</td>${fv('taxWdvCf')}${tot('taxWdvCf')}</tr>

                            <tr><td colspan="${cats.length + 2}" style="border:none;height:6px"></td></tr>
                            <tr style="background:#059669"><td style="font-weight:900;color:#fff;font-size:9px">Total Allowance (IA + AA)</td>${cats.map(c => `<td class="num" style="font-weight:900;color:#fff">${ys[c] ? f(ys[c].totalAllowance) : '—'}</td>`).join('')}<td class="num" style="font-weight:900;color:#fff;font-size:10px">${f(this.yearTotal(year, 'totalAllowance'))}</td></tr>
                        </tbody>
                    </table>
                    <div class="footer"><p style="font-weight:900;text-transform:uppercase;letter-spacing:2px">Generated by MIAUDITOPS</p><p>${genDate}</p></div>
                </div>`;
            });

            const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Capital Allowance — ${esc(rec.title)}</title><style>${css}</style></head><body>${pages}</body></html>`;
            const w = window.open('', '_blank');
            w.document.write(html); w.document.close();
            setTimeout(() => { w.print(); }, 800);
        }
    };
}
