<?php

/**
 * Scholiq Learning Record Import Service
 *
 * OR lifecycle guard for `LearningRecordImport`'s `parse` transition.
 * Reads the raw uploaded bundle bytes from nc:files (via `sourceRef`, set by
 * `LearningRecordImportController` before the transition fires), recognises
 * `sourceFormat: scholiq-learning-record` (this capability's own
 * `LearningRecordExportService` bundle shape) and `sourceFormat:
 * elm-europass` (a bare ELM/Europass credential set with no scholiqNative
 * section), attempts signature verification against the IMPORTING tenant's
 * own key only (the only "key this tenant recognises" this app can actually
 * check — there is no federation/partner-key registry, by design; see
 * design.md Non-Goals), and populates `entries[]`/`verificationStatus`.
 * MUST NOT write to any schema other than `LearningRecordImport` itself —
 * an institution's academic judgment about what a prior record is worth is
 * a human decision made through the existing `ExemptionCase` mechanism, not
 * automated here (design.md "record, don't adjudicate").
 *
 * Legitimate PHP per ADR-031 "External-format import" — the same exception
 * category `QtiImportService`/`CoursePackageImportService` already occupy.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Guards `LearningRecordImport`'s `parse` transition and parses the
 * uploaded bundle into an evidence-only coverage report.
 *
 * @spec openspec/changes/portable-learning-record/tasks.md#task-4-1
 */
class LearningRecordImportService
{

    /**
     * Top-level keys expected in an own-format (`scholiq-learning-record`)
     * bundle's `scholiqNative` section, mapped to the schema slug each
     * collection reports on — mirrors
     * `LearningRecordAggregationService::compose()`'s own collection keys.
     */
    private const SCHOLIQ_NATIVE_COLLECTION_SCHEMA = [
        'enrolments'              => 'enrolment',
        'finalGrades'             => 'final-grade',
        'competencyAttainments'   => 'competency-attainment',
        'credentials'             => 'credential',
        'portfolios'              => 'portfolio',
        'portfolioEntries'        => 'portfolio-entry',
        'externalTrainingRecords' => 'external-training-record',
        'bpvPlacements'           => 'bpv-placement',
        'werkprocesAssessments'   => 'werkproces-assessment',
        'lessonCompletions'       => 'lesson-completion',
        'reportCards'             => 'report-card',
    ];

    /**
     * Constructor.
     *
     * @param LearningRecordExportSigningService $signingService Bundle canonicalisation + JWS verification.
     * @param IRootFolder                        $rootFolder     NC root folder for reading the uploaded bytes.
     * @param LoggerInterface                    $logger         PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly LearningRecordExportSigningService $signingService,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point for `LearningRecordImport`'s `parse`
     * transition.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the LearningRecordImport data array
     *                                               - 'transition' : 'parse'
     *                                               - 'from'       : 'uploaded'
     *                                               - 'to'         : 'parsed'
     *
     * @return bool True when the bundle was parsed (even when `unrecognized`/`unverifiable` outcomes
     *              resulted — those are honest, non-error results); false blocks the transition only
     *              when the uploaded content could not be parsed as JSON at all.
     *
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-coordinator-uploads-a-prior-scholiq-export-during-intake-and-sees-a-verified-coverage-report
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-an-unrecognisable-file-fails-closed-without-partial-data
     */
    public function check(array &$transitionContext): bool
    {
        $object = &$transitionContext['object'];

        $sourceRef    = (string) ($object['sourceRef'] ?? '');
        $uploadedBy   = (string) ($object['uploadedBy'] ?? '');
        $sourceFormat = (string) ($object['sourceFormat'] ?? '');
        $tenantId     = (string) ($object['tenant_id'] ?? '');

        $raw = $this->readSourceBytes(sourceRef: $sourceRef, ownerUid: $uploadedBy);
        if ($raw === null) {
            $object['errorMessage'] = 'Could not read the uploaded bundle.';
            $object['entries']      = [];
            return false;
        }

        $decoded = json_decode($raw, associative: true);
        if (is_array($decoded) === false) {
            $object['errorMessage'] = 'Uploaded file is not valid JSON.';
            $object['entries']      = [];
            return false;
        }

        if ($sourceFormat === 'scholiq-learning-record') {
            $this->parseScholiqLearningRecord(decoded: $decoded, tenantId: $tenantId, object: $object);
            return true;
        }

        if ($sourceFormat === 'elm-europass') {
            $this->parseElmEuropass(decoded: $decoded, object: $object);
            return true;
        }

        $object['errorMessage'] = 'Unrecognised sourceFormat: '.$sourceFormat;
        $object['entries']      = [];
        return false;
    }//end check()

    /**
     * Parse an own-format `scholiq-learning-record` bundle: one `entries[]`
     * row per collection found in its `scholiqNative` section plus each
     * `elm[]` credential, all `recognized` — this app produced the shape, so
     * it always recognises its own schema names. Verification checks the
     * embedded `proof.jws` against the IMPORTING tenant's own key only.
     *
     * @param array<string,mixed> $decoded  The decoded bundle JSON.
     * @param string              $tenantId Importing tenant's UUID (the only key this app can check against).
     * @param array<string,mixed> $object   The LearningRecordImport transition-context object, by reference.
     *
     * @return void
     */
    private function parseScholiqLearningRecord(array $decoded, string $tenantId, array &$object): void
    {
        $entries = [];

        $scholiqNative = $decoded['scholiqNative'] ?? [];
        if (is_array($scholiqNative) === true) {
            foreach (self::SCHOLIQ_NATIVE_COLLECTION_SCHEMA as $collectionKey => $schema) {
                $rows = $scholiqNative[$collectionKey] ?? [];
                if (is_array($rows) === false) {
                    continue;
                }

                foreach ($rows as $row) {
                    $rowData = [];
                    if (is_array($row) === true) {
                        $rowData = $row;
                    }

                    $entries[] = [
                        'sourceSchema' => $schema,
                        'sourceTitle'  => $this->rowTitle(schema: $schema, row: $rowData),
                        'outcome'      => 'recognized',
                        'reason'       => null,
                    ];
                }
            }
        }//end if

        $elm = $decoded['elm'] ?? [];
        if (is_array($elm) === true) {
            foreach ($elm as $credentialEntry) {
                $kind = 'credential';
                if (is_array($credentialEntry) === true) {
                    $kind = $credentialEntry['kind'] ?? 'credential';
                }

                $entries[] = [
                    'sourceSchema' => 'credential',
                    'sourceTitle'  => 'Credential ('.$kind.')',
                    'outcome'      => 'recognized',
                    'reason'       => null,
                ];
            }
        }

        $object['entries']            = $entries;
        $object['issuerDid']          = $decoded['issuerDid'] ?? null;
        $object['verificationStatus'] = $this->verifyAgainstOwnTenant(decoded: $decoded, tenantId: $tenantId);
        $object['errorMessage']       = null;
    }//end parseScholiqLearningRecord()

    /**
     * Parse a bare `elm-europass` credential set: a plain array (or
     * `{credentials: [...]}`) of ELM-shaped credential objects with no
     * `scholiqNative` counterpart, so every entry's `sourceSchema` is null.
     * This app builds no generic third-party ELM/JOSE verifier
     * (out of scope), so verification always reads `unverifiable` — the
     * expected, honest default for a genuinely foreign format.
     *
     * @param array<string,mixed> $decoded The decoded bundle JSON.
     * @param array<string,mixed> $object  The LearningRecordImport transition-context object, by reference.
     *
     * @return void
     */
    private function parseElmEuropass(array $decoded, array &$object): void
    {
        $credentialList = $decoded;
        if (isset($decoded['credentials']) === true && is_array($decoded['credentials']) === true) {
            $credentialList = $decoded['credentials'];
        }

        $entries = [];
        $index   = 0;
        foreach ($credentialList as $credential) {
            $index++;
            $title = 'ELM credential '.$index;
            if (is_array($credential) === true) {
                $name = $credential['credentialSubject']['achievement']['name'] ?? ($credential['name'] ?? null);
                if (is_string($name) === true && $name !== '') {
                    $title = $name;
                }
            }

            $entries[] = [
                'sourceSchema' => null,
                'sourceTitle'  => $title,
                'outcome'      => 'recognized',
                'reason'       => null,
            ];
        }

        $object['entries']            = $entries;
        $object['issuerDid']          = $decoded['issuer']['id'] ?? ($decoded['issuerDid'] ?? null);
        $object['verificationStatus'] = 'unverifiable';
        $object['errorMessage']       = null;
    }//end parseElmEuropass()

    /**
     * Attempt signature verification against the importing tenant's own
     * key only — the only key "this tenant recognises" without a
     * federation/partner-key registry (not built; design.md Non-Goals).
     *
     * @param array<string,mixed> $decoded  The decoded bundle JSON (with its embedded `proof`).
     * @param string              $tenantId Importing tenant's UUID.
     *
     * @return string `verified`, `unverifiable`, or `invalid`.
     */
    private function verifyAgainstOwnTenant(array $decoded, string $tenantId): string
    {
        $jws = $decoded['proof']['jws'] ?? null;
        if (is_string($jws) === false || $jws === '') {
            return 'unverifiable';
        }

        $ownIssuerDid    = $this->signingService->resolveIssuerDid(tenantId: $tenantId);
        $bundleIssuerDid = $decoded['issuerDid'] ?? null;

        if ($ownIssuerDid === null || $bundleIssuerDid !== $ownIssuerDid) {
            // A different (or unresolvable) issuer — the expected case for a
            // genuinely foreign system or a different, unconnected Scholiq
            // tenant. Not an error.
            return 'unverifiable';
        }

        $verified = $this->signingService->verify(jws: $jws, bundle: $decoded, tenantId: $tenantId);
        if ($verified === true) {
            return 'verified';
        }

        return 'invalid';
    }//end verifyAgainstOwnTenant()

    /**
     * Resolve a human-readable title for one scholiqNative row, mirroring
     * `LearningRecordExportService::resolveSourceTitle()`.
     *
     * @param string              $schema Schema slug.
     * @param array<string,mixed> $row    The source row.
     *
     * @return string
     */
    private function rowTitle(string $schema, array $row): string
    {
        return match ($schema) {
            'credential' => 'Credential ('.($row['kind'] ?? 'unknown').')',
            'portfolio', 'portfolio-entry', 'external-training-record' => (string) ($row['title'] ?? $schema),
            'bpv-placement' => (string) ($row['leerbedrijfName'] ?? 'BPV placement'),
            'werkproces-assessment' => (string) ($row['werkprocesLabel'] ?? 'Werkproces assessment'),
            'lesson-completion' => 'Lesson completions — course '.($row['courseId'] ?? 'unknown'),
            default => $schema,
        };
    }//end rowTitle()

    /**
     * Read the raw uploaded bundle bytes from nc:files.
     *
     * @param string $sourceRef nc:files path (relative to the owner's home).
     * @param string $ownerUid  Nextcloud user id who owns the uploaded file.
     *
     * @return string|null The raw bytes, or null when unresolvable.
     */
    private function readSourceBytes(string $sourceRef, string $ownerUid): ?string
    {
        if ($sourceRef === '' || $ownerUid === '') {
            return null;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($ownerUid);
            $node       = $userFolder->get(ltrim($sourceRef, '/'));
            if (($node instanceof \OCP\Files\File) === false) {
                return null;
            }

            return $node->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[LearningRecordImportService] Could not read uploaded bundle at "{ref}": {msg}',
                ['ref' => $sourceRef, 'msg' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end readSourceBytes()
}//end class
