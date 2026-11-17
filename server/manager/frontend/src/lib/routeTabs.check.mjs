import assert from 'node:assert/strict'
import { addSplit, isSubPageRoute, removeTab, upsertTab } from './routeTabs.js'

assert.equal(isSubPageRoute('home'), false)
assert.equal(isSubPageRoute('domains'), false)
assert.equal(isSubPageRoute('nginx'), false)
assert.equal(isSubPageRoute('php-versions'), false)
assert.equal(isSubPageRoute('terminal'), true)
assert.equal(isSubPageRoute('php-version-detail'), true)

const a = { path: '/a', fullPath: '/a' }
const b = { path: '/b', fullPath: '/b?x=1' }
const c = { path: '/c', fullPath: '/c' }

assert.deepEqual(upsertTab([], '/a', '/a'), [a])
assert.deepEqual(upsertTab([a], '/b', '/b?x=1'), [a, b])
const same = [a, b]
assert.equal(upsertTab(same, '/a', '/a'), same)
assert.deepEqual(upsertTab([a, b], '/a', '/a?page=2'), [{ path: '/a', fullPath: '/a?page=2' }, b])
assert.deepEqual(upsertTab([a, b], '/c', '/c', 2), [b, c])

assert.deepEqual(removeTab([a, b], '/a'), { list: [b], neighbor: b })
assert.deepEqual(removeTab([a, b], '/b'), { list: [a], neighbor: a })
assert.deepEqual(removeTab([a, b], '/missing'), { list: [a, b], neighbor: null })

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
