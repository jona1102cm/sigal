# Legislaturas y gestión legislativa

Estado: **implementado en backend e interfaz web; pendiente de despliegue en la beta**.

## Legislaturas y Directiva institucional

- Una legislatura representa un período anual compuesto por dos años consecutivos, como `2026-2027`.
- El período se almacena con `start_year` y `end_year`; la etiqueta visible se deriva de ambos datos.
- Solo puede existir una legislatura activa. Al activar una nueva, SIGAL inactiva la anterior dentro de la misma transacción.
- Los estados permitidos son `active` e `inactive`. El período puede corregirse si fue registrado con error, manteniendo la consecutividad y unicidad.
- La Directiva institucional conserva los cargos Presidente, Vicepresidente, Segundo Vicepresidente, Secretaria y Segunda Secretaria.
- Cada cargo admite un único titular vigente, pero un usuario puede ocupar más de un cargo institucional simultáneamente.
- Cada reemplazo cierra la asignación anterior en el mismo instante en que comienza la nueva y conserva fecha administrativa, fecha/hora exacta e historial.

## Identidad del asambleísta y perfiles simultáneos

- Cada asambleísta utiliza una sola cuenta de usuario y una asignación histórica a la legislatura.
- La misma persona puede actuar simultáneamente como miembro de su bancada y de una o varias comisiones, incluidas comisiones mixtas temporales.
- Cada bancada admite Jefe, Subjefe y Miembros. Jefe y Subjefe son cargos únicos vigentes dentro de esa bancada.
- Cada comisión admite Presidente, Vicepresidente, Secretario y Miembros. Los tres primeros son cargos únicos vigentes dentro de esa comisión.
- Un asambleísta puede pertenecer a una sola bancada durante un mismo intervalo, pero a varias comisiones.
- La interfaz ofrece el selector **Actuar como**. La selección se envía en cada solicitud mediante una cabecera de contexto; la API vuelve a validar vigencia, pertenencia y autoridad, por lo que cambiar datos del navegador no otorga permisos.

## Autoridad, preparación y delegaciones

- En una bancada, todos los miembros y el personal administrativo autorizado pueden leer la correspondencia general; únicamente el Jefe responde o deriva.
- En una comisión, todos los miembros pueden leer y el personal administrativo o asesor puede preparar borradores; únicamente el Presidente responde o deriva.
- Un borrador preparado no representa una respuesta oficial. La autoridad vigente debe emitirlo y derivarlo, operación que SIGAL ejecuta de forma atómica y deja el documento emitido inmutable.
- Las sustituciones son temporales, expresas y auditadas: el Subjefe sustituye al Jefe y el Vicepresidente al Presidente.
- Durante una delegación vigente, la autoridad principal deja de poder actuar en esa representación para evitar respuestas simultáneas o contradictorias.
- El remitente institucional de un documento emitido por una bancada o comisión conserva una instantánea de su Directiva vigente. La cuenta que ejecutó materialmente la acción queda identificada aparte en auditoría.

## Correspondencia general y personal

- La correspondencia general dirigida a una bancada puede ser leída por todos sus miembros y personal administrativo.
- Secretaría General puede individualizar una llegada a un asambleísta perteneciente a la bancada destinataria.
- Una llegada individual solo puede ser leída y respondida por el asambleísta indicado y por el personal administrativo de la bancada. La respuesta se emite a nombre del asambleísta.
- La atención personal no crea un expediente separado ni altera la oficina destinataria; se conserva como dato auditable del destinatario de la derivación.

## Secretaría General como canal legislativo

- Toda correspondencia institucional que ingresa al ámbito legislativo debe pasar primero por Secretaría General (`SGEN`).
- Toda respuesta o salida de una bancada o comisión se deriva exclusivamente a Secretaría General. La autoridad política la emite y Secretaría General conserva la función centralizadora y el despacho administrativo.
- Solo Secretaría General puede individualizar correspondencia dentro de una bancada y solo su personal vigente puede remitir un expediente al Pleno.
- Estas reglas se validan en los servicios del backend; la interfaz únicamente refleja las acciones autorizadas.

## Lista de espera del Pleno

- **Derivar al Pleno** incorpora el expediente a una lista de espera sin transferir la tenencia administrativa: Secretaría General sigue siendo la oficina responsable del flujo físico y administrativo.
- Todos los asambleístas con mandato y cuenta vigentes pueden consultar el expediente completo y sus documentos, incluso cuando sea reservado o confidencial, porque el tratamiento por el Pleno requiere conocimiento de sus 28 integrantes.
- La incorporación no decide, archiva ni concluye el expediente. La agenda, sesiones, votaciones, tratamiento y resultado corresponden al futuro módulo de CCP y Sesiones.
- Cuando el Pleno adopte una decisión, Presidencia instruirá a Secretaría General y esta ejecutará el movimiento correspondiente. Si el flujo termina, el expediente se remitirá a la Unidad de Registros y Archivos (`URA`).

## Seguridad y auditoría

- Solo superadministración gestiona legislaturas, mandatos, bancadas, comisiones, membresías y delegaciones.
- El acceso legislativo exige simultáneamente cuenta activa, legislatura activa, mandato vigente y perfil vigente.
- Cerrar un mandato cierra sus membresías políticas abiertas. Los intervalos y cargos únicos se protegen también mediante restricciones PostgreSQL.
- Cada documento emitido registra la identidad representada y una instantánea del remitente. Cada evento de auditoría registra actor real, perfil de actuación, IP, navegador, fecha/hora y cambios.
- Los cierres no borran historial. La limpieza operativa UAT elimina transacciones de prueba en el orden seguro, pero conserva la auditoría inmutable y sus instantáneas.

## Componentes principales

- Modelos: `LegislatureMember`, `LegislativeBody`, `LegislativeBodyMembership`, `LegislativeDelegation` y `PlenaryReferral`.
- Servicios: `LegislativeCompositionService`, `LegislativeAuthorizationService` y `PlenaryReferralService`.
- Middleware: `ResolveLegislativeActingContext`, responsable de validar el perfil elegido antes de llegar al dominio.
- Interfaz: administración de composición dentro de Legislaturas, selector global **Actuar como** y espacio **Lista de espera del Pleno**.
