import { defineStore } from 'pinia';
import { getToken, request, setToken } from '../lib/api';

/**
 * Fuente única de la sesión visible en Vue.
 * Las capacidades son ayudas de interfaz; las Policies del backend siguen siendo la autoridad.
 */
export const useSessionStore = defineStore('session', {
    state: () => ({
        user: null,
        ready: false,
        loading: false,
    }),

    getters: {
        authenticated: (state) => Boolean(state.user),
        mustChangePassword: (state) => state.user?.must_change_password === true,
        isSuperAdministrator: (state) => state.user?.roles?.some((role) => role.code === 'super_administrator') ?? false,
        isHumanResourcesManager: (state) => state.user?.roles?.some((role) => role.code === 'human_resources_manager') ?? false,
        canManageHumanResources() {
            return this.isSuperAdministrator || this.isHumanResourcesManager;
        },
        canUseDocumentManagement: (state) => state.user?.roles?.some((role) => ['super_administrator', 'observer', 'simple_user'].includes(role.code)) ?? false,
        canCreateExpedients: (state) => state.user?.roles?.some((role) => ['super_administrator', 'simple_user'].includes(role.code)) ?? false,
        roleLabel: (state) => state.user?.roles?.map((role) => role.name).join(' · ') || 'Usuario del sistema',
    },

    actions: {
        async restore() {
            // Sin token local no se consulta /auth/me y la aplicación puede mostrar el login de inmediato.
            if (!getToken()) {
                this.ready = true;
                return;
            }

            try {
                await this.refresh();
            } catch {
                // Un fallo transitorio de red no destruye el token; el siguiente enfoque vuelve a consultar.
            } finally {
                this.ready = true;
            }
        },

        async refresh() {
            if (!getToken()) return null;

            try {
                const payload = await request('/auth/me');
                this.user = payload.data;
                return this.user;
            } catch (error) {
                if ([401, 403].includes(error.status)) {
                    setToken(null);
                    this.user = null;
                }
                throw error;
            }
        },

        async login(credentials) {
            this.loading = true;

            try {
                const payload = await request('/auth/login', {
                    method: 'POST',
                    body: { ...credentials, device_name: 'SIGAL Web' },
                });

                setToken(payload.token);
                this.user = payload.user.data ?? payload.user;
                return this.user;
            } finally {
                this.loading = false;
                this.ready = true;
            }
        },

        async logout() {
            try {
                if (getToken()) {
                    await request('/auth/logout', { method: 'POST' });
                }
            } finally {
                setToken(null);
                this.user = null;
            }
        },

        async changePassword(data) {
            this.loading = true;

            try {
                const payload = await request('/auth/password', {
                    method: 'POST',
                    body: { ...data, device_name: 'SIGAL Web' },
                });

                setToken(payload.token);
                this.user = payload.user.data ?? payload.user;
                return this.user;
            } finally {
                this.loading = false;
            }
        },
    },
});
