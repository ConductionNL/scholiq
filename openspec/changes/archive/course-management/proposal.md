## Why

Course Management is the data layer that every other wedge spec depends on. Enrolment needs a `Course` + `CourseSection` to attach learners to; compliance-audit needs a `Lesson` with a mandatory-training tag to track completion; certification needs a course completion event to trigger credential issuance. Without Course + Lesson entities in OpenRegister, all downstream specs have no subject to operate on. The wedge restricts scope to the minimum viable course model (no QTI assessments, no LTI, no OOAPI) needed to support compliance-training delivery via cmi5 + xAPI per ADR-002.

## What Changes

- Add OpenRegister schema `scholiq-course` (`Course` entity per ARCHITECTURE.md §3.1) with all required fields: id, code, name, name_nl, description, level, language, tenant_id, provider, published, created_at, updated_at, deleted_at.
- Add OpenRegister schema `scholiq-lesson` (`Lesson` / Module per ARCHITECTURE.md §3.1) with content_type enum: `text` | `video` | `scorm12` | `scorm2004` | `cmi5`.
- Add OpenRegister schema `scholiq-course-section` (`CourseSection` / Cohort) with nc_group_id binding.
- Add OpenRegister schema `scholiq-xapi-statement` (LRS statement store, append-only, per ADR-002).
- Add `Scholiq\Controllers\CourseController` (CRUD: list, show, create, update, archive), extending `AuditedController`.
- Add `Scholiq\Controllers\LessonController` (CRUD + import endpoint).
- Add `Scholiq\Controllers\LrsController` (`POST /api/lrs/statements`, `GET /api/lrs/statements`) per ADR-002 §Implementation notes.
- Add `Scholiq\Controllers\ScormController` serving SCORM 1.2/2004 runtime API per ADR-002 §Decision (3).
- Add `Scholiq\Service\Cmi5LaunchService` minting signed JWT launch tokens per ADR-002.
- Add `Scholiq\Service\ScormToXapiTranslator` mapping SCORM API calls to xAPI verbs per ADR-002 §Decision (3) table.
- Add `Scholiq\Service\CourseContentService` unpacking cmi5 + SCORM packages into `nc:files` at `/Scholiq/<tenant>/<course-id>/`.
- Add Vue views: `CourseListView`, `CourseDetailView`, `CourseFormView`, `LessonListView`, `LessonPlayer`.
- All mutations emit audit events per ADR-008.

## Capabilities

### New Capabilities

- `course-management`: Course and Lesson entity CRUD, cmi5 launch protocol, SCORM shim, xAPI LRS endpoint, content import, nc:files storage.

### Modified Capabilities

(none — no prior specs land before this one except `nextcloud-app`)

## Impact

- **OpenRegister schemas**: `scholiq-course`, `scholiq-lesson`, `scholiq-course-section`, `scholiq-xapi-statement` must be registered and available before enrolment, compliance-audit, or certification specs can write their entities.
- **LrsController**: `POST /api/lrs/statements` is the convergence point for all learning events — enrolment completion, attestation, certification — that downstream specs emit.
- **nc:files**: every course unpacks into `/Scholiq/<tenant>/<course-id>/`; `IRootFolder` must be available and the directory created at course-create time.
- **AuditTrail**: xAPI LRS endpoint writes `xapi.statement.received` events; these feed compliance-audit coverage % computation.
- **Wedge scope restriction**: OOAPI 5.0 endpoints, LTI 1.3, Common Cartridge import, and prerequisite enforcement are explicitly deferred to Phase 2/3; zero HE-specific fields are required in the wedge course model.
