import { reactive } from 'vue'

export const authState = reactive({
  ready: false,
  remote: false,
  authenticated: true,
  locked: false,
  domain: '',
})

export function applySessionPayload(payload) {
  authState.remote = !!payload?.remote
  authState.authenticated = payload?.authenticated !== false
  authState.locked = !!payload?.locked
  authState.domain = String(payload?.domain || '')
  authState.ready = true
}
