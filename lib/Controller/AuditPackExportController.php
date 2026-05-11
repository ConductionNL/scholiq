<?php

/**
 * Scholiq Audit Pack Export Controller
 *
 * Streams the ADR-008 §6 compliance audit-pack ZIP on demand.
 *
 * This is a legitimate PHP file per ADR-031 §"Document/ZIP generation":
 * streaming a ZIP containing ndjson/csv/manifest/signature-verification cannot
 * be expressed declaratively. All heavy lifting (audit-trail query, HMAC chain
 * verification) is delegated to OR's AuditTrailService — this controller is
 * intentionally thin.
 *
 * Per ADR-022: uses OR's audit-trail-query abstraction; does NOT maintain a
 * local event store or write any audit entries itself (OR does that on the
 * AuditPackExportController's OR-query call automatically).
 *
 * Per ADR-008: the `compliance.audit_pack.exported` audit-trail entry is emitted
 * automatically by OR when we call $auditTrailService->query() (OR records every
 * audit-trail access as an audit event for non-repudiation).
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\OpenRegister\Service\AuditTrailService;
use OCA\Scholiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Streams the ADR-008 §6 audit-pack ZIP for compliance officers and auditors.
 *
 * Single method: export(). Accepts POST with {regulationSlug, dateFrom, dateTo},
 * queries OR's audit trail, and returns a ZIP containing:
 *   - audit-trail.ndjson  (one JSON object per line, all matching events)
 *   - audit-trail.csv     (flat CSV of the same events)
 *   - manifest.json       (tenant_id, period, regulation_slug, event_count,
 *                          signature_status, export_timestamp, key_fingerprint)
 *   - signature-verification.txt  (OR's HMAC chain verification report)
 */
class AuditPackExportController extends Controller
{
    /**
     * Event types included in the audit pack per ADR-008 §6.
     *
     * @var string[]
     */
    private const AUDIT_EVENT_TYPES = [
        'attestation.signed',
        'attestation.revoked',
        'credential.issued',
        'credential.revoked',
        'credential.expired',
        'enrolment.completed',
        'compliance.regulation.published',
        'compliance.audit_pack.exported',
        'xapi.statement.received',
    ];

    /**
     * Constructor.
     *
     * @param IRequest          $request           HTTP request.
     * @param AuditTrailService $auditTrailService OR audit-trail abstraction.
     * @param LoggerInterface   $logger            PSR logger.
     */
    public function __construct(
        IRequest $request,
        private readonly AuditTrailService $auditTrailService,
        private readonly LoggerInterface $logger,
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

        // Query OR's audit trail — OR auto-records the access as compliance.audit_pack.exported.
        $events = $this->auditTrailService->query(
                [
                    'event_type'     => self::AUDIT_EVENT_TYPES,
                    'regulationSlug' => $regulationSlug,
                    'period'         => [$dateFrom, $dateTo],
                ]
                );

        // Retrieve HMAC chain verification report from OR.
        $verification = $this->auditTrailService->verifyChain(
                [
                    'regulationSlug' => $regulationSlug,
                    'period'         => [$dateFrom, $dateTo],
                ]
                );

        $keyFingerprint  = $verification['keyFingerprint'] ?? 'unavailable';
        $signatureStatus = $verification['status'] ?? 'unknown';
        $eventCount      = count($events);
        $exportTimestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        // Build the four required files.
        $ndjson       = $this->buildNdjson(events: $events);
        $csv          = $this->buildCsv(events: $events);
        $manifestJson = $this->buildManifestJson(
            tenantId: $this->auditTrailService->getCurrentTenantId(),
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
     */
    private function buildCsv(array $events): string
    {
        if (empty($events) === true) {
            return "event_id,event_type,regulation_slug,subject_id,actor_id,occurred_at,signature\n";
        }

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['event_id', 'event_type', 'regulation_slug', 'subject_id', 'actor_id', 'occurred_at', 'signature']);

        foreach ($events as $event) {
            fputcsv(
                    $handle,
                    [
                        $event['id'] ?? '',
                        $event['event_type'] ?? '',
                        $event['regulationSlug'] ?? '',
                        $event['subject_id'] ?? '',
                        $event['actor_id'] ?? '',
                        $event['occurred_at'] ?? '',
                        $event['signature'] ?? '',
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
     * @param string $tenantId        Tenant UUID.
     * @param string $regulationSlug  Regulation slug.
     * @param string $dateFrom        Period start.
     * @param string $dateTo          Period end.
     * @param int    $eventCount      Total events in pack.
     * @param string $signatureStatus OR chain verification result.
     * @param string $exportTimestamp ISO-8601 timestamp of this export.
     * @param string $keyFingerprint  Public verification key fingerprint.
     *
     * @return string JSON string.
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
     * Build the signature-verification.txt content from OR's chain report.
     *
     * @param array<string,mixed> $verification OR verification response.
     *
     * @return string Plain-text verification report.
     */
    private function buildVerificationTxt(array $verification): string
    {
        $status      = $verification['status'] ?? 'unknown';
        $fingerprint = $verification['keyFingerprint'] ?? 'unavailable';
        $checkedAt   = $verification['checkedAt'] ?? 'unknown';
        $totalEvents = $verification['totalEvents'] ?? 0;
        $brokenAt    = $verification['firstBrokenAt'] ?? null;

        $lines   = [];
        $lines[] = '=== Scholiq Compliance Audit Pack — Signature Verification Report ===';
        $lines[] = '';
        $lines[] = 'Status          : '.$status;
        $lines[] = 'Key fingerprint : '.$fingerprint;
        $lines[] = 'Checked at      : '.$checkedAt;
        $lines[] = 'Total events    : '.$totalEvents;

        if ($brokenAt !== null) {
            $lines[] = '';
            $lines[] = 'WARNING: Chain integrity broken at event: '.$brokenAt;
            $lines[] = 'This indicates a record was modified or deleted after recording.';
        } else {
            $lines[] = '';
            $lines[] = 'All HMAC signatures verified. Evidence log is intact.';
        }

        $lines[] = '';
        $lines[] = 'This report is generated by OpenRegister\'s audit-trail verification endpoint.';
        $lines[] = 'For offline verification use the key fingerprint above with the NDJSON file.';

        return implode("\n", $lines)."\n";
    }//end buildVerificationTxt()

    /**
     * Build an in-memory ZIP archive from named string content entries.
     *
     * @param array<string,string> $files Map of filename => content.
     *
     * @return string Raw ZIP bytes.
     */
    private function buildZip(array $files): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'scholiq_audit_');
        if ($tmpFile === false) {
            return '';
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
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
