<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectCreatedEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Stub for ObjectCreatedEvent.
 */
abstract class ObjectCreatedEvent extends Event
{
    abstract public function getObject(): ObjectEntity;
    abstract public function getRegister(): string;
    abstract public function getSchema(): string;
}//end class
