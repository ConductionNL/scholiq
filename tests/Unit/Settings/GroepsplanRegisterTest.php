<?php

/**
 * Unit tests for the `groepsplan` register-JSON declarations.
 *
 * IMPORTANT SCOPE NOTE: `x-openregister-calculations` are evaluated by
 * OpenRegister core at runtime, which does not live in this repository (only
 * test stubs for its PHP service classes do — see composer.json's
 * `autoload-dev`). Scholiq cannot unit-test the numeric/boolean OUTPUT of a
 * declared calculation (no existing test in this suite does — see e.g.
 * ZorgvraagSwvTlvChainRegisterTest::testTlvExpiringSoonCalculationShape).
 * What Scholiq CAN and MUST verify is that the declared SHAPE is correct:
 * the right fields exist, the expression references the right props, and
 * the lifecycle/notification declarations are wired the way design.md
 * specifies. This mirrors the established pattern in
 * ZorgvraagSwvTlvChainRegisterTest and PupilDossierNotesRegisterTest.
 *
 * Covers GroupPlan/GroupPlanSubgroup/GroupPlanEvaluation shape, the
 * "no denormalised LearningPlan/SupportRequest link on GroupPlanSubgroup"
 * negative assertion, the supersedesId version-chain reuse, and the
 * SupportRequest.originGroupPlanSubgroupId additive-nullable field.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/groepsplan/tasks.md#1-schema-learning-plan-delta
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the GroupPlan/GroupPlanSubgroup/GroupPlanEvaluation declarations
 * plus the SupportRequest.originGroupPlanSubgroupId extension.
 */
class GroepsplanRegisterTest extends TestCase
{

    /**
     * Decoded register configuration.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Load the register configuration once per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $path         = __DIR__.'/../../../lib/Settings/scholiq_register.json';
        $this->config = json_decode((string) file_get_contents($path), true);

    }//end setUp()

    /**
     * GroupPlan declares the full draft→…→closed|superseded lifecycle,
     * identical in shape to LearningPlan's — but with NO `requires` guard on
     * `activate` (design.md "Rejected Alternatives": no co-sign requirement,
     * unlike LearningPlan.activate's LearningPlanSignatureGuard).
     *
     * @return void
     */
    public function testGroupPlanLifecycleShapeHasNoSignatureGuard(): void
    {
        $schema = $this->config['components']['schemas']['GroupPlan'];

        self::assertSame('group-plan', $schema['slug']);

        $lifecycle = $schema['x-openregister-lifecycle'];
        self::assertSame('lifecycle', $lifecycle['field']);
        self::assertSame('draft', $lifecycle['initial']);

        $transitions = $lifecycle['transitions'];
        self::assertSame('active', $transitions['activate']['to']);
        self::assertArrayNotHasKey('requires', $transitions['activate']);
        self::assertSame('under-evaluation', $transitions['startEvaluation']['to']);
        self::assertSame('active', $transitions['completeEvaluation']['to']);
        self::assertSame('closed', $transitions['close']['to']);
        self::assertSame('superseded', $transitions['supersede']['to']);

        self::assertSame(
            ['cohortId', 'subject', 'coordinatorId', 'tenant_id'],
            $schema['required']
        );

    }//end testGroupPlanLifecycleShapeHasNoSignatureGuard()

    /**
     * GroupPlan.periodEndDue is a declared boolean calculation structurally
     * mirroring LearningPlan.nextReviewDue's and([ne(prop,null),
     * lte(prop,now)]) shape, substituting periodEndDate for nextReviewAt.
     *
     * @return void
     */
    public function testPeriodEndDueCalculationShape(): void
    {
        $schema = $this->config['components']['schemas']['GroupPlan'];
        $calc   = $schema['x-openregister-calculations']['periodEndDue'];

        self::assertSame('boolean', $calc['type']);

        $terms = $calc['expression']['and'];
        self::assertSame('periodEndDate', $terms[0]['ne'][0]['prop']);
        self::assertNull($terms[0]['ne'][1]);
        self::assertSame('periodEndDate', $terms[1]['lte'][0]['prop']);
        self::assertArrayHasKey('now', $terms[1]['lte'][1]);

    }//end testPeriodEndDueCalculationShape()

    /**
     * GroupPlan.periodEndReminder notification is a calculatedChange
     * trigger flipping false → true on periodEndDue, idempotent via the
     * same previously/condition mechanism as TlvApplication.tlvExpiringSoon,
     * addressed to coordinatorId — NOT a PHP TimedJob (ADR-022).
     *
     * @return void
     */
    public function testPeriodEndReminderNotificationShape(): void
    {
        $schema       = $this->config['components']['schemas']['GroupPlan'];
        $notification = $schema['x-openregister-notifications']['periodEndReminder'];

        self::assertSame('calculatedChange', $notification['trigger']['type']);
        self::assertSame('periodEndDue', $notification['trigger']['field']);
        self::assertTrue($notification['trigger']['condition']['eq']);
        self::assertFalse($notification['trigger']['previously']['eq']);
        self::assertSame('coordinatorId', $notification['recipients'][0]['field']);

    }//end testPeriodEndReminderNotificationShape()

    /**
     * GroupPlan.resultsAnalysis is a narrative + evidenceRefs block, NOT a
     * Cito/LVS-specific results schema — the evidenceRefs items are bare
     * UUID strings (referencing GradeEntry/AssessmentResult/FinalGrade/a
     * prior GroupPlanEvaluation), same shape as
     * LearningPlan.goals[].evidenceRefs.
     *
     * @return void
     */
    public function testResultsAnalysisReferencesEvidenceNotADuplicateSchema(): void
    {
        $schema          = $this->config['components']['schemas']['GroupPlan'];
        $resultsAnalysis = $schema['properties']['resultsAnalysis'];

        self::assertSame('object', $resultsAnalysis['type']);
        $props = $resultsAnalysis['properties'];
        self::assertSame('string', $props['narrative']['type']);
        self::assertTrue($props['narrative']['nullable']);
        self::assertSame('array', $props['evidenceRefs']['type']);
        self::assertSame('uuid', $props['evidenceRefs']['items']['format']);

        // resultsAnalysis is not in GroupPlan's required list — creation is
        // never blocked on it (no Cito/LVS import exists yet in this repo).
        self::assertNotContains('resultsAnalysis', $schema['required']);

    }//end testResultsAnalysisReferencesEvidenceNotADuplicateSchema()

    /**
     * GroupPlan.supersedesId reuses the exact LearningPlan.supersedesId
     * version-chain shape — nullable $ref pointing at the same schema, no
     * second forward-pointing "seeds next plan" field exists anywhere on
     * GroupPlan or GroupPlanEvaluation.
     *
     * @return void
     */
    public function testSupersedesIdReusesVersionChainShape(): void
    {
        $groupPlan = $this->config['components']['schemas']['GroupPlan'];
        $supersedesId = $groupPlan['properties']['supersedesId'];

        self::assertTrue($supersedesId['nullable']);
        self::assertSame('GroupPlan', $supersedesId['$ref']);
        self::assertNull($supersedesId['default']);

        $evaluation = $this->config['components']['schemas']['GroupPlanEvaluation'];
        self::assertArrayNotHasKey('seedsGroupPlanId', $evaluation['properties']);

    }//end testSupersedesIdReusesVersionChainShape()

    /**
     * GroupPlanSubgroup carries learnerIds/instructieniveau/
     * differentiatedGoal/approach/intendedOutcome but NO
     * learningPlanId/learningPlanIds field — the link to a member's
     * LearningPlan is resolved live (design.md "Why no stored link"), never
     * stored as a denormalised reference.
     *
     * @return void
     */
    public function testGroupPlanSubgroupHasNoDenormalisedLearningPlanLink(): void
    {
        $schema = $this->config['components']['schemas']['GroupPlanSubgroup'];

        self::assertSame('group-plan-subgroup', $schema['slug']);
        self::assertSame(
            ['groupPlanId', 'name', 'instructieniveau', 'differentiatedGoal', 'approach', 'tenant_id'],
            $schema['required']
        );

        $properties = $schema['properties'];
        self::assertArrayNotHasKey('learningPlanId', $properties);
        self::assertArrayNotHasKey('learningPlanIds', $properties);
        self::assertArrayNotHasKey('supportRequestId', $properties);
        self::assertArrayNotHasKey('supportRequestIds', $properties);

        self::assertSame(
            ['intensief', 'basis', 'verdiept', 'custom'],
            $properties['instructieniveau']['enum']
        );
        self::assertSame('array', $properties['learnerIds']['type']);
        self::assertSame('string', $properties['learnerIds']['items']['type']);

    }//end testGroupPlanSubgroupHasNoDenormalisedLearningPlanLink()

    /**
     * GroupPlanEvaluation.outcomes supports one entry per subgroup with an
     * outcome enum (met|partially-met|not-met), each entry referencing a
     * GroupPlanSubgroup by UUID.
     *
     * @return void
     */
    public function testGroupPlanEvaluationOutcomesShape(): void
    {
        $schema = $this->config['components']['schemas']['GroupPlanEvaluation'];

        self::assertSame('group-plan-evaluation', $schema['slug']);
        self::assertSame(
            ['groupPlanId', 'evaluatedAt', 'evaluatedBy', 'tenant_id'],
            $schema['required']
        );

        $outcomeItem = $schema['properties']['outcomes']['items'];
        self::assertSame(['subgroupId', 'outcome'], $outcomeItem['required']);
        self::assertSame('GroupPlanSubgroup', $outcomeItem['properties']['subgroupId']['$ref']);
        self::assertSame(
            ['met', 'partially-met', 'not-met'],
            $outcomeItem['properties']['outcome']['enum']
        );

    }//end testGroupPlanEvaluationOutcomesShape()

    /**
     * SupportRequest.originGroupPlanSubgroupId is additive, nullable, and
     * independent of the existing learningPlanId — a request may originate
     * from a GroupPlanSubgroup, a LearningPlan, both, or neither.
     *
     * @return void
     */
    public function testSupportRequestOriginGroupPlanSubgroupIdIsAdditiveAndIndependent(): void
    {
        $schema = $this->config['components']['schemas']['SupportRequest'];

        $origin = $schema['properties']['originGroupPlanSubgroupId'];
        self::assertTrue($origin['nullable']);
        self::assertSame('GroupPlanSubgroup', $origin['$ref']);
        self::assertNull($origin['default']);

        // Purely additive: the existing required list is untouched.
        self::assertSame(
            ['learnerId', 'raisedBy', 'supportDomain', 'description', 'urgency', 'tenant_id'],
            $schema['required']
        );

        // learningPlanId is still present, unaffected, and independently nullable.
        $learningPlanId = $schema['properties']['learningPlanId'];
        self::assertTrue($learningPlanId['nullable']);
        self::assertSame('LearningPlan', $learningPlanId['$ref']);

    }//end testSupportRequestOriginGroupPlanSubgroupIdIsAdditiveAndIndependent()

    /**
     * The seeded GroupPlan/GroupPlanSubgroup/GroupPlanEvaluation fixtures
     * exercise the cross-lookup and version-chain scenarios: an active plan
     * with an intensief/basis/verdiept split, an intensief-subgroup learner
     * (learner-001) also present in the closed prior plan's subgroup, and a
     * closed prior-period plan referenced via the active plan's
     * supersedesId.
     *
     * @return void
     */
    public function testSeedFixturesExerciseSubgroupSplitAndVersionChain(): void
    {
        $groupPlanSeeds = $this->config['components']['schemas']['GroupPlan']['x-openregister-seed'];
        self::assertCount(2, $groupPlanSeeds);

        $closed = $groupPlanSeeds[0];
        $active = $groupPlanSeeds[1];
        self::assertSame('closed', $closed['lifecycle']);
        self::assertSame('active', $active['lifecycle']);
        self::assertNull($closed['supersedesId']);
        self::assertSame($closed['id'], $active['supersedesId']);

        $subgroupSeeds = $this->config['components']['schemas']['GroupPlanSubgroup']['x-openregister-seed'];
        $activeSubgroups = array_values(array_filter(
            $subgroupSeeds,
            static fn (array $s): bool => $s['groupPlanId'] === $active['id']
        ));
        $levels = array_column($activeSubgroups, 'instructieniveau');
        sort($levels);
        self::assertSame(['basis', 'intensief', 'verdiept'], $levels);

        $intensief = array_values(array_filter(
            $activeSubgroups,
            static fn (array $s): bool => $s['instructieniveau'] === 'intensief'
        ))[0];
        self::assertContains('learner-001', $intensief['learnerIds']);

        $evaluationSeeds = $this->config['components']['schemas']['GroupPlanEvaluation']['x-openregister-seed'];
        self::assertSame($closed['id'], $evaluationSeeds[0]['groupPlanId']);
        self::assertContains($evaluationSeeds[0]['id'], $active['resultsAnalysis']['evidenceRefs']);

    }//end testSeedFixturesExerciseSubgroupSplitAndVersionChain()
}//end class
