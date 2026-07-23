# Design: bpv-praktijkovereenkomst

## Architecture Overview

```
Programme ──CurriculumPlan(kind:opleidingsplan)───────────────────────┐
                                                                       │
LearnerProfile ──learnerRef──> BpvPlacement <──praktijkopleiderId── Praktijkopleider
                                    │  │                                  (no NC account —
                                    │  └─leerbedrijfVerification──────┐    portal identity
                                    │      {provider,status,...}      │    anchor)
                                    │            │                    │
                                    │   ProvidesLeerbedrijfVerification│
                                    │      (pluggable, no bundled       │
                                    │       provider; SBB adapter =     │
                                    │       openconnector follow-up)    │
                                    │                                   │
                                    ├──> Praktijkovereenkomst (POK) ────┤
                                    │        │  lifecycle: draft →      │
                                    │        │  pending-signatures →    │
                                    │        │  active | terminated     │
                                    │        └──> PokSignature × 3      │
                                    │             (student/school/──────┘
                                    │              praktijkopleider)
                                    │
                                    ├──> WerkprocesAssessment ──curriculumPlanId+componentId──> GradeEntry (grading spec)
                                    │        assessorId ─────────────────────────────────────────┘ (→ Praktijkopleider)
                                    │
                                    └──> BpvVisitReport ──learnerRef──> LearnerProfile dossier
                                             (visitDueReminder → schoolCoachId)

PortalContributionProvider (lib/Portal/, real code at HEAD)
  getAudiences() → ['student', 'parent', 'praktijkopleider']   (this change adds the third)
  praktijkopleiderContribution():
    direct match praktijkopleiderId == subject.subjectRef   (like `student`, NOT `parent`'s reverse join)
    reads:   bpvPlacements (field-projected)
    actions: createWerkprocesAssessment, signPraktijkovereenkomst  (server-stamped scopeField)
```

## The three reused patterns — verified at HEAD, and where each one bends

### 1. Pluggable provider (proctoring / plagiarism → leerbedrijf verification)

`lib/Proctoring/ProvidesProctoring.php:61` and
`lib/Plagiarism/ProvidesPlagiarismCheck.php:41` are real, shipped interfaces.
Both follow the same shape: a config field on the owning schema names an
adapter identifier (`Assessment.proctoring.provider`,
`Assignment.plagiarismProvider`), the app resolves it through DI to a concrete
class implementing the interface, and **no concrete adapter ships in the app
itself** — the app works with the seam unconfigured (proctoring/plagiarism
checks simply don't run). `ProvidesLeerbedrijfVerification` follows this
exactly:

```php
interface ProvidesLeerbedrijfVerification
{
    /**
     * @return array{status: string, erkenningNumber: ?string, expiresAt: ?string, raw: array}
     */
    public function verify(string $kvkOrErkenningNumber): array;
}
```

`status` is one of `verified | rejected | pending`. `BpvConfirmationGuard`
reads the **stored** `BpvPlacement.leerbedrijfVerification.status` (not a live
call) — exactly how `AssessmentPublishGuard` and `LearningPlanSignatureGuard`
read stored lifecycle-adjacent state rather than calling out synchronously
during a transition. Writing `leerbedrijfVerification.status` from a
`verify()` call is a coordinator-triggered action (a "Check leerbedrijf"
button/action on the `BpvPlacement` detail page — declarative
`lifecycleActions`-adjacent, not a new controller pattern; consistent with how
`data-exchange`'s `DataExchangeJob` is a request record a user drives). No new
controller pattern is introduced.

**Why this must NOT be a `DataExchangeJob` (the pattern the gap-report evidence
initially suggested):** `data-exchange` (`openspec/specs/data-exchange/spec.md`)
is the right home for *bulk, scheduled, or queued* exchanges (BRON, OSO,
leerplicht). A leerbedrijf check is a *synchronous, per-placement, blocking
gate* — closer in shape to a proctoring/plagiarism hook than an export job.
Routing it through `ProvidesLeerbedrijfVerification` also means Scholiq
**works standalone without openconnector** (the task's explicit requirement) —
a `DataExchangeJob`'s `target` field is architecturally an OpenConnector
connection reference by design (`data-exchange/spec.md:54-60`), so that path
would have made the openconnector adapter a hard dependency instead of a
follow-up.

### 2. Signature → PokSignature (pattern reuse, not schema reuse)

Verified at HEAD (`lib/Settings/scholiq_register.json:6263-6353`):

| Field | Signature (learning-plan) | PokSignature (this change) |
|---|---|---|
| `subjectKind` | enum, **fixed single value** `"learning-plan"` (6288-6296) | not needed — one schema, one subject type |
| `subjectId` | `format: uuid`, **hard `$ref: "LearningPlan"`** (6297-6303) | `format: uuid`, `$ref: "Praktijkovereenkomst"` |
| `subjectVersion` | integer | integer (same) |
| `signerId` | NC uid or external identifier (string) | same (NC uid for student/school; `Praktijkopleider` object UUID for the workplace role — both are just opaque strings in this field) |
| `signerRole` | enum `learner\|parent\|coordinator\|teacher\|other` | enum `student\|school\|praktijkopleider` |
| `signedAt` / `assuranceLevel` / `method` / `evidenceRef` | as-is | identical |
| `appendOnly` | `true` | `true` |

The reason this is a **parallel schema**, not an enum-widened `Signature`:
`subjectId`'s `$ref` is a single fixed schema reference, and the fleet's
`hydra-gate-relation-dialect` gate explicitly bans bespoke/polymorphic
relation shapes on a `format: uuid` property — a `$ref` must resolve to
exactly one schema in the same register. Making `Signature.subjectId`
polymorphic (resolving to either `LearningPlan` or `Praktijkovereenkomst`
depending on `subjectKind`) is exactly the "bespoke dialect" that gate exists
to catch, and it would also make `learning-plan` — a capability this change
does not otherwise touch — carry BPV-specific vocabulary. A same-shaped
sibling schema costs one small JSON block and keeps both capabilities
independently owned and gate-clean. This is the literal, grounded meaning of
"reuse the Signature **pattern**": identical field shape, identical
append-only + guard-gated-activation idiom (`LearningPlanSignatureGuard` →
`PokActivationGuard`), deliberately not the same JSON Schema object.

### 3. ADR-046 portaliq — praktijkopleider as a third audience

`lib/Portal/PortalContributionProvider.php` is real, merged code (`git log
--oneline -- lib/Portal/` shows commits `3acf642`, `ea7b19f`, `480259a`), not
a draft or a future change. It currently serves two audiences with two
different scoping shapes:

- **`student`** (lines 156-327): **direct match** — `record.learnerRef ==
  subject.subjectRef`. Ships two create-actions because a direct match is safe
  to accept a write against (the server stamps the scope field from the
  authenticated subject; there is no cross-reference to validate).
- **`parent`** (lines 365-459): **reverse one-hop join** — a guardian's
  `subjectRef` has no direct scope key on any record; the reader resolves
  `learner-profile` rows whose `guardianRefs` contains it, collects the
  children's own object UUIDs, then keeps records whose `learnerRef` is in
  that set (`match: 'scopeField'`). This audience explicitly ships **zero**
  create-actions (lines 444-455) — the code comment states why: portaliq's
  writer stamps the scope field server-side but does not yet verify a
  client-supplied cross-reference (a child's `learnerRef` in the create body)
  against the reverse-join set, so shipping a parent create would be a write
  IDOR (a guardian filing something against a child that isn't theirs).

`praktijkopleiderId` on `BpvPlacement` / `WerkprocesAssessment` /
`PokSignature` is a **direct** scope key — the placement/assessment/signature
literally belongs to that praktijkopleider, no join required. This change
therefore follows the `student` shape, not the `parent` shape, and it is safe
to ship both create-actions in this same change (no IDOR gap to wait on):

```php
private function praktijkopleiderContribution(): array
{
    return [
        'label' => 'Scholiq',
        'collections' => [
            [
                'id' => 'poBpvPlacements',
                'register' => self::REGISTER,
                'schema' => 'bpv-placement',
                'scopeField' => 'praktijkopleiderId',
                'scopeClaim' => 'praktijkopleiderId',
                'label' => 'My BPV placements',
                'listable' => true,
                'minTrust' => 'low',
                'fields' => [
                    'praktijkopleiderId', 'learnerRef', 'curriculumPlanId',
                    'leerbedrijfName', 'periodFrom', 'periodTo', 'lifecycle',
                    // dropped: schoolCoachId, leerbedrijfVerification.raw
                ],
            ],
        ],
        'actions' => [
            [
                'id' => 'createWerkprocesAssessment',
                'type' => 'create',
                'register' => self::REGISTER,
                'schema' => 'werkproces-assessment',
                'scopeField' => 'assessorId',
                'scopeClaim' => 'praktijkopleiderId',
                'minTrust' => 'substantial',
                'fields' => [
                    'bpvPlacementId', 'curriculumPlanId', 'componentId',
                    'kwalificatiedossierCode', 'kerntaakCode', 'werkprocesCode',
                    'werkprocesLabel', 'beoordeling', 'toelichting',
                ],
            ],
            [
                'id' => 'signPraktijkovereenkomst',
                'type' => 'create',
                'register' => self::REGISTER,
                'schema' => 'pok-signature',
                'scopeField' => 'signerId',
                'scopeClaim' => 'praktijkopleiderId',
                'minTrust' => 'substantial',
                'fields' => ['subjectId', 'subjectVersion', 'assuranceLevel', 'method', 'evidenceRef'],
            ],
        ],
        'notifications' => [],
    ];
}
```

`minTrust: 'substantial'` on both actions (not `'low'`, unlike the student's
own creates) because both write into a record that ultimately feeds a
learner's official diploma-track evidence (a graded werkproces, a legally
required contract signature) — the same reasoning `portal-parent` applied to
guardian reads/creates over minor data. `getAudiences()` becomes `['student',
'parent', 'praktijkopleider']`; `getContribution()` gains one more `if`
branch, fail-closed `null` otherwise (unchanged default).

## The notification-channel gap (documented, not silently dropped)

Every `x-openregister-notifications.*.channels` value in
`lib/Settings/scholiq_register.json` is `"nc-notification"` or `"activity"` —
both are Nextcloud-internal (`IManager`/Activity, per the app's own
architecture notes). A praktijkopleider has no NC account, so a declared
notification cannot reach them today. This change makes an explicit choice
rather than papering over it:

- `Praktijkovereenkomst`'s missing-signature reminder targets **only** the
  internal parties (student + `schoolCoachId`) — the coordinator sees who is
  outstanding and chases the praktijkopleider by whatever channel the school
  already uses (phone/email outside Scholiq), OR the praktijkopleider simply
  sees their own POK's status is not yet `active` next time they open the
  portal (pull, not push).
- `BpvVisitReport.visitDueReminder` targets `schoolCoachId` only, for the same
  reason.
- Adding an email/external-recipient channel to the `x-openregister-notifications`
  dialect is a **cross-cutting platform gap** (it would benefit every portal
  audience, not just BPV) and is explicitly out of scope here — a follow-up
  against the notification dialect itself, not this capability.

## Data Model

All in OpenRegister (ADR-001). New schemas and their essential fields:

- **`Praktijkopleider`**: `givenName`, `familyName`, `email`, `phone`,
  `leerbedrijfName`, `leerbedrijfKvkNumber`, `active` (bool), `tenant_id`.
- **`BpvPlacement`**: `learnerId` (NC uid) + `learnerRef` (uuid, `$ref:
  LearnerProfile` — both from day one, unlike the additive-remap
  `portal-identity` had to retrofit onto pre-existing schemas, because this
  schema is new), `programmeId` (`$ref: Programme`), `curriculumPlanId`
  (`$ref: CurriculumPlan`), `praktijkopleiderId` (`$ref: Praktijkopleider`),
  `schoolCoachId` (NC uid), `leerbedrijfName`, `leerbedrijfKvkNumber`,
  `periodFrom`/`periodTo`, `leerbedrijfVerification` (object: `provider`,
  `status` enum `unverified|pending|verified|rejected|expired`,
  `erkenningNumber`, `verifiedAt`, `expiresAt`, `raw`), `lifecycle`
  (`proposed → sbb-verification-pending → confirmed → active → completed |
  terminated`, guarded by `BpvConfirmationGuard` on `confirm`), `tenant_id`.
- **`Praktijkovereenkomst`**: `bpvPlacementId` (`$ref: BpvPlacement`),
  `periodFrom`/`periodTo`, `terms` (structured clauses or rich text),
  `version` (int), `lifecycle` (`draft → pending-signatures → active →
  completed | terminated`, guarded by `PokActivationGuard` on `activate`),
  `isFullySigned` (calculation: count of distinct `signerRole` values across
  `PokSignature` rows for this `subjectId`+`subjectVersion` == 3), `tenant_id`.
- **`PokSignature`**: as the table above — `appendOnly: true`.
- **`WerkprocesAssessment`**: `bpvPlacementId` (`$ref: BpvPlacement`),
  `curriculumPlanId` + `componentId` (existing generic grading hook,
  `kind: "assessment"` on the referenced `CurriculumPlan.components[]` entry —
  no schema change to `school-structure`), `kwalificatiedossierCode`,
  `kerntaakCode`, `werkprocesCode`, `werkprocesLabel`, `assessorId` (`$ref:
  Praktijkopleider`), `assessedAt`, `beoordeling` (enum
  `nog-niet-competent|competent`), `toelichting`, `lifecycle` (`draft →
  submitted → confirmed`, `confirmed` emits/updates a `GradeEntry`),
  `tenant_id`.
- **`BpvVisitReport`**: `bpvPlacementId` (`$ref: BpvPlacement`), `learnerRef`
  (`$ref: LearnerProfile` — the dossier link), `visitDate`, `visitKind` (enum
  `voortgangsbezoek|tussentijds-gesprek|eindgesprek|incident`), `attendees`
  (array of `{role, name}` — praktijkopleider/other external attendees have no
  reliable NC uid), `schoolCoachId` (NC uid), `narrative`, `actionPoints`,
  `nextVisitDue` (calculation), `lifecycle` (`draft → finalized`), `tenant_id`.

Every relation above is a schema property (`format: uuid` + `$ref` to a
schema in the same `scholiq` register), per the fleet's canonical relation
dialect — no bespoke `x-openregister-relations` block (verified at HEAD: that
key does not literally appear anywhere in `scholiq_register.json`; relations
are expressed as `$ref` properties, e.g. `GradeEntry.curriculumPlanId` at
`lib/Settings/scholiq_register.json:5382-5388`).

Register `info.version` bumps 0.3.1 → 0.4.0 (new schemas only; no existing
schema is modified, so no touched-schema version bump is needed beyond the six
new schemas starting at `0.1.0`).

## API Design

None. No new PHP CRUD controllers or routes (ADR-022) — all reads/writes go
through OpenRegister's existing object API from the frontend, and through
portaliq's server-side read/write path for the `praktijkopleider` audience.
The only PHP surfaces are: `ProvidesLeerbedrijfVerification` (interface only),
`BpvConfirmationGuard`, `PokActivationGuard` (both `x-openregister-lifecycle`
`requires` guard classes, the same idiom as
`AssessmentPublishGuard`/`LearningPlanSignatureGuard`), and the
`PortalContributionProvider::praktijkopleiderContribution()` addition.

## Nextcloud Integration

- Controllers/Services/Mappers: none (thin OR client, per architecture rules).
- No new OCP interface usage — `Praktijkopleider` is a plain OR object, not an
  NC user/group; no `IGroupManager`/`IUserManager` involvement.

## Security Considerations

- **A4-clean from day one** (ADR-046): `BpvPlacement.learnerRef` and
  `Praktijkopleider`'s own object UUID are UUID domain refs from the start —
  no retrofit needed because these are new schemas, unlike the
  `portal-identity` additive-remap that had to retrofit `learnerId`-scoped
  schemas.
- **Fail-closed audience filter**: any subject audience other than
  `student`/`parent`/`praktijkopleider` → `null` (unchanged default branch).
- **Server-derived subject only** (ADR-005): `assessorId` and `signerId` on
  the praktijkopleider's create-actions are stamped from
  `subject.subjectRef` server-side by portaliq's writer — never accepted from
  the request body, per the `scopeField`/`scopeClaim` contract already
  enforced for `student`.
- **`minTrust: substantial`** on both praktijkopleider create-actions: an
  official werkproces assessment and a contract signature both feed
  diploma-track evidence — same trust floor `portal-parent` set for
  guardian actions over minor data.
- **Field projection**: the praktijkopleider read collection excludes
  `schoolCoachId` (internal staff identity) and
  `leerbedrijfVerification.raw` (the SBB provider's raw payload may carry
  more than the erkenning status) — mirrors the staff-only-column drop table
  in `portal-contribution/design.md`.
- **No write-IDOR gap** (unlike `parent`): `praktijkopleiderId` is a direct
  scope key, so both create-actions ship in this change (no reverse-join
  cross-reference to leave unvalidated).
- **EU AI Act**: none of this change involves AI — the leerbedrijf check is a
  deterministic registry lookup (once a provider exists), and workplace
  assessment is entered directly by a human. No `AiFeature` registration
  needed.
- No secrets, tokens, or new endpoints in this change.

## File Structure

```
lib/Bpv/ProvidesLeerbedrijfVerification.php          (new — pluggable interface)
lib/Lifecycle/BpvConfirmationGuard.php                (new)
lib/Lifecycle/PokActivationGuard.php                  (new)
lib/Portal/PortalContributionProvider.php             (modified — + praktijkopleider audience)
lib/Settings/scholiq_register.json                    (+6 schemas; info.version 0.3.1 → 0.4.0)
src/manifest.json                                     (+ index/detail pages, + SignPokModal)
tests/Unit/Bpv/                                        (new — guard + provider unit tests)
tests/Unit/Portal/PortalContributionProviderTest.php  (modified — + praktijkopleider assertions
                                                         + register-drift pin extension)
openspec/
  changes/bpv-praktijkovereenkomst/                   (this change)
  specs/bpv/spec.md                                   (capability status stub, in-progress)
```

## Trade-offs

- **`ProvidesLeerbedrijfVerification` vs `DataExchangeJob`** — a job queue fits
  bulk/scheduled exchanges (BRON, OSO); a per-placement blocking gate that
  must work with zero openconnector dependency fits the pluggable-provider
  shape instead. See "The three reused patterns" above.
- **`PokSignature` (parallel schema) vs widening `Signature`** — widening
  reuses one fewer schema block but couples `learning-plan` to BPV vocabulary
  and needs a polymorphic `$ref`, which the fleet's relation-dialect gate
  rejects. A same-shaped sibling schema is the honest reuse of the *pattern*.
- **`WerkprocesAssessment` referencing existing `CurriculumPlan.components[]`
  `kind: "assessment"` vs adding a `"werkproces"` component kind** — a new
  enum value would require a `school-structure` delta for a distinction that
  changes nothing about how grading rolls the component up; the
  kwalificatiedossier taxonomy fields on `WerkprocesAssessment` itself carry
  all the MBO-specific meaning without touching a capability this change
  doesn't otherwise need.
- **Praktijkopleider portal creates ship in v1 (unlike `parent`'s creates,
  which are still deferred)** — the scope key is direct, not a reverse join,
  so the write-IDOR gap that blocks `parent` creates does not apply here.
- **No email notification channel** — adding one is a cross-cutting dialect
  change benefiting every portal audience; scoping it into this change would
  blur a capability spec with a platform change. Documented gap, not a silent
  drop.
- **No SBB adapter in this change** — explicit, requested scope cut (task
  brief): Scholiq must work without openconnector; the adapter is cross-repo
  follow-up work.
