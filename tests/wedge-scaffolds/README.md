# Wedge test scaffolds

This directory holds Newman-shaped TDD stubs for the 14 capability specs in `openspec/specs/`. They are deliberately **outside** `tests/integration/` so the CI Newman job doesn't try to run them — the endpoints don't exist yet, and running them in CI would always fail.

## Workflow

When a Hydra PR implements a capability spec:

1. **Move the matching folder** out of `tests/wedge-scaffolds/scholiq-wedge.postman_collection.json` and into `tests/integration/scholiq.postman_collection.json` (under the active `item[]`).
2. **Implement the endpoints** the requests target.
3. **CI Newman job** runs against the moved folder and validates the implementation against the previously-static contract.

This pattern gives Hydra builders a concrete API target before code lands, without burning CI red on the development branch.

## Files

| File | Purpose |
|---|---|
| `scholiq-wedge.postman_collection.json` | 14 folders × 3-7 requests = 56 requests covering all 19 critical-priority user stories from `intelligence-db.user_stories`. Postman Collection v2.1.0. |

## Folder order (matches WEDGE-PLAN.md sequencing)

**Phase 1 (wedge)** — implement these first:
1. `course-management`
2. `enrolment`
3. `certification`
4. `compliance-audit` (wedge core — 7 requests)
5. `dashboard`
6. `nextcloud-app`

**Phase 2 (NL gatekeeper)**:
7. `bron-rod-exchange`
8. `oso-transfer`
9. `absence-leerplicht`
10. `identity-federation`

**Phase 3 (Assessment + credentials)**:
11. `assessment-engine`
12. `proctoring`
13. `grading-pta`
14. `opp-cycle`

## Convention reminders

- All requests use basic auth (`{{adminUser}}` / `{{adminPass}}`) — overridable per Newman environment.
- All requests target `{{baseUrl}}/index.php/apps/scholiq/api/…`.
- `OCS-APIRequest: true` header is required on every request (Nextcloud convention).
- Each request's `description` cites the user-story slug + the openspec/specs/ path so Hydra builders can trace evidence.
- Each request's `event.test` script asserts a 200/201/204 status + expected top-level response shape — these are the contracts the implementation must satisfy.
