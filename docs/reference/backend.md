# Referencia del backend

## Convención de capas

### Rutas

`routes/api.php` agrupa endpoints por módulo. Excepto el login, todos requieren Sanctum y usuario activo. Después del cambio inicial de contraseña se habilita el grupo funcional completo.

Las rutas no deciden permisos ni procesan datos; solo conectan verbo/URI con controlador y aplican middlewares.

### Controllers

Los controladores bajo `app/Http/Controllers/Api` realizan cuatro tareas:

1. autorizan con Policies;
2. convierten datos validados a DTO;
3. llaman al Service;
4. construyen Resource/respuesta y código HTTP.

Una transacción o regla reutilizable dentro de un controlador es señal de que debe moverse al dominio.

### Form Requests

Los archivos de `app/Http/Requests` declaran reglas de validación, normalizan datos y generan los DTO usados por servicios. La validación de forma pertenece aquí; la validación dependiente de estado concurrente pertenece al Service dentro de una transacción.

### Policies

`app/Policies` es la frontera de autorización:

- `UserPolicy`: usuarios, roles y reinicio de contraseña.
- `EmployeePolicy`: kardex, importación y contratos.
- `OfficePolicy`: organigrama y membresías.
- `LegislaturePolicy`: período y Directiva.
- `ExpedientTypePolicy`: catálogos.
- `ExpedientPolicy`: visibilidad, tenencia, documentos, movimientos, acceso y ciclo de vida.

Vue puede ocultar acciones por usabilidad, pero la Policy es la autoridad real.

### DTO

Los DTO de `app/Domain/*/DTOs` son objetos inmutables de entrada/salida. Evitan que los servicios dependan de `Request` y hacen explícitos campos opcionales, enums y colecciones.

### Enums

Los enums definen vocabularios cerrados: roles, estados, prioridades, tipos de contrato, posiciones y capacidades. Para agregar un valor se debe revisar migraciones/constraints, validación, seeders, serialización, filtros, etiquetas y pruebas.

### Services

Son la implementación de casos de uso. Un Service:

- valida invariantes que dependen de la base;
- abre transacciones;
- bloquea filas sensibles;
- coordina varios modelos;
- registra auditoría;
- devuelve modelos cargados o DTO de resultado.

Servicios principales:

| Servicio | Responsabilidad |
|---|---|
| `AuthenticationService` | Login, logout y cambio de contraseña. |
| `UserManagementService` | Cuenta, estado, reset temporal y roles históricos. |
| `RolePermissionService` | Lectura y reemplazo auditado de la matriz de permisos de roles fijos. |
| `ObserverOfficeScopeService` | Alcance jerárquico e histórico de cada observador. |
| `LegislatureService` | Período único activo y Directiva histórica. |
| `OfficeService` | Árbol organizacional y membresías. |
| `OfficeHierarchyService` | Expansión consistente de oficinas raíz y todas sus dependencias. |
| `HumanResourcesService` | Kardex, contrato, cuenta, cargo, membresía y archivos. |
| `EmployeeImportWorkbookReader` | Lectura segura de la estructura XLSX. |
| `EmployeeBulkImportService` | Validación/importación masiva con reglas del alta manual. |
| `ExpedientService` | Creación base del expediente. |
| `DocumentedExpedientEntryService` | Alta normal: expediente + documento + derivación. |
| `DocumentWorkflowService` | Documento posterior + adjuntos + derivación. |
| `DocumentService` | Borrador, emisión, corrección, adjuntos y vínculos. |
| `ExpedientMovementService` | Tenencia, destinatarios y estados por oficina. |
| `ExpedientLifecycleService` | Archivo, cierre, anulación y reapertura. |
| `ExpedientAccessService` | Concesiones extraordinarias y su cierre. |
| `OfficeDocumentAccessService` | Modalidad persistente y equipo operativo autorizado por la jefatura. |
| `ExpedientInternalAssignmentService` | Responsable único y colaboradores históricos de cada llegada. |
| `NumberSequenceService` | Reserva concurrente de numeración. |
| `OperationalResetService` | Limpieza controlada de datos de prueba. |
| `ActivityLogger` | Registro uniforme de eventos auditables. |

### Models

Los modelos de `app/Models` describen tabla, atributos asignables, casts, relaciones y scopes. No deben convertirse en servicios ocultos. Los scopes como `active()` o relaciones como `currentOfficeMemberships()` forman un lenguaje de consulta reutilizable.

### API Resources

Los Resources evitan exponer accidentalmente todas las columnas y estabilizan nombres/relaciones del JSON. Agregar una columna a la base no la publica automáticamente.

### Middleware

- `auth:sanctum`: valida el token.
- `active.user`: rechaza cuentas inactivas y revoca acceso inválido.
- `password.changed`: limita la sesión con clave temporal al cambio de contraseña/logout.

## Mapa de módulos PHP

### `Domain/Authorization`

Contiene roles, permisos, estados de usuario, alcance del observador, datos de sesión y gestión de identidad. `HasSystemRoles` centraliza roles y permisos vigentes; el superadministrador obtiene siempre todas las capacidades y su matriz no es editable.

### `Domain/Audit`

El contexto HTTP viaja separado del caso de uso. Esto permite invocar servicios desde consola sin fingir un `Request`, suministrando un contexto explícito.

### `Domain/Legislatures`

Valida años consecutivos, exclusividad de activa e intervalos de Directiva. Activaciones y reemplazos usan transacción/bloqueo.

### `Domain/Organization`

Impide ciclos del organigrama, dependencia de nodos inválidos e inactivación destructiva. Las membresías se cierran con fecha; no se eliminan. La jerarquía también expande, desde las mismas relaciones, las dependencias incluidas en un alcance de observación.

### `Domain/HumanResources`

Orquesta el agregado más amplio del sistema. La cuenta es reutilizable y el contrato es histórico. Toda alta manual o masiva desde RR. HH. asigna únicamente `simple_user`; elevar privilegios es una operación administrativa posterior. `EmployeeInitialPasswordGenerator` aplica en ambos flujos la fórmula CI completo más iniciales ASCII de todos los nombres y apellidos. La cuenta se marca para cambio obligatorio antes de habilitar cualquier módulo. Las tareas de consola llaman al mismo servicio para activar o vencer contratos.

### `Domain/DocumentManagement`

Se divide en servicios pequeños porque numeración, documentos, movimientos, acceso, distribución interna y ciclo de vida tienen permisos e invariantes diferentes. La Policy combina permiso general, visibilidad por oficina/observador, confidencialidad y capacidad operativa. Los servicios coordinadores componen operaciones sin duplicar reglas internas.

### `Domain/Administration`

Contiene operaciones extraordinarias de mantenimiento. Deben ser explícitas, auditadas y restringidas; nunca convertirse en endpoints genéricos para borrar tablas.

## Auditoría y snapshots

Los métodos privados `*Snapshot()` convierten el estado relevante a arrays estables antes/después de una mutación. No deben incluir secretos o binarios. Si se agrega un campo institucional relevante, decidir expresamente si debe aparecer en auditoría.

## Transacciones y bloqueos

Usar transacción cuando una operación cambia más de una entidad o cuando el historial debe quedar completo. Usar `lockForUpdate()` cuando dos solicitudes simultáneas podrían:

- activar dos registros exclusivos;
- reservar el mismo número;
- sustituir el mismo titular;
- derivar desde la misma tenencia;
- cerrar/renovar el mismo contrato;
- inactivar al último superadministrador.

El bloqueo solo es eficaz dentro de una transacción y debe obtenerse en un orden consistente para evitar interbloqueos.

## Comandos de consola

- `human-resources:activate-starting-contracts`: activa contratos cuya fecha inicial llegó.
- `human-resources:expire-contracts`: cierra contratos vencidos e inactiva accesos sin otra vigencia.
- `sigal:bootstrap-superadministrator`: crea la identidad administrativa inicial.
- `sigal:bootstrap-human-resources-administrator`: crea la cuenta institucional exclusiva de RR. HH.

Consultar opciones exactas con `php artisan help <comando>` y nunca registrar contraseñas de producción en scripts o documentación.

## Estándar de comentarios

Se documentan clases de servicio, métodos públicos, algoritmos de estado, bloqueos, compensaciones de archivos y reglas no evidentes. DTO, Resources, migraciones y plantillas declarativas se explican en este manual y mediante nombres/tipos. No se agregan comentarios como “incrementa página” encima de `page += 1`, porque duplican el código y se desactualizan con facilidad.
