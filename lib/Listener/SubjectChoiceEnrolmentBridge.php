<?php

/**
 * Scholiq Subject Choice Enrolment Bridge
 *
 * IEventListener for SubjectChoice lifecycle `approved -> locked` (the OR
 * ObjectTransitionedEvent with register=scholiq, schema=subject-choice,
 * to=locked). Creates an Enrolment (source: "subject-choice") for each
 * course in selectedElectiveCourseIds that the learner is not already
 * enrolled in — idempotent against a repeated lock.
 *
 * ADR-031 legitimate exception: cross-object write bridge — an approved
 * vakkenpakket must feed Enrolment rows. This cannot be expressed as a
 * schema declaration. Mirrors ExcuseApprovalHandler's cross-schema
 * write-bridge shape.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-an-approved-subject-choice-feeds-enrolment
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges SubjectChoice.approved -> locked to per-course Enrolment creation.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-an-approved-subject-choice-feeds-enrolment
 */
class SubjectChoiceEnrolmentBridge implements IEventListener
{

    private const SCHOLIQ_REGISTER      = 'scholiq';
    private const SUBJECT_CHOICE_SCHEMA = 'subject-choice';
    private const ENROLMENT_SCHEMA      = 'enrolment';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
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
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-an-approved-subject-choice-feeds-enrolment
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::SUBJECT_CHOICE_SCHEMA
            || $event->getFrom() !== 'approved'
            || $event->getTo() !== 'locked'
        ) {
            return;
        }

        $this->bridge(choice: $event->getObject()->jsonSerialize());

    }//end handle()

    /**
     * Create an Enrolment per selected course not already enrolled.
     *
     * @param array<string,mixed> $choice The locked SubjectChoice property array.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-locking-a-subject-choice-enrols-the-learner-in-the-chosen-electives
     */
    private function bridge(array $choice): void
    {
        $learnerId = (string) ($choice['learnerId'] ?? '');
        $tenantId  = (string) ($choice['tenant_id'] ?? '');
        $selected  = $choice['selectedElectiveCourseIds'] ?? [];

        if ($learnerId === '' || is_array($selected) === false || count($selected) === 0) {
            return;
        }

        $existingCourseIds = $this->fetchExistingEnrolmentCourseIds(learnerId: $learnerId, tenantId: $tenantId);

        $created = 0;
        foreach ($selected as $courseId) {
            if (in_array($courseId, $existingCourseIds, true) === true) {
                // Already enrolled in this course — do not duplicate.
                continue;
            }

            $this->objectService->saveObject(
                register: self::SCHOLIQ_REGISTER,
                schema: self::ENROLMENT_SCHEMA,
                object: [
                    'learnerId' => $learnerId,
                    'courseId'  => $courseId,
                    'source'    => 'subject-choice',
                    'tenant_id' => $tenantId,
                ]
            );
            $created++;
        }

        $this->logger->info(
            '[SubjectChoiceEnrolmentBridge] SubjectChoice {id} locked — {count} Enrolment(s) created for learner {learner}.',
            ['id' => $choice['id'] ?? ($choice['uuid'] ?? ''), 'count' => $created, 'learner' => $learnerId]
        );

    }//end bridge()

    /**
     * Fetch the courseIds the learner already has an Enrolment for.
     *
     * @param string $learnerId Nextcloud user id.
     * @param string $tenantId  Tenant ID.
     *
     * @return array<int,mixed>
     */
    private function fetchExistingEnrolmentCourseIds(string $learnerId, string $tenantId): array
    {
        $filters = ['learnerId' => $learnerId];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $rows = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENROLMENT_SCHEMA,
                'filters'  => $filters,
                'limit'    => 2000,
            ]
        );

        $courseIds = [];
        foreach ($rows as $row) {
            if (is_array($row) === false) {
                $row = $row->jsonSerialize();
            }

            if (isset($row['courseId']) === true) {
                $courseIds[] = $row['courseId'];
            }
        }

        return $courseIds;

    }//end fetchExistingEnrolmentCourseIds()
}//end class
