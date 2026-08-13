import { defineStore } from 'pinia';
import { download, getAll, request } from '../lib/api';

function list(payload) {
    return payload?.data ?? [];
}

export const useHumanResourcesStore = defineStore('humanResources', {
    state: () => ({
        bootstrap: { offices: [], roles: [] },
        employees: [],
        pagination: null,
        selected: null,
        positionsByOffice: {},
        loading: false,
        busy: {},
        error: null,
    }),

    actions: {
        async run(key, operation) {
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

        async loadBootstrap() {
            return this.run('bootstrap', async () => {
                const payload = await request('/human-resources/bootstrap');
                this.bootstrap = payload.data;
                return this.bootstrap;
            });
        },

        async loadEmployees({ page = 1, search = '' } = {}) {
            this.loading = true;
            this.error = null;

            try {
                const query = new URLSearchParams({ page: String(page) });
                if (search) query.set('search', search);
                const payload = await request(`/human-resources/employees?${query}`);
                this.employees = list(payload);
                this.pagination = payload.meta ?? null;
                return this.employees;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async lookupByIdentity(identityCard) {
            if (!identityCard?.trim()) return null;

            const payload = await request(`/human-resources/employees?${new URLSearchParams({ search: identityCard.trim() })}`);
            return list(payload).find((employee) => employee.identity_card.toLowerCase() === identityCard.trim().toLowerCase()) ?? null;
        },

        async selectEmployee(employee) {
            const id = typeof employee === 'object' ? employee.id : employee;

            return this.run('employee-detail', async () => {
                const payload = await request(`/human-resources/employees/${id}`);
                this.selected = payload.data;
                return this.selected;
            });
        },

        async loadPositions(officeId) {
            if (!officeId) return [];

            return this.run(`positions-${officeId}`, async () => {
                const positions = await getAll(`/human-resources/offices/${officeId}/positions`);
                this.positionsByOffice = { ...this.positionsByOffice, [officeId]: positions };
                return positions;
            });
        },

        async createPosition(data) {
            return this.run('create-position', async () => {
                const payload = await request('/human-resources/positions', { method: 'POST', body: data });
                const position = payload.data;
                const positions = this.positionsByOffice[position.office_id] ?? [];
                this.positionsByOffice = {
                    ...this.positionsByOffice,
                    [position.office_id]: [...positions, position].sort((a, b) => a.name.localeCompare(b.name, 'es')),
                };
                return position;
            });
        },

        async updatePosition(positionId, data) {
            return this.run('update-position', async () => {
                const payload = await request(`/human-resources/positions/${positionId}`, { method: 'PATCH', body: data });
                const position = payload.data;
                const positions = this.positionsByOffice[position.office_id] ?? [];
                this.positionsByOffice = {
                    ...this.positionsByOffice,
                    [position.office_id]: positions
                        .map((item) => item.id === position.id ? position : item)
                        .sort((left, right) => left.name.localeCompare(right.name, 'es')),
                };
                return position;
            });
        },

        async registerEmployee(formData) {
            return this.run('register-employee', async () => {
                const payload = await request('/human-resources/employees', { method: 'POST', body: formData });
                await this.loadEmployees();
                this.selected = payload.data;
                return payload;
            });
        },

        async downloadImportTemplate() {
            return this.run('employee-import-template', async () => {
                await download('/human-resources/employee-import-template', 'plantilla-importacion-funcionarios-sigal.xlsx');
            });
        },

        async importEmployees(file) {
            return this.run('employee-import', async () => {
                const body = new FormData();
                body.append('file', file);
                const payload = await request('/human-resources/employees/import', { method: 'POST', body });
                await this.loadEmployees();
                return payload.data;
            });
        },

        async uploadProfilePhoto(employeeId, file) {
            return this.run(`profile-photo-${employeeId}`, async () => {
                const body = new FormData();
                body.append('profile_photo', file);
                await request(`/human-resources/employees/${employeeId}/profile-photo`, { method: 'POST', body });
                await this.selectEmployee(employeeId);
                await this.loadEmployees({ page: this.pagination?.current_page ?? 1 });
            });
        },

        async updateEmployee(employeeId, data) {
            return this.run(`update-employee-${employeeId}`, async () => {
                const payload = await request(`/human-resources/employees/${employeeId}`, { method: 'PATCH', body: data });
                this.selected = payload.data;
                await this.loadEmployees({ page: this.pagination?.current_page ?? 1 });
                return payload.data;
            });
        },

        async uploadAttachment(employeeId, documentType, file) {
            return this.run(`employee-attachment-${employeeId}`, async () => {
                const body = new FormData();
                body.append('document_type', documentType);
                body.append('attachment', file);
                const payload = await request(`/human-resources/employees/${employeeId}/attachments`, { method: 'POST', body });
                this.selected = payload.data;
                return payload.data;
            });
        },

        async extendContract(contractId, endsOn) {
            return this.run(`extend-contract-${contractId}`, async () => {
                await request(`/human-resources/contracts/${contractId}/extend`, { method: 'POST', body: { ends_on: endsOn } });
                if (this.selected) await this.selectEmployee(this.selected.id);
                await this.loadEmployees({ page: this.pagination?.current_page ?? 1 });
            });
        },

        async finishContract(contractId) {
            return this.run(`finish-contract-${contractId}`, async () => {
                await request(`/human-resources/contracts/${contractId}/finish`, { method: 'POST' });
                if (this.selected) await this.selectEmployee(this.selected.id);
                await this.loadEmployees({ page: this.pagination?.current_page ?? 1 });
            });
        },

        async downloadAttachment(attachment) {
            return this.run(`attachment-${attachment.id}`, async () => {
                await download(attachment.download_url.replace('/api', ''), attachment.original_name);
            });
        },
    },
});
