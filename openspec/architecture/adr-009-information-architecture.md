---
adr_id: ADR-009
title: Information architecture — six top-level menus, sectoren as config, aanleveringen first-class
status: accepted
category: architecture
date: 2026-05-23
accepted_at: 2026-05-23
deciders:
  - architecture-team
  - product
supersedes: []
depends_on:
  - ADR-002
  - ADR-005
  - ADR-008
applies_to:
  - course-management
  - school-structure
  - enrolment
  - attendance
  - assignments
  - grading
  - assessment
  - dashboard
  - learning-plan
  - compliance-audit
  - certification
  - examenadministratie-ce-se
  - bpv-praktijkleerovereenkomst
  - duo-bron-aanlevering
  - rio-register-instellingen-opleidingen
  - lerarenregister-koppeling
  - bekostiging-stelsel-1okt-1feb
  - elo-koppeling
  - data-exchange
  - nextcloud-app
references:
  - /tmp/ia-small5.md (Small-Five IA design session, 2026-05-22)
  - hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md
---

# ADR-009 — Information architecture: six top-level menus, sectoren as config, aanleveringen first-class

## Status

**accepted** (2026-05-23) — binding for every spec and PR that introduces or moves a top-level menu, sub-page, tab, widget, or settings surface in scholiq. Result of the 2026-05-22 Small-Five IA design session (`/tmp/ia-small5.md`, §4. scholiq), which fixed scholiq's nav at six menus and collapsed twenty spec slugs into a single hierarchy.

## Context

scholiq is the school administration platform for Dutch onderwijs sectoren (PO/VO/MBO/HBO/WO). Twenty distinct spec slugs span course catalog, inschrijvingen, opdrachten + cijfers, examenadministratie (CE + SE), aanwezigheid, diplomering, individueel leerplan, BPV (praktijkleren), compliance-audit, role-aware dashboards, ELO bridges (Moodle/Brightspace/Canvas), generic data-exchange, and the verplichte aanleveringen to DUO/BRON, RIO, and lerarenregister.

Without a fixed IA each spec lands as a sibling menu. That breaks four persona groups simultaneously:

- **docent** (Cursussen + Studenten) — wants one entry point per cursus, not five.
- **examencommissie** (Examens) — wants cijfers, CE, SE, diplomering, herkansingen in one menu.
- **stagecoordinator** (Praktijk) — wants BPV, leerbedrijven, beoordelingen in one place.
- **administratie / DUO-contactpersoon** (Aanleveringen + Beheer) — wants deadline-driven aanleveringen as core werk, not buried under admin.

Sector differences (PO vs VO vs MBO vs HBO) also threaten to fork the IA per sector, multiplying menu counts and creating per-sector code paths.

The Small-Five session resolved this with one hierarchy: six top-level menus, all spec slugs placed as sub-pages, tabs, widgets, or settings; sectoren as configuration; aanleveringen as a first-class menu (deadline workflow), with only adapters in Beheer.

## Decision

scholiq's information architecture is the following six top-level menus. No other top-level menu may be added without superseding this ADR.

```
1. Cursussen           (Courses & Curriculum)
2. Studenten
3. Examens
4. Praktijk            (BPV / werkplekleren)
5. Aanleveringen       (DUO/BRON, RIO, lerarenregister)
6. Beheer
```

### 1. Six menus, no more

Top-level navigation is fixed at six items. Tier-suffixed specs, adapter specs, and infrastructure specs are demoted to sub-pages, tabs, widgets, or settings under one of the six. A new capability lands inside an existing menu; promoting it to a seventh menu requires a superseding ADR.

### 2. Sectoren (PO/VO/MBO/HBO/WO) are configuration, not navigation

The same UI serves every sector. Sector toggles live in `Beheer > Sectoren`; sector-specific velden and aanlevering-types are hidden by configuration. A docent in a VO-school sees the same screens as a docent in MBO — never a sector-specific menu. CAO/sector-config is admin, not navigation.

### 3. Aanleveringen are a first-class top-level menu

DUO/BRON, RIO, lerarenregister, and bekostigingsteldatum are top-level under **Aanleveringen** because validate-and-submit is core deadline-driven werk, not configuration. Only the connector credentials (DUO endpoint, RIO credentials, lerarenregister keys) live in `Beheer > Connectors`. The action of indienen never lives in Beheer.

### 4. Cijfers live once, in `Examens > Cijferregister`

The cijferregister under Examens is authoritative. Cijfers are surfaced as tabs on Student (`Studenten > student > Cijfers`) and on Cursus (`Cursussen > cursus > Cijfers`), but never duplicated. A docent who edits a cijfer from the cursus-scherm sees the same value as the student and the examencommissie. There is no parallel cijferstore per surface.

### 5. ELO and data-exchange are adapters, not user-facing menus

ELO-koppeling (Moodle/Brightspace/Canvas), generic data-exchange, and the Nextcloud-app-shell config live as settings under **Beheer**. The docent sees user-facing actions ("ELO-koppeling testen", "Content ophalen uit Moodle") that hide the adapter; there is no top-level "Integraties" menu. This mirrors ADR-022 (apps consume OR abstractions) on the IA layer: integrations are infrastructure, not destinations.

### 6. Role-aware dashboards are one component with role-switching

Docent, mentor, coordinator, and student dashboards live in `Studenten > Dashboards` as one role-aware component, not four separate apps or menus. Promoting a docent to coordinator does not require a separate login or a separate menu surface — the component re-renders for the active role.

### 7. Tier-suffixed and adapter specs collapse into the six-menu hierarchy

Every spec slug has exactly one canonical placement (menu, sub-page, tab, widget, or settings). The mapping is the source of truth for IA decisions and is reproduced from `/tmp/ia-small5.md` §4.D:

| spec_slug | placement | parent | rationale |
|---|---|---|---|
| course-management | menu | Cursussen | core curriculum |
| school-structure | sub-page | Cursussen > Schoolstructuur (also Beheer) | structural data, visible to docent |
| enrolment | sub-page | Studenten > Inschrijvingen | enrolment is student-scoped |
| attendance | sub-page | Studenten > Aanwezigheid | attendance is student-scoped |
| assignments | sub-page | Cursussen > Opdrachten (+ Student tab) | opdrachten live on course, shown on student |
| grading | sub-page | Examens > Cijferregister (+ Student tab) | grades centralised under Examens |
| assessment | sub-page | Studenten > Beoordelingen | qualitative assessment under student |
| dashboard | sub-page | Studenten > Dashboards | role-aware dashboards |
| learning-plan | sub-page | Studenten > Individueel leerplan | personal-plan scoped to student |
| compliance-audit | sub-page | Studenten > Compliance-training | training compliance per student |
| certification | sub-page | Examens > Certificaten | credentials follow diploma flow |
| examenadministratie-ce-se | sub-page | Examens > CE/SE | exam administration core |
| bpv-praktijkleerovereenkomst | sub-page | Praktijk > Praktijkleerovereenkomsten | BPV agreements |
| duo-bron-aanlevering | sub-page | Aanleveringen > DUO/BRON | DUO submission |
| rio-register-instellingen-opleidingen | sub-page | Aanleveringen > RIO | RIO submission |
| lerarenregister-koppeling | sub-page | Aanleveringen > Lerarenregister | teacher registry submission |
| bekostiging-stelsel-1okt-1feb | sub-page | Aanleveringen > Bekostigingsteldatum | funding count dates |
| elo-koppeling | settings | Beheer > ELO-koppeling | LMS adapter |
| data-exchange | settings | Beheer > Data-exchange | generic exchange config |
| nextcloud-app | settings | Beheer > Nextcloud-app-shell | shell config |

### 8. Per-menu sub-architecture is normative

The sub-pages, tabs, widgets, actions, and settings listed in `/tmp/ia-small5.md` §4.C per menu are the normative starting set. Adding a sub-page, tab, or widget to a menu is a design change tracked in a spec; removing one requires explicit deprecation. Beheer is the only menu where extra sub-pages may be added without a spec (config surfaces grow with connectors).

### 9. Implementation phases follow the IA, not the other way around

The phased rollout in `/tmp/ia-small5.md` §4.E (Phase 1 MVP-cursus → Phase 2 examens → Phase 3 praktijk → Phase 4 compliance + aanleveringen) is binding for sequencing. Earlier phases may not introduce sub-pages that belong to later phases; later phases may not promote sub-pages to top-level menus to "land sooner".

## Consequences

### Positive

- A docent, examencommissie-lid, stagecoordinator, or administratie-medewerker each has exactly one obvious entry point — the menu count stays at six for every persona.
- Sector toggles prevent the menu from forking per PO/VO/MBO/HBO; one codebase, one IA, one training set for users.
- Aanleveringen at top level makes DUO/BRON/RIO/lerarenregister deadlines visible; they no longer hide in Beheer where deadlines get missed.
- Cijfers as a single source under Examens removes the classic onderwijs anti-pattern of two grade systems (docent's gradebook ≠ examencommissie's cijferlijst).
- Role-aware dashboards as one component cap maintenance cost; adding a sixth role is a switch case, not a fifth app.
- Adapters under Beheer keep the user-facing surfaces uncluttered; ELO/data-exchange/Nextcloud-app-shell churn does not move user menus.
- Twenty specs land in six menus + Beheer with zero ambiguity, killing the "where does this spec go" debate at planning time.

### Negative / trade-offs

- New capabilities must justify themselves against an existing menu; a genuinely new top-level surface requires a superseding ADR, which is friction by design.
- Aanleveringen as a top-level menu adds one item beyond a typical five-menu app; accepted because the deadline-driven workflow demands visibility.
- Sector toggles add config complexity in Beheer (each sector enables/disables fields and aanlevering types); accepted because the alternative is per-sector forks.
- Role-aware dashboards as a single component means a regression in one role's view affects all roles; mitigated by per-role e2e tests.

## Alternatives considered

| Option | Reason not chosen |
|---|---|
| Flat menu per spec slug (twenty top-level items) | Breaks every persona; matches the legacy onderwijspakket anti-pattern the IA session explicitly rejected. |
| Per-sector IA (separate menus for PO/VO/MBO/HBO) | Multiplies code paths and training; sectoren are configuration per the Small-Five decision. |
| Aanleveringen under Beheer | Deadline-driven workflow buried under admin; aanleveringen are core werk for administratie persona. |
| Separate cijfer stores per surface (docent vs examencommissie) | Classic onderwijs anti-pattern — two grade systems drift; one cijferregister under Examens is authoritative. |
| Top-level "Integraties" menu for ELO/data-exchange | Adapters are infrastructure (ADR-022 IA mirror); user actions hide the adapter, settings live in Beheer. |
| Role-specific dashboard apps (docent-app, mentor-app, coordinator-app) | Quadruples maintenance; role-switching component is the canonical pattern. |

## References

- `/tmp/ia-small5.md` §4 — Small-Five IA design session, 2026-05-22, scholiq section.
- ADR-002 — Content runtime cmi5 + xAPI (informs Cursussen and Examens sub-architecture).
- ADR-005 — EU AI Act gating (AI features land as widgets/actions inside the six menus, never as a separate "AI" menu).
- ADR-008 — Audit trail consumed from OpenRegister (audit tabs render inside Studenten/Examens/Praktijk surfaces, no separate audit menu).
- Hydra ADR-022 — apps consume OR abstractions (IA-layer analogue: adapters are settings, not destinations).
