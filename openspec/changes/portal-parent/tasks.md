# Tasks: portal-parent

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 12.
     Follow-up to portal-contribution; depends on portaliq's merged reverse join. -->

## Implementation Tasks

### Task 1: Re-enable the parent audience

- **spec_ref**: `openspec/changes/portal-parent/specs/portal-contribution/spec.md#requirement-parent-manifest-resolves-child-via-a-reverse-scope-value-join-req-pcon-004`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the provider WHEN `getAudiences()` is called THEN it returns `['student','parent']` and `getAudience()` stays `'student'`
  - GIVEN a `parent` subject WHEN `getContribution()` is called THEN it returns a manifest (not null); a `teacher`/empty subject still returns null
- [x] Implement
- [x] Test

### Task 2: Ship parentContribution() with the reverse via join

- **spec_ref**: `openspec/changes/portal-parent/specs/portal-contribution/spec.md#requirement-parent-manifest-resolves-child-via-a-reverse-scope-value-join-req-pcon-004`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the parent manifest THEN it has three read collections (`parentGrades`,`parentAttendance`,`parentExcuseRequests`), each `scopeClaim` `guardianRef`, `scopeField` `learnerRef`, `minTrust` `substantial`, field-projected identically to the student surface
  - GIVEN each read collection's `via` THEN its keys are EXACTLY `{register, schema, scopeField, targetField, match}` with `schema: learner-profile`, `scopeField: guardianRefs`, `targetField: id`, `match: 'scopeField'`
- [x] Implement
- [x] Test

### Task 3: Ship the substantial guardian-stamped create action

- **spec_ref**: `openspec/changes/portal-parent/specs/portal-contribution/spec.md#requirement-parent-manifest-resolves-child-via-a-reverse-scope-value-join-req-pcon-004`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the parent action `createExcuseRequestForChild` THEN it is `type: create` on `excuse-request`, `scopeField: submittedByRef`, `scopeClaim: guardianRef`, `minTrust: substantial`
  - GIVEN its whitelist THEN it includes the child `learnerRef` + absence-intake fields and excludes `submittedBy`/`submittedByRef`/`submittedAuthLevel`/`decidedBy`/`lifecycle`
- [x] Implement
- [x] Test

### Task 4: Re-add parent unit tests + fix the via drift-pin

- **spec_ref**: `openspec/changes/portal-parent/specs/portal-contribution/spec.md#requirement-scoping-uses-portal-identity-uuid-refs-and-a-verified-via-shape-req-pcon-005`
- **files**: `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the suite WHEN run via `vendor/bin/phpunit -c phpunit-unit.xml` (php 8.3 container) THEN it asserts the parent audience, manifest shape, the exact via key-set + `match: 'scopeField'`, and the substantial guardian-stamped create — and the student tests still pass
  - GIVEN the register-drift pin THEN it asserts each parent collection's `via.scopeField` (`guardianRefs`) exists on `learner-profile` and `via.targetField` is a property or the OR identity token — the invented `matchField` key is gone
- [x] Implement
- [x] Test

### Task 5: Remove the stale "parent deferred" narrative

- **spec_ref**: `openspec/changes/portal-parent/specs/portal-contribution/spec.md#requirement-parent-manifest-resolves-child-via-a-reverse-scope-value-join-req-pcon-004`
- **files**: `lib/Portal/PortalContributionProvider.php`, `openspec/changes/portal-contribution/proposal.md`, `openspec/changes/portal-contribution/design.md`
- **acceptance_criteria**:
  - GIVEN the provider's `getContribution()` THEN the "parent deferred" comment is gone, replaced by a `parent` branch
  - GIVEN `portal-contribution`'s proposal/design THEN the deferral scope-note and the "Parent audience — DEFERRED" section describe the SHIPPED reverse join (`match: 'scopeField'`), not the deferral
- [x] Implement
- [x] Test

### Task 6: Validate the change and pass the gates

- **spec_ref**: `openspec/changes/portal-parent/specs/portal-contribution/spec.md`
- **files**: `openspec/changes/portal-parent/*`, `openspec/specs/portal-contribution/spec.md`
- **acceptance_criteria**:
  - GIVEN the repo gates WHEN run (php -l, phpcs, phpstan, psalm, unit suite via the php:8.3-cli container) THEN the changed files pass with zero new violations
  - GIVEN the change WHEN `openspec validate portal-parent --type change --strict` runs THEN it passes; the capability spec stays `in-progress`
- [x] Implement
- [x] Test

## Quality checklist

- All new logic covered by PHPUnit unit tests (`tests/Unit/Portal/`)
- No new API endpoints → no Newman; no UI change → no Playwright (portal renders in portaliq)
- All tests pass (`vendor/bin/phpunit -c phpunit-unit.xml` in the php 8.3 container)
- No schema/register change (the refs already exist in `portal-identity`)
- No user-facing Scholiq strings added (manifest labels are portal-side data, English source)
- `openspec validate portal-parent` passes
