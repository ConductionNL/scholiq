# Tasks — Secure Exam Test Mode (native, provider-free delivery hardening)

> Scope: 1 schema delta (`Assessment.proctoring` + `ProctoringSession` authorization), 0 new PHP, 1 modified
> Vue view (`TakeAssessmentView.vue`), l10n, e2e coverage. `ProctoringReviewQueue.vue` is unchanged (already
> generic).

## Phase 1: Schema — `lib/Settings/scholiq_register.json`

- [ ] Add `nativeTestMode` (boolean, default `false`) and `navigationLock` (boolean, default `true`) to the
      `Assessment.proctoring` object (design §4.1), alongside the existing `provider` / `lockdownBrowser` /
      `recordWebcam` / `flagReviewMode` properties.
- [ ] Update `lockdownBrowser`'s `description` to add the native-mode clause (Fullscreen API, not an
      OS-level kiosk client).
- [ ] Update `recordWebcam`'s `description` to state it does not apply when `nativeTestMode` is set.
- [ ] Add `x-openregister-authorization: { "create": ["user"] }` to the `ProctoringSession` schema (design
      §4.2), matching the `XapiStatement` precedent.
- [ ] Validate JSON (`python3 -c 'import json; json.load(open("lib/Settings/scholiq_register.json"))'`); run
      `npm run check:json-strict` and `npm run check:register` — both PASS, no duplicate slugs, no broken
      lifecycle/`requires` references.

## Phase 2: Frontend — `src/views/TakeAssessmentView.vue`

- [ ] Add `showTestModeIntro`, `nativeTestModeActive`, `proctoringSession`, `tabId`, `tabLockInterval` to
      `data()`.
- [ ] `init()`: branch on `assessment.proctoring.nativeTestMode` (external `provider` still wins if both are
      set — log a `console.warn`) per design §3.1; native branch shows `showTestModeIntro` before creating
      any `AssessmentResult`.
- [ ] Add the pre-start instructions screen (design §3.2): states what is logged (fullscreen exit, tab/
      window focus loss, blocked navigation), what is never captured (camera, microphone, page content
      outside the assessment), and that this deters rather than guarantees prevention. Only proceeds on an
      explicit "Start" click.
- [ ] Implement `checkExistingAttempt()`: `GET AssessmentResult?limit=100`, client-side filter to
      `assessmentId + learnerId + lifecycle === 'in-progress'` (design §3.3 — mirrors
      `ProctoringReviewQueue.vue`'s existing fetch-all-then-filter convention). Resume the existing result
      instead of calling `createResult()` when found.
- [ ] Implement `acquireTabLock(resultId)` / `releaseTabLock()`: `localStorage` heartbeat keyed
      `scholiq-native-test-mode-lock-${resultId}`, 5s refresh, 15s staleness window (design §3.3). Block
      rendering with an "already open elsewhere" message when a live lock from a different `tabId` is
      detected; append a `concurrent-session-detected` flag (severity `high`) if a `ProctoringSession`
      already exists for the result.
- [ ] Implement `createProctoringSession()`: `POST ProctoringSession` (`assessmentResultId`, `learnerId`,
      `provider: "native-test-mode"`, `status: "created"`, `tenant_id`), then dispatch the existing
      `activate` lifecycle transition.
- [ ] Implement `attachNativeHardening()`: request `document.documentElement.requestFullscreen()` when
      `lockdownBrowser`; attach `fullscreenchange`, `visibilitychange`, `blur`, `popstate`/`beforeunload`
      listeners per the event → flag `kind` table in design §3.4.
- [ ] Implement `appendFlag(kind, severity)`: read-modify-write PUT to `ProctoringSession.flags[]`,
      identical shape/pattern to `ProctoringReviewQueue.vue`'s `recordDecision()` (`flagId` via
      `crypto.randomUUID()`, `occurredAt`, `severity`, `reviewDecision: "pending"`). Client-side throttle:
      max one flag per `kind` per 5 seconds.
- [ ] `submitAssessment()`: on native-mode success, dispatch the `ProctoringSession`'s `end` transition,
      release the tab lock, exit fullscreen if active.
- [ ] `beforeDestroy()`: best-effort teardown — `navigator.sendBeacon()` for a final state update where
      supported; always release the tab lock and remove listeners.
- [ ] `npm run lint` — 0 errors on `TakeAssessmentView.vue`.

## Phase 3: i18n

- [ ] Add new keys to `l10n/en.json` and `l10n/nl.json`: pre-start instructions screen copy, "already open
      in another tab" message, flag-kind display labels (`fullscreen-exit`, `tab-hidden`, `window-blur`,
      `blocked-navigation`, `concurrent-session-detected`) for `ProctoringReviewQueue`'s existing
      `flag.kind` display.

## Phase 4: e2e coverage

- [ ] Create `tests/e2e/assessment-native-test-mode.spec.ts`: seed an Assessment with
      `proctoring.nativeTestMode: true`, drive `TakeAssessmentView` through the pre-start screen, assert a
      `fullscreen-exit` or `tab-hidden` flag lands in the created `ProctoringSession` after simulating the
      corresponding browser event, and assert the flag appears in `ProctoringReviewQueue` with
      `reviewDecision: "pending"`.
- [ ] Extend (or add a case to) the e2e suite: assert reloading `TakeAssessmentView` mid-attempt resumes the
      existing `AssessmentResult` rather than creating a second one.
- [ ] `npm run test:e2e -- assessment-native-test-mode` PASS.

## Phase 5: Spec-validation gate

- [ ] `npm run check:specs` PASS (`check:json-strict` + `check:manifest` + `check:register`).
- [ ] `openspec validate secure-exam-test-mode` PASS.
