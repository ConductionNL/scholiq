<?php

/**
 * Scholiq Course Package Import Controller
 *
 * Thin HTTP endpoint for uploading and importing an IMS Common Cartridge 1.3
 * or Moodle backup (`.mbz`) course package. All heavy lifting is delegated
 * to CoursePackageImportService — this controller is intentionally thin per
 * ADR-022.
 *
 * Legitimate PHP per ADR-031 §"NC framework requirement — thin controller":
 * the import requires file upload handling (`$_FILES`) which cannot be
 * expressed declaratively, and CoursePackageImportService is itself an
 * ADR-031 "external-format import" exception.
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
 * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\ActionAuthService;
use OCA\Scholiq\Service\CoursePackageImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Handles course-package upload and import.
 *
 * Single endpoint: POST /api/course-management/course-package-import
 *
 * Multipart form field:
 *   - file : the `.imscc`/`.zip` (Common Cartridge) or `.mbz` (Moodle backup) file (required)
 *
 * Returns JSON: the created `CoursePackageImportReport`, or `{ error: "message" }` on failure.
 */
class CoursePackageImportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request                    HTTP request.
     * @param CoursePackageImportService $coursePackageImportService Course-package import service.
     * @param IUserSession               $userSession                Nextcloud user session.
     * @param ActionAuthService          $actionAuth                 ADR-023 action authorization service.
     * @param IConfig                    $config                     Nextcloud config for tenant resolution.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly CoursePackageImportService $coursePackageImportService,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly IConfig $config,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Import a Common Cartridge or Moodle backup course package.
     *
     * CSRF is required (mutating endpoint). The caller's tenant is resolved
     * from the authenticated user's per-user tenant binding, same pattern as
     * `QtiImportController::import()` / `AuditPackExportController::export()`.
     *
     * @return JSONResponse The created `CoursePackageImportReport`, or an error.
     *
     * @spec openspec/changes/course-package-import-export/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
     */
    #[NoAdminRequired]
    public function import(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'Not authenticated'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        $this->actionAuth->requireAction(user: $user, action: 'course-package.import');

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

        $sourceFilename = (string) ($uploadedFile['name'] ?? 'package');

        // Resolve the caller's tenant — same pattern as QtiImportController::import().
        $tenantId     = $this->config->getSystemValue('instanceid', '');
        $userTenantId = $this->config->getUserValue(
            userId: $user->getUID(),
            appName: 'scholiq',
            key: 'tenant_id',
            default: ''
        );
        if ($userTenantId !== '') {
            $tenantId = $userTenantId;
        }

        try {
            $report = $this->coursePackageImportService->import(
                packagePath: $tmpPath,
                sourceFilename: $sourceFilename,
                importedBy: $user->getUID(),
                tenantId: $tenantId,
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => 'Import failed: '.$e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(data: $report, statusCode: Http::STATUS_OK);
    }//end import()
}//end class
