import { reactive } from 'vue'

export const authState = reactive({
  ready: false,
  remote: false,
  authenticated: true,
  locked: false,
  domain: '',
  hosts_write_enabled: true,
})

export function applySessionPayload(payload) {
  authState.remote = !!payload?.remote
  authState.authenticated = payload?.authenticated !== false
  authState.locked = !!payload?.locked
  authState.domain = String(payload?.domain || '')
  authState.hosts_write_enabled = payload?.hosts_write_enabled !== false
  authState.ready = true
}
