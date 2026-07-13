# Design: zorgvraag-swv-tlv-chain

## Architecture Overview

This change closes the gap `learning-plan/spec.md:99` named and deferred: the samenwerkingsverband (SWV)
support chain that Wet passend onderwijs actually requires beyond the OPP itself. It adds three new
`learning-plan`-capability schemas (`SupportRequest`, `TlvApplication`, `DeliberationRecord`) and reuses
two already-built mechanisms rather than inventing new ones:

1. **`data-exchange`'s `DataExchangeJob` + `pending-parent-review` gate** — proven by the existing OSO
   PO→VO overstapdossier flow (`lib/Settings/scholiq_register.json:7424-7770`). SWV routing is a new
   `target: swv` value on the same schema, not a new job type.
2. **`learning-plan`'s append-only, assurance-aware co-sign pattern** (`Signature`,
   `LearningPlanEvaluation`) — reused conceptually for `DeliberationRecord`'s immutability and for the
   pupil-hoorrecht capture.

```
Pupil dossier
  └─ coordinator raises SupportRequest (draft)
       │  optional link → existing LearningPlan (OPP)
       ▼
  submit ──────────────────────────────────────────────► DataExchangeJob
                                                             target: swv
                                                             scope.schema: support-request
                                                             │
                                                        pending-parent-review
                                                        (same gate as target: oso)
                                                             │ parent approves
                                                             ▼
                                                        running → succeeded
                                                        (OpenConnector swv adapter,
                                                         openconnector#753)
       │
  SupportRequest → routed-to-swv
       │
       ▼
  DeliberationRecord(s)                         ◄── attendees: parent, PUPIL, municipality,
    appendOnly, pupilVoice{heard|waived}             care-partner, school, swv-coordinator
       │
       ▼
  TlvApplication (linked to SupportRequest)
    under-review → decided(approved|rejected|conditional)
    validFrom/validUntil → tlvExpiringSoon (declared calculation)
```

## Data Model

All new objects live in OpenRegister, in the `learning-plan` capability (same register as `LearningPlan`,
`Signature`, `LearningPlanEvaluation`). No new PHP database tables (ADR-001).

### SupportRequest

| Field | Type | Notes |
|---|---|---|
| `learnerId` | string | NC user ID, same convention as `LearningPlan.learnerId` |
| `learningPlanId` | string, uuid, `$ref: LearningPlan`, nullable | Optional — a zorgvraag can precede the OPP |
| `raisedBy` | string | NC user ID of the coordinator/IB'er, same convention as `LearningPlan.coordinatorId` |
| `supportDomain` | string | Plain-language hulpvraag area |
| `description` | string | The support request narrative |
| `urgency` | enum: `low\|medium\|high` | |
| `tenant_id` | string, uuid | Required, multi-tenant isolation |
| `lifecycle` | enum: `draft\|submitted\|routed-to-swv\|in-deliberation\|decided\|closed` | |
| `dataExchangeJobId` | string, uuid, `$ref: DataExchangeJob`, nullable | Set when the SWV job is auto-queued |

### TlvApplication

| Field | Type | Notes |
|---|---|---|
| `supportRequestId` | string, uuid, `$ref: SupportRequest` | Required — a TLV always traces to a SupportRequest |
| `arrangementType` | string | Requested special-education arrangement |
| `swvCaseReference` | string, nullable | The SWV's own case number, set once known |
| `decision` | enum: `approved\|rejected\|conditional`, nullable | Null until the SWV decides |
| `validFrom` / `validUntil` | date, nullable | Set only once `decision` is recorded |
| `decisionDocumentRef` | string, nullable | OR file attachment reference |
| `tenant_id` | string, uuid | |
| `lifecycle` | enum: `draft\|submitted\|under-review\|decided\|expired` | |

### DeliberationRecord

| Field | Type | Notes |
|---|---|---|
| `supportRequestId` / `tlvApplicationId` | string, uuid, nullable each | At least one MUST be set |
| `attendees` | array of `{ role: enum(parent\|pupil\|municipality\|care-partner\|school\|swv-coordinator), name/refId }` | |
| `scheduledAt` / `recordedAt` | date-time | |
| `outcome` | string | Recommendation / conclusion |
| `pupilVoice` | object `{ heard: boolean, statementNote: string\|null, waived: boolean, waiverReason: string\|null }` | See guard below |
| `tenant_id` | string, uuid | |
| `lifecycle` | enum: `scheduled\|recorded` | `appendOnly: true` once `recorded` |

### DataExchangeJob / DataMappingProfile extension (implementation-time, `data-exchange` register)

- `DataExchangeJob.target` gains `swv` as a valid value alongside the existing `bron-rod\|oso\|leerplicht\|
  surfconext\|hr` (`lib/Settings/scholiq_register.json:7479-7483`).
- `DataExchangeJob.scope.schema` gains `support-request` as a valid scope schema.
- A new `DataMappingProfile` row (admin-configured or shipped, same as the existing BRON/ROD/OSO
  profiles) maps `SupportRequest`/`LearnerProfile`/`LearningPlan` fields → the OSO care-request dossier
  schema, whitelist-only (see Security Considerations).
- The `x-openregister-lifecycle` `pendingParentReview`/`approveDossier` transitions
  (`lib/Settings/scholiq_register.json:7648-7672`) are reused unmodified — no new transition names, no
  new guard class beyond generalising `OsoDossierReviewGuard` to also match `target: swv` jobs (or, if
  the guard is target-specific by design at implementation time, adding a sibling guard that shares the
  same review-gate contract).

## Decisions

### Reuse `DataExchangeJob` for SWV routing rather than a new job schema

**Chosen**: `target: swv` on the existing `DataExchangeJob`/`DataMappingProfile` pair.
**Rejected**: a parallel `SwvRoutingJob` schema. The SWV zorgvraag is, structurally, exactly what
`DataExchangeJob` already models — an export to a named OpenConnector connection, gated by parent review,
delegating the wire protocol out. A second schema would duplicate the lifecycle, the
`pending-parent-review` gate, and the audit-trail emission `data-exchange` already provides, which is the
exact anti-pattern ADR-022 (apps consume OR abstractions) and the redundant-controller gate exist to
catch — except here it would be a redundant *schema*, not a redundant controller, but the same waste.

### SupportRequest as a standalone schema, not a LearningPlan subtype

**Chosen**: `SupportRequest` is its own OpenRegister object with an optional `learningPlanId` link.
**Rejected**: embedding the zorgvraag as a state/section inside `LearningPlan`. A zorgvraag frequently
*precedes* the OPP — the coordinator raises it before there is a plan to attach it to (the SWV's
deliberation may be what determines whether an OPP is even warranted, or what kind). Making it a
`LearningPlan` field would force a `LearningPlan` to exist before a school could even ask the SWV for
help, which is backwards from how the law and the competitor flows (Kindkans feature 32376: intake
"direct from ParnasSys/ESIS/Magister/Somtoday", i.e. from the pupil record, not from an OPP) actually
work.

### TLV decision recorded, never adjudicated

**Chosen**: `TlvApplication.decision` is written by a coordinator recording what the SWV decided; no
approval/rejection logic lives in Scholiq.
**Rejected**: any rule-based or AI-assisted TLV recommendation engine inside Scholiq. The TLV is a
legally binding decision made by the SWV as an external, statutory authority (Wet passend onderwijs) —
Scholiq automating or predicting it would be, at minimum, scope creep into a decision the school does not
have the authority to make, and at worst a compliance risk if a prediction were mistaken for an actual
determination. If AI-assisted drafting of the *deliberation recommendation* is ever wanted, that would be
a separate `AiFeature` registration (per the existing `ai-surface` pattern) — explicitly out of scope
here.

### Pupil-hoorrecht as a lifecycle-gated field, not a free-text note

**Chosen**: `pupilVoice.heard`/`waived` is a structured boolean pair with a guard blocking
`DeliberationRecord.recorded` until one is satisfied — mirroring `LearningPlanSignatureGuard`'s pattern of
blocking `LearningPlan.activate` without required signatures.
**Rejected**: a free-text field the coordinator fills in "when relevant." Insight 1145 is explicit that
the 2025 law *strengthens* the pupil's position — a soft, optional field would let the hoorrecht be
silently skipped exactly as easily as before the law changed, defeating the point of modelling it at all.
A hard lifecycle guard makes the omission visible and requires an explicit, recorded reason (`waived` +
`waiverReason`) rather than a silent gap.

### Minimal disclosure via DataMappingProfile whitelist, not object-level ACLs

**Chosen**: the `swv` `DataMappingProfile` is a field-level whitelist (same mechanism `data-exchange`
already uses for BRON/ROD/OSO), so only OSO-schema-required fields ever leave the tenant boundary.
**Rejected**: sending the full `LearnerProfile`/`LearningPlan`/`SupportRequest` objects and relying on the
SWV-side system to discard what it doesn't need. Zorg data is the most sensitive category this
application handles (support needs, diagnoses referenced in `description`/`supportDomain`, family
circumstances discussed in deliberations) — over-disclosure to a third-party organisation is an AVG
minimal-disclosure violation regardless of what the receiving system does with the surplus. This mirrors
the existing `openspec/specs/data-exchange/spec.md:24` `DataMappingProfile` design, applied to the most
sensitive data category the app carries.

## Security Considerations

- **RBAC on SupportRequest/TlvApplication/DeliberationRecord creation and read**: creation and
  full-detail read restricted to the coordinator role (same NC-user-id convention as
  `LearningPlan.coordinatorId` — not gated by the `LearnerProfile.roles` enum, which has no
  zorgcoördinator-specific role today; a fleet-wide role addition is out of scope for this change), plus
  `admin`/`principal`. This follows the existing `x-openregister-authorization` pattern used elsewhere in
  the register (e.g. `lib/Settings/scholiq_register.json:1281-1285`'s stopgap-then-relax pattern for
  `xapi-statement` — here the restriction is the intended steady state, not a stopgap, given the data
  sensitivity).
- **Parent read is field-projected, not full-object**: a parent viewing their child's `SupportRequest`/
  `TlvApplication`/`DeliberationRecord` (via the portal, once/if a `portal-contribution` provider is
  built for this capability — not in this change's scope) MUST see a projection that drops internal
  coordinator notes and any care-partner-only fields, following the same whitelist-projection pattern
  `portal-contribution`/`portal-parent` already use for grade/attendance/excuse reads. This is a
  design constraint for any future portal provider, not a controller built in this change (no
  PHP CRUD controllers per ADR-022).
- **Pupil-facing visibility**: `pupilVoice.statementNote` is the pupil's own recorded voice and MUST be
  visible to the pupil (where the pupil has portal access); other attendees' contributions in the same
  `DeliberationRecord` are coordinator/staff-facing only, following the same field-level split as the
  parent projection above.
- **Fail-closed on the SWV export**: absent/nullable `learningPlanId` on `SupportRequest`, an unset
  `DataMappingProfile`, or a `TlvApplication` with no linked `SupportRequest` all MUST yield no export —
  never a wider one. This mirrors the existing `data-exchange` "never widen" posture already established
  for OSO/BRON-ROD jobs.
- **Audit**: every `SupportRequest`/`TlvApplication` lifecycle transition and every `DeliberationRecord`
  creation emits an OR audit-trail entry (ADR-008), matching `DataExchangeJob`'s existing "every
  transition is audited" requirement.
- **No BSN exposure**: the SWV dossier composition MUST NOT include `LearnerProfile.bsnEncrypted` unless
  the OSO care-request schema itself requires the encrypted form for identity matching — if so, it MUST
  remain encrypted end-to-end and never appear in plaintext in the `DataExchangeJob.result`/
  `errorMessage` fields (which are visible to the requesting coordinator).

## API / Nextcloud Integration

None beyond what `data-exchange`'s existing job-execution handler already does (the single ADR-031
"external-system bridge" exception granted to that handler is extended to switch on `target: swv`, not
duplicated). No new controllers, no new routes beyond what OpenRegister's generic object API already
serves for the three new schemas (ADR-022).

## Trade-offs

- **`swv` as a new `DataExchangeJob.target` vs a dedicated schema** — chosen for reuse; the cost is that
  `DataExchangeJob`'s `target` enum keeps growing (now six values), which is an acceptable, low-friction
  extension point rather than schema proliferation.
- **Pupil-hoorrecht enforced by lifecycle guard vs a review-time checklist item** — the guard is stricter
  (blocks the transition outright) but matches how `LearningPlanSignatureGuard` already enforces
  co-signing; a softer checklist would be inconsistent with the app's existing enforcement style for
  legally-mandated steps.
- **No portal-parent provider for this capability in this change** — the SWV/TLV/deliberation chain
  involves more parties (municipality, care partners) than the existing parent-only portal projection
  model covers cleanly; scoping a `portal-contribution` provider for it is deferred to a follow-up so
  this change stays at its declared M size.
