#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// seed-example-data.mjs — best-effort import of the scholiq OpenRegister register
// (lib/Settings/scholiq_register.json) into a running Nextcloud + OpenRegister,
// then create a small coherent example dataset so the index pages + dashboard KPI
// widgets have content. Idempotent: re-running skips objects that already exist.
//
// Run:  node tests/e2e/seed-example-data.mjs
// Env:  OR_BASE_URL (default http://localhost:8080)
//       OR_USER / OR_PASS (default admin / admin)
//
// Exit code: 0 if the register imported essentially completely (≥30 of 35 schemas)
//              and example objects were seeded — the e2e specs then run their
//              deeper assertions (no JS error / ≥1 row per index page);
//            2 if the import was partial (the OpenRegister register-import gap,
//              openregister#1487 — the e2e specs then run only the smoke checks);
//            1 only if Nextcloud is unreachable.
//
// Known limitation: OR's register-import endpoint is partial in some builds
// (openregister#1487 / scholiq#35) — this script imports what it can and logs
// what failed; the e2e index-page specs tolerate empty index pages where a
// schema couldn't be imported.

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const REPO_ROOT = join(__dirname, '..', '..')

const BASE = (process.env.OR_BASE_URL ?? 'http://localhost:8080').replace(/\/$/, '')
const USER = process.env.OR_USER ?? 'admin'
const PASS = process.env.OR_PASS ?? 'admin'
const AUTH = 'Basic ' + Buffer.from(`${USER}:${PASS}`).toString('base64')

const REGISTER_SLUG = 'scholiq'

function log(...a) { console.log('[seed]', ...a) }
function warn(...a) { console.warn('[seed]', ...a) }

async function api(method, path, body, { raw = false } = {}) {
	const headers = {
		Authorization: AUTH,
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
	let payload
	if (body !== undefined) {
		headers['Content-Type'] = 'application/json'
		payload = JSON.stringify(body)
	}
	let res
	try {
		res = await fetch(`${BASE}${path}`, { method, headers, body: payload })
	} catch (e) {
		return { ok: false, status: 0, err: String(e), json: null }
	}
	const text = await res.text()
	let json = null
	try { json = text ? JSON.parse(text) : null } catch { /* HTML / non-JSON */ }
	if (raw) return { ok: res.ok, status: res.status, text, json }
	return { ok: res.ok, status: res.status, json, text }
}

// ── 1. Ping NC ───────────────────────────────────────────────────────────────
async function pingNc() {
	const r = await api('GET', '/status.php')
	if (!r.ok || !r.json || r.json.installed !== true) {
		warn(`Nextcloud not reachable/installed at ${BASE} (status ${r.status}). Aborting.`)
		return false
	}
	log(`Nextcloud ${r.json.versionstring ?? '?'} at ${BASE} — OK`)
	return true
}

// ── 2. Import the register ───────────────────────────────────────────────────
function loadRegister() {
	return JSON.parse(readFileSync(join(REPO_ROOT, 'lib', 'Settings', 'scholiq_register.json'), 'utf8'))
}

async function existingSchemaSlugs() {
	const r = await api('GET', '/index.php/apps/openregister/api/schemas?limit=500')
	const items = r.json?.results ?? r.json?.data ?? (Array.isArray(r.json) ? r.json : [])
	return new Set(items.map((s) => s.slug).filter(Boolean))
}

async function ensureRegisterRow() {
	const r = await api('GET', '/index.php/apps/openregister/api/registers?limit=500')
	const items = r.json?.results ?? r.json?.data ?? (Array.isArray(r.json) ? r.json : [])
	const found = items.find((x) => x.slug === REGISTER_SLUG)
	if (found) { log(`register "${REGISTER_SLUG}" exists (id ${found.id})`); return found }
	const c = await api('POST', '/index.php/apps/openregister/api/registers', {
		slug: REGISTER_SLUG, title: 'Scholiq', description: 'Scholiq LVS/LMS register', version: '0.1.0',
	})
	if (c.ok) { log(`created register "${REGISTER_SLUG}" (id ${c.json?.id})`); return c.json }
	warn(`could not create register row (status ${c.status})`); return null
}

async function importRegister() {
	const register = loadRegister()
	const registerJson = JSON.stringify(register)
	const schemaNames = Object.keys(register.components?.schemas ?? {})
	log(`register declares ${schemaNames.length} schemas`)

	// Try the configurations import endpoints (these vary by OR build).
	let imported = 0
	const beforeImport = await existingSchemaSlugs()
	// (a) create a Configuration entity, then import into it
	const cfg = await api('POST', '/index.php/apps/openregister/api/configurations', {
		title: 'scholiq-seed', type: REGISTER_SLUG,
	})
	if (cfg.ok && cfg.json?.id) {
		const imp = await api('POST', `/index.php/apps/openregister/api/configurations/${cfg.json.id}/import`, { json: registerJson })
		log(`configurations/${cfg.json.id}/import → status ${imp.status}${imp.json?.message ? ` (${imp.json.message})` : ''}`)
	}
	// (b) bare configurations/import
	const imp2 = await api('POST', '/index.php/apps/openregister/api/configurations/import', { json: registerJson })
	log(`configurations/import → status ${imp2.status}${imp2.json?.message ? ` (${imp2.json.message})` : ''}`)
	if (imp2.json?.imported?.schemas) {
		log(`  imported schemas: ${imp2.json.imported.schemas.map((s) => s.slug).join(', ') || '(none)'}`)
	}

	// (c) for any scholiq schema still missing, POST it individually.
	await ensureRegisterRow()
	const after = await existingSchemaSlugs()
	imported = [...after].filter((s) => !beforeImport.has(s)).length
	const wanted = new Map(Object.entries(register.components.schemas).map(([name, s]) => [s.slug, { name, s }]))
	let createdIndividually = 0
	for (const [slug, { name, s }] of wanted) {
		if (after.has(slug)) continue
		// Build a minimal schema body OR doesn't choke on (slug/title/required/properties + x-openregister-*).
		const body = { ...s }
		const r = await api('POST', '/index.php/apps/openregister/api/schemas', body)
		if (r.ok) { createdIndividually++; log(`  + created schema "${slug}" individually`) }
		else { warn(`  ! could not create schema "${slug}" (status ${r.status})`) }
	}
	const finalSet = await existingSchemaSlugs()
	const present = [...wanted.keys()].filter((slug) => finalSet.has(slug))
	const missing = [...wanted.keys()].filter((slug) => !finalSet.has(slug))
	log(`register import: ${present.length}/${wanted.size} scholiq schemas now present` +
		(createdIndividually ? ` (${createdIndividually} created individually)` : ''))
	if (missing.length) warn(`still missing: ${missing.join(', ')}`)
	return { presentSlugs: new Set(present), missingSlugs: new Set(missing) }
}

// ── 3. Seed example objects ──────────────────────────────────────────────────
// Each entry: schemaSlug, a stable "marker" field+value to dedupe on, and the object body.
function uid(p) { return `${p}-${Date.now().toString(36)}` }

async function objectExists(slug, markerField, markerValue) {
	const r = await api('GET', `/index.php/apps/openregister/api/objects/${REGISTER_SLUG}/${slug}?${encodeURIComponent(markerField)}=${encodeURIComponent(markerValue)}&_limit=1`)
	const items = r.json?.results ?? r.json?.data ?? (Array.isArray(r.json) ? r.json : [])
	return items.length > 0 ? items[0] : null
}

async function createObject(slug, body) {
	const r = await api('POST', `/index.php/apps/openregister/api/objects/${REGISTER_SLUG}/${slug}`, body)
	if (!r.ok) { warn(`  ! create ${slug} failed (status ${r.status}): ${(r.text || '').slice(0, 160)}`); return null }
	return r.json
}

// Seed in dependency order. Returns a map slug -> first created object (for refs).
async function seedObjects(presentSlugs) {
	const created = {}
	const counts = {}
	async function seed(slug, marker, body) {
		if (!presentSlugs.has(slug)) { return null }
		const existing = await objectExists(slug, marker.field, marker.value)
		if (existing) { counts[slug] = (counts[slug] ?? 0) + 1; if (!created[slug]) created[slug] = existing; return existing }
		const obj = await createObject(slug, body)
		if (obj) { counts[slug] = (counts[slug] ?? 0) + 1; if (!created[slug]) created[slug] = obj }
		return obj
	}
	const id = (o) => o && (o.uuid ?? o.id ?? o['@self']?.uuid)
	// tenant_id must be a UUID. Use OR's active organisation if we can discover it;
	// otherwise a fixed demo UUID (OR may still reject it on multitenancy grounds —
	// then those creates 400 and we just log + continue).
	let TENANT = '00000000-0000-0000-0000-00000000d3a0'
	try {
		const meR = await api('GET', '/index.php/apps/openregister/api/registers?limit=1')
		const items = meR.json?.results ?? meR.json?.data ?? (Array.isArray(meR.json) ? meR.json : [])
		const org = items[0]?.organisation
		if (typeof org === 'string' && /^[0-9a-f-]{36}$/i.test(org)) { TENANT = org; log(`using active org as tenant_id: ${TENANT}`) }
	} catch { /* keep the fixed demo UUID */ }

	// Programme + CurriculumPlan
	const prog = await seed('programme', { field: 'code', value: 'DEMO-HBOV' }, {
		name: 'HBO-V bachelor (demo)', code: 'DEMO-HBOV', level: 'hbo', description: 'Demo nursing bachelor', tenant_id: TENANT,
	})
	const plan = await seed('curriculum-plan', { field: 'name', value: 'HBO-V curriculum (demo)' }, {
		name: 'HBO-V curriculum (demo)', kind: 'oer', formula: 'weighted-average',
		components: [{ componentId: 'c1', label: 'Exam', weight: 3, period: 'P1', kind: 'assessment' }, { componentId: 'c2', label: 'Essay', weight: 1, period: 'P1', kind: 'assignment' }],
		periods: [{ periodId: 'P1', label: 'Period 1', startDate: '2026-09-01', endDate: '2026-12-31' }], tenant_id: TENANT,
	})
	// Courses (one recursive)
	const courseRoot = await seed('course', { field: 'code', value: 'DEMO-ANAT' }, { code: 'DEMO-ANAT', name: 'Anatomy (demo)', level: 'hbo', language: 'nl', mandatoryTraining: false, tenant_id: TENANT, ...(id(plan) ? { curriculumPlanId: id(plan) } : {}), ...(id(prog) ? { programmeIds: [id(prog)] } : {}) })
	const courseSub = await seed('course', { field: 'code', value: 'DEMO-ANAT-1' }, { code: 'DEMO-ANAT-1', name: 'Anatomy — module 1 (demo)', level: 'hbo', language: 'nl', mandatoryTraining: false, tenant_id: TENANT, ...(id(courseRoot) ? { parentCourseId: id(courseRoot) } : {}) })
	const courseCompliance = await seed('course', { field: 'code', value: 'DEMO-AVG' }, { code: 'DEMO-AVG', name: 'AVG refresher (demo)', level: 'corporate', language: 'nl', mandatoryTraining: true, regulationSlug: 'AVG', tenant_id: TENANT })
	// Lessons
	for (const [n, cid] of [[1, id(courseSub)], [2, id(courseSub)], [3, id(courseRoot)], [4, id(courseRoot)], [5, id(courseCompliance)]]) {
		if (!cid) continue
		await seed('lesson', { field: 'name', value: `Demo lesson ${n}` }, { courseId: cid, name: `Demo lesson ${n}`, order: n, contentType: 'text', mandatoryTraining: n === 5, tenant_id: TENANT })
	}
	// Cohort + LearnerProfiles
	const cohort = await seed('cohort', { field: 'name', value: 'Demo cohort 2026' }, { name: 'Demo cohort 2026', period: 'P1', academicYear: '2026', learnerIds: ['demo-learner-1', 'demo-learner-2', 'demo-learner-3'], tenant_id: TENANT, ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}), ...(id(prog) ? { programmeId: id(prog) } : {}) })
	for (let n = 1; n <= 3; n++) {
		await seed('learner-profile', { field: 'ncUserId', value: `demo-learner-${n}` }, { ncUserId: `demo-learner-${n}`, givenName: `Demo${n}`, familyName: 'Learner', roles: n === 1 ? ['learner', 'manager'] : ['learner'], tenant_id: TENANT })
	}
	// Sessions
	for (const [n, when] of [[1, '2026-09-07T10:00:00Z'], [2, '2026-09-14T10:00:00Z']]) {
		if (!id(cohort)) break
		await seed('session', { field: 'title', value: `Demo session ${n}` }, { cohortId: id(cohort), title: `Demo session ${n}`, startsAt: when, endsAt: when.replace('10:00', '12:00'), location: 'Room A', tenant_id: TENANT, ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}) })
	}
	// Materials
	for (const n of [1, 2]) await seed('material', { field: 'title', value: `Demo material ${n}` }, { title: `Demo material ${n}`, kind: 'reading', fileRef: `demo://material/${n}`, order: n, tenant_id: TENANT, ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}) })
	// Rubric + Assignments + Submissions
	const rubric = await seed('rubric', { field: 'name', value: 'Demo rubric' }, { name: 'Demo rubric', criteria: [{ criterionId: 'r1', label: 'Content', weight: 1, levels: [{ levelId: 'l1', label: 'Poor', points: 1 }, { levelId: 'l2', label: 'Good', points: 5 }] }], maxPoints: 5, tenant_id: TENANT })
	const a1 = await seed('assignment', { field: 'title', value: 'Demo assignment 1' }, { title: 'Demo assignment 1', instructions: 'Write an essay.', dueAt: '2026-10-01', maxPoints: 10, allowLateSubmission: true, latePenaltyPercent: 10, tenant_id: TENANT, ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}), ...(id(rubric) ? { rubricId: id(rubric) } : {}) })
	await seed('assignment', { field: 'title', value: 'Demo assignment 2' }, { title: 'Demo assignment 2', instructions: 'Group work.', dueAt: '2026-11-01', maxPoints: 10, groupSubmission: true, tenant_id: TENANT, ...(id(courseCompliance) ? { courseId: id(courseCompliance) } : {}) })
	if (id(a1)) { for (const ln of ['demo-learner-1', 'demo-learner-2']) await seed('submission', { field: 'assignmentId', value: id(a1) }, { assignmentId: id(a1), learnerIds: [ln], attachmentRefs: [`demo://sub/${ln}`], submittedAt: '2026-09-30', tenant_id: TENANT }) }
	// Assessment + Item + Result
	const item = await seed('item', { field: 'title', value: 'Demo MC item' }, { name: 'Demo MC item', title: 'Demo MC item', interactionType: 'choice', qtiBody: '<qti-assessment-item/>', correctResponse: { value: 'A' }, maxScore: 1, tenant_id: TENANT })
	const assess = await seed('assessment', { field: 'title', value: 'Demo quiz' }, { title: 'Demo quiz', scoringScheme: 'points', maxAttempts: 1, keepScore: 'last', tenant_id: TENANT, ...(id(item) ? { itemRefs: [{ itemId: id(item), points: 1 }] } : {}), ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}) })
	if (id(assess)) await seed('assessment-result', { field: 'assessmentId', value: id(assess) }, { assessmentId: id(assess), learnerId: 'demo-learner-1', attemptNumber: 1, responses: [], startedAt: '2026-09-15T10:00:00Z', submittedAt: '2026-09-15T10:30:00Z', tenant_id: TENANT })
	// GradeScale + GradeEntries + FinalGrade
	const scale = await seed('grade-scale', { field: 'name', value: 'NL 1-10 (demo)' }, { name: 'NL 1-10 (demo)', tenant_id: TENANT })
	for (let n = 1; n <= 3; n++) await seed('grade-entry', { field: 'value', value: 6 + n }, { learnerId: 'demo-learner-1', value: 6 + n, period: 'P1', componentId: 'c1', weight: 1, lifecycle: 'published', tenant_id: TENANT })
	await seed('final-grade', { field: 'learnerId', value: 'demo-learner-1' }, { learnerId: 'demo-learner-1', value: 7.5, passed: true, tenant_id: TENANT, ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}) })
	// LearningPlan stack
	const lpt = await seed('learning-plan-template', { field: 'kind', value: 'opp' }, { name: 'OPP-VO template (demo)', kind: 'opp', goalDomains: ['leren-en-ontwikkeling'], requiredSignerRoles: ['learner', 'parent', 'coordinator'], tenant_id: TENANT })
	const lp = await seed('learning-plan', { field: 'learnerId', value: 'demo-learner-2' }, { learnerId: 'demo-learner-2', kind: 'opp', period: '2026-2027', version: 1, goals: [{ goalId: 'g1', description: 'Improve reading', domain: 'leren-en-ontwikkeling', status: 'open' }], tenant_id: TENANT, ...(id(lpt) ? { templateId: id(lpt) } : {}), ...(id(cohort) ? { cohortId: id(cohort) } : {}) })
	if (id(lp)) { await seed('learning-plan-evaluation', { field: 'learningPlanId', value: id(lp) }, { learningPlanId: id(lp), narrative: 'First review', nextReviewAt: '2027-01-15', tenant_id: TENANT }); await seed('signature', { field: 'subjectId', value: id(lp) }, { subjectId: id(lp), subjectVersion: 1, signerId: 'coordinator-1', signerRole: 'coordinator', signedAt: '2026-09-10', assuranceLevel: 'basic', method: 'click-to-confirm', tenant_id: TENANT }) }
	// Attendance stack
	const thr = await seed('attendance-threshold', { field: 'name', value: 'Leerplicht 16uur (demo)' }, { name: 'Leerplicht 16uur (demo)', kind: 'leerplicht-16uur', scope: 'per-learner', window: { type: 'rolling-weeks', weeks: 4 }, metric: 'unexcused-lesuren', limit: 16, lesuurMinutes: 60, onCross: { notify: true, notifyRoles: ['mentor', 'coordinator'], createFlag: true, dataExchangeTarget: 'leerplicht' }, active: true, tenant_id: TENANT })
	for (let n = 1; n <= 5; n++) { if (!id(cohort)) break; await seed('attendance-record', { field: 'learnerId', value: `demo-learner-${(n % 3) + 1}` }, { learnerId: `demo-learner-${(n % 3) + 1}`, status: n === 4 ? 'absent-unexcused' : 'present', minutesAttended: n === 4 ? 0 : 120, markedBy: 'teacher-1', markedAt: `2026-09-0${n}T12:00:00Z`, tenant_id: TENANT, ...(id(cohort) ? { cohortId: id(cohort) } : {}) }) }
	await seed('excuse-request', { field: 'learnerId', value: 'demo-learner-3' }, { learnerId: 'demo-learner-3', submittedBy: 'parent-3', dateFrom: '2026-09-08', dateTo: '2026-09-09', reason: 'illness', reasonKind: 'illness', submittedAuthLevel: 'substantial', tenant_id: TENANT })
	if (id(thr)) await seed('attendance-flag', { field: 'attendanceThresholdId', value: id(thr) }, { learnerId: 'demo-learner-2', attendanceThresholdId: id(thr), windowStart: '2026-09-01', windowEnd: '2026-09-28', metricValue: 16, breachingRecordIds: [], tenant_id: TENANT })
	// Compliance: Regulations, Attestations, Credentials, Enrolments
	for (const slug of ['AVG', 'NIS2']) await seed('regulation', { field: 'slug', value: slug }, { slug, name: `${slug} (demo)`, tenant_id: TENANT })
	for (let n = 1; n <= 2; n++) await seed('attestation', { field: 'learnerId', value: `demo-learner-${n}` }, { learnerId: `demo-learner-${n}`, lessonId: id(courseCompliance) ?? 'demo-lesson', courseId: id(courseCompliance) ?? 'demo-course', regulationSlug: 'AVG', score: 90, lifecycle: 'drafted', tenant_id: TENANT })
	for (let n = 1; n <= 2; n++) await seed('credential', { field: 'learnerId', value: `demo-learner-${n}` }, { learnerId: `demo-learner-${n}`, kind: 'certificate', issuedAt: '2026-09-01', issuedBy: 'Conduction', source: 'system', regulationSlug: 'AVG', lifecycle: 'issued', tenant_id: TENANT, ...(id(courseCompliance) ? { courseId: id(courseCompliance) } : {}) })
	for (let n = 1; n <= 2; n++) await seed('enrolment', { field: 'learnerId', value: `demo-learner-${n}` }, { learnerId: `demo-learner-${n}`, courseId: id(courseCompliance) ?? id(courseRoot) ?? 'demo-course', mandatory: n === 1, dueDate: '2026-12-01', source: 'bulk', tenant_id: TENANT, ...(id(cohort) ? { cohortId: id(cohort) } : {}) })
	// xAPI, DataExchange, AiFeature
	await seed('xapi-statement', { field: 'verb', value: 'completed' }, { actor: { account: { name: 'demo-learner-1' } }, verb: { id: 'http://adlnet.gov/expapi/verbs/completed' }, object: { id: 'demo://lesson/5' }, version: '1.0.3', timestamp: '2026-09-30T10:00:00Z', tenant_id: TENANT })
	await seed('data-mapping-profile', { field: 'name', value: 'Demo BRON mapping' }, { name: 'Demo BRON mapping', target: 'bron-rod', tenant_id: TENANT })
	await seed('data-exchange-job', { field: 'target', value: 'bron-rod' }, { direction: 'export', target: 'bron-rod', scope: { cohortId: id(cohort) ?? null }, lifecycle: 'queued', requestedBy: 'admin', tenant_id: TENANT })
	await seed('ai-feature', { field: 'slug', value: 'demo-adaptive-paths' }, { slug: 'demo-adaptive-paths', name: 'Adaptive learning paths (demo)', riskCategory: 'high', lifecycle: 'disabled', tenant_id: TENANT })

	return counts
}

// ── main ─────────────────────────────────────────────────────────────────────
const TOTAL_SCHEMAS = 35
const FULL_IMPORT_THRESHOLD = 30 // ≥ this many of the 35 = "complete enough" → exit 0

async function main() {
	if (!(await pingNc())) process.exit(1)
	const { presentSlugs } = await importRegister()
	if (presentSlugs.size === 0) {
		warn('no scholiq schemas present in OR after import — index pages will be empty. (openregister#1487)')
		process.exit(2) // partial — e2e specs run smoke checks only
	}
	const counts = await seedObjects(presentSlugs)
	const total = Object.values(counts).reduce((a, b) => a + b, 0)
	log(`seeded/verified ${total} objects across ${Object.keys(counts).length} schemas: ${JSON.stringify(counts)}`)
	if (presentSlugs.size < FULL_IMPORT_THRESHOLD) {
		warn(`only ${presentSlugs.size}/${TOTAL_SCHEMAS} schemas imported (OpenRegister register-import gap — openregister#1487). ` +
			`Seeded what was possible; e2e specs will run smoke checks only until the full register imports.`)
		process.exit(2)
	}
	log(`register fully imported (${presentSlugs.size}/${TOTAL_SCHEMAS}) and example data seeded.`)
	process.exit(0)
}

main().catch((e) => { console.error('[seed] fatal:', e); process.exit(1) })
