# Recursos Humanos

Estado: **implementado en la primera version operativa**.

## Proposito

Recursos Humanos sera el origen institucional de los funcionarios y de su historial contractual. Desde este modulo se podra alimentar la identidad de las personas que, cuando corresponda, tendran una cuenta de acceso en SIGAL.

## Navegacion modular propuesta

La navegacion lateral se organizara por modulos y subopciones:

- Inicio.
- Gestion documental: bandeja de expedientes y registro de ingresos.
- Recursos Humanos: funcionarios, contratos y cuentas de acceso.
- Administracion: organigrama, legislaturas y catalogos institucionales.

Un funcionario, su contrato, su asignacion a una oficina y sus roles de sistema son conceptos distintos. La relacion entre ellos debe conservar historial y fechas de vigencia.

## Reglas ya confirmadas

- Recursos Humanos registra las nuevas contrataciones y alimenta a los funcionarios y usuarios del sistema.
- La ficha personal conserva historiales aunque la persona o su usuario ya no esten activos; el acceso se restringe al inactivar el usuario.
- Datos personales obligatorios: nombres, apellidos, CI, celular, fecha de nacimiento, grado academico y profesion. Los nombres y apellidos se normalizan y guardan en mayusculas.
- Datos personales opcionales: correo, direccion, numero de CUA, libreta de servicio militar, tipo de sangre y contacto de emergencia.
- Se podran adjuntar los certificados REJAP, CENVI y de padron biometrico electoral, ademas de una fotografia de perfil opcional. Sus archivos se guardaran fuera de PostgreSQL, con sus metadatos y huella de contenido auditables.
- El kardex personal puede corregirse por personal autorizado. Las correcciones de datos quedan auditadas; los respaldos se agregan como nuevas evidencias y nunca eliminan su historial. Tambien se admite la categoria "otro documento de respaldo".
- Los contratos permitidos son: Eventual, Consultoria de Linea, TGN y Funcionamiento.
- Un contrato requiere tipo, fecha de inicio, oficina y cargo. La fecha de fin y el monto contractual son opcionales.
- Una persona no puede mantener mas de un contrato vigente.
- Cada cargo pertenece a una oficina. Al elegir una oficina, solo se muestran sus cargos; si falta uno se puede registrar desde el mismo flujo.
- Al registrar el contrato se crea automaticamente una cuenta SIGAL y se le asigna el rol elegido en el kardex. El rol inicial por defecto es Usuario simple.
- Si no se proporciona correo, SIGAL genera una dirección institucional corta y única basada en el CI, con dominio interno `sigal.local`.
- Antes de crear a una persona se consulta su CI. Si ya existe, se reutiliza su kardex y su cuenta en vez de duplicarlos.
- Al finalizar o vencer un contrato, SIGAL cierra la membresía creada por ese contrato, cierra su rol contractual, invalida los tokens e inactiva la cuenta. Una nueva contratación reactiva la misma cuenta sin duplicarla.
- Un contrato con fecha de inicio futura conserva la cuenta inactiva hasta su fecha de inicio; una tarea institucional diaria la activa. Otra tarea diaria finaliza los contratos vencidos.
- Existe una cuenta institucional independiente, no ligada a funcionario, con el rol Administrador de Recursos Humanos. Puede gestionar solamente el módulo de RR. HH.; los superadministradores también mantienen acceso al módulo.

## Operación programada

El programador de Laravel ejecuta diariamente la activación de contratos que comienzan y el cierre de contratos vencidos. En producción debe estar habilitado el programador de la aplicación (`schedule:run` cada minuto o el proceso equivalente del servidor).

## Modelo propuesto

- **Funcionario:** datos personales, fotografia de perfil opcional y documentos de respaldo.
- **Cargo de oficina:** catalogo historico de cargos vinculados a una oficina.
- **Contrato:** relacion historica entre funcionario, cargo y oficina.
- **Usuario SIGAL:** cuenta de acceso vinculada al funcionario, con roles y membresia de oficina separados del contrato para conservar la trazabilidad institucional.

El alta se realizara en una sola transaccion: funcionario, cargo si se crea, contrato, usuario, rol y membresia de oficina. Si una validacion falla, no se registrara una contratacion parcial.

## Decisiones abiertas antes de crear datos reales

- La extensión se habilita para contratos que ya tengan fecha de fin. Los contratos sin fecha de fin no requieren extensión.
