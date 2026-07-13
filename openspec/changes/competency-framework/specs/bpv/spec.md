## MODIFIED Requirements

### Requirement: WerkprocesAssessment aligns to the kwalificatiedossier and emits a GradeEntry

`WerkprocesAssessment` MUST carry the kwalificatiedossier taxonomy (`kwalificatiedossierCode`,
`kerntaakCode`, `werkprocesCode`, `werkprocesLabel`) alongside an existing `curriculumPlanId`/`componentId`
pair (`kind: "assessment"`), and a confirmed assessment MUST emit (or update) a `GradeEntry` for that
component consumed by the `grading` spec; this schema MUST NOT compute the final grade itself. It MUST
additionally carry a nullable `competencyId` (`$ref: Competency`, from the `competency` capability's
taxonomy) resolved server-side — never accepted as client input, including from the praktijkopleider
portal action — from a `Competency` whose `code` matches `werkprocesCode` under a `CompetencyFramework`
with `sourceAuthority: sbb-kwalificatiedossier`; when resolution fails to find a matching `Competency`
(e.g. no such framework has been authored yet, or the code doesn't match any `Competency.code`),
`competencyId` MUST remain `null` and the existing `kwalificatiedossierCode`/`kerntaakCode`/
`werkprocesCode`/`werkprocesLabel` string fields — unchanged in shape and meaning — remain the sole record
of what was assessed, so grading and the praktijkopleider portal flow are never blocked by a missing or
stale taxonomy mapping. The `confirm` transition MUST fire both the existing
`WerkprocesGradeEmitHandler` and the `competency` capability's `CompetencyAttainmentRollupHandler` (two
independent listeners on the same `ObjectTransitionedEvent`, matching this app's existing multi-listener
idiom) — the former emitting the `GradeEntry` as before, the latter creating or updating the learner's
`CompetencyAttainment` row for `competencyId` when it is set, mapping `beoordeling` onto the framework's
`proficiencyLevels` the same way `WerkprocesGradeEmitHandler` already maps `beoordeling` onto
`GradeEntry.value` (`competent` → the highest declared level, `nog-niet-competent` → the lowest).

#### Scenario: A confirmed werkproces assessment feeds grading

<!-- @e2e exclude Pure backend/data-model + portal-manifest spec; no scholiq DOM surface — covered by the existing WerkprocesGradeEmitHandlerTest plus the new CompetencyAttainmentRollupHandlerTest referenced in tasks.md. -->

- **GIVEN** a `WerkprocesAssessment` reaches the `confirmed` lifecycle state
- **WHEN** it is confirmed
- **THEN** a `GradeEntry` is emitted or updated for its `curriculumPlanId`/`componentId`, consumed by the
  `grading` spec, and this schema computes no final grade itself

#### Scenario: A confirmed werkproces assessment with a resolved competency also updates CompetencyAttainment

<!-- @e2e exclude Backend event-driven roll-up logic; no scholiq DOM surface — covered by the competency capability's PHPUnit CompetencyAttainmentRollupHandlerTest referenced in tasks.md. -->

- **GIVEN** a `WerkprocesAssessment` whose `werkprocesCode` matches a `Competency.code` under an
  `sbb-kwalificatiedossier` `CompetencyFramework`, so `competencyId` was resolved and stored at creation
- **WHEN** the assessment transitions `submitted → confirmed`
- **THEN** both the existing `GradeEntry` emission and the `competency` capability's
  `CompetencyAttainmentRollupHandler` fire, and the learner's `CompetencyAttainment` row for that
  competency reflects the `beoordeling` mapped onto the framework's proficiency scale

#### Scenario: An assessment whose kwalificatiedossier code has no matching Competency still confirms normally

<!-- @e2e exclude Backend fallback/no-op behaviour; no scholiq DOM surface — covered by PHPUnit CompetencyAttainmentRollupHandlerTest::testUnresolvedCompetencyIsNoOp referenced in tasks.md. -->

- **GIVEN** a `WerkprocesAssessment` whose `werkprocesCode` does not match any `Competency.code` in the
  taxonomy (`competencyId` remains `null`)
- **WHEN** it is confirmed
- **THEN** the existing `GradeEntry` emission behaves exactly as before this change
- **AND** `CompetencyAttainmentRollupHandler` performs no write — no `CompetencyAttainment` row is created
  or updated, and confirmation is not blocked
