# portal-identity Specification

**Status**: in-progress
**Scope**: scholiq
**OpenSpec changes**:
- `openspec/changes/portal-identity/`

## Purpose

Make a first slice of Scholiq's record schemas ready for the ADR-046 external
portal by giving each a UUID **domain-object** scope reference, satisfying
amendment A4 (portal scoping MUST use domain UUIDs, never Nextcloud user ids).
Every reference is additive and optional; the existing Nextcloud-uid properties
are preserved for internal flows, and rows without the new reference are
invisible to the portal (fail-closed). This capability is the head of the
portal chain — `portal-contribution` depends on it.

## ADDED Requirements

### Requirement: Learner-scoped record schemas expose a UUID domain ref (REQ-PID-001)

The learner-scoped record schemas MUST each expose a UUID domain-object scope
reference alongside their existing Nextcloud-uid property (ADR-046 A4).
Specifically, `GradeEntry`, `FinalGrade`, `AttendanceRecord` and `Enrolment` in
`lib/Settings/scholiq_register.json` each define a `learnerRef` property
(`type: string`, `format: uuid`, title "Learner Ref") whose value is the UUID
of the learner's `LearnerProfile` object — the portal-subject scope key,
distinct from the Nextcloud-uid `learnerId`. `Submission` defines a
`learnerRefs` array (items `format: uuid`, title "Learner Refs") alongside its
Nextcloud-uid `learnerIds`. The refs are additive: the Nextcloud-uid properties
stay unchanged and no new ref appears in a `required` list, so every existing
object stays valid with the ref absent.

#### Scenario: Learner-scoped schemas carry the UUID scope ref

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN `GradeEntry`, `FinalGrade`, `AttendanceRecord` and `Enrolment` each define `learnerRef` with `type` `string` and `format` `uuid`
- AND `Submission` defines `learnerRefs` as an array whose items have `format` `uuid`
- AND each schema still defines its original `learnerId` / `learnerIds` property and lists neither new ref as required
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate (`python3 json.load`) and the provider register-drift-pin PHPUnit test (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Parent linkage and submitter use UUID domain refs (REQ-PID-002)

`LearnerProfile` MUST define a `guardianRefs` array (items `format: uuid`,
title "Guardian Refs") alongside the unchanged Nextcloud-uid `parentIds`, so
the portal can resolve a parent subject to that parent's learner(s) via a
one-hop join. `ExcuseRequest` MUST define both `learnerRef` (uuid) and
`submittedByRef` (uuid) alongside the unchanged `learnerId` and `submittedBy`,
so a parent-audience create can be scope-stamped by the guardian domain UUID
without touching a Nextcloud user id. Neither new ref may be `required`.

#### Scenario: Guardian and submitter refs are present and additive

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN `LearnerProfile` defines `guardianRefs` (array of uuid items) and still defines `parentIds`
- AND `ExcuseRequest` defines `learnerRef` (uuid) and `submittedByRef` (uuid) and still defines `learnerId` and `submittedBy`
- AND none of `guardianRefs`, `learnerRef`, `submittedByRef` is listed as required
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate and the provider register-drift-pin PHPUnit test

### Requirement: Versions bump for the version-gated import (REQ-PID-003)

Because OpenRegister's import is version-gated, the register `info.version` MUST
be bumped from `0.2.0` to `0.3.0` and every touched schema version
(`GradeEntry`, `FinalGrade`, `AttendanceRecord`, `Enrolment`, `Submission`,
`ExcuseRequest`, `LearnerProfile`, `GradeNotification`) MUST be bumped from
`0.1.0` to `0.2.0` in the same change. `GradeNotification` MUST also define
`learnerRef` (uuid, title "Learner Ref") alongside its `learnerId` and
`recipient`, so the portal can scope a learner inbox. The register MUST remain
valid JSON.

#### Scenario: Register and schema versions are bumped and the file is valid

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN `info.version` is `0.3.0` and each of the eight touched schemas has version `0.2.0`
- AND `GradeNotification` defines `learnerRef` with `format` `uuid`
- AND the file loads without error via `python3 -c "import json; json.load(...)"`
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate (`python3 json.load`) run in CI

## Non-Functional Requirements

- **Performance:** N/A — a schema-configuration addition; no runtime path added.
- **Accessibility:** N/A — no UI; the rendering surface is portaliq's SPA.
- **Internationalization:** property titles are English source per fleet i18n
  policy; portaliq owns portal-side translation of any contributed labels.

## Acceptance Criteria

- `scholiq_register.json` is valid JSON with the register and eight schema
  versions bumped.
- Every new `*Ref` property carries a `title` (gate-28) and `format: uuid` (on
  the item for arrays).
- No Nextcloud-uid property is removed, renamed, or made non-required.

## Notes

- Backfilling existing rows with the new refs is a documented follow-up
  (design.md), NOT part of this change — unset refs are fail-closed.
- Related: hydra ADR-046 (+ amendment A4), ADR-024 (declarative app manifest),
  ADR-031 (declarative config), ADR-005 (security — server-derived scope).
- Tracking issue: Conduction/scholiq#39.
