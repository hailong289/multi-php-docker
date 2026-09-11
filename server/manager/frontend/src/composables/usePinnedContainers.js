import { ref } from 'vue'
import * as store from '../lib/pinnedContainers'

const pins = ref(store.readPins())

function sync(next) {
  pins.value = next
  return next
}

export function usePinnedContainers() {
  function refresh() {
    pins.value = store.readPins()
  }

  return {
    pins,
    refresh,
    isPinned: (kind, id) => {
      const key = store.pinKey(kind, id)
      return pins.value.some((p) => store.pinKey(p.kind, p.id) === key)
    },
    pin: (kind, id) => sync(store.pin(kind, id)),
    unpin: (kind, id) => sync(store.unpin(kind, id)),
    togglePin: (kind, id) => sync(store.togglePin(kind, id)),
    movePin: (kind, id, toIndex) => sync(store.movePin(kind, id, toIndex)),
    reorderPins: (nextList) => sync(store.reorderPins(nextList)),
  }
}
