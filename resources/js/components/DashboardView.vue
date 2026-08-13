<script setup>
/** Resumen operativo derivado de la bandeja ya autorizada por el backend. */
import { computed } from 'vue';
import { useDocumentManagementStore } from '../stores/document-management';

const emit = defineEmits(['open-expedients', 'create-expedient', 'select-expedient']);
const documents = useDocumentManagementStore();

const metrics = computed(() => {
    const items = documents.expedients;

    return [
        { label: 'En trámite', value: items.filter((item) => ['in_process', 'derived', 'pending_response', 'partially_responded'].includes(item.status)).length, tone: 'blue' },
        { label: 'Por responder', value: items.filter((item) => item.status === 'pending_response').length, tone: 'amber' },
        { label: 'Concluidos', value: items.filter((item) => ['fully_responded', 'archived', 'closed'].includes(item.status)).length, tone: 'green' },
        { label: 'Total visible', value: items.length, tone: 'ink' },
    ];
});

const recent = computed(() => documents.expedients.slice(0, 5));

function formatDate(value) {
    return value ? new Intl.DateTimeFormat('es-BO', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T12:00:00`)) : '—';
}
</script>

<template>
    <section class="workspace">
        <header class="page-heading">
            <div>
                <p class="eyebrow">Vista general</p>
                <h1>Buenos días</h1>
                <p class="muted">Controle el avance de los expedientes bajo su alcance.</p>
            </div>
            <button class="button button--primary" type="button" @click="emit('create-expedient')">+ Registrar ingreso</button>
        </header>

        <section class="metric-grid" aria-label="Resumen de expedientes">
            <article v-for="metric in metrics" :key="metric.label" class="metric-card" :class="`metric-card--${metric.tone}`">
                <p>{{ metric.label }}</p>
                <strong>{{ metric.value }}</strong>
                <span>expedientes</span>
            </article>
        </section>

        <section class="dashboard-grid">
            <article class="panel panel--table">
                <div class="panel__heading">
                    <div>
                        <p class="eyebrow">Actividad reciente</p>
                        <h2>Expedientes bajo seguimiento</h2>
                    </div>
                    <button class="text-button" type="button" @click="emit('open-expedients')">Ver bandeja</button>
                </div>

                <div v-if="documents.loading" class="empty-state">Actualizando la bandeja…</div>
                <div v-else-if="!recent.length" class="empty-state">Aún no hay expedientes visibles para su usuario.</div>
                <div v-else class="recent-list">
                    <button v-for="expedient in recent" :key="expedient.id" class="recent-item" type="button" @click="emit('select-expedient', expedient)">
                        <span class="recent-item__route">{{ expedient.route_code }}</span>
                        <span class="recent-item__subject">{{ expedient.subject }}</span>
                        <span class="badge" :class="`badge--${expedient.status}`">{{ expedient.status_label }}</span>
                        <span class="recent-item__date">{{ formatDate(expedient.received_on) }}</span>
                    </button>
                </div>
            </article>

            <aside class="panel quick-guide">
                <p class="eyebrow">Flujo institucional</p>
                <h2>Una actuación a la vez</h2>
                <ol>
                    <li><span>1</span><div><strong>Registre</strong><small>Incorpore el documento de ingreso y defina la oficina responsable.</small></div></li>
                    <li><span>2</span><div><strong>Derive</strong><small>Asigne destinatarios, instrucción y plazo.</small></div></li>
                    <li><span>3</span><div><strong>Documente</strong><small>Elabore, revise y emita documentos trazables.</small></div></li>
                    <li><span>4</span><div><strong>Resguarde</strong><small>Archivo Central ejecuta el cierre institucional.</small></div></li>
                </ol>
            </aside>
        </section>
    </section>
</template>
