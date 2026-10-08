<script setup>
/** Descarga la plantilla, clasifica cargos nuevos y presenta el resultado de la importación masiva. */
import { computed, ref } from 'vue';
import { useHumanResourcesStore } from '../stores/human-resources';

const emit = defineEmits(['close']);
const humanResources = useHumanResourcesStore();
const file = ref(null);
const errors = ref([]);
const result = ref(null);
const missingPositions = ref([]);
const positionRoles = ref({});
const resolvingPositions = computed(() => missingPositions.value.length > 0);
const allPositionsResolved = computed(() => missingPositions.value.every((position) => Boolean(positionRoles.value[position.key])));

function selectFile(event) {
    file.value = event.target.files?.[0] ?? null;
    errors.value = [];
    result.value = null;
    missingPositions.value = [];
    positionRoles.value = {};
}

function setPositionRole(position, membershipRole) {
    if (membershipRole === 'manager' && !managerCanBeSelected(position)) return;

    positionRoles.value = { ...positionRoles.value, [position.key]: membershipRole };
}

function managerCanBeSelected(position) {
    return position.office_requires_manager && !position.existing_manager_position?.has_contracts;
}

function preparePositionResolution(positions) {
    const roles = {};

    positions.forEach((position) => {
        if (!managerCanBeSelected(position)) roles[position.key] = 'official';
    });

    positionRoles.value = roles;
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

    if (resolvingPositions.value && !allPositionsResolved.value) {
        errors.value = ['Clasifique todos los cargos nuevos antes de confirmar la importación.'];
        return;
    }

    errors.value = [];

    try {
        const resolutions = missingPositions.value.map((position) => ({
            key: position.key,
            membership_role: positionRoles.value[position.key],
        }));
        const response = await humanResources.importEmployees(file.value, resolutions);

        if (response.requires_position_resolution) {
            missingPositions.value = response.missing_positions;
            preparePositionResolution(response.missing_positions);
            return;
        }

        result.value = response;
        missingPositions.value = [];
    } catch (exception) {
        errors.value = exception.errors?.file ?? [exception.message];
    }
}

function returnToFileSelection() {
    missingPositions.value = [];
    positionRoles.value = {};
    errors.value = [];
}
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <section class="modal modal--wide employee-import-modal" role="dialog" aria-modal="true" aria-labelledby="employee-import-title">
            <header class="modal__header"><div><p class="eyebrow">Carga masiva</p><h2 id="employee-import-title">Importar funcionarios y contratos</h2><p class="muted">Cada fila registra el kardex, contrato, cargo, pertenencia a oficina y cuenta SIGAL. Los certificados y fotografías se adjuntan luego desde el kardex.</p></div><button class="icon-button" type="button" aria-label="Cerrar" @click="emit('close')">x</button></header>
            <div v-if="!result && !resolvingPositions" class="employee-import-content">
                <div class="employee-import-step"><span>1</span><div><strong>Descargue la plantilla actualizada</strong><p>Incluye encabezados, valores permitidos e instrucciones. Puede utilizar una plantilla que ya tenga completada.</p></div><button class="button button--ghost" type="button" :disabled="humanResources.busy['employee-import-template']" @click="downloadTemplate">{{ humanResources.busy['employee-import-template'] ? 'Descargando…' : 'Descargar plantilla Excel' }}</button></div>
                <div class="employee-import-step"><span>2</span><div><strong>Complete y cargue el archivo</strong><p>Los campos obligatorios deben estar completos. Las fechas usan el formato YYYY-MM-DD. Si encuentra cargos nuevos, SIGAL los mostrará antes de guardar.</p></div><label class="button button--ghost employee-import-file">{{ file?.name || 'Seleccionar archivo .xlsx' }}<input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="selectFile"></label></div>
                <div class="employee-import-rule"><strong>Validación integral</strong><p>Una fila con error impide registrar toda la importación. Ningún funcionario ni cargo se guarda durante esta validación previa.</p></div>
                <div v-if="errors.length" class="employee-import-errors" role="alert"><strong>No se pudo realizar la importación.</strong><ul><li v-for="error in errors" :key="error">{{ error }}</li></ul></div>
                <footer class="modal__actions"><button class="button button--ghost" type="button" @click="emit('close')">Cancelar</button><button class="button button--primary" type="button" :disabled="!file || humanResources.busy['employee-import']" @click="submit">{{ humanResources.busy['employee-import'] ? 'Validando e importando…' : 'Importar archivo' }}</button></footer>
            </div>
            <div v-else-if="!result" class="employee-import-content">
                <div class="employee-import-resolution-intro"><div><p class="eyebrow">Clasificación previa</p><strong>Se encontraron {{ missingPositions.length }} cargo(s) nuevo(s)</strong><p>Indique si cada cargo corresponde a un funcionario o a la persona responsable de la oficina. Los cargos repetidos en varias filas aparecen una sola vez.</p></div><span>{{ file?.name }}</span></div>
                <div class="employee-import-position-list">
                    <article v-for="position in missingPositions" :key="position.key" class="employee-import-position">
                        <div class="employee-import-position__identity"><small>{{ position.office_code }} · {{ position.office_name }}</small><strong>{{ position.position_name }}</strong><span>Fila{{ position.rows.length === 1 ? '' : 's' }} {{ position.rows.join(', ') }}</span></div>
                        <div class="employee-import-position__roles" role="group" :aria-label="`Clasificación de ${position.position_name}`">
                            <button type="button" :class="{ 'is-selected': positionRoles[position.key] === 'official' }" :aria-pressed="positionRoles[position.key] === 'official'" @click="setPositionRole(position, 'official')"><strong>Funcionario</strong><span>Sin atribuciones de jefatura</span></button>
                            <button type="button" :class="{ 'is-selected': positionRoles[position.key] === 'manager' }" :disabled="!managerCanBeSelected(position)" :aria-pressed="positionRoles[position.key] === 'manager'" @click="setPositionRole(position, 'manager')"><strong>Responsable o jefe</strong><span>Gestiona la oficina y sus dependientes</span></button>
                        </div>
                        <p v-if="position.existing_manager_position?.has_contracts" class="employee-import-position__note">La oficina ya tiene el cargo responsable {{ position.existing_manager_position.name }} con historial. Este cargo nuevo solo puede registrarse como funcionario.</p>
                        <p v-else-if="position.existing_manager_position" class="employee-import-position__note">Si lo clasifica como responsable, reemplazará el nombre provisional “{{ position.existing_manager_position.name }}” sin crear una segunda jefatura.</p>
                        <p v-else-if="!position.office_requires_manager" class="employee-import-position__note">Esta oficina no tiene responsable propio según el organigrama institucional.</p>
                    </article>
                </div>
                <div class="employee-import-rule"><strong>Confirmación atómica y auditable</strong><p>Al confirmar, SIGAL creará o ajustará estos cargos y registrará los funcionarios. Si cualquier fila falla, se revertirá toda la operación.</p></div>
                <div v-if="errors.length" class="employee-import-errors" role="alert"><strong>No se pudo realizar la importación.</strong><ul><li v-for="error in errors" :key="error">{{ error }}</li></ul></div>
                <footer class="modal__actions"><button class="button button--ghost" type="button" :disabled="humanResources.busy['employee-import']" @click="returnToFileSelection">Volver</button><button class="button button--primary" type="button" :disabled="!allPositionsResolved || humanResources.busy['employee-import']" @click="submit">{{ humanResources.busy['employee-import'] ? 'Creando cargos e importando…' : 'Confirmar cargos e importar' }}</button></footer>
            </div>
            <div v-else class="employee-import-content">
                <div class="employee-import-success"><strong>Importación completada</strong><p>Se registraron {{ result.imported_count }} funcionarios y contratos. Cada funcionario debera reemplazar su contraseña inicial en el primer acceso.</p></div>
                <div v-if="result.credentials.length" class="employee-import-credentials"><div class="employee-import-credentials__header"><strong>Credenciales de cuentas nuevas</strong><span>{{ result.credentials.length }} cuenta(s)</span></div><div class="employee-import-credentials__table"><div class="employee-import-credentials__row employee-import-credentials__row--header"><span>Fila</span><span>Funcionario</span><span>Correo SIGAL</span><span>Contraseña inicial</span></div><div v-for="credential in result.credentials" :key="credential.row" class="employee-import-credentials__row"><span>{{ credential.row }}</span><span>{{ credential.name }}</span><span>{{ credential.email }}</span><strong>{{ credential.temporary_password }}</strong></div></div></div>
                <div v-else class="employee-import-rule"><strong>Sin cuentas nuevas</strong><p>Las filas importadas corresponden a kardex ya existentes; por ello no se generaron nuevas credenciales.</p></div>
                <footer class="modal__actions"><button class="button button--primary" type="button" @click="emit('close')">Ya guardé las credenciales</button></footer>
            </div>
        </section>
    </div>
</template>
