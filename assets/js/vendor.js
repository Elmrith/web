// assets/js/vendor.js - Enhanced Vendor Dashboard & Procurement
let productsData = [];
let vendorOrdersData = [];
let currentCategory = 'all';
let currentSort = 'featured';

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    }[character]));
}

function safeImageUrl(value) {
    const imageUrl = String(value ?? '').trim();
    if (!imageUrl) return '';
    if (/^(https:\/\/|\/|assets\/|uploads\/products\/)/i.test(imageUrl)) {
        return imageUrl;
    }
    return '';
}

function formatPeso(amount) {
    return '₱' + Number(amount || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

document.addEventListener('DOMContentLoaded', () => {
    loadVendorData();

    // Live search & filters
    document.getElementById('vendorSearchInput')?.addEventListener('input', filterProducts);
    document.getElementById('vendorCategoryFilter')?.addEventListener('change', e => {
        currentCategory = e.target.value;
        updateCategoryPills(currentCategory);
        filterProducts();
    });
    document.getElementById('vendorSortSelect')?.addEventListener('change', e => {
        currentSort = e.target.value;
        filterProducts();
    });

    // Hash navigation listener
    window.addEventListener('hashchange', handleHashTab);
    handleHashTab();
});

function handleHashTab() {
    const hash = window.location.hash.replace('#', '');
    if (['marketplace', 'procurement', 'orders'].includes(hash)) {
        switchVendorTab(hash, false);
    }
}

// Load vendor stats, product catalog, and order history
async function loadVendorData() {
    try {
        const totalOrdersEl = document.getElementById('statTotalOrders');
        const pendingOrdersEl = document.getElementById('statPendingOrders');
        const totalSpendEl = document.getElementById('statTotalSpend');
        const activeSuppliersEl = document.getElementById('statActiveSuppliers');

        // 1. Fetch Summary Stats
        const stats = await API.get('api/vendor.php?action=summary');
        if (totalOrdersEl) totalOrdersEl.textContent = String(stats?.totalOrders ?? 0);
        if (pendingOrdersEl) pendingOrdersEl.textContent = String(stats?.pendingOrders ?? 0);
        if (totalSpendEl) totalSpendEl.textContent = formatPeso(stats?.totalSpend);

        // 2. Fetch Products
        productsData = await API.get('api/products.php');
        if (Array.isArray(productsData)) {
            renderVendorProducts(productsData);
            populateCategoryPills(productsData);
            openRequestedProduct();
        }

        // 3. Fetch Orders
        vendorOrdersData = await API.get('api/vendor.php?action=orders');
        if (Array.isArray(vendorOrdersData)) {
            renderVendorOrders(vendorOrdersData);
            
            // Calculate distinct active suppliers
            if (activeSuppliersEl) {
                const uniqueSuppliers = new Set(vendorOrdersData.map(o => o.supplierId)).size;
                activeSuppliersEl.textContent = String(uniqueSuppliers);
            }
        }

        lucide.createIcons();
    } catch (err) {
        console.error('Failed to load vendor data:', err);
    }
}

function openRequestedProduct() {
    const productId = new URLSearchParams(window.location.search).get('product');
    if (!productId) return;

    const product = productsData.find(item => String(item.id) === productId);
    if (product && (product.variants?.some(variant => Number(variant.stockQuantity) > 0) || Number(product.stockQuantity) > 0)) {
        openCheckoutModal(product);
    }
}

// Populate Category Filter Pills with counts
function populateCategoryPills(items) {
    const container = document.getElementById('vendorCategoryPills');
    if (!container) return;

    const counts = { all: items.length };
    items.forEach(p => {
        const cat = p.category || 'General';
        counts[cat] = (counts[cat] || 0) + 1;
    });

    const categories = [
        { id: 'all', label: 'All Supplies' },
        { id: 'Grains', label: 'Grains & Rice' },
        { id: 'Poultry', label: 'Poultry & Meat' },
        { id: 'Produce', label: 'Fresh Produce' },
        { id: 'Baking', label: 'Baking' },
        { id: 'Oils', label: 'Oils & Seasonings' }
    ];

    container.innerHTML = categories.map(c => `
        <button type="button" onclick="selectCategory('${c.id}')"
            class="vendor-pill-btn ${currentCategory === c.id ? 'vendor-pill-btn--active' : ''}">
            <span>${c.label}</span>
            <span class="vendor-pill-count">${counts[c.id] || 0}</span>
        </button>
    `).join('');
}

function selectCategory(cat) {
    currentCategory = cat;
    const filterSelect = document.getElementById('vendorCategoryFilter');
    if (filterSelect) filterSelect.value = cat;
    updateCategoryPills(cat);
    filterProducts();
}

function updateCategoryPills(cat) {
    document.querySelectorAll('.vendor-pill-btn').forEach(btn => {
        const isMatch = btn.getAttribute('onclick')?.includes(`'${cat}'`);
        btn.classList.toggle('vendor-pill-btn--active', Boolean(isMatch));
    });
}

// Render Products Grid
function renderVendorProducts(products) {
    const grid = document.getElementById('vendorProductGrid');
    const countEl = document.getElementById('vendorProductCount');
    if (countEl) {
        countEl.textContent = `${products.length} product${products.length === 1 ? '' : 's'}`;
    }

    if (!products.length) {
        grid.innerHTML = `
            <div class="col-span-full py-16 text-center bg-white rounded-2xl border border-slate-200/80 p-8">
                <div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="package-search" class="w-8 h-8"></i>
                </div>
                <h3 class="font-bold text-slate-800 text-base">No supplies match your search</h3>
                <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">Try selecting a different category or clearing your search term to see available stock.</p>
                <button type="button" onclick="resetFilters()" class="mt-4 px-4 py-2 bg-blue-50 text-blue-600 rounded-xl text-xs font-bold hover:bg-blue-100 transition">
                    Reset Filters
                </button>
            </div>
        `;
        lucide.createIcons();
        return;
    }

    grid.innerHTML = products.map(p => {
        const variants = Array.isArray(p.variants) ? p.variants : [];
        const stock = variants.length ? variants.reduce((total, variant) => total + Number(variant.stockQuantity || 0), 0) : Number(p.stockQuantity);
        const displayPrice = variants.length ? Math.min(...variants.map(variant => Number(variant.price))) : Number(p.price);
        const isOutOfStock = stock <= 0;
        const isLowStock = stock > 0 && stock < 15;

        return `
            <div class="vendor-card group">
                <div class="vendor-card-media">
                    ${safeImageUrl(p.imageUrl) 
                        ? `<img src="${escapeHtml(safeImageUrl(p.imageUrl))}" alt="${escapeHtml(p.name)}" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">` 
                        : `<div class="w-full h-full flex items-center justify-center bg-slate-100 text-slate-300"><i data-lucide="package" class="w-12 h-12"></i></div>`}
                    
                    <span class="vendor-card-category">${escapeHtml(p.category || 'Supplies')}</span>

                    <span class="vendor-card-stock ${
                        isOutOfStock ? 'stock-pill--out' : isLowStock ? 'stock-pill--low' : 'stock-pill--normal'
                    }">
                        ${isOutOfStock ? 'Out of Stock' : isLowStock ? `${stock} left` : `${stock} in stock`}
                    </span>
                </div>

                <div class="vendor-card-content">
                    <div class="flex items-center gap-1.5 text-xs text-slate-500 font-medium mb-1">
                        <i data-lucide="store" class="w-3.5 h-3.5 text-blue-600 shrink-0"></i>
                        <span class="truncate">${escapeHtml(p.supplierBusinessName || p.supplierName || 'Verified Supplier')}</span>
                    </div>

                    <h3 class="vendor-card-title">${escapeHtml(p.name)}</h3>
                    
                    <p class="vendor-card-desc">${escapeHtml(p.description || 'Wholesale food supplies delivered directly to your stall or store.')}</p>

                    <div class="vendor-card-footer">
                        <div>
                            <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Wholesale Price</div>
                            <div class="text-lg font-black text-slate-900">${variants.length ? 'From ' : ''}${formatPeso(displayPrice)}</div>
                        </div>

                        <button type="button" data-product-id="${escapeHtml(p.id)}" 
                            class="vendor-card-cta ${isOutOfStock ? 'vendor-card-cta--disabled' : ''}" 
                            ${isOutOfStock ? 'disabled' : ''}>
                            <i data-lucide="shopping-cart" class="w-4 h-4"></i>
                            <span>${isOutOfStock ? 'Sold Out' : 'Order'}</span>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }).join('');

    grid.querySelectorAll('[data-product-id]').forEach(button => {
        button.addEventListener('click', () => {
            const product = products.find(item => String(item.id) === button.dataset.productId);
            if (product && (product.variants?.some(variant => Number(variant.stockQuantity) > 0) || Number(product.stockQuantity) > 0)) {
                openCheckoutModal(product);
            }
        });
    });

    lucide.createIcons();
}

function resetFilters() {
    const searchInput = document.getElementById('vendorSearchInput');
    const catSelect = document.getElementById('vendorCategoryFilter');
    const sortSelect = document.getElementById('vendorSortSelect');
    if (searchInput) searchInput.value = '';
    if (catSelect) catSelect.value = 'all';
    if (sortSelect) sortSelect.value = 'featured';
    currentCategory = 'all';
    currentSort = 'featured';
    updateCategoryPills('all');
    filterProducts();
}

// Filter and Sort Products
function filterProducts() {
    const query = document.getElementById('vendorSearchInput')?.value.toLowerCase().trim() || '';
    const cat = currentCategory;

    let filtered = productsData.filter(p => {
        const matchesQuery = !query || p.name.toLowerCase().includes(query) || (p.category && p.category.toLowerCase().includes(query)) || (p.supplierBusinessName && p.supplierBusinessName.toLowerCase().includes(query));
        const matchesCat = (cat === 'all') || (p.category === cat);
        return matchesQuery && matchesCat;
    });

    // Apply Sorting
    if (currentSort === 'price-asc') {
        filtered.sort((a, b) => Number(a.price) - Number(b.price));
    } else if (currentSort === 'price-desc') {
        filtered.sort((a, b) => Number(b.price) - Number(a.price));
    } else if (currentSort === 'stock-desc') {
        filtered.sort((a, b) => Number(b.stockQuantity) - Number(a.stockQuantity));
    }

    renderVendorProducts(filtered);
}

// Render Orders Table
function renderVendorOrders(orders) {
    const tbody = document.getElementById('vendorOrdersTableBody');
    const countBadge = document.getElementById('vendorOrdersCountBadge');
    if (countBadge) countBadge.textContent = `${orders.length} orders`;
    if (!tbody) return;

    if (!orders.length) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="py-12 text-center text-slate-400">
                    <div class="w-12 h-12 rounded-xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-2">
                        <i data-lucide="package" class="w-6 h-6"></i>
                    </div>
                    <p class="font-bold text-slate-700 text-sm">No wholesale orders recorded yet</p>
                    <p class="text-xs text-slate-400 mt-1">Browse the marketplace to order your first supplies batch.</p>
                </td>
            </tr>
        `;
        lucide.createIcons();
        return;
    }

    tbody.innerHTML = orders.map(o => {
        const statusMap = {
            delivered: { cls: 'bg-emerald-50 text-emerald-700 border-emerald-200', label: 'Delivered', icon: 'check-circle-2' },
            confirmed: { cls: 'bg-blue-50 text-blue-700 border-blue-200', label: 'Confirmed', icon: 'truck' },
            cancelled: { cls: 'bg-red-50 text-red-700 border-red-200', label: 'Cancelled', icon: 'x-circle' },
            pending: { cls: 'bg-amber-50 text-amber-700 border-amber-200', label: 'Pending Approval', icon: 'clock' }
        };
        const s = statusMap[o.status] || statusMap.pending;

        return `
            <tr class="hover:bg-slate-50/80 transition group">
                <td class="py-4 px-5">
                    <div class="font-black text-slate-900 text-sm">Receipt #${escapeHtml(o.supplierOrderNumber)}</div>
                    <div class="text-[11px] text-slate-400">${new Date(o.createdAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                </td>
                <td class="py-4 px-5">
                    <div class="font-bold text-slate-800 text-sm">${escapeHtml(o.supplierBusinessName || o.supplierName || 'Verified Supplier')}</div>
                    <div class="text-xs text-slate-400 truncate max-w-[200px]">${escapeHtml(o.deliveryAddress || 'Standard Delivery')}</div>
                </td>
                <td class="py-4 px-5 text-slate-600">
                    <div class="space-y-1">
                        ${(o.items || []).map(item => `
                            <div class="text-xs flex items-center gap-1.5 font-medium">
                                <span class="w-5 h-5 rounded bg-slate-100 text-slate-700 flex items-center justify-center font-bold text-[10px] shrink-0">${Number(item.quantity)}</span>
                                <span class="truncate max-w-[180px]">${escapeHtml(item.name)}</span>
                            </div>
                        `).join('')}
                    </div>
                </td>
                <td class="py-4 px-5">
                    <div class="font-black text-slate-900 text-sm">${formatPeso(o.totalAmount)}</div>
                    <div class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">${escapeHtml(o.paymentMethod || 'COD')}</div>
                </td>
                <td class="py-4 px-5">
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold border ${s.cls}">
                        <i data-lucide="${s.icon}" class="w-3.5 h-3.5"></i>
                        <span>${s.label}</span>
                    </span>
                </td>
                <td class="py-4 px-5 text-right">
                    <div class="inline-flex items-center gap-1.5 justify-end">
                        ${o.status !== 'delivered' && o.status !== 'cancelled' ? `
                            <button type="button" onclick="confirmOrderDelivered(${o.id}, '${escapeHtml(o.supplierOrderNumber)}')"
                                class="px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs inline-flex items-center gap-1 shadow-sm transition">
                                <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                                <span>Delivered</span>
                            </button>
                        ` : ''}
                        <a href="orders.html" class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-100 font-bold text-xs inline-flex items-center gap-1 transition">
                            <span>Details</span>
                            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                        </a>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    lucide.createIcons();
}

async function confirmOrderDelivered(orderId, orderNumber) {
    if (!confirm(`Confirm receipt of Wholesale Order #${orderNumber}?\n\nThis will mark the delivery as complete.`)) {
        return;
    }

    try {
        const res = await API.put('api/orders.php', {
            orderId: Number(orderId),
            status: 'delivered'
        });

        if (res && res.success) {
            const order = vendorOrdersData.find(o => Number(o.id) === Number(orderId));
            if (order) order.status = 'delivered';
            renderVendorOrders(vendorOrdersData);

            const toast = document.createElement('div');
            toast.className = 'fixed bottom-5 right-5 z-[60] w-[min(24rem,calc(100vw-2rem))] rounded-2xl bg-emerald-600 text-white p-4 shadow-2xl border border-emerald-500 flex items-start gap-3';
            toast.innerHTML = `
                <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                    <i data-lucide="check-circle-2" class="w-5 h-5 text-white"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-sm">Delivery Completed!</p>
                    <p class="text-xs text-emerald-100 mt-0.5">Order Receipt #${escapeHtml(orderNumber)} marked as Delivered.</p>
                </div>
            `;
            document.body.appendChild(toast);
            lucide.createIcons();
            setTimeout(() => toast.remove(), 3500);

            // Refresh stats
            const stats = await API.get('api/vendor.php?action=summary');
            const totalOrdersEl = document.getElementById('statTotalOrders');
            const pendingOrdersEl = document.getElementById('statPendingOrders');
            const totalSpendEl = document.getElementById('statTotalSpend');
            if (totalOrdersEl) totalOrdersEl.textContent = String(stats?.totalOrders ?? 0);
            if (pendingOrdersEl) pendingOrdersEl.textContent = String(stats?.pendingOrders ?? 0);
            if (totalSpendEl) totalSpendEl.textContent = formatPeso(stats?.totalSpend);
        }
    } catch (err) {
        alert(err.message || 'Unable to update order status.');
    }
}

// Switch between Tabs: 'marketplace', 'procurement', 'orders'
function switchVendorTab(tab, updateHash = true) {
    const secMarket = document.getElementById('sectionMarketplace');
    const secProc = document.getElementById('sectionProcurement');
    const secOrders = document.getElementById('sectionOrders');

    const btnMarket = document.getElementById('tabMarketplaceBtn');
    const btnProc = document.getElementById('tabProcurementBtn');
    const btnOrders = document.getElementById('tabOrdersBtn');

    // Hide all
    secMarket?.classList.add('hidden');
    secProc?.classList.add('hidden');
    secOrders?.classList.add('hidden');

    btnMarket?.classList.remove('vendor-tab-btn--active');
    btnProc?.classList.remove('vendor-tab-btn--active');
    btnOrders?.classList.remove('vendor-tab-btn--active');

    if (tab === 'orders') {
        secOrders?.classList.remove('hidden');
        btnOrders?.classList.add('vendor-tab-btn--active');
        if (updateHash) window.location.hash = 'orders';
    } else if (tab === 'procurement') {
        secProc?.classList.remove('hidden');
        btnProc?.classList.add('vendor-tab-btn--active');
        if (updateHash) window.location.hash = 'procurement';
        if (typeof loadVendorAnalytics === 'function') loadVendorAnalytics();
    } else {
        secMarket?.classList.remove('hidden');
        btnMarket?.classList.add('vendor-tab-btn--active');
        if (updateHash) window.location.hash = 'marketplace';
    }

    lucide.createIcons();
}

// Checkout Modal Logic
let checkoutProduct = null;

function updateCheckoutVariantDisplay() {
    const variant = checkoutProduct?.variants?.find(item => String(item.id) === String(checkoutProduct.variantId));
    const price = variant ? Number(variant.price) : Number(checkoutProduct?.basePrice || 0);
    const stock = variant ? Number(variant.stockQuantity) : Number(checkoutProduct?.baseStock || 0);
    checkoutProduct.unitPrice = price;
    checkoutProduct.stockQuantity = stock;
    document.getElementById('modalRawPrice').value = price;
    document.getElementById('modalStockQuantity').value = stock;
    document.getElementById('modalUnitPrice').textContent = formatPeso(price);
    document.getElementById('modalStock').textContent = `${stock} units in stock`;
    document.getElementById('modalStockHelper').textContent = `${stock} units`;
    document.getElementById('modalQuantity').max = stock;
    document.getElementById('modalQuantity').value = stock > 0 ? 1 : 0;
    document.getElementById('checkoutSubmitButton').disabled = stock <= 0 || (checkoutProduct.variants?.length > 0 && !checkoutProduct.variantId);
    calculateTotal();
}

function selectCheckoutVariant(variantId) {
    checkoutProduct.variantId = variantId || null;
    document.getElementById('variantSelectionError')?.classList.add('hidden');
    updateCheckoutVariantDisplay();
}

function renderCheckoutVariants(variants) {
    const selector = document.getElementById('variantSelector');
    const select = document.getElementById('modalVariant');
    if (!selector || !select) return;
    if (!variants?.length) {
        selector.classList.add('hidden');
        select.innerHTML = '';
        return;
    }
    selector.classList.remove('hidden');
    select.innerHTML = '<option value="">Select a variant</option>' + variants.map(variant => `<option value="${escapeHtml(variant.id)}">${escapeHtml(variant.name)} · ${formatPeso(variant.price)} · ${Number(variant.stockQuantity)} in stock</option>`).join('');
    select.value = '';
}

async function openCheckoutModal(product) {
    const productName = product.name;
    const supplierName = product.supplierBusinessName || product.supplierName || 'Verified Supplier';
    checkoutProduct = {
        productId: product.id,
        productName,
        basePrice: Number(product.price),
        baseStock: Number(product.stockQuantity),
        unitPrice: Number(product.price),
        stockQuantity: Number(product.stockQuantity),
        supplierId: product.supplierId,
        supplierName,
        variants: Array.isArray(product.variants) ? product.variants : [],
        variantId: null
    };

    document.getElementById('modalProdId').value = product.id;
    document.getElementById('modalRawPrice').value = checkoutProduct.unitPrice;
    document.getElementById('modalStockQuantity').value = checkoutProduct.stockQuantity;
    document.getElementById('modalProdName').textContent = productName;
    document.getElementById('modalSupplierName').textContent = supplierName;
    document.getElementById('modalCategory').textContent = product.category || 'Food Supplies';
    document.getElementById('modalUnitPrice').textContent = formatPeso(checkoutProduct.unitPrice);
    renderCheckoutVariants(checkoutProduct.variants);
    updateCheckoutVariantDisplay();

    const qtyInput = document.getElementById('modalQuantity');
    qtyInput.max = checkoutProduct.stockQuantity;
    qtyInput.value = 1;
    document.getElementById('quantityError')?.classList.add('hidden');

    // Load saved default delivery address if empty
    const addressInput = document.getElementById('deliveryAddress');
    if (addressInput && !addressInput.value.trim()) {
        try {
            const profile = await API.get('api/profile.php');
            if (profile?.address) addressInput.value = profile.address;
        } catch (e) {
            // ignore
        }
    }

    const image = document.getElementById('modalProductImage');
    const placeholder = document.getElementById('modalProductPlaceholder');
    if (image) {
        image.alt = productName;
        const validSrc = safeImageUrl(product.imageUrl);
        image.src = validSrc;
        image.classList.toggle('hidden', !validSrc);
        placeholder?.classList.toggle('hidden', Boolean(validSrc));
    }

    const modal = document.getElementById('checkoutModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.querySelector('.checkout-modal-panel')?.scrollTo(0, 0);
    }
    lucide.createIcons();
    qtyInput.focus({ preventScroll: true });
}

function closeCheckoutModal() {
    const modal = document.getElementById('checkoutModal');
    if (modal) modal.classList.add('hidden');
    const submitBtn = document.getElementById('checkoutSubmitButton');
    if (submitBtn) submitBtn.disabled = false;
}

function updateQuantity(change) {
    const input = document.getElementById('modalQuantity');
    const max = Number(document.getElementById('modalStockQuantity').value) || 1;
    const current = Number.parseInt(input.value, 10) || 1;
    input.value = Math.min(max, Math.max(1, current + change));
    calculateTotal();
}

function calculateTotal() {
    const price = Number(document.getElementById('modalRawPrice')?.value) || 0;
    const max = Number(document.getElementById('modalStockQuantity')?.value) || 1;
    const input = document.getElementById('modalQuantity');
    const quantity = Number.parseInt(input?.value, 10) || 1;
    const validQuantity = Math.min(max, Math.max(1, quantity));
    const subtotal = price * validQuantity;
    const deliveryFee = subtotal >= 5000 ? 0 : 150;

    if (input) input.value = validQuantity;
    document.getElementById('quantityError')?.classList.toggle('hidden', quantity >= 1 && quantity <= max);

    const subtotalEl = document.getElementById('modalSubtotal');
    const feeEl = document.getElementById('modalDeliveryFee');
    const totalEl = document.getElementById('modalTotalPayable');

    if (subtotalEl) subtotalEl.textContent = formatPeso(subtotal);
    if (feeEl) feeEl.textContent = deliveryFee === 0 ? 'FREE (Orders ₱5,000+)' : formatPeso(deliveryFee);
    if (totalEl) totalEl.textContent = formatPeso(subtotal + deliveryFee);

    return { quantity: validQuantity, subtotal, deliveryFee, total: subtotal + deliveryFee };
}

function showCheckoutToast(order) {
    const toast = document.createElement('div');
    toast.className = 'fixed bottom-5 right-5 z-[60] w-[min(24rem,calc(100vw-2rem))] rounded-2xl bg-emerald-600 text-white p-4 shadow-2xl animate-fade-in flex items-start gap-3 border border-emerald-500';
    toast.innerHTML = `
        <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
            <i data-lucide="check-circle-2" class="w-5 h-5 text-white"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="font-bold text-sm">Wholesale Order Confirmed!</p>
            <p class="text-xs text-emerald-100 mt-0.5">Receipt #${Number(order.supplierOrderNumber)} · ${formatPeso(order.totalAmount)}</p>
            <a href="orders.html" class="inline-flex items-center gap-1 mt-2 text-xs font-bold text-white bg-white/20 hover:bg-white/30 px-3 py-1 rounded-lg transition">
                <span>View My Orders</span>
                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </a>
        </div>
    `;
    document.body.appendChild(toast);
    lucide.createIcons();
    setTimeout(() => {
        window.location.href = 'orders.html';
    }, 1500);
}

async function handleCheckoutSubmit(event) {
    event.preventDefault();
    const button = document.getElementById('checkoutSubmitButton');
    const summary = calculateTotal();
    const address = document.getElementById('deliveryAddress')?.value.trim();

    if (checkoutProduct?.variants?.length && !checkoutProduct.variantId) {
        document.getElementById('variantSelectionError')?.classList.remove('hidden');
        return;
    }
    if (!checkoutProduct || !address || summary.quantity < 1 || summary.quantity > checkoutProduct.stockQuantity) {
        alert('Please provide a complete delivery address and valid quantity.');
        return;
    }

    button.disabled = true;
    button.innerHTML = '<i data-lucide="loader-circle" class="w-4 h-4 animate-spin"></i><span>Placing Wholesale Order...</span>';
    lucide.createIcons();

    try {
        const order = await API.post('api/orders.php', {
            productId: checkoutProduct.productId,
            variantId: checkoutProduct.variantId || null,
            quantity: summary.quantity,
            paymentMethod: 'Cash on Delivery',
            deliveryAddress: address,
            deliveryNotes: document.getElementById('deliveryNotes')?.value.trim() || ''
        });

        closeCheckoutModal();
        showCheckoutToast(order);
    } catch (err) {
        button.disabled = false;
        button.innerHTML = '<i data-lucide="check-circle" class="w-4 h-4"></i><span>Confirm Checkout</span>';
        lucide.createIcons();
        alert(err.message || 'Unable to place order. Please try again.');
    }
}