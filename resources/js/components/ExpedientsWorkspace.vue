<script setup>
/** Bandeja documental con vistas separadas para pendientes y finalizados. */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ExpedientDetail from './ExpedientDetail.vue';
import SearchableSelect from './SearchableSelect.vue';
import { useDocumentManagementStore } from '../stores/document-management';

defineProps({ session: { type: Object, required: true } });
const emit = defineEmits(['create-expedient']);
const documents = useDocumentManagementStore();
const search = ref(documents.expedientSearch);
const inboxScope = ref(documents.expedientScope);
const status = ref('all');
const priority = ref('all');
let searchTimer = null;
let refreshTimer = null;
const AUTO_REFRESH_INTERVAL_MS = 60_000;

const filteredExpedients = computed(() => documents.expedients.filter((expedient) => {
    const matchesStatus = status.value === 'all' || expedient.status === status.value;
    const matchesPriority = priority.value === 'all' || expedient.priority === priority.value;
    return matchesStatus && matchesPriority;
}));

const statuses = computed(() => Array.from(new Map(documents.expedients.map((item) => [item.status, item.status_label])).entries()));
const lastUpdatedLabel = computed(() => {
    if (!documents.lastExpedientSyncAt) return 'Sin sincronizar';

    return `Actualizado ${new Intl.DateTimeFormat('es-BO', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(documents.lastExpedientSyncAt))}`;
});

async function openExpedient(expedient) {
    try {
        await documents.selectExpedient(expedient);
    } catch {
        // The store exposes the actionable server message in the workspace.
    }
}

function formatDate(value) {
    return value ? new Intl.DateTimeFormat('es-BO', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T12:00:00`)) : '—';
}

async function synchronizeInbox() {
    if (document.visibilityState !== 'visible' || documents.loading) return;

    try {
        await documents.loadExpedients(
            documents.pagination?.current_page ?? 1,
            search.value,
            inboxScope.value,
            { silent: true },
        );
    } catch {
        // La sincronización automática es silenciosa; el botón Actualizar conserva el error visible.
    }
}

function handleVisibilityChange() {
    if (document.visibilityState === 'visible') synchronizeInbox();
}

onMounted(async () => {
    if (!documents.expedientsLoaded) {
        await documents.loadExpedients(1, search.value, inboxScope.value).catch(() => {});
    }

    refreshTimer = window.setInterval(synchronizeInbox, AUTO_REFRESH_INTERVAL_MS);
    window.addEventListener('focus', synchronizeInbox);
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    window.clearInterval(refreshTimer);
    window.removeEventListener('focus', synchronizeInbox);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
});

watch(search, (value) => {
    // El debounce evita consultar el servidor por cada pulsación sin exigir Enter al usuario.
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        documents.loadExpedients(1, value, inboxScope.value).catch(() => {});
    }, 250);
});

function setInboxScope(scope) {
    if (inboxScope.value === scope) return;

    inboxScope.value = scope;
    status.value = 'all';
    documents.loadExpedients(1, search.value, scope).catch(() => {});
}
</script>

<template>
    <section class="workspace workspace--wide">
        <header class="page-heading">
            <div>
                <p class="eyebrow">Gestión documental</p>
                <h1>Bandeja de expedientes</h1>
                <p class="muted">{{ inboxScope === 'pending' ? 'Atienda primero las respuestas pendientes de su oficina.' : 'Consulte los expedientes ya finalizados o cerrados.' }}</p>
            </div>
            <button v-if="session.canCreateExpedients" class="button button--primary" type="button" @click="emit('create-expedient')">+ Registrar ingreso</button>
        </header>

        <p v-if="documents.error" class="alert alert--error" role="alert">{{ documents.error }}</p>

        <section class="panel inbox-panel">
            <div class="inbox-view-switcher" aria-label="Vista de bandeja">
                <button type="button" :class="{ 'is-active': inboxScope === 'pending' }" @click="setInboxScope('pending')">Pendientes de respuesta</button>
                <button type="button" :class="{ 'is-active': inboxScope === 'finalized' }" @click="setInboxScope('finalized')">Finalizados y cerrados</button>
            </div>
            <div class="inbox-toolbar">
                <label class="search-field"><span aria-hidden="true">⌕</span><input v-model.trim="search" type="search" placeholder="Ruta SIGAL, asunto, remitente o número de documento"></label>
                <label class="filter-field"><span>Estado</span><SearchableSelect v-model="status" :options="[{ value: 'all', label: 'Todos los estados' }, ...statuses.map(([value, label]) => ({ value, label }))]" /><select v-show="false" v-model="status" disabled><option value="all">Todos los estados</option><option v-for="[value, label] in statuses" :key="value" :value="value">{{ label }}</option></select></label>
                <label class="filter-field"><span>Prioridad</span><SearchableSelect v-model="priority" :options="[{ value: 'all', label: 'Todas' }, { value: 'normal', label: 'Normal' }, { value: 'high', label: 'Alta' }, { value: 'urgent', label: 'Urgente' }]" /><select v-show="false" v-model="priority" disabled><option value="all">Todas</option><option value="normal">Normal</option><option value="high">Alta</option><option value="urgent">Urgente</option></select></label>
                <button class="button button--ghost" type="button" :disabled="documents.loading" @click="documents.loadExpedients(documents.pagination?.current_page ?? 1, search, inboxScope)">Actualizar</button>
                <small class="muted" aria-live="polite">{{ lastUpdatedLabel }}</small>
            </div>

            <div v-if="documents.loading" class="empty-state">Cargando expedientes…</div>
            <div v-else-if="!filteredExpedients.length" class="empty-state">{{ inboxScope === 'pending' ? 'No tiene respuestas pendientes con estos filtros.' : 'No se encontraron expedientes finalizados o cerrados con estos filtros.' }}</div>
            <div v-else class="table-wrap">
                <table class="inbox-table">
                    <thead><tr><th>Ruta</th><th>Asunto</th><th>Responsable</th><th>Recepción</th><th>Prioridad</th><th>Estado</th><th><span class="sr-only">Abrir</span></th></tr></thead>
                    <tbody>
                        <tr v-for="expedient in filteredExpedients" :key="expedient.id" :class="[{ 'is-selected': documents.selected?.id === expedient.id }, `inbox-row--priority-${expedient.priority || 'normal'}`]" @click="openExpedient(expedient)">
                            <td><strong>{{ expedient.route_code }}</strong><small>{{ expedient.expedient_type?.name }}</small></td>
                            <td><span class="table-subject">{{ expedient.subject }}</span><small>{{ expedient.sender_name }}</small></td>
                            <td>{{ expedient.responsible_office?.code || '—' }}</td>
                            <td>{{ formatDate(expedient.received_on) }}</td>
                            <td><span class="badge" :class="`badge--priority-${expedient.priority || 'normal'}`">{{ expedient.priority_label || 'Normal' }}</span></td>
                            <td><span class="badge" :class="`badge--${expedient.status}`">{{ expedient.status_label }}</span></td>
                            <td><button class="row-open" type="button" :aria-label="`Abrir ${expedient.route_code}`">Abrir →</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer v-if="documents.pagination?.last_page > 1" class="pagination"><button class="button button--ghost" :disabled="documents.pagination.current_page <= 1" type="button" @click="documents.loadExpedients(documents.pagination.current_page - 1, search, inboxScope)">Anterior</button><span>Página {{ documents.pagination.current_page }} de {{ documents.pagination.last_page }}</span><button class="button button--ghost" :disabled="documents.pagination.current_page >= documents.pagination.last_page" type="button" @click="documents.loadExpedients(documents.pagination.current_page + 1, search, inboxScope)">Siguiente</button></footer>
        </section>

        <ExpedientDetail v-if="documents.selected" :session="session" @close="documents.clearSelected()" />
    </section>
</template>
