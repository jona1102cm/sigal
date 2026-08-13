<script setup>
/** Select accesible y filtrable mientras el usuario escribe, reutilizado en todo SIGAL. */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number, Boolean], default: null },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Seleccione una opción' },
    emptyMessage: { type: String, default: 'No se encontraron opciones.' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change']);
const root = ref(null);
const searchInput = ref(null);
const open = ref(false);
const query = ref('');

const selected = computed(() => props.options.find((option) => String(option.value) === String(props.modelValue)) ?? null);
const filteredOptions = computed(() => {
    // Se filtra localmente en cada pulsación; no hace falta confirmar con Enter.
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

function choose(option) {
    if (option.disabled) return;

    emit('update:modelValue', option.value);
    emit('change', option.value);
    open.value = false;
    query.value = '';
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
    <div ref="root" class="searchable-select" :class="{ 'is-open': open, 'is-disabled': disabled }" @keydown="closeFromKeyboard">
        <button class="searchable-select__trigger" type="button" :disabled="disabled" :aria-expanded="open" aria-haspopup="listbox" @click="toggle">
            <span :class="{ 'is-placeholder': !selected }">{{ selected?.label || placeholder }}</span>
            <span class="searchable-select__chevron" aria-hidden="true">⌄</span>
        </button>
        <div v-if="open" class="searchable-select__menu">
            <label class="searchable-select__search"><span aria-hidden="true">⌕</span><input ref="searchInput" v-model="query" type="search" placeholder="Escriba para filtrar..." @keydown.stop></label>
            <div class="searchable-select__options" role="listbox">
                <button v-for="option in filteredOptions" :key="String(option.value)" class="searchable-select__option" :class="{ 'is-selected': String(option.value) === String(modelValue) }" type="button" role="option" :aria-selected="String(option.value) === String(modelValue)" :disabled="option.disabled" @click="choose(option)">{{ option.label }}</button>
                <p v-if="!filteredOptions.length" class="searchable-select__empty">{{ emptyMessage }}</p>
            </div>
        </div>
    </div>
</template>
