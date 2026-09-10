<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { apiGet } from '../api'
import { useManager } from '../composables/useManager'

const INFRA_ALLOWED = ['mysql', 'postgres', 'redis', 'rabbitmq', 'kafka']

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const {
  data,
  loadBootstrap,
  showToast,
  translateApiError,
  stateLabel,
  infraServiceState,
  phpServiceState,
  composeFileState,
} = useManager()

const logsKind = computed(() => route.meta.logsKind || 'infra')
const isPhp = computed(() => logsKind.value === 'php')
const isCompose = computed(() => logsKind.value === 'compose')
const service = computed(() => String(route.params.service || ''))
const composeName = computed(() => String(route.params.name || ''))
const targets = computed(() =>
  isPhp.value ? data.php_controllers?.targets || {} : data.infra_services?.targets || {},
)
const target = computed(() => targets.value[service.value] || null)
const composeItem = computed(
  () => data.infra_services?.compose_files?.find((file) => file.name === composeName.value) || null,
)
const logs = ref(null)
const logsLoading = ref(false)
const followLogs = ref(false)
const logPre = ref(null)
let followTimer = null

const title = computed(() => {
  if (isPhp.value) {
    return t('php_controller.logs_title', {
      version: target.value?.label || service.value,
    })
  }
  if (isCompose.value) {
    const label =
      composeItem.value?.compose_services?.[0]?.name ||
      composeName.value.replace(/\.ya?ml$/i, '')
    return t('services.logs_title', { service: label })
  }
  return t('services.logs_title', { service: target.value?.label || service.value })
})

const container = computed(() => {
  if (isCompose.value) return composeItem.value?.container || ''
  return target.value?.container || ''
})

const state = computed(() => {
  if (isPhp.value) return phpServiceState(service.value)
  if (isCompose.value) return composeFileState(composeItem.value)
  return infraServiceState(service.value)
})

const backLabel = computed(() => t('services.back_to_list'))

function stateSeverity(value) {
  if (value === 'running') return 'success'
  if (value === 'stopped') return 'secondary'
  if (value === 'error') return 'danger'
  if (value === 'busy') return 'warn'
  return 'contrast'
}

function isAllowed() {
  if (isPhp.value) {
    if (Object.keys(targets.value).length > 0) {
      return !!targets.value[service.value]
    }
    return /^php-\d+\.\d+(?:\.\d+)?(?:-alpine|-trixie)?$/.test(service.value)
  }
  if (isCompose.value) return !!composeItem.value
  return INFRA_ALLOWED.includes(service.value)
}

function logsUrl() {
  if (isPhp.value) {
    return `/api/php-controllers/${encodeURIComponent(service.value)}/logs`
  }
  if (isCompose.value) {
    return `/api/infra-services/compose-files/${encodeURIComponent(composeName.value)}/logs`
  }
  return `/api/infra-services/${encodeURIComponent(service.value)}/logs`
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
  if (!isAllowed()) return
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

function onFollowChange(value) {
  followLogs.value = !!value
  stopFollow()
  if (!followLogs.value) return
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
  if (!isAllowed()) {
    showToast(
      'failure',
      t(isPhp.value ? 'php_controller.invalid_service' : 'services.invalid_service'),
    )
    goBack()
    return
  }
  await loadLogs()
  await scrollLogs()
}

watch([service, composeName, logsKind], () => {
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
        <Button
          type="button"
          class="icon-back"
          icon="pi pi-arrow-left"
          severity="secondary"
          text
          rounded
          :aria-label="backLabel"
          :title="backLabel"
          @click="goBack"
        />
        <div>
          <h2>{{ title }}</h2>
          <p>
            <code>{{ container || service || composeName }}</code>
            ·
            <Tag :value="stateLabel(state)" :severity="stateSeverity(state)" rounded />
          </p>
        </div>
      </div>
      <div class="controller-actions">
        <div class="follow-toggle">
          <Checkbox
            :model-value="followLogs"
            binary
            input-id="service-follow-logs"
            @update:model-value="onFollowChange"
          />
          <label for="service-follow-logs">{{ t('services.follow_logs') }}</label>
        </div>
        <Button
          type="button"
          severity="secondary"
          outlined
          size="small"
          :label="t('services.refresh_logs')"
          :loading="logsLoading"
          :disabled="logsLoading"
          @click="loadLogs()"
        />
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
