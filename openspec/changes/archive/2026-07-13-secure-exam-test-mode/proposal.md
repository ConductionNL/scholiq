---
kind: code
depends_on: []
---

# Proposal: secure-exam-test-mode

## Why

The `assessment` spec already models proctoring as a pluggable, provider-free seam — but at HEAD that seam
is the *only* delivery-hardening path, and it is entirely inert:

- **`Assessment.proctoring` config is external-adapter-only.** `lib/Settings/scholiq_register.json:4573-4602`
  defines `provider`, `lockdownBrowser`, `recordWebcam`, `flagReviewMode` — but `lockdownBrowser`'s own
  description (`:4586`, "Enable lockdown browser integration") and `provider`'s description (`:4580`,
  "Proctoring adapter identifier; resolves to ProvidesProctoring") both presuppose a concrete external
  adapter. `lib/Proctoring/ProvidesProctoring.php:44-46` says explicitly "No concrete provider ships with
  Scholiq; this is the integration seam only." There is no config path that means "harden the browser tab
  itself, with no external vendor."
- **`TakeAssessmentView.vue` never calls the proctoring seam at all.** When `assessment.proctoring.provider`
  is set, the view shows a passive dismiss-only notice (`src/views/TakeAssessmentView.vue:47-55`) and
  `dismissProctoringNotice()` (`:425-436`) just calls `createResult()` — it never invokes
  `ProvidesProctoring::startSession()`, never creates a `ProctoringSession` object, and attaches no browser
  event listeners. A repo-wide grep for `ProctoringSession` construction (`fetch(...{method:'POST'`) confirms
  this: the only two hits are the interface's own docblock (`lib/Proctoring/ProvidesProctoring.php:67,98`)
  and `ProctoringReviewQueue.vue`'s *read* path — no code anywhere creates a `ProctoringSession` object today.
  `ProctoringSession` also carries no `x-openregister-authorization` block (verified by brace-matching the
  10,483-character schema object in `lib/Settings/scholiq_register.json` — the string
  `x-openregister-authorization` does not occur inside it), unlike the one schema in the register that does
  restrict `create` (`XapiStatement`, `:1281`, `"create": ["admin"]`) — so the object type this change starts
  writing to has never had its authorization posture decided.
- **`ProctoringReviewQueue.vue` already reads generically.** `loadSessions()`
  (`src/views/ProctoringReviewQueue.vue:163-182`) does `GET
  /apps/openregister/api/objects/scholiq/ProctoringSession?limit=100` with no `provider` filter and lists
  every session with a pending flag; `recordDecision()` (`:205-258`) PUTs the full `flags[]` array back,
  matching the existing schema shape (`flagId`, `kind`, `occurredAt`, `severity`, `reviewDecision`,
  `reviewedBy`, `reviewedAt` — `lib/Settings/scholiq_register.json:5039-5100`+). Nothing in this queue is
  provider-specific, so a native, browser-JS-only session that writes the same shape is reviewed by the exact
  same invigilator UI with zero changes to that file.
- **No open-source Dutch assessment platform exists** (insight 824 — Cito/DiatOets/IEP are all proprietary),
  and demand is concentrated at the low/mid-stakes end: competitor evidence shows "Test Mode lockdown
  browser" gated behind a **paid** tier even at It's Learning (Spectr `competitor_features.id=32145`, K-12
  incumbent), while ProctorU's "Browser lockdown" (`id=27837`) and Questionmark's "Secure testing"
  (`id=27855`) are marketed as high-stakes vendor add-ons. OpenOLAT ships a comparable browser-native
  "Exam/assessment mode with secure fixed-timespan testing" (`id=32071`) without a paid vendor — closest
  analogue to what this change proposes. Stories `assessment-take-online` (9799, critical: "As a student I
  want a clear environment check and start flow so I know what is recorded and when the exam ends") and
  `assessment-integrity-review` (9800, high: "As an assessment expert I want a queue of flagged events with
  context so I can decide quickly whether to refer a case") both describe a workflow the current stubbed
  proctoring notice cannot deliver at all — the "environment check and start flow" doesn't exist, and the
  review queue has nothing to review because no session is ever created.
- **EU AI Act posture already set the guardrail this change must not cross** (insight 928, critical,
  legal-requirement): proctoring is Annex III §3 high-risk *when it performs biometric/behavioural
  inference*; emotion recognition in education is prohibited outright (Art. 5). `assessment/spec.md:64-70`
  already requires "a flag MUST NOT auto-alter a result" and `:71-77` gates any AI-assisted flag review
  behind an `AiFeature` + DPO registration, with v1 shipping `flagReviewMode: manual` only. A native mode
  built from deterministic browser events (fullscreen/visibility/focus/navigation) — no camera, no
  microphone, no inference — sits outside Annex III §3 entirely and can reuse the same human-review
  discipline the pluggable-provider path already establishes.

This is a genuine capability gap, not a UI polish task: schools and MBO programmes running a routine `toets`
have no in-app way to discourage tab-switching or accidental navigation away from a timed test today, and the
only documented "solution" (`provider`) requires contracting an external vendor even for a weekly quiz.

## What Changes

- **`Assessment.proctoring` gains a `nativeTestMode` boolean and a `navigationLock` boolean**
  (`lib/Settings/scholiq_register.json`, additive to the existing `proctoring` object at `:4573-4606`).
  `nativeTestMode: true` selects Scholiq's own browser-JS hardening instead of resolving `provider` through
  `ProvidesProctoring`; the two are mutually exclusive (a config with both set is a config error — enforced in
  `TakeAssessmentView.vue`, not the schema, per the existing precedent of no `x-openregister-authorization`
  cross-field validation elsewhere in this register). `lockdownBrowser`'s description gains a native-mode
  clause (browser Fullscreen API, not an OS-level kiosk client). `recordWebcam` is explicitly documented as
  not applicable when `nativeTestMode` is set — this change never requests camera/microphone access, by
  design (keeps native mode outside Annex III §3 and away from the Art. 5 emotion-recognition line).
- **`TakeAssessmentView.vue` implements the native hardening path**: a pre-start instructions screen (what is
  logged, what is not, that this deters rather than prevents), a single-attempt-window guard (resume an
  existing `in-progress` `AssessmentResult` for the same learner+assessment instead of creating a duplicate;
  detect a second tab/window attaching to the same attempt), a fullscreen request + `fullscreenchange` /
  `visibilitychange` / `window blur` / `popstate` listener set, and a read-modify-write append of each
  qualifying event into a `ProctoringSession.flags[]` entry — using the exact array-PUT pattern
  `ProctoringReviewQueue.vue:224-235` already uses for the reciprocal write, and the exact flag object shape
  (`flagId`, `kind`, `occurredAt`, `severity`, `reviewDecision: "pending"`) the schema already defines.
- **`ProctoringSession` gains an explicit `x-openregister-authorization.create: ["user"]`** — turns the
  previously-undecided default into a documented posture, mirroring the one precedent in this register
  (`XapiStatement`, `:1281`). This is the first change to ever write this schema, so its authorization posture
  needs to be a decision, not an accident.
- **No new PHP.** Session/flag persistence goes through the existing generic OpenRegister object API the way
  `TakeAssessmentView.vue` and `ProctoringReviewQueue.vue` already do (ADR-022) — no controller, no service
  class. `ProctoringReviewQueue.vue` is unchanged; it already reviews whatever `ProctoringSession` rows exist,
  regardless of `provider`.
- **Explicitly out of scope, stated plainly in `design.md`**: this is browser-JS-level deterrence, not
  tamper-proof enforcement — it does not replace the external `ProvidesProctoring` path for `tentamen-he`,
  `examen`, or `certification-exam` profiles, which should keep using a real vendor for high-stakes delivery.

## Capabilities

### Modified Capabilities

- `assessment`: the "Proctoring is a pluggable provider" requirement is clarified to scope "no bundled
  provider" to the *external adapter* seam; a new, always-available native test-mode path is added alongside
  it, reusing `ProctoringSession` and the existing review queue unchanged, and a single-attempt window guard
  is added to `AssessmentResult` delivery.

## Impact

- **`lib/Settings/scholiq_register.json`** — `Assessment.proctoring` gains `nativeTestMode` +
  `navigationLock`; `lockdownBrowser`/`recordWebcam` description updates; `ProctoringSession` gains
  `x-openregister-authorization.create: ["user"]`.
- **`src/views/TakeAssessmentView.vue`** — pre-start instructions screen, single-attempt guard, fullscreen +
  event-listener hardening, `ProctoringSession` create/append/end calls.
- **`src/views/ProctoringReviewQueue.vue`** — unchanged (already generic).
- **`lib/Proctoring/ProvidesProctoring.php`** — unchanged (native mode does not implement this interface; it
  is a parallel, non-pluggable path, not a new adapter).
- **`l10n/en.json` / `l10n/nl.json`** — new keys for the instructions screen and flag-kind labels.
