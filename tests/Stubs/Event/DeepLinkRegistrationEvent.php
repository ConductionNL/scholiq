<?php

/**
 * Test stub for OCA\OpenRegister\Event\DeepLinkRegistrationEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Stub for DeepLinkRegistrationEvent.
 */
abstract class DeepLinkRegistrationEvent extends Event
{
    abstract public function register(
        string $appId,
        string $registerSlug,
        string $schemaSlug,
        string $urlTemplate
    ): void;
}//end class
