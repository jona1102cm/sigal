# Separación de beta y producción

## Decisión operativa

SIGAL mantiene dos instalaciones independientes:

| Entorno | Finalidad | Datos permitidos | Reinicio preoperativo |
| --- | --- | --- | --- |
| Beta/UAT | Pruebas funcionales con usuarios institucionales | Funcionarios reales y transacciones identificadas como prueba | Deshabilitado normalmente; TI puede habilitarlo temporalmente antes de existir operación válida |
| Producción | Operación institucional oficial | Solo datos validados y actuaciones reales | Imposible por configuración y por código |

No se crean botones permanentes para limpiar Gestión Documental o Almacenes por separado. Ambos módulos comparten expedientes, documentos, derivaciones, numeraciones, usuarios, archivos y auditoría; borrar solo una parte puede dejar referencias históricas incompletas. La beta completa se conserva como evidencia de pruebas y producción nace desde una línea base limpia.

## Línea base institucional vigente

El 9 de octubre de 2026 se fijó en el servidor beta:

```text
/opt/sigal/baselines/20261009-091519-linea-base-institucional
```

Contenido verificado:

- 88 cuentas de usuario;
- 86 funcionarios;
- 0 expedientes y 0 documentos;
- 0 solicitudes de materiales y 0 ingresos de almacén;
- dump PostgreSQL, almacenamiento de archivos, configuración protegida, manifiesto y sumas SHA-256.

Esta copia no entra en la retención rotativa de 30 días. Solo root puede leerla. No debe descargarse a equipos personales ni enviarse por correo porque contiene datos personales y secretos de recuperación del entorno beta.

## Uso de la beta

1. Probar Gestión Documental y Almacenes en la instalación actual.
2. Identificar asuntos, referencias, descripciones o archivos de prueba con `PRUEBA UAT`.
3. No ejecutar el reinicio preoperativo después de comenzar las pruebas con los 86 funcionarios cargados.
4. Registrar defectos con usuario, oficina, fecha/hora, acción y resultado, sin publicar contraseñas ni documentos sensibles.
5. Mantener los respaldos diarios y verificar periódicamente una restauración.

Los cambios posteriores de RR. HH. deben registrarse en una bitácora de corte. Antes del paso a producción se compararán contra la línea base para aplicar altas, bajas o correcciones legítimas sin copiar transacciones UAT.

## Configuración que bloquea el reinicio

Cada instalación declara un nivel independiente de `APP_ENV`:

```dotenv
SIGAL_DEPLOYMENT_TIER=beta
SIGAL_ENVIRONMENT_LABEL="BETA / UAT"
SIGAL_OPERATIONAL_RESET_ENABLED=false
SIGAL_UAT_MODE=true
SIGAL_UAT_LABEL="UAT · DATOS DE PRUEBA"
```

En producción es obligatorio:

```dotenv
SIGAL_DEPLOYMENT_TIER=production
SIGAL_ENVIRONMENT_LABEL="PRODUCCIÓN"
SIGAL_OPERATIONAL_RESET_ENABLED=false
SIGAL_UAT_MODE=false
```

El backend solo acepta el reinicio cuando la bandera está activa **y** el nivel es `local`, `testing` o `beta`. Por tanto, colocar accidentalmente la bandera en `true` con nivel `production` no habilita la operación. La interfaz tampoco muestra la opción cuando está bloqueada.

## Identificación automática de pruebas UAT

La beta muestra una franja permanente y marca automáticamente los nuevos expedientes, documentos, solicitudes de materiales, ingresos de almacén y movimientos de kardex. `expedients.is_uat`, `warehouse_receipts.is_uat` y `warehouse_stock_movements.is_uat` persisten la procedencia; documentos y solicitudes la heredan de su expediente para evitar duplicación.

Las actas digitales generadas en beta incluyen aviso dentro del contenido y marca de agua al imprimir. Los archivos externos adjuntados por el usuario conservan sus bytes originales y su hash: SIGAL los identifica como parte de un expediente UAT, pero no modifica el archivo binario porque hacerlo rompería su integridad.

`SIGAL_UAT_MODE=true` queda además neutralizado cuando `SIGAL_DEPLOYMENT_TIER=production`. Los datos maestros de funcionarios, contratos, oficinas, cargos y catálogos no son pruebas y no reciben esta marca.

## Respaldo coordinado

El script versionado `ops/backup.sh`:

1. pone Laravel en mantenimiento por una ventana breve;
2. genera un dump PostgreSQL en formato custom;
3. archiva el volumen `storage` con los adjuntos;
4. conserva una copia protegida del entorno para recuperación;
5. escribe conteos no sensibles y la versión en un manifiesto;
6. valida dump, archivo y sumas SHA-256 antes de publicar el respaldo;
7. vuelve a habilitar SIGAL incluso si ocurre un error;
8. elimina respaldos rotativos vencidos, pero nunca las líneas base externas.

Ejecución manual:

```bash
sudo SIGAL_BACKUP_LABEL=antes-de-version-x /opt/sigal/ops/backup.sh
```

Verificación mediante una restauración real en una base temporal:

```bash
sudo /opt/sigal/ops/verify-restore.sh /opt/sigal/backups/AAAAMMDD-HHMMSS-etiqueta
```

El segundo comando no altera la base activa: crea una base efímera, restaura, comprueba tablas esenciales y la elimina al salir.

## Preparación de producción

La plantilla `.env.production.example` y `docker-compose.production.yml` crean volúmenes, red, base y almacenamiento independientes bajo el proyecto Docker `sigal-production`. Nunca se deben apuntar ambos entornos al mismo volumen o base.

Antes de iniciar producción se requiere confirmar:

- nombre DNS definitivo, recomendado `sigal`;
- dirección IP dedicada o ventana de corte de beta, porque una misma IP no puede publicar dos servicios simultáneamente en 80/443;
- certificado institucional cuyo SAN incluya el DNS y la IP definitivos;
- fecha y responsables del corte;
- ubicación de una segunda copia cifrada fuera del servidor;
- resultados de aceptación de permisos y flujos críticos.

Preparación del directorio, todavía sin publicar:

```bash
sudo install -d -o sistemas -g sistemas /opt/sigal-production
sudo install -d -m 750 -o root -g sistemas /opt/sigal-production/certificates-production
cp .env.production.example .env.production
chmod 600 .env.production
```

Se generan secretos nuevos para producción. No se copia `sigal.env` desde el respaldo beta como configuración activa. La `APP_KEY`, contraseña PostgreSQL y certificados de producción son exclusivos.

## Inicialización desde la línea base

Durante la ventana de corte, TI debe:

1. crear un último respaldo beta y congelar cambios de RR. HH.;
2. construir la versión aprobada en `/opt/sigal-production`;
3. iniciar solo PostgreSQL y restaurar `database.dump` de la línea base;
4. restaurar `storage.tar.gz` en el volumen productivo;
5. eliminar únicamente sesiones, tokens, caché y colas heredadas de beta, conservando `activity_logs`;
6. ejecutar migraciones pendientes con `--force`;
7. aplicar y auditar cambios válidos de RR. HH. posteriores a la línea base;
8. comprobar que expedientes, documentos, solicitudes e ingresos siguen en cero;
9. probar login, cambio obligatorio de contraseña, permisos, kardex y descarga de un adjunto;
10. publicar DNS/TLS y abrir el acceso institucional.

La restauración productiva es deliberadamente un procedimiento supervisado y no un botón web. Antes de ejecutarlo se debe tomar una copia del destino, incluso si se considera vacío.

## Permanencia y retiro de beta

Después del arranque oficial:

- beta debe mostrar una identificación visual inequívoca y permanecer separada;
- los usuarios no deben registrar trámites oficiales en beta;
- se conserva en solo consulta durante el período institucional acordado;
- su retiro exige respaldo final verificado y acta de cierre;
- producción continúa con respaldos diarios y pruebas de restauración programadas.
