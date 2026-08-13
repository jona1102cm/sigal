# Acceso al sistema y frontera con Recursos Humanos

Estado: **implementado y pendiente de la definición funcional de Recursos Humanos**.

## Alcance actual

- `users` representa una identidad de acceso al sistema, no un contrato laboral ni una ficha de personal.
- Cada usuario tiene estado `active` o `inactive`. Al inactivarlo se conserva toda su historia, se revocan sus tokens de Sanctum y se bloquea cualquier acceso posterior. Un cambio de contraseña también revoca los tokens vigentes.
- Los únicos roles definidos actualmente son `super_administrator`, `observer` y `simple_user`.
- Los roles se asignan y se cierran de forma histórica mediante `user_role_assignments`; no se eliminan registros previos.
- Solo un superadministrador activo puede administrar usuarios y roles. El sistema protege al último superadministrador activo para evitar perder el acceso institucional, incluso ante solicitudes concurrentes.
- Toda autenticación y acción de administración de usuarios o roles genera un evento inmutable en `activity_logs`.

## Efecto de los roles en Gestión Documental

- `super_administrator`: administración completa, incluida Legislaturas y Directiva.
- `observer`: podrá consultar cualquier flujo de Expedientes cuando se implemente su política.
- `simple_user`: podrá consultar lo creado por él o lo tramitado por su oficina cuando se implemente la política de Expedientes.
- La visibilidad del jefe sobre actuaciones de dependientes será una regla de la política de Expedientes apoyada por la futura estructura organizacional; no se modela como un campo duplicado en `users`.

## Límite con Recursos Humanos

Recursos Humanos será la fuente institucional de funcionarios, contrataciones, cargos, montos, fechas de inicio y finalización. El módulo de acceso se vinculará posteriormente a esa fuente mediante una relación explícita; no copiará contratos ni datos salariales.

Antes de implementar RR. HH. se deben definir, entre otros, modalidades de contratación, estados y renovaciones, aprobaciones, tratamiento de adendas, confidencialidad salarial, estructura organizativa y relación entre funcionario, contrato y cuenta de acceso.
