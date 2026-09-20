// assets/js/profile.js
const profileForm = document.getElementById('profileForm');
const profileError = document.getElementById('profileError');
const saveState = document.getElementById('saveState');
const saveButton = document.getElementById('saveProfileButton');
const avatarPreview = document.getElementById('avatarPreview');
const avatarInitial = document.getElementById('avatarInitial');
const deliveryAddressForm = document.getElementById('deliveryAddressForm');
const savedAddresses = document.getElementById('savedAddresses');
const addressSuggestions = document.getElementById('addressSuggestions');
let savedAddressData = [];
let addressSearchTimer;
let addressCoordinates = { latitude: null, longitude: null };

function profileValue(id) {
    return document.getElementById(id).value.trim();
}

function setFeedback(message, type = 'success') {
    saveState.textContent = message;
    saveState.className = type === 'success'
        ? 'text-sm font-semibold px-4 py-2.5 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100'
        : 'text-sm font-semibold px-4 py-2.5 rounded-xl bg-red-50 text-red-700 border border-red-100';
    saveState.classList.remove('hidden');
    profileError.classList.add('hidden');
}

function setError(message) {
    profileError.textContent = message;
    profileError.classList.remove('hidden');
    saveState.classList.add('hidden');
    profileError.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function updateAvatar(name, imageUrl) {
    avatarInitial.textContent = (name || '?').trim().charAt(0).toUpperCase() || '?';
    const safeUrl = /^(https:\/\/|\/|assets\/)/i.test(imageUrl || '') ? imageUrl : '';
    if (safeUrl) {
        avatarPreview.src = safeUrl;
        avatarPreview.classList.remove('hidden');
        avatarInitial.classList.add('hidden');
        avatarPreview.onerror = () => {
            avatarPreview.classList.add('hidden');
            avatarInitial.classList.remove('hidden');
        };
    } else {
        avatarPreview.removeAttribute('src');
        avatarPreview.classList.add('hidden');
        avatarInitial.classList.remove('hidden');
    }
}

function fillProfile(profile) {
    ['name', 'username', 'email', 'contactNumber', 'profileImage', 'businessName', 'address', 'gcashNumber', 'paymayaNumber'].forEach(id => {
        document.getElementById(id).value = profile[id] || '';
    });
    document.getElementById('notificationsEnabled').checked = Number(profile.notificationsEnabled) === 1;
    document.getElementById('summaryName').textContent = profile.name || 'VendLink User';
    document.getElementById('summaryRole').textContent = profile.role || 'Account';
    document.getElementById('summaryMemberSince').textContent = profile.createdAt ? `Member since ${new Date(profile.createdAt.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', year: 'numeric' })}` : '';
    updateAvatar(profile.name, profile.profileImage);
}

async function loadProfile() {
    try {
        const profile = await API.get('api/profile.php');
        fillProfile(profile);
        lucide.createIcons();
    } catch (error) {
        setError(error.message || 'Unable to load your profile.');
    }
}

function collectProfile() {
    return {
        name: profileValue('name'),
        username: profileValue('username'),
        email: profileValue('email'),
        contactNumber: profileValue('contactNumber'),
        profileImage: profileValue('profileImage'),
        businessName: profileValue('businessName'),
        address: profileValue('address'),
        gcashNumber: profileValue('gcashNumber'),
        paymayaNumber: profileValue('paymayaNumber'),
        notificationsEnabled: document.getElementById('notificationsEnabled').checked,
        currentPassword: document.getElementById('currentPassword').value,
        newPassword: document.getElementById('newPassword').value,
        confirmPassword: document.getElementById('confirmPassword').value
    };
}

async function handleProfileSubmit(event) {
    event.preventDefault();
    const data = collectProfile();
    if (!data.name || !data.email) {
        setError('Full name and email address are required.');
        return;
    }
    if ((data.currentPassword || data.newPassword || data.confirmPassword) && (!data.currentPassword || data.newPassword.length < 6 || data.newPassword !== data.confirmPassword)) {
        setError('Enter your current password, a new password of at least 6 characters, and matching confirmation.');
        return;
    }

    saveButton.disabled = true;
    saveButton.innerHTML = '<i data-lucide="loader-circle" class="w-4 h-4 animate-spin"></i><span>Saving changes...</span>';
    lucide.createIcons();
    try {
        const result = await API.post('api/profile.php', data);
        document.getElementById('currentPassword').value = '';
        document.getElementById('newPassword').value = '';
        document.getElementById('confirmPassword').value = '';
        setFeedback(result.message || 'Profile updated successfully!');
        await loadProfile();
    } catch (error) {
        setError(error.message || 'Profile could not be updated.');
    } finally {
        saveButton.disabled = false;
        saveButton.innerHTML = '<i data-lucide="save" class="w-4 h-4"></i><span>Save Changes</span>';
        lucide.createIcons();
    }
}

document.getElementById('profileImage').addEventListener('input', event => {
    updateAvatar(profileValue('name'), event.target.value.trim());
});
document.getElementById('name').addEventListener('input', event => {
    updateAvatar(event.target.value, profileValue('profileImage'));
});
profileForm.addEventListener('submit', handleProfileSubmit);

function addressValue(id) {
    return document.getElementById(id)?.value.trim() || '';
}

function escapeAddressHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    }[character]));
}

function setAddressFieldError(id, message = '') {
    const field = document.getElementById(id);
    const error = document.getElementById(`${id}Error`);
    if (!field || !error) return;
    field.classList.toggle('address-invalid', Boolean(message));
    error.textContent = message;
    error.classList.toggle('hidden', !message);
}

function validateDeliveryAddress() {
    const phone = addressValue('addressPhone').replace(/[\s().-]+/g, '');
    const errors = {
        addressRecipientName: addressValue('addressRecipientName') ? '' : 'Recipient name is required.',
        addressPhone: /^(?:\+63|0)9\d{9}$/.test(phone) ? '' : 'Use a valid Philippine mobile number, such as 09171234567.',
        addressLine1: addressValue('addressLine1') ? '' : 'Address line 1 is required.',
        addressCity: addressValue('addressCity') ? '' : 'City or municipality is required.',
        addressPostalCode: addressValue('addressPostalCode') ? '' : 'Postal or ZIP code is required.'
    };
    Object.entries(errors).forEach(([id, message]) => setAddressFieldError(id, message));
    return Object.values(errors).every(message => !message);
}

function showAddressToast(message, type = 'success') {
    const toast = document.getElementById('addressToast');
    if (!toast) return;
    toast.textContent = message;
    toast.className = `fixed bottom-5 right-5 z-[70] max-w-sm p-4 rounded-2xl text-sm font-semibold shadow-2xl ${type === 'success' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'}`;
    window.clearTimeout(showAddressToast.timer);
    showAddressToast.timer = window.setTimeout(() => toast.classList.add('hidden'), 3500);
}

function resetDeliveryAddressForm() {
    deliveryAddressForm.reset();
    document.getElementById('deliveryAddressId').value = '';
    document.getElementById('addressCountry').value = 'Philippines';
    document.getElementById('cancelAddressEditButton').classList.add('hidden');
    document.querySelector('#saveAddressButton span').textContent = 'Save Address';
    addressCoordinates = { latitude: null, longitude: null };
    document.querySelectorAll('#deliveryAddressForm .address-field-error').forEach(error => error.classList.add('hidden'));
    document.querySelectorAll('#deliveryAddressForm .address-invalid').forEach(field => field.classList.remove('address-invalid'));
}

function renderSavedAddresses() {
    const count = document.getElementById('addressCount');
    count.textContent = `${savedAddressData.length} address${savedAddressData.length === 1 ? '' : 'es'}`;
    if (!savedAddressData.length) {
        savedAddresses.innerHTML = '<p class="text-sm text-slate-400 py-4">No saved addresses yet. Add one above to speed up checkout.</p>';
        return;
    }
    savedAddresses.innerHTML = savedAddressData.map(address => `
        <article class="p-4 rounded-xl border ${Number(address.isDefault) ? 'border-emerald-300 bg-emerald-50/40' : 'border-slate-200 bg-slate-50/50'}">
          <div class="flex items-start justify-between gap-3"><div><h4 class="text-sm font-black text-slate-900">${escapeAddressHtml(address.recipientName)}</h4><p class="text-xs text-slate-500 mt-1">${escapeAddressHtml(address.phone)}</p></div>${Number(address.isDefault) ? '<span class="px-2 py-1 rounded-lg bg-emerald-100 text-emerald-700 text-[10px] font-bold uppercase">Default</span>' : ''}</div>
          <p class="text-sm text-slate-700 mt-3">${escapeAddressHtml(address.addressLine1)}${address.addressLine2 ? `, ${escapeAddressHtml(address.addressLine2)}` : ''}<br>${escapeAddressHtml(address.city)}${address.province ? `, ${escapeAddressHtml(address.province)}` : ''} ${escapeAddressHtml(address.postalCode)}<br>${escapeAddressHtml(address.country)}</p>
          ${address.deliveryNotes ? `<p class="text-xs text-slate-500 mt-2"><strong>Note:</strong> ${escapeAddressHtml(address.deliveryNotes)}</p>` : ''}
          <div class="flex flex-wrap gap-2 mt-4"><button type="button" data-address-action="edit" data-address-id="${Number(address.id)}" class="text-xs font-bold text-blue-600 hover:text-blue-700">Edit</button><button type="button" data-address-action="delete" data-address-id="${Number(address.id)}" class="text-xs font-bold text-red-600 hover:text-red-700">Delete</button>${Number(address.isDefault) ? '' : `<button type="button" data-address-action="default" data-address-id="${Number(address.id)}" class="text-xs font-bold text-slate-600 hover:text-slate-900">Set as default</button>`}</div>
        </article>`).join('');
}

async function loadDeliveryAddresses() {
    try {
        savedAddressData = await API.get('api/delivery_addresses.php');
        renderSavedAddresses();
    } catch (error) {
        savedAddresses.innerHTML = `<p class="text-sm text-red-600 py-4">${escapeAddressHtml(error.message || 'Unable to load addresses.')}</p>`;
    }
}

function fillDeliveryAddress(address) {
    document.getElementById('deliveryAddressId').value = address.id;
    ['recipientName', 'phone', 'addressLine1', 'addressLine2', 'city', 'province', 'postalCode', 'country', 'deliveryNotes'].forEach(key => {
        document.getElementById(`address${key.charAt(0).toUpperCase()}${key.slice(1)}`).value = address[key] || '';
    });
    document.getElementById('addressIsDefault').checked = Number(address.isDefault) === 1;
    addressCoordinates = { latitude: address.latitude || null, longitude: address.longitude || null };
    document.getElementById('cancelAddressEditButton').classList.remove('hidden');
    document.querySelector('#saveAddressButton span').textContent = 'Update Address';
    document.getElementById('delivery-addresses').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function saveDeliveryAddress(event) {
    event.preventDefault();
    if (!validateDeliveryAddress()) return;
    const button = document.getElementById('saveAddressButton');
    const id = addressValue('deliveryAddressId');
    const payload = {
        id: id ? Number(id) : undefined,
        recipientName: addressValue('addressRecipientName'), phone: addressValue('addressPhone'), addressLine1: addressValue('addressLine1'), addressLine2: addressValue('addressLine2'), city: addressValue('addressCity'), province: addressValue('addressProvince'), postalCode: addressValue('addressPostalCode'), country: addressValue('addressCountry'), deliveryNotes: addressValue('addressDeliveryNotes'), isDefault: document.getElementById('addressIsDefault').checked, ...addressCoordinates
    };
    button.disabled = true;
    button.innerHTML = '<i data-lucide="loader-circle" class="w-4 h-4 animate-spin"></i><span>Saving...</span>';
    lucide.createIcons();
    try {
        const result = id ? await API.put('api/delivery_addresses.php', payload) : await API.post('api/delivery_addresses.php', payload);
        showAddressToast(result.message || 'Delivery address saved.');
        resetDeliveryAddressForm();
        await loadDeliveryAddresses();
    } catch (error) {
        document.getElementById('addressFormError').textContent = error.message || 'Address could not be saved.';
        document.getElementById('addressFormError').classList.remove('hidden');
        showAddressToast(error.message || 'Address could not be saved.', 'error');
    } finally {
        button.disabled = false;
        button.innerHTML = '<i data-lucide="save" class="w-4 h-4"></i><span>Save Address</span>';
        lucide.createIcons();
    }
}

async function searchAddressSuggestions() {
    const query = addressValue('addressLine1');
    if (query.length < 3) { addressSuggestions.classList.add('hidden'); return; }
    try {
        const response = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=5&countrycodes=ph&q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
        const results = await response.json();
        addressSuggestions.innerHTML = results.map(result => `<button type="button" class="block w-full text-left px-4 py-3 text-xs text-slate-700 hover:bg-slate-50 border-b border-slate-100 last:border-0" data-place="${escapeAddressHtml(JSON.stringify(result))}">${escapeAddressHtml(result.display_name)}</button>`).join('') || '<p class="px-4 py-3 text-xs text-slate-400">No matching addresses found.</p>';
        addressSuggestions.classList.remove('hidden');
    } catch (error) { addressSuggestions.classList.add('hidden'); }
}

function applyPlace(result) {
    const address = result.address || {};
    document.getElementById('addressLine1').value = [address.house_number, address.road || address.pedestrian || address.neighbourhood].filter(Boolean).join(' ') || result.display_name.split(',')[0];
    document.getElementById('addressCity').value = address.city || address.town || address.municipality || address.village || '';
    document.getElementById('addressProvince').value = address.state || address.province || '';
    document.getElementById('addressPostalCode').value = address.postcode || '';
    document.getElementById('addressCountry').value = address.country || 'Philippines';
    addressCoordinates = { latitude: Number(result.lat), longitude: Number(result.lon) };
    addressSuggestions.classList.add('hidden');
}

function reverseGeocodeLocation(latitude, longitude) {
    return fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${latitude}&lon=${longitude}&addressdetails=1`).then(response => response.json());
}

document.addEventListener('DOMContentLoaded', async () => {
    lucide.createIcons();
    loadProfile();
    try {
        await window.vendLinkAccessReady;
        if (!document.body.classList.contains('role-vendor')) return;
        await loadDeliveryAddresses();
    } catch (error) { return; }
});

if (deliveryAddressForm) {
    deliveryAddressForm.addEventListener('submit', saveDeliveryAddress);
    document.getElementById('addressLine1').addEventListener('input', () => {
        window.clearTimeout(addressSearchTimer);
        addressSearchTimer = window.setTimeout(searchAddressSuggestions, 350);
    });
    addressSuggestions.addEventListener('click', event => {
        const button = event.target.closest('[data-place]');
        if (button) applyPlace(JSON.parse(button.dataset.place));
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('#addressLine1') && !event.target.closest('#addressSuggestions')) addressSuggestions.classList.add('hidden');
    });
    document.getElementById('useCurrentLocationButton').addEventListener('click', () => {
        if (!navigator.geolocation) { showAddressToast('Location is not available in this browser.', 'error'); return; }
        navigator.geolocation.getCurrentPosition(async position => {
            try { applyPlace(await reverseGeocodeLocation(position.coords.latitude, position.coords.longitude)); }
            catch (error) { showAddressToast('Could not read this location.', 'error'); }
        }, () => showAddressToast('Location permission was not granted.', 'error'), { enableHighAccuracy: true, timeout: 10000 });
    });
    document.getElementById('cancelAddressEditButton').addEventListener('click', resetDeliveryAddressForm);
    savedAddresses.addEventListener('click', async event => {
        const button = event.target.closest('[data-address-action]');
        if (!button) return;
        const address = savedAddressData.find(item => Number(item.id) === Number(button.dataset.addressId));
        if (!address) return;
        if (button.dataset.addressAction === 'edit') fillDeliveryAddress(address);
        if (button.dataset.addressAction === 'delete' && window.confirm('Delete this delivery address?')) { try { await API.delete(`api/delivery_addresses.php?id=${Number(address.id)}`); showAddressToast('Delivery address deleted.'); await loadDeliveryAddresses(); } catch (error) { showAddressToast(error.message, 'error'); } }
        if (button.dataset.addressAction === 'default') { try { await API.put('api/delivery_addresses.php', { ...address, id: Number(address.id), isDefault: true }); showAddressToast('Default address updated.'); await loadDeliveryAddresses(); } catch (error) { showAddressToast(error.message, 'error'); } }
    });
}
