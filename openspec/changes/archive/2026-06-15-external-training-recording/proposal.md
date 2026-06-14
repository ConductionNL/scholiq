## Why

Scholiq's wedge promise is a *live coverage % per regulation* and an *audit-pack export that survives external auditor scrutiny*. Both are computed exclusively over in-app data (Enrolment, Attestation, Credential, xAPI statements). Real compliance programmes are not in-app-only: boards take classroom NIS2 awareness sessions from external trainers, BHV/EHBO and VOG-adjacent trainings are delivered by third parties, teachers earn herregistratie points externally, and corporate buyers run instructor-led training that never touches the LMS. Today a compliance officer whose board did a classroom NIS2 training sees **red coverage they cannot fix** — or worse, fakes an in-app enrolment to make the dashboard green, which corrupts the evidence log. The 2026-06-11 feature re-evaluation lists this as expected-gap #3: a "record externally-completed training with evidence attachment" path is needed for **honest audit packs**.

This is also a category-standard feature: every corporate LMS (SAP SuccessFactors, Cornerstone, Docebo) has "external learning / instructor-led completion with evidence", and Dutch school boards track external nascholing per teacher.

## What Changes

- **New OR schema `ExternalTrainingRecord`**: `learnerId`, `title`, `provider`, `kind` (`classroom | external-elearning | conference | on-the-job | other`), optional `regulationSlug` (links into compliance coverage), optional `courseId` (equivalence with an in-app course), `completedAt`, optional `validUntil`, `evidenceNote`, `submittedBy`, `verifiedBy`, `rejectionReason`, optional `credentialId`, lifecycle (`submitted → verified | rejected`), `tenant_id`. Evidence files (certificate scan, signed attendance list) are **OpenRegister file attachments** on the record — no bytes in the app, mirroring the Material pattern.
- **Verification gate**: a record only counts once a `compliance-officer`/`hr`/`admin` user verifies it (lifecycle transition with evidence attached as a precondition). Self-reported, unverified records never influence coverage.
- **Coverage integration (compliance-audit)**: a learner counts as covered for a Regulation when they have EITHER the existing in-app evidence (signed Attestation / valid Credential) OR a `verified` ExternalTrainingRecord with a matching `regulationSlug` whose `validUntil` (if set) has not passed. The audit-pack ZIP gains an `external-training.csv` plus the evidence attachments for the selected regulation/date range, clearly separated from in-app attestations.
- **Bulk entry for classroom sessions**: record one training (title/provider/date/regulation) for many learners at once (multi-select or CSV), creating one record per learner sharing the same evidence attachment — the bulk-enrolment pattern applied to completions. The classroom event itself is scheduled in **NC Calendar** if the school wants it on a calendar; Scholiq does not grow an event schema for this.
- **Optional credential issuance**: on verification, the verifier MAY issue a linked manual `Credential` (existing `source: manual` issuance path, `expiresAt = validUntil`) so the certification capability's existing expiry machinery (tiered expiry alerts, renewal auto-enrol) covers external certificates too. No Credential schema change.
- **Notifications** (verified dialect, per the in-flight `scholiq-notifications` migration — referenced, not re-specified): `submitted` → verifier groups; `verified`/`rejected` transitions → `submittedBy`.
- **Declarative UI**: manifest index/detail pages for ExternalTrainingRecord + a "Record external training" action on the learner and regulation views; custom view only for the bulk-entry form if a manifest page cannot express it.

## Capabilities

### New Capabilities

- `external-training-recording`: capture externally-completed training (classroom, third-party, conference) per learner with evidence file attachments and an officer verification gate, so external training is first-class compliance evidence.

### Modified Capabilities

- `compliance-audit`: coverage % computation and the audit-pack ZIP MUST include `verified` external training records (delta spec adds this as a new requirement; existing requirements are unchanged).

## Impact

- `lib/Settings/scholiq_register.json` — new `ExternalTrainingRecord` schema + lifecycle + notification rules.
- Coverage computation (compliance-audit service) — denominator unchanged, numerator gains the verified-external-record predicate.
- Audit-pack exporter — one new CSV artefact + evidence-attachment inclusion.
- `src/manifest.json` — index/detail pages + entry actions; possible custom bulk-entry view.
- Reuses unchanged: OR file attachments, Credential manual issuance + expiry notifications, Regulation schema, NC Calendar for scheduling.
- No change to Attestation or the evidence-log semantics: external records are a *separate, clearly-labelled* evidence class, never synthesised attestations.
