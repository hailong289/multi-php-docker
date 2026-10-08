<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import { apiGet, apiSend } from '../api'
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

const connectionLoading = ref(false)
const connectionSaving = ref(false)
const connectionTesting = ref(false)
const connection = ref(null)
const connectionForm = ref(emptyConnectionForm())

const modeOptions = computed(() => [
  { label: t('docker_connection.mode_local'), value: 'local' },
  { label: t('docker_connection.mode_tcp'), value: 'tcp_tls' },
  { label: t('docker_connection.mode_ssh'), value: 'ssh' },
])

function emptyConnectionForm() {
  return {
    mode: 'local',
    tcp: { host: '', port: 2376, tls: true, ca: '', cert: '', key: '' },
    ssh: { user: '', host: '', port: 22, identity_file: '' },
    remote_project_path: '',
  }
}

function applyConnectionPayload(payload) {
  connection.value = payload || null
  const src = payload || emptyConnectionForm()
  connectionForm.value = {
    mode: src.mode || 'local',
    tcp: {
      host: src.tcp?.host || '',
      port: src.tcp?.port || 2376,
      tls: src.tcp?.tls !== false,
      ca: src.tcp?.ca || '',
      cert: src.tcp?.cert || '',
      key: src.tcp?.key || '',
    },
    ssh: {
      user: src.ssh?.user || '',
      host: src.ssh?.host || '',
      port: src.ssh?.port || 22,
      identity_file: src.ssh?.identity_file || '',
    },
    remote_project_path: src.remote_project_path || '',
  }
}

async function loadConnection({ quiet = false } = {}) {
  if (!quiet) connectionLoading.value = true
  try {
    const result = await apiGet('/api/docker-connection')
    applyConnectionPayload(result.docker_connection)
  } catch (error) {
    if (!quiet) showToast('failure', translateApiError(error))
  } finally {
    connectionLoading.value = false
  }
}

async function testConnection() {
  connectionTesting.value = true
  try {
    const result = await apiSend('POST', '/api/docker-connection/test', connectionForm.value)
    showToast(result.ok ? 'success' : 'failure', t(result.message_key || 'docker_connection.test_failed'))
    if (connection.value) {
      connection.value = { ...connection.value, reachable: !!result.ok }
    }
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    connectionTesting.value = false
  }
}

async function saveConnection() {
  connectionSaving.value = true
  try {
    const result = await apiSend('PUT', '/api/docker-connection', connectionForm.value)
    showToast('success', t(result.message_key || 'docker_connection.saved'))
    if (result.docker_connection) applyConnectionPayload(result.docker_connection)
    if (result.php_controller_daemon) {
      details.value = { ...(details.value || {}), ...result.php_controller_daemon }
      data.php_controller_daemon = {
        ...data.php_controller_daemon,
        ...result.php_controller_daemon,
      }
    }
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    connectionSaving.value = false
  }
}

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

function connectionReachableSeverity() {
  if (connection.value?.reachable === true) return 'success'
  if (connection.value?.reachable === false) return 'danger'
  return 'secondary'
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
    await Promise.all([
      loadDetails({ quiet: true }),
      loadLogs({ quiet: true }),
      loadConnection({ quiet: true }),
    ])
  } finally {
    refreshing.value = false
  }
}

onMounted(async () => {
  await loadBootstrap({ silent: true })
  await Promise.all([loadDetails(), loadConnection()])
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
        <article class="php-daemon-connection">
          <div class="php-daemon-connection-head">
            <div>
              <h3>{{ t('docker_connection.title') }}</h3>
              <p>{{ t('docker_connection.subtitle') }}</p>
            </div>
            <div class="php-daemon-connection-status">
              <Tag
                :value="connection?.effective?.label || t('docker_connection.mode_local')"
                :severity="connectionReachableSeverity()"
                rounded
              />
              <Tag
                v-if="connection?.reachable === true"
                :value="t('docker_connection.reachable')"
                severity="success"
                rounded
              />
              <Tag
                v-else-if="connection?.reachable === false"
                :value="t('docker_connection.unreachable')"
                severity="danger"
                rounded
              />
            </div>
          </div>

          <Message severity="warn" :closable="false" class="php-daemon-connection-warn">
            {{ t('docker_connection.remote_bind_warning') }}
          </Message>

          <div v-if="connectionLoading" class="nginx-tab-pad">{{ t('loading') }}</div>
          <div v-else class="php-daemon-connection-form">
            <label class="php-daemon-field">
              <span>{{ t('docker_connection.mode') }}</span>
              <Select v-model="connectionForm.mode" :options="modeOptions" option-label="label" option-value="value" />
            </label>

            <template v-if="connectionForm.mode === 'tcp_tls'">
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.tcp_host') }}</span>
                <InputText v-model="connectionForm.tcp.host" fluid />
              </label>
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.tcp_port') }}</span>
                <InputNumber v-model="connectionForm.tcp.port" :min="1" :max="65535" fluid />
              </label>
              <label class="php-daemon-field php-daemon-field-check">
                <Checkbox v-model="connectionForm.tcp.tls" binary />
                <span>{{ t('docker_connection.tcp_tls') }}</span>
              </label>
              <template v-if="connectionForm.tcp.tls">
                <label class="php-daemon-field">
                  <span>{{ t('docker_connection.tcp_ca') }}</span>
                  <InputText v-model="connectionForm.tcp.ca" fluid :placeholder="t('docker_connection.path_placeholder')" />
                </label>
                <label class="php-daemon-field">
                  <span>{{ t('docker_connection.tcp_cert') }}</span>
                  <InputText v-model="connectionForm.tcp.cert" fluid :placeholder="t('docker_connection.path_placeholder')" />
                </label>
                <label class="php-daemon-field">
                  <span>{{ t('docker_connection.tcp_key') }}</span>
                  <InputText v-model="connectionForm.tcp.key" fluid :placeholder="t('docker_connection.path_placeholder')" />
                </label>
              </template>
            </template>

            <template v-if="connectionForm.mode === 'ssh'">
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.ssh_user') }}</span>
                <InputText v-model="connectionForm.ssh.user" fluid />
              </label>
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.ssh_host') }}</span>
                <InputText v-model="connectionForm.ssh.host" fluid />
              </label>
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.ssh_port') }}</span>
                <InputNumber v-model="connectionForm.ssh.port" :min="1" :max="65535" fluid />
              </label>
              <label class="php-daemon-field">
                <span>{{ t('docker_connection.ssh_identity') }}</span>
                <InputText
                  v-model="connectionForm.ssh.identity_file"
                  fluid
                  :placeholder="t('docker_connection.path_placeholder')"
                />
              </label>
            </template>

            <label v-if="connectionForm.mode !== 'local'" class="php-daemon-field php-daemon-field-wide">
              <span>{{ t('docker_connection.remote_project_path') }}</span>
              <InputText v-model="connectionForm.remote_project_path" fluid />
              <small>{{ t('docker_connection.remote_project_hint') }}</small>
            </label>

            <div class="php-daemon-connection-actions">
              <Button
                type="button"
                size="small"
                severity="secondary"
                outlined
                :label="connectionTesting ? t('action.working') : t('docker_connection.test')"
                :loading="connectionTesting"
                :disabled="connectionSaving || connectionTesting"
                @click="testConnection"
              />
              <Button
                type="button"
                size="small"
                :label="connectionSaving ? t('action.working') : t('docker_connection.save')"
                :loading="connectionSaving"
                :disabled="connectionSaving || connectionTesting"
                @click="saveConnection"
              />
            </div>
          </div>
        </article>

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
.php-daemon-connection {
  display: grid;
  gap: 0.85rem;
  padding: 1rem;
  border: 1px solid var(--p-content-border-color, var(--line));
  border-radius: 10px;
  background: color-mix(in srgb, var(--p-card-background, var(--panel)) 94%, var(--p-primary-color, var(--primary)) 6%);
}

.php-daemon-connection-head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: flex-start;
}

.php-daemon-connection-head h3 {
  margin: 0;
  font-size: 1rem;
}

.php-daemon-connection-head p {
  margin: 0.35rem 0 0;
  opacity: 0.8;
  font-size: 0.9rem;
}

.php-daemon-connection-status {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.php-daemon-connection-form {
  display: grid;
  gap: 0.75rem 1rem;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
}

.php-daemon-field {
  display: grid;
  gap: 0.35rem;
}

.php-daemon-field span {
  font-size: 0.85rem;
  opacity: 0.8;
}

.php-daemon-field small {
  opacity: 0.7;
}

.php-daemon-field-check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.php-daemon-field-wide {
  grid-column: 1 / -1;
}

.php-daemon-connection-actions {
  grid-column: 1 / -1;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

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
