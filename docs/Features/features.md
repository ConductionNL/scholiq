# Scholiq, Feature Analysis & Product Strategy

## Executive Summary

Scholiq is an open-source, Nextcloud-native learning platform that fuses three categories, Leerlingvolgsysteem (LVS) for Dutch primary and secondary education, Learning Management System (LMS) for corporate and higher education, and credentialing/assessment infrastructure, into one EUPL-1.2 surface. The intelligence brief identifies 159 procurement records, 52 profiled competitors, 354 deduplicated canonical features (71 must, 229 should), 121 verified external sources, and 26 linked standards. No competitor on the market today operates as a Nextcloud app: Moodle, Canvas, Open edX, ILIAS, Sakai and Chamilo all live outside the school's existing collaboration stack, and every Dutch K-12 incumbent (ParnasSys, Magister, SOMtoday, ESIS, It's Learning, SchoolWise, Kindkans, Basispoort) is closed-source SaaS.

The opportunity is structural rather than incremental. ParnasSys controls roughly 65% of the Dutch primary segment under Topicus/Visma ownership, while Magister (55%) and SOMtoday (40%) duopolise secondary education, and all three carry a documented UX and privacy backlash captured across AOb, De Correspondent, NOS, FtM and Computable reporting. Open-source LMS leaders share dated interfaces and require separate hosting, identity, file storage and conferencing infrastructure. The EU AI Act (Reg. 2024/1689) reclassifies adaptive learning and proctoring as high-risk systems, and AVG-Onderwijs enforces minimisation, DPIA, parental consent and pseudonymisation via SchoolID and ECK iD. Together these forces create a forced rebuild window: institutions must replace systems they cannot easily explain or audit.

## 1. Strategic Positioning

### 1.1 No Nextcloud-native LVS or LMS exists today

Across the 52 competitors profiled in the intelligence database, not one is delivered as a Nextcloud app. Moodle (37k stars, GPL-3.0), Canvas LMS (5.6k stars, AGPL-3.0), Open edX (7.5k stars, AGPL-3.0), ILIAS (400 stars, GPL-3.0), Sakai (1.2k stars, ECL-2.0), Chamilo, OpenOLAT, Forma, ATutor, Claroline, Opigno and Kolibri all assume their own user database, file store, conferencing layer, and admin chrome. Schools that have already adopted Nextcloud for files, talk, calendar and groups currently bolt an LMS on top through SAML and LTI rather than running it natively. Scholiq inverts that relationship, `nc:files` is the content store, `nc:talk` is the virtual classroom, `nc:calendar` is the lesson timetable, `nc:groups` is the cohort, and `nc:user-saml` is the SURFconext bridge. This is the structural differentiator: a school that runs Nextcloud already runs 60% of an LMS.

### 1.2 Dutch incumbents face a switching window

The intelligence brief flags ParnasSys at roughly 65% market share in PO under Topicus/Visma ownership, and the Magister 55% / SOMtoday 40% duopoly in VO. All three are systemically reviewed for UX debt, privacy posture and pricing opacity across mainstream journalism (AOb, De Correspondent, NOS, FtM) and review platforms (Capterra, G2). Switching costs are real, 12 to 18 month migration cycles, but the brief identifies SIVON, the cooperative procurement channel representing 1,000+ school boards, as a centralised route to address that switching cost across many institutions in parallel. The Dutch government separately spends an estimated €250M annually on civil-servant training (RADIO, A+O fonds Rijk), and no open Dutch assessment platform exists at all, Cito, DiatOets and IEP are closed.

### 1.3 OSS LMS leaders all carry dated UX

The 13 OSS competitors profiled (Moodle, Canvas, Open edX, ILIAS, Sakai, Chamilo, OpenOLAT, Forma, ATutor, Claroline, Opigno, Kolibri, Gibbon, Totara) hold over 60% of global higher-education share, Moodle alone serves 400M users across 240 countries, but every external review captured in the database surfaces the same complaints: dated theming, slow page rendering, fragmented mobile experience, complicated admin chrome. The intelligence brief flags "modern Vue/NL-Design surface" as a competitive differentiator (insight ID: competitive-gap, impact: high). Scholiq inherits @conduction/nextcloud-vue and the NL Design System out of the gate, including WCAG 2.1 AA, government palette, and apexcharts-based analytics primitives.

### 1.4 AI Act and AVG force a privacy-first rebuild

EU AI Act (Reg. 2024/1689) classifies adaptive learning and proctoring as high-risk AI, a critical-impact legal-requirement insight. AVG-Onderwijs (Autoriteit Persoonsgegevens guidance) plus the Cyberbeveiligingswet (NIS2) drive DPIA, minimisation, parental consent, immutable evidence logs and pseudonymisation via SchoolID and ECK iD as non-negotiables. Closed-source SaaS incumbents cannot easily provide the audit trail or sovereign hosting that a school's data protection officer increasingly requires. Self-hosted Nextcloud, running on a school's own Strato/Hetzner/SURF infrastructure or behind a Cyso-managed ISAE 3402 boundary, provides the data-control posture that the regulations demand. Scholiq carries that posture inherently because it is a Nextcloud app, not a SaaS tenant.

### 1.5 SIVON, EDCI and corporate training expand the addressable market

The Dutch education market totals 7,400+ schools (PO 6,600, VO 650, HO 51) and roughly 2.5M students. SIVON aggregates procurement across 1,000+ boards, EDCI / Europass Digital Credentials open the diploma and microcredential market, and post-COVID corporate e-learning continues to grow 15%+ annually in a Dutch corporate segment estimated at €200M+. The same engine that serves a basisschool's OPP cycle and a VO mentor's PTA grading also serves a Rijksambtenaar completing an annual BIO/AVG refresher under A+O fonds Rijk or RADIO. The brief flags this convergence as `market-insight, high`: per-seat corporate LMS pricing is disrupted by open-source self-hosting in the MKB segment.

## 2. Competitive Landscape

### 2.1 Top 15 Competitors Across 5 Segments

| Competitor | Segment | License | Stars | Pricing | Features captured |
|---|---|---|---:|---|---:|
| Odoo eLearning | Corporate/ERP-LMS | LGPL-3.0 (CE) / Proprietary (EE) |, | subscription | 31 |
| Moodle | OSS LMS | GPL-3.0 | 37,000 | open-source | 15 |
| Docebo | Corporate LMS | Proprietary |, | per-user | 14 |
| SAP SuccessFactors Learning | Corporate LMS | Proprietary |, | per-user | 13 |
| TalentLMS | Corporate LMS | Proprietary |, | subscription | 13 |
| Canvas LMS | OSS LMS | AGPL-3.0 | 5,600 | freemium | 12 |
| Cornerstone OnDemand | Corporate LMS | Proprietary |, | per-user | 12 |
| Litmos (SAP) | Corporate LMS | Proprietary |, | per-user | 12 |
| LearnUpon | Corporate LMS | Proprietary |, | per-user | 12 |
| Teachable | Course Creator | Proprietary |, | subscription | 12 |
| 360Learning | Collaborative LMS | Proprietary |, | per-user | 12 |
| Udemy Business | Course Marketplace | Proprietary |, | per-user | 12 |
| Kajabi | Course Creator | Proprietary |, | subscription | 12 |
| eFront | Corporate LMS | Proprietary |, | subscription | 12 |
| iSpring Learn | Corporate LMS | Proprietary |, | per-user | 12 |

### 2.2 Dutch K-12 Incumbents (segment 6, captured separately)

| Vendor | Segment | License | Market share | Notes |
|---|---|---|---|---|
| ParnasSys | PO LAS/LVS | Proprietary SaaS | ~65% | Topicus/Visma; UX + privacy backlash |
| Magister | VO LAS/LVS | Proprietary SaaS | ~55% | Iddink/SanomaLearning; duopoly with SOMtoday |
| SOMtoday | VO LAS/LVS | Proprietary SaaS | ~40% | Topicus; duopoly with Magister |
| ESIS (Cito) | PO LVS | Proprietary SaaS | minority | Cito-aligned testing |
| It's Learning | VO LMS | Proprietary | minority | Norwegian-origin |
| SchoolWise | PO LAS | Proprietary | minority | Reformatorisch onderwijs focus |
| Kindkans | PO leerlingbegeleiding | Proprietary | niche | Special-needs (samenwerkingsverbanden) |
| Basispoort | PO SSO/methode-toegang | Proprietary | sector-wide | Federated method access (not a full LVS) |

### 2.3 Assessment, Credentialing and Big-Tech (selection)

| Competitor | Segment | License | Notes |
|---|---|---|---|
| Inspera Assessment | HE assessment | Proprietary | High-stakes exams, EDCI pathway |
| Cito / DiatOets / IEP | PO/VO assessment | Proprietary | No open NL assessment exists |
| Google Classroom | Big-tech | Proprietary | Bundled with Workspace |
| Microsoft Teams Education | Big-tech | Proprietary | Bundled with M365 |
| Brightspace (D2L) | HE LMS | Proprietary | Strong NL HE footprint |
| Totara | Corporate (OSS-derived) | GPL-3.0 | Moodle fork |
| Open edX | OSS LMS | AGPL-3.0 | edX.org engine |

## 3. Feature Matrix

### 3.1 Course Management

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| Course Management (CRUD, versions, prerequisites) | MVP | 153 | 43 | 12 | Top demand; all OSS + corporate competitors deliver it |
| Classroom management | MVP | 153 | 43 | 12 | Bridges cohort to course; `nc:groups` integration |
| Resource management | MVP | 153 | 43 | 12 | Books, devices, rooms, `nc:files` for content |
| Instructor-led training (ILT) | MVP | 153 | 43 | 12 | Scheduling via `nc:calendar` |
| Instructor Management | MVP | 151 | 43 | 11 | Roster, qualifications, availability |
| Drag-and-drop course builder | V1 | 10 | 0 | 5 | UX parity with Teachable/Kajabi |
| Course site builder | V1 | 13 | 1 | 5 | Public-facing landing pages |
| Multi-Format Course Content (HTML, video, PDF) | V1 | 4 | 0 | 2 | Mixed-media authoring |
| Drip content scheduling | V1 | 4 | 0 | 2 | Time-released lessons |
| Multi-course prerequisites | Enterprise | 4 | 0 | 2 | Complex learning-path gating |
| Learning Paths | V1 | 15 | 1 | 6 | Sequenced multi-course flows |
| Studio (Course Authoring) | V1 | 5 | 1 | 1 | Open edX-style structured authoring |
| Shape (content authoring) | Enterprise | 4 | 0 | 2 | Block-based authoring engine |

### 3.2 Assessment & Examination

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| QTI 3.0 item banks (import + author) | MVP | critical | story |, | IMS QTI 3.0 native (ADR-004); no open NL platform exists |
| Take an online proctored exam | MVP | critical | story |, | Critical user story; AI Act high-risk surface |
| Configure proctoring per exam | MVP | critical | story |, | Provider-pluggable proctoring |
| Detect student exam conflicts | MVP | critical | story |, | Scheduling integrity |
| Student quizzes and assessments | V1 | 4 | 0 | 2 | Formative assessment |
| Soft-publish grades to review the cohort first | V1 | 15 | 5 | 0 | Dutch VO grade-publication pattern |
| Gradebook | V1 | 4 | 0 | 2 | Cohort-level grade overview |
| PTA weighting per kolom | V1 | critical | story |, | Dutch VO grading rule |
| Sync exam calendar to the LMS | V1 | 4 | 0 | 2 | Bidirectional exam scheduling |
| Inspera-style high-stakes exam orchestration | Enterprise |, |, | 1 | Plug-in providers via ADR-004 |
| Lockdown browser integration | Enterprise |, |, |, | Provider-specific (Respondus, Safe Exam Browser) |

### 3.3 Certification & Credentials

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| Certification management | MVP | 153 | 43 | 12 | Top-7 demand; mandatory for compliance + corporate |
| Credential management (Open Badges + EDCI) | MVP | 153 | 43 | 12 | Europass/EDCI opens microcredential market |
| Competency management | MVP | 153 | 43 | 12 | Skills/competency framework |
| Skills management | MVP | 153 | 43 | 12 | Linked to credentials |
| Issue an edubadge as digital credential | V1 | 18 | 4 | 3 | NL edubadges federation (SURF) |
| Professional certificates | V1 | 6 | 0 | 3 | PDF + signed PDF output |
| Certificates and badges | V1 | 6 | 0 | 3 | Mixed credential strategies |
| Custom certificates | V1 | 6 | 0 | 3 | Template-driven generation |
| White-label credentials | V1 | 6 | 0 | 3 | Institution branding |
| Certificate Template Designer | V1 | 4 | 0 | 2 | Drag-drop template editor |
| EDCI signing + verification (Europass) | Enterprise | story |, |, | Cryptographic credential signing |
| Skills Framework (e21st CC / o*NET) | Enterprise | 4 | 0 | 2 | Imported skills taxonomies |
| Track time-to-competence per role | Enterprise | 9 | 3 | 0 | Workforce analytics |

### 3.4 Compliance Training

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| Compliance management | MVP | 166 | 44 | 17 | Highest demand feature in entire app |
| Compliance Training | MVP | 23 | 1 | 10 | Annual cycle |
| Capture signed attestation per learner | MVP | critical | story |, | AVG/BIO refresher |
| Bulk-enroll all employees in annual refresher | MVP | critical | story |, | Cohort enrolment |
| Detect upcoming certificate expiries | MVP | critical | story |, | Renewal workflow |
| Compliance tracking | MVP | 16 | 2 | 5 | Coverage % per regulation |
| Compliance automation | V1 | 23 | 1 | 10 | Rule-driven enrolment |
| Compliance assessments | V1 | 23 | 1 | 10 | Embedded quizzes |
| Compliance suite | V1 | 23 | 1 | 10 | Cross-regulation bundle |
| Annual Compliance Training Audit (audit pack export) | V1 | critical | story |, | Export per regulation |
| Prove board training (NIS2/Cyberbeveiligingswet) | V1 | critical | story |, | Board-level audit |
| Maintain immutable evidence log | V1 | critical | story |, | Audit trail (OR-backed) |
| Global compliance | Enterprise | 23 | 1 | 10 | Multi-jurisdiction packs |
| SCORM compliance (legacy 1.2/2004) | Enterprise | 23 | 1 | 10 | Shim per ADR-002 |

### 3.5 Identity & Federation

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| User management | MVP | 135 | 43 | 3 | `nc:user` + SURFconext |
| Group management | MVP | 153 | 43 | 12 | `nc:groups` |
| SURFconext SSO (NL HE) | MVP | story |, |, | ADR-003; eduPersonAffiliation |
| Nextcloud user-saml (K-12) | MVP |, |, |, | ADR-003 |
| SchoolID + ECK iD pseudonymisation | MVP | critical |, |, | AVG/AP mandatory |
| DigiD authentication (parents/students) | MVP | critical | story |, | Studielink, ziekmelding |
| SSO and API | V1 | 24 | 0 | 12 | OAuth2/OIDC clients |
| SSO and LDAP | V1 | 4 | 0 | 2 | On-prem directory bind |
| SCIM user provisioning | V1 | 9 | 1 | 3 | HRIS-driven user lifecycle |
| Custom user types | V1 | 9 | 1 | 3 | Parent/staff/inspector personas |
| Federated identity to eduGAIN | Enterprise |, |, |, | International HE |
| Multi-tenant separation per board | Enterprise | 10 | 0 | 5 | ADR-007 |

### 3.6 Student Administration (NL)

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| BRON/ROD koppeling (DUO) | MVP | 49 | 5 | 17 | Non-negotiable for PO/VO |
| OSO Transfer Dossier PO to VO | MVP | critical | story |, | Mandatory NL gatekeeper |
| Inline correction of DUO afkeurmeldingen | MVP | critical | story |, | Critical user story |
| VO mentor imports OSO into LAS | V1 | high | story |, | High-priority story |
| Pull enrolment data from Studielink (HE) | MVP | critical | story |, | DigiD-verified enrolment |
| Publish course catalog via OOAPI 5.0 | V1 | 15 | 5 | 0 | Edustandaard 5.0 |
| Rapportage DUO | V1 | 15 | 1 | 6 | DUO reporting cycle |
| UWLR koppeling (methode-uitwisseling) | MVP |, |, |, | Edukoppeling mandatory |
| Edukoppeling transport binding | MVP |, |, |, | Gatekeeper standard |
| Auto-track 16-uur leerplicht threshold | MVP | critical | story |, | Compulsory-education law |
| Report sick via DigiD-authenticated app | V1 | 15 | 1 | 6 | Parent ziekmelding |
| Mentor sees absence patterns at a glance | V1 | high | story |, | VO mentor workflow |
| Create OPP from sector template | MVP | critical | story |, | PO special-needs cycle |
| Parent digitally signs OPP | V1 | high | story |, | Parental consent |
| Quarterly OPP evaluation reminder | V1 | high | story |, | Cycle automation |

### 3.7 Analytics

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| Analytics dashboard | MVP | 39 | 3 | 15 | Top demand; apexcharts primitives |
| Student Analytics | MVP | 34 | 2 | 14 | Per-learner views |
| Reports and analytics | MVP | 34 | 2 | 14 | Operational reports |
| Course Performance Analytics | V1 | 26 | 2 | 10 | Per-course KPIs |
| Learning Analytics | V1 | 26 | 2 | 10 | Caliper Analytics standard |
| Manager Dashboard | V1 | 17 | 1 | 7 | Corporate/board view |
| Learner Dashboard | V1 | 17 | 1 | 7 | Self-service progress |
| Advanced analytics | V1 | 34 | 2 | 14 | Cohort comparisons |
| Analytics Pipeline | Enterprise | 34 | 2 | 14 | xAPI/Caliper warehouse |
| Badge analytics | V1 | 34 | 2 | 14 | Credential issuance |
| Credential analytics | V1 | 34 | 2 | 14 | Per-credential KPIs |
| Generate group-level trend report | V1 | 15 | 1 | 6 | Cohort trend |
| ProPanel reporting | V1 | 15 | 1 | 6 | Real-time admin panel |
| Custom reports | V1 | 8 | 0 | 4 | User-defined queries |
| Progress Tracking | V1 | 11 | 1 | 4 | Lesson-level |
| Report renewal status to board | V1 | 13 | 1 | 5 | Compliance KPI |
| Coverage % per regulation in real time | V1 | critical | story |, | Compliance audit |
| AI Act high-risk model monitoring | Enterprise |, |, |, | Regulatory artefact |

### 3.8 Integrations

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| REST API | MVP | 24 | 0 | 12 | OpenRegister-provided |
| API-first platform | MVP | 24 | 0 | 12 | OR + openconnector |
| Webhooks and API | MVP | 24 | 0 | 12 | Outbound events |
| API access | MVP | 8 | 2 | 1 | Authenticated tokens |
| HR System Integration | MVP | 47 | 5 | 16 | AFAS/Visma/Workday via openconnector |
| Google Meet Integration | V1 | 45 | 5 | 15 | `nc:talk` preferred fallback |
| Website Integration | V1 | 15 | 5 | 0 | Embed widgets |
| Classroom API | V1 | 24 | 0 | 12 | LTI 1.3 inbound + outbound |
| API and integrations | V1 | 24 | 0 | 12 | Marketplace |
| SCORM/xAPI/cmi5 support | MVP | 14 | 0 | 7 | ADR-002; cmi5 primary |
| SCORM Support (1.2 + 2004 shim) | V1 | 14 | 0 | 7 | Legacy content compatibility |
| SCORM and Tin Can support | V1 | 8 | 0 | 4 | xAPI alias |
| LTI 1.3 provider + consumer | MVP |, |, |, | Tool interoperability |
| QTI 3.0 in/out | V1 |, |, |, | Item-bank portability |
| Common Cartridge import | V1 |, |, |, | Course portability |
| Caliper Analytics endpoint | V1 |, |, |, | xAPI alternative |

### 3.9 Content & Authoring

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| Built-in content library | V1 | 7 | 1 | 2 | Reusable assets via `nc:files` |
| SCORM content import | V1 | 6 | 0 | 3 | Legacy course packages |
| SCORM Content Support | V1 | 8 | 0 | 4 | Runtime player |
| Custom content hosting | V1 | 4 | 0 | 2 | `nc:files` bucket |
| Content Marketplace | Enterprise | 6 | 0 | 3 | Third-party catalogue |
| Multi-language support (NL/EN minimum) | MVP | 10 | 0 | 5 | i18n requirement |
| Accessibility (WCAG 2.1 AA) | MVP | 4 | 0 | 2 | NL Design baseline |
| Accessibility Checker | V1 | 4 | 0 | 2 | Author-time validation |
| AI-powered learning | Enterprise | 7 | 1 | 2 | AI Act gated (ADR-005) |
| AI-powered recommendations | Enterprise | 4 | 0 | 2 | AI Act gated |

### 3.10 Collaboration & Communication

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| Virtual classroom (`nc:talk`) | MVP | 4 | 0 | 2 | Native NC Talk |
| Discussion Forums | V1 | 10 | 0 | 5 | Course forums |
| Social Learning | V1 | 12 | 2 | 3 | Peer interaction |
| Community platform | V1 | 7 | 1 | 2 | Cohort feeds |
| Reactions and feedback | V1 | 5 | 1 | 1 | Comments/upvotes |
| Record and review | V1 | 5 | 1 | 1 | Lesson recording (`nc:talk`) |
| Survey capabilities | V1 | 5 | 1 | 1 | Course evaluation |
| Knowledge base | V1 |, |, |, | Shared with opencatalogi/decidesk |
| Blended learning | V1 | 5 | 1 | 1 | Hybrid in-person + online |

### 3.11 Mobile

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| Mobile responsive (NL Design + nc-vue) | MVP |, |, |, | Baseline; no native app needed |
| Mobile Learning | V1 | 22 | 0 | 11 | Offline-capable PWA |
| Mobile app (Go.Learn-equivalent PWA) | V1 | 18 | 0 | 9 | Branded PWA |
| White-label mobile app | Enterprise | 22 | 0 | 11 | Institution-branded |

### 3.12 Administration & Workflow

| Feature | Tier | Demand | Tenders | Comp. | Rationale |
|---|---|---:|---:|---:|---|
| Automations (rules engine) | V1 |, |, |, | Platform shared (5 apps) |
| Custom plugins (Plugin Ecosystem) | Enterprise |, |, |, | OR + scholiq shared |
| Multi-tenant architecture | Enterprise | 10 | 0 | 5 | Per-board separation |
| Self-hosted | MVP |, |, |, | EUPL-1.2 + NC native |
| Payment processing | Enterprise |, |, |, | Course commerce |
| E-commerce module | Enterprise | 10 | 0 | 5 | Public course sales |
| Extended enterprise | Enterprise | 4 | 0 | 2 | External-learner portals |
| Multi-portal architecture | Enterprise | 4 | 0 | 2 | Branded sub-portals |
| Open source LMS | MVP | 11 | 1 | 4 | Positioning capability |
| White-label experience | Enterprise | 6 | 0 | 3 | Theme/logo override |
| Gamification engine | V1 | 14 | 0 | 7 | Badges/points/leaderboards |
| Gamification | V1 | 14 | 0 | 7 | Same engine, surfaced |
| Affiliate marketing | Enterprise | 4 | 0 | 2 | Course-creator economy |
| Digital downloads | V1 | 18 | 4 | 3 | Asset delivery |
| Rooster (timetable) | V1 | 5 | 1 | 1 | NL school timetable |
| Calendar and scheduling | V1 | 4 | 0 | 2 | `nc:calendar` |
| Apply 30-60-90 onboarding template | V1 | high | story |, | Corporate onboarding |
| Track Digital Opportunity Index for onboarding | V1 | 12 | 4 | 0 | Onboarding analytics |
| Enterprise LMS (orchestration) | Enterprise | 5 | 1 | 1 | Cross-tenant admin |

## 4. MVP Scope (25 must-have features)

Pulled from `canonical_features` where `priority='must'` plus critical user stories. The MVP delivers a Dutch-ready LVS+LMS with compliance training, identity federation and analytics.

1. Course Management (CRUD, versions, prerequisites)
2. Classroom management (cohorts on `nc:groups`)
3. Instructor Management
4. Resource management (materials, rooms, devices)
5. ILT management (instructor-led training on `nc:calendar`)
6. Skills + Competency + Certification + Credential management (linked entities)
7. Compliance management + Compliance Training + Compliance tracking
8. Group management (`nc:groups`)
9. User management + SURFconext SSO + Nextcloud user-saml
10. SchoolID + ECK iD pseudonymisation
11. DigiD authentication (parents/students)
12. BRON/ROD koppeling (DUO)
13. UWLR + Edukoppeling transport binding
14. OSO Transfer Dossier PO to VO
15. Pull enrolment data from Studielink (HE)
16. Create OPP from sector template (PO special-needs)
17. Auto-track 16-uur leerplicht threshold
18. QTI 3.0 item banks (import + author) + proctored online exam
19. PTA weighting per kolom (VO grading)
20. Analytics dashboard + Student Analytics + Reports and analytics
21. REST API + API-first platform + Webhooks
22. HR System Integration (via openconnector)
23. SCORM/xAPI/cmi5 support + LTI 1.3 provider+consumer
24. Multi-language support (NL/EN minimum) + WCAG 2.1 AA
25. Virtual classroom (`nc:talk`) + Mobile responsive surface

## 5. V1 Features (45 should-have)

V1 lifts Scholiq from "ready for one school board" to "credible across the Dutch + corporate market."

26. Drag-and-drop course builder
27. Course site builder (public landing pages)
28. Multi-Format Course Content (HTML/video/PDF)
29. Drip content scheduling
30. Learning Paths (sequenced courses)
31. Studio (Course Authoring)
32. Soft-publish grades to review the cohort first
33. Gradebook
34. Student quizzes and assessments
35. Sync exam calendar to the LMS
36. Issue an edubadge as digital credential
37. Professional certificates + Certificates and badges + Custom certificates
38. White-label credentials
39. Certificate Template Designer
40. Compliance automation + Compliance assessments + Compliance suite
41. Annual Compliance Training Audit (audit pack export per regulation)
42. Prove board training (NIS2/Cyberbeveiligingswet)
43. Maintain immutable evidence log
44. SSO and API + SSO and LDAP + SCIM user provisioning + Custom user types
45. VO mentor imports OSO into LAS
46. Publish course catalog via OOAPI 5.0
47. Rapportage DUO
48. Report sick via DigiD-authenticated app
49. Mentor sees absence patterns at a glance
50. Parent digitally signs OPP + Quarterly OPP evaluation reminder
51. Course Performance Analytics + Learning Analytics + Advanced analytics
52. Manager Dashboard + Learner Dashboard
53. Badge analytics + Credential analytics
54. Generate group-level trend report + ProPanel reporting
55. Custom reports
56. Progress Tracking
57. Report renewal status to board
58. Coverage % per regulation in real time
59. Google Meet Integration (fallback to `nc:talk`)
60. Website Integration (embed widgets)
61. Classroom API (LTI 1.3 inbound + outbound)
62. QTI 3.0 in/out + Common Cartridge import + Caliper Analytics endpoint
63. SCORM Support (1.2 + 2004 shim) + SCORM and Tin Can support + SCORM content import
64. Built-in content library + Custom content hosting
65. Accessibility Checker
66. Discussion Forums + Social Learning + Community platform
67. Reactions and feedback + Record and review + Survey capabilities + Blended learning
68. Mobile Learning + Mobile app (Go.Learn-equivalent PWA)
69. Gamification engine + Gamification
70. Digital downloads
71. Rooster (timetable) + Calendar and scheduling
72. Apply 30-60-90 onboarding template + Track Digital Opportunity Index

## 6. Enterprise Features (25 advanced)

Enterprise tier targets multi-board boards, HE consortia, and Rijksoverheid training, including AI Act gating and EDCI credential signing.

73. Multi-course prerequisites
74. Shape (block-based content authoring engine)
75. Inspera-style high-stakes exam orchestration (provider plug-ins per ADR-004)
76. Lockdown browser integration (Respondus / Safe Exam Browser)
77. Proctoring providers, pluggable (online + onsite)
78. EDCI signing + verification (cryptographic Europass credentials)
79. Skills Framework (e21st CC / o*NET imports)
80. Track time-to-competence per role
81. Global compliance (multi-jurisdiction packs)
82. SCORM compliance (legacy 1.2/2004 deep certification)
83. Federated identity to eduGAIN
84. Multi-tenant separation per board (ADR-007)
85. Multi-portal architecture
86. Extended enterprise (external-learner portals)
87. Analytics Pipeline (xAPI/Caliper warehouse)
88. AI Act high-risk model monitoring + adaptive-learning gating (ADR-005)
89. AI-powered learning + AI-powered recommendations (AI Act gated)
90. Content Marketplace (third-party catalogue)
91. White-label mobile app
92. White-label experience (full theme/logo override)
93. E-commerce module + Payment processing
94. Affiliate marketing
95. Custom plugins (Plugin Ecosystem)
96. Enterprise LMS orchestration (cross-tenant admin)
97. Automations (rules engine)

## 7. Settings & Notifications

### 7.1 Admin Settings (IAppConfig)

| Setting | Feature source | Type | Default | Tier |
|---|---|---|---|---|
| `default_register` | Course/student schemas | string (OR register id) | `scholiq` | MVP |
| `default_school_type` | OPP/PTA branching | enum (PO/VO/MBO/HBO/WO) | `PO` | MVP |
| `bron_endpoint` | BRON/ROD koppeling | URL | `https://www.duo.nl/...` | MVP |
| `bron_client_id` | BRON/ROD | string | empty | MVP |
| `oso_endpoint` | OSO transfer | URL | empty | MVP |
| `uwlr_endpoint` | UWLR koppeling | URL | empty | MVP |
| `edukoppeling_oin` | Edukoppeling | string (OIN) | empty | MVP |
| `surfconext_idp` | SURFconext SSO | URL | empty | MVP |
| `schoolid_provider` | SchoolID/ECK iD | enum | `kennisnet` | MVP |
| `digid_endpoint` | DigiD parents/students | URL | empty | V1 |
| `studielink_endpoint` | HE enrolment | URL | empty | V1 |
| `ooapi_publish` | Course catalog | bool | false | V1 |
| `cmi5_enabled` | Content runtime | bool | true | MVP |
| `scorm_shim_enabled` | Legacy SCORM | bool | true | V1 |
| `qti_default_version` | Assessment | enum (3.0/2.x) | `3.0` | MVP |
| `proctoring_providers` | Proctored exams | JSON list | `[]` | V1 |
| `ai_act_high_risk_features` | AI gating | JSON list of feature ids | `[]` | Enterprise |
| `ai_act_audit_retention_days` | AI gating | int | 730 | Enterprise |
| `dpia_required_features` | AVG | JSON list | `[]` | MVP |
| `parental_consent_required_under_age` | AVG | int | 16 | MVP |
| `compliance_regulations` | Compliance audit | JSON list (AVG, BIO, NIS2, ...) | seeded | MVP |
| `certificate_template_default` | Certification | string | `default.pdf` | V1 |
| `edci_signing_key_path` | EDCI | string (path) | empty | Enterprise |
| `notification_defaults` | Notifications | JSON map | seeded | MVP |
| `cohort_size_warn_threshold` | Classroom mgmt | int | 35 | V1 |
| `analytics_retention_days` | Analytics | int | 1825 | V1 |
| `pta_weighting_strict` | VO grading | bool | true | MVP |
| `leerplicht_threshold_hours` | Absence | int | 16 | MVP |
| `opp_cycle_months` | OPP | int | 3 | MVP |
| `multi_tenant_mode` | Multi-tenancy | enum (single/per-board) | `single` | Enterprise |
| `gamification_enabled` | Gamification | bool | false | V1 |
| `ecommerce_enabled` | E-commerce | bool | false | Enterprise |
| `branding_logo_url` | White-label | string | empty | Enterprise |
| `branding_palette` | White-label | JSON | NL Design | Enterprise |

### 7.2 User Settings (OCP\IConfig, NcAppSettingsDialog)

| Setting | Feature source | Type | Default | Tier |
|---|---|---|---|---|
| `default_view` | List vs board | enum (list/cards/calendar) | `list` | MVP |
| `items_per_page` | List views | int | 25 | MVP |
| `default_sort` | List sort | string | `-updated_at` | MVP |
| `dashboard_cards` | Dashboard | JSON list | seeded per role | MVP |
| `my_work_grouping` | My Work | enum (status/due/priority) | `due` | V1 |
| `notify_assignments` | Notifications | bool | true | MVP |
| `notify_status_changes` | Notifications | bool | true | MVP |
| `notify_due_dates` | Notifications | bool | true | MVP |
| `notify_grades_published` | Notifications | bool | true | MVP |
| `notify_compliance_renewal` | Notifications | bool | true | V1 |
| `notify_opp_signature_request` | Notifications | bool | true | V1 |
| `notify_oso_dossier_received` | Notifications | bool | true | V1 |
| `notify_bron_afkeurmelding` | Notifications | bool | true | V1 |
| `notify_proctoring_alert` | Notifications | bool | true | V1 |
| `notify_credential_issued` | Notifications | bool | true | V1 |
| `notify_forum_mentions` | Notifications | bool | true | V1 |
| `language` | i18n | enum (nl/en) | account default | MVP |
| `accessibility_high_contrast` | A11y | bool | false | V1 |
| `accessibility_reduce_motion` | A11y | bool | false | V1 |

### 7.3 Notifications (OCP\Notification\IManager)

| Event | Subject key | Setting category | Recipient logic | Tier |
|---|---|---|---|---|
| Course enrolment confirmed | `course_enrolled` | `notify_assignments` | learner | MVP |
| Assignment due soon | `assignment_due_soon` | `notify_due_dates` | learner | MVP |
| Assignment overdue | `assignment_overdue` | `notify_due_dates` | learner + instructor | MVP |
| Grade published | `grade_published` | `notify_grades_published` | learner + parent | MVP |
| Soft-published grade ready for review | `grade_soft_published` | `notify_status_changes` | instructor cohort | V1 |
| Cohort enrolment completed | `cohort_enrolment_done` | `notify_assignments` | instructor | MVP |
| OPP evaluation due | `opp_evaluation_due` | `notify_opp_signature_request` | mentor + parent | V1 |
| OPP awaiting parent signature | `opp_signature_requested` | `notify_opp_signature_request` | parent | V1 |
| OSO dossier received | `oso_dossier_received` | `notify_oso_dossier_received` | receiving mentor | V1 |
| BRON afkeurmelding | `bron_rejection` | `notify_bron_afkeurmelding` | leerlingadmin | MVP |
| Leerplicht 16h threshold approaching | `leerplicht_threshold_warn` | `notify_status_changes` | mentor + leerplicht | MVP |
| Sick note submitted | `sick_note_submitted` | `notify_status_changes` | mentor | V1 |
| Proctored exam scheduled | `exam_scheduled` | `notify_assignments` | learner | MVP |
| Proctoring anomaly detected | `proctoring_anomaly` | `notify_proctoring_alert` | examiner | V1 |
| Certificate issued | `credential_issued` | `notify_credential_issued` | learner | V1 |
| Certificate expiry warning | `credential_expiring` | `notify_compliance_renewal` | learner + manager | V1 |
| Compliance refresher due | `compliance_due` | `notify_compliance_renewal` | learner + manager | MVP |
| Compliance evidence required | `compliance_evidence_required` | `notify_status_changes` | learner | V1 |
| Forum mention | `mentioned_in_forum` | `notify_forum_mentions` | mentioned user | V1 |
| Discussion reply | `discussion_reply` | `notify_forum_mentions` | thread author | V1 |
| Approval requested (course publish) | `approval_requested` | `notify_assignments` | approver | V1 |
| Approval granted | `approval_granted` | `notify_status_changes` | requester | V1 |
| Schedule conflict detected | `schedule_conflict` | `notify_status_changes` | scheduler | MVP |
| AI Act high-risk feature engaged | `ai_act_engagement` | `notify_status_changes` | DPO | Enterprise |

`NotificationService.php` follows the Pipelinq pattern with a `SUBJECT_SETTING_MAP` constant binding each subject key to its user-setting category.

## 8. Gap Analysis

### 8.1 What Competitors Do Well

- Moodle, Canvas and Open edX deliver mature course-management, gradebook and SCORM/xAPI runtimes at scale.
- Corporate LMS leaders (Docebo, Cornerstone, SAP SuccessFactors) deliver polished compliance reporting and HR-system integrations.
- ParnasSys, Magister and SOMtoday own deep, audited integrations with BRON/ROD, UWLR, OSO and Edukoppeling, the gatekeeper standards.
- Teachable, Kajabi and Udemy deliver slick course-creator UX, e-commerce and marketing automation.

### 8.2 What They Lack

| Gap | Opportunity for Scholiq |
|---|---|
| No Nextcloud-native LMS exists | First-mover on schools already running NC |
| OSS LMS leaders share dated UX | Modern Vue + NL Design surface |
| No open-source Dutch assessment platform | QTI 3.0 native fills the Cito/DiatOets/IEP gap |
| Dutch incumbents are closed SaaS | EUPL-1.2 + self-hosted satisfies sovereign-data DPOs |
| Per-seat corporate LMS pricing | Open-source self-host disrupts MKB segment |
| Fragmented identity (SAML, OAuth bolt-ons) | SURFconext + user-saml + DigiD native via NC |
| AI Act compliance burden | Built-in feature-flag gating + DPIA evidence log |
| EDCI/Europass credentials rarely first-class | First-class signing + verification |

### 8.3 Nextcloud-Native Advantages

| Capability | Why competitors cannot match it |
|---|---|
| `nc:files` for course content | Avoids a separate object-store + permissions model |
| `nc:talk` for virtual classroom | Avoids licensing Zoom/Meet/Webex per seat |
| `nc:calendar` for timetabling | Avoids a separate calendar product + sync layer |
| `nc:groups` for cohorts | Reuses school's existing org chart |
| `nc:user-saml` + SURFconext | School's existing SSO; no new IdP integration |
| `nc:notifications` | Unified across all NC apps the school already runs |
| Sovereign data (self-host) | Schools control AVG-compliant infrastructure |
| EUPL-1.2 + no per-seat pricing | Sustainable for boards with 50 to 50,000 learners |
| @conduction/nextcloud-vue + NL Design | Government-grade UX + WCAG 2.1 AA built-in |
| OpenRegister-backed audit trail | Immutable compliance evidence for free |
| OpenConnector adapters for BRON/UWLR/OSO | Reusable across docudesk, procest, mydash |

## 9. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| 12 to 18 month switching costs at school level | High | Target SIVON-aggregated procurement; co-fund 2-3 lighthouse boards |
| EU AI Act compliance burden (adaptive learning, proctoring high-risk) | High | ADR-005 feature-flag gating + audit trail; default-off for high-risk surfaces |
| Incumbent lock-in (Topicus/Visma ParnasSys + Magister duopoly) | High | OSO export support + parallel-run mode; never break the parent-facing UX during migration |
| UWLR/OSO/Edukoppeling certification as procurement gatekeeper | Critical | Treat as launch-blocker; certify via Edu-K + Kennisnet before SIVON pitch |
| BRON/ROD afkeurmeldingen breaking trust | High | Inline correction UI + replay; openconnector retry semantics |
| AVG/AP enforcement on pseudonymisation | Critical | SchoolID + ECK iD as primary identifier; internal UUID never crosses boundary |
| AI Act audit-trail retention costs | Medium | Default 730 days; configurable per regulation |
| Open-source sustainability perception in education procurement | Medium | EUPL-1.2 + ConductionNL paid support + SIVON cooperative endorsement |
| SCORM 1.2 legacy content breaking on cmi5-first runtime | Medium | ADR-002 shim; never block import of working SCORM packages |
| Proctoring-provider lock-in (Respondus / Inspera / ProctorU) | Medium | ADR-004 plug-in architecture; never bind one provider |
| EDCI signing key management | Medium | HSM-backed signing key path; rotation procedure documented |
| Multi-tenant boundary leaks (board A sees board B's learners) | Critical | ADR-007 OpenRegister tenant column with row-level enforcement; tested with multi-tenant fixtures |
| Mobile PWA accessibility regression | Low | NL Design + nc-vue baseline; CI-enforced axe-core checks |
| Per-seat corporate buyer expectation | Low | Bundle pricing; explicit "no per-seat" positioning |
| Cross-app feature drift (analytics in mydash + scholiq) | Medium | Shared canonical-feature ownership in `app_feature_decisions` |
