<script setup>
/**
 * Shell principal autenticado: decide qué módulo puede ver el usuario y coordina
 * las cargas iniciales sin duplicar las reglas de autorización del backend.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AccountPasswordDialog from '../components/AccountPasswordDialog.vue';
import AdministrationWorkspace from '../components/AdministrationWorkspace.vue';
import DashboardView from '../components/DashboardView.vue';
import ExpedientCreateDialog from '../components/ExpedientCreateDialog.vue';
import ExpedientsWorkspace from '../components/ExpedientsWorkspace.vue';
import HumanResourcesWorkspace from '../components/HumanResourcesWorkspace.vue';
import LoginScreen from '../components/LoginScreen.vue';
import OfficeDocumentAccessWorkspace from '../components/OfficeDocumentAccessWorkspace.vue';
import PasswordChangeScreen from '../components/PasswordChangeScreen.vue';
import WarehouseWorkspace from '../components/WarehouseWorkspace.vue';
import { useDocumentManagementStore } from '../stores/document-management';
import { useHumanResourcesStore } from '../stores/human-resources';
import { useSessionStore } from '../stores/session';
import { useWarehouseStore } from '../stores/warehouse';

const session = useSessionStore();
const documents = useDocumentManagementStore();
const humanResources = useHumanResourcesStore();
const warehouse = useWarehouseStore();
const view = ref('dashboard');
const mobileMenuOpen = ref(false);
const sidebarCollapsed = ref(false);
const expandedModule = ref(null);
const createDialogOpen = ref(false);
const booting = ref(false);
const bootError = ref(null);
const passwordDialogOpen = ref(false);
const warehouseWorkspace = ref(null);
let refreshingSecurityContext = false;
let securityRefreshTimer = null;
const SECURITY_REFRESH_INTERVAL_MS = 60_000;

async function loadWorkspace() {
    booting.value = true;
    bootError.value = null;

    try {
        // Carga en paralelo solo los módulos permitidos para reducir tiempo de arranque y exposición.
        const loads = [];
        if (session.canUseDocumentManagement) loads.push(documents.loadCatalogs(), documents.loadExpedients());
        if (session.canManageHumanResources) loads.push(humanResources.loadBootstrap(), humanResources.loadEmployees());
        if (session.canUseWarehouse) loads.push(warehouse.loadBootstrap(), warehouse.loadRequests());
        await Promise.all(loads);

        if (!session.canViewDashboard) {
            if (session.canUseDocumentManagement) view.value = 'expedients';
            else if (session.canManageHumanResources) view.value = 'human-resources';
            else if (session.canUseWarehouse) view.value = 'warehouse';
            else if (session.canUseAdministration) view.value = 'administration';
        }
    } catch (error) {
        bootError.value = error.message;
    } finally {
        booting.value = false;
    }
}

async function authenticated() {
    await loadWorkspace();
}

async function openExpedient(expedient) {
    // La selección cambia primero al workspace; el detalle ocupa después toda el área principal.
    view.value = 'expedients';
    try {
        await documents.selectExpedient(expedient);
    } catch {
        // The workspace displays the actionable server message when access changes concurrently.
    }
}

async function openWarehouseRequest() {
    view.value = 'warehouse';
    expandedModule.value = 'warehouse';
    await nextTick();
    warehouseWorkspace.value?.openNewRequest();
}

async function logout() {
    await session.logout();
    documents.$reset();
    humanResources.$reset();
    warehouse.$reset();
    view.value = 'dashboard';
}

function normalizeAuthorizedView() {
    const dashboardWithoutAccess = view.value === 'dashboard' && !session.canViewDashboard;
    const documentViewWithoutAccess = view.value === 'expedients' && !session.canUseDocumentManagement;
    const officeSettingsWithoutAccess = view.value === 'office-access' && !session.canConfigureOfficeAccess;
    const humanResourcesViewWithoutAccess = view.value === 'human-resources' && !session.canManageHumanResources;
    const warehouseViewWithoutAccess = view.value === 'warehouse' && !session.canUseWarehouse;
    const administrationViewWithoutAccess = view.value === 'administration' && !session.canUseAdministration;

    if (!dashboardWithoutAccess && !documentViewWithoutAccess && !officeSettingsWithoutAccess && !humanResourcesViewWithoutAccess && !warehouseViewWithoutAccess && !administrationViewWithoutAccess) return;

    if (session.canViewDashboard) view.value = 'dashboard';
    else if (session.canUseDocumentManagement) view.value = 'expedients';
    else if (session.canManageHumanResources) view.value = 'human-resources';
    else if (session.canUseWarehouse) view.value = 'warehouse';
    else if (session.isSuperAdministrator) view.value = 'administration';
}

async function refreshSecurityContext() {
    if (!session.authenticated || refreshingSecurityContext) return;

    refreshingSecurityContext = true;
    const hadDocumentAccess = session.canUseDocumentManagement;
    const hadHumanResourcesAccess = session.canManageHumanResources;
    const hadWarehouseAccess = session.canUseWarehouse;
    try {
        await session.refresh();

        if (hadDocumentAccess && !session.canUseDocumentManagement) documents.$reset();
        if (!hadDocumentAccess && session.canUseDocumentManagement) {
            await Promise.all([documents.loadCatalogs(), documents.loadExpedients()]);
        }
        if (hadHumanResourcesAccess && !session.canManageHumanResources) humanResources.$reset();
        if (!hadHumanResourcesAccess && session.canManageHumanResources) {
            await Promise.all([humanResources.loadBootstrap(), humanResources.loadEmployees()]);
        }
        if (hadWarehouseAccess && !session.canUseWarehouse) warehouse.$reset();
        if (!hadWarehouseAccess && session.canUseWarehouse) {
            await Promise.all([warehouse.loadBootstrap(), warehouse.loadRequests()]);
        }
        if (!session.canCreateExpedients) createDialogOpen.value = false;

        normalizeAuthorizedView();
        if (session.canUseDocumentManagement && documents.selected) {
            await documents.refreshSelected();
        }
    } catch {
        if (!session.authenticated) {
            documents.$reset();
            humanResources.$reset();
            warehouse.$reset();
            view.value = 'dashboard';
        }
    } finally {
        refreshingSecurityContext = false;
    }
}

function toggleModule(module, targetView) {
    expandedModule.value = expandedModule.value === module ? null : module;
    view.value = targetView;
    mobileMenuOpen.value = false;
}

const viewContext = computed(() => ({
    dashboard: 'Inicio',
    expedients: 'Gestion documental',
    'office-access': 'Configuración documental',
    'human-resources': 'Recursos Humanos',
    warehouse: 'Almacenes',
    administration: 'Administracion',
}[view.value] || 'SIGAL'));

onMounted(async () => {
    await session.restore();
    if (session.authenticated) await loadWorkspace();
    window.addEventListener('focus', refreshSecurityContext);
    securityRefreshTimer = window.setInterval(refreshSecurityContext, SECURITY_REFRESH_INTERVAL_MS);
});

onBeforeUnmount(() => {
    window.removeEventListener('focus', refreshSecurityContext);
    window.clearInterval(securityRefreshTimer);
});
</script>

<template>
    <div v-if="!session.ready" class="app-loading"><div class="app-loading__mark">S</div><p>Preparando SIGAL...</p></div>
    <LoginScreen v-else-if="!session.authenticated" @authenticated="authenticated" />
    <PasswordChangeScreen v-else-if="session.mustChangePassword" @changed="authenticated" @logout="logout" />

    <div v-else class="app-shell" :class="{ 'is-sidebar-collapsed': sidebarCollapsed }">
        <aside class="app-sidebar" :class="{ 'is-open': mobileMenuOpen }">
            <div class="sidebar__brand-row">
                <div class="sidebar__brand"><span class="brand__mark" aria-hidden="true">S</span><span class="sidebar__label">SIGAL</span></div>
                <button class="sidebar-collapse-button" type="button" :aria-label="sidebarCollapsed ? 'Expandir panel de navegacion' : 'Contraer panel de navegacion'" :title="sidebarCollapsed ? 'Expandir panel' : 'Contraer panel'" @click="sidebarCollapsed = !sidebarCollapsed"><span aria-hidden="true">{{ sidebarCollapsed ? '>' : '<' }}</span></button>
            </div>
            <p class="sidebar__institution">Asamblea Legislativa Departamental del Beni</p>
            <nav class="sidebar__nav" aria-label="Navegacion principal">
                <button v-if="session.canViewDashboard" type="button" :class="{ 'is-active': view === 'dashboard' }" @click="view = 'dashboard'; mobileMenuOpen = false"><span aria-hidden="true">D</span><span class="sidebar__nav-label">Inicio</span></button>
                <section v-if="session.canUseDocumentManagement" class="sidebar__module" :class="{ 'is-active': ['expedients', 'office-access'].includes(view) }"><button class="sidebar__module-button" type="button" :aria-expanded="expandedModule === 'document-management'" @click="toggleModule('document-management', 'expedients')"><span aria-hidden="true">GD</span><span class="sidebar__nav-label">Gestion documental</span><em class="sidebar__module-chevron" aria-hidden="true">{{ expandedModule === 'document-management' ? '-' : '+' }}</em></button><template v-if="expandedModule === 'document-management'"><button class="sidebar__subnav" type="button" :class="{ 'is-active': view === 'expedients' }" @click="view = 'expedients'; mobileMenuOpen = false"><span class="sidebar__nav-label">Bandeja de expedientes</span></button><button v-if="session.canConfigureOfficeAccess" class="sidebar__subnav" type="button" :class="{ 'is-active': view === 'office-access' }" @click="view = 'office-access'; mobileMenuOpen = false"><span class="sidebar__nav-label">Acceso de mi oficina</span></button></template></section>
                <section v-if="session.canManageHumanResources" class="sidebar__module" :class="{ 'is-active': view === 'human-resources' }"><button class="sidebar__module-button" type="button" :aria-expanded="expandedModule === 'human-resources'" @click="toggleModule('human-resources', 'human-resources')"><span aria-hidden="true">RH</span><span class="sidebar__nav-label">Recursos Humanos</span><em class="sidebar__module-chevron" aria-hidden="true">{{ expandedModule === 'human-resources' ? '-' : '+' }}</em></button><button v-if="expandedModule === 'human-resources'" class="sidebar__subnav" type="button" :class="{ 'is-active': view === 'human-resources' }" @click="view = 'human-resources'; mobileMenuOpen = false"><span class="sidebar__nav-label">Funcionarios y contratos</span></button></section>
                <section v-if="session.canUseWarehouse" class="sidebar__module" :class="{ 'is-active': view === 'warehouse' }"><button class="sidebar__module-button" type="button" :aria-expanded="expandedModule === 'warehouse'" @click="toggleModule('warehouse', 'warehouse')"><span aria-hidden="true">AL</span><span class="sidebar__nav-label">Almacenes</span><em class="sidebar__module-chevron" aria-hidden="true">{{ expandedModule === 'warehouse' ? '-' : '+' }}</em></button><button v-if="expandedModule === 'warehouse'" class="sidebar__subnav" type="button" :class="{ 'is-active': view === 'warehouse' }" @click="view = 'warehouse'; mobileMenuOpen = false"><span class="sidebar__nav-label">Solicitudes y existencias</span></button></section>
                <section v-if="session.canUseAdministration" class="sidebar__module" :class="{ 'is-active': view === 'administration' }"><button class="sidebar__module-button" type="button" :aria-expanded="expandedModule === 'administration'" @click="toggleModule('administration', 'administration')"><span aria-hidden="true">AD</span><span class="sidebar__nav-label">Administracion</span><em class="sidebar__module-chevron" aria-hidden="true">{{ expandedModule === 'administration' ? '-' : '+' }}</em></button><button v-if="expandedModule === 'administration'" class="sidebar__subnav" type="button" :class="{ 'is-active': view === 'administration' }" @click="view = 'administration'; mobileMenuOpen = false"><span class="sidebar__nav-label">Institucion y catalogos</span></button></section>
            </nav>
            <div class="sidebar__footer"><span class="status-dot status-dot--success"></span><span class="sidebar__label">Sesion protegida</span></div>
        </aside>

        <main class="app-main">
            <header class="app-topbar">
                <button class="mobile-menu-button" type="button" aria-label="Abrir navegacion" @click="mobileMenuOpen = !mobileMenuOpen">=</button>
                <div class="topbar__context"><span class="topbar__eyebrow">SIGAL</span><span>{{ viewContext }}</span></div>
                <div class="profile-menu"><div class="profile-menu__initial">{{ session.user.name?.slice(0, 1).toUpperCase() }}</div><div><strong>{{ session.user.name }}</strong><small>{{ session.roleLabel }}</small></div><button class="text-button" type="button" @click="passwordDialogOpen = true">Cambiar contraseña</button><button class="text-button" type="button" @click="logout">Salir</button></div>
            </header>

            <div v-if="booting" class="workspace-loading"><div class="loading-line"></div><p>Actualizando la informacion institucional...</p></div>
            <section v-else-if="bootError" class="boot-error"><p class="alert alert--error">{{ bootError }}</p><button class="button button--primary" type="button" @click="loadWorkspace">Reintentar</button></section>
            <DashboardView v-else-if="view === 'dashboard' && session.canViewDashboard" :can-create-expedients="session.canCreateExpedients" :can-create-warehouse-request="session.canCreateWarehouseRequest" @open-expedients="view = 'expedients'" @create-expedient="createDialogOpen = true" @create-warehouse-request="openWarehouseRequest" @select-expedient="openExpedient" />
            <ExpedientsWorkspace v-else-if="view === 'expedients' && session.canUseDocumentManagement" :session="session" @create-expedient="createDialogOpen = true" />
            <OfficeDocumentAccessWorkspace v-else-if="view === 'office-access' && session.canConfigureOfficeAccess" />
            <HumanResourcesWorkspace v-else-if="view === 'human-resources' && session.canManageHumanResources" />
            <WarehouseWorkspace v-else-if="view === 'warehouse' && session.canUseWarehouse" ref="warehouseWorkspace" :session="session" />
            <AdministrationWorkspace v-else-if="session.canUseAdministration" />
            <section v-else class="boot-error"><p class="alert alert--error">Su cuenta está activa, pero no tiene un módulo asignado. Solicite al administrador revisar sus roles vigentes.</p></section>
        </main>

        <ExpedientCreateDialog v-if="session.canCreateExpedients" :open="createDialogOpen" @close="createDialogOpen = false" @created="createDialogOpen = false; view = 'expedients'" />
        <AccountPasswordDialog v-if="passwordDialogOpen" @close="passwordDialogOpen = false" @changed="loadWorkspace" />
    </div>
</template>
