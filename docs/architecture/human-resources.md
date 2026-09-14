# Recursos Humanos

Estado: **implementado en la versión beta, incluida la importación masiva**.

## Propósito

Recursos Humanos es el origen institucional de los funcionarios y de su historial contractual. Desde este módulo se alimentan las personas que, cuando corresponde, tienen una cuenta de acceso en SIGAL.

Funcionario, contrato, cargo, membresía de oficina y rol de sistema son conceptos distintos. Sus relaciones conservan vigencia e historial.

## Reglas implementadas

- Datos obligatorios: nombres, apellidos, CI, celular, fecha de nacimiento, grado académico y profesión.
- Datos opcionales: correo, dirección, número de CUA, libreta de servicio militar, tipo de sangre y contacto de emergencia.
- Nombres y apellidos se normalizan en mayúsculas.
- El CI evita duplicar a una persona que trabajó anteriormente.
- Los respaldos REJAP, CENVI, padrón biométrico, otros documentos y fotografía se guardan fuera de PostgreSQL con metadatos y SHA-256.
- El kardex puede corregirse con validación y auditoría. Los respaldos nuevos se agregan sin borrar evidencia anterior.
- Tipos de contrato: Eventual, Consultoría de Línea, TGN y Funcionamiento.
- Tipo, inicio, oficina y cargo son obligatorios. Fin y monto pueden ser opcionales según el contrato.
- Un funcionario no puede mantener más de un contrato vigente.
- Cada cargo pertenece a una oficina; el formulario filtra cargos por la oficina elegida y permite crear uno autorizado cuando falta.
- Registrar un contrato crea o reutiliza la cuenta y abre membresía. Toda cuenta creada por RR. HH., incluso cuando actúa un superadministrador, recibe exclusivamente el rol Usuario simple; los privilegios se asignan después desde Administración.
- Si no hay correo, SIGAL genera una dirección interna corta y única basada en el CI.
- Un contrato futuro conserva el acceso inactivo hasta su inicio.
- Al finalizar/vencer el contrato se cierran sus asignaciones, se revocan tokens y se inactiva la cuenta si no existe otra vigencia.
- Una nueva contratación reutiliza kardex y cuenta, sin duplicar la persona.
- Existe una cuenta institucional independiente con rol `human_resources_manager`, además del acceso del superadministrador.

## Importación masiva

Superadministración y RR. HH. pueden descargar una plantilla XLSX en español y subirla llena. La importación mantiene intacto el alta manual y aplica las mismas reglas de negocio.

- La plantilla ofrece selects de grado académico, tipo de sangre y oficina.
- Las oficinas se muestran por nombre completo; el lector también acepta códigos por compatibilidad.
- La plantilla no solicita un rol: todos los registros importados nacen como Usuario simple.
- Correo, dirección, CUA, libreta militar, tipo de sangre, contacto de emergencia y fecha final son opcionales.
- El lector valida tipo/estructura del libro; el servicio valida encabezados, catálogos, fechas, CI y duplicados.
- Los errores se reportan por fila para poder corregir el archivo.
- El alta de cada registro reutiliza el flujo transaccional de RR. HH.; no existe una segunda lógica de contratos para Excel.

## Operación programada

El scheduler ejecuta diariamente la activación de contratos que comienzan y el cierre de contratos vencidos. Producción debe invocar el scheduler de Laravel; si se detiene, las fechas cambian en base pero sus efectos de acceso no se aplican automáticamente.

## Modelo

- `employees`: kardex personal.
- `office_positions`: catálogo de cargos por oficina y función responsable/funcionario.
- `employment_contracts`: historial contractual y asignaciones originadas.
- `employee_attachments`: respaldos y fotografía.
- `users`: identidad de acceso reutilizable.
- `office_memberships`: ubicación histórica.
- `user_role_assignments`: permisos históricos.

El alta coordinada registra funcionario, contrato, cuenta, rol y membresía en una transacción. Si una regla falla, no queda una contratación parcial.

## Consideración vigente

La extensión se habilita para contratos con fecha final. Los contratos sin fecha final no requieren extensión; su cierre se realiza expresamente cuando corresponda.
