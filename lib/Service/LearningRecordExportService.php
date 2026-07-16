<?php

/**
 * Scholiq Learning Record Export Service
 *
 * OR lifecycle guard for `LearningRecordExport`'s `generate` transition.
 * Reads `LearningRecordAggregationService::compose()`'s live composition,
 * splits it into an ELM/Europass-shaped `elm` section (per in-scope
 * Credential: `edciPayload` when non-empty else `openbadges3Payload`,
 * verbatim — the identical fallback `WalletOfferDelegationService
 * ::buildOfferRequest()` already uses) and a lossless `scholiqNative`
 * section, populates `coverageReport[]` (mirroring
 * `CoursePackageImportReport.entries` verbatim, applied to output instead
 * of input), stores the bundle as an nc:files attachment (`bundleRef`), and
 * delegates signing to `LearningRecordExportSigningService`.
 *
 * Fails closed: any failure sets `errorMessage` and blocks the transition —
 * never leaves partial bundle state, the identical shape
 * `WalletOfferDelegationService::check()`/`CredentialSigningService
 * ::check()` already establish.
 *
 * Legitimate PHP per ADR-031 "Lifecycle guard" — referenced from
 * `LearningRecordExport`'s `x-openregister-lifecycle.transitions.generate
 * .requires` in scholiq_register.json.
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
 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Guards `LearningRecordExport`'s `generate` transition and assembles the
 * signed dual-shaped bundle.
 *
 * @spec openspec/changes/portable-learning-record/tasks.md#task-2-3
 */
class LearningRecordExportService
{

    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Schemas whose per-item timestamp is unambiguous enough to apply
     * periodFrom/periodTo narrowing against — mapped to the field name
     * holding that timestamp. Every other composed collection (Enrolment,
     * FinalGrade, CompetencyAttainment, Portfolio/PortfolioEntry,
     * BpvPlacement, the LessonCompletion per-course summary) is a
     * container/roll-up with no single comparable "when this evidence
     * happened" instant, so period narrowing is deliberately NOT applied to
     * those — they are always `included`, never silently dropped for lack
     * of a date to compare.
     */
    private const PERIOD_FIELD_BY_SCHEMA = [
        'credential'               => 'issuedAt',
        'external-training-record' => 'completedAt',
        'werkproces-assessment'    => 'assessedAt',
        'report-card'              => 'composedAt',
    ];

    /**
     * Staff professional-judgment schemas explicitly OUT of
     * LearningRecordAggregationService's scope. Reported in coverageReport[]
     * as `omitted` (never included) whenever a row exists for the learner
     * within the requested period, so the exclusion boundary is visible, not
     * silent — design.md "What the learner controls vs. what stays
     * institutional".
     */
    private const EXCLUDED_SCHEMA_DATE_FIELD = [
        'dossier-note'       => 'date',
        'behaviour-incident' => 'occurredAt',
        'wellbeing-check-in' => 'submittedAt',
    ];

    /**
     * Constructor.
     *
     * @param LearningRecordAggregationService   $aggregationService Cross-schema composition.
     * @param LearningRecordExportSigningService $signingService     Bundle canonicalisation + signing.
     * @param ObjectService                      $objectService      OR object read service (excluded-schema lookups).
     * @param IRootFolder                        $rootFolder         NC root folder for writing the signed bundle.
     * @param LoggerInterface                    $logger             PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly LearningRecordAggregationService $aggregationService,
        private readonly LearningRecordExportSigningService $signingService,
        private readonly ObjectService $objectService,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point for `LearningRecordExport`'s `generate`
     * transition.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the LearningRecordExport data array
     *                                               - 'transition' : 'generate'
     *                                               - 'from'       : 'requested'
     *                                               - 'to'         : 'generated'
     *
     * @return bool True when the bundle was assembled and signed; false blocks the transition.
     *
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-generation-fails-closed-and-blocks-the-transition-on-error
     */
    public function check(array &$transitionContext): bool
    {
        $object = &$transitionContext['object'];

        $learnerRef  = (string) ($object['learnerRef'] ?? '');
        $learnerId   = (string) ($object['learnerId'] ?? '');
        $requestedBy = (string) ($object['requestedBy'] ?? '');
        $tenantId    = (string) ($object['tenant_id'] ?? '');
        $periodFrom  = $object['periodFrom'] ?? null;
        $periodTo    = $object['periodTo'] ?? null;

        if ($learnerRef === '' || $tenantId === '') {
            $object['errorMessage'] = 'Missing learnerRef or tenant_id — cannot compose a record to export.';
            return false;
        }

        $composition = $this->aggregationService->compose(learnerRef: $learnerRef);

        $coverageReport = [];
        $scholiqNative  = [];

        foreach ($composition as $collectionKey => $rows) {
            $schema = $this->schemaForCollectionKey(key: $collectionKey);

            if ($collectionKey === 'lessonCompletions') {
                // Already a per-course summary — always `summarized`, never
                // period-filtered (the summary spans the whole enrolment).
                foreach ($rows as $row) {
                    $coverageReport[] = [
                        'sourceSchema' => 'lesson-completion',
                        'sourceId'     => (string) ($row['courseId'] ?? ''),
                        'sourceTitle'  => 'Lesson completions — course '.($row['courseId'] ?? 'unknown'),
                        'outcome'      => 'summarized',
                        'reason'       => 'Summarized per course (count + percentage) — the raw per-lesson log is never exported.',
                    ];
                }

                $scholiqNative[$collectionKey] = $rows;
                continue;
            }

            $dateField    = self::PERIOD_FIELD_BY_SCHEMA[$schema] ?? null;
            $includedRows = [];
            foreach ($rows as $row) {
                $inPeriod = $this->isWithinPeriod(row: $row, dateField: $dateField, periodFrom: $periodFrom, periodTo: $periodTo);

                if ($inPeriod === false) {
                    $coverageReport[] = [
                        'sourceSchema' => $schema,
                        'sourceId'     => (string) ($row['id'] ?? ($row['uuid'] ?? '')),
                        'sourceTitle'  => $this->resolveSourceTitle(schema: $schema, row: $row),
                        'outcome'      => 'omitted',
                        'reason'       => 'Outside the requested export period.',
                    ];
                    continue;
                }

                $includedRows[]   = $row;
                $coverageReport[] = [
                    'sourceSchema' => $schema,
                    'sourceId'     => (string) ($row['id'] ?? ($row['uuid'] ?? '')),
                    'sourceTitle'  => $this->resolveSourceTitle(schema: $schema, row: $row),
                    'outcome'      => 'included',
                    'reason'       => null,
                ];
            }//end foreach

            $scholiqNative[$collectionKey] = $includedRows;
        }//end foreach

        // Name the staff professional-judgment records that fall within the
        // requested scope but are deliberately never included — visible, not
        // silent, per design.md.
        foreach ($this->findExcludedRowsInScope(learnerId: $learnerId, periodFrom: $periodFrom, periodTo: $periodTo) as $excludedEntry) {
            $coverageReport[] = $excludedEntry;
        }

        $elm = $this->buildElmSection(credentials: $scholiqNative['credentials'] ?? []);

        $issuerDid = $this->signingService->resolveIssuerDid(tenantId: $tenantId);
        if ($issuerDid === null) {
            $object['errorMessage'] = 'No signing key configured for this tenant — an admin must generate one before an export can be signed.';
            return false;
        }

        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $bundle = [
            'bundleType'    => 'scholiq-learning-record',
            'issuerDid'     => $issuerDid,
            'generatedAt'   => $generatedAt,
            'learnerRef'    => $learnerRef,
            'periodFrom'    => $periodFrom,
            'periodTo'      => $periodTo,
            'elm'           => $elm,
            'scholiqNative' => $scholiqNative,
        ];

        $jws = $this->signingService->sign(bundle: $bundle, tenantId: $tenantId);
        if ($jws === null) {
            $object['errorMessage'] = 'Signing the export bundle failed.';
            return false;
        }

        // The stored/downloadable artifact is fully self-contained: the
        // signed payload PLUS its own proof, so a third party can verify it
        // without calling Scholiq. Mirrors Credential.openbadges3Payload
        // embedding its own `proof` block after signing (CredentialSigningService
        // ::check()) — the signing input itself never includes `proof`.
        $signedBundle          = $bundle;
        $signedBundle['proof'] = [
            'type'               => 'DataIntegrityProof',
            'cryptosuite'        => 'rsa-signature-2025',
            'created'            => $generatedAt,
            'verificationMethod' => $issuerDid,
            'proofPurpose'       => 'assertionMethod',
            'jws'                => $jws,
        ];

        $exportId = (string) ($object['id'] ?? ($object['uuid'] ?? bin2hex(random_bytes(8))));

        $ownerUid = $requestedBy;
        if ($learnerId !== '') {
            $ownerUid = $learnerId;
        }

        $bundleRef = $this->writeBundleToFiles(bundle: $signedBundle, ownerUid: $ownerUid, tenantId: $tenantId, exportId: $exportId);
        if ($bundleRef === null) {
            $object['errorMessage'] = 'Could not store the signed bundle.';
            return false;
        }

        $object['coverageReport']  = $coverageReport;
        $object['bundleRef']       = $bundleRef;
        $object['bundleSignature'] = $jws;
        $object['issuerDid']       = $issuerDid;
        $object['generatedAt']     = $generatedAt;
        $object['errorMessage']    = null;

        return true;
    }//end check()

    /**
     * Map an aggregation collection key back to its OpenRegister schema slug.
     *
     * @param string $key Collection key from `LearningRecordAggregationService::compose()`.
     *
     * @return string Schema slug.
     */
    private function schemaForCollectionKey(string $key): string
    {
        return match ($key) {
            'enrolments' => 'enrolment',
            'finalGrades' => 'final-grade',
            'competencyAttainments' => 'competency-attainment',
            'credentials' => 'credential',
            'portfolios' => 'portfolio',
            'portfolioEntries' => 'portfolio-entry',
            'externalTrainingRecords' => 'external-training-record',
            'bpvPlacements' => 'bpv-placement',
            'werkprocesAssessments' => 'werkproces-assessment',
            'reportCards' => 'report-card',
            default => $key,
        };
    }//end schemaForCollectionKey()

    /**
     * Whether a row falls within the requested export period.
     *
     * A row with no comparable date field for its schema (`$dateField` is
     * null) is always in scope — period narrowing is only applied to
     * schemas carrying one unambiguous per-item timestamp (see
     * `PERIOD_FIELD_BY_SCHEMA`). A row whose own date field is null (e.g. an
     * unassessed WerkprocesAssessment) is likewise kept in scope rather than
     * guessed at.
     *
     * @param array<string,mixed> $row        The source row.
     * @param string|null         $dateField  Field name holding this schema's comparable date, or null.
     * @param string|null         $periodFrom Requested period start (inclusive), or null.
     * @param string|null         $periodTo   Requested period end (inclusive), or null.
     *
     * @return bool
     */
    private function isWithinPeriod(array $row, ?string $dateField, ?string $periodFrom, ?string $periodTo): bool
    {
        if ($dateField === null || ($periodFrom === null && $periodTo === null)) {
            return true;
        }

        $value = $row[$dateField] ?? null;
        if (is_string($value) === false || $value === '') {
            return true;
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            return true;
        }

        if ($periodFrom !== null) {
            try {
                if ($date < new DateTimeImmutable((string) $periodFrom)) {
                    return false;
                }
            } catch (\Exception) {
                // Unparseable bound — do not exclude on it.
            }
        }

        if ($periodTo !== null) {
            try {
                if ($date > new DateTimeImmutable((string) $periodTo)) {
                    return false;
                }
            } catch (\Exception) {
                // Unparseable bound — do not exclude on it.
            }
        }

        return true;
    }//end isWithinPeriod()

    /**
     * Resolve a human-readable label for a coverage-report entry, per schema.
     *
     * @param string              $schema Schema slug.
     * @param array<string,mixed> $row    The source row.
     *
     * @return string
     */
    private function resolveSourceTitle(string $schema, array $row): string
    {
        return match ($schema) {
            'credential' => 'Credential ('.($row['kind'] ?? 'unknown').')',
            'final-grade' => 'Final grade',
            'competency-attainment' => 'Competency attainment',
            'portfolio', 'portfolio-entry', 'external-training-record' => (string) ($row['title'] ?? $schema),
            'bpv-placement' => (string) ($row['leerbedrijfName'] ?? 'BPV placement'),
            'werkproces-assessment' => (string) ($row['werkprocesLabel'] ?? 'Werkproces assessment'),
            'enrolment' => 'Course enrolment',
            'report-card' => 'Report card',
            'dossier-note' => 'Dossier note',
            'behaviour-incident' => 'Behaviour incident',
            'wellbeing-check-in' => 'Wellbeing check-in',
            default => $schema,
        };
    }//end resolveSourceTitle()

    /**
     * Build the ELM/Europass-shaped section: per in-scope Credential,
     * `edciPayload` when non-empty else `openbadges3Payload`, verbatim — the
     * identical fallback `WalletOfferDelegationService::buildOfferRequest()`
     * already uses. Never re-signs or re-derives a credential payload.
     *
     * @param array<int,array<string,mixed>> $credentials In-scope Credential rows.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-export-never-bypasses-or-duplicates-the-eudi-wallet-push
     */
    private function buildElmSection(array $credentials): array
    {
        $elm = [];
        foreach ($credentials as $credential) {
            $payload = $credential['edciPayload'] ?? null;
            if (empty($payload) === true) {
                $payload = $credential['openbadges3Payload'] ?? null;
            }

            if (empty($payload) === true) {
                continue;
            }

            $elm[] = [
                'credentialId' => $credential['id'] ?? ($credential['uuid'] ?? null),
                'kind'         => $credential['kind'] ?? null,
                'payload'      => $payload,
            ];
        }

        return $elm;
    }//end buildElmSection()

    /**
     * Find DossierNote/BehaviourIncident/WellbeingCheckIn rows for this
     * learner that fall within the requested period (or, when no period is
     * requested, unconditionally — the honest-coverage default), producing
     * one `omitted` coverageReport entry each. Never included in the bundle.
     *
     * @param string      $learnerId  Nextcloud user id of the learner.
     * @param string|null $periodFrom Requested period start (inclusive), or null.
     * @param string|null $periodTo   Requested period end (inclusive), or null.
     *
     * @return array<int,array<string,mixed>> coverageReport-shaped entries.
     */
    private function findExcludedRowsInScope(string $learnerId, ?string $periodFrom, ?string $periodTo): array
    {
        if ($learnerId === '') {
            return [];
        }

        $entries = [];
        foreach (self::EXCLUDED_SCHEMA_DATE_FIELD as $schema => $dateField) {
            $rows = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => $schema,
                    'filters'  => ['learnerId' => $learnerId],
                ]
            );

            foreach ($rows as $row) {
                $data = [];
                if (is_array($row) === true) {
                    $data = $row;
                } else if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                    $data = (array) $row->jsonSerialize();
                }

                if ($this->isWithinPeriod(row: $data, dateField: $dateField, periodFrom: $periodFrom, periodTo: $periodTo) === false) {
                    continue;
                }

                $entries[] = [
                    'sourceSchema' => $schema,
                    'sourceId'     => (string) ($data['id'] ?? ($data['uuid'] ?? '')),
                    'sourceTitle'  => $this->resolveSourceTitle(schema: $schema, row: $data),
                    'outcome'      => 'omitted',
                    'reason'       => 'staff professional-judgment record, not learner-portable evidence',
                ];
            }
        }//end foreach

        return $entries;
    }//end findExcludedRowsInScope()

    /**
     * Write the signed bundle JSON to the owner's nc:files home, mirroring
     * `CoursePackageImportService::writeBytesToFiles()`'s destination
     * convention (`Scholiq/{tenant}/...`) — this app does not store file
     * bytes anywhere other than nc:files.
     *
     * @param array<string,mixed> $bundle   The signed bundle (bundle itself, not the JWS).
     * @param string              $ownerUid Nextcloud user id who will own the file.
     * @param string              $tenantId Tenant UUID, used to namespace the destination folder.
     * @param string              $exportId LearningRecordExport UUID, used as the filename.
     *
     * @return string|null The nc:files path, or null on failure.
     */
    private function writeBundleToFiles(array $bundle, string $ownerUid, string $tenantId, string $exportId): ?string
    {
        if ($ownerUid === '') {
            return null;
        }

        $encoded = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            return null;
        }

        try {
            $tenantSegment = 'default';
            if ($tenantId !== '') {
                $tenantSegment = $tenantId;
            }

            $ncBaseDir = 'Scholiq/'.$tenantSegment.'/learning-record-exports';
            $ncPath    = $ncBaseDir.'/'.$exportId.'.json';

            $userFolder = $this->rootFolder->getUserFolder($ownerUid);
            $this->ensureFolder(userFolder: $userFolder, path: $ncBaseDir);

            if ($userFolder->nodeExists($ncPath) === true) {
                $existingNode = $userFolder->get($ncPath);
                if ($existingNode instanceof \OCP\Files\File) {
                    $existingNode->putContent($encoded);
                }
            } else {
                $userFolder->newFile($ncPath, $encoded);
            }

            return '/'.$ncPath;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[LearningRecordExportService] Could not write signed bundle for export {id}: {msg}',
                ['id' => $exportId, 'msg' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end writeBundleToFiles()

    /**
     * Ensure a nested nc:files folder path exists under the given folder.
     *
     * @param \OCP\Files\Folder $userFolder The root user folder.
     * @param string            $path       Slash-separated relative path to ensure.
     *
     * @return void
     */
    private function ensureFolder(\OCP\Files\Folder $userFolder, string $path): void
    {
        $segments = array_filter(explode('/', $path));
        $current  = '';
        foreach ($segments as $segment) {
            if ($current === '') {
                $current = $segment;
            } else {
                $current = $current.'/'.$segment;
            }

            try {
                $userFolder->get($current);
            } catch (NotFoundException $e) {
                $userFolder->newFolder($current);
            }
        }
    }//end ensureFolder()
}//end class
