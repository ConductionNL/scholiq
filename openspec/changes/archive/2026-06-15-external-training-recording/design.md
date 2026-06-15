## Context

Compliance coverage in Scholiq is computed from in-app artefacts: Enrolments against a Regulation's course set, HMAC-signed Attestations, and Credentials with expiry. The integrity story (ADR-008) is strong precisely because attestations are machine-captured at the moment of completion with provenance (timestamp, IP, xAPI statement). External training breaks that model: the completion happened outside, so the evidence is a document (certificate, signed attendance list) plus a human assertion. The design problem is admitting that evidence class into coverage and audit packs **without diluting** the signed-attestation guarantees.

Constraints honoured:

- Storage, lifecycle, RBAC, file attachments, notifications: OpenRegister (ADR-022); evidence bytes are OR file attachments (Material precedent), never app-stored.
- Notification rules use the verified dialect owned by `scholiq-notifications` (referenced, not duplicated).
- Classroom events are NC Calendar items if scheduled at all; no Scholiq event schema.
- No Attestation forgery: external records are a separate evidence class with their own lifecycle and labelling.

## Goals / Non-Goals

**Goals:**
- Honest coverage %: verified external training counts, clearly distinguishable from in-app evidence.
- Auditor-grade trail: who submitted, who verified, what evidence, when — all OR audit-trail-backed.
- Low-friction bulk entry for instructor-led sessions (the dominant real-world case).
- Expiry continuity: external certificates with validity windows feed the existing certification expiry machinery via optional manual Credential issuance.

**Non-Goals:**
- Importing external LMS completions over the wire (xAPI/LTI/UWLR adapters are OpenConnector/data-exchange territory; this change is the *manual + evidence* path).
- OCR/auto-validation of certificates.
- External-provider catalogue management (provider is a free-text/typeahead field, not a schema).
- Changing Attestation semantics or the signed evidence log.

## Decisions

**D1: Separate `ExternalTrainingRecord` schema, not a synthetic Attestation or Enrolment.**
Attestations are signed, machine-captured artefacts; faking one for external training would corrupt the ADR-008 evidence model. A distinct schema keeps the evidence classes honest, lets audit packs label them separately, and avoids polluting Enrolment funnels and dashboards.

**D2: Verification gate is a lifecycle transition with an evidence precondition.**
`submitted → verified` requires (a) actor in `compliance-officer`/`hr`/`admin`, (b) at least one evidence file attachment present, (c) `verifiedBy ≠ submittedBy` when the submitter is the learner themselves (no self-verification). Declared as an `x-openregister-lifecycle` transition guard, mirroring the AttestationSigningGuard pattern.

**D3: Coverage predicate is OR-of-evidence, validity-window aware.**
Covered(learner, regulation) = signed Attestation ∨ valid Credential ∨ (`verified` ExternalTrainingRecord with matching `regulationSlug` ∧ (`validUntil` unset ∨ `validUntil` ≥ today)). The coverage UI shows the evidence class per learner so an auditor can see *why* someone counts.

**D4: Bulk entry creates per-learner records sharing one evidence attachment.**
A signed attendance list covers N learners; the bulk form creates N `ExternalTrainingRecord`s referencing the same OR file attachment. Verification of the batch is a single action that transitions all records (one audit entry per record).

**D5: Optional manual Credential on verification, no schema change.**
Where `validUntil` is set (BHV, certified NIS2 training), the verifier can issue a `Credential` via the existing manual path (`source: manual`, `expiresAt = validUntil`, `regulationSlug` carried over) and the record stores `credentialId`. Expiry alerts and renewal auto-enrol then come for free from certification. Where no validity applies, no credential is issued.

**D6: Audit-pack separation.**
The ZIP gains `external-training.csv` (record fields + verifier + evidence file references) and a folder with the evidence attachments. In-app attestation artefacts are untouched; the pack README/manifest names the two evidence classes.

**D7: Notifications: two rules, verified dialect.**
`submitted` (trigger `created`, recipients `kind: groups` [`compliance-officer`, `hr`]) and `decided` (trigger `transition` on `verify`/`reject`, recipient `kind: field` `submittedBy`). Subjects inline `{nl,en}`. Same group-provisioning caveat as `scholiq-notifications`.

## Risks / Trade-offs

- **Evidence-quality laundering** (green dashboard via rubber-stamp verification) → the verification actor + evidence are first-class audit-pack content; an auditor can reject weak evidence, which is exactly the honest outcome. No-self-verification (D2) closes the cheapest abuse.
- **Coverage-computation drift** — touching the coverage predicate risks regressions on the wedge's core number → spec'd as a delta on compliance-audit with explicit scenarios; PHPUnit coverage on the predicate for all three evidence classes.
- **Bulk verification scale** — hundreds of records per batch; transitions run server-side in one job, per-record audit entries preserved.
- **Privacy of evidence files** — attendance lists contain other people's names; evidence attachments inherit OR object RBAC (officer/HR/admin + the learner's own record), never public. Flag in the AVG verwerkingsregister seed (training administration entry) if both changes land.

## Migration Plan

1. Add `ExternalTrainingRecord` schema + lifecycle guards + notification rules to `lib/Settings/scholiq_register.json`.
2. Extend the coverage predicate + per-learner evidence-class display.
3. Extend the audit-pack exporter (`external-training.csv` + evidence folder).
4. Add manifest pages + bulk-entry flow + entry actions on learner/regulation views.
5. nl/en i18n; PHPUnit (predicate, guards) + Playwright (submit → verify → coverage flips) tests.
6. Bump `appinfo/info.xml` version.

Rollback: remove the schema and the predicate extension; coverage reverts to in-app-only (records remain as inert OR objects).

## Open Questions

- Should `rejected` records be resubmittable (new lifecycle edge `rejected → submitted`) or require a fresh record? Lean: fresh record, keeps the trail simple. Decide at apply time.
