// retail_engine.js

document.addEventListener('DOMContentLoaded', () => {
    // Only init if we are on a page where the context is set and the grid exists
    if(document.getElementById('productsTableBody')) {
        loadProducts();
        loadPurchases();
        loadAuditSessions();
        loadSuppliers();
    }
});

// GLOBAL STATE
let retailProducts = [];
let retailCategories = [];
let retailSuppliers = [];




// Unit Cost Preview Engine
document.addEventListener('DOMContentLoaded', () => {
    const qtyInput = document.querySelector('input[name="quantity_added"]');
    const costInput = document.querySelector('input[name="total_cost"]');
    const previewCard = document.getElementById('costPreviewCard');
    const previewAmount = document.getElementById('costPreviewAmount');
    
    if(qtyInput && costInput && previewCard) {
        const calculatePreview = () => {
            const q = parseFloat(qtyInput.value) || 0;
            const c = parseFloat(costInput.value) || 0;
            if(q > 0 && c > 0) {
                previewCard.classList.remove('hidden');
                previewAmount.innerText = '₦' + (c / q).toLocaleString(undefined, {minimumFractionDigits: 2});
            } else {
                previewCard.classList.add('hidden');
            }
        };
        qtyInput.addEventListener('input', calculatePreview);
        costInput.addEventListener('input', calculatePreview);
    }
});

// ============================================
// CATEGORIES
// ============================================
async function openCategoryModal() {
    document.getElementById('categoryModal').classList.remove('hidden');
    loadCategories();
}

async function loadCategories() {
    try {
        const res = await fetch(`retail_api.php?action=get_categories&_t=${Date.now()}`);
        const json = await res.json();
        if(json.success) {
            retailCategories = json.data;
            renderCategories();
        }
    } catch(e) {}
}

function renderCategories() {
    const list = document.getElementById('categoryListBody');
    if(!list) return;
    if(retailCategories.length === 0) {
        list.innerHTML = `<div class="p-8 text-center text-slate-500 text-sm">No custom categories created yet.</div>`;
        return;
    }
    list.innerHTML = retailCategories.map(c => `
        <div class="flex justify-between items-center p-3 border-b border-slate-100 dark:border-slate-800 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-800/50 rounded-lg group transition">
            <span class="font-bold text-slate-800 dark:text-slate-200">${c.name}</span>
            <button onclick="deleteCategory(${c.id})" class="text-slate-400 hover:text-red-500 opacity-0 group-hover:opacity-100 transition"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
        </div>
    `).join('');
    lucide.createIcons();

    // Also update datalist for add product modal to pull from the DB table exclusively
    const dl = document.getElementById('category_list');
    if(dl) dl.innerHTML = '<option value="">-- Select Category --</option>' + retailCategories.map(c => `<option value="${c.name}">${c.name}</option>`).join('');
}

async function addCategory(e) {
    e.preventDefault();
    const input = document.getElementById('newCategoryName');
    const name = input.value.trim();
    if(!name) return;
    
    const btn = document.getElementById('catAddBtn');
    btn.disabled = true;
    btn.innerText = '...';
    
    const fd = new FormData();
    fd.append('action', 'add_category');
    fd.append('name', name);
    
    try {
        const res = await fetch(`retail_api.php`, { method: 'POST', body: fd });
        const json = await res.json();
        if(json.success) {
            input.value = '';
            loadCategories();
        } else {
            Swal.fire('Error', json.message, 'error');
        }
    } catch(e) {}
    
    btn.disabled = false;
    btn.innerText = 'Add';
}

async function deleteCategory(id) {
    if(!confirm("Delete this category?")) return;
    const fd = new FormData();
    fd.append('action', 'delete_category');
    fd.append('id', id);
    try {
        await fetch(`retail_api.php`, { method: 'POST', body: fd });
        loadCategories();
    } catch(e) {}
}

// ============================================
// TAB 1: PRODUCT REGISTRY
// ============================================
async function loadProducts() {
    try {
        const res = await fetch(`retail_api.php?action=get_products`);
        const json = await res.json();
        if(json.success) {
            retailProducts = json.data;
            renderProducts(retailProducts);
        } else {
            console.error('Failed to load products', json.message);
        }
    } catch (e) {
        console.error(e);
    }
}

function renderProducts(data) {
    const tbody = document.getElementById('productsTableBody');
    if(!tbody) return;
    
    const colSpan = (typeof userIsAdmin !== 'undefined' && userIsAdmin) ? 9 : 8;
    if(data.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${colSpan}" class="p-8 text-center text-slate-500">No products registered yet.</td></tr>`;
        return;
    }
    
    tbody.innerHTML = data.map((p, index) => {
        let actionCol = '';
        if (typeof userIsAdmin !== 'undefined' && userIsAdmin) {
            actionCol = `
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right">
                <div class="flex items-center justify-end gap-2">
                    <button onclick="editProduct(${p.id})" class="p-1.5 text-slate-400 dark:text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 rounded-lg transition-colors bg-white dark:bg-slate-900 shadow-sm border border-slate-200 dark:border-slate-800" title="Edit Product">
                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                    </button>
                    <button onclick="deleteProduct(${p.id})" class="p-1.5 text-slate-400 dark:text-slate-500 hover:text-red-600 dark:hover:text-red-400 rounded-lg transition-colors bg-white dark:bg-slate-900 shadow-sm border border-slate-200 dark:border-slate-800" title="Delete Product">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>
            </td>`;
        }
        
        const costPrice = Number(p.cost_price) || 0;
        const packQty = Number(p.pack_qty) || 1;
        const bulkUnit = p.bulk_unit || 'Pack';
        const unit = p.unit || 'pcs';
        
        return `
        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group">
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 font-bold text-slate-500">${index + 1}</td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 font-medium">${p.category || '-'}</td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 font-bold">${p.name}</td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-slate-500">${p.sku || '-'}</td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold ${p.expiry_date ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400'}">${p.expiry_date || 'N/A'}</td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right font-mono text-slate-500">₦${costPrice.toLocaleString(undefined,{minimumFractionDigits:2})} / ${packQty} <span class="text-[10px] uppercase opacity-70">${bulkUnit}</span></td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right font-mono text-amber-600 dark:text-amber-500">₦${Number(p.unit_cost).toLocaleString(undefined,{minimumFractionDigits:2})} <span class="text-[10px] uppercase opacity-70">/${unit}</span></td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right font-mono text-emerald-600 dark:emerald-400">₦${Number(p.selling_price).toLocaleString(undefined,{minimumFractionDigits:2})} <span class="text-[10px] uppercase opacity-70">/${unit}</span></td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right font-bold">${p.current_system_stock} ${unit}</td>
            ${actionCol}
        </tr>`;
    }).join('');

    // Categories are now populated via PHP on initial load.
}

function filterProducts() {
    const q = document.getElementById('productSearch').value.toLowerCase();
    const expiryToggle = document.getElementById('expiryFilterToggle') ? document.getElementById('expiryFilterToggle').checked : false;
    
    // Setup date boundary for "Expiring Soon" (30 Days from today)
    const today = new Date();
    const thresholdDate = new Date();
    thresholdDate.setDate(today.getDate() + 30);

    const filtered = retailProducts.filter(p => {
        let matchesQuery = p.name.toLowerCase().includes(q) || 
                           (p.category && p.category.toLowerCase().includes(q)) || 
                           (p.sku && p.sku.toLowerCase().includes(q));
                           
        let matchesExpiry = true;
        if (expiryToggle) {
            if (!p.expiry_date) {
                matchesExpiry = false;
            } else {
                const expDate = new Date(p.expiry_date);
                // Matches if expiry date is before or equal to 30 days from now, and hasn't already passed excessively (though past shows up as well)
                matchesExpiry = expDate <= thresholdDate;
            }
        }
        
        return matchesQuery && matchesExpiry;
    });
    
    renderProducts(filtered);
}

async function submitCount(e, type) {
    e.preventDefault();
    const form = e.target;
    const cleanFD = new FormData();
    const linesData = {};
    const sessId = form.querySelector('input[name="session_id"]');
    
    // Collect lines
    form.querySelectorAll('input[name^="lines"]').forEach(input => {
        const match = input.name.match(/lines\[(\d+)\]\[physical_qty\]/);
        if(match && input.value !== '') {
            linesData[match[1]] = input.value;
        }
    });
    
    cleanFD.append('action', 'save_audit');
    cleanFD.append('type', type);
    cleanFD.append('session_name', form.elements['session_name'].value);
    cleanFD.append('audit_date', form.elements['audit_date'].value);
    if(sessId && sessId.value) cleanFD.append('session_id', sessId.value);
    cleanFD.append('lines', JSON.stringify(linesData));
    
    // Choose which button to animate
    const btnId = type === 'draft' ? 'btnDraftCount' : 'btnFinalizeCount';
    const btn = document.getElementById(btnId);
    let ogText = '';
    if(btn) {
        ogText = btn.innerHTML;
        btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving...`;
        btn.disabled = true;
    }

    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: cleanFD });
        const json = await res.json();
        if(json.success) {
            document.getElementById('addCountModal').classList.add('hidden');
            form.reset();
            // Clear hidden session_id tracker
            if(sessId) sessId.value = '';
            
            Swal.fire({icon: 'success', title: 'Complete', text: json.message, timer: 1500, showConfirmButton: false});
            loadAuditSessions();
            if(type === 'finalize') loadProducts(); // update main system stock logic
        } else {
            Swal.fire('Error', json.message, 'error');
        }
    } catch(err) {
        Swal.fire('Error', 'Network request failed', 'error');
    }
    if(btn) {
        btn.innerHTML = ogText;
        btn.disabled = false;
    }
    if(typeof lucide !== 'undefined') lucide.createIcons();
}

window.resumeAudit = async function(session_id) {
    // Open modal
    openCountModal();
    const form = document.getElementById('addCountForm');
    
    // Fetch session details
    const session = auditSessions.find(s => s.id == session_id);
    if(session) {
        form.elements['session_name'].value = session.session_name;
        form.elements['audit_date'].value = session.audit_date;
        let sidInput = form.querySelector('input[name="session_id"]');
        if(!sidInput) {
            sidInput = document.createElement('input');
            sidInput.type = 'hidden';
            sidInput.name = 'session_id';
            form.appendChild(sidInput);
        }
        sidInput.value = session_id;
    }

    // Fetch lines
    const fd = new FormData();
    fd.append('action', 'get_audit_lines');
    fd.append('session_id', session_id);
    
    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: fd });
        const json = await res.json();
        if(json.success) {
            json.data.forEach(ln => {
                const input = form.querySelector(`input[name="lines[${ln.product_id}][physical_qty]"]`);
                if(input) {
                    input.value = ln.physical_qty;
                }
            });
        }
    } catch(err) {}
}

async function deleteProduct(id) {
    if(!confirm("Are you sure you want to permanently delete this product?")) return;
    const fd = new FormData();
    fd.append('action', 'delete_product');
    fd.append('id', id);
    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: fd });
        const json = await res.json();
        if(json.success) {
            Swal.fire({icon: 'success', title: 'Deleted', text: json.message, timer: 1500, showConfirmButton: false});
            loadProducts();
        } else {
            Swal.fire('Error', json.message, 'error');
        }
    } catch(err) {
        Swal.fire('Error', 'Network request failed', 'error');
    }
}

function editProduct(id) {
    const p = retailProducts.find(x => x.id == id);
    if(!p) return;
    
    // Check if Edit form exists, if not, create it
    let modal = document.getElementById('editProductModal');
    if(!modal) {
        // Clone the add modal and modify it for edit
        const addModal = document.getElementById('addProductModal');
        modal = addModal.cloneNode(true);
        modal.id = 'editProductModal';
        
        const h3 = modal.querySelector('h3');
        if (h3) h3.innerText = 'Edit Retail Product';
        
        const form = modal.querySelector('form');
        form.id = 'editProductForm';
        form.setAttribute('onsubmit', 'submitEditProduct(event)');
        
        // Add ID hidden field
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'product_id';
        form.appendChild(idInput);
        
        // Change action hidden value
        const actionInput = form.querySelector('input[name="action"]');
        if(actionInput) actionInput.value = 'update_product';
        
        // Change close buttons to close edit modal
        modal.querySelectorAll('button').forEach(btn => {
            if(btn.hasAttribute('onclick') && btn.getAttribute('onclick').includes('addProductModal')) {
                btn.setAttribute('onclick', "document.getElementById('editProductModal').classList.add('hidden')");
            }
        });
        
        document.body.appendChild(modal);
    }
    
    // Populate form
    const form = modal.querySelector('form');
    form.elements['product_id'].value = p.id;
    form.elements['name'].value = p.name || '';
    if(form.elements['category']) form.elements['category'].value = p.category || '';
    if(form.elements['supplier_id']) form.elements['supplier_id'].value = p.supplier_id || '';
    form.elements['sku'].value = p.sku || '';
    form.elements['expiry_date'].value = p.expiry_date || '';
    form.elements['bulk_unit'].value = p.bulk_unit || 'Pack';
    form.elements['unit'].value = p.unit || 'pcs';
    form.elements['cost_price'].value = p.cost_price || '';
    form.elements['pack_qty'].value = p.pack_qty || '';
    form.elements['selling_price'].value = p.selling_price || '';
    
    modal.classList.remove('hidden');
    lucide.createIcons();
}

async function submitEditProduct(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const btn = e.target.querySelector('button[type="submit"]');
    const ogText = btn.innerHTML;
    btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving...`;
    btn.disabled = true;
    
    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: fd });
        const json = await res.json();
        if(json.success) {
            document.getElementById('editProductModal').classList.add('hidden');
            Swal.fire({icon: 'success', title: 'Updated', text: json.message, timer: 1500, showConfirmButton: false});
            loadProducts();
        } else {
            Swal.fire('Error', json.message, 'error');
        }
    } catch(err) {
        Swal.fire('Error', 'Network request failed', 'error');
    }
    btn.innerHTML = ogText;
    btn.disabled = false;
    lucide.createIcons();
}

function handleProductUpload(input) {
    const file = input.files[0];
    if(!file) return;

    const status = document.getElementById('importStatus');
    status.classList.remove('hidden');
    status.className = 'mt-4 text-sm font-mono text-blue-600';
    status.innerText = `Parsing ${file.name}...`;

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const jsonSheet = XLSX.utils.sheet_to_json(firstSheet, { header: 1 });
            
            if(jsonSheet.length < 2) {
                status.className = 'mt-4 text-sm font-mono text-red-500';
                status.innerText = "Error: File appears to be empty.";
                return;
            }

            const headerMapper = {
                'category': null, 'item name': null, 'name': null,
                'sku': null, 'barcode': null, 
                'bulk type': null, 'retail type': null,
                'cost': null, 'pack cost': null, 'bulk cost': null,
                'pack qty': null, 'items per bulk': null,
                'price': null, 'selling price': null,
                'qty': null, 'stock': null, 'quantity': null, 'system stock': null
            };

            const rawHeaders = jsonSheet[0];
            rawHeaders.forEach((col, index) => {
                const c = String(col).toLowerCase().trim();
                for(let key in headerMapper) {
                    if(c.includes(key) && headerMapper[key] === null) {
                        headerMapper[key] = index;
                    }
                }
            });

            // Map data
            const mappedItems = [];
            for(let i = 1; i < jsonSheet.length; i++) {
                const row = jsonSheet[i];
                if(row.length === 0 || !row.join('').trim()) continue;
                
                const nameIdx = headerMapper['item name'] ?? headerMapper['name'];
                if(nameIdx === null || !row[nameIdx]) continue;

                mappedItems.push({
                    name: row[nameIdx],
                    category: headerMapper['category'] !== null ? row[headerMapper['category']] : '',
                    sku: (headerMapper['sku'] ?? headerMapper['barcode']) !== null ? row[(headerMapper['sku'] ?? headerMapper['barcode'])] : '',
                    bulk_unit: (headerMapper['bulk type']) !== null ? String(row[(headerMapper['bulk type'])]) : 'Carton',
                    unit: (headerMapper['retail type']) !== null ? String(row[(headerMapper['retail type'])]) : 'Piece',
                    cost_price: (headerMapper['pack cost'] ?? headerMapper['bulk cost'] ?? headerMapper['cost']) !== null ? row[(headerMapper['pack cost'] ?? headerMapper['bulk cost'] ?? headerMapper['cost'])] : 0,
                    pack_qty: (headerMapper['items per bulk'] ?? headerMapper['pack qty']) !== null ? row[(headerMapper['items per bulk'] ?? headerMapper['pack qty'])] : 1,
                    selling_price: (headerMapper['selling price'] ?? headerMapper['price']) !== null ? row[(headerMapper['selling price'] ?? headerMapper['price'])] : 0,
                    qty: (headerMapper['system stock'] ?? headerMapper['stock'] ?? headerMapper['qty'] ?? headerMapper['quantity']) !== null ? row[(headerMapper['system stock'] ?? headerMapper['stock'] ?? headerMapper['qty'] ?? headerMapper['quantity'])] : 0,
                });
            }

            if(mappedItems.length === 0) {
                status.className = 'mt-4 text-sm font-mono text-red-500';
                status.innerText = "Error: Could not extract valid products. Ensure 'Item Name' column exists.";
                return;
            }

            status.className = 'mt-4 text-sm font-mono text-amber-600';
            status.innerText = `Mapped ${mappedItems.length} items. Uploading to server...`;

            uploadMappedProducts(mappedItems, status, input);

        } catch(err) {
            console.error(err);
            status.className = 'mt-4 text-sm font-mono text-red-500';
            status.innerText = "Error: Failed to read Excel file.";
        }
    };
    reader.readAsArrayBuffer(file);
}

async function uploadMappedProducts(items, statusEl, fileInput) {
    const fd = new FormData();
    fd.append('action', 'import_products');
    fd.append('items', JSON.stringify(items));

    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: fd });
        const json = await res.json();
        if(json.success) {
            statusEl.className = 'mt-4 text-sm font-mono text-emerald-600';
            statusEl.innerText = json.message;
            setTimeout(() => {
                document.getElementById('importProductsModal').classList.add('hidden');
                fileInput.value = '';
                statusEl.classList.add('hidden');
                loadProducts();
            }, 1000);
            Swal.fire({icon: 'success', title: 'Import Complete', text: json.message, timer: 2000});
        } else {
            statusEl.className = 'mt-4 text-sm font-mono text-red-500';
            statusEl.innerText = json.message;
        }
    } catch(err) {
        statusEl.className = 'mt-4 text-sm font-mono text-red-500';
        statusEl.innerText = "Network Error.";
    }
}

// ============================================
// TAB 2: PURCHASES / ADDITIONS
// ============================================
let retailPurchases = [];

async function loadPurchases() {
    try {
        const res = await fetch(`retail_api.php?action=get_purchases`);
        const json = await res.json();
        if(json.success) {
            retailPurchases = json.data;
            renderPurchases(retailPurchases);
        }
    } catch (e) {
        console.error(e);
    }
}

let purchaseGroupsOpen = {};
window.togglePurchaseGroup = function(month) {
    purchaseGroupsOpen[month] = !purchaseGroupsOpen[month];
    renderPurchases(retailPurchases);
};

function renderPurchases(data) {
    const tbody = document.getElementById('purchasesTableBody');
    if(!tbody) return;
    
    if(data.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-slate-500">No deliveries logged yet.</td></tr>`;
        return;
    }
    
    const groups = {};
    data.forEach(p => {
        let dateObj = new Date(p.purchase_date);
        let monthYear = isNaN(dateObj) ? 'Unknown Date' : dateObj.toLocaleString('default', { month: 'long', year: 'numeric' });
        if(!groups[monthYear]) groups[monthYear] = [];
        groups[monthYear].push(p);
    });

    let html = '';
    for(const [month, items] of Object.entries(groups)) {
        const isOpen = purchaseGroupsOpen[month];
        html += `
        <tr class="bg-slate-100 dark:bg-slate-800/80 cursor-pointer" onclick="togglePurchaseGroup('${month}')">
            <td colspan="6" class="p-3 font-bold text-slate-700 dark:text-slate-300">
                <div class="flex items-center gap-2">
                    <i data-lucide="${isOpen ? 'chevron-down' : 'chevron-right'}" class="w-4 h-4 flex-shrink-0"></i>
                    ${month} <span class="font-normal text-xs text-slate-500 ml-2">(${items.length} items)</span>
                </div>
            </td>
        </tr>`;
        
        if(isOpen) {
            html += items.map(p => `
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group">
                <td class="p-4 border-b border-slate-200 dark:border-slate-800 font-medium pl-8">${p.purchase_date}</td>
                <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-slate-500">${p.reference || '-'}</td>
                <td class="p-4 border-b border-slate-200 dark:border-slate-800 font-bold">${p.product_name}</td>
                <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right font-bold text-emerald-600 dark:text-emerald-400">+${p.quantity_added}</td>
                <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right font-mono">₦${Number(p.total_cost).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right">
                    <button onclick="editPurchase(${p.id})" class="text-blue-500 hover:text-blue-700 p-1.5 bg-blue-50 dark:bg-blue-900/20 rounded-lg mr-1 transition" title="Edit"><i data-lucide="edit-3" class="w-4 h-4 inline"></i></button>
                    <button onclick="deletePurchase(${p.id})" class="text-red-500 hover:text-red-700 p-1.5 bg-red-50 dark:bg-red-900/20 rounded-lg transition" title="Delete"><i data-lucide="trash-2" class="w-4 h-4 inline"></i></button>
                </td>
            </tr>
            `).join('');
        }
    }
    tbody.innerHTML = html;
    if(typeof lucide !== 'undefined') lucide.createIcons();
}

function filterPurchases() {
    const q = document.getElementById('purchaseSearch').value.toLowerCase();
    const filtered = retailPurchases.filter(p => 
        p.product_name.toLowerCase().includes(q) || 
        (p.reference && p.reference.toLowerCase().includes(q))
    );
    // Auto-open groups when filtering
    if (q.length > 0) {
        Object.keys(purchaseGroupsOpen).forEach(k => purchaseGroupsOpen[k] = true);
    }
    renderPurchases(filtered);
}

function openAddPurchaseModal() {
    const select = document.getElementById('purchaseProductSelect');
    if(select) {
        select.innerHTML = '<option value="">-- Choose Product --</option>' + retailProducts.map(p => `<option value="${p.id}">${p.name} (Stock: ${p.current_system_stock})</option>`).join('');
    }
    document.getElementById('addPurchaseModal').classList.remove('hidden');
}

async function submitPurchase(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const btn = e.target.querySelector('button[type="submit"]');
    const ogText = btn.innerHTML;
    btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving...`;
    btn.disabled = true;
    
    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: fd });
        const json = await res.json();
        if(json.success) {
            document.getElementById('addPurchaseModal').classList.add('hidden');
            e.target.reset();
            Swal.fire({icon: 'success', title: 'Saved', text: json.message, timer: 1500, showConfirmButton: false});
            loadPurchases();
            loadProducts(); // Refresh master stock count
        } else {
            Swal.fire('Error', json.message, 'error');
        }
    } catch(err) {
        Swal.fire('Error', 'Network request failed', 'error');
    }
    btn.innerHTML = ogText;
    btn.disabled = false;
    if(typeof lucide !== 'undefined') lucide.createIcons();
}

window.deletePurchase = async function(id) {
    const { value: reason } = await Swal.fire({
        title: 'Delete Addition?',
        text: 'This will reverse the system stock. You must provide a reason.',
        icon: 'warning',
        input: 'text',
        inputPlaceholder: 'Enter reason for deletion...',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Delete Globally',
        inputValidator: (value) => {
            if (!value) return 'You need to write a reason!'
        }
    });

    if (reason) {
        const fd = new FormData();
        fd.append('action', 'delete_purchase');
        fd.append('id', id);
        fd.append('reason', reason);

        try {
            const res = await fetch('retail_api.php', { method: 'POST', body: fd });
            const json = await res.json();
            if(json.success) {
                Swal.fire('Deleted!', json.message, 'success');
                loadPurchases();
                loadProducts(); 
            } else {
                Swal.fire('Error', json.message, 'error');
            }
        } catch(e) {
            Swal.fire('Error', 'Network error', 'error');
        }
    }
}

window.editPurchase = function(id) {
    const pur = retailPurchases.find(p => p.id == id);
    if(!pur) return;
    
    let modal = document.getElementById('editPurchaseModal');
    if(!modal) {
        const addModal = document.getElementById('addPurchaseModal');
        modal = addModal.cloneNode(true);
        modal.id = 'editPurchaseModal';
        
        const h3 = modal.querySelector('h3');
        if (h3) h3.innerText = 'Edit Stock Delivery';
        
        const form = modal.querySelector('form');
        form.id = 'editPurchaseForm';
        form.setAttribute('onsubmit', 'submitEditPurchase(event)');
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'purchase_id';
        form.appendChild(idInput);
        
        const actionInput = form.querySelector('input[name="action"]');
        if(actionInput) actionInput.value = 'update_purchase';
        
        modal.querySelectorAll('button').forEach(btn => {
            if(btn.hasAttribute('onclick') && btn.getAttribute('onclick').includes('addPurchaseModal')) {
                btn.setAttribute('onclick', "document.getElementById('editPurchaseModal').classList.add('hidden')");
            }
        });
        
        document.body.appendChild(modal);
    }
    
    const form = modal.querySelector('form');
    
    const select = form.querySelector('select[name="product_id"]');
    select.innerHTML = '<option value="">-- Choose Product --</option>' + retailProducts.map(p => `<option value="${p.id}">${p.name} (Stock: ${p.current_system_stock})</option>`).join('');
    
    const supSelect = form.querySelector('select[name="supplier_id"]');
    if(window.globalSupplierList) {
        supSelect.innerHTML = '<option value="">-- No Supplier Map --</option>' + window.globalSupplierList.map(s => `<option value="${s.id}">${s.name}</option>`).join('');
    }
    
    form.elements['purchase_id'].value = pur.id;
    form.elements['product_id'].value = pur.product_id;
    if(form.elements['supplier_id']) form.elements['supplier_id'].value = pur.supplier_id || '';
    form.elements['purchase_date'].value = pur.purchase_date;
    form.elements['reference'].value = pur.reference || '';
    form.elements['quantity_added'].value = pur.quantity_added;
    form.elements['total_cost'].value = pur.total_cost || '';
    
    modal.classList.remove('hidden');
}

window.submitEditPurchase = async function(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const btn = e.target.querySelector('button[type="submit"]');
    const ogText = btn.innerHTML;
    btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving...`;
    btn.disabled = true;
    
    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: fd });
        const json = await res.json();
        if(json.success) {
            document.getElementById('editPurchaseModal').classList.add('hidden');
            Swal.fire({icon: 'success', title: 'Saved', text: json.message, timer: 1500, showConfirmButton: false});
            loadPurchases();
            loadProducts();
        } else {
            Swal.fire('Error', json.message, 'error');
        }
    } catch(err) {
        Swal.fire('Error', 'Network request failed', 'error');
    }
    btn.innerHTML = ogText;
    btn.disabled = false;
    if(typeof lucide !== 'undefined') lucide.createIcons();
}

function handlePurchaseUpload(input) {
    const file = input.files[0];
    if(!file) return;

    const status = document.getElementById('importPurchaseStatus');
    status.classList.remove('hidden');
    status.className = 'mt-4 text-sm font-mono text-blue-600';
    status.innerText = `Parsing ${file.name}...`;

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const jsonSheet = XLSX.utils.sheet_to_json(firstSheet, { header: 1 });
            
            if(jsonSheet.length < 2) {
                status.className = 'mt-4 text-sm font-mono text-red-500';
                status.innerText = "Error: File empty."; return;
            }

            const headerMapper = {'date': null, 'item name': null, 'name': null, 'qty': null, 'quantity': null, 'cost': null, 'total cost': null, 'reference': null, 'ref': null};
            const rawHeaders = jsonSheet[0];
            rawHeaders.forEach((col, index) => {
                const c = String(col).toLowerCase().trim();
                for(let key in headerMapper) { if(c.includes(key) && headerMapper[key] === null) headerMapper[key] = index; }
            });

            const mappedItems = [];
            for(let i = 1; i < jsonSheet.length; i++) {
                const row = jsonSheet[i];
                if(row.length === 0 || !row.join('').trim()) continue;
                const nameIdx = headerMapper['item name'] ?? headerMapper['name'];
                if(nameIdx === null || !row[nameIdx]) continue;

                mappedItems.push({
                    name: row[nameIdx],
                    date: headerMapper['date'] !== null ? row[headerMapper['date']] : '',
                    qty: (headerMapper['qty'] ?? headerMapper['quantity']) !== null ? row[(headerMapper['qty'] ?? headerMapper['quantity'])] : 0,
                    cost: (headerMapper['total cost'] ?? headerMapper['cost']) !== null ? row[(headerMapper['total cost'] ?? headerMapper['cost'])] : 0,
                    reference: (headerMapper['reference'] ?? headerMapper['ref']) !== null ? row[(headerMapper['reference'] ?? headerMapper['ref'])] : '',
                });
            }

            if(mappedItems.length === 0) {
                status.className = 'mt-4 text-sm font-mono text-red-500';
                status.innerText = "Error: Could not map data."; return;
            }

            status.className = 'mt-4 text-sm font-mono text-amber-600';
            status.innerText = `Mapped ${mappedItems.length} delivery records. Uploading...`;

            uploadMappedPurchases(mappedItems, status, input);
        } catch(err) {
            status.className = 'mt-4 text-sm font-mono text-red-500';
            status.innerText = "Failed to parse file.";
        }
    };
    reader.readAsArrayBuffer(file);
}

async function uploadMappedPurchases(items, statusEl, fileInput) {
    const fd = new FormData();
    fd.append('action', 'import_purchases');
    fd.append('items', JSON.stringify(items));
    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: fd });
        const json = await res.json();
        if(json.success) {
            Swal.fire('Complete', json.message, 'success');
            document.getElementById('importPurchasesModal').classList.add('hidden');
            fileInput.value = '';
            statusEl.classList.add('hidden');
            loadPurchases();
            loadProducts();
        } else {
            statusEl.className = 'mt-4 text-sm font-mono text-red-500';
            statusEl.innerText = json.message;
        }
    } catch(err) {
        statusEl.innerText = "Network Error.";
    }
}

// ============================================
// TAB 3 & 4: PHYSICAL COUNT & RECONCILIATION
// ============================================
let auditSessions = [];
let activeSessionLines = [];
let activeSessionId = null;
let activeEditReason = null;

async function loadAuditSessions() {
    try {
        const res = await fetch(`retail_api.php?action=get_audit_sessions&_t=${Date.now()}`);
        const json = await res.json();
        if(json.success) {
            auditSessions = json.data;
            renderAuditSessions(auditSessions);
            renderReconSessions(auditSessions);
            if(typeof renderSalesSessions === 'function') renderSalesSessions(auditSessions);
            if(typeof renderReportSessions === 'function') renderReportSessions(auditSessions);
        }
    } catch (e) {
        console.error(e);
    }
}

function renderAuditSessions(data) {
    const tbody = document.getElementById('countTableBody');
    if(!tbody) return;
    
    if(data.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="p-8 text-center text-slate-500">No physical counts performed.</td></tr>`;
        return;
    }
    
    tbody.innerHTML = data.map(s => {
        let statusBadge = '';
        let clickAction = `viewSessionRecon(${s.id})`;

        if (s.status === 'draft') {
            statusBadge = '<span class="px-2 py-1 rounded bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-xs font-bold uppercase tracking-wider"><i data-lucide="edit-2" class="w-3 h-3 inline"></i> Draft / Resume</span>';
            clickAction = `resumeAudit(${s.id})`;
        } else if (s.status === 'open') {
            statusBadge = '<span class="px-2 py-1 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-xs font-bold uppercase tracking-wider">Unresolved</span>';
        } else {
            statusBadge = '<span class="px-2 py-1 rounded bg-slate-100 dark:bg-slate-800 text-slate-500 text-xs font-bold uppercase tracking-wider"><i data-lucide="lock" class="w-3 h-3 inline"></i> Locked</span>';
        }

        return `
        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group cursor-pointer" onclick="${clickAction}">
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 font-medium">${s.audit_date}</td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 font-bold text-blue-600 dark:text-blue-400">${s.session_name || 'No Title'}</td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-center font-bold">${s.total_items_counted} Items</td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right font-mono">₦${Number(s.total_physical_value).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
            <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-center">
                ${statusBadge}
            </td>
        </tr>
    `}).join('');
    lucide.createIcons();
}

function openCountModal() {
    const tbody = document.getElementById('countGridBody');
    if(!tbody) return;
    const form = document.getElementById('addCountForm');
    form.reset();
    form.elements['session_id'].value = '';
    form.elements['audit_date'].value = new Date().toISOString().split('T')[0];
    // Don't clear activeEditReason here — unlockAudit sets it BEFORE calling this
    
    tbody.innerHTML = retailProducts.map(p => `
        <tr class="count-row" data-name="${p.name.toLowerCase()}">
            <td class="p-3 border-b border-slate-100 dark:border-slate-800 pl-6">
                <input type="hidden" name="lines[${p.id}][product_id]" value="${p.id}">
                <input type="hidden" name="lines[${p.id}][system_qty]" value="${p.current_system_stock}">
                <input type="hidden" name="lines[${p.id}][unit_cost]" value="${p.unit_cost}">
                <input type="hidden" name="lines[${p.id}][selling_price]" value="${p.selling_price}">
                <div class="font-bold text-slate-800 dark:text-slate-200">${p.name}</div>
                <div class="text-xs text-slate-400">${p.sku || 'No SKU'} · Cost: ₦${p.unit_cost}</div>
            </td>
            <td id="disp_sys_${p.id}" class="p-3 border-b border-slate-100 dark:border-slate-800 text-right font-mono text-slate-500">${p.current_system_stock}</td>
            <td class="p-3 border-b border-slate-100 dark:border-slate-800 text-right w-40">
                <input type="number" step="0.01" name="lines[${p.id}][physical_qty]" class="w-full px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm focus:ring-2 focus:ring-blue-500 text-right font-bold text-blue-600 dark:text-blue-400" placeholder="Count...">
            </td>
        </tr>
    `).join('');
    document.getElementById('addCountModal').classList.remove('hidden');
}

function filterCountGrid() {
    const q = document.getElementById('countGridSearch').value.toLowerCase();
    document.querySelectorAll('.count-row').forEach(row => {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
}

window.submitCountAs = async function(type) {
    const form = document.getElementById('addCountForm');
    const fd = new FormData(form);
    
    let hasValues = false;
    for(let entry of fd.entries()) {
        if(entry[0].includes('[physical_qty]') && entry[1].trim() !== '') {
            hasValues = true; break;
        }
    }
    if(!hasValues) {
        Swal.fire('Empty Count', 'You must input physical quantities for at least one item.', 'warning');
        return;
    }

    const cleanFD = new FormData();
    cleanFD.append('action', 'save_audit_session');
    
    // Pass session_id if it exists
    const sessId = form.elements['session_id'];
    if(sessId && sessId.value && sessId.value !== '') cleanFD.append('session_id', sessId.value);
    
    cleanFD.append('session_name', fd.get('session_name'));
    cleanFD.append('audit_date', fd.get('audit_date'));
    cleanFD.append('action_type', type);
    
    let linesData = [];
    retailProducts.forEach(p => {
        let pQty = fd.get(`lines[${p.id}][physical_qty]`);
        if(pQty && pQty.trim() !== '') {
            linesData.push({
                product_id: p.id,
                system_qty: fd.get(`lines[${p.id}][system_qty]`),
                physical_qty: pQty,
                unit_cost: fd.get(`lines[${p.id}][unit_cost]`),
                selling_price: fd.get(`lines[${p.id}][selling_price]`),
            });
        }
    });
    cleanFD.append('lines', JSON.stringify(linesData));

    if (activeEditReason) {
        cleanFD.append('edit_reason', activeEditReason);
    }

    const btnId = type === 'draft' ? 'btnDraftCount' : 'btnFinalizeCount';
    const btn = document.getElementById(btnId);
    let ogText = '';
    if (btn) {
        ogText = btn.innerHTML;
        btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin inline"></i> Saving...`;
        btn.disabled = true;
    }
    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: cleanFD });
        const json = await res.json();
        if(json.success) {
            document.getElementById('addCountModal').classList.add('hidden');
            form.reset();
            if(sessId) sessId.value = '';
            activeEditReason = null; // Clear reason after successful save
            Swal.fire({icon: 'success', title: 'Complete', text: json.message, timer: 1500, showConfirmButton: false});
            loadAuditSessions();
            if (type === 'finalize') loadProducts();
        } else Swal.fire('Error', json.message, 'error');
    } catch(err) { Swal.fire('Error', 'Network request failed', 'error'); }
    
    if(btn) {
        btn.innerHTML = ogText;
        btn.disabled = false;
        if(typeof lucide !== 'undefined') lucide.createIcons();
    }
}

window.resumeAudit = async function(session_id) {
    // Open modal safely
    openCountModal();
    const form = document.getElementById('addCountForm');
    
    // Fetch session details
    const session = auditSessions.find(s => s.id == session_id);
    if(session) {
        form.elements['session_name'].value = session.session_name;
        form.elements['audit_date'].value = session.audit_date;
        form.elements['session_id'].value = session_id;
    }

    // Fetch lines from api
    try {
        const res = await fetch(`retail_api.php?action=get_audit_lines&session_id=${session_id}&_t=${Date.now()}`);
        const json = await res.json();
        if(json.success) {
            json.data.forEach(ln => {
                const input = form.querySelector(`input[name="lines[${ln.product_id}][physical_qty]"]`);
                const sysInput = form.querySelector(`input[name="lines[${ln.product_id}][system_qty]"]`);
                const costInput = form.querySelector(`input[name="lines[${ln.product_id}][unit_cost]"]`);
                const priceInput = form.querySelector(`input[name="lines[${ln.product_id}][selling_price]"]`);
                const dispSys = document.getElementById(`disp_sys_${ln.product_id}`);

                if (input) input.value = ln.physical_qty;
                
                // CRITICAL: Restore historical snapshot data for System Qty to prevent variance collapse
                if(sysInput) sysInput.value = ln.system_qty;
                if(dispSys) dispSys.innerText = ln.system_qty;
                
                // We deliberately DO NOT restore unit_cost and selling_price snapshots here.
                // This allows the "Unlock to Edit" function to natively pull in the newest Master Registry prices
                // allowing the user to manually authorize a recalibration of erroneous historical prices by saving.
            });
        }
    } catch(err) {
        console.error("Failed to load draft lines", err);
    }
}

let activeEditReason_placeholder = null; // declaration moved to top

window.unlockAudit = async function() {
    if(!activeSessionId) return;
    const session = auditSessions.find(s => s.id === activeSessionId);
    if(!session || session.status !== 'finalized') return;

    const { value: reason } = await Swal.fire({
        title: 'Unlock Finalized Count',
        text: 'Altering a finalized physical count goes against strictly accepted auditing principles. Please provide a mandatory reason explaining why you require an administrative override.',
        input: 'textarea',
        inputPlaceholder: 'Enter reason for unlocking...',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Unlock & Edit'
    });

    if (reason && reason.trim() !== '') {
        activeEditReason = reason.trim();
        // Switch to the Physical Count tab using Alpine.js data
        const tabBtns = document.querySelectorAll('.tab-btn');
        tabBtns.forEach(btn => {
            if(btn.textContent.trim().includes('Physical Count') || btn.textContent.trim().includes('Stock Count')) {
                btn.click();
            }
        });
        resumeAudit(activeSessionId);
    } else if (reason !== undefined) {
        Swal.fire('Required', 'A valid reason must be provided to unlock.', 'error');
    }
}

// ============================================
// TAB 4: RECONCILIATION ENGINE
// ============================================
function renderReconSessions(data) {
    const list = document.getElementById('reconSessionList');
    if(!list) return;
    list.innerHTML = data.map(s => `
        <button onclick="viewSessionRecon(${s.id})" class="w-full text-left p-3 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition border border-transparent hover:border-slate-200 dark:hover:border-slate-700 flex justify-between items-center ${activeSessionId === s.id ? 'bg-purple-50 dark:bg-purple-900/20 border-purple-200 dark:border-purple-800' : ''}">
            <div>
                <div class="font-bold text-slate-800 dark:text-slate-200 text-sm truncate max-w-[150px]">${s.session_name}</div>
                <div class="text-xs text-slate-500">${s.audit_date}</div>
            </div>
            ${s.status==='open' ? '<div class="w-2 h-2 rounded-full bg-amber-500"></div>' : '<i data-lucide="lock" class="w-3 h-3 text-slate-400"></i>'}
        </button>
    `).join('');
    lucide.createIcons();
}

function filterReconSessions() {
    const q = document.getElementById('reconSessionSearch').value.toLowerCase();
    const filtered = auditSessions.filter(s => s.session_name.toLowerCase().includes(q) || s.audit_date.includes(q));
    renderReconSessions(filtered);
}

async function viewSessionRecon(id) {
    activeSessionId = id;
    const session = auditSessions.find(s => s.id === id);
    if(!session) return;
    
    // Switch to reconciliation tab
    const tabBtns = document.querySelectorAll('.tab-btn');
    tabBtns.forEach(btn => {
        if(btn.textContent.trim().includes('Reconciliation')) {
            btn.click();
        }
    });
    
    document.getElementById('reconEmptyState').classList.add('hidden');
    document.getElementById('reconDataState').classList.remove('hidden');
    
    document.getElementById('r_sessionTitle').innerText = session.session_name;
    document.getElementById('r_sessionMeta').innerText = `Date: ${session.audit_date} | Status: ${session.status.toUpperCase()}`;
    
    renderReconSessions(auditSessions);

    if(session.status === 'open') {
        document.getElementById('r_closeBtn').classList.remove('hidden');
        document.getElementById('r_applyBtn').classList.remove('hidden');
        document.getElementById('r_unlockBtn').classList.add('hidden');
        document.getElementById('r_frozenBtn').classList.add('hidden');
    } else {
        document.getElementById('r_closeBtn').classList.add('hidden');
        document.getElementById('r_applyBtn').classList.add('hidden');
        if (session.status === 'finalized') {
            document.getElementById('r_unlockBtn').classList.remove('hidden');
        } else {
            document.getElementById('r_unlockBtn').classList.add('hidden');
        }
        
        if (session.has_frozen_record == 1) {
            document.getElementById('r_frozenBtn').classList.remove('hidden');
        } else {
            document.getElementById('r_frozenBtn').classList.add('hidden');
        }
    }

    try {
        const res = await fetch(`retail_api.php?action=get_audit_lines&session_id=${id}&_t=${Date.now()}`);
        const json = await res.json();
        if(json.success) {
            activeSessionLines = json.data;
            renderReconMath(activeSessionLines);
        }
    } catch(e) {}
}

function renderReconMath(lines) {
    const tbody = document.getElementById('reconTableBody');
    let totalItems = 0;
    let nbv = 0;
    let shortageTotal = 0;
    let overageTotal = 0;

    tbody.innerHTML = lines.map(L => {
        totalItems++;
        const s = Number(L.system_qty);
        const p = Number(L.physical_qty);
        const cost = Number(L.unit_cost);
        const variance = p - s;
        const vWorth = variance * cost;

        nbv += (p * cost);
        if(variance < 0) shortageTotal += Math.abs(vWorth);
        if(variance > 0) overageTotal += vWorth;

        let varStr = `<span class="text-slate-500">0</span>`;
        if(variance < 0) varStr = `<span class="text-red-500 font-bold">${variance}</span>`;
        if(variance > 0) varStr = `<span class="text-emerald-500 font-bold">+${variance}</span>`;

        const rowNBV = p * cost;
        const vwStr = `<span class="text-blue-600 font-bold font-mono">₦${rowNBV.toLocaleString(undefined,{minimumFractionDigits:2})}</span>`;

        return `
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                <td class="p-3 pl-4 border-b border-slate-100 dark:border-slate-800">
                    <div class="font-bold text-slate-800 dark:text-slate-200">${L.product_name}</div>
                    <div class="text-xs text-slate-400">${L.sku || 'No SKU'}</div>
                </td>
                <td class="p-3 text-right border-b border-slate-100 dark:border-slate-800 text-slate-500">${s}</td>
                <td class="p-3 text-right border-b border-slate-100 dark:border-slate-800 font-bold text-slate-800 dark:text-slate-200">${p}</td>
                <td class="p-3 text-right border-b border-slate-100 dark:border-slate-800">${varStr}</td>
                <td class="p-3 pr-4 text-right border-b border-slate-100 dark:border-slate-800">${vwStr}</td>
            </tr>
        `;
    }).join('');

    document.getElementById('r_totalItems').innerText = totalItems;
    document.getElementById('r_nbv').innerText = `₦${nbv.toLocaleString(undefined,{minimumFractionDigits:2})}`;
    document.getElementById('r_shortageVal').innerText = `₦${shortageTotal.toLocaleString(undefined,{minimumFractionDigits:2})}`;
    document.getElementById('r_overageVal').innerText = `₦${overageTotal.toLocaleString(undefined,{minimumFractionDigits:2})}`;
}

function exportReconciliation() {
    if(activeSessionLines.length === 0) {
        Swal.fire('Empty', 'No data to export.', 'info');
        return;
    }
    
    let csvStr = "Product Name,SKU,System Qty,Physical Qty,Variance,Net Book Value (NGN)\n";
    activeSessionLines.forEach(L => {
        const s = Number(L.system_qty);
        const p = Number(L.physical_qty);
        const cost = Number(L.unit_cost);
        const variance = p - s;
        const rowNBV = p * cost;
        csvStr += `"${L.product_name}","${L.sku}",${s},${p},${variance},${rowNBV}\n`;
    });

    const blob = new Blob([csvStr], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", `Reconciliation_Export_${activeSessionId}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

window.downloadFrozenRecord = function() {
    if(!activeSessionId) return;
    const url = `retail_api.php?action=download_frozen_html&session_id=${activeSessionId}`;
    window.open(url, '_blank', 'noopener,noreferrer');
}

window.exportReconPDF = function() {
    if(activeSessionLines.length === 0) {
        Swal.fire('Empty', 'No data to export.', 'info');
        return;
    }

    const element = document.getElementById('reconDataState');
    const headerBtns = element.querySelector('.flex.gap-2');

    // Temporarily hide interactive buttons from the print layout
    if(headerBtns) headerBtns.style.display = 'none';

    // Inject Miauditops branding header
    const tempHeader = document.createElement('div');
    tempHeader.id = 'temp_recon_pdf_hdr';
    tempHeader.innerHTML = `<div style="text-align:right; margin-bottom:15px; font-family:sans-serif;">
        <span style="font-size:18px; font-weight:900; color:#6366f1; letter-spacing:1px; display:block;">MIAUDITOPS</span>
        <span style="font-size:11px; color:#94a3b8; font-weight:600;">by Miemploya</span>
    </div>`;
    element.insertBefore(tempHeader, element.firstChild);

    // Some tailwind classes (like hidden overflow or fixed heights) might clip the PDF
    const oldHeight = element.style.height;
    element.style.height = 'auto';

    const opt = {
        margin:       0.5,
        filename:     `Retail_Audit_Reconciliation_${activeSessionId}.pdf`,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true },
        jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
    };

    Swal.fire({title: 'Rendering PDF...', text: 'Collating styles and executing...', showConfirmButton: false, allowOutsideClick: false, didOpen: () => Swal.showLoading()});

    html2pdf().set(opt).from(element).save().then(() => {
        // Restore layout
        if(document.getElementById('temp_recon_pdf_hdr')) document.getElementById('temp_recon_pdf_hdr').remove();
        if(headerBtns) headerBtns.style.display = 'flex';
        element.style.height = oldHeight;
        Swal.close();
    }).catch(err => {
        if(headerBtns) headerBtns.style.display = 'flex';
        element.style.height = oldHeight;
        Swal.fire('Error', 'Failed to generate PDF.', 'error');
    });
}

async function applyAuditToSystem() {
    executeAuditLockCommand(1, "Are you sure? This will update all master product Stock QTY to match the physical equivalents counted in this session.");
}

async function closeAuditSession() {
    executeAuditLockCommand(0, "Lock session without migrating system stock? Use this only for blind review runs.");
}

function executeAuditLockCommand(syncFlag, messageStr) {
    Swal.fire({
        title: 'Finalize Audit?', text: messageStr, icon: 'warning',
        showCancelButton: true, confirmButtonText: 'Yes, Finalize it!', confirmButtonColor: '#6366f1'
    }).then(async (res) => {
        if(res.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'lock_audit_session');
            fd.append('session_id', activeSessionId);
            fd.append('sync_system', syncFlag);
            try {
                const r = await fetch('retail_api.php', { method: 'POST', body: fd });
                const json = await r.json();
                if(json.success) {
                    Swal.fire('Locked', json.message, 'success');
                    loadAuditSessions();
                    loadProducts();
                    viewSessionRecon(activeSessionId);
                } else Swal.fire('Error', json.message, 'error');
            } catch(e) {}
        }
    });
}

// ---------------------------
// TAB 3: Batch Upload Counts
// ---------------------------
function handleCountUpload(input) {
    const file = input.files[0];
    if(!file) return;

    const title = document.getElementById('uploadCountTitle').value.trim();
    if(!title) {
        Swal.fire('Required', 'Please enter a Session Title.', 'warning');
        input.value = ''; return;
    }

    const status = document.getElementById('importCountStatus');
    status.classList.remove('hidden');
    status.className = 'mt-4 text-sm font-mono text-blue-600';
    status.innerText = `Parsing ${file.name}...`;

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const jsonSheet = XLSX.utils.sheet_to_json(firstSheet, { header: 1 });
            
            if(jsonSheet.length < 2) {
                status.className = 'mt-4 text-sm font-mono text-red-500';
                status.innerText = "Error: File empty."; return;
            }

            const headerMapper = {'name': null, 'item name': null, 'count': null, 'physical count': null, 'qty': null};
            const rawHeaders = jsonSheet[0];
            rawHeaders.forEach((col, index) => {
                const c = String(col).toLowerCase().trim();
                for(let key in headerMapper) { if(c.includes(key) && headerMapper[key] === null) headerMapper[key] = index; }
            });

            // Map data
            let linesData = [];
            for(let i = 1; i < jsonSheet.length; i++) {
                const row = jsonSheet[i];
                if(row.length === 0 || !row.join('').trim()) continue;
                
                const nameIdx = headerMapper['item name'] ?? headerMapper['name'];
                const countIdx = headerMapper['physical count'] ?? headerMapper['count'] ?? headerMapper['qty'];
                
                if(nameIdx === null || countIdx === null || !row[nameIdx]) continue;
                
                let itemName = String(row[nameIdx]).toLowerCase().trim();
                let phyCount = row[countIdx];
                
                // Find product match
                let prod = retailProducts.find(p => p.name.toLowerCase().trim() === itemName);
                if(prod && phyCount !== '' && phyCount !== null) {
                    linesData.push({
                        product_id: prod.id,
                        system_qty: prod.current_system_stock,
                        physical_qty: phyCount,
                        unit_cost: prod.unit_cost,
                        selling_price: prod.selling_price
                    });
                }
            }

            if(linesData.length === 0) {
                status.className = 'mt-4 text-sm font-mono text-red-500';
                status.innerText = "Error: Could not match any items in your file to the Registry."; return;
            }

            status.className = 'mt-4 text-sm font-mono text-cyan-600';
            status.innerText = `Mapped ${linesData.length} records. Initiating Session...`;

            uploadMappedCounts(title, linesData, status, input);
        } catch(err) {
            status.className = 'mt-4 text-sm font-mono text-red-500';
            status.innerText = "Failed to parse file.";
        }
    };
    reader.readAsArrayBuffer(file);
}

async function uploadMappedCounts(title, linesData, statusEl, fileInput) {
    const cleanFD = new FormData();
    cleanFD.append('action', 'save_audit_session');
    cleanFD.append('session_name', title);
    cleanFD.append('audit_date', new Date().toISOString().split('T')[0]);
    cleanFD.append('lines', JSON.stringify(linesData));

    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: cleanFD });
        const json = await res.json();
        if(json.success) {
            statusEl.className = 'mt-4 text-sm font-mono text-emerald-600';
            statusEl.innerText = json.message;
            setTimeout(() => {
                document.getElementById('importCountModal').classList.add('hidden');
                fileInput.value = '';
                statusEl.classList.add('hidden');
                loadAuditSessions();
            }, 1000);
            Swal.fire({icon: 'success', title: 'Batch Count Initialized', text: json.message, timer: 2000});
        } else {
            statusEl.className = 'mt-4 text-sm font-mono text-red-500';
            statusEl.innerText = json.message;
        }
    } catch(err) {
        statusEl.className = 'mt-4 text-sm font-mono text-red-500';
        statusEl.innerText = "Network Error.";
    }
}

// ============================================
// TAB: SUPPLIERS
// ============================================
window.globalSupplierList = []; // Flat list for dropdowns
let supplierGroupsOpen = {};

async function loadSuppliers() {
    try {
        // Fetch raw list for dropdowns
        const rawRes = await fetch(`retail_api.php?action=get_suppliers`);
        const rawJson = await rawRes.json();
        if(rawJson.success) {
            window.globalSupplierList = rawJson.data;
            
            // Populate Dropdowns
            const opts = '<option value="">-- No Supplier Map --</option>' + window.globalSupplierList.map(s => `<option value="${s.id}">${s.name}</option>`).join('');
            const formSel = document.getElementById('purchaseSupplierSelect');
            if(formSel) formSel.innerHTML = opts;
            
            const impSel = document.getElementById('importSupplierSelect');
            if(impSel) impSel.innerHTML = '<option value="">-- No Supplier assigned --</option>' + window.globalSupplierList.map(s => `<option value="${s.id}">${s.name}</option>`).join('');
        }

        // Fetch chronological history
        const res = await fetch(`retail_api.php?action=get_supplier_history`);
        const json = await res.json();
        if(json.success) {
            renderSuppliers(json.data);
        }
    } catch (e) {
        console.error(e);
    }
}

window.toggleSupplierGroup = function(month) {
    supplierGroupsOpen[month] = !supplierGroupsOpen[month];
    loadSuppliers(); // Soft reload to invoke renderSuppliers
};

function renderSuppliers(data) {
    const container = document.getElementById('suppliersContainer');
    if(!container) return;

    if(data.length === 0) {
        container.innerHTML = `
        <div class="glass-card rounded-2xl p-10 text-center border-dashed border-2 border-slate-300 dark:border-slate-700 flex flex-col items-center justify-center">
            <div class="w-16 h-16 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center mb-4">
                <i data-lucide="users" class="w-8 h-8 text-slate-400"></i>
            </div>
            <p class="text-slate-500 dark:text-slate-400 text-sm max-w-sm">No sources registered. Add external suppliers or internal departments here to track inventory inflows.</p>
        </div>`;
        return;
    }

    const groups = {};
    data.forEach(s => {
        let monthKey = s.month_label || 'No Delivery History';
        if(!groups[monthKey]) groups[monthKey] = [];
        groups[monthKey].push(s);
    });

    let html = '';
    for(const [month, items] of Object.entries(groups)) {
        // Default open so they can see it (or set to false if default closed layout wanted)
        if(supplierGroupsOpen[month] === undefined) supplierGroupsOpen[month] = true; 
        const isOpen = supplierGroupsOpen[month];

        html += `
        <div class="mb-6">
            <div class="flex items-center gap-3 cursor-pointer bg-slate-200/50 dark:bg-slate-800/50 p-3 rounded-xl hover:bg-slate-200 dark:hover:bg-slate-800 transition" onclick="toggleSupplierGroup('${month}')">
                <i data-lucide="${isOpen ? 'chevron-down' : 'chevron-right'}" class="w-5 h-5 text-slate-500 flex-shrink-0"></i>
                <h3 class="font-bold text-slate-800 dark:text-white flex-1">${month}</h3>
                <span class="text-xs font-semibold text-slate-500 bg-white dark:bg-slate-900 px-3 py-1 rounded-full shadow-sm">${items.length} records</span>
            </div>
        `;

        if (isOpen) {
            html += `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 mt-4">`;
            items.forEach(sup => {
                html += `
                <div class="glass-card rounded-2xl p-6 border border-slate-200 dark:border-slate-800 relative group transition-all hover:border-rose-300 dark:hover:border-rose-900/50 hover:shadow-lg">
                    <button onclick="deleteSupplier(${sup.id})" class="absolute top-4 right-4 p-2 bg-red-50 text-red-600 hover:bg-red-500 hover:text-white dark:bg-red-900/20 dark:hover:bg-red-600 rounded-lg opacity-0 group-hover:opacity-100 transition-all z-10" title="Delete Supplier">
                        <i data-lucide="trash-2" class="w-4 h-4 inline"></i>
                    </button>

                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-12 h-12 rounded-full bg-rose-100 dark:bg-rose-900/30 flex items-center justify-center text-rose-600">
                            <i data-lucide="building-2" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 dark:text-white text-lg">${sup.name}</h3>
                            <p class="text-[11px] text-slate-500 font-semibold">${sup.phone || 'No Phone'}</p>
                            <p class="text-[11px] text-slate-500">${sup.email || 'No Email'}</p>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-between items-center text-sm">
                        <div class="flex-1">
                            <p class="text-[10px] uppercase font-bold text-slate-400">Items Supplied</p>
                            <p class="font-bold text-slate-800 dark:text-slate-200">${parseFloat(sup.units_supplied).toLocaleString()}</p>
                        </div>
                        <div class="text-right flex-1">
                            <p class="text-[10px] uppercase font-bold text-slate-400">Total Value (₦)</p>
                            <p class="font-black text-rose-600">${parseFloat(sup.total_value_supplied).toLocaleString()}</p>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 border-dashed">
                        <button onclick="viewSupplierItems(${sup.id}, '${month}', '${sup.name.replace(/'/g, "\\'")}')" class="w-full py-2 bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-bold rounded-lg transition flex items-center justify-center gap-1">
                            <i data-lucide="list" class="w-3.5 h-3.5"></i> View Itemized Deliveries
                        </button>
                    </div>
                </div>
                `;
            });
            html += `</div>`;
        }
        html += `</div>`;
    }
    
    container.innerHTML = html;
    if(typeof lucide !== 'undefined') lucide.createIcons();
}

window.openSupplierModal = function() {
    document.getElementById('supplierModal').classList.remove('hidden');
}

window.closeSupplierModal = function() {
    document.getElementById('supplierModal').classList.add('hidden');
}

window.submitSupplier = async function(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const og = btn.innerHTML;
    btn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin inline"></i> Saving...`;
    btn.disabled = true;

    try {
        const res = await fetch('retail_api.php', { method: 'POST', body: new FormData(e.target) });
        const json = await res.json();
        if(json.success) {
            closeSupplierModal();
            e.target.reset();
            Swal.fire({icon:'success', title:'Saved', text:json.message, timer:1500, showConfirmButton:false});
            loadSuppliers();
        } else {
            Swal.fire('Error', json.message, 'error');
        }
    } catch(err) {
        Swal.fire('Error', 'Network failure.', 'error');
    }
    btn.innerHTML = og;
    btn.disabled = false;
    if(typeof lucide !== 'undefined') lucide.createIcons();
}

window.deleteSupplier = async function(id) {
    if(confirm("Delete this source? Data cannot be recovered.")) {
        const fd = new FormData();
        fd.append('action', 'delete_supplier');
        fd.append('id', id);
        try {
            const res = await fetch('retail_api.php', { method: 'POST', body: fd });
            const json = await res.json();
            if(json.success) {
                loadSuppliers();
            } else {
                Swal.fire('Error', json.message, 'error');
            }
        } catch(e) { console.error(e); }
    }
}

window.viewSupplierItems = function(supplier_id, monthLabel, supplierName) {
    document.getElementById('vspSupplierName').innerText = supplierName;
    document.getElementById('vspMonthLabel').innerText = monthLabel;

    // Filter purchases
    // We already have 'retailPurchases' global loaded from get_purchases
    // 'monthLabel' is e.g. "April 2026" or "No Delivery History"
    const filtered = retailPurchases.filter(p => {
        if (p.supplier_id != supplier_id) return false;
        
        // Check date
        let dateObj = new Date(p.purchase_date);
        let purMonth = isNaN(dateObj) ? 'No Delivery History' : dateObj.toLocaleString('default', { month: 'long', year: 'numeric' });
        
        return purMonth === monthLabel;
    });

    const tbody = document.getElementById('vspTableBody');
    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center text-slate-500">No specific breakdown found in local state.</td></tr>';
        document.getElementById('vspTotalItems').innerText = '0';
    } else {
        let ttl = 0;
        tbody.innerHTML = filtered.map(p => {
            ttl += parseFloat(p.quantity_added);
            return `
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                <td class="p-4 border-b border-slate-200 dark:border-slate-800">${p.purchase_date}</td>
                <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-slate-500">${p.reference || '-'}</td>
                <td class="p-4 border-b border-slate-200 dark:border-slate-800 font-bold">${p.product_name}</td>
                <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right text-emerald-600 font-bold">+${p.quantity_added}</td>
                <td class="p-4 border-b border-slate-200 dark:border-slate-800 text-right font-mono">₦${Number(p.total_cost).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
            </tr>`;
        }).join('');
        document.getElementById('vspTotalItems').innerText = ttl.toLocaleString();
    }
    
    document.getElementById('viewSupplierPurchasesModal').classList.remove('hidden');
}

// ==========================================
// SALES MATH RECONCILIATION
// ==========================================

function filterSalesSessions() {
    const q = document.getElementById('salesSessionSearch').value.toLowerCase();
    // Only show finalized for sales math
    const filtered = auditSessions.filter(s => s.status === 'finalized' && 
        (s.session_name.toLowerCase().includes(q) || s.audit_date.includes(q)));
    renderSalesSessions(filtered);
}

function renderSalesSessions(data) {
    const list = document.getElementById('salesSessionList');
    if(!list) return;
    
    // Only finalized
    const finals = data.filter(s => s.status === 'finalized');
    
    list.innerHTML = finals.map(s => `
        <button onclick="viewSessionSalesRecon(${s.id})" class="w-full text-left p-3 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition border border-transparent hover:border-slate-200 dark:hover:border-slate-700 flex justify-between items-center ${activeSessionId === s.id ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800' : ''}">
            <div>
                <div class="font-bold text-slate-800 dark:text-slate-200 text-sm truncate max-w-[150px]">${s.session_name}</div>
                <div class="text-xs text-slate-500">${s.audit_date}</div>
            </div>
            <i data-lucide="banknote" class="w-4 h-4 text-emerald-500"></i>
        </button>
    `).join('');
    
    if(finals.length===0){
        list.innerHTML = `<div class="p-4 text-center text-slate-500 text-sm">No finalized audits available.</div>`;
    }
    lucide.createIcons();
}

async function viewSessionSalesRecon(id) {
    activeSessionId = id;
    const session = auditSessions.find(s => s.id === id);
    if(!session) return;
    
    document.getElementById('salesEmptyState').classList.add('hidden');
    document.getElementById('salesDataState').classList.remove('hidden');
    
    document.getElementById('sr_sessionTitle').innerText = session.session_name;
    document.getElementById('sr_sessionMeta').innerText = `Date: ${session.audit_date} | Status: ${session.status.toUpperCase()}`;
    
    // Auto-update lists to show active state
    renderSalesSessions(auditSessions);

    // Fetch lines to calculate Base Expected Sales
    try {
        const res = await fetch(`retail_api.php?action=get_audit_lines&session_id=${id}&_t=${Date.now()}`);
        const json = await res.json();
        if(json.success) {
            activeSessionLines = json.data;
            
            let expectedSales = 0;
            activeSessionLines.forEach(l => {
                const sys = parseFloat(l.system_qty);
                const phys = parseFloat(l.physical_qty);
                const sp = parseFloat(l.selling_price);
                if (phys < sys) {
                    expectedSales += (sys - phys) * sp;
                }
            });
            
            // Set base
            window.activeBaseSales = expectedSales;
            document.getElementById('sr_baseExpected').innerText = '₦' + expectedSales.toLocaleString(undefined, {minimumFractionDigits:2});
            
            // Load DB values if exist
            document.getElementById('dec_pos').value = session.declared_pos || 0;
            document.getElementById('dec_transfer').value = session.declared_transfer || 0;
            document.getElementById('dec_cash').value = session.declared_cash || 0;
            
            document.getElementById('adj_add_to_sales').value = session.adj_add_to_sales || 0;
            document.getElementById('adj_damages').value = session.adj_damages || 0;
            document.getElementById('adj_written_off').value = session.adj_written_off || 0;
            document.getElementById('adj_complimentary').value = session.adj_complimentary || 0;
            document.getElementById('adj_error').value = session.adj_error || 0;
            
            recalcSalesMath();
            
        }
    } catch(e) { console.error(e); }
}

window.recalcSalesMath = function() {
    // 1. Declarations
    const pos = parseFloat(document.getElementById('dec_pos').value) || 0;
    const trans = parseFloat(document.getElementById('dec_transfer').value) || 0;
    const cash = parseFloat(document.getElementById('dec_cash').value) || 0;
    
    const declared = pos + trans + cash;
    document.getElementById('sr_totalDeclared').innerText = '₦' + declared.toLocaleString(undefined, {minimumFractionDigits:2});
    
    // 2. Adjustments
    const base = window.activeBaseSales || 0;
    const addSale = parseFloat(document.getElementById('adj_add_to_sales').value) || 0;
    const damages = parseFloat(document.getElementById('adj_damages').value) || 0;
    const wOff = parseFloat(document.getElementById('adj_written_off').value) || 0;
    const comp = parseFloat(document.getElementById('adj_complimentary').value) || 0;
    const err = parseFloat(document.getElementById('adj_error').value) || 0;
    
    const finalExpected = base + addSale - damages - wOff - comp - err;
    
    // 3. Variance Math
    const variance = declared - finalExpected;
    
    const banner = document.getElementById('sr_verdictBanner');
    const amtLabel = document.getElementById('sr_verdictAmount');
    
    amtLabel.innerText = (variance > 0 ? '+' : '') + '₦' + variance.toLocaleString(undefined, {minimumFractionDigits:2});
    
    banner.classList.remove('bg-emerald-50', 'border-emerald-200', 'text-emerald-700');
    banner.classList.remove('bg-red-50', 'border-red-200', 'text-red-700');
    banner.classList.remove('bg-blue-50', 'border-blue-200', 'text-blue-700');
    
    if (Math.abs(variance) < 0.01) {
        // Balanced
        banner.classList.add('bg-blue-50', 'border-blue-200', 'text-blue-700', 'dark:bg-blue-900/20', 'dark:border-blue-800');
    } else if (variance > 0) {
        // Overage cash
        banner.classList.add('bg-emerald-50', 'border-emerald-200', 'text-emerald-700', 'dark:bg-emerald-900/20', 'dark:border-emerald-800');
    } else {
        // Shortage cash
        banner.classList.add('bg-red-50', 'border-red-200', 'text-red-700', 'dark:bg-red-900/20', 'dark:border-red-800');
    }
    
    window.activeFinalExpected = finalExpected; // for saving
};

window.saveSalesDeclarations = async function() {
    if(!activeSessionId) return;
    
    Swal.fire({title: 'Saving...', didOpen: () => Swal.showLoading(), allowOutsideClick: false});
    
    const fd = new FormData();
    fd.append('action', 'save_sales_declarations');
    fd.append('session_id', activeSessionId);
    
    fd.append('declared_pos', document.getElementById('dec_pos').value || 0);
    fd.append('declared_transfer', document.getElementById('dec_transfer').value || 0);
    fd.append('declared_cash', document.getElementById('dec_cash').value || 0);
    
    fd.append('adj_add_to_sales', document.getElementById('adj_add_to_sales').value || 0);
    fd.append('adj_damages', document.getElementById('adj_damages').value || 0);
    fd.append('adj_written_off', document.getElementById('adj_written_off').value || 0);
    fd.append('adj_complimentary', document.getElementById('adj_complimentary').value || 0);
    fd.append('adj_error', document.getElementById('adj_error').value || 0);
    
    fd.append('total_expected_sales', window.activeFinalExpected || window.activeBaseSales || 0);
    
    try {
        const r = await fetch('retail_api.php', { method: 'POST', body: fd });
        const json = await r.json();
        if(json.success) {
            Swal.fire('Saved!', json.message, 'success');
            loadAuditSessions(); // refresh RAM
        } else {
            Swal.fire('Error', json.message, 'error');
        }
    } catch(e) {
        Swal.fire('Error', 'Network request failed.', 'error');
    }
};

window.exportSalesMathPDF = function() {
    const element = document.getElementById('salesDataState');
    const headerBtns = element.querySelector('.shrink-0'); // Top actions bar

    if(headerBtns) headerBtns.style.display = 'none';

    // Inject Miauditops branding header
    const tempHeader = document.createElement('div');
    tempHeader.id = 'temp_sales_pdf_hdr';
    tempHeader.innerHTML = `<div style="text-align:right; margin-bottom:15px; font-family:sans-serif;">
        <span style="font-size:18px; font-weight:900; color:#6366f1; letter-spacing:1px; display:block;">MIAUDITOPS</span>
        <span style="font-size:11px; color:#94a3b8; font-weight:600;">by Miemploya</span>
    </div>`;
    element.insertBefore(tempHeader, element.firstChild);

    // Compute and inject breakdown of sold items (appended as a new page in the PDF)
    let soldItemsHTML = `
        <div id="temp_sales_breakdown" style="padding:40px; margin-top:30px; border-top: 2px dashed #cbd5e1; page-break-before: always; font-family:sans-serif;">
            <h3 style="font-size:18px; font-weight:800; color:#0f172a; margin-bottom:15px; letter-spacing: -0.5px;">Breakdown of Items Discovered as Sold</h3>
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                <thead>
                    <tr style="background:#f1f5f9;">
                        <th style="padding:12px;text-align:left;font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:1px;border-bottom:2px solid #e2e8f0;">Product</th>
                        <th style="padding:12px;text-align:right;font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:1px;border-bottom:2px solid #e2e8f0;">Missing Qty</th>
                        <th style="padding:12px;text-align:right;font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:1px;border-bottom:2px solid #e2e8f0;">Selling Price</th>
                        <th style="padding:12px;text-align:right;font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:1px;border-bottom:2px solid #e2e8f0;">Expected Value</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    let itemsFound = false;
    activeSessionLines.forEach(l => {
        const sys  = parseFloat(l.system_qty)   || 0;
        const phys = parseFloat(l.physical_qty) || 0;
        const price = parseFloat(l.selling_price) || 0;
        const diff = phys - sys;
        if(diff < 0) {
            itemsFound = true;
            const missing = Math.abs(diff);
            const val = missing * price;
            soldItemsHTML += `
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:10px 12px;">
                        <div style="font-size:13px;font-weight:800;color:#0f172a;">${l.product_name}</div>
                        <div style="font-size:10px;color:#94a3b8;margin-top:2px;">${l.sku || 'No SKU'}</div>
                    </td>
                    <td style="padding:10px 12px;text-align:right;font-size:13px;font-weight:800;color:#ef4444;">${missing}</td>
                    <td style="padding:10px 12px;text-align:right;font-size:13px;font-weight:600;color:#64748b;font-family:monospace;">₦${price.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                    <td style="padding:10px 12px;text-align:right;font-size:13px;font-weight:800;color:#10b981;font-family:monospace;">₦${val.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                </tr>
            `;
        }
    });

    if(!itemsFound) {
        soldItemsHTML += `<tr><td colspan="4" style="padding:30px;text-align:center;font-size:13px;color:#94a3b8;font-style:italic;">No shortages detected. Zero items sold.</td></tr>`;
    }

    soldItemsHTML += `</tbody></table></div>`;
    element.insertAdjacentHTML('beforeend', soldItemsHTML);

    // Provide auto-height for canvas drawing
    const oldHeight = element.style.height;
    element.style.height = 'auto';

    const opt = {
        margin:       0.5,
        filename:     `Sales_Math_${activeSessionId}.pdf`,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { 
            scale: 2, 
            useCORS: true,
            onclone: (clonedDoc) => {
                const clonedElement = clonedDoc.getElementById('salesDataState');
                // Remove any buttons cleanly from the cloned PDF snapshot
                clonedElement.querySelectorAll('button').forEach(b => b.remove());
                
                // Convert live inputs to text for the PDF export so they don't look like empty fields
                clonedElement.querySelectorAll('input').forEach(inp => {
                    const span = clonedDoc.createElement('span');
                    span.innerText = inp.value;
                    span.className = 'font-mono font-bold text-slate-800 dark:text-slate-200';
                    inp.parentNode.replaceChild(span, inp);
                });
            }
        },
        jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
    };

    Swal.fire({title: 'Rendering PDF...', text: 'Collating styles and executing...', showConfirmButton: false, allowOutsideClick: false, didOpen: () => Swal.showLoading()});

    html2pdf().set(opt).from(element).save().then(() => {
        // Restore layout
        if(document.getElementById('temp_sales_pdf_hdr')) document.getElementById('temp_sales_pdf_hdr').remove();
        if(document.getElementById('temp_sales_breakdown')) document.getElementById('temp_sales_breakdown').remove();
        if(headerBtns) headerBtns.style.display = '';
        element.style.height = oldHeight;
        Swal.close();
    }).catch(err => {
        if(document.getElementById('temp_sales_pdf_hdr')) document.getElementById('temp_sales_pdf_hdr').remove();
        if(document.getElementById('temp_sales_breakdown')) document.getElementById('temp_sales_breakdown').remove();
        if(headerBtns) headerBtns.style.display = '';
        element.style.height = oldHeight;
        Swal.fire('Error', 'Failed to generate PDF.', 'error');
    });
}

// ============================================
// TAB 5: REPORTS ENGINE (CONSOLIDATED PDF)
// ============================================

window.filterReportSessions = function() {
    const q = document.getElementById('finalReportSearch').value.toLowerCase();
    const filtered = auditSessions.filter(s => s.status === 'finalized' && 
        (s.session_name.toLowerCase().includes(q) || s.audit_date.includes(q)));
    renderReportSessions(filtered);
}

window.renderReportSessions = function(data) {
    const list = document.getElementById('finalReportSessionList');
    if(!list) return;
    const finals = data.filter(s => s.status === 'finalized');
    
    list.innerHTML = finals.map(s => `
        <button onclick="viewFinalReport(${s.id})" class="w-full text-left p-3 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition border border-transparent hover:border-slate-200 dark:hover:border-slate-700 flex justify-between items-center ${activeSessionId === s.id ? 'bg-indigo-50 dark:bg-indigo-900/20 border-indigo-200 dark:border-indigo-800' : ''}">
            <div>
                <div class="font-bold text-slate-800 dark:text-slate-200 text-sm truncate max-w-[150px]">${s.session_name}</div>
                <div class="text-xs text-slate-500">${s.audit_date}</div>
            </div>
            <i data-lucide="file-check-2" class="w-4 h-4 text-indigo-500"></i>
        </button>
    `).join('');
    
    if(finals.length===0){
        list.innerHTML = `<div class="p-4 text-center text-slate-500 text-sm">No finalized audits available.</div>`;
    }
    if(typeof lucide !== 'undefined') lucide.createIcons();
}

window.viewFinalReport = async function(id) {
    activeSessionId = id;
    const session = auditSessions.find(s => s.id === id);
    if(!session) return;
    
    const tabBtns = document.querySelectorAll('.tab-btn');
    tabBtns.forEach(btn => {
        if(btn.textContent.trim().includes('Report')) {
            btn.click();
        }
    });
    
    const rES = document.getElementById('reportEmptyState');
    const rDS = document.getElementById('reportDataState');
    if(rES) rES.classList.add('hidden');
    if(rDS) rDS.classList.remove('hidden');
    
    const titleObj = document.getElementById('fr_sessionTitle');
    const metaObj = document.getElementById('fr_sessionMeta');
    if(titleObj) titleObj.innerText = session.session_name;
    if(metaObj) metaObj.innerText = `Date: ${session.audit_date}`;
    
    renderReportSessions(auditSessions);

    try {
        const res = await fetch(`retail_api.php?action=get_audit_lines&session_id=${id}&_t=${Date.now()}`);
        const json = await res.json();
        if(json.success) {
            activeSessionLines = json.data;
        }
    } catch(e) {}
}

let activePdfChartObj = null;

// ==========================================
// MASTER PDF GENERATION ENGINE (COMPLETE REBUILD)
// Uses the proven live-DOM rendering pattern.
// html2pdf REQUIRES visible, in-viewport elements.
// ==========================================
window.exportFinalConsolidatedPDF = async function() {
    if (!activeSessionId) return;
    const session = auditSessions.find(s => s.id === activeSessionId);
    if (!session) return;
    if (!activeSessionLines || activeSessionLines.length === 0) {
        return Swal.fire('Wait', 'Loading audit data, please try again in a moment.', 'info');
    }

    // Show loading
    document.getElementById('fr_loadingState').classList.remove('hidden');
    Swal.fire({title: 'Assembling Report...', text: 'Compiling professional audit consolidation...', showConfirmButton: false, allowOutsideClick: false, didOpen: () => Swal.showLoading()});

    // ========== DATA COMPUTATION ==========
    let baseExpected = 0;
    let totalGrossMargin = 0;
    let totalNBV = 0;
    let totalShortageVal = 0;
    let totalOverageVal = 0;
    let missingItemCount = 0;
    let chartLabels = [];
    let chartData = [];
    let chartOtherTotal = 0;

    let soldMathRows = '';
    let reconRows = '';
    let profitRows = '';

    activeSessionLines.forEach(l => {
        const sys  = parseFloat(l.system_qty)   || 0;
        const phys = parseFloat(l.physical_qty) || 0;
        const price = parseFloat(l.selling_price) || 0;
        const cost = parseFloat(l.unit_cost) || 0;
        const diff = phys - sys;

        // Recon row
        let varColor = '#64748b';
        let varText = '' + diff;
        if (diff < 0) { varColor = '#ef4444'; varText = '' + diff; }
        if (diff > 0) { varColor = '#10b981'; varText = '+' + diff; }

        const nbv = phys * cost;
        totalNBV += nbv;
        if (diff < 0) totalShortageVal += Math.abs(diff) * cost;
        if (diff > 0) totalOverageVal += diff * cost;

        reconRows += `<tr>
            <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e2e8f0;"><b>${l.product_name}</b></td>
            <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;color:#64748b;">${sys}</td>
            <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;font-weight:bold;">${phys}</td>
            <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;color:${varColor};font-weight:bold;">${varText}</td>
            <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;">₦${cost.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
            <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;font-weight:bold;color:#475569;">₦${nbv.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
        </tr>`;

        // Sales math + profit (only missing/sold items)
        if (diff < 0) {
            const missing = Math.abs(diff);
            const val = missing * price;
            baseExpected += val;
            missingItemCount++;

            if (chartLabels.length < 10) {
                chartLabels.push(l.product_name.substring(0, 18));
                chartData.push(val);
            } else {
                chartOtherTotal += val;
            }

            soldMathRows += `<tr>
                <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e2e8f0;"><b>${l.product_name}</b></td>
                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;font-weight:bold;color:#ef4444;">${missing}</td>
                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;color:#64748b;">₦${price.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;font-weight:bold;color:#10b981;">₦${val.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
            </tr>`;

            const unitMargin = price - cost;
            const grossMargin = unitMargin * missing;
            totalGrossMargin += grossMargin;

            profitRows += `<tr>
                <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e2e8f0;"><b>${l.product_name}</b></td>
                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;font-weight:bold;color:#ef4444;">${missing}</td>
                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;color:#64748b;">₦${cost.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;color:#64748b;">₦${price.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;">₦${unitMargin.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;font-weight:bold;color:#6366f1;">₦${grossMargin.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
            </tr>`;
        }
    });

    if (chartOtherTotal > 0) {
        chartLabels.push('Other');
        chartData.push(chartOtherTotal);
    }

    if (missingItemCount === 0) {
        soldMathRows = `<tr><td colspan="4" style="text-align:center;padding:20px;font-style:italic;color:#94a3b8;">No shortages recorded.</td></tr>`;
        profitRows = `<tr><td colspan="6" style="text-align:center;padding:20px;font-style:italic;color:#94a3b8;">No shortages recorded.</td></tr>`;
    }

    // Summary calculations
    const adjAdd = parseFloat(session.adj_add_to_sales) || 0;
    const adjLess = (parseFloat(session.adj_damages)||0) + (parseFloat(session.adj_written_off)||0) + (parseFloat(session.adj_complimentary)||0) + (parseFloat(session.adj_error)||0);
    const targetNetSales = baseExpected + adjAdd - adjLess;
    const decPos = parseFloat(session.declared_pos) || 0;
    const decTrans = parseFloat(session.declared_transfer) || 0;
    const decCash = parseFloat(session.declared_cash) || 0;
    const totalDec = decPos + decTrans + decCash;
    const varMath = totalDec - targetNetSales;
    let varColor = '#64748b'; let varSign = ''; let varLabel = 'BALANCED';
    if (varMath < -0.01) { varColor = '#ef4444'; varLabel = 'SHORTAGE'; }
    if (varMath > 0.01) { varColor = '#10b981'; varSign = '+'; varLabel = 'OVERAGE'; }

    // ========== COMMON STYLES ==========
    const S = {
        font: "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;",
        th: "padding:6px 8px; text-align:left; font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase; border-bottom:2px solid #cbd5e1; background:#f8fafc;",
        thR: "padding:6px 8px; text-align:right; font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase; border-bottom:2px solid #cbd5e1; background:#f8fafc;",
    };

    const fmtN = (n) => '₦' + n.toLocaleString(undefined, {minimumFractionDigits:2});

    // ========== PRE-RENDER CHART (before building HTML) ==========
    let chartImgTag = '<div style="color:#94a3b8; font-style:italic; padding:20px 40px;">No shortages detected.</div>';
    if (missingItemCount > 0 && chartLabels.length > 0) {
        // Create temporary off-screen canvas
        const tmpCanvas = document.createElement('canvas');
        tmpCanvas.width = 420;
        tmpCanvas.height = 220;
        // Must briefly attach to DOM for Chart.js to measure it
        tmpCanvas.style.cssText = 'position:fixed; top:-9999px; left:-9999px;';
        document.body.appendChild(tmpCanvas);

        if (activePdfChartObj) { activePdfChartObj.destroy(); activePdfChartObj = null; }

        const chartColors = ['#f43f5e','#3b82f6','#10b981','#f59e0b','#8b5cf6','#ec4899','#14b8a6','#f97316','#6366f1','#84cc16','#94a3b8'];

        activePdfChartObj = new Chart(tmpCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: chartLabels,
                datasets: [{
                    data: chartData,
                    backgroundColor: chartColors.slice(0, chartData.length),
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: false,
                animation: false,
                cutout: '55%',
                plugins: {
                    legend: {
                        display: false  // We'll build a custom legend in HTML for crisp text
                    }
                }
            }
        });

        // Synchronous — Chart.js with animation:false renders immediately
        const chartBase64 = tmpCanvas.toDataURL('image/png');

        // Build a professional custom legend table
        let legendRows = '';
        chartLabels.forEach((label, i) => {
            const pct = chartData[i] / chartData.reduce((a,b) => a+b, 0) * 100;
            legendRows += `<tr>
                <td style="padding:2px 6px;"><div style="width:10px; height:10px; border-radius:2px; background:${chartColors[i % chartColors.length]};"></div></td>
                <td style="padding:2px 6px; font-size:9px; color:#334155; white-space:nowrap;">${label}</td>
                <td style="padding:2px 6px; font-size:9px; color:#64748b; text-align:right; font-family:monospace;">${pct.toFixed(1)}%</td>
            </tr>`;
        });

        chartImgTag = `
            <table cellpadding="0" cellspacing="0" style="margin:0 auto;">
                <tr>
                    <td style="vertical-align:middle; padding-right:15px;">
                        <img src="${chartBase64}" width="180" height="180" style="display:block;" />
                    </td>
                    <td style="vertical-align:middle;">
                        <table cellpadding="0" cellspacing="0">${legendRows}</table>
                    </td>
                </tr>
            </table>`;

        // Destroy temp chart and remove canvas from DOM
        activePdfChartObj.destroy();
        activePdfChartObj = null;
        document.body.removeChild(tmpCanvas);
    }


    const coverHTML = `
    <div style="padding:40px; text-align:center; ${S.font} color:#1e293b; page-break-after:always;">
        <div style="padding-top:140px; padding-bottom:100px;">
            <div style="font-size:13px; font-weight:bold; color:#94a3b8; text-transform:uppercase; letter-spacing:5px; margin-bottom:14px;">Consolidated</div>
            <div style="font-size:38px; font-weight:900; color:#0f172a; margin-bottom:20px; line-height:1.1; letter-spacing:-1px;">Retail Audit Report</div>
            <div style="height:4px; width:60px; background:linear-gradient(90deg,#3b82f6,#8b5cf6); margin:0 auto 30px; border-radius:2px;"></div>
            <div style="font-size:20px; font-weight:800; color:#1d4ed8; padding:12px 24px; background:rgba(59,130,246,0.08); border-radius:10px; border:1px solid rgba(59,130,246,0.15); display:inline-block;">${session.session_name}</div>
            <div style="font-size:14px; color:#64748b; margin-top:14px; font-weight:600;">Audit Date: ${session.audit_date}</div>
        </div>
        <div style="border-top:1px solid #e2e8f0; padding-top:30px;">
            <table width="100%" cellpadding="0" cellspacing="0"><tr>
                <td style="text-align:left; vertical-align:bottom;">
                    <div style="font-size:9px; color:#94a3b8; text-transform:uppercase; font-weight:800; margin-bottom:4px;">Prepared For</div>
                    <div style="font-size:16px; font-weight:900; color:#0f172a;">${pdfClientName}</div>
                    <div style="font-size:12px; color:#64748b;">${pdfOutletName}</div>
                </td>
                <td style="text-align:right; vertical-align:bottom;">
                    <div style="font-size:20px; font-weight:900; color:#6366f1; letter-spacing:1.5px; line-height:1;">MIAUDITOPS</div>
                    <div style="font-size:10px; color:#94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:1px;">by Miemploya</div>
                </td>
            </tr></table>
        </div>
    </div>`;

    // ========== SECTION 2: EXECUTIVE SUMMARY ==========
    const summaryHTML = `
    <div style="padding:30px 35px; ${S.font} color:#1e293b; page-break-after:always;">
        <div style="border-bottom:2px solid #e2e8f0; padding-bottom:10px; margin-bottom:20px;">
            <table width="100%" cellpadding="0" cellspacing="0"><tr>
                <td><h2 style="font-size:18px; font-weight:900; color:#0f172a; margin:0;">Executive Sales Summary</h2>
                <div style="font-size:10px; color:#94a3b8; margin-top:3px;">${session.session_name} &mdash; ${session.audit_date}</div></td>
                <td style="text-align:right; font-size:8px; color:#94a3b8;">Page 1</td>
            </tr></table>
        </div>

        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px; font-size:11px; line-height:1.5;">
            <tr>
                <td width="33%" valign="top" style="padding-right:12px;">
                    <div style="font-size:9px; text-transform:uppercase; color:#94a3b8; margin-bottom:6px; font-weight:800; border-bottom:1px solid #f1f5f9; padding-bottom:3px;">Expected Revenue</div>
                    <table width="100%" cellpadding="0" cellspacing="0" style="font-size:11px;">
                        <tr><td>Base Expected:</td><td style="text-align:right;"><b style="font-family:monospace;">${fmtN(baseExpected)}</b></td></tr>
                        <tr><td style="color:#10b981;">Additions (+):</td><td style="text-align:right;"><b style="font-family:monospace;color:#10b981;">${fmtN(adjAdd)}</b></td></tr>
                        <tr><td style="color:#ef4444;">Liabilities (-):</td><td style="text-align:right;"><b style="font-family:monospace;color:#ef4444;">${fmtN(adjLess)}</b></td></tr>
                    </table>
                    <div style="background:#f0f9ff; padding:6px 8px; border-radius:4px; margin-top:8px; border:1px solid #bae6fd;">
                        <table width="100%" cellpadding="0" cellspacing="0"><tr>
                            <td style="font-weight:800; font-size:9px; text-transform:uppercase;">Net Target</td>
                            <td style="text-align:right; color:#0369a1; font-size:13px; font-family:monospace; font-weight:bold;">${fmtN(targetNetSales)}</td>
                        </tr></table>
                    </div>
                </td>
                <td width="34%" valign="top" style="padding:0 12px; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0;">
                    <div style="font-size:9px; text-transform:uppercase; color:#94a3b8; margin-bottom:6px; font-weight:800; border-bottom:1px solid #f1f5f9; padding-bottom:3px;">Declarations</div>
                    <table width="100%" cellpadding="0" cellspacing="0" style="font-size:11px;">
                        <tr><td>POS:</td><td style="text-align:right;"><b style="font-family:monospace;">${fmtN(decPos)}</b></td></tr>
                        <tr><td>Transfer:</td><td style="text-align:right;"><b style="font-family:monospace;">${fmtN(decTrans)}</b></td></tr>
                        <tr><td>Cash:</td><td style="text-align:right;"><b style="font-family:monospace;">${fmtN(decCash)}</b></td></tr>
                    </table>
                    <div style="background:#f0fdf4; padding:6px 8px; border-radius:4px; margin-top:8px; border:1px solid #bbf7d0;">
                        <table width="100%" cellpadding="0" cellspacing="0"><tr>
                            <td style="font-weight:800; font-size:9px; text-transform:uppercase;">Total In</td>
                            <td style="text-align:right; color:#15803d; font-size:13px; font-family:monospace; font-weight:bold;">${fmtN(totalDec)}</td>
                        </tr></table>
                    </div>
                </td>
                <td width="33%" valign="top" style="padding-left:12px; text-align:center;">
                    <div style="font-size:9px; text-transform:uppercase; color:#94a3b8; margin-bottom:6px; font-weight:800; border-bottom:1px solid #f1f5f9; padding-bottom:3px;">Final Variance</div>
                    <div style="font-size:9px; text-transform:uppercase; color:${varColor}; font-weight:800; margin-top:8px;">${varLabel}</div>
                    <div style="color:${varColor}; font-size:26px; font-weight:900; margin-top:4px; font-family:monospace; line-height:1;">${varSign}${fmtN(Math.abs(varMath))}</div>
                </td>
            </tr>
        </table>

        <div style="margin-top:20px; padding-top:10px; border-top:1px solid #e2e8f0; font-size:8px; color:#94a3b8; text-transform:uppercase; text-align:right;">
            MIAUDITOPS by Miemploya &bull; Page 1
        </div>
    </div>`;

    // ========== SECTION 3: SALES MATH TABLE ==========
    const salesHTML = `
    <div style="padding:30px 35px; ${S.font} color:#1e293b; page-break-after:always;">
        <div style="border-bottom:2px solid #e2e8f0; padding-bottom:10px; margin-bottom:15px;">
            <table width="100%" cellpadding="0" cellspacing="0"><tr>
                <td><h2 style="font-size:18px; font-weight:900; color:#0f172a; margin:0;">Detailed Sales Math</h2>
                <div style="font-size:10px; color:#94a3b8; margin-top:3px;">Missing stock converted to target retail revenue at selling price.</div></td>
                <td style="text-align:right; font-size:8px; color:#94a3b8;">Page 2</td>
            </tr></table>
        </div>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:15px;">
            <thead>
                <tr>
                    <th style="${S.th}">Product</th>
                    <th style="${S.thR}">Missing Qty</th>
                    <th style="${S.thR}">Selling Price</th>
                    <th style="${S.thR}">Target Revenue</th>
                </tr>
            </thead>
            <tbody>${soldMathRows}</tbody>
        </table>
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#eef2ff; border:1px solid #c7d2fe; border-radius:6px;">
            <tr>
                <td style="padding:10px 14px; font-size:10px; font-weight:800; text-transform:uppercase; color:#4f46e5;">Base Expected Sales Total</td>
                <td style="padding:10px 14px; text-align:right; font-size:16px; font-family:monospace; font-weight:bold; color:#312e81;">${fmtN(baseExpected)}</td>
            </tr>
        </table>
        <div style="margin-top:20px; padding-top:10px; border-top:1px solid #e2e8f0; font-size:8px; color:#94a3b8; text-transform:uppercase; text-align:right;">
            MIAUDITOPS by Miemploya &bull; Page 2
        </div>
    </div>`;

    // ========== SECTION 4: RECONCILIATION TABLE ==========
    const reconHTML = `
    <div style="padding:30px 35px; ${S.font} color:#1e293b; page-break-after:always;">
        <div style="border-bottom:2px solid #e2e8f0; padding-bottom:10px; margin-bottom:15px;">
            <table width="100%" cellpadding="0" cellspacing="0"><tr>
                <td><h2 style="font-size:18px; font-weight:900; color:#0f172a; margin:0;">Complete Stock Verification</h2>
                <div style="font-size:10px; color:#94a3b8; margin-top:3px;">System targets vs actual physical counts &amp; Net Book Value at cost.</div></td>
                <td style="text-align:right; font-size:8px; color:#94a3b8;">Page 3</td>
            </tr></table>
        </div>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:15px;">
            <thead>
                <tr>
                    <th style="${S.th}">Product</th>
                    <th style="${S.thR}">System</th>
                    <th style="${S.thR}">Physical</th>
                    <th style="${S.thR}">Variance</th>
                    <th style="${S.thR}">Cost</th>
                    <th style="${S.thR}">Net Book Val</th>
                </tr>
            </thead>
            <tbody>${reconRows}</tbody>
        </table>
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;">
            <tr>
                <td width="33%" style="padding:8px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; text-align:center;">
                    <div style="font-size:8px; font-weight:800; text-transform:uppercase; color:#15803d;">Total NBV</div>
                    <div style="font-size:14px; font-weight:900; color:#15803d; font-family:monospace;">${fmtN(totalNBV)}</div>
                </td>
                <td width="33%" style="padding:8px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; text-align:center;">
                    <div style="font-size:8px; font-weight:800; text-transform:uppercase; color:#dc2626;">Shortage Value</div>
                    <div style="font-size:14px; font-weight:900; color:#dc2626; font-family:monospace;">${fmtN(totalShortageVal)}</div>
                </td>
                <td width="33%" style="padding:8px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; text-align:center;">
                    <div style="font-size:8px; font-weight:800; text-transform:uppercase; color:#15803d;">Overage Value</div>
                    <div style="font-size:14px; font-weight:900; color:#15803d; font-family:monospace;">${fmtN(totalOverageVal)}</div>
                </td>
            </tr>
        </table>
        <div style="margin-top:20px; padding-top:10px; border-top:1px solid #e2e8f0; font-size:8px; color:#94a3b8; text-transform:uppercase; text-align:right;">
            MIAUDITOPS by Miemploya &bull; Page 3
        </div>
    </div>`;

    // ========== SECTION 5: GROSS PROFIT MARGIN ==========
    const profitHTML = `
    <div style="padding:30px 35px; ${S.font} color:#1e293b; page-break-after:always;">
        <div style="border-bottom:2px solid #e2e8f0; padding-bottom:10px; margin-bottom:15px;">
            <table width="100%" cellpadding="0" cellspacing="0"><tr>
                <td><h2 style="font-size:18px; font-weight:900; color:#0f172a; margin:0;">Gross Profit Margin Declaration</h2>
                <div style="font-size:10px; color:#94a3b8; margin-top:3px;">Unit margins calculated exclusively against items verified as sold.</div></td>
                <td style="text-align:right; font-size:8px; color:#94a3b8;">Page 4</td>
            </tr></table>
        </div>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:15px;">
            <thead>
                <tr>
                    <th style="${S.th}">Product</th>
                    <th style="${S.thR}">Sold Qty</th>
                    <th style="${S.thR}">Cost</th>
                    <th style="${S.thR}">Price</th>
                    <th style="${S.thR}">Unit Margin</th>
                    <th style="${S.thR} color:#6366f1;">Gross Profit</th>
                </tr>
            </thead>
            <tbody>${profitRows}</tbody>
        </table>
        <div style="background:#e0e7ff; border:2px solid #c7d2fe; padding:14px 16px; border-radius:8px; text-align:right;">
            <div style="font-size:11px; color:#4f46e5; font-weight:800; text-transform:uppercase; letter-spacing:1px; margin-bottom:3px;">Total Expected Gross Profit</div>
            <div style="font-size:26px; font-weight:900; color:#312e81; font-family:monospace;">${fmtN(totalGrossMargin)}</div>
        </div>
        <div style="margin-top:20px; padding-top:10px; border-top:1px solid #e2e8f0; font-size:8px; color:#94a3b8; text-transform:uppercase; text-align:right;">
            MIAUDITOPS by Miemploya &bull; Page 4
        </div>
    </div>`;

    // ========== SECTION 6: REVENUE SHARE BREAKDOWN (PIE CHART) ==========
    const chartHTML = `
    <div style="padding:30px 35px; ${S.font} color:#1e293b;">
        <div style="border-bottom:2px solid #e2e8f0; padding-bottom:10px; margin-bottom:25px;">
            <table width="100%" cellpadding="0" cellspacing="0"><tr>
                <td><h2 style="font-size:18px; font-weight:900; color:#0f172a; margin:0;">Revenue Share Breakdown</h2>
                <div style="font-size:10px; color:#94a3b8; margin-top:3px;">Visual distribution of expected sales revenue by product category.</div></td>
                <td style="text-align:right; font-size:8px; color:#94a3b8;">Page 5</td>
            </tr></table>
        </div>

        <div style="text-align:center; margin-bottom:25px;">
            <div style="display:inline-block; padding:15px 25px; border:1px solid #e2e8f0; border-radius:12px; background:#fafbfc;">
                ${chartImgTag}
            </div>
        </div>

        <div style="margin-top:15px;">
            <div style="font-size:10px; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:8px; letter-spacing:0.5px;">Detailed Revenue Contribution</div>
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="${S.th}">Product</th>
                        <th style="${S.thR}">Missing Qty</th>
                        <th style="${S.thR}">Selling Price</th>
                        <th style="${S.thR}">Revenue Value</th>
                        <th style="${S.thR}">Share %</th>
                    </tr>
                </thead>
                <tbody>${(() => {
                    if (missingItemCount === 0) return '<tr><td colspan="5" style="text-align:center;padding:20px;font-style:italic;color:#94a3b8;">No shortages recorded.</td></tr>';
                    let rows = '';
                    activeSessionLines.forEach(l => {
                        const sys = parseFloat(l.system_qty)||0;
                        const phys = parseFloat(l.physical_qty)||0;
                        const diff = phys - sys;
                        if (diff < 0) {
                            const missing = Math.abs(diff);
                            const price = parseFloat(l.selling_price)||0;
                            const val = missing * price;
                            const pct = baseExpected > 0 ? (val / baseExpected * 100) : 0;
                            rows += `<tr>
                                <td style="padding:5px 8px;font-size:10px;border-bottom:1px solid #e2e8f0;"><b>${l.product_name}</b></td>
                                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;color:#ef4444;font-weight:bold;">${missing}</td>
                                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;color:#64748b;">${fmtN(price)}</td>
                                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;font-weight:bold;color:#10b981;">${fmtN(val)}</td>
                                <td style="padding:5px 8px;text-align:right;font-size:10px;border-bottom:1px solid #e2e8f0;font-family:monospace;color:#6366f1;font-weight:bold;">${pct.toFixed(1)}%</td>
                            </tr>`;
                        }
                    });
                    return rows;
                })()}</tbody>
            </table>
        </div>

        <div style="margin-top:20px; padding-top:10px; border-top:1px solid #e2e8f0; font-size:8px; color:#94a3b8; text-transform:uppercase; text-align:right;">
            MIAUDITOPS by Miemploya &bull; Page 5
        </div>
    </div>`;

    // ========== RENDER INTO LIVE DOM ==========
    const renderZone = document.getElementById('pdfRenderZone');
    const reportUIHeader = document.getElementById('reportUIHeader');

    if (reportUIHeader) reportUIHeader.style.display = 'none';
    renderZone.innerHTML = coverHTML + summaryHTML + salesHTML + reconHTML + profitHTML + chartHTML;
    renderZone.style.display = 'block';

    // Allow DOM to fully paint
    await new Promise(r => setTimeout(r, 300));

    // ========== GENERATE PDF ==========
    const opt = {
        margin:       0.3,
        filename:     `MIAUDITOPS_Report_${session.session_name.replace(/[^a-z0-9]/gi, '_')}.pdf`,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, scrollY: 0 },
        jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' },
        pagebreak:    { mode: ['css', 'legacy'] }
    };

    try {
        await html2pdf().set(opt).from(renderZone).save();
        Swal.fire('Success', 'Professional consolidated PDF generated!', 'success');
    } catch(err) {
        console.error('PDF generation error:', err);
        Swal.fire('Error', 'Failed to compile PDF: ' + err.message, 'error');
    }

    // ========== RESTORE UI ==========
    renderZone.style.display = 'none';
    renderZone.innerHTML = '';
    if (reportUIHeader) reportUIHeader.style.display = 'flex';
    document.getElementById('fr_loadingState').classList.add('hidden');
}


