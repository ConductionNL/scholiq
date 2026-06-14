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
use OCA\Scholiq\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
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
 *   - verwerkingsregister.csv     (OR-PA-7 Art. 30 register slice; loud warning if absent)
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class AuditPackExportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request          HTTP request.
     * @param AuditTrailMapper  $auditTrailMapper OR audit-trail database mapper.
     * @param AuditHashService  $auditHashService OR HMAC chain verification service.
     * @param IConfig           $config           Nextcloud system config for tenant ID lookup.
     * @param IUserSession      $userSession      Nextcloud user session.
     * @param ActionAuthService $actionAuth       ADR-023 action authorization service.
     * @param IClientService    $clientService    NC HTTP client factory (OR read-log fetch).
     * @param IURLGenerator     $urlGenerator     NC URL generator for the OR endpoint.
     */
    public function __construct(
        IRequest $request,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly AuditHashService $auditHashService,
        private readonly IConfig $config,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
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
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    #[NoAdminRequired]
    public function export(
        string $regulationSlug='',
        string $dateFrom='',
        string $dateTo='',
    ): DataDownloadResponse|JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'audit-pack.export');

        if ($regulationSlug === '' || $dateFrom === '' || $dateTo === '') {
            return new JSONResponse(
                data: ['error' => 'regulationSlug, dateFrom, and dateTo are required'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        // #184: resolve the requesting tenant's ID. instanceid is the same for every
        // tenant on the instance — use the authenticated user's primary tenant instead.
        // We fall back to instanceid only when no per-user tenant mapping is available.
        $tenantId = $this->config->getSystemValue('instanceid', 'unknown');
        if ($user !== null) {
            // Attempt to read a per-user tenant binding stored by the admin module.
            $userTenantId = $this->config->getUserValue(
                userId: $user->getUID(),
                appName: 'scholiq',
                key: 'tenant_id',
                default: ''
            );
            if ($userTenantId !== '') {
                $tenantId = $userTenantId;
            }
        }

        // Query OR's audit trail via the real mapper — filters available columns.
        // #184: always scope the query to the requesting tenant's ID so that audit
        // entries from other tenants are never returned.
        $entries = $this->auditTrailMapper->findAll(
            filters: [
                'created'   => $dateFrom.','.$dateTo,
                'tenant_id' => $tenantId,
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

        // #192: collect the IDs of the matched events so verifyChain operates on
        // exactly the same entries that appear in the export, not the full log.
        $minId = null;
        $maxId = null;
        foreach ($events as $ev) {
            $id = (int) ($ev['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            if ($minId === null || $id < $minId) {
                $minId = $id;
            }

            if ($maxId === null || $id > $maxId) {
                $maxId = $id;
            }
        }

        // #192: pass the date-scoped ID bounds to verifyChain so the integrity report
        // covers the same entries as the export (not the whole audit log). Fixes #192.
        if ($minId !== null && $maxId !== null) {
            $verification = $this->auditHashService->verifyChain(fromId: $minId, toId: $maxId);
        } else {
            $verification = $this->auditHashService->verifyChain();
        }

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

        // Build the four required files.
        // $tenantId is already resolved above (per-user or instanceid fallback).
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

        // AVG Art. 30 verwerkingsregister artefact. The aggregate export is an
        // OpenRegister capability (OR-PA-7); scholiq only fetches the platform
        // output scoped to its register slice and includes it verbatim — no
        // export engine, serialisation, or column logic here. When the platform
        // capability is absent the artefact degrades loudly (warning content),
        // it is never silently omitted.
        $verwerkingsregisterCsv = $this->buildVerwerkingsregisterCsv(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );

        // Build ZIP in memory.
        $zipContent = $this->buildZip(
                files: [
                    'audit-trail.ndjson'         => $ndjson,
                    'audit-trail.csv'            => $csv,
                    'manifest.json'              => $manifestJson,
                    'signature-verification.txt' => $verificationTxt,
                    'verwerkingsregister.csv'    => $verwerkingsregisterCsv,
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
     * Fetch the AVG Art. 30 verwerkingsregister for scholiq's slice from
     * OpenRegister and return it as CSV for inclusion in the audit pack.
     *
     * Per ADR-022 scholiq ships NO export engine: it calls OpenRegister's
     * per-access processing-log endpoint (`/api/avg/verwerkingen`, OR-PA-7/8)
     * scoped to scholiq's register and includes the platform output. The
     * aggregate Art. 30 register export to CSV/JSON/PDF is a forthcoming
     * OpenRegister capability; until it lands this artefact carries the OR
     * read-log query result. When OpenRegister lacks the capability entirely
     * (endpoint 404 / not installed) the artefact contains a loud
     * "platform capability missing" warning rather than being omitted.
     *
     * @param string $dateFrom ISO-8601 lower bound forwarded to the platform.
     * @param string $dateTo   ISO-8601 upper bound forwarded to the platform.
     *
     * @return string CSV content, or a loud warning when the platform capability is absent.
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    private function buildVerwerkingsregisterCsv(string $dateFrom, string $dateTo): string
    {
        try {
            $url = $this->urlGenerator->linkToRoute('openregister.processingLog.index');
        } catch (\Throwable $e) {
            return $this->verwerkingsregisterWarning(
                reason: 'OpenRegister does not expose the AVG processing-log capability '
                    .'(route openregister.processingLog.index is not registered). '
                    .'The Art. 30 verwerkingsregister is provided by OpenRegister (OR-PA-7); '
                    .'install or upgrade OpenRegister (>= 0.2.14) to include it.'
            );
        }

        $absoluteUrl = $this->urlGenerator->getAbsoluteURL($url)
            .'?register=scholiq&from='.rawurlencode($dateFrom).'&to='.rawurlencode($dateTo);

        // Forward the caller's session so OpenRegister applies its own RBAC
        // (OR-PA-8). Scholiq performs no access decision of its own here.
        $cookieHeader = (string) $this->request->getHeader('Cookie');

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                $absoluteUrl,
                [
                    'headers'   => [
                        'Accept'         => 'application/json',
                        'Cookie'         => $cookieHeader,
                        'OCS-APIRequest' => 'true',
                        'requesttoken'   => (string) $this->request->getHeader('requesttoken'),
                    ],
                    'nextcloud' => ['allow_local_address' => true],
                ]
            );
        } catch (\Throwable $e) {
            return $this->verwerkingsregisterWarning(
                reason: 'The OpenRegister AVG processing-log endpoint could not be reached ('.$e->getMessage().'). '
                    .'The Art. 30 verwerkingsregister is provided by OpenRegister (OR-PA-7) and could not be included.'
            );
        }

        $body    = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (is_array($decoded) === false || isset($decoded['results']) === false) {
            return $this->verwerkingsregisterWarning(
                reason: 'OpenRegister returned an unexpected response for the AVG processing log; '
                    .'the Art. 30 verwerkingsregister could not be included.'
            );
        }

        return $this->verwerkingsregisterCsvFromEntries(entries: (array) $decoded['results']);
    }//end buildVerwerkingsregisterCsv()

    /**
     * Render the OpenRegister processing-log entries (platform output) as CSV.
     *
     * This is a flat passthrough of whatever fields OpenRegister returns; it
     * applies no Art. 30 column semantics of its own (those are OR-PA-7's).
     *
     * @param array<int,array<string,mixed>> $entries Platform processing-log rows.
     *
     * @return string CSV content.
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    private function verwerkingsregisterCsvFromEntries(array $entries): string
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return '';
        }

        if (empty($entries) === true) {
            fputcsv($handle, ['activity', 'register', 'schema', 'action', 'actor', 'subjectIdType', 'created']);
            rewind($handle);
            $csv = (string) stream_get_contents($handle);
            fclose($handle);
            return $csv;
        }

        // Header = the union of keys present on the first row (platform-defined).
        $first   = (array) ($entries[0] ?? []);
        $columns = array_keys($first);
        fputcsv($handle, $columns);

        foreach ($entries as $entry) {
            $row   = (array) $entry;
            $cells = [];
            foreach ($columns as $column) {
                $value = ($row[$column] ?? '');
                if (is_array($value) === true) {
                    $value = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $cells[] = $this->sanitizeCsvCell(value: (string) $value);
            }

            fputcsv($handle, $cells);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }//end verwerkingsregisterCsvFromEntries()

    /**
     * Build the loud "platform capability missing" warning artefact content.
     *
     * @param string $reason Human-readable reason the register could not be included.
     *
     * @return string Warning CSV content.
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    private function verwerkingsregisterWarning(string $reason): string
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return 'WARNING,'.$reason."\n";
        }

        fputcsv($handle, ['status', 'message']);
        fputcsv($handle, ['PLATFORM CAPABILITY MISSING', $this->sanitizeCsvCell(value: $reason)]);
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }//end verwerkingsregisterWarning()

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
                        $this->sanitizeCsvCell(value: $event['uuid'] ?? ''),
                        $this->sanitizeCsvCell(value: $event['action'] ?? ''),
                        $this->sanitizeCsvCell(value: $event['object'] ?? ''),
                        $this->sanitizeCsvCell(value: $event['register'] ?? ''),
                        $this->sanitizeCsvCell(value: $event['schema'] ?? ''),
                        $this->sanitizeCsvCell(value: $event['user'] ?? ''),
                        $this->sanitizeCsvCell(value: $event['created'] ?? ''),
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
     * Sanitize a single CSV cell to prevent formula-injection attacks.
     *
     * Excel and LibreOffice Calc treat cells starting with `=`, `+`, `-`, `@`, `\t`,
     * or `\r` as formula expressions. Prefixing such values with a tab character
     * neutralises the injection without altering the visible cell content in most
     * spreadsheet applications. Fixes #191.
     *
     * @param string $value The raw cell value.
     *
     * @return string The sanitised cell value.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    private function sanitizeCsvCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Trim leading whitespace first so we test the true first character.
        $first = $value[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], strict: true) === true) {
            return "\t".$value;
        }

        return $value;
    }//end sanitizeCsvCell()

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
