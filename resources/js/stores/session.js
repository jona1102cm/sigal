import { defineStore } from 'pinia';
import { getToken, request, setToken } from '../lib/api';

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
        roleLabel: (state) => state.user?.roles?.map((role) => role.name).join(' · ') || 'Usuario del sistema',
    },

    actions: {
        async restore() {
            if (!getToken()) {
                this.ready = true;
                return;
            }

            try {
                const payload = await request('/auth/me');
                this.user = payload.data;
            } catch {
                setToken(null);
                this.user = null;
            } finally {
                this.ready = true;
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
