const API_PREFIX = '/api';
const TOKEN_KEY = 'sigal.access-token';

/** Error uniforme que conserva el código HTTP y los errores por campo enviados por Laravel. */
export class ApiError extends Error {
    constructor(message, { status = 0, errors = {} } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
    }
}

export function getToken() {
    return window.sessionStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
    if (token) {
        window.sessionStorage.setItem(TOKEN_KEY, token);
    } else {
        window.sessionStorage.removeItem(TOKEN_KEY);
    }
}

/** Construye cabeceras comunes sin fijar Content-Type en FormData: el navegador agrega su boundary. */
function buildHeaders(headers, body) {
    const merged = new Headers({
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...headers,
    });
    const token = getToken();

    if (token) {
        merged.set('Authorization', `Bearer ${token}`);
    }

    if (body && !(body instanceof FormData) && !merged.has('Content-Type')) {
        merged.set('Content-Type', 'application/json');
    }

    return merged;
}

/** Ejecuta una solicitud autenticada y convierte cualquier respuesta fallida en ApiError. */
export async function request(path, options = {}) {
    const { body, headers, ...requestOptions } = options;
    const response = await fetch(`${API_PREFIX}${path}`, {
        ...requestOptions,
        cache: 'no-store',
        headers: buildHeaders(headers, body),
        body: body instanceof FormData || body === undefined ? body : JSON.stringify(body),
    });
    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        const errors = payload?.errors ?? {};
        const message = Object.values(errors).flat().join(' ') || payload?.message || 'No fue posible completar la operación.';

        throw new ApiError(message, { status: response.status, errors });
    }

    return payload;
}

/** Recorre colecciones paginadas de Laravel y devuelve un único arreglo para catálogos pequeños. */
export async function getAll(path) {
    const items = [];
    let page = 1;
    let lastPage = 1;

    do {
        const separator = path.includes('?') ? '&' : '?';
        const payload = await request(`${path}${separator}page=${page}`);
        items.push(...(payload?.data ?? []));
        lastPage = payload?.meta?.last_page ?? 1;
        page += 1;
    } while (page <= lastPage);

    return items;
}

/** Descarga un blob protegido sin exponer el token en una URL ni conservar URLs temporales. */
export async function download(path, fileName) {
    const response = await fetch(`${API_PREFIX}${path}`, { cache: 'no-store', headers: buildHeaders() });

    if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new ApiError(payload?.message || 'No fue posible descargar el archivo.', { status: response.status, errors: payload?.errors });
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}
