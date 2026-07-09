---
sidebar_position: 1
description: Get started with Scholiq, learning and course management on Nextcloud. Courses, enrolment, attendance, grading, and compliance training, manifest-first.
---

# Scholiq

**Open-source education platform for Nextcloud**, leerlingvolgsysteem (LVS) for primary/secondary schools, full-stack LMS for higher education, and corporate-learning + compliance-training engine, all on one privacy-first, self-hosted stack.

## What is Scholiq?

Scholiq merges three traditionally separate worlds into one Nextcloud app: the Dutch K-12 student-tracking systems (Magister, SOMtoday, ParnasSys), the global open LMS market (Moodle, Open edX, Canvas), and corporate learning platforms (Docebo, TalentLMS, Cornerstone). It is the first Nextcloud-native education platform, built around the GEMMA reference model for Dutch onderwijs and the EU AI Act high-risk gating regime.

The app serves four primary audiences: **primary-school teachers** (PO) running OPP cycles for special-needs pupils, **secondary-school mentors** (VO) tracking absence patterns and PTA grades, **higher-ed coordinators** who will publish OOAPI catalogs and manage Studielink enrolment once that data-exchange work ships, and **compliance officers** at MKB / government bodies who must prove annual AVG, BIO, and NIS2 board training. Today, Scholiq ships IMS QTI 2.x/3.0 assessment import and Open Badges 3.0 credential verification behind a modern Vue 3 surface that uses NL Design System primitives. DUO BRON/ROD, OSO transfer, SURFconext federation, UWLR and Edukoppeling are **not yet live** — Scholiq holds a generic `DataExchangeJob` queue (with an OSO parent-approval gate already built) designed to hand off to OpenConnector-configured connections for those protocols, but no education-specific OpenConnector adapter exists yet, so no wire-level BRON/OSO/SURFconext exchange can run out of the box.

## Getting Started

- [Architecture & Data Model](./Technical/architecture): Standards research (BRON, OSO, UWLR, QTI, cmi5, SCORM, EDCI), entity definitions, multi-tenancy strategy, AI Act feature gating
- [Feature Analysis](./Features/features): 354 canonical features, 52 competitors profiled, 159 tender records, 22 strategic insights, MVP / V1 / Enterprise roadmap
- [Design References](./Technical/design-decisions): Wireframes for teacher / student / compliance dashboards, course detail page, proctored exam runner, OPP cycle view, EDCI credential viewer, admin settings

## Why Scholiq?

- **First Nextcloud-native LMS**, schools control data; no SaaS lock-in; aligns with AVG-Onderwijs minimisation principles
- **Dutch market gatekeepers on the roadmap**, BRON/ROD (DUO), OSO transfer, UWLR, Edukoppeling, SchoolID/ECK iD pseudonymisation and SURFconext SSO are the design target for the `data-exchange` capability; none of them run end-to-end yet — see the note above
- **EU AI Act ready**, AI-assisted feature governance (register, lifecycle, DPO acknowledgement) is delegated to Conduction's Hermiq app; proctoring is an interface only today, with no wired provider yet
- **Open standards for what ships today**, IMS QTI 2.x/3.0 item-bank import and Common Cartridge import, Open Badges 3.0 verifiable credentials via a public verification endpoint
- **Content runtime, in progress**, the xAPI statement schema (LRS substrate) and its compliance lifecycle hook exist, but there is no learner-facing ingest endpoint yet and cmi5 AU launch-token minting is a documented, currently-disabled stub — tracked in `openspec/changes/cmi5-xapi-lrs-ingest/`
- **Three markets, one codebase**, PO LVS (OPP, Handreiking, parent-signing), VO mentor tooling (PTA, leerplicht), HE coordination and corporate compliance (NIS2 board attestation, AVG refresher cycles, audit-pack export) share one register-backed data model today; DigiD, Studielink and OOAPI publishing are still roadmap items
- **EUPL-1.2 licensed**, government-grade reciprocity, EU-recognised, compatible with the Forum Standaardisatie open-source guidance
