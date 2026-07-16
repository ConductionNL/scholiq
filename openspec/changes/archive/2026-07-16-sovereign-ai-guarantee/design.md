# Design: sovereign-ai-guarantee

## Context

`ai-feature-delegate-to-hermiq` (merged, unarchived at
`openspec/changes/ai-feature-delegate-to-hermiq/`, all tasks `[x]`) moved EU AI Act governance for Scholiq's
AI features entirely into the Hermiq app. Scholiq's own `AiFeature` schema
(`lib/Settings/scholiq_register.json:765-818`) is now a thin AVG Art. 30 carrier — `slug`/`name`/
`description` plus a `x-openregister-processing` annotation, nothing else. The one place Scholiq still
enforces anything about AI is `AssessmentPublishGuard.php:137-187`, which blocks publishing an
`ai-assisted`-proctored `Assessment` unless Hermiq's `agentaifeature` register shows the
`assessment-ai-proctor-review` feature DPO-`enabled` (`ObjectService::findAll(['register' => 'hermiq',
'schema' => 'agentaifeature', ...])`, lines 167-174).

This design adds one more thing that guard (and, read-only, a disclosure page) can check: not just "is this
AI feature governed," but "is this AI feature's processing happening somewhere this school has agreed to
accept." The central design problem is not the schema or the guard shape — both are small and mirror
existing precedent closely. It is **what can actually be known, with what confidence, about where an LLM
call physically goes**, given Scholiq's and even Hermiq's own vantage point. Get that wrong — either by
overclaiming or by building nothing useful because full certainty isn't available — and the whole feature is
either dishonest or absent. This document works out the honest middle: prove what is provable, refuse to
pretend about the rest, and structure the guard/disclosure so "cannot verify" is a distinct, always-visible
state, never silently upgraded to "compliant."

## Goals / Non-Goals

**Goals**
- Give a school a locality tier it can set (`on-premises-only` / `eu-hosted-allowed` /
  `third-country-allowed`) and have Scholiq refuse to let an AI-assisted feature take effect when the
  feature's actual processing destination violates that tier.
- Derive the locality verdict from real, code-enforced configuration — Hermiq's active provider and, for
  broker-mediated providers, OpenRegister's own host-lock guarantee — never from an admin-typed field that
  could be wrong, stale, or optimistic.
- Make "cannot verify" a first-class, always-visible outcome, structurally distinct from "verified
  compliant," so a school's DPO is never shown a green claim the platform cannot back up.
- Give the DPO one composed disclosure surface — which AI features are on, what they touch (AVG carrier),
  where they run (locality verdict), whether that's currently policy-compliant — without inventing a second
  processing-activity register alongside the one `avg-verwerkingsregister` already ships.
- Reuse the exact cross-app read pattern `AssessmentPublishGuard` already established, rather than inventing
  a new coupling mechanism.

**Non-Goals**
- **Any hermiq-repo file.** The classification described below reads Hermiq's *existing* config surface
  cross-app; it does not add a `jurisdiction` field to Hermiq's provider catalogue or a per-feature provider
  binding. Both are named precisely in "Cross-repo follow-up" below and left as prose.
- **A positively-verified `on-premises` or `eu-hosted` classification.** No code path available to Scholiq
  (or, per the evidence below, to Hermiq itself) can currently *prove* either true. The schema carries both
  enum values for forward compatibility; the classifier cannot emit them as `verified: true` today, and this
  document says so rather than building a heuristic that would quietly look like proof.
- **Per-feature provider binding.** Every Hermiq `AiFeature` currently shares one instance-wide
  `hermiq.llm` chat-provider configuration (`ProviderFactory.php:243-299`). This change's guard and
  disclosure therefore evaluate "the currently active Hermiq provider," not "this specific feature's
  provider" — disclosed as a named limitation, not glossed over.
- **A second processing-activity register.** The AVG carrier (`scholiq-ai-features`) already exists in
  `avg-verwerkingsregister`; this change composes a locality view over it, it does not duplicate Art. 30
  fields.
- **Publishing to an external register.** Unlike `accessibility-conformance-statement`'s
  toegankelijkheidsverklaring.nl, there is no equivalent public sovereignty filing to publish to. The
  disclosure page is evidence for the school's *own* DPO/FG, not a public document.
- **Historical re-verification.** This is a forward-looking gate plus a point-in-time disclosure snapshot,
  not a retroactive audit of past AI calls (OpenRegister's own audit trail, per ADR-005, already covers
  historical decisions).

## What can actually be verified — the evidence chain

This is the load-bearing section. Every other design decision follows from what this table can honestly
claim.

| Hermiq `chatProvider` | Path | What is knowable from Scholiq's/Hermiq's own code | Verdict |
|---|---|---|---|
| `openai` | Broker-mediated (`ProviderFactory.php:264-270`, `BrokerHttpClient.php`) | The credential's `provider` field (`brokeredcredential.provider`, `credential_broker_register.json:49-55`) is read by OpenRegister's `CredentialBrokerService::request()`; **Guard 4** (`CredentialBrokerService.php:532-556`) code-enforces the resolved host equals the catalogue's fixed `baseUrl` for that provider — `https://api.openai.com` (`credential-providers.json:152-155`), a US-domiciled vendor. The app-supplied path can never redirect this. | `third-country`, **verified** — IF the credential's catalogue provider is `openai` (not an inject-only `generic-*` type) |
| `fireworks` | Same broker path | Catalogue-locked to `https://api.fireworks.ai` (`credential-providers.json:195-197`), US-domiciled. | `third-country`, **verified** (same condition) |
| `anthropic` | Same broker path | Catalogue-locked to `https://api.anthropic.com` (`credential-providers.json:167-184`, both `anthropic` and `anthropic-oauth` entries), US-domiciled. | `third-country`, **verified** (same condition) |
| any of the three above, but the referenced credential is `generic-apikey`/`generic-bearer`/`generic-basic`/`generic-oauth2`/`generic-jwt` | Broker-mediated, but Guard 4 explicitly does not apply — these are the catalogue's documented inject-only, non-host-locked path for "arbitrary/self-hosted targets" (`CredentialBrokerService.php:217-227`) | The actual destination could be anywhere, including genuinely on-premises or EU-hosted — but nothing code-enforces it, so nothing can be asserted. | `unverified` |
| `ollama` | Never brokered (`ProviderFactory.php:1142-1164`) | `ollamaConfig.url` is a bare string an admin typed into Hermiq's settings. No code path checks it resolves to a private network, an EU host, or anything at all. | `unverified` (even when the URL visually looks like a private/`.local` address — a string is not proof) |
| `nextcloud` | `TaskProcessing` (`ProviderFactory.php:1411-1419`) | Delegates to whatever `TaskProcessing` provider is installed instance-wide. Hermiq's own code has no visibility into that provider's actual backend (could be a local model, could itself call out to a cloud API). | `unverified` |
| Hermiq not installed, or `hermiq.llm` unconfigured | — | No active provider at all. | `unverified` (and the guarded feature cannot be `enabled` in Hermiq's own register anyway, per `ai-feature-delegate-to-hermiq`'s fail-closed posture) |

**The asymmetry is the whole point.** This design can *prove* a violation (a catalogued, host-locked,
US-domiciled SaaS provider is active) with code-level certainty. It can *never currently prove* the positive
case (on-premises, EU-hosted) — every path that isn't a catalogued SaaS provider is, by construction, a path
with no host-lock at all, so "unverified" is the only honest label, not "presumed fine." A school running
Ollama on its own server in its own building almost certainly *is* processing on-premises in reality — this
design refuses to say so anyway, because "almost certainly, based on an admin-typed URL" is exactly the kind
of unverifiable claim the brief prohibits from ever rendering as green.

## Data Model

```
SovereigntyPolicy (flat, singleton, no lifecycle)
  policy: on-premises-only | eu-hosted-allowed | third-country-allowed   (default: eu-hosted-allowed)
  rationale: string, optional — why this tier was chosen, for the DPO record
  setBy / setAt: derived, stamped on create/update
  x-openregister-authorization: create/update -> [admin, compliance-officer]   (mirrors AccessibilityLimitation)
```

No `AiFeature` schema change and no new processing-activity schema — `SovereigntyPolicy` is the only new OR
object. It is a singleton by convention (mirrors Hermiq's own `TenantControl`/`ModelPolicy`/
`GuardrailPolicy`, which are flat, un-lifecycled policy records): the frontend and the backend services both
read "the first `SovereigntyPolicy` object, or the schema default if none exists yet" — no delete/archive
flow, an update-in-place record, the exact posture Hermiq already uses for org-wide policy records with no
tenant scoping needed here since one Scholiq instance is one school (verified: no `organisation`/`tenantId`
field exists anywhere in `scholiq_register.json`, unlike Hermiq's multi-tenant `agentaifeature`/
`ModelPolicy`).

### `AiLocalityClassifier` (stateless service)

```php
classify(string $chatProvider, ?string $credentialId): array{
    locality: 'on-premises'|'eu-hosted'|'third-country'|'unverified',
    verified: bool,
    evidence: string,   // human-readable, cited, shown verbatim on the disclosure page
}
```

Implements exactly the table above. For `openai`/`fireworks`/`anthropic`, it cross-app-reads the
`brokeredcredential` object named by `$credentialId` (`ObjectService::findAll(['register' =>
'credential-broker', 'schema' => 'brokeredcredential', 'filters' => ['id' => $credentialId], 'limit' =>
1])`, mirroring `AssessmentPublishGuard`'s own `register`/`schema`/`filters` shape exactly) and checks its
`provider` field against the three catalogued, host-locked identifiers. `$chatProvider` and `$credentialId`
come from decoding Hermiq's `hermiq.llm` `IAppConfig` blob (`IAppConfig::getValueString('hermiq', 'llm',
'{}')` — NC's `IAppConfig` interface takes the target app id as an explicit parameter on every read, the same
cross-app mechanism already relied on implicitly by every `getAppValue`-style call in the fleet; verified
signature: `lib/public/IAppConfig.php:176`, `getValueString(string $app, string $key, ...)`). This is a
narrower, more tightly-coupled read than the `ObjectService` register/schema pattern (it requires knowing
Hermiq's exact JSON key shape, not just a schema slug) — documented here explicitly, mirroring
`AssessmentPublishGuard`'s own `HERMIQ_REGISTER`/`HERMIQ_AI_FEATURE_SCHEMA` constant-documentation precedent
for coupling.

Two things are explicitly **not yet confirmed** and are named as implementation-time verification, not
assumed:
1. Whether `brokeredcredential` objects are readable cross-app by Scholiq's calling identity under
   OpenRegister's RBAC (the schema excerpt read for this design does not show its own
   `x-openregister-authorization` block). If the read is denied, the classifier MUST degrade to `unverified`
   for that credential (never error, never silently assume compliant) — the same fail-closed default as
   every other unresolvable case in the table above.
2. The exact `ObjectService` lookup-by-id call shape (a direct `find($credentialId)` vs. a `findAll` filter)
   — `AssessmentPublishGuard` only ever filters by `slug`, not by object id; `ai-companion-tools`'s
   `findCourse()` (`openspec/specs/ai-companion-tools/spec.md` REQ-004) is the precedent for a
   try-direct-then-fallback id resolution. This is an implementation detail to pin during `tasks.md`, not a
   design risk — either shape reaches the same `provider` field.

### `SovereigntyPolicyService` (stateless service)

```php
currentPolicy(): string   // reads the SovereigntyPolicy singleton, defaults to 'eu-hosted-allowed'
isCompliant(string $locality, bool $verified): bool
```

The compliance rule, the second load-bearing decision of this change:

| Policy tier | Compliant localities |
|---|---|
| `on-premises-only` | `on-premises` **and** `verified === true` only |
| `eu-hosted-allowed` | `on-premises` or `eu-hosted`, **and** `verified === true` |
| `third-country-allowed` | anything — including `unverified` |

`unverified` never satisfies the two stricter tiers, by construction. It only ever passes under
`third-country-allowed` — the tier that means "we accept not knowing," which is an honest position a school
can choose, distinct from the platform pretending to know.

### `AssessmentPublishGuard` (modified)

After its existing DPO-enablement check for `ai-assisted` `flagReviewMode` passes (lines 157-184, unchanged),
the guard now also calls `AiLocalityClassifier::classify()` (fed the current `hermiq.llm` config) and
`SovereigntyPolicyService::isCompliant()`. A `false` result blocks the transition with a distinct log message
("DPO-enabled but locality violates SovereigntyPolicy") so an admin can tell the two failure reasons apart.
This is composed **inside** the existing `check()` method as a new private call, not a second
`x-openregister-lifecycle.requires` entry — `scholiq_register.json`'s own v0.11.0 changelog note records
that `requires` is verified (via `LifecycleAnnotationValidator::validate` + `LifecycleGuardRegistry::resolve`)
to be a single DI-tag string, never an array, in this register; `ReportPeriodLockGuard` composing
`FraudCaseBlockGuard` internally is the precedent this change follows for "two checks, one guard class."

### `AiProcessingDisclosureController::index()`

`#[NoAdminRequired]`, gated to `admin`/`compliance-officer` (mirrors the AVG Settings section's `isAdmin`
client-side gate plus OR's own server-side RBAC as the real enforcement layer — Scholiq "enforces nothing
itself" beyond defence-in-depth, the exact posture `avg-verwerkingsregister`'s spec already documents for its
own compliance surface). Composes, per Hermiq `agentaifeature` object (when Hermiq is installed):

```
{
  slug, name, riskCategory, lifecycle,        // from Hermiq's agentaifeature (cross-app read)
  aiProcessingActivity: {...},                // Scholiq's own AiFeature AVG carrier (scholiq-ai-features)
  locality, verified, evidence,               // AiLocalityClassifier::classify() on the active provider
  policyCompliant,                            // SovereigntyPolicyService::isCompliant()
}
```

plus the school's current `SovereigntyPolicy`. This is read-only composition across two registers with
derived classification — not a single declarative OR query, so a thin controller is the legitimate ADR-031
seam (identical justification `AssessmentPublishGuard`'s own docblock already states for its Hermiq read:
"Requires a cross-schema query ... and conditional logic"). No write path lives here — `SovereigntyPolicy`
create/update goes through OR's existing generic object endpoint directly from the frontend, the same
"frontend orchestration against OpenRegister's existing object-create endpoint" pattern `course-authoring-ux`
already established for `CourseTemplate` (no bespoke PHP CRUD, per ADR-022).

### `ScholiqAiProcessingDisclosure.vue`

Singleton page, no `:id` route param (mirrors `ScholiqAccessibilityStatement.vue`'s exact shape:
`src/manifest.json:1079-1084`'s own note calls this "a singleton disclosure page"). Renders: the current
`SovereigntyPolicy` tier with an inline editor; a list of every disclosed AI feature with three clearly
distinguished badge states — **compliant** (green, only when `verified: true` and the tier is satisfied),
**violates policy** (red, `verified: true` but the tier rejects it — mirrors the guard's own block reason),
and **unverified** (amber, always — regardless of policy tier — whenever `verified: false`, so a school on
the permissive `third-country-allowed` tier still sees "unverified," not a false "compliant"). No badge state
renders green without `verified: true` — this is the one non-negotiable UI rule this whole change exists to
enforce.

## Decisions

### Decision 1: Prove violations, never prove compliance — and say so

The obvious alternative design classifies `ollama`/`nextcloud`/self-hosted credentials as `on-premises` by
default (an admin chose to configure a local provider, presumably for a reason) and only falls back to
`unverified` when even that inference is impossible. Rejected: that is exactly the unverifiable-claim-shown-
as-green failure mode the brief prohibits. A URL string or a provider name choice is not proof of physical
hosting location — networks get misconfigured, `ollamaConfig.url` could point at a cloud VM an admin spun up
temporarily, and nothing re-checks it. The asymmetry (provable violations, unprovable compliance) is not a
limitation to apologise for; it is the honest shape of what "verify" can mean given today's credential-broker
and provider-catalogue design. If it reads as a weaker guarantee than a marketing claim would be, that is
correct — a marketing claim is exactly what the Specter counter-insight says gets relieved by the next DPIA
remediation cycle. A structurally-incapable-of-lying guarantee is the stronger, more durable position.

### Decision 2: `SovereigntyPolicy` defaults to `eu-hosted-allowed`, not the permissive tier

`GuardrailPolicy` (Hermiq) defaults fail-open ("matching pre-change behavior," its own schema description)
because it retrofits a check onto already-live traffic — a stricter default would break existing agents on
upgrade. `SovereigntyPolicy` has no such constraint: it is a wholly new capability a school opts into by
upgrading Scholiq, with no prior enforcement to preserve. Defaulting to the fully-permissive
`third-country-allowed` tier would mean the feature ships and changes nothing for any school until an admin
manually tightens it — undermining the whole "guarantee, not claim" premise on day one. `on-premises-only` as
the default was considered and rejected as too strict for a default: nearly every school piloting this will
have zero on-premises-*verified* AI processing today (verification of `on-premises` is not currently
possible at all, per the evidence chain above), so that default would immediately and permanently block the
one path this design *can* prove compliant (a genuinely EU-domiciled hosted provider once one is catalogued).
`eu-hosted-allowed` is the default because it is the AVG/DPIA remediation floor regulators already push
schools toward (Baden-Württemberg's 2022 ruling and Denmark's Datatilsymet remediation both push toward
EU-hosted, not necessarily fully on-premises) — and, honestly, because at HEAD nothing can pass it as
`verified` either, which is itself a correct and visible signal to a school evaluating the feature for the
first time, not a bug to paper over.

### Decision 3: One shared classifier/policy pair, used by both the guard and the disclosure page

Rejected alternative: duplicate the classification logic inline in `AssessmentPublishGuard` and again in the
disclosure controller. `AiLocalityClassifier`/`SovereigntyPolicyService` exist as separate, injectable
services precisely so the guard's enforcement decision and the disclosure page's displayed verdict can never
disagree — the same "one place the logic lives" discipline `ai-course-recommendations`'s `matchedSignals`
design already established ("the actual explanation... never an independent source of truth"). A future
second AI-consuming feature in Scholiq (there is exactly one live today, `assessment-ai-proctor-review`) adds
its own guard call to the same two services rather than reinventing classification.

### Decision 4: No new processing-activity register entry

`avg-verwerkingsregister`'s `scholiq-ai-features` activity already exists
(`scholiq_register.json:766-787`). This change's disclosure page reads that existing carrier's
`doelbinding`/`dataCategories` fields rather than declaring an eighth Art. 30 activity — a locality guarantee
is a property of an *existing* processing activity ("where," not "what" or "why"), not a new one. Keeping the
Art. 30 catalogue at seven activities also matches `ai-feature-delegate-to-hermiq`'s own stated invariant
(`ProcessingActivityCatalogueTest`, "verwerkingsregister stays at seven activities") — this change does not
reopen that count.

## Cross-repo follow-up (hermiq repo — not built in this change)

Two concrete gaps, both intentionally left as prose per this change's brief:

1. **A `jurisdiction`/`region` field on `credential-providers.json` catalogue entries** (openregister repo,
   `lib/Settings/credential-providers.json`) — e.g. `{"identifier": "openai", "jurisdiction":
   "third-country-us"}`. Without this, "third-country" is inferred by Scholiq hardcoding a lookup table of
   three vendor names against public knowledge of where they are domiciled — accurate today, but a
   maintenance burden and not itself code-enforced the way the host-lock is. A catalogue-level jurisdiction
   field would let the *broker* assert jurisdiction with the same authority it already asserts host-lock,
   and — critically — would be the one place a genuinely EU-hosted or on-premises catalogue entry (a future
   self-hosted-with-real-attestation provider) could ever be added and *positively* verified, closing the
   asymmetry Decision 1 documents. This is Hermiq/OpenRegister's decision to make, not Scholiq's.
2. **Per-`AiFeature` provider binding on Hermiq's `agentaifeature` schema**
   (`hermiq_register.json:477-644`) — today every Hermiq `AiFeature` shares the one instance-wide
   `hermiq.llm` provider. A `providerBinding` field (or equivalent) on `agentaifeature` would let a future
   feature genuinely differ in locality from another, and would let Scholiq's guard/disclosure key off a
   specific feature's binding instead of "whatever Hermiq is currently configured to use fleet-wide" — this
   change's guard and disclosure both currently evaluate the latter, disclosed honestly as a named limitation
   rather than implied away.

Until either lands, this change's classifier and policy service are the seam a consuming app calls — they
are written so that when Hermiq exposes richer, per-feature, jurisdiction-aware data, `AiLocalityClassifier`
gains a new, more precise branch without changing its public contract or the guard/disclosure call sites.

## Risks / Trade-offs

- **The guard only covers one live AI-consuming action** (`Assessment.publish` with `ai-assisted`
  proctoring) because it is the only one Scholiq owns today (`ai-course-recommendations`'s scholiq-side
  "Recommended for you" widget is still an unbuilt follow-up per that change's own design.md). A future
  scholiq AI-consuming surface must add its own call to the same two services — documented in Decision 3,
  not silently assumed to be covered.
- **`brokeredcredential` cross-app readability is unverified at design time** (see `AiLocalityClassifier`
  above) — if OpenRegister's RBAC denies the read for Scholiq's calling identity, the classifier degrades to
  `unverified`, which is the safe direction to fail but reduces the guarantee's practical value for schools
  using a broker-mediated provider. A `tasks.md` acceptance criterion pins this down at implementation time.
- **A school on `third-country-allowed` sees "unverified" for a self-hosted Ollama setup that is, in
  physical reality, almost certainly on-premises.** This is the direct, accepted cost of Decision 1 — a real
  usability friction traded for never showing an unproven claim as green. If this proves too conservative in
  practice, the cross-repo follow-up (item 1 above) is the correct place to relax it, not a local heuristic.
