<script setup>
/** Administra períodos legislativos y reemplazos efectivos de la Directiva. */
import { computed, onMounted, reactive, ref } from 'vue';
import SearchableSelect from './SearchableSelect.vue';
import { useDocumentManagementStore } from '../stores/document-management';

const documents = useDocumentManagementStore();
const error = ref(null);
const selectedLegislatureId = ref(null);
const boardFormOpen = ref(false);
const memberFormOpen = ref(false);
const bodyFormOpen = ref(false);
const selectedBodyId = ref(null);

const currentYear = new Date().getFullYear();
const legislatureForm = reactive({ start_year: currentYear, end_year: currentYear + 1, status: 'active' });
const boardForm = reactive({ user_id: '', position: 'president', effective_on: new Date().toISOString().slice(0, 10), effective_at: new Date().toISOString().slice(0, 16) });
const memberForm = reactive({ user_id: '', effective_from: new Date().toISOString().slice(0, 16) });
const bodyForm = reactive({ office_id: '', body_type: 'caucus', purpose: '', effective_from: new Date().toISOString().slice(0, 16) });
const membershipForm = reactive({ legislature_member_id: '', role: 'member', effective_from: new Date().toISOString().slice(0, 16) });
const delegationForm = reactive({ principal_membership_id: '', delegate_membership_id: '', effective_from: '', effective_to: '', reason: '' });

const selectedLegislature = computed(() => documents.legislatures.find((item) => item.id === selectedLegislatureId.value) ?? null);
const activeLegislature = computed(() => documents.legislatures.find((item) => item.status === 'active') ?? null);
const activeUsers = computed(() => documents.users.filter((user) => user.status === 'active'));
const composition = computed(() => documents.legislativeComposition);
const selectedBody = computed(() => composition.value.bodies.find((body) => body.id === selectedBodyId.value) ?? null);
const legislativeOffices = computed(() => documents.administrationOffices.filter((office) => ['BANC', 'COMIS'].includes(office.parent?.code) && office.status === 'active'));
const availableBodyOffices = computed(() => legislativeOffices.value.filter((office) => !composition.value.bodies.some((body) => Number(body.office?.id) === Number(office.id))));
const bodyRoles = computed(() => selectedBody.value?.body_type === 'caucus'
    ? [['chief', 'Jefe'], ['deputy_chief', 'Subjefe'], ['member', 'Miembro']]
    : [['president', 'Presidente'], ['vice_president', 'Vicepresidente'], ['secretary', 'Secretario(a)'], ['member', 'Miembro']]);
const principalMemberships = computed(() => selectedBody.value?.current_memberships?.filter((item) => ['chief', 'president'].includes(item.role)) ?? []);
const delegateMemberships = computed(() => selectedBody.value?.current_memberships?.filter((item) => ['deputy_chief', 'vice_president'].includes(item.role)) ?? []);

const positions = [
    ['president', 'Presidente'],
    ['vice_president', 'Vicepresidente'],
    ['second_vice_president', 'Segundo Vicepresidente'],
    ['secretary', 'Secretaria'],
    ['second_secretary', 'Segunda Secretaria'],
];

function reset() {
    selectedLegislatureId.value = null;
    error.value = null;
    Object.assign(legislatureForm, { start_year: currentYear, end_year: currentYear + 1, status: activeLegislature.value ? 'inactive' : 'active' });
    boardFormOpen.value = false;
}

async function selectLegislature(legislature) {
    selectedLegislatureId.value = legislature.id;
    error.value = null;
    Object.assign(legislatureForm, { start_year: legislature.start_year, end_year: legislature.end_year, status: legislature.status });
    boardFormOpen.value = false;
    memberFormOpen.value = false;
    bodyFormOpen.value = false;
    selectedBodyId.value = null;
    try {
        await documents.loadLegislativeComposition(legislature.id);
    } catch (exception) {
        error.value = exception.message;
    }
}

async function save() {
    try {
        const data = { start_year: Number(legislatureForm.start_year), end_year: Number(legislatureForm.end_year) };
        if (!selectedLegislature.value) data.status = legislatureForm.status;
        await documents.saveLegislature(data, selectedLegislature.value?.id);
        if (!selectedLegislature.value) {
            const created = documents.legislatures.find((item) => item.start_year === data.start_year && item.end_year === data.end_year);
            if (created) selectLegislature(created);
        } else {
            const updated = documents.legislatures.find((item) => item.id === selectedLegislatureId.value);
            if (updated) selectLegislature(updated);
        }
    } catch (exception) {
        error.value = exception.message;
    }
}

async function changeStatus(action) {
    try {
        await documents.changeLegislatureStatus(selectedLegislature.value, action);
        const updated = documents.legislatures.find((item) => item.id === selectedLegislatureId.value);
        if (updated) selectLegislature(updated);
    } catch (exception) {
        error.value = exception.message;
    }
}

async function assignBoardMember() {
    try {
        await documents.replaceBoardMember(selectedLegislature.value.id, {
            user_id: Number(boardForm.user_id),
            position: boardForm.position,
            effective_on: boardForm.effective_on,
            effective_at: boardForm.effective_at,
        });
        const updated = documents.legislatures.find((item) => item.id === selectedLegislatureId.value);
        if (updated) selectLegislature(updated);
        Object.assign(boardForm, { user_id: '', position: 'president', effective_on: new Date().toISOString().slice(0, 10), effective_at: new Date().toISOString().slice(0, 16) });
        boardFormOpen.value = false;
    } catch (exception) {
        error.value = exception.message;
    }
}

async function assignMember() {
    try {
        await documents.assignLegislatureMember(selectedLegislature.value.id, {
            user_id: Number(memberForm.user_id),
            effective_from: memberForm.effective_from,
        });
        Object.assign(memberForm, { user_id: '', effective_from: new Date().toISOString().slice(0, 16) });
        memberFormOpen.value = false;
    } catch (exception) {
        error.value = exception.message;
    }
}

async function closeMember(member) {
    const effectiveTo = window.prompt('Fecha y hora exacta de finalización del mandato:', new Date().toISOString().slice(0, 16));
    if (!effectiveTo) return;
    try {
        await documents.closeLegislatureMember(selectedLegislature.value.id, member.id, effectiveTo);
    } catch (exception) {
        error.value = exception.message;
    }
}

async function createBody() {
    try {
        await documents.createLegislativeBody(selectedLegislature.value.id, {
            office_id: Number(bodyForm.office_id),
            body_type: bodyForm.body_type,
            purpose: bodyForm.purpose || null,
            effective_from: bodyForm.effective_from,
        });
        Object.assign(bodyForm, { office_id: '', body_type: 'caucus', purpose: '', effective_from: new Date().toISOString().slice(0, 16) });
        bodyFormOpen.value = false;
    } catch (exception) {
        error.value = exception.message;
    }
}

async function assignBodyMembership() {
    if (!selectedBody.value) return;
    try {
        await documents.assignLegislativeBodyMembership(selectedLegislature.value.id, selectedBody.value.id, {
            legislature_member_id: Number(membershipForm.legislature_member_id),
            role: membershipForm.role,
            effective_from: membershipForm.effective_from,
        });
        Object.assign(membershipForm, { legislature_member_id: '', role: 'member', effective_from: new Date().toISOString().slice(0, 16) });
    } catch (exception) {
        error.value = exception.message;
    }
}

async function closeBodyMembership(membership) {
    const effectiveTo = window.prompt('Fecha y hora exacta de finalización del cargo:', new Date().toISOString().slice(0, 16));
    if (!effectiveTo || !selectedBody.value) return;
    try {
        await documents.closeLegislativeBodyMembership(selectedLegislature.value.id, selectedBody.value.id, membership.id, effectiveTo);
    } catch (exception) {
        error.value = exception.message;
    }
}

async function createDelegation() {
    if (!selectedBody.value) return;
    try {
        await documents.createLegislativeDelegation(selectedLegislature.value.id, selectedBody.value.id, {
            principal_membership_id: Number(delegationForm.principal_membership_id),
            delegate_membership_id: Number(delegationForm.delegate_membership_id),
            effective_from: delegationForm.effective_from,
            effective_to: delegationForm.effective_to,
            reason: delegationForm.reason,
        });
        Object.assign(delegationForm, { principal_membership_id: '', delegate_membership_id: '', effective_from: '', effective_to: '', reason: '' });
    } catch (exception) {
        error.value = exception.message;
    }
}

onMounted(async () => {
    try {
        await Promise.all([documents.loadLegislatures(), documents.loadUsers(), documents.loadOrganizationAdministration()]);
        if (activeLegislature.value) selectLegislature(activeLegislature.value);
        else reset();
    } catch (exception) {
        error.value = exception.message;
    }
});
</script>

<template>
    <section class="administration-section">
        <header class="section-heading section-heading--administration">
            <div>
                <p class="eyebrow">Periodo institucional</p>
                <h2>Legislaturas y Directiva</h2>
                <p class="muted">Debe existir una legislatura activa para registrar expedientes. Al activar una nueva, SIGAL inactiva la anterior.</p>
            </div>
            <button class="button button--primary" type="button" @click="reset">+ Nueva legislatura</button>
        </header>

        <p v-if="!activeLegislature && !documents.busy.legislatures" class="alert alert--error">No existe una legislatura activa. Cree una y déjela activa antes de registrar expedientes.</p>
        <p v-if="error || documents.error" class="alert alert--error" role="alert">{{ error || documents.error }}</p>

        <div class="legislature-administration-layout">
            <section class="legislature-list panel">
                <header><p class="eyebrow">Períodos registrados</p><h3>Legislaturas</h3></header>
                <div v-if="documents.busy.legislatures" class="empty-state">Cargando legislaturas…</div>
                <div v-else-if="!documents.legislatures.length" class="empty-state">Aún no hay legislaturas registradas.</div>
                <button v-for="legislature in documents.legislatures" :key="legislature.id" class="type-row" :class="{ 'is-selected': selectedLegislatureId === legislature.id }" type="button" @click="selectLegislature(legislature)"><div><strong>{{ legislature.period_label }}</strong><span>{{ legislature.current_board_assignments?.length || 0 }} cargos de Directiva vigentes</span></div><span class="tree-row__status" :class="`tree-row__status--${legislature.status}`">{{ legislature.status_label }}</span></button>
            </section>

            <section class="legislature-editor panel">
                <header><p class="eyebrow">{{ selectedLegislature ? 'Legislatura seleccionada' : 'Nuevo período' }}</p><h3>{{ selectedLegislature ? selectedLegislature.period_label : 'Crear legislatura' }}</h3></header>
                <form class="form-grid form-grid--compact" @submit.prevent="save"><label class="field"><span>Año inicial</span><input v-model.number="legislatureForm.start_year" type="number" min="2000" max="2200" required></label><label class="field"><span>Año final</span><input v-model.number="legislatureForm.end_year" type="number" min="2001" max="2201" required><small>Debe ser exactamente el año siguiente.</small></label><label v-if="!selectedLegislature" class="field form-grid__full"><span>Estado al crear</span><SearchableSelect v-model="legislatureForm.status" :options="[{ value: 'active', label: 'Activa' }, { value: 'inactive', label: 'Inactiva' }]" /><select v-show="false" v-model="legislatureForm.status" disabled><option value="active">Activa</option><option value="inactive">Inactiva</option></select><small>Si la crea activa, cualquier legislatura activa anterior se inactivará automáticamente.</small></label><footer class="form-grid__full inline-actions"><button class="button button--primary" :disabled="documents.busy['legislature-save']" type="submit">{{ documents.busy['legislature-save'] ? 'Guardando…' : selectedLegislature ? 'Guardar período' : 'Crear legislatura' }}</button><button v-if="selectedLegislature" class="button button--ghost" type="button" @click="reset">Cancelar</button><button v-if="selectedLegislature?.status === 'inactive'" class="button button--secondary" :disabled="documents.busy[`legislature-${selectedLegislature.id}`]" type="button" @click="changeStatus('activate')">Activar legislatura</button><button v-if="selectedLegislature?.status === 'active'" class="button button--danger" :disabled="documents.busy[`legislature-${selectedLegislature.id}`]" type="button" @click="changeStatus('inactivate')">Inactivar</button></footer></form>

                <section v-if="selectedLegislature" class="office-memberships">
                    <div class="section-heading"><div><p class="eyebrow">Titulares vigentes</p><h3>Directiva</h3></div><button class="button button--secondary" type="button" @click="boardFormOpen = !boardFormOpen">{{ boardFormOpen ? 'Cancelar' : '+ Asignar cargo' }}</button></div>
                    <form v-if="boardFormOpen" class="action-form" @submit.prevent="assignBoardMember"><label class="field form-grid__full"><span>Usuario titular</span><SearchableSelect v-model="boardForm.user_id" :options="activeUsers.map((user) => ({ value: user.id, label: `${user.name} - ${user.email}` }))" placeholder="Seleccione un usuario del sistema" /><select v-show="false" v-model="boardForm.user_id" disabled><option value="" disabled>Seleccione un usuario del sistema</option><option v-for="user in activeUsers" :key="user.id" :value="user.id">{{ user.name }} · {{ user.email }}</option></select></label><label class="field"><span>Cargo</span><SearchableSelect v-model="boardForm.position" :options="positions.map(([value, label]) => ({ value, label }))" /><select v-show="false" v-model="boardForm.position" disabled><option v-for="[value, label] in positions" :key="value" :value="value">{{ label }}</option></select></label><label class="field"><span>Fecha efectiva</span><input v-model="boardForm.effective_on" type="date" required></label><label class="field form-grid__full"><span>Fecha y hora efectiva</span><input v-model="boardForm.effective_at" type="datetime-local" required></label><button class="button button--primary" :disabled="documents.busy['board-assignment']" type="submit">Asignar titular</button></form>
                    <div v-if="!selectedLegislature.current_board_assignments?.length" class="empty-state">No se asignaron cargos de Directiva todavía.</div>
                    <div v-else class="membership-list"><article v-for="assignment in selectedLegislature.current_board_assignments" :key="assignment.id" class="membership-row"><div><strong>{{ assignment.position_label }}</strong><span>{{ assignment.user_name || `Usuario #${assignment.user_id}` }}</span><small>Vigente desde {{ assignment.effective_on }}</small></div><button class="text-button" type="button" @click="boardFormOpen = true; boardForm.position = assignment.position">Reemplazar</button></article></div>
                </section>

                <section v-if="selectedLegislature" class="office-memberships">
                    <div class="section-heading"><div><p class="eyebrow">Mandatos vigentes</p><h3>Asambleístas</h3></div><button class="button button--secondary" type="button" @click="memberFormOpen = !memberFormOpen">{{ memberFormOpen ? 'Cancelar' : '+ Registrar asambleísta' }}</button></div>
                    <form v-if="memberFormOpen" class="action-form" @submit.prevent="assignMember">
                        <label class="field form-grid__full"><span>Cuenta del asambleísta</span><SearchableSelect v-model="memberForm.user_id" :options="activeUsers.map((user) => ({ value: user.id, label: `${user.name} - ${user.email}` }))" placeholder="Buscar usuario activo" /></label>
                        <label class="field form-grid__full"><span>Inicio exacto del mandato</span><input v-model="memberForm.effective_from" type="datetime-local" required></label>
                        <button class="button button--primary" :disabled="documents.busy['legislature-member-save']" type="submit">Registrar mandato</button>
                    </form>
                    <div v-if="documents.busy['legislative-composition']" class="empty-state">Cargando composición…</div>
                    <div v-else-if="!composition.members.length" class="empty-state">Aún no hay asambleístas registrados para este período.</div>
                    <div v-else class="membership-list"><article v-for="member in composition.members" :key="member.id" class="membership-row"><div><strong>{{ member.user?.name }}</strong><span>{{ member.body_memberships?.map((item) => `${item.role_label} · ${composition.bodies.find((body) => body.id === item.legislative_body_id)?.name || 'Órgano'}`).join(' / ') || 'Sin bancada o comisión asignada' }}</span><small>Mandato desde {{ new Date(member.effective_from).toLocaleString('es-BO') }}</small></div><button class="text-button" type="button" @click="closeMember(member)">Finalizar mandato</button></article></div>
                </section>

                <section v-if="selectedLegislature" class="office-memberships">
                    <div class="section-heading"><div><p class="eyebrow">Representación política</p><h3>Bancadas y comisiones</h3></div><button class="button button--secondary" type="button" @click="bodyFormOpen = !bodyFormOpen">{{ bodyFormOpen ? 'Cancelar' : '+ Habilitar órgano' }}</button></div>
                    <form v-if="bodyFormOpen" class="action-form" @submit.prevent="createBody">
                        <label class="field"><span>Tipo</span><SearchableSelect v-model="bodyForm.body_type" :options="[{ value: 'caucus', label: 'Bancada' }, { value: 'permanent_commission', label: 'Comisión permanente' }, { value: 'temporary_mixed_commission', label: 'Comisión mixta temporal' }]" /></label>
                        <label class="field"><span>Oficina vinculada</span><SearchableSelect v-model="bodyForm.office_id" :options="availableBodyOffices.filter((office) => bodyForm.body_type === 'caucus' ? office.parent?.code === 'BANC' : office.parent?.code === 'COMIS').map((office) => ({ value: office.id, label: `${office.code} · ${office.name}` }))" placeholder="Seleccione la oficina" /></label>
                        <label v-if="bodyForm.body_type === 'temporary_mixed_commission'" class="field form-grid__full"><span>Finalidad específica</span><textarea v-model.trim="bodyForm.purpose" rows="2" required></textarea></label>
                        <label class="field"><span>Vigente desde</span><input v-model="bodyForm.effective_from" type="datetime-local" required></label>
                        <button class="button button--primary" :disabled="documents.busy['legislative-body-save']" type="submit">Habilitar</button>
                    </form>

                    <div v-if="!composition.bodies.length" class="empty-state">Habilite las bancadas y comisiones que funcionarán en esta legislatura.</div>
                    <template v-else>
                        <label class="field form-grid__full"><span>Órgano a configurar</span><SearchableSelect v-model="selectedBodyId" :options="composition.bodies.map((body) => ({ value: body.id, label: `${body.body_type_label} · ${body.name}` }))" placeholder="Seleccione bancada o comisión" /></label>
                        <div v-if="selectedBody" class="action-form">
                            <div class="form-grid__full"><p class="eyebrow">{{ selectedBody.body_type_label }}</p><h4>{{ selectedBody.name }}</h4><p v-if="selectedBody.purpose" class="muted">{{ selectedBody.purpose }}</p></div>
                            <label class="field"><span>Asambleísta</span><SearchableSelect v-model="membershipForm.legislature_member_id" :options="composition.members.map((member) => ({ value: member.id, label: member.user?.name }))" placeholder="Seleccione un miembro" /></label>
                            <label class="field"><span>Cargo</span><SearchableSelect v-model="membershipForm.role" :options="bodyRoles.map(([value, label]) => ({ value, label }))" /></label>
                            <label class="field"><span>Vigente desde</span><input v-model="membershipForm.effective_from" type="datetime-local" required></label>
                            <button class="button button--primary" :disabled="documents.busy[`legislative-body-${selectedBody.id}-membership`]" type="button" @click="assignBodyMembership">Asignar cargo</button>
                            <div class="membership-list form-grid__full"><article v-for="membership in selectedBody.current_memberships" :key="membership.id" class="membership-row"><div><strong>{{ membership.role_label }}</strong><span>{{ membership.member?.name }}</span><small>Desde {{ new Date(membership.effective_from).toLocaleString('es-BO') }}</small></div><button class="text-button" type="button" @click="closeBodyMembership(membership)">Finalizar</button></article></div>
                        </div>

                        <form v-if="selectedBody && principalMemberships.length && delegateMemberships.length" class="action-form" @submit.prevent="createDelegation">
                            <div class="form-grid__full"><p class="eyebrow">Reemplazo temporal auditado</p><h4>Delegación de autoridad</h4><p class="muted">El Subjefe reemplaza al Jefe o el Vicepresidente al Presidente únicamente dentro de este intervalo.</p></div>
                            <label class="field"><span>Autoridad titular</span><SearchableSelect v-model="delegationForm.principal_membership_id" :options="principalMemberships.map((item) => ({ value: item.id, label: `${item.role_label} · ${item.member?.name}` }))" /></label>
                            <label class="field"><span>Reemplazo</span><SearchableSelect v-model="delegationForm.delegate_membership_id" :options="delegateMemberships.map((item) => ({ value: item.id, label: `${item.role_label} · ${item.member?.name}` }))" /></label>
                            <label class="field"><span>Desde</span><input v-model="delegationForm.effective_from" type="datetime-local" required></label>
                            <label class="field"><span>Hasta</span><input v-model="delegationForm.effective_to" type="datetime-local" required></label>
                            <label class="field form-grid__full"><span>Motivo</span><textarea v-model.trim="delegationForm.reason" rows="2" required></textarea></label>
                            <button class="button button--secondary" :disabled="documents.busy[`legislative-delegation-${selectedBody.id}`]" type="submit">Registrar delegación</button>
                            <div v-if="selectedBody.delegations?.length" class="membership-list form-grid__full"><article v-for="delegation in selectedBody.delegations" :key="delegation.id" class="membership-row"><div><strong>{{ delegation.principal?.name }} → {{ delegation.delegate?.name }}</strong><span>{{ delegation.reason }}</span><small>{{ new Date(delegation.effective_from).toLocaleString('es-BO') }} — {{ new Date(delegation.effective_to).toLocaleString('es-BO') }}</small></div></article></div>
                        </form>
                    </template>
                </section>
            </section>
        </div>
    </section>
</template>
