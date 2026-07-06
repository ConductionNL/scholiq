# Tasks: portal-identity

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8.
     Acceptance criteria are plain bullets, not checkboxes. -->

## Implementation Tasks

### Task 1: Add learner UUID scope refs to the five learner-scoped record schemas

- **spec_ref**: `openspec/changes/portal-identity/specs/portal-identity/spec.md#requirement-learner-scoped-record-schemas-expose-a-uuid-domain-ref-req-pid-001`
- **files**: `lib/Settings/scholiq_register.json`
- **acceptance_criteria**:
  - GIVEN the shipped register WHEN parsed THEN `GradeEntry`, `FinalGrade`, `AttendanceRecord` and `Enrolment` each define `learnerRef` (`type: string`, `format: uuid`, title "Learner Ref", description marking it a LearnerProfile object UUID distinct from `learnerId`), and `Submission` defines `learnerRefs` (`type: array`, items `format: uuid`, title "Learner Refs")
  - GIVEN each touched schema WHEN compared to HEAD THEN the existing `learnerId` / `learnerIds` property is unchanged and the new ref is NOT in `required`
- [x] Implement
- [x] Test

### Task 2: Add the parent-linkage + submitter refs (LearnerProfile, ExcuseRequest)

- **spec_ref**: `openspec/changes/portal-identity/specs/portal-identity/spec.md#requirement-parent-linkage-and-submitter-use-uuid-domain-refs-req-pid-002`
- **files**: `lib/Settings/scholiq_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN parsed THEN `LearnerProfile` defines `guardianRefs` (`type: array`, items `format: uuid`, title "Guardian Refs") alongside the unchanged `parentIds`, and `ExcuseRequest` defines both `learnerRef` (uuid) and `submittedByRef` (uuid) alongside the unchanged `learnerId` / `submittedBy`
  - GIVEN neither new ref WHEN checked THEN it is absent from any `required` list (existing objects stay valid)
- [x] Implement
- [x] Test

### Task 3: Add the inbox scope ref to GradeNotification + bump versions

- **spec_ref**: `openspec/changes/portal-identity/specs/portal-identity/spec.md#requirement-versions-bump-for-the-version-gated-import-req-pid-003`
- **files**: `lib/Settings/scholiq_register.json`
- **acceptance_criteria**:
  - GIVEN `GradeNotification` WHEN parsed THEN it defines `learnerRef` (uuid, title "Learner Ref") alongside the unchanged `learnerId` and `recipient`
  - GIVEN the register WHEN compared to HEAD THEN `info.version` is `0.3.0` (was `0.2.0`) and all eight touched schema versions are `0.2.0` (were `0.1.0`)
  - GIVEN the edited file WHEN loaded with `python3 -c "import json; json.load(...)"` THEN it parses without error
- [x] Implement
- [x] Test

### Task 4: Register the capability spec

- **spec_ref**: `openspec/changes/portal-identity/specs/portal-identity/spec.md`
- **files**: `openspec/specs/portal-identity/spec.md`, `openspec/changes/portal-identity/*`
- **acceptance_criteria**:
  - GIVEN the declared capability WHEN the change is in flight THEN `openspec/specs/portal-identity/spec.md` exists with status `in-progress` pointing at this change
  - GIVEN the repo gates WHEN run (`openspec validate portal-identity`, JSON validity) THEN they pass
- [x] Implement
- [x] Test

## Quality checklist

- Register remains valid JSON (`python3 json.load`)
- Every addition is additive/optional; no NC-uid property removed or renamed; no `required` change
- gate-28: every new property carries a `title`
- `openspec validate portal-identity` passes
- No user-facing strings, no code, no UI → no i18n / Newman / Playwright artifacts needed
