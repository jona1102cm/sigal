# SIGAL

SIGAL es el Sistema Integral de Gestión de la Asamblea Legislativa Departamental del Beni. Esta versión beta reúne administración institucional, Recursos Humanos y gestión documental sobre una arquitectura preparada para crecer como ERP.

## Funcionalidad disponible

- Autenticación con Laravel Sanctum, cambio obligatorio de contraseña y revocación de sesiones.
- Usuarios, roles históricos y recuperación administrativa de acceso.
- Organigrama jerárquico, oficinas, cargos, responsables y membresías históricas.
- Kardex de funcionarios, contratos, respaldos, fotografía e importación masiva desde Excel.
- Legislaturas con una sola activa y composición histórica de la Directiva.
- Expedientes, documentos, adjuntos, derivaciones múltiples, prioridades, bandejas y ciclo de vida.
- Auditoría de operaciones sensibles con actor, fecha, IP, navegador y cambios realizados.
- Reinicio operativo controlado para limpiar datos de prueba sin destruir la configuración institucional básica.

## Tecnologías

- Backend: PHP 8.4, Laravel 13, Sanctum y PostgreSQL 18.
- Frontend: Vue 3, Pinia, Tailwind CSS/Vite y componentes propios.
- Calidad: PHPUnit, Pest y Laravel Pint.
- Despliegue beta: Docker Compose, PHP-FPM y Nginx con TLS.

## Lectura recomendada

La documentación técnica completa comienza en [docs/README.md](docs/README.md). Para incorporarse al mantenimiento del proyecto, seguir este orden:

1. [Manual técnico y arquitectura](docs/manual-tecnico.md).
2. [Referencia del backend](docs/reference/backend.md).
3. [Referencia del frontend](docs/reference/frontend.md).
4. [API REST](docs/reference/api.md).
5. [Base de datos](docs/reference/base-de-datos.md).
6. [Pruebas automatizadas](docs/reference/pruebas.md).
7. [Operación y soporte](docs/operations/soporte.md).
8. Documentos de cada dominio en [docs/architecture](docs/architecture).

## Instalación local resumida

Requisitos: PHP compatible con el proyecto, Composer, Node.js, PostgreSQL y las extensiones PHP indicadas por Laravel.

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Configure primero la conexión PostgreSQL en `.env`. Nunca versionar ese archivo ni credenciales, certificados privados o datos reales de funcionarios.

Para desarrollo del frontend:

```bash
npm run dev
```

Para verificar el sistema:

```bash
php artisan test
npm run build
vendor/bin/pint --test
```

Las pruebas de integración usan la base aislada `sigal_test`; no deben ejecutarse apuntando a la base operativa.

## Reglas de contribución

- Las rutas reciben la solicitud, los Form Requests validan, las Policies autorizan y los Services ejecutan reglas y transacciones.
- No colocar reglas de negocio únicamente en Vue ni duplicarlas entre controladores.
- Los documentos emitidos son inmutables; una corrección genera una revisión.
- Los adjuntos se almacenan fuera de PostgreSQL; la base conserva metadatos y hash.
- No borrar historiales laborales, de roles, membresías, directivas, movimientos o auditoría.
- Todo cambio de esquema se realiza mediante una nueva migración.
- Una funcionalidad debe incluir pruebas proporcionales a su riesgo.

La guía normativa del repositorio se encuentra en `AGENTS.md`.
