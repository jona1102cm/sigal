<script setup>
/** Formulario público de acceso; el backend limita intentos y valida el estado de la cuenta. */
import { reactive, ref } from 'vue';
import { useSessionStore } from '../stores/session';

const emit = defineEmits(['authenticated']);
const session = useSessionStore();
const error = ref(null);
const form = reactive({ email: '', password: '' });

async function submit() {
    error.value = null;

    try {
        await session.login(form);
        emit('authenticated');
    } catch (exception) {
        error.value = exception.message;
    }
}
</script>

<template>
    <main class="login-layout">
        <section class="login-intro" aria-labelledby="login-intro-title">
            <div class="brand brand--light">
                <span class="brand__mark" aria-hidden="true">S</span>
                <span>SIGAL</span>
            </div>

            <div class="login-intro__body">
                <p class="eyebrow eyebrow--light">Asamblea Legislativa Departamental del Beni</p>
                <h1 id="login-intro-title">Gestión institucional, con trazabilidad completa.</h1>
                <p>
                    Registre, derive y resguarde los expedientes de la institución en un solo flujo seguro.
                </p>
            </div>

            <div class="login-intro__footer">
                <span class="status-dot status-dot--success" aria-hidden="true"></span>
                Sistema institucional seguro
            </div>
        </section>

        <section class="login-panel" aria-labelledby="login-title">
            <form class="login-card" @submit.prevent="submit">
                <div>
                    <p class="eyebrow">Acceso institucional</p>
                    <h2 id="login-title">Bienvenido a SIGAL</h2>
                    <p class="muted">Ingrese sus credenciales para continuar.</p>
                </div>

                <p v-if="error" class="alert alert--error" role="alert">{{ error }}</p>

                <label class="field">
                    <span>Correo electrónico</span>
                    <input v-model.trim="form.email" type="email" autocomplete="email" placeholder="nombre@institucion.gob.bo" required>
                </label>

                <label class="field">
                    <span>Contraseña</span>
                    <input v-model="form.password" type="password" autocomplete="current-password" placeholder="••••••••••" required>
                </label>

                <button class="button button--primary button--wide" :disabled="session.loading" type="submit">
                    {{ session.loading ? 'Verificando acceso…' : 'Ingresar al sistema' }}
                </button>

                <p class="login-help">Si no puede acceder, comuníquese con el administrador del sistema.</p>
            </form>
        </section>
    </main>
</template>
