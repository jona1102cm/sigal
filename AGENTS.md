# SIGAL Engineering Guide

SIGAL is the long-lived Enterprise ERP for the Asamblea Legislativa Departamental del Beni (Bolivia). Design for maintainability, security, auditability, normalized data, extensibility, and performance. Do not trade architecture for short-term implementation speed.

## Technology baseline

- Laravel 13, PHP 8.4, PostgreSQL, Vue 3, Pinia, Tailwind CSS, REST API, Laravel Sanctum, Docker, PHPUnit, Pest, and Git.
- Redis and queues will be introduced only when a concrete use case requires them.

## Application architecture

- Organize code under `app/Domain`, `Models`, `Services`, `Repositories`, `Policies`, `DTOs`, `Actions`, `Events`, `Listeners`, `Notifications`, and `Http` as each module requires it.
- Controllers remain thin. Form Requests validate input, Policies authorize, and Services hold business rules and transactions.
- Never duplicate domain logic or trust frontend authorization.
- Complete an entity through: business analysis, database design, migration, model, relationships, validation, policy, service, controller, tests, and frontend before starting the next one.

## Domain invariants

- The electronic case-management engine is the ERP core. Every module reuses the Expedient and workflow engine.
- A Document always belongs to one Expedient; no orphan documents exist.
- An Expedient is an administrative process, not a document.
- Documents are never physically moved. Movements are administrative actions and reference documents through a many-to-many pivot.
- Issued documents are immutable. Corrections create a new version and retain institutional numbering.
- Binary attachments are stored outside PostgreSQL; persist metadata and a content hash only.
- Audit every security-relevant and domain action with actor, timestamp, IP, browser, and event data. Do not erase audit history.

## Decision discipline

- Do not invent business rules, statuses, permissions, numbering formulas, or workflow transitions.
- When a business rule is unclear, record the open question and obtain confirmation before implementing it.
- Explain a material architectural improvement before implementing it.
