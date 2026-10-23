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
- El cargo contractual clasificado como jefatura es la fuente autoritativa del responsable titular. Al registrar manualmente o importar su contrato, SIGAL crea automáticamente la responsabilidad de la oficina; no existe una segunda designación manual.
- Cada oficina admite un único contrato vigente en un cargo responsable. El cierre o reclasificación del cargo termina también la responsabilidad y conserva el historial, motivo y actor.
- Una ausencia temporal no cambia el contrato: titular o superadministración registran una delegación con inicio, fin y motivo desde **Gestión documental › Acceso de mi oficina**. Durante la suplencia, el delegado opera y el titular conserva consulta histórica.
- Registrar un contrato crea o reutiliza la cuenta y abre membresía. Toda cuenta creada por RR. HH., incluso cuando actúa un superadministrador, recibe exclusivamente el rol Usuario simple; los privilegios se asignan después desde Administración.
- Si no hay correo, SIGAL genera una dirección interna corta y única basada en el CI.
- Un contrato futuro conserva el acceso inactivo hasta su inicio.
- Al finalizar/vencer el contrato se cierran sus asignaciones, se revocan tokens y se inactiva la cuenta si no existe otra vigencia.
- Una nueva contratación reutiliza kardex y cuenta, sin duplicar la persona.
- Existe una cuenta institucional independiente con rol `human_resources_manager`, además del acceso del superadministrador.

## Rotaciones de personal

Una rotación no modifica el contrato ni su fuente presupuestaria. SIGAL conserva el cargo y oficina originales en `employment_contracts` y abre una nueva asignación operativa en `office_memberships`.

- Solo superadministración o RR. HH. con `human_resources.rotations.manage` pueden registrar rotaciones.
- La oficina de destino, el tratamiento del cargo y la fecha/hora efectiva son obligatorios. Se admite una fecha pasada dentro del contrato para regularizar movimientos ya ejecutados; no se programan rotaciones futuras.
- El tratamiento del cargo ofrece exactamente dos alternativas: conservar la denominación y función organizacional vigentes, sin crear un cargo ajeno en el catálogo de destino; o definir un cargo nuevo que se crea dentro de la oficina destino y se asigna en la misma transacción.
- Al crear el cargo nuevo se registra expresamente si es funcionario o responsable. Si es responsable, se aplican las mismas restricciones de organigrama y titularidad única que en el alta manual.
- Motivo, número/fecha de memorándum y archivo de respaldo son opcionales.
- La disposición queda vigente hasta una nueva rotación, retorno o finalización contractual. Cada nueva disposición cierra exactamente la anterior.
- Antes de confirmar, la API previsualiza origen, destino, expedientes personales que se liberarán, dotación restante y advertencias de oficina sin personal o sin responsable.
- La oficina puede quedar temporalmente sin personal o responsable, pero SIGAL advierte y registra la decisión. Un cargo responsable de destino se rechaza si produciría dos titulares superpuestos.
- Las asignaciones personales pendientes de Gestión documental se liberan para que la oficina de origen las reasigne; las autorizaciones de equipo de esa oficina también se cierran.
- El funcionario conserva consulta histórica sobre expedientes no confidenciales en los que participó directamente. Los confidenciales permanecen ocultos salvo concesión expresa de superadministración. La consulta histórica nunca concede operación vigente.
- El cierre del contrato cierra la asignación operativa y la rotación abiertas, inactiva la cuenta según las reglas generales y preserva todo el historial.

## Importación masiva

Superadministración y RR. HH. pueden descargar una plantilla XLSX en español y subirla llena. La importación mantiene intacto el alta manual y aplica las mismas reglas de negocio.

- La plantilla ofrece selects de grado académico, tipo de sangre y oficina.
- Las oficinas se muestran por nombre completo; el lector también acepta códigos por compatibilidad.
- La plantilla no solicita un rol: todos los registros importados nacen como Usuario simple.
- Correo, dirección, CUA, libreta militar, tipo de sangre, contacto de emergencia y fecha final son opcionales.
- El lector valida tipo/estructura del libro; el servicio valida encabezados, catálogos, fechas, CI y duplicados.
- Los errores se reportan por fila para poder corregir el archivo.
- El alta de cada registro reutiliza el flujo transaccional de RR. HH.; no existe una segunda lógica de contratos para Excel.
- Si un cargo nuevo se clasifica como jefatura, el funcionario importado queda automáticamente como responsable titular; la importación se rechaza si produciría dos titulares contractuales vigentes en la misma oficina.

## Operación programada

El scheduler ejecuta diariamente la activación de contratos que comienzan y el cierre de contratos vencidos. Producción debe invocar el scheduler de Laravel; si se detiene, las fechas cambian en base pero sus efectos de acceso no se aplican automáticamente.

## Modelo

- `employees`: kardex personal.
- `office_positions`: catálogo de cargos por oficina y función responsable/funcionario.
- `employment_contracts`: historial contractual y asignaciones originadas.
- `employment_rotations`: disposiciones de cambio operativo, oficina destino, modo de tratamiento y fotografía histórica del cargo asignado.
- `employment_rotation_attachments`: metadatos, ruta y huella del archivo opcional.
- `employee_attachments`: respaldos y fotografía.
- `users`: identidad de acceso reutilizable.
- `office_memberships`: ubicación histórica.
- `office_manager_assignments`: historial temporal y auditable de responsables de oficina.
- `office_manager_delegations`: suplencias administrativas temporales, sin modificar contrato ni titularidad.
- `user_role_assignments`: permisos históricos.

El alta coordinada registra funcionario, contrato, cuenta, rol y membresía en una transacción. Si una regla falla, no queda una contratación parcial.

## Consideración vigente

La extensión se habilita para contratos con fecha final. Los contratos sin fecha final no requieren extensión; su cierre se realiza expresamente cuando corresponda.
