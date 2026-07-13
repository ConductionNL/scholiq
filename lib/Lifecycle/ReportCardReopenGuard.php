<?php

/**
 * Scholiq Report Card Reopen Guard
 *
 * Lifecycle guard for the ReportCard schema's `reopen` transition
 * (finalised -> rapportvergadering-review). Restricted to admin/mentor/
 * principal — an explicit correction path before parent publication, never
 * a self-service action for a subject teacher.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Mirrors {@see ExternalTrainingVerificationGuard}'s
 * role-group-check shape. Referenced from the ReportCard schema's
 * x-openregister-lifecycle.transitions.reopen.requires in
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the ReportCard `reopen` (finalised -> rapportvergadering-review)
 * lifecycle transition.
 *
 * Allows the transition only when the acting user is in one of the
 * privileged groups (`admin`, `mentor`, `principal`).
 *
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-the-rapportvergadering-review-lifecycle-gates-parent-visibility-behind-a-finalise-step
 */
class ReportCardReopenGuard
{

    /**
     * Groups whose members may reopen a finalised report card.
     *
     * @var string[]
     */
    private const REOPEN_GROUPS = ['admin', 'mentor', 'principal'];

    /**
     * Constructor.
     *
     * @param IGroupManager   $groupManager OR/NC group manager to resolve the acting user's role groups.
     * @param IUserManager    $userManager  User manager to resolve the acting user object for membership checks.
     * @param LoggerInterface $logger       PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the ReportCard data array
     *                                               - 'transition' : 'reopen'
     *                                               - 'actor'      : NC user ID of the requester
     *
     * @return bool True when the actor holds admin/mentor/principal; false blocks it.
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
     */
    public function check(array &$transitionContext): bool
    {
        $object = $transitionContext['object'] ?? [];
        $actor  = (string) ($transitionContext['actor'] ?? '');

        if ($actor === '') {
            $this->logger->warning('[ReportCardReopenGuard] No actor in transitionContext — denying reopen.');
            return false;
        }

        $user = $this->userManager->get($actor);
        if ($user === null) {
            $this->logger->info(
                '[ReportCardReopenGuard] Actor {actor} could not be resolved — denying reopen.',
                ['actor' => $actor]
            );
            return false;
        }

        $actorGroups = $this->groupManager->getUserGroupIds($user);

        if (count(array_intersect($actorGroups, self::REOPEN_GROUPS)) === 0) {
            $this->logger->info(
                '[ReportCardReopenGuard] ReportCard {id} reopen denied — actor {actor} holds no admin/mentor/principal role.',
                ['id' => ($object['id'] ?? ($object['uuid'] ?? '')), 'actor' => $actor]
            );
            return false;
        }

        return true;

    }//end check()
}//end class
