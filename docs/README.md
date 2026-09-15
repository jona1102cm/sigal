# Documentación de SIGAL

Este directorio es la fuente técnica de referencia del sistema. Describe el comportamiento que existe en el código actual; cuando una regla cambie, su implementación, pruebas y documentación deben actualizarse en el mismo commit.

## Ruta de lectura para un nuevo desarrollador

1. [Manual técnico](manual-tecnico.md): visión completa, flujo de una solicitud y módulos.
2. [Backend](reference/backend.md): capas PHP, responsabilidades y convenciones.
3. [Frontend](reference/frontend.md): aplicación Vue, estado Pinia y componentes.
4. [API REST](reference/api.md): seguridad y catálogo de endpoints.
5. [Base de datos](reference/base-de-datos.md): tablas, relaciones e invariantes.
6. [Pruebas automatizadas](reference/pruebas.md): suites, base aislada y cobertura funcional.
7. [Operación y soporte](operations/soporte.md): instalación, despliegue, tareas y diagnóstico.

## Arquitectura por dominio

- [Acceso, roles y relación con RR. HH.](architecture/access-and-hr-boundary.md)
- [Legislaturas y Directiva](architecture/legislatures.md)
- [Organización institucional](architecture/organization.md)
- [Recursos Humanos](architecture/human-resources.md)
- [Gestión documental](architecture/document-management.md)
- [Almacenes](architecture/warehouse.md)

## Documentación junto al código

El código utiliza PHPDoc/JSDoc y comentarios en español para explicar decisiones, invariantes, efectos laterales y operaciones que no son evidentes. No se comenta cada línea literal: una asignación como `$user->name = $name` ya expresa su mecánica y repetirla agrega ruido. Los nombres, tipos, DTO, enums y pruebas documentan la parte declarativa; los comentarios explican el **porqué**, las restricciones y los límites transaccionales.

Al modificar código:

- actualizar el comentario si cambia su supuesto;
- eliminar comentarios obsoletos;
- no describir una regla que el backend no imponga;
- vincular decisiones amplias desde esta documentación;
- escribir mensajes de error y comentarios en español claro, conservando los identificadores técnicos en inglés.

## Estado documental

La documentación cubre la versión beta actual. Los archivos `vendor/`, `node_modules/`, artefactos compilados y código del framework no se comentan porque no pertenecen al código fuente mantenido por SIGAL.
