import { defineStore } from 'pinia';
import { download, request } from '../lib/api';

/** Estado operativo de Almacenes; cada mutación refresca sus proyecciones para evitar datos obsoletos. */
export const useWarehouseStore = defineStore('warehouse', {
    state: () => ({
        measurementUnits: [],
        categories: [],
        items: [],
        materialRequests: [],
        receipts: [],
        selectedRequest: null,
        permissions: { manage_catalog: false, operate: false },
        pagination: null,
        loading: false,
        error: null,
    }),

    actions: {
        async capture(operation) {
            this.error = null;
            try {
                return await operation();
            } catch (error) {
                this.error = error?.message || 'No fue posible completar la operación de Almacenes.';
                throw error;
            }
        },

        async loadBootstrap() {
            return this.capture(async () => {
                const payload = await request('/warehouse/bootstrap');
                this.measurementUnits = payload.measurement_units ?? [];
                this.categories = payload.categories ?? [];
                this.items = payload.items ?? [];
                this.permissions = payload.permissions ?? this.permissions;
            });
        },

        async loadRequests({ scope = 'active', search = '', page = 1 } = {}) {
            this.loading = true;
            this.error = null;
            try {
                const query = new URLSearchParams({ scope, search, page: String(page) });
                const payload = await request(`/warehouse/material-requests?${query}`);
                this.materialRequests = payload.data ?? [];
                this.pagination = payload.meta ?? null;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async selectRequest(id) {
            return this.capture(async () => {
                const payload = await request(`/warehouse/material-requests/${id}`);
                this.selectedRequest = payload.data;
                this.replaceRequest(payload.data);
                return payload.data;
            });
        },

        async createRequest(data) {
            return this.capture(async () => {
                const payload = await request('/warehouse/material-requests', { method: 'POST', body: data });
                this.selectedRequest = payload.data;
                this.replaceRequest(payload.data);
                return payload.data;
            });
        },

        async updateRequest(id, data) {
            return this.capture(async () => {
                const payload = await request(`/warehouse/material-requests/${id}`, { method: 'PATCH', body: data });
                this.selectedRequest = payload.data;
                this.replaceRequest(payload.data);
                return payload.data;
            });
        },

        async submitRequest(id) { return this.mutateRequest(id, 'submit', {}); },
        async decideRequest(id, data) { return this.mutateRequest(id, 'decisions', data); },
        async reviseRequest(id, data) { return this.mutateRequest(id, 'revisions', data); },
        async decideDelivery(id, data) { return this.mutateRequest(id, 'delivery', data); },
        async confirmReceipt(id, observations = null) { return this.mutateRequest(id, 'confirm-receipt', { observations }); },

        async authorizeReceiver(id, data) {
            return this.mutateRequest(id, 'receiver-authorization', data);
        },

        async eligibleReceivers(id) {
            return this.capture(async () => {
                const payload = await request(`/warehouse/material-requests/${id}/eligible-receivers`);
                return payload.data ?? [];
            });
        },

        async createCategory(data) {
            return this.capture(async () => {
                const payload = await request('/warehouse/categories', { method: 'POST', body: data });
                await this.loadBootstrap();
                return payload.data;
            });
        },

        async updateCategory(id, data) {
            return this.capture(async () => {
                const payload = await request(`/warehouse/categories/${id}`, { method: 'PATCH', body: data });
                await this.loadBootstrap();
                return payload.data;
            });
        },

        async createItem(data) {
            return this.capture(async () => {
                const payload = await request('/warehouse/items', { method: 'POST', body: data });
                await this.loadBootstrap();
                return payload.data;
            });
        },

        async updateItem(id, data) {
            return this.capture(async () => {
                const payload = await request(`/warehouse/items/${id}`, { method: 'PATCH', body: data });
                await this.loadBootstrap();
                return payload.data;
            });
        },

        async adjustStock(id, data) {
            return this.capture(async () => {
                const payload = await request(`/warehouse/items/${id}/adjustments`, { method: 'POST', body: data });
                await this.loadBootstrap();
                return payload.data;
            });
        },

        async loadStockMovements(id) {
            return this.capture(async () => (await request(`/warehouse/items/${id}/movements`)).data ?? []);
        },

        async loadReceipts() {
            return this.capture(async () => {
                const payload = await request('/warehouse/receipts');
                this.receipts = payload.data ?? [];
            });
        },

        async createReceipt(formData) {
            return this.capture(async () => {
                const payload = await request('/warehouse/receipts', { method: 'POST', body: formData });
                await Promise.all([this.loadBootstrap(), this.loadReceipts()]);
                return payload.data;
            });
        },

        async downloadReceiptAttachment(receiptId, attachment) {
            return download(`/warehouse/receipts/${receiptId}/attachments/${attachment.id}/download`, attachment.original_name);
        },

        async mutateRequest(id, action, data) {
            return this.capture(async () => {
                const payload = await request(`/warehouse/material-requests/${id}/${action}`, { method: 'POST', body: data });
                this.selectedRequest = payload.data;
                this.replaceRequest(payload.data);
                await this.loadBootstrap();
                return payload.data;
            });
        },

        replaceRequest(materialRequest) {
            const index = this.materialRequests.findIndex((entry) => entry.id === materialRequest.id);
            if (index >= 0) this.materialRequests.splice(index, 1, materialRequest);
            else this.materialRequests.unshift(materialRequest);
        },
    },
});
