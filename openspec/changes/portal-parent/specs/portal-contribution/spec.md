# portal-contribution Specification (delta: portal-parent)

This delta re-enables the `parent` audience against portaliq's merged reverse /
scope-value `via` join (contract v2.2, ADR-046 A5 ext). It supersedes the
deferred/placeholder shape from `portal-contribution` (which used the invented
`matchField`/`selectField` keys that do not exist in portaliq's reader) with the
reader's real contract: `{register, schema, scopeField, targetField}` plus
`match: 'scopeField'`.

## MODIFIED Requirements

### Requirement: Parent manifest resolves child via a reverse scope-value join (REQ-PCON-004)

For a `parent` subject `getContribution()` MUST return a manifest labelled
`Scholiq` whose three read collections (`grade-entry`, `attendance-record`,
`excuse-request`) each carry a one-hop reverse `via` join whose keys are EXACTLY
`{register: scholiq, schema: learner-profile, scopeField: guardianRefs,
targetField: id, match: 'scopeField'}` — the exact contract portaliq's
`PortalObjectReader::isValidVia()` recognises — with the collection's own
`scopeClaim` `guardianRef`, `scopeField` `learnerRef`, and `minTrust`
`substantial`. The `via.scopeField` `guardianRefs` is the `learner-profile`
field matched (array-contains) against the resolved guardian UUID; `via.targetField`
`id` collects each matched child profile's own OpenRegister object UUID (the
top-level identity `ObjectEntity::jsonSerialize()` exposes), which the outer
records match on their own `learnerRef` under `match: 'scopeField'`. The parent
manifest MUST ship `actions: []` (reads only): a guardian create would require a
client-supplied child `learnerRef` cross-reference that portaliq's writer does
not yet verify against the guardian's `guardianRefs` (a write IDOR), so the
create is withheld until that writer-side validation lands. Field projection on
reads MUST be identical to the student surface for the same schema. Invented via
keys (`matchField`/`selectField`) MUST NOT be used — they fail portaliq's
`isValidVia()` closed to zero rows.

#### Scenario: Parent subject receives reverse-joined reads and no create action

- GIVEN a subject whose `audience` is `parent` and `subjectRef` is a guardian domain-object UUID resolved from the `guardianRef` claim
- WHEN `getContribution($subject)` is called
- THEN each of `parentGrades`, `parentAttendance`, `parentExcuseRequests` carries a `via` whose keys are exactly `register, schema, scopeField, targetField, match`, with `schema` `learner-profile`, `scopeField` `guardianRefs`, `targetField` `id` and `match` `scopeField`, and the collection's own `scopeField` is `learnerRef`, `scopeClaim` is `guardianRef`, `minTrust` is `substantial`
- AND the manifest's `actions` is empty (the guardian create is withheld pending portaliq writer cross-ref validation — a write IDOR guard)
- @e2e exclude parent resolution is executed by portaliq's reader, not by any Scholiq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php: testParentManifestShape, testParentCollectionsUseReverseScopeValueVia, testParentShipsNoCreateActionPendingCrossRefValidation)

### Requirement: Scoping uses portal-identity UUID refs and a verified via shape (REQ-PCON-005)

The manifest MUST reference only scope fields, `via` join fields and whitelisted
fields that exist in `lib/Settings/scholiq_register.json` — the UUID domain refs
added by `portal-identity` (`learnerRef`, `learnerRefs`, `submittedByRef`,
`guardianRefs`) and never a Nextcloud user id (A4). A unit register-drift pin
MUST fail if any referenced schema slug or property is renamed or missing. For
the parent `via` join the pin MUST assert `via.scopeField` (`guardianRefs`)
exists on the `via` schema (`learner-profile`), and MUST assert `via.targetField`
is either a real property on that schema OR an OpenRegister object-identity token
(`id`/`uuid`) — so the reverse join can never silently break, and an invented
key can never masquerade as a real one.

#### Scenario: Manifest references only properties present in the register (or identity tokens)

- GIVEN the shipped `scholiq_register.json` and both audience manifests
- WHEN every collection/action schema slug, `scopeField`, whitelisted field and parent `via.scopeField`/`via.targetField` is checked against the register
- THEN each schema slug, `scopeField` and whitelisted field resolves to a real schema property
- AND each parent `via.scopeField` (`guardianRefs`) exists on `learner-profile` and each `via.targetField` (`id`) is a register property or an OR identity token
- AND `grade-entry` defines `learnerRef`, `submission` defines `learnerRefs`, `excuse-request` defines `submittedByRef`, and `learner-profile` defines `guardianRefs`
- @e2e exclude register-vs-manifest consistency is a backend invariant with no UI surface — covered by the register-drift-pin PHPUnit test (tests/Unit/Portal/PortalContributionProviderTest.php: testManifestMatchesRegisterSchemas)
