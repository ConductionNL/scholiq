# Scholiq — Design References & Wireframes

> Visual design references, UX patterns from competitor analysis, and ASCII wireframes for every primary view in Scholiq. All wireframes are ~70 chars wide and use realistic Dutch + English data (Sven Bakker, Esra Yıldız, Klas 5B, OPP-2026-014, etc.) so the team can pressure-test layout density.

Document version: 1.0 · 2026-05-11 · Companion to [`docs/ARCHITECTURE.md`](./ARCHITECTURE.md) and [`docs/FEATURES.md`](./FEATURES.md).

---

## 1. Design philosophy

Scholiq's competitive opening is UX. From the intelligence brief:

> **Insight (high)**: "Open-source LMS leaders (Moodle, ILIAS, Open edX) all share dated UX — modern Vue/NL Design surface is the differentiator."
> **Insight (high)**: "Dutch incumbents (Magister, SOMtoday, ParnasSys) face systemic UX and privacy backlash — opening switching window."

Three rules govern every screen:

1. **NL Design System primitives only.** Spacing, typography, colour, focus states, form elements all come from `@conduction/nextcloud-vue` + NL Design. No app-local fonts, no custom colour palettes, no untested icon sets.
2. **Cards over tables for primary navigation; tables for bulk operations.** Detail pages use `CnDetailPage` (card grid) + `CnObjectSidebar` (Files / Notes / Tags / Tasks / Audit Trail). List views use `CnDataTable` with `CnListViewLayout` for facet filtering, sticky actions, bulk operations.
3. **Realistic density, never lorem ipsum.** Every wireframe must read as if real data were loaded. This is how we catch density regressions early.

---

## 2. Design inspiration audit

Cross-referencing the 52 competitors from the brief against UX patterns worth borrowing or avoiding.

### Borrow

| Source | Pattern | Adopt in |
|---|---|---|
| **Open edX** | Clean learner dashboard — "course cards with progress ring + next-up action" | Student Dashboard |
| **Moodle (Boost)** | Three-column course layout (sidebar nav + content + completion timeline) | Course Detail Page |
| **Canvas LMS** | Module list with check-off pattern, "release after" gating visualization | Course/Lesson view |
| **Magister** | Familiar NL idioms (cijferoverzicht, lesoverzicht, schoolafspraak, mentor-attentie) | NL K-12 surfaces |
| **Studytube** | Course-card grid for corporate onboarding; "verplicht" badges | Corporate Learner Dashboard |
| **Docebo** | Manager dashboard — completion-rate heatmap by team | Manager Dashboard |
| **Inspera Assessment** | Item-bank tree + blueprint composer | Assessment Author View |
| **ProctorU** | Pre-flight checklist (camera / mic / id / room scan) | Proctored Exam Runner |
| **Credly** | Credential viewer with verifiable-signature affordance | Certificate View |
| **GoodHabitz** | "Pakketje van de week" badge-progression carousel | Microlearning surface |

### Avoid

| Source | Anti-pattern |
|---|---|
| **Moodle (default)** | Wall-of-blocks visual hierarchy, breadcrumbs that compete with primary nav |
| **ParnasSys** | Dense form-grid with no whitespace; modal stacking 3 deep |
| **SOMtoday** | Tab-within-tab navigation; primary actions hidden in overflow menus |
| **ILIAS** | "Tree of trees" navigation that requires expanding 4 levels to reach a quiz |
| **Blackboard** | Inconsistent icon system; mystery-meat tooltips |
| **Magister parent app** | Push notification spam with no per-cohort granularity |

---

## 3. Information architecture (top-level navigation)

Scholiq's MainMenu adapts to the user's role(s). Role discovery comes from `eduPersonAffiliation` (SURFconext), Nextcloud groups, or explicit role assignment on `LearnerProfile.roles`. Multi-role users see a role switcher in the top-right (e.g. "Compliance officer + Learner").

```
┌────────────────────────────────────────────────────────────────────┐
│ Scholiq                                              user-switcher │
├────────────────────────────────────────────────────────────────────┤
│ TEACHER VIEW                                                       │
│   Dashboard                                                        │
│   Mijn klassen        (Cohorts I instruct)                         │
│   Cursussen           (Courses I author/own)                       │
│   Toetsen             (Assessments I author)                       │
│   Leerlingen          (Learners across my cohorts)                 │
│   OPPs                (Passend onderwijs plans I oversee)          │
│   Cijfers             (Grade entry)                                │
│   Aanwezigheid        (Attendance / verzuim)                       │
│                                                                    │
│ STUDENT / LEARNER VIEW                                             │
│   Mijn dashboard                                                   │
│   Mijn cursussen                                                   │
│   Aankomende toetsen                                               │
│   Resultaten                                                       │
│   Certificaten                                                     │
│   Rooster                                                          │
│                                                                    │
│ PARENT VIEW (K-12)                                                 │
│   Overzicht (per kind)                                             │
│   Cijfers                                                          │
│   Aanwezigheid + ziekmelden                                        │
│   OPP                                                              │
│   Berichten van school                                             │
│                                                                    │
│ HR / COMPLIANCE VIEW                                               │
│   Dashboard                                                        │
│   Trainings                                                        │
│   Compliance overzicht                                             │
│   Certificeringen                                                  │
│   Audit pack                                                       │
│                                                                    │
│ ADMIN VIEW                                                         │
│   Instellingen                                                     │
│   Integraties (BRON, UWLR, OSO, Edukoppeling, SURFconext)          │
│   Gebruikers + rollen                                              │
│   Tenants + scholen                                                │
│   AI Act feature flags                                             │
│   Audit log                                                        │
└────────────────────────────────────────────────────────────────────┘
```

Every navigation item is a `NcAppNavigationItem` with `:to` prop bound to a Vue Router route. Detail routes use props functions to map `route.params.id` to component props (per skill guardrail).

---

## 4. Wireframes

All wireframes ≤ 70 chars wide. Use Dutch names + realistic IDs. Every detail view shows `CnDetailPage` (card grid) + `CnObjectSidebar` with the five standard tabs (Files, Notes, Tags, Tasks, Audit Trail).

### 4.1 Teacher Dashboard (PO / VO)

```
+──────────────────────────────────────────────────────────────────────+
| Scholiq  > Dashboard                              [ ] Sven (Mentor) |
+──────────────────────────────────────────────────────────────────────+
|                                                                      |
| Goedemorgen, Sven Bakker                                             |
| Mentor 5B  · OBS De Wilg ·  ma 11 mei 2026                           |
|                                                                      |
| KPI's                                                                |
| ┌─────────────┬─────────────┬──────────────┬───────────────────┐   |
| │ OPPs open    │ Late cijfers │ Verzuim flags │ BRON afkeur       │   |
| │   3          │   12         │   2 (16-uur)  │   1               │   |
| │ over 4 lln.  │ PTA-week 5   │ Esra · Tim    │ tegen behandeling │   |
| └─────────────┴─────────────┴──────────────┴───────────────────┘   |
|                                                                      |
| Mijn klassen                                                         |
| ─────────────────────────────────────────────────────────────────── |
| Klas    Vak       Volgende les    Open acties        Score gem.     |
| 5B      Biologie  do 13:30        2 OPP, 4 cijfers    7.1           |
| 5B      Mentor    di 08:30        1 ziekmelding ✎    -              |
| 4VWO    Biologie  wo 11:00        Geen                7.4           |
| 3HV-D   Loopbaan  vr 14:00        1 OPP evaluatie     -             |
|                                                                      |
| Vandaag                                                              |
| ─────────────────────────────────────────────────────────────────── |
| 08:30  Mentoruur 5B          Lokaal B2.07     [ Open ▸ ]            |
| 11:00  Biologie 4VWO         Lokaal C1.14     [ Open ▸ ]            |
| 13:30  Biologie 5B (toets)   Lokaal C1.14     [ Toezicht ▸ ]        |
|                                                                      |
| Mededelingen                                                         |
| ─────────────────────────────────────────────────────────────────── |
| · OPP-2026-014 (Tim de Vries) wacht op handtekening ouder          |
| · DUO ROD: 1 afkeurmelding bij inschrijving Esra Yıldız (rectifie..)|
| · NIS2 bestuurstraining loopt af op vr 22 mei                       |
+──────────────────────────────────────────────────────────────────────+
```

Density rules: ≥4 KPI cards, never more than 4 in a row; tables truncate to 4-6 rows with "show more"; mededelingen is the inbox for things the teacher can't ignore.

### 4.2 Student / Learner Dashboard (HO + corporate)

```
+──────────────────────────────────────────────────────────────────────+
| Scholiq  > Mijn dashboard                          [ ] Esra Yıldız  |
+──────────────────────────────────────────────────────────────────────+
|                                                                      |
| Hi Esra · Bachelor Informatiekunde · Radboud Universiteit            |
|                                                                      |
| Vandaag te doen                                                      |
| ┌──────────────────────────────────────────────────────────────────┐|
| │ ► Module 4: Database normalisatie                  78% klaar     │|
| │   nog 22 min   · cmi5 · Course: INFOMDB1-2026                    │|
| │   [ Doorgaan ▸ ]                                                 │|
| └──────────────────────────────────────────────────────────────────┘|
|                                                                      |
| Aankomende toetsen                                                   |
| ─────────────────────────────────────────────────────────────────── |
| Datum    Vak                  Modus            Tijd      Actie      |
| wo 13 mei INFOMDB1 toets-2    Proctored (HR)   90 min    [ Boek ▸ ] |
| vr 15 mei AVG-refresher 2026  Self-paced       30 min    [ Start ▸ ]|
| ma 18 mei INFOMVR Project     Inlevering       n.v.t.    [ Open ▸ ] |
|                                                                      |
| Mijn cursussen                                       Voortgangsring  |
| ─────────────────────────────────────────────────────────────────── |
| INFOMDB1 Databases                                    ◉ 78%          |
| INFOMVR Virtual Reality                               ◉ 31%          |
| INFOMVK Vakdidactiek                                  ◉ 100%  ✓     |
| AVG-Onderwijs verplichte refresher 2026               ◉ 0%   !       |
|                                                                      |
| Certificaten verlopen binnenkort                                     |
| ─────────────────────────────────────────────────────────────────── |
| EHBO bij kinderen          verloopt 18-jun-2026   [ Verleng ▸ ]     |
+──────────────────────────────────────────────────────────────────────+
```

Reference: Open edX learner dashboard's "course cards with progress ring + next-up action" pattern.

### 4.3 Compliance Officer Dashboard

```
+──────────────────────────────────────────────────────────────────────+
| Scholiq  > Compliance overzicht          [ ] Marieke (Compliance)   |
+──────────────────────────────────────────────────────────────────────+
|                                                                      |
| Dekking per regelgeving                                              |
| ┌────────────────────┬───────┬──────────┬───────────┬────────────┐ |
| │ Regelgeving        │ Doel  │ Gehaald  │ Achter    │ Audit-pack │ |
| ├────────────────────┼───────┼──────────┼───────────┼────────────┤ |
| │ NIS2 bestuur       │ 100%  │   91%    │ 4 bestuur│ [ Export ▸ ] │ |
| │ BIO2 medewerker    │  95%  │   88%    │ 41 mw.   │ [ Export ▸ ] │ |
| │ AVG basis 2026     │ 100%  │   97%    │ 11 mw.   │ [ Export ▸ ] │ |
| │ Cyberbeveiligingsw │ 100%  │   76%    │ 78 mw.   │ [ Export ▸ ] │ |
| │ Rijksbasis modules │  90%  │   84%    │ 53 mw.   │ [ Export ▸ ] │ |
| └────────────────────┴───────┴──────────┴───────────┴────────────┘ |
|                                                                      |
| Aankomende deadlines                                                 |
| ─────────────────────────────────────────────────────────────────── |
| do 22 mei  NIS2 bestuurstraining               4 leden te gaan      |
| ma 02 jun  Cyberbeveiligingswet refresher      78 medewerkers       |
| vr 13 jun  EDCI hercertificering DPO           1 medewerker         |
|                                                                      |
| Snelste acties                                                       |
| [ Bulk-enroll AVG 2026 (11) ▸ ]   [ Reminder NIS2 bestuur (4) ▸ ]  |
| [ Audit-pack Q1 genereren ▸ ]    [ Attestaties van mei tellen ▸ ]  |
|                                                                      |
| Onveranderlijk evidence-log (laatste 5)                              |
| ─────────────────────────────────────────────────────────────────── |
| 10:42  Mwah Souza  AVG basis 2026          attestatie getekend     |
| 10:31  Bram Smit   AVG basis 2026          attestatie getekend     |
| 09:58  J. Versluis NIS2 bestuur 2026       module 3 voltooid       |
| 09:14  Iqra Ahmed  BIO2 medewerker 2026    attestatie getekend     |
| 08:52  R. v.d.Berg Cyberbeveiligingswet    voltooid + attest.       |
+──────────────────────────────────────────────────────────────────────+
```

Reference: Docebo's completion-rate heatmap, but flattened into bar visualisation per regelgeving.

### 4.4 Course Detail Page (CnDetailPage + CnObjectSidebar)

```
+──────────────────────────────────────────────────────────────────────+
| Scholiq > Cursussen > BIO-3H-2026             Bewerken | Publiceren |
+───────────────────────────────────────────────────┬──────────────────+
|                                                   │ ▤ Files     [9] |
|  ╭─────────────────────────────────────────────╮ │ ✎ Notes     [2] |
|  │  Biologie 3 havo (2026-2027)                │ │ # Tags     [12] |
|  │  Code: BIO-3H-2026                          │ │ ✓ Tasks     [5] |
|  │  Niveau: HAVO klas 3 · NL · 4 EC            │ │ ⟳ Audit    [38] |
|  │  Provider: OBS De Wilg                      │ │                 |
|  │  Status: gepubliceerd                       │ │ Snelacties      |
|  ╰─────────────────────────────────────────────╯ │ ─────────────── |
|                                                   │ Roostercheck    |
|  ╭─── Modules ─────────────────────────────────╮ │ Kloon cursus    |
|  │  1. Cel & weefsels       12 lessen  cmi5    │ │ Exporteer QTI   |
|  │  2. Erfelijkheid          8 lessen  cmi5    │ │ Hercertificeer  |
|  │  3. Ecologie             10 lessen  cmi5    │ │                 |
|  │  4. Mensbiologie         14 lessen  cmi5    │ │ Linked          |
|  │  + Module toevoegen                         │ │ ─────────────── |
|  ╰─────────────────────────────────────────────╯ │ Cohorten        |
|                                                   │  · Klas 3A      |
|  ╭─── Cohorten ────────────────────────────────╮ │  · Klas 3B      |
|  │  3A   28 lln.   Mentor: Sven Bakker  ◉ actief│ │  · Klas 3C-MAVO |
|  │  3B   26 lln.   Mentor: F. Hoebink   ◉ actief│ │                 |
|  │  3C-MAVO 22 lln. Mentor: T. Pena    ◉ actief│ │                 |
|  ╰─────────────────────────────────────────────╯ │                 |
|                                                   │                 |
|  ╭─── Toetsen ─────────────────────────────────╮ │                 |
|  │  T1  Cel & weefsels        wk 38   QTI 3.0  │ │                 |
|  │  T2  Erfelijkheid          wk 44   QTI 3.0  │ │                 |
|  │  T3  Ecologie              wk 04   QTI 3.0  │ │                 |
|  │  EX  Schoolexamen          wk 19   PTA      │ │                 |
|  ╰─────────────────────────────────────────────╯ │                 |
+──────────────────────────────────────────────────┴──────────────────+
```

The main area is a `CnDetailPage` with three `CnDetailCard` widgets (Modules, Cohorten, Toetsen). The sidebar is `CnObjectSidebar` with the five mandatory tabs (Files / Notes / Tags / Tasks / Audit Trail) plus role-aware Snelacties + Linked panels.

### 4.5 Assessment Author View

```
+──────────────────────────────────────────────────────────────────────+
| Scholiq > Toetsen > T2 Erfelijkheid (BIO-3H wk 44)   Bewerken modus |
+──────────────────────────────────────────────────────────────────────+
| Itembank                  ┃ Blueprint                  ┃ Itempreview |
| ────────────────────────  ┃ ───────────────────────────  ┃ ────────── |
| ▾ Hoofdstuk 1 Cel         ┃ Competentie × Niveau × Score ┃ Vraag #042  |
|   ▾ § 1.1 Bouw            ┃   ┌──────┬──────┬──────┬──┐ ┃ ─────────── |
|     · #042 Multiplechoice ┃   │      │ K1   │ K2   │K3│ ┃ MC          |
|     · #043 Drag-and-drop  ┃   ├──────┼──────┼──────┼──┤ ┃             |
|     · #044 Textentry      ┃   │ B1   │ 2/4  │ 1/3  │  │ ┃ Welk celonderdeel|
|   ▾ § 1.2 Functie         ┃   │ B2   │ 1/2  │ 2/3  │  │ ┃ produceert ATP? |
|     · #050 Match          ┃   │ B3   │ 0/0  │ 1/1  │1/1│ ┃             |
| ▾ Hoofdstuk 2 Erfelijkheid┃   └──────┴──────┴──────┴──┘ ┃ A) Ribosoom  |
|   ▾ § 2.1 Mendel          ┃                              ┃ B) Mitochondrium |
|     · #088 Multiplechoice ┃ Totaal items: 14             ┃ C) Cytosol  |
|     · #089 Order          ┃ Totaal punten: 32            ┃ D) Membraan |
|     · #090 ExtendedText   ┃ Tijdslimiet: 60 min          ┃             |
|   ▾ § 2.2 Stamboom        ┃ Cesuur: 5.5 → 7.0 → 8.5      ┃ Antwoord: B |
|     · #093 Match          ┃                              ┃ Score: 2 pt |
|     · #094 Match          ┃ [ Genereer adaptief ▸ ]      ┃ Bloom: K2   |
|                           ┃ [ Sla op als blueprint ▸ ]   ┃ Tags: cel, ATP|
| [ + Item via QTI 3.0 ▸ ] ┃ [ Exporteer QTI 3.0 ▸ ]      ┃             |
+──────────────────────────────────────────────────────────────────────+
```

Reference: Inspera's item-bank tree + blueprint composer. Three columns: bank tree → blueprint matrix → live item preview. AI item-suggestion ("Genereer adaptief") sits behind the EU AI Act feature flag (insight #1).

### 4.6 Proctored Exam Runner (student-facing)

```
+──────────────────────────────────────────────────────────────────────+
| Toets T2 Erfelijkheid                ◉ rec.  ⏱ 47:23 / 60:00         |
+──────────────────────────────────────────────────────────────────────+
|                                                                      |
|  Vraag 5 van 14            ┌─────────────────────────────────────┐  |
|                            │ Camera                              │  |
|  Welk allel is dominant    │  ┌──────────┐                       │  |
|  in onderstaande kruising? │  │  jij  ▣  │                       │  |
|                            │  └──────────┘                       │  |
|  Aa  ×  aa  →  ?           │                                     │  |
|                            │  Status:                             │  |
|  ( ) Aa en aa              │  ✓ Camera actief                     │  |
|  ( ) AA en Aa              │  ✓ Microfoon actief                  │  |
|  ( ) Aa en Aa              │  ✓ Identiteit bevestigd (DigiD)      │  |
|  ( ) AA en aa              │  ✓ Ruimte gescand                    │  |
|                            │  ✓ Geen ander gezicht (laatste 90s)  │  |
|  [ ✓ Markeer voor          │  ⓘ Provider: ProctorU                │  |
|     hercontrole ]          └─────────────────────────────────────┘  |
|                                                                      |
|  ╔══════════════════════════════════════════════════════════════╗   |
|  ║  Eerlijkheidsverklaring zichtbaar tijdens hele toets         ║   |
|  ║  AI-gebaseerde detectie (high-risk per EU AI Act):           ║   |
|  ║  · gezichtsherkenning ON · spraakdetectie ON                 ║   |
|  ║  · resultaat altijd door mens gecontroleerd voor sanctie     ║   |
|  ╚══════════════════════════════════════════════════════════════╝   |
|                                                                      |
| [ ◂ Vorige ]            Voortgang ▮▮▮▮▮▮▱▱▱▱▱▱▱▱           [ Volgende ▸ ] |
+──────────────────────────────────────────────────────────────────────+
```

Reference: ProctorU pre-flight checklist baked into a persistent right rail. EU AI Act compliance banner is mandatory and non-dismissible (constraint §6.1 of ARCHITECTURE.md).

### 4.7 Cohort / Class Roster

```
+──────────────────────────────────────────────────────────────────────+
| Scholiq > Klassen > 5B (2026-2027)            Bekijk als | Exporteer |
+──────────────────────────────────────────────────────────────────────+
| 28 leerlingen · Mentor Sven Bakker · OBS De Wilg                     |
|                                                                      |
| Filter: [ Alles ▾ ]  [ Vak ▾ ]  [ Status ▾ ]   [+ Leerling toevoegen]|
|                                                                      |
| Naam              Klas BSN✕ SchoolID  OPP  Verzuim Cijferg. BRON     |
| ───────────────── ──── ──── ────────  ───  ─────── ────────  ────    |
| Bakker, Lize      5B   ●    SID-…814  -    -        7.2      ✓ OK    |
| Boon, Adam        5B   ●    SID-…815  -    -        6.8      ✓ OK    |
| de Vries, Tim     5B   ●    SID-…823  ⓘ   ⚠ 16u    6.1      ⚠ corr. |
| Hartog, Mees      5B   ●    SID-…824  -    -        7.5      ✓ OK    |
| Ibrahim, Yasmin   5B   ●    SID-…825  -    -        7.0      ✓ OK    |
| Jansen, Sophie    5B   ●    SID-…826  -    -        7.4      ✓ OK    |
| Karadeniz, Ali    5B   ●    SID-…827  ⓘ   -        6.4      ✓ OK    |
| Mulder, Pim       5B   ●    SID-…828  -    -        7.3      ✓ OK    |
| Pena, Iza         5B   ●    SID-…829  -    -        8.1      ✓ OK    |
| Smit, Daan        5B   ●    SID-…830  -    -        6.9      ✓ OK    |
| Vermeer, Nora     5B   ●    SID-…831  -    -        7.7      ✓ OK    |
| Yıldız, Esra      5B   ●    SID-…832  -    ✎ open   7.1      ✓ OK    |
| Zeggelaar, Tess   5B   ●    SID-…833  -    -        6.5      ✓ OK    |
| … (15 meer)                                                          |
|                                                                      |
| Legend:  ● = BSN encrypted (nooit getoond)  ⓘ = OPP actief           |
|          ⚠ 16u = leerplicht-threshold overschreden (verzuim 4 weken) |
|          ⚠ corr. = afkeurmelding BRON, inline rectificatie nodig    |
+──────────────────────────────────────────────────────────────────────+
```

BSN column shows ● only — BSN is encrypted at rest and never displayed (constraint §6.2 of ARCHITECTURE.md). SchoolID is the operational pseudonym.

### 4.8 OPP cycle (Ontwikkelingsperspectief)

```
+──────────────────────────────────────────────────────────────────────+
| Scholiq > OPPs > OPP-2026-014 (Tim de Vries · 5B)            Wijzig |
+──────────────────────────────────────────────────────────────────────+
| Status: in evaluatie  ·  Volgende evaluatie: do 22 mei 2026          |
| Mentor: Sven Bakker  ·  Zorgcoördinator: M. el Idrissi               |
|                                                                      |
| ╭── Startpositie ────────────────────────────────────────────────╮  |
| │ Tim is een 14-jarige leerling met TOS-aanduiding (taal/spraak)  │  |
| │ Cito laatste meting: rekenen V, begrijpend lezen IV, spelling V  │  |
| │ Voorgaande inzet: pre-teaching wiskunde 2×/wk vanaf okt 2025     │  |
| ╰────────────────────────────────────────────────────────────────╯  |
|                                                                      |
| ╭── Ambitie + ondersteuning ────────────────────────────────────╮   |
| │ Eindniveau VMBO-T havo-mogelijkheid open                       │   |
| │ Pre-teaching wiskunde 2×/wk · pre-teaching biologie 1×/wk      │   |
| │ Verlengde tijd toetsen (+33%) · auditieve ondersteuning lezen  │   |
| │ Wekelijks SMART-doel evaluatie met mentor                      │   |
| ╰────────────────────────────────────────────────────────────────╯  |
|                                                                      |
| ╭── Evaluaties ───────────────────────────────────────────────────╮ |
| │ Q1 (sep '25)  voltooid    cijferontwikkeling stabiel            │ |
| │ Q2 (dec '25)  voltooid    rekenen V→IV  ↑                      │ |
| │ Q3 (mrt '26)  voltooid    begrijpend lezen IV→IV =              │ |
| │ Q4 (mei '26)  in voorbereiding  do 22 mei evaluatiemoment       │ |
| ╰────────────────────────────────────────────────────────────────╯  |
|                                                                      |
| ╭── Ondertekening ──────────────────────────────────────────────╮   |
| │ Mentor:           Sven Bakker        getekend  09-okt-2025      │  |
| │ Zorgcoordinator:  M. el Idrissi      getekend  09-okt-2025      │  |
| │ Ouder/verzorger:  N. de Vries        WACHT     [ Herinnering ▸ ]│  |
| │                                       (DigiD-ondertekening)     │  |
| ╰────────────────────────────────────────────────────────────────╯  |
|                                                                      |
| [ Plan evaluatieafspraak ]   [ Stuur OSO-transferdossier ▸ ]        |
+──────────────────────────────────────────────────────────────────────+
```

Reference: Handreiking Ontwikkelingsperspectief (Steunpunt Passend Onderwijs PO-VO). Parent signing via DigiD (mandatory NL flow). Quarterly evaluation reminder fires from a `TimedJob`.

### 4.9 Certificate / Credential view (EDCI + Open Badges 3.0)

```
+──────────────────────────────────────────────────────────────────────+
| Scholiq > Mijn certificaten > AVG-Onderwijs 2026                    |
+──────────────────────────────────────────────────────────────────────+
|                                                                      |
|     ╭───────────────────────────────────────────────────────╮       |
|     │                  ▄▄▄▄▄                                │       |
|     │                ▄█████████▄                            │       |
|     │              ▄█████████████▄                          │       |
|     │              ███           ███                        │       |
|     │              ███   Scholiq ███                        │       |
|     │              ███           ███                        │       |
|     │              ▀█████████████▀                          │       |
|     │                ▀█████████▀                            │       |
|     │                  ▀▀▀▀▀                                │       |
|     │                                                       │       |
|     │   AVG-Onderwijs verplichte refresher 2026             │       |
|     │   uitgegeven aan                                      │       |
|     │                                                       │       |
|     │     ESRA YILDIZ                                       │       |
|     │     Radboud Universiteit Nijmegen                     │       |
|     │                                                       │       |
|     │   Uitgegeven  03-mei-2026                             │       |
|     │   Verloopt    03-mei-2027                             │       |
|     │   Issuer DID  did:web:scholiq.ru.nl                  │       |
|     │   Format      EDCI ELM v1 · Open Badges 3.0 (W3C VC)  │       |
|     ╰───────────────────────────────────────────────────────╯       |
|                                                                      |
| Verificatie                                                          |
| ─────────────────────────────────────────────────────────────────── |
| ✓ Cryptografische handtekening geldig                                |
| ✓ Issuer DID gevalideerd via did:web                                 |
| ✓ Niet ingetrokken                                                   |
| ✓ Binnen geldigheidstermijn                                          |
|                                                                      |
| [ Download als PDF ]  [ Toon QR-verificatie ]  [ Toevoegen aan      |
|                                                  Europass wallet ]   |
+──────────────────────────────────────────────────────────────────────+
```

Reference: Credly's credential viewer with verifiable-signature affordance. Verification page is publicly reachable at `/scholiq/verify/<credential-id>` for third parties.

### 4.10 Admin Settings (integrations + AI Act flags)

```
+──────────────────────────────────────────────────────────────────────+
| Scholiq > Instellingen                              Tenant: OBS-WLG │
+──────────────────────────────────────────────────────────────────────+
| ▾ CnVersionInfoCard                                                  |
| ╭──────────────────────────────────────────────────────────────────╮|
| │ Scholiq v0.1.0     OpenRegister v0.x ✓   OpenConnector v0.x ✓    │|
| │ Update beschikbaar: nee · Build: 2026-05-11                       │|
| ╰──────────────────────────────────────────────────────────────────╯|
|                                                                      |
| Integraties (CnSettingsSection per adapter)                          |
| ─────────────────────────────────────────────────────────────────── |
|                                                                      |
| ▾ DUO BRON / ROD                                       ✓ connected  |
|   Endpoint: https://bron.duo.nl/api/...                              |
|   Laatste sync: ma 11 mei 06:00       Wachtwoord: ********           |
|   Afkeurmeldingen wachtrij: 1                       [ Open queue ▸ ] |
|                                                                      |
| ▾ UWLR (publisher exchange)                            ✓ connected  |
|   Profile: Edukoppeling WUS-RM · ECK iD enabled                      |
|   Partners: Noordhoff, ThiemeMeulenhoff, Malmberg, Zwijsen           |
|   Laatste exchange: ma 11 mei 04:30                                  |
|                                                                      |
| ▾ OSO (Overstapservice Onderwijs)                      ✓ connected  |
|   Account: schoolcode 23AB · sleutelpaar OK                          |
|   Open transfers: 0 in, 3 out (PO→VO)                                |
|                                                                      |
| ▾ Edukoppeling (transport)                             ✓ connected  |
|   Onderliggend voor: BRON/ROD, UWLR, OSO                             |
|                                                                      |
| ▾ SURFconext (identity)                                — niet ingest.|
|   Voor HE-tenants. PO/VO blijft NC user-saml of lokaal.              |
|                                                                      |
| ▾ Proctoring providers                                               |
|   ▾ ProctorU      ◉ enabled    [ Test connectie ]                    |
|   ▾ Honorlock     ○ disabled                                         |
|   ▾ ExamSoft      ○ disabled                                         |
|   ▾ In-house      ◉ enabled    (camera + flag-for-review)            |
|                                                                      |
| AI Act feature flags                                                 |
| ─────────────────────────────────────────────────────────────────── |
| ▾ Adaptief leren           ○ uit   (high-risk Annex III §3)          |
| ▾ AI-itemgeneratie         ○ uit   (high-risk Annex III §3)          |
| ▾ Spraakdetectie proctoring ◉ aan  (high-risk · audit-trail actief)  |
| ▾ AI-essayscoring           ○ uit   (high-risk · niet beschikbaar v1)|
|                                                                      |
| Iedere AI-feature schrijft een verplichte audit-regel per beslissing.|
+──────────────────────────────────────────────────────────────────────+
```

Every section is a `CnSettingsSection` with footer slot. First section is the mandatory `CnVersionInfoCard`. AI Act flags are off by default and require explicit admin opt-in plus a one-time CE/DoC acknowledgement (constraint §6.1).

### 4.11 NcAppSettingsDialog (user settings)

```
+──────────────────────────────────────────────────────────────────────+
|  Scholiq · Mijn instellingen                                  [×]   |
+──────────┬───────────────────────────────────────────────────────────+
| Algemeen |  Taal                                                    |
| Meldingen|  ◉ Nederlands    ○ English                                 |
| Privacy  |                                                          |
| Rollen   |  Tijdzone                                                |
|          |  Europe/Amsterdam (auto)                                  |
|          |                                                          |
|          |  Startscherm                                             |
|          |  ◉ Dashboard    ○ Mijn cursussen    ○ Vandaag             |
|          |                                                          |
|          |  Toegankelijkheid                                        |
|          |  ☑ Verhoogd contrast                                     |
|          |  ☑ Reduceer animatie                                     |
|          |  ☐ Grotere lettergrootte                                 |
+──────────┴───────────────────────────────────────────────────────────+
```

Tab "Meldingen" expanded:

```
| Algemeen |  Meldingen (toggle per gebeurtenis)                      |
| Meldingen|  ─────────────────────────────────────────────────────── |
| Privacy  |  Cijfer gepubliceerd          ☑ in-app  ☑ e-mail  ☐ push |
| Rollen   |  Toets ingepland              ☑ in-app  ☑ e-mail  ☑ push |
|          |  OPP-evaluatie aanstaande     ☑ in-app  ☑ e-mail  ☐ push |
|          |  Certificaat verloopt         ☑ in-app  ☑ e-mail  ☐ push |
|          |  Compliance-training opdracht ☑ in-app  ☑ e-mail  ☐ push |
|          |  OSO-dossier ontvangen        ☑ in-app  ☑ e-mail  ☐ push |
|          |  BRON afkeurmelding           ☑ in-app  ☑ e-mail  ☐ push |
|          |  Ziekmelding ouder            ☑ in-app  ☐ e-mail  ☐ push |
```

Reference: skill guardrail "User settings use `NcAppSettingsDialog` (NOT `NcDialog`). Backend uses `OCP\IConfig` for per-user storage."

---

## 5. Empty / error / loading states

Per skill guardrail "OpenRegister dependency check is mandatory":

```
+──────────────────────────────────────────────────────────────────────+
|                                                                      |
|                                                                      |
|                         ┌──────────────┐                             |
|                         │              │                             |
|                         │   ╱╲ ╱╲ ╱╲   │                             |
|                         │   ╲╱╲╱╲╱     │                             |
|                         │              │                             |
|                         └──────────────┘                             |
|                                                                      |
|              OpenRegister is niet geïnstalleerd                      |
|                                                                      |
|  Scholiq slaat alle gegevens op in OpenRegister. Vraag een          |
|  beheerder om OpenRegister te installeren in deze Nextcloud.         |
|                                                                      |
|              [ Installeer OpenRegister ▸ ]                           |
|                  (alleen zichtbaar voor admins)                      |
|                                                                      |
+──────────────────────────────────────────────────────────────────────+
```

This view is centered, no sidebar, no MainMenu — full-page empty state via `NcEmptyContent`. Admin CTA links to the NC app store.

---

## 6. Mobile / responsive notes

Scholiq is built on `@conduction/nextcloud-vue` which inherits Nextcloud's mobile responsive grid. Specific patterns:

- **Mobile Teacher Dashboard**: KPI cards stack vertically; "Vandaag" jumps to top; "Mededelingen" collapses to a count badge.
- **Mobile Student Dashboard**: "Vandaag te doen" full-bleed; course cards swipe horizontally.
- **Proctored Exam Runner**: tablet-only (mobile blocked by `Mobile.NotSuitableForExam` warning).
- **Parent app (DigiD-authenticated)**: simplified surface — ziekmelden, cijfers, OPP-ondertekening only. No corporate / compliance surfaces.

---

## 7. Iconography

All icons from Material Symbols (rounded variant) — same source as the blue hexagon logo. Domain mapping:

| Domain | Icon (Material Symbols name) |
|---|---|
| Course | `school` |
| Lesson / Module | `library_books` |
| Assessment | `quiz` |
| Proctored exam | `monitor_heart` |
| Certificate | `verified` |
| Compliance | `policy` |
| Cohort / Class | `groups` |
| Attendance | `event_available` |
| OPP | `accessibility` |
| Settings | `tune` |
| Integrations | `cable` |
| AI Act flag | `psychology` (with high-risk pip) |

---

## 8. Open design questions for `/opsx-new`

1. **Parent app shape** — separate Nextcloud "Scholiq Ouders" companion app or simply a role-aware surface inside Scholiq? (Recommendation: role-aware surface; avoids fragmenting NC user base.)
2. **Federated catalog** — SIVON-wide course catalog: federated via OpenCatalogi or duplicated per tenant? (Recommendation: OpenCatalogi federation when SIVON channel matures.)
3. **DigiD parent flow** — `nc:user-oidc` direct or via Studielink-like broker for K-12? (Recommendation: nc:user-oidc direct; Studielink is HE-only.)
4. **Talk room lifecycle** — auto-created per cohort or per-lesson on demand? (Recommendation: per cohort, persistent.)
5. **Audit log retention** — AVG 5y default, but AI Act audit logs may need 10y for high-risk feature traceability. Resolve at ADR-005 time.

---

## 9. References

- ARCHITECTURE.md: [`docs/ARCHITECTURE.md`](./ARCHITECTURE.md)
- FEATURES.md: [`docs/FEATURES.md`](./FEATURES.md)
- Intelligence brief: [`https://github.com/ConductionNL/market-intelligence/blob/development/briefs/scholiq-context.md`](https://github.com/ConductionNL/market-intelligence/blob/development/briefs/scholiq-context.md)
- Pipelinq design references (format inspiration): https://github.com/ConductionNL/pipelinq/blob/main/docs/DESIGN-REFERENCES.md
- Procest design references: https://github.com/ConductionNL/procest/blob/main/docs/DESIGN-REFERENCES.md
- nextcloud-vue component library: https://github.com/ConductionNL/nextcloud-vue
- NL Design System: https://nldesignsystem.nl/
