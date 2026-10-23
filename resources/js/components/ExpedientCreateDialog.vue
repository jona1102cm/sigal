<script setup>
/**
 * Alta normal de gestión documental: reúne documento inicial, metadatos del
 * expediente, archivos y destinatarios en una sola intención del usuario.
 */
import { computed, reactive, ref, watch } from 'vue';
import { flattenOfficeHierarchy } from '../lib/organization';
import RichTextEditor from './RichTextEditor.vue';
import SearchableMultiSelect from './SearchableMultiSelect.vue';
import SearchableSelect from './SearchableSelect.vue';
import { useDocumentManagementStore } from '../stores/document-management';
import { useSessionStore } from '../stores/session';

const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'created']);
const documents = useDocumentManagementStore();
const session = useSessionStore();
const error = ref(null);
const catalogError = ref(null);
const exceptionalEntry = ref(false);
const deadlineEnabled = ref(false);
const queuedFiles = ref([]);

function currentInstitutionalDate() {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/La_Paz',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
}

const today = currentInstitutionalDate();
const form = reactive({
    confidentiality_level_id: '',
    subject: '',
    summary: '',
    origin: 'internal',
    sender_type: 'person',
    sender_name: '',
    responsible_office_id: '',
    received_on: today,
    priority: 'normal',
    due_on: '',
    observations: '',
    derive_after_registration: true,
    primary_office_ids: [],
    primary_recipient_attention: {},
    copy_office_ids: [],
    requires_response: true,
    document_type_id: '',
    origin_document_number: '',
    origin_document_date: today,
    content: '',
});

const selectedConfidentiality = computed(() =>
    documents.catalogs.confidentialityLevels.find((level) => level.id === Number(form.confidentiality_level_id)),
);
const myOfficeMemberships = computed(() => session.user?.office_memberships ?? []);
const hierarchicalOffices = computed(() => flattenOfficeHierarchy(documents.catalogs.offices));
const responsibleOffices = computed(() => {
    if (session.isSuperAdministrator) return hierarchicalOffices.value;

    return myOfficeMemberships.value
        .map((membership) => hierarchicalOffices.value.find((office) => office.id === membership.office_id) ?? {
            id: membership.office_id,
            code: membership.office?.code ?? 'OFICINA',
            name: membership.office?.name ?? 'Oficina asignada',
            hierarchy_label: `${membership.office?.code ?? 'OFICINA'} · ${membership.office?.name ?? 'Oficina asignada'}`,
        })
        .filter((office) => Number.isInteger(Number(office.id)) && Number(office.id) > 0);
});
const selectedResponsibleOfficeId = computed(() => positiveIntegerId(form.responsible_office_id));
const selectedResponsibleOffice = computed(() => responsibleOffices.value.find((office) => Number(office.id) === selectedResponsibleOfficeId.value) ?? null);
const currentOfficeDisplay = computed(() => selectedResponsibleOffice.value ?? responsibleOffices.value[0] ?? myOfficeMemberships.value[0]?.office ?? null);
const canRegisterExternalDocuments = computed(() => selectedResponsibleOffice.value?.receives_external_documents === true);
const hasManyConfidentialityLevels = computed(() => documents.catalogs.confidentialityLevels.length > 1);
const hasManyDocumentTypes = computed(() => documents.catalogs.documentTypes.length > 1);
const hasManyResponsibleOffices = computed(() => responsibleOffices.value.length > 1);
const primaryRecipientOptions = computed(() => hierarchicalOffices.value
    .filter((office) => Number(office.id) !== selectedResponsibleOfficeId.value)
    .map((office) => ({ value: office.id, label: office.hierarchy_label })));
const copyRecipientOptions = computed(() => hierarchicalOffices.value
    .filter((office) => Number(office.id) !== selectedResponsibleOfficeId.value && !form.primary_office_ids.map(Number).includes(Number(office.id)))
    .map((office) => ({ value: office.id, label: office.hierarchy_label })));
const selectedCaucusRecipients = computed(() => {
    const issuer = responsibleOffices.value.find((office) => Number(office.id) === selectedResponsibleOfficeId.value);
    if (issuer?.code !== 'SGEN') return [];
    return form.primary_office_ids
        .map((officeId) => documents.legislativeDirectory.bodies.find((body) => Number(body.office_id) === Number(officeId) && body.type === 'caucus'))
        .filter(Boolean);
});

function setSensibleDefaults() {
    // Solo se autoselecciona una opción cuando no existe una decisión real que tomar.
    const internal = documents.catalogs.confidentialityLevels.find((level) => level.code === 'INTERNAL');
    const defaultLevel = internal ?? documents.catalogs.confidentialityLevels[0];
    const defaultDocumentCode = form.origin === 'internal' ? 'INTERNAL_NOTE' : 'OFFICIAL_LETTER';
    const defaultDocument = documents.catalogs.documentTypes.find((type) => type.code === defaultDocumentCode)
        ?? documents.catalogs.documentTypes[0];

    if (!form.confidentiality_level_id && defaultLevel) form.confidentiality_level_id = defaultLevel.id;
    if (!form.document_type_id && defaultDocument) form.document_type_id = defaultDocument.id;
    if (!form.responsible_office_id && responsibleOffices.value.length === 1) form.responsible_office_id = responsibleOffices.value[0].id;
}

async function loadRequiredCatalogs() {
    catalogError.value = null;

    try {
        await documents.loadCatalogs();
        setSensibleDefaults();
    } catch (exception) {
        catalogError.value = exception.message;
    }
}

watch(() => props.open, async (open) => {
    if (open) {
        error.value = null;
        catalogError.value = null;
        exceptionalEntry.value = false;
        deadlineEnabled.value = false;
        queuedFiles.value = [];
        const institutionalDate = currentInstitutionalDate();
        Object.assign(form, {
            confidentiality_level_id: '',
            subject: '',
            summary: '',
            origin: 'internal',
            sender_type: 'person',
            sender_name: '',
            responsible_office_id: '',
            received_on: institutionalDate,
            priority: 'normal',
            due_on: '',
            observations: '',
            derive_after_registration: true,
            primary_office_ids: [],
            primary_recipient_attention: {},
            copy_office_ids: [],
            requires_response: true,
            document_type_id: '',
            origin_document_number: '',
            origin_document_date: institutionalDate,
            content: '',
        });
        setSensibleDefaults();
        if (!documents.catalogsLoaded) await loadRequiredCatalogs();
    }
});

watch([
    responsibleOffices,
    () => documents.catalogs.confidentialityLevels.length,
    () => documents.catalogs.documentTypes.length,
], setSensibleDefaults);

watch(() => form.origin, (origin) => {
    if (origin === 'internal') {
        form.sender_type = 'person';
        form.sender_name = '';
    }
    setSensibleDefaults();
});

watch(canRegisterExternalDocuments, (allowed) => {
    if (!allowed && form.origin === 'external') form.origin = 'internal';
});

watch(deadlineEnabled, (enabled) => {
    // Desactivar el plazo también elimina cualquier fecha elegida previamente.
    if (!enabled) form.due_on = '';
});

watch(selectedResponsibleOfficeId, (officeId) => {
    form.primary_office_ids = form.primary_office_ids.filter((id) => Number(id) !== officeId);
    form.copy_office_ids = form.copy_office_ids.filter((id) => Number(id) !== officeId);
});

watch(() => [...form.primary_office_ids], (primaryOfficeIds) => {
    const primaryIds = new Set(primaryOfficeIds.map(Number));
    form.copy_office_ids = form.copy_office_ids.filter((id) => !primaryIds.has(Number(id)));

    Object.keys(form.primary_recipient_attention).forEach((officeId) => {
        if (!primaryIds.has(Number(officeId))) delete form.primary_recipient_attention[officeId];
    });
});

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function queueFiles(event) {
    queuedFiles.value = [...queuedFiles.value, ...Array.from(event.target.files ?? [])];
    event.target.value = '';
}

function removeQueuedFile(index) {
    queuedFiles.value.splice(index, 1);
}

function positiveIntegerId(value) {
    const id = Number(value);

    return Number.isInteger(id) && id > 0 ? id : null;
}

function caucusAttentionOptions(body) {
    return [
        { value: '', label: 'Bancada en general' },
        ...(body.members ?? []).map((member) => ({ value: member.legislature_member_id, label: member.name })),
    ];
}

async function submit() {
    // FormData permite enviar los campos estructurados y múltiples binarios en la misma solicitud.
    error.value = null;

    const responsibleOfficeId = positiveIntegerId(form.responsible_office_id);
    const documentTypeId = positiveIntegerId(form.document_type_id);

    if (!responsibleOfficeId) {
        error.value = 'No fue posible determinar la oficina responsable. Actualice la sesión o solicite a Recursos Humanos verificar su asignación vigente.';
        return;
    }

    if (!exceptionalEntry.value && !documentTypeId) {
        error.value = 'Seleccione el tipo de documento que inicia el trámite.';
        return;
    }

    if (!exceptionalEntry.value) form.derive_after_registration = true;

    if (form.derive_after_registration && !form.primary_office_ids.length) {
        error.value = 'Seleccione al menos una oficina destinataria principal para derivar el expediente.';
        return;
    }

    try {
        const data = {
            ...form,
            confidentiality_level_id: positiveIntegerId(form.confidentiality_level_id),
            responsible_office_id: responsibleOfficeId,
            summary: form.summary || null,
            sender_type: form.origin === 'external' ? form.sender_type : null,
            sender_name: form.origin === 'external' ? form.sender_name : null,
            due_on: form.due_on || null,
            observations: form.observations || null,
        };
        delete data.derive_after_registration;
        delete data.primary_office_ids;
        delete data.primary_recipient_attention;
        delete data.copy_office_ids;

        if (form.derive_after_registration) {
            data.primary_office_ids = form.primary_office_ids;
            data.primary_recipient_attention = Object.fromEntries(Object.entries(form.primary_recipient_attention).filter(([, value]) => value));
            data.copy_office_ids = form.copy_office_ids;
        }

        if (exceptionalEntry.value) {
            await documents.createExpedient(data);
        } else {
            await documents.createDocumentedExpedient({
                ...data,
                document_type_id: documentTypeId,
                origin_document_number: form.origin_document_number,
                origin_document_date: form.origin_document_date,
                content: form.content || null,
            }, queuedFiles.value);
        }
        emit('created');
    } catch (exception) {
        error.value = exception.message;
    }
}
</script>

<template>
    <div v-if="open" class="modal-backdrop" role="presentation" @mousedown.self="emit('close')">
        <section class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="create-expedient-title">
            <header class="modal__header">
                <div>
                    <p class="eyebrow">Nuevo expediente</p>
                    <h2 id="create-expedient-title">{{ exceptionalEntry ? 'Registrar expediente excepcional' : 'Registrar expediente documentado' }}</h2>
                    <p class="muted">{{ exceptionalEntry ? 'Use esta opción solo cuando el trámite deba abrirse sin documentación inicial.' : 'El documento inicial abre el expediente y se conserva como su primer antecedente.' }}</p>
                </div>
                <button class="icon-button" type="button" aria-label="Cerrar" @click="emit('close')">×</button>
            </header>

            <form class="form-grid" @submit.prevent="submit">
                <p v-if="error" class="alert alert--error form-grid__full" role="alert">{{ error }}</p>
                <div v-if="documents.busy.catalogs" class="entry-mode-notice form-grid__full" role="status"><p><strong>Cargando datos institucionales…</strong> Estamos consultando tipos documentales, confidencialidad y oficinas.</p></div>
                <div v-else-if="catalogError || documents.catalogLoadError" class="alert alert--error form-grid__full" role="alert"><span>{{ catalogError || documents.catalogLoadError }}</span> <button class="text-button" type="button" @click="loadRequiredCatalogs">Reintentar carga</button></div>

                <div class="entry-mode-notice form-grid__full">
                    <p v-if="!exceptionalEntry"><strong>Flujo normal:</strong> registre la nota, circular, informe o requerimiento que inicia el trámite.</p>
                    <p v-else><strong>Atención:</strong> este expediente quedará sin documento inicial hasta que se incorpore documentación por el flujo correspondiente.</p>
                    <button class="text-button" type="button" @click="exceptionalEntry = !exceptionalEntry">{{ exceptionalEntry ? 'Volver al registro documentado' : 'Registrar excepcionalmente sin documento' }}</button>
                </div>

                <label v-if="hasManyConfidentialityLevels" class="field">
                    <span>Confidencialidad</span>
                    <SearchableSelect v-model="form.confidentiality_level_id" :options="documents.catalogs.confidentialityLevels.map((level) => ({ value: level.id, label: level.name }))" placeholder="Seleccione un nivel" />
                    <select v-show="false" v-model="form.confidentiality_level_id" disabled><option v-for="level in documents.catalogs.confidentialityLevels" :key="level.id" :value="level.id">{{ level.name }}</option></select>
                    <small v-if="selectedConfidentiality?.requires_explicit_access">El acceso deberá concederse expresamente después del registro.</small>
                    <small v-else>{{ selectedConfidentiality?.code === 'PUBLIC_INSTITUTIONAL' ? 'Para información institucional de consulta amplia.' : 'Interno es el nivel normal para el flujo institucional.' }}</small>
                </label>
                <div v-else class="field field--static"><span>Confidencialidad</span><strong>{{ documents.catalogs.confidentialityLevels[0]?.name || (documents.catalogsLoaded ? 'Sin niveles activos' : 'Catálogo pendiente de carga') }}</strong><small v-if="documents.catalogs.confidentialityLevels.length">Interno es el nivel normal. Reservado y Confidencial requieren autorización explícita.</small></div>

                <label class="field">
                    <span>Referencia</span>
                    <textarea v-model.trim="form.subject" rows="3" maxlength="255" placeholder="Indique la referencia principal de la documentación" required></textarea>
                </label>
                <label class="field"><span>Prioridad</span><SearchableSelect v-model="form.priority" :options="[{ value: 'normal', label: 'Normal' }, { value: 'high', label: 'Alta' }, { value: 'urgent', label: 'Urgente' }]" /></label>

                <template v-if="!exceptionalEntry">
                    <label v-if="hasManyDocumentTypes" class="field">
                        <span>Documento que inicia el trámite</span>
                        <SearchableSelect v-model="form.document_type_id" :options="documents.catalogs.documentTypes.map((type) => ({ value: type.id, label: type.name }))" placeholder="Seleccione el tipo documental" />
                        <select v-show="false" v-model="form.document_type_id" disabled><option value="" disabled>Seleccione el tipo documental</option><option v-for="type in documents.catalogs.documentTypes" :key="type.id" :value="type.id">{{ type.name }}</option></select>
                        <small>Identifica la nota, informe, circular, solicitud u otro documento que abre este expediente.</small>
                    </label>
                    <div v-else class="field field--static"><span>Documento que inicia el trámite</span><strong>{{ documents.catalogs.documentTypes[0]?.name || (documents.catalogsLoaded ? 'No hay tipos activos' : 'Catálogo pendiente de carga') }}</strong></div>
                    <label class="field"><span>Número o CITE del documento</span><input v-model.trim="form.origin_document_number" maxlength="255" placeholder="Ej.: CITE: 024/2026" required></label>
                </template>

                <label class="field form-grid__full">
                    <span>Nota <small>Opcional</small></span>
                    <textarea v-model.trim="form.summary" rows="3" placeholder="Describa brevemente el motivo de la documentación"></textarea>
                </label>

                <label v-if="canRegisterExternalDocuments" class="field form-grid__full">
                    <span>Procedencia del documento</span>
                    <SearchableSelect v-model="form.origin" :options="[{ value: 'internal', label: 'Documento interno de mi oficina' }, { value: 'external', label: 'Documento recibido de persona o institución externa' }]" />
                    <select v-show="false" v-model="form.origin" disabled><option value="internal">Documento interno de mi oficina</option><option value="external">Documento recibido de persona o institución externa</option></select>
                    <small>El usuario que registra y su oficina se guardan automáticamente. Solo identifique remitente cuando el documento sea externo.</small>
                </label>

                <template v-if="form.origin === 'external'">
                    <label class="field"><span>Tipo de remitente externo</span><SearchableSelect v-model="form.sender_type" :options="[{ value: 'person', label: 'Persona' }, { value: 'organization', label: 'Institución u organización' }]" /><select v-show="false" v-model="form.sender_type" disabled><option value="person">Persona</option><option value="organization">Institución u organización</option></select></label>
                    <label class="field"><span>Nombre del remitente externo</span><input v-model.trim="form.sender_name" maxlength="255" placeholder="Nombre de la persona o institución" required></label>
                </template>

                <label v-if="hasManyResponsibleOffices" class="field form-grid__full">
                    <span>Oficina que registra y queda responsable</span>
                    <SearchableSelect v-model="form.responsible_office_id" :options="responsibleOffices.map((office) => ({ value: office.id, label: office.hierarchy_label }))" placeholder="Seleccione la oficina a cargo" />
                    <select v-show="false" v-model="form.responsible_office_id" disabled><option value="" disabled>Seleccione la oficina a cargo</option><option v-for="office in responsibleOffices" :key="office.id" :value="office.id">{{ office.hierarchy_label }}</option></select>
                </label>
                <div v-else class="field field--static form-grid__full"><span>Oficina que registra y queda responsable</span><strong>{{ currentOfficeDisplay ? `${currentOfficeDisplay.code} · ${currentOfficeDisplay.name}` : 'No tiene una oficina vigente asignada' }}</strong><small v-if="!currentOfficeDisplay">Recursos Humanos debe registrar o corregir el contrato, cargo y oficina de su kardex.</small></div>
                <div v-if="form.origin === 'internal' && currentOfficeDisplay" class="entry-mode-notice form-grid__full"><p><strong>Registro interno:</strong> SIGAL dejará constancia de {{ currentOfficeDisplay.name }} como oficina remitente y de {{ session.user?.name }} como usuario que realizó el registro.</p></div>

                <div class="optional-deadline">
                    <label class="check-option"><input v-model="deadlineEnabled" type="checkbox"><span><strong>Definir fecha límite</strong><small>Opcional. Actívela únicamente cuando la documentación tenga un plazo concreto.</small></span></label>
                    <label v-if="deadlineEnabled" class="field"><span>Fecha límite</span><input v-model="form.due_on" type="date" :min="form.received_on || undefined" required></label>
                </div>

                <div class="entry-mode-notice form-grid__full">
                    <label v-if="exceptionalEntry" class="check-option"><input v-model="form.derive_after_registration" type="checkbox"><span><strong>Derivar al registrar</strong><small>El expediente excepcional se abrirá y enviará directamente a la oficina seleccionada.</small></span></label>
                    <p v-else><strong>Derivación obligatoria:</strong> el documento inicial quedará vinculado y se enviará a la oficina que seleccione.</p>
                </div>
                <template v-if="form.derive_after_registration">
                    <label class="field form-grid__full"><span>Destinatarios principales <small>Obligatorio</small></span><SearchableMultiSelect v-model="form.primary_office_ids" :options="primaryRecipientOptions" placeholder="Buscar y seleccionar oficinas destinatarias" /></label>
                    <div v-if="selectedCaucusRecipients.length" class="action-form form-grid__full"><div class="form-grid__full"><p class="eyebrow">Distribución legislativa</p><h4>Atención dentro de la bancada</h4><p class="muted">Puede dirigirla a toda la bancada o exclusivamente a uno de sus asambleístas.</p></div><label v-for="body in selectedCaucusRecipients" :key="body.id" class="field"><span>{{ body.name }}</span><SearchableSelect v-model="form.primary_recipient_attention[body.office_id]" :options="caucusAttentionOptions(body)" /></label></div>
                    <label class="field form-grid__full"><span>En copia <small>Opcional</small></span><SearchableMultiSelect v-model="form.copy_office_ids" :options="copyRecipientOptions" placeholder="Buscar y seleccionar oficinas en copia" /></label>
                    <div class="entry-mode-notice form-grid__full"><label class="check-option"><input v-model="form.requires_response" type="checkbox"><span><strong>Exige respuesta</strong><small>Está activado por defecto. Si lo desactiva, el documento llegará a los destinatarios solo para conocimiento y no les quedará una respuesta pendiente.</small></span></label></div>
                    <p class="form-note form-grid__full">La derivación conservará la prioridad y el plazo registrados en este expediente.</p>
                </template>

                <template v-if="!exceptionalEntry">
                    <label class="field form-grid__full"><span>Contenido del documento <small>Opcional si adjunta archivos</small></span><RichTextEditor v-model="form.content" placeholder="Transcriba o redacte el contenido del documento de ingreso si corresponde…" /></label>
                    <div class="file-dropzone form-grid__full"><div><strong>Adjuntar documentación</strong><p>Agregue una o varias imágenes, videos, PDFs, audios u otros archivos. Debe redactar contenido o adjuntar al menos un archivo.</p></div><label class="button button--ghost">Seleccionar archivos<input type="file" multiple @change="queueFiles"></label></div>
                    <div v-if="queuedFiles.length" class="queued-files form-grid__full"><div v-for="(file, index) in queuedFiles" :key="`${file.name}-${file.lastModified}-${index}`"><span>{{ file.name }}</span><small>{{ formatSize(file.size) }}</small><button type="button" aria-label="Quitar archivo" @click="removeQueuedFile(index)">×</button></div></div>
                </template>

                <footer class="modal__actions form-grid__full">
                    <button class="button button--secondary" type="button" @click="emit('close')">Cancelar</button>
                    <button class="button button--primary" :disabled="!documents.catalogsLoaded || documents.busy.catalogs || documents.busy[exceptionalEntry ? 'create-expedient' : 'create-entry'] || (form.derive_after_registration && !form.primary_office_ids.length)" type="submit">{{ exceptionalEntry ? (documents.busy['create-expedient'] ? 'Registrando…' : form.derive_after_registration ? 'Registrar y derivar expediente' : 'Registrar expediente excepcional') : (documents.busy['create-entry'] ? 'Registrando…' : 'Registrar y derivar expediente') }}</button>
                </footer>
            </form>
        </section>
    </div>
</template>
