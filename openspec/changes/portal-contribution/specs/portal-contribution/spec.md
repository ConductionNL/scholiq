# portal-contribution Specification

**Status**: in-progress
**Scope**: scholiq
**Depends on**: `portal-identity`
**OpenSpec changes**:
- `openspec/changes/portal-contribution/`

## Purpose

Scholiq contributes a `student` and a `parent` section to portaliq, the shared
external portal for people without Nextcloud accounts (hydra ADR-046 + contract
v2). The contribution is one plain, dependency-free provider class that declares
the OpenRegister collections a portal subject may read (field-projected), the
whitelisted create-actions, and a learner inbox — all scoped by the UUID
domain-object refs from `portal-identity`, never a Nextcloud user id (A4).

## ADDED Requirements

### Requirement: Provider is a plain, dependency-free class (REQ-PCON-001)

The app MUST ship `OCA\Scholiq\Portal\PortalContributionProvider` as a plain PHP
class: no imports from portaliq, no `implements` clause, no `info.xml`
dependency on portaliq, and no constructor dependencies. Portaliq discovers it
by convention FQCN and duck-types it via `method_exists` (never `instanceof`),
so without portaliq installed the class MUST be inert and MUST NOT change any
app behaviour (ADR-046 amendment A1).

#### Scenario: Provider constructs standalone

- GIVEN a PHP runtime where portaliq is not installed and no portaliq class is autoloadable
- WHEN `new PortalContributionProvider()` is called
- THEN the class instantiates without error
- AND it declares no `implements` clause, no parent, no constructor, and no `use` of any portaliq symbol
- @e2e exclude backend-only contract class with no Scholiq UI surface; the portal renders inside portaliq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Provider declares both v2 and v1 audience methods (REQ-PCON-002)

The provider MUST implement `getAudiences(): array` returning
`['student','parent']` (contract v2, preferred by the registry) AND
`getAudience(): string` returning `'student'` (v1 fallback), so it works against
both registry generations (A2). `getContribution(array $subject): ?array` MUST
return `null` for any audience other than `student` or `parent` (fail-closed).

#### Scenario: Audience methods agree and unserved audiences get null

- GIVEN a constructed provider
- WHEN `getAudiences()` and `getAudience()` are called
- THEN `getAudiences()` returns exactly `['student','parent']` and `getAudience()` returns `'student'`, which is a member of `getAudiences()`
- AND `getContribution()` returns `null` for a `teacher` subject and for an empty subject
- @e2e exclude backend-only contract methods with no Scholiq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Student manifest scopes by learnerRef with inbox and whitelisted creates (REQ-PCON-003)

For a `student` subject `getContribution()` MUST return a manifest labelled
`Scholiq` with six field-projected read collections — `grade-entry`,
`final-grade`, `attendance-record`, `enrolment`, `submission`, `excuse-request`
— each in register `scholiq` with `scopeClaim` `learnerRef`, scoped by
`learnerRef` (or `learnerRefs` for `submission`); a `grade-notification`
collection with `kind: inbox` scoped by `learnerRef`; and two create-actions,
`createSubmission` (fields `assignmentId`, `attachmentRefs`) and
`createExcuseRequest` (fields `dateFrom`, `dateTo`, `reason`, `reasonKind`,
`attachmentRef`, `minTrust` `low`). No grade, status, lifecycle or staff field
may be exposed on read projection or accepted on create.

#### Scenario: Student subject receives the scoped, projected manifest

- GIVEN a subject whose `audience` is `student` and `subjectRef` is the student's LearnerProfile object UUID
- WHEN `getContribution($subject)` is called
- THEN the manifest has label `Scholiq`, six learner-scoped read collections (submission by `learnerRefs`, the rest by `learnerRef`) and a `grade-notification` `kind: inbox` collection
- AND `createSubmission` whitelists exactly `assignmentId`,`attachmentRefs` and `createExcuseRequest` whitelists exactly `dateFrom`,`dateTo`,`reason`,`reasonKind`,`attachmentRef` — neither exposes `value`, `passed`, `lifecycle`, `submittedBy`, `submittedAuthLevel` or `decidedBy`
- @e2e exclude manifest is consumed and rendered by portaliq, not by any Scholiq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Parent manifest resolves child via a one-hop join (REQ-PCON-004)

For a `parent` subject `getContribution()` MUST return a manifest whose three
read collections (`grade-entry`, `attendance-record`, `excuse-request`) each
carry a one-hop `via` join `{register: scholiq, schema: learner-profile,
matchField: guardianRefs, selectField: id, targetField: learnerRef}` with
`scopeClaim` `guardianRef` and `scopeField` `learnerRef`, and whose single
create-action `createExcuseRequestForChild` is `type: create` on
`excuse-request`, scope-stamped by `submittedByRef`, at `minTrust` `substantial`,
whitelisting the child `learnerRef` but never `submittedBy`.

#### Scenario: Parent subject receives via-joined reads and a substantial create

- GIVEN a subject whose `audience` is `parent` and `subjectRef` is a guardian domain object UUID
- WHEN `getContribution($subject)` is called
- THEN each read collection carries a `via` join whose `schema` is `learner-profile`, `matchField` is `guardianRefs` and `targetField` is `learnerRef`, claimed by `guardianRef`
- AND `createExcuseRequestForChild` has `scopeField` `submittedByRef`, `minTrust` `substantial`, includes `learnerRef` in its whitelist and excludes `submittedBy`
- @e2e exclude parent resolution is executed by portaliq's reader/writer, not by any Scholiq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Scoping uses portal-identity UUID refs (REQ-PCON-005)

The manifest MUST reference only scope fields, `via` match-fields and
whitelisted fields that exist in `lib/Settings/scholiq_register.json` — the
UUID domain refs added by the `portal-identity` change (`learnerRef`,
`learnerRefs`, `submittedByRef`, `guardianRefs`) and never a Nextcloud user id
(A4). A unit register-drift pin MUST fail if any referenced schema slug or
property is renamed or missing, so scoping can never silently break at runtime.

#### Scenario: Manifest references only properties present in the register

- GIVEN the shipped `scholiq_register.json` and both audience manifests
- WHEN every collection/action schema slug, `scopeField`, `via.matchField` and whitelisted field is checked against the register
- THEN each resolves to a real schema slug and a real property on that schema
- AND `grade-entry` defines `learnerRef`, `submission` defines `learnerRefs`, `excuse-request` defines `submittedByRef`, and `learner-profile` defines `guardianRefs`
- @e2e exclude register-vs-manifest consistency is a backend invariant with no UI surface — covered by the register-drift-pin PHPUnit test (tests/Unit/Portal/PortalContributionProviderTest.php)

## Non-Functional Requirements

- **Performance:** `getContribution()` is pure data assembly — no I/O, no
  container access; sub-millisecond by construction.
- **Accessibility:** N/A in Scholiq — the rendering surface is portaliq's SPA
  (ADR-046), which owns WCAG compliance.
- **Internationalization:** manifest labels ship in English source per fleet
  i18n policy; portaliq owns portal-side translation of contributed labels.

## Acceptance Criteria

- Unit suite proves: audiences, fail-closed null, full student + parent manifest
  shapes (scope fields, inbox, `via` joins, create whitelists, minTrust) and the
  register-drift pin.
- `php -l`, phpcs and phpstan pass on the new files.
- `openspec validate portal-contribution` passes.

## Notes

- The provider is deliberately NOT registered in `lib/AppInfo/Application.php` —
  discovery is by FQCN from portaliq's side.
- `via` one-hop join, `fields` read-projection, `minTrust` and `scopeClaim` are
  declared per fleet convention; portaliq honours the direct scope match + create
  whitelist today and the rest as its reader/projector matures (design.md).
- Depends on `portal-identity`; backfilling existing rows is a follow-up on
  Conduction/scholiq#39. Related: ADR-046 (+ A1–A6), ADR-022, ADR-005.
