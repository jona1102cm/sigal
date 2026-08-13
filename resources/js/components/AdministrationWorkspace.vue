<script setup>
/** Contenedor de las herramientas institucionales exclusivas de superadministración. */
import { ref } from 'vue';
import ExpedientTypeAdministration from './ExpedientTypeAdministration.vue';
import LegislatureAdministration from './LegislatureAdministration.vue';
import OrganizationAdministration from './OrganizationAdministration.vue';
import OperationalResetAdministration from './OperationalResetAdministration.vue';
import UserAccessAdministration from './UserAccessAdministration.vue';

const activeSection = ref('legislatures');
</script>

<template>
    <section class="workspace workspace--wide administration-workspace">
        <header class="page-heading">
            <div>
                <p class="eyebrow">Administración del sistema</p>
                <h1>Configuración institucional</h1>
                <p class="muted">Gestione la estructura organizacional y los catálogos que habilitan el flujo documental.</p>
            </div>
        </header>

        <nav class="administration-tabs" aria-label="Secciones de administración">
            <button type="button" :class="{ 'is-active': activeSection === 'legislatures' }" @click="activeSection = 'legislatures'">Legislaturas y Directiva</button>
            <button type="button" :class="{ 'is-active': activeSection === 'organization' }" @click="activeSection = 'organization'">Organigrama y oficinas</button>
            <button type="button" :class="{ 'is-active': activeSection === 'expedient-types' }" @click="activeSection = 'expedient-types'">Tipos de expediente</button>
            <button type="button" :class="{ 'is-active': activeSection === 'users' }" @click="activeSection = 'users'">Accesos y contraseñas</button>
            <button type="button" :class="{ 'is-active': activeSection === 'operational-reset' }" @click="activeSection = 'operational-reset'">Reinicio preoperativo</button>
        </nav>

        <LegislatureAdministration v-if="activeSection === 'legislatures'" />
        <OrganizationAdministration v-else-if="activeSection === 'organization'" />
        <ExpedientTypeAdministration v-else-if="activeSection === 'expedient-types'" />
        <UserAccessAdministration v-else-if="activeSection === 'users'" />
        <OperationalResetAdministration v-else />
    </section>
</template>
