<script setup>
import { onMounted, ref } from 'vue';
import { useDocumentManagementStore } from '../stores/document-management';
import { useHumanResourcesStore } from '../stores/human-resources';

const documents = useDocumentManagementStore();
const humanResources = useHumanResourcesStore();
const confirmationOpen = ref(false);
const confirmation = ref('');
const completed = ref(null);

onMounted(() => documents.loadOperationalResetSummary().catch(() => {}));

async function performReset() {
    completed.value = await documents.performOperationalReset(confirmation.value);
    humanResources.$reset();
    confirmation.value = '';
    confirmationOpen.value = false;
    await Promise.all([documents.loadCatalogs(), documents.loadOperationalResetSummary()]);
}
</script>

<template>
    <section class="administration-section operational-reset">
        <header class="section-heading section-heading--administration">
            <div><p class="eyebrow">Mantenimiento preoperativo</p><h2>Reinicio de datos de prueba</h2><p class="muted">Use esta herramienta solo antes de iniciar la operación institucional definitiva.</p></div>
        </header>

        <p v-if="documents.error" class="alert alert--error" role="alert">{{ documents.error }}</p>
        <p v-if="completed" class="alert alert--success" role="status">Reinicio completado: {{ completed.expedients }} expedientes, {{ completed.documents }} documentos y {{ completed.employees }} funcionarios retirados.</p>

        <div class="operational-reset__grid">
            <article class="operational-reset__card">
                <p class="eyebrow">Se conserva</p>
                <h3>Base institucional preparada</h3>
                <ul><li>Oficinas, cargos, estructura y capacidades.</li><li>Catálogos, tipos documentales, roles y permisos.</li><li>Cuentas técnicas de administración necesarias.</li><li>Historial de auditoría, por trazabilidad y seguridad.</li></ul>
            </article>
            <article class="operational-reset__card operational-reset__card--danger">
                <p class="eyebrow">Se elimina</p>
                <h3>Información de operación y prueba</h3>
                <ul><li>Expedientes, documentos, movimientos y archivos adjuntos.</li><li>Legislaturas, Directiva y numeraciones emitidas.</li><li>Funcionarios, contratos, membresías y sus respaldos.</li><li>Sesiones, tareas pendientes y cuentas no técnicas.</li></ul>
            </article>
        </div>

        <div class="operational-reset__summary">
            <div><span>Expedientes</span><strong>{{ documents.operationalResetSummary?.expedients ?? '—' }}</strong></div><div><span>Documentos</span><strong>{{ documents.operationalResetSummary?.documents ?? '—' }}</strong></div><div><span>Funcionarios</span><strong>{{ documents.operationalResetSummary?.employees ?? '—' }}</strong></div><div><span>Contratos</span><strong>{{ documents.operationalResetSummary?.employment_contracts ?? '—' }}</strong></div>
        </div>

        <div class="operational-reset__action">
            <div><strong>Acción irreversible sobre los datos operativos.</strong><p>Se registrará quién ejecutó el reinicio, cuándo y desde qué equipo.</p></div>
            <button v-if="!confirmationOpen" class="button button--danger" type="button" @click="confirmationOpen = true">Preparar reinicio</button>
        </div>

        <form v-if="confirmationOpen" class="operational-reset__confirmation" @submit.prevent="performReset">
            <p>Para continuar, escriba exactamente <strong>REINICIAR SIGAL</strong>.</p>
            <input v-model.trim="confirmation" autocomplete="off" spellcheck="false" aria-label="Confirmación del reinicio" placeholder="REINICIAR SIGAL">
            <div><button class="button button--ghost" type="button" @click="confirmationOpen = false; confirmation = ''">Cancelar</button><button class="button button--danger" type="submit" :disabled="confirmation !== 'REINICIAR SIGAL' || documents.busy['operational-reset']">{{ documents.busy['operational-reset'] ? 'Reiniciando...' : 'Confirmar reinicio' }}</button></div>
        </form>
    </section>
</template>
