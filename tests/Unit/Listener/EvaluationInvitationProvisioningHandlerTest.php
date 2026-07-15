<?php

/**
 * Scholiq EvaluationInvitationProvisioningHandler unit tests.
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
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\EvaluationInvitationProvisioningHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EvaluationInvitationProvisioningHandler::handle() on EvaluationCampaign → open.
 */
class EvaluationInvitationProvisioningHandlerTest extends TestCase
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
     * Build a handler with a stubbed ObjectService.
     *
     * @param array<int, array<string, mixed>> $cohorts            Cohort rows returned by find()/findAll().
     * @param array<int, array<string, mixed>> $existingInvitations Existing EvaluationInvitation rows for the campaign.
     *
     * @return EvaluationInvitationProvisioningHandler
     */
    private function makeHandler(array $cohorts, array $existingInvitations = []): EvaluationInvitationProvisioningHandler
    {
        $objectService = $this->createMock(ObjectService::class);

        $cohortsById = [];
        foreach ($cohorts as $cohort) {
            $cohortsById[$cohort['id']] = $cohort;
        }

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($cohortsById) {
                if ($schema === 'cohort') {
                    return $cohortsById[$id] ?? null;
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($cohorts, $existingInvitations) {
                if ($config['schema'] === 'cohort') {
                    $courseId = $config['filters']['courseId'] ?? null;
                    return array_values(array_filter($cohorts, static fn ($c) => ($c['courseId'] ?? null) === $courseId));
                }

                if ($config['schema'] === 'evaluation-invitation') {
                    return $existingInvitations;
                }

                return [];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        return new EvaluationInvitationProvisioningHandler(
            $objectService,
            $this->createMock(LoggerInterface::class),
        );

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for an EvaluationCampaign → open transition.
     *
     * @param array<string, mixed> $campaignData The EvaluationCampaign's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $campaignData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($campaignData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('evaluation-campaign');
        $event->method('getTo')->willReturn('open');
        $event->method('getFrom')->willReturn('draft');

        return $event;

    }//end makeEvent()

    /**
     * One EvaluationInvitation is provisioned per distinct learner across a
     * multi-cohort campaign (a learner appearing in two qualifying cohorts
     * gets exactly one invitation).
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
     */
    public function testOneInvitationPerLearnerAcrossMultiCohortCampaign(): void
    {
        $cohorts = [
            [
                'id'         => 'cohort-1',
                'courseId'   => 'course-1',
                'learnerIds' => ['learner-1', 'learner-2'],
            ],
            [
                'id'         => 'cohort-2',
                'courseId'   => 'course-1',
                'learnerIds' => ['learner-2', 'learner-3'],
            ],
        ];

        $handler = $this->makeHandler(cohorts: $cohorts);

        $campaign = [
            'id'            => 'campaign-1',
            'courseIds'     => ['course-1'],
            'cohortIds'     => [],
            'academicYear'  => '2025-2026',
            'period'        => 'Q1',
            'closesAt'      => '2026-08-01T00:00:00+02:00',
            'tenant_id'     => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($campaign));

        $invitationSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'evaluation-invitation'));
        self::assertCount(3, $invitationSaves, 'Exactly one invitation per distinct learner (learner-1, learner-2, learner-3)');

        $learnerIds = array_map(static fn ($s) => $s['object']['learnerId'], $invitationSaves);
        self::assertEqualsCanonicalizing(['learner-1', 'learner-2', 'learner-3'], $learnerIds);

        foreach ($invitationSaves as $save) {
            self::assertSame('campaign-1', $save['object']['campaignId']);
            self::assertSame('course-1', $save['object']['courseId']);
            self::assertFalse($save['object']['hasResponded']);
            self::assertSame('2026-08-01T00:00:00+02:00', $save['object']['campaignClosesAt']);
            self::assertSame('2025-2026', $save['object']['academicYear']);
            self::assertSame('Q1', $save['object']['period']);
        }

    }//end testOneInvitationPerLearnerAcrossMultiCohortCampaign()

    /**
     * A duplicate/replayed open event does not create duplicate invitations
     * for a learner who already has one for this campaign.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
     */
    public function testNoDuplicateInvitationOnRepeatedOpenEvent(): void
    {
        $cohorts = [
            [
                'id'         => 'cohort-1',
                'courseId'   => 'course-1',
                'learnerIds' => ['learner-1', 'learner-2'],
            ],
        ];

        $existing = [
            ['campaignId' => 'campaign-1', 'learnerId' => 'learner-1', 'hasResponded' => false],
        ];

        $handler = $this->makeHandler(cohorts: $cohorts, existingInvitations: $existing);

        $campaign = [
            'id'           => 'campaign-1',
            'courseIds'    => ['course-1'],
            'cohortIds'    => [],
            'academicYear' => '2025-2026',
            'period'       => 'Q1',
            'closesAt'     => '2026-08-01T00:00:00+02:00',
            'tenant_id'    => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($campaign));

        $invitationSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'evaluation-invitation'));
        self::assertCount(1, $invitationSaves, 'Only the not-yet-invited learner-2 gets a new row');
        self::assertSame('learner-2', $invitationSaves[0]['object']['learnerId']);

    }//end testNoDuplicateInvitationOnRepeatedOpenEvent()

    /**
     * A transition to a state other than `open` is ignored entirely.
     *
     * @return void
     */
    public function testIgnoresNonOpenTransitions(): void
    {
        $handler = $this->makeHandler(cohorts: []);

        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn(['id' => 'campaign-1']);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('evaluation-campaign');
        $event->method('getTo')->willReturn('closed');

        $handler->handle($event);

        self::assertEmpty($this->savedObjects);

    }//end testIgnoresNonOpenTransitions()

    /**
     * A cohort in scope with no courseId is skipped (logged, not fatal) —
     * its learners receive no invitation.
     *
     * @return void
     */
    public function testCohortWithNoCourseIdIsSkipped(): void
    {
        $cohorts = [
            [
                'id'         => 'cohort-1',
                'courseId'   => null,
                'learnerIds' => ['learner-1'],
            ],
        ];

        $handler = $this->makeHandler(cohorts: $cohorts);

        $campaign = [
            'id'           => 'campaign-1',
            'courseIds'    => [],
            'cohortIds'    => ['cohort-1'],
            'academicYear' => '2025-2026',
            'period'       => 'Q1',
            'closesAt'     => '2026-08-01T00:00:00+02:00',
            'tenant_id'    => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($campaign));

        self::assertEmpty($this->savedObjects, 'No invitation can be provisioned without a courseId');

    }//end testCohortWithNoCourseIdIsSkipped()
}//end class
