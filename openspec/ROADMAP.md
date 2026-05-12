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
| school-structure | planned | 2 | — (run `/opsx:ff school-structure`) |
| assignments | planned | 2 | — |
| assessment | planned | 2 | — (replaces assessment-engine + proctoring) |
| grading | planned | 2 | — (replaces grading-pta) |
| learning-plan | planned | 2 | — (replaces opp-cycle) |
| attendance | planned | 2 | — (replaces absence-leerplicht) |
| data-exchange | planned | 2 | — (thin slice of bron-rod-exchange + oso-transfer) |

## Phases

### Phase 1 — The compliance-audit wedge — **done**

The smallest credible Scholiq a buyer would pay for: a Dutch compliance officer installs Scholiq + OpenRegister, enrols employees in a mandatory-training course, captures attestations, sees coverage % per regulation, and exports an audit-grade evidence pack — no code. 6 capability specs, all built (PRs #28, #30–#34, #37, #40, #41, #42).

### Phase 2 — The generic educational-institution core — **planned**

Model how a school / university / training firm actually operates — programmes governed by curriculum plans, cohorts meeting in sessions with materials and assignments, hand-ins and structured tests that get graded and roll up to a final grade, individual learning plans, attendance with threshold reporting, and a thin data-exchange layer that delegates the NL-gov wire protocols to OpenConnector. 7 jurisdiction-neutral specs (Dutch requirements as profiles). Build order: `school-structure` → `assignments` → `assessment` → `grading` → `learning-plan` → `attendance` → `data-exchange`.

### Phase 3 — Polish & advanced

OOAPI 5.0 catalog publication, EDCI verifiable credentials, AI features behind the `AiFeature` gate (adaptive learning, AI item generation, AI essay scoring, proctoring AI — each a registration), performance, accessibility, full localization, production hardening.

---

## How This Works

1. Run `/app-explore` to define features in `appspec/features/`
2. When a feature is `planned`, add it to the table above
3. Run `/opsx:ff {feature-name}` to create the implementation spec
4. Update the **OpenSpec Change** column with a link to the change directory
5. When all changes for a feature are done, mark the feature `done`
