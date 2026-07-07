---
capability: portal-contribution
status: in-progress
built_by: openspec/changes/portal-contribution
---

# portal-contribution Specification

**Status**: in-progress
**Scope**: scholiq
**Depends on**: `portal-identity`
**OpenSpec changes**:
- [portal-contribution](../../changes/portal-contribution/) _(active)_ — plain ADR-046 provider class for the `student` + `parent` audiences (kind: code, depends_on portal-identity)
- [portal-parent](../../changes/portal-parent/) _(active)_ — re-enables the `parent` audience against portaliq's merged reverse / scope-value `via` join (`match: 'scopeField'`); corrects the via key-set (kind: code, depends_on portal-contribution)

## Purpose

Scholiq contributes a `student` (the learner) and a `parent` (a guardian)
section to portaliq, the shared external portal for people without Nextcloud
accounts (hydra ADR-046 + contract v2). The contribution is one plain,
dependency-free provider class (`OCA\Scholiq\Portal\PortalContributionProvider`,
duck-typed by FQCN — inert without portaliq) that declares field-projected read
collections, whitelisted create-actions and a learner inbox, all scoped by the
UUID domain-object refs from `portal-identity` (never a Nextcloud user id, A4).

## Requirements

Detailed requirements (REQ-PCON-001 … REQ-PCON-005) are defined in the active
change's delta spec —
[`openspec/changes/portal-contribution/specs/portal-contribution/spec.md`](../../changes/portal-contribution/specs/portal-contribution/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
umbrella requirement below anchors the capability until then.

### Requirement: Scholiq ships an ADR-046 portal contribution scoped by domain UUIDs (REQ-PCON-000)

The app MUST serve its portal contribution through one plain, dependency-free
`OCA\Scholiq\Portal\PortalContributionProvider` class (duck-typed by FQCN,
inert without portaliq) that declares the `student` and `parent` audiences and
scopes every read/create by the `portal-identity` UUID domain refs, never a
Nextcloud user id. No other portal contribution logic, UI, or dependency may
ship in Scholiq.

#### Scenario: The contribution is one plain, domain-UUID-scoped class

- GIVEN a Scholiq install with portaliq present
- WHEN portaliq resolves `OCA\Scholiq\Portal\PortalContributionProvider` and calls `getContribution()` for a student or parent subject
- THEN it receives a declarative manifest scoped exclusively by UUID domain refs (`learnerRef` / `learnerRefs` / `submittedByRef` / `guardianRefs`)
- AND the provider imports nothing from portaliq and is inert when portaliq is absent
- @e2e exclude backend-only contract class rendered by portaliq, not by any Scholiq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)
