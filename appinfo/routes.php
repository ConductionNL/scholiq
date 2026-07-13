<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

/*
 * AppHost adoption (ADR-040): the settings, preferences, health and metrics
 * controllers are the OpenRegister AppHost generics, aliased onto Scholiq's
 * conventional controller class names in lib/AppInfo/Application.php via
 * \OCA\OpenRegister\AppHost\Bootstrap::register(). The route entries below keep
 * Scholiq's URLs and route names so info.xml navigation + frontend
 * `generateUrl` calls are unchanged; only the controller bodies are now engine-owned.
 *
 * Routes::standard() is intentionally NOT used for the SPA shell: Scholiq's
 * `page#index`/`page#catchAll` keep pointing at the bespoke PageController,
 * which provides role-aware dashboard initial-state (primaryRole / dashboardRole
 * / dashboardRoles) that the generic GenericDashboardController does not — this
 * is the role-aware-dashboards domain we keep. The canonical settings/preferences
 * /health/metrics routes are reproduced here pointing at the aliased generics.
 */

return [
    'routes' => [
        // SPA shell — bespoke PageController (role-aware initial state).
        ['name' => 'page#index',     'url' => '/',            'verb' => 'GET'],
        // ADR-024 §4 — manifest endpoint (bundled blob).
        ['name' => 'page#manifest',  'url' => '/api/manifest', 'verb' => 'GET'],

        // Public credential verification — no auth, per ADR-031 external-system contract.
        // Controller: CredentialVerifyController (slug: credentialVerify)
        ['name' => 'credentialVerify#verify', 'url' => '/api/credentials/{id}/verify', 'verb' => 'GET'],

        // Admin key management — admin-only via #[AuthorizedAdminSetting], cryptographic operation (ADR-031).
        // Controller: KeyAdminController (slug: keyAdmin)
        ['name' => 'keyAdmin#generateKey', 'url' => '/api/credentials/admin/generate-key', 'verb' => 'POST'],
        ['name' => 'keyAdmin#keyStatus',   'url' => '/api/credentials/admin/key-status',   'verb' => 'GET'],

        // Compliance audit-pack export — ZIP generation, user-invokable action (ADR-023: audit-pack.export).
        // Controller: AuditPackExportController (slug: auditPackExport)
        ['name' => 'auditPackExport#export', 'url' => '/api/compliance/audit/export', 'verb' => 'POST'],

        // QTI package import — user-invokable action (ADR-023: qti.import).
        // Controller: QtiImportController (slug: qtiImport)
        ['name' => 'qtiImport#import', 'url' => '/api/assessment/qti-import', 'verb' => 'POST'],

        // School-year rollover wizard — proposal + side-effect-free preview,
        // authorized via the ADR-023 action matrix (rollover.plan).
        // Controller: RolloverController (slug: rollover)
        ['name' => 'rollover#proposeMapping', 'url' => '/api/rollover/propose', 'verb' => 'GET'],
        ['name' => 'rollover#preview',        'url' => '/api/rollover/{planId}/preview', 'verb' => 'POST'],

        // External-training multi-object actions — authorized via the ADR-023
        // action matrix (external-training.bulk-record / .issue-credential).
        // Controller: ExternalTrainingController (slug: externalTraining)
        ['name' => 'externalTraining#bulkRecord',      'url' => '/api/external-training/bulk',                  'verb' => 'POST'],
        ['name' => 'externalTraining#issueCredential', 'url' => '/api/external-training/{recordId}/credential', 'verb' => 'POST'],
        ['name' => 'externalTraining#learnerCoverage', 'url' => '/api/external-training/coverage',              'verb' => 'GET'],

        // LTI 1.3 tool placement launch — delegates to OpenConnector's
        // lti-13-platform Platform-role launch-initiation surface (opaque
        // proxy, no LTI protocol code here). Any authenticated caller may
        // launch a placement they can resolve; #[NoAdminRequired] +
        // #[NoCSRFRequired] (state-changing but session-authenticated, no
        // cross-site form target).
        // Controller: LtiToolPlacementController (slug: ltiToolPlacement)
        ['name' => 'ltiToolPlacement#launch', 'url' => '/api/lti-placements/{placementId}/launch', 'verb' => 'POST'],

        // Personal timetable — the caller's own sessions for a window, resolved
        // from cohort membership (teacher/learner) via ObjectService (RBAC-scoped).
        // Read-only; #[NoAdminRequired] (any signed-in user) + #[NoCSRFRequired] (GET read).
        // Controller: TimetableController (slug: timetable)
        ['name' => 'timetable#mine', 'url' => '/api/timetable/mine', 'verb' => 'GET'],

        // Observability (ADR-006 / ADR-040) — AppHost generic controllers.
        // health#index → GenericHealthController (PUBLIC, declarative checks).
        ['name' => 'health#index',  'url' => '/api/health',  'verb' => 'GET'],
        // metrics#index → GenericMetricsController (admin-only Prometheus text).
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],

        // Settings (admin-only) — AppHost GenericSettingsController.
        ['name' => 'settings#index',  'url' => '/api/settings',      'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings',      'verb' => 'POST'],
        ['name' => 'settings#load',   'url' => '/api/settings/load', 'verb' => 'POST'],

        // ADR-023 action-authorization matrix (admin-only via #[AuthorizedAdminSetting]).
        ['name' => 'actionMatrix#getMatrix', 'url' => '/api/admin/action-matrix', 'verb' => 'GET'],
        ['name' => 'actionMatrix#setMatrix', 'url' => '/api/admin/action-matrix', 'verb' => 'PUT'],

        // Generic per-user preferences — AppHost GenericPreferencesController.
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // SPA catch-all — Vue history mode; specific routes MUST precede this.
        ['name' => 'page#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
