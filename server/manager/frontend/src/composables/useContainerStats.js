import { onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, unref } from 'vue'
import { apiGet } from '../api'

const POLL_MS = 5000

/** CPU/RAM per container name, polled while the (kept-alive) view is visible. */
export function useContainerStats(names) {
  const stats = ref({})
  let timer = 0

  async function poll() {
    const list = (unref(names) || []).filter((name) => name && name !== '—')
    if (list.length && document.visibilityState === 'visible') {
      try {
        const payload = await apiGet(`/api/health/containers?names=${encodeURIComponent(list.join(','))}`)
        stats.value = payload.containers || {}
      } catch (_) {}
    }
    if (timer) timer = setTimeout(poll, POLL_MS)
  }

  function start() {
    if (timer) return
    timer = -1
    poll()
  }

  function stop() {
    if (timer > 0) clearTimeout(timer)
    timer = 0
  }

  onMounted(start)
  onActivated(start)
  onDeactivated(stop)
  onBeforeUnmount(stop)

  return { stats }
}
