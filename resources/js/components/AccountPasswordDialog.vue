<script setup>
/** Permite al usuario autenticado rotar su propia contraseña y revocar sesiones anteriores. */
import { reactive, ref } from 'vue';
import { useSessionStore } from '../stores/session';

const emit = defineEmits(['close', 'changed']);
const session = useSessionStore();
const error = ref(null);
const form = reactive({ current_password: '', password: '', password_confirmation: '' });

async function submit() {
    error.value = null;

    try {
        await session.changePassword(form);
        emit('changed');
        emit('close');
    } catch (exception) {
        error.value = exception.message;
    }
}
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <section class="modal credential-modal" role="dialog" aria-modal="true" aria-labelledby="account-password-title">
            <header class="modal__header"><div><p class="eyebrow">Seguridad de la cuenta</p><h2 id="account-password-title">Cambiar contraseña</h2><p class="muted">La nueva contraseña debe tener 12 caracteres o más, combinando mayúsculas, minúsculas, números y símbolos.</p></div><button class="icon-button" type="button" aria-label="Cerrar" @click="emit('close')">x</button></header>
            <form class="credential-content" @submit.prevent="submit">
                <p v-if="error" class="alert alert--error" role="alert">{{ error }}</p>
                <label class="field"><span>Contraseña actual</span><input v-model="form.current_password" type="password" autocomplete="current-password" required></label>
                <label class="field"><span>Nueva contraseña</span><input v-model="form.password" type="password" autocomplete="new-password" required></label>
                <label class="field"><span>Confirmar nueva contraseña</span><input v-model="form.password_confirmation" type="password" autocomplete="new-password" required></label>
                <footer class="modal__actions"><button class="button button--ghost" type="button" @click="emit('close')">Cancelar</button><button class="button button--primary" type="submit" :disabled="session.loading">{{ session.loading ? 'Guardando…' : 'Guardar contraseña' }}</button></footer>
            </form>
        </section>
    </div>
</template>
