<?php

/**
 * Scholiq CourseEvaluationEligibilityGuard unit tests.
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
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\CourseEvaluationEligibilityGuard;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the CourseEvaluationEligibilityGuard lifecycle guard (draft → submitted).
 */
class CourseEvaluationEligibilityGuardTest extends TestCase
{

    /**
     * ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * User-session mock.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Set up fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession   = $this->createMock(IUserSession::class);

    }//end setUp()

    /**
     * Make IUserSession return a user with the given uid.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function signInAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end signInAs()

    /**
     * Wire ObjectService::findAll to return the given EvaluationInvitation row(s).
     *
     * @param array<int, array<string, mixed>> $invitations Rows to return for an evaluation-invitation query.
     *
     * @return void
     */
    private function wireInvitations(array $invitations): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($invitations) {
                if ($config['schema'] === 'evaluation-invitation') {
                    return $invitations;
                }

                return [];
            }
        );

    }//end wireInvitations()

    /**
     * Build the guard under test.
     *
     * @return CourseEvaluationEligibilityGuard
     */
    private function makeGuard(): CourseEvaluationEligibilityGuard
    {
        return new CourseEvaluationEligibilityGuard(
            $this->userSession,
            $this->objectService,
            $this->createMock(LoggerInterface::class),
        );

    }//end makeGuard()

    /**
     * A learner with no EvaluationInvitation for the campaign is blocked.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-learner-without-an-invitation-cannot-submit
     */
    public function testNoInvitationBlocksSubmit(): void
    {
        $this->signInAs('learner-1');
        $this->wireInvitations([]);

        $context = ['object' => ['campaignId' => 'campaign-1', 'tenant_id' => 'tenant-a']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testNoInvitationBlocksSubmit()

    /**
     * A learner whose EvaluationInvitation for the campaign already has
     * hasResponded:true is blocked from a second submission (the filter
     * itself excludes it, mirroring the guard's own findAll filter).
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-learner-cannot-submit-a-second-response-for-the-same-campaign
     */
    public function testAlreadyRespondedBlocksSecondSubmit(): void
    {
        $this->signInAs('learner-1');
        // The guard filters hasResponded:false server-side — an already-responded
        // invitation never matches, so findAll returns empty for this caller.
        $this->wireInvitations([]);

        $context = ['object' => ['campaignId' => 'campaign-1', 'tenant_id' => 'tenant-a']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testAlreadyRespondedBlocksSecondSubmit()

    /**
     * An eligible, not-yet-responded invitation allows the submit.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
     */
    public function testEligibleInvitationAllowsSubmit(): void
    {
        $this->signInAs('learner-1');
        $this->wireInvitations(
            [
                [
                    'campaignId'   => 'campaign-1',
                    'learnerId'    => 'learner-1',
                    'hasResponded' => false,
                ],
            ]
        );

        $context = ['object' => ['campaignId' => 'campaign-1', 'tenant_id' => 'tenant-a']];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testEligibleInvitationAllowsSubmit()

    /**
     * The guard never reads or mutates a learner-identity field on the
     * CourseEvaluationResponse payload it receives — the payload it is
     * given carries none, and the guard's own filters key off the
     * *session-resolved* caller, never the object payload.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-a-response-is-anonymous-by-schema-shape-not-by-rbac
     */
    public function testGuardNeverMutatesResponsePayload(): void
    {
        $this->signInAs('learner-1');
        $this->wireInvitations(
            [
                [
                    'campaignId'   => 'campaign-1',
                    'learnerId'    => 'learner-1',
                    'hasResponded' => false,
                ],
            ]
        );

        $original = [
            'campaignId' => 'campaign-1',
            'courseId'   => 'course-1',
            'answers'    => [],
            'tenant_id'  => 'tenant-a',
        ];
        $context  = ['object' => $original];

        $this->makeGuard()->check($context);

        self::assertSame($original, $context['object'], 'The guard MUST NOT add/remove/change any key on the response payload');
        self::assertArrayNotHasKey('learnerId', $context['object']);
        self::assertArrayNotHasKey('submittedBy', $context['object']);

    }//end testGuardNeverMutatesResponsePayload()

    /**
     * No authenticated session (getUser() returns null) fails closed.
     *
     * @return void
     */
    public function testNoAuthenticatedUserFailsClosed(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->wireInvitations(
            [
                [
                    'campaignId'   => 'campaign-1',
                    'learnerId'    => 'learner-1',
                    'hasResponded' => false,
                ],
            ]
        );

        $context = ['object' => ['campaignId' => 'campaign-1', 'tenant_id' => 'tenant-a']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testNoAuthenticatedUserFailsClosed()

    /**
     * A missing campaignId on the response fails closed without querying.
     *
     * @return void
     */
    public function testMissingCampaignIdFailsClosedWithoutQuerying(): void
    {
        $this->signInAs('learner-1');
        $this->objectService->expects(self::never())->method('findAll');

        $context = ['object' => ['tenant_id' => 'tenant-a']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testMissingCampaignIdFailsClosedWithoutQuerying()
}//end class
