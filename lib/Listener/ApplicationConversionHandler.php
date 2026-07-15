<?php

/**
 * Scholiq Application Conversion Handler
 *
 * IEventListener for Application lifecycle -> placed (the OR
 * ObjectTransitionedEvent with register=scholiq, schema=application,
 * to=placed). Creates a LearnerProfile (guardianRefs stamped from
 * Application.guardianRef when set), bulk-creates one Enrolment
 * (source: "admission") per course in the chosen Programme.courseIds, stamps
 * Application.convertedLearnerProfileId/convertedEnrolmentIds, and drives the
 * Application through its existing `convert` transition to `converted`.
 *
 * The applicant has no pre-existing Nextcloud account at intake time — this
 * handler does NOT provision one (design.md Non-Goals: "NC user-account/LMS
 * provisioning on conversion" is explicitly out of scope; the HE Studielink
 * path already owns account provisioning for its own channel). A
 * deterministic, Application-scoped placeholder identity
 * (`applicant-<applicationId>`) satisfies LearnerProfile.ncUserId's and
 * Enrolment.learnerId's required-string shape without inventing a second
 * identity mechanism. This is a documented, flagged gap: an administrator
 * must separately provision the real Nextcloud account and repoint
 * ncUserId/learnerId once one exists — out of scope for this handler.
 *
 * ADR-031 legitimate exception: cross-object write bridge — an accepted
 * Application must create a LearnerProfile and Enrolment rows and drive
 * itself through the existing convert transition. This cannot be expressed
 * as schema metadata declarations. Mirrors GradeRollupHandler's cross-schema
 * write-bridge shape (design.md "Conversion is a write bridge, not a
 * controller").
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-accepted-application-converts-into-a-learnerprofile-and-enrolments
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges Application.placed -> LearnerProfile + Enrolment creation + convert transition.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-accepted-application-converts-into-a-learnerprofile-and-enrolments
 */
class ApplicationConversionHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER       = 'scholiq';
    private const APPLICATION_SCHEMA     = 'application';
    private const LEARNER_PROFILE_SCHEMA = 'learner-profile';
    private const ENROLMENT_SCHEMA       = 'enrolment';
    private const PROGRAMME_SCHEMA       = 'programme';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object access service.
     * @param TransitionEngine $transitionEngine OR lifecycle engine used to dispatch the `convert` transition.
     * @param LoggerInterface  $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
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
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-accepted-application-converts-into-a-learnerprofile-and-enrolments
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::APPLICATION_SCHEMA || $event->getTo() !== 'placed') {
            return;
        }

        $this->convert(application: $event->getObject()->jsonSerialize());

    }//end handle()

    /**
     * Create the LearnerProfile + Enrolments and drive the Application to converted.
     *
     * @param array<string,mixed> $application The placed Application property array.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-placement-creates-a-learnerprofile-and-enrolments
     */
    private function convert(array $application): void
    {
        $applicationId = (string) ($application['id'] ?? ($application['uuid'] ?? ''));
        if ($applicationId === '') {
            $this->logger->warning('[ApplicationConversionHandler] Placed Application has no id; aborting conversion.');
            return;
        }

        $programmeId = (string) ($application['programmeId'] ?? '');
        $tenantId    = (string) ($application['tenant_id'] ?? '');

        // See class docblock: no pre-existing NC account exists for the
        // applicant at intake time, and this handler deliberately does not
        // provision one. This placeholder identity is a documented gap.
        $ncUserId = 'applicant-'.$applicationId;

        $learnerProfileId = $this->createLearnerProfile(application: $application, ncUserId: $ncUserId, tenantId: $tenantId);

        $enrolmentIds = $this->createEnrolments(programmeId: $programmeId, ncUserId: $ncUserId, tenantId: $tenantId);

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::APPLICATION_SCHEMA,
            object: array_merge(
                $application,
                [
                    'convertedLearnerProfileId' => $learnerProfileId,
                    'convertedEnrolmentIds'     => $enrolmentIds,
                ]
            )
        );

        $this->transitionEngine->transition($applicationId, 'convert');

        $this->logger->info(
            '[ApplicationConversionHandler] Application {id} converted — LearnerProfile {profile}, {count} Enrolment(s) created.',
            ['id' => $applicationId, 'profile' => $learnerProfileId, 'count' => count($enrolmentIds)]
        );

    }//end convert()

    /**
     * Create the LearnerProfile for a placed Application.
     *
     * @param array<string,mixed> $application The Application property array.
     * @param string              $ncUserId    The synthesised placeholder identity.
     * @param string              $tenantId    Tenant ID.
     *
     * @return mixed The created LearnerProfile's id, or null when unavailable.
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-placement-creates-a-learnerprofile-and-enrolments
     */
    private function createLearnerProfile(array $application, string $ncUserId, string $tenantId): mixed
    {
        $guardianRef  = $application['guardianRef'] ?? null;
        $guardianRefs = [];
        if (is_string($guardianRef) === true && $guardianRef !== '') {
            $guardianRefs[] = $guardianRef;
        }

        $saved = $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::LEARNER_PROFILE_SCHEMA,
            object: [
                'ncUserId'     => $ncUserId,
                'givenName'    => $application['applicantGivenName'] ?? '',
                'familyName'   => $application['applicantFamilyName'] ?? '',
                'birthDate'    => $application['applicantBirthDate'] ?? null,
                'roles'        => ['learner'],
                'guardianRefs' => $guardianRefs,
                'tenant_id'    => $tenantId,
            ]
        );

        return $this->extractId(row: $saved);

    }//end createLearnerProfile()

    /**
     * Bulk-create one Enrolment (source: admission) per Programme.courseIds entry.
     *
     * @param string $programmeId The Programme UUID applied for.
     * @param string $ncUserId    The learner's (placeholder) Nextcloud user id.
     * @param string $tenantId    Tenant ID.
     *
     * @return array<int,mixed> The created Enrolment ids.
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-placement-creates-a-learnerprofile-and-enrolments
     */
    private function createEnrolments(string $programmeId, string $ncUserId, string $tenantId): array
    {
        $courseIds = $this->fetchProgrammeCourseIds(programmeId: $programmeId);

        $enrolmentIds = [];
        foreach ($courseIds as $courseId) {
            $saved = $this->objectService->saveObject(
                register: self::SCHOLIQ_REGISTER,
                schema: self::ENROLMENT_SCHEMA,
                object: [
                    'learnerId' => $ncUserId,
                    'courseId'  => $courseId,
                    'source'    => 'admission',
                    'tenant_id' => $tenantId,
                ]
            );

            $enrolmentId = $this->extractId(row: $saved);
            if ($enrolmentId !== null) {
                $enrolmentIds[] = $enrolmentId;
            }
        }

        return $enrolmentIds;

    }//end createEnrolments()

    /**
     * Fetch a Programme's courseIds.
     *
     * @param string $programmeId Programme UUID.
     *
     * @return array<int,mixed>
     */
    private function fetchProgrammeCourseIds(string $programmeId): array
    {
        if ($programmeId === '') {
            return [];
        }

        $programme = $this->objectService->find(
            id: $programmeId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::PROGRAMME_SCHEMA
        );

        if ($programme === null) {
            return [];
        }

        $data = $programme;
        if (is_array($programme) === false) {
            $data = $programme->jsonSerialize();
        }

        $courseIds = $data['courseIds'] ?? [];

        if (is_array($courseIds) === false) {
            return [];
        }

        return $courseIds;

    }//end fetchProgrammeCourseIds()

    /**
     * Extract the `id` (or `uuid`) from a saveObject() return value.
     *
     * @param mixed $row Raw return value from ObjectService::saveObject().
     *
     * @return mixed
     */
    private function extractId(mixed $row): mixed
    {
        if ($row === null) {
            return null;
        }

        $data = $row;
        if (is_array($row) === false) {
            $data = $row->jsonSerialize();
        }

        return $data['id'] ?? ($data['uuid'] ?? null);

    }//end extractId()
}//end class
