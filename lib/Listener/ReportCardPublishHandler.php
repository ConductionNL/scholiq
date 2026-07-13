<?php

/**
 * Scholiq Report Card Publish Handler
 *
 * IEventListener for ObjectTransitionedEvent (register=scholiq,
 * schema=report-card, to=published-to-parents). Resolves the learner's
 * `LearnerProfile.parentIds[]` and creates one `ReportCardParentNotification`
 * per parent, stamping `visibleFrom = now()` and
 * `idempotencyKey = "{reportCardId}-parent-{recipient}"`.
 *
 * Mirrors `GradeRollupHandler::fanOutParentNotifications()`'s reasoning and
 * shape exactly: OR's declarative `x-openregister-notifications` addresses a
 * single field (`learnerId`, already covered by ReportCard's own declared
 * `reportCardPublished` transition-trigger rule), not a related array
 * (`LearnerProfile.parentIds[]`) — a PHP fan-out bridge is required.
 *
 * ADR-031 legitimate exception: "Lifecycle handler — event-to-object-write
 * bridge that cannot be expressed as a schema declaration."
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-publication-fans-out-a-learner-parent-notification-mirroring-gradenotifications-reason
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publishing-notifies-the-learner-directly-and-fans-out-to-each-parent
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Fans out a ReportCardParentNotification per parent when a ReportCard
 * transitions to `published-to-parents`.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-publication-fans-out-a-learner-parent-notification-mirroring-gradenotifications-reason
 */
class ReportCardPublishHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER       = 'scholiq';
    private const REPORT_CARD_SCHEMA     = 'report-card';
    private const LEARNER_PROFILE_SCHEMA = 'learner-profile';
    private const REPORT_CARD_PARENT_NOTIFICATION_SCHEMA = 'report-card-parent-notification';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param ITimeFactory    $timeFactory   NC time source (injectable "now" for tests).
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publishing-notifies-the-learner-directly-and-fans-out-to-each-parent
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::REPORT_CARD_SCHEMA || $event->getTo() !== 'published-to-parents') {
            return;
        }

        $this->fanOutParentNotifications(reportCard: $event->getObject()->jsonSerialize());

    }//end handle()

    /**
     * Resolve `LearnerProfile.parentIds[]` for the report card's learner and
     * create one `ReportCardParentNotification` per parent.
     *
     * @param array<string,mixed> $reportCard The published-to-parents ReportCard data array.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publishing-notifies-the-learner-directly-and-fans-out-to-each-parent
     */
    private function fanOutParentNotifications(array $reportCard): void
    {
        $reportCardId = (string) ($reportCard['id'] ?? ($reportCard['uuid'] ?? ''));
        $learnerId    = (string) ($reportCard['learnerId'] ?? '');

        if ($reportCardId === '' || $learnerId === '') {
            $this->logger->warning('[ReportCardPublishHandler] ReportCard missing id/learnerId; aborting parent fan-out.');
            return;
        }

        $profiles = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LEARNER_PROFILE_SCHEMA,
                'filters'  => ['learnerId' => $learnerId],
                'limit'    => 1,
            ]
        );

        if (empty($profiles) === true) {
            return;
        }

        $profile   = $this->normalise(row: $profiles[0]);
        $parentIds = $profile['parentIds'] ?? [];

        if (is_array($parentIds) === false || empty($parentIds) === true) {
            return;
        }

        $learnerRef  = $reportCard['learnerRef'] ?? null;
        $tenantId    = (string) ($reportCard['tenant_id'] ?? '');
        $visibleFrom = $this->timeFactory->getDateTime()->format(\DATE_ATOM);

        $notifiedCount = 0;

        foreach ($parentIds as $parentId) {
            if (empty($parentId) === true) {
                continue;
            }

            $this->objectService->saveObject(
                register: self::SCHOLIQ_REGISTER,
                schema: self::REPORT_CARD_PARENT_NOTIFICATION_SCHEMA,
                object: [
                    'event'          => 'reportCardPublished',
                    'recipient'      => $parentId,
                    'sourceId'       => $reportCardId,
                    'learnerId'      => $learnerId,
                    'learnerRef'     => $learnerRef,
                    'idempotencyKey' => $reportCardId.'-parent-'.$parentId,
                    'visibleFrom'    => $visibleFrom,
                    'tenant_id'      => $tenantId,
                ]
            );
            $notifiedCount++;
        }//end foreach

        $this->logger->info(
            '[ReportCardPublishHandler] ReportCard {id} published — {count} parent notification(s) created.',
            ['id' => $reportCardId, 'count' => $notifiedCount]
        );

    }//end fanOutParentNotifications()

    /**
     * Normalise an ObjectService row to a plain array.
     *
     * @param mixed $row Raw row from ObjectService::findAll().
     *
     * @return array<string,mixed>
     */
    private function normalise(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        return $row->jsonSerialize();

    }//end normalise()
}//end class
