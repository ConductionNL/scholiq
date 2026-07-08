# Design: portal-parent

## Architecture Overview

`portal-parent` re-enables the `parent` (guardian) audience that
`portal-contribution` shipped deferred. It is a pure edit to the already-merged
provider class — no new file, no schema, no route. Portaliq resolves
`OCA\Scholiq\Portal\PortalContributionProvider` by FQCN and calls
`getContribution($subject)`; with `$subject['audience'] === 'parent'` the new
`parentContribution()` returns a declarative manifest whose reads route through
portaliq's **merged reverse / scope-value `via` join**.

```
parent subject (subjectRef = guardian UUID, claim `guardianRef`)
  └─ getContribution() → parentContribution()
       ├─ reads: grade-entry / attendance-record / excuse-request
       │    via {schema: learner-profile, scopeField: guardianRefs,
       │         targetField: id, match: 'scopeField'}   (reverse join)
       │    → guardian → child LearnerProfile(s) → records WHERE learnerRef ∈ children
       └─ actions: [] (create withheld — see "Write-IDOR guard" below)
```

## The reverse / scope-value `via` join (contract v2.2, ADR-046 A5 ext)

Verified against portaliq's merged reader
(`origin/development:lib/Service/PortalObjectReader.php`, portaliq#15):
`readCollection()` → `readViaCollection()` → `verifiedJoinTargets()` +
`filterTargetRows()` / `rowInTargetSet()`.

### The exact via declaration this change ships

Every parent read collection carries the identical join:

```php
'via' => [
    'register'    => 'scholiq',
    'schema'      => 'learner-profile',
    'scopeField'  => 'guardianRefs',   // join row's field matched vs the scope value
    'targetField' => 'id',             // join row's field collected into the target set
    'match'       => 'scopeField',     // REVERSE: keep outer rows by their own scopeField
],
```

and on the collection itself: `scopeField: 'learnerRef'`, `scopeClaim: 'guardianRef'`.

**Key names are contract law.** `isValidVia()` recognises EXACTLY
`register, schema, scopeField, targetField` (all non-empty strings, no nested
`via`) plus an optional `match ∈ {'id','scopeField'}`. The deferral placeholder
used invented keys (`matchField`, `selectField`) that **do not exist** in the
reader — they would leave `scopeField`/`targetField` unset, fail `isValidVia()`,
and return zero rows. This change uses the real keys.

### Why `targetField: 'id'` resolves the child LearnerProfile UUID

`grade-entry.learnerRef` is documented as *"UUID reference to the LearnerProfile
object"* — i.e. the child profile's own OpenRegister object UUID. The join row
**is** that `learner-profile` object, so `targetField` must name the property
holding its own UUID. A normalised OR row exposes it at the **top-level `id`
key**: `ObjectEntity::jsonSerialize()` sets `$object['id'] = $this->uuid` (and
`@self.id = uuid`). There is no top-level `uuid` key unless the domain data
carries one — `learner-profile` does not — so `targetField: 'uuid'` would
`dotGet` to null and collect nothing. `targetField: 'id'` is therefore the
correct token, and it matches how the reader's own `rowIds()` reads a row's
identity. It is the OR object-identity token, **not** a schema property, so the
register-drift pin checks it against `{id, uuid}` rather than the schema.

### End-to-end flow (how each side compares like-for-like)

1. `scopeClaim: 'guardianRef'` → the reader resolves the guardian UUID from the
   subject's own `portalAccount` claim (`claims['scholiq']['guardianRef']`),
   server-side, never client input. That becomes `$scopeValue`.
2. `verifiedJoinTargets()` queries `learner-profile` (best-effort filter
   `guardianRefs == $scopeValue`, row-capped at 500) and, **per row**, verifies
   `joinRowMatches(row, 'guardianRefs', $scopeValue)` — `guardianRefs` is an
   array, so this is a strict `in_array` containment. For each survivor it
   collects `targetRefs(dotGet(row, 'id'))` → the child's LearnerProfile UUID —
   into the target set.
3. The outer read pulls `grade-entry` rows and, with `match: 'scopeField'`,
   `rowInTargetSet(row, targets, 'scopeField', 'learnerRef')` keeps a row iff
   `targetRefs(dotGet(row, 'learnerRef'))` intersects the target set — i.e. the
   record's `learnerRef` is one of the guardian's children's UUIDs.

Both sides normalise through the same `targetRefs()` (string or array-of-strings),
so the comparison is strict UUID-to-UUID membership. An empty target set can
only ever yield zero rows (the "never widen" fail-closed floor), and the tenant
`organisation` check still runs per row on both the join and the outer rows.

## Worked nil-UUID example

Seed convention (see `portal-identity/design.md`) stamps refs at apply-time with
the nil-UUID placeholder `00000000-0000-0000-0000-000000000000` until a real
`LearnerProfile` is seeded. Concrete guardian `G` = `22222222-…-2222`, child
profile object UUID `L` = `aaaaaaaa-…-aaaa`:

| Object                         | Field         | Value                         |
|--------------------------------|---------------|-------------------------------|
| guardian subject               | subjectRef    | `G` (claim `guardianRef`)     |
| child `learner-profile` (id=`L`) | guardianRefs  | `[G]`                         |
| `grade-entry` #1               | learnerRef    | `L`      → **kept**           |
| `grade-entry` #2               | learnerRef    | `bbbb-…` (other learner) → dropped |
| `grade-entry` #3               | learnerRef    | `00000000-…-0000` (unseeded nil) → dropped |

- Join pre-pass: `learner-profile` id=`L`, `guardianRefs=[G]` contains `G` →
  survives; `dotGet(row,'id') = L` → target set `{ L }`.
- Outer match (`match: 'scopeField'`, `scopeField: 'learnerRef'`): grade #1
  `learnerRef=L ∈ {L}` → kept; grade #2 `≠ L` → dropped; grade #3 nil-UUID
  `∉ {L}` → dropped (an unbackfilled row stays invisible — fail-closed).
- Degenerate guardian (no child, or `guardianRefs` unset/nil): target set `{}` →
  **zero** rows for every collection, never all rows.

## minTrust story

Recorded on the provider and enforced portaliq-side:

- **parent grade / attendance / excuse reads:** `minTrust: substantial` — a
  guardian authenticating to a **minor's** records requires substantial
  assurance (this pairs with the DigiD/eHerkenning broker per ADR-046). This is
  raised from the earlier "low-today" placeholder in `portal-contribution`'s
  deferral note: because the reverse join is now live, the reads are real, so
  the trust floor is set to its true value now rather than deferred.
- (Student reads/creates are unchanged at `low`.)

## Write-IDOR guard — why the parent create is withheld

A guardian reporting an absence would `type: create` an `excuse-request`.
Portaliq's writer stamps `scopeField == subjectRef`, and a parent's `subjectRef`
is the **guardian** UUID — so the guardian lands in `submittedByRef`. But the
record also needs the **child** `learnerRef`, which is a *different* value from
the subject, so it can only be **client-supplied** (whitelisted). Portaliq's
writer does not verify that a client-supplied cross-reference is one the
guardian's `guardianRefs` actually covers — so a guardian could file an excuse
against **any** child's record. That is a write IDOR, and it is **not**
fail-closed (the writer would store the smuggled `learnerRef`).

Therefore the parent audience ships **`actions: []`** — reads only. The reverse
`via` join protects the reads (each row is verified against the guardian's child
set); the create waits on a portaliq writer follow-up that resolves the same
one-hop join and rejects a create whose cross-referenced child is outside the
subject's reverse-join set. Once that lands, re-adding the create is a pure
provider addition (the trimmed action is preserved in this change's git
history).

## Field projection

Parent read projections are byte-identical to the student surface for the same
schema (grade-entry, attendance-record, excuse-request) — the same staff-only
columns are dropped (grader identity + comment on grades, `markedBy` on
attendance, `submittedBy`/`submittedByRef`/`submittedAuthLevel`/`decidedBy`/
`decisionNote` on excuse requests). See `portal-contribution/design.md` for the
whitelist tables.

## API / Database / Seed / Nextcloud Integration

None. No routes, controllers, services, mappers, migrations or seed objects.
Reads/creates go through OpenRegister's object API, invoked by portaliq
server-side (ADR-022). No `Application.php` registration — discovery is
pull-based from portaliq by FQCN.

## Security Considerations

- **Server-derived subject only** (ADR-005 / A6): the guardian UUID comes from
  the subject's own `portalAccount` claim, resolved server-side; the provider
  only branches on `audience`.
- **Fail-closed everywhere:** an absent claim, an unset `guardianRefs`, an
  invalid via, or an empty child set all yield zero rows — never a wider read.
- **UUID-domain scoping (A4):** every scope key is a `LearnerProfile` / guardian
  object UUID, never a Nextcloud user id.
- **Minor-data trust:** `substantial` on all parent surfaces.

## Trade-offs

- **Reverse `via` join vs a first-class Guardian schema** — the one-hop join over
  `guardianRefs` is the minimum honest guardian linkage; a Guardian domain object
  is a later slice.
- **`targetField: 'id'` (OR identity) vs a materialised self-UUID property** —
  reusing the OR object identity avoids a redundant schema field; the drift pin
  documents that `id` is an identity token, not a schema property.
- **`substantial` now vs low-until-broker** — the reads are live, so the true
  trust floor is declared now; portaliq enforces it (no exposure meanwhile).
