# School Year Rollover — Tenant-Scoped Lookups Delta

**Spec refs**: `school-year-rollover`, ADR-005 (security)

## MODIFIED Requirements

### Requirement: Preview MUST be side-effect free and produce a complete dry-run report

Running a preview MUST NOT create, mutate, or archive any Cohort, Enrolment, NC group, or DataExchangeJob
except the plan's own `dryRunReport`/`lifecycle` fields, and MUST produce a per-cohort report: learners to
promote/retain/graduate/outflow, cohorts to create, incomplete mandatory enrolments to carry over, and NC
groups to sync. The preview endpoint MUST resolve the caller's own tenant and MUST reject (404) a `planId`
whose `RolloverPlan.tenant_id` does not match the caller's tenant, before reading any cohort data or writing
`dryRunReport`/`lifecycle`. The mapping-proposal endpoint (`proposeMapping`) MUST scope its Cohort query to
the caller's own tenant, not merely to the requested `academicYear`.

#### Scenario: Dry run changes nothing

- **GIVEN** a draft plan mapping `2A → 3A` for 28 learners with 2 retain overrides
- **WHEN** the preview runs
- **THEN** the report shows 26 promotions, 2 retentions, 1 new cohort, and the enrolment carry-over count
- **AND** no Cohort, Enrolment, or NC group has been created or modified

#### Scenario: A plan belonging to another tenant cannot be previewed

- **GIVEN** `RolloverPlan` P belongs to tenant B
- **WHEN** a user authenticated as tenant A (holding the `rollover.plan` action grant) calls
  `preview(planId: P)`
- **THEN** the endpoint MUST return HTTP 404
- **AND** P's `dryRunReport` and `lifecycle` MUST remain unchanged

#### Scenario: Mapping proposals do not leak another tenant's cohorts

- **GIVEN** tenant A and tenant B each have a Cohort with `academicYear` `2025-2026`
- **WHEN** a user authenticated as tenant A calls `proposeMapping(fromAcademicYear: '2025-2026')`
- **THEN** the response MUST only include tenant A's cohorts
