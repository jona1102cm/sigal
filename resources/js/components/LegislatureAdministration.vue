<script setup>
/** Administra períodos legislativos y reemplazos efectivos de la Directiva. */
import { computed, onMounted, reactive, ref } from 'vue';
import SearchableSelect from './SearchableSelect.vue';
import { useDocumentManagementStore } from '../stores/document-management';

const documents = useDocumentManagementStore();
const error = ref(null);
const selectedLegislatureId = ref(null);
const boardFormOpen = ref(false);

const currentYear = new Date().getFullYear();
const legislatureForm = reactive({ start_year: currentYear, end_year: currentYear + 1, status: 'active' });
const boardForm = reactive({ user_id: '', position: 'president', effective_on: new Date().toISOString().slice(0, 10), effective_at: new Date().toISOString().slice(0, 16) });

const selectedLegislature = computed(() => documents.legislatures.find((item) => item.id === selectedLegislatureId.value) ?? null);
const activeLegislature = computed(() => documents.legislatures.find((item) => item.status === 'active') ?? null);
const activeUsers = computed(() => documents.users.filter((user) => user.status === 'active'));

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

function selectLegislature(legislature) {
    selectedLegislatureId.value = legislature.id;
    error.value = null;
    Object.assign(legislatureForm, { start_year: legislature.start_year, end_year: legislature.end_year, status: legislature.status });
    boardFormOpen.value = false;
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

onMounted(async () => {
    try {
        await Promise.all([documents.loadLegislatures(), documents.loadUsers()]);
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
            </section>
        </div>
    </section>
</template>
