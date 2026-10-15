import { apiGet } from '../api'

/** Ask before start/create when another container already publishes the port. */
export async function confirmPublishedPorts(service, t) {
  try {
    const payload = await apiGet(`/api/health/ports?service=${encodeURIComponent(service)}`)
    const conflicts = payload.conflicts || []
    if (conflicts.length === 0) return true
    const first = conflicts[0]
    return window.confirm(t('health.port_conflict', { port: first.port, holder: first.holder }))
  } catch {
    return true
  }
}
