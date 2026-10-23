# Referencia de base de datos

PostgreSQL es la fuente persistente. Las migraciones de `database/migrations` son el historial canónico del esquema; este documento explica su intención, no las sustituye.

## Identidad e infraestructura

| Tabla | Propósito |
|---|---|
| `users` | Identidad, email de acceso, hash de contraseña, estado y cambio obligatorio. |
| `personal_access_tokens` | Tokens Sanctum. |
| `password_reset_tokens` | Infraestructura de recuperación Laravel. |
| `sessions` | Sesiones cuando se usa driver de base. |
| `roles` | Catálogo de roles. |
| `permissions` | Catálogo estable de vistas y acciones autorizables. |
| `permission_role` | Matriz auditable de permisos asignados a cada rol fijo. |
| `user_role_assignments` | Rol histórico con inicio, cierre y actor. |
| `observer_office_scopes` | Raíces de observación por intervalo; sus dependencias se resuelven desde el organigrama. |
| `activity_logs` | Auditoría inmutable de seguridad/dominio. |
| `cache`, `cache_locks` | Caché/locks de infraestructura. |
| `jobs`, `job_batches`, `failed_jobs` | Cola y fallos de trabajos. |

`users.password` nunca contiene texto claro. Un soporte solo puede iniciar un reset temporal, no recuperar la clave anterior.

## Legislaturas

| Tabla | Propósito |
|---|---|
| `legislatures` | Período, años consecutivos y estado. |
| `legislature_board_assignments` | Titular por cargo e intervalo efectivo. |

Existe protección para una sola legislatura activa y para impedir solapamiento de titulares en el mismo cargo/período.

## Organización y Recursos Humanos

| Tabla | Propósito |
|---|---|
| `offices` | Árbol institucional, código, estado y reglas de dotación/responsable. |
| `office_memberships` | Pertenencia operativa histórica usuario-oficina; vincula contrato, cargo efectivo y rotación cuando corresponde. |
| `office_document_access_settings` | Modalidad persistente con la que una oficina distribuye sus llegadas. |
| `office_document_access_authorizations` | Equipo permanente autorizado por una jefatura, con vigencia histórica. |
| `office_manager_assignments` | Proyección histórica de la titularidad originada por un cargo contractual responsable. |
| `office_manager_delegations` | Suplencias administrativas temporales con titular, delegado, período, motivo y actores. |
| `employees` | Kardex personal único por CI. |
| `office_positions` | Cargos propios de cada oficina. |
| `employment_contracts` | Contratación, vigencia, tipo, monto, cargo, oficina y asignaciones creadas. |
| `employment_rotations` | Disposición que cambia oficina operativa sin reescribir contrato, monto u origen presupuestario; guarda el modo de conservar o crear cargo y su fotografía histórica. |
| `employment_rotation_attachments` | Respaldo opcional de la rotación con ruta, metadatos y SHA-256. |
| `employee_attachments` | Foto/certificados/respaldos con ruta, metadatos y hash. |

Contrato, membresía, rotación y rol permanecen separados. Solo puede existir una membresía operativa abierta por contrato y una rotación abierta por contrato. Una rotación con cargo conservado deja `office_position_id` en `NULL` en la nueva membresía y conserva denominación/función como fotografía, evitando enlazar un cargo perteneciente a otra oficina. Si se crea un cargo, este sí pertenece al catálogo de destino y queda referenciado. La membresía de tipo `manager`, derivada del cargo contractual o del tratamiento elegido, origina automáticamente `office_manager_assignments`; una delegación suspende temporalmente la operación del titular sin reescribir su contrato ni el historial.

## Catálogos y numeración documental

| Tabla | Propósito |
|---|---|
| `expedient_types` | Catálogo heredado, conservado solamente para no destruir la clasificación de expedientes históricos. Los registros nuevos dejan `expedient_type_id` en `NULL`. |
| `document_types` | Circular, nota, informe y otros tipos. |
| `confidentiality_levels` | Nivel de visibilidad. |
| `office_capabilities` | Facultades especiales como archivo/reapertura. |
| `institutional_sequences` | Correlativo SIGAL por legislatura. |
| `office_document_sequences` | Configuración del correlativo por oficina. |
| `document_number_series` | Serie institucional reservada para documentos. |

Los contadores se actualizan con bloqueo pesimista. No calcular el siguiente número con `MAX()+1`, porque dos solicitudes simultáneas podrían duplicarlo.

## Expedientes

| Tabla | Propósito |
|---|---|
| `expedients` | Proceso, número SIGAL, origen, responsable inicial, prioridad, plazo y estado agregado. |
| `expedient_access_grants` | Acceso extraordinario temporal a usuario u oficina. |
| `expedient_movements` | Derivación emitida por una oficina. |
| `expedient_movement_recipients` | Destinatario primario/copia y estado independiente. |
| `expedient_recipient_reads` | Primera apertura de una llegada por usuario; no concede control operativo. |
| `expedient_internal_assignments` | Responsable operativo único y colaboradores de lectura para una llegada, con historial. |
| `expedient_assignment_requests` | Solicitudes, decisiones y cancelaciones para transferir el responsable operativo. |
| `expedient_reopening_requests` | Solicitud y decisión de reapertura. |

La tenencia vigente se obtiene del último movimiento y sus destinatarios; no debe inferirse solo desde `responsible_office_id`, que conserva contexto del alta. La lectura individual, la tenencia de oficina y el responsable operativo son conceptos separados. La asignación interna no cambia la tenencia ni genera una nueva derivación: restringe qué miembro puede operar. Solo puede existir una solicitud pendiente por usuario y llegada; aceptar una cancela las demás. Tampoco debe inferirse el módulo o resultado del trámite desde un tipo fijo: las tablas especializadas, por ejemplo `material_requests`, se relacionan directamente con `expedients`.

## Documentos y archivos

| Tabla | Propósito |
|---|---|
| `documents` | Documento perteneciente a un expediente, tipo, emisor, número y estado. |
| `document_revisions` | Contenido/versiones inmutables. |
| `document_attachments` | Metadatos y hash de binarios externos. |
| `document_expedient_movement` | Relación muchos-a-muchos documento-derivación. |

No existen documentos huérfanos. Un documento emitido se corrige agregando revisión, no sobrescribiendo evidencia. El pivot permite que una derivación incluya documentos y que un documento participe en el flujo sin “mover” su archivo.

## Convenciones de historia

- `starts_at`/`ends_at` o equivalentes representan intervalos de vigencia.
- Un valor final `NULL` suele significar asignación vigente, no dato desconocido.
- `created_by`, `assigned_by`, `closed_by`, `sent_by`, etc. identifican al actor responsable.
- Fechas administrativas y timestamps pueden coexistir cuando la institución necesita ambas evidencias.
- Las eliminaciones físicas están reservadas a operaciones extraordinarias expresamente documentadas.

## Evolución del esquema

1. Crear una migración nueva; no editar una migración ya aplicada en producción.
2. Incluir índices para claves de búsqueda y relaciones frecuentes.
3. Expresar invariantes críticas también en PostgreSQL cuando sea posible.
4. Mantener compatibilidad durante despliegues si aplicación y migración no cambian en el mismo instante.
5. Actualizar modelo, Request, DTO, Resource, pruebas y este documento.
6. Probar migración hacia adelante sobre copia/restauración controlada.

## Respaldo mínimo

Una copia completa de SIGAL requiere dos elementos sincronizados:

1. dump de PostgreSQL;
2. volumen/directorio `storage` con adjuntos.

Respaldar solo la base conserva metadatos, pero no los documentos binarios. Respaldar solo archivos pierde relaciones, permisos, hashes y auditoría.
