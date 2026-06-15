---
status: proposed
---

# Nextcloud App Shell — Integration surfaces under Settings

**Status:** proposed
**Scope:** scholiq
**Tier:** leaf
**Depends on:** ADR-037 (modular config fragments + canonical REQ-ID), ADR-022 (apps consume OpenRegister abstractions), ADR-012 (deduplication)

## Purpose

Relocate Scholiq's integration / data-plumbing navigation ("Data exchange" and
"xAPI statements") out of the primary transactional navigation and into the
Settings/gear group as a coherent "Integrations" cluster, following the docudesk IA
model — while keeping every underlying page routable for deep links. Genuine
learner/instructor/officer transactional surfaces, including Compliance, stay in the
primary navigation.

## MODIFIED Requirements

### Requirement: REQ-SIS-001 — The system SHALL surface "Data exchange" under the Settings group, not as primary transactional navigation

The system SHALL render the `DataExchange` menu entry within the Settings/gear group by setting `"section": "settings"` on that `menu[]` entry in `src/manifest.json`, and SHALL NOT render it as a top-level primary-navigation item. The entry SHALL retain its label "Data exchange" and `"route": "DataExchangeJobs"`, and SHALL be ordered within the settings group ahead of the xAPI statements entry. No `pages[]` entry SHALL be added, removed, or repointed by this requirement.

#### Scenario: Data exchange appears under the gear, not in primary nav

- **GIVEN** the rendered Scholiq navigation
- **WHEN** the menu is inspected
- **THEN** the "Data exchange" entry is present in the Settings/gear group (`section: "settings"`)
- **AND** it is NOT present among the top-level primary-navigation entries (Courses, Enrolments, Grades, Attendance)

#### Scenario: Data exchange route is unchanged

- **WHEN** the relocated "Data exchange" entry is activated
- **THEN** it routes to `DataExchangeJobs` exactly as before the move

### Requirement: REQ-SIS-002 — The system SHALL group xAPI statements with Data exchange as one Integrations cluster under Settings

The system SHALL keep the `XapiStatementsMenu` entry under `"section": "settings"` and SHALL order it immediately after the relocated `DataExchange` entry so that "Data exchange" and "xAPI statements" read as one coherent Integrations cluster under the gear. The `XapiStatements` index SHALL remain read-only and its route SHALL be unchanged.

#### Scenario: Integrations entries are adjacent under Settings

- **WHEN** the Settings/gear group is inspected
- **THEN** "Data exchange" and "xAPI statements" appear adjacent, with "Data exchange" ordered first
- **AND** the "xAPI statements" index remains read-only

## ADDED Requirements

### Requirement: REQ-SIS-003 — The system SHALL keep all relocated integration pages routable for deep links

The system SHALL keep every Data exchange and xAPI `pages[]` entry declared with its existing route after the menu relocation, so that deep links and `@route`-bound detail pages resolve unchanged. This SHALL include at least `/data-exchange/jobs`, `/data-exchange/jobs/:id`, `/data-exchange/jobs/:id/oso-review`, `/data-exchange/mapping-profiles`, `/data-exchange/mapping-profiles/:id`, `/data-exchange/request`, `/xapi-statements`, and `/xapi-statements/:id`. Removing an entry from the menu SHALL NOT remove or repoint its page.

#### Scenario: A bookmarked data-exchange job detail still resolves

- **GIVEN** a bookmarked deep link to `/data-exchange/jobs/:id`
- **WHEN** the user opens it after the relocation
- **THEN** the data-exchange job detail page renders (the page entry is unchanged)

#### Scenario: The OSO dossier review deep link still resolves

- **WHEN** a user navigates to `/data-exchange/jobs/:id/oso-review`
- **THEN** the OSO dossier review page renders unchanged

#### Scenario: An xAPI statement deep link still resolves

- **WHEN** a user navigates to `/xapi-statements/:id`
- **THEN** the read-only xAPI statement detail page renders unchanged

### Requirement: REQ-SIS-004 — The system SHALL retain Compliance and all transactional surfaces in the primary navigation

The system SHALL keep `Compliance` and every genuine learner/instructor/officer transactional menu entry (Courses, Enrolments, Credentials, Curriculum, Grades, Assignments, Assessments, Learning plans, Attendance, Learners) in the primary navigation, and SHALL NOT relocate them under Settings. The `Compliance` entry SHALL retain its `visibleIf` gate limiting visibility to `compliance-officer` and `hr` roles, because Compliance is officer/audit transactional work rather than app configuration.

#### Scenario: Compliance stays a primary-nav, role-gated entry

- **GIVEN** the rendered navigation for a user in the `compliance-officer` role
- **WHEN** the menu is inspected
- **THEN** "Compliance" is present in the primary navigation (not under the Settings/gear group)
- **AND** it is not visible to a user lacking the `compliance-officer`/`hr` role

#### Scenario: No transactional surface is moved under Settings

- **WHEN** the Settings/gear group is inspected after the change
- **THEN** it contains only configuration, health, assistant, AI-features, and the Integrations cluster (Data exchange, xAPI statements)
- **AND** it contains none of Courses, Enrolments, Grades, Attendance, or Compliance

### Requirement: REQ-SIS-005 — The change SHALL be navigation metadata only, touching no schema, route, or backend

The change SHALL modify only `menu[]` entry metadata (`section` / `order`) in `src/manifest.json` and SHALL NOT modify any `pages[]` entry, OpenRegister schema, register fragment, route definition, or controller. This keeps the change a pure IA relocation consistent with ADR-022 (no new app-side machinery duplicating OpenRegister) and ADR-012 (reuse the existing `section: "settings"` grouping rather than build new Settings UI).

#### Scenario: Diff is confined to menu metadata

- **WHEN** the change's diff is reviewed
- **THEN** the only edits are to `section`/`order` on `menu[]` entries in `src/manifest.json`
- **AND** no `pages[]` entry, schema file, register fragment, route, or controller is changed
