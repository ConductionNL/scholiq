---
kind: config
---

# Proposal: portal-identity

## Summary

Add UUID **domain-object** scoping references to a first slice of six Scholiq
record schemas plus the identity anchor, so the ADR-046 portal
(`portal-contribution`, the next change in this chain) can scope external
portal subjects to their own records **without ever touching a Nextcloud user
id**. Every addition is **additive, optional, and non-destructive**: the
existing `learnerId` / `learnerIds` / `submittedBy` / `parentIds` Nextcloud-uid
properties stay exactly as they are (internal flows depend on them); each
schema simply gains a new `*Ref` UUID property alongside. Rows that leave the
new property unset are invisible to the portal (fail-closed). This is the head
of a two-change chain — `portal-contribution` (`kind: code`) depends on it and
is meaningless without these properties defined.

Tracking issue: Conduction/scholiq#39.

## Motivation

ADR-046 (portaliq, the one shared external portal for people **without**
Nextcloud accounts) amendment A4 is a hard rule: portal scoping properties MUST
be UUID references to **domain** objects — never Nextcloud user ids, because an
external subject has no Nextcloud account by premise. Scholiq today scopes its
learner records by `learnerId` (a plain-string Nextcloud user id — verified at
HEAD: `type: string`, no `format`, described as "Nextcloud user ID …") and
links parents by `LearnerProfile.parentIds` (Nextcloud user ids). Those are the
exact A4 anti-pattern. Before the portal provider can read a student's grades
or a parent's child's attendance, the schemas must expose a domain-object UUID
scope key. Doing it as a standalone `kind: config` change keeps the schema
delta reviewable on its own and lets the `kind: code` provider depend on a
merged, version-gated register.

## Affected Projects

- [x] Project: `scholiq` — additive `*Ref` UUID properties on eight schemas in
  `lib/Settings/scholiq_register.json` (register + touched-schema version
  bumps). No code, no frontend, no routes.

## Scope

### In Scope — the first portal slice (six record schemas + anchor + inbox)

- `GradeEntry` — `learnerRef` (uuid) alongside `learnerId`.
- `FinalGrade` — `learnerRef` (uuid) alongside `learnerId`.
- `AttendanceRecord` — `learnerRef` (uuid) alongside `learnerId`.
- `Enrolment` — `learnerRef` (uuid) alongside `learnerId`.
- `Submission` — `learnerRefs` (uuid[]) alongside `learnerIds` (array — the
  portal matches the subject's LearnerProfile UUID by membership).
- `ExcuseRequest` — `learnerRef` (uuid) alongside `learnerId`, **and**
  `submittedByRef` (uuid) alongside `submittedBy` (the parent-audience
  scope-stamp for create).
- `LearnerProfile` (identity anchor) — `guardianRefs` (uuid[]) alongside
  `parentIds`, so the provider can resolve parent → learner via a one-hop join.
- `GradeNotification` (inbox source) — `learnerRef` (uuid) alongside
  `learnerId`, so the provider can scope a learner inbox.
- Register `info.version` 0.2.0 → 0.3.0 and each touched schema version
  0.1.0 → 0.2.0 (the OpenRegister import is version-gated).

### Out of Scope

- The provider class, audiences, collections, actions and inbox — that is the
  `portal-contribution` change that depends on this one.
- **Backfilling** the new `*Ref` properties on existing objects — that is a
  documented follow-up (design.md), deliberately NOT in this change. Until a
  row carries the new ref it is invisible to the portal (fail-closed safe).
- Removing / renaming / repointing `learnerId`, `learnerIds`, `submittedBy`,
  `parentIds` — never; internal grading, attendance, notification and
  authorship flows depend on them.
- Any of the other ~30 Scholiq schemas — later portal slices.
- Any portaliq change, any `x-property-rbac` change.

## Approach

Additive remap per A4. For each schema in the slice, add one (or two) new
optional UUID property whose value is the UUID of a domain object
(`LearnerProfile` for the learner refs; the guardian domain object for
`guardianRefs` / `submittedByRef`), leaving the Nextcloud-uid property in place.
None of the new properties enters a `required` list, so every existing object
stays valid with the ref absent. The register and touched-schema versions bump
so OpenRegister's version-gated import (repair step →
`ConfigurationService::importFromApp()`) picks the properties up. Details,
seed-data convention, and the additive-remap rationale are in design.md.

## New Dependencies

None. This is a pure register-configuration change.

## Impact

- `lib/Settings/scholiq_register.json` — additive `*Ref` properties on eight
  schemas; register `info.version` 0.2.0 → 0.3.0; eight schema versions
  0.1.0 → 0.2.0. JSON validity verified mechanically (`python3 json.load`).
- No PHP, no Vue, no routes, no info.xml.

## Cross-Project Dependencies

At install time none. At runtime, once merged, the `portal-contribution`
provider (this repo, next change) reads these properties; portaliq — when
installed — scopes reads/creates by them.

## Risks

### Risk 1: A new ref shadows the Nextcloud-uid property or breaks an object

**Severity:** Low — **Mitigation:** every new property is additive and NOT in
any `required` list; the Nextcloud-uid properties are untouched. Existing
objects validate unchanged (ref absent = invisible to portal). JSON validity is
gate-checked.

### Risk 2: Version-gated import misses the new properties

**Severity:** Low — **Mitigation:** register `info.version` and every touched
schema version are bumped in the same edit; the import is version-gated on
exactly those fields.

## Rollback Strategy

Revert the `scholiq_register.json` edit. Because every addition is additive and
optional, no object data is lost — existing rows simply keep no `*Ref` value,
and the dependent `portal-contribution` provider (if already shipped) reads an
absent scope field and returns nothing (fail-closed).
