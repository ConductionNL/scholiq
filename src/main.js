// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import {
	CnPageRenderer,
	CnAuditTrailWidget,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
	buildManifest,
	registerDashboardWidget,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import registry from './registry.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

// Bootstrap the library's built-in `audit-trail` widget (CnAuditTrailWidget)
// into the shared widget-type catalog. CnDetailPage's config-grid body resolves
// widget `type` via getWidgetTypeEntry (the dashboard-widget catalog), NOT via
// BUILT_IN_WIDGETS — and the library only self-seeds chart/stats-block/table/
// related into that catalog, so `audit-trail` must be registered here for the
// 36 detail-page audit-trail widgets to render. This dissolves the former
// bespoke AuditTrailWidget adapter (deleted) down to the library component and
// works around the dissolution renderer gap tracked in nextcloud-vue#89 (the
// library should self-seed audit-trail into the detail-page catalog too; once
// it does, this bootstrap can be removed outright). The v2 slot/widgetKey path
// already resolves `audit-trail` via the library's BUILT_IN_WIDGETS map.
registerDashboardWidget('audit-trail', {
	renderer: CnAuditTrailWidget,
	form: null,
	defaultContent: {},
	displayName: 'Audit trail',
	icon: 'History',
	surfaces: ['detail-page'],
})

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)

// Register library-side icon set + lib translations once at bootstrap.
registerIcons()
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[scholiq] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs only allow the
// JS/CSS allowlist through Apache — /custom_apps/<app>/l10n/<locale>.json
// 404s in those environments. Wrapping mount in the callback means silent
// boot failure. Strings fall back to their English source on miss.
function tryLoadTranslations() {
	try {
		const result = loadTranslations('scholiq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(() => {}, () => {})
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer to give Vue Router an extensible component
// object — lib barrel exports are non-extensible (webpack ESM module records)
// and Vue 2's Vue.extend() adds an internal _Ctor cache entry.
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route name IS page.id (per the lib's manifest contract).
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to dashboard, preserving prior router behaviour.
	routes.push({ path: '*', redirect: '/' })
	return routes
}

// Populate the manifest runtime context so menu `visibleIf` predicates
// (e.g. `user.primaryRole`) and the role-aware Dashboards component resolve
// against the signed-in user's role. Provided as initial state by
// PageController; absent runtime would (by lib fail-safe) hide every
// role-gated menu item. Defaults to the least-privileged role on miss.
// The set of dashboard views the signed-in user may see (resolved server-side
// from `scholiq-{role}` group membership; admins get all three, everyone gets
// 'student'). Exposed as per-role booleans so each dashboard menu item's
// `visibleIf` can gate on a scalar `eq: true` (the predicate grammar has no
// array-contains operator).
const dashboardRoles = loadState('scholiq', 'dashboardRoles', ['student']) || []
bundledManifest.runtime = {
	...(bundledManifest.runtime || {}),
	user: {
		...(bundledManifest.runtime?.user || {}),
		primaryRole: loadState('scholiq', 'primaryRole', 'learner'),
		canAdminDashboard: dashboardRoles.includes('admin'),
		canTeachDashboard: dashboardRoles.includes('teacher'),
		canLearnDashboard: dashboardRoles.includes('student'),
	},
}

// Collect the app's manifest.d/*.json fragments — require.context is resolved
// by this app's own webpack build, so it stays app-local — then hand the base
// manifest, fragments, and menu-layout to the shared pipeline (ADR-037 / ADR-044).
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

const router = new VueRouter({
	mode: 'history',
	base: generateUrl('/apps/scholiq'),
	routes: routesFromManifest(mergedManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and our `registry`) as frozen module objects in some
// bundle shapes — Vue 2's `Vue.extend()` mutates component definitions to
// attach an internal `_Ctor` cache, which throws "Cannot add property _Ctor,
// object is not extensible" against a frozen source map. Cloning yields
// extensible objects without altering the values the lib resolves at render
// time.
const pageTypesProp = { ...defaultPageTypes }
const registryProp = { ...registry }

// Boot order: initializeStores() must resolve before mount so that any
// `created()` hooks that call OR APIs run against a configured store.
;(async () => {
	// eslint-disable-next-line no-new
	new Vue({
		pinia,
		router,
		render: (h) => h(App, {
			props: {
				manifest: mergedManifest,
				registry: registryProp,
				pageTypes: pageTypesProp,
			},
		}),
	}).$mount('#content')
})()
