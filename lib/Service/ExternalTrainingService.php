<?php

/**
 * Scholiq External Training Service
 *
 * Business logic for externally-completed training records: the compliance
 * coverage predicate (which now counts verified external records), bulk
 * classroom-session entry, and optional manual Credential issuance on
 * verification.
 *
 * Per ADR-022 all persistence goes through OpenRegister's ObjectService; this
 * service holds no local store. Per ADR-008 OR's lifecycle engine and audit
 * trail record creation/transition events automatically — this service does not
 * write audit entries.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 *
 * @spec openspec/changes/external-training-recording/tasks.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Coverage, bulk-entry, and credential-issuance logic for external training.
 *
 * @spec openspec/changes/external-training-recording/tasks.md
 */
class ExternalTrainingService
{
    /**
     * OpenRegister register slug Scholiq objects live in.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Schema slug of the external-training record.
     */
    private const SCHEMA = 'external-training-record';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object query/persistence service.
     * @param LoggerInterface $logger        PSR logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether a learner is covered for a regulation by ANY evidence class.
     *
     * Coverage holds when the learner has at least one of:
     *   1. a signed Attestation for the regulation, OR
     *   2. a valid (non-expired, non-revoked) Credential for the regulation, OR
     *   3. a verified ExternalTrainingRecord for the regulation whose
     *      `validUntil` (if set) has not passed.
     *
     * The denominator (population) is unchanged by this method; it only widens
     * the numerator predicate to include verified external records.
     *
     * @param string                 $learnerId      LearnerProfile UUID.
     * @param string                 $regulationSlug Regulation slug to test.
     * @param DateTimeInterface|null $now            Evaluation instant
     *                                               (injectable for tests).
     *
     * @return bool True when the learner counts as covered.
     *
     * @spec openspec/changes/external-training-recording/tasks.md
     */
    public function isLearnerCovered(
        string $learnerId,
        string $regulationSlug,
        ?DateTimeInterface $now=null,
    ): bool {
        if ($learnerId === '' || $regulationSlug === '') {
            return false;
        }

        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

        if ($this->hasSignedAttestation(learnerId: $learnerId, regulationSlug: $regulationSlug) === true) {
            return true;
        }

        if ($this->hasValidCredential(learnerId: $learnerId, regulationSlug: $regulationSlug, now: $now) === true) {
            return true;
        }

        if ($this->hasVerifiedExternalRecord(learnerId: $learnerId, regulationSlug: $regulationSlug, now: $now) === true) {
            return true;
        }

        return false;
    }//end isLearnerCovered()

    /**
     * The evidence class that currently covers a learner for a regulation.
     *
     * Returns the strongest matching class for display in the coverage view, or
     * `null` when the learner is not covered. Precedence: attestation, then
     * credential, then external-training.
     *
     * @param string                 $learnerId      LearnerProfile UUID.
     * @param string                 $regulationSlug Regulation slug.
     * @param DateTimeInterface|null $now            Evaluation instant.
     *
     * @return string|null One of 'attestation'|'credential'|'external-training', or null.
     *
     * @spec openspec/changes/external-training-recording/tasks.md
     */
    public function coveringEvidenceClass(
        string $learnerId,
        string $regulationSlug,
        ?DateTimeInterface $now=null,
    ): ?string {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

        if ($this->hasSignedAttestation(learnerId: $learnerId, regulationSlug: $regulationSlug) === true) {
            return 'attestation';
        }

        if ($this->hasValidCredential(learnerId: $learnerId, regulationSlug: $regulationSlug, now: $now) === true) {
            return 'credential';
        }

        if ($this->hasVerifiedExternalRecord(learnerId: $learnerId, regulationSlug: $regulationSlug, now: $now) === true) {
            return 'external-training';
        }

        return null;
    }//end coveringEvidenceClass()

    /**
     * Create one external-training record for each of N learners (bulk entry).
     *
     * All records share a single `batchId` so the classroom session can be
     * verified and audited together. Each record starts in `submitted`. The
     * shared evidence attachment is uploaded once and linked per record by the
     * caller (OR file-attachment API); this method only creates the records.
     *
     * @param array<string>        $learnerIds The learners attending the session.
     * @param array<string,mixed>  $shared     Shared fields: title, provider,
     *                                          kind, completedAt, regulationSlug?,
     *                                          validUntil?, evidenceNote?,
     *                                          submittedBy, tenant_id.
     *
     * @return string The generated batchId (empty when nothing was created).
     *
     * @spec openspec/changes/external-training-recording/tasks.md
     */
    public function bulkRecord(array $learnerIds, array $shared): string
    {
        $learnerIds = array_values(array_unique(array_filter($learnerIds, static fn ($id): bool => $id !== '')));
        if (empty($learnerIds) === true) {
            return '';
        }

        $required = ['title', 'provider', 'completedAt', 'submittedBy', 'tenant_id'];
        foreach ($required as $key) {
            if (($shared[$key] ?? '') === '') {
                $this->logger->warning('[ExternalTrainingService] bulkRecord missing required field {f}.', ['f' => $key]);
                return '';
            }
        }

        $batchId = $this->generateBatchId();

        foreach ($learnerIds as $learnerId) {
            $record = [
                'learnerId'    => $learnerId,
                'title'        => (string) $shared['title'],
                'provider'     => (string) $shared['provider'],
                'kind'         => (string) ($shared['kind'] ?? 'classroom'),
                'completedAt'  => (string) $shared['completedAt'],
                'submittedBy'  => (string) $shared['submittedBy'],
                'batchId'      => $batchId,
                'tenant_id'    => (string) $shared['tenant_id'],
            ];

            if (($shared['regulationSlug'] ?? '') !== '') {
                $record['regulationSlug'] = (string) $shared['regulationSlug'];
            }

            if (($shared['validUntil'] ?? '') !== '') {
                $record['validUntil'] = (string) $shared['validUntil'];
            }

            if (($shared['evidenceNote'] ?? '') !== '') {
                $record['evidenceNote'] = (string) $shared['evidenceNote'];
            }

            // Do NOT set lifecycle — OR defaults it to 'submitted' and fires the
            // 'created' notification rule.
            $this->objectService->saveObject(
                register: self::SCHOLIQ_REGISTER,
                schema: self::SCHEMA,
                object: $record
            );
        }//end foreach

        return $batchId;
    }//end bulkRecord()

    /**
     * Build the manual Credential payload to issue on verification.
     *
     * Returns the object array to persist (via OR `source: manual`) so the
     * certification capability's existing expiry alerts and renewal auto-enrol
     * cover external certificates too. The caller persists it and writes the new
     * credential's UUID back onto the record as `credentialId`. No Credential
     * schema change is introduced.
     *
     * @param array<string,mixed> $record The verified external-training record.
     * @param string              $issuedBy NC user ID / issuer name on the credential.
     *
     * @return array<string,mixed> The Credential object payload to save.
     *
     * @spec openspec/changes/external-training-recording/tasks.md
     */
    public function buildManualCredentialPayload(array $record, string $issuedBy): array
    {
        return [
            'learnerId'      => (string) ($record['learnerId'] ?? ''),
            'kind'           => 'external-training',
            'issuedAt'       => (string) ($record['completedAt'] ?? ''),
            'expiresAt'      => ($record['validUntil'] ?? null),
            'issuedBy'       => $issuedBy,
            'source'         => 'manual',
            'regulationSlug' => ($record['regulationSlug'] ?? null),
            'tenant_id'      => (string) ($record['tenant_id'] ?? ''),
        ];
    }//end buildManualCredentialPayload()

    /**
     * Whether a signed Attestation exists for the learner + regulation.
     *
     * @param string $learnerId      LearnerProfile UUID.
     * @param string $regulationSlug Regulation slug.
     *
     * @return bool True when a signed attestation is present.
     */
    private function hasSignedAttestation(string $learnerId, string $regulationSlug): bool
    {
        $rows = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'attestation',
                'filters'  => [
                    'learnerId'      => $learnerId,
                    'regulationSlug' => $regulationSlug,
                    'lifecycle'      => 'signed',
                ],
                'limit'    => 1,
            ]
        );

        return empty($rows) === false;
    }//end hasSignedAttestation()

    /**
     * Whether a non-expired, non-revoked Credential exists for learner + regulation.
     *
     * @param string            $learnerId      LearnerProfile UUID.
     * @param string            $regulationSlug Regulation slug.
     * @param DateTimeInterface $now            Evaluation instant.
     *
     * @return bool True when a valid credential is present.
     */
    private function hasValidCredential(string $learnerId, string $regulationSlug, DateTimeInterface $now): bool
    {
        $rows = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'credential',
                'filters'  => [
                    'learnerId'      => $learnerId,
                    'regulationSlug' => $regulationSlug,
                ],
            ]
        );

        foreach ($rows as $row) {
            $cred = $this->toArray($row);

            if (($cred['lifecycle'] ?? '') === 'revoked' || ($cred['lifecycle'] ?? '') === 'expired') {
                continue;
            }

            if ($this->isExpired(value: ($cred['expiresAt'] ?? null), now: $now) === true) {
                continue;
            }

            return true;
        }

        return false;
    }//end hasValidCredential()

    /**
     * Whether a verified, unexpired ExternalTrainingRecord exists for the pair.
     *
     * @param string            $learnerId      LearnerProfile UUID.
     * @param string            $regulationSlug Regulation slug.
     * @param DateTimeInterface $now            Evaluation instant.
     *
     * @return bool True when a verified, unexpired external record is present.
     */
    private function hasVerifiedExternalRecord(string $learnerId, string $regulationSlug, DateTimeInterface $now): bool
    {
        $rows = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::SCHEMA,
                'filters'  => [
                    'learnerId'      => $learnerId,
                    'regulationSlug' => $regulationSlug,
                    'lifecycle'      => 'verified',
                ],
            ]
        );

        foreach ($rows as $row) {
            $rec = $this->toArray($row);
            if ($this->isExpired(value: ($rec['validUntil'] ?? null), now: $now) === true) {
                continue;
            }

            return true;
        }

        return false;
    }//end hasVerifiedExternalRecord()

    /**
     * Whether an optional ISO date string is in the past relative to $now.
     *
     * A null/empty value is treated as "never expires" (not expired).
     *
     * @param mixed             $value An ISO-8601 datetime string or null.
     * @param DateTimeInterface $now   Evaluation instant.
     *
     * @return bool True when the value is a date that has passed.
     */
    private function isExpired(mixed $value, DateTimeInterface $now): bool
    {
        if (is_string($value) === false || $value === '') {
            return false;
        }

        try {
            $expires = new DateTimeImmutable($value);
        } catch (\Exception) {
            // Unparseable expiry is treated as expired (fail closed for coverage).
            return true;
        }

        return $expires < $now;
    }//end isExpired()

    /**
     * Normalise an OR result row (entity or array) to a plain array.
     *
     * @param mixed $row Entity with jsonSerialize() or a plain array.
     *
     * @return array<string,mixed> The row as an associative array.
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            return (array) $row->jsonSerialize();
        }

        return [];
    }//end toArray()

    /**
     * Generate a unique batch identifier for a bulk classroom-session entry.
     *
     * @return string A UUID-like batch identifier.
     */
    private function generateBatchId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }//end generateBatchId()
}//end class
