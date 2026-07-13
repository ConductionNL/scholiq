# Tasks — Secure Exam Test Mode (native, provider-free delivery hardening)

> Scope: 1 schema delta (`Assessment.proctoring` + `ProctoringSession` authorization), 0 new PHP, 1 modified
> Vue view (`TakeAssessmentView.vue`), l10n, e2e coverage. `ProctoringReviewQueue.vue` is unchanged (already
> generic).

## Phase 1: Schema — `lib/Settings/scholiq_register.json`

- [x] Add `nativeTestMode` (boolean, default `false`) and `navigationLock` (boolean, default `true`) to the
      `Assessment.proctoring` object (design §4.1), alongside the existing `provider` / `lockdownBrowser` /
      `recordWebcam` / `flagReviewMode` properties.
- [x] Update `lockdownBrowser`'s `description` to add the native-mode clause (Fullscreen API, not an
      OS-level kiosk client).
- [x] Update `recordWebcam`'s `description` to state it does not apply when `nativeTestMode` is set.
- [x] Add `x-openregister-authorization: { "create": ["user"] }` to the `ProctoringSession` schema (design
      §4.2), matching the `XapiStatement` precedent.
- [x] Validate JSON (`python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'`); run
      `npm run check:json-strict` and `npm run check:register` — both PASS, no duplicate slugs, no broken
      lifecycle/`requires` references.

## Phase 2: Frontend — `src/views/TakeAssessmentView.vue`

- [x] Add `showTestModeIntro`, `nativeTestModeActive`, `proctoringSession`, `tabId`, `tabLockInterval` to
      `data()`. (Also added `showTabLockBlocked`, `tabLockKey`, `lastFlagAt`, `nativeHandlers` — needed to
      implement the tab-lock-blocked UI state, throttle bookkeeping, and listener teardown described in
      design §3.3/§3.4, not individually enumerated in this bullet but required by it.)
- [x] `init()`: branch on `assessment.proctoring.nativeTestMode` (external `provider` still wins if both are
      set — log a `console.warn`) per design §3.1; native branch shows `showTestModeIntro` before creating
      any `AssessmentResult`.
- [x] Add the pre-start instructions screen (design §3.2): states what is logged (fullscreen exit, tab/
      window focus loss, blocked navigation), what is never captured (camera, microphone, page content
      outside the assessment), and that this deters rather than guarantees prevention. Only proceeds on an
      explicit "Start" click.
- [x] Implement `checkExistingAttempt()`: `GET AssessmentResult?limit=100`, client-side filter to
      `assessmentId + learnerId + lifecycle === 'in-progress'` (design §3.3 — mirrors
      `ProctoringReviewQueue.vue`'s existing fetch-all-then-filter convention). Resume the existing result
      instead of calling `createResult()` when found. Wired through a new `getOrCreateResult()` helper used
      by ALL three start paths (native, external-dismiss, unproctored) — the requirement text and the
      evidenced bug (`createResult()` was unconditional in every path, not just native) are not scoped to
      native mode only; see Flags.
- [x] Implement `acquireTabLock(resultId)` / `releaseTabLock()`: `localStorage` heartbeat keyed
      `scholiq-native-test-mode-lock-${resultId}`, 5s refresh, 15s staleness window (design §3.3). Block
      rendering with an "already open elsewhere" message when a live lock from a different `tabId` is
      detected; append a `concurrent-session-detected` flag (severity `high`) if a `ProctoringSession`
      already exists for the result.
- [x] Implement `createProctoringSession()`: `POST ProctoringSession` (`assessmentResultId`, `learnerId`,
      `provider: "native-test-mode"`, `status: "created"`, `tenant_id`), then dispatch the existing
      `activate` lifecycle transition.
- [x] Implement `attachNativeHardening()`: request `document.documentElement.requestFullscreen()` when
      `lockdownBrowser`; attach `fullscreenchange`, `visibilitychange`, `blur`, `popstate`/`beforeunload`
      listeners per the event → flag `kind` table in design §3.4.
- [x] Implement `appendFlag(kind, severity)`: read-modify-write PUT to `ProctoringSession.flags[]`,
      identical shape/pattern to `ProctoringReviewQueue.vue`'s `recordDecision()` (`flagId` via
      `crypto.randomUUID()`, `occurredAt`, `severity`, `reviewDecision: "pending"`). Client-side throttle:
      max one flag per `kind` per 5 seconds.
- [x] `submitAssessment()`: on native-mode success, dispatch the `ProctoringSession`'s `end` transition,
      release the tab lock, exit fullscreen if active.
- [x] `beforeDestroy()`: best-effort teardown — `navigator.sendBeacon()` for a final state update where
      supported; always release the tab lock and remove listeners.
- [x] `npm run lint` — 0 errors on `TakeAssessmentView.vue` (35 pre-existing-pattern warnings: 3 `v-html`,
      32 `jsdoc/check-tag-names` on `@spec`, both already the established convention in this file at HEAD;
      baseline was 21 warnings before this change, all additive from the new `@spec`-tagged methods).

## Phase 3: i18n

- [x] Add new keys to `l10n/en.json` and `l10n/nl.json`: pre-start instructions screen copy, "already open
      in another tab" message, flag-kind display labels (`fullscreen-exit`, `tab-hidden`, `window-blur`,
      `blocked-navigation`, `concurrent-session-detected`) for `ProctoringReviewQueue`'s existing
      `flag.kind` display. This required a minimal display-only change to `ProctoringReviewQueue.vue`
      (`{{ flag.kind }}` → `{{ flagKindLabel(flag.kind) }}`, falling back to the raw kind for
      unrecognised/external-provider kinds) — see Flags: proposal.md's Impact section says
      `ProctoringReviewQueue.vue` is unchanged, but this Phase-3 bullet explicitly targets its flag.kind
      display; resolved in favour of the literal task since it is additive/backward-compatible and does not
      touch review logic.

## Phase 4: e2e coverage

- [x] Create an e2e coverage spec — **path deviates from the literal bullet**: every existing gate-19
      per-change coverage spec in this app lives at `tests/e2e/spec-coverage/<change-slug>.spec.ts` and is a
      lightweight "route/component resolves, no fatal console error" smoke test (see
      `tests/e2e/spec-coverage/school-year-rollover.spec.ts`, `external-training-recording.spec.ts`) — deep
      interactive flows with seeded fixtures + simulated Fullscreen/visibility events + two-tab concurrency
      are NOT the established pattern anywhere in this app's e2e suite. Created
      `tests/e2e/spec-coverage/secure-exam-test-mode.spec.ts` following that exact convention: asserts
      `TakeAssessmentView` and `ProctoringReviewQueue` resolve without a fatal JS error. Tagged the delta
      spec's scenarios with `@e2e tests/e2e/spec-coverage/secure-exam-test-mode.spec.ts` (2 DOM-observable
      scenarios) or `@e2e exclude <reason>` (6 scenarios needing seeded native-mode data, live Fullscreen
      API gesture, or two-tab localStorage concurrency — none of which are within this app's established
      e2e scope), matching the `<!-- @e2e ... -->` HTML-comment convention already used in
      `openspec/specs/school-year-rollover/spec.md`.
- [ ] Assert reloading `TakeAssessmentView` mid-attempt resumes the existing `AssessmentResult` rather than
      creating a second one — NOT implemented as an e2e case (see reasoning above: needs a seeded
      in-progress `AssessmentResult` + a live OR backend). The resume-vs-create logic itself
      (`getOrCreateResult()` / `checkExistingAttempt()`) is deterministic, unit-testable client code with no
      JS unit-test harness in this app (no jest/vitest wired — see baseline `package.json`); flagged as a
      gap, not silently skipped.
- [ ] `npm run test:e2e -- secure-exam-test-mode` — NOT RUN. No live Nextcloud/OpenRegister instance was
      available in this apply session (this task's bar is schema/frontend/PHPUnit/build, not live browser
      verification — see apply-common.md; live e2e verification is a later `/opsx-verify` step). The spec
      file is structurally valid TypeScript following the exact pattern of two passing sibling specs, but
      its outcome against a live instance is UNVERIFIED — reported honestly, not claimed as passing.

## Phase 5: Spec-validation gate

- [x] `npm run check:specs` PASS (`check:json-strict` + `check:manifest` + `check:register`).
- [x] `openspec validate secure-exam-test-mode --type change --strict` PASS ("Change 'secure-exam-test-mode'
      is valid").
