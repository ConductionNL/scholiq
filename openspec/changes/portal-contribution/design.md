# Design: portal-contribution

## Architecture Overview

Portaliq (hydra ADR-046) is the one shared external portal for people without
Nextcloud accounts. Domain apps contribute by shipping a single plain class at
a convention FQCN; portaliq's `PortalContributionRegistry` resolves
`OCA\{App}\Portal\PortalContributionProvider` per installed app and duck-types
it (`method_exists`, never `instanceof`). Scholiq therefore adds exactly one
new file under `lib/Portal/` and touches nothing else in the runtime app:

```
portaliq (if installed)
  └─ registry resolves OCA\Scholiq\Portal\PortalContributionProvider (FQCN)
       ├─ getAudiences() → ['student','parent']   (v2, preferred)
       ├─ getAudience()  → 'student'              (v1 fallback)
       └─ getContribution($subject) → manifest (pure data) or null
            ├─ student: read grade-entry/final-grade/attendance-record/
            │           enrolment/submission/excuse-request scoped by
            │           learnerRef == subject.subjectRef (their LearnerProfile
            │           object UUID); grade-notification inbox; create
            │           Submission + ExcuseRequest
            └─ parent:  read grade-entry/attendance-record/excuse-request via a
                        one-hop join (LearnerProfile.guardianRefs contains
                        subject.subjectRef → child learnerRef); create
                        ExcuseRequest for the child (substantial trust)
```

This change is **`kind: code`** and depends on **`portal-identity`** (the
`kind: config` head that adds the UUID refs). It carries **no** register edit —
the manifest's `scopeField`/`via` values are meaningless unless those
properties already exist, which is exactly why the schema work is its own,
prior change (ADR-032).

Without portaliq the class is never instantiated — inert ~9 KB of dead weight
by design (A1). There is deliberately **no** DI registration in
`lib/AppInfo/Application.php`; portal discovery is pull-based from portaliq.

Note on the FQCN: PHP resolves class names case-insensitively, so the
registry's `ucfirst(appId)` candidate `OCA\Scholiq\...` resolves to this app's
`OCA\Scholiq\Portal\PortalContributionProvider` (composer PSR-4 + info.xml
`<namespace>Scholiq`).

## Declarative-vs-imperative note

The contribution is **declarative by nature**: `getContribution()` returns a
pure-data manifest (label, collections, actions, notifications) that portaliq
interprets — the ADR-024 / ADR-031 philosophy. No behaviour, no I/O, no
callbacks. A provider *class* (rather than a JSON file) is used only because it
is the delivery vehicle ADR-046 mandates: autoloadable cross-app by FQCN,
discoverable in the DI container, and able to branch on the server-derived
`$subject` (audience filtering) without portaliq parsing app-private config.
The one imperative surface is that single audience branch; everything portaliq
renders or enforces (scoping, projection, trust, RBAC) is data in the manifest,
evaluated portaliq-side.

## The additive-remap rationale (why UUID refs, not learnerId)

Amendment A4 forbids scoping a portal subject by a Nextcloud user id — an
external has no NC account. Scholiq scopes internally by `learnerId` (an NC
uid) and links parents by `LearnerProfile.parentIds` (NC uids). The
`portal-identity` change added UUID domain-object refs **alongside** those
(never replacing them — internal grading/attendance/notification flows depend
on the NC-uid fields). This provider scopes **exclusively** by the new refs:

- `student`: record `learnerRef` == `subject.subjectRef` (the student's own
  `LearnerProfile` object UUID). Submission uses the `learnerRefs` array
  (membership).
- `parent`: `subject.subjectRef` is a guardian domain UUID; resolve the child
  `LearnerProfile`(s) whose `guardianRefs` contains it, then read records by
  `learnerRef`.

## Claim-names contract (the stable Scholiq↔portaliq boundary)

`scopeClaim` names the portaliq subject claim the scope value is resolved from;
`subject.subjectRef` carries that resolved value server-side (never trusted from
the client — ADR-005):

| Audience  | `scopeClaim` | subjectRef is…                                       |
|-----------|--------------|------------------------------------------------------|
| `student` | `learnerRef` | the student's own `LearnerProfile` object UUID       |
| `parent`  | `guardianRef`| a guardian domain-object UUID (matched vs `guardianRefs`) |

## Parent audience — SHIPPED via the reverse / scope-value join

> Originally this section deferred the parent surface. Portaliq has since merged
> the reverse join and the follow-up change **`portal-parent`** re-enabled the
> `parent` audience; this section now documents the shipped shape. Full
> derivation + a worked nil-UUID example: `openspec/changes/portal-parent/design.md`.

The parent surface (a guardian reading a minor's records) resolves: from
`learner-profile` rows whose `guardianRefs` contains the guardian's ref, collect
the child learner ref, then read `grade-entry`/`attendance-record`/… **where
`learnerRef` ∈ those child refs**. That is a *reverse / scope-value join*: the
record carries a foreign scope key (`learnerRef`) that the join resolves.

Portaliq's original one-hop `via` did the **opposite** direction (kept outer
rows whose **own id** was in the target set — the zaakafhandelapp `rol → zaak`
shape). Portaliq now ships a `match` discriminator on `via`: `match: 'id'`
(default, forward) keeps rows by their own id, while `match: 'scopeField'`
(reverse) keeps outer rows whose value at their own `scopeField` (dot-path) is
in the verified target set. So each parent collection declares
`via {register: scholiq, schema: learner-profile, scopeField: guardianRefs,
targetField: id, match: 'scopeField'}` with its own `scopeField: learnerRef` —
the guardian resolves to the children's `LearnerProfile` UUIDs (via `targetField:
id`, the OR object identity), and each `grade-entry` survives iff its `learnerRef`
is in that set.

The additive schema refs `portal-identity` landed (`guardianRefs` on
`learner-profile`, `submittedByRef` on `excuse-request`) made the parent
audience a **pure provider addition** — no schema work. Tracked on
Conduction/scholiq#43.

## minTrust story

- **student reads** (grades/attendance/etc.): `minTrust: low` — the learner
  viewing their own data.
- **parent reads** (grades/attendance/excuses): `minTrust: substantial` — set by
  the `portal-parent` follow-up now that the reverse `via` reads are live: a
  guardian authenticating to view a **minor's** data needs substantial assurance
  (pairs with the DigiD/eHerkenning broker). (Earlier, while deferred, this was
  noted as "low today, raise later"; the true floor is declared now.)
- **parent ExcuseRequest create**: `minTrust: substantial` **now** — the schema
  already models the eIDAS `submittedAuthLevel`, and a guardian acting on a
  minor's behalf implies substantial assurance.
- **student ExcuseRequest create**: `minTrust: low`.

## Field projection (staff-only columns dropped)

Read collections ship a `fields` **whitelist** (portaliq keeps identifiers +
these, drops the rest). Excluded staff-only/internal columns per schema:

| Schema             | Kept (student/parent)                                              | Dropped (staff-only / internal)                                   |
|--------------------|-------------------------------------------------------------------|-------------------------------------------------------------------|
| grade-entry        | learnerRef, courseId, curriculumPlanId, componentId, value, gradeScaleId, period, gradedAt | grader, comment, weight, sourceKind, submissionId, assessmentResultId, sessionId |
| final-grade        | learnerRef, courseId, programmeId, curriculumPlanId, gradeScaleId, value, passed, lastRecomputedAt | breakdown (internal weighting) |
| attendance-record  | learnerRef, sessionId, cohortId, status, minutesAttended, markedAt | markedBy (staff identity), reason, excuseRequestId |
| enrolment          | learnerRef, courseId, mandatory, dueDate, source, regulationSlug, cohortId | managerId, bulkJobId, reason |
| submission         | learnerRefs, assignmentId, attachmentRefs, submittedAt, feedbackText, lifecycle | rubricScores, proposedGrade, gradeEntryId (marking internals) |
| excuse-request     | learnerRef, dateFrom, dateTo, reason, reasonKind, attachmentRef, lifecycle, decidedAt | submittedBy, submittedByRef, submittedAuthLevel, decidedBy, decisionNote |
| grade-notification | learnerRef, event, courseId (inbox)                               | recipient (parent NC uid), sourceId, idempotencyKey |

## Create-action whitelists

Portaliq accepts only whitelisted fields and stamps `scopeField == subjectRef`
server-side; everything else is server-authoritative.

| Action                        | Audience | schema         | scopeField (stamped) | Whitelisted fields |
|-------------------------------|----------|----------------|----------------------|--------------------|
| createSubmission              | student  | submission     | learnerRefs          | assignmentId, attachmentRefs |
| createExcuseRequest           | student  | excuse-request | learnerRef           | dateFrom, dateTo, reason, reasonKind, attachmentRef |
| createExcuseRequestForChild   | parent   | excuse-request | submittedByRef       | learnerRef (the child), dateFrom, dateTo, reason, reasonKind, attachmentRef |

**Parent create scope-stamp.** Portaliq's writer stamps `scopeField ==
subjectRef`. A parent's subjectRef is the guardian UUID, so it must land in
`submittedByRef` (never `learnerRef`, which is the child). The child
`learnerRef` is therefore in the whitelist (client-supplied). Cross-checking
that the supplied child is actually one the guardian's `guardianRefs` covers is
the same one-hop join as the parent reads and is a documented follow-up (fail-
closed: unvalidated until the join lands).

**Submission array scope-stamp.** `createSubmission` stamps `learnerRefs`
(array). Portaliq's writer stamps a scalar subjectRef; for the array-typed
`learnerRefs` OR stores a single-element array (or the internal Submission flow
keeps using `learnerIds`). An array-aware portaliq writer stamp is a portaliq-
side follow-up, not part of this change.

## API Design

None. No routes, controllers, or endpoints. Reads/creates go through
OpenRegister's existing object API, invoked by portaliq server-side with subject
scoping (ADR-022 — no app-local CRUD wrappers).

## Database Changes

None owned by this change. Scholiq is a thin OR client; the UUID refs are added
by `portal-identity`. No `migration.md`: no data transformation, no
required-field change.

## Seed Data

This change adds **no** seed objects (it ships code + tests only). Portal
scoping needs records that carry the `portal-identity` refs pointing at a real
seeded `LearnerProfile` object UUID; because register.d fragments and
`components.objects` go **LIVE** on import (never drafts), no demo objects ship
here. The tutorial/demo harness stamps refs at apply-time using the nil-UUID
placeholder `00000000-0000-0000-0000-000000000000` until a real LearnerProfile
is seeded — see `portal-identity/design.md` for the seed convention. Refs stay
optional, so records without them remain valid and simply invisible to the
portal (fail-closed).

## Nextcloud Integration

- Controllers: none. Services: none. Mappers/Entities: none (OR owns storage).
- Events/Hooks: none — no `Application.php` registration by design.

## Security Considerations

- **Server-derived subject only** (ADR-005 / ADR-046 A6): `$subject` is built by
  portaliq's auth edge; the provider only reads `audience` to branch and never
  echoes or trusts client-supplied identity.
- **UUID domain-object scoping** (A4): every scope key is a `LearnerProfile` /
  guardian object UUID, never an NC uid.
- **Fail-closed audience filter**: any audience other than `student` / `parent`
  → `null`.
- **Field whitelist on read and create**: grades, status, lifecycle, staff
  identities, decision notes and assurance levels are never exposed on read and
  never client-settable on create; portaliq enforces both server-side.
- **Minor-data trust**: parent reads and the parent excuse-create all require
  `minTrust: substantial` (set by the `portal-parent` follow-up; pairs with the
  DigiD/eHerkenning broker).
- No secrets, no tokens, no endpoints in this change.

## File Structure

```
lib/Portal/PortalContributionProvider.php            (new — plain class, no deps)
tests/Unit/Portal/PortalContributionProviderTest.php (new — contract + drift pin)
openspec/
  changes/portal-contribution/                       (this change)
  specs/portal-contribution/spec.md                  (capability status stub)
```

## Trade-offs

- **Both audience methods vs v2-only** — v2-only is leaner but the registry's v1
  fallback path must keep working; two constant-return methods cost nothing.
- **Declared `via`/`minTrust`/`scopeClaim` (fleet convention per pipelinq)** —
  declaring them keeps the manifest a pure-data surface portaliq interprets; the
  parent `via` join was forward-looking while deferred and is now honoured by
  portaliq's merged reverse-join reader (re-enabled in `portal-parent`).
- **Parent `via` join vs a Guardian schema** — a first-class Guardian schema is
  heavier than this slice warrants; the one-hop join over `guardianRefs` is the
  minimum honest parent linkage. Guardians-as-domain-objects is a later slice.
- **Six-schema slice vs all ~38** — a tractable first slice (the learner-facing
  records) proves the contract; the rest are later portal slices.
