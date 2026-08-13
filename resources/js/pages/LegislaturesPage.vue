<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import SearchableSelect from '../components/SearchableSelect.vue';
import { useLegislaturesStore } from '../stores/legislatures';

const legislatures = useLegislaturesStore();
const submitting = ref(false);
const formError = ref(null);

const currentYear = new Date().getFullYear();
const legislatureForm = reactive({
    start_year: currentYear,
    end_year: currentYear + 1,
    status: 'inactive',
});

const boardForm = reactive({
    legislature_id: null,
    user_id: null,
    position: 'president',
    effective_on: new Date().toISOString().slice(0, 10),
    effective_at: new Date().toISOString(),
});

const activeLegislature = computed(() =>
    legislatures.items.find((legislature) => legislature.status === 'active'),
);

const positions = [
    ['president', 'Presidente'],
    ['vice_president', 'Vicepresidente'],
    ['second_vice_president', 'Segundo Vicepresidente'],
    ['secretary', 'Secretaria'],
    ['second_secretary', 'Segunda Secretaria'],
];

async function submitLegislature() {
    submitting.value = true;
    formError.value = null;

    try {
        await legislatures.create({
            ...legislatureForm,
            start_year: Number(legislatureForm.start_year),
            end_year: Number(legislatureForm.end_year),
        });
    } catch (error) {
        formError.value = error.message;
    } finally {
        submitting.value = false;
    }
}

async function changeStatus(legislature) {
    submitting.value = true;
    formError.value = null;

    try {
        if (legislature.status === 'active') {
            await legislatures.inactivate(legislature.id);
        } else {
            await legislatures.activate(legislature.id);
        }
    } catch (error) {
        formError.value = error.message;
    } finally {
        submitting.value = false;
    }
}

async function submitBoardAssignment() {
    if (!boardForm.legislature_id) {
        formError.value = 'Seleccione una legislatura antes de asignar un cargo.';
        return;
    }

    submitting.value = true;
    formError.value = null;

    try {
        await legislatures.replaceBoardMember(boardForm.legislature_id, {
            ...boardForm,
            legislature_id: undefined,
            user_id: Number(boardForm.user_id),
        });
    } catch (error) {
        formError.value = error.message;
    } finally {
        submitting.value = false;
    }
}

onMounted(() => legislatures.load());
</script>

<template>
    <main class="mx-auto min-h-screen max-w-7xl space-y-8 bg-slate-50 px-4 py-8 text-slate-900 sm:px-6 lg:px-8">
        <header class="rounded-2xl bg-slate-900 px-6 py-8 text-white shadow-sm">
            <p class="text-sm font-semibold tracking-[0.2em] text-emerald-300">SIGAL · ADMINISTRACIÓN</p>
            <h1 class="mt-2 text-3xl font-bold">Legislaturas y Directiva</h1>
            <p class="mt-2 max-w-3xl text-slate-300">
                Gestión de períodos legislativos y sus asignaciones históricas de Directiva.
            </p>
        </header>

        <p v-if="legislatures.error || formError" class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-800">
            {{ formError || legislatures.error }}
        </p>

        <section class="grid gap-6 lg:grid-cols-2">
            <form class="rounded-2xl bg-white p-6 shadow-sm" @submit.prevent="submitLegislature">
                <h2 class="text-xl font-semibold">Registrar legislatura</h2>
                <p class="mt-1 text-sm text-slate-500">El período debe abarcar dos años consecutivos.</p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm font-medium">
                        Año inicial
                        <input v-model="legislatureForm.start_year" class="rounded-md border-slate-300" type="number" required>
                    </label>
                    <label class="grid gap-1 text-sm font-medium">
                        Año final
                        <input v-model="legislatureForm.end_year" class="rounded-md border-slate-300" type="number" required>
                    </label>
                </div>

                <label class="mt-4 grid gap-1 text-sm font-medium">
                    Estado inicial
                    <SearchableSelect v-model="legislatureForm.status" :options="[{ value: 'inactive', label: 'Inactiva' }, { value: 'active', label: 'Activa' }]" />
                    <select v-show="false" v-model="legislatureForm.status" disabled class="rounded-md border-slate-300">
                        <option value="inactive">Inactiva</option>
                        <option value="active">Activa</option>
                    </select>
                </label>

                <button :disabled="submitting" class="mt-6 rounded-md bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-50">
                    Guardar legislatura
                </button>
            </form>

            <form class="rounded-2xl bg-white p-6 shadow-sm" @submit.prevent="submitBoardAssignment">
                <h2 class="text-xl font-semibold">Asignar Directiva</h2>
                <p class="mt-1 text-sm text-slate-500">Reemplazar un cargo cierra el titular anterior en el mismo instante.</p>

                <div class="mt-5 grid gap-4">
                    <label class="grid gap-1 text-sm font-medium">
                        Legislatura
                        <SearchableSelect v-model="boardForm.legislature_id" :options="legislatures.items.map((legislature) => ({ value: legislature.id, label: `${legislature.period_label} - ${legislature.status_label}` }))" placeholder="Seleccione un periodo" />
                        <select v-show="false" v-model="boardForm.legislature_id" disabled class="rounded-md border-slate-300">
                            <option :value="null" disabled>Seleccione un período</option>
                            <option v-for="legislature in legislatures.items" :key="legislature.id" :value="legislature.id">
                                {{ legislature.period_label }} · {{ legislature.status_label }}
                            </option>
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm font-medium">
                        ID del usuario titular
                        <input v-model="boardForm.user_id" class="rounded-md border-slate-300" type="number" min="1" required>
                    </label>
                    <label class="grid gap-1 text-sm font-medium">
                        Cargo
                        <SearchableSelect v-model="boardForm.position" :options="positions.map(([value, label]) => ({ value, label }))" />
                        <select v-show="false" v-model="boardForm.position" disabled class="rounded-md border-slate-300">
                            <option v-for="[value, label] in positions" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm font-medium">
                            Fecha efectiva
                            <input v-model="boardForm.effective_on" class="rounded-md border-slate-300" type="date" required>
                        </label>
                        <label class="grid gap-1 text-sm font-medium">
                            Fecha y hora exacta (ISO 8601)
                            <input v-model="boardForm.effective_at" class="rounded-md border-slate-300" type="text" required>
                        </label>
                    </div>
                </div>

                <button :disabled="submitting" class="mt-6 rounded-md bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                    Asignar titular
                </button>
            </form>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold">Períodos registrados</h2>
                    <p v-if="activeLegislature" class="text-sm text-emerald-700">Activa: {{ activeLegislature.period_label }}</p>
                </div>
                <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50" @click="legislatures.load">
                    Actualizar
                </button>
            </div>

            <p v-if="legislatures.loading" class="mt-5 text-slate-500">Cargando legislaturas…</p>
            <p v-else-if="!legislatures.items.length" class="mt-5 text-slate-500">No hay legislaturas registradas.</p>

            <div v-else class="mt-5 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-3 py-3 font-semibold">Período</th>
                            <th class="px-3 py-3 font-semibold">Estado</th>
                            <th class="px-3 py-3 font-semibold">Directiva vigente</th>
                            <th class="px-3 py-3 font-semibold">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="legislature in legislatures.items" :key="legislature.id">
                            <td class="px-3 py-4 font-medium">{{ legislature.period_label }}</td>
                            <td class="px-3 py-4">{{ legislature.status_label }}</td>
                            <td class="px-3 py-4 text-slate-600">
                                <ul v-if="legislature.current_board_assignments?.length" class="space-y-1">
                                    <li v-for="assignment in legislature.current_board_assignments" :key="assignment.id">
                                        {{ assignment.position_label }}: {{ assignment.user_name || `Usuario #${assignment.user_id}` }}
                                    </li>
                                </ul>
                                <span v-else>Sin asignaciones</span>
                            </td>
                            <td class="px-3 py-4">
                                <button :disabled="submitting" class="rounded-md px-3 py-2 font-medium text-white disabled:opacity-50" :class="legislature.status === 'active' ? 'bg-amber-700' : 'bg-emerald-700'" @click="changeStatus(legislature)">
                                    {{ legislature.status === 'active' ? 'Inactivar' : 'Activar' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</template>
