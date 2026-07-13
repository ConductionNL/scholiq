<?php

/**
 * Scholiq Cohort Talk Membership Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and keeps a Cohort's
 * linked Nextcloud Talk conversation(s) participant list in sync with its
 * `Enrolment` roster:
 *
 * 1. Enrolment `activate` (pending → active): adds `Enrolment.learnerId` as a
 *    Talk participant of every conversation currently linked to the
 *    Enrolment's Cohort.
 * 2. Enrolment `withdraw` (pending|active → withdrawn): removes that learner
 *    from every conversation currently linked to the Cohort.
 *
 * Talk (spreed) and OpenRegister's Talk-linking abstraction
 * (`OCA\OpenRegister\Service\TalkLinkService`) are both consumed unchanged —
 * this class adds no Talk client of its own. It fails soft in every
 * degraded case (Talk not installed/enabled, Cohort has no linked
 * conversation yet, Talk room lookup fails): logs and returns, never throws,
 * never blocks the Enrolment transition itself (this listener runs after
 * the transition has already been committed by OR's lifecycle engine).
 *
 * ADR-031 legitimate exception: event-to-external-API bridge with
 * cross-object lookup (Enrolment → Cohort → linked Talk room) — not
 * expressible as a schema declaration. Same category as
 * `GradeRollupHandler`/`BpvLeerbedrijfVerificationHandler`.
 *
 * Known limitation (documented, not a bug): learners whose Enrolment was
 * already `active` before a conversation was linked to their Cohort are NOT
 * retroactively added — OR's `TalkLinksController`/`TalkLinkService` fire no
 * "room linked" event to hook into. The coordinator adds that initial batch
 * once via Talk's own participant UI; every Enrolment change after that
 * point stays in sync automatically. See
 * openspec/changes/talk-classroom-spaces/design.md Decision 3.
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
 * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#requirement-enrolled-learners-sync-as-talk-room-participants-on-cohort-membership-changes
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\TalkLinkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bridges Enrolment activate/withdraw → Talk conversation participant sync.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#requirement-enrolled-learners-sync-as-talk-room-participants-on-cohort-membership-changes
 */
class CohortTalkMembershipHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER         = 'scholiq';
    private const ENROLMENT_SCHEMA         = 'enrolment';
    private const ACTION_ACTIVATE          = 'activate';
    private const ACTION_WITHDRAW          = 'withdraw';
    private const TALK_MANAGER_FQCN        = 'OCA\\Talk\\Manager';
    private const PARTICIPANT_SERVICE_FQCN = 'OCA\\Talk\\Service\\ParticipantService';
    // Mirrors OCA\Talk\Events\AAttendeeRemovedEvent::REASON_LEFT — not
    // imported directly since Talk may not be installed at all (loose,
    // defensive coupling matches TalkLinkService's own style).
    private const REMOVE_REASON_LEFT = 'leave';

    /**
     * Constructor.
     *
     * @param TalkLinkService    $talkLinkService OpenRegister's Talk-linking abstraction
     *                                            (cross-app, same injection pattern
     *                                            scholiq already uses for
     *                                            `ObjectService` elsewhere).
     * @param ContainerInterface $container       DI container used to late-bind Talk's
     *                                            `Manager`/`ParticipantService`
     *                                            (mirrors
     *                                            `BpvLeerbedrijfVerificationHandler`'s
     *                                            container-lookup pattern; Talk
     *                                            classes only exist in the container
     *                                            when `spreed` is installed).
     * @param IUserManager       $userManager     NC user manager, resolving `Enrolment.learnerId`
     *                                            to an `IUser` for
     *                                            `ParticipantService::removeUser()`. Always
     *                                            available (core NC service, not Talk-gated).
     * @param LoggerInterface    $logger          PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly TalkLinkService $talkLinkService,
        private readonly ContainerInterface $container,
        private readonly IUserManager $userManager,
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
     * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-activating-an-enrolment-adds-the-learner-to-the-cohorts-linked-conversation
     * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-withdrawing-an-enrolment-removes-the-learner-from-the-cohorts-linked-conversation
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER
            || $event->getSchema() !== self::ENROLMENT_SCHEMA
        ) {
            return;
        }

        $action = $event->getAction();
        if ($action !== self::ACTION_ACTIVATE && $action !== self::ACTION_WITHDRAW) {
            return;
        }

        $enrolment = $event->getObject()->jsonSerialize();
        $cohortId  = $enrolment['cohortId'] ?? null;
        $learnerId = $enrolment['learnerId'] ?? '';

        if (is_string($cohortId) === false || $cohortId === '' || $learnerId === '') {
            // No cohort association (individually enrolled learner) or a
            // malformed event payload — nothing to sync.
            return;
        }

        $this->syncParticipant(
            cohortId: $cohortId,
            learnerId: $learnerId,
            add: $action === self::ACTION_ACTIVATE
        );

    }//end handle()

    /**
     * Add or remove `$learnerId` as a participant of every Talk conversation
     * currently linked to the Cohort `$cohortId`. Fails soft at every step.
     *
     * @param string $cohortId  Cohort object uuid (== Enrolment.cohortId).
     * @param string $learnerId Nextcloud user id of the learner.
     * @param bool   $add       True to add (activate), false to remove (withdraw).
     *
     * @return void
     *
     * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-no-conversation-linked-yet-is-a-no-op-not-an-error
     * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-talk-unavailable-is-a-no-op-not-an-error
     */
    private function syncParticipant(string $cohortId, string $learnerId, bool $add): void
    {
        if ($this->talkLinkService->isTalkAvailable() === false) {
            $this->logger->info(
                '[CohortTalkMembershipHandler] Talk (spreed) is not installed/enabled — skipping '
                .'participant sync for learner {learner} on Cohort {cohort}.',
                ['learner' => $learnerId, 'cohort' => $cohortId]
            );
            return;
        }

        $links = $this->talkLinkService->getLinkedRooms($cohortId);
        if (empty($links) === true) {
            $this->logger->info(
                '[CohortTalkMembershipHandler] Cohort {cohort} has no linked Talk conversation yet — '
                .'skipping participant sync for learner {learner}.',
                ['cohort' => $cohortId, 'learner' => $learnerId]
            );
            return;
        }

        $manager = $this->resolveManager();
        if ($manager === null) {
            $this->logger->info(
                '[CohortTalkMembershipHandler] Talk Manager could not be resolved — skipping '
                .'participant sync for learner {learner} on Cohort {cohort}.',
                ['learner' => $learnerId, 'cohort' => $cohortId]
            );
            return;
        }

        foreach ($links as $link) {
            $roomToken = $link['roomToken'] ?? '';
            if (is_string($roomToken) === false || $roomToken === '') {
                continue;
            }

            $room = $this->findRoomByToken(manager: $manager, roomToken: $roomToken);
            if ($room === null) {
                continue;
            }

            if ($add === true) {
                $this->addParticipant(room: $room, learnerId: $learnerId, cohortId: $cohortId);
            } else {
                $this->removeParticipant(room: $room, learnerId: $learnerId, cohortId: $cohortId);
            }
        }//end foreach

    }//end syncParticipant()

    /**
     * Add `$learnerId` as a Talk participant of `$room`.
     *
     * @param object $room      Talk Room entity.
     * @param string $learnerId Nextcloud user id.
     * @param string $cohortId  Cohort uuid (for log context only).
     *
     * @return void
     */
    private function addParticipant(object $room, string $learnerId, string $cohortId): void
    {
        $participantService = $this->resolveParticipantService();
        if ($participantService === null || method_exists($participantService, 'addUsers') === false) {
            return;
        }

        try {
            $participantService->addUsers($room, [['actorType' => 'users', 'actorId' => $learnerId]]);
        } catch (Throwable $e) {
            $this->logger->warning(
                '[CohortTalkMembershipHandler] Failed to add learner {learner} to Cohort {cohort}\'s '
                .'linked Talk conversation: {msg}',
                ['learner' => $learnerId, 'cohort' => $cohortId, 'msg' => $e->getMessage()]
            );
        }

    }//end addParticipant()

    /**
     * Remove `$learnerId` as a Talk participant of `$room`.
     *
     * `ParticipantService::removeUser()` already no-ops internally when the
     * user is not currently a participant of the room (catches Talk's own
     * `ParticipantNotFoundException`), which matches the fail-soft contract
     * for a learner who was never added (e.g. Cohort had no room linked when
     * they activated).
     *
     * @param object $room      Talk Room entity.
     * @param string $learnerId Nextcloud user id.
     * @param string $cohortId  Cohort uuid (for log context only).
     *
     * @return void
     */
    private function removeParticipant(object $room, string $learnerId, string $cohortId): void
    {
        $participantService = $this->resolveParticipantService();
        if ($participantService === null || method_exists($participantService, 'removeUser') === false) {
            return;
        }

        $user = $this->resolveUser(userId: $learnerId);
        if ($user === null) {
            return;
        }

        try {
            $participantService->removeUser($room, $user, self::REMOVE_REASON_LEFT);
        } catch (Throwable $e) {
            $this->logger->warning(
                '[CohortTalkMembershipHandler] Failed to remove learner {learner} from Cohort {cohort}\'s '
                .'linked Talk conversation: {msg}',
                ['learner' => $learnerId, 'cohort' => $cohortId, 'msg' => $e->getMessage()]
            );
        }

    }//end removeParticipant()

    /**
     * Resolve Talk's `Manager` from the server container.
     *
     * No `class_exists()` pre-check (unlike `TalkLinkService`'s private
     * equivalent) — the container's `get()` call is trusted to throw a
     * catchable exception (PSR-11 `NotFoundExceptionInterface`) when Talk
     * is not installed, matching `BpvLeerbedrijfVerificationHandler::resolveProvider()`'s
     * plain try/catch container-lookup pattern. Return type stays the loose
     * `object` (not `\OCA\Talk\Manager`) so this class never hard-references
     * a class that may not exist on the running instance — mirrors hermiq's
     * `DeliveryService::talkManager()`.
     *
     * @return \OCA\Talk\Manager|object|null Returns null when Talk is unavailable or unresolvable.
     */
    private function resolveManager(): ?object
    {
        try {
            return $this->container->get(self::TALK_MANAGER_FQCN);
        } catch (Throwable $e) {
            $this->logger->debug('[CohortTalkMembershipHandler] Talk Manager not resolvable: '.$e->getMessage());
            return null;
        }

    }//end resolveManager()

    /**
     * Resolve Talk's `ParticipantService` from the server container.
     *
     * @return object|null Returns null when Talk is unavailable or unresolvable.
     */
    private function resolveParticipantService(): ?object
    {
        try {
            return $this->container->get(self::PARTICIPANT_SERVICE_FQCN);
        } catch (Throwable $e) {
            $this->logger->debug(
                '[CohortTalkMembershipHandler] Talk ParticipantService not resolvable: '.$e->getMessage()
            );
            return null;
        }

    }//end resolveParticipantService()

    /**
     * Look up a Talk Room by its token via the Manager. No user-scoping is
     * applied — this is a server-side membership sync, not a request made
     * on behalf of a logged-in session.
     *
     * @param object $manager   Talk Manager.
     * @param string $roomToken Talk room token.
     *
     * @return object|null Talk Room or null when not found / unresolvable.
     */
    private function findRoomByToken(object $manager, string $roomToken): ?object
    {
        if (method_exists($manager, 'getRoomByToken') === false) {
            return null;
        }

        try {
            return $manager->getRoomByToken($roomToken);
        } catch (Throwable $e) {
            $this->logger->debug(
                '[CohortTalkMembershipHandler] Talk room {token} not found: {msg}',
                ['token' => $roomToken, 'msg' => $e->getMessage()]
            );
            return null;
        }

    }//end findRoomByToken()

    /**
     * Resolve an `IUser` by Nextcloud user id.
     *
     * @param string $userId Nextcloud user id.
     *
     * @return object|null The `IUser`, or null when not found.
     */
    private function resolveUser(string $userId): ?object
    {
        try {
            return $this->userManager->get($userId);
        } catch (Throwable $e) {
            $this->logger->debug(
                '[CohortTalkMembershipHandler] Failed to resolve IUser for {userId}: {msg}',
                ['userId' => $userId, 'msg' => $e->getMessage()]
            );
            return null;
        }

    }//end resolveUser()
}//end class
