// Shared frontend layout for static HTML pages.
(function () {
    const rolePages = {
        vendor: ['vendor.html', 'marketplace.html', 'orders.html', 'profile.html'],
        admin: ['admin.html', 'marketplace.html', 'profile.html'],
        supplier: ['supplier.html', 'profile.html']
    };

    const pageDetails = {
        'vendor.html': ['Vendor Hub', 'layout-dashboard'],
        'marketplace.html': ['Marketplace', 'store'],
        'orders.html': ['My Orders', 'shopping-bag'],
        'supplier.html': ['Supplier Hub', 'briefcase-business'],
        'profile.html': ['Profile', 'user-cog'],
        'admin.html': ['Admin Panel', 'shield-check']
    };

    function icon(name, className) {
        return `<i data-lucide="${name}" class="${className || 'w-4 h-4'}"></i>`;
    }

    function renderHeader(role) {
        const mount = document.querySelector('[data-site-header]');
        if (!mount) return;
        const currentPage = window.location.pathname.split('/').pop() || 'index.html';
        const navItems = (rolePages[role] || []).map(href => [href, ...pageDetails[href]]);
        mount.innerHTML = `
            <header class="site-header sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200 shadow-sm">
                <div class="site-header-inner max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                    <a href="index.html" class="site-brand flex items-center gap-2 text-xl font-extrabold text-slate-900 tracking-tight">
                        <div class="site-brand-mark w-9 h-9 rounded-xl bg-blue-600 text-white flex items-center justify-center shadow-md shadow-blue-500/20">${icon('boxes', 'w-5 h-5')}</div>
                        <span>Vend<span class="text-blue-600">Link</span></span>
                    </a>
                    <button type="button" class="mobile-menu-button" aria-controls="primaryNavigation" aria-expanded="false" aria-label="Open navigation menu" onclick="toggleNavigation()">${icon('menu', 'w-5 h-5')}</button>
                    <nav id="primaryNavigation" class="primary-navigation flex items-center gap-1 sm:gap-2" aria-label="Primary navigation">
                        ${navItems.map(([href, label, lucide]) => `<a href="${href}" class="nav-link ${currentPage === href ? 'active' : ''} flex items-center gap-1.5">${icon(lucide)} ${label}</a>`).join('')}
                        <div class="h-6 w-px bg-slate-200 mx-2"></div>
                        <button onclick="logout()" class="logout-button px-3 py-1 text-white bg-red-500 hover:bg-red-600 rounded-lg shadow-sm transition" title="Logout">${icon('log-out', 'w-4 h-4 inline-block mr-1')} Logout</button>
                    </nav>
                </div>
            </header>`;
        lucide.createIcons();
    }

    function applyRole(role) {
        document.body.classList.remove('role-vendor', 'role-admin', 'role-supplier');
        document.body.classList.add(`role-${role}`);
        document.querySelectorAll('[data-role-only]').forEach(element => {
            element.hidden = element.dataset.roleOnly !== role;
        });
    }

    function getRoleHome(role) {
        return role === 'supplier' ? 'supplier.html' : role === 'admin' ? 'admin.html' : 'vendor.html';
    }

    async function initializeAccess() {
        const response = await fetch('api/profile.php', { credentials: 'same-origin' });
        if (!response.ok) throw new Error('Authentication required');
        const profile = await response.json();
        const role = profile.role;
        if (!rolePages[role]) throw new Error('Invalid account role');

        applyRole(role);
        renderHeader(role);
        const currentPage = window.location.pathname.split('/').pop() || 'index.html';
        if (!rolePages[role].includes(currentPage)) {
            window.location.replace(getRoleHome(role));
            return;
        }
        document.documentElement.dataset.roleReady = role;
        return true;
    }

    function renderFooter() {
        const mount = document.querySelector('[data-site-footer]');
        if (!mount) return;
        mount.innerHTML = `<footer class="site-footer"><div class="site-footer-inner"><div class="site-footer-brand"><div class="site-footer-mark">${icon('boxes', 'w-4 h-4')}</div><div><strong>Vend<span>Link</span></strong><p>Better sourcing for growing food businesses.</p></div></div><div class="site-footer-meta"><span>Wholesale food marketplace</span><span>&copy; ${new Date().getFullYear()} VendLink</span></div></div></footer>`;
        lucide.createIcons();
    }

    window.toggleNavigation = function () {
        const navigation = document.getElementById('primaryNavigation');
        const menuButton = document.querySelector('.mobile-menu-button');
        if (!navigation || !menuButton) return;
        const isOpen = navigation.classList.toggle('is-open');
        menuButton.setAttribute('aria-expanded', String(isOpen));
        menuButton.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
    };

    document.addEventListener('DOMContentLoaded', () => {
        renderFooter();
        const currentPage = window.location.pathname.split('/').pop() || 'index.html';
        if (currentPage !== 'index.html') {
            window.vendLinkAccessReady = initializeAccess().catch(() => {
                window.location.replace('index.html');
                return false;
            });
        } else {
            window.vendLinkAccessReady = Promise.resolve(true);
        }
        fetch('api/auth.php?action=csrf', { credentials: 'same-origin' })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data?.csrfToken) {
                    let meta = document.querySelector('meta[name="csrf-token"]');
                    if (!meta) {
                        meta = document.createElement('meta');
                        meta.name = 'csrf-token';
                        document.head.appendChild(meta);
                    }
                    meta.content = data.csrfToken;
                }
            })
            .catch(() => {});
    });
})();
