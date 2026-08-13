<script setup>
import { computed, onMounted, ref } from 'vue';
import { useDocumentManagementStore } from '../stores/document-management';
import { useSessionStore } from '../stores/session';

const documents = useDocumentManagementStore();
const session = useSessionStore();
const search = ref('');
const resetCandidate = ref(null);
const credentials = ref(null);

const filteredUsers = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();

    if (!query) return documents.users;

    return documents.users.filter((user) => [user.name, user.email, user.status_label, ...(user.roles?.map((role) => role.name) ?? [])]
        .filter(Boolean)
        .join(' ')
        .toLocaleLowerCase()
        .includes(query));
});

onMounted(() => documents.loadUsers());

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
                <button class="button button--ghost" type="button" :disabled="user.id === session.user.id" :title="user.id === session.user.id ? 'Cambie su propia contraseña desde su sesión' : undefined" @click="resetCandidate = user">Restablecer contraseña</button>
            </article>
        </div>
    </section>

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
