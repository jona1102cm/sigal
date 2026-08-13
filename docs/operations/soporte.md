# Operación y soporte

## Entornos

- `local`: desarrollo, depuración habilitada, Vite y base local.
- `testing`: base `sigal_test`, nunca la operativa.
- `production`/beta: Docker Compose, depuración deshabilitada, Nginx/TLS y volúmenes persistentes.

No copiar `.env` entre entornos sin revisar claves, URL, base, logs, cookies y almacenamiento. `APP_KEY` debe conservarse mientras existan datos cifrados.

## Puesta en marcha local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan storage:link
php artisan serve
```

En Windows PowerShell, `cp` puede sustituirse por `Copy-Item`. Configurar primero `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`.

## Cuentas administrativas iniciales

Los comandos de bootstrap crean cuentas institucionales sin asociarlas a un funcionario:

```bash
php artisan sigal:bootstrap-superadministrator
php artisan sigal:bootstrap-human-resources-administrator
```

Usar `php artisan list` o `php artisan help <comando>` para confirmar el nombre/opciones disponibles en la versión desplegada. Entregar la clave temporal por un canal seguro y exigir cambio al primer ingreso. No colocar credenciales reales en `.env`, README, Git o capturas.

## Despliegue beta con Docker

El conjunto incluye:

- `database`: PostgreSQL 18 con volumen `database_data`.
- `app`: PHP-FPM/Laravel con volumen `application_storage`.
- `web`: Nginx, redirección HTTP a HTTPS y certificados montados como solo lectura.

Comandos habituales desde el servidor:

```bash
docker compose -f docker-compose.beta.yml build
docker compose -f docker-compose.beta.yml up -d
docker compose -f docker-compose.beta.yml ps
docker compose -f docker-compose.beta.yml logs --tail=200 app web database
```

Después de actualizar código:

```bash
git pull --ff-only
docker compose -f docker-compose.beta.yml build
docker compose -f docker-compose.beta.yml up -d
docker compose -f docker-compose.beta.yml exec app php artisan migrate --force
docker compose -f docker-compose.beta.yml exec app php artisan optimize:clear
```

Antes de una migración relevante, crear respaldo. Evitar `docker compose down -v`: la opción `-v` elimina volúmenes persistentes.

## TLS y DNS interno

Nginx expone 80/443 en `SIGAL_BIND_IP`. El certificado debe incluir la IP y/o `sigal-beta` como SAN. Cada equipo cliente debe confiar en la CA institucional; el instalador de `docs/deployment` automatiza esa confianza, incluida la de Firefox cuando corresponde.

El DNS interno debe resolver `sigal-beta` a la IP del servidor. Un certificado válido no crea el DNS: ambos componentes son independientes.

## Scheduler

`routes/console.php` programa:

- 00:05: activar contratos cuya vigencia comienza.
- 00:10: finalizar contratos vencidos.

El host/contenedor debe ejecutar el scheduler de Laravel continuamente o invocar `php artisan schedule:run` cada minuto. Diagnóstico:

```bash
php artisan schedule:list
php artisan human-resources:activate-starting-contracts
php artisan human-resources:expire-contracts
```

La ejecución manual muta datos reales; usarla solo cuando se haya verificado la fecha y el entorno.

## Logs y salud

- Endpoint de salud: `/up`.
- Laravel: `storage/logs/laravel.log` o salida configurada.
- Contenedores: `docker compose ... logs`.
- Nginx: logs del contenedor web.
- PostgreSQL: logs del contenedor database.

No copiar logs completos a canales inseguros: pueden contener CI, nombres, rutas, IP u otros datos personales. Redactar datos antes de compartir.

## Recuperación de contraseña

1. El superadministrador identifica la cuenta correcta.
2. Ejecuta “restablecimiento de emergencia”.
3. SIGAL revoca tokens y genera una contraseña temporal.
4. Se comunica una sola vez por canal seguro.
5. El usuario inicia sesión y queda obligado a cambiarla.

No existe una pantalla para ver contraseñas actuales porque se almacenan como hashes irreversibles. Implementarla exigiría guardar texto recuperable y comprometería todas las cuentas.

## Incidentes frecuentes

### La aplicación no abre

1. comprobar `/up`;
2. revisar `docker compose ps`;
3. revisar logs de `web` y `app`;
4. verificar bind IP/firewall;
5. verificar resolución DNS y certificado.

### Error 500

Revisar log Laravel, variables de entorno, conexión PostgreSQL, permisos/volumen de `storage` y migraciones pendientes. Mantener `APP_DEBUG=false` en beta accesible a usuarios.

### Usuario sin oficina responsable

Verificar en RR. HH. contrato activo, oficina, cargo y membresía vigente. Si el cargo debe representar jefatura, comprobar la función del cargo. Cerrar sesión e ingresar de nuevo solo después de corregir los datos; no editar directamente el token.

### Expediente asignado a oficina incorrecta

Consultar el último movimiento por `sent_at` e `id` y sus destinatarios primarios. El control no corresponde necesariamente a la oficina inicial. Revisar también si la participación fue finalizada o era informativa.

### Bandeja requiere recarga

Revisar respuesta de `/api/expedients`, errores en consola del navegador y mutaciones del store. La solución debe corregir sincronización/consulta, no instruir al usuario a recargar dos veces.

### Archivo no descarga

Confirmar autorización, registro de metadatos, disco configurado, existencia física, permisos del volumen y hash. No regenerar un registro apuntando a otro archivo sin auditarlo.

### Importación Excel rechazada

Descargar una plantilla nueva de la misma instancia, no renombrar encabezados, usar opciones de los selects y revisar errores por fila. Los campos opcionales pueden quedar vacíos. La oficina admite nombre completo o código por compatibilidad, pero la plantilla muestra nombres.

## Respaldo y restauración

Respaldar coordinadamente PostgreSQL y `application_storage`. Probar restauraciones periódicamente en un ambiente aislado. Un respaldo no probado es solo una expectativa.

Antes de restaurar:

1. detener escrituras;
2. conservar copia del estado actual;
3. restaurar base y archivos del mismo punto temporal;
4. revisar `.env`/`APP_KEY`;
5. ejecutar comprobaciones de salud;
6. validar login, kardex, un adjunto y un expediente;
7. reabrir acceso.

## Reinicio operativo beta

La opción administrativa muestra un resumen antes de ejecutar. Está destinada a limpiar pruebas conservando configuración básica, cargos y oficinas. No usar como mecanismo de retención ni mantenimiento periódico. En un entorno con datos válidos requiere autorización institucional y respaldo previo.

## Checklist de entrega de una versión

```bash
vendor/bin/pint --test
php artisan test
npm run build
php artisan route:list
```

Además:

- revisar migraciones y estrategia de rollback;
- verificar que no existan secretos en Git;
- actualizar documentación;
- respaldar datos/archivos;
- desplegar;
- comprobar `/up`, login, permisos y flujo crítico;
- registrar la versión/commit desplegado.
