import { defineStore } from 'pinia';

const jsonHeaders = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
};

// Cliente histórico de la página aislada de legislaturas; el shell principal usa el cliente común.
async function request(path, options = {}) {
    const response = await fetch(`/api${path}`, {
        credentials: 'include',
        headers: jsonHeaders,
        ...options,
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const message = payload.message ?? 'No fue posible procesar la solicitud.';
        const errors = payload.errors ?? {};

        throw new Error(Object.values(errors).flat().join(' ') || message);
    }

    return payload;
}

/** Estado y operaciones de la pantalla administrativa aislada de legislaturas. */
export const useLegislaturesStore = defineStore('legislatures', {
    state: () => ({
        items: [],
        loading: false,
        error: null,
    }),

    actions: {
        async load() {
            this.loading = true;
            this.error = null;

            try {
                const payload = await request('/legislatures');
                this.items = payload.data;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },

        async create(data) {
            const payload = await request('/legislatures', {
                method: 'POST',
                body: JSON.stringify(data),
            });

            await this.load();
            return payload.data;
        },

        async update(id, data) {
            const payload = await request(`/legislatures/${id}`, {
                method: 'PATCH',
                body: JSON.stringify(data),
            });

            await this.load();
            return payload.data;
        },

        async activate(id) {
            const payload = await request(`/legislatures/${id}/activate`, {
                method: 'POST',
            });

            await this.load();
            return payload.data;
        },

        async inactivate(id) {
            const payload = await request(`/legislatures/${id}/inactivate`, {
                method: 'POST',
            });

            await this.load();
            return payload.data;
        },

        async replaceBoardMember(legislatureId, data) {
            const payload = await request(`/legislatures/${legislatureId}/board-assignments`, {
                method: 'POST',
                body: JSON.stringify(data),
            });

            await this.load();
            return payload.data;
        },
    },
});
