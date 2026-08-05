<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const {
  data,
  loadBootstrap,
  stateClass,
  stateLabel,
  showToast,
  translateApiError,
} = useManager()

const phpService = computed(() => String(route.params.service || ''))
const supervisorService = computed(() => {
  const fromTarget = data.php_controllers?.targets?.[phpService.value]?.supervisor_service
  if (fromTarget) return fromTarget
  if (phpService.value === 'php-8.2') return 'supervisor'
  if (phpService.value.startsWith('php-')) return `supervisor-${phpService.value.slice(4)}`
  return ''
})

const loading = ref(true)
const detailsLoading = ref(false)
const pending = ref('')
const selectedLog = ref('')
const followLogs = ref(false)
const clearing = ref(false)
const details = ref(null)
const logPre = ref(null)
let followTimer = null

const phpLabel = computed(
  () => data.php_controllers?.targets?.[phpService.value]?.label || phpService.value,
)
const target = computed(
  () => data.supervisor_services?.targets?.[supervisorService.value] || details.value || {},
)
const currentState = computed(
  () =>
    details.value?.state ||
    data.supervisor_services?.statuses?.[supervisorService.value]?.state ||
    'not_created',
)

function showCreate() {
  return currentState.value === 'not_created'
}

function enabled(action) {
  if (pending.value || loading.value) return false
  const state = currentState.value
  if (action === 'create') return state === 'not_created'
  if (state === 'busy' || state === 'error' || state === 'not_created') return false
  if (action === 'start') return state === 'stopped'
  if (action === 'stop' || action === 'restart') return state === 'running'
  return false
}

async function scrollLogToBottom() {
  await nextTick()
  const el = logPre.value
  if (el) el.scrollTop = el.scrollHeight
}

async function ensureBootstrap() {
  if (!data.supervisor_services?.targets?.[supervisorService.value]) {
    await loadBootstrap()
  }
}

async function loadDetails({ quiet = false } = {}) {
  if (!supervisorService.value) {
    details.value = null
    return
  }
  if (!quiet) detailsLoading.value = true
  try {
    const query = selectedLog.value
      ? `?log=${encodeURIComponent(selectedLog.value)}`
      : ''
    const result = await apiGet(`/api/supervisor/${supervisorService.value}${query}`)
    details.value = result.supervisor
    if (result.supervisor?.selected_log) {
      selectedLog.value = result.supervisor.selected_log
    }
    if (result.supervisor && data.supervisor_services?.statuses) {
      data.supervisor_services.statuses[supervisorService.value] = {
        ...(data.supervisor_services.statuses[supervisorService.value] || {}),
        service: supervisorService.value,
        state: result.supervisor.state,
        message_key: result.supervisor.message_key,
        request_id: result.supervisor.request_id || '',
        updated_at: result.supervisor.updated_at || '',
      }
    }
    if (followLogs.value) {
      await scrollLogToBottom()
    }
  } catch (error) {
    if (!quiet) showToast('failure', translateApiError(error))
  } finally {
    detailsLoading.value = false
  }
}

async function runAction(action) {
  if (!supervisorService.value) return
  pending.value = action
  try {
    const result = await apiSend('POST', `/api/supervisor/${supervisorService.value}/${action}`, {})
    const params = { ...(result.message_parameters || {}) }
    if (params.action) params.action = t(params.action)
    showToast('success', t(result.message_key || 'supervisor.requested', params))
    if (result.supervisor_services) {
      data.supervisor_services = result.supervisor_services
    }
    await new Promise((resolve) => setTimeout(resolve, 1400))
    await loadBootstrap()
    await loadDetails({ quiet: true })
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function clearSelectedLog() {
  if (!supervisorService.value || !selectedLog.value || clearing.value) return
  if (!window.confirm(t('supervisor.clear_confirm', { log: selectedLog.value }))) return
  clearing.value = true
  try {
    const result = await apiSend('POST', `/api/supervisor/${supervisorService.value}/clear-log`, {
      log: selectedLog.value,
    })
    showToast('success', t(result.message_key || 'supervisor.cleared', result.message_parameters || {}))
    if (result.supervisor) {
      details.value = result.supervisor
    } else {
      await loadDetails({ quiet: true })
    }
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    clearing.value = false
  }
}

function onFollowChange(event) {
  followLogs.value = !!event.target.checked
}

function stopFollow() {
  if (followTimer) {
    clearInterval(followTimer)
    followTimer = null
  }
}

function startFollow() {
  stopFollow()
  if (!followLogs.value || !supervisorService.value) return
  followTimer = setInterval(() => {
    loadDetails({ quiet: true })
  }, 3000)
}

watch(phpService, async () => {
  selectedLog.value = ''
  details.value = null
  loading.value = true
  try {
    await ensureBootstrap()
    if (!data.php_controllers?.targets?.[phpService.value]) {
      showToast('failure', t('supervisor.invalid_php_service'))
      router.replace({ name: 'php-versions' })
      return
    }
    await loadDetails()
    startFollow()
  } finally {
    loading.value = false
  }
})

watch(followLogs, (enabled) => {
  startFollow()
  if (enabled) scrollLogToBottom()
})

onMounted(async () => {
  loading.value = true
  try {
    await ensureBootstrap()
    if (!phpService.value || !data.php_controllers?.targets?.[phpService.value]) {
      showToast('failure', t('supervisor.invalid_php_service'))
      router.replace({ name: 'php-versions' })
      return
    }
    await loadDetails()
    startFollow()
  } finally {
    loading.value = false
  }
})

onUnmounted(stopFollow)
</script>

<template>
  <section class="panel" data-tour="supervisor-panel">
    <div class="panel-heading nginx-heading">
      <div class="php-detail-heading">
        <button
          type="button"
          class="icon-back"
          :aria-label="t('supervisor.back_to_php')"
          :title="t('supervisor.back_to_php')"
          @click="router.push({ name: 'php-versions' })"
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
          <h2>{{ t('supervisor.title_for', { version: phpLabel }) }}</h2>
          <p>{{ t('supervisor.subtitle_single') }}</p>
        </div>
      </div>
      <button
        type="button"
        :disabled="loading || !!pending || detailsLoading"
        @click="loadBootstrap().then(() => loadDetails())"
      >
        {{ t('supervisor.refresh') }}
      </button>
    </div>

    <div v-if="loading" class="panel-body">{{ t('loading') }}</div>
    <template v-else>
      <div class="panel-body nginx-overview">
        <div>
          <span class="state-badge" :class="stateClass(currentState)">
            {{ stateLabel(currentState) }}
          </span>
          <code>{{ target.container || details?.container || '—' }}</code>
          <div class="create-hint">
            <code>{{ supervisorService }}</code>
            · profile <code>{{ target.profile || supervisorService }}</code>
          </div>
          <div v-if="showCreate()" class="create-hint">
            {{ t('supervisor.create_hint') }}
          </div>
        </div>
        <div class="controller-actions" data-tour="supervisor-actions">
          <button
            v-if="showCreate()"
            type="button"
            class="primary"
            :disabled="!enabled('create')"
            @click="runAction('create')"
          >
            {{ pending === 'create' ? t('action.working') : t('supervisor.create') }}
          </button>
          <button type="button" :disabled="!enabled('start')" @click="runAction('start')">
            {{ pending === 'start' ? t('action.working') : t('supervisor.start') }}
          </button>
          <button type="button" :disabled="!enabled('stop')" @click="runAction('stop')">
            {{ pending === 'stop' ? t('action.working') : t('supervisor.stop') }}
          </button>
          <button type="button" :disabled="!enabled('restart')" @click="runAction('restart')">
            {{ pending === 'restart' ? t('action.working') : t('supervisor.restart') }}
          </button>
        </div>
      </div>

      <div class="panel-body supervisor-logs" data-tour="supervisor-logs">
        <div class="nginx-heading">
          <div>
            <h3>{{ t('supervisor.logs_title') }}</h3>
            <p v-if="details?.log_dir">
              <code>{{ details.log_dir }}</code>
            </p>
          </div>
          <div class="controller-actions">
            <label class="follow-toggle">
              <input type="checkbox" :checked="followLogs" @change="onFollowChange" />
              <span>{{ t('supervisor.follow') }}</span>
            </label>
            <button type="button" :disabled="detailsLoading" @click="loadDetails()">
              {{ t('supervisor.refresh_logs') }}
            </button>
            <button
              type="button"
              :disabled="!selectedLog || clearing || detailsLoading"
              @click="clearSelectedLog"
            >
              {{ clearing ? t('action.working') : t('supervisor.clear_log') }}
            </button>
          </div>
        </div>

        <div v-if="detailsLoading && !details" class="panel-body">{{ t('loading') }}</div>
        <template v-else-if="details">
          <div class="supervisor-log-toolbar">
            <label class="supervisor-log-file">
              {{ t('supervisor.log_file') }}
              <select
                :value="selectedLog"
                @change="selectedLog = $event.target.value; loadDetails()"
              >
                <option v-if="!(details.log_files || []).length" value="">
                  {{ t('supervisor.log_empty') }}
                </option>
                <option v-for="name in details.log_files || []" :key="name" :value="name">
                  {{ name }}
                </option>
              </select>
            </label>
            <span v-if="details.log?.updated_at" class="create-hint">
              {{ t('supervisor.log_updated', { at: details.log.updated_at }) }}
            </span>
          </div>
          <article class="nginx-log-card">
            <pre ref="logPre">{{
              details.log?.available ? details.log.content : t('supervisor.log_empty')
            }}</pre>
          </article>
        </template>
      </div>
    </template>
  </section>
</template>
