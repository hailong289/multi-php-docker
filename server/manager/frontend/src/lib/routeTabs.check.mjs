import assert from 'node:assert/strict'
import { HOME_TAB, addSplit, removeTab, upsertTab } from './routeTabs.js'

const home = { path: HOME_TAB, fullPath: HOME_TAB }
const a = { path: '/a', fullPath: '/a' }
const b = { path: '/b', fullPath: '/b?x=1' }

assert.deepEqual(upsertTab([home], '/a', '/a'), [home, a])
const same = [home, a]
assert.equal(upsertTab(same, '/a', '/a'), same)
assert.deepEqual(upsertTab([home, a], '/a', '/a?page=2'), [home, { path: '/a', fullPath: '/a?page=2' }])
assert.deepEqual(upsertTab([home, a], '/b', '/b?x=1', 2), [home, b])

assert.deepEqual(removeTab([home, a, b], '/a'), { list: [home, b], neighbor: b })
assert.deepEqual(removeTab([home, a, b], '/b'), { list: [home, a], neighbor: a })
assert.deepEqual(removeTab([home, a], HOME_TAB), { list: [home, a], neighbor: null })
assert.deepEqual(removeTab([home], '/missing'), { list: [home], neighbor: null })

const one = addSplit([], '/a', 2)
assert.equal(one.list.length, 1)
assert.equal(one.evicted, null)
assert.equal(addSplit(one.list, '/a', 2).list, one.list)
const two = addSplit(one.list, '/b', 2)
assert.deepEqual(two.list.map((pane) => pane.fullPath), ['/a', '/b'])
const full = addSplit(two.list, '/c', 2)
assert.deepEqual(full.list.map((pane) => pane.fullPath), ['/a', '/c'])
assert.equal(full.evicted, two.list[1])
assert.equal(full.list[0].id, one.list[0].id)

console.log('routeTabs.check: ok')
