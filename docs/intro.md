---
sidebar_position: 1
---

# Scholiq

**Open-source education platform for Nextcloud** — leerlingvolgsysteem (LVS) for primary/secondary schools, full-stack LMS for higher education, and corporate-learning + compliance-training engine — all on one privacy-first, self-hosted stack.

## What is Scholiq?

Scholiq merges three traditionally separate worlds into one Nextcloud app: the Dutch K-12 student-tracking systems (Magister, SOMtoday, ParnasSys), the global open LMS market (Moodle, Open edX, Canvas), and corporate learning platforms (Docebo, TalentLMS, Cornerstone). It is the first Nextcloud-native education platform, built around the GEMMA reference model for Dutch onderwijs and the EU AI Act high-risk gating regime.

The app serves four primary audiences: **primary-school teachers** (PO) running OPP cycles for special-needs pupils, **secondary-school mentors** (VO) tracking absence patterns and PTA grades, **higher-ed coordinators** publishing OOAPI catalogs and managing Studielink enrolment, and **compliance officers** at MKB / government bodies who must prove annual AVG, BIO, and NIS2 board training. Scholiq integrates natively with DUO BRON/ROD, OSO transfer, SURFconext federation, EDCI / Open-Badges 3.0 credentials, and IMS QTI 3.0 assessment — all behind a modern Vue 3 surface that uses NL Design System primitives.

## Getting Started

- [Architecture & Data Model](./ARCHITECTURE) — Standards research (BRON, OSO, UWLR, QTI, cmi5, SCORM, EDCI), entity definitions, multi-tenancy strategy, AI Act feature gating
- [Feature Analysis](./FEATURES) — 354 canonical features, 52 competitors profiled, 159 tender records, 22 strategic insights, MVP / V1 / Enterprise roadmap
- [Design References](./DESIGN-REFERENCES) — Wireframes for teacher / student / compliance dashboards, course detail page, proctored exam runner, OPP cycle view, EDCI credential viewer, admin settings

## Why Scholiq?

- **First Nextcloud-native LMS** — schools control data; no SaaS lock-in; aligns with AVG-Onderwijs minimisation principles
- **Dutch market gatekeepers covered** — BRON/ROD (DUO), OSO transfer, UWLR, Edukoppeling, SchoolID/ECK iD pseudonymisation, SURFconext SSO
- **EU AI Act ready** — adaptive learning and proctoring features are flag-gated and audit-logged from day one (Reg. 2024/1689 high-risk classification)
- **Open standards first** — IMS QTI 3.0 native, cmi5 + xAPI primary content runtime (with SCORM 1.2/2004 compatibility shim), EDCI / Open-Badges 3.0 verifiable credentials, OAI-PMH metadata harvesting
- **Three markets, one codebase** — PO LVS (OPP, Handreiking, parent-signing), VO mentor tooling (PTA, leerplicht), HE coordination (OOAPI, Studielink, DigiD), corporate compliance (NIS2 board attestation, AVG refresher cycles)
- **EUPL-1.2 licensed** — government-grade reciprocity, EU-recognised, compatible with the Forum Standaardisatie open-source guidance
