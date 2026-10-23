<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useHumanResourcesStore } from '../stores/human-resources';
import SearchableSelect from './SearchableSelect.vue';

const humanResources = useHumanResourcesStore();
const selectedOfficeId = ref('');
const endingAssignment = ref(null);
const localError = ref(null);

function institutionalMinute() {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/La_Paz',
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(new Date()).filter((part) => part.type !== 'literal').map((part) => [part.type, part.value]));

    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

const form = reactive({ user_id: '', effective_from: institutionalMinute(), effective_to: '', designation_note: '' });
const endForm = reactive({ effective_to: institutionalMinute(), closure_note: '' });
const selectedOffice = computed(() => humanResources.officeManagers.find((office) => Number(office.id) === Number(selectedOfficeId.value)) ?? null);
const officeOptions = computed(() => humanResources.officeManagers.map((office) => ({
    value: office.id,
    label: `${office.code} · ${office.name}${office.current_manager ? ` — ${office.current_manager.user?.name}` : ' — SIN RESPONSABLE'}`,
})));
const memberOptions = computed(() => selectedOffice.value?.members.map((member) => ({
    value: member.user_id,
    label: `${member.name} · ${member.position_title || 'Sin cargo descriptivo'}`,
})) ?? []);

watch(selectedOfficeId, () => {
    Object.assign(form, { user_id: '', effective_from: institutionalMinute(), effective_to: '', designation_note: '' });
    endingAssignment.value = null;
    localError.value = null;
});

onMounted(async () => {
    await humanResources.loadOfficeManagers();
    const withoutManager = humanResources.officeManagers.find((office) => !office.current_manager);
    selectedOfficeId.value = withoutManager?.id ?? humanResources.officeManagers[0]?.id ?? '';
});

function formatDate(value) {
    if (!value) return 'Sin fecha de cierre';
    return new Intl.DateTimeFormat('es-BO', {
        timeZone: 'America/La_Paz', dateStyle: 'medium', timeStyle: 'short',
    }).format(new Date(value));
}

async function designate() {
    localError.value = null;
    if (!selectedOffice.value || !form.user_id) return;

    try {
        await humanResources.designateOfficeManager(selectedOffice.value.id, {
            user_id: Number(form.user_id),
            effective_from: form.effective_from,
            effective_to: form.effective_to || null,
            designation_note: form.designation_note || null,
        });
        Object.assign(form, { user_id: '', effective_from: institutionalMinute(), effective_to: '', designation_note: '' });
    } catch (error) {
        localError.value = error.message;
    }
}

function beginEnd(assignment) {
    endingAssignment.value = assignment;
    Object.assign(endForm, { effective_to: institutionalMinute(), closure_note: '' });
    localError.value = null;
}

async function finishAssignment() {
    localError.value = null;
    if (!selectedOffice.value || !endingAssignment.value) return;

    try {
        await humanResources.endOfficeManager(selectedOffice.value.id, endingAssignment.value.id, { ...endForm });
        endingAssignment.value = null;
    } catch (error) {
        localError.value = error.message;
    }
}
</script>

<template>
    <section class="workspace workspace--wide office-manager-workspace">
        <header class="page-heading">
            <div><p class="eyebrow">Recursos Humanos</p><h1>Responsables de oficinas</h1><p class="muted">Designe una única autoridad vigente por oficina sin modificar su cargo contractual. Cada reemplazo conserva fechas, motivo y autoría.</p></div>
            <span class="badge badge--large">{{ humanResources.officeManagers.filter((office) => !office.current_manager).length }} sin responsable</span>
        </header>

        <p v-if="localError || humanResources.error" class="alert alert--error" role="alert">{{ localError || humanResources.error }}</p>
        <div v-if="humanResources.busy['office-managers'] && !humanResources.officeManagers.length" class="empty-state">Cargando estructura y responsables vigentes…</div>

        <template v-else>
            <section class="panel office-manager-selector">
                <label class="field field--full"><span>Oficina</span><SearchableSelect v-model="selectedOfficeId" :options="officeOptions" placeholder="Busque una oficina" /></label>
            </section>

            <div v-if="selectedOffice" class="office-manager-layout">
                <section class="panel office-manager-current">
                    <p class="eyebrow">Responsabilidad vigente</p>
                    <template v-if="selectedOffice.current_manager">
                        <span class="status-dot status-dot--success"></span>
                        <h2>{{ selectedOffice.current_manager.user?.name }}</h2>
                        <p>{{ selectedOffice.current_manager.position_title || 'Sin cargo descriptivo' }}</p>
                        <small>Desde {{ formatDate(selectedOffice.current_manager.effective_from) }}</small>
                        <small v-if="selectedOffice.current_manager.effective_to">Designación temporal hasta {{ formatDate(selectedOffice.current_manager.effective_to) }}</small>
                        <p v-if="selectedOffice.current_manager.designation_note" class="form-note">{{ selectedOffice.current_manager.designation_note }}</p>
                        <button class="button button--ghost" type="button" @click="beginEnd(selectedOffice.current_manager)">Finalizar designación</button>
                    </template>
                    <template v-else>
                        <span class="status-dot status-dot--warning"></span>
                        <h2>Sin responsable vigente</h2>
                        <p class="muted">La oficina no debe recibir nuevos trámites hasta contar con responsable o equipo documental autorizado.</p>
                    </template>
                </section>

                <form class="panel office-manager-form" @submit.prevent="designate">
                    <div><p class="eyebrow">Nueva designación</p><h2>{{ selectedOffice.current_manager ? 'Reemplazar responsable' : 'Designar responsable' }}</h2><p class="muted">El funcionario debe pertenecer actualmente a {{ selectedOffice.name }}.</p></div>
                    <label class="field field--full"><span>Funcionario *</span><SearchableSelect v-model="form.user_id" :options="memberOptions" placeholder="Seleccione entre los miembros vigentes" /></label>
                    <label class="field"><span>Inicio efectivo *</span><input v-model="form.effective_from" type="datetime-local" :max="institutionalMinute()" required></label>
                    <label class="field"><span>Finalización <small>Opcional</small></span><input v-model="form.effective_to" type="datetime-local" :min="form.effective_from || undefined"></label>
                    <label class="field field--full"><span>Fundamento o referencia <small>Opcional</small></span><textarea v-model.trim="form.designation_note" rows="3" maxlength="2000" placeholder="Ej.: Memorándum de designación, suplencia temporal o cambio de gestión"></textarea></label>
                    <div v-if="!memberOptions.length" class="alert alert--error field--full">Esta oficina no tiene funcionarios activos. Primero registre o corrija sus contratos.</div>
                    <button class="button button--primary" type="submit" :disabled="!form.user_id || humanResources.busy[`office-manager-${selectedOffice.id}`]">{{ humanResources.busy[`office-manager-${selectedOffice.id}`] ? 'Guardando…' : selectedOffice.current_manager ? 'Confirmar reemplazo' : 'Confirmar designación' }}</button>
                </form>
            </div>

            <section v-if="selectedOffice" class="panel office-manager-history">
                <div class="section-heading"><div><p class="eyebrow">Trazabilidad</p><h2>Historial de responsables</h2></div></div>
                <div v-if="!selectedOffice.history.length" class="empty-state">Aún no existen designaciones registradas.</div>
                <div v-else class="office-manager-history__list">
                    <article v-for="assignment in selectedOffice.history" :key="assignment.id">
                        <div><strong>{{ assignment.user?.name }}</strong><span>{{ assignment.position_title || 'Sin cargo descriptivo' }}</span><small>{{ formatDate(assignment.effective_from) }} — {{ assignment.effective_to ? formatDate(assignment.effective_to) : 'Actualidad' }}</small></div>
                        <div><span class="badge" :class="`badge--manager-${assignment.state}`">{{ assignment.state_label }}</span><small v-if="assignment.designated_by?.name">Designado por {{ assignment.designated_by.name }}</small></div>
                    </article>
                </div>
            </section>
        </template>

        <div v-if="endingAssignment" class="modal-backdrop" @click.self="endingAssignment = null">
            <section class="modal" role="dialog" aria-modal="true">
                <header class="modal__header"><div><p class="eyebrow">Cierre auditable</p><h2>Finalizar responsabilidad</h2><p class="muted">{{ endingAssignment.user?.name }} dejará de ejercer la responsabilidad desde la fecha indicada.</p></div><button class="icon-button" type="button" @click="endingAssignment = null">×</button></header>
                <form class="form-grid" @submit.prevent="finishAssignment">
                    <label class="field field--full"><span>Fecha y hora efectiva *</span><input v-model="endForm.effective_to" type="datetime-local" :min="endingAssignment.effective_from?.slice(0, 16)" required></label>
                    <label class="field field--full"><span>Motivo *</span><textarea v-model.trim="endForm.closure_note" rows="3" maxlength="2000" required placeholder="Indique el motivo de finalización o reemplazo"></textarea></label>
                    <footer class="modal__actions field--full"><button class="button button--ghost" type="button" @click="endingAssignment = null">Cancelar</button><button class="button button--primary" type="submit" :disabled="humanResources.busy[`office-manager-end-${endingAssignment.id}`]">Confirmar finalización</button></footer>
                </form>
            </section>
        </div>
    </section>
</template>
