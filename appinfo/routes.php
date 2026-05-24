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
        ['name' => 'credential#verify', 'url' => '/api/credentials/{id}/verify', 'verb' => 'GET'],

        // Admin key management — admin-only, cryptographic operation (ADR-031).
        ['name' => 'key_admin#generateKey', 'url' => '/api/credentials/admin/generate-key', 'verb' => 'POST'],
        ['name' => 'key_admin#keyStatus',   'url' => '/api/credentials/admin/key-status',   'verb' => 'GET'],

        // Compliance audit-pack export — ZIP generation.
        ['name' => 'audit_pack#export',  'url' => '/api/compliance/audit/export',           'verb' => 'POST'],
        ['name' => 'audit_pack#dossier', 'url' => '/api/ai-features/{slug}/dossier',        'verb' => 'GET'],

        // QTI package import — external-format import, legitimate PHP per ADR-031.
        ['name' => 'qti_import#import', 'url' => '/api/assessment/qti-import', 'verb' => 'POST'],

        // App health observability — AdminHealth dashboard page (admin-only, ADR-031 exception).
        ['name' => 'health#index', 'url' => '/api/admin/health', 'verb' => 'GET'],

        // Settings (kept for existing settings store compatibility).
        ['name' => 'settings#index',  'url' => '/api/settings',      'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings',      'verb' => 'POST'],
        ['name' => 'settings#load',   'url' => '/api/settings/load', 'verb' => 'POST'],

        // SPA catch-all — Vue history mode; specific routes MUST precede this.
        ['name' => 'page#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
