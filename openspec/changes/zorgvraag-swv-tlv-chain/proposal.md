---
kind: code
depends_on: []
---

## Why

`learning-plan` (`status: done`) builds the OPP/handelingsplan itself — goals, support measures, a
review cycle, and co-signing — but its own **Out of Scope** section says so explicitly:
"The samenwerkingsverband (collaboration-network) funding flow that some OPPs trigger — out of scope;
a follow-up if a buyer needs it" (`openspec/specs/learning-plan/spec.md:99`). That follow-up is the
actual mechanism **Wet passend onderwijs** runs on: a school signals a `zorgvraag` (support/care
request) to its regional samenwerkingsverband (SWV) when a pupil's needs exceed what the school can
provide alone; the SWV convenes a deliberation with parents, the school, the municipality, and care
partners; and — if extra provision is warranted — issues a `toelaatbaarheidsverklaring` (TLV) for a
bounded validity period that entitles the pupil to a specific special-education arrangement. None of
that exists at HEAD:

- **No `SupportRequest`, `TlvApplication`, or `DeliberationRecord` schema anywhere.** Repo-wide grep
  for `SupportRequest|TlvApplication|toelaatbaarheid|zorgvraag|DeliberationRecord|samenwerkingsverband|
  \bSWV\b|\bTLV\b` across `lib/Settings/scholiq_register.json`, every `openspec/specs/*/spec.md`, and
  `src/manifest.json` returns exactly one hit — the `learning-plan` Out-of-Scope line quoted above.
  There is no manifest page, no register schema, nothing.
- **The seam this change must reuse already exists and is proven.** `data-exchange`'s `DataExchangeJob`
  schema (`lib/Settings/scholiq_register.json:7424-7647`) already models exactly the shape a zorgvraag
  needs: a `target` naming an OpenConnector connection (`bron-rod`, `oso`, `leerplicht`, `surfconext`,
  `hr` today — `lib/Settings/scholiq_register.json:7479-7483`), a `pending-parent-review` lifecycle gate
  that the OSO PO→VO overstapdossier already uses before the wire send proceeds
  (`lib/Settings/scholiq_register.json:7627-7672`, transitions `pendingParentReview` →
  `approveDossier`, guarded by `OCA\Scholiq\Lifecycle\OsoDossierReviewGuard`), and a `DataMappingProfile`
  for field-level whitelisting (`openspec/specs/data-exchange/spec.md:24`). The
  `data-exchange` spec's own requirement "Delegate wire protocols to OpenConnector"
  (`openspec/specs/data-exchange/spec.md:54-60`) already commits Scholiq to zero inline OSO/Edukoppeling
  wire code and names the adapter issues as living on `ConductionNL/openconnector`. This change's SWV
  routing is a new named `target` (`swv`) on the *same* `DataExchangeJob`/`DataMappingProfile` pair —
  not a new mechanism — and the corresponding OpenConnector adapter work is tracked as
  `openconnector#753` (extends the existing OSO/Edukoppeling adapter scope the data-exchange spec
  already delegates to).
- **The `learning-plan` `Signature` co-sign pattern is proven and reusable for TLV/deliberation
  sign-off.** `Signature` (`lib/Settings/scholiq_register.json:6263-6300+`) is `appendOnly`, references a
  specific `LearningPlan` version, and records an assurance level per `openspec/specs/learning-plan/
  spec.md:70-76` ("Signing assurance level is declarative config"). A TLV decision record and a
  deliberation outcome need the identical append-only, versioned, assurance-aware co-sign shape.
- **The 2025 pupil-hoorrecht is a MUST, not a nice-to-have, and nothing in the app captures it for the
  zorgvraag/TLV/deliberation chain.** Insight 1145 (impact: high): "the Wet versterking positie ouders
  en leerlingen in passend onderwijs (in force 2025) adds hoorrecht for pupils on their OPP and requires
  the support offering in the schoolgids... must model the handelingsdeel consent as a distinct
  signature step for parents, pupil-voice notes." `learning-plan` already models the parent consent
  half (`Signature`); it does not model the pupil's own voice as a first-class, non-optional field on
  the deliberation that leads to a TLV decision — the exact gap this insight flags.
  `openspec/specs/data-exchange/spec.md:70-76` ("OSO parent-review is a lifecycle gate") likewise only
  gates on *parent* approval; hoorrecht requires the pupil's own voice to be captured independently.
- **Competitor evidence: this is the SWV-side half every K-12 LAS incumbent already ships school-side.**
  Kindkans (SWV-side incumbent, `kindkans.nl`) ships features 32376 "Hulpvraag intake direct from
  ParnasSys/ESIS/Magister/Somtoday" (IB'ers/zorgcoördinatoren submit support requests to the SWV
  straight from their own SIS), 32378 "TLV (toelaatbaarheidsverklaring) workflow" (assignment and
  administration of TLVs and educational arrangements), and 32380 "Online deliberation rounds with
  stakeholders" (structured online consultation with educators, parents, municipalities, and care
  services). ParnasSys itself (competitor 921, ~65% PO market share per `learning-plan/spec.md:19`)
  ships feature 31978 "OSO care request to samenwerkingsverband" (send an OSO-based zorgvraag with
  attached dossier to a regional SWV), and ESIS/Cito (competitor 922) ships feature 32003 "OSO dossier
  attached to care requests" (the pupil's OSO dossier auto-attaches). The market-side incumbents Scholiq
  competes against for the K-12 LVS phase-2 play already ship the exact school-side intake this change
  builds — Scholiq has none of it.

This is a genuine MUST/SHOULD gap, not a documentation nit: `learning-plan` explicitly named this as
deferred scope, the seam it should reuse (`DataExchangeJob` + `pending-parent-review` + `Signature`) is
already built and battle-tested by the OSO overstap flow, and the 2025 hoorrecht law makes the pupil-voice
half a compliance requirement, not an optional UX nicety.

## What Changes

- **`SupportRequest`** (zorgvraag) — new OpenRegister schema in the `learning-plan` capability. Raised
  from a pupil's dossier by a coordinator (`raisedBy`, an NC user ID — same convention as
  `LearningPlan.coordinatorId`), it names the learner, an optional linked `LearningPlan` (a zorgvraag
  can precede an OPP, so the link is nullable), a `supportDomain`/description of the hulpvraag, and an
  `urgency`. Lifecycle: `draft → submitted → routed-to-swv → in-deliberation → decided → closed`.
- **Routing to the SWV reuses `data-exchange`'s `DataExchangeJob`, not a new wire mechanism.** Submitting
  a `SupportRequest` auto-queues a `DataExchangeJob` with `target: swv`, `scope.schema:
  support-request`, composing the OSO-format care-request dossier from the linked `SupportRequest` +
  `LearnerProfile` + (if present) `LearningPlan` data — the same composition pattern the OSO PO→VO
  overstapdossier already uses. The job enters `pending-parent-review` before the SWV send, identically
  to the existing OSO gate. The wire protocol itself is an OpenConnector adapter (`openconnector#753`,
  extending the existing OSO/Edukoppeling adapter scope) — Scholiq implements no XML/wire code, per the
  data-exchange spec's existing "Delegate wire protocols to OpenConnector" requirement.
- **`TlvApplication`** — the toelaatbaarheidsverklaring application, linked to the `SupportRequest` that
  triggered it. Records the requested arrangement type, the SWV's case reference, and — once decided —
  the `decision` (`approved | rejected | conditional`), `validFrom`/`validUntil`, and a
  `decisionDocumentRef` (OR file attachment). Scholiq records the SWV's decision; it does not adjudicate
  it. `validUntil` drives a declared `tlvExpiringSoon` calculation trigger (reusing the same
  `calculatedChange` machinery as `attendance`'s threshold triggers and `certification`'s renewal
  reminders — no PHP TimedJob).
- **`DeliberationRecord`** — a structured, append-only record of a consultation round (parents,
  municipality, care partners, school, SWV coordinator), linked to a `SupportRequest`/`TlvApplication`.
  Carries `attendees[]` (role-tagged), an `outcome`/recommendation, and a first-class `pupilVoice` block
  (`heard: boolean`, `statementNote`, or an explicit `waived` + `waiverReason` for cases where hearing
  the pupil directly is not appropriate — e.g. very young children) reflecting the 2025 hoorrecht
  (insight 1145). A lifecycle guard blocks a `DeliberationRecord` from reaching `recorded` unless
  `pupilVoice.heard` is true or `pupilVoice.waived` carries a reason — mirroring how
  `LearningPlanSignatureGuard` blocks `LearningPlan.activate` without the required signatures.
- **Minimal disclosure to the SWV**: the `DataMappingProfile` for `target: swv` is a field whitelist —
  only the fields the OSO care-request schema requires leave the tenant; full `LearnerProfile` /
  `LearningPlan` objects are never handed to OpenConnector wholesale. See `design.md` for the field list
  and the RBAC model (zorg data is the most sensitive category this app handles).
- **Frontend**: declarative `src/manifest.json` pages for `SupportRequest`/`TlvApplication`/
  `DeliberationRecord` index+detail (same `<Schema>s`/`<Schema>Detail` convention as `LearningPlans`/
  `LearningPlanDetail`), plus reuse of the existing `OsoDossierReviewView` custom view
  (`CnStructuredDocReview`, `src/manifest.json:6094`) for the SWV dossier review step rather than a new
  bespoke component. No PHP CRUD controllers (ADR-022).

## Impact

- `openspec/specs/learning-plan/spec.md` — ADDED requirements: `SupportRequest`/`TlvApplication`/
  `DeliberationRecord` persistence, SWV routing via `DataExchangeJob`, TLV expiry as a declared
  calculation, pupil-hoorrecht as a lifecycle-gated field, minimal-disclosure mapping, declarative
  frontend.
- `openspec/specs/data-exchange/spec.md` — MODIFIED requirements: "Delegate wire protocols to
  OpenConnector" extended to name the `swv` target (`openconnector#753`); "OSO parent-review is a
  lifecycle gate" extended to note the same gate applies when the dossier scope is `support-request`.
- `lib/Settings/scholiq_register.json` — new schemas `SupportRequest`, `TlvApplication`,
  `DeliberationRecord`; extended `DataExchangeJob.target`/`DataMappingProfile` scope (implementation-time
  — see `tasks.md`).
- `src/manifest.json` — new index/detail pages; reuses `OsoDossierReviewView`.
- No PHP service classes beyond the existing ADR-031 "external-system bridge" exception already granted
  to `data-exchange`'s job-execution handler — this change extends that handler's `target` switch, it
  does not add a new one.
