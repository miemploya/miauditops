/**
 * MIAUDITOPS — Fixed Assets App (Alpine.js)
 */
function fixedAssetsApp() {
    return {
        currentTab: 'register',
        categories: [],
        assets: [],
        saving: false,
        loading: false,
        showAssetModal: false,
        showCatModal: false,
        editingAsset: null,
        editingCat: null,
        scheduleYear: new Date().getFullYear(),
        filterCat: '',

        // Asset form
        form: { asset_name: '', asset_code: '', category: '', purchase_date: new Date().toISOString().slice(0, 10), cost: 0, salvage_value: 0, serial_number: '', location: '', status: 'active', disposal_date: '', disposal_amount: 0, notes: '' },
        // Category form
        catForm: { name: '', dep_rate: 0, sort_order: 0 },

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
                let [catR, assetR] = await Promise.all([
                    fetch('../ajax/fixed_asset_api.php?action=list_categories'),
                    fetch('../ajax/fixed_asset_api.php?action=list')
                ]);
                let catD = await catR.json(), assetD = await assetR.json();
                if (catD.success) this.categories = catD.categories || [];
                if (assetD.success) this.assets = assetD.assets || [];
            } catch (e) { this.toast('Load failed', 'error'); } finally { this.loading = false; }
        },

        // ─── ASSET CRUD ───
        openAddAsset() { this.editingAsset = null; this.form = { asset_name: '', asset_code: '', category: this.categories[0]?.name || '', purchase_date: new Date().toISOString().slice(0, 10), cost: 0, salvage_value: 0, serial_number: '', location: '', status: 'active', disposal_date: '', disposal_amount: 0, notes: '' }; this.showAssetModal = true; },
        openEditAsset(a) { this.editingAsset = a; this.form = { ...a, cost: +a.cost, salvage_value: +a.salvage_value, disposal_amount: +a.disposal_amount }; this.showAssetModal = true; },

        async saveAsset() {
            if (!this.form.asset_name) { this.toast('Asset name required', 'error'); return; }
            this.saving = true;
            try {
                let fd = new FormData();
                const action = this.editingAsset ? 'update' : 'create';
                fd.append('action', action);
                if (this.editingAsset) fd.append('id', this.editingAsset.id);
                for (let k in this.form) fd.append(k, this.form[k] ?? '');
                let r = await fetch('../ajax/fixed_asset_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) { this.showAssetModal = false; await this.init(); this.toast(action === 'create' ? 'Asset added' : 'Asset updated'); }
                else this.toast(d.message || 'Failed', 'error');
            } catch (e) { this.toast('Error', 'error'); } finally { this.saving = false; }
        },

        async deleteAsset(id) {
            if (!confirm('Delete this asset?')) return;
            let fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
            await fetch('../ajax/fixed_asset_api.php', { method: 'POST', body: fd });
            await this.init(); this.toast('Deleted');
        },

        // ─── CATEGORY CRUD ───
        openAddCat() { this.editingCat = null; this.catForm = { name: '', dep_rate: 0, sort_order: this.categories.length }; this.showCatModal = true; },
        openEditCat(c) { this.editingCat = c; this.catForm = { name: c.name, dep_rate: +c.dep_rate, sort_order: +c.sort_order }; this.showCatModal = true; },

        async saveCat() {
            if (!this.catForm.name) { this.toast('Name required', 'error'); return; }
            this.saving = true;
            try {
                let fd = new FormData(); fd.append('action', 'save_category');
                if (this.editingCat) fd.append('id', this.editingCat.id);
                fd.append('name', this.catForm.name); fd.append('dep_rate', this.catForm.dep_rate); fd.append('sort_order', this.catForm.sort_order);
                let r = await fetch('../ajax/fixed_asset_api.php', { method: 'POST', body: fd });
                let d = await r.json();
                if (d.success) { this.showCatModal = false; await this.init(); this.toast('Category saved'); }
            } catch (e) { this.toast('Error', 'error'); } finally { this.saving = false; }
        },

        async deleteCat(id) {
            if (!confirm('Delete this category?')) return;
            let fd = new FormData(); fd.append('action', 'delete_category'); fd.append('id', id);
            await fetch('../ajax/fixed_asset_api.php', { method: 'POST', body: fd });
            await this.init(); this.toast('Category deleted');
        },

        // ─── COMPUTED ───
        get filteredAssets() { return this.filterCat ? this.assets.filter(a => a.category === this.filterCat) : this.assets; },
        get activeAssets() { return this.assets.filter(a => a.status === 'active'); },

        getRate(catName) {
            const c = this.categories.find(c => c.name === catName);
            return c ? +c.dep_rate : 0;
        },

        calcDepreciation(asset, year) {
            const cost = +asset.cost || 0;
            const salvage = +asset.salvage_value || 0;
            const rate = this.getRate(asset.category) / 100;
            const purchaseYear = asset.purchase_date ? new Date(asset.purchase_date).getFullYear() : year;
            const annualDep = cost * rate;
            const depreciable = cost - salvage;
            if (rate === 0 || purchaseYear > year) return { annualDep: 0, accumDep: 0, nbv: cost };

            const yearsUsed = year - purchaseYear;
            const accumDep = Math.min(depreciable, annualDep * (yearsUsed + 1));
            const prevAccumDep = Math.min(depreciable, annualDep * yearsUsed);
            const yearDep = accumDep - prevAccumDep;
            return { annualDep: yearDep, accumDep, nbv: cost - accumDep };
        },

        // Total stats
        get totalCost() { return this.assets.reduce((s, a) => s + (+a.cost || 0), 0); },
        get totalAccumDep() { return this.assets.reduce((s, a) => s + this.calcDepreciation(a, this.scheduleYear).accumDep, 0); },
        get totalNBV() { return this.totalCost - this.totalAccumDep; },

        // ─── SCHEDULE ENGINE ───
        buildSchedule() {
            const year = this.scheduleYear;
            const prevYear = year - 1;
            const schedule = [];

            this.categories.forEach(cat => {
                const catAssets = this.assets.filter(a => a.category === cat.name);
                if (catAssets.length === 0 && false) return; // always show category

                let openingCost = 0, additions = 0, disposals = 0;
                let openingDep = 0, yearDep = 0;
                let prevNBV = 0;

                catAssets.forEach(a => {
                    const cost = +a.cost || 0;
                    const salvage = +a.salvage_value || 0;
                    const rate = (+cat.dep_rate || 0) / 100;
                    const pYear = a.purchase_date ? new Date(a.purchase_date).getFullYear() : year;
                    const annDep = cost * rate;
                    const depreciable = cost - salvage;

                    // Cost section
                    if (pYear < year) { openingCost += cost; }
                    else if (pYear === year) { additions += cost; }

                    // Disposal
                    if (a.status === 'disposed' && a.disposal_date) {
                        const dYear = new Date(a.disposal_date).getFullYear();
                        if (dYear === year) disposals += cost;
                    }

                    // Depreciation
                    if (rate > 0 && pYear <= year) {
                        const yearsUsedPrev = Math.max(0, prevYear - pYear);
                        const yearsUsedCurr = Math.max(0, year - pYear);
                        const oPrevDep = Math.min(depreciable, annDep * yearsUsedPrev);
                        const oOpenDep = Math.min(depreciable, annDep * yearsUsedCurr);
                        const oCloseDep = Math.min(depreciable, annDep * (yearsUsedCurr + 1));
                        openingDep += oOpenDep;
                        yearDep += oCloseDep - oOpenDep;

                        // Previous year NBV
                        prevNBV += cost - Math.min(depreciable, annDep * (yearsUsedPrev + 1));
                    } else if (pYear <= prevYear) {
                        prevNBV += cost; // no depreciation (like land)
                    }
                });

                const closingCost = openingCost + additions - disposals;
                const closingDep = openingDep + yearDep;
                const closingNBV = closingCost - closingDep;

                schedule.push({
                    category: cat.name,
                    rate: +cat.dep_rate || 0,
                    openingCost, additions, disposals, closingCost,
                    openingDep, yearDep, closingDep,
                    closingNBV, prevNBV,
                    assetCount: catAssets.length
                });
            });

            return schedule;
        },

        get scheduleData() { return this.buildSchedule(); },

        scheduleTotal(field) { return this.scheduleData.reduce((s, r) => s + (r[field] || 0), 0); },

        // ─── PDF EXPORT ───
        exportPDF() {
            const year = this.scheduleYear;
            const sched = this.scheduleData;
            const f = this.fmt.bind(this);
            const esc = v => String(v || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
            const genDate = new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
            const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

            const css = `
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
                *{margin:0;padding:0;box-sizing:border-box}
                body{font-family:'Inter',sans-serif;color:#1e293b;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
                .page{width:297mm;min-height:210mm;padding:14mm 16mm;margin:0 auto;background:#fff;position:relative;page-break-after:always;overflow:hidden}
                .page:last-child{page-break-after:avoid}
                @media print{body{background:none}.page{margin:0;width:100%}}
                @page{size:A4 landscape;margin:0}
                table{width:100%;border-collapse:collapse}
                th,td{padding:6px 8px;font-size:9px;border:1px solid #d1d5db}
                th{background:#1e293b;color:#fff;font-weight:800;text-transform:uppercase;letter-spacing:.5px;font-size:8px}
                .row-label{font-weight:700;color:#334155;background:#f8fafc}
                .row-bold{font-weight:900;background:#f1f5f9;color:#000}
                .row-section{background:#000;color:#f59e0b;font-weight:900;font-size:8px;text-transform:uppercase;letter-spacing:1px}
                .row-nbv{background:#000;color:#fff;font-weight:900}
                .row-rate{background:#f8fafc;color:#64748b;font-weight:700;font-style:italic}
                td.num{text-align:right;font-variant-numeric:tabular-nums}
            `;

            // Build header columns
            const cats = sched.map(s => s.category);
            const colHeaders = cats.map(c => `<th style="text-align:center;min-width:80px">${esc(c)}</th>`).join('');
            const fv = (arr, field) => arr.map(s => `<td class="num">${s[field] ? f(s[field]) : '—'}</td>`).join('');
            const totalCol = (field) => `<td class="num" style="font-weight:900">${f(sched.reduce((s, r) => s + (r[field] || 0), 0))}</td>`;

            const scheduleTable = `
            <table>
                <thead><tr><th style="text-align:left;min-width:130px"></th>${colHeaders}<th style="text-align:right;min-width:100px">TOTAL</th></tr></thead>
                <tbody>
                    <tr class="row-section"><td colspan="${cats.length + 2}">COST</td></tr>
                    <tr class="row-label"><td>AS AT 1/1/${year}</td>${fv(sched, 'openingCost')}${totalCol('openingCost')}</tr>
                    <tr><td style="padding-left:16px">Additions</td>${fv(sched, 'additions')}${totalCol('additions')}</tr>
                    <tr><td style="padding-left:16px">Disposal</td>${fv(sched, 'disposals')}${totalCol('disposals')}</tr>
                    <tr class="row-bold"><td>AS AT 31/12/${year}</td>${fv(sched, 'closingCost')}${totalCol('closingCost')}</tr>

                    <tr class="row-section"><td colspan="${cats.length + 2}">DEPRECIATION</td></tr>
                    <tr class="row-label"><td>AS AT 1/1/${year}</td>${fv(sched, 'openingDep')}${totalCol('openingDep')}</tr>
                    <tr><td style="padding-left:16px">For the Year</td>${fv(sched, 'yearDep')}${totalCol('yearDep')}</tr>
                    <tr class="row-bold"><td>AS AT 31/12/${year}</td>${fv(sched, 'closingDep')}${totalCol('closingDep')}</tr>

                    <tr><td colspan="${cats.length + 2}" style="border:none;height:8px"></td></tr>
                    <tr class="row-nbv"><td>AS AT 31/12/${year}</td>${fv(sched, 'closingNBV')}${totalCol('closingNBV')}</tr>
                    <tr class="row-label"><td>AS AT 31/12/${year - 1}</td>${fv(sched, 'prevNBV')}${totalCol('prevNBV')}</tr>

                    <tr class="row-rate"><td>RATES</td>${sched.map(s => `<td class="num">${s.rate > 0 ? s.rate + '%' : 'N/A'}</td>`).join('')}<td></td></tr>
                </tbody>
            </table>`;

            const pageFooter = `<div style="position:absolute;bottom:10mm;left:16mm;right:16mm;border-top:1px solid #f1f5f9;padding-top:6px;display:flex;justify-content:space-between;opacity:.5"><p style="font-size:7px;font-weight:900;text-transform:uppercase;letter-spacing:2px">Generated by MIAUDITOPS</p><p style="font-size:7px">${genDate}</p></div>`;

            const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Fixed Asset Schedule</title><style>${css}</style></head><body>
            <div class="page">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px">
                    <div><h1 style="font-size:16px;font-weight:900;text-transform:uppercase;letter-spacing:1px">Fixed Asset Schedule As At 31st December ${year}</h1></div>
                    <div style="text-align:right"><p style="font-size:8px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:2px">Confidential</p><p style="font-size:7px;color:#cbd5e1;margin-top:2px">miauditops.ng</p></div>
                </div>
                ${scheduleTable}
                ${pageFooter}
            </div>
            </body></html>`;

            const w = window.open('', '_blank');
            w.document.write(html);
            w.document.close();
            setTimeout(() => { w.print(); }, 800);
        }
    };
}
