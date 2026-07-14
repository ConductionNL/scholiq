<?php

/**
 * Scholiq PortfolioGradeEmitHandler unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/eportfolio/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\PortfolioGradeEmitHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortfolioGradeEmitHandler::handle() on Portfolio → graded.
 *
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-graded-course-bound-portfolio-flows-through-the-existing-gradeentry-pipeline-not-a-parallel-one
 * @spec openspec/changes/eportfolio/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 */
class PortfolioGradeEmitHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls, captured by the ObjectService stub used per test.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Reset the capture buffer before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];

    }//end setUp()

    /**
     * Build a handler backed by an ObjectService stub.
     *
     * @param array<string, mixed>|null $curriculumPlan CurriculumPlan returned for
     *                                                   curriculum-plan lookups.
     *
     * @return PortfolioGradeEmitHandler
     */
    private function makeHandler(?array $curriculumPlan): PortfolioGradeEmitHandler
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($curriculumPlan) {
                if ($config['schema'] === 'curriculum-plan') {
                    return ($curriculumPlan === null) ? [] : [$curriculumPlan];
                }

                return [];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                if ($schema === 'grade-entry' && ($object['id'] ?? null) === null) {
                    $object['id'] = 'grade-entry-generated';
                }

                return $object;
            }
        );

        return new PortfolioGradeEmitHandler($objectService, $this->createMock(LoggerInterface::class));

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a Portfolio → graded transition.
     *
     * @param array<string, mixed> $portfolioData The Portfolio's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $portfolioData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($portfolioData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('portfolio');
        $event->method('getTo')->willReturn('graded');
        $event->method('getFrom')->willReturn('submitted');
        $event->method('getUserId')->willReturn('teacher-1');

        return $event;

    }//end makeEvent()

    /**
     * Scenario: "Transitioning a course-bound portfolio to graded emits a concept GradeEntry" —
     * sourceKind: portfolio, lifecycle: concept, portfolioId set, and Portfolio.gradeEntryId
     * back-linked.
     *
     * @return void
     */
    public function testGradedPortfolioCreatesConceptGradeEntry(): void
    {
        $plan    = ['id' => 'plan-1', 'gradeScaleId' => 'scale-numeric'];
        $handler = $this->makeHandler($plan);

        $portfolio = [
            'id'                        => 'portfolio-1',
            'learnerId'                 => 'learner-7',
            'curriculumPlanId'          => 'plan-1',
            'curriculumPlanComponentId' => 'component-9',
            'gradeValue'                => 7.5,
            'gradeEntryId'              => null,
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($portfolio));

        $gradeSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
        $this->assertCount(1, $gradeSaves);

        $saved = $gradeSaves[0]['object'];
        $this->assertSame('portfolio', $saved['sourceKind']);
        $this->assertSame('portfolio-1', $saved['portfolioId']);
        $this->assertSame('concept', $saved['lifecycle']);
        $this->assertSame('learner-7', $saved['learnerId']);
        $this->assertSame('plan-1', $saved['curriculumPlanId']);
        $this->assertSame('component-9', $saved['componentId']);
        $this->assertSame(7.5, $saved['value']);
        $this->assertSame('scale-numeric', $saved['gradeScaleId']);
        $this->assertSame('teacher-1', $saved['grader']);
        $this->assertSame('tenant-a', $saved['tenant_id']);
        $this->assertNotSame('manual', $saved['sourceKind']);

        // Back-link: a second saveObject() call writes gradeEntryId onto the Portfolio.
        $portfolioSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'portfolio'));
        $this->assertCount(1, $portfolioSaves);
        $this->assertSame('grade-entry-generated', $portfolioSaves[0]['object']['gradeEntryId']);

    }//end testGradedPortfolioCreatesConceptGradeEntry()

    /**
     * Scenario: "Re-triggering the graded transition does not create a duplicate GradeEntry" —
     * when Portfolio.gradeEntryId is already set, the handler is idempotent and writes nothing.
     *
     * @return void
     */
    public function testNoDuplicateWhenGradeEntryIdAlreadySet(): void
    {
        $handler = $this->makeHandler(['id' => 'plan-1', 'gradeScaleId' => 'scale-numeric']);

        $portfolio = [
            'id'                        => 'portfolio-1',
            'learnerId'                 => 'learner-7',
            'curriculumPlanId'          => 'plan-1',
            'curriculumPlanComponentId' => 'component-9',
            'gradeValue'                => 7.5,
            'gradeEntryId'              => 'grade-entry-existing',
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($portfolio));

        $this->assertCount(0, $this->savedObjects);

    }//end testNoDuplicateWhenGradeEntryIdAlreadySet()

    /**
     * A graded Portfolio missing a required field (learnerId/curriculumPlanId/
     * curriculumPlanComponentId/gradeValue) is a safe no-op — no GradeEntry is written.
     *
     * @return void
     */
    public function testMissingRequiredFieldIsNoOp(): void
    {
        $handler = $this->makeHandler(['id' => 'plan-1', 'gradeScaleId' => 'scale-numeric']);

        $portfolio = [
            'id'                        => 'portfolio-1',
            'learnerId'                 => 'learner-7',
            'curriculumPlanId'          => 'plan-1',
            'curriculumPlanComponentId' => 'component-9',
            'gradeValue'                => null,
            'gradeEntryId'              => null,
            'tenant_id'                 => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($portfolio));

        $this->assertCount(0, $this->savedObjects);

    }//end testMissingRequiredFieldIsNoOp()

    /**
     * Events for other schemas/states are ignored entirely.
     *
     * @return void
     */
    public function testIgnoresUnrelatedEvents(): void
    {
        $handler = $this->makeHandler(['id' => 'plan-1', 'gradeScaleId' => 'scale-numeric']);

        $objectEntity = $this->createMock(ObjectEntity::class);
        $event        = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('werkproces-assessment');
        $event->method('getTo')->willReturn('graded');

        $handler->handle($event);

        $this->assertCount(0, $this->savedObjects);

    }//end testIgnoresUnrelatedEvents()
}//end class
