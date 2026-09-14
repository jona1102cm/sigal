# Pruebas automatizadas

SIGAL usa PHPUnit para la suite Laravel convencional y Pest para escenarios expresivos de dominio. Ambas configuraciones apuntan a PostgreSQL y a la base aislada `sigal_test`.

## Comandos

```bash
composer test
php artisan test
vendor/bin/phpunit
vendor/bin/pest --configuration=pest.xml
```

`composer test` limpia la caché de configuración y ejecuta ambas suites definidas por el proyecto. Para un escenario concreto:

```bash
vendor/bin/pest --configuration=pest.xml --filter="texto del escenario"
```

## Aislamiento obligatorio

- Confirmar `APP_ENV=testing`.
- Confirmar `DB_DATABASE=sigal_test`.
- No reutilizar credenciales/base de beta o producción.
- Las pruebas de integración migran y limpian datos; asumir que la base de pruebas es descartable.
- Archivos generados por pruebas deben usar el fake/disco temporal definido en el escenario.

## Cobertura funcional actual

- Acceso: roles históricos, inactivación, revocación y reset temporal.
- Autorización avanzada: matriz de permisos de roles fijos, protección del Superadministrador y altas de RR. HH. limitadas a Usuario simple.
- Observación: oficinas raíz, dependencias, retiro con consulta histórica y bloqueo de confidenciales sin concesión explícita.
- Distribución interna: exclusividad del responsable por llegada, colaboradores de lectura, equipo permanente y oficinas sin jefatura.
- Autorización: matriz de permisos de roles fijos, alcance jerárquico/histórico del observador y excepción confidencial mediante concesión expresa.
- Legislaturas: exclusividad activa, Directiva y restricciones PostgreSQL.
- Organización: árbol, membresías y reglas del organigrama sembrado.
- RR. HH.: alta coordinada, reutilización, cargos responsables, cierre programado, reset e importación XLSX.
- Catálogos/numeración: configuración y correlativos consecutivos.
- Entrada documental: documento inicial, adjuntos, oficina derivada y resumen opcional.
- Flujo: tenencia, devolución, destinatarios múltiples, finalización individual, informativos sin respuesta y distribución interna por responsable único/equipo/oficina sin jefatura.
- Documentos: revisiones, emisión inmutable y correcciones con número conservado.
- Ciclo de vida: estados agregados, archivo/cierre/anulación y reapertura.
- Seguridad de contenido: sanitización del editor enriquecido.

## Cómo escribir una prueba de dominio

1. Preparar solo los catálogos, usuarios, oficinas y vigencias indispensables.
2. Ejecutar el caso de uso por API cuando se quiera cubrir Request, Policy y Resource; ejecutar el Service directamente para una unidad de dominio aislada.
3. Afirmar respuesta y estado persistido.
4. Afirmar historial/auditoría cuando la acción sea sensible.
5. Probar el rechazo relevante, no solo el camino feliz.
6. En reglas concurrentes, verificar también la restricción de PostgreSQL o el bloqueo que impide duplicidad.

Una corrección de un incidente debe incluir una prueba que falle con el comportamiento anterior. Así el problema no reaparece de forma silenciosa.
