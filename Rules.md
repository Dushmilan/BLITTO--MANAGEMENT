# BLITTO Patent Management — Development Rules

> **Stack note (2026):** This repo is reimplemented as **FastAPI + Python**
> (no DB, local in-memory adapters), per `Readme.md` and `Agent.md`. The
> Java 21 / Spring Boot / Gradle rules below are the *original* design intent
> for the deep-module architecture (7 modules + seams) and still govern module
> boundaries, seam pairing, and interface-level testing — but the language, build,
> and test tooling are now Python (pytest, not JUnit/ArchUnit).

## Architecture Enforcement
- Only 6 deep modules allowed: applicationIntake, documentVault, prosecution, authorization, notification, portfolioAnalytics
- No shallow wrappers — deletion test must pass for any new module
- Every seam has exactly 2 adapters (production + test) — enforce via ArchUnit test

## Code Style (Java 21 + Spring Boot 3)
- Domain vocabulary from CONTEXT.md — use "Application", "Deadline", "Document", "Inventor", "Attorney" — no "Entity", "DTO", "Service"
- Interface names: `Module` suffix (e.g., `DocketingModule`) — not `Service`, `Repository`
- Adapter names: `XxxAdapter` in `adapters/xxx/impl/` — production + test pair required
- No circular dependencies — enforce via ArchUnit module graph test

## Testing Rules
- Tests ONLY at module interface level — no unit tests on internal classes
- In-memory adapters for ALL seams (PGlite, InMemoryDocumentStore, MockEmailProvider, etc.)
- Delete old tests when module is deepened — shallow-wrapper tests become waste

## Git + CI Gates (required on every PR)
- `./gradlew check` passes (checkstyle + detekt + test)
- `./gradlew archTest` passes (ArchUnit rules)
- `prisma validate` passes (schema + migrations)
- ADR created for any new seam or module

## Build Scripts
- `./mvnw clean package` for Java 21 projects
- `./gradlew clean build` for Gradle projects
- `./gradlew test` for unit tests
- `./gradlew archTest` for architecture tests

## Documentation Standards
- CONTEXT.md always first reference for domain terminology
- ADRs in `docs/adr/` — one file per decision
- Interface definitions in module interfaces only
- Implementation hidden behind adapters

## Coding Standards
- JUnit5 for testing
- SpringBootTest + DataJpaTest for integration tests
- @DomainEvent annotation for domain events
- @CheckReturnValue for public interface methods
- Final where immutable, nullable where appropriate