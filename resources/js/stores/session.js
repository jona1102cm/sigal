import { defineStore } from 'pinia';
import { getActingContext, getToken, request, setActingContext, setToken } from '../lib/api';

/**
 * Fuente única de la sesión visible en Vue.
 * Las capacidades son ayudas de interfaz; las Policies del backend siguen siendo la autoridad.
 */
export const useSessionStore = defineStore('session', {
    state: () => ({
        user: null,
        ready: false,
        loading: false,
        actingContext: getActingContext(),
    }),

    getters: {
        authenticated: (state) => Boolean(state.user),
        mustChangePassword: (state) => state.user?.must_change_password === true,
        hasPermission: (state) => (code) => state.user?.permissions?.includes(code) ?? false,
        isSuperAdministrator: (state) => state.user?.roles?.some((role) => role.code === 'super_administrator') ?? false,
        isHumanResourcesManager: (state) => state.user?.roles?.some((role) => role.code === 'human_resources_manager') ?? false,
        canManageHumanResources() {
            return this.hasPermission('human_resources.employees.view');
        },
        canViewDashboard() {
            return this.hasPermission('dashboard.view');
        },
        canUseDocumentManagement() {
            return this.hasPermission('document_management.expedients.view');
        },
        canUseLegislativeWorkspace() {
            return this.canUseDocumentManagement && (
                this.isSuperAdministrator
                || this.legislativeProfiles.length > 0
                || (this.user?.office_memberships ?? []).some((membership) => membership.office?.code === 'SGEN')
            );
        },
        canCreateExpedients() {
            return this.hasPermission('document_management.expedients.create');
        },
        canConfigureOfficeAccess() {
            return this.hasPermission('document_management.office_access.configure')
                && (this.isSuperAdministrator
                    || (this.user?.office_memberships ?? []).some((membership) => membership.can_view_document_access)
                    || this.legislativeProfiles.some((profile) => profile.is_principal_authority || profile.is_effective_authority));
        },
        canUseWarehouse() {
            return this.hasPermission('warehouse.requests.view');
        },
        canCreateWarehouseRequest() {
            return this.hasPermission('warehouse.requests.create');
        },
        canUseAdministration: (state) => state.user?.permissions?.some((permission) => permission.startsWith('administration.')) ?? false,
        roleLabel: (state) => state.user?.roles?.map((role) => role.name).join(' · ') || 'Usuario del sistema',
        legislativeProfiles: (state) => state.user?.legislative_profiles ?? [],
        actingProfile(state) {
            if (!state.actingContext) return null;
            return (state.user?.legislative_profiles ?? []).find((profile) => (
                state.actingContext.body_membership_id
                    ? Number(profile.body_membership_id) === Number(state.actingContext.body_membership_id)
                    : Number(profile.legislature_member_id) === Number(state.actingContext.legislature_member_id) && !profile.body_membership_id
            )) ?? null;
        },
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
                this.normalizeActingContext();
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
                this.normalizeActingContext();
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
                this.actingContext = null;
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
                this.normalizeActingContext();
                return this.user;
            } finally {
                this.loading = false;
            }
        },

        selectActingProfile(profile) {
            const context = profile ? {
                legislature_member_id: profile.legislature_member_id,
                body_membership_id: profile.body_membership_id || null,
            } : null;
            this.actingContext = context;
            setActingContext(context);
        },

        normalizeActingContext() {
            if (!this.actingContext || !this.actingProfile) {
                const first = this.user?.legislative_profiles?.[0] ?? null;
                this.selectActingProfile(first);
            }
        },
    },
});
