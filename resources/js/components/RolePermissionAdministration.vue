<script setup>
/** Matriz visual de capacidades globales para los cuatro roles institucionales. */
import { computed, onMounted, ref, watch } from 'vue';
import { useDocumentManagementStore } from '../stores/document-management';

const documents = useDocumentManagementStore();
const selectedRoleId = ref(null);
const selectedCodes = ref([]);
const saved = ref(false);

const matrix = computed(() => documents.authorizationMatrix ?? { roles: [], permissions: [] });
const selectedRole = computed(() => matrix.value.roles.find((role) => Number(role.id) === Number(selectedRoleId.value)) ?? null);
const groupedPermissions = computed(() => {
    const modules = new Map();
    for (const permission of matrix.value.permissions) {
        if (!modules.has(permission.module)) modules.set(permission.module, new Map());
        const sections = modules.get(permission.module);
        if (!sections.has(permission.section)) sections.set(permission.section, []);
        sections.get(permission.section).push(permission);
    }
    return [...modules].map(([module, sections]) => ({
        module,
        sections: [...sections].map(([section, permissions]) => ({ section, permissions })),
    }));
});

watch(selectedRole, (role) => {
    selectedCodes.value = [...(role?.permission_codes ?? [])];
    saved.value = false;
}, { immediate: true });

onMounted(async () => {
    await documents.loadAuthorizationMatrix();
    selectedRoleId.value = matrix.value.roles[0]?.id ?? null;
});

function toggle(code) {
    const index = selectedCodes.value.indexOf(code);
    if (index >= 0) selectedCodes.value.splice(index, 1);
    else selectedCodes.value.push(code);
}

async function save() {
    if (!selectedRole.value || selectedRole.value.protected) return;
    saved.value = false;
    await documents.saveRolePermissions(selectedRole.value.id, selectedCodes.value);
    selectedRoleId.value = selectedRole.value.id;
    saved.value = true;
}
</script>

<template>
    <section class="administration-section role-permission-administration">
        <header class="section-heading"><div><p class="eyebrow">Control de acceso</p><h2>Roles y permisos</h2><p class="muted">Los checks habilitan funciones, pero cada solicitud continúa limitada por oficina, custodia y confidencialidad desde el servidor.</p></div></header>
        <p v-if="documents.error" class="alert alert--error" role="alert">{{ documents.error }}</p>
        <div v-if="documents.busy['authorization-matrix'] && !matrix.roles.length" class="empty-state">Cargando matriz de seguridad…</div>
        <template v-else>
            <div class="role-selector" role="tablist" aria-label="Roles institucionales">
                <button v-for="role in matrix.roles" :key="role.id" type="button" :class="{ 'is-active': role.id === selectedRoleId }" @click="selectedRoleId = role.id">{{ role.name }}</button>
            </div>
            <div v-if="selectedRole" class="permission-matrix">
                <div v-if="selectedRole.protected" class="security-notice"><strong>Rol protegido</strong><span>El Superadministrador posee obligatoriamente todas las capacidades y sus checks no se pueden desmarcar.</span></div>
                <section v-for="group in groupedPermissions" :key="group.module" class="permission-module">
                    <header><h3>{{ group.module }}</h3></header>
                    <div v-for="section in group.sections" :key="section.section" class="permission-section">
                        <h4>{{ section.section }}</h4>
                        <label v-for="permission in section.permissions" :key="permission.code" class="permission-option" :class="{ 'is-protected': permission.super_administrator_only && !selectedRole.protected }">
                            <input type="checkbox" :checked="selectedCodes.includes(permission.code)" :disabled="selectedRole.protected || permission.super_administrator_only" @change="toggle(permission.code)">
                            <span><strong>{{ permission.name }}</strong><small>{{ permission.description }}</small></span>
                        </label>
                    </div>
                </section>
                <footer v-if="!selectedRole.protected" class="settings-actions"><span v-if="saved" class="saved-indicator">Permisos actualizados</span><button class="button button--primary" type="button" :disabled="documents.busy[`role-permissions-${selectedRole.id}`]" @click="save">{{ documents.busy[`role-permissions-${selectedRole.id}`] ? 'Guardando…' : 'Guardar permisos' }}</button></footer>
            </div>
        </template>
    </section>
</template>
