<?php

/**
 * Scholiq ReportCardFinaliseGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-finalise-is-blocked-without-a-mentor-comment
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\ReportCardFinaliseGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportCardFinaliseGuard (rapportvergadering-review -> finalised).
 */
class ReportCardFinaliseGuardTest extends TestCase
{

    /**
     * Build a guard with a mocked logger.
     *
     * @return ReportCardFinaliseGuard
     */
    private function makeGuard(): ReportCardFinaliseGuard
    {
        return new ReportCardFinaliseGuard($this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * mentorComment set and at least one subjectGrades row -> allowed.
     *
     * @return void
     */
    public function testCommentAndSubjectsAllowsFinalise(): void
    {
        $guard   = $this->makeGuard();
        $context = [
            'object' => [
                'id'             => 'card-1',
                'mentorComment'  => 'Goed gedaan dit rapport.',
                'subjectGrades'  => [['curriculumPlanId' => 'plan-1']],
            ],
        ];

        self::assertTrue($guard->check($context));

    }//end testCommentAndSubjectsAllowsFinalise()

    /**
     * Missing mentorComment (unset) blocks finalise.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-finalise-is-blocked-without-a-mentor-comment
     */
    public function testMissingMentorCommentBlocksFinalise(): void
    {
        $guard   = $this->makeGuard();
        $context = ['object' => ['id' => 'card-1', 'subjectGrades' => [['curriculumPlanId' => 'plan-1']]]];

        self::assertFalse($guard->check($context));

    }//end testMissingMentorCommentBlocksFinalise()

    /**
     * A blank/whitespace-only mentorComment blocks finalise.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-finalise-is-blocked-without-a-mentor-comment
     */
    public function testBlankMentorCommentBlocksFinalise(): void
    {
        $guard   = $this->makeGuard();
        $context = [
            'object' => [
                'id'            => 'card-1',
                'mentorComment' => '   ',
                'subjectGrades' => [['curriculumPlanId' => 'plan-1']],
            ],
        ];

        self::assertFalse($guard->check($context));

    }//end testBlankMentorCommentBlocksFinalise()

    /**
     * An empty subjectGrades array blocks finalise even with a comment.
     *
     * @return void
     */
    public function testEmptySubjectGradesBlocksFinalise(): void
    {
        $guard   = $this->makeGuard();
        $context = ['object' => ['id' => 'card-1', 'mentorComment' => 'Prima.', 'subjectGrades' => []]];

        self::assertFalse($guard->check($context));

    }//end testEmptySubjectGradesBlocksFinalise()
}//end class
