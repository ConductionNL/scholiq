<?php

/**
 * Scholiq Learning Record Import Controller
 *
 * Thin HTTP endpoint for uploading another institution's exported learning
 * record (or a bare ELM/Europass credential set) as evidence during
 * `Application` admissions intake. All parsing is delegated to
 * `LearningRecordImportService` — this controller is intentionally thin
 * per ADR-022.
 *
 * Legitimate PHP per ADR-031 §"NC framework requirement — thin controller":
 * the upload requires multipart file-upload handling (`$_FILES`), which
 * cannot be expressed declaratively — the same reasoning
 * `CoursePackageImportController`'s own docblock already gives.
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
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

namespace OCA\Scholiq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles prior-institution learning-record upload during Application intake.
 *
 * Single endpoint: POST /api/applications/{applicationId}/learning-record-imports
 *
 * Multipart form fields:
 *   - file         : the uploaded JSON bundle (required)
 *   - sourceFormat : `scholiq-learning-record` | `elm-europass` (required)
 *
 * @spec openspec/changes/portable-learning-record/tasks.md#task-4-2
 */
class LearningRecordImportController extends Controller
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const SCHEMA           = 'learning-record-import';

    /**
     * Constructor.
     *
     * @param IRequest          $request          HTTP request.
     * @param ObjectService     $objectService    OR object create/read service.
     * @param TransitionEngine  $transitionEngine OR lifecycle engine used to dispatch the `parse` transition.
     * @param IUserSession      $userSession      Nextcloud user session.
     * @param ActionAuthService $actionAuth       ADR-023 action authorization service.
     * @param IRootFolder       $rootFolder       NC root folder for writing the uploaded bytes.
     * @param IConfig           $config           Nextcloud config for tenant resolution.
     * @param LoggerInterface   $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly IRootFolder $rootFolder,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Upload and parse a prior-institution learning record for one Application.
     *
     * @param string $applicationId UUID of the Application this import is evidence for.
     *
     * @return JSONResponse The created (now `parsed`, or `uploaded`+errorMessage) LearningRecordImport, or an error.
     *
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-coordinator-uploads-a-prior-scholiq-export-during-intake-and-sees-a-verified-coverage-report
     */
    #[NoAdminRequired]
    public function upload(string $applicationId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'learning-record.import');

        if ($applicationId === '') {
            return new JSONResponse(data: ['error' => 'applicationId is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $uploadedFile = $this->request->getUploadedFile('file');
        if (isset($uploadedFile['tmp_name']) === false) {
            return new JSONResponse(
                data: ['error' => 'No file uploaded. POST a multipart/form-data request with a `file` field.'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return new JSONResponse(
                data: ['error' => 'File upload error code '.$uploadedFile['error']],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $sourceFormat = (string) $this->request->getParam('sourceFormat', 'scholiq-learning-record');
        if (in_array($sourceFormat, ['scholiq-learning-record', 'elm-europass'], true) === false) {
            return new JSONResponse(data: ['error' => 'Invalid sourceFormat'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $sourceFilename = (string) ($uploadedFile['name'] ?? 'learning-record.json');
        $tmpPath        = (string) $uploadedFile['tmp_name'];
        if (file_exists($tmpPath) === false) {
            return new JSONResponse(
                data: ['error' => 'Uploaded file not found on server.'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $tenantId     = $this->config->getSystemValue('instanceid', '');
        $userTenantId = $this->config->getUserValue(userId: $user->getUID(), appName: 'scholiq', key: 'tenant_id', default: '');
        if ($userTenantId !== '') {
            $tenantId = $userTenantId;
        }

        $sourceRef = $this->writeUploadToFiles(
            tmpPath: $tmpPath,
            ownerUid: $user->getUID(),
            tenantId: $tenantId
        );
        if ($sourceRef === null) {
            return new JSONResponse(
                data: ['error' => 'Could not store the uploaded file.'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $uploadedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $created = $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::SCHEMA,
            object: [
                'applicationId'  => $applicationId,
                'sourceFilename' => $sourceFilename,
                'sourceFormat'   => $sourceFormat,
                'uploadedBy'     => $user->getUID(),
                'uploadedAt'     => $uploadedAt,
                'sourceRef'      => $sourceRef,
                'lifecycle'      => 'uploaded',
                'tenant_id'      => $tenantId,
            ]
        );

        $createdId = $this->extractId(saved: $created);
        if ($createdId === null) {
            return new JSONResponse(
                data: ['error' => 'Could not create the LearningRecordImport record.'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        try {
            $this->transitionEngine->transition($createdId, 'parse');
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[LearningRecordImportController] parse transition for {id} raised: {msg}',
                ['id' => $createdId, 'msg' => $e->getMessage()]
            );
        }

        $final = $this->objectService->find(id: $createdId, register: self::SCHOLIQ_REGISTER, schema: self::SCHEMA);

        return new JSONResponse(data: $this->toArray(row: $final), statusCode: Http::STATUS_OK);
    }//end upload()

    /**
     * Write the raw uploaded bytes into the caller's nc:files home, mirroring
     * `CoursePackageImportService::writeBytesToFiles()`'s destination
     * convention (`Scholiq/{tenant}/...`).
     *
     * @param string $tmpPath  Absolute path to the uploaded tmp file.
     * @param string $ownerUid Nextcloud user id who will own the file.
     * @param string $tenantId Tenant UUID, used to namespace the destination folder.
     *
     * @return string|null The nc:files path (relative, no leading slash), or null on failure.
     */
    private function writeUploadToFiles(string $tmpPath, string $ownerUid, string $tenantId): ?string
    {
        try {
            $content = (string) file_get_contents($tmpPath);

            $tenantSegment = 'default';
            if ($tenantId !== '') {
                $tenantSegment = $tenantId;
            }

            $ncBaseDir = 'Scholiq/'.$tenantSegment.'/learning-record-imports';
            $ncPath    = $ncBaseDir.'/'.bin2hex(random_bytes(8)).'.json';

            $userFolder = $this->rootFolder->getUserFolder($ownerUid);

            $segments = array_filter(explode('/', $ncBaseDir));
            $current  = '';
            foreach ($segments as $segment) {
                if ($current === '') {
                    $current = $segment;
                } else {
                    $current = $current.'/'.$segment;
                }

                try {
                    $userFolder->get($current);
                } catch (\OCP\Files\NotFoundException $e) {
                    $userFolder->newFolder($current);
                }
            }

            $userFolder->newFile($ncPath, $content);

            return $ncPath;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[LearningRecordImportController] Could not write uploaded bundle: {msg}',
                ['msg' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end writeUploadToFiles()

    /**
     * Extract a created object's UUID from an `ObjectService::saveObject()` return value.
     *
     * @param mixed $saved Return value of `saveObject()` (array or an ObjectEntity-like object).
     *
     * @return string|null The UUID, or null if it could not be resolved.
     */
    private function extractId(mixed $saved): ?string
    {
        $data = $this->toArray(row: $saved);

        $id = $data['id'] ?? ($data['uuid'] ?? null);

        if (is_string($id) === true) {
            return $id;
        }

        return null;
    }//end extractId()

    /**
     * Normalise an OR result row (a raw array or an ObjectEntity-like object) to a plain array.
     *
     * @param mixed $row Raw row from ObjectService.
     *
     * @return array<string,mixed>
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            $serialized = $row->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return [];
    }//end toArray()
}//end class
