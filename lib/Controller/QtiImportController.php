<?php

/**
 * Scholiq QTI Import Controller
 *
 * Thin HTTP endpoint for uploading and importing a QTI 2.x / 3.0 or Common Cartridge
 * ZIP package into a specified ItemBank. All heavy lifting is delegated to
 * QtiImportService — this controller is intentionally thin per ADR-022.
 *
 * Legitimate PHP per ADR-031 §"NC framework requirement — thin controller": the QTI
 * import requires file upload handling (`$_FILES`) which cannot be expressed
 * declaratively, and QtiImportService is itself an ADR-031 "external-format import"
 * exception. This controller provides the Nextcloud HTTP surface only.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\QtiImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Handles QTI package upload and import into an ItemBank.
 *
 * Single endpoint: POST /api/assessment/qti-import
 *
 * Multipart form fields:
 *   - file       : the QTI / CC .zip file (required)
 *   - itemBankId : UUID of the target ItemBank (required)
 *
 * Returns JSON:
 *   - { itemCount: N, itemIds: [uuid, ...] } on success
 *   - { error: "message" }                   on failure
 */
class QtiImportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          HTTP request.
     * @param QtiImportService $qtiImportService QTI import service.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly QtiImportService $qtiImportService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Import a QTI 2.x / 3.0 or Common Cartridge ZIP package into an ItemBank.
     *
     * @param string $itemBankId UUID of the target ItemBank.
     *
     * @return JSONResponse JSON with created item UUIDs and count, or an error.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
     */
    public function import(string $itemBankId=''): JSONResponse
    {
        if ($itemBankId === '') {
            return new JSONResponse(
                data: ['error' => 'itemBankId is required'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
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

        $tmpPath = $uploadedFile['tmp_name'];

        if (file_exists($tmpPath) === false) {
            return new JSONResponse(
                data: ['error' => 'Uploaded file not found on server.'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        try {
            $createdIds = $this->qtiImportService->import(
                packagePath: $tmpPath,
                itemBankId: $itemBankId,
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => 'Import failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(
            data: [
                'itemCount' => count($createdIds),
                'itemIds'   => $createdIds,
            ],
            statusCode: Http::STATUS_OK
        );
    }//end import()
}//end class
