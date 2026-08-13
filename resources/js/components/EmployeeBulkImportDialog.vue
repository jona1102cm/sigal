<script setup>
/** Descarga la plantilla oficial y presenta el resultado por fila de la importación masiva. */
import { ref } from 'vue';
import { useHumanResourcesStore } from '../stores/human-resources';

const emit = defineEmits(['close']);
const humanResources = useHumanResourcesStore();
const file = ref(null);
const errors = ref([]);
const result = ref(null);

function selectFile(event) {
    file.value = event.target.files?.[0] ?? null;
    errors.value = [];
}

async function downloadTemplate() {
    errors.value = [];

    try {
        await humanResources.downloadImportTemplate();
    } catch (exception) {
        errors.value = [exception.message];
    }
}

async function submit() {
    // El archivo viaja como multipart; el servicio del backend valida nuevamente todos sus valores.
    if (!file.value) {
        errors.value = ['Seleccione la plantilla Excel completada antes de importar.'];
        return;
    }

    errors.value = [];

    try {
        result.value = await humanResources.importEmployees(file.value);
    } catch (exception) {
        errors.value = exception.errors?.file ?? [exception.message];
    }
}
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <section class="modal modal--wide employee-import-modal" role="dialog" aria-modal="true" aria-labelledby="employee-import-title">
            <header class="modal__header"><div><p class="eyebrow">Carga masiva</p><h2 id="employee-import-title">Importar funcionarios y contratos</h2><p class="muted">Cada fila registra el kardex, contrato, cargo, pertenencia a oficina y cuenta SIGAL. Los certificados y fotografías se adjuntan luego desde el kardex.</p></div><button class="icon-button" type="button" aria-label="Cerrar" @click="emit('close')">x</button></header>
            <div v-if="!result" class="employee-import-content">
                <div class="employee-import-step"><span>1</span><div><strong>Descargue la plantilla actualizada</strong><p>Incluye los encabezados, valores permitidos e instrucciones. Verifique los códigos de oficina y cargos vigentes en SIGAL antes de llenarla.</p></div><button class="button button--ghost" type="button" :disabled="humanResources.busy['employee-import-template']" @click="downloadTemplate">{{ humanResources.busy['employee-import-template'] ? 'Descargando…' : 'Descargar plantilla Excel' }}</button></div>
                <div class="employee-import-step"><span>2</span><div><strong>Complete y cargue el archivo</strong><p>Todos los campos obligatorios indicados en la hoja de instrucciones deben estar completos. Fechas en formato YYYY-MM-DD.</p></div><label class="button button--ghost employee-import-file">{{ file?.name || 'Seleccionar archivo .xlsx' }}<input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="selectFile"></label></div>
                <div class="employee-import-rule"><strong>Validación integral</strong><p>Si una fila tiene errores, SIGAL no registrará ninguna fila. Corregirá el libro usando el listado de filas y motivos.</p></div>
                <div v-if="errors.length" class="employee-import-errors" role="alert"><strong>No se pudo realizar la importación.</strong><ul><li v-for="error in errors" :key="error">{{ error }}</li></ul></div>
                <footer class="modal__actions"><button class="button button--ghost" type="button" @click="emit('close')">Cancelar</button><button class="button button--primary" type="button" :disabled="!file || humanResources.busy['employee-import']" @click="submit">{{ humanResources.busy['employee-import'] ? 'Validando e importando…' : 'Importar archivo' }}</button></footer>
            </div>
            <div v-else class="employee-import-content">
                <div class="employee-import-success"><strong>Importación completada</strong><p>Se registraron {{ result.imported_count }} funcionarios y contratos. Las contraseñas temporales siguientes se muestran una sola vez.</p></div>
                <div v-if="result.credentials.length" class="employee-import-credentials"><div class="employee-import-credentials__header"><strong>Credenciales de cuentas nuevas</strong><span>{{ result.credentials.length }} cuenta(s)</span></div><div class="employee-import-credentials__table"><div class="employee-import-credentials__row employee-import-credentials__row--header"><span>Fila</span><span>Funcionario</span><span>Correo SIGAL</span><span>Contraseña temporal</span></div><div v-for="credential in result.credentials" :key="credential.row" class="employee-import-credentials__row"><span>{{ credential.row }}</span><span>{{ credential.name }}</span><span>{{ credential.email }}</span><strong>{{ credential.temporary_password }}</strong></div></div></div>
                <div v-else class="employee-import-rule"><strong>Sin cuentas nuevas</strong><p>Las filas importadas corresponden a kardex ya existentes; por ello no se generaron nuevas credenciales.</p></div>
                <footer class="modal__actions"><button class="button button--primary" type="button" @click="emit('close')">Ya guardé las credenciales</button></footer>
            </div>
        </section>
    </div>
</template>
