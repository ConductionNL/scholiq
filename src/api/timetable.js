/**
 * Scholiq personal-timetable API.
 *
 * Stateless functions over @nextcloud/axios + generateUrl (no Pinia store,
 * per ADR-004 store-pattern): the personal timetable is a read surface, so a
 * thin fetch helper is all the view needs. The backend resolves the caller's
 * own sessions from cohort membership and RBAC-scopes the result — the client
 * only passes the requested window.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Fetch the signed-in caller's own sessions for a time window.
 *
 * When `from`/`to` are omitted the backend defaults to the current ISO week.
 * A caller with no cohorts receives an empty `sessions` list (HTTP 200), never
 * an error.
 *
 * @param {string} [from] Inclusive ISO 8601 window start.
 * @param {string} [to]   Exclusive ISO 8601 window end.
 *
 * @return {Promise<{sessions: Array<object>, from: string, to: string}>} The
 *   ordered session list plus the resolved window echoed by the server.
 */
export async function fetchMyTimetable(from, to) {
	const params = {}
	if (from) {
		params.from = from
	}
	if (to) {
		params.to = to
	}

	const url = generateUrl('/apps/scholiq/api/timetable/mine')
	const response = await axios.get(url, { params })

	const data = response.data || {}
	return {
		sessions: Array.isArray(data.sessions) ? data.sessions : [],
		from: data.from || from || '',
		to: data.to || to || '',
	}
}
