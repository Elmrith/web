function escapeAdminHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[character]));
}

function renderAdminData(data) {
    const stats = data.stats || {};
    document.getElementById('adminUserCount').textContent = Number(stats.userCount || 0).toLocaleString();
    document.getElementById('adminProductCount').textContent = Number(stats.productCount || 0).toLocaleString();
    document.getElementById('adminOrderTotal').textContent = `₱${Number(stats.orderTotal || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    document.getElementById('adminAccountCount').textContent = `${(data.users || []).length} accounts registered`;

    const body = document.getElementById('adminUsersBody');
    const users = data.users || [];
    body.innerHTML = users.length ? users.map(user => `
        <tr class="hover:bg-slate-50/50 transition">
            <td class="py-4 px-6 font-bold text-slate-900">${escapeAdminHtml(user.name || 'User')}
                ${user.businessName ? `<div class="text-xs font-normal text-slate-400">${escapeAdminHtml(user.businessName)}</div>` : ''}
            </td>
            <td class="py-4 px-6 text-slate-600">${escapeAdminHtml(user.email)}</td>
            <td class="py-4 px-6"><span class="px-2.5 py-1 text-[10px] font-bold rounded-lg uppercase tracking-wider ${user.role === 'supplier' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'}">${escapeAdminHtml(user.role)}</span></td>
            <td class="py-4 px-6"><span class="px-2.5 py-1 text-[10px] font-bold rounded-lg uppercase tracking-wider ${Number(user.isActive) ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}">${Number(user.isActive) ? 'Authorized' : 'Suspended'}</span></td>
            <td class="py-4 px-6 text-xs text-slate-400">${new Date(user.createdAt).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })}</td>
        </tr>`).join('') : '<tr><td colspan="5" class="py-8 text-center text-slate-400">No registered users found.</td></tr>';
}

document.addEventListener('DOMContentLoaded', async () => {
    try {
        renderAdminData(await API.get('api/admin.php'));
    } catch (error) {
        document.getElementById('adminUsersBody').innerHTML = `<tr><td colspan="5" class="py-8 text-center text-red-500">${escapeAdminHtml(error.message || 'Unable to load users.')}</td></tr>`;
    }
});
