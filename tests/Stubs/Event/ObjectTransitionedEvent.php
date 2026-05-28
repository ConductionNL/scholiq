<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectTransitionedEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Stub for ObjectTransitionedEvent.
 */
abstract class ObjectTransitionedEvent extends Event
{
    abstract public function getObject(): ObjectEntity;
    abstract public function getRegister(): string;
    abstract public function getSchema(): string;
    abstract public function getFrom(): string;
    abstract public function getTo(): string;
    /** @return array<string,mixed> */
    abstract public function getContext(): array;
}//end class
