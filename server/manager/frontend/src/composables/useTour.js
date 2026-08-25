import { nextTick, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { driver } from 'driver.js'
import 'driver.js/dist/driver.css'
import { buildTourSteps, tourIdForRoute } from '../tours/definitions'
import { useManager } from './useManager'

const STORAGE_KEY = 'manager-tour-seen'
let activeDriver = null
let autoTimer = null
let tourShown = false

/** Page root anchors — tour waits until these exist (after router + view mount). */
const PAGE_READY = {
  home: '[data-tour="home-panel"]',
  domains: '[data-tour="domains-panel"]',
  nginx: '[data-tour="nginx-panel"]',
  services: '[data-tour="services-panel"]',
  'php-versions': '[data-tour="php-panel"]',
  'php-version-detail': '[data-tour="php-detail-panel"]',
  'php-version-catalog': '[data-tour="php-catalog-panel"]',
  'php-version-supervisor': '[data-tour="supervisor-panel"]',
  terminal: '[data-tour="terminal-panel"]',
}

function readSeen() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw)
    return parsed && typeof parsed === 'object' ? parsed : {}
  } catch (_) {
    return {}
  }
}

function writeSeen(map) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(map))
  } catch (_) {}
}

function hasSeen(tourId) {
  return !!readSeen()[tourId]
}

function markSeen(tourId) {
  const map = readSeen()
  map[tourId] = true
  writeSeen(map)
}

function destroyActive() {
  if (autoTimer) {
    clearTimeout(autoTimer)
    autoTimer = null
  }
  if (activeDriver) {
    try {
      activeDriver.destroy()
    } catch (_) {}
    activeDriver = null
  }
}

/**
 * Keep steps that are visible now, or that can become visible via prepareClick.
 * prepareClick runs on highlight so tabbed UI can switch before the spotlight refreshes.
 */
function buildDriverSteps(rawSteps) {
  return rawSteps
    .filter((step) => {
      if (!step.element) return true
      if (document.querySelector(step.element)) return true
      if (step.prepareClick && document.querySelector(step.prepareClick)) return true
      return false
    })
    .map((step) => {
      const prepareClick = step.prepareClick
      return {
        element: step.element,
        popover: step.popover,
        onHighlightStarted: (_element, _step, { driver: active }) => {
          if (!prepareClick) return
          const trigger = document.querySelector(prepareClick)
          if (!trigger) return
          try {
            trigger.click()
          } catch (_) {}
          window.setTimeout(() => {
            try {
              active?.refresh?.()
            } catch (_) {}
          }, 80)
        },
      }
    })
}

function pageReady(tourId) {
  const selector = PAGE_READY[tourId]
  if (!selector) return true
  return !!document.querySelector(selector)
}

export function useTour() {
  const { t } = useI18n()
  const route = useRoute()
  const router = useRouter()
  const { bootstrapped, loading } = useManager()

  function startTour(tourId, { force = false } = {}) {
    const id = tourId || tourIdForRoute(route.name)
    if (!id) return false
    if (!force && hasSeen(id)) return false

    const all = buildTourSteps(t)
    const raw = all[id]
    if (!raw?.length) return false

    destroyActive()
    const steps = buildDriverSteps(raw)
    if (!steps.length) return false

    activeDriver = driver({
      showProgress: true,
      animate: true,
      overlayColor: 'rgb(15, 23, 42)',
      stagePadding: 8,
      stageRadius: 10,
      popoverClass: 'manager-tour-popover',
      nextBtnText: t('tour.common.next'),
      prevBtnText: t('tour.common.prev'),
      doneBtnText: t('tour.common.done'),
      progressText: t('tour.common.progress', {
        current: '{{current}}',
        total: '{{total}}',
      }),
      steps,
      onDestroyed: () => {
        if (tourShown) markSeen(id)
        activeDriver = null
        tourShown = false
      },
    })
    activeDriver.drive()
    tourShown = true
    return true
  }

  function startCurrentTour(options = {}) {
    return startTour(tourIdForRoute(route.name), { force: true, ...options })
  }

  function needsBootstrap(tourId) {
    return [
      'home',
      'domains',
      'services',
      'php-versions',
      'php-version-detail',
      'php-version-supervisor',
    ].includes(tourId)
  }

  function canAutoStart(tourId) {
    if (!tourId || hasSeen(tourId)) return false
    if (needsBootstrap(tourId) && (!bootstrapped.value || loading.value)) return false
    if (!pageReady(tourId)) return false
    return true
  }

  function scheduleAutoTour(attempt = 0) {
    if (autoTimer) {
      clearTimeout(autoTimer)
      autoTimer = null
    }
    const id = tourIdForRoute(route.name)
    if (!id || hasSeen(id)) return

    autoTimer = setTimeout(async () => {
      autoTimer = null
      try {
        await router.isReady()
      } catch (_) {}
      await nextTick()

      if (tourIdForRoute(route.name) !== id || hasSeen(id)) return

      if (!canAutoStart(id)) {
        if (attempt < 40) {
          scheduleAutoTour(attempt + 1)
        }
        return
      }

      const started = startTour(id)
      if (!started && attempt < 40 && !hasSeen(id)) {
        scheduleAutoTour(attempt + 1)
      }
    }, attempt === 0 ? 400 : 250)
  }

  watch(
    () => route.fullPath,
    () => {
      destroyActive()
      scheduleAutoTour(0)
    },
    { immediate: true },
  )

  watch([bootstrapped, loading], () => {
    const id = tourIdForRoute(route.name)
    if (!id || hasSeen(id) || activeDriver) return
    if (canAutoStart(id)) scheduleAutoTour(0)
  })

  onUnmounted(() => destroyActive())

  return {
    startCurrentTour,
    startTour,
    hasSeen,
    markSeen,
    tourIdForRoute,
  }
}
