<script setup>
/** Lista pre-sesión: no decide ni transfiere custodia; solo expone lo remitido al Pleno. */
import { onMounted, ref } from 'vue';
import { useDocumentManagementStore } from '../stores/document-management';

const emit = defineEmits(['open-expedient']);
const documents = useDocumentManagementStore();
const error = ref(null);

function formatDate(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('es-BO', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value));
}

async function load(page = 1) {
    try {
        error.value = null;
        await documents.loadPlenaryReferrals(page);
    } catch (exception) {
        error.value = exception.message;
    }
}

onMounted(() => load());
</script>

<template>
    <section class="workspace workspace--wide">
        <header class="page-heading">
            <div><p class="eyebrow">Gestión legislativa</p><h1>Lista de espera del Pleno</h1><p class="muted">Expedientes remitidos formalmente por Secretaría General para conocimiento de todos los asambleístas. La custodia continúa en Secretaría General.</p></div>
            <button class="button button--ghost" :disabled="documents.busy['plenary-referrals']" type="button" @click="load()">Actualizar</button>
        </header>

        <p v-if="error || documents.error" class="alert alert--error">{{ error || documents.error }}</p>
        <div v-if="documents.busy['plenary-referrals']" class="empty-state">Actualizando lista del Pleno…</div>
        <div v-else-if="!documents.plenaryReferrals.length" class="empty-state">No existen expedientes pendientes de tratamiento por el Pleno.</div>
        <div v-else class="request-list">
            <article v-for="referral in documents.plenaryReferrals" :key="referral.id" class="request-card">
                <div>
                    <p class="eyebrow">{{ referral.expedient?.route_code }} <span v-if="referral.expedient?.is_uat" class="uat-badge">UAT</span></p>
                    <h3>{{ referral.expedient?.subject }}</h3>
                    <p>{{ referral.expedient?.summary || 'Sin resumen registrado.' }}</p>
                    <small>{{ referral.expedient?.confidentiality_level?.name }} · {{ referral.expedient?.priority_label }} · Remitido {{ formatDate(referral.referred_at) }} por {{ referral.referred_by?.name }}</small>
                </div>
                <button class="button button--primary" type="button" @click="emit('open-expedient', referral.expedient)">Leer expediente completo</button>
            </article>
        </div>
        <div v-if="documents.plenaryPagination?.last_page > 1" class="inline-actions">
            <button class="button button--ghost" :disabled="documents.plenaryPagination.current_page <= 1" type="button" @click="load(documents.plenaryPagination.current_page - 1)">Anterior</button>
            <span>Página {{ documents.plenaryPagination.current_page }} de {{ documents.plenaryPagination.last_page }}</span>
            <button class="button button--ghost" :disabled="documents.plenaryPagination.current_page >= documents.plenaryPagination.last_page" type="button" @click="load(documents.plenaryPagination.current_page + 1)">Siguiente</button>
        </div>
    </section>
</template>
