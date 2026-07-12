# Compliance Audit — Controller Test Coverage Delta

**Spec refs**: `compliance-audit`, ADR-008 (immutable audit trail)

## MODIFIED Requirements

### Requirement: Export audit-ready ZIP per regulation and date range
The system MUST export an audit-ready ZIP per regulation and date range. `AuditPackExportController::export()`
MUST have a controller-level automated test asserting the export is scoped to the caller's own `tenant_id`
across every underlying query it issues (audit trail, external-training, verwerkingsregister), and that a
date range with no matching entries produces a header-only ZIP rather than an error.

#### Scenario: Audit pack exported for a regulation
<!-- @e2e exclude ZIP-stream backend artefact (AuditPackExportController::export) gated by the ADR-023 audit-pack.export action; no DOM surface to drive the download stream. -->

- **GIVEN** a regulation slug and a date range
- **WHEN** an authorized officer requests the audit pack
- **THEN** a ZIP streams containing the audit trail (ndjson + csv), manifest, signature-verification report, verwerkingsregister, and external-training artefacts

#### Scenario: Audit pack export is tenant-scoped and covered by a controller-level test
<!-- @e2e exclude Controller-level PHPUnit test (AuditPackExportControllerTest), no scholiq DOM surface. -->

- **GIVEN** two tenants A and B each have audit-trail entries within the requested date range
- **WHEN** a user bound to tenant A calls `export()`
- **THEN** the produced ZIP contains only tenant A's entries
- **AND** this behaviour is asserted by a controller-level automated test, not only by reasoning about the code

## ADDED Requirements

### Requirement: Action-authorization matrix admin API MUST be covered by a controller-level test
Both `ActionMatrixController::getMatrix()` and `::setMatrix()` MUST have a controller-level automated test, since these endpoints gate every other controller's authorization decision in the app via `ActionAuthService::requireAction()`. The test MUST assert: the seeded default matrix is returned when no override exists; a valid write
round-trips; and a malformed write is rejected without corrupting the stored matrix.

#### Scenario: A malformed action-matrix write does not corrupt the stored matrix
<!-- @e2e exclude Admin-only backend API gated by AuthorizedAdminSetting; no scholiq DOM surface — covered by ActionMatrixControllerTest. -->

- **GIVEN** a previously-stored valid action-authorization matrix
- **WHEN** an admin calls `setMatrix()` with a payload missing a required action key
- **THEN** the endpoint returns a 4xx response
- **AND** the previously-stored matrix is unchanged
