<?php

/**
 * Scholiq Portfolio Share Grant Handler
 *
 * Dual-role class for the PortfolioShare schema's `grant` transition
 * (draft → active):
 *
 * 1. Lifecycle guard (`check()`, referenced from the `grant` transition's
 *    `requires:` in scholiq_register.json): blocks the transition when
 *    `sharedBy` equals the resolved recipient identity for the share's
 *    `sharedWithKind` — a recipient must never be able to grant themselves
 *    access. `x-property-rbac`/`x-openregister-authorization` cannot express
 *    this cross-object "compare two fields on the same object" check (this
 *    codebase's RBAC `match` operator is `eq`-only against a fixed `$userId`,
 *    never a second property), so it is a PHP guard per ADR-031.
 * 2. IEventListener (`handle()`, registered against
 *    OCA\OpenRegister\Event\ObjectTransitionedEvent in
 *    OCA\Scholiq\AppInfo\Application, mirroring
 *    WerkprocesGradeEmitHandler's event-listener shape): on
 *    PortfolioShare.active with `sharedWithKind: teacher`, resolves the NC
 *    file paths behind the shared portfolio's (or selected entries')
 *    `attachmentRef`s and calls `OCP\Share\IManager::createShare()` for a
 *    read-only Nextcloud Files share targeting `sharedWithTeacherId`. This
 *    reuses Nextcloud's own file-sharing mechanism — it does not duplicate
 *    file bytes or build a parallel access-control layer. For
 *    `sharedWithKind: praktijkopleider`/`external-assessor`, no NC Files
 *    call is made — visibility is served declaratively by
 *    `PortalContributionProvider` (portal-contribution's existing
 *    mechanism).
 *
 * ADR-031 legitimate exception: "Lifecycle guard — business rule that must
 * run before a state transition" (the self-grant check) AND "event-to-
 * object-write bridge" (the NC Files share creation) — both cannot be
 * expressed as schema declarations.
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
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-teacher-can-be-granted-a-read-only-share-via-native-nextcloud-files-sharing
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Constants;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Guards + fulfils the PortfolioShare `grant` transition.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-teacher-can-be-granted-a-read-only-share-via-native-nextcloud-files-sharing
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
 */
class PortfolioShareGrantHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const SHARE_SCHEMA     = 'portfolio-share';
    private const PORTFOLIO_SCHEMA = 'portfolio';
    private const ENTRY_SCHEMA     = 'portfolio-entry';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param IManager        $shareManager  NC share manager for the teacher-kind NC Files share.
     * @param IRootFolder     $rootFolder    NC root folder for resolving attachmentRef paths to Nodes.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IManager $shareManager,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point — blocks self-grant.
     *
     * Called by OpenRegister's lifecycle engine before executing the `grant`
     * transition on a PortfolioShare object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the PortfolioShare data array
     *                                               - 'transition' : 'grant'
     *                                               - 'from'       : 'draft'
     *                                               - 'to'         : 'active'
     *
     * @return bool True to allow the transition; false blocks it (HTTP 422 from OR engine).
     *
     * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-teacher-can-be-granted-a-read-only-share-via-native-nextcloud-files-sharing
     */
    public function check(array &$transitionContext): bool
    {
        $share     = $transitionContext['object'] ?? [];
        $shareId   = $share['id'] ?? ($share['uuid'] ?? '');
        $sharedBy  = $share['sharedBy'] ?? '';
        $recipient = $this->resolveRecipientIdentity(share: $share);

        if ($recipient !== null && $recipient !== '' && $sharedBy !== '' && $sharedBy === $recipient) {
            $this->logger->info(
                '[PortfolioShareGrantHandler] PortfolioShare {id} names the same identity ({who}) as both '
                .'sharedBy and the recipient; blocking grant.',
                ['id' => $shareId, 'who' => $sharedBy]
            );
            return false;
        }

        return true;

    }//end check()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-teacher-can-be-granted-a-read-only-share-via-native-nextcloud-files-sharing
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::SHARE_SCHEMA) {
            return;
        }

        if ($event->getTo() !== 'active') {
            return;
        }

        $share = $event->getObject()->jsonSerialize();

        if (($share['sharedWithKind'] ?? '') !== 'teacher') {
            // Praktijkopleider/external-assessor visibility is served declaratively
            // by PortalContributionProvider — no NC Files action here.
            return;
        }

        $this->createTeacherFileShare(share: $share);

    }//end handle()

    /**
     * Resolve the recipient identity string for a PortfolioShare's sharedWithKind.
     *
     * @param array<string,mixed> $share The PortfolioShare data.
     *
     * @return string|null The recipient identity (an NC uid for teacher; a domain-object UUID for
     *                      praktijkopleider/external-assessor), or null when unresolved.
     *
     * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-teacher-can-be-granted-a-read-only-share-via-native-nextcloud-files-sharing
     */
    private function resolveRecipientIdentity(array $share): ?string
    {
        $kind = $share['sharedWithKind'] ?? '';

        if ($kind === 'teacher') {
            return $share['sharedWithTeacherId'] ?? null;
        }

        if ($kind === 'praktijkopleider') {
            return $share['sharedWithPraktijkopleiderId'] ?? null;
        }

        if ($kind === 'external-assessor') {
            return $share['sharedWithExternalAssessorId'] ?? null;
        }

        return null;

    }//end resolveRecipientIdentity()

    /**
     * Create a read-only NC Files share of the referenced attachments for the teacher recipient.
     *
     * @param array<string,mixed> $share The active PortfolioShare data.
     *
     * @return void
     *
     * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#scenario-granting-a-teacher-share-creates-a-native-nc-files-share
     */
    private function createTeacherFileShare(array $share): void
    {
        $shareId       = $share['id'] ?? ($share['uuid'] ?? '');
        $portfolioId   = $share['portfolioId'] ?? '';
        $teacherId     = $share['sharedWithTeacherId'] ?? '';
        $entryIdFilter = $share['entryIds'] ?? null;

        if ($portfolioId === '' || $teacherId === '') {
            $this->logger->warning(
                '[PortfolioShareGrantHandler] PortfolioShare {id} missing portfolioId/sharedWithTeacherId — '
                .'cannot create NC Files share.',
                ['id' => $shareId]
            );
            return;
        }

        $portfolio = $this->loadObject(schema: self::PORTFOLIO_SCHEMA, id: $portfolioId);
        if ($portfolio === null) {
            $this->logger->warning(
                '[PortfolioShareGrantHandler] PortfolioShare {id} references Portfolio {pid} which could not '
                .'be resolved — cannot create NC Files share.',
                ['id' => $shareId, 'pid' => $portfolioId]
            );
            return;
        }

        $ownerId = $portfolio['learnerId'] ?? '';
        if ($ownerId === '') {
            return;
        }

        $entries = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENTRY_SCHEMA,
                'filters'  => ['portfolioId' => $portfolioId],
            ]
        );

        $shared = 0;
        foreach ($entries as $entry) {
            $entryData = $entry;
            if (is_array($entry) === false) {
                $entryData = $entry->jsonSerialize();
            }

            if (($entryData['evidenceKind'] ?? '') !== 'file') {
                continue;
            }

            $entryId = $entryData['id'] ?? ($entryData['uuid'] ?? '');
            if (is_array($entryIdFilter) === true
                && empty($entryIdFilter) === false
                && in_array($entryId, $entryIdFilter, true) === false
            ) {
                continue;
            }

            $attachmentRef = $entryData['attachmentRef'] ?? '';
            if ($attachmentRef === '') {
                continue;
            }

            if ($this->shareAttachment(ownerId: $ownerId, teacherId: $teacherId, attachmentRef: $attachmentRef) === true) {
                $shared++;
            }
        }//end foreach

        $this->logger->info(
            '[PortfolioShareGrantHandler] PortfolioShare {id} granted — {n} file(s) shared with teacher {t}.',
            ['id' => $shareId, 'n' => $shared, 't' => $teacherId]
        );

    }//end createTeacherFileShare()

    /**
     * Resolve one attachmentRef to an NC Node under the owner's home and create a
     * read-only user share targeting the teacher. Best-effort — an unresolvable
     * attachmentRef (already-deleted file, foreign OR attachment id shape) is
     * logged and skipped rather than failing the whole grant.
     *
     * @param string $ownerId       NC uid of the file owner (the portfolio's own learner).
     * @param string $teacherId     NC uid of the recipient.
     * @param string $attachmentRef nc:files path (relative to the owner's home) or OR attachment id.
     *
     * @return bool True when a share was created (or already existed for this recipient).
     *
     * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#scenario-granting-a-teacher-share-creates-a-native-nc-files-share
     */
    private function shareAttachment(string $ownerId, string $teacherId, string $attachmentRef): bool
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($ownerId);
            $node       = $userFolder->get($attachmentRef);
        } catch (NotFoundException $e) {
            $this->logger->warning(
                '[PortfolioShareGrantHandler] Could not resolve attachmentRef "{ref}" under {owner}\'s home — '
                .'skipping.',
                ['ref' => $attachmentRef, 'owner' => $ownerId]
            );
            return false;
        }

        $existing = $this->shareManager->getSharesBy(userId: $ownerId, shareType: IShare::TYPE_USER, path: $node);
        foreach ($existing as $existingShare) {
            if ($existingShare->getSharedWith() === $teacherId) {
                // Already shared with this recipient — nothing to do.
                return true;
            }
        }

        try {
            $share = $this->shareManager->newShare();
            $share->setNode($node);
            $share->setShareType(IShare::TYPE_USER);
            $share->setSharedWith($teacherId);
            $share->setSharedBy($ownerId);
            $share->setPermissions(Constants::PERMISSION_READ);
            $this->shareManager->createShare($share);
            return true;
        } catch (\Exception $e) {
            $this->logger->warning(
                '[PortfolioShareGrantHandler] Failed to create NC Files share of "{ref}" with {teacher}: {msg}',
                ['ref' => $attachmentRef, 'teacher' => $teacherId, 'msg' => $e->getMessage()]
            );
            return false;
        }

    }//end shareAttachment()

    /**
     * Load a single OpenRegister object by id.
     *
     * @param string $schema Schema slug.
     * @param string $id     Object UUID.
     *
     * @return array<string,mixed>|null The object data, or null when not found.
     *
     * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-teacher-can-be-granted-a-read-only-share-via-native-nextcloud-files-sharing
     */
    private function loadObject(string $schema, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => $schema,
                'filters'  => ['id' => $id],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        if (is_array($results[0]) === true) {
            return $results[0];
        }

        return $results[0]->jsonSerialize();

    }//end loadObject()
}//end class
