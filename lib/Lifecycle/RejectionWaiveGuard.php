<?php

/**
 * Scholiq Rejection Waive Guard
 *
 * Lifecycle guard for the ExchangeRejection schema's `waive` transition
 * (`open|corrected` → `waived`). Mirrors PupilVoiceGuard's mandatory-reason
 * enforcement and MunicipalityFeedbackGuard's role-check + server-side-stamp
 * shape: requires a non-empty `waiveReason` and stamps `waivedBy`/`waivedAt`
 * server-side, never trusting a caller-supplied identity/timestamp for this
 * compliance-sensitive field.
 *
 * ADR-031 legitimate exception: this register has no declarative
 * field-scoped write-authorization extension, and no declarative mechanism
 * to express "block this transition unless a companion payload field is a
 * non-empty string" — a PHP guard is the only proven mechanism (same
 * rationale as PupilVoiceGuard's hoorrecht gate).
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
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the ExchangeRejection `open|corrected → waived` transition.
 *
 * The transition proceeds only when BOTH of the following hold:
 *   1. The acting user is in one of the authorised groups (`admin`, `coordinator`).
 *   2. `transitionContext['payload']['waiveReason']` is a non-empty string.
 *
 * On success it stamps `waivedBy` (always the acting user, never a
 * caller-supplied value) and `waivedAt` (server clock) into the transition
 * payload.
 *
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.4
 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-waiving-without-a-reason-is-refused
 */
class RejectionWaiveGuard
{

    /**
     * Groups whose members may waive a rejection.
     *
     * @var string[]
     */
    private const AUTHORISED_GROUPS = [
        'admin',
        'coordinator',
    ];

    /**
     * Constructor.
     *
     * @param IGroupManager   $groupManager NC group manager to resolve the acting user's role groups.
     * @param IUserManager    $userManager  User manager to resolve the acting user object for membership checks.
     * @param LoggerInterface $logger       PSR logger for guard rejections.
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
     * Assert the waive preconditions and stamp waivedBy/waivedAt.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `open|corrected → waived` waive transition. Returns true to allow the
     * transition (and writes `waivedBy`/`waivedAt` into the payload), false to
     * block it.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine. Expected
     *                                               keys:
     *                                               - 'object'  : the
     *                                               ExchangeRejection data array
     *                                               - 'actor'   : NC user ID of
     *                                               the requester
     *                                               - 'payload' : mutable array;
     *                                               waiveReason is read from
     *                                               here, waivedBy/waivedAt are
     *                                               written here
     *
     * @return bool True when the transition is allowed; false blocks it.
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.4
     */
    public function check(array &$transitionContext): bool
    {
        $rejection   = $transitionContext['object'] ?? [];
        $rejectionId = $rejection['id'] ?? ($rejection['uuid'] ?? '?');
        $actor       = (string) ($transitionContext['actor'] ?? '');

        if ($actor === '') {
            $this->logger->warning(
                '[RejectionWaiveGuard] No actor in transitionContext — denying waive of {id}.',
                ['id' => $rejectionId]
            );
            return false;
        }

        if ($this->actorIsAuthorised(actor: $actor) === false) {
            $this->logger->info(
                '[RejectionWaiveGuard] Actor {a} is not in an authorised group — denying waive of {id}.',
                ['a' => $actor, 'id' => $rejectionId]
            );
            return false;
        }

        $payload = $transitionContext['payload'] ?? [];
        if (is_array($payload) === false) {
            $payload = [];
        }

        $waiveReason = $payload['waiveReason'] ?? null;

        if (is_string($waiveReason) === false || trim($waiveReason) === '') {
            $this->logger->info(
                '[RejectionWaiveGuard] ExchangeRejection {id}: waiveReason is empty — denying waive.',
                ['id' => $rejectionId]
            );
            return false;
        }

        // Stamp waivedBy/waivedAt server-side — never trust a caller-supplied
        // identity/timestamp for this compliance-sensitive field (mirrors
        // MunicipalityFeedbackGuard's recordedBy/receivedAt stamping).
        $payload['waivedBy'] = $actor;
        $payload['waivedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $transitionContext['payload'] = $payload;

        return true;

    }//end check()

    /**
     * Whether the acting user is in one of the authorised groups.
     *
     * @param string $actor NC user ID of the requester.
     *
     * @return bool True when the user is in admin / coordinator.
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.4
     */
    private function actorIsAuthorised(string $actor): bool
    {
        $user = $this->userManager->get($actor);
        if ($user === null) {
            return false;
        }

        $actorGroups = $this->groupManager->getUserGroupIds($user);

        return count(array_intersect($actorGroups, self::AUTHORISED_GROUPS)) > 0;

    }//end actorIsAuthorised()
}//end class
