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
| `office_memberships` | Pertenencia histórica usuario-oficina. |
| `office_document_access_settings` | Modalidad persistente con la que una oficina distribuye sus llegadas. |
| `office_document_access_authorizations` | Equipo permanente autorizado por una jefatura, con vigencia histórica. |
| `employees` | Kardex personal único por CI. |
| `office_positions` | Cargos propios de cada oficina. |
| `employment_contracts` | Contratación, vigencia, tipo, monto, cargo, oficina y asignaciones creadas. |
| `employee_attachments` | Foto/certificados/respaldos con ruta, metadatos y hash. |

Contrato, membresía y rol permanecen separados. El contrato puede referenciar las asignaciones que originó para cerrarlas al terminar sin afectar asignaciones institucionales ajenas.

## Catálogos y numeración documental

| Tabla | Propósito |
|---|---|
| `expedient_types` | Clasificación del proceso administrativo. |
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
| `expedient_internal_assignments` | Responsable operativo único y colaboradores de lectura para una llegada, con historial. |
| `expedient_reopening_requests` | Solicitud y decisión de reapertura. |

La tenencia vigente se obtiene del último movimiento y sus destinatarios; no debe inferirse solo desde `responsible_office_id`, que conserva contexto del alta. La asignación interna no cambia esa tenencia ni genera una nueva derivación: restringe qué miembro de la oficina puede operar la llegada.

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
