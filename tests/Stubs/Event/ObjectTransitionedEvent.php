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
 *
 * Kept in sync with the real class (openregister/lib/Event/ObjectTransitionedEvent.php)
 * — `getAction()`/`getUserId()` added here alongside report-card-composer's
 * ReportCardComposer, which needs `getAction()` to distinguish ReportPeriod's
 * `compose` transition from ReportCard's `recompose` self-loop (both dispatch
 * this same event class).
 */
abstract class ObjectTransitionedEvent extends Event
{
    abstract public function getObject(): ObjectEntity;
    abstract public function getAction(): string;
    abstract public function getRegister(): string;
    abstract public function getSchema(): string;
    abstract public function getFrom(): string;
    abstract public function getTo(): string;
    abstract public function getUserId(): ?string;
    /** @return array<string,mixed> */
    abstract public function getContext(): array;
}//end class
