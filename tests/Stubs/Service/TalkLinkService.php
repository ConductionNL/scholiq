<?php

/**
 * Test stub for OCA\OpenRegister\Service\TalkLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal TalkLinkService stub for Scholiq unit tests (CohortTalkMembershipHandler).
 *
 * Declares only the two methods CohortTalkMembershipHandler consumes:
 * `isTalkAvailable()` and `getLinkedRooms()`. The real class exposes a much
 * larger Tier-2 REST surface (linkRoom, createAndLinkRoom, unlinkRoom,
 * getAvailableRoomsForUser) that scholiq does not call directly — it is
 * reached only via OpenRegister's own `TalkLinksController` from the
 * frontend `integration`/`talk` manifest widgets.
 */
abstract class TalkLinkService
{
    /**
     * Whether NC Talk (spreed) is installed + enabled for the current user.
     *
     * @return bool
     */
    abstract public function isTalkAvailable(): bool;

    /**
     * Return the Talk conversations currently linked to an OR object.
     *
     * Each row carries at least `roomToken` (the real class also caches
     * `roomName`, `roomType`, `subtitle`, `participantCount`,
     * `lastMessageData`, `lastActivity`, `linkedBy`, `linkedAt`).
     *
     * @param string $objectUuid Parent OR object uuid.
     *
     * @return array<int,array<string,mixed>>
     */
    abstract public function getLinkedRooms(string $objectUuid): array;
}//end class
