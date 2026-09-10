<script setup>
import { onMounted, onUnmounted, computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import ToastHost from './components/ToastHost.vue'
import PullProgressPanel from './components/PullProgressPanel.vue'
import ConfirmDialog from 'primevue/confirmdialog'
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
const localeOptions = [
  { label: 'VI', value: 'vi' },
  { label: 'EN', value: 'en' },
]
const themeOptions = computed(() => [
  { label: t('theme.system'), value: 'system' },
  { label: t('theme.light'), value: 'light' },
  { label: t('theme.dark'), value: 'dark' },
])

const navItems = computed(() => [
  {
    label: t('nav.home'),
    route: '/',
    tour: 'nav-home',
    active: route.name === 'home',
  },
  {
    label: t('nav.domains'),
    route: '/domains',
    tour: 'nav-domains',
    active: route.name === 'domains',
  },
  {
    label: t('nav.nginx'),
    route: '/nginx',
    tour: 'nav-nginx',
    active: route.name === 'nginx',
  },
  {
    label: t('nav.services'),
    route: '/services',
    tour: 'nav-services',
    active:
      route.name === 'services' ||
      route.name === 'service-logs' ||
      route.name === 'compose-yaml' ||
      route.name === 'compose-file-logs',
  },
  {
    label: t('nav.php_versions'),
    route: '/php-versions',
    tour: 'nav-php',
    active:
      route.name === 'php-versions' ||
      route.name === 'php-version-detail' ||
      route.name === 'php-version-catalog' ||
      route.name === 'php-version-supervisor' ||
      route.name === 'php-version-run' ||
      route.name === 'php-version-logs' ||
      route.name === 'php-compose-yaml',
  },
])

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
  if (!next) return
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
    <header v-if="showChrome" class="app-header" data-tour="app-header">
      <div>
        <h1>{{ t('header.title') }}</h1>
        <p>{{ t('header.subtitle') }}</p>
      </div>
      <div class="header-actions">
        <Tag :value="accessBadge" severity="info" rounded />
        <Button
          v-if="authState.remote"
          type="button"
          :label="t('login.logout')"
          severity="secondary"
          outlined
          size="small"
          @click="logout"
        />
        <Button
          type="button"
          data-tour="tour-replay"
          :label="t('tour.button')"
          severity="secondary"
          outlined
          size="small"
          @click="startCurrentTour({ force: true })"
        />
        <div class="switcher">
          <span class="switcher-label">{{ t('language.label') }}</span>
          <SelectButton
            :model-value="locale"
            :options="localeOptions"
            option-label="label"
            option-value="value"
            :allow-empty="false"
            :aria-label="t('language.label')"
            @update:model-value="setLocale"
          />
        </div>
        <div class="switcher">
          <span class="switcher-label">{{ t('theme.label') }}</span>
          <Select
            :model-value="themeMode"
            :options="themeOptions"
            option-label="label"
            option-value="value"
            size="small"
            class="theme-select"
            @update:model-value="applyTheme"
          />
        </div>
      </div>
    </header>

    <nav v-if="showChrome" class="app-nav" aria-label="Main" data-tour="app-nav">
      <ul class="app-nav-list">
        <li v-for="item in navItems" :key="item.route">
          <RouterLink
            :to="item.route"
            class="app-nav-link"
            :class="{ 'is-active': item.active }"
            :data-tour="item.tour"
            :aria-current="item.active ? 'page' : undefined"
          >
            {{ item.label }}
          </RouterLink>
        </li>
      </ul>
    </nav>

    <Message
      v-if="showPhpControllerBanner"
      severity="warn"
      class="php-controller-banner"
      :closable="false"
      role="status"
    >
      <div class="php-controller-banner-inner">
        <div class="php-controller-banner-copy">
          <Tag
            :value="stateLabel(data.php_controller_daemon?.state)"
            severity="warn"
          />
          <p>{{ t('php_controller.daemon_banner') }}</p>
          <p
            v-if="!data.php_controller_daemon?.start_available"
            class="create-hint"
          >
            {{ t('php_controller.daemon_not_created_hint') }}
          </p>
        </div>
        <Button
          type="button"
          :label="
            isPending('php-daemon')
              ? t('action.working')
              : t('php_controller.daemon_start')
          "
          :loading="isPending('php-daemon')"
          :disabled="!data.php_controller_daemon?.start_available || isPending('php-daemon')"
          @click="startPhpControllerDaemon"
        />
      </div>
    </Message>

    <Message
      v-if="showChrome && fatalError"
      severity="error"
      :closable="false"
    >
      {{ fatalError }}
    </Message>
    <RouterView v-if="!showChrome || !fatalError" />
    <ToastHost />
    <ConfirmDialog />
    <PullProgressPanel />
  </main>
</template>
