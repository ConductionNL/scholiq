// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Component-level regression test for CourseBuilder.vue's module-ordering
// comparator (course-authoring-ux task 7.3). compareByOrder lives in
// src/utils/courseOrder.js specifically so it is importable here without an
// SFC compile step — run via `node --test tests/unit-js/` (Node's built-in
// test runner, no extra framework dependency; see package.json's
// `test:js-unit` script).

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { compareByOrder } from '../../src/utils/courseOrder.js'

test('explicit order values sort ascending', () => {
	const rows = [{ id: 'b', order: 2 }, { id: 'a', order: 1 }, { id: 'c', order: 3 }]
	rows.sort(compareByOrder)
	assert.deepEqual(rows.map((r) => r.id), ['a', 'b', 'c'])
})

test('a null-order row sorts after every row with an explicit order — not first, not an error', () => {
	const rows = [{ id: 'null-order', order: null }, { id: 'first', order: 1 }]
	rows.sort(compareByOrder)
	assert.deepEqual(rows.map((r) => r.id), ['first', 'null-order'])
})

test('an undefined-order row sorts after every row with an explicit order', () => {
	const rows = [{ id: 'no-order-field' }, { id: 'first', order: 1 }]
	rows.sort(compareByOrder)
	assert.deepEqual(rows.map((r) => r.id), ['first', 'no-order-field'])
})

test('two null-order rows are stable (comparator returns 0, no reordering forced)', () => {
	assert.equal(compareByOrder({ order: null }, { order: null }), 0)
	assert.equal(compareByOrder({ order: undefined }, {}), 0)
})

test('mixed explicit + null rows: every explicit-order row precedes every null-order row', () => {
	const rows = [
		{ id: 'null-1', order: null },
		{ id: 'two', order: 2 },
		{ id: 'null-2', order: null },
		{ id: 'one', order: 1 },
	]
	rows.sort(compareByOrder)
	const orderedIds = rows.map((r) => r.id)
	assert.deepEqual(orderedIds.slice(0, 2), ['one', 'two'])
	assert.deepEqual(new Set(orderedIds.slice(2)), new Set(['null-1', 'null-2']))
})
