/**
 * Shared sort comparator for order-bearing rows where `order` may be null
 * (course-authoring-ux). Explicit values ascend first; null/undefined
 * values sort last (append-to-end) — never as an error, never as position
 * zero. Used by CourseBuilder.vue for its module list (Course.order) and is
 * the same shape Lesson.order / block.order already assume elsewhere.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a
 * Node test runner without an SFC compile step.
 *
 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-pre-existing-module-without-an-order-value-sorts-last-not-first
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * @param {{order?: number|null}} a First row.
 * @param {{order?: number|null}} b Second row.
 * @return {number} Standard Array#sort comparator return value.
 */
export function compareByOrder(a, b) {
	const ao = a && a.order
	const bo = b && b.order
	if (ao === null || ao === undefined) {
		if (bo === null || bo === undefined) return 0
		return 1
	}
	if (bo === null || bo === undefined) return -1
	return ao - bo
}
