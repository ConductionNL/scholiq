---
capability: portal-identity
status: in-progress
built_by: openspec/changes/portal-identity
---

# portal-identity Specification

**Status**: in-progress
**Scope**: scholiq
**OpenSpec changes**:
- [portal-identity](../../changes/portal-identity/) _(active)_ — additive UUID domain-object scope refs (`learnerRef` / `learnerRefs` / `submittedByRef` / `guardianRefs`) on a first slice of eight schemas (kind: config)

## Purpose

Scholiq's record schemas carry UUID **domain-object** scope references
(`learnerRef` / `learnerRefs` / `submittedByRef` / `guardianRefs`) alongside
their existing Nextcloud-uid properties, so the ADR-046 external portal can
scope portal subjects to their own records without ever touching a Nextcloud
user id (amendment A4). The references are additive, optional, and fail-closed.
This capability is the head of the portal chain — `portal-contribution` depends
on it.

## Requirements

Detailed requirements (REQ-PID-001 … REQ-PID-003) are defined in the active
change's delta spec —
[`openspec/changes/portal-identity/specs/portal-identity/spec.md`](../../changes/portal-identity/specs/portal-identity/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
umbrella requirement below anchors the capability until then.

### Requirement: Scholiq exposes ADR-046 domain-UUID portal scope refs (REQ-PID-000)

The first portal slice MUST scope every portal-exposed record by a UUID
domain-object reference, never a Nextcloud user id (ADR-046 A4). Each schema in
the slice (`GradeEntry`, `FinalGrade`, `AttendanceRecord`, `Enrolment`,
`Submission`, `ExcuseRequest`, `LearnerProfile`, `GradeNotification`) carries a
new `*Ref` UUID property alongside — never replacing — its existing
Nextcloud-uid property, additive and optional so existing objects stay valid
and unset refs are fail-closed (invisible to the portal).

#### Scenario: Every slice schema carries an additive UUID scope ref

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN each schema in the first portal slice defines a `*Ref` property with `format` `uuid` (on the item for arrays)
- AND its original Nextcloud-uid property is still present and no new ref is `required`
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate (`python3 json.load`) and the provider register-drift-pin PHPUnit test (tests/Unit/Portal/PortalContributionProviderTest.php)
