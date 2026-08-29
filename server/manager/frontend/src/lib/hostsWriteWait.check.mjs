import assert from 'node:assert/strict'
import {
  HOSTS_WRITE_POLL_MS,
  hostsWritePollState,
} from './hostsWriteWait.js'

assert.ok(HOSTS_WRITE_POLL_MS <= 300, 'poll at least ~3x/sec so the UI is not stuck on 1s ticks')

assert.equal(
  hostsWritePollState({
    status: { status: 'success', updated_at: '2026-08-29T10:00:01Z' },
    pendingSync: true,
    previousUpdatedAt: '2026-08-29T09:00:00Z',
  }),
  'done',
  'fresh success must finish even if hosts.sync still looks pending (macOS Docker bind-mount lag)',
)

assert.equal(
  hostsWritePollState({
    status: { status: 'success', updated_at: '2026-08-29T09:00:00Z' },
    pendingSync: true,
    previousUpdatedAt: '2026-08-29T09:00:00Z',
  }),
  'wait',
  'same timestamp is the previous write, not this one',
)

assert.equal(
  hostsWritePollState({
    status: { status: 'busy', updated_at: '2026-08-29T10:00:01Z', message_key: 'hosts.elevation_required' },
    pendingSync: true,
    previousUpdatedAt: '2026-08-29T09:00:00Z',
  }),
  'wait',
)

assert.equal(
  hostsWritePollState({
    status: { status: 'error', updated_at: '2026-08-29T10:00:01Z' },
    pendingSync: false,
    previousUpdatedAt: '2026-08-29T09:00:00Z',
  }),
  'done',
)

assert.equal(
  hostsWritePollState({
    status: {
      status: 'success',
      updated_at: '2026-08-29T10:00:01Z',
      request_id: 'aabbccddeeff0011',
    },
    pendingSync: true,
    previousUpdatedAt: '2026-08-29T09:00:00Z',
    requestId: 'aabbccddeeff0011',
  }),
  'done',
)

assert.equal(
  hostsWritePollState({
    status: {
      status: 'success',
      updated_at: '2026-08-29T10:00:01Z',
      request_id: 'aaaaaaaaaaaaaaaa',
    },
    pendingSync: false,
    previousUpdatedAt: '2026-08-29T09:00:00Z',
    requestId: 'bbbbbbbbbbbbbbbb',
  }),
  'wait',
)

assert.equal(hostsWritePollState({ status: null, pendingSync: true }), 'wait')

console.log('OK: hostsWriteWait checks')
