<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

return [
    'routes' => [
        // SPA shell — renders the Nextcloud app page (main.php template).
        ['name' => 'page#index',     'url' => '/',            'verb' => 'GET'],
        // ADR-024 §4 — manifest endpoint (bundled blob, override hook deferred to v0.2).
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

        // App health observability — admin-only via #[AuthorizedAdminSetting] (ADR-031 exception).
        ['name' => 'health#index', 'url' => '/api/admin/health', 'verb' => 'GET'],

        // Settings (admin-only via #[AuthorizedAdminSetting]).
        ['name' => 'settings#index',  'url' => '/api/settings',      'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings',      'verb' => 'POST'],
        ['name' => 'settings#load',   'url' => '/api/settings/load', 'verb' => 'POST'],

        // ADR-023 action-authorization matrix (admin-only via #[AuthorizedAdminSetting]).
        ['name' => 'actionMatrix#getMatrix', 'url' => '/api/admin/action-matrix', 'verb' => 'GET'],
        ['name' => 'actionMatrix#setMatrix', 'url' => '/api/admin/action-matrix', 'verb' => 'PUT'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // SPA catch-all — Vue history mode; specific routes MUST precede this.
        ['name' => 'page#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
