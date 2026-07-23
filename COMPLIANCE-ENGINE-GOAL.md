# Goal: Education/LMS compliance rule-engine for scholiq

> Kickoff brief for a **separate session**. Replicates the compliance-rule-engine
> built in **shillinq** (bookkeeping) for the **education / LMS** domain in scholiq.
> Reference implementation to copy: `apps-extra/shillinq/lib/Standards/`,
> `lib/Service/RuleAuditService.php`, `lib/Service/RuleTestDataSeeder.php`,
> `lib/Command/Rules*Command.php`, `lib/Lifecycle/RuleComplianceGuard.php`,
> `lib/Reporting/`. Result there: 442 machine-checkable rules enforced, 258/258
> test objects compliant, via 11 auto-discovered CheckProviders + 3 multi-agent
> workflow runs.

## What to build (the methodology — proven in shillinq)

1. **Static, versioned rule corpus** of international education/e-learning rules and
   guidelines. Compliance rules are universal facts → **versioned static code/JSON,
   NOT OpenRegister**. One JSON file per sub-domain under `lib/Standards/rules/*.json`,
   loaded/merged by a `RuleCatalogue.php` (a `VERSION` constant). Each rule:
   `{id, domain, jurisdiction, framework, source, statement, severity,
   machineCheckable, effectiveDate, sourceUrl}`. Go deep per framework; cite the
   clause/success-criterion in `source`.

2. **Corpus correctness pass.** `machineCheckable: true` ONLY if a deterministic
   program can decide compliance from structured fields (presence / format /
   arithmetic / enumeration / cardinality / date-window / referential). Pedagogical
   /judgemental guidelines ("appropriate", "learner-centred", narrative policy) →
   `false`. Fan out sub-agents to audit/correct flags honestly.

3. **`RuleEngine`** (`lib/Standards/RuleEngine.php`): auto-discovers per-domain
   **`CheckProvider`** classes in `lib/Standards/Checks/*.php` (interface
   `checks(): [objectType => [ruleId => fn($obj,$ctx):bool]]` + `seedSpec()`;
   optional `SeedsObjects::seedObjects()` for new object types). **Jurisdiction-gated**
   (rule fires for its country + EU-wide for EU members + global everywhere).
   Returns `Violation`s from the corpus. Copy the mechanism from shillinq.

4. **Lifecycle guards** (`RuleComplianceGuard`): block lifecycle transitions
   (e.g. course `publish`, certificate `issue`, exam `finalise`) on mandatory
   violations.

5. **Seeder + audit**: `RuleTestDataSeeder` + `occ scholiq:rules:seed-testdata`
   (idempotent compliant test data) and `RuleAuditService` +
   `occ scholiq:rules:audit` (enforced ÷ machine-checkable coverage, violations,
   per-object-type compliance). Drive test data to 100% compliant.

6. **Scope discipline.** Define scholiq's **in-scope** domains; target **100% of
   in-scope**, not the whole corpus. Route out-of-scope rules to the right app and
   skip honestly. Never fabricate vacuous `return true` checks or invent data to
   inflate the number.

7. **Scale with multi-agent workflows.** Once the engine + a few providers exist,
   fan out one author agent per domain (writes a `CheckProvider` + schema fragment +
   `seedObjects`), each followed by an **adversarial verify** agent that fails on
   vacuous always-true predicates, invalid/duplicate rule ids, lowercased-slug
   collisions, and un-seeded new object types. Integrate centrally (re-enable to
   import fragments, re-seed, audit, drop anything flagged, commit per domain).

8. **Reporting & Compliance UI** (optional, later): surface the audit + generate
   compliance/accreditation report files. Reuse the PHPOffice libs bundled in
   OpenRegister (PhpWord/PhpSpreadsheet + dompdf) — no new office dependency.

## Education/LMS domains & frameworks to catalogue (in-scope for scholiq)

- **Accessibility** (high yield, very machine-checkable): WCAG 2.1 / 2.2 level AA
  success criteria; EN 301 549; European Accessibility Act (EU) 2019/882; for content
  objects: alt-text presence, caption/transcript presence, contrast ratios, language
  attribute, heading structure, keyboard-operability metadata.
- **E-learning interoperability standards**: SCORM 1.2 / 2004 (manifest structure,
  required metadata, sequencing), xAPI / Tin Can (statement actor-verb-object
  validity, endpoint), cmi5, IMS LTI 1.3 (launch claims), QTI (assessment item
  structure), IMS Common Cartridge, Europass / ELM (European Learning Model),
  Open Badges / Verifiable Credentials.
- **Qualifications & credit frameworks**: EQF (8-level European Qualifications
  Framework), NLQF (NL), ECTS credit rules (credits per workload hours), micro-
  credential standard (EU Council Recommendation 2022), learning-outcome descriptors.
- **Data protection for learners** (often minors): GDPR (lawful basis, data
  minimisation, parental consent for under-16s, retention limits, DPIA), and
  jurisdiction equivalents.
- **Quality assurance & accreditation**: ESG (Standards & Guidelines for QA in the
  European Higher Education Area), NVAO accreditation criteria (NL/Flanders),
  Onderwijsinspectie toezichtkader, exam-regulation (OER) requirements, RPL/EVC
  (recognition of prior learning), diploma-supplement (Europass) requirements.
- **Assessment integrity & certification**: exam-result retention, certificate/
  diploma mandatory fields, proctoring data rules, grading-scale validity.

## Likely scholiq object types the checks map to
`Course`/`Module`, `LearningObject`/`Content`, `Enrolment`, `Assessment`/`ExamItem`,
`Grade`/`LearningRecord` (xAPI statement), `Certificate`/`Diploma`, `Competency`/
`LearningOutcome`, `Curriculum`/`Programme`, `Learner` (with age/consent fields).
Model only what the rules need; add fields via schema fragments + `seedSpec`.

## Carry-over gotchas (from shillinq)
- Adding a property to an OR schema needs the schema **`version` bumped** in the
  register.d fragment or OR won't re-import it (re-enable the app to import).
- OR resolves schemas by **lowercased slug** → avoid new object-type names that
  collide with an existing schema's slug.
- `seedObjects()` only fires when an object type is **empty**; backfill existing rows
  via `seedSpec()`.
- `phpcbf` needs `-u root` on bind-mounts; match the fleet's pervasive PHPCS style.
- Coverage `enforced ÷ machine-checkable`: literal 100% of the full corpus is
  unreachable. Target **100% of in-scope**, report honestly, don't fake. Accessibility
  + SCORM/xAPI/QTI structural rules are the densest machine-checkable seam — start there.

## Definition of done
- `occ scholiq:rules:audit` reports a meaningful coverage % of the in-scope education
  corpus and **0 violations** on seeded compliant test data across all modelled object
  types.
- Lifecycle guards block non-compliant transitions (publish course / issue certificate
  / finalise exam).
- Corpus + engine + providers + seeder + audit committed; coverage trajectory and
  scope decisions recorded in this repo's memory.
