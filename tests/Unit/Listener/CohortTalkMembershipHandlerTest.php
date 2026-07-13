<?php

/**
 * Scholiq CohortTalkMembershipHandler unit tests.
 *
 * Covers the `talk-classroom-spaces` Enrolment activate/withdraw -> Cohort
 * Talk conversation participant sync bridge: a learner is added when their
 * Enrolment activates and a room is linked, removed when it withdraws, and
 * both paths no-op (no participant-service call attempted) when Talk is
 * unavailable or the Cohort has no linked conversation yet.
 *
 * Uses the `OCA\Talk\Manager`/`OCA\Talk\Room`/`OCA\Talk\Service\ParticipantService`
 * stubs under `tests/Stubs/Talk/` (mirroring hermiq's
 * `tests/Stubs/Talk/*` pattern) so the handler's DI-container-resolved Talk
 * calls can be mocked with plain PHPUnit `createMock()` against real class
 * shapes, instead of hand-rolled duck-typed fakes.
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
 * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#requirement-enrolled-learners-sync-as-talk-room-participants-on-cohort-membership-changes
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\TalkLinkService;
use OCA\Scholiq\Listener\CohortTalkMembershipHandler;
use OCA\Talk\Manager as TalkManager;
use OCA\Talk\Room as TalkRoom;
use OCA\Talk\Service\ParticipantService as TalkParticipantService;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CohortTalkMembershipHandler::handle().
 */
class CohortTalkMembershipHandlerTest extends TestCase
{
    /**
     * Build a container mock that resolves `OCA\Talk\Manager` /
     * `OCA\Talk\Service\ParticipantService` to the given mocks, throwing for
     * any other lookup (and for either when its mock is null — simulating
     * Talk's classes being entirely absent from the container).
     *
     * @param TalkManager|null            $manager            Resolved for the Manager FQCN.
     * @param TalkParticipantService|null $participantService Resolved for the ParticipantService FQCN.
     *
     * @return ContainerInterface
     */
    private function makeContainer(?TalkManager $manager, ?TalkParticipantService $participantService): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($manager, $participantService) {
                if ($id === 'OCA\\Talk\\Manager') {
                    if ($manager === null) {
                        throw new \RuntimeException('Talk Manager not available');
                    }

                    return $manager;
                }

                if ($id === 'OCA\\Talk\\Service\\ParticipantService') {
                    if ($participantService === null) {
                        throw new \RuntimeException('Talk ParticipantService not available');
                    }

                    return $participantService;
                }

                throw new \RuntimeException('Unexpected container lookup: '.$id);
            }
        );

        return $container;

    }//end makeContainer()

    /**
     * Build a mocked ObjectTransitionedEvent for an Enrolment transition.
     *
     * @param string              $action        Transition action ('activate'|'withdraw'|...).
     * @param array<string,mixed> $enrolmentData The Enrolment's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(string $action, array $enrolmentData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($enrolmentData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('enrolment');
        $event->method('getAction')->willReturn($action);

        return $event;

    }//end makeEvent()

    /**
     * Activating an Enrolment adds the learner as a participant of every
     * Talk conversation linked to the Cohort.
     *
     * @return void
     *
     * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-activating-an-enrolment-adds-the-learner-to-the-cohorts-linked-conversation
     */
    public function testActivateAddsParticipant(): void
    {
        $room = new TalkRoom();

        $manager = $this->createMock(TalkManager::class);
        $manager->method('getRoomByToken')->with('room-token-1')->willReturn($room);

        $participantService = $this->createMock(TalkParticipantService::class);
        $participantService->expects(self::once())
            ->method('addUsers')
            ->with($room, [['actorType' => 'users', 'actorId' => 'learner-1']]);
        $participantService->expects(self::never())->method('removeUser');

        $talkLinkService = $this->createMock(TalkLinkService::class);
        $talkLinkService->method('isTalkAvailable')->willReturn(true);
        $talkLinkService->method('getLinkedRooms')->with('cohort-1')->willReturn([['roomToken' => 'room-token-1']]);

        $handler = new CohortTalkMembershipHandler(
            $talkLinkService,
            $this->makeContainer($manager, $participantService),
            $this->createMock(IUserManager::class),
            $this->createMock(LoggerInterface::class)
        );

        $handler->handle($this->makeEvent('activate', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

    }//end testActivateAddsParticipant()

    /**
     * Withdrawing an Enrolment removes the learner as a participant of every
     * Talk conversation linked to the Cohort.
     *
     * @return void
     *
     * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-withdrawing-an-enrolment-removes-the-learner-from-the-cohorts-linked-conversation
     */
    public function testWithdrawRemovesParticipant(): void
    {
        $room = new TalkRoom();
        $user = $this->createMock(IUser::class);

        $manager = $this->createMock(TalkManager::class);
        $manager->method('getRoomByToken')->with('room-token-1')->willReturn($room);

        $participantService = $this->createMock(TalkParticipantService::class);
        $participantService->expects(self::once())
            ->method('removeUser')
            ->with($room, $user, 'leave');
        $participantService->expects(self::never())->method('addUsers');

        $talkLinkService = $this->createMock(TalkLinkService::class);
        $talkLinkService->method('isTalkAvailable')->willReturn(true);
        $talkLinkService->method('getLinkedRooms')->with('cohort-1')->willReturn([['roomToken' => 'room-token-1']]);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('learner-1')->willReturn($user);

        $handler = new CohortTalkMembershipHandler(
            $talkLinkService,
            $this->makeContainer($manager, $participantService),
            $userManager,
            $this->createMock(LoggerInterface::class)
        );

        $handler->handle($this->makeEvent('withdraw', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

    }//end testWithdrawRemovesParticipant()

    /**
     * A Cohort with no linked Talk conversation is a no-op — no
     * participant-sync call is attempted (the container is never even asked
     * to resolve Talk's Manager/ParticipantService).
     *
     * @return void
     *
     * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-no-conversation-linked-yet-is-a-no-op-not-an-error
     */
    public function testActivateWithNoLinkedRoomIsNoop(): void
    {
        $talkLinkService = $this->createMock(TalkLinkService::class);
        $talkLinkService->method('isTalkAvailable')->willReturn(true);
        $talkLinkService->method('getLinkedRooms')->willReturn([]);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');

        $handler = new CohortTalkMembershipHandler(
            $talkLinkService,
            $container,
            $this->createMock(IUserManager::class),
            $this->createMock(LoggerInterface::class)
        );

        $handler->handle($this->makeEvent('activate', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

    }//end testActivateWithNoLinkedRoomIsNoop()

    /**
     * Talk being unavailable is a no-op — no participant-sync call is
     * attempted, even when the Cohort has a (stale) linked conversation
     * record.
     *
     * @return void
     *
     * @spec openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-talk-unavailable-is-a-no-op-not-an-error
     */
    public function testActivateWithTalkUnavailableIsNoop(): void
    {
        $talkLinkService = $this->createMock(TalkLinkService::class);
        $talkLinkService->method('isTalkAvailable')->willReturn(false);
        // isTalkAvailable() is checked first — getLinkedRooms() must never be
        // reached, proving the fail-soft guard short-circuits before any
        // further lookup.
        $talkLinkService->expects(self::never())->method('getLinkedRooms');

        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');

        $handler = new CohortTalkMembershipHandler(
            $talkLinkService,
            $container,
            $this->createMock(IUserManager::class),
            $this->createMock(LoggerInterface::class)
        );

        $handler->handle($this->makeEvent('activate', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

    }//end testActivateWithTalkUnavailableIsNoop()

    /**
     * An Enrolment with no `cohortId` (individually enrolled learner) is a
     * fast no-op regardless of action.
     *
     * @return void
     */
    public function testActivateWithNoCohortIdIsNoop(): void
    {
        $talkLinkService = $this->createMock(TalkLinkService::class);
        $talkLinkService->expects(self::never())->method('isTalkAvailable');
        $talkLinkService->expects(self::never())->method('getLinkedRooms');

        $handler = new CohortTalkMembershipHandler(
            $talkLinkService,
            $this->createMock(ContainerInterface::class),
            $this->createMock(IUserManager::class),
            $this->createMock(LoggerInterface::class)
        );

        $handler->handle($this->makeEvent('activate', ['cohortId' => null, 'learnerId' => 'learner-1']));

    }//end testActivateWithNoCohortIdIsNoop()

    /**
     * A transition action other than activate/withdraw (e.g. `complete`) is
     * a fast no-op.
     *
     * @return void
     */
    public function testNonActivateWithdrawActionIsNoop(): void
    {
        $talkLinkService = $this->createMock(TalkLinkService::class);
        $talkLinkService->expects(self::never())->method('isTalkAvailable');

        $handler = new CohortTalkMembershipHandler(
            $talkLinkService,
            $this->createMock(ContainerInterface::class),
            $this->createMock(IUserManager::class),
            $this->createMock(LoggerInterface::class)
        );

        $handler->handle($this->makeEvent('complete', ['cohortId' => 'cohort-1', 'learnerId' => 'learner-1']));

    }//end testNonActivateWithdrawActionIsNoop()
}//end class
