<script setup>
/** Editor enriquecido liviano; su HTML se considera no confiable hasta sanearlo en el backend. */
import { nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Redacte el contenido del informe…' },
});
const emit = defineEmits(['update:modelValue']);
const editor = ref(null);

function syncValue() {
    emit('update:modelValue', editor.value?.innerHTML || '');
}

function execute(command, value = null) {
    editor.value?.focus();
    document.execCommand(command, false, value);
    syncValue();
}

function insertLink() {
    const href = window.prompt('Dirección del enlace (https:// o correo):');
    if (href) execute('createLink', href);
}

function pastePlainText(event) {
    // Evita incorporar estilos y etiquetas ocultas al pegar desde Word u otra página.
    event.preventDefault();
    const text = event.clipboardData?.getData('text/plain') || '';
    execute('insertText', text);
}

watch(() => props.modelValue, async (value) => {
    await nextTick();
    if (editor.value && editor.value.innerHTML !== (value || '')) editor.value.innerHTML = value || '';
});

onMounted(() => {
    if (editor.value) editor.value.innerHTML = props.modelValue || '';
});
</script>

<template>
    <div class="rich-text-editor">
        <div class="rich-text-editor__toolbar" role="toolbar" aria-label="Formato del texto">
            <button type="button" aria-label="Negrita" title="Negrita" @click="execute('bold')"><strong>B</strong></button>
            <button type="button" aria-label="Cursiva" title="Cursiva" @click="execute('italic')"><em>I</em></button>
            <button type="button" aria-label="Subrayado" title="Subrayado" @click="execute('underline')"><u>U</u></button>
            <span></span>
            <button type="button" aria-label="Título" title="Título" @click="execute('formatBlock', 'h2')">T</button>
            <button type="button" aria-label="Lista con viñetas" title="Lista con viñetas" @click="execute('insertUnorderedList')">• Lista</button>
            <button type="button" aria-label="Lista numerada" title="Lista numerada" @click="execute('insertOrderedList')">1. Lista</button>
            <button type="button" aria-label="Cita" title="Cita" @click="execute('formatBlock', 'blockquote')">“</button>
            <button type="button" aria-label="Insertar enlace" title="Insertar enlace" @click="insertLink">Enlace</button>
            <button type="button" aria-label="Limpiar formato" title="Limpiar formato" @click="execute('removeFormat')">Limpiar</button>
        </div>
        <div ref="editor" class="rich-text-editor__area" contenteditable="true" role="textbox" aria-multiline="true" :data-placeholder="placeholder" @input="syncValue" @paste="pastePlainText"></div>
    </div>
</template>
