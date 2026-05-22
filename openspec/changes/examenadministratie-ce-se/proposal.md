## Why

Examenadministratie is the most regulated process stream in Dutch secondary and vocational education. The Eindexamenbesluit VO dictates every step: PTA (Programma van Toetsing en Afsluiting) must be determined and locked before October 1 of the school year, individual exam scores (SE and CE) must be traceable to PTA items, the slaag-zakregeling (pass/fail rule) applies different kernvak (core subject) requirements per educational level (VMBO, HAVO, VWO), and every graduation must be verifiable by appeal and inspectorate audit. A single calculation error or miscommunicated score to DUO (the Dutch student finance agency) can mean the difference between a student passing or failing the year.

Scholiq currently lacks end-to-end exam administration: no PTA version control, no audit trail for score entry, no automated slaag-zakregeling logic per education level, no herkansing (retake) workflow, and no integration with the DUO BRON/ROOD specification for transmitting final grades. Schools manage these workflows via spreadsheets and manual DUO reporting, creating compliance risk and data integrity problems.

This spec delivers a complete, regulation-compliant exam administration system: the PTA is a first-class data model with automatic lock-after-October-1, exam scores (CE and SE) are keyed to PTA items with dual-entry verification for CE, the slaag-zakregeling is computed per education level with reasoning visible to examiners, herkansing deadlines are enforced, diplomas are batch-generated with examinee committee review, and DUO transmission is validated and tracked with return confirmation.

## What Changes

**New entities:**
- `ExamenKandidaat` — one per learner per exam year; holds status, education level, exam package
- `ExamenPakket` — subject selection per candidate; tracks core subjects, electives, exemptions, approvals
- `PTA` — program of testing and evaluation per subject per cohort; locked after October 1; version-controlled
- `CijferRegister` — all individual exam scores; keyed to PTA items; supports retakes with highest-score rule
- `SeEindcijfer` — derived SE final grade per subject per candidate; auto-calculated from weighted register scores
- `CeResultaat` — CE (central exam) score per subject per candidate per exam period; dual-entry verified
- `Eindcijfer` — combined SE+CE final grade per subject; rounded per spec rules
- `SlaagBerekening` — per-candidate pass/fail verdict; applies kernvak rule, CE average rule, compensation rule per education level
- `HerkansingsAanvraag` — retake request with deadline enforcement and status tracking
- `Diploma` — final credential with PDF; batch-generated; examinee committee approved; DUO-confirmed

**New capabilities:**
- `examenadministratie` — complete CE/SE exam administration, PTA versioning, auto-locking, score entry with validation, SE/CE/final grade calculation per education level, slaag-zakregeling logic, herkansing workflow, diploma batch generation and approval, DUO transmission, 50-year archival with integrity verification.

**Modified capabilities:**
- `scholiq-examenadministratie-integration` — integration layer to scholiq base (learner registry, class registry, teacher assignments) and openconnector DUO adapter (BRON/ROOD transmission).

## Impact

- **Data layer:** 10 new entities with OpenRegister schemas; 50+ fields covering exam workflows
- **Backend:** Service layer for PTA versioning, score validation, slaag-zakregeling calculation per education level, herkansing deadline enforcement, diploma batch generation, DUO transmission
- **Frontend:** 
  - Examensecretaris dashboard: candidate status, missing scores, retake queue, DUO readiness
  - PTA management: version history, lock status, publication to learners
  - Docent/examinator UI: score entry against PTA items, retake scoring
  - Learner portal: view own scores, retake requests, diploma download
  - Examinee committee: diploma batch review/approval before issuance
- **Integrations:** OpenConnector DUO adapter for BRON/ROOD transmission; docudesk for 50-year archival; decidesk for dispute resolution
- **Compliance:** Full Eindexamenbesluit VO mapping; AVG (GDPR) + Wet bescherming persoonsgegevens leerlingen; inspectorate audit trail
- **Database:** Migration to create 10 entity schemas, indexes on candidate+school+year, audit triggers on all score mutations
