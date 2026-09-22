<script setup>
/**
 * Workspace de RR. HH. para registrar, corregir y consultar el ciclo laboral
 * completo sin separar artificialmente kardex, contrato, cargo y cuenta.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import EmployeeAvatar from './EmployeeAvatar.vue';
import EmployeeBulkImportDialog from './EmployeeBulkImportDialog.vue';
import SearchableSelect from './SearchableSelect.vue';
import { useHumanResourcesStore } from '../stores/human-resources';

const humanResources = useHumanResourcesStore();
const search = ref('');
const recordOpen = ref(false);
const editOpen = ref(false);
const positionOpen = ref(false);
const positionEditingId = ref(null);
const existingEmployee = ref(null);
const credentials = ref(null);
const bulkImportOpen = ref(false);
const extensionDates = reactive({});
const files = reactive({ profile_photo: null, rejap_certificate: null, cenvi_certificate: null, electoral_registry_certificate: null });
const editFiles = reactive({ rejap_certificate: null, cenvi_certificate: null, electoral_registry_certificate: null, other_supporting_document: null });
const form = reactive(newForm());
const editForm = reactive(newPersonalForm());
const positionForm = reactive({ office_id: null, name: '', membership_role: 'official' });
let searchTimer = null;

const academicSuggestions = ['Licenciatura', 'Egresado', 'Tecnico Superior', 'Tecnico Medio', 'Bachiller', 'Maestria', 'Doctorado'];
const officeOptions = computed(() => humanResources.bootstrap.offices.map((office) => ({ value: office.id, label: `${office.code} - ${office.name}` })));
const positionOptions = computed(() => (humanResources.positionsByOffice[form.office_id] ?? []).map((position) => ({ value: position.id, label: `${position.name} - ${position.membership_role_label}` })));
const selectedPosition = computed(() => (humanResources.positionsByOffice[form.office_id] ?? [])
    .find((position) => position.id === Number(form.office_position_id)) ?? null);
const positionIsManager = computed({
    get: () => positionForm.membership_role === 'manager',
    set: (value) => { positionForm.membership_role = value ? 'manager' : 'official'; },
});

function newPersonalForm() {
    return {
        identity_card: '', first_names: '', last_names: '', mobile_phone: '', email: '', address: '', cua_number: '',
        birth_date: '', military_service_booklet: '', academic_degree: '', profession: '', blood_type: '', emergency_contact: '',
    };
}

function newForm() {
    return {
        ...newPersonalForm(), contract_type: 'eventual', contract_amount: '', starts_on: new Date().toISOString().slice(0, 10),
        ends_on: '', office_id: null, office_position_id: null, role: 'simple_user',
    };
}

function personalAttributes(employee) {
    return {
        identity_card: employee.identity_card,
        first_names: employee.first_names,
        last_names: employee.last_names,
        mobile_phone: employee.mobile_phone,
        email: employee.email || '',
        address: employee.address || '',
        cua_number: employee.cua_number || '',
        birth_date: employee.birth_date,
        military_service_booklet: employee.military_service_booklet || '',
        academic_degree: employee.academic_degree,
        profession: employee.profession,
        blood_type: employee.blood_type || '',
        emergency_contact: employee.emergency_contact || '',
    };
}

function resetRecord() {
    Object.assign(form, newForm());
    Object.assign(files, { profile_photo: null, rejap_certificate: null, cenvi_certificate: null, electoral_registry_certificate: null });
}

function resetEdit(employee) {
    Object.assign(editForm, newPersonalForm(), personalAttributes(employee));
    Object.assign(editFiles, { rejap_certificate: null, cenvi_certificate: null, electoral_registry_certificate: null, other_supporting_document: null });
}

onMounted(() => Promise.all([humanResources.loadBootstrap(), humanResources.loadEmployees()]).catch(() => {}));
onBeforeUnmount(() => clearTimeout(searchTimer));

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => humanResources.loadEmployees({ search: value }).catch(() => {}), 250);
});

watch(() => form.office_id, async (officeId, previousOfficeId) => {
    // El catálogo de cargos depende de la oficina y se invalida al cambiarla.
    if (officeId !== previousOfficeId) form.office_position_id = null;
    if (officeId) await humanResources.loadPositions(officeId);
});

function uppercase(formData, field) {
    // Normaliza mientras se escribe; el backend repite la normalización por seguridad.
    formData[field] = formData[field].toLocaleUpperCase('es-BO');
}

async function verifyIdentityCard() {
    // Consultar el CI antes del alta permite reutilizar funcionarios de gestiones anteriores.
    existingEmployee.value = await humanResources.lookupByIdentity(form.identity_card);
    if (existingEmployee.value) Object.assign(form, personalAttributes(existingEmployee.value));
}

function openRecord(employee = null) {
    existingEmployee.value = employee;
    resetRecord();
    if (employee) Object.assign(form, personalAttributes(employee));
    recordOpen.value = true;
}

function closeRecord() {
    recordOpen.value = false;
    existingEmployee.value = null;
}

function openEdit(employee) {
    resetEdit(employee);
    editOpen.value = true;
}

function selectFile(event, collection, field) {
    collection[field] = event.target.files?.[0] ?? null;
}

function fileLabel(collection, field, fallback = 'Adjuntar archivo') {
    return collection[field]?.name || fallback;
}

async function submitRecord() {
    const body = new FormData();
    Object.entries(form).forEach(([key, value]) => { if (value !== null && value !== '') body.append(key, value); });
    Object.entries(files).forEach(([key, file]) => { if (file) body.append(key, file); });
    const payload = await humanResources.registerEmployee(body);
    credentials.value = payload.credentials;
    closeRecord();
}

async function submitEdit() {
    if (!humanResources.selected) return;
    const employeeId = humanResources.selected.id;
    await humanResources.updateEmployee(employeeId, editForm);
    for (const [documentType, file] of Object.entries(editFiles)) {
        if (file) await humanResources.uploadAttachment(employeeId, documentType, file);
    }
    editOpen.value = false;
}

function openPosition(position = null) {
    positionEditingId.value = position?.id ?? null;
    positionForm.office_id = position?.office_id ?? form.office_id;
    positionForm.name = position?.name ?? '';
    positionForm.membership_role = position?.membership_role ?? 'official';
    positionOpen.value = true;
}

async function savePosition() {
    const data = positionEditingId.value === null
        ? positionForm
        : { name: positionForm.name, membership_role: positionForm.membership_role };
    const position = positionEditingId.value === null
        ? await humanResources.createPosition(data)
        : await humanResources.updatePosition(positionEditingId.value, data);
    form.office_id = position.office_id;
    form.office_position_id = position.id;
    positionOpen.value = false;
}

async function openEmployee(employee) {
    await humanResources.selectEmployee(employee);
}

async function uploadProfilePhoto(event) {
    const photo = event.target.files?.[0];
    if (!photo || !humanResources.selected) return;
    await humanResources.uploadProfilePhoto(humanResources.selected.id, photo);
    event.target.value = '';
}

async function extendContract(contract) {
    await humanResources.extendContract(contract.id, extensionDates[contract.id]);
    delete extensionDates[contract.id];
}

async function finishContract(contract) {
    if (!window.confirm(`Finalizar el contrato de ${humanResources.selected.full_name}? La cuenta quedara inactiva.`)) return;
    await humanResources.finishContract(contract.id);
}

function formatDate(value) {
    return value ? new Intl.DateTimeFormat('es-BO', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T12:00:00`)) : 'Sin fecha de finalizacion';
}

function formatAmount(value) {
    return value === null || value === undefined ? 'No definido' : new Intl.NumberFormat('es-BO', { style: 'currency', currency: 'BOB' }).format(value);
}
</script>

<template>
    <section class="workspace workspace--wide human-resources-workspace">
        <header class="page-heading">
            <div><p class="eyebrow">Recursos Humanos</p><h1>Kardex de funcionarios</h1><p class="muted">Registre, consulte y corrija la informacion historica de cada persona.</p></div>
            <div class="inline-actions"><button class="button button--ghost" type="button" @click="bulkImportOpen = true">Importar Excel</button><button class="button button--primary" type="button" @click="openRecord()">+ Registrar contratacion</button></div>
        </header>
        <p v-if="humanResources.error" class="alert alert--error" role="alert">{{ humanResources.error }}</p>

        <div class="human-resources-layout">
            <section class="panel employee-directory">
                <header class="directory-toolbar">
                    <label class="search-field"><span aria-hidden="true">Buscar</span><input v-model.trim="search" type="search" placeholder="CI, nombres o apellidos"></label>
                    <button class="button button--ghost" type="button" :disabled="humanResources.loading" @click="humanResources.loadEmployees({ search })">Actualizar</button>
                </header>
                <div v-if="humanResources.loading" class="empty-state">Actualizando kardex...</div>
                <div v-else-if="!humanResources.employees.length" class="empty-state">No se encontraron funcionarios.</div>
                <div v-else class="employee-list">
                    <button v-for="employee in humanResources.employees" :key="employee.id" class="employee-row" :class="{ 'is-selected': humanResources.selected?.id === employee.id }" type="button" @click="openEmployee(employee)">
                        <EmployeeAvatar :employee="employee" />
                        <span class="employee-row__content"><strong>{{ employee.full_name }}</strong><small>CI {{ employee.identity_card }} - {{ employee.profession }}</small><small v-if="employee.open_contract">{{ employee.open_contract.office_position?.office?.code }} - {{ employee.open_contract.office_position?.name }}</small></span>
                        <span class="employee-row__state" :class="employee.account?.status === 'active' ? 'is-active' : 'is-inactive'">{{ employee.account?.status_label || 'Sin cuenta' }}</span>
                    </button>
                </div>
                <footer v-if="humanResources.pagination?.last_page > 1" class="pagination">
                    <button class="button button--ghost" :disabled="humanResources.pagination.current_page <= 1" type="button" @click="humanResources.loadEmployees({ page: humanResources.pagination.current_page - 1, search })">Anterior</button>
                    <span>Pagina {{ humanResources.pagination.current_page }} de {{ humanResources.pagination.last_page }}</span>
                    <button class="button button--ghost" :disabled="humanResources.pagination.current_page >= humanResources.pagination.last_page" type="button" @click="humanResources.loadEmployees({ page: humanResources.pagination.current_page + 1, search })">Siguiente</button>
                </footer>
            </section>

            <aside class="panel employee-detail">
                <template v-if="humanResources.busy['employee-detail']"><div class="empty-state">Abriendo kardex...</div></template>
                <template v-else-if="!humanResources.selected"><div class="empty-state">Seleccione un funcionario para ver sus datos, cuenta y contratos.</div></template>
                <template v-else>
                    <header class="employee-detail__header">
                        <div class="employee-detail__identity"><EmployeeAvatar :employee="humanResources.selected" size="large" /><div><p class="eyebrow">Kardex individual</p><h2>{{ humanResources.selected.full_name }}</h2><p>CI {{ humanResources.selected.identity_card }} - {{ humanResources.selected.profession }}</p></div></div>
                        <div class="employee-detail__actions">
                            <label class="text-button profile-photo-button" :class="{ 'is-busy': humanResources.busy[`profile-photo-${humanResources.selected.id}`] }">{{ humanResources.busy[`profile-photo-${humanResources.selected.id}`] ? 'Actualizando foto...' : 'Actualizar foto' }}<input type="file" accept="image/jpeg,image/png,image/webp,image/avif" :disabled="humanResources.busy[`profile-photo-${humanResources.selected.id}`]" @change="uploadProfilePhoto"></label>
                            <button class="button button--ghost" type="button" @click="openEdit(humanResources.selected)">Editar kardex</button>
                            <button class="button button--ghost" type="button" @click="openRecord(humanResources.selected)">Nuevo contrato</button>
                        </div>
                    </header>
                    <div class="employee-facts"><div><span>Celular</span><strong>{{ humanResources.selected.mobile_phone }}</strong></div><div><span>Correo personal</span><strong>{{ humanResources.selected.email || 'No registrado' }}</strong></div><div><span>Grado academico</span><strong>{{ humanResources.selected.academic_degree }}</strong></div><div><span>Fecha de nacimiento</span><strong>{{ formatDate(humanResources.selected.birth_date) }}</strong></div></div>
                    <section class="account-card"><p class="eyebrow">Cuenta SIGAL</p><strong>{{ humanResources.selected.account?.email || 'Pendiente' }}</strong><span class="employee-row__state" :class="humanResources.selected.account?.status === 'active' ? 'is-active' : 'is-inactive'">{{ humanResources.selected.account?.status_label || 'Sin cuenta' }}</span><small>{{ humanResources.selected.account?.roles?.map((role) => role.name).join(' - ') || 'Sin roles vigentes' }}</small></section>
                    <section class="detail-subsection">
                        <div class="section-heading"><div><p class="eyebrow">Contratos</p><h3>Historial contractual</h3></div></div>
                        <div v-if="!humanResources.selected.contracts?.length" class="empty-state">No hay contratos registrados.</div>
                        <div v-else class="contract-list"><article v-for="contract in humanResources.selected.contracts" :key="contract.id" class="contract-card"><header><div><strong>{{ contract.contract_type_label }}</strong><small>{{ contract.office_position?.office?.name }} - {{ contract.office_position?.name }}</small></div><span class="badge" :class="`badge--contract-${contract.state}`">{{ contract.state_label }}</span></header><p>Inicio: {{ formatDate(contract.starts_on) }} - Fin: {{ formatDate(contract.ends_on) }} - Monto: {{ formatAmount(contract.contract_amount) }}</p><div v-if="!contract.ended_at && contract.state !== 'upcoming'" class="contract-card__actions"><template v-if="contract.ends_on"><input v-model="extensionDates[contract.id]" type="date" :min="contract.ends_on" aria-label="Nueva fecha de finalizacion"><button class="text-button" type="button" :disabled="!extensionDates[contract.id] || humanResources.busy[`extend-contract-${contract.id}`]" @click="extendContract(contract)">Extender</button></template><button class="text-button text-button--danger" type="button" :disabled="humanResources.busy[`finish-contract-${contract.id}`]" @click="finishContract(contract)">Finalizar</button></div></article></div>
                    </section>
                    <section class="detail-subsection">
                        <div class="section-heading"><div><p class="eyebrow">Respaldos</p><h3>Documentacion personal</h3></div><button class="text-button" type="button" @click="openEdit(humanResources.selected)">Actualizar respaldos</button></div>
                        <div v-if="!humanResources.selected.attachments?.length" class="empty-state">No hay documentos adjuntos.</div>
                        <div v-else class="attachment-list"><button v-for="attachment in humanResources.selected.attachments" :key="attachment.id" class="attachment-download" type="button" @click="humanResources.downloadAttachment(attachment)"><span><strong>{{ attachment.document_type_label }}</strong><small>{{ attachment.original_name }} - {{ formatDate(attachment.uploaded_at?.slice(0, 10)) }}</small></span><span>Descargar</span></button></div>
                    </section>
                </template>
            </aside>
        </div>
    </section>

    <div v-if="recordOpen" class="modal-backdrop" @click.self="closeRecord">
        <section class="modal modal--wide human-resources-modal" role="dialog" aria-modal="true" aria-labelledby="employee-record-title">
            <header class="modal__header"><div><p class="eyebrow">{{ existingEmployee ? 'Reincorporacion o nuevo contrato' : 'Nuevo kardex' }}</p><h2 id="employee-record-title">{{ existingEmployee ? existingEmployee.full_name : 'Registrar funcionario y contratacion' }}</h2><p class="muted">{{ existingEmployee ? 'Se reutilizara su kardex y su cuenta SIGAL.' : 'La cuenta de acceso se crea junto con el primer contrato.' }}</p></div><button class="icon-button" type="button" aria-label="Cerrar" @click="closeRecord">x</button></header>
            <form class="human-resources-form" @submit.prevent="submitRecord">
                <p v-if="humanResources.error" class="alert alert--error" role="alert">{{ humanResources.error }}</p>
                <section><div class="form-section-heading"><span>1</span><div><h3>Datos personales</h3><p>Los nombres y apellidos se guardan en mayusculas.</p></div></div><div class="form-grid">
                    <label class="field"><span>CI *</span><input v-model.trim="form.identity_card" maxlength="30" required @blur="verifyIdentityCard"></label>
                    <div v-if="existingEmployee" class="identity-match"><strong>Kardex existente encontrado</strong><span>Se reutilizara la cuenta {{ existingEmployee.account?.email || 'institucional existente' }}.</span></div>
                    <label class="field"><span>Nombres *</span><input v-model.trim="form.first_names" required maxlength="255" @input="uppercase(form, 'first_names')"></label><label class="field"><span>Apellidos *</span><input v-model.trim="form.last_names" required maxlength="255" @input="uppercase(form, 'last_names')"></label>
                    <label class="field"><span>Celular *</span><input v-model.trim="form.mobile_phone" required maxlength="40"></label><label class="field"><span>Correo</span><input v-model.trim="form.email" type="email" maxlength="255"></label>
                    <label class="field"><span>Fecha de nacimiento *</span><input v-model="form.birth_date" type="date" required></label><label class="field"><span>Numero de CUA</span><input v-model.trim="form.cua_number" maxlength="50"></label>
                    <label class="field"><span>Libreta de servicio militar</span><input v-model.trim="form.military_service_booklet" maxlength="100"></label><label class="field"><span>Grado academico *</span><input v-model.trim="form.academic_degree" list="academic-degrees" required maxlength="255"></label>
                    <label class="field"><span>Profesion *</span><input v-model.trim="form.profession" required maxlength="255"></label><label class="field"><span>Tipo de sangre</span><input v-model.trim="form.blood_type" list="blood-types" maxlength="20"></label>
                    <label class="field"><span>Contacto de emergencia</span><input v-model.trim="form.emergency_contact" maxlength="2000"></label><label class="field field--full"><span>Direccion</span><textarea v-model.trim="form.address" rows="2" maxlength="2000"></textarea></label>
                </div></section>
                <section><div class="form-section-heading"><span>2</span><div><h3>Fotografia y respaldos</h3><p>Opcional. Los archivos quedan vinculados al kardex y auditados.</p></div></div><div class="support-file-grid">
                    <label class="support-file support-file--photo"><span>Fotografia de perfil</span><strong>{{ fileLabel(files, 'profile_photo') }}</strong><input type="file" accept="image/jpeg,image/png,image/webp,image/avif" @change="selectFile($event, files, 'profile_photo')"></label>
                    <label class="support-file"><span>Certificado REJAP</span><strong>{{ fileLabel(files, 'rejap_certificate') }}</strong><input type="file" @change="selectFile($event, files, 'rejap_certificate')"></label>
                    <label class="support-file"><span>Certificado CENVI</span><strong>{{ fileLabel(files, 'cenvi_certificate') }}</strong><input type="file" @change="selectFile($event, files, 'cenvi_certificate')"></label>
                    <label class="support-file"><span>Padron biometrico electoral</span><strong>{{ fileLabel(files, 'electoral_registry_certificate') }}</strong><input type="file" @change="selectFile($event, files, 'electoral_registry_certificate')"></label>
                </div></section>
                <section><div class="form-section-heading"><span>3</span><div><h3>Contrato, oficina y acceso</h3><p>El cargo determina la pertenencia dentro del organigrama.</p></div></div><div class="form-grid">
                    <label class="field"><span>Tipo de contrato *</span><SearchableSelect v-model="form.contract_type" :options="[{ value: 'eventual', label: 'Eventual' }, { value: 'line_consultancy', label: 'Consultoria de Linea' }, { value: 'tgn', label: 'TGN' }, { value: 'functioning', label: 'Funcionamiento' }]" /></label><label class="field"><span>Monto contractual</span><input v-model="form.contract_amount" type="number" min="0" step="0.01"></label>
                    <label class="field"><span>Inicio de contrato *</span><input v-model="form.starts_on" type="date" required></label><label class="field"><span>Fin de contrato</span><input v-model="form.ends_on" type="date" :min="form.starts_on || undefined"></label>
                    <label class="field"><span>Oficina *</span><SearchableSelect v-model="form.office_id" :options="officeOptions" placeholder="Seleccione una oficina" /></label><label class="field"><span>Cargo *</span><SearchableSelect v-model="form.office_position_id" :options="positionOptions" :disabled="!form.office_id || humanResources.busy[`positions-${form.office_id}`]" :placeholder="form.office_id ? 'Seleccione un cargo' : 'Seleccione primero la oficina'" /></label>
                    <div class="field field--static"><span>No existe el cargo?</span><button class="button button--ghost" type="button" :disabled="!form.office_id" @click="openPosition()">+ Crear cargo para esta oficina</button></div>
                    <div class="field field--static"><span>Clasificación del cargo</span><strong>{{ selectedPosition?.membership_role_label || 'Seleccione un cargo' }}</strong><button v-if="selectedPosition" class="text-button" type="button" @click="openPosition(selectedPosition)">Corregir cargo</button></div>
                    <div class="field field--static field--full"><span>Rol inicial en SIGAL</span><strong>Usuario simple</strong><small>Los roles privilegiados se asignan después desde Administración, con trazabilidad independiente del contrato.</small></div>
                </div></section>
                <footer class="modal__actions"><button class="button button--ghost" type="button" @click="closeRecord">Cancelar</button><button class="button button--primary" type="submit" :disabled="humanResources.busy['register-employee']">{{ humanResources.busy['register-employee'] ? 'Registrando...' : 'Guardar kardex y contratacion' }}</button></footer>
            </form>
        </section>
    </div>

    <div v-if="editOpen" class="modal-backdrop" @click.self="editOpen = false">
        <section class="modal modal--wide human-resources-modal" role="dialog" aria-modal="true" aria-labelledby="employee-edit-title">
            <header class="modal__header"><div><p class="eyebrow">Correccion de kardex</p><h2 id="employee-edit-title">{{ humanResources.selected?.full_name }}</h2><p class="muted">Cada modificacion queda auditada. El correo SIGAL de acceso no cambia al corregir el CI.</p></div><button class="icon-button" type="button" aria-label="Cerrar" @click="editOpen = false">x</button></header>
            <form class="human-resources-form" @submit.prevent="submitEdit">
                <section><div class="form-section-heading"><span>1</span><div><h3>Informacion personal</h3><p>El CI se valida contra los kardex existentes y los nombres se normalizan a mayusculas.</p></div></div><div class="form-grid">
                    <label class="field"><span>CI *</span><input v-model.trim="editForm.identity_card" maxlength="30" required></label><label class="field"><span>Celular *</span><input v-model.trim="editForm.mobile_phone" maxlength="40" required></label>
                    <label class="field"><span>Nombres *</span><input v-model.trim="editForm.first_names" maxlength="255" required @input="uppercase(editForm, 'first_names')"></label><label class="field"><span>Apellidos *</span><input v-model.trim="editForm.last_names" maxlength="255" required @input="uppercase(editForm, 'last_names')"></label>
                    <label class="field"><span>Correo personal</span><input v-model.trim="editForm.email" type="email" maxlength="255"></label><label class="field"><span>Fecha de nacimiento *</span><input v-model="editForm.birth_date" type="date" required></label>
                    <label class="field"><span>Numero de CUA</span><input v-model.trim="editForm.cua_number" maxlength="50"></label><label class="field"><span>Libreta de servicio militar</span><input v-model.trim="editForm.military_service_booklet" maxlength="100"></label>
                    <label class="field"><span>Grado academico *</span><input v-model.trim="editForm.academic_degree" list="academic-degrees" maxlength="255" required></label><label class="field"><span>Profesion *</span><input v-model.trim="editForm.profession" maxlength="255" required></label>
                    <label class="field"><span>Tipo de sangre</span><input v-model.trim="editForm.blood_type" list="blood-types" maxlength="20"></label><label class="field"><span>Contacto de emergencia</span><input v-model.trim="editForm.emergency_contact" maxlength="2000"></label>
                    <label class="field field--full"><span>Direccion</span><textarea v-model.trim="editForm.address" rows="2" maxlength="2000"></textarea></label>
                </div></section>
                <section><div class="form-section-heading"><span>2</span><div><h3>Agregar o actualizar respaldos</h3><p>El nuevo archivo se agrega al historial; los anteriores se conservan para auditoria. La fotografia se actualiza desde el encabezado del kardex.</p></div></div><div class="support-file-grid">
                    <label class="support-file"><span>Certificado REJAP</span><strong>{{ fileLabel(editFiles, 'rejap_certificate', 'Mantener actual') }}</strong><input type="file" @change="selectFile($event, editFiles, 'rejap_certificate')"></label>
                    <label class="support-file"><span>Certificado CENVI</span><strong>{{ fileLabel(editFiles, 'cenvi_certificate', 'Mantener actual') }}</strong><input type="file" @change="selectFile($event, editFiles, 'cenvi_certificate')"></label>
                    <label class="support-file"><span>Padron biometrico electoral</span><strong>{{ fileLabel(editFiles, 'electoral_registry_certificate', 'Mantener actual') }}</strong><input type="file" @change="selectFile($event, editFiles, 'electoral_registry_certificate')"></label>
                    <label class="support-file"><span>Otro documento de respaldo</span><strong>{{ fileLabel(editFiles, 'other_supporting_document', 'Adjuntar si corresponde') }}</strong><input type="file" @change="selectFile($event, editFiles, 'other_supporting_document')"></label>
                </div></section>
                <footer class="modal__actions"><button class="button button--ghost" type="button" @click="editOpen = false">Cancelar</button><button class="button button--primary" type="submit" :disabled="humanResources.busy[`update-employee-${humanResources.selected?.id}`] || humanResources.busy[`employee-attachment-${humanResources.selected?.id}`]">Guardar correcciones</button></footer>
            </form>
        </section>
    </div>

    <div v-if="positionOpen" class="modal-backdrop" @click.self="positionOpen = false">
        <section class="modal position-modal" role="dialog" aria-modal="true">
            <header class="modal__header">
                <div><p class="eyebrow">Organigrama</p><h2>{{ positionEditingId ? 'Corregir cargo de oficina' : 'Crear cargo de oficina' }}</h2><p class="muted">Esta definición determina si la persona contratada será funcionario o responsable de la oficina.</p></div>
                <button class="icon-button" type="button" aria-label="Cerrar" @click="positionOpen = false">x</button>
            </header>
            <form class="form-grid" @submit.prevent="savePosition">
                <label class="field field--full"><span>Oficina</span><SearchableSelect v-model="positionForm.office_id" :options="officeOptions" disabled /></label>
                <label class="field field--full"><span>Nombre del cargo *</span><input v-model.trim="positionForm.name" required maxlength="255"></label>
                <label class="check-option field--full"><input v-model="positionIsManager" type="checkbox"><span><strong>Es responsable de esta oficina</strong><small>Marque esta opción para jefaturas, responsables de unidad o sección. SIGAL aplicará sus atribuciones jerárquicas al contrato activo.</small></span></label>
                <footer class="modal__actions form-grid__full"><button class="button button--ghost" type="button" @click="positionOpen = false">Cancelar</button><button class="button button--primary" type="submit" :disabled="humanResources.busy[positionEditingId ? 'update-position' : 'create-position']">{{ positionEditingId ? 'Guardar corrección' : 'Crear cargo' }}</button></footer>
            </form>
        </section>
    </div>
    <div v-if="credentials" class="modal-backdrop"><section class="modal credential-modal" role="alertdialog" aria-modal="true"><header class="modal__header"><div><p class="eyebrow">Cuenta creada</p><h2>Entregue estas credenciales al funcionario</h2></div></header><div class="credential-content"><p>La contrasena inicial combina el CI completo con las iniciales de todos sus nombres y apellidos. SIGAL obligara a reemplazarla en el primer acceso.</p><div><span>Correo SIGAL</span><strong>{{ credentials.email }}</strong></div><div><span>Contrasena inicial</span><strong class="credential-password">{{ credentials.temporary_password }}</strong></div></div><footer class="modal__actions"><button class="button button--primary" type="button" @click="credentials = null">Entendido</button></footer></section></div>
    <EmployeeBulkImportDialog v-if="bulkImportOpen" @close="bulkImportOpen = false" />
    <datalist id="academic-degrees"><option v-for="degree in academicSuggestions" :key="degree" :value="degree"></option></datalist><datalist id="blood-types"><option value="A+"></option><option value="A-"></option><option value="B+"></option><option value="B-"></option><option value="AB+"></option><option value="AB-"></option><option value="O+"></option><option value="O-"></option></datalist>
</template>
