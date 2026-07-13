<?php

/**
 * Scholiq Report Card Finalise Guard
 *
 * Lifecycle guard for the ReportCard schema's `finalise` transition
 * (rapportvergadering-review -> finalised). Blocks finalisation until the
 * mentor has recorded an overall comment and the card carries at least one
 * subject-grade row — a report card with no comment and no subjects reaching
 * `finalised` would be a meaningless, empty document handed to parents.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Referenced from the ReportCard schema's
 * x-openregister-lifecycle.transitions.finalise.requires in
 * scholiq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-finalise-is-blocked-without-a-mentor-comment
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Guards the ReportCard `finalise` (rapportvergadering-review -> finalised)
 * lifecycle transition.
 *
 * Allows the transition only when `mentorComment` is a non-empty string and
 * `subjectGrades` is a non-empty array.
 *
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-the-rapportvergadering-review-lifecycle-gates-parent-visibility-behind-a-finalise-step
 */
class ReportCardFinaliseGuard
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the ReportCard data array
     *                                               - 'transition' : 'finalise'
     *                                               - 'from'       : 'rapportvergadering-review'
     *                                               - 'to'         : 'finalised'
     *
     * @return bool True when the card carries a mentor comment and at least one subject grade; false blocks it.
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-finalise-is-blocked-without-a-mentor-comment
     */
    public function check(array &$transitionContext): bool
    {
        $object   = $transitionContext['object'] ?? [];
        $objectId = $object['id'] ?? ($object['uuid'] ?? '');
        $comment  = $object['mentorComment'] ?? null;
        $subjects = $object['subjectGrades'] ?? [];

        if (is_string($comment) === false || trim($comment) === '') {
            $this->logger->info(
                '[ReportCardFinaliseGuard] ReportCard {id} has no mentorComment — denying finalise.',
                ['id' => $objectId]
            );
            return false;
        }

        if (is_array($subjects) === false || empty($subjects) === true) {
            $this->logger->info(
                '[ReportCardFinaliseGuard] ReportCard {id} has no subjectGrades — denying finalise.',
                ['id' => $objectId]
            );
            return false;
        }

        return true;

    }//end check()
}//end class
