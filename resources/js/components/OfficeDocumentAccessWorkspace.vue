<script setup>
/** Configuración permanente de reparto para las oficinas dirigidas por el usuario. */
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

const allOffices = computed(() => flattenOfficeHierarchy(documents.catalogs.offices));
const configurableOffices = computed(() => {
    if (session.isSuperAdministrator) return allOffices.value.filter((office) => office.requires_manager);
    const managedIds = (session.user?.office_memberships ?? [])
        .filter((membership) => membership.membership_role === 'manager')
        .map((membership) => Number(membership.office_id));
    return allOffices.value.filter((office) => office.requires_manager && managedIds.includes(Number(office.id)));
});
const setting = computed(() => documents.officeAccessSetting);
const officialMembers = computed(() => setting.value?.members?.filter((member) => member.membership_role === 'official') ?? []);

watch(setting, (value) => {
    if (!value) return;
    form.mode = value.mode;
    form.authorized_user_ids = [...(value.authorized_user_ids ?? [])];
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
    const id = Number(userId);
    const index = form.authorized_user_ids.indexOf(id);
    if (index >= 0) form.authorized_user_ids.splice(index, 1);
    else form.authorized_user_ids.push(id);
}

async function save() {
    saved.value = false;
    await documents.saveOfficeAccessSetting(Number(selectedOfficeId.value), {
        mode: form.mode,
        authorized_user_ids: form.mode === 'authorized_team' ? form.authorized_user_ids : [],
    });
    saved.value = true;
}
</script>

<template>
    <section class="workspace workspace--wide office-access-workspace">
        <header class="page-heading">
            <div><p class="eyebrow">Gestión documental</p><h1>Acceso de mi oficina</h1><p class="muted">La modalidad guardada se aplicará automáticamente a todos los expedientes que lleguen posteriormente. Puede modificarla cuando cambie la organización del trabajo.</p></div>
        </header>

        <p v-if="documents.error" class="alert alert--error" role="alert">{{ documents.error }}</p>
        <div v-if="!configurableOffices.length" class="empty-state">No posee una jefatura vigente que permita configurar el reparto documental.</div>
        <template v-else>
            <label class="field office-access-workspace__office"><span>Oficina a configurar</span><SearchableSelect v-model="selectedOfficeId" :options="configurableOffices.map((office) => ({ value: office.id, label: office.hierarchy_label }))" placeholder="Seleccione una oficina" /></label>

            <div v-if="documents.busy[`office-access-${selectedOfficeId}`] && !setting" class="empty-state">Cargando configuración…</div>
            <form v-else-if="setting" class="office-access-settings" @submit.prevent="save">
                <section class="choice-card" :class="{ 'is-selected': form.mode === 'manager_assignment' }">
                    <label><input v-model="form.mode" type="radio" value="manager_assignment"><span><strong>Asignación individual por la jefatura</strong><small>Solo la jefatura recibe inicialmente el expediente. En cada ingreso designará un responsable operativo y, si corresponde, colaboradores de lectura.</small></span></label>
                </section>
                <section class="choice-card" :class="{ 'is-selected': form.mode === 'authorized_team' }">
                    <label><input v-model="form.mode" type="radio" value="authorized_team"><span><strong>Equipo autorizado permanente</strong><small>Los funcionarios seleccionados podrán ver, responder y derivar automáticamente todos los expedientes que lleguen a esta oficina.</small></span></label>
                </section>

                <section v-if="form.mode === 'authorized_team'" class="authorized-team-panel">
                    <div><p class="eyebrow">Equipo permanente</p><h2>Funcionarios autorizados</h2><p class="muted">La jefatura conserva acceso aunque no figure en esta lista.</p></div>
                    <div v-if="!officialMembers.length" class="empty-state">Esta oficina no tiene funcionarios dependientes vigentes.</div>
                    <div v-else class="authorization-checklist">
                        <label v-for="member in officialMembers" :key="member.user_id" class="check-option">
                            <input type="checkbox" :checked="form.authorized_user_ids.includes(Number(member.user_id))" @change="toggleMember(member.user_id)">
                            <span><strong>{{ member.name }}</strong><small>{{ member.position_title }}</small></span>
                        </label>
                    </div>
                </section>

                <footer class="settings-actions"><span v-if="saved" class="saved-indicator">Configuración actualizada</span><button class="button button--primary" type="submit" :disabled="documents.busy[`office-access-${selectedOfficeId}`]">{{ documents.busy[`office-access-${selectedOfficeId}`] ? 'Guardando…' : 'Guardar configuración' }}</button></footer>
            </form>
        </template>
    </section>
</template>
