<?php

/**
 * Scholiq Subject Choice Validator
 *
 * IEventListener for SubjectChoice lifecycle -> submitted (the OR
 * ObjectTransitionedEvent with register=scholiq, schema=subject-choice,
 * to=submitted). Checks selectedElectiveCourseIds against the referenced
 * CurriculumPlan.electiveRules (minElectives/maxElectives,
 * mandatoryCombinations, mutuallyExclusive) and against the current
 * capacityByCourseId occupancy (counting sibling SubjectChoice rows in
 * approved/locked state for the same curriculumPlanId), then writes the
 * object directly to `validated` on success or `needs-revision` with a
 * populated validationErrors[] naming each unmet rule on failure.
 *
 * Modeled on ConferenceScheduleGenerator, not on a `requires` lifecycle
 * guard — design.md "Subject choice validation is a listener, not a
 * lifecycle guard": a guard can only block a transition, it cannot itself
 * decide between two legal destination states. Like
 * ConferenceScheduleGenerator writes ConferenceSignup.lifecycle directly to
 * `scheduled`/`waitlisted`, this listener writes SubjectChoice.lifecycle
 * directly to `validated`/`needs-revision` rather than driving a named
 * transition through TransitionEngine.
 *
 * ADR-031 legitimate exception: the rule set is a cross-object read (the
 * referenced CurriculumPlan plus every sibling SubjectChoice for capacity)
 * the pure per-object JSON-logic engine cannot express — the same rationale
 * BsaProgressEvaluator already established.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-a-submitted-subject-choice-is-validated-against-the-plan-s-elective-rules-not-persisted-unchecked
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Validates a submitted SubjectChoice against its CurriculumPlan.electiveRules and sibling capacity.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-a-submitted-subject-choice-is-validated-against-the-plan-s-elective-rules-not-persisted-unchecked
 */
class SubjectChoiceValidator implements IEventListener
{

    private const SCHOLIQ_REGISTER       = 'scholiq';
    private const SUBJECT_CHOICE_SCHEMA  = 'subject-choice';
    private const CURRICULUM_PLAN_SCHEMA = 'curriculum-plan';

    /**
     * SubjectChoice lifecycle states that occupy capacityByCourseId seats.
     *
     * @var string[]
     */
    private const OCCUPYING_STATES = ['approved', 'locked'];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
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
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-a-submitted-subject-choice-is-validated-against-the-plan-s-elective-rules-not-persisted-unchecked
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::SUBJECT_CHOICE_SCHEMA || $event->getTo() !== 'submitted') {
            return;
        }

        $this->validate(choice: $event->getObject()->jsonSerialize());

    }//end handle()

    /**
     * Validate a submitted SubjectChoice and write validated/needs-revision.
     *
     * @param array<string,mixed> $choice The submitted SubjectChoice property array.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-choice-satisfying-every-rule-validates
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-choice-violating-a-mandatory-combination-is-sent-back-for-revision
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-choice-exceeding-a-course-s-capacity-is-sent-back-for-revision
     */
    private function validate(array $choice): void
    {
        $choiceId         = (string) ($choice['id'] ?? ($choice['uuid'] ?? ''));
        $curriculumPlanId = (string) ($choice['curriculumPlanId'] ?? '');
        $tenantId         = (string) ($choice['tenant_id'] ?? '');

        $selected = $choice['selectedElectiveCourseIds'] ?? [];
        if (is_array($selected) === false) {
            $selected = [];
        }

        if ($choiceId === '' || $curriculumPlanId === '') {
            $this->logger->warning('[SubjectChoiceValidator] SubjectChoice has no id or curriculumPlanId; skipping validation.');
            return;
        }

        $plan  = $this->fetchPlan(curriculumPlanId: $curriculumPlanId);
        $rules = ($plan['electiveRules'] ?? null);
        if (is_array($rules) === false) {
            $rules = [];
        }

        $errors = array_merge(
            $this->checkMinMax(rules: $rules, selected: $selected),
            $this->checkMandatoryCombinations(rules: $rules, selected: $selected),
            $this->checkMutuallyExclusive(rules: $rules, selected: $selected),
            $this->checkCapacity(
                rules: $rules,
                selected: $selected,
                curriculumPlanId: $curriculumPlanId,
                tenantId: $tenantId,
                choiceId: (string) $choiceId
            )
        );

        $updated = $choice;
        if (count($errors) === 0) {
            $updated['lifecycle']        = 'validated';
            $updated['validationErrors'] = [];
        } else {
            $updated['lifecycle']        = 'needs-revision';
            $updated['validationErrors'] = $errors;
        }

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::SUBJECT_CHOICE_SCHEMA,
            object: $updated
        );

        $this->logger->info(
            '[SubjectChoiceValidator] SubjectChoice {id} -> {result} ({count} error(s)).',
            ['id' => $choiceId, 'result' => $updated['lifecycle'], 'count' => count($errors)]
        );

    }//end validate()

    /**
     * MinElectives/maxElectives check.
     *
     * @param array<string,mixed> $rules    electiveRules block.
     * @param array<int,mixed>    $selected selectedElectiveCourseIds.
     *
     * @return string[]
     */
    private function checkMinMax(array $rules, array $selected): array
    {
        $errors = [];
        $count  = count($selected);

        $min = $rules['minElectives'] ?? null;
        if (is_int($min) === true && $count < $min) {
            $errors[] = sprintf('At least %d elective(s) required (selected %d).', $min, $count);
        }

        $max = $rules['maxElectives'] ?? null;
        if (is_int($max) === true && $count > $max) {
            $errors[] = sprintf('At most %d elective(s) allowed (selected %d).', $max, $count);
        }

        return $errors;

    }//end checkMinMax()

    /**
     * MandatoryCombinations check — every named combination must be selected in full.
     *
     * @param array<string,mixed> $rules    electiveRules block.
     * @param array<int,mixed>    $selected selectedElectiveCourseIds.
     *
     * @return string[]
     */
    private function checkMandatoryCombinations(array $rules, array $selected): array
    {
        $errors       = [];
        $combinations = $rules['mandatoryCombinations'] ?? [];
        if (is_array($combinations) === false) {
            return $errors;
        }

        foreach ($combinations as $combination) {
            if (is_array($combination) === false || count($combination) === 0) {
                continue;
            }

            $missing = array_values(array_diff($combination, $selected));
            if (count($missing) > 0) {
                $errors[] = sprintf('Mandatory combination not fully selected: missing %s.', implode(', ', $missing));
            }
        }

        return $errors;

    }//end checkMandatoryCombinations()

    /**
     * MutuallyExclusive check — at most one course per named set.
     *
     * @param array<string,mixed> $rules    electiveRules block.
     * @param array<int,mixed>    $selected selectedElectiveCourseIds.
     *
     * @return string[]
     */
    private function checkMutuallyExclusive(array $rules, array $selected): array
    {
        $errors = [];
        $groups = $rules['mutuallyExclusive'] ?? [];
        if (is_array($groups) === false) {
            return $errors;
        }

        foreach ($groups as $group) {
            if (is_array($group) === false) {
                continue;
            }

            $chosen = array_values(array_intersect($group, $selected));
            if (count($chosen) > 1) {
                $errors[] = sprintf('Mutually exclusive courses selected together: %s.', implode(', ', $chosen));
            }
        }

        return $errors;

    }//end checkMutuallyExclusive()

    /**
     * CapacityByCourseId check — counts sibling approved/locked SubjectChoice rows.
     *
     * @param array<string,mixed> $rules            electiveRules block.
     * @param array<int,mixed>    $selected         selectedElectiveCourseIds.
     * @param string              $curriculumPlanId The governing CurriculumPlan UUID.
     * @param string              $tenantId         Tenant ID.
     * @param string              $choiceId         The SubjectChoice being validated (excluded from occupancy).
     *
     * @return string[]
     */
    private function checkCapacity(array $rules, array $selected, string $curriculumPlanId, string $tenantId, string $choiceId): array
    {
        $errors = [];
        $capacityByCourseId = $rules['capacityByCourseId'] ?? null;
        if (is_array($capacityByCourseId) === false || count($capacityByCourseId) === 0) {
            return $errors;
        }

        $siblings = $this->fetchOccupyingSiblings(curriculumPlanId: $curriculumPlanId, tenantId: $tenantId, excludeId: $choiceId);

        foreach ($capacityByCourseId as $courseId => $limit) {
            if (in_array($courseId, $selected, true) === false || is_int($limit) === false) {
                continue;
            }

            $occupied = 0;
            foreach ($siblings as $sibling) {
                $siblingSelected = $sibling['selectedElectiveCourseIds'] ?? [];
                if (is_array($siblingSelected) === true && in_array($courseId, $siblingSelected, true) === true) {
                    $occupied++;
                }
            }

            if ($occupied >= $limit) {
                $errors[] = sprintf('Capacity reached for course %s (limit %d).', $courseId, $limit);
            }
        }

        return $errors;

    }//end checkCapacity()

    /**
     * Fetch sibling SubjectChoice rows in an occupying state for the same plan.
     *
     * @param string $curriculumPlanId Governing CurriculumPlan UUID.
     * @param string $tenantId         Tenant ID.
     * @param string $excludeId        The SubjectChoice id being validated, excluded from results.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchOccupyingSiblings(string $curriculumPlanId, string $tenantId, string $excludeId): array
    {
        $siblings = [];

        foreach (self::OCCUPYING_STATES as $lifecycle) {
            $filters = [
                'curriculumPlanId' => $curriculumPlanId,
                'lifecycle'        => $lifecycle,
            ];
            if ($tenantId !== '') {
                $filters['tenant_id'] = $tenantId;
            }

            $rows = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::SUBJECT_CHOICE_SCHEMA,
                    'filters'  => $filters,
                    'limit'    => 5000,
                ]
            );

            foreach ($rows as $row) {
                if (is_array($row) === false) {
                    $row = $row->jsonSerialize();
                }

                $id = (string) ($row['id'] ?? ($row['uuid'] ?? ''));
                if ($id !== '' && $id === $excludeId) {
                    continue;
                }

                $siblings[] = $row;
            }
        }//end foreach

        return $siblings;

    }//end fetchOccupyingSiblings()

    /**
     * Fetch the referenced CurriculumPlan, normalised to a plain array.
     *
     * @param string $curriculumPlanId CurriculumPlan UUID.
     *
     * @return array<string,mixed>|null
     */
    private function fetchPlan(string $curriculumPlanId): ?array
    {
        $plan = $this->objectService->find(
            id: $curriculumPlanId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::CURRICULUM_PLAN_SCHEMA
        );

        if ($plan === null) {
            return null;
        }

        if (is_array($plan) === true) {
            return $plan;
        }

        return $plan->jsonSerialize();

    }//end fetchPlan()
}//end class
