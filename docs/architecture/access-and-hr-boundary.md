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

- Un contrato que empieza hoy o antes activa/reutiliza la cuenta, abre membresía y asigna el rol seleccionado.
- Un contrato futuro conserva la cuenta inactiva hasta su fecha inicial.
- Al vencer o finalizar el único contrato vigente se cierran las asignaciones creadas por él, se inactiva la cuenta y se revocan tokens.
- Una cuenta inactiva no puede autenticarse ni continuar usando un token anterior.
- Una contraseña temporal limita la sesión al cambio de clave o logout.
- Las claves actuales no son recuperables: Laravel conserva un hash. El superadministrador puede generar una clave temporal de emergencia, nunca visualizar la anterior.

## Roles

- `super_administrator`: administración completa de SIGAL.
- `human_resources_manager`: administración de RR. HH. sin privilegios globales.
- `observer`: consulta transversal de expedientes según Policy.
- `simple_user`: operación documental según creación, oficina, tenencia y jerarquía.

Los roles no reemplazan la pertenencia a oficina. Para derivar o actuar sobre un expediente se evalúan ambas dimensiones y las reglas específicas del flujo.

## Seguridad e historia

- Solo superadministración gestiona identidades/roles globales y reset operativo.
- Superadministración y el rol de RR. HH. administran funcionarios y contratos.
- El último superadministrador activo está protegido frente a inactivación o pérdida de rol.
- La creación, edición, activación, inactivación, cambio de contraseña, reset y asignación/cierre de roles se auditan.
- Los historiales no se eliminan cuando una persona deja de trabajar.

Para el flujo HTTP y las clases involucradas, consultar [../manual-tecnico.md](../manual-tecnico.md) y [../reference/backend.md](../reference/backend.md).
