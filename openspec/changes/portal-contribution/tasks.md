# Tasks: portal-contribution

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 12.
     Acceptance criteria are plain bullets, not checkboxes.
     Depends on: portal-identity (schema refs must exist first). -->

## Implementation Tasks

### Task 1: Ship the plain PortalContributionProvider class

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-provider-is-a-plain-dependency-free-class-req-pcon-001`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the new class WHEN inspected THEN it is namespace `OCA\Scholiq\Portal`, has NO `use` of any portaliq symbol, NO `implements` clause, NO constructor dependencies, and carries the repo-standard EUPL-1.2/SPDX docblock header plus `@spec` tags
  - GIVEN portaliq is absent WHEN the app runs THEN nothing references the class (no DI registration, no route) — it is inert
- [x] Implement
- [x] Test

### Task 2: Implement the v2+v1 audience contract

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-provider-declares-both-v2-and-v1-audience-methods-req-pcon-002`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the provider WHEN `getAudiences()` / `getAudience()` are called THEN they return `['student','parent']` / `'student'`, and `getAudience()` is a member of `getAudiences()`
  - GIVEN an unserved or audience-less subject WHEN `getContribution()` is called THEN it returns `null` (fail-closed)
- [x] Implement
- [x] Test

### Task 3: Build the student manifest (reads + inbox + creates)

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-student-manifest-scopes-by-learnerref-with-inbox-and-whitelisted-creates-req-pcon-003`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN a student subject WHEN `getContribution()` is called THEN label is `Scholiq`; six read collections (grade-entry, final-grade, attendance-record, enrolment, submission, excuse-request) each register `scholiq`, `scopeClaim` `learnerRef`, field-projected; submission scoped by `learnerRefs`, the rest by `learnerRef`
  - GIVEN the same manifest THEN a `grade-notification` collection has `kind: inbox` scoped by `learnerRef`; and actions `createSubmission` (fields `assignmentId`,`attachmentRefs`) and `createExcuseRequest` (fields `dateFrom`,`dateTo`,`reason`,`reasonKind`,`attachmentRef`, `minTrust` `low`) expose no grade/status/staff field
- [x] Implement
- [x] Test

### Task 4: Build the parent manifest (one-hop via join + substantial create)

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-parent-manifest-resolves-child-via-a-one-hop-join-req-pcon-004`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN a parent subject WHEN `getContribution()` is called THEN three read collections (grades, attendance, excuse-requests) each carry a `via` join `{schema: learner-profile, matchField: guardianRefs, targetField: learnerRef}`, `scopeClaim` `guardianRef`, `scopeField` `learnerRef`
  - GIVEN the parent action `createExcuseRequestForChild` THEN it is `type: create` on `excuse-request`, `scopeField` `submittedByRef`, `minTrust` `substantial`, and whitelists the child `learnerRef` but never `submittedBy`
- [x] Implement
- [x] Test

### Task 5: Unit-test the contract + register-drift pin

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-scoping-uses-portal-identity-uuid-refs-req-pcon-005`
- **files**: `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the test class WHEN it constructs the provider THEN it does so directly (`new`, no mocks/container), following existing `tests/Unit/` conventions
  - GIVEN the suite WHEN run via `vendor/bin/phpunit -c phpunit-unit.xml` (php 8.3 container) THEN it asserts audiences, fail-closed null, the full student + parent manifest shapes, AND a register-drift pin: every schema slug, scope field and whitelisted field the manifest references exists in `scholiq_register.json` (incl. `learnerRef`/`learnerRefs`/`submittedByRef`/`guardianRefs`) — and passes
- [x] Implement
- [x] Test

### Task 6: Register the capability spec and pass the gates

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md`
- **files**: `openspec/specs/portal-contribution/spec.md`, `openspec/changes/portal-contribution/*`
- **acceptance_criteria**:
  - GIVEN the declared capability WHEN the change is in flight THEN `openspec/specs/portal-contribution/spec.md` exists with status `in-progress` pointing at this change
  - GIVEN the repo gates WHEN run (php -l, phpcs, phpstan, unit suite via the php:8.3-cli container; `openspec validate portal-contribution`) THEN the new files pass with zero new violations
- [x] Implement
- [x] Test

## Quality checklist

- All new business logic covered by PHPUnit unit tests (`tests/Unit/Portal/`)
- No new API endpoints → no Newman collection; no UI change → no Playwright (portal renders in portaliq)
- All tests pass (`vendor/bin/phpunit -c phpunit-unit.xml` in the php 8.3 container)
- No user-facing strings added inside Scholiq (manifest labels are portal-side data; English source per i18n policy)
- `openspec validate portal-contribution` passes
