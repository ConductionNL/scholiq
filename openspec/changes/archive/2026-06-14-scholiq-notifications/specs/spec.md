---
status: pr-created
---

# Scholiq Schema Notifications

## Purpose

Migrates the legacy `x-openregister-notifications` annotations in `lib/Settings/scholiq_register.json` to the verified OpenRegister notification-engine dialect (`trigger.type` / `channels[]` / `recipients[]` / inline `subject{nl,en}`), reworking the legacy expiry/days-remaining flags into engine-supported triggers. Configuration-only; no data-model or API change.

## ADDED Requirements

### Requirement: Annotated schemas MUST declare notifications in the verified engine dialect

Every `x-openregister-notifications` block in `scholiq_register.json` MUST use only verified keys: `trigger.type` (one of created|updated|transition|scheduled|threshold|calculatedChange), `channels[]`, `recipients[]` with `kind` of field|groups|object-acl, and inline `subject` with `nl` and `en` strings. Legacy keys (`channel`, `recipient`/`recipientField`/`recipientFromTenantRole`, `@self.`, `lifecycleEnter`, boolean `calculated`, `userPreferenceKey`, `idempotencyKey`, `alsoDispatchLifecycle`, `event`, `template`) MUST NOT remain.

#### Scenario: Legacy lifecycle-enter rule migrated to a transition trigger

- **GIVEN** the legacy `Credential.issuedToLearner` rule used `trigger.lifecycleEnter: "issued"` and `recipient: "@self.learnerId"`
- **WHEN** the rule is migrated
- **THEN** it uses `trigger.type: "transition"` with `action: "issue"`
- **AND** `recipients` is `[{"kind": "field", "field": "learnerId"}]`
- **AND** `subject` carries both `nl` and `en` strings

#### Scenario: Legacy boolean-expiry flag reworked into a numeric calculatedChange

- **GIVEN** the legacy `Credential.expiringSoonAlert` rule used `trigger.calculated: "isExpiringIn30Days", eq: true`
- **WHEN** the rule is migrated
- **THEN** it uses a numeric `trigger.type: "calculatedChange"` on a days-to-expiry field crossing a threshold
- **AND** no boolean `calculated` flag remains

### Requirement: Field recipients MUST resolve to Nextcloud user IDs and tenant roles MUST map to groups

Every `kind:field` recipient MUST reference a schema property holding a Nextcloud user ID (`learnerId`, `managerId`, `requestedBy`, `submittedBy`). Legacy `recipientFromTenantRole` values MUST become `kind:groups`.

#### Scenario: Tenant-role recipient becomes a groups recipient

- **GIVEN** the legacy `Regulation.onPublished` rule used `recipientFromTenantRole: "compliance-officer"`
- **WHEN** the rule is migrated
- **THEN** `recipients` is `[{"kind": "groups", "groups": ["compliance-officer"]}]`

### Requirement: Rules with no verified-dialect equivalent MUST be deferred, not left in the legacy dialect

Rules depending on engine features with no verified equivalent (non-numeric field change, expression conditions, per-role fan-out, `idempotencyKey`/`alsoDispatchLifecycle`) MUST be removed from the register and documented as deferred, because the engine silently ignores legacy-dialect blocks.

#### Scenario: Non-numeric field-change rule is held pending the engine change

- **GIVEN** the legacy `Regulation.officerAlertOnCoverageDrop` rule changed `ragStatus` to `red` (a string)
- **WHEN** the migration runs
- **THEN** the rule is removed from `scholiq_register.json`
- **AND** the proposal's Caveats record it as held pending `notification-updated-field-change-condition`
