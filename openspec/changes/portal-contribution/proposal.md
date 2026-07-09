---
kind: code
depends_on: [portal-identity]
---

# Proposal: portal-contribution

> **Scope note:** this change originally shipped the **`student`** audience only
> and deferred the **`parent`** audience, because portaliq's one-hop `via` join
> was forward-only (it kept outer rows by their **own id**, the zaak/rol shape,
> which could not express the guardian → records direction). Portaliq has since
> merged the **reverse / scope-value join** (`via.match: 'scopeField'`, contract
> v2.2), and the follow-up change **`portal-parent`** re-enabled the `parent`
> audience against it as a pure provider addition — no schema work, since the
> additive `guardianRefs` / `submittedByRef` refs already landed in
> `portal-identity`. Tracked on Conduction/scholiq#43.

## Summary

Ship Scholiq's ADR-046 portal contribution: one plain, dependency-free class
(`lib/Portal/PortalContributionProvider.php`) that declares — for the `student`
(the learner) and `parent` (a guardian) audiences — the OpenRegister
collections a portal subject may read, the whitelisted create-actions they may
perform, and a learner notification inbox. All scoping is by the UUID
domain-object references added by the `portal-identity` change (`learnerRef`,
`learnerRefs`, `submittedByRef`, `guardianRefs`) — never a Nextcloud user id
(amendment A4). This is Wave-3 of the ADR-046 rollout, whose whole point is the
A4 identity rule; it **depends on** `portal-identity` (the schema head) and is
meaningless without it.

Tracking issue: Conduction/scholiq#39.

## Motivation

ADR-046 makes portaliq the single external portal for people without Nextcloud
accounts; contribution contract v2 (2026-07-06 amendment) has domain apps
contribute by shipping one duck-typed class — no portaliq import, no info.xml
dependency — so portal support is always optional (A1). Scholiq must let a
learner see their own grades, attendance, enrolments, submissions and absence
excuses (and hand in work / report an absence), and let a guardian see and act
for their child — all scoped by domain-object UUIDs, not the Nextcloud user ids
that externals do not have (A4). The provider is the declarative delivery
vehicle for exactly that.

## Affected Projects

- [x] Project: `scholiq` — new `lib/Portal/PortalContributionProvider.php`,
  new `tests/Unit/Portal/PortalContributionProviderTest.php`, OpenSpec
  capability `portal-contribution`. No routes, controllers, services, frontend
  or info.xml changes. No register edit (that is `portal-identity`).

## Scope

### In Scope

- A plain `OCA\Scholiq\Portal\PortalContributionProvider` class (no portaliq
  imports, no `implements`, no constructor deps) exposing `getAudiences()`,
  `getAudience()`, `getContribution(array $subject): ?array`.
- `student` audience (claim `learnerRef`): six field-projected read collections
  (grade-entry, final-grade, attendance-record, enrolment, submission,
  excuse-request) scoped by `learnerRef` / `learnerRefs`; a `grade-notification`
  inbox (`kind: inbox`); two create-actions (Submission, ExcuseRequest) with
  strict field whitelists.
- `parent` audience (claim `guardianRef`): three read collections (grades,
  attendance, excuse-requests) resolved via a one-hop `guardianRefs` join to the
  child learner; one create-action (ExcuseRequest for a child) scope-stamped by
  `submittedByRef` at `minTrust: substantial`.
- Field projections that drop staff-only columns (grader identity + private
  comments, marking internals, marker + internal links, staff decision +
  assurance fields).
- PHPUnit unit tests for the full contract, incl. a register-drift pin against
  `scholiq_register.json`.

### Out of Scope

- The schema refs themselves — owned by `portal-identity` (this change's
  dependency).
- Any portal UI, auth edge, session, inbox rendering, or `via`/`minTrust`
  enforcement — portaliq owns the entire external surface (ADR-046).
- **Backfilling** existing rows with the refs (documented follow-up on
  `portal-identity`); until then read surfaces are fail-closed empty.
- Endpoint (A6) actions + assertion verification — none needed for this slice.
- The remaining ~30 Scholiq schemas — later portal slices.

## Approach

Duck-typed discovery per A1: portaliq resolves
`OCA\Scholiq\Portal\PortalContributionProvider` by FQCN and probes it with
`method_exists`; Scholiq ships a plain class with the three contract methods
and nothing else. `getContribution()` returns a pure-data manifest branched on
the server-derived `$subject['audience']` (fail-closed null otherwise). Scoping
follows A4 exclusively via the `portal-identity` UUID refs. The parent audience
uses a declared one-hop `via` join (guardian → learner → records); portaliq's
reader honours a direct `scopeField == subjectRef` match today and the `via`
hop when join support lands — until then parent reads are fail-closed. Details,
whitelist tables, claim contract and the minTrust story are in design.md.

## New Dependencies

None. The provider is dependency-free by contract; the class is inert when
portaliq is not installed.

## Impact

- `lib/Portal/PortalContributionProvider.php` — new, self-contained.
- `tests/Unit/Portal/PortalContributionProviderTest.php` — new.
- No register edit here; the `*Ref` properties land in `portal-identity`.
- No routes, controllers, services, frontend, or info.xml changes; no DI
  registration (discovery is pull-based from portaliq).

## Cross-Project Dependencies

Build/install time: none (that is the point of A1). Change-chain: **depends on
`portal-identity`** — the manifest's `scopeField`/`via` refs are meaningless
until those schema properties exist and are imported. Runtime: portaliq — when
installed — discovers and renders the contribution; contract v2 is implemented
in portaliq in parallel, hence both `getAudiences()` (v2) and `getAudience()`
(v1) are shipped.

## Risks

### Risk 1: Register drift breaks scoping silently

**Severity:** Medium — **Mitigation:** the unit suite includes a register-drift
pin that asserts every schema slug, scope field and whitelisted field the
manifest references exists in `scholiq_register.json`, so a rename or a missing
`portal-identity` ref fails the test, not production.

### Risk 2: Parent `via` join not yet honoured → parents see nothing

**Severity:** Low (by design) — **Mitigation:** this is the fail-closed default
for a minor's data. The `via` contract is declared and documented; when
portaliq's reader gains one-hop join support the parent surfaces light up with
no Scholiq change. Grades/attendance also carry the documented `minTrust`
raise-to-substantial note for the DigiD/eHerkenning broker.

### Risk 3: Contract v2 drift while portaliq lands in parallel

**Severity:** Medium — **Mitigation:** ship both `getAudiences()` and
`getAudience()`, use only amendment-fixed manifest keys, and pin the exact
shape in unit tests so any contract change is a visible, reviewed edit.

## Rollback Strategy

Delete `lib/Portal/` and `tests/Unit/Portal/`. Without the provider class,
portaliq discovery finds nothing and the portal shows no Scholiq section — the
app itself is unaffected. The `portal-identity` refs are additive and can stay
(or be rolled back separately); no object data is touched by this change.
