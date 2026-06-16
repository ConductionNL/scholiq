<?php

/**
 * Scholiq ExternalTrainingService unit tests.
 *
 * Covers the compliance coverage predicate across all three evidence classes
 * (signed Attestation, valid Credential, verified unexpired external record),
 * the expiry behaviour, bulk classroom-session entry, and the manual-credential
 * payload builder.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Service
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

namespace OCA\Scholiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\ExternalTrainingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ExternalTrainingService.
 */
class ExternalTrainingServiceTest extends TestCase
{
    /**
     * Evaluation instant used across coverage tests.
     */
    private const NOW = '2026-06-15T12:00:00Z';

    /**
     * Build a service whose ObjectService::findAll responds based on the schema
     * in the query, using the provided per-schema result map.
     *
     * @param array<string,array<int,array<string,mixed>>> $bySchema Map of
     *        schema slug => rows returned for that schema.
     *
     * @return ExternalTrainingService
     */
    private function serviceReturning(array $bySchema): ExternalTrainingService
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $query) use ($bySchema): array {
                $schema = $query['schema'] ?? '';
                return $bySchema[$schema] ?? [];
            }
        );

        return new ExternalTrainingService($objectService, $this->createMock(LoggerInterface::class));
    }//end serviceReturning()

    /**
     * A signed attestation alone covers the learner.
     *
     * @return void
     */
    public function testSignedAttestationCovers(): void
    {
        $svc = $this->serviceReturning([
            'attestation' => [['lifecycle' => 'signed']],
        ]);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertTrue($svc->isLearnerCovered('learner-1', 'NIS2', $now));
        $this->assertSame('attestation', $svc->coveringEvidenceClass('learner-1', 'NIS2', $now));
    }//end testSignedAttestationCovers()

    /**
     * A valid (unexpired) credential covers the learner.
     *
     * @return void
     */
    public function testValidCredentialCovers(): void
    {
        $svc = $this->serviceReturning([
            'credential' => [['lifecycle' => 'issued', 'expiresAt' => '2027-01-01T00:00:00Z']],
        ]);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertTrue($svc->isLearnerCovered('learner-1', 'NIS2', $now));
        $this->assertSame('credential', $svc->coveringEvidenceClass('learner-1', 'NIS2', $now));
    }//end testValidCredentialCovers()

    /**
     * An expired credential does NOT cover the learner.
     *
     * @return void
     */
    public function testExpiredCredentialDoesNotCover(): void
    {
        $svc = $this->serviceReturning([
            'credential' => [['lifecycle' => 'issued', 'expiresAt' => '2025-01-01T00:00:00Z']],
        ]);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertFalse($svc->isLearnerCovered('learner-1', 'NIS2', $now));
        $this->assertNull($svc->coveringEvidenceClass('learner-1', 'NIS2', $now));
    }//end testExpiredCredentialDoesNotCover()

    /**
     * A verified, unexpired external record covers the learner.
     *
     * @return void
     */
    public function testVerifiedExternalRecordCovers(): void
    {
        $svc = $this->serviceReturning([
            'external-training-record' => [['lifecycle' => 'verified', 'validUntil' => '2027-03-10T00:00:00Z']],
        ]);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertTrue($svc->isLearnerCovered('learner-1', 'NIS2', $now));
        $this->assertSame('external-training', $svc->coveringEvidenceClass('learner-1', 'NIS2', $now));
    }//end testVerifiedExternalRecordCovers()

    /**
     * A verified external record with no validUntil never expires → covers.
     *
     * @return void
     */
    public function testVerifiedExternalRecordWithoutExpiryCovers(): void
    {
        $svc = $this->serviceReturning([
            'external-training-record' => [['lifecycle' => 'verified']],
        ]);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertTrue($svc->isLearnerCovered('learner-1', 'NIS2', $now));
    }//end testVerifiedExternalRecordWithoutExpiryCovers()

    /**
     * An expired external record does NOT cover the learner.
     *
     * @return void
     */
    public function testExpiredExternalRecordDoesNotCover(): void
    {
        $svc = $this->serviceReturning([
            'external-training-record' => [['lifecycle' => 'verified', 'validUntil' => '2026-03-10T00:00:00Z']],
        ]);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertFalse($svc->isLearnerCovered('learner-1', 'NIS2', $now));
    }//end testExpiredExternalRecordDoesNotCover()

    /**
     * A submitted (unverified) external record does NOT cover the learner —
     * the filter only matches `verified` records, so an unverified one is never
     * returned by the query and thus never counts.
     *
     * @return void
     */
    public function testUnverifiedExternalRecordDoesNotCover(): void
    {
        // The query filters lifecycle=verified, so an unverified record is not
        // returned; modelling that here as an empty result set.
        $svc = $this->serviceReturning([
            'external-training-record' => [],
        ]);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertFalse($svc->isLearnerCovered('learner-1', 'NIS2', $now));
    }//end testUnverifiedExternalRecordDoesNotCover()

    /**
     * No evidence at all → not covered, null evidence class.
     *
     * @return void
     */
    public function testNoEvidenceNotCovered(): void
    {
        $svc = $this->serviceReturning([]);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertFalse($svc->isLearnerCovered('learner-1', 'NIS2', $now));
        $this->assertNull($svc->coveringEvidenceClass('learner-1', 'NIS2', $now));
    }//end testNoEvidenceNotCovered()

    /**
     * Empty inputs are never covered.
     *
     * @return void
     */
    public function testEmptyInputsNotCovered(): void
    {
        $svc = $this->serviceReturning([]);

        $this->assertFalse($svc->isLearnerCovered('', 'NIS2'));
        $this->assertFalse($svc->isLearnerCovered('learner-1', ''));
    }//end testEmptyInputsNotCovered()

    /**
     * Bulk entry creates one record per unique learner sharing one batchId.
     *
     * @return void
     */
    public function testBulkRecordCreatesOnePerLearnerWithSharedBatch(): void
    {
        $saved = [];
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->exactly(2))
            ->method('saveObject')
            ->willReturnCallback(
                static function (string $register, string $schema, array $object) use (&$saved): array {
                    $saved[] = $object;
                    return $object;
                }
            );

        $svc = new ExternalTrainingService($objectService, $this->createMock(LoggerInterface::class));

        $batchId = $svc->bulkRecord(
            ['learner-1', 'learner-2', 'learner-1'],
            [
                'title'          => 'NIS2 classroom session',
                'provider'       => 'SecureBoard',
                'kind'           => 'classroom',
                'completedAt'    => '2026-06-10T10:00:00Z',
                'regulationSlug' => 'NIS2',
                'submittedBy'    => 'officer-1',
                'tenant_id'      => 'tenant-a',
            ]
        );

        $this->assertNotSame('', $batchId);
        $this->assertCount(2, $saved, 'duplicate learner ids are de-duplicated');
        $this->assertSame($batchId, $saved[0]['batchId']);
        $this->assertSame($batchId, $saved[1]['batchId']);
        $this->assertArrayNotHasKey('lifecycle', $saved[0], 'lifecycle is left for OR to default to submitted');
        $this->assertSame('NIS2', $saved[0]['regulationSlug']);
    }//end testBulkRecordCreatesOnePerLearnerWithSharedBatch()

    /**
     * Bulk entry refuses (returns empty batch, no saves) on a missing required field.
     *
     * @return void
     */
    public function testBulkRecordRefusesWithoutRequiredFields(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('saveObject');

        $svc = new ExternalTrainingService($objectService, $this->createMock(LoggerInterface::class));

        $batchId = $svc->bulkRecord(
            ['learner-1'],
            ['title' => 'x', 'provider' => 'y'] // missing completedAt/submittedBy/tenant_id
        );

        $this->assertSame('', $batchId);
    }//end testBulkRecordRefusesWithoutRequiredFields()

    /**
     * The manual-credential payload maps validUntil → expiresAt and uses source manual.
     *
     * @return void
     */
    public function testBuildManualCredentialPayload(): void
    {
        $svc = $this->serviceReturning([]);

        $payload = $svc->buildManualCredentialPayload(
            [
                'learnerId'      => 'learner-1',
                'completedAt'    => '2026-06-10T10:00:00Z',
                'validUntil'     => '2027-06-10T10:00:00Z',
                'regulationSlug' => 'NIS2',
                'tenant_id'      => 'tenant-a',
            ],
            'officer-1'
        );

        $this->assertSame('learner-1', $payload['learnerId']);
        $this->assertSame('manual', $payload['source']);
        $this->assertSame('2027-06-10T10:00:00Z', $payload['expiresAt'], 'validUntil maps to expiresAt');
        $this->assertSame('2026-06-10T10:00:00Z', $payload['issuedAt']);
        $this->assertSame('NIS2', $payload['regulationSlug']);
    }//end testBuildManualCredentialPayload()
}//end class
