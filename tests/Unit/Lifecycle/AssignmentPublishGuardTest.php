<?php

/**
 * Scholiq AssignmentPublishGuard unit tests.
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
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-publish-is-blocked-when-peerself-assessment-is-enabled-without-a-rubric
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\AssignmentPublishGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the AssignmentPublishGuard (Assignment `publish` transition).
 */
class AssignmentPublishGuardTest extends TestCase
{

    /**
     * Build the guard under test.
     *
     * @return AssignmentPublishGuard
     */
    private function guard(): AssignmentPublishGuard
    {
        return new AssignmentPublishGuard($this->createMock(LoggerInterface::class));
    }//end guard()

    /**
     * Pre-existing behaviour: no courseId and no sessionId blocks publish.
     *
     * @return void
     */
    public function testNoCourseOrSessionBlocksPublish(): void
    {
        $guard   = $this->guard();
        $context = ['object' => ['id' => 'a-1']];

        self::assertFalse($guard->check($context));
    }//end testNoCourseOrSessionBlocksPublish()

    /**
     * Pre-existing behaviour: a courseId alone allows publish (when peer/self
     * assessment is not enabled).
     *
     * @return void
     */
    public function testCourseIdAllowsPublish(): void
    {
        $guard   = $this->guard();
        $context = ['object' => ['id' => 'a-1', 'courseId' => 'course-1']];

        self::assertTrue($guard->check($context));
    }//end testCourseIdAllowsPublish()

    /**
     * Pre-existing behaviour: a sessionId alone allows publish.
     *
     * @return void
     */
    public function testSessionIdAllowsPublish(): void
    {
        $guard   = $this->guard();
        $context = ['object' => ['id' => 'a-1', 'sessionId' => 'session-1']];

        self::assertTrue($guard->check($context));
    }//end testSessionIdAllowsPublish()

    /**
     * peer-and-self-assessment: peerReviewEnabled without rubricId blocks publish.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-publish-is-blocked-when-peerself-assessment-is-enabled-without-a-rubric
     */
    public function testPeerReviewEnabledWithoutRubricBlocksPublish(): void
    {
        $guard   = $this->guard();
        $context = [
            'object' => [
                'id'                => 'a-1',
                'courseId'          => 'course-1',
                'peerReviewEnabled' => true,
                'rubricId'          => null,
            ],
        ];

        self::assertFalse($guard->check($context));
    }//end testPeerReviewEnabledWithoutRubricBlocksPublish()

    /**
     * peer-and-self-assessment: selfAssessmentEnabled without rubricId blocks publish.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-publish-is-blocked-when-peerself-assessment-is-enabled-without-a-rubric
     */
    public function testSelfAssessmentEnabledWithoutRubricBlocksPublish(): void
    {
        $guard   = $this->guard();
        $context = [
            'object' => [
                'id'                    => 'a-1',
                'courseId'              => 'course-1',
                'selfAssessmentEnabled' => true,
                'rubricId'              => null,
            ],
        ];

        self::assertFalse($guard->check($context));
    }//end testSelfAssessmentEnabledWithoutRubricBlocksPublish()

    /**
     * peer-and-self-assessment: peerReviewEnabled WITH rubricId set allows publish.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-publish-is-blocked-when-peerself-assessment-is-enabled-without-a-rubric
     */
    public function testPeerReviewEnabledWithRubricAllowsPublish(): void
    {
        $guard   = $this->guard();
        $context = [
            'object' => [
                'id'                => 'a-1',
                'courseId'          => 'course-1',
                'peerReviewEnabled' => true,
                'rubricId'          => 'rubric-1',
            ],
        ];

        self::assertTrue($guard->check($context));
    }//end testPeerReviewEnabledWithRubricAllowsPublish()

    /**
     * peer-and-self-assessment: both peer/self assessment disabled and rubricId
     * unset still allows publish — the existing (unrelated) behaviour is unaffected.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-publish-is-blocked-when-peerself-assessment-is-enabled-without-a-rubric
     */
    public function testBothDisabledAllowsPublishWithoutRubric(): void
    {
        $guard   = $this->guard();
        $context = [
            'object' => [
                'id'                    => 'a-1',
                'courseId'              => 'course-1',
                'peerReviewEnabled'     => false,
                'selfAssessmentEnabled' => false,
                'rubricId'              => null,
            ],
        ];

        self::assertTrue($guard->check($context));
    }//end testBothDisabledAllowsPublishWithoutRubric()
}//end class
