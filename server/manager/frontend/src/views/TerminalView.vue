<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import DockerTerminalPanel from '../components/DockerTerminalPanel.vue'
import { useManager } from '../composables/useManager'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { data, loading, loadBootstrap, bootstrapped, showToast } = useManager()

const serverKey = computed(() => String(route.params.serverKey || ''))
const expanded = ref(false)

function toggleExpanded() {
  expanded.value = !expanded.value
}

watch(expanded, (value) => {
  document.body.style.overflow = value ? 'hidden' : ''
})

onBeforeUnmount(() => {
  document.body.style.overflow = ''
})

const serverEntry = computed(() => {
  const key = serverKey.value
  if (!key) return null
  const servers = data.servers || {}
  const server = servers[key]
  if (!server || typeof server !== 'object') return null
  return { key, server }
})

const pageTitle = computed(() => {
  const entry = serverEntry.value
  if (!entry) return t('terminal.title')
  const s = entry.server
  return `${s.APP_NAME || entry.key} · ${s.DOMAIN_NAME || ''} · ${s.CONTAINER_PHP_VERSION || ''}`
})

function goHome() {
  router.push({ name: 'home' })
}

watch(
  [serverKey, () => bootstrapped.value, () => loading.value, () => data.servers],
  async () => {
    if (!bootstrapped.value) {
      await loadBootstrap()
      return
    }
    if (loading.value) return
    if (!serverKey.value || !/^SERVER_NAME\d+$/.test(serverKey.value)) {
      showToast('failure', t('terminal.server_not_found'))
      goHome()
      return
    }
    if (!serverEntry.value) {
      showToast('failure', t('terminal.server_not_found'))
      goHome()
    }
  },
  { immediate: true },
)
</script>

<template>
  <section
    class="panel terminal-page"
    :class="{ 'is-expanded': expanded }"
    data-tour="terminal-panel"
  >
    <div v-show="!expanded" class="panel-heading nginx-heading">
      <div class="php-detail-heading">
        <Button
          type="button"
          class="icon-back"
          icon="pi pi-arrow-left"
          severity="secondary"
          text
          rounded
          :aria-label="t('terminal.back')"
          :title="t('terminal.back')"
          @click="goHome"
        />
        <div>
          <h2>{{ t('terminal.page_title') }}</h2>
          <p>{{ pageTitle }}</p>
        </div>
      </div>
      <div class="panel-heading-actions">
        <Button
          type="button"
          :icon="expanded ? 'pi pi-window-minimize' : 'pi pi-window-maximize'"
          severity="secondary"
          text
          rounded
          :aria-label="expanded ? t('terminal.collapse') : t('terminal.expand')"
          :title="expanded ? t('terminal.collapse') : t('terminal.expand')"
          @click="toggleExpanded"
        />
      </div>
    </div>

    <div class="panel-body terminal-page-body">
      <DockerTerminalPanel
        v-if="serverEntry"
        :key="serverKey"
        :server-key="serverKey"
        page
        @close="goHome"
      />
    </div>
    <Button
      v-if="expanded"
      type="button"
      class="terminal-collapse"
      icon="pi pi-window-minimize"
      severity="secondary"
      text
      rounded
      :aria-label="t('terminal.collapse')"
      :title="t('terminal.collapse')"
      @click="toggleExpanded"
    />
  </section>
</template>
