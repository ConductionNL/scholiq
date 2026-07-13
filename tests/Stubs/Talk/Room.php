<?php

/**
 * Test stub for OCA\Talk\Room (spreed 24.0.1).
 *
 * Stands in for the spreed Room entity so CohortTalkMembershipHandler can be
 * unit-tested in standalone CI without Talk installed. The handler only ever
 * passes the resolved Room straight through to ParticipantService, so no
 * members are modelled — mirrors hermiq's `tests/Stubs/Talk/Room.php` stub.
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
 * Minimal stub of the spreed Room entity.
 */
class Room
{
    /**
     * The room token.
     *
     * @return string
     */
    public function getToken(): string
    {
        return 'stub-token';

    }//end getToken()
}//end class
