<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useDocumentManagementStore } from '../stores/document-management';

const documents = useDocumentManagementStore();
const error = ref(null);
const selectedTypeId = ref(null);
const filter = ref('');
const form = reactive({ code: '', name: '', category: 'Administrativo' });

const filteredTypes = computed(() => {
    const query = filter.value.toLocaleLowerCase('es');
    return documents.administrationExpedientTypes.filter((type) => !query || `${type.code} ${type.name} ${type.category}`.toLocaleLowerCase('es').includes(query));
});

function reset() {
    selectedTypeId.value = null;
    error.value = null;
    Object.assign(form, { code: '', name: '', category: 'Administrativo' });
}

function selectType(type) {
    selectedTypeId.value = type.id;
    error.value = null;
    Object.assign(form, { code: type.code, name: type.name, category: type.category });
}

async function save() {
    try {
        await documents.saveExpedientType({
            code: form.code.trim().toUpperCase(),
            name: form.name.trim(),
            category: form.category.trim(),
        }, selectedTypeId.value);
        reset();
    } catch (exception) {
        error.value = exception.message;
    }
}

async function changeStatus(type, action) {
    try {
        await documents.changeExpedientTypeStatus(type, action);
        if (selectedTypeId.value === type.id) reset();
    } catch (exception) {
        error.value = exception.message;
    }
}

onMounted(async () => {
    try {
        await documents.loadExpedientTypeAdministration();
    } catch (exception) {
        error.value = exception.message;
    }
});
</script>

<template>
    <section class="administration-section">
        <header class="section-heading section-heading--administration">
            <div>
                <p class="eyebrow">Catálogo operativo</p>
                <h2>Tipos de expediente</h2>
                <p class="muted">Los tipos activos se muestran automáticamente al registrar un expediente.</p>
            </div>
            <button class="button button--primary" type="button" @click="reset">+ Nuevo tipo</button>
        </header>

        <p v-if="error || documents.error" class="alert alert--error" role="alert">{{ error || documents.error }}</p>

        <div class="type-administration-layout">
            <section class="type-list panel">
                <header><label class="search-field"><span aria-hidden="true">⌕</span><input v-model.trim="filter" type="search" placeholder="Buscar tipo o categoría"></label></header>
                <div v-if="documents.busy['expedient-types-administration']" class="empty-state">Cargando tipos de expediente…</div>
                <div v-else-if="!filteredTypes.length" class="empty-state">No hay tipos que coincidan con la búsqueda.</div>
                <button v-for="type in filteredTypes" :key="type.id" class="type-row" :class="{ 'is-selected': selectedTypeId === type.id, 'is-inactive': type.status === 'inactive' }" type="button" @click="selectType(type)"><div><strong>{{ type.name }}</strong><span>{{ type.category }} · {{ type.code }}</span></div><span class="tree-row__status" :class="`tree-row__status--${type.status}`">{{ type.status_label }}</span></button>
            </section>

            <section class="type-editor panel">
                <header><p class="eyebrow">{{ selectedTypeId ? 'Tipo seleccionado' : 'Nuevo tipo' }}</p><h3>{{ selectedTypeId ? form.name || 'Editar tipo' : 'Registrar tipo de expediente' }}</h3></header>
                <form class="form-grid form-grid--compact" @submit.prevent="save"><label class="field"><span>Código</span><input v-model="form.code" maxlength="50" pattern="[A-Za-z0-9_-]+" placeholder="Ej.: LEGAL_PROCESS" required></label><label class="field"><span>Categoría</span><input v-model.trim="form.category" maxlength="100" list="expedient-categories" required><datalist id="expedient-categories"><option value="Administrativo"></option><option value="Legislativo"></option><option value="Institucional"></option></datalist></label><label class="field form-grid__full"><span>Nombre</span><input v-model.trim="form.name" maxlength="255" placeholder="Ej.: Proceso legislativo" required></label><footer class="form-grid__full inline-actions"><button class="button button--primary" :disabled="documents.busy['expedient-type-save']" type="submit">{{ documents.busy['expedient-type-save'] ? 'Guardando…' : selectedTypeId ? 'Guardar cambios' : 'Crear tipo' }}</button><button v-if="selectedTypeId" class="button button--ghost" type="button" @click="reset">Cancelar</button></footer></form>
                <div v-if="selectedTypeId" class="type-editor__status"><p>Estado actual: <strong>{{ documents.administrationExpedientTypes.find((type) => type.id === selectedTypeId)?.status_label }}</strong></p><button v-if="documents.administrationExpedientTypes.find((type) => type.id === selectedTypeId)?.status === 'active'" class="button button--danger" type="button" @click="changeStatus(documents.administrationExpedientTypes.find((type) => type.id === selectedTypeId), 'inactivate')">Inactivar tipo</button><button v-else class="button button--secondary" type="button" @click="changeStatus(documents.administrationExpedientTypes.find((type) => type.id === selectedTypeId), 'activate')">Activar tipo</button></div>
            </section>
        </div>
    </section>
</template>
