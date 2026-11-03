# FILMUNITY

**A unified, open-source digital infrastructure for film industry associations, federations, councils and workers' unions across South India.**

FILMUNITY is designed as a multi-tenant association and union management platform. Each participating organisation operates independently while sharing a common, maintainable software foundation.

## Project principles

- Open-source application code under Apache License 2.0.
- Organisation-level data isolation and independent administration.
- Support for centrally hosted and self-hosted deployments.
- Modular monolith first; services are separated only when justified by operational evidence.
- Privacy, security, accessibility, auditability and localisation by design.
- No dependency on, code reuse from, or modification of the separate proprietary OTT platform.

## Planned technology baseline

- Laravel 13 / PHP 8.3+
- PostgreSQL
- React + TypeScript + Inertia.js
- Tailwind CSS
- Redis for queues/cache where operationally justified
- Pest or PHPUnit for backend tests; Playwright for browser-level tests
- Docker for reproducible local and self-hosted deployment
- GitHub Actions for continuous integration

Confirm framework, PHP, Node and package versions against official release documentation at implementation time. Pin versions in `composer.lock`, the JavaScript lockfile and container images.

## Status

Pre-implementation foundation. The first milestone is repository creation, CI, Laravel bootstrap and documented architecture. Do not represent the project as production-ready until security, tenancy, backup/restore and operational acceptance tests pass.

## Documentation

- [Master project blueprint](docs/MASTER_PROJECT_BLUEPRINT.md)
- [Database architecture](docs/database/DATABASE_ARCHITECTURE.md)
- [Development standards](docs/DEVELOPMENT_STANDARDS.md)
- [Laravel project structure](docs/architecture/LARAVEL_PROJECT_STRUCTURE.md)
- [Implementation runbook](docs/IMPLEMENTATION_RUNBOOK.md)
- [Architecture decisions](docs/architecture/ADR-0001-modular-monolith-and-tenancy.md)
- [Security policy](SECURITY.md)
- [Contribution guide](CONTRIBUTING.md)
- [Governance](GOVERNANCE.md)

## Licence

Apache License 2.0. See [LICENSE](LICENSE).

## Important separation boundary

FILMUNITY is a separate codebase, repository, deployment, database, credentials set and infrastructure boundary from the founder's proprietary OTT product. Do not copy OTT source code, secrets, production data, customer records, media assets, database dumps or proprietary business logic into this repository.
