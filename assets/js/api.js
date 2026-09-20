// assets/js/api.js
const API = {
    async request(endpoint, method = 'GET', data = null) {
        const canRetry = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method);
        for (let attempt = 0; attempt < (canRetry ? 2 : 1); attempt += 1) {
            const options = {
                method,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }
            };
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (csrfToken && canRetry) {
                options.headers['X-CSRF-Token'] = csrfToken;
            }
            if (data && method !== 'GET') {
                options.body = JSON.stringify(data);
            }

            try {
                const res = await fetch(endpoint, options);
                const raw = await res.text();
                let json;
                try {
                    json = JSON.parse(raw);
                } catch (parseError) {
                    console.error(`Invalid JSON response [${endpoint}]:`, raw.slice(0, 1000));
                    throw new Error(endpoint.includes('products.php')
                        ? 'Unable to load products right now.'
                        : 'Unable to complete this request right now.');
                }
                if (res.ok) return json;
                if (attempt === 0 && canRetry && res.status === 403 && json.error === 'Invalid security token.') {
                    const tokenResponse = await fetch('api/auth.php?action=csrf', { credentials: 'same-origin' });
                    const tokenJson = await tokenResponse.json();
                    if (tokenResponse.ok && tokenJson.csrfToken) {
                        const meta = document.querySelector('meta[name="csrf-token"]');
                        if (meta) meta.content = tokenJson.csrfToken;
                        continue;
                    }
                }
                throw new Error(json.error || 'An error occurred.');
            } catch (err) {
                if (attempt === 0 && canRetry && err.message === 'Invalid security token.') continue;
                console.error(`API Error [${endpoint}]:`, err);
                throw err;
            }
        }
    },

    get(endpoint) { return this.request(endpoint, 'GET'); },
    post(endpoint, data) { return this.request(endpoint, 'POST', data); },
    put(endpoint, data) { return this.request(endpoint, 'PUT', data); },
    delete(endpoint) { return this.request(endpoint, 'DELETE'); }
};

async function logout() {
    try {
        await API.post('api/auth.php?action=logout');
        window.location.href = 'index.html';
    } catch (e) {
        window.location.href = 'index.html';
    }
}