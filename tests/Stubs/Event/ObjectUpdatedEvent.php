<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectUpdatedEvent.
 *
 * Unlike ObjectCreatedEvent/ObjectTransitionedEvent, the real class exposes no
 * direct getRegister()/getSchema() convenience methods on the event itself —
 * only getObject() (the post-update ObjectEntity) and getOldObject() (the
 * pre-update ObjectEntity, nullable when unavailable). Listeners resolve
 * register/schema via `$event->getObject()->getRegister()`/`getSchema()`,
 * mirroring the existing ObjectCreatedEvent-consuming listeners (e.g.
 * LessonProgressHandler).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Stub for ObjectUpdatedEvent.
 */
abstract class ObjectUpdatedEvent extends Event
{
    abstract public function getObject(): ObjectEntity;
    abstract public function getOldObject(): ?ObjectEntity;
}//end class
