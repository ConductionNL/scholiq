# Design: portal-identity

## Architecture Overview

Portaliq (hydra ADR-046) is the one shared external portal for people **without**
Nextcloud accounts. Its amendment A4 forbids scoping portal records by
Nextcloud user ids: externals have no NC account, so scope keys MUST be UUID
references to **domain** objects. Scholiq's learner records are scoped today by
`learnerId` — a Nextcloud user id (`type: string`, no `format`; verified at
HEAD) — and parents are linked by `LearnerProfile.parentIds` (also NC user ids).

This change makes the schemas A4-ready by adding, alongside each NC-uid
property, a new UUID domain-ref property:

```
LearnerProfile (the domain anchor; its own OBJECT UUID is the learner ref)
  ├─ parentIds   (kept: NC user ids of guardian accounts — internal)
  └─ guardianRefs[] (NEW: UUID refs to guardian domain objects — portal parent linkage)

record schemas          kept (NC uid)     NEW (domain UUID ref)  portal role
  GradeEntry            learnerId          learnerRef             student read
  FinalGrade            learnerId          learnerRef             student read
  AttendanceRecord      learnerId          learnerRef             student read
  Enrolment             learnerId          learnerRef             student read
  Submission            learnerIds[]       learnerRefs[]          student read/create (membership)
  ExcuseRequest         learnerId          learnerRef             student/parent read/create
  ExcuseRequest         submittedBy        submittedByRef         parent create scope-stamp
  GradeNotification     learnerId          learnerRef             student inbox
```

`learnerRef` holds the UUID of the `LearnerProfile` **object** the record is
about. The portal subject for the `student` audience IS that LearnerProfile
UUID (portaliq's auth edge maps the external person to it), so a record is the
student's iff `record.learnerRef == subject.subjectRef`. For the `parent`
audience the subject is a guardian domain UUID; the provider resolves
parent → learner in one hop by matching `subject.guardianRef` against
`LearnerProfile.guardianRefs`, then reads that learner's records by `learnerRef`.

## The additive-remap rationale

A4 could be satisfied two ways: (a) **repoint** `learnerId` to a UUID, or (b)
**add** a new UUID property beside it. This change chooses (b), deliberately:

- `learnerId` / `learnerIds` / `submittedBy` / `parentIds` are load-bearing for
  internal flows — grading roll-up keys on `(learnerId, curriculumPlanId)`,
  attendance leerplicht reporting reads `learnerId` as a `subjectIdField`,
  `GradeNotification` fan-out addresses `recipient`/`learnerId`, and
  `ExcuseApprovalHandler` matches on `learnerId`. Repointing them to a UUID
  would be a breaking, data-migrating change touching handlers far outside the
  portal slice.
- Additive keeps the change a pure schema addition with **zero** behavioural
  risk: existing objects validate unchanged, internal code paths see the same
  fields, and only the portal (which reads the new refs exclusively) is
  affected. The remap is "both live side by side"; a later program may migrate
  internal flows onto the UUID refs, but that is out of scope here.

## Claim-names contract

The `portal-contribution` provider names the portaliq subject claims it scopes
by — this is the stable contract between Scholiq and portaliq's auth edge:

| Audience  | Subject claim (`scopeClaim`) | Meaning                                         |
|-----------|------------------------------|-------------------------------------------------|
| `student` | `learnerRef`                 | the student's own `LearnerProfile` object UUID  |
| `parent`  | `guardianRef`                | a guardian domain-object UUID (matched vs `LearnerProfile.guardianRefs`) |

`subject.subjectRef` carries the claim value portaliq resolved server-side.
Scholiq never trusts a client-supplied identifier (ADR-005).

## Backfill is a follow-up (NOT in this change)

No existing object is rewritten here. Every `*Ref` starts life unset, which
means every pre-existing row is **invisible** to the portal until backfilled —
the fail-closed default. Backfilling (walking each record, resolving its
`learnerId` NC-uid to the matching `LearnerProfile` object UUID, and stamping
`learnerRef`; resolving `parentIds` to `guardianRefs`) is a documented
follow-up — a repair step or a one-off migration on Conduction/scholiq#39 — and
is intentionally excluded so this change stays a reviewable, reversible schema
addition.

## Declarative-vs-imperative note

This is a **declarative** change end to end: the entire delta is data in
`scholiq_register.json` (ADR-024 app-manifest / ADR-031 declarative-config
philosophy). There is no imperative surface — no PHP, no repair code, no
migration transform. OpenRegister's existing version-gated import applies the
additive properties on upgrade because the register `info.version` and each
touched schema version are bumped. No `migration.md` artifact: there is no data
transformation and no required-field change; rollback is reverting the JSON.

## Seed Data

This change adds **no** seed objects. Two hard reasons:

1. **Fragments go LIVE.** Objects placed in `register.d/*` fragments (or the
   register's `components.objects`) are imported as live rows — they are not
   drafts. Seeding a demo LearnerProfile/GradeEntry with a real portal ref here
   would inject live data into every install. So no demo objects ship in this
   change.
2. **Refs are cross-object.** A meaningful `learnerRef` is the UUID of a
   *specific* seeded `LearnerProfile`, which does not exist at register-import
   time. Any illustrative value would be the **nil-UUID placeholder**
   `00000000-0000-0000-0000-000000000000`.

Convention for the tutorial/demo environment (apply-time, not shipped):

| Schema           | Field          | Demo value (apply-time)                              |
|------------------|----------------|------------------------------------------------------|
| `LearnerProfile` | `guardianRefs` | `["<guardian domain object UUID>"]` (nil-UUID until a real guardian object is seeded) |
| `GradeEntry`     | `learnerRef`   | `<the seeded LearnerProfile object UUID>` (nil-UUID placeholder otherwise) |
| `Submission`     | `learnerRefs`  | `["<the seeded LearnerProfile object UUID>"]`        |
| `ExcuseRequest`  | `learnerRef` / `submittedByRef` | child LearnerProfile UUID / guardian domain UUID |

The demo/tutorial harness replaces the nil-UUID with the real object UUID at
import time; it is never committed to a register fragment (which would go live).

## Security Considerations

- **A4 domain-UUID scoping**: the whole point — a portal subject is scoped by a
  domain-object UUID (`LearnerProfile` / guardian), never a Nextcloud user id.
- **Fail-closed by construction**: an unset `*Ref` = no portal match = the row
  is invisible externally. There is no "empty ref matches everything" path
  because portaliq matches `scopeField == subjectRef` and a resolved subject
  ref is always a concrete UUID.
- **No `required` change**: adding a ref to `required` would break existing
  objects and could force partial/half-scoped rows; keeping them optional is
  both non-destructive and fail-safe.
- No PII is added; the refs are opaque object UUIDs. `bsnEncrypted`, `eckId`
  and other special-category fields on `LearnerProfile` are untouched and are
  NOT exposed by the identity change.

## File Structure

```
lib/Settings/scholiq_register.json    (8 schemas +*Ref; info 0.2.0→0.3.0; schemas 0.1.0→0.2.0)
openspec/
  changes/portal-identity/            (this change)
  specs/portal-identity/spec.md       (capability status stub, in-progress)
```

## Trade-offs

- **Additive vs repoint** — repoint would give one clean UUID scope key but
  breaks every internal flow and forces a data migration; additive is
  non-destructive at the cost of two side-by-side identifier spaces (documented
  above), reconciled by a later, separate migration if ever desired.
- **Optional vs required ref** — required guarantees scoping on every future
  row but invalidates every existing object; optional keeps the change additive
  and fail-closed (unset = invisible).
- **`submittedByRef` on ExcuseRequest** — added so the parent-audience create
  has an A4-clean scope-stamp (portaliq stamps `scopeField == subjectRef`,
  which for a parent is the guardian UUID → lands in `submittedByRef`, never in
  `learnerRef`). Without it a parent create would mis-stamp the child scope key.
- **`guardianRefs` on `LearnerProfile` vs a first-class Guardian schema** — a
  Guardian schema is heavier than this slice warrants; a UUID array on the
  learner is the minimum one-hop join the parent audience needs. Modelling
  guardians as their own domain objects is a later portal slice.
