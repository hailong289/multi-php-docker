<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import { apiGet } from '../api'
import { useManager } from '../composables/useManager'
import { confirmDialog } from '../lib/confirm'

const { t } = useI18n()
const {
  data,
  loadBootstrap,
  showToast,
  translateApiError,
  stateLabel,
  phpControllerDaemonAction,
  isPending,
} = useManager()

const loading = ref(true)
const refreshing = ref(false)
const details = ref(null)
const logs = ref('')
const logsLoading = ref(false)
const followLogs = ref(false)
const logPre = ref(null)
let followTimer = null

const daemon = computed(() => details.value || data.php_controller_daemon || {})
const state = computed(() => daemon.value.state || 'not_created')
const showInitialLoading = computed(() => loading.value && !details.value && !data.php_controller_daemon?.container)
const pending = computed(() => {
  if (isPending('php-daemon', { action: 'create' })) return 'create'
  if (isPending('php-daemon', { action: 'start' })) return 'start'
  if (isPending('php-daemon', { action: 'stop' })) return 'stop'
  if (isPending('php-daemon', { action: 'restart' })) return 'restart'
  if (isPending('php-daemon', { action: 'remove' })) return 'remove'
  return ''
})

function stateSeverity(value) {
  if (value === 'running') return 'success'
  if (value === 'stopped') return 'secondary'
  if (value === 'error') return 'danger'
  if (value === 'busy') return 'warn'
  return 'contrast'
}

function enabled(action) {
  if (pending.value || refreshing.value || loading.value) return false
  if (action === 'create') return state.value === 'not_created'
  if (action === 'start') return state.value === 'stopped'
  if (action === 'stop' || action === 'restart') return state.value === 'running'
  if (action === 'remove') return state.value === 'running' || state.value === 'stopped'
  return false
}

function formatTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString()
}

async function loadDetails({ quiet = false } = {}) {
  if (!quiet) loading.value = true
  try {
    const result = await apiGet('/api/php-controller')
    if (result.php_controller_daemon) {
      details.value = result.php_controller_daemon
      data.php_controller_daemon = {
        ...data.php_controller_daemon,
        ...result.php_controller_daemon,
      }
    }
  } catch (error) {
    if (!quiet) showToast('failure', translateApiError(error))
  } finally {
    loading.value = false
  }
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
  if (state.value === 'not_created') {
    logs.value = ''
    return
  }
  if (!quiet) logsLoading.value = true
  try {
    const result = await apiGet('/api/php-controller/logs?tail=300')
    logs.value = result.content || ''
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

async function runAction(action) {
  if (action === 'remove') {
    if (
      !(await confirmDialog(t('php_controller.daemon_remove_confirm'), {
        acceptLabel: t('action.delete'),
        rejectLabel: t('action.cancel'),
        acceptSeverity: 'danger',
      }))
    ) {
      return
    }
  }
  await phpControllerDaemonAction(action)
  await loadDetails({ quiet: true })
  if (action === 'remove') {
    logs.value = ''
    followLogs.value = false
    stopFollow()
  } else if (state.value !== 'not_created') {
    await loadLogs({ quiet: true })
  }
}

async function refreshAll() {
  refreshing.value = true
  try {
    await loadDetails({ quiet: true })
    await loadLogs({ quiet: true })
  } finally {
    refreshing.value = false
  }
}

onMounted(async () => {
  await loadBootstrap({ silent: true })
  await loadDetails()
  await loadLogs({ quiet: true })
})

onUnmounted(() => {
  stopFollow()
})

watch(
  () => data.php_controller_daemon?.state,
  (next) => {
    if (!next || !details.value) return
    details.value = { ...details.value, ...data.php_controller_daemon }
  },
)
</script>

<template>
  <section class="panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <div>
          <h2>{{ t('php_controller.daemon_page_title') }}</h2>
          <p>{{ t('php_controller.daemon_page_subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <Button
            type="button"
            :label="refreshing ? t('action.working') : t('nginx.refresh')"
            :loading="refreshing"
            :disabled="showInitialLoading || refreshing || logsLoading || !!pending"
            @click="refreshAll"
          />
        </div>
      </div>
    </div>

    <div class="panel-body">
      <div v-if="showInitialLoading" class="nginx-tab-pad">{{ t('loading') }}</div>
      <div v-else class="nginx-control-stack">
        <div class="nginx-status-bar">
          <div class="nginx-status-meta">
            <Tag :value="stateLabel(state)" :severity="stateSeverity(state)" rounded />
            <code class="nginx-container">{{ daemon.container || 'php_controller_container' }}</code>
          </div>
          <div class="controller-actions">
            <Button
              type="button"
              size="small"
              :label="pending === 'create' ? t('action.working') : t('php_controller.daemon_create')"
              :loading="pending === 'create'"
              :disabled="!enabled('create')"
              @click="runAction('create')"
            />
            <Button
              type="button"
              size="small"
              :label="pending === 'start' ? t('action.working') : t('php_controller.daemon_start')"
              :loading="pending === 'start'"
              :disabled="!enabled('start')"
              @click="runAction('start')"
            />
            <Button
              type="button"
              size="small"
              severity="secondary"
              outlined
              :label="pending === 'stop' ? t('action.working') : t('php_controller.daemon_stop')"
              :loading="pending === 'stop'"
              :disabled="!enabled('stop')"
              @click="runAction('stop')"
            />
            <Button
              type="button"
              size="small"
              severity="secondary"
              outlined
              :label="pending === 'restart' ? t('action.working') : t('php_controller.daemon_restart')"
              :loading="pending === 'restart'"
              :disabled="!enabled('restart')"
              @click="runAction('restart')"
            />
            <Button
              type="button"
              size="small"
              severity="danger"
              outlined
              :label="pending === 'remove' ? t('action.working') : t('php_controller.daemon_remove')"
              :loading="pending === 'remove'"
              :disabled="!enabled('remove')"
              @click="runAction('remove')"
            />
          </div>
        </div>

        <Message
          v-if="state === 'not_created'"
          severity="warn"
          :closable="false"
        >
          {{ t('php_controller.daemon_create_hint') }}
        </Message>

        <div class="php-daemon-details">
          <h3>{{ t('php_controller.daemon_details') }}</h3>
          <dl class="php-daemon-meta">
            <div>
              <dt>{{ t('php_controller.daemon_meta_container') }}</dt>
              <dd><code>{{ daemon.container || '—' }}</code></dd>
            </div>
            <div>
              <dt>{{ t('php_controller.daemon_meta_image') }}</dt>
              <dd><code>{{ daemon.image || '—' }}</code></dd>
            </div>
            <div>
              <dt>{{ t('php_controller.daemon_meta_state') }}</dt>
              <dd>{{ stateLabel(state) }}</dd>
            </div>
            <div>
              <dt>{{ t('php_controller.daemon_meta_started') }}</dt>
              <dd>{{ formatTime(daemon.started_at) }}</dd>
            </div>
          </dl>
        </div>

        <article class="nginx-log-card">
          <div class="nginx-log-card-head">
            <h3>{{ t('php_controller.daemon_logs') }}</h3>
            <div class="php-daemon-log-actions">
              <label class="php-daemon-follow">
                <ToggleSwitch
                  :model-value="followLogs"
                  :disabled="state === 'not_created' || logsLoading"
                  @update:model-value="onFollowChange"
                />
                <span>{{ t('services.follow_logs') }}</span>
              </label>
              <Button
                type="button"
                size="small"
                severity="secondary"
                outlined
                :label="logsLoading ? t('action.working') : t('nginx.refresh')"
                :loading="logsLoading"
                :disabled="state === 'not_created' || logsLoading || !!pending || refreshing"
                @click="loadLogs()"
              />
            </div>
          </div>
          <pre ref="logPre" class="nginx-log-pre">{{
            state === 'not_created'
              ? t('php_controller.daemon_logs_unavailable_hint')
              : logs || t('nginx.log_empty')
          }}</pre>
        </article>
      </div>
    </div>
  </section>
</template>

<style scoped>
.php-daemon-details {
  display: grid;
  gap: 0.75rem;
}

.php-daemon-details h3 {
  margin: 0;
  font-size: 1rem;
}

.php-daemon-meta {
  display: grid;
  gap: 0.75rem 1.5rem;
  margin: 0;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
}

.php-daemon-meta div {
  display: grid;
  gap: 0.25rem;
}

.php-daemon-meta dt {
  margin: 0;
  opacity: 0.7;
  font-size: 0.85rem;
}

.php-daemon-meta dd {
  margin: 0;
}

.php-daemon-log-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.php-daemon-follow {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}
</style>
