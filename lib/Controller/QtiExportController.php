<?php

/**
 * Scholiq QTI Export Controller
 *
 * Thin HTTP endpoint that streams an ItemBank's QTI 3.0 export package.
 * All heavy lifting is delegated to QtiExportService — this controller is
 * intentionally thin per ADR-022.
 *
 * Legitimate PHP per ADR-031 §"Document/ZIP generation": streaming a ZIP
 * cannot be expressed declaratively.
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
 * @spec openspec/changes/course-package-import-export/specs/assessment/spec.md#requirement-itembank-exports-its-items-as-a-qti-30-package
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\ActionAuthService;
use OCA\Scholiq\Service\QtiExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Handles QTI 3.0 package export for an ItemBank.
 *
 * Single endpoint: GET /api/assessment/qti-export?itemBankId=...
 */
class QtiExportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request          HTTP request.
     * @param QtiExportService  $qtiExportService QTI export service.
     * @param IUserSession      $userSession      Nextcloud user session.
     * @param ActionAuthService $actionAuth       ADR-023 action authorization service.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly QtiExportService $qtiExportService,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Export an ItemBank as a QTI 3.0 package ZIP.
     *
     * @param string $itemBankId UUID of the ItemBank to export.
     *
     * @return DataDownloadResponse|JSONResponse ZIP stream, or a JSON error.
     *
     * @spec openspec/changes/course-package-import-export/specs/assessment/spec.md#requirement-itembank-exports-its-items-as-a-qti-30-package
     */
    #[NoAdminRequired]
    public function export(string $itemBankId=''): DataDownloadResponse|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'qti.export');

        if ($itemBankId === '') {
            return new JSONResponse(
                data: ['error' => 'itemBankId is required'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $zipContent = $this->qtiExportService->export(itemBankId: $itemBankId);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => 'Export failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new DataDownloadResponse(
            data: $zipContent,
            filename: 'item-bank_'.$itemBankId.'_qti3.zip',
            contentType: 'application/zip'
        );
    }//end export()
}//end class
