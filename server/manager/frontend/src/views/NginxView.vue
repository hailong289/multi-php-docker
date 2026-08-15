<script setup>
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const { t } = useI18n()
const { showToast, translateApiError, stateClass, data, dockerStatusBusy } = useManager()
const tab = ref('control')
const loading = ref(true)
const pending = ref('')
const nginx = ref({
  container: 'nginx_container',
  state: 'not_created',
  test_status: null,
  reload_status: null,
  logs: {},
})

const templates = ref([])
const templatesLoading = ref(false)
const selectedName = ref('')
const draft = ref('')
const original = ref('')
const templateMeta = ref(null)
const templateLoading = ref(false)

const domainLogList = ref([])
const domainLogsLoading = ref(false)
const selectedDomain = ref('')
const domainLogs = ref(null)
const domainLogsDetailLoading = ref(false)
const domainRefreshSec = ref(5)
let statusPollTimer = null
let domainPollTimer = null

const STATUS_POLL_IDLE_MS = 5000
const STATUS_POLL_BUSY_MS = 2000
const REFRESH_OPTIONS = [0, 5, 10, 15]

try {
  const raw = localStorage.getItem('manager-nginx-domain-log-refresh')
  if (raw !== null) {
    const saved = Number(raw)
    if (REFRESH_OPTIONS.includes(saved)) domainRefreshSec.value = saved
  }
} catch (_) {}

const stateLabel = computed(() => t(`nginx.state_${nginx.value.state || 'not_created'}`))
const dirty = computed(() => draft.value !== original.value)

function enabled(action) {
  if (pending.value || nginx.value.state === 'busy') return false
  if (action === 'start') return nginx.value.state === 'stopped'
  return nginx.value.state === 'running'
}

function formatSize(bytes) {
  if (!bytes && bytes !== 0) return '—'
  if (bytes < 1024) return `${bytes} B`
  return `${(bytes / 1024).toFixed(1)} KB`
}

function formatTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString()
}

function refreshOptionLabel(sec) {
  return sec === 0 ? t('nginx.domain_logs_refresh_off') : t('nginx.domain_logs_refresh_option', { sec })
}

function setDomainRefresh(sec) {
  const next = Number(sec)
  domainRefreshSec.value = REFRESH_OPTIONS.includes(next) ? next : 5
  try {
    localStorage.setItem('manager-nginx-domain-log-refresh', String(domainRefreshSec.value))
  } catch (_) {}
  restartDomainPoll()
}

function stopStatusPoll() {
  if (statusPollTimer) {
    clearInterval(statusPollTimer)
    statusPollTimer = null
  }
}

function stopDomainPoll() {
  if (domainPollTimer) {
    clearInterval(domainPollTimer)
    domainPollTimer = null
  }
}

function startStatusPoll() {
  stopStatusPoll()
  const ms = dockerStatusBusy.value || nginx.value.state === 'busy' ? STATUS_POLL_BUSY_MS : STATUS_POLL_IDLE_MS
  statusPollTimer = setInterval(() => {
    if (document.visibilityState !== 'visible' || pending.value) return
    if (tab.value === 'domain-logs') return
    load({ silent: true })
    if (tab.value === 'templates') loadTemplates({ silent: true })
  }, ms)
}

function restartDomainPoll() {
  stopDomainPoll()
  if (domainRefreshSec.value <= 0) return
  const ms = domainRefreshSec.value * 1000
  domainPollTimer = setInterval(() => {
    if (document.visibilityState !== 'visible' || pending.value) return
    if (tab.value !== 'domain-logs') return
    loadDomainLogList({ silent: true })
    if (selectedDomain.value) openDomainLogs(selectedDomain.value, { silent: true })
  }, ms)
}

function restartPoll() {
  startStatusPoll()
  restartDomainPoll()
}

function stopPoll() {
  stopStatusPoll()
  stopDomainPoll()
}

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true
  try {
    const result = await apiGet('/api/nginx/management')
    nginx.value = result.nginx_management
  } catch (error) {
    if (!silent) showToast('failure', translateApiError(error))
  } finally {
    if (!silent) loading.value = false
  }
}

async function loadTemplates({ silent = false } = {}) {
  if (!silent) templatesLoading.value = true
  try {
    const result = await apiGet('/api/nginx/templates')
    templates.value = result.templates || []
    if (selectedName.value && !templates.value.some((item) => item.name === selectedName.value)) {
      selectedName.value = ''
      draft.value = ''
      original.value = ''
      templateMeta.value = null
    }
  } catch (error) {
    if (!silent) showToast('failure', translateApiError(error))
  } finally {
    if (!silent) templatesLoading.value = false
  }
}

async function loadDomainLogList({ silent = false } = {}) {
  if (!silent) domainLogsLoading.value = true
  try {
    const result = await apiGet('/api/nginx/domain-logs')
    domainLogList.value = result.domains || []
    if (selectedDomain.value && !domainLogList.value.some((item) => item.domain === selectedDomain.value)) {
      selectedDomain.value = ''
      domainLogs.value = null
    }
  } catch (error) {
    if (!silent) showToast('failure', translateApiError(error))
  } finally {
    if (!silent) domainLogsLoading.value = false
  }
}

async function openDomainLogs(domain, { silent = false } = {}) {
  selectedDomain.value = domain
  if (!silent) domainLogsDetailLoading.value = true
  try {
    const result = await apiGet(`/api/nginx/domain-logs/${encodeURIComponent(domain)}`)
    domainLogs.value = result.domain_logs
  } catch (error) {
    if (!silent) showToast('failure', translateApiError(error))
    if (!silent) {
      selectedDomain.value = ''
      domainLogs.value = null
    }
  } finally {
    if (!silent) domainLogsDetailLoading.value = false
  }
}

async function clearDomainLogs() {
  if (!selectedDomain.value) return
  if (!confirm(t('nginx.domain_logs_clear_confirm', { domain: selectedDomain.value }))) return
  pending.value = 'domain-clear'
  try {
    const result = await apiSend(
      'POST',
      `/api/nginx/domain-logs/${encodeURIComponent(selectedDomain.value)}/clear`,
      { which: 'both' },
    )
    domainLogs.value = result.domain_logs
    showToast('success', t(result.message_key || 'nginx.domain_logs_cleared'))
    await loadDomainLogList({ silent: true })
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function clearGlobalLog(name) {
  if (!confirm(t('nginx.global_log_clear_confirm', { name: t(`nginx.log_${name}`) }))) return
  pending.value = `clear-${name}`
  try {
    const result = await apiSend('POST', '/api/nginx/logs/clear', { log: name })
    if (result.nginx_management) nginx.value = result.nginx_management
    showToast('success', t(result.message_key || 'nginx.global_log_cleared'))
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function openTemplate(name) {
  if (dirty.value && !confirm(t('nginx.template_discard_confirm'))) return
  selectedName.value = name
  templateLoading.value = true
  try {
    const result = await apiGet(`/api/nginx/templates/${encodeURIComponent(name)}`)
    const tpl = result.template
    draft.value = tpl.content || ''
    original.value = draft.value
    templateMeta.value = tpl
  } catch (error) {
    showToast('failure', translateApiError(error))
    selectedName.value = ''
  } finally {
    templateLoading.value = false
  }
}

async function saveTemplate() {
  if (!selectedName.value || !dirty.value) return
  pending.value = 'template-save'
  try {
    const result = await apiSend(
      'PUT',
      `/api/nginx/templates/${encodeURIComponent(selectedName.value)}`,
      { content: draft.value, soft_reload: true },
    )
    original.value = draft.value
    templateMeta.value = { ...(templateMeta.value || {}), ...result.template }
    showToast('success', t(result.message_key || 'nginx.template_saved_reloading'))
    await loadTemplates({ silent: true })
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function run(action, path) {
  pending.value = action
  try {
    const result = await apiSend('POST', path, {})
    showToast('success', t(result.message_key || 'nginx.requested'))
    if (result.nginx_management) {
      nginx.value = result.nginx_management
      data.nginx_management = {
        state: result.nginx_management.state,
        container: result.nginx_management.container,
        message_key: result.nginx_management.message_key,
        request_id: result.nginx_management.request_id,
        updated_at: result.nginx_management.updated_at,
        service: result.nginx_management.service,
      }
    }
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

function statusText(status) {
  if (!status) return t('nginx.no_result')
  return status.message_key ? t(status.message_key) : status.message || t('nginx.no_result')
}

function refreshCurrentTab() {
  if (tab.value === 'templates') return loadTemplates()
  if (tab.value === 'domain-logs') {
    return loadDomainLogList().then(() => {
      if (selectedDomain.value) return openDomainLogs(selectedDomain.value, { silent: true })
    })
  }
  return load()
}

watch(tab, (next) => {
  if (next === 'templates') loadTemplates()
  if (next === 'domain-logs') loadDomainLogList()
  restartPoll()
})

watch(
  () => data.nginx_management,
  (next) => {
    if (!next || typeof next !== 'object') return
    nginx.value = {
      ...nginx.value,
      ...next,
      logs: nginx.value.logs || {},
      test_status: nginx.value.test_status,
      reload_status: nginx.value.reload_status,
    }
  },
)

watch(
  () => [dockerStatusBusy.value, nginx.value.state],
  () => startStatusPoll(),
)

onMounted(() => {
  load()
  restartPoll()
})

onUnmounted(() => {
  stopPoll()
})
</script>

<template>
  <section class="panel" data-tour="nginx-panel">
    <div class="panel-heading nginx-heading">
      <div>
        <h2>{{ t('nginx.title') }}</h2>
        <p>{{ t('nginx.subtitle') }}</p>
      </div>
      <div class="panel-heading-actions nginx-heading-actions">
        <label class="nginx-refresh-select">
          <span>{{ t('nginx.domain_logs_refresh') }}</span>
          <select
            :value="domainRefreshSec"
            :disabled="!!pending"
            @change="setDomainRefresh($event.target.value)"
          >
            <option v-for="sec in REFRESH_OPTIONS" :key="sec" :value="sec">
              {{ refreshOptionLabel(sec) }}
            </option>
          </select>
        </label>
        <button
          type="button"
          :disabled="loading || templatesLoading || domainLogsLoading || !!pending"
          @click="refreshCurrentTab"
        >
          {{ t('nginx.refresh') }}
        </button>
      </div>
    </div>

    <div class="panel-body php-detail-tabs-wrap" data-tour="nginx-tabs">
      <div class="php-detail-tabs" role="tablist">
        <button
          type="button"
          role="tab"
          :aria-selected="tab === 'control'"
          :class="{ active: tab === 'control' }"
          data-tour="nginx-control-tab"
          @click="tab = 'control'"
        >
          {{ t('nginx.tab_control') }}
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="tab === 'templates'"
          :class="{ active: tab === 'templates' }"
          data-tour="nginx-templates-tab"
          @click="tab = 'templates'"
        >
          {{ t('nginx.tab_templates') }}
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="tab === 'domain-logs'"
          :class="{ active: tab === 'domain-logs' }"
          data-tour="nginx-domain-logs-tab"
          @click="tab = 'domain-logs'"
        >
          {{ t('nginx.tab_domain_logs') }}
        </button>
      </div>
    </div>

    <div v-if="tab === 'control'">
      <div v-if="loading" class="panel-body">{{ t('loading') }}</div>
      <template v-else>
        <div class="panel-body nginx-overview">
          <div>
            <span class="state-badge" :class="stateClass(nginx.state)">{{ stateLabel }}</span>
            <code>{{ nginx.container }}</code>
          </div>
          <div class="controller-actions" data-tour="nginx-actions">
            <button :disabled="!enabled('start')" @click="run('start', '/api/nginx/actions/start')">
              {{ pending === 'start' ? t('action.working') : t('nginx.start') }}
            </button>
            <button :disabled="!enabled('stop')" @click="run('stop', '/api/nginx/actions/stop')">
              {{ pending === 'stop' ? t('action.working') : t('nginx.stop') }}
            </button>
            <button :disabled="!enabled('restart')" @click="run('restart', '/api/nginx/actions/restart')">
              {{ pending === 'restart' ? t('action.working') : t('nginx.restart') }}
            </button>
            <button :disabled="!enabled('test')" @click="run('test', '/api/nginx/test')">
              {{ pending === 'test' ? t('action.working') : t('nginx.test') }}
            </button>
            <button class="primary" :disabled="!enabled('reload')" @click="run('reload', '/api/nginx/reload')">
              {{ pending === 'reload' ? t('action.working') : t('nginx.apply_reload') }}
            </button>
          </div>
        </div>

        <div class="panel-body nginx-results">
          <p><strong>{{ t('nginx.test_result') }}:</strong> {{ statusText(nginx.test_status) }}</p>
          <p><strong>{{ t('nginx.reload_result') }}:</strong> {{ statusText(nginx.reload_status) }}</p>
        </div>

        <div class="panel-body nginx-log-grid" data-tour="nginx-logs">
          <article v-for="name in ['operation', 'error', 'access']" :key="name" class="nginx-log-card">
            <div class="nginx-log-card-head">
              <h3>{{ t(`nginx.log_${name}`) }}</h3>
              <button
                type="button"
                class="danger"
                :disabled="!!pending || loading"
                @click="clearGlobalLog(name)"
              >
                {{ pending === `clear-${name}` ? t('action.working') : t('nginx.global_log_clear') }}
              </button>
            </div>
            <pre>{{ nginx.logs?.[name]?.available ? nginx.logs[name].content : t('nginx.log_empty') }}</pre>
          </article>
        </div>
      </template>
    </div>

    <div v-else-if="tab === 'templates'" class="nginx-templates" data-tour="nginx-templates">
      <div class="panel-body">
        <p class="status-line warn">{{ t('nginx.templates_warn') }}</p>
      </div>

      <div v-if="templatesLoading && templates.length === 0" class="panel-body">{{ t('loading') }}</div>
      <div v-else-if="templates.length === 0" class="panel-body empty">{{ t('nginx.templates_empty') }}</div>
      <div v-else class="nginx-templates-layout">
        <div class="table-wrap nginx-templates-list">
          <table>
            <thead>
              <tr>
                <th>{{ t('nginx.template_name') }}</th>
                <th>{{ t('nginx.template_size') }}</th>
                <th>{{ t('nginx.template_updated') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in templates"
                :key="item.name"
                :class="{ 'is-selected': item.name === selectedName }"
                @click="openTemplate(item.name)"
              >
                <td><code>{{ item.name }}</code></td>
                <td>{{ formatSize(item.size) }}</td>
                <td>{{ formatTime(item.updated_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="panel-body nginx-template-editor">
          <div v-if="!selectedName" class="empty">{{ t('nginx.template_pick') }}</div>
          <template v-else>
            <div class="nginx-template-editor-head">
              <div>
                <strong><code>{{ selectedName }}</code></strong>
                <span v-if="dirty" class="status-pill status-off">{{ t('nginx.template_dirty') }}</span>
              </div>
              <button
                type="button"
                class="primary"
                :disabled="!dirty || !!pending || templateLoading"
                @click="saveTemplate"
              >
                {{ pending === 'template-save' ? t('action.working') : t('nginx.template_save') }}
              </button>
            </div>
            <p v-if="templateMeta" class="nginx-template-meta">
              {{ formatSize(templateMeta.size) }} · {{ formatTime(templateMeta.updated_at) }}
            </p>
            <MonacoEditor
              v-model="draft"
              language="plaintext"
              min-height="420px"
              :read-only="templateLoading || pending === 'template-save'"
            />
          </template>
        </div>
      </div>
    </div>

    <div v-else class="nginx-domain-logs" data-tour="nginx-domain-logs">
      <div class="panel-body">
        <p class="status-line">{{ t('nginx.domain_logs_hint') }}</p>
      </div>

      <div v-if="domainLogsLoading && domainLogList.length === 0" class="panel-body">{{ t('loading') }}</div>
      <div v-else-if="domainLogList.length === 0" class="panel-body empty">{{ t('nginx.domain_logs_empty') }}</div>
      <div v-else class="nginx-templates-layout">
        <div class="table-wrap nginx-templates-list">
          <table>
            <thead>
              <tr>
                <th>{{ t('nginx.domain_logs_domain') }}</th>
                <th>{{ t('nginx.log_access') }}</th>
                <th>{{ t('nginx.log_error') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in domainLogList"
                :key="item.domain"
                :class="{ 'is-selected': item.domain === selectedDomain }"
                @click="openDomainLogs(item.domain)"
              >
                <td><code>{{ item.domain }}</code></td>
                <td>{{ item.access?.available ? formatSize(item.access.size) : '—' }}</td>
                <td>{{ item.error?.available ? formatSize(item.error.size) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="panel-body nginx-domain-log-viewer">
          <div v-if="!selectedDomain" class="empty">{{ t('nginx.domain_logs_pick') }}</div>
          <div v-else-if="domainLogsDetailLoading && !domainLogs" class="empty">{{ t('loading') }}</div>
          <template v-else-if="domainLogs">
            <div class="nginx-template-editor-head">
              <strong><code>{{ domainLogs.domain }}</code></strong>
              <div class="actions">
                <button type="button" :disabled="domainLogsDetailLoading || !!pending" @click="openDomainLogs(selectedDomain)">
                  {{ t('nginx.refresh') }}
                </button>
                <button
                  type="button"
                  class="danger"
                  :disabled="domainLogsDetailLoading || !!pending"
                  @click="clearDomainLogs"
                >
                  {{ pending === 'domain-clear' ? t('action.working') : t('nginx.domain_logs_clear') }}
                </button>
              </div>
            </div>
            <div class="nginx-domain-log-grid">
              <article class="nginx-log-card">
                <h3>
                  {{ t('nginx.log_error') }}
                  <span v-if="domainLogs.error?.updated_at" class="nginx-template-meta">
                    · {{ formatTime(domainLogs.error.updated_at) }}
                    · {{ formatSize(domainLogs.error.size) }}
                  </span>
                </h3>
                <pre>{{
                  domainLogs.error?.available ? domainLogs.error.content || t('nginx.log_empty') : t('nginx.log_empty')
                }}</pre>
              </article>
              <article class="nginx-log-card">
                <h3>
                  {{ t('nginx.log_access') }}
                  <span v-if="domainLogs.access?.updated_at" class="nginx-template-meta">
                    · {{ formatTime(domainLogs.access.updated_at) }}
                    · {{ formatSize(domainLogs.access.size) }}
                  </span>
                </h3>
                <pre>{{
                  domainLogs.access?.available
                    ? domainLogs.access.content || t('nginx.log_empty')
                    : t('nginx.log_empty')
                }}</pre>
              </article>
            </div>
          </template>
        </div>
      </div>
    </div>
  </section>
</template>
