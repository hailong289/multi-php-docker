/** Decide when Manager should stop waiting for a hosts write. */

export const HOSTS_WRITE_POLL_MS = 250
export const HOSTS_WRITE_TIMEOUT_MS = 45_000

function normalizeId(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
}

/**
 * @param {{
 *   status?: { status?: string, updated_at?: string, request_id?: string } | null,
 *   pendingSync?: boolean,
 *   previousUpdatedAt?: string | null,
 *   requestId?: string,
 * }} input
 * @returns {'wait' | 'done'}
 */
export function hostsWritePollState({
  status = null,
  pendingSync = false,
  previousUpdatedAt = null,
  requestId = '',
} = {}) {
  void pendingSync
  if (!status?.status) return 'wait'

  const wantId = normalizeId(requestId)
  const gotId = normalizeId(status.request_id)
  if (wantId && gotId && wantId !== gotId) return 'wait'

  if (previousUpdatedAt && status.updated_at && status.updated_at === previousUpdatedAt) {
    return 'wait'
  }

  if (status.status === 'busy') return 'wait'
  if (status.status === 'success' || status.status === 'error') return 'done'
  return 'wait'
}
