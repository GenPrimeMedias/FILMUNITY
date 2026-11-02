# ADR-0001: Modular monolith and deployment-neutral multi-tenancy

- **Status:** Accepted as initial architecture
- **Date:** 2026-11-02

## Context

UNI360 must support multiple independent associations and unions in a centralised service and must also be deployable as a self-hosted installation. The project begins with a founder-led maintenance model, so operational complexity must remain manageable. Confidential member, grievance and financial records require strict isolation.

## Decision

Adopt a Laravel modular monolith with explicit domain modules and a deployment-neutral configuration model.

For centralised hosting, use a shared application and PostgreSQL database with tenant-scoped records, server-resolved tenant context, application authorisation, database constraints and optional PostgreSQL RLS after correct transaction-context design.

For self-hosting, deploy the same application release with an organisation-specific environment, database, storage, secrets and backup process. The codebase must not require a central control plane for core operations.

## Consequences

### Positive
- Lower operational overhead.
- Easier contribution and local development.
- Shared release and test process.
- Easier transactional workflows.
- Central and self-hosted modes share the same code.

### Trade-offs
- Tenant isolation must be rigorously implemented and tested.
- Shared database workloads may require later partitioning or dedicated databases.
- Module boundaries must be enforced by conventions and architecture tests.

## Revisit when

- a tenant requires dedicated infrastructure;
- performance tests show contention;
- independent scaling or compliance boundaries require service separation;
- multiple teams own separate domains.
