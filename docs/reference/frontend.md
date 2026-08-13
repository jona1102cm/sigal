# Referencia del frontend

## Arranque

`resources/js/app.js` busca `#sigal-app`, crea la aplicación Vue, instala Pinia y monta `SigalApp.vue`. La vista Blade solo aporta el punto de montaje y los assets compilados por Vite.

## Shell y navegación

`pages/SigalApp.vue` controla autenticación visible, menú lateral, workspace activo, carga inicial y diálogos globales. Los módulos se muestran según permisos derivados de la sesión:

- Gestión documental para usuarios operativos.
- Recursos Humanos para superadministración o rol de RR. HH.
- Administración institucional solo para superadministración.

El menú colapsable conserva poco espacio cuando está contraído y cada módulo despliega únicamente sus subopciones. El detalle de expediente sustituye la bandeja dentro del área principal para disponer del ancho completo.

## Estado Pinia

### `stores/session.js`

Mantiene token/usuario, restaura `/auth/me`, calcula roles y expone banderas de capacidad. El token se almacena en `sessionStorage`: se elimina al cerrar la pestaña/sesión y se revoca en servidor al cerrar sesión, inactivar cuenta o cambiar clave.

### `stores/document-management.js`

Mantiene catálogos, bandeja activa/finalizada, filtros, expediente seleccionado, documentos, movimientos y carga. Tras una mutación vuelve a consultar el detalle/bandeja necesarios para que la interfaz refleje la autoridad del servidor.

### `stores/human-resources.js`

Carga oficinas/cargos/catálogos, lista funcionarios y coordina altas, ediciones, contratos, archivos e importación. Los errores por fila de Excel se muestran sin convertir una importación fallida en registros parciales invisibles.

### `stores/legislatures.js`

Lista períodos y ejecuta operaciones administrativas sobre estado y Directiva.

## Cliente HTTP

`lib/api.js` es el único punto general para solicitudes:

- antepone `/api`;
- agrega `Accept`, `X-Requested-With` y Bearer token;
- serializa objetos como JSON, pero deja intacto `FormData`;
- transforma respuestas no exitosas en `ApiError` con `status` y `errors`;
- pagina automáticamente mediante `getAll()`;
- descarga blobs autenticados y libera la URL temporal.

No consumir `fetch` directamente desde un componente salvo una razón documentada; hacerlo duplicaría autenticación y manejo de errores.

## Componentes principales

| Componente | Función |
|---|---|
| `LoginScreen` | Inicio de sesión y error de credenciales. |
| `PasswordChangeScreen` | Cambio obligatorio de clave temporal. |
| `DashboardView` | Resumen y accesos rápidos. |
| `ExpedientsWorkspace` | Bandejas, búsqueda, prioridades y selección. |
| `ExpedientCreateDialog` | Documento inicial, expediente y derivación embebida. |
| `ExpedientDetail` | Vista completa del flujo, documentos, estados y acciones. |
| `RichTextEditor` | Edición opcional de informe HTML. |
| `SearchableSelect` | Selección estándar con filtrado mientras se escribe. |
| `HumanResourcesWorkspace` | Kardex, contratos, foto, cargos y edición. |
| `EmployeeBulkImportDialog` | Descarga de plantilla, carga y resultado de importación. |
| `AdministrationWorkspace` | Contenedor de usuarios, organización, legislaturas, catálogos y reset. |
| `UserAccessAdministration` | Usuarios, estado, roles y reset de emergencia. |
| `OrganizationAdministration` | Oficinas y organigrama. |
| `LegislatureAdministration` | Legislaturas y Directiva. |
| `ExpedientTypeAdministration` | Catálogo de tipos de expediente. |
| `OperationalResetAdministration` | Limpieza controlada de datos beta. |

## Formularios y errores

- Los campos opcionales deben enviarse como `null` o excluirse según el DTO esperado, no como texto arbitrario.
- Los archivos usan `FormData`; el navegador define el límite `multipart`.
- `ApiError.errors` conserva errores por campo de Laravel.
- Los selects institucionales usan `SearchableSelect` para filtrar al teclear.
- Mostrar el error del backend; no sustituirlo por un mensaje genérico que impida soporte.
- Una acción deshabilitada visualmente no reemplaza autorización del backend.

## Estilos

`resources/css` contiene el sistema visual global y estilos especializados. Para mantener consistencia, reutilizar clases de botones, formularios, alertas, chips, paneles y estados. Verificar al menos escritorio y ancho móvil antes de integrar una vista.

## Cómo añadir una operación frontend

1. Confirmar endpoint, JSON y permisos.
2. Añadir método al store correspondiente.
3. Gestionar estado de carga y `ApiError`.
4. Implementar/componer el formulario.
5. Actualizar estado consultando nuevamente la fuente necesaria.
6. Probar sesión sin permiso, validación `422`, éxito y fallo de red.
7. Ejecutar `npm run build`.
