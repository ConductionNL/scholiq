<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

return [
    'routes' => [
        // SPA shell — renders the Nextcloud app page (main.php template).
        ['name' => 'page#index',     'url' => '/',            'verb' => 'GET'],
        // ADR-024 §4 — manifest endpoint (bundled blob, override hook deferred to v0.2).
        ['name' => 'page#manifest',  'url' => '/api/manifest', 'verb' => 'GET'],

        // xAPI LRS — external-system contract, legitimate PHP per ADR-031.
        ['name' => 'lrs#postStatements', 'url' => '/api/lrs/statements',             'verb' => 'POST'],  // ADR-002
        ['name' => 'lrs#getStatements',  'url' => '/api/lrs/statements',             'verb' => 'GET'],

        // SCORM compatibility shim — external-system contract per ADR-031.
        ['name' => 'scorm#launch', 'url' => '/api/scorm/{lessonId}/launch', 'verb' => 'GET'],  // ADR-002
        ['name' => 'scorm#api',    'url' => '/api/scorm/{lessonId}/api',    'verb' => 'POST'],

        // cmi5 JWT minting — cryptographic operation, legitimate PHP per ADR-031.
        ['name' => 'cmi5_launch#token', 'url' => '/api/lessons/{lessonId}/launch', 'verb' => 'GET'],

        // Public credential verification.
        ['name' => 'credential#verify', 'url' => '/api/credentials/{id}/verify', 'verb' => 'GET'],

        // Compliance audit-pack export — ZIP generation.
        ['name' => 'audit_pack#export',  'url' => '/api/compliance/audit/export',           'verb' => 'POST'],
        ['name' => 'audit_pack#dossier', 'url' => '/api/ai-features/{slug}/dossier',        'verb' => 'GET'],

        // Settings (kept for existing settings store compatibility).
        ['name' => 'settings#index',  'url' => '/api/settings',      'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings',      'verb' => 'POST'],
        ['name' => 'settings#load',   'url' => '/api/settings/load', 'verb' => 'POST'],

        // SPA catch-all — Vue history mode; specific routes MUST precede this.
        ['name' => 'page#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
