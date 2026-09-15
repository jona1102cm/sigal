<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import SearchableSelect from './SearchableSelect.vue';
import { useWarehouseStore } from '../stores/warehouse';

const props = defineProps({ session: { type: Object, required: true } });
const warehouse = useWarehouseStore();
const tab = ref('requests');
const requestScope = ref('active');
const search = ref('');
const requestModalOpen = ref(false);
const requestFormTargetId = ref(null);
const detailOpen = ref(false);
const categoryModalOpen = ref(false);
const itemModalOpen = ref(false);
const categoryEditingId = ref(null);
const itemEditingId = ref(null);
const stockDetailOpen = ref(false);
const stockDetailItem = ref(null);
const stockMovements = ref([]);
const adjustmentForm = reactive({ quantity_delta: '', reason: '' });
const receiptDetail = ref(null);
const receiptModalOpen = ref(false);
const actionNotes = ref('');
const receiverOptions = ref([]);
const receiverForm = reactive({ receiver_user_id: null, reason: '' });
const confirmationObservations = ref('');
const notAttendedReason = ref('');
const requestForm = reactive(newRequestForm());
const categoryForm = reactive({ code: '', name: '', description: '', parent_id: null, status: 'active' });
const itemForm = reactive({ code: '', name: '', description: '', warehouse_category_id: null, measurement_unit_id: null, minimum_stock: 0, physical_location: '', status: 'active' });
const receiptForm = reactive(newReceiptForm());
const receiptFiles = ref([]);
const deliveryLines = reactive({});
let searchTimer = null;

const officeOptions = computed(() => (props.session.user?.office_memberships ?? []).map((membership) => ({
    value: membership.office_id,
    label: `${membership.office?.code ?? ''} — ${membership.office?.name ?? 'Oficina'}`,
})));
const unitOptions = computed(() => warehouse.measurementUnits.filter((unit) => unit.status === 'active').map((unit) => ({ value: unit.id, label: `${unit.name} (${unit.symbol})` })));
const categoryOptions = computed(() => warehouse.categories.filter((category) => category.status === 'active').map((category) => ({ value: category.id, label: category.name })));
const categoryParentOptions = computed(() => [
    { value: null, label: 'Sin categoría superior' },
    ...warehouse.categories
        .filter((category) => category.id !== categoryEditingId.value)
        .map((category) => ({ value: category.id, label: `${category.name} (${category.code})` })),
]);
const itemOptions = computed(() => warehouse.items.filter((item) => item.status === 'active').map((item) => ({ value: item.id, label: `${item.name} · disponible ${quantity(item.stock_on_hand)} ${item.measurement_unit?.symbol ?? ''}` })));
const statusOptions = [{ value: 'active', label: 'Activo' }, { value: 'inactive', label: 'Inactivo' }];
const requestsNeedingAction = computed(() => warehouse.materialRequests.filter((entry) => Object.values(entry.actions ?? {}).some(Boolean)));
const selected = computed(() => warehouse.selectedRequest);

function newRequestForm() {
    return { requesting_office_id: null, justification: '', office_reference: '', items: [newRequestLine()] };
}

function newRequestLine() {
    return { mode: 'catalog', warehouse_item_id: null, measurement_unit_id: null, item_name: '', requested_quantity: 1, notes: '' };
}

function newReceiptForm() {
    return {
        supplier_name: '', supplier_tax_id: '', reference_type: 'invoice', reference_number: '',
        reference_date: today(), received_on: today(), observations: '', lines: [newReceiptLine()],
    };
}

function newReceiptLine() {
    return { warehouse_item_id: null, quantity: 1, unit_cost: 0, lot_number: '', expires_on: '', physical_location: '' };
}

function today() { return new Date().toISOString().slice(0, 10); }
function quantity(value) { return new Intl.NumberFormat('es-BO', { maximumFractionDigits: 4 }).format(Number(value ?? 0)); }
function money(value) { return new Intl.NumberFormat('es-BO', { style: 'currency', currency: 'BOB' }).format(Number(value ?? 0)); }
function dateTime(value) { return value ? new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Pendiente'; }

function resetRequestForm(materialRequest = null) {
    requestFormTargetId.value = materialRequest?.id ?? null;
    Object.assign(requestForm, newRequestForm());
    requestForm.requesting_office_id = materialRequest?.requesting_office?.id ?? (officeOptions.value.length === 1 ? officeOptions.value[0].value : null);
    requestForm.justification = materialRequest?.justification ?? '';
    requestForm.office_reference = materialRequest?.document?.office_reference ?? '';
    requestForm.items = materialRequest?.items?.map((line) => ({
        mode: line.warehouse_item_id ? 'catalog' : 'custom',
        warehouse_item_id: line.warehouse_item_id,
        measurement_unit_id: line.measurement_unit?.id,
        item_name: line.warehouse_item_id ? '' : line.item_name,
        requested_quantity: line.requested_quantity,
        notes: line.notes ?? '',
    })) ?? [newRequestLine()];
}

function selectCatalogItem(line) {
    const item = warehouse.items.find((entry) => entry.id === Number(line.warehouse_item_id));
    if (item) line.measurement_unit_id = item.measurement_unit?.id;
}

function changeLineMode(line) {
    line.warehouse_item_id = null;
    line.item_name = '';
    line.measurement_unit_id = null;
}

function requestPayload() {
    return {
        requesting_office_id: requestForm.requesting_office_id,
        justification: requestForm.justification,
        office_reference: requestForm.office_reference || null,
        items: requestForm.items.map((line) => ({
            warehouse_item_id: line.mode === 'catalog' ? line.warehouse_item_id : null,
            measurement_unit_id: line.measurement_unit_id,
            item_name: line.mode === 'custom' ? line.item_name : null,
            requested_quantity: line.requested_quantity,
            notes: line.notes || null,
        })),
    };
}

async function saveRequest(submit = false) {
    let materialRequest;
    if (requestFormTargetId.value && selected.value?.actions?.update) {
        materialRequest = await warehouse.updateRequest(requestFormTargetId.value, requestPayload());
    } else if (requestFormTargetId.value && selected.value?.actions?.revise) {
        materialRequest = await warehouse.reviseRequest(requestFormTargetId.value, requestPayload());
    } else {
        materialRequest = await warehouse.createRequest(requestPayload());
    }
    if (submit && materialRequest.actions?.submit) materialRequest = await warehouse.submitRequest(materialRequest.id);
    requestModalOpen.value = false;
    detailOpen.value = true;
    await warehouse.loadRequests({ scope: requestScope.value, search: search.value });
    warehouse.selectedRequest = materialRequest;
}

async function openRequest(entry) {
    await warehouse.selectRequest(entry.id);
    detailOpen.value = true;
    prepareDelivery();
}

function editSelected() {
    resetRequestForm(selected.value);
    requestModalOpen.value = true;
}

function openNewRequest() {
    tab.value = 'requests';
    resetRequestForm();
    requestModalOpen.value = true;
}

defineExpose({ openNewRequest });

async function decide(action) {
    await warehouse.decideRequest(selected.value.id, { action, notes: actionNotes.value || null });
    actionNotes.value = '';
    await warehouse.loadRequests({ scope: requestScope.value, search: search.value });
}

async function submitSelected() {
    await warehouse.submitRequest(selected.value.id);
    await warehouse.loadRequests({ scope: requestScope.value, search: search.value });
}

function prepareDelivery() {
    Object.keys(deliveryLines).forEach((key) => delete deliveryLines[key]);
    for (const line of selected.value?.items ?? []) {
        deliveryLines[line.id] = {
            material_request_item_id: line.id,
            warehouse_item_id: line.warehouse_item_id,
            delivered_quantity: line.warehouse_item_id ? Math.min(Number(line.requested_quantity), Number(line.warehouse_item?.stock_on_hand ?? 0)) : 0,
            over_delivery_reason: '',
        };
    }
}

function deliveryItemOptions(requestItem) {
    return warehouse.items.filter((item) => item.status === 'active' && item.measurement_unit?.id === requestItem.measurement_unit?.id)
        .map((item) => ({ value: item.id, label: `${item.name} · disponible ${quantity(item.stock_on_hand)}` }));
}

async function registerDelivery() {
    const lines = Object.values(deliveryLines).filter((line) => Number(line.delivered_quantity) > 0);
    await warehouse.decideDelivery(selected.value.id, { not_attended: false, lines });
    await warehouse.loadRequests({ scope: requestScope.value, search: search.value });
}

async function markNotAttended() {
    await warehouse.decideDelivery(selected.value.id, { not_attended: true, reason: notAttendedReason.value, lines: [] });
    notAttendedReason.value = '';
    await warehouse.loadRequests({ scope: requestScope.value, search: search.value });
}

async function loadReceivers() {
    receiverOptions.value = (await warehouse.eligibleReceivers(selected.value.id)).map((user) => ({ value: user.id, label: `${user.name} — ${user.email}` }));
}

async function authorizeReceiver() {
    await warehouse.authorizeReceiver(selected.value.id, receiverForm);
    Object.assign(receiverForm, { receiver_user_id: null, reason: '' });
}

async function confirmReceipt() {
    await warehouse.confirmReceipt(selected.value.id, confirmationObservations.value || null);
    confirmationObservations.value = '';
    await warehouse.loadRequests({ scope: requestScope.value, search: search.value });
}

async function printAct() {
    await nextTick();
    window.print();
}

async function saveCategory() {
    if (categoryEditingId.value) await warehouse.updateCategory(categoryEditingId.value, categoryForm);
    else await warehouse.createCategory(categoryForm);
    Object.assign(categoryForm, { code: '', name: '', description: '', parent_id: null, status: 'active' });
    categoryEditingId.value = null;
    categoryModalOpen.value = false;
}

async function saveItem() {
    if (itemEditingId.value) await warehouse.updateItem(itemEditingId.value, itemForm);
    else await warehouse.createItem(itemForm);
    Object.assign(itemForm, { code: '', name: '', description: '', warehouse_category_id: null, measurement_unit_id: null, minimum_stock: 0, physical_location: '', status: 'active' });
    itemEditingId.value = null;
    itemModalOpen.value = false;
}

function editCategory(category = null) {
    categoryEditingId.value = category?.id ?? null;
    Object.assign(categoryForm, category
        ? { parent_id: category.parent_id, code: category.code, name: category.name, description: category.description ?? '', status: category.status }
        : { code: '', name: '', description: '', parent_id: null, status: 'active' });
    categoryModalOpen.value = true;
}

function editItem(item = null) {
    itemEditingId.value = item?.id ?? null;
    Object.assign(itemForm, item ? {
        code: item.code, name: item.name, description: item.description ?? '', warehouse_category_id: item.category?.id,
        measurement_unit_id: item.measurement_unit?.id, minimum_stock: item.minimum_stock, physical_location: item.physical_location ?? '', status: item.status,
    } : { code: '', name: '', description: '', warehouse_category_id: null, measurement_unit_id: null, minimum_stock: 0, physical_location: '', status: 'active' });
    itemModalOpen.value = true;
}

async function openStock(item) {
    stockDetailItem.value = item;
    stockMovements.value = await warehouse.loadStockMovements(item.id);
    stockDetailOpen.value = true;
}

async function adjustStock() {
    await warehouse.adjustStock(stockDetailItem.value.id, adjustmentForm);
    stockDetailItem.value = warehouse.items.find((item) => item.id === stockDetailItem.value.id);
    stockMovements.value = await warehouse.loadStockMovements(stockDetailItem.value.id);
    Object.assign(adjustmentForm, { quantity_delta: '', reason: '' });
}

async function saveReceipt() {
    const body = new FormData();
    for (const [key, value] of Object.entries(receiptForm)) {
        if (key === 'lines') body.append('lines', JSON.stringify(value));
        else if (value !== '' && value !== null) body.append(key, value);
    }
    for (const file of receiptFiles.value) body.append('attachments[]', file);
    await warehouse.createReceipt(body);
    Object.assign(receiptForm, newReceiptForm());
    receiptFiles.value = [];
    receiptModalOpen.value = false;
}

async function switchTab(next) {
    tab.value = next;
    if (next === 'receipts') await warehouse.loadReceipts();
}

watch([requestScope, search], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => warehouse.loadRequests({ scope: requestScope.value, search: search.value }).catch(() => {}), 250);
});

onMounted(async () => {
    await Promise.all([warehouse.loadBootstrap(), warehouse.loadRequests()]);
    if (officeOptions.value.length === 1) requestForm.requesting_office_id = officeOptions.value[0].value;
});
</script>

<template>
    <section class="workspace workspace--wide warehouse-workspace">
        <header class="page-heading">
            <div><p class="eyebrow">Almacenes</p><h1>Materiales, solicitudes y existencias</h1><p class="muted">Solicitudes vinculadas a expedientes SIGAL y kardex físico auditable.</p></div>
            <button v-if="session.hasPermission('warehouse.requests.create')" class="button button--primary" type="button" @click="openNewRequest">+ Nueva solicitud</button>
        </header>
        <p v-if="warehouse.error" class="alert alert--error" role="alert">{{ warehouse.error }}</p>

        <nav class="warehouse-tabs" aria-label="Secciones de Almacenes">
            <button :class="{ 'is-active': tab === 'requests' }" type="button" @click="switchTab('requests')">Solicitudes</button>
            <button :class="{ 'is-active': tab === 'attention' }" type="button" @click="switchTab('attention')">Por atender <span>{{ requestsNeedingAction.length }}</span></button>
            <button :class="{ 'is-active': tab === 'stock' }" type="button" @click="switchTab('stock')">Existencias</button>
            <button v-if="warehouse.permissions.operate" :class="{ 'is-active': tab === 'receipts' }" type="button" @click="switchTab('receipts')">Ingresos</button>
            <button v-if="warehouse.permissions.manage_catalog" :class="{ 'is-active': tab === 'catalog' }" type="button" @click="switchTab('catalog')">Catálogo</button>
        </nav>

        <section v-if="tab === 'requests' || tab === 'attention'" class="panel warehouse-list-panel">
            <header class="warehouse-toolbar">
                <label class="search-field"><span>Buscar</span><input v-model.trim="search" type="search" placeholder="SIGAL, material o justificación"></label>
                <div v-if="tab === 'requests'" class="segmented-control"><button v-for="scope in [{ value: 'active', label: 'Activas' }, { value: 'mine', label: 'Mis solicitudes' }, { value: 'history', label: 'Historial' }]" :key="scope.value" :class="{ 'is-active': requestScope === scope.value }" type="button" @click="requestScope = scope.value">{{ scope.label }}</button></div>
                <button class="button button--ghost" type="button" @click="warehouse.loadRequests({ scope: requestScope, search })">Actualizar</button>
            </header>
            <div v-if="warehouse.loading" class="empty-state">Actualizando solicitudes...</div>
            <div v-else-if="!(tab === 'attention' ? requestsNeedingAction : warehouse.materialRequests).length" class="empty-state">No hay solicitudes en esta bandeja.</div>
            <div v-else class="warehouse-request-list">
                <button v-for="entry in (tab === 'attention' ? requestsNeedingAction : warehouse.materialRequests)" :key="entry.id" class="warehouse-request-row" type="button" @click="openRequest(entry)">
                    <span class="warehouse-request-row__route">{{ entry.expedient?.route_code }}</span>
                    <span><strong>{{ entry.requesting_office?.name }}</strong><small>{{ entry.requesting_user?.name }} · {{ entry.items?.length }} renglón(es)</small></span>
                    <span class="warehouse-request-row__status">{{ entry.display_status }}</span>
                    <time>{{ dateTime(entry.submitted_at || entry.created_at) }}</time>
                </button>
            </div>
        </section>

        <section v-else-if="tab === 'stock'" class="warehouse-stock-grid">
            <article v-for="item in warehouse.items" :key="item.id" class="panel stock-card" :class="{ 'is-low': item.is_below_minimum }">
                <header><span>{{ item.category?.name }}</span><strong>{{ item.code }}</strong></header>
                <h3>{{ item.name }}</h3><p>{{ item.physical_location || 'Sin ubicación física registrada' }}</p>
                <div><strong>{{ quantity(item.stock_on_hand) }}</strong><span>{{ item.measurement_unit?.symbol }} disponibles</span></div>
                <small>Stock mínimo: {{ quantity(item.minimum_stock) }} <b v-if="item.is_below_minimum">· Reponer</b></small>
                <button class="text-button" type="button" @click="openStock(item)">Ver kardex{{ warehouse.permissions.operate ? ' y ajustar' : '' }}</button>
            </article>
            <div v-if="!warehouse.items.length" class="panel empty-state">Aún no existen materiales en el catálogo.</div>
        </section>

        <section v-else-if="tab === 'receipts'" class="panel warehouse-list-panel">
            <header class="section-heading"><div><p class="eyebrow">Transparencia</p><h2>Ingresos registrados</h2></div><button class="button button--primary" type="button" @click="receiptModalOpen = true">+ Registrar ingreso</button></header>
            <div v-if="!warehouse.receipts.length" class="empty-state">No existen ingresos registrados.</div>
            <div v-else class="receipt-list"><button v-for="receipt in warehouse.receipts" :key="receipt.id" type="button" @click="receiptDetail = receipt"><div><strong>{{ receipt.receipt_number }}</strong><span>{{ receipt.supplier_name }} · {{ receipt.reference_type_label }} {{ receipt.reference_number }}</span></div><div><strong>{{ money(receipt.total_amount) }}</strong><small>{{ receipt.received_on }}</small></div></button></div>
        </section>

        <section v-else-if="tab === 'catalog'" class="catalog-layout">
            <section class="panel"><header class="section-heading"><div><p class="eyebrow">Clasificación</p><h2>Categorías</h2></div><button class="button button--ghost" type="button" @click="editCategory()">+ Categoría</button></header><div class="compact-list"><button v-for="category in warehouse.categories" :key="category.id" type="button" @click="editCategory(category)"><strong>{{ category.name }}</strong><small>{{ category.code }} · {{ category.status_label }}</small></button></div></section>
            <section class="panel"><header class="section-heading"><div><p class="eyebrow">Catálogo</p><h2>Materiales</h2></div><button class="button button--primary" type="button" @click="editItem()">+ Material</button></header><div class="compact-list"><button v-for="item in warehouse.items" :key="item.id" type="button" @click="editItem(item)"><strong>{{ item.name }}</strong><small>{{ item.code }} · {{ item.category?.name }} · {{ quantity(item.stock_on_hand) }} {{ item.measurement_unit?.symbol }}</small></button></div></section>
        </section>

        <div v-if="requestModalOpen" class="modal-backdrop" @click.self="requestModalOpen = false"><section class="modal warehouse-modal" role="dialog" aria-modal="true"><header class="modal__header"><div><p class="eyebrow">Solicitud documental</p><h2>{{ selected?.actions?.revise ? 'Corregir solicitud observada' : selected?.actions?.update ? 'Editar borrador' : 'Nueva solicitud de materiales' }}</h2></div><button type="button" aria-label="Cerrar" @click="requestModalOpen = false">×</button></header><form class="warehouse-form" @submit.prevent="saveRequest(true)">
            <div class="form-grid"><label class="field"><span>Oficina solicitante *</span><SearchableSelect v-model="requestForm.requesting_office_id" :options="officeOptions" /></label><label class="field"><span>Número o CITE propio</span><input v-model.trim="requestForm.office_reference" type="text" placeholder="Opcional; SIGAL asignará correlativo oficial"></label><label class="field field--full"><span>Justificación *</span><textarea v-model.trim="requestForm.justification" rows="3" required placeholder="Explique la necesidad institucional"></textarea></label></div>
            <section class="request-lines"><header><div><h3>Materiales solicitados</h3><p>Puede combinar materiales existentes y necesidades todavía no catalogadas.</p></div><button class="button button--ghost" type="button" @click="requestForm.items.push(newRequestLine())">+ Renglón</button></header><article v-for="(line, index) in requestForm.items" :key="index" class="request-line"><div class="request-line__mode"><label><input v-model="line.mode" value="catalog" type="radio" @change="changeLineMode(line)"> Catálogo</label><label><input v-model="line.mode" value="custom" type="radio" @change="changeLineMode(line)"> No registrado</label></div><label class="field"><span>Material *</span><SearchableSelect v-if="line.mode === 'catalog'" v-model="line.warehouse_item_id" :options="itemOptions" @change="selectCatalogItem(line)" /><input v-else v-model.trim="line.item_name" type="text" required placeholder="Describa el material"></label><label class="field"><span>Unidad *</span><SearchableSelect v-model="line.measurement_unit_id" :options="unitOptions" :disabled="line.mode === 'catalog'" /></label><label class="field"><span>Cantidad *</span><input v-model="line.requested_quantity" type="number" min="0.0001" step="0.0001" required></label><label class="field"><span>Detalle</span><input v-model.trim="line.notes" type="text" placeholder="Marca, tamaño u otra precisión"></label><button v-if="requestForm.items.length > 1" class="icon-danger" type="button" aria-label="Quitar renglón" @click="requestForm.items.splice(index, 1)">×</button></article></section>
            <footer class="modal__actions"><button class="button button--ghost" type="button" @click="requestModalOpen = false">Cancelar</button><button v-if="!selected?.actions?.revise" class="button button--ghost" type="button" @click="saveRequest(false)">Guardar borrador</button><button class="button button--primary" type="submit">{{ selected?.actions?.revise ? 'Corregir y reenviar' : 'Guardar y presentar' }}</button></footer>
        </form></section></div>

        <div v-if="detailOpen && selected" class="detail-pane warehouse-detail"><header class="detail-pane__header"><div><p class="eyebrow">{{ selected.expedient?.route_code }}</p><h2>Solicitud de materiales</h2><p>{{ selected.requesting_office?.name }} · {{ selected.requesting_user?.name }}</p></div><button type="button" aria-label="Cerrar" @click="detailOpen = false">×</button></header><div class="detail-pane__content">
            <section class="request-status-hero"><div><span>Estado actual</span><strong>{{ selected.display_status }}</strong></div><div><span>Documento</span><strong>{{ selected.document?.office_reference || selected.document?.official_number || 'Borrador sin número' }}</strong></div></section>
            <section class="panel detail-block"><p class="eyebrow">Justificación</p><p>{{ selected.justification }}</p><table class="warehouse-table"><thead><tr><th>Material</th><th>Solicitado</th><th>Observación</th></tr></thead><tbody><tr v-for="line in selected.items" :key="line.id"><td>{{ line.item_name }}<small v-if="!line.warehouse_item_id">No catalogado</small></td><td>{{ quantity(line.requested_quantity) }} {{ line.measurement_unit?.symbol }}</td><td>{{ line.notes || '—' }}</td></tr></tbody></table></section>
            <div v-if="selected.actions?.update || selected.actions?.revise || selected.actions?.submit" class="panel request-action-card"><h3>Acciones del solicitante</h3><div class="inline-actions"><button v-if="selected.actions.update || selected.actions.revise" class="button button--ghost" type="button" @click="editSelected">{{ selected.actions.revise ? 'Corregir observaciones' : 'Editar borrador' }}</button><button v-if="selected.actions.submit" class="button button--primary" type="button" @click="submitSelected">Presentar solicitud</button></div></div>
            <div v-if="selected.actions?.decide" class="panel request-action-card"><h3>Decisión de {{ selected.current_stage_label }}</h3><label class="field"><span>Observación o fundamento</span><textarea v-model.trim="actionNotes" rows="3" placeholder="Obligatoria al observar o rechazar"></textarea></label><div class="decision-actions"><button class="button button--primary" type="button" @click="decide('approve')">Aprobar y continuar</button><button class="button button--ghost" type="button" :disabled="!actionNotes" @click="decide('observe')">Observar</button><button class="button button--danger" type="button" :disabled="!actionNotes" @click="decide('reject')">Rechazar</button></div></div>
            <div v-if="selected.actions?.deliver" class="panel request-action-card"><h3>Definir entrega de Almacenes</h3><p class="muted">La cantidad entregada es la decisión final. Una entrega menor no deja saldo pendiente.</p><div class="delivery-line" v-for="requestItem in selected.items" :key="requestItem.id"><strong>{{ requestItem.item_name }}</strong><label class="field"><span>Material que se entrega</span><SearchableSelect v-model="deliveryLines[requestItem.id].warehouse_item_id" :options="deliveryItemOptions(requestItem)" /></label><label class="field"><span>Cantidad</span><input v-model="deliveryLines[requestItem.id].delivered_quantity" type="number" min="0" step="0.0001"></label><label v-if="Number(deliveryLines[requestItem.id].delivered_quantity) > Number(requestItem.requested_quantity)" class="field field--full"><span>Justificación por entregar más *</span><textarea v-model.trim="deliveryLines[requestItem.id].over_delivery_reason" rows="2"></textarea></label></div><div class="decision-actions"><button class="button button--primary" type="button" @click="registerDelivery">Registrar entrega</button></div><div class="not-attended-box"><label class="field"><span>Motivo para no atender</span><textarea v-model.trim="notAttendedReason" rows="2"></textarea></label><button class="button button--danger" type="button" :disabled="!notAttendedReason" @click="markNotAttended">Cerrar sin entrega</button></div></div>
            <div v-if="selected.actions?.authorize_receiver" class="panel request-action-card"><h3>Autorizar receptor alterno</h3><p class="muted">Solo para esta entrega. El acta identificará a quien reciba realmente.</p><button v-if="!receiverOptions.length" class="button button--ghost" type="button" @click="loadReceivers">Seleccionar funcionario</button><template v-else><label class="field"><span>Funcionario *</span><SearchableSelect v-model="receiverForm.receiver_user_id" :options="receiverOptions" /></label><label class="field"><span>Motivo *</span><textarea v-model.trim="receiverForm.reason" rows="2"></textarea></label><button class="button button--primary" type="button" :disabled="!receiverForm.receiver_user_id || !receiverForm.reason" @click="authorizeReceiver">Autorizar</button></template></div>
            <div v-if="selected.actions?.confirm_receipt" class="panel request-action-card"><h3>Confirmar recepción</h3><p>Confirme que recibió las cantidades detalladas. Esta acción cerrará la solicitud y generará el acta digital.</p><label class="field"><span>Observación</span><textarea v-model.trim="confirmationObservations" rows="2"></textarea></label><button class="button button--primary" type="button" @click="confirmReceipt">Confirmar y generar acta</button></div>
            <section v-if="selected.delivery" class="panel detail-block act-sheet" :class="{ 'is-printable': selected.actions?.view_act }"><header><div><p class="eyebrow">Acta de entrega</p><h3>{{ selected.delivery.act_document?.official_number || selected.delivery.delivery_number }}</h3></div><button v-if="selected.actions?.view_act" class="button button--ghost no-print" type="button" @click="printAct">Imprimir acta</button></header><dl><div><dt>Responsable de Almacenes</dt><dd>{{ selected.delivery.warehouse_responsible?.name }}</dd></div><div><dt>Entregado por</dt><dd>{{ selected.delivery.delivered_by?.name }}</dd></div><div><dt>Recibido por</dt><dd>{{ selected.delivery.confirmed_by?.name || 'Pendiente' }}</dd></div><div><dt>Verificación</dt><dd>{{ selected.delivery.act_verification_code || 'Se asignará al confirmar' }}</dd></div></dl><table class="warehouse-table"><thead><tr><th>Material</th><th>Solicitado</th><th>Entregado</th></tr></thead><tbody><tr v-for="line in selected.delivery.lines" :key="line.id"><td>{{ line.item?.name }}</td><td>{{ quantity(line.requested_quantity) }}</td><td>{{ quantity(line.delivered_quantity) }} {{ line.item?.measurement_unit?.symbol }}</td></tr></tbody></table><small v-if="selected.delivery.act_hash" class="act-hash">Integridad SHA-256: {{ selected.delivery.act_hash }}</small></section>
            <section class="panel detail-block"><p class="eyebrow">Trazabilidad</p><div class="decision-timeline"><article v-for="decision in selected.decisions" :key="decision.id"><span></span><div><strong>{{ decision.action.replaceAll('_', ' ') }}</strong><p>{{ decision.actor?.name }} · {{ decision.office?.name || selected.requesting_office?.name }}</p><small>{{ dateTime(decision.decided_at) }}<template v-if="decision.notes"> · {{ decision.notes }}</template></small></div></article></div></section>
        </div></div>

        <div v-if="categoryModalOpen" class="modal-backdrop" @click.self="categoryModalOpen = false"><section class="modal small-modal"><header class="modal__header"><div><p class="eyebrow">Catálogo</p><h2>{{ categoryEditingId ? 'Editar categoría' : 'Nueva categoría' }}</h2></div><button @click="categoryModalOpen = false">×</button></header><form class="warehouse-form" @submit.prevent="saveCategory"><div class="form-grid"><label class="field"><span>Código *</span><input v-model.trim="categoryForm.code" required></label><label class="field"><span>Nombre *</span><input v-model.trim="categoryForm.name" required></label><label class="field"><span>Categoría superior</span><SearchableSelect v-model="categoryForm.parent_id" :options="categoryParentOptions" /></label><label class="field"><span>Estado *</span><SearchableSelect v-model="categoryForm.status" :options="statusOptions" /></label><label class="field field--full"><span>Descripción</span><textarea v-model.trim="categoryForm.description" rows="2"></textarea></label></div><footer class="modal__actions"><button class="button button--ghost" type="button" @click="categoryModalOpen = false">Cancelar</button><button class="button button--primary">Guardar</button></footer></form></section></div>
        <div v-if="itemModalOpen" class="modal-backdrop" @click.self="itemModalOpen = false"><section class="modal warehouse-modal"><header class="modal__header"><div><p class="eyebrow">Catálogo</p><h2>{{ itemEditingId ? 'Editar material consumible' : 'Nuevo material consumible' }}</h2></div><button @click="itemModalOpen = false">×</button></header><form class="warehouse-form" @submit.prevent="saveItem"><div class="form-grid"><label class="field"><span>Código *</span><input v-model.trim="itemForm.code" required></label><label class="field"><span>Nombre *</span><input v-model.trim="itemForm.name" required></label><label class="field"><span>Categoría *</span><SearchableSelect v-model="itemForm.warehouse_category_id" :options="categoryOptions" /></label><label class="field"><span>Unidad base *</span><SearchableSelect v-model="itemForm.measurement_unit_id" :options="unitOptions" /></label><label class="field"><span>Stock mínimo</span><input v-model="itemForm.minimum_stock" type="number" min="0" step="0.0001"></label><label class="field"><span>Ubicación física</span><input v-model.trim="itemForm.physical_location" placeholder="Estante, sector o referencia"></label><label class="field"><span>Estado *</span><SearchableSelect v-model="itemForm.status" :options="statusOptions" /></label><label class="field field--full"><span>Descripción</span><textarea v-model.trim="itemForm.description" rows="2"></textarea></label></div><footer class="modal__actions"><button class="button button--ghost" type="button" @click="itemModalOpen = false">Cancelar</button><button class="button button--primary">Guardar material</button></footer></form></section></div>
        <div v-if="receiptModalOpen" class="modal-backdrop" @click.self="receiptModalOpen = false"><section class="modal warehouse-modal"><header class="modal__header"><div><p class="eyebrow">Existencias</p><h2>Registrar ingreso a Almacenes</h2></div><button @click="receiptModalOpen = false">×</button></header><form class="warehouse-form" @submit.prevent="saveReceipt"><div class="form-grid"><label class="field"><span>Proveedor *</span><input v-model.trim="receiptForm.supplier_name" required></label><label class="field"><span>NIT</span><input v-model.trim="receiptForm.supplier_tax_id"></label><label class="field"><span>Respaldo *</span><SearchableSelect v-model="receiptForm.reference_type" :options="[{ value: 'invoice', label: 'Factura' }, { value: 'note', label: 'Nota' }]" /></label><label class="field"><span>Número *</span><input v-model.trim="receiptForm.reference_number" required></label><label class="field"><span>Fecha del respaldo *</span><input v-model="receiptForm.reference_date" type="date" required></label><label class="field"><span>Fecha de ingreso *</span><input v-model="receiptForm.received_on" type="date" required></label></div><section class="request-lines"><header><div><h3>Detalle comprado</h3><p>El costo y cantidad actualizarán inmediatamente el kardex.</p></div><button class="button button--ghost" type="button" @click="receiptForm.lines.push(newReceiptLine())">+ Renglón</button></header><article v-for="(line, index) in receiptForm.lines" :key="index" class="receipt-line"><label class="field"><span>Material *</span><SearchableSelect v-model="line.warehouse_item_id" :options="itemOptions" /></label><label class="field"><span>Cantidad *</span><input v-model="line.quantity" type="number" min="0.0001" step="0.0001" required></label><label class="field"><span>Costo unitario Bs *</span><input v-model="line.unit_cost" type="number" min="0" step="0.0001" required></label><label class="field"><span>Lote</span><input v-model.trim="line.lot_number"></label><label class="field"><span>Vencimiento</span><input v-model="line.expires_on" type="date" :min="receiptForm.received_on"></label><label class="field"><span>Ubicación</span><input v-model.trim="line.physical_location"></label><button v-if="receiptForm.lines.length > 1" class="icon-danger" type="button" @click="receiptForm.lines.splice(index, 1)">×</button></article></section><label class="support-file"><span>Factura, nota u otros respaldos</span><strong>{{ receiptFiles.length ? `${receiptFiles.length} archivo(s)` : 'Seleccionar archivos' }}</strong><input type="file" multiple @change="receiptFiles = [...$event.target.files]"></label><label class="field"><span>Observaciones</span><textarea v-model.trim="receiptForm.observations" rows="2"></textarea></label><footer class="modal__actions"><button class="button button--ghost" type="button" @click="receiptModalOpen = false">Cancelar</button><button class="button button--primary">Registrar ingreso</button></footer></form></section></div>

        <div v-if="stockDetailOpen && stockDetailItem" class="modal-backdrop" @click.self="stockDetailOpen = false"><section class="modal warehouse-modal"><header class="modal__header"><div><p class="eyebrow">Kardex</p><h2>{{ stockDetailItem.name }}</h2><p>{{ quantity(stockDetailItem.stock_on_hand) }} {{ stockDetailItem.measurement_unit?.symbol }} disponibles</p></div><button @click="stockDetailOpen = false">×</button></header><div class="warehouse-form"><table class="warehouse-table"><thead><tr><th>Fecha</th><th>Tipo</th><th>Variación</th><th>Saldo</th><th>Responsable</th></tr></thead><tbody><tr v-for="movement in stockMovements" :key="movement.id"><td>{{ dateTime(movement.occurred_at) }}</td><td>{{ movement.movement_type_label }}</td><td>{{ quantity(movement.quantity_delta) }}</td><td>{{ quantity(movement.balance_after) }}</td><td>{{ movement.performed_by?.name }}</td></tr></tbody></table><div v-if="!stockMovements.length" class="empty-state">No existen movimientos.</div><form v-if="warehouse.permissions.operate" class="request-action-card" @submit.prevent="adjustStock"><h3>Ajuste excepcional</h3><div class="form-grid"><label class="field"><span>Variación *</span><input v-model="adjustmentForm.quantity_delta" type="number" step="0.0001" required placeholder="Use negativo para disminuir"></label><label class="field"><span>Justificación *</span><input v-model.trim="adjustmentForm.reason" required></label></div><button class="button button--primary">Registrar ajuste auditable</button></form></div></section></div>

        <div v-if="receiptDetail" class="modal-backdrop" @click.self="receiptDetail = null"><section class="modal warehouse-modal"><header class="modal__header"><div><p class="eyebrow">Ingreso {{ receiptDetail.receipt_number }}</p><h2>{{ receiptDetail.supplier_name }}</h2><p>{{ receiptDetail.reference_type_label }} {{ receiptDetail.reference_number }} · {{ money(receiptDetail.total_amount) }}</p></div><button @click="receiptDetail = null">×</button></header><div class="warehouse-form"><table class="warehouse-table"><thead><tr><th>Material</th><th>Cantidad</th><th>Costo unitario</th><th>Subtotal</th><th>Lote / vencimiento</th></tr></thead><tbody><tr v-for="line in receiptDetail.lines" :key="line.id"><td>{{ line.item?.name }}</td><td>{{ quantity(line.quantity) }} {{ line.item?.measurement_unit?.symbol }}</td><td>{{ money(line.unit_cost) }}</td><td>{{ money(line.subtotal) }}</td><td>{{ line.lot_number || '—' }} / {{ line.expires_on || 'Sin vencimiento' }}</td></tr></tbody></table><div v-if="receiptDetail.attachments?.length" class="attachment-list"><button v-for="attachment in receiptDetail.attachments" :key="attachment.id" class="attachment-download" type="button" @click="warehouse.downloadReceiptAttachment(receiptDetail.id, attachment)"><strong>{{ attachment.original_name }}</strong><span>Descargar</span></button></div></div></section></div>
    </section>
</template>
