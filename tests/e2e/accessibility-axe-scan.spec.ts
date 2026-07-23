import { test, expect } from './fixtures'
import AxeBuilder from '@axe-core/playwright'
import manifest from '../../src/manifest.json'

/**
 * accessibility-conformance-statement — automated accessibility scan (axe-core).
 *
 * Runs an axe-core scan (WCAG 2.1 A/AA rule set) against a representative
 * SAMPLE of `src/manifest.json` pages, reusing index-pages.spec.ts's
 * manifest-driven page-iteration pattern rather than a new harness. Runs in
 * the default `chromium` Playwright project (playwright.config.ts) so it
 * executes on every PR.
 *
 * Sampling, not exhaustive coverage: design.md's own Risks/Trade-offs
 * section names this explicitly — a full sweep of all 250+ manifest pages is
 * a natural follow-up once this sampled scan's runtime/noise level are
 * known; sampling now is chosen over a slow, potentially flaky full sweep at
 * scope S. The sample below is every `type: "index"` page (cheap, uniform
 * shape, the bulk of the manifest) PLUS a fixed list of `type: "custom"`
 * pages covering each major surface family (dashboards, the new
 * accessibility-conformance pages, a wizard, a review board) so the sample
 * is not skewed toward one page archetype.
 *
 * A `serious` or `critical` violation fails the test for that page — this is
 * the evidence an `AccessibilityStatement` with `evaluationMethod:
 * automated-scan` cites (spec.md "Automated accessibility scans MUST be
 * wired into the Playwright suite as citable evidence").
 *
 * @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-automated-accessibility-scans-must-be-wired-into-the-playwright-suite-as-citable-evidence
 */

const APP_BASE = '/index.php/apps/scholiq'

// `serious`/`critical` fail the run; `minor`/`moderate` are recorded but not
// blocking, matching the spec's own "fails on a serious or critical
// violation" wording.
const BLOCKING_IMPACTS = new Set(['serious', 'critical'])

type SampledPage = { id: string; route: string }

// Every `type: "index"` page with a static route — the bulk of the manifest,
// uniform CnIndexPage shape, cheap to sample exhaustively.
const indexPages: SampledPage[] = (manifest as any).pages
	.filter((p: any) => p.type === 'index' && typeof p.route === 'string' && !p.route.includes(':'))
	.map((p: any) => ({ id: p.id, route: p.route }))

// A fixed, named sample of `type: "custom"` pages spanning the manifest's
// other page archetypes, so the scan is not skewed entirely toward index
// pages. Includes every page this change adds.
const customPageIds = [
	'Dashboard',
	'Compliance',
	'AccessibilityStatement',
	'AccessibilityFeedbackCreate',
	'RolloverWizard',
	'AdmissionsReviewBoard',
]
const customPages: SampledPage[] = (manifest as any).pages
	.filter((p: any) => customPageIds.includes(p.id) && typeof p.route === 'string')
	.map((p: any) => ({ id: p.id, route: p.route }))

const sample: SampledPage[] = [...indexPages, ...customPages]

test.describe(`Scholiq axe-core accessibility scan (WCAG 2.1 A/AA, ${sample.length} sampled pages)`, () => {
	for (const p of sample) {
		test(`${p.id} — ${APP_BASE}${p.route} has no serious/critical WCAG 2.1 A/AA violations`, async ({ loggedInPage: page }) => {
			await page.goto(`${APP_BASE}${p.route === '/' ? '/' : p.route}`, { waitUntil: 'domcontentloaded', timeout: 20_000 })
			await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

			const results = await new AxeBuilder({ page })
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.analyze()

			const blocking = results.violations.filter((v) => BLOCKING_IMPACTS.has(v.impact ?? ''))

			if (blocking.length > 0) {
				const detail = blocking
					.map((v) => `${v.id} (${v.impact}): ${v.help} — ${v.nodes.length} node(s)`)
					.join('\n')
				expect(blocking, `${p.id}: serious/critical WCAG 2.1 A/AA violation(s):\n${detail}`).toHaveLength(0)
			}
		})
	}
})
