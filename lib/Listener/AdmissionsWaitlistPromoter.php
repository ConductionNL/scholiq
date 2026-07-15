<?php

/**
 * Scholiq Admissions Waitlist Promoter
 *
 * IEventListener for Application lifecycle -> withdrawn/rejected FROM placed
 * (the OR ObjectTransitionedEvent with register=scholiq, schema=application).
 * A freed seat promotes the single oldest-submittedAt waitlisted Application
 * for the same admissionsRoundId to placed, re-running its normal `promote`
 * transition so AdmissionsDecisionGuard's capacity branch still applies —
 * promotion cannot silently over-fill a round.
 *
 * Reuses ConferenceScheduleGenerator's "an event handler reacts to a freed
 * resource and promotes the next waitlisted row, oldest-first, without
 * disturbing already-confirmed rows" shape (design.md "Waitlist promotion
 * reuses ConferenceScheduleGenerator's shape, not its algorithm"). Unlike
 * ConferenceScheduleGenerator this is a single-row promotion, not a batch
 * solver — admissions capacity is a plain integer, not a calendar.
 *
 * Closes the gap enrolment/spec.md named as deferred ("Waitlist
 * auto-promotion (V1 enhancement)").
 *
 * ADR-031 legitimate exception: cross-object write bridge — promoting the
 * next waitlisted Application on a freed seat cannot be expressed as a
 * schema declaration.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-placement-capacity-is-enforced-and-a-waitlisted-application-is-auto-promoted-when-a-seat-frees-up
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Promotes the oldest waitlisted Application when a placed seat frees up.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-placement-capacity-is-enforced-and-a-waitlisted-application-is-auto-promoted-when-a-seat-frees-up
 */
class AdmissionsWaitlistPromoter implements IEventListener
{

    private const SCHOLIQ_REGISTER   = 'scholiq';
    private const APPLICATION_SCHEMA = 'application';

    /**
     * Target states that free a placed seat.
     *
     * @var string[]
     */
    private const FREED_TARGETS = ['withdrawn', 'rejected'];

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object access service.
     * @param TransitionEngine $transitionEngine OR lifecycle engine used to dispatch the `promote` transition.
     * @param LoggerInterface  $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-placement-capacity-is-enforced-and-a-waitlisted-application-is-auto-promoted-when-a-seat-frees-up
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::APPLICATION_SCHEMA) {
            return;
        }

        if ($event->getFrom() !== 'placed') {
            return;
        }

        if (in_array($event->getTo(), self::FREED_TARGETS, true) === false) {
            return;
        }

        $this->promoteOldestWaitlisted(freed: $event->getObject()->jsonSerialize());

    }//end handle()

    /**
     * Promote the oldest-submittedAt waitlisted Application for the same round.
     *
     * @param array<string,mixed> $freed The Application that just freed its seat.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-withdrawal-promotes-the-oldest-waitlisted-applicant
     */
    private function promoteOldestWaitlisted(array $freed): void
    {
        $roundId  = (string) ($freed['admissionsRoundId'] ?? '');
        $tenantId = (string) ($freed['tenant_id'] ?? '');

        if ($roundId === '') {
            $this->logger->warning('[AdmissionsWaitlistPromoter] Freed Application has no admissionsRoundId; skipping promotion.');
            return;
        }

        $filters = [
            'admissionsRoundId' => $roundId,
            'lifecycle'         => 'waitlisted',
        ];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $waitlisted = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::APPLICATION_SCHEMA,
                'filters'  => $filters,
                'limit'    => 2000,
            ]
        );
        $waitlisted = array_map([$this, 'normalise'], $waitlisted);

        if (count($waitlisted) === 0) {
            return;
        }

        usort(
            $waitlisted,
            static fn (array $a, array $b): int => strcmp((string) ($a['submittedAt'] ?? ''), (string) ($b['submittedAt'] ?? ''))
        );

        $oldest   = $waitlisted[0];
        $oldestId = (string) ($oldest['id'] ?? ($oldest['uuid'] ?? ''));

        if ($oldestId === '') {
            $this->logger->warning('[AdmissionsWaitlistPromoter] Oldest waitlisted Application has no id; skipping promotion.');
            return;
        }

        $this->transitionEngine->transition((string) $oldestId, 'promote');

        $this->logger->info(
            '[AdmissionsWaitlistPromoter] Promoted oldest waitlisted Application {id} for round {round}.',
            ['id' => $oldestId, 'round' => $roundId]
        );

    }//end promoteOldestWaitlisted()

    /**
     * Normalise an ObjectService row to a plain array.
     *
     * @param mixed $row Raw row from ObjectService::findAll().
     *
     * @return array<string,mixed>
     */
    private function normalise(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        return $row->jsonSerialize();

    }//end normalise()
}//end class
