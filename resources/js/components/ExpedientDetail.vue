<script setup>
/**
 * Vista operativa completa del expediente. Presenta exclusivamente las acciones
 * autorizadas por las Policies que el API devuelve junto con el expediente.
 */
import { computed, reactive, ref, watch } from 'vue';
import RichTextEditor from './RichTextEditor.vue';
import SearchableSelect from './SearchableSelect.vue';
import { flattenOfficeHierarchy } from '../lib/organization';
import { useDocumentManagementStore } from '../stores/document-management';

const props = defineProps({ session: { type: Object, required: true } });
const emit = defineEmits(['close']);
const documents = useDocumentManagementStore();
const activeTab = ref('summary');
const error = ref(null);
const showDocumentForm = ref(false);
const documentComposerMode = ref('rich_text');
const queuedFiles = ref([]);
const lifecycleAction = ref(null);
const editingDocumentId = ref(null);
const correctionForId = ref(null);

const documentForm = reactive({
    document_type_id: '',
    issuing_office_id: '',
    office_reference: '',
    title: '',
    content: '',
    primary_office_ids: [],
    copy_office_ids: [],
    requires_response: true,
});
const editDocumentForm = reactive({ title: '', content: '', office_reference: '' });
const lifecycleReason = ref('');
const reopeningReason = ref('');
const decisionNote = ref('');
const accessForm = reactive({ target: 'office', office_id: '', user_id: '', effective_from: '', reason: '' });

const tabs = [
    ['summary', 'Resumen'],
    ['movements', 'Derivaciones'],
    ['documents', 'Documentos'],
    ['lifecycle', 'Ciclo de vida'],
    ['access', 'Acceso'],
];

const selected = computed(() => documents.selected);
const isTerminal = computed(() => ['closed', 'voided'].includes(selected.value?.status));
const isDocumentLocked = computed(() => ['archived', 'closed', 'voided'].includes(selected.value?.status));
const myOfficeIds = computed(() => (props.session.user?.office_memberships ?? []).map((membership) => Number(membership.office_id)));
const allOffices = computed(() => flattenOfficeHierarchy(documents.catalogs.offices));
const latestMovement = computed(() => documents.movements[0] ?? null);
const currentHolderOfficeIds = computed(() => {
    // Solo el movimiento más reciente representa la tenencia; los anteriores son historial.
    if (Array.isArray(selected.value?.current_holder_office_ids)) {
        return selected.value.current_holder_office_ids.map(Number).filter(Boolean);
    }

    if (!latestMovement.value) return [selected.value?.responsible_office?.id].filter(Boolean);

    const recipientOfficeIds = latestMovement.value.recipients
        .filter((recipient) => recipient.recipient_kind === 'primary' && !['returned', 'rejected', 'completed'].includes(recipient.status))
        .map((recipient) => Number(recipient.recipient_office?.id))
        .filter(Boolean);

    return recipientOfficeIds.length
        ? recipientOfficeIds
        : [Number(latestMovement.value.sender_office?.id)].filter(Boolean);
});
const holderOffices = computed(() => allOffices.value.filter((office) => currentHolderOfficeIds.value.includes(Number(office.id))));
const myHolderOffices = computed(() => holderOffices.value.filter((office) => myOfficeIds.value.includes(Number(office.id))));
const permissions = computed(() => selected.value?.permissions ?? {});
const documentOffices = computed(() => permissions.value.manage_documents ? myHolderOffices.value : []);
const canUseAccess = computed(() => permissions.value.manage_access === true);
const canUseLifecycle = computed(() => permissions.value.view_lifecycle === true
    || permissions.value.request_reopening === true
    || permissions.value.approve_reopening === true);
const visibleTabs = computed(() => tabs.filter(([value]) => {
    if (value === 'access') return canUseAccess.value;
    if (value === 'lifecycle') return canUseLifecycle.value;
    return true;
}));
const hasManyDocumentOffices = computed(() => documentOffices.value.length > 1);
const hasManyDocumentTypes = computed(() => documents.catalogs.documentTypes.length > 1);
const canCreateDocuments = computed(() => permissions.value.manage_documents === true && documentOffices.value.length > 0);
const officeToneNames = ['jade', 'ocean', 'indigo', 'violet', 'plum', 'ruby', 'terracotta', 'gold', 'olive', 'teal', 'slate', 'cocoa'];

watch(selected, () => {
    activeTab.value = 'summary';
    showDocumentForm.value = false;
    documentComposerMode.value = 'rich_text';
    queuedFiles.value = [];
    lifecycleAction.value = null;
    error.value = null;
}, { flush: 'post' });

watch([documentOffices, () => documents.catalogs.documentTypes.length], () => {
    if (!documentOffices.value.some((office) => office.id === Number(documentForm.issuing_office_id))) documentForm.issuing_office_id = '';
    if (!documentForm.issuing_office_id && documentOffices.value.length === 1) documentForm.issuing_office_id = documentOffices.value[0].id;
    if (!documentForm.document_type_id && documents.catalogs.documentTypes.length === 1) documentForm.document_type_id = documents.catalogs.documentTypes[0].id;
}, { immediate: true });

function formatDate(value, withTime = false) {
    if (!value) return '—';
    const date = new Date(value.includes('T') ? value : `${value}T12:00:00`);
    return new Intl.DateTimeFormat('es-BO', withTime
        ? { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { day: '2-digit', month: 'short', year: 'numeric' }).format(date);
}

function formatSize(bytes) {
    if (!Number.isFinite(bytes)) return 'Tamaño no disponible';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function officeLabel(office) {
    return office ? `${office.code} · ${office.name}` : '—';
}

function officeOptionLabel(office) {
    return office?.hierarchy_label ?? officeLabel(office);
}

function officeToneClass(office) {
    const index = Math.abs((Number(office?.id) || 1) - 1) % officeToneNames.length;
    return `route-tone--${officeToneNames[index]}`;
}

function officeCode(office) {
    return office?.code || 'OFICINA';
}

function movementRouteLabel(movement) {
    const primaryRecipients = (movement.recipients ?? [])
        .filter((recipient) => recipient.recipient_kind === 'primary')
        .map((recipient) => officeCode(recipient.recipient_office));
    const copyRecipients = (movement.recipients ?? [])
        .filter((recipient) => recipient.recipient_kind === 'copy')
        .map((recipient) => officeCode(recipient.recipient_office));
    const route = `${officeCode(movement.sender_office)} → ${primaryRecipients.join(', ') || 'SIN DESTINATARIO'}`;

    return copyRecipients.length ? `${route} · CC → ${copyRecipients.join(', ')}` : route;
}

function recipientCanAct(movement, recipient) {
    // Una copia o una recepción histórica nunca debe presentar controles operativos.
    return permissions.value.act_on_movement === true
        && movement.id === latestMovement.value?.id
        && recipient.recipient_kind === 'primary'
        && !recipientIsTerminal(recipient)
        && myOfficeIds.value.includes(Number(recipient.recipient_office?.id));
}

function recipientIsTerminal(recipient) {
    return ['responded', 'returned', 'rejected', 'completed'].includes(recipient.status);
}

function canManageDocument(document) {
    return permissions.value.manage_documents === true
        && !document.is_initial
        && documentOffices.value.some((office) => Number(office.id) === Number(document.issuing_office?.id));
}

function toggleRecipient(list, id) {
    const numericId = Number(id);
    const index = list.indexOf(numericId);
    if (index >= 0) list.splice(index, 1);
    else list.push(numericId);
}

async function openTab(tab) {
    activeTab.value = tab;
    error.value = null;
    if (tab === 'access' && canUseAccess.value) {
        try {
            await Promise.all([documents.loadAccessGrants(), documents.loadUsers()]);
        } catch (exception) {
            error.value = exception.message;
        }
    }
}

async function updateRecipient(recipient, status) {
    const note = window.prompt('Añada una nota para esta actuación (opcional):') ?? '';
    try {
        await documents.updateRecipientStatus(recipient.id, { status, action_note: note || null });
    } catch (exception) {
        error.value = exception.message;
    }
}

async function submitDocument() {
    // El documento y su derivación se envían juntos para evitar el doble trabajo del flujo antiguo.
    error.value = null;
    try {
        const payload = {
            ...documentForm,
            document_type_id: Number(documentForm.document_type_id),
            issuing_office_id: Number(documentForm.issuing_office_id),
            content: documentForm.content || null,
            office_reference: documentForm.office_reference || null,
            primary_office_ids: documentForm.primary_office_ids,
            copy_office_ids: documentForm.copy_office_ids,
            requires_response: documentForm.requires_response,
        };

        await documents.createDocument(payload, queuedFiles.value);
        Object.assign(documentForm, {
            document_type_id: '',
            issuing_office_id: '',
            office_reference: '',
            title: '',
            content: '',
            primary_office_ids: [],
            copy_office_ids: [],
            requires_response: true,
        });
        queuedFiles.value = [];
        documentComposerMode.value = 'rich_text';
        showDocumentForm.value = false;
    } catch (exception) {
        error.value = exception.message;
    }
}

async function saveDocument(document) {
    try {
        await documents.updateDocument(document.id, {
            title: editDocumentForm.title,
            content: editDocumentForm.content || null,
            office_reference: editDocumentForm.office_reference || null,
        });
        editingDocumentId.value = null;
    } catch (exception) {
        error.value = exception.message;
    }
}

function startEditingDocument(document) {
    editingDocumentId.value = document.id;
    editDocumentForm.title = document.title;
    editDocumentForm.content = document.content || '';
    editDocumentForm.office_reference = document.office_reference || '';
}

async function issueDocument(document) {
    if (!window.confirm('Al emitir el documento ya no podrá modificarlo. ¿Desea continuar?')) return;
    try {
        await documents.issueDocument(document.id);
    } catch (exception) {
        error.value = exception.message;
    }
}

async function createCorrection(document) {
    try {
        await documents.createCorrection(document.id, { title: document.title, content: document.content || null });
        correctionForId.value = null;
    } catch (exception) {
        error.value = exception.message;
    }
}

function queueFiles(event) {
    queuedFiles.value = [...queuedFiles.value, ...Array.from(event.target.files ?? [])];
    event.target.value = '';
}

function removeQueuedFile(index) {
    queuedFiles.value.splice(index, 1);
}

async function attachFiles(document, event) {
    const files = Array.from(event.target.files ?? []);
    if (!files.length) return;
    try {
        await documents.attachFiles(document.id, files);
    } catch (exception) {
        error.value = exception.message;
    } finally {
        event.target.value = '';
    }
}

async function submitLifecycle() {
    if (!lifecycleAction.value || !lifecycleReason.value.trim()) return;
    try {
        await documents.lifecycle(lifecycleAction.value, lifecycleReason.value.trim());
        lifecycleAction.value = null;
        lifecycleReason.value = '';
    } catch (exception) {
        error.value = exception.message;
    }
}

async function submitReopening() {
    try {
        await documents.requestReopening(reopeningReason.value);
        reopeningReason.value = '';
    } catch (exception) {
        error.value = exception.message;
    }
}

async function decideReopening(reopeningRequest, decision) {
    try {
        await documents.decideReopening(reopeningRequest.id, decision, decisionNote.value);
        decisionNote.value = '';
    } catch (exception) {
        error.value = exception.message;
    }
}

async function grantAccess() {
    try {
        await documents.grantAccess({
            user_id: accessForm.target === 'user' ? Number(accessForm.user_id) : null,
            office_id: accessForm.target === 'office' ? Number(accessForm.office_id) : null,
            effective_from: accessForm.effective_from || null,
            reason: accessForm.reason || null,
        });
        Object.assign(accessForm, { target: 'office', office_id: '', user_id: '', effective_from: '', reason: '' });
    } catch (exception) {
        error.value = exception.message;
    }
}
</script>

<template>
    <aside v-if="selected" class="detail-pane" aria-label="Detalle del expediente">
        <header class="detail-pane__header">
            <button class="back-button" type="button" @click="emit('close')">← Volver a la bandeja</button>
            <div class="detail-title-row">
                <div>
                    <p class="eyebrow">{{ selected.route_code }}</p>
                    <h2>{{ selected.subject }}</h2>
                </div>
                <span class="badge badge--large" :class="`badge--${selected.status}`">{{ selected.status_label }}</span>
            </div>
            <div class="detail-meta">
                <span>{{ selected.expedient_type?.name }}</span><span>•</span><span>Recepción: {{ formatDate(selected.received_on) }}</span><span>•</span><span>{{ officeLabel(selected.responsible_office) }}</span>
            </div>
        </header>

        <p v-if="error || documents.error" class="alert alert--error detail-pane__alert" role="alert">{{ error || documents.error }}</p>

        <nav class="detail-tabs" aria-label="Secciones del expediente">
            <button v-for="[value, label] in visibleTabs" :key="value" type="button" :class="{ 'is-active': activeTab === value }" @click="openTab(value)">
                {{ label }}
            </button>
        </nav>

        <div class="detail-pane__content">
            <section v-if="activeTab === 'summary'" class="tab-section">
                <div class="summary-grid">
                    <article class="summary-card"><span>Remitente</span><strong>{{ selected.sender_name }}</strong><small>{{ selected.sender_type_label }} · {{ selected.origin_label }}</small></article>
                    <article class="summary-card"><span>Plazo</span><strong>{{ formatDate(selected.due_on) }}</strong><small>{{ selected.priority_label || 'Normal' }}</small></article>
                    <article class="summary-card"><span>Custodia actual</span><strong>{{ holderOffices.map(officeLabel).join(', ') || 'Sin oficina determinada' }}</strong><small>Oficina autorizada para continuar el trámite.</small></article>
                    <article class="summary-card"><span>Confidencialidad</span><strong>{{ selected.confidentiality_level?.name || 'Interno' }}</strong><small>{{ selected.confidentiality_level?.requires_explicit_access ? 'Acceso restringido' : 'Acceso institucional' }}</small></article>
                    <article class="summary-card"><span>Registrado por</span><strong>{{ selected.created_by?.name || '—' }}</strong><small>{{ formatDate(selected.created_at, true) }}</small></article>
                </div>
                <article class="narrative-card"><span>Resumen</span><p>{{ selected.summary }}</p></article>
                <article v-if="selected.observations" class="narrative-card"><span>Instrucción general</span><p>{{ selected.observations }}</p></article>
            </section>

            <section v-if="activeTab === 'movements'" class="tab-section">
                <div class="section-heading">
                    <div><p class="eyebrow">Ruta de atención</p><h3>Derivaciones</h3></div>
                </div>

                <div v-if="!documents.movements.length" class="empty-state">Este expediente aún no tiene derivaciones.</div>
                <ol v-else class="timeline">
                    <li v-for="movement in documents.movements" :key="movement.id" class="timeline__item" :class="officeToneClass(movement.sender_office)">
                        <div class="timeline__marker"></div>
                        <article class="movement-card">
                            <header><div><span class="office-route-key">{{ officeCode(movement.sender_office) }}</span><strong>Remite: {{ officeLabel(movement.sender_office) }}</strong></div><span>{{ formatDate(movement.sent_at, true) }}</span></header>
                            <p>{{ movement.instruction || 'Sin instrucción registrada.' }}</p>
                            <small v-if="movement.due_on">Plazo: {{ formatDate(movement.due_on) }} · {{ movement.priority_label || 'Normal' }}</small>
                            <small>{{ movement.requires_response_label }}</small>
                            <small v-if="movement.documents?.length">Documento que acompaña la derivación: {{ movement.documents.map((document) => document.title).join(', ') }}</small>
                            <div class="recipient-list">
                                <div v-for="recipient in movement.recipients" :key="recipient.id" class="recipient-row">
                                    <div><span class="recipient-kind">{{ recipient.recipient_kind_label }}</span><strong>{{ officeLabel(recipient.recipient_office) }}</strong><small v-if="recipient.action_note">{{ recipient.action_note }}</small></div>
                                    <div class="recipient-row__status"><span class="badge" :class="`badge--recipient-${recipient.status}`">{{ recipient.status_label }}</span><SearchableSelect v-if="recipientCanAct(movement, recipient) && !recipientIsTerminal(recipient) && !isDocumentLocked" :model-value="recipient.status" :disabled="documents.busy[`recipient-${recipient.id}`]" :options="[{ value: 'received', label: 'Recibido' }, { value: 'in_process', label: 'En proceso' }, { value: 'responded', label: 'Respondido' }, { value: 'completed', label: 'Finalizar mi participación' }, { value: 'returned', label: 'Devuelto' }, { value: 'rejected', label: 'Rechazado' }]" @update:model-value="updateRecipient(recipient, $event)" /><select v-show="false" :value="recipient.status" disabled><option value="received">Recibido</option><option value="in_process">En proceso</option><option value="responded">Respondido</option><option value="completed">Finalizar mi participación</option><option value="returned">Devuelto</option><option value="rejected">Rechazado</option></select></div>
                                </div>
                            </div>
                        </article>
                    </li>
                </ol>
            </section>

            <section v-if="activeTab === 'documents'" class="tab-section">
                <div class="section-heading"><div><p class="eyebrow">Contenido institucional</p><h3>Documentos y anexos</h3></div><button v-if="!isDocumentLocked && canCreateDocuments" class="button button--secondary" type="button" @click="showDocumentForm = !showDocumentForm">{{ showDocumentForm ? 'Cancelar' : '+ Nuevo documento o anexo' }}</button></div>
                <p v-if="!isDocumentLocked && !canCreateDocuments" class="form-note">Podrá crear documentación cuando una derivación principal llegue a una de sus oficinas.</p>

                <form v-if="showDocumentForm && canCreateDocuments" class="action-form" @submit.prevent="submitDocument">
                    <h4>Crear borrador o registrar anexos</h4>
                    <label v-if="hasManyDocumentTypes" class="field"><span>Tipo documental</span><SearchableSelect v-model="documentForm.document_type_id" :options="documents.catalogs.documentTypes.map((type) => ({ value: type.id, label: `${type.name}${type.is_official ? ' - Oficial' : ''}` }))" placeholder="Seleccione un tipo" /><select v-show="false" v-model="documentForm.document_type_id" disabled><option value="" disabled>Seleccione un tipo</option><option v-for="type in documents.catalogs.documentTypes" :key="type.id" :value="type.id">{{ type.name }}{{ type.is_official ? ' · Oficial' : '' }}</option></select></label>
                    <div v-else class="field field--static"><span>Tipo documental</span><strong>{{ documents.catalogs.documentTypes[0]?.name || 'No hay tipos activos' }}</strong></div>
                    <label v-if="hasManyDocumentOffices" class="field"><span>Oficina emisora</span><SearchableSelect v-model="documentForm.issuing_office_id" :options="documentOffices.map((office) => ({ value: office.id, label: officeOptionLabel(office) }))" placeholder="Seleccione una oficina" /><select v-show="false" v-model="documentForm.issuing_office_id" disabled><option value="" disabled>Seleccione una oficina</option><option v-for="office in documentOffices" :key="office.id" :value="office.id">{{ officeOptionLabel(office) }}</option></select></label>
                    <div v-else class="field field--static"><span>Oficina emisora</span><strong>{{ officeLabel(documentOffices[0]) }}</strong></div>
                    <label class="field"><span>Número o CITE de la oficina <small>Opcional</small></span><input v-model.trim="documentForm.office_reference" maxlength="255" class="text-uppercase" placeholder="Ej.: SIS 003/2026"></label>
                    <label class="field field--full"><span>Título</span><input v-model.trim="documentForm.title" maxlength="255" placeholder="Ej.: Informe técnico sobre el trámite" required></label>
                    <div class="composer-mode form-grid__full"><button type="button" :class="{ 'is-active': documentComposerMode === 'rich_text' }" @click="documentComposerMode = 'rich_text'">Redactar informe</button><button type="button" :class="{ 'is-active': documentComposerMode === 'attachments' }" @click="documentComposerMode = 'attachments'">Solo anexar archivos</button></div>
                    <label v-if="documentComposerMode === 'rich_text'" class="field field--full"><span>Contenido del informe <small>Opcional</small></span><RichTextEditor v-model="documentForm.content" placeholder="Redacte el informe, nota o contenido del documento…" /></label>
                    <div class="file-dropzone form-grid__full"><div><strong>Adjuntar documentación</strong><p>Puede seleccionar imágenes, videos, audios, PDFs, hojas de cálculo o cualquier otro archivo permitido por el servidor.</p></div><label class="button button--ghost">Seleccionar archivos<input type="file" multiple @change="queueFiles"></label></div>
                    <div v-if="queuedFiles.length" class="queued-files form-grid__full"><div v-for="(file, index) in queuedFiles" :key="`${file.name}-${file.lastModified}-${index}`"><span>{{ file.name }}</span><small>{{ formatSize(file.size) }}</small><button type="button" aria-label="Quitar archivo" @click="removeQueuedFile(index)">×</button></div></div>
                    <div class="recipient-picker form-grid__full"><span>Destinatarios principales <small>Obligatorio</small></span><div><label v-for="office in allOffices.filter((item) => Number(item.id) !== Number(documentForm.issuing_office_id))" :key="office.id" class="check-option"><input type="checkbox" :checked="documentForm.primary_office_ids.includes(office.id)" @change="toggleRecipient(documentForm.primary_office_ids, office.id)"><span>{{ officeOptionLabel(office) }}</span></label></div></div>
                    <div class="recipient-picker form-grid__full"><span>En copia (opcional)</span><div><label v-for="office in allOffices.filter((item) => Number(item.id) !== Number(documentForm.issuing_office_id) && !documentForm.primary_office_ids.includes(item.id))" :key="office.id" class="check-option"><input type="checkbox" :checked="documentForm.copy_office_ids.includes(office.id)" @change="toggleRecipient(documentForm.copy_office_ids, office.id)"><span>{{ officeOptionLabel(office) }}</span></label></div></div>
                    <div class="entry-mode-notice form-grid__full"><label class="check-option"><input v-model="documentForm.requires_response" type="checkbox"><span><strong>Exige respuesta</strong><small>Está activado por defecto. Si lo desactiva, los destinatarios recibirán el documento solo para conocimiento y su participación quedará finalizada automáticamente.</small></span></label></div>
                    <p class="form-note form-grid__full">Al guardar, el documento quedará vinculado a la derivación. Se conservarán la prioridad <strong>{{ selected.priority_label || 'Normal' }}</strong>, el plazo <strong>{{ formatDate(selected.due_on) }}</strong> y la instrucción general ya registrada.</p>
                    <p class="form-note form-grid__full">Los anexos quedan asociados al borrador. Una vez emitido, el documento y sus anexos no pueden modificarse.</p>
                    <button class="button button--primary" :disabled="documents.busy.document || !documentForm.primary_office_ids.length" type="submit">{{ documents.busy.document ? 'Guardando…' : 'Guardar y derivar' }}</button>
                </form>

                <div v-if="!documents.documents.length" class="empty-state">No hay documentos asociados a este expediente.</div>
                <article v-for="document in documents.documents" :key="document.id" class="document-card">
                    <header class="document-card__header"><div><p class="eyebrow">{{ document.is_initial ? 'Documento de ingreso' : `${document.document_type?.name} · v${document.version_number}` }}</p><h4>{{ document.office_reference || document.formatted_number || (document.is_initial ? 'Antecedente inicial' : 'Borrador sin numeración') }}</h4><small v-if="document.office_reference && document.formatted_number">Código SIGAL: {{ document.formatted_number }}</small><small v-if="document.is_initial">Origen: {{ document.origin_number }} · {{ formatDate(document.origin_date) }}</small></div><div class="document-card__signals"><div v-if="document.movement_routes?.length" class="document-route-list"><span v-for="route in document.movement_routes" :key="route.id" class="document-route" :class="officeToneClass(route.sender_office)">{{ movementRouteLabel(route) }}</span></div><span v-else class="document-route document-route--unrouted">{{ document.is_initial ? 'Documento de ingreso' : 'Sin derivación vinculada' }}</span><span class="badge" :class="`badge--document-${document.status}`">{{ document.is_initial ? 'Ingreso registrado' : document.status_label }}</span></div></header>
                    <template v-if="editingDocumentId === document.id"><label class="field"><span>Número o CITE de la oficina <small>Opcional</small></span><input v-model.trim="editDocumentForm.office_reference" maxlength="255" class="text-uppercase" placeholder="Ej.: SIS 003/2026"></label><label class="field"><span>Título</span><input v-model="editDocumentForm.title" maxlength="255"></label><label class="field"><span>Contenido del informe</span><RichTextEditor v-model="editDocumentForm.content" /></label><div class="inline-actions"><button class="button button--primary" type="button" @click="saveDocument(document)">Guardar cambios</button><button class="button button--ghost" type="button" @click="editingDocumentId = null">Cancelar</button></div></template>
                    <template v-else><h4 class="document-card__title">{{ document.title }}</h4><div v-if="document.content" class="document-card__content document-card__content--rich" v-html="document.content"></div></template>
                    <div v-if="document.attachments?.length" class="attachment-list"><span>Adjuntos</span><div v-for="attachment in document.attachments" :key="attachment.id"><small>{{ attachment.original_name }} · {{ formatSize(attachment.size_bytes) }}</small><button class="text-button" type="button" :disabled="documents.busy[`download-${attachment.id}`]" @click="documents.downloadAttachment(document.id, attachment)">{{ documents.busy[`download-${attachment.id}`] ? 'Descargando…' : 'Descargar' }}</button></div></div>
                    <footer v-if="!isDocumentLocked && canManageDocument(document)" class="document-card__actions">
                        <button v-if="document.status === 'draft'" class="text-button" type="button" @click="startEditingDocument(document)">Editar</button>
                        <button v-if="document.status === 'draft'" class="text-button" type="button" @click="issueDocument(document)">Emitir</button>
                        <label v-if="document.status === 'draft'" class="file-button">Adjuntar archivos<input type="file" multiple @change="attachFiles(document, $event)"></label>
                        <button v-if="document.status === 'issued'" class="text-button" type="button" @click="correctionForId = correctionForId === document.id ? null : document.id">Crear corrección</button>
                    </footer>
                    <div v-if="correctionForId === document.id" class="correction-box"><p>Se creará un nuevo borrador que conservará la serie y aumentará la versión.</p><button class="button button--secondary" type="button" @click="createCorrection(document)">Crear borrador de corrección</button></div>
                </article>
            </section>

            <section v-if="activeTab === 'lifecycle'" class="tab-section">
                <div class="section-heading"><div><p class="eyebrow">Resguardo institucional</p><h3>Ciclo de vida</h3></div></div>
                <div class="lifecycle-stage"><span class="badge badge--large" :class="`badge--${selected.status}`">{{ selected.status_label }}</span><p>Las acciones se validan según la oficina, el cargo vigente y las reglas institucionales.</p></div>
                <div v-if="!isTerminal" class="lifecycle-actions"><button v-if="permissions.archive && selected.status !== 'archived'" class="button button--secondary" type="button" @click="lifecycleAction = 'archive'">Archivar</button><button v-if="permissions.close && selected.status === 'archived'" class="button button--secondary" type="button" @click="lifecycleAction = 'close'">Cerrar</button><button v-if="permissions.void" class="button button--danger" type="button" @click="lifecycleAction = 'void'">Anular</button></div>
                <form v-if="lifecycleAction" class="action-form" @submit.prevent="submitLifecycle"><h4>{{ lifecycleAction === 'archive' ? 'Archivar expediente' : lifecycleAction === 'close' ? 'Cerrar expediente' : 'Anular expediente' }}</h4><label class="field field--full"><span>Motivo de la acción</span><textarea v-model.trim="lifecycleReason" rows="3" required placeholder="Explique el fundamento institucional"></textarea></label><div class="inline-actions"><button class="button button--primary" :disabled="documents.busy[`lifecycle-${lifecycleAction}`]" type="submit">Confirmar acción</button><button class="button button--ghost" type="button" @click="lifecycleAction = null">Cancelar</button></div></form>

                <div v-if="permissions.request_reopening && ['archived', 'closed'].includes(selected.status)" class="reopening-area"><h4>Solicitud de reapertura</h4><p>La jefatura de la oficina responsable puede justificar una reapertura. OMAF decide la solicitud.</p><form @submit.prevent="submitReopening"><label class="field"><span>Justificación</span><textarea v-model.trim="reopeningReason" rows="3" required placeholder="Describa el antecedente que exige reabrir el trámite"></textarea></label><button class="button button--secondary" :disabled="documents.busy['reopening-request']" type="submit">Solicitar reapertura</button></form></div>
                <div v-if="documents.reopeningRequests.length" class="request-list"><h4>Historial de reaperturas</h4><article v-for="request in documents.reopeningRequests" :key="request.id" class="request-card"><div><span class="badge" :class="`badge--request-${request.status}`">{{ request.status_label }}</span><p>{{ request.justification }}</p><small>Solicitada: {{ formatDate(request.requested_at, true) }}</small><small v-if="request.decision_note">Decisión: {{ request.decision_note }}</small></div><div v-if="permissions.approve_reopening && request.status === 'pending'" class="decision-actions"><input v-model.trim="decisionNote" placeholder="Nota de decisión (opcional)"><button class="button button--primary" type="button" @click="decideReopening(request, 'approve')">Aprobar</button><button class="button button--ghost" type="button" @click="decideReopening(request, 'reject')">Rechazar</button></div></article></div>
            </section>

            <section v-if="activeTab === 'access'" class="tab-section">
                <div v-if="!canUseAccess" class="empty-state">Las concesiones de acceso están disponibles únicamente para superadministradores.</div>
                <template v-else><div class="section-heading"><div><p class="eyebrow">Confidencialidad</p><h3>Concesiones de acceso</h3></div></div><form class="action-form" @submit.prevent="grantAccess"><div class="segmented-control"><button type="button" :class="{ 'is-active': accessForm.target === 'office' }" @click="accessForm.target = 'office'">Oficina</button><button type="button" :class="{ 'is-active': accessForm.target === 'user' }" @click="accessForm.target = 'user'">Usuario</button></div><label v-if="accessForm.target === 'office'" class="field"><span>Oficina autorizada</span><SearchableSelect v-model="accessForm.office_id" :options="allOffices.map((office) => ({ value: office.id, label: officeOptionLabel(office) }))" placeholder="Seleccione una oficina" /><select v-show="false" v-model="accessForm.office_id" disabled><option value="" disabled>Seleccione una oficina</option><option v-for="office in allOffices" :key="office.id" :value="office.id">{{ officeOptionLabel(office) }}</option></select></label><label v-else class="field"><span>Usuario autorizado</span><SearchableSelect v-model="accessForm.user_id" :options="documents.users.map((user) => ({ value: user.id, label: `${user.name} - ${user.email}` }))" placeholder="Seleccione un usuario" /><select v-show="false" v-model="accessForm.user_id" disabled><option value="" disabled>Seleccione un usuario</option><option v-for="user in documents.users" :key="user.id" :value="user.id">{{ user.name }} · {{ user.email }}</option></select></label><label class="field"><span>Vigente desde</span><input v-model="accessForm.effective_from" type="date"></label><label class="field field--full"><span>Motivo</span><textarea v-model.trim="accessForm.reason" rows="2" placeholder="Motivo de la concesión"></textarea></label><button class="button button--primary" :disabled="documents.busy['grant-access']" type="submit">Conceder acceso</button></form><div v-if="!documents.accessGrants.length" class="empty-state">No hay concesiones registradas.</div><div v-else class="grant-list"><article v-for="grant in documents.accessGrants" :key="grant.id" class="grant-row"><div><strong>{{ grant.user?.name || officeLabel(grant.office) }}</strong><small>Vigente desde {{ formatDate(grant.effective_from, true) }}{{ grant.effective_to ? ` hasta ${formatDate(grant.effective_to, true)}` : '' }}</small><small v-if="grant.reason">{{ grant.reason }}</small></div><button v-if="!grant.effective_to" class="text-button text-button--danger" type="button" @click="documents.closeAccessGrant(grant.id)">Cerrar acceso</button></article></div></template>
            </section>
        </div>
    </aside>
</template>
