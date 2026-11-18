/**
 * Small helpers for admin endpoints that answer with JSON instead of an
 * Inertia redirect (bulk import preview/import, inline creates). Same
 * session and CSRF token as Inertia's own requests.
 */
function xsrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

function headers(extra = {}) {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrfToken(),
        ...extra,
    };
}

/**
 * POST a JSON body and return the raw response.
 */
export function postJson(url, body) {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(body),
    });
}

/**
 * POST a multipart form (file uploads) and return the raw response.
 */
export function postForm(url, formData) {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers(),
        body: formData,
    });
}

/**
 * First human-readable message of a Laravel error response, or a fallback.
 */
export function firstError(body, fallback) {
    return String(Object.values(body?.errors ?? {})[0]?.[0] ?? body?.message ?? fallback);
}
