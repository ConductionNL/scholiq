<?php

/**
 * Scholiq Course Package Export Controller
 *
 * Thin HTTP endpoint that streams a Course export (Common Cartridge 1.3 or
 * scholiq-native JSON). Mirrors `AuditPackExportController`'s in-memory-ZIP
 * streaming pattern. All heavy lifting is delegated to
 * CoursePackageExportService — this controller is intentionally thin per
 * ADR-022.
 *
 * Legitimate PHP per ADR-031 §"Document/ZIP generation": streaming a
 * ZIP/JSON download cannot be expressed declaratively.
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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\ActionAuthService;
use OCA\Scholiq\Service\CoursePackageExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Handles Course export as Common Cartridge or scholiq-native JSON.
 *
 * Single endpoint: GET /api/course-management/course-package-export?courseId=...&format=...
 */
class CoursePackageExportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request                    HTTP request.
     * @param CoursePackageExportService $coursePackageExportService Course-package export service.
     * @param IUserSession               $userSession                Nextcloud user session.
     * @param ActionAuthService          $actionAuth                 ADR-023 action authorization service.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly CoursePackageExportService $coursePackageExportService,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Export a Course as Common Cartridge or scholiq-native JSON.
     *
     * @param string $courseId UUID of the Course to export.
     * @param string $format   `common-cartridge` or `scholiq-json`.
     *
     * @return DataDownloadResponse|JSONResponse The download, or a JSON error.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
     */
    #[NoAdminRequired]
    public function export(string $courseId='', string $format=''): DataDownloadResponse|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'course-package.export');

        if ($courseId === '') {
            return new JSONResponse(data: ['error' => 'courseId is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        if (in_array($format, ['common-cartridge', 'scholiq-json'], strict: true) === false) {
            return new JSONResponse(
                data: ['error' => "format must be 'common-cartridge' or 'scholiq-json'"],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            if ($format === 'common-cartridge') {
                $content     = $this->coursePackageExportService->exportCommonCartridge(courseId: $courseId, exportingUser: $user->getUID());
                $filename    = 'course-'.$courseId.'_common-cartridge.zip';
                $contentType = 'application/zip';
            } else {
                $content     = $this->coursePackageExportService->exportScholiqJson(courseId: $courseId, exportingUser: $user->getUID());
                $filename    = 'course-'.$courseId.'_scholiq.json';
                $contentType = 'application/json';
            }
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
        }//end try

        return new DataDownloadResponse(data: $content, filename: $filename, contentType: $contentType);
    }//end export()
}//end class
