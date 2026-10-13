<script setup>
/** Selector múltiple filtrable que mantiene visibles las oficinas elegidas. */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Seleccione una o más opciones' },
    emptyMessage: { type: String, default: 'No se encontraron opciones.' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change']);
const root = ref(null);
const searchInput = ref(null);
const open = ref(false);
const query = ref('');

const selectedValueKeys = computed(() => new Set(props.modelValue.map((value) => String(value))));
const selectedOptions = computed(() => props.options.filter((option) => selectedValueKeys.value.has(String(option.value))));
const filteredOptions = computed(() => {
    const normalizedQuery = query.value.trim().toLocaleLowerCase('es');

    if (!normalizedQuery) return props.options;

    return props.options.filter((option) => option.label.toLocaleLowerCase('es').includes(normalizedQuery));
});

function toggle() {
    if (props.disabled) return;

    open.value = !open.value;
    query.value = '';
    if (open.value) nextTick(() => searchInput.value?.focus());
}

function toggleOption(option) {
    if (props.disabled || option.disabled) return;

    const selected = selectedValueKeys.value.has(String(option.value));
    const values = selected
        ? props.modelValue.filter((value) => String(value) !== String(option.value))
        : [...props.modelValue, option.value];

    emit('update:modelValue', values);
    emit('change', values);
}

function remove(option) {
    if (props.disabled) return;

    const values = props.modelValue.filter((value) => String(value) !== String(option.value));
    emit('update:modelValue', values);
    emit('change', values);
}

function closeFromOutside(event) {
    if (root.value && !root.value.contains(event.target)) {
        open.value = false;
        query.value = '';
    }
}

function closeFromKeyboard(event) {
    if (event.key === 'Escape') {
        open.value = false;
        query.value = '';
    }
}

onMounted(() => document.addEventListener('pointerdown', closeFromOutside));
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeFromOutside));
</script>

<template>
    <div ref="root" class="searchable-multiselect" :class="{ 'is-open': open, 'is-disabled': disabled }" @keydown="closeFromKeyboard">
        <button class="searchable-multiselect__trigger" type="button" :disabled="disabled" :aria-expanded="open" aria-haspopup="listbox" @click="toggle">
            <span :class="{ 'is-placeholder': !selectedOptions.length }">{{ selectedOptions.length ? `${selectedOptions.length} oficina${selectedOptions.length === 1 ? '' : 's'} seleccionada${selectedOptions.length === 1 ? '' : 's'}` : placeholder }}</span>
            <span class="searchable-select__chevron" aria-hidden="true">⌄</span>
        </button>

        <div v-if="open" class="searchable-multiselect__menu">
            <label class="searchable-select__search"><span aria-hidden="true">⌕</span><input ref="searchInput" v-model="query" type="search" placeholder="Escriba para filtrar oficinas..." @keydown.stop></label>
            <div class="searchable-multiselect__options" role="listbox" aria-multiselectable="true">
                <button v-for="option in filteredOptions" :key="String(option.value)" class="searchable-multiselect__option" :class="{ 'is-selected': selectedValueKeys.has(String(option.value)) }" type="button" role="option" :aria-selected="selectedValueKeys.has(String(option.value))" :disabled="option.disabled" @click="toggleOption(option)">
                    <span class="searchable-multiselect__check" aria-hidden="true">{{ selectedValueKeys.has(String(option.value)) ? '✓' : '' }}</span>
                    <span>{{ option.label }}</span>
                </button>
                <p v-if="!filteredOptions.length" class="searchable-select__empty">{{ emptyMessage }}</p>
            </div>
        </div>

        <div v-if="selectedOptions.length" class="searchable-multiselect__selected" aria-live="polite">
            <button v-for="option in selectedOptions" :key="String(option.value)" type="button" :disabled="disabled" :aria-label="`Quitar ${option.label}`" @click="remove(option)">
                <span>{{ option.label }}</span><strong aria-hidden="true">×</strong>
            </button>
        </div>
    </div>
</template>
