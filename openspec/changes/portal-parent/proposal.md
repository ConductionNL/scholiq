---
kind: code
depends_on: [portal-contribution]
---

# Proposal: portal-parent

> **Follow-up to `portal-contribution`.** That change shipped Scholiq's portal
> provider **student-only** and deferred the `parent` audience, because
> portaliq's one-hop `via` join was **forward-only** — it kept outer rows by
> their **own id** (the zaakafhandelapp `rol → zaak` shape), which cannot
> express the guardian → records direction. Portaliq has now **merged the
> reverse / scope-value join** (contract v2.2, ADR-046 A5 ext): a collection's
> `via` may declare `match: 'scopeField'`, keeping outer rows whose value at the
> collection's own `scopeField` (dot-path) is in the verified target set. This
> change re-enables the `parent` audience against that shipped reader.

## Summary

Re-enable the `parent` (guardian) audience in Scholiq's
`OCA\Scholiq\Portal\PortalContributionProvider`, now that portaliq ships the
reverse / scope-value `via` join. `getAudiences()` returns `['student','parent']`
again, `getContribution()` grows a `parent` branch, and a new
`parentContribution()` declares three reverse-joined read collections
(`parentGrades`, `parentAttendance`, `parentExcuseRequests`). It ships **reads
only** — the guardian create action is withheld (see below). No schema change is
needed — the additive refs (`learner-profile.guardianRefs`, the `learnerRef`s,
`excuse-request.submittedByRef`) already landed in `portal-identity`.

**Create action deferred (write-IDOR guard).** A guardian reporting an absence
would supply the child `learnerRef` in the create body, but portaliq's writer
only server-stamps the scope field (`submittedByRef` = the guardian); it does
**not** verify that a client-supplied cross-reference (`learnerRef`) is one of
the guardian's own children. Shipping the create would let a guardian file an
excuse on another child's record. Parent reads are safe (the reverse `via`
verifies the child set per row); the create waits on a portaliq writer follow-up
that validates create-body cross-refs against the subject's reverse-join set.

Tracking issue: Conduction/scholiq#43.

## Motivation

A guardian of a minor must be able to see their child's grades, attendance and
absence excuses, and report an absence on the child's behalf — all scoped by
domain-object UUIDs, never a Nextcloud user id the external does not have
(ADR-046 A4). The guardian case is the **reverse** of the learner case: the
subject (a guardian UUID) carries no direct scope key on the record schemas;
it must be resolved one hop through `learner-profile.guardianRefs` to the child
`LearnerProfile`(s), then the records read by their `learnerRef`. Portaliq's
newly-merged reverse join is exactly the reader primitive that makes this a
**pure provider addition** — no Scholiq schema, controller, service or UI change.

## Affected Projects

- [x] Project: `scholiq` — edit `lib/Portal/PortalContributionProvider.php`
  (re-add the `parent` branch + `parentContribution()`), edit
  `tests/Unit/Portal/PortalContributionProviderTest.php` (re-add parent tests +
  fix the via drift-pin keys). No routes, controllers, services, frontend,
  info.xml or register changes.

## Scope

### In Scope

- `getAudiences()` → `['student','parent']`; `getAudience()` stays `'student'`.
- A `parent` branch in `getContribution()` and a new `parentContribution()`:
  three read collections (`grade-entry`, `attendance-record`, `excuse-request`)
  each carrying the reverse `via` join `{register: scholiq, schema:
  learner-profile, scopeField: guardianRefs, targetField: id, match:
  'scopeField'}`, with `scopeClaim` `guardianRef`, `scopeField` `learnerRef`,
  `minTrust` `substantial`, field-projected identically to the student surface.
- Parent unit tests (audiences, manifest shape, exact via key-set + `match`,
  reads-only actions) and a via-aware register-drift pin.
- Remove the stale "parent deferred" narrative from the provider and from
  `portal-contribution`'s `proposal.md` / `design.md`.

### Out of Scope

- The schema refs — owned by `portal-identity` (already merged). This change
  makes **no** register edit.
- Portaliq's reverse-join reader itself — merged upstream (portaliq#15); this
  change only consumes it.
- Cross-checking that a client-supplied child `learnerRef` on the create is one
  the guardian actually covers — a portaliq-side writer follow-up (fail-closed
  until then; documented in design.md).
- Raising the DigiD/eHerkenning broker — the `substantial` trust is declared now
  and enforced by portaliq.

## Approach

Consume the merged reader contract exactly (portaliq
`PortalObjectReader::isValidVia()` / `rowInTargetSet(match: 'scopeField')`): the
via keys are precisely `{register, schema, scopeField, targetField, match}` —
the invented `matchField`/`selectField` keys the deferral placeholder used do
not exist in the reader and would fail closed. `targetField` is `id` because a
normalised OpenRegister row exposes its own object UUID at top-level `id`
(`ObjectEntity::jsonSerialize()` sets `$object['id'] = $this->uuid`), which is
exactly what `grade-entry.learnerRef` points at. Full derivation, the minTrust
story and a worked nil-UUID example are in design.md.

## New Dependencies

None. The provider is dependency-free by contract; it consumes portaliq's reader
at runtime only when portaliq is installed.

## Impact

- `lib/Portal/PortalContributionProvider.php` — parent branch + method re-added.
- `tests/Unit/Portal/PortalContributionProviderTest.php` — parent tests + via
  drift-pin re-added/corrected.
- `openspec/changes/portal-parent/*` — this change.
- `openspec/specs/portal-contribution/spec.md` — stays `in-progress`.
- No register, route, controller, service, frontend or info.xml change.

## Cross-Project Dependencies

Change-chain: **depends on `portal-contribution`** (the student provider this
extends) and, at runtime, on portaliq's **merged reverse-join reader**
(portaliq#15 / contract v2.2). Without the reverse join a parent manifest would
fail closed to empty; with it the parent surfaces light up unchanged.

## Risks

### Risk 1: Wrong `via` keys silently fail closed

**Severity:** Medium — **Mitigation:** portaliq's `isValidVia()` recognises
EXACTLY `{register, schema, scopeField, targetField}` (+ optional `match ∈
{id, scopeField}`); any other key set yields zero rows. A unit test pins the
exact key list and `match: 'scopeField'`, so a drift back to `matchField`/
`selectField` fails the suite, not production.

### Risk 2: `targetField` must resolve the child LearnerProfile UUID

**Severity:** Medium — **Mitigation:** verified against
`ObjectEntity::jsonSerialize()` (top-level `id` = the object UUID) and the
reader's `dotGet`/`targetRefs`; the drift pin asserts `targetField` is either a
register property or the OR identity token.

### Risk 3: A guardian reads a minor's data

**Severity:** Medium — **Mitigation:** every parent read and the create carry
`minTrust: substantial`; portaliq enforces the trust floor and (per ADR-046)
pairs it with the DigiD/eHerkenning broker.

## Rollback Strategy

Revert the provider to `getAudiences() → ['student']` and drop the `parent`
branch + `parentContribution()`; the student surface and the app are unaffected.
No schema or data change to roll back.
