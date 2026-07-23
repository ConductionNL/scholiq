<?php

/**
 * Scholiq GradeVisibilityResolver unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Grading
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

namespace OCA\Scholiq\Tests\Unit\Grading;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Scholiq\Grading\GradeVisibilityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GradeVisibilityResolver::resolve().
 */
class GradeVisibilityResolverTest extends TestCase
{

    private GradeVisibilityResolver $resolver;

    /**
     * Set up a fresh resolver before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new GradeVisibilityResolver();

    }//end setUp()

    /**
     * An explicit teacher override always wins, regardless of the policy.
     *
     * @return void
     */
    public function testExplicitOverrideWins(): void
    {
        $publishedAt = new DateTimeImmutable('2026-07-13 23:40:00', new DateTimeZone('Europe/Amsterdam'));
        $policy      = [
            'mode'     => 'nextSchoolDay',
            'time'     => '10:00',
            'timezone' => 'Europe/Amsterdam',
        ];

        $result = $this->resolver->resolve(
            override: '2026-07-13T23:41:00+02:00',
            policy: $policy,
            publishedAt: $publishedAt
        );

        self::assertSame('2026-07-13 23:41:00', $result->format('Y-m-d H:i:s'));

    }//end testExplicitOverrideWins()

    /**
     * A null policy resolves to 'immediate' — today's behaviour, the publish moment itself.
     *
     * @return void
     */
    public function testNullPolicyResolvesToImmediate(): void
    {
        $publishedAt = new DateTimeImmutable('2026-07-13 23:40:00', new DateTimeZone('Europe/Amsterdam'));

        $result = $this->resolver->resolve(override: null, policy: null, publishedAt: $publishedAt);

        self::assertSame($publishedAt, $result);

    }//end testNullPolicyResolvesToImmediate()

    /**
     * An explicit `mode: "immediate"` policy behaves identically to a null policy.
     *
     * @return void
     */
    public function testExplicitImmediateModeResolvesToPublishMoment(): void
    {
        $publishedAt = new DateTimeImmutable('2026-07-13 23:40:00', new DateTimeZone('Europe/Amsterdam'));

        $result = $this->resolver->resolve(
            override: null,
            policy: ['mode' => 'immediate'],
            publishedAt: $publishedAt
        );

        self::assertSame($publishedAt, $result);

    }//end testExplicitImmediateModeResolvesToPublishMoment()

    /**
     * A `nextSchoolDay` publish before the cutoff, on a weekday, resolves to
     * later the SAME day at the cutoff time.
     *
     * @return void
     */
    public function testNextSchoolDayBeforeCutoffOnWeekdayResolvesSameDay(): void
    {
        // Monday 2026-07-13, 08:00 — before the 10:00 cutoff.
        $publishedAt = new DateTimeImmutable('2026-07-13 08:00:00', new DateTimeZone('Europe/Amsterdam'));
        $policy      = [
            'mode'     => 'nextSchoolDay',
            'time'     => '10:00',
            'timezone' => 'Europe/Amsterdam',
        ];

        $result = $this->resolver->resolve(override: null, policy: $policy, publishedAt: $publishedAt);

        self::assertSame('2026-07-13 10:00:00', $result->format('Y-m-d H:i:s'));

    }//end testNextSchoolDayBeforeCutoffOnWeekdayResolvesSameDay()

    /**
     * A `nextSchoolDay` publish before the cutoff, but on a weekend (today is
     * not a possible school day), rolls forward to the next weekday.
     *
     * @return void
     */
    public function testNextSchoolDayBeforeCutoffOnWeekendRollsToNextWeekday(): void
    {
        // Saturday 2026-07-11, 08:00 — before the 10:00 cutoff, but a weekend.
        $publishedAt = new DateTimeImmutable('2026-07-11 08:00:00', new DateTimeZone('Europe/Amsterdam'));
        $policy      = [
            'mode'     => 'nextSchoolDay',
            'time'     => '10:00',
            'timezone' => 'Europe/Amsterdam',
        ];

        $result = $this->resolver->resolve(override: null, policy: $policy, publishedAt: $publishedAt);

        // Saturday -> Sunday -> Monday 2026-07-13.
        self::assertSame('2026-07-13 10:00:00', $result->format('Y-m-d H:i:s'));

    }//end testNextSchoolDayBeforeCutoffOnWeekendRollsToNextWeekday()

    /**
     * A `nextSchoolDay` publish after the cutoff on a weekday rolls to the
     * following day at the cutoff time.
     *
     * @return void
     */
    public function testNextSchoolDayAfterCutoffRollsToNextDay(): void
    {
        // Monday 2026-07-13, 23:40 — after the 10:00 cutoff.
        $publishedAt = new DateTimeImmutable('2026-07-13 23:40:00', new DateTimeZone('Europe/Amsterdam'));
        $policy      = [
            'mode'     => 'nextSchoolDay',
            'time'     => '10:00',
            'timezone' => 'Europe/Amsterdam',
        ];

        $result = $this->resolver->resolve(override: null, policy: $policy, publishedAt: $publishedAt);

        self::assertSame('2026-07-14 10:00:00', $result->format('Y-m-d H:i:s'));

    }//end testNextSchoolDayAfterCutoffRollsToNextDay()

    /**
     * A Friday-evening publish (after cutoff) rolls over the weekend to Monday
     * — the worked "never pings at 3 a.m., and never on a Saturday either"
     * example from the proposal.
     *
     * @return void
     */
    public function testFridayEveningPublishRollsToMonday(): void
    {
        // Friday 2026-07-10, 23:40 — after the 10:00 cutoff.
        $publishedAt = new DateTimeImmutable('2026-07-10 23:40:00', new DateTimeZone('Europe/Amsterdam'));
        $policy      = [
            'mode'     => 'nextSchoolDay',
            'time'     => '10:00',
            'timezone' => 'Europe/Amsterdam',
        ];

        $result = $this->resolver->resolve(override: null, policy: $policy, publishedAt: $publishedAt);

        self::assertSame('2026-07-13 10:00:00', $result->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Amsterdam', $result->getTimezone()->getName());

    }//end testFridayEveningPublishRollsToMonday()

    /**
     * Timezone handling: a UTC publish moment is correctly interpreted against
     * the policy's Europe/Amsterdam local time (summer = CEST, UTC+2).
     *
     * @return void
     */
    public function testTimezoneHandlingConvertsUtcPublishToLocalPolicyTime(): void
    {
        // 2026-07-10 21:40 UTC == 2026-07-10 23:40 CEST (Friday evening, after cutoff).
        $publishedAt = new DateTimeImmutable('2026-07-10 21:40:00', new DateTimeZone('UTC'));
        $policy      = [
            'mode'     => 'nextSchoolDay',
            'time'     => '10:00',
            'timezone' => 'Europe/Amsterdam',
        ];

        $result = $this->resolver->resolve(override: null, policy: $policy, publishedAt: $publishedAt);

        self::assertSame('2026-07-13 10:00:00', $result->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Amsterdam', $result->getTimezone()->getName());
        // Cross-check the absolute instant: 10:00 CEST == 08:00 UTC.
        self::assertSame('2026-07-13 08:00:00', $result->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

    }//end testTimezoneHandlingConvertsUtcPublishToLocalPolicyTime()

    /**
     * A malformed override string is treated as "no override" and falls
     * through to the CurriculumPlan policy (fail safe).
     *
     * @return void
     */
    public function testMalformedOverrideFallsThroughToPolicy(): void
    {
        $publishedAt = new DateTimeImmutable('2026-07-13 08:00:00', new DateTimeZone('Europe/Amsterdam'));
        $policy      = [
            'mode'     => 'nextSchoolDay',
            'time'     => '10:00',
            'timezone' => 'Europe/Amsterdam',
        ];

        $result = $this->resolver->resolve(override: 'not-a-date', policy: $policy, publishedAt: $publishedAt);

        self::assertSame('2026-07-13 10:00:00', $result->format('Y-m-d H:i:s'));

    }//end testMalformedOverrideFallsThroughToPolicy()

    /**
     * A missing `time`/`timezone` on a `nextSchoolDay` policy falls back to
     * the documented defaults (00:00, Europe/Amsterdam) instead of throwing.
     *
     * @return void
     */
    public function testMissingTimeAndTimezoneFallBackToDefaults(): void
    {
        // Monday 2026-07-13, 08:00 Amsterdam — before the default 00:00 cutoff, so rolls to next day.
        $publishedAt = new DateTimeImmutable('2026-07-13 08:00:00', new DateTimeZone('Europe/Amsterdam'));

        $result = $this->resolver->resolve(override: null, policy: ['mode' => 'nextSchoolDay'], publishedAt: $publishedAt);

        self::assertSame('2026-07-14 00:00:00', $result->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Amsterdam', $result->getTimezone()->getName());

    }//end testMissingTimeAndTimezoneFallBackToDefaults()
}//end class
