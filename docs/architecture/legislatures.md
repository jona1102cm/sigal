# Legislaturas y Directiva

Estado: **implementado y verificado en PostgreSQL 18 el 29 de julio de 2026**.

## Reglas implementadas

- Una legislatura representa un período anual compuesto por dos años consecutivos, como `2026-2027`.
- El período se almacena con `start_year` y `end_year`; la etiqueta visible se deriva de ambos datos y no es editable como texto libre.
- Solo puede existir una legislatura con estado `active`. PostgreSQL lo impone mediante un índice único parcial.
- Los estados permitidos son `active` e `inactive`.
- Activar una legislatura inactiva, o crear una directamente activa, inactiva automáticamente la anterior dentro de la misma transacción.
- El período puede corregirse después de crearlo, incluso si está activo, siempre que conserve años consecutivos y no duplique otro período.
- La numeración de rutas usará la legislatura activa: `SIGAL-000001/2026-2027`. La generación se implementará junto a Expedientes.

## Directiva

- Los cargos son: Presidente, Vicepresidente, Segundo Vicepresidente, Secretaria y Segunda Secretaria.
- Los titulares son Usuarios del Sistema.
- Un usuario puede ocupar varios cargos en forma simultánea.
- Cada cargo admite un único titular vigente por legislatura. PostgreSQL impide intervalos temporales solapados para una misma posición.
- Reemplazar un titular cierra obligatoriamente la asignación anterior en la misma fecha y hora efectiva de la nueva asignación.
- Se conserva `effective_on` (fecha administrativa), `effective_at` (instante exacto), y sus equivalentes de cierre. Las restricciones validan que la fecha administrativa corresponda al instante registrado en `America/La_Paz`.

## Seguridad y auditoría

- Solo los usuarios con una asignación vigente del rol `super_administrator` pueden consultar o administrar Legislaturas y Directiva a través de la API.
- Las asignaciones de roles son históricas: tienen inicio, cierre y responsable de asignación, sin sobrescribir registros anteriores.
- Cada creación, edición, activación, inactivación, consulta, cierre de cargo y nueva asignación genera un registro inmutable en `activity_logs` con actor, IP, navegador, valores previos/nuevos e instante.

## Estructura entregada

- Modelos: `Legislature`, `LegislatureBoardAssignment`, `Role`, `UserRoleAssignment` y `ActivityLog`.
- Servicios: `LegislatureService` y `ActivityLogger`.
- API Sanctum: `GET/POST /api/legislatures`, `GET/PATCH /api/legislatures/{id}`, activación, inactivación y asignación de Directiva.
- Interfaz Vue/Pinia en `/legislatures`, preparada para una sesión autenticada.
- El seeder registra el rol `super_administrator`; la futura administración completa de Usuarios y Roles asignará ese rol a personas reales.

## Verificación de infraestructura

Las migraciones y el seeder se ejecutaron correctamente en `sigal`. La prueba de integración se ejecutó correctamente sobre `sigal_test` y verifica la inactivación atómica, el reemplazo de Directiva y las restricciones de la base de datos.
