# Design — Secure Exam Test Mode (native, provider-free delivery hardening)

## 1. What this is and is not (read this first)

Native test mode is **browser-JS-level deterrence for low/mid-stakes assessments** — it makes casual
cheating (alt-tabbing to notes, opening a second app, wandering off) visibly logged and slightly harder, and
it gives a teacher an evidence trail. It is **not** a security boundary:

- A learner with devtools open, a browser extension, a second physical device, a virtual machine, or a
  screen-mirroring setup can defeat every check in this design. There is no code-integrity attestation, no
  OS-level kiosk lock, and no way for JavaScript running in an ordinary browser tab to prove it hasn't been
  tampered with.
- The Fullscreen API can be exited by the user at will (that's the platform contract — browsers do not let a
  page trap a user in fullscreen against their will); this design treats the exit as a **logged event**, not
  a blocked action.
- `beforeunload` can *warn* before a tab closes but cannot *prevent* it, and cannot guarantee a final
  network call completes (see §4.5).

This is the same posture the `assessment` spec already takes toward the external `ProvidesProctoring` seam —
plug in a real vendor for `tentamen-he` / `examen` / `certification-exam`. Native test mode is for `toets-vo`
/ `formative-quiz` profiles: a weekly digital toets where the alternative today is either no safeguard at
all, or a school paying for a proctoring vendor it doesn't need for that stakes level. `TakeAssessmentView`'s
pre-start screen (§3.2) must say this to the learner in plain language — what is logged, what is not
recorded (no camera, no microphone, no keystrokes/content outside the assessment items), and that this is a
deterrent, not a guarantee.

## 2. Why this doesn't touch the EU AI Act Annex III §3 boundary

Insight 928 (Spectr, critical, legal-requirement) is explicit: proctoring is high-risk **when it performs
biometric/behavioural inference** to detect/monitor prohibited behaviour, and emotion recognition in
education is prohibited outright (Art. 5). Native test mode's event sources are all deterministic DOM/browser
APIs — `fullscreenchange`, `visibilitychange`, `window.blur`, `popstate` — firing on unambiguous browser
state transitions. There is no camera, no microphone, no gaze tracking, no ML model, no inference step of any
kind between the browser event and the logged flag. This is why `recordWebcam` is explicitly documented as
not applicable to `nativeTestMode` (§5.1) — adding webcam capture later would be the thing that actually
crosses into Annex III §3 territory, and that door is deliberately not opened here.

The existing `assessment` spec guardrails (Art. 14 human oversight: "a flag never auto-alters a result";
`flagReviewMode: manual` only in v1; AI-assisted review gated behind `AiFeature` + DPO) already cover the
*review* side and apply unchanged to native-mode flags, since they land in the same `ProctoringSession.flags`
array reviewed by the same `ProctoringReviewQueue.vue`.

## 3. Frontend flow — `TakeAssessmentView.vue`

### 3.1 Branch on `proctoring` shape

`init()` (`:274-296` at HEAD) currently branches only on `assessment.proctoring.provider` truthiness. It
gains a second branch:

```
if (assessment.proctoring?.provider) → existing external-notice path (unchanged)
else if (assessment.proctoring?.nativeTestMode) → new native path (§3.2-§3.5)
else → existing unproctored path (unchanged)
```

A config with both `provider` and `nativeTestMode` set is treated as external-wins (log a console warning) —
this is a config-authoring error, not a state the UI needs to make impossible; the register has no
cross-field validation precedent to enforce it declaratively (checked: no schema in
`lib/Settings/scholiq_register.json` expresses a mutual-exclusion constraint between two properties of the
same object).

### 3.2 Pre-start instructions screen (new UI state `showTestModeIntro`)

Before creating the `AssessmentResult`, native mode shows a screen (mirrors the existing
`take-assessment__proctoring-notice` pattern at `:47-55`) stating in plain language:

- Fullscreen is required while the test is open (if `lockdownBrowser: true`).
- Switching tabs, minimising the window, or losing focus is logged and reviewed by your teacher — not acted
  on automatically.
- No camera, microphone, or screen content outside this page is captured.
- The test can only be open in one tab/window at a time.

Only a **Start** button proceeds (no silent auto-start) — this satisfies story 9799's "clear environment
check and start flow so I know what is recorded and when the exam ends" directly, which the current
dismiss-only notice does not (it says a provider is configured but not installed; it never says what native
mode actually logs).

### 3.3 Single-attempt window guard

Two failure modes to prevent, evidenced by the fact `createResult()` (`:354-382`) unconditionally POSTs a new
`AssessmentResult` every time `init()` runs with no prior-attempt check:

1. **Duplicate `AssessmentResult` creation** — a learner reloading the page, or `maxAttempts=1` being
   silently violated by a second `init()` run, would create a second `in-progress` result.
2. **Concurrent tabs on the same attempt** — a learner opens the assessment in two tabs to give themselves an
   easier time evading the focus/visibility checks in one of them.

Mitigation, both client-side (no new backend query capability assumed — see note below):

- `checkExistingAttempt()`: `GET /apps/openregister/api/objects/scholiq/AssessmentResult?limit=100`, then
  filter client-side to `assessmentId === this.assessmentId && learnerId === currentUser.uid && lifecycle ===
  'in-progress'`. **This mirrors the established convention in this app** — `ProctoringReviewQueue.vue:168`
  fetches the full `ProctoringSession` collection with no server-side field filter and filters in JS; no
  fetch call site in `src/` at HEAD passes a field-filter query parameter, so this design does not assume an
  unverified filter API. If a matching result exists, resume it (`resultId = existing.uuid`) instead of
  calling `createResult()`.
- `acquireTabLock(resultId)`: a `localStorage` heartbeat keyed `scholiq-native-test-mode-lock-${resultId}`,
  value `{ tabId, updatedAt }`, refreshed every 5s. On load, if a live lock (updated within the last 15s) from
  a different `tabId` exists for the same `resultId`, this tab shows a blocking "already open elsewhere"
  message instead of rendering items, and — if a `ProctoringSession` already exists for the result — appends
  a `concurrent-session-detected` flag (severity `high`) via the same append pattern as §3.4. This is a
  same-browser, best-effort guard (two different browsers/devices can't see each other's `localStorage`) —
  stated plainly as a limitation, not sold as cross-device detection.

### 3.4 Hardening + event → flag mapping

On `Start` (native path only): request fullscreen if `lockdownBrowser`, create the `ProctoringSession`
(`POST` with `assessmentResultId`, `learnerId`, `provider: "native-test-mode"`, `status: "created"`,
`tenant_id`), dispatch its `activate` transition (existing lifecycle transition, `:5127-5130` — no new
transition needed), then attach listeners:

| Browser event | Flag `kind` | Default `severity` | Gated by |
|---|---|---|---|
| `document.fullscreenchange` (exiting fullscreen) | `fullscreen-exit` | `medium` | `lockdownBrowser` |
| `document.visibilitychange` (`document.hidden === true`) | `tab-hidden` | `medium` | always (native mode) |
| `window.blur` | `window-blur` | `low` | always (native mode) |
| `window.popstate` / blocked back-navigation | `blocked-navigation` | `low` | `navigationLock` |
| tab-lock conflict (§3.3) | `concurrent-session-detected` | `high` | always (native mode) |

Each qualifying event calls `appendFlag(kind, severity)`: read the current `ProctoringSession` (already held
in component state from creation — no extra GET needed for the common case), append `{ flagId: crypto
.randomUUID(), kind, occurredAt: new Date().toISOString(), severity, reviewDecision: 'pending' }` to `flags`,
`PUT` the full array back — **identical shape and identical read-modify-write pattern to
`ProctoringReviewQueue.vue`'s `recordDecision()` (`:205-258`)**, so `ProctoringReviewQueue.vue` needs zero
changes to display these flags: `pendingFlagCount`/`hasAnnulledFlag` calculations (`:5142-5187`) and the
review UI all already operate on the generic `flags[]` shape.

Client-side throttle: no more than one flag of the same `kind` per 5 seconds, to avoid flooding the array
when a user rapidly alt-tabs (e.g. `visibilitychange` firing repeatedly). This is an implementation detail,
not a normative requirement — the spec only requires that qualifying events are logged, not a specific rate.

### 3.5 Submit / teardown

On `submitAssessment()` success (native mode): dispatch the `ProctoringSession`'s `end` transition
(`:5131-5134`, existing), release the tab lock (`localStorage.removeItem`), exit fullscreen if active. On
`beforeDestroy()` without a successful submit (browser closed mid-attempt): best-effort only — a synchronous
`fetch` cannot be guaranteed to complete during `beforeunload`; this implementation uses
`navigator.sendBeacon()` where the browser supports it for the final state, and otherwise the session is left
`active` and simply has no `end` transition recorded, which is itself informative to a reviewer (an
attempt that never cleanly ended is visible in the session's `status` field).

## 4. Schema changes

### 4.1 `Assessment.proctoring` (existing object, `lib/Settings/scholiq_register.json:4573-4606`)

Add two properties, alongside the existing `provider` / `lockdownBrowser` / `recordWebcam` /
`flagReviewMode`:

| field | type | default | notes |
|---|---|---|---|
| `nativeTestMode` | boolean | `false` | Enables Scholiq's built-in browser-JS hardening (no external `ProvidesProctoring` adapter). Mutually exclusive with `provider` in practice (external wins if both set — see §3.1); not schema-enforced, no precedent for cross-field constraints in this register. |
| `navigationLock` | boolean | `true` | When `nativeTestMode` is set: warn on tab close/reload, log blocked back-navigation attempts as `blocked-navigation` flags. |

Update existing `lockdownBrowser` description to add: "When `nativeTestMode` is set, requests browser
Fullscreen API and logs exits as flags — not an OS-level kiosk client." Update `recordWebcam` description to
add: "Not applicable when `nativeTestMode` is set — native mode never requests camera/microphone access."

### 4.2 `ProctoringSession` authorization (`lib/Settings/scholiq_register.json:4975-5187` at HEAD)

Add `x-openregister-authorization: { "create": ["user"] }` at the schema level, matching the one existing
precedent in this register (`XapiStatement`, `:1281`, `"create": ["admin"]`).

**Known residual gap, stated honestly rather than silently left implicit**: this register has no
demonstrated pattern for *object-level* create scoping (e.g. "only the learner named in `learnerId`") or
*field-level* write scoping (e.g. "only an invigilator may write `flags[].reviewDecision`") — `x-property-rbac`
elsewhere in this register (e.g. `AssessmentResult`, `:4957-4972`) only scopes **read**, never write. This
means, at HEAD and after this change, any authenticated user with `create`/`update` rights on
`ProctoringSession` can technically write a flag's `reviewDecision` directly via the generic object API,
bypassing `ProctoringReviewQueue.vue`'s invigilator-only UI convention — an *application-level* convention,
not a *server-enforced* one. This is a pre-existing platform limitation (it also already applies to
`AssessmentResult`'s `responses`/scores, written by the same generic API), not something introduced by native
test mode, and not something this M-sized, browser-JS-scoped change can fix without OpenRegister engine work
on field-level write RBAC. Flagged in Out of Scope (§6) as a follow-up.

## 5. Data model summary

No new schemas. `ProctoringSession.flags[].kind` remains free-text `string` (`lib/Settings/scholiq_register
.json:5039+`) — the table in §3.4 is the native-mode vocabulary, documented in the spec delta, not a schema
enum change (kind was already free-text to support arbitrary external-provider flag kinds like "gaze-away,
audio-event, object-detected" per its existing description).

## 6. Out of scope

- Any change to the external `ProvidesProctoring` interface or adapters — native mode is a parallel path, not
  a new implementation of that interface.
- OS-level kiosk/lockdown client, webcam/microphone capture, gaze or emotion inference — deliberately never
  (see §2).
- Cross-device / cross-browser concurrent-session detection (the tab lock in §3.3 is same-browser only via
  `localStorage`).
- Field-level write RBAC on `ProctoringSession.flags[].reviewDecision` (§4.2) — a cross-cutting OpenRegister
  capability gap, not fixable within this change's scope.
- AI-assisted review of native-mode flags — already gated behind the existing `AiFeature` + DPO requirement
  (`assessment/spec.md:71-77`); unchanged by this proposal.
- Retrofitting `maxAttempts > 1` handling into the single-attempt guard beyond "don't duplicate the current
  in-progress attempt" — multi-attempt policies remain governed by `Assessment.maxAttempts` /
  `keepScore` (existing, unchanged).
