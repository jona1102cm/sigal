# Gestión documental y expedientes

Estado: **backend e interfaz web implementados**.

## Interfaz operativa

- La aplicación web Vue ofrece inicio de sesión, tablero, bandeja de expedientes y un detalle a pantalla completa organizado por resumen, derivaciones, documentos, ciclo de vida y acceso.
- Los superadministradores disponen de Administración para crear, editar, activar o inactivar oficinas, definir su dependencia en el organigrama y conservar las asignaciones históricas de responsables y funcionarios. También administran los tipos de expediente.
- El usuario selecciona las oficinas desde un directorio operativo de solo lectura que refleja su jerarquía. Este directorio expone únicamente código, nombre y dependencia de oficinas activas, nunca membresías ni jefaturas.
- Cuando un catálogo o las oficinas disponibles contienen una única opción, la interfaz la utiliza por defecto y la presenta como dato fijo; evita obligar una selección redundante.
- La sesión informa al usuario sobre sus propias oficinas vigentes. En un ingreso interno, SIGAL deriva automáticamente la oficina remitente y el usuario registrador; únicamente un usuario con más de una oficina vigente elige cuál quedará responsable. La autorización final siempre se aplica en la API.
- El historial de solicitudes de reapertura se consulta desde el propio expediente; OMAF conserva la decisión de aprobar o rechazar.

## Modelo institucional

- Un expediente es el proceso administrativo que agrupa documentos; no es un documento.
- Cada documento pertenece obligatoriamente a un expediente. Un expediente puede reunir documentos de distintos tipos y de varias oficinas.
- Una derivación es un acto administrativo: no desplaza archivos. Puede referenciar uno o más documentos del expediente mediante una relación muchos-a-muchos.
- La ruta institucional se numera como `SIGAL-000001/AAAA-AAAA` y se reinicia por legislatura. Los documentos oficiales se numeran por oficina, legislatura y tipo documental, con una serie institucional persistente.

## Estados y movimientos

- El estado global del expediente se calcula a partir de las respuestas de sus destinatarios: derivado, recibido, en proceso, respondido parcialmente, respondido completamente, devuelto o rechazado.
- Cada destinatario de una derivación puede ser principal o en copia y registra su propio estado. Los usuarios actúan mediante la membresía vigente de su oficina.
- Un expediente no puede archivarse mientras existan acciones pendientes en las derivaciones vigentes.
- Archivo Central (`ARCH`) tiene las capacidades institucionales para archivar, cerrar y anular. Los superadministradores también pueden ejecutarlas.
- La reapertura se solicita por el jefe vigente de la oficina responsable. La aprueba o rechaza un jefe vigente de OMAF, como máxima autoridad administrativa; no corresponde a Secretaría General.

## Confidencialidad y acceso

- Los expedientes se crean inicialmente con nivel `interno`. El catálogo distingue Público institucional, Interno, Reservado y Confidencial; los dos últimos requieren concesiones expresas de acceso.
- Para un expediente confidencial solo acceden los superadministradores y los usuarios u oficinas con una concesión explícita vigente. El rol observador no reemplaza esa concesión.
- En expedientes no confidenciales, cada observador ve el expediente completo únicamente cuando alguna oficina participante pertenece a las raíces de observación que le asignó superadministración o a sus dependencias. El alcance se registra por intervalos: al retirar una oficina, conserva consulta histórica sobre los expedientes que ya alcanzó, pero no incorpora actuaciones futuras ajenas al nuevo alcance.
- El usuario simple solo accede a expedientes creados por él o que llegaron a una de sus oficinas, sujeto además a la modalidad interna configurada para esa oficina. Archivo Central y OMAF conservan la visibilidad estrictamente necesaria para sus capacidades institucionales.
- Las concesiones de acceso, su inicio y cierre, quedan conservadas para auditoría.

## Distribución dentro de una oficina

- La llegada a una oficina y la asignación a un funcionario son hechos distintos. La primera es una derivación interinstitucional; la segunda queda como historial interno auditable y no crea un movimiento adicional.
- En una oficina que exige jefatura, su responsable vigente elige una modalidad persistente: **asignación individual por la jefatura** o **equipo autorizado permanente**.
- En asignación individual, inicialmente solo la jefatura puede actuar. Para cada llegada designa un único responsable operativo y, opcionalmente, colaboradores de lectura. Una vez asignado otro responsable, solo ese funcionario responde, documenta y deriva; la jefatura conserva lectura y capacidad de reasignar.
- En equipo autorizado, la jefatura y los funcionarios seleccionados pueden operar las llegadas de la oficina. La lista queda vigente para los expedientes siguientes hasta que la jefatura la modifique.
- Las oficinas que admiten funcionarios pero no requieren responsable usan automáticamente **todos los miembros**; actualmente corresponde a Asesores del Pleno y Asesores de Presidencia.
- Estas reglas controlan lectura y operación, pero nunca amplían un expediente confidencial. El acceso reservado o confidencial continúa requiriendo una concesión expresa de superadministración.

## Documentos, versiones y adjuntos

- Los documentos nacen como borradores. Cada modificación crea una revisión inmutable.
- Al emitir un documento se reserva su numeración y queda inmutable. Una corrección crea un borrador de la misma serie con versión superior y conserva el historial del emitido anterior.
- Un borrador puede contener contenido redactado opcionalmente mediante editor de texto enriquecido o funcionar solo como contenedor de anexos. El HTML se sanea en el servidor antes de guardarse y al mostrarse.
- Un borrador admite múltiples anexos de cualquier tipo de archivo. Los adjuntos se almacenan fuera de PostgreSQL; la base guarda solamente su metadato, ruta de almacenamiento, tamaño, tipo MIME y hash SHA-256.
- La descarga de cada anexo exige autorización de lectura del expediente y se registra en la auditoría. Los límites de tamaño se rigen por la configuración de carga de PHP y del servidor.

## Registro de ingreso documentado

- El flujo normal inicia con el registro de un documento de origen. En una unica transaccion se crean el expediente, su antecedente inicial y todos los anexos cargados.
- El ingreso exige fecha de recepción, tipo documental, número y fecha del documento inicial, y contenido redactado o al menos un archivo adjunto. El remitente y su tipo se solicitan únicamente si el documento fue recibido desde una persona o institución externa; en un ingreso interno se derivan de la oficina responsable y de la sesión.
- La prioridad se estandariza como Normal, Alta o Urgente; Normal es el valor inicial tanto para el expediente como para sus derivaciones. El campo de clasificación queda fuera del nuevo registro por ser redundante con el tipo de expediente y la confidencialidad, aunque se conserva en históricos.
- El documento inicial conserva el antecedente recibido: no puede editarse, emitirse, corregirse ni recibir anexos posteriormente. Un documento de origen externo no se atribuye a una oficina emisora interna.
- La creacion de un expediente sin documento permanece disponible solamente como ruta excepcional. Los documentos posteriores se crean dentro del mismo expediente, nunca abren uno nuevo.
- Una oficina puede crear, editar, emitir o adjuntar documentacion solo antes de la primera derivacion cuando es responsable, o despues de recibir una derivacion dirigida a ella. Esta regla se valida tambien en el servidor.

## Endpoints principales

Todos requieren autenticación Sanctum y un usuario activo. El prefijo real depende de la configuración de rutas de Laravel, normalmente `/api`.

- `POST /expedient-entries` para registrar el ingreso documentado; `GET|POST /expedients`, `GET /expedients/{expedient}`
- `GET|POST /expedients/{expedient}/movements`
- `POST /expedients/{expedient}/movement-recipients/{recipient}/status`
- `GET|PUT /expedients/{expedient}/movement-recipients/{recipient}/internal-assignments`
- `GET|PUT /document-management/offices/{office}/access-setting`
- `POST /expedients/{expedient}/archive`, `/close`, `/void`
- `POST /expedients/{expedient}/reopening-requests` y sus acciones `/approve` o `/reject`
- `GET|POST /expedients/{expedient}/documents`
- `PATCH /expedients/{expedient}/documents/{document}`, y las acciones `/issue`, `/corrections`, `/attachments`, `/movement-links`
- `GET /expedients/{expedient}/documents/{document}/revisions`
- `GET|POST /expedients/{expedient}/access-grants` y `POST .../access-grants/{grant}/close`

Las reglas de autorización se aplican en el servidor mediante Policies, Form Requests y servicios transaccionales; la interfaz no es una fuente de autorización.
