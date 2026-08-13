<script setup>
/**
 * Alta normal de gestión documental: reúne documento inicial, metadatos del
 * expediente, archivos y destinatarios en una sola intención del usuario.
 */
import { computed, reactive, ref, watch } from 'vue';
import { flattenOfficeHierarchy } from '../lib/organization';
import RichTextEditor from './RichTextEditor.vue';
import SearchableSelect from './SearchableSelect.vue';
import { useDocumentManagementStore } from '../stores/document-management';
import { useSessionStore } from '../stores/session';

const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'created']);
const documents = useDocumentManagementStore();
const session = useSessionStore();
const error = ref(null);
const exceptionalEntry = ref(false);
const queuedFiles = ref([]);

const today = new Date().toISOString().slice(0, 10);
const form = reactive({
    expedient_type_id: '',
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
    derive_after_registration: false,
    primary_office_ids: [],
    copy_office_ids: [],
    requires_response: true,
    document_type_id: '',
    origin_document_number: '',
    origin_document_date: '',
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
const currentOfficeDisplay = computed(() => responsibleOffices.value[0] ?? myOfficeMemberships.value[0]?.office ?? null);
const hasManyConfidentialityLevels = computed(() => documents.catalogs.confidentialityLevels.length > 1);
const hasManyExpedientTypes = computed(() => documents.catalogs.expedientTypes.length > 1);
const hasManyDocumentTypes = computed(() => documents.catalogs.documentTypes.length > 1);
const hasManyResponsibleOffices = computed(() => responsibleOffices.value.length > 1);
const selectedResponsibleOfficeId = computed(() => positiveIntegerId(form.responsible_office_id));

function setSensibleDefaults() {
    // Solo se autoselecciona una opción cuando no existe una decisión real que tomar.
    const internal = documents.catalogs.confidentialityLevels.find((level) => level.code === 'INTERNAL');
    const defaultLevel = internal ?? documents.catalogs.confidentialityLevels[0];
    const defaultExpedientCode = form.origin === 'internal' ? 'INTERNAL_CORRESPONDENCE' : 'EXTERNAL_CORRESPONDENCE';
    const defaultDocumentCode = form.origin === 'internal' ? 'INTERNAL_NOTE' : 'OFFICIAL_LETTER';
    const defaultExpedient = documents.catalogs.expedientTypes.find((type) => type.code === defaultExpedientCode)
        ?? documents.catalogs.expedientTypes[0];
    const defaultDocument = documents.catalogs.documentTypes.find((type) => type.code === defaultDocumentCode)
        ?? documents.catalogs.documentTypes[0];

    if (!form.confidentiality_level_id && defaultLevel) form.confidentiality_level_id = defaultLevel.id;
    if (!form.expedient_type_id && defaultExpedient) form.expedient_type_id = defaultExpedient.id;
    if (!form.document_type_id && defaultDocument) form.document_type_id = defaultDocument.id;
    if (!form.responsible_office_id && responsibleOffices.value.length === 1) form.responsible_office_id = responsibleOffices.value[0].id;
}

watch(() => props.open, (open) => {
    if (open) {
        error.value = null;
        exceptionalEntry.value = false;
        queuedFiles.value = [];
        form.derive_after_registration = false;
        form.primary_office_ids = [];
        form.copy_office_ids = [];
        form.requires_response = true;
        setSensibleDefaults();
    }
});

watch([
    responsibleOffices,
    () => documents.catalogs.confidentialityLevels.length,
    () => documents.catalogs.expedientTypes.length,
    () => documents.catalogs.documentTypes.length,
], setSensibleDefaults);

watch(() => form.origin, (origin) => {
    if (origin === 'internal') {
        form.sender_type = 'person';
        form.sender_name = '';
    }
    setSensibleDefaults();
});

watch(selectedResponsibleOfficeId, (officeId) => {
    form.primary_office_ids = form.primary_office_ids.filter((id) => Number(id) !== officeId);
    form.copy_office_ids = form.copy_office_ids.filter((id) => Number(id) !== officeId);
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

function toggleRecipient(list, id) {
    const numericId = Number(id);
    const index = list.indexOf(numericId);
    if (index >= 0) list.splice(index, 1);
    else list.push(numericId);
}

async function submit() {
    // FormData permite enviar los campos estructurados y múltiples binarios en la misma solicitud.
    error.value = null;

    const expedientTypeId = positiveIntegerId(form.expedient_type_id);
    const responsibleOfficeId = positiveIntegerId(form.responsible_office_id);
    const documentTypeId = positiveIntegerId(form.document_type_id);

    if (!expedientTypeId) {
        error.value = 'Seleccione un tipo de expediente antes de registrar el ingreso.';
        return;
    }

    if (!responsibleOfficeId) {
        error.value = 'No fue posible determinar la oficina responsable. Actualice la sesión o solicite a Recursos Humanos verificar su asignación vigente.';
        return;
    }

    if (!exceptionalEntry.value && !documentTypeId) {
        error.value = 'Seleccione el tipo de documento que inicia el trámite.';
        return;
    }

    if (form.derive_after_registration && !form.primary_office_ids.length) {
        error.value = 'Seleccione al menos una oficina destinataria principal para derivar el expediente.';
        return;
    }

    try {
        const data = {
            ...form,
            expedient_type_id: expedientTypeId,
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
        delete data.copy_office_ids;

        if (form.derive_after_registration) {
            data.primary_office_ids = form.primary_office_ids;
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
                    <p class="eyebrow">Nuevo registro</p>
                    <h2 id="create-expedient-title">{{ exceptionalEntry ? 'Registrar expediente excepcional' : 'Registrar ingreso documentado' }}</h2>
                    <p class="muted">{{ exceptionalEntry ? 'Use esta opción solo cuando el trámite deba abrirse sin documentación inicial.' : 'El documento de ingreso abre el expediente y se conserva como su antecedente inicial.' }}</p>
                </div>
                <button class="icon-button" type="button" aria-label="Cerrar" @click="emit('close')">×</button>
            </header>

            <form class="form-grid" @submit.prevent="submit">
                <p v-if="error" class="alert alert--error form-grid__full" role="alert">{{ error }}</p>

                <div class="entry-mode-notice form-grid__full">
                    <p v-if="!exceptionalEntry"><strong>Flujo normal:</strong> registre la nota, circular, informe o requerimiento que inicia el trámite.</p>
                    <p v-else><strong>Atención:</strong> este expediente quedará sin documento inicial hasta que se incorpore documentación por el flujo correspondiente.</p>
                    <button class="text-button" type="button" @click="exceptionalEntry = !exceptionalEntry">{{ exceptionalEntry ? 'Volver al registro documentado' : 'Registrar excepcionalmente sin documento' }}</button>
                </div>

                <label v-if="hasManyExpedientTypes" class="field">
                    <span>Tipo de expediente</span>
                    <SearchableSelect v-model="form.expedient_type_id" :options="documents.catalogs.expedientTypes.map((type) => ({ value: type.id, label: `${type.name} · ${type.category}` }))" placeholder="Seleccione el trámite" />
                    <select v-show="false" v-model="form.expedient_type_id" disabled><option value="" disabled>Seleccione el trámite</option><option v-for="type in documents.catalogs.expedientTypes" :key="type.id" :value="type.id">{{ type.name }} · {{ type.category }}</option></select>
                    <small>Clasifica el trámite completo; no es el tipo de nota, informe o circular que lo inicia.</small>
                </label>
                <div v-else class="field field--static"><span>Tipo de expediente</span><strong>{{ documents.catalogs.expedientTypes[0]?.name || 'No hay tipos activos' }}</strong><small v-if="!documents.catalogs.expedientTypes.length">Solicite a un superadministrador que active un tipo de expediente.</small></div>

                <label v-if="hasManyConfidentialityLevels" class="field">
                    <span>Confidencialidad</span>
                    <SearchableSelect v-model="form.confidentiality_level_id" :options="documents.catalogs.confidentialityLevels.map((level) => ({ value: level.id, label: level.name }))" placeholder="Seleccione un nivel" />
                    <select v-show="false" v-model="form.confidentiality_level_id" disabled><option v-for="level in documents.catalogs.confidentialityLevels" :key="level.id" :value="level.id">{{ level.name }}</option></select>
                    <small v-if="selectedConfidentiality?.requires_explicit_access">El acceso deberá concederse expresamente después del registro.</small>
                    <small v-else>{{ selectedConfidentiality?.code === 'PUBLIC_INSTITUTIONAL' ? 'Para información institucional de consulta amplia.' : 'Interno es el nivel normal para el flujo institucional.' }}</small>
                </label>
                <div v-else class="field field--static"><span>Confidencialidad</span><strong>{{ documents.catalogs.confidentialityLevels[0]?.name || 'Interno' }}</strong><small>Interno es el nivel normal. Reservado y Confidencial requieren autorización explícita.</small></div>

                <label class="field form-grid__full">
                    <span>Asunto</span>
                    <input v-model.trim="form.subject" maxlength="255" placeholder="Describa el asunto principal" required>
                </label>

                <template v-if="!exceptionalEntry">
                    <label v-if="hasManyDocumentTypes" class="field">
                        <span>Documento que inicia el trámite</span>
                        <SearchableSelect v-model="form.document_type_id" :options="documents.catalogs.documentTypes.map((type) => ({ value: type.id, label: type.name }))" placeholder="Seleccione el tipo documental" />
                        <select v-show="false" v-model="form.document_type_id" disabled><option value="" disabled>Seleccione el tipo documental</option><option v-for="type in documents.catalogs.documentTypes" :key="type.id" :value="type.id">{{ type.name }}</option></select>
                        <small>Identifica la nota, informe, circular, solicitud u otro documento que abre este expediente.</small>
                    </label>
                    <div v-else class="field field--static"><span>Documento que inicia el trámite</span><strong>{{ documents.catalogs.documentTypes[0]?.name || 'No hay tipos activos' }}</strong></div>
                    <label class="field"><span>Número o CITE del documento</span><input v-model.trim="form.origin_document_number" maxlength="255" placeholder="Ej.: CITE: 024/2026" required></label>
                    <label class="field"><span>Fecha del documento</span><input v-model="form.origin_document_date" type="date" required></label>
                </template>

                <label class="field form-grid__full">
                    <span>Resumen <small>Opcional</small></span>
                    <textarea v-model.trim="form.summary" rows="3" placeholder="Antecedentes y propósito del trámite"></textarea>
                </label>

                <label class="field form-grid__full">
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

                <label class="field"><span>Fecha de recepción</span><input v-model="form.received_on" type="date" required></label>
                <label class="field"><span>Prioridad</span><SearchableSelect v-model="form.priority" :options="[{ value: 'normal', label: 'Normal' }, { value: 'high', label: 'Alta' }, { value: 'urgent', label: 'Urgente' }]" /></label>
                <label class="field"><span>Fecha límite</span><input v-model="form.due_on" type="date" :min="form.received_on || undefined"></label>
                <label class="field form-grid__full"><span>Instrucción general <small>Opcional</small></span><textarea v-model.trim="form.observations" rows="2" placeholder="Actuación que se espera completar durante el trámite"></textarea></label>

                <div class="entry-mode-notice form-grid__full">
                    <label class="check-option"><input v-model="form.derive_after_registration" type="checkbox"><span><strong>Derivar al registrar</strong><small>{{ exceptionalEntry ? 'El expediente se abrirá y enviará directamente a la oficina seleccionada.' : 'El documento inicial se vinculará automáticamente a la derivación.' }}</small></span></label>
                </div>
                <template v-if="form.derive_after_registration">
                    <div class="recipient-picker form-grid__full"><span>Destinatarios principales</span><div><label v-for="office in hierarchicalOffices.filter((item) => Number(item.id) !== selectedResponsibleOfficeId)" :key="office.id" class="check-option"><input type="checkbox" :checked="form.primary_office_ids.includes(office.id)" @change="toggleRecipient(form.primary_office_ids, office.id)"><span>{{ office.hierarchy_label }}</span></label></div></div>
                    <div class="recipient-picker form-grid__full"><span>En copia (opcional)</span><div><label v-for="office in hierarchicalOffices.filter((item) => Number(item.id) !== selectedResponsibleOfficeId && !form.primary_office_ids.includes(item.id))" :key="office.id" class="check-option"><input type="checkbox" :checked="form.copy_office_ids.includes(office.id)" @change="toggleRecipient(form.copy_office_ids, office.id)"><span>{{ office.hierarchy_label }}</span></label></div></div>
                    <div class="entry-mode-notice form-grid__full"><label class="check-option"><input v-model="form.requires_response" type="checkbox"><span><strong>Exige respuesta</strong><small>Está activado por defecto. Si lo desactiva, el documento llegará a los destinatarios solo para conocimiento y no les quedará una respuesta pendiente.</small></span></label></div>
                    <p class="form-note form-grid__full">La derivación conservará la prioridad, plazo e instrucción general registrados en este expediente.</p>
                </template>

                <template v-if="!exceptionalEntry">
                    <label class="field form-grid__full"><span>Contenido del documento <small>Opcional si adjunta archivos</small></span><RichTextEditor v-model="form.content" placeholder="Transcriba o redacte el contenido del documento de ingreso si corresponde…" /></label>
                    <div class="file-dropzone form-grid__full"><div><strong>Adjuntar documento de origen</strong><p>Agregue una o varias imágenes, videos, PDFs, audios u otros archivos. Debe redactar contenido o adjuntar al menos un archivo.</p></div><label class="button button--ghost">Seleccionar archivos<input type="file" multiple @change="queueFiles"></label></div>
                    <div v-if="queuedFiles.length" class="queued-files form-grid__full"><div v-for="(file, index) in queuedFiles" :key="`${file.name}-${file.lastModified}-${index}`"><span>{{ file.name }}</span><small>{{ formatSize(file.size) }}</small><button type="button" aria-label="Quitar archivo" @click="removeQueuedFile(index)">×</button></div></div>
                </template>

                <footer class="modal__actions form-grid__full">
                    <button class="button button--secondary" type="button" @click="emit('close')">Cancelar</button>
                    <button class="button button--primary" :disabled="documents.busy[exceptionalEntry ? 'create-expedient' : 'create-entry'] || (form.derive_after_registration && !form.primary_office_ids.length)" type="submit">{{ exceptionalEntry ? (documents.busy['create-expedient'] ? 'Registrando…' : form.derive_after_registration ? 'Registrar y derivar expediente' : 'Registrar expediente excepcional') : (documents.busy['create-entry'] ? 'Registrando…' : form.derive_after_registration ? 'Registrar, abrir y derivar' : 'Registrar ingreso y abrir expediente') }}</button>
                </footer>
            </form>
        </section>
    </div>
</template>
