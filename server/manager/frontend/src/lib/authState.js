import { reactive } from 'vue'

export const authState = reactive({
  ready: false,
  hosts_write_enabled: true,
})

export function applySessionPayload(payload) {
  authState.hosts_write_enabled = payload?.hosts_write_enabled !== false
  authState.ready = true
}
