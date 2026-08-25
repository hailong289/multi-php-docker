<script setup>
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import DockerTerminalPanel from '../components/DockerTerminalPanel.vue'
import { useManager } from '../composables/useManager'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { data, loading, loadBootstrap, bootstrapped, showToast } = useManager()

const serverKey = computed(() => String(route.params.serverKey || ''))

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
  <section class="panel terminal-page" data-tour="terminal-panel">
    <div class="panel-heading nginx-heading">
      <div class="php-detail-heading">
        <button
          type="button"
          class="icon-back"
          :aria-label="t('terminal.back')"
          :title="t('terminal.back')"
          @click="goHome"
        >
          <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false">
            <path
              d="M12.5 4.5 7 10l5.5 5.5"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </button>
        <div>
          <h2>{{ t('terminal.page_title') }}</h2>
          <p>{{ pageTitle }}</p>
        </div>
      </div>
    </div>

    <div class="panel-body terminal-page-body">
      <DockerTerminalPanel
        v-if="serverEntry"
        :key="serverKey"
        :server-key="serverKey"
        :title="pageTitle"
        page
        @close="goHome"
      />
    </div>
  </section>
</template>
