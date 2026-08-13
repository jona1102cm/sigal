<script setup>
import { reactive, ref } from 'vue';
import { useSessionStore } from '../stores/session';

const emit = defineEmits(['changed', 'logout']);
const session = useSessionStore();
const error = ref(null);
const form = reactive({ current_password: '', password: '', password_confirmation: '' });

async function submit() {
    error.value = null;

    try {
        await session.changePassword(form);
        emit('changed');
    } catch (exception) {
        error.value = exception.message;
    }
}
</script>

<template>
    <main class="login-layout">
        <section class="login-intro" aria-labelledby="password-change-intro-title">
            <div class="brand brand--light"><span class="brand__mark" aria-hidden="true">S</span><span>SIGAL</span></div>
            <div class="login-intro__body">
                <p class="eyebrow eyebrow--light">Seguridad de la cuenta</p>
                <h1 id="password-change-intro-title">Actualice su contraseña temporal.</h1>
                <p>Por seguridad, no podrá acceder al sistema hasta registrar una contraseña personal.</p>
            </div>
        </section>

        <section class="login-panel" aria-labelledby="password-change-title">
            <form class="login-card" @submit.prevent="submit">
                <div><p class="eyebrow">Cambio obligatorio</p><h2 id="password-change-title">Nueva contraseña</h2><p class="muted">Use al menos 12 caracteres, mayúsculas, minúsculas, números y símbolos.</p></div>
                <p v-if="error" class="alert alert--error" role="alert">{{ error }}</p>
                <label class="field"><span>Contraseña temporal</span><input v-model="form.current_password" type="password" autocomplete="current-password" required></label>
                <label class="field"><span>Nueva contraseña</span><input v-model="form.password" type="password" autocomplete="new-password" required></label>
                <label class="field"><span>Confirmar nueva contraseña</span><input v-model="form.password_confirmation" type="password" autocomplete="new-password" required></label>
                <button class="button button--primary button--wide" :disabled="session.loading" type="submit">{{ session.loading ? 'Actualizando…' : 'Guardar y continuar' }}</button>
                <button class="text-button" type="button" @click="emit('logout')">Salir</button>
            </form>
        </section>
    </main>
</template>
