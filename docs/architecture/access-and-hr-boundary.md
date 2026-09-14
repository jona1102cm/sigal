# Acceso al sistema y relación con Recursos Humanos

Estado: **implementado en la versión beta**.

## Separación de responsabilidades

- `users` representa una identidad de acceso; no es un contrato ni duplica el kardex.
- `employees` representa a la persona y se identifica de forma única por CI.
- `employment_contracts` conserva cada relación laboral y su vigencia.
- `office_memberships` conserva la pertenencia histórica del usuario a una oficina.
- `user_role_assignments` conserva roles por intervalos.

Esta separación permite que una persona vuelva a la institución con otro contrato, oficina o rol utilizando el mismo kardex y la misma identidad, sin perder historia.

## Ciclo de acceso

- Un contrato que empieza hoy o antes activa/reutiliza la cuenta, abre membresía y asigna el rol Usuario simple.
- Un contrato futuro conserva la cuenta inactiva hasta su fecha inicial.
- Al vencer o finalizar el único contrato vigente se cierran las asignaciones creadas por él, se inactiva la cuenta y se revocan tokens.
- Una cuenta inactiva no puede autenticarse ni continuar usando un token anterior.
- Una contraseña temporal limita la sesión al cambio de clave o logout.
- Las claves actuales no son recuperables: Laravel conserva un hash. El superadministrador puede generar una clave temporal de emergencia, nunca visualizar la anterior.

## Roles

- `super_administrator`: administración completa de SIGAL; su matriz es total e inmutable.
- `human_resources_manager`: administración de RR. HH. sin privilegios globales.
- `observer`: consulta del expediente completo dentro de las oficinas seleccionadas por superadministración y todas sus dependencias.
- `simple_user`: operación documental según permisos, membresía, llegada a su oficina y distribución interna.

Los cuatro roles son fijos, pero superadministración puede editar sus permisos mediante una matriz de vistas y acciones. Un permiso habilita la capacidad general; no reemplaza el alcance de los datos. Para leer o actuar se vuelven a evaluar en la API el rol vigente, sus permisos, la pertenencia a oficina, la confidencialidad, la custodia y la distribución interna.

## Alcance de observación

- Superadministración asigna una o más oficinas raíz a cada observador y la interfaz muestra antes de guardar qué dependencias quedarán incluidas.
- El observador obtiene lectura integral del expediente cuando participa una oficina dentro de su alcance, pero no puede responder ni derivar.
- Al cerrar un alcance se conserva consulta histórica sobre expedientes previamente visibles; los expedientes posteriores quedan fuera.
- Reservados y confidenciales permanecen ocultos mientras no exista una concesión explícita de acceso creada por superadministración para ese usuario u oficina.

## Seguridad e historia

- Solo superadministración gestiona identidades/roles globales y reset operativo.
- Superadministración y el rol de RR. HH. administran funcionarios y contratos.
- Toda cuenta creada desde RR. HH., manualmente o por importación, nace obligatoriamente como `simple_user`. Cualquier elevación posterior se realiza desde Administración y queda auditada.
- El último superadministrador activo está protegido frente a inactivación o pérdida de rol.
- La creación, edición, activación, inactivación, cambio de contraseña, reset y asignación/cierre de roles se auditan.
- Los historiales no se eliminan cuando una persona deja de trabajar.

Para el flujo HTTP y las clases involucradas, consultar [../manual-tecnico.md](../manual-tecnico.md) y [../reference/backend.md](../reference/backend.md).
