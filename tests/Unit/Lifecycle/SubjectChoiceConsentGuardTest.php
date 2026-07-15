<?php

/**
 * Scholiq SubjectChoiceConsentGuard unit tests.
 *
 * Mirrors ConferenceSignupGuardianGuardTest — the guard's body is
 * copy-identical to ConferenceSignupGuardianGuard, reapplied to a new schema.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\SubjectChoiceConsentGuard;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the SubjectChoiceConsentGuard lifecycle guard (draft → submitted).
 */
class SubjectChoiceConsentGuardTest extends TestCase
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
     * Wire ObjectService::findAll to return the given LearnerProfile row(s) for
     * a learner-profile query, empty for everything else.
     *
     * @param array<int, array<string, mixed>> $profiles Rows to return for a learner-profile query.
     *
     * @return void
     */
    private function wireLearnerProfile(array $profiles): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($profiles) {
                if ($config['schema'] === 'learner-profile') {
                    return $profiles;
                }

                return [];
            }
        );

    }//end wireLearnerProfile()

    /**
     * Build the guard under test.
     *
     * @return SubjectChoiceConsentGuard
     */
    private function makeGuard(): SubjectChoiceConsentGuard
    {
        return new SubjectChoiceConsentGuard(
            $this->userSession,
            $this->objectService,
            $this->createMock(LoggerInterface::class),
        );

    }//end makeGuard()

    /**
     * A linked guardian (caller's uid is in LearnerProfile.parentIds) may submit.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-a-linked-guardian-can-submit-a-subject-choice-for-their-own-child
     */
    public function testLinkedGuardianCanSubmit(): void
    {
        $this->signInAs('parent-1');
        $this->wireLearnerProfile([['ncUserId' => 'learner-1', 'parentIds' => ['parent-1', 'parent-2']]]);

        $context = ['object' => ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a']];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testLinkedGuardianCanSubmit()

    /**
     * An unrelated user (neither a linked guardian nor the learner) cannot submit.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/school-structure/spec.md#scenario-an-unrelated-user-cannot-submit-a-subject-choice-for-someone-elses-child
     */
    public function testUnrelatedUserCannotSubmit(): void
    {
        $this->signInAs('stranger-1');
        $this->wireLearnerProfile([['ncUserId' => 'learner-1', 'parentIds' => ['parent-1', 'parent-2']]]);

        $context = ['object' => ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testUnrelatedUserCannotSubmit()

    /**
     * An 18+ learner submitting for themselves (caller uid === LearnerProfile.ncUserId) passes,
     * even with no parentIds linked.
     *
     * @return void
     */
    public function testSelfSubmissionPasses(): void
    {
        $this->signInAs('learner-1');
        $this->wireLearnerProfile([['ncUserId' => 'learner-1', 'parentIds' => []]]);

        $context = ['object' => ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a']];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testSelfSubmissionPasses()

    /**
     * A missing LearnerProfile fails closed.
     *
     * @return void
     */
    public function testMissingLearnerProfileFailsClosed(): void
    {
        $this->signInAs('parent-1');
        $this->wireLearnerProfile([]);

        $context = ['object' => ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testMissingLearnerProfileFailsClosed()

    /**
     * A missing learnerId on the choice fails closed without querying.
     *
     * @return void
     */
    public function testMissingLearnerIdFailsClosedWithoutQuerying(): void
    {
        $this->signInAs('parent-1');
        $this->objectService->expects(self::never())->method('findAll');

        $context = ['object' => ['tenant_id' => 'tenant-a']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testMissingLearnerIdFailsClosedWithoutQuerying()

    /**
     * No authenticated session (getUser() returns null) fails closed.
     *
     * @return void
     */
    public function testNoAuthenticatedUserFailsClosed(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->wireLearnerProfile([['ncUserId' => 'learner-1', 'parentIds' => ['parent-1']]]);

        $context = ['object' => ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a']];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testNoAuthenticatedUserFailsClosed()
}//end class
