<?php

/**
 * Scholiq Evaluation Invitation Provisioning Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent, filtered to the
 * EvaluationCampaign schema's `open` transition. Resolves every learner in
 * the campaign's scope (courseIds directly, plus cohortIds — both resolved
 * via the referenced Cohort.learnerIds) and creates one EvaluationInvitation
 * per (campaignId, learnerId) pair, stamping courseId/cohortId/
 * academicYear/period/campaignClosesAt onto each row.
 *
 * ADR-031 legitimate exception: "Lifecycle handler — event-to-object-write
 * bridge that cannot be expressed as a schema declaration." Idempotency-
 * keyed: queries existing EvaluationInvitations for the campaign first and
 * never creates a second row for a learner who already has one, so a
 * duplicate/replayed `open` event (or a learner appearing in more than one
 * qualifying Cohort) cannot create duplicate invitations.
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
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges EvaluationCampaign `open` → one EvaluationInvitation per learner in scope.
 *
 * @implements IEventListener<Event>
 */
class EvaluationInvitationProvisioningHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER           = 'scholiq';
    private const EVALUATION_CAMPAIGN_SCHEMA = 'evaluation-campaign';
    private const EVALUATION_INVITATION_SCHEMA = 'evaluation-invitation';
    private const COHORT_SCHEMA = 'cohort';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object access.
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
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER
            || $event->getSchema() !== self::EVALUATION_CAMPAIGN_SCHEMA
            || $event->getTo() !== 'open'
        ) {
            return;
        }

        $campaign   = $event->getObject()->jsonSerialize();
        $campaignId = $campaign['id'] ?? ($campaign['uuid'] ?? null);

        if ($campaignId === null) {
            $this->logger->warning(
                '[EvaluationInvitationProvisioningHandler] EvaluationCampaign has no id; cannot provision invitations.'
            );
            return;
        }

        $scopedCohorts = $this->resolveScopedCohorts(campaign: $campaign);

        if (empty($scopedCohorts) === true) {
            $this->logger->info(
                '[EvaluationInvitationProvisioningHandler] EvaluationCampaign {campaignId} opened with no '
                .'resolvable Cohort scope (courseIds matched no Cohort, cohortIds empty/unresolvable); '
                .'no invitations provisioned.',
                ['campaignId' => $campaignId]
            );
            return;
        }

        $existingLearnerIds = $this->fetchExistingInvitedLearnerIds(campaignId: $campaignId);

        $academicYear = $campaign['academicYear'] ?? '';
        $period       = $campaign['period'] ?? '';
        $closesAt     = $campaign['closesAt'] ?? null;
        $tenantId     = $campaign['tenant_id'] ?? '';

        $provisioned = $existingLearnerIds;
        foreach ($scopedCohorts as $cohort) {
            $courseId = $cohort['courseId'] ?? null;
            if (empty($courseId) === true) {
                // A cohort with no courseId cannot back a course-scoped invitation
                // (design.md: course-evaluation is keyed to a Course). Skip, log,
                // fail soft — matches GradeRollupHandler's "insufficient data, skip"
                // shape.
                $this->logger->warning(
                    '[EvaluationInvitationProvisioningHandler] Cohort {cohortId} in scope for campaign '
                    .'{campaignId} has no courseId; skipping its learners.',
                    ['cohortId' => $cohort['id'] ?? '', 'campaignId' => $campaignId]
                );
                continue;
            }

            $cohortId   = $cohort['id'] ?? ($cohort['uuid'] ?? null);
            $learnerIds = $cohort['learnerIds'] ?? [];

            foreach ($learnerIds as $learnerId) {
                if (empty($learnerId) === true || isset($provisioned[$learnerId]) === true) {
                    continue;
                }

                $provisioned[$learnerId] = true;

                $this->objectService->saveObject(
                    register: self::SCHOLIQ_REGISTER,
                    schema: self::EVALUATION_INVITATION_SCHEMA,
                    object: [
                        'campaignId'       => $campaignId,
                        'courseId'         => $courseId,
                        'cohortId'         => $cohortId,
                        'learnerId'        => $learnerId,
                        'hasResponded'     => false,
                        'respondedAt'      => null,
                        'campaignClosesAt' => $closesAt,
                        'academicYear'     => $academicYear,
                        'period'           => $period,
                        'tenant_id'        => $tenantId,
                    ]
                );
            }//end foreach
        }//end foreach

    }//end handle()

    /**
     * Resolve every Cohort in the campaign's scope: Cohorts referenced directly via
     * cohortIds, plus every Cohort whose courseId is in courseIds.
     *
     * @param array $campaign The EvaluationCampaign data.
     *
     * @return array<int, array> Cohort data arrays, de-duplicated by id.
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
     */
    private function resolveScopedCohorts(array $campaign): array
    {
        $byId = [];

        foreach (($campaign['cohortIds'] ?? []) as $cohortId) {
            if (empty($cohortId) === true || isset($byId[$cohortId]) === true) {
                continue;
            }

            $cohort = $this->objectService->find(
                id: $cohortId,
                register: self::SCHOLIQ_REGISTER,
                schema: self::COHORT_SCHEMA
            );

            if ($cohort === null) {
                continue;
            }

            $cohortData = $cohort;
            if (is_array($cohort) === false) {
                $cohortData = $cohort->jsonSerialize();
            }

            $byId[$cohortId] = $cohortData;
        }//end foreach

        foreach (($campaign['courseIds'] ?? []) as $courseId) {
            if (empty($courseId) === true) {
                continue;
            }

            $matches = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::COHORT_SCHEMA,
                    'filters'  => ['courseId' => $courseId],
                ]
            );

            foreach ($matches as $match) {
                $matchData = $match;
                if (is_array($match) === false) {
                    $matchData = $match->jsonSerialize();
                }

                $cohortId = $matchData['id'] ?? ($matchData['uuid'] ?? null);
                if ($cohortId === null || isset($byId[$cohortId]) === true) {
                    continue;
                }

                $byId[$cohortId] = $matchData;
            }
        }//end foreach

        return array_values($byId);

    }//end resolveScopedCohorts()

    /**
     * Fetch the set of learnerIds that already have an EvaluationInvitation for
     * this campaign, keyed by learnerId for O(1) lookup — the idempotency guard
     * against duplicate provisioning on a replayed/duplicate `open` event.
     *
     * @param string $campaignId UUID of the EvaluationCampaign.
     *
     * @return array<string, bool>
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
     */
    private function fetchExistingInvitedLearnerIds(string $campaignId): array
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::EVALUATION_INVITATION_SCHEMA,
                'filters'  => ['campaignId' => $campaignId],
            ]
        );

        $learnerIds = [];
        foreach ($existing as $invitation) {
            $data = $invitation;
            if (is_array($invitation) === false) {
                $data = $invitation->jsonSerialize();
            }

            $learnerId = $data['learnerId'] ?? null;
            if ($learnerId !== null) {
                $learnerIds[$learnerId] = true;
            }
        }

        return $learnerIds;

    }//end fetchExistingInvitedLearnerIds()
}//end class
