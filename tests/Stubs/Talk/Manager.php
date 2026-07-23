<?php

/**
 * Test stub for OCA\Talk\Manager (spreed 24.0.1).
 *
 * Only getRoomByToken() is modelled — the server-side, unscoped room
 * resolution CohortTalkMembershipHandler uses (a background event-listener
 * sync has no logged-in session to scope a `getRoomForUserByToken()` call
 * against). Real signature verified against spreed 24.0.1 lib/Manager.php.
 * Mirrors hermiq's `tests/Stubs/Talk/Manager.php` stub pattern.
 *
 * @category Stub
 * @package  OCA\Talk
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

namespace OCA\Talk;

/**
 * Minimal stub of the spreed room manager.
 */
class Manager
{
    /**
     * Resolve a room by its token, unscoped to any particular user.
     *
     * @param string      $token         The room token.
     * @param string|null $preloadUserId Unused in the stub.
     * @param string|null $serverUrl     Unused in the stub.
     *
     * @return Room The resolved room.
     */
    public function getRoomByToken(string $token, ?string $preloadUserId=null, ?string $serverUrl=null): Room
    {
        return new Room();

    }//end getRoomByToken()
}//end class
