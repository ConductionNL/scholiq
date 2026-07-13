<?php

/**
 * Scholiq ReportCardPublishHandler unit tests.
 *
 * Covers the parent fan-out on `publishToParents`: one
 * ReportCardParentNotification per LearnerProfile.parentIds entry, each
 * carrying its own idempotencyKey, plus the no-parents and unresolvable-
 * profile no-op paths.
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publishing-notifies-the-learner-directly-and-fans-out-to-each-parent
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\ReportCardPublishHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ReportCardPublishHandler::handle().
 */
class ReportCardPublishHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];

    }//end setUp()

    /**
     * Build a handler whose ObjectService::findAll(learner-profile) resolves
     * a fixed learnerId => LearnerProfile map.
     *
     * @param array<string,array<string,mixed>|null> $profiles learnerId => LearnerProfile data (or null = unresolvable).
     * @param DateTime                                $now      The "now" the injected ITimeFactory reports.
     *
     * @return ReportCardPublishHandler
     */
    private function makeHandler(array $profiles, DateTime $now): ReportCardPublishHandler
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($profiles) {
                if ($config['schema'] !== 'learner-profile') {
                    return [];
                }

                $learnerId = $config['filters']['learnerId'] ?? '';
                $profile   = $profiles[$learnerId] ?? null;

                return $profile === null ? [] : [$profile];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn($now);

        return new ReportCardPublishHandler($objectService, $timeFactory, new NullLogger());

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a ReportCard ->
     * published-to-parents transition.
     *
     * @param array<string,mixed> $cardData The ReportCard's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $cardData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($cardData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('report-card');
        $event->method('getTo')->willReturn('published-to-parents');

        return $event;

    }//end makeEvent()

    /**
     * A learner with 2 linked parents fans out 2 ReportCardParentNotifications,
     * each with its own idempotencyKey ({sourceId}-parent-{recipient}).
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publishing-notifies-the-learner-directly-and-fans-out-to-each-parent
     */
    public function testTwoParentsYieldTwoNotificationsWithDistinctIdempotencyKeys(): void
    {
        $now     = new DateTime('2026-07-13T09:00:00+00:00');
        $handler = $this->makeHandler(
            profiles: ['learner-1' => ['parentIds' => ['parent-1', 'parent-2']]],
            now: $now
        );

        $card = [
            'id'         => 'card-1',
            'learnerId'  => 'learner-1',
            'learnerRef' => 'profile-uuid-1',
            'tenant_id'  => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($card));

        $notificationSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'report-card-parent-notification'));
        self::assertCount(2, $notificationSaves);

        $byRecipient = [];
        foreach ($notificationSaves as $save) {
            $byRecipient[$save['object']['recipient']] = $save['object'];
        }

        self::assertSame('reportCardPublished', $byRecipient['parent-1']['event']);
        self::assertSame('card-1', $byRecipient['parent-1']['sourceId']);
        self::assertSame('learner-1', $byRecipient['parent-1']['learnerId']);
        self::assertSame('profile-uuid-1', $byRecipient['parent-1']['learnerRef']);
        self::assertSame('card-1-parent-parent-1', $byRecipient['parent-1']['idempotencyKey']);
        self::assertSame('card-1-parent-parent-2', $byRecipient['parent-2']['idempotencyKey']);
        self::assertSame('tenant-a', $byRecipient['parent-1']['tenant_id']);
        self::assertNotEmpty($byRecipient['parent-1']['visibleFrom']);

    }//end testTwoParentsYieldTwoNotificationsWithDistinctIdempotencyKeys()

    /**
     * A learner with no linked parents yields zero notifications, no error.
     *
     * @return void
     */
    public function testNoParentsYieldsNoNotifications(): void
    {
        $now     = new DateTime('2026-07-13T09:00:00+00:00');
        $handler = $this->makeHandler(profiles: ['learner-1' => ['parentIds' => []]], now: $now);

        $card = ['id' => 'card-1', 'learnerId' => 'learner-1', 'tenant_id' => 'tenant-a'];

        $handler->handle($this->makeEvent($card));

        $notificationSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'report-card-parent-notification'));
        self::assertCount(0, $notificationSaves);

    }//end testNoParentsYieldsNoNotifications()

    /**
     * An unresolvable LearnerProfile (no profile found for the learnerId)
     * yields zero notifications, no error.
     *
     * @return void
     */
    public function testUnresolvableProfileYieldsNoNotifications(): void
    {
        $now     = new DateTime('2026-07-13T09:00:00+00:00');
        $handler = $this->makeHandler(profiles: [], now: $now);

        $card = ['id' => 'card-1', 'learnerId' => 'learner-1', 'tenant_id' => 'tenant-a'];

        $handler->handle($this->makeEvent($card));

        $notificationSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'report-card-parent-notification'));
        self::assertCount(0, $notificationSaves);

    }//end testUnresolvableProfileYieldsNoNotifications()
}//end class
