import {
  computed,
  defineComponent,
  onActivated,
  onDeactivated,
  provide,
  reactive,
  ref,
  shallowRef,
  watch,
} from 'vue'
import { routeLocationKey, useRouter } from 'vue-router'

export const TABS_STORAGE_KEY = 'manager-route-tabs'
export const SPLIT_STORAGE_KEY = 'manager-split-panes'
export const MAX_TABS = 10
export const HOME_TAB = '/'
/** Side panes next to the main area (4 areas total: 2 cols, 3 cols, then 2×2). */
export const MAX_SPLITS = 3

/** Opens or refreshes the tab for `path`; past `max` the oldest closable tab is dropped. */
export function upsertTab(list, path, fullPath, max = MAX_TABS) {
  const index = list.findIndex((tab) => tab.path === path)
  if (index >= 0) {
    if (list[index].fullPath === fullPath) return list
    const next = list.slice()
    next[index] = { path, fullPath }
    return next
  }
  const next = [...list, { path, fullPath }]
  // ponytail: evicts by open order, not last use; switch to LRU if users complain.
  if (next.length > max) next.splice(next.findIndex((tab) => tab.path !== HOME_TAB), 1)
  return next
}

/** Removes `path` (home stays); `neighbor` is the tab to show if `path` was active. */
export function removeTab(list, path) {
  const index = list.findIndex((tab) => tab.path === path)
  if (index < 0 || path === HOME_TAB) return { list, neighbor: null }
  const next = list.filter((_, i) => i !== index)
  return { list: next, neighbor: next[Math.min(index, next.length - 1)] || null }
}

function readTabs() {
  let saved = []
  try {
    const raw = JSON.parse(localStorage.getItem(TABS_STORAGE_KEY) || '[]')
    if (Array.isArray(raw)) {
      saved = raw.filter((tab) => typeof tab?.path === 'string' && typeof tab?.fullPath === 'string')
    }
  } catch (_) {}
  const home = saved.find((tab) => tab.path === HOME_TAB) || { path: HOME_TAB, fullPath: HOME_TAB }
  return [home, ...saved.filter((tab) => tab.path !== HOME_TAB)].slice(0, MAX_TABS)
}

export const routeTabs = ref(readTabs())

watch(routeTabs, (list) => {
  try {
    localStorage.setItem(TABS_STORAGE_KEY, JSON.stringify(list))
  } catch (_) {}
})

/**
 * Adds a side pane for `fullPath`; when full, the last pane is replaced and returned as `evicted`.
 * Pane ids stay stable so closing one pane never remounts the others.
 */
export function addSplit(list, fullPath, max = MAX_SPLITS) {
  if (list.some((pane) => pane.fullPath === fullPath)) return { list, evicted: null }
  const pane = { id: `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`, fullPath }
  if (list.length < max) return { list: [...list, pane], evicted: null }
  return { list: [...list.slice(0, -1), pane], evicted: list[list.length - 1] }
}

/** Side panes as `{ id, fullPath }`, left to right. */
export const splitTabs = ref(readSplits())

function readSplits() {
  try {
    const raw = JSON.parse(localStorage.getItem(SPLIT_STORAGE_KEY) || '[]')
    if (Array.isArray(raw)) {
      return raw
        .filter((pane) => typeof pane?.id === 'string' && typeof pane?.fullPath === 'string' && pane.fullPath.startsWith('/'))
        .slice(0, MAX_SPLITS)
    }
  } catch (_) {}
  return []
}

watch(splitTabs, (list) => {
  try {
    localStorage.setItem(SPLIT_STORAGE_KEY, JSON.stringify(list))
  } catch (_) {}
})

/** Resolves like a navigation would, following one string redirect (e.g. the catch-all). */
export function resolveTab(router, to) {
  const target = router.resolve(to)
  const redirect = target.matched[0]?.redirect
  return typeof redirect === 'string' ? router.resolve(redirect) : target
}

/** "Page title · subject" for a tab or the split pane. */
export function tabLabel(router, t, servers, fullPath) {
  const target = resolveTab(router, fullPath)
  const title = target.meta?.titleKey ? t(target.meta.titleKey) : target.path
  const { serverKey, service, name } = target.params
  const subject = serverKey ? servers?.[serverKey]?.APP_NAME || serverKey : service || name
  return subject ? `${title} · ${subject}` : title
}

/** Makes `useRoute()` (and RouterLink) below the caller see `current` instead of the URL. */
export function provideRoute(current) {
  const route = {}
  for (const key of Object.keys(current.value)) {
    route[key] = computed(() => current.value[key])
  }
  provide(routeLocationKey, reactive(route))
}

const wrappers = new Map()

/**
 * KeepAlive caches by component name, so each tab gets its own named wrapper.
 * The wrapper also pins `useRoute()` inside the tab to that tab's own route,
 * so hidden views keep their params instead of following the active tab.
 */
export function tabWrapper(path) {
  if (!wrappers.has(path)) {
    wrappers.set(
      path,
      defineComponent({
        name: path,
        setup(_, { slots }) {
          const router = useRouter()
          const current = shallowRef(router.currentRoute.value)
          watch(router.currentRoute, (next) => {
            if (next.path === path) current.value = next
          })
          provideRoute(current)
          return () => slots.default?.()
        },
      }),
    )
  }
  return wrappers.get(path)
}

/** False while the view's tab is hidden; use it to pause polling and shortcuts. */
export function useTabActive() {
  const active = ref(true)
  onActivated(() => {
    active.value = true
  })
  onDeactivated(() => {
    active.value = false
  })
  return active
}
