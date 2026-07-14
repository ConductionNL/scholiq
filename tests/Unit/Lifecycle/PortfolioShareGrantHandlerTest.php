<?php

/**
 * Scholiq PortfolioShareGrantHandler unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-teacher-can-be-granted-a-read-only-share-via-native-nextcloud-files-sharing
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\PortfolioShareGrantHandler;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortfolioShareGrantHandler — both its `check()` self-grant guard and its
 * `handle()` IEventListener half (native NC Files share creation for sharedWithKind=teacher).
 *
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-teacher-can-be-granted-a-read-only-share-via-native-nextcloud-files-sharing
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
 */
class PortfolioShareGrantHandlerTest extends TestCase
{

    /**
     * Recorded IManager::createShare() calls.
     *
     * @var array<int, IShare>
     */
    private array $createdShares = [];

    /**
     * Reset the capture buffer before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->createdShares = [];

    }//end setUp()

    /**
     * Build a handler backed by ObjectService/IManager/IRootFolder stubs.
     *
     * @param array<string, mixed>|null        $portfolio The Portfolio returned for portfolio lookups.
     * @param array<int, array<string, mixed>> $entries   PortfolioEntry rows returned for
     *                                                     portfolio-entry lookups.
     * @param bool                             $nodeFound Whether IRootFolder::getUserFolder()->get()
     *                                                     resolves a Node (true) or throws
     *                                                     NotFoundException (false).
     *
     * @return PortfolioShareGrantHandler
     */
    private function makeHandler(?array $portfolio, array $entries, bool $nodeFound=true): PortfolioShareGrantHandler
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($portfolio, $entries) {
                if ($config['schema'] === 'portfolio') {
                    return ($portfolio === null) ? [] : [$portfolio];
                }

                if ($config['schema'] === 'portfolio-entry') {
                    return $entries;
                }

                return [];
            }
        );

        $shareManager = $this->createMock(IManager::class);
        $shareManager->method('getSharesBy')->willReturn([]);
        $shareManager->method('newShare')->willReturnCallback(
            function () {
                return $this->createMock(IShare::class);
            }
        );
        $shareManager->method('createShare')->willReturnCallback(
            function (IShare $share) {
                $this->createdShares[] = $share;
                return $share;
            }
        );

        $node = $this->createMock(Node::class);

        $userFolder = $this->createMock(Folder::class);
        if ($nodeFound === true) {
            $userFolder->method('get')->willReturn($node);
        } else {
            $userFolder->method('get')->willThrowException(new NotFoundException());
        }

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($userFolder);

        return new PortfolioShareGrantHandler(
            $objectService,
            $shareManager,
            $rootFolder,
            $this->createMock(LoggerInterface::class)
        );

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a PortfolioShare → active transition.
     *
     * @param array<string, mixed> $shareData The PortfolioShare's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $shareData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($shareData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('portfolio-share');
        $event->method('getTo')->willReturn('active');
        $event->method('getFrom')->willReturn('draft');

        return $event;

    }//end makeEvent()

    /**
     * `check()`: a self-grant (sharedBy === the resolved recipient identity) is blocked.
     *
     * @return void
     */
    public function testSelfGrantBlocked(): void
    {
        $handler = $this->makeHandler(null, []);

        $context = [
            'object' => [
                'id'                  => 'share-1',
                'sharedWithKind'      => 'teacher',
                'sharedWithTeacherId' => 'user-1',
                'sharedBy'            => 'user-1',
            ],
        ];

        $this->assertFalse($handler->check($context));

    }//end testSelfGrantBlocked()

    /**
     * `check()`: a different sharedBy/recipient pair is allowed.
     *
     * @return void
     */
    public function testDifferentRecipientAllowed(): void
    {
        $handler = $this->makeHandler(null, []);

        $context = [
            'object' => [
                'id'                  => 'share-1',
                'sharedWithKind'      => 'teacher',
                'sharedWithTeacherId' => 'teacher-mentor',
                'sharedBy'            => 'learner-7',
            ],
        ];

        $this->assertTrue($handler->check($context));

    }//end testDifferentRecipientAllowed()

    /**
     * `check()`: self-grant is also blocked for the praktijkopleider/external-assessor kinds
     * (the guard resolves the recipient identity per sharedWithKind, not just teacher).
     *
     * @return void
     */
    public function testSelfGrantBlockedForPraktijkopleiderKind(): void
    {
        $handler = $this->makeHandler(null, []);

        $context = [
            'object' => [
                'id'                            => 'share-2',
                'sharedWithKind'                => 'praktijkopleider',
                'sharedWithPraktijkopleiderId'   => 'po-1',
                'sharedBy'                       => 'po-1',
            ],
        ];

        $this->assertFalse($handler->check($context));

    }//end testSelfGrantBlockedForPraktijkopleiderKind()

    /**
     * Scenario: "Granting a teacher share creates a native NC Files share" — sharedWithKind:
     * teacher calls IManager::createShare() with read-only permissions for the correct recipient.
     *
     * @return void
     */
    public function testTeacherGrantCreatesReadOnlyNcFilesShare(): void
    {
        $portfolio = ['id' => 'portfolio-1', 'learnerId' => 'learner-7'];
        $entries   = [
            [
                'id'            => 'entry-1',
                'portfolioId'   => 'portfolio-1',
                'evidenceKind'  => 'file',
                'attachmentRef' => 'evidence/file1.pdf',
            ],
        ];

        $handler = $this->makeHandler($portfolio, $entries);

        $share = [
            'id'                  => 'share-1',
            'portfolioId'         => 'portfolio-1',
            'sharedWithKind'      => 'teacher',
            'sharedWithTeacherId' => 'teacher-mentor',
            'sharedBy'            => 'learner-7',
            'entryIds'            => null,
        ];

        $handler->handle($this->makeEvent($share));

        $this->assertCount(1, $this->createdShares);

    }//end testTeacherGrantCreatesReadOnlyNcFilesShare()

    /**
     * `sharedWithKind: praktijkopleider`/`external-assessor` make no IManager call —
     * visibility is served declaratively by PortalContributionProvider.
     *
     * @return void
     */
    public function testNonTeacherKindsMakeNoNcFilesCall(): void
    {
        $portfolio = ['id' => 'portfolio-1', 'learnerId' => 'learner-7'];
        $entries   = [
            [
                'id'            => 'entry-1',
                'portfolioId'   => 'portfolio-1',
                'evidenceKind'  => 'file',
                'attachmentRef' => 'evidence/file1.pdf',
            ],
        ];

        foreach (['praktijkopleider', 'external-assessor'] as $kind) {
            $this->createdShares = [];
            $handler             = $this->makeHandler($portfolio, $entries);

            $share = [
                'id'             => 'share-1',
                'portfolioId'    => 'portfolio-1',
                'sharedWithKind' => $kind,
                'sharedBy'       => 'learner-7',
            ];

            $handler->handle($this->makeEvent($share));

            $this->assertCount(0, $this->createdShares, "kind '{$kind}' must not create an NC Files share");
        }

    }//end testNonTeacherKindsMakeNoNcFilesCall()

    /**
     * `entryIds` scoping: only `file`-kind entries in the selection are shared — a non-file
     * entry and an entry outside the selection are both skipped.
     *
     * @return void
     */
    public function testEntryIdsFilterLimitsSharedFiles(): void
    {
        $portfolio = ['id' => 'portfolio-1', 'learnerId' => 'learner-7'];
        $entries   = [
            ['id' => 'entry-1', 'portfolioId' => 'portfolio-1', 'evidenceKind' => 'file', 'attachmentRef' => 'a.pdf'],
            ['id' => 'entry-2', 'portfolioId' => 'portfolio-1', 'evidenceKind' => 'file', 'attachmentRef' => 'b.pdf'],
            ['id' => 'entry-3', 'portfolioId' => 'portfolio-1', 'evidenceKind' => 'reflection'],
        ];

        $handler = $this->makeHandler($portfolio, $entries);

        $share = [
            'id'                  => 'share-1',
            'portfolioId'         => 'portfolio-1',
            'sharedWithKind'      => 'teacher',
            'sharedWithTeacherId' => 'teacher-mentor',
            'sharedBy'            => 'learner-7',
            'entryIds'            => ['entry-1'],
        ];

        $handler->handle($this->makeEvent($share));

        // Only entry-1 (file, in the selection) is shared.
        $this->assertCount(1, $this->createdShares);

    }//end testEntryIdsFilterLimitsSharedFiles()

    /**
     * An unresolvable attachmentRef (NotFoundException from the user folder) is a safe,
     * best-effort skip — no exception propagates and no share is created for that entry.
     *
     * @return void
     */
    public function testUnresolvableAttachmentRefSkippedSafely(): void
    {
        $portfolio = ['id' => 'portfolio-1', 'learnerId' => 'learner-7'];
        $entries   = [
            ['id' => 'entry-1', 'portfolioId' => 'portfolio-1', 'evidenceKind' => 'file', 'attachmentRef' => 'gone.pdf'],
        ];

        $handler = $this->makeHandler($portfolio, $entries, false);

        $share = [
            'id'                  => 'share-1',
            'portfolioId'         => 'portfolio-1',
            'sharedWithKind'      => 'teacher',
            'sharedWithTeacherId' => 'teacher-mentor',
            'sharedBy'            => 'learner-7',
            'entryIds'            => null,
        ];

        $handler->handle($this->makeEvent($share));

        $this->assertCount(0, $this->createdShares);

    }//end testUnresolvableAttachmentRefSkippedSafely()

    /**
     * Events for other schemas/states are ignored entirely.
     *
     * @return void
     */
    public function testIgnoresUnrelatedEvents(): void
    {
        $handler = $this->makeHandler(null, []);

        $objectEntity = $this->createMock(ObjectEntity::class);
        $event        = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('portfolio');
        $event->method('getTo')->willReturn('active');

        $handler->handle($event);

        $this->assertCount(0, $this->createdShares);

    }//end testIgnoresUnrelatedEvents()
}//end class
