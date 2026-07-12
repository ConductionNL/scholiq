<?php

/**
 * Scholiq Grade Visibility Resolver
 *
 * Stateless service that resolves the `visibleFrom` datetime for a `GradeEntry`
 * at the moment it publishes — the earliest moment its (and its fanned-out
 * `GradeNotification`s') declared `gradePublished` notification is eligible to
 * fire. Resolution order: an explicit teacher-supplied override always wins;
 * otherwise the governing `CurriculumPlan.gradeVisibilityPolicy` is applied
 * (`null` policy — or an explicit `mode: "immediate"` — resolves to the publish
 * moment itself, preserving today's behaviour). `mode: "nextSchoolDay"`
 * resolves to the next non-Saturday/non-Sunday day at `policy.time` in
 * `policy.timezone`, rolling to the following day when the publish moment has
 * already passed that time on the same day.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata,"
 * the same shape as `GradeFormulaEvaluator`'s exception — resolving a
 * scheduled-visibility window (weekday arithmetic + timezone-aware
 * time-of-day comparison) cannot be expressed in JSON-logic. Single
 * responsibility: resolve → return; no state, no writes, no notification
 * dispatch of its own.
 *
 * Consumed by:
 *   - GradeRollupHandler::handleGradeEntryPublished (via ObjectTransitionedEvent)
 *
 * @category Grading
 * @package  OCA\Scholiq\Grading
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
 * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Scholiq\Grading;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Resolves the effective `visibleFrom` for a publishing GradeEntry.
 *
 * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 */
class GradeVisibilityResolver
{

    private const DEFAULT_TIME     = '00:00';
    private const DEFAULT_TIMEZONE = 'Europe/Amsterdam';

    /**
     * ISO-8601 weekday numbers (per DateTimeImmutable::format('N')) that are
     * never a school day: 6 = Saturday, 7 = Sunday.
     */
    private const WEEKEND_ISO_DAYS = [6, 7];

    /**
     * Resolve the effective `visibleFrom` for a GradeEntry publish.
     *
     * @param string|null               $override    Teacher-supplied ISO-8601 `visibleFrom` override, if any.
     * @param array<string, mixed>|null $policy      CurriculumPlan.gradeVisibilityPolicy (`{mode, time, timezone}` or null).
     * @param DateTimeImmutable         $publishedAt Moment the GradeEntry transitioned to published.
     *
     * @return DateTimeImmutable The resolved visibleFrom.
     *
     * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
     */
    public function resolve(
        ?string $override,
        ?array $policy,
        DateTimeImmutable $publishedAt,
    ): DateTimeImmutable {
        $overrideDate = $this->parseOverride(override: $override);
        if ($overrideDate !== null) {
            return $overrideDate;
        }

        $mode = (string) ($policy['mode'] ?? 'immediate');
        if ($mode !== 'nextSchoolDay') {
            // Null policy, or an explicit 'immediate' mode: today's behaviour.
            return $publishedAt;
        }

        return $this->resolveNextSchoolDay(policy: $policy, publishedAt: $publishedAt);

    }//end resolve()

    /**
     * Parse the teacher-supplied override, if present and valid.
     *
     * A blank or unparsable override is treated as "no override" (fail safe —
     * resolution falls through to the CurriculumPlan policy rather than
     * silently publishing on a garbage timestamp).
     *
     * @param string|null $override Raw override value.
     *
     * @return DateTimeImmutable|null
     */
    private function parseOverride(?string $override): ?DateTimeImmutable
    {
        if ($override === null || $override === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($override);
        } catch (Exception $e) {
            return null;
        }

    }//end parseOverride()

    /**
     * Resolve the `nextSchoolDay` policy mode.
     *
     * Computes the next non-weekend day at `policy.time` in `policy.timezone`,
     * rolling forward a day at a time until both the time-of-day cutoff has
     * not already passed (relative to the publish moment) AND the resulting
     * calendar day is not a Saturday/Sunday.
     *
     * @param array<string, mixed> $policy      The `gradeVisibilityPolicy` block.
     * @param DateTimeImmutable    $publishedAt Moment the GradeEntry transitioned to published.
     *
     * @return DateTimeImmutable
     *
     * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#scenario-curriculumplan-supplies-the-default-visibility-policy-when-a-teacher-does-not-override
     */
    private function resolveNextSchoolDay(array $policy, DateTimeImmutable $publishedAt): DateTimeImmutable
    {
        $timezone        = $this->resolveTimezone(raw: ($policy['timezone'] ?? null));
        [$hour, $minute] = $this->parseTimeOfDay(raw: ($policy['time'] ?? null));

        $localNow = $publishedAt->setTimezone($timezone);

        $candidate = $localNow
            ->setTime(hour: $hour, minute: $minute, second: 0, microsecond: 0);

        // Roll forward a day if the cutoff time has already passed today.
        if ($candidate <= $localNow) {
            $candidate = $candidate->add(new DateInterval('P1D'));
        }

        // Roll forward, day by day, until the candidate lands on a weekday.
        while (in_array((int) $candidate->format('N'), self::WEEKEND_ISO_DAYS, true) === true) {
            $candidate = $candidate->add(new DateInterval('P1D'));
        }

        return $candidate;

    }//end resolveNextSchoolDay()

    /**
     * Resolve a `DateTimeZone` from the policy's raw timezone string.
     *
     * Falls back to Europe/Amsterdam (the schema default) on a missing or
     * invalid identifier rather than throwing.
     *
     * @param mixed $raw Raw timezone value from the policy.
     *
     * @return DateTimeZone
     */
    private function resolveTimezone($raw): DateTimeZone
    {
        $identifier = self::DEFAULT_TIMEZONE;
        if (is_string($raw) === true && $raw !== '') {
            $identifier = $raw;
        }

        try {
            return new DateTimeZone($identifier);
        } catch (Exception $e) {
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }

    }//end resolveTimezone()

    /**
     * Parse a policy `time` string ("HH:MM") into an [hour, minute] pair.
     *
     * Falls back to 00:00 on a missing or malformed value rather than
     * throwing.
     *
     * @param mixed $raw Raw time-of-day value from the policy.
     *
     * @return array{0: int, 1: int}
     */
    private function parseTimeOfDay($raw): array
    {
        $value = self::DEFAULT_TIME;
        if (is_string($raw) === true && $raw !== '') {
            $value = $raw;
        }

        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $matches) !== 1) {
            return [0, 0];
        }

        return [(int) $matches[1], (int) $matches[2]];

    }//end parseTimeOfDay()
}//end class
