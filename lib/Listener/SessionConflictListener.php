<?php

/**
 * Scholiq Session Conflict Listener
 *
 * IEventListener for Session create/update (OR's `ObjectCreatedEvent` and
 * `ObjectUpdatedEvent`, filtered to register=scholiq, schema=session).
 * Delegates the actual pairwise overlap scan to
 * {@see \OCA\Scholiq\Timetabling\TimetableConflictDetector} — this class'
 * only responsibility is filtering the incoming OR event to the right
 * schema and invoking the detector for the single changed Session, per the
 * timetabling spec's "Detection MUST run as an OR-event-driven scan (on
 * Session create/update...)" requirement.
 *
 * ObjectUpdatedEvent has no prior scholiq listener precedent (verified: every
 * other event-driven listener in this app's lib/AppInfo/Application.php
 * registers against `ObjectCreatedEvent`, `ObjectCreatingEvent`, or
 * `ObjectTransitionedEvent` only) — it is, however, a real, already-shipped
 * OpenRegister event class (`OCA\OpenRegister\Event\ObjectUpdatedEvent`,
 * `getObject()`/`getOldObject()`), the natural analogue needed here since a
 * Session's `roomId`/`startsAt`/`endsAt` can be edited via the generic OR
 * object-update endpoint without going through any lifecycle transition.
 *
 * ADR-031 legitimate exception: cross-object write bridge dispatcher — the
 * actual conflict-detection algorithm lives in TimetableConflictDetector,
 * not here.
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Scholiq\Timetabling\TimetableConflictDetector;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Triggers TimetableConflictDetector for a single created/updated Session.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */
class SessionConflictListener implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const SESSION_SCHEMA   = 'session';

    /**
     * Constructor.
     *
     * @param TimetableConflictDetector $detector The pairwise overlap scan engine.
     *
     * @return void
     */
    public function __construct(
        private readonly TimetableConflictDetector $detector,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectCreatedEvent or ObjectUpdatedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-two-sessions-imported-for-the-same-room-at-overlapping-times-are-flagged-not-auto-moved
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === true) {
            $object = $event->getObject();
        } else if ($event instanceof ObjectUpdatedEvent === true) {
            $object = $event->getObject();
        } else {
            return;
        }

        if ($object->getRegister() !== self::SCHOLIQ_REGISTER || $object->getSchema() !== self::SESSION_SCHEMA) {
            return;
        }

        $this->detector->scan([$object->jsonSerialize()]);

    }//end handle()
}//end class
