<?php

/**
 * Scholiq Audit Pack Export Controller
 *
 * Streams the ADR-008 §6 compliance audit-pack ZIP on demand.
 *
 * This is a legitimate PHP file per ADR-031 §"Document/ZIP generation":
 * streaming a ZIP containing ndjson/csv/manifest/signature-verification cannot
 * be expressed declaratively. All heavy lifting (audit-trail query, HMAC chain
 * verification) is delegated to OR's AuditTrailMapper and AuditHashService —
 * this controller is intentionally thin.
 *
 * Per ADR-022: uses OR's audit-trail-query abstraction; does NOT maintain a
 * local event store or write any audit entries itself.
 *
 * Per ADR-008: the audit-pack export is recorded automatically by OR's audit
 * trail when the query is made.
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\Scholiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use ZipArchive;

/**
 * Streams the ADR-008 §6 audit-pack ZIP for compliance officers and auditors.
 *
 * Single method: export(). Accepts POST with {regulationSlug, dateFrom, dateTo},
 * queries OR's audit trail via AuditTrailMapper, verifies the HMAC chain via
 * AuditHashService, and returns a ZIP containing:
 *   - audit-trail.ndjson  (one JSON object per line, all matching events)
 *   - audit-trail.csv     (flat CSV of the same events)
 *   - manifest.json       (tenant_id, period, regulation_slug, event_count,
 *                          signature_status, export_timestamp, key_fingerprint)
 *   - signature-verification.txt  (HMAC chain verification report)
 */
class AuditPackExportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          HTTP request.
     * @param AuditTrailMapper $auditTrailMapper OR audit-trail database mapper.
     * @param AuditHashService $auditHashService OR HMAC chain verification service.
     * @param IConfig          $config           Nextcloud system config for tenant ID lookup.
     */
    public function __construct(
        IRequest $request,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly AuditHashService $auditHashService,
        private readonly IConfig $config,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Export the compliance audit pack as a ZIP download.
     *
     * @param string $regulationSlug Regulation slug to filter (e.g. 'NIS2').
     * @param string $dateFrom       ISO-8601 date lower bound (inclusive).
     * @param string $dateTo         ISO-8601 date upper bound (inclusive).
     *
     * @return DataDownloadResponse|JSONResponse ZIP stream or JSON error.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    public function export(
        string $regulationSlug='',
        string $dateFrom='',
        string $dateTo='',
    ): DataDownloadResponse|JSONResponse {
        if ($regulationSlug === '' || $dateFrom === '' || $dateTo === '') {
            return new JSONResponse(
                data: ['error' => 'regulationSlug, dateFrom, and dateTo are required'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        // Query OR's audit trail via the real mapper — filters available columns.
        // `action` maps to event type; `created` holds the timestamp.
        $entries = $this->auditTrailMapper->findAll(
            filters: [
                'created' => $dateFrom.','.$dateTo,
            ],
            sort: ['created' => 'ASC']
        );

        // Serialise AuditTrail entities to plain arrays.
        $events = [];
        foreach ($entries as $entry) {
            $row = $entry->jsonSerialize();
            // Apply regulation filter on the serialised data (changed JSON field).
            $changed = [];
            if (is_string($row['changed'] ?? null) === true) {
                $changed = (array) json_decode($row['changed'], associative: true);
            }

            if ($regulationSlug !== '' && ($changed['regulationSlug'] ?? '') !== $regulationSlug) {
                continue;
            }

            $events[] = $row;
        }

        // Verify HMAC chain for the full log (inline — AuditHashService::verifyChain
        // is the only real OR method for chain verification, accepting int IDs as bounds).
        $verification    = $this->auditHashService->verifyChain();
        $signatureStatus = 'broken';
        if (($verification['valid'] ?? false) === true) {
            $signatureStatus = 'valid';
        }

        $keyFingerprint = $verification['keyFingerprint'] ?? 'unavailable';
        if (array_key_exists('brokenAt', $verification) === true) {
            $keyFingerprint = 'unavailable';
        }

        $eventCount      = count($events);
        $exportTimestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $tenantId        = $this->config->getSystemValue('instanceid', 'unknown');

        // Build the four required files.
        $ndjson       = $this->buildNdjson(events: $events);
        $csv          = $this->buildCsv(events: $events);
        $manifestJson = $this->buildManifestJson(
            tenantId: $tenantId,
            regulationSlug: $regulationSlug,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            eventCount: $eventCount,
            signatureStatus: $signatureStatus,
            exportTimestamp: $exportTimestamp,
            keyFingerprint: $keyFingerprint,
        );
        $verificationTxt = $this->buildVerificationTxt(verification: $verification);

        // Build ZIP in memory.
        $zipContent = $this->buildZip(
                files: [
                    'audit-trail.ndjson'         => $ndjson,
                    'audit-trail.csv'            => $csv,
                    'manifest.json'              => $manifestJson,
                    'signature-verification.txt' => $verificationTxt,
                ]
                );

        $filename = sprintf(
            'audit-pack_%s_%s_%s.zip',
            $regulationSlug,
            $dateFrom,
            $dateTo
        );

        return new DataDownloadResponse(
            data: $zipContent,
            filename: $filename,
            contentType: 'application/zip'
        );
    }//end export()

    /**
     * Render events as newline-delimited JSON (one object per line).
     *
     * @param array<int,array<string,mixed>> $events Audit-trail events.
     *
     * @return string NDJSON string.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    private function buildNdjson(array $events): string
    {
        $lines = [];
        foreach ($events as $event) {
            $lines[] = (string) json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines)."\n";
    }//end buildNdjson()

    /**
     * Render events as a flat CSV (header row + one data row per event).
     *
     * @param array<int,array<string,mixed>> $events Audit-trail events.
     *
     * @return string CSV string.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    private function buildCsv(array $events): string
    {
        if (empty($events) === true) {
            return "event_id,action,object,register,schema,user,created\n";
        }

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['event_id', 'action', 'object', 'register', 'schema', 'user', 'created']);

        foreach ($events as $event) {
            fputcsv(
                    $handle,
                    [
                        $event['uuid'] ?? '',
                        $event['action'] ?? '',
                        $event['object'] ?? '',
                        $event['register'] ?? '',
                        $event['schema'] ?? '',
                        $event['user'] ?? '',
                        $event['created'] ?? '',
                    ]
                    );
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }//end buildCsv()

    /**
     * Build the manifest.json content per ADR-008 §6.
     *
     * @param string $tenantId        Tenant UUID or instanceid.
     * @param string $regulationSlug  Regulation slug.
     * @param string $dateFrom        Period start.
     * @param string $dateTo          Period end.
     * @param int    $eventCount      Total events in pack.
     * @param string $signatureStatus OR chain verification result.
     * @param string $exportTimestamp ISO-8601 timestamp of this export.
     * @param string $keyFingerprint  Public verification key fingerprint.
     *
     * @return string JSON string.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    private function buildManifestJson(
        string $tenantId,
        string $regulationSlug,
        string $dateFrom,
        string $dateTo,
        int $eventCount,
        string $signatureStatus,
        string $exportTimestamp,
        string $keyFingerprint,
    ): string {
        $manifest = [
            'schema_version'   => '1.0',
            'tenant_id'        => $tenantId,
            'regulation_slug'  => $regulationSlug,
            'period'           => ['from' => $dateFrom, 'to' => $dateTo],
            'event_count'      => $eventCount,
            'signature_status' => $signatureStatus,
            'export_timestamp' => $exportTimestamp,
            'key_fingerprint'  => $keyFingerprint,
            'generator'        => 'scholiq/AuditPackExportController@0.1.0',
        ];

        return (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }//end buildManifestJson()

    /**
     * Build the signature-verification.txt content from OR's chain verification result.
     *
     * @param array<string,mixed> $verification OR AuditHashService::verifyChain() response.
     *
     * @return string Plain-text verification report.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-2
     */
    private function buildVerificationTxt(array $verification): string
    {
        $valid  = ($verification['valid'] ?? false) === true;
        $status = 'broken';
        if ($valid === true) {
            $status = 'valid';
        }

        $entriesVerified = $verification['entriesVerified'] ?? 0;
        $brokenAt        = $verification['brokenAt'] ?? null;

        $lines   = [];
        $lines[] = '=== Scholiq Compliance Audit Pack — Signature Verification Report ===';
        $lines[] = '';
        $lines[] = 'Status          : '.$status;
        $lines[] = 'Entries verified: '.$entriesVerified;

        $lines[] = '';
        if ($brokenAt !== null) {
            $lines[] = 'WARNING: Chain integrity broken at entry id: '.$brokenAt;
            $lines[] = 'This indicates a record was modified or deleted after recording.';
        }

        if ($brokenAt === null) {
            $lines[] = 'All HMAC signatures verified. Evidence log is intact.';
        }

        $lines[] = '';
        $lines[] = 'This report is generated by OpenRegister\'s AuditHashService::verifyChain().';
        $lines[] = 'For offline verification cross-reference the NDJSON file with the chain hashes.';

        return implode("\n", $lines)."\n";
    }//end buildVerificationTxt()

    /**
     * Build an in-memory ZIP archive from named string content entries.
     *
     * @param array<string,string> $files Map of filename => content.
     *
     * @return string Raw ZIP bytes.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    private function buildZip(array $files): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'scholiq_audit_');
        if ($tmpFile === false) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            unlink($tmpFile);
            return '';
        }

        foreach ($files as $filename => $content) {
            $zip->addFromString($filename, $content);
        }

        $zip->close();

        $zipContent = (string) file_get_contents($tmpFile);
        unlink($tmpFile);

        return $zipContent;
    }//end buildZip()
}//end class
