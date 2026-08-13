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
| POST | `/human-resources/positions` | Crea cargo. |
| PATCH | `/human-resources/positions/{officePosition}` | Corrige cargo. |
| POST | `/human-resources/contracts/{contract}/extend` | Extiende fecha final. |
| POST | `/human-resources/contracts/{contract}/finish` | Finaliza contrato y efectos de acceso. |
| GET | `/human-resources/attachments/{attachment}/download` | Descarga autorizada. |

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

## Catálogos documentales

| Método | Ruta | Uso |
|---|---|---|
| GET/POST | `/expedient-types` | Lista o crea tipo de expediente. |
| PATCH | `/expedient-types/{expedientType}` | Corrige catálogo. |
| POST | `/expedient-types/{expedientType}/activate` | Activa opción. |
| POST | `/expedient-types/{expedientType}/inactivate` | Inactiva opción. |
| GET | `/document-types` | Tipos de documento. |
| GET | `/confidentiality-levels` | Niveles de acceso. |

## Expedientes y flujo

| Método | Ruta | Uso |
|---|---|---|
| GET | `/expedients` | Bandeja filtrada por alcance y estado. |
| POST | `/expedient-entries` | Alta normal con documento inicial y derivación. |
| POST | `/expedients` | Alta excepcional sin documento. |
| GET | `/expedients/{expedient}` | Detalle autorizado. |
| GET/POST | `/expedients/{expedient}/movements` | Historial o nueva derivación. |
| POST | `/expedients/{expedient}/movement-recipients/{recipient}/status` | Acción de una oficina destinataria. |
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

## Administración extraordinaria

| Método | Ruta | Uso |
|---|---|---|
| GET | `/administration/operational-reset/summary` | Previsualiza registros afectados. |
| POST | `/administration/operational-reset` | Ejecuta limpieza beta confirmada. |

Para conocer reglas de cada payload, consultar el Form Request asociado en `app/Http/Requests` y sus pruebas. Ese código es la fuente exacta cuando este resumen no incluya un campo nuevo.
