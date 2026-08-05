<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ToastHost from './components/ToastHost.vue'
import { useManager } from './composables/useManager'
import { useTour } from './composables/useTour'

const { t, locale } = useI18n()
const route = useRoute()
const { fatalError, loadBootstrap, bootstrapped } = useManager()
const { startCurrentTour } = useTour()

const themeMode = ref(document.documentElement.dataset.themeMode || 'system')

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

onMounted(async () => {
  applyTheme(themeMode.value)
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', onSystemThemeChange)
  updateTitle()
  if (!bootstrapped.value) {
    await loadBootstrap()
  }
})

onUnmounted(() => {
  matchMedia('(prefers-color-scheme: dark)').removeEventListener('change', onSystemThemeChange)
})

watch(locale, () => {
  updateTitle()
  loadBootstrap()
})

watch(() => route.fullPath, updateTitle)
</script>

<template>
  <main class="shell">
    <header data-tour="app-header">
      <div>
        <h1>{{ t('header.title') }}</h1>
        <p>{{ t('header.subtitle') }}</p>
      </div>
      <div class="header-actions">
        <span class="badge">{{ t('header.local_only') }}</span>
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

    <nav class="nav-menu" aria-label="Main" data-tour="app-nav">
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
        :aria-current="route.name === 'services' ? 'page' : undefined"
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
          route.name === 'php-version-supervisor'
            ? 'page'
            : undefined
        "
      >
        {{ t('nav.php_versions') }}
      </RouterLink>
    </nav>

    <div v-if="fatalError" class="notice failure">{{ fatalError }}</div>
    <RouterView v-else />
    <ToastHost />
  </main>
</template>
