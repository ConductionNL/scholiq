<?php

/**
 * Scholiq BSA Progress Flag Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent on the same GradeEntry
 * `-> published` transition GradeRollupHandler already reacts to (a learner's
 * cumulative earned credits can only change when a GradeEntry publishes and
 * FinalGrade recomputes). For the affected learner, resolves the Programme(s)
 * their published Course belongs to, finds every `active` BsaTrajectory for
 * that Programme, computes `ectsEarned` via BsaProgressEvaluator, and — once
 * the trajectory's `windowOpensAt` has passed and `ectsEarned` falls below
 * `interimNormEcts` — creates a `BsaProgressFlag` (`open`), idempotency-keyed
 * so re-crossing the same trajectory doesn't duplicate flags.
 *
 * ADR-031 legitimate exception: new-object creation in response to a
 * calculatedChange-equivalent recompute cannot be expressed as schema
 * metadata declarations — mirrors AttendanceFlagCreationHandler's role for
 * AttendanceThreshold, and GradeRollupHandler's role for FinalGrade. It is
 * NOT a TimedJob — it fires off the real GradeEntry.published event, exactly
 * like GradeRollupHandler (ADR-022).
 *
 * IMPORTANT: This handler ONLY creates the flag. It NEVER auto-acts against
 * the learner — the study-advisor's intervention (drafting a BsaWarning) is a
 * separate, human-initiated step. Human-in-the-loop throughout, mirroring the
 * AttendanceFlag rule.
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
 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\StudyProgress\BsaProgressEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Creates a BsaProgressFlag when a learner's ectsEarned falls below their
 * BsaTrajectory's interimNormEcts once the interim-check window has opened.
 *
 * @implements IEventListener<Event>
 */
class BsaProgressFlagHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER      = 'scholiq';
    private const GRADE_ENTRY_SCHEMA    = 'grade-entry';
    private const COURSE_SCHEMA         = 'course';
    private const BSA_TRAJECTORY_SCHEMA = 'bsa-trajectory';
    private const BSA_PROGRESS_FLAG_SCHEMA = 'bsa-progress-flag';

    /**
     * BsaProgressFlag lifecycle states that count as "still open" for
     * idempotency purposes — a resolved flag does not block a fresh one.
     *
     * @var string[]
     */
    private const OPEN_FLAG_STATES = ['open', 'in-handling', 'warned'];

    /**
     * Constructor.
     *
     * @param ObjectService        $objectService OR object access.
     * @param BsaProgressEvaluator $evaluator     ectsEarned calculation engine.
     * @param ITimeFactory         $timeFactory   NC time source (injectable "now" for tests).
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly BsaProgressEvaluator $evaluator,
        private readonly ITimeFactory $timeFactory,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::GRADE_ENTRY_SCHEMA || $event->getTo() !== 'published') {
            return;
        }

        $entry     = $event->getObject()->jsonSerialize();
        $learnerId = $entry['learnerId'] ?? '';
        $courseId  = $entry['courseId'] ?? null;
        $tenantId  = $entry['tenant_id'] ?? '';

        if ($learnerId === '' || $courseId === null) {
            // Cohort-only GradeEntries (no courseId) carry no Programme scope
            // to check trajectories against — nothing to do.
            return;
        }

        $programmeIds = $this->resolveProgrammeIds(courseId: $courseId, tenantId: $tenantId);

        foreach ($programmeIds as $programmeId) {
            $this->checkProgramme(programmeId: $programmeId, learnerId: $learnerId, tenantId: $tenantId);
        }

    }//end handle()

    /**
     * Resolve the Programme(s) a Course belongs to.
     *
     * @param string $courseId UUID of the Course.
     * @param string $tenantId Tenant ID (unused for lookup scoping; Course is
     *                         resolved by id, which is already tenant-unique).
     *
     * @return array<int, string>
     */
    private function resolveProgrammeIds(string $courseId, string $tenantId): array
    {
        $course = $this->objectService->find(
            id: $courseId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::COURSE_SCHEMA
        );

        if ($course === null) {
            return [];
        }

        $courseData = $course;
        if (is_array($course) === false) {
            $courseData = $course->jsonSerialize();
        }

        $programmeIds = $courseData['programmeIds'] ?? [];
        if (is_array($programmeIds) === false) {
            return [];
        }

        return array_values(array_filter($programmeIds, static fn ($id) => is_string($id) && $id !== ''));

    }//end resolveProgrammeIds()

    /**
     * Check every active BsaTrajectory for a Programme against the learner's
     * recomputed ectsEarned, creating a flag when at risk.
     *
     * @param string $programmeId Programme UUID.
     * @param string $learnerId   NC user ID of the learner.
     * @param string $tenantId    Tenant ID.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
     */
    private function checkProgramme(string $programmeId, string $learnerId, string $tenantId): void
    {
        $trajectories = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::BSA_TRAJECTORY_SCHEMA,
                'filters'  => [
                    'programmeId' => $programmeId,
                    'lifecycle'   => 'active',
                ],
            ]
        );

        foreach ($trajectories as $trajectory) {
            if (is_array($trajectory) === false) {
                $trajectory = $trajectory->jsonSerialize();
            }

            $this->checkTrajectory(trajectory: $trajectory, learnerId: $learnerId, tenantId: $tenantId);
        }

    }//end checkProgramme()

    /**
     * Evaluate a single BsaTrajectory for a learner and create a flag if at risk.
     *
     * @param array<string,mixed> $trajectory BsaTrajectory data.
     * @param string               $learnerId  NC user ID of the learner.
     * @param string               $tenantId   Tenant ID.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
     */
    private function checkTrajectory(array $trajectory, string $learnerId, string $tenantId): void
    {
        $interimNormEcts = $trajectory['interimNormEcts'] ?? null;
        if ($interimNormEcts === null) {
            // No interim pace threshold configured — nothing to check ahead
            // of the guideline for this trajectory.
            return;
        }

        $windowOpensAt = $trajectory['windowOpensAt'] ?? null;
        if (is_string($windowOpensAt) === false || $windowOpensAt === '') {
            return;
        }

        $now = DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime());
        try {
            $windowOpensAtDate = new DateTimeImmutable($windowOpensAt);
        } catch (\Exception) {
            return;
        }

        if ($now < $windowOpensAtDate) {
            // Interim-check window has not opened yet.
            return;
        }

        $programmeId    = $trajectory['programmeId'] ?? '';
        $bsaTrajectoryId = $trajectory['id'] ?? ($trajectory['uuid'] ?? '');
        $academicYear   = $trajectory['academicYear'] ?? '';

        if ($programmeId === '' || $bsaTrajectoryId === '') {
            return;
        }

        $result     = $this->evaluator->evaluate(programmeId: $programmeId, learnerId: $learnerId);
        $ectsEarned = $result['ectsEarned'];

        if ($ectsEarned >= (float) $interimNormEcts) {
            // Not at risk.
            return;
        }

        if ($this->hasOpenFlag(learnerId: $learnerId, bsaTrajectoryId: $bsaTrajectoryId) === true) {
            // Idempotency: do not duplicate an already-open flag for this
            // learner + trajectory.
            return;
        }

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::BSA_PROGRESS_FLAG_SCHEMA,
            object: [
                'learnerId'           => $learnerId,
                'programmeId'         => $programmeId,
                'bsaTrajectoryId'     => $bsaTrajectoryId,
                'academicYear'        => $academicYear,
                'ectsEarned'          => $ectsEarned,
                'ectsRequiredAtCheck' => (float) $interimNormEcts,
                'flaggedAt'           => $now->format(\DATE_ATOM),
                'lifecycle'           => 'open',
                'tenant_id'           => $tenantId,
            ]
        );

    }//end checkTrajectory()

    /**
     * Check whether a still-open BsaProgressFlag already exists for this
     * learner + trajectory.
     *
     * @param string $learnerId       NC user ID of the learner.
     * @param string $bsaTrajectoryId UUID of the BsaTrajectory.
     *
     * @return bool
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
     */
    private function hasOpenFlag(string $learnerId, string $bsaTrajectoryId): bool
    {
        foreach (self::OPEN_FLAG_STATES as $state) {
            $existing = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::BSA_PROGRESS_FLAG_SCHEMA,
                    'filters'  => [
                        'learnerId'       => $learnerId,
                        'bsaTrajectoryId' => $bsaTrajectoryId,
                        'lifecycle'       => $state,
                    ],
                    'limit'    => 1,
                ]
            );

            if (empty($existing) === false) {
                return true;
            }
        }

        return false;

    }//end hasOpenFlag()
}//end class
