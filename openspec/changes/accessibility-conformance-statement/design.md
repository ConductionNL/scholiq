# Design: accessibility-conformance-statement

## Context

Scholiq's buyers (schools, school boards) are public-sector bodies squarely inside the BDTO's scope
(`wetten.overheid.nl/BWBR0040936`). The decree's obligation is not "be accessible" — it is "publish a
verklaring in the mandatory model, keep it current, and give people a way to complain if it's wrong." NC +
nc-vue give Scholiq the WCAG 2.1 AA component baseline (`openspec/specs/nextcloud-app/spec.md:172`); nothing
in the app today discharges the disclosure obligation itself. This document works out the statement's
required field set, the data model connecting the statement to its evidence, and — the central decision —
why a maintained limitations register beats a blanket compliance claim.

## Goals / Non-Goals

**Goals**
- Ship an `AccessibilityStatement` whose fields match the Dutch government model closely enough that its
  content can be transcribed into the official invulassistent by the school.
- Make every conformance claim structurally evidence-backed: no `published` statement without evaluation
  evidence, no `fully-compliant` status while an `open` limitation exists.
- Give any user — not just admins — a way to report a barrier, landing as an actionable, notified record.
- Wire a real automated check (axe-core) into the existing Playwright suite so at least one class of
  evidence is continuously re-verified rather than asserted once and forgotten.
- Reuse existing declarative machinery (lifecycle guards, `calculatedChange` notifications, the generic
  object-create surface) — no new parallel mechanism.

**Non-Goals**
- Publishing the statement on the school's own public website or registering it at
  `toegankelijkheidsverklaring.nl` — those are the school's administrative acts; Scholiq generates the
  content, the school performs the publication step.
- A pluggable "any accessibility scanner" architecture — axe-core is the concrete, named choice.
- Automated remediation of any finding — every scan violation becomes a human-triaged
  `AccessibilityLimitation` row, not an auto-fix.
- Full EAA (private-sector) conformance tooling — this change targets the BDTO model because Scholiq's
  actual buyers are public-sector bodies; the EAA is noted for context, not implemented against.
- Implementing the `course-authoring-ux` keyboard-equivalent itself — only the cross-reference and the
  landing place (an `AccessibilityLimitation` row) if it ships without one.

## Data Model

```
AccessibilityStatement (draft → published → archived)
  │  requires AccessibilityStatementPublishGuard at draft → published
  │    (BLOCKS publish without status+evaluationMethod+evaluationDate+feedbackContact)
  │    (BLOCKS fully-compliant status while any open/mitigated limitation references it)
  │  x-openregister-calculations: reviewOverdue (JSON-logic @now/dateDiff idiom)
  │  x-openregister-notifications: reviewOverdue → compliance-officer (calculatedChange)
  ▼  (referenced by)
AccessibilityLimitation (open → mitigated → fixed)
  │  create/update restricted to admin/compliance-officer

AccessibilityFeedback (submitted → acknowledged → resolved)
  │  create OPEN to any authenticated user
  │  x-openregister-notifications: onSubmitted → compliance-officer/admin (trigger.type: created)
  └  optionally triaged into an AccessibilityLimitation ($ref, set during triage)
```

### `AccessibilityStatement`

Fields mirror the toegankelijkheidsverklaring.nl invulassistent's step order (`channelTitle`, `status`,
`evaluationMethod`/`evaluationDate`/`researchReportUrl`, `standardApplied`, `feedbackContact`,
`escalationRoute`, `lastReviewedAt`, `approvedBy`/`approvedByRole`). `status` is a 3-value enum
(`fully-compliant`/`partially-compliant`/`non-compliant`) rather than the government's literal five-letter
scale — see Decision 1 below for why, and how the mapping is documented so a human transcribing this record
into the official invulassistent isn't guessing.

### `AccessibilityLimitation`

One row per known issue, `$ref`-linked to the statement it is disclosed under. `severity` +
`wcagCriterion` + `justification` + `workaround` + `plannedFixDate` are all required-shape fields (nullable
`plannedFixDate` only, and only when genuinely undetermined) — this is deliberately the most tedious schema
in the change, because it is the one that has to be true.

### `AccessibilityFeedback`

Minimal by design: reporter, surface, description, severity, optional triage `$ref`. No new ticketing
machinery — this is the same generic object-create + notification-dialect shape every other user-facing
event in this app already uses (`Course.published`, `scholiq-notifications`'s four learner events).

## Decisions

### Decision 1: The statement's required fields, and why a 3-value status with a documented A–E mapping

**What the government model requires** (verified 2026-07-13, `toegankelijkheidsverklaring.nl` +
`digitoegankelijk.nl/toegankelijkheidsverklaring/invulassistent`): channel identity, one of five statuses
(A voldoet volledig, B/C voldoet gedeeltelijk — with/without an approved plan, D voldoet niet, E geen
verklaring), evaluation evidence (method, date, optional report link), findings against WCAG success
criteria, a remediation plan, a feedback/contact mechanism with a response-time commitment, and a named
approving official, actualised at least once a year. The example published statement at
`toegankelijkheidsverklaring.nl/register/14001` confirms the concrete shape: self-declared vs.
platform-verified basis, an audit date and conductor, the standard applied (EN 301 549 ch. 9/11 ≈ WCAG 2.1
A/AA), a feedback SLA, and an escalation reference to the Nationale Ombudsman.

**Why the schema uses a 3-value `status` enum instead of literal A–E.** The B/C distinction turns on whether
the school has a Logius-approved remediation *plan* on file — a procedural fact that lives in the school's
relationship with Logius, not in Scholiq's data model, and E ("no statement exists") is a non-state for a
record that exists by definition. Forcing Scholiq's schema to carry a field it cannot itself determine
(plan-approval status) would either be always-empty or silently wrong. Instead the schema captures
`fully-compliant`/`partially-compliant`/`non-compliant` — the part Scholiq's own evidence (the limitations
register, the axe-core scan) can actually attest to — and the schema description documents the A→fully,
B/C→partially, D→non mapping explicitly, so whoever transcribes the record into the official invulassistent
(the school, using this statement as their source) can pick B or C correctly based on their own plan status.
This is the same "don't hardcode what the institution, not the platform, decides" reasoning already applied
elsewhere in this app (see `study-progress`'s `normEcts` — no hardcoded default because the norm is
institution-set).

### Decision 2: A maintained limitations register, not a blanket "fully compliant" claim, is the defensible posture

A statement that simply asserts `fully-compliant` with no supporting register is exactly the failure mode
BDTO enforcement targets: Logius checks that the *substantiation* is present, not just that a checkbox is
ticked. Two structural choices make an unsubstantiated claim impossible in this schema, both enforced by
`AccessibilityStatementPublishGuard`, not by convention:

1. **Publish requires evidence.** A `draft` statement cannot become `published` without `status` +
   `evaluationMethod` + `evaluationDate` + a feedback contact all being set (Requirement 3).
2. **`fully-compliant` requires an empty limitations register.** The guard also refuses `published` with
   `status: fully-compliant` while any `open`/`mitigated` `AccessibilityLimitation` still references the
   statement (Requirement 4's scenario "Open limitation blocks a fully-compliant status"). A school cannot
   claim full conformance while a known, logged issue is still open — the only way to reach
   `fully-compliant` is to actually close out every logged limitation first, or downgrade the status to
   `partially-compliant` and let the limitations register carry the honest detail.

This mirrors `study-progress`'s `BsaDecisionGuard` precedent exactly: "no negative BSA without a logged
warning" there, "no fully-compliant claim while an issue is open" here — the same shape (a lifecycle-guard
that makes an invariant structural), applied to a different legal obligation. The limitations register is
therefore not optional scaffolding around the statement; it is the mechanism that makes the statement
trustworthy, and — because `AccessibilityFeedback` can be triaged into a new `AccessibilityLimitation` row —
it stays current between the mandatory annual reviews rather than only being revisited once a year.

### Decision 3: Feedback creation is open to any authenticated user, the opposite RBAC posture from the statement

`AccessibilityStatement` and `AccessibilityLimitation` are `compliance-officer`/`admin`-authored (the same
posture as `BsaWarning`/`xapi-statement`) because they are the school's official record. `AccessibilityFeedback`
is deliberately unrestricted at create time — restricting who can *report* a barrier would defeat the
requirement's own purpose (BDTO explicitly requires a feedback mechanism reachable by the people encountering
the barrier, not by the people who already know about it). Triage — moving a report into the limitations
register — stays officer-gated, same as every other schema in this change.

## Risks / Trade-offs

- **The axe-core scan samples pages rather than exhaustively covering all 141 manifest pages** (task 4, see
  `tasks.md`) — a full-coverage scan is a natural follow-up once the sampled scan's runtime and noise level
  are known; sampling now is chosen over a slow, potentially flaky full sweep at scope S.
- **`AccessibilityStatementPublishGuard`'s "no fully-compliant with an open limitation" rule is strict** — a
  school might reasonably want to publish `fully-compliant` while a `mitigated` (workaround shipped, fix not
  yet merged) limitation exists. This design blocks on `open` OR `mitigated`, deliberately the stricter
  reading, because a workaround is not the same as compliance; if this proves too strict in practice, a
  follow-up can relax the guard to allow `mitigated`-only exceptions with the workaround surfaced in the
  published statement text.
