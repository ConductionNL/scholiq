# Design — Dashboard

## 1. Role Detection Architecture

### 1.1 `RoleDetectionService`

```php
class RoleDetectionService
{
    public function resolveUserRole(string $ncUserId): string
    {
        // 1. NC admin check (highest precedence)
        if ($this->groupManager->isAdmin($ncUserId)) {
            return 'admin';
        }

        // 2. LearnerProfile lookup in OpenRegister
        $profiles = $this->objectService->getObjects('scholiq-learner-profile', [
            'nc_user_id' => $ncUserId,
            'tenant_id'  => $this->tenantId,
        ]);

        if (empty($profiles)) {
            return 'learner'; // default, show profile completion prompt
        }

        $roles = $profiles[0]['roles'] ?? ['learner'];

        // Role priority order: compliance-officer > hr > admin > instructor > learner
        $priority = ['compliance-officer' => 5, 'hr' => 4, 'admin' => 3, 'instructor' => 2, 'learner' => 1];
        usort($roles, fn($a, $b) => ($priority[$b] ?? 0) <=> ($priority[$a] ?? 0));

        return $roles[0] ?? 'learner';
    }
}
```

The resolved role is injected into the PHP template:
```php
// PageController::index()
$resolvedRole = $this->roleDetectionService->resolveUserRole($uid);
$config = [
    'user_role'       => $resolvedRole,
    'guard_failed'    => !$this->openRegisterGuard->isInstalled(),
    'mydash_installed'=> $this->appManager->isInstalled('mydash'),
    'nc_user_id'      => $uid,
    'is_admin'        => $this->groupManager->isAdmin($uid),
];
```

### 1.2 `DashboardRouter.vue`

```js
// src/components/DashboardRouter.vue
export default {
  created() {
    const role = window.scholiq_config?.user_role || 'learner'
    const routeMap = {
      'compliance-officer': '/dashboard/compliance',
      'hr':                 '/dashboard/compliance',
      'admin':              '/dashboard/admin',
      'instructor':         '/dashboard/instructor', // Phase 2
      'learner':            '/dashboard/learner',
    }
    const target = routeMap[role] || '/dashboard/learner'
    if (this.$route.path !== target) {
      this.$router.replace(target)
    }
  },
  render: () => null, // invisible redirect component
}
```

Mounted at the `/` route:
```js
{ path: '/', component: DashboardRouter },
{ path: '/dashboard/compliance', component: () => import('../views/ComplianceOfficerDashboard.vue') },
{ path: '/dashboard/learner',    component: () => import('../views/LearnerDashboard.vue')           },
{ path: '/dashboard/admin',      component: () => import('../views/AdminDashboard.vue')             },
```

---

## 2. `DashboardController`

```
GET  /api/dashboard/compliance   → compliance officer KPI aggregation
GET  /api/dashboard/learner      → learner mandatory enrolment list
GET  /api/dashboard/admin        → app health status
```

### 2.1 Compliance KPI response

```json
{
  "regulations": [
    {
      "slug": "NIS2",
      "name": "NIS2 Cyberbeveiligingswet",
      "audience_scope": "board",
      "coverage_percent": 83.3,
      "rag_status": "amber",
      "enrolled": 12,
      "completed": 10,
      "overdue": 1,
      "last_campaign_date": "2026-10-01T00:00:00Z",
      "trend_12m": [60, 65, 70, 75, 80, 83, 83, 83, 83, 83, 83, 83]
    }
  ],
  "active_campaigns": 2,
  "recent_exports": [
    { "regulation_slug": "AVG", "exported_at": "2026-05-01T14:23:00Z", "actor": "j.de.vries" }
  ],
  "mydash_url": "/index.php/apps/mydash/#/scholiq-analytics"
}
```

`coverage_percent` and `trend_12m` come from `CoverageComputationService`; `trend_12m` is computed by querying the audit trail for each of the previous 12 months (caching: once per day per regulation per tenant).

### 2.2 Learner task-list response

```json
{
  "mandatory_enrolments": [
    {
      "id": "<uuid>",
      "course_id": "<uuid>",
      "course_name": "AVG Basis 2026",
      "regulation_slug": "AVG",
      "status": "active",
      "due_date": "2026-06-30",
      "days_remaining": 50,
      "rag": "green",
      "progress_percent": 0,
      "first_lesson_id": "<uuid>",
      "credential_verify_url": null
    }
  ]
}
```

### 2.3 Admin health response

```json
{
  "openregister_connected": true,
  "openconnector_installed": true,
  "schemas_registered": ["scholiq-course","scholiq-lesson","scholiq-enrolment","scholiq-credential","scholiq-attestation","scholiq-audit-event","scholiq-regulation","scholiq-compliance-campaign"],
  "audit_trail_events_24h": 152,
  "last_audit_pack_export": "2026-05-01T14:23:00Z"
}
```

---

## 3. Vue Components

### 3.1 `ComplianceOfficerDashboard.vue`

Layout: `CnDashboardPage` from `@conduction/nextcloud-vue`.

Structure:
```
CnDashboardPage
├── Header: "Compliance Dashboard" + "View in MyDash" button (conditionally rendered)
├── Section: KPI Cards Grid (responsive 3-col)
│   └── RegulationKpiCard.vue × N regulations
│       ├── apexcharts radialBar (coverage %)
│       ├── RAG badge (red/amber/green)
│       ├── enrolled / completed / overdue counts
│       └── "Export" + "Campaign" action buttons
└── Section: Coverage Table (CnDataTable)
    ├── Columns: regulation, enrolled, completed, overdue, coverage %, last campaign, actions
    ├── Sortable by all numeric columns
    └── Actions: "Create campaign" (opens BulkEnrolmentModal) | "Export" (opens AuditPackExportModal)
```

### 3.2 `RegulationKpiCard.vue`

Props: `{ regulation: RegulationCoverage }`.

```
<template>
  <NcCard :class="['regulation-kpi-card', `rag--${regulation.rag_status}`]">
    <template #title>{{ regulation.name }}</template>
    <apexchart type="radialBar" :series="[regulation.coverage_percent]" :options="gaugeOptions" />
    <div class="kpi-stats">
      <span class="enrolled">{{ regulation.enrolled }}</span>
      <span class="completed">{{ regulation.completed }}</span>
      <span class="overdue" :class="{ 'text-error': regulation.overdue > 0 }">{{ regulation.overdue }}</span>
    </div>
    <div class="kpi-actions">
      <NcButton @click="$emit('create-campaign', regulation.slug)">Campagne</NcButton>
      <NcButton @click="$emit('export-audit', regulation.slug)">Exporteer</NcButton>
    </div>
  </NcCard>
</template>
```

CSS: `.rag--red` → `border-left: 4px solid var(--color-error)`. `.rag--amber` → `border-left: 4px solid var(--color-warning)`. `.rag--green` → `border-left: 4px solid var(--color-success)`. All using NC design tokens (double-fallback per REQ-NA-004).

### 3.3 `LearnerDashboard.vue`

Layout: `CnIndexPage` from `@conduction/nextcloud-vue`.

Structure:
```
CnIndexPage
├── Header: "Mijn verplichte trainingen"
├── Profile-incomplete banner (conditionally, if no LearnerProfile)
└── Task list (CnDataTable, slim version)
    ├── Sorted by due_date ascending
    ├── Columns: course name, regulation, status badge, due_date (coloured), "Start/Resume" button
    └── NcEmptyContent if zero mandatory enrolments: "Geen verplichte trainingen op dit moment"
```

### 3.4 `AdminDashboard.vue`

Minimal: health-check widget using `GET /api/dashboard/admin`. Shows:
- OpenRegister connected: green checkmark or red X.
- Schemas registered: count.
- Audit events last 24h: count.
- Link to admin settings: "Beheer instellingen".

---

## 4. 12-Month Trend Computation

`trend_12m` is an array of 12 coverage % values, one per calendar month, ending with the current month.

Query strategy per month M: count distinct learners with xAPI `completed` statement timestamped ≤ end-of-M, divided by total mandatory Enrolments created ≤ end-of-M for the regulation. Cached with a 24h TTL per (regulation, tenant).

In v0.1 if historical data does not exist, earlier months default to 0; the trend chart shows the ramp-up from Scholiq installation.

---

## 5. MyDash Integration

```js
// computed in ComplianceOfficerDashboard.vue
const mydashUrl = computed(() => {
  const config = window.scholiq_config
  if (!config.mydash_installed) return null
  return `/index.php/apps/mydash/#/scholiq-analytics?tenant=${config.tenant_id}`
})
```

If `mydash_installed=false` (injected by PHP from `IAppManager::isInstalled('mydash')`), the "View in MyDash" button is hidden via `v-if="mydashUrl"`.

---

## 6. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | `ObjectService` | LearnerProfile role lookup; read Regulation, Enrolment, Credential |
| OCP\IGroupManager | — | Admin check (isAdmin()) |
| OCP\IAppManager | — | mydash_installed check |
| CoverageComputationService | compliance-audit spec | Regulation coverage % for KPI cards |
| AuditPackExportService | compliance-audit spec | Export action from dashboard |
| BulkEnrolmentService | enrolment spec | "Create campaign" action |
| @conduction/nextcloud-vue | CnDashboardPage, CnIndexPage | Layout primitives |
| apexcharts (via nc-vue) | radialBar, line | KPI gauge + trend chart |
| MyDash | deep-link URL | Heavy analytics delegation |

---

## 7. Performance Considerations

- `GET /api/dashboard/compliance` aggregates coverage % from `CoverageComputationService` (cached), active campaigns count, and recent exports list. Total expected latency: < 500ms if coverage cache is warm.
- `GET /api/dashboard/learner` is a simple Enrolment list query with 3 filters; expected < 200ms.
- `trend_12m` computation is the most expensive; cached per day. First load after installation may be slow; show a skeleton loader while fetching.

---

## 8. Wedge Scope Exclusions (Phase 2+)

| Excluded | Role | Deferred to |
|---|---|---|
| Mentor absence-pattern dashboard | mentor | Phase 2 |
| Manager team heat-map + skill-area map | manager | V1 |
| Principal Cito results overview | principal | Phase 2 |
| Parent grade digest + OPP signing tasks | parent | Phase 2 |
| Pupil grade-impact view | learner (K-12) | Phase 2 |
| Instructor cohort distribution + soft-publish queue | instructor | V1 |
| AI Act post-market monitoring dashboard | compliance-officer | Enterprise + ADR-005 |
| Cross-tenant benchmarking | compliance-officer | Enterprise + mydash |
