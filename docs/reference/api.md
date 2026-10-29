# Referencia de la API REST

Todas las rutas tienen prefijo `/api`. El login está limitado a seis intentos por minuto. Las demás rutas requieren `Authorization: Bearer <token>`, cuenta activa y contraseña definitiva.

## Convenciones

- `GET`: consulta sin mutación.
- `POST`: creación o acción de dominio.
- `PATCH`: corrección parcial autorizada.
- `DELETE`: cierre lógico de una asignación cuando la URI representa roles; el historial no se borra.
- `200`: operación/consulta correcta.
- `201`: recurso creado.
- `401`: token ausente o inválido.
- `403`: usuario autenticado sin autorización.
- `404`: recurso no encontrado o no perteneciente al padre indicado.
- `422`: validación o regla de negocio incumplida.

Las respuestas de colección usan `data`, `links` y `meta` de paginación. Los errores de validación usan `message` y `errors` por campo.

## Autenticación

| Método | Ruta | Uso |
|---|---|---|
| POST | `/auth/login` | Obtiene token y datos de sesión. |
| GET | `/auth/me` | Restaura usuario, roles y capacidades. |
| POST | `/auth/logout` | Revoca el token actual. |
| POST | `/auth/password` | Cambia la contraseña propia y libera la sesión temporal. |

## Usuarios y roles

| Método | Ruta | Uso |
|---|---|---|
| GET/POST | `/users` | Lista o crea identidades. |
| GET/PATCH | `/users/{user}` | Consulta o corrige una cuenta. |
| POST | `/users/{user}/activate` | Activa la cuenta. |
| POST | `/users/{user}/inactivate` | Inactiva y revoca tokens. |
| POST | `/users/{user}/emergency-password-reset` | Genera una clave temporal de un solo proceso. |
| POST | `/users/{user}/roles` | Abre una asignación histórica de rol. |
| DELETE | `/users/{user}/roles/{role}` | Cierra la asignación vigente. |
| GET | `/authorization/roles` | Obtiene roles fijos, permisos agrupados y matriz actual. |
| PUT | `/authorization/roles/{role}/permissions` | Sustituye la matriz de un rol editable. |
| GET/PUT | `/users/{user}/observer-office-scope` | Consulta o reemplaza raíces de observación y previsualiza dependencias. |

## Recursos Humanos

| Método | Ruta | Uso |
|---|---|---|
| GET | `/human-resources/bootstrap` | Catálogos, oficinas y valores para formularios. |
| GET/POST | `/human-resources/employees` | Lista o registra funcionario y contrato. |
| GET/PATCH | `/human-resources/employees/{employee}` | Kardex completo o corrección. |
| GET | `/human-resources/employee-import-template` | Descarga plantilla XLSX en español. |
| POST | `/human-resources/employees/import` | Valida e importa el libro. |
| POST | `/human-resources/employees/{employee}/profile-photo` | Agrega/reemplaza foto conservando historial. |
| POST | `/human-resources/employees/{employee}/attachments` | Agrega respaldo documental. |
| GET | `/human-resources/offices/{office}/positions` | Lista cargos de la oficina. |
| PUT | `/human-resources/offices/{office}/responsible-position` | Selecciona el único cargo responsable de la oficina sin alterar su denominación contractual; actualiza titularidad y auditoría. |
| POST | `/human-resources/positions` | Crea cargo. |
| PATCH | `/human-resources/positions/{officePosition}` | Corrige cargo. |
| POST | `/human-resources/contracts/{contract}/extend` | Extiende fecha final. |
| POST | `/human-resources/contracts/{contract}/finish` | Finaliza contrato y efectos de acceso. |
| GET | `/human-resources/attachments/{attachment}/download` | Descarga autorizada. |
| POST | `/human-resources/contracts/{contract}/rotations/preview` | Valida oficina, fecha y modo `preserve_current` o `create_new`; previsualiza efectos sin mutar datos. |
| POST | `/human-resources/contracts/{contract}/rotations` | Registra la rotación; al elegir `create_new`, crea el cargo de destino y lo asigna dentro de la misma transacción. |
| GET | `/human-resources/rotation-attachments/{attachment}/download` | Descarga autorizada del respaldo de rotación. |

## Organización

| Método | Ruta | Uso |
|---|---|---|
| GET/POST | `/offices` | Lista o crea nodos. |
| GET | `/offices/directory` | Directorio jerárquico operativo. |
| GET/PATCH | `/offices/{office}` | Consulta o modifica nodo. |
| POST | `/offices/{office}/activate` | Activa nodo. |
| POST | `/offices/{office}/inactivate` | Inactiva si no rompe dependencias. |
| GET/POST | `/offices/{office}/memberships` | Lista o asigna membresía. |
| POST | `/offices/{office}/memberships/{membership}/close` | Cierra membresía. |
| GET/PUT | `/document-management/offices/{office}/access-setting` | Consulta o configura modalidad y equipo documental de la oficina. |
| POST | `/document-management/offices/{office}/manager-delegations` | Registra una suplencia administrativa temporal. |
| POST | `/document-management/offices/{office}/manager-delegations/{delegation}/end` | Finaliza o cancela la suplencia administrativa. |
| POST | `/document-management/offices/{office}/legislative-delegations` | Registra la delegación temporal de Jefe/Presidente a Subjefe/Vicepresidente. |

## Catálogos documentales

| Método | Ruta | Uso |
|---|---|---|
| GET | `/document-types` | Tipos de documento. |
| GET | `/confidentiality-levels` | Niveles de acceso. |

## Expedientes y flujo

| Método | Ruta | Uso |
|---|---|---|
| GET | `/expedients` | Bandeja por `scope=new|pending|finalized`; incluye `inbox.new_count`. |
| POST | `/expedient-entries` | Alta normal con documento inicial y derivación. |
| POST | `/expedients` | Alta excepcional sin documento. |
| GET | `/expedients/{expedient}` | Detalle autorizado. |
| GET/POST | `/expedients/{expedient}/movements` | Historial o nueva derivación. |
| GET | `/expedients/{expedient}/attention` | Estado de lectura/control de la llegada vigente para el usuario. |
| POST | `/expedients/{expedient}/read` | Registra la primera apertura individual sin asignar control. |
| POST | `/expedients/{expedient}/movement-recipients/{recipient}/claim` | Toma voluntariamente una atención libre. |
| POST | `/expedients/{expedient}/movement-recipients/{recipient}/assignment-requests` | Solicita el control; `justification` es opcional. |
| POST | `/expedients/{expedient}/movement-recipients/{recipient}/assignment-requests/{assignmentRequest}/decision` | Acepta/rechaza; `decision_note` es opcional. |
| POST | `/expedients/{expedient}/movement-recipients/{recipient}/transfer` | Transfiere directamente el responsable único; `note` es opcional. |
| POST | `/expedients/{expedient}/movement-recipients/{recipient}/status` | Acción de una oficina destinataria. |
| GET/PUT | `/expedients/{expedient}/movement-recipients/{recipient}/internal-assignments` | Consulta o reemplaza responsable único y colaboradores de una llegada. |
| GET | `/document-management/office-responsibilities` | Directorio vigente de titulares, delegados y oficinas sin autoridad; exclusivo de superadministración. |
| GET/POST | `/expedients/{expedient}/access-grants` | Lista o concede acceso extraordinario. |
| POST | `/expedients/{expedient}/access-grants/{grant}/close` | Cierra concesión. |
| POST | `/expedients/{expedient}/archive` | Archiva con motivo. |
| POST | `/expedients/{expedient}/close` | Cierra con motivo. |
| POST | `/expedients/{expedient}/void` | Anula con motivo. |
| GET/POST | `/expedients/{expedient}/reopening-requests` | Lista o solicita reapertura. |
| POST | `/expedients/{expedient}/reopening-requests/{request}/approve` | Aprueba reapertura. |
| POST | `/expedients/{expedient}/reopening-requests/{request}/reject` | Rechaza reapertura. |

## Documentos

| Método | Ruta | Uso |
|---|---|---|
| GET/POST | `/expedients/{expedient}/documents` | Lista o crea documento/derivación. |
| GET/PATCH | `/expedients/{expedient}/documents/{document}` | Consulta o edita borrador. |
| POST | `/expedients/{expedient}/documents/{document}/issue` | Emite e inmoviliza documento. |
| POST | `/expedients/{expedient}/documents/{document}/issue-and-derive` | La autoridad legislativa emite un borrador preparado y lo devuelve a SGEN en una sola transacción. |
| POST | `/expedients/{expedient}/documents/{document}/corrections` | Crea corrección/versionado. |
| POST | `/expedients/{expedient}/documents/{document}/attachments` | Adjunta binario. |
| GET | `/expedients/{expedient}/documents/{document}/attachments/{attachment}/download` | Descarga autorizada. |
| GET | `/expedients/{expedient}/documents/{document}/revisions` | Historial de contenido. |
| POST | `/expedients/{expedient}/documents/{document}/movement-links` | Vincula documento con movimiento. |
| GET/POST | `/legislatures/{legislature}/offices/{office}/document-sequence` | Consulta/configura correlativo de oficina. |

## Legislaturas

| Método | Ruta | Uso |
|---|---|---|
| GET/POST | `/legislatures` | Lista o crea período. |
| GET/PATCH | `/legislatures/{legislature}` | Consulta o corrige período. |
| POST | `/legislatures/{legislature}/activate` | Activa e inactiva la anterior. |
| POST | `/legislatures/{legislature}/inactivate` | Inactiva período. |
| POST | `/legislatures/{legislature}/board-assignments` | Reemplaza titular con fecha efectiva. |
| GET | `/legislatures/{legislature}/composition` | Consulta mandatos, órganos, membresías y delegaciones vigentes. |
| POST | `/legislatures/{legislature}/members` | Registra el mandato de un asambleísta. |
| POST | `/legislatures/{legislature}/members/{legislatureMember}/close` | Cierra mandato y membresías abiertas. |
| POST | `/legislatures/{legislature}/legislative-bodies` | Crea bancada, comisión o comisión mixta temporal. |
| POST | `/legislatures/{legislature}/legislative-bodies/{legislativeBody}/memberships` | Asigna cargo político histórico. |
| POST | `/legislatures/{legislature}/legislative-bodies/{legislativeBody}/memberships/{membership}/close` | Cierra una membresía política. |
| POST | `/legislatures/{legislature}/legislative-bodies/{legislativeBody}/delegations` | Registra sustitución temporal de autoridad. |
| GET | `/legislative/directory` | Directorio mínimo de destinatarios legislativos vigentes. |
| GET | `/legislative/plenary-referrals` | Lista de espera del Pleno autorizada. |
| POST | `/expedients/{expedient}/plenary-referrals` | SGEN incorpora un expediente al Pleno sin transferir la tenencia. |

El contexto seleccionado en **Actuar como** se transmite mediante `X-SIGAL-Acting-Body-Membership` o `X-SIGAL-Acting-Legislature-Member`. La API no acepta ambas cabeceras simultáneamente y valida su vigencia en cada petición.

## Administración extraordinaria

| Método | Ruta | Uso |
|---|---|---|
| GET | `/administration/operational-reset/summary` | Previsualiza registros afectados. |
| POST | `/administration/operational-reset` | Ejecuta limpieza beta confirmada. |

## Almacenes

| Método | Ruta | Uso |
|---|---|---|
| GET | `/warehouse/bootstrap` | Unidades, categorías, materiales y capacidades de interfaz. |
| POST/PATCH | `/warehouse/categories[/{warehouseCategory}]` | Crea o actualiza categorías. |
| POST/PATCH | `/warehouse/items[/{warehouseItem}]` | Crea o actualiza materiales. |
| GET | `/warehouse/items/{warehouseItem}/movements` | Consulta el kardex del material. |
| POST | `/warehouse/items/{warehouseItem}/adjustments` | Registra un ajuste justificado. |
| GET/POST | `/warehouse/receipts` | Lista o registra ingresos con factura/nota. |
| GET | `/warehouse/receipts/{warehouseReceipt}` | Consulta un ingreso y sus respaldos. |
| GET/POST | `/warehouse/material-requests` | Lista o prepara una solicitud documental. |
| GET/PATCH | `/warehouse/material-requests/{materialRequest}` | Consulta o edita un borrador. |
| POST | `/warehouse/material-requests/{materialRequest}/submit` | Presenta y activa el conducto regular. |
| POST | `/warehouse/material-requests/{materialRequest}/decisions` | Aprueba, observa o rechaza en la etapa vigente. |
| POST | `/warehouse/material-requests/{materialRequest}/revisions` | Corrige una solicitud observada y la reenvía. |
| POST | `/warehouse/material-requests/{materialRequest}/delivery` | Define la entrega o el cierre sin atención. |
| POST | `/warehouse/material-requests/{materialRequest}/receiver-authorization` | Autoriza un receptor alterno para esa entrega. |
| POST | `/warehouse/material-requests/{materialRequest}/confirm-receipt` | Confirma, genera el acta oficial y cierra. |
| GET | `/warehouse/material-requests/{materialRequest}/act` | Obtiene el acta digital autorizada. |

Para conocer reglas de cada payload, consultar el Form Request asociado en `app/Http/Requests` y sus pruebas. Ese código es la fuente exacta cuando este resumen no incluya un campo nuevo.
