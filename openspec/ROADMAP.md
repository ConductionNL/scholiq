# Roadmap

This document tracks the planned development of Nextcloud Scholiq.

Features are defined in [`appspec/features/`](../appspec/features/). When a feature reaches `planned` status during an `/app-explore` session, it is listed here and an OpenSpec change is created with `/opsx:ff`.

## Status Overview

See `WEDGE-PLAN.md` for the full rationale. Capability specs live in `openspec/specs/`.

| Feature | Status | Phase | OpenSpec Change |
|---------|--------|-------|-----------------|
| nextcloud-app | done | 1 | `changes/nextcloud-app` (archived) |
| course-management | done | 1 | `changes/course-management` (archived) — follow-up: collapse Course/Module → recursive Course, link CurriculumPlan |
| enrolment | done | 1 | `changes/enrolment` (archived) |
| certification | done | 1 | `changes/certification` (archived) |
| compliance-audit | done | 1 | `changes/compliance-audit` (archived) |
| dashboard | done | 1 | `changes/dashboard` (archived) |
| school-structure | done | 2 | `changes/archive/school-structure` (PR #45) |
| assignments | done | 2 | `changes/archive/assignments` (PR #47) |
| assessment | done | 2 | `changes/archive/assessment` (PR #48) — replaces assessment-engine + proctoring |
| grading | done | 2 | `changes/archive/grading` (PR #50) — replaces grading-pta |
| learning-plan | done | 2 | `changes/archive/learning-plan` (PR #51) — replaces opp-cycle |
| attendance | done | 2 | `changes/archive/attendance` (PR #52) — replaces absence-leerplicht |
| data-exchange | done | 2 | `changes/archive/data-exchange` (PR #53) — thin slice of bron-rod-exchange + oso-transfer; delegates wire protocols to OpenConnector (openconnector#753) |

## Phases

### Phase 1 — The compliance-audit wedge — **done**

The smallest credible Scholiq a buyer would pay for: a Dutch compliance officer installs Scholiq + OpenRegister, enrols employees in a mandatory-training course, captures attestations, sees coverage % per regulation, and exports an audit-grade evidence pack — no code. 6 capability specs, all built (PRs #28, #30–#34, #37, #40, #41, #42).

### Phase 2 — The generic educational-institution core — **built (2026-05-12)**

Model how a school / university / training firm actually operates — programmes governed by curriculum plans, cohorts meeting in sessions with materials and assignments, hand-ins and structured tests that get graded and roll up to a final grade, individual learning plans, attendance with threshold reporting, and a thin data-exchange layer that delegates the NL-gov wire protocols to OpenConnector. 7 jurisdiction-neutral specs (Dutch requirements — PTA, OPP, leerplicht-16-uur, BRON/OSO — as profiles), all merged: `school-structure` (#45) → `assignments` (#47) → `assessment` (#48) → `grading` (#50) → `learning-plan` (#51) → `attendance` (#52) → `data-exchange` (#53). Schema count 9 → 35; 89 manifest pages. Built on the `integration/scholiq-deps-all` OR branch (openregister#1470) — pending that landing in OR `development`, scholiq's CI PHPUnit/Newman jobs run against the released OR and are red there. NL-gov wire protocols + federated auth are OpenConnector adapters (openconnector#753), not scholiq code.

### Phase 3 — Polish & advanced

OOAPI 5.0 catalog publication, EDCI verifiable credentials, AI features behind the `AiFeature` gate (adaptive learning, AI item generation, AI essay scoring, proctoring AI — each a registration), performance, accessibility, full localization, production hardening.

---

## How This Works

1. Run `/app-explore` to define features in `appspec/features/`
2. When a feature is `planned`, add it to the table above
3. Run `/opsx:ff {feature-name}` to create the implementation spec
4. Update the **OpenSpec Change** column with a link to the change directory
5. When all changes for a feature are done, mark the feature `done`
