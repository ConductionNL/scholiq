<?php

/**
 * Test stub for OCA\Talk\Service\ParticipantService (spreed 24.0.1).
 *
 * Only `addUsers()` and `removeUser()` are modelled — the two calls
 * CohortTalkMembershipHandler makes. Real signatures verified against
 * spreed 24.0.1 lib/Service/ParticipantService.php:
 *   - addUsers(Room $room, array $participants, ?IUser $addedBy = null, bool $bansAlreadyChecked = false): array
 *   - removeUser(Room $room, IUser $user, string $reason): void
 * Mirrors hermiq's `tests/Stubs/Talk/Service/ParticipantService.php` stub pattern.
 *
 * @category Stub
 * @package  OCA\Talk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Talk\Service;

use OCA\Talk\Room;
use OCP\IUser;

/**
 * Minimal stub of the spreed participant service.
 */
class ParticipantService
{
    /**
     * Add users as participants of a room.
     *
     * @param Room                            $room               The room.
     * @param array<int,array<string,string>> $participants       Actor rows (`{actorType, actorId}`).
     * @param IUser|null                      $addedBy            Unused in the stub.
     * @param bool                            $bansAlreadyChecked Unused in the stub.
     *
     * @return array<int,mixed>
     */
    public function addUsers(Room $room, array $participants, ?IUser $addedBy=null, bool $bansAlreadyChecked=false): array
    {
        return [];

    }//end addUsers()

    /**
     * Remove a user's participation from a room. No-ops (per the real
     * implementation) when the user is not currently a participant.
     *
     * @param Room   $room   The room.
     * @param IUser  $user   The user to remove.
     * @param string $reason Removal reason.
     *
     * @return void
     */
    public function removeUser(Room $room, IUser $user, string $reason): void
    {
    }//end removeUser()
}//end class
