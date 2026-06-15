---
kind: code
depends_on: []
---

# Proposal: scholiq-integration-to-settings

kind: IA / navigation cleanup — cites **ADR-037** (modular config fragments + canonical REQ-ID), **ADR-022** (apps consume OpenRegister abstractions), **ADR-012** (deduplication).

## Summary

Scholiq exposes its integration and data-plumbing surfaces as top-level transactional navigation alongside genuine learner-facing data. "**Data exchange**" (`DataExchange`, route `DataExchangeJobs`, `order: 60`) sits in the primary menu at the same altitude as Courses, Enrolments, Grades and Attendance, even though it is purely an integration surface (data-mapping profiles, export/import jobs, OSO dossier review). "**xAPI statements**" (`XapiStatementsMenu`, route `XapiStatements`) is a read-only learning-record integration *log/stream* and is already tagged `section: "settings"` but reads as an ad-hoc settings entry rather than a coherent integrations group.

Per the IA pattern (the good model = docudesk: config/types/definitions/**integrations**/retention belong under a **Settings** group, NOT as top-level transactional nav), this change relocates the two integration entries into the existing Settings/gear group as a coherent "Integrations" cluster, while **keeping every page routable** so deep links and `@route`-bound detail pages continue to resolve. The change is navigation-only metadata in `src/manifest.json` (the entry's `section`/`order`); no page is deleted, no route changes, no schema or controller is touched.

**Compliance is explicitly NOT moved.** `Compliance` (route `Compliance` → `ScholiqCompliance`, with Regulations / Attestations / audit-pack export underneath) is genuine compliance/audit *transactional* surface — officers act on signed attestations and produce audit packs — not app configuration. It stays a top-level entry (gated to `compliance-officer` / `hr`).

## Motivation

- **Altitude mismatch.** A learner or instructor scanning the primary menu sees "Data exchange" between domain concepts they act on daily; it is administrator/integrator plumbing that belongs behind the gear. xAPI statements likewise is an integration log, not a daily task.
- **Coherent integrations group.** xAPI is already under `section: "settings"` but as a lone entry; Data exchange is top-level. Grouping both (consistently ordered) under Settings gives one obvious home for "how data flows in/out of Scholiq".
- **Routability preserved.** Removing an entry from the primary menu must never break a deep link. Every `data-exchange/*` and `xapi-statements/*` page stays declared in `pages[]` with its existing route, so bookmarks, detail-page navigation, and OSO-review deep links resolve unchanged (the docudesk precedent: relocate the menu entry, keep the page).

## Affected Projects
- [ ] Project: `scholiq` — `src/manifest.json` `menu[]` metadata only (relocate `DataExchange` into the Settings section; align `XapiStatementsMenu` ordering within the same group). No `pages[]`, schema, route, or backend change.

## Scope

### In Scope
- Relocate the `DataExchange` menu entry from the primary navigation (`order: 60`, no `section`) into the Settings/gear group by setting `section: "settings"` and an order consistent with the other settings entries; keep `route: "DataExchangeJobs"`.
- Align `XapiStatementsMenu` (already `section: "settings"`) so Data exchange + xAPI statements read as one ordered "Integrations" cluster under Settings.
- Keep **all** data-exchange and xAPI `pages[]` entries (index + detail + custom flows) declared and routable for deep links.

### Out of Scope
- Moving any genuine learner/instructor/officer transactional surface (Courses, Enrolments, Grades, Attendance, **Compliance**, Learners, Learning plans, Assignments, Assessments).
- Renaming routes, changing page `type`, editing schemas, register fragments, controllers, or notification rules.
- Building an actual Settings sub-section component for integrations (the existing gear grouping via `section: "settings"` is reused as-is — ADR-022/ADR-012: do not invent new UI machinery when the manifest section already groups entries).

## Approach

scholiq has **no** `src/menu-layout.json` and **no** `src/manifest.d/` fragment directory — it carries a single bundled `src/manifest.json` (the pre-ADR-037 layout) and groups settings entries with the per-entry `section: "settings"` flag (already used by `AdminHealthMenu`, `XapiStatementsMenu`, `AiFeaturesMenu`, `AssistantMenu`). This change therefore follows scholiq's own established pattern: edit the menu entry's `section`/`order` in place, exactly as docudesk relocates a menu entry while keeping the page routable. Per ADR-037's canonical REQ-ID rule, the spec requirements use `REQ-SIS-NNN` ids.

## New Dependencies
None.

## Impact
- `src/manifest.json` — `menu[]`: `DataExchange` gains `section: "settings"` and a settings-group `order`; `XapiStatementsMenu` order aligned. `pages[]` unchanged.

## Cross-Project Dependencies
None. Self-contained within scholiq.

## Risks

### Risk 1: A deep link or detail page breaks after the menu move
**Severity:** Low — **Mitigation:** only the `menu[]` entry's `section`/`order` change; every `pages[]` entry (routes `/data-exchange/...`, `/xapi-statements/...`) is left intact, so all deep links and `@route`-bound detail pages resolve unchanged. A regression check asserts the routes still resolve.

### Risk 2: Mis-classifying Compliance as config and hiding officer workflows
**Severity:** Low — **Mitigation:** Compliance is explicitly excluded; it stays a top-level entry gated to `compliance-officer`/`hr`. The spec names it as deliberately retained.

## Rollback Strategy
Single-file metadata change in `src/manifest.json`; revert via `git revert` of the change commit. No data migration, no schema change, nothing to undo server-side.

## Capabilities
- Modified: `nextcloud-app` (navigation IA — integration surfaces relocated under Settings, pages kept routable)
