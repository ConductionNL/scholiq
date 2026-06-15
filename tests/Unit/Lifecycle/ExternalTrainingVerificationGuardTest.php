<?php

/**
 * Scholiq ExternalTrainingVerificationGuard unit tests.
 *
 * Covers the three verification preconditions: verifier-group membership, an
 * evidence attachment being present, and the no-self-verification rule; plus
 * the verifier stamping on success.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\ExternalTrainingVerificationGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the ExternalTrainingVerificationGuard (submitted → verified).
 */
class ExternalTrainingVerificationGuardTest extends TestCase
{
    /**
     * Build a guard whose group manager reports the given groups for the actor.
     *
     * @param array<string> $actorGroups Group IDs the actor belongs to.
     * @param bool          $actorExists Whether the user manager resolves the actor.
     *
     * @return ExternalTrainingVerificationGuard
     */
    private function makeGuard(array $actorGroups, bool $actorExists=true): ExternalTrainingVerificationGuard
    {
        $user = $this->createMock(IUser::class);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($actorExists === true ? $user : null);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('getUserGroupIds')->willReturn($actorGroups);

        return new ExternalTrainingVerificationGuard(
            $groupManager,
            $userManager,
            $this->createMock(LoggerInterface::class)
        );
    }//end makeGuard()

    /**
     * A record fixture with one evidence attachment present.
     *
     * @param string $submittedBy The submitter user ID.
     *
     * @return array<string,mixed>
     */
    private function recordWithEvidence(string $submittedBy='learner-1'): array
    {
        return [
            'id'          => 'rec-1',
            'learnerId'   => 'learner-1',
            'submittedBy' => $submittedBy,
            '@self'       => ['files' => [['name' => 'certificate.pdf']]],
        ];
    }//end recordWithEvidence()

    /**
     * Happy path: officer in a verifier group, evidence present, not self → true + stamp.
     *
     * @return void
     */
    public function testValidVerificationStampsVerifier(): void
    {
        $guard   = $this->makeGuard(['compliance-officer']);
        $context = [
            'object'  => $this->recordWithEvidence(submittedBy: 'learner-1'),
            'actor'   => 'officer-1',
            'payload' => [],
        ];

        $this->assertTrue($guard->check($context));
        $this->assertSame('officer-1', $context['payload']['verifiedBy']);
        $this->assertArrayHasKey('verifiedAt', $context['payload']);
    }//end testValidVerificationStampsVerifier()

    /**
     * Actor not in any verifier group → denied.
     *
     * @return void
     */
    public function testNonVerifierGroupDenied(): void
    {
        $guard   = $this->makeGuard(['learner']);
        $context = [
            'object'  => $this->recordWithEvidence(),
            'actor'   => 'pupil-1',
            'payload' => [],
        ];

        $this->assertFalse($guard->check($context));
        $this->assertArrayNotHasKey('verifiedBy', $context['payload']);
    }//end testNonVerifierGroupDenied()

    /**
     * No evidence attachment present → denied even for a valid verifier.
     *
     * @return void
     */
    public function testNoEvidenceAttachmentDenied(): void
    {
        $guard   = $this->makeGuard(['hr']);
        $context = [
            'object'  => ['id' => 'rec-2', 'learnerId' => 'learner-1', 'submittedBy' => 'learner-1'],
            'actor'   => 'hr-1',
            'payload' => [],
        ];

        $this->assertFalse($guard->check($context));
    }//end testNoEvidenceAttachmentDenied()

    /**
     * Self-verification (verifier == submitter) → denied.
     *
     * @return void
     */
    public function testSelfVerificationDenied(): void
    {
        $guard   = $this->makeGuard(['admin']);
        $context = [
            'object'  => $this->recordWithEvidence(submittedBy: 'officer-1'),
            'actor'   => 'officer-1',
            'payload' => [],
        ];

        $this->assertFalse($guard->check($context));
    }//end testSelfVerificationDenied()

    /**
     * Missing actor → denied.
     *
     * @return void
     */
    public function testMissingActorDenied(): void
    {
        $guard   = $this->makeGuard(['admin']);
        $context = ['object' => $this->recordWithEvidence(), 'actor' => '', 'payload' => []];

        $this->assertFalse($guard->check($context));
    }//end testMissingActorDenied()

    /**
     * Admin verifying an officer-submitted record (different person) → allowed.
     *
     * @return void
     */
    public function testAdminVerifiesOfficerSubmission(): void
    {
        $guard   = $this->makeGuard(['admin']);
        $context = [
            'object'  => $this->recordWithEvidence(submittedBy: 'officer-2'),
            'actor'   => 'admin',
            'payload' => [],
        ];

        $this->assertTrue($guard->check($context));
    }//end testAdminVerifiesOfficerSubmission()
}//end class
