const DEFAULT_TIMEOUT = 30000;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function request(method, url, body = null, options = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), options.timeout ?? DEFAULT_TIMEOUT);

    const headers = {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers,
    };

    // Bearer token support (API endpoints) — set later in Phase 1
    const bearer = options.bearer ?? window.__authToken;
    if (bearer) headers['Authorization'] = `Bearer ${bearer}`;

    let payload;
    if (body instanceof FormData) {
        payload = body; // let browser set multipart boundary
    } else if (body !== null) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    try {
        const res = await fetch(url, { method, headers, body: payload, signal: controller.signal });
        clearTimeout(timeout);

        const isJson = res.headers.get('content-type')?.includes('application/json');
        const data = isJson ? await res.json() : await res.text();

        if (!res.ok) {
            throw { status: res.status, data, message: data?.message ?? `Request failed (${res.status})` };
        }
        return data;
    } catch (err) {
        clearTimeout(timeout);
        if (err.name === 'AbortError') {
            throw { status: 0, message: 'Request timed out' };
        }
        throw err;
    }
}

export const http = {
    get:    (url, opts)        => request('GET', url, null, opts),
    post:   (url, body, opts)  => request('POST', url, body, opts),
    put:    (url, body, opts)  => request('PUT', url, body, opts),
    delete: (url, opts)        => request('DELETE', url, null, opts),
};