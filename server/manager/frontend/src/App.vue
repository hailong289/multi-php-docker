<script setup>
import { onMounted, onUnmounted, computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ToastHost from './components/ToastHost.vue'
import PullProgressPanel from './components/PullProgressPanel.vue'
import { useManager } from './composables/useManager'
import { useTour } from './composables/useTour'
import { authState } from './lib/authState'

const { t, locale } = useI18n()
const route = useRoute()
const {
  fatalError,
  loadBootstrap,
  bootstrapped,
  logout,
  dockerStatusBusy,
  data,
  stateClass,
  stateLabel,
  startPhpControllerDaemon,
  isPending,
} = useManager()
const { startCurrentTour } = useTour()

const showChrome = computed(() => {
  if (route.meta?.public || route.name === 'login') return false
  if (!route.meta?.manager) return false
  if (authState.remote && (!authState.authenticated || authState.locked)) return false
  return true
})

const PHP_CONTROLLER_BANNER_ROUTES = new Set([
  'nginx',
  'services',
  'compose-yaml',
  'service-logs',
  'php-versions',
  'php-version-catalog',
  'php-version-detail',
  'php-version-supervisor',
  'php-version-run',
  'php-version-logs',
])

const showPhpControllerBanner = computed(() => {
  if (!showChrome.value || !bootstrapped.value) return false
  if (data.php_controller_daemon?.state === 'running') return false
  return PHP_CONTROLLER_BANNER_ROUTES.has(route.name)
})

const accessBadge = computed(() => {
  if (!authState.remote) return t('header.local_only')
  if (authState.domain) return t('header.remote', { domain: authState.domain })
  return t('header.remote_unnamed')
})

const themeMode = ref(document.documentElement.dataset.themeMode || 'system')
let statusPollTimer = null

const STATUS_POLL_IDLE_MS = 5000
const STATUS_POLL_BUSY_MS = 2000

function applyTheme(mode) {
  themeMode.value = mode
  try {
    localStorage.setItem('manager-theme', mode)
  } catch (_) {}
  const effective =
    mode === 'system'
      ? matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light'
      : mode
  document.documentElement.dataset.theme = effective
  document.documentElement.dataset.themeMode = mode
}

function onSystemThemeChange() {
  if (themeMode.value === 'system') applyTheme('system')
}

function setLocale(next) {
  locale.value = next
  try {
    localStorage.setItem('manager-locale', next)
  } catch (_) {}
  document.documentElement.lang = next
}

function updateTitle() {
  const pageKey = route.meta?.titleKey
  document.title = pageKey ? `${t(pageKey)} · ${t('page.title')}` : t('page.title')
}

function shouldPollStatus() {
  return document.visibilityState === 'visible' && bootstrapped.value && showChrome.value
}

function pollStatusOnce() {
  if (shouldPollStatus()) loadBootstrap({ silent: true })
}

function stopStatusPoll() {
  if (statusPollTimer) {
    clearInterval(statusPollTimer)
    statusPollTimer = null
  }
}

function startStatusPoll() {
  stopStatusPoll()
  const ms = dockerStatusBusy.value ? STATUS_POLL_BUSY_MS : STATUS_POLL_IDLE_MS
  statusPollTimer = setInterval(pollStatusOnce, ms)
}

onMounted(async () => {
  applyTheme(themeMode.value)
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', onSystemThemeChange)
  updateTitle()
  if (showChrome.value && !bootstrapped.value && route.meta?.manager) {
    await loadBootstrap()
  }
  document.addEventListener('visibilitychange', onVisibilityRefresh)
  startStatusPoll()
})

onUnmounted(() => {
  matchMedia('(prefers-color-scheme: dark)').removeEventListener('change', onSystemThemeChange)
  document.removeEventListener('visibilitychange', onVisibilityRefresh)
  stopStatusPoll()
})

function onVisibilityRefresh() {
  if (shouldPollStatus()) loadBootstrap({ silent: true })
}

watch(dockerStatusBusy, () => {
  startStatusPoll()
})

watch(locale, () => {
  updateTitle()
})

watch(
  () => [showChrome.value, route.name],
  async ([chrome]) => {
    if (chrome && !bootstrapped.value && route.meta?.manager) {
      await loadBootstrap()
    }
  },
)

watch(() => route.fullPath, updateTitle)
</script>

<template>
  <main class="shell" :class="{ 'shell-login': !showChrome }">
    <header v-if="showChrome" data-tour="app-header">
      <div>
        <h1>{{ t('header.title') }}</h1>
        <p>{{ t('header.subtitle') }}</p>
      </div>
      <div class="header-actions">
        <span class="badge">{{ accessBadge }}</span>
        <button
          v-if="authState.remote"
          type="button"
          @click="logout"
        >
          {{ t('login.logout') }}
        </button>
        <button
          type="button"
          data-tour="tour-replay"
          class="tour-replay-btn"
          @click="startCurrentTour({ force: true })"
        >
          {{ t('tour.button') }}
        </button>
        <div class="switcher">
          <span class="switcher-label">{{ t('language.label') }}</span>
          <div class="locale-form" role="group" :aria-label="t('language.label')">
            <button type="button" :aria-current="locale === 'vi'" @click="setLocale('vi')">VI</button>
            <button type="button" :aria-current="locale === 'en'" @click="setLocale('en')">EN</button>
          </div>
        </div>
        <div class="switcher">
          <span class="switcher-label">{{ t('theme.label') }}</span>
          <select :value="themeMode" @change="applyTheme($event.target.value)">
            <option value="system">{{ t('theme.system') }}</option>
            <option value="light">{{ t('theme.light') }}</option>
            <option value="dark">{{ t('theme.dark') }}</option>
          </select>
        </div>
      </div>
    </header>

    <nav v-if="showChrome" class="nav-menu" aria-label="Main" data-tour="app-nav">
      <RouterLink
        to="/"
        data-tour="nav-home"
        :aria-current="route.name === 'home' ? 'page' : undefined"
      >
        {{ t('nav.home') }}
      </RouterLink>
      <RouterLink
        to="/domains"
        data-tour="nav-domains"
        :aria-current="route.name === 'domains' ? 'page' : undefined"
      >
        {{ t('nav.domains') }}
      </RouterLink>
      <RouterLink
        to="/nginx"
        data-tour="nav-nginx"
        :aria-current="route.name === 'nginx' ? 'page' : undefined"
      >
        {{ t('nav.nginx') }}
      </RouterLink>
      <RouterLink
        to="/services"
        data-tour="nav-services"
        :aria-current="
          route.name === 'services' ||
          route.name === 'service-logs' ||
          route.name === 'compose-yaml'
            ? 'page'
            : undefined
        "
      >
        {{ t('nav.services') }}
      </RouterLink>
      <RouterLink
        to="/php-versions"
        data-tour="nav-php"
        :aria-current="
          route.name === 'php-versions' ||
          route.name === 'php-version-detail' ||
          route.name === 'php-version-catalog' ||
          route.name === 'php-version-supervisor' ||
          route.name === 'php-version-run' ||
          route.name === 'php-version-logs'
            ? 'page'
            : undefined
        "
      >
        {{ t('nav.php_versions') }}
      </RouterLink>
    </nav>

    <div
      v-if="showPhpControllerBanner"
      class="notice warning php-controller-banner"
      role="status"
    >
      <div class="php-controller-banner-copy">
        <span
          class="state-badge"
          :class="stateClass(data.php_controller_daemon?.state)"
        >
          {{ stateLabel(data.php_controller_daemon?.state) }}
        </span>
        <p>{{ t('php_controller.daemon_banner') }}</p>
        <p
          v-if="!data.php_controller_daemon?.start_available"
          class="create-hint"
        >
          {{ t('php_controller.daemon_not_created_hint') }}
        </p>
      </div>
      <button
        type="button"
        class="primary"
        :class="{ 'is-loading': isPending('php-daemon') }"
        :disabled="!data.php_controller_daemon?.start_available || isPending('php-daemon')"
        @click="startPhpControllerDaemon"
      >
        <span
          v-if="isPending('php-daemon')"
          class="btn-spinner"
          aria-hidden="true"
        ></span>
        {{
          isPending('php-daemon')
            ? t('action.working')
            : t('php_controller.daemon_start')
        }}
      </button>
    </div>

    <div v-if="showChrome && fatalError" class="notice failure">{{ fatalError }}</div>
    <RouterView v-if="!showChrome || !fatalError" />
    <ToastHost />
    <PullProgressPanel />
  </main>
</template>
