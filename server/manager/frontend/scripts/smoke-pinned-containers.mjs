import assert from 'node:assert/strict'
import {
  pin,
  unpin,
  togglePin,
  isPinned,
  movePin,
  readPins,
  writePins,
  PIN_STORAGE_KEY,
} from '../src/lib/pinnedContainers.js'

const store = new Map()
globalThis.localStorage = {
  getItem: (k) => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => store.set(k, String(v)),
  removeItem: (k) => store.delete(k),
}

store.clear()
assert.deepEqual(readPins(), [])
pin('infra', 'mysql')
pin('php', 'php-8.5')
pin('infra', 'mysql')
assert.equal(readPins().length, 2)
assert.equal(isPinned('infra', 'mysql'), true)
movePin('php', 'php-8.5', 0)
assert.deepEqual(
  readPins().map((p) => p.id),
  ['php-8.5', 'mysql'],
)
togglePin('infra', 'mysql')
assert.equal(isPinned('infra', 'mysql'), false)
unpin('php', 'php-8.5')
assert.deepEqual(readPins(), [])
writePins([
  { kind: 'nginx', id: 'nginx' },
  { kind: 'bad', id: 'x' },
  { kind: 'nginx', id: 'other' },
])
assert.deepEqual(readPins(), [{ kind: 'nginx', id: 'nginx' }])
assert.equal(PIN_STORAGE_KEY, 'manager-pinned-containers')
console.log('smoke-pinned-containers: ok')
