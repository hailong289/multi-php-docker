<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { apiGet } from '../api'
import { useManager } from '../composables/useManager'

const INFRA_ALLOWED = ['mysql', 'redis', 'rabbitmq']

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const {
  data,
  loadBootstrap,
  showToast,
  translateApiError,
  stateClass,
  stateLabel,
  infraServiceState,
  phpServiceState,
} = useManager()

const service = computed(() => String(route.params.service || ''))
const isPhp = computed(() => route.meta.logsKind === 'php')
const targets = computed(() =>
  isPhp.value ? data.php_controllers?.targets || {} : data.infra_services?.targets || {},
)
const target = computed(() => targets.value[service.value] || null)
const logs = ref(null)
const logsLoading = ref(false)
const followLogs = ref(false)
const logPre = ref(null)
let followTimer = null

const title = computed(() =>
  t(isPhp.value ? 'php_controller.logs_title' : 'services.logs_title', {
    service: target.value?.label || service.value,
    version: target.value?.label || service.value,
  }),
)
const container = computed(() => target.value?.container || '')
const state = computed(() =>
  isPhp.value ? phpServiceState(service.value) : infraServiceState(service.value),
)
const backLabel = computed(() =>
  t(isPhp.value ? 'php_controller.back_to_versions' : 'services.back_to_list'),
)

function isAllowed(name) {
  if (isPhp.value) {
    if (Object.keys(targets.value).length > 0) {
      return !!targets.value[name]
    }
    return /^php-\d+\.\d+(?:\.\d+)?(?:-alpine|-trixie)?$/.test(name)
  }
  return INFRA_ALLOWED.includes(name)
}

function logsUrl() {
  const encoded = encodeURIComponent(service.value)
  return isPhp.value ? `/api/php-controllers/${encoded}/logs` : `/api/infra-services/${encoded}/logs`
}

async function scrollLogs() {
  await nextTick()
  const el = logPre.value
  if (el) el.scrollTop = el.scrollHeight
}

function stopFollow() {
  if (followTimer) {
    clearInterval(followTimer)
    followTimer = null
  }
}

async function loadLogs({ quiet = false } = {}) {
  if (!isAllowed(service.value)) return
  if (!quiet) logsLoading.value = true
  try {
    const result = await apiGet(logsUrl())
    logs.value = result.logs || null
    if (followLogs.value) await scrollLogs()
  } catch (error) {
    if (!quiet) showToast('failure', translateApiError(error))
  } finally {
    logsLoading.value = false
  }
}

function onFollowChange(event) {
  followLogs.value = !!event.target.checked
  stopFollow()
  if (!followLogs.value || !service.value) return
  followTimer = setInterval(() => {
    if (document.visibilityState !== 'visible') return
    loadLogs({ quiet: true })
  }, 4000)
}

function goBack() {
  router.push({ name: isPhp.value ? 'php-versions' : 'services' })
}

async function openForService() {
  followLogs.value = false
  stopFollow()
  logs.value = null
  if (!isAllowed(service.value)) {
    showToast('failure', t(isPhp.value ? 'php_controller.invalid_service' : 'services.invalid_service'))
    goBack()
    return
  }
  await loadLogs()
  await scrollLogs()
}

watch([service, isPhp], () => {
  openForService()
})

onMounted(async () => {
  await loadBootstrap()
  await openForService()
})

onUnmounted(() => {
  stopFollow()
})
</script>

<template>
  <section class="panel service-logs-page" data-tour="service-logs-panel">
    <div class="panel-heading nginx-heading">
      <div class="php-detail-heading">
        <button
          type="button"
          class="icon-back"
          :aria-label="backLabel"
          :title="backLabel"
          @click="goBack"
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
          <h2>{{ title }}</h2>
          <p>
            <code>{{ container || service }}</code>
            ·
            <span class="state-badge" :class="stateClass(state)">{{ stateLabel(state) }}</span>
          </p>
        </div>
      </div>
      <div class="controller-actions">
        <label class="follow-toggle">
          <input type="checkbox" :checked="followLogs" @change="onFollowChange" />
          <span>{{ t('services.follow_logs') }}</span>
        </label>
        <button type="button" :disabled="logsLoading" @click="loadLogs()">
          {{ t('services.refresh_logs') }}
        </button>
      </div>
    </div>

    <div class="panel-body supervisor-logs">
      <p v-if="logs?.updated_at" class="create-hint">
        {{ t('services.logs_updated', { at: logs.updated_at }) }}
      </p>
      <article class="nginx-log-card">
        <pre v-if="logsLoading && !logs">{{ t('loading') }}</pre>
        <pre v-else ref="logPre">{{
          logs?.available ? logs.content || t('services.logs_empty') : t('services.logs_unavailable')
        }}</pre>
      </article>
    </div>
  </section>
</template>
