<script setup>
/** Configura el reparto permanente y las suplencias temporales de una oficina. */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import SearchableSelect from './SearchableSelect.vue';
import { flattenOfficeHierarchy } from '../lib/organization';
import { useDocumentManagementStore } from '../stores/document-management';
import { useSessionStore } from '../stores/session';

const documents = useDocumentManagementStore();
const session = useSessionStore();
const selectedOfficeId = ref('');
const saved = ref(false);
const form = reactive({ mode: 'manager_assignment', authorized_user_ids: [] });
const managerDelegation = reactive({ delegate_user_id: '', effective_from: '', effective_to: '', reason: '' });
const legislativeDelegation = reactive({ delegate_membership_id: '', effective_from: '', effective_to: '', reason: '' });

const allOffices = computed(() => flattenOfficeHierarchy(documents.catalogs.offices));
const configurableOffices = computed(() => {
    if (session.isSuperAdministrator) return allOffices.value.filter((office) => office.requires_manager);

    const officeIds = new Set(
        (session.user?.office_memberships ?? [])
            .filter((membership) => membership.can_view_document_access)
            .map((membership) => Number(membership.office_id)),
    );
    session.legislativeProfiles
        .filter((profile) => profile.is_principal_authority || profile.is_effective_authority)
        .forEach((profile) => officeIds.add(Number(profile.body?.office_id)));

    return allOffices.value.filter((office) => office.requires_manager && officeIds.has(Number(office.id)));
});
const setting = computed(() => documents.officeAccessSetting);
const officialMembers = computed(() => setting.value?.members?.filter((member) => member.membership_role === 'official') ?? []);
const leadership = computed(() => setting.value?.leadership ?? null);

watch(setting, (value) => {
    if (!value) return;
    form.mode = value.mode;
    form.authorized_user_ids = [...(value.authorized_user_ids ?? [])];
    Object.assign(managerDelegation, { delegate_user_id: '', effective_from: '', effective_to: '', reason: '' });
    Object.assign(legislativeDelegation, { delegate_membership_id: '', effective_from: '', effective_to: '', reason: '' });
}, { immediate: true });

watch(selectedOfficeId, async (value) => {
    saved.value = false;
    documents.officeAccessSetting = null;
    if (value) await documents.loadOfficeAccessSetting(Number(value));
});

onMounted(async () => {
    if (!documents.catalogs.offices.length) await documents.loadCatalogs();
    selectedOfficeId.value = configurableOffices.value[0]?.id ?? '';
});

function toggleMember(userId) {
    if (!setting.value?.can_update_setting) return;
    const id = Number(userId);
    const index = form.authorized_user_ids.indexOf(id);
    if (index >= 0) form.authorized_user_ids.splice(index, 1);
    else form.authorized_user_ids.push(id);
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString('es-BO') : 'Sin fecha de cierre';
}

function localNow() {
    const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000);
    return now.toISOString().slice(0, 16);
}

async function save() {
    if (!setting.value?.can_update_setting) return;
    saved.value = false;
    await documents.saveOfficeAccessSetting(Number(selectedOfficeId.value), {
        mode: form.mode,
        authorized_user_ids: form.mode === 'authorized_team' ? form.authorized_user_ids : [],
    });
    saved.value = true;
}

async function createManagerDelegation() {
    await documents.createOfficeManagerDelegation(Number(selectedOfficeId.value), {
        delegate_user_id: Number(managerDelegation.delegate_user_id),
        effective_from: managerDelegation.effective_from,
        effective_to: managerDelegation.effective_to,
        reason: managerDelegation.reason,
    });
}

async function endManagerDelegation(delegationId) {
    await documents.endOfficeManagerDelegation(Number(selectedOfficeId.value), delegationId, localNow());
}

async function createLegislativeDelegation() {
    await documents.createOfficeLegislativeDelegation(Number(selectedOfficeId.value), {
        delegate_membership_id: Number(legislativeDelegation.delegate_membership_id),
        effective_from: legislativeDelegation.effective_from,
        effective_to: legislativeDelegation.effective_to,
        reason: legislativeDelegation.reason,
    });
}
</script>

<template>
    <section class="workspace workspace--wide office-access-workspace">
        <header class="page-heading">
            <div><p class="eyebrow">Gestión documental</p><h1>Acceso de mi oficina</h1><p class="muted">Aquí se define una sola vez cómo trabajará el equipo con toda correspondencia futura y, cuando corresponda, se registra una suplencia temporal auditable.</p></div>
        </header>

        <p v-if="documents.error" class="alert alert--error" role="alert">{{ documents.error }}</p>
        <div v-if="!configurableOffices.length" class="empty-state">No posee una responsabilidad titular o delegada que permita consultar esta configuración.</div>
        <template v-else>
            <label class="field office-access-workspace__office"><span>Oficina a configurar</span><SearchableSelect v-model="selectedOfficeId" :options="configurableOffices.map((office) => ({ value: office.id, label: office.hierarchy_label }))" placeholder="Seleccione una oficina" /></label>

            <div v-if="documents.busy[`office-access-${selectedOfficeId}`] && !setting" class="empty-state">Cargando configuración…</div>
            <div v-else-if="setting" class="office-access-settings">
                <section class="office-leadership-panel">
                    <div><p class="eyebrow">Responsabilidad vigente</p><h2>{{ setting.office_kind === 'legislative_body' ? leadership.body_name : setting.office.name }}</h2></div>
                    <div class="leadership-summary">
                        <div><small>Titular</small><strong>{{ leadership.principal?.name || 'Sin titular vigente' }}</strong><span v-if="leadership.principal?.role_label">{{ leadership.principal.role_label }}</span></div>
                        <div><small>Autoridad operativa actual</small><strong>{{ leadership.effective?.name || 'Sin autoridad vigente' }}</strong><span>{{ leadership.effective?.kind === 'delegate' ? 'Delegación temporal' : 'Titular' }}</span></div>
                    </div>
                    <p v-if="!setting.can_update_setting" class="alert alert--info">Conserva acceso de consulta por ser titular, pero durante la suplencia solamente la autoridad operativa actual puede modificar el reparto o tramitar la correspondencia.</p>

                    <form v-if="setting.office_kind === 'administrative' && setting.can_manage_manager_delegations" class="leadership-delegation-form" @submit.prevent="createManagerDelegation">
                        <div><strong>Delegación administrativa temporal</strong><small>La persona delegada asumirá la operación; el titular conservará lectura y trazabilidad.</small></div>
                        <label class="field"><span>Funcionario delegado</span><SearchableSelect v-model="managerDelegation.delegate_user_id" :options="leadership.delegate_candidates.map((candidate) => ({ value: candidate.user_id, label: `${candidate.name} · ${candidate.position_title}` }))" placeholder="Seleccione un funcionario" required /></label>
                        <label class="field"><span>Desde</span><input v-model="managerDelegation.effective_from" type="datetime-local" required></label>
                        <label class="field"><span>Hasta</span><input v-model="managerDelegation.effective_to" type="datetime-local" required></label>
                        <label class="field field--full"><span>Motivo</span><textarea v-model.trim="managerDelegation.reason" rows="2" maxlength="2000" required></textarea></label>
                        <button class="button button--secondary" type="submit" :disabled="documents.busy[`office-delegation-${selectedOfficeId}`]">Registrar delegación</button>
                        <button v-if="leadership.active_delegation_id" class="button button--ghost" type="button" :disabled="documents.busy[`office-delegation-end-${leadership.active_delegation_id}`]" @click="endManagerDelegation(leadership.active_delegation_id)">Finalizar delegación vigente ahora</button>
                    </form>

                    <form v-if="setting.office_kind === 'legislative_body' && setting.can_manage_legislative_delegations" class="leadership-delegation-form" @submit.prevent="createLegislativeDelegation">
                        <div><strong>Delegación legislativa temporal</strong><small>Solo puede recaer en la autoridad suplente prevista para la bancada o comisión.</small></div>
                        <label class="field"><span>Autoridad delegada</span><SearchableSelect v-model="legislativeDelegation.delegate_membership_id" :options="leadership.delegate_candidates.map((candidate) => ({ value: candidate.membership_id, label: `${candidate.name} · ${candidate.role_label}` }))" placeholder="Seleccione una autoridad" required /></label>
                        <label class="field"><span>Desde</span><input v-model="legislativeDelegation.effective_from" type="datetime-local" required></label>
                        <label class="field"><span>Hasta</span><input v-model="legislativeDelegation.effective_to" type="datetime-local" required></label>
                        <label class="field field--full"><span>Motivo</span><textarea v-model.trim="legislativeDelegation.reason" rows="2" maxlength="2000" required></textarea></label>
                        <button class="button button--secondary" type="submit" :disabled="documents.busy[`office-legislative-delegation-${selectedOfficeId}`]">Registrar delegación</button>
                    </form>

                    <details v-if="leadership.delegations?.length" class="leadership-history"><summary>Ver historial de delegaciones ({{ leadership.delegations.length }})</summary><article v-for="delegation in leadership.delegations" :key="delegation.id"><strong>{{ delegation.principal_name }} → {{ delegation.delegate_name }}</strong><span>{{ delegation.reason }}</span><small>{{ formatDate(delegation.effective_from) }} — {{ delegation.cancelled_at ? 'Cancelada' : formatDate(delegation.effective_to) }}</small></article></details>
                </section>

                <section class="choice-card" :class="{ 'is-selected': form.mode === 'manager_assignment', 'is-disabled': !setting.can_update_setting }">
                    <label><input v-model="form.mode" type="radio" value="manager_assignment" :disabled="!setting.can_update_setting"><span><strong>Asignación individual por la autoridad</strong><small>Solo la autoridad operativa recibe inicialmente el expediente. En cada ingreso designará un responsable y, si corresponde, colaboradores de lectura.</small></span></label>
                </section>
                <section class="choice-card" :class="{ 'is-selected': form.mode === 'authorized_team', 'is-disabled': !setting.can_update_setting }">
                    <label><input v-model="form.mode" type="radio" value="authorized_team" :disabled="!setting.can_update_setting"><span><strong>Equipo autorizado permanente</strong><small>Los funcionarios seleccionados podrán ver, responder y derivar automáticamente todos los expedientes que lleguen a esta oficina.</small></span></label>
                </section>

                <section v-if="form.mode === 'authorized_team'" class="authorized-team-panel">
                    <div><p class="eyebrow">Equipo permanente</p><h2>Funcionarios autorizados</h2><p class="muted">La autoridad vigente conserva acceso aunque no figure en esta lista.</p></div>
                    <div v-if="!officialMembers.length" class="empty-state">Esta oficina no tiene personal administrativo dependiente vigente.</div>
                    <div v-else class="authorization-checklist">
                        <label v-for="member in officialMembers" :key="member.user_id" class="check-option">
                            <input type="checkbox" :disabled="!setting.can_update_setting" :checked="form.authorized_user_ids.includes(Number(member.user_id))" @change="toggleMember(member.user_id)">
                            <span><strong>{{ member.name }}</strong><small>{{ member.position_title }}</small></span>
                        </label>
                    </div>
                </section>

                <footer v-if="setting.can_update_setting" class="settings-actions"><span v-if="saved" class="saved-indicator">Configuración actualizada</span><button class="button button--primary" type="button" :disabled="documents.busy[`office-access-${selectedOfficeId}`]" @click="save">{{ documents.busy[`office-access-${selectedOfficeId}`] ? 'Guardando…' : 'Guardar configuración' }}</button></footer>
            </div>
        </template>
    </section>
</template>
