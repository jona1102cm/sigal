import { defineStore } from 'pinia';
import { download, getAll, request } from '../lib/api';

function list(payload) {
    return payload?.data ?? [];
}

function appendFormValue(body, key, value) {
    if (value === null || value === undefined || value === '') return;
    if (Array.isArray(value)) {
        value.forEach((item) => appendFormValue(body, `${key}[]`, item));
        return;
    }
    if (typeof value === 'object' && !(value instanceof File)) {
        Object.entries(value).forEach(([childKey, childValue]) => appendFormValue(body, `${key}[${childKey}]`, childValue));
        return;
    }
    body.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
}

/**
 * Estado compartido del módulo documental y de sus catálogos administrativos.
 * Las mutaciones recargan la vista afectada para reflejar el estado calculado por el servidor.
 */
export const useDocumentManagementStore = defineStore('documentManagement', {
    state: () => ({
        catalogs: {
            documentTypes: [],
            confidentialityLevels: [],
            offices: [],
        },
        catalogsLoaded: false,
        catalogsLoadedAt: null,
        catalogLoadError: null,
        expedients: [],
        pagination: null,
        expedientSearch: '',
        expedientScope: 'new',
        inboxNewCount: 0,
        expedientLoadVersion: 0,
        expedientsLoaded: false,
        lastExpedientSyncAt: null,
        selected: null,
        selectedLoadVersion: 0,
        selectedCollectionsLoadVersion: 0,
        movements: [],
        documents: [],
        reopeningRequests: [],
        accessGrants: [],
        authorizationMatrix: null,
        observerScopes: {},
        officeLeadershipDirectory: null,
        officeAccessSetting: null,
        internalAssignment: null,
        attention: null,
        users: [],
        administrationOffices: [],
        selectedOffice: null,
        officeMemberships: [],
        legislatures: [],
        legislativeComposition: { members: [], bodies: [] },
        legislativeDirectory: { legislature: null, members: [], bodies: [] },
        plenaryReferrals: [],
        plenaryPagination: null,
        operationalResetSummary: null,
        loading: false,
        busy: {},
        error: null,
    }),

    actions: {
        async run(key, operation) {
            // busy es un mapa por operación: permite bloquear un botón sin congelar todo el workspace.
            this.busy = { ...this.busy, [key]: true };
            this.error = null;

            try {
                return await operation();
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.busy = { ...this.busy, [key]: false };
            }
        },

        async loadCatalogs() {
            return this.run('catalogs', async () => {
                this.catalogLoadError = null;
                const results = await Promise.allSettled([
                    getAll('/document-types'),
                    getAll('/confidentiality-levels'),
                    getAll('/offices/directory'),
                ]);
                const keys = ['documentTypes', 'confidentialityLevels', 'offices'];
                const failures = [];

                results.forEach((result, index) => {
                    if (result.status === 'fulfilled') {
                        this.catalogs = { ...this.catalogs, [keys[index]]: result.value };
                    } else {
                        failures.push(result.reason);
                    }
                });

                if (failures.length) {
                    this.catalogsLoaded = false;
                    this.catalogLoadError = failures[0]?.message || 'No fue posible cargar los catálogos de gestión documental.';
                    throw failures[0];
                }

                this.catalogsLoaded = true;
                this.catalogsLoadedAt = new Date().toISOString();
            });
        },

        async loadExpedients(page = 1, search = this.expedientSearch, scope = this.expedientScope, { silent = false } = {}) {
            const requestVersion = this.expedientLoadVersion + 1;
            this.expedientLoadVersion = requestVersion;
            if (!silent) {
                this.loading = true;
                this.error = null;
            }

            try {
                const query = new URLSearchParams({ page: String(page), scope });
                if (search?.trim()) query.set('search', search.trim());
                const payload = await request(`/expedients?${query}`);
                if (requestVersion !== this.expedientLoadVersion) return;
                this.expedients = list(payload);
                this.pagination = payload.meta ?? null;
                this.inboxNewCount = Number(payload.inbox?.new_count ?? 0);
                this.expedientSearch = search;
                this.expedientScope = scope;
                this.expedientsLoaded = true;
                this.lastExpedientSyncAt = new Date().toISOString();
            } catch (error) {
                if (requestVersion === this.expedientLoadVersion && !silent) this.error = error.message;
                throw error;
            } finally {
                if (requestVersion === this.expedientLoadVersion) this.loading = false;
            }
        },

        async selectExpedient(expedient) {
            const id = typeof expedient === 'object' ? expedient.id : expedient;
            const selectionVersion = this.selectedLoadVersion + 1;
            this.selectedLoadVersion = selectionVersion;
            this.busy = { ...this.busy, detail: true };
            this.error = null;

            try {
                const payload = await request(`/expedients/${id}`);
                if (selectionVersion !== this.selectedLoadVersion) return null;

                this.selected = payload.data;
                this.movements = [];
                this.documents = [];
                this.reopeningRequests = [];
                this.accessGrants = [];
                this.internalAssignment = null;
                await this.loadSelectedCollections(id, selectionVersion);

                if (selectionVersion !== this.selectedLoadVersion) return null;
                await request(`/expedients/${id}/read`, { method: 'POST' });
                if (selectionVersion !== this.selectedLoadVersion) return null;
                await this.loadAttention(id, selectionVersion);
                if (selectionVersion !== this.selectedLoadVersion) return null;
                await this.loadExpedients(
                    this.pagination?.current_page ?? 1,
                    this.expedientSearch,
                    this.expedientScope,
                    { silent: true },
                );
                return this.selected;
            } catch (error) {
                if (selectionVersion === this.selectedLoadVersion) this.error = error.message;
                throw error;
            } finally {
                if (selectionVersion === this.selectedLoadVersion) {
                    this.busy = { ...this.busy, detail: false };
                }
            }
        },

        async loadSelectedCollections(expedientId = this.selected?.id, selectionVersion = this.selectedLoadVersion) {
            if (!expedientId) return;

            const id = Number(expedientId);
            const collectionVersion = this.selectedCollectionsLoadVersion + 1;
            this.selectedCollectionsLoadVersion = collectionVersion;
            const lifecyclePermissions = this.selected?.permissions ?? {};
            const canLoadReopeningHistory = lifecyclePermissions.view_lifecycle
                || lifecyclePermissions.request_reopening
                || lifecyclePermissions.approve_reopening;
            const [movements, documents, reopeningRequests] = await Promise.all([
                request(`/expedients/${id}/movements`),
                request(`/expedients/${id}/documents`),
                canLoadReopeningHistory
                    ? request(`/expedients/${id}/reopening-requests`)
                    : Promise.resolve({ data: [] }),
            ]);

            if (
                collectionVersion !== this.selectedCollectionsLoadVersion
                || selectionVersion !== this.selectedLoadVersion
                || Number(this.selected?.id) !== id
            ) return;

            this.movements = list(movements);
            this.documents = list(documents);
            this.reopeningRequests = list(reopeningRequests);
        },

        async refreshSelected() {
            if (!this.selected) return;

            return this.selectExpedient(this.selected.id);
        },

        async refreshSelectedAndInbox() {
            // selectExpedient ya sincroniza la bandeja tras cargar detalle, lectura
            // y control. Evitar una segunda consulta concurrente elimina carreras
            // donde una respuesta anterior podía reemplazar datos más recientes.
            await this.refreshSelected();
        },

        clearSelected() {
            this.selectedLoadVersion += 1;
            this.selectedCollectionsLoadVersion += 1;
            this.selected = null;
            this.movements = [];
            this.documents = [];
            this.reopeningRequests = [];
            this.accessGrants = [];
            this.internalAssignment = null;
            this.attention = null;
        },

        async loadAttention(expedientId = this.selected?.id, selectionVersion = this.selectedLoadVersion) {
            if (!expedientId) return null;

            const payload = await request(`/expedients/${expedientId}/attention`);
            if (selectionVersion !== this.selectedLoadVersion || Number(this.selected?.id) !== Number(expedientId)) return null;
            this.attention = payload.data ?? null;
            return this.attention;
        },

        async claimAttention(recipientId) {
            return this.run(`attention-claim-${recipientId}`, async () => {
                await request(`/expedients/${this.selected.id}/movement-recipients/${recipientId}/claim`, { method: 'POST' });
                await this.refreshSelectedAndInbox();
            });
        },

        async requestAttentionAssignment(recipientId, justification) {
            return this.run(`attention-request-${recipientId}`, async () => {
                await request(`/expedients/${this.selected.id}/movement-recipients/${recipientId}/assignment-requests`, {
                    method: 'POST',
                    body: { justification: justification || null },
                });
                await this.refreshSelectedAndInbox();
            });
        },

        async decideAttentionAssignment(recipientId, requestId, decision, decisionNote) {
            return this.run(`attention-decision-${requestId}`, async () => {
                await request(`/expedients/${this.selected.id}/movement-recipients/${recipientId}/assignment-requests/${requestId}/decision`, {
                    method: 'POST',
                    body: { decision, decision_note: decisionNote || null },
                });
                await this.refreshSelectedAndInbox();
            });
        },

        async transferAttention(recipientId, newResponsibleUserId, note) {
            return this.run(`attention-transfer-${recipientId}`, async () => {
                await request(`/expedients/${this.selected.id}/movement-recipients/${recipientId}/transfer`, {
                    method: 'POST',
                    body: { new_responsible_user_id: Number(newResponsibleUserId), note: note || null },
                });
                await this.refreshSelectedAndInbox();
            });
        },

        async createExpedient(data) {
            return this.run('create-expedient', async () => {
                const payload = await request('/expedients', { method: 'POST', body: data });
                await this.loadExpedients();
                await this.selectExpedient(payload.data.id);
                return payload.data;
            });
        },

        async createDocumentedExpedient(data, files) {
            return this.run('create-entry', async () => {
                const body = new FormData();

                Object.entries(data).forEach(([key, value]) => appendFormValue(body, key, value));
                Array.from(files).forEach((file) => body.append('attachments[]', file));

                const payload = await request('/expedient-entries', { method: 'POST', body });
                await this.loadExpedients();
                await this.selectExpedient(payload.data.id);
                return payload.data;
            });
        },

        async createMovement(data) {
            return this.run('movement', async () => {
                await request(`/expedients/${this.selected.id}/movements`, { method: 'POST', body: data });
                await this.refreshSelectedAndInbox();
            });
        },

        async updateRecipientStatus(recipientId, data) {
            return this.run(`recipient-${recipientId}`, async () => {
                await request(`/expedients/${this.selected.id}/movement-recipients/${recipientId}/status`, {
                    method: 'POST',
                    body: data,
                });
                await this.refreshSelectedAndInbox();
            });
        },

        async createDocument(data, files = []) {
            return this.run('document', async () => {
                const uploadList = Array.from(files);
                let body = data;

                if (uploadList.length) {
                    body = new FormData();
                    Object.entries(data).forEach(([key, value]) => appendFormValue(body, key, value));
                    uploadList.forEach((file) => body.append('attachments[]', file));
                }

                const payload = await request(`/expedients/${this.selected.id}/documents`, { method: 'POST', body });
                await this.refreshSelectedAndInbox();
                return payload.data;
            });
        },

        async updateDocument(documentId, data) {
            return this.run(`document-${documentId}`, async () => {
                await request(`/expedients/${this.selected.id}/documents/${documentId}`, { method: 'PATCH', body: data });
                await this.refreshSelected();
            });
        },

        async issueDocument(documentId) {
            return this.run(`issue-${documentId}`, async () => {
                await request(`/expedients/${this.selected.id}/documents/${documentId}/issue`, { method: 'POST' });
                await this.refreshSelected();
            });
        },

        async issueAndDeriveDocument(documentId, data) {
            return this.run(`issue-and-derive-${documentId}`, async () => {
                await request(`/expedients/${this.selected.id}/documents/${documentId}/issue-and-derive`, {
                    method: 'POST',
                    body: data,
                });
                await this.refreshSelectedAndInbox();
            });
        },

        async createCorrection(documentId, data) {
            return this.run(`correction-${documentId}`, async () => {
                await request(`/expedients/${this.selected.id}/documents/${documentId}/corrections`, { method: 'POST', body: data });
                await this.refreshSelected();
            });
        },

        async attachFile(documentId, file) {
            return this.attachFiles(documentId, [file]);
        },

        async attachFiles(documentId, files) {
            const uploadList = Array.from(files);

            return this.run(`attachment-${documentId}`, async () => {
                for (const file of uploadList) {
                    const fileBody = new FormData();
                    fileBody.append('file', file);
                    await request(`/expedients/${this.selected.id}/documents/${documentId}/attachments`, { method: 'POST', body: fileBody });
                }
                await this.refreshSelected();
            });
        },

        async downloadAttachment(documentId, attachment) {
            return this.run(`download-${attachment.id}`, async () => {
                await download(
                    `/expedients/${this.selected.id}/documents/${documentId}/attachments/${attachment.id}/download`,
                    attachment.original_name,
                );
            });
        },

        async linkDocument(documentId, movementId) {
            return this.run(`link-${documentId}`, async () => {
                await request(`/expedients/${this.selected.id}/documents/${documentId}/movement-links`, {
                    method: 'POST',
                    body: { movement_id: movementId },
                });
                await this.refreshSelected();
            });
        },

        async lifecycle(action, reason) {
            return this.run(`lifecycle-${action}`, async () => {
                const payload = await request(`/expedients/${this.selected.id}/${action}`, {
                    method: 'POST',
                    body: { reason },
                });
                await this.refreshSelectedAndInbox();
                return payload.data;
            });
        },

        async requestReopening(reason) {
            return this.run('reopening-request', async () => {
                await request(`/expedients/${this.selected.id}/reopening-requests`, { method: 'POST', body: { reason } });
                await this.loadSelectedCollections();
            });
        },

        async decideReopening(requestId, decision, decisionNote) {
            return this.run(`reopening-${requestId}`, async () => {
                await request(`/expedients/${this.selected.id}/reopening-requests/${requestId}/${decision}`, {
                    method: 'POST',
                    body: { decision_note: decisionNote || null },
                });
                await this.refreshSelectedAndInbox();
            });
        },

        async loadAccessGrants() {
            return this.run('access', async () => {
                const payload = await request(`/expedients/${this.selected.id}/access-grants`);
                this.accessGrants = list(payload);
            });
        },

        async loadUsers(force = false) {
            if (this.users.length && !force) return;

            return this.run('users', async () => {
                this.users = await getAll('/users');
            });
        },

        async resetUserPassword(userId) {
            return this.run(`user-password-reset-${userId}`, async () => {
                const payload = await request(`/users/${userId}/emergency-password-reset`, { method: 'POST' });
                await this.loadUsers(true);
                return {
                    user: payload.data,
                    temporaryPassword: payload.temporary_password,
                };
            });
        },

        async assignUserRole(userId, role) {
            return this.run(`user-role-${userId}-${role}`, async () => {
                await request(`/users/${userId}/roles`, { method: 'POST', body: { role } });
                await this.loadUsers(true);
            });
        },

        async removeUserRole(userId, role) {
            return this.run(`user-role-${userId}-${role}`, async () => {
                await request(`/users/${userId}/roles/${role}`, { method: 'DELETE' });
                await this.loadUsers(true);
                if (role === 'observer') {
                    const scopes = { ...this.observerScopes };
                    delete scopes[userId];
                    this.observerScopes = scopes;
                }
            });
        },

        async loadAuthorizationMatrix(force = false) {
            if (this.authorizationMatrix && !force) return this.authorizationMatrix;

            return this.run('authorization-matrix', async () => {
                const payload = await request('/authorization/roles');
                this.authorizationMatrix = payload.data;
                return this.authorizationMatrix;
            });
        },

        async saveRolePermissions(roleId, permissions) {
            return this.run(`role-permissions-${roleId}`, async () => {
                await request(`/authorization/roles/${roleId}/permissions`, {
                    method: 'PUT',
                    body: { permissions },
                });
                return this.loadAuthorizationMatrix(true);
            });
        },

        async loadObserverScope(userId) {
            return this.run(`observer-scope-${userId}`, async () => {
                const payload = await request(`/users/${userId}/observer-office-scope`);
                this.observerScopes = { ...this.observerScopes, [userId]: payload.data };
                return payload.data;
            });
        },

        async saveObserverScope(userId, officeIds) {
            return this.run(`observer-scope-${userId}`, async () => {
                const payload = await request(`/users/${userId}/observer-office-scope`, {
                    method: 'PUT',
                    body: { office_ids: officeIds },
                });
                this.observerScopes = { ...this.observerScopes, [userId]: payload.data };
                return payload.data;
            });
        },

        async loadOfficeAccessSetting(officeId) {
            return this.run(`office-access-${officeId}`, async () => {
                const payload = await request(`/document-management/offices/${officeId}/access-setting`);
                this.officeAccessSetting = payload.data;
                return payload.data;
            });
        },

        async loadOfficeLeadershipDirectory(force = false) {
            if (this.officeLeadershipDirectory && !force) return this.officeLeadershipDirectory;

            return this.run('office-leadership-directory', async () => {
                const payload = await request('/document-management/office-responsibilities');
                this.officeLeadershipDirectory = payload.data;
                return payload.data;
            });
        },

        async saveOfficeAccessSetting(officeId, data) {
            return this.run(`office-access-${officeId}`, async () => {
                const payload = await request(`/document-management/offices/${officeId}/access-setting`, {
                    method: 'PUT',
                    body: data,
                });
                this.officeAccessSetting = payload.data;
                return payload.data;
            });
        },

        async createOfficeManagerDelegation(officeId, data) {
            return this.run(`office-delegation-${officeId}`, async () => {
                const payload = await request(`/document-management/offices/${officeId}/manager-delegations`, {
                    method: 'POST',
                    body: data,
                });
                this.officeAccessSetting = payload.data;
                return payload.data;
            });
        },

        async endOfficeManagerDelegation(officeId, delegationId, effectiveTo) {
            return this.run(`office-delegation-end-${delegationId}`, async () => {
                const payload = await request(`/document-management/offices/${officeId}/manager-delegations/${delegationId}/end`, {
                    method: 'POST',
                    body: { effective_to: effectiveTo },
                });
                this.officeAccessSetting = payload.data;
                return payload.data;
            });
        },

        async createOfficeLegislativeDelegation(officeId, data) {
            return this.run(`office-legislative-delegation-${officeId}`, async () => {
                const payload = await request(`/document-management/offices/${officeId}/legislative-delegations`, {
                    method: 'POST',
                    body: data,
                });
                this.officeAccessSetting = payload.data;
                return payload.data;
            });
        },

        async loadInternalAssignment(recipientId) {
            return this.run(`internal-assignment-${recipientId}`, async () => {
                const payload = await request(`/expedients/${this.selected.id}/movement-recipients/${recipientId}/internal-assignments`);
                this.internalAssignment = payload.data;
                return payload.data;
            });
        },

        async saveInternalAssignment(recipientId, data) {
            return this.run(`internal-assignment-${recipientId}`, async () => {
                const payload = await request(`/expedients/${this.selected.id}/movement-recipients/${recipientId}/internal-assignments`, {
                    method: 'PUT',
                    body: data,
                });
                this.internalAssignment = payload.data;
                await this.refreshSelectedAndInbox();
                return payload.data;
            });
        },

        async grantAccess(data) {
            return this.run('grant-access', async () => {
                await request(`/expedients/${this.selected.id}/access-grants`, { method: 'POST', body: data });
                await this.loadAccessGrants();
            });
        },

        async closeAccessGrant(grantId) {
            return this.run(`close-grant-${grantId}`, async () => {
                await request(`/expedients/${this.selected.id}/access-grants/${grantId}/close`, { method: 'POST' });
                await this.loadAccessGrants();
            });
        },

        async loadOrganizationAdministration() {
            return this.run('organization', async () => {
                this.administrationOffices = await getAll('/offices');
            });
        },

        async selectOffice(office) {
            this.selectedOffice = office;
            return this.loadOfficeMemberships(office.id);
        },

        async loadOfficeMemberships(officeId) {
            return this.run('office-memberships', async () => {
                this.officeMemberships = await getAll(`/offices/${officeId}/memberships`);
            });
        },

        async createOffice(data) {
            return this.run('office-save', async () => {
                await request('/offices', { method: 'POST', body: data });
                await Promise.all([this.loadOrganizationAdministration(), this.loadCatalogs()]);
            });
        },

        async updateOffice(officeId, data) {
            return this.run('office-save', async () => {
                await request(`/offices/${officeId}`, { method: 'PATCH', body: data });
                await Promise.all([this.loadOrganizationAdministration(), this.loadCatalogs()]);
                this.selectedOffice = this.administrationOffices.find((office) => office.id === officeId) ?? null;
                if (this.selectedOffice) await this.loadOfficeMemberships(officeId);
            });
        },

        async changeOfficeStatus(office, action) {
            return this.run(`office-${office.id}`, async () => {
                await request(`/offices/${office.id}/${action}`, { method: 'POST' });
                await Promise.all([this.loadOrganizationAdministration(), this.loadCatalogs()]);
                this.selectedOffice = this.administrationOffices.find((item) => item.id === office.id) ?? null;
            });
        },

        async assignOfficeMembership(officeId, data) {
            return this.run('membership-save', async () => {
                await request(`/offices/${officeId}/memberships`, { method: 'POST', body: data });
                await Promise.all([this.loadOrganizationAdministration(), this.loadOfficeMemberships(officeId)]);
            });
        },

        async closeOfficeMembership(officeId, membershipId) {
            return this.run(`membership-${membershipId}`, async () => {
                await request(`/offices/${officeId}/memberships/${membershipId}/close`, { method: 'POST' });
                await Promise.all([this.loadOrganizationAdministration(), this.loadOfficeMemberships(officeId)]);
            });
        },

        async loadLegislatures() {
            return this.run('legislatures', async () => {
                this.legislatures = await getAll('/legislatures');
            });
        },

        async saveLegislature(data, legislatureId = null) {
            return this.run('legislature-save', async () => {
                if (legislatureId) {
                    await request(`/legislatures/${legislatureId}`, { method: 'PATCH', body: data });
                } else {
                    await request('/legislatures', { method: 'POST', body: data });
                }

                await this.loadLegislatures();
            });
        },

        async changeLegislatureStatus(legislature, action) {
            return this.run(`legislature-${legislature.id}`, async () => {
                await request(`/legislatures/${legislature.id}/${action}`, { method: 'POST' });
                await this.loadLegislatures();
            });
        },

        async replaceBoardMember(legislatureId, data) {
            return this.run('board-assignment', async () => {
                await request(`/legislatures/${legislatureId}/board-assignments`, { method: 'POST', body: data });
                await this.loadLegislatures();
            });
        },

        async loadLegislativeComposition(legislatureId) {
            return this.run('legislative-composition', async () => {
                const payload = await request(`/legislatures/${legislatureId}/composition`);
                this.legislativeComposition = {
                    members: payload.members?.data ?? payload.members ?? [],
                    bodies: payload.bodies?.data ?? payload.bodies ?? [],
                };
                return this.legislativeComposition;
            });
        },

        async assignLegislatureMember(legislatureId, data) {
            return this.run('legislature-member-save', async () => {
                await request(`/legislatures/${legislatureId}/members`, { method: 'POST', body: data });
                return this.loadLegislativeComposition(legislatureId);
            });
        },

        async closeLegislatureMember(legislatureId, memberId, effectiveTo) {
            return this.run(`legislature-member-${memberId}`, async () => {
                await request(`/legislatures/${legislatureId}/members/${memberId}/close`, {
                    method: 'POST',
                    body: { effective_to: effectiveTo },
                });
                return this.loadLegislativeComposition(legislatureId);
            });
        },

        async createLegislativeBody(legislatureId, data) {
            return this.run('legislative-body-save', async () => {
                await request(`/legislatures/${legislatureId}/legislative-bodies`, { method: 'POST', body: data });
                return this.loadLegislativeComposition(legislatureId);
            });
        },

        async assignLegislativeBodyMembership(legislatureId, bodyId, data) {
            return this.run(`legislative-body-${bodyId}-membership`, async () => {
                await request(`/legislatures/${legislatureId}/legislative-bodies/${bodyId}/memberships`, { method: 'POST', body: data });
                return this.loadLegislativeComposition(legislatureId);
            });
        },

        async closeLegislativeBodyMembership(legislatureId, bodyId, membershipId, effectiveTo) {
            return this.run(`legislative-membership-${membershipId}`, async () => {
                await request(`/legislatures/${legislatureId}/legislative-bodies/${bodyId}/memberships/${membershipId}/close`, {
                    method: 'POST',
                    body: { effective_to: effectiveTo },
                });
                return this.loadLegislativeComposition(legislatureId);
            });
        },

        async createLegislativeDelegation(legislatureId, bodyId, data) {
            return this.run(`legislative-delegation-${bodyId}`, async () => {
                await request(`/legislatures/${legislatureId}/legislative-bodies/${bodyId}/delegations`, { method: 'POST', body: data });
                return this.loadLegislativeComposition(legislatureId);
            });
        },

        async loadLegislativeDirectory(force = false) {
            if (this.legislativeDirectory.legislature && !force) return this.legislativeDirectory;
            return this.run('legislative-directory', async () => {
                const payload = await request('/legislative/directory');
                this.legislativeDirectory = payload.data;
                return this.legislativeDirectory;
            });
        },

        async loadPlenaryReferrals(page = 1) {
            return this.run('plenary-referrals', async () => {
                const payload = await request(`/legislative/plenary-referrals?page=${page}`);
                this.plenaryReferrals = list(payload);
                this.plenaryPagination = payload.meta ?? null;
                return this.plenaryReferrals;
            });
        },

        async referSelectedToPlenary() {
            if (!this.selected) return null;
            return this.run('plenary-referral-save', async () => {
                const payload = await request(`/expedients/${this.selected.id}/plenary-referrals`, { method: 'POST' });
                await Promise.all([this.refreshSelected(), this.loadPlenaryReferrals()]);
                return payload.data;
            });
        },

        async loadOperationalResetSummary() {
            return this.run('operational-reset-summary', async () => {
                const payload = await request('/administration/operational-reset/summary');
                this.operationalResetSummary = payload.data;
                return this.operationalResetSummary;
            });
        },

        async performOperationalReset(confirmation) {
            return this.run('operational-reset', async () => {
                const payload = await request('/administration/operational-reset', {
                    method: 'POST',
                    body: { confirmation },
                });
                this.operationalResetSummary = payload.data;
                this.expedients = [];
                this.pagination = null;
                this.expedientsLoaded = true;
                this.lastExpedientSyncAt = new Date().toISOString();
                this.clearSelected();
                this.legislatures = [];
                return payload.data;
            });
        },
    },
});
