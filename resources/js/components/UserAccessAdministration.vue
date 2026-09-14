<script setup>
/** Administra cuentas y recuperación de acceso sin intentar revelar contraseñas hash. */
import { computed, onMounted, ref } from 'vue';
import { flattenOfficeHierarchy } from '../lib/organization';
import { useDocumentManagementStore } from '../stores/document-management';
import { useSessionStore } from '../stores/session';

const documents = useDocumentManagementStore();
const session = useSessionStore();
const search = ref('');
const resetCandidate = ref(null);
const credentials = ref(null);
const accessCandidate = ref(null);
const roleSelection = ref([]);
const observerDirectOfficeIds = ref([]);

const filteredUsers = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();

    if (!query) return documents.users;

    return documents.users.filter((user) => [user.name, user.email, user.status_label, ...(user.roles?.map((role) => role.name) ?? [])]
        .filter(Boolean)
        .join(' ')
        .toLocaleLowerCase()
        .includes(query));
});
const roles = computed(() => documents.authorizationMatrix?.roles ?? []);
const activeOffices = computed(() => flattenOfficeHierarchy(documents.administrationOffices.filter((office) => office.status === 'active')));
const effectiveObservedOffices = computed(() => {
    const selected = new Set(observerDirectOfficeIds.value.map(Number));
    let changed = true;
    while (changed) {
        changed = false;
        for (const office of activeOffices.value) {
            if (office.parent_id && selected.has(Number(office.parent_id)) && !selected.has(Number(office.id))) {
                selected.add(Number(office.id));
                changed = true;
            }
        }
    }
    return activeOffices.value.filter((office) => selected.has(Number(office.id)));
});

onMounted(() => Promise.all([
    documents.loadUsers(),
    documents.loadAuthorizationMatrix(),
    documents.loadOrganizationAdministration(),
]));

function toggleRole(value) {
    const index = roleSelection.value.indexOf(value);
    if (index >= 0) roleSelection.value.splice(index, 1);
    else roleSelection.value.push(value);
}

function toggleObservedOffice(value) {
    const id = Number(value);
    const index = observerDirectOfficeIds.value.indexOf(id);
    if (index >= 0) observerDirectOfficeIds.value.splice(index, 1);
    else observerDirectOfficeIds.value.push(id);
}

async function openAccess(user) {
    accessCandidate.value = user;
    roleSelection.value = user.roles?.map((role) => role.code) ?? [];
    observerDirectOfficeIds.value = [];
    if (roleSelection.value.includes('observer')) {
        const scope = await documents.loadObserverScope(user.id);
        observerDirectOfficeIds.value = [...scope.direct_office_ids];
    }
}

async function saveAccess() {
    if (!accessCandidate.value) return;
    const userId = accessCandidate.value.id;
    const current = accessCandidate.value.roles?.map((role) => role.code) ?? [];

    try {
        for (const role of roleSelection.value.filter((role) => !current.includes(role))) {
            await documents.assignUserRole(userId, role);
        }
        for (const role of current.filter((role) => !roleSelection.value.includes(role))) {
            await documents.removeUserRole(userId, role);
        }
        if (roleSelection.value.includes('observer')) {
            await documents.saveObserverScope(userId, observerDirectOfficeIds.value.map(Number));
        }
        accessCandidate.value = null;
    } catch {
        // El store conserva el mensaje preciso enviado por la Policy o el servicio.
    }
}

async function confirmReset() {
    if (!resetCandidate.value) return;

    try {
        const result = await documents.resetUserPassword(resetCandidate.value.id);
        credentials.value = {
            name: result.user.name,
            email: result.user.email,
            temporaryPassword: result.temporaryPassword,
        };
        resetCandidate.value = null;
    } catch {
        // The store retains the server message for the administrator.
    }
}
</script>

<template>
    <section class="administration-section user-access-administration">
        <header class="section-heading">
            <div><p class="eyebrow">Seguridad de acceso</p><h2>Usuarios y contraseñas</h2><p class="muted">Restablezca por emergencia la contraseña de un usuario. SIGAL cerrará sus sesiones y exigirá una nueva contraseña al ingresar.</p></div>
            <button class="button button--ghost" type="button" :disabled="documents.busy.users" @click="documents.loadUsers(true)">{{ documents.busy.users ? 'Actualizando…' : 'Actualizar lista' }}</button>
        </header>

        <p v-if="documents.error" class="alert alert--error" role="alert">{{ documents.error }}</p>
        <label class="field user-access-search"><span>Buscar usuario</span><input v-model="search" type="search" placeholder="Nombre, correo, rol o estado"></label>

        <div v-if="documents.busy.users" class="empty-state">Cargando cuentas del sistema…</div>
        <div v-else-if="!filteredUsers.length" class="empty-state">No se encontraron usuarios.</div>
        <div v-else class="user-access-list">
            <article v-for="user in filteredUsers" :key="user.id" class="user-access-row">
                <div class="user-access-row__identity"><span>{{ user.name?.slice(0, 1) || '?' }}</span><div><strong>{{ user.name }}</strong><small>{{ user.email }}</small></div></div>
                <div class="user-access-row__meta"><span class="employee-row__state" :class="user.status === 'active' ? 'is-active' : 'is-inactive'">{{ user.status_label }}</span><small>{{ user.roles?.map((role) => role.name).join(' · ') || 'Sin roles vigentes' }}</small><small v-if="user.must_change_password">Cambio de contraseña pendiente</small></div>
                <div class="user-access-row__actions"><button class="button button--secondary" type="button" @click="openAccess(user)">Roles y alcance</button><button class="button button--ghost" type="button" :disabled="user.id === session.user.id" :title="user.id === session.user.id ? 'Cambie su propia contraseña desde su sesión' : undefined" @click="resetCandidate = user">Restablecer contraseña</button></div>
            </article>
        </div>
    </section>

    <div v-if="accessCandidate" class="modal-backdrop" @click.self="accessCandidate = null">
        <section class="modal access-role-modal" role="dialog" aria-modal="true" aria-labelledby="role-access-title">
            <header class="modal__header"><div><p class="eyebrow">Seguridad de la cuenta</p><h2 id="role-access-title">Roles y alcance</h2><p class="muted">{{ accessCandidate.name }} · {{ accessCandidate.email }}</p></div><button class="icon-button" type="button" aria-label="Cerrar" @click="accessCandidate = null">×</button></header>
            <div class="role-checklist">
                <label v-for="role in roles" :key="role.code" class="check-option"><input type="checkbox" :checked="roleSelection.includes(role.code)" @change="toggleRole(role.code)"><span><strong>{{ role.name }}</strong><small>{{ role.code === 'super_administrator' ? 'Acceso global protegido.' : 'Sus funciones dependen de la matriz de permisos.' }}</small></span></label>
            </div>
            <section v-if="roleSelection.includes('observer')" class="observer-scope-editor">
                <div><p class="eyebrow">Observación documental</p><h3>Oficinas raíz autorizadas</h3><p class="muted">Al marcar una oficina también se incluyen automáticamente todas sus dependencias actuales. Los expedientes confidenciales continúan ocultos sin una concesión expresa.</p></div>
                <div class="observer-office-grid"><label v-for="office in activeOffices" :key="office.id" class="check-option" :style="{ paddingLeft: `${12 + office.depth * 16}px` }"><input type="checkbox" :checked="observerDirectOfficeIds.includes(Number(office.id))" @change="toggleObservedOffice(office.id)"><span>{{ office.code }} · {{ office.name }}</span></label></div>
                <div class="scope-preview"><strong>Alcance efectivo: {{ effectiveObservedOffices.length }} oficinas</strong><span v-if="effectiveObservedOffices.length">{{ effectiveObservedOffices.map((office) => office.name).join(' · ') }}</span><span v-else>El Observador no verá expedientes ordinarios hasta seleccionar al menos una oficina.</span></div>
            </section>
            <footer class="modal__actions"><button class="button button--ghost" type="button" @click="accessCandidate = null">Cancelar</button><button class="button button--primary" type="button" :disabled="documents.busy[`observer-scope-${accessCandidate.id}`]" @click="saveAccess">Guardar roles y alcance</button></footer>
        </section>
    </div>

    <div v-if="resetCandidate" class="modal-backdrop" @click.self="resetCandidate = null">
        <section class="modal credential-modal" role="dialog" aria-modal="true" aria-labelledby="password-reset-title">
            <header class="modal__header"><div><p class="eyebrow">Acción de emergencia</p><h2 id="password-reset-title">Restablecer contraseña</h2><p class="muted">{{ resetCandidate.name }} perderá el acceso desde todas sus sesiones actuales.</p></div><button class="icon-button" type="button" aria-label="Cerrar" @click="resetCandidate = null">x</button></header>
            <div class="credential-content"><p>SIGAL generará una contraseña temporal segura. Se mostrará una sola vez para entregarla personalmente al usuario.</p><div><span>Cuenta</span><strong>{{ resetCandidate.email }}</strong></div></div>
            <footer class="modal__actions"><button class="button button--ghost" type="button" @click="resetCandidate = null">Cancelar</button><button class="button button--danger" type="button" :disabled="documents.busy[`user-password-reset-${resetCandidate.id}`]" @click="confirmReset">{{ documents.busy[`user-password-reset-${resetCandidate.id}`] ? 'Restableciendo…' : 'Confirmar restablecimiento' }}</button></footer>
        </section>
    </div>

    <div v-if="credentials" class="modal-backdrop">
        <section class="modal credential-modal" role="alertdialog" aria-modal="true" aria-labelledby="temporary-password-title">
            <header class="modal__header"><div><p class="eyebrow">Contraseña temporal</p><h2 id="temporary-password-title">Guarde estos datos ahora</h2></div></header>
            <div class="credential-content"><p>Entregue esta contraseña por un canal seguro. No se volverá a mostrar y la persona deberá cambiarla en su próximo ingreso.</p><div><span>Usuario</span><strong>{{ credentials.name }}</strong></div><div><span>Correo SIGAL</span><strong>{{ credentials.email }}</strong></div><div><span>Contraseña temporal</span><strong class="credential-password">{{ credentials.temporaryPassword }}</strong></div></div>
            <footer class="modal__actions"><button class="button button--primary" type="button" @click="credentials = null">Ya la guardé</button></footer>
        </section>
    </div>
</template>
