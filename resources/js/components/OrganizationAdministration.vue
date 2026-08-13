<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import SearchableSelect from './SearchableSelect.vue';
import { useDocumentManagementStore } from '../stores/document-management';

const documents = useDocumentManagementStore();
const error = ref(null);
const mode = ref('create');
const membershipFormOpen = ref(false);

const officeForm = reactive({ parent_id: '', code: '', name: '', status: 'active', supports_staffing: true, requires_manager: true });
const membershipForm = reactive({ user_id: '', membership_role: 'official', position_title: '' });

const flattenedOffices = computed(() => {
    const offices = documents.administrationOffices;
    const byParent = new Map();
    offices.forEach((office) => {
        const parentId = office.parent_id ?? null;
        if (!byParent.has(parentId)) byParent.set(parentId, []);
        byParent.get(parentId).push(office);
    });
    byParent.forEach((items) => items.sort((left, right) => left.name.localeCompare(right.name, 'es')));

    const rows = [];
    function visit(parentId, depth = 0) {
        for (const office of byParent.get(parentId) ?? []) {
            rows.push({ ...office, depth });
            visit(office.id, depth + 1);
        }
    }
    visit(null);
    return rows;
});

const excludedParentIds = computed(() => {
    if (!documents.selectedOffice) return new Set();

    const children = new Map();
    documents.administrationOffices.forEach((office) => {
        if (!children.has(office.parent_id)) children.set(office.parent_id, []);
        children.get(office.parent_id).push(office.id);
    });
    const excluded = new Set([documents.selectedOffice.id]);
    const visit = (officeId) => {
        for (const childId of children.get(officeId) ?? []) {
            excluded.add(childId);
            visit(childId);
        }
    };
    visit(documents.selectedOffice.id);
    return excluded;
});

const activeParentOptions = computed(() => flattenedOffices.value.filter((office) =>
    office.status === 'active' && !excludedParentIds.value.has(office.id),
));

const activeUsers = computed(() => documents.users.filter((user) => user.status === 'active'));

function officeLabel(office) {
    return office ? `${office.code} · ${office.name}` : 'Raíz institucional';
}

function formatDate(value) {
    return value ? new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium' }).format(new Date(value)) : '—';
}

function resetForm() {
    mode.value = 'create';
    error.value = null;
    documents.selectedOffice = null;
    Object.assign(officeForm, { parent_id: '', code: '', name: '', status: 'active', supports_staffing: true, requires_manager: true });
    Object.assign(membershipForm, { user_id: '', membership_role: 'official', position_title: '' });
    membershipFormOpen.value = false;
}

async function selectOffice(office) {
    error.value = null;
    mode.value = 'edit';
    Object.assign(officeForm, {
        parent_id: office.parent_id ?? '',
        code: office.code,
        name: office.name,
        status: office.status,
        supports_staffing: office.supports_staffing,
        requires_manager: office.requires_manager,
    });
    try {
        await documents.selectOffice(office);
    } catch (exception) {
        error.value = exception.message;
    }
}

async function saveOffice() {
    error.value = null;
    const data = {
        parent_id: officeForm.parent_id ? Number(officeForm.parent_id) : null,
        code: officeForm.code.trim().toUpperCase(),
        name: officeForm.name.trim(),
        status: officeForm.status,
        supports_staffing: officeForm.supports_staffing,
        requires_manager: officeForm.requires_manager,
    };
    try {
        if (mode.value === 'edit') {
            await documents.updateOffice(documents.selectedOffice.id, data);
        } else {
            await documents.createOffice(data);
            resetForm();
        }
    } catch (exception) {
        error.value = exception.message;
    }
}

async function changeStatus(action) {
    try {
        await documents.changeOfficeStatus(documents.selectedOffice, action);
        if (action === 'inactivate') resetForm();
    } catch (exception) {
        error.value = exception.message;
    }
}

async function assignMembership() {
    try {
        await documents.assignOfficeMembership(documents.selectedOffice.id, {
            user_id: Number(membershipForm.user_id),
            membership_role: membershipForm.membership_role,
            position_title: membershipForm.position_title || null,
        });
        Object.assign(membershipForm, { user_id: '', membership_role: 'official', position_title: '' });
        membershipFormOpen.value = false;
    } catch (exception) {
        error.value = exception.message;
    }
}

async function closeMembership(membership) {
    if (!window.confirm(`¿Cerrar la asignación vigente de ${membership.user?.name}?`)) return;
    try {
        await documents.closeOfficeMembership(documents.selectedOffice.id, membership.id);
    } catch (exception) {
        error.value = exception.message;
    }
}

onMounted(async () => {
    try {
        await Promise.all([documents.loadOrganizationAdministration(), documents.loadUsers()]);
    } catch (exception) {
        error.value = exception.message;
    }
});
</script>

<template>
    <section class="administration-section">
        <header class="section-heading section-heading--administration">
            <div>
                <p class="eyebrow">Estructura institucional</p>
                <h2>Organigrama y oficinas</h2>
                <p class="muted">Defina la dependencia jerárquica antes de habilitar una oficina para operar expedientes.</p>
            </div>
            <button class="button button--primary" type="button" @click="resetForm">+ Nueva oficina</button>
        </header>

        <p v-if="error || documents.error" class="alert alert--error" role="alert">{{ error || documents.error }}</p>

        <div class="organization-layout">
            <section class="organization-tree panel">
                <header><p class="eyebrow">Mapa jerárquico</p><h3>Organigrama institucional</h3></header>
                <div v-if="documents.busy.organization" class="empty-state">Cargando estructura…</div>
                <div v-else-if="!flattenedOffices.length" class="empty-state">Aún no hay oficinas registradas.</div>
                <div v-else class="tree-list">
                    <button v-for="office in flattenedOffices" :key="office.id" class="tree-row" :class="{ 'is-selected': documents.selectedOffice?.id === office.id, 'is-inactive': office.status !== 'active' }" :style="{ '--depth': office.depth }" type="button" @click="selectOffice(office)">
                        <span class="tree-row__branch" aria-hidden="true"></span>
                        <span class="tree-row__code">{{ office.code }}</span>
                        <span class="tree-row__name">{{ office.name }}</span>
                        <span class="tree-row__status" :class="`tree-row__status--${office.status}`">{{ office.status_label }}</span>
                    </button>
                </div>
            </section>

            <section class="organization-editor panel">
                <header><p class="eyebrow">{{ mode === 'edit' ? 'Oficina seleccionada' : 'Nueva unidad' }}</p><h3>{{ mode === 'edit' ? officeForm.name || 'Editar oficina' : 'Registrar oficina' }}</h3></header>
                <form class="form-grid form-grid--compact" @submit.prevent="saveOffice">
                    <label class="field form-grid__full"><span>Dependencia jerárquica</span><SearchableSelect v-model="officeForm.parent_id" :options="[{ value: '', label: 'Sin dependencia - raiz institucional' }, ...activeParentOptions.map((office) => ({ value: office.id, label: `${'— '.repeat(office.depth)}${officeLabel(office)}` }))]" /><select v-show="false" v-model="officeForm.parent_id" disabled><option value="">Sin dependencia — raíz institucional</option><option v-for="office in activeParentOptions" :key="office.id" :value="office.id">{{ '— '.repeat(office.depth) }}{{ officeLabel(office) }}</option></select><small>La oficina dependerá de la unidad seleccionada en el organigrama.</small></label>
                    <label class="field"><span>Código interno</span><input v-model="officeForm.code" maxlength="30" pattern="[A-Za-z0-9_-]+" placeholder="Ej.: DLEG" required></label>
                    <label class="field"><span>Nombre de la oficina</span><input v-model.trim="officeForm.name" maxlength="255" placeholder="Ej.: Dirección Legislativa" required></label>
                    <label class="check-option form-grid__full"><input v-model="officeForm.supports_staffing" type="checkbox" @change="!officeForm.supports_staffing && (officeForm.requires_manager = false)"><span><strong>Admite funcionarios</strong><small>Desactive para nodos representativos como Pleno, Directiva, Comisiones o Bancadas.</small></span></label>
                    <label v-if="officeForm.supports_staffing" class="check-option form-grid__full"><input v-model="officeForm.requires_manager" type="checkbox"><span><strong>Requiere responsable de oficina</strong><small>Asesores del Pleno y Asesores de Presidencia tienen personal, pero no responsable propio.</small></span></label>
                    <label v-if="mode === 'create'" class="field form-grid__full"><span>Estado inicial</span><SearchableSelect v-model="officeForm.status" :options="[{ value: 'active', label: 'Activa' }, { value: 'inactive', label: 'Inactiva' }]" /><select v-show="false" v-model="officeForm.status" disabled><option value="active">Activa</option><option value="inactive">Inactiva</option></select></label>
                    <footer class="form-grid__full inline-actions"><button class="button button--primary" :disabled="documents.busy['office-save']" type="submit">{{ documents.busy['office-save'] ? 'Guardando…' : mode === 'edit' ? 'Guardar cambios' : 'Crear oficina' }}</button><button v-if="mode === 'edit'" class="button button--ghost" type="button" @click="resetForm">Cancelar</button><button v-if="mode === 'edit' && documents.selectedOffice.status === 'active'" class="button button--danger" :disabled="documents.busy[`office-${documents.selectedOffice.id}`]" type="button" @click="changeStatus('inactivate')">Inactivar</button><button v-if="mode === 'edit' && documents.selectedOffice.status === 'inactive'" class="button button--secondary" :disabled="documents.busy[`office-${documents.selectedOffice.id}`]" type="button" @click="changeStatus('activate')">Activar</button></footer>
                </form>

                <section v-if="mode === 'edit'" class="office-memberships">
                    <div class="section-heading"><div><p class="eyebrow">Responsables y funcionarios</p><h3>Asignaciones vigentes</h3><small>{{ documents.selectedOffice.organizational_profile_label }}</small></div><button v-if="documents.selectedOffice.status === 'active' && documents.selectedOffice.supports_staffing" class="button button--secondary" type="button" @click="membershipFormOpen = !membershipFormOpen">{{ membershipFormOpen ? 'Cancelar' : '+ Asignar usuario' }}</button></div>
                    <form v-if="membershipFormOpen" class="action-form" @submit.prevent="assignMembership"><label class="field form-grid__full"><span>Usuario</span><SearchableSelect v-model="membershipForm.user_id" :options="activeUsers.map((user) => ({ value: user.id, label: `${user.name} - ${user.email}` }))" placeholder="Seleccione un usuario activo" /><select v-show="false" v-model="membershipForm.user_id" disabled><option value="" disabled>Seleccione un usuario activo</option><option v-for="user in activeUsers" :key="user.id" :value="user.id">{{ user.name }} · {{ user.email }}</option></select></label><label class="field"><span>Función en la oficina</span><SearchableSelect v-model="membershipForm.membership_role" :options="[{ value: 'official', label: 'Funcionario' }, { value: 'manager', label: 'Jefatura' }]" /><select v-show="false" v-model="membershipForm.membership_role" disabled><option value="official">Funcionario</option><option value="manager">Jefatura</option></select></label><label class="field"><span>Cargo descriptivo</span><input v-model.trim="membershipForm.position_title" maxlength="255" placeholder="Ej.: Responsable de unidad"></label><button class="button button--primary" :disabled="documents.busy['membership-save']" type="submit">Asignar a la oficina</button></form>
                    <div v-if="documents.busy['office-memberships']" class="empty-state">Cargando asignaciones…</div>
                    <div v-else-if="!documents.officeMemberships.length" class="empty-state">No hay asignaciones vigentes o históricas.</div>
                    <div v-else class="membership-list"><article v-for="membership in documents.officeMemberships" :key="membership.id" class="membership-row"><div><strong>{{ membership.user?.name }}</strong><span>{{ membership.membership_role_label }}{{ membership.position_title ? ` · ${membership.position_title}` : '' }}</span><small>Desde {{ formatDate(membership.effective_from) }}{{ membership.effective_to ? ' · Cerrada' : ' · Vigente' }}</small></div><button v-if="!membership.effective_to" class="text-button text-button--danger" :disabled="documents.busy[`membership-${membership.id}`]" type="button" @click="closeMembership(membership)">Cerrar asignación</button></article></div>
                </section>
            </section>
        </div>
    </section>
</template>
